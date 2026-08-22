<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace DataMachineCode\Workspace {
	function disk_free_space( string $path ): float|false {
		return $GLOBALS['dmc_test_disk_free_bytes'] ?? \disk_free_space($path);
	}

	function disk_total_space( string $path ): float|false {
		return $GLOBALS['dmc_test_disk_total_bytes'] ?? \disk_total_space($path);
	}
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
		/** @var array<string,array<string,mixed>> */
		public static array $command_args = array();
		/** @var list<string> */
		public static array $output = array();

		public static function add_command( string $name, mixed $command, array $args = array() ): void {
			self::$commands[ $name ] = $command;
			self::$command_args[ $name ] = $args;
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
	$GLOBALS['dmc_test_disk_free_bytes'] = null;
	$GLOBALS['dmc_test_disk_total_bytes'] = null;

	function startup_bounds_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	function startup_bounds_assert_process_stopped( int $pid ): void {
		$states = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Process-level regression coverage must verify the timed child exited.
		exec(sprintf('ps -o stat= -p %d', $pid), $states);
		startup_bounds_assert(empty($states) || str_starts_with(trim($states[0]), 'Z'), sprintf('Timed workspace probe process %d remained alive.', $pid));
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

	define('WP_CLI', true);
	define('WPINC', 'wp-includes');
	define('ABSPATH', $root . '/tests/fixtures/');
	define('DATAMACHINE_WORKSPACE_PATH', $workspace);
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'add', '--help' );

	require_once $root . '/data-machine-code.php';
	do_action('plugins_loaded');
	startup_bounds_assert(
		datamachine_code_is_targeted_workspace_read_cli_request(array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'get', 'repo@branch', '--format=json' )),
		'Targeted worktree get did not use the side-effect-free bootstrap classification.'
	);
	startup_bounds_assert(
		! datamachine_code_is_targeted_workspace_read_cli_request(array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'finalize', 'repo@branch', '--pr=https://github.com/example/repo/pull/1' )),
		'Worktree finalize must retain the normal mutable-service bootstrap path.'
	);

	startup_bounds_assert(isset(WP_CLI::$commands['datamachine-code workspace']), 'Nested help did not register the workspace command for WP-CLI dispatch.');
	startup_bounds_assert(isset(WP_CLI::$commands['datamachine-code runtime']), 'Nested help did not register the managed runtime command for WP-CLI dispatch.');
	startup_bounds_assert(isset(WP_CLI::$commands['datamachine-code workspace worktree add']), 'Operation-specific worktree help did not register the add command.');
	startup_bounds_assert(isset(WP_CLI::$commands['datamachine-code workspace worktree cleanup']), 'Operation-specific worktree help did not register the cleanup command.');
	foreach ( \DataMachineCode\Cli\Commands\WorkspaceCommand::worktree_command_definitions() as $operation => $definition ) {
		$name = 'datamachine-code workspace worktree ' . $operation;
		startup_bounds_assert(isset(WP_CLI::$commands[ $name ]), sprintf('WP-CLI did not register %s.', $operation));
		startup_bounds_assert($definition === ( WP_CLI::$command_args[ $name ] ?? null ), sprintf('WP-CLI did not receive the %s help contract.', $operation));
	}
	startup_bounds_assert(0 === $GLOBALS['dmc_test_get_option_calls'], 'Nested help initialized database-backed discovery.');
	startup_bounds_assert(0 === $GLOBALS['dmc_test_mutation_calls'], 'Nested help mutated schema or registry state.');
	startup_bounds_assert(class_exists(\DataMachineCode\Abilities\WorkspaceAbilities::class, false), 'Nested help did not schedule the bounded workspace ability surface.');
	foreach ( array(
		'DataMachineCode\\Abilities\\GitHubAbilities',
		'DataMachineCode\\Storage\\WorktreeInventoryRepository',
		'DataMachineCode\\Workspace\\Workspace',
		'DataMachineCode\\Workspace\\WorkspaceMutationLock',
		'DataMachineCode\\Support\\GitRunner',
		'DataMachineCode\\Workspace\\RemoteWorkspaceBackend',
	) as $service_class ) {
		startup_bounds_assert(! class_exists($service_class, false), sprintf('Nested help initialized %s.', $service_class));
	}

	// Dispatch the registered command exactly as WP-CLI does. A normal targeted
	// show must not depend on the full Abilities API bootstrap that was skipped
	// above.
	$GLOBALS['dmc_test_disk_total_bytes'] = (float) ( 100 * 1024 * 1024 * 1024 );
	$GLOBALS['dmc_test_disk_free_bytes']  = (float) ( 50 * 1024 * 1024 * 1024 );
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'show', 'target' );
	$before_entries = scandir($workspace);
	$started        = microtime(true);
	$command_class  = WP_CLI::$commands['datamachine-code workspace'];
	$command        = new $command_class();
	startup_bounds_assert(1 === \DataMachineCode\Workspace\WorkspaceTargetInspector::timeout_seconds('target'), 'Exact-target lookup must honor its bounded timeout policy.');
	$command->show(array( 'target' ), array());
	$elapsed        = microtime(true) - $started;
	startup_bounds_assert(in_array('Path:     ' . $workspace . '/target', WP_CLI::$output, true), 'Real workspace show dispatch returned the wrong path.');
	startup_bounds_assert($elapsed < 3.0, sprintf('Targeted show exceeded its startup bound: %.3fs.', $elapsed));
	startup_bounds_assert(0 === $GLOBALS['dmc_test_get_option_calls'], 'Existing local targeted show consulted registry or remote backend state.');
	startup_bounds_assert($before_entries === scandir($workspace), 'Targeted show changed workspace state.');
	startup_bounds_assert(! str_contains(implode("\n", WP_CLI::$output), 'Recovery (all commands are non-destructive):'), 'Normal targeted show rendered capacity recovery.');

	// Make capacity pressure deterministic while retaining the actual CLI ->
	// WorkspaceAbilities -> Workspace show path. The warning/refusal output may
	// only consume this already-measured capacity result, not bootstrap hygiene.
	for ( $index = 0; $index < 600; ++$index ) {
		mkdir($workspace . '/unrelated-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT));
	}
	$GLOBALS['dmc_test_disk_total_bytes'] = (float) ( 100 * 1024 * 1024 * 1024 );
	$GLOBALS['dmc_test_disk_free_bytes']  = (float) ( 15 * 1024 * 1024 * 1024 );
	WP_CLI::$output = array();
	$warning_options_before = $GLOBALS['dmc_test_get_option_calls'];
	$warning_started = microtime(true);
	$command->show(array( 'target' ), array());
	$warning_elapsed = microtime(true) - $warning_started;
	$warning_output = implode("\n", WP_CLI::$output);
	startup_bounds_assert(str_contains($warning_output, 'Recovery (all commands are non-destructive):'), 'Warning targeted show did not render the shared recovery suggestion.');
	startup_bounds_assert($warning_options_before === $GLOBALS['dmc_test_get_option_calls'], 'Warning targeted show bootstrapped hygiene inventory or remote state.');
	startup_bounds_assert($warning_elapsed < 3.0, sprintf('Warning targeted show exceeded its startup bound: %.3fs.', $warning_elapsed));
	startup_bounds_assert(! str_contains($warning_output, '--force'), 'Warning targeted show suggested bypassing capacity protection.');
	startup_bounds_assert(str_contains($warning_output, 'workspace hygiene --include-sizes --size-limit=100'), 'Warning targeted show did not emit bounded size inspection.');
	startup_bounds_assert(str_contains($warning_output, 'workspace hygiene --format=json'), 'Warning targeted show did not emit the generic hygiene next step.');
	startup_bounds_assert(! str_contains($warning_output, 'workspace worktree cleanup'), 'Warning targeted show inferred cleanup work without observed lane state.');
	startup_bounds_assert(! str_contains($warning_output, 'workspace worktree locks'), 'Warning targeted show inferred stale locks without observed lane state.');

	$GLOBALS['dmc_test_disk_free_bytes'] = (float) ( 5 * 1024 * 1024 * 1024 );
	WP_CLI::$output = array();
	$refusal_options_before = $GLOBALS['dmc_test_get_option_calls'];
	$refusal_started = microtime(true);
	$command->show(array( 'target' ), array());
	$refusal_elapsed = microtime(true) - $refusal_started;
	$refusal_output = implode("\n", WP_CLI::$output);
	startup_bounds_assert(str_contains($refusal_output, 'Recovery (all commands are non-destructive):'), 'Refused targeted show did not render the shared recovery suggestion.');
	startup_bounds_assert($refusal_options_before === $GLOBALS['dmc_test_get_option_calls'], 'Refused targeted show bootstrapped hygiene inventory or remote state.');
	startup_bounds_assert($refusal_elapsed < 3.0, sprintf('Refused targeted show exceeded its startup bound: %.3fs.', $refusal_elapsed));
	startup_bounds_assert(str_contains($refusal_output, 'workspace hygiene --include-sizes --size-limit=100'), 'Refused targeted show did not emit bounded size inspection.');
	startup_bounds_assert(str_contains($refusal_output, 'workspace hygiene --format=json'), 'Refused targeted show did not emit the generic hygiene next step.');
	startup_bounds_assert(! str_contains($refusal_output, 'workspace worktree cleanup'), 'Refused targeted show inferred cleanup work without observed lane state.');
	startup_bounds_assert(! str_contains($refusal_output, 'workspace worktree locks'), 'Refused targeted show inferred stale locks without observed lane state.');
	$GLOBALS['dmc_test_disk_free_bytes']  = null;
	$GLOBALS['dmc_test_disk_total_bytes'] = null;

	$stall_probe    = $workspace . '/stall-boundary.php';
	$stall_pid_file = $workspace . '/stall-pids';
	file_put_contents(
		$stall_probe,
		'<?php file_put_contents(' . var_export($stall_pid_file, true) . ', getmypid() . PHP_EOL, FILE_APPEND | LOCK_EX); while (true) { usleep(100000); }'
	);
	file_put_contents($stall_pid_file, '');
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
	startup_bounds_assert(is_array($remote['workspace_capacity'] ?? null), 'Remote show variant must attach measurable local workspace capacity.');
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
	startup_bounds_assert(is_array($context['workspace_capacity'] ?? null), 'Context show variant must attach measurable local workspace capacity.');
	unset($GLOBALS['dmc_test_filters']['datamachine_code_context_repositories']);
	$stall_pids = array_unique(array_map('intval', file($stall_pid_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()));
	startup_bounds_assert(3 === count($stall_pids), 'Startup-bound fixture did not record every injected stalled process.');
	foreach ( $stall_pids as $stall_pid ) {
		startup_bounds_assert_process_stopped($stall_pid);
	}

	startup_bounds_assert(! is_dir($workspace . '/.locks'), 'Read/help startup acquired a mutation lock.');
	startup_bounds_assert(0 === $GLOBALS['dmc_test_mutation_calls'], 'A failed startup probe left partial registry state.');

	startup_bounds_remove_tree($workspace);
	echo "workspace-command-startup-bounds: ok\n";
}
