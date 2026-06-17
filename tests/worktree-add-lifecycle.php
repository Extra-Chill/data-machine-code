<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

$temp_root      = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$workspace_root = rtrim($temp_root, '/') . '/datamachine-code-worktree-add-' . getmypid();
if ( ! defined('DATAMACHINE_WORKSPACE_PATH') ) {
	define('DATAMACHINE_WORKSPACE_PATH', $workspace_root);
}

final class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate('Y-m-d H:i:s');
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode($value, $flags, $depth);
}

$GLOBALS['datamachine_code_test_options'] = array();
function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['datamachine_code_test_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['datamachine_code_test_options'][ $name ] = $value;
	return true;
}

function home_url(): string {
	return 'https://example.test';
}

function get_bloginfo( string $show = '' ): string {
	return 'DMC Test';
}

function dbDelta( string $sql ): array {
	return array();
}

final class Datamachine_Code_Test_Wpdb {
	public string $prefix = 'wp_';
	public bool $fail_replace = false;
	public string $last_error = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $get_row_calls = 0;

	/** @var array<string,array<string,mixed>> */
	public array $rows = array();

	/** @var array<int,array<string,mixed>> */
	public array $lock_rows = array();

	public function get_charset_collate(): string {
		return '';
	}

	public function replace( string $table, array $data ): int|false {
		if ( $this->fail_replace ) {
			return false;
		}

		$this->rows[ (string) $data['handle'] ] = $data;
		$this->rows_affected = 1;
		return 1;
	}

	public function insert( string $table, array $data, array $format = array() ): int|false {
		++$this->insert_id;
		$data['id'] = $this->insert_id;
		$this->lock_rows[ $this->insert_id ] = $data;
		$this->rows_affected = 1;
		return 1;
	}

	public function delete( string $table, array $where ): int|false {
		unset($this->rows[ (string) ( $where['handle'] ?? '' ) ]);
		return 1;
	}

	public function update( string $table, array $data, array $where ): int|false {
		$handle = (string) ( $where['handle'] ?? '' );
		if ( isset($this->rows[ $handle ]) ) {
			$this->rows[ $handle ] = array_merge($this->rows[ $handle ], $data);
		}
		if ( isset($where['id'], $this->lock_rows[ (int) $where['id'] ]) ) {
			$this->lock_rows[ (int) $where['id'] ] = array_merge($this->lock_rows[ (int) $where['id'] ], $data);
		}
		$this->rows_affected = 1;
		return 1;
	}

	public function get_results( string $sql, string $output = ARRAY_A ): array {
		return array_values($this->rows);
	}

	public function get_row( string $sql, string $output = ARRAY_A ): ?array {
		++$this->get_row_calls;
		foreach ( $this->rows as $handle => $row ) {
			if ( str_contains($sql, (string) $handle) ) {
				return $row;
			}
		}

		return null;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace('/%s/', addslashes((string) $arg), $query, 1) ?? $query;
		}
		return $query;
	}

	public function query( string $sql ): int|false {
		$this->rows_affected = 0;
		return 1;
	}

	public function get_var( string $sql ): string|int|null {
		if ( str_contains($sql, 'SHOW TABLES LIKE') ) {
			return str_contains($sql, 'datamachine_code_locks') ? $this->prefix . 'datamachine_code_locks' : $this->prefix . 'datamachine_code_worktrees';
		}
		return 0;
	}

	public function get_col( string $sql ): array {
		return array();
	}
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';

use DataMachineCode\Workspace\Workspace;

function run_command( string $command, ?string $cwd = null ): string {
	$prefix = null === $cwd ? '' : 'cd ' . escapeshellarg($cwd) . ' && ';
	$output = array();
	$code   = 0;
	exec($prefix . $command . ' 2>&1', $output, $code);
	if ( 0 !== $code ) {
		throw new RuntimeException(sprintf("Command failed (%d): %s\n%s", $code, $command, implode("\n", $output)));
	}
	return implode("\n", $output);
}

function remove_tree( string $path ): void {
	if ( ! file_exists($path) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function create_primary_checkout( string $workspace_root ): void {
	$source = $workspace_root . '/source';
	$origin = $workspace_root . '/origin.git';
	mkdir($workspace_root, 0777, true);
	mkdir($source, 0777, true);
	run_command('git init -b main', $source);
	run_command('git config user.email test@example.test', $source);
	run_command('git config user.name "DMC Test"', $source);
	file_put_contents($source . '/README.md', "fixture\n");
	run_command('git add README.md', $source);
	run_command('git commit -m initial', $source);
	run_command('git init --bare ' . escapeshellarg($origin));
	run_command('git remote add origin ' . escapeshellarg($origin), $source);
	run_command('git push -u origin main', $source);
	run_command('git clone ' . escapeshellarg($origin) . ' ' . escapeshellarg($workspace_root . '/homeboy'));
	run_command('git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main', $workspace_root . '/homeboy');
}

remove_tree($workspace_root);

try {
	create_primary_checkout($workspace_root);
	$wpdb = new Datamachine_Code_Test_Wpdb();
	$GLOBALS['wpdb'] = $wpdb;

	$workspace = new Workspace();
	$result    = $workspace->worktree_add('homeboy', 'audit-primitives-20260616', 'origin/main', false, false, false, false, true);
	assert_true(! is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'worktree_add failed');
	assert_true(is_dir($result['path']), 'successful worktree_add path is not accessible');
	assert_true(isset($wpdb->rows['homeboy@audit-primitives-20260616']), 'successful worktree_add was not persisted');

	$show = $workspace->show_repo('homeboy@audit-primitives-20260616');
	assert_true(! is_wp_error($show), 'persisted worktree is not visible to show_repo');
	assert_true(0 < $wpdb->get_row_calls, 'persisted worktree metadata did not use direct inventory lookup');

	$list    = $workspace->worktree_list('homeboy', null, array( 'include_status' => false, 'include_disk' => false ));
	$handles = array_map(static fn( array $row ): string => (string) $row['handle'], $list['worktrees'] ?? array());
	assert_true(in_array('homeboy@audit-primitives-20260616', $handles, true), 'persisted worktree is not visible to worktree_list');

	$failure_wpdb = new Datamachine_Code_Test_Wpdb();
	$failure_wpdb->fail_replace = true;
	$GLOBALS['wpdb'] = $failure_wpdb;
	$failed = $workspace->worktree_add('homeboy', 'audit-primitives-persist-fails', 'origin/main', false, false, false, false, true);
	assert_true(is_wp_error($failed), 'inventory persistence failure reported success');
	assert_true('worktree_inventory_persist_failed' === $failed->get_error_code(), 'unexpected persistence failure error code');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-persist-fails'), 'failed persistence left a worktree directory behind');

	$GLOBALS['wpdb'] = new Datamachine_Code_Test_Wpdb();
	run_command('git remote set-url origin ' . escapeshellarg($workspace_root . '/missing-origin.git'), $workspace_root . '/homeboy');
	$fetch_failed_default = $workspace->worktree_add('homeboy', 'audit-primitives-fetch-fails', 'origin/main', false, false, false, false, true);
	assert_true(is_wp_error($fetch_failed_default), 'fetch failure reported success without explicit opt-in');
	assert_true('worktree_freshness_unverified' === $fetch_failed_default->get_error_code(), 'unexpected fetch failure error code');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-fetch-fails'), 'fetch failure left a worktree directory behind');

	$fetch_failed_allowed = $workspace->worktree_add('homeboy', 'audit-primitives-fetch-fails-allowed', 'origin/main', false, false, false, false, true, array(), true);
	assert_true(! is_wp_error($fetch_failed_allowed), is_wp_error($fetch_failed_allowed) ? $fetch_failed_allowed->get_error_message() : 'fetch failure opt-in failed');
	assert_true(! empty($fetch_failed_allowed['fetch_failed']), 'fetch failure opt-in did not surface fetch_failed');
	assert_true(is_dir($fetch_failed_allowed['path']), 'fetch failure opt-in worktree path is not accessible');

	remove_tree($workspace_root);
	fwrite(STDOUT, "worktree-add-lifecycle ok\n");
} catch (Throwable $e) {
	remove_tree($workspace_root);
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
