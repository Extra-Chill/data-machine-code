<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupPlan.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceCleanupPlan;
use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function cleanup_retention_plan_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

final class CleanupRetentionPlanWorktreeRemovalWorkspace {
	use WorkspaceCleanupPlan;

	public const CLEANUP_PLAN_DEFAULT_LIMIT  = 100;
	public const CLEANUP_PLAN_DEFAULT_BUDGET = '30s';

	private string $workspace_path = '/tmp/dmc-retention-plan-contract';
	public array $last_worktree_cleanup_opts = array();

	public function worktree_cleanup_artifacts( array $opts = array() ): array {
		return array(
			'success'    => true,
			'dry_run'    => true,
			'candidates' => array(),
			'skipped'    => array(),
			'summary'    => array(),
		);
	}

	public function worktree_cleanup_merged( array $opts = array() ): array {
		$this->last_worktree_cleanup_opts = $opts;

		return array(
			'success'    => true,
			'dry_run'    => true,
			'candidates' => array(
				array(
					'handle'      => 'repo@local-merged',
					'repo'        => 'repo',
					'branch'      => 'local-merged',
					'path'        => '/tmp/dmc-retention-plan-contract/repo@local-merged',
					'signal'      => 'local-merged',
					'reason_code' => 'local-merged',
					'size_bytes'  => 100,
				),
				array(
					'handle'      => 'repo@remote-clean',
					'repo'        => 'repo',
					'branch'      => 'remote-clean',
					'path'        => '/tmp/dmc-retention-plan-contract/repo@remote-clean',
					'signal'      => 'remote-tracking-clean',
					'reason_code' => 'remote-tracking-clean',
					'size_bytes'  => 200,
				),
				array(
					'handle'      => 'repo@upstream-gone',
					'repo'        => 'repo',
					'branch'      => 'upstream-gone',
					'path'        => '/tmp/dmc-retention-plan-contract/repo@upstream-gone',
					'signal'      => 'upstream-gone',
					'reason_code' => 'upstream-gone',
					'size_bytes'  => 300,
				),
			),
			'skipped'    => array(),
			'summary'    => array(
				'fresh_safe_removable_count' => 3,
			),
			'pagination' => array(
				'total'          => 5,
				'offset'         => 0,
				'limit'          => 3,
				'scanned'        => 3,
				'partial'        => true,
				'complete'       => false,
				'next_offset'    => 3,
				'next_command'   => 'studio wp datamachine-code workspace worktree cleanup --dry-run --limit=3 --offset=3 --until-budget=45s --format=json',
				'budget_stopped' => false,
			),
		);
	}

	private function stable_cleanup_hash( array $data, string $prefix ): string {
		return $prefix . '-' . substr(hash('sha256', wp_json_encode($data)), 0, 12);
	}
}

if ( ! function_exists('wp_json_encode') ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode($data, $options, $depth);
	}
}

$workspace = new CleanupRetentionPlanWorktreeRemovalWorkspace();
$plan      = $workspace->workspace_cleanup_plan(
	array(
		'include_artifacts' => false,
		'limit'             => 3,
		'offset'            => 0,
		'until_budget'      => '45s',
	)
);

cleanup_retention_plan_assert(is_array($plan), 'retention cleanup plan should return an array');
cleanup_retention_plan_assert(empty($workspace->last_worktree_cleanup_opts['inventory_only']), 'retention plan should run the probed worktree cleanup preview, not inventory-only cleanup');
cleanup_retention_plan_assert(3 === (int) ( $workspace->last_worktree_cleanup_opts['limit'] ?? 0 ), 'retention plan should pass the bounded page limit to worktree cleanup');
cleanup_retention_plan_assert('45s' === ( $workspace->last_worktree_cleanup_opts['until_budget'] ?? null ), 'retention plan should pass the budget to worktree cleanup');

$rows = (array) ( $plan['rows']['worktree_removal'] ?? array() );
cleanup_retention_plan_assert(3 === count($rows), 'retention plan should preserve fresh safe worktree cleanup candidates as removal rows');

$signals = array_column($rows, 'signal', 'handle');
cleanup_retention_plan_assert('local-merged' === ( $signals['repo@local-merged'] ?? null ), 'local-merged candidates should be applyable worktree_removal rows');
cleanup_retention_plan_assert('remote-tracking-clean' === ( $signals['repo@remote-clean'] ?? null ), 'remote-tracking-clean candidates should be applyable worktree_removal rows');
cleanup_retention_plan_assert('upstream-gone' === ( $signals['repo@upstream-gone'] ?? null ), 'upstream-gone candidates should be applyable worktree_removal rows');
cleanup_retention_plan_assert(3 === (int) ( $plan['summary']['rows_by_action']['remove_worktree'] ?? 0 ), 'remove_worktree action rows should match reviewed safe rows');
cleanup_retention_plan_assert('studio wp datamachine-code workspace cleanup apply <run-id>' === ( $plan['summary']['apply_command'] ?? '' ), 'retention plan should expose the DB-backed apply command');

$engine = new class {
	use WorkspaceWorktreeCleanupEngine;
};
$review_command = ( new ReflectionMethod($engine, 'build_worktree_cleanup_review_plan_command') )->invoke($engine, 100, 25, '180s');
cleanup_retention_plan_assert(
	'studio wp datamachine-code workspace cleanup plan --mode=retention --limit=100 --offset=25 --until-budget=180s --format=json' === $review_command,
	'bounded worktree cleanup should point to the DB-backed reviewed apply path for the same page'
);

fwrite(STDOUT, "cleanup-retention-plan-worktree-removals ok\n");
