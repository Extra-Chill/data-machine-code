<?php
/**
 * Smoke test for workspace retention cleanup system task.
 *
 *   php tests/smoke-workspace-retention-task.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__);
    }

    $GLOBALS['__retention_task_logs'] = array();

    function is_wp_error( $value ): bool
    {
        return false;
    }

    function do_action( string $hook, ...$args ): void
    {
        $GLOBALS['__retention_task_logs'][] = array( $hook, $args );
    }
}

namespace DataMachine\Core {
    class PluginSettings
    {
        public static array $settings = array();

        public static function get( string $key, $default = null )
        {
            return array_key_exists($key, self::$settings) ? self::$settings[ $key ] : $default;
        }
    }
}

namespace DataMachine\Engine\AI\System\Tasks {
    abstract class SystemTask
    {
        public array $completed = array();
        public array $failed = array();

        abstract public function getTaskType(): string;

        protected function completeJob( int $jobId, array $data ): void
        {
            $this->completed[] = array( $jobId, $data );
        }

        protected function failJob( int $jobId, string $message ): void
        {
            $this->failed[] = array( $jobId, $message );
        }
    }
}

namespace DataMachine\Engine\Tasks {
    class TaskScheduler
    {
        public static array $batches = array();

        public static function scheduleBatch( string $task_type, array $items, array $context = array() )
        {
            self::$batches[] = array(
            'task_type' => $task_type,
            'items'     => $items,
            'context'   => $context,
            );

            return array(
            'batch_job_id' => 301,
            'job_ids'      => range(401, 400 + count($items)),
            );
        }
    }
}

namespace DataMachineCode\Workspace {
    class Workspace
    {
        public static array $artifact_opts = array();

        public function get_path(): string
        {
            return '/tmp/dmc-retention-task-workspace';
        }

        public function workspace_retention_cleanup( array $opts = array() ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return array(
            'success' => true,
            'dry_run' => ! empty($opts['dry_run']),
            'report'  => array(
            'removed_count'                => 2,
            'freed_human'                  => '16.0 MiB',
            'skipped_dirty_unpushed_count' => 3,
            'remaining_disk_budget_human'  => '400.0 GiB',
            ),
            );
        }

        public function worktree_cleanup_artifacts( array $opts = array() ): array
        {
            self::$artifact_opts[] = $opts;
            return array(
            'success'    => true,
            'dry_run'    => true,
            'candidates' => array(
            array(
            'handle'    => 'repo@active',
            'repo'      => 'repo',
            'branch'    => 'active',
            'path'      => '/tmp/dmc-retention-task-workspace/repo@active',
            'artifacts' => array( array( 'path' => 'vendor', 'size_bytes' => 1024 ) ),
            ),
            ),
            'skipped'    => array(),
            'summary'    => array(
            'pagination' => array(
                        'total' => 25,
            ),
            ),
            'pagination' => array(
            'total' => 25,
            ),
            );
        }

        public function worktree_reconcile_metadata( array $opts = array() ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return array( 'proposals' => array() );
        }

        public function worktree_cleanup_merged( array $opts = array() ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return array( 'candidates' => array() );
        }
    }
}

namespace {
    include_once dirname(__DIR__) . '/inc/Tasks/WorkspaceRetentionCleanupTask.php';

    function datamachine_code_retention_task_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    echo "=== smoke-workspace-retention-task ===\n";

    echo "\n[1] Default-enabled task runs without an explicit setting\n";
    $settings_class = '\\DataMachine\\Core\\PluginSettings';
    $settings_prop  = 'settings';
    $settings_class::$$settings_prop = array();
    $task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
    $task->executeTask(201, array( 'dry_run' => true ));
    $completed_prop = 'completed';
    $failed_prop    = 'failed';
    $completed      = $task->{$completed_prop};
    $failed         = $task->{$failed_prop};
    datamachine_code_retention_task_assert('workspace_retention_cleanup' === $task->getTaskType(), 'task type is stable');
    datamachine_code_retention_task_assert(true === (bool) ( $completed[0][1]['dry_run'] ?? false ), 'task runs with default PluginSettings fallback');
    datamachine_code_retention_task_assert(array() === $failed, 'default-enabled task does not fail the job');
    $meta = \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::getTaskMeta();
    datamachine_code_retention_task_assert(true === (bool) ( $meta['default_enabled'] ?? false ), 'task metadata defaults retention cleanup on');

    echo "\n[1b] Explicit disabled setting completes as skipped\n";
    $settings_class::$$settings_prop = array( \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::SETTING_KEY => false );
    $task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
    $task->executeTask(211, array());
    $completed = $task->{$completed_prop};
    $failed    = $task->{$failed_prop};
    datamachine_code_retention_task_assert(true === ( $completed[0][1]['skipped'] ?? false ), 'explicit disabled setting reports skipped completion');
    datamachine_code_retention_task_assert(array() === $failed, 'explicit disabled setting does not fail the job');

    echo "\n[2] Enabled task stores report and logs concise summary\n";
    $GLOBALS['__retention_task_logs'] = array();
    $settings_class::$$settings_prop = array( \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::SETTING_KEY => true );
    $task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
    $task->executeTask(202, array( 'dry_run' => true ));
    $completed = $task->{$completed_prop};
    datamachine_code_retention_task_assert(true === (bool) ( $completed[0][1]['dry_run'] ?? false ), 'enabled task forwards dry-run flag');
    datamachine_code_retention_task_assert(2 === (int) ( $completed[0][1]['report']['removed_count'] ?? 0 ), 'enabled task stores compact report');
    datamachine_code_retention_task_assert(1 === count($GLOBALS['__retention_task_logs']), 'enabled task emits one datamachine_log event');
    $first_log = $GLOBALS['__retention_task_logs'][0] ?? array();
    datamachine_code_retention_task_assert(str_contains((string) ( $first_log[1][1] ?? '' ), 'removed 2 item(s)'), 'log summary includes removed count');

    echo "\n[3] Explicit CLI run bypasses global disabled setting\n";
    $GLOBALS['__retention_task_logs'] = array();
    $settings_class::$$settings_prop = array( \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::SETTING_KEY => false );
    $task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
    $task->executeTask(203, array( 'source' => 'workspace_cleanup_cli', 'dry_run' => true ));
    $completed = $task->{$completed_prop};
    datamachine_code_retention_task_assert(empty($completed[0][1]['skipped']), 'explicit CLI run bypasses disabled recurring schedule');
    datamachine_code_retention_task_assert(true === (bool) ( $completed[0][1]['dry_run'] ?? false ), 'explicit CLI run forwards task params');

    echo "\n[4] Artifact cleanup freezes bounded candidates before scheduling chunks\n";
    \DataMachine\Engine\Tasks\TaskScheduler::$batches = array();
    \DataMachineCode\Workspace\Workspace::$artifact_opts = array();
    $task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
    $task->executeTask(
        204,
        array(
        'source'              => 'workspace_cleanup_cli',
        'artifact_cleanup'    => true,
        'worktree_cleanup'    => false,
        'artifact_chunk_size' => 10,
        'limit'               => 100,
        )
    );
    $completed = $task->{$completed_prop};
    $batch     = \DataMachine\Engine\Tasks\TaskScheduler::$batches[0] ?? array();
    datamachine_code_retention_task_assert('worktree_cleanup_chunk' === ( $batch['task_type'] ?? '' ), 'retention task schedules cleanup chunk batch');
    datamachine_code_retention_task_assert(1 === count($batch['items'] ?? array()), 'artifact candidates fan out proportionally to eligible rows');
    datamachine_code_retention_task_assert('artifacts' === ( $batch['items'][0]['chunk_type'] ?? '' ), 'artifact cleanup uses frozen candidate chunks instead of discovery pages');
    datamachine_code_retention_task_assert('repo@active' === ( $batch['items'][0]['rows'][0]['handle'] ?? '' ), 'artifact chunk carries reviewed candidate rows');
    datamachine_code_retention_task_assert(100 === (int) ( \DataMachineCode\Workspace\Workspace::$artifact_opts[0]['limit'] ?? 0 ), 'parent forwards artifact scan limit');
    datamachine_code_retention_task_assert(true === ( \DataMachineCode\Workspace\Workspace::$artifact_opts[0]['safety_probes'] ?? false ), 'parent runs safety probes before scheduling artifact apply chunks');
    datamachine_code_retention_task_assert(0 === (int) ( $completed[0][1]['chunk_row_counts']['artifact_discovery'] ?? -1 ), 'completion report shows no discovery chunks');
    datamachine_code_retention_task_assert(1 === (int) ( $completed[0][1]['chunk_row_counts']['artifacts'] ?? 0 ), 'completion report exposes artifact candidate chunk count');

    echo "\nAll workspace retention task smoke tests passed.\n";
}
