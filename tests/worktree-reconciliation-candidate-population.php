<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__));

require_once dirname(__DIR__) . '/inc/Workspace/TaskUrl.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupCandidateClassifier.php';
require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
require_once dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';
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

	public function dead_bootstrap_proposal( array $row, string $path ): array {
		$method = new ReflectionMethod($this, 'build_dead_bootstrap_coordinator_reconciliation_proposal');
		return $method->invoke($this, $row, $row['metadata'], $path, array(
			'coordinator'  => array( 'state' => 'stale', 'reason' => 'owner_process_missing' ),
			'active_child' => array( 'state' => 'stale', 'reason' => 'no_active_child' ),
		));
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

$dead_bootstrap = $metadata;
$dead_bootstrap['allocation_id'] = 'allocation-1225';
$dead_bootstrap['handoff_continuation_identity'] = array( 'version' => 1, 'allocation_id' => 'allocation-1225' );
$dead_bootstrap['provisioning']['bootstrap'] = array(
	'requested'            => true,
	'outcome'              => 'running',
	'resume_command'       => 'studio wp datamachine-code workspace worktree add repo fix/issue-961',
	'capacity_reservation' => array( 'bytes' => 10, 'inodes' => 1 ),
	'coordinator'          => array( 'pid' => 999999, 'identity' => array( 'platform' => 'linux_proc', 'start_ticks' => '1' ) ),
);
DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn(): array => array( 'state' => 'stale', 'reason' => 'owner_process_missing' ));
$harness = new WorktreeReconciliationCandidatePopulationHarness();
worktree_reconciliation_candidate_population_assert_same(
	'dead_bootstrap_coordinator',
	$harness->reason(array( 'metadata' => $dead_bootstrap )),
	'verified-dead bootstrap coordinators enter metadata reconciliation'
);

$noop_path = sys_get_temp_dir() . '/dmc-dead-bootstrap-noop-' . bin2hex(random_bytes(6));
mkdir($noop_path, 0777, true);
try {
	$proposal = $harness->dead_bootstrap_proposal(array(
		'handle'          => $metadata['handle'],
		'repo'            => $metadata['repo'],
		'branch'          => $metadata['branch'],
		'path'            => $noop_path,
		'metadata'        => $dead_bootstrap,
		'hydrated_fields' => array(),
		'stored_identity' => array(),
	), $noop_path)['proposal'];
	worktree_reconciliation_candidate_population_assert_same('succeeded', $proposal['proposed_metadata']['provisioning']['bootstrap']['outcome'] ?? null, 'dead no-op bootstrap repair is terminal and ready');
	worktree_reconciliation_candidate_population_assert_same(false, isset($proposal['proposed_metadata']['provisioning']['bootstrap']['coordinator']), 'dead no-op bootstrap repair clears coordinator ownership');
	worktree_reconciliation_candidate_population_assert_same('allocation-1225', $proposal['proposed_metadata']['allocation_id'] ?? null, 'dead bootstrap repair changed allocation identity');
	worktree_reconciliation_candidate_population_assert_same($dead_bootstrap['handoff_continuation_identity'], $proposal['proposed_metadata']['handoff_continuation_identity'] ?? null, 'dead no-op repair discarded the exact handoff continuation');

	file_put_contents($noop_path . '/composer.lock', '{}');
	$interrupted = $harness->dead_bootstrap_proposal(array(
		'handle'          => $metadata['handle'],
		'repo'            => $metadata['repo'],
		'branch'          => $metadata['branch'],
		'path'            => $noop_path,
		'metadata'        => $dead_bootstrap,
		'hydrated_fields' => array(),
		'stored_identity' => array(),
	), $noop_path)['proposal'];
	worktree_reconciliation_candidate_population_assert_same('interrupted', $interrupted['proposed_metadata']['provisioning']['bootstrap']['outcome'] ?? null, 'dead bootstrap with declared work remains fail-closed');
	worktree_reconciliation_candidate_population_assert_same($dead_bootstrap['provisioning']['bootstrap']['resume_command'], $interrupted['proposed_metadata']['provisioning']['bootstrap']['resume_command'] ?? null, 'interrupted bootstrap repair lost its deterministic resume command');
} finally {
	DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(null);
	if ( is_file($noop_path . '/composer.lock') ) {
		unlink($noop_path . '/composer.lock');
	}
	rmdir($noop_path);
}

$live_bootstrap                 = $dead_bootstrap;
$live_bootstrap['last_seen_at'] = gmdate('c');
DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn(): array => array( 'state' => 'active', 'identity' => array( 'platform' => 'linux_proc', 'start_ticks' => '1' ) ));
worktree_reconciliation_candidate_population_assert_same(null, $harness->reason(array( 'metadata' => $live_bootstrap )), 'live bootstrap coordinator was exposed as repairable');
DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn(): array => array( 'state' => 'unverifiable', 'reason' => 'owner_probe_denied' ));
worktree_reconciliation_candidate_population_assert_same(null, $harness->reason(array( 'metadata' => $live_bootstrap )), 'unverifiable bootstrap coordinator was exposed as repairable');
DataMachineCode\Workspace\WorktreeContextInjector::set_bootstrap_owner_probe_for_test(null);

echo "worktree-reconciliation-candidate-population: ok\n";
