<?php
/**
 * Worktree artifact cleanup task for agent-owned maintenance flows.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeArtifactCleanupTask extends SystemTask {

	public function getTaskType(): string {
		return 'worktree_artifact_cleanup';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Worktree Artifact Cleanup',
			'description'     => 'Dry-run-first artifact cleanup plan for reconstructable worktree outputs.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$opts = array(
			'dry_run' => array_key_exists( 'dry_run', $params ) ? (bool) $params['dry_run'] : true,
			'force'   => ! empty( $params['force'] ),
		);
		if ( isset( $params['apply_plan'] ) && is_array( $params['apply_plan'] ) ) {
			$opts['apply_plan'] = $params['apply_plan'];
		}

		$result = ( new Workspace() )->worktree_cleanup_artifacts( $opts );
		if ( $result instanceof \WP_Error ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}
		$this->completeJob( $jobId, $result );
	}
}
