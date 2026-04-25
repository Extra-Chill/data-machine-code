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

		$branch = sanitize_text_field( $input['branch'] ?? '' );
		$ref    = ! empty( $branch ) ? $branch : 'HEAD';

		$url      = sprintf( '%s/repos/%s/git/trees/%s', self::API_BASE, $repo, $ref );
		$response = self::apiGet( $url, array( 'recursive' => '1' ), $pat );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$tree = $response['data']['tree'] ?? array();

		// Filter to blobs (files) only — skip trees (directories).
		$files = array_filter( $tree, fn( $entry ) => 'blob' === ( $entry['type'] ?? '' ) );
		$files = array_values( array_map( array( self::class, 'normalizeTreeEntry' ), $files ) );

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
		$branch       = sanitize_text_field( $input['branch'] ?? '' );
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
			'base'       => $pr['base']['ref'] ?? '',
			'draft'      => $pr['draft'] ?? false,
			'merged'     => ! empty( $pr['merged_at'] ),
			'labels'     => array_map( fn( $label ) => $label['name'] ?? '', $pr['labels'] ?? array() ),
			'created_at' => $pr['created_at'] ?? '',
			'updated_at' => $pr['updated_at'] ?? '',
			'closed_at'  => $pr['closed_at'] ?? '',
			'merged_at'  => $pr['merged_at'] ?? '',
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
