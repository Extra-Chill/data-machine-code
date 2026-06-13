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
			usort($candidates, fn( $a, $b ) => (int) $b['artifact_size_bytes'] <=> (int) $a['artifact_size_bytes']);

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
				'summary'    => array(),
			);
		}

		public function worktree_cleanup_merged( array $opts = array() ): array {
			$this->worktree_input = $opts;
			return array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => array(
					array(
						'handle'      => 'repo@old-small',
						'repo'        => 'repo',
						'branch'      => 'old-small',
						'path'        => '/workspace/repo@old-small',
						'reason_code' => 'upstream-gone',
						'reason'      => 'remote branch deleted (likely merged + auto-deleted)',
						'size_bytes'  => 2048,
					),
					array(
						'handle'      => 'repo@old-large',
						'repo'        => 'repo',
						'branch'      => 'old-large',
						'path'        => '/workspace/repo@old-large',
						'reason_code' => 'upstream-gone',
						'reason'      => 'remote branch deleted (likely merged + auto-deleted)',
						'size_bytes'  => 4096,
					),
				),
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
				'summary'    => array(),
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
	datamachine_code_cleanup_plan_assert(true === ( $workspace->artifact_input['full_workspace'] ?? false ), 'artifact planning uses full-workspace inventory mode');
	datamachine_code_cleanup_plan_assert('size' === ( $workspace->artifact_input['sort'] ?? '' ), 'artifact planning asks for biggest artifacts first');
	datamachine_code_cleanup_plan_assert('size' === ( $workspace->worktree_input['sort'] ?? '' ), 'worktree cleanup planning asks for biggest worktrees first');
	datamachine_code_cleanup_plan_assert(150 === count($plan['rows']['artifact_cleanup'] ?? array()), 'all artifact rows are planned without manual paging');
	datamachine_code_cleanup_plan_assert('repo@artifact-150' === ( $plan['rows']['artifact_cleanup'][0]['handle'] ?? '' ), 'artifact rows are largest first');
	datamachine_code_cleanup_plan_assert('repo@old-large' === ( $plan['rows']['worktree_removal'][0]['handle'] ?? '' ), 'worktree rows are largest first');
	datamachine_code_cleanup_plan_assert(2 === (int) ( $plan['summary']['rows_by_type']['worktree_removal'] ?? 0 ), 'worktree cleanup rows are counted separately');
	datamachine_code_cleanup_plan_assert(150 === (int) ( $plan['summary']['rows_by_type']['artifact_cleanup'] ?? 0 ), 'artifact cleanup rows are counted separately');
	datamachine_code_cleanup_plan_assert(6144 === (int) ( $plan['summary']['byte_totals']['worktree_removal'] ?? 0 ), 'worktree byte total includes all removal rows');
	datamachine_code_cleanup_plan_assert(11596800 === (int) ( $plan['summary']['byte_totals']['artifact_cleanup'] ?? 0 ), 'artifact byte total includes all 150 rows');
	datamachine_code_cleanup_plan_assert(1 === (int) ( $plan['summary']['blocked_by_reason']['artifact_cleanup']['active_symlink_target'] ?? 0 ), 'artifact blockers include clear reason code');
	datamachine_code_cleanup_plan_assert(1 === (int) ( $plan['summary']['blocked_by_reason']['worktree_removal']['dirty_worktree'] ?? 0 ), 'worktree blockers include clear reason code');
	datamachine_code_cleanup_plan_assert('artifact_cleanup' === ( $plan['summary']['top_reclaimable'][0]['row_type'] ?? '' ), 'top reclaimable summary starts with the biggest lane row');
	datamachine_code_cleanup_plan_assert(153600 === (int) ( $plan['summary']['top_reclaimable'][0]['reclaimable_bytes'] ?? 0 ), 'top reclaimable summary reports the largest bytes first');

	echo "=== done ===\n";
}
