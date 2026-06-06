<?php
/**
 * Pure-PHP smoke test for worktree cleanup classification buckets.
 *
 * Run: php tests/smoke-worktree-cleanup-classifier.php
 */

declare( strict_types=1 );

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/');
}

require __DIR__ . '/../inc/Workspace/WorktreeCleanupClassifier.php';

use DataMachineCode\Workspace\WorktreeCleanupClassifier;

$failures = array();
$total    = 0;
$assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  ok {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  fail {$label}\n";
};

echo "Worktree cleanup classifier - smoke\n";

$assert('metadata skip maps to reconciliation', WorktreeCleanupClassifier::BUCKET_NEEDS_RECONCILIATION === WorktreeCleanupClassifier::bucket_for_reason('needs_metadata_reconcile'));
$assert('lifecycle skip maps to reconciliation', WorktreeCleanupClassifier::BUCKET_NEEDS_RECONCILIATION === WorktreeCleanupClassifier::bucket_for_reason('lifecycle_reconciliation_candidate'));
$assert('dirty skip maps to dirty blocker', WorktreeCleanupClassifier::BUCKET_BLOCKED_BY_DIRTY_OR_UNPUSHED === WorktreeCleanupClassifier::bucket_for_reason('dirty_worktree'));
$assert('unpushed skip maps to dirty blocker', WorktreeCleanupClassifier::BUCKET_BLOCKED_BY_DIRTY_OR_UNPUSHED === WorktreeCleanupClassifier::bucket_for_reason('unpushed_commits'));
$assert('artifact-only dirty has distinct bucket', WorktreeCleanupClassifier::BUCKET_ARTIFACT_ONLY_DIRTY === WorktreeCleanupClassifier::bucket_for_reason('artifact_only_dirty_worktree'));
$assert('active/no-signal maps to full review', WorktreeCleanupClassifier::BUCKET_NEEDS_FULL_REVIEW === WorktreeCleanupClassifier::bucket_for_reason('active_no_signal'));
$assert('unknown reasons fail into full review', WorktreeCleanupClassifier::BUCKET_NEEDS_FULL_REVIEW === WorktreeCleanupClassifier::bucket_for_reason('future_unknown_reason'));

$buckets = WorktreeCleanupClassifier::buckets(
	2,
	array(
		'cleanup_eligible' => 1,
	),
	array(
		'needs_metadata_reconcile'                    => 2,
		'requires_full_scan'                          => 1,
		'lifecycle_reconciliation_candidate'          => 1,
		'active_no_signal'                            => 3,
		'github_unknown'                              => 1,
		'dirty_worktree'                              => 1,
		'merged_pr_with_only_obsolete_dirty_changes'  => 1,
		'unpushed_commits'                            => 1,
		'artifact_only_dirty_worktree'                => 1,
	)
);

$assert('bucket counts safe candidates', 2 === (int) ( $buckets['safe_to_remove_now'] ?? -1 ));
$assert('bucket counts reconciliation reasons', 4 === (int) ( $buckets['needs_reconciliation'] ?? -1 ));
$assert('bucket counts full review reasons', 4 === (int) ( $buckets['needs_full_review'] ?? -1 ));
$assert('bucket counts dirty/unpushed blockers', 3 === (int) ( $buckets['blocked_by_dirty_or_unpushed'] ?? -1 ));
$assert('bucket counts artifact-only dirty separately', 1 === (int) ( $buckets['artifact_only_dirty_worktree'] ?? -1 ));
$assert('legacy explicit cleanup alias remains', 1 === (int) ( $buckets['explicit_cleanup_candidates'] ?? -1 ));
$assert('legacy metadata alias remains', 3 === (int) ( $buckets['metadata_reconciliation_candidates'] ?? -1 ));
$assert('legacy lifecycle alias remains', 1 === (int) ( $buckets['lifecycle_reconciliation_candidates'] ?? -1 ));
$assert('legacy active alias remains', 3 === (int) ( $buckets['active_no_signal'] ?? -1 ));
$assert('legacy dirty alias remains', 3 === (int) ( $buckets['dirty_unpushed'] ?? -1 ));

$assert('metadata rows can produce resolver rows', WorktreeCleanupClassifier::is_resolver_reason('needs_metadata_reconcile'));
$assert('dirty rows do not produce resolver rows', ! WorktreeCleanupClassifier::is_resolver_reason('dirty_worktree'));
$assert('metadata resolver type is stable', 'metadata_reconciliation' === WorktreeCleanupClassifier::resolver_type('requires_full_scan'));
$assert('lifecycle resolver type is stable', 'lifecycle_reconciliation' === WorktreeCleanupClassifier::resolver_type('lifecycle_reconciliation_candidate'));
$assert('active resolver type is merge signal', 'merge_signal' === WorktreeCleanupClassifier::resolver_type('active_no_signal'));

if ( ! empty($failures) ) {
	echo "\nFAIL: " . count($failures) . " assertion(s) failed out of {$total}\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure}\n";
	}
	exit(1);
}

echo "\nOK ({$total} assertions)\n";
exit(0);
