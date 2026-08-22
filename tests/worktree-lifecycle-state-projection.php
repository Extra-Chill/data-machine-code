<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupCandidateClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMetadataReconciliation.php';

use DataMachineCode\Workspace\WorktreeContextInjector;

final class WorktreeLifecycleStateProjectionHarness {
	use DataMachineCode\Workspace\WorkspaceMetadataReconciliation;

	protected function workspace_row_triage_status_from_metadata( array $metadata ): string {
		return '';
	}

	protected function recover_worktree_identity_from_metadata( array $row ): array {
		return array( 'repo' => (string) $row['repo'], 'branch' => (string) $row['branch'], 'path' => (string) $row['path'], 'conflicts' => array(), 'hydrated_fields' => array() );
	}

	public function candidate_reason( array $row ): ?string {
		return ( new ReflectionMethod($this, 'worktree_metadata_reconciliation_candidate_reason') )->invoke($this, $row);
	}

	public function classify( array $metadata ): array {
		return ( new ReflectionMethod($this, 'build_worktree_metadata_backfill_classification') )->invoke($this, $metadata, 'repo@merged', 'repo', 'main', '/workspace/repo@merged');
	}
}

function worktree_lifecycle_state_projection_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
	}
}

$finalized = array(
	'handle' => 'repo@merged', 'repo' => 'repo', 'branch' => 'main', 'path' => '/workspace/repo@merged',
	'created_at' => '2026-01-01T00:00:00Z', 'observed_at' => '2026-01-01T00:00:00Z',
	'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE, 'finalized_state' => WorktreeContextInjector::STATE_MERGED,
	'finalized_at' => '2026-01-02T00:00:00Z', 'cleanup_eligible_at' => '2026-01-02T00:00:00Z',
	'pr_url' => 'https://github.com/Extra-Chill/data-machine-code/pull/1049', 'last_seen_at' => gmdate('c'),
);
$harness = new WorktreeLifecycleStateProjectionHarness();

worktree_lifecycle_state_projection_assert_same(WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE, WorktreeContextInjector::project_lifecycle_state($finalized), 'merged PR metadata remains cleanup eligible after branch rename/main checkout');
worktree_lifecycle_state_projection_assert_same(WorktreeContextInjector::LIVENESS_STOPPED, WorktreeContextInjector::classify_liveness($finalized)['liveness'], 'heartbeat cannot revive finalized lifecycle state');
worktree_lifecycle_state_projection_assert_same('explicit_cleanup_eligibility', $harness->candidate_reason(array( 'metadata' => $finalized, 'repo' => 'repo', 'branch' => 'main', 'path' => '/workspace/repo@merged' )), 'contradictory finalized metadata enters reconciliation');

$classification = $harness->classify($finalized);
worktree_lifecycle_state_projection_assert_same(WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE, $classification['proposed_metadata']['lifecycle_state'], 'reconciliation normalizes the projected lifecycle state');
worktree_lifecycle_state_projection_assert_same('explicit_cleanup_eligibility', $classification['source_map']['lifecycle_state'], 'reconciliation records canonical precedence as its source');
$normalized = $classification['proposed_metadata'];
worktree_lifecycle_state_projection_assert_same(null, $harness->candidate_reason(array( 'metadata' => $normalized, 'repo' => 'repo', 'branch' => 'main', 'path' => '/workspace/repo@merged' )), 'reconciliation is idempotent after normalization');

$dirty_finalized = $finalized;
$dirty_finalized['dirty'] = 2;
$dirty_finalized['unpushed'] = 1;
worktree_lifecycle_state_projection_assert_same(WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE, WorktreeContextInjector::project_lifecycle_state($dirty_finalized), 'dirty finalized rows retain lifecycle projection without discarding safety evidence');
worktree_lifecycle_state_projection_assert_same(2, $dirty_finalized['dirty'], 'dirty safety evidence remains intact');
worktree_lifecycle_state_projection_assert_same(1, $dirty_finalized['unpushed'], 'unpushed safety evidence remains intact');

$active = array( 'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE, 'last_seen_at' => gmdate('c') );
worktree_lifecycle_state_projection_assert_same(WorktreeContextInjector::STATE_ACTIVE, WorktreeContextInjector::project_lifecycle_state($active), 'ordinary active metadata remains active');
worktree_lifecycle_state_projection_assert_same(WorktreeContextInjector::LIVENESS_LIVE, WorktreeContextInjector::classify_liveness($active)['liveness'], 'ordinary active heartbeat remains live');

echo "worktree-lifecycle-state-projection: ok\n";
