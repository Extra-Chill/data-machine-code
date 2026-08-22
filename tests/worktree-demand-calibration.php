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
function demand_calibration_plan( string $commit = 'tree-a', bool $bootstrap = true ): array { return array( 'bytes' => $bootstrap ? 1150 : 150, 'inodes' => $bootstrap ? 115 : 15, 'tracked_bytes' => 100, 'target_commit' => $commit, 'tracked_bytes_source' => 'exact_git_blob_sizes', 'bootstrap' => $bootstrap, 'git_safety_margin' => array( 'bytes' => 50, 'inodes' => 5 ), 'counts' => array( 'tracked_entries' => 10 ), 'detected' => array( 'package_roots' => $bootstrap ? array( array( 'relative' => '.', 'manager' => 'npm' ) ) : array(), 'composer_roots' => array() ), 'lockfile_identities' => array( 'package-lock.json' => $commit ) ); }
function demand_calibration_record( array $plan, int $bytes, int $inodes, bool $success = true ): array { return WorktreeDemandCalibration::record_bootstrap('repo', $plan, array( 'filesystem_free_bytes' => 100000, 'filesystem_free_inodes' => 100000 ), array( 'filesystem_free_bytes' => 100000 - $bytes, 'filesystem_free_inodes' => 100000 - $inodes ), $success); }

$plan = demand_calibration_plan();
$first = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert('conservative_defaults' === $first['calibration']['source'] && 0 === $first['calibration']['sample_count'], 'First run must retain conservative defaults.');
demand_calibration_record($plan, 200, 20);
$one = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert('conservative_defaults' === $one['calibration']['source'] && 'insufficient_nonzero_samples' === $one['calibration']['confidence'], 'One sample must not lower a forecast.');
demand_calibration_record($plan, 200, 20); demand_calibration_record($plan, 200, 20);
$repeat = WorktreeDemandCalibration::forecast('repo', $plan);
demand_calibration_assert('compatible_observed_percentile' === $repeat['calibration']['source'] && 3 === $repeat['calibration']['sample_count'], 'Three compatible samples must establish calibration.');
demand_calibration_assert(250 === $repeat['demand_components']['package_bootstrap']['bytes'] && 25 === $repeat['demand_components']['package_bootstrap']['inodes'], 'Bootstrap-only observations must receive the conservative margin.');
demand_calibration_assert(isset($repeat['demand_components']['git_materialization'], $repeat['demand_components']['source_tree'], $repeat['demand_components']['package_bootstrap'], $repeat['demand_components']['safety_margin']), 'Demand must keep checkout/source/bootstrap/margin components separate.');
$changed_lockfile_plan = demand_calibration_plan(); $changed_lockfile_plan['lockfile_identities']['package-lock.json'] = 'changed-lockfile';
demand_calibration_assert('conservative_defaults' === WorktreeDemandCalibration::forecast('repo', $changed_lockfile_plan)['calibration']['source'], 'Post-rebase lockfile identities must not reuse pre-rebase samples.');
$free_space = demand_calibration_record($plan, -500, -5);
demand_calibration_assert(false === $free_space['recorded'] && 'nonpositive_or_unavailable_bootstrap_delta' === $free_space['reason'], 'Concurrent cleanup/free-space increases must not become zero-demand samples.');
$zero = demand_calibration_record($plan, 0, 0);
demand_calibration_assert(false === $zero['recorded'], 'Zero bootstrap deltas must not calibrate demand.');
putenv('npm_config_cache=/tmp/cache-a'); demand_calibration_record($plan, 200, 20); putenv('npm_config_cache=/tmp/cache-b');
demand_calibration_assert('conservative_defaults' === WorktreeDemandCalibration::forecast('repo', $plan)['calibration']['source'], 'Changed cache identities must retain defaults.'); putenv('npm_config_cache');
$bare = demand_calibration_plan('bare-tree', false);
demand_calibration_assert(0 === WorktreeDemandCalibration::forecast('repo', $bare)['demand_components']['package_bootstrap']['bytes'], 'Zero-bootstrap worktrees retain zero package demand.');
$state = get_option('datamachine_code_worktree_demand_observations', array());
$state['stale-bucket'] = array( 'last_used' => time() - 2592001, 'samples' => array( array( 'at' => time() - 2592001, 'bytes' => 10, 'inodes' => 2 ) ) );
update_option('datamachine_code_worktree_demand_observations', $state); demand_calibration_record(demand_calibration_plan('prune-trigger'), 10, 2);
demand_calibration_assert(! isset(get_option('datamachine_code_worktree_demand_observations', array())['stale-bucket']), 'Recording must prune stale global identity buckets.');
for ( $i = 0; $i < 70; ++$i ) { demand_calibration_record(demand_calibration_plan('bucket-' . $i), 10, 2); }
$state = get_option('datamachine_code_worktree_demand_observations', array());
demand_calibration_assert(count($state) <= 64 && strlen(serialize($state)) <= 65536, 'Global calibration state must prune stale/LRU buckets within count and byte bounds.');
$floor = DataMachineCode\Workspace\WorktreeDiskBudget::evaluate(array( 'free_bytes' => 500, 'total_bytes' => 1000, 'free_inodes' => 500, 'total_inodes' => 1000 ), array( 'refuse_free_bytes' => 100, 'warn_free_bytes' => 0, 'refuse_free_percent' => 0, 'warn_free_percent' => 0, 'refuse_free_inodes' => 0, 'warn_free_inodes' => 0, 'refuse_free_inode_percent' => 0, 'warn_free_inode_percent' => 0 ), false, $repeat);
demand_calibration_assert('refused' === $floor['status'], 'Observed calibration must not lower filesystem refusal floors.');
$lifecycle_source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
demand_calibration_assert(str_contains((string) $lifecycle_source, 'WorktreeDemandCalibration::forecast($repo, $post_rebase_demand)') && str_contains((string) $lifecycle_source, 'record_bootstrap($repo, $measurement_plan, $bootstrap_before_capacity'), 'Lifecycle must measure bootstrap separately and record under the post-rebase identity.');
echo "worktree-demand-calibration: ok\n";
