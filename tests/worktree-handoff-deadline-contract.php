<?php

declare(strict_types=1);

function handoff_deadline_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$source = (string) file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
handoff_deadline_contract_assert(str_contains($source, 'resolve_remote_default_ref($primary, $remaining)'), 'Handoff proof did not forward its remaining remote-probe deadline to remote-default resolution.');
handoff_deadline_contract_assert(str_contains($source, 'resolve_remote_default_ref( string $repo_path, int $timeout_seconds = 0 )'), 'Remote-default resolver does not accept an explicit bounded timeout.');
handoff_deadline_contract_assert(str_contains($source, "'symbolic-ref --quiet refs/remotes/origin/HEAD', \$timeout_seconds"), 'Remote-default resolver did not forward its timeout to run_git.');
handoff_deadline_contract_assert(str_contains($source, "new \\WP_Error('worktree_handoff_revalidation_timeout'"), 'Bounded remote-default timeout does not produce the typed handoff timeout.');
handoff_deadline_contract_assert(str_contains($source, 'worktree_handoff_remaining_seconds'), 'Handoff probes do not use their deadline-safe timeout conversion.');
handoff_deadline_contract_assert(str_contains($source, 'floor($deadline - microtime(true))'), 'Handoff revalidation rounds partial GitRunner seconds beyond its remote-probe deadline.');
handoff_deadline_contract_assert(str_contains($source, 'bounded handoff remote probe has less than one safe Git execution second remaining'), 'Handoff revalidation does not explain partial-second refusal.');
handoff_deadline_contract_assert(str_contains($source, 'get_metadata_fresh($handle);') && str_contains($source, '$deadline = microtime(true) + self::HANDOFF_REMOTE_PROBE_TIMEOUT;'), 'The remote-probe deadline starts before unbounded fresh metadata lookup.');
handoff_deadline_contract_assert(! str_contains($source, 'HANDOFF_REVALIDATION_TIMEOUT'), 'The implementation still claims an aggregate handoff deadline.');

fwrite(STDOUT, "worktree-handoff-deadline-contract: ok\n");
