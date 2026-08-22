<?php

declare(strict_types=1);

namespace DataMachineCode\Abilities {
	final class WorkspaceAbilities {
		public function __construct() {
			++$GLOBALS['dmc_minimal_workspace_abilities'];
		}
	}

	final class GitHubAbilities {
		public function __construct() {
			++$GLOBALS['dmc_minimal_github_abilities'];
		}
	}

	final class WorkspaceDiffAbilities {
		public function __construct() {
			++$GLOBALS['dmc_minimal_workspace_diff_abilities'];
		}
	}

	final class CodeTaskAbilities {
		public function __construct() {
			++$GLOBALS['dmc_minimal_code_task_abilities'];
		}
	}

	final class WordPressRuntimeAbilities {
		public function __construct() {
			++$GLOBALS['dmc_minimal_wordpress_runtime_abilities'];
		}
	}
}

namespace {
	function minimal_runtime_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
		}
	}

	function plugin_dir_path( string $file ): string { return dirname($file) . '/'; }
	function plugin_dir_url( string $file ): string { return 'https://example.test/'; }
	function register_activation_hook( string $file, callable|string $callback ): void {}
	function add_action( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function add_filter( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function did_action( string $hook ): int { return 0; }

	define('WP_CLI', true);
	define('WPINC', 'wp-includes');
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'add', 'fixture', 'fix/example' );
	$GLOBALS['dmc_minimal_workspace_abilities'] = 0;
	$GLOBALS['dmc_minimal_github_abilities'] = 0;
	$GLOBALS['dmc_minimal_workspace_diff_abilities'] = 0;
	$GLOBALS['dmc_minimal_code_task_abilities'] = 0;
	$GLOBALS['dmc_minimal_wordpress_runtime_abilities'] = 0;

	require_once dirname(__DIR__) . '/data-machine-code.php';
	datamachine_code_bootstrap();
	datamachine_code_bootstrap();

	minimal_runtime_assert_same(1, $GLOBALS['dmc_minimal_workspace_abilities'], 'Executable workspace CLI must register WorkspaceAbilities exactly once.');
	minimal_runtime_assert_same(0, $GLOBALS['dmc_minimal_github_abilities'], 'Minimal workspace CLI must not register GitHub abilities.');
	minimal_runtime_assert_same(0, $GLOBALS['dmc_minimal_workspace_diff_abilities'], 'Minimal workspace CLI must not register workspace diff abilities.');
	minimal_runtime_assert_same(0, $GLOBALS['dmc_minimal_code_task_abilities'], 'Minimal workspace CLI must not register code task abilities.');
	minimal_runtime_assert_same(0, $GLOBALS['dmc_minimal_wordpress_runtime_abilities'], 'Minimal workspace CLI must not register WordPress runtime abilities.');

	echo "workspace-minimal-runtime-abilities: ok\n";
}
