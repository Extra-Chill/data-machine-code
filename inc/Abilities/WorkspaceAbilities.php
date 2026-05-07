<?php
/**
 * Workspace Abilities
 *
 * WordPress 6.9 Abilities API primitives for all agent workspace operations.
 * These are the canonical entry points — CLI commands and chat tools delegate here.
 *
 * Read-only abilities (path, list, show, read, ls) are exposed via REST.
 * Mutating abilities (clone, remove) are CLI-only (show_in_rest = false).
 *
 * @package DataMachineCode\Abilities
 * @since 0.1.0
 */

namespace DataMachineCode\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachineCode\Workspace\CleanupRunService;
use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorkspaceReader;
use DataMachineCode\Workspace\WorkspaceWriter;

defined( 'ABSPATH' ) || exit;

class WorkspaceAbilities {

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
			// Read-only discovery abilities (show_in_rest = true).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-path',
				array(
					'label'               => 'Get Workspace Path',
					'description'         => 'Get the agent workspace directory path. Optionally create the directory.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'ensure' => array(
								'type'        => 'boolean',
								'description' => 'Create the workspace directory if it does not exist.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'exists'  => array( 'type' => 'boolean' ),
							'created' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'getPath' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-list',
				array(
					'label'               => 'List Workspace Repos',
					'description'         => 'List repositories in the agent workspace.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'repos'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name'   => array( 'type' => 'string' ),
										'path'   => array( 'type' => 'string' ),
										'git'    => array( 'type' => 'boolean' ),
										// Local-only repos have no remote; detached HEAD has no branch.
										'remote' => array( 'type' => array( 'string', 'null' ) ),
										'branch' => array( 'type' => array( 'string', 'null' ) ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listRepos' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-show',
				array(
					'label'               => 'Show Workspace Repo',
					'description'         => 'Show detailed info about a workspace repository (branch, remote, latest commit, dirty status).',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` for primary checkout or `<repo>@<branch-slug>` for a worktree.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'name'        => array( 'type' => 'string' ),
							'repo'        => array( 'type' => 'string' ),
							'is_worktree' => array( 'type' => 'boolean' ),
							'path'        => array( 'type' => 'string' ),
							// Nullable: detached HEAD has no branch; local-only repos
							// have no remote; freshly-init'd repos have no commit yet.
							'branch'      => array( 'type' => array( 'string', 'null' ) ),
							'remote'      => array( 'type' => array( 'string', 'null' ) ),
							'commit'      => array( 'type' => array( 'string', 'null' ) ),
							'dirty'       => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'showRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// File reading abilities (show_in_rest = true).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-read',
				array(
					'label'               => 'Read Workspace File',
					'description'         => 'Read the contents of a text file from a workspace repository.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'     => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path'     => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'max_size' => array(
								'type'        => 'integer',
								'description' => 'Maximum file size in bytes (default 1 MB).',
							),
							'offset'   => array(
								'type'        => 'integer',
								'description' => 'Line number to start reading from (1-indexed).',
							),
							'limit'    => array(
								'type'        => 'integer',
								'description' => 'Maximum number of lines to return.',
							),
						),
						'required'   => array( 'repo', 'path' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'content'    => array( 'type' => 'string' ),
							'path'       => array( 'type' => 'string' ),
							'size'       => array( 'type' => 'integer' ),
							'lines_read' => array( 'type' => 'integer' ),
							'offset'     => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'readFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-ls',
				array(
					'label'               => 'List Workspace Directory',
					'description'         => 'List directory contents within a workspace repository.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path' => array(
								'type'        => 'string',
								'description' => 'Relative directory path within the repo (omit for root).',
							),
						),
						'required'   => array( 'repo' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'repo'    => array( 'type' => 'string' ),
							'path'    => array( 'type' => 'string' ),
							'entries' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name' => array( 'type' => 'string' ),
										'type' => array( 'type' => 'string' ),
										'size' => array( 'type' => 'integer' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listDirectory' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// Mutating abilities (show_in_rest = false, CLI-only).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-clone',
				array(
					'label'               => 'Clone Workspace Repo',
					'description'         => 'Clone a git repository into the workspace as a primary checkout. Worktrees are created separately via `workspace-worktree-add`.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'url'  => array(
								'type'        => 'string',
								'description' => 'Git repository URL to clone.',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Directory name override (derived from URL if omitted).',
							),
						),
						'required'   => array( 'url' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'path'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'cloneRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-adopt',
				array(
					'label'               => 'Adopt Workspace Repo',
					'description'         => 'Validate an existing git primary checkout already located under the workspace root so it can be managed by workspace commands.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'path' => array(
								'type'        => 'string',
								'description' => 'Existing git primary checkout path under DATAMACHINE_WORKSPACE_PATH.',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Workspace name override (derived from path basename if omitted).',
							),
						),
						'required'   => array( 'path' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'name'            => array( 'type' => 'string' ),
							'path'            => array( 'type' => 'string' ),
							'already_adopted' => array( 'type' => 'boolean' ),
							'message'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'adoptRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-remove',
				array(
					'label'               => 'Remove Workspace Repo',
					'description'         => 'Remove a workspace handle. Refuses to remove a primary that has linked worktrees.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'removeRepo' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-write',
				array(
					'label'               => 'Write Workspace File',
					'description'         => 'Create or overwrite a file in a workspace repository.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'    => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path'    => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'File content to write.',
							),
						),
						'required'   => array( 'repo', 'path', 'content' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'size'    => array( 'type' => 'integer' ),
							'created' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'writeFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-edit',
				array(
					'label'               => 'Edit Workspace File',
					'description'         => 'Find-and-replace text in a workspace repository file.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path'        => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'old_string'  => array(
								'type'        => 'string',
								'description' => 'Text to find.',
							),
							'new_string'  => array(
								'type'        => 'string',
								'description' => 'Replacement text.',
							),
							'replace_all' => array(
								'type'        => 'boolean',
								'description' => 'Replace all occurrences (default false).',
							),
						),
						'required'   => array( 'repo', 'path', 'old_string', 'new_string' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'path'         => array( 'type' => 'string' ),
							'replacements' => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'editFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-apply-patch',
				array(
					'label'               => 'Apply Workspace Patch',
					'description'         => 'Apply a unified diff to a workspace repository through git apply. Mutating ops on the primary checkout require allow_primary_mutation=true. The patch is checked before apply and fails closed on context mismatch.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'                   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'patch'                 => array(
								'type'        => 'string',
								'description' => 'Unified diff content to apply.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit mutation on the primary checkout (default false). Worktrees are always allowed.',
							),
						),
						'required'   => array( 'repo', 'patch' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'name'          => array( 'type' => 'string' ),
							'path'          => array( 'type' => 'string' ),
							'changed_files' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'diff'          => array( 'type' => 'string' ),
							'status'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'applyPatch' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-status',
				array(
					'label'               => 'Workspace Git Status',
					'description'         => 'Get git status information for a workspace handle (primary or worktree).',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'name'        => array( 'type' => 'string' ),
							'repo'        => array( 'type' => 'string' ),
							'is_worktree' => array( 'type' => 'boolean' ),
							'path'        => array( 'type' => 'string' ),
							// Nullable: detached HEAD has no branch; local-only repos
							// have no remote; freshly-init'd repos have no commit yet.
							'branch'      => array( 'type' => array( 'string', 'null' ) ),
							'remote'      => array( 'type' => array( 'string', 'null' ) ),
							'commit'      => array( 'type' => array( 'string', 'null' ) ),
							'dirty'       => array( 'type' => 'integer' ),
							'files'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'gitStatus' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-log',
				array(
					'label'               => 'Workspace Git Log',
					'description'         => 'Read git log entries for a workspace handle.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'  => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'limit' => array(
								'type'        => 'integer',
								'description' => 'Maximum log entries to return (1-100).',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'entries' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'hash'    => array( 'type' => 'string' ),
										'author'  => array( 'type' => 'string' ),
										'date'    => array( 'type' => 'string' ),
										'subject' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'gitLog' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-diff',
				array(
					'label'               => 'Workspace Git Diff',
					'description'         => 'Read git diff output for a workspace handle.',
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
								'description' => 'Read staged diff instead of working tree diff.',
							),
							'path'   => array(
								'type'        => 'string',
								'description' => 'Optional relative path filter.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'diff'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitDiff' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-pull',
				array(
					'label'               => 'Workspace Git Pull',
					'description'         => 'Run git pull --ff-only for a workspace handle. Mutating ops on the primary checkout require allow_primary_mutation=true.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'                   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'allow_dirty'            => array(
								'type'        => 'boolean',
								'description' => 'Allow pull when working tree is dirty.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit mutation on the primary checkout (default false). Worktrees are always allowed.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitPull' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-add',
				array(
					'label'               => 'Workspace Git Add',
					'description'         => 'Stage repository paths with git add. Mutating ops on the primary checkout require allow_primary_mutation=true.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'                   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'paths'                  => array(
								'type'        => 'array',
								'description' => 'Relative paths to stage.',
								'items'       => array( 'type' => 'string' ),
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit mutation on the primary checkout (default false). Worktrees are always allowed.',
							),
						),
						'required'   => array( 'name', 'paths' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'paths'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitAdd' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-delete',
				array(
					'label'               => 'Delete Workspace Path',
					'description'         => 'Delete a tracked or untracked file or directory from a workspace repository. Tracked paths are removed via git rm; untracked paths are unlinked from disk. Mutating ops on the primary checkout require allow_primary_mutation=true.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'                   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path'                   => array(
								'type'        => 'string',
								'description' => 'Relative path within the repo (file or directory).',
							),
							'recursive'              => array(
								'type'        => 'boolean',
								'description' => 'Required when target is a directory. Default false.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit mutation on the primary checkout (default false). Worktrees are always allowed.',
							),
						),
						'required'   => array( 'repo', 'path' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'name'        => array( 'type' => 'string' ),
							'repo'        => array( 'type' => 'string' ),
							'path'        => array( 'type' => 'string' ),
							'deleted'     => array(
								'type'        => 'array',
								'description' => 'Every relative path removed (recursive deletes report each entry).',
								'items'       => array( 'type' => 'string' ),
							),
							'was_tracked' => array(
								'type'        => 'boolean',
								'description' => 'True when the path was removed via git rm; false for untracked filesystem deletes.',
							),
						),
					),
					'execute_callback'    => array( self::class, 'deletePath' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-commit',
				array(
					'label'               => 'Workspace Git Commit',
					'description'         => 'Commit staged changes in a workspace handle. Mutating ops on the primary checkout require allow_primary_mutation=true.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'                   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'message'                => array(
								'type'        => 'string',
								'description' => 'Commit message.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit mutation on the primary checkout (default false). Worktrees are always allowed.',
							),
						),
						'required'   => array( 'name', 'message' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'commit'  => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitCommit' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-git-push',
				array(
					'label'               => 'Workspace Git Push',
					'description'         => 'Push commits for a workspace handle. `fixed_branch` policy applies only to the primary checkout; worktrees may push any branch.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'                   => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'remote'                 => array(
								'type'        => 'string',
								'description' => 'Remote name (default origin).',
							),
							'branch'                 => array(
								'type'        => 'string',
								'description' => 'Branch override.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit pushing from the primary checkout (default false). Worktrees are always allowed.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'name'    => array( 'type' => 'string' ),
							'remote'  => array( 'type' => 'string' ),
							'branch'  => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitPush' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			// -----------------------------------------------------------------
			// Worktree abilities (mutating, CLI-only by default).
			// -----------------------------------------------------------------

			wp_register_ability(
				'datamachine/workspace-worktree-add',
				array(
					'label'               => 'Add Workspace Worktree',
					'description'         => 'Create a git worktree for a branch under `<repo>@<branch-slug>`. Branches are created off the supplied `from` ref (default `origin/HEAD`) when they do not yet exist locally. When `inject_context` is true (default), the originating site\'s agent memory is snapshotted into `.claude/CLAUDE.local.md` and `.opencode/AGENTS.local.md` and added to the worktree\'s per-checkout `info/exclude`. When `bootstrap` is true (default), submodule init plus root or one-level nested package-manager/composer installs run after creation so the worktree is immediately test/build-ready; set false to create a bare checkout.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'           => array(
								'type'        => 'string',
								'description' => 'Primary repo name (no @-suffix).',
							),
							'branch'         => array(
								'type'        => 'string',
								'description' => 'Branch to check out in the worktree (e.g. fix/foo-bar). Slashes become dashes in the on-disk slug.',
							),
							'from'           => array(
								'type'        => 'string',
								'description' => 'Base ref when creating the branch (default origin/HEAD).',
							),
							'inject_context' => array(
								'type'        => 'boolean',
								'description' => 'Inject the originating site\'s agent context (MEMORY.md, USER.md, RULES.md) into the new worktree. Default true. Set false to create a bare worktree.',
							),
							'bootstrap'      => array(
								'type'        => 'boolean',
								'description' => 'Run detected bootstrap steps (submodule init plus root or one-level nested package-manager/composer installs) after creating the worktree. Default true. Steps are skipped gracefully when their trigger file or tool is missing. Set false for a bare checkout (e.g. when only reading code).',
							),
							'allow_stale'    => array(
								'type'        => 'boolean',
								'description' => 'Bypass the staleness gate. When false (default) and the new worktree would be more than `datamachine_worktree_stale_threshold` commits behind upstream, worktree creation is rolled back and a `worktree_stale` error is returned. Set true to opt in to a known-stale checkout.',
							),
							'rebase_base'    => array(
								'type'        => 'boolean',
								'description' => 'After creating the worktree, rebase onto the upstream tip (the branch\'s @{upstream} for existing branches, origin/<base> for new branches off a local base). Default false. On rebase conflicts the rebase is aborted; the worktree stays at its pre-rebase state and `rebase_succeeded: false` is surfaced.',
							),
							'force'          => array(
								'type'        => 'boolean',
								'description' => 'Explicitly bypass the disk-budget refusal threshold. The disk-budget report still appears in output so the override is visible.',
							),
							'task_url'       => array(
								'type'        => 'string',
								'description' => 'Optional task/issue URL (e.g. GitHub issue link) to record on the worktree for ownership/duplicate detection. Falls back to DATAMACHINE_TASK_URL env when omitted.',
							),
							'task_ref'       => array(
								'type'        => 'string',
								'description' => 'Optional short task/issue reference (e.g. `org/repo#123`) recorded alongside task_url. Falls back to DATAMACHINE_TASK_REF env when omitted.',
							),
						),
						'required'   => array( 'repo', 'branch' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'                   => array( 'type' => 'boolean' ),
							'handle'                    => array( 'type' => 'string' ),
							'path'                      => array( 'type' => 'string' ),
							'branch'                    => array( 'type' => 'string' ),
							'slug'                      => array( 'type' => 'string' ),
							'created_branch'            => array( 'type' => 'boolean' ),
							'message'                   => array( 'type' => 'string' ),
							'context_injected'          => array( 'type' => 'boolean' ),
							'context_files'             => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'context_exclude_path'      => array( 'type' => 'string' ),
							'context_skip_reason'       => array( 'type' => 'string' ),
							'bootstrap'                 => array(
								'type'        => 'object',
								'description' => 'Present only when bootstrap=true. Contains success/ran_any booleans and a steps array.',
							),
							'fetch_failed'              => array(
								'type'        => 'boolean',
								'description' => 'Present only when the pre-create `git fetch origin` failed. Worktree creation continues either way; staleness fields are omitted when true.',
							),
							'fetch_error'               => array(
								'type'        => 'string',
								'description' => 'Present only when fetch_failed=true. Trimmed error output from the failing fetch.',
							),
							'stale_commits_behind'      => array(
								'type'        => 'integer',
								'description' => 'For the existing-local-branch path, how many commits the worktree branch is behind its configured upstream. Omitted when no upstream is configured.',
							),
							'upstream'                  => array(
								'type'        => 'string',
								'description' => 'Paired with stale_commits_behind: the upstream ref label (e.g. `origin/fix/foo`).',
							),
							'base_stale_commits_behind' => array(
								'type'        => 'integer',
								'description' => 'For the new-branch path cut from a local base ref: how many commits that local base is behind its origin counterpart at fetch time.',
							),
							'base_upstream'             => array(
								'type'        => 'string',
								'description' => 'Paired with base_stale_commits_behind: the origin ref the local base was compared against (e.g. `origin/main`).',
							),
							'gate_threshold'            => array(
								'type'        => 'integer',
								'description' => 'Echo of the staleness threshold (in commits) that was evaluated. Present whenever the gate ran (i.e. `allow_stale` was false and fetch succeeded).',
							),
							'disk_budget'               => array(
								'type'        => 'object',
								'description' => 'Pre-create disk-budget report: free bytes/GiB, worktree count, thresholds, status, warnings, and force override state.',
							),
							'rebase_attempted'          => array(
								'type'        => 'boolean',
								'description' => 'Set when `rebase_base=true` AND there was meaningful staleness to rebase over. Absent when rebase was not requested or there was nothing to do.',
							),
							'rebase_target'             => array(
								'type'        => 'string',
								'description' => 'Paired with `rebase_attempted`: the ref the worktree was rebased onto (e.g. `@{upstream}` or `origin/main`).',
							),
							'rebase_succeeded'          => array(
								'type'        => 'boolean',
								'description' => 'Paired with `rebase_attempted`: true when the rebase landed cleanly, false when it hit conflicts and was aborted.',
							),
							'rebase_error'              => array(
								'type'        => 'string',
								'description' => 'Present only when `rebase_succeeded=false`. Trimmed error output from the failing rebase.',
							),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeAdd' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-refresh-context',
				array(
					'label'               => 'Refresh Worktree Context',
					'description'         => 'Re-read the originating site\'s agent memory and rewrite the injected context files (`.claude/CLAUDE.local.md`, `.opencode/AGENTS.local.md`) in an existing worktree. Must be run from the site that created the worktree — cross-machine refresh is not supported.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'handle' => array(
								'type'        => 'string',
								'description' => 'Worktree handle (`<repo>@<branch-slug>`).',
							),
						),
						'required'   => array( 'handle' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'handle'       => array( 'type' => 'string' ),
							'path'         => array( 'type' => 'string' ),
							'written'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'exclude_path' => array( 'type' => 'string' ),
							'metadata'     => array( 'type' => 'object' ),
							'message'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeRefreshContext' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-finalize',
				array(
					'label'               => 'Finalize Workspace Worktree',
					'description'         => 'Attach lifecycle metadata to a worktree after a coding-agent session opens a PR, completes, or marks the worktree cleanup-eligible. This metadata is a cleanup signal only; dirty/unpushed safety gates still apply.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'handle' => array(
								'type'        => 'string',
								'description' => 'Worktree handle (`<repo>@<branch-slug>`).',
							),
							'state'  => array(
								'type'        => 'string',
								'description' => 'Lifecycle state: active, pr_opened, merged, closed, abandoned, cleanup_eligible.',
							),
							'pr'     => array(
								'type'        => 'string',
								'description' => 'Optional GitHub PR URL or number.',
							),
						),
						'required'   => array( 'handle', 'state' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'handle'          => array( 'type' => 'string' ),
							'path'            => array( 'type' => 'string' ),
							'lifecycle_state' => array( 'type' => 'string' ),
							'metadata'        => array( 'type' => 'object' ),
							'message'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeFinalize' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-hygiene-report',
				array(
					'label'               => 'Workspace Hygiene Report',
					'description'         => 'Build a non-destructive workspace hygiene report with disk, size, worktree, and local cleanup dry-run summaries.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'include_cleanup' => array(
								'type'        => 'boolean',
								'description' => 'Include a local-only worktree cleanup dry-run summary. Default true.',
							),
							'include_sizes'   => array(
								'type'        => 'boolean',
								'description' => 'Include best-effort top-level workspace size data. Default true.',
							),
							'include_worktree_status' => array(
								'type'        => 'boolean',
								'description' => 'Include full per-worktree git status. Default false for huge-workspace safety.',
							),
							'refresh_inventory' => array(
								'type'        => 'boolean',
								'description' => 'Refresh the DB-backed worktree inventory before reporting freshness. Default false.',
							),
							'size_limit'      => array(
								'type'        => 'integer',
								'description' => 'Maximum top-level workspace entries to size. Default 1000.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'                   => array( 'type' => 'boolean' ),
							'generated_at'              => array( 'type' => 'string' ),
							'workspace_path'            => array( 'type' => 'string' ),
							'destructive'               => array( 'type' => 'boolean' ),
							'size'                      => array( 'type' => 'object' ),
							'disk'                      => array( 'type' => 'object' ),
							'inventory'                 => array( 'type' => 'object' ),
							'worktrees'                 => array( 'type' => 'object' ),
							'worktree_status_mode'      => array( 'type' => 'string' ),
							'top_repos_by_worktrees'    => array( 'type' => 'array' ),
							'top_repos_by_size'         => array( 'type' => 'array' ),
							'locks'                     => array( 'type' => 'object' ),
							'cleanup'                   => array( 'type' => 'object' ),
							'suggested_cleanup_command' => array( 'type' => 'string' ),
							'notes'                     => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'workspaceHygieneReport' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-inventory-refresh',
				array(
					'label'               => 'Refresh Worktree Inventory',
					'description'         => 'Reconcile the DB-backed worktree inventory from the current filesystem/git worktree view. Current rows are upserted; stale known rows are marked missing_path.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'refreshed_at'   => array( 'type' => 'string' ),
							'upserted'       => array( 'type' => 'array' ),
							'marked_missing' => array( 'type' => 'array' ),
							'summary'        => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeInventoryRefresh' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-cleanup-run',
				array(
					'label'               => 'Schedule Workspace Cleanup Run',
					'description'         => 'Schedule a background workspace cleanup system task. Review/dry-run commands are separate synchronous abilities.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'mode'       => array(
								'type'        => 'string',
								'description' => 'Cleanup mode: inventory, artifacts, retention, or emergency.',
							),
							'force'      => array(
								'type'        => 'boolean',
								'description' => 'Forward force=true to cleanup tasks that support it.',
							),
							'dry_run'    => array(
								'type'        => 'boolean',
								'description' => 'Rejected for background cleanup scheduling; use review abilities for dry-runs.',
							),
							'older_than' => array(
								'type'        => 'string',
								'description' => 'Optional worktree retention age gate such as 14d.',
							),
							'source'     => array(
								'type'        => 'string',
								'description' => 'Caller source marker.',
							),
							'user_id'    => array( 'type' => 'integer' ),
							'agent_id'   => array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'state'     => array( 'type' => 'string' ),
							'run_id'    => array( 'type' => 'string' ),
							'job_id'    => array( 'type' => 'integer' ),
							'mode'      => array( 'type' => 'string' ),
							'task_type' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'workspaceCleanupRun' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-list',
				array(
					'label'               => 'List Workspace Worktrees',
					'description'         => 'List all worktrees in the workspace (optionally filtered by repo and lifecycle state). Defaults to a fast cheap-inventory listing on large workspaces; opt in to per-worktree git status and disk probes via include_status / include_disk.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Optional repo name to limit the list.',
							),
							'state' => array(
								'type'        => 'string',
								'description' => 'Optional lifecycle state filter.',
							),
							'include_status' => array(
								'type'        => 'boolean',
								'description' => 'Run `git status --porcelain` per worktree to populate the dirty count. Default false (cheap listing). Expensive on large workspaces.',
							),
							'include_disk' => array(
								'type'        => 'boolean',
								'description' => 'Run size and artifact `du` probes per worktree. Default false (cheap listing). Expensive on large workspaces.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'fields_skipped' => array(
								'type'        => 'array',
								'description' => 'Probe groups skipped on this listing (e.g. "status", "disk"). Empty when full data is requested.',
								'items'       => array( 'type' => 'string' ),
							),
							'worktrees'      => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'handle'          => array( 'type' => 'string' ),
										'repo'            => array( 'type' => 'string' ),
										'is_worktree'     => array( 'type' => 'boolean' ),
										'is_primary'      => array( 'type' => 'boolean' ),
										'external'        => array( 'type' => 'boolean' ),
										'branch_slug'     => array( 'type' => array( 'string', 'null' ) ),
										'branch'          => array( 'type' => array( 'string', 'null' ) ),
										'head'            => array( 'type' => 'string' ),
										'path'            => array( 'type' => 'string' ),
										'dirty'           => array( 'type' => array( 'integer', 'null' ) ),
										'created_at'      => array( 'type' => array( 'string', 'null' ) ),
										'lifecycle_state' => array( 'type' => array( 'string', 'null' ) ),
										'pr_url'          => array( 'type' => array( 'string', 'null' ) ),
										'pr_number'       => array( 'type' => array( 'integer', 'null' ) ),
										'last_seen_at'    => array( 'type' => array( 'string', 'null' ) ),
										'liveness'        => array(
											'type'        => 'string',
											'description' => 'Owner/agent liveness: live, stopped, stale, or unknown. Distinct from lifecycle_state.',
										),
										'liveness_reason' => array(
											'type'        => 'string',
											'description' => 'Reason code for the liveness classification (e.g. heartbeat_fresh, heartbeat_stale, lifecycle_pr_opened, metadata_missing).',
										),
										'heartbeat_age_seconds' => array( 'type' => array( 'integer', 'null' ) ),
										'owner'           => array(
											'type'       => 'object',
											'description' => 'Owner snapshot recorded at worktree creation. Unknown-safe defaults: site, agent, user default to the literal string "unknown".',
											'properties' => array(
												'site'     => array( 'type' => 'string' ),
												'site_url' => array( 'type' => array( 'string', 'null' ) ),
												'agent'    => array( 'type' => 'string' ),
												'user'     => array( 'type' => 'string' ),
											),
										),
										'session'         => array(
											'type'       => 'object',
											'description' => 'Captured session identifiers (kimaki/opencode). Fields default to null when the corresponding env was not present at worktree creation.',
											'properties' => array(
												'primary_id'         => array( 'type' => array( 'string', 'null' ) ),
												'kimaki_session_id'  => array( 'type' => array( 'string', 'null' ) ),
												'kimaki_thread_id'   => array( 'type' => array( 'string', 'null' ) ),
												'kimaki_thread_url'  => array( 'type' => array( 'string', 'null' ) ),
												'opencode_session_id' => array( 'type' => array( 'string', 'null' ) ),
												'opencode_run_id'    => array( 'type' => array( 'string', 'null' ) ),
											),
										),
										'task'            => array(
											'type'       => array( 'object', 'null' ),
											'description' => 'Optional task/issue reference recorded at creation, when supplied via input or DATAMACHINE_TASK_URL/DATAMACHINE_TASK_REF env.',
										),
										'last_touched_at' => array( 'type' => array( 'string', 'null' ) ),
										'age_days'        => array( 'type' => array( 'integer', 'null' ) ),
										'size_bytes'      => array( 'type' => array( 'integer', 'null' ) ),
										'artifact_size_bytes' => array( 'type' => 'integer' ),
										'artifacts'       => array( 'type' => 'array' ),
										'stale_reason'    => array( 'type' => array( 'string', 'null' ) ),
										'metadata'        => array( 'type' => array( 'object', 'null' ) ),
										'fields_skipped'  => array(
											'type'  => 'array',
											'items' => array( 'type' => 'string' ),
										),
									),
								),
							),
							'duplicates' => array(
								'type'       => 'array',
								'description' => 'Groups of worktrees sharing a task_url, task_ref, pr_url, or pr_repo#pr_number. Reported only — never used to drive deletions.',
								'items'      => array(
									'type'       => 'object',
									'properties' => array(
										'kind'    => array( 'type' => 'string' ),
										'key'     => array( 'type' => 'string' ),
										'handles' => array(
											'type'  => 'array',
											'items' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeList' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-remove',
				array(
					'label'               => 'Remove Workspace Worktree',
					'description'         => 'Remove a worktree by repo and branch (or branch slug). Refuses if the worktree has uncommitted changes unless `force` is true.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'   => array(
								'type'        => 'string',
								'description' => 'Primary repo name.',
							),
							'branch' => array(
								'type'        => 'string',
								'description' => 'Branch (or slug) of the worktree.',
							),
							'force'  => array(
								'type'        => 'boolean',
								'description' => 'Force removal even if dirty (default false).',
							),
						),
						'required'   => array( 'repo', 'branch' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'handle'  => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeRemove' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-prune',
				array(
					'label'               => 'Prune Workspace Worktrees',
					'description'         => 'Run git worktree prune across all primary checkouts to drop stale registry entries.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pruned'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'worktreePrune' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-cleanup',
				array(
					'label'               => 'Cleanup Merged Worktrees',
					'description'         => 'Remove worktrees whose branch is merged to the remote default branch. Detects merge via `upstream: gone` (remote branch deleted, e.g. by GitHub auto-delete on PR merge) or closed+merged PR via the GitHub API. Deletes the local branch and prunes the git registry after removal. Dry-run supported.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'     => array(
								'type'        => 'boolean',
								'description' => 'If true, return the plan without removing anything.',
							),
							'force'       => array(
								'type'        => 'boolean',
								'description' => 'If true, ignore dirty working-tree safety check.',
							),
							'skip_github' => array(
								'type'        => 'boolean',
								'description' => 'If true, rely solely on the local upstream-gone signal and skip GitHub API lookup.',
							),
							'inventory_only' => array(
								'type'        => 'boolean',
								'description' => 'If true, build a dry-run review from cheap top-level inventory and explicit lifecycle cleanup signals only. Avoids full git worktree/status scans and GitHub lookups.',
							),
							'apply_plan'  => array(
								'type'        => 'object',
								'description' => 'Decoded cleanup dry-run report to apply after revalidating every candidate.',
							),
							'older_than'  => array(
								'type'        => 'string',
								'description' => 'Optional candidate age filter such as 7d, 24h, 30m, or 60s. Uses lifecycle created_at metadata only.',
							),
							'sort'        => array(
								'type'        => 'string',
								'description' => 'Optional cleanup candidate sort: size or age.',
							),
							'include_repaired_metadata' => array(
								'type'        => 'boolean',
								'description' => 'If true, include repaired metadata rows as operator-approved removal candidates after full safety revalidation.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'dry_run'    => array( 'type' => 'boolean' ),
							'candidates' => array( 'type' => 'array' ),
							'removed'    => array( 'type' => 'array' ),
							'skipped'    => array( 'type' => 'array' ),
							'summary'    => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeCleanup' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-reconcile-metadata',
				array(
					'label'               => 'Reconcile Worktree Metadata',
					'description'         => 'Build or apply a reviewed, non-destructive lifecycle metadata plan for unmanaged workspace worktrees. Never removes worktrees and never infers cleanup eligibility.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'    => array(
								'type'        => 'boolean',
								'description' => 'If true, return a review plan without writing metadata.',
							),
							'apply_plan' => array(
								'type'        => 'object',
								'description' => 'Decoded dry-run plan to apply after exact handle/path/repo/branch revalidation.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'dry_run'   => array( 'type' => 'boolean' ),
							'applied'   => array( 'type' => 'boolean' ),
							'proposals' => array( 'type' => 'array' ),
							'written'   => array( 'type' => 'array' ),
							'skipped'   => array( 'type' => 'array' ),
							'summary'   => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeReconcileMetadata' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-cleanup-artifacts',
				array(
					'label'               => 'Cleanup Worktree Artifacts',
					'description'         => 'Remove profile-derived, reconstructable artifact directories inside workspace worktrees. Dry-run defaults to bounded inventory mode (limit=' . Workspace::ARTIFACT_CLEANUP_DEFAULT_LIMIT . ', cheap top-level scan, no per-worktree git probes) so huge workspaces stay responsive. Requires a dry-run plan before deletion and revalidates exact paths before applying.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'    => array(
								'type'        => 'boolean',
								'description' => 'If true, return the artifact cleanup plan without deleting anything.',
							),
							'force'      => array(
								'type'        => 'boolean',
								'description' => 'If true, allow artifact cleanup in dirty or unpushed worktrees. Active plugin/theme symlink targets remain protected.',
							),
							'apply_plan' => array(
								'type'        => 'object',
								'description' => 'Decoded artifact cleanup dry-run report to apply after revalidating every worktree and artifact path.',
							),
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Maximum worktrees to scan in a dry-run page. Defaults to ' . Workspace::ARTIFACT_CLEANUP_DEFAULT_LIMIT . '. Use 0 to disable the cap (still bounded by exhaustive=false unless you also pass exhaustive=true).',
							),
							'offset'     => array(
								'type'        => 'integer',
								'description' => 'Pagination offset (0-indexed) into the inventory ordering. Combine with the previous response\'s pagination.next_offset to walk huge workspaces in pages.',
							),
							'exhaustive' => array(
								'type'        => 'boolean',
								'description' => 'If true, scan every worktree (no limit) AND run per-worktree git status / unpushed-commit safety probes. Slow on huge workspaces; use for one-shot full audits.',
							),
							'safety_probes' => array(
								'type'        => 'boolean',
								'description' => 'If true, run per-worktree git status + unpushed-commit safety probes during the dry-run. Default false in bounded mode (apply paths revalidate planned rows). Implied by exhaustive=true and apply_plan.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'dry_run'    => array( 'type' => 'boolean' ),
							'candidates' => array( 'type' => 'array' ),
							'removed'    => array( 'type' => 'array' ),
							'skipped'    => array( 'type' => 'array' ),
							'summary'    => array( 'type' => 'object' ),
							'pagination' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeCleanupArtifacts' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-emergency-cleanup',
				array(
					'label'               => 'Emergency Cleanup Worktrees',
					'description'         => 'Build or apply a disk-pressure emergency cleanup plan using cheap workspace inventory first. The plan prioritizes artifact/cache deletion and oldest finalized or cleanup-eligible worktrees without running full git status or GitHub checks before initial output.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'    => array(
								'type'        => 'boolean',
								'description' => 'If true, return the emergency plan without deleting anything. Emergency cleanup defaults to dry-run unless apply_plan is provided.',
							),
							'force'      => array(
								'type'        => 'boolean',
								'description' => 'If true during apply-plan, allow dirty or unpushed worktree deletion after human review. Primary checkouts remain protected.',
							),
							'apply_plan' => array(
								'type'        => 'object',
								'description' => 'Decoded emergency cleanup dry-run report to apply after current-state revalidation.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'mode'                => array( 'type' => 'string' ),
							'dry_run'             => array( 'type' => 'boolean' ),
							'artifact_candidates' => array( 'type' => 'array' ),
							'worktree_candidates' => array( 'type' => 'array' ),
							'removed_artifacts'   => array( 'type' => 'array' ),
							'removed_worktrees'   => array( 'type' => 'array' ),
							'skipped'             => array( 'type' => 'array' ),
							'summary'             => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeEmergencyCleanup' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-worktree-bounded-cleanup-eligible-apply',
				array(
					'label'               => 'Bounded Cleanup Apply for Obvious Worktrees',
					'description'         => 'Apply only worktrees with explicit lifecycle cleanup_eligible metadata in a bounded batch using cheap workspace inventory. Can explicitly include repaired metadata rows for operator-approved cleanup. Revalidates dirty/unpushed/missing-metadata/external/primary safety gates immediately before each removal. Optionally schedules per-candidate chunk jobs for resumable async apply. Produces evidence with processed/removed/skipped/bytes_reclaimed/continuation.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'    => array(
								'type'        => 'boolean',
								'description' => 'Preview the bounded batch without removing anything.',
							),
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Maximum candidates to attempt this call (default 25, hard ceiling 200).',
							),
							'older_than' => array(
								'type'        => 'string',
								'description' => 'Restrict candidates to lifecycle created_at older than the duration (e.g. 7d, 24h).',
							),
							'sort'       => array(
								'type'        => 'string',
								'description' => 'Candidate ordering before bounding: size or age (default age).',
							),
							'force'      => array(
								'type'        => 'boolean',
								'description' => 'Allow apply on dirty worktrees. Unpushed-commit gate is never overridden.',
							),
							'via_jobs'   => array(
								'type'        => 'boolean',
								'description' => 'Schedule each candidate as a single-row worktree_cleanup_chunk job for resumable async apply.',
							),
							'include_repaired_metadata' => array(
								'type'        => 'boolean',
								'description' => 'Also include repaired metadata rows. Requires explicit opt-in and still runs fresh safety probes before removal.',
							),
							'source'     => array(
								'type'        => 'string',
								'description' => 'Caller source marker recorded in evidence.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'mode'            => array( 'type' => 'string' ),
							'dry_run'         => array( 'type' => 'boolean' ),
							'destructive'     => array( 'type' => 'boolean' ),
							'job_backed'      => array( 'type' => 'boolean' ),
							'workspace_path'  => array( 'type' => 'string' ),
							'generated_at'    => array( 'type' => 'string' ),
							'candidates'      => array( 'type' => 'array' ),
							'removed'         => array( 'type' => 'array' ),
							'skipped'         => array( 'type' => 'array' ),
							'summary'         => array( 'type' => 'object' ),
							'continuation'    => array( 'type' => 'object' ),
							'evidence'        => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeBoundedCleanupEligibleApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-cleanup-plan',
				array(
					'label'               => 'Build DB-backed Workspace Cleanup Plan',
					'description'         => 'Freeze a non-destructive cleanup plan into DMC cleanup run/item database rows and return a stable run_id. File plans are an escape hatch on lower-level commands only.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'emit_chunks'            => array( 'type' => 'boolean' ),
							'chunk_size'             => array( 'type' => 'integer' ),
							'include_artifacts'      => array( 'type' => 'boolean' ),
							'include_worktrees'      => array( 'type' => 'boolean' ),
							'include_resolvers'      => array( 'type' => 'boolean' ),
							'force_artifact_cleanup' => array( 'type' => 'boolean' ),
							'worktree_older_than'    => array( 'type' => 'string' ),
							'worktree_sort'          => array( 'type' => 'string' ),
							'plan'                   => array( 'type' => 'object' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'mode'    => array( 'type' => 'string' ),
							'plan_id' => array( 'type' => 'string' ),
							'rows'    => array( 'type' => 'object' ),
							'chunks'  => array( 'type' => 'array' ),
							'summary' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'workspaceCleanupPlan' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-cleanup-apply',
				array(
					'label'               => 'Apply Workspace Cleanup Run',
					'description'         => 'Apply pending rows from a DB-backed cleanup run by run_id after current-state revalidation.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'run_id' ),
						'properties' => array(
							'run_id' => array( 'type' => 'string' ),
							'force'  => array( 'type' => 'boolean' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'workspaceCleanupApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			foreach ( array( 'status', 'evidence', 'resume', 'cancel' ) as $cleanup_operation ) {
				wp_register_ability(
					'datamachine/workspace-cleanup-' . $cleanup_operation,
					array(
						'label'               => 'Workspace Cleanup ' . ucfirst( $cleanup_operation ),
						'description'         => 'Operate on a DB-backed workspace cleanup run by run_id.',
						'category'            => 'datamachine-code-workspace',
						'input_schema'        => array(
							'type'       => 'object',
							'required'   => array( 'run_id' ),
							'properties' => array(
								'run_id' => array( 'type' => 'string' ),
								'force'  => array( 'type' => 'boolean' ),
							),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( self::class, 'workspaceCleanup' . ucfirst( $cleanup_operation ) ),
						'permission_callback' => fn() => PermissionHelper::can_manage(),
						'meta'                => array( 'show_in_rest' => false ),
					)
				);
			}
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// =========================================================================
	// Ability callbacks
	// =========================================================================

	/**
	 * Get workspace path, optionally ensuring the directory exists.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function getPath( array $input ): array|\WP_Error {
		$workspace = new Workspace();

		if ( ! empty( $input['ensure'] ) ) {
			$result = $workspace->ensure_exists();
			return array(
				'success' => $result['success'],
				'path'    => $workspace->get_path(),
				'exists'  => $result['success'],
				'created' => $result['created'] ?? false,
				'message' => $result['message'] ?? null,
			);
		}

		return array(
			'success' => true,
			'path'    => $workspace->get_path(),
			'exists'  => is_dir( $workspace->get_path() ),
		);
	}

	/**
	 * List workspace repos.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function listRepos( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$workspace = new Workspace();
		return $workspace->list_repos();
	}

	/**
	 * Show detailed repo info.
	 *
	 * @param array $input Input parameters with 'name'.
	 * @return array Result.
	 */
	public static function showRepo( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->show_repo( $input['name'] ?? '' );
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', 'path', optional 'max_size', 'offset', 'limit'.
	 * @return array Result.
	 */
	public static function readFile( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$reader    = new WorkspaceReader( $workspace );

		return $reader->read_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			isset( $input['max_size'] ) ? (int) $input['max_size'] : Workspace::MAX_READ_SIZE,
			isset( $input['offset'] ) ? (int) $input['offset'] : null,
			isset( $input['limit'] ) ? (int) $input['limit'] : null
		);
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', optional 'path'.
	 * @return array Result.
	 */
	public static function listDirectory( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$reader    = new WorkspaceReader( $workspace );

		return $reader->list_directory(
			$input['repo'] ?? '',
			$input['path'] ?? null
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * @param array $input Input parameters with 'url', optional 'name'.
	 * @return array Result.
	 */
	public static function cloneRepo( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->clone_repo(
			$input['url'] ?? '',
			$input['name'] ?? null
		);
	}

	/**
	 * Adopt an existing primary checkout already under the workspace root.
	 *
	 * @param array $input Input parameters with 'path', optional 'name'.
	 * @return array Result.
	 */
	public static function adoptRepo( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->adopt_repo(
			$input['path'] ?? '',
			$input['name'] ?? null
		);
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * @param array $input Input parameters with 'name'.
	 * @return array Result.
	 */
	public static function removeRepo( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->remove_repo( $input['name'] ?? '' );
	}

	/**
	 * Write (create or overwrite) a file in a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', 'path', 'content'.
	 * @return array Result.
	 */
	public static function writeFile( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$writer    = new WorkspaceWriter( $workspace );

		return $writer->write_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			$input['content'] ?? ''
		);
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * @param array $input Input parameters with 'repo', 'path', 'old_string', 'new_string', optional 'replace_all'.
	 * @return array Result.
	 */
	public static function editFile( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$writer    = new WorkspaceWriter( $workspace );

		return $writer->edit_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			$input['old_string'] ?? '',
			$input['new_string'] ?? '',
			! empty( $input['replace_all'] )
		);
	}

	/**
	 * Apply a unified diff to a workspace repo.
	 *
	 * @param array $input Input parameters with 'repo', 'patch', optional 'allow_primary_mutation'.
	 * @return array Result.
	 */
	public static function applyPatch( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$writer    = new WorkspaceWriter( $workspace );

		return $writer->apply_patch(
			$input['repo'] ?? '',
			$input['patch'] ?? '',
			! empty( $input['allow_primary_mutation'] )
		);
	}

	/**
	 * Get git status details for a workspace repository.
	 *
	 * @param array $input Input parameters with 'name'.
	 * @return array
	 */
	public static function gitStatus( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_status( $input['name'] ?? '' );
	}

	/**
	 * Pull latest changes for a workspace repository.
	 *
	 * @param array $input Input parameters with 'name', optional 'allow_dirty'.
	 * @return array
	 */
	public static function gitPull( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_pull(
			$input['name'] ?? '',
			! empty( $input['allow_dirty'] ),
			! empty( $input['allow_primary_mutation'] )
		);
	}

	/**
	 * Stage paths in a workspace repository.
	 *
	 * @param array $input Input parameters with 'name', 'paths'.
	 * @return array
	 */
	public static function gitAdd( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$paths     = $input['paths'] ?? array();

		if ( ! is_array( $paths ) ) {
			$paths = array();
		}

		return $workspace->git_add( $input['name'] ?? '', $paths, ! empty( $input['allow_primary_mutation'] ) );
	}

	/**
	 * Delete a tracked or untracked path from a workspace repository.
	 *
	 * @param array $input Input parameters with 'repo', 'path', optional 'recursive', 'allow_primary_mutation'.
	 * @return array
	 */
	public static function deletePath( array $input ): array|\WP_Error {
		$workspace = new Workspace();

		return $workspace->delete_path(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			! empty( $input['recursive'] ),
			! empty( $input['allow_primary_mutation'] )
		);
	}

	/**
	 * Commit staged changes in a workspace repository.
	 *
	 * @param array $input Input parameters with 'name', 'message'.
	 * @return array
	 */
	public static function gitCommit( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_commit(
			$input['name'] ?? '',
			$input['message'] ?? '',
			! empty( $input['allow_primary_mutation'] )
		);
	}

	/**
	 * Push commits for a workspace repository.
	 *
	 * @param array $input Input parameters with 'name', optional 'remote', 'branch'.
	 * @return array
	 */
	public static function gitPush( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_push(
			$input['name'] ?? '',
			$input['remote'] ?? 'origin',
			$input['branch'] ?? null,
			! empty( $input['allow_primary_mutation'] )
		);
	}

	/**
	 * Add a worktree for a branch.
	 *
	 * @param array $input Input parameters with 'repo', 'branch', optional 'from'.
	 * @return array
	 */
	public static function worktreeAdd( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		// Default inject_context=true; only false when explicitly provided.
		$inject_context = array_key_exists( 'inject_context', $input ) ? (bool) $input['inject_context'] : true;
		// Default bootstrap=true; only false when explicitly provided.
		$bootstrap = array_key_exists( 'bootstrap', $input ) ? (bool) $input['bootstrap'] : true;
		// Default allow_stale=false (gate enforced); only true when explicitly opted in.
		$allow_stale = array_key_exists( 'allow_stale', $input ) ? (bool) $input['allow_stale'] : false;
		// Default rebase_base=false; only true when explicitly requested.
		$rebase_base = array_key_exists( 'rebase_base', $input ) ? (bool) $input['rebase_base'] : false;
		$force       = ! empty( $input['force'] );
		$task        = array();
		if ( isset( $input['task_url'] ) && '' !== trim( (string) $input['task_url'] ) ) {
			$task['task_url'] = (string) $input['task_url'];
		}
		if ( isset( $input['task_ref'] ) && '' !== trim( (string) $input['task_ref'] ) ) {
			$task['task_ref'] = (string) $input['task_ref'];
		}
		return $workspace->worktree_add(
			$input['repo'] ?? '',
			$input['branch'] ?? '',
			$input['from'] ?? null,
			$inject_context,
			$bootstrap,
			$allow_stale,
			$rebase_base,
			$force,
			$task
		);
	}

	/**
	 * Refresh a worktree's injected context from the originating site.
	 *
	 * @param array $input Input parameters with 'handle'.
	 * @return array|\WP_Error
	 */
	public static function worktreeRefreshContext( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_refresh_context( $input['handle'] ?? '' );
	}

	/**
	 * Attach lifecycle metadata to a worktree.
	 *
	 * @param array $input Input parameters with handle, state, optional pr.
	 * @return array|\WP_Error
	 */
	public static function worktreeFinalize( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_finalize(
			$input['handle'] ?? '',
			$input['state'] ?? '',
			isset( $input['pr'] ) ? (string) $input['pr'] : null
		);
	}

	/**
	 * List worktrees in the workspace.
	 *
	 * @param array $input Input parameters with optional 'repo'.
	 * @return array
	 */
	public static function worktreeList( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$repo      = isset( $input['repo'] ) && '' !== trim( (string) $input['repo'] )
			? (string) $input['repo']
			: null;
		$state     = isset( $input['state'] ) && '' !== trim( (string) $input['state'] )
			? (string) $input['state']
			: null;

		// Default to the cheap listing on the ability surface so MCP/REST/CLI callers
		// don't pay the per-worktree git status + du cost on huge workspaces.
		// Internal PHP callers can still call worktree_list() directly with full probes.
		$opts = array(
			'include_status' => array_key_exists( 'include_status', $input ) ? (bool) $input['include_status'] : false,
			'include_disk'   => array_key_exists( 'include_disk', $input ) ? (bool) $input['include_disk'] : false,
		);

		return $workspace->worktree_list( $repo, $state, $opts );
	}

	/**
	 * Build a non-destructive workspace hygiene report.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public static function workspaceHygieneReport( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array();
		if ( array_key_exists( 'include_cleanup', $input ) ) {
			$opts['include_cleanup'] = (bool) $input['include_cleanup'];
		}
		if ( array_key_exists( 'include_sizes', $input ) ) {
			$opts['include_sizes'] = (bool) $input['include_sizes'];
		}
		if ( array_key_exists( 'include_worktree_status', $input ) ) {
			$opts['include_worktree_status'] = (bool) $input['include_worktree_status'];
		}
		if ( array_key_exists( 'refresh_inventory', $input ) ) {
			$opts['refresh_inventory'] = (bool) $input['refresh_inventory'];
		}
		if ( isset( $input['size_limit'] ) ) {
			$opts['size_limit'] = (int) $input['size_limit'];
		}

		return $workspace->workspace_hygiene_report( $opts );
	}

	/**
	 * Refresh DB-backed worktree inventory from the filesystem/git view.
	 *
	 * @param array $input Unused input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeInventoryRefresh( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$workspace = new Workspace();
		return $workspace->worktree_inventory_refresh();
	}

	/**
	 * Schedule a background workspace cleanup system task.
	 *
	 * @param array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupRun( array $input ): array|\WP_Error {
		if ( ! empty( $input['dry_run'] ) ) {
			return new \WP_Error( 'workspace_cleanup_run_no_dry_run', 'Background cleanup scheduling does not accept dry_run. Use the synchronous workspace cleanup review abilities instead.', array( 'status' => 400 ) );
		}

		$mode = strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) ( $input['mode'] ?? 'retention' ) ) );
		$map  = array(
			'inventory' => array(
				'task_type' => 'workspace_hygiene_report',
				'params'    => array(
					'include_cleanup'         => true,
					'include_sizes'           => true,
					'include_worktree_status' => false,
					'size_limit'              => 200,
				),
			),
			'artifacts' => array(
				'task_type' => 'workspace_retention_cleanup',
				'params'    => array(
					'dry_run'          => false,
					'artifact_cleanup' => true,
					'worktree_cleanup' => false,
					'skip_github'      => true,
				),
			),
			'retention' => array(
				'task_type' => 'workspace_retention_cleanup',
				'params'    => array(
					'dry_run'              => false,
					'artifact_cleanup'     => true,
					'worktree_cleanup'     => true,
					'skip_github'          => true,
					'worktree_older_than'  => '14d',
				),
			),
			'emergency' => array(
				'task_type' => 'workspace_disk_emergency_cleanup',
				'params'    => array(
					'artifact_chunk_size' => 10,
				),
			),
		);

		if ( ! isset( $map[ $mode ] ) ) {
			return new \WP_Error( 'unknown_workspace_cleanup_mode', sprintf( 'Unknown cleanup mode: %s.', $mode ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskScheduler' ) ) {
			return new \WP_Error( 'task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable.', array( 'status' => 500 ) );
		}

		$task_type = (string) $map[ $mode ]['task_type'];
		$params    = (array) $map[ $mode ]['params'];
		$params['source'] = (string) ( $input['source'] ?? 'workspace_cleanup_ability' );

		if ( isset( $input['force'] ) ) {
			$params['force'] = (bool) $input['force'];
		}
		if ( isset( $input['older_than'] ) && '' !== trim( (string) $input['older_than'] ) ) {
			$params['worktree_older_than'] = trim( (string) $input['older_than'] );
		}

		$context = array();
		if ( isset( $input['user_id'] ) ) {
			$context['user_id'] = (int) $input['user_id'];
		}
		if ( isset( $input['agent_id'] ) ) {
			$context['agent_id'] = (int) $input['agent_id'];
		}

		$job_id = \DataMachine\Engine\Tasks\TaskScheduler::schedule( $task_type, $params, $context );
		if ( false === $job_id ) {
			return new \WP_Error( 'workspace_cleanup_schedule_failed', 'Failed to schedule workspace cleanup task.', array( 'status' => 500 ) );
		}

		return array(
			'success'   => true,
			'state'     => 'jobs_queued',
			'run_id'    => 'cleanup-run-' . (int) $job_id,
			'job_id'    => (int) $job_id,
			'mode'      => $mode,
			'task_type' => $task_type,
		);
	}

	/**
	 * Remove a worktree.
	 *
	 * @param array $input Input parameters with 'repo', 'branch', optional 'force'.
	 * @return array
	 */
	public static function worktreeRemove( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_remove(
			$input['repo'] ?? '',
			$input['branch'] ?? '',
			! empty( $input['force'] )
		);
	}

	/**
	 * Prune stale worktree registry entries.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public static function worktreePrune( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$workspace = new Workspace();
		return $workspace->worktree_prune();
	}

	/**
	 * Remove merged worktrees across all primary checkouts.
	 *
	 * @param array $input Input parameters (dry_run, force, skip_github, inventory_only, apply_plan, older_than, sort).
	 * @return array
	 */
	public static function worktreeCleanup( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run'                 => ! empty( $input['dry_run'] ),
			'force'                   => ! empty( $input['force'] ),
			'skip_github'             => ! empty( $input['skip_github'] ),
			'inventory_only'          => ! empty( $input['inventory_only'] ),
			'include_repaired_metadata' => ! empty( $input['include_repaired_metadata'] ),
		);
		if ( isset( $input['apply_plan'] ) && is_array( $input['apply_plan'] ) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}
		if ( isset( $input['older_than'] ) && '' !== trim( (string) $input['older_than'] ) ) {
			$opts['older_than'] = trim( (string) $input['older_than'] );
		}
		if ( isset( $input['sort'] ) && '' !== trim( (string) $input['sort'] ) ) {
			$opts['sort'] = trim( (string) $input['sort'] );
		}
		if ( isset( $input['progress_callback'] ) && is_callable( $input['progress_callback'] ) ) {
			$opts['progress_callback'] = $input['progress_callback'];
		}

		return $workspace->worktree_cleanup_merged( $opts );
	}

	/**
	 * Reconcile unmanaged worktree lifecycle metadata.
	 *
	 * @param array $input Input parameters (dry_run, apply_plan).
	 * @return array
	 */
	public static function worktreeReconcileMetadata( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty( $input['dry_run'] ),
			'apply'   => ! empty( $input['apply'] ),
		);
		if ( isset( $input['apply_plan'] ) && is_array( $input['apply_plan'] ) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}

		return $workspace->worktree_reconcile_metadata( $opts );
	}

	/**
	 * Remove profile-derived artifacts inside workspace worktrees.
	 *
	 * @param array $input Input parameters (dry_run, force, apply_plan, limit,
	 *                     offset, exhaustive, safety_probes).
	 * @return array
	 */
	public static function worktreeCleanupArtifacts( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty( $input['dry_run'] ),
			'force'   => ! empty( $input['force'] ),
		);
		if ( isset( $input['apply_plan'] ) && is_array( $input['apply_plan'] ) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}
		if ( array_key_exists( 'limit', $input ) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists( 'offset', $input ) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( array_key_exists( 'exhaustive', $input ) ) {
			$opts['exhaustive'] = (bool) $input['exhaustive'];
		}
		if ( array_key_exists( 'safety_probes', $input ) ) {
			$opts['safety_probes'] = (bool) $input['safety_probes'];
		}

		return $workspace->worktree_cleanup_artifacts( $opts );
	}

	/**
	 * Apply only worktrees with explicit lifecycle cleanup_eligible metadata in a bounded batch.
	 *
	 * @param array $input Input parameters (dry_run, limit, older_than, sort, force, via_jobs, source).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeBoundedCleanupEligibleApply( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run'                 => ! empty( $input['dry_run'] ),
			'force'                   => ! empty( $input['force'] ),
			'via_jobs'                => ! empty( $input['via_jobs'] ),
			'include_repaired_metadata' => ! empty( $input['include_repaired_metadata'] ),
		);
		if ( isset( $input['limit'] ) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( isset( $input['older_than'] ) && '' !== trim( (string) $input['older_than'] ) ) {
			$opts['older_than'] = trim( (string) $input['older_than'] );
		}
		if ( isset( $input['sort'] ) && '' !== trim( (string) $input['sort'] ) ) {
			$opts['sort'] = trim( (string) $input['sort'] );
		}
		if ( isset( $input['source'] ) && '' !== trim( (string) $input['source'] ) ) {
			$opts['source'] = trim( (string) $input['source'] );
		}

		return $workspace->worktree_bounded_cleanup_eligible_apply( $opts );
	}

	/**
	 * Build or apply a disk-pressure emergency cleanup plan.
	 *
	 * @param array $input Input parameters (dry_run, force, apply_plan).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeEmergencyCleanup( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty( $input['dry_run'] ),
			'force'   => ! empty( $input['force'] ),
		);
		if ( isset( $input['apply_plan'] ) && is_array( $input['apply_plan'] ) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}

		return $workspace->worktree_emergency_cleanup( $opts );
	}

	/**
	 * Freeze a cleanup plan and optionally emit bounded chunks.
	 *
	 * @param array $input Input parameters (emit_chunks, chunk_size, include_*).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupPlan( array $input ): array|\WP_Error {
		$opts      = array(
			'force_artifact_cleanup' => ! empty( $input['force_artifact_cleanup'] ),
			'include_resolvers'      => ! empty( $input['include_resolvers'] ),
			'mode'                   => (string) ( $input['mode'] ?? 'cleanup_plan' ),
		);
		foreach ( array( 'include_artifacts', 'include_worktrees' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$opts[ $key ] = (bool) $input[ $key ];
			}
		}
		if ( isset( $input['worktree_older_than'] ) && '' !== trim( (string) $input['worktree_older_than'] ) ) {
			$opts['worktree_older_than'] = trim( (string) $input['worktree_older_than'] );
		}
		if ( isset( $input['worktree_sort'] ) && '' !== trim( (string) $input['worktree_sort'] ) ) {
			$opts['worktree_sort'] = trim( (string) $input['worktree_sort'] );
		}
		if ( isset( $input['plan'] ) && is_array( $input['plan'] ) ) {
			$opts['plan'] = $input['plan'];
		}
		if ( isset( $input['chunk_size'] ) ) {
			$opts['chunk_size'] = (int) $input['chunk_size'];
		}


		$service = new CleanupRunService();
		$plan    = $service->plan( $opts );
		if ( $plan instanceof \WP_Error || empty( $input['emit_chunks'] ) ) {
			return $plan;
		}

		$workspace = new Workspace();
		$chunks    = $workspace->workspace_cleanup_plan_chunks( array_merge( $opts, array( 'plan' => $plan ) ) );
		if ( $chunks instanceof \WP_Error ) {
			return $chunks;
		}
		$chunks['run_id']          = $plan['run_id'] ?? null;
		$chunks['cleanup_storage'] = $plan['cleanup_storage'] ?? array();
		return $chunks;
	}

	/**
	 * Apply a DB-backed cleanup run.
	 *
	 * @param array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupApply( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->apply( (string) ( $input['run_id'] ?? '' ), array( 'force' => ! empty( $input['force'] ) ) );
	}

	/**
	 * Return DB-backed cleanup run status.
	 *
	 * @param array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupStatus( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->status( (string) ( $input['run_id'] ?? '' ) );
	}

	/**
	 * Return DB-backed cleanup run evidence.
	 *
	 * @param array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupEvidence( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->evidence( (string) ( $input['run_id'] ?? '' ) );
	}

	/**
	 * Resume pending cleanup rows.
	 *
	 * @param array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupResume( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->resume( (string) ( $input['run_id'] ?? '' ), array( 'force' => ! empty( $input['force'] ) ) );
	}

	/**
	 * Cancel pending cleanup rows.
	 *
	 * @param array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupCancel( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->cancel( (string) ( $input['run_id'] ?? '' ) );
	}

	/**
	 * Read git log entries for a workspace repository.
	 *
	 * @param array $input Input parameters with 'name', optional 'limit'.
	 * @return array
	 */
	public static function gitLog( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_log(
			$input['name'] ?? '',
			isset( $input['limit'] ) ? (int) $input['limit'] : 20
		);
	}

	/**
	 * Read git diff output for a workspace repository.
	 *
	 * @param array $input Input parameters.
	 * @return array
	 */
	public static function gitDiff( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_diff(
			$input['name'] ?? '',
			$input['from'] ?? null,
			$input['to'] ?? null,
			! empty( $input['staged'] ),
			$input['path'] ?? null
		);
	}
}
