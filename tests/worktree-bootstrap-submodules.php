<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';

use DataMachineCode\Workspace\WorktreeBootstrapper;

function worktree_bootstrap_submodules_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function worktree_bootstrap_submodules_write( string $path, string $contents = '' ): void {
	if ( ! is_dir(dirname($path)) && ! mkdir(dirname($path), 0777, true) && ! is_dir(dirname($path)) ) {
		throw new RuntimeException(sprintf('Unable to create fixture directory for %s.', $path));
	}
	file_put_contents($path, $contents);
}

$base = sys_get_temp_dir() . '/dmc-bootstrap-submodules-' . bin2hex(random_bytes(6));

	$submodule_fixture = $base . '/submodule-fixture';
	worktree_bootstrap_submodules_write($submodule_fixture . '/package-lock.json', '{}');
	worktree_bootstrap_submodules_write(
		$submodule_fixture . '/.gitmodules',
		"[submodule \"space\"]\n\tpath = with space\n\turl = https://example.invalid/space.git\n[submodule \"uninitialized\"]\n\tpath = missing commit\n\turl = https://example.invalid/missing.git\n"
	);
	worktree_bootstrap_submodules_write($submodule_fixture . '/with space/package-lock.json', '{}');

	$default = WorktreeBootstrapper::detect($submodule_fixture);
	worktree_bootstrap_submodules_assert_same(true, $default['submodules'], 'Declared but uninitialized submodules remain visible to package discovery.');
	worktree_bootstrap_submodules_assert_same(
		array( array( 'path' => $submodule_fixture, 'relative' => '.', 'manager' => 'npm' ) ),
		$default['package_roots'],
		'Default discovery selects only the superproject package.'
	);
	worktree_bootstrap_submodules_assert_same(
		array(
			array(
				'relative' => 'with space',
				'manager'  => 'npm',
				'reason'   => 'git submodule dependency root is excluded by default',
			),
		),
		$default['skipped_package_roots'],
		'Submodule package roots with spaces are retained as structured skip evidence.'
	);

	worktree_bootstrap_submodules_write(
		$submodule_fixture . '/.datamachine/worktree-bootstrap.json',
		"{\n  \"submodule_dependency_roots\": [\"with space\"]\n}\n"
	);
	$opted_in = WorktreeBootstrapper::detect($submodule_fixture);
	worktree_bootstrap_submodules_assert_same(
		array(
			array( 'path' => $submodule_fixture, 'relative' => '.', 'manager' => 'npm' ),
			array( 'path' => $submodule_fixture . '/with space', 'relative' => 'with space', 'manager' => 'npm' ),
		),
		$opted_in['package_roots'],
		'Explicit superproject contract enables discovery of the declared submodule package.'
	);
	worktree_bootstrap_submodules_assert_same(array(), $opted_in['skipped_package_roots'], 'Opted-in submodule is not reported as skipped.');

	$monorepo_fixture = $base . '/monorepo-fixture';
	worktree_bootstrap_submodules_write($monorepo_fixture . '/package-lock.json', '{}');
	worktree_bootstrap_submodules_write($monorepo_fixture . '/ordinary/package-lock.json', '{}');
	$monorepo = WorktreeBootstrapper::detect($monorepo_fixture);
	worktree_bootstrap_submodules_assert_same(
		array(
			array( 'path' => $monorepo_fixture, 'relative' => '.', 'manager' => 'npm' ),
			array( 'path' => $monorepo_fixture . '/ordinary', 'relative' => 'ordinary', 'manager' => 'npm' ),
		),
		$monorepo['package_roots'],
		'Ordinary nested monorepo packages remain discoverable.'
	);

echo "worktree-bootstrap-submodules: ok\n";
