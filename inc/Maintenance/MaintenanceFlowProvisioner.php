<?php
/**
 * Agent maintenance flow provisioner.
 *
 * @package DataMachineCode\Maintenance
 */

namespace DataMachineCode\Maintenance;

use DataMachineCode\Pipelines\WorkspaceMaintenancePipelineTemplates;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotently creates agent-owned DMC maintenance pipelines and flows.
 */
class MaintenanceFlowProvisioner {

	private const PIPELINE_SLUG_PREFIX = 'dmc-maintenance-';
	private const FLOW_SLUG_SUFFIX     = '-flow';
	private const MARKER_KEY           = 'data_machine_code_agent_maintenance';
	private const SCHEMA               = 'data-machine-code/agent-maintenance-flow/v1';

	/**
	 * Provision all maintenance flows for an agent.
	 *
	 * @param array<string,mixed> $args Provisioning args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function provision( array $args ): array|\WP_Error {
		$agent = $this->resolve_agent( $args );
		if ( $agent instanceof \WP_Error ) {
			return $agent;
		}

		if ( ! class_exists( '\DataMachine\Core\Database\Pipelines\Pipelines' ) || ! class_exists( '\DataMachine\Core\Database\Flows\Flows' ) || ! class_exists( WorkspaceMaintenancePipelineTemplates::class ) ) {
			return new \WP_Error( 'datamachine_repositories_missing', 'Data Machine pipeline/flow repositories are not available.' );
		}

		$pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$flows     = new \DataMachine\Core\Database\Flows\Flows();
		$context   = $this->build_context( $agent, $args );

		$results = array();
		foreach ( WorkspaceMaintenancePipelineTemplates::templates() as $template_key => $definition ) {
			$provisioned = $this->provision_one( $pipelines, $flows, (int) $agent['agent_id'], (string) $template_key, $definition, $context );
			if ( $provisioned instanceof \WP_Error ) {
				return $provisioned;
			}
			$results[] = $provisioned;
		}

		return array(
			'success'        => true,
			'agent_id'       => (int) $agent['agent_id'],
			'agent_slug'     => (string) $agent['agent_slug'],
			'workspace_root' => (string) $context['workspace_root'],
			'flows'          => $results,
		);
	}

	/**
	 * @param object              $pipelines Pipelines repository.
	 * @param object              $flows     Flows repository.
	 * @param array<string,mixed> $definition Flow definition.
	 * @param array<string,mixed> $context Provisioning context.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function provision_one( object $pipelines, object $flows, int $agent_id, string $template_key, array $definition, array $context ): array|\WP_Error {
		$pipeline_slug = (string) ( $definition['slug'] ?? self::PIPELINE_SLUG_PREFIX . $template_key );
		$flow_slug     = $pipeline_slug . self::FLOW_SLUG_SUFFIX;
		$name          = (string) $definition['name'];
		$pipeline      = $pipelines->get_by_portable_slug( $agent_id, $pipeline_slug );
		$status        = 'unchanged';

		if ( ! $pipeline ) {
			$pipeline_id = $pipelines->create_pipeline( array(
				'pipeline_name'   => $name,
				'pipeline_config' => array(),
				'agent_id'        => $agent_id,
				'portable_slug'   => $pipeline_slug,
			) );
			if ( ! $pipeline_id ) {
				return new \WP_Error( 'maintenance_pipeline_create_failed', sprintf( 'Failed to create maintenance pipeline %s.', $name ) );
			}
			$pipeline = $pipelines->get_pipeline( (int) $pipeline_id );
			$status   = 'created';
		}

		$pipeline_id     = (int) $pipeline['pipeline_id'];
		$pipeline_config = $this->build_pipeline_config( $pipeline_id, $definition );
		if ( (array) ( $pipeline['pipeline_config'] ?? array() ) !== $pipeline_config || (string) ( $pipeline['pipeline_name'] ?? '' ) !== $name ) {
			$updated = $pipelines->update_pipeline( $pipeline_id, array(
				'pipeline_name'   => $name,
				'pipeline_config' => $pipeline_config,
				'portable_slug'   => $pipeline_slug,
			) );
			if ( ! $updated ) {
				return new \WP_Error( 'maintenance_pipeline_update_failed', sprintf( 'Failed to update maintenance pipeline %s.', $name ) );
			}
			$status = 'created' === $status ? 'created' : 'updated';
		}

		$flow       = $flows->get_by_portable_slug( $pipeline_id, $flow_slug );
		$scheduling = $this->build_scheduling_config( $template_key, $definition, $context );
		if ( ! $flow ) {
			$flow_id = $flows->create_flow( array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $name,
				'flow_config'       => array(),
				'scheduling_config' => $scheduling,
				'agent_id'          => $agent_id,
				'portable_slug'     => $flow_slug,
			) );
			if ( ! $flow_id ) {
				return new \WP_Error( 'maintenance_flow_create_failed', sprintf( 'Failed to create maintenance flow %s.', $name ) );
			}
			$flow   = $flows->get_flow( (int) $flow_id );
			$status = 'created';
		}

		$flow_id     = (int) $flow['flow_id'];
		$flow_config = $this->build_flow_config( $pipeline_id, $flow_id, $definition, $context );
		if ( (array) ( $flow['flow_config'] ?? array() ) !== $flow_config || (array) ( $flow['scheduling_config'] ?? array() ) !== $scheduling || (string) ( $flow['flow_name'] ?? '' ) !== $name ) {
			$updated = $flows->update_flow( $flow_id, array(
				'flow_name'         => $name,
				'flow_config'       => $flow_config,
				'scheduling_config' => $scheduling,
				'portable_slug'     => $flow_slug,
			) );
			if ( ! $updated ) {
				return new \WP_Error( 'maintenance_flow_update_failed', sprintf( 'Failed to update maintenance flow %s.', $name ) );
			}
			$this->sync_schedule( $flow_id, $scheduling );
			$status = 'created' === $status ? 'created' : 'updated';
		}

		return array(
			'slug'          => $template_key,
			'status'        => $status,
			'pipeline_id'   => $pipeline_id,
			'pipeline_slug' => $pipeline_slug,
			'flow_id'       => $flow_id,
			'flow_slug'     => $flow_slug,
			'flow_name'     => $name,
			'interval'      => (string) ( $scheduling['interval'] ?? 'manual' ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function build_pipeline_config( int $pipeline_id, array $definition ): array {
		$config = array();
		foreach ( array_values( (array) ( $definition['steps'] ?? array() ) ) as $index => $step ) {
			$step_id            = $this->pipeline_step_id( $pipeline_id, $definition, (array) $step, $index );
			$config[ $step_id ] = array_filter(
				array(
					'pipeline_step_id' => $step_id,
					'step_type'        => (string) ( $step['type'] ?? '' ),
					'execution_order'  => $index,
					'label'            => (string) ( $step['label'] ?? ucfirst( str_replace( '_', ' ', (string) ( $step['type'] ?? '' ) ) ) ),
					'system_prompt'    => $step['user_message'] ?? null,
					'enabled_tools'    => $step['enabled_tools'] ?? null,
					'template_meta'    => array(
						'slug'        => (string) ( $definition['slug'] ?? '' ),
						'description' => (string) ( $definition['description'] ?? '' ),
						'inputs'      => (array) ( $definition['inputs'] ?? array() ),
					),
				),
				fn( $value ) => null !== $value
			);
		}

		return $config;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function build_flow_config( int $pipeline_id, int $flow_id, array $definition, array $context ): array {
		$config = array();
		foreach ( array_values( (array) ( $definition['steps'] ?? array() ) ) as $index => $step ) {
			$step             = (array) $step;
			$pipeline_step_id = $this->pipeline_step_id( $pipeline_id, $definition, $step, $index );
			$flow_step_id     = $pipeline_step_id . '_' . $flow_id;
			$flow_step        = array_filter(
				array(
					'flow_step_id'     => $flow_step_id,
					'pipeline_step_id' => $pipeline_step_id,
					'step_type'        => (string) ( $step['type'] ?? '' ),
					'execution_order'  => $index,
					'queue_mode'       => 'static',
					'pipeline_id'      => $pipeline_id,
					'flow_id'          => $flow_id,
					'enabled_tools'    => $step['enabled_tools'] ?? null,
					'handler_config'   => $this->enrich_handler_config( (array) ( $step['handler_config'] ?? array() ), $context ),
				),
				fn( $value ) => null !== $value && array() !== $value
			);

			if ( isset( $step['user_message'] ) ) {
				$flow_step['prompt_queue'] = array(
					array(
						'prompt'   => (string) $step['user_message'],
						'added_at' => gmdate( 'c' ),
					),
				);
			}

			$config[ $flow_step_id ] = $flow_step;
		}

		return $config;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_scheduling_config( string $template_key, array $definition, array $context ): array {
		return array(
			'interval'       => $this->interval_for_template( $template_key ),
			self::MARKER_KEY => array(
				'schema'         => self::SCHEMA,
				'slug'           => (string) ( $definition['slug'] ?? '' ),
				'template_key'   => $template_key,
				'template_version' => WorkspaceMaintenancePipelineTemplates::VERSION,
				'workspace_root' => (string) ( $context['workspace_root'] ?? '' ),
				'agent_slug'     => (string) ( $context['agent_slug'] ?? '' ),
				'site_identity'  => (array) ( $context['site_identity'] ?? array() ),
				'defaults'       => array(
					'retention' => array(
						'worktree_older_than' => '30d',
						'skip_github'         => true,
					),
					'artifacts' => array(
						'profile' => 'reconstructable',
						'force'   => false,
					),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function enrich_handler_config( array $handler_config, array $context ): array {
		if ( empty( $handler_config['task'] ) ) {
			return $handler_config;
		}

		$params = is_array( $handler_config['params'] ?? null ) ? $handler_config['params'] : array();
		$params = array_merge(
			array(
				'source'         => 'agent_maintenance_flow',
				'workspace_root' => (string) ( $context['workspace_root'] ?? '' ),
				'site_identity'  => (array) ( $context['site_identity'] ?? array() ),
				'agent_slug'     => (string) ( $context['agent_slug'] ?? '' ),
			),
			$params
		);

		$handler_config['params'] = $params;
		return $handler_config;
	}

	private function interval_for_template( string $template_key ): string {
		return match ( $template_key ) {
			'workspace-inventory'         => 'hourly',
			'workspace-artifact-cleanup'  => 'every_4_hours',
			'workspace-metadata-repair',
			'workspace-retention-cleanup' => 'daily',
			default                       => 'manual',
		};
	}

	private function sync_schedule( int $flow_id, array $scheduling ): void {
		if ( class_exists( '\DataMachine\Api\Flows\FlowScheduling' ) ) {
			\DataMachine\Api\Flows\FlowScheduling::handle_scheduling_update( $flow_id, $scheduling, true );
		}
	}

	private function pipeline_step_id( int $pipeline_id, array $definition, array $step, int $index ): string {
		$slug = (string) ( $definition['slug'] ?? 'dmc-maintenance' );
		$id   = (string) ( $step['id'] ?? 'step-' . $index );
		$key  = $slug . '-' . $id;

		return $pipeline_id . '_' . ( function_exists( 'sanitize_key' ) ? sanitize_key( $key ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ) );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function resolve_agent( array $args ): array|\WP_Error {
		if ( ! class_exists( '\DataMachine\Core\Database\Agents\Agents' ) ) {
			return new \WP_Error( 'agent_repository_missing', 'Data Machine agent repository is not available.' );
		}

		$repo = new \DataMachine\Core\Database\Agents\Agents();
		if ( ! empty( $args['agent_id'] ) ) {
			$agent = method_exists( $repo, 'get_agent' ) ? $repo->get_agent( (int) $args['agent_id'] ) : null;
			return $agent ? $agent : new \WP_Error( 'agent_not_found', sprintf( 'Agent ID %d was not found.', (int) $args['agent_id'] ) );
		}

		$slug = $this->sanitize_slug( (string) ( $args['agent'] ?? $args['agent_slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$agent = $repo->get_by_slug( $slug );
			return $agent ? $agent : new \WP_Error( 'agent_not_found', sprintf( 'Agent "%s" was not found.', $slug ) );
		}

		return new \WP_Error( 'agent_required', 'Pass an agent slug or agent_id to provision maintenance flows.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_context( array $agent, array $args ): array {
		$workspace = new Workspace();
		return array(
			'workspace_root' => (string) ( $args['workspace_root'] ?? $workspace->get_path() ),
			'agent_slug'     => (string) ( $agent['agent_slug'] ?? '' ),
			'site_identity'  => array(
				'name' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
				'url'  => function_exists( 'home_url' ) ? (string) home_url() : '',
			),
		);
	}

	private function sanitize_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		return function_exists( 'sanitize_title' ) ? sanitize_title( $slug ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '-', $slug ) );
	}
}
