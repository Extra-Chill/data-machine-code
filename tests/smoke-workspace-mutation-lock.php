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

	if (! defined('ARRAY_A') ) {
		define('ARRAY_A', 'ARRAY_A');
	}

	if (! function_exists('wp_json_encode') ) {
		function wp_json_encode( $data ) {
			return json_encode($data);
		}
	}

	if (! function_exists('dbDelta') ) {
		function dbDelta( $sql ) {
			return array();
		}
	}

	class DataMachineCode_Fake_WPDB
	{
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $insert_id = 0;
		public int $rows_affected = 0;
		public array $rows = array();

		public function prepare( string $query, ...$args ): string
		{
			return $query;
		}

		public function get_var( string $query )
		{
			if (str_starts_with($query, 'SHOW TABLES LIKE') ) {
				return $this->prefix . 'datamachine_code_locks';
			}

			return 0;
		}

		public function insert( string $table, array $data, array $format )
		{
			$this->insert_id++;
			$data['id'] = $this->insert_id;
			$this->rows[] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format )
		{
			foreach ( $this->rows as &$row ) {
				if ((int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$row = array_merge($row, $data);
					$this->rows_affected = 1;
					return 1;
				}
			}

			$this->rows_affected = 0;
			return 0;
		}

		public function get_row( string $query, string $output )
		{
			$active = array_values(array_filter($this->rows, fn( $row ) => 'active' === ( $row['status'] ?? '' )));
			if (empty($active) ) {
				return null;
			}

			return $active[count($active) - 1];
		}

		public function query( string $query )
		{
			$this->rows_affected = 0;
			return 0;
		}
	}
    }

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

    $stale_path = $tmp . '/.locks/worktree-stale.lock';
    touch($stale_path, time() - 172800);
    $stale_status = \DataMachineCode\Workspace\WorkspaceMutationLock::status($tmp);
    $assert(1, (int) $stale_status['stale'], 'old unlocked filesystem lock is counted stale');
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
	if (! is_wp_error($db_first) ) {
		$db_first->release();
	}

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
