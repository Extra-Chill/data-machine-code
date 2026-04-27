<?php
/**
 * GitHub Tools — AI agent tools for GitHub read/write operations.
 *
 * Complements the existing GitHubIssueTool (create-only) with read and
 * management capabilities: list issues, list PRs, view issue, close issue,
 * comment on issue, list repos.
 *
 * @package DataMachineCode\Tools
 * @since 0.1.0
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Abilities\GitHubAbilities;

defined( 'ABSPATH' ) || exit;

class GitHubTools extends BaseTool {

	/**
	 * Constructor — register all GitHub tools as global tools.
	 */
	public function __construct() {
		$contexts = array( 'chat', 'pipeline' );
		$this->registerTool( 'list_github_issues', array( $this, 'getListIssuesDefinition' ), $contexts, array( 'access_level' => 'editor' ) );
		$this->registerTool( 'get_github_issue', array( $this, 'getGetIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ) );
		$this->registerTool( 'manage_github_issue', array( $this, 'getManageIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ) );
		$this->registerTool( 'comment_github_pull_request', array( $this, 'getCommentPullRequestDefinition' ), $contexts, array( 'access_level' => 'editor' ) );
		$this->registerTool( 'list_github_pulls', array( $this, 'getListPullsDefinition' ), $contexts, array( 'access_level' => 'editor' ) );
		$this->registerTool( 'get_github_pull', array( $this, 'getGetPullDefinition' ), $contexts, array(
			'access_level' => 'editor',
			'ability'      => 'datamachine/get-github-pull',
		) );
		$this->registerTool( 'get_github_pull_files', array( $this, 'getPullFilesDefinition' ), $contexts, array(
			'access_level' => 'editor',
			'ability'      => 'datamachine/list-github-pull-files',
		) );
		$this->registerTool( 'get_github_pull_review_context', array( $this, 'getPullReviewContextDefinition' ), $contexts, array(
			'access_level' => 'editor',
			'ability'      => 'datamachine/get-github-pull-review-context',
		) );
		$this->registerTool( 'list_github_tree', array( $this, 'getListTreeDefinition' ), $contexts, array(
			'access_level' => 'editor',
			'ability'      => 'datamachine/list-github-tree',
		) );
		$this->registerTool( 'get_github_file', array( $this, 'getGetFileDefinition' ), $contexts, array(
			'access_level' => 'editor',
			'ability'      => 'datamachine/get-github-file',
		) );
		$this->registerTool( 'list_github_repos', array( $this, 'getListReposDefinition' ), $contexts, array( 'access_level' => 'editor' ) );
	}

	/**
	 * Handle tool call — dispatches to the appropriate handler based on tool_def.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition with 'method' key.
	 * @return array
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$method = $tool_def['method'] ?? 'handleListIssues';
		if ( method_exists( $this, $method ) ) {
			return $this->{$method}( $parameters, $tool_def );
		}
		return $this->buildErrorResponse( "Unknown method: {$method}", 'github_tools' );
	}

	/**
	 * Check if GitHub tools are properly configured.
	 *
	 * @param bool   $configured Current configuration status.
	 * @param string $tool_id    Tool identifier to check.
	 * @return bool True if configured.
	 */
	public function check_configuration( $configured, $tool_id ) {
		$github_tools = array(
			'list_github_issues',
			'get_github_issue',
			'manage_github_issue',
			'comment_github_pull_request',
			'list_github_pulls',
			'get_github_pull',
			'get_github_pull_files',
			'get_github_pull_review_context',
			'list_github_tree',
			'get_github_file',
			'list_github_repos',
		);
		if ( ! in_array( $tool_id, $github_tools, true ) ) {
			return $configured;
		}
		return GitHubAbilities::isConfigured();
	}

	/**
	 * Check if GitHub tools are configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return GitHubAbilities::isConfigured();
	}

	/**
	 * Get tool definition — returns the primary tool definition (list issues).
	 *
	 * Individual tools use their own definition methods via registerTool.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return $this->getListIssuesDefinition();
	}

	// -------------------------------------------------------------------------
	// List Issues
	// -------------------------------------------------------------------------

	/**
	 * Handle list_github_issues tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleListIssues( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::listIssues( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'list_github_issues' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_github_issues',
		);
	}

	/**
	 * Get tool definition for list_github_issues.
	 *
	 * @return array
	 */
	public function getListIssuesDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListIssues',
			'description' => 'List issues from a GitHub repository. Returns issue numbers, titles, states, labels, and assignees. Use to review open issues, track progress, or find specific issues by label.',
			'parameters'  => array(
				'repo'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format (e.g., Extra-Chill/data-machine).',
				),
				'state'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Issue state: open, closed, or all. Default: open.',
				),
				'labels'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Comma-separated label names to filter by.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Results per page (max: 100). Default: 30.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Get Issue
	// -------------------------------------------------------------------------

	/**
	 * Handle get_github_issue tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleGetIssue( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::getIssue( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'get_github_issue' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'get_github_issue',
		);
	}

	/**
	 * Get tool definition for get_github_issue.
	 *
	 * @return array
	 */
	public function getGetIssueDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleGetIssue',
			'description' => 'Get a single GitHub issue with full details including body, labels, assignees, and comment count.',
			'parameters'  => array(
				'repo'         => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'issue_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Issue number.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Manage Issue (update, close, comment)
	// -------------------------------------------------------------------------

	/**
	 * Handle manage_github_issue tool call.
	 *
	 * Supports three actions: update, close, comment.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleManageIssue( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		if ( 'comment' === $action ) {
			$result = GitHubAbilities::commentOnIssue( $parameters );
		} elseif ( 'close' === $action ) {
			$parameters['state'] = 'closed';
			$result              = GitHubAbilities::updateIssue( $parameters );
		} else {
			// Default: update.
			$result = GitHubAbilities::updateIssue( $parameters );
		}

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'manage_github_issue' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_github_issue',
		);
	}

	/**
	 * Get tool definition for manage_github_issue.
	 *
	 * @return array
	 */
	public function getManageIssueDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleManageIssue',
			'description' => 'Update, close, or comment on a GitHub issue. Use action "update" to change title/body/labels, "close" to close the issue, or "comment" to add a comment.',
			'parameters'  => array(
				'repo'         => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'issue_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Issue number.',
				),
				'action'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: update, close, or comment.',
				),
				'title'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New issue title (update action).',
				),
				'body'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New issue body (update action) or comment text (comment action).',
				),
				'labels'       => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Labels to set (update action). Replaces existing labels.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Comment Pull Request
	// -------------------------------------------------------------------------

	/**
	 * Handle comment_github_pull_request tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleCommentPullRequest( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::commentOnPullRequest( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'comment_github_pull_request' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'comment_github_pull_request',
		);
	}

	/**
	 * Get tool definition for comment_github_pull_request.
	 *
	 * @return array
	 */
	public function getCommentPullRequestDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleCommentPullRequest',
			'description' => 'Comment on a GitHub pull request without granting broader issue update or close capabilities.',
			'parameters'  => array(
				'repo'        => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'pull_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Pull request number.',
				),
				'body'        => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Comment body (supports GitHub Markdown).',
				),
				'marker'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional stable marker appended as an HTML comment for future update-by-marker support.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// List Pulls
	// -------------------------------------------------------------------------

	/**
	 * Handle list_github_pulls tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleListPulls( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::listPulls( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'list_github_pulls' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_github_pulls',
		);
	}

	/**
	 * Get tool definition for list_github_pulls.
	 *
	 * @return array
	 */
	public function getListPullsDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListPulls',
			'description' => 'List pull requests from a GitHub repository. Returns PR numbers, titles, states, branches, and merge status.',
			'parameters'  => array(
				'repo'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'state' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'PR state: open, closed, or all. Default: open.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Read-only PR and source context tools.
	// -------------------------------------------------------------------------

	/**
	 * Execute a registered GitHub ability for a tool call.
	 *
	 * @param string $ability_name Ability slug.
	 * @param string $tool_name    Tool name for the response envelope.
	 * @param array  $parameters   Tool parameters.
	 * @return array
	 */
	private function executeGitHubAbility( string $ability_name, string $tool_name, array $parameters ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->buildErrorResponse( 'WordPress Abilities API is not available.', $tool_name );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return $this->buildErrorResponse( sprintf( 'GitHub ability %s is not available.', $ability_name ), $tool_name );
		}

		$result = $ability->execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => $tool_name,
		);
	}

	/**
	 * Handle get_github_pull tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleGetPull( array $parameters ): array {
		return $this->executeGitHubAbility( 'datamachine/get-github-pull', 'get_github_pull', $parameters );
	}

	/**
	 * Get tool definition for get_github_pull.
	 *
	 * @return array
	 */
	public function getGetPullDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleGetPull',
			'description' => 'Get one GitHub pull request with normalized title, body, branch, SHA, labels, and merge metadata.',
			'parameters'  => array(
				'repo'        => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'pull_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Pull request number.',
				),
			),
		);
	}

	/**
	 * Handle get_github_pull_files tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handlePullFiles( array $parameters ): array {
		return $this->executeGitHubAbility( 'datamachine/list-github-pull-files', 'get_github_pull_files', $parameters );
	}

	/**
	 * Get tool definition for get_github_pull_files.
	 *
	 * @return array
	 */
	public function getPullFilesDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handlePullFiles',
			'description' => 'List files changed by a GitHub pull request, including filename, status, additions, deletions, and patch when available.',
			'parameters'  => array(
				'repo'        => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'pull_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Pull request number.',
				),
				'per_page'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Results per page (max: 100). Default: 100.',
				),
				'page'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Page number. Default: 1.',
				),
			),
		);
	}

	/**
	 * Handle get_github_pull_review_context tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handlePullReviewContext( array $parameters ): array {
		return $this->executeGitHubAbility( 'datamachine/get-github-pull-review-context', 'get_github_pull_review_context', $parameters );
	}

	/**
	 * Get tool definition for get_github_pull_review_context.
	 *
	 * @return array
	 */
	public function getPullReviewContextDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handlePullReviewContext',
			'description' => 'Build a review-ready context packet for a GitHub pull request, including normalized PR metadata and changed-file patches.',
			'parameters'  => array(
				'repo'                    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'pull_number'             => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Pull request number.',
				),
				'head_sha'                => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional expected pull request head SHA. Returns an error if GitHub reports a different head SHA.',
				),
				'max_patch_chars'         => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum cumulative patch characters to include. Default: 200000.',
				),
				'include_file_contents'   => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Opt in to bounded full-file contents for changed files.',
				),
				'include_base_contents'   => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'When changed file contents are enabled, also include bounded base-branch contents for comparison.',
				),
				'context_paths'           => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Additional repository paths to include from the PR head ref.',
				),
				'max_file_content_chars'  => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum characters included per expanded file content block. Default: 20000.',
				),
				'max_context_files'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum number of files included in expanded PR review context. Default: 10.',
				),
				'max_total_context_chars' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum cumulative characters included across expanded PR review context files. Default: 100000.',
				),
			),
		);
	}

	/**
	 * Handle list_github_tree tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleListTree( array $parameters ): array {
		return $this->executeGitHubAbility( 'datamachine/list-github-tree', 'list_github_tree', $parameters );
	}

	/**
	 * Get tool definition for list_github_tree.
	 *
	 * @return array
	 */
	public function getListTreeDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListTree',
			'description' => 'List files in a GitHub repository tree at a branch, tag, or commit SHA. Optionally filter to a path prefix.',
			'parameters'  => array(
				'repo' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'ref'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Branch, tag, or commit SHA. Defaults to HEAD.',
				),
				'path' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional path prefix to filter returned files.',
				),
			),
		);
	}

	/**
	 * Handle get_github_file tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleGetFile( array $parameters ): array {
		return $this->executeGitHubAbility( 'datamachine/get-github-file', 'get_github_file', $parameters );
	}

	/**
	 * Get tool definition for get_github_file.
	 *
	 * @return array
	 */
	public function getGetFileDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleGetFile',
			'description' => 'Get decoded content for a single file from a GitHub repository.',
			'parameters'  => array(
				'repo' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'path' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'File path within the repository.',
				),
				'ref'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Branch, tag, or commit SHA. Defaults to the repository default branch.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// List Repos
	// -------------------------------------------------------------------------

	/**
	 * Handle list_github_repos tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleListRepos( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::listRepos( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'list_github_repos' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_github_repos',
		);
	}

	/**
	 * Get tool definition for list_github_repos.
	 *
	 * @return array
	 */
	public function getListReposDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListRepos',
			'description' => 'List GitHub repositories for a user or organization. Shows repo names, languages, stars, open issues, and last push date.',
			'parameters'  => array(
				'owner' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'GitHub user or organization name.',
				),
				'sort'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Sort by: created, updated, pushed, full_name. Default: updated.',
				),
			),
		);
	}
}
