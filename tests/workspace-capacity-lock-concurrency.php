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

$GLOBALS['dmc_capacity_lock_options'] = array();
if ( ! function_exists('get_option') ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['dmc_capacity_lock_options'][ $key ] ?? $default;
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;
use DataMachineCode\Workspace\WorktreeDiskBudget;
use DataMachineCode\Workspace\WorktreeBootstrapper;

function capacity_lock_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function capacity_lock_remove_tree( string $path ): void {
	foreach ( scandir($path) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$child = $path . '/' . $entry;
		if ( is_dir($child) ) {
			capacity_lock_remove_tree($child);
		} else {
			unlink($child);
		}
	}
	rmdir($path);
}

function capacity_lock_lsof_binary(): ?string {
	foreach ( array( '/usr/sbin/lsof', '/usr/bin/lsof' ) as $candidate ) {
		if ( is_executable($candidate) ) {
			return $candidate;
		}
	}
	return null;
}

function capacity_lock_process_has_descriptor( int $pid, string $path ): bool {
	$lsof = capacity_lock_lsof_binary();
	if ( $pid <= 0 || null === $lsof ) {
		return false;
	}

	$output = array();
	$status = 1;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- This verifies the OS-level descriptor inheritance contract.
	@exec(escapeshellarg($lsof) . ' -Fn -a -p ' . $pid . ' -- ' . escapeshellarg($path), $output, $status);
	return 0 === $status && in_array('n' . $path, $output, true);
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

if ( 'signal-holder' === $mode ) {
	$workspace = (string) $argv[2];
	$ready     = (string) $argv[3];
	$release   = (string) $argv[4];
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static function () use ( $ready, $release ): string|WP_Error {
			file_put_contents($ready, 'ready');
			$deadline = microtime(true) + 5;
			while ( ! is_file($release) && microtime(true) < $deadline ) {
				usleep(10000);
			}
			return is_file($release) ? 'released' : new WP_Error('release_signal_timeout');
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
	$events    = array();
	$marker    = $workspace . '/diagnostic-mutated';
	$result    = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static function () use ( $marker ): string {
			file_put_contents($marker, 'mutated');
			return 'acquired';
		},
		1,
		array(),
		static function ( array $event ) use ( &$events ): void {
			$events[] = $event;
		}
	);
	if ( ! is_wp_error($result) ) {
		exit(7);
	}
	fwrite(STDOUT, json_encode(array( 'error' => $result->get_error_data(), 'events' => $events, 'mutated' => is_file($marker) )));
	exit(0);
}

if ( 'bootstrap-child' === $mode ) {
	file_put_contents((string) $argv[2], (string) getmypid());
	sleep(10);
	exit(0);
}

if ( 'bootstrap-descendant' === $mode ) {
	file_put_contents((string) $argv[2], (string) getmypid());
	sleep(10);
	exit(0);
}

if ( 'bootstrap-real-parent' === $mode ) {
	$workspace = (string) $argv[2];
	$root = (string) $argv[3];
	$reserved = (string) $argv[4];
	$child_pid = (string) $argv[5];
	$descendant_pid = (string) $argv[6];
	$old_path = getenv('PATH') ?: '';
	putenv('PATH=' . $root . '/bin:' . $old_path);
	$result = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static fn() => WorkspaceMutationLock::with_repo($workspace, 'repo-a', static function () use ( $reserved ): bool {
			file_put_contents($reserved, 'reserved');
			return true;
		}),
		2
	);
	if ( is_wp_error($result) ) {
		exit(9);
	}
	$result = WorktreeBootstrapper::bootstrap($root, 30);
	file_put_contents($root . '/result.json', json_encode($result));
	exit(0);
}

if ( 'bootstrap-parent' === $mode ) {
	$workspace   = (string) $argv[2];
	$reserved    = (string) $argv[3];
	$child_ready = (string) $argv[4];
	$child_pid   = (string) $argv[5];
	$result = WorkspaceMutationLock::with_repo(
		$workspace,
		'workspace-capacity-admission',
		static function ( WorkspaceMutationLock $lock ) use ( $reserved, $child_ready, $child_pid ): void {
			// This marker represents the metadata reservation committed under admission.
			file_put_contents($reserved, 'reserved');
			$lock->release();
			$child = proc_open(array( PHP_BINARY, __FILE__, 'bootstrap-child', $child_ready ), array(), $pipes);
			if (! is_resource($child)) {
				exit(9);
			}
			file_put_contents($child_pid, (string) proc_get_status($child)['pid']);
			sleep(10);
		},
		2
	);
	exit(is_wp_error($result) ? 9 : 0);
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
	$owner = array( 'pid' => 100, 'identity' => array( 'platform' => 'linux_proc', 'start_ticks' => '123' ) );
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'active', 'identity' => $owner['identity'] ));
	capacity_lock_assert('active' === (DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_owner_state($owner)['state'] ?? null), 'Exact process identity was not active.');
	$mac_owner = array( 'pid' => 101, 'identity' => array( 'platform' => 'ps', 'started_at' => 'Mon Aug 24 12:00:00 2026', 'command' => '/usr/bin/php worker', 'command_sha256' => hash('sha256', '/usr/bin/php worker') ) );
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'active', 'identity' => array_merge($mac_owner['identity'], array( 'command' => '/usr/bin/php other', 'command_sha256' => hash('sha256', '/usr/bin/php other') )) ));
	capacity_lock_assert('stale' === (DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_owner_state($mac_owner)['state'] ?? null), 'Same coarse macOS start time with another command did not prove PID reuse.');
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'active', 'identity' => $mac_owner['identity'] ));
	capacity_lock_assert('unverifiable' === (DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_owner_state($mac_owner)['state'] ?? null), 'Exact coarse macOS identity did not fail closed.');
	foreach ( array( 'owner_probe_unavailable', 'owner_probe_denied', 'owner_probe_unparsable' ) as $reason ) {
		DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'unverifiable', 'reason' => $reason ));
		capacity_lock_assert('unverifiable' === (DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_owner_state($owner)['state'] ?? null), 'Unverifiable owner probe was classified as stale for ' . $reason . '.');
	}
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'stale', 'reason' => 'owner_process_missing' ));
	capacity_lock_assert('stale' === (DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_owner_state($owner)['state'] ?? null), 'ESRCH-equivalent owner absence was not stale.');
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'unverifiable', 'reason' => 'owner_probe_denied' ));
	capacity_lock_assert('unverifiable' === (DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_owner_state($owner)['state'] ?? null), 'EPERM-equivalent owner denial was not unverifiable.');
	$GLOBALS['dmc_capacity_lock_options']['datamachine_worktree_metadata'] = array(
		'repo@blocked-bootstrap' => array(
			'provisioning' => array(
				'bootstrap' => array(
					'outcome' => 'running',
					'capacity_reservation' => array( 'bytes' => 400, 'inodes' => 40 ),
					'coordinator' => $owner,
					'active_child' => array( 'pid' => 101, 'identity' => array( 'platform' => 'linux_proc', 'start_ticks' => '124' ) ),
				),
			),
		),
	);
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'active', 'identity' => 100 === $pid ? $owner['identity'] : array( 'platform' => 'linux_proc', 'start_ticks' => '124' ) ));
	$reservations = DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_capacity_reservations();
	capacity_lock_assert(400 === $reservations['bytes'] && 40 === $reservations['inodes'] && array( 'repo@blocked-bootstrap' ) === $reservations['handles'], 'A running bootstrap reservation was not durably visible to the next admission.');
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => 100 === $pid ? array( 'state' => 'stale', 'reason' => 'owner_process_missing' ) : array( 'state' => 'active', 'identity' => array( 'platform' => 'linux_proc', 'start_ticks' => '124' ) ));
	$reservations = DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_capacity_reservations();
	capacity_lock_assert(400 === $reservations['bytes'] && 40 === $reservations['inodes'], 'Live child reservation must remain charged after coordinator death.');
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => 100 === $pid ? array( 'state' => 'unverifiable', 'reason' => 'owner_probe_denied' ) : array( 'state' => 'stale', 'reason' => 'owner_process_missing' ));
	$reservations = DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_capacity_reservations();
	capacity_lock_assert(400 === $reservations['bytes'] && 40 === $reservations['inodes'], 'Unverifiable coordinator reservation must remain capacity charged.');
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'stale', 'reason' => 'owner_process_missing' ));
	$reservations = DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_capacity_reservations();
	capacity_lock_assert(0 === $reservations['bytes'] && 0 === $reservations['inodes'], 'Verified stale owner reservation remained capacity charged.');
	$GLOBALS['dmc_capacity_lock_options']['datamachine_worktree_metadata']['repo@blocked-bootstrap']['provisioning']['bootstrap']['outcome'] = 'succeeded';
	$reservations = DataMachineCode\Workspace\WorktreeContextInjector::bootstrap_capacity_reservations();
	capacity_lock_assert(0 === $reservations['bytes'] && 0 === $reservations['inodes'], 'Completed bootstrap reservations must not remain charged to later admissions.');
	$GLOBALS['dmc_capacity_lock_options']['datamachine_worktree_metadata'] = array();
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(null);

	$zero_argument_callback = WorkspaceMutationLock::with_repo($workspace, 'callback-compat', static fn(): string => 'zero-argument', 1);
	capacity_lock_assert('zero-argument' === $zero_argument_callback, 'Zero-argument lock callback compatibility regressed.');
	$lock_aware_callback = WorkspaceMutationLock::with_repo($workspace, 'callback-compat', static fn( WorkspaceMutationLock $lock ): string => $lock instanceof WorkspaceMutationLock ? 'lock-aware' : 'invalid', 1);
	capacity_lock_assert('lock-aware' === $lock_aware_callback, 'Lock-aware callback did not receive the safe lease handle.');
	$observer_failure = WorkspaceMutationLock::with_repo($workspace, 'callback-compat', static fn(): string => 'observer-safe', 1, array(), static function (): void { throw new RuntimeException('observer failed'); });
	capacity_lock_assert('observer-safe' === $observer_failure, 'A failing progress observer interrupted lock admission.');

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
	$diagnostic_result = json_decode($diagnostic_output, true);
	$diagnostic_data = $diagnostic_result['error'] ?? null;
	$diagnostic_events = $diagnostic_result['events'] ?? array();
	capacity_lock_assert(is_array($diagnostic_data) && ! empty($diagnostic_data['request_id']), 'Timeout must expose a durable request identity.');
	capacity_lock_assert(1 === ($diagnostic_data['queue_position'] ?? null), 'Single waiter must report queue position one.');
	capacity_lock_assert('lock_wait' === ($diagnostic_data['progress']['phase'] ?? null), 'Timeout must identify lock-wait progress.');
	capacity_lock_assert(is_array($diagnostic_data['owner'] ?? null), 'Timeout must expose the active lock owner.');
	capacity_lock_assert(array_key_exists('estimated_wait_seconds', $diagnostic_data) && 'active_holder_release_unknown' === ($diagnostic_data['eta_status'] ?? null), 'Timeout without an owner operation deadline must not estimate completion from the DB lease.');
	$request_events = array_values(array_filter($diagnostic_events, static fn( array $event ): bool => 'lock_request' === ($event['phase'] ?? null)));
	$wait_events = array_values(array_filter($diagnostic_events, static fn( array $event ): bool => 'lock_wait' === ($event['phase'] ?? null)));
	capacity_lock_assert(1 === count($request_events) && ($diagnostic_data['request_id'] ?? null) === ($request_events[0]['request_id'] ?? null), 'Lock admission did not report its durable request identity before waiting.');
	capacity_lock_assert(2 <= count($wait_events) && 'queued' === ($wait_events[0]['state'] ?? null) && 'timed_out' === ($wait_events[count($wait_events) - 1]['state'] ?? null), 'Contended admission did not report bounded queued and terminal wait states.');
	capacity_lock_assert(1 === ($wait_events[0]['queue_position'] ?? null) && is_array($wait_events[0]['owner'] ?? null) && (float) ($wait_events[0]['elapsed_seconds'] ?? 1) < 0.5, 'Initial lock wait progress omitted timely queue or owner evidence.');
	capacity_lock_assert(false === ($diagnostic_result['mutated'] ?? true) && ! is_file($workspace . '/diagnostic-mutated'), 'Timed-out admission ran its protected mutation callback.');
	fclose($holder_pipes[1]); fclose($holder_pipes[2]); proc_close($holder);
	$diagnostic_retry = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'retry-acquired', 1);
	capacity_lock_assert('retry-acquired' === $diagnostic_retry, 'A clean retry did not acquire after the observed holder released.');
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

	if ( ! function_exists('posix_kill') || null === capacity_lock_lsof_binary() ) {
		fwrite(STDERR, "workspace-capacity-lock-concurrency: SKIP fd lifetime proof requires posix_kill and lsof\n");
	} else {
		// Bootstrap children start only after their parent has committed the
		// reservation and released admission. Killing the parent must therefore
		// leave the child unable to retain the global flock.
		$reserved = $workspace . '/bootstrap-reserved';
		$child_ready = $workspace . '/bootstrap-child-ready';
		$child_pid_path = $workspace . '/bootstrap-child-pid';
		$parent = proc_open(array( PHP_BINARY, __FILE__, 'bootstrap-parent', $workspace, $reserved, $child_ready, $child_pid_path), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $parent_pipes);
		capacity_lock_assert(is_resource($parent), 'Could not start deferred bootstrap parent.');
		fclose($parent_pipes[0]);
		$deadline = microtime(true) + 3;
		while ((! is_file($reserved) || ! is_file($child_ready) || ! is_file($child_pid_path)) && microtime(true) < $deadline) { usleep(10000); }
		capacity_lock_assert(is_file($reserved) && is_file($child_ready) && is_file($child_pid_path), 'Deferred bootstrap did not commit reservation before starting its child.');
		$child_pid = (int) file_get_contents($child_pid_path);
		$lock_path = $workspace . '/.locks/worktree-workspace-capacity-admission.lock';
		capacity_lock_assert(! capacity_lock_process_has_descriptor($child_pid, $lock_path), 'Bootstrap child inherited the global capacity lock descriptor.');
		$parent_status = proc_get_status($parent);
		posix_kill((int) $parent_status['pid'], SIGKILL);
		fclose($parent_pipes[1]); fclose($parent_pipes[2]); proc_close($parent);
		$independent = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'admitted', 1);
		capacity_lock_assert('admitted' === $independent, 'A blocked bootstrap child retained the global capacity lock after its parent exited.');
		capacity_lock_assert(@posix_kill($child_pid, 0), 'Deferred bootstrap child did not remain available for descriptor verification.');
		posix_kill($child_pid, SIGKILL);
		foreach (array($reserved, $child_ready, $child_pid_path) as $path) { unlink($path); }

		// Run the actual WorktreeBootstrapper -> ProcessRunner composer path after
		// both locks are released, then prove neither the command nor its child
		// process can retain either lock after the foreground parent is killed.
		$bootstrap_root = $workspace . '/real-bootstrap';
		mkdir($bootstrap_root . '/bin', 0777, true);
		file_put_contents($bootstrap_root . '/composer.lock', "{}\n");
		file_put_contents($bootstrap_root . '/composer.json', "{}\n");
		// Bootstrap snapshots tracked state before Composer, so this fixture must be
		// a real committed Git checkout rather than a synthetic directory.
		$git_root = escapeshellarg($bootstrap_root);
		@exec('git -C ' . $git_root . ' init && git -C ' . $git_root . ' config user.email test@example.test && git -C ' . $git_root . ' config user.name Test && git -C ' . $git_root . ' add composer.json composer.lock && git -C ' . $git_root . ' commit -m fixture', $git_output, $git_status); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Sets up the real bootstrap fixture checkout.
		capacity_lock_assert(0 === $git_status, 'Could not initialize the real bootstrap Git fixture.');
		file_put_contents($bootstrap_root . '/bin/composer', '#!/bin/sh' . "\n" . PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' bootstrap-child ' . escapeshellarg($bootstrap_root . '/child.pid') . ' &' . "\n" . PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' bootstrap-descendant ' . escapeshellarg($bootstrap_root . '/descendant.pid') . ' &' . "\n" . 'sleep 10' . "\n");
		chmod($bootstrap_root . '/bin/composer', 0755);
		$real_reserved = $workspace . '/real-bootstrap-reserved';
		$real_parent = proc_open(array( PHP_BINARY, __FILE__, 'bootstrap-real-parent', $workspace, $bootstrap_root, $real_reserved, $bootstrap_root . '/child.pid', $bootstrap_root . '/descendant.pid' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $real_pipes);
		capacity_lock_assert(is_resource($real_parent), 'Could not start real deferred bootstrap parent.');
		fclose($real_pipes[0]);
		$deadline = microtime(true) + 5;
		while ((! is_file($real_reserved) || ! is_file($bootstrap_root . '/child.pid') || ! is_file($bootstrap_root . '/descendant.pid')) && microtime(true) < $deadline) { usleep(10000); }
		capacity_lock_assert(is_file($real_reserved) && is_file($bootstrap_root . '/child.pid') && is_file($bootstrap_root . '/descendant.pid'), 'Real WorktreeBootstrapper fixture did not start its Composer child and descendant: ' . (is_file($bootstrap_root . '/result.json') ? (string) file_get_contents($bootstrap_root . '/result.json') : (string) stream_get_contents($real_pipes[2])));
		$global_lock = $workspace . '/.locks/worktree-workspace-capacity-admission.lock';
		$repo_lock = $workspace . '/.locks/worktree-repo-a.lock';
		foreach ( array( (int) file_get_contents($bootstrap_root . '/child.pid'), (int) file_get_contents($bootstrap_root . '/descendant.pid') ) as $pid ) {
			capacity_lock_assert(! capacity_lock_process_has_descriptor($pid, $global_lock) && ! capacity_lock_process_has_descriptor($pid, $repo_lock), 'Real bootstrap descendant inherited a workspace lock descriptor.');
		}
		$real_parent_status = proc_get_status($real_parent);
		posix_kill((int) $real_parent_status['pid'], SIGKILL);
		fclose($real_pipes[1]); fclose($real_pipes[2]); proc_close($real_parent);
		$second_repo = WorkspaceMutationLock::with_repo($workspace, 'repo-b', static fn(): string => 'admitted', 1);
		capacity_lock_assert('admitted' === $second_repo, 'Second repository allocation remained blocked by a real bootstrap process.');
		foreach ( array( (int) file_get_contents($bootstrap_root . '/child.pid'), (int) file_get_contents($bootstrap_root . '/descendant.pid') ) as $pid ) { posix_kill($pid, SIGKILL); }
		capacity_lock_remove_tree($bootstrap_root); unlink($real_reserved);

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
	$release = $workspace . '/fifo-release';
	$order = $workspace . '/fifo-order';
	$holder = proc_open(array( PHP_BINARY, __FILE__, 'signal-holder', $workspace, $ready, $release), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $holder_pipes);
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
	file_put_contents($release, 'release');
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
	unlink($ready); unlink($release); unlink($order);

	$policy = new class {
		use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;
	};
	$lifecycle_source = (string) file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
	capacity_lock_assert(str_contains($lifecycle_source, "'workspace-capacity-admission', \$reuse"), 'Bootstrap resume must acquire global capacity admission before its repository lock.');
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
	foreach ( array( 'capacity-state', 'admission-ready', 'second-ready', 'fanout-ready', 'fifo-ready', 'fifo-release', 'fifo-order', 'cancel-order', 'kill-ready', 'diagnostic-ready', 'repo-a-ready', 'repo-b-ready' ) as $file ) {
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
