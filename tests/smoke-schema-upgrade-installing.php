<?php
/**
 * Smoke test for schema upgrade bootstrap during WordPress install.
 *
 * Run: php tests/smoke-schema-upgrade-installing.php
 */

declare( strict_types=1 );

define('WPINC', 'wp-includes');
define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['datamachine_code_test_wp_installing']  = true;
$GLOBALS['datamachine_code_test_option_reads']   = 0;
$GLOBALS['datamachine_code_test_option_writes']  = 0;
$GLOBALS['datamachine_code_test_registered_hook'] = false;

function wp_installing(): bool
{
    return (bool) $GLOBALS['datamachine_code_test_wp_installing'];
}

function get_option( string $name, mixed $default = false ): mixed
{
    unset($name);
    ++$GLOBALS['datamachine_code_test_option_reads'];
    return $default;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool
{
    unset($name, $value, $autoload);
    ++$GLOBALS['datamachine_code_test_option_writes'];
    return true;
}

function register_activation_hook( string $file, callable|string $callback ): void
{
    unset($file, $callback);
}

function add_action( string $hook_name, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void
{
    unset($accepted_args);
    if ('plugins_loaded' === $hook_name && 'datamachine_code_maybe_upgrade_schema' === $callback && 5 === $priority ) {
        $GLOBALS['datamachine_code_test_registered_hook'] = true;
    }
}

function add_filter( string $hook_name, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void
{
    unset($hook_name, $callback, $priority, $accepted_args);
}

function plugin_dir_path( string $file ): string
{
    return dirname($file) . '/';
}

function plugin_dir_url( string $file ): string
{
    unset($file);
    return 'https://example.test/wp-content/plugins/data-machine-code/';
}

function datamachine_code_schema_upgrade_installing_assert( bool $condition, string $message ): void
{
    if ($condition ) {
        echo "  [PASS] {$message}\n";
        return;
    }

    echo "  [FAIL] {$message}\n";
    exit(1);
}

echo "=== smoke-schema-upgrade-installing ===\n";

require dirname(__DIR__) . '/data-machine-code.php';

datamachine_code_maybe_upgrade_schema();

datamachine_code_schema_upgrade_installing_assert(
    $GLOBALS['datamachine_code_test_registered_hook'],
    'schema upgrade hook stays registered'
);
datamachine_code_schema_upgrade_installing_assert(
    0 === $GLOBALS['datamachine_code_test_option_reads'],
    'install bootstrap skips schema option reads'
);
datamachine_code_schema_upgrade_installing_assert(
    0 === $GLOBALS['datamachine_code_test_option_writes'],
    'install bootstrap skips schema option writes'
);

echo "\nSchema upgrade installing smoke passed.\n";
