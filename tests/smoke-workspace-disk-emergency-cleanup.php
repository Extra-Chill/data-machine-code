<?php
/**
 * Smoke test for threshold-triggered workspace emergency cleanup orchestration.
 *
 *   php tests/smoke-workspace-disk-emergency-cleanup.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    if (! class_exists(__NAMESPACE__ . '\FilesystemHelper') ) {
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
    $workspace_root = sys_get_temp_dir() . '/dmc-disk-emergency-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($workspace_root, 0755, true);
    mkdir($workspace_root . '/repo@one', 0755, true);
    mkdir($workspace_root . '/repo@two', 0755, true);

    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }
    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', $workspace_root);
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public function __construct( public string $code = '', public string $message = '', public array $data = array() )
            {
            }
        }
    }

    function is_wp_error( $value ): bool
    {
        return $value instanceof \WP_Error;
    }

    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

    function datamachine_code_disk_emergency_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    class Disk_Emergency_Workspace extends \DataMachineCode\Workspace\Workspace
    {
        public array $apply_inputs = array();

        public function worktree_emergency_cleanup( array $opts = array() ): array|\WP_Error
        {
            if (isset($opts['apply_plan']) ) {
                $this->apply_inputs[] = $opts;
                return array(
                 'success'           => true,
                 'dry_run'           => false,
                 'removed_artifacts' => $opts['apply_plan']['artifact_candidates'] ?? array(),
                 'removed_worktrees' => $opts['apply_plan']['worktree_candidates'] ?? array(),
                 'summary'           => array(
                  'removed_artifacts'      => count($opts['apply_plan']['artifact_candidates'] ?? array()),
                  'removed_worktrees'      => count($opts['apply_plan']['worktree_candidates'] ?? array()),
                  'skipped_by_reason'      => array(),
                 ),
                );
            }

            return array(
            'success'             => true,
            'mode'                => 'emergency',
            'dry_run'             => true,
            'artifact_candidates' => array(
            array(
            'handle'              => 'repo@one',
            'repo'                => 'repo',
            'branch'              => 'one',
            'path'                => DATAMACHINE_WORKSPACE_PATH . '/repo@one',
            'artifact_size_bytes' => 2048,
            'artifacts'           => array( array( 'path' => 'vendor', 'size_bytes' => 2048 ) ),
            ),
            array(
                        'handle'              => 'repo@two',
                        'repo'                => 'repo',
                        'branch'              => 'two',
                        'path'                => DATAMACHINE_WORKSPACE_PATH . '/repo@two',
                        'artifact_size_bytes' => 1024,
                        'artifacts'           => array( array( 'path' => 'node_modules', 'size_bytes' => 1024 ) ),
            ),
            ),
            'worktree_candidates' => array(
            array(
                        'handle' => 'repo@two',
                        'repo'   => 'repo',
                        'branch' => 'two',
                        'path'   => DATAMACHINE_WORKSPACE_PATH . '/repo@two',
            ),
            ),
            'skipped'             => array(),
            'summary'             => array(),
            );
        }
    }

    echo "=== smoke-workspace-disk-emergency-cleanup ===\n";

    try {
        $thresholds = array(
        'warn_free_bytes'     => 0,
        'refuse_free_bytes'   => 0,
        'warn_free_percent'   => 0,
        'refuse_free_percent' => 0,
        'warn_worktree_count' => 1,
        );

        $workspace = new Disk_Emergency_Workspace();
        $result    = $workspace->workspace_disk_emergency_cleanup(
            array(
            'thresholds'          => $thresholds,
            'artifact_chunk_size' => 1,
            )
        );

        datamachine_code_disk_emergency_assert(! is_wp_error($result), 'threshold emergency cleanup succeeds');
        datamachine_code_disk_emergency_assert(true === (bool) ( $result['triggered'] ?? false ), 'worktree-count threshold triggers cleanup');
        datamachine_code_disk_emergency_assert(1 === count($workspace->apply_inputs), 'triggered cleanup applies one reviewed chunk');
        datamachine_code_disk_emergency_assert(1 === count($workspace->apply_inputs[0]['apply_plan']['artifact_candidates'] ?? array()), 'artifact chunk is bounded');
        datamachine_code_disk_emergency_assert(array() === ( $workspace->apply_inputs[0]['apply_plan']['worktree_candidates'] ?? array( 'unexpected' ) ), 'worktree deletion is not automatic while artifacts remain');
        datamachine_code_disk_emergency_assert('repo@one' === ( $result['disk_budget']['top_artifact_offenders'][0]['handle'] ?? '' ), 'top artifact offenders are exposed on disk budget');

        $workspace = new Disk_Emergency_Workspace();
        $result    = $workspace->workspace_disk_emergency_cleanup(
            array(
            'thresholds' => array_merge($thresholds, array( 'warn_worktree_count' => 100 )),
            )
        );
        datamachine_code_disk_emergency_assert(false === (bool) ( $result['triggered'] ?? true ), 'healthy threshold skips cleanup');
        datamachine_code_disk_emergency_assert(array() === $workspace->apply_inputs, 'skipped cleanup does not apply a plan');
    } finally {
        rmdir($workspace_root . '/repo@two');
        rmdir($workspace_root . '/repo@one');
        rmdir($workspace_root);
    }

    echo "\nAll workspace disk emergency cleanup smoke tests passed.\n";
}
