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

function process_runner_assert_stopped( int $pid, string $message ): void {
	// A zombie has exited but may remain visible until its parent reaps it.
	// Check its state instead of treating kill(pid, 0) as proof it is still running.
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	@exec(sprintf('ps -o stat= -p %d', $pid), $states);
	if ( ! empty($states) && ! str_starts_with(trim($states[0]), 'Z') ) {
		throw new RuntimeException($message);
	}
}

function process_runner_assert_running( int $pid, string $message ): void {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	@exec(sprintf('ps -o stat= -p %d', $pid), $states);
	if ( empty($states) || str_starts_with(trim($states[0]), 'Z') ) {
		throw new RuntimeException($message);
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

$timed_out_result = ProcessRunner::run(
	CommandSpec::from_argv(array( PHP_BINARY, '-r', 'while (true) {}' )),
	array(
		'timeout_seconds'            => 1,
		'poll_interval_microseconds' => 1000,
		'error_as_result'            => true,
	)
);
process_runner_assert_same(false, $timed_out_result['success'] ?? null, 'Result-mode timeouts report failure.');
process_runner_assert_same(true, is_array($timed_out_result['cleanup'] ?? null), 'Result-mode timeouts preserve cleanup diagnostics.');

if ( 'Windows' !== PHP_OS_FAMILY ) {
	$failed_command = ProcessRunner::run(
		CommandSpec::from_argv(array( '/bin/sh', '-c', 'exit 23' )),
		array( 'timeout_seconds' => 1 )
	);
	process_runner_assert_same(true, $failed_command instanceof WP_Error, 'Timed commands preserve non-timeout exit failures.');
	process_runner_assert_same(23, $failed_command->get_error_data()['exit_code'] ?? null, 'Timed commands preserve the child exit code.');

	// This process is not part of either timed command and must remain untouched.
	$sibling = proc_open(array( '/bin/sh', '-c', 'sleep 5' ), array(), $sibling_pipes);
	process_runner_assert_same(true, is_resource($sibling), 'Test sibling process should start.');
	$sibling_status = proc_get_status($sibling);
	$sibling_pid    = (int) $sibling_status['pid'];

	$descendant_command = 'printf descendant-ready:; sh -c ' . escapeshellarg('trap "" TERM; printf "%s" "$$"; sleep 2') . ' & wait';
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
	$descendant_stdout = $descendant_timeout->get_error_data()['stdout'] ?? '';
	process_runner_assert_same(true, 1 === preg_match('/^descendant-ready:(\d+)$/', $descendant_stdout, $matches), 'Timed-out argv commands preserve captured stdout.');
	$descendant_cleanup = $descendant_timeout->get_error_data()['cleanup'] ?? array();
	usleep(100000);
	if ( ! empty($descendant_cleanup['verified']) ) {
		process_runner_assert_same(1, $descendant_cleanup['attempts'] ?? null, 'Verified containment uses one force signal.');
		process_runner_assert_same('SIGKILL', $descendant_cleanup['signal'] ?? null, 'Verified containment uses one immediate force signal.');
		process_runner_assert_stopped((int) $matches[1], 'Verified containment must terminate pipe-owning descendants.');
	} else {
		process_runner_assert_same('process', $descendant_cleanup['containment'] ?? null, 'Fallback cleanup reports direct-process containment.');
		process_runner_assert_same(false, $descendant_cleanup['verified'] ?? null, 'Fallback cleanup reports unverified descendants.');
		process_runner_assert_same(2, $descendant_cleanup['attempts'] ?? null, 'Fallback cleanup only signals the owned parent.');
		process_runner_assert_running((int) $matches[1], 'Fallback cleanup must not signal an unverified reparented descendant.');
	}
	process_runner_assert_running($sibling_pid, 'Timed command cleanup must not signal an unrelated sibling.');
	process_runner_assert_less_than(3.0, $elapsed, 'Timed-out argv commands must not wait for pipe-owning descendants.');

	$alias_args = '-c ' . escapeshellarg('alias.timeout-descendant=!sh -c ' . escapeshellarg($descendant_command)) . ' timeout-descendant';
	$started    = microtime(true);
	$git_timeout = GitRunner::run(sys_get_temp_dir(), $alias_args, 1);
	$elapsed     = microtime(true) - $started;
	process_runner_assert_same(true, $git_timeout instanceof WP_Error, 'GitRunner string commands should time out when a git alias leaves a pipe-owning descendant.');
	process_runner_assert_same('git_command_timeout', $git_timeout->get_error_code(), 'GitRunner string commands preserve the timeout error envelope.');
	$git_output = $git_timeout->get_error_data()['output'] ?? '';
	process_runner_assert_same(true, 1 === preg_match('/^descendant-ready:(\d+)$/', $git_output, $matches), 'GitRunner string commands preserve captured output.');
	$git_cleanup = $git_timeout->get_error_data()['cleanup'] ?? array();
	usleep(100000);
	if ( ! empty($git_cleanup['verified']) ) {
		process_runner_assert_same(1, $git_cleanup['attempts'] ?? null, 'Verified GitRunner containment uses one force signal.');
		process_runner_assert_same('SIGKILL', $git_cleanup['signal'] ?? null, 'Verified GitRunner containment uses one immediate force signal.');
		process_runner_assert_stopped((int) $matches[1], 'Verified GitRunner containment must terminate pipe-owning descendants.');
	} else {
		process_runner_assert_same('process', $git_cleanup['containment'] ?? null, 'GitRunner fallback cleanup reports direct-process containment.');
		process_runner_assert_same(false, $git_cleanup['verified'] ?? null, 'GitRunner fallback cleanup reports unverified descendants.');
		process_runner_assert_same(2, $git_cleanup['attempts'] ?? null, 'GitRunner fallback only signals the owned parent.');
		process_runner_assert_running((int) $matches[1], 'GitRunner fallback cleanup must not signal an unverified reparented descendant.');
	}
	process_runner_assert_running($sibling_pid, 'GitRunner cleanup must not signal an unrelated sibling.');
	process_runner_assert_less_than(3.0, $elapsed, 'GitRunner string commands must not wait for pipe-owning descendants.');
	proc_terminate($sibling, 9);
	proc_close($sibling);
}

$invalid = CommandSpec::from_argv(array());
process_runner_assert_same(true, $invalid instanceof WP_Error, 'CommandSpec rejects empty argv.');

echo "process-runner-command-spec: ok\n";
