<?php
/**
 * Pure-PHP smoke test for workspace repo list filtering.
 *
 * Run: php tests/smoke-workspace-list-repo-filter.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    class FilesystemHelper
    {
        public static function get()
        {
            return null;
        }
    }
}

namespace {
    $workspace_path = rtrim(sys_get_temp_dir(), '/') . '/datamachine-code-list-filter-' . getmypid();
    $remove_tree    = static function ( string $path ) use ( &$remove_tree ): void {
        if (! is_dir($path) ) {
            return;
        }

        foreach ( scandir($path) ?: array() as $entry ) {
            if ('.' === $entry || '..' === $entry ) {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? $remove_tree($child) : unlink($child);
        }

        rmdir($path);
    };
    register_shutdown_function($remove_tree, $workspace_path);

    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/fixtures/web-root/');
    }

    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', $workspace_path);
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

    include __DIR__ . '/../inc/Workspace/Workspace.php';

    $failures = 0;
    $total    = 0;

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

    $repo_names = static function ( array $result ): array {
        return array_values(array_map(static fn( array $repo ): string => $repo['name'], $result['repos'] ?? array()));
    };

    echo "=== smoke-workspace-list-repo-filter ===\n";

	mkdir($workspace_path . '/my-plugin', 0755, true);
	mkdir($workspace_path . '/my-plugin@feature-one', 0755, true);
    mkdir($workspace_path . '/data-machine-code', 0755, true);

    $workspace = new \DataMachineCode\Workspace\Workspace();

    echo "\n[1] Unfiltered list includes every workspace directory\n";
    $all = $workspace->list_repos();
	$assert_same(array( 'data-machine-code', 'my-plugin', 'my-plugin@feature-one' ), $repo_names($all), 'unfiltered list includes all repos');

    echo "\n[2] Repo filter includes primary checkout and worktrees\n";
	$filtered = $workspace->list_repos('my-plugin');
	$assert_same(array( 'my-plugin', 'my-plugin@feature-one' ), $repo_names($filtered), 'repo filter includes matching primary and worktree handles');

    echo "\n[3] Missing repo filter returns an empty list\n";
    $missing = $workspace->list_repos('missing');
    $assert_same(array(), $repo_names($missing), 'missing repo filter returns no repos');

    echo "\n[4] Type filter can return only primary checkouts\n";
    $primaries = $workspace->list_repos(null, 'primary');
	$assert_same(array( 'data-machine-code', 'my-plugin' ), $repo_names($primaries), 'primary type filter excludes worktree handles');

    echo "\n[5] Type filter can return only worktrees\n";
    $worktrees = $workspace->list_repos(null, 'worktree');
	$assert_same(array( 'my-plugin@feature-one' ), $repo_names($worktrees), 'worktree type filter excludes primary handles');

    echo "\n[6] Repo and type filters compose\n";
	$plugin_worktrees = $workspace->list_repos('my-plugin', 'worktree');
	$assert_same(array( 'my-plugin@feature-one' ), $repo_names($plugin_worktrees), 'repo plus worktree filter returns matching worktrees only');

    echo "\n[7] Invalid type filter returns a structured error\n";
    $invalid = $workspace->list_repos(null, 'dirty');
    $assert_same(true, is_wp_error($invalid), 'invalid type returns WP_Error');
    $assert_same('invalid_workspace_type', is_wp_error($invalid) ? $invalid->get_error_code() : '', 'invalid type error code is stable');

    echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
