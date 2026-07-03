<?php
/**
 * Focused coverage for hygiene cleanup candidate fresh-revalidation context.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHygieneReport.php';

function hygiene_cleanup_summary_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$hygiene = new class {
	use DataMachineCode\Workspace\WorkspaceHygieneReport;

	public function annotate( array $candidates, string $source ): array {
		$method = new ReflectionMethod($this, 'annotate_workspace_cleanup_candidates_for_hygiene');
		return $method->invoke($this, $candidates, $source);
	}

	public function outcome( array $summary, bool $inventory_only ): string {
		$method = new ReflectionMethod($this, 'workspace_cleanup_expected_outcome');
		return $method->invoke($this, $summary, $inventory_only);
	}
};

$inventory_candidates = $hygiene->annotate(
	array(
		array(
			'handle'              => 'repo@cleanup-eligible',
			'safety_probe_status' => 'not_run_inventory_only',
			'dirty'               => null,
		),
	),
	'inventory_known'
);

hygiene_cleanup_summary_assert('not_run_inventory_only' === $inventory_candidates[0]['fresh_revalidation_status'], 'inventory candidates explain that fresh probes have not run');
hygiene_cleanup_summary_assert(array( 'pending_fresh_revalidation' ) === $inventory_candidates[0]['fresh_revalidation_blockers'], 'inventory candidates expose pending fresh blocker');
hygiene_cleanup_summary_assert(in_array('dirty_worktree', $inventory_candidates[0]['fresh_revalidation_checks'], true), 'inventory candidates list dirty revalidation check');
hygiene_cleanup_summary_assert(in_array('unpushed_commits', $inventory_candidates[0]['fresh_revalidation_checks'], true), 'inventory candidates list unpushed revalidation check');

$fresh_candidates = $hygiene->annotate(
	array(
		array(
			'handle' => 'repo@safe',
			'dirty'  => 0,
		),
	),
	'fresh_probe'
);

hygiene_cleanup_summary_assert('passed' === $fresh_candidates[0]['fresh_revalidation_status'], 'fresh-probed candidates are marked passed');
hygiene_cleanup_summary_assert(0 === $fresh_candidates[0]['unpushed'], 'fresh-probed candidates expose passed unpushed check');

$expected_outcome = $hygiene->outcome(
	array(
		'fresh_safe_removable_count' => 0,
		'cleanup_buckets'            => array(
			DataMachineCode\Workspace\WorktreeCleanupClassifier::BUCKET_CLEANUP_ELIGIBLE_UNPROBED => 8,
		),
	),
	true
);

hygiene_cleanup_summary_assert(str_contains($expected_outcome, '8 cleanup-eligible row(s) pending fresh revalidation'), 'expected outcome names pending cleanup-eligible rows');
hygiene_cleanup_summary_assert(str_contains($expected_outcome, '0 fresh-safe removals'), 'expected outcome names zero fresh-safe removals');

echo "workspace hygiene cleanup summary test passed.\n";
