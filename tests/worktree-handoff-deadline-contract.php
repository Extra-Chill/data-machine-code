<?php

declare(strict_types=1);

function handoff_deadline_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$source = (string) file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
handoff_deadline_contract_assert(str_contains($source, 'resolve_remote_default_ref($primary, $remaining)'), 'Handoff proof did not forward its remaining aggregate deadline to remote-default resolution.');
handoff_deadline_contract_assert(str_contains($source, 'resolve_remote_default_ref( string $repo_path, int $timeout_seconds = 0 )'), 'Remote-default resolver does not accept an explicit bounded timeout.');
handoff_deadline_contract_assert(str_contains($source, "'symbolic-ref --quiet refs/remotes/origin/HEAD', \$timeout_seconds"), 'Remote-default resolver did not forward its timeout to run_git.');
handoff_deadline_contract_assert(str_contains($source, "new \\WP_Error('worktree_handoff_revalidation_timeout'"), 'Bounded remote-default timeout does not produce the typed handoff timeout.');
handoff_deadline_contract_assert(str_contains($source, 'if ( $remaining <= 0 )'), 'Handoff probes do not refuse an expired aggregate deadline before Git execution.');

fwrite(STDOUT, "worktree-handoff-deadline-contract: ok\n");
