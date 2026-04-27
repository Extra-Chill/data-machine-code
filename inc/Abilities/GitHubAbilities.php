<?php
/**
 * GitHub Abilities
 *
 * Core business logic for GitHub API interactions: listing issues, PRs,
 * repos, reading repo source files, and managing issues (update, close,
 * comment). All GitHub operations — CLI, REST, chat tools, fetch handler —
 * route through here.
 *
 * Auth: Uses the github_pat stored in Data Machine PluginSettings.
 *
 * @package DataMachineCode\Abilities
 * @since 0.1.0
 */

namespace DataMachineCode\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class GitHubAbilities {

	private static bool $registered = false;

	/**
	 * GitHub API base URL.
	 */
	const API_BASE = 'https://api.github.com';

	/**
	 * Default per_page for API requests.
	 */
	const DEFAULT_PER_PAGE = 30;

	/**
	 * Maximum per_page for API requests.
	 */
	const MAX_PER_PAGE = 100;

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
			wp_register_ability(
				'datamachine/list-github-issues',
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

			wp_register_ability(
				'datamachine/get-github-issue',
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

			wp_register_ability(
				'datamachine/update-github-issue',
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
								'description' => 'Labels to set on the issue (replaces existing).',
							),
							'assignees'    => array(
								'type'        => 'array',
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

			wp_register_ability(
				'datamachine/comment-github-issue',
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

			wp_register_ability(
				'datamachine/comment-github-pull-request',
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

			wp_register_ability(
				'datamachine/upsert-github-pull-review-comment',
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

			wp_register_ability(
				'datamachine/list-github-pulls',
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

			wp_register_ability(
				'datamachine/get-github-pull',
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

			wp_register_ability(
				'datamachine/list-github-pull-files',
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

			wp_register_ability(
				'datamachine/get-github-pull-review-context',
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

			wp_register_ability(
				'datamachine/list-github-tree',
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

			wp_register_ability(
				'datamachine/get-github-file',
				array(
					'label'               => 'Get GitHub File',
					'description'         => 'Get decoded content for a single file in a GitHub repository',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'path' ),
						'properties' => array(
							'repo' => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'path' => array(
								'type'        => 'string',
								'description' => 'File path within the repository.',
							),
							'ref'  => array(
								'type'        => 'string',
								'description' => 'Branch, tag, or commit SHA. Defaults to the repository default branch.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'file'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getFileContents' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/create-or-update-github-file',
				array(
					'label'               => 'Create or Update GitHub File',
					'description'         => 'Create or update a file in a GitHub repository via the Contents API (upsert). If the file exists, it is updated; if not, it is created.',
					'category'            => 'datamachine-code-github',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'file_path', 'content', 'commit_message' ),
						'properties' => array(
							'repo'           => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'file_path'      => array(
								'type'        => 'string',
								'description' => 'Path within the repository (e.g., docs/getting-started.md).',
							),
							'content'        => array(
								'type'        => 'string',
								'description' => 'File content (will be base64-encoded automatically).',
							),
							'commit_message' => array(
								'type'        => 'string',
								'description' => 'Commit message for the change.',
							),
							'branch'         => array(
								'type'        => 'string',
								'description' => 'Target branch. Defaults to the repository\'s default branch.',
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

			wp_register_ability(
				'datamachine/list-github-repos',
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
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// -------------------------------------------------------------------------
	// Ability Callbacks
	// -------------------------------------------------------------------------

	public static function listIssues( array $input ): array|\WP_Error {
		$repo = sanitize_text_field( $input['repo'] ?? '' );
		if ( empty( $repo ) ) {
			return new \WP_Error( 'missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'state'    => sanitize_text_field( $input['state'] ?? 'open' ),
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::DEFAULT_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
		);

		if ( ! empty( $input['labels'] ) ) {
			$query_params['labels'] = sanitize_text_field( $input['labels'] );
		}
		if ( ! empty( $input['assignee'] ) ) {
			$query_params['assignee'] = sanitize_text_field( $input['assignee'] );
		}
		if ( ! empty( $input['since'] ) ) {
			$query_params['since'] = sanitize_text_field( $input['since'] );
		}

		$url      = sprintf( '%s/repos/%s/issues', self::API_BASE, $repo );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$issues = array_filter( $response['data'], fn( $item ) => empty( $item['pull_request'] ) );
		$issues = array_values( $issues );

		$normalized = array_map( array( self::class, 'normalizeIssue' ), $issues );

		return array(
			'success' => true,
			'issues'  => $normalized,
			'count'   => count( $normalized ),
		);
	}

	public static function getIssue( array $input ): array|\WP_Error {
		$repo         = sanitize_text_field( $input['repo'] ?? '' );
		$issue_number = (int) ( $input['issue_number'] ?? 0 );

		if ( empty( $repo ) || $issue_number <= 0 ) {
			return new \WP_Error( 'missing_params', 'Repository (owner/repo) and issue_number are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$url      = sprintf( '%s/repos/%s/issues/%d', self::API_BASE, $repo, $issue_number );
		$response = self::apiGet( $url, array(), $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'issue'   => self::normalizeIssue( $response['data'] ),
		);
	}

	public static function updateIssue( array $input ): array|\WP_Error {
		$repo         = sanitize_text_field( $input['repo'] ?? '' );
		$issue_number = (int) ( $input['issue_number'] ?? 0 );

		if ( empty( $repo ) || $issue_number <= 0 ) {
			return new \WP_Error( 'missing_params', 'Repository (owner/repo) and issue_number are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$body = array();
		if ( isset( $input['title'] ) ) {
			$body['title'] = $input['title'];
		}
		if ( isset( $input['body'] ) ) {
			$body['body'] = $input['body'];
		}
		if ( isset( $input['state'] ) ) {
			$body['state'] = $input['state'];
		}
		if ( isset( $input['labels'] ) && is_array( $input['labels'] ) ) {
			$body['labels'] = $input['labels'];
		}
		if ( isset( $input['assignees'] ) && is_array( $input['assignees'] ) ) {
			$body['assignees'] = $input['assignees'];
		}

		if ( empty( $body ) ) {
			return new \WP_Error( 'no_fields', 'No fields to update. Provide title, body, state, labels, or assignees.', array( 'status' => 400 ) );
		}

		$url      = sprintf( '%s/repos/%s/issues/%d', self::API_BASE, $repo, $issue_number );
		$response = self::apiRequest( 'PATCH', $url, $body, $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'issue'   => self::normalizeIssue( $response['data'] ),
			'message' => sprintf( 'Issue #%d updated.', $issue_number ),
		);
	}

	public static function createIssue( array $input ): array|\WP_Error {
		$repo = self::resolveRepo( sanitize_text_field( $input['repo'] ?? '' ) );
		if ( empty( $repo ) ) {
			return new \WP_Error( 'missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ) );
		}

		$title = sanitize_text_field( $input['title'] ?? '' );
		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', 'Issue title is required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$body = array( 'title' => $title );

		if ( ! empty( $input['body'] ) ) {
			$body['body'] = $input['body'];
		}
		if ( ! empty( $input['labels'] ) && is_array( $input['labels'] ) ) {
			$body['labels'] = array_map( 'sanitize_text_field', $input['labels'] );
		}
		if ( ! empty( $input['assignees'] ) && is_array( $input['assignees'] ) ) {
			$body['assignees'] = array_map( 'sanitize_text_field', $input['assignees'] );
		}

		$url      = sprintf( '%s/repos/%s/issues', self::API_BASE, $repo );
		$response = self::apiRequest( 'POST', $url, $body, $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$issue = self::normalizeIssue( $response['data'] );

		return array(
			'success'      => true,
			'issue'        => $issue,
			'issue_url'    => $issue['url'] ?? '',
			'issue_number' => $issue['number'] ?? 0,
			'html_url'     => $issue['html_url'] ?? '',
			'message'      => sprintf( 'Issue #%d created in %s.', $issue['number'] ?? 0, $repo ),
		);
	}

	public static function commentOnIssue( array $input ): array|\WP_Error {
		$repo         = sanitize_text_field( $input['repo'] ?? '' );
		$issue_number = (int) ( $input['issue_number'] ?? 0 );
		$body         = $input['body'] ?? '';

		if ( empty( $repo ) || $issue_number <= 0 || empty( $body ) ) {
			return new \WP_Error( 'missing_params', 'Repository, issue_number, and body are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$url      = sprintf( '%s/repos/%s/issues/%d/comments', self::API_BASE, $repo, $issue_number );
		$response = self::apiRequest( 'POST', $url, array( 'body' => $body ), $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'comment' => array(
				'id'         => $response['data']['id'] ?? 0,
				'html_url'   => $response['data']['html_url'] ?? '',
				'created_at' => $response['data']['created_at'] ?? '',
			),
			'message' => sprintf( 'Comment added to issue #%d.', $issue_number ),
		);
	}

	public static function commentOnPullRequest( array $input ): array|\WP_Error {
		return self::commentOnIssue( self::buildPullRequestCommentInput( $input ) );
	}

	/**
	 * Create or update one managed bot-authored PR review comment.
	 *
	 * @param array         $input       Ability input.
	 * @param callable|null $api_get     Optional test seam: fn(string $url, array $query, string $pat): array|WP_Error.
	 * @param callable|null $api_request Optional test seam: fn(string $method, string $url, array $body, string $pat): array|WP_Error.
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function upsertPullReviewComment( array $input, ?callable $api_get = null, ?callable $api_request = null ): array|\WP_Error {
		$repo        = sanitize_text_field( $input['repo'] ?? '' );
		$pull_number = (int) ( $input['pull_number'] ?? 0 );
		$body        = (string) ( $input['body'] ?? '' );
		$mode        = sanitize_text_field( $input['mode'] ?? 'update_existing' );
		$head_sha    = sanitize_text_field( $input['head_sha'] ?? '' );

		if ( empty( $repo ) || $pull_number <= 0 || '' === $body ) {
			return new \WP_Error( 'missing_params', 'Repository, pull_number, and body are required.', array( 'status' => 400 ) );
		}

		if ( ! in_array( $mode, array( 'update_existing', 'per_head_sha' ), true ) ) {
			return new \WP_Error( 'invalid_mode', 'Mode must be update_existing or per_head_sha.', array( 'status' => 400 ) );
		}

		if ( 'per_head_sha' === $mode && '' === $head_sha ) {
			return new \WP_Error( 'missing_head_sha', 'head_sha is required when mode is per_head_sha.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$api_get     = $api_get ?? array( self::class, 'apiGet' );
		$api_request = $api_request ?? array( self::class, 'apiRequest' );
		$marker      = self::normalizeManagedCommentMarker( $input['marker'] ?? '<!-- datamachine-pr-review -->' );
		$target_body = self::buildManagedPullReviewCommentBody( $body, $marker, $head_sha, $mode );

		$user_response = $api_get( self::API_BASE . '/user', array(), $pat );
		if ( is_wp_error( $user_response ) ) {
			return $user_response;
		}

		$login = (string) ( $user_response['data']['login'] ?? '' );
		if ( '' === $login ) {
			return new \WP_Error( 'github_identity_missing', 'GitHub API did not return the authenticated user login.', array( 'status' => 500 ) );
		}

		$comments = array();
		$page     = 1;
		do {
			$comments_url = sprintf( '%s/repos/%s/issues/%d/comments', self::API_BASE, $repo, $pull_number );
			$page_result  = $api_get(
				$comments_url,
				array(
					'per_page' => self::MAX_PER_PAGE,
					'page'     => $page,
				),
				$pat
			);
			if ( is_wp_error( $page_result ) ) {
				return $page_result;
			}

			$page_comments = is_array( $page_result['data'] ?? null ) ? $page_result['data'] : array();
			$page_count    = count( $page_comments );
			$comments      = array_merge( $comments, $page_comments );
			++$page;
		} while ( $page_count >= self::MAX_PER_PAGE );

		$existing = self::findManagedPullReviewComment( $comments, $login, $marker, $head_sha, $mode );
		if ( null !== $existing ) {
			$comment_id = (int) ( $existing['id'] ?? 0 );
			if ( $comment_id <= 0 ) {
				return new \WP_Error( 'github_comment_missing_id', 'Matched GitHub comment did not include an id.', array( 'status' => 500 ) );
			}

			$response = $api_request(
				'PATCH',
				sprintf( '%s/repos/%s/issues/comments/%d', self::API_BASE, $repo, $comment_id ),
				array( 'body' => $target_body ),
				$pat
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return self::buildManagedCommentResponse( 'updated', $response['data'] ?? array() );
		}

		$response = $api_request(
			'POST',
			sprintf( '%s/repos/%s/issues/%d/comments', self::API_BASE, $repo, $pull_number ),
			array( 'body' => $target_body ),
			$pat
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::buildManagedCommentResponse( 'created', $response['data'] ?? array() );
	}

	private static function normalizeManagedCommentMarker( string $marker ): string {
		$marker = trim( $marker );
		if ( '' === $marker ) {
			$marker = '<!-- datamachine-pr-review -->';
		}

		if ( str_starts_with( $marker, '<!--' ) && str_ends_with( $marker, '-->' ) ) {
			$inner = trim( substr( $marker, 4, -3 ) );
		} else {
			$inner = $marker;
		}

		$inner = str_replace( '--', '-', $inner );
		return '<!-- ' . trim( $inner ) . ' -->';
	}

	private static function buildManagedPullReviewCommentBody( string $body, string $marker, string $head_sha, string $mode ): string {
		$markers = array( $marker );
		if ( 'per_head_sha' === $mode ) {
			$markers[] = self::buildManagedHeadMarker( $head_sha );
		}

		return rtrim( $body ) . "\n\n" . implode( "\n", $markers );
	}

	private static function buildManagedHeadMarker( string $head_sha ): string {
		return '<!-- datamachine-pr-review-head-sha: ' . str_replace( '--', '-', $head_sha ) . ' -->';
	}

	private static function findManagedPullReviewComment( array $comments, string $login, string $marker, string $head_sha, string $mode ): ?array {
		$matched = null;
		foreach ( $comments as $comment ) {
			if ( ! is_array( $comment ) ) {
				continue;
			}

			if ( (string) ( $comment['user']['login'] ?? '' ) !== $login ) {
				continue;
			}

			$comment_body = (string) ( $comment['body'] ?? '' );
			if ( ! str_contains( $comment_body, $marker ) ) {
				continue;
			}

			if ( 'per_head_sha' === $mode && ! str_contains( $comment_body, self::buildManagedHeadMarker( $head_sha ) ) ) {
				continue;
			}

			if ( null === $matched || self::isNewerGitHubComment( $comment, $matched ) ) {
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
		if ( isset( $input['marker'] ) && '' !== (string) $input['marker'] ) {
			$marker = str_replace( '--', '-', trim( (string) $input['marker'] ) );
			$body  .= "\n\n<!-- {$marker} -->";
		}

		return array(
			'repo'         => $input['repo'] ?? '',
			'issue_number' => (int) ( $input['pull_number'] ?? 0 ),
			'body'         => $body,
		);
	}

	public static function listPulls( array $input ): array|\WP_Error {
		$repo = sanitize_text_field( $input['repo'] ?? '' );
		if ( empty( $repo ) ) {
			return new \WP_Error( 'missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'state'    => sanitize_text_field( $input['state'] ?? 'open' ),
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::DEFAULT_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
		);

		$url      = sprintf( '%s/repos/%s/pulls', self::API_BASE, $repo );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$normalized = array_map( array( self::class, 'normalizePull' ), $response['data'] );

		return array(
			'success' => true,
			'pulls'   => $normalized,
			'count'   => count( $normalized ),
		);
	}

	/**
	 * Get one pull request with full review context.
	 *
	 * @param array $input {
	 *     Required: repo, pull_number. Optional: head_sha, max_patch_chars.
	 * }
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function getPullReviewContext( array $input ): array|\WP_Error {
		$repo        = sanitize_text_field( $input['repo'] ?? '' );
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty( $repo ) || $pull_number <= 0 ) {
			return new \WP_Error( 'missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$pull = self::getPull( array(
			'repo'        => $repo,
			'pull_number' => $pull_number,
		) );
		if ( is_wp_error( $pull ) ) {
			return $pull;
		}

		$files = array();
		$page  = 1;
		do {
			$file_page = self::listPullFiles( array(
				'repo'        => $repo,
				'pull_number' => $pull_number,
				'per_page'    => self::MAX_PER_PAGE,
				'page'        => $page,
			) );
			if ( is_wp_error( $file_page ) ) {
				return $file_page;
			}

			$page_files = $file_page['files'] ?? array();
			$page_count = count( $page_files );
			$files      = array_merge( $files, $page_files );
			++$page;
		} while ( $page_count >= self::MAX_PER_PAGE );

		$context = self::normalizePullReviewContext(
			$repo,
			$pull['pull'],
			$files,
			array(
				'head_sha'         => sanitize_text_field( $input['head_sha'] ?? '' ),
				'max_patch_chars'  => (int) ( $input['max_patch_chars'] ?? 200000 ),
				'expanded_context' => self::buildPullReviewExpandedContext( $repo, $pull['pull'], $files, $input ),
			)
		);

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return array(
			'success' => true,
			'context' => $context,
		);
	}

	/**
	 * Build optional full-file context for PR review packets.
	 *
	 * @param string        $repo    Repository in owner/repo format.
	 * @param array         $pull    Normalized pull request payload.
	 * @param array         $files   Normalized changed file payloads.
	 * @param array         $options Expansion options.
	 * @param callable|null $fetcher Optional test seam: fn(string $path, string $ref, string $side): array|WP_Error.
	 * @return array|null Expanded context object, or null when no expansion was requested.
	 */
	public static function buildPullReviewExpandedContext( string $repo, array $pull, array $files, array $options = array(), ?callable $fetcher = null ): ?array {
		$include_file_contents = ! empty( $options['include_file_contents'] );
		$include_base_contents = ! empty( $options['include_base_contents'] );
		$context_paths         = self::normalizeContextPaths( $options['context_paths'] ?? array() );

		if ( ! $include_file_contents && empty( $context_paths ) ) {
			return null;
		}

		$limits = array(
			'max_file_content_chars'  => max( 1, (int) ( $options['max_file_content_chars'] ?? 20000 ) ),
			'max_context_files'       => max( 1, (int) ( $options['max_context_files'] ?? 10 ) ),
			'max_total_context_chars' => max( 1, (int) ( $options['max_total_context_chars'] ?? 100000 ) ),
		);

		$head_ref = (string) ( $pull['head_sha'] ?? $pull['head_ref'] ?? $pull['head'] ?? '' );
		$base_ref = (string) ( $pull['base_sha'] ?? $pull['base_ref'] ?? $pull['base'] ?? '' );
		$fetcher  = $fetcher ?? static function ( string $path, string $ref, string $side ) use ( $repo ): array|\WP_Error {
			unset( $side );

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
					self::recordExpandedContextSkip( $expanded, $path, 'changed_file', 'limit_exceeded' );
					continue;
				}

				$entry = array(
					'path' => $path,
				);

				if ( '' !== $head_ref ) {
					$entry['head'] = self::fetchBoundedContextFile( $fetcher, $path, $head_ref, 'head', $limits['max_file_content_chars'], $remaining_chars );
					self::applyContextFileAccounting( $expanded, $entry['head'], $remaining_chars );
				}

				if ( $include_base_contents && '' !== $base_ref && $remaining_chars > 0 ) {
					$entry['base'] = self::fetchBoundedContextFile( $fetcher, $path, $base_ref, 'base', $limits['max_file_content_chars'], $remaining_chars );
					self::applyContextFileAccounting( $expanded, $entry['base'], $remaining_chars );
				}

				$expanded['changed_files'][] = $entry;
				--$remaining_files;
			}
		}

		foreach ( $context_paths as $path ) {
			if ( $remaining_files <= 0 || $remaining_chars <= 0 ) {
				self::recordExpandedContextSkip( $expanded, $path, 'context_path', 'limit_exceeded' );
				continue;
			}

			$file_context = self::fetchBoundedContextFile( $fetcher, $path, $head_ref, 'head', $limits['max_file_content_chars'], $remaining_chars );
			self::applyContextFileAccounting( $expanded, $file_context, $remaining_chars );
			$expanded['extra_files'][] = array(
				'path' => $path,
				'head' => $file_context,
			);
			--$remaining_files;
		}

		$expanded['summary']['included_files'] = count( $expanded['changed_files'] ) + count( $expanded['extra_files'] );
		return $expanded;
	}

	/**
	 * Normalize context paths from array or comma/newline separated string input.
	 */
	private static function normalizeContextPaths( mixed $paths ): array {
		if ( is_string( $paths ) ) {
			$split_paths = preg_split( '/[\r\n,]+/', $paths );
			$paths       = false === $split_paths ? array() : $split_paths;
		}

		if ( ! is_array( $paths ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $paths as $path ) {
			$path = trim( (string) $path );
			$path = ltrim( $path, '/' );
			if ( '' === $path || str_contains( $path, '..' ) ) {
				continue;
			}
			$normalized[ $path ] = true;
		}

		return array_keys( $normalized );
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

		$result = $fetcher( $path, $ref, $side );
		if ( is_wp_error( $result ) ) {
			return array(
				'ref'      => $ref,
				'included' => false,
				'reason'   => $result->get_error_code(),
				'error'    => $result->get_error_message(),
			);
		}

		$file     = $result['file'] ?? $result;
		$content  = (string) ( $file['content'] ?? '' );
		$original = strlen( $content );
		$limit    = min( $max_file_chars, $remaining_chars );
		$included = substr( $content, 0, $limit );
		$chars    = strlen( $included );

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
		if ( empty( $file_context['included'] ) ) {
			++$expanded['summary']['skipped_files'];
			return;
		}

		$included_chars                         = (int) ( $file_context['included_chars'] ?? 0 );
		$expanded['summary']['included_chars'] += $included_chars;
		$remaining_chars                        = max( 0, $remaining_chars - $included_chars );

		if ( ! empty( $file_context['truncated'] ) ) {
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
	 * @param array $input Required: repo, pull_number.
	 * @return array|\WP_Error { success: bool, pull: normalized } or error.
	 */
	public static function getPull( array $input ): array|\WP_Error {
		$repo        = sanitize_text_field( $input['repo'] ?? '' );
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty( $repo ) || $pull_number <= 0 ) {
			return new \WP_Error( 'missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$url      = sprintf( '%s/repos/%s/pulls/%d', self::API_BASE, $repo, $pull_number );
		$response = self::apiGet( $url, array(), $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'pull'    => self::normalizePull( $response['data'] ),
		);
	}

	/**
	 * List changed files for a pull request.
	 *
	 * @param array $input Required: repo, pull_number. Optional: per_page, page.
	 * @return array|\WP_Error { success: bool, files: normalized[], count: int } or error.
	 */
	public static function listPullFiles( array $input ): array|\WP_Error {
		$repo        = sanitize_text_field( $input['repo'] ?? '' );
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );

		if ( empty( $repo ) || $pull_number <= 0 ) {
			return new \WP_Error( 'missing_params', 'Repository (owner/repo) and pull_number are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::MAX_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
		);

		$url      = sprintf( '%s/repos/%s/pulls/%d/files', self::API_BASE, $repo, $pull_number );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$normalized = array_map( array( self::class, 'normalizePullFile' ), $response['data'] );

		return array(
			'success' => true,
			'files'   => $normalized,
			'count'   => count( $normalized ),
		);
	}

	public static function listRepos( array $input ): array|\WP_Error {
		$owner = sanitize_text_field( $input['owner'] ?? '' );
		if ( empty( $owner ) ) {
			return new \WP_Error( 'missing_owner', 'Owner (user or org) is required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::DEFAULT_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'sort'     => sanitize_text_field( $input['sort'] ?? 'updated' ),
		);

		if ( ! empty( $input['type'] ) ) {
			$query_params['type'] = sanitize_text_field( $input['type'] );
		}

		$url      = sprintf( '%s/orgs/%s/repos', self::API_BASE, $owner );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( is_wp_error( $response ) ) {
			$url      = sprintf( '%s/users/%s/repos', self::API_BASE, $owner );
			$response = self::apiGet( $url, $query_params, $pat );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		$normalized = array_map( array( self::class, 'normalizeRepo' ), $response['data'] );

		return array(
			'success' => true,
			'repos'   => $normalized,
			'count'   => count( $normalized ),
		);
	}

	/**
	 * Create or update a file in a repository via the Contents API.
	 *
	 * GET for SHA (if exists) → PUT with base64 content. Genuinely upsert:
	 * creates the file if it doesn't exist, updates it if it does.
	 *
	 * @param array $input {
	 *     Required: repo, file_path, content, commit_message.
	 *     Optional: branch.
	 * }
	 * @return array|\WP_Error Success payload or error.
	 */
	public static function createOrUpdateFile( array $input ): array|\WP_Error {
		$repo = self::resolveRepo( sanitize_text_field( $input['repo'] ?? '' ) );
		if ( empty( $repo ) ) {
			return new \WP_Error( 'missing_repo', 'Repository (owner/repo) is required or configure a default repo.', array( 'status' => 400 ) );
		}

		$file_path = sanitize_text_field( $input['file_path'] ?? '' );
		if ( empty( $file_path ) ) {
			return new \WP_Error( 'missing_file_path', 'File path is required.', array( 'status' => 400 ) );
		}

		$content = $input['content'] ?? '';
		if ( '' === $content ) {
			return new \WP_Error( 'missing_content', 'File content is required.', array( 'status' => 400 ) );
		}

		$commit_message = sanitize_text_field( $input['commit_message'] ?? '' );
		if ( empty( $commit_message ) ) {
			return new \WP_Error( 'missing_commit_message', 'Commit message is required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$branch = sanitize_text_field( $input['branch'] ?? '' );

		// Check if the file already exists to get its SHA for update.
		$get_url    = sprintf( '%s/repos/%s/contents/%s', self::API_BASE, $repo, $file_path );
		$get_params = array();
		if ( ! empty( $branch ) ) {
			$get_params['ref'] = $branch;
		}

		$existing = self::apiGet( $get_url, $get_params, $pat );

		$body = array(
			'message' => $commit_message,
			'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by GitHub API.
		);

		// If the file exists, include its SHA for the update.
		if ( ! is_wp_error( $existing ) && ! empty( $existing['data']['sha'] ) ) {
			$body['sha'] = $existing['data']['sha'];
		}

		if ( ! empty( $branch ) ) {
			$body['branch'] = $branch;
		}

		$put_url  = sprintf( '%s/repos/%s/contents/%s', self::API_BASE, $repo, $file_path );
		$response = self::apiRequest( 'PUT', $put_url, $body, $pat );

		if ( is_wp_error( $response ) ) {
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
				isset( $data['content'] ) ? 'updated' : 'created',
				$repo
			),
		);
	}

	/**
	 * Get the recursive file tree for a repository branch.
	 *
	 * Calls `GET /repos/{owner}/{repo}/git/trees/{sha}?recursive=1`.
	 * Returns all files and directories in a single response.
	 *
	 * @param array $input {
	 *     Required: repo. Optional: branch (default: default branch).
	 * }
	 * @return array|\WP_Error { files: normalized[], count: int } or error.
	 */
	public static function getRepoTree( array $input ): array|\WP_Error {
		$repo = sanitize_text_field( $input['repo'] ?? '' );
		if ( empty( $repo ) ) {
			return new \WP_Error( 'missing_repo', 'Repository is required (owner/repo format).', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$branch = sanitize_text_field( $input['ref'] ?? $input['branch'] ?? '' );
		$ref    = ! empty( $branch ) ? $branch : 'HEAD';

		$url      = sprintf( '%s/repos/%s/git/trees/%s', self::API_BASE, $repo, $ref );
		$response = self::apiGet( $url, array( 'recursive' => '1' ), $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$tree = $response['data']['tree'] ?? array();

		// Filter to blobs (files) only — skip trees (directories).
		$path_prefix = trim( sanitize_text_field( $input['path'] ?? '' ), '/' );
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
				return $entry_path === $path_prefix || str_starts_with( $entry_path, $path_prefix . '/' );
			}
		);
		$files       = array_values( array_map( array( self::class, 'normalizeTreeEntry' ), $files ) );

		return array(
			'success' => true,
			'files'   => $files,
			'count'   => count( $files ),
		);
	}

	/**
	 * Get the decoded content of a single file from a repository.
	 *
	 * Calls `GET /repos/{owner}/{repo}/contents/{path}`.
	 * GitHub returns base64-encoded content for files ≤ 1 MB.
	 *
	 * @param array $input {
	 *     Required: repo, path. Optional: branch.
	 * }
	 * @return array|\WP_Error { success: bool, file: normalized } or error.
	 */
	public static function getFileContents( array $input ): array|\WP_Error {
		$repo = sanitize_text_field( $input['repo'] ?? '' );
		$path = sanitize_text_field( $input['path'] ?? '' );

		if ( empty( $repo ) || empty( $path ) ) {
			return new \WP_Error( 'missing_params', 'Repository (owner/repo) and file path are required.', array( 'status' => 400 ) );
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array();
		$branch       = sanitize_text_field( $input['ref'] ?? $input['branch'] ?? '' );
		if ( ! empty( $branch ) ) {
			$query_params['ref'] = $branch;
		}

		$url      = sprintf( '%s/repos/%s/contents/%s', self::API_BASE, $repo, ltrim( $path, '/' ) );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'];

		// GitHub returns a directory listing if path is a directory.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			return new \WP_Error( 'is_directory', 'Path is a directory, not a file.', array( 'status' => 400 ) );
		}

		// Reject large files (> 1 MB returns HTML URL instead of content).
		if ( empty( $data['content'] ) ) {
			return new \WP_Error( 'file_too_large', 'File content not available (may exceed 1 MB GitHub API limit).', array( 'status' => 400 ) );
		}

		$decoded = base64_decode( $data['content'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required by GitHub Contents API.
		if ( false === $decoded ) {
			return new \WP_Error( 'decode_failed', 'Failed to decode file content.', array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'file'    => self::normalizeFileContent( $data, $decoded ),
		);
	}

	// -------------------------------------------------------------------------
	// HTTP Helpers
	// -------------------------------------------------------------------------

	public static function apiGet( string $url, array $query_params, string $pat ): array|\WP_Error {
		if ( ! empty( $query_params ) ) {
			$url = add_query_arg( $query_params, $url );
		}

		$response = wp_remote_get( $url, array(
			'headers' => self::getHeaders( $pat ),
			'timeout' => 30,
		) );

		return self::parseResponse( $response );
	}

	public static function apiRequest( string $method, string $url, array $body, string $pat ): array|\WP_Error {
		$response = wp_remote_request( $url, array(
			'method'  => $method,
			'headers' => self::getHeaders( $pat ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );

		return self::parseResponse( $response );
	}

	private static function parseResponse( $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'github_request_failed', 'GitHub API request failed: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			$message = $body['message'] ?? 'Unknown error';
			$code    = $status_code >= 500 ? 'github_server_error' : ( 404 === $status_code ? 'github_not_found' : 'github_api_error' );
			return new \WP_Error( $code, sprintf( 'GitHub API error (%d): %s', $status_code, $message ), array( 'status' => $status_code ) );
		}

		return array(
			'success' => true,
			'data'    => $body,
		);
	}

	private static function getHeaders( string $pat ): array {
		return array(
			'Authorization' => 'token ' . $pat,
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'DataMachineCode',
			'Content-Type'  => 'application/json',
		);
	}

	// -------------------------------------------------------------------------
	// Normalizers
	// -------------------------------------------------------------------------

	public static function normalizeIssue( array $issue ): array {
		return array(
			'number'     => $issue['number'] ?? 0,
			'title'      => $issue['title'] ?? '',
			'state'      => $issue['state'] ?? '',
			'body'       => $issue['body'] ?? '',
			'html_url'   => $issue['html_url'] ?? '',
			'user'       => $issue['user']['login'] ?? '',
			'labels'     => array_map( fn( $label ) => $label['name'] ?? '', $issue['labels'] ?? array() ),
			'assignees'  => array_map( fn( $a ) => $a['login'] ?? '', $issue['assignees'] ?? array() ),
			'comments'   => $issue['comments'] ?? 0,
			'created_at' => $issue['created_at'] ?? '',
			'updated_at' => $issue['updated_at'] ?? '',
			'closed_at'  => $issue['closed_at'] ?? '',
		);
	}

	public static function normalizePull( array $pr ): array {
		return array(
			'number'     => $pr['number'] ?? 0,
			'title'      => $pr['title'] ?? '',
			'state'      => $pr['state'] ?? '',
			'body'       => $pr['body'] ?? '',
			'html_url'   => $pr['html_url'] ?? '',
			'user'       => $pr['user']['login'] ?? '',
			'head'       => $pr['head']['ref'] ?? '',
			'head_ref'   => $pr['head']['ref'] ?? '',
			'head_sha'   => $pr['head']['sha'] ?? '',
			'base'       => $pr['base']['ref'] ?? '',
			'base_ref'   => $pr['base']['ref'] ?? '',
			'base_sha'   => $pr['base']['sha'] ?? '',
			'draft'      => $pr['draft'] ?? false,
			'merged'     => ! empty( $pr['merged_at'] ),
			'labels'     => array_map( fn( $label ) => $label['name'] ?? '', $pr['labels'] ?? array() ),
			'created_at' => $pr['created_at'] ?? '',
			'updated_at' => $pr['updated_at'] ?? '',
			'closed_at'  => $pr['closed_at'] ?? '',
			'merged_at'  => $pr['merged_at'] ?? '',
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
	 * Build a review-ready DataPacket for one pull request.
	 *
	 * @param string $repo Repository in owner/repo format.
	 * @param array  $pull Normalized pull request payload.
	 * @param array  $files Normalized changed file payloads.
	 * @param array  $options Optional head_sha and max_patch_chars.
	 * @return array|\WP_Error DataPacket-compatible array or validation error.
	 */
	public static function normalizePullReviewContext( string $repo, array $pull, array $files, array $options = array() ): array|\WP_Error {
		$pull_number = (int) ( $pull['number'] ?? 0 );
		$head_sha    = (string) ( $pull['head_sha'] ?? '' );
		$expected    = (string) ( $options['head_sha'] ?? '' );

		if ( '' !== $expected && '' !== $head_sha && $expected !== $head_sha ) {
			return new \WP_Error(
				'github_pr_head_sha_mismatch',
				sprintf( 'GitHub PR head SHA mismatch for %s#%d: expected %s, found %s.', $repo, $pull_number, $expected, $head_sha ),
				array( 'status' => 409 )
			);
		}

		$max_patch_chars = max( 0, (int) ( $options['max_patch_chars'] ?? 200000 ) );
		$patch_chars     = 0;
		$truncated_files = 0;
		$changed_files   = array();

		foreach ( $files as $file ) {
			$patch      = (string) ( $file['patch'] ?? '' );
			$patch_size = strlen( $patch );
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

		$item_identifier = sprintf( '%s#%d@%s', $repo, $pull_number, $head_sha );
		$title           = sprintf( 'PR review context: %s#%d %s', $repo, $pull_number, $pull['title'] ?? '' );

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

		if ( isset( $options['expanded_context'] ) && is_array( $options['expanded_context'] ) ) {
			$context['expanded_context'] = $options['expanded_context'];
		}

		return array(
			'title'    => $title,
			'content'  => wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
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
	 * @param array  $data     Raw GitHub API response data.
	 * @param string $decoded  Base64-decoded file content.
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

	public static function getPat(): string {
		return trim( PluginSettings::get( 'github_pat', '' ) );
	}

	public static function isConfigured(): bool {
		return ! empty( self::getPat() );
	}

	public static function getDefaultRepo(): string {
		return trim( PluginSettings::get( 'github_default_repo', '' ) );
	}

	public static function getRegisteredRepos(): array {
		$repos = array();

		$default_repo = self::getDefaultRepo();
		if ( ! empty( $default_repo ) && str_contains( $default_repo, '/' ) ) {
			$parts   = explode( '/', $default_repo, 2 );
			$repos[] = array(
				'owner' => $parts[0],
				'repo'  => $parts[1],
				'label' => 'Default (from settings)',
			);
		}

		$repos = apply_filters( 'datamachine_github_issue_repos', $repos );

		$seen   = array();
		$unique = array();
		foreach ( $repos as $entry ) {
			$key = strtolower( ( $entry['owner'] ?? '' ) . '/' . ( $entry['repo'] ?? '' ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $entry;
		}

		return $unique;
	}

	public static function resolveRepo( string $repo = '' ): string {
		if ( ! empty( $repo ) ) {
			return $repo;
		}

		$default = self::getDefaultRepo();
		if ( ! empty( $default ) ) {
			return $default;
		}

		$registered = self::getRegisteredRepos();
		if ( ! empty( $registered ) ) {
			return $registered[0]['owner'] . '/' . $registered[0]['repo'];
		}

		return '';
	}

	private static function patError(): \WP_Error {
		return new \WP_Error( 'pat_not_configured', 'GitHub Personal Access Token not configured. Set github_pat in Data Machine settings.', array( 'status' => 403 ) );
	}

	private static function clampPerPage( $per_page ): int {
		return max( 1, min( self::MAX_PER_PAGE, (int) $per_page ) );
	}
}
