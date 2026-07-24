<?php
/**
 * Workspace Retention Cleanup System Task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachineCode\Support\SystemTaskDrainability;
use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

class WorkspaceRetentionCleanupTask extends SystemTask {



	/**
	 * PluginSettings key that gates recurring/manual task execution.
	 */
	public const SETTING_KEY = 'workspace_retention_cleanup_enabled';

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'workspace_retention_cleanup';
	}

	/**
	 * Pure workspace/disk maintenance — runs without agent ownership context.
	 *
	 * This task applies age-gated worktree and reconstructable-artifact cleanup
	 * via the Workspace service (disk/file/git ops gated by PluginSettings). It
	 * never acts as an agent or invokes an agent-scoped ability; the only agent_id
	 * it touches is read from task params (defaulting to 0) to forward into child
	 * cleanup chunk jobs. It is registered as an agent-less daily recurring
	 * schedule, so it must opt out of the SystemTask agent-context gate or
	 * TaskScheduler::schedule() rejects it before it runs.
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
			'label'           => 'Workspace Retention Cleanup',
			'description'     => 'Age-gated cleanup for stale DMC worktrees plus bounded cleanup of reconstructable artifacts. Runs daily by default on local agent installs.',
			'setting_key'     => self::SETTING_KEY,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	/**
	 * Execute retention cleanup.
	 *
	 * @param  int   $jobId  Job ID.
	 * @param  array $params Task params.
	 * @return void
	 */
	public function executeTask( int $jobId, array $params ): void {
		$source  = (string) ( $params['source'] ?? '' );
		$enabled = (bool) PluginSettings::get(self::SETTING_KEY, true)
		|| 'workspace_cleanup_cli' === $source;
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf('Workspace retention cleanup disabled (PluginSettings: %s=false).', self::SETTING_KEY),
				)
			);
			return;
		}

		$opts = array(
			'dry_run'             => ! empty($params['dry_run']),
			'force'               => ! empty($params['force']),
			'skip_github'         => array_key_exists('skip_github', $params) ? (bool) $params['skip_github'] : true,
			'worktree_cleanup'    => array_key_exists('worktree_cleanup', $params) ? (bool) $params['worktree_cleanup'] : true,
			'artifact_cleanup'    => array_key_exists('artifact_cleanup', $params) ? (bool) $params['artifact_cleanup'] : true,
			'worktree_stale_only' => ! empty($params['worktree_stale_only']),
		);
		if ( isset($params['worktree_older_than']) && '' !== trim( (string) $params['worktree_older_than']) ) {
			$opts['worktree_older_than'] = trim( (string) $params['worktree_older_than']);
		}

		$workspace = new Workspace();
		$result    = ! empty($opts['dry_run'])
		? $workspace->workspace_retention_cleanup($opts)
		: $this->schedule_job_backed_cleanup($jobId, $workspace, $opts, $params);

		if ( $result instanceof \WP_Error ) {
			do_action(
				'datamachine_log',
				'error',
				'Workspace retention cleanup failed',
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

		$report = (array) ( $result['report'] ?? array() );
		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Workspace retention cleanup %s: removed %d item(s), freed %s, skipped %d dirty/unpushed, %s remaining.',
				! empty($result['dry_run']) ? 'dry-run' : 'executed',
				(int) ( $report['removed_count'] ?? 0 ),
				(string) ( $report['freed_human'] ?? '0 B' ),
				(int) ( $report['skipped_dirty_unpushed_count'] ?? 0 ),
				(string) ( $report['remaining_disk_budget_human'] ?? 'unknown disk' )
			),
			array(
				'task'   => $this->getTaskType(),
				'jobId'  => $jobId,
				'report' => $result,
			)
		);

		$this->completeJob($jobId, $result);
	}

	/**
	 * Build reviewed plans and schedule cleanup chunks as child Data Machine jobs.
	 *
	 * @param  int       $jobId     Parent job ID.
	 * @param  Workspace $workspace Workspace service.
	 * @param  array     $opts      Normalized options.
	 * @param  array     $params    Raw task params.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function schedule_job_backed_cleanup( int $jobId, Workspace $workspace, array $opts, array $params ): array|\WP_Error {
		$started_at = microtime(true);
		$chunk_rows = $this->build_cleanup_chunk_rows($workspace, $opts, $params);
		if ( $chunk_rows instanceof \WP_Error ) {
			return $chunk_rows;
		}

		$chunk_row_counts = array(
			'artifact_discovery' => count($chunk_rows['artifact_discovery']),
			'artifacts'          => count($chunk_rows['artifacts']),
			'worktrees'          => count($chunk_rows['worktrees']),
		);
		$item_params      = $this->build_cleanup_chunk_params($chunk_rows, $opts, $params);

		if ( array() === $item_params ) {
			return array(
				'success'          => true,
				'dry_run'          => false,
				'destructive'      => false,
				'job_backed'       => true,
				'generated_at'     => gmdate('c'),
				'workspace_path'   => $workspace->get_path(),
				'chunk_row_counts' => $chunk_row_counts,
				'chunks'           => array(),
				'report'           => array(
					'removed_count'                => 0,
					'bytes_reclaimed'              => 0,
					'freed_human'                  => '0 B',
					'skipped_dirty_unpushed_count' => 0,
					'remaining_disk_budget_human'  => 'unknown disk',
				),
				'evidence'         => array(
					'elapsed_ms' => (int) round(( microtime(true) - $started_at ) * 1000),
					'note'       => 'No cleanup chunks were eligible after plan generation.',
				),
			);
		}

		if ( ! class_exists(TaskScheduler::class) ) {
			return new \WP_Error('task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable; cannot schedule cleanup chunks.', array( 'status' => 500 ));
		}

		$batch = TaskScheduler::scheduleBatch(
			'worktree_cleanup_chunk',
			$item_params,
			array(
				'parent_job_id' => $jobId,
				'source'        => 'workspace_retention_cleanup',
				'user_id'       => (int) ( $params['user_id'] ?? 0 ),
				'agent_id'      => (int) ( $params['agent_id'] ?? 0 ),
			)
		);

		if ( false === $batch ) {
			return new \WP_Error('cleanup_chunk_schedule_failed', 'Failed to schedule cleanup chunk jobs.', array( 'status' => 500 ));
		}

		$drainability = SystemTaskDrainability::ensure_jobs_have_execute_step_actions(
			is_array($batch['job_ids'] ?? null) ? $batch['job_ids'] : array()
		);

		return array(
			'success'          => true,
			'dry_run'          => false,
			'destructive'      => true,
			'job_backed'       => true,
			'generated_at'     => gmdate('c'),
			'workspace_path'   => $workspace->get_path(),
			'policy'           => array(
				'worktree_cleanup'    => (bool) $opts['worktree_cleanup'],
				'artifact_cleanup'    => (bool) $opts['artifact_cleanup'],
				'worktree_older_than' => (string) ( $opts['worktree_older_than'] ?? '14d' ),
				'worktree_stale_only' => (bool) $opts['worktree_stale_only'],
				'skip_github'         => (bool) $opts['skip_github'],
				'force'               => (bool) $opts['force'],
			),
			'chunk_row_counts' => $chunk_row_counts,
			'chunks'           => $batch,
			'report'           => array(
				'removed_count'                => 0,
				'bytes_reclaimed'              => 0,
				'freed_human'                  => 'pending child jobs',
				'skipped_dirty_unpushed_count' => 0,
				'remaining_disk_budget_human'  => 'pending child jobs',
			),
			'evidence'         => array(
				'elapsed_ms'      => (int) round(( microtime(true) - $started_at ) * 1000),
				'planned_chunks'  => count($item_params),
				'planned_handles' => $this->cleanup_chunk_handles($chunk_rows),
				'batch_job_id'    => (int) ( $batch['batch_job_id'] ?? 0 ),
				'direct_job_ids'  => $batch['job_ids'] ?? array(),
				'drainability'    => $drainability,
			),
		);
	}

	/**
	 * Build cleanup rows from reviewed dry-run plans.
	 *
	 * @param  Workspace $workspace Workspace service.
	 * @param  array     $opts      Normalized options.
	 * @param  array     $params    Raw task params.
	 * @return array{artifact_discovery:array<int,array>,artifacts:array<int,array>,worktrees:array<int,array>}|\WP_Error
	 */
	private function build_cleanup_chunk_rows( Workspace $workspace, array $opts, array $params ): array|\WP_Error {
		$rows = array(
			'artifact_discovery' => array(),
			'artifacts'          => array(),
			'worktrees'          => array(),
		);

		if ( ! empty($opts['artifact_cleanup']) ) {
			$artifact_page = $workspace->worktree_cleanup_artifacts(
				array(
					'dry_run'       => true,
					'force'         => ! empty($opts['force']),
					'exhaustive'    => true,
					'safety_probes' => true,
				)
			);
			if ( $artifact_page instanceof \WP_Error ) {
				return $artifact_page;
			}
			$rows['artifacts'] = array_values( (array) $artifact_page['candidates']);
		}

		if ( ! empty($opts['worktree_cleanup']) ) {
			$worktree_plan = $workspace->worktree_cleanup_merged(
				array(
					'dry_run'             => true,
					'force'               => ! empty($opts['force']),
					'skip_github'         => ! empty($opts['skip_github']),
					'older_than'          => (string) ( $opts['worktree_older_than'] ?? '14d' ),
					'sort'                => 'age',
					'stale_liveness_only' => ! empty($opts['worktree_stale_only']),
				)
			);
			if ( $worktree_plan instanceof \WP_Error ) {
				return $worktree_plan;
			}
			$rows['worktrees'] = array_values( (array) $worktree_plan['candidates']);
		}

		return $rows;
	}

	/**
	 * Convert planned rows into child task params.
	 *
	 * @param  array $chunk_rows Chunk rows by type.
	 * @param  array $opts       Normalized options.
	 * @param  array $params     Raw task params.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_cleanup_chunk_params( array $chunk_rows, array $opts, array $params ): array {
		$item_params = array();
		$sizes       = array(
			'artifacts' => max(1, (int) ( $params['artifact_chunk_size'] ?? 10 )),
			'metadata'  => max(1, (int) ( $params['metadata_chunk_size'] ?? 10 )),
			// Whole-worktree removal intentionally stays single-row per child job to
			// keep git worktree metadata mutations conservative and lock-friendly.
			'worktrees' => 1,
		);

		foreach ( (array) ( $chunk_rows['artifact_discovery'] ?? array() ) as $index => $page ) {
			$item_params[] = array(
				'chunk_type'  => 'artifact_discovery',
				'chunk_index' => $index,
				'limit'       => max(1, (int) ( $page['limit'] ?? $sizes['artifacts'] )),
				'offset'      => max(0, (int) ( $page['offset'] ?? 0 )),
				'force'       => ! empty($opts['force']),
				'skip_github' => ! empty($opts['skip_github']),
			);
		}

		foreach ( array( 'artifacts', 'metadata', 'worktrees' ) as $type ) {
			foreach ( array_chunk( (array) ( $chunk_rows[ $type ] ?? array() ), $sizes[ $type ]) as $index => $rows ) {
				$item_params[] = array(
					'chunk_type'          => $type,
					'chunk_index'         => $index,
					'rows'                => $rows,
					'force'               => ! empty($opts['force']),
					'skip_github'         => ! empty($opts['skip_github']),
					'stale_liveness_only' => ! empty($opts['worktree_stale_only']),
				);
			}
		}

		return $item_params;
	}

	/**
	 * Summarize planned handles by chunk type.
	 *
	 * @param  array $chunk_rows Chunk rows by type.
	 * @return array<string,array<int,string>>
	 */
	private function cleanup_chunk_handles( array $chunk_rows ): array {
		$handles = array();
		foreach ( $chunk_rows as $type => $rows ) {
			$handles[ $type ] = array_values(array_unique(array_filter(array_map(fn( $row ) => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '', (array) $rows))));
		}

		return $handles;
	}
}
