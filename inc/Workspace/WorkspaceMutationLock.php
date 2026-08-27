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

use DataMachineCode\Support\WallClockBudget;

defined('ABSPATH') || exit;

if ( ! class_exists(WorkspaceLockStore::class) ) {
	include_once __DIR__ . '/WorkspaceLockStore.php';
}
if ( ! class_exists(WallClockBudget::class) ) {
	include_once dirname(__DIR__) . '/Support/WallClockBudget.php';
}
if ( ! class_exists(TaskUrl::class) ) {
	include_once __DIR__ . '/TaskUrl.php';
}

final class WorkspaceMutationLock {



	private const POLL_USEC                 = 100000;
	private const REQUEST_EXPIRES_SECONDS   = 900;
	private const PROGRESS_INTERVAL_SECONDS = 5.0;

	/**
	 * @var resource|null
	 */
	private $handle = null;

	private int $lock_id = 0;

	private string $request_path;

	/** @var array<string,mixed> */
	private array $metadata;

	private ?float $operation_deadline;

	private function __construct( $handle, int $lock_id = 0, string $request_path = '', array $metadata = array(), ?float $operation_deadline = null ) {
		$this->handle             = $handle;
		$this->lock_id            = $lock_id;
		$this->request_path       = $request_path;
		$this->metadata           = $metadata;
		$this->operation_deadline = $operation_deadline;
	}

	/**
	 * Run a callback while holding a per-primary-repo workspace mutation lock.
	 *
	 * @param  string   $workspace_path Workspace root.
	 * @param  string   $repo           Primary repo handle.
	 * @param  callable $callback       Callback to run while locked.
	 * @param  int      $timeout        Seconds to wait for the lock.
	 * @param  array    $metadata       Owner evidence persisted with the lock.
	 * @param  callable|null $progress_callback Best-effort admission observer.
	 * @return mixed|\WP_Error Callback result or lock acquisition error.
	 */
	public static function with_repo(
		string $workspace_path,
		string $repo,
		callable $callback,
		int $timeout = 30,
		array $metadata = array(),
		?callable $progress_callback = null
	): mixed {
		$lock = self::acquire($workspace_path, $repo, $timeout, $metadata, $progress_callback);
		if ( is_wp_error($lock) ) {
			return $lock;
		}

		$result  = null;
		$release = true;
		try {
			$result = self::invoke_callback($callback, $lock);
		} finally {
			$release = $lock->release();
		}
		return is_wp_error($release) ? self::completed_callback_error($release, $result) : $result;
	}

	/**
	 * Run a callback while holding a deterministic set of repository locks.
	 *
	 * Callers that also need the workspace capacity lock must acquire it first.
	 *
	 * @param array<int,string> $repos Primary repository handles.
	 */
	public static function with_repos( string $workspace_path, array $repos, callable $callback, int $timeout = 30 ): mixed {
		$repos = array_values(array_unique(array_filter(array_map(array( self::class, 'sanitize_repo_key' ), $repos))));
		sort($repos, SORT_STRING);
		$locks         = array();
		$result        = null;
		$release_error = null;

		try {
			foreach ( $repos as $repo ) {
				$lock = self::acquire($workspace_path, $repo, $timeout);
				if ( is_wp_error($lock) ) {
					$result = $lock;
					break;
				}
				$locks[] = $lock;
			}

			if ( ! is_wp_error($result) ) {
				$result = $callback();
			}
		} finally {
			foreach ( array_reverse($locks) as $lock ) {
				$released = $lock->release();
				if ( null === $release_error && is_wp_error($released) ) {
					$release_error = $released;
				}
			}
		}
		return $release_error instanceof \WP_Error ? self::completed_callback_error($release_error, $result) : $result;
	}

	/**
	 * Acquire a per-primary-repo lock.
	 *
	 * @param  string $workspace_path Workspace root.
	 * @param  string $repo           Primary repo handle.
	 * @param  int    $timeout        Seconds to wait for the lock.
	 * @param  array  $metadata       Owner evidence persisted with the lock.
	 * @param  callable|null $progress_callback Best-effort admission observer.
	 * @return self|\WP_Error Lock object or retryable error.
	 */
	public static function acquire( string $workspace_path, string $repo, int $timeout = 30, array $metadata = array(), ?callable $progress_callback = null ): self|\WP_Error {
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
			 // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic local lock setup handles the suppressed race by rechecking the directory.
			: @mkdir($lock_dir, 0755, true);
			if ( ! $created && ! is_dir($lock_dir) ) {
				return new \WP_Error(
					'workspace_lock_create_failed',
					sprintf('Failed to create workspace lock directory: %s', $lock_dir),
					array( 'status' => 500 )
				);
			}
		}

		$lock_path = $lock_dir . '/worktree-' . $repo . '.lock';
		self::prune_stale_requests($lock_dir);
		$request_path = self::record_request($lock_dir, $repo, $lock_path);
		if ( '' === $request_path || ! is_file($request_path) ) {
			return new \WP_Error(
				'workspace_lock_request_create_failed',
				sprintf('Failed to persist workspace lock request for "%s".', $repo),
				array(
					'status'             => 500,
					'retryable'          => true,
					'repo'               => $repo,
					'scope'              => $repo,
					'lock_key'           => 'worktree-' . $repo,
					'mutation_committed' => false,
				)
			);
		}
		$request_id   = '' === $request_path ? '' : basename($request_path, '.json');
		$handle       = fopen($lock_path, 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			self::remove_request($request_path);
			return new \WP_Error(
				'workspace_lock_open_failed',
				sprintf('Failed to open workspace mutation lock: %s', $lock_path),
				array( 'status' => 500 )
			);
		}

		$started = microtime(true);
		$timeout = max(0, $timeout);
		$first_attempt        = true;
		$acquisition_deadline = isset($metadata['_acquisition_deadline']) && is_numeric($metadata['_acquisition_deadline']) ? (float) $metadata['_acquisition_deadline'] : null;
		$operation_deadline   = isset($metadata['_operation_deadline']) && is_numeric($metadata['_operation_deadline']) ? (float) $metadata['_operation_deadline'] : null;
		unset($metadata['_acquisition_deadline']);
		unset($metadata['_operation_deadline']);
		self::emit_progress($progress_callback, array(
			'operation'            => 'workspace_mutation_lock',
			'phase'                => 'lock_request',
			'state'                => 'registered',
			'request_id'           => $request_id,
			'scope'                => $repo,
			'lock_key'             => 'worktree-' . $repo,
			'queue_position'       => self::request_queue_position($lock_dir, $lock_path, $request_path),
			'wait_timeout_seconds' => $timeout,
		));
		if ( 0 < $timeout && ( microtime(true) - $started ) >= $timeout ) {
			return self::timed_out_error($repo, $lock_path, $request_path, $timeout, $started, $handle, $progress_callback);
		}
		$last_progress = null;

		do {
			self::update_request($request_path, 'queued');
			$acquired = self::request_is_head($lock_dir, $lock_path, $request_path) && flock($handle, LOCK_EX | LOCK_NB); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			$elapsed  = microtime(true) - $started;
			$deadline_live = null === $acquisition_deadline || microtime(true) < $acquisition_deadline;
			if ( $acquired && $deadline_live && ( ( 0 === $timeout && $first_attempt ) || $elapsed < $timeout ) ) {
				$metadata                  = WorkspaceLockStore::activate_lease($metadata);
				$metadata['owner_context'] = WorkspaceLockStore::default_owner_context();
				self::update_request($request_path, 'acquiring');
				$registration_deadline = $acquisition_deadline ?? $operation_deadline;
				$registration_wait_ms  = null === $registration_deadline ? null : max(1, (int) floor(($registration_deadline - microtime(true)) * 1000));
				$lock_id = WorkspaceLockStore::register_acquired(
					array(
						'lock_key' => 'worktree-' . $repo,
						'purpose'  => 'workspace_repo_mutation',
						'scope'    => $repo,
						'metadata' => array_merge($metadata, array(
							'workspace_path' => $workspace_path,
							'lock_path'      => $lock_path,
							'request_id'     => $request_id,
							'owner_context'  => $metadata['owner_context'],
						)),
						'max_wait_ms' => $registration_wait_ms,
					)
				);
				if ( is_wp_error($lock_id) ) {
					self::remove_request($request_path);
						flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
						fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return self::admission_error($lock_id, $repo, $lock_path, $request_id);
				}

				self::update_request($request_path, 'acquired');
				return new self($handle, (int) $lock_id, $request_path, $metadata, $operation_deadline);
			}
			if ( $acquired ) {
				flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			}

			$now = microtime(true);
			if ( null === $last_progress || ( $now - $last_progress ) >= self::PROGRESS_INTERVAL_SECONDS ) {
				self::emit_lock_wait_progress($progress_callback, $repo, $lock_path, $request_path, $timeout, $started, 'queued');
				$now           = microtime(true);
				$last_progress = $now;
			}

			if ( 0 === $timeout || ( $now - $started ) >= $timeout || ! $deadline_live ) {
				return self::timed_out_error($repo, $lock_path, $request_path, $timeout, $started, $handle, $progress_callback);
			}

			$first_attempt = false;
			usleep(self::POLL_USEC);
		} while ( true );
	}

	public function release(): true|\WP_Error {
		if ( null === $this->handle ) {
			return true;
		}

		$lock_id = $this->lock_id;
		flock($this->handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose($this->handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->handle  = null;
		$this->lock_id = 0;
		self::remove_request($this->request_path);
		$this->request_path = '';
		$release_wait_ms = null === $this->operation_deadline ? null : max(1, (int) floor(($this->operation_deadline - microtime(true)) * 1000));
		$released        = WorkspaceLockStore::release($lock_id, $release_wait_ms);
		if ( is_wp_error($released) ) {
			$data = array_merge( (array) $released->get_error_data(), array( 'filesystem_lock_released' => true ));
			return new \WP_Error($released->get_error_code(), $released->get_error_message(), $data);
		}
		return true;
	}

	/** Refresh the DB lease for callers that reach a bounded long-running phase. */
	public function heartbeat( array $metadata = array() ): bool|\WP_Error {
		if ( null === $this->handle ) {
			return false;
		}
		// Filesystem-only installs retain OS-flock safety without a DB row to renew.
		if ( $this->lock_id <= 0 ) {
			return true;
		}
		$this->metadata = array_merge($this->metadata, $metadata);
		return WorkspaceLockStore::heartbeat($this->lock_id, $this->metadata);
	}

	/** Return the acquisition-bounded lease deadline, when one was declared. */
	public function lease_deadline(): ?int {
		$deadline = strtotime((string) ( $this->metadata['expected_release_at'] ?? '' ));
		return false === $deadline ? null : $deadline;
	}

	/** Whether this object still owns its authoritative OS lock handle. */
	public function is_active(): bool {
		return null !== $this->handle;
	}

	/** Return bounded ownership evidence without exposing arbitrary caller metadata. */
	public function lease_evidence(): array {
		return array(
			'os_lock_active'        => $this->is_active(),
			'lease_strategy'        => $this->metadata['lease_strategy'] ?? null,
			'lease_activated_at'    => $this->metadata['lease_activated_at'] ?? null,
			'expected_release_at'   => $this->metadata['expected_release_at'] ?? null,
			'lease_duration_seconds' => isset($this->metadata['lease_duration_seconds']) ? (int) $this->metadata['lease_duration_seconds'] : null,
			'owner'                 => $this->metadata['owner_context'] ?? WorkspaceLockStore::default_owner_context(),
		);
	}

	/** Invoke legacy zero-argument callbacks without a new argument. */
	private static function invoke_callback( callable $callback, self $lock ): mixed {
		try {
			$reflection = $callback instanceof \Closure
				? new \ReflectionFunction($callback)
				: ( is_array($callback)
					? new \ReflectionMethod($callback[0], $callback[1])
					: ( is_object($callback)
						? new \ReflectionMethod($callback, '__invoke')
						: ( str_contains($callback, '::') ? new \ReflectionMethod($callback) : new \ReflectionFunction($callback) )
					)
				);
			if ( 0 === $reflection->getNumberOfParameters() && ! $reflection->isVariadic() ) {
				return $callback();
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Reflection is only a compatibility probe.
		} catch ( \ReflectionException | \TypeError ) {
			// Callable reflection is best-effort; PHP remains the invocation authority.
		}

		return $callback($lock);
	}

	/**
	 * Close an expired waiter without allowing its protected mutation to run.
	 *
	 * @param resource $handle Open filesystem lock handle.
	 */
	private static function timed_out_error( string $repo, string $lock_path, string $request_path, int $timeout, float $started, $handle, ?callable $progress_callback ): \WP_Error {
		$error_data                         = self::busy_error_data($repo, $lock_path, $request_path);
		$error_data['wait_timeout_seconds'] = $timeout;
		$error_data['timed_out']            = true;
		self::remove_request($request_path);
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::emit_lock_wait_progress($progress_callback, $repo, $lock_path, $request_path, $timeout, $started, 'timed_out', $error_data);
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

	/** Emit queue progress without allowing diagnostics to alter lock admission. */
	private static function emit_lock_wait_progress( ?callable $callback, string $repo, string $lock_path, string $request_path, int $timeout, float $started, string $state, ?array $data = null ): void {
		if ( null === $callback ) {
			return;
		}
		$data = $data ?? self::busy_error_data($repo, $lock_path, $request_path);
		self::emit_progress($callback, array_filter(array(
			'operation'              => 'workspace_mutation_lock',
			'phase'                  => 'lock_wait',
			'state'                  => $state,
			'request_id'             => $data['request_id'] ?? basename($request_path, '.json'),
			'scope'                  => $repo,
			'lock_key'               => $data['lock_key'] ?? 'worktree-' . $repo,
			'queue_position'         => $data['queue_position'] ?? null,
			'owner'                  => $data['owner'] ?? null,
			'elapsed_seconds'        => round(max(0.0, microtime(true) - $started), 3),
			'wait_timeout_seconds'   => $timeout,
			'retry_after_seconds'    => $data['retry_after_seconds'] ?? null,
			'estimated_wait_seconds' => $data['estimated_wait_seconds'] ?? null,
			'eta_status'             => $data['eta_status'] ?? null,
		), static fn( mixed $value ): bool => null !== $value && '' !== $value));
	}

	/** Best-effort observers must never interrupt a protected mutation. */
	private static function emit_progress( ?callable $callback, array $event ): void {
		if ( null === $callback ) {
			return;
		}
		try {
			$callback($event);
		} catch ( \Throwable ) {
			// Presentation failures cannot change lock ownership or queue order.
		}
	}

	/** Preserve terminal ownership-write failure after a callback has returned. */
	private static function completed_callback_error( \WP_Error $error, mixed $result ): \WP_Error {
		if ( is_wp_error($result) ) {
			$release_data = (array) $error->get_error_data();
			$data         = array_merge(
				(array) $result->get_error_data(),
				array(
					'lock_release_error' => array(
						'code'                     => $error->get_error_code(),
						'operation'                => $release_data['operation'] ?? null,
						'retryable'                => $release_data['retryable'] ?? false,
						'filesystem_lock_released' => $release_data['filesystem_lock_released'] ?? false,
					),
				)
			);
			return new \WP_Error($result->get_error_code(), $result->get_error_message(), $data);
		}

		$data = array_merge(
			(array) $error->get_error_data(),
			array(
				'lock_callback_completed'  => true,
				'lock_callback_error_code' => null,
			)
		);
		return new \WP_Error($error->get_error_code(), $error->get_error_message(), $data);
	}

	/**
	 * Summarize DB-visible and filesystem lock state.
	 *
	 * @return array<string,mixed>
	 */
	public static function status( string $workspace_path, ?WallClockBudget $budget = null ): array {
		$budget     = $budget ?? WallClockBudget::from_seconds(5, '5s');
		$filesystem = self::filesystem_status($workspace_path, $budget);
		$queue      = $budget->expired() ? array() : self::queued_requests($workspace_path, $budget);
		$database   = $budget->remaining_seconds() < 1.0
			? array( 'available' => false, 'state' => 'budget_exhausted', 'active' => 0, 'stale' => 0, 'released' => 0, 'total' => 0 )
			: WorkspaceLockStore::status();
		$stale      = self::stale_lock_report($database, $filesystem, $budget);
		$partial    = $budget->expired() || ! empty($filesystem['partial']) || ! empty($stale['partial']) || empty($database['available']);

		return array(
			'database'          => $database,
			'filesystem'        => $filesystem,
			'active'            => self::logical_lock_count($database, $filesystem, 'active'),
			'stale'             => self::logical_lock_count($database, $filesystem, 'stale'),
			'stale_locks'       => $stale,
			'prune_commands'    => array(
				'preview' => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
				'apply'   => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			),
			'retention_enabled' => true,
			'policy'            => self::retention_policy(),
			'queue'             => $queue,
			'partial'           => $partial,
			'evidence'          => array( 'wall_clock_budget' => $budget->evidence() ),
		);
	}

	/**
	 * Safely prune stale lock rows and unlocked stale filesystem lock files.
	 *
	 * @return array<string,mixed>
	 */
	public static function prune_stale( string $workspace_path, bool $dry_run = false, ?WallClockBudget $budget = null ): array {
		$budget    = $budget ?? WallClockBudget::from_seconds(10, '10s');
		$before    = self::status($workspace_path, $budget);
		$protected = self::active_filesystem_lock_keys( (array) ( $before['filesystem'] ?? array() ) );
		$db_pruned = $budget->remaining_seconds() < 1.0 ? array(
			'dry_run'         => $dry_run,
			'skipped'         => true,
			'reason'          => 'budget_exhausted',
			'candidate_count' => (int) ( $before['database']['stale'] ?? 0 ),
		) : ( $dry_run ? array(
			'dry_run'          => true,
			'protected_active' => count($protected),
			'protected_keys'   => $protected,
			'candidate_count'  => (int) ( $before['database']['stale'] ?? 0 ),
		) : WorkspaceLockStore::prune_expired($protected) );
		$fs_pruned = self::prune_stale_filesystem_locks($workspace_path, $dry_run, $budget);
		$after     = $budget->remaining_seconds() < 1.0 ? array( 'available' => false, 'state' => 'budget_exhausted' ) : self::status($workspace_path, $budget);

		return array(
			'dry_run'    => $dry_run,
			'before'     => $before,
			'database'   => $db_pruned,
			'filesystem' => $fs_pruned,
			'after'      => $after,
			'partial'    => $budget->expired() || ! empty($fs_pruned['partial']) || 'budget_exhausted' === (string) ( $after['state'] ?? '' ),
			'evidence'   => array( 'wall_clock_budget' => $budget->evidence() ),
		);
	}

	public function __destruct() {
		$this->release();
	}

	private static function sanitize_repo_key( string $repo ): string {
		$repo = preg_replace('/[^a-zA-Z0-9._-]/', '', $repo);
		return trim( (string) $repo, '-.');
	}

	private static function admission_error( \WP_Error $error, string $repo, string $lock_path, string $request_id ): \WP_Error {
		$data  = (array) $error->get_error_data();
		$owner = WorkspaceLockStore::default_owner_context();
		unset($owner['wp_cli_args']);
		if ( isset($owner['datamachine_task_url']) ) {
			$task_url = TaskUrl::canonicalize_for_replay($owner['datamachine_task_url']);
			if ( null === $task_url ) {
				unset($owner['datamachine_task_url']);
			} else {
				$owner['datamachine_task_url'] = $task_url;
			}
		}
		$data = array_merge(
			$data,
			array(
				'resource'              => $lock_path,
				'repo'                  => $repo,
				'request_id'            => $request_id,
				'owner'                 => $owner,
				'lock_callback_started' => false,
			)
		);
		return new \WP_Error($error->get_error_code(), $error->get_error_message(), $data);
	}

	private static function record_request( string $lock_dir, string $repo, string $lock_path ): string {
		$request_dir = $lock_dir . '/requests';
		if ( ! is_dir($request_dir) && ! @mkdir($request_dir, 0755, true) && ! is_dir($request_dir) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic local lock setup handles the suppressed race by rechecking the directory.
			return '';
		}
		$request_id     = bin2hex(random_bytes(12));
		$path           = $request_dir . '/' . $request_id . '.json';
		$queue_position = count(self::queued_requests_for_resource($lock_dir, $lock_path)) + 1;
		self::write_request($path, array(
			'request_id'     => $request_id,
			'repo'           => $repo,
			'resource'       => $lock_path,
			'state'          => 'queued',
			'pid'            => getmypid(),
			'created_at'     => self::request_time(),
			'heartbeat_at'   => self::request_time(),
			'expires_at'     => self::request_expires_at(),
			'queue_order'    => sprintf('%020.6F-%s', microtime(true), $request_id),
			'queue_position' => $queue_position,
		));
		return $path;
	}

	private static function request_queue_position( string $lock_dir, string $lock_path, string $request_path ): int {
		foreach ( self::queued_requests_for_resource($lock_dir, $lock_path) as $position => $request ) {
			if ( (string) ( $request['path'] ?? '' ) === $request_path ) {
				return $position + 1;
			}
		}
		return 1;
	}

	private static function update_request( string $path, string $state ): void {
		if ( '' === $path || ! is_file($path) ) {
			return;
		}
		$data = self::read_request($path);
		if ( ! is_array($data) ) {
			return;
		}
		$data['state']        = $state;
		$data['updated_at']   = self::request_time();
		$data['heartbeat_at'] = self::request_time();
		$data['expires_at']   = self::request_expires_at();
		self::write_request($path, $data);
	}

	private static function write_request( string $path, array $data ): void {
		if ( '' !== $path ) {
			$json      = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This lock primitive also runs outside WordPress bootstrap.
			$temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
			if ( false !== file_put_contents($temporary, false === $json ? '{}' : (string) $json, LOCK_EX) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				rename($temporary, $path); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		}
	}

	private static function remove_request( string $path ): void {
		if ( '' !== $path && is_file($path) ) {
			unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/** A request can be removed by its owner while a concurrent inspector scans it. */
	private static function read_request( string $path ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished local queue file is an expected concurrent release race.
		$json = @file_get_contents($path);
		$data = false === $json ? null : json_decode($json, true);
		return is_array($data) ? $data : null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function queued_requests( string $workspace_path, ?WallClockBudget $budget = null ): array {
		$lock_dir = rtrim($workspace_path, '/') . '/.locks';
		self::prune_stale_requests($lock_dir, $budget);
		$files = glob($lock_dir . '/requests/*.json');
		$files = false !== $files ? $files : array();
		$rows  = array();
		foreach ( array_slice($files, 0, 25) as $file ) {
			if ( null !== $budget && $budget->expired() ) {
				break;
			}
			$row = self::read_request($file);
			if ( is_array($row) && ! self::request_is_stale($row) ) {
				$rows[] = $row;
			} elseif ( is_array($row) ) {
				self::remove_request($file);
			}
		}
		usort($rows, static fn( array $left, array $right ): int => strcmp( (string) ( $left['queue_order'] ?? $left['created_at'] ?? '' ), (string) ( $right['queue_order'] ?? $right['created_at'] ?? '' )));
		$positions = array();
		foreach ( $rows as &$row ) {
			if ( 'queued' !== (string) ( $row['state'] ?? '' ) ) {
				continue;
			}
			$resource               = (string) ( $row['resource'] ?? '' );
			$positions[ $resource ] = (int) ( $positions[ $resource ] ?? 0 ) + 1;
			$row['queue_position']  = $positions[ $resource ];
		}
		unset($row);
		return $rows;
	}

	/** Reclaim dead or expired queue tokens before selecting the FIFO head. */
	private static function prune_stale_requests( string $lock_dir, ?WallClockBudget $budget = null ): void {
		$files = glob($lock_dir . '/requests/*.json');
		foreach ( false === $files ? array() : $files as $file ) {
			if ( null !== $budget && $budget->expired() ) {
				break;
			}
			$request = self::read_request($file);
			if ( is_array($request) && self::request_is_stale($request) ) {
				self::remove_request($file);
			}
		}
	}

	/**
	 * A live request refreshes its token on every admission poll. A token without
	 * a fresh heartbeat is reclaimable even when its PID was reused or cannot be
	 * inspected; a fresh EPERM/no-POSIX token remains protected.
	 */
	private static function request_is_stale( array $request ): bool {
		if ( '' === (string) ( $request['request_id'] ?? '' ) ) {
			return true;
		}
		$liveness = self::request_owner_liveness($request);
		if ( 'exited' === $liveness['state'] ) {
			return true;
		}

		$expires = strtotime( (string) ( $request['expires_at'] ?? $request['updated_at'] ?? $request['created_at'] ?? '' ));
		return false === $expires || $expires < self::request_time_timestamp();
	}

	/** @return array{state:string,reason:string} */
	private static function request_owner_liveness( array $request ): array {
		$override = function_exists('apply_filters') ? apply_filters('datamachine_code_workspace_lock_request_liveness', null, $request) : null;
		if ( is_array($override) && in_array( (string) ( $override['state'] ?? '' ), array( 'live', 'exited', 'unknown' ), true) ) {
			return array(
				'state'  => (string) $override['state'],
				'reason' => (string) ( $override['reason'] ?? 'runtime_override' ),
			);
		}

		$pid = (int) ( $request['pid'] ?? 0 );
		if ( $pid <= 0 || ! function_exists('posix_kill') ) {
			return array(
				'state'  => 'unknown',
				'reason' => $pid <= 0 ? 'missing_pid' : 'posix_unavailable',
			);
		}
		if ( @posix_kill($pid, 0) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- POSIX liveness probes report false with an errno.
			return array(
				'state'  => 'live',
				'reason' => 'pid_signalable',
			);
		}
		$errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
		$esrch = defined('POSIX_ESRCH') ? POSIX_ESRCH : 3;
		$eperm = defined('POSIX_EPERM') ? POSIX_EPERM : 1;
		if ( $esrch === $errno ) {
			return array(
				'state'  => 'exited',
				'reason' => 'esrch',
			);
		}
		if ( $eperm === $errno ) {
			return array(
				'state'  => 'unknown',
				'reason' => 'eperm',
			);
		}
		return array(
			'state'  => 'unknown',
			'reason' => 'probe_failed',
		);
	}

	private static function request_time(): string {
		return gmdate('c', self::request_time_timestamp());
	}

	private static function request_expires_at(): string {
		return gmdate('c', self::request_time_timestamp() + self::request_expires_seconds());
	}

	private static function request_time_timestamp(): int {
		$time = time();
		if ( function_exists('apply_filters') ) {
			$time = (int) apply_filters('datamachine_code_workspace_lock_request_time', $time);
		}
		return max(0, $time);
	}

	private static function request_expires_seconds(): int {
		$seconds = self::REQUEST_EXPIRES_SECONDS;
		if ( function_exists('apply_filters') ) {
			$seconds = (int) apply_filters('datamachine_code_workspace_lock_request_expires_seconds', $seconds);
		}
		return max(1, $seconds);
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
	 * Build the operator-facing stale lock follow-up report.
	 *
	 * @param array<string,mixed> $database   DB lock status.
	 * @param array<string,mixed> $filesystem Filesystem lock status.
	 * @return array<string,mixed>
	 */
	private static function stale_lock_report( array $database, array $filesystem, ?WallClockBudget $budget = null ): array {
		$active_filesystem_keys = self::active_filesystem_lock_keys($filesystem);
		$database_rows          = array();
		foreach ( (array) ( $database['locks'] ?? array() ) as $lock ) {
			if ( null !== $budget && $budget->expired() ) {
				break;
			}
			if ( ! is_array($lock) || 'stale' !== (string) ( $lock['state'] ?? '' ) ) {
				continue;
			}
			$lock_key           = (string) ( $lock['lock_key'] ?? '' );
			$live_flock_present = in_array($lock_key, $active_filesystem_keys, true);
			$owner_context      = (array) ( $lock['metadata']['owner_context'] ?? array() );
			$database_rows[]    = array(
				'source'                   => 'database',
				'lock_key'                 => $lock_key,
				'scope'                    => (string) ( $lock['scope'] ?? '' ),
				'state'                    => 'stale',
				'owner'                    => (string) ( $lock['owner'] ?? '' ),
				'session'                  => self::owner_context_session_id($owner_context),
				'run_id'                   => (string) ( $lock['run_id'] ?? '' ),
				'job_id'                   => $lock['job_id'] ?? null,
				'acquired_at'              => (string) ( $lock['acquired_at'] ?? '' ),
				'heartbeat_at'             => (string) ( $lock['heartbeat_at'] ?? '' ),
				'expires_at'               => (string) ( $lock['expires_at'] ?? '' ),
				'age_seconds'              => $lock['age_seconds'] ?? null,
				'heartbeat_age_seconds'    => $lock['heartbeat_age_seconds'] ?? null,
				'expires_age_seconds'      => $lock['expires_age_seconds'] ?? null,
				'live_flock_present'       => $live_flock_present,
				'safe_to_prune'            => ! $live_flock_present,
				'preview_command'          => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
				'apply_command'            => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
				'active_lock_refusal_note' => $live_flock_present ? 'Matching filesystem lock has a live flock; DB row is reported but protected from stale pruning.' : '',
			);
		}

		$filesystem_rows = array();
		foreach ( (array) ( $filesystem['locks'] ?? array() ) as $lock ) {
			if ( null !== $budget && $budget->expired() ) {
				break;
			}
			if ( ! is_array($lock) || 'stale' !== (string) ( $lock['state'] ?? '' ) ) {
				continue;
			}
			$filesystem_rows[] = array(
				'source'             => 'filesystem',
				'lock_key'           => (string) ( $lock['lock_key'] ?? '' ),
				'scope'              => (string) ( $lock['scope'] ?? '' ),
				'state'              => 'stale',
				'path'               => (string) ( $lock['path'] ?? '' ),
				'mtime'              => $lock['mtime'] ?? null,
				'age_seconds'        => $lock['age_seconds'] ?? null,
				'live_flock_present' => false,
				'safe_to_prune'      => ! empty($lock['safe_to_prune']),
				'preview_command'    => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
				'apply_command'      => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			);
		}

		$count = count($database_rows) + count($filesystem_rows);
		return array(
			'count'                  => $count,
			'database_count'         => count($database_rows),
			'filesystem_count'       => count($filesystem_rows),
			'active_filesystem_keys' => $active_filesystem_keys,
			'preview_command'        => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			'apply_command'          => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			'safety'                 => 'Preview is non-destructive. Apply prunes expired DB rows and old unlocked filesystem lock files only; live filesystem flocks are reported and protected.',
			'database'               => $database_rows,
			'filesystem'             => $filesystem_rows,
			'partial'                => null !== $budget && $budget->expired(),
		);
	}

	/**
	 * @param array<string,mixed> $filesystem Filesystem lock status.
	 * @return array<int,string>
	 */
	private static function active_filesystem_lock_keys( array $filesystem ): array {
		return array_values(
			array_filter(
				array_map('strval', (array) ( $filesystem['active_keys'] ?? array() )),
				static fn( string $key ): bool => '' !== $key
			)
		);
	}

	/**
	 * @param array<string,mixed> $owner_context Decoded DB lock owner context.
	 */
	private static function owner_context_session_id( array $owner_context ): string {
		$runtime_ids = (array) ( $owner_context['runtime_ids'] ?? array() );
		foreach ( $runtime_ids as $entry ) {
			if ( is_array($entry) && '' !== trim( (string) ( $entry['session_id'] ?? '' )) ) {
				return (string) $entry['session_id'];
			}
		}
		foreach ( $runtime_ids as $entry ) {
			if ( ! is_array($entry) ) {
				continue;
			}
			foreach ( $entry as $value ) {
				if ( '' !== trim( (string) $value) ) {
					return (string) $value;
				}
			}
		}

		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function busy_error_data( string $repo, string $lock_path, string $request_path = '' ): array {
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
			$data['owner']           = array_filter(array(
				'lock_key' => $lock_key,
				'source'   => 'filesystem',
				'path'     => $filesystem_lock['path'] ?? null,
				'mtime'    => $filesystem_lock['mtime'] ?? null,
			));
			if ( isset($filesystem_lock['age_seconds']) ) {
				$data['filesystem_age_seconds'] = (int) $filesystem_lock['age_seconds'];
			}
		}

		$active_lock = WorkspaceLockStore::active_lock($lock_key, $repo);
		if ( is_array($active_lock) ) {
			$data['active_lock'] = $active_lock;
			$data['owner']       = array_filter(array(
				'owner'                 => $active_lock['owner'] ?? null,
				'run_id'                => $active_lock['run_id'] ?? null,
				'job_id'                => $active_lock['job_id'] ?? null,
				'owner_context'         => $active_lock['metadata']['owner_context'] ?? null,
				'acquired_at'           => $active_lock['acquired_at'] ?? null,
				'heartbeat_at'          => $active_lock['heartbeat_at'] ?? null,
				'heartbeat_age_seconds' => $active_lock['heartbeat_age_seconds'] ?? null,
				'source'                => 'database',
			));
			if ( isset($active_lock['age_seconds']) ) {
				$data['age_seconds'] = (int) $active_lock['age_seconds'];
			}
			if ( isset($active_lock['retry_after_seconds']) ) {
				// Preserve lease timing for stale-owner recovery without presenting it
				// as the healthy owner's expected completion time.
				$data['retry_after_seconds']      = (int) $active_lock['retry_after_seconds'];
				$data['lease_expires_in_seconds'] = (int) $active_lock['retry_after_seconds'];
			}
			$expected_release_at = (string) ( $active_lock['metadata']['expected_release_at'] ?? '' );
			$expected_release    = '' === $expected_release_at ? false : strtotime($expected_release_at);
			if ( false !== $expected_release ) {
				$data['expected_release_at']    = $expected_release_at;
				$data['estimated_wait_seconds'] = max(0, $expected_release - time());
				$data['eta_status']             = 'owner_operation_deadline';
			}
		} elseif ( is_wp_error($active_lock) ) {
			$data['lock_store_error'] = $active_lock->get_error_message();
		}
		if ( ! array_key_exists('estimated_wait_seconds', $data) ) {
			$data['estimated_wait_seconds'] = null;
			$data['eta_status']             = 'active_holder_release_unknown';
		}

		if ( '' !== $request_path ) {
			$data['request_id'] = basename($request_path, '.json');
			$data['progress']   = array(
				'phase' => 'lock_wait',
				'state' => 'timed_out',
			);
			$request            = self::read_request($request_path);
			if ( is_array($request) && isset($request['queue_position']) ) {
				$data['queue_position'] = (int) $request['queue_position'];
			}
			$queue = self::queued_requests_for_resource(dirname($lock_path), $lock_path);
			foreach ( $queue as $position => $request ) {
				if ( (string) ( $request['path'] ?? '' ) === $request_path ) {
					$data['queue_position'] = $position + 1;
					break;
				}
			}
			// The queue file can disappear during a concurrent stale-request prune.
			// This request still reached the lock after the active holder, so report
			// the minimum truthful position instead of omitting admission progress.
			$data['queue_position'] = $data['queue_position'] ?? 1;
		}

		return $data;
	}

	/** @return array<int,array<string,mixed>> */
	private static function queued_requests_for_resource( string $lock_dir, string $lock_path ): array {
		self::prune_stale_requests($lock_dir);
		$files = glob($lock_dir . '/requests/*.json');
		$rows  = array();
		foreach ( false === $files ? array() : $files as $file ) {
			$row = self::read_request($file);
			if ( is_array($row) && 'queued' === (string) ( $row['state'] ?? '' ) && (string) ( $row['resource'] ?? '' ) === $lock_path ) {
				$row['path'] = $file;
				$rows[]      = $row;
			}
		}
		usort($rows, static fn( array $left, array $right ): int => strcmp( (string) ( $left['queue_order'] ?? $left['created_at'] ?? '' ), (string) ( $right['queue_order'] ?? $right['created_at'] ?? '' )));
		return $rows;
	}

	/** Only the oldest live request may contend for the OS flock. */
	private static function request_is_head( string $lock_dir, string $lock_path, string $request_path ): bool {
		$queue = self::queued_requests_for_resource($lock_dir, $lock_path);
		return array() !== $queue && (string) ( $queue[0]['path'] ?? '' ) === $request_path;
	}

	/**
	 * Operator guidance shared by busy errors and status commands.
	 *
	 * @return array<string,mixed>
	 */
	private static function recovery_guidance(): array {
		return array(
			'status_command'   => 'wp datamachine-code workspace worktree locks --format=json',
			'dry_run_command'  => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			'apply_command'    => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			'safety'           => 'Only expired DB rows and old unlocked filesystem lock files are pruned. Active filesystem flocks are never removed by this command.',
			'active_lock_note' => 'If a filesystem lock is active without DB owner evidence, another process still holds the OS file descriptor or crashed without releasing an operator-visible DB row. Inspect running DMC/WP-CLI processes before retrying.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function filesystem_status( string $workspace_path, ?WallClockBudget $budget = null ): array {
		$lock_dir    = rtrim($workspace_path, '/') . '/.locks';
		$files       = is_dir($lock_dir) ? glob($lock_dir . '/*.lock') : array();
		$files       = false === $files ? array() : $files;
		$active      = 0;
		$stale       = 0;
		$recent      = 0;
		$active_keys = array();
		$stale_keys  = array();
		$locks       = array();
		$partial     = false;

		foreach ( $files as $file ) {
			if ( null !== $budget && $budget->expired() ) {
				$partial = true;
				break;
			}
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
			'partial'     => $partial,
			'observed'    => count($locks),
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
			$entry['state']             = 'active';
			$entry['reason']            = 'filesystem_flock_held';
			$entry['owner_evidence']    = self::owner_evidence_for_lock();
			$entry['recovery_command']  = 'wp datamachine-code workspace worktree locks --format=json';
			$entry['safe_to_prune']     = false;
			$entry['operator_guidance'] = 'An active OS flock cannot be safely pruned. Inspect the owner evidence or running DMC/WP-CLI processes and retry after the holder exits.';
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $entry;
		}

		flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// The flock above is the authoritative liveness signal: acquiring it
		// proves no process holds this lock. Age only guards the window between
		// creating the file and taking the flock, so it is a short grace period
		// rather than a retention window. Gating prunability on the 24h staleness
		// window instead left orphaned locks protected for a full day with zero
		// holders, so `active_locks: 0` still reported nothing prunable (#1273).
		$orphan_grace           = (int) $policy['orphan_grace_seconds'];
		$age                    = false === $mtime ? null : max(0, time() - $mtime);
		$within_creation_grace  = null === $age || $age < $orphan_grace;
		$entry['orphan_grace_seconds'] = $orphan_grace;

		$entry['state']            = $within_creation_grace ? 'recent' : 'stale';
		$entry['reason']           = $within_creation_grace ? 'unlocked_within_creation_grace' : 'unlocked_no_live_holder';
		$entry['safe_to_prune']    = ! $within_creation_grace;
		$entry['recovery_command'] = $within_creation_grace
			? 'wp datamachine-code workspace worktree locks --format=json'
			: 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json';
		if ( $within_creation_grace ) {
			$entry['operator_guidance'] = sprintf(
				'No process holds this lock, but it was created less than %ds ago. It becomes prunable once the creation grace window passes.',
				$orphan_grace
			);
		}

		return $entry;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function owner_evidence_for_lock(): array {
		return array(
			'source'  => 'filesystem_only',
			'message' => 'Database owner lookup is intentionally deferred so lock inspection remains available during database contention.',
		);
	}

	private static function lock_key_from_path( string $path ): string {
		$filename = basename($path);
		return str_ends_with($filename, '.lock') ? substr($filename, 0, -5) : $filename;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function prune_stale_filesystem_locks( string $workspace_path, bool $dry_run, ?WallClockBudget $budget = null ): array {
		$lock_dir = rtrim($workspace_path, '/') . '/.locks';
		$files    = is_dir($lock_dir) ? glob($lock_dir . '/*.lock') : array();
		$files    = false === $files ? array() : $files;
		$policy   = self::retention_policy();
		// Matches the classifier: liveness decides, and age only covers the
		// create-then-flock race. Skipping on the 24h staleness window here meant
		// an orphaned lock was never even tested for a live holder (#1273).
		$cutoff   = time() - (int) $policy['orphan_grace_seconds'];
		$removed  = array();
		$skipped  = array();

		foreach ( $files as $file ) {
			if ( null !== $budget && $budget->expired() ) {
				break;
			}
			$mtime = filemtime($file);
			if ( false === $mtime || $mtime >= $cutoff ) {
				$skipped[] = array(
					'path'   => $file,
					'reason' => 'within_creation_grace',
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
			'partial'       => count($removed) + count($skipped) < count($files),
		);
	}

	/**
	 * @return array<string,int>
	 */
	private static function retention_policy(): array {
		$policy = array(
			'filesystem_stale_after_seconds' => 86400,
			'orphan_grace_seconds'           => 300,
		);
		if ( function_exists('apply_filters') ) {
			$policy = (array) apply_filters('datamachine_code_cleanup_lock_retention_policy', $policy);
		}

		return array(
			'filesystem_stale_after_seconds' => max(60, (int) ( $policy['filesystem_stale_after_seconds'] ?? 86400 )),
			'orphan_grace_seconds'           => max(30, (int) ( $policy['orphan_grace_seconds'] ?? 300 )),
		);
	}
}
