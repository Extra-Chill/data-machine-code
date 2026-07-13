<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
if ( false === $source ) {
	throw new RuntimeException('Unable to read WorkspaceCommand.php');
}

$get_case_start = strpos($source, "case 'get':", strpos($source, 'private function renderWorktreeResult'));
$list_case_start = strpos($source, "case 'list':", $get_case_start);
$next_case_start = strpos($source, "case 'prune':", $list_case_start);
if ( false === $get_case_start || false === $list_case_start || false === $next_case_start ) {
	throw new RuntimeException('Unable to locate worktree get renderer case.');
}

$get_renderer = substr($source, $get_case_start, $next_case_start - $get_case_start);
foreach ( array( "'worktree_not_found'", "'success' => false", 'WP_CLI::halt(1)' ) as $expected ) {
	if ( ! str_contains($get_renderer, $expected) ) {
		throw new RuntimeException(sprintf('JSON worktree get not-found contract is missing %s.', $expected));
	}
}

echo "worktree-get-cli-json-contract: ok\n";
