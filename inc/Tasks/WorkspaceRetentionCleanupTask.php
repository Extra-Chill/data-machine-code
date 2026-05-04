<?php
/**
 * Workspace Retention Cleanup System Task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

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
	 * Task metadata for Data Machine system-task surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Workspace Retention Cleanup',
			'description'     => 'Age-gated cleanup for stale DMC worktrees plus aggressive cleanup of reconstructable artifacts. Runs daily when enabled.',
			'setting_key'     => self::SETTING_KEY,
			'default_enabled' => false,
			'supports_run'    => true,
		);
	}

	/**
	 * Execute retention cleanup.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params Task params.
	 * @return void
	 */
	public function executeTask( int $jobId, array $params ): void {
		$enabled = (bool) PluginSettings::get( self::SETTING_KEY, false )
			|| 'workspace_cleanup_cli' === (string) ( $params['source'] ?? '' );
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf( 'Workspace retention cleanup disabled (PluginSettings: %s=false).', self::SETTING_KEY ),
				)
			);
			return;
		}

		$opts = array(
			'dry_run'          => ! empty( $params['dry_run'] ),
			'force'            => ! empty( $params['force'] ),
			'skip_github'      => array_key_exists( 'skip_github', $params ) ? (bool) $params['skip_github'] : true,
			'worktree_cleanup' => array_key_exists( 'worktree_cleanup', $params ) ? (bool) $params['worktree_cleanup'] : true,
			'artifact_cleanup' => array_key_exists( 'artifact_cleanup', $params ) ? (bool) $params['artifact_cleanup'] : true,
		);
		if ( isset( $params['worktree_older_than'] ) && '' !== trim( (string) $params['worktree_older_than'] ) ) {
			$opts['worktree_older_than'] = trim( (string) $params['worktree_older_than'] );
		}

		$workspace = new Workspace();
		$result    = $workspace->workspace_retention_cleanup( $opts );

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
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$report = (array) ( $result['report'] ?? array() );
		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Workspace retention cleanup %s: removed %d item(s), freed %s, skipped %d dirty/unpushed, %s remaining.',
				! empty( $result['dry_run'] ) ? 'dry-run' : 'executed',
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

		$this->completeJob( $jobId, $result );
	}
}
