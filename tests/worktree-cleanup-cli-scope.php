<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
if ( false === $source ) {
	throw new RuntimeException('Unable to read WorkspaceCommand.php');
}

$cleanup_case_start = strpos($source, "case 'cleanup':");
if ( false === $cleanup_case_start ) {
	throw new RuntimeException('cleanup CLI case was not found');
}

$cleanup_case_end = strpos($source, "case 'reconcile-metadata':", $cleanup_case_start);
if ( false === $cleanup_case_end ) {
	throw new RuntimeException('cleanup CLI case end was not found');
}

$cleanup_case = substr($source, $cleanup_case_start, $cleanup_case_end - $cleanup_case_start);
if ( ! str_contains($cleanup_case, '$input[\'repo\'] = (string) $args[1];') ) {
	throw new RuntimeException('workspace worktree cleanup must forward its positional repo/worktree scope into ability input');
}

$artifact_case_start = strpos($source, "case 'cleanup-artifacts':");
$artifact_case_end = false === $artifact_case_start ? false : strpos($source, "case 'emergency-cleanup':", $artifact_case_start);
$artifact_case = false === $artifact_case_start || false === $artifact_case_end ? '' : substr($source, $artifact_case_start, $artifact_case_end - $artifact_case_start);
if ( ! str_contains($artifact_case, '$input[\'repo\'] = (string) $args[1];') ) {
	throw new RuntimeException('workspace worktree cleanup-artifacts must forward its positional repo/worktree scope into ability input');
}

$engine = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php');
if ( false === $engine || ! str_contains($engine, 'worktree_row_matches_operation_scope($wt, $scope)') || ! str_contains($engine, "'scope'      => \$scope") ) {
	throw new RuntimeException('workspace worktree cleanup must constrain discovery and reports to its normalized scope');
}

if ( ! str_contains($source, 'is workspace-wide and does not accept a repository argument') ) {
	throw new RuntimeException('workspace-wide worktree operations must reject a positional repository argument');
}

echo "worktree-cleanup-cli-scope: ok\n";
