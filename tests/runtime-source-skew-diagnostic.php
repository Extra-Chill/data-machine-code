<?php

declare(strict_types=1);

namespace DataMachine\Cli { class BaseCommand {} }

namespace DataMachineCode\Abilities { class AbilityRegistry {} }

namespace {
	final class WP_CLI {
		/** @var list<string> */
		public static array $lines = array();
		public static function line( string $line ): void { self::$lines[] = $line; }
		public static function log( string $line ): void { self::$lines[] = $line; }
		public static function warning( string $line ): void { self::$lines[] = $line; }
		public static function error( string $line ): void { throw new RuntimeException($line); }
	}
	final class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	function apply_filters( string $hook, mixed $value ): mixed {
		return 'datamachine_code_runtime_identity_config' === $hook ? ( $GLOBALS['dmc_skew_config'] ?? $value ) : $value;
	}
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode($value, $flags); }

	$root = sys_get_temp_dir() . '/dmc-runtime-source-skew-' . bin2hex(random_bytes(6));
	define('ABSPATH', __DIR__ . '/fixtures/');
	define('DATAMACHINE_CODE_PATH', __DIR__ . '/fixtures/');
	define('DATAMACHINE_CODE_VERSION', '0.65.0');
	define('DATAMACHINE_WORKSPACE_PATH', $root . '/workspace');
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/RuntimeCommand.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function skew_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}
	function skew_tree( string $path, string $version, string $build ): void {
		if ( ! is_dir($path) ) { mkdir($path, 0777, true); }
		file_put_contents($path . '/data-machine-code.php', "<?php\n/*\n * Version: {$version}\n * Package-Source-Commit: {$build}\n */\n");
	}
	function skew_register( string $path ): void {
		mkdir($path . '/.git', 0777, true);
		file_put_contents($path . '/.git/config', "[remote \"origin\"]\n\turl = https://github.com/Extra-Chill/data-machine-code.git\n");
	}
	function skew_remove( string $path ): void {
		if (! is_dir($path)) { return; }
		foreach (scandir($path) ?: array() as $entry) { if ('.' !== $entry && '..' !== $entry) { $child = $path . '/' . $entry; is_dir($child) ? skew_remove($child) : unlink($child); } }
		rmdir($path);
	}

	try {
		$runtime   = $root . '/active-release';
		$workspace = $root . '/workspace';
		$source    = $workspace . '/data-machine-code';
		skew_tree($runtime, '0.65.0', 'active-build');
		skew_tree($source, '0.66.1', 'source-build');
		skew_register($source);
		for ($index = 0; $index < 500; ++$index) { mkdir($workspace . '/unrelated-' . $index); }
		$worktree_shaped = $workspace . '/data-machine-code@feature';
		skew_tree($worktree_shaped, '0.66.1', 'worktree-build');
		skew_register($worktree_shaped);
		$malformed = $workspace . '/malformed-primary';
		skew_tree($malformed, '0.66.1', 'malformed-build');
		mkdir($malformed . '/.git');
		$GLOBALS['dmc_skew_config'] = array(
			'runtime_file'     => $runtime . '/data-machine-code.php',
			'runtime_version'  => '0.65.0',
			'workspace_path'   => $workspace,
			'source_repository' => 'https://github.com/Extra-Chill/data-machine-code.git',
		);

		$ability = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		skew_assert('0.65.0' === ($ability['active_runtime']['version'] ?? null), 'Ability did not expose the loaded runtime version.');
		skew_assert('plugin_directory' === ($ability['active_runtime']['kind'] ?? null), 'Managed copied release was not identified independently from the source checkout.');
		skew_assert('0.66.1' === ($ability['managed_source']['version'] ?? null), 'Ability did not expose the managed source version.');
		skew_assert('data-machine-code' === ($ability['managed_source']['workspace_handle'] ?? null), 'Ability did not expose the registered source handle.');
		skew_assert('registered_workspace' === ($ability['managed_source']['authority'] ?? null), 'Registered workspace did not become the source authority.');
		skew_assert('resolved' === ($ability['source_resolution']['state'] ?? null), 'Registered source discovery did not resolve exactly one primary.');
		skew_assert(1 === count((array) ($ability['source_resolution']['candidates'] ?? array())), 'Worktree-shaped or malformed checkouts became source candidates.');
		skew_assert('older' === ($ability['skew']['classification'] ?? null), 'Active 0.65.0 versus source 0.66.1 was not typed as older.');
		skew_assert('runtime_refresh' === ($ability['skew']['recovery']['kind'] ?? null), 'Older runtime recovery did not distinguish runtime refresh.');
		skew_assert(true === ($ability['bounded'] ?? false), 'Diagnostic did not state its bounded inspection contract.');

		$hidden_workspace = $root . '/hidden-bound';
		mkdir($hidden_workspace);
		mkdir($hidden_workspace . '/.hidden-one');
		mkdir($hidden_workspace . '/.hidden-two');
		$hidden_bound = \DataMachineCode\Workspace\WorkspaceSourceResolver::discover($hidden_workspace, 'https://github.com/Extra-Chill/data-machine-code.git', 'data-machine-code.php', 1, 1.0);
		skew_assert('incomplete' === ($hidden_bound['state'] ?? null) && 1 === ($hidden_bound['scanned_entries'] ?? null), 'Hidden workspace entries bypassed the discovery entry bound.');

		$partial_workspace = $root . '/partial-bound';
		mkdir($partial_workspace);
		foreach ( array( 'one', 'two', 'three' ) as $handle ) {
			$partial_source = $partial_workspace . '/' . $handle;
			skew_tree($partial_source, '0.66.1', $handle . '-build');
			skew_register($partial_source);
		}
		$partial_ambiguity = \DataMachineCode\Workspace\WorkspaceSourceResolver::discover($partial_workspace, 'https://github.com/Extra-Chill/data-machine-code.git', 'data-machine-code.php', 2, 1.0);
		skew_assert('ambiguous' === ($partial_ambiguity['state'] ?? null) && false === ($partial_ambiguity['complete'] ?? true), 'Observed source ambiguity was downgraded when discovery reached its entry bound: ' . (string) wp_json_encode($partial_ambiguity));

		(new \DataMachineCode\Cli\Commands\RuntimeCommand())->identity(array(), array());
		$cli = json_decode(WP_CLI::$lines[0] ?? '', true);
		skew_assert('older' === ($cli['skew']['classification'] ?? null), 'CLI identity command did not render the skew diagnostic.');
		$restricted = array();
		$restricted_status = 1;
		$restricted_script = 'define("ABSPATH", ' . var_export(__DIR__ . '/fixtures/', true) . '); require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; $report = \\DataMachineCode\\Runtime\\RuntimeSourceSkewDiagnostic::inspect(' . var_export($runtime . '/data-machine-code.php', true) . ', "0.65.0", ' . var_export($source, true) . '); echo json_encode(array("exec_exists" => function_exists("exec"), "skew" => $report["skew"]["classification"]));';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Verifies a separate PHP runtime with exec disabled.
		exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=exec -r ' . escapeshellarg($restricted_script), $restricted, $restricted_status);
		$restricted_report = json_decode(implode("\n", $restricted), true);
		skew_assert(0 === $restricted_status && false === ($restricted_report['exec_exists'] ?? true), 'Restricted PHP fixture did not disable exec.');
		skew_assert('older' === ($restricted_report['skew'] ?? null), 'Restricted PHP fixture did not preserve version skew diagnostics.');
		file_put_contents($source . '/data-machine-code.php', "<?php\n/*\n * Version: 0.65.0\n */\n");
		$restricted = array();
		$restricted_status = 1;
		exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=exec -r ' . escapeshellarg($restricted_script), $restricted, $restricted_status); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Verifies incomparable build evidence without exec.
		$restricted_report = json_decode(implode("\n", $restricted), true);
		skew_assert(0 === $restricted_status && 'unknown' === ($restricted_report['skew'] ?? null), 'Restricted PHP fixture compared a source digest with a packaged commit as divergence.');

		$oversized = $root . '/oversized-source';
		mkdir($oversized);
		file_put_contents($oversized . '/data-machine-code.php', str_repeat('x', 1048577));
		$oversized_report = \DataMachineCode\Runtime\RuntimeSourceSkewDiagnostic::inspect($runtime . '/data-machine-code.php', '0.65.0', $oversized);
		skew_assert('entrypoint_size_unavailable' === ($oversized_report['managed_source']['reason'] ?? null) && 'unknown' === ($oversized_report['skew']['classification'] ?? null), 'Oversized source entrypoint bypassed the diagnostic read bound.');

		skew_tree($source, '0.65.0', 'active-build');
		$current = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		skew_assert('current' === ($current['skew']['classification'] ?? null), 'Matching runtime and source builds were not typed as current.');
		WP_CLI::$lines = array();
		$render = new ReflectionMethod(\DataMachineCode\Cli\Commands\WorkspaceCommand::class, 'render_workspace_error');
		try { $render->invoke(new \DataMachineCode\Cli\Commands\WorkspaceCommand(), new WP_Error('fixture_failure', 'fixture failure')); } catch (RuntimeException) {}
		skew_assert('' === implode("\n", WP_CLI::$lines), 'Current runtime/source evidence added a noisy workspace error prefix.');

		skew_tree($source, '0.64.0', 'older-source-build');
		$newer = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		skew_assert('newer' === ($newer['skew']['classification'] ?? null), 'A runtime newer than its source was not typed as newer.');

		skew_tree($source, '0.65.0', 'different-build');
		$diverged = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		skew_assert('diverged' === ($diverged['skew']['classification'] ?? null), 'Same-version different builds were not typed as diverged.');

		$duplicate = $workspace . '/data-machine-code-copy';
		skew_tree($duplicate, '0.65.0', 'duplicate-build');
		skew_register($duplicate);
		$ambiguous = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		$candidates = array_column((array) ($ambiguous['source_resolution']['candidates'] ?? array()), 'path');
		skew_assert('ambiguous' === ($ambiguous['skew']['classification'] ?? null), 'Duplicate registered primaries were not typed as ambiguous.');
		skew_assert(array($source, $duplicate) === $candidates, 'Ambiguity did not report the exact registered source candidates.');
		$GLOBALS['dmc_skew_config']['source_path'] = $duplicate;
		$explicit = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		skew_assert($duplicate === ($explicit['managed_source']['path'] ?? null) && 'diverged' === ($explicit['skew']['classification'] ?? null), 'Explicit source configuration did not take precedence over discovery ambiguity.');
		unset($GLOBALS['dmc_skew_config']['source_path']);
		WP_CLI::$lines = array();
		try { $render->invoke(new \DataMachineCode\Cli\Commands\WorkspaceCommand(), new WP_Error('fixture_failure', 'fixture failure')); } catch (RuntimeException) {}
		$ambiguity_evidence = implode("\n", WP_CLI::$lines);
		skew_assert(str_contains($ambiguity_evidence, 'Runtime/source: ambiguous'), 'Workspace failure evidence omitted source ambiguity.');
		skew_assert(str_contains($ambiguity_evidence, $source) && str_contains($ambiguity_evidence, $duplicate), 'Workspace failure evidence omitted exact ambiguous candidates.');
		WP_CLI::$lines = array();
		try { $render->invoke(new \DataMachineCode\Cli\Commands\WorkspaceCommand(), new WP_Error('fixture_failure', 'fixture failure'), 'json'); } catch (RuntimeException) {}
		skew_assert('' === implode("\n", WP_CLI::$lines), 'Machine-format workspace errors included an unstructured runtime/source prefix.');
		skew_remove($duplicate);

		skew_tree($source, '0.66.1', 'source-build');
		WP_CLI::$lines = array();
		try { $render->invoke(new \DataMachineCode\Cli\Commands\WorkspaceCommand(), new WP_Error('fixture_failure', 'fixture failure')); } catch (RuntimeException) {}
		$error_evidence = implode("\n", WP_CLI::$lines);
		skew_assert(str_contains($error_evidence, 'Active runtime: 0.65.0 (active-build)'), 'Workspace failure evidence omitted the active runtime identity.');
		skew_assert(str_contains($error_evidence, 'Runtime recovery: Refresh the active plugin'), 'Workspace failure evidence omitted runtime-refresh guidance.');

		skew_remove($source);
		WP_CLI::$lines = array();
		try { $render->invoke(new \DataMachineCode\Cli\Commands\WorkspaceCommand(), new WP_Error('fixture_failure', 'fixture failure')); } catch (RuntimeException) {}
		skew_assert('' === implode("\n", WP_CLI::$lines), 'Unknown source evidence added a noisy workspace error prefix.');
		echo "runtime-source-skew-diagnostic: ok\n";
	} finally {
		skew_remove($root);
	}
}
