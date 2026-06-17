<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHandle.php';

use DataMachineCode\Workspace\WorkspaceHandle;

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$primary = WorkspaceHandle::parse(' data-machine-code ');
assert_same('data-machine-code', $primary->repo(), 'primary repo parses');
assert_same(null, $primary->branch_slug(), 'primary has no branch slug');
assert_same(false, $primary->is_worktree(), 'primary is not worktree');
assert_same('data-machine-code', $primary->dir_name(), 'primary dir name matches repo');

$worktree = WorkspaceHandle::parse('data-machine-code@cook-workspace-identity-primitives');
assert_same('data-machine-code', $worktree->repo(), 'worktree repo parses');
assert_same('cook-workspace-identity-primitives', $worktree->branch_slug(), 'worktree branch slug parses');
assert_same(true, $worktree->is_worktree(), 'worktree is worktree');
assert_same('data-machine-code@cook-workspace-identity-primitives', $worktree->dir_name(), 'worktree dir name is canonical');
assert_same(
	array(
		'repo'        => 'data-machine-code',
		'branch_slug' => 'cook-workspace-identity-primitives',
		'is_worktree' => true,
		'dir_name'    => 'data-machine-code@cook-workspace-identity-primitives',
	),
	$worktree->to_array(),
	'worktree array shape matches parse_handle contract'
);

$fallback = WorkspaceHandle::parse('data-machine-code@');
assert_same('data-machine-code', $fallback->repo(), 'invalid worktree falls back to primary parsing');
assert_same(false, $fallback->is_worktree(), 'invalid worktree fallback is primary');

echo "workspace-handle: ok\n";
