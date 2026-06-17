<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Workspace\WorktreeDiskBudget;

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$gib = 1073741824;

try {
	$budget = WorktreeDiskBudget::evaluate(
		array(
			'workspace_path' => '/tmp/dmc-test-workspace',
			'free_bytes'     => 2 * $gib,
			'total_bytes'    => 100 * $gib,
			'worktree_count' => 12,
		),
		array(
			'warn_free_bytes'     => 20 * $gib,
			'refuse_free_bytes'   => 10 * $gib,
			'warn_free_percent'   => 15.0,
			'refuse_free_percent' => 10.0,
			'warn_worktree_count' => 100,
		)
	);

	assert_true('refused' === $budget['status'], 'low free space should refuse worktree creation');
	assert_true(8 * $gib === $budget['cleanup_recommendations'][0]['expected_reclaim_bytes'], 'recommendations should include the bytes needed to clear the effective floor');
	assert_true(str_contains(WorktreeDiskBudget::format_summary($budget), '2.0 GiB (2.0%) free'), 'summary should include current free GiB and percent');

	$commands = array_column($budget['cleanup_recommendations'], 'command');
	assert_true(in_array('studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run --sort=size', $commands, true), 'artifact cleanup preview command is missing');
	assert_true(in_array('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25', $commands, true), 'bounded cleanup-eligible dry-run command is missing');
	assert_true(in_array('studio wp datamachine-code workspace worktree emergency-cleanup --format=json', $commands, true), 'emergency cleanup report command is missing');

	$bounded = $budget['cleanup_recommendations'][1];
	assert_true('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25' === $bounded['preview_command'], 'bounded cleanup preview command is missing');
	assert_true('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=25' === $bounded['apply_command'], 'bounded cleanup apply command is missing');

	fwrite(STDOUT, "worktree-disk-budget ok\n");
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
