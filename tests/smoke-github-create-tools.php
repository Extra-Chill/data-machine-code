<?php
/**
 * Pure-PHP smoke test for GitHub create chat/pipeline tools.
 *
 * Run: php tests/smoke-github-create-tools.php
 */

declare( strict_types=1 );

namespace DataMachine\Engine\AI\Tools {
    class BaseTool
    {
        public array $registered = array();

        protected function registerTool( string $name, array $definition_callback, array $contexts, array $options = array() ): void
        {
            $this->registered[ $name ] = array(
            'definition_callback' => $definition_callback,
            'contexts'            => $contexts,
            'options'             => $options,
            );
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

namespace DataMachineCode\Abilities {
    class GitHubAbilities
    {
        public static array $addLabelsCalls = array();
        public static array $removeLabelCalls = array();

        public static function getRegisteredRepos(): array
        {
            return array(
            array(
            'owner' => 'Extra-Chill',
            'repo'  => 'data-machine-code',
            'label' => 'Data Machine Code',
            ),
            );
        }

        public static function addLabels( array $input ): array|\WP_Error
        {
            self::$addLabelsCalls[] = $input;

            $labels = $input['labels'] ?? array();
            if (! is_array($labels) || '' === (string) ( $labels[0] ?? '' ) ) {
                return new \WP_Error('missing_labels', 'At least one label is required.');
            }

            return array(
            'success'        => true,
            'applied_labels' => $labels,
            );
        }

        public static function removeLabel( array $input ): array|\WP_Error
        {
            self::$removeLabelCalls[] = $input;

            $label = (string) ( $input['label'] ?? '' );
            if ('' === $label ) {
                return new \WP_Error('missing_label', 'A single label is required.');
            }

            return array(
            'success'       => true,
            'removed_label' => $label,
            );
        }
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    $GLOBALS['dmc_tool_ability_calls'] = array();

    class DMC_Test_Ability
    {
        public function __construct( private string $name )
        {
        }

		public function execute( array $input ): array|WP_Error
		{
			$GLOBALS['dmc_tool_ability_calls'][] = array(
			'name'  => $this->name,
			'input' => $input,
			);

			if ('datamachine-code/add-github-labels' === $this->name ) {
				return \DataMachineCode\Abilities\GitHubAbilities::addLabels($input);
			}

			if ('datamachine-code/remove-github-label' === $this->name ) {
				return \DataMachineCode\Abilities\GitHubAbilities::removeLabel($input);
			}

			if ('datamachine-code/create-github-pull-request' === $this->name ) {
                return array(
                'success'      => true,
                'kind'         => 'pull_request',
                'repo'         => 'Extra-Chill/data-machine-code',
                'number'       => 261,
                'pull_number'  => 261,
                'url'          => 'https://github.com/Extra-Chill/data-machine-code/pull/261',
                'html_url'     => 'https://github.com/Extra-Chill/data-machine-code/pull/261',
                'pull_request' => array( 'number' => 261 ),
                );
            }

            return array(
            'success'      => true,
            'kind'         => 'issue',
            'repo'         => 'Extra-Chill/data-machine-code',
            'number'       => 262,
            'issue_number' => 262,
            'url'          => 'https://github.com/Extra-Chill/data-machine-code/issues/262',
            'html_url'     => 'https://github.com/Extra-Chill/data-machine-code/issues/262',
            );
        }
    }

    function wp_get_ability( string $name ): ?DMC_Test_Ability
    {
		if (in_array($name, array( 'datamachine-code/create-github-issue', 'datamachine-code/create-github-pull-request', 'datamachine-code/add-github-labels', 'datamachine-code/remove-github-label' ), true) ) {
            return new DMC_Test_Ability($name);
        }

        return null;
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public function __construct( private string $code = '', private string $message = '', private mixed $data = null )
            {
            }
            public function get_error_message(): string
            {
                return $this->message; 
            }
            public function get_error_code(): string
            {
                return $this->code; 
            }
            public function get_error_data(): mixed
            {
                return $this->data; 
            }
        }
    }

    function is_wp_error( $thing ): bool
    {
        return $thing instanceof WP_Error;
    }

    include __DIR__ . '/../inc/Tools/GitHubIssueTool.php';
    include __DIR__ . '/../inc/Tools/GitHubPullRequestTool.php';
    include __DIR__ . '/../inc/Tools/GitHubTools.php';

    use DataMachineCode\Tools\GitHubIssueTool;
    use DataMachineCode\Tools\GitHubTools;
    use DataMachineCode\Tools\GitHubPullRequestTool;

    $failures = array();
    $assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
        if ($cond ) {
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };
    $assert_array_items = function ( array $schema, string $path ) use ( &$assert_array_items, $assert ): void {
        if ('array' === ( $schema['type'] ?? null ) ) {
            $assert("{$path} array schema declares items", isset($schema['items']) && is_array($schema['items']));
        }

        foreach ( $schema['properties'] ?? array() as $property => $property_schema ) {
            if (is_array($property_schema) ) {
                $assert_array_items($property_schema, "{$path}.properties.{$property}");
            }
        }

        if (isset($schema['items']) && is_array($schema['items']) ) {
            $assert_array_items($schema['items'], "{$path}.items");
        }
    };

    echo "GitHub create tools - smoke\n";

    $issue_tool = new GitHubIssueTool();
    $pr_tool    = new GitHubPullRequestTool();
    $github_tools = new GitHubTools();

    $assert('create_github_issue tool is registered', isset($issue_tool->registered['create_github_issue']));
    $assert('create_github_issue is available in chat', in_array('chat', $issue_tool->registered['create_github_issue']['contexts'] ?? array(), true));
    $assert('create_github_issue is available in pipeline', in_array('pipeline', $issue_tool->registered['create_github_issue']['contexts'] ?? array(), true));
    $assert('create_github_issue links to create issue ability', 'datamachine-code/create-github-issue' === ( $issue_tool->registered['create_github_issue']['options']['ability'] ?? '' ));

    $assert('create_github_pull_request tool is registered', isset($pr_tool->registered['create_github_pull_request']));
    $assert('create_github_pull_request is available in chat', in_array('chat', $pr_tool->registered['create_github_pull_request']['contexts'] ?? array(), true));
    $assert('create_github_pull_request is available in pipeline', in_array('pipeline', $pr_tool->registered['create_github_pull_request']['contexts'] ?? array(), true));
    $assert('create_github_pull_request links to create PR ability', 'datamachine-code/create-github-pull-request' === ( $pr_tool->registered['create_github_pull_request']['options']['ability'] ?? '' ));

    $assert('add_label_to_issue tool is registered', isset($github_tools->registered['add_label_to_issue']));
    $assert('add_label_to_issue is available in chat', in_array('chat', $github_tools->registered['add_label_to_issue']['contexts'] ?? array(), true));
    $assert('add_label_to_issue is available in pipeline', in_array('pipeline', $github_tools->registered['add_label_to_issue']['contexts'] ?? array(), true));
    $assert('add_label_to_issue uses wrapper input normalization instead of direct ability projection', ! isset($github_tools->registered['add_label_to_issue']['options']['ability']));
    $assert('remove_label_from_issue tool is registered', isset($github_tools->registered['remove_label_from_issue']));
    $assert('remove_label_from_issue is available in chat', in_array('chat', $github_tools->registered['remove_label_from_issue']['contexts'] ?? array(), true));
    $assert('remove_label_from_issue is available in pipeline', in_array('pipeline', $github_tools->registered['remove_label_from_issue']['contexts'] ?? array(), true));

    $list_issues_definition = $github_tools->getListIssuesDefinition();
    $assert('list_github_issues uses default duplicate guard', 'repeatable' !== ( $list_issues_definition['runtime']['duplicate_policy'] ?? '' ));

    $issue_definition = $issue_tool->getToolDefinition();
    $issue_params     = $issue_definition['parameters']['properties'] ?? array();
    $assert('create_github_issue exposes assignees from ability schema', array_key_exists('assignees', $issue_params));
    $assert('create_github_issue exposes milestone from ability schema', array_key_exists('milestone', $issue_params));

    $pr_definition = $pr_tool->getToolDefinition();
    $pr_params     = $pr_definition['parameters']['properties'] ?? array();
    $pr_required   = $pr_definition['parameters']['required'] ?? array();
    $assert('create_github_pull_request requires repo', in_array('repo', $pr_required, true));
    $assert('create_github_pull_request requires title', in_array('title', $pr_required, true));
    $assert('create_github_pull_request requires head', in_array('head', $pr_required, true));
    foreach ( array( 'repo', 'title', 'head', 'base', 'body', 'draft', 'labels', 'maintainer_can_modify' ) as $param ) {
        $assert("create_github_pull_request exposes {$param}", array_key_exists($param, $pr_params));
    }

    $issue_result = $issue_tool->handle_tool_call(
        array(
        'repo'      => 'Extra-Chill/data-machine-code',
        'title'     => 'Issue title',
        'body'      => 'Issue body',
        'labels'    => array( 'bug' ),
        'assignees' => array( 'chubes4' ),
        'milestone' => 7,
        ) 
    );
    $assert('create_github_issue returns direct ability success', true === ( $issue_result['success'] ?? false ));
    $assert('create_github_issue response names tool', 'create_github_issue' === ( $issue_result['tool_name'] ?? '' ));
    $assert('create_github_issue response identifies issue kind', 'issue' === ( $issue_result['kind'] ?? '' ));
    $assert('create_github_issue response exposes canonical issue URL', 'https://github.com/Extra-Chill/data-machine-code/issues/262' === ( $issue_result['url'] ?? '' ));

    $pr_result = $pr_tool->handle_tool_call(
        array(
        'repo'                  => 'Extra-Chill/data-machine-code',
        'title'                 => 'PR title',
        'head'                  => 'fix/github-pr-tool',
        'base'                  => 'main',
        'body'                  => 'PR body',
        'labels'                => array( 'needs-review' ),
        'draft'                 => true,
        'maintainer_can_modify' => false,
        'job_id'                     => 123,
        'run_artifacts'              => array( 'required_tool_names' => array( 'agent_daily_memory' ) ),
        'run_artifact_egress_policy' => array(
        'daily_memory' => array( 'egress' => array( 'bundle-file', 'pr-body' ) ),
        ),
        'bundle_root'                => 'bundles/world-creator',
        ) 
    );
    $assert('create_github_pull_request returns direct ability success', true === ( $pr_result['success'] ?? false ));
    $assert('create_github_pull_request response names tool', 'create_github_pull_request' === ( $pr_result['tool_name'] ?? '' ));
    $assert('create_github_pull_request response identifies PR kind', 'pull_request' === ( $pr_result['kind'] ?? '' ));
    $assert('create_github_pull_request response exposes canonical PR URL', 'https://github.com/Extra-Chill/data-machine-code/pull/261' === ( $pr_result['url'] ?? '' ));
    $assert('create_github_pull_request URL is distinguishable from branch URL', ! str_contains($pr_result['url'] ?? '', '/tree/'));

    $pr_call = $GLOBALS['dmc_tool_ability_calls'][1] ?? array();
    $assert('create_github_pull_request calls PR ability', 'datamachine-code/create-github-pull-request' === ( $pr_call['name'] ?? '' ));
    $assert('create_github_pull_request forwards labels', array( 'needs-review' ) === ( $pr_call['input']['labels'] ?? null ));
    $assert('create_github_pull_request forwards maintainer_can_modify', false === ( $pr_call['input']['maintainer_can_modify'] ?? null ));
    $assert('create_github_pull_request forwards job context', 123 === ( $pr_call['input']['job_id'] ?? null ));
    $assert('create_github_pull_request forwards run artifacts', array( 'required_tool_names' => array( 'agent_daily_memory' ) ) === ( $pr_call['input']['run_artifacts'] ?? null ));
    $assert(
        'create_github_pull_request forwards run artifact policy',
        array( 'daily_memory' => array( 'egress' => array( 'bundle-file', 'pr-body' ) ) ) === ( $pr_call['input']['run_artifact_egress_policy'] ?? null )
    );
    $assert('create_github_pull_request forwards bundle root', 'bundles/world-creator' === ( $pr_call['input']['bundle_root'] ?? null ));

    $add_label_definition    = $github_tools->getAddLabelToIssueDefinition();
    $remove_label_definition = $github_tools->getRemoveLabelFromIssueDefinition();
    $manage_issue_definition = $github_tools->getManageIssueDefinition();
    $assert('add_label_to_issue requires repo issue_number label', array( 'repo', 'issue_number', 'label' ) === ( $add_label_definition['parameters']['required'] ?? array() ));
    $assert('remove_label_from_issue requires repo issue_number label', array( 'repo', 'issue_number', 'label' ) === ( $remove_label_definition['parameters']['required'] ?? array() ));
    $assert('manage_github_issue warns update labels replace full set', str_contains($manage_issue_definition['parameters']['properties']['labels']['description'] ?? '', 'REPLACES the entire existing label set'));
    $assert('manage_github_issue points to surgical label tools', str_contains($manage_issue_definition['description'] ?? '', 'add_label_to_issue') && str_contains($manage_issue_definition['description'] ?? '', 'remove_label_from_issue'));

    $add_label_result = $github_tools->handleAddLabelToIssue(
        array(
        'repo'         => 'Extra-Chill/data-machine-code',
        'issue_number' => 328,
        'label'        => 'status:idea-ready',
        ) 
    );
    $assert('add_label_to_issue returns standard success envelope', true === ( $add_label_result['success'] ?? false ) && 'add_label_to_issue' === ( $add_label_result['tool_name'] ?? '' ));
    $assert('add_label_to_issue dispatches to addLabels with single label array', array( 'status:idea-ready' ) === ( \DataMachineCode\Abilities\GitHubAbilities::$addLabelsCalls[0]['labels'] ?? null ));
    $assert('add_label_to_issue forwards issue_number', 328 === (int) ( \DataMachineCode\Abilities\GitHubAbilities::$addLabelsCalls[0]['issue_number'] ?? 0 ));

    $remove_label_result = $github_tools->handleRemoveLabelFromIssue(
        array(
        'repo'         => 'Extra-Chill/data-machine-code',
        'issue_number' => 328,
        'label'        => 'status:idea-ready',
        ) 
    );
    $assert('remove_label_from_issue returns standard success envelope', true === ( $remove_label_result['success'] ?? false ) && 'remove_label_from_issue' === ( $remove_label_result['tool_name'] ?? '' ));
    $assert('remove_label_from_issue dispatches to removeLabel', 'status:idea-ready' === ( \DataMachineCode\Abilities\GitHubAbilities::$removeLabelCalls[0]['label'] ?? '' ));
    $assert('remove_label_from_issue forwards issue_number', 328 === (int) ( \DataMachineCode\Abilities\GitHubAbilities::$removeLabelCalls[0]['issue_number'] ?? 0 ));

    $missing_add_label_result = $github_tools->handleAddLabelToIssue(
        array(
        'repo'         => 'Extra-Chill/data-machine-code',
        'issue_number' => 328,
        ) 
    );
    $assert('add_label_to_issue validation errors use error envelope', false === ( $missing_add_label_result['success'] ?? true ) && 'add_label_to_issue' === ( $missing_add_label_result['tool_name'] ?? '' ));

    $missing_remove_label_result = $github_tools->handleRemoveLabelFromIssue(
        array(
        'repo'         => 'Extra-Chill/data-machine-code',
        'issue_number' => 328,
        ) 
    );
    $assert('remove_label_from_issue validation errors use error envelope', false === ( $missing_remove_label_result['success'] ?? true ) && 'remove_label_from_issue' === ( $missing_remove_label_result['tool_name'] ?? '' ));

    $tool_definitions = array(
    'create_github_issue'        => $issue_definition,
    'create_github_pull_request' => $pr_definition,
    );
    foreach ( $github_tools->registered as $tool_name => $registration ) {
        $callback = $registration['definition_callback'] ?? null;
        if (is_callable($callback) ) {
            $tool_definitions[ $tool_name ] = $callback();
        }
    }

    foreach ( $tool_definitions as $tool_name => $definition ) {
        foreach ( $definition['parameters'] ?? array() as $parameter => $parameter_schema ) {
            if (is_array($parameter_schema) ) {
                $assert_array_items($parameter_schema, "{$tool_name}.parameters.{$parameter}");
            }
        }
    }

    $plugin_source = file_get_contents(__DIR__ . '/../data-machine-code.php');
    $assert('legacy system ability function is removed', ! str_contains($plugin_source, 'datamachine_code_register_system_abilities'));
    $assert('legacy create-github-issue registration is removed from plugin bootstrap', ! str_contains($plugin_source, "wp_register_ability(\n\t\t'datamachine-code/create-github-issue'"));
    $assert('plugin instantiates PR create tool', str_contains($plugin_source, 'new \\DataMachineCode\\Tools\\GitHubPullRequestTool();'));

    if (! empty($failures) ) {
        echo "\nFAIL: " . count($failures) . " assertion(s)\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK\n";
    exit(0);
}
