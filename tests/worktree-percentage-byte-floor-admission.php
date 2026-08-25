<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorktreeDiskBudget;

function percentage_floor_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$gib = 1024 * 1024 * 1024;
$thresholds = array(
	'warn_free_bytes' => 0,
	'refuse_free_bytes' => 20 * $gib,
	'warn_free_percent' => 0,
	'refuse_free_percent' => 10,
	'warn_free_inodes' => 1000,
	'refuse_free_inodes' => 2000,
	'warn_free_inode_percent' => 0,
	'refuse_free_inode_percent' => 1,
);
$metrics = array( 'free_bytes' => 40 * $gib, 'total_bytes' => 1024 * $gib, 'free_inodes' => 500000, 'total_inodes' => 1000000 );
$small_trusted = array( 'bytes' => 40 * 1024 * 1024, 'inodes' => 100, 'source' => 'conservative_defaults', 'allow_percentage_byte_floor_exception' => true );

$admitted = WorktreeDiskBudget::evaluate($metrics, $thresholds, false, $small_trusted);
percentage_floor_assert('warning' === $admitted['status'] && true === $admitted['creation_allowed'], 'Small trusted demand must be admitted past only the percentage byte floor.');
percentage_floor_assert('admitted' === ($admitted['admission_exception']['status'] ?? null), 'Admission evidence must record the narrow exception.');
percentage_floor_assert('projected_free_bytes_percentage_refusal_floor' === ($admitted['admission_exception']['waived_trigger'] ?? null), 'Evidence must name the waived percentage floor.');
percentage_floor_assert(20 * $gib === ($admitted['admission_exception']['retained_hard_floors']['refuse_free_bytes'] ?? null), 'Evidence must retain the absolute byte floor.');

$unknown = $small_trusted;
$unknown['source'] = 'not_provided';
$rejected_unknown = WorktreeDiskBudget::evaluate($metrics, $thresholds, false, $unknown);
percentage_floor_assert('refused' === $rejected_unknown['status'] && 'untrusted_demand_source' === ($rejected_unknown['admission_exception']['rejection_reason'] ?? null), 'Unknown demand must remain refused.');

$large = $small_trusted;
$large['bytes'] = 64 * 1024 * 1024;
$rejected_large = WorktreeDiskBudget::evaluate($metrics, $thresholds, false, $large);
percentage_floor_assert('refused' === $rejected_large['status'] && 'demand_exceeds_bounded_ceiling' === ($rejected_large['admission_exception']['rejection_reason'] ?? null), 'Demand exactly at the ceiling must remain refused.');

$post_materialization = $small_trusted;
$post_materialization['source'] = 'post_materialization_target_tree_conservative';
$post_materialization_admitted = WorktreeDiskBudget::evaluate($metrics, $thresholds, false, $post_materialization);
percentage_floor_assert('admitted' === ($post_materialization_admitted['admission_exception']['status'] ?? null), 'Post-materialization conservative demand must remain trusted for rebase re-admission.');

$absolute = $metrics;
$absolute['free_bytes'] = 20 * $gib;
$rejected_absolute = WorktreeDiskBudget::evaluate($absolute, $thresholds, false, $small_trusted);
percentage_floor_assert('refused' === $rejected_absolute['status'] && 'not_percentage_byte_floor_only' === ($rejected_absolute['admission_exception']['rejection_reason'] ?? null), 'An absolute-byte refusal must remain refused.');

$inodes = $metrics;
$inodes['free_inodes'] = 2000;
$rejected_inodes = WorktreeDiskBudget::evaluate($inodes, $thresholds, false, $small_trusted);
percentage_floor_assert('refused' === $rejected_inodes['status'] && 'not_percentage_byte_floor_only' === ($rejected_inodes['admission_exception']['rejection_reason'] ?? null), 'An inode refusal must remain refused.');

$forced = WorktreeDiskBudget::evaluate($absolute, $thresholds, true, array( 'bytes' => 0, 'source' => 'not_provided' ));
percentage_floor_assert(true === $forced['creation_allowed'] && true === $forced['force_override_applied'], 'Force must remain the unrestricted override.');

echo "worktree-percentage-byte-floor-admission: ok\n";
