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
 * @since   0.1.0
 */

namespace DataMachineCode\Abilities;

use DataMachineCode\Support\PermissionHelper;
use DataMachineCode\Cleanup\WorkspaceCleanupRunEvidenceStore;
use DataMachineCode\Workspace\CleanupRunService;
use DataMachineCode\Workspace\RemoteWorkspaceBackend;
use DataMachineCode\Workspace\RunnerWorkspacePublisher;
use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator;
use DataMachineCode\Workspace\WorkspaceCleanupEligibleDrainOrchestrator;
use DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator;
use DataMachineCode\Workspace\WorkspaceReader;
use DataMachineCode\Workspace\WorkspaceWriter;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\RuntimeCapabilities;

defined('ABSPATH') || exit;

if ( ! class_exists(AbilityRegistry::class) ) {
	require_once __DIR__ . '/AbilityRegistry.php';
}
if ( ! class_exists(RuntimeCapabilities::class) ) {
	require_once dirname(__DIR__) . '/Support/RuntimeCapabilities.php';
}
if ( ! class_exists(GitHubAbilities::class) ) {
	require_once __DIR__ . '/GitHubAbilities.php';
}
if ( ! class_exists(RunnerWorkspacePublisher::class) ) {
	require_once dirname(__DIR__) . '/Workspace/RunnerWorkspacePublisher.php';
}

class WorkspaceAbilities {



	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		if ( ! function_exists('wp_register_ability') ) {
			add_action(
				'wp_abilities_api_init', function (): void {
					if ( self::$registered || ! function_exists('wp_register_ability') ) {
						return;
					}

					$this->registerAbilities();
					self::$registered = true;
				}
			);
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

			AbilityRegistry::register(
				'datamachine-code/workspace-path',
				array(
					'label'               => 'Get Workspace Path',
					'description'         => 'Get the agent workspace root path, or the path for a workspace repository handle.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array(
								'type'        => 'string',
								'description' => 'Optional primary or worktree handle, such as <repo> or <repo>@<branch-slug>.',
							),
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

			AbilityRegistry::register(
				'datamachine-code/workspace-list',
				array(
					'label'               => 'List Workspace Repos',
					'description'         => 'List repositories in the agent workspace. Primary rows include local-ref freshness metadata; refresh stale primaries before using them for verification.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Optional primary repository name to filter by. Includes the primary checkout and its worktrees.',
							),
							'type' => array(
								'type'        => 'string',
								'enum'        => array( 'primary', 'worktree', 'context' ),
								'description' => 'Optional checkout type filter. Use "primary" for base checkouts, "worktree" for branch worktrees, or "context" for read-only context repositories.',
							),
						),
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
										'primary_freshness' => self::primaryFreshnessSchema(),
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

			AbilityRegistry::register(
				'datamachine-code/workspace-capabilities',
				array(
					'label'               => 'Inspect Workspace Capabilities',
					'description'         => 'Inspect the current Data Machine Code workspace backend and whether local git operations can execute in this runtime.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'backend'             => array( 'type' => 'string' ),
							'workspace_path'      => array( 'type' => 'string' ),
							'git_available'       => array( 'type' => 'boolean' ),
							'exec_available'      => array( 'type' => 'boolean' ),
							'proc_open_available' => array( 'type' => 'boolean' ),
							'git_path'            => array( 'type' => 'string' ),
							'remediation'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getCapabilities' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-show',
				array(
					'label'               => 'Show Workspace Repo',
					'description'         => 'Show detailed info about a workspace repository (branch, remote, latest commit, dirty status, and primary freshness).',
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
							'success'           => array( 'type' => 'boolean' ),
							'name'              => array( 'type' => 'string' ),
							'repo'              => array( 'type' => 'string' ),
							'is_worktree'       => array( 'type' => 'boolean' ),
							'path'              => array( 'type' => 'string' ),
							// Nullable: detached HEAD has no branch; local-only repos
							// have no remote; freshly-init'd repos have no commit yet.
							'branch'            => array( 'type' => array( 'string', 'null' ) ),
							'remote'            => array( 'type' => array( 'string', 'null' ) ),
							'commit'            => array( 'type' => array( 'string', 'null' ) ),
							'dirty'             => array( 'type' => 'integer' ),
							'primary_freshness' => self::primaryFreshnessSchema(),
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

			AbilityRegistry::register(
				'datamachine-code/workspace-read',
				array(
					'label'               => 'Read Workspace File',
					'description'         => 'Read the contents of a text file from a workspace repository.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'                => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path'                => array(
								'type'        => 'string',
								'description' => 'Relative file path within the repo.',
							),
							'max_size'            => array(
								'type'        => 'integer',
								'description' => 'Maximum file size in bytes (default 1 MB).',
							),
							'offset'              => array(
								'type'        => 'integer',
								'description' => 'Line number to start reading from (1-indexed).',
							),
							'limit'               => array(
								'type'        => 'integer',
								'description' => 'Maximum number of lines to return.',
							),
							'allow_stale_primary' => array(
								'type'        => 'boolean',
								'description' => 'Explicitly allow reading from a stale, diverged, detached, or otherwise unsafe primary checkout. Worktree reads are unaffected.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-ls',
				array(
					'label'               => 'List Workspace Directory',
					'description'         => 'List directory contents within a workspace repository.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'                => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'path'                => array(
								'type'        => 'string',
								'description' => 'Relative directory path within the repo (omit for root).',
							),
							'allow_stale_primary' => array(
								'type'        => 'boolean',
								'description' => 'Explicitly allow listing a stale, diverged, detached, or otherwise unsafe primary checkout. Worktree reads are unaffected.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-grep',
				array(
					'label'               => 'Search Workspace Files',
					'description'         => 'Search text files within a workspace repository using a regular expression pattern.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'                => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'pattern'             => array(
								'type'        => 'string',
								'description' => 'Regular expression pattern to search for.',
							),
							'path'                => array(
								'type'        => 'string',
								'description' => 'Optional relative file or directory path to search within.',
							),
							'include'             => array(
								'type'        => 'string',
								'description' => 'Optional glob pattern to limit matching file paths.',
							),
							'max_results'         => array(
								'type'        => 'integer',
								'description' => 'Maximum number of matches to return (default 100, max 500).',
							),
							'context_lines'       => array(
								'type'        => 'integer',
								'description' => 'Number of surrounding lines to include for each match (default 0, max 10).',
							),
							'allow_stale_primary' => array(
								'type'        => 'boolean',
								'description' => 'Explicitly allow grepping a stale, diverged, detached, or otherwise unsafe primary checkout. Worktree reads are unaffected.',
							),
						),
						'required'   => array( 'repo', 'pattern' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'repo'      => array( 'type' => 'string' ),
							'path'      => array( 'type' => 'string' ),
							'pattern'   => array( 'type' => 'string' ),
							'count'     => array( 'type' => 'integer' ),
							'truncated' => array( 'type' => 'boolean' ),
							'matches'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'path'    => array( 'type' => 'string' ),
										'line'    => array( 'type' => 'integer' ),
										'text'    => array( 'type' => 'string' ),
										'context' => array( 'type' => 'array' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'grepFiles' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// -----------------------------------------------------------------
			// Mutating abilities (show_in_rest = false, CLI-only).
			// -----------------------------------------------------------------

			AbilityRegistry::register(
				'datamachine-code/workspace-clone',
				array(
					'label'               => 'Clone Workspace Repo',
					'description'         => 'Clone a git repository into the workspace as a primary checkout only when no primary for that remote already exists. If the remote exists, refresh/reuse that primary and create a worktree via `workspace-worktree-add`.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'url'                    => array(
								'type'        => 'string',
								'description' => 'Git repository URL to clone.',
							),
							'name'                   => array(
								'type'        => 'string',
								'description' => 'Directory name override (derived from URL if omitted).',
							),
							'full'                   => array(
								'type'        => 'boolean',
								'description' => 'Disable the default blobless partial clone for remote repositories.',
							),
							'auth_token_env'         => array(
								'type'        => 'string',
								'description' => 'Optional environment variable name containing a bearer token for HTTPS clone authentication.',
							),
							'allow_duplicate_remote' => array(
								'type'        => 'boolean',
								'description' => 'Explicitly allow cloning a second top-level primary for a remote already present in the workspace. Default false; use only for deliberate release/proof checkouts.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-context-repositories',
				array(
					'label'               => 'Register Workspace Context Repositories',
					'description'         => 'Register read-only context repositories for the current workspace run. Context repositories are exposed through workspace read/list/grep tools with path allowlists and are rejected by mutating workspace operations.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'target_repo'      => array( 'type' => 'string' ),
							'target_workspace' => array( 'type' => 'string' ),
							'access'           => array(
								'type'        => 'string',
								'enum'        => array( 'readonly', 'read_only' ),
								'description' => 'Context repositories are currently read-only.',
							),
							'repositories'     => array(
								'type'        => 'array',
								'description' => 'Read-only context repository specs. Each entry is { repo, ref, alias, paths }.',
							),
						),
						'required'   => array( 'repositories' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'access'       => array( 'type' => 'string' ),
							'count'        => array( 'type' => 'integer' ),
							'repositories' => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'registerContextRepositories' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-adopt',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-remove',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-write',
				array(
					'label'               => 'Write Workspace File',
					'description'         => 'Create or overwrite a file in a workspace repository.',
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
								'description' => 'Relative file path within the repo.',
							),
							'content'                => array(
								'type'        => 'string',
								'description' => 'File content to write.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit mutation on the primary checkout (default false). Worktrees are always allowed.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-edit',
				array(
					'label'               => 'Edit Workspace File',
					'description'         => 'Find-and-replace text in a workspace repository file.',
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
								'description' => 'Relative file path within the repo.',
							),
							'old_string'             => array(
								'type'        => 'string',
								'description' => 'Text to find.',
							),
							'new_string'             => array(
								'type'        => 'string',
								'description' => 'Replacement text.',
							),
							'search'                 => array(
								'type'        => 'string',
								'description' => 'Alias for old_string.',
							),
							'replace'                => array(
								'type'        => 'string',
								'description' => 'Alias for new_string.',
							),
							'old'                    => array(
								'type'        => 'string',
								'description' => 'Alias for old_string.',
							),
							'new'                    => array(
								'type'        => 'string',
								'description' => 'Alias for new_string.',
							),
							'replace_all'            => array(
								'type'        => 'boolean',
								'description' => 'Replace all occurrences (default false).',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-apply-patch',
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
							'patch'                  => array(
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-status',
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
						'required'   => array(),
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-log',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-diff',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-pull',
				array(
					'label'               => 'Workspace Git Pull',
					'description'         => 'Run git pull --ff-only for a workspace handle. Primary refresh requires allow_primary_refresh=true; worktrees are always allowed.',
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
							'allow_primary_refresh'  => array(
								'type'        => 'boolean',
								'description' => 'Permit safe primary refresh with git pull --ff-only. Worktrees are always allowed.',
							),
							'allow_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Legacy alias for allow_primary_refresh on git pull only.',
							),
							'remote'                 => array(
								'type'        => 'string',
								'description' => 'Remote name for pull when branch is supplied (default origin).',
							),
							'branch'                 => array(
								'type'        => 'string',
								'description' => 'Remote branch to pull. Useful when the checkout is detached.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-add',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-delete',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-commit',
				array(
					'label'               => 'Workspace Git Commit',
					'description'         => 'Commit staged changes in a workspace handle. Primary commits require allow_dangerous_primary_mutation=true; use a worktree whenever possible.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'    => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'message' => array(
								'type'        => 'string',
								'description' => 'Commit message.',
							),
							'allow_dangerous_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit committing on a primary checkout. Use only for an explicitly approved primary mutation.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-git-push',
				array(
					'label'               => 'Workspace Git Push',
					'description'         => 'Push commits for a workspace handle. `fixed_branch` policy applies only to the primary checkout; worktrees may push any branch.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'             => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).',
							),
							'remote'           => array(
								'type'        => 'string',
								'description' => 'Remote name (default origin).',
							),
							'branch'           => array(
								'type'        => 'string',
								'description' => 'Branch override.',
							),
							'allow_dangerous_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit pushing from a primary checkout. Use only for an explicitly approved primary mutation.',
							),
							'force_with_lease' => array(
								'type'        => 'boolean',
								'description' => 'Use git push --force-with-lease. Refuses protected base/fixed branches.',
							),
							'expected_sha'     => array(
								'type'        => 'string',
								'description' => 'Optional expected remote branch SHA for --force-with-lease.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'            => array( 'type' => 'boolean' ),
							'kind'               => array( 'type' => 'string' ),
							'name'               => array( 'type' => 'string' ),
							'repo'               => array( 'type' => 'string' ),
							'workspace_repo'     => array( 'type' => 'string' ),
							'github_repo'        => array( 'type' => array( 'string', 'null' ) ),
							'remote'             => array( 'type' => 'string' ),
							'branch'             => array( 'type' => 'string' ),
							'force_with_lease'   => array( 'type' => 'boolean' ),
							'expected_sha'       => array( 'type' => array( 'string', 'null' ) ),
							'url'                => array( 'type' => array( 'string', 'null' ) ),
							'html_url'           => array( 'type' => array( 'string', 'null' ) ),
							'next_required_tool' => array( 'type' => array( 'string', 'null' ) ),
							'next_required_args' => array( 'type' => array( 'object', 'null' ) ),
							'message'            => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitPush' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/publish-runner-workspace',
				array(
					'label'               => 'Publish Runner Workspace',
					'description'         => 'Canonical Data Machine Code publication API for runner-owned workspace changes: stage/commit/push the workspace branch and open or reuse the pull request.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => self::runnerWorkspacePublishInputSchema(),
					'output_schema'       => self::runnerWorkspacePublishOutputSchema(),
					'execute_callback'    => array( self::class, 'publishRunnerWorkspace' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/run-runner-workspace-command',
				array(
					'label'               => 'Run Runner Workspace Command',
					'description'         => 'Run a bounded verification or drift command against a runner-owned workspace handle without exposing DMC local paths to callers.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => self::runnerWorkspaceCommandInputSchema(),
					'output_schema'       => self::runnerWorkspaceCommandOutputSchema(),
					'execute_callback'    => array( self::class, 'runRunnerWorkspaceCommand' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-git-rebase',
				array(
					'label'               => 'Workspace Git Rebase',
					'description'         => 'Fetch and rebase a workspace handle, returning structured conflict information without auto-resolving conflicts.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'            => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` or `<repo>@<branch-slug>`.',
							),
							'onto'            => array(
								'type'        => 'string',
								'description' => 'Base ref to rebase onto. Defaults to origin/HEAD or origin/<branch>.',
							),
							'interactive'     => array(
								'type'        => 'boolean',
								'description' => 'Reserved; interactive rebases are not supported.',
							),
							'strategy_option' => array(
								'type'        => 'string',
								'description' => 'Optional git strategy option such as theirs or ours.',
							),
							'continue'        => array(
								'type'        => 'boolean',
								'description' => 'Continue an in-progress rebase after conflicts were resolved and staged.',
							),
							'allow_dangerous_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit rebasing a primary checkout. Use only for an explicitly approved primary mutation.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => self::gitRebaseOutputSchema(),
					'execute_callback'    => array( self::class, 'gitRebase' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-git-reset',
				array(
					'label'               => 'Workspace Git Reset',
					'description'         => 'Run git reset --soft/--mixed/--hard for a workspace handle. Hard reset requires allow_destructive=true.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'              => array(
								'type'        => 'string',
								'description' => 'Workspace handle: `<repo>` or `<repo>@<branch-slug>`.',
							),
							'mode'              => array(
								'type'        => 'string',
								'enum'        => array( 'soft', 'mixed', 'hard' ),
								'description' => 'Reset mode. Default mixed.',
							),
							'target'            => array(
								'type'        => 'string',
								'description' => 'Target ref or commit. Defaults to origin/HEAD or origin/<branch>.',
							),
							'allow_destructive' => array(
								'type'        => 'boolean',
								'description' => 'Required for hard reset.',
							),
							'allow_dangerous_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit resetting a primary checkout. Use only for an explicitly approved primary mutation.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'mode'           => array( 'type' => 'string' ),
							'previous_head'  => array( 'type' => 'string' ),
							'new_head'       => array( 'type' => 'string' ),
							'paths_affected' => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'gitReset' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-pr-status',
				array(
					'label'               => 'Workspace Pull Request Status',
					'description'         => 'Resolve a workspace pull request and return mergeability/freshness state from GitHub.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array(
								'type'        => 'string',
								'description' => 'Workspace handle.',
							),
							'pr'     => array(
								'type'        => array( 'string', 'integer' ),
								'description' => 'PR number or URL. Defaults to the current branch PR.',
							),
							'branch' => array(
								'type'        => 'string',
								'description' => 'Branch to resolve when pr is omitted.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
					),
					'execute_callback'    => array( self::class, 'prStatus' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-pr-rebase',
				array(
					'label'               => 'Workspace Pull Request Rebase',
					'description'         => 'Bring a workspace pull request branch up to date with its base branch, optionally dropping configured conflict paths, squashing, and force-with-lease pushing.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'       => array(
								'type'        => 'string',
								'description' => 'Workspace handle.',
							),
							'pr'         => array(
								'type'        => array( 'string', 'integer' ),
								'description' => 'PR number or URL. Defaults to the current branch PR.',
							),
							'squash'     => array(
								'type'        => 'boolean',
								'description' => 'Squash rebased commits into one PR-title commit before pushing.',
							),
							'drop_paths' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Glob patterns to resolve by taking the base version during rebase conflicts.',
							),
							'allow_dangerous_primary_mutation' => array(
								'type'        => 'boolean',
								'description' => 'Permit rebasing and force-with-lease pushing from a primary checkout. Use only for an explicitly approved primary mutation.',
							),
						),
						'required'   => array( 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
					),
					'execute_callback'    => array( self::class, 'prRebase' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			// -----------------------------------------------------------------
			// Worktree abilities (mutating, CLI-only by default).
			// -----------------------------------------------------------------

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-add',
				array(
					'label'               => 'Add Workspace Worktree',
					'description'         => 'Create a git worktree for a branch under `<repo>@<branch-slug>`. Branches are created off the supplied `from` ref (default `origin/HEAD`) when they do not yet exist locally. Creation fails closed when remote freshness cannot be verified; set `allow_unverified_freshness=true` only for intentional offline work. When `inject_context` is true (default), the originating site\'s context is projected through registered runtime integrations and injected paths are added to the worktree\'s per-checkout `info/exclude`. When `bootstrap` is true (default), submodule init plus root or one-level nested package-manager/composer installs run after creation. Git submodule package roots are excluded unless `.datamachine/worktree-bootstrap.json` explicitly opts them in; set false to create a bare checkout.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'                       => array(
								'type'        => 'string',
								'description' => 'Primary repo name (no @-suffix).',
							),
							'branch'                     => array(
								'type'        => 'string',
								'description' => 'Branch to check out in the worktree (e.g. fix/foo-bar). Slashes become dashes in the on-disk slug.',
							),
							'from'                       => array(
								'type'        => 'string',
								'description' => 'Base ref when creating the branch (default origin/HEAD).',
							),
							'inject_context'             => array(
								'type'        => 'boolean',
								'description' => 'Inject the originating site\'s agent context (MEMORY.md, USER.md, RULES.md) into the new worktree. Default true. Set false to create a bare worktree.',
							),
							'bootstrap'                  => array(
								'type'        => 'boolean',
								'description' => 'Run detected bootstrap steps (submodule init plus root or one-level nested package-manager/composer installs) after creating the worktree. Git submodule package roots are excluded unless `.datamachine/worktree-bootstrap.json` explicitly opts them in. Default true. Steps are skipped gracefully when their trigger file or tool is missing. Set false for a bare checkout (e.g. when only reading code).',
							),
							'allow_stale'                => array(
								'type'        => 'boolean',
								'description' => 'Bypass the staleness gate. When false (default), any branch/base behind the remote default branch is refused, and a new worktree more than `datamachine_worktree_stale_threshold` commits behind upstream is rolled back with a staleness error. Set true to opt in to a known-stale checkout.',
							),
							'allow_unverified_freshness' => array(
								'type'        => 'boolean',
								'description' => 'Bypass the fetch-failure freshness gate. When false (default), worktree creation is refused if remote freshness cannot be verified. Set true only for intentional offline work with local refs.',
							),
							'rebase_base'                => array(
								'type'        => 'boolean',
								'description' => 'After creating the worktree, rebase onto the upstream tip (the branch\'s @{upstream} for existing branches, origin/<base> for new branches off a local base). Default false. On rebase conflicts the rebase is aborted; the worktree stays at its pre-rebase state and `rebase_succeeded: false` is surfaced.',
							),
							'force'                      => array(
								'type'        => 'boolean',
								'description' => 'Explicitly bypass the disk-budget refusal threshold. The disk-budget report still appears in output so the override is visible.',
							),
							'task_url'                   => array(
								'type'        => 'string',
								'description' => 'Optional task/issue URL (e.g. GitHub issue link) to record on the worktree for ownership/duplicate detection. Falls back to DATAMACHINE_TASK_URL env when omitted.',
							),
							'task_ref'                   => array(
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
								'description' => 'Present only when the pre-create `git fetch origin` failed and allow_unverified_freshness=true allowed creation to continue. Staleness fields are omitted when true.',
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
							'default_branch_commits_behind' => array(
								'type'        => 'integer',
								'description' => 'How many commits the requested branch/base is behind the remote default branch. Any value greater than 0 is refused unless allow_stale=true.',
							),
							'default_branch_ref'        => array(
								'type'        => 'string',
								'description' => 'Remote default branch ref used for default-branch freshness checks (e.g. `refs/remotes/origin/main`).',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-refresh-context',
				array(
					'label'               => 'Refresh Worktree Context',
					'description'         => 'Re-read the originating site\'s agent memory and refresh its registered context projections in an existing worktree. Must be run from the site that created the worktree — cross-machine refresh is not supported.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-finalize',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-hygiene-report',
				array(
					'label'               => 'Workspace Hygiene Report',
					'description'         => 'Build a non-destructive workspace hygiene report with disk, size, worktree, and local cleanup dry-run summaries.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'include_cleanup'         => array(
								'type'        => 'boolean',
								'description' => 'Include a local-only worktree cleanup dry-run summary. Default true.',
							),
							'include_sizes'           => array(
								'type'        => 'boolean',
								'description' => 'Include best-effort top-level workspace size data. Default false for huge-workspace safety.',
							),
							'include_worktree_status' => array(
								'type'        => 'boolean',
								'description' => 'Include full per-worktree git status. Default false for huge-workspace safety.',
							),
							'refresh_inventory'       => array(
								'type'        => 'boolean',
								'description' => 'Refresh the DB-backed worktree inventory before reporting freshness. Default false.',
							),
							'size_limit'              => array(
								'type'        => 'integer',
								'description' => 'Maximum top-level workspace entries to size when include_sizes is true. Default 1000.',
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
							'suggested_size_command'    => array( 'type' => 'string' ),
							'notes'                     => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'workspaceHygieneReport' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-inventory-refresh',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-inventory-prune-missing',
				array(
					'label'               => 'Prune Missing Worktree Inventory Rows',
					'description'         => 'Delete DB-backed worktree inventory rows flagged missing_path whose on-disk path is still absent. Re-probes each candidate before deletion and protects rows with unpushed commits or an open PR unless force is set.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => 'Preview candidates and skips without deleting any rows. Default false.',
							),
							'force'   => array(
								'type'        => 'boolean',
								'description' => 'Allow pruning rows with unpushed_count > 0 or a non-empty pr_url. Default false.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'pruned_at' => array( 'type' => 'string' ),
							'dry_run'   => array( 'type' => 'boolean' ),
							'deleted'   => array( 'type' => 'array' ),
							'skipped'   => array( 'type' => 'array' ),
							'summary'   => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeInventoryPruneMissing' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-cleanup-run',
				array(
					'label'               => 'Schedule Workspace Cleanup Run',
					'description'         => 'Schedule a background workspace cleanup system task. Review/dry-run commands are separate synchronous abilities.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'mode'                => array(
								'type'        => 'string',
								'description' => 'Cleanup mode: inventory, artifacts, retention, stale-worktrees, or emergency.',
							),
							'force'               => array(
								'type'        => 'boolean',
								'description' => 'Forward force=true to cleanup tasks that support it.',
							),
							'dry_run'             => array(
								'type'        => 'boolean',
								'description' => 'Rejected for background cleanup scheduling; use review abilities for dry-runs.',
							),
							'older_than'          => array(
								'type'        => 'string',
								'description' => 'Optional worktree retention age gate such as 14d.',
							),
							'worktree_stale_only' => array(
								'type'        => 'boolean',
								'description' => 'Only plan stale/inactive worktrees for destructive removal.',
							),
							'source'              => array(
								'type'        => 'string',
								'description' => 'Caller source marker.',
							),
							'user_id'             => array( 'type' => 'integer' ),
							'agent_id'            => array( 'type' => 'integer' ),
							'agent_slug'          => array( 'type' => 'string' ),
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

			AbilityRegistry::register(
				'datamachine-code/workspace-cleanup-safe',
				array(
					'label'               => 'Run Safe Workspace Cleanup',
					'description'         => 'Run the canonical DMC safe workspace cleanup flow. Uses DMC safe classifiers/removals, refuses force and unpushed discard, and reports remaining blockers.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'          => array(
								'type'        => 'boolean',
								'description' => 'Preview safe cleanup without removing worktrees or stale DMC lock files.',
							),
							'limit'            => array(
								'type'        => 'integer',
								'description' => 'Maximum rows each child drain processes per pass. Clamped by the orchestrator.',
							),
							'passes'           => array(
								'type'        => 'integer',
								'description' => 'Maximum child-drain passes per cycle. Clamped by the orchestrator.',
							),
							'cycles'           => array(
								'type'        => 'integer',
								'description' => 'Maximum safe cleanup cycles before stopping. Clamped by the orchestrator.',
							),
							'until_budget'     => array(
								'type'        => 'string',
								'description' => 'Optional child-drain time budget such as 30s.',
							),
							'source'           => array(
								'type'        => 'string',
								'description' => 'Caller source marker recorded on child cleanup operations.',
							),
							'force'            => array(
								'type'        => 'boolean',
								'description' => 'Always refused by safe cleanup; dirty worktrees remain blockers.',
							),
							'discard_unpushed' => array(
								'type'        => 'boolean',
								'description' => 'Always refused by safe cleanup; unpushed worktrees remain blockers.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'mode'         => array( 'type' => 'string' ),
							'run_id'       => array( 'type' => 'string' ),
							'applied'      => array( 'type' => 'boolean' ),
							'destructive'  => array( 'type' => 'boolean' ),
							'summary'      => array( 'type' => 'object' ),
							'blockers'     => array( 'type' => 'array' ),
							'evidence'     => array( 'type' => 'object' ),
							'steps'        => array( 'type' => 'object' ),
							'commands'     => array( 'type' => 'object' ),
							'continuation' => array( 'type' => 'object' ),
							'state'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'workspaceCleanupSafe' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-list',
				array(
					'label'               => 'List Workspace Worktrees',
					'description'         => 'List all worktrees in the workspace (optionally filtered by repo and lifecycle state). Defaults to a fast cheap-inventory listing on large workspaces; opt in to per-worktree git status and disk probes via include_status / include_disk.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'repo'           => array(
								'type'        => 'string',
								'description' => 'Optional repo name to limit the list.',
							),
							'handle'         => array(
								'type'        => 'string',
								'description' => 'Optional exact worktree handle. Filters before status and disk probes.',
							),
							'state'          => array(
								'type'        => 'string',
								'description' => 'Optional lifecycle state filter.',
							),
							'include_status' => array(
								'type'        => 'boolean',
								'description' => 'Run `git status --porcelain` per worktree to populate the dirty count. Default false (cheap listing). Expensive on large workspaces.',
							),
							'include_disk'   => array(
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
											'type'        => 'object',
											'description' => 'Owner snapshot recorded at worktree creation. Unknown-safe defaults: site, agent, user default to the literal string "unknown".',
											'properties'  => array(
												'site'     => array( 'type' => 'string' ),
												'site_url' => array( 'type' => array( 'string', 'null' ) ),
												'agent'    => array( 'type' => 'string' ),
												'user'     => array( 'type' => 'string' ),
											),
										),
										'session'         => array(
											'type'        => 'object',
											'description' => 'Captured session identifiers in a runtime-agnostic envelope. `primary_id` is the single renderer-friendly identifier downstream surfaces display. `ids` is a free-form map keyed by runtime ID (a string the integration layer chooses, e.g. via the `datamachine_code_worktree_runtime_signatures` filter); each entry is a string-map of subkeys (e.g. session_id, thread_id, thread_url, run_id) the integration chose to capture. DMC enumerates no runtime IDs and no subkeys.',
											'properties'  => array(
												'primary_id' => array( 'type' => array( 'string', 'null' ) ),
												'ids' => array(
													'type' => 'object',
													'description' => 'Map of runtime-id => { subkey => string|null }. Keys are opaque; DMC does not validate against a closed set.',
													'additionalProperties' => array(
														'type'                 => 'object',
														'additionalProperties' => array( 'type' => array( 'string', 'null' ) ),
													),
												),
											),
										),
										'task'            => array(
											'type'        => array( 'object', 'null' ),
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
							'duplicates'     => array(
								'type'        => 'array',
								'description' => 'Groups of worktrees sharing a task_url, task_ref, pr_url, or pr_repo#pr_number. Reported only — never used to drive deletions.',
								'items'       => array(
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-remove',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-prune',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-cleanup',
				array(
					'label'               => 'Cleanup Merged Worktrees',
					'description'         => 'Remove worktrees whose branch is merged to the remote default branch. Detects merge via `upstream: gone` (remote branch deleted, e.g. by GitHub auto-delete on PR merge) or closed+merged PR via the GitHub API. Deletes the local branch and prunes the git registry after removal. Dry-run supported.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'                   => array(
								'type'        => 'boolean',
								'description' => 'If true, return the plan without removing anything.',
							),
							'force'                     => array(
								'type'        => 'boolean',
								'description' => 'If true, ignore dirty working-tree safety check.',
							),
							'skip_github'               => array(
								'type'        => 'boolean',
								'description' => 'If true, rely solely on the local upstream-gone signal and skip GitHub API lookup.',
							),
							'inventory_only'            => array(
								'type'        => 'boolean',
								'description' => 'If true, build a dry-run review from cheap top-level inventory and explicit lifecycle cleanup signals only. Avoids full git worktree/status scans and GitHub lookups.',
							),
							'apply_plan'                => array(
								'type'        => 'object',
								'description' => 'Decoded cleanup dry-run report to apply after revalidating every candidate.',
							),
							'older_than'                => array(
								'type'        => 'string',
								'description' => 'Optional candidate age filter such as 7d, 24h, 30m, or 60s. Uses lifecycle created_at metadata only.',
							),
							'sort'                      => array(
								'type'        => 'string',
								'description' => 'Optional cleanup candidate sort: size or age.',
							),
							'include_repaired_metadata' => array(
								'type'        => 'boolean',
								'description' => 'If true, include repaired metadata rows as operator-approved removal candidates after full safety revalidation.',
							),
							'limit'                     => array(
								'type'        => 'integer',
								'description' => 'For dry-run cleanup, maximum worktrees to inspect in this page.',
							),
							'offset'                    => array(
								'type'        => 'integer',
								'description' => 'For dry-run cleanup, pagination offset into the worktree inventory.',
							),
							'until_budget'              => array(
								'type'        => 'string',
								'description' => 'For dry-run cleanup, compact wall-clock budget such as 60s or 10m. Returns continuation evidence when more rows remain.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-reconcile-metadata',
				array(
					'label'               => 'Reconcile Worktree Metadata',
					'description'         => 'Build, apply, or schedule bounded apply jobs for lifecycle metadata reconciliation. Never removes worktrees; apply paths revalidate identity and cleanup-eligibility safety gates before writing.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'If true, return a review plan without writing metadata.',
							),
							'apply_plan'   => array(
								'type'        => 'object',
								'description' => 'Decoded dry-run plan to apply after exact handle/path/repo/branch revalidation.',
							),
							'apply'        => array(
								'type'        => 'boolean',
								'description' => 'If true, run DMC-owned direct apply without a manual plan.',
							),
							'via_jobs'     => array(
								'type'        => 'boolean',
								'description' => 'With apply=true, schedule bounded metadata reconciliation page jobs instead of applying synchronously.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope. When supplied, pagination, evidence, and writes are constrained to matching rows.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Positive page size for bounded dry-run, direct apply, budgeted apply, or job-backed apply.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Pagination offset for bounded dry-run, direct apply, or budgeted apply. Job-backed apply schedules from offset 0 across all pages.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Compact time budget for direct apply drain mode or bounded dry-run pages, such as 60s or 10m.',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-active-no-signal-report',
				array(
					'label'               => 'Report Active Worktrees Without Cleanup Signal',
					'description'         => 'Build a bounded, review-only evidence report for active_no_signal worktrees. Gathers PR, dirty, unpushed, remote tracking, and default-branch evidence without deleting worktrees or branches.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Positive maximum active_no_signal rows to inspect in this page. Defaults to 25.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Pagination offset into the active_no_signal inventory ordering.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Compact time budget for this report page, such as 60s or 10m.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope. When supplied, pagination and evidence are constrained to matching rows.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'review_only' => array( 'type' => 'boolean' ),
							'rows'        => array( 'type' => 'array' ),
							'summary'     => array( 'type' => 'object' ),
							'pagination'  => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeActiveNoSignalReport' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-active-no-signal-finalized-apply',
				array(
					'label'               => 'Promote Finalized Active Worktrees',
					'description'         => 'Promote active_no_signal rows with merged PR evidence into explicit cleanup_eligible metadata. Reviewable and bounded; never deletes worktrees.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'If true, preview metadata promotions without writing.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Positive maximum active_no_signal rows to inspect in this page. Defaults to 25.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Pagination offset into the active_no_signal inventory ordering.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Compact time budget for the underlying active_no_signal report page, such as 60s or 10m.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope. When supplied, pagination, evidence, and writes are constrained to matching rows.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'dry_run' => array( 'type' => 'boolean' ),
							'planned' => array( 'type' => 'array' ),
							'written' => array( 'type' => 'array' ),
							'skipped' => array( 'type' => 'array' ),
							'summary' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeActiveNoSignalFinalizedApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply',
				array(
					'label'               => 'Promote Equivalent Clean Active Worktrees',
					'description'         => 'Promote active_no_signal rows with effective_status=equivalent_clean into explicit cleanup_eligible metadata. Reviewable and bounded; never deletes worktrees.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'If true, preview metadata promotions without writing.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Positive maximum active_no_signal rows to inspect in this page. Defaults to 25.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Pagination offset into the active_no_signal inventory ordering.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Compact time budget for the underlying active_no_signal report page, such as 60s or 10m.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope. When supplied, pagination, evidence, and writes are constrained to matching rows.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'dry_run' => array( 'type' => 'boolean' ),
							'planned' => array( 'type' => 'array' ),
							'written' => array( 'type' => 'array' ),
							'skipped' => array( 'type' => 'array' ),
							'summary' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeActiveNoSignalEquivalentCleanApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-active-no-signal-merged-apply',
				array(
					'label'               => 'Promote Merged Active Worktrees',
					'description'         => 'Promote clean active_no_signal rows with suggested_action=merged_to_default into explicit cleanup_eligible metadata. Reviewable and bounded; never deletes worktrees.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'If true, preview metadata promotions without writing.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Maximum active_no_signal rows to inspect in this page. Defaults to 25.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Pagination offset into the active_no_signal inventory ordering.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Compact time budget for the underlying active_no_signal report page, such as 60s or 10m.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope. When supplied, pagination, evidence, and writes are constrained to matching rows.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'dry_run' => array( 'type' => 'boolean' ),
							'planned' => array( 'type' => 'array' ),
							'written' => array( 'type' => 'array' ),
							'skipped' => array( 'type' => 'array' ),
							'summary' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeActiveNoSignalMergedApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply',
				array(
					'label'               => 'Promote Clean Remote Active Worktrees',
					'description'         => 'Promote clean active_no_signal rows with suggested_action=remote_tracking_clean into explicit cleanup_eligible metadata. Reviewable and bounded; never deletes worktrees or remote branches.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'If true, preview metadata promotions without writing.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Maximum active_no_signal rows to inspect in this page. Defaults to 25.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Pagination offset into the active_no_signal inventory ordering.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Compact time budget for the underlying active_no_signal report page, such as 60s or 10m.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope. When supplied, pagination, evidence, and writes are constrained to matching rows.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'dry_run' => array( 'type' => 'boolean' ),
							'planned' => array( 'type' => 'array' ),
							'written' => array( 'type' => 'array' ),
							'skipped' => array( 'type' => 'array' ),
							'summary' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeActiveNoSignalRemoteCleanApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-active-no-signal-drain',
				array(
					'label'               => 'Drain Safe Active Worktree Cleanup',
					'description'         => 'Run safe active/no-signal classifiers, remove newly cleanup-eligible worktrees through bounded cleanup, and stop with protected blockers. Refuses force and unpushed discard.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'apply'        => array(
								'type'        => 'boolean',
								'description' => 'If true, write safe cleanup metadata and remove bounded cleanup-eligible worktrees. Defaults to preview mode.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Page/removal limit, clamped to 1..1000. Defaults to 100.',
							),
							'passes'       => array(
								'type'        => 'integer',
								'description' => 'Maximum apply passes, clamped to 1..25. Preview mode runs one pass.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Stage pagination offset for resumed runs.',
							),
							'stage'        => array(
								'type'        => 'string',
								'enum'        => array( 'finalized', 'equivalent-clean', 'merged', 'remote-clean', 'bounded' ),
								'description' => 'Active/no-signal stage to start from. Defaults to finalized.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Optional compact wall-clock budget such as 60s, 10m, or 1h.',
							),
							'scope'        => array(
								'type'        => 'string',
								'description' => 'Operator scope label preserved in continuation commands and forwarded to child cleanup abilities.',
							),
							'source'       => array(
								'type'        => 'string',
								'description' => 'Caller source marker forwarded to underlying cleanup abilities.',
							),
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Optional primary repo or worktree handle scope forwarded to underlying paginated cleanup abilities.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'mode'          => array( 'type' => 'string' ),
							'applied'       => array( 'type' => 'boolean' ),
							'summary'       => array( 'type' => 'object' ),
							'steps'         => array( 'type' => 'object' ),
							'blocked'       => array( 'type' => 'array' ),
							'continuation'  => array( 'type' => 'object' ),
							'next_commands' => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeActiveNoSignalDrain' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-abandoned-cleanup',
				array(
					'label'               => 'Orchestrate Abandoned Worktree Cleanup',
					'description'         => 'Run the bounded abandoned-worktree cleanup orchestration: reconcile metadata, promote safe cleanup candidates, remove eligible rows, prune git metadata, and return continuation evidence.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'apply'        => array(
								'type'        => 'boolean',
								'description' => 'If true, write cleanup metadata and remove bounded cleanup-eligible worktrees. Defaults to preview mode.',
							),
							'force'        => array(
								'type'        => 'boolean',
								'description' => 'Forward force to bounded cleanup removal. Unpushed commits remain protected.',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Page/removal limit, clamped to 1..1000. Defaults to 100.',
							),
							'passes'       => array(
								'type'        => 'integer',
								'description' => 'Maximum apply passes, clamped to 1..25. Preview mode runs one pass.',
							),
							'offset'       => array(
								'type'        => 'integer',
								'description' => 'Stage pagination offset for resumed runs.',
							),
							'stage'        => array(
								'type'        => 'string',
								'enum'        => array( 'reconcile', 'finalized', 'equivalent-clean', 'merged', 'remote-clean', 'bounded' ),
								'description' => 'Stage to start from. Defaults to reconcile.',
							),
							'until_budget' => array(
								'type'        => 'string',
								'description' => 'Optional compact wall-clock budget such as 60s, 10m, or 1h.',
							),
							'scope'        => array(
								'type'        => 'string',
								'description' => 'Operator scope label preserved in continuation commands and forwarded to child cleanup abilities.',
							),
							'source'       => array(
								'type'        => 'string',
								'description' => 'Caller source marker forwarded to underlying cleanup abilities.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'mode'          => array( 'type' => 'string' ),
							'applied'       => array( 'type' => 'boolean' ),
							'summary'       => array( 'type' => 'object' ),
							'steps'         => array( 'type' => 'object' ),
							'blocked'       => array( 'type' => 'array' ),
							'continuation'  => array( 'type' => 'object' ),
							'next_commands' => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeAbandonedCleanup' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-cleanup-artifacts',
				array(
					'label'               => 'Cleanup Worktree Artifacts',
					'description'         => 'Remove profile-derived, reconstructable artifact directories inside workspace worktrees. Dry-run defaults to bounded inventory mode (limit=' . Workspace::ARTIFACT_CLEANUP_DEFAULT_LIMIT . ', cheap top-level scan, no per-worktree git probes) so huge workspaces stay responsive. Requires a dry-run plan before deletion and revalidates exact paths before applying.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'       => array(
								'type'        => 'boolean',
								'description' => 'If true, return the artifact cleanup plan without deleting anything.',
							),
							'force'         => array(
								'type'        => 'boolean',
								'description' => 'If true, allow artifact cleanup in dirty or unpushed worktrees. Active plugin/theme symlink targets remain protected.',
							),
							'apply_plan'    => array(
								'type'        => 'object',
								'description' => 'Decoded artifact cleanup dry-run report to apply after revalidating every worktree and artifact path.',
							),
							'limit'         => array(
								'type'        => 'integer',
								'description' => 'Positive maximum worktrees to scan in a dry-run page. Defaults to ' . Workspace::ARTIFACT_CLEANUP_DEFAULT_LIMIT . '. Use exhaustive=true for the explicit unbounded full audit mode.',
							),
							'offset'        => array(
								'type'        => 'integer',
								'description' => 'Pagination offset (0-indexed) into the inventory ordering. Combine with the previous response\'s pagination.next_offset to walk huge workspaces in pages.',
							),
							'exhaustive'    => array(
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-emergency-cleanup',
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

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply',
				array(
					'label'               => 'Bounded Cleanup Apply for Obvious Worktrees',
					'description'         => 'Apply only worktrees with explicit lifecycle cleanup_eligible metadata in a bounded batch using cheap workspace inventory. Can explicitly include repaired metadata rows for operator-approved cleanup. Revalidates dirty/unpushed/missing-metadata/external/primary safety gates immediately before each removal. Optionally schedules per-candidate chunk jobs for resumable async apply. Produces evidence with processed/removed/skipped/bytes_reclaimed/continuation.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'                   => array(
								'type'        => 'boolean',
								'description' => 'Preview the bounded batch without removing anything.',
							),
							'limit'                     => array(
								'type'        => 'integer',
								'description' => 'Maximum candidates to attempt this call (default 25, hard ceiling 200).',
							),
							'older_than'                => array(
								'type'        => 'string',
								'description' => 'Restrict candidates to lifecycle created_at older than the duration (e.g. 7d, 24h).',
							),
							'sort'                      => array(
								'type'        => 'string',
								'description' => 'Candidate ordering before bounding: size or age (default age).',
							),
							'force'                     => array(
								'type'        => 'boolean',
								'description' => 'Allow apply on dirty worktrees. Unpushed-commit gate is never overridden.',
							),
							'discard_unpushed'          => array(
								'type'        => 'boolean',
								'description' => 'Explicitly discard unpushed commits for bounded cleanup-eligible rows. This is a data-loss mode and is separate from force.',
							),
							'via_jobs'                  => array(
								'type'        => 'boolean',
								'description' => 'Schedule each candidate as a single-row worktree_cleanup_chunk job for resumable async apply.',
							),
							'remove_timeout'            => array(
								'type'        => 'integer',
								'description' => 'Timeout in seconds for destructive git worktree remove calls during apply. Defaults to the cleanup removal timeout.',
							),
							'include_repaired_metadata' => array(
								'type'        => 'boolean',
								'description' => 'Also include repaired metadata rows. Requires explicit opt-in and still runs fresh safety probes before removal.',
							),
							'source'                    => array(
								'type'        => 'string',
								'description' => 'Caller source marker recorded in evidence.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'mode'           => array( 'type' => 'string' ),
							'dry_run'        => array( 'type' => 'boolean' ),
							'destructive'    => array( 'type' => 'boolean' ),
							'job_backed'     => array( 'type' => 'boolean' ),
							'workspace_path' => array( 'type' => 'string' ),
							'generated_at'   => array( 'type' => 'string' ),
							'candidates'     => array( 'type' => 'array' ),
							'removed'        => array( 'type' => 'array' ),
							'skipped'        => array( 'type' => 'array' ),
							'summary'        => array( 'type' => 'object' ),
							'continuation'   => array( 'type' => 'object' ),
							'evidence'       => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'worktreeBoundedCleanupEligibleApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-worktree-cleanup-eligible-drain',
				array(
					'label'               => 'Drain Cleanup-Eligible Worktrees',
					'description'         => 'Repeat the bounded cleanup-eligible apply primitive until no safe candidates remain, the pass limit is reached, or the time budget expires. Defaults to preview mode and never discards unpushed commits.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'apply'                     => array( 'type' => 'boolean' ),
							'force'                     => array( 'type' => 'boolean' ),
							'limit'                     => array( 'type' => 'integer' ),
							'passes'                    => array( 'type' => 'integer' ),
							'until_budget'              => array( 'type' => 'string' ),
							'older_than'                => array( 'type' => 'string' ),
							'sort'                      => array( 'type' => 'string' ),
							'remove_timeout'            => array( 'type' => 'integer' ),
							'include_repaired_metadata' => array( 'type' => 'boolean' ),
							'discard_unpushed'          => array( 'type' => 'boolean' ),
							'source'                    => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'worktreeCleanupEligibleDrain' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-cleanup-plan',
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
							'limit'                  => array( 'type' => 'integer' ),
							'offset'                 => array( 'type' => 'integer' ),
							'until_budget'           => array( 'type' => 'string' ),
							'full_workspace'         => array( 'type' => 'boolean' ),
							'worktree_older_than'    => array( 'type' => 'string' ),
							'worktree_sort'          => array( 'type' => 'string' ),
							'worktree_stale_only'    => array( 'type' => 'boolean' ),
							'plan'                   => array( 'type' => 'object' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'mode'        => array( 'type' => 'string' ),
							'plan_id'     => array( 'type' => 'string' ),
							'rows'        => array( 'type' => 'object' ),
							'action_rows' => array( 'type' => 'object' ),
							'chunks'      => array( 'type' => 'array' ),
							'summary'     => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'workspaceCleanupPlan' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-cleanup-apply',
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
							'limit'  => array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'workspaceCleanupApply' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/workspace-cleanup-until-empty',
				array(
					'label'               => 'Run Artifact Cleanup Until Empty',
					'description'         => 'Repeatedly plan and apply DB-backed artifact cleanup until no safe rows remain, a budget is hit, or candidates repeat.',
					'category'            => 'datamachine-code-workspace',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'mode'           => array( 'type' => 'string' ),
							'force'          => array( 'type' => 'boolean' ),
							'limit'          => array( 'type' => 'integer' ),
							'max_passes'     => array( 'type' => 'integer' ),
							'budget_seconds' => array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'workspaceCleanupUntilEmpty' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			foreach ( array( 'status', 'evidence', 'resume', 'cancel' ) as $cleanup_operation ) {
				AbilityRegistry::register(
					'datamachine-code/workspace-cleanup-' . $cleanup_operation,
					array(
						'label'               => 'Workspace Cleanup ' . ucfirst($cleanup_operation),
						'description'         => 'Operate on a DB-backed workspace cleanup run by run_id.',
						'category'            => 'datamachine-code-workspace',
						'input_schema'        => array(
							'type'       => 'object',
							'required'   => array( 'run_id' ),
							'properties' => array(
								'run_id' => array( 'type' => 'string' ),
								'force'  => array( 'type' => 'boolean' ),
								'limit'  => array( 'type' => 'integer' ),
							),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( self::class, 'workspaceCleanup' . ucfirst($cleanup_operation) ),
						'permission_callback' => fn() => PermissionHelper::can_manage(),
						'meta'                => array( 'show_in_rest' => false ),
					)
				);
			}
		};

		if ( doing_action('wp_abilities_api_init') ) {
			$register_callback();
		} else {
			add_action('wp_abilities_api_init', $register_callback);
		}
	}

	// =========================================================================
	// Ability callbacks
	// =========================================================================

	/**
	 * Get workspace path, optionally ensuring the directory exists.
	 *
	 * @param  array $input Input parameters.
	 * @return array Result.
	 */
	public static function getPath( array $input ): array|\WP_Error {
		if ( ! empty($input['name']) ) {
			$result = self::showRepo(array( 'name' => (string) $input['name'] ));
			if ( is_wp_error($result) ) {
				return $result;
			}

			$path = (string) ( $result['path'] ?? '' );
			return array(
				'success' => true,
				'path'    => $path,
				'exists'  => '' !== $path && ( RemoteWorkspaceBackend::should_handle() || is_dir($path) ),
			);
		}

		$workspace = new Workspace();

		if ( ! empty($input['ensure']) ) {
			$result = $workspace->ensure_exists();
			if ( is_wp_error($result) ) {
				return $result;
			}

			return array(
				'success' => $result['success'],
				'path'    => $workspace->get_path(),
				'exists'  => $result['success'],
				'created' => $result['created'] ?? false,
			);
		}

		return array(
			'success' => true,
			'path'    => $workspace->get_path(),
			'exists'  => is_dir($workspace->get_path()),
		);
	}

	/**
	 * List workspace repos.
	 *
	 * @param  array $input Input parameters.
	 * @return array Result.
	 */
	public static function listRepos( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$repo      = isset($input['repo']) ? (string) $input['repo'] : null;
		$type      = isset($input['type']) ? (string) $input['type'] : null;
		return $workspace->list_repos($repo, $type);
	}

	/**
	 * Inspect workspace runtime capabilities.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>
	 */
	public static function getCapabilities( array $input ): array {   // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$workspace   = new Workspace();
		$diagnostic  = GitRunner::diagnose();
		$backend     = RemoteWorkspaceBackend::should_handle() ? 'github_api' : 'local_git';
		$remediation = RuntimeCapabilities::workspace_remediation();

		return array_merge(
			$diagnostic,
			array(
				'success'        => true,
				'backend'        => $backend,
				'local_backend'  => $diagnostic['backend'] ?? 'local_git',
				'workspace_path' => $workspace->get_path(),
				'remediation'    => $remediation,
			)
		);
	}

	/**
	 * Show detailed repo info.
	 *
	 * @param  array $input Input parameters with 'name'.
	 * @return array Result.
	 */
	public static function showRepo( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		if ( RemoteWorkspaceBackend::should_handle() ) {
			$local_result = self::showLocalWorkspaceHandleIfPresent($workspace, (string) ( $input['name'] ?? '' ));
			if ( null !== $local_result ) {
				return $local_result;
			}
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->show($input['name'] ?? '');
			if ( ! self::shouldFallbackToLocalWorkspace($result) ) {
				return $result;
			}
		}

		return $workspace->show_repo($input['name'] ?? '');
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * @param  array $input Input parameters with 'repo', 'path', optional 'max_size', 'offset', 'limit'.
	 * @return array Result.
	 */
	public static function readFile( array $input ): array|\WP_Error {
		$input        = self::normalize_mounted_workspace_path_input($input, array( 'repo' ));
		$workspace    = new Workspace();
		$handle_check = $workspace->require_explicit_workspace_handle($input['repo'] ?? '');
		if ( is_wp_error($handle_check) ) {
			return $handle_check;
		}
		$reader = new WorkspaceReader($workspace);
		if ( RemoteWorkspaceBackend::should_handle() && null !== self::showLocalWorkspaceHandleIfPresent($workspace, (string) ( $input['repo'] ?? '' )) ) {
			return $reader->read_file(
				$input['repo'] ?? '',
				$input['path'] ?? '',
				isset($input['max_size']) ? (int) $input['max_size'] : Workspace::MAX_READ_SIZE,
				isset($input['offset']) ? (int) $input['offset'] : null,
				isset($input['limit']) ? (int) $input['limit'] : null,
				! empty($input['allow_stale_primary'])
			);
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->read_file(
				$input['repo'] ?? '',
				$input['path'] ?? '',
				isset($input['max_size']) ? (int) $input['max_size'] : Workspace::MAX_READ_SIZE,
				isset($input['offset']) ? (int) $input['offset'] : null,
				isset($input['limit']) ? (int) $input['limit'] : null
			);
			if ( ! self::shouldFallbackToLocalWorkspace($result) ) {
				return $result;
			}
		}

		return $reader->read_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			isset($input['max_size']) ? (int) $input['max_size'] : Workspace::MAX_READ_SIZE,
			isset($input['offset']) ? (int) $input['offset'] : null,
			isset($input['limit']) ? (int) $input['limit'] : null,
			! empty($input['allow_stale_primary'])
		);
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * @param  array $input Input parameters with 'repo', optional 'path'.
	 * @return array Result.
	 */
	public static function listDirectory( array $input ): array|\WP_Error {
		$input        = self::normalize_mounted_workspace_path_input($input, array( 'repo' ));
		$workspace    = new Workspace();
		$handle_check = $workspace->require_explicit_workspace_handle($input['repo'] ?? '');
		if ( is_wp_error($handle_check) ) {
			return $handle_check;
		}
		$reader = new WorkspaceReader($workspace);
		if ( RemoteWorkspaceBackend::should_handle() && null !== self::showLocalWorkspaceHandleIfPresent($workspace, (string) ( $input['repo'] ?? '' )) ) {
			return $reader->list_directory(
				$input['repo'] ?? '',
				$input['path'] ?? null,
				! empty($input['allow_stale_primary'])
			);
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->list_directory(
				$input['repo'] ?? '',
				$input['path'] ?? null
			);
			if ( ! self::shouldFallbackToLocalWorkspace($result) ) {
				return $result;
			}
		}

		return $reader->list_directory(
			$input['repo'] ?? '',
			$input['path'] ?? null,
			! empty($input['allow_stale_primary'])
		);
	}

	/**
	 * Search workspace files.
	 *
	 * @param  array $input Input parameters with 'repo', 'pattern', optional 'path', 'include', 'max_results', 'context_lines'.
	 * @return array Result.
	 */
	public static function grepFiles( array $input ): array|\WP_Error {
		$input        = self::normalize_mounted_workspace_path_input($input, array( 'repo' ));
		$workspace    = new Workspace();
		$handle_check = $workspace->require_explicit_workspace_handle($input['repo'] ?? '');
		if ( is_wp_error($handle_check) ) {
			return $handle_check;
		}
		$reader = new WorkspaceReader($workspace);
		if ( RemoteWorkspaceBackend::should_handle() && null !== self::showLocalWorkspaceHandleIfPresent($workspace, (string) ( $input['repo'] ?? '' )) ) {
			return $reader->grep(
				$input['repo'] ?? '',
				$input['pattern'] ?? '',
				$input['path'] ?? null,
				$input['include'] ?? null,
				isset($input['max_results']) ? (int) $input['max_results'] : 100,
				isset($input['context_lines']) ? (int) $input['context_lines'] : 0,
				! empty($input['allow_stale_primary'])
			);
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->grep(
				$input['repo'] ?? '',
				$input['pattern'] ?? '',
				$input['path'] ?? null,
				$input['include'] ?? null,
				isset($input['max_results']) ? (int) $input['max_results'] : 100,
				isset($input['context_lines']) ? (int) $input['context_lines'] : 0
			);
			if ( ! self::shouldFallbackToLocalWorkspace($result) ) {
				return $result;
			}
		}

		return $reader->grep(
			$input['repo'] ?? '',
			$input['pattern'] ?? '',
			$input['path'] ?? null,
			$input['include'] ?? null,
			isset($input['max_results']) ? (int) $input['max_results'] : 100,
			isset($input['context_lines']) ? (int) $input['context_lines'] : 0,
			! empty($input['allow_stale_primary'])
		);
	}

	/**
	 * Add model-facing next-tool guidance to remote workspace ability results.
	 *
	 * RemoteWorkspaceBackend returns domain state only; this adapter is the
	 * explicitly agent-facing layer that preserves CLI/tool loop guidance.
	 *
	 * @param string                 $operation Remote backend operation name.
	 * @param array<string,mixed>|\WP_Error $result Backend result.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function decorate_remote_workspace_result( string $operation, array|\WP_Error $result ): array|\WP_Error {
		if ( is_wp_error($result) ) {
			return $result;
		}

		$guidance = self::remote_workspace_guidance($operation, $result);
		if ( empty($guidance) ) {
			return $result;
		}

		return array_merge($result, $guidance);
	}

	/**
	 * @param array<string,mixed> $result Backend result.
	 * @return array<string,mixed>
	 */
	private static function remote_workspace_guidance( string $operation, array $result ): array {
		$name   = (string) ( $result['name'] ?? '' );
		$handle = (string) ( $result['handle'] ?? $name );

		switch ( $operation ) {
			case 'clone_repo':
				return array(
					'conversation_state' => 'incomplete',
					'next_required_tool' => 'workspace_worktree_add',
					'next_required_args' => array( 'repo' => $name ),
				);

			case 'worktree_add':
				return array(
					'conversation_state' => 'incomplete',
					'next_required_tool' => 'workspace_read or workspace_edit or workspace_write',
					'next_required_args' => array( 'repo' => $handle ),
				);

			case 'write_file':
			case 'edit_file':
				return array(
					'conversation_state' => 'incomplete',
					'next_required_tool' => 'workspace_git_status',
					'next_required_args' => array( 'name' => $name ),
				);

			case 'git_status':
				return array(
					'conversation_state' => 'incomplete',
					'next_required_tool' => (int) ( $result['dirty'] ?? 0 ) > 0 ? 'workspace_git_commit' : 'workspace_edit or workspace_write',
					'next_required_args' => array( 'name' => $name ),
				);

			case 'git_commit':
				return array(
					'conversation_state' => 'incomplete',
					'next_required_tool' => 'workspace_git_push',
					'next_required_args' => array(
						'name'   => $name,
						'branch' => (string) ( $result['branch'] ?? '' ),
					),
				);

			case 'git_push':
				return array(
					'conversation_state' => 'incomplete',
					'next_required_tool' => 'create_github_pull_request',
					'next_required_args' => array(
						'repo' => (string) ( $result['repo'] ?? $result['github_repo'] ?? '' ),
						'head' => (string) ( $result['branch'] ?? '' ),
					),
				);
		}

		return array();
	}

	/**
	 * Shared schema for primary checkout freshness metadata.
	 *
	 * @return array<string,mixed>
	 */
	private static function primaryFreshnessSchema(): array {
		return array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'status'            => array(
					'type'        => 'string',
					'description' => 'One of current, stale, diverged, ahead, detached, no_upstream, or unknown.',
				),
				'branch'            => array( 'type' => array( 'string', 'null' ) ),
				'upstream'          => array( 'type' => array( 'string', 'null' ) ),
				'behind'            => array( 'type' => array( 'integer', 'null' ) ),
				'ahead'             => array( 'type' => array( 'integer', 'null' ) ),
				'detached'          => array( 'type' => 'boolean' ),
				'local_refs'        => array( 'type' => 'boolean' ),
				'fetch_checked'     => array( 'type' => 'boolean' ),
				'suggested_command' => array( 'type' => array( 'string', 'null' ) ),
			),
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * @param  array $input Input parameters with 'url', optional 'name'.
	 * @return array Result.
	 */
	public static function cloneRepo( array $input ): array|\WP_Error {
		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->clone_repo(
				$input['url'] ?? '',
				$input['name'] ?? null
			);
			return self::decorate_remote_workspace_result('clone_repo', $result);
		}

		$workspace = new Workspace();
		$result    = $workspace->clone_repo(
			$input['url'] ?? '',
			$input['name'] ?? null,
			array(
				'full'                   => (bool) ( $input['full'] ?? false ),
				'auth_token_env'         => $input['auth_token_env'] ?? '',
				'allow_duplicate_remote' => ! empty($input['allow_duplicate_remote']),
			)
		);

		if ( is_wp_error($result) && 'datamachine_workspace_git_unavailable' === $result->get_error_code() ) {
			$remote_result = ( new RemoteWorkspaceBackend() )->clone_repo(
				$input['url'] ?? '',
				$input['name'] ?? null
			);
			return self::decorate_remote_workspace_result('clone_repo', $remote_result);
		}

		return $result;
	}

	/**
	 * Register read-only context repositories for workspace tools.
	 *
	 * @param  array $input Input parameters with repositories list.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function registerContextRepositories( array $input ): array|\WP_Error {
		$repositories = self::normalizeContextRepositories($input['repositories'] ?? array());
		if ( is_wp_error($repositories) ) {
			return $repositories;
		}

		if ( ! function_exists('update_option') ) {
			return new \WP_Error('context_repositories_storage_unavailable', 'Context repositories cannot be registered because option storage is unavailable.', array( 'status' => 500 ));
		}

		update_option('datamachine_code_context_repositories', $repositories, false);

		return array(
			'success'      => true,
			'access'       => 'readonly',
			'count'        => count($repositories),
			'repositories' => array_values($repositories),
		);
	}

	/**
	 * @param mixed $repositories Raw repository specs.
	 * @return array<string,array<string,mixed>>|\WP_Error
	 */
	private static function normalizeContextRepositories( mixed $repositories ): array|\WP_Error {
		if ( ! is_array($repositories) ) {
			return new \WP_Error('invalid_context_repositories', 'repositories must be an array.', array( 'status' => 400 ));
		}

		$normalized = array();
		foreach ( $repositories as $repository ) {
			if ( ! is_array($repository) ) {
				continue;
			}

			$repo = trim( (string) ( $repository['repo'] ?? '' ));
			if ( '' === $repo ) {
				return new \WP_Error('invalid_context_repository', 'Each context repository requires a repo value.', array( 'status' => 400 ));
			}

			$alias = sanitize_key( (string) ( $repository['alias'] ?? basename($repo) ) );
			if ( '' === $alias ) {
				return new \WP_Error('invalid_context_repository_alias', sprintf('Could not derive a context repository alias for %s.', $repo), array( 'status' => 400 ));
			}

			$paths = array();
			if ( is_array($repository['paths'] ?? null) ) {
				foreach ( $repository['paths'] as $path ) {
					$path = trim( (string) $path );
					if ( '' !== $path ) {
						$paths[] = $path;
					}
				}
			}

			$normalized[ $alias ] = array(
				'alias'  => $alias,
				'repo'   => $repo,
				'ref'    => trim( (string) ( $repository['ref'] ?? '' )),
				'target' => $alias,
				'paths'  => array_values(array_unique($paths)),
			);
		}

		return $normalized;
	}

	/**
	 * Adopt an existing primary checkout already under the workspace root.
	 *
	 * @param  array $input Input parameters with 'path', optional 'name'.
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
	 * @param  array $input Input parameters with 'name'.
	 * @return array Result.
	 */
	public static function removeRepo( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->remove_repo($input['name'] ?? '');
	}

	/**
	 * Write (create or overwrite) a file in a workspace repo.
	 *
	 * @param  array $input Input parameters with 'repo', 'path', 'content'.
	 * @return array Result.
	 */
	public static function writeFile( array $input ): array|\WP_Error {
		$input        = self::normalize_mounted_workspace_path_input($input, array( 'repo' ));
		$workspace    = new Workspace();
		$handle_check = $workspace->ensure_workspace_mutation_allowed($input['repo'] ?? '', ! empty($input['allow_primary_mutation']));
		if ( is_wp_error($handle_check) ) {
			return $handle_check;
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->write_file(
				$input['repo'] ?? '',
				$input['path'] ?? '',
				$input['content'] ?? ''
			);
			return self::decorate_remote_workspace_result('write_file', $result);
		}

		$writer = new WorkspaceWriter($workspace);

		return $writer->write_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			$input['content'] ?? '',
			! empty($input['allow_primary_mutation'])
		);
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * @param  array $input Input parameters with 'repo', 'path', 'old_string', 'new_string', optional 'replace_all'.
	 * @return array Result.
	 */
	public static function editFile( array $input ): array|\WP_Error {
		$input        = self::normalize_mounted_workspace_path_input($input, array( 'repo' ));
		$workspace    = new Workspace();
		$handle_check = $workspace->ensure_workspace_mutation_allowed($input['repo'] ?? '', ! empty($input['allow_primary_mutation']));
		if ( is_wp_error($handle_check) ) {
			return $handle_check;
		}
		$old_string = (string) ( $input['old_string'] ?? $input['search'] ?? $input['old'] ?? '' );
		$new_string = (string) ( $input['new_string'] ?? $input['replace'] ?? $input['new'] ?? '' );

		if ( '' === $old_string ) {
			return new \WP_Error('missing_old_string', 'old_string is required.', array( 'status' => 400 ));
		}

		if ( ! array_key_exists('new_string', $input) && ! array_key_exists('replace', $input) && ! array_key_exists('new', $input) ) {
			return new \WP_Error('missing_new_string', 'new_string is required.', array( 'status' => 400 ));
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->edit_file(
				$input['repo'] ?? '',
				$input['path'] ?? '',
				$old_string,
				$new_string,
				! empty($input['replace_all'])
			);
			return self::decorate_remote_workspace_result('edit_file', $result);
		}

		$writer = new WorkspaceWriter($workspace);

		return $writer->edit_file(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			$old_string,
			$new_string,
			! empty($input['replace_all']),
			! empty($input['allow_primary_mutation'])
		);
	}

	/**
	 * Apply a unified diff to a workspace repo.
	 *
	 * @param  array $input Input parameters with 'repo', 'patch', optional 'allow_primary_mutation'.
	 * @return array Result.
	 */
	public static function applyPatch( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$writer    = new WorkspaceWriter($workspace);

		return $writer->apply_patch(
			$input['repo'] ?? '',
			$input['patch'] ?? '',
			! empty($input['allow_primary_mutation'])
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function gitRebaseOutputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'state'     => array(
					'type' => 'string',
					'enum' => array( 'clean', 'conflicting' ),
				),
				'conflicts' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'path'                 => array( 'type' => 'string' ),
							'conflict_markers'     => array( 'type' => 'integer' ),
							'has_conflict_markers' => array( 'type' => 'boolean' ),
						),
					),
				),
				'applied'   => array( 'type' => 'integer' ),
				'pending'   => array( 'type' => 'integer' ),
				'head_sha'  => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Get git status details for a workspace repository.
	 *
	 * @param  array $input Input parameters with 'name'.
	 * @return array
	 */
	public static function gitStatus( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		if ( RemoteWorkspaceBackend::should_handle() && null !== self::showLocalWorkspaceHandleIfPresent($workspace, (string) ( $input['name'] ?? '' )) ) {
			return $workspace->git_status($input['name'] ?? '');
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->git_status($input['name'] ?? '');
			if ( ! self::shouldFallbackToLocalWorkspace($result) ) {
				return self::decorate_remote_workspace_result('git_status', $result);
			}
		}

		return $workspace->git_status($input['name'] ?? '');
	}

	/**
	 * Pull latest changes for a workspace repository.
	 *
	 * @param  array $input Input parameters with 'name', optional 'allow_dirty'.
	 * @return array
	 */
	public static function gitPull( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_pull(
			$input['name'] ?? '',
			! empty($input['allow_dirty']),
			! empty($input['allow_primary_refresh']) || ! empty($input['allow_primary_mutation']),
			(string) ( $input['remote'] ?? 'origin' ),
			isset($input['branch']) ? (string) $input['branch'] : null
		);
	}

	/**
	 * Stage paths in a workspace repository.
	 *
	 * @param  array $input Input parameters with 'name', 'paths'.
	 * @return array
	 */
	public static function gitAdd( array $input ): array|\WP_Error {
		if ( RemoteWorkspaceBackend::should_handle() ) {
			$paths = $input['paths'] ?? array();
			return ( new RemoteWorkspaceBackend() )->git_add(
				$input['name'] ?? '',
				is_array($paths) ? $paths : array()
			);
		}

		$workspace = new Workspace();
		$paths     = $input['paths'] ?? array();

		if ( ! is_array($paths) ) {
			$paths = array();
		}

		return $workspace->git_add($input['name'] ?? '', $paths, ! empty($input['allow_primary_mutation']));
	}

	/**
	 * Delete a tracked or untracked path from a workspace repository.
	 *
	 * @param  array $input Input parameters with 'repo', 'path', optional 'recursive', 'allow_primary_mutation'.
	 * @return array
	 */
	public static function deletePath( array $input ): array|\WP_Error {
		$workspace = new Workspace();

		return $workspace->delete_path(
			$input['repo'] ?? '',
			$input['path'] ?? '',
			! empty($input['recursive']),
			! empty($input['allow_primary_mutation'])
		);
	}

	/**
	 * Commit staged changes in a workspace repository.
	 *
	 * @param  array $input Input parameters with 'name', 'message'.
	 * @return array
	 */
	public static function gitCommit( array $input ): array|\WP_Error {
		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->git_commit(
				$input['name'] ?? '',
				$input['message'] ?? ''
			);
			return self::decorate_remote_workspace_result('git_commit', $result);
		}

		$workspace = new Workspace();
		return $workspace->git_commit(
			$input['name'] ?? '',
			$input['message'] ?? '',
			! empty($input['allow_dangerous_primary_mutation'])
		);
	}

	/**
	 * Push commits for a workspace repository.
	 *
	 * @param  array $input Input parameters with 'name', optional 'remote', 'branch'.
	 * @return array
	 */
	public static function gitPush( array $input ): array|\WP_Error {
		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->git_push(
				$input['name'] ?? '',
				$input['remote'] ?? 'origin',
				$input['branch'] ?? null
			);
			return self::decorate_remote_workspace_result('git_push', $result);
		}

		$workspace = new Workspace();
		return $workspace->git_push(
			$input['name'] ?? '',
			$input['remote'] ?? 'origin',
			$input['branch'] ?? null,
			! empty($input['allow_dangerous_primary_mutation']),
			! empty($input['force_with_lease']),
			$input['expected_sha'] ?? null
		);
	}

	/**
	 * Publish runner-owned workspace changes through one canonical DMC API.
	 *
	 * @param  array<string,mixed> $input Publication input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publishRunnerWorkspace( array $input ): array|\WP_Error {
		return ( new RunnerWorkspacePublisher() )->publish($input);
	}

	/**
	 * Run a bounded command against a runner-owned workspace.
	 *
	 * @param  array<string,mixed> $input Command input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function runRunnerWorkspaceCommand( array $input ): array|\WP_Error {
		$handle  = trim( (string) ( $input['workspace_handle'] ?? $input['name'] ?? $input['repo'] ?? '' ) );
		$command = trim( (string) ( $input['command'] ?? '' ) );
		$timeout = isset($input['timeout']) ? (int) $input['timeout'] : (int) ( $input['timeout_seconds'] ?? 300 );
		$env     = isset($input['env']) && is_array($input['env']) ? $input['env'] : array();

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->run_command(
				$handle,
				$command,
				(string) ( $input['description'] ?? '' ),
				$timeout,
				$env,
				isset($input['cwd']) ? (string) $input['cwd'] : null
			);
			return self::decorate_remote_workspace_result('run_runner_workspace_command', $result);
		}

		$workspace = new Workspace();
		return $workspace->run_runner_workspace_command(
			$handle,
			$command,
			(string) ( $input['description'] ?? '' ),
			$timeout,
			$env,
			isset($input['cwd']) ? (string) $input['cwd'] : null
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function runnerWorkspaceCommandInputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'workspace_handle', 'command' ),
			'properties' => array(
				'workspace_handle' => array(
					'type'        => 'string',
					'description' => 'Workspace handle: <repo>, <repo>@<branch-slug>, or a runner-provided alias.',
				),
				'name'             => array(
					'type'        => 'string',
					'description' => 'Alias for workspace_handle.',
				),
				'repo'             => array(
					'type'        => 'string',
					'description' => 'Alias for workspace_handle.',
				),
				'command'          => array(
					'type'        => 'string',
					'description' => 'Shell command to run inside the workspace.',
				),
				'description'      => array(
					'type'        => 'string',
					'description' => 'Human-readable reason for the command.',
				),
				'timeout'          => array(
					'type'        => 'integer',
					'description' => 'Timeout in seconds. Defaults to 300 and is capped at 1800.',
				),
				'timeout_seconds'  => array(
					'type'        => 'integer',
					'description' => 'Alias for timeout.',
				),
				'cwd'              => array(
					'type'        => 'string',
					'description' => 'Optional relative working directory inside the workspace.',
				),
				'env'              => array(
					'type'                 => 'object',
					'description'          => 'Optional string environment variables for the command.',
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'context'          => array(
					'type'        => 'object',
					'description' => 'Optional caller context carried for observability.',
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function runnerWorkspaceCommandOutputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'kind'         => array( 'type' => 'string' ),
				'backend'      => array( 'type' => 'string' ),
				'failure_type' => array( 'type' => array( 'string', 'null' ) ),
				'name'         => array( 'type' => 'string' ),
				'repo'         => array( 'type' => 'string' ),
				'path'         => array( 'type' => array( 'string', 'null' ) ),
				'command'      => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
				'exit_code'    => array( 'type' => array( 'integer', 'null' ) ),
				'stdout'       => array( 'type' => 'string' ),
				'stderr'       => array( 'type' => 'string' ),
				'elapsed_ms'   => array( 'type' => 'integer' ),
				'timed_out'    => array( 'type' => 'boolean' ),
				'workspace'    => array( 'type' => 'object' ),
				'message'      => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function runnerWorkspacePublishInputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'workspace_handle', 'target_repo', 'commit_message', 'pr_title' ),
			'properties' => array(
				'workspace_handle'       => array(
					'type'        => 'string',
					'description' => 'Workspace handle: <repo> or <repo>@<branch-slug>.',
				),
				'target_repo'            => array(
					'type'        => 'string',
					'description' => 'GitHub repository in owner/repo format for the pull request.',
				),
				'base'                   => array(
					'type'        => 'string',
					'description' => 'Base branch/ref for the pull request.',
				),
				'base_branch'            => array(
					'type'        => 'string',
					'description' => 'Alias for base.',
				),
				'head'                   => array(
					'type'        => 'string',
					'description' => 'Pull request head branch or owner:branch.',
				),
				'head_branch'            => array(
					'type'        => 'string',
					'description' => 'Head branch to push and publish.',
				),
				'branch'                 => array(
					'type'        => 'string',
					'description' => 'Alias for head_branch.',
				),
				'commit_message'         => array(
					'type'        => 'string',
					'description' => 'Commit message for workspace changes.',
				),
				'pr_title'               => array(
					'type'        => 'string',
					'description' => 'Pull request title.',
				),
				'pr_body'                => array(
					'type'        => 'string',
					'description' => 'Pull request body.',
				),
				'labels'                 => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Optional pull request labels.',
				),
				'draft'                  => array(
					'type'        => 'boolean',
					'description' => 'Open the pull request as a draft.',
				),
				'maintainer_can_modify'  => array(
					'type'        => 'boolean',
					'description' => 'Allow maintainers to modify the pull request branch.',
				),
				'evidence_context'       => array(
					'type'        => 'object',
					'description' => 'Runner evidence metadata appended to the PR body and returned in the result.',
				),
				'artifact_context'       => array(
					'type'        => 'object',
					'description' => 'Alias for evidence_context.',
				),
				'run_artifacts'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => 'Run artifacts forwarded to PR artifact egress handling.',
				),
				'run_artifact_policy'    => array(
					'type'        => 'object',
					'description' => 'Run artifact egress policy forwarded to PR creation.',
				),
				'paths'                  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Workspace paths to stage before commit. Defaults to all changes.',
				),
				'remote'                 => array(
					'type'        => 'string',
					'description' => 'Git remote name for local workspaces. Default origin for same-repo heads or the head owner for owner:branch heads.',
				),
				'push_remote'            => array(
					'type'        => 'string',
					'description' => 'Explicit git remote to push before opening the pull request. Overrides remote.',
				),
				'push_branch'            => array(
					'type'        => 'string',
					'description' => 'Explicit branch name to push. Defaults to the pull request head branch without owner prefix.',
				),
				'allow_primary_mutation' => array(
					'type'        => 'boolean',
					'description' => 'Permit publication from a primary checkout. Default false.',
				),
				'force_with_lease'       => array(
					'type'        => 'boolean',
					'description' => 'Use --force-with-lease for local workspace push.',
				),
				'expected_sha'           => array(
					'type'        => 'string',
					'description' => 'Expected remote branch SHA for --force-with-lease.',
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function runnerWorkspacePublishOutputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'kind'         => array( 'type' => 'string' ),
				'workspace'    => array( 'type' => 'object' ),
				'branch'       => array( 'type' => 'object' ),
				'commit'       => array( 'type' => 'object' ),
				'pull_request' => array( 'type' => 'object' ),
				'evidence'     => array( 'type' => 'object' ),
				'message'      => array( 'type' => 'string' ),
				'failure_type' => array( 'type' => 'string' ),
				'error'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Rebase a workspace repository.
	 *
	 * @param  array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public static function gitRebase( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_rebase(
			$input['name'] ?? '',
			$input['onto'] ?? null,
			$input['strategy_option'] ?? null,
			! empty($input['continue']),
			! empty($input['allow_dangerous_primary_mutation'])
		);
	}

	/**
	 * Reset a workspace repository.
	 *
	 * @param  array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public static function gitReset( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_reset(
			$input['name'] ?? '',
			$input['mode'] ?? 'mixed',
			$input['target'] ?? null,
			! empty($input['allow_destructive']),
			! empty($input['allow_dangerous_primary_mutation'])
		);
	}

	/**
	 * Return pull request freshness state.
	 *
	 * @param  array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public static function prStatus( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->pr_status(
			$input['name'] ?? '',
			$input['pr'] ?? null,
			$input['branch'] ?? null
		);
	}

	/**
	 * Bring a pull request branch up to date.
	 *
	 * @param  array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public static function prRebase( array $input ): array|\WP_Error {
		$workspace  = new Workspace();
		$drop_paths = $input['drop_paths'] ?? array();
		if ( ! is_array($drop_paths) ) {
			$drop_paths = array();
		}

		return $workspace->pr_rebase(
			$input['name'] ?? '',
			$input['pr'] ?? null,
			! empty($input['squash']),
			$drop_paths,
			! empty($input['allow_dangerous_primary_mutation'])
		);
	}

	/**
	 * Add a worktree for a branch.
	 *
	 * @param  array $input Input parameters with 'repo', 'branch', optional 'from'.
	 * @return array
	 */
	public static function worktreeAdd( array $input ): array|\WP_Error {
		// Default inject_context=true; only false when explicitly provided.
		$inject_context = array_key_exists('inject_context', $input) ? (bool) $input['inject_context'] : true;
		// Default bootstrap=true; only false when explicitly provided.
		$bootstrap = array_key_exists('bootstrap', $input) ? (bool) $input['bootstrap'] : true;
		// Default allow_stale=false (gate enforced); only true when explicitly opted in.
		$allow_stale = array_key_exists('allow_stale', $input) ? (bool) $input['allow_stale'] : false;
		// Default allow_unverified_freshness=false (fetch-failure gate enforced).
		$allow_unverified_freshness = array_key_exists('allow_unverified_freshness', $input) ? (bool) $input['allow_unverified_freshness'] : false;
		// Default rebase_base=false; only true when explicitly requested.
		$rebase_base = array_key_exists('rebase_base', $input) ? (bool) $input['rebase_base'] : false;
		$force       = ! empty($input['force']);
		$task        = array();
		if ( isset($input['task_url']) && '' !== trim( (string) $input['task_url']) ) {
			$task['task_url'] = (string) $input['task_url'];
		}
		if ( isset($input['task_ref']) && '' !== trim( (string) $input['task_ref']) ) {
			$task['task_ref'] = (string) $input['task_ref'];
		}

		$workspace = new Workspace();
		if ( RemoteWorkspaceBackend::should_handle() && self::hasLocalPrimaryCheckout($workspace, (string) ( $input['repo'] ?? '' )) ) {
			return $workspace->worktree_add(
				$input['repo'] ?? '',
				$input['branch'] ?? '',
				$input['from'] ?? null,
				$inject_context,
				$bootstrap,
				$allow_stale,
				$rebase_base,
				$force,
				$task,
				$allow_unverified_freshness
			);
		}

		if ( RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->worktree_add(
				$input['repo'] ?? '',
				$input['branch'] ?? '',
				$input['from'] ?? null
			);
			if ( ! self::shouldFallbackToLocalWorkspace($result) ) {
				return self::decorate_remote_workspace_result('worktree_add', $result);
			}
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
			$task,
			$allow_unverified_freshness
		);
	}

	/**
	 * Whether a repo argument resolves to an editable local primary checkout.
	 */
	private static function hasLocalPrimaryCheckout( Workspace $workspace, string $repo ): bool {
		$result = self::showLocalWorkspaceHandleIfPresent($workspace, $repo);
		if ( null === $result ) {
			return false;
		}

		$path = (string) ( $result['path'] ?? '' );
		return ! str_contains(basename($path), '@');
	}

	/**
	 * Return local workspace details for an existing local handle, if present.
	 */
	private static function showLocalWorkspaceHandleIfPresent( Workspace $workspace, string $handle ): ?array {
		if ( '' === trim($handle) ) {
			return null;
		}

		$result = $workspace->show_repo($handle);
		if ( is_wp_error($result) ) {
			return null;
		}

		$path = (string) ( $result['path'] ?? '' );
		if ( '' === $path || str_starts_with($path, 'github://') ) {
			return null;
		}

		return $result;
	}

	/**
	 * Whether a remote-backend miss should be retried against local workspace discovery.
	 */
	private static function shouldFallbackToLocalWorkspace( mixed $result ): bool {
		return is_wp_error($result) && in_array($result->get_error_code(), array( 'remote_workspace_repo_not_found', 'unsupported_remote_workspace_repo_argument' ), true);
	}

	/**
	 * Refresh a worktree's injected context from the originating site.
	 *
	 * @param  array $input Input parameters with 'handle'.
	 * @return array|\WP_Error
	 */
	public static function worktreeRefreshContext( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_refresh_context($input['handle'] ?? '');
	}

	/**
	 * Attach lifecycle metadata to a worktree.
	 *
	 * @param  array $input Input parameters with handle, state, optional pr.
	 * @return array|\WP_Error
	 */
	public static function worktreeFinalize( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_finalize(
			$input['handle'] ?? '',
			$input['state'] ?? '',
			isset($input['pr']) ? (string) $input['pr'] : null
		);
	}

	/**
	 * List worktrees in the workspace.
	 *
	 * @param  array $input Input parameters with optional 'repo'.
	 * @return array
	 */
	public static function worktreeList( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$repo      = isset($input['repo']) && '' !== trim( (string) $input['repo'])
		? (string) $input['repo']
		: null;
		$state     = isset($input['state']) && '' !== trim( (string) $input['state'])
		? (string) $input['state']
		: null;

		// Default to the cheap listing on the ability surface so MCP/REST/CLI callers
		// don't pay the per-worktree git status + du cost on huge workspaces.
		// Internal PHP callers can still call worktree_list() directly with full probes.
		$opts = array(
			'include_status' => array_key_exists('include_status', $input) ? (bool) $input['include_status'] : false,
			'include_disk'   => array_key_exists('include_disk', $input) ? (bool) $input['include_disk'] : false,
			'handle'         => isset($input['handle']) ? (string) $input['handle'] : '',
		);

		return $workspace->worktree_list($repo, $state, $opts);
	}

	/**
	 * Build a non-destructive workspace hygiene report.
	 *
	 * @param  array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public static function workspaceHygieneReport( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array();
		if ( array_key_exists('include_cleanup', $input) ) {
			$opts['include_cleanup'] = (bool) $input['include_cleanup'];
		}
		if ( array_key_exists('include_sizes', $input) ) {
			$opts['include_sizes'] = (bool) $input['include_sizes'];
		}
		if ( array_key_exists('include_worktree_status', $input) ) {
			$opts['include_worktree_status'] = (bool) $input['include_worktree_status'];
		}
		if ( array_key_exists('refresh_inventory', $input) ) {
			$opts['refresh_inventory'] = (bool) $input['refresh_inventory'];
		}
		if ( isset($input['size_limit']) ) {
			$opts['size_limit'] = (int) $input['size_limit'];
		}

		return $workspace->workspace_hygiene_report($opts);
	}

	/**
	 * Refresh DB-backed worktree inventory from the filesystem/git view.
	 *
	 * @param  array $input Unused input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeInventoryRefresh( array $input ): array|\WP_Error {   // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$workspace = new Workspace();
		return $workspace->worktree_inventory_refresh();
	}

	/**
	 * Prune missing_path inventory rows whose on-disk path is still absent.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeInventoryPruneMissing( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->worktree_inventory_prune_missing(
			array(
				'dry_run' => ! empty($input['dry_run']),
				'force'   => ! empty($input['force']),
			)
		);
	}

	/**
	 * Schedule a background workspace cleanup system task.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupRun( array $input ): array|\WP_Error {
		if ( ! empty($input['dry_run']) ) {
			return new \WP_Error('workspace_cleanup_run_no_dry_run', 'Background cleanup scheduling does not accept dry_run. Use the synchronous workspace cleanup review abilities instead.', array( 'status' => 400 ));
		}

		$mode = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) ( $input['mode'] ?? 'retention' )));
		$map  = array(
			'inventory'       => array(
				'task_type' => 'workspace_hygiene_report',
				'params'    => array(
					'include_cleanup'         => true,
					'include_sizes'           => false,
					'include_worktree_status' => false,
				),
			),
			'artifacts'       => array(
				'task_type' => 'workspace_retention_cleanup',
				'params'    => array(
					'dry_run'          => false,
					'artifact_cleanup' => true,
					'worktree_cleanup' => false,
					'skip_github'      => true,
				),
			),
			'stale-worktrees' => array(
				'task_type' => 'workspace_retention_cleanup',
				'params'    => array(
					'dry_run'             => false,
					'artifact_cleanup'    => false,
					'worktree_cleanup'    => true,
					'skip_github'         => true,
					'worktree_older_than' => '14d',
					'worktree_stale_only' => true,
				),
			),
			'retention'       => array(
				'task_type' => 'workspace_retention_cleanup',
				'params'    => array(
					'dry_run'             => false,
					'artifact_cleanup'    => true,
					'worktree_cleanup'    => true,
					'skip_github'         => true,
					'worktree_older_than' => '14d',
				),
			),
			'emergency'       => array(
				'task_type' => 'workspace_disk_emergency_cleanup',
				'params'    => array(
					'artifact_chunk_size' => 10,
				),
			),
		);

		if ( ! isset($map[ $mode ]) ) {
			return new \WP_Error('unknown_workspace_cleanup_mode', sprintf('Unknown cleanup mode: %s.', $mode), array( 'status' => 400 ));
		}

		if ( ! class_exists('\DataMachine\Engine\Tasks\TaskScheduler') ) {
			return new \WP_Error('task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable.', array( 'status' => 500 ));
		}

		$task_type        = (string) $map[ $mode ]['task_type'];
		$params           = (array) $map[ $mode ]['params'];
		$params['source'] = (string) ( $input['source'] ?? 'workspace_cleanup_ability' );

		if ( isset($input['force']) ) {
			$params['force'] = (bool) $input['force'];
		}
		if ( isset($input['older_than']) && '' !== trim( (string) $input['older_than']) ) {
			$params['worktree_older_than'] = trim( (string) $input['older_than']);
		}
		if ( isset($input['worktree_stale_only']) ) {
			$params['worktree_stale_only'] = (bool) $input['worktree_stale_only'];
		}
		if ( 'artifacts' === $mode ) {
			if ( isset($input['limit']) ) {
				$params['limit'] = (int) $input['limit'];
			}
			if ( isset($input['offset']) ) {
				$params['offset'] = (int) $input['offset'];
			}
			if ( ! empty($input['exhaustive']) ) {
				$params['exhaustive'] = true;
			}
		}

		$context = array();
		if ( isset($input['user_id']) ) {
			$context['user_id'] = (int) $input['user_id'];
		}
		if ( isset($input['agent_id']) ) {
			$context['agent_id'] = (int) $input['agent_id'];
		}
		if ( isset($input['agent_slug']) && '' !== trim( (string) $input['agent_slug']) ) {
			$context['agent_slug'] = sanitize_key( (string) $input['agent_slug']);
		} elseif ( empty($context['agent_id']) ) {
			$agent_slug = self::resolveCleanupAgentSlug( (int) ( $context['user_id'] ?? 0 ) );
			if ( '' !== $agent_slug ) {
				$context['agent_slug'] = $agent_slug;
			}
		}

		$batch_result = \DataMachine\Engine\Tasks\TaskScheduler::scheduleBatch(
			$task_type,
			array( $params ),
			$context
		);
		if ( false === $batch_result ) {
			return new \WP_Error('workspace_cleanup_schedule_failed', 'Failed to schedule workspace cleanup task.', array( 'status' => 500 ));
		}

		$job_ids = is_array($batch_result['job_ids'] ?? null) ? $batch_result['job_ids'] : array();
		$job_id  = (int) ( $job_ids[0] ?? ( $batch_result['batch_job_id'] ?? 0 ) );
		if ( $job_id <= 0 ) {
			return new \WP_Error('workspace_cleanup_schedule_empty', 'Workspace cleanup scheduling returned no job id. Check Data Machine logs for the rejected task reason.', array( 'status' => 500 ));
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
	 * Run the canonical safe workspace cleanup flow.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupSafe( array $input ): array|\WP_Error {
		$orchestrator = new WorkspaceSafeCleanupOrchestrator();
		return $orchestrator->run($input);
	}

	/**
	 * Resolve the active Data Machine agent slug for CLI-scheduled cleanup jobs.
	 *
	 * @param int $user_id Optional user id from the caller context.
	 * @return string
	 */
	private static function resolveCleanupAgentSlug( int $user_id = 0 ): string {
		if ( ! class_exists('\\DataMachine\\Core\\FilesRepository\\DirectoryManager') ) {
			return '';
		}

		try {
			$manager        = new \DataMachine\Core\FilesRepository\DirectoryManager();
			$effective_user = $manager->get_effective_user_id($user_id);
			$agent_slug     = $manager->resolve_agent_slug(array( 'user_id' => $effective_user ));
			return '' !== trim( (string) $agent_slug) ? sanitize_key( (string) $agent_slug) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Remove a worktree.
	 *
	 * @param  array $input Input parameters with 'repo', 'branch', optional 'force'.
	 * @return array
	 */
	public static function worktreeRemove( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$repo      = (string) ( $input['repo'] ?? '' );
		$branch    = (string) ( $input['branch'] ?? '' );
		$handle    = $repo . '@' . $workspace->slugify_branch($branch);

		if ( RemoteWorkspaceBackend::should_handle() && null !== self::showLocalWorkspaceHandleIfPresent($workspace, $handle) ) {
			return $workspace->worktree_remove(
				$repo,
				$branch,
				! empty($input['force'])
			);
		}

		if ( RemoteWorkspaceBackend::has_registered_state() && RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->worktree_remove(
				$repo,
				$branch
			);
			return self::decorate_remote_workspace_result('worktree_remove', $result);
		}

		return $workspace->worktree_remove(
			$repo,
			$branch,
			! empty($input['force'])
		);
	}

	/**
	 * Prune stale worktree registry entries.
	 *
	 * @param  array $input Unused.
	 * @return array
	 */
	public static function worktreePrune( array $input ): array|\WP_Error {   // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( RemoteWorkspaceBackend::has_registered_state() && RemoteWorkspaceBackend::should_handle() ) {
			$result = ( new RemoteWorkspaceBackend() )->worktree_prune();
			return self::decorate_remote_workspace_result('worktree_prune', $result);
		}

		$workspace = new Workspace();
		return $workspace->worktree_prune();
	}

	/**
	 * Remove merged worktrees across all primary checkouts.
	 *
	 * @param  array $input Input parameters (dry_run, force, skip_github, inventory_only, apply_plan, older_than, sort).
	 * @return array
	 */
	public static function worktreeCleanup( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run'                   => ! empty($input['dry_run']),
			'force'                     => ! empty($input['force']),
			'skip_github'               => ! empty($input['skip_github']),
			'inventory_only'            => ! empty($input['inventory_only']),
			'include_repaired_metadata' => ! empty($input['include_repaired_metadata']),
		);
		if ( isset($input['apply_plan']) && is_array($input['apply_plan']) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}
		if ( isset($input['older_than']) && '' !== trim( (string) $input['older_than']) ) {
			$opts['older_than'] = trim( (string) $input['older_than']);
		}
		if ( isset($input['sort']) && '' !== trim( (string) $input['sort']) ) {
			$opts['sort'] = trim( (string) $input['sort']);
		}
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['progress_callback']) && is_callable($input['progress_callback']) ) {
			$opts['progress_callback'] = $input['progress_callback'];
		}

		return $workspace->worktree_cleanup_merged($opts);
	}

	/**
	 * Reconcile unmanaged worktree lifecycle metadata.
	 *
	 * @param  array $input Input parameters (dry_run, apply_plan, limit, offset).
	 * @return array
	 */
	public static function worktreeReconcileMetadata( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run'  => ! empty($input['dry_run']),
			'apply'    => ! empty($input['apply']),
			'via_jobs' => ! empty($input['via_jobs']),
		);
		if ( isset($input['apply_plan']) && is_array($input['apply_plan']) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['source']) && '' !== trim( (string) $input['source']) ) {
			$opts['source'] = trim( (string) $input['source']);
		}
		if ( isset($input['repo']) && '' !== trim( (string) $input['repo']) ) {
			$opts['repo'] = trim( (string) $input['repo']);
		}

		return $workspace->worktree_reconcile_metadata($opts);
	}

	/**
	 * Build a bounded active/no-signal worktree evidence report.
	 *
	 * @param  array $input Input parameters (limit, offset).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeActiveNoSignalReport( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array();
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['repo']) && '' !== trim( (string) $input['repo']) ) {
			$opts['repo'] = trim( (string) $input['repo']);
		}

		return $workspace->worktree_active_no_signal_report($opts);
	}

	/**
	 * Promote finalized active/no-signal evidence into cleanup metadata.
	 *
	 * @param  array $input Input parameters (dry_run, limit, offset).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeActiveNoSignalFinalizedApply( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty($input['dry_run']),
		);
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['repo']) && '' !== trim( (string) $input['repo']) ) {
			$opts['repo'] = trim( (string) $input['repo']);
		}

		return $workspace->worktree_active_no_signal_finalized_apply($opts);
	}

	/**
	 * Promote equivalent-clean active/no-signal evidence into cleanup metadata.
	 *
	 * @param  array $input Input parameters (dry_run, limit, offset).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeActiveNoSignalEquivalentCleanApply( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty($input['dry_run']),
		);
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['repo']) && '' !== trim( (string) $input['repo']) ) {
			$opts['repo'] = trim( (string) $input['repo']);
		}

		return $workspace->worktree_active_no_signal_equivalent_clean_apply($opts);
	}

	/**
	 * Promote merged-to-default active/no-signal evidence into cleanup metadata.
	 *
	 * @param  array $input Input parameters (dry_run, limit, offset).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeActiveNoSignalMergedApply( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty($input['dry_run']),
		);
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['repo']) && '' !== trim( (string) $input['repo']) ) {
			$opts['repo'] = trim( (string) $input['repo']);
		}

		return $workspace->worktree_active_no_signal_merged_apply($opts);
	}

	/**
	 * Promote clean remote-tracking active/no-signal evidence into cleanup metadata.
	 *
	 * @param  array $input Input parameters (dry_run, limit, offset).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeActiveNoSignalRemoteCleanApply( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty($input['dry_run']),
		);
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['repo']) && '' !== trim( (string) $input['repo']) ) {
			$opts['repo'] = trim( (string) $input['repo']);
		}

		return $workspace->worktree_active_no_signal_remote_clean_apply($opts);
	}

	/**
	 * Drain safe active/no-signal cleanup classifications and removals.
	 *
	 * @param  array $input Orchestration input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeActiveNoSignalDrain( array $input ): array|\WP_Error {
		$input['active_no_signal_drain'] = true;
		if ( ! array_key_exists('force', $input) ) {
			$input['force'] = false;
		}

		$orchestrator = new WorkspaceAbandonedCleanupOrchestrator();

		return $orchestrator->run($input);
	}

	/**
	 * Orchestrate abandoned-worktree cleanup across existing bounded abilities.
	 *
	 * @param  array $input Orchestration input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeAbandonedCleanup( array $input ): array|\WP_Error {
		$orchestrator = new WorkspaceAbandonedCleanupOrchestrator();

		return $orchestrator->run($input);
	}

	/**
	 * Remove profile-derived artifacts inside workspace worktrees.
	 *
	 * @param  array $input Input parameters (dry_run, force, apply_plan, limit,
	 *                      offset, exhaustive, safety_probes).
	 * @return array
	 */
	public static function worktreeCleanupArtifacts( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty($input['dry_run']),
			'force'   => ! empty($input['force']),
		);
		if ( isset($input['apply_plan']) && is_array($input['apply_plan']) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}
		if ( array_key_exists('limit', $input) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( array_key_exists('offset', $input) ) {
			$opts['offset'] = (int) $input['offset'];
		}
		if ( array_key_exists('exhaustive', $input) ) {
			$opts['exhaustive'] = (bool) $input['exhaustive'];
		}
		if ( array_key_exists('safety_probes', $input) ) {
			$opts['safety_probes'] = (bool) $input['safety_probes'];
		}

		return $workspace->worktree_cleanup_artifacts($opts);
	}

	/**
	 * Apply only worktrees with explicit lifecycle cleanup_eligible metadata in a bounded batch.
	 *
	 * @param  array $input Input parameters (dry_run, limit, older_than, sort, force, discard_unpushed, via_jobs, remove_timeout, source).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeBoundedCleanupEligibleApply( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run'                   => ! empty($input['dry_run']),
			'force'                     => ! empty($input['force']),
			'discard_unpushed'          => ! empty($input['discard_unpushed']),
			'via_jobs'                  => ! empty($input['via_jobs']),
			'include_repaired_metadata' => ! empty($input['include_repaired_metadata']),
		);
		if ( isset($input['limit']) ) {
			$opts['limit'] = (int) $input['limit'];
		}
		if ( isset($input['older_than']) && '' !== trim( (string) $input['older_than']) ) {
			$opts['older_than'] = trim( (string) $input['older_than']);
		}
		if ( isset($input['sort']) && '' !== trim( (string) $input['sort']) ) {
			$opts['sort'] = trim( (string) $input['sort']);
		}
		if ( isset($input['remove_timeout']) ) {
			$opts['remove_timeout'] = (int) $input['remove_timeout'];
		}
		if ( isset($input['source']) && '' !== trim( (string) $input['source']) ) {
			$opts['source'] = trim( (string) $input['source']);
		}

		return $workspace->worktree_bounded_cleanup_eligible_apply($opts);
	}

	/**
	 * Drain cleanup-eligible worktrees with repeated bounded apply passes.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeCleanupEligibleDrain( array $input ): array|\WP_Error {
		$orchestrator = new WorkspaceCleanupEligibleDrainOrchestrator();

		return $orchestrator->run($input);
	}

	/**
	 * Build or apply a disk-pressure emergency cleanup plan.
	 *
	 * @param  array $input Input parameters (dry_run, force, apply_plan).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function worktreeEmergencyCleanup( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		$opts      = array(
			'dry_run' => ! empty($input['dry_run']),
			'force'   => ! empty($input['force']),
		);
		if ( isset($input['apply_plan']) && is_array($input['apply_plan']) ) {
			$opts['apply_plan'] = $input['apply_plan'];
		}

		return $workspace->worktree_emergency_cleanup($opts);
	}

	/**
	 * Freeze a cleanup plan and optionally emit bounded chunks.
	 *
	 * @param  array $input Input parameters (emit_chunks, chunk_size, include_*).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupPlan( array $input ): array|\WP_Error {
		$opts = array(
			'force_artifact_cleanup' => ! empty($input['force_artifact_cleanup']),
			'include_resolvers'      => ! empty($input['include_resolvers']),
			'mode'                   => (string) ( $input['mode'] ?? 'cleanup_plan' ),
			'worktree_stale_only'    => ! empty($input['worktree_stale_only']),
		);
		foreach ( array( 'include_artifacts', 'include_worktrees', 'full_workspace' ) as $key ) {
			if ( array_key_exists($key, $input) ) {
				$opts[ $key ] = (bool) $input[ $key ];
			}
		}
		foreach ( array( 'limit', 'offset' ) as $key ) {
			if ( isset($input[ $key ]) ) {
				$opts[ $key ] = (int) $input[ $key ];
			}
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$opts['until_budget'] = trim( (string) $input['until_budget']);
		}
		if ( isset($input['worktree_older_than']) && '' !== trim( (string) $input['worktree_older_than']) ) {
			$opts['worktree_older_than'] = trim( (string) $input['worktree_older_than']);
		}
		if ( isset($input['worktree_sort']) && '' !== trim( (string) $input['worktree_sort']) ) {
			$opts['worktree_sort'] = trim( (string) $input['worktree_sort']);
		}
		if ( isset($input['plan']) && is_array($input['plan']) ) {
			$opts['plan'] = $input['plan'];
		}
		if ( isset($input['chunk_size']) ) {
			$opts['chunk_size'] = (int) $input['chunk_size'];
		}

		$service = new CleanupRunService();
		$plan    = $service->plan($opts);
		if ( $plan instanceof \WP_Error || empty($input['emit_chunks']) ) {
			return $plan;
		}

		$workspace = new Workspace();
		$chunks    = $workspace->workspace_cleanup_plan_chunks(array_merge($opts, array( 'plan' => $plan )));
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
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupApply( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->apply( (string) ( $input['run_id'] ?? '' ), self::cleanupRunApplyOptions($input));
	}

	/**
	 * Run artifact cleanup until no rows remain or progress stalls.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupUntilEmpty( array $input ): array|\WP_Error {
		$options = self::cleanupRunApplyOptions($input);
		foreach ( array( 'mode', 'max_passes', 'budget_seconds' ) as $key ) {
			if ( isset($input[ $key ]) ) {
				$options[ $key ] = 'mode' === $key ? (string) $input[ $key ] : (int) $input[ $key ];
			}
		}
		return ( new CleanupRunService() )->until_empty($options);
	}

	/**
	 * Return DB-backed cleanup run status.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupStatus( array $input ): array|\WP_Error {
		return ( new WorkspaceCleanupRunEvidenceStore() )->read( (string) ( $input['run_id'] ?? '' ));
	}

	/**
	 * Return DB-backed cleanup run evidence.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupEvidence( array $input ): array|\WP_Error {
		return ( new WorkspaceCleanupRunEvidenceStore() )->read( (string) ( $input['run_id'] ?? '' ), true );
	}

	/**
	 * Resume pending cleanup rows.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupResume( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->resume( (string) ( $input['run_id'] ?? '' ), self::cleanupRunApplyOptions($input));
	}

	/**
	 * Normalize bounded cleanup apply/resume options.
	 *
	 * @param  array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function cleanupRunApplyOptions( array $input ): array {
		$options = array( 'force' => ! empty($input['force']) );
		if ( isset($input['limit']) ) {
			$options['limit'] = (int) $input['limit'];
		}
		return $options;
	}

	/**
	 * Cancel pending cleanup rows.
	 *
	 * @param  array $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function workspaceCleanupCancel( array $input ): array|\WP_Error {
		return ( new CleanupRunService() )->cancel( (string) ( $input['run_id'] ?? '' ));
	}

	/**
	 * Normalize mounted workspace absolute paths into ability-native inputs.
	 *
	 * @param  array<string,mixed> $input       Ability input.
	 * @param  string[]            $handle_keys Keys that can hold workspace handles.
	 * @return array<string,mixed>
	 */
	private static function normalize_mounted_workspace_path_input( array $input, array $handle_keys ): array {
		$workspace_root = defined('DATAMACHINE_WORKSPACE_PATH') ? self::normalize_workspace_root( (string) DATAMACHINE_WORKSPACE_PATH ) : '';
		if ( '' === $workspace_root ) {
			return $input;
		}

		foreach ( $handle_keys as $key ) {
			if ( isset($input[ $key ]) && is_string($input[ $key ]) && self::is_absolute_path($input[ $key ]) ) {
				$parts = self::split_workspace_root_path($input[ $key ], $workspace_root);
				if ( null === $parts ) {
					return $input;
				}

				$input[ $key ] = $parts['repo'];
				if ( '' !== $parts['path'] ) {
					$existing_path = isset($input['path']) && is_string($input['path']) ? trim($input['path'], '/') : '';
					$input['path'] = '' === $existing_path ? $parts['path'] : $parts['path'] . '/' . $existing_path;
				}
			}
		}

		if ( isset($input['path']) && is_string($input['path']) && self::is_absolute_path($input['path']) ) {
			$parts = self::split_workspace_root_path($input['path'], $workspace_root);
			if ( null === $parts ) {
				return $input;
			}

			$current_handle = '';
			foreach ( $handle_keys as $key ) {
				if ( isset($input[ $key ]) && is_string($input[ $key ]) && '' !== trim($input[ $key ]) ) {
					$current_handle = trim($input[ $key ]);
					break;
				}
			}

			if ( '' === $current_handle && ! empty($handle_keys) ) {
				$input[ $handle_keys[0] ] = $parts['repo'];
			}
			if ( '' === $current_handle || $current_handle === $parts['repo'] ) {
				$input['path'] = $parts['path'];
			}
		}

		return $input;
	}

	private static function normalize_workspace_root( string $root ): string {
		$root = trim(str_replace('\\', '/', trim($root)), '/');
		return '' === $root ? '' : '/' . $root;
	}

	private static function is_absolute_path( string $path ): bool {
		$path = str_replace('\\', '/', trim($path));
		return str_starts_with($path, '/') || (bool) preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path);
	}

	/**
	 * @return array{repo:string,path:string}|null
	 */
	private static function split_workspace_root_path( string $path, string $workspace_root ): ?array {
		$path = str_replace('\\', '/', trim($path));
		if ( preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path) ) {
			return null;
		}

		$root = rtrim($workspace_root, '/');
		if ( $path !== $root && ! str_starts_with($path, $root . '/') ) {
			return null;
		}

		foreach ( self::mounted_workspace_path_aliases() as $mount_path => $workspace_ref ) {
			if ( $path !== $mount_path && ! str_starts_with($path, $mount_path . '/') ) {
				continue;
			}

			$relative = ltrim(substr($path, strlen($mount_path)), '/');
			$segments = '' === $relative ? array() : array_values(array_filter(explode('/', $relative), static fn( string $segment ): bool => '' !== $segment && '.' !== $segment));
			if ( in_array('..', $segments, true) ) {
				return null;
			}

			return array(
				'repo' => $workspace_ref,
				'path' => implode('/', $segments),
			);
		}

		$relative = ltrim(substr($path, strlen($root)), '/');
		if ( '' === $relative ) {
			return null;
		}

		$segments = array_values(array_filter(explode('/', $relative), static fn( string $segment ): bool => '' !== $segment && '.' !== $segment));
		if ( empty($segments) || in_array('..', $segments, true) ) {
			return null;
		}

		$repo = array_shift($segments);
		return array(
			'repo' => $repo,
			'path' => implode('/', $segments),
		);
	}

	/** @return array<string,string> */
	private static function mounted_workspace_path_aliases(): array {
		if ( ! class_exists('\DataMachineCode\Runtime\MountedRuntimeBootstrap') ) {
			return array();
		}

		$aliases = \DataMachineCode\Runtime\MountedRuntimeBootstrap::mounted_workspace_path_aliases();

		$normalized = array();
		foreach ( $aliases as $path => $workspace_ref ) {
			$path          = rtrim(str_replace('\\', '/', (string) $path), '/');
			$workspace_ref = trim( (string) $workspace_ref );
			if ( '' !== $path && '' !== $workspace_ref ) {
				$normalized[ $path ] = $workspace_ref;
			}
		}

		uksort($normalized, static fn ( string $left, string $right ): int => strlen($right) <=> strlen($left));
		return $normalized;
	}

	/**
	 * Read git log entries for a workspace repository.
	 *
	 * @param  array $input Input parameters with 'name', optional 'limit'.
	 * @return array
	 */
	public static function gitLog( array $input ): array|\WP_Error {
		$workspace = new Workspace();
		return $workspace->git_log(
			$input['name'] ?? '',
			isset($input['limit']) ? (int) $input['limit'] : 20
		);
	}

	/**
	 * Read git diff output for a workspace repository.
	 *
	 * @param  array $input Input parameters.
	 * @return array
	 */
	public static function gitDiff( array $input ): array|\WP_Error {
		$input = self::normalize_mounted_workspace_path_input($input, array( 'name' ));
		if ( RemoteWorkspaceBackend::should_handle() ) {
			return ( new RemoteWorkspaceBackend() )->git_diff(
				$input['name'] ?? '',
				$input['from'] ?? null,
				$input['to'] ?? null,
				! empty($input['staged']),
				$input['path'] ?? null
			);
		}

		$workspace = new Workspace();
		return $workspace->git_diff(
			$input['name'] ?? '',
			$input['from'] ?? null,
			$input['to'] ?? null,
			! empty($input['staged']),
			$input['path'] ?? null
		);
	}
}
