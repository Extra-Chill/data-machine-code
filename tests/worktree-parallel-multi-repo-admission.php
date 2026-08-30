<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}
if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}
if ( ! function_exists('wp_json_encode') ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }
}
if ( ! function_exists('apply_filters') ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( 'datamachine_worktree_disk_budget_thresholds' === $hook ) {
			return array_merge((array) $value, array( 'refuse_free_bytes' => 0, 'refuse_free_percent' => 0, 'refuse_free_inodes' => 0, 'refuse_free_inode_percent' => 0 ));
		}
		return $value;
	}
}
if ( ! function_exists('get_option') ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['dmc_parallel_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists('update_option') ) {
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['dmc_parallel_options'][ $name ] = $value;
		return true;
	}
}
$GLOBALS['dmc_parallel_options'] = $GLOBALS['dmc_parallel_options'] ?? array();

if ( ! function_exists('current_time') ) {
	function current_time( string $type, bool $gmt = false ): string { return gmdate('Y-m-d H:i:s'); }
}
if ( ! function_exists('home_url') ) {
	function home_url(): string { return 'https://example.test'; }
}
if ( ! function_exists('get_bloginfo') ) {
	function get_bloginfo( string $show = '' ): string { return 'DMC Test'; }
}
if ( ! function_exists('wp_generate_password') ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string { return str_repeat('a', $length); }
}
if ( ! function_exists('dbDelta') ) {
	function dbDelta( string $sql ): array { return array(); }
}

const ARRAY_A = 'ARRAY_A';

final class Dmc_Parallel_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	/** @var array<string,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $lock_rows = array();

	public function get_charset_collate(): string { return ''; }
	public function db_server_info(): string { return 'MySQL 8.4'; }
	public function replace( string $table, array $data ): int|false {
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
	public function get_results( string $sql, string $output = ARRAY_A ): array { return array_values($this->rows); }
	public function get_row( string $sql, string $output = ARRAY_A ): ?array {
		foreach ( $this->rows as $handle => $row ) {
			if ( str_contains($sql, (string) $handle) ) {
				return $row;
			}
		}
		return null;
	}
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace('/%[is]/', addslashes((string) $arg), $query, 1) ?? $query;
		}
		return $query;
	}
	public function query( string $sql ): int|false { return 1; }
	public function get_var( string $sql ): string|int|null {
		return str_contains($sql, 'SHOW TABLES LIKE') ? $this->prefix . ( str_contains($sql, 'datamachine_code_locks') ? 'datamachine_code_locks' : 'datamachine_code_worktrees' ) : 0;
	}
	public function get_col( string $sql ): array { return array(); }
}

function parallel_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function parallel_remove_tree( string $path ): void {
	if ( ! is_dir($path) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

function parallel_run( string $command, string $cwd ): void {
	$output = array();
	$code   = 0;
	exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $code);
	parallel_assert(0 === $code, sprintf('Command failed (%d): %s\n%s', $code, $command, implode("\n", $output)));
}

function parallel_create_repo( string $workspace, string $repo ): void {
	$origin = $workspace . '/origin.git';
	$path   = $workspace . '/' . $repo;
	if ( ! is_dir($origin) ) {
		parallel_run('git init --bare ' . escapeshellarg($origin), $workspace);
		$source = $workspace . '/source';
		mkdir($source, 0777, true);
		parallel_run('git init -b main', $source);
		parallel_run('git config user.email test@example.test', $source);
		parallel_run('git config user.name "DMC Test"', $source);
		file_put_contents($source . '/README.md', "fixture\n");
		parallel_run('git add README.md && git commit -m initial && git remote add origin ' . escapeshellarg($origin) . ' && git push -u origin main', $source);
		parallel_run('git symbolic-ref HEAD refs/heads/main', $origin);
	}
	parallel_run('git clone ' . escapeshellarg($origin) . ' ' . escapeshellarg($path), $workspace);
	parallel_run('git config user.email test@example.test', $path);
	parallel_run('git config user.name "DMC Test"', $path);
	parallel_run('git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main', $path);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';
require_once __DIR__ . '/support/bootstrap.php';

use DataMachineCode\Workspace\WorktreeContextInjector;
use DataMachineCode\Workspace\WorkspaceMutationLock;

$mode = $argv[1] ?? 'test';
if ( 'add' === $mode ) {
	$workspace = (string) $argv[2];
	$repo      = (string) $argv[3];
	$branch    = (string) $argv[4];
	$events    = (string) $argv[5];
	if ( ! defined('DATAMACHINE_WORKSPACE_PATH') ) {
		define('DATAMACHINE_WORKSPACE_PATH', $workspace);
	}
	$GLOBALS['wpdb'] = new Dmc_Parallel_Wpdb();
	require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';
	$started = microtime(true);
	$result  = ( new DataMachineCode\Workspace\Workspace() )->worktree_add_request(
		dmc_test_allocation_request(
			$repo,
			$branch,
			'origin/main',
			false,
			false,
			false,
			false,
			false,
			array(),
			true,
			false,
			array(),
			'reuse_compatible',
			false,
			false,
			static function ( array $event ) use ( $events, $repo ): void {
				$phase = (string) ( $event['phase'] ?? '' );
				if ( 'git_worktree_add' !== $phase ) {
					return;
				}
				file_put_contents($events, wp_json_encode(array( 'repo' => $repo, 'mark' => 'start', 'at' => microtime(true) )) . "\n", FILE_APPEND | LOCK_EX);
				usleep(750000);
				file_put_contents($events, wp_json_encode(array( 'repo' => $repo, 'mark' => 'end', 'at' => microtime(true) )) . "\n", FILE_APPEND | LOCK_EX);
			}
		)
	);
	if ( is_wp_error($result) ) {
		fwrite(STDOUT, 'error:' . $result->get_error_code() . ':' . $result->get_error_message());
		exit(2);
	}
	fwrite(STDOUT, wp_json_encode(array( 'ok' => true, 'handle' => $result['handle'] ?? null, 'elapsed' => microtime(true) - $started )));
	exit(0);
}

$workspace = sys_get_temp_dir() . '/dmc-parallel-admission-' . bin2hex(random_bytes(6));
mkdir($workspace, 0700, true);

try {
	$reserved = WorktreeContextInjector::reserve_capacity($workspace, 'repo-a@one', array( 'bytes' => 100, 'inodes' => 10 ));
	parallel_assert(true === $reserved, 'Admission reservation did not persist.');
	$snapshot = WorktreeContextInjector::admission_capacity_reservations($workspace);
	parallel_assert(100 === $snapshot['bytes'] && 10 === $snapshot['inodes'] && array( 'repo-a@one' ) === $snapshot['handles'], 'Live admission reservation was not visible to the next inspect.');
	WorktreeContextInjector::set_bootstrap_owner_probe_for_test(static fn( int $pid ): array => array( 'state' => 'stale', 'reason' => 'owner_process_missing' ));
	$stale = WorktreeContextInjector::admission_capacity_reservations($workspace);
	parallel_assert(0 === $stale['bytes'] && 0 === $stale['inodes'], 'Stale admission reservation remained capacity charged.');
	WorktreeContextInjector::set_bootstrap_owner_probe_for_test(null);
	WorktreeContextInjector::release_capacity_reservation($workspace, 'repo-a@one');
	$released = WorktreeContextInjector::admission_capacity_reservations($workspace);
	parallel_assert(0 === $released['bytes'] && array() === $released['handles'], 'Released admission reservation remained visible.');

	if ( ! defined('DATAMACHINE_WORKSPACE_PATH') ) {
		define('DATAMACHINE_WORKSPACE_PATH', $workspace);
	}
	$GLOBALS['wpdb'] = new Dmc_Parallel_Wpdb();
	require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';

	$repos = array( 'repo-a', 'repo-b', 'repo-c' );
	foreach ( $repos as $repo ) {
		parallel_create_repo($workspace, $repo);
	}

	$events = $workspace . '/checkout-events.jsonl';
	file_put_contents($events, '');
	$workers = array();
	foreach ( $repos as $repo ) {
		$process = proc_open(
			array( PHP_BINARY, __FILE__, 'add', $workspace, $repo, 'parallel-' . $repo, $events ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		parallel_assert(is_resource($process), 'Could not start parallel worktree add for ' . $repo);
		fclose($pipes[0]);
		$workers[ $repo ] = array( $process, $pipes );
	}
	foreach ( $workers as $repo => [ $process, $pipes ] ) {
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		parallel_assert(0 === $status, 'Parallel worktree add failed for ' . $repo . ': ' . $output . ' ' . $error);
		$decoded = json_decode((string) $output, true);
		parallel_assert(is_array($decoded) && true === ( $decoded['ok'] ?? false ), 'Parallel worktree add omitted success evidence for ' . $repo . ': ' . $output);
		parallel_assert(is_dir($workspace . '/' . $repo . '@parallel-' . $repo), 'Parallel worktree add did not provision ' . $repo);
	}
	$event_log = (string) file_get_contents($events);
	$windows   = array();
	foreach ( array_filter(explode("\n", trim($event_log))) as $line ) {
		$row = json_decode($line, true);
		if ( ! is_array($row) ) {
			continue;
		}
		$windows[ (string) $row['repo'] ][ (string) $row['mark'] ] = (float) $row['at'];
	}
	parallel_assert(3 === count($windows), 'Checkout overlap evidence missing for one or more repositories: ' . $event_log);
	$checkout_starts = array_map(static fn( array $window ): float => (float) ( $window['start'] ?? 0.0 ), $windows);
	parallel_assert(max($checkout_starts) - min($checkout_starts) < 1.2, 'Independent repositories waited too long to begin checkout after admission: ' . $event_log);
	$overlapped = false;
	$names      = array_keys($windows);
	foreach ( $names as $index => $left ) {
		foreach ( array_slice($names, $index + 1) as $right ) {
			$left_start  = $windows[ $left ]['start'] ?? 0.0;
			$left_end    = $windows[ $left ]['end'] ?? 0.0;
			$right_start = $windows[ $right ]['start'] ?? 0.0;
			$right_end   = $windows[ $right ]['end'] ?? 0.0;
			if ( $left_start < $right_end && $right_start < $left_end ) {
				$overlapped = true;
			}
		}
	}
	parallel_assert($overlapped, 'Independent repository checkouts did not overlap after capacity admission: ' . $event_log);
	parallel_assert(array() === ( glob($workspace . '/.locks/capacity-reservations/*.json') ?: array() ), 'Successful allocations left admission reservations behind.');

	echo "worktree-parallel-multi-repo-admission: ok\n";
} finally {
	parallel_remove_tree($workspace);
}
