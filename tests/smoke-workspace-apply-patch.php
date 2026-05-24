<?php
/**
 * Smoke test for workspace unified patch application.
 *
 * Run: php tests/smoke-workspace-apply-patch.php
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
}

namespace DataMachine\Core\FilesRepository {
    class FilesystemHelper
    {
        public static function get(): self
        {
            return new self();
        }

        public function is_writable( string $path ): bool
        {
            return is_writable($path);
        }
    }
}

namespace {
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceWriter.php';

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

    $run = function ( string $command, string $cwd ): void {
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $exit_code);
        if (0 !== $exit_code ) {
            throw new RuntimeException(implode("\n", $output));
        }
    };

    $tmp = sys_get_temp_dir() . '/dmc-workspace-apply-patch-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    register_shutdown_function(
        function () use ( $tmp ) {
            if (is_dir($tmp) ) {
                exec('rm -rf ' . escapeshellarg($tmp));
            }
        } 
    );

    define('DATAMACHINE_WORKSPACE_PATH', $tmp);

    $worktree = $tmp . '/demo@ci-fix';
    mkdir($worktree, 0755, true);
    $run('git init -q && git config user.email t@example.com && git config user.name Test', $worktree);
    file_put_contents($worktree . '/README.md', "one\n");
    $run('git add README.md && git commit -q -m initial', $worktree);

    $primary = $tmp . '/demo';
    mkdir($primary, 0755, true);
    $run('git init -q && git config user.email t@example.com && git config user.name Test', $primary);

    file_put_contents($worktree . '/README.md', "two\n");
    exec('cd ' . escapeshellarg($worktree) . ' && git diff -- README.md 2>&1', $patch_output, $patch_exit);
    if (0 !== $patch_exit ) {
        throw new RuntimeException(implode("\n", $patch_output));
    }
    $patch = implode("\n", $patch_output) . "\n";
    file_put_contents($worktree . '/README.md', "one\n");

    echo "Workspace apply patch\n";

    $writer = new \DataMachineCode\Workspace\WorkspaceWriter(new \DataMachineCode\Workspace\Workspace());
    $result = $writer->apply_patch('demo@ci-fix', $patch);
    $assert(false, is_wp_error($result), 'patch applies to a worktree handle');
    $assert("two\n", file_get_contents($worktree . '/README.md'), 'patch mutates file content');
    $assert(array( 'README.md' ), is_wp_error($result) ? array() : ( $result['changed_files'] ?? array() ), 'changed file evidence is returned');
    $assert(true, is_wp_error($result) ? false : str_contains((string) ( $result['diff'] ?? '' ), '+two'), 'diff evidence includes applied change');

    $again = $writer->apply_patch('demo@ci-fix', $patch);
    $assert(true, is_wp_error($again), 'stale patch fails closed');
    $assert('patch_check_failed', is_wp_error($again) ? $again->get_error_code() : '', 'stale patch fails during check phase');
    $assert("two\n", file_get_contents($worktree . '/README.md'), 'failed check does not mutate file content');

    $blocked = $writer->apply_patch('demo', $patch);
    $assert(true, is_wp_error($blocked), 'primary checkout mutation is blocked by default');
    $assert('primary_mutation_blocked', is_wp_error($blocked) ? $blocked->get_error_code() : '', 'primary block uses expected error code');

    if ($failures > 0 ) {
        echo "\n{$failures} of {$total} assertions failed.\n";
        exit(1);
    }

    echo "\nAll {$total} workspace apply patch assertions passed.\n";
}
