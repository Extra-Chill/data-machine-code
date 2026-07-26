<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__));

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupCandidateClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMetadataReconciliation.php';

final class WorktreeReconciliationCandidatePopulationHarness {
	use DataMachineCode\Workspace\WorkspaceMetadataReconciliation;

	protected function workspace_row_triage_status_from_metadata( array $metadata ): string {
		return '';
	}

	protected function recover_worktree_identity_from_metadata( array $row ): array {
		return array(
			'repo'            => (string) ( $row['repo'] ?? 'repo' ),
			'branch'          => (string) ( $row['branch'] ?? 'fix/issue-961' ),
			'path'            => (string) ( $row['path'] ?? '/workspace/repo@issue-961' ),
			'conflicts'       => array(),
			'hydrated_fields' => array(),
		);
	}

	public function reason( array $row ): ?string {
		$method = new ReflectionMethod($this, 'worktree_metadata_reconciliation_candidate_reason');
		return $method->invoke($this, $row);
	}
}

function worktree_reconciliation_candidate_population_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . sprintf(' expected=%s actual=%s', var_export($expected, true), var_export($actual, true)));
	}
}

$metadata = array(
	'handle'          => 'repo@issue-961',
	'repo'            => 'repo',
	'branch'          => 'fix/issue-961',
	'path'            => '/workspace/repo@issue-961',
	'created_at'      => '2026-01-01T00:00:00Z',
	'observed_at'     => '2026-01-01T00:00:00Z',
	'last_seen_at'    => '2026-01-01T00:00:00Z',
	'lifecycle_state' => DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
	'origin_task'     => array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/961' ),
);
$liveness = DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness($metadata);

worktree_reconciliation_candidate_population_assert_same(
	true,
	DataMachineCode\Workspace\WorktreeCleanupCandidateClassifier::needs_lifecycle_reconciliation($metadata, (string) $liveness['liveness']),
	'task-backed stale cleanup rows require lifecycle reconciliation'
);
worktree_reconciliation_candidate_population_assert_same(
	'lifecycle_reconciliation_candidate',
	(new WorktreeReconciliationCandidatePopulationHarness())->reason(array( 'metadata' => $metadata )),
	'task-backed stale rows enter metadata reconciliation pages'
);

$live_metadata                 = $metadata;
$live_metadata['last_seen_at'] = gmdate('c');
$live_liveness                 = DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness($live_metadata);
worktree_reconciliation_candidate_population_assert_same(
	false,
	DataMachineCode\Workspace\WorktreeCleanupCandidateClassifier::needs_lifecycle_reconciliation($live_metadata, (string) $live_liveness['liveness']),
	'live task-backed rows remain protected from lifecycle reconciliation'
);

$finalized_metadata                    = $metadata;
$finalized_metadata['lifecycle_state'] = DataMachineCode\Workspace\WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE;
worktree_reconciliation_candidate_population_assert_same(
	false,
	DataMachineCode\Workspace\WorktreeCleanupCandidateClassifier::needs_lifecycle_reconciliation($finalized_metadata, DataMachineCode\Workspace\WorktreeContextInjector::LIVENESS_STALE),
	'finalized rows leave the reconciliation population'
);

echo "worktree-reconciliation-candidate-population: ok\n";
