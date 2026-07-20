<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
require_once dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Support/GitRunner.php';

use DataMachineCode\Support\CommandSpec;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\ProcessRunner;

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

function process_runner_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function process_runner_assert_not_error( mixed $result, string $message ): void {
	if ( $result instanceof WP_Error ) {
		throw new RuntimeException(sprintf('%s %s', $message, $result->get_error_message()));
	}
}

function process_runner_assert_less_than( float $expected, float $actual, string $message ): void {
	if ( $actual >= $expected ) {
		throw new RuntimeException(sprintf('%s Expected less than %.2f, got %.2f.', $message, $expected, $actual));
	}
}

$cwd = sys_get_temp_dir();

$spec = CommandSpec::from_argv(
	array( PHP_BINARY, '-r', 'fwrite(STDOUT, getenv("DMC_COMMAND_SPEC_TEST") . "|" . basename(getcwd()));' ),
	array(
		'cwd' => $cwd,
		'env' => array_merge(
			getenv() ?: array(),
			array( 'DMC_COMMAND_SPEC_TEST' => 'argv-ok' )
		),
	)
);
process_runner_assert_not_error($spec, 'CommandSpec should accept argv.');

$result = ProcessRunner::run($spec, array( 'separate_streams' => true ));
process_runner_assert_not_error($result, 'ProcessRunner should execute argv specs.');
process_runner_assert_same('argv-ok|' . basename($cwd), $result['stdout'], 'CommandSpec preserves argv, cwd, and env policy.');
process_runner_assert_same('', $result['stderr'], 'CommandSpec captures stderr separately.');

$timed_out = ProcessRunner::run(
	CommandSpec::from_argv(array( PHP_BINARY, '-r', 'while (true) {}' )),
	array(
		'timeout_seconds'           => 1,
		'poll_interval_microseconds' => 1000,
	)
);
process_runner_assert_same(true, $timed_out instanceof WP_Error, 'ProcessRunner should terminate a controlled hanging process.');
process_runner_assert_same(1, $timed_out->get_error_data()['timeout'] ?? null, 'ProcessRunner timeout result carries the configured budget.');

if ( 'Windows' !== PHP_OS_FAMILY ) {
	$descendant_command = 'printf descendant-ready; sleep 6 & wait';
	$started            = microtime(true);
	$descendant_timeout = ProcessRunner::run(
		CommandSpec::from_argv(array( '/bin/sh', '-c', $descendant_command )),
		array(
			'timeout_seconds'            => 1,
			'poll_interval_microseconds' => 1000,
			'separate_streams'           => true,
		)
	);
	$elapsed = microtime(true) - $started;
	process_runner_assert_same(true, $descendant_timeout instanceof WP_Error, 'ProcessRunner should time out an argv command whose shell descendant retains pipes.');
	process_runner_assert_same('descendant-ready', $descendant_timeout->get_error_data()['stdout'] ?? null, 'Timed-out argv commands preserve captured stdout.');
	process_runner_assert_less_than(3.0, $elapsed, 'Timed-out argv commands must not wait for pipe-owning descendants.');

	$alias_args = '-c ' . escapeshellarg('alias.timeout-descendant=!sh -c ' . escapeshellarg($descendant_command)) . ' timeout-descendant';
	$started    = microtime(true);
	$git_timeout = GitRunner::run(sys_get_temp_dir(), $alias_args, 1);
	$elapsed     = microtime(true) - $started;
	process_runner_assert_same(true, $git_timeout instanceof WP_Error, 'GitRunner string commands should time out when a git alias leaves a pipe-owning descendant.');
	process_runner_assert_same('git_command_timeout', $git_timeout->get_error_code(), 'GitRunner string commands preserve the timeout error envelope.');
	process_runner_assert_same('descendant-ready', $git_timeout->get_error_data()['output'] ?? null, 'GitRunner string commands preserve captured output.');
	process_runner_assert_less_than(3.0, $elapsed, 'GitRunner string commands must not wait for pipe-owning descendants.');
}

$invalid = CommandSpec::from_argv(array());
process_runner_assert_same(true, $invalid instanceof WP_Error, 'CommandSpec rejects empty argv.');

echo "process-runner-command-spec: ok\n";
