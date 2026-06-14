<?php
/**
 * Smoke test that DMC core boots without Data Machine active.
 *
 * Run: php tests/smoke-standalone-bootstrap.php
 */

declare( strict_types=1 );

if ( ! defined('WPINC') ) {
	define('WPINC', __DIR__);
}
if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['dmc_standalone_actions']    = array();
$GLOBALS['dmc_standalone_filters']    = array();
$GLOBALS['dmc_standalone_did_action'] = array();
$GLOBALS['dmc_standalone_abilities']  = array();
$GLOBALS['dmc_standalone_categories'] = array();
$GLOBALS['dmc_standalone_options']    = array();

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

function dmc_standalone_add_hook( array &$store, string $hook, callable $callback, int $priority, int $accepted_args ): void {
	$store[ $hook ][ $priority ][] = compact('callback', 'accepted_args');
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	dmc_standalone_add_hook($GLOBALS['dmc_standalone_actions'], $hook, $callback, $priority, $accepted_args);
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	dmc_standalone_add_hook($GLOBALS['dmc_standalone_filters'], $hook, $callback, $priority, $accepted_args);
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['dmc_standalone_did_action'][ $hook ] = ( $GLOBALS['dmc_standalone_did_action'][ $hook ] ?? 0 ) + 1;
	$callbacks = $GLOBALS['dmc_standalone_actions'][ $hook ] ?? array();
	ksort($callbacks);
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $entry ) {
			call_user_func($entry['callback'], ...array_slice($args, 0, (int) $entry['accepted_args']));
		}
	}
}

function apply_filters( string $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['dmc_standalone_filters'][ $hook ] ?? array();
	ksort($callbacks);
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $entry ) {
			$value = call_user_func($entry['callback'], $value, ...array_slice($args, 0, (int) $entry['accepted_args'] - 1));
		}
	}
	return $value;
}

function did_action( string $hook ): int { return (int) ( $GLOBALS['dmc_standalone_did_action'][ $hook ] ?? 0 ); }
function doing_action( string $hook ): bool { return did_action($hook) > 0; }
function register_activation_hook( string $file, callable $callback ): void { unset($file, $callback); }
function plugin_dir_path( string $file ): string { return dirname($file) . '/'; }
function plugin_dir_url( string $file ): string { return 'https://example.test/plugins/' . basename(dirname($file)) . '/'; }
function wp_installing(): bool { return false; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['dmc_standalone_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, mixed $autoload = null ): bool { unset($autoload); $GLOBALS['dmc_standalone_options'][ $name ] = $value; return true; }
function current_user_can( string $capability ): bool { unset($capability); return true; }
function wp_register_ability_category( string $category, array $args ): void { $GLOBALS['dmc_standalone_categories'][ $category ] = $args; }
function wp_register_ability( string $ability, array $args ): void { $GLOBALS['dmc_standalone_abilities'][ $ability ] = $args; }
function wp_has_ability( string $ability ): bool { return isset($GLOBALS['dmc_standalone_abilities'][ $ability ]); }
function wp_json_encode( mixed $value ): string|false { return json_encode($value); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( mixed $value ): string { return trim((string) $value); }
function sanitize_key( mixed $value ): string { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value) ?? ''); }
function __( string $text, string $domain = 'default' ): string { unset($domain); return $text; }
function esc_html_e( string $text, string $domain = 'default' ): void { unset($domain); echo htmlspecialchars($text, ENT_QUOTES); }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url($url, $component); }

require __DIR__ . '/../data-machine-code.php';

do_action('plugins_loaded');
do_action('wp_abilities_api_categories_init');
do_action('wp_abilities_api_init');

$failures = array();
$assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
	echo ( $condition ? '  ok ' : '  fail ' ) . $label . "\n";
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

echo "Data Machine Code standalone bootstrap - smoke\n";

$assert('Data Machine PermissionHelper is absent', ! class_exists('DataMachine\\Abilities\\PermissionHelper'));
$assert('DMC version constant is defined', defined('DATAMACHINE_CODE_VERSION'));
$assert('workspace category registers', isset($GLOBALS['dmc_standalone_categories']['datamachine-code-workspace']));
$assert('GitHub category registers', isset($GLOBALS['dmc_standalone_categories']['datamachine-code-github']));
$assert('workspace ability registers', isset($GLOBALS['dmc_standalone_abilities']['datamachine-code/workspace-read']));
$assert('GitHub ability registers', isset($GLOBALS['dmc_standalone_abilities']['datamachine-code/list-github-issues']));
$assert('code task ability registers', isset($GLOBALS['dmc_standalone_abilities']['datamachine-code/create-code-task']));
$assert('permission facade allows core callbacks', \DataMachineCode\Support\PermissionHelper::can_manage());
$assert('settings facade falls back to options', 'fallback' === \DataMachineCode\Support\PluginSettings::get('missing_setting', 'fallback'));

if ( ! empty($failures) ) {
	echo "\nFAIL: " . count($failures) . " assertion(s) failed\n";
	exit(1);
}

echo "\nOK\n";
