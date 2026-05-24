<?php
/**
 * Smoke test for workspace disk emergency cleanup system task.
 *
 *   php tests/smoke-workspace-disk-emergency-task.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__);
    }

    $GLOBALS['__disk_emergency_task_logs'] = array();

    function do_action( string $hook, ...$args ): void
    {
        $GLOBALS['__disk_emergency_task_logs'][] = array( $hook, $args );
    }
}

namespace DataMachine\Core {
    class PluginSettings
    {
        public static bool $enabled = true;

        public static function get( string $key, $default = null )  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            return self::$enabled;
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
            self::$batches[] = array( $task_type, $items, $context );
            return array(
            'batch_job_id' => 900,
            'job_ids'      => array( 901 ),
            'task_type'    => $task_type,
            );
        }
    }
}

namespace DataMachineCode\Workspace {
    class Workspace
    {
        public function workspace_disk_emergency_cleanup( array $opts = array() ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return array(
            'success'                 => true,
            'triggered'               => true,
            'dry_run'                 => ! empty($opts['dry_run']),
            'action_required'         => true,
            'selected_artifact_count' => 2,
            'disk_budget'             => array(
            'trigger_reasons' => array( 'worktree_count_warning_threshold' ),
            ),
            'apply_plan'              => array(
            'artifact_candidates' => array(
            array( 'handle' => 'repo@one' ),
            array( 'handle' => 'repo@two' ),
            ),
            ),
            );
        }
    }
}

namespace {
    include_once dirname(__DIR__) . '/inc/Tasks/WorkspaceDiskEmergencyCleanupTask.php';

    function datamachine_code_disk_emergency_task_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    echo "=== smoke-workspace-disk-emergency-task ===\n";

    $settings_class = '\\DataMachine\\Core\\PluginSettings';
    $enabled_prop   = 'enabled';
    $completed_prop = 'completed';
    $failed_prop    = 'failed';

    echo "\n[1] Disabled task completes as skipped\n";
    $settings_class::$$enabled_prop = false;
    $task = new \DataMachineCode\Tasks\WorkspaceDiskEmergencyCleanupTask();
    $task->executeTask(301, array());
    datamachine_code_disk_emergency_task_assert('workspace_disk_emergency_cleanup' === $task->getTaskType(), 'task type is stable');
    datamachine_code_disk_emergency_task_assert(true === ( $task->{$completed_prop}[0][1]['skipped'] ?? false ), 'disabled task reports skipped completion');
    datamachine_code_disk_emergency_task_assert(array() === $task->{$failed_prop}, 'disabled task does not fail');

    echo "\n[2] Enabled task logs triggered cleanup\n";
    $GLOBALS['__disk_emergency_task_logs'] = array();
    $settings_class::$$enabled_prop = true;
    $task = new \DataMachineCode\Tasks\WorkspaceDiskEmergencyCleanupTask();
    $task->executeTask(302, array( 'artifact_chunk_size' => 2 ));
    datamachine_code_disk_emergency_task_assert(true === (bool) ( $task->{$completed_prop}[0][1]['triggered'] ?? false ), 'enabled task stores triggered report');
    datamachine_code_disk_emergency_task_assert(true === (bool) ( $task->{$completed_prop}[0][1]['job_backed'] ?? false ), 'enabled task schedules chunk jobs');
    datamachine_code_disk_emergency_task_assert(1 === count(\DataMachine\Engine\Tasks\TaskScheduler::$batches), 'enabled task schedules one cleanup batch');
    datamachine_code_disk_emergency_task_assert('worktree_cleanup_chunk' === ( \DataMachine\Engine\Tasks\TaskScheduler::$batches[0][0] ?? '' ), 'scheduled batch uses cleanup chunk task');
    datamachine_code_disk_emergency_task_assert(1 === count($GLOBALS['__disk_emergency_task_logs']), 'enabled task emits one log event');
    $first_log = $GLOBALS['__disk_emergency_task_logs'][0] ?? array();
    datamachine_code_disk_emergency_task_assert('warning' === (string) ( $first_log[1][0] ?? '' ), 'action-required run logs as warning');
    datamachine_code_disk_emergency_task_assert(str_contains((string) ( $first_log[1][1] ?? '' ), 'scheduled 1 artifact chunk(s)'), 'log summary includes scheduled chunk count');

    echo "\nAll workspace disk emergency task smoke tests passed.\n";
}
