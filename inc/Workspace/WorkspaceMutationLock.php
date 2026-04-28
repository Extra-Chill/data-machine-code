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

defined( 'ABSPATH' ) || exit;

final class WorkspaceMutationLock {

	private const POLL_USEC = 100000;

	/** @var resource|null */
	private $handle = null;

	private function __construct( $handle ) {
		$this->handle = $handle;
	}

	/**
	 * Run a callback while holding a per-primary-repo workspace mutation lock.
	 *
	 * @param string   $workspace_path Workspace root.
	 * @param string   $repo           Primary repo handle.
	 * @param callable $callback       Callback to run while locked.
	 * @param int      $timeout        Seconds to wait for the lock.
	 * @return mixed|\WP_Error Callback result or lock acquisition error.
	 */
	public static function with_repo(
		string $workspace_path,
		string $repo,
		callable $callback,
		int $timeout = 30
	): mixed {
		$lock = self::acquire( $workspace_path, $repo, $timeout );
		if ( is_wp_error( $lock ) ) {
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
	 * @param string $workspace_path Workspace root.
	 * @param string $repo           Primary repo handle.
	 * @param int    $timeout        Seconds to wait for the lock.
	 * @return self|\WP_Error Lock object or retryable error.
	 */
	public static function acquire( string $workspace_path, string $repo, int $timeout = 30 ): self|\WP_Error {
		$workspace_path = rtrim( $workspace_path, '/' );
		$repo           = self::sanitize_repo_key( $repo );

		if ( '' === $workspace_path || '' === $repo ) {
			return new \WP_Error(
				'workspace_lock_invalid_target',
				'Workspace mutation lock requires a workspace path and repo handle.',
				array( 'status' => 400 )
			);
		}

		$lock_dir = $workspace_path . '/.locks';
		if ( ! is_dir( $lock_dir ) ) {
			$created = function_exists( 'wp_mkdir_p' )
				? wp_mkdir_p( $lock_dir )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback for non-WordPress smoke tests.
				: mkdir( $lock_dir, 0755, true );
			if ( ! $created && ! is_dir( $lock_dir ) ) {
				return new \WP_Error(
					'workspace_lock_create_failed',
					sprintf( 'Failed to create workspace lock directory: %s', $lock_dir ),
					array( 'status' => 500 )
				);
			}
		}

		$lock_path = $lock_dir . '/worktree-' . $repo . '.lock';
		$handle    = fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error(
				'workspace_lock_open_failed',
				sprintf( 'Failed to open workspace mutation lock: %s', $lock_path ),
				array( 'status' => 500 )
			);
		}

		$started = microtime( true );
		$timeout = max( 0, $timeout );

		do {
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				return new self( $handle );
			}

			if ( 0 === $timeout || ( microtime( true ) - $started ) >= $timeout ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
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

			usleep( self::POLL_USEC );
		} while ( true );
	}

	public function release(): void {
		if ( null === $this->handle ) {
			return;
		}

		flock( $this->handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->handle = null;
	}

	public function __destruct() {
		$this->release();
	}

	private static function sanitize_repo_key( string $repo ): string {
		$repo = preg_replace( '/[^a-zA-Z0-9._-]/', '', $repo );
		return trim( (string) $repo, '-.' );
	}
}
