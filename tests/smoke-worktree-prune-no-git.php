<?php
/**
 * Pure-PHP smoke for worktree prune when the workspace is visible but git is unavailable.
 *
 * Run: php tests/smoke-worktree-prune-no-git.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    class FilesystemHelper
    {
        public static function get(): ?self
        {
            return null;
        }
    }
}

namespace {
    $tmp = sys_get_temp_dir() . '/dmc-worktree-prune-no-git-' . getmypid();
    if (! defined('ABSPATH') ) {
        define('ABSPATH', $tmp . '/wp/');
    }
    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', $tmp . '/workspace');
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public function __construct( private string $code, private string $message, private array $data = array() )
            {
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
        function is_wp_error( $value ): bool
        {
            return $value instanceof WP_Error;
        }
    }

    $failures = array();
    $total    = 0;
    $assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
        ++$total;
        if ($condition ) {
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    $old_path = getenv('PATH');
    putenv('PATH=/nonexistent-dmc-no-git');

    mkdir(DATAMACHINE_WORKSPACE_PATH . '/demo/.git', 0777, true);

    require __DIR__ . '/../inc/Support/RuntimeCapabilities.php';
    require __DIR__ . '/../inc/Support/ProcessRunner.php';
    require __DIR__ . '/../inc/Support/GitRunner.php';
    require __DIR__ . '/../inc/Support/PathSecurity.php';
    require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    require __DIR__ . '/../inc/Workspace/Workspace.php';

    echo "Worktree prune without git - smoke\n";

    $workspace = new DataMachineCode\Workspace\Workspace();
    $result    = $workspace->worktree_prune();

    $assert('prune returns success instead of git-unavailable error', ! is_wp_error($result) && true === ( $result['success'] ?? false ));
    $assert('prune records skipped primary', ! is_wp_error($result) && 'demo' === ( $result['skipped'][0]['repo'] ?? '' ));
    $assert('prune returns host git command', ! is_wp_error($result) && str_contains((string) ( $result['next_commands'][0] ?? '' ), 'git -C'));
    $assert('inventory refresh still runs', ! is_wp_error($result) && isset($result['inventory']['summary']));

    putenv(false === $old_path ? 'PATH' : 'PATH=' . $old_path);

    if (is_dir($tmp) ) {
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    if (! empty($failures) ) {
        echo "\nFAIL: " . count($failures) . " assertion(s) failed out of {$total}\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK ({$total} assertions)\n";
    exit(0);
}
