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
	$GLOBALS['dmc_help_lifecycle_actions'] = array();
	$GLOBALS['dmc_help_lifecycle_calls'] = array( 'worker' => 0, 'transport' => 0, 'database' => 0 );

	function help_lifecycle_assert( bool $condition, string $message ): void {
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
		$GLOBALS['dmc_help_lifecycle_actions'][ $hook ][ $priority ][] = $callback;
	}
	function remove_all_actions( string $hook, int|false $priority = false ): bool {
		if ( false === $priority ) {
			unset($GLOBALS['dmc_help_lifecycle_actions'][ $hook ]);
		} else {
			unset($GLOBALS['dmc_help_lifecycle_actions'][ $hook ][ $priority ]);
		}
		return true;
	}
	function do_action( string $hook ): void {
		$priorities = array_keys($GLOBALS['dmc_help_lifecycle_actions'][ $hook ] ?? array());
		sort($priorities, SORT_NUMERIC);
		foreach ( $priorities as $priority ) {
			foreach ( $GLOBALS['dmc_help_lifecycle_actions'][ $hook ][ $priority ] ?? array() as $callback ) {
				call_user_func($callback);
			}
		}
	}
	function __( string $text, string $domain = '' ): string { return $text; }

	define('WP_CLI', true);
	define('WPINC', 'wp-includes');
	define('ABSPATH', __DIR__ . '/fixtures/');
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'list', '--help' );

	$root = dirname(__DIR__);
	require_once $root . '/data-machine-code.php';
	do_action('plugins_loaded');

	// These callbacks model the active Studio composition: Data Machine's queue
	// dispatcher plus coding-agent transport and SQLite shutdown work.
	add_action('shutdown', static function (): void { ++$GLOBALS['dmc_help_lifecycle_calls']['worker']; usleep(500000); }, 10);
	add_action('shutdown', static function (): void { ++$GLOBALS['dmc_help_lifecycle_calls']['transport']; usleep(500000); }, 10);
	add_action('shutdown', static function (): void { ++$GLOBALS['dmc_help_lifecycle_calls']['database']; usleep(500000); }, 10);

	$started = microtime(true);
	do_action('shutdown');
	$elapsed = microtime(true) - $started;
	help_lifecycle_assert($elapsed < 0.2, sprintf('Metadata shutdown exceeded its bounded deadline: %.3fs.', $elapsed));
	help_lifecycle_assert(array( 'worker' => 0, 'transport' => 0, 'database' => 0 ) === $GLOBALS['dmc_help_lifecycle_calls'], 'Metadata help ran an active runtime shutdown callback.');
	help_lifecycle_assert(array() === ($GLOBALS['dmc_help_lifecycle_actions']['shutdown'] ?? array()), 'Metadata help left a shutdown callback registered.');
	help_lifecycle_assert(isset(WP_CLI::$commands['datamachine-code workspace']), 'Metadata help did not retain the WP-CLI command registration.');

	// Executable requests keep the ordinary WordPress shutdown lifecycle.
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'list' );
	add_action('shutdown', static function (): void { ++$GLOBALS['dmc_help_lifecycle_calls']['worker']; }, 10);
	do_action('shutdown');
	help_lifecycle_assert(1 === $GLOBALS['dmc_help_lifecycle_calls']['worker'], 'Executable CLI request lost its shutdown lifecycle.');

	echo "workspace-help-cli-lifecycle: ok\n";
}
