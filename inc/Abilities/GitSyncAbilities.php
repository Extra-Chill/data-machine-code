<?php
/**
 * GitSync Abilities
 *
 * WordPress Abilities API surface for the API-first GitSync primitive.
 * 7 abilities cover the full lifecycle: bind/unbind, list/status,
 * pull, submit (PR flow), push (direct-to-pinned), policy-update.
 *
 * Read-only abilities (list, status) are exposed via REST; every
 * mutating call is CLI-only — they either hit the filesystem (pull)
 * or spend a GitHub PAT (submit/push/policy), so they stay behind
 * explicit operator action.
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
			// Read-only (show_in_rest = true).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/gitsync-list',
				array(
					'label'               => 'List GitSync Bindings',
					'description'         => 'List every registered GitSync binding with a lightweight summary.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'bindings' => array( 'type' => 'array' ),
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
					'description'         => 'Report on-disk + upstream status for a single GitSync binding.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug' => array( 'type' => 'string' ),
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
							'last_pulled'    => array( 'type' => array( 'string', 'null' ) ),
							'last_commit'    => array( 'type' => array( 'string', 'null' ) ),
							'pulled_count'   => array( 'type' => 'integer' ),
							'policy'         => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'status' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// Mutating (show_in_rest = false).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/gitsync-bind',
				array(
					'label'               => 'Bind GitSync Path',
					'description'         => 'Register a binding between a site-owned local directory (relative to ABSPATH) and a GitHub repository. First pull materializes files.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug', 'local_path', 'remote_url' ),
						'properties' => array(
							'slug'       => array( 'type' => 'string' ),
							'local_path' => array( 'type' => 'string' ),
							'remote_url' => array( 'type' => 'string' ),
							'branch'     => array( 'type' => 'string' ),
							'policy'     => array( 'type' => 'object' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'binding' => array( 'type' => 'object' ),
							'message' => array( 'type' => 'string' ),
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
					'description'         => 'Remove a binding. Directory preserved by default; pass purge=true to delete it.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug'  => array( 'type' => 'string' ),
							'purge' => array( 'type' => 'boolean' ),
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
					'description'         => 'Download all files from the pinned branch to the local directory. Uses GitHub Contents API — no git binary required.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug'        => array( 'type' => 'string' ),
							'allow_dirty' => array(
								'type'        => 'boolean',
								'description' => 'Override the conflict policy for this pull only.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'slug'      => array( 'type' => 'string' ),
							'branch'    => array( 'type' => 'string' ),
							'tree_sha'  => array( 'type' => 'string' ),
							'updated'   => array( 'type' => 'array' ),
							'unchanged' => array( 'type' => 'integer' ),
							'deleted'   => array( 'type' => 'array' ),
							'conflicts' => array( 'type' => 'array' ),
							'truncated' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'pull' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/gitsync-submit',
				array(
					'label'               => 'Submit GitSync Binding as Pull Request',
					'description'         => 'Upload changed local files to the sticky proposal branch (gitsync/<slug>) and open or update a PR against the pinned branch.',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug', 'message' ),
						'properties' => array(
							'slug'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
							'paths'   => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional explicit list of relative paths. If omitted, every file with a SHA mismatch against upstream (filtered by allowed_paths) is submitted.',
							),
							'title'   => array( 'type' => 'string' ),
							'body'    => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'slug'    => array( 'type' => 'string' ),
							'branch'  => array( 'type' => 'string' ),
							'commits' => array( 'type' => 'array' ),
							'pr'      => array( 'type' => 'object' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'submit' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/gitsync-push',
				array(
					'label'               => 'Push GitSync Binding Directly',
					'description'         => 'Commit changed local files directly to the pinned branch — no PR. Requires policy.write_enabled=true AND policy.safe_direct_push=true (two-key authorization).',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug', 'message' ),
						'properties' => array(
							'slug'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
							'paths'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'slug'    => array( 'type' => 'string' ),
							'branch'  => array( 'type' => 'string' ),
							'commits' => array( 'type' => 'array' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'push' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/gitsync-policy-update',
				array(
					'label'               => 'Update GitSync Binding Policy',
					'description'         => 'Update one or more policy fields on an existing binding (write_enabled, safe_direct_push, allowed_paths, conflict, auto_pull, pull_interval).',
					'category'            => 'datamachine-code-gitsync',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug', 'policy' ),
						'properties' => array(
							'slug'   => array( 'type' => 'string' ),
							'policy' => array( 'type' => 'object' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'slug'    => array( 'type' => 'string' ),
							'policy'  => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'policyUpdate' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

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
		$args = array();
		if ( ! empty( $input['allow_dirty'] ) ) {
			$args['allow_dirty'] = true;
		}
		return ( new GitSync() )->pull( (string) ( $input['slug'] ?? '' ), $args );
	}

	public static function submit( array $input ): array|\WP_Error {
		$slug = (string) ( $input['slug'] ?? '' );
		$args = $input;
		unset( $args['slug'] );
		return ( new GitSync() )->submit( $slug, $args );
	}

	public static function push( array $input ): array|\WP_Error {
		$slug = (string) ( $input['slug'] ?? '' );
		$args = $input;
		unset( $args['slug'] );
		return ( new GitSync() )->push( $slug, $args );
	}

	public static function policyUpdate( array $input ): array|\WP_Error {
		$patch = $input['policy'] ?? array();
		if ( ! is_array( $patch ) ) {
			$patch = array();
		}
		return ( new GitSync() )->updatePolicy( (string) ( $input['slug'] ?? '' ), $patch );
	}
}
