<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';
define('ABSPATH', __DIR__ . '/fixtures/');

final class WP_Error {
	public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $GLOBALS['filters'][$hook] ?? $value; }

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;

final class Lock_Contention_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public function __construct(private PDO $pdo) {}
	public function db_server_info(): string { return 'SQLite'; }
	public function prepare(string $query, mixed ...$args): string { foreach ($args as $arg) { $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : $this->pdo->quote((string) $arg), $query, 1); } return $query; }
	public function get_var(string $query): mixed { if (str_contains($query, 'SHOW TABLES')) { return 'wp_datamachine_code_locks'; } try { $this->last_error = ''; return $this->pdo->query($query)->fetchColumn(); } catch (PDOException $e) { $this->last_error = $e->getMessage(); return false; } }
	public function get_col(string $query): array { try { $this->last_error = ''; return $this->pdo->query($query)->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) { $this->last_error = $e->getMessage(); return array(); } }
	public function get_results(string $query, string $format): array { try { $this->last_error = ''; return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { $this->last_error = $e->getMessage(); return array(); } }
	public function insert(string $table, array $data, array $formats): int|false { try { $columns = array_keys($data); $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'; $this->pdo->prepare($sql)->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId(); $this->last_error = ''; return 1; } catch (PDOException $e) { $this->last_error = $e->getMessage(); return false; } }
	public function update(string $table, array $data, array $where, array $formats, array $where_formats): int|false { return 1; }
}
function lock_sqlite_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function lock_sqlite_worker(string $mode, string $database, string $workspace): void {
	$GLOBALS['filters'] = array('datamachine_code_sqlite_busy_retry_max_wait_ms' => 'admit' === $mode ? 1000 : 100);
	$pdo = new PDO('sqlite:' . $database); $pdo->exec('PRAGMA busy_timeout = 0'); $GLOBALS['wpdb'] = new Lock_Contention_Wpdb($pdo);
	if ('status' === $mode) { fwrite(STDOUT, json_encode(WorkspaceMutationLock::status($workspace))); return; }
	$result = WorkspaceMutationLock::with_repo($workspace, 'repo', static fn(): string => 'acquired', 1);
	fwrite(STDOUT, json_encode(is_wp_error($result) ? array('error' => $result->get_error_code(), 'data' => $result->get_error_data()) : array('success' => true)));
}
if ('--worker' === ($argv[1] ?? '')) { lock_sqlite_worker((string) $argv[2], (string) $argv[3], (string) $argv[4]); exit; }

$database = tempnam(sys_get_temp_dir(), 'dmc-lock-sqlite-'); $workspace = sys_get_temp_dir() . '/dmc-lock-sqlite-' . bin2hex(random_bytes(6)); mkdir($workspace);
try {
	$setup = new PDO('sqlite:' . $database); $setup->exec('CREATE TABLE wp_datamachine_code_locks (id INTEGER PRIMARY KEY, lock_key TEXT, purpose TEXT, scope TEXT, owner TEXT, run_id TEXT, job_id INTEGER, status TEXT, acquired_at TEXT, heartbeat_at TEXT, expires_at TEXT, released_at TEXT, metadata_json TEXT)');
	$setup->exec('BEGIN EXCLUSIVE');
	$admission = proc_open(array(PHP_BINARY, __FILE__, '--worker', 'admit', $database, $workspace), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $admission_pipes);
	usleep(50000);
	$status = proc_open(array(PHP_BINARY, __FILE__, '--worker', 'status', $database, $workspace), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $status_pipes);
	$status_output = stream_get_contents($status_pipes[1]); $status_error = stream_get_contents($status_pipes[2]); fclose($status_pipes[1]); fclose($status_pipes[2]); lock_sqlite_assert(0 === proc_close($status), 'Status worker failed: ' . $status_error);
	$status_result = json_decode($status_output, true); lock_sqlite_assert('contended' === ($status_result['database']['state'] ?? ''), 'SQLite contention was not projected by status.'); lock_sqlite_assert(1 === count($status_result['queue'] ?? array()), 'Filesystem queue was unavailable during SQLite contention.');
	$admission_output = stream_get_contents($admission_pipes[1]); fclose($admission_pipes[1]); fclose($admission_pipes[2]); lock_sqlite_assert(0 === proc_close($admission), 'Admission worker failed.');
	$setup->exec('COMMIT'); $admission_result = json_decode($admission_output, true); lock_sqlite_assert('workspace_sqlite_lock_contention' === ($admission_result['error'] ?? ''), 'Admission did not return retryable SQLite contention.'); lock_sqlite_assert(array() === (glob($workspace . '/.locks/requests/*.json') ?: array()), 'Failed admission left a request record.'); lock_sqlite_assert(0 === (int) $setup->query('SELECT COUNT(*) FROM wp_datamachine_code_locks')->fetchColumn(), 'Failed admission left a DB lock row.');
	echo "workspace-lock-sqlite-contention ok\n";
} finally { foreach (glob($workspace . '/.locks/requests/*') ?: array() as $file) { @unlink($file); } @rmdir($workspace . '/.locks/requests'); foreach (glob($workspace . '/.locks/*') ?: array() as $file) { @unlink($file); } @rmdir($workspace . '/.locks'); @rmdir($workspace); @unlink($database); }
