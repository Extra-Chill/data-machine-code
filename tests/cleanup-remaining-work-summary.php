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
		array(
			'item_type'   => 'artifact_cleanup',
			'status'      => 'skipped',
			'reason_code' => 'artifact_plan_mismatch',
		),
	)
);

cleanup_summary_assert_same(3072, $summary['total_bytes_reclaimed'] ?? null, 'Total reclaimed bytes should sum applied rows across types.');
cleanup_summary_assert_same(1, $summary['remaining_safe_candidates'] ?? null, 'Remaining safe candidates should mirror safely removable worktrees.');
cleanup_summary_assert_same(1, $summary['protected_unpushed_candidates'] ?? null, 'Protected unpushed candidates should be counted explicitly.');
cleanup_summary_assert_same(4096, $summary['remaining_reclaimable_artifact_bytes'] ?? null, 'Remaining reclaimable artifact bytes should stay visible.');

$next_commands = (array) ( $summary['next_commands'] ?? array() );
$recommended_commands = (array) ( $summary['recommended_commands'] ?? array() );
$artifact_commands = array_values(array_filter($recommended_commands, fn( $row ) => 'remaining_artifacts' === ( $row['bucket'] ?? '' )));
cleanup_summary_assert_same(1, count($artifact_commands), 'Remaining artifact work should expose one review/apply command pair.');
cleanup_summary_assert_same('studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json', $artifact_commands[0]['command'] ?? null, 'Remaining artifact command should create a DB-backed review plan.');
cleanup_summary_assert_same('studio wp datamachine-code workspace cleanup apply <run-id>', $artifact_commands[0]['apply'] ?? null, 'Remaining artifact apply should use the reviewed DB-backed run ID.');

$mismatch_commands = array_values(array_filter($recommended_commands, fn( $row ) => 'skipped:artifact_plan_mismatch' === ( $row['bucket'] ?? '' )));
cleanup_summary_assert_same(1, count($mismatch_commands), 'Artifact plan mismatch should expose one remediation command.');
cleanup_summary_assert_same('studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json', $mismatch_commands[0]['command'] ?? null, 'Artifact plan mismatch remediation should create a fresh DB-backed plan.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json', $next_commands, true), 'Artifact DB-backed review command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace cleanup apply <run-id>', $next_commands, true), 'Artifact DB-backed apply command should be flattened into next_commands.');
cleanup_summary_assert_same(false, in_array('studio wp datamachine-code workspace cleanup run --mode=artifacts', $next_commands, true), 'Artifact next commands must not imply the scheduler reclaims disk.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25', $next_commands, true), 'Safe worktree review command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace cleanup run --mode=retention', $next_commands, true), 'Safe worktree apply command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('git -C <worktree-path> log --oneline --decorate @{u}..HEAD', $next_commands, true), 'Unpushed inspection command should be flattened into next_commands.');
cleanup_summary_assert_same(true, in_array('studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=unpushed_commits --verbose --format=json', $next_commands, true), 'Unpushed focused review command should be flattened into next_commands.');

eval('namespace DataMachine\\Cli; class BaseCommand {}');
require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
$method  = new ReflectionMethod($command, 'build_cleanup_operator_summary');

$task_backed = $method->invoke(
	$command,
	array(
		'cleanup_items'          => array(
			'planned_rows'    => 4,
			'applied_rows'    => 2,
			'skipped_rows'    => 1,
			'failed_rows'     => 1,
			'bytes_reclaimed' => 3072,
		),
		'remaining_work_summary' => array( 'total_bytes_reclaimed' => 3072 ),
	)
);
cleanup_summary_assert_same(4, $task_backed['cleanup_counts']['planned'] ?? null, 'Task-backed cleanup planned count should remain unchanged.');
cleanup_summary_assert_same(2, $task_backed['cleanup_counts']['applied'] ?? null, 'Task-backed cleanup applied count should remain unchanged.');
cleanup_summary_assert_same(1, $task_backed['cleanup_counts']['skipped'] ?? null, 'Task-backed cleanup skipped count should remain unchanged.');
cleanup_summary_assert_same(1, $task_backed['cleanup_counts']['failed'] ?? null, 'Task-backed cleanup failed count should remain unchanged.');
cleanup_summary_assert_same(3072, $task_backed['cleanup_counts']['bytes_reclaimed'] ?? null, 'Task-backed cleanup reclaimed bytes should remain unchanged.');

$db_backed_apply = $method->invoke(
	$command,
	array(
		'cleanup_items'          => array(
			'planned_rows'    => 3,
			'applied_rows'    => 1,
			'skipped_rows'    => 1,
			'failed_rows'     => 1,
			'bytes_reclaimed' => 2048,
		),
		'remaining_work_summary' => array( 'total_bytes_reclaimed' => 2048 ),
	)
);
cleanup_summary_assert_same(3, $db_backed_apply['cleanup_counts']['planned'] ?? null, 'DB-backed apply planned count should use persisted cleanup item totals.');
cleanup_summary_assert_same(1, $db_backed_apply['cleanup_counts']['applied'] ?? null, 'DB-backed apply applied count should use persisted cleanup item totals.');
cleanup_summary_assert_same(1, $db_backed_apply['cleanup_counts']['skipped'] ?? null, 'DB-backed apply skipped count should use persisted cleanup item totals.');
cleanup_summary_assert_same(1, $db_backed_apply['cleanup_counts']['failed'] ?? null, 'DB-backed apply failed count should use persisted cleanup item totals.');
cleanup_summary_assert_same(2048, $db_backed_apply['cleanup_counts']['bytes_reclaimed'] ?? null, 'DB-backed apply reclaimed bytes should match remaining work totals.');
cleanup_summary_assert_same(2048, $db_backed_apply['total_bytes_reclaimed'] ?? null, 'DB-backed apply top-level reclaimed bytes should match remaining work totals.');

echo "cleanup remaining work summary test passed.\n";
