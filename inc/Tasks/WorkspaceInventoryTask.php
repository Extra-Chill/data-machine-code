<?php
/**
 * Workspace inventory task for agent-owned maintenance flows.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

class WorkspaceInventoryTask extends SystemTask {

	public function getTaskType(): string {
		return 'workspace_inventory';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Workspace Inventory',
			'description'     => 'Agent-owned workspace inventory report for DMC maintenance flows.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$workspace = new Workspace();
		$result    = $workspace->workspace_hygiene_report( array(
			'include_cleanup'         => array_key_exists( 'include_cleanup', $params ) ? (bool) $params['include_cleanup'] : true,
			'include_sizes'           => array_key_exists( 'include_sizes', $params ) ? (bool) $params['include_sizes'] : true,
			'include_worktree_status' => array_key_exists( 'include_worktree_status', $params ) ? (bool) $params['include_worktree_status'] : false,
			'size_limit'              => isset( $params['size_limit'] ) ? (int) $params['size_limit'] : 200,
		) );

		$this->complete_or_fail( $jobId, $result );
	}

	private function complete_or_fail( int $jobId, array|\WP_Error $result ): void {
		if ( $result instanceof \WP_Error ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}
		$this->completeJob( $jobId, $result );
	}
}
