<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
	defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct( private string $code = '' ) {}
			public function get_error_code(): string { return $this->code; }
		}
	}
	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}
	function wp_get_ability( string $name ): ?object {
		return 'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' === $name ? $GLOBALS['capacity_remediation_ability'] : null;
	}
}

namespace DataMachineCode\Workspace {

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupEligibleDrainOrchestrator.php';

	final class CapacityAdmissionRemediationAbility {
		public array $calls = array();

		public function execute( array $input ): array {
			$this->calls[] = $input;
			$dry_run = ! empty($input['dry_run']);
			return array(
				'dry_run'        => $dry_run,
				'workspace_path' => '/tmp',
				'candidates'     => $dry_run ? array( array( 'handle' => 'repo@protected-recent' ) ) : array(),
				'removed'        => $dry_run ? array() : array( array( 'handle' => 'repo@eligible', 'size_bytes' => 50 ) ),
				'skipped'        => array( array( 'handle' => 'repo@dirty', 'reason_code' => 'dirty_worktree' ) ),
				'summary'        => array( 'processed' => 1, 'removed' => $dry_run ? 0 : 1, 'skipped' => 1, 'bytes_reclaimed' => $dry_run ? 0 : 50 ),
				'continuation'   => array( 'remaining_total' => 0 ),
				'evidence'       => array( 'elapsed_ms' => 1 ),
			);
		}
	}

	final class CapacityAdmissionRemediationHarness {
		use WorkspaceWorktreeLifecycle;
		public array $artifact_options = array();
		public array $budgets;

		public function __construct( array $budgets ) { $this->budgets = $budgets; }
		public function remediate( array $before, bool $dry_run ): array|\WP_Error { return $this->remediate_capacity_refusal('repo', 'branch', array( 'bytes' => 1 ), $before, $dry_run); }
		protected function worktree_cleanup_artifacts( array $opts = array() ): array { $this->artifact_options[] = $opts; return array( 'candidates' => array( array( 'handle' => 'repo@artifact' ) ), 'skipped' => array() ); }
		protected function inspect_worktree_capacity( string $repo, string $branch, bool $force, array $demand_plan ): array { return array_shift($this->budgets) ?? array( 'status' => 'refused' ); }
	}

	function capacity_remediation_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	$before = array( 'status' => 'refused', 'free_inodes' => 10, 'free_bytes' => 10 );
	$GLOBALS['capacity_remediation_ability'] = new CapacityAdmissionRemediationAbility();
	$dry_harness = new CapacityAdmissionRemediationHarness(array());
	$dry = $dry_harness->remediate($before, true);
	capacity_remediation_assert_same(true, $dry['dry_run'], 'Dry-run must be reported.');
	capacity_remediation_assert_same(true, $dry_harness->artifact_options[0]['dry_run'] ?? false, 'Dry-run must preview artifact cleanup only.');
	capacity_remediation_assert_same(true, $GLOBALS['capacity_remediation_ability']->calls[0]['dry_run'] ?? false, 'Dry-run must preview the eligible drain only.');
	capacity_remediation_assert_same('dry_run_no_retry', $dry['retry_disposition'], 'Dry-run must never create or retry the add.');

	$GLOBALS['capacity_remediation_ability'] = new CapacityAdmissionRemediationAbility();
	$insufficient_harness = new CapacityAdmissionRemediationHarness(array( $before ));
	$insufficient = $insufficient_harness->remediate($before, false);
	capacity_remediation_assert_same('insufficient_safe_reclaim', $insufficient['retry_disposition'], 'A still-refused remeasurement must return a typed no-retry disposition.');
	capacity_remediation_assert_same(false, $GLOBALS['capacity_remediation_ability']->calls[0]['dry_run'] ?? true, 'Applied remediation must invoke the bounded drain once.');
	capacity_remediation_assert_same(1, $insufficient['cleanup_drain']['summary']['removed'] ?? null, 'Applied remediation must retain removed-row evidence.');
	capacity_remediation_assert_same(1, $insufficient['cleanup_drain']['summary']['skipped'] ?? null, 'Applied remediation must retain protected skipped-row evidence.');
	capacity_remediation_assert_same('repo@eligible', $insufficient['cleanup_drain']['pass_results'][0]['removed_rows'][0]['handle'] ?? null, 'Applied remediation must retain every removed row.');
	capacity_remediation_assert_same('dirty_worktree', $insufficient['cleanup_drain']['pass_results'][0]['skipped_rows'][0]['reason_code'] ?? null, 'Applied remediation must retain every protected skipped row.');

	$GLOBALS['capacity_remediation_ability'] = new CapacityAdmissionRemediationAbility();
	$success_harness = new CapacityAdmissionRemediationHarness(array( array( 'status' => 'ok', 'free_inodes' => 13, 'free_bytes' => 100 ) ));
	$success = $success_harness->remediate($before, false);
	capacity_remediation_assert_same('retry_once', $success['retry_disposition'], 'Crossing the floor must authorize exactly one continuation of the original add.');
	capacity_remediation_assert_same(3, $success['reclaimed_inodes'], 'Post-remediation measurement must report inode recovery.');
	capacity_remediation_assert_same(50, $success['reclaimed_bytes'], 'Remediation must report reclaimed bytes.');

	echo "worktree-capacity-admission-remediation: ok\n";
}
