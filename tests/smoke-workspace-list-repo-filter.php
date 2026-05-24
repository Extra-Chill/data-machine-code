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

    mkdir($workspace_path . '/homeboy', 0755, true);
    mkdir($workspace_path . '/homeboy@feature-one', 0755, true);
    mkdir($workspace_path . '/data-machine-code', 0755, true);

    $workspace = new \DataMachineCode\Workspace\Workspace();

    echo "\n[1] Unfiltered list includes every workspace directory\n";
    $all = $workspace->list_repos();
    $assert_same(array( 'data-machine-code', 'homeboy', 'homeboy@feature-one' ), $repo_names($all), 'unfiltered list includes all repos');

    echo "\n[2] Repo filter includes primary checkout and worktrees\n";
    $filtered = $workspace->list_repos('homeboy');
    $assert_same(array( 'homeboy', 'homeboy@feature-one' ), $repo_names($filtered), 'repo filter includes matching primary and worktree handles');

    echo "\n[3] Missing repo filter returns an empty list\n";
    $missing = $workspace->list_repos('missing');
    $assert_same(array(), $repo_names($missing), 'missing repo filter returns no repos');

    echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
