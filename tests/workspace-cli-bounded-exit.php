<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_CLI {
		/** @var array<string,mixed> */
		public static array $commands = array();
		/** @param array<string,mixed> $args */
		public static function add_command( string $name, mixed $command, array $args = array() ): void { self::$commands[ $name ] = $command; }
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

	if ( 'child' === ( $argv[1] ?? '' ) ) {
		$operation = (string) ( $argv[2] ?? '' );
		$marker    = (string) ( $argv[3] ?? '' );
		$commands  = array(
			'show'     => array( 'wp', 'datamachine-code', 'workspace', 'show', 'data-machine-code' ),
			'finalize' => array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'finalize', 'data-machine-code@issue-1068', '--pr=https://example.test/pr/1068' ),
			'remove'   => array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'remove', 'data-machine-code@issue-1068' ),
			'locks'    => array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'locks', '--format=json' ),
			'list'     => array( 'wp', 'datamachine-code', 'workspace', 'list', '--limit=1' ),
		);
		bounded_exit_assert(isset($commands[ $operation ]), 'Unknown executable workspace command.');

		define('WP_CLI', true);
		define('WPINC', 'wp-includes');
		define('ABSPATH', __DIR__ . '/fixtures/');
		$GLOBALS['argv'] = $commands[ $operation ];
		require_once dirname(__DIR__) . '/data-machine-code.php';
		do_action('plugins_loaded');
		bounded_exit_assert(isset(WP_CLI::$commands['datamachine-code workspace']), 'Workspace command was not registered.');

		if ( 'finalize' === $operation ) {
			file_put_contents($marker, 'committed');
		}
		fwrite(STDOUT, "output-complete {$operation}\n");
		fflush(STDOUT);
		add_action('shutdown', static function (): void { usleep(1500000); }, 10);
		do_action('shutdown');
		exit(0);
	}

	$operations = array( 'show', 'finalize', 'remove', 'locks', 'list' );
	foreach ( $operations as $operation ) {
		$marker = tempnam(sys_get_temp_dir(), 'dmc-bounded-exit-');
		$start  = microtime(true);
		$process = proc_open(array( PHP_BINARY, __FILE__, 'child', $operation, $marker ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		bounded_exit_assert(is_resource($process), "Could not start {$operation} lifecycle process.");
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status  = proc_close($process);
		$elapsed = microtime(true) - $start;

		bounded_exit_assert(0 === $status, "{$operation} lifecycle process failed: {$error}");
		bounded_exit_assert(str_contains($output, "output-complete {$operation}"), "{$operation} did not complete output.");
		bounded_exit_assert($elapsed < 0.5, sprintf('%s exceeded the bounded post-output exit deadline: %.3fs.', $operation, $elapsed));
		if ( 'finalize' === $operation ) {
			bounded_exit_assert('committed' === file_get_contents($marker), 'Finalize did not durably commit before its output-complete boundary.');
		}
		unlink($marker);
	}

	echo "workspace-cli-bounded-exit: ok\n";
}
