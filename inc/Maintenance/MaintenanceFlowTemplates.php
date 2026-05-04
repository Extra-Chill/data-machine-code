<?php
/**
 * Agent maintenance flow templates.
 *
 * @package DataMachineCode\Maintenance
 */

namespace DataMachineCode\Maintenance;

defined( 'ABSPATH' ) || exit;

/**
 * Builds DMC workspace maintenance pipeline definitions.
 */
class MaintenanceFlowTemplates {

	public const SCHEMA = 'data-machine-code/agent-maintenance-flow/v1';

	/**
	 * Return all maintenance flow definitions with context defaults applied.
	 *
	 * @param array<string,mixed> $context Provisioning context.
	 * @return array<string,array<string,mixed>>
	 */
	public static function all( array $context ): array {
		$base_params = array(
			'source'         => 'agent_maintenance_flow',
			'workspace_root' => (string) ( $context['workspace_root'] ?? '' ),
			'site_identity'  => (array) ( $context['site_identity'] ?? array() ),
			'agent_slug'     => (string) ( $context['agent_slug'] ?? '' ),
		);

		return array(
			'workspace-inventory' => array(
				'name'        => 'DMC Workspace Inventory',
				'description' => 'Agent-owned inventory report for workspace size, disk pressure, and worktree counts.',
				'interval'    => 'hourly',
				'task_type'   => 'workspace_inventory',
				'params'      => array_merge( $base_params, array(
					'include_cleanup'         => true,
					'include_sizes'           => true,
					'include_worktree_status' => false,
					'size_limit'              => 200,
				) ),
			),
			'metadata-repair'     => array(
				'name'        => 'DMC Worktree Metadata Repair',
				'description' => 'Dry-run-first metadata reconciliation plan for malformed or incomplete worktree lifecycle metadata.',
				'interval'    => 'daily',
				'task_type'   => 'worktree_metadata_repair',
				'params'      => array_merge( $base_params, array(
					'dry_run' => true,
				) ),
			),
			'artifact-cleanup'    => array(
				'name'        => 'DMC Worktree Artifact Cleanup',
				'description' => 'Frequent dry-run artifact cleanup plan for reconstructable dependency/cache/build outputs.',
				'interval'    => 'every_4_hours',
				'task_type'   => 'worktree_artifact_cleanup',
				'params'      => array_merge( $base_params, array(
					'dry_run'          => true,
					'force'            => false,
					'artifact_profile' => 'reconstructable',
				) ),
			),
			'retention-cleanup'   => array(
				'name'        => 'DMC Workspace Retention Cleanup',
				'description' => 'Conservative retention cleanup for cleanup-eligible worktrees and reconstructable artifacts.',
				'interval'    => 'daily',
				'task_type'   => 'workspace_retention_cleanup',
				'params'      => array_merge( $base_params, array(
					'dry_run'             => false,
					'force'               => false,
					'skip_github'         => true,
					'worktree_cleanup'    => true,
					'artifact_cleanup'    => true,
					'worktree_older_than' => '30d',
				) ),
			),
			'emergency-cleanup'   => array(
				'name'        => 'DMC Emergency Workspace Cleanup',
				'description' => 'Manual disk-pressure emergency cleanup plan. Applies only after a reviewed plan is supplied.',
				'interval'    => 'manual',
				'task_type'   => 'worktree_emergency_cleanup',
				'params'      => array_merge( $base_params, array(
					'dry_run' => true,
					'force'   => false,
				) ),
			),
		);
	}

	/**
	 * Build a persistent workflow definition for a maintenance task.
	 *
	 * @param array<string,mixed> $definition Flow definition.
	 * @return array<string,mixed>
	 */
	public static function workflow( array $definition ): array {
		return array(
			'type'  => 'pipeline_template',
			'steps' => array(
				array(
					'id'             => 'run_maintenance_task',
					'type'           => 'system_task',
					'label'          => (string) ( $definition['name'] ?? 'DMC maintenance task' ),
					'handler_config' => array(
						'task'   => (string) ( $definition['task_type'] ?? '' ),
						'params' => (array) ( $definition['params'] ?? array() ),
					),
				),
			),
		);
	}
}
