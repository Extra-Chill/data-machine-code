<?php
/**
 * Workspace cleanup plan handler settings.
 *
 * @package DataMachineCode\Handlers\Workspace
 */

namespace DataMachineCode\Handlers\Workspace;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CleanupPlanSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for the cleanup plan fetch handler.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_fields(): array {
		return array(
			'emit_chunks'            => array(
				'type'        => 'checkbox',
				'label'       => __( 'Emit Chunks', 'data-machine-code' ),
				'description' => __( 'Emit one DataPacket per bounded cleanup chunk instead of one frozen plan packet.', 'data-machine-code' ),
				'default'     => true,
			),
			'chunk_size'             => array(
				'type'        => 'number',
				'label'       => __( 'Chunk Size', 'data-machine-code' ),
				'description' => __( 'Maximum rows per chunk. Clamped to 1-50. Default: 10.', 'data-machine-code' ),
				'default'     => 10,
			),
			'include_artifacts'      => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Artifact Cleanup Rows', 'data-machine-code' ),
				'default'     => true,
			),
			'include_metadata'       => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Metadata Repair Rows', 'data-machine-code' ),
				'default'     => true,
			),
			'include_worktrees'      => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Worktree Removal Rows', 'data-machine-code' ),
				'default'     => true,
			),
			'include_resolvers'      => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Resolver Rows', 'data-machine-code' ),
				'description' => __( 'Emit read-only resolver rows for ambiguous inventory rows that need merge/lifecycle signal resolution.', 'data-machine-code' ),
				'default'     => false,
			),
			'worktree_older_than'    => array(
				'type'        => 'text',
				'label'       => __( 'Worktree Older Than', 'data-machine-code' ),
				'description' => __( 'Optional age filter for inventory-only worktree removal rows, such as 14d.', 'data-machine-code' ),
			),
			'force_artifact_cleanup' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Force Artifact Cleanup Planning', 'data-machine-code' ),
				'description' => __( 'Permit dirty/unpushed artifact cleanup candidates in the frozen plan. Active symlink targets remain protected.', 'data-machine-code' ),
				'default'     => false,
			),
		);
	}
}
