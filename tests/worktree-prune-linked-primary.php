<?php

/**
 * Regression coverage for pruning a deleted attempt from a linked primary.
 *
 * Managed DMC primaries can themselves be Git worktrees, whose `.git` is a
 * marker file. The reconciliation must recognize that checkout and let Git
 * prune only the registration whose worktree path has disappeared.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');

require_once dirname(__DIR__) . '/inc/Workspace/GitCheckout.php';

use DataMachineCode\Workspace\GitCheckout;

function linked_primary_prune_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

/**
 * @return string
 */
function linked_primary_prune_git( string $command ): string {
	$output = array();
	$status = 0;
	exec($command . ' 2>&1', $output, $status);
	if ( 0 !== $status ) {
		throw new RuntimeException(sprintf('Git command failed (%d): %s', $status, implode("\n", $output)));
	}

	return implode("\n", $output);
}

function linked_primary_prune_remove_tree( string $path ): void {
	if ( ! is_dir($path) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir($item->getPathname());
		} else {
			unlink($item->getPathname());
		}
	}
	rmdir($path);
}

$root    = sys_get_temp_dir() . '/dmc-linked-primary-prune-' . getmypid() . '-' . bin2hex(random_bytes(4));
$repo    = $root . '/repo';
$primary = $root . '/repo-primary';
$attempt = $root . '/repo@deleted-attempt';
$live    = $root . '/repo@live-attempt';

try {
	linked_primary_prune_git(sprintf('git init --initial-branch=main %s', escapeshellarg($repo)));
	linked_primary_prune_git(sprintf('git -C %s config user.email test@example.test', escapeshellarg($repo)));
	linked_primary_prune_git(sprintf('git -C %s config user.name Test', escapeshellarg($repo)));
	linked_primary_prune_git(sprintf('git -C %s commit --allow-empty -m initial', escapeshellarg($repo)));
	linked_primary_prune_git(sprintf('git -C %s worktree add --detach %s', escapeshellarg($repo), escapeshellarg($primary)));
	linked_primary_prune_git(sprintf('git -C %s worktree add --detach %s', escapeshellarg($primary), escapeshellarg($attempt)));
	linked_primary_prune_git(sprintf('git -C %s worktree add --detach %s', escapeshellarg($primary), escapeshellarg($live)));

	linked_primary_prune_assert(is_file($primary . '/.git'), 'linked primary fixture must use a .git marker file');
	linked_primary_prune_assert(GitCheckout::exists($primary), 'linked primary must be recognized as a valid Git checkout');
	linked_primary_prune_assert(is_dir($attempt) && is_file($attempt . '/.git'), 'deleted attempt fixture must be registered before removal');
	linked_primary_prune_assert(is_dir($live), 'live worktree fixture must exist before pruning');

	// Empty detached worktrees contain only their .git marker, so this models a deleted attempt without touching a real checkout.
	linked_primary_prune_assert(unlink($attempt . '/.git') && rmdir($attempt), 'attempt fixture must be deleted while its Git registration remains');
	$preview = linked_primary_prune_git(sprintf('git -C %s worktree prune --dry-run --verbose --expire=now', escapeshellarg($primary)));
	linked_primary_prune_assert(str_contains($preview, basename($attempt)), 'Git dry-run must identify the stale attempt registration before reconciliation');

	$pruned = linked_primary_prune_git(sprintf('git -C %s worktree prune --verbose --expire=now', escapeshellarg($primary)));
	linked_primary_prune_assert(str_contains($pruned, basename($attempt)), 'reconciliation must prune the deleted attempt registration');
	linked_primary_prune_git(sprintf('git -C %s status --porcelain', escapeshellarg($live)));
	linked_primary_prune_assert(is_dir($live), 'reconciliation must preserve a live worktree');

	$source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
	linked_primary_prune_assert(false !== $source && str_contains($source, 'GitCheckout::exists($primary_path)'), 'DMC worktree prune must recognize linked primary checkouts');
	linked_primary_prune_assert(false !== $source && str_contains($source, 'worktree prune -v --expire=now'), 'DMC worktree prune must reconcile proven stale registrations immediately');

	printf("worktree-prune-linked-primary: ok\n");
} finally {
	linked_primary_prune_remove_tree($attempt);
	linked_primary_prune_remove_tree($live);
	linked_primary_prune_remove_tree($primary);
	linked_primary_prune_remove_tree($repo);
	linked_primary_prune_remove_tree($root);
}
