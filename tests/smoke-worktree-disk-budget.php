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
	'refuse_free_bytes'   => 5 * $gib,
	'warn_worktree_count' => 100,
);

echo "\n[1] healthy workspace passes cleanly\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 25 * $gib,
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
		'free_bytes'     => 10 * $gib,
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
		'free_bytes'     => 1 * $gib,
		'worktree_count' => 10,
	),
	$thresholds
);
datamachine_code_budget_assert( 'refused' === $budget['status'], 'refused status below 5 GiB' );
datamachine_code_budget_assert( true === $budget['force_override_required'], 'force required below refusal threshold' );
datamachine_code_budget_assert( str_contains( $budget['cleanup_dry_run_command'], 'workspace worktree cleanup --dry-run' ), 'cleanup dry-run command is present' );

echo "\n[4] force makes low-space override explicit\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 1 * $gib,
		'worktree_count' => 10,
	),
	$thresholds,
	true
);
datamachine_code_budget_assert( 'warning' === $budget['status'], 'forced low-space budget is warning, not refused' );
datamachine_code_budget_assert( true === $budget['forced'], 'forced flag is recorded' );
datamachine_code_budget_assert( true === $budget['force_override_applied'], 'force override is visible in output data' );

echo "\n[5] high worktree count warns independently\n";
$budget = WorktreeDiskBudget::evaluate(
	array(
		'workspace_path' => '/tmp/workspace',
		'free_bytes'     => 25 * $gib,
		'worktree_count' => 101,
	),
	$thresholds
);
datamachine_code_budget_assert( 'warning' === $budget['status'], 'high worktree count warns' );
datamachine_code_budget_assert( str_contains( $budget['warnings'][0], '101 worktree-like directories' ), 'worktree-count warning is descriptive' );

echo "\n[6] inspect counts only worktree-like directories\n";
$tmp = sys_get_temp_dir() . '/dmc-budget-' . uniqid( '', true );
mkdir( $tmp );
mkdir( $tmp . '/repo' );
mkdir( $tmp . '/repo@feature-one' );
mkdir( $tmp . '/repo@feature-two' );
file_put_contents( $tmp . '/repo@not-a-dir', 'file' );
$budget = WorktreeDiskBudget::inspect( $tmp, $thresholds );
datamachine_code_budget_assert( 2 === $budget['worktree_count'], 'inspect counts @ directories only' );
datamachine_code_budget_assert( null !== $budget['free_bytes'], 'inspect reports free bytes' );
datamachine_code_budget_assert( str_contains( WorktreeDiskBudget::format_summary( $budget ), 'worktree-like dirs' ), 'summary includes worktree count' );
unlink( $tmp . '/repo@not-a-dir' );
rmdir( $tmp . '/repo@feature-two' );
rmdir( $tmp . '/repo@feature-one' );
rmdir( $tmp . '/repo' );
rmdir( $tmp );

echo "\nAll worktree disk-budget smoke tests passed.\n";
