<?php

declare(strict_types=1);

$GLOBALS['remote_workspace_task_isolation_state'] = array(
	'repos'      => array( 'repo' => array( 'repo' => 'Extra-Chill/example' ) ),
	'repo_names' => array(),
	'worktrees'  => array(),
);
$GLOBALS['remote_workspace_task_isolation_locks'] = array();

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

$backend = new RemoteWorkspaceBackend();
$task    = array( 'task_url' => 'https://example.test/issues/1' );
$first   = $backend->worktree_add('repo', 'first', 'main', $task);
remote_isolation_assert(! is_wp_error($first), 'first remote task worktree failed');
$reused = $backend->worktree_add('repo', 'first', 'main', $task);
remote_isolation_assert(! is_wp_error($reused) && true === ( $reused['reused'] ?? false ) && false === ( $reused['created_branch'] ?? true ), 'exact remote handle was not reused idempotently');
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
remote_isolation_assert(! isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@second']), 'refused remote duplicate mutated state');

$ownerless = $backend->worktree_add('repo', 'second', 'main', $task, array(), 'isolated');
remote_isolation_assert(is_wp_error($ownerless) && 'same_task_isolation_intent_required' === ( $ownerless->get_error_data()['reuse']['reason_code'] ?? null ), 'ownerless remote isolation was not refused');

$isolated = $backend->worktree_add('repo', 'second', 'main', $task, array( 'purpose' => 'parallel-review', 'owner_run_ref' => 'run-1', 'cleanup_policy' => 'remove_on_success' ), 'isolated');
remote_isolation_assert(! is_wp_error($isolated) && isset($GLOBALS['remote_workspace_task_isolation_state']['worktrees']['repo@second']), 'owned remote isolation was not created');

$recycle_new = $backend->worktree_add('repo', 'third', 'main', $task, array(), 'recycle_terminal');
remote_isolation_assert(is_wp_error($recycle_new) && 'same_task_candidate_requires_explicit_isolation' === ( $recycle_new->get_error_data()['reuse']['reason_code'] ?? null ), 'recycle_terminal allocated a new remote duplicate');

echo "remote workspace task isolation test passed.\n";
