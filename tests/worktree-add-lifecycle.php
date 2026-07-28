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

$GLOBALS['datamachine_code_test_filters'] = array();
function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	$callback = $GLOBALS['datamachine_code_test_filters'][ $hook_name ] ?? null;
	if ( is_callable($callback) ) {
		return $callback($value, ...$args);
	}

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
	public bool $sqlite = false;
	public bool $busy_replace = false;
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

	public function db_server_info(): string {
		return $this->sqlite ? 'SQLite 3' : 'MySQL 8.4';
	}

	public function replace( string $table, array $data ): int|false {
		if ( $this->busy_replace ) {
			$this->last_error = 'database is locked';
			return false;
		}
		if ( $this->fail_replace ) {
			$this->last_error = 'constraint failed for token=ghp_abcdefghijklmnop and ' . str_repeat('x', 600);
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
require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

use DataMachineCode\Abilities\WorkspaceAbilities;
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
	$source_path = $workspace_root . '/source';
	$primary_path = $workspace_root . '/homeboy';
	run_command('git checkout -b stale-rebase-demand', $source_path);
	file_put_contents($source_path . '/stale.txt', "stale\n");
	run_command('git add stale.txt && git commit -m stale && git push -u origin stale-rebase-demand', $source_path);
	run_command('git fetch origin && git checkout -b stale-rebase-demand origin/stale-rebase-demand && git checkout main', $primary_path);
	mkdir($source_path . '/upstream-package', 0777, true);
	file_put_contents($source_path . '/upstream-package/composer.lock', '{}');
	run_command('git add upstream-package/composer.lock && git commit -m upstream-dependency && git push', $source_path);
	$rebased_admission = $workspace->worktree_add('homeboy', 'stale-rebase-demand', null, false, true, false, true, true);
	assert_true(! is_wp_error($rebased_admission), is_wp_error($rebased_admission) ? $rebased_admission->get_error_message() : 'stale branch rebase admission failed');
	assert_true(true === ( $rebased_admission['rebase_succeeded'] ?? false ), 'stale branch was not rebased onto its advanced upstream');
	assert_true(1 === ( $rebased_admission['post_rebase_disk_budget']['demand_plan']['counts']['composer_roots'] ?? 0 ), 'post-rebase admission did not reserve the dependency root introduced only by upstream');
	assert_true('post_materialization_target_tree_conservative' === ( $rebased_admission['post_rebase_disk_budget']['demand_source'] ?? '' ), 'post-rebase admission did not report its effective target-tree demand source');
	run_command(
		'git clone ' . escapeshellarg($workspace_root . '/origin.git') . ' ' . escapeshellarg($workspace_root . '/homeboy@custom-provider-auth-live')
	);
	run_command(
		'git worktree add -b issue/242-embedding-generation ' . escapeshellarg($workspace_root . '/homeboy@address-darren-embedding-review') . ' origin/main',
		$workspace_root . '/homeboy@custom-provider-auth-live'
	);
	$canonical_targeted = $workspace->worktree_list(
		null,
		null,
		array(
			'handle'         => 'homeboy@address-darren-embedding-review',
			'include_status' => true,
			'include_disk'   => false,
		)
	);
	assert_true(1 === count($canonical_targeted['worktrees'] ?? array()), 'canonical worktree handle did not resolve when its directory slug differs from the Git branch');
	assert_true('homeboy@address-darren-embedding-review' === ( $canonical_targeted['worktrees'][0]['handle'] ?? '' ), 'canonical worktree lookup returned the wrong handle');
	assert_true('issue/242-embedding-generation' === ( $canonical_targeted['worktrees'][0]['branch'] ?? '' ), 'canonical worktree lookup did not preserve the Git branch');
	assert_true(null !== ( $canonical_targeted['worktrees'][0]['dirty'] ?? null ), 'canonical worktree lookup did not run the requested status probe');

	// A GitHub API workspace registers only this identity. Materialization must
	// use the normal local lifecycle so the resulting handle is resolver-ready.
	$materialized = $workspace->materialize_remote_workspace(
		array(
			'handle'    => 'homeboy@feat-remote-materialization',
			'repo_name' => 'homeboy',
			'repo'      => 'owner/homeboy',
			'url'       => $workspace_root . '/origin.git',
			'branch'    => 'feat/remote-materialization',
			'base_ref'  => 'origin/main',
			'task'      => array( 'task_url' => 'https://example.test/issues/255' ),
		),
		array(
			'inject_context'       => false,
			'bootstrap'            => false,
			'force'                => true,
			'require_task_tracker' => true,
		)
	);
	assert_true(! is_wp_error($materialized), is_wp_error($materialized) ? $materialized->get_error_message() : 'remote workspace materialization failed');
	assert_true($workspace_root . '/homeboy@feat-remote-materialization' === ( $materialized['path'] ?? '' ), 'materialized workspace returned an unexpected path');
	assert_true(is_file($workspace_root . '/homeboy@feat-remote-materialization/.git'), 'materialized workspace did not create a local worktree');
	assert_true('https://example.test/issues/255' === ( $wpdb->rows['homeboy@feat-remote-materialization']['task_url'] ?? '' ), 'materialization did not preserve remote task metadata');
	$materialized_targeted = $workspace->worktree_list(null, null, array( 'handle' => 'homeboy@feat-remote-materialization', 'include_status' => false, 'include_disk' => false ));
	assert_true(1 === count($materialized_targeted['worktrees'] ?? array()), 'materialized workspace is not discoverable by targeted worktree lookup');
	assert_true($workspace_root . '/homeboy@feat-remote-materialization' === ( $materialized_targeted['worktrees'][0]['path'] ?? '' ), 'targeted lookup did not return the materialized local path');
	$GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] = static function ( array $thresholds ) use ( $workspace_root ): array {
		$free = disk_free_space($workspace_root);
		assert_true(false !== $free, 'fixture workspace free space is not measurable');
		$thresholds['refuse_free_bytes']   = (int) $free + 1;
		$thresholds['warn_free_bytes']     = (int) $free + 1;
		$thresholds['refuse_free_percent'] = 0.0;
		$thresholds['warn_free_percent']   = 0.0;
		return $thresholds;
	};
	$refused = $workspace->worktree_add('homeboy', 'audit-primitives-disk-refused', 'origin/main', false, false, false, false, false);
	unset($GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds']);
	assert_true(is_wp_error($refused), 'disk pressure below the hard floor reported success');
	assert_true('worktree_disk_budget_exceeded' === $refused->get_error_code(), 'unexpected disk pressure refusal error code');
	$refusal_data = (array) $refused->get_error_data();
	$disk_budget  = (array) ( $refusal_data['disk_budget'] ?? array() );
	assert_true('refused' === ( $disk_budget['status'] ?? '' ), 'disk pressure refusal did not include refused budget status');
	assert_true(isset($disk_budget['free_bytes'], $disk_budget['effective_refuse_bytes']), 'disk pressure refusal must include exact free and required bytes');
	assert_true(str_contains($refused->get_error_message(), 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25'), 'disk pressure refusal must include the next cleanup command');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-disk-refused'), 'disk pressure refusal left a worktree directory behind');

	$ability_default = WorkspaceAbilities::worktreeAdd(
		array(
			'repo'            => 'homeboy',
			'branch'          => 'ability-default-tracker-required',
			'from'            => 'origin/main',
			'inject_context'  => false,
			'bootstrap'       => false,
			'force'           => true,
		)
	);
	assert_true(is_wp_error($ability_default), 'agent-facing worktree ability accepted missing tracker metadata by default');
	assert_true('worktree_task_tracker_required' === $ability_default->get_error_code(), 'agent-facing worktree ability returned an unexpected error code');
	assert_true(! is_dir($workspace_root . '/homeboy@ability-default-tracker-required'), 'agent-facing tracker refusal left a worktree directory behind');

	$ability_operator_local = WorkspaceAbilities::worktreeAdd(
		array(
			'repo'                 => 'homeboy',
			'branch'               => 'ability-operator-local',
			'from'                 => 'origin/main',
			'inject_context'       => false,
			'bootstrap'            => false,
			'force'                => true,
			'require_task_tracker' => false,
		)
	);
	assert_true(! is_wp_error($ability_operator_local), is_wp_error($ability_operator_local) ? $ability_operator_local->get_error_message() : 'explicit operator-local ability creation failed');
	assert_true(is_dir($workspace_root . '/homeboy@ability-operator-local'), 'explicit operator-local ability creation did not materialize a worktree');

	$strict_missing = $workspace->worktree_add('homeboy', 'audit-primitives-tracker-required', 'origin/main', false, false, false, false, true, array(), false, true);
	assert_true(is_wp_error($strict_missing), 'strict worktree creation accepted missing tracker metadata');
	assert_true('worktree_task_tracker_required' === $strict_missing->get_error_code(), 'strict worktree creation returned an unexpected error code');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-tracker-required'), 'strict tracker refusal left a worktree directory behind');

	putenv('DATAMACHINE_TASK_URL=https://example.test/issues/environment');
	$result    = $workspace->worktree_add('homeboy', 'audit-primitives-20260616', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/explicit' ), false, true);
	assert_true(! is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'worktree_add failed');
	assert_true(is_dir($result['path']), 'successful worktree_add path is not accessible');
	assert_true(isset($wpdb->rows['homeboy@audit-primitives-20260616']), 'successful worktree_add was not persisted');
	assert_true('refused' !== ( $result['disk_budget']['status'] ?? '' ), 'normal worktree_add should pass the disk budget gate without hard refusal');
	$capacity_locks = array_values(
		array_filter(
			$wpdb->lock_rows,
			static fn( array $row ): bool => 'workspace-capacity-admission' === ( $row['scope'] ?? '' )
		)
	);
	assert_true(array() !== $capacity_locks, 'worktree admission did not acquire the workspace-wide capacity lock');
	assert_true('released' === ( $capacity_locks[count($capacity_locks) - 1]['status'] ?? '' ), 'workspace capacity lock was not released after creation and bootstrap boundary');
	assert_true('https://example.test/issues/explicit' === ( $wpdb->rows['homeboy@audit-primitives-20260616']['task_url'] ?? '' ), 'explicit tracker metadata did not override the environment fallback');
	run_command('git push -u origin audit-primitives-20260616', $result['path']);

	$environment_tracker = $workspace->worktree_add('homeboy', 'audit-primitives-environment-tracker', 'origin/main', false, false, false, false, true, array(), false, true);
	assert_true(! is_wp_error($environment_tracker), is_wp_error($environment_tracker) ? $environment_tracker->get_error_message() : 'environment tracker fallback failed');
	assert_true('https://example.test/issues/environment' === ( $wpdb->rows['homeboy@audit-primitives-environment-tracker']['task_url'] ?? '' ), 'environment tracker metadata was not persisted');
	putenv('DATAMACHINE_TASK_URL');

	$handle = 'homeboy@audit-primitives-20260616';

	file_put_contents($result['path'] . '/untracked.txt', "untracked\n");
	$untracked_finalization = $workspace->worktree_finalize($handle, 'merged');
	assert_true(is_wp_error($untracked_finalization), 'untracked worktree finalization reported success');
	assert_true('worktree_dirty' === $untracked_finalization->get_error_code(), 'untracked finalization did not return worktree_dirty');
	assert_true(1 === ( $untracked_finalization->get_error_data()['dirty_count'] ?? 0 ), 'untracked finalization did not report the dirty count');
	assert_true(in_array('?? untracked.txt', $untracked_finalization->get_error_data()['dirty_paths'] ?? array(), true), 'untracked finalization did not report the dirty path');
	assert_true('active' === ( $wpdb->rows[$handle]['lifecycle_state'] ?? '' ), 'dirty terminal finalization mutated lifecycle metadata');
	$active_update = $workspace->worktree_finalize($handle, 'active');
	assert_true(! is_wp_error($active_update), 'dirty active lifecycle update must remain permissive');
	unlink($result['path'] . '/untracked.txt');

	file_put_contents($result['path'] . '/README.md', "unstaged\n");
	$unstaged_finalization = $workspace->worktree_finalize($handle, 'closed');
	assert_true(is_wp_error($unstaged_finalization) && 'worktree_dirty' === $unstaged_finalization->get_error_code(), 'unstaged worktree finalization did not fail closed');
	run_command('git checkout -- README.md', $result['path']);

	file_put_contents($result['path'] . '/README.md', "staged\n");
	run_command('git add README.md', $result['path']);
	$staged_finalization = $workspace->worktree_finalize($handle, 'cleanup_eligible');
	assert_true(is_wp_error($staged_finalization) && 'worktree_dirty' === $staged_finalization->get_error_code(), 'staged worktree finalization did not fail closed');
	assert_true('active' === ( $wpdb->rows[$handle]['lifecycle_state'] ?? '' ), 'staged terminal finalization mutated lifecycle metadata');
	run_command('git reset --hard HEAD', $result['path']);

	$clean_finalization = $workspace->worktree_finalize($handle, 'merged');
	assert_true(! is_wp_error($clean_finalization), 'clean terminal worktree finalization failed');
	assert_true('cleanup_eligible' === ( $clean_finalization['lifecycle_state'] ?? '' ), 'clean terminal finalization did not expose cleanup eligibility');

	$show = $workspace->show_repo('homeboy@audit-primitives-20260616');
	assert_true(! is_wp_error($show), 'persisted worktree is not visible to show_repo');
	assert_true(0 < $wpdb->get_row_calls, 'persisted worktree metadata did not use direct inventory lookup');

	$list    = $workspace->worktree_list('homeboy', null, array( 'include_status' => false, 'include_disk' => false ));
	$handles = array_map(static fn( array $row ): string => (string) $row['handle'], $list['worktrees'] ?? array());
	assert_true(in_array('homeboy@audit-primitives-20260616', $handles, true), 'persisted worktree is not visible to worktree_list');
	$targeted = $workspace->worktree_list(
		null,
		null,
		array(
			'handle'         => 'homeboy@audit-primitives-20260616',
			'include_status' => true,
			'include_disk'   => false,
		)
	);
	assert_true(1 === count($targeted['worktrees'] ?? array()), 'targeted worktree lookup returned unrelated rows');
	assert_true('homeboy@audit-primitives-20260616' === ( $targeted['worktrees'][0]['handle'] ?? '' ), 'targeted worktree lookup returned the wrong handle');
	assert_true(null !== ( $targeted['worktrees'][0]['dirty'] ?? null ), 'targeted worktree lookup did not run the requested status probe');
	$missing_target = $workspace->worktree_list(null, null, array( 'handle' => 'homeboy@missing', 'include_status' => true, 'include_disk' => false ));
	assert_true(! is_wp_error($missing_target) && array() === ( $missing_target['worktrees'] ?? null ), 'targeted worktree list changed its missing-handle empty-list contract');
	$targeted_default = $workspace->worktree_get($handle);
	assert_true(! is_wp_error($targeted_default), 'default targeted worktree_get failed');
	assert_true(in_array('disk', $targeted_default['fields_skipped'] ?? array(), true), 'default targeted worktree_get ran an unbounded disk probe');

	// A targeted lookup must stay bounded by the requested checkout, even when
	// the workspace contains many unrelated broken primary markers.
	for ( $index = 0; $index < 300; ++$index ) {
		$unrelated = $workspace_root . '/unrelated-' . $index;
		mkdir($unrelated, 0777, true);
		file_put_contents($unrelated . '/.git', 'gitdir: /missing/' . $index);
	}
	$started       = microtime(true);
	$targeted_large = $workspace->worktree_get($handle, array( 'include_status' => true, 'include_disk' => false ));
	$elapsed       = microtime(true) - $started;
	assert_true(! is_wp_error($targeted_large), 'targeted worktree_get failed in a large workspace fixture');
	assert_true(1 === count($targeted_large['worktrees'] ?? array()), 'targeted worktree_get returned unrelated worktrees');
	assert_true($elapsed < 3.0, sprintf('targeted worktree_get scanned unrelated workspace entries: %.3fs', $elapsed));

	// Every targeted Git probe has a finite deadline and identifies the phase
	// that blocked without consulting another checkout.
	$fake_bin = $workspace_root . '/fake-bin';
	mkdir($fake_bin, 0777, true);
	file_put_contents($fake_bin . '/git', "#!/bin/sh\nsleep 10\n");
	chmod($fake_bin . '/git', 0755);
	$original_path = getenv('PATH');
	putenv('PATH=' . $fake_bin . ':' . ( false === $original_path ? '' : $original_path ));
	$started       = microtime(true);
	$timed_out     = $workspace->worktree_get($handle, array( 'include_status' => true, 'include_disk' => false ));
	$elapsed       = microtime(true) - $started;
	putenv('PATH=' . ( false === $original_path ? '' : $original_path ));
	assert_true(is_wp_error($timed_out), 'stalled targeted Git probe did not fail');
	assert_true('worktree_get_identity_probe_failed' === $timed_out->get_error_code(), 'stalled targeted Git probe did not identify its phase');
	assert_true(5 === ( $timed_out->get_error_data()['timeout_seconds'] ?? null ), 'stalled targeted Git probe did not report its deadline');
	assert_true($elapsed < 7.0, sprintf('stalled targeted Git probe exceeded its deadline: %.3fs', $elapsed));

	// Inventory contention after the option metadata write reports a retry-safe
	// inventory phase and preserves the committed lifecycle state.
	$wpdb->fail_replace = true;
	$inventory_failure  = $workspace->worktree_finalize($handle, 'pr_opened', 'https://github.com/Extra-Chill/data-machine-code/pull/964');
	$wpdb->fail_replace = false;
	assert_true(is_wp_error($inventory_failure), 'inventory persistence failure reported finalization success');
	assert_true('worktree_finalize_inventory_upsert_failed' === $inventory_failure->get_error_code(), 'inventory failure did not identify the inventory upsert phase');
	assert_true(true === ( $inventory_failure->get_error_data()['lifecycle_metadata_committed'] ?? false ), 'inventory failure did not report committed lifecycle metadata');
	$retry = $workspace->worktree_finalize($handle, 'pr_opened', 'https://github.com/Extra-Chill/data-machine-code/pull/964');
	assert_true(! is_wp_error($retry), 'idempotent finalization retry failed after inventory persistence recovered');
	assert_true('https://github.com/Extra-Chill/data-machine-code/pull/964' === ( $retry['metadata']['pr_url'] ?? '' ), 'finalization retry did not read back PR lifecycle metadata');

	update_option(
		'datamachine_code_remote_workspace_state',
		array(
			'repos' => array(
				'other-repo' => array( 'repo' => 'owner/other-repo' ),
			),
		)
	);
	$removed = WorkspaceAbilities::worktreeRemove(
		array(
			'repo'   => 'homeboy',
			'branch' => 'audit-primitives-20260616',
			'force'  => true,
		)
	);
	assert_true(! is_wp_error($removed), is_wp_error($removed) ? $removed->get_error_message() : 'worktreeRemove failed');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-20260616'), 'local worktree remove did not remove the fixture path');
	assert_true(! isset($wpdb->rows['homeboy@audit-primitives-20260616']), 'local worktree remove did not delete inventory row');

	$failure_wpdb = new Datamachine_Code_Test_Wpdb();
	$failure_wpdb->fail_replace = true;
	$GLOBALS['wpdb'] = $failure_wpdb;
	$failed = $workspace->worktree_add('homeboy', 'audit-primitives-persist-fails', 'origin/main', false, false, false, false, true);
	assert_true(is_wp_error($failed), 'inventory persistence failure reported success');
	assert_true('worktree_inventory_persist_failed' === $failed->get_error_code(), 'unexpected persistence failure error code');
	$failure_data = (array) $failed->get_error_data();
	assert_true('worktree_inventory_upsert' === ( $failure_data['operation'] ?? '' ), 'inventory persistence failure did not identify the failed operation');
	assert_true('database' === ( $failure_data['backend'] ?? '' ), 'inventory persistence failure did not identify the database backend');
	assert_true(! str_contains((string) ( $failure_data['database_error'] ?? '' ), 'ghp_abcdefghijklmnop'), 'inventory persistence failure exposed a secret-like database detail');
	assert_true(strlen((string) ( $failure_data['database_error'] ?? '' )) <= 512, 'inventory persistence failure did not bound database details');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-persist-fails'), 'failed persistence left a worktree directory behind');

	$contention_wpdb = new Datamachine_Code_Test_Wpdb();
	$contention_wpdb->sqlite = true;
	$contention_wpdb->busy_replace = true;
	$GLOBALS['wpdb'] = $contention_wpdb;
	$GLOBALS['datamachine_code_test_filters']['datamachine_code_sqlite_busy_retry_max_wait_ms'] = static fn(): int => 1;
	$contention = $workspace->worktree_add('homeboy', 'audit-primitives-sqlite-locked', 'origin/main', false, false, false, false, true);
	unset($GLOBALS['datamachine_code_test_filters']['datamachine_code_sqlite_busy_retry_max_wait_ms']);
	assert_true(is_wp_error($contention), 'SQLite contention reported success');
	assert_true('workspace_sqlite_lock_contention' === $contention->get_error_code(), 'SQLite contention did not return the structured error');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-sqlite-locked'), 'SQLite contention left a partial Git worktree behind');

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
