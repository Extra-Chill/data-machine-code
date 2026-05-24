<?php
/**
 * GitHub Tools — AI agent tools for GitHub read/write operations.
 *
 * Complements the existing GitHubIssueTool (create-only) with read and
 * management capabilities: list issues, list PRs, view issue, close issue,
 * comment on issue, list repos.
 *
 * @package DataMachineCode\Tools
 * @since   0.1.0
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Abilities\GitHubAbilities;

defined('ABSPATH') || exit;

class GitHubTools extends BaseTool
{

    /**
     * Constructor — register all GitHub tools as global tools.
     */
    public function __construct()
    {
        $contexts = array( 'chat', 'pipeline' );
        $this->registerTool('list_github_issues', array( $this, 'getListIssuesDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool('get_github_issue', array( $this, 'getGetIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool('manage_github_issue', array( $this, 'getManageIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool('add_label_to_issue', array( $this, 'getAddLabelToIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool('remove_label_from_issue', array( $this, 'getRemoveLabelFromIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool('comment_github_pull_request', array( $this, 'getCommentPullRequestDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool(
            'upsert_github_pull_review_comment', array( $this, 'getUpsertPullReviewCommentDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/upsert-github-pull-review-comment',
            ) 
        );
        $this->registerTool(
            'merge_github_pull_request', array( $this, 'getMergePullRequestDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/merge-github-pull-request',
            ) 
        );
        $this->registerTool(
            'cleanup_github_pull_request', array( $this, 'getCleanupPullRequestDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/cleanup-github-pull-request',
            ) 
        );
        $this->registerTool('list_github_pulls', array( $this, 'getListPullsDefinition' ), $contexts, array( 'access_level' => 'editor' ));
        $this->registerTool(
            'get_github_pull', array( $this, 'getGetPullDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-pull',
            ) 
        );
        $this->registerTool(
            'get_github_pull_files', array( $this, 'getPullFilesDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/list-github-pull-files',
            ) 
        );
        $this->registerTool(
            'get_github_check_runs', array( $this, 'getCheckRunsDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-check-runs',
            ) 
        );
        $this->registerTool(
            'get_github_commit_statuses', array( $this, 'getCommitStatusesDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-commit-statuses',
            ) 
        );
        $this->registerTool(
            'get_github_actions_artifact', array( $this, 'getActionsArtifactDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-actions-artifact',
            ) 
        );
        $this->registerTool(
            'get_github_homeboy_ci_results', array( $this, 'getHomeboyCiResultsDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-homeboy-ci-results',
            ) 
        );
        $this->registerTool(
            'get_github_pull_review_context', array( $this, 'getPullReviewContextDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-pull-review-context',
            ) 
        );
        $this->registerTool(
            'github_repo_review_profile', array( $this, 'getRepoReviewProfileDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-repo-review-profile',
            ) 
        );
        $this->registerTool(
            'run_pr_homeboy_review', array( $this, 'getRunPrHomeboyReviewDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine-code/run-pr-homeboy-review',
            ) 
        );
        $this->registerTool(
            'github_pr_documentation_impact', array( $this, 'getPullDocumentationImpactDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-pr-documentation-impact',
            ) 
        );
        $this->registerTool(
            'list_github_tree', array( $this, 'getListTreeDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/list-github-tree',
            ) 
        );
        $this->registerTool(
            'get_github_file', array( $this, 'getGetFileDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/get-github-file',
            ) 
        );
        $this->registerTool(
            'create_or_update_github_file', array( $this, 'getCreateOrUpdateFileDefinition' ), $contexts, array(
            'access_level' => 'editor',
            'ability'      => 'datamachine/create-or-update-github-file',
            ) 
        );
        $this->registerTool('list_github_repos', array( $this, 'getListReposDefinition' ), $contexts, array( 'access_level' => 'editor' ));
    }

    /**
     * Handle tool call — dispatches to the appropriate handler based on tool_def.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition with 'method' key.
     * @return array
     */
    public function handle_tool_call( array $parameters, array $tool_def = array() ): array
    {
        $method = $tool_def['method'] ?? 'handleListIssues';
        if (method_exists($this, $method) ) {
            return $this->{$method}($parameters, $tool_def);
        }
        return $this->buildErrorResponse("Unknown method: {$method}", 'github_tools');
    }

    /**
     * Build a standard GitHub tool error response.
     *
     * @param  string $message   Error message.
     * @param  string $tool_name Tool name.
     * @return array<string,mixed>
     */
    protected function buildErrorResponse( string $message, string $tool_name ): array
    {
        return array(
        'success'   => false,
        'error'     => $message,
        'tool_name' => $tool_name,
        );
    }

    /**
     * Check if GitHub tools are properly configured.
     *
     * @param  bool   $configured Current configuration status.
     * @param  string $tool_id    Tool identifier to check.
     * @return bool True if configured.
     */
    public function check_configuration( $configured, $tool_id )
    {
        $github_tools = array(
        'list_github_issues',
        'get_github_issue',
        'manage_github_issue',
        'add_label_to_issue',
        'remove_label_from_issue',
        'comment_github_pull_request',
        'upsert_github_pull_review_comment',
        'merge_github_pull_request',
        'cleanup_github_pull_request',
        'list_github_pulls',
        'get_github_pull',
        'get_github_pull_files',
        'get_github_check_runs',
        'get_github_commit_statuses',
        'get_github_actions_artifact',
        'get_github_homeboy_ci_results',
        'get_github_pull_review_context',
        'github_repo_review_profile',
        'run_pr_homeboy_review',
        'github_pr_documentation_impact',
        'list_github_tree',
        'get_github_file',
        'create_or_update_github_file',
        'list_github_repos',
        );
        if (! in_array($tool_id, $github_tools, true) ) {
            return $configured;
        }
        return GitHubAbilities::isConfigured();
    }

    /**
     * Check if GitHub tools are configured.
     *
     * @return bool
     */
    public static function is_configured(): bool
    {
        return GitHubAbilities::isConfigured();
    }

    /**
     * Get tool definition — returns the primary tool definition (list issues).
     *
     * Individual tools use their own definition methods via registerTool.
     *
     * @return array Tool definition array.
     */
    public function getToolDefinition(): array
    {
        return $this->getListIssuesDefinition();
    }

    // -------------------------------------------------------------------------
    // List Issues
    // -------------------------------------------------------------------------

    /**
     * Handle list_github_issues tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleListIssues( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::listIssues($parameters);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'list_github_issues');
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
    public function getListIssuesDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleListIssues',
            'description' => 'List issues from a GitHub repository. Returns issue numbers, titles, states, labels, assignees, comment counts, timestamps, and a generated_at timestamp. Use to review open issues, track progress, or find specific issues by label.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'repo'     => array(
            'type'        => 'string',
            'description' => 'Repository in owner/repo format (e.g., Extra-Chill/data-machine).',
                    ),
                    'state'    => array(
                        'type'        => 'string',
                        'description' => 'Issue state: open, closed, or all. Default: open.',
                    ),
                    'labels'   => array(
                        'type'        => 'string',
                        'description' => 'Comma-separated label names to filter by.',
                    ),
                    'per_page' => array(
                        'type'        => 'integer',
                        'description' => 'Results per page (max: 100). Default: 30.',
                    ),
            ),
            'required'   => array( 'repo' ),
            ),
            ) 
        );
    }

    // -------------------------------------------------------------------------
    // Get Issue
    // -------------------------------------------------------------------------

    /**
     * Handle get_github_issue tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleGetIssue( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::getIssue($parameters);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'get_github_issue');
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
    public function getGetIssueDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleGetIssue',
            'description' => 'Get a single GitHub issue with full details including body, labels, assignees, comment count, timestamps, generated_at, and latest comment metadata when comments exist.',
            'parameters'  => array(
            'type'       => 'object',
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
            'required'   => array( 'repo', 'issue_number' ),
            ),
            ) 
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
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleManageIssue( array $parameters, array $tool_def = array() ): array
    {
        $action = $parameters['action'] ?? '';

        if ('comment' === $action ) {
            $result = GitHubAbilities::commentOnIssue($parameters);
        } elseif ('close' === $action ) {
            $parameters['state'] = 'closed';
            $result              = GitHubAbilities::updateIssue($parameters);
        } else {
            // Default: update.
            $result = GitHubAbilities::updateIssue($parameters);
        }

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'manage_github_issue');
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
    public function getManageIssueDefinition(): array
    {
        return $this->progressDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleManageIssue',
            'description' => 'Update, close, or comment on a GitHub issue. Use action "update" to change title/body or replace the full labels set, "close" to close the issue, or "comment" to add a comment. For surgical label edits that preserve other labels, use add_label_to_issue or remove_label_from_issue instead - action=update replaces the full label set.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'repo'         => array(
            'type'        => 'string',
            'description' => 'Repository in owner/repo format.',
                    ),
                    'issue_number' => array(
                        'type'        => 'integer',
                        'description' => 'Issue number.',
                    ),
                    'action'       => array(
                        'type'        => 'string',
                        'description' => 'Action: update, close, or comment.',
                    ),
                    'title'        => array(
                        'type'        => 'string',
                        'description' => 'New issue title (update action).',
                    ),
                    'body'         => array(
                        'type'        => 'string',
                        'description' => 'New issue body (update action) or comment text (comment action).',
                    ),
                    'labels'       => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'Labels to set (update action). REPLACES the entire existing label set. For surgical add/remove that preserves other labels, use add_label_to_issue / remove_label_from_issue.',
                    ),
                    'allow_repeat_automation_comment' => array(
                        'type'        => 'boolean',
                        'description' => 'For comment action only: allow a repeated automation comment when the latest issue comment is already from this automation actor. Default: false.',
                    ),
            ),
            'required'   => array( 'repo', 'issue_number', 'action' ),
            ),
            ) 
        );
    }

    // -------------------------------------------------------------------------
    // Surgical Issue Labels
    // -------------------------------------------------------------------------

    /**
     * Handle add_label_to_issue tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleAddLabelToIssue( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::addLabels(
            array(
            'repo'         => $parameters['repo'] ?? '',
            'issue_number' => $parameters['issue_number'] ?? 0,
            'labels'       => array( $parameters['label'] ?? '' ),
            ) 
        );

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'add_label_to_issue');
        }

        return array(
        'success'   => true,
        'data'      => $result,
        'tool_name' => 'add_label_to_issue',
        );
    }

    /**
     * Get tool definition for add_label_to_issue.
     *
     * @return array
     */
    public function getAddLabelToIssueDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleAddLabelToIssue',
        'description' => 'Add a single label to an existing GitHub issue or pull request without replacing the rest of the label set. Use for surgical lifecycle transitions where preserving existing labels matters.',
        'parameters'  => array(
        'type'       => 'object',
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
                        'description' => 'Single label name to add. Existing labels are unchanged.',
                    ),
        ),
        'required'   => array( 'repo', 'issue_number', 'label' ),
        ),
        );
    }

    /**
     * Handle remove_label_from_issue tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleRemoveLabelFromIssue( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::removeLabel($parameters);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'remove_label_from_issue');
        }

        return array(
        'success'   => true,
        'data'      => $result,
        'tool_name' => 'remove_label_from_issue',
        );
    }

    /**
     * Get tool definition for remove_label_from_issue.
     *
     * @return array
     */
    public function getRemoveLabelFromIssueDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleRemoveLabelFromIssue',
        'description' => 'Remove a single label from an existing GitHub issue or pull request without touching the rest of the label set. Use for surgical lifecycle transitions where preserving other labels matters. Returns success even if the label was already absent.',
        'parameters'  => array(
        'type'       => 'object',
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
                        'description' => 'Single label name to remove. Other labels are unchanged.',
                    ),
        ),
        'required'   => array( 'repo', 'issue_number', 'label' ),
        ),
        );
    }

    // -------------------------------------------------------------------------
    // Comment Pull Request
    // -------------------------------------------------------------------------

    /**
     * Handle comment_github_pull_request tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleCommentPullRequest( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::commentOnPullRequest($parameters);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'comment_github_pull_request');
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
    public function getCommentPullRequestDefinition(): array
    {
        return $this->progressDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleCommentPullRequest',
            'description' => 'Comment on a GitHub pull request without granting broader issue update or close capabilities.',
            'parameters'  => array(
            'type'       => 'object',
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
            'required'   => array( 'repo', 'pull_number', 'body' ),
            ),
            ) 
        );
    }

    /**
     * Handle upsert_github_pull_review_comment tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleUpsertPullReviewComment( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/upsert-github-pull-review-comment', 'upsert_github_pull_review_comment', $parameters);
    }

    /**
     * Get tool definition for upsert_github_pull_review_comment.
     *
     * @return array
     */
    public function getUpsertPullReviewCommentDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleUpsertPullReviewComment',
        'description' => 'Create or update one managed bot-authored GitHub pull request review comment identified by a hidden marker.',
        'parameters'  => array(
        'type'       => 'object',
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
        'required'   => array( 'repo', 'pull_number', 'body' ),
        ),
        );
    }

    /**
     * Handle merge_github_pull_request tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleMergePullRequest( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/merge-github-pull-request', 'merge_github_pull_request', $parameters);
    }

    /**
     * Handle cleanup_github_pull_request tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleCleanupPullRequest( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/cleanup-github-pull-request', 'cleanup_github_pull_request', $parameters);
    }

    /**
     * Get tool definition for merge_github_pull_request.
     *
     * @return array
     */
    public function getMergePullRequestDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleMergePullRequest',
        'description' => 'Merge an open GitHub pull request only when its current head SHA exactly matches expected_head_sha. Defaults to squash merge.',
        'parameters'  => array(
        'type'       => 'object',
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
                        'description' => 'Exact head SHA expected immediately before merge.',
                    ),
                    'merge_method'      => array(
                        'type'        => 'string',
                        'description' => 'GitHub merge method: merge, squash, or rebase. Default: squash.',
                    ),
                    'delete_branch'     => array(
                        'type'        => 'boolean',
                        'description' => 'Delete the pull request head branch through the GitHub API after merge when the branch is in the same repository.',
                    ),
        ),
        'required'   => array( 'repo', 'pull_number', 'expected_head_sha' ),
        ),
        );
    }

    /**
     * Get tool definition for cleanup_github_pull_request.
     *
     * @return array
     */
    public function getCleanupPullRequestDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleCleanupPullRequest',
        'description' => 'Cleanup a merged pull request by finalizing the matching local DMC worktree before optional remote branch deletion. Supports dry_run previews and local_only cleanup.',
        'parameters'  => array(
        'type'       => 'object',
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
        'required'   => array( 'repo', 'pull_number' ),
        ),
        );
    }

    // -------------------------------------------------------------------------
    // List Pulls
    // -------------------------------------------------------------------------

    /**
     * Handle list_github_pulls tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleListPulls( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::listPulls($parameters);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'list_github_pulls');
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
    public function getListPullsDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleListPulls',
            'description' => 'List pull requests from a GitHub repository. Returns PR numbers, titles, states, branches, merge status, comment counts, change counts, timestamps, and generated_at.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'repo'  => array(
            'type'        => 'string',
            'description' => 'Repository in owner/repo format.',
                    ),
                    'state' => array(
                        'type'        => 'string',
                        'description' => 'PR state: open, closed, or all. Default: open.',
                    ),
            ),
            'required'   => array( 'repo' ),
            ),
            ) 
        );
    }

    // -------------------------------------------------------------------------
    // Read-only PR and source context tools.
    // -------------------------------------------------------------------------

    /**
     * Execute a registered GitHub ability for a tool call.
     *
     * @param  string $ability_name Ability slug.
     * @param  string $tool_name    Tool name for the response envelope.
     * @param  array  $parameters   Tool parameters.
     * @return array
     */
    private function executeGitHubAbility( string $ability_name, string $tool_name, array $parameters ): array
    {
        if (! function_exists('wp_get_ability') ) {
            return $this->buildErrorResponse('WordPress Abilities API is not available.', $tool_name);
        }

        $ability = wp_get_ability($ability_name);
        if (! $ability ) {
            return $this->buildErrorResponse(sprintf('GitHub ability %s is not available.', $ability_name), $tool_name);
        }

        $result = $ability->execute($parameters);
        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), $tool_name);
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
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleGetPull( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-pull', 'get_github_pull', $parameters);
    }

    /**
     * Get tool definition for get_github_pull.
     *
     * @return array
     */
    public function getGetPullDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleGetPull',
            'description' => 'Get one GitHub pull request with normalized title, body, branch, SHA, labels, merge metadata, comment counts, change counts, timestamps, and generated_at.',
            'parameters'  => array(
            'type'       => 'object',
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
            'required'   => array( 'repo', 'pull_number' ),
            ),
            ) 
        );
    }

    /**
     * Handle get_github_pull_files tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handlePullFiles( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/list-github-pull-files', 'get_github_pull_files', $parameters);
    }

    /**
     * Get tool definition for get_github_pull_files.
     *
     * @return array
     */
    public function getPullFilesDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handlePullFiles',
            'description' => 'List files changed by a GitHub pull request, including filename, status, additions, deletions, and patch when available.',
            'parameters'  => array(
            'type'       => 'object',
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
                        'description' => 'Results per page (max: 100). Default: 100.',
                    ),
                    'page'        => array(
                        'type'        => 'integer',
                        'description' => 'Page number. Default: 1.',
                    ),
            ),
            'required'   => array( 'repo', 'pull_number' ),
            ),
            ) 
        );
    }

    /**
     * Handle get_github_check_runs tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleCheckRuns( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-check-runs', 'get_github_check_runs', $parameters);
    }

    /**
     * Get tool definition for get_github_check_runs.
     *
     * @return array
     */
    public function getCheckRunsDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleCheckRuns',
        'description' => 'Get GitHub check runs for a commit SHA or ref, including aggregate state and failing check names/URLs.',
        'parameters'  => array(
        'type'       => 'object',
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
                        'description' => 'Results per page (max: 100). Default: 30.',
                    ),
                    'include_check_output' => array(
                        'type'        => 'boolean',
                        'description' => 'Include bounded check output summaries and text.',
                    ),
        ),
        'required'   => array( 'repo', 'sha' ),
        ),
        );
    }

    /**
     * Handle get_github_commit_statuses tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleCommitStatuses( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-commit-statuses', 'get_github_commit_statuses', $parameters);
    }

    /**
     * Handle get_github_actions_artifact tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleActionsArtifact( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-actions-artifact', 'get_github_actions_artifact', $parameters);
    }

    /**
     * Handle get_github_homeboy_ci_results tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleHomeboyCiResults( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-homeboy-ci-results', 'get_github_homeboy_ci_results', $parameters);
    }

    /**
     * Get tool definition for get_github_commit_statuses.
     *
     * @return array
     */
    public function getCommitStatusesDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleCommitStatuses',
        'description' => 'Get unmanaged GitHub commit statuses for a commit SHA or ref, including aggregate state and failing contexts.',
        'parameters'  => array(
        'type'       => 'object',
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
        'required'   => array( 'repo', 'sha' ),
        ),
        );
    }

    /**
     * Get tool definition for get_github_actions_artifact.
     *
     * @return array
     */
    public function getActionsArtifactDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleActionsArtifact',
        'description' => 'Download a GitHub Actions artifact by artifact name for a pull request or commit SHA and return generic artifact metadata plus parsed JSON files. Does not interpret producer-specific payload semantics.',
        'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
        'repo'               => array(
         'type'        => 'string',
         'description' => 'Repository in owner/repo format.',
                    ),
                    'head_sha'           => array(
                        'type'        => 'string',
                        'description' => 'Commit SHA to match the artifact against.',
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
        'required'   => array( 'repo', 'artifact_name' ),
        ),
        );
    }

    /**
     * Get tool definition for get_github_homeboy_ci_results.
     *
     * @return array
     */
    public function getHomeboyCiResultsDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleHomeboyCiResults',
        'description' => 'Download and summarize the Homeboy CI results artifact for a pull request or commit SHA. Uses GitHub Actions artifacts, preferring review.json and falling back to audit/lint/test JSON files.',
        'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
        'repo'               => array(
         'type'        => 'string',
         'description' => 'Repository in owner/repo format.',
                    ),
                    'head_sha'           => array(
                        'type'        => 'string',
                        'description' => 'Commit SHA to match the Homeboy artifact against.',
                    ),
                    'pull_number'        => array(
                        'type'        => 'integer',
                        'description' => 'Pull request number. Used to resolve head_sha when head_sha is omitted.',
                    ),
                    'artifact_name'      => array(
                        'type'        => 'string',
                        'description' => 'GitHub Actions artifact name. Default: homeboy-ci-results.',
                    ),
                    'max_artifact_bytes' => array(
                        'type'        => 'integer',
                        'description' => 'Maximum artifact ZIP bytes to download. Default: 2000000.',
                    ),
                    'include_raw'        => array(
                        'type'        => 'boolean',
                        'description' => 'Include bounded raw parsed JSON payloads.',
                    ),
        ),
        'required'   => array( 'repo' ),
        ),
        );
    }

    /**
     * Handle get_github_pull_review_context tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handlePullReviewContext( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-pull-review-context', 'get_github_pull_review_context', $parameters);
    }

    /**
     * Handle run_pr_homeboy_review tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleRunPrHomeboyReview( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine-code/run-pr-homeboy-review', 'run_pr_homeboy_review', $parameters);
    }

    /**
     * Get tool definition for get_github_pull_review_context.
     *
     * @return array
     */
    public function getPullReviewContextDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handlePullReviewContext',
            'description' => 'Build a review-ready context packet for a GitHub pull request, including normalized PR metadata and changed-file patches.',
            'parameters'  => array(
            'type'       => 'object',
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
                        'description' => 'Optional expected pull request head SHA. Returns an error if GitHub reports a different head SHA.',
                    ),
                    'max_patch_chars'         => array(
                        'type'        => 'integer',
                        'description' => 'Maximum cumulative patch characters to include. Default: 200000.',
                    ),
                    'include_file_contents'   => array(
                        'type'        => 'boolean',
                        'description' => 'Opt in to bounded full-file contents for changed files.',
                    ),
                    'include_base_contents'   => array(
                        'type'        => 'boolean',
                        'description' => 'When changed file contents are enabled, also include bounded base-branch contents for comparison.',
                    ),
                    'context_paths'           => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'Additional repository paths to include from the PR head ref.',
                    ),
                    'max_file_content_chars'  => array(
                        'type'        => 'integer',
                        'description' => 'Maximum characters included per expanded file content block. Default: 20000.',
                    ),
                    'max_context_files'       => array(
                        'type'        => 'integer',
                        'description' => 'Maximum number of files included in expanded PR review context. Default: 10.',
                    ),
                    'max_total_context_chars' => array(
                        'type'        => 'integer',
                        'description' => 'Maximum cumulative characters included across expanded PR review context files. Default: 100000.',
                    ),
                    'include_checks'          => array(
                        'type'        => 'boolean',
                        'description' => 'Include GitHub check runs for the PR head SHA.',
                    ),
                    'include_statuses'        => array(
                        'type'        => 'boolean',
                        'description' => 'Include classic commit statuses for the PR head SHA.',
                    ),
                    'max_check_runs'          => array(
                        'type'        => 'integer',
                        'description' => 'Maximum check runs to include. Default: 30.',
                    ),
                    'include_check_output'    => array(
                        'type'        => 'boolean',
                        'description' => 'Include bounded check output summaries and text.',
                    ),
                    'include_homeboy_ci'      => array(
                        'type'        => 'boolean',
                        'description' => 'Include parsed Homeboy CI result artifact data for the PR head SHA.',
                    ),
                    'artifact_name'           => array(
                        'type'        => 'string',
                        'description' => 'GitHub Actions artifact name for Homeboy CI results. Default: homeboy-ci-results.',
                    ),
            ),
            'required'   => array( 'repo', 'pull_number' ),
            ),
            ) 
        );
    }

    /**
     * Handle github_repo_review_profile tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleRepoReviewProfile( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-repo-review-profile', 'github_repo_review_profile', $parameters);
    }

    /**
     * Get tool definition for github_repo_review_profile.
     *
     * @return array
     */
    public function getRepoReviewProfileDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleRepoReviewProfile',
        'description' => 'Build bounded repository-level review context from AGENTS.md, README, contributing docs, Homeboy config, and small architecture/development docs. Use before reviewing to learn repo-specific rules and conventions.',
        'parameters'  => array(
        'type'       => 'object',
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
        'required'   => array( 'repo' ),
        ),
        );
    }

    /**
     * Get tool definition for run_pr_homeboy_review.
     *
     * @return array
     */
    public function getRunPrHomeboyReviewDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handleRunPrHomeboyReview',
        'description' => 'Run checkout-backed Homeboy review checks for a pull request in an isolated DMC workspace worktree. Does not post comments or mutate the pull request.',
        'parameters'  => array(
        'type'       => 'object',
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
                        'description' => 'Expected pull request head SHA. Execution fails closed if GitHub reports a different head.',
                    ),
                    'base_ref'    => array(
                        'type'        => 'string',
                        'description' => 'Optional base ref. Defaults to the pull request base ref.',
                    ),
        ),
        'required'   => array( 'repo', 'pull_number', 'head_sha' ),
        ),
        );
    }

    /**
     * Handle github_pr_documentation_impact tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handlePullDocumentationImpact( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-pr-documentation-impact', 'github_pr_documentation_impact', $parameters);
    }

    /**
     * Get tool definition for github_pr_documentation_impact.
     *
     * @return array
     */
    public function getPullDocumentationImpactDefinition(): array
    {
        return array(
        'class'       => __CLASS__,
        'method'      => 'handlePullDocumentationImpact',
        'description' => 'Build a heuristic documentation-impact packet for a GitHub pull request. Use this before documentation/content freshness workflows to identify changed command, ability/tool, REST/webhook, settings, and public PHP surfaces with evidence.',
        'parameters'  => array(
        'type'       => 'object',
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
                        'description' => 'Optional expected pull request head SHA. Returns an error if GitHub reports a different head SHA.',
                    ),
                    'base_ref'    => array(
                        'type'        => 'string',
                        'description' => 'Optional base ref used for docs tree lookup. Defaults to the PR base ref.',
                    ),
                    'docs_paths'  => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'Optional documentation path allow-list used when suggesting likely stale docs.',
                    ),
        ),
        'required'   => array( 'repo', 'pull_number' ),
        ),
        );
    }

    /**
     * Handle list_github_tree tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleListTree( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/list-github-tree', 'list_github_tree', $parameters);
    }

    /**
     * Get tool definition for list_github_tree.
     *
     * @return array
     */
    public function getListTreeDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleListTree',
            'description' => 'List files in a GitHub repository tree at a branch, tag, or commit SHA. Optionally filter to a path prefix.',
            'parameters'  => array(
            'type'       => 'object',
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
            'required'   => array( 'repo' ),
            ),
            ) 
        );
    }

    /**
     * Handle get_github_file tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleGetFile( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/get-github-file', 'get_github_file', $parameters);
    }

    /**
     * Get tool definition for get_github_file.
     *
     * @return array
     */
    public function getGetFileDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleGetFile',
            'description' => 'Get decoded content for one or more files from a GitHub repository. Accepts path for one file or paths for one-or-many; always returns files[].',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'repo' => array(
            'type'        => 'string',
            'description' => 'Repository in owner/repo format.',
                    ),
                    'path' => array(
                        'type'        => 'string',
                        'description' => 'Single file path within the repository. Use paths for multiple files.',
                    ),
                    'paths' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'One or more file paths within the repository.',
                    ),
                    'ref'  => array(
                        'type'        => 'string',
                        'description' => 'Branch, tag, or commit SHA. Defaults to the repository default branch.',
                    ),
                    'max_total_size' => array(
                        'type'        => 'integer',
                        'description' => 'Maximum cumulative decoded bytes to return across files. Default: 500000.',
                    ),
            ),
            'required'   => array( 'repo' ),
            ),
            ) 
        );
    }

    /**
     * Handle create_or_update_github_file tool call.
     *
     * @param  array $parameters Tool parameters.
     * @return array
     */
    public function handleCreateOrUpdateFile( array $parameters ): array
    {
        return $this->executeGitHubAbility('datamachine/create-or-update-github-file', 'create_or_update_github_file', $parameters);
    }

    /**
     * Get tool definition for create_or_update_github_file.
     *
     * @return array
     */
    public function getCreateOrUpdateFileDefinition(): array
    {
        return $this->progressDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleCreateOrUpdateFile',
            'description' => 'Create or update a file in a GitHub repository using the Contents API. If branch is provided and does not exist, it is created from the repository default branch before committing.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'repo'               => array(
            'type'        => 'string',
            'description' => 'Repository in owner/repo format.',
                    ),
                    'file_path'          => array(
                        'type'        => 'string',
                        'description' => 'Path within the repository.',
                    ),
                    'content'            => array(
                        'type'        => 'string',
                        'description' => 'Full file content to write.',
                    ),
                    'commit_message'     => array(
                        'type'        => 'string',
                        'description' => 'Commit message for the file change.',
                    ),
                    'branch'             => array(
                        'type'        => 'string',
                        'description' => 'Target branch. Defaults to the repository default branch. New branches are created from the default branch.',
                    ),
                    'allowed_file_paths' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => 'Optional allowlist of writable file paths or glob-like patterns, such as README.md or docs/**.',
                    ),
            ),
            'required'   => array( 'repo', 'file_path', 'content', 'commit_message' ),
            ),
            ) 
        );
    }

    // -------------------------------------------------------------------------
    // List Repos
    // -------------------------------------------------------------------------

    /**
     * Handle list_github_repos tool call.
     *
     * @param  array $parameters Tool parameters.
     * @param  array $tool_def   Tool definition.
     * @return array
     */
    public function handleListRepos( array $parameters, array $tool_def = array() ): array
    {
        $result = GitHubAbilities::listRepos($parameters);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), 'list_github_repos');
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
    public function getListReposDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleListRepos',
            'description' => 'List GitHub repositories for a user or organization. Shows repo names, languages, stars, open issues, and last push date.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'owner' => array(
            'type'        => 'string',
            'description' => 'GitHub user or organization name.',
                    ),
                    'sort'  => array(
                        'type'        => 'string',
                        'description' => 'Sort by: created, updated, pushed, full_name. Default: updated.',
                    ),
            ),
            'required'   => array( 'owner' ),
            ),
            ) 
        );
    }

    /**
     * @param array<string,mixed> $definition Tool definition. @return array<string,mixed> 
     */
    private function repeatableDefinition( array $definition ): array
    {
        return $this->withRuntime($definition, array( 'duplicate_policy' => 'repeatable' ));
    }

    /**
     * @param array<string,mixed> $definition Tool definition. @return array<string,mixed> 
     */
    private function progressDefinition( array $definition ): array
    {
        return $this->withRuntime($definition, array( 'completion_signal' => 'progress' ));
    }

    /**
     * @param array<string,mixed> $definition Tool definition. @param array<string,mixed> $runtime Runtime metadata. @return array<string,mixed> 
     */
    private function withRuntime( array $definition, array $runtime ): array
    {
        $definition['runtime'] = array_merge(is_array($definition['runtime'] ?? null) ? $definition['runtime'] : array(), $runtime);
        return $definition;
    }
}
