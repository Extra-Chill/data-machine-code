<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/dmc-primary-restore-' . getmypid();
$source = $root . '-source';
define('DATAMACHINE_WORKSPACE_PATH', $root);
define('ABSPATH', __DIR__ . '/fixtures/');
define('ARRAY_A', 'ARRAY_A');
$state = array();
$options = array();

class WP_Error { public function __construct(private string $code, private string $message = '', private mixed $data = null) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } public function get_error_data(): mixed { return $this->data; } }
class Restore_Test_Wpdb {
	public array $rows = array();
	public function get_results(string $sql, string $output = ARRAY_A): array { $rows = array_values($this->rows); usort($rows, static fn(array $a, array $b): int => strcmp($a['handle'], $b['handle'])); return $rows; }
	public function replace(string $table, array $row): int { $this->rows[$row['handle']] = array_merge($this->rows[$row['handle']] ?? array(), $row); return 1; }
	public function prepare(string $query, mixed ...$args): string { return $query; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_option(string $key, mixed $default = false): mixed { global $state, $options; return 'datamachine_code_remote_workspace_state' === $key ? $state : ($options[$key] ?? $default); }
function update_option(string $key, mixed $value, bool $autoload = false): bool { global $options; $options[$key] = $value; return true; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function a(bool $value, string $message): void { if (! $value) { throw new RuntimeException($message); } }
function g(string $directory, string $command): void { passthru('git -C ' . escapeshellarg($directory) . ' ' . $command . ' 2>&1', $status); if ($status) { throw new RuntimeException($command); } }
function r(string $path): void { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($path); }

mkdir($root, 0700, true); mkdir($source, 0700, true);
$origin = $source . '/origin.git'; $seed = $source . '/seed';
g($source, 'init -q --bare ' . escapeshellarg($origin)); g($source, 'init -q -b main ' . escapeshellarg($seed)); file_put_contents($seed . '/a', 'a'); g($seed, 'add a'); g($seed, '-c user.email=t@t -c user.name=t commit -qm a'); g($seed, 'remote add origin ' . escapeshellarg($origin)); g($seed, 'push -q origin main');
mkdir($root . '/fixture@linked-a', 0700, true); mkdir($root . '/fixture@retained', 0700, true); mkdir($root . '/fixture@linked-b', 0700, true);
file_put_contents($root . '/fixture@linked-a/.git', 'gitdir: ' . $root . '/fixture/.git/worktrees/linked-a');
file_put_contents($root . '/fixture@retained/.git', 'not a linked worktree marker');
file_put_contents($root . '/fixture@linked-b/.git', 'gitdir: ' . $root . '/fixture/.git/worktrees/linked-b');
global $wpdb, $state; $wpdb = new Restore_Test_Wpdb();
$wpdb->rows = array(
	'fixture@linked-a' => array('handle' => 'fixture@linked-a', 'repo' => 'fixture', 'path' => $root . '/fixture@linked-a', 'is_primary' => 0, 'metadata' => null),
	'fixture@retained' => array('handle' => 'fixture@retained', 'repo' => 'fixture', 'path' => $root . '/fixture@retained', 'is_primary' => 0, 'metadata' => null),
	'fixture@linked-b' => array('handle' => 'fixture@linked-b', 'repo' => 'fixture', 'path' => $root . '/fixture@linked-b', 'is_primary' => 0, 'metadata' => null),
);
$state = array('repos' => array('fixture' => array('repo' => 'fixture/repo', 'url' => $origin)), 'repo_names' => array('fixture/repo' => 'fixture'), 'worktrees' => array());
require_once dirname(__DIR__) . '/vendor/autoload.php'; require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';
use DataMachineCode\Workspace\Workspace;

$workspace = new Workspace();
$plan = $workspace->primary_restore_plan('fixture', 1, 0);
a(is_array($plan) && 64 === strlen($plan['digest']) && true === $plan['has_more'], 'registered remote missing-primary plan must be digest-addressed and bounded');
a($plan === $workspace->primary_restore_plan('fixture', 1, 0), 'an unchanged live replan must retain plan identity');
$changed = $plan; $changed['digest'] = 'bad'; a(is_wp_error($workspace->primary_restore_apply($changed)), 'changed plan must fail closed');
$first = $workspace->primary_restore_apply($plan);
a(is_array($first) && array('fixture@linked-a') === $first['terminally_classified'] && 1 === $first['next_offset'] && ! is_dir($root . '/fixture'), 'a non-final page must classify only its linked record and retain the missing primary');
a('terminally_classified' === ($options['datamachine_worktree_metadata']['fixture@linked-a']['primary_restore']['status'] ?? null), 'verified linked record must receive terminal metadata');
$second = $workspace->primary_restore_plan('fixture', 2, 1);
a(is_array($second) && ! $second['has_more'] && 'retained_unverified' === $second['linked'][1]['classification'], 'second bounded page must retain unverified linked rows');
$applied = $workspace->primary_restore_apply($second);
a(is_array($applied) && is_dir($root . '/fixture/.git') && array('fixture@linked-b') === $applied['terminally_classified'] && array('fixture@retained') === $applied['retained_unverified'], 'final page must use DMC-managed registered-remote reconstruction and preserve unverified rows');
$again = $workspace->primary_restore_apply($second); a(! empty($again['already_restored']), 'rerun after reconstruction must be idempotent');
r($root); r($source);
mkdir($root, 0700, true); file_put_contents($root . '/fixture', 'unsafe');
$unsafe = (new Workspace())->primary_restore_plan('fixture'); a(is_wp_error($unsafe) && 'primary_restore_path_unsafe' === $unsafe->get_error_code(), 'an occupied non-Git primary target must veto restore');
unlink($root . '/fixture'); rmdir($root);
fwrite(STDOUT, "primary-restore-plan-apply: ok\n");
