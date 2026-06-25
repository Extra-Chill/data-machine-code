<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function bounded_cleanup_processed_candidates_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

final class BoundedCleanupProcessedCandidateHarness {
	use WorkspaceWorktreeCleanupEngine;

	public function processed( array $candidate, string $action, array $outcome ): array {
		return $this->build_bounded_cleanup_processed_candidate($candidate, $action, $outcome);
	}
}

$harness = new BoundedCleanupProcessedCandidateHarness();

$candidate = array(
	'handle'      => 'repo@stale-cleanup-row',
	'repo'        => 'repo',
	'branch'      => 'stale-cleanup-row',
	'path'        => '/tmp/repo@stale-cleanup-row',
	'reason_code' => 'cleanup_eligible',
	'dirty'       => 0,
);

$processed = $harness->processed(
	$candidate,
	'skipped',
	array(
		'handle'      => 'repo@stale-cleanup-row',
		'repo'        => 'repo',
		'branch'      => 'stale-cleanup-row',
		'path'        => '/tmp/repo@stale-cleanup-row',
		'reason_code' => 'dirty_worktree',
		'reason'      => 'working tree dirty (2 entries)',
		'dirty'       => 2,
		'unpushed'    => 1,
	)
);

bounded_cleanup_processed_candidates_assert_same(2, $processed['dirty'], 'processed candidate carries fresh dirty count');
bounded_cleanup_processed_candidates_assert_same(1, $processed['unpushed'], 'processed candidate carries fresh unpushed count');
bounded_cleanup_processed_candidates_assert_same('skipped', $processed['final_action'], 'processed candidate records final action');
bounded_cleanup_processed_candidates_assert_same('dirty_worktree', $processed['final_reason_code'], 'processed candidate records blocker bucket');
bounded_cleanup_processed_candidates_assert_same('cleanup_eligible', $processed['reason_code'], 'planned reason remains available separately');

echo "bounded-cleanup-processed-candidates: ok\n";
