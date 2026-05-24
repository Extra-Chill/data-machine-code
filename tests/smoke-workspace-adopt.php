<?php
/**
 * Pure-PHP smoke test for workspace primary checkout adoption.
 *
 * Run: php tests/smoke-workspace-adopt.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    if (! class_exists(__NAMESPACE__ . '\\FilesystemHelper') ) {
        class FilesystemHelper
        {
            public static function get()
            {
                return null;
            }
        }
    }
}

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
        function get_option( string $name, $default = false )  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
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

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

    exec('git --version 2>&1', $_git_version, $git_version_exit);
    if (0 !== $git_version_exit ) {
        echo "SKIP: git not available\n";
        exit(0);
    }

    $tmp = sys_get_temp_dir() . '/dmc-adopt-smoke-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    register_shutdown_function(
        function () use ( $tmp ) {
            if (is_dir($tmp) ) {
                exec('rm -rf ' . escapeshellarg($tmp));
            }
        } 
    );

    define('DATAMACHINE_WORKSPACE_PATH', realpath($tmp) ? realpath($tmp) : $tmp);

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

    $run = function ( string $cmd, string $cwd = '' ): array {
        $full = '' === $cwd ? $cmd : sprintf('cd %s && %s', escapeshellarg($cwd), $cmd);
        exec($full . ' 2>&1', $out, $rc);
        return array(
        'output' => implode("\n", $out),
        'exit'   => $rc,
        );
    };

    $make_repo = function ( string $name ) use ( $tmp, $run ): string {
        $path = $tmp . '/' . $name;
        mkdir($path, 0755, true);
        $run('git init', $path);
        $run('git config user.email test@example.com', $path);
        $run('git config user.name test', $path);
        file_put_contents($path . '/README.md', $name . "\n");
        $run('git add README.md && git commit -m init', $path);
        return $path;
    };

    echo "Setting up workspace at {$tmp}\n";
    $primary        = $make_repo('homeboy');
    $explicit       = $make_repo('explicit-name');
    $collision      = $make_repo('collision');
    $collision_path = $make_repo('collision-path');
    $non_git        = $tmp . '/not-git';
    mkdir($non_git, 0755, true);

    $run('git branch linked', $primary);
    $linked = $tmp . '/homeboy@linked';
    $run(sprintf('git worktree add %s linked', escapeshellarg($linked)), $primary);
    $outside = sys_get_temp_dir() . '/dmc-adopt-outside-' . bin2hex(random_bytes(4));
    mkdir($outside, 0755, true);
    $run('git init', $outside);
    register_shutdown_function(
        function () use ( $outside ) {
            if (is_dir($outside) ) {
                exec('rm -rf ' . escapeshellarg($outside));
            }
        } 
    );

    $ws = new \DataMachineCode\Workspace\Workspace();
    $adopt = function ( string $path, ?string $name = null ) use ( $ws ): array|\WP_Error {
        // @phpstan-ignore-next-line Pure-PHP smoke requires the production class at runtime.
        return $ws->adopt_repo($path, $name);
    };

    echo "\nAdopt existing primary\n";
    $result = $adopt($primary);
    $assert(true, ! is_wp_error($result) && ( $result['success'] ?? false ), 'existing primary under workspace adopts');
    $assert('homeboy', is_wp_error($result) ? '' : $result['name'], 'omitted name derives path basename');
    $assert(realpath($primary), is_wp_error($result) ? '' : $result['path'], 'adopted path is real primary path');
    $assert(true, is_wp_error($result) ? false : $result['already_adopted'], 'adoption reports already_adopted');

    $result = $adopt($explicit, 'explicit-name');
    $assert(true, ! is_wp_error($result) && ( $result['success'] ?? false ), 'explicit matching name adopts');
    $assert('explicit-name', is_wp_error($result) ? '' : $result['name'], 'explicit name is used');

    echo "\nReject invalid adoption targets\n";
    $result = $adopt($linked);
    $assert(true, is_wp_error($result), 'linked worktree is rejected');
    $assert('adopt_linked_worktree', is_wp_error($result) ? $result->get_error_code() : '', 'linked worktree error code');

    $result = $adopt($non_git);
    $assert(true, is_wp_error($result), 'non-git path is rejected');
    $assert('adopt_not_git_primary', is_wp_error($result) ? $result->get_error_code() : '', 'non-git error code');

    $result = $adopt($outside);
    $assert(true, is_wp_error($result), 'outside workspace path is rejected');
    $assert('adopt_outside_workspace', is_wp_error($result) ? $result->get_error_code() : '', 'outside workspace error code');

    $result = $adopt($collision_path, 'collision');
    $assert(true, is_wp_error($result), 'name collision is rejected');
    $assert('adopt_name_collision', is_wp_error($result) ? $result->get_error_code() : '', 'collision error code');

    $result = $adopt($collision_path, 'future-name');
    $assert(true, is_wp_error($result), 'adoption refuses moves or symlinks');
    $assert('adopt_requires_workspace_path', is_wp_error($result) ? $result->get_error_code() : '', 'move/symlink refusal error code');

    echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
