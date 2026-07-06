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

echo "worktree-cleanup-cli-scope: ok\n";
