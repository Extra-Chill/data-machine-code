<?php

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

	function capacity_recovery_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	final class CapacityRecoveryHarness {
		use WorkspaceWorktreeLifecycle;

		public array $reconcile_options = array();
		public array $plan_options = array();
		public array $metadata_pages = array();
		public array $plan_pages = array();

		public function worktree_reconcile_metadata( array $opts = array() ): array {
			$this->reconcile_options[] = $opts;
			return array_shift($this->metadata_pages);
		}

		protected function run_capacity_recovery_plan( array $options ): array {
			$this->plan_options[] = $options;
			return array_shift($this->plan_pages);
		}
	}

	$harness = new CapacityRecoveryHarness();
	$harness->metadata_pages = array( array( 'pagination' => array( 'complete' => true ), 'summary' => array( 'written' => 2 ) ) );
	$harness->plan_pages = array( array(
		'summary' => array( 'total_rows' => 1, 'actionable_reclaim_bytes' => 4096, 'apply_command' => 'studio wp datamachine-code workspace cleanup apply cleanup-run-1' ),
	) );
	$result = $harness->worktree_capacity_recovery(array( 'limit' => 7, 'until_budget' => '15s' ));

	capacity_recovery_assert_same(true, $harness->reconcile_options[0]['apply'] ?? null, 'Capacity recovery may write reconciled metadata but must not invoke cleanup apply.');
	capacity_recovery_assert_same(7, $harness->reconcile_options[0]['limit'] ?? null, 'Capacity recovery must bound metadata reconciliation.');
	capacity_recovery_assert_same(1, $result['next_approval']['actionable_rows'] ?? null, 'Capacity recovery must return only the current actionable approval.');
	capacity_recovery_assert_same('studio wp datamachine-code workspace cleanup apply cleanup-run-1', $result['next_approval']['command'] ?? null, 'Capacity recovery must return the DB-backed apply command.');

	$harness = new CapacityRecoveryHarness();
	$harness->metadata_pages = array(
		array( 'pagination' => array( 'complete' => false, 'partial' => true, 'next_offset' => 7 ) ),
		array( 'pagination' => array( 'complete' => false, 'partial' => true, 'next_offset' => 14 ) ),
		array( 'pagination' => array( 'complete' => true ), 'summary' => array( 'written' => 1 ) ),
	);
	$harness->plan_pages = array( array( 'summary' => array( 'total_rows' => 1, 'actionable_reclaim_bytes' => 2048, 'apply_command' => 'studio wp datamachine-code workspace cleanup apply cleanup-run-resumed' ) ) );
	$first = $harness->worktree_capacity_recovery(array( 'limit' => 7, 'until_budget' => '15s' ));
	capacity_recovery_assert_same(null, array_key_exists('next_approval', $first) ? $first['next_approval'] : 'missing', 'Incomplete metadata reconciliation must not issue a cleanup approval.');
	capacity_recovery_assert_same(0, count($harness->plan_options), 'Incomplete metadata reconciliation must not replan partial state.');
	capacity_recovery_assert_same(true, str_contains((string) ($first['next_command'] ?? ''), 'worktree capacity-recovery'), 'Incomplete metadata reconciliation must resume capacity recovery, not bare reconciliation.');
	capacity_recovery_assert_same(true, str_contains((string) ($first['next_command'] ?? ''), '--offset=7'), 'Capacity recovery continuation must preserve the metadata cursor.');
	$second = $harness->worktree_capacity_recovery(array( 'limit' => 7, 'offset' => 7, 'until_budget' => '15s' ));
	capacity_recovery_assert_same(7, $harness->reconcile_options[1]['offset'] ?? null, 'Resumed capacity recovery must pass the continuation cursor to metadata reconciliation.');
	capacity_recovery_assert_same(0, count($harness->plan_options), 'Repeated incomplete reconciliation must still defer replanning.');
	capacity_recovery_assert_same(true, str_contains((string) ($second['next_command'] ?? ''), '--offset=14'), 'Repeated capacity recovery continuation must advance its cursor.');
	$resumed = $harness->worktree_capacity_recovery(array( 'limit' => 7, 'offset' => 14, 'until_budget' => '15s' ));
	capacity_recovery_assert_same(14, $harness->reconcile_options[2]['offset'] ?? null, 'Final capacity recovery continuation must preserve its advanced cursor.');
	capacity_recovery_assert_same(1, count($harness->plan_options), 'Completed continuation must run a fresh cleanup plan.');
	capacity_recovery_assert_same('studio wp datamachine-code workspace cleanup apply cleanup-run-resumed', $resumed['next_approval']['command'] ?? null, 'Completed continuation must return the reviewed DB-backed apply command.');

	$harness = new CapacityRecoveryHarness();
	$harness->metadata_pages = array(
		array( 'pagination' => array( 'complete' => true ) ),
		array( 'pagination' => array( 'complete' => true ) ),
	);
	$harness->plan_pages = array(
		array( 'summary' => array( 'total_rows' => 1 ), 'continuation' => array( 'partial' => true, 'next_offset' => 7, 'next_command' => 'studio wp datamachine-code workspace cleanup plan --mode=capacity-recovery --offset=7' ) ),
		array( 'summary' => array( 'total_rows' => 1, 'actionable_reclaim_bytes' => 1024, 'apply_command' => 'studio wp datamachine-code workspace cleanup apply cleanup-run-final' ) ),
	);
	$partial_replan = $harness->worktree_capacity_recovery(array( 'limit' => 7, 'until_budget' => '15s' ));
	capacity_recovery_assert_same(null, array_key_exists('next_approval', $partial_replan) ? $partial_replan['next_approval'] : 'missing', 'Partial cleanup replans must defer approval until their continuation completes.');
	capacity_recovery_assert_same(true, str_contains((string) ($partial_replan['next_command'] ?? ''), 'worktree capacity-recovery'), 'Partial cleanup replans must continue through capacity recovery.');
	capacity_recovery_assert_same(true, str_contains((string) ($partial_replan['next_command'] ?? ''), '--replan-offset=7'), 'Partial cleanup replans must preserve their plan cursor.');
	capacity_recovery_assert_same(false, str_contains((string) ($partial_replan['next_command'] ?? ''), 'cleanup plan --mode=capacity-recovery'), 'Partial cleanup replans must never emit an unsupported cleanup-plan mode.');
	$completed_replan = $harness->worktree_capacity_recovery(array( 'limit' => 7, 'replan_offset' => 7, 'until_budget' => '15s' ));
	capacity_recovery_assert_same(7, $harness->plan_options[1]['offset'] ?? null, 'Resumed recovery must forward the cleanup-plan cursor into the fresh replan.');
	capacity_recovery_assert_same('studio wp datamachine-code workspace cleanup apply cleanup-run-final', $completed_replan['next_approval']['command'] ?? null, 'Completed cleanup replan must return its reviewed DB-backed approval.');
	$ability_source = file_get_contents(dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php');
	capacity_recovery_assert_same(true, str_contains((string) $ability_source, "'datamachine-code/workspace-worktree-capacity-recovery'"), 'Capacity recovery must be registered as an ability.');
	capacity_recovery_assert_same(true, str_contains((string) $ability_source, "array( 'limit', 'offset', 'replan_offset' )"), 'Capacity recovery ability must forward both workflow cursors at its boundary.');
	$cli_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
	capacity_recovery_assert_same(true, str_contains((string) $cli_source, "'replan-offset'"), 'Capacity recovery CLI must accept the cleanup-plan continuation cursor.');

	echo "worktree-capacity-recovery: ok\n";
}
