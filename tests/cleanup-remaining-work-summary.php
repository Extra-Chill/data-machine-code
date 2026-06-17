<?php
/**
 * Standalone coverage for cleanup remaining-work summary rendering fields.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';

use DataMachineCode\Cleanup\CleanupRemainingWorkSummary;

function cleanup_summary_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
	}
}

$summary = CleanupRemainingWorkSummary::from_items(
	array(
		array(
			'item_type'       => 'artifact_cleanup',
			'status'          => 'applied',
			'bytes_reclaimed' => 2048,
		),
		array(
			'item_type'       => 'worktree_removal',
			'status'          => 'applied',
			'bytes_reclaimed' => 1024,
		),
		array(
			'item_type' => 'worktree_removal',
			'status'    => 'pending',
			'handle'    => 'repo@safe-candidate',
		),
		array(
			'item_type'   => 'worktree_removal',
			'status'      => 'skipped',
			'handle'      => 'repo@unpushed',
			'reason_code' => 'unpushed_commits',
		),
		array(
			'item_type' => 'artifact_cleanup',
			'status'    => 'pending',
			'evidence'  => array(
				'handle'              => 'repo@artifact',
				'artifact_size_bytes' => 4096,
			),
		),
	)
);

cleanup_summary_assert_same(3072, $summary['total_bytes_reclaimed'] ?? null, 'Total reclaimed bytes should sum applied rows across types.');
cleanup_summary_assert_same(1, $summary['remaining_safe_candidates'] ?? null, 'Remaining safe candidates should mirror safely removable worktrees.');
cleanup_summary_assert_same(1, $summary['protected_unpushed_candidates'] ?? null, 'Protected unpushed candidates should be counted explicitly.');
cleanup_summary_assert_same(4096, $summary['remaining_reclaimable_artifact_bytes'] ?? null, 'Remaining reclaimable artifact bytes should stay visible.');

$next_commands = (array) ( $summary['next_commands'] ?? array() );
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25', $next_commands, true), 'Safe worktree review command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace cleanup run --mode=retention', $next_commands, true), 'Safe worktree apply command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('git -C <worktree-path> log --oneline --decorate @{u}..HEAD', $next_commands, true), 'Unpushed inspection command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=unpushed_commits --verbose --format=json', $next_commands, true), 'Unpushed focused review command should be flattened into next_commands.');

echo "cleanup remaining work summary test passed.\n";
