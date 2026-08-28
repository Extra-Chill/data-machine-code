<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', dirname(__DIR__) . '/fixtures/');
}

if ( ! function_exists('wp_json_encode') ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode($value, $flags, $depth);
	}
}

function dmc_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

require_once dirname(__DIR__, 2) . '/inc/Workspace/WorktreeAllocationRequest.php';

function dmc_test_allocation_request(
	string $repo,
	string $branch,
	?string $from = null,
	bool $inject_context = true,
	bool $bootstrap = true,
	bool $allow_stale = false,
	bool $rebase_base = false,
	bool $force = false,
	array $task = array(),
	bool $allow_unverified_freshness = false,
	bool $require_task_tracker = false,
	array $intent = array(),
	string $reuse_policy = 'reuse_compatible',
	bool $remediate_capacity = false,
	bool $remediate_capacity_dry_run = false,
	?callable $progress_callback = null,
	array $expected_freshness_identity = array(),
	bool $allow_percentage_byte_floor_exception = false
): \DataMachineCode\Workspace\WorktreeAllocationRequest {
	return new \DataMachineCode\Workspace\WorktreeAllocationRequest(
		repo: $repo,
		branch: $branch,
		from: $from,
		inject_context: $inject_context,
		bootstrap: $bootstrap,
		allow_stale: $allow_stale,
		rebase_base: $rebase_base,
		force: $force,
		task: $task,
		allow_unverified_freshness: $allow_unverified_freshness,
		require_task_tracker: $require_task_tracker,
		intent: $intent,
		reuse_policy: $reuse_policy,
		remediate_capacity: $remediate_capacity,
		remediate_capacity_dry_run: $remediate_capacity_dry_run,
		progress_callback: $progress_callback,
		expected_freshness_identity: $expected_freshness_identity,
		allow_percentage_byte_floor_exception: $allow_percentage_byte_floor_exception
	);
}

function dmc_test_source( string $relative_path ): string {
	$source = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($relative_path, '/'));
	if ( false === $source ) {
		throw new RuntimeException('Unable to read test source: ' . $relative_path);
	}
	return $source;
}
