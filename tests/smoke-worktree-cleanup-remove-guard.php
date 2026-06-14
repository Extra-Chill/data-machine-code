<?php
/**
 * Source-level guard for destructive cleanup removal behavior.
 *
 * Run: php tests/smoke-worktree-cleanup-remove-guard.php
 */

declare( strict_types=1 );

$source_path  = __DIR__ . '/../inc/Workspace/WorkspaceWorktreeCleanupEngine.php';
$service_path = __DIR__ . '/../inc/Workspace/CleanupRunService.php';
$workspace_path = __DIR__ . '/../inc/Workspace/Workspace.php';
$source       = file_get_contents($source_path);
$service      = file_get_contents($service_path);
$workspace    = file_get_contents($workspace_path);

if ( false === $source || false === $service || false === $workspace ) {
	fwrite(STDERR, "Could not read cleanup source.\n");
	exit(1);
}

if ( ! preg_match('/private function remove_worktree_by_path\(.*?^\t}\n\n\t\/\*\*/ms', $source, $matches) ) {
	fwrite(STDERR, "Could not isolate remove_worktree_by_path().\n");
	exit(1);
}

$function = $matches[0];
$failures = array();

if ( ! str_contains($function, '$this->run_git($primary_path, $cmd, $remove_timeout_seconds)') ) {
	$failures[] = 'git worktree remove must use the configurable cleanup removal timeout';
}

if ( ! str_contains($workspace, 'protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60') ) {
	$failures[] = 'cleanup removal must have a larger removal-specific default timeout';
}

if ( ! str_contains($source, "'reason_code' => \$is_timeout ? 'remove_timeout' : 'remove_failed'") ) {
	$failures[] = 'cleanup removal timeout failures must be distinguished from generic remove failures';
}

if ( ! str_contains($source, 'timeout_resume_command') || ! str_contains($source, '--remove-timeout=') ) {
	$failures[] = 'bounded cleanup timeout rows must emit a resumable remove-timeout command';
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

if ( ! preg_match("/'direct_apply_plan'\s*=>\s*true/", $service) ) {
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
