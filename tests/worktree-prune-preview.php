<?php

/**
 * Preview-first Git worktree registration prune coverage.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');

require_once dirname(__DIR__) . '/inc/Workspace/GitCheckout.php';

use DataMachineCode\Workspace\GitCheckout;

function worktree_prune_preview_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function worktree_prune_preview_git( string $command ): string {
	$output = array();
	$status = 0;
	exec($command . ' 2>&1', $output, $status);
	if ( 0 !== $status ) {
		throw new RuntimeException(sprintf('Git command failed (%d): %s', $status, implode("\n", $output)));
	}

	return implode("\n", $output);
}

function worktree_prune_preview_remove_tree( string $path ): void {
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

$porcelain = <<<'PORCELAIN'
worktree /workspace/repo
HEAD abcdef
branch refs/heads/main

worktree /workspace/repo@dead
HEAD 123456
dead 1
prunable gitdir file points to non-existent location

worktree /workspace/repo@live
HEAD fedcba
branch refs/heads/feat
PORCELAIN;

$parsed = GitCheckout::prunable_registrations_from_porcelain($porcelain);
worktree_prune_preview_assert(1 === count($parsed) && '/workspace/repo@dead' === ($parsed[0]['path'] ?? null), 'Porcelain parser must return only prunable registrations.');
worktree_prune_preview_assert('gitdir file points to non-existent location' === ($parsed[0]['reason'] ?? null), 'Porcelain parser must retain the Git prunable reason.');
worktree_prune_preview_assert(array() === GitCheckout::prunable_registrations_from_porcelain(''), 'Empty porcelain must yield no candidates.');
worktree_prune_preview_assert('worktree prune --dry-run -v --expire=now' === GitCheckout::prune_git_args(true), 'Dry-run Git args must preview without mutation.');
worktree_prune_preview_assert('worktree prune -v --expire=now' === GitCheckout::prune_git_args(false), 'Apply Git args must prune immediately.');

$lifecycle = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
$ability   = file_get_contents(dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php');
$cli       = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
worktree_prune_preview_assert(is_string($lifecycle) && str_contains($lifecycle, "'dry_run'"), 'Bounded prune must accept a dry-run option.');
worktree_prune_preview_assert(is_string($lifecycle) && str_contains($lifecycle, 'worktree_inventory_refresh()') && str_contains($lifecycle, '$repair_inventory'), 'Dry-run prune must skip the full inventory refresh.');
worktree_prune_preview_assert(is_string($ability) && str_contains($ability, "! array_key_exists( 'dry_run', \$input ) || ! empty( \$input['dry_run'] )"), 'Ability prune must default to preview.');
worktree_prune_preview_assert(is_string($cli) && str_contains($cli, "empty( \$assoc_args['yes'] )"), 'CLI prune must stay preview-first unless --yes is passed.');
worktree_prune_preview_assert(is_string($cli) && str_contains($cli, "'after-repo'"), 'CLI prune must expose bounded continuation.');

$root    = sys_get_temp_dir() . '/dmc-prune-preview-' . getmypid() . '-' . bin2hex(random_bytes(4));
$repo    = $root . '/repo';
$attempt = $root . '/repo@deleted-attempt';
$live    = $root . '/repo@live-attempt';

try {
	worktree_prune_preview_git(sprintf('git init --initial-branch=main %s', escapeshellarg($repo)));
	worktree_prune_preview_git(sprintf('git -C %s config user.email test@example.test', escapeshellarg($repo)));
	worktree_prune_preview_git(sprintf('git -C %s config user.name Test', escapeshellarg($repo)));
	worktree_prune_preview_git(sprintf('git -C %s commit --allow-empty -m initial', escapeshellarg($repo)));
	worktree_prune_preview_git(sprintf('git -C %s worktree add --detach %s', escapeshellarg($repo), escapeshellarg($attempt)));
	worktree_prune_preview_git(sprintf('git -C %s worktree add --detach %s', escapeshellarg($repo), escapeshellarg($live)));
	worktree_prune_preview_assert(unlink($attempt . '/.git') && rmdir($attempt), 'attempt fixture must be deleted while its Git registration remains');

	$list = worktree_prune_preview_git(sprintf('git -C %s worktree list --porcelain', escapeshellarg($repo)));
	$dead = GitCheckout::prunable_registrations_from_porcelain($list);
	worktree_prune_preview_assert(1 === count($dead) && str_ends_with((string) ($dead[0]['path'] ?? ''), '/repo@deleted-attempt'), 'Cheap porcelain must identify the stale registration before prune.');

	$preview = worktree_prune_preview_git(sprintf('git -C %s %s', escapeshellarg($repo), GitCheckout::prune_git_args(true)));
	worktree_prune_preview_assert(str_contains($preview, basename($attempt)), 'Git dry-run must name the stale registration.');
	$still = worktree_prune_preview_git(sprintf('git -C %s worktree list --porcelain', escapeshellarg($repo)));
	worktree_prune_preview_assert(1 === count(GitCheckout::prunable_registrations_from_porcelain($still)), 'Dry-run must leave the stale registration in place.');
	worktree_prune_preview_assert(is_dir($live), 'Dry-run must preserve a live worktree.');

	$applied = worktree_prune_preview_git(sprintf('git -C %s %s', escapeshellarg($repo), GitCheckout::prune_git_args(false)));
	worktree_prune_preview_assert(str_contains($applied, basename($attempt)), 'Apply must prune the stale registration.');
	$after = worktree_prune_preview_git(sprintf('git -C %s worktree list --porcelain', escapeshellarg($repo)));
	worktree_prune_preview_assert(array() === GitCheckout::prunable_registrations_from_porcelain($after), 'Apply must leave no prunable registrations.');
	worktree_prune_preview_assert(is_dir($live), 'Apply must preserve a live worktree.');

	echo "worktree-prune-preview: ok\n";
} finally {
	worktree_prune_preview_remove_tree($attempt);
	worktree_prune_preview_remove_tree($live);
	worktree_prune_preview_remove_tree($repo);
	worktree_prune_preview_remove_tree($root);
}
