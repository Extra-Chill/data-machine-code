<?php

declare(strict_types=1);

function handoff_advertisement_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$source = (string) file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
handoff_advertisement_assert(str_contains($source, "'ls-remote --symref origin HEAD'"), 'Handoff proof does not query the remote HEAD advertisement.');
handoff_advertisement_assert(str_contains($source, "'/^([0-9a-f]{40,64})\\s+HEAD$/mi'"), 'Handoff proof does not retain the advertised HEAD SHA.');
handoff_advertisement_assert(str_contains($source, "'remote_default_advertised_sha' => \$remote_default['sha']"), 'Handoff proof does not persist the advertised SHA.');
handoff_advertisement_assert(str_contains($source, "hash_equals(\$remote_default['sha'], trim( (string) \$default['output'] ))"), 'Handoff proof does not reject stale fetched remote refs after a remote race.');

fwrite(STDOUT, "worktree-handoff-remote-advertisement-contract: ok\n");
