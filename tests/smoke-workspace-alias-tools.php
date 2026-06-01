<?php
/**
 * Smoke test for agent-facing workspace aliases.
 *
 * Run: php tests/smoke-workspace-alias-tools.php
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }
    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', '/workspace');
    }

    $GLOBALS['dmc_workspace_alias_filters'] = array();
    $GLOBALS['dmc_workspace_alias_ability'] = null;
    $GLOBALS['dmc_workspace_alias_registered_abilities'] = array();
    $GLOBALS['dmc_workspace_alias_remote_edit_input'] = array();

    function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void
    {
        $GLOBALS['dmc_workspace_alias_filters'][ $tag ][ $priority ][] = $callback;
    }

    function apply_filters( string $tag, $value )
    {
        if (empty($GLOBALS['dmc_workspace_alias_filters'][ $tag ]) ) {
            return $value;
        }

        ksort($GLOBALS['dmc_workspace_alias_filters'][ $tag ]);
        foreach ( $GLOBALS['dmc_workspace_alias_filters'][ $tag ] as $callbacks ) {
            foreach ( $callbacks as $callback ) {
                $value = $callback($value);
            }
        }

        return $value;
    }

    function get_option( string $name, $default = false )
    {
        return $default;
    }

    function wp_get_ability( string $name )
    {
        return $GLOBALS['dmc_workspace_alias_ability'];
    }

    function wp_register_ability( string $name, array $definition ): void
    {
        $GLOBALS['dmc_workspace_alias_registered_abilities'][ $name ] = $definition;
    }

    function add_action( string $hook, callable $callback, int $priority = 10 ): void
    {
    }

    function doing_action( string $hook ): bool
    {
        return 'wp_abilities_api_init' === $hook;
    }

    function is_wp_error( $value ): bool
    {
        return $value instanceof WP_Error;
    }

    class WP_Error
    {
        private string $code;
        private string $message;

        public function __construct( string $code, string $message, array $data = array() )
        {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

namespace DataMachine\Abilities {
    class PermissionHelper
    {
        public static function can_manage(): bool
        {
            return true;
        }
    }
}

namespace DataMachineCode\Workspace {
    class Workspace
    {
        public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
        public const MAX_READ_SIZE = 1048576;
    }

    class RemoteWorkspaceBackend
    {
        public static function should_handle(): bool
        {
            return true;
        }

        public function edit_file( string $handle, string $path, string $old_string, string $new_string, bool $replace_all = false ): array
        {
            $GLOBALS['dmc_workspace_alias_remote_edit_input'] = compact('handle', 'path', 'old_string', 'new_string', 'replace_all');
            return array(
            'success'      => true,
            'name'         => $handle,
            'path'         => $path,
            'replacements' => 1,
            );
        }
    }
}

namespace DataMachine\Engine\AI\Tools {
    class BaseTool
    {
        protected function registerTool( string $tool_id, callable $definition_callback, array $contexts = array(), array $options = array() ): void
        {
        }
        protected function buildErrorResponse( string $message, string $tool_name ): array
        {
            return array(
            'success'   => false,
            'error'     => $message,
            'tool_name' => $tool_name,
            );
        }
    }
}

namespace {
    include __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';
    include __DIR__ . '/../inc/Abilities/WorkspaceAbilities.php';
    include __DIR__ . '/../inc/Tools/WorkspaceTools.php';
    include __DIR__ . '/../inc/Tools/WorkspaceDiffTools.php';

    class DataMachineCodeWorkspaceAliasFakeAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            return array(
            'success'            => true,
            'name'               => 'wp-rl@agent-runs-modern-api-openai-gpt-5-5',
            'repo'               => 'wp-rl',
            'path'               => '.agent-workspace/current-project/plugins/demo.php',
            'files'              => array( '.agent-workspace/current-project/plugins/demo.php' ),
            'diff'               => "diff --git a/.agent-workspace/current-project/plugins/demo.php b/.agent-workspace/current-project/plugins/demo.php\n--- a/.agent-workspace/current-project/plugins/demo.php\n+++ b/.agent-workspace/current-project/plugins/demo.php\n",
            'branch'             => 'agent-runs-modern-api-openai-gpt-5-5',
            'conversation_state' => 'incomplete',
            'next_required_args' => array( 'name' => 'wp-rl@agent-runs-modern-api-openai-gpt-5-5' ),
            'content'            => 'The real handle wp-rl@agent-runs-modern-api-openai-gpt-5-5 and .agent-workspace/current-project may appear in file content and must not be rewritten.',
            );
        }
    }

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

    echo "Workspace alias tools - smoke\n";

    add_filter(
        'datamachine_code_workspace_aliases',
        fn( array $aliases ): array => array_merge(
            $aliases,
            array(
                    'current-project' => array(
                        'target' => 'wp-rl@agent-runs-modern-api-openai-gpt-5-5',
                        'root'   => '.agent-workspace/current-project',
                    ),
                )
        )
    );

    $ability                                  = new DataMachineCodeWorkspaceAliasFakeAbility();
    $GLOBALS['dmc_workspace_alias_ability'] = $ability;
    $tools                                    = new \DataMachineCode\Tools\WorkspaceTools();
    new \DataMachineCode\Abilities\WorkspaceAbilities();
    $read_definition                          = $tools->getReadDefinition();
    $grep_definition                          = $tools->getGrepDefinition();
    $edit_definition                          = $tools->getEditDefinition();
    $git_diff_definition                      = $tools->getGitDiffDefinition();
    $ability_edit_schema                       = $GLOBALS['dmc_workspace_alias_registered_abilities']['datamachine/workspace-edit']['input_schema'] ?? array();
    $assert('workspace_read schema allows path-only mounted workspace calls', array( 'path' ) === ( $read_definition['parameters']['required'] ?? null ));
    $assert('workspace_grep schema allows path-only mounted workspace calls', array( 'pattern' ) === ( $grep_definition['parameters']['required'] ?? null ));
    $assert('workspace_git_diff schema allows path-only mounted workspace calls', array() === ( $git_diff_definition['parameters']['required'] ?? null ));
    $assert('workspace_edit wrapper schema keeps edit text optional for aliases', array( 'path' ) === ( $edit_definition['parameters']['required'] ?? null ));
    $assert('workspace_edit ability schema keeps edit text optional for aliases', array( 'path' ) === ( $ability_edit_schema['required'] ?? null ));
    foreach ( array( 'old_string', 'new_string', 'search', 'replace', 'old', 'new' ) as $property ) {
        $assert("workspace_edit wrapper schema exposes {$property}", isset($edit_definition['parameters']['properties'][ $property ]));
        $assert("workspace_edit ability schema exposes {$property}", isset($ability_edit_schema['properties'][ $property ]));
    }
    $assert('workspace_edit schema does not expose broad find alias', ! isset($edit_definition['parameters']['properties']['find']) && ! isset($ability_edit_schema['properties']['find']));

    $absolute_read                           = $tools->handleRead(array( 'path' => '/workspace/homeboy-extensions/wordpress/scripts/build/build.sh' ));
    $assert('absolute workspace path read succeeds', true === ( $absolute_read['success'] ?? false ));
    $assert('absolute workspace path infers repo', 'homeboy-extensions' === ( $ability->last_input['repo'] ?? '' ));
    $assert('absolute workspace path becomes relative path', 'wordpress/scripts/build/build.sh' === ( $ability->last_input['path'] ?? '' ));

    $absolute_repo_read = $tools->handleRead(array( 'repo' => '/workspace/homeboy-extensions', 'path' => 'wordpress/scripts/build/build.sh' ));
    $assert('absolute workspace repo read succeeds', true === ( $absolute_repo_read['success'] ?? false ));
    $assert('absolute workspace repo normalizes to handle', 'homeboy-extensions' === ( $ability->last_input['repo'] ?? '' ));
    $assert('absolute workspace repo preserves relative path', 'wordpress/scripts/build/build.sh' === ( $ability->last_input['path'] ?? '' ));

	$absolute_escape = $tools->handleRead(array( 'path' => '/tmp/homeboy-extensions/README.md' ));
	$assert('absolute path outside workspace root is rejected', false === ( $absolute_escape['success'] ?? true ));

	$edit_alias = $tools->handleEdit(
		array(
			'repo'    => 'homeboy-extensions',
			'path'    => 'wordpress/scripts/build/build.sh',
			'search'  => 'npm install --silent',
			'replace' => 'npm install --legacy-peer-deps',
		)
	);
	$assert('workspace_edit accepts search/replace aliases', true === ( $edit_alias['success'] ?? false ));
	$assert('workspace_edit maps search to old_string', 'npm install --silent' === ( $ability->last_input['old_string'] ?? '' ));
	$assert('workspace_edit maps replace to new_string', 'npm install --legacy-peer-deps' === ( $ability->last_input['new_string'] ?? '' ));

	$edit_short_alias = $tools->handleEdit(
		array(
			'repo' => 'homeboy-extensions',
			'path' => 'wordpress/scripts/build/build.sh',
			'old'  => 'npm install --silent',
			'new'  => 'npm install --legacy-peer-deps',
		)
	);
	$assert('workspace_edit accepts old/new aliases', true === ( $edit_short_alias['success'] ?? false ));
	$assert('workspace_edit maps old to old_string', 'npm install --silent' === ( $ability->last_input['old_string'] ?? '' ));
	$assert('workspace_edit maps new to new_string', 'npm install --legacy-peer-deps' === ( $ability->last_input['new_string'] ?? '' ));

    $ability_mounted_edit = \DataMachineCode\Abilities\WorkspaceAbilities::editFile(
        array(
            'path'    => '/workspace/homeboy-extensions/wordpress/scripts/build/build.sh',
            'old'     => 'npm install --silent',
            'new'     => 'npm install --legacy-peer-deps',
        )
    );
    $assert('workspace_edit ability accepts mounted absolute path', ! is_wp_error($ability_mounted_edit) && true === ( $ability_mounted_edit['success'] ?? false ));
    $assert('workspace_edit ability infers repo from mounted path', 'homeboy-extensions' === ( $GLOBALS['dmc_workspace_alias_remote_edit_input']['handle'] ?? '' ));
    $assert('workspace_edit ability converts mounted path to relative path', 'wordpress/scripts/build/build.sh' === ( $GLOBALS['dmc_workspace_alias_remote_edit_input']['path'] ?? '' ));
    $assert('workspace_edit ability maps old alias to old_string', 'npm install --silent' === ( $GLOBALS['dmc_workspace_alias_remote_edit_input']['old_string'] ?? '' ));
    $assert('workspace_edit ability maps new alias to new_string', 'npm install --legacy-peer-deps' === ( $GLOBALS['dmc_workspace_alias_remote_edit_input']['new_string'] ?? '' ));

    $unsupported_alias = \DataMachineCode\Abilities\WorkspaceAbilities::editFile(
        array(
            'repo'    => 'homeboy-extensions',
            'path'    => 'wordpress/scripts/build/build.sh',
            'find'    => 'npm install --silent',
            'replace' => 'npm install --legacy-peer-deps',
        )
    );
    $assert('unsupported edit aliases fail at ability layer', is_wp_error($unsupported_alias));
    $assert('unsupported edit alias error is actionable', is_wp_error($unsupported_alias) && 'old_string is required.' === $unsupported_alias->get_error_message());

	$result                                   = $tools->handleGitStatus(array( 'name' => 'current-project' ));
    $data                                     = $result['data'] ?? array();

    $assert('alias resolves before ability execution', 'wp-rl@agent-runs-modern-api-openai-gpt-5-5' === ( $ability->last_input['name'] ?? '' ));
    $assert('agent-facing name is sanitized', 'current-project' === ( $data['name'] ?? '' ));
    $assert('agent-facing repo is sanitized', 'current-project' === ( $data['repo'] ?? '' ));
    $assert('agent-facing paths are sanitized', 'plugins/demo.php' === ( $data['path'] ?? '' ));
    $assert('agent-facing file lists strip scoped root', array( 'plugins/demo.php' ) === ( $data['files'] ?? array() ));
    $assert('agent-facing diffs strip scoped root', is_string($data['diff'] ?? null) && str_contains($data['diff'], 'a/plugins/demo.php') && ! str_contains($data['diff'], '.agent-workspace'));
    $assert('nested required args are sanitized', 'current-project' === ( $data['next_required_args']['name'] ?? '' ));
    $assert('file content is not rewritten', is_string($data['content'] ?? null) && str_contains($data['content'], 'wp-rl@agent-runs-modern-api-openai-gpt-5-5') && str_contains($data['content'], '.agent-workspace/current-project'));

    $diff_tools = new \DataMachineCode\Tools\WorkspaceDiffTools();
    $diff       = $diff_tools->handleDiffSummary(array( 'name' => 'current-project' ));
    $assert('diff tools resolve aliases before ability execution', 'wp-rl@agent-runs-modern-api-openai-gpt-5-5' === ( $ability->last_input['name'] ?? '' ));
    $assert('diff tools scope empty path to alias root', '.agent-workspace/current-project' === ( $ability->last_input['path'] ?? '' ));
    $assert('diff tool result is sanitized', 'current-project' === ( $diff['data']['name'] ?? '' ));

    add_filter(
        'datamachine_code_workspace_aliases',
        fn( array $aliases ): array => array_merge(
            $aliases,
            array(
                    'scoped-project' => array(
                        'target' => 'wp-rl@agent-runs-modern-api-openai-gpt-5-5',
                        'root'   => '.agent-workspace/current-project',
                    ),
                )
        )
    );

    $write = $tools->handleWrite(array( 'repo' => 'scoped-project', 'path' => 'plugins/demo.php', 'content' => '<?php' ));
    $assert('scoped write succeeds', true === ( $write['success'] ?? false ));
    $assert('scoped write prefixes virtual path before ability execution', '.agent-workspace/current-project/plugins/demo.php' === ( $ability->last_input['path'] ?? '' ));

    $grep = $tools->handleGrep(array( 'repo' => 'scoped-project', 'pattern' => 'Plugin Name' ));
    $assert('scoped grep defaults to alias root', '.agent-workspace/current-project' === ( $ability->last_input['path'] ?? '' ));

    $escape = $tools->handleRead(array( 'repo' => 'scoped-project', 'path' => '../README.md' ));
    $assert('scoped read rejects path escape', false === ( $escape['success'] ?? true ));

    if ($failures ) {
        echo "\nFAIL: " . count($failures) . " assertion(s) failed\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK ({$passes} assertions)\n";
}
