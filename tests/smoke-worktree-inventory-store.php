<?php
/**
 * Smoke test for DB-backed DMC worktree inventory lifecycle signals.
 *
 * Run: php tests/smoke-worktree-inventory-store.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (! defined('ARRAY_A') ) {
        define('ARRAY_A', 'ARRAY_A');
    }

    class Datamachine_Code_Test_Wpdb
    {
        public string $prefix = 'wp_';

        /**
         * @var array<string,array<string,mixed>> 
         */
        public array $rows = array();

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }

        public function prepare( string $query, ...$args ): string
        {
            foreach ( $args as $arg ) {
                $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
                $query       = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
            }
            return $query;
        }

        public function get_var( string $query ): ?string
        {
            return str_contains($query, 'wp_datamachine_code_worktrees') ? 'wp_datamachine_code_worktrees' : null;
        }

        /**
         * @param array<string,mixed> $data
         * @param array<int,string>   $format
         */
        public function replace( string $table, array $data, ?array $format = null )
        {
            $this->rows[ (string) $data['handle'] ] = $data;
            return 1;
        }

        /**
         * @return array<int,array<string,mixed>> 
         */
        public function get_results( string $query, string $output_type ): array
        {
            return array_values($this->rows);
        }

        /**
         * @param array<string,mixed> $where
         * @param array<int,string>   $where_format
         */
        public function delete( string $table, array $where, ?array $where_format = null )
        {
            unset($this->rows[ (string) $where['handle'] ]);
            return 1;
        }
    }

    if (! function_exists('current_time') ) {
        function current_time( string $type, bool $gmt = false ): string  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            return gmdate('Y-m-d H:i:s');
        }
    }

    if (! function_exists('wp_json_encode') ) {
        function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false
        {
            return json_encode($value, $flags, $depth);
        }
    }

    if (! function_exists('get_option') ) {
        function get_option( string $name, $default = false )
        {
            global $datamachine_code_test_options;
            return $datamachine_code_test_options[ $name ] ?? $default;
        }
    }

    if (! function_exists('update_option') ) {
        function update_option( string $name, $value, $autoload = null ): bool
        {
            global $datamachine_code_test_options;
            $datamachine_code_test_options[ $name ] = $value;
            return true;
        }
    }

    if (! function_exists('apply_filters') ) {
        function apply_filters( string $hook_name, $value, ...$args )
        {
            global $datamachine_code_test_filters;
            if (isset($datamachine_code_test_filters[ $hook_name ]) && is_callable($datamachine_code_test_filters[ $hook_name ]) ) {
                return $datamachine_code_test_filters[ $hook_name ]($value, ...$args);
            }
            return $value;
        }
    }

    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Storage/WorktreeInventoryRepository.php';

    $assertions = 0;
    $failures   = 0;

    $assert = function ( $expected, $actual, string $message ) use ( &$assertions, &$failures ): void {
        ++$assertions;
        if ($expected === $actual ) {
            echo "  [PASS] {$message}\n";
            return;
        }

        ++$failures;
        echo "  [FAIL] {$message}\n";
        echo '         expected: ' . var_export($expected, true) . "\n";
        echo '         actual:   ' . var_export($actual, true) . "\n";
    };

    echo "=== smoke-worktree-inventory-store ===\n";

    $GLOBALS['wpdb']                          = new Datamachine_Code_Test_Wpdb();
    $GLOBALS['datamachine_code_test_options'] = array();
    $GLOBALS['datamachine_code_test_filters'] = array();

    // Register a synthetic test runtime so primary_id resolution has a
    // registered runtime to scan. DMC enumerates no runtime IDs itself.
    $GLOBALS['datamachine_code_test_filters']['datamachine_code_worktree_runtime_signatures'] = function ( array $signatures ): array {
        $signatures['test-runtime'] = array(
        'session_id' => 'DMC_SMOKE_TEST_SESSION_ID',
        'thread_id'  => 'DMC_SMOKE_TEST_THREAD_ID',
        );
        return $signatures;
    };

    $metadata = array(
    'handle'          => 'demo@agent-session-lifecycle',
    'repo'            => 'demo',
    'branch'          => 'agent-session-lifecycle',
    'path'            => '/workspace/demo@agent-session-lifecycle',
    'lifecycle_state' => 'active',
    'origin_site'     => 'Intelligence',
    'origin_agent'    => 'franklin',
    'origin_session'  => array(
    'primary_id' => 'ses_123',
    'ids'        => array(
                'test-runtime' => array(
                    'session_id' => 'ses_123',
                    'thread_id'  => 'thread_456',
                ),
    ),
    ),
    'origin_task'     => array(
    'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/221',
    'task_ref' => 'Extra-Chill/data-machine-code#221',
    ),
    'created_at'      => '2026-05-04T12:00:00Z',
    'last_seen_at'    => '2026-05-04T12:05:00Z',
    );

    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata('demo@agent-session-lifecycle', $metadata);

    $assert(true, isset($GLOBALS['wpdb']->rows['demo@agent-session-lifecycle']), 'store_lifecycle_metadata mirrors row into DB inventory');
    $db_row = $GLOBALS['wpdb']->rows['demo@agent-session-lifecycle'];
    $assert('demo', $db_row['repo'] ?? null, 'DB row stores repo column');
    $assert('active', $db_row['lifecycle_state'] ?? null, 'DB row stores lifecycle_state column');
    $assert('franklin', $db_row['origin_agent'] ?? null, 'DB row stores origin_agent column');
    $assert('https://github.com/Extra-Chill/data-machine-code/issues/221', $db_row['task_url'] ?? null, 'DB row stores task_url column');
    $assert('2026-05-04 12:05:00', $db_row['last_seen_at'] ?? null, 'DB row normalizes last_seen_at for queryable TTL checks');

    // Prove get_metadata can be DB-backed by clearing the option fallback.
    $GLOBALS['datamachine_code_test_options'] = array();
    $loaded = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@agent-session-lifecycle');
    $assert('ses_123', $loaded['origin_session']['ids']['test-runtime']['session_id'] ?? null, 'get_metadata reads origin session ids envelope from DB inventory when option is absent');
    $assert('thread_456', $loaded['origin_session']['ids']['test-runtime']['thread_id'] ?? null, 'get_metadata exposes ids subkeys from DB inventory');
    $assert('Extra-Chill/data-machine-code#221', $loaded['origin_task']['task_ref'] ?? null, 'get_metadata reads task ref from DB inventory when option is absent');

    \DataMachineCode\Workspace\WorktreeContextInjector::record_heartbeat('demo@agent-session-lifecycle', '2026-05-04T13:00:00Z');
    $assert('2026-05-04 13:00:00', $GLOBALS['wpdb']->rows['demo@agent-session-lifecycle']['last_seen_at'] ?? null, 'record_heartbeat updates DB last_seen_at');

    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@agent-session-lifecycle',
        array(
        'lifecycle_state' => 'cleanup_eligible',
        'pr_url'          => 'https://github.com/Extra-Chill/data-machine-code/pull/999',
        )
    );
    $assert('cleanup_eligible', $GLOBALS['wpdb']->rows['demo@agent-session-lifecycle']['cleanup_signal'] ?? null, 'cleanup-eligible lifecycle writes queryable cleanup_signal');

    \DataMachineCode\Workspace\WorktreeContextInjector::forget_metadata('demo@agent-session-lifecycle');
    $assert(false, isset($GLOBALS['wpdb']->rows['demo@agent-session-lifecycle']), 'forget_metadata deletes DB inventory row');

    if ($failures > 0 ) {
        echo "\n{$failures} failure(s) across {$assertions} assertion(s).\n";
        exit(1);
    }

    echo "\nAll {$assertions} worktree inventory store assertions passed.\n";
}
