<?php

declare(strict_types=1);

function handoff_deadline_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$source = (string) file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
handoff_deadline_contract_assert(str_contains($source, '$deadline = microtime(true) + self::HANDOFF_REMOTE_PROBE_TIMEOUT;'), 'Handoff revalidation does not establish its deadline before lock acquisition.');
handoff_deadline_contract_assert(str_contains($source, 'function () use ( $handle, $proof, $deadline )'), 'Handoff deadline is not passed through the locked revalidation phase.');
handoff_deadline_contract_assert(2 === substr_count($source, 'WorktreeContextInjector::get_metadata_fresh($handle);') || 1 === substr_count($source, 'WorktreeContextInjector::get_metadata_fresh($handle);'), 'Handoff metadata lookup is not visible to the deadline contract.');
handoff_deadline_contract_assert(str_contains($source, "'ls-remote --symref origin HEAD', \$timeout_seconds"), 'Handoff proof did not require bounded remote default-branch evidence.');
handoff_deadline_contract_assert(str_contains($source, "'remote_default_changed_during_verification'"), 'Handoff proof does not reject a remote advertisement that differs from the fetched remote ref.');
handoff_deadline_contract_assert(str_contains($source, "new \\WP_Error('worktree_handoff_revalidation_timeout'"), 'Bounded remote-default timeout does not produce the typed handoff timeout.');
handoff_deadline_contract_assert(str_contains($source, 'worktree_handoff_remaining_seconds'), 'Handoff probes do not use their deadline-safe timeout conversion.');
handoff_deadline_contract_assert(str_contains($source, 'floor($deadline - microtime(true))'), 'Handoff revalidation rounds partial GitRunner seconds beyond its remote-probe deadline.');
handoff_deadline_contract_assert(str_contains($source, 'bounded handoff remote probe has less than one safe Git execution second remaining'), 'Handoff revalidation does not explain partial-second refusal.');
handoff_deadline_contract_assert(2 <= substr_count($source, 'worktree_handoff_remaining_seconds($deadline) <= 0'), 'Handoff revalidation does not refuse deadlines exhausted by lock acquisition or metadata lookup.');

fwrite(STDOUT, "worktree-handoff-deadline-contract: ok\n");
