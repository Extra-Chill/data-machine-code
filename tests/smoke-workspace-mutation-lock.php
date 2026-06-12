<?php
/**
 * Pure-PHP smoke test for WorkspaceMutationLock.
 *
 * Run: php tests/smoke-workspace-mutation-lock.php
 */

declare( strict_types=1 );

namespace {

    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public string $code;
            public string $message;
            public array $data;

            public function __construct( $code = '', $message = '', $data = array() )
            {
                $this->code    = (string) $code;
                $this->message = (string) $message;
                $this->data    = (array) $data;
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data(): array
            {
                return $this->data;
            }
        }
    }

    if (! function_exists('is_wp_error') ) {
        function is_wp_error( $thing ): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

	if (! function_exists('wp_mkdir_p') ) {
		function wp_mkdir_p( string $path ): bool
		{
			return is_dir($path) || mkdir($path, 0755, true);
		}
	}

	if (! defined('ARRAY_A') ) {
		define('ARRAY_A', 'ARRAY_A');
	}

	if (! function_exists('wp_json_encode') ) {
		function wp_json_encode( $data, int $flags = 0 )
		{
			return json_encode($data, $flags);
		}
	}

	class Workspace_Mutation_Lock_Test_Wpdb
	{
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public int $rows_affected = 0;
		public string $last_error = '';

		/** @var array<int,array<string,mixed>> */
		public array $rows = array();

		public function insert( string $table, array $data, array $format ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			$this->insert_id++;
			$data['id']                 = $this->insert_id;
			$this->rows[ $this->insert_id ] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			$id = (int) ( $where['id'] ?? 0 );
			if (! isset($this->rows[ $id ]) ) {
				return 0;
			}
			$this->rows[ $id ] = array_merge($this->rows[ $id ], $data);
			return 1;
		}

		public function get_var( string $sql ): mixed
		{
			if (str_contains($sql, 'SHOW TABLES LIKE') ) {
				return $this->prefix . 'datamachine_code_locks';
			}

			if (! str_contains($sql, 'COUNT(*)') ) {
				return null;
			}

			return count($this->matching_rows($sql));
		}

		/**
		 * @return array<int,string>
		 */
		public function get_col( string $sql ): array
		{
			$keys = array_map(
				static fn( array $row ): string => (string) ( $row['lock_key'] ?? '' ),
				$this->matching_rows($sql)
			);
			return array_values(array_unique(array_filter($keys)));
		}

		/**
		 * @return array<string,mixed>|null
		 */
		public function get_row( string $sql, string $output ): ?array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			$rows = $this->matching_rows($sql);
			return empty($rows) ? null : $rows[count($rows) - 1];
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( string $sql, string $output ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			return $this->matching_rows($sql);
		}

		public function query( string $sql ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			$this->rows_affected = 0;
			$now                 = gmdate('Y-m-d H:i:s');
			if (str_starts_with(trim($sql), 'UPDATE') ) {
				foreach ( $this->rows as $id => $row ) {
					$lock_key = (string) ( $row['lock_key'] ?? '' );
					if ('active' !== (string) ( $row['status'] ?? '' ) || (string) ( $row['expires_at'] ?? '' ) >= $now ) {
						continue;
					}
					if (str_contains($sql, 'lock_key NOT IN') && str_contains($sql, "'" . str_replace("'", "''", $lock_key) . "'") ) {
						continue;
					}
					$this->rows[ $id ]['status'] = 'stale';
					$this->rows_affected++;
				}
			}
			return $this->rows_affected;
		}

		public function prepare( string $query, mixed ...$args ): string
		{
			foreach ( $args as $arg ) {
				$replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
				$query       = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
			}
			return $query;
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		private function matching_rows( string $sql ): array
		{
			$now = gmdate('Y-m-d H:i:s');
			return array_values(
				array_filter(
					$this->rows,
					static function ( array $row ) use ( $sql, $now ): bool {
						$status = (string) ( $row['status'] ?? '' );
						$expiry = (string) ( $row['expires_at'] ?? '' );
						if (str_contains($sql, "status = 'active'") && 'active' !== $status ) {
							return false;
						}
						if (str_contains($sql, "status = 'stale'") && 'stale' !== $status ) {
							return false;
						}
						if (str_contains($sql, "status = 'released'") ) {
							return 'released' === $status;
						}
						if (str_contains($sql, 'expires_at <') ) {
							return '' !== $expiry && $expiry < $now;
						}
						if (str_contains($sql, 'expires_at >=') ) {
							return '' !== $expiry && $expiry >= $now;
						}
						return true;
					}
				)
			);
		}
	}

	class DataMachineCode_Fake_WPDB extends Workspace_Mutation_Lock_Test_Wpdb {}

	include __DIR__ . '/../inc/Workspace/WorkspaceLockStore.php';
	include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';

    $failures = 0;
    $total    = 0;

    $assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
        $total++;
        if ($expected === $actual ) {
            echo "  ✓ {$message}\n";
            return;
        }
        $failures++;
        echo "  ✗ {$message}\n";
        echo '    expected: ' . var_export($expected, true) . "\n";
        echo '    actual:   ' . var_export($actual, true) . "\n";
    };

    $tmp = sys_get_temp_dir() . '/dmc-workspace-lock-smoke-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    register_shutdown_function(
        function () use ( $tmp ) {
            if (is_dir($tmp) ) {
                exec('rm -rf ' . escapeshellarg($tmp));
            }
        } 
    );

    echo "Workspace mutation lock\n";

    $first = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'demo', 1);
    $assert(false, is_wp_error($first), 'first acquisition succeeds');
    $assert(true, is_file($tmp . '/.locks/worktree-demo.lock'), 'lock file is created under workspace .locks directory');
    $status = \DataMachineCode\Workspace\WorkspaceMutationLock::status($tmp);
    $assert(1, (int) $status['active'], 'held filesystem lock is visible in aggregate active count');
    $assert(false, (bool) $status['database']['available'], 'DB lock store reports unavailable in pure-PHP smoke context');

	$busy = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'demo', 0);
	$assert(true, is_wp_error($busy), 'same repo acquisition fails fast while held');
	$assert('workspace_repo_busy', is_wp_error($busy) ? $busy->get_error_code() : '', 'busy failure uses DMC-shaped retryable code');
	$assert(true, is_wp_error($busy) ? (bool) ( $busy->get_error_data()['retryable'] ?? false ) : false, 'busy failure is marked retryable');
	$assert('worktree-demo', is_wp_error($busy) ? (string) ( $busy->get_error_data()['lock_key'] ?? '' ) : '', 'busy failure includes lock key');
	$busy_data = is_wp_error($busy) ? $busy->get_error_data() : array();
	$assert('active', (string) ( $busy_data['filesystem_lock']['state'] ?? '' ), 'busy failure includes active filesystem lock state');
	$assert('filesystem_only', (string) ( $busy_data['filesystem_lock']['owner_evidence']['source'] ?? '' ), 'busy failure explains filesystem-only owner evidence when DB row is unavailable');
	$assert(true, isset($busy_data['filesystem_lock']['age_seconds']), 'busy failure includes filesystem lock age');
	$assert(true, isset($busy_data['status_command']), 'busy failure includes lock status command');
	$assert(true, isset($busy_data['stale_prune_command']), 'busy failure includes stale prune command');
	$assert(true, isset($busy_data['recovery_guidance']['safety']), 'busy failure includes recovery safety guidance');
	$filesystem_locks = (array) ( $status['filesystem']['locks'] ?? array() );
	$assert(1, count($filesystem_locks), 'status includes per-filesystem-lock detail rows');
	$assert('active', (string) ( $filesystem_locks[0]['state'] ?? '' ), 'status lock detail records active state');
	$assert('filesystem_flock_held', (string) ( $filesystem_locks[0]['reason'] ?? '' ), 'status lock detail records active flock reason');
	$assert(false, (bool) ( $filesystem_locks[0]['safe_to_prune'] ?? true ), 'active filesystem lock is not safe to prune');

    $other = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'other', 0);
    $assert(false, is_wp_error($other), 'different repo acquisition is independent');
    if (! is_wp_error($other) ) {
        $other->release();
    }

    if (! is_wp_error($first) ) {
        $first->release();
    }
	$status = \DataMachineCode\Workspace\WorkspaceMutationLock::status($tmp);
	$assert(0, (int) $status['active'], 'released filesystem lock is no longer active');
	$assert(2, (int) $status['filesystem']['recent'], 'released filesystem lock files are visible as recent retention residue');

	$GLOBALS['wpdb'] = new Workspace_Mutation_Lock_Test_Wpdb();
	$db_backed = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'db-demo', 1);
	$assert(false, is_wp_error($db_backed), 'DB-backed acquisition succeeds');
	$db_backed_status = \DataMachineCode\Workspace\WorkspaceMutationLock::status($tmp);
	$assert(1, (int) $db_backed_status['database']['active'], 'DB-backed acquisition records one active DB row');
	$assert(1, (int) $db_backed_status['filesystem']['active'], 'DB-backed acquisition also holds one filesystem lock');
	$assert(1, (int) $db_backed_status['active'], 'same DB row and filesystem lock count as one logical active lock');
	if (! is_wp_error($db_backed) ) {
		$db_backed->release();
	}
	unset($GLOBALS['wpdb']);

	$stale_path = $tmp . '/.locks/worktree-stale.lock';
    touch($stale_path, time() - 172800);
	$stale_status = \DataMachineCode\Workspace\WorkspaceMutationLock::status($tmp);
	$assert(1, (int) $stale_status['stale'], 'old unlocked filesystem lock is counted stale');
	$stale_locks = array_values(array_filter((array) ( $stale_status['filesystem']['locks'] ?? array() ), static fn( array $lock ): bool => 'worktree-stale' === (string) ( $lock['lock_key'] ?? '' )));
	$assert(1, count($stale_locks), 'stale filesystem lock appears in detail rows');
	$assert('stale', (string) ( $stale_locks[0]['state'] ?? '' ), 'stale lock detail records stale state');
	$assert(true, (bool) ( $stale_locks[0]['safe_to_prune'] ?? false ), 'stale unlocked filesystem lock is safe to prune');
	$assert('wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json', (string) ( $stale_locks[0]['recovery_command'] ?? '' ), 'stale lock detail points at dry-run prune command');
	$dry_prune = \DataMachineCode\Workspace\WorkspaceMutationLock::prune_stale($tmp, true);
    $assert(true, is_file($stale_path), 'dry-run stale lock prune does not remove file');
    $assert(1, (int) $dry_prune['filesystem']['removed_count'], 'dry-run reports stale lock file candidate');
    $prune = \DataMachineCode\Workspace\WorkspaceMutationLock::prune_stale($tmp, false);
    $assert(false, is_file($stale_path), 'stale lock prune removes unlocked old file');
    $assert(1, (int) $prune['filesystem']['removed_count'], 'stale lock prune reports removed file');

    $after_release = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'demo', 0);
    $assert(false, is_wp_error($after_release), 'same repo acquisition succeeds after release');
    if (! is_wp_error($after_release) ) {
        $after_release->release();
    }

    $result = \DataMachineCode\Workspace\WorkspaceMutationLock::with_repo(
        $tmp,
        'demo',
        fn() => array( 'success' => true ),
        0
    );
    $assert(array( 'success' => true ), $result, 'with_repo returns callback result');

    $post_callback = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'demo', 0);
    $assert(false, is_wp_error($post_callback), 'with_repo releases after normal callback return');
    if (! is_wp_error($post_callback) ) {
        $post_callback->release();
    }

    try {
        \DataMachineCode\Workspace\WorkspaceMutationLock::with_repo(
            $tmp,
            'demo',
            function () {
                throw new \RuntimeException('boom');
            },
            0
        );
        $assert(true, false, 'with_repo exception path throws');
    } catch ( \RuntimeException $e ) {
        $assert('boom', $e->getMessage(), 'with_repo propagates callback exception');
    }

    $post_exception = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($tmp, 'demo', 0);
    $assert(false, is_wp_error($post_exception), 'with_repo releases after callback exception');
	if (! is_wp_error($post_exception) ) {
		$post_exception->release();
	}

	$GLOBALS['wpdb'] = new DataMachineCode_Fake_WPDB();
	$db_tmp = sys_get_temp_dir() . '/dmc-workspace-lock-db-smoke-' . bin2hex(random_bytes(4));
	mkdir($db_tmp, 0755, true);
	register_shutdown_function(
		function () use ( $db_tmp ) {
			if (is_dir($db_tmp) ) {
				exec('rm -rf ' . escapeshellarg($db_tmp));
			}
		}
	);
	$db_first = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($db_tmp, 'demo', 1);
	$db_busy = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($db_tmp, 'demo', 0);
	$db_data = is_wp_error($db_busy) ? $db_busy->get_error_data() : array();
	$assert(true, is_wp_error($db_busy), 'DB-backed same repo acquisition fails fast while held');
	$assert('demo', (string) ( $db_data['active_lock']['scope'] ?? '' ), 'busy failure includes active DB lock scope');
	$assert('worktree-demo', (string) ( $db_data['active_lock']['lock_key'] ?? '' ), 'busy failure includes active DB lock key');
	$assert(true, isset($db_data['active_lock']['owner']), 'busy failure includes active DB lock owner');
	$assert(true, isset($db_data['active_lock']['acquired_at']), 'busy failure includes acquired timestamp');
	$assert(true, isset($db_data['active_lock']['heartbeat_at']), 'busy failure includes heartbeat timestamp');
	$assert(true, isset($db_data['active_lock']['expires_at']), 'busy failure includes expires timestamp');
	$assert(true, isset($db_data['active_lock']['retry_after_seconds']), 'busy failure includes retry-after seconds');
	$assert(true, isset($db_data['active_lock']['age_seconds']), 'busy failure includes age seconds');
	$assert(true, isset($db_data['active_lock']['metadata']['owner_context']), 'busy failure includes owner context metadata');

	$active_row_id = array_key_last($GLOBALS['wpdb']->rows);
	$GLOBALS['wpdb']->rows[ $active_row_id ]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 3600);
	$GLOBALS['wpdb']->rows[ $active_row_id ]['metadata_json'] = wp_json_encode(
		array(
			'owner_context' => array(
				'runtime_ids' => array(
					'opencode' => array(
						'session_id' => 'ses-db-active',
					),
				),
			),
		)
	);
	$GLOBALS['wpdb']->insert(
		$GLOBALS['wpdb']->prefix . 'datamachine_code_locks',
		array(
			'lock_key'      => 'worktree-db-stale',
			'purpose'       => 'workspace_repo_mutation',
			'scope'         => 'db-stale',
			'owner'         => 'owner:123',
			'run_id'        => 'run-123',
			'job_id'        => 456,
			'status'        => 'active',
			'acquired_at'   => gmdate('Y-m-d H:i:s', time() - 7200),
			'heartbeat_at'  => gmdate('Y-m-d H:i:s', time() - 7100),
			'expires_at'    => gmdate('Y-m-d H:i:s', time() - 3600),
			'released_at'   => null,
			'metadata_json' => wp_json_encode(
				array(
					'owner_context' => array(
						'runtime_ids' => array(
							'opencode' => array(
								'session_id' => 'ses-db-stale',
							),
						),
					),
				)
			),
		),
		array()
	);

	$db_stale_status = \DataMachineCode\Workspace\WorkspaceMutationLock::status($db_tmp);
	$db_stale_report = (array) ( $db_stale_status['stale_locks'] ?? array() );
	$assert(2, (int) ( $db_stale_report['database_count'] ?? 0 ), 'stale DB rows are included in stale lock report');
	$assert('wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json', (string) ( $db_stale_report['preview_command'] ?? '' ), 'stale lock report exposes exact prune preview command');
	$assert('wp datamachine-code workspace worktree locks --prune-stale --format=json', (string) ( $db_stale_report['apply_command'] ?? '' ), 'stale lock report exposes exact prune apply command');
	$db_rows = array_column((array) ( $db_stale_report['database'] ?? array() ), null, 'lock_key');
	$assert(true, (bool) ( $db_rows['worktree-demo']['live_flock_present'] ?? false ), 'expired DB row with live filesystem flock is reported as live');
	$assert(false, (bool) ( $db_rows['worktree-demo']['safe_to_prune'] ?? true ), 'expired DB row with live filesystem flock is not safe to prune');
	$assert('ses-db-active', (string) ( $db_rows['worktree-demo']['session'] ?? '' ), 'stale DB report includes owner session evidence');
	$assert(true, isset($db_rows['worktree-demo']['age_seconds']), 'stale DB report includes owner age');
	$assert(false, (bool) ( $db_rows['worktree-db-stale']['live_flock_present'] ?? true ), 'stale DB row without filesystem flock reports no live flock');
	$assert(true, (bool) ( $db_rows['worktree-db-stale']['safe_to_prune'] ?? false ), 'stale DB row without live flock is safe to prune');

	$db_prune = \DataMachineCode\Workspace\WorkspaceMutationLock::prune_stale($db_tmp, false);
	$assert(1, (int) ( $db_prune['database']['protected_active'] ?? 0 ), 'DB prune protects rows with live filesystem flocks');
	$assert(1, (int) ( $db_prune['database']['active_marked_stale'] ?? 0 ), 'DB prune marks only unprotected expired rows stale');
	$assert('active', (string) ( $GLOBALS['wpdb']->rows[ $active_row_id ]['status'] ?? '' ), 'DB prune refuses active filesystem lock row');

	if (! is_wp_error($db_first) ) {
		$db_first->release();
	}

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
