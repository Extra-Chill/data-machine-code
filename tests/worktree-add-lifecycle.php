<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once __DIR__ . '/worktree-lifecycle-fixture-guard-support.php';

$temp_root      = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$workspace_root = rtrim($temp_root, '/') . '/datamachine-code-worktree-add-' . getmypid() . '-' . bin2hex(random_bytes(8));
if ( defined('DATAMACHINE_WORKSPACE_PATH') && rtrim((string) DATAMACHINE_WORKSPACE_PATH, '/') !== $workspace_root ) {
	throw new RuntimeException('worktree-add-lifecycle refuses a predefined DATAMACHINE_WORKSPACE_PATH that is not its owned fixture.');
}
define('DATAMACHINE_WORKSPACE_PATH', $workspace_root);

if ( ! mkdir($workspace_root, 0700) ) {
	throw new RuntimeException('worktree-add-lifecycle could not create its owned fixture.');
}
$fixture_identity = realpath($workspace_root);
if ( false === $fixture_identity || $workspace_root !== $fixture_identity || worktree_lifecycle_fixture_has_symlink_component($workspace_root) ) {
	worktree_lifecycle_dispose_incomplete_fixture($workspace_root, is_string($fixture_identity) ? $fixture_identity : '', '');
	throw new RuntimeException('worktree-add-lifecycle could not establish a canonical non-symlink fixture identity.');
}
$fixture_sentinel = $workspace_root . '/.datamachine-code-worktree-add-fixture';
$fixture_token    = bin2hex(random_bytes(16));
if ( false === file_put_contents($fixture_sentinel, $fixture_token . "\n") || false === realpath($fixture_sentinel) || $fixture_sentinel !== realpath($fixture_sentinel) || is_link($fixture_sentinel) ) {
	worktree_lifecycle_dispose_incomplete_fixture($workspace_root, $fixture_identity, $fixture_sentinel);
	throw new RuntimeException('worktree-add-lifecycle could not establish its fixture sentinel.');
}
$fixture = array(
	'root'              => $workspace_root,
	'identity'          => $fixture_identity,
	'sentinel'          => $fixture_sentinel,
	'sentinel_identity' => $fixture_sentinel,
	'token'             => $fixture_token,
);

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

function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
	return str_repeat('a', $length);
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

try {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';
	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';
} catch (Throwable $e) {
	remove_tree($workspace_root, $fixture);
	fwrite(STDERR, "worktree-add-lifecycle bootstrap failure cleaned fixture {$workspace_root}\n");
	throw $e;
}

use DataMachineCode\Abilities\WorkspaceAbilities;
use DataMachineCode\Workspace\RemoteWorkspaceBackend;
use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeContextInjector;

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

function remove_tree( string $path, array $fixture ): void {
	worktree_lifecycle_assert_fixture_cleanup_safe($path, $fixture);

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($fixture['identity'], FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($fixture['identity']);
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function interrupted_creation_intent( string $branch, string $base_head, array $task ): array {
	return array(
		'repo'           => 'homeboy',
		'branch'         => $branch,
		'base_ref'       => 'origin/main',
		'base_head'      => $base_head,
		'task'           => $task,
		'inject_context' => false,
		'bootstrap'      => false,
		'intent'         => array(),
	);
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

try {
	$workspace = new Workspace();
	assert_true($workspace_root === $workspace->get_path(), 'worktree-add-lifecycle resolved a workspace outside its owned fixture before lifecycle operations.');

	create_primary_checkout($workspace_root);
	$wpdb = new Datamachine_Code_Test_Wpdb();
	$GLOBALS['wpdb'] = $wpdb;

	$source_path = $workspace_root . '/source';
	$primary_path = $workspace_root . '/homeboy';
	$create_plan = $workspace->worktree_plan('homeboy', 'planned-create', 'origin/main', false, false, false, false, false, array( 'task_url' => 'https://example.test/issues/planned-create' ));
	assert_true(! is_wp_error($create_plan) && 'create' === ( $create_plan['disposition'] ?? null ) && ! empty($create_plan['digest']) && 'homeboy@planned-create' === ( $create_plan['handle'] ?? null ) && ! is_dir($workspace_root . '/homeboy@planned-create'), 'create plan was not a non-mutating typed allocation');
	$applied_plan = $workspace->worktree_apply_plan($create_plan);
	assert_true(! is_wp_error($applied_plan) && is_dir($workspace_root . '/homeboy@planned-create'), is_wp_error($applied_plan) ? $applied_plan->get_error_message() : 'unchanged create plan did not apply');
	$capacity_planner = new class extends Workspace {
		protected function inspect_worktree_capacity( string $repo, string $branch, bool $force, array $demand_plan ): array {
			return array( 'status' => 'refused', 'demand_plan' => $demand_plan );
		}
	};
	$capacity_plan = $capacity_planner->worktree_plan('homeboy', 'planned-capacity', 'origin/main', false, false, false, false, false, array( 'task_url' => 'https://example.test/issues/planned-capacity' ));
	assert_true(! is_wp_error($capacity_plan) && 'capacity_blocked' === ( $capacity_plan['disposition'] ?? null ), 'capacity-blocked plan was not deterministic');
	$stale_plan = $workspace->worktree_plan('homeboy', 'planned-stale', 'origin/main', false, false, false, false, false, array( 'task_url' => 'https://example.test/issues/planned-stale' ));
	file_put_contents($source_path . '/plan-remote-change.txt', "changed\n");
	run_command('git add plan-remote-change.txt && git commit -m plan-remote-change && git push', $source_path);
	$stale_apply = $workspace->worktree_apply_plan($stale_plan);
	assert_true(is_wp_error($stale_apply) && 'stale_worktree_plan' === $stale_apply->get_error_code() && ! is_dir($workspace_root . '/homeboy@planned-stale'), 'remote change did not fail closed as a stale plan');
	$remote_backend = new RemoteWorkspaceBackend();
	$public_remote = $remote_backend->clone_repo('https://github.com/example/public-project.git', 'public-project');
	assert_true(! is_wp_error($public_remote), 'public GitHub remote registration failed');
	$ghe_remote = $remote_backend->clone_repo('git@github.example.com:example/enterprise-project.git', 'enterprise-project');
	assert_true(! is_wp_error($ghe_remote), 'GitHub Enterprise remote registration failed');
	$ghe_context = $remote_backend->materialization_context('enterprise-project');
	assert_true(! is_wp_error($ghe_context), 'GitHub Enterprise registration was not materializable');
	assert_true('git@github.example.com:example/enterprise-project.git' === ( $ghe_context['url'] ?? '' ), 'GitHub Enterprise materialization did not preserve its clone URL');

	// Existing API-backed state must not divert a capable local runtime away
	// from creating the primary checkout requested by workspace clone.
	$clone_origin = $workspace_root . '/source/clone-origin.git';
	run_command('git clone --bare ' . escapeshellarg($workspace_root . '/origin.git') . ' ' . escapeshellarg($clone_origin));
	$cloned = WorkspaceAbilities::cloneRepo(
		array(
			'url'  => $clone_origin,
			'name' => 'clone-handoff',
		)
	);
	assert_true(! is_wp_error($cloned), is_wp_error($cloned) ? $cloned->get_error_message() : 'local clone was diverted to remote registration');
	assert_true(is_dir($workspace_root . '/clone-handoff/.git'), 'workspace clone did not materialize the local primary');
	$cloned_worktree = $workspace->worktree_add('clone-handoff', 'feat/clone-handoff', 'origin/main', false, false, false, false, true);
	assert_true(! is_wp_error($cloned_worktree), is_wp_error($cloned_worktree) ? $cloned_worktree->get_error_message() : 'worktree add failed from the cloned primary');
	assert_true(is_file($workspace_root . '/clone-handoff@feat-clone-handoff/.git'), 'cloned primary did not support worktree materialization');

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
	$reclaim      = (array) ( $refusal_data['capacity_reclaim'] ?? array() );
	assert_true('refused' === ( $disk_budget['status'] ?? '' ), 'disk pressure refusal did not include refused budget status');
	assert_true(isset($disk_budget['free_bytes'], $disk_budget['effective_refuse_bytes']), 'disk pressure refusal must include exact free and required bytes');
	assert_true(true === ( $reclaim['attempted'] ?? false ), 'capacity refusal did not attempt bounded safe artifact reclaim');
	assert_true('refused_after_reclaim' === ( $reclaim['final_decision'] ?? '' ), 'capacity refusal did not report the final reclaim decision');
	assert_true('no_actionable_rows' === ( $reclaim['actionability_status'] ?? '' ), 'zero-row artifact recovery must expose its non-actionable state');
	assert_true(0 === ( $reclaim['actionable_reclaim_bytes'] ?? null ), 'zero-row artifact recovery must expose zero actionable bytes');
	assert_true(array_key_exists('gross_candidate_bytes', $reclaim), 'zero-row artifact recovery must retain gross candidate bytes separately from actionable reclaim');
	assert_true(str_contains($refused->get_error_message(), 'Automatic safe artifact recovery found 0 actionable rows (0 B)'), 'zero-row recovery must not advertise a cleanup command as reclaimable');
	assert_true(str_contains($refused->get_error_message(), 'retry only this request with --force'), 'zero-row recovery must recommend the bounded worktree exception instead of promising reclaim');
	assert_true(str_contains($refused->get_error_message(), 'workspace worktree capacity-recovery --limit=25 --until-budget=30s --format=json'), 'zero-row recovery must expose the bounded reconcile-and-replan path.');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-disk-refused'), 'disk pressure refusal left a worktree directory behind');
	$dry_run_remote_refs = run_command("git for-each-ref --format='%(refname) %(objectname)' refs/remotes", $primary_path);
	$dry_run_lock_rows   = $wpdb->lock_rows;
	$dry_run_requests    = glob($workspace_root . '/.locks/requests/*.json') ?: array();
	$dry_run_metadata    = $wpdb->rows;
	$GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] = static function ( array $thresholds ): array {
		$thresholds['refuse_free_bytes']   = 1;
		$thresholds['warn_free_bytes']     = 1;
		$thresholds['refuse_free_percent'] = 0.0;
		$thresholds['warn_free_percent']   = 0.0;
		return $thresholds;
	};
	$admitted_dry_run = $workspace->worktree_add('homeboy', 'capacity-remediation-admitted-dry-run', 'origin/main', false, false, false, false, false, array(), false, false, array(), 'reuse_compatible', true, true);
	unset($GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds']);
	assert_true(! is_wp_error($admitted_dry_run), is_wp_error($admitted_dry_run) ? $admitted_dry_run->get_error_message() : 'admitted capacity remediation dry-run failed');
	assert_true(true === ( $admitted_dry_run['dry_run'] ?? false ), 'admitted capacity remediation request did not report dry-run.');
	assert_true(false === ( $admitted_dry_run['created'] ?? true ), 'admitted capacity remediation dry-run created a worktree.');
	assert_true('not_applicable' === ( $admitted_dry_run['handoff_freshness']['status'] ?? null ) && 'non_allocation_dry_run' === ( $admitted_dry_run['handoff_freshness']['reason'] ?? null ), 'capacity remediation dry-run did not report its non-allocation handoff status.');
	assert_true(! is_dir($workspace_root . '/homeboy@capacity-remediation-admitted-dry-run'), 'admitted capacity remediation dry-run materialized a worktree directory.');
	assert_true($dry_run_remote_refs === run_command("git for-each-ref --format='%(refname) %(objectname)' refs/remotes", $primary_path), 'capacity remediation dry-run fetched or changed remote refs.');
	assert_true($dry_run_lock_rows === $wpdb->lock_rows, 'capacity remediation dry-run wrote lock-store lifecycle rows.');
	assert_true($dry_run_requests === ( glob($workspace_root . '/.locks/requests/*.json') ?: array() ), 'capacity remediation dry-run wrote lock request files.');
	assert_true($dry_run_metadata === $wpdb->rows, 'capacity remediation dry-run rewrote lifecycle metadata.');
	$GLOBALS['datamachine_code_test_filters']['datamachine_code_remote_workspace_backend_should_handle'] = static fn(): bool => true;
	$remote_remediation = WorkspaceAbilities::worktreeAdd(
		array(
			'repo'                       => 'remote-only',
			'branch'                     => 'capacity-remediation-remote',
			'inject_context'             => false,
			'bootstrap'                  => false,
			'require_task_tracker'       => false,
			'remediate_capacity'         => true,
			'remediate_capacity_dry_run' => true,
		)
	);
	unset($GLOBALS['datamachine_code_test_filters']['datamachine_code_remote_workspace_backend_should_handle']);
	assert_true(is_wp_error($remote_remediation), 'remote capacity remediation silently created a remote worktree.');
	assert_true('remote_worktree_capacity_remediation_unsupported' === $remote_remediation->get_error_code(), 'remote capacity remediation did not return its explicit unsupported error.');

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
	assert_true(! isset($ability_operator_local['disk_budget']), 'default ability response exposed the full disk-budget model');
	assert_true(isset($ability_operator_local['capacity']['status']), 'default ability response omitted the concise capacity decision');
	assert_true(true === ( $ability_operator_local['evidence']['verbose']['input']['verbose'] ?? null ), 'default ability response omitted its explicit verbose evidence request');

	$strict_missing = $workspace->worktree_add('homeboy', 'audit-primitives-tracker-required', 'origin/main', false, false, false, false, true, array(), false, true);
	assert_true(is_wp_error($strict_missing), 'strict worktree creation accepted missing tracker metadata');
	assert_true('worktree_task_tracker_required' === $strict_missing->get_error_code(), 'strict worktree creation returned an unexpected error code');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-tracker-required'), 'strict tracker refusal left a worktree directory behind');

	putenv('DATAMACHINE_TASK_URL=https://example.test/issues/environment');
	$result    = $workspace->worktree_add('homeboy', 'audit-primitives-20260616', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/explicit' ), false, true);
	assert_true(! is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'worktree_add failed');
	$handoff_freshness = (array) ( $result['handoff_freshness'] ?? array() );
	$handoff_proof = (array) ( $handoff_freshness['proof'] ?? array() );
	assert_true('verified' === ( $handoff_freshness['status'] ?? null ), 'new allocation did not issue a verified handoff freshness contract');
	assert_true('homeboy@audit-primitives-20260616' === ( $handoff_proof['handle'] ?? '' ) && ! empty($handoff_proof['worktree_sha']) && ! empty($handoff_proof['resolved_base_sha']) && ! empty($handoff_proof['resolved_base_ref']) && ! empty($handoff_proof['remote_default_sha']) && ! empty($handoff_proof['remote_default_ref']) && ! empty($handoff_proof['verified_at']), 'new allocation did not issue a complete handoff proof');
	$current_handoff = $workspace->worktree_handoff_revalidate((string) $result['handle'], $handoff_proof);
	assert_true(! is_wp_error($current_handoff) && 'current' === ( $current_handoff['status'] ?? null ), 'fresh handoff proof did not revalidate as current');
	$reordered_handoff = $handoff_proof;
	krsort($reordered_handoff, SORT_STRING);
	$reordered_result = $workspace->worktree_handoff_revalidate((string) $result['handle'], $reordered_handoff);
	assert_true(! is_wp_error($reordered_result) && 'current' === ( $reordered_result['status'] ?? null ), 'semantically identical reordered handoff proof was refused');
	$handoff_lock = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($workspace_root, 'homeboy', 0);
	assert_true(! is_wp_error($handoff_lock), 'handoff contention fixture could not acquire the repository lock');
	try {
		$handoff_contention = $workspace->worktree_handoff_revalidate((string) $result['handle'], $handoff_proof);
	} finally {
		$handoff_lock->release();
	}
	assert_true(! is_wp_error($handoff_contention) && 'contention' === ( $handoff_contention['status'] ?? null ), 'handoff revalidation did not return typed contention');
	$tampered_handoff = $handoff_proof;
	$tampered_handoff['worktree_sha'] = str_repeat('0', 40);
	$tampered_result = $workspace->worktree_handoff_revalidate((string) $result['handle'], $tampered_handoff);
	assert_true(is_wp_error($tampered_result) && 'untrusted_worktree_handoff_proof' === $tampered_result->get_error_code(), 'tampered handoff proof was accepted');
	run_command('git checkout main', $source_path);
	file_put_contents($source_path . '/handoff-remote-advance.txt', "advance\n");
	run_command('git add handoff-remote-advance.txt && git commit -m handoff-remote-advance && git push', $source_path);
	$drifted_handoff = $workspace->worktree_handoff_revalidate((string) $result['handle'], $handoff_proof);
	assert_true(! is_wp_error($drifted_handoff) && 'drift' === ( $drifted_handoff['status'] ?? null ) && isset($drifted_handoff['drift']['remote_default_sha']), 'remote advance did not produce typed handoff drift');
	$replayed_drift = $workspace->worktree_handoff_revalidate((string) $result['handle'], (array) ( $drifted_handoff['proof'] ?? array() ));
	assert_true(is_wp_error($replayed_drift) && 'untrusted_worktree_handoff_proof' === $replayed_drift->get_error_code(), 'a drift response could be replayed as a trusted proof');
	assert_true(is_dir($result['path']), 'successful worktree_add path is not accessible');
	assert_true(isset($wpdb->rows['homeboy@audit-primitives-20260616']), 'successful worktree_add was not persisted');
	assert_true(null === WorktreeContextInjector::get_creation_intent('homeboy@audit-primitives-20260616'), 'successful worktree_add left its pre-creation journal behind');
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

	$reusable = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(! is_wp_error($reusable), is_wp_error($reusable) ? $reusable->get_error_message() : 'reuse fixture creation failed');
	$invalid_reuse_policy = $workspace->worktree_add('homeboy', 'invalid-reuse-policy', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, array(), 'recycle-terminal');
	assert_true(is_wp_error($invalid_reuse_policy) && 'invalid_worktree_reuse_policy' === $invalid_reuse_policy->get_error_code(), 'invalid reuse policy did not fail with a typed error');
	run_command('git remote set-url origin ' . escapeshellarg($workspace_root . '/missing-origin.git'), $primary_path);
	$same_task_refused = $workspace->worktree_add('homeboy', 'same-task-refused', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	run_command('git remote set-url origin ' . escapeshellarg($workspace_root . '/origin.git'), $primary_path);
	assert_true(is_wp_error($same_task_refused) && 'worktree_reuse_refused' === $same_task_refused->get_error_code(), 'default same-task allocation did not fail with a typed reuse refusal before fetch');
	$same_task_evidence = (array) $same_task_refused->get_error_data();
	assert_true('same_task_candidate_requires_explicit_isolation' === ( $same_task_evidence['reuse']['reason_code'] ?? null ), 'same-task refusal did not expose its typed reason code');
	assert_true(array( 'homeboy@idempotent-reuse' ) === array_column((array) ( $same_task_evidence['reuse']['candidates'] ?? array() ), 'handle'), 'same-task refusal did not include deterministic candidate evidence');
	assert_true(! is_dir($workspace_root . '/homeboy@same-task-refused') && '' === trim(run_command('git branch --list same-task-refused', $primary_path)), 'same-task refusal created a worktree path or branch');
	$isolated_without_owner = $workspace->worktree_add('homeboy', 'same-task-ownerless', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, array(), 'isolated');
	assert_true(is_wp_error($isolated_without_owner) && 'same_task_isolation_intent_required' === ( $isolated_without_owner->get_error_data()['reuse']['reason_code'] ?? null ), 'ownerless same-task isolation was not refused');
	assert_true(! is_dir($workspace_root . '/homeboy@same-task-ownerless') && '' === trim(run_command('git branch --list same-task-ownerless', $primary_path)), 'ownerless isolation refusal created a worktree path or branch');
	$isolated_intent = array( 'purpose' => 'parallel-verification', 'owner_run_ref' => 'run-123', 'cleanup_policy' => 'remove_on_success' );
	$candidate_report = $workspace->worktree_add('homeboy', 'same-task-isolated', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, $isolated_intent, 'isolated');
	assert_true(! is_wp_error($candidate_report), is_wp_error($candidate_report) ? $candidate_report->get_error_message() : 'same-task isolated creation failed');
	assert_true('homeboy@same-task-isolated' === ( $candidate_report['handle'] ?? '' ), 'isolated policy adopted a same-task candidate instead of creating the requested handle');
	assert_true(array( 'homeboy@idempotent-reuse' ) === array_column((array) ($candidate_report['reuse_candidates'] ?? array()), 'handle'), 'same-task admission did not report deterministic informational candidates');
	$isolated_owner_retry = $workspace->worktree_add('homeboy', 'same-task-isolated', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, $isolated_intent, 'isolated');
	assert_true(! is_wp_error($isolated_owner_retry) && true === ( $isolated_owner_retry['reused'] ?? false ) && 'owner_identical_live_retry' === ( $isolated_owner_retry['reuse']['reason_code'] ?? null ), is_wp_error($isolated_owner_retry) ? $isolated_owner_retry->get_error_message() : 'same-owner live isolated retry was not accepted');
	$isolated_foreign_owner = $workspace->worktree_add('homeboy', 'same-task-isolated', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, array( 'purpose' => 'parallel-verification', 'owner_run_ref' => 'run-foreign', 'cleanup_policy' => 'remove_on_success' ), 'isolated');
	assert_true(is_wp_error($isolated_foreign_owner) && 'disposable_intent_mismatch' === ( $isolated_foreign_owner->get_error_data()['reuse']['reason_code'] ?? null ), 'foreign owner live isolated retry did not fail closed');
	$candidate_report_second = $workspace->worktree_add('homeboy', 'same-task-second', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, array( 'purpose' => 'parallel-review', 'owner_run_ref' => 'run-456', 'cleanup_policy' => 'remove_on_success' ), 'isolated');
	assert_true(! is_wp_error($candidate_report_second), is_wp_error($candidate_report_second) ? $candidate_report_second->get_error_message() : 'second same-task isolated creation failed');
	assert_true(array( 'homeboy@idempotent-reuse', 'homeboy@same-task-isolated' ) === array_column((array) ($candidate_report_second['reuse_candidates'] ?? array()), 'handle'), 'same-task candidates were not deterministically ordered');
	$recycle_fixture = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-old' ));
	assert_true(! is_wp_error($recycle_fixture), is_wp_error($recycle_fixture) ? $recycle_fixture->get_error_message() : 'terminal recycle fixture creation failed');
	run_command('git push -u origin terminal-recycle', $recycle_fixture['path']);
	$recycle_handle = 'homeboy@terminal-recycle';
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata($recycle_handle, array( 'last_seen_at' => gmdate('c', time() - 90000) ));
	$recycle_finalized = $workspace->worktree_finalize($recycle_handle, 'merged');
	assert_true(! is_wp_error($recycle_finalized), is_wp_error($recycle_finalized) ? $recycle_finalized->get_error_message() : 'terminal recycle fixture finalization failed');
	$dry_recycle_head     = trim(run_command('git rev-parse HEAD', $recycle_fixture['path']));
	$dry_recycle_metadata = $wpdb->rows[$recycle_handle];
	$GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] = static function ( array $thresholds ): array {
		$thresholds['refuse_free_bytes']   = 1;
		$thresholds['warn_free_bytes']     = 1;
		$thresholds['refuse_free_percent'] = 0.0;
		return $thresholds;
	};
	$dry_recycle = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, false, array( 'task_url' => 'https://example.test/issues/recycle-dry-run' ), false, false, array(), 'recycle_terminal', true, true);
	unset($GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds']);
	assert_true(! is_wp_error($dry_recycle) && true === ($dry_recycle['dry_run'] ?? false), 'capacity remediation dry-run did not return its non-mutating preview');
	assert_true($dry_recycle_head === trim(run_command('git rev-parse HEAD', $recycle_fixture['path'])), 'capacity remediation dry-run reset a terminal worktree');
	assert_true($dry_recycle_metadata === $wpdb->rows[$recycle_handle], 'capacity remediation dry-run rewrote terminal worktree metadata');
	$recycle_exclude = trim(run_command('git rev-parse --git-path info/exclude', $recycle_fixture['path']));
	file_put_contents($recycle_exclude, ".recycle-context\nvendor/\n", FILE_APPEND);
	file_put_contents($recycle_fixture['path'] . '/.recycle-context', "preserved context\n");
	mkdir($recycle_fixture['path'] . '/vendor', 0777, true);
	file_put_contents($recycle_fixture['path'] . '/vendor/.recycle-bootstrap-marker', "preserved bootstrap\n");
	$recycled = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-new' ), false, false, array(), 'recycle_terminal');
	assert_true(! is_wp_error($recycled) && true === ( $recycled['recycled'] ?? false ), is_wp_error($recycled) ? $recycled->get_error_message() : 'terminal recycle did not succeed');
	assert_true('verified' === ( $recycled['handoff_freshness']['status'] ?? null ) && ! empty($recycled['handoff_freshness']['proof']), 'terminal recycle did not issue a verified handoff proof');
	assert_true('terminal_exact_handle' === ( $recycled['recycle']['reason_code'] ?? null ) && 'https://example.test/issues/recycle-old' === ( $recycled['recycle']['lineage']['previous_task']['task_url'] ?? null ) && 'https://example.test/issues/recycle-new' === ( $recycled['recycle']['lineage']['new_task']['task_url'] ?? null ), 'terminal recycle did not return durable task lineage');
	assert_true('https://example.test/issues/recycle-new' === ( $wpdb->rows[$recycle_handle]['task_url'] ?? '' ) && 'https://example.test/issues/recycle-old' === ( $recycled['metadata']['recycle_lineage'][0]['previous_task']['task_url'] ?? null ), 'terminal recycle did not persist task lineage');
	assert_true('preserved context' === trim((string) file_get_contents($recycle_fixture['path'] . '/.recycle-context')) && 'preserved bootstrap' === trim((string) file_get_contents($recycle_fixture['path'] . '/vendor/.recycle-bootstrap-marker')) && 'preserved' === ( $recycled['recycle']['context'] ?? null ) && 'preserved' === ( $recycled['recycle']['bootstrap'] ?? null ), 'terminal recycle did not preserve compatible context/bootstrap assets');
	$recycle_before_refusal = trim(run_command('git rev-parse HEAD', $recycle_fixture['path']));
	file_put_contents($recycle_fixture['path'] . '/recycle-dirty.txt', "dirty\n");
	$recycle_dirty = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-new' ), false, false, array(), 'recycle_terminal');
	assert_true(is_wp_error($recycle_dirty) && 'dirty_worktree' === ( $recycle_dirty->get_error_data()['reuse']['reason_code'] ?? null ) && $recycle_before_refusal === trim(run_command('git rev-parse HEAD', $recycle_fixture['path'])), 'dirty terminal recycle refusal mutated the worktree');
	unlink($recycle_fixture['path'] . '/recycle-dirty.txt');
	file_put_contents($recycle_fixture['path'] . '/recycle-unpushed.txt', "unpushed\n");
	run_command('git add recycle-unpushed.txt && git commit -m recycle-unpushed', $recycle_fixture['path']);
	$recycle_unpushed_head = trim(run_command('git rev-parse HEAD', $recycle_fixture['path']));
	$recycle_unpushed = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-new' ), false, false, array(), 'recycle_terminal');
	assert_true(is_wp_error($recycle_unpushed) && 'unpushed_commits' === ( $recycle_unpushed->get_error_data()['reuse']['reason_code'] ?? null ) && $recycle_unpushed_head === trim(run_command('git rev-parse HEAD', $recycle_fixture['path'])), 'unpushed terminal recycle refusal mutated the worktree');
	run_command('git reset --hard origin/main', $recycle_fixture['path']);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata($recycle_handle, array( 'last_seen_at' => gmdate('c') ));
	$recycle_live = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-new' ), false, false, array(), 'recycle_terminal');
	assert_true(! is_wp_error($recycle_live) && true === ( $recycle_live['recycled'] ?? false ), 'finalized terminal worktree heartbeat revived lifecycle state instead of allowing terminal recycle');
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata($recycle_handle, array( 'last_seen_at' => gmdate('c', time() - 90000) ));
	$recycle_base = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/other-base', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-new' ), false, false, array(), 'recycle_terminal');
	assert_true(is_wp_error($recycle_base) && 'base_mismatch' === ( $recycle_base->get_error_data()['reuse']['reason_code'] ?? null ), 'base-mismatched terminal recycle did not fail closed');
	$recycle_context = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', true, false, false, false, true, array( 'task_url' => 'https://example.test/issues/recycle-new' ), false, false, array(), 'recycle_terminal');
	assert_true(is_wp_error($recycle_context) && 'runtime_incompatible' === ( $recycle_context->get_error_data()['reuse']['reason_code'] ?? null ), 'context-mismatched terminal recycle did not fail closed');
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata($recycle_handle, array( 'last_seen_at' => gmdate('c', time() - 90000) ));
	$recycle_terminal = $workspace->worktree_finalize($recycle_handle, 'merged');
	assert_true(! is_wp_error($recycle_terminal), 'failed to restore terminal fixture for rollback tests');
	$rollback_head = trim(run_command('git rev-parse HEAD', $recycle_fixture['path']));
	$rollback_task = $wpdb->rows[$recycle_handle]['task_url'] ?? '';
	$GLOBALS['datamachine_code_test_filters']['datamachine_code_worktree_recycle_metadata_preflight'] = static fn() => new WP_Error('metadata_failure', 'Injected metadata failure.');
	$metadata_failure = $workspace->worktree_add('homeboy', 'terminal-recycle', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/rollback-metadata' ), false, false, array(), 'recycle_terminal');
	unset($GLOBALS['datamachine_code_test_filters']['datamachine_code_worktree_recycle_metadata_preflight']);
	assert_true(is_wp_error($metadata_failure) && 'worktree_recycle_metadata_persistence_failed' === $metadata_failure->get_error_code() && true === ( $metadata_failure->get_error_data()['recycle']['rollback']['head_restored'] ?? false ) && true === ( $metadata_failure->get_error_data()['recycle']['rollback']['metadata_restored'] ?? false ) && $rollback_head === trim(run_command('git rev-parse HEAD', $recycle_fixture['path'])) && $rollback_task === ( $wpdb->rows[$recycle_handle]['task_url'] ?? '' ) && 'cleanup_eligible' === ( $wpdb->rows[$recycle_handle]['lifecycle_state'] ?? '' ) && 'preserved context' === trim((string) file_get_contents($recycle_fixture['path'] . '/.recycle-context')) && 'preserved bootstrap' === trim((string) file_get_contents($recycle_fixture['path'] . '/vendor/.recycle-bootstrap-marker')), 'metadata failure did not restore exact terminal state or preserve compatible assets');
	$reuse_handle      = 'homeboy@idempotent-reuse';
	$disposable_intent = array( 'purpose' => 'integration-test', 'owner_run_ref' => 'run-991', 'cleanup_policy' => 'remove_on_success' );
	$disposable = $workspace->worktree_add('homeboy', 'purpose-owned-disposable', 'origin/main', false, false, false, false, true, array(), false, false, $disposable_intent);
	assert_true(! is_wp_error($disposable), is_wp_error($disposable) ? $disposable->get_error_message() : 'purpose-owned disposable creation failed');
	assert_true('integration-test' === ( $wpdb->rows['homeboy@purpose-owned-disposable']['purpose'] ?? '' ), 'purpose was not persisted to local inventory');
	assert_true('run-991' === ( $disposable['metadata']['owner_run_ref'] ?? '' ), 'owner run reference did not round-trip through lifecycle metadata');
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata('homeboy@purpose-owned-disposable', array( 'last_seen_at' => gmdate('c', time() - 90000) ));
	$disposable_mismatch = $workspace->worktree_add('homeboy', 'purpose-owned-disposable', 'origin/main', false, false, false, false, true, array(), false, false, array( 'purpose' => 'other', 'owner_run_ref' => 'run-991', 'cleanup_policy' => 'remove_on_success' ));
	assert_true(is_wp_error($disposable_mismatch) && 'disposable_intent_mismatch' === ( $disposable_mismatch->get_error_data()['reuse']['reason_code'] ?? '' ), 'incompatible disposable reuse did not return typed intent evidence');
	$disposable_finalized = $workspace->worktree_finalize('homeboy@purpose-owned-disposable', 'active', null, 'success');
	assert_true(! is_wp_error($disposable_finalized) && 'cleanup_eligible' === ( $disposable_finalized['lifecycle_state'] ?? '' ), 'successful owner terminal outcome did not make disposable worktree cleanup eligible');
	assert_true(strtotime((string) ( $disposable_finalized['metadata']['last_seen_at'] ?? '' )) < strtotime((string) ( $disposable_finalized['metadata']['finalized_at'] ?? '' )), 'terminal finalization must not refresh heartbeat activity');
	$reuse_created_at  = $wpdb->rows[$reuse_handle]['created_at'] ?? null;
	$owner_conflict_plan = $workspace->worktree_plan('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(! is_wp_error($owner_conflict_plan) && 'owner_conflict' === ( $owner_conflict_plan['disposition'] ?? null ), 'live owner conflict was not planned');
	$live_reuse_refusal = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(is_wp_error($live_reuse_refusal) && 'worktree_reuse_refused' === $live_reuse_refusal->get_error_code(), 'live worktree reuse did not fail closed');
	assert_true('live_worktree' === ( $live_reuse_refusal->get_error_data()['reuse']['reason_code'] ?? null ), 'live worktree refusal lacked typed reuse evidence');
	$isolated_refusal = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, false, array(), 'isolated');
	assert_true(is_wp_error($isolated_refusal) && 'isolated_requested' === ( $isolated_refusal->get_error_data()['reuse']['reason_code'] ?? null ), 'explicit isolated policy did not return typed refusal evidence');
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata($reuse_handle, array( 'last_seen_at' => gmdate('c', time() - 90000) ));
	$reused = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(! is_wp_error($reused), is_wp_error($reused) ? $reused->get_error_message() : 'clean compatible worktree was not reused');
	assert_true(true === ( $reused['reused'] ?? false ) && 'exact_compatible_handle' === ( $reused['reuse']['reason_code'] ?? null ), 'exact reuse did not return accepted evidence');
	assert_true('accepted' === ( $reused['reuse']['status'] ?? null ) && 'homeboy@idempotent-reuse' === ( $reused['reuse']['handle'] ?? null ), 'default exact reuse did not preserve typed result evidence');
	assert_true('verified' === ( $reused['handoff_freshness']['status'] ?? null ) && ! empty($reused['handoff_freshness']['proof']), 'exact reuse did not issue a verified handoff proof');
	$late_reuse = new ReflectionMethod($workspace, 'worktree_add_with_capacity_lock');
	$late_reuse_lock = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire($workspace_root, 'homeboy', 0);
	assert_true(! is_wp_error($late_reuse_lock), 'late capacity reuse fixture could not acquire the repository lock');
	try {
		$late_reuse_contended = $late_reuse->invoke($workspace, 'homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, array(), 'idempotent-reuse', $reuse_handle, (string) $reused['path'], $primary_path, 'reuse_compatible', false, false, microtime(true) + 0.1, 1, microtime(true), array());
	} finally {
		$late_reuse_lock->release();
	}
	assert_true(is_wp_error($late_reuse_contended) && 'workspace_repo_busy' === $late_reuse_contended->get_error_code(), 'late capacity admission reuse bypassed the repository lock');
	$late_reuse_result = $late_reuse->invoke($workspace, 'homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ), false, array(), 'idempotent-reuse', $reuse_handle, (string) $reused['path'], $primary_path, 'reuse_compatible', false, false, microtime(true) + 30, 30, microtime(true), array());
	assert_true(! is_wp_error($late_reuse_result) && true === ( $late_reuse_result['reused'] ?? false ) && 'verified' === ( $late_reuse_result['handoff_freshness']['status'] ?? null ) && ! empty($late_reuse_result['handoff_freshness']['proof']), 'late capacity admission reuse did not take the repo-locked proof path');
	assert_true($reuse_created_at === ( $wpdb->rows[$reuse_handle]['created_at'] ?? null ), 'reuse rewrote durable lifecycle metadata');
	assert_true('https://example.test/issues/reuse' === ( $wpdb->rows[$reuse_handle]['task_url'] ?? '' ), 'reuse rewrote durable task metadata');
	$claim_fixture = $workspace->worktree_add('homeboy', 'claim-expired', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/claim-expired' ));
	assert_true(! is_wp_error($claim_fixture), is_wp_error($claim_fixture) ? $claim_fixture->get_error_message() : 'claim fixture creation failed');
	run_command('git push -u origin claim-expired', $claim_fixture['path']);
	$claim_handle = 'homeboy@claim-expired';
	WorktreeContextInjector::store_lifecycle_metadata($claim_handle, array( 'last_seen_at' => gmdate('c', time() - WorktreeContextInjector::DEFAULT_HEARTBEAT_TTL_SECONDS - 1), 'origin_agent' => (object) array( 'id' => 'invalid' ), 'origin_session' => 'session', 'origin_user' => 'user', 'owner_run_ref' => 'old-run' ));
	$claim_intent = array( 'purpose' => 'recovered-owner', 'owner_run_ref' => 'claim-run-1', 'cleanup_policy' => 'remove_on_success' );
	$malformed_claim = $workspace->worktree_add('homeboy', 'claim-expired', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/claim-expired' ), false, false, $claim_intent, 'claim_expired');
	assert_true(is_wp_error($malformed_claim) && 'malformed_ownership_metadata' === ( $malformed_claim->get_error_data()['reuse']['reason_code'] ?? null ) && 'malformed' === ( $malformed_claim->get_error_data()['reuse']['liveness_evidence']['attribution'] ?? null ) && array( 'origin_agent' ) === ( $malformed_claim->get_error_data()['reuse']['liveness_evidence']['malformed_ownership_fields'] ?? null ), 'claim expired refused malformed ownership metadata');
	WorktreeContextInjector::store_lifecycle_metadata($claim_handle, array( 'last_seen_at' => gmdate('c'), 'origin_agent' => '', 'origin_session' => '', 'origin_user' => '', 'owner_run_ref' => '' ));
	$fresh_claim = $workspace->worktree_add('homeboy', 'claim-expired', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/claim-expired' ), false, false, $claim_intent, 'claim_expired');
	assert_true(is_wp_error($fresh_claim) && 'fresh_unattributed_heartbeat' === ( $fresh_claim->get_error_data()['reuse']['reason_code'] ?? null ) && 0 <= (int) ( $fresh_claim->get_error_data()['reuse']['liveness_evidence']['heartbeat_age_seconds'] ?? -1 ) && WorktreeContextInjector::DEFAULT_HEARTBEAT_TTL_SECONDS === (int) ( $fresh_claim->get_error_data()['reuse']['liveness_evidence']['heartbeat_ttl_seconds'] ?? 0 ) && array( 'origin_agent', 'origin_session', 'origin_user', 'owner_run_ref' ) === ( $fresh_claim->get_error_data()['reuse']['liveness_evidence']['missing_ownership_fields'] ?? null ), 'fresh unattributed heartbeat did not refuse with complete deterministic evidence');
	WorktreeContextInjector::store_lifecycle_metadata($claim_handle, array( 'last_seen_at' => gmdate('c', time() - WorktreeContextInjector::DEFAULT_HEARTBEAT_TTL_SECONDS - 1) ));
	$claimed = $workspace->worktree_add('homeboy', 'claim-expired', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/claim-expired' ), false, false, $claim_intent, 'claim_expired');
	assert_true(! is_wp_error($claimed) && true === ( $claimed['claimed'] ?? false ) && 'expired_unattributed_heartbeat' === ( $claimed['claim']['reason_code'] ?? null ) && 'claim-run-1' === ( $claimed['metadata']['owner_run_ref'] ?? null ) && '' === ( $claimed['metadata']['ownership_lineage'][0]['previous_owner_run_ref'] ?? 'not-empty' ), is_wp_error($claimed) ? $claimed->get_error_message() : 'expired anonymous heartbeat was not safely claimed with ownership lineage');
	// Simulate a caller being terminated after checkout materialization and after
	// bootstrap starts: its durable running phase must block readiness until the
	// exact compatible add retry completes bootstrap.
	$interrupted_bootstrap = $workspace->worktree_add('homeboy', 'interrupted-bootstrap', 'origin/main', false, true, false, false, true, array( 'task_url' => 'https://example.test/issues/interrupted-bootstrap' ));
	assert_true(! is_wp_error($interrupted_bootstrap), is_wp_error($interrupted_bootstrap) ? $interrupted_bootstrap->get_error_message() : 'interrupted bootstrap fixture creation failed');
	$interrupted_bootstrap_handle = 'homeboy@interrupted-bootstrap';
	WorktreeContextInjector::store_lifecycle_metadata($interrupted_bootstrap_handle, array(
		'provisioning' => array(
			'create'    => array( 'outcome' => 'succeeded', 'completed_at' => gmdate('c') ),
			'bootstrap' => array( 'requested' => true, 'outcome' => 'running', 'started_at' => gmdate('c'), 'resume_command' => 'wp datamachine-code workspace worktree add homeboy interrupted-bootstrap --from=origin/main --skip-context-injection --task-url=https://example.test/issues/interrupted-bootstrap --require-task-tracker' ),
		),
	));
	$incomplete_get = $workspace->worktree_get($interrupted_bootstrap_handle);
	assert_true(false === ( $incomplete_get['worktrees'][0]['readiness']['ready'] ?? true ) && 'bootstrap_running' === ( $incomplete_get['worktrees'][0]['readiness']['reason'] ?? '' ) && str_contains((string) ($incomplete_get['worktrees'][0]['readiness']['resume_command'] ?? ''), 'worktree add homeboy interrupted-bootstrap'), 'interrupted bootstrap did not survive as explicit incomplete readiness evidence');
	$incomplete_show = $workspace->show_repo($interrupted_bootstrap_handle);
	assert_true(false === ( $incomplete_show['readiness']['ready'] ?? true ) && 'incomplete' === ( $incomplete_show['readiness']['status'] ?? '' ), 'workspace show did not project incomplete bootstrap readiness');
	WorktreeContextInjector::store_lifecycle_metadata($interrupted_bootstrap_handle, array( 'last_seen_at' => gmdate('c', time() - 90000) ));
	$resumed_bootstrap = $workspace->worktree_add('homeboy', 'interrupted-bootstrap', 'origin/main', false, true, false, false, true, array( 'task_url' => 'https://example.test/issues/interrupted-bootstrap' ));
	assert_true(! is_wp_error($resumed_bootstrap) && true === ( $resumed_bootstrap['resumed'] ?? false ) && 'succeeded' === ( $resumed_bootstrap['metadata']['provisioning']['bootstrap']['outcome'] ?? null ), is_wp_error($resumed_bootstrap) ? $resumed_bootstrap->get_error_message() : 'exact retry did not resume interrupted bootstrap');
	assert_true(true === ( $workspace->worktree_get($interrupted_bootstrap_handle)['worktrees'][0]['readiness']['ready'] ?? false ), 'resumed bootstrap remained incomplete');
	$exact_reuse_plan = $workspace->worktree_plan('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(! is_wp_error($exact_reuse_plan) && 'exact_reuse' === ( $exact_reuse_plan['disposition'] ?? null ), 'exact compatible reuse was not planned');
	file_put_contents($reusable['path'] . '/reuse-dirty.txt', "dirty\n");
	$unsafe_plan = $workspace->worktree_plan('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(! is_wp_error($unsafe_plan) && 'unsafe' === ( $unsafe_plan['disposition'] ?? null ), 'unsafe existing destination was not planned');
	$dirty_reuse_refusal = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(is_wp_error($dirty_reuse_refusal) && 'dirty_worktree' === ( $dirty_reuse_refusal->get_error_data()['reuse']['reason_code'] ?? null ), 'dirty worktree reuse did not fail closed');
	unlink($reusable['path'] . '/reuse-dirty.txt');
	$runtime_reuse_refusal = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', true, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(is_wp_error($runtime_reuse_refusal) && 'runtime_incompatible' === ( $runtime_reuse_refusal->get_error_data()['reuse']['reason_code'] ?? null ), 'incompatible context runtime reuse did not fail closed');
	$base_reuse_refusal = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/other-base', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(is_wp_error($base_reuse_refusal) && 'base_mismatch' === ( $base_reuse_refusal->get_error_data()['reuse']['reason_code'] ?? null ), 'mismatched base reuse did not fail closed');
	run_command('git push -u origin idempotent-reuse', $reusable['path']);
	file_put_contents($reusable['path'] . '/reuse-commit.txt', "unpushed\n");
	run_command('git add reuse-commit.txt && git commit -m reuse-unpushed', $reusable['path']);
	$unpushed_reuse_refusal = $workspace->worktree_add('homeboy', 'idempotent-reuse', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/reuse' ));
	assert_true(is_wp_error($unpushed_reuse_refusal) && 'unpushed_commits' === ( $unpushed_reuse_refusal->get_error_data()['reuse']['reason_code'] ?? null ), 'unpushed worktree reuse did not fail closed');

	// Simulate process termination immediately after `git worktree add`: the
	// durable pre-creation journal exists, while lifecycle metadata does not.
	$interrupted_base_head = trim(run_command('git rev-parse origin/main', $primary_path));
	$interrupted_path = $workspace_root . '/homeboy@interrupted-add-recovery';
	$interrupted_task = array( 'task_url' => 'https://example.test/issues/interrupted-add' );
	assert_true(true === WorktreeContextInjector::store_creation_intent('homeboy@interrupted-add-recovery', interrupted_creation_intent('interrupted-add-recovery', $interrupted_base_head, $interrupted_task)), 'interruption fixture could not persist its pre-creation intent');
	run_command('git worktree add -b interrupted-add-recovery ' . escapeshellarg($interrupted_path) . ' origin/main', $primary_path);
	$adoptable_plan = $workspace->worktree_plan('homeboy', 'interrupted-add-recovery', 'origin/main', false, false, false, false, true, $interrupted_task);
	assert_true(! is_wp_error($adoptable_plan) && 'adoptable' === ( $adoptable_plan['disposition'] ?? null ) && null !== WorktreeContextInjector::get_creation_intent('homeboy@interrupted-add-recovery'), 'interrupted exact handle was not planned as adoptable without metadata mutation');
	$adopted = $workspace->worktree_add('homeboy', 'interrupted-add-recovery', 'origin/main', false, false, false, false, true, $interrupted_task);
	assert_true(! is_wp_error($adopted) && true === ( $adopted['adopted'] ?? false ), is_wp_error($adopted) ? $adopted->get_error_message() : 'exact interrupted worktree was not adopted');
	assert_true('interrupted_exact_handle' === ( $adopted['recovery']['reason_code'] ?? null ) && 'https://example.test/issues/interrupted-add' === ( $adopted['metadata']['origin_task']['task_url'] ?? null ) && 'origin/main' === ( $adopted['metadata']['reuse_contract']['base_ref'] ?? null ) && null === WorktreeContextInjector::get_creation_intent('homeboy@interrupted-add-recovery'), 'interrupted adoption did not promote and clear the exact journal contract');
	assert_true('verified' === ( $adopted['handoff_freshness']['status'] ?? null ) && ! empty($adopted['handoff_freshness']['proof']), 'interrupted adoption did not issue a verified handoff proof');

	$external_path = $workspace_root . '/homeboy@interrupted-add-external';
	run_command('git worktree add -b interrupted-add-external ' . escapeshellarg($external_path) . ' origin/main', $primary_path);
	$external = $workspace->worktree_add('homeboy', 'interrupted-add-external', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/external' ));
	assert_true(is_wp_error($external) && 'interrupted_recovery_intent_missing' === ( $external->get_error_data()['reuse']['reason_code'] ?? null ) && null === WorktreeContextInjector::get_creation_intent('homeboy@interrupted-add-external'), 'external metadata-less worktree was adopted from the retry task alone');

	$mismatch_path = $workspace_root . '/homeboy@interrupted-add-task-mismatch';
	$mismatch_task = array( 'task_url' => 'https://example.test/issues/original-task' );
	$mismatch_intent = interrupted_creation_intent('interrupted-add-task-mismatch', $interrupted_base_head, $mismatch_task);
	assert_true(true === WorktreeContextInjector::store_creation_intent('homeboy@interrupted-add-task-mismatch', $mismatch_intent), 'mismatched-task fixture could not persist its pre-creation intent');
	run_command('git worktree add -b interrupted-add-task-mismatch ' . escapeshellarg($mismatch_path) . ' origin/main', $primary_path);
	$mismatched_task = $workspace->worktree_add('homeboy', 'interrupted-add-task-mismatch', 'origin/main', false, false, false, false, true, array( 'task_url' => 'https://example.test/issues/retry-task' ));
	assert_true(is_wp_error($mismatched_task) && 'interrupted_recovery_intent_mismatch' === ( $mismatched_task->get_error_data()['reuse']['reason_code'] ?? null ) && $mismatch_intent === WorktreeContextInjector::get_creation_intent('homeboy@interrupted-add-task-mismatch'), 'mismatched retry task adopted or cleared an interrupted creation journal');

	$dirty_interrupted_path = $workspace_root . '/homeboy@interrupted-add-dirty';
	$dirty_interrupted_task = array( 'task_url' => 'https://example.test/issues/interrupted-dirty' );
	assert_true(true === WorktreeContextInjector::store_creation_intent('homeboy@interrupted-add-dirty', interrupted_creation_intent('interrupted-add-dirty', $interrupted_base_head, $dirty_interrupted_task)), 'dirty interruption fixture could not persist its pre-creation intent');
	run_command('git worktree add -b interrupted-add-dirty ' . escapeshellarg($dirty_interrupted_path) . ' origin/main', $primary_path);
	file_put_contents($dirty_interrupted_path . '/interrupted-dirty.txt', "dirty\n");
	$dirty_interrupted = $workspace->worktree_add('homeboy', 'interrupted-add-dirty', 'origin/main', false, false, false, false, true, $dirty_interrupted_task);
	assert_true(is_wp_error($dirty_interrupted) && 'dirty_worktree' === ( $dirty_interrupted->get_error_data()['reuse']['reason_code'] ?? null ) && null !== WorktreeContextInjector::get_creation_intent('homeboy@interrupted-add-dirty'), 'dirty interrupted worktree was adopted');

	$advanced_interrupted_path = $workspace_root . '/homeboy@interrupted-add-advanced';
	$advanced_interrupted_task = array( 'task_url' => 'https://example.test/issues/interrupted-advanced' );
	assert_true(true === WorktreeContextInjector::store_creation_intent('homeboy@interrupted-add-advanced', interrupted_creation_intent('interrupted-add-advanced', $interrupted_base_head, $advanced_interrupted_task)), 'advanced interruption fixture could not persist its pre-creation intent');
	run_command('git worktree add -b interrupted-add-advanced ' . escapeshellarg($advanced_interrupted_path) . ' origin/main', $primary_path);
	file_put_contents($advanced_interrupted_path . '/advanced.txt', "advanced\n");
	run_command('git add advanced.txt && git commit -m interrupted-advanced', $advanced_interrupted_path);
	$advanced_interrupted = $workspace->worktree_add('homeboy', 'interrupted-add-advanced', 'origin/main', false, false, false, false, true, $advanced_interrupted_task);
	assert_true(is_wp_error($advanced_interrupted) && 'unpushed_commits' === ( $advanced_interrupted->get_error_data()['reuse']['reason_code'] ?? null ) && null !== WorktreeContextInjector::get_creation_intent('homeboy@interrupted-add-advanced'), 'advanced interrupted worktree was adopted');

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
	$fake_bin = $workspace_root . '/fake-bin';
	mkdir($fake_bin, 0777, true);
	$git_probe_log = $workspace_root . '/git-probe.log';
	$real_git      = trim((string) shell_exec('command -v git'));
	assert_true('' !== $real_git, 'test fixture could not resolve git');
	file_put_contents($fake_bin . '/git', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($git_probe_log) . "\nexec " . escapeshellarg($real_git) . " \"\$@\"\n");
	chmod($fake_bin . '/git', 0755);
	$original_path = getenv('PATH');
	putenv('PATH=' . $fake_bin . ':' . ( false === $original_path ? '' : $original_path ));
	$started       = microtime(true);
	$targeted_large = $workspace->worktree_get($result['path'], array( 'include_status' => true, 'include_disk' => false ));
	$elapsed       = microtime(true) - $started;
	putenv('PATH=' . ( false === $original_path ? '' : $original_path ));
	assert_true(! is_wp_error($targeted_large), 'targeted worktree_get failed in a large workspace fixture');
	assert_true(1 === count($targeted_large['worktrees'] ?? array()), 'targeted worktree_get returned unrelated worktrees');
	assert_true($handle === ( $targeted_large['worktrees'][0]['handle'] ?? '' ), 'canonical-path worktree_get returned the wrong handle');
	assert_true($result['path'] === ( $targeted_large['worktrees'][0]['path'] ?? '' ), 'canonical-path worktree_get did not preserve the canonical path');
	assert_true($elapsed < 3.0, sprintf('targeted worktree_get scanned unrelated workspace entries: %.3fs', $elapsed));
	$git_probes = array_values(array_filter(file($git_probe_log, FILE_IGNORE_NEW_LINES) ?: array(), static fn( string $probe ): bool => str_starts_with($probe, '-C ')));
	assert_true(5 === count($git_probes), 'targeted worktree_get did not perform its bounded identity and safety probes');
	foreach ( $git_probes as $probe ) {
		assert_true(str_starts_with($probe, '-C ' . $result['path'] . ' '), 'targeted worktree_get probed an unrelated worktree: ' . $probe);
	}

	// Every targeted Git probe has a finite deadline and identifies the phase
	// that blocked without consulting another checkout.
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
	assert_true(null === WorktreeContextInjector::get_creation_intent('homeboy@audit-primitives-persist-fails'), 'rolled-back worktree creation left its pre-creation journal behind');

	// An explicit missing base remains fail-closed, but exposes the detected
	// default ref and an exact corrected command for main, trunk, and custom heads.
	$missing_main = $workspace->worktree_add('homeboy', 'missing-main-base', 'origin/not-a-ref', false, false, false, false, true);
	assert_true(is_wp_error($missing_main), 'missing explicit main base reported success');
	assert_true('worktree_target_ref_invalid' === $missing_main->get_error_code(), 'missing explicit main base changed the existing error code');
	$missing_main_data = (array) $missing_main->get_error_data();
	assert_true('origin/main' === ( $missing_main_data['detected_default_ref'] ?? null ), 'missing explicit main base did not detect origin/main');
	assert_true('remote_head' === ( $missing_main_data['default_ref_source'] ?? null ), 'missing explicit main base did not report remote-head evidence');
	assert_true(1 === count((array) ( $missing_main_data['next_commands'] ?? array() )) && str_contains((string) $missing_main_data['next_commands'][0], "--from='origin/main'"), 'missing explicit main base did not return a corrected replay command');
	$adversarial_branch = 'missing;$(touch should-not-run)';
	$adversarial_intent = array(
		'purpose'        => 'review;$(touch should-not-run)',
		'owner_run_ref'  => 'run;$(touch should-not-run)',
		'cleanup_policy' => 'remove_on_success',
	);
	$adversarial = $workspace->worktree_add('homeboy', $adversarial_branch, 'origin/not-a-ref;$(touch should-not-run)', false, false, false, false, true, array(), false, false, $adversarial_intent);
	$adversarial_data = (array) $adversarial->get_error_data();
	$adversarial_command = (string) ( $adversarial_data['next_commands'][0] ?? '' );
	assert_true(is_wp_error($adversarial) && 'worktree_target_ref_invalid' === $adversarial->get_error_code(), 'adversarial missing base changed fail-closed error behavior');
	assert_true(str_contains($adversarial_command, escapeshellarg($adversarial_branch)) && str_contains($adversarial_command, '--purpose=' . escapeshellarg($adversarial_intent['purpose'])) && str_contains($adversarial_command, '--owner-run-ref=' . escapeshellarg($adversarial_intent['owner_run_ref'])), 'replay command did not shell-escape adversarial values');
	assert_true(! str_contains($adversarial_command, 'origin/not-a-ref;'), 'replay command retained the invalid explicit base');
	assert_true(! file_exists($workspace_root . '/should-not-run'), 'replay command executed adversarial shell input while rendering');

	run_command('git checkout main', $source_path);
	run_command('git branch -f trunk main && git push -f origin trunk', $source_path);
	run_command('git fetch origin && git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/trunk', $primary_path);
	$missing_trunk = $workspace->worktree_add('homeboy', 'missing-trunk-base', 'origin/not-a-ref', false, false, false, false, true);
	$missing_trunk_data = (array) $missing_trunk->get_error_data();
	assert_true(is_wp_error($missing_trunk) && 'origin/trunk' === ( $missing_trunk_data['detected_default_ref'] ?? null ), 'missing explicit trunk base did not detect origin/trunk');

	run_command('git branch -f release/current main && git push -f origin release/current', $source_path);
	run_command('git fetch origin && git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/release/current', $primary_path);
	$missing_custom = $workspace->worktree_add('homeboy', 'missing-custom-base', 'origin/not-a-ref', false, false, false, false, true);
	$missing_custom_data = (array) $missing_custom->get_error_data();
	assert_true(is_wp_error($missing_custom) && 'origin/release/current' === ( $missing_custom_data['detected_default_ref'] ?? null ), 'missing explicit custom base did not detect the configured remote head');

	run_command('git --git-dir=' . escapeshellarg($workspace_root . '/origin.git') . ' symbolic-ref HEAD refs/heads/no-default-branch', $primary_path);
	run_command('git symbolic-ref -d refs/remotes/origin/HEAD', $primary_path);
	$missing_remote_head = $workspace->worktree_add('homeboy', 'missing-remote-head-base', 'origin/not-a-ref', false, false, false, false, true);
	$missing_remote_head_data = (array) $missing_remote_head->get_error_data();
	assert_true(is_wp_error($missing_remote_head) && 'origin/main' === ( $missing_remote_head_data['detected_default_ref'] ?? null ) && 'workspace_upstream' === ( $missing_remote_head_data['default_ref_source'] ?? null ), 'missing remote head did not fall back to the configured workspace upstream');
	run_command('git symbolic-ref refs/remotes/origin/HEAD refs/heads/main', $primary_path);
	$malformed_remote_head = $workspace->worktree_add('homeboy', 'malformed-remote-head-base', 'origin/not-a-ref', false, false, false, false, true);
	$malformed_remote_head_data = (array) $malformed_remote_head->get_error_data();
	assert_true(is_wp_error($malformed_remote_head) && 'origin/main' === ( $malformed_remote_head_data['detected_default_ref'] ?? null ) && 'workspace_upstream' === ( $malformed_remote_head_data['default_ref_source'] ?? null ), 'malformed remote head did not fall back to the configured workspace upstream');

	run_command('git symbolic-ref -d refs/remotes/origin/HEAD', $primary_path);
	run_command('git config --unset branch.main.remote && git config --unset branch.main.merge', $primary_path);
	$missing_metadata = $workspace->worktree_add('homeboy', 'missing-metadata-base', 'origin/not-a-ref', false, false, false, false, true);
	$missing_metadata_data = (array) $missing_metadata->get_error_data();
	assert_true(is_wp_error($missing_metadata) && array_key_exists('detected_default_ref', $missing_metadata_data) && null === $missing_metadata_data['detected_default_ref'], 'unavailable remote metadata reported a default ref');
	assert_true('unavailable' === ( $missing_metadata_data['default_ref_source'] ?? null ), 'unavailable remote metadata did not report its evidence state');
	assert_true(array() === ( $missing_metadata_data['next_commands'] ?? null ), 'unavailable remote metadata returned an unsafe replay command');

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
	$handoff_proof_issuer = new ReflectionMethod($workspace, 'worktree_add_handoff_proof');
	$post_allocation_refusal = $handoff_proof_issuer->invoke($workspace, $result, false);
	$post_allocation_data = is_wp_error($post_allocation_refusal) ? (array) $post_allocation_refusal->get_error_data() : array();
	assert_true(is_wp_error($post_allocation_refusal) && 'worktree_handoff_freshness_unverified' === $post_allocation_refusal->get_error_code(), 'direct Workspace caller did not fail closed after post-allocation proof failure');
	assert_true((string) $result['handle'] === (string) ( $post_allocation_data['handle'] ?? '' ) && (string) $result['path'] === (string) ( $post_allocation_data['path'] ?? '' ) && true === ( $post_allocation_data['retry']['allocation_preserved'] ?? false ), 'post-allocation freshness refusal omitted preserved allocation and safe retry evidence');
	$post_allocation_opt_in = $handoff_proof_issuer->invoke($workspace, $result, true);
	assert_true(! is_wp_error($post_allocation_opt_in) && 'unverified' === ( $post_allocation_opt_in['handoff_freshness']['status'] ?? null ), 'explicit post-allocation freshness opt-in did not return typed unverified state');
	$fetch_failed_default = $workspace->worktree_add('homeboy', 'audit-primitives-fetch-fails', 'origin/main', false, false, false, false, true);
	assert_true(is_wp_error($fetch_failed_default), 'fetch failure reported success without explicit opt-in');
	assert_true('worktree_freshness_unverified' === $fetch_failed_default->get_error_code(), 'unexpected fetch failure error code');
	$fetch_failure_data = (array) $fetch_failed_default->get_error_data();
	assert_true(2 === ( $fetch_failure_data['fetch_attempts'] ?? null ), 'persistent fetch failure did not report the bounded retry count');
	assert_true(! empty($fetch_failure_data['fetch_error']), 'persistent fetch failure did not expose Git stderr');
	assert_true(2 === count((array) ( $fetch_failure_data['next_commands'] ?? array() )), 'persistent fetch failure did not return safe refresh and retry commands');
	assert_true(! str_contains(implode(' ', (array) $fetch_failure_data['next_commands']), '--allow-unverified-freshness'), 'persistent fetch failure recommended an unsafe freshness bypass');
	assert_true(! is_dir($workspace_root . '/homeboy@audit-primitives-fetch-fails'), 'fetch failure left a worktree directory behind');

	$fetch_failed_allowed = $workspace->worktree_add('homeboy', 'audit-primitives-fetch-fails-allowed', 'origin/main', false, false, false, false, true, array(), true);
	assert_true(! is_wp_error($fetch_failed_allowed), is_wp_error($fetch_failed_allowed) ? $fetch_failed_allowed->get_error_message() : 'fetch failure opt-in failed');
	assert_true(! empty($fetch_failed_allowed['fetch_failed']), 'fetch failure opt-in did not surface fetch_failed');
	assert_true(is_dir($fetch_failed_allowed['path']), 'fetch failure opt-in worktree path is not accessible');
	$handoff_fetch_failed = $workspace->worktree_handoff_revalidate((string) $result['handle'], $handoff_proof);
	assert_true(! is_wp_error($handoff_fetch_failed) && 'fetch_failed' === ( $handoff_fetch_failed['status'] ?? null ), 'handoff revalidation did not return typed fetch failure');

	remove_tree($workspace_root, $fixture);
	fwrite(STDOUT, "worktree-add-lifecycle ok: fixture {$workspace_root}\n");
} catch (Throwable $e) {
	if ( is_dir($workspace_root) ) {
		remove_tree($workspace_root, $fixture);
		fwrite(STDERR, "worktree-add-lifecycle failure cleaned fixture {$workspace_root}\n");
	}
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
