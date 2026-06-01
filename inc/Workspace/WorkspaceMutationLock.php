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
							'owner_context'  => WorkspaceLockStore::default_owner_context(),
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
				$error_data = self::busy_error_data($repo, $lock_path);
				return new \WP_Error(
					'workspace_repo_busy',
					sprintf(
						'Workspace repo "%s" is busy with another worktree lifecycle mutation. Retry after the current add/remove/cleanup/prune operation completes. Inspect lock status with `%s`; prune stale/orphaned locks with `%s` after confirming no active holder remains.',
						$repo,
						(string) ( $error_data['status_command'] ?? 'wp datamachine-code workspace worktree locks --format=json' ),
						(string) ( $error_data['stale_prune_command'] ?? 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json' )
					),
					$error_data
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
			'active'            => self::logical_lock_count($database, $filesystem, 'active'),
			'stale'             => self::logical_lock_count($database, $filesystem, 'stale'),
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
	 * Count logical locks once when DB rows and flock files describe the same key.
	 *
	 * @param array<string,mixed> $database   DB lock status.
	 * @param array<string,mixed> $filesystem Filesystem lock status.
	 */
	private static function logical_lock_count( array $database, array $filesystem, string $state ): int {
		$database_count   = (int) ( $database[ $state ] ?? 0 );
		$filesystem_count = (int) ( $filesystem[ $state ] ?? 0 );
		$database_keys    = self::lock_status_keys($database, $state);
		$filesystem_keys  = self::lock_status_keys($filesystem, $state);

		if ( array() === $database_keys && array() === $filesystem_keys ) {
			return $database_count + $filesystem_count;
		}

		$known_count = count(array_unique(array_merge($database_keys, $filesystem_keys)));
		$unknown_db  = max(0, $database_count - count($database_keys));
		$unknown_fs  = max(0, $filesystem_count - count($filesystem_keys));

		return $known_count + $unknown_db + $unknown_fs;
	}

	/**
	 * @param array<string,mixed> $status Lock status.
	 * @return array<int,string>
	 */
	private static function lock_status_keys( array $status, string $state ): array {
		$keys = $status[ $state . '_keys' ] ?? array();
		if ( ! is_array($keys) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map('strval', $keys),
				static fn( string $key ): bool => '' !== $key
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function busy_error_data( string $repo, string $lock_path ): array {
		$lock_key = 'worktree-' . $repo;
		$data     = array(
			'status'              => 423,
			'retryable'           => true,
			'repo'                => $repo,
			'scope'               => $repo,
			'lock_key'            => $lock_key,
			'lock_path'           => $lock_path,
			'status_command'      => 'wp datamachine-code workspace worktree locks --format=json',
			'stale_prune_command' => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			'recovery_guidance'   => self::recovery_guidance(),
		);

		$filesystem_lock = self::filesystem_lock_entry($lock_path);
		if ( ! empty($filesystem_lock) ) {
			$data['filesystem_lock'] = $filesystem_lock;
			if ( isset($filesystem_lock['age_seconds']) ) {
				$data['filesystem_age_seconds'] = (int) $filesystem_lock['age_seconds'];
			}
		}

		$active_lock = WorkspaceLockStore::active_lock($lock_key, $repo);
		if ( is_array($active_lock) ) {
			$data['active_lock'] = $active_lock;
			if ( isset($active_lock['retry_after_seconds']) ) {
				$data['retry_after_seconds'] = (int) $active_lock['retry_after_seconds'];
			}
			if ( isset($active_lock['age_seconds']) ) {
				$data['age_seconds'] = (int) $active_lock['age_seconds'];
			}
		} elseif ( is_wp_error($active_lock) ) {
			$data['lock_store_error'] = $active_lock->get_error_message();
		}

		return $data;
	}

	/**
	 * Operator guidance shared by busy errors and status commands.
	 *
	 * @return array<string,mixed>
	 */
	private static function recovery_guidance(): array {
		return array(
			'status_command'      => 'wp datamachine-code workspace worktree locks --format=json',
			'dry_run_command'     => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			'apply_command'       => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			'safety'              => 'Only expired DB rows and old unlocked filesystem lock files are pruned. Active filesystem flocks are never removed by this command.',
			'active_lock_note'    => 'If a filesystem lock is active without DB owner evidence, another process still holds the OS file descriptor or crashed without releasing an operator-visible DB row. Inspect running DMC/WP-CLI processes before retrying.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function filesystem_status( string $workspace_path ): array {
		$lock_dir    = rtrim($workspace_path, '/') . '/.locks';
		$files       = is_dir($lock_dir) ? glob($lock_dir . '/*.lock') : array();
		$files       = false === $files ? array() : $files;
		$active      = 0;
		$stale       = 0;
		$recent      = 0;
		$active_keys = array();
		$stale_keys  = array();
		$locks       = array();

		foreach ( $files as $file ) {
			$entry = self::filesystem_lock_entry($file);
			if ( empty($entry) ) {
				continue;
			}
			$locks[] = $entry;
			if ( 'active' === (string) ( $entry['state'] ?? '' ) ) {
				++$active;
				$active_keys[] = (string) ( $entry['lock_key'] ?? '' );
				continue;
			}
			if ( 'stale' === (string) ( $entry['state'] ?? '' ) ) {
				++$stale;
				$stale_keys[] = (string) ( $entry['lock_key'] ?? '' );
			} else {
				++$recent;
			}
		}

		return array(
			'lock_dir'    => $lock_dir,
			'total'       => count($files),
			'active'      => $active,
			'active_keys' => array_values(array_filter($active_keys)),
			'stale'       => $stale,
			'stale_keys'  => array_values(array_filter($stale_keys)),
			'recent'      => $recent,
			'locks'       => $locks,
			'guidance'    => self::recovery_guidance(),
		);
	}

	/**
	 * Inspect one filesystem lock file without mutating it.
	 *
	 * @return array<string,mixed>
	 */
	private static function filesystem_lock_entry( string $file ): array {
		$policy   = self::retention_policy();
		$cutoff   = time() - (int) $policy['filesystem_stale_after_seconds'];
		$lock_key = self::lock_key_from_path($file);
		$scope    = str_starts_with($lock_key, 'worktree-') ? substr($lock_key, strlen('worktree-')) : $lock_key;
		$mtime    = filemtime($file);
		$entry    = array(
			'lock_key'            => $lock_key,
			'scope'               => $scope,
			'path'                => $file,
			'mtime'               => false === $mtime ? null : gmdate('c', $mtime),
			'age_seconds'         => false === $mtime ? null : max(0, time() - $mtime),
			'stale_after_seconds' => (int) $policy['filesystem_stale_after_seconds'],
		);

		$handle = fopen($file, 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$entry['state']  = 'unknown';
			$entry['reason'] = 'open_failed';
			return $entry;
		}

		if ( ! flock($handle, LOCK_EX | LOCK_NB) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			$entry['state']               = 'active';
			$entry['reason']              = 'filesystem_flock_held';
			$entry['owner_evidence']      = self::owner_evidence_for_lock($lock_key, $scope);
			$entry['recovery_command']    = 'wp datamachine-code workspace worktree locks --format=json';
			$entry['safe_to_prune']       = false;
			$entry['operator_guidance']   = 'An active OS flock cannot be safely pruned. Inspect the owner evidence or running DMC/WP-CLI processes and retry after the holder exits.';
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $entry;
		}

		flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$stale                    = false !== $mtime && $mtime < $cutoff;
		$entry['state']           = $stale ? 'stale' : 'recent';
		$entry['reason']          = $stale ? 'unlocked_stale_file' : 'unlocked_recent_file';
		$entry['safe_to_prune']   = $stale;
		$entry['recovery_command'] = $stale
			? 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json'
			: 'wp datamachine-code workspace worktree locks --format=json';

		return $entry;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function owner_evidence_for_lock( string $lock_key, string $scope ): array {
		$active_lock = WorkspaceLockStore::active_lock($lock_key, $scope);
		if ( is_array($active_lock) ) {
			return array(
				'source' => 'database',
				'lock'   => $active_lock,
			);
		}
		if ( is_wp_error($active_lock) ) {
			return array(
				'source'  => 'database_error',
				'message' => $active_lock->get_error_message(),
			);
		}

		return array(
			'source'  => 'filesystem_only',
			'message' => 'No active DB lock row is visible for this held filesystem flock.',
		);
	}

	private static function lock_key_from_path( string $path ): string {
		$filename = basename($path);
		return str_ends_with($filename, '.lock') ? substr($filename, 0, -5) : $filename;
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
