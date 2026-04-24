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
					'description'         => 'Create a git worktree for a branch under `<repo>@<branch-slug>`. Branches are created off the supplied `from` ref (default `origin/HEAD`) when they do not yet exist locally. When `inject_context` is true (default), the originating site\'s agent memory is snapshotted into `.claude/CLAUDE.local.md` and `.opencode/AGENTS.local.md` and added to the worktree\'s per-checkout `info/exclude`. When `bootstrap` is true (default), submodule init + package-manager install + composer install run after creation so the worktree is immediately test/build-ready; set false to create a bare checkout.',
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
								'description' => 'Run detected bootstrap steps (submodule init, package-manager install, composer install) after creating the worktree. Default true. Steps are skipped gracefully when their trigger file or tool is missing. Set false for a bare checkout (e.g. when only reading code).',
							),
						),
						'required'   => array( 'repo', 'branch' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'handle'              => array( 'type' => 'string' ),
							'path'                => array( 'type' => 'string' ),
							'branch'              => array( 'type' => 'string' ),
							'slug'                => array( 'type' => 'string' ),
							'created_branch'      => array( 'type' => 'boolean' ),
							'message'             => array( 'type' => 'string' ),
							'context_injected'    => array( 'type' => 'boolean' ),
							'context_files'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'context_exclude_path' => array( 'type' => 'string' ),
							'context_skip_reason' => array( 'type' => 'string' ),
							'bootstrap'           => array(
								'type'        => 'object',
								'description' => 'Present only when bootstrap=true. Contains success/ran_any booleans and a steps array.',
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
				'datamachine/workspace-worktree-list',
				array(
					'label'               => 'List Workspace Worktrees',
					'description'         => 'List all worktrees in the workspace (optionally filtered by repo).',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Optional repo name to limit the list.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'worktrees' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'handle'      => array( 'type' => 'string' ),
										'repo'        => array( 'type' => 'string' ),
										'is_worktree' => array( 'type' => 'boolean' ),
										'is_primary'  => array( 'type' => 'boolean' ),
										'external'    => array( 'type' => 'boolean' ),
										'branch_slug' => array( 'type' => array( 'string', 'null' ) ),
										'branch'      => array( 'type' => array( 'string', 'null' ) ),
										'head'        => array( 'type' => 'string' ),
										'path'        => array( 'type' => 'string' ),
										'dirty'       => array( 'type' => 'integer' ),
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
						),
					),
					'execute_callback'    => array( self::class, 'worktreeCleanup' ),
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
		return $workspace->worktree_add(
			$input['repo'] ?? '',
			$input['branch'] ?? '',
			$input['from'] ?? null,
			$inject_context,
			$bootstrap
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
		return $workspace->worktree_list( $repo );
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
	 * @param array $input Input parameters (dry_run, force, skip_github).
	 * @return array
	 */
	public static function worktreeCleanup( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_cleanup_merged(
			array(
				'dry_run'     => ! empty( $input['dry_run'] ),
				'force'       => ! empty( $input['force'] ),
				'skip_github' => ! empty( $input['skip_github'] ),
			)
		);
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
