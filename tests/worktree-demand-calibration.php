<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) { define('ABSPATH', __DIR__ . '/fixtures/'); }
$GLOBALS['demand_calibration_options'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['demand_calibration_options'][$key] ?? $default; }
function update_option( string $key, mixed $value, mixed ...$unused ): bool { $GLOBALS['demand_calibration_options'][$key] = $value; return true; }
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDemandCalibration.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorktreeDemandCalibration;

function demand_calibration_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException($message); } }
function demand_calibration_plan( string $commit = 'tree-a', bool $bootstrap = true ): array {
	return array( 'bytes' => 1150, 'inodes' => 115, 'tracked_bytes' => 100, 'target_commit' => $commit, 'tracked_bytes_source' => 'exact_git_blob_sizes', 'bootstrap' => $bootstrap, 'git_safety_margin' => array( 'bytes' => 50, 'inodes' => 5 ), 'counts' => array( 'tracked_entries' => 10 ), 'detected' => array( 'package_roots' => $bootstrap ? array( array( 'relative' => '.', 'manager' => 'npm' ) ) : array(), 'composer_roots' => array() ), 'lockfile_identities' => array( 'package-lock.json' => $commit ) );
}
function demand_calibration_record( array $plan, int $bytes, int $inodes, string $outcome = 'success' ): void {
	WorktreeDemandCalibration::record('repo', $plan, array( 'filesystem_free_bytes' => 100000, 'filesystem_free_inodes' => 100000 ), array( 'filesystem_free_bytes' => 100000 - $bytes, 'filesystem_free_inodes' => 100000 - $inodes ), $outcome);
}

$plan = demand_calibration_plan();
$first = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert('conservative_defaults' === $first['calibration']['source'] && 0 === $first['calibration']['sample_count'], 'First run must retain conservative defaults.');
demand_calibration_assert(1000 === $first['demand_components']['package_bootstrap']['bytes'], 'First run must preserve the fixed bootstrap allowance.');
demand_calibration_record($plan, 200, 20);
$repeat = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert('compatible_observed_percentile' === $repeat['calibration']['source'] && 1 === $repeat['calibration']['sample_count'], 'Compatible repeat must use its observed sample.');
demand_calibration_assert(250 === $repeat['demand_components']['package_bootstrap']['bytes'] && 25 === $repeat['demand_components']['package_bootstrap']['inodes'], 'Observed values must use the conservative percentile safety margin for bytes and inodes.');
demand_calibration_assert(isset($repeat['demand_components']['git_materialization'], $repeat['demand_components']['source_tree'], $repeat['demand_components']['package_bootstrap'], $repeat['demand_components']['safety_margin']), 'Demand output must separate Git, source, bootstrap, and margin components.');
$changed_lockfile_plan = demand_calibration_plan(); $changed_lockfile_plan['lockfile_identities']['package-lock.json'] = 'changed-lockfile';
$changed_lockfile = WorktreeDemandCalibration::forecast('repo', $changed_lockfile_plan);
demand_calibration_assert('conservative_defaults' === $changed_lockfile['calibration']['source'], 'Changed Git tree or lockfile identity must not reuse observations.');
putenv('npm_config_cache=/tmp/cache-a'); demand_calibration_record($plan, 200, 20); putenv('npm_config_cache=/tmp/cache-b');
$changed_cache = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert('conservative_defaults' === $changed_cache['calibration']['source'], 'Changed cache identity must not reuse observations.');
putenv('npm_config_cache');
$state = get_option('datamachine_code_worktree_demand_observations', array());
foreach ( $state as &$samples ) { $samples[] = array( 'at' => time() - 2592001, 'bytes' => 1, 'inodes' => 1, 'outcome' => 'success' ); $samples[] = array( 'at' => time(), 'bytes' => 999999, 'inodes' => 999999, 'outcome' => 'success' ); } unset($samples);
update_option('datamachine_code_worktree_demand_observations', $state);
$outlier = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert(250 === $outlier['demand_components']['package_bootstrap']['bytes'], 'Stale and above-default outlier observations must not affect the compatible percentile.');
$bare = demand_calibration_plan('bare-tree', false); demand_calibration_record($bare, 0, 0);
$zero = WorktreeDemandCalibration::forecast('repo', $bare);
demand_calibration_assert(0 === $zero['demand_components']['package_bootstrap']['bytes'] && 0 === $zero['demand_components']['package_bootstrap']['inodes'], 'Zero-bootstrap worktrees must retain zero package demand.');
$budget = array( 'filesystem_free_bytes' => 110, 'filesystem_free_inodes' => 110 );
$evidence = WorktreeDemandCalibration::record('repo', $plan, $budget, array( 'filesystem_free_bytes' => 100, 'filesystem_free_inodes' => 105 ), 'rollback');
demand_calibration_assert(10 === $evidence['observed_delta']['bytes'] && 5 === $evidence['observed_delta']['inodes'] && 'rollback' === $evidence['outcome'], 'Post-operation evidence must retain byte and inode rollback deltas.');
$floor = DataMachineCode\Workspace\WorktreeDiskBudget::evaluate(array( 'free_bytes' => 500, 'total_bytes' => 1000, 'free_inodes' => 500, 'total_inodes' => 1000 ), array( 'refuse_free_bytes' => 100, 'warn_free_bytes' => 0, 'refuse_free_percent' => 0, 'warn_free_percent' => 0, 'refuse_free_inodes' => 0, 'warn_free_inodes' => 0, 'refuse_free_inode_percent' => 0, 'warn_free_inode_percent' => 0 ), false, $repeat);
demand_calibration_assert('refused' === $floor['status'], 'Observed calibration must not lower the configured filesystem refusal floor.');

echo "worktree-demand-calibration: ok\n";
