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

		public function get_error_data(): mixed {
			return $this->data;
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
	$lock_key  = (string) ( $argv[5] ?? 'workspace-capacity-admission' );
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		$lock_key,
		static function () use ( $ready, $seconds ): string {
			file_put_contents($ready, 'ready');
			sleep($seconds);
			return 'released';
		},
		1
	);
	exit(is_wp_error($result) ? 2 : 0);
}

if ( 'artifact-cleanup' === $mode ) {
	$workspace = (string) $argv[2];
	$marker    = (string) $argv[3];
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static fn() => WorkspaceMutationLock::with_repos(
			$workspace,
			array( 'repo-z', 'repo-a' ),
			static function () use ( $marker ): string {
				file_put_contents($marker, 'artifact removed');
				return 'removed';
			},
			1
		),
		1
	);
	fwrite(STDOUT, is_wp_error($result) ? 'error:' . $result->get_error_code() : $result);
	exit(is_wp_error($result) ? 5 : 0);
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

if ( 'ordered-waiter' === $mode ) {
	$workspace = (string) $argv[2];
	$id        = (string) $argv[3];
	$order     = (string) $argv[4];
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static function () use ( $id, $order ): string {
			file_put_contents($order, $id . "\n", FILE_APPEND | LOCK_EX);
			return $id;
		},
		5
	);
	fwrite(STDOUT, is_wp_error($result) ? 'error:' . $result->get_error_code() : $result);
	exit(is_wp_error($result) ? 8 : 0);
}

if ( 'repo-holder' === $mode ) {
	$workspace = (string) $argv[2];
	$repo      = (string) $argv[3];
	$ready     = (string) $argv[4];
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		$repo,
		static function () use ( $ready ): string {
			file_put_contents($ready, 'ready');
			sleep(1);
			return 'released';
		},
		2
	);
	exit(is_wp_error($result) ? 6 : 0);
}

if ( 'diagnostic-waiter' === $mode ) {
	$workspace = (string) $argv[2];
	$result    = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'acquired', 1);
	if ( ! is_wp_error($result) ) {
		exit(7);
	}
	fwrite(STDOUT, json_encode($result->get_error_data()));
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
	$zero_argument_callback = WorkspaceMutationLock::with_repo($workspace, 'callback-compat', static fn(): string => 'zero-argument', 1);
	capacity_lock_assert('zero-argument' === $zero_argument_callback, 'Zero-argument lock callback compatibility regressed.');
	$lock_aware_callback = WorkspaceMutationLock::with_repo($workspace, 'callback-compat', static fn( WorkspaceMutationLock $lock ): string => $lock instanceof WorkspaceMutationLock ? 'lock-aware' : 'invalid', 1);
	capacity_lock_assert('lock-aware' === $lock_aware_callback, 'Lock-aware callback did not receive the safe lease handle.');

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
	capacity_lock_assert((float) substr($waited['output'], 9) < 2.5, 'Waiter did not wake promptly after the holder released the OS flock.');

	$timed_out = $run_contention(2, 1);
	capacity_lock_assert(3 === $timed_out['waiter_exit'], 'Short waiter did not return the retryable timeout path.');
	capacity_lock_assert('error:workspace_repo_busy' === $timed_out['output'], 'Short waiter returned an unexpected lock error.');
	capacity_lock_assert(array() === ( glob($workspace . '/.locks/requests/*.json') ?: array() ), 'Cancelled or released requests left queue evidence behind.');

	// A timed-out client receives durable diagnostic identity and enough owner
	// state to inspect the holder rather than silently guessing at completion.
	$ready = $workspace . '/diagnostic-ready';
	$holder = proc_open(array( PHP_BINARY, __FILE__, 'holder', $workspace, $ready, '2' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $holder_pipes);
	capacity_lock_assert(is_resource($holder), 'Could not start diagnostic lock holder.');
	fclose($holder_pipes[0]);
	$deadline = microtime(true) + 3;
	while ( ! is_file($ready) && microtime(true) < $deadline ) { usleep(10000); }
	$diagnostic = proc_open(array( PHP_BINARY, __FILE__, 'diagnostic-waiter', $workspace), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $diagnostic_pipes);
	capacity_lock_assert(is_resource($diagnostic), 'Could not start diagnostic waiter.');
	fclose($diagnostic_pipes[0]);
	$diagnostic_output = stream_get_contents($diagnostic_pipes[1]);
	fclose($diagnostic_pipes[1]); fclose($diagnostic_pipes[2]);
	capacity_lock_assert(0 === proc_close($diagnostic), 'Diagnostic waiter did not return typed timeout evidence.');
	$diagnostic_data = json_decode($diagnostic_output, true);
	capacity_lock_assert(is_array($diagnostic_data) && ! empty($diagnostic_data['request_id']), 'Timeout must expose a durable request identity.');
	capacity_lock_assert(1 === ($diagnostic_data['queue_position'] ?? null), 'Single waiter must report queue position one.');
	capacity_lock_assert('lock_wait' === ($diagnostic_data['progress']['phase'] ?? null), 'Timeout must identify lock-wait progress.');
	capacity_lock_assert(is_array($diagnostic_data['owner'] ?? null), 'Timeout must expose the active lock owner.');
	capacity_lock_assert(array_key_exists('estimated_wait_seconds', $diagnostic_data) && 'active_holder_release_unknown' === ($diagnostic_data['eta_status'] ?? null), 'Timeout without an owner operation deadline must not estimate completion from the DB lease.');
	fclose($holder_pipes[1]); fclose($holder_pipes[2]); proc_close($holder);
	unlink($ready);

	// Repository-local preparation locks are independent. This is the property
	// used by worktree admission before it enters the global disk-safety fence.
	$parallel_started = microtime(true);
	$repo_a_ready = $workspace . '/repo-a-ready';
	$repo_b_ready = $workspace . '/repo-b-ready';
	$repo_a = proc_open(array( PHP_BINARY, __FILE__, 'repo-holder', $workspace, 'repo-a', $repo_a_ready), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $repo_a_pipes);
	$repo_b = proc_open(array( PHP_BINARY, __FILE__, 'repo-holder', $workspace, 'repo-b', $repo_b_ready), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $repo_b_pipes);
	capacity_lock_assert(is_resource($repo_a) && is_resource($repo_b), 'Could not start independent repository preflight holders.');
	fclose($repo_a_pipes[0]); fclose($repo_b_pipes[0]);
	foreach ( array( $repo_a_ready, $repo_b_ready ) as $ready_path ) { $deadline = microtime(true) + 2; while ( ! is_file($ready_path) && microtime(true) < $deadline ) { usleep(10000); } capacity_lock_assert(is_file($ready_path), 'Independent repository preflight did not acquire.'); }
	fclose($repo_a_pipes[1]); fclose($repo_a_pipes[2]); fclose($repo_b_pipes[1]); fclose($repo_b_pipes[2]);
	capacity_lock_assert(0 === proc_close($repo_a) && 0 === proc_close($repo_b), 'Independent repository preflight holder failed.');
	capacity_lock_assert(microtime(true) - $parallel_started < 1.8, 'Independent repository preflight locks serialized globally.');
	unlink($repo_a_ready); unlink($repo_b_ready);

	// Automatic artifact cleanup takes the global capacity lock before sorted
	// affected-repository locks. A concurrent lifecycle lock must prevent the
	// deletion callback from running.
	$artifact_ready = $workspace . '/artifact-ready';
	$artifact_marker = $workspace . '/artifact-removed';
	$artifact_holder = proc_open(array( PHP_BINARY, __FILE__, 'holder', $workspace, $artifact_ready, '2', 'repo-a'), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $artifact_holder_pipes);
	capacity_lock_assert(is_resource($artifact_holder), 'Could not start per-repo lifecycle lock holder.');
	fclose($artifact_holder_pipes[0]);
	$deadline = microtime(true) + 3;
	while ( ! is_file($artifact_ready) && microtime(true) < $deadline ) { usleep(10000); }
	capacity_lock_assert(is_file($artifact_ready), 'Per-repo lifecycle lock holder did not signal acquisition.');
	$artifact_cleanup = proc_open(array( PHP_BINARY, __FILE__, 'artifact-cleanup', $workspace, $artifact_marker), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $artifact_cleanup_pipes);
	capacity_lock_assert(is_resource($artifact_cleanup), 'Could not start automatic artifact cleanup process.');
	fclose($artifact_cleanup_pipes[0]);
	$artifact_output = stream_get_contents($artifact_cleanup_pipes[1]);
	$artifact_error = stream_get_contents($artifact_cleanup_pipes[2]);
	fclose($artifact_cleanup_pipes[1]);
	fclose($artifact_cleanup_pipes[2]);
	$artifact_exit = proc_close($artifact_cleanup);
	fclose($artifact_holder_pipes[1]);
	fclose($artifact_holder_pipes[2]);
	proc_close($artifact_holder);
	capacity_lock_assert(5 === $artifact_exit && 'error:workspace_repo_busy' === $artifact_output, 'Concurrent per-repo lifecycle operation did not block automatic artifact cleanup: ' . $artifact_error);
	capacity_lock_assert(! is_file($artifact_marker), 'Blocked automatic artifact cleanup deleted an artifact.');
	capacity_lock_assert(array() === ( glob($workspace . '/.locks/requests/*.json') ?: array() ), 'Blocked artifact cleanup left a request file behind.');
	unlink($artifact_ready);

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
		$cancel_order = $workspace . '/cancel-order';
		$next = proc_open(array( PHP_BINARY, __FILE__, 'ordered-waiter', $workspace, 'after-cancel', $cancel_order), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $next_pipes);
		capacity_lock_assert(is_resource($next), 'Could not start the follower promoted after cancellation.');
		fclose($next_pipes[0]);
		fclose($holder_pipes[1]); fclose($holder_pipes[2]); proc_close($holder);
		$next_output = stream_get_contents($next_pipes[1]); $next_error = stream_get_contents($next_pipes[2]); fclose($next_pipes[1]); fclose($next_pipes[2]);
		capacity_lock_assert(0 === proc_close($next) && 'after-cancel' === $next_output && "after-cancel\n" === file_get_contents($cancel_order), 'Cancellation did not promote the next queued admission: ' . $next_error);
		unlink($cancel_order);
		unlink($ready);

		// A holder killed after acquisition must release its OS flock so the next
		// admission can proceed without waiting for its stale DB lease.
		$ready = $workspace . '/owner-exit-ready';
		$holder = proc_open(array( PHP_BINARY, __FILE__, 'holder', $workspace, $ready, '10' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $holder_pipes);
		capacity_lock_assert(is_resource($holder), 'Could not start owner-exit holder.');
		fclose($holder_pipes[0]);
		$deadline = microtime(true) + 3;
		while ( ! is_file($ready) && microtime(true) < $deadline ) { usleep(10000); }
		$holder_status = proc_get_status($holder);
		posix_kill((int) $holder_status['pid'], SIGKILL);
		fclose($holder_pipes[1]); fclose($holder_pipes[2]); proc_close($holder);
		$recovered = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'acquired', 1);
		capacity_lock_assert('acquired' === $recovered, 'Next admission remained blocked after its lock owner exited.');
		unlink($ready);
	}

	// A stale queue record is diagnostic only and must be pruned before the
	// next admission without preventing that admission from acquiring.
	$request_dir = $workspace . '/.locks/requests';
	if ( ! is_dir($request_dir) ) { mkdir($request_dir, 0777, true); }
	file_put_contents($request_dir . '/stale-owner.json', json_encode(array( 'pid' => 999999, 'state' => 'queued' )));
	$after_stale_queue = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'acquired', 1);
	capacity_lock_assert('acquired' === $after_stale_queue, 'Stale queue evidence blocked the next admission.');
	capacity_lock_assert(array() === ( glob($request_dir . '/*.json') ?: array() ), 'Stale queue evidence was not removed before the next admission.');

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
		capacity_lock_assert(str_starts_with($output, 'acquired:'), 'Queued fanout admission did not report acquisition: ' . $output . ' ' . $error);
	}
	fclose($holder_pipes[1]); fclose($holder_pipes[2]);
	capacity_lock_assert(0 === proc_close($holder), 'Fanout lock holder failed.');
	capacity_lock_assert(array() === ( glob($workspace . '/.locks/requests/*.json') ?: array() ), 'Completed fanout admissions left queue evidence behind.');
	unlink($ready);

	// Queue records are admission tokens, not diagnostics: followers retain their
	// arrival order and a later request cannot barge ahead after release.
	$ready = $workspace . '/fifo-ready';
	$order = $workspace . '/fifo-order';
	$holder = proc_open(array( PHP_BINARY, __FILE__, 'holder', $workspace, $ready, '1' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $holder_pipes);
	capacity_lock_assert(is_resource($holder), 'Could not start FIFO holder.');
	fclose($holder_pipes[0]);
	$deadline = microtime(true) + 3;
	while ( ! is_file($ready) && microtime(true) < $deadline ) { usleep(10000); }
	capacity_lock_assert(is_file($ready), 'FIFO holder did not acquire.');
	$fifo_waiters = array();
	foreach ( array( 'first', 'second', 'late' ) as $id ) {
		$process = proc_open(array( PHP_BINARY, __FILE__, 'ordered-waiter', $workspace, $id, $order), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		capacity_lock_assert(is_resource($process), 'Could not start FIFO waiter ' . $id . '.');
		fclose($pipes[0]);
		$fifo_waiters[] = array( $id, $process, $pipes );
		$deadline = microtime(true) + 2;
		do { $queued = array_filter(WorkspaceMutationLock::status($workspace)['queue'] ?? array(), static fn( array $request ): bool => 'queued' === ($request['state'] ?? '')); usleep(10000); } while ( count($queued) < count($fifo_waiters) && microtime(true) < $deadline );
		capacity_lock_assert(count($queued) === count($fifo_waiters), 'FIFO waiter did not become durably queued.');
	}
	usort($queued, static fn( array $left, array $right ): int => strcmp((string) ($left['queue_order'] ?? ''), (string) ($right['queue_order'] ?? '')));
	capacity_lock_assert(array( 1, 2, 3 ) === array_map(static fn( array $request ): int => (int) ($request['queue_position'] ?? 0), $queued), 'Queued followers did not retain FIFO queue positions.');
	foreach ( $fifo_waiters as [ $id, $process, $pipes ] ) {
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		capacity_lock_assert(0 === proc_close($process) && $id === $output, 'FIFO waiter failed or completed out of contract: ' . $error);
	}
	fclose($holder_pipes[1]); fclose($holder_pipes[2]);
	capacity_lock_assert(0 === proc_close($holder), 'FIFO holder failed.');
	capacity_lock_assert(array( 'first', 'second', 'late' ) === array_values(array_filter(explode("\n", trim((string) file_get_contents($order))))), 'Capacity queue permitted barging instead of FIFO admission.');
	capacity_lock_assert(array() === ( glob($workspace . '/.locks/requests/*.json') ?: array() ), 'FIFO admissions left queue evidence behind.');
	unlink($ready); unlink($order);

	$policy = new class {
		use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;
	};
	$lifecycle_source = (string) file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
	capacity_lock_assert(strpos($lifecycle_source, 'worktree_capacity_preflight') < strpos($lifecycle_source, "'workspace-capacity-admission'"), 'Repo-local preflight must run before global capacity admission.');
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
	foreach ( array( 'capacity-state', 'admission-ready', 'second-ready', 'fanout-ready', 'fifo-ready', 'fifo-order', 'cancel-order', 'kill-ready', 'diagnostic-ready', 'repo-a-ready', 'repo-b-ready' ) as $file ) {
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
