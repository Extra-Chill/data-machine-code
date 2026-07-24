<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private mixed $data = null
		) {}

		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	final class WP_CLI {
		/** @var array<string,string> */
		public static array $commands = array();
		/** @var list<string> */
		public static array $output = array();

		public static function add_command( string $name, string $class ): void {
			self::$commands[ $name ] = $class;
		}

		public static function log( string $message ): void { self::$output[] = $message; }
		public static function warning( string $message ): void { self::$output[] = $message; }
		public static function success( string $message ): void { self::$output[] = $message; }
		public static function error( string $message ): void { throw new \RuntimeException($message); }
	}

	/** @var array<string,array<int,array{priority:int,callback:callable}>> */
	$GLOBALS['dmc_test_actions'] = array();
	$GLOBALS['dmc_test_get_option_calls'] = 0;
	$GLOBALS['dmc_test_mutation_calls'] = 0;
	$GLOBALS['dmc_test_options'] = array();
	$GLOBALS['dmc_test_filters'] = array();

	function startup_bounds_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function plugin_dir_path( string $file ): string { return dirname($file) . '/'; }
	function plugin_dir_url( string $file ): string { return 'https://example.test/' . basename(dirname($file)) . '/'; }
	function register_activation_hook( string $file, callable|string $callback ): void {}
	function wp_installing(): bool { return false; }
	function add_action( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['dmc_test_actions'][ $hook ][] = array( 'priority' => $priority, 'callback' => $callback );
	}
	function add_filter( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action($hook, $callback, $priority, $accepted_args);
	}
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( 'datamachine_code_workspace_target_lookup_timeout_seconds' === $hook ) {
			return 1;
		}
		if ( array_key_exists($hook, $GLOBALS['dmc_test_filters']) ) {
			return $GLOBALS['dmc_test_filters'][ $hook ];
		}
		return $value;
	}
	function do_action( string $hook, mixed ...$args ): void {
		$callbacks = $GLOBALS['dmc_test_actions'][ $hook ] ?? array();
		usort($callbacks, static fn ( array $left, array $right ): int => $left['priority'] <=> $right['priority']);
		foreach ( $callbacks as $entry ) {
			call_user_func_array($entry['callback'], $args);
		}
	}
	function did_action( string $hook ): int { return 0; }
	function get_option( string $key, mixed $default = false ): mixed {
		++$GLOBALS['dmc_test_get_option_calls'];
		return $GLOBALS['dmc_test_options'][ $key ] ?? $default;
	}
	function update_option( string $key, mixed $value, bool $autoload = false ): bool {
		++$GLOBALS['dmc_test_mutation_calls'];
		$GLOBALS['dmc_test_options'][ $key ] = $value;
		return true;
	}
	function __( string $text, string $domain = '' ): string { return $text; }

	function startup_bounds_remove_tree( string $path ): void {
		if ( ! is_dir($path) ) {
			return;
		}
		foreach ( scandir($path) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $path . '/' . $entry;
			is_dir($child) ? startup_bounds_remove_tree($child) : unlink($child);
		}
		rmdir($path);
	}

	$root      = dirname(__DIR__);
	$workspace = sys_get_temp_dir() . '/dmc-startup-bounds-' . bin2hex(random_bytes(6));
	mkdir($workspace, 0777, true);
	mkdir($workspace . '/target', 0777, true);
	for ( $index = 0; $index < 600; ++$index ) {
		mkdir($workspace . '/unrelated-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT));
	}

	define('WP_CLI', true);
	define('WPINC', 'wp-includes');
	define('ABSPATH', $root . '/tests/fixtures/');
	define('DATAMACHINE_WORKSPACE_PATH', $workspace);
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'add', '--help' );

	require_once $root . '/data-machine-code.php';
	do_action('plugins_loaded');

	startup_bounds_assert(isset(WP_CLI::$commands['datamachine-code workspace']), 'Nested help did not register the workspace command for WP-CLI dispatch.');
	startup_bounds_assert(0 === $GLOBALS['dmc_test_get_option_calls'], 'Nested help initialized database-backed discovery.');
	startup_bounds_assert(0 === $GLOBALS['dmc_test_mutation_calls'], 'Nested help mutated schema or registry state.');
	foreach ( array(
		'DataMachineCode\\Abilities\\WorkspaceAbilities',
		'DataMachineCode\\Abilities\\GitHubAbilities',
		'DataMachineCode\\Storage\\WorktreeInventoryRepository',
		'DataMachineCode\\Workspace\\Workspace',
		'DataMachineCode\\Workspace\\WorkspaceMutationLock',
		'DataMachineCode\\Support\\GitRunner',
		'DataMachineCode\\Workspace\\RemoteWorkspaceBackend',
	) as $service_class ) {
		startup_bounds_assert(! class_exists($service_class, false), sprintf('Nested help initialized %s.', $service_class));
	}

	// Dispatch the registered command exactly as WP-CLI does. Targeted show must
	// not depend on the full Abilities API bootstrap that was skipped above.
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'show', 'target' );
	$before_entries = scandir($workspace);
	$started        = microtime(true);
	$command_class  = WP_CLI::$commands['datamachine-code workspace'];
	$command        = new $command_class();
	$command->show(array( 'target' ), array());
	$elapsed        = microtime(true) - $started;
	startup_bounds_assert(in_array('Path:     ' . $workspace . '/target', WP_CLI::$output, true), 'Real workspace show dispatch returned the wrong path.');
	startup_bounds_assert($elapsed < 3.0, sprintf('Targeted show exceeded its startup bound: %.3fs.', $elapsed));
	startup_bounds_assert(0 === $GLOBALS['dmc_test_get_option_calls'], 'Existing local targeted show consulted registry or remote backend state.');
	startup_bounds_assert($before_entries === scandir($workspace), 'Targeted show changed workspace state.');

	$stall_probe = $workspace . '/stall-boundary.php';
	file_put_contents(
		$stall_probe,
		'<?php while (true) { usleep(100000); }'
	);
	$state_before_failures = scandir($workspace);
	foreach ( array(
		'filesystem' => 'datamachine_code_workspace_target_filesystem_probe_command',
		'git'        => 'datamachine_code_workspace_target_git_command',
	) as $phase => $filter ) {
		$GLOBALS['dmc_test_filters'][ $filter ] = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stall_probe);
		$started = microtime(true);
		$error   = \DataMachineCode\Workspace\WorkspaceTargetInspector::inspect($workspace . '/target', 'target');
		$elapsed = microtime(true) - $started;
		startup_bounds_assert(is_wp_error($error), sprintf('Stalled %s dependency did not return an error.', $phase));
		startup_bounds_assert('workspace_target_lookup_timeout' === $error->get_error_code(), sprintf('Stalled %s dependency returned an untyped error.', $phase));
		$data = $error->get_error_data();
		startup_bounds_assert($phase === ( $data['phase'] ?? null ), sprintf('Stalled %s diagnostic lost its blocked phase.', $phase));
		startup_bounds_assert($workspace . '/target' === ( $data['resource'] ?? null ), sprintf('Stalled %s diagnostic lost its resource.', $phase));
		startup_bounds_assert('workspace-show' === ( $data['probe_owner'] ?? null ), sprintf('Stalled %s diagnostic lost its probe owner.', $phase));
		startup_bounds_assert(('filesystem' === $phase ? 'is_dir' : 'branch') === ( $data['operation'] ?? null ), sprintf('Stalled %s diagnostic lost its operation.', $phase));
		startup_bounds_assert($elapsed < 2.5, sprintf('Stalled %s dependency exceeded its bound: %.3fs.', $phase, $elapsed));
		unset($GLOBALS['dmc_test_filters'][ $filter ]);
	}
	startup_bounds_assert($state_before_failures === scandir($workspace), 'A failed startup probe left partial worktree state.');

	// Exercise the actual option/database and remote-backend boundaries. A local
	// miss and a context alias must still resolve registered remote state.
	$GLOBALS['dmc_test_options']['datamachine_code_remote_workspace_state'] = array(
		'repos' => array(
			'remote-target' => array( 'repo' => 'owner/remote-target', 'url' => 'https://github.com/owner/remote-target.git' ),
			'timeout-target' => array( 'repo' => 'owner/timeout-target', 'url' => 'https://github.com/owner/timeout-target.git' ),
		),
		'repo_names' => array(
			'owner/remote-target'  => 'remote-target',
			'owner/timeout-target' => 'timeout-target',
		),
		'worktrees' => array(),
	);
	$database_calls = $GLOBALS['dmc_test_get_option_calls'];
	$remote         = \DataMachineCode\Abilities\WorkspaceAbilities::showRepo(array( 'name' => 'remote-target' ));
	startup_bounds_assert(! is_wp_error($remote) && 'github_api' === ( $remote['backend'] ?? null ), 'Local miss did not preserve registered remote workspace behavior.');
	startup_bounds_assert($GLOBALS['dmc_test_get_option_calls'] > $database_calls, 'Remote show did not cross the database option boundary.');

	mkdir($workspace . '/timeout-target');
	$GLOBALS['dmc_test_filters']['datamachine_code_workspace_target_git_command'] = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stall_probe);
	$timed_out_local = \DataMachineCode\Abilities\WorkspaceAbilities::showRepo(array( 'name' => 'timeout-target' ));
	startup_bounds_assert(! is_wp_error($timed_out_local) && 'github_api' === ( $timed_out_local['backend'] ?? null ), 'Bounded local timeout did not preserve registered remote workspace behavior.');
	unset($GLOBALS['dmc_test_filters']['datamachine_code_workspace_target_git_command']);

	$GLOBALS['dmc_test_filters']['datamachine_code_context_repositories'] = array(
		'remote-context' => array( 'alias' => 'remote-context', 'repo' => 'owner/remote-target', 'target' => 'remote-target', 'ref' => 'main' ),
	);
	$context = \DataMachineCode\Abilities\WorkspaceAbilities::showRepo(array( 'name' => 'remote-context' ));
	startup_bounds_assert(! is_wp_error($context) && 'github_api' === ( $context['backend'] ?? null ), 'Context alias did not preserve registered remote workspace behavior.');
	startup_bounds_assert(! empty($context['is_context']), 'Remote context alias lost its context policy.');
	unset($GLOBALS['dmc_test_filters']['datamachine_code_context_repositories']);

	startup_bounds_assert(! is_dir($workspace . '/.locks'), 'Read/help startup acquired a mutation lock.');
	startup_bounds_assert(0 === $GLOBALS['dmc_test_mutation_calls'], 'A failed startup probe left partial registry state.');

	startup_bounds_remove_tree($workspace);
	echo "workspace-command-startup-bounds: ok\n";
}
