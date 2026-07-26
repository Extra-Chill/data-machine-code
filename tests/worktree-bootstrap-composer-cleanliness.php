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

function worktree_bootstrap_tracked_files_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function worktree_bootstrap_tracked_files_command( string $command, string $cwd ): string {
	$lines = array();
	exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $lines, $exit_code);
	if ( 0 !== $exit_code ) {
		throw new RuntimeException(sprintf('Command failed (%d): %s', $exit_code, implode("\n", $lines)));
	}
	return implode("\n", $lines);
}

function worktree_bootstrap_tracked_files_write( string $path, string $contents ): void {
	if ( ! is_dir(dirname($path)) && ! mkdir(dirname($path), 0777, true) && ! is_dir(dirname($path)) ) {
		throw new RuntimeException(sprintf('Unable to create directory for %s.', $path));
	}
	file_put_contents($path, $contents);
}

function worktree_bootstrap_tracked_files_fixture( string $base, string $name ): string {
	$repo = $base . '/' . $name;
	mkdir($repo, 0777, true);
	worktree_bootstrap_tracked_files_command('git init -q && git config user.email test@example.invalid && git config user.name Test', $repo);
	worktree_bootstrap_tracked_files_write($repo . '/figma-transformer/composer.lock', "{}\n");
	worktree_bootstrap_tracked_files_write($repo . '/figma-transformer/vendor/composer/installed.php', "original\n");
	worktree_bootstrap_tracked_files_write($repo . '/bootstrap-marker.txt', "original marker\n");
	worktree_bootstrap_tracked_files_command('git add . && git commit -qm initial', $repo);
	return $repo;
}

$base = sys_get_temp_dir() . '/dmc-bootstrap-tracked-files-' . bin2hex(random_bytes(6));
$bin  = $base . '/bin';
mkdir($bin, 0777, true);
worktree_bootstrap_tracked_files_write(
	$bin . '/composer',
	"#!/bin/sh\nmkdir -p vendor/composer\nprintf '%s\\n' \"rewritten\" > vendor/composer/installed.php\nprintf '%s\\n' \"rewritten marker\" > ../bootstrap-marker.txt\nprintf '%s\\n' \"autoload\" > vendor/autoload.php\nif [ \"\${DMC_COMPOSER_FAIL:-0}\" = 1 ]; then exit 7; fi\n"
);
chmod($bin . '/composer', 0755);

$old_path = (string) getenv('PATH');
putenv('PATH=' . $bin . PATH_SEPARATOR . $old_path);

$success = worktree_bootstrap_tracked_files_fixture($base, 'success');
$installed = $success . '/figma-transformer/vendor/composer/installed.php';
worktree_bootstrap_tracked_files_write($installed, "preexisting staged edit\n");
worktree_bootstrap_tracked_files_command('git add figma-transformer/vendor/composer/installed.php', $success);
worktree_bootstrap_tracked_files_write($installed, "preexisting local edit\n");
$cached_before   = worktree_bootstrap_tracked_files_command('git diff --cached --binary', $success);
$worktree_before = worktree_bootstrap_tracked_files_command('git diff --binary', $success);
putenv('DMC_COMPOSER_FAIL=0');
$result = WorktreeBootstrapper::bootstrap($success);
$composer = $result['steps'][2];
worktree_bootstrap_tracked_files_assert_same(WorktreeBootstrapper::STATUS_RAN, $composer['status'], 'Successful Composer bootstrap remains successful after cleanup: ' . var_export($composer, true));
worktree_bootstrap_tracked_files_assert_same("preexisting local edit\n", file_get_contents($installed), 'Pre-existing tracked modification is preserved.');
worktree_bootstrap_tracked_files_assert_same($cached_before, worktree_bootstrap_tracked_files_command('git diff --cached --binary', $success), 'Pre-existing staged state is preserved exactly.');
worktree_bootstrap_tracked_files_assert_same($worktree_before, worktree_bootstrap_tracked_files_command('git diff --binary', $success), 'Pre-existing unstaged state is preserved exactly.');
worktree_bootstrap_tracked_files_assert_same("original marker\n", file_get_contents($success . '/bootstrap-marker.txt'), 'Tracked mutations outside the nested package are restored.');
worktree_bootstrap_tracked_files_assert_same("autoload\n", file_get_contents($success . '/figma-transformer/vendor/autoload.php'), 'Untracked Composer dependency output remains installed.');
worktree_bootstrap_tracked_files_assert_same(
	array( 'bootstrap-marker.txt', 'figma-transformer/vendor/composer/installed.php' ),
	$composer['tracked_file_cleanup']['restored_paths'],
	'Cleanup evidence identifies the rewritten nested tracked metadata path.'
);
worktree_bootstrap_tracked_files_assert_same(
	array( 'figma-transformer/vendor/composer/installed.php' ),
	$composer['tracked_file_cleanup']['retained_paths'],
	'Cleanup evidence identifies the retained pre-existing mutation.'
);

$failure = worktree_bootstrap_tracked_files_fixture($base, 'failure');
putenv('DMC_COMPOSER_FAIL=1');
$result = WorktreeBootstrapper::bootstrap($failure);
$composer = $result['steps'][2];
worktree_bootstrap_tracked_files_assert_same(WorktreeBootstrapper::STATUS_FAILED, $composer['status'], 'Failed Composer bootstrap remains failed after cleanup.');
worktree_bootstrap_tracked_files_assert_same("original\n", file_get_contents($failure . '/figma-transformer/vendor/composer/installed.php'), 'Tracked metadata is restored when Composer fails.');
worktree_bootstrap_tracked_files_assert_same("original marker\n", file_get_contents($failure . '/bootstrap-marker.txt'), 'Repository-wide tracked state is restored when Composer fails.');
worktree_bootstrap_tracked_files_assert_same("autoload\n", file_get_contents($failure . '/figma-transformer/vendor/autoload.php'), 'Untracked dependency output remains after a failed install.');
worktree_bootstrap_tracked_files_assert_same(
	array( 'bootstrap-marker.txt', 'figma-transformer/vendor/composer/installed.php' ),
	$composer['tracked_file_cleanup']['restored_paths'],
	'Failure cleanup evidence identifies the restored tracked path.'
);
worktree_bootstrap_tracked_files_assert_same('', worktree_bootstrap_tracked_files_command('git diff -- figma-transformer/vendor/composer/installed.php', $failure), 'Failed bootstrap leaves tracked generated metadata clean.');

putenv('PATH=' . $old_path);
putenv('DMC_COMPOSER_FAIL');
echo "worktree-bootstrap-composer-cleanliness: ok\n";
