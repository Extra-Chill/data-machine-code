<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/dmc-primary-common-dir-' . getmypid();
define('DATAMACHINE_WORKSPACE_PATH', $root);
define('ABSPATH', __DIR__ . '/fixtures/');

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_option(string $key, mixed $default = false): mixed { return $default; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';

use DataMachineCode\Workspace\Workspace;

function primary_common_dir_run(string $cwd, string $command): void {
	passthru('git -C ' . escapeshellarg($cwd) . ' ' . $command . ' 2>&1', $status);
	if (0 !== $status) { throw new RuntimeException('git failed: ' . $command); }
}
function primary_common_dir_assert(bool $condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}

mkdir($root, 0700, true);
$primary = $root . '/example';
primary_common_dir_run($root, 'init -q -b main ' . escapeshellarg($primary));
file_put_contents($primary . '/tracked', 'one');
primary_common_dir_run($primary, 'add tracked');
primary_common_dir_run($primary, '-c user.email=t@example.test -c user.name=t commit -qm initial');
$worktree = $root . '/example@fix-primary-guard';
primary_common_dir_run($primary, 'worktree add -q -b fix/primary-guard ' . escapeshellarg($worktree));

$workspace = new Workspace();
$primary_remove = $workspace->remove_repo('example');
primary_common_dir_assert(is_wp_error($primary_remove), 'generic workspace remove must refuse an authoritative primary');
primary_common_dir_assert('primary_common_dir_protected' === $primary_remove->get_error_code(), 'primary refusal must use the stable common-dir protection code');
primary_common_dir_assert(is_dir($primary . '/.git') && is_dir($worktree), 'primary refusal must preserve the common Git directory and linked worktree');

$worktree_remove = $workspace->worktree_remove('example', 'fix/primary-guard');
primary_common_dir_assert(is_array($worktree_remove) && ! is_dir($worktree), 'direct worktree remove must complete filesystem removal before reporting success');
primary_common_dir_assert(is_dir($primary . '/.git'), 'direct worktree remove must preserve the primary common Git directory');

primary_common_dir_run($primary, 'status --porcelain');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
	$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($root);

fwrite(STDOUT, "primary-common-dir-protection: ok\n");
