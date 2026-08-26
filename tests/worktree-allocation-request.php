<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/TaskUrl.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeAllocationRequest.php';

use DataMachineCode\Workspace\WorktreeAllocationRequest;

function allocation_request_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$previous_task_url = getenv('DATAMACHINE_TASK_URL');
$previous_task_ref = getenv('DATAMACHINE_TASK_REF');
putenv('DATAMACHINE_TASK_URL');
putenv('DATAMACHINE_TASK_REF');

try {
	$defaults = WorktreeAllocationRequest::from_input(
		array(
			'repo'   => 'data-machine-code',
			'branch' => 'refactor/1243',
		)
	);

	allocation_request_assert('data-machine-code' === $defaults->repo, 'Repository should be preserved.');
	allocation_request_assert('refactor/1243' === $defaults->branch, 'Branch should be preserved.');
	allocation_request_assert($defaults->inject_context, 'Context injection should default on.');
	allocation_request_assert($defaults->bootstrap, 'Bootstrap should default on.');
	allocation_request_assert($defaults->require_task_tracker, 'Managed allocations should require a tracker by default.');
	allocation_request_assert('reuse_compatible' === $defaults->reuse_policy, 'Compatible reuse should remain the default.');

	$progress = static function (): void {};
	$request  = WorktreeAllocationRequest::from_input(
		array(
			'repo'                                  => 'data-machine-code',
			'branch'                                => 'refactor/1243',
			'from'                                  => 'origin/main',
			'inject_context'                        => false,
			'bootstrap'                             => false,
			'allow_stale'                           => true,
			'rebase_base'                           => true,
			'force'                                 => true,
			'task_url'                              => 'https://github.com/Extra-Chill/data-machine-code/issues/1243/?source=test#fragment',
			'task_ref'                              => 'Extra-Chill/data-machine-code#1243',
			'allow_unverified_freshness'            => true,
			'require_task_tracker'                  => false,
			'purpose'                               => 'allocation-contract',
			'owner_run_ref'                         => 'test-run',
			'cleanup_policy'                        => 'remove_on_success',
			'reuse_policy'                          => 'isolated',
			'remediate_capacity'                    => true,
			'remediate_capacity_dry_run'            => true,
			'progress_callback'                     => $progress,
			'expected_freshness_identity'           => array( 'target_head' => 'abc123' ),
			'allow_percentage_byte_floor_exception' => true,
		)
	);

	allocation_request_assert('origin/main' === $request->from, 'Base ref should be preserved.');
	allocation_request_assert(! $request->inject_context && ! $request->bootstrap, 'Explicit false defaults should be preserved.');
	allocation_request_assert($request->allow_stale && $request->rebase_base && $request->force, 'Admission overrides should be preserved.');
	allocation_request_assert(
		'https://github.com/Extra-Chill/data-machine-code/issues/1243' === $request->task['task_url'],
		'Task URLs should use the canonical lifecycle identity.'
	);
	allocation_request_assert('Extra-Chill/data-machine-code#1243' === $request->task['task_ref'], 'Task refs should be preserved.');
	allocation_request_assert('allocation-contract' === $request->intent['purpose'], 'Lifecycle purpose should be grouped into intent.');
	allocation_request_assert('isolated' === $request->reuse_policy, 'Explicit reuse policy should be preserved.');
	allocation_request_assert($progress === $request->progress_callback, 'Progress callbacks should be preserved.');
	allocation_request_assert(array( 'target_head' => 'abc123' ) === $request->expected_freshness_identity, 'Freshness identity should be preserved.');

	$apply_request = WorktreeAllocationRequest::from_input(array(
		'repo'   => 'data-machine-code',
		'branch' => 'refactor/1243',
		'task'   => array(
			'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/1243',
			'task_ref' => 'Extra-Chill/data-machine-code#1243',
		),
		'intent' => array(
			'purpose'        => 'plan-apply',
			'owner_run_ref'  => 'test-run',
			'cleanup_policy' => 'remove_on_success',
		),
	));

	allocation_request_assert('Extra-Chill/data-machine-code#1243' === $apply_request->task['task_ref'], 'Nested plan task metadata should be preserved.');
	allocation_request_assert('plan-apply' === $apply_request->intent['purpose'], 'Nested plan intent should be preserved.');
} finally {
	false === $previous_task_url ? putenv('DATAMACHINE_TASK_URL') : putenv('DATAMACHINE_TASK_URL=' . $previous_task_url);
	false === $previous_task_ref ? putenv('DATAMACHINE_TASK_REF') : putenv('DATAMACHINE_TASK_REF=' . $previous_task_ref);
}

echo "worktree-allocation-request ok\n";
