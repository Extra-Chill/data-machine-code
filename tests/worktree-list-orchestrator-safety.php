<?php

declare(strict_types=1);

$root      = dirname(__DIR__);
$cli_source = file_get_contents($root . '/inc/Cli/Commands/WorkspaceCommand.php');
$list_source = file_get_contents($root . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');

if ( false === $cli_source || false === $list_source ) {
	throw new RuntimeException('Unable to read worktree list sources.');
}

foreach ( array( "'dirty'    => 0 !== (int) \$dirty", "'unpushed' => 0 !== (int) \$unpushed", "'primary'  => ! empty(\$wt['is_primary'])" ) as $expected ) {
	if ( ! str_contains($cli_source, $expected) ) {
		throw new RuntimeException(sprintf('Worktree list safety mapping is missing %s.', $expected));
	}
}

if ( ! str_contains($list_source, '$unpushed_commits = $this->count_unpushed_commits($wt[\'path\']);') ) {
	throw new RuntimeException('Status-enabled worktree listings must reuse the unpushed commit probe.');
}

echo "worktree-list-orchestrator-safety: ok\n";
