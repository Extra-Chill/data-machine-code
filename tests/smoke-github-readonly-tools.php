<?php
/**
 * Pure-PHP smoke test for read-only GitHub source and PR context tools.
 *
 * Run: php tests/smoke-github-readonly-tools.php
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

namespace DataMachine\Core {
	class PluginSettings {
		public static function get( string $key, string $default_value = '' ): string {
			return 'github_pat' === $key ? 'test-token' : $default_value;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Ability {}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '' ) {}
			public function get_error_message(): string { return $this->message; }
			public function get_error_code(): string { return $this->code; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
	}

	$GLOBALS['dmc_registered_abilities'] = array();
	$GLOBALS['dmc_executed_abilities']   = array();

	function wp_register_ability( string $name, array $definition ): void {
		$GLOBALS['dmc_registered_abilities'][ $name ] = $definition;
	}

	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook;
	}

	function did_action( string $hook ): int {
		return 0;
	}

	function add_action( string $hook, callable $callback ): void {}

	function wp_get_ability( string $name ) {
		if ( ! isset( $GLOBALS['dmc_registered_abilities'][ $name ] ) ) {
			return null;
		}

		return new class( $name ) {
			public function __construct( private string $name ) {}

			public function execute( array $parameters ): array {
				$GLOBALS['dmc_executed_abilities'][ $this->name ] = $parameters;

				return match ( $this->name ) {
					'datamachine/get-github-file' => array(
						'success' => true,
						'file'    => array( 'path' => $parameters['path'] ?? '', 'content' => 'file body' ),
					),
					'datamachine/list-github-tree' => array(
						'success' => true,
						'files'   => array( array( 'path' => 'inc/Foo.php' ) ),
						'count'   => 1,
					),
					'datamachine/get-github-pull' => array(
						'success' => true,
						'pull'    => array( 'number' => $parameters['pull_number'] ?? 0 ),
					),
					'datamachine/list-github-pull-files' => array(
						'success' => true,
						'files'   => array( array( 'filename' => 'README.md' ) ),
						'count'   => 1,
					),
					'datamachine/get-github-check-runs' => array(
						'success'    => true,
						'sha'        => $parameters['sha'] ?? '',
						'summary'    => array( 'state' => 'success' ),
						'check_runs' => array(),
						'count'      => 0,
					),
					'datamachine/get-github-commit-statuses' => array(
						'success'  => true,
						'sha'      => $parameters['sha'] ?? '',
						'summary'  => array( 'state' => 'success' ),
						'statuses' => array(),
						'count'    => 0,
					),
					'datamachine/get-github-pull-review-context' => array(
						'success' => true,
						'context' => array( 'metadata' => array( 'github_type' => 'pull_review_context' ) ),
					),
					default => array( 'success' => true ),
				};
			}
		};
	}

	require __DIR__ . '/../inc/Abilities/GitHubAbilities.php';
	require __DIR__ . '/../inc/Tools/GitHubTools.php';

	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Tools\GitHubTools;

	$failures = array();
	$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
		if ( $cond ) {
			echo "  ok {$label}\n";
			return;
		}
		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "GitHub read-only tools - smoke\n";

	new GitHubAbilities();
	$tools = new GitHubTools();

	$expected = array(
		'get_github_file'                => array(
			'ability'  => 'datamachine/get-github-file',
			'method'   => 'handleGetFile',
			'callback' => array( GitHubAbilities::class, 'getFileContents' ),
			'required' => array( 'repo', 'path' ),
			'params'   => array( 'repo' => 'Extra-Chill/data-machine-code', 'path' => 'README.md', 'ref' => 'main' ),
		),
		'list_github_tree'               => array(
			'ability'  => 'datamachine/list-github-tree',
			'method'   => 'handleListTree',
			'callback' => array( GitHubAbilities::class, 'getRepoTree' ),
			'required' => array( 'repo' ),
			'params'   => array( 'repo' => 'Extra-Chill/data-machine-code', 'ref' => 'main', 'path' => 'inc' ),
		),
		'get_github_pull'                => array(
			'ability'  => 'datamachine/get-github-pull',
			'method'   => 'handleGetPull',
			'callback' => array( GitHubAbilities::class, 'getPull' ),
			'required' => array( 'repo', 'pull_number' ),
			'params'   => array( 'repo' => 'Extra-Chill/data-machine-code', 'pull_number' => 89 ),
		),
		'get_github_pull_files'          => array(
			'ability'  => 'datamachine/list-github-pull-files',
			'method'   => 'handlePullFiles',
			'callback' => array( GitHubAbilities::class, 'listPullFiles' ),
			'required' => array( 'repo', 'pull_number' ),
			'params'   => array( 'repo' => 'Extra-Chill/data-machine-code', 'pull_number' => 89, 'per_page' => 10 ),
		),
		'get_github_check_runs'          => array(
			'ability'  => 'datamachine/get-github-check-runs',
			'method'   => 'handleCheckRuns',
			'callback' => array( GitHubAbilities::class, 'getCheckRuns' ),
			'required' => array( 'repo', 'sha' ),
			'optional' => array( 'per_page', 'include_check_output' ),
			'params'   => array( 'repo' => 'Extra-Chill/data-machine-code', 'sha' => 'abc123', 'include_check_output' => true ),
		),
		'get_github_commit_statuses'     => array(
			'ability'  => 'datamachine/get-github-commit-statuses',
			'method'   => 'handleCommitStatuses',
			'callback' => array( GitHubAbilities::class, 'getCommitStatuses' ),
			'required' => array( 'repo', 'sha' ),
			'params'   => array( 'repo' => 'Extra-Chill/data-machine-code', 'sha' => 'abc123' ),
		),
		'get_github_pull_review_context' => array(
			'ability'  => 'datamachine/get-github-pull-review-context',
			'method'   => 'handlePullReviewContext',
			'callback' => array( GitHubAbilities::class, 'getPullReviewContext' ),
			'required' => array( 'repo', 'pull_number' ),
			'optional' => array( 'head_sha', 'max_patch_chars', 'include_file_contents', 'include_base_contents', 'context_paths', 'max_file_content_chars', 'max_context_files', 'max_total_context_chars', 'include_checks', 'include_statuses', 'max_check_runs', 'include_check_output' ),
			'params'   => array(
				'repo'                    => 'Extra-Chill/data-machine-code',
				'pull_number'             => 89,
				'head_sha'                => 'abc123',
				'include_file_contents'   => true,
				'include_base_contents'   => true,
				'context_paths'           => array( 'README.md' ),
				'max_file_content_chars'  => 20000,
				'max_context_files'       => 10,
				'max_total_context_chars' => 100000,
				'include_checks'          => true,
				'include_statuses'        => true,
				'max_check_runs'          => 30,
				'include_check_output'    => false,
			),
		),
	);

	foreach ( $expected as $tool_name => $spec ) {
		$ability_name = $spec['ability'];
		$assert( "{$ability_name} ability is registered", isset( $GLOBALS['dmc_registered_abilities'][ $ability_name ] ) );
		$assert( "{$ability_name} delegates to GitHubAbilities", $spec['callback'] === ( $GLOBALS['dmc_registered_abilities'][ $ability_name ]['execute_callback'] ?? null ) );

		$tool = $tools->registered[ $tool_name ] ?? null;
		$assert( "{$tool_name} tool is registered", null !== $tool );
		$assert( "{$tool_name} is available in chat", in_array( 'chat', $tool['contexts'] ?? array(), true ) );
		$assert( "{$tool_name} is available in pipeline", in_array( 'pipeline', $tool['contexts'] ?? array(), true ) );
		$assert( "{$tool_name} records ability option", $ability_name === ( $tool['options']['ability'] ?? '' ) );

		$definition = call_user_func( $tool['definition_callback'] );
		$assert( "{$tool_name} definition uses narrow handler", $spec['method'] === ( $definition['method'] ?? '' ) );
		foreach ( $spec['required'] as $param ) {
			$assert( "{$tool_name} requires {$param}", true === ( $definition['parameters'][ $param ]['required'] ?? false ) );
		}
		foreach ( $spec['optional'] ?? array() as $param ) {
			$assert( "{$tool_name} exposes optional {$param}", array_key_exists( $param, $definition['parameters'] ?? array() ) );
			$assert( "{$tool_name} does not require optional {$param}", false === ( $definition['parameters'][ $param ]['required'] ?? false ) );
			$assert( "{$tool_name} ability schema exposes optional {$param}", array_key_exists( $param, $GLOBALS['dmc_registered_abilities'][ $ability_name ]['input_schema']['properties'] ?? array() ) );
		}

		$response = $tools->handle_tool_call( $spec['params'], $definition );
		$assert( "{$tool_name} handler returns success", true === ( $response['success'] ?? false ) );
		$assert( "{$tool_name} handler returns normalized data", isset( $response['data']['success'] ) && true === $response['data']['success'] );
		$assert( "{$tool_name} handler reports tool name", $tool_name === ( $response['tool_name'] ?? '' ) );
		$assert( "{$tool_name} executed registered ability", $spec['params'] === ( $GLOBALS['dmc_executed_abilities'][ $ability_name ] ?? array() ) );
		$assert( "{$tool_name} configuration check is wired", true === $tools->check_configuration( false, $tool_name ) );
	}

	$ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/GitHubAbilities.php' );
	$tool_source    = file_get_contents( __DIR__ . '/../inc/Tools/GitHubTools.php' );

	$assert( 'read-only tools do not expose file writes', ! str_contains( $tool_source, "'commit_message'" ) );
	$assert( 'get_github_file uses ref naming', str_contains( $ability_source, "'datamachine/get-github-file'" ) && str_contains( $tool_source, "'ref'" ) );
	$assert( 'list_github_tree supports optional path filtering', str_contains( $ability_source, '$path_prefix' ) );

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
