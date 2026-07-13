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

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $GLOBALS['sqlite_retry_filters'][ $hook ] ?? $value;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Storage\WorktreeInventoryRepository;

final class Sqlite_Contention_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';

	public function __construct( private PDO $pdo ) {}
	public function db_server_info(): string { return 'SQLite ' . $this->pdo->query('SELECT sqlite_version()')->fetchColumn(); }
	public function replace( string $table, array $data ): int|false {
		try {
			$statement = $this->pdo->prepare('INSERT INTO wp_datamachine_code_worktrees (handle, metadata) VALUES (:handle, :metadata) ON CONFLICT(handle) DO UPDATE SET metadata = excluded.metadata');
			$statement->execute(array( ':handle' => $data['handle'], ':metadata' => $data['metadata'] ));
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
	$count = (int) $setup->query('SELECT COUNT(*) FROM wp_datamachine_code_worktrees')->fetchColumn();
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
