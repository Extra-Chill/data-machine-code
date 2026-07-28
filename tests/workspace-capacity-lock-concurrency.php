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
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;

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

	$policy = new class {
		use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;
	};
	capacity_lock_assert(1800 === $policy::worktree_capacity_wait_timeout_seconds(true), 'Bootstrap admissions must not inherit the 30-second repo-lock wait.');
	echo "workspace-capacity-lock-concurrency: ok\n";
} finally {
	if ( is_file($workspace . '/ready') ) {
		unlink($workspace . '/ready');
	}
	if ( is_dir($workspace . '/.locks') ) {
		foreach ( scandir($workspace . '/.locks') ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				unlink($workspace . '/.locks/' . $entry);
			}
		}
		rmdir($workspace . '/.locks');
	}
	rmdir($workspace);
}
