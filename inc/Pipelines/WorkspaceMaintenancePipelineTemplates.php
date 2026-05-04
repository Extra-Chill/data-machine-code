<?php
/**
 * Bundled workspace maintenance pipeline templates.
 *
 * @package DataMachineCode\Pipelines
 */

namespace DataMachineCode\Pipelines;

use DataMachine\Core\Database\Pipelines\Pipelines;

defined( 'ABSPATH' ) || exit;

/**
 * Installs DMC-owned, agent-generic workspace maintenance pipeline templates.
 */
class WorkspaceMaintenancePipelineTemplates {

	public const VERSION = '2026-05-04';
	public const OPTION_NAME = 'datamachine_code_workspace_maintenance_pipeline_templates_version';

	/**
	 * Register the installer hook.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'install' ), 30 );
	}

	/**
	 * Install or refresh bundled template pipelines.
	 */
	public static function install(): void {
		if ( get_option( self::OPTION_NAME ) === self::VERSION ) {
			return;
		}

		if ( ! class_exists( Pipelines::class ) ) {
			return;
		}

		$repo = new Pipelines();
		foreach ( self::templates() as $template ) {
			$pipeline_data = array(
				'user_id'         => 0,
				'pipeline_name'   => $template['name'],
				'pipeline_config' => self::pipeline_config( $template ),
				'portable_slug'   => $template['slug'],
			);

			$existing = self::find_existing_pipeline( $template['slug'] );
			if ( $existing > 0 ) {
				$repo->update_pipeline( $existing, $pipeline_data );
				continue;
			}

			$repo->create_pipeline( $pipeline_data );
		}

		update_option( self::OPTION_NAME, self::VERSION, true );
	}

	/**
	 * Return bundled template definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function templates(): array {
		return array(
			'workspace-inventory' => array(
				'slug'        => 'dmc-workspace-inventory',
				'name'        => 'DMC Workspace Inventory',
				'description' => 'Cheap disk/worktree inventory, disk budget state, and top cleanup offenders.',
				'inputs'      => array( 'workspace_root', 'size_limit', 'include_cleanup', 'include_sizes', 'include_worktree_status' ),
				'steps'       => array(
					array(
						'id'             => 'workspace_hygiene_report',
						'type'           => 'system_task',
						'label'          => 'Inventory workspace disk and worktrees',
						'handler_config' => array(
							'task'   => 'workspace_hygiene_report',
							'params' => array(
								'include_cleanup'         => true,
								'include_sizes'           => true,
								'include_worktree_status' => false,
								'size_limit'              => 200,
							),
						),
					),
				),
			),
			'workspace-metadata-repair' => array(
				'slug'        => 'dmc-workspace-metadata-repair',
				'name'        => 'DMC Workspace Metadata Repair',
				'description' => 'Review-first lifecycle metadata backfill for legacy worktrees.',
				'inputs'      => array( 'workspace_root', 'apply_plan' ),
				'steps'       => array(
					array(
						'id'             => 'reconcile_metadata_plan',
						'type'           => 'ai',
						'label'          => 'Plan metadata reconciliation',
						'enabled_tools'  => array( 'workspace_path', 'workspace_list' ),
						'user_message'   => 'Inspect DMC workspace inventory and prepare a review-first metadata reconciliation plan. Apply nothing automatically; use `studio wp datamachine-code workspace worktree reconcile-metadata --dry-run` for the canonical plan and only apply a reviewed plan later.',
					),
				),
			),
			'workspace-artifact-cleanup' => array(
				'slug'        => 'dmc-workspace-artifact-cleanup',
				'name'        => 'DMC Workspace Artifact Cleanup',
				'description' => 'Remove safe generated artifacts before touching worktrees.',
				'inputs'      => array( 'workspace_root', 'artifact_profiles', 'apply_plan', 'force' ),
				'steps'       => array(
					array(
						'id'             => 'artifact_cleanup_plan',
						'type'           => 'system_task',
						'label'          => 'Plan artifact-only cleanup',
						'handler_config' => array(
							'task'   => 'workspace_retention_cleanup',
							'params' => array(
								'dry_run'          => false,
								'artifact_cleanup' => true,
								'worktree_cleanup' => false,
								'skip_github'      => true,
							),
						),
					),
				),
			),
			'workspace-retention-cleanup' => array(
				'slug'        => 'dmc-workspace-retention-cleanup',
				'name'        => 'DMC Workspace Retention Cleanup',
				'description' => 'Age-gated cleanup for finalized, merged, or stale worktrees after retention windows.',
				'inputs'      => array( 'workspace_root', 'retention_windows', 'apply_plan', 'force', 'skip_github' ),
				'steps'       => array(
					array(
						'id'             => 'retention_cleanup',
						'type'           => 'system_task',
						'label'          => 'Run retention cleanup',
						'handler_config' => array(
							'task'   => 'workspace_retention_cleanup',
							'params' => array(
								'dry_run'              => false,
								'worktree_older_than'  => '14d',
								'artifact_cleanup'     => true,
								'worktree_cleanup'     => true,
								'skip_github'          => true,
							),
						),
					),
				),
			),
			'workspace-emergency-cleanup' => array(
				'slug'        => 'dmc-workspace-emergency-cleanup',
				'name'        => 'DMC Workspace Emergency Cleanup',
				'description' => 'Disk-pressure recovery template: artifact-first, reviewable, escalating only after a plan is inspected.',
				'inputs'      => array( 'workspace_root', 'disk_thresholds', 'apply_plan', 'force' ),
				'steps'       => array(
					array(
						'id'             => 'emergency_inventory',
						'type'           => 'system_task',
						'label'          => 'Build emergency inventory',
						'handler_config' => array(
							'task'   => 'workspace_hygiene_report',
							'params' => array(
								'include_cleanup'         => true,
								'include_sizes'           => true,
								'include_worktree_status' => false,
								'size_limit'              => 500,
							),
						),
					),
					array(
						'id'             => 'emergency_review',
						'type'           => 'ai',
						'label'          => 'Review emergency cleanup plan',
						'enabled_tools'  => array( 'workspace_path', 'workspace_list' ),
						'user_message'   => 'Prioritize artifact/cache cleanup before worktree deletion. Escalate if disk pressure remains after safe artifact removal. Never apply destructive cleanup without a reviewed plan.',
					),
				),
			),
		);
	}

	/**
	 * Build Data Machine pipeline_config from a template definition.
	 *
	 * @param array<string,mixed> $template Template definition.
	 * @return array<string,array<string,mixed>>
	 */
	private static function pipeline_config( array $template ): array {
		$config = array();
		foreach ( array_values( $template['steps'] ?? array() ) as $index => $step ) {
			$step_id = sanitize_key( $template['slug'] . '-' . ( $step['id'] ?? 'step-' . $index ) );
			$config[ $step_id ] = array_filter(
				array(
					'pipeline_step_id' => $step_id,
					'step_type'        => $step['type'] ?? '',
					'execution_order'  => $index,
					'label'            => $step['label'] ?? ucfirst( str_replace( '_', ' ', (string) ( $step['type'] ?? '' ) ) ),
					'system_prompt'    => $step['user_message'] ?? null,
					'enabled_tools'    => $step['enabled_tools'] ?? null,
					'handler_config'   => $step['handler_config'] ?? null,
					'template_meta'    => array(
						'slug'        => $template['slug'],
						'description' => $template['description'],
						'inputs'      => $template['inputs'],
					),
				),
				fn( $value ) => null !== $value
			);
		}

		return $config;
	}

	/**
	 * Find an existing shared DMC template pipeline by portable slug.
	 */
	private static function find_existing_pipeline( string $slug ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'datamachine_pipelines';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pipeline_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pipeline_id FROM {$table} WHERE portable_slug = %s AND user_id = 0 AND (agent_id IS NULL OR agent_id = 0) LIMIT 1",
				$slug
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $pipeline_id ? (int) $pipeline_id : 0;
	}
}
