<?php
/**
 * Pure-PHP smoke test for workspace root visibility diagnostics.
 *
 * Run: php tests/smoke-workspace-path-diagnostics.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', __DIR__ . '/fixtures/nonexistent-host-workspace');
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            private string $code;
            private string $message;
            private $data;

            public function __construct( string $code = '', string $message = '', $data = array() )
            {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = $data;
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data()
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

    if (! function_exists('get_option') ) {
        function get_option( string $_name, $default = false )
        {
            return $default;
        }
    }

    if (! function_exists('size_format') ) {
        function size_format( $bytes ): string
        {
            return (string) $bytes;
        }
    }

    include __DIR__ . '/../inc/Workspace/Workspace.php';

    $failures = 0;
    $total    = 0;

    $assert = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
        $total++;
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }

        $failures++;
        echo "  [FAIL] {$message}\n";
    };

    $assert_same = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
        $total++;
        if ($expected === $actual ) {
            echo "  [PASS] {$message}\n";
            return;
        }

        $failures++;
        echo "  [FAIL] {$message}\n";
        echo '    expected: ' . var_export($expected, true) . "\n";
        echo '    actual:   ' . var_export($actual, true) . "\n";
    };

    echo "=== smoke-workspace-path-diagnostics ===\n";

    $workspace  = new \DataMachineCode\Workspace\Workspace();
    $diagnostic = $workspace->inspect_workspace_path();

    echo "\n[1] Inspect configured invisible path\n";
    $assert_same(DATAMACHINE_WORKSPACE_PATH, $diagnostic['path'], 'diagnostic includes configured path');
    $assert_same(true, $diagnostic['configured'], 'diagnostic marks path as explicitly configured');
    $assert_same(false, $diagnostic['is_dir'], 'diagnostic includes is_dir=false');
    $assert_same(false, $diagnostic['is_readable'], 'diagnostic includes is_readable=false');
    $assert_same('failed', $diagnostic['scandir'], 'diagnostic includes scandir outcome');

    echo "\n[2] List fails with workspace visibility error instead of empty success\n";
    $list = $workspace->list_repos();
    $assert(is_wp_error($list), 'list_repos returns WP_Error');
    $assert_same('workspace_path_invisible', $list->get_error_code(), 'list_repos uses workspace_path_invisible code');
    $assert(str_contains($list->get_error_message(), 'is_dir=false'), 'list_repos error includes is_dir diagnostic');
    $assert(str_contains($list->get_error_message(), 'scandir=failed'), 'list_repos error includes scandir diagnostic');
    $assert(str_contains($list->get_error_message(), 'Studio/local host path'), 'list_repos error includes Studio/PHP mount hint');

    echo "\n[3] Clone preflights workspace before git clone\n";
	$clone = $workspace->clone_repo('https://github.com/example/my-plugin.git');
    $assert(is_wp_error($clone), 'clone_repo returns WP_Error');
    $assert_same('workspace_path_invisible', $clone->get_error_code(), 'clone_repo surfaces workspace visibility error');
    $clone_data = $clone->get_error_data();
    $assert_same(DATAMACHINE_WORKSPACE_PATH, $clone_data['workspace']['path'] ?? null, 'clone_repo error data includes configured path');

    echo "\n[4] Worktree add preflights workspace before primary checkout check\n";
	$worktree = $workspace->worktree_add('my-plugin', 'fix/example', null, false, false);
    $assert(is_wp_error($worktree), 'worktree_add returns WP_Error');
    $assert_same('workspace_path_invisible', $worktree->get_error_code(), 'worktree_add surfaces workspace visibility error');
    $assert(! str_contains($worktree->get_error_message(), 'Primary checkout'), 'worktree_add does not claim primary checkout is missing');

    echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
