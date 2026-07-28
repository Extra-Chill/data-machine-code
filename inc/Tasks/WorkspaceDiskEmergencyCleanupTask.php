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

class WorkspaceDiskEmergencyCleanupTask extends SystemTask {

	private const MAX_DURABLE_REASONS = 10;
	private const MAX_DURABLE_REASON_BYTES = 120;
	private const MAX_DURABLE_JOB_IDS = 25;
	/**
	 * PluginSettings key that gates threshold-triggered emergency cleanup.
	 */
	public const SETTING_KEY = 'workspace_disk_emergency_cleanup_enabled';

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'workspace_disk_emergency_cleanup';
	}

	/**
	 * Pure workspace/disk maintenance — runs without agent ownership context.
	 *
	 * This task cleans disk under pressure via the Workspace service (disk/file/
	 * git ops gated by PluginSettings). It never acts as an agent or invokes an
	 * agent-scoped ability; the only agent_id it touches is read from task params
	 * (defaulting to 0) to forward into child cleanup chunk jobs. It is registered
	 * as an agent-less hourly recurring schedule, so it must opt out of the
	 * SystemTask agent-context gate or TaskScheduler::schedule() rejects it before
	 * it runs.
	 *
	 * @return bool
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	/**
	 * Task metadata for Data Machine system-task surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
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
	public function executeTask( int $jobId, array $params ): void {
		$enabled = (bool) PluginSettings::get(self::SETTING_KEY, true);
		if ( ! $enabled ) {
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
		if ( ! empty($result['triggered']) && empty($opts['dry_run']) && ! empty($result['selected_artifact_count']) ) {
			$result = $this->schedule_artifact_cleanup_chunks($jobId, $result, $params);
		}

		if ( $result instanceof \WP_Error ) {
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
		$durable_report = $this->compact_durable_report($result);

		do_action(
			'datamachine_log',
			! empty($result['action_required']) ? 'warning' : 'info',
			$message,
			array(
				'task'   => $this->getTaskType(),
				'jobId'  => $jobId,
				'report' => $durable_report,
			)
		);

		$this->completeJob($jobId, $durable_report);
	}

	/**
	 * Project a recurring task result into bounded durable evidence.
	 *
	 * Workspace abilities and CLI commands intentionally retain the complete
	 * plan for explicit operator inspection. Recurring jobs retain only the
	 * operational facts needed to diagnose disk pressure and scheduled work.
	 *
	 * @param  array<string,mixed> $result Full emergency cleanup result.
	 * @return array<string,mixed>
	 */
	private function compact_durable_report( array $result ): array {
		$budget   = (array) ( $result['disk_budget'] ?? array() );
		$summary  = (array) ( $result['scheduled_summary'] ?? array() );
		$selection = (array) ( $result['inode_recovery_plan'] ?? array() );
		$capacity = (array) ( $result['capacity_evidence'] ?? array() );

		return array(
			'success'                  => ! empty($result['success']),
			'triggered'                => ! empty($result['triggered']),
			'skipped'                  => ! empty($result['skipped']),
			'dry_run'                  => ! empty($result['dry_run']),
			'generated_at'             => (string) ( $result['generated_at'] ?? '' ),
			'trigger_reasons'          => $this->compact_reasons($budget['trigger_reasons'] ?? array()),
			'selected_artifact_count'  => (int) ( $result['selected_artifact_count'] ?? 0 ),
			'selected_worktree_count'  => (int) ( $result['selected_worktree_count'] ?? 0 ),
			'scheduled'                => array(
				'chunks'        => (int) ( $summary['scheduled_chunks'] ?? 0 ),
				'artifact_rows'  => (int) ( $summary['scheduled_artifact_rows'] ?? 0 ),
				'batch_job_id'   => (int) ( $summary['batch_job_id'] ?? 0 ),
				'direct_job_ids' => array_slice(array_map('intval', (array) ( $summary['direct_job_ids'] ?? array() )), 0, self::MAX_DURABLE_JOB_IDS),
			),
			'measured_recovery'        => array(
				'target_bytes'          => (int) ( $budget['target_recovery_bytes'] ?? 0 ),
				'target_inodes'         => (int) ( $budget['target_recovery_inodes'] ?? 0 ),
				'planned_bytes'         => (int) ( $selection['planned_measured_recovery_bytes'] ?? 0 ),
				'planned_inodes'        => (int) ( $selection['planned_measured_recovery_inodes'] ?? 0 ),
				'target_met'            => ! empty($selection['target_met']),
				'reclaimed_bytes'       => is_numeric($capacity['reclaimed_bytes'] ?? null) ? (int) $capacity['reclaimed_bytes'] : null,
				'reclaimed_inodes'      => is_numeric($capacity['reclaimed_inodes'] ?? null) ? (int) $capacity['reclaimed_inodes'] : null,
			),
			'action_required'         => ! empty($result['action_required']),
			'action_required_reasons' => $this->compact_reasons($result['action_required_reasons'] ?? array()),
			'capacity_evidence'       => $this->compact_capacity_evidence($capacity),
		);
	}

	/**
	 * @param  mixed $reasons Candidate reason values.
	 * @return array<int,string>
	 */
	private function compact_reasons( mixed $reasons ): array {
		$compact = array();
		foreach ( array_slice((array) $reasons, 0, self::MAX_DURABLE_REASONS) as $reason ) {
			$compact[] = substr((string) $reason, 0, self::MAX_DURABLE_REASON_BYTES);
		}
		return $compact;
	}

	/**
	 * @param  array<string,mixed> $capacity Full capacity snapshots.
	 * @return array<string,mixed>
	 */
	private function compact_capacity_evidence( array $capacity ): array {
		$fields = array( 'filesystem_total_bytes', 'filesystem_free_bytes', 'filesystem_free_inodes', 'workspace_allocated_bytes' );
		$compact = array(
			'reclaimed_bytes'  => is_numeric($capacity['reclaimed_bytes'] ?? null) ? (int) $capacity['reclaimed_bytes'] : null,
			'reclaimed_inodes' => is_numeric($capacity['reclaimed_inodes'] ?? null) ? (int) $capacity['reclaimed_inodes'] : null,
			'before'           => array(),
			'after'            => array(),
		);

		foreach ( $fields as $field ) {
			$compact['before'][ $field ] = is_numeric($capacity['before'][ $field ] ?? null) ? (int) $capacity['before'][ $field ] : null;
			$compact['after'][ $field ]  = is_numeric($capacity['after'][ $field ] ?? null) ? (int) $capacity['after'][ $field ] : null;
		}

		return $compact;
	}

	/**
	 * Enqueue selected artifact cleanup rows as child chunk jobs.
	 *
	 * @param  int   $jobId  Parent job ID.
	 * @param  array $result Emergency cleanup dry-run result.
	 * @param  array $params Original task params.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function schedule_artifact_cleanup_chunks( int $jobId, array $result, array $params ): array|\WP_Error {
		if ( ! class_exists(TaskScheduler::class) ) {
			return new \WP_Error('task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable; cannot schedule emergency cleanup chunks.', array( 'status' => 500 ));
		}

		$rows = array_values( (array) ( $result['apply_plan']['artifact_candidates'] ?? array() ));
		if ( array() === $rows ) {
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

		if ( false === $batch ) {
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
