<?php
/**
 * Workspace diff abilities.
 *
 * @package DataMachineCode\Abilities
 */

namespace DataMachineCode\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachineCode\Workspace\RemoteWorkspaceBackend;
use DataMachineCode\Workspace\WorkspaceDiff;

defined( 'ABSPATH' ) || exit;

class WorkspaceDiffAbilities {

	public function __construct() {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/workspace-diff-summary',
				array(
					'label'               => 'Workspace Diff Summary',
					'description'         => 'Summarize changed files and compact diff metadata for a workspace handle.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'from'   => array(
								'type'        => 'string',
								'description' => 'Optional from git ref.',
							),
							'to'     => array(
								'type'        => 'string',
								'description' => 'Optional to git ref.',
							),
							'staged' => array(
								'type'        => 'boolean',
								'description' => 'Summarize staged diff instead of working tree diff.',
							),
							'path'   => array(
								'type'        => 'string',
								'description' => 'Optional relative path filter.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'diffSummary' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-diff-validate',
				array(
					'label'               => 'Validate Workspace Diff',
					'description'         => 'Validate workspace diff shape using allowed/denied path patterns and optional test-change requirements.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'                  => array( 'type' => 'string' ),
							'from'                  => array( 'type' => 'string' ),
							'to'                    => array( 'type' => 'string' ),
							'staged'                => array( 'type' => 'boolean' ),
							'path'                  => array( 'type' => 'string' ),
							'allow'                 => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'deny'                  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'include_any'           => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'include_all'           => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'require_tests'         => array( 'type' => 'boolean' ),
							'require_changed_files' => array( 'type' => 'object' ),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'diffValidate' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
			return;
		}

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/** @param array<string,mixed> $input Input parameters. @return array<string,mixed>|\WP_Error */
	public static function diffSummary( array $input ): array|\WP_Error {
		if ( RemoteWorkspaceBackend::should_handle() ) {
			return new \WP_Error( 'workspace_diff_summary_remote_unsupported', 'Workspace diff summary currently supports local workspaces only.', array( 'status' => 501 ) );
		}

		$diff = new WorkspaceDiff();
		return $diff->summary(
			$input['name'] ?? '',
			$input['from'] ?? null,
			$input['to'] ?? null,
			! empty( $input['staged'] ),
			$input['path'] ?? null
		);
	}

	/** @param array<string,mixed> $input Input parameters. @return array<string,mixed>|\WP_Error */
	public static function diffValidate( array $input ): array|\WP_Error {
		if ( RemoteWorkspaceBackend::should_handle() ) {
			return new \WP_Error( 'workspace_diff_validate_remote_unsupported', 'Workspace diff validation currently supports local workspaces only.', array( 'status' => 501 ) );
		}

		$diff = new WorkspaceDiff();
		return $diff->validate( $input['name'] ?? '', $input );
	}
}
