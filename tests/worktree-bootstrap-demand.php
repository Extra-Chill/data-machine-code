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
mkdir($fixture . '/frontend', 0777, true);
mkdir($fixture . '/php', 0777, true);
mkdir($bin, 0777, true);
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

	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_bootstrap_command_timeout_seconds'] = static fn() => 1;
	bootstrap_demand_assert(1 === WorktreeBootstrapper::command_timeout_seconds('submodules'), 'Bootstrap command timeout must be explicitly filterable.');
	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_capacity_wait_timeout_seconds'] = static fn() => 77;
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';
	$lock_policy = new class { use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle; };
	bootstrap_demand_assert(77 === $lock_policy::worktree_capacity_wait_timeout_seconds(true), 'Capacity admission wait must use its explicit filter instead of the lock default.');
	$lifecycle_source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
	bootstrap_demand_assert(str_contains((string) $lifecycle_source, 'self::worktree_capacity_wait_timeout_seconds($bootstrap)'), 'Admission must pass the explicit capacity wait policy to the global lock.');

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
	foreach ( array( 'package-lock.json', 'frontend/pnpm-lock.yaml', 'php/composer.lock', '.gitmodules' ) as $file ) {
		unlink($fixture . '/' . $file);
	}
	if ( is_file($bin . '/git') ) { unlink($bin . '/git'); }
	rmdir($bin);
	rmdir($fixture . '/frontend');
	rmdir($fixture . '/php');
	rmdir($fixture);
}
