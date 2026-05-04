<?php
/**
 * Worktree metadata repair task for agent-owned maintenance flows.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeMetadataRepairTask extends SystemTask {

	public function getTaskType(): string {
		return 'worktree_metadata_repair';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Worktree Metadata Repair',
			'description'     => 'Dry-run-first worktree metadata reconciliation for DMC maintenance flows.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$opts = array( 'dry_run' => array_key_exists( 'dry_run', $params ) ? (bool) $params['dry_run'] : true );
		if ( isset( $params['apply_plan'] ) && is_array( $params['apply_plan'] ) ) {
			$opts['apply_plan'] = $params['apply_plan'];
		}

		$result = ( new Workspace() )->worktree_reconcile_metadata( $opts );
		if ( $result instanceof \WP_Error ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}
		$this->completeJob( $jobId, $result );
	}
}
