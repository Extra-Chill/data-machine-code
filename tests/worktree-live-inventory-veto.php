<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool {
		return false;
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeAgeFilter.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupSignal.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupCandidateClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeInventoryCleanup.php';

use DataMachineCode\Workspace\WorkspaceWorktreeInventoryCleanup;
use DataMachineCode\Workspace\WorktreeContextInjector;

function worktree_live_inventory_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$harness = new class {
	use WorkspaceWorktreeInventoryCleanup;

	public array $rows = array();

	public function preview(): array {
		$method = new ReflectionMethod($this, 'worktree_cleanup_inventory_only');
		return $method->invoke($this, '', '', false, null, 0, '');
	}

	private function build_workspace_inventory_rows(): array {
		return $this->rows;
	}

	private function normalize_worktree_operation_scope( string $scope ): array {
		return array( 'type' => 'all', 'value' => $scope );
	}

	private function worktree_row_matches_operation_scope( array $row, array $scope ): bool {
		return true;
	}

	private function workspace_row_triage_status_from_metadata( array $metadata ): string {
		return '';
	}

	private function build_submodule_worktree_cleanup_skip( array $row ): ?array {
		return null;
	}

	private function sort_worktree_cleanup_rows( array $rows, string $sort ): array {
		return $rows;
	}

	private function build_worktree_cleanup_summary( array $candidates, array $removed, array $skipped, ?array $age_filter, string $candidate_bucket ): array {
		return array(
			'candidates' => count($candidates),
			'skipped'    => count($skipped),
		);
	}

	private function build_worktree_cleanup_pagination( int $offset, ?int $limit, int $processed, int $total, bool $budget_stopped, ?array $budget_context ): ?array {
		return null;
	}
};

$metadata = array(
	'lifecycle_state'    => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
	'cleanup_eligible_at' => '2026-01-01T00:00:00+00:00',
);
$base_row = array(
	'handle'          => 'repo@cleanup-eligible',
	'repo'            => 'repo',
	'branch'          => 'cleanup-eligible',
	'branch_slug'     => 'cleanup-eligible',
	'path'            => '/tmp/repo@cleanup-eligible',
	'is_worktree'     => true,
	'created_at'      => '2026-01-01T00:00:00+00:00',
	'liveness_reason' => 'registered_runtime_live',
	'metadata'        => $metadata,
);

$harness->rows = array(array_merge($base_row, array( 'liveness' => WorktreeContextInjector::LIVENESS_LIVE )));
$live          = $harness->preview();
worktree_live_inventory_assert_same(0, count($live['candidates']), 'live cleanup-eligible inventory row is not a candidate');
worktree_live_inventory_assert_same('live_worktree', $live['skipped'][0]['reason_code'] ?? null, 'live inventory row has stable protection reason');

$harness->rows = array(array_merge($base_row, array( 'liveness' => WorktreeContextInjector::LIVENESS_STOPPED )));
$stopped       = $harness->preview();
worktree_live_inventory_assert_same(1, count($stopped['candidates']), 'stopped cleanup-eligible inventory row remains removable');
worktree_live_inventory_assert_same('cleanup_eligible', $stopped['candidates'][0]['reason_code'] ?? null, 'stopped inventory row preserves cleanup signal');

echo "worktree-live-inventory-veto: ok\n";
