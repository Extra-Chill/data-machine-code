<?php
/**
 * Smoke test for workspace tool pipeline policy exposure.
 *
 * Run: php tests/smoke-workspace-policy-tools.php
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    $GLOBALS['dmc_workspace_policy_filters'] = array();

    function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void
    {
        $GLOBALS['dmc_workspace_policy_filters'][ $tag ][ $priority ][] = $callback;
    }

    function apply_filters( string $tag, $value )
    {
        if (empty($GLOBALS['dmc_workspace_policy_filters'][ $tag ]) ) {
            return $value;
        }

        ksort($GLOBALS['dmc_workspace_policy_filters'][ $tag ]);
        foreach ( $GLOBALS['dmc_workspace_policy_filters'][ $tag ] as $callbacks ) {
            foreach ( $callbacks as $callback ) {
                $value = $callback($value);
            }
        }

        return $value;
    }
}

namespace DataMachine\Engine\AI\Tools {
    class BaseTool
    {
        /**
         * @param array<int,string> $contexts Context names. @param array<string,mixed> $options Tool options. 
         */
        protected function registerTool( string $tool_id, callable $definition_callback, array $contexts = array(), array $options = array() ): void
        {
            \add_filter(
                'datamachine_tools',
                static function ( array $tools ) use ( $tool_id, $definition_callback, $contexts, $options ): array {
                    $tools[ $tool_id ] = array(
                    '_callable' => $definition_callback,
                    'modes'     => $contexts,
                    ) + $options;

                    return $tools;
                }
            );
        }
    }
}

namespace {
    include __DIR__ . '/../inc/Tools/WorkspaceTools.php';

    $failures = array();
    $passes   = 0;
    $assert   = function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
        if ($condition ) {
            ++$passes;
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    echo "Workspace policy tools - smoke\n";

    $workspace_tools = new \DataMachineCode\Tools\WorkspaceTools();
    $tools = apply_filters('datamachine_tools', array());

    $default_pipeline_tools = array(
    'workspace_path',
    'workspace_capabilities',
    'workspace_list',
    'workspace_show',
    'workspace_ls',
    'workspace_read',
    'workspace_grep',
    );

    foreach ( $default_pipeline_tools as $tool ) {
        $assert("{$tool} remains default pipeline-visible", array( 'chat', 'pipeline' ) === ( $tools[ $tool ]['modes'] ?? null ));
    }

    $show_definition = $workspace_tools->getShowDefinition();
    $assert('workspace_show does not allow duplicate repeat calls', 'repeatable' !== ( $show_definition['runtime']['duplicate_policy'] ?? null ));

    $ls_definition = $workspace_tools->getLsDefinition();
    $assert('workspace_ls still allows intentional repeat calls', 'repeatable' === ( $ls_definition['runtime']['duplicate_policy'] ?? null ));

    $policy_tools = array(
    'workspace_write',
    'workspace_edit',
    'workspace_apply_patch',
    'workspace_delete',
    'workspace_git_status',
    'workspace_git_log',
    'workspace_git_diff',
    'workspace_git_pull',
    'workspace_worktree_add',
    'workspace_git_add',
    'workspace_git_commit',
    'workspace_git_push',
    'workspace_git_rebase',
    'workspace_git_reset',
    'workspace_pr_status',
    'workspace_pr_rebase',
    );

    foreach ( $policy_tools as $tool ) {
        $assert("{$tool} is available in real chat and pipeline modes", array( 'chat', 'pipeline' ) === ( $tools[ $tool ]['modes'] ?? null ));
        $assert("{$tool} requires explicit opt-in for pipeline use", true === ( $tools[ $tool ]['requires_opt_in'] ?? null ));
    }

    if ($failures ) {
        echo "\nFAIL: " . count($failures) . " assertion(s) failed\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK ({$passes} assertions)\n";
}
