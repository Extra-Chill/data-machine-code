<?php
/**
 * Workspace diff AI tools.
 *
 * @package DataMachineCode\Tools
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Workspace\WorkspaceAliasResolver;

defined('ABSPATH') || exit;

class WorkspaceDiffTools extends BaseTool
{

    public function __construct()
    {
        $contexts = array( 'chat', 'pipeline' );
        $this->registerTool('workspace_diff_summary', array( $this, 'getDiffSummaryDefinition' ), $contexts, array( 'ability' => 'datamachine-code/workspace-diff-summary' ));
        $this->registerTool('workspace_diff_validate', array( $this, 'getDiffValidateDefinition' ), $contexts, array( 'ability' => 'datamachine-code/workspace-diff-validate' ));
    }

    public static function is_configured(): bool
    {
        return (bool) wp_get_ability('datamachine-code/workspace-diff-summary');
    }

    public function check_configuration( $configured, $tool_id )
    {
        $workspace_diff_tools = array( 'workspace_diff_summary', 'workspace_diff_validate' );
        if (! in_array($tool_id, $workspace_diff_tools, true) ) {
            return $configured;
        }

        return self::is_configured();
    }

    /**
     * @param array<string,mixed> $parameters Tool parameters. @param array<string,mixed> $tool_def Tool definition. @return array<string,mixed> 
     */
    public function handle_tool_call( array $parameters, array $tool_def = array() ): array
    {
        $method = $tool_def['method'] ?? '';
        if (! method_exists($this, $method) ) {
            return $this->buildErrorResponse("Unknown workspace diff tool method: {$method}", 'workspace_diff');
        }

        return $this->{$method}($parameters);
    }

    /**
     * @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> 
     */
    public function handleDiffSummary( array $parameters ): array
    {
        return $this->executeDiffAbility('datamachine-code/workspace-diff-summary', 'workspace_diff_summary', $parameters);
    }

    /**
     * @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> 
     */
    public function handleDiffValidate( array $parameters ): array
    {
        return $this->executeDiffAbility('datamachine-code/workspace-diff-validate', 'workspace_diff_validate', $parameters);
    }

    /**
     * @return array<string,mixed> 
     */
    public function getToolDefinition(): array
    {
        return $this->getDiffSummaryDefinition();
    }

    /**
     * @return array<string,mixed> 
     */
    public function getDiffSummaryDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleDiffSummary',
            'description' => 'Summarize changed files, additions/deletions, test-touch status, and compact git diff metadata for a workspace handle.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'name'   => array( 'type' => 'string', 'description' => 'Workspace repository directory name or worktree handle.' ),
            'from'   => array( 'type' => 'string', 'description' => 'Optional from git ref.' ),
            'to'     => array( 'type' => 'string', 'description' => 'Optional to git ref.' ),
            'staged' => array( 'type' => 'boolean', 'description' => 'Summarize staged changes only.' ),
            'path'   => array( 'type' => 'string', 'description' => 'Optional relative path filter.' ),
            ),
            'required'   => array( 'name' ),
            ),
            ) 
        );
    }

    /**
     * @return array<string,mixed> 
     */
    public function getDiffValidateDefinition(): array
    {
        return $this->repeatableDefinition(
            array(
            'class'       => __CLASS__,
            'method'      => 'handleDiffValidate',
            'description' => 'Validate workspace diff shape using allowed/denied path patterns and optional test-change requirements.',
            'parameters'  => array(
            'type'       => 'object',
            'properties' => array(
            'name'                  => array( 'type' => 'string', 'description' => 'Workspace repository directory name or worktree handle.' ),
            'allow'                 => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns every changed file must match.' ),
            'deny'                  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns that must not be changed.' ),
            'include_any'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns where at least one changed file must match.' ),
            'include_all'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns where each pattern must match at least one changed file.' ),
            'require_tests'         => array( 'type' => 'boolean', 'description' => 'Require at least one changed file that looks like test coverage.' ),
            'require_changed_files' => array(
            'type'        => 'object',
            'description' => 'Compatibility object supporting allow, deny, include_any, and include_all.',
            'properties'  => array(
            'allow'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns every changed file must match.' ),
            'deny'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns that must not be changed.' ),
            'include_any' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns where at least one changed file must match.' ),
            'include_all' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional path patterns where each pattern must match at least one changed file.' ),
            ),
                    ),
                    'from'                  => array( 'type' => 'string', 'description' => 'Optional from git ref.' ),
                    'to'                    => array( 'type' => 'string', 'description' => 'Optional to git ref.' ),
                    'staged'                => array( 'type' => 'boolean', 'description' => 'Validate staged changes only.' ),
                    'path'                  => array( 'type' => 'string', 'description' => 'Optional relative path filter.' ),
            ),
            'required'   => array( 'name' ),
            ),
            ) 
        );
    }

    /**
     * @param array<string,mixed> $definition Tool definition. @return array<string,mixed> 
     */
    private function repeatableDefinition( array $definition ): array
    {
        $definition['runtime'] = array_merge(is_array($definition['runtime'] ?? null) ? $definition['runtime'] : array(), array( 'duplicate_policy' => 'repeatable' ));
        return $definition;
    }

    /**
     * @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> 
     */
    private function normalizeInput( array $parameters ): array
    {
        $input = array( 'name' => $parameters['name'] ?? $parameters['repo'] ?? '' );
        foreach ( array( 'from', 'to', 'path', 'allow', 'deny', 'include_any', 'include_all', 'require_changed_files' ) as $key ) {
            if (array_key_exists($key, $parameters) ) {
                $input[ $key ] = $parameters[ $key ];
            }
        }
        foreach ( array( 'staged', 'require_tests' ) as $key ) {
            if (array_key_exists($key, $parameters) ) {
                $input[ $key ] = (bool) $parameters[ $key ];
            }
        }

        if (is_string($input['name']) && WorkspaceAliasResolver::has_alias($input['name']) ) {
            $alias = $input['name'];
            $spec  = WorkspaceAliasResolver::spec($alias);
            if (null !== $spec ) {
                $input['name']              = $spec['target'];
                $input['_workspace_alias']  = $alias;
                $input['_workspace_handle'] = $input['name'];
                $input['_workspace_root']   = $spec['root'];

                if ('' !== $spec['root'] ) {
                    $scoped_path = WorkspaceAliasResolver::scope_path((string) ( $input['path'] ?? '' ), $spec['root']);
                    if (false === $scoped_path ) {
                        $input['_workspace_alias_error'] = 'Path is outside the scoped workspace.';
                    } else {
                        $input['path'] = $scoped_path;
                    }
                }
            }
        }

        return $input;
    }

    /**
     * @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> 
     */
    private function executeDiffAbility( string $ability_name, string $tool_name, array $parameters ): array
    {
        $ability = wp_get_ability($ability_name);
        if (! $ability ) {
            return $this->buildErrorResponse("{$tool_name} ability not available.", $tool_name);
        }

        $input  = $this->normalizeInput($parameters);
        if (isset($input['_workspace_alias_error']) ) {
            return $this->buildErrorResponse((string) $input['_workspace_alias_error'], $tool_name);
        }
        $result = $ability->execute($input);
        if (is_wp_error($result) ) {
            return $this->buildErrorResponse($result->get_error_message(), $tool_name);
        }

        $alias  = isset($input['_workspace_alias']) ? (string) $input['_workspace_alias'] : '';
        $handle = isset($input['_workspace_handle']) ? (string) $input['_workspace_handle'] : '';
        $root   = isset($input['_workspace_root']) ? (string) $input['_workspace_root'] : '';
        if ('' !== $alias && '' !== $handle ) {
            $result = WorkspaceAliasResolver::sanitize_result($result, $alias, $handle, $root);
        }

        return array(
        'success'   => true,
        'data'      => $result,
        'tool_name' => $tool_name,
        );
    }
}
