<?php
/**
 * GitHub Abilities
 *
 * Core business logic for GitHub API interactions: listing issues, PRs,
 * repos, reading repo source files, and managing issues (update, close,
 * comment). All GitHub operations — CLI, REST, chat tools, fetch handler —
 * route through here.
 *
 * Auth: Uses either github_pat or GitHub App settings stored in Data Machine PluginSettings.
 *
 * @package DataMachineCode\Abilities
 * @since   0.1.0
 */

namespace DataMachineCode\Abilities;

use DataMachineCode\Support\PermissionHelper;
use DataMachineCode\Support\PluginSettings;
use DataMachineCode\GitHub\PrReviewEscalationPolicy;
use DataMachineCode\Support\GitHubCredentialResolver;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\RunArtifactBundleFileWriter;
use DataMachineCode\Support\RunArtifactPrSectionRenderer;
use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

if ( ! class_exists(AbilityRegistry::class) ) {
	require_once __DIR__ . '/AbilityRegistry.php';
}

if ( ! class_exists(GitHubCredentialResolver::class) ) {
	include_once dirname(__DIR__) . '/Support/GitHubCredentialResolver.php';
}

if ( ! class_exists(GitHubRemote::class) ) {
	include_once dirname(__DIR__) . '/Support/GitHubRemote.php';
}

if ( ! class_exists(PrReviewEscalationPolicy::class) ) {
	include_once dirname(__DIR__) . '/GitHub/PrReviewEscalationPolicy.php';
}

if ( ! class_exists(RunArtifactBundleFileWriter::class) ) {
	include_once dirname(__DIR__) . '/Support/RunArtifactBundleFileWriter.php';
}

if ( ! class_exists(RunArtifactPrSectionRenderer::class) ) {
	include_once dirname(__DIR__) . '/Support/RunArtifactPrSectionRenderer.php';
}

if ( ! class_exists(Workspace::class) ) {
	include_once dirname(__DIR__) . '/Workspace/Workspace.php';
}

class GitHubAbilities {



	private static bool $registered = false;

	private static ?\WP_Error $last_auth_error = null;

	/**
	 * Map of resolved tokens → auth mode ('pat' | 'app').
	 *
	 * Lets getHeaders() pick the right Authorization scheme without
	 * re-resolving the credential. Populated by getPat() / getCredential()
	 * each time a credential is resolved.
	 *
	 * @var array<string,string>
	 */
	private static array $token_modes = array();

	/**
	 * GitHub API base URL for non-repository-scoped public GitHub requests.
	 */
	const API_BASE = GitHubRemote::PUBLIC_API_BASE_URL;

	/**
	 * Default per_page for API requests.
	 */
	const DEFAULT_PER_PAGE = 30;

	/**
	 * Maximum per_page for API requests.
	 */
	const MAX_PER_PAGE = 100;

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
			AbilityRegistry::register(
				'datamachine-code/list-github-issues',
				array(
					'label'               => 'List GitHub Issues',
					'description'         => 'List issues from a GitHub repository with optional filters',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo'     => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'state'    => array(
								'type'        => 'string',
								'description' => 'Issue state: open, closed, all (default: open).',
							),
							'labels'   => array(
								'type'        => 'string',
								'description' => 'Comma-separated list of label names to filter by.',
							),
							'assignee' => array(
								'type'        => 'string',
								'description' => 'Filter by assignee username.',
							),
							'since'    => array(
								'type'        => 'string',
								'description' => 'ISO 8601 timestamp to filter issues updated after this date.',
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'page'     => array(
								'type'        => 'integer',
								'description' => 'Page number for pagination (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issues'  => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listIssues' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-issue',
				array(
					'label'               => 'Get GitHub Issue',
					'description'         => 'Get a single GitHub issue with full details',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue number.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issue'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/update-github-issue',
				array(
					'label'               => 'Update GitHub Issue',
					'description'         => 'Update a GitHub issue (title, body, labels, assignees, state)',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue number.',
							),
							'title'        => array(
								'type'        => 'string',
								'description' => 'New issue title.',
							),
							'body'         => array(
								'type'        => 'string',
								'description' => 'New issue body.',
							),
							'state'        => array(
								'type'        => 'string',
								'description' => 'Issue state: open or closed.',
							),
							'labels'       => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Labels to set on the issue (replaces existing).',
							),
							'assignees'    => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Assignees to set on the issue (replaces existing).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issue'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'updateIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/create-github-issue',
				array(
					'label'               => 'Create GitHub Issue',
					'description'         => 'Create a new GitHub issue with optional labels, assignees, and milestone',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'title' ),
						'properties' => array(
							'repo'      => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'title'     => array(
								'type'        => 'string',
								'description' => 'Issue title (required).',
							),
							'body'      => array(
								'type'        => 'string',
								'description' => 'Issue body (supports GitHub Markdown).',
							),
							'labels'    => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Labels to attach to the issue.',
							),
							'assignees' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'GitHub usernames to assign to the issue.',
							),
							'milestone' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Milestone number to attach to the issue.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issue'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'createIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/create-github-pull-request',
				array(
					'label'               => 'Create GitHub Pull Request',
					'description'         => 'Open a new GitHub pull request from a head branch into a base branch',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'title', 'head' ),
						'properties' => array(
							'repo'                  => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'title'                 => array(
								'type'        => 'string',
								'description' => 'Pull request title (required).',
							),
							'head'                  => array(
								'type'        => 'string',
								'description' => 'Branch where changes are implemented (required). Use owner:branch for cross-fork PRs.',
							),
							'base'                  => array(
								'type'        => 'string',
								'description' => 'Branch to merge into. Defaults to the repository default branch.',
							),
							'body'                  => array(
								'type'        => 'string',
								'description' => 'Pull request description (supports GitHub Markdown).',
							),
							'draft'                 => array(
								'type'        => 'boolean',
								'description' => 'Whether to open the pull request as a draft. Default: false.',
							),
							'labels'                => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Labels to attach to the pull request after creation.',
							),
							'maintainer_can_modify' => array(
								'type'        => 'boolean',
								'description' => 'Whether maintainers can modify the pull request. Default: true.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'kind'         => array( 'type' => 'string' ),
							'repo'         => array( 'type' => 'string' ),
							'number'       => array( 'type' => 'integer' ),
							'pull_number'  => array( 'type' => 'integer' ),
							'url'          => array( 'type' => 'string' ),
							'html_url'     => array( 'type' => 'string' ),
							'reused'       => array( 'type' => 'boolean' ),
							'pull_request' => array( 'type' => 'object' ),
							'labeling'     => array( 'type' => 'object' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'createPullRequest' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/comment-github-issue',
				array(
					'label'               => 'Comment on GitHub Issue',
					'description'         => 'Add a comment to a GitHub issue',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number', 'body' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue number.',
							),
							'body'         => array(
								'type'        => 'string',
								'description' => 'Comment body (supports GitHub Markdown).',
							),
							'allow_repeat_automation_comment' => array(
								'type'        => 'boolean',
								'description' => 'Allow commenting when the latest issue comment is already from this automation actor. Default: false.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'comment' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'commentOnIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/comment-github-pull-request',
				array(
					'label'               => 'Comment on GitHub Pull Request',
					'description'         => 'Add a comment to a GitHub pull request without broader issue-management permissions',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number', 'body' ),
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number' => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'body'        => array(
								'type'        => 'string',
								'description' => 'Comment body (supports GitHub Markdown).',
							),
							'marker'      => array(
								'type'        => 'string',
								'description' => 'Optional stable marker appended as an HTML comment for future update-by-marker support.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'comment' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'commentOnPullRequest' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/add-github-labels',
				array(
					'label'               => 'Add GitHub Labels',
					'description'         => 'Add one or more labels to a GitHub issue or pull request without replacing existing labels.',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number', 'labels' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue or pull request number.',
							),
							'labels'       => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Labels to add.',
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'addLabels' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/remove-github-label',
				array(
					'label'               => 'Remove GitHub Label',
					'description'         => 'Remove a single label from a GitHub issue or pull request without touching other labels.',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number', 'label' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue or pull request number.',
							),
							'label'        => array(
								'type'        => 'string',
								'description' => 'Label to remove.',
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'removeLabel' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/upsert-github-pull-review-comment',
				array(
					'label'               => 'Upsert GitHub Pull Request Review Comment',
					'description'         => 'Create or update one managed bot-authored GitHub pull request review comment identified by a hidden marker',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number', 'body' ),
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number' => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'body'        => array(
								'type'        => 'string',
								'description' => 'Review comment body (supports GitHub Markdown). Hidden marker text is appended automatically.',
							),
							'marker'      => array(
								'type'        => 'string',
								'description' => 'Hidden HTML comment marker used to find the managed comment. Default: <!-- datamachine-pr-review -->.',
							),
							'head_sha'    => array(
								'type'        => 'string',
								'description' => 'Optional pull request head SHA. Required to separate comments when mode is per_head_sha.',
							),
							'mode'        => array(
								'type'        => 'string',
								'description' => 'Comment policy: update_existing or per_head_sha. Default: update_existing.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'action'     => array( 'type' => 'string' ),
							'comment_id' => array( 'type' => 'integer' ),
							'html_url'   => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'upsertPullReviewComment' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/merge-github-pull-request',
				array(
					'label'               => 'Merge GitHub Pull Request',
					'description'         => 'Merge an open GitHub pull request after verifying the expected head SHA',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number', 'expected_head_sha' ),
						'properties' => array(
							'repo'              => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number'       => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'expected_head_sha' => array(
								'type'        => 'string',
								'description' => 'Exact pull request head SHA expected immediately before merge.',
							),
							'merge_method'      => array(
								'type'        => 'string',
								'enum'        => array( 'merge', 'squash', 'rebase' ),
								'description' => 'GitHub merge method. Default: squash.',
							),
							'delete_branch'     => array(
								'type'        => 'boolean',
								'description' => 'Delete the pull request head branch through the GitHub API after a successful merge when the branch is in the same repository.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'                => array( 'type' => 'boolean' ),
							'repo'                   => array( 'type' => 'string' ),
							'pull_number'            => array( 'type' => 'integer' ),
							'merged'                 => array( 'type' => 'boolean' ),
							'sha'                    => array( 'type' => 'string' ),
							'message'                => array( 'type' => 'string' ),
							'html_url'               => array( 'type' => 'string' ),
							'local_worktree_cleanup' => array( 'type' => 'object' ),
							'error'                  => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'mergePullRequest' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/cleanup-github-pull-request',
				array(
					'label'               => 'Cleanup GitHub Pull Request',
					'description'         => 'Delete a merged pull request head branch through the GitHub API without checking out local branches',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number' ),
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number' => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'dry_run'     => array(
								'type'        => 'boolean',
								'description' => 'Preview the cleanup decision without deleting the branch.',
							),
							'local_only'  => array(
								'type'        => 'boolean',
								'description' => 'Finalize and remove the matching local DMC worktree without deleting the remote branch.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'                => array( 'type' => 'boolean' ),
							'repo'                   => array( 'type' => 'string' ),
							'pull_number'            => array( 'type' => 'integer' ),
							'head_branch'            => array( 'type' => 'string' ),
							'branch_deleted'         => array( 'type' => 'boolean' ),
							'branch_already_deleted' => array( 'type' => 'boolean' ),
							'dry_run'                => array( 'type' => 'boolean' ),
							'message'                => array( 'type' => 'string' ),
							'local_worktree_cleanup' => array( 'type' => 'object' ),
							'error'                  => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'cleanupPullRequest' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/list-github-pulls',
				array(
					'label'               => 'List GitHub Pull Requests',
					'description'         => 'List pull requests from a GitHub repository',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo'     => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'state'    => array(
								'type'        => 'string',
								'description' => 'PR state: open, closed, all (default: open).',
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'page'     => array(
								'type'        => 'integer',
								'description' => 'Page number (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pulls'   => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listPulls' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-pull',
				array(
					'label'               => 'Get GitHub Pull Request',
					'description'         => 'Get a single GitHub pull request with normalized metadata',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number' ),
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number' => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pull'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getPull' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/list-github-pull-files',
				array(
					'label'               => 'List GitHub Pull Request Files',
					'description'         => 'List changed files for a GitHub pull request',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number' ),
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number' => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'per_page'    => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 100, max: 100).',
							),
							'page'        => array(
								'type'        => 'integer',
								'description' => 'Page number (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'files'   => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listPullFiles' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-check-runs',
				array(
					'label'               => 'Get GitHub Check Runs',
					'description'         => 'Get GitHub check runs for a commit SHA or ref with an overall summary',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'sha' ),
						'properties' => array(
							'repo'                 => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'sha'                  => array(
								'type'        => 'string',
								'description' => 'Commit SHA, branch, or tag ref.',
							),
							'per_page'             => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'include_check_output' => array(
								'type'        => 'boolean',
								'description' => 'Whether to include bounded check output summaries and text.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'sha'        => array( 'type' => 'string' ),
							'summary'    => array( 'type' => 'object' ),
							'check_runs' => array( 'type' => 'array' ),
							'count'      => array( 'type' => 'integer' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getCheckRuns' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-commit-statuses',
				array(
					'label'               => 'Get GitHub Commit Statuses',
					'description'         => 'Get unmanaged GitHub commit statuses for a commit SHA or ref',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'sha' ),
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'sha'  => array(
								'type'        => 'string',
								'description' => 'Commit SHA, branch, or tag ref.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'sha'      => array( 'type' => 'string' ),
							'summary'  => array( 'type' => 'object' ),
							'statuses' => array( 'type' => 'array' ),
							'count'    => array( 'type' => 'integer' ),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getCommitStatuses' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-actions-artifact',
				array(
					'label'               => 'Get GitHub Actions Artifact',
					'description'         => 'Download a GitHub Actions artifact for a pull request or commit SHA and optionally parse JSON files from the ZIP',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'artifact_name' ),
						'properties' => array(
							'repo'               => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'head_sha'           => array(
								'type'        => 'string',
								'description' => 'Pull request head SHA or commit SHA to match artifacts against.',
							),
							'pull_number'        => array(
								'type'        => 'integer',
								'description' => 'Pull request number. Used to resolve head_sha when head_sha is omitted.',
							),
							'artifact_name'      => array(
								'type'        => 'string',
								'description' => 'GitHub Actions artifact name.',
							),
							'max_artifact_bytes' => array(
								'type'        => 'integer',
								'description' => 'Maximum artifact ZIP bytes to download. Default: 2000000.',
							),
							'include_json'       => array(
								'type'        => 'boolean',
								'description' => 'Parse and include JSON files from the artifact ZIP. Default: true.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'repo'        => array( 'type' => 'string' ),
							'head_sha'    => array( 'type' => 'string' ),
							'pull_number' => array( 'type' => 'integer' ),
							'artifact'    => array( 'type' => 'object' ),
							'json_files'  => array( 'type' => 'object' ),
							'error'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getActionsArtifact' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-pull-review-context',
				array(
					'label'               => 'Get GitHub Pull Request Review Context',
					'description'         => 'Get a review-ready payload for a GitHub pull request and its changed files',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number' ),
						'properties' => array(
							'repo'                    => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number'             => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'head_sha'                => array(
								'type'        => 'string',
								'description' => 'Optional expected pull request head SHA.',
							),
							'max_patch_chars'         => array(
								'type'        => 'integer',
								'description' => 'Maximum cumulative patch characters to include (default: 200000).',
							),
							'include_file_contents'   => array(
								'type'        => 'boolean',
								'description' => 'Whether to include bounded full-file contents for changed files.',
							),
							'include_base_contents'   => array(
								'type'        => 'boolean',
								'description' => 'Whether to include bounded base-file contents for changed files.',
							),
							'context_paths'           => array(
								'type'        => array( 'array', 'string' ),
								'description' => 'Additional repository paths to include from the PR head ref, as an array or comma/newline-separated string.',
							),
							'max_file_content_chars'  => array(
								'type'        => 'integer',
								'description' => 'Maximum characters included per expanded file content block (default: 20000).',
							),
							'max_context_files'       => array(
								'type'        => 'integer',
								'description' => 'Maximum number of files included in expanded PR review context (default: 10).',
							),
							'max_total_context_chars' => array(
								'type'        => 'integer',
								'description' => 'Maximum cumulative characters included across expanded PR review context files (default: 100000).',
							),
							'include_checks'          => array(
								'type'        => 'boolean',
								'description' => 'Whether to include GitHub check runs for the PR head SHA.',
							),
							'include_statuses'        => array(
								'type'        => 'boolean',
								'description' => 'Whether to include classic commit statuses for the PR head SHA.',
							),
							'max_check_runs'          => array(
								'type'        => 'integer',
								'description' => 'Maximum check runs to include (default: 30, max: 100).',
							),
							'include_check_output'    => array(
								'type'        => 'boolean',
								'description' => 'Whether to include bounded check output summaries and text.',
							),
							'artifact_name'           => array(
								'type'        => 'string',
								'description' => 'Optional GitHub Actions artifact name to include through generic artifact consumers.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'context' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getPullReviewContext' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-repo-review-profile',
				array(
					'label'               => 'Get GitHub Repository Review Profile',
					'description'         => 'Get bounded repository-level review context for a GitHub repository',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo'                  => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'ref'                   => array(
								'type'        => 'string',
								'description' => 'Branch, tag, or commit SHA. Defaults to HEAD.',
							),
							'max_profile_files'     => array(
								'type'        => 'integer',
								'description' => 'Maximum profile files to include. Default: 14.',
							),
							'max_file_chars'        => array(
								'type'        => 'integer',
								'description' => 'Maximum characters per profile file. Default: 12000.',
							),
							'max_total_chars'       => array(
								'type'        => 'integer',
								'description' => 'Maximum cumulative profile characters. Default: 60000.',
							),
							'max_architecture_docs' => array(
								'type'        => 'integer',
								'description' => 'Maximum docs/** architecture/development files to include. Default: 8.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'profile' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getRepoReviewProfile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-pr-documentation-impact',
				array(
					'label'               => 'Get GitHub PR Documentation Impact',
					'description'         => 'Build a heuristic documentation-impact packet for a GitHub pull request',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'pull_number' ),
						'properties' => array(
							'repo'        => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'pull_number' => array(
								'type'        => 'integer',
								'description' => 'Pull request number.',
							),
							'head_sha'    => array(
								'type'        => 'string',
								'description' => 'Optional expected pull request head SHA.',
							),
							'base_ref'    => array(
								'type'        => 'string',
								'description' => 'Optional base ref used for docs tree lookup. Defaults to the PR base ref.',
							),
							'docs_paths'  => array(
								'type'        => array( 'array', 'string' ),
								'description' => 'Optional docs path allow-list, as an array or comma/newline-separated string.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'packet'  => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getPullDocumentationImpact' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/list-github-tree',
				array(
					'label'               => 'List GitHub Repository Tree',
					'description'         => 'List files in a GitHub repository tree at a branch or ref',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'ref'  => array(
								'type'        => 'string',
								'description' => 'Branch, tag, or commit SHA. Defaults to HEAD.',
							),
							'path' => array(
								'type'        => 'string',
								'description' => 'Optional path prefix to filter returned files.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'files'   => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getRepoTree' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/get-github-file',
				array(
					'label'               => 'Get GitHub Files',
					'description'         => 'Get decoded content for one or more files in a GitHub repository',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo'           => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'path'           => array(
								'type'        => 'string',
								'description' => 'Single file path within the repository. Use paths for multiple files.',
							),
							'paths'          => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'One or more file paths within the repository.',
							),
							'ref'            => array(
								'type'        => 'string',
								'description' => 'Branch, tag, or commit SHA. Defaults to the repository default branch.',
							),
							'max_total_size' => array(
								'type'        => 'integer',
								'description' => 'Maximum cumulative decoded bytes to return across files. Default: 500000.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'files'      => array( 'type' => 'array' ),
							'errors'     => array( 'type' => 'array' ),
							'count'      => array( 'type' => 'integer' ),
							'total_size' => array( 'type' => 'integer' ),
							'truncated'  => array( 'type' => 'boolean' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getFileContents' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/create-or-update-github-file',
				array(
					'label'               => 'Create or Update GitHub File',
					'description'         => 'Create or update a file in a GitHub repository via the Contents API (upsert). If the file exists, it is updated; if not, it is created.',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'file_path', 'content', 'commit_message' ),
						'properties' => array(
							'repo'               => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'file_path'          => array(
								'type'        => 'string',
								'description' => 'Path within the repository (e.g., docs/getting-started.md).',
							),
							'content'            => array(
								'type'        => 'string',
								'description' => 'File content (will be base64-encoded automatically).',
							),
							'commit_message'     => array(
								'type'        => 'string',
								'description' => 'Commit message for the change.',
							),
							'branch'             => array(
								'type'        => 'string',
								'description' => 'Target branch. Defaults to the repository\'s default branch.',
							),
							'allowed_file_paths' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional allowlist of file paths or glob-like patterns this call may write, such as README.md or docs/**.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'commit'  => array( 'type' => 'object' ),
							'content' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'createOrUpdateFile' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/list-github-repos',
				array(
					'label'               => 'List GitHub Repositories',
					'description'         => 'List repositories for a user or organization',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'owner' ),
						'properties' => array(
							'owner'    => array(
								'type'        => 'string',
								'description' => 'GitHub user or organization name.',
							),
							'type'     => array(
								'type'        => 'string',
								'description' => 'For orgs: all, public, private, forks, sources, member. For users: all, owner, member.',
							),
							'sort'     => array(
								'type'        => 'string',
								'description' => 'Sort by: created, updated, pushed, full_name (default: updated).',
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'page'     => array(
								'type'        => 'integer',
								'description' => 'Page number (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'repos'   => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listRepos' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			AbilityRegistry::register(
				'datamachine-code/github-status',
				array(
					'label'               => 'Get GitHub Integration Status',
					'description'         => 'Inspect GitHub authentication, default repository, and registered repository status.',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'          => array( 'type' => 'boolean' ),
							'auth_status'      => array( 'type' => 'object' ),
							'configured'       => array( 'type' => 'boolean' ),
							'default_repo'     => array( 'type' => 'string' ),
							'registered_repos' => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'githubStatus' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( doing_action('wp_abilities_api_init') ) {
			$register_callback();
		} else {
			add_action('wp_abilities_api_init', $register_callback);
		}
	}

	// -------------------------------------------------------------------------
	// Ability Callbacks
	// -------------------------------------------------------------------------

	public static function githubStatus( array $input ): array {
		unset($input);

		$auth_status = self::getAuthStatus();

		return array(
			'success'          => true,
			'auth_status'      => $auth_status,
			'configured'       => ! empty($auth_status['configured']),
			'default_repo'     => self::getDefaultRepo(),
			'registered_repos' => self::getRegisteredRepos(),
		);
	}

	public static function listIssues( array $input ): array|\WP_Error {
		$repo = sanitize_text_field($input['repo'] ?? '');
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_read');
		if ( empty($pat) ) {
			return self::patError();
		}

		$query_params = array(
			'state'    => sanitize_text_field($input['state'] ?? 'open'),
			'per_page' => self::clampPerPage($input['per_page'] ?? self::DEFAULT_PER_PAGE),
			'page'     => max(1, (int) ( $input['page'] ?? 1 )),
		);

		if ( ! empty($input['labels']) ) {
			$query_params['labels'] = sanitize_text_field($input['labels']);
		}
		if ( ! empty($input['assignee']) ) {
			$query_params['assignee'] = sanitize_text_field($input['assignee']);
		}
		if ( ! empty($input['since']) ) {
			$query_params['since'] = sanitize_text_field($input['since']);
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues');
		$response = self::apiGet($url, $query_params, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$issues = array_filter($response['data'], fn( $item ) => empty($item['pull_request']));
		$issues = array_values($issues);

		$normalized = array_map(array( self::class, 'normalizeIssue' ), $issues);

		return array(
			'success'      => true,
			'issues'       => $normalized,
			'count'        => count($normalized),
			'generated_at' => gmdate('c'),
		);
	}

	public static function getIssue( array $input ): array|\WP_Error {
		$repo         = sanitize_text_field($input['repo'] ?? '');
		$issue_number = (int) ( $input['issue_number'] ?? 0 );

		if ( empty($repo) || $issue_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and issue_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_read');
		if ( empty($pat) ) {
			return self::patError();
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues/' . $issue_number);
		$response = self::apiGet($url, array(), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$issue = self::normalizeIssue($response['data']);
		if ( $issue['comment_count'] > 0 ) {
			$latest_comment = self::getLatestIssueComment($repo, $issue_number, $issue['comment_count'], $pat);
			if ( is_wp_error($latest_comment) ) {
				return $latest_comment;
			}

			if ( ! empty($latest_comment) ) {
				$issue['latest_comment']        = $latest_comment;
				$issue['latest_comment_at']     = $latest_comment['created_at'];
				$issue['latest_comment_author'] = $latest_comment['user'];
			}
		}

		return array(
			'success'      => true,
			'issue'        => $issue,
			'generated_at' => gmdate('c'),
		);
	}

	public static function updateIssue( array $input ): array|\WP_Error {
		$repo         = sanitize_text_field($input['repo'] ?? '');
		$issue_number = (int) ( $input['issue_number'] ?? 0 );

		if ( empty($repo) || $issue_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and issue_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_write');
		if ( empty($pat) ) {
			return self::patError();
		}

		$body = array();
		if ( isset($input['title']) ) {
			$body['title'] = $input['title'];
		}
		if ( isset($input['body']) ) {
			$body['body'] = $input['body'];
		}
		if ( isset($input['state']) ) {
			$body['state'] = $input['state'];
		}
		if ( isset($input['labels']) && is_array($input['labels']) ) {
			$body['labels'] = $input['labels'];
		}
		if ( isset($input['assignees']) && is_array($input['assignees']) ) {
			$body['assignees'] = $input['assignees'];
		}

		if ( empty($body) ) {
			return new \WP_Error('no_fields', 'No fields to update. Provide title, body, state, labels, or assignees.', array( 'status' => 400 ));
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues/' . $issue_number);
		$response = self::apiRequest('PATCH', $url, $body, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		return array(
			'success' => true,
			'issue'   => self::normalizeIssue($response['data']),
			'message' => sprintf('Issue #%d updated.', $issue_number),
		);
	}

	public static function createIssue( array $input ): array|\WP_Error {
		$repo = self::resolveRepo(sanitize_text_field($input['repo'] ?? ''));
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ));
		}

		$title = sanitize_text_field($input['title'] ?? '');
		if ( empty($title) ) {
			return new \WP_Error('missing_title', 'Issue title is required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_write');
		if ( empty($pat) ) {
			return self::patError();
		}

		$body = array( 'title' => $title );

		if ( ! empty($input['body']) ) {
			$body['body'] = $input['body'];
		}
		$labels = self::mergeProvenanceLabels(isset($input['labels']) && is_array($input['labels']) ? $input['labels'] : array());
		if ( ! empty($labels) ) {
			$body['labels'] = $labels;
		}
		if ( ! empty($input['assignees']) && is_array($input['assignees']) ) {
			$body['assignees'] = array_map('sanitize_text_field', $input['assignees']);
		}
		if ( isset($input['milestone']) && '' !== $input['milestone'] ) {
			$milestone = (int) $input['milestone'];
			if ( $milestone > 0 ) {
				$body['milestone'] = $milestone;
			}
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues');
		$response = self::apiRequest('POST', $url, $body, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$issue = self::normalizeIssue($response['data']);

		return array(
			'success'      => true,
			'kind'         => 'issue',
			'repo'         => $repo,
			'number'       => $issue['number'] ?? 0,
			'issue'        => $issue,
			'issue_url'    => $issue['html_url'] ?? '',
			'issue_number' => $issue['number'] ?? 0,
			'url'          => $issue['html_url'] ?? '',
			'html_url'     => $issue['html_url'] ?? '',
			'message'      => sprintf('Issue #%d created in %s.', $issue['number'] ?? 0, $repo),
		);
	}

	/**
	 * Create a new pull request.
	 *
	 * Calls `POST /repos/{owner}/{repo}/pulls`.
	 *
	 * @param  array $input {
	 *                      Required: repo, title, head. Optional: base, body, draft, maintainer_can_modify.
	 *                      }
	 * @return array|\WP_Error { success: bool, pull_request: normalized, error: string|null } or error.
	 */
	public static function createPullRequest( array $input ): array|\WP_Error {
		$repo = self::resolveRepo(sanitize_text_field($input['repo'] ?? ''));
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ));
		}

		$title = sanitize_text_field($input['title'] ?? '');
		if ( empty($title) ) {
			return new \WP_Error('missing_title', 'Pull request title is required.', array( 'status' => 400 ));
		}

		$head = sanitize_text_field($input['head'] ?? '');
		if ( empty($head) ) {
			return new \WP_Error('missing_head', 'Pull request head branch is required.', array( 'status' => 400 ));
		}

		$pat = self::getPat(
			array(
				'repo'       => $repo,
				'capability' => 'pull_request_create',
			)
		);
		if ( empty($pat) ) {
			return self::patError();
		}

		$body = array(
			'title' => $title,
			'head'  => $head,
		);

		$base = sanitize_text_field($input['base'] ?? '');
		if ( '' === $base ) {
			$base = self::resolveDefaultBranch($repo, $pat);
			if ( is_wp_error($base) ) {
				return $base;
			}
		}
		$body['base'] = $base;

		$existing_pull = self::findExistingOpenPullRequest($repo, $head, $base, $pat);
		if ( is_wp_error($existing_pull) ) {
			return $existing_pull;
		}

		if ( null !== $existing_pull ) {
			$pull     = self::normalizePull($existing_pull);
			$labels   = self::mergeProvenanceLabels(isset($input['labels']) && is_array($input['labels']) ? $input['labels'] : array());
			$labeling = null;

			if ( ! empty($labels) && ! empty($pull['number']) ) {
				$label_response = self::applyLabelsToNumber($repo, (int) $pull['number'], $labels, $pat);
				if ( is_wp_error($label_response) ) {
					$labeling = array(
						'success'    => false,
						'labels'     => $labels,
						'error_code' => $label_response->get_error_code(),
						'error'      => $label_response->get_error_message(),
						'status'     => is_array($label_response->get_error_data()) ? ( $label_response->get_error_data()['status'] ?? null ) : null,
					);
				} else {
					$labeling = array(
						'success'        => true,
						'labels'         => $labels,
						'applied_labels' => $label_response['applied_labels'] ?? array(),
					);
				}
			}

			$result = array(
				'success'      => true,
				'kind'         => 'pull_request',
				'repo'         => $repo,
				'number'       => $pull['number'] ?? 0,
				'pull_request' => $pull,
				'pull_number'  => $pull['number'] ?? 0,
				'url'          => $pull['html_url'] ?? '',
				'html_url'     => $pull['html_url'] ?? '',
				'reused'       => true,
				'message'      => sprintf('Pull request #%d already exists in %s.', $pull['number'] ?? 0, $repo),
			);

			if ( null !== $labeling ) {
				$result['labeling'] = $labeling;
			}

			return $result;
		}

		$body_text = isset($input['body']) ? (string) $input['body'] : '';
		$artifacts = self::preparePullRequestRunArtifacts($input, $repo, $head, $body_text);
		if ( is_wp_error($artifacts) ) {
			return $artifacts;
		}

		if ( '' !== $body_text ) {
			$body['body'] = $body_text;
		}
		if ( array_key_exists('draft', $input) ) {
			$body['draft'] = (bool) $input['draft'];
		}
		if ( array_key_exists('maintainer_can_modify', $input) ) {
			$body['maintainer_can_modify'] = (bool) $input['maintainer_can_modify'];
		} else {
			$body['maintainer_can_modify'] = true;
		}

		$url      = GitHubRemote::apiUrl($repo, 'pulls');
		$response = self::apiRequest('POST', $url, $body, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$pull     = self::normalizePull($response['data']);
		$labels   = self::mergeProvenanceLabels(isset($input['labels']) && is_array($input['labels']) ? $input['labels'] : array());
		$labeling = null;

		if ( ! empty($labels) && ! empty($pull['number']) ) {
			$label_response = self::applyLabelsToNumber($repo, (int) $pull['number'], $labels, $pat);
			if ( is_wp_error($label_response) ) {
				$labeling = array(
					'success'    => false,
					'labels'     => $labels,
					'error_code' => $label_response->get_error_code(),
					'error'      => $label_response->get_error_message(),
					'status'     => is_array($label_response->get_error_data()) ? ( $label_response->get_error_data()['status'] ?? null ) : null,
				);
			} else {
				$labeling = array(
					'success'        => true,
					'labels'         => $labels,
					'applied_labels' => $label_response['applied_labels'] ?? array(),
				);
			}
		}

		$result = array(
			'success'      => true,
			'kind'         => 'pull_request',
			'repo'         => $repo,
			'number'       => $pull['number'] ?? 0,
			'pull_request' => $pull,
			'pull_number'  => $pull['number'] ?? 0,
			'url'          => $pull['html_url'] ?? '',
			'html_url'     => $pull['html_url'] ?? '',
			'message'      => sprintf('Pull request #%d opened in %s.', $pull['number'] ?? 0, $repo),
		);

		if ( ! empty($artifacts['files']) ) {
			$result['run_artifact_files'] = $artifacts['files'];
		}

		if ( null !== $labeling ) {
			$result['labeling'] = $labeling;
		}

		return $result;
	}

	/**
	 * Find an open pull request for the exact head/base pair before creating one.
	 *
	 * @param  string $repo Repository owner/name.
	 * @param  string $head Pull request head branch or owner:branch.
	 * @param  string $base Pull request base branch.
	 * @param  string $pat  GitHub token.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	private static function findExistingOpenPullRequest( string $repo, string $head, string $base, string $pat ): array|null|\WP_Error {
		$repo_owner = strtok($repo, '/');
		$head_query = str_contains($head, ':') ? $head : sprintf('%s:%s', $repo_owner, $head);
		$head_ref   = str_contains($head, ':') ? substr($head, (int) strpos($head, ':') + 1) : $head;

		$url      = GitHubRemote::apiUrl($repo, 'pulls');
		$response = self::apiGet(
			$url,
			array(
				'state'    => 'open',
				'head'     => $head_query,
				'base'     => $base,
				'per_page' => 10,
			),
			$pat
		);

		if ( is_wp_error($response) ) {
			return $response;
		}

		foreach ( $response['data'] ?? array() as $pull ) {
			if ( ! is_array($pull) ) {
				continue;
			}

			$normalized = self::normalizePull($pull);
			if ( $head_ref === $normalized['head_ref'] && $base === $normalized['base_ref'] ) {
				return $pull;
			}
		}

		return null;
	}

	/**
	 * Persist and render Data Machine run artifacts for direct PR creation.
	 *
	 * Direct `create_github_pull_request` calls bypass publish handlers, so the
	 * ability must honor the same generic run artifact egress policy itself.
	 *
	 * @param  array  $input     Ability input.
	 * @param  string $repo      Repository owner/name.
	 * @param  string $head      Pull request head branch.
	 * @param  string $body_text Pull request body, mutated when pr-body egress applies.
	 * @return array{files: array<int,array<string,string>>}|\WP_Error
	 */
	private static function preparePullRequestRunArtifacts( array $input, string $repo, string $head, string &$body_text ): array|\WP_Error {
		$artifacts = self::runArtifactsFromInput($input);
		if ( empty($artifacts) ) {
			return array( 'files' => array() );
		}

		$policy = self::runArtifactEgressPolicyFromInput($input, $artifacts);
		if ( empty($policy) ) {
			return array( 'files' => array() );
		}

		$committed_files = array();
		$file_writes     = RunArtifactBundleFileWriter::fileWritesFromArtifacts($artifacts, $policy, (string) ( $input['bundle_root'] ?? '' ));
		foreach ( $file_writes as $file ) {
			$file_result = self::createOrUpdateFile(
				array(
					'repo'           => $repo,
					'file_path'      => $file['file_path'],
					'content'        => $file['content'],
					'commit_message' => $file['commit_message'],
					'branch'         => $head,
				)
			);

			if ( is_wp_error($file_result) ) {
				return $file_result;
			}

			$committed_files[] = array(
				'file_path'  => (string) ( $file_result['content']['path'] ?? $file['file_path'] ),
				'commit_sha' => (string) ( $file_result['commit']['sha'] ?? '' ),
				'file_url'   => (string) ( $file_result['content']['html_url'] ?? '' ),
			);
		}

		$body_artifacts = self::filterRunArtifactsForEgressTarget($artifacts, $policy, 'pr-body');
		if ( ! empty($body_artifacts) ) {
			$body_text = RunArtifactPrSectionRenderer::renderForMode(RunArtifactPrSectionRenderer::MODE_BODY_SECTION, $body_artifacts, $body_text);
		}

		return array( 'files' => $committed_files );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function runArtifactsFromInput( array $input ): array {
		if ( is_array($input['run_artifacts'] ?? null) ) {
			return $input['run_artifacts'];
		}

		$job_id = (int) ( $input['job_id'] ?? 0 );
		if ( $job_id <= 0 || ! class_exists('\\DataMachine\\Core\\JobArtifacts') ) {
			return array();
		}

		$result = ( new \DataMachine\Core\JobArtifacts() )->get($job_id);
		if ( empty($result['success']) || ! is_array($result['artifacts'] ?? null) ) {
			return array();
		}

		return $result['artifacts'];
	}

	/**
	 * @param  array<string,mixed> $artifacts Run artifact payload.
	 * @return array<string,mixed>
	 */
	private static function runArtifactEgressPolicyFromInput( array $input, array $artifacts ): array {
		if ( is_array($input['run_artifact_egress_policy'] ?? null) ) {
			return $input['run_artifact_egress_policy'];
		}

		$engine = $input['engine'] ?? null;
		if ( is_object($engine) && method_exists($engine, 'get') ) {
			$policy = $engine->get('run_artifact_egress_policy', array());
			if ( is_array($policy) && ! empty($policy) ) {
				return $policy;
			}
		}

		return is_array($artifacts['run_artifact_egress_policy'] ?? null) ? $artifacts['run_artifact_egress_policy'] : array();
	}

	/**
	 * @param  array<string,mixed> $artifacts Run artifact payload.
	 * @param  array<string,mixed> $policy    Egress policy.
	 * @return array<string,mixed>
	 */
	private static function filterRunArtifactsForEgressTarget( array $artifacts, array $policy, string $target ): array {
		$filtered = array();

		if ( self::runArtifactPolicyAllowsTarget($policy, 'daily_memory', $target) && ! empty($artifacts['daily_memory_artifacts']) ) {
			$filtered['daily_memory_artifacts'] = $artifacts['daily_memory_artifacts'];
		}

		if ( self::runArtifactPolicyAllowsTarget($policy, 'completion_assertions', $target) ) {
			foreach ( array( 'required_tool_names', 'satisfied_tool_names', 'successful_tool_calls' ) as $key ) {
				if ( array_key_exists($key, $artifacts) ) {
					$filtered[ $key ] = $artifacts[ $key ];
				}
			}

			$satisfied = is_array($filtered['satisfied_tool_names'] ?? null) ? $filtered['satisfied_tool_names'] : array();
			foreach ( array( 'create_github_pull_request', 'github_pull_request_publish' ) as $tool_name ) {
				if ( ! in_array($tool_name, $satisfied, true) ) {
					$satisfied[] = $tool_name;
				}
			}
			$filtered['satisfied_tool_names'] = $satisfied;
		}

		if ( self::runArtifactPolicyAllowsTarget($policy, 'transcript_summary', $target) && ! empty($artifacts['transcript']) ) {
			$filtered['transcript'] = $artifacts['transcript'];
		}

		return $filtered;
	}

	/**
	 * @param array<string,mixed> $policy Egress policy.
	 */
	private static function runArtifactPolicyAllowsTarget( array $policy, string $source, string $target ): bool {
		$egress = $policy[ $source ]['egress'] ?? array();
		return is_array($egress) && in_array($target, $egress, true);
	}

	/**
	 * Merge caller-provided labels with runtime agent provenance labels.
	 *
	 * @param  array<int,mixed> $labels Caller-provided labels.
	 * @return array<int,string>
	 */
	private static function mergeProvenanceLabels( array $labels ): array {
		$merged = array();
		foreach ( $labels as $label ) {
			$label = sanitize_text_field( (string) $label);
			if ( '' !== $label && ! in_array($label, $merged, true) ) {
				$merged[] = $label;
			}
		}

		$agent_slug = self::getCurrentAgentSlug();
		if ( '' === $agent_slug ) {
			return $merged;
		}

		foreach ( array( 'agent:' . $agent_slug, 'datamachine-agent' ) as $label ) {
			if ( ! in_array($label, $merged, true) ) {
				$merged[] = $label;
			}
		}

		return $merged;
	}

	/**
	 * Resolve the current Data Machine agent slug when running in agent context.
	 */
	private static function getCurrentAgentSlug(): string {
		$agent_slug = self::agentSlugFromContext(PermissionHelper::get_runtime_context());
		if ( '' !== $agent_slug ) {
			return $agent_slug;
		}

		$principal = PermissionHelper::get_execution_principal();
		if ( is_object($principal) ) {
			$agent_slug = self::agentSlugFromContext(method_exists($principal, 'to_array') ? $principal->to_array() : get_object_vars($principal));
			if ( '' !== $agent_slug ) {
				return $agent_slug;
			}
		}

		$agent_slug = (string) PermissionHelper::get_acting_agent_slug();
		if ( '' !== trim($agent_slug) ) {
			return sanitize_text_field($agent_slug);
		}

		return '';
	}

	/**
	 * Extract agent_slug from a runtime context-like shape.
	 *
	 * @param mixed $context Runtime context, principal array, or metadata.
	 */
	private static function agentSlugFromContext( mixed $context ): string {
		if ( ! is_array($context) ) {
			return '';
		}

		foreach ( array( 'agent_slug', 'current_agent_slug' ) as $key ) {
			$agent_slug = $context[ $key ] ?? null;
			if ( is_string($agent_slug) && '' !== trim($agent_slug) ) {
				return sanitize_text_field($agent_slug);
			}
		}

		foreach ( array( 'runtime_context', 'request_metadata', 'metadata' ) as $key ) {
			$agent_slug = self::agentSlugFromContext($context[ $key ] ?? null);
			if ( '' !== $agent_slug ) {
				return $agent_slug;
			}
		}

		return '';
	}

	/**
	 * Apply labels to an issue or pull request number.
	 *
	 * @param  string            $repo   owner/repo identifier.
	 * @param  int               $number Issue or pull request number.
	 * @param  array<int,string> $labels Labels to add.
	 * @param  string            $pat    GitHub token.
	 * @return array|\WP_Error
	 */
	private static function applyLabelsToNumber( string $repo, int $number, array $labels, string $pat ): array|\WP_Error {
		$url      = GitHubRemote::apiUrl($repo, 'issues/' . $number . '/labels');
		$response = self::apiRequest('POST', $url, array( 'labels' => $labels ), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$applied = array();
		if ( is_array($response['data'] ?? null) ) {
			foreach ( $response['data'] as $label ) {
				$name = is_array($label) ? ( $label['name'] ?? '' ) : '';
				if ( '' !== $name ) {
					$applied[] = (string) $name;
				}
			}
		}

		return array(
			'success'        => true,
			'applied_labels' => $applied,
		);
	}

	/**
	 * Add labels to an issue or pull request.
	 *
	 * GitHub treats pull requests as issues for labeling purposes, so this
	 * single endpoint covers both. Calls
	 * `POST /repos/{owner}/{repo}/issues/{number}/labels`, which is additive
	 * (does not remove existing labels).
	 *
	 * @param  array $input {
	 *                      Required: repo, issue_number (or pull_number), labels (array<string>).
	 *                      }
	 * @return array|\WP_Error { success: bool, applied_labels: string[] } or error.
	 */
	public static function addLabels( array $input ): array|\WP_Error {
		$repo   = self::resolveRepo(sanitize_text_field($input['repo'] ?? ''));
		$number = (int) ( $input['issue_number'] ?? $input['pull_number'] ?? 0 );

		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ));
		}
		if ( $number <= 0 ) {
			return new \WP_Error('missing_number', 'issue_number or pull_number is required.', array( 'status' => 400 ));
		}

		$labels = array();
		if ( isset($input['labels']) && is_array($input['labels']) ) {
			$labels = array_values(
				array_filter(
					array_map(
						static fn( $label ): string => sanitize_text_field( (string) $label),
						$input['labels']
					),
					static fn( string $label ): bool => '' !== $label
				)
			);
		}

		if ( empty($labels) ) {
			return new \WP_Error('missing_labels', 'At least one label is required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_write');
		if ( empty($pat) ) {
			return self::patError();
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues/' . $number . '/labels');
		$response = self::apiRequest('POST', $url, array( 'labels' => $labels ), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$applied = array();
		if ( is_array($response['data'] ?? null) ) {
			foreach ( $response['data'] as $label ) {
				$name = is_array($label) ? ( $label['name'] ?? '' ) : '';
				if ( '' !== $name ) {
					$applied[] = (string) $name;
				}
			}
		}

		return array(
			'success'        => true,
			'applied_labels' => $applied,
			'message'        => sprintf('Applied %d label(s) to #%d in %s.', count($applied), $number, $repo),
		);
	}

	/**
	 * Remove a single label from an issue or pull request.
	 *
	 * GitHub treats pull requests as issues for labeling purposes, so this
	 * single endpoint covers both. Calls
	 * `DELETE /repos/{owner}/{repo}/issues/{number}/labels/{name}`, which is
	 * surgical (removes one label, leaves all others untouched). If GitHub
	 * returns 404 because the label is already absent, the desired state is
	 * already achieved and this returns success.
	 *
	 * @param  array $input {
	 *                      Required: repo, issue_number (or pull_number), label.
	 *                      }
	 * @return array|\WP_Error { success: bool, removed_label: string } or error.
	 */
	public static function removeLabel( array $input ): array|\WP_Error {
		$repo   = self::resolveRepo(sanitize_text_field($input['repo'] ?? ''));
		$number = (int) ( $input['issue_number'] ?? $input['pull_number'] ?? 0 );

		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ));
		}
		if ( $number <= 0 ) {
			return new \WP_Error('missing_number', 'issue_number or pull_number is required.', array( 'status' => 400 ));
		}

		$label = sanitize_text_field( (string) ( $input['label'] ?? '' ));
		if ( '' === $label ) {
			return new \WP_Error('missing_label', 'A single label is required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_write');
		if ( empty($pat) ) {
			return self::patError();
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues/' . $number . '/labels/' . rawurlencode($label));
		$response = self::apiRequest('DELETE', $url, null, $pat);

		if ( is_wp_error($response) ) {
			$error_data = $response->get_error_data();
			$status     = is_array($error_data) ? (int) ( $error_data['status'] ?? 0 ) : 0;
			if ( 'github_not_found' === $response->get_error_code() || 404 === $status ) {
				return array(
					'success'       => true,
					'removed_label' => $label,
					'message'       => sprintf('Label %s was already absent from #%d in %s.', $label, $number, $repo),
				);
			}

			return $response;
		}

		return array(
			'success'       => true,
			'removed_label' => $label,
			'message'       => sprintf('Removed label %s from #%d in %s.', $label, $number, $repo),
		);
	}

	/**
	 * Resolve the default branch name for a repository.
	 *
	 * @param  string $repo owner/repo identifier.
	 * @param  string $pat  GitHub credential token.
	 * @return string|\WP_Error Default branch name or error.
	 */
	private static function resolveDefaultBranch( string $repo, string $pat ): string|\WP_Error {
		$response = self::apiGet(GitHubRemote::apiUrl($repo), array(), $pat);
		if ( is_wp_error($response) ) {
			return $response;
		}

		$default_branch = (string) ( $response['data']['default_branch'] ?? '' );
		if ( '' === $default_branch ) {
			return new \WP_Error('default_branch_missing', 'GitHub did not return a default branch for the repository.', array( 'status' => 500 ));
		}

		return $default_branch;
	}

	public static function commentOnIssue( array $input ): array|\WP_Error {
		$repo         = sanitize_text_field($input['repo'] ?? '');
		$issue_number = (int) ( $input['issue_number'] ?? 0 );
		$body         = $input['body'] ?? '';
		$skip_guard   = ! empty($input['skip_automation_comment_guard']);
		$allow_repeat = ! empty($input['allow_repeat_automation_comment']);

		if ( empty($repo) || $issue_number <= 0 || empty($body) ) {
			return new \WP_Error('missing_params', 'Repository, issue_number, and body are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepoCapability($repo, 'issues_write');
		if ( empty($pat) ) {
			return self::patError();
		}

		if ( ! $skip_guard && ! $allow_repeat ) {
			$guard = self::checkIssueAutomationCommentTurn($repo, $issue_number, $pat);
			if ( is_wp_error($guard) ) {
				return $guard;
			}
		}

		$url      = GitHubRemote::apiUrl($repo, 'issues/' . $issue_number . '/comments');
		$response = self::apiRequest('POST', $url, array( 'body' => $body ), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		return array(
			'success' => true,
			'comment' => array(
				'id'         => $response['data']['id'] ?? 0,
				'html_url'   => $response['data']['html_url'] ?? '',
				'created_at' => $response['data']['created_at'] ?? '',
			),
			'message' => sprintf('Comment added to issue #%d.', $issue_number),
		);
	}

	/**
	 * Block repeated issue comments when this automation actor already owns the latest turn.
	 *
	 * @param  string $repo         Repository in owner/repo format.
	 * @param  int    $issue_number Issue number.
	 * @param  string $pat          GitHub credential token.
	 * @return true|\WP_Error True when a comment may be posted, or an error explaining why not.
	 */
	private static function checkIssueAutomationCommentTurn( string $repo, int $issue_number, string $pat ): true|\WP_Error {
		$issue = self::apiGet(GitHubRemote::apiUrl($repo, 'issues/' . $issue_number), array(), $pat);
		if ( is_wp_error($issue) ) {
			return $issue;
		}

		$comment_count = max(0, (int) ( $issue['data']['comments'] ?? 0 ));
		if ( 0 === $comment_count ) {
			return true;
		}

		$actor = self::getAuthenticatedGitHubLogin($pat);
		if ( is_wp_error($actor) ) {
			return $actor;
		}

		$comments = self::apiGet(
			GitHubRemote::apiUrl($repo, 'issues/' . $issue_number . '/comments'),
			array(
				'per_page' => self::MAX_PER_PAGE,
				'page'     => (int) ceil($comment_count / self::MAX_PER_PAGE),
			),
			$pat
		);

		if ( is_wp_error($comments) ) {
			return $comments;
		}

		$items = is_array($comments['data'] ?? null) ? $comments['data'] : array();
		if ( empty($items) ) {
			return true;
		}

		$latest = end($items);
		if ( ! is_array($latest) ) {
			return true;
		}

		$latest_login = (string) ( $latest['user']['login'] ?? '' );
		if ( '' !== $latest_login && strcasecmp($latest_login, $actor) === 0 ) {
			return new \WP_Error(
				'github_issue_automation_turn_closed',
				'This issue already has the latest comment from the automation actor; wait for newer external activity or pass allow_repeat_automation_comment=true.',
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Get the GitHub login for the credential currently posting comments.
	 *
	 * @param  string $pat GitHub credential token.
	 * @return string|\WP_Error GitHub login or error.
	 */
	private static function getAuthenticatedGitHubLogin( string $pat ): string|\WP_Error {
		static $cache = array();

		if ( isset($cache[ $pat ]) ) {
			return $cache[ $pat ];
		}

		$response = self::apiGet(self::API_BASE . '/user', array(), $pat);
		if ( is_wp_error($response) ) {
			return $response;
		}

		$login = (string) ( $response['data']['login'] ?? '' );
		if ( '' === $login ) {
			return new \WP_Error('github_actor_missing', 'GitHub did not return a login for the current credential.', array( 'status' => 500 ));
		}

		$cache[ $pat ] = $login;
		return $login;
	}

	public static function commentOnPullRequest( array $input ): array|\WP_Error {
		return self::commentOnIssue(self::buildPullRequestCommentInput($input));
	}

	/**
	 * Merge an open pull request after re-checking the head SHA.
	 *
	 * Calls `GET /repos/{owner}/{repo}/pulls/{pull_number}` immediately before
	 * `PUT /repos/{owner}/{repo}/pulls/{pull_number}/merge`.
	 *
	 * @param  array         $input       Required: repo, pull_number, expected_head_sha. Optional: merge_method, delete_branch.
	 * @param  callable|null $api_get     Optional test seam: fn(string $url, array $query, string $pat): array|WP_Error.
	 * @param  callable|null $api_request Optional test seam: fn(string $method, string $url, array $body, string $pat): array|WP_Error.
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function mergePullRequest( array $input, ?callable $api_get = null, ?callable $api_request = null ): array|\WP_Error {
		$repo              = sanitize_text_field($input['repo'] ?? '');
		$pull_number       = (int) ( $input['pull_number'] ?? 0 );
		$expected_head_sha = sanitize_text_field($input['expected_head_sha'] ?? '');
		$merge_method      = sanitize_text_field($input['merge_method'] ?? 'squash');
		$delete_branch     = ! empty($input['delete_branch']);

		if ( empty($repo) || $pull_number <= 0 || '' === $expected_head_sha ) {
			return new \WP_Error('missing_params', 'Repository, pull_number, and expected_head_sha are required.', array( 'status' => 400 ));
		}

		if ( ! in_array($merge_method, array( 'merge', 'squash', 'rebase' ), true) ) {
			return new \WP_Error('invalid_merge_method', 'merge_method must be merge, squash, or rebase.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$api_get     = $api_get ?? array( self::class, 'apiGet' );
		$api_request = $api_request ?? array( self::class, 'apiRequest' );
		$pull_url    = GitHubRemote::apiUrl($repo, 'pulls/' . $pull_number);

		$pull_response = $api_get($pull_url, array(), $pat);
		if ( is_wp_error($pull_response) ) {
			return $pull_response;
		}

		$pull = is_array($pull_response['data'] ?? null) ? $pull_response['data'] : array();
		if ( 'open' !== (string) ( $pull['state'] ?? '' ) ) {
			return new \WP_Error('pull_request_not_open', 'Pull request must be open before merge.', array( 'status' => 409 ));
		}

		$current_head_sha = sanitize_text_field($pull['head']['sha'] ?? '');
		if ( '' === $current_head_sha || $current_head_sha !== $expected_head_sha ) {
			return new \WP_Error('pull_request_head_sha_mismatch', 'Pull request head SHA does not match expected_head_sha.', array( 'status' => 409 ));
		}

		$merge_url = GitHubRemote::apiUrl($repo, 'pulls/' . $pull_number . '/merge');
		$response  = $api_request('PUT', $merge_url, array( 'merge_method' => $merge_method ), $pat);
		if ( is_wp_error($response) ) {
			return $response;
		}

		$data = is_array($response['data'] ?? null) ? $response['data'] : array();

		$result = array(
			'success'          => true,
			'repo'             => $repo,
			'pull_number'      => $pull_number,
			'merged'           => (bool) ( $data['merged'] ?? false ),
			'sha'              => (string) ( $data['sha'] ?? '' ),
			'message'          => (string) ( $data['message'] ?? '' ),
			'html_url'         => (string) ( $pull['html_url'] ?? '' ),
			'pull_request_url' => (string) ( $pull['html_url'] ?? '' ),
			'merge_method'     => $merge_method,
		);

		if ( $delete_branch && ! empty($result['merged']) ) {
			$result['cleanup'] = self::cleanupPullRequest(
				array(
					'repo'        => $repo,
					'pull_number' => $pull_number,
				),
				static fn( string $url, array $query, string $credential ): array|\WP_Error => $api_get($url, $query, $credential),
				static fn( string $method, string $url, ?array $body, string $credential ): array|\WP_Error => $api_request($method, $url, $body, $credential)
			);
		}

		return $result;
	}

	/**
	 * Delete a merged pull request head branch through the GitHub API.
	 *
	 * This intentionally does not use local git or `gh`, so it is safe to call
	 * from a linked worktree where the base branch is already checked out in the
	 * primary checkout.
	 *
	 * @param  array         $input       Required: repo, pull_number. Optional: dry_run, local_only.
	 * @param  callable|null $api_get     Optional test seam: fn(string $url, array $query, string $pat): array|WP_Error.
	 * @param  callable|null $api_request Optional test seam: fn(string $method, string $url, ?array $body, string $pat): array|WP_Error.
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function cleanupPullRequest( array $input, ?callable $api_get = null, ?callable $api_request = null ): array|\WP_Error {
		$repo        = sanitize_text_field($input['repo'] ?? '');
		$pull_number = (int) ( $input['pull_number'] ?? 0 );
		$dry_run     = ! empty($input['dry_run']);
		$local_only  = ! empty($input['local_only']);

		if ( empty($repo) || $pull_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository and pull_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$api_get     = $api_get ?? array( self::class, 'apiGet' );
		$api_request = $api_request ?? array( self::class, 'apiRequest' );
		$pull_url    = GitHubRemote::apiUrl($repo, 'pulls/' . $pull_number);

		$pull_response = $api_get($pull_url, array(), $pat);
		if ( is_wp_error($pull_response) ) {
			return $pull_response;
		}

		$pull        = is_array($pull_response['data'] ?? null) ? $pull_response['data'] : array();
		$head_branch = sanitize_text_field($pull['head']['ref'] ?? '');
		$head_repo   = sanitize_text_field($pull['head']['repo']['full_name'] ?? '');
		$merged      = ! empty($pull['merged_at']);

		if ( ! $merged ) {
			return new \WP_Error('pull_request_not_merged', 'Pull request head branch cleanup requires a merged pull request.', array( 'status' => 409 ));
		}

		if ( '' === $head_branch ) {
			return new \WP_Error('pull_request_head_branch_missing', 'GitHub did not return a pull request head branch.', array( 'status' => 500 ));
		}

		if ( '' !== $head_repo && $head_repo !== $repo ) {
			return new \WP_Error('pull_request_head_repo_mismatch', 'Refusing to delete a pull request branch from a different repository.', array( 'status' => 409 ));
		}

		if ( in_array($head_branch, array( 'main', 'master', 'trunk', 'develop', 'HEAD' ), true) ) {
			return new \WP_Error('protected_head_branch', sprintf('Refusing to delete protected branch %s.', $head_branch), array( 'status' => 409 ));
		}

		$result = array(
			'success'                => true,
			'repo'                   => $repo,
			'pull_number'            => $pull_number,
			'head_branch'            => $head_branch,
			'head_repo'              => '' !== $head_repo ? $head_repo : $repo,
			'branch_deleted'         => false,
			'branch_already_deleted' => false,
			'dry_run'                => $dry_run,
			'local_only'             => $local_only,
			'partial_success'        => false,
			'pull_request_url'       => (string) ( $pull['html_url'] ?? '' ),
		);

		if ( $dry_run ) {
			$result['message'] = sprintf('Would delete branch %s from %s.', $head_branch, $repo);
			return $result;
		}

		$local_cleanup = self::cleanupMergedPullRequestWorktree($repo, $head_branch, (string) ( $pull['html_url'] ?? '' ));
		if ( $local_cleanup instanceof \WP_Error ) {
			$result['local_worktree_cleanup'] = array(
				'success' => false,
				'code'    => $local_cleanup->get_error_code(),
				'message' => $local_cleanup->get_error_message(),
			);
		} else {
			$result['local_worktree_cleanup'] = $local_cleanup;
		}

		if ( $local_only ) {
			$result['message'] = sprintf('Cleaned up local DMC worktree for %s without deleting remote branch %s.', $repo, $head_branch);
			return $result;
		}

		$encoded_head_branch = implode('/', array_map('rawurlencode', explode('/', $head_branch)));
		$delete_url          = GitHubRemote::apiUrl($repo, 'git/refs/heads/' . $encoded_head_branch);
		$deleted             = $api_request('DELETE', $delete_url, null, $pat);
		if ( is_wp_error($deleted) ) {
			$status = is_array($deleted->get_error_data()) ? (int) ( $deleted->get_error_data()['status'] ?? 0 ) : 0;
			if ( 404 !== $status ) {
				if ( true === ( $result['local_worktree_cleanup']['success'] ?? false ) ) {
					$result['partial_success']     = true;
					$result['branch_delete_error'] = array(
						'code'    => $deleted->get_error_code(),
						'message' => $deleted->get_error_message(),
						'status'  => $status,
					);
					$result['message']             = sprintf('Cleaned up local DMC worktree for %s, but could not delete remote branch %s: %s', $repo, $head_branch, $deleted->get_error_message());
					return $result;
				}

				return $deleted;
			}

			$result['branch_already_deleted'] = true;
			$result['message']                = sprintf('Branch %s was already deleted from %s.', $head_branch, $repo);
			return $result;
		}

		$result['branch_deleted'] = true;
		$result['message']        = sprintf('Deleted branch %s from %s.', $head_branch, $repo);
		return $result;
	}

	/**
	 * Best-effort local DMC worktree cleanup for a merged pull request branch.
	 *
	 * @param  string $repo        GitHub repository slug (`owner/repo`).
	 * @param  string $head_branch Pull request head branch.
	 * @param  string $pr_url      Pull request URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function cleanupMergedPullRequestWorktree( string $repo, string $head_branch, string $pr_url ): array|\WP_Error {
		if ( ! class_exists(Workspace::class) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'workspace_unavailable',
			);
		}

		$workspace = new Workspace();
		return $workspace->cleanup_merged_pr_worktree($repo, $head_branch, '' !== $pr_url ? $pr_url : null);
	}

	/**
	 * Create or update one managed bot-authored PR review comment.
	 *
	 * @param  array         $input       Ability input.
	 * @param  callable|null $api_get     Optional test seam: fn(string $url, array $query, string $pat): array|WP_Error.
	 * @param  callable|null $api_request Optional test seam: fn(string $method, string $url, array $body, string $pat): array|WP_Error.
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function upsertPullReviewComment( array $input, ?callable $api_get = null, ?callable $api_request = null ): array|\WP_Error {
		$repo        = sanitize_text_field($input['repo'] ?? '');
		$pull_number = (int) ( $input['pull_number'] ?? 0 );
		$body        = (string) ( $input['body'] ?? '' );
		$mode        = sanitize_text_field($input['mode'] ?? 'update_existing');
		$head_sha    = sanitize_text_field($input['head_sha'] ?? '');

		if ( empty($repo) || $pull_number <= 0 || '' === $body ) {
			return new \WP_Error('missing_params', 'Repository, pull_number, and body are required.', array( 'status' => 400 ));
		}

		if ( ! in_array($mode, array( 'update_existing', 'per_head_sha' ), true) ) {
			return new \WP_Error('invalid_mode', 'Mode must be update_existing or per_head_sha.', array( 'status' => 400 ));
		}

		if ( 'per_head_sha' === $mode && '' === $head_sha ) {
			return new \WP_Error('missing_head_sha', 'head_sha is required when mode is per_head_sha.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$api_get     = $api_get ?? array( self::class, 'apiGet' );
		$api_request = $api_request ?? array( self::class, 'apiRequest' );
		$marker      = self::normalizeManagedCommentMarker($input['marker'] ?? '<!-- datamachine-pr-review -->');
		$target_body = self::buildManagedPullReviewCommentBody($body, $marker, $head_sha, $mode);

		$user_response = $api_get(self::API_BASE . '/user', array(), $pat);
		if ( is_wp_error($user_response) ) {
			return $user_response;
		}

		$login = (string) ( $user_response['data']['login'] ?? '' );
		if ( '' === $login ) {
			return new \WP_Error('github_identity_missing', 'GitHub API did not return the authenticated user login.', array( 'status' => 500 ));
		}

		$comments = array();
		$page     = 1;
		do {
			$comments_url = GitHubRemote::apiUrl($repo, 'issues/' . $pull_number . '/comments');
			$page_result  = $api_get(
				$comments_url,
				array(
					'per_page' => self::MAX_PER_PAGE,
					'page'     => $page,
				),
				$pat
			);
			if ( is_wp_error($page_result) ) {
				return $page_result;
			}

			$page_comments = is_array($page_result['data'] ?? null) ? $page_result['data'] : array();
			$page_count    = count($page_comments);
			$comments      = array_merge($comments, $page_comments);
			++$page;
		} while ( $page_count >= self::MAX_PER_PAGE );

		$existing = self::findManagedPullReviewComment($comments, $login, $marker, $head_sha, $mode);
		if ( null !== $existing ) {
			$comment_id = (int) ( $existing['id'] ?? 0 );
			if ( $comment_id <= 0 ) {
				return new \WP_Error('github_comment_missing_id', 'Matched GitHub comment did not include an id.', array( 'status' => 500 ));
			}

			$response = $api_request(
				'PATCH',
				GitHubRemote::apiUrl($repo, 'issues/comments/' . $comment_id),
				array( 'body' => $target_body ),
				$pat
			);
			if ( is_wp_error($response) ) {
				return $response;
			}

			return self::buildManagedCommentResponse('updated', $response['data'] ?? array());
		}

		$response = $api_request(
			'POST',
			GitHubRemote::apiUrl($repo, 'issues/' . $pull_number . '/comments'),
			array( 'body' => $target_body ),
			$pat
		);
		if ( is_wp_error($response) ) {
			return $response;
		}

		return self::buildManagedCommentResponse('created', $response['data'] ?? array());
	}

	private static function normalizeManagedCommentMarker( string $marker ): string {
		$marker = trim($marker);
		if ( '' === $marker ) {
			$marker = '<!-- datamachine-pr-review -->';
		}

		if ( str_starts_with($marker, '<!--') && str_ends_with($marker, '-->') ) {
			$inner = trim(substr($marker, 4, -3));
		} else {
			$inner = $marker;
		}

		$inner = str_replace('--', '-', $inner);
		return '<!-- ' . trim($inner) . ' -->';
	}

	private static function buildManagedPullReviewCommentBody( string $body, string $marker, string $head_sha, string $mode ): string {
		$markers = array( $marker );
		if ( 'per_head_sha' === $mode ) {
			$markers[] = self::buildManagedHeadMarker($head_sha);
		}

		return rtrim($body) . "\n\n" . implode("\n", $markers);
	}

	private static function buildManagedHeadMarker( string $head_sha ): string {
		return '<!-- datamachine-pr-review-head-sha: ' . str_replace('--', '-', $head_sha) . ' -->';
	}

	private static function findManagedPullReviewComment( array $comments, string $login, string $marker, string $head_sha, string $mode ): ?array {
		$matched = null;
		foreach ( $comments as $comment ) {
			if ( ! is_array($comment) ) {
				continue;
			}

			if ( (string) ( $comment['user']['login'] ?? '' ) !== $login ) {
				continue;
			}

			$comment_body = (string) ( $comment['body'] ?? '' );
			if ( ! str_contains($comment_body, $marker) ) {
				continue;
			}

			if ( 'per_head_sha' === $mode && ! str_contains($comment_body, self::buildManagedHeadMarker($head_sha)) ) {
				continue;
			}

			if ( null === $matched || self::isNewerGitHubComment($comment, $matched) ) {
				$matched = $comment;
			}
		}

		return $matched;
	}

	private static function isNewerGitHubComment( array $candidate, array $current ): bool {
		$candidate_time = (string) ( $candidate['updated_at'] ?? $candidate['created_at'] ?? '' );
		$current_time   = (string) ( $current['updated_at'] ?? $current['created_at'] ?? '' );

		if ( '' === $candidate_time || '' === $current_time ) {
			return true;
		}

		return $current_time <= $candidate_time;
	}

	private static function buildManagedCommentResponse( string $action, array $comment ): array {
		return array(
			'success'    => true,
			'action'     => $action,
			'comment_id' => (int) ( $comment['id'] ?? 0 ),
			'html_url'   => (string) ( $comment['html_url'] ?? '' ),
		);
	}

	private static function buildPullRequestCommentInput( array $input ): array {
		$body = $input['body'] ?? '';
		if ( isset($input['marker']) && '' !== (string) $input['marker'] ) {
			$marker = str_replace('--', '-', trim( (string) $input['marker']));
			$body  .= "\n\n<!-- {$marker} -->";
		}

		return array(
			'repo'                          => $input['repo'] ?? '',
			'issue_number'                  => (int) ( $input['pull_number'] ?? 0 ),
			'body'                          => $body,
			'skip_automation_comment_guard' => true,
		);
	}

	public static function listPulls( array $input ): array|\WP_Error {
		$repo = sanitize_text_field($input['repo'] ?? '');
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$query_params = array(
			'state'    => sanitize_text_field($input['state'] ?? 'open'),
			'per_page' => self::clampPerPage($input['per_page'] ?? self::DEFAULT_PER_PAGE),
			'page'     => max(1, (int) ( $input['page'] ?? 1 )),
		);

		$url      = GitHubRemote::apiUrl($repo, 'pulls');
		$response = self::apiGet($url, $query_params, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$normalized = array_map(array( self::class, 'normalizePull' ), $response['data']);

		return array(
			'success'      => true,
			'pulls'        => $normalized,
			'count'        => count($normalized),
			'generated_at' => gmdate('c'),
		);
	}

	/**
	 * Get one pull request with full review context.
	 *
	 * @param  array $input {
	 *                      Required: repo, pull_number. Optional: head_sha, max_patch_chars.
	 *                      }
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function getPullReviewContext( array $input ): array|\WP_Error {
		$repo        = sanitize_text_field($input['repo'] ?? '');
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty($repo) || $pull_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$pull = self::getPull(
			array(
				'repo'        => $repo,
				'pull_number' => $pull_number,
			)
		);
		if ( is_wp_error($pull) ) {
			return $pull;
		}

		$files = array();
		$page  = 1;
		do {
			$file_page = self::listPullFiles(
				array(
					'repo'        => $repo,
					'pull_number' => $pull_number,
					'per_page'    => self::MAX_PER_PAGE,
					'page'        => $page,
				)
			);
			if ( is_wp_error($file_page) ) {
				return $file_page;
			}

			$page_files = $file_page['files'] ?? array();
			$page_count = count($page_files);
			$files      = array_merge($files, $page_files);
			++$page;
		} while ( $page_count >= self::MAX_PER_PAGE );

		$context = self::normalizePullReviewContext(
			$repo,
			$pull['pull'],
			$files,
			array(
				'head_sha'         => sanitize_text_field($input['head_sha'] ?? ''),
				'max_patch_chars'  => (int) ( $input['max_patch_chars'] ?? 200000 ),
				'expanded_context' => self::buildPullReviewExpandedContext($repo, $pull['pull'], $files, $input),
				'checks'           => self::buildPullReviewCheckContext($repo, $pull['pull'], $input),
			)
		);

		if ( is_wp_error($context) ) {
			return $context;
		}

		return array(
			'success' => true,
			'context' => $context,
		);
	}

	/**
	 * Get bounded repository-level context for PR review agents.
	 *
	 * @param  array $input Required: repo. Optional: ref and profile limits.
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function getRepoReviewProfile( array $input ): array|\WP_Error {
		$repo = sanitize_text_field($input['repo'] ?? '');
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$ref = sanitize_text_field($input['ref'] ?? $input['branch'] ?? '');

		$fetcher = static function ( string $path ) use ( $repo, $ref ): array|\WP_Error {
			return self::getFileContents(self::buildRepoPathInput($repo, $path, $ref));
		};

		$tree_fetcher = static function ( string $path ) use ( $repo, $ref ): array|\WP_Error {
			return self::getRepoTree(self::buildRepoPathInput($repo, $path, $ref));
		};

		return array(
			'success' => true,
			'profile' => self::buildRepoReviewProfile($repo, $input, $fetcher, $tree_fetcher),
		);
	}

	/**
	 * Get one pull request as a documentation-impact packet.
	 *
	 * @param  array         $input        Required: repo, pull_number. Optional: head_sha, base_ref, docs_paths.
	 * @param  callable|null $tree_fetcher Optional test seam: fn(string $ref): array|WP_Error.
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function getPullDocumentationImpact( array $input, ?callable $tree_fetcher = null ): array|\WP_Error {
		$repo        = sanitize_text_field($input['repo'] ?? '');
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty($repo) || $pull_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$pull = self::getPull(
			array(
				'repo'        => $repo,
				'pull_number' => $pull_number,
			)
		);
		if ( is_wp_error($pull) ) {
			return $pull;
		}

		$files = array();
		$page  = 1;
		do {
			$file_page = self::listPullFiles(
				array(
					'repo'        => $repo,
					'pull_number' => $pull_number,
					'per_page'    => self::MAX_PER_PAGE,
					'page'        => $page,
				)
			);
			if ( is_wp_error($file_page) ) {
				return $file_page;
			}

			$page_files = $file_page['files'] ?? array();
			$page_count = count($page_files);
			$files      = array_merge($files, $page_files);
			++$page;
		} while ( $page_count >= self::MAX_PER_PAGE );

		$docs_paths = self::normalizeContextPaths($input['docs_paths'] ?? $input['docs_path_allowlist'] ?? array());
		$base_ref   = sanitize_text_field($input['base_ref'] ?? ( $pull['pull']['base_ref'] ?? '' ));
		$repo_docs  = self::resolveDocumentationTreePaths($repo, $base_ref, $docs_paths, $tree_fetcher);

		$packet = self::buildDocumentationImpactPacket(
			$repo,
			$pull['pull'],
			$files,
			array(
				'head_sha'  => sanitize_text_field($input['head_sha'] ?? ''),
				'base_ref'  => $base_ref,
				'repo_docs' => $repo_docs,
			)
		);

		if ( is_wp_error($packet) ) {
			return $packet;
		}

		return array(
			'success' => true,
			'packet'  => $packet,
		);
	}

	/**
	 * Build a deterministic repository review profile from bounded file fetchers.
	 *
	 * @param  string   $repo         Repository in owner/repo format.
	 * @param  array    $options      Limit and ref options.
	 * @param  callable $fetcher      fn(string $path): array|WP_Error.
	 * @param  callable $tree_fetcher fn(string $path): array|WP_Error.
	 * @return array<string,mixed>
	 */
	public static function buildRepoReviewProfile( string $repo, array $options, callable $fetcher, callable $tree_fetcher ): array {
		$max_files = max(1, (int) ( $options['max_profile_files'] ?? 14 ));
		$max_docs  = max(0, (int) ( $options['max_architecture_docs'] ?? 8 ));
		$limits    = array(
			'max_profile_files'     => $max_files,
			'max_file_chars'        => (int) max(1, (int) ( $options['max_file_chars'] ?? 12000 )),
			'max_total_chars'       => (int) max(1, (int) ( $options['max_total_chars'] ?? 60000 )),
			'max_architecture_docs' => $max_docs,
		);

		$profile = array(
			'schema'          => 'data-machine-code/repo-review-profile/v1',
			'repo'            => $repo,
			'profile_files'   => array(),
			'commands'        => array(),
			'review_rules'    => array(),
			'public_surfaces' => array(),
			'docs_surfaces'   => array(),
			'truncation'      => array_merge(
				$limits,
				array(
					'included_files'  => 0,
					'included_chars'  => 0,
					'truncated_files' => 0,
					'skipped_files'   => 0,
					'truncated'       => false,
					'errors'          => array(),
				)
			),
		);

		$paths = array(
			'AGENTS.md',
			'README.md',
			'CONTRIBUTING.md',
			'docs/review-context.md',
			'.dmc/review-profile.json',
		);

		if ( $max_docs > 0 ) {
			$docs_tree = $tree_fetcher('docs');
			if ( is_wp_error($docs_tree) ) {
				if ( ! in_array($docs_tree->get_error_code(), array( 'github_not_found', 'not_found' ), true) ) {
					$profile['truncation']['errors']['docs_tree'] = $docs_tree->get_error_message();
				}
			} else {
				$docs_paths = self::selectReviewProfileDocs($docs_tree['files'] ?? array(), $max_docs);
				$paths      = array_merge($paths, $docs_paths);
			}
		}

		$paths           = array_values(array_unique($paths));
		$remaining_chars = (int) $limits['max_total_chars'];

		foreach ( $paths as $path ) {
			if ( $profile['truncation']['included_files'] >= $limits['max_profile_files'] || $remaining_chars <= 0 ) {
				++$profile['truncation']['skipped_files'];
				$profile['truncation']['truncated'] = true;
				continue;
			}

			$file_result = $fetcher($path);
			if ( is_wp_error($file_result) ) {
				if ( ! in_array($file_result->get_error_code(), array( 'github_not_found', 'not_found' ), true) ) {
					$profile['truncation']['errors'][ $path ] = $file_result->get_error_message();
				}
				continue;
			}

			$file                       = $file_result['files'][0] ?? $file_result;
			$content                    = (string) ( $file['content'] ?? '' );
			$entry                      = self::buildReviewProfileFileEntry($file, $content, $limits['max_file_chars'], $remaining_chars);
			$profile['profile_files'][] = $entry;

			++$profile['truncation']['included_files'];
			$profile['truncation']['included_chars'] += $entry['included_chars'];
			$remaining_chars                          = (int) max(0, $remaining_chars - $entry['included_chars']);

			if ( ! empty($entry['truncated']) ) {
				++$profile['truncation']['truncated_files'];
				$profile['truncation']['truncated'] = true;
			}

			self::applyReviewProfileStructuredFile($profile, $path, $content);
		}

		return $profile;
	}

	/**
	 * Select deterministic architecture/development docs from a tree listing.
	 */
	private static function selectReviewProfileDocs( array $files, int $limit ): array {
		$paths = array();
		foreach ( $files as $file ) {
			$path       = (string) ( $file['path'] ?? '' );
			$lower_path = strtolower($path);
			$base       = basename($lower_path);
			if ( ! str_starts_with($path, 'docs/') || ! str_ends_with($base, '.md') ) {
				continue;
			}

			if ( str_contains($lower_path, 'architecture') || str_contains($lower_path, 'development') ) {
				$paths[] = $path;
			}
		}

		sort($paths, SORT_STRING);
		return array_slice(array_values(array_unique($paths)), 0, $limit);
	}

	/**
	 * Build one bounded profile file entry.
	 */
	private static function buildReviewProfileFileEntry( array $file, string $content, int $max_file_chars, int $remaining_chars ): array {
		$original = strlen($content);
		$limit    = min($max_file_chars, $remaining_chars);
		$included = substr($content, 0, $limit);
		$chars    = strlen($included);

		return array(
			'path'           => (string) ( $file['path'] ?? '' ),
			'sha'            => (string) ( $file['sha'] ?? '' ),
			'size'           => (int) ( $file['size'] ?? $original ),
			'html_url'       => (string) ( $file['html_url'] ?? '' ),
			'content'        => $included,
			'included_chars' => $chars,
			'original_chars' => $original,
			'truncated'      => $chars < $original,
		);
	}

	/**
	 * Extract machine-readable review profile fields from known files.
	 */
	private static function applyReviewProfileStructuredFile( array &$profile, string $path, string $content ): void {
		if ( '.dmc/review-profile.json' === $path ) {
			$decoded = json_decode($content, true);
			if ( is_array($decoded) ) {
				foreach ( array( 'commands', 'review_rules', 'public_surfaces', 'docs_surfaces' ) as $key ) {
					if ( isset($decoded[ $key ]) && is_array($decoded[ $key ]) ) {
						$profile[ $key ] = self::mergeReviewProfileField($profile[ $key ], $decoded[ $key ]);
					}
				}
			}
		}

		if ( str_starts_with($path, 'docs/') ) {
			$profile['docs_surfaces'][] = $path;
			$profile['docs_surfaces']   = array_values(array_unique($profile['docs_surfaces']));
		}
	}

	/**
	 * Merge list or map fields while preserving deterministic keys.
	 */
	private static function mergeReviewProfileField( array $current, array $incoming ): array {
		if ( array_is_list($current) && array_is_list($incoming) ) {
			return array_values(array_unique(array_merge($current, $incoming), SORT_REGULAR));
		}

		return array_merge($current, $incoming);
	}

	/**
	 * Build common repo/path/ref input for GitHub file and tree reads.
	 */
	private static function buildRepoPathInput( string $repo, string $path, string $ref ): array {
		$args = array(
			'repo' => $repo,
			'path' => $path,
		);
		if ( '' !== $ref ) {
			$args['ref'] = $ref;
		}

		return $args;
	}

	/**
	 * Build a heuristic documentation-impact packet from normalized PR files.
	 *
	 * DMC intentionally emits code-derived facts only; downstream content systems
	 * decide which docs/wiki/content artifacts to update.
	 */
	public static function buildDocumentationImpactPacket( string $repo, array $pull, array $files, array $options = array() ): array|\WP_Error {
		$pull_number = (int) ( $pull['number'] ?? 0 );
		$head_sha    = (string) ( $pull['head_sha'] ?? '' );
		$expected    = (string) ( $options['head_sha'] ?? '' );

		if ( '' !== $expected && '' !== $head_sha && $expected !== $head_sha ) {
			return new \WP_Error(
				'github_pr_head_sha_mismatch',
				sprintf('GitHub PR head SHA mismatch for %s#%d: expected %s, found %s.', $repo, $pull_number, $expected, $head_sha),
				array( 'status' => 409 )
			);
		}

		$packet = array(
			'schema'                   => 'data-machine-code/pr-documentation-impact/v1',
			'repo'                     => $repo,
			'pull_number'              => $pull_number,
			'head_sha'                 => $head_sha,
			'base_ref'                 => (string) ( $options['base_ref'] ?? $pull['base_ref'] ?? $pull['base'] ?? '' ),
			'impacts'                  => array(),
			'changed_docs'             => array(),
			'likely_stale_docs'        => array(),
			'suggested_search_queries' => array(),
			'evidence'                 => array(),
		);

		$changed_docs = array();
		$impact_keys  = array();
		$evidence_ids = array();

		foreach ( $files as $file ) {
			$path  = (string) ( $file['filename'] ?? '' );
			$patch = (string) ( $file['patch'] ?? '' );
			if ( '' === $path ) {
				continue;
			}

			if ( self::isDocumentationPath($path) ) {
				$changed_docs[ $path ] = array(
					'path'      => $path,
					'status'    => $file['status'] ?? '',
					'additions' => (int) ( $file['additions'] ?? 0 ),
					'deletions' => (int) ( $file['deletions'] ?? 0 ),
					'evidence'  => self::appendDocumentationEvidence($packet['evidence'], $evidence_ids, $path, 'changed_doc', 'Documentation file changed in the pull request.'),
				);
			}

			foreach ( self::detectDocumentationImpactsForFile($path, $patch) as $impact ) {
				$key = $impact['category'] . '|' . $impact['type'] . '|' . $path . '|' . ( $impact['symbol'] ?? '' );
				if ( isset($impact_keys[ $key ]) ) {
					continue;
				}

				$evidence_id = self::appendDocumentationEvidence($packet['evidence'], $evidence_ids, $path, $impact['type'], $impact['reason'], $impact['snippet'] ?? '');
				unset($impact['snippet']);
				$impact['path']      = $path;
				$impact['evidence']  = array( $evidence_id );
				$packet['impacts'][] = $impact;
				$impact_keys[ $key ] = true;
			}
		}

		$packet['changed_docs']             = array_values($changed_docs);
		$packet['likely_stale_docs']        = self::suggestLikelyStaleDocs($packet['impacts'], array_keys($changed_docs), $options['repo_docs'] ?? array());
		$packet['suggested_search_queries'] = self::suggestDocumentationSearchQueries($repo, $packet['impacts'], array_keys($changed_docs));

		return $packet;
	}

	/**
	 * Build optional full-file context for PR review packets.
	 *
	 * @param  string        $repo    Repository in owner/repo format.
	 * @param  array         $pull    Normalized pull request payload.
	 * @param  array         $files   Normalized changed file payloads.
	 * @param  array         $options Expansion options.
	 * @param  callable|null $fetcher Optional test seam: fn(string $path, string $ref, string $side): array|WP_Error.
	 * @return array|null Expanded context object, or null when no expansion was requested.
	 */
	public static function buildPullReviewExpandedContext( string $repo, array $pull, array $files, array $options = array(), ?callable $fetcher = null ): ?array {
		$include_file_contents = ! empty($options['include_file_contents']);
		$include_base_contents = ! empty($options['include_base_contents']);
		$context_paths         = self::normalizeContextPaths($options['context_paths'] ?? array());

		if ( ! $include_file_contents && empty($context_paths) ) {
			return null;
		}

		$limits = array(
			'max_file_content_chars'  => max(1, (int) ( $options['max_file_content_chars'] ?? 20000 )),
			'max_context_files'       => max(1, (int) ( $options['max_context_files'] ?? 10 )),
			'max_total_context_chars' => max(1, (int) ( $options['max_total_context_chars'] ?? 100000 )),
		);

		$head_ref = (string) ( $pull['head_sha'] ?? $pull['head_ref'] ?? $pull['head'] ?? '' );
		$base_ref = (string) ( $pull['base_sha'] ?? $pull['base_ref'] ?? $pull['base'] ?? '' );
		$fetcher  = $fetcher ?? static function ( string $path, string $ref, string $side ) use ( $repo ): array|\WP_Error {
			unset($side);

			return self::getFileContents(
				array(
					'repo'   => $repo,
					'path'   => $path,
					'branch' => $ref,
				)
			);
		};

		$expanded = array(
			'changed_files' => array(),
			'extra_files'   => array(),
			'skipped'       => array(),
			'limits'        => $limits,
			'summary'       => array(
				'included_files' => 0,
				'included_chars' => 0,
				'skipped_files'  => 0,
				'truncated'      => false,
			),
		);

		$remaining_files = $limits['max_context_files'];
		$remaining_chars = $limits['max_total_context_chars'];

		if ( $include_file_contents ) {
			foreach ( $files as $file ) {
				$path = (string) ( $file['filename'] ?? '' );
				if ( '' === $path ) {
					continue;
				}

				if ( $remaining_files <= 0 || $remaining_chars <= 0 ) {
					self::recordExpandedContextSkip($expanded, $path, 'changed_file', 'limit_exceeded');
					continue;
				}

				$entry = array(
					'path' => $path,
				);

				if ( '' !== $head_ref ) {
					$entry['head'] = self::fetchBoundedContextFile($fetcher, $path, $head_ref, 'head', $limits['max_file_content_chars'], $remaining_chars);
					self::applyContextFileAccounting($expanded, $entry['head'], $remaining_chars);
				}

				if ( $include_base_contents && '' !== $base_ref && $remaining_chars > 0 ) {
					$entry['base'] = self::fetchBoundedContextFile($fetcher, $path, $base_ref, 'base', $limits['max_file_content_chars'], $remaining_chars);
					self::applyContextFileAccounting($expanded, $entry['base'], $remaining_chars);
				}

				$expanded['changed_files'][] = $entry;
				--$remaining_files;
			}
		}

		foreach ( $context_paths as $path ) {
			if ( $remaining_files <= 0 || $remaining_chars <= 0 ) {
				self::recordExpandedContextSkip($expanded, $path, 'context_path', 'limit_exceeded');
				continue;
			}

			$file_context = self::fetchBoundedContextFile($fetcher, $path, $head_ref, 'head', $limits['max_file_content_chars'], $remaining_chars);
			self::applyContextFileAccounting($expanded, $file_context, $remaining_chars);
			$expanded['extra_files'][] = array(
				'path' => $path,
				'head' => $file_context,
			);
			--$remaining_files;
		}

		$expanded['summary']['included_files'] = count($expanded['changed_files']) + count($expanded['extra_files']);
		return $expanded;
	}

	/**
	 * Build optional CI context for PR review packets.
	 */
	private static function buildPullReviewCheckContext( string $repo, array $pull, array $options = array() ): ?array {
		$include_checks   = ! empty($options['include_checks']);
		$include_statuses = ! empty($options['include_statuses']);

		if ( ! $include_checks && ! $include_statuses ) {
			return null;
		}

		$sha = (string) ( $pull['head_sha'] ?? '' );
		if ( '' === $sha ) {
			return array(
				'sha'    => '',
				'errors' => array( 'missing_head_sha' => 'Pull request head SHA is missing.' ),
			);
		}

		$context = array(
			'sha' => $sha,
		);

		if ( $include_checks ) {
			$checks = self::getCheckRuns(
				array(
					'repo'                 => $repo,
					'sha'                  => $sha,
					'per_page'             => $options['max_check_runs'] ?? self::DEFAULT_PER_PAGE,
					'include_check_output' => ! empty($options['include_check_output']),
				)
			);

			if ( is_wp_error($checks) ) {
				$context['errors']['check_runs']      = $checks->get_error_message();
				$context['error_codes']['check_runs'] = $checks->get_error_code();
			} else {
				$context['check_runs'] = array(
					'summary' => $checks['summary'] ?? array(),
					'items'   => $checks['check_runs'] ?? array(),
					'count'   => $checks['count'] ?? 0,
				);
			}
		}

		if ( $include_statuses ) {
			$statuses = self::getCommitStatuses(
				array(
					'repo' => $repo,
					'sha'  => $sha,
				)
			);

			if ( is_wp_error($statuses) ) {
				$context['errors']['commit_statuses']      = $statuses->get_error_message();
				$context['error_codes']['commit_statuses'] = $statuses->get_error_code();
			} else {
				$context['commit_statuses'] = array(
					'summary' => $statuses['summary'] ?? array(),
					'items'   => $statuses['statuses'] ?? array(),
					'count'   => $statuses['count'] ?? 0,
				);
			}
		}

		return $context;
	}

	/**
	 * Normalize context paths from array or comma/newline separated string input.
	 */
	private static function normalizeContextPaths( mixed $paths ): array {
		if ( is_string($paths) ) {
			$split_paths = preg_split('/[\r\n,]+/', $paths);
			$paths       = false === $split_paths ? array() : $split_paths;
		}

		if ( ! is_array($paths) ) {
			return array();
		}

		$normalized = array();
		foreach ( $paths as $path ) {
			$path = trim( (string) $path);
			$path = ltrim($path, '/');
			if ( '' === $path || str_contains($path, '..') ) {
				continue;
			}
			$normalized[ $path ] = true;
		}

		return array_keys($normalized);
	}

	private static function resolveDocumentationTreePaths( string $repo, string $ref, array $docs_paths, ?callable $tree_fetcher = null ): array {
		if ( ! empty($docs_paths) ) {
			return array_values(array_filter($docs_paths, array( self::class, 'isDocumentationPath' )));
		}

		$tree_fetcher = $tree_fetcher ?? static function ( string $lookup_ref ) use ( $repo ): array|\WP_Error {
			return self::getRepoTree(
				array(
					'repo' => $repo,
					'ref'  => '' !== $lookup_ref ? $lookup_ref : 'HEAD',
				)
			);
		};

		$result = $tree_fetcher($ref);
		if ( is_wp_error($result) ) {
			return array();
		}

		$paths = array();
		foreach ( $result['files'] ?? array() as $file ) {
			$path = (string) ( $file['path'] ?? $file['filename'] ?? '' );
			if ( self::isDocumentationPath($path) ) {
				$paths[ $path ] = true;
			}
		}

		return array_keys($paths);
	}

	private static function isDocumentationPath( string $path ): bool {
		$path  = ltrim(strtolower($path), '/');
		$base  = basename($path);
		$parts = explode('/', $path);

		if ( preg_match('/\.(md|mdx|rst|adoc|txt)$/', $path) ) {
			return true;
		}

		if ( in_array($base, array( 'readme', 'readme.txt', 'handbook.txt' ), true) ) {
			return true;
		}

		return ! empty(array_intersect($parts, array( 'docs', 'doc', 'documentation', 'handbook', 'wiki' )));
	}

	private static function detectDocumentationImpactsForFile( string $path, string $patch ): array {
		$path_lc  = strtolower($path);
		$haystack = $path . "\n" . $patch;
		$impacts  = array();

		if ( str_contains($path_lc, '/cli/') || str_contains($path_lc, 'command.php') || str_contains($haystack, 'WP_CLI::add_command') || preg_match('/\+\s*\$assoc_args\[[\'\"][^\'\"]+[\'\"]\]/', $patch) ) {
			$impacts[] = self::documentationImpact('wp_cli', 'changed_wp_cli_surface', 'Changed WP-CLI command surface or option handling.', $patch, self::extractWpCliSymbol($path, $patch));
		}

		if ( str_contains($path_lc, '/abilities/') || str_contains($path_lc, '/tools/') || str_contains($haystack, 'wp_register_ability') || str_contains($haystack, 'registerTool') || str_contains($haystack, 'input_schema') || str_contains($haystack, 'output_schema') ) {
			$impacts[] = self::documentationImpact('abilities_tools', 'changed_ability_or_tool_schema', 'Changed ability/tool registration or schema surface.', $patch, self::extractQuotedSymbol($patch, '/[\'\"]datamachine\/[a-z0-9\-_]+[\'\"]/i'));
		}

		if ( str_contains($path_lc, 'rest') || str_contains($path_lc, 'webhook') || str_contains($haystack, 'register_rest_route') || str_contains($haystack, 'wp-json') || str_contains($haystack, 'webhook') ) {
			$impacts[] = self::documentationImpact('rest_webhook', 'changed_rest_or_webhook_contract', 'Changed REST endpoint, webhook verifier, payload, or scheduling contract.', $patch);
		}

		if ( str_contains($path_lc, 'settings') || str_contains($path_lc, 'config') || str_contains($haystack, 'PluginSettings::') || preg_match('/[\'\"](datamachine_code_|github_)[a-z0-9_\-]+[\'\"]/', $patch) ) {
			$impacts[] = self::documentationImpact('settings_config', 'changed_setting_or_config_key', 'Changed settings/config key or schema surface.', $patch, self::extractQuotedSymbol($patch, '/[\'\"](?:datamachine_code_|github_)[a-z0-9_\-]+[\'\"]/i'));
		}

		if ( preg_match('/\.php$/', $path_lc) && preg_match('/^\+\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)|^\+\s*(?:public\s+)?(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)|^\+\s*(?:public\s+)?const\s+([A-Z_][A-Z0-9_]*)/m', $patch) ) {
			$impacts[] = self::documentationImpact('public_php', 'changed_public_php_symbol', 'Changed public PHP class, function, method, or constant surface.', $patch, self::extractPhpSymbol($patch));
		}

		return $impacts;
	}

	private static function documentationImpact( string $category, string $type, string $reason, string $patch, string $symbol = '' ): array {
		$impact = array(
			'category'   => $category,
			'type'       => $type,
			'reason'     => $reason,
			'confidence' => '' === $patch ? 'medium' : 'high',
			'snippet'    => self::firstAddedPatchLine($patch),
		);

		if ( '' !== $symbol ) {
			$impact['symbol'] = $symbol;
		}

		return $impact;
	}

	private static function appendDocumentationEvidence( array &$evidence, array &$seen, string $path, string $kind, string $reason, string $snippet = '' ): string {
		$key = $path . '|' . $kind . '|' . $reason . '|' . $snippet;
		if ( isset($seen[ $key ]) ) {
			return $seen[ $key ];
		}

		$id             = 'e' . ( count($evidence) + 1 );
		$seen[ $key ]   = $id;
		$evidence_entry = array(
			'id'     => $id,
			'path'   => $path,
			'kind'   => $kind,
			'reason' => $reason,
		);

		if ( '' !== $snippet ) {
			$evidence_entry['snippet'] = $snippet;
		}

		$evidence[] = $evidence_entry;
		return $id;
	}

	private static function suggestLikelyStaleDocs( array $impacts, array $changed_docs, array $repo_docs ): array {
		$changed = array_fill_keys($changed_docs, true);
		$docs    = ! empty($repo_docs) ? $repo_docs : array( 'README.md', 'docs/cli.md', 'docs/abilities.md', 'docs/webhooks.md', 'docs/settings.md', 'docs/development.md' );
		$wanted  = array();

		foreach ( $impacts as $impact ) {
			$category = $impact['category'] ?? '';
			foreach ( $docs as $doc ) {
				$doc_lc       = strtolower($doc);
				$category_doc = match ( $category ) {
					'wp_cli' => str_contains($doc_lc, 'cli') || str_contains($doc_lc, 'command'),
					'abilities_tools' => str_contains($doc_lc, 'abilit') || str_contains($doc_lc, 'tool') || str_contains($doc_lc, 'agent'),
					'rest_webhook' => str_contains($doc_lc, 'rest') || str_contains($doc_lc, 'webhook') || str_contains($doc_lc, 'api'),
					'settings_config' => str_contains($doc_lc, 'setting') || str_contains($doc_lc, 'config') || str_contains($doc_lc, 'setup'),
					'public_php' => str_contains($doc_lc, 'develop') || str_contains($doc_lc, 'api') || str_contains($doc_lc, 'reference'),
					default => false,
				};
				$match = 'README.md' === $doc || $category_doc;

				if ( $match && ! isset($changed[ $doc ]) ) {
					$wanted[ $doc ]['path']       = $doc;
					$wanted[ $doc ]['confidence'] = 'medium';
					$wanted[ $doc ]['reasons'][]  = $category;
				}
			}
		}

		foreach ( $wanted as &$entry ) {
			$entry['reasons'] = array_values(array_unique($entry['reasons']));
		}

		return array_values($wanted);
	}

	private static function suggestDocumentationSearchQueries( string $repo, array $impacts, array $changed_docs ): array {
		$queries = array();
		foreach ( $impacts as $impact ) {
			$category  = str_replace('_', ' ', (string) ( $impact['category'] ?? '' ));
			$symbol    = (string) ( $impact['symbol'] ?? '' );
			$queries[] = trim($repo . ' ' . $category . ' ' . $symbol);
		}

		foreach ( $changed_docs as $doc ) {
			$queries[] = $repo . ' ' . basename($doc);
		}

		return array_values(array_unique(array_filter($queries)));
	}

	private static function firstAddedPatchLine( string $patch ): string {
		$lines = preg_split('/\R/', $patch);
		if ( false === $lines ) {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			if ( str_starts_with($line, '+') && ! str_starts_with($line, '+++') ) {
				return substr($line, 0, 240);
			}
		}

		return '';
	}

	private static function extractWpCliSymbol( string $path, string $patch ): string {
		if ( preg_match('/WP_CLI::add_command\(\s*[\'\"]([^\'\"]+)/', $patch, $matches) ) {
			return $matches[1];
		}

		return basename($path, '.php');
	}

	private static function extractPhpSymbol( string $patch ): string {
		if ( preg_match('/^\+\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $patch, $matches) ) {
			return $matches[1];
		}
		if ( preg_match('/^\+\s*(?:public\s+)?(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)/m', $patch, $matches) ) {
			return $matches[1];
		}
		if ( preg_match('/^\+\s*(?:public\s+)?const\s+([A-Z_][A-Z0-9_]*)/m', $patch, $matches) ) {
			return $matches[1];
		}

		return '';
	}

	private static function extractQuotedSymbol( string $patch, string $pattern ): string {
		if ( preg_match($pattern, $patch, $matches) ) {
			return trim($matches[0], "'\"");
		}

		return '';
	}

	/**
	 * Fetch and bound one expanded-context file response.
	 */
	private static function fetchBoundedContextFile( callable $fetcher, string $path, string $ref, string $side, int $max_file_chars, int $remaining_chars ): array {
		if ( '' === $ref ) {
			return array(
				'ref'      => $ref,
				'included' => false,
				'reason'   => 'missing_ref',
			);
		}

		if ( $remaining_chars <= 0 ) {
			return array(
				'ref'      => $ref,
				'included' => false,
				'reason'   => 'total_limit_exceeded',
			);
		}

		$result = $fetcher($path, $ref, $side);
		if ( is_wp_error($result) ) {
			return array(
				'ref'      => $ref,
				'included' => false,
				'reason'   => $result->get_error_code(),
				'error'    => $result->get_error_message(),
			);
		}

		$file     = $result['files'][0] ?? $result;
		$content  = (string) ( $file['content'] ?? '' );
		$original = strlen($content);
		$limit    = min($max_file_chars, $remaining_chars);
		$included = substr($content, 0, $limit);
		$chars    = strlen($included);

		return array(
			'ref'            => $ref,
			'sha'            => $file['sha'] ?? '',
			'size'           => (int) ( $file['size'] ?? $original ),
			'html_url'       => $file['html_url'] ?? '',
			'content'        => $included,
			'included'       => true,
			'included_chars' => $chars,
			'original_chars' => $original,
			'truncated'      => $chars < $original,
		);
	}

	/**
	 * Apply char accounting for one expanded-context file side.
	 */
	private static function applyContextFileAccounting( array &$expanded, array $file_context, int &$remaining_chars ): void {
		if ( empty($file_context['included']) ) {
			++$expanded['summary']['skipped_files'];
			return;
		}

		$included_chars                         = (int) ( $file_context['included_chars'] ?? 0 );
		$expanded['summary']['included_chars'] += $included_chars;
		$remaining_chars                        = max(0, $remaining_chars - $included_chars);

		if ( ! empty($file_context['truncated']) ) {
			$expanded['summary']['truncated'] = true;
		}
	}

	/**
	 * Record a file skipped before any API fetch was attempted.
	 */
	private static function recordExpandedContextSkip( array &$expanded, string $path, string $source, string $reason ): void {
		$expanded['skipped'][] = array(
			'path'   => $path,
			'source' => $source,
			'reason' => $reason,
		);
		++$expanded['summary']['skipped_files'];
	}

	/**
	 * Get a single pull request.
	 *
	 * @param  array $input Required: repo, pull_number.
	 * @return array|\WP_Error { success: bool, pull: normalized } or error.
	 */
	public static function getPull( array $input ): array|\WP_Error {
		$repo        = sanitize_text_field($input['repo'] ?? '');
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty($repo) || $pull_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$url      = GitHubRemote::apiUrl($repo, 'pulls/' . $pull_number);
		$response = self::apiGet($url, array(), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		return array(
			'success'      => true,
			'pull'         => self::normalizePull($response['data']),
			'generated_at' => gmdate('c'),
		);
	}

	/**
	 * Fetch the newest issue comment using GitHub's ascending issue comment order.
	 *
	 * @return array|\WP_Error|null Normalized latest comment, null when none exists, or error.
	 */
	private static function getLatestIssueComment( string $repo, int $issue_number, int $comment_count, string $pat ): array|\WP_Error|null {
		$page = max(1, $comment_count);
		$url  = GitHubRemote::apiUrl($repo, 'issues/' . $issue_number . '/comments');

		$response = self::apiGet(
			$url, array(
				'per_page' => 1,
				'page'     => $page,
			), $pat
		);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$comments = is_array($response['data'] ?? null) ? $response['data'] : array();
		if ( empty($comments) || ! is_array($comments[0] ?? null) ) {
			return null;
		}

		return self::normalizeIssueComment($comments[0]);
	}

	/**
	 * List changed files for a pull request.
	 *
	 * @param  array $input Required: repo, pull_number. Optional: per_page, page.
	 * @return array|\WP_Error { success: bool, files: normalized[], count: int } or error.
	 */
	public static function listPullFiles( array $input ): array|\WP_Error {
		$repo        = sanitize_text_field($input['repo'] ?? '');
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty($repo) || $pull_number <= 0 ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$query_params = array(
			'per_page' => self::clampPerPage($input['per_page'] ?? self::MAX_PER_PAGE),
			'page'     => max(1, (int) ( $input['page'] ?? 1 )),
		);

		$url      = GitHubRemote::apiUrl($repo, 'pulls/' . $pull_number . '/files');
		$response = self::apiGet($url, $query_params, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$normalized = array_map(array( self::class, 'normalizePullFile' ), $response['data']);

		return array(
			'success' => true,
			'files'   => $normalized,
			'count'   => count($normalized),
		);
	}

	/**
	 * Get check runs for one commit SHA or ref.
	 *
	 * @param  array $input Required: repo, sha. Optional: per_page, include_check_output.
	 * @return array|\WP_Error
	 */
	public static function getCheckRuns( array $input ): array|\WP_Error {
		$repo = sanitize_text_field($input['repo'] ?? '');
		$sha  = sanitize_text_field($input['sha'] ?? $input['ref'] ?? $input['head_sha'] ?? '');

		if ( empty($repo) || empty($sha) ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and sha are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$query_params = array(
			'per_page' => self::clampPerPage($input['per_page'] ?? $input['max_check_runs'] ?? self::DEFAULT_PER_PAGE),
		);

		$url      = GitHubRemote::apiUrl($repo, 'commits/' . $sha . '/check-runs');
		$response = self::apiGet($url, $query_params, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$include_output = ! empty($input['include_check_output']);
		$check_runs     = array_map(
			fn( $run ) => self::normalizeCheckRun($run, $include_output),
			$response['data']['check_runs'] ?? array()
		);

		return array(
			'success'    => true,
			'sha'        => $sha,
			'summary'    => self::summarizeCheckRuns($check_runs),
			'check_runs' => $check_runs,
			'count'      => count($check_runs),
		);
	}

	/**
	 * Get classic commit statuses for one commit SHA or ref.
	 *
	 * @param  array $input Required: repo, sha.
	 * @return array|\WP_Error
	 */
	public static function getCommitStatuses( array $input ): array|\WP_Error {
		$repo = sanitize_text_field($input['repo'] ?? '');
		$sha  = sanitize_text_field($input['sha'] ?? $input['ref'] ?? $input['head_sha'] ?? '');

		if ( empty($repo) || empty($sha) ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and sha are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$url      = GitHubRemote::apiUrl($repo, 'commits/' . $sha . '/status');
		$response = self::apiGet($url, array(), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$statuses = array_map(array( self::class, 'normalizeCommitStatus' ), $response['data']['statuses'] ?? array());

		return array(
			'success'  => true,
			'sha'      => $sha,
			'summary'  => self::summarizeCommitStatuses($statuses, $response['data']['state'] ?? ''),
			'statuses' => $statuses,
			'count'    => count($statuses),
		);
	}

	/**
	 * Download and parse a generic GitHub Actions artifact for a pull request or head SHA.
	 *
	 * @param  array         $input    Required: repo plus head_sha or pull_number.
	 * @param  callable|null $api_get  Test seam for JSON GitHub GET calls.
	 * @param  callable|null $download Test seam for artifact ZIP download.
	 * @param  callable|null $extract  Test seam for ZIP extraction.
	 * @return array|\WP_Error
	 */
	public static function getActionsArtifact( array $input, ?callable $api_get = null, ?callable $download = null, ?callable $extract = null ): array|\WP_Error {
		$repo          = sanitize_text_field($input['repo'] ?? '');
		$head_sha      = sanitize_text_field($input['head_sha'] ?? $input['sha'] ?? '');
		$pull_number   = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );
		$artifact_name = sanitize_text_field($input['artifact_name'] ?? '');
		$include_json  = ! array_key_exists('include_json', $input) || ! empty($input['include_json']);

		if ( empty($repo) ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) is required.', array( 'status' => 400 ));
		}

		if ( '' === $artifact_name ) {
			return new \WP_Error('missing_params', 'Artifact name is required.', array( 'status' => 400 ));
		}

		if ( '' === $head_sha && $pull_number <= 0 ) {
			return new \WP_Error('missing_params', 'Either head_sha or pull_number is required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$api_get = $api_get ?? static fn( string $url, array $query ) => self::apiGet($url, $query, $pat);
		if ( '' === $head_sha ) {
			$pull_response = $api_get(GitHubRemote::apiUrl($repo, 'pulls/' . $pull_number), array());
			if ( is_wp_error($pull_response) ) {
				return $pull_response;
			}

			$head_sha = sanitize_text_field($pull_response['data']['head']['sha'] ?? '');
			if ( '' === $head_sha ) {
				return new \WP_Error('github_actions_artifact_missing_head_sha', 'Pull request head SHA could not be resolved.', array( 'status' => 404 ));
			}
		}

		$artifacts_response = $api_get(
			GitHubRemote::apiUrl($repo, 'actions/artifacts'),
			array(
				'name'     => $artifact_name,
				'per_page' => self::MAX_PER_PAGE,
			)
		);

		if ( is_wp_error($artifacts_response) ) {
			return $artifacts_response;
		}

		$artifact_result = self::selectActionsArtifact($artifacts_response['data']['artifacts'] ?? array(), $artifact_name, $head_sha);
		if ( is_wp_error($artifact_result) ) {
			return $artifact_result;
		}

		$artifact = $artifact_result;
		$json     = array();

		if ( $include_json ) {
			$download = $download ?? static fn( string $url, int $max_bytes ) => self::apiRawGet($url, $pat, $max_bytes);
			$zip      = $download( (string) ( $artifact['archive_download_url'] ?? '' ), max(1, (int) ( $input['max_artifact_bytes'] ?? 2000000 )));
			if ( is_wp_error($zip) ) {
				return $zip;
			}

			$extract = $extract ?? array( self::class, 'extractZipJsonFiles' );
			$json    = $extract($zip);
			if ( is_wp_error($json) ) {
				return $json;
			}
		}

		return array(
			'success'     => true,
			'repo'        => $repo,
			'head_sha'    => $head_sha,
			'pull_number' => $pull_number,
			'artifact'    => self::normalizeActionsArtifact($artifact),
			'json_files'  => $json,
		);
	}

	/**
	 * Pick the newest matching GitHub Actions artifact for a head SHA.
	 */
	public static function selectActionsArtifact( array $artifacts, string $artifact_name, string $head_sha ): array|\WP_Error {
		$expired_match = null;
		$matches       = array();

		foreach ( $artifacts as $artifact ) {
			if ( (string) ( $artifact['name'] ?? '' ) !== $artifact_name ) {
				continue;
			}

			$artifact_sha = (string) ( $artifact['workflow_run']['head_sha'] ?? $artifact['head_sha'] ?? '' );
			if ( $head_sha !== $artifact_sha ) {
				continue;
			}

			if ( ! empty($artifact['expired']) ) {
				$expired_match = $artifact;
				continue;
			}

			$matches[] = $artifact;
		}

		if ( empty($matches) ) {
			if ( null !== $expired_match ) {
				return new \WP_Error('github_actions_artifact_expired', 'GitHub Actions artifact exists for this head SHA but has expired.', array( 'status' => 410 ));
			}

			return new \WP_Error('github_actions_artifact_not_found', 'No matching GitHub Actions artifact found for this head SHA.', array( 'status' => 404 ));
		}

		usort($matches, static fn( $a, $b ) => strcmp( (string) ( $b['updated_at'] ?? $b['created_at'] ?? '' ), (string) ( $a['updated_at'] ?? $a['created_at'] ?? '' )));
		return $matches[0];
	}

	private static function normalizeActionsArtifact( array $artifact ): array {
		$workflow_run = is_array($artifact['workflow_run'] ?? null) ? $artifact['workflow_run'] : array();

		return array(
			'id'                   => (int) ( $artifact['id'] ?? 0 ),
			'node_id'              => (string) ( $artifact['node_id'] ?? '' ),
			'name'                 => (string) ( $artifact['name'] ?? '' ),
			'size_in_bytes'        => (int) ( $artifact['size_in_bytes'] ?? 0 ),
			'url'                  => (string) ( $artifact['url'] ?? '' ),
			'archive_download_url' => (string) ( $artifact['archive_download_url'] ?? '' ),
			'expired'              => ! empty($artifact['expired']),
			'created_at'           => (string) ( $artifact['created_at'] ?? '' ),
			'updated_at'           => (string) ( $artifact['updated_at'] ?? '' ),
			'expires_at'           => (string) ( $artifact['expires_at'] ?? '' ),
			'workflow_run'         => array(
				'id'          => (int) ( $workflow_run['id'] ?? 0 ),
				'head_sha'    => (string) ( $workflow_run['head_sha'] ?? $artifact['head_sha'] ?? '' ),
				'head_branch' => (string) ( $workflow_run['head_branch'] ?? '' ),
				'event'       => (string) ( $workflow_run['event'] ?? '' ),
				'html_url'    => (string) ( $workflow_run['html_url'] ?? '' ),
			),
		);
	}

	/**
	 * Parse JSON files from a GitHub Actions artifact ZIP.
	 */
	public static function extractZipJsonFiles( string $zip_bytes ): array|\WP_Error {
		if ( ! class_exists('\ZipArchive') ) {
			return new \WP_Error('github_actions_artifact_zip_unavailable', 'ZipArchive is not available, so GitHub artifact ZIPs cannot be parsed.', array( 'status' => 500 ));
		}

		$tmp = tempnam(sys_get_temp_dir(), 'dmc-gh-artifact-');
		if ( false === $tmp ) {
			return new \WP_Error('github_actions_artifact_temp_failed', 'Could not allocate a temporary file for artifact parsing.', array( 'status' => 500 ));
		}

		file_put_contents($tmp, $zip_bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for ZipArchive.
		$zip = new \ZipArchive();
		if ( true !== $zip->open($tmp) ) {
			self::deleteTempFile($tmp);
			return new \WP_Error('github_actions_artifact_zip_invalid', 'GitHub Actions artifact is not a valid ZIP archive.', array( 'status' => 422 ));
		}

		$files = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$name = (string) $zip->getNameIndex($i);
			if ( ! str_ends_with($name, '.json') ) {
				continue;
			}

			$raw = $zip->getFromIndex($i);
			if ( false === $raw ) {
				continue;
			}

			$data = json_decode($raw, true);
			if ( ! is_array($data) ) {
				$zip->close();
				self::deleteTempFile($tmp);
				return new \WP_Error('github_actions_artifact_malformed_json', sprintf('GitHub Actions artifact file %s is not valid JSON.', $name), array( 'status' => 422 ));
			}

			$files[ basename($name) ] = $data;
		}

		$zip->close();
		self::deleteTempFile($tmp);
		return $files;
	}

	private static function deleteTempFile( string $path ): void {
		if ( function_exists('wp_delete_file') ) {
			wp_delete_file($path);
			return;
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Pure-PHP smoke tests load this file without WordPress filesystem helpers.
		unlink($path);
	}

	public static function listRepos( array $input ): array|\WP_Error {
		$owner = sanitize_text_field($input['owner'] ?? '');
		if ( empty($owner) ) {
			return new \WP_Error('missing_owner', 'Owner (user or org) is required.', array( 'status' => 400 ));
		}

		// listRepos lists by owner — no specific repo, so fall back to default profile.
		$pat = self::getPatForRepo('');
		if ( empty($pat) ) {
			return self::patError();
		}

		$query_params = array(
			'per_page' => self::clampPerPage($input['per_page'] ?? self::DEFAULT_PER_PAGE),
			'page'     => max(1, (int) ( $input['page'] ?? 1 )),
			'sort'     => sanitize_text_field($input['sort'] ?? 'updated'),
		);

		if ( ! empty($input['type']) ) {
			$query_params['type'] = sanitize_text_field($input['type']);
		}

		$url      = sprintf('%s/orgs/%s/repos', self::API_BASE, $owner);
		$response = self::apiGet($url, $query_params, $pat);

		if ( is_wp_error($response) ) {
			$url      = sprintf('%s/users/%s/repos', self::API_BASE, $owner);
			$response = self::apiGet($url, $query_params, $pat);

			if ( is_wp_error($response) ) {
				return $response;
			}
		}

		$normalized = array_map(array( self::class, 'normalizeRepo' ), $response['data']);

		return array(
			'success' => true,
			'repos'   => $normalized,
			'count'   => count($normalized),
		);
	}

	/**
	 * Create or update a file in a repository via the Contents API.
	 *
	 * GET for SHA (if exists) → PUT with base64 content. Genuinely upsert:
	 * creates the file if it doesn't exist, updates it if it does.
	 *
	 * @param  array $input {
	 *                      Required: repo, file_path, content, commit_message.
	 *                      Optional: branch, sha.
	 *                      }
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function createOrUpdateFile( array $input ): array|\WP_Error {
		$repo = self::resolveRepo(sanitize_text_field($input['repo'] ?? ''));
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ));
		}

		$file_path = self::normalizeRepoWritePath($input['file_path'] ?? '');
		if ( is_wp_error($file_path) ) {
			return $file_path;
		}
		if ( empty($file_path) ) {
			return new \WP_Error('missing_file_path', 'File path is required.', array( 'status' => 400 ));
		}

		$allowed_file_paths = is_array($input['allowed_file_paths'] ?? null) ? $input['allowed_file_paths'] : array();
		$scope_result       = self::validateAllowedFilePath($file_path, $allowed_file_paths);
		if ( is_wp_error($scope_result) ) {
			return $scope_result;
		}

		$content = $input['content'] ?? '';
		if ( '' === $content ) {
			return new \WP_Error('missing_content', 'File content is required.', array( 'status' => 400 ));
		}

		$commit_message = sanitize_text_field($input['commit_message'] ?? '');
		if ( empty($commit_message) ) {
			return new \WP_Error('missing_commit_message', 'Commit message is required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$branch = sanitize_text_field($input['branch'] ?? '');
		if ( ! empty($branch) ) {
			$branch_result = self::ensureBranchExists($repo, $branch, $pat);
			if ( is_wp_error($branch_result) ) {
				return $branch_result;
			}
		}

		// Check if the file already exists to get its SHA for update.
		$get_url    = GitHubRemote::apiUrl($repo, 'contents/' . $file_path);
		$get_params = array();
		if ( ! empty($branch) ) {
			$get_params['ref'] = $branch;
		}

		$existing = self::apiGet($get_url, $get_params, $pat);

		$current_sha = sanitize_text_field($input['sha'] ?? '');

		$body = array(
			'message' => $commit_message,
			'content' => base64_encode($content), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by GitHub API.
		);

		if ( '' !== $current_sha ) {
			$body['sha'] = $current_sha;
		}

		// If the file exists, include its SHA for the update.
		if ( empty($body['sha']) && ! is_wp_error($existing) && ! empty($existing['data']['sha']) ) {
			$body['sha'] = $existing['data']['sha'];
		}

		if ( ! empty($branch) ) {
			$body['branch'] = $branch;
		}

		$put_url  = GitHubRemote::apiUrl($repo, 'contents/' . $file_path);
		$response = self::apiRequest('PUT', $put_url, $body, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$data = $response['data'];

		return array(
			'success' => true,
			'commit'  => array(
				'sha'      => $data['commit']['sha'] ?? '',
				'html_url' => $data['commit']['html_url'] ?? '',
				'message'  => $data['commit']['message'] ?? '',
			),
			'content' => array(
				'path'     => $data['content']['path'] ?? $file_path,
				'html_url' => $data['content']['html_url'] ?? '',
			),
			'message' => sprintf(
				'File %s in %s.',
				isset($data['content']) ? 'updated' : 'created',
				$repo
			),
		);
	}

	/**
	 * Commit multiple file writes atomically by creating Git objects and moving one ref.
	 *
	 * Unlike the Contents API, this publishes all file changes in one commit or leaves
	 * the branch ref unchanged when any pre-publication step fails.
	 *
	 * @param  array $input {
	 *                      Required: repo, files, commit_message.
	 *                      Optional: branch.
	 *                      }
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function commitFiles( array $input ): array|\WP_Error {
		$repo = self::resolveRepo(sanitize_text_field($input['repo'] ?? ''));
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ));
		}

		$commit_message = sanitize_text_field($input['commit_message'] ?? '');
		if ( empty($commit_message) ) {
			return new \WP_Error('missing_commit_message', 'Commit message is required.', array( 'status' => 400 ));
		}

		$files = self::normalizeCommitFiles($input['files'] ?? array());
		if ( is_wp_error($files) ) {
			return $files;
		}
		if ( empty($files) ) {
			return new \WP_Error('missing_files', 'At least one file is required for an atomic GitHub commit.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$branch = sanitize_text_field($input['branch'] ?? '');
		if ( '' === $branch ) {
			$branch = self::resolveDefaultBranch($repo, $pat);
			if ( is_wp_error($branch) ) {
				return $branch;
			}
		} else {
			$branch_result = self::ensureBranchExists($repo, $branch, $pat);
			if ( is_wp_error($branch_result) ) {
				return $branch_result;
			}
		}

		$ref_url = sprintf('%s/repos/%s/git/ref/heads/%s', self::API_BASE, $repo, rawurlencode($branch));
		$ref     = self::apiGet($ref_url, array(), $pat);
		if ( is_wp_error($ref) ) {
			return $ref;
		}

		$base_commit_sha = (string) ( $ref['data']['object']['sha'] ?? '' );
		if ( '' === $base_commit_sha ) {
			return new \WP_Error('github_ref_sha_missing', 'GitHub did not return a SHA for the target branch ref.', array( 'status' => 500 ));
		}

		$base_commit = self::apiGet(sprintf('%s/repos/%s/git/commits/%s', self::API_BASE, $repo, rawurlencode($base_commit_sha)), array(), $pat);
		if ( is_wp_error($base_commit) ) {
			return $base_commit;
		}

		$base_tree_sha = (string) ( $base_commit['data']['tree']['sha'] ?? '' );
		if ( '' === $base_tree_sha ) {
			return new \WP_Error('github_base_tree_sha_missing', 'GitHub did not return a tree SHA for the target branch commit.', array( 'status' => 500 ));
		}

		$tree_entries = array();
		foreach ( $files as $file ) {
			$blob = self::apiRequest(
				'POST',
				sprintf('%s/repos/%s/git/blobs', self::API_BASE, $repo),
				array(
					'content'  => (string) $file['content'],
					'encoding' => 'utf-8',
				),
				$pat
			);
			if ( is_wp_error($blob) ) {
				return $blob;
			}

			$blob_sha = (string) ( $blob['data']['sha'] ?? '' );
			if ( '' === $blob_sha ) {
				return new \WP_Error('github_blob_sha_missing', sprintf('GitHub did not return a blob SHA for %s.', $file['path']), array( 'status' => 500 ));
			}

			$tree_entries[] = array(
				'path' => $file['path'],
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $blob_sha,
			);
		}

		$tree = self::apiRequest(
			'POST',
			sprintf('%s/repos/%s/git/trees', self::API_BASE, $repo),
			array(
				'base_tree' => $base_tree_sha,
				'tree'      => $tree_entries,
			),
			$pat
		);
		if ( is_wp_error($tree) ) {
			return $tree;
		}

		$new_tree_sha = (string) ( $tree['data']['sha'] ?? '' );
		if ( '' === $new_tree_sha ) {
			return new \WP_Error('github_tree_sha_missing', 'GitHub did not return a tree SHA for the atomic commit.', array( 'status' => 500 ));
		}

		$commit = self::apiRequest(
			'POST',
			sprintf('%s/repos/%s/git/commits', self::API_BASE, $repo),
			array(
				'message' => $commit_message,
				'tree'    => $new_tree_sha,
				'parents' => array( $base_commit_sha ),
			),
			$pat
		);
		if ( is_wp_error($commit) ) {
			return $commit;
		}

		$new_commit_sha = (string) ( $commit['data']['sha'] ?? '' );
		if ( '' === $new_commit_sha ) {
			return new \WP_Error('github_commit_sha_missing', 'GitHub did not return a SHA for the atomic commit.', array( 'status' => 500 ));
		}

		$updated_ref = self::apiRequest(
			'PATCH',
			$ref_url,
			array(
				'sha'   => $new_commit_sha,
				'force' => false,
			),
			$pat
		);
		if ( is_wp_error($updated_ref) ) {
			return $updated_ref;
		}

		return array(
			'success' => true,
			'commit'  => array(
				'sha'      => $new_commit_sha,
				'html_url' => $commit['data']['html_url'] ?? '',
				'message'  => $commit['data']['message'] ?? $commit_message,
			),
			'branch'  => $branch,
			'files'   => array_map(static fn( array $file ): string => (string) $file['path'], $files),
			'message' => sprintf('Committed %d file(s) to %s in %s.', count($files), $branch, $repo),
		);
	}

	/**
	 * Normalize an atomic commit file set.
	 *
	 * @param  mixed $files Associative path => content map or list of path/content arrays.
	 * @return array<int,array{path:string,content:string}>|\WP_Error
	 */
	private static function normalizeCommitFiles( mixed $files ): array|\WP_Error {
		if ( ! is_array($files) ) {
			return new \WP_Error('invalid_files', 'Files must be an array.', array( 'status' => 400 ));
		}

		$normalized = array();
		foreach ( $files as $key => $value ) {
			if ( is_array($value) ) {
				$path    = self::normalizeRepoWritePath($value['path'] ?? $key);
				$content = $value['content'] ?? '';
			} else {
				$path    = self::normalizeRepoWritePath($key);
				$content = $value;
			}

			if ( is_wp_error($path) ) {
				return $path;
			}
			if ( '' === $path ) {
				return new \WP_Error('missing_file_path', 'Each file in an atomic GitHub commit requires a path.', array( 'status' => 400 ));
			}
			if ( ! is_scalar($content) && null !== $content ) {
				return new \WP_Error('invalid_file_content', sprintf('File content for %s must be scalar.', $path), array( 'status' => 400 ));
			}

			$normalized[ $path ] = array(
				'path'    => $path,
				'content' => (string) $content,
			);
		}

		return array_values($normalized);
	}

	/**
	 * Normalize a repository write path without allowing traversal outside the repo.
	 *
	 * @param  mixed $path Candidate repository-relative path.
	 * @return string|\WP_Error Normalized path or validation error.
	 */
	private static function normalizeRepoWritePath( mixed $path ): string|\WP_Error {
		$path = str_replace('\\', '/', trim(is_scalar($path) ? (string) $path : ''));
		$path = ltrim($path, '/');
		if ( '' === $path ) {
			return '';
		}

		$segments = explode('/', $path);
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return new \WP_Error('invalid_file_path', 'File path must be repository-relative and must not contain traversal segments.', array( 'status' => 400 ));
			}
		}

		return implode('/', array_map('sanitize_text_field', $segments));
	}

	/**
	 * Validate a write path against caller-provided allowlist patterns.
	 *
	 * @param  string $file_path          Repository-relative file path.
	 * @param  array  $allowed_file_paths Optional exact paths, directory prefixes, or glob-like patterns.
	 * @return true|\WP_Error True when allowed, or an error when the path is outside scope.
	 */
	private static function validateAllowedFilePath( string $file_path, array $allowed_file_paths ): true|\WP_Error {
		$patterns = array_values(array_filter(array_map(array( self::class, 'normalizeAllowedFilePathPattern' ), $allowed_file_paths)));
		if ( empty($patterns) ) {
			return true;
		}

		foreach ( $patterns as $pattern ) {
			if ( $file_path === $pattern || fnmatch($pattern, $file_path) ) {
				return true;
			}

			$prefix = '';
			if ( str_ends_with($pattern, '/**') ) {
				$prefix = substr($pattern, 0, -2);
			} elseif ( str_ends_with($pattern, '/') ) {
				$prefix = $pattern;
			}

			if ( '' !== $prefix && str_starts_with($file_path, $prefix) ) {
				return true;
			}
		}

		return new \WP_Error(
			'forbidden_file_path',
			sprintf('File path %s is outside the allowed write scope.', $file_path),
			array(
				'status'             => 403,
				'allowed_file_paths' => $patterns,
			)
		);
	}

	/**
	 * Normalize an allowlist pattern for comparison against repository-relative paths.
	 *
	 * @param  mixed $pattern Candidate allowlist pattern.
	 * @return string Normalized pattern, or empty string when invalid.
	 */
	private static function normalizeAllowedFilePathPattern( mixed $pattern ): string {
		$pattern = str_replace('\\', '/', trim(is_scalar($pattern) ? (string) $pattern : ''));
		$pattern = ltrim($pattern, '/');
		if ( '' === $pattern || str_contains($pattern, '../') || str_contains($pattern, '/..') || '..' === $pattern ) {
			return '';
		}

		return $pattern;
	}

	/**
	 * Ensure a target branch exists before committing through the Contents API.
	 *
	 * @param  string $repo   owner/repo identifier.
	 * @param  string $branch Target branch name.
	 * @param  string $pat    GitHub credential token.
	 * @return true|\WP_Error True when the branch exists or was created.
	 */
	private static function ensureBranchExists( string $repo, string $branch, string $pat ): true|\WP_Error {
		$branch_ref_url = GitHubRemote::apiUrl($repo, 'git/ref/heads/' . rawurlencode($branch));
		$response       = self::apiGet($branch_ref_url, array(), $pat);

		if ( ! is_wp_error($response) ) {
			return true;
		}

		$status = (int) ( $response->get_error_data()['status'] ?? 0 );
		if ( 404 !== $status ) {
			return $response;
		}

		$default_branch = self::resolveDefaultBranch($repo, $pat);
		if ( is_wp_error($default_branch) ) {
			return $default_branch;
		}

		$default_ref_url = GitHubRemote::apiUrl($repo, 'git/ref/heads/' . rawurlencode($default_branch));
		$default_ref     = self::apiGet($default_ref_url, array(), $pat);
		if ( is_wp_error($default_ref) ) {
			return $default_ref;
		}

		$sha = (string) ( $default_ref['data']['object']['sha'] ?? '' );
		if ( '' === $sha ) {
			return new \WP_Error('github_default_branch_sha_missing', 'GitHub did not return a SHA for the repository default branch.', array( 'status' => 500 ));
		}

		$create_ref_url = GitHubRemote::apiUrl($repo, 'git/refs');
		$created        = self::apiRequest(
			'POST',
			$create_ref_url,
			array(
				'ref' => 'refs/heads/' . $branch,
				'sha' => $sha,
			),
			$pat
		);

		if ( is_wp_error($created) ) {
			return $created;
		}

		return true;
	}

	/**
	 * Get the recursive file tree for a repository branch.
	 *
	 * Calls `GET /repos/{owner}/{repo}/git/trees/{sha}?recursive=1`.
	 * Returns all files and directories in a single response.
	 *
	 * @param  array $input {
	 *                      Required: repo. Optional: branch (default: default branch).
	 *                      }
	 * @return array|\WP_Error { files: normalized[], count: int } or error.
	 */
	public static function getRepoTree( array $input ): array|\WP_Error {
		$repo = sanitize_text_field($input['repo'] ?? '');
		if ( empty($repo) ) {
			return new \WP_Error('missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$branch = sanitize_text_field($input['ref'] ?? $input['branch'] ?? '');
		$ref    = ! empty($branch) ? $branch : 'HEAD';

		$url      = GitHubRemote::apiUrl($repo, 'git/trees/' . $ref);
		$response = self::apiGet($url, array( 'recursive' => '1' ), $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$tree = $response['data']['tree'] ?? array();

		// Filter to blobs (files) only — skip trees (directories).
		$path_prefix = trim(sanitize_text_field($input['path'] ?? ''), '/');
		$files       = array_filter(
			$tree,
			function ( $entry ) use ( $path_prefix ) {
				if ( 'blob' !== ( $entry['type'] ?? '' ) ) {
					return false;
				}
				if ( '' === $path_prefix ) {
					return true;
				}
				$entry_path = (string) ( $entry['path'] ?? '' );
				return $entry_path === $path_prefix || str_starts_with($entry_path, $path_prefix . '/');
			}
		);
		$files       = array_values(array_map(array( self::class, 'normalizeTreeEntry' ), $files));

		return array(
			'success' => true,
			'files'   => $files,
			'count'   => count($files),
		);
	}

	/**
	 * Get decoded content for one or more files from a repository.
	 *
	 * Calls `GET /repos/{owner}/{repo}/contents/{path}`.
	 * GitHub returns base64-encoded content for files ≤ 1 MB.
	 *
	 * @param  array $input {
	 *                      Required: repo and path or paths. Optional: ref, branch, max_total_size.
	 *                      }
	 * @return array|\WP_Error { success: bool, files: normalized[], errors: array[], count: int, total_size: int, truncated: bool } or error.
	 */
	public static function getFileContents( array $input ): array|\WP_Error {
		$repo  = sanitize_text_field($input['repo'] ?? '');
		$paths = self::normalizeFileContentPaths($input);

		if ( empty($repo) || empty($paths) ) {
			return new \WP_Error('missing_params', 'Repository (owner/repo) and at least one file path are required.', array( 'status' => 400 ));
		}

		$pat = self::getPatForRepo($repo);
		if ( empty($pat) ) {
			return self::patError();
		}

		$query_params = array();
		$branch       = sanitize_text_field($input['ref'] ?? $input['branch'] ?? '');
		if ( ! empty($branch) ) {
			$query_params['ref'] = $branch;
		}

		$max_total_size = max(1, (int) ( $input['max_total_size'] ?? 500000 ));
		$files          = array();
		$errors         = array();
		$total_size     = 0;
		$truncated      = false;

		foreach ( $paths as $path ) {
			$file = self::getSingleFileContent($repo, $path, $query_params, $pat);
			if ( is_wp_error($file) ) {
				$error_data = $file->get_error_data();
				$errors[]   = array(
					'path'    => $path,
					'code'    => $file->get_error_code(),
					'message' => $file->get_error_message(),
					'status'  => is_array($error_data) ? (int) ( $error_data['status'] ?? 0 ) : 0,
				);
				continue;
			}

			$size = (int) ( $file['size'] ?? strlen( (string) ( $file['content'] ?? '' )) );
			if ( $total_size + $size > $max_total_size ) {
				$truncated = true;
				$errors[]  = array(
					'path'    => $path,
					'code'    => 'max_total_size_exceeded',
					'message' => sprintf('Skipping file because it would exceed max_total_size (%d bytes).', $max_total_size),
				);
				continue;
			}

			$files[]     = $file;
			$total_size += $size;
		}

		return array(
			'success'    => empty($errors),
			'files'      => $files,
			'errors'     => $errors,
			'count'      => count($files),
			'total_size' => $total_size,
			'truncated'  => $truncated,
		);
	}

	/**
	 * Normalize the clean one-or-many file path contract.
	 *
	 * @param  array $input Raw ability input.
	 * @return string[] Ordered, unique sanitized paths.
	 */
	private static function normalizeFileContentPaths( array $input ): array {
		$raw_paths = array();
		if ( isset($input['paths']) ) {
			$raw_paths = is_array($input['paths']) ? $input['paths'] : array( $input['paths'] );
		} elseif ( isset($input['path']) ) {
			$raw_paths = array( $input['path'] );
		}

		$paths = array();
		foreach ( $raw_paths as $path ) {
			$path = ltrim(sanitize_text_field( (string) $path), '/');
			if ( '' !== $path && ! in_array($path, $paths, true) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Fetch and decode a single file from GitHub.
	 *
	 * @param  string $repo         Repository in owner/repo format.
	 * @param  string $path         Repository file path.
	 * @param  array  $query_params GitHub query params.
	 * @param  string $pat          Token to use.
	 * @return array|\WP_Error Normalized file or error.
	 */
	private static function getSingleFileContent( string $repo, string $path, array $query_params, string $pat ): array|\WP_Error {
		$url      = GitHubRemote::apiUrl($repo, 'contents/' . ltrim($path, '/'));
		$response = self::apiGet($url, $query_params, $pat);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$data = $response['data'];

		// GitHub returns a directory listing if path is a directory.
		if ( isset($data[0]) && is_array($data[0]) ) {
			return new \WP_Error('is_directory', 'Path is a directory, not a file.', array( 'status' => 400 ));
		}

		// Reject large files (> 1 MB returns HTML URL instead of content).
		if ( empty($data['content']) ) {
			return new \WP_Error('file_too_large', 'File content not available (may exceed 1 MB GitHub API limit).', array( 'status' => 400 ));
		}

		$decoded = base64_decode($data['content'], true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required by GitHub Contents API.
		if ( false === $decoded ) {
			return new \WP_Error('decode_failed', 'Failed to decode file content.', array( 'status' => 500 ));
		}

		return self::normalizeFileContent($data, $decoded);
	}

	// -------------------------------------------------------------------------
	// HTTP Helpers
	// -------------------------------------------------------------------------

	public static function apiGet( string $url, array $query_params, string $pat, int $timeout = 30 ): array|\WP_Error {
		if ( ! empty($query_params) ) {
			$url = add_query_arg($query_params, $url);
		}

		$response = wp_remote_get(
			$url, array(
				'headers' => self::getHeaders($pat),
				'timeout' => $timeout,
			)
		);

		return self::parseResponse($response);
	}

	public static function apiRequest( string $method, string $url, ?array $body, string $pat ): array|\WP_Error {
		$args = array(
			'method'  => $method,
			'headers' => self::getHeaders($pat),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$encoded_body = wp_json_encode($body);
			$args['body'] = false === $encoded_body ? '' : $encoded_body;
		}

		$response = wp_remote_request($url, $args);

		return self::parseResponse($response);
	}

	public static function apiRawGet( string $url, string $pat, int $max_bytes = 2000000 ): string|\WP_Error {
		if ( '' === $url ) {
			return new \WP_Error('github_actions_artifact_download_missing', 'GitHub artifact download URL is missing.', array( 'status' => 404 ));
		}

		$response = wp_remote_get(
			$url, array(
				'headers' => array_merge(self::getHeaders($pat), array( 'Accept' => 'application/vnd.github+json' )),
				'timeout' => 30,
			)
		);

		if ( is_wp_error($response) ) {
			return new \WP_Error('github_request_failed', 'GitHub API request failed: ' . $response->get_error_message(), array( 'status' => 500 ));
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body        = (string) wp_remote_retrieve_body($response);
		if ( $status_code >= 400 ) {
			return new \WP_Error(404 === $status_code ? 'github_not_found' : 'github_api_error', sprintf('GitHub API error (%d) while downloading artifact.', $status_code), array( 'status' => $status_code ));
		}

		if ( strlen($body) > $max_bytes ) {
			return new \WP_Error('github_actions_artifact_too_large', sprintf('GitHub Actions artifact exceeded the configured %d byte limit.', $max_bytes), array( 'status' => 413 ));
		}

		return $body;
	}

	private static function parseResponse( $response ): array|\WP_Error {
		if ( is_wp_error($response) ) {
			return new \WP_Error('github_request_failed', 'GitHub API request failed: ' . $response->get_error_message(), array( 'status' => 500 ));
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body        = json_decode(wp_remote_retrieve_body($response), true);

		if ( $status_code >= 400 ) {
			$message = $body['message'] ?? 'Unknown error';
			$code    = $status_code >= 500 ? 'github_server_error' : ( 404 === $status_code ? 'github_not_found' : 'github_api_error' );
			return new \WP_Error($code, sprintf('GitHub API error (%d): %s', $status_code, $message), array( 'status' => $status_code ));
		}

		return array(
			'success' => true,
			'data'    => $body,
		);
	}

	private static function getHeaders( string $pat ): array {
		// Prefer per-token mode tracked at resolution time so multi-profile
		// callers get the right Authorization scheme regardless of which
		// profile is the global default.
		$mode          = self::$token_modes[ $pat ] ?? GitHubCredentialResolver::mode();
		$authorization = 'app' === $mode ? 'Bearer ' . $pat : 'token ' . $pat;

		return array(
			'Authorization' => $authorization,
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'DataMachineCode',
			'Content-Type'  => 'application/json',
		);
	}

	// -------------------------------------------------------------------------
	// Normalizers
	// -------------------------------------------------------------------------

	public static function normalizeIssue( array $issue ): array {
		$comment_count = (int) ( $issue['comments'] ?? 0 );

		return array(
			'number'        => (int) ( $issue['number'] ?? 0 ),
			'title'         => $issue['title'] ?? '',
			'state'         => $issue['state'] ?? '',
			'body'          => $issue['body'] ?? '',
			'html_url'      => $issue['html_url'] ?? '',
			'user'          => $issue['user']['login'] ?? '',
			'labels'        => array_map(fn( $label ) => $label['name'] ?? '', $issue['labels'] ?? array()),
			'assignees'     => array_map(fn( $a ) => $a['login'] ?? '', $issue['assignees'] ?? array()),
			'comments'      => $comment_count,
			'comment_count' => $comment_count,
			'created_at'    => $issue['created_at'] ?? '',
			'updated_at'    => $issue['updated_at'] ?? '',
			'closed_at'     => $issue['closed_at'] ?? '',
		);
	}

	public static function normalizeIssueComment( array $comment ): array {
		return array(
			'id'         => (int) ( $comment['id'] ?? 0 ),
			'body'       => $comment['body'] ?? '',
			'html_url'   => $comment['html_url'] ?? '',
			'user'       => $comment['user']['login'] ?? '',
			'created_at' => $comment['created_at'] ?? '',
			'updated_at' => $comment['updated_at'] ?? '',
		);
	}

	public static function normalizePull( array $pr ): array {
		$comment_count        = (int) ( $pr['comments'] ?? 0 );
		$review_comment_count = (int) ( $pr['review_comments'] ?? 0 );

		return array(
			'number'               => (int) ( $pr['number'] ?? 0 ),
			'title'                => $pr['title'] ?? '',
			'state'                => $pr['state'] ?? '',
			'body'                 => $pr['body'] ?? '',
			'html_url'             => $pr['html_url'] ?? '',
			'user'                 => $pr['user']['login'] ?? '',
			'head'                 => $pr['head']['ref'] ?? '',
			'head_ref'             => $pr['head']['ref'] ?? '',
			'head_sha'             => $pr['head']['sha'] ?? '',
			'base'                 => $pr['base']['ref'] ?? '',
			'base_ref'             => $pr['base']['ref'] ?? '',
			'base_sha'             => $pr['base']['sha'] ?? '',
			'draft'                => $pr['draft'] ?? false,
			'merged'               => ! empty($pr['merged_at']),
			'labels'               => array_map(fn( $label ) => $label['name'] ?? '', $pr['labels'] ?? array()),
			'comments'             => $comment_count,
			'comment_count'        => $comment_count,
			'issue_comment_count'  => $comment_count,
			'review_comments'      => $review_comment_count,
			'review_comment_count' => $review_comment_count,
			'commits'              => (int) ( $pr['commits'] ?? 0 ),
			'additions'            => (int) ( $pr['additions'] ?? 0 ),
			'deletions'            => (int) ( $pr['deletions'] ?? 0 ),
			'changed_files'        => (int) ( $pr['changed_files'] ?? 0 ),
			'created_at'           => $pr['created_at'] ?? '',
			'updated_at'           => $pr['updated_at'] ?? '',
			'closed_at'            => $pr['closed_at'] ?? '',
			'merged_at'            => $pr['merged_at'] ?? '',
		);
	}

	/**
	 * Normalize one changed file from the pull files API.
	 */
	public static function normalizePullFile( array $file ): array {
		return array(
			'filename'  => $file['filename'] ?? '',
			'status'    => $file['status'] ?? '',
			'additions' => (int) ( $file['additions'] ?? 0 ),
			'deletions' => (int) ( $file['deletions'] ?? 0 ),
			'changes'   => (int) ( $file['changes'] ?? 0 ),
			'patch'     => $file['patch'] ?? '',
			'raw_url'   => $file['raw_url'] ?? '',
			'blob_url'  => $file['blob_url'] ?? '',
		);
	}

	/**
	 * Normalize one GitHub check run.
	 */
	public static function normalizeCheckRun( array $run, bool $include_output = false ): array {
		$normalized = array(
			'id'           => (int) ( $run['id'] ?? 0 ),
			'name'         => $run['name'] ?? '',
			'status'       => $run['status'] ?? '',
			'conclusion'   => $run['conclusion'] ?? '',
			'html_url'     => $run['html_url'] ?? '',
			'details_url'  => $run['details_url'] ?? '',
			'started_at'   => $run['started_at'] ?? '',
			'completed_at' => $run['completed_at'] ?? '',
			'app'          => $run['app']['slug'] ?? $run['app']['name'] ?? '',
		);

		if ( $include_output ) {
			$output               = $run['output'] ?? array();
			$normalized['output'] = array(
				'title'   => $output['title'] ?? '',
				'summary' => substr( (string) ( $output['summary'] ?? '' ), 0, 2000),
				'text'    => substr( (string) ( $output['text'] ?? '' ), 0, 4000),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize one unmanaged commit status.
	 */
	public static function normalizeCommitStatus( array $status ): array {
		return array(
			'id'          => (int) ( $status['id'] ?? 0 ),
			'context'     => $status['context'] ?? '',
			'state'       => $status['state'] ?? '',
			'description' => $status['description'] ?? '',
			'target_url'  => $status['target_url'] ?? '',
			'created_at'  => $status['created_at'] ?? '',
			'updated_at'  => $status['updated_at'] ?? '',
		);
	}

	/**
	 * Summarize check-run states into one review-friendly status.
	 */
	public static function summarizeCheckRuns( array $check_runs ): array {
		$counts  = array(
			'success'   => 0,
			'failure'   => 0,
			'pending'   => 0,
			'skipped'   => 0,
			'cancelled' => 0,
			'neutral'   => 0,
		);
		$failing = array();

		foreach ( $check_runs as $run ) {
			$state = self::normalizeCheckRunState( (string) ( $run['status'] ?? '' ), (string) ( $run['conclusion'] ?? '' ));
			++$counts[ $state ];

			if ( 'failure' === $state ) {
				$failing[] = array(
					'name'       => $run['name'] ?? '',
					'conclusion' => $run['conclusion'] ?? '',
					'html_url'   => $run['html_url'] ?? '',
					'summary'    => $run['output']['summary'] ?? '',
				);
			}
		}

		return array(
			'state'   => self::resolveAggregateState($counts, count($check_runs)),
			'counts'  => $counts,
			'failing' => $failing,
		);
	}

	/**
	 * Summarize classic commit statuses.
	 */
	public static function summarizeCommitStatuses( array $statuses, string $combined_state = '' ): array {
		$counts  = array(
			'success'   => 0,
			'failure'   => 0,
			'pending'   => 0,
			'skipped'   => 0,
			'cancelled' => 0,
			'neutral'   => 0,
		);
		$failing = array();

		foreach ( $statuses as $status ) {
			$state = self::normalizeCommitStatusState( (string) ( $status['state'] ?? '' ));
			++$counts[ $state ];

			if ( 'failure' === $state ) {
				$failing[] = array(
					'name'        => $status['context'] ?? '',
					'conclusion'  => $status['state'] ?? '',
					'html_url'    => $status['target_url'] ?? '',
					'description' => $status['description'] ?? '',
				);
			}
		}

		$state = '' !== $combined_state ? self::normalizeCommitStatusState($combined_state) : self::resolveAggregateState($counts, count($statuses));

		return array(
			'state'   => $state,
			'counts'  => $counts,
			'failing' => $failing,
		);
	}

	private static function normalizeCheckRunState( string $status, string $conclusion ): string {
		if ( 'completed' !== $status ) {
			return 'pending';
		}

		return match ( $conclusion ) {
			'success' => 'success',
			'skipped' => 'skipped',
			'neutral' => 'neutral',
			'cancelled' => 'cancelled',
			default => 'failure',
		};
	}

	private static function normalizeCommitStatusState( string $state ): string {
		return match ( $state ) {
			'success' => 'success',
			'pending' => 'pending',
			default => 'failure',
		};
	}

	private static function resolveAggregateState( array $counts, int $total ): string {
		if ( 0 === $total ) {
			return 'neutral';
		}
		if ( ( $counts['failure'] ?? 0 ) > 0 ) {
			return 'failure';
		}
		if ( ( $counts['pending'] ?? 0 ) > 0 ) {
			return 'pending';
		}
		if ( ( $counts['cancelled'] ?? 0 ) > 0 ) {
			return 'cancelled';
		}
		if ( ( $counts['success'] ?? 0 ) > 0 ) {
			return 'success';
		}
		if ( ( $counts['skipped'] ?? 0 ) > 0 ) {
			return 'skipped';
		}

		return 'neutral';
	}

	/**
	 * Build a review-ready DataPacket for one pull request.
	 *
	 * @param  string $repo    Repository in owner/repo format.
	 * @param  array  $pull    Normalized pull request payload.
	 * @param  array  $files   Normalized changed file payloads.
	 * @param  array  $options Optional head_sha and max_patch_chars.
	 * @return array|\WP_Error DataPacket-compatible array or validation error.
	 */
	public static function normalizePullReviewContext( string $repo, array $pull, array $files, array $options = array() ): array|\WP_Error {
		$pull_number = (int) ( $pull['number'] ?? 0 );
		$head_sha    = (string) ( $pull['head_sha'] ?? '' );
		$expected    = (string) ( $options['head_sha'] ?? '' );

		if ( '' !== $expected && '' !== $head_sha && $expected !== $head_sha ) {
			return new \WP_Error(
				'github_pr_head_sha_mismatch',
				sprintf('GitHub PR head SHA mismatch for %s#%d: expected %s, found %s.', $repo, $pull_number, $expected, $head_sha),
				array( 'status' => 409 )
			);
		}

		$max_patch_chars = max(0, (int) ( $options['max_patch_chars'] ?? 200000 ));
		$patch_chars     = 0;
		$truncated_files = 0;
		$changed_files   = array();

		foreach ( $files as $file ) {
			$patch      = (string) ( $file['patch'] ?? '' );
			$patch_size = strlen($patch);
			$include    = '' !== $patch && ( 0 === $max_patch_chars || $patch_chars + $patch_size <= $max_patch_chars );

			if ( '' !== $patch && ! $include ) {
				++$truncated_files;
			}

			$entry = array(
				'filename'       => $file['filename'] ?? '',
				'status'         => $file['status'] ?? '',
				'additions'      => (int) ( $file['additions'] ?? 0 ),
				'deletions'      => (int) ( $file['deletions'] ?? 0 ),
				'changes'        => (int) ( $file['changes'] ?? 0 ),
				'patch'          => $include ? $patch : '',
				'patch_included' => $include,
				'patch_bytes'    => $patch_size,
			);

			if ( $include ) {
				$patch_chars += $patch_size;
			}

			$changed_files[] = $entry;
		}

		$item_identifier = sprintf('%s#%d@%s', $repo, $pull_number, $head_sha);
		$title           = sprintf('PR review context: %s#%d %s', $repo, $pull_number, $pull['title'] ?? '');

		$context = array(
			'repo'          => $repo,
			'pull_number'   => $pull_number,
			'url'           => $pull['html_url'] ?? '',
			'title'         => $pull['title'] ?? '',
			'body'          => $pull['body'] ?? '',
			'author'        => $pull['user'] ?? '',
			'base_ref'      => $pull['base_ref'] ?? $pull['base'] ?? '',
			'base_sha'      => $pull['base_sha'] ?? '',
			'head_ref'      => $pull['head_ref'] ?? $pull['head'] ?? '',
			'head_sha'      => $head_sha,
			'changed_files' => $changed_files,
			'truncation'    => array(
				'max_patch_chars'      => $max_patch_chars,
				'included_patch_chars' => $patch_chars,
				'truncated_files'      => $truncated_files,
				'truncated'            => $truncated_files > 0,
			),
		);

		if ( isset($options['expanded_context']) && is_array($options['expanded_context']) ) {
			$context['expanded_context'] = $options['expanded_context'];
		}

		if ( isset($options['checks']) && is_array($options['checks']) ) {
			$context['checks'] = $options['checks'];
		}

		if ( false !== ( $options['include_escalation_policy'] ?? true ) ) {
			$context['escalation_policy'] = PrReviewEscalationPolicy::evaluate(
				$repo,
				$pull,
				$changed_files,
				$context['checks'] ?? array(),
				isset($options['escalation_policy']) && is_array($options['escalation_policy']) ? $options['escalation_policy'] : array()
			);
		}

		return array(
			'title'    => $title,
			'content'  => wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'metadata' => array(
				'source_type'       => 'github_pr_review',
				'item_identifier'   => $item_identifier,
				'original_id'       => $item_identifier,
				'dedup_key'         => $item_identifier,
				'original_title'    => $pull['title'] ?? '',
				'original_date_gmt' => $pull['updated_at'] ?? $pull['created_at'] ?? '',
				'github_repo'       => $repo,
				'github_type'       => 'pull_review_context',
				'github_number'     => $pull_number,
				'github_head_sha'   => $head_sha,
				'github_base_sha'   => $pull['base_sha'] ?? '',
				'github_url'        => $pull['html_url'] ?? '',
				'source_url'        => $pull['html_url'] ?? '',
				'review_context'    => $context,
			),
		);
	}

	public static function normalizeRepo( array $repo ): array {
		return array(
			'full_name'        => $repo['full_name'] ?? '',
			'description'      => $repo['description'] ?? '',
			'html_url'         => $repo['html_url'] ?? '',
			'private'          => $repo['private'] ?? false,
			'fork'             => $repo['fork'] ?? false,
			'language'         => $repo['language'] ?? '',
			'stargazers_count' => $repo['stargazers_count'] ?? 0,
			'open_issues'      => $repo['open_issues_count'] ?? 0,
			'default_branch'   => $repo['default_branch'] ?? 'main',
			'pushed_at'        => $repo['pushed_at'] ?? '',
			'updated_at'       => $repo['updated_at'] ?? '',
		);
	}

	/**
	 * Normalize a git tree entry (file listing from getRepoTree).
	 */
	public static function normalizeTreeEntry( array $entry ): array {
		return array(
			'path' => $entry['path'] ?? '',
			'size' => (int) ( $entry['size'] ?? 0 ),
			'sha'  => $entry['sha'] ?? '',
		);
	}

	/**
	 * Normalize a file content response from getFileContents.
	 *
	 * @param array  $data    Raw GitHub API response data.
	 * @param string $decoded Base64-decoded file content.
	 */
	public static function normalizeFileContent( array $data, string $decoded ): array {
		return array(
			'path'     => $data['path'] ?? '',
			'size'     => (int) ( $data['size'] ?? 0 ),
			'sha'      => $data['sha'] ?? '',
			'content'  => $decoded,
			'html_url' => $data['html_url'] ?? '',
		);
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Resolve a GitHub credential token.
	 *
	 * @param  array<string,mixed>|null $selector Optional `profile_id` or `repo` selector. See GitHubCredentialResolver::resolve().
	 * @return string Token string, or empty string when resolution fails (caller should call patError()).
	 */
	public static function getPat( ?array $selector = null ): string {
		$credential = GitHubCredentialResolver::resolve(null, null, $selector);
		if ( is_wp_error($credential) ) {
			self::$last_auth_error = $credential;
			return '';
		}

		self::$last_auth_error       = null;
		$token                       = (string) $credential['token'];
		self::$token_modes[ $token ] = (string) $credential['mode'];
		return $token;
	}

	/**
	 * Resolve a full credential array for callers that need both the token and its mode.
	 *
	 * @param  array<string,mixed>|null $selector
	 * @return array{mode:string,token:string,authorization:string,profile_id:string}|\WP_Error
	 */
	public static function getCredential( ?array $selector = null ): array|\WP_Error {
		$credential = GitHubCredentialResolver::resolve(null, null, $selector);
		if ( is_wp_error($credential) ) {
			self::$last_auth_error = $credential;
			return $credential;
		}

		$token                       = (string) $credential['token'];
		self::$last_auth_error       = null;
		self::$token_modes[ $token ] = (string) $credential['mode'];
		return $credential;
	}

	public static function isConfigured(): bool {
		return GitHubCredentialResolver::isConfigured();
	}

	/**
	 * Return non-secret GitHub auth status for CLI/status surfaces.
	 *
	 * @return array<string, mixed>
	 */
	public static function getAuthStatus(): array {
		return GitHubCredentialResolver::status();
	}

	public static function getDefaultRepo(): string {
		return trim(PluginSettings::get('github_default_repo', ''));
	}

	public static function getRegisteredRepos(): array {
		$repos = array();

		$default_repo = self::getDefaultRepo();
		if ( ! empty($default_repo) && str_contains($default_repo, '/') ) {
			$parts   = explode('/', $default_repo, 2);
			$repos[] = array(
				'owner' => $parts[0],
				'repo'  => $parts[1],
				'label' => 'Default (from settings)',
			);
		}

		$repos = apply_filters('datamachine_github_issue_repos', $repos);

		$seen   = array();
		$unique = array();
		foreach ( $repos as $entry ) {
			$key = strtolower(( $entry['owner'] ?? '' ) . '/' . ( $entry['repo'] ?? '' ));
			if ( isset($seen[ $key ]) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $entry;
		}

		return $unique;
	}

	public static function resolveRepo( string $repo = '' ): string {
		if ( ! empty($repo) ) {
			return $repo;
		}

		$default = self::getDefaultRepo();
		if ( ! empty($default) ) {
			return $default;
		}

		$registered = self::getRegisteredRepos();
		if ( ! empty($registered) ) {
			return $registered[0]['owner'] . '/' . $registered[0]['repo'];
		}

		return '';
	}

	/**
	 * Resolve a PAT/installation token, scoped to the current repo when known.
	 *
	 * Internal sugar for ability methods that already have the repo in scope.
	 * Pass the empty string to fall back to the default profile.
	 */
	private static function getPatForRepo( string $repo ): string {
		$selector = '' !== trim($repo) ? array( 'repo' => $repo ) : null;
		return self::getPat($selector);
	}

	private static function getPatForRepoCapability( string $repo, string $capability ): string {
		$selector = '' !== trim($repo)
		? array(
			'repo'       => $repo,
			'capability' => $capability,
		)
		: null;
		return self::getPat($selector);
	}

	private static function patError(): \WP_Error {
		if ( self::$last_auth_error ) {
			return self::$last_auth_error;
		}

		return new \WP_Error('github_auth_not_configured', 'GitHub authentication is not configured. Set github_pat for PAT mode or GitHub App credentials for app mode.', array( 'status' => 403 ));
	}

	private static function clampPerPage( $per_page ): int {
		return max(1, min(self::MAX_PER_PAGE, (int) $per_page));
	}
}
