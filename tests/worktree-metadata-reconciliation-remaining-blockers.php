<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHandle.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMetadataReconciliation.php';

function worktree_metadata_reconciliation_remaining_blockers_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$reconciler = new class {
	use DataMachineCode\Workspace\WorkspaceCoreUtilities;
	use DataMachineCode\Workspace\WorkspaceMetadataReconciliation;
};

$method = new ReflectionMethod($reconciler, 'build_worktree_metadata_reconciliation_remaining_blockers');

$dry_run = $method->invoke(
	$reconciler,
	array(
		array(
			'handle'            => 'repo@one',
			'reason_code'       => 'metadata_backfill',
			'proposed_metadata' => array( 'lifecycle_state' => 'active' ),
		),
	),
	array(),
	array(
		array(
			'handle'      => 'repo@manual',
			'reason_code' => 'missing_identity',
		),
	),
	true,
	25,
	'30s'
);

worktree_metadata_reconciliation_remaining_blockers_assert_same(2, $dry_run['total'], 'dry-run complete pass counts proposals and skips as remaining blockers');
worktree_metadata_reconciliation_remaining_blockers_assert_same(1, $dry_run['needs_apply'], 'dry-run complete pass reports repairable rows needing apply');
worktree_metadata_reconciliation_remaining_blockers_assert_same(array( 'metadata_backfill' => 1 ), $dry_run['proposed_by_reason'], 'dry-run groups repairable blockers by reconcile reason');
worktree_metadata_reconciliation_remaining_blockers_assert_same(array( 'active' => 1 ), $dry_run['proposed_by_state'], 'dry-run groups repairable blockers by proposed state');
worktree_metadata_reconciliation_remaining_blockers_assert_same(array( 'missing_identity' => 1 ), $dry_run['skipped_by_reason'], 'dry-run groups skipped blockers by reconcile reason');
worktree_metadata_reconciliation_remaining_blockers_assert_same('apply_reviewed_plan', $dry_run['next_action'], 'dry-run next action points at apply when it can help');
worktree_metadata_reconciliation_remaining_blockers_assert_same('studio wp datamachine-code workspace worktree reconcile-metadata --apply --limit=25 --offset=0 --until-budget=30s --format=json', $dry_run['next_command'], 'dry-run next command is actionable');

$scoped_dry_run = $method->invoke(
	$reconciler,
	array(
		array(
			'handle'            => 'homeboy@one',
			'reason_code'       => 'metadata_backfill',
			'proposed_metadata' => array( 'lifecycle_state' => 'active' ),
		),
	),
	array(),
	array(),
	true,
	25,
	'30s',
	array(
		'argument' => 'homeboy',
		'repo'     => 'homeboy',
		'handle'   => null,
	)
);
worktree_metadata_reconciliation_remaining_blockers_assert_same('studio wp datamachine-code workspace worktree reconcile-metadata homeboy --apply --limit=25 --offset=0 --until-budget=30s --format=json', $scoped_dry_run['next_command'], 'scoped dry-run next command preserves repo scope');

$manual = $method->invoke(
	$reconciler,
	array(
		array(
			'handle'            => 'repo@written',
			'reason_code'       => 'metadata_backfill',
			'proposed_metadata' => array( 'lifecycle_state' => 'active' ),
		),
	),
	array( array( 'handle' => 'repo@written' ) ),
	array( array( 'handle' => 'repo@manual', 'reason_code' => 'manual_review_identity_metadata' ) ),
	false,
	25,
	''
);

worktree_metadata_reconciliation_remaining_blockers_assert_same(1, $manual['total'], 'apply complete pass excludes written proposals from remaining blockers');
worktree_metadata_reconciliation_remaining_blockers_assert_same(0, $manual['needs_apply'], 'apply complete pass has no pending proposals after write');
worktree_metadata_reconciliation_remaining_blockers_assert_same(1, $manual['manual_repair'], 'apply complete pass identifies manual repair blockers');
worktree_metadata_reconciliation_remaining_blockers_assert_same('manual_repair_required', $manual['next_action'], 'apply complete pass does not suggest another pass for manual blockers');
worktree_metadata_reconciliation_remaining_blockers_assert_same(null, $manual['next_command'], 'manual repair blockers do not get a misleading next command');

$source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeInventoryCleanup.php');
if ( false === $source || ! str_contains($source, "'reconcile_reason_code'   => 'missing_metadata'") || ! str_contains($source, "'reconcile_skipped_state' => 'not_yet_reconciled'") ) {
	throw new RuntimeException('Inventory needs_metadata_reconcile rows must expose reconcile reason and skipped state.');
}

echo "worktree-metadata-reconciliation-remaining-blockers: ok\n";
