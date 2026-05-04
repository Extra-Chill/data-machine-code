<?php
/**
 * Worktree emergency cleanup task for agent-owned maintenance flows.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeEmergencyCleanupTask extends SystemTask {

	public function getTaskType(): string {
		return 'worktree_emergency_cleanup';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Emergency Worktree Cleanup',
			'description'     => 'Manual disk-pressure emergency cleanup planning for DMC workspaces.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$opts = array( 'force' => ! empty( $params['force'] ) );
		if ( isset( $params['apply_plan'] ) && is_array( $params['apply_plan'] ) ) {
			$opts['apply_plan'] = $params['apply_plan'];
		}

		$result = ( new Workspace() )->worktree_emergency_cleanup( $opts );
		if ( $result instanceof \WP_Error ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}
		$this->completeJob( $jobId, $result );
	}
}
