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
	dmc_test_assert(! str_contains($function, 'new Workspace('), sprintf('WorkspaceAbilities::%s() reaches the lifecycle aggregate directly.', $method));
}

dmc_test_assert(str_contains($command, 'CleanupRunControlOperation'), 'WorkspaceCommand does not delegate job-backed cleanup control.');
dmc_test_assert(! str_contains($command, 'SystemTaskDrainability'), 'WorkspaceCommand still owns cleanup drainability decisions.');
dmc_test_assert(! is_file(dirname(__DIR__) . '/inc/Tools/WorkspaceTools.php'), 'Legacy workspace BaseTool wrappers were restored.');

echo "workspace-operation-architecture ok\n";
