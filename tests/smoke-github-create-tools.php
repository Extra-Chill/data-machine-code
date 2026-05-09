<?php
/**
 * Pure-PHP smoke test for GitHub create chat/pipeline tools.
 *
 * Run: php tests/smoke-github-create-tools.php
 */

declare( strict_types=1 );

namespace DataMachine\Engine\AI\Tools {
	class BaseTool {
		public array $registered = array();

		protected function registerTool( string $name, array $definition_callback, array $contexts, array $options = array() ): void {
			$this->registered[ $name ] = array(
				'definition_callback' => $definition_callback,
				'contexts'            => $contexts,
				'options'             => $options,
			);
		}

		protected function buildErrorResponse( string $message, string $tool_name ): array {
			return array(
				'success'   => false,
				'error'     => $message,
				'tool_name' => $tool_name,
			);
		}
	}
}

namespace DataMachineCode\Abilities {
	class GitHubAbilities {
		public static function getRegisteredRepos(): array {
			return array(
				array(
					'owner' => 'Extra-Chill',
					'repo'  => 'data-machine-code',
					'label' => 'Data Machine Code',
				),
			);
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['dmc_tool_ability_calls'] = array();

	class DMC_Test_Ability {
		public function __construct( private string $name ) {}

		public function execute( array $input ): array {
			$GLOBALS['dmc_tool_ability_calls'][] = array(
				'name'  => $this->name,
				'input' => $input,
			);

			if ( 'datamachine/create-github-pull-request' === $this->name ) {
				return array(
					'success'      => true,
					'pull_request' => array( 'number' => 261 ),
				);
			}

			return array(
				'success'      => true,
				'issue_number' => 262,
			);
		}
	}

	function wp_get_ability( string $name ): ?DMC_Test_Ability {
		if ( in_array( $name, array( 'datamachine/create-github-issue', 'datamachine/create-github-pull-request' ), true ) ) {
			return new DMC_Test_Ability( $name );
		}

		return null;
	}

	function is_wp_error( $thing ): bool {
		return false;
	}

	require __DIR__ . '/../inc/Tools/GitHubIssueTool.php';
	require __DIR__ . '/../inc/Tools/GitHubPullRequestTool.php';
	require __DIR__ . '/../inc/Tools/GitHubTools.php';

	use DataMachineCode\Tools\GitHubIssueTool;
	use DataMachineCode\Tools\GitHubTools;
	use DataMachineCode\Tools\GitHubPullRequestTool;

	$failures = array();
	$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
		if ( $cond ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};
	$assert_array_items = function ( array $schema, string $path ) use ( &$assert_array_items, $assert ): void {
		if ( 'array' === ( $schema['type'] ?? null ) ) {
			$assert( "{$path} array schema declares items", isset( $schema['items'] ) && is_array( $schema['items'] ) );
		}

		foreach ( $schema['properties'] ?? array() as $property => $property_schema ) {
			if ( is_array( $property_schema ) ) {
				$assert_array_items( $property_schema, "{$path}.properties.{$property}" );
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$assert_array_items( $schema['items'], "{$path}.items" );
		}
	};

	echo "GitHub create tools - smoke\n";

	$issue_tool = new GitHubIssueTool();
	$pr_tool    = new GitHubPullRequestTool();
	$github_tools = new GitHubTools();

	$assert( 'create_github_issue tool is registered', isset( $issue_tool->registered['create_github_issue'] ) );
	$assert( 'create_github_issue is available in chat', in_array( 'chat', $issue_tool->registered['create_github_issue']['contexts'] ?? array(), true ) );
	$assert( 'create_github_issue is available in pipeline', in_array( 'pipeline', $issue_tool->registered['create_github_issue']['contexts'] ?? array(), true ) );
	$assert( 'create_github_issue links to create issue ability', 'datamachine/create-github-issue' === ( $issue_tool->registered['create_github_issue']['options']['ability'] ?? '' ) );

	$assert( 'create_github_pull_request tool is registered', isset( $pr_tool->registered['create_github_pull_request'] ) );
	$assert( 'create_github_pull_request is available in chat', in_array( 'chat', $pr_tool->registered['create_github_pull_request']['contexts'] ?? array(), true ) );
	$assert( 'create_github_pull_request is available in pipeline', in_array( 'pipeline', $pr_tool->registered['create_github_pull_request']['contexts'] ?? array(), true ) );
	$assert( 'create_github_pull_request links to create PR ability', 'datamachine/create-github-pull-request' === ( $pr_tool->registered['create_github_pull_request']['options']['ability'] ?? '' ) );

	$issue_definition = $issue_tool->getToolDefinition();
	$issue_params     = $issue_definition['parameters'] ?? array();
	$assert( 'create_github_issue exposes assignees from ability schema', array_key_exists( 'assignees', $issue_params ) );
	$assert( 'create_github_issue exposes milestone from ability schema', array_key_exists( 'milestone', $issue_params ) );

	$pr_definition = $pr_tool->getToolDefinition();
	$pr_params     = $pr_definition['parameters'] ?? array();
	$assert( 'create_github_pull_request requires repo', true === ( $pr_params['repo']['required'] ?? false ) );
	$assert( 'create_github_pull_request requires title', true === ( $pr_params['title']['required'] ?? false ) );
	$assert( 'create_github_pull_request requires head', true === ( $pr_params['head']['required'] ?? false ) );
	foreach ( array( 'repo', 'title', 'head', 'base', 'body', 'draft', 'labels', 'maintainer_can_modify' ) as $param ) {
		$assert( "create_github_pull_request exposes {$param}", array_key_exists( $param, $pr_params ) );
	}

	$issue_result = $issue_tool->handle_tool_call( array(
		'repo'      => 'Extra-Chill/data-machine-code',
		'title'     => 'Issue title',
		'body'      => 'Issue body',
		'labels'    => array( 'bug' ),
		'assignees' => array( 'chubes4' ),
		'milestone' => 7,
	) );
	$assert( 'create_github_issue returns direct ability success', true === ( $issue_result['success'] ?? false ) );
	$assert( 'create_github_issue response names tool', 'create_github_issue' === ( $issue_result['tool_name'] ?? '' ) );

	$pr_result = $pr_tool->handle_tool_call( array(
		'repo'                  => 'Extra-Chill/data-machine-code',
		'title'                 => 'PR title',
		'head'                  => 'fix/github-pr-tool',
		'base'                  => 'main',
		'body'                  => 'PR body',
		'labels'                => array( 'needs-review' ),
		'draft'                 => true,
		'maintainer_can_modify' => false,
	) );
	$assert( 'create_github_pull_request returns direct ability success', true === ( $pr_result['success'] ?? false ) );
	$assert( 'create_github_pull_request response names tool', 'create_github_pull_request' === ( $pr_result['tool_name'] ?? '' ) );

	$pr_call = $GLOBALS['dmc_tool_ability_calls'][1] ?? array();
	$assert( 'create_github_pull_request calls PR ability', 'datamachine/create-github-pull-request' === ( $pr_call['name'] ?? '' ) );
	$assert( 'create_github_pull_request forwards labels', array( 'needs-review' ) === ( $pr_call['input']['labels'] ?? null ) );
	$assert( 'create_github_pull_request forwards maintainer_can_modify', false === ( $pr_call['input']['maintainer_can_modify'] ?? null ) );

	$tool_definitions = array(
		'create_github_issue'        => $issue_definition,
		'create_github_pull_request' => $pr_definition,
	);
	foreach ( $github_tools->registered as $tool_name => $registration ) {
		$callback = $registration['definition_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$tool_definitions[ $tool_name ] = $callback();
		}
	}

	foreach ( $tool_definitions as $tool_name => $definition ) {
		foreach ( $definition['parameters'] ?? array() as $parameter => $parameter_schema ) {
			if ( is_array( $parameter_schema ) ) {
				$assert_array_items( $parameter_schema, "{$tool_name}.parameters.{$parameter}" );
			}
		}
	}

	$plugin_source = file_get_contents( __DIR__ . '/../data-machine-code.php' );
	$assert( 'legacy system ability function is removed', ! str_contains( $plugin_source, 'datamachine_code_register_system_abilities' ) );
	$assert( 'legacy create-github-issue registration is removed from plugin bootstrap', ! str_contains( $plugin_source, "wp_register_ability(\n\t\t'datamachine/create-github-issue'" ) );
	$assert( 'plugin instantiates PR create tool', str_contains( $plugin_source, 'new \\DataMachineCode\\Tools\\GitHubPullRequestTool();' ) );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK\n";
	exit( 0 );
}
