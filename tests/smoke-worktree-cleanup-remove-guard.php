<?php
/**
 * Source-level guard for destructive cleanup removal behavior.
 *
 * Run: php tests/smoke-worktree-cleanup-remove-guard.php
 */

declare( strict_types=1 );

$source_path  = __DIR__ . '/../inc/Workspace/WorkspaceWorktreeCleanupEngine.php';
$service_path = __DIR__ . '/../inc/Workspace/CleanupRunService.php';
$source       = file_get_contents($source_path);
$service      = file_get_contents($service_path);

if ( false === $source || false === $service ) {
	fwrite(STDERR, "Could not read cleanup source.\n");
	exit(1);
}

if ( ! preg_match('/private function remove_worktree_by_path\(.*?^\t}\n\n\t\/\*\*/ms', $source, $matches) ) {
	fwrite(STDERR, "Could not isolate remove_worktree_by_path().\n");
	exit(1);
}

$function = $matches[0];
$failures = array();

if ( ! str_contains($function, '$this->run_git($primary_path, $cmd, self::CLEANUP_GIT_PROBE_TIMEOUT)') ) {
	$failures[] = 'git worktree remove must use the cleanup git timeout';
}

if ( str_contains($function, 'rm -rf') ) {
	$failures[] = 'remove_worktree_by_path must not fall back to rm -rf';
}

if ( ! str_contains($function, 'worktree_remove_incomplete') ) {
	$failures[] = 'surviving worktree directory must return a row-level failure';
}

if ( ! str_contains($source, 'apply_worktree_cleanup_plan_candidates') || ! str_contains($source, '$direct_apply_plan && ! $dry_run') ) {
	$failures[] = 'DB-backed cleanup apply must have a direct plan path';
}

if ( ! str_contains($service, "'direct_apply_plan' => true") ) {
	$failures[] = 'CleanupRunService must request direct plan apply for worktree rows';
}

if ( $failures ) {
	echo "Worktree cleanup remove guard failed:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit(1);
}

echo "Worktree cleanup remove guard OK\n";
