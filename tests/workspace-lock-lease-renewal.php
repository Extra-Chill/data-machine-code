<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';
const ABSPATH = __DIR__ . '/fixtures/';

final class WP_Error {
	public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return 'datamachine_code_workspace_lock_time' === $hook ? $GLOBALS['workspace_lock_test_time'] : $value; }

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceLockStore.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

use DataMachineCode\Workspace\WorkspaceLockStore;
use DataMachineCode\Workspace\WorkspaceMutationLock;
use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;

final class Workspace_Lock_Lease_Lifecycle_Harness {
	use WorkspaceWorktreeLifecycle;
	public function heartbeat(WorkspaceMutationLock $lock, string $phase, float $deadline, int $timeout, float $started): ?WP_Error { return $this->worktree_capacity_lock_heartbeat($lock, $phase, $deadline, $timeout, $started); }
}

final class Workspace_Lock_Lease_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public function __construct(private PDO $pdo) {}
	public function prepare(string $query, mixed ...$args): string { foreach ($args as $arg) { $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : $this->pdo->quote((string) $arg), $query, 1); } return $query; }
	public function get_var(string $query): mixed { return str_contains($query, 'SHOW TABLES') ? 'wp_datamachine_code_locks' : $this->pdo->query($query)->fetchColumn(); }
	public function get_row(string $query, string $format): array|false { $row = $this->pdo->query($query)->fetch(PDO::FETCH_ASSOC); return false === $row ? false : $row; }
	public function insert(string $table, array $data, array $formats): int|false { $columns = array_keys($data); $statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'); $statement->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
	public function update(string $table, array $data, array $where, array $formats, array $where_formats): int|false { $sets = implode(',', array_map(static fn(string $key): string => $key . ' = ?', array_keys($data))); $terms = implode(' AND ', array_map(static fn(string $key): string => $key . ' = ?', array_keys($where))); $statement = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $terms); $statement->execute(array_merge(array_values($data), array_values($where))); return $statement->rowCount(); }
}

function lease_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }

$database = tempnam(sys_get_temp_dir(), 'dmc-lock-lease-');
try {
	$pdo = new PDO('sqlite:' . $database);
	$pdo->exec('CREATE TABLE wp_datamachine_code_locks (id INTEGER PRIMARY KEY, lock_key TEXT, purpose TEXT, scope TEXT, owner TEXT, run_id TEXT, job_id INTEGER, status TEXT, acquired_at TEXT, heartbeat_at TEXT, expires_at TEXT, released_at TEXT, metadata_json TEXT)');
	$GLOBALS['wpdb'] = new Workspace_Lock_Lease_Wpdb($pdo);
	$GLOBALS['workspace_lock_test_time'] = 1000;
	$expected = gmdate('c', 3000);
	$id = WorkspaceLockStore::register_acquired(array( 'lock_key' => 'worktree-workspace-capacity-admission', 'scope' => 'workspace-capacity-admission', 'metadata' => array( 'expected_release_at' => $expected ) ));
	lease_assert(is_int($id) && $id > 0, 'Could not register the virtual long-running lock.');
	$GLOBALS['workspace_lock_test_time'] = 1901;
	$active = WorkspaceLockStore::active_lock('worktree-workspace-capacity-admission', 'workspace-capacity-admission');
	lease_assert(is_array($active) && 901 === ($active['heartbeat_age_seconds'] ?? null), 'Owner must remain DB-visible after the default 15-minute lease when its operation deadline is still live.');
	lease_assert($expected === ($active['metadata']['expected_release_at'] ?? null), 'Long-running owner must retain its live operation ETA.');
	lease_assert(1099 === ($active['expires_in_seconds'] ?? null), 'Explicit operation ETA must cap the DB lease instead of extending to a default lease window.');
	$renewed = WorkspaceLockStore::heartbeat($id, array( 'expected_release_at' => gmdate('c', 4000) ));
	lease_assert(true === $renewed, 'Healthy owner heartbeat renewal failed.');
	$renewed_active = WorkspaceLockStore::active_lock('worktree-workspace-capacity-admission', 'workspace-capacity-admission');
	lease_assert(0 === ($renewed_active['heartbeat_age_seconds'] ?? null), 'Heartbeat renewal did not refresh DB-visible liveness.');
	lease_assert(gmdate('c', 4000) === ($renewed_active['metadata']['expected_release_at'] ?? null), 'Heartbeat renewal did not refresh the DB-visible operation ETA.');
	lease_assert(2099 === ($renewed_active['expires_in_seconds'] ?? null), 'Renewal must use the declared operation ETA as its exact expiry.');
	$GLOBALS['workspace_lock_test_time'] = 4001;
	lease_assert(false === WorkspaceLockStore::heartbeat($id, array( 'expected_release_at' => gmdate('c', 4000) )), 'Over-deadline heartbeat renewal must not revive an expired allocation ETA.');
	lease_assert(null === WorkspaceLockStore::active_lock('worktree-workspace-capacity-admission', 'workspace-capacity-admission'), 'Expired owner operation deadline must remain bounded for stale recovery.');
	WorkspaceLockStore::release($id);
	lease_assert(false === WorkspaceLockStore::heartbeat($id, array()), 'Conditional heartbeat must report false when no active row was updated.');

	// This exercises the allocation lifecycle against the DB-visible lock row,
	// not the filesystem-only fallback used by lock primitive tests.
	$workspace = sys_get_temp_dir() . '/dmc-allocation-heartbeat-' . bin2hex(random_bytes(6));
	mkdir($workspace, 0777, true);
	$allocation_time = time();
	$GLOBALS['workspace_lock_test_time'] = $allocation_time;
	$started  = microtime(true);
	$deadline = $started + 35.0;
	$lock = WorkspaceMutationLock::acquire($workspace, 'workspace-capacity-admission', 1, array( 'expected_release_at' => gmdate('c', (int) ceil($deadline)) ));
	lease_assert($lock instanceof WorkspaceMutationLock, 'Could not acquire the DB-backed allocation heartbeat lock.');
	$before_heartbeat = WorkspaceLockStore::active_lock('worktree-workspace-capacity-admission', 'workspace-capacity-admission');
	lease_assert(is_array($before_heartbeat) && 0 === ($before_heartbeat['heartbeat_age_seconds'] ?? null), 'DB-backed allocation lock did not expose its initial heartbeat.');
	$GLOBALS['workspace_lock_test_time'] = $allocation_time + 5;
	$lifecycle = new Workspace_Lock_Lease_Lifecycle_Harness();
	lease_assert(null === $lifecycle->heartbeat($lock, 'bootstrap', $deadline, 35, $started), 'A virtual allocation phase beyond 30 seconds must renew its DB-backed heartbeat.');
	$active = WorkspaceLockStore::active_lock('worktree-workspace-capacity-admission', 'workspace-capacity-admission');
	lease_assert(is_array($active) && gmdate('Y-m-d H:i:s', $allocation_time + 5) === ($active['heartbeat_at'] ?? null) && ($before_heartbeat['heartbeat_at'] ?? null) !== ($active['heartbeat_at'] ?? null) && 0 === ($active['heartbeat_age_seconds'] ?? null), 'Lifecycle heartbeat did not refresh the active DB lock timestamp after virtual time advanced.');
	lease_assert('bootstrap' === ($active['metadata']['capacity_phase'] ?? null) && gmdate('c', (int) ceil($deadline)) === ($active['metadata']['expected_release_at'] ?? null), 'Lifecycle heartbeat did not persist the active phase and ETA.');
	$pdo->exec("UPDATE wp_datamachine_code_locks SET status = 'released' WHERE lock_key = 'worktree-workspace-capacity-admission' AND scope = 'workspace-capacity-admission' AND status = 'active'");
	$lost = $lifecycle->heartbeat($lock, 'bootstrap_complete', $deadline, 35, $started);
	lease_assert($lost instanceof WP_Error && 'workspace_capacity_lock_heartbeat_lost' === $lost->get_error_code() && 'bootstrap_complete' === ($lost->get_error_data()['phase'] ?? null), 'Lost DB heartbeat must abort allocation with typed lifecycle evidence.');
	$lock->release();
	foreach (glob($workspace . '/.locks/requests/*.json') ?: array() as $file) { @unlink($file); }
	@rmdir($workspace . '/.locks/requests'); @unlink($workspace . '/.locks/worktree-workspace-capacity-admission.lock'); @rmdir($workspace . '/.locks'); @rmdir($workspace);
	echo "workspace-lock-lease-renewal ok\n";
} finally {
	@unlink($database);
}
