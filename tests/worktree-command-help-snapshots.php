<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	function worktree_help_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	define('ABSPATH', __DIR__ . '/');
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$definitions = \DataMachineCode\Cli\Commands\WorkspaceCommand::worktree_command_definitions();
	$operations  = array_keys((new \ReflectionClass(\DataMachineCode\Cli\Commands\WorkspaceCommand::class))->getConstant('WORKTREE_OPERATIONS'));
	$operations  = array_merge($operations, array( 'locks', 'backfill-origin-session' ));

	$defined_operations = array_keys($definitions);
	sort($defined_operations);
	sort($operations);
	worktree_help_assert($defined_operations === $operations, 'Worktree command help snapshot lost an operation.');
	foreach ( $definitions as $operation => $definition ) {
		worktree_help_assert(isset($definition['shortdesc']), sprintf('%s help lacks a short description.', $operation));
		worktree_help_assert(str_contains((string) ( $definition['longdesc'] ?? '' ), '## EXAMPLES'), sprintf('%s help lacks an example.', $operation));
		worktree_help_assert(! str_contains((string) ( $definition['longdesc'] ?? '' ), 'Delegates to the canonical'), sprintf('%s help uses placeholder fallback content.', $operation));
		worktree_help_assert(isset($definition['synopsis']), sprintf('%s help lacks a synopsis.', $operation));
		foreach ( $definition['synopsis'] as $argument ) {
			if ( in_array($argument['type'] ?? '', array( 'assoc', 'flag' ), true) ) {
				worktree_help_assert(true === ( $argument['optional'] ?? false ), sprintf('%s --%s must be optional in WP-CLI.', $operation, $argument['name'] ?? 'unknown'));
			}
		}
	}

	$assert_synopsis = static function ( string $operation, array $expected ) use ( $definitions ): void {
		$actual = array_column($definitions[ $operation ]['synopsis'], 'name');
		foreach ( $expected as $name ) {
			worktree_help_assert(in_array($name, $actual, true), sprintf('%s synopsis rejects supported --%s.', $operation, $name));
		}
	};
	$assert_synopsis('cleanup', array( 'dry-run', 'force', 'skip-github', 'inventory-only', 'include-repaired-metadata', 'limit', 'offset', 'until-budget', 'apply-plan', 'older-than', 'sort', 'format', 'verbose', 'only' ));
	$assert_synopsis('emergency-cleanup', array( 'apply', 'force', 'apply-plan', 'format' ));
	$assert_synopsis('cleanup-artifacts', array( 'dry-run', 'force', 'allow-active-artifact-cleanup', 'allow-unavailable-process-probe', 'limit', 'offset', 'only-handle', 'exhaustive', 'safety-probes', 'sort', 'older-than', 'apply-plan', 'format' ));
	$artifact_cleanup_options = array_column($definitions['cleanup-artifacts']['synopsis'], null, 'name');
	worktree_help_assert(true === ( $artifact_cleanup_options['repo']['optional'] ?? false ), 'Artifact cleanup must accept the unscoped command shown in its help.');
	$assert_synopsis('abandoned', array( 'apply', 'force', 'discard-unpushed', 'limit', 'passes', 'offset', 'stage', 'scope', 'until-budget', 'format', 'verbose' ));
	$assert_synopsis('active-no-signal-drain', array( 'apply', 'force', 'discard-unpushed', 'limit', 'passes', 'offset', 'stage', 'scope', 'until-budget', 'format', 'verbose' ));
	foreach ( array( 'abandoned', 'active-no-signal-drain' ) as $operation ) {
		$options = array_column($definitions[ $operation ]['synopsis'], null, 'name');
		worktree_help_assert(true === ( $options['repo']['optional'] ?? false ), sprintf('%s must accept its documented global invocation.', $operation));
	}
	$assert_synopsis('cleanup-eligible-drain', array( 'apply', 'force', 'discard-unpushed', 'include-repaired-metadata', 'limit', 'passes', 'remove-timeout', 'older-than', 'sort', 'until-budget', 'format', 'verbose' ));
	$assert_synopsis('bounded-cleanup-eligible-apply', array( 'dry-run', 'force', 'discard-unpushed', 'via-jobs', 'include-repaired-metadata', 'limit', 'older-than', 'sort', 'remove-timeout', 'scope', 'format' ));
	$bounded_cleanup_options = array_column($definitions['bounded-cleanup-eligible-apply']['synopsis'], null, 'name');
	worktree_help_assert(true === ( $bounded_cleanup_options['repo']['optional'] ?? false ), 'Bounded cleanup must accept the unscoped commands emitted by hygiene.');

	$add = $definitions['add'];
	worktree_help_assert('Create an isolated, managed worktree.' === $add['shortdesc'], 'Add help snapshot changed.');
	worktree_help_assert(array_column($add['synopsis'], 'name') === array( 'repo', 'branch', 'from', 'base', 'base-ref', 'base-branch', 'skip-context-injection', 'skip-bootstrap', 'allow-stale', 'allow-unverified-freshness', 'rebase-base', 'force', 'remediate-capacity', 'remediate-capacity-dry-run', 'task-url', 'task-ref', 'require-task-tracker', 'reuse-policy', 'purpose', 'owner-run-ref', 'cleanup-policy', 'verbose', 'format' ), 'Add help option snapshot changed.');
	worktree_help_assert(str_contains($add['longdesc'], 'worktree add data-machine-code fix/1025'), 'Add help lacks a creation example.');
	$add_options = array_column($add['synopsis'], null, 'name');
	worktree_help_assert(str_contains($add_options['reuse-policy']['description'], 'reuse_compatible|isolated|recycle_terminal|claim_expired'), 'Compact add help does not enumerate reuse policies.');
	worktree_help_assert(str_contains($add_options['cleanup-policy']['description'], 'manual|remove_on_success|preserve_on_failure'), 'Compact add help does not enumerate cleanup policies.');
	worktree_help_assert(str_contains($add_options['reuse-policy']['description'], 'purpose, owner_run_ref, and cleanup_policy=remove_on_success'), 'Compact add help does not describe the isolated same-task contract.');

	$remove = $definitions['remove'];
	worktree_help_assert(array_column($remove['synopsis'], 'name') === array( 'repo-or-handle', 'branch', 'force', 'format' ), 'Remove help option snapshot changed.');
	worktree_help_assert(str_contains($remove['longdesc'], 'worktree remove data-machine-code fix/1025'), 'Remove help lacks a removal example.');

	$finalize = $definitions['finalize'];
	worktree_help_assert(array_column($finalize['synopsis'], 'name') === array( 'handle', 'pr', 'state', 'owner-terminal-outcome', 'format' ), 'Finalize help option snapshot changed.');
	worktree_help_assert(str_contains($finalize['longdesc'], 'worktree finalize data-machine-code@fix-1025'), 'Finalize help lacks a finalization example.');

	$cleanup = $definitions['cleanup'];
	worktree_help_assert(array_column($cleanup['synopsis'], 'name') === array( 'repo', 'dry-run', 'force', 'skip-github', 'inventory-only', 'include-repaired-metadata', 'limit', 'offset', 'until-budget', 'apply-plan', 'older-than', 'sort', 'format', 'verbose', 'only' ), 'Cleanup help option snapshot changed.');
	worktree_help_assert(str_contains($cleanup['longdesc'], 'worktree cleanup --dry-run --format=json'), 'Cleanup help lacks a review example.');

	echo "worktree-command-help-snapshots: ok\n";
}
