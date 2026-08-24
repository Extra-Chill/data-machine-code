<?php

declare(strict_types=1);

namespace DataMachine\Cli { class BaseCommand {} }

namespace DataMachineCode\Abilities { class AbilityRegistry {} }

namespace DataMachineCode\Support { class RuntimeCapabilities {} }

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
		return $GLOBALS['dmc_skew_config'] ?? $value;
	}
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode($value, $flags); }

	define('ABSPATH', __DIR__ . '/fixtures/');
	define('DATAMACHINE_CODE_PATH', __DIR__ . '/fixtures/');
	define('DATAMACHINE_CODE_VERSION', '0.65.0');
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/RuntimeCommand.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function skew_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}
	function skew_tree( string $path, string $version, string $build ): void {
		mkdir($path, 0777, true);
		file_put_contents($path . '/data-machine-code.php', "<?php\n/*\n * Version: {$version}\n * Package-Source-Commit: {$build}\n */\n");
	}
	function skew_remove( string $path ): void {
		if (! is_dir($path)) { return; }
		foreach (scandir($path) ?: array() as $entry) { if ('.' !== $entry && '..' !== $entry) { $child = $path . '/' . $entry; is_dir($child) ? skew_remove($child) : unlink($child); } }
		rmdir($path);
	}

	$root = sys_get_temp_dir() . '/dmc-runtime-source-skew-' . bin2hex(random_bytes(6));
	try {
		$runtime = $root . '/active';
		$source = $root . '/source';
		skew_tree($runtime, '0.65.0', 'active-build');
		skew_tree($source, '0.66.1', 'source-build');
		for ($index = 0; $index < 500; ++$index) { mkdir($root . '/unrelated-' . $index); }
		$GLOBALS['dmc_skew_config'] = array( 'runtime_file' => $runtime . '/data-machine-code.php', 'runtime_version' => '0.65.0', 'source_path' => $source );

		$ability = \DataMachineCode\Abilities\WorkspaceAbilities::runtimeIdentity(array());
		skew_assert('0.65.0' === ($ability['active_runtime']['version'] ?? null), 'Ability did not expose the loaded runtime version.');
		skew_assert('0.66.1' === ($ability['managed_source']['version'] ?? null), 'Ability did not expose the managed source version.');
		skew_assert('older' === ($ability['skew']['classification'] ?? null), 'Active 0.65.0 versus source 0.66.1 was not typed as older.');
		skew_assert('runtime_refresh' === ($ability['skew']['recovery']['kind'] ?? null), 'Older runtime recovery did not distinguish runtime refresh.');
		skew_assert(true === ($ability['bounded'] ?? false), 'Diagnostic did not state its bounded inspection contract.');

		(new \DataMachineCode\Cli\Commands\RuntimeCommand())->identity(array(), array());
		$cli = json_decode(WP_CLI::$lines[0] ?? '', true);
		skew_assert('older' === ($cli['skew']['classification'] ?? null), 'CLI identity command did not render the skew diagnostic.');
		WP_CLI::$lines = array();
		$render = new ReflectionMethod(\DataMachineCode\Cli\Commands\WorkspaceCommand::class, 'render_workspace_error');
		try { $render->invoke(new \DataMachineCode\Cli\Commands\WorkspaceCommand(), new WP_Error('fixture_failure', 'fixture failure')); } catch (RuntimeException) {}
		$error_evidence = implode("\n", WP_CLI::$lines);
		skew_assert(str_contains($error_evidence, 'Active runtime: 0.65.0 (active-build)'), 'Workspace failure evidence omitted the active runtime identity.');
		skew_assert(str_contains($error_evidence, 'Runtime recovery: Refresh the active plugin'), 'Workspace failure evidence omitted runtime-refresh guidance.');
		echo "runtime-source-skew-diagnostic: ok\n";
	} finally {
		skew_remove($root);
	}
}
