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

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';

use DataMachineCode\Workspace\WorktreeBootstrapper;

function worktree_bootstrap_shrinkwrap_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function worktree_bootstrap_shrinkwrap_command( string $command, string $cwd ): void {
	exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $output, $exit_code);
	if ( 0 !== $exit_code ) {
		throw new RuntimeException(sprintf('Command failed (%d): %s', $exit_code, implode("\n", $output)));
	}
}

function worktree_bootstrap_shrinkwrap_remove( string $path ): void {
	if ( ! is_dir($path) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ( $iterator as $entry ) {
		$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
	}
	rmdir($path);
}

$base    = sys_get_temp_dir() . '/dmc-bootstrap-npm-shrinkwrap-' . bin2hex(random_bytes(6));
$repo    = $base . '/repo';
$bin     = $base . '/bin';
$npm_log = $base . '/npm.log';
mkdir($repo . '/frontend', 0777, true);
mkdir($repo . '/precedence', 0777, true);
mkdir($bin, 0777, true);
file_put_contents($repo . '/npm-shrinkwrap.json', "{}\n");
file_put_contents($repo . '/frontend/npm-shrinkwrap.json', "{}\n");
file_put_contents($repo . '/precedence/npm-shrinkwrap.json', "{}\n");
file_put_contents($repo . '/precedence/yarn.lock', "# yarn lockfile v1\n");

try {
	$detected = WorktreeBootstrapper::detect($repo);
	worktree_bootstrap_shrinkwrap_assert_same('npm', $detected['packages'], 'A root npm-shrinkwrap.json must select npm.');
	worktree_bootstrap_shrinkwrap_assert_same(
		array(
			array( 'path' => $repo, 'relative' => '.', 'manager' => 'npm' ),
			array( 'path' => $repo . '/frontend', 'relative' => 'frontend', 'manager' => 'npm' ),
			array( 'path' => $repo . '/precedence', 'relative' => 'precedence', 'manager' => 'yarn' ),
		),
		$detected['package_roots'],
		'Root and one-level npm-shrinkwrap.json files must be package roots.'
	);

	worktree_bootstrap_shrinkwrap_command('git init -q && git config user.email test@example.invalid && git config user.name Test && git add . && git commit -qm initial', $repo);
	$target_plan = WorktreeBootstrapper::demand_plan_for_target($repo, 'HEAD', true);
	worktree_bootstrap_shrinkwrap_assert_same(false, $target_plan instanceof WP_Error, 'The shrinkwrap target tree must be inspectable.');
	worktree_bootstrap_shrinkwrap_assert_same(3, $target_plan['counts']['package_roots'], 'Target-tree demand must include root and one-level shrinkwrap package roots.');
	worktree_bootstrap_shrinkwrap_assert_same(
		array(
			array( 'relative' => 'frontend', 'manager' => 'npm' ),
			array( 'relative' => '.', 'manager' => 'npm' ),
			array( 'relative' => 'precedence', 'manager' => 'yarn' ),
		),
		$target_plan['detected']['package_roots'],
		'Target-tree parsing must classify shrinkwrap roots as npm while preserving Yarn precedence.'
	);

	file_put_contents($bin . '/npm', "#!/bin/sh\nprintf '%s|%s\\n' \"\$PWD\" \"\$*\" >> \"\$DMC_NPM_LOG\"\n");
	chmod($bin . '/npm', 0755);
	file_put_contents($bin . '/yarn', "#!/bin/sh\nexit 0\n");
	chmod($bin . '/yarn', 0755);
	$old_path = (string) getenv('PATH');
	$old_home = (string) getenv('HOME');
	putenv('PATH=' . $bin . PATH_SEPARATOR . $old_path);
	putenv('HOME=' . $base);
	putenv('DMC_NPM_LOG=' . $npm_log);
	$result = WorktreeBootstrapper::bootstrap($repo);
	putenv('PATH=' . $old_path);
	putenv('HOME=' . $old_home);
	putenv('DMC_NPM_LOG');

	$package_steps = array_values(array_filter($result['steps'], static fn( array $step ): bool => WorktreeBootstrapper::STEP_PACKAGES === $step['step']));
	worktree_bootstrap_shrinkwrap_assert_same(3, count($package_steps), 'Both shrinkwrap roots and the higher-priority Yarn root must run package bootstrap.');
	worktree_bootstrap_shrinkwrap_assert_same('npm ci', $package_steps[0]['command'], 'Shrinkwrap bootstrap must select npm ci.');
	worktree_bootstrap_shrinkwrap_assert_same('npm ci', $package_steps[1]['command'], 'Nested shrinkwrap bootstrap must select npm ci.');
	worktree_bootstrap_shrinkwrap_assert_same('yarn install --immutable', $package_steps[2]['command'], 'A Yarn lockfile must retain precedence over shrinkwrap.');
	worktree_bootstrap_shrinkwrap_assert_same(
		array( realpath($repo) . '|ci', realpath($repo . '/frontend') . '|ci' ),
		explode("\n", trim((string) file_get_contents($npm_log))),
		'Fake npm must receive ci in each package root without downloading dependencies.'
	);

	echo "worktree-bootstrap-npm-shrinkwrap: ok\n";
} finally {
	worktree_bootstrap_shrinkwrap_remove($base);
}
