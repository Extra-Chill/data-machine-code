<?php

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate('Y-m-d H:i:s');
}

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['sqlite_test_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['sqlite_test_options'][ $name ] = $value;
	return true;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $GLOBALS['sqlite_retry_filters'][ $hook ] ?? $value;
}

function dbDelta( string $sql ): array {
	$columns = $GLOBALS['sqlite_schema_pdo']->query('PRAGMA table_info(wp_datamachine_code_worktrees)')->fetchAll(PDO::FETCH_ASSOC);
	$names   = array_column($columns, 'name');
	foreach ( array( 'purpose' => 'TEXT', 'owner_run_ref' => 'TEXT', 'cleanup_policy' => 'TEXT' ) as $column => $type ) {
		if ( ! in_array($column, $names, true) ) {
			$GLOBALS['sqlite_schema_pdo']->exec("ALTER TABLE wp_datamachine_code_worktrees ADD COLUMN {$column} {$type} DEFAULT NULL");
		}
	}
	return array();
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Storage\WorktreeInventoryRepository;

final class Sqlite_Contention_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';

	public function __construct( private PDO $pdo ) {}
	public function db_server_info(): string { return 'SQLite ' . $this->pdo->query('SELECT sqlite_version()')->fetchColumn(); }
	public function get_charset_collate(): string { return ''; }
	public function replace( string $table, array $data ): int|false {
		if ( in_array(null, $data, true) ) {
			$this->last_error = "Unknown column '' in 'field list'";
			return false;
		}
		try {
			$statement = $this->pdo->prepare('INSERT INTO wp_datamachine_code_worktrees (handle, metadata, purpose, owner_run_ref, cleanup_policy) VALUES (:handle, :metadata, :purpose, :owner_run_ref, :cleanup_policy) ON CONFLICT(handle) DO UPDATE SET metadata = excluded.metadata, purpose = excluded.purpose, owner_run_ref = excluded.owner_run_ref, cleanup_policy = excluded.cleanup_policy');
			$statement->execute(array(
				':handle'         => $data['handle'],
				':metadata'       => $data['metadata'],
				':purpose'        => $data['purpose'] ?? null,
				':owner_run_ref'  => $data['owner_run_ref'] ?? null,
				':cleanup_policy' => $data['cleanup_policy'] ?? null,
			));
			$this->last_error = '';
			return 1;
		} catch ( PDOException $error ) {
			$this->last_error = $error->getMessage();
			return false;
		}
	}
}

function sqlite_contention_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function sqlite_contention_worker( string $database, string $handle, int $max_wait_ms ): void {
	$GLOBALS['sqlite_retry_filters'] = array( 'datamachine_code_sqlite_busy_retry_max_wait_ms' => $max_wait_ms );
	$pdo = new PDO('sqlite:' . $database);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA busy_timeout = 0');
	$GLOBALS['wpdb'] = new Sqlite_Contention_Wpdb($pdo);
	$repository = new WorktreeInventoryRepository();
	$ok = $repository->upsert(array( 'handle' => $handle, 'repo' => 'repo', 'path' => '/tmp/' . $handle, 'metadata' => array( 'handle' => $handle ) ));
	$error = $repository->last_error();
	fwrite(STDOUT, json_encode(array( 'ok' => $ok, 'error' => $error instanceof WP_Error ? array( 'code' => $error->get_error_code(), 'data' => $error->get_error_data() ) : null )) . "\n");
}

if ( '--worker' === ( $argv[1] ?? '' ) ) {
	sqlite_contention_worker((string) $argv[2], (string) $argv[3], (int) $argv[4]);
	exit(0);
}

final class Mysql_Contract_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = 'database is locked';
	public int $replace_calls = 0;
	public function db_server_info(): string { return 'MySQL 8.4'; }
	public function replace( string $table, array $data ): false { ++$this->replace_calls; return false; }
}

function sqlite_contention_start_worker( string $database, string $handle, int $max_wait_ms ): array {
	$command = array(PHP_BINARY, __FILE__, '--worker', $database, $handle, (string) $max_wait_ms);
	$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
	if ( ! is_resource($process) ) {
		throw new RuntimeException('Could not start SQLite contention worker.');
	}
	return array( $process, $pipes );
}

function sqlite_contention_finish_worker( array $worker ): array {
	[ $process, $pipes ] = $worker;
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($process);
	sqlite_contention_assert(0 === $exit, 'SQLite contention worker failed: ' . $error);
	$result = json_decode(trim($output), true);
	sqlite_contention_assert(is_array($result), 'SQLite contention worker returned invalid JSON: ' . $output);
	return $result;
}

$database = tempnam(sys_get_temp_dir(), 'dmc-sqlite-contention-');
if ( false === $database ) {
	throw new RuntimeException('Could not allocate SQLite test database.');
}

try {
	$setup = new PDO('sqlite:' . $database);
	$setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$setup->exec('CREATE TABLE wp_datamachine_code_worktrees (handle TEXT PRIMARY KEY, metadata TEXT NOT NULL)');
	$GLOBALS['sqlite_schema_pdo'] = $setup;
	$GLOBALS['wpdb']              = new Sqlite_Contention_Wpdb($setup);
	$repository                   = new WorktreeInventoryRepository();
	$repository::install_schema();
	$columns = $setup->query('PRAGMA table_info(wp_datamachine_code_worktrees)')->fetchAll(PDO::FETCH_COLUMN, 1);
	sqlite_contention_assert(array( 'purpose', 'owner_run_ref', 'cleanup_policy' ) === array_values(array_intersect($columns, array( 'purpose', 'owner_run_ref', 'cleanup_policy' ))), 'Schema version upgrade must add lifecycle-intent columns to version-1 inventory tables.');
	sqlite_contention_assert(WorktreeInventoryRepository::SCHEMA_VERSION === get_option('datamachine_code_worktrees_schema_version'), 'Schema upgrade must record the current inventory version.');
	sqlite_contention_assert($repository->upsert(array( 'handle' => 'repo@intent', 'repo' => 'repo', 'path' => '/tmp/intent', 'purpose' => 'integration-test', 'owner_run_ref' => 'run-1014', 'cleanup_policy' => 'remove_on_success', 'metadata' => array( 'handle' => 'repo@intent' ) )), 'SQLite upsert with lifecycle intent should succeed after schema migration.');
	$intent = $setup->query('SELECT purpose, owner_run_ref, cleanup_policy FROM wp_datamachine_code_worktrees WHERE handle = "repo@intent"')->fetch(PDO::FETCH_ASSOC);
	sqlite_contention_assert(array( 'purpose' => 'integration-test', 'owner_run_ref' => 'run-1014', 'cleanup_policy' => 'remove_on_success' ) === $intent, 'SQLite upsert must persist lifecycle intent columns after schema migration.');

	// A short exclusive writer lock forces independent CLI processes through retry.
	$setup->exec('BEGIN EXCLUSIVE');
	$workers = array();
	foreach ( range(1, 4) as $number ) {
		$workers[] = sqlite_contention_start_worker($database, 'repo@short-' . $number, 1000);
	}
	usleep(150000);
	$setup->exec('COMMIT');
	foreach ( $workers as $worker ) {
		$result = sqlite_contention_finish_worker($worker);
		sqlite_contention_assert(true === $result['ok'], 'Brief SQLite lock should succeed within the bounded retry budget.');
	}
	$count = (int) $setup->query('SELECT COUNT(*) FROM wp_datamachine_code_worktrees WHERE handle LIKE "repo@short-%"')->fetchColumn();
	sqlite_contention_assert(4 === $count, 'Brief contention created duplicate or missing registry rows.');

	$setup->exec('BEGIN EXCLUSIVE');
	$prolonged = sqlite_contention_start_worker($database, 'repo@prolonged', 50);
	$result = sqlite_contention_finish_worker($prolonged);
	$setup->exec('COMMIT');
	sqlite_contention_assert(false === $result['ok'], 'Prolonged SQLite lock must not report success.');
	sqlite_contention_assert('workspace_sqlite_lock_contention' === ( $result['error']['code'] ?? '' ), 'Prolonged SQLite lock must return the structured contention error.');
	sqlite_contention_assert('sqlite' === ( $result['error']['data']['backend'] ?? '' ), 'Contention diagnostic must identify the SQLite backend.');
	$count = (int) $setup->query('SELECT COUNT(*) FROM wp_datamachine_code_worktrees WHERE handle = "repo@prolonged"')->fetchColumn();
	sqlite_contention_assert(0 === $count, 'Exhausted retry must not leave a partial registry row.');

	$mysql = new Mysql_Contract_Wpdb();
	$GLOBALS['wpdb'] = $mysql;
	$repository = new WorktreeInventoryRepository();
	sqlite_contention_assert(false === $repository->upsert(array( 'handle' => 'repo@mysql', 'path' => '/tmp/mysql' )), 'Non-SQLite failure should preserve the existing false return.');
	sqlite_contention_assert(1 === $mysql->replace_calls, 'MySQL/non-SQLite path must not add SQLite retry attempts.');

	fwrite(STDOUT, "sqlite-worktree-inventory-contention ok\n");
} finally {
	@unlink($database);
}
