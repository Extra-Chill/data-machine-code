<?php
/**
 * GitHub Pull Request Tool — AI agent wrapper for create-github-pull-request.
 *
 * Provides the AI-callable tool interface for opening GitHub pull requests.
 *
 * @package DataMachineCode\Tools
 * @since   0.30.1
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;

defined('ABSPATH') || exit;

class GitHubPullRequestTool extends BaseTool
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->registerTool('create_github_pull_request', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline' ), array( 'ability' => 'datamachine/create-github-pull-request' ));
    }

    /**
     * Execute GitHub pull request creation by delegating to the ability.
     *
     * @param  array $parameters Contains ability-shaped pull request parameters.
     * @param  array $tool_def   Tool definition (unused).
     * @return array Tool response.
     */
    public function handle_tool_call( array $parameters, array $tool_def = array() ): array
    {
        $ability = wp_get_ability('datamachine/create-github-pull-request');

        if (! $ability ) {
            return $this->buildErrorResponse(
                'GitHub pull request creation ability not registered. Ensure WordPress 6.9+ is active.',
                'create_github_pull_request'
            );
        }

        $input = array(
        'repo'                  => $parameters['repo'] ?? '',
        'title'                 => $parameters['title'] ?? '',
        'head'                  => $parameters['head'] ?? '',
        'base'                  => $parameters['base'] ?? '',
        'body'                  => $parameters['body'] ?? '',
        'labels'                => isset($parameters['labels']) && is_array($parameters['labels']) ? $parameters['labels'] : array(),
        'draft'                 => $parameters['draft'] ?? false,
        'maintainer_can_modify' => $parameters['maintainer_can_modify'] ?? true,
        );

        foreach ( array( 'job_id', 'engine', 'run_artifacts', 'run_artifact_egress_policy', 'bundle_root' ) as $context_key ) {
            if (array_key_exists($context_key, $parameters) ) {
                $input[ $context_key ] = $parameters[ $context_key ];
            }
        }

        $result = $ability->execute($input);

        if (is_wp_error($result) ) {
            return $this->buildErrorResponse(
                $result->get_error_message(),
                'create_github_pull_request'
            );
        }

        if (is_array($result) && ! empty($result['success']) ) {
            $result['tool_name'] = 'create_github_pull_request';
            return $result;
        }

        if (is_array($result) && ! empty($result['error']) ) {
            return $this->buildErrorResponse(
                $result['error'],
                'create_github_pull_request'
            );
        }

        return $this->buildErrorResponse(
            'Failed to create GitHub pull request.',
            'create_github_pull_request'
        );
    }

    /**
     * Get tool definition for AI agents.
     *
     * @return array Tool definition array.
     */
    public function getToolDefinition(): array
    {
        $description = 'Create a GitHub pull request in a repository. Requires a GitHub PAT or App credential configured in settings.';

        $repos = \DataMachineCode\Abilities\GitHubAbilities::getRegisteredRepos();
        if (! empty($repos) ) {
            $repo_list    = array_map(
                function ( $r ) {
                    return $r['owner'] . '/' . $r['repo'] . ' (' . $r['label'] . ')';
                },
                $repos
            );
            $description .= ' Available repos: ' . implode(', ', $repo_list) . '.';
        }

        return array(
        'class'       => __CLASS__,
        'method'      => 'handle_tool_call',
        'description' => $description,
        'runtime'     => array(
        'completion_signal' => 'progress',
        ),
        'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
                    'repo'                  => array(
                        'type'        => 'string',
                        'description' => 'Repository in owner/repo format.',
        ),
        'title'                 => array(
         'type'        => 'string',
         'description' => 'Pull request title.',
        ),
        'head'                  => array(
         'type'        => 'string',
         'description' => 'Branch where changes are implemented. Use owner:branch for cross-fork PRs.',
        ),
        'base'                  => array(
         'type'        => 'string',
         'description' => 'Branch to merge into. Defaults to the repository default branch.',
        ),
        'body'                  => array(
         'type'        => 'string',
         'description' => 'Pull request description. Supports GitHub Markdown.',
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
        'required'   => array( 'repo', 'title', 'head' ),
        ),
        );
    }
}
