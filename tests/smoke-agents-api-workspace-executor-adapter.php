<?php
/**
 * Smoke test for optional Agents API workspace executor adapter.
 *
 * Run: php tests/smoke-agents-api-workspace-executor-adapter.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	$GLOBALS['dmc_agents_api_filters']          = array();
	$GLOBALS['dmc_agents_api_executed_ability'] = null;

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['dmc_agents_api_filters'][ $hook ][] = compact('callback', 'priority', 'accepted_args');
	}

	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['dmc_agents_api_filters'][ $hook ] ?? array() as $filter ) {
			$value = call_user_func($filter['callback'], $value, ...array_slice($args, 0, (int) $filter['accepted_args'] - 1));
		}
		return $value;
	}

	function wp_get_ability( string $name ) {
		return new DataMachineCodeAgentsApiFakeAbility($name);
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class DataMachineCodeAgentsApiFakeAbility {
		public function __construct( private string $name ) {}

		public function execute( array $input ): array {
			$GLOBALS['dmc_agents_api_executed_ability'] = array(
				'name'  => $this->name,
				'input' => $input,
			);

			return array(
				'success' => true,
				'ability' => $this->name,
				'input'   => $input,
			);
		}
	}

	include __DIR__ . '/../inc/AgentsApi/WorkspaceExecutorAdapter.php';

	$failures = array();
	$assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Data Machine Code Agents API workspace executor adapter - smoke\n";

	$assert(
		'adapter no-ops before Agents API tool substrate is loaded',
		false === \DataMachineCode\AgentsApi\WorkspaceExecutorAdapter::register()
			&& array() === $GLOBALS['dmc_agents_api_filters']
	);
	eval(
		'namespace AgentsAPI\\AI\\Tools {'
		. 'interface WP_Agent_Tool_Executor {'
		. 'public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array;'
		. '}'
		. 'class WP_Agent_Tool_Source_Registry {}'
		. '}'
	);

	$assert(
		'adapter registers when Agents API tool substrate is loaded',
		true === \DataMachineCode\AgentsApi\WorkspaceExecutorAdapter::register()
	);
	$assert('tool source filter is registered', isset($GLOBALS['dmc_agents_api_filters']['agents_api_tool_sources']));
	$assert('executor target filter is registered', isset($GLOBALS['dmc_agents_api_filters']['agents_api_executor_targets']));
	$assert('tool executor filter is registered', isset($GLOBALS['dmc_agents_api_filters']['agents_api_tool_executors']));

	$sources = apply_filters(
		'agents_api_tool_sources',
		array(
			'client' => static fn(): array => array(
				'client/existing_tool' => array(
					'name'        => 'client/existing_tool',
					'source'      => 'client',
					'description' => 'Existing client tool.',
					'parameters'  => array( 'type' => 'object' ),
				),
			),
		),
		array(),
		new \AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry()
	);
	$assert('client source callback is exposed for current Agents API runtime tools', isset($sources['client']) && is_callable($sources['client']));

	$tools = call_user_func($sources['client'], array(), null);
	$assert('existing client source tools are preserved', isset($tools['client/existing_tool']));
	$assert('filesystem.read declaration is exposed', isset($tools['client/filesystem_read']));
	$assert('filesystem.write declaration is exposed', isset($tools['client/filesystem_write']));
	$assert('git.diff declaration is exposed', isset($tools['client/git_diff']));
	$assert('git.commit declaration is exposed', isset($tools['client/git_commit']));
	$assert('github.pr declaration is exposed', isset($tools['client/github_pr']));
	$assert('declaration carries blessed target metadata', 'data-machine-code/blessed-workspace' === ( $tools['client/git_commit']['runtime']['executor_target'] ?? '' ));
	$assert('declaration carries generic capability metadata', 'git.commit' === ( $tools['client/git_commit']['runtime']['capability'] ?? '' ));
	$assert('mutating declaration exposes DMC side-effect boundary', 'data-machine-code' === ( $tools['client/filesystem_write']['runtime']['side_effect_boundary'] ?? '' ));

	$targets = apply_filters('agents_api_executor_targets', array(), array());
	$target  = $targets['data-machine-code/blessed-workspace'] ?? array();
	$assert('target metadata is discoverable', 'data-machine-code/blessed-workspace' === ( $target['id'] ?? '' ));
	$assert('target required capabilities stay generic', in_array('github.pr', $target['required_capabilities'] ?? array(), true));

	$executors = apply_filters('agents_api_tool_executors', array(), array());
	$executor  = $executors['data-machine-code/blessed-workspace'] ?? null;
	$assert('tool executor adapter is discoverable', $executor instanceof \AgentsAPI\AI\Tools\WP_Agent_Tool_Executor);

	$result = $executor->executeWP_Agent_Tool_Call(
		array(
			'tool_name'  => 'client/filesystem_read',
			'parameters' => array(
				'repo' => 'demo@branch',
				'path' => 'README.md',
			),
		),
		$tools['client/filesystem_read'],
		array()
	);
	$assert('executor delegates to DMC workspace-read ability', 'datamachine-code/workspace-read' === ( $GLOBALS['dmc_agents_api_executed_ability']['name'] ?? '' ));
	$assert('executor passes parameters through unchanged', 'README.md' === ( $GLOBALS['dmc_agents_api_executed_ability']['input']['path'] ?? '' ));
	$assert('executor result preserves runtime target metadata', 'data-machine-code/blessed-workspace' === ( $result['runtime']['executor_target'] ?? '' ));

	$unsupported = $executor->executeWP_Agent_Tool_Call(
		array(
			'tool_name'  => 'client/unknown',
			'parameters' => array(),
		),
		array( 'name' => 'client/unknown' ),
		array()
	);
	$assert('unsupported tools fail without side effects', false === ( $unsupported['success'] ?? true ) && 'unsupported_tool' === ( $unsupported['error_type'] ?? '' ));

	if ( ! empty($failures) ) {
		echo "\nFAIL: " . count($failures) . " assertion(s) failed\n";
		exit(1);
	}

	echo "\nOK\n";
}
