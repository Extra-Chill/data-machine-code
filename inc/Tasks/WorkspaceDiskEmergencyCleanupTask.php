<?php
/**
 * Workspace Disk Emergency Cleanup System Task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

class WorkspaceDiskEmergencyCleanupTask extends SystemTask
{

    /**
     * PluginSettings key that gates threshold-triggered emergency cleanup.
     */
    public const SETTING_KEY = 'workspace_disk_emergency_cleanup_enabled';

    /**
     * Task type identifier.
     *
     * @return string
     */
    public function getTaskType(): string
    {
        return 'workspace_disk_emergency_cleanup';
    }

    /**
     * Task metadata for Data Machine system-task surfaces.
     *
     * @return array<string,mixed>
     */
    public static function getTaskMeta(): array
    {
        return array(
        'label'           => 'Workspace Disk Emergency Cleanup',
        'description'     => 'Threshold-triggered emergency cleanup for disk pressure. Applies reconstructable artifact chunks first and reports when worktree deletion needs human approval.',
        'setting_key'     => self::SETTING_KEY,
        'default_enabled' => true,
        'supports_run'    => true,
        );
    }

    /**
     * Execute threshold-triggered emergency cleanup.
     *
     * @param  int   $jobId  Job ID.
     * @param  array $params Task params.
     * @return void
     */
    public function executeTask( int $jobId, array $params ): void
    {
        $enabled = (bool) PluginSettings::get(self::SETTING_KEY, true);
        if (! $enabled ) {
            $this->completeJob(
                $jobId,
                array(
                'skipped' => true,
                'reason'  => sprintf('Workspace disk emergency cleanup disabled (PluginSettings: %s=false).', self::SETTING_KEY),
                )
            );
            return;
        }

        $opts = array(
        'dry_run'                          => ! empty($params['dry_run']),
        'artifact_chunk_size'              => isset($params['artifact_chunk_size']) ? (int) $params['artifact_chunk_size'] : 10,
        'allow_worktree_deletion'          => ! empty($params['allow_worktree_deletion']),
        'human_approved_worktree_deletion' => ! empty($params['human_approved_worktree_deletion']),
        'force'                            => ! empty($params['force']),
        );

        $workspace = new Workspace();
        $result    = $workspace->workspace_disk_emergency_cleanup(array_merge($opts, array( 'dry_run' => true )));
        if (! empty($result['triggered']) && empty($opts['dry_run']) && ! empty($result['selected_artifact_count']) ) {
            $result = $this->schedule_artifact_cleanup_chunks($jobId, $result, $params);
        }

        if ($result instanceof \WP_Error ) {
            do_action(
                'datamachine_log',
                'error',
                'Workspace disk emergency cleanup failed',
                array(
                'task'  => $this->getTaskType(),
                'jobId' => $jobId,
                'error' => $result->get_error_message(),
                'code'  => $result->get_error_code(),
                )
            );
            $this->failJob($jobId, $result->get_error_message());
            return;
        }

        $budget  = (array) ( $result['disk_budget'] ?? array() );
        $summary = (array) ( $result['scheduled_summary'] ?? array() );
        $message = ! empty($result['triggered'])
        ? sprintf(
            'Workspace disk emergency cleanup triggered (%s): scheduled %d artifact chunk(s) covering %d artifact row(s); action_required=%s.',
            implode(',', (array) ( $budget['trigger_reasons'] ?? array() )),
            (int) ( $summary['scheduled_chunks'] ?? 0 ),
            (int) ( $summary['scheduled_artifact_rows'] ?? 0 ),
            ! empty($result['action_required']) ? 'yes' : 'no'
        )
        : 'Workspace disk emergency cleanup skipped: disk thresholds not crossed.';

        do_action(
            'datamachine_log',
            ! empty($result['action_required']) ? 'warning' : 'info',
            $message,
            array(
            'task'   => $this->getTaskType(),
            'jobId'  => $jobId,
            'report' => $result,
            )
        );

        $this->completeJob($jobId, $result);
    }

    /**
     * Enqueue selected artifact cleanup rows as child chunk jobs.
     *
     * @param  int   $jobId  Parent job ID.
     * @param  array $result Emergency cleanup dry-run result.
     * @param  array $params Original task params.
     * @return array<string,mixed>|\WP_Error
     */
    private function schedule_artifact_cleanup_chunks( int $jobId, array $result, array $params ): array|\WP_Error
    {
        if (! class_exists(TaskScheduler::class) ) {
            return new \WP_Error('task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable; cannot schedule emergency cleanup chunks.', array( 'status' => 500 ));
        }

        $rows = array_values((array) ( $result['apply_plan']['artifact_candidates'] ?? array() ));
        if (array() === $rows ) {
            $result['job_backed']        = false;
            $result['scheduled_summary'] = array(
            'scheduled_chunks'        => 0,
            'scheduled_artifact_rows' => 0,
            );
            return $result;
        }

        $batch = TaskScheduler::scheduleBatch(
            'worktree_cleanup_chunk',
            array(
            array(
                    'chunk_type'  => 'artifacts',
                    'chunk_index' => 0,
                    'rows'        => $rows,
                    'force'       => ! empty($params['force_artifact_cleanup']),
            ),
            ),
            array(
            'parent_job_id' => $jobId,
            'source'        => 'workspace_disk_emergency_cleanup',
            'user_id'       => (int) ( $params['user_id'] ?? 0 ),
            'agent_id'      => (int) ( $params['agent_id'] ?? 0 ),
            )
        );

        if (false === $batch ) {
            return new \WP_Error('emergency_cleanup_chunk_schedule_failed', 'Failed to schedule emergency cleanup chunk jobs.', array( 'status' => 500 ));
        }

        $result['dry_run']           = false;
        $result['job_backed']        = true;
        $result['chunks']            = $batch;
        $result['scheduled_summary'] = array(
        'scheduled_chunks'        => 1,
        'scheduled_artifact_rows' => count($rows),
        'batch_job_id'            => (int) ( $batch['batch_job_id'] ?? 0 ),
        'direct_job_ids'          => $batch['job_ids'] ?? array(),
        );

        return $result;
    }
}
