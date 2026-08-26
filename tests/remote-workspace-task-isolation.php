<?php

declare(strict_types=1);

$GLOBALS['remote_workspace_task_isolation_state'] = array(
	'repos'      => array( 'repo' => array( 'repo' => 'Extra-Chill/example' ) ),
	'repo_names' => array(),
	'worktrees'  => array(),
);
$GLOBALS['remote_workspace_task_isolation_locks'] = array();
$GLOBALS['remote_workspace_task_isolation_fail_state_write'] = false;

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}
if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): array { return $this->data; }
	}
}
function get_option( string $name, mixed $default = null ): mixed {
	return 'datamachine_code_remote_workspace_state' === $name ? $GLOBALS['remote_workspace_task_isolation_state'] : ( $GLOBALS['remote_workspace_task_isolation_locks'][ $name ] ?? $default );
}
function update_option( string $name, mixed $value, bool $autoload = false ): bool {
	if ( 'datamachine_code_remote_workspace_state' === $name ) {
		if ( $GLOBALS['remote_workspace_task_isolation_fail_state_write'] ) {
			return false;
		}
		$GLOBALS['remote_workspace_task_isolation_state'] = $value;
	}
	return true;
}
function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( isset($GLOBALS['remote_workspace_task_isolation_locks'][ $name ]) ) {
		return false;
	}
	$GLOBALS['remote_workspace_task_isolation_locks'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset($GLOBALS['remote_workspace_task_isolation_locks'][ $name ]);
	return true;
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspacePolicy.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/RemoteWorkspaceBackend.php';

use DataMachineCode\Workspace\RemoteWorkspaceBackend;

function remote_isolation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$remote_backend = new RemoteWorkspaceBackend();
$unverified_refused = $remote_backend->worktree_add('repo', 'freshness-refused', 'main', array( 'task_url' => 'https://example.test/issues/freshness' ));
remote_isolation_assert(is_wp_error($unverified_refused) && 'worktree_handoff_freshness_unverified' === $unverified_refused->get_error_code(), 'remote backend did not fail closed before unsupported-proof allocation');
remote_isolation_assert(! isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@freshness-refused']), 'remote freshness refusal mutated state');
$backend = new class( $remote_backend ) {
	public function __construct( private RemoteWorkspaceBackend $backend ) {}
	public function worktree_add( mixed ...$args ): array|WP_Error {
		if ( count($args) < 5 ) {
			$args[] = array();
		}
		if ( count($args) < 6 ) {
			$args[] = 'reuse_compatible';
		}
		$args[] = true;
		return $this->backend->worktree_add(...$args);
	}
};
$task    = array( 'task_url' => 'https://example.test/issues/1' );
$invalid = $backend->worktree_add('repo', 'invalid-cleanup-policy', 'main', $task, array( 'cleanup_policy' => 'retain' ));
remote_isolation_assert(is_wp_error($invalid) && 'invalid_cleanup_policy' === $invalid->get_error_code(), 'invalid cleanup policy did not return a typed validation error');
remote_isolation_assert(! isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@invalid-cleanup-policy']), 'invalid cleanup policy reserved a remote worktree lifecycle record');
$corrected = $backend->worktree_add('repo', 'invalid-cleanup-policy', 'main', $task, array( 'cleanup_policy' => 'manual' ));
remote_isolation_assert(! is_wp_error($corrected) && isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@invalid-cleanup-policy']), 'corrected immediate retry was blocked by an invalid reservation');
remote_isolation_assert('unverified' === ( $corrected['handoff_freshness']['status'] ?? null ) && 'remote_freshness_probe_unsupported' === ( $corrected['handoff_freshness']['reason'] ?? null ), 'remote creation did not expose its typed unverified freshness contract');
$GLOBALS['remote_workspace_task_isolation_fail_state_write'] = true;
$persistence_failed = $backend->worktree_add('repo', 'state-write-failure', 'main', array( 'task_url' => 'https://example.test/issues/write-failure' ));
$GLOBALS['remote_workspace_task_isolation_fail_state_write'] = false;
remote_isolation_assert(is_wp_error($persistence_failed) && 'remote_workspace_state_persist_failed' === $persistence_failed->get_error_code(), 'state write failure did not return a typed creation failure');
remote_isolation_assert(! isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@state-write-failure']), 'failed remote creation retained a reservation');
$persistence_retry = $backend->worktree_add('repo', 'state-write-failure', 'main', array( 'task_url' => 'https://example.test/issues/write-failure' ));
remote_isolation_assert(! is_wp_error($persistence_retry), 'immediate retry after state write failure remained reserved');
$GLOBALS['remote_workspace_task_isolation_state']['worktrees'] = array();
$first   = $backend->worktree_add('repo', 'first', 'main', $task);
remote_isolation_assert(! is_wp_error($first), 'first remote task worktree failed');
$reused = $backend->worktree_add('repo', 'first', 'main', $task);
remote_isolation_assert(! is_wp_error($reused) && true === ( $reused['reused'] ?? false ) && false === ( $reused['created_branch'] ?? true ), 'exact remote handle was not reused idempotently');
remote_isolation_assert('unverified' === ( $reused['handoff_freshness']['status'] ?? null ) && 'remote_freshness_probe_unsupported' === ( $reused['handoff_freshness']['reason'] ?? null ), 'remote reuse did not expose its typed unverified freshness contract');
$isolated_exact = $backend->worktree_add('repo', 'first', 'main', $task, array(), 'isolated');
remote_isolation_assert(is_wp_error($isolated_exact) && 'isolated_requested' === ( $isolated_exact->get_error_data()['reuse']['reason_code'] ?? null ), 'isolated exact remote handle was silently reused');
$recycle_exact = $backend->worktree_add('repo', 'first', 'main', $task, array(), 'recycle_terminal');
remote_isolation_assert(is_wp_error($recycle_exact) && 'remote_recycle_terminal_unsupported' === ( $recycle_exact->get_error_data()['reuse']['reason_code'] ?? null ), 'remote exact handle was recycled without terminal safety proof');
$base_mismatch = $backend->worktree_add('repo', 'first', 'other-base', $task);
remote_isolation_assert(is_wp_error($base_mismatch) && 'base_mismatch' === ( $base_mismatch->get_error_data()['reuse']['reason_code'] ?? null ), 'remote exact handle silently accepted a different base');
$branch_collision = $backend->worktree_add('repo', 'FIRST', 'main', $task);
remote_isolation_assert(is_wp_error($branch_collision) && 'branch_mismatch' === ( $branch_collision->get_error_data()['reuse']['reason_code'] ?? null ), 'remote slug collision silently reused a different branch');

$refused = $backend->worktree_add('repo', 'second', 'main', $task);
remote_isolation_assert(is_wp_error($refused) && 'same_task_candidate_requires_explicit_isolation' === ( $refused->get_error_data()['reuse']['reason_code'] ?? null ), 'default remote duplicate was not refused');
remote_isolation_assert('repo@first' === ( $refused->get_error_data()['reuse']['conflicting_handle'] ?? null ) && 'isolated' === ( $refused->get_error_data()['reuse']['supported_reuse_policy'] ?? null ), 'remote conflict did not identify the blocking handle and supported reuse policy');
$remote_refusal = (array) $refused->get_error_data()['reuse'];
remote_isolation_assert(array( '--purpose', '--owner-run-ref', '--cleanup-policy' ) === array_column((array) ( $remote_refusal['missing_fields'] ?? array() ), 'cli_flag'), 'remote conflict did not expose canonical structured missing fields');
remote_isolation_assert(array() === array_diff(array( 'handle', 'owner', 'state', 'cleanup_policy' ), array_keys((array) ( $remote_refusal['candidates'][0] ?? array() ))), 'remote conflict omitted bounded candidate ownership evidence');
remote_isolation_assert(str_contains((string) ( $remote_refusal['corrected_command_template'] ?? '' ), "--reuse-policy='isolated'") && str_contains((string) $remote_refusal['corrected_command_template'], "--owner-run-ref='<owner-run-ref>'"), 'remote conflict did not include a replayable isolation template');
remote_isolation_assert(! isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@second']), 'refused remote duplicate mutated state');

for ( $index = 0; $index < 7; $index++ ) {
	$GLOBALS['remote_workspace_task_isolation_state']['worktrees'][ 'repo@bounded-' . $index ] = array( 'repo_name' => 'repo', 'branch' => 'bounded-' . $index, 'task' => array( 'task_ref' => 'bounded#1' ), 'owner_run_ref' => 'run-' . $index, 'cleanup_policy' => 'manual' );
}
$bounded = $backend->worktree_add('repo', 'bounded-new', 'main', array( 'task_ref' => 'bounded#1' ));
remote_isolation_assert(is_wp_error($bounded) && 5 === count((array) ( $bounded->get_error_data()['reuse']['candidates'] ?? array() )), 'remote same-task candidate evidence exceeded its declared bound');
remote_isolation_assert(array( 'repo@bounded-0', 'repo@bounded-1', 'repo@bounded-2', 'repo@bounded-3', 'repo@bounded-4' ) === array_column((array) $bounded->get_error_data()['reuse']['candidates'], 'handle'), 'bounded remote candidate evidence was not deterministic');

$ownerless = $backend->worktree_add('repo', 'second', 'main', $task, array(), 'isolated');
remote_isolation_assert(is_wp_error($ownerless) && 'same_task_isolation_intent_required' === ( $ownerless->get_error_data()['reuse']['reason_code'] ?? null ), 'ownerless remote isolation was not refused');

$isolation_fields = array(
	'purpose'                           => 'parallel-review',
	'owner_run_ref'                     => 'run-1',
	'cleanup_policy=remove_on_success' => 'remove_on_success',
);
for ( $mask = 0; $mask < 7; $mask++ ) {
	$intent  = array();
	$missing = array();
	foreach ( $isolation_fields as $field => $value ) {
		$bit = array_search($field, array_keys($isolation_fields), true);
		if ( 0 !== ( $mask & ( 1 << $bit ) ) ) {
			$intent[ 'cleanup_policy=remove_on_success' === $field ? 'cleanup_policy' : $field ] = $value;
		} else {
			$missing[] = $field;
		}
	}
	$incomplete = $backend->worktree_add('repo', 'incomplete-isolation-' . $mask, 'main', $task, $intent, 'isolated');
	remote_isolation_assert(is_wp_error($incomplete), 'incomplete remote isolation was accepted for mask ' . $mask);
	remote_isolation_assert($missing === ( $incomplete->get_error_data()['reuse']['missing_intent'] ?? null ), 'incomplete remote isolation did not return the complete missing-field list for mask ' . $mask);
	remote_isolation_assert(count((array) ( $incomplete->get_error_data()['reuse']['missing_fields'] ?? array() )) === count($missing), 'incomplete remote isolation did not mirror missing intent as structured CLI fields for mask ' . $mask);
}

$isolated = $backend->worktree_add('repo', 'second', 'main', $task, array( 'purpose' => 'parallel-review', 'owner_run_ref' => 'run-1', 'cleanup_policy' => 'remove_on_success' ), 'isolated');
remote_isolation_assert(! is_wp_error($isolated) && isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@second']), 'owned remote isolation was not created');

$recycle_new = $backend->worktree_add('repo', 'third', 'main', $task, array(), 'recycle_terminal');
remote_isolation_assert(is_wp_error($recycle_new) && 'same_task_candidate_requires_explicit_isolation' === ( $recycle_new->get_error_data()['reuse']['reason_code'] ?? null ), 'recycle_terminal allocated a new remote duplicate');

echo "remote workspace task isolation test passed.\n";
