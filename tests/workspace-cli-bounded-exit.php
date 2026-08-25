<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {}
	}
}

namespace DataMachineCode\Abilities {
	class WorkspaceAbilities {
		public function __construct() {}
		public static function showRepo( array $input ): array {
			return array( 'name' => (string) $input['name'], 'path' => '/workspace/' . $input['name'], 'branch' => 'main', 'remote' => 'https://example.test/repo.git', 'commit' => 'abc123', 'dirty' => 0, 'is_worktree' => false );
		}
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public static function normalize_workspace_list_limit( mixed $limit ): int { return (int) $limit; }
		public static function workspace_hygiene_recovery_suggestion( array $capacity ): array { return array(); }
	}
	class WorktreeDiskBudget {
		public static function format_summary( array $capacity ): string { return ''; }
		public static function format_trigger_reasons( array $capacity ): array { return array(); }
	}
}

namespace {
	final class WP_CLI {
		/** @var array<string,mixed> */
		public static array $commands = array();
		/** @var list<string> */
		public static array $output = array();
		/** @param array<string,mixed> $args */
		public static function add_command( string $name, mixed $command, array $args = array() ): void { self::$commands[ $name ] = $command; }
		public static function line( string $message ): void { self::$output[] = $message; }
		public static function log( string $message ): void { self::$output[] = $message; }
		public static function warning( string $message ): void { self::$output[] = $message; }
		public static function error( string $message ): never { throw new \RuntimeException($message); }
	}

	/** @var array<string,array<int,array<int,callable>>> */
	$GLOBALS['dmc_bounded_exit_actions'] = array();

	function bounded_exit_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}
	function plugin_dir_path( string $file ): string { return dirname($file) . '/'; }
	function plugin_dir_url( string $file ): string { return 'https://example.test/'; }
	function register_activation_hook( string $file, callable|string $callback ): void {}
	function wp_installing(): bool { return false; }
	function did_action( string $hook ): int { return 0; }
	function add_filter( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void { add_action($hook, $callback, $priority, $accepted_args); }
	function add_action( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['dmc_bounded_exit_actions'][ $hook ][ $priority ][] = $callback;
	}
	function remove_all_actions( string $hook, int|false $priority = false ): bool {
		if ( false === $priority ) {
			unset($GLOBALS['dmc_bounded_exit_actions'][ $hook ]);
		} else {
			unset($GLOBALS['dmc_bounded_exit_actions'][ $hook ][ $priority ]);
		}
		return true;
	}
	function do_action( string $hook ): void {
		$priorities = array_keys($GLOBALS['dmc_bounded_exit_actions'][ $hook ] ?? array());
		sort($priorities, SORT_NUMERIC);
		foreach ( $priorities as $priority ) {
			foreach ( $GLOBALS['dmc_bounded_exit_actions'][ $hook ][ $priority ] ?? array() as $callback ) {
				call_user_func($callback);
			}
		}
	}
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode($value, $flags); }
	function is_wp_error( mixed $value ): bool { return false; }
	function wp_get_ability( string $name ): object {
		return new class {
			public function execute( array $input ): array {
				if ( 'repo' === ( $input['repo'] ?? null ) ) {
					return array( 'success' => true, 'worktrees' => array() );
				}
				return array( 'path' => '/workspace', 'total' => 1, 'returned' => 1, 'repos' => array( array( 'name' => 'repo', 'repo' => 'repo', 'git' => true, 'path' => '/workspace/repo' ) ) );
			}
		};
	}

	if ( 'child' === ( $argv[1] ?? '' ) ) {
		$mode = (string) ( $argv[2] ?? '' );
		$marker = (string) ( $argv[3] ?? '' );
		bounded_exit_assert(in_array($mode, array( 'success', 'failure', 'broken_pipe', 'embedded' ), true), 'Unknown lifecycle mode.');
		if ( 'embedded' !== $mode ) {
			define('WP_CLI', true);
		}
		define('WPINC', 'wp-includes');
		define('ABSPATH', __DIR__ . '/fixtures/');
		$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'list', 'repo' );
		require_once dirname(__DIR__) . '/data-machine-code.php';

		bounded_exit_assert(
			'embedded' === $mode ? ! datamachine_code_is_minimal_runtime_cli_request() : datamachine_code_is_minimal_runtime_cli_request(),
			'Minimal runtime scope did not match the execution mode.'
		);
		if ( 'embedded' === $mode ) {
			file_put_contents($marker, 'embedded-safe');
			exit(0);
		}

		// Mirrors WordPress' native shutdown bridge, followed by an unrelated native
		// cleanup callback that DMC must neither remove nor bypass.
		register_shutdown_function(static function (): void { do_action('shutdown'); });
		register_shutdown_function(static function () use ( $marker ): void { file_put_contents($marker, 'native-cleanup'); });
		datamachine_code_register_cli_commands();
		bounded_exit_assert(isset(WP_CLI::$commands['datamachine-code workspace']), 'Minimal runtime did not register the workspace command.');
		$command_class = WP_CLI::$commands['datamachine-code workspace'];
		$command = new $command_class();
		$started = microtime(true);
		$command->list_repos(array(), array());
		$command->show(array( 'repo' ), array());
		$worktree_list = WP_CLI::$commands['datamachine-code workspace worktree list'] ?? null;
		bounded_exit_assert(is_callable($worktree_list), 'Minimal runtime did not register the filtered worktree-list leaf command.');
		$worktree_list(array( 'repo' ), array());
		bounded_exit_assert(microtime(true) - $started < 0.5, 'Registered workspace list/show dispatch exceeded its bounded command deadline.');
		bounded_exit_assert(in_array('Name:     repo', WP_CLI::$output, true), 'Workspace show did not dispatch through the registered command.');
		if ( 'broken_pipe' === $mode ) {
			@fwrite(STDOUT, "buffered-output {$mode}\n");
			@fflush(STDOUT);
		} else {
			fwrite(STDOUT, "buffered-output {$mode}\n");
			fflush(STDOUT);
		}
		if ( 'failure' === $mode ) {
			// WP-CLI runs after_invoke after command output and before its final exit.
			fwrite(STDERR, 'after_invoke failure');
			exit(1);
		}
		exit(0);
	}

	foreach ( array( 'success' => 0, 'failure' => 1, 'broken_pipe' => 0, 'embedded' => 0 ) as $mode => $expected_status ) {
		$marker = tempnam(sys_get_temp_dir(), 'dmc-bounded-exit-');
		$process = proc_open(array( PHP_BINARY, __FILE__, 'child', $mode, $marker), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		bounded_exit_assert(is_resource($process), "Could not start {$mode} lifecycle process.");
		if ( 'broken_pipe' === $mode ) {
			fclose($pipes[1]);
			$output = '';
		} else {
			$output = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
		}
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$status = proc_close($process);

		bounded_exit_assert($expected_status === $status, "{$mode} lifecycle returned {$status}: {$error}");
		if ( 'embedded' === $mode ) {
			bounded_exit_assert('embedded-safe' === file_get_contents($marker), 'Embedded invocation was not left untouched.');
		} else {
			if ( 'broken_pipe' === $mode ) {
				bounded_exit_assert('' === $output, 'Broken-pipe parent unexpectedly retained child stdout.');
			} else {
				bounded_exit_assert(str_contains($output, "buffered-output {$mode}"), "{$mode} output was not flushed before shutdown.");
			}
			bounded_exit_assert('native-cleanup' === file_get_contents($marker), "{$mode} native cleanup was suppressed.");
		}
		unlink($marker);
	}

	echo "workspace-cli-bounded-exit: ok\n";
}
