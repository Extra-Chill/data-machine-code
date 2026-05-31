<?php
/**
 * Smoke test that DMC bundle artifact hooks register at plugin load time.
 *
 * Run: php tests/smoke-bundle-artifact-early-registration.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$filters = array();
$actions = array();

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function register_activation_hook( string $file, callable|string $callback ): void {
	unset( $file, $callback );
}

function add_action( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $actions;
	$actions[ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function add_filter( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $filters;
	$filters[ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	global $filters;
	if ( empty( $filters[ $hook ] ) ) {
		return $value;
	}

	ksort( $filters[ $hook ] );
	foreach ( $filters[ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, (int) $callback[1] ) );
		}
	}

	return $value;
}

require __DIR__ . '/../data-machine-code.php';

$types = apply_filters( 'datamachine_agent_bundle_artifact_types', array( 'agent' ) );
$ok    = in_array( 'datamachine-code/workspace_preload', $types, true );

echo 'Bundle artifact early registration - smoke' . PHP_EOL;
echo ( $ok ? '  ok registers workspace preload artifact before bootstrap' : '  fail registers workspace preload artifact before bootstrap' ) . PHP_EOL;
echo PHP_EOL . 'Result: ' . ( $ok ? '1/1 passed' : '0/1 passed' ) . PHP_EOL;

exit( $ok ? 0 : 1 );
