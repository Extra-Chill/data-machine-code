<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeAgeFilter.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupSignal.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupCandidateClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorktreeAgeFilter;
use DataMachineCode\Workspace\WorktreeCleanupClassifier;
use DataMachineCode\Workspace\WorktreeCleanupCandidateClassifier;

function worktree_cleanup_candidate_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$context = array(
	'handle'      => 'repo@merged-branch',
	'repo'        => 'repo',
	'branch'      => 'merged-branch',
	'path'        => '/tmp/repo@merged-branch',
	'dirty_count' => 0,
	'created_at'  => '2026-01-01T00:00:00+00:00',
	'liveness'    => 'stale',
	'metadata'    => array( 'created_at' => '2026-01-01T00:00:00+00:00' ),
	'disk_fields' => array( 'size_bytes' => 123 ),
);

$age_filter       = null;
$evidence_called  = false;
$candidate_result = WorktreeCleanupCandidateClassifier::classify_merge_signal_path(
	$context,
	array(
		'signal' => 'github-merged-pr',
		'reason' => 'GitHub reports merged PR',
		'pr_url' => 'https://example.com/pr/1',
	),
	$age_filter,
	function () use ( &$evidence_called ): array {
		$evidence_called = true;
		return array( 'classification' => 'no_cleanup_signal' );
	},
	array( 'review_command' => 'review' )
);

worktree_cleanup_candidate_assert_same('candidate', $candidate_result['type'], 'merged signal is a candidate');
worktree_cleanup_candidate_assert_same('github-merged-pr', $candidate_result['row']['signal'], 'candidate signal is preserved');
worktree_cleanup_candidate_assert_same('github-merged-pr', $candidate_result['row']['reason_code'], 'candidate reason_code matches signal');
worktree_cleanup_candidate_assert_same(0, $candidate_result['row']['unpushed'], 'fresh safe candidate records the passed unpushed probe');
worktree_cleanup_candidate_assert_same(false, $evidence_called, 'no-signal evidence stays lazy for candidates');

$no_signal_filter = null;
$no_signal        = WorktreeCleanupCandidateClassifier::classify_merge_signal_path(
	$context,
	null,
	$no_signal_filter,
	function (): array {
		return array( 'classification' => 'no_cleanup_signal' );
	},
	array( 'review_command' => 'review' )
);

worktree_cleanup_candidate_assert_same('skip', $no_signal['type'], 'missing signal is skipped');
worktree_cleanup_candidate_assert_same('no_merge_signal', $no_signal['row']['reason_code'], 'missing signal reason_code matches cleanup contract');
worktree_cleanup_candidate_assert_same(array( 'classification' => 'no_cleanup_signal' ), $no_signal['row']['merge_signal_evidence'], 'missing signal includes evidence');

$recent_context               = $context;
$recent_context['created_at'] = '2026-06-16T00:00:00+00:00';
$recent_age_filter           = WorktreeAgeFilter::build('30d', 30 * 24 * 60 * 60, strtotime('2026-06-17T00:00:00+00:00'));
$age_skip                    = WorktreeCleanupCandidateClassifier::classify_merge_signal_path(
	$recent_context,
	array(
		'signal' => 'upstream-gone',
		'reason' => 'upstream branch is gone',
	),
	$recent_age_filter,
	fn(): array => array(),
	array()
);

worktree_cleanup_candidate_assert_same('skip', $age_skip['type'], 'recent worktree is skipped by age filter');
worktree_cleanup_candidate_assert_same('age_filter', $age_skip['row']['reason_code'], 'age skip reason_code matches cleanup contract');
worktree_cleanup_candidate_assert_same(1, $recent_age_filter['excluded'], 'age filter excluded counter is updated');

$inventory_buckets = WorktreeCleanupClassifier::buckets(
	3,
	array( 'cleanup_eligible' => 3 ),
	array(),
	WorktreeCleanupClassifier::BUCKET_CLEANUP_ELIGIBLE_UNPROBED
);
worktree_cleanup_candidate_assert_same(3, $inventory_buckets['cleanup_eligible_pending_revalidation'], 'inventory-only candidates are pending revalidation');
worktree_cleanup_candidate_assert_same(0, $inventory_buckets['safe_to_remove_now'], 'inventory-only candidates are not labeled safe to remove now');

$probed_buckets = WorktreeCleanupClassifier::buckets(2, array(), array());
worktree_cleanup_candidate_assert_same(2, $probed_buckets['safe_to_remove_now'], 'probed cleanup candidates keep the safe-to-remove bucket');

$engine = new class {
	use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

	public function summary( array $candidates, string $bucket ): array {
		$method = new ReflectionMethod($this, 'build_worktree_cleanup_summary');
		return $method->invoke($this, $candidates, array(), array(), null, $bucket);
	}

	private function worktree_cleanup_skipped_next_commands( array $skipped_by_reason ): array {
		return array();
	}

	private function summarize_top_worktree_rows( array $rows, string $field ): array {
		return array();
	}
};

$inventory_summary = $engine->summary(array( array( 'signal' => 'cleanup_eligible' ) ), WorktreeCleanupClassifier::BUCKET_CLEANUP_ELIGIBLE_UNPROBED);
worktree_cleanup_candidate_assert_same(1, $inventory_summary['inventory_cleanup_candidate_count'], 'inventory summary exposes cheap cleanup candidates separately');
worktree_cleanup_candidate_assert_same(0, $inventory_summary['fresh_safe_removable_count'], 'inventory summary does not label unprobed candidates fresh safe');

$fresh_summary = $engine->summary(array( array( 'signal' => 'github-merged-pr' ) ), WorktreeCleanupClassifier::BUCKET_SAFE_TO_REMOVE_NOW);
worktree_cleanup_candidate_assert_same(0, $fresh_summary['inventory_cleanup_candidate_count'], 'fresh summary does not count inventory-only candidates');
worktree_cleanup_candidate_assert_same(1, $fresh_summary['fresh_safe_removable_count'], 'fresh summary exposes freshly probed safe removals');

echo "worktree-cleanup-candidate-classifier: ok\n";
