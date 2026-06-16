<?php
/**
 * Smoke test for high-volume workspace cleanup planning.
 *
 *   php tests/smoke-workspace-cleanup-plan-high-volume.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__);
	}

	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode($data, $flags);
	}

	function datamachine_code_cleanup_plan_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit(1);
	}
}

namespace DataMachineCode\Workspace {
	include_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
	include_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupPlan.php';

	class HighVolumeCleanupPlanWorkspace {
		use WorkspaceCleanupPlan;

		public const CLEANUP_PLAN_DEFAULT_LIMIT  = 100;
		public const CLEANUP_PLAN_DEFAULT_BUDGET = '30s';
		public const METADATA_RECONCILE_DEFAULT_LIMIT = 25;
		public const METADATA_RECONCILE_DEFAULT_BUDGET = '30s';

		public string $workspace_path = '/workspace';
		public array $artifact_input = array();
		public array $worktree_input = array();

		public function worktree_cleanup_artifacts( array $opts = array() ): array {
			$this->artifact_input = $opts;
			$candidates = array();
			for ( $i = 1; $i <= 150; ++$i ) {
				$candidates[] = array(
					'handle'              => 'repo@artifact-' . $i,
					'repo'                => 'repo',
					'branch'              => 'artifact-' . $i,
					'path'                => '/workspace/repo@artifact-' . $i,
					'reason_code'         => 'profile_artifacts',
					'reason'              => 'profile-derived reconstructable artifacts can be removed',
					'artifact_size_bytes' => $i * 1024,
					'artifacts'           => array( array( 'path' => 'target', 'size_bytes' => $i * 1024 ) ),
				);
			}
			if ( 'size' === (string) ( $opts['sort'] ?? '' ) ) {
				usort($candidates, fn( $a, $b ) => (int) $b['artifact_size_bytes'] <=> (int) $a['artifact_size_bytes']);
			}
			$limit      = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 100;
			$offset     = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;
			$total      = count($candidates);
			$candidates = ! empty($opts['full_workspace']) ? $candidates : array_slice($candidates, $offset, $limit);
			$next       = ( $offset + count($candidates) ) < $total ? $offset + count($candidates) : null;
			$pagination = array(
				'total'       => $total,
				'offset'      => $offset,
				'limit'       => $limit,
				'scanned'     => count($candidates),
				'partial'     => null !== $next,
				'complete'    => null === $next,
				'next_offset' => $next,
			);

			return array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => $candidates,
				'skipped'    => array(
					array(
						'handle'              => 'repo@active-symlink',
						'repo'                => 'repo',
						'branch'              => 'active-symlink',
						'path'                => '/workspace/repo@active-symlink',
						'reason_code'         => 'active_symlink_target',
						'reason'              => 'worktree is the target of a wp-content plugin/theme symlink - leaving artifacts in place',
						'artifact_size_bytes' => 4096,
					),
				),
				'pagination' => $pagination,
				'summary'    => array( 'pagination' => $pagination ),
			);
		}

		public function worktree_cleanup_merged( array $opts = array() ): array {
			$this->worktree_input = $opts;
			$candidates = array(
				array(
					'handle'      => 'repo@old-small',
					'repo'        => 'repo',
					'branch'      => 'old-small',
					'path'        => '/workspace/repo@old-small',
					'reason_code' => 'cleanup_eligible',
					'reason'      => 'worktree finalized or explicitly marked cleanup_eligible',
					'size_bytes'  => 2048,
				),
				array(
					'handle'      => 'repo@old-large',
					'repo'        => 'repo',
					'branch'      => 'old-large',
					'path'        => '/workspace/repo@old-large',
					'reason_code' => 'cleanup_eligible',
					'reason'      => 'worktree finalized or explicitly marked cleanup_eligible',
					'size_bytes'  => 4096,
				),
			);
			if ( 'size' === (string) ( $opts['sort'] ?? '' ) ) {
				usort($candidates, fn( $a, $b ) => (int) $b['size_bytes'] <=> (int) $a['size_bytes']);
			}
			$limit      = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 100;
			$offset     = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;
			$total      = count($candidates);
			$candidates = array_slice($candidates, $offset, $limit);
			$next       = ( $offset + count($candidates) ) < $total ? $offset + count($candidates) : null;
			$pagination = array(
				'total'       => $total,
				'offset'      => $offset,
				'limit'       => $limit,
				'scanned'     => count($candidates),
				'partial'     => null !== $next,
				'complete'    => null === $next,
				'next_offset' => $next,
			);

			return array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => $candidates,
				'skipped'    => array(
					array(
						'handle'      => 'repo@dirty',
						'repo'        => 'repo',
						'branch'      => 'dirty',
						'path'        => '/workspace/repo@dirty',
						'reason_code' => 'dirty_worktree',
						'reason'      => 'working tree dirty (1 files) - leaving in place',
						'size_bytes'  => 8192,
					),
				),
				'pagination' => $pagination,
				'summary'    => array( 'pagination' => $pagination ),
			);
		}
	}
}

namespace {
	echo "=== smoke-workspace-cleanup-plan-high-volume ===\n";

	$workspace = new \DataMachineCode\Workspace\HighVolumeCleanupPlanWorkspace();
	$plan      = $workspace->workspace_cleanup_plan(
		array(
			'include_artifacts' => true,
			'include_worktrees' => true,
			'include_resolvers' => true,
		)
	);

	datamachine_code_cleanup_plan_assert(true === ( $plan['success'] ?? false ), 'cleanup plan succeeds');
	datamachine_code_cleanup_plan_assert(false === ( $workspace->artifact_input['full_workspace'] ?? false ), 'artifact planning defaults to bounded inventory mode');
	datamachine_code_cleanup_plan_assert(100 === (int) ( $workspace->artifact_input['limit'] ?? 0 ), 'artifact planning uses the default page limit');
	datamachine_code_cleanup_plan_assert(0 === (int) ( $workspace->artifact_input['offset'] ?? -1 ), 'artifact planning starts at offset 0');
	datamachine_code_cleanup_plan_assert(true === ( $workspace->worktree_input['inventory_only'] ?? false ), 'worktree planning defaults to inventory-only cleanup signals');
	datamachine_code_cleanup_plan_assert(100 === (int) ( $workspace->worktree_input['limit'] ?? 0 ), 'worktree planning uses the default page limit');
	datamachine_code_cleanup_plan_assert('30s' === ( $workspace->worktree_input['until_budget'] ?? '' ), 'worktree planning includes a default wall-clock budget');
	datamachine_code_cleanup_plan_assert(100 === count($plan['rows']['artifact_cleanup'] ?? array()), 'artifact rows are bounded to one default page');
	datamachine_code_cleanup_plan_assert('repo@artifact-100' === ( $plan['rows']['artifact_cleanup'][0]['handle'] ?? '' ), 'artifact rows are ranked within the bounded page for review');
	datamachine_code_cleanup_plan_assert('repo@old-large' === ( $plan['rows']['worktree_removal'][0]['handle'] ?? '' ), 'worktree rows are largest first');
	datamachine_code_cleanup_plan_assert(2 === (int) ( $plan['summary']['rows_by_type']['worktree_removal'] ?? 0 ), 'worktree cleanup rows are counted separately');
	datamachine_code_cleanup_plan_assert(100 === (int) ( $plan['summary']['rows_by_type']['artifact_cleanup'] ?? 0 ), 'artifact cleanup rows are counted separately');
	datamachine_code_cleanup_plan_assert(6144 === (int) ( $plan['summary']['byte_totals']['worktree_removal'] ?? 0 ), 'worktree byte total includes all removal rows');
	datamachine_code_cleanup_plan_assert(5171200 === (int) ( $plan['summary']['byte_totals']['artifact_cleanup'] ?? 0 ), 'artifact byte total includes the bounded page rows');
	datamachine_code_cleanup_plan_assert(1 === (int) ( $plan['summary']['blocked_by_reason']['artifact_cleanup']['active_symlink_target'] ?? 0 ), 'artifact blockers include clear reason code');
	datamachine_code_cleanup_plan_assert(1 === (int) ( $plan['summary']['blocked_by_reason']['worktree_removal']['dirty_worktree'] ?? 0 ), 'worktree blockers include clear reason code');
	datamachine_code_cleanup_plan_assert('artifact_cleanup' === ( $plan['summary']['top_reclaimable'][0]['row_type'] ?? '' ), 'top reclaimable summary starts with the biggest lane row');
	datamachine_code_cleanup_plan_assert(102400 === (int) ( $plan['summary']['top_reclaimable'][0]['size_bytes'] ?? 0 ), 'top reclaimable summary reports the largest bytes in the bounded page');
	datamachine_code_cleanup_plan_assert(false === ( $plan['continuation']['complete'] ?? true ), 'cleanup plan reports incomplete bounded scan');
	datamachine_code_cleanup_plan_assert(100 === (int) ( $plan['continuation']['next_offset'] ?? 0 ), 'cleanup plan reports next offset for continuation');
	datamachine_code_cleanup_plan_assert(str_contains((string) ( $plan['continuation']['next_command'] ?? '' ), '--offset=100'), 'cleanup plan emits next page command');
	datamachine_code_cleanup_plan_assert(str_contains((string) ( $plan['continuation']['full_audit_command'] ?? '' ), '--exhaustive'), 'cleanup plan exposes explicit full audit command');

	echo "=== done ===\n";
}
