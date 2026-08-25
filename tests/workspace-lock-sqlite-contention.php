<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';
define('ABSPATH', __DIR__ . '/fixtures/');

final class WP_Error {
	public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
	public function add_data(mixed $data): void { $this->data = $data; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $GLOBALS['filters'][$hook] ?? $value; }
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['lock_sqlite_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['lock_sqlite_options'][$name] = $value; return true; }

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;
use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeContextInjector;

final class Lock_Contention_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	private bool $errors_suppressed = false;

	public function __construct(private PDO $pdo, private bool $advertise_sqlite = true) {}
	public function db_server_info(): string { return $this->advertise_sqlite ? 'SQLite' : 'MySQL'; }
	public function suppress_errors(bool $suppress = true): bool { $previous = $this->errors_suppressed; $this->errors_suppressed = $suppress; return $previous; }
	public function prepare(string $query, mixed ...$args): string {
		foreach ($args as $arg) {
			if (!preg_match('/%[sdi]/', $query, $match)) { break; }
			$replacement = '%i' === $match[0] ? (string) $arg : ('%d' === $match[0] ? (string) (int) $arg : $this->pdo->quote((string) $arg));
			$query = preg_replace('/%[sdi]/', $replacement, $query, 1);
		}
		return $query;
	}
	public function get_var(string $query): mixed {
		if (str_contains($query, 'SHOW TABLES')) { return 'wp_datamachine_code_locks'; }
		try { $this->last_error = ''; return $this->pdo->query($query)->fetchColumn(); } catch (PDOException $error) { return $this->failed($error); }
	}
	public function get_col(string $query): array { try { $this->last_error = ''; return $this->pdo->query($query)->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $error) { $this->failed($error); return array(); } }
	public function get_results(string $query, string $format): array { try { $this->last_error = ''; return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $error) { $this->failed($error); return array(); } }
	public function get_row(string $query, string $format): array|false { try { $this->last_error = ''; return $this->pdo->query($query)->fetch(PDO::FETCH_ASSOC); } catch (PDOException $error) { return $this->failed($error); } }
	public function insert(string $table, array $data, array $formats): int|false {
		try {
			$columns = array_keys($data);
			$sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
			$this->pdo->prepare($sql)->execute(array_values($data));
			$this->insert_id = (int) $this->pdo->lastInsertId();
			$this->last_error = '';
			return 1;
		} catch (PDOException $error) { return $this->failed($error); }
	}
	public function update(string $table, array $data, array $where, array $formats, array $where_formats): int|false {
		try {
			$sets = implode(',', array_map(static fn(string $column): string => $column . ' = ?', array_keys($data)));
			$terms = implode(' AND ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($where)));
			$statement = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $terms);
			$statement->execute(array_merge(array_values($data), array_values($where)));
			$this->last_error = '';
			return $statement->rowCount();
		} catch (PDOException $error) { return $this->failed($error); }
	}
	public function replace(string $table, array $data): int|false {
		try {
			$statement = $this->pdo->prepare('INSERT INTO ' . $table . ' (handle, repo, path, lifecycle_state, metadata) VALUES (?, ?, ?, ?, ?) ON CONFLICT(handle) DO UPDATE SET repo = excluded.repo, path = excluded.path, lifecycle_state = excluded.lifecycle_state, metadata = excluded.metadata');
			$statement->execute(array($data['handle'], $data['repo'] ?? '', $data['path'] ?? '', $data['lifecycle_state'] ?? null, $data['metadata'] ?? '{}'));
			$this->last_error = '';
			return 1;
		} catch (PDOException $error) { return $this->failed($error); }
	}
	private function failed(PDOException $error): false {
		$this->last_error = $error->getMessage();
		if (!$this->errors_suppressed) { fwrite(STDOUT, '<div class="sqlite-error">' . $this->last_error . '</div>'); }
		return false;
	}
}

function lock_sqlite_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function lock_sqlite_result(mixed $result): array { return is_wp_error($result) ? array('error' => $result->get_error_code(), 'data' => $result->get_error_data()) : array('success' => $result); }
function lock_sqlite_wait(string $path): void { $deadline = microtime(true) + 5; while (!is_file($path) && microtime(true) < $deadline) { usleep(10000); } if (!is_file($path)) { throw new RuntimeException('Timed out waiting for contention signal: ' . $path); } }

function lock_sqlite_worker(array $args): void {
	[$mode, $database, $workspace, $repo, $max_wait_ms] = $args;
	$GLOBALS['filters'] = 'default' === $max_wait_ms ? array() : array('datamachine_code_sqlite_busy_retry_max_wait_ms' => (int) $max_wait_ms);
	$pdo = new PDO('sqlite:' . $database);
	$pdo->exec('PRAGMA busy_timeout = 0');
	$GLOBALS['wpdb'] = new Lock_Contention_Wpdb($pdo, 'decorated-acquire' !== $mode);

	if ('allocation' === $mode) {
		$result = WorkspaceMutationLock::with_repo($workspace, $repo, static function (WorkspaceMutationLock $lock) use ($workspace, $repo): mixed {
			$heartbeat = $lock->heartbeat(array('contention_phase' => 'allocation'));
			if (is_wp_error($heartbeat)) { return $heartbeat; }
			file_put_contents($workspace . '/' . $repo . '-allocated', 'allocated');
			return 'allocated';
		}, 2);
		fwrite(STDOUT, json_encode(lock_sqlite_result($result)));
		return;
	}

	if ('hygiene' === $mode) {
		fwrite(STDOUT, json_encode(array('success' => WorkspaceMutationLock::status($workspace))));
		return;
	}

	if ('lifecycle' === $mode || 'unsafe-lifecycle' === $mode) {
		if (!defined('DATAMACHINE_WORKSPACE_PATH')) { define('DATAMACHINE_WORKSPACE_PATH', $workspace); }
		putenv('DATAMACHINE_TASK_URL=HTTPS://Example.TEST:443/tracker/1247/?source=environment');
		putenv('DATAMACHINE_TASK_REF=environment#1247');
		$task = 'unsafe-lifecycle' === $mode ? array( 'task_url' => 'https://token:must-not-leak@example.test/tracker/1247' ) : array();
		$result = (new Workspace())->worktree_add(
			$repo,
			"contention/quote'path;safe",
			"refs/heads/base/quote'path;safe",
			false,
			false,
			true,
			true,
			false,
			$task,
			true,
			true,
			array( 'purpose' => "review'purpose", 'owner_run_ref' => 'run;1247', 'cleanup_policy' => 'remove_on_success' ),
			'isolated',
			true
		);
		fwrite(STDOUT, json_encode(lock_sqlite_result($result)));
		return;
	}

	if ('finalize' === $mode) {
		if (!defined('DATAMACHINE_WORKSPACE_PATH')) { define('DATAMACHINE_WORKSPACE_PATH', $workspace); }
		$result = (new Workspace())->worktree_finalize($repo . '@finalize-contention', WorktreeContextInjector::STATE_PR_OPENED, 'https://example.test/pull/1250', 'success');
		fwrite(STDOUT, json_encode(lock_sqlite_result($result)));
		return;
	}

	if ('discovery' === $mode) {
		if (!defined('DATAMACHINE_WORKSPACE_PATH')) { define('DATAMACHINE_WORKSPACE_PATH', $workspace); }
		$result = (new Workspace())->worktree_get($repo . '@finalize-contention', array('include_status' => false, 'include_disk' => false));
		fwrite(STDOUT, json_encode(lock_sqlite_result($result)));
		return;
	}

	if ('acquire' === $mode || 'decorated-acquire' === $mode) {
		fwrite(STDOUT, json_encode(lock_sqlite_result(WorkspaceMutationLock::acquire($workspace, $repo, 1))));
		return;
	}

	$ready = (string) ($args[5] ?? '');
	$go = (string) ($args[6] ?? '');
	if ('handoff' === $mode) {
		$result = WorkspaceMutationLock::with_repo($workspace, $repo, static function () use ($ready, $go): string {
			file_put_contents($ready, 'ready');
			lock_sqlite_wait($go);
			return 'mutation-complete';
		}, 1);
		fwrite(STDOUT, json_encode(lock_sqlite_result($result)));
		return;
	}
	if ('heartbeat-handoff' === $mode) {
		$result = WorkspaceMutationLock::with_repo($workspace, $repo, static function (WorkspaceMutationLock $lock) use ($ready, $go): mixed {
			file_put_contents($ready, 'ready');
			lock_sqlite_wait($go);
			return $lock->heartbeat(array('contention_phase' => 'admitted'));
		}, 1);
		fwrite(STDOUT, json_encode(lock_sqlite_result($result)));
		return;
	}

	$lock = WorkspaceMutationLock::acquire($workspace, $repo, 1);
	if (is_wp_error($lock)) { fwrite(STDOUT, json_encode(array('acquire' => lock_sqlite_result($lock)))); return; }
	file_put_contents($ready, 'ready');
	lock_sqlite_wait($go);
	$phase = 'heartbeat' === $mode ? $lock->heartbeat(array('contention_phase' => 'heartbeat')) : true;
	$release = $lock->release();
	fwrite(STDOUT, json_encode(array('phase' => lock_sqlite_result($phase), 'release' => lock_sqlite_result($release))));
}

if ('--worker' === ($argv[1] ?? '')) { lock_sqlite_worker(array_slice($argv, 2)); exit; }

function lock_sqlite_start(array $arguments): array {
	$process = proc_open(array_merge(array(PHP_BINARY, __FILE__, '--worker'), $arguments), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	lock_sqlite_assert(is_resource($process), 'Could not start SQLite contention worker.');
	return array($process, $pipes);
}
function lock_sqlite_finish(array $worker): array {
	[$process, $pipes] = $worker;
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	lock_sqlite_assert(0 === proc_close($process), 'SQLite contention worker failed: ' . $error);
	lock_sqlite_assert('' === trim($error), 'SQLite contention worker leaked stderr: ' . $error);
	lock_sqlite_assert(!str_contains($output . $error, '<div') && !str_contains(strtolower($output . $error), 'database is locked'), 'Raw SQLite diagnostics escaped the canonical retry boundary: ' . $output . $error);
	$result = json_decode($output, true);
	lock_sqlite_assert(is_array($result), 'SQLite contention worker returned invalid JSON: ' . $output);
	return $result;
}
function lock_sqlite_signal_worker(string $mode, string $database, string $workspace, string $repo, int $max_wait_ms): array {
	$ready = $workspace . '/' . $repo . '-ready';
	$go = $workspace . '/' . $repo . '-go';
	$worker = lock_sqlite_start(array($mode, $database, $workspace, $repo, (string) $max_wait_ms, $ready, $go));
	lock_sqlite_wait($ready);
	return array($worker, $ready, $go);
}
function lock_sqlite_cleanup_signals(string ...$paths): void { foreach ($paths as $path) { @unlink($path); } }
function lock_sqlite_run(array $command, ?string $cwd = null): string {
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
	lock_sqlite_assert(is_resource($process), 'Could not start fixture command: ' . implode(' ', $command));
	$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	lock_sqlite_assert(0 === proc_close($process), 'Fixture command failed: ' . $error);
	return trim($output);
}
function lock_sqlite_remove_tree(string $path): void {
	if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
	foreach (scandir($path) ?: array() as $entry) {
		if ('.' !== $entry && '..' !== $entry) { lock_sqlite_remove_tree($path . '/' . $entry); }
	}
	@rmdir($path);
}

$database = tempnam(sys_get_temp_dir(), 'dmc-lock-sqlite-');
$workspace = sys_get_temp_dir() . '/dmc-lock-sqlite-' . bin2hex(random_bytes(6));
mkdir($workspace);
try {
	$setup = new PDO('sqlite:' . $database);
	$setup->exec('CREATE TABLE wp_datamachine_code_locks (id INTEGER PRIMARY KEY, lock_key TEXT, purpose TEXT, scope TEXT, owner TEXT, run_id TEXT, job_id INTEGER, status TEXT, acquired_at TEXT, heartbeat_at TEXT, expires_at TEXT, released_at TEXT, metadata_json TEXT)');
	$setup->exec('CREATE TABLE wp_datamachine_code_worktrees (handle TEXT PRIMARY KEY, repo TEXT, path TEXT, lifecycle_state TEXT, metadata TEXT)');

	// A hygiene reader and independent allocations must all survive a short
	// shared writer transaction without leaking adapter output.
	$setup->exec('BEGIN EXCLUSIVE');
	$hygiene = lock_sqlite_start(array('hygiene', $database, $workspace, 'hygiene', 'default'));
	$workers = array();
	foreach (range(1, 8) as $number) { $workers[] = lock_sqlite_start(array('allocation', $database, $workspace, 'repo-' . $number, 'default')); }
	usleep(1200000);
	$setup->exec('COMMIT');
	$hygiene_result = lock_sqlite_finish($hygiene);
	lock_sqlite_assert(true === ($hygiene_result['success']['database']['available'] ?? null), 'Concurrent hygiene did not recover after the bounded writer transaction.');
	foreach ($workers as $number => $worker) {
		$result = lock_sqlite_finish($worker);
		lock_sqlite_assert('allocated' === ($result['success'] ?? null), 'Brief multi-process allocation contention did not serialize successfully.');
		lock_sqlite_assert(is_file($workspace . '/repo-' . ($number + 1) . '-allocated'), 'Concurrent ownership was reported before its allocation callback completed.');
	}
	lock_sqlite_assert(8 === (int) $setup->query("SELECT COUNT(*) FROM wp_datamachine_code_locks WHERE status = 'released'")->fetchColumn(), 'Concurrent allocations left missing or active ownership rows.');

	// Exhausted acquisition returns typed retry evidence and releases the OS flock.
	$setup->exec('BEGIN EXCLUSIVE');
	$started = microtime(true);
	$acquire = lock_sqlite_finish(lock_sqlite_start(array('acquire', $database, $workspace, 'acquire-exhausted', '100')));
	lock_sqlite_assert((microtime(true) - $started) < 2, 'Exhausted acquisition exceeded its bounded retry envelope.');
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($acquire['error'] ?? null) && true === ($acquire['data']['retryable'] ?? null) && 'workspace_lock_register' === ($acquire['data']['operation'] ?? null), 'Exhausted acquisition did not retain canonical contention diagnostics.');
	lock_sqlite_assert('workspace_lock_register' === ($acquire['data']['blocker_phase'] ?? null), 'Exhausted acquisition omitted its blocker phase.');
	lock_sqlite_assert(false === ($acquire['data']['lock_callback_started'] ?? null), 'Exhausted acquisition did not prove its mutation callback remained unstarted.');
	lock_sqlite_assert(!isset($acquire['data']['retry_command']), 'Generic lock acquisition invented a worktree allocation command.');
	lock_sqlite_assert(!isset($acquire['data']['owner']['wp_cli_args']), 'Public lock contention retained raw process argv.');
	lock_sqlite_assert(100 === ($acquire['data']['max_wait_ms'] ?? null) && ($acquire['data']['waited_ms'] ?? 0) >= 100, 'Exhausted acquisition did not report its configured retry bound.');
	$raw = fopen($workspace . '/.locks/worktree-acquire-exhausted.lock', 'c');
	lock_sqlite_assert(is_resource($raw) && flock($raw, LOCK_EX | LOCK_NB), 'Failed DB acquisition retained the authoritative OS flock.');
	flock($raw, LOCK_UN); fclose($raw);
	$setup->exec('COMMIT');

	// A decorator can hide the SQLite backend from wpdb capability probes. The
	// failed operation and last_error remain authoritative for bounded retry.
	$setup->exec('BEGIN EXCLUSIVE');
	$decorated = lock_sqlite_finish(lock_sqlite_start(array('decorated-acquire', $database, $workspace, 'decorated-acquire-exhausted', '100')));
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($decorated['error'] ?? null) && 'workspace_lock_register' === ($decorated['data']['operation'] ?? null), 'Decorated SQLite acquisition bypassed canonical contention retry.');
	$setup->exec('COMMIT');

	// The real worktree lifecycle must stop before Git mutation when ownership
	// registration cannot be made durable.
	$primary = $workspace . '/lifecycle-repo';
	mkdir($primary);
	lock_sqlite_run(array('git', 'init', '--initial-branch=main'), $primary);
	lock_sqlite_run(array('git', 'config', 'user.email', 'test@example.test'), $primary);
	lock_sqlite_run(array('git', 'config', 'user.name', 'DMC Test'), $primary);
	lock_sqlite_run(array('git', 'commit', '--allow-empty', '-m', 'fixture'), $primary);
	$branch = "contention/quote'path;safe";
	$from   = "refs/heads/base/quote'path;safe";
	lock_sqlite_run(array('git', 'check-ref-format', 'refs/heads/' . $branch), $primary);
	lock_sqlite_run(array('git', 'check-ref-format', $from), $primary);
	$setup->exec('BEGIN EXCLUSIVE');
	$lifecycle = lock_sqlite_finish(lock_sqlite_start(array('lifecycle', $database, $workspace, $primary, '100')));
	$setup->exec('COMMIT');
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($lifecycle['error'] ?? null) && 'workspace_lock_register' === ($lifecycle['data']['operation'] ?? null), 'Worktree add did not preserve lock-registration contention: ' . json_encode($lifecycle));
	$expected_retry = "wp datamachine-code workspace worktree add 'lifecycle-repo' " . escapeshellarg($branch)
		. ' --from=' . escapeshellarg($from)
		. " --skip-context-injection --skip-bootstrap --allow-stale --allow-unverified-freshness --rebase-base --remediate-capacity --reuse-policy='isolated'"
		. " --task-url='https://example.test/tracker/1247' --task-ref='environment#1247' --require-task-tracker"
		. ' --purpose=' . escapeshellarg("review'purpose") . " --owner-run-ref='run;1247' --cleanup-policy='remove_on_success'";
	lock_sqlite_assert($expected_retry === ($lifecycle['data']['retry_command'] ?? null), 'Lifecycle contention did not retain the normalized environment-resolved allocation request: ' . json_encode($lifecycle));
	lock_sqlite_assert(!is_dir($workspace . '/lifecycle-repo@contention-quote-path-safe'), 'Failed ownership registration allowed a worktree path mutation.');
	lock_sqlite_assert('' === lock_sqlite_run(array('git', 'branch', '--list', $branch), $primary), 'Failed ownership registration allowed a branch mutation.');

	$setup->exec('BEGIN EXCLUSIVE');
	$unsafe_lifecycle = lock_sqlite_finish(lock_sqlite_start(array('unsafe-lifecycle', $database, $workspace, $primary, '100')));
	$setup->exec('COMMIT');
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($unsafe_lifecycle['error'] ?? null) && !isset($unsafe_lifecycle['data']['retry_command']), 'Credential-bearing task identity retained an executable allocation receipt.');
	lock_sqlite_assert(!str_contains(json_encode($unsafe_lifecycle), 'must-not-leak'), 'Credential-bearing task identity leaked through contention diagnostics.');

	// Finalization is owned by the same repository lock as allocation. An
	// exhausted DB admission cannot begin metadata mutation and returns one exact,
	// idempotent replay command. Once contention clears, that replay succeeds.
	$finalize_path = $workspace . '/lifecycle-repo@finalize-contention';
	lock_sqlite_run(array('git', 'worktree', 'add', '-b', 'finalize-contention', $finalize_path, 'HEAD'), $primary);
	$setup->exec('BEGIN EXCLUSIVE');
	$finalize = lock_sqlite_finish(lock_sqlite_start(array('finalize', $database, $workspace, 'lifecycle-repo', '100')));
	$setup->exec('COMMIT');
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($finalize['error'] ?? null) && 'workspace_lock_register' === ($finalize['data']['operation'] ?? null), 'Finalization did not preserve canonical lock-registration contention: ' . json_encode($finalize));
	lock_sqlite_assert(false === ($finalize['data']['lock_callback_started'] ?? null), 'Contended finalization did not prove its metadata callback remained unstarted.');
	$finalize_retry = "wp datamachine-code workspace worktree finalize 'lifecycle-repo@finalize-contention' --state='pr_opened' --pr='https://example.test/pull/1250' --owner-terminal-outcome='success'";
	lock_sqlite_assert($finalize_retry === ($finalize['data']['retry_command'] ?? null), 'Contended finalization omitted its exact normalized replay command: ' . json_encode($finalize));
	$finalize_lock = fopen($workspace . '/.locks/worktree-lifecycle-repo.lock', 'c');
	lock_sqlite_assert(is_resource($finalize_lock) && flock($finalize_lock, LOCK_EX | LOCK_NB), 'Contended finalization retained the authoritative OS flock.');
	flock($finalize_lock, LOCK_UN); fclose($finalize_lock);
	$setup->exec('BEGIN EXCLUSIVE');
	$started = microtime(true);
	$discovery = lock_sqlite_finish(lock_sqlite_start(array('discovery', $database, $workspace, 'lifecycle-repo', '100')));
	$elapsed = microtime(true) - $started;
	$setup->exec('COMMIT');
	lock_sqlite_assert(true === ($discovery['success']['success'] ?? null) && 'lifecycle-repo@finalize-contention' === ($discovery['success']['worktrees'][0]['handle'] ?? null), 'Read-only exact discovery did not survive registry contention: ' . json_encode($discovery));
	lock_sqlite_assert(null === ($discovery['success']['worktrees'][0]['metadata'] ?? null), 'Contended read-only discovery invented unavailable registry metadata.');
	lock_sqlite_assert($elapsed < 2, 'Read-only exact discovery exceeded its bounded registry recovery window.');
	$replayed = lock_sqlite_finish(lock_sqlite_start(array('finalize', $database, $workspace, 'lifecycle-repo', '100')));
	lock_sqlite_assert(true === ($replayed['success']['success'] ?? null) && 'cleanup_eligible' === ($replayed['success']['lifecycle_state'] ?? null) && 'pr_opened' === ($replayed['success']['metadata']['finalized_state'] ?? null), 'Finalization replay did not recover after SQLite contention cleared: ' . json_encode($replayed));

	// Heartbeat retries through a short lock, then reports both writes if exhausted.
	[$worker, $ready, $go] = lock_sqlite_signal_worker('heartbeat', $database, $workspace, 'heartbeat-recovers', 1000);
	$setup->exec('BEGIN EXCLUSIVE'); file_put_contents($go, 'go'); usleep(150000); $setup->exec('COMMIT');
	$heartbeat = lock_sqlite_finish($worker);
	lock_sqlite_assert(true === ($heartbeat['phase']['success'] ?? null) && true === ($heartbeat['release']['success'] ?? null), 'Heartbeat/release did not recover within the retry budget.');
	lock_sqlite_cleanup_signals($ready, $go);

	[$worker, $ready, $go] = lock_sqlite_signal_worker('heartbeat', $database, $workspace, 'heartbeat-exhausted', 100);
	$setup->exec('BEGIN EXCLUSIVE'); file_put_contents($go, 'go');
	$heartbeat = lock_sqlite_finish($worker);
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($heartbeat['phase']['error'] ?? null) && 'workspace_lock_heartbeat' === ($heartbeat['phase']['data']['operation'] ?? null), 'Exhausted heartbeat lost its canonical operation receipt.');
	lock_sqlite_assert('workspace_lock_release' === ($heartbeat['release']['data']['operation'] ?? null) && true === ($heartbeat['release']['data']['filesystem_lock_released'] ?? null), 'Exhausted heartbeat cleanup hid release contention or retained the OS flock.');
	$setup->exec('COMMIT'); lock_sqlite_cleanup_signals($ready, $go);

	// If an admitted callback and its cleanup both contend, preserve the phase
	// failure and attach release evidence instead of replacing it.
	[$worker, $ready, $go] = lock_sqlite_signal_worker('heartbeat-handoff', $database, $workspace, 'heartbeat-handoff', 100);
	$setup->exec('BEGIN EXCLUSIVE'); file_put_contents($go, 'go');
	$heartbeat_handoff = lock_sqlite_finish($worker);
	lock_sqlite_assert('workspace_lock_heartbeat' === ($heartbeat_handoff['data']['operation'] ?? null), 'Automatic release replaced the admitted callback contention receipt.');
	lock_sqlite_assert('workspace_lock_release' === ($heartbeat_handoff['data']['lock_release_error']['operation'] ?? null) && true === ($heartbeat_handoff['data']['lock_release_error']['filesystem_lock_released'] ?? null), 'Admitted callback contention omitted compact release evidence.');
	$setup->exec('COMMIT'); lock_sqlite_cleanup_signals($ready, $go);

	[$worker, $ready, $go] = lock_sqlite_signal_worker('release', $database, $workspace, 'release-recovers', 1000);
	$setup->exec('BEGIN EXCLUSIVE'); file_put_contents($go, 'go'); usleep(150000); $setup->exec('COMMIT');
	$release = lock_sqlite_finish($worker);
	lock_sqlite_assert(true === ($release['release']['success'] ?? null), 'Release did not recover within the bounded retry budget.');
	lock_sqlite_cleanup_signals($ready, $go);

	// A terminal release write is surfaced after callback completion, but cannot
	// keep the filesystem lock authoritative beyond the callback lifetime.
	[$worker, $ready, $go] = lock_sqlite_signal_worker('handoff', $database, $workspace, 'release-exhausted', 100);
	$setup->exec('BEGIN EXCLUSIVE'); file_put_contents($go, 'go');
	$handoff = lock_sqlite_finish($worker);
	lock_sqlite_assert('workspace_sqlite_lock_contention' === ($handoff['error'] ?? null) && 'workspace_lock_release' === ($handoff['data']['operation'] ?? null), 'Terminal release contention was hidden after callback completion.');
	lock_sqlite_assert(true === ($handoff['data']['lock_callback_completed'] ?? null) && true === ($handoff['data']['filesystem_lock_released'] ?? null), 'Terminal release receipt did not distinguish callback completion from OS unlock.');
	$raw = fopen($workspace . '/.locks/worktree-release-exhausted.lock', 'c');
	lock_sqlite_assert(is_resource($raw) && flock($raw, LOCK_EX | LOCK_NB), 'Terminal DB release failure retained the authoritative OS flock.');
	flock($raw, LOCK_UN); fclose($raw);
	$setup->exec('COMMIT'); lock_sqlite_cleanup_signals($ready, $go);

	echo "workspace-lock-sqlite-contention ok\n";
} finally {
	lock_sqlite_remove_tree($workspace);
	@unlink($database);
}
