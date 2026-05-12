<?php
/**
 * Smoke test for agent-facing workspace aliases.
 *
 * Run: php tests/smoke-workspace-alias-tools.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['dmc_workspace_alias_filters'] = array();
	$GLOBALS['dmc_workspace_alias_ability'] = null;

	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['dmc_workspace_alias_filters'][ $tag ][ $priority ][] = $callback;
	}

	function apply_filters( string $tag, $value ) {
		if ( empty( $GLOBALS['dmc_workspace_alias_filters'][ $tag ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['dmc_workspace_alias_filters'][ $tag ] );
		foreach ( $GLOBALS['dmc_workspace_alias_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value );
			}
		}

		return $value;
	}

	function get_option( string $name, $default = false ) {
		return $default;
	}

	function wp_get_ability( string $name ) {
		return $GLOBALS['dmc_workspace_alias_ability'];
	}

	function is_wp_error( $value ): bool {
		return false;
	}
}

namespace DataMachine\Engine\AI\Tools {
	class BaseTool {
		protected function registerTool( string $tool_id, callable $definition_callback, array $contexts = array(), array $options = array() ): void {}
		protected function buildErrorResponse( string $message, string $tool_name ): array {
			return array(
				'success'   => false,
				'error'     => $message,
				'tool_name' => $tool_name,
			);
		}
	}
}

namespace {
	require __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';
	require __DIR__ . '/../inc/Tools/WorkspaceTools.php';
	require __DIR__ . '/../inc/Tools/WorkspaceDiffTools.php';

	class DataMachineCodeWorkspaceAliasFakeAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
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
		if ( $condition ) {
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
	$result                                   = $tools->handleGitStatus( array( 'name' => 'current-project' ) );
	$data                                     = $result['data'] ?? array();

	$assert( 'alias resolves before ability execution', 'wp-rl@agent-runs-modern-api-openai-gpt-5-5' === ( $ability->last_input['name'] ?? '' ) );
	$assert( 'agent-facing name is sanitized', 'current-project' === ( $data['name'] ?? '' ) );
	$assert( 'agent-facing repo is sanitized', 'current-project' === ( $data['repo'] ?? '' ) );
	$assert( 'agent-facing paths are sanitized', 'plugins/demo.php' === ( $data['path'] ?? '' ) );
	$assert( 'agent-facing file lists strip scoped root', array( 'plugins/demo.php' ) === ( $data['files'] ?? array() ) );
	$assert( 'agent-facing diffs strip scoped root', is_string( $data['diff'] ?? null ) && str_contains( $data['diff'], 'a/plugins/demo.php' ) && ! str_contains( $data['diff'], '.agent-workspace' ) );
	$assert( 'nested required args are sanitized', 'current-project' === ( $data['next_required_args']['name'] ?? '' ) );
	$assert( 'file content is not rewritten', is_string( $data['content'] ?? null ) && str_contains( $data['content'], 'wp-rl@agent-runs-modern-api-openai-gpt-5-5' ) && str_contains( $data['content'], '.agent-workspace/current-project' ) );

	$diff_tools = new \DataMachineCode\Tools\WorkspaceDiffTools();
	$diff       = $diff_tools->handleDiffSummary( array( 'name' => 'current-project' ) );
	$assert( 'diff tools resolve aliases before ability execution', 'wp-rl@agent-runs-modern-api-openai-gpt-5-5' === ( $ability->last_input['name'] ?? '' ) );
	$assert( 'diff tools scope empty path to alias root', '.agent-workspace/current-project' === ( $ability->last_input['path'] ?? '' ) );
	$assert( 'diff tool result is sanitized', 'current-project' === ( $diff['data']['name'] ?? '' ) );

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

	$write = $tools->handleWrite( array( 'repo' => 'scoped-project', 'path' => 'plugins/demo.php', 'content' => '<?php' ) );
	$assert( 'scoped write succeeds', true === ( $write['success'] ?? false ) );
	$assert( 'scoped write prefixes virtual path before ability execution', '.agent-workspace/current-project/plugins/demo.php' === ( $ability->last_input['path'] ?? '' ) );

	$grep = $tools->handleGrep( array( 'repo' => 'scoped-project', 'pattern' => 'Plugin Name' ) );
	$assert( 'scoped grep defaults to alias root', '.agent-workspace/current-project' === ( $ability->last_input['path'] ?? '' ) );

	$escape = $tools->handleRead( array( 'repo' => 'scoped-project', 'path' => '../README.md' ) );
	$assert( 'scoped read rejects path escape', false === ( $escape['success'] ?? true ) );

	if ( $failures ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s) failed\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK ({$passes} assertions)\n";
}
