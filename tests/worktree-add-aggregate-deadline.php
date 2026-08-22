<?php

declare(strict_types=1);

const ABSPATH = __DIR__ . '/fixtures/';

final class WP_Error {
	public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;

final class Worktree_Add_Aggregate_Deadline_Harness {
	use WorkspaceWorktreeLifecycle;
	protected string $workspace_path = '';
	public function workspace(string $path): void { $this->workspace_path = $path; }
	public function remaining(float $deadline): int { return $this->worktree_operation_remaining_seconds($deadline); }
	public function timeout(string $phase, int $timeout, float $started, array $extra = array()): WP_Error { return $this->worktree_operation_timeout($phase, $timeout, $started, $extra); }
	public function lock_result(mixed $result, string $phase, int $timeout, float $started): mixed { return $this->worktree_operation_lock_result($result, $phase, $timeout, $started); }
	public function rollback(string $primary, string $path, string $branch): void { $this->rollback_rejected_worktree($primary, $path, $branch, true); }
	protected function run_git(string $path, string $args, int $timeout = 30): array|WP_Error {
		$lines = array();
		$code = 0;
		exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1', $lines, $code);
		return 0 === $code ? array( 'output' => implode("\n", $lines) ) : new WP_Error('git_failed', implode("\n", $lines));
	}
}

function deadline_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }

function deadline_remove_tree(string $path): void {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

try {
	$harness = new Worktree_Add_Aggregate_Deadline_Harness();
	$started = microtime(true) - 3.0;
	$timed_out = $harness->lock_result(new WP_Error('workspace_repo_busy', 'busy', array(
		'timed_out' => true,
		'lock_key' => 'worktree-workspace-capacity-admission',
		'active_lock' => array('owner' => 'exited-owner'),
	)), 'capacity_lock_wait', 2, $started);
	deadline_assert(is_wp_error($timed_out), 'expired capacity acquisition must return an error');
	deadline_assert('worktree_operation_timeout' === $timed_out->get_error_code(), 'lock expiry must use the aggregate timeout type');
	deadline_assert('capacity_lock_wait' === ($timed_out->get_error_data()['phase'] ?? null), 'lock expiry must name the acquisition phase');
	deadline_assert('exited-owner' === ($timed_out->get_error_data()['lock_owner']['active_lock']['owner'] ?? null), 'lock expiry must retain owner evidence');
	$rollback = $harness->timeout('git_worktree_add', 2, $started, array('cleanup' => 'rollback_requested'));
	deadline_assert('rollback_requested' === ($rollback->get_error_data()['cleanup'] ?? null), 'post-create timeout must report rollback evidence');
	$root = sys_get_temp_dir() . '/dmc-deadline-rollback-' . bin2hex(random_bytes(6));
	mkdir($root, 0777, true);
	$primary = $root . '/primary';
	$worktree = $root . '/timed-out';
	$harness->workspace($root);
	exec('git init -b main ' . escapeshellarg($primary) . ' >/dev/null 2>&1');
	exec('git -C ' . escapeshellarg($primary) . ' config user.email test@example.test');
	exec('git -C ' . escapeshellarg($primary) . ' config user.name test');
	file_put_contents($primary . '/README.md', "fixture\n");
	exec('git -C ' . escapeshellarg($primary) . ' add README.md && git -C ' . escapeshellarg($primary) . ' commit -m initial >/dev/null 2>&1');
	exec('git -C ' . escapeshellarg($primary) . ' worktree add -b timed-out ' . escapeshellarg($worktree) . ' main >/dev/null 2>&1');
	$harness->rollback($primary, $worktree, 'timed-out');
	deadline_assert(! is_dir($worktree), 'timeout rollback left its worktree directory behind');
	$branches = shell_exec('git -C ' . escapeshellarg($primary) . ' branch --list timed-out');
	deadline_assert('' === trim((string) $branches), 'timeout rollback left its created branch behind');
	exec('git -C ' . escapeshellarg($primary) . ' worktree add -b next-add ' . escapeshellarg($worktree) . ' main >/dev/null 2>&1', $output, $exit_code);
	deadline_assert(0 === $exit_code && is_file($worktree . '/.git'), 'the next add did not succeed after timeout rollback');
	exec('git -C ' . escapeshellarg($primary) . ' worktree remove --force ' . escapeshellarg($worktree) . ' >/dev/null 2>&1');
	exec('git -C ' . escapeshellarg($primary) . ' branch -D next-add >/dev/null 2>&1');
	deadline_remove_tree($root);
	deadline_assert(0 === $harness->remaining(microtime(true) - 0.01), 'expired deadlines must not grant another command second');
	deadline_assert(1 === $harness->remaining(microtime(true) + 0.01), 'a partial second must retain one bounded command second');
	fwrite(STDOUT, "worktree-add-aggregate-deadline ok\n");
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
}
