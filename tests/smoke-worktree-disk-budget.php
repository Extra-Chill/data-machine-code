<?php
/**
 * Smoke test for WorktreeDiskBudget.
 *
 *   php tests/smoke-worktree-disk-budget.php
 *
 * @package DataMachineCode\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorktreeDiskBudget;

function datamachine_code_budget_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}

	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

echo "=== smoke-worktree-disk-budget ===\n";

$gib        = 1073741824;
$thresholds = array(
	'warn_free_bytes'     => 20 * $gib,
	'refuse_free_bytes'   => 10 * $gib,
	'warn_free_percent'   => 15,
	'refuse_free_percent' => 10,
	'warn_worktree_count' => 100,
);

echo "\n[1] healthy workspace passes cleanly\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 25 * $gib,
		'total_bytes'    => 100 * $gib,
		'worktree_count' => 10,
	),
	$thresholds
);
datamachine_code_budget_assert( 'ok' === $budget['status'], 'healthy budget status is ok' );
datamachine_code_budget_assert( array() === $budget['warnings'], 'healthy budget has no warnings' );
datamachine_code_budget_assert( false === $budget['workspace_size_exact'], 'workspace size is intentionally not walked' );
datamachine_code_budget_assert( null === $budget['workspace_size_bytes'], 'workspace size bytes are null when not exact' );

echo "\n[2] low free space warns above refusal threshold\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 15 * $gib,
		'total_bytes'    => 100 * $gib,
		'worktree_count' => 10,
	),
	$thresholds
);
datamachine_code_budget_assert( 'warning' === $budget['status'], 'warning status below 20 GiB' );
datamachine_code_budget_assert( 1 === count( $budget['warnings'] ), 'warning contains one free-space warning' );
datamachine_code_budget_assert( false === $budget['force_override_required'], 'warning does not require force' );

echo "\n[3] critically low free space refuses without force\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 5 * $gib,
		'total_bytes'    => 100 * $gib,
		'worktree_count' => 10,
	),
	$thresholds
);
datamachine_code_budget_assert( 'refused' === $budget['status'], 'refused status below 10 GiB' );
datamachine_code_budget_assert( true === $budget['force_override_required'], 'force required below refusal threshold' );
datamachine_code_budget_assert( true === $budget['emergency_triggered'], 'refusal threshold triggers emergency cleanup' );
datamachine_code_budget_assert( in_array( 'free_space_refusal_threshold', $budget['trigger_reasons'], true ), 'refusal trigger reason is stable' );
datamachine_code_budget_assert( str_contains( $budget['cleanup_dry_run_command'], 'workspace worktree cleanup --dry-run' ), 'cleanup dry-run command is present' );
datamachine_code_budget_assert( str_contains( $budget['artifact_cleanup_command'], 'workspace worktree cleanup-artifacts --dry-run' ), 'artifact cleanup dry-run command is present' );
datamachine_code_budget_assert( str_contains( $budget['emergency_cleanup_command'], 'workspace worktree emergency-cleanup' ), 'emergency cleanup command is present' );

echo "\n[4] force makes low-space override explicit\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 5 * $gib,
		'total_bytes'    => 100 * $gib,
		'worktree_count' => 10,
	),
	$thresholds,
	true
);
datamachine_code_budget_assert( 'warning' === $budget['status'], 'forced low-space budget is warning, not refused' );
datamachine_code_budget_assert( true === $budget['forced'], 'forced flag is recorded' );
datamachine_code_budget_assert( true === $budget['force_override_applied'], 'force override is visible in output data' );

echo "\n[5] percent floor refuses even when absolute free space is above 10 GiB\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 15 * $gib,
		'total_bytes'    => 200 * $gib,
		'worktree_count' => 10,
	),
	$thresholds
);
datamachine_code_budget_assert( 'refused' === $budget['status'], 'refused status below 10 percent free' );
datamachine_code_budget_assert( 20.0 === $budget['effective_refuse_gib'], 'effective refusal floor uses stricter percentage threshold' );
datamachine_code_budget_assert( 7.5 === $budget['free_percent'], 'free percentage is reported' );

echo "\n[6] high worktree count warns independently\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 25 * $gib,
		'total_bytes'    => 100 * $gib,
		'worktree_count' => 101,
	),
	$thresholds
);
datamachine_code_budget_assert( 'warning' === $budget['status'], 'high worktree count warns' );
datamachine_code_budget_assert( true === $budget['emergency_triggered'], 'worktree count warning triggers emergency cleanup' );
datamachine_code_budget_assert( str_contains( $budget['warnings'][0], '101 worktree-like directories' ), 'worktree-count warning is descriptive' );

echo "\n[7] inspect counts only worktree-like directories\n";
$tmp = sys_get_temp_dir() . '/dmc-budget-' . uniqid( '', true );
mkdir( $tmp );
mkdir( $tmp . '/repo' );
mkdir( $tmp . '/repo@feature-one' );
mkdir( $tmp . '/repo@feature-two' );
file_put_contents( $tmp . '/repo@not-a-dir', 'file' );
$budget = WorktreeDiskBudget::inspect( $tmp, $thresholds );
datamachine_code_budget_assert( 2 === $budget['worktree_count'], 'inspect counts @ directories only' );
datamachine_code_budget_assert( null !== $budget['free_bytes'], 'inspect reports free bytes' );
datamachine_code_budget_assert( null !== $budget['total_bytes'], 'inspect reports total bytes' );
datamachine_code_budget_assert( str_contains( WorktreeDiskBudget::format_summary( $budget ), 'worktree-like dirs' ), 'summary includes worktree count' );
datamachine_code_budget_assert( str_contains( WorktreeDiskBudget::format_summary( $budget ), $tmp ), 'summary includes workspace root' );
unlink( $tmp . '/repo@not-a-dir' );
rmdir( $tmp . '/repo@feature-two' );
rmdir( $tmp . '/repo@feature-one' );
rmdir( $tmp . '/repo' );
rmdir( $tmp );

echo "\nAll worktree disk-budget smoke tests passed.\n";
