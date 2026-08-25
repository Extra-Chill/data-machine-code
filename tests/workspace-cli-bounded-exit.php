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
		/** @var array<string,array<string,mixed>> */
		public static array $command_args = array();
		/** @var list<string> */
		public static array $output = array();
		/** @param array<string,mixed> $args */
		public static function add_command( string $name, mixed $command, array $args = array() ): void { self::$commands[ $name ] = $command; self::$command_args[ $name ] = $args; }
		public static function line( string $message ): void { self::$output[] = $message; @fwrite(STDOUT, $message . "\n"); }
		public static function log( string $message ): void { self::line($message); }
		public static function success( string $message ): void { self::line('Success: ' . $message); }
		public static function warning( string $message ): void { self::$output[] = $message; @fwrite(STDERR, 'Warning: ' . $message . "\n"); }
		public static function error( string $message ): never { throw new \RuntimeException($message); }
	}

	final class BoundedExitWpdb {
		public bool $connected = true;
		public function __construct( private string $marker ) {}
		public function close(): bool {
			$this->connected = false;
			file_put_contents($this->marker . '.db', 'closed');
			return true;
		}
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
				if ( 'repo@merged-branch' === ( $input['handle'] ?? null ) ) {
					bounded_exit_assert('https://github.com/example/repo/pull/123' === ($input['pr'] ?? null), 'Finalize did not preserve the merged PR URL.');
					bounded_exit_assert('success' === ($input['owner_terminal_outcome'] ?? null), 'Finalize did not preserve the owner terminal outcome.');
					file_put_contents((string) $GLOBALS['dmc_bounded_exit_marker'], 'committed');
					return array(
						'success' => true,
						'handle' => 'repo@merged-branch',
						'lifecycle_state' => 'pr_opened',
						'metadata' => array( 'pr_url' => (string) $input['pr'], 'owner_terminal_outcome' => 'success' ),
						'message' => 'Worktree "repo@merged-branch" marked pr_opened.',
					);
				}
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
		$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'finalize', 'repo@merged-branch', '--pr=https://github.com/example/repo/pull/123', '--owner-terminal-outcome=success', '--format=json' );
		$GLOBALS['dmc_bounded_exit_marker'] = $marker;
		require_once dirname(__DIR__) . '/data-machine-code.php';

		bounded_exit_assert(
			'embedded' === $mode ? ! datamachine_code_is_minimal_runtime_cli_request() : datamachine_code_is_minimal_runtime_cli_request(),
			'Minimal runtime scope did not match the execution mode.'
		);
		if ( 'embedded' === $mode ) {
			file_put_contents($marker, 'embedded-safe');
			exit(0);
		}

		$GLOBALS['wpdb'] = new BoundedExitWpdb($marker);
		// Mirrors WordPress' native shutdown bridge. A later native owner remains
		// safe, but would retain the process while the request-owned DB stays open.
		register_shutdown_function(static function (): void { do_action('shutdown'); });
		register_shutdown_function(static function () use ( $marker, $mode ): void {
			if ( 'success' === $mode && $GLOBALS['wpdb']->connected ) {
				usleep(1500000);
			}
			file_put_contents($marker . '.native', 'native-cleanup');
		});
		datamachine_code_register_cli_commands();
		$command_name = 'datamachine-code workspace worktree finalize';
		$finalize = WP_CLI::$commands[ $command_name ] ?? null;
		$after_invoke = WP_CLI::$command_args[ $command_name ]['after_invoke'] ?? null;
		bounded_exit_assert(is_callable($finalize) && is_callable($after_invoke), 'Finalize leaf did not register its bounded owning-layer callback.');
		$finalize(array( 'repo@merged-branch' ), array( 'pr' => 'https://github.com/example/repo/pull/123', 'owner-terminal-outcome' => 'success', 'format' => 'json' ));
		if ( 'failure' === $mode ) {
			// WP-CLI runs after_invoke after command output and before its final exit.
			fwrite(STDERR, 'after_invoke failure');
			exit(1);
		}
		$after_invoke();
		exit(0);
	}

	foreach ( array( 'success' => 0, 'failure' => 1, 'broken_pipe' => 0, 'embedded' => 0 ) as $mode => $expected_status ) {
		$marker = tempnam(sys_get_temp_dir(), 'dmc-bounded-exit-');
		$started = microtime(true);
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
		$elapsed = microtime(true) - $started;

		bounded_exit_assert($expected_status === $status, "{$mode} lifecycle returned {$status}: {$error}");
		if ( 'embedded' === $mode ) {
			bounded_exit_assert('embedded-safe' === file_get_contents($marker), 'Embedded invocation was not left untouched.');
		} else {
			if ( 'broken_pipe' === $mode ) {
				bounded_exit_assert('' === $output, 'Broken-pipe parent unexpectedly retained child stdout.');
			} else {
				bounded_exit_assert(str_contains($output, 'Success: Worktree "repo@merged-branch" marked pr_opened.'), "{$mode} finalizer receipt was not flushed before shutdown.");
			}
			bounded_exit_assert('committed' === file_get_contents($marker), "{$mode} finalizer mutation did not commit before output.");
			bounded_exit_assert('native-cleanup' === file_get_contents($marker . '.native'), "{$mode} native cleanup was suppressed.");
			if ( 'failure' === $mode ) {
				bounded_exit_assert(! file_exists($marker . '.db'), 'A failure before after_invoke unexpectedly closed the database.');
			} else {
				bounded_exit_assert('closed' === file_get_contents($marker . '.db'), "{$mode} database connection survived successful output.");
				bounded_exit_assert($elapsed < 0.5, sprintf('%s exceeded the bounded process deadline: %.3fs.', $mode, $elapsed));
			}
			unlink($marker . '.native');
			if ( file_exists($marker . '.db') ) {
				unlink($marker . '.db');
			}
		}
		unlink($marker);
	}

	echo "workspace-cli-bounded-exit: ok\n";
}
