<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}
if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function cleanup_recovery_run( string $path, string $command ): string {
	$output = array();
	$status = 0;
	exec('git -C ' . escapeshellarg($path) . ' ' . $command . ' 2>&1', $output, $status);
	if ( 0 !== $status ) {
		throw new RuntimeException(implode("\n", $output));
	}
	return trim(implode("\n", $output));
}

function cleanup_recovery_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function cleanup_recovery_remove_fixture( string $path ): void {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) {
		if ( $entry->isDir() && ! $entry->isLink() ) {
			rmdir($entry->getPathname());
		} else {
			unlink($entry->getPathname());
		}
	}
	rmdir($path);
}

final class CleanupRecoveryHarness {
	use WorkspaceWorktreeCleanupEngine;
	protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;
	public string $workspace_path;

	public function __construct(private string $primary) {
		$this->workspace_path = dirname($primary);
	}

	public function preserve( array $candidate ): array|WP_Error {
		$method = new ReflectionMethod($this, 'preserve_cleanup_recovery_ref');
		return $method->invoke($this, $candidate);
	}

	private function get_primary_path( string $repo ): string {
		return $this->primary;
	}

	private function run_git( string $path, string $command, int $timeout = 0 ): array|WP_Error {
		try {
			return array( 'output' => cleanup_recovery_run($path, $command) );
		} catch ( RuntimeException $error ) {
			return new WP_Error('git_failed', $error->getMessage());
		}
	}
}

$root    = sys_get_temp_dir() . '/dmc-cleanup-recovery-' . getmypid();
$primary = $root . '/example';
$work    = $root . '/example@candidate';
mkdir($primary, 0777, true);
cleanup_recovery_run($primary, 'init -q');
cleanup_recovery_run($primary, 'config user.name Test');
cleanup_recovery_run($primary, 'config user.email test@example.test');
file_put_contents($primary . '/base.txt', "base\n");
cleanup_recovery_run($primary, 'add base.txt');
cleanup_recovery_run($primary, 'commit -qm base');
cleanup_recovery_run($primary, 'worktree add -qb candidate ' . escapeshellarg($work));
file_put_contents($work . '/candidate.txt', "candidate\n");
cleanup_recovery_run($work, 'add candidate.txt');
cleanup_recovery_run($work, 'commit -qm candidate');
$commit = cleanup_recovery_run($work, 'rev-parse HEAD');

$harness  = new CleanupRecoveryHarness($primary);
$recovery = $harness->preserve(array( 'repo' => 'example', 'path' => $work ));
cleanup_recovery_assert(! is_wp_error($recovery), is_wp_error($recovery) ? $recovery->get_error_message() : 'recovery preservation failed');
cleanup_recovery_assert('refs/dmc/recovery/' . $commit === ($recovery['recovery_ref'] ?? null), 'recovery ref does not identify the exact candidate commit');

cleanup_recovery_run($primary, 'worktree remove ' . escapeshellarg($work));
cleanup_recovery_run($primary, 'branch -D candidate');
cleanup_recovery_assert($commit === cleanup_recovery_run($primary, 'rev-parse --verify ' . escapeshellarg((string) $recovery['recovery_ref'])), 'recovery ref did not survive branch deletion');
exec((string) $recovery['recovery_command'], $restore_output, $restore_status);
cleanup_recovery_assert(0 === $restore_status && is_dir($work), 'recovery command did not reconstruct the removed worktree');
cleanup_recovery_assert($commit === cleanup_recovery_run($work, 'rev-parse HEAD'), 'reconstructed worktree does not resolve to the preserved commit');

cleanup_recovery_run($primary, 'worktree remove ' . escapeshellarg($work));
cleanup_recovery_run($primary, 'update-ref -d ' . escapeshellarg((string) $recovery['recovery_ref']) . ' ' . escapeshellarg($commit));
cleanup_recovery_remove_fixture($root);

echo "worktree-cleanup-recovery-ref ok\n";
