<?php
/**
 * GitSync Abilities
 *
 * WordPress Abilities API surface for the Phase 1 GitSync primitive:
 * bind, unbind, pull, status, list. Push, policy-update, and the
 * scheduled pull task land in later phases (see DMC#38).
 *
 * Read-only abilities (status, list) are exposed via REST; mutating
 * abilities (bind, unbind, pull) are CLI-only (`show_in_rest = false`)
 * since they change filesystem state and should be gated behind
 * manual review.
 *
 * @package DataMachineCode\Abilities
 * @since   0.7.0
 */

namespace DataMachineCode\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachineCode\GitSync\GitSync;

defined( 'ABSPATH' ) || exit;

class GitSyncAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {

			// -----------------------------------------------------------------
			// Read-only abilities (show_in_rest = true).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/gitsync-list',
				array(
					'label'               => 'List GitSync Bindings',
					'description'         => 'List every registered GitSync binding and its on-disk status snapshot.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'bindings' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'slug'          => array( 'type' => 'string' ),
										'local_path'    => array( 'type' => 'string' ),
										'absolute_path' => array( 'type' => 'string' ),
										'remote_url'    => array( 'type' => 'string' ),
										'branch'        => array( 'type' => 'string' ),
										'exists'        => array( 'type' => 'boolean' ),
										'is_repo'       => array( 'type' => 'boolean' ),
										'auto_pull'     => array( 'type' => 'boolean' ),
										'pull_interval' => array( 'type' => 'string' ),
										'last_pulled'   => array( 'type' => array( 'string', 'null' ) ),
										'last_commit'   => array( 'type' => array( 'string', 'null' ) ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listBindings' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/gitsync-status',
				array(
					'label'               => 'GitSync Binding Status',
					'description'         => 'Report on-disk status for a single GitSync binding: branch, HEAD, dirty count, ahead/behind vs upstream.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug' => array(
								'type'        => 'string',
								'description' => 'Binding slug.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'slug'           => array( 'type' => 'string' ),
							'local_path'     => array( 'type' => 'string' ),
							'remote_url'     => array( 'type' => 'string' ),
							'tracked_branch' => array( 'type' => 'string' ),
							'exists'         => array( 'type' => 'boolean' ),
							'is_repo'        => array( 'type' => 'boolean' ),
							'branch'         => array( 'type' => array( 'string', 'null' ) ),
							'head'           => array( 'type' => array( 'string', 'null' ) ),
							'dirty'          => array( 'type' => 'integer' ),
							'ahead'          => array( 'type' => array( 'integer', 'null' ) ),
							'behind'         => array( 'type' => array( 'integer', 'null' ) ),
							'last_pulled'    => array( 'type' => array( 'string', 'null' ) ),
							'policy'         => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'status' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// Mutating abilities (show_in_rest = false).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/gitsync-bind',
				array(
					'label'               => 'Bind GitSync Path',
					'description'         => 'Bind a site-owned directory (relative to ABSPATH) to a remote git repository. Clones the remote or adopts an existing matching checkout.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug', 'local_path', 'remote_url' ),
						'properties' => array(
							'slug'       => array(
								'type'        => 'string',
								'description' => 'Unique binding slug. Lowercase letters, digits, hyphen, underscore.',
							),
							'local_path' => array(
								'type'        => 'string',
								'description' => 'Path relative to ABSPATH, e.g. "/wp-content/uploads/markdown/wiki/".',
							),
							'remote_url' => array(
								'type'        => 'string',
								'description' => 'Git remote URL (https:// or git@).',
							),
							'branch'     => array(
								'type'        => 'string',
								'description' => 'Branch to track. Defaults to "main".',
							),
							'policy'     => array(
								'type'        => 'object',
								'description' => 'Policy overrides (auto_pull, pull_interval, conflict, write_enabled, push_enabled, allowed_paths).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'binding'    => array( 'type' => 'object' ),
							'cloned'     => array( 'type' => 'boolean' ),
							'adopted'    => array( 'type' => 'boolean' ),
							'local_path' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'bind' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/gitsync-unbind',
				array(
					'label'               => 'Unbind GitSync Path',
					'description'         => 'Remove a GitSync binding. By default the on-disk directory is preserved; pass purge=true to delete it.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug'  => array(
								'type'        => 'string',
								'description' => 'Binding slug.',
							),
							'purge' => array(
								'type'        => 'boolean',
								'description' => 'Also remove the on-disk directory. Default: false.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'slug'       => array( 'type' => 'string' ),
							'purged'     => array( 'type' => 'boolean' ),
							'local_path' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'unbind' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/gitsync-pull',
				array(
					'label'               => 'Pull GitSync Binding',
					'description'         => 'Fast-forward pull the remote into a bound directory, honoring the binding\'s conflict policy.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug'        => array(
								'type'        => 'string',
								'description' => 'Binding slug.',
							),
							'allow_dirty' => array(
								'type'        => 'boolean',
								'description' => 'Bypass dirty-working-tree safety for this pull. Default: false.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'slug'          => array( 'type' => 'string' ),
							'branch'        => array( 'type' => 'string' ),
							'previous_head' => array( 'type' => array( 'string', 'null' ) ),
							'head'          => array( 'type' => array( 'string', 'null' ) ),
							'message'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'pull' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		// Matches the WorkspaceAbilities lifecycle: register now if we're
		// inside the init action, defer if it hasn't fired yet, skip if it
		// already fired without us (registration missed the window).
		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// =========================================================================
	// Execute callbacks
	// =========================================================================

	public static function listBindings( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return ( new GitSync() )->list_bindings();
	}

	public static function status( array $input ): array|\WP_Error {
		return ( new GitSync() )->status( (string) ( $input['slug'] ?? '' ) );
	}

	public static function bind( array $input ): array|\WP_Error {
		return ( new GitSync() )->bind( $input );
	}

	public static function unbind( array $input ): array|\WP_Error {
		return ( new GitSync() )->unbind(
			(string) ( $input['slug'] ?? '' ),
			! empty( $input['purge'] )
		);
	}

	public static function pull( array $input ): array|\WP_Error {
		return ( new GitSync() )->pull(
			(string) ( $input['slug'] ?? '' ),
			! empty( $input['allow_dirty'] )
		);
	}
}
