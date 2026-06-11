<?php
/**
 * Smoke test for workspace path CLI routing.
 *
 * Run: php tests/smoke-workspace-path-cli.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__);
    }

    class WP_CLI
    {
        public static array $logs = array();
        public static array $successes = array();

        public static function error( string $message ): void
        {
            throw new RuntimeException($message);
        }

        public static function success( string $message ): void
        {
            self::$successes[] = $message;
        }

        public static function log( string $message ): void
        {
            self::$logs[] = $message;
        }

        public static function warning( string $message ): void
        {
            self::$logs[] = 'warning: ' . $message;
        }
    }

    function is_wp_error( $_value ): bool
    {
        return false;
    }

    function wp_get_ability( string $name )
    {
        return $GLOBALS['__abilities'][ $name ] ?? null;
    }
}

namespace DataMachine\Cli {
    class BaseCommand
    {
        protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void
        {
            \WP_CLI::log('table:' . count($items) . ':' . implode(',', $fields));
        }
    }
}

namespace {
    include_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

    function datamachine_code_workspace_path_cli_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    class FakeWorkspacePathAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $name             = (string) ( $input['name'] ?? '' );

            return array(
                'success' => true,
                'path'    => '' === $name ? '/workspace' : '/workspace/' . $name,
                'exists'  => true,
            );
        }
    }

    echo "=== smoke-workspace-path-cli ===\n";

    $ability = new FakeWorkspacePathAbility();
    $GLOBALS['__abilities']['datamachine-code/workspace-path'] = $ability;
    $command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();

    echo "\n[1] workspace path without a handle keeps root behavior\n";
    WP_CLI::$logs = array();
    $command->path(array(), array());
    datamachine_code_workspace_path_cli_assert('/workspace' === ( WP_CLI::$logs[0] ?? null ), 'prints workspace root path');
    datamachine_code_workspace_path_cli_assert(array( 'ensure' => false ) === $ability->last_input, 'root path sends only ensure flag');

    echo "\n[2] workspace path accepts a primary repo handle\n";
    WP_CLI::$logs = array();
    $command->path(array( 'my-plugin' ), array());
    datamachine_code_workspace_path_cli_assert('/workspace/my-plugin' === ( WP_CLI::$logs[0] ?? null ), 'prints primary checkout path');
    datamachine_code_workspace_path_cli_assert('my-plugin' === ( $ability->last_input['name'] ?? null ), 'primary handle is passed to ability');

    echo "\n[3] workspace path accepts a worktree handle\n";
    WP_CLI::$logs = array();
    $command->path(array( 'my-plugin@fix-foo' ), array());
    datamachine_code_workspace_path_cli_assert('/workspace/my-plugin@fix-foo' === ( WP_CLI::$logs[0] ?? null ), 'prints worktree checkout path');
    datamachine_code_workspace_path_cli_assert('my-plugin@fix-foo' === ( $ability->last_input['name'] ?? null ), 'worktree handle is passed to ability');

    echo "\nResult: all passed\n";
}
