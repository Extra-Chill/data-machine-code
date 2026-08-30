<?php
/**
 * JSON CLI stdout must remain strict-parseable when PHP emits diagnostics.
 */

declare(strict_types=1);

if ( 'child' === ( $argv[1] ?? '' ) ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
	define('WP_CLI', true);

	require_once dirname(__DIR__) . '/inc/Cli/CliJsonStdoutGuard.php';

	ini_set('display_errors', '1');
	error_reporting(E_ALL);

	$installed = DataMachineCode\Cli\CliJsonStdoutGuard::install(
		array( 'wp', 'datamachine-code', 'workspace', 'show', 'homeboy', '--format=json' )
	);
	if ( true !== $installed ) {
		fwrite(STDERR, "guard-not-installed\n");
		exit(2);
	}

	trigger_error('json-stdout-guard-probe', E_USER_DEPRECATED);
	fwrite(STDOUT, "{\"success\":true}\n");
	exit(0);
}

define('ABSPATH', __DIR__ . '/fixtures/');
define('WP_CLI', true);

require_once dirname(__DIR__) . '/inc/Cli/CliJsonStdoutGuard.php';

use DataMachineCode\Cli\CliJsonStdoutGuard;

function cli_json_guard_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

cli_json_guard_assert(CliJsonStdoutGuard::argv_requests_json(array( 'wp', 'datamachine-code', 'workspace', 'show', 'homeboy', '--format=json' )), 'Equals-style JSON format was not detected.');
cli_json_guard_assert(CliJsonStdoutGuard::argv_requests_json(array( 'wp', 'datamachine-code', 'workspace', 'show', 'homeboy', '--format', 'json' )), 'Separate JSON format was not detected.');
cli_json_guard_assert(CliJsonStdoutGuard::argv_requests_json(array( 'wp', 'datamachine-code', 'workspace', 'list', '--format=table', '--format=json' )), 'Last --format=json did not win.');
cli_json_guard_assert(! CliJsonStdoutGuard::argv_requests_json(array( 'wp', 'datamachine-code', 'workspace', 'show', 'homeboy' )), 'Human output was classified as JSON.');
cli_json_guard_assert(! CliJsonStdoutGuard::argv_requests_json(array( 'wp', 'datamachine-code', 'workspace', 'list', '--format=json', '--format=table' )), 'Superseded JSON format was still classified as JSON.');
cli_json_guard_assert(! CliJsonStdoutGuard::argv_requests_json(array( 'wp', 'other-plugin', '--format=json' )), 'Another plugin command was classified as DMC JSON.');

$display_errors = ini_get('display_errors');
ini_set('display_errors', '1');
cli_json_guard_assert(false === CliJsonStdoutGuard::install(array( 'wp', 'datamachine-code', 'workspace', 'show', 'homeboy' )), 'Human format unexpectedly installed the JSON stdout guard.');
cli_json_guard_assert('1' === (string) ini_get('display_errors'), 'Human format changed display_errors.');
ini_set('display_errors', (string) $display_errors);

$boot = (string) file_get_contents(dirname(__DIR__) . '/data-machine-code.php');
cli_json_guard_assert(str_contains($boot, 'CliJsonStdoutGuard::install()'), 'Plugin bootstrap no longer installs the JSON stdout guard.');

$process = proc_open(
	array( PHP_BINARY, __FILE__, 'child' ),
	array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$pipes
);
cli_json_guard_assert(is_resource($process), 'Could not start JSON stdout guard child.');
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$status = proc_close($process);

cli_json_guard_assert(0 === $status, 'JSON stdout guard child failed: ' . $stderr);
cli_json_guard_assert(is_string($stdout) && array( 'success' => true ) === json_decode($stdout, true, 512, JSON_THROW_ON_ERROR), 'Guarded stdout was not strict-parseable JSON: ' . (string) $stdout);
cli_json_guard_assert(is_string($stderr) && str_contains($stderr, 'json-stdout-guard-probe'), 'PHP diagnostic was not moved to stderr.');
cli_json_guard_assert(is_string($stdout) && ! str_contains($stdout, 'json-stdout-guard-probe'), 'PHP diagnostic still shared the JSON stdout stream.');

echo "cli-json-stdout-guard: ok\n";
