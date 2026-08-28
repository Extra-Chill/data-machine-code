<?php

declare(strict_types=1);

require_once __DIR__ . '/support/bootstrap.php';

$abilities = dmc_test_source('inc/Abilities/WorkspaceAbilities.php');
$command   = dmc_test_source('inc/Cli/Commands/WorkspaceCommand.php');

foreach ( array( 'worktreePlan', 'worktreeAdd' ) as $method ) {
	$start = strpos($abilities, 'public static function ' . $method . '(');
	dmc_test_assert(false !== $start, sprintf('Unable to locate WorkspaceAbilities::%s().', $method));
	$next     = strpos($abilities, "\n\tpublic static function ", $start + 1);
	$function = substr($abilities, $start, false === $next ? null : $next - $start);
	dmc_test_assert(str_contains($function, 'WorktreeAllocationOperation'), sprintf('WorkspaceAbilities::%s() bypasses the allocation operation.', $method));
	dmc_test_assert(str_contains($function, 'new WorktreeAllocationOperation(new Workspace())'), sprintf('WorkspaceAbilities::%s() does not pass the lifecycle contract into the operation.', $method));
	dmc_test_assert(! str_contains($function, 'worktree_plan('), sprintf('WorkspaceAbilities::%s() still uses the positional plan adapter.', $method));
	dmc_test_assert(! str_contains($function, 'worktree_add('), sprintf('WorkspaceAbilities::%s() still uses the positional add adapter.', $method));
}

$operation = dmc_test_source('inc/Workspace/WorktreeAllocationOperation.php');
dmc_test_assert(str_contains($operation, 'WorktreeLifecycle $lifecycle'), 'WorktreeAllocationOperation does not depend on the lifecycle contract.');
dmc_test_assert(! str_contains($operation, 'new Workspace('), 'WorktreeAllocationOperation still constructs the trait aggregate internally.');

$lifecycle = dmc_test_source('inc/Workspace/WorkspaceWorktreeLifecycle.php');
dmc_test_assert(1 !== preg_match('/function worktree_plan\s*\(\s*string/', $lifecycle), 'Positional worktree_plan() adapter was restored.');
dmc_test_assert(1 !== preg_match('/function worktree_add\s*\(\s*string/', $lifecycle), 'Positional worktree_add() adapter was restored.');

dmc_test_assert(str_contains($command, 'CleanupRunControlOperation'), 'WorkspaceCommand does not delegate job-backed cleanup control.');
dmc_test_assert(! str_contains($command, 'SystemTaskDrainability'), 'WorkspaceCommand still owns cleanup drainability decisions.');
dmc_test_assert(! is_file(dirname(__DIR__) . '/inc/Tools/WorkspaceTools.php'), 'Legacy workspace BaseTool wrappers were restored.');

echo "workspace-operation-architecture ok\n";
