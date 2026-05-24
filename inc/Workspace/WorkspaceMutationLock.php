<?php
/**
 * Workspace mutation lock.
 *
 * Serializes worktree lifecycle operations that mutate a primary checkout's
 * shared Git metadata and Data Machine Code's workspace registry state.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

if ( ! class_exists(WorkspaceLockStore::class) ) {
	include_once __DIR__ . '/WorkspaceLockStore.php';
}

final class WorkspaceMutationLock {



	private const POLL_USEC = 100000;

	/**
	 * @var resource|null
	 */
	private $handle = null;

	private int $lock_id = 0;

	private string $lock_path = '';

	private function __construct( $handle, int $lock_id = 0, string $lock_path = '' ) {
		$this->handle    = $handle;
		$this->lock_id   = $lock_id;
		$this->lock_path = $lock_path;
	}

	/**
	 * Run a callback while holding a per-primary-repo workspace mutation lock.
	 *
	 * @param  string   $workspace_path Workspace root.
	 * @param  string   $repo           Primary repo handle.
	 * @param  callable $callback       Callback to run while locked.
	 * @param  int      $timeout        Seconds to wait for the lock.
	 * @return mixed|\WP_Error Callback result or lock acquisition error.
	 */
	public static function with_repo(
		string $workspace_path,
		string $repo,
		callable $callback,
		int $timeout = 30
	): mixed {
		$lock = self::acquire($workspace_path, $repo, $timeout);
		if ( is_wp_error($lock) ) {
			return $lock;
		}

		try {
			return $callback();
		} finally {
			$lock->release();
		}
	}

	/**
	 * Acquire a per-primary-repo lock.
	 *
	 * @param  string $workspace_path Workspace root.
	 * @param  string $repo           Primary repo handle.
	 * @param  int    $timeout        Seconds to wait for the lock.
	 * @return self|\WP_Error Lock object or retryable error.
	 */
	public static function acquire( string $workspace_path, string $repo, int $timeout = 30 ): self|\WP_Error {
		$workspace_path = rtrim($workspace_path, '/');
		$repo           = self::sanitize_repo_key($repo);

		if ( '' === $workspace_path || '' === $repo ) {
			return new \WP_Error(
				'workspace_lock_invalid_target',
				'Workspace mutation lock requires a workspace path and repo handle.',
				array( 'status' => 400 )
			);
		}

		$lock_dir = $workspace_path . '/.locks';
		if ( ! is_dir($lock_dir) ) {
			$created = function_exists('wp_mkdir_p')
			? wp_mkdir_p($lock_dir)
             // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback for non-WordPress smoke tests.
			: mkdir($lock_dir, 0755, true);
			if ( ! $created && ! is_dir($lock_dir) ) {
				return new \WP_Error(
					'workspace_lock_create_failed',
					sprintf('Failed to create workspace lock directory: %s', $lock_dir),
					array( 'status' => 500 )
				);
			}
		}

		$lock_path = $lock_dir . '/worktree-' . $repo . '.lock';
		$handle    = fopen($lock_path, 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error(
				'workspace_lock_open_failed',
				sprintf('Failed to open workspace mutation lock: %s', $lock_path),
				array( 'status' => 500 )
			);
		}

		$started = microtime(true);
		$timeout = max(0, $timeout);

		do {
			if ( flock($handle, LOCK_EX | LOCK_NB) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				$lock_id = WorkspaceLockStore::register_acquired(
					array(
						'lock_key' => 'worktree-' . $repo,
						'purpose'  => 'workspace_repo_mutation',
						'scope'    => $repo,
						'metadata' => array(
							'workspace_path' => $workspace_path,
							'lock_path'      => $lock_path,
						),
					)
				);
				if ( is_wp_error($lock_id) ) {
						flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
						fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return $lock_id;
				}

				return new self($handle, (int) $lock_id, $lock_path);
			}

			if ( 0 === $timeout || ( microtime(true) - $started ) >= $timeout ) {
				fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error(
					'workspace_repo_busy',
					sprintf(
						'Workspace repo "%s" is busy with another worktree lifecycle mutation. Retry after the current add/remove/cleanup/prune operation completes.',
						$repo
					),
					array(
						'status'    => 423,
						'retryable' => true,
						'repo'      => $repo,
						'lock_path' => $lock_path,
					)
				);
			}

			usleep(self::POLL_USEC);
		} while ( true );
	}

	public function release(): void {
		if ( null === $this->handle ) {
			return;
		}

		WorkspaceLockStore::release($this->lock_id);
		flock($this->handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose($this->handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->handle    = null;
		$this->lock_id   = 0;
		$this->lock_path = '';
	}

	/**
	 * Summarize DB-visible and filesystem lock state.
	 *
	 * @return array<string,mixed>
	 */
	public static function status( string $workspace_path ): array {
		$filesystem = self::filesystem_status($workspace_path);
		$database   = WorkspaceLockStore::status();

		return array(
			'database'          => $database,
			'filesystem'        => $filesystem,
			'active'            => (int) ( $database['active'] ?? 0 ) + (int) ( $filesystem['active'] ?? 0 ),
			'stale'             => (int) ( $database['stale'] ?? 0 ) + (int) ( $filesystem['stale'] ?? 0 ),
			'retention_enabled' => true,
			'policy'            => self::retention_policy(),
		);
	}

	/**
	 * Safely prune stale lock rows and unlocked stale filesystem lock files.
	 *
	 * @return array<string,mixed>
	 */
	public static function prune_stale( string $workspace_path, bool $dry_run = false ): array {
		$before    = self::status($workspace_path);
		$db_pruned = $dry_run ? array( 'dry_run' => true ) : WorkspaceLockStore::prune_expired();
		$fs_pruned = self::prune_stale_filesystem_locks($workspace_path, $dry_run);
		$after     = self::status($workspace_path);

		return array(
			'dry_run'    => $dry_run,
			'before'     => $before,
			'database'   => $db_pruned,
			'filesystem' => $fs_pruned,
			'after'      => $after,
		);
	}

	public function __destruct() {
		$this->release();
	}

	private static function sanitize_repo_key( string $repo ): string {
		$repo = preg_replace('/[^a-zA-Z0-9._-]/', '', $repo);
		return trim( (string) $repo, '-.');
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function filesystem_status( string $workspace_path ): array {
		$lock_dir = rtrim($workspace_path, '/') . '/.locks';
		$files    = is_dir($lock_dir) ? glob($lock_dir . '/*.lock') : array();
		$files    = false === $files ? array() : $files;
		$policy   = self::retention_policy();
		$cutoff   = time() - (int) $policy['filesystem_stale_after_seconds'];
		$active   = 0;
		$stale    = 0;
		$recent   = 0;

		foreach ( $files as $file ) {
			$handle = fopen($file, 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				continue;
			}

			if ( ! flock($handle, LOCK_EX | LOCK_NB) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				++$active;
				fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				continue;
			}

			flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$mtime = filemtime($file);
			if ( false !== $mtime && $mtime < $cutoff ) {
				++$stale;
			} else {
				++$recent;
			}
		}

		return array(
			'lock_dir' => $lock_dir,
			'total'    => count($files),
			'active'   => $active,
			'stale'    => $stale,
			'recent'   => $recent,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function prune_stale_filesystem_locks( string $workspace_path, bool $dry_run ): array {
		$lock_dir = rtrim($workspace_path, '/') . '/.locks';
		$files    = is_dir($lock_dir) ? glob($lock_dir . '/*.lock') : array();
		$files    = false === $files ? array() : $files;
		$policy   = self::retention_policy();
		$cutoff   = time() - (int) $policy['filesystem_stale_after_seconds'];
		$removed  = array();
		$skipped  = array();

		foreach ( $files as $file ) {
			$mtime = filemtime($file);
			if ( false === $mtime || $mtime >= $cutoff ) {
				$skipped[] = array(
					'path'   => $file,
					'reason' => 'not_stale',
				);
				continue;
			}

			$handle = fopen($file, 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				$skipped[] = array(
					'path'   => $file,
					'reason' => 'open_failed',
				);
				continue;
			}

			if ( ! flock($handle, LOCK_EX | LOCK_NB) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				$skipped[] = array(
					'path'   => $file,
					'reason' => 'active',
				);
				fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				continue;
			}

			if ( ! $dry_run ) {
				unlink($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$removed[] = $file;
		}

		return array(
			'dry_run'       => $dry_run,
			'removed_count' => count($removed),
			'removed'       => $removed,
			'skipped_count' => count($skipped),
			'skipped'       => $skipped,
		);
	}

	/**
	 * @return array<string,int>
	 */
	private static function retention_policy(): array {
		$policy = array(
			'filesystem_stale_after_seconds' => 86400,
		);
		if ( function_exists('apply_filters') ) {
			$policy = (array) apply_filters('datamachine_code_cleanup_lock_retention_policy', $policy);
		}

		return array(
			'filesystem_stale_after_seconds' => max(60, (int) ( $policy['filesystem_stale_after_seconds'] ?? 86400 )),
		);
	}
}
