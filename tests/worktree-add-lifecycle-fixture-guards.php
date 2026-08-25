<?php

declare(strict_types=1);

require_once __DIR__ . '/worktree-lifecycle-fixture-guard-support.inc';

function fixture_guard_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function fixture_guard_refuses( callable $callback, string $message ): void {
	try {
		$callback();
	} catch (RuntimeException) {
		return;
	}
	throw new RuntimeException($message);
}

function fixture_guard_command( string $command, string $cwd ): array {
	$output = array();
	$status = 0;
	exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $status);
	return array( $status, implode("\n", $output) );
}

$test_file = __DIR__ . '/worktree-add-lifecycle.php';
$cwd       = getcwd() ?: __DIR__;
$before    = fixture_guard_command('git status --porcelain', $cwd);
$command   = 'php -r ' . escapeshellarg("define('DATAMACHINE_WORKSPACE_PATH', " . var_export($cwd, true) . '); require ' . var_export($test_file, true) . ';');
$result    = fixture_guard_command($command, $cwd);
$after     = fixture_guard_command('git status --porcelain', $cwd);

fixture_guard_assert(0 !== $result[0], 'A predefined external workspace path did not fail before bootstrap.');
fixture_guard_assert(str_contains($result[1], 'refuses a predefined DATAMACHINE_WORKSPACE_PATH'), 'Conflicting workspace refusal did not identify the fixture guard.');
fixture_guard_assert($before === $after, 'Conflicting workspace refusal changed the invoking managed worktree.');
$lifecycle_source = (string) file_get_contents($test_file);
$guard_offset     = strpos($lifecycle_source, 'worktree_lifecycle_assert_fixture_cleanup_safe($path, $fixture);');
$deletion_offset  = strpos($lifecycle_source, 'new RecursiveIteratorIterator(');
fixture_guard_assert(false !== $guard_offset && false !== $deletion_offset && $guard_offset < $deletion_offset, 'Fixture guard does not precede recursive deletion.');

$temp_root = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$root      = rtrim($temp_root, '/') . '/dmc-fixture-guard-' . getmypid() . '-' . bin2hex(random_bytes(8));
fixture_guard_assert(mkdir($root, 0700), 'Could not create the owned guard fixture.');
$identity = realpath($root);
fixture_guard_assert(is_string($identity) && $identity === $root && ! worktree_lifecycle_fixture_has_symlink_component($root), 'Owned guard fixture is not canonical.');
$sentinel = $root . '/sentinel';
$token    = bin2hex(random_bytes(16));
fixture_guard_assert(false !== file_put_contents($sentinel, $token . "\n"), 'Could not create the guard fixture sentinel.');
$fixture = array(
	'root'              => $root,
	'identity'          => $identity,
	'sentinel'          => $sentinel,
	'sentinel_identity' => $sentinel,
	'token'             => $token,
);

try {
	$malformed_repository = $root . '/malformed-marker';
	fixture_guard_assert(mkdir($malformed_repository, 0700), 'Could not create the malformed marker fixture.');
	fixture_guard_assert(false !== file_put_contents($malformed_repository . '/.git', 'not a Git directory marker\n'), 'Could not create the malformed Git marker fixture.');
	fixture_guard_assert(! worktree_lifecycle_fixture_is_inspectable_repository($malformed_repository), 'Malformed Git marker was treated as an inspectable repository.');
	worktree_lifecycle_assert_fixture_cleanup_safe($root, $fixture);
	fixture_guard_assert(unlink($malformed_repository . '/.git') && rmdir($malformed_repository), 'Could not clean the malformed marker fixture after registry inspection.');

	$alias = $root . '-alias';
	fixture_guard_assert(symlink($root, $alias), 'Could not create the fixture symlink alias.');
	fixture_guard_refuses(static fn() => worktree_lifecycle_assert_fixture_cleanup_safe($alias, $fixture, static fn(): array => array()), 'Symlink fixture alias was not refused.');

	fixture_guard_refuses(static fn() => worktree_lifecycle_assert_fixture_cleanup_safe($root, $fixture, static fn(): array => array( $identity )), 'Registered fixture target was not refused.');
	fixture_guard_refuses(static fn() => worktree_lifecycle_assert_fixture_cleanup_safe($root, $fixture, static function (): array {
		throw new RuntimeException('registry unavailable');
	}), 'Unavailable managed-worktree registry was not refused.');

	$outside_sentinel                     = __FILE__;
	$outside_fixture                      = $fixture;
	$outside_fixture['sentinel']          = $outside_sentinel;
	$outside_fixture['sentinel_identity'] = realpath($outside_sentinel) ?: '';
	fixture_guard_refuses(static fn() => worktree_lifecycle_assert_fixture_cleanup_safe($root, $outside_fixture, static fn(): array => array()), 'Outside-fixture sentinel was not refused.');
} finally {
	if ( is_file(($malformed_repository ?? '') . '/.git') && ! is_link($malformed_repository) ) {
		unlink($malformed_repository . '/.git');
	}
	if ( is_dir($malformed_repository ?? '') && ! is_link($malformed_repository) ) {
		rmdir($malformed_repository);
	}
	if ( is_link($alias ?? '') ) {
		unlink($alias);
	}
	if ( is_file($sentinel) && ! is_link($sentinel) ) {
		unlink($sentinel);
	}
	rmdir($root);
}

fwrite(STDOUT, "worktree-add-lifecycle-fixture-guards ok: external path {$cwd} unchanged; refusal-only fixture {$root}\n");
