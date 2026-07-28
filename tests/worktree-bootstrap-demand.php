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
$GLOBALS['bootstrap_demand_filters'] = array();
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$filter = $GLOBALS['bootstrap_demand_filters'][ $hook ] ?? null;
	return is_callable($filter) ? $filter($value, ...$args) : $value;
}

require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
require_once dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';

use DataMachineCode\Workspace\WorktreeBootstrapper;

function bootstrap_demand_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$fixture = sys_get_temp_dir() . '/dmc-bootstrap-demand-' . bin2hex(random_bytes(6));
$bin = $fixture . '/bin';
$repo = $fixture . '/repo';
mkdir($fixture . '/frontend', 0777, true);
mkdir($fixture . '/php', 0777, true);
mkdir($bin, 0777, true);
mkdir($repo, 0777, true);
file_put_contents($fixture . '/package-lock.json', '{}');
file_put_contents($fixture . '/frontend/pnpm-lock.yaml', '');
file_put_contents($fixture . '/php/composer.lock', '{}');
file_put_contents($fixture . '/.gitmodules', "[submodule \"dependency\"]\n\tpath = dependency\n\turl = https://example.invalid/dependency.git\n");

try {
	$bare = WorktreeBootstrapper::demand_plan($fixture, false);
	bootstrap_demand_assert(256 === $bare['inodes'], 'Bare creation must retain the Git mutation/index-lock inode reserve.');
	bootstrap_demand_assert(0 === $bare['counts']['package_roots'], 'Bare creation must not budget disabled package bootstrap.');

	$bootstrap = WorktreeBootstrapper::demand_plan($fixture, true);
	bootstrap_demand_assert(1 === $bootstrap['counts']['submodules'], 'Demand must derive declared submodules from detect planning.');
	bootstrap_demand_assert(2 === $bootstrap['counts']['package_roots'], 'Demand must derive package roots from detect planning.');
	bootstrap_demand_assert(1 === $bootstrap['counts']['composer_roots'], 'Demand must derive Composer roots from detect planning.');
	bootstrap_demand_assert($bootstrap['inodes'] > $bare['inodes'], 'Bootstrap demand must exceed the Git-only reserve.');
	bootstrap_demand_assert('conservative_defaults' === $bootstrap['source'], 'Standalone fallback source must be explicit.');
	bootstrap_demand_assert(str_contains($bootstrap['fallback_semantics'], 'without_wordpress'), 'Fallback semantics must be explicit.');

	exec('git -C ' . escapeshellarg($repo) . ' init -q && git -C ' . escapeshellarg($repo) . ' config user.email test@example.com && git -C ' . escapeshellarg($repo) . ' config user.name Test');
	file_put_contents($repo . '/README.md', "primary\n");
	exec('git -C ' . escapeshellarg($repo) . ' add README.md && git -C ' . escapeshellarg($repo) . ' commit -qm primary');
	$primary_branch = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' branch --show-current'));
	exec('git -C ' . escapeshellarg($repo) . ' checkout -qb target-tree');
	mkdir($repo . '/frontend', 0777, true);
	file_put_contents($repo . '/frontend/package-lock.json', '{}');
	for ( $index = 0; $index < 300; ++$index ) {
		file_put_contents($repo . '/frontend/tracked-' . $index . '.txt', 'x');
	}
	exec('git -C ' . escapeshellarg($repo) . ' add frontend && git -C ' . escapeshellarg($repo) . ' commit -qm target');
	exec('git -C ' . escapeshellarg($repo) . ' checkout -q ' . escapeshellarg($primary_branch));

	$primary_tree = WorktreeBootstrapper::demand_plan_for_target($repo, $primary_branch, true);
	$target_tree  = WorktreeBootstrapper::demand_plan_for_target($repo, 'target-tree', true);
	bootstrap_demand_assert(! is_wp_error($primary_tree) && ! is_wp_error($target_tree), 'Both primary and differing target refs must resolve before admission.');
	bootstrap_demand_assert(0 === $primary_tree['counts']['package_roots'] && 1 === $target_tree['counts']['package_roots'], 'Dependency demand must come from the target tree rather than the current primary checkout.');
	bootstrap_demand_assert($target_tree['counts']['tracked_entries'] > 256, 'Target-tree tracked entry demand fixture must exceed the old fixed Git reserve.');
	bootstrap_demand_assert($target_tree['inodes'] >= $target_tree['counts']['tracked_entries'] + $target_tree['git_safety_margin']['inodes'], 'Admission must reserve tracked materialization plus an explicit Git lock margin.');
	bootstrap_demand_assert($target_tree['target_commit'] !== $primary_tree['target_commit'], 'Differing refs must retain their exact resolved commits in demand evidence.');

	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_bootstrap_command_timeout_seconds'] = static fn() => 1;
	bootstrap_demand_assert(1 === WorktreeBootstrapper::command_timeout_seconds('submodules'), 'Bootstrap command timeout must be explicitly filterable.');
	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_capacity_wait_timeout_seconds'] = static fn() => 77;
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';
	$lock_policy = new class { use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle; };
	bootstrap_demand_assert(77 === $lock_policy::worktree_capacity_wait_timeout_seconds(true), 'Capacity admission wait must use its explicit filter instead of the lock default.');
	$lifecycle_source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
	bootstrap_demand_assert(str_contains((string) $lifecycle_source, 'self::worktree_capacity_wait_timeout_seconds($bootstrap)'), 'Admission must pass the explicit capacity wait policy to the global lock.');
	unset($GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_capacity_wait_timeout_seconds']);
	bootstrap_demand_assert(WorktreeBootstrapper::total_timeout_seconds() + 600 === $lock_policy::worktree_capacity_wait_timeout_seconds(true), 'Capacity wait must exceed the complete bounded bootstrap lifecycle.');

	$git = $bin . '/git';
	file_put_contents($git, "#!/bin/sh\nexec sleep 5\n");
	chmod($git, 0700);
	$original_path = (string) getenv('PATH');
	putenv('PATH=' . $bin . PATH_SEPARATOR . $original_path);
	$started = microtime(true);
	$run_command = new ReflectionMethod(WorktreeBootstrapper::class, 'run_command');
	$submodule = $run_command->invoke(null, 'submodules', $fixture, 'git submodule update --init --recursive');
	putenv('PATH=' . $original_path);
	bootstrap_demand_assert('command_timeout' === ( $submodule['reason'] ?? '' ), 'Bounded bootstrap failure must expose a stable timeout reason.');
	bootstrap_demand_assert(true === ( $submodule['timed_out'] ?? false ) && 1 === ( $submodule['timeout_seconds'] ?? null ), 'Bootstrap timeout evidence must be structured.');
	bootstrap_demand_assert(microtime(true) - $started < 3, 'Bootstrap command must stop within its finite deadline.');

	echo "worktree-bootstrap-demand: ok\n";
} finally {
	if ( is_dir($repo) ) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ( $iterator as $entry ) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($repo);
	}
	foreach ( array( 'package-lock.json', 'frontend/pnpm-lock.yaml', 'php/composer.lock', '.gitmodules' ) as $file ) {
		unlink($fixture . '/' . $file);
	}
	if ( is_file($bin . '/git') ) { unlink($bin . '/git'); }
	rmdir($bin);
	rmdir($fixture . '/frontend');
	rmdir($fixture . '/php');
	rmdir($fixture);
}
