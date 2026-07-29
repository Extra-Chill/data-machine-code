<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(private string $code, private string $message = '', private mixed $data = null) {}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;
use DataMachineCode\Workspace\WorktreeDiskBudget;

function capacity_lock_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$mode = $argv[1] ?? 'test';
if ( 'holder' === $mode ) {
	$workspace = (string) $argv[2];
	$ready     = (string) $argv[3];
	$seconds   = (int) $argv[4];
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static function () use ( $ready, $seconds ): string {
			file_put_contents($ready, 'ready');
			sleep($seconds);
			return 'released';
		},
		1
	);
	exit(is_wp_error($result) ? 2 : 0);
}

if ( 'waiter' === $mode ) {
	$workspace = (string) $argv[2];
	$timeout   = (int) $argv[3];
	$started   = microtime(true);
	$result    = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'acquired', $timeout);
	if ( is_wp_error($result) ) {
		fwrite(STDOUT, 'error:' . $result->get_error_code());
		exit(3);
	}
	fwrite(STDOUT, sprintf('acquired:%.3f', microtime(true) - $started));
	exit(0);
}

if ( 'admission' === $mode ) {
	$workspace = (string) $argv[2];
	$state     = (string) $argv[3];
	$ready     = (string) $argv[4];
	$result = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static function () use ( $state, $ready ): string {
			$free = (int) file_get_contents($state);
			$budget = WorktreeDiskBudget::evaluate(
				array( 'free_bytes' => 1000000, 'total_bytes' => 2000000, 'free_inodes' => $free, 'total_inodes' => 1000 ),
				array( 'warn_free_bytes' => 1, 'warn_free_percent' => 0.0, 'refuse_free_bytes' => 1, 'refuse_free_percent' => 0.0, 'warn_free_inodes' => 150, 'warn_free_inode_percent' => 0.0, 'refuse_free_inodes' => 100, 'refuse_free_inode_percent' => 0.0, 'warn_worktree_count' => 99 ),
				false,
				array( 'bytes' => 0, 'inodes' => 50, 'source' => 'concurrency_test' )
			);
			if ( 'refused' === $budget['status'] ) {
				return 'refused';
			}
			file_put_contents($state, (string) ( $free - 50 ));
			file_put_contents($ready, 'ready');
			sleep(1);
			return 'admitted';
		},
		5
	);
	fwrite(STDOUT, is_wp_error($result) ? 'error' : $result);
	exit(is_wp_error($result) ? 4 : 0);
}

$workspace = sys_get_temp_dir() . '/dmc-capacity-lock-' . bin2hex(random_bytes(6));
mkdir($workspace, 0777, true);

try {
	$run_contention = static function ( int $hold_seconds, int $wait_timeout ) use ( $workspace ): array {
		$ready   = $workspace . '/ready';
		$command = array( PHP_BINARY, __FILE__, 'holder', $workspace, $ready, (string) $hold_seconds );
		$holder  = proc_open($command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		capacity_lock_assert(is_resource($holder), 'Could not start capacity lock holder process.');
		fclose($pipes[0]);

		$deadline = microtime(true) + 3;
		while ( ! is_file($ready) && microtime(true) < $deadline ) {
			usleep(10000);
		}
		capacity_lock_assert(is_file($ready), 'Capacity lock holder did not signal acquisition.');

		$waiter_command = array( PHP_BINARY, __FILE__, 'waiter', $workspace, (string) $wait_timeout );
		$waiter         = proc_open($waiter_command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $waiter_pipes);
		capacity_lock_assert(is_resource($waiter), 'Could not start capacity lock waiter process.');
		fclose($waiter_pipes[0]);
		$output = stream_get_contents($waiter_pipes[1]);
		$error  = stream_get_contents($waiter_pipes[2]);
		fclose($waiter_pipes[1]);
		fclose($waiter_pipes[2]);
		$waiter_exit = proc_close($waiter);

		fclose($pipes[1]);
		fclose($pipes[2]);
		$holder_exit = proc_close($holder);
		unlink($ready);

		return array( 'output' => $output, 'error' => $error, 'waiter_exit' => $waiter_exit, 'holder_exit' => $holder_exit );
	};

	$waited = $run_contention(2, 5);
	capacity_lock_assert(0 === $waited['holder_exit'], 'Capacity lock holder failed.');
	capacity_lock_assert(0 === $waited['waiter_exit'], 'A bounded legitimate waiter failed instead of acquiring: ' . $waited['error']);
	capacity_lock_assert(str_starts_with($waited['output'], 'acquired:'), 'Successful waiter did not report acquisition.');
	capacity_lock_assert((float) substr($waited['output'], 9) >= 1.0, 'Waiter did not actually serialize behind the holder.');

	$timed_out = $run_contention(2, 1);
	capacity_lock_assert(3 === $timed_out['waiter_exit'], 'Short waiter did not return the retryable timeout path.');
	capacity_lock_assert('error:workspace_repo_busy' === $timed_out['output'], 'Short waiter returned an unexpected lock error.');
	capacity_lock_assert(array() === ( glob($workspace . '/.locks/requests/*.json') ?: array() ), 'Cancelled or released requests left queue evidence behind.');

	if ( function_exists('posix_kill') ) {
		$ready = $workspace . '/kill-ready';
		$holder = proc_open(array( PHP_BINARY, __FILE__, 'holder', $workspace, $ready, '2' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $holder_pipes);
		capacity_lock_assert(is_resource($holder), 'Could not start cancellation lock holder.');
		fclose($holder_pipes[0]);
		$deadline = microtime(true) + 3;
		while ( ! is_file($ready) && microtime(true) < $deadline ) { usleep(10000); }
		$waiter = proc_open(array( PHP_BINARY, __FILE__, 'waiter', $workspace, '5' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $waiter_pipes);
		capacity_lock_assert(is_resource($waiter), 'Could not start cancellable waiter.');
		fclose($waiter_pipes[0]);
		$deadline = microtime(true) + 3;
		do { $requests = glob($workspace . '/.locks/requests/*.json') ?: array(); usleep(10000); } while ( array() === $requests && microtime(true) < $deadline );
		capacity_lock_assert(1 === count($requests), 'Cancellable waiter did not create request evidence.');
		$status = proc_get_status($waiter);
		$killed_pid = (int) $status['pid'];
		posix_kill($killed_pid, SIGKILL);
		stream_get_contents($waiter_pipes[1]); stream_get_contents($waiter_pipes[2]);
		fclose($waiter_pipes[1]); fclose($waiter_pipes[2]); proc_close($waiter);
		$queue = WorkspaceMutationLock::status($workspace)['queue'] ?? array();
		capacity_lock_assert(array() === array_filter($queue, static fn( array $request ): bool => $killed_pid === (int) ($request['pid'] ?? 0)), 'SIGKILL request was not reconciled after its owner exited.');
		fclose($holder_pipes[1]); fclose($holder_pipes[2]); proc_close($holder);
		unlink($ready);
	}

	// Eight independent admissions must remain observable while an unrelated
	// holder stalls the shared capacity resource, then drain without leftovers.
	$ready = $workspace . '/fanout-ready';
	$holder = proc_open(array( PHP_BINARY, __FILE__, 'holder', $workspace, $ready, '2' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $holder_pipes);
	capacity_lock_assert(is_resource($holder), 'Could not start fanout lock holder.');
	fclose($holder_pipes[0]);
	$deadline = microtime(true) + 3;
	while ( ! is_file($ready) && microtime(true) < $deadline ) { usleep(10000); }
	capacity_lock_assert(is_file($ready), 'Fanout lock holder did not signal acquisition.');
	$waiters = array();
	foreach ( range(1, 8) as $number ) {
		$process = proc_open(array( PHP_BINARY, __FILE__, 'waiter', $workspace, '5' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		capacity_lock_assert(is_resource($process), 'Could not start fanout waiter ' . $number . '.');
		fclose($pipes[0]);
		$waiters[] = array( $process, $pipes );
	}
	$deadline = microtime(true) + 3;
	do {
		$queue = WorkspaceMutationLock::status($workspace)['queue'] ?? array();
		$queued = array_filter($queue, static fn( array $request ): bool => 'queued' === ($request['state'] ?? ''));
		if ( 8 === count($queued) ) { break; }
		usleep(10000);
	} while ( microtime(true) < $deadline );
	capacity_lock_assert(8 === count($queued ?? array()), 'Every queued fanout admission must have durable queue evidence.');
	foreach ( $waiters as [ $process, $pipes ] ) {
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		capacity_lock_assert(0 === proc_close($process), 'Queued fanout admission did not drain: ' . $error);
		capacity_lock_assert(str_starts_with($output, 'acquired:'), 'Queued fanout admission did not report acquisition.');
	}
	fclose($holder_pipes[1]); fclose($holder_pipes[2]);
	capacity_lock_assert(0 === proc_close($holder), 'Fanout lock holder failed.');
	capacity_lock_assert(array() === ( glob($workspace . '/.locks/requests/*.json') ?: array() ), 'Completed fanout admissions left queue evidence behind.');
	unlink($ready);

	$policy = new class {
		use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;
	};
	capacity_lock_assert(2400 === $policy::worktree_capacity_wait_timeout_seconds(true), 'Bootstrap admission wait must exceed the complete bounded operation lifecycle.');

	$state = $workspace . '/capacity-state';
	$admission_ready = $workspace . '/admission-ready';
	file_put_contents($state, '180');
	$first = proc_open(array( PHP_BINARY, __FILE__, 'admission', $workspace, $state, $admission_ready ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $first_pipes);
	capacity_lock_assert(is_resource($first), 'Could not start first admission process.');
	fclose($first_pipes[0]);
	$deadline = microtime(true) + 3;
	while ( ! is_file($admission_ready) && microtime(true) < $deadline ) { usleep(10000); }
	capacity_lock_assert(is_file($admission_ready), 'First admission did not materialize its demand.');
	$second = proc_open(array( PHP_BINARY, __FILE__, 'admission', $workspace, $state, $workspace . '/second-ready' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $second_pipes);
	capacity_lock_assert(is_resource($second), 'Could not start second admission process.');
	fclose($second_pipes[0]);
	$second_output = stream_get_contents($second_pipes[1]);
	fclose($second_pipes[1]); fclose($second_pipes[2]);
	$second_exit = proc_close($second);
	$first_output = stream_get_contents($first_pipes[1]);
	fclose($first_pipes[1]); fclose($first_pipes[2]);
	$first_exit = proc_close($first);
	capacity_lock_assert(0 === $first_exit && 'admitted' === $first_output, 'First measured admission should succeed.');
	capacity_lock_assert(0 === $second_exit && 'refused' === $second_output, 'Second admission must remeasure after serialization and refuse projected overcommit.');
	capacity_lock_assert('130' === file_get_contents($state), 'Refused second admission must not consume stale capacity.');
	echo "workspace-capacity-lock-concurrency: ok\n";
} finally {
	foreach ( array( 'capacity-state', 'admission-ready', 'second-ready', 'fanout-ready', 'kill-ready' ) as $file ) {
		if ( is_file($workspace . '/' . $file) ) { unlink($workspace . '/' . $file); }
	}
	if ( is_file($workspace . '/ready') ) {
		unlink($workspace . '/ready');
	}
	if ( is_dir($workspace . '/.locks') ) {
		foreach ( glob($workspace . '/.locks/requests/*.json') ?: array() as $request ) {
			unlink($request);
		}
		if ( is_dir($workspace . '/.locks/requests') ) {
			rmdir($workspace . '/.locks/requests');
		}
		foreach ( scandir($workspace . '/.locks') ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				unlink($workspace . '/.locks/' . $entry);
			}
		}
		rmdir($workspace . '/.locks');
	}
	rmdir($workspace);
}
