<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct( private string $code = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;

function capacity_auto_reclaim_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

final class CapacityAutoReclaimHarness {
	use WorkspaceWorktreeLifecycle;

	/** @var array<int,array<string,mixed>> */
	private array $budgets;

	public function __construct( array $budgets ) {
		$this->budgets = $budgets;
	}

	public function reclaim( array $before ): array {
		return $this->reclaim_capacity_eligible_artifacts('repo', 'branch', false, array( 'bytes' => 10 ), $before);
	}

	protected function inspect_worktree_capacity( string $repo, string $branch, bool $force, array $demand_plan ): array {
		return array_shift($this->budgets) ?? array( 'status' => 'refused' );
	}

	protected function run_capacity_artifact_reclaim(): array|\WP_Error {
		return array(
			'state'           => 'completed_with_skips',
			'applied'         => 2,
			'bytes_reclaimed' => 60,
			'remaining_blocked_reasons' => array(
				'dirty_worktree'   => array( 'count' => 1 ),
				'unpushed_commits' => array( 'count' => 2 ),
			),
		);
	}
}

$before = array( 'status' => 'refused', 'projected_free_bytes' => 90, 'effective_refuse_bytes' => 100 );
$harness = new CapacityAutoReclaimHarness(array( array( 'status' => 'ok', 'projected_free_bytes' => 150, 'effective_refuse_bytes' => 100 ) ));
$result = $harness->reclaim($before);

capacity_auto_reclaim_assert_same('ok', $result['after']['status'] ?? null, 'Reclaim crossing the capacity floor must use the recomputed capacity.');
capacity_auto_reclaim_assert_same(true, $result['evidence']['attempted'] ?? null, 'Refused admission must attempt artifact-only reclaim.');
capacity_auto_reclaim_assert_same(60, $result['evidence']['reclaimed_bytes'] ?? null, 'Reclaim evidence must retain measured bytes.');
capacity_auto_reclaim_assert_same(array( 'dirty_worktree' => 1, 'unpushed_commits' => 2 ), $result['evidence']['skipped'] ?? null, 'Reclaim evidence must retain protected skip categories.');
capacity_auto_reclaim_assert_same('admitted_after_reclaim', $result['evidence']['final_decision'] ?? null, 'Crossing the floor must admit after safe reclaim.');

echo "worktree-capacity-auto-reclaim: ok\n";
