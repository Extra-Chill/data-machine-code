<?php
/**
 * High-cardinality and concurrent-mutation coverage for hygiene inventory.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorktreeContextInjector {
		public const METADATA_OPTION = 'datamachine_worktree_metadata';
		public const LIVENESS_LIVE = 'live';
		public const LIVENESS_STOPPED = 'stopped';
		public const LIVENESS_STALE = 'stale';
		public const LIVENESS_UNKNOWN = 'unknown';
		public static function classify_liveness( ?array $metadata ): array { return array( 'liveness' => 'unknown', 'reason' => 'fixture', 'heartbeat_age_seconds' => null ); }
		public static function summarize_owner( ?array $metadata ): array { return array(); }
		public static function summarize_session( ?array $metadata ): array { return array(); }
		public static function find_duplicate_task_ownership( array $rows ): array { return array(); }
	}

	final class WorkspaceMutationLock {
		public static function status( string $workspace_path, mixed $budget = null ): array {
			return array( 'active' => 0, 'stale' => 0, 'partial' => false );
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

	function get_option( string $name, mixed $default = false ): mixed { return $default; }

	require_once dirname(__DIR__) . '/inc/Support/WallClockBudget.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHygieneReport.php';

	use DataMachineCode\Support\WallClockBudget;
	use DataMachineCode\Workspace\WorkspaceHygieneReport;

	final class HygieneInventoryRepositoryFixture {
		public int $list_calls = 0;
		public function __construct( private PDO $pdo ) {}
		public function list(): array {
			++$this->list_calls;
			$rows = $this->pdo->query('SELECT handle, metadata FROM inventory ORDER BY handle')->fetchAll(PDO::FETCH_ASSOC);
			return array_map(
				static fn( array $row ): array => array( 'handle' => $row['handle'], 'metadata' => json_decode($row['metadata'], true) ),
				$rows
			);
		}
		public function freshness_from_rows( array $rows ): array { return array( 'total_rows' => count($rows), 'missing_paths' => 0, 'last_probe_at' => null ); }
	}

	final class HygieneInventoryHarness {
		use WorkspaceHygieneReport;

		public const HYGIENE_DEFAULT_SIZE_LIMIT = 1000;
		public const HYGIENE_DEFAULT_SIZE_ENTRY_TIMEOUT = 5;
		public const HYGIENE_DEFAULT_SIZE_TOTAL_TIMEOUT = 30;

		public function __construct( private string $workspace_path, private HygieneInventoryRepositoryFixture $repository ) {}
		private function worktree_inventory(): HygieneInventoryRepositoryFixture { return $this->repository; }
		private function parse_handle( string $handle ): array {
			$parts = explode('@', $handle, 2);
			return array( 'repo' => $parts[0], 'is_worktree' => isset($parts[1]), 'branch_slug' => $parts[1] ?? '', 'dir_name' => $handle );
		}
		private function classify_workspace_entry_kind( string $entry, array $parsed, string $path ): string { return $parsed['is_worktree'] ? 'worktree' : 'primary'; }
		private function workspace_entry_git_marker_state( string $kind, string $path ): string { return 'present'; }
		private function base_branch_worktree_warning( array $row ): ?array { return null; }
		private function build_workspace_disk_report( ?array $size_report = null, ?WallClockBudget $budget = null, ?int $worktree_count = null ): array { return array( 'status' => 'unknown', 'partial' => false ); }
		public function snapshot( WallClockBudget $budget, ?callable $progress = null ): array {
			$method = new ReflectionMethod($this, 'build_workspace_inventory_snapshot');
			return $method->invoke($this, $budget, $progress);
		}
		public function report( callable $progress ): array|WP_Error { return $this->workspace_hygiene_report(array( 'include_cleanup' => false, 'progress_callback' => $progress, 'until_budget' => '2s' )); }
	}

	function hygiene_inventory_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	function hygiene_inventory_remove_tree( string $path ): void {
		foreach ( scandir($path) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $path . '/' . $entry;
			is_dir($child) ? hygiene_inventory_remove_tree($child) : unlink($child);
		}
		rmdir($path);
	}

	function hygiene_inventory_wait_for_file( string $path ): void {
		$deadline = microtime(true) + 2;
		while ( ! is_file($path) && microtime(true) < $deadline ) {
			usleep(10000);
		}
		if ( ! is_file($path) ) {
			throw new RuntimeException('Timed out waiting for process signal.');
		}
	}

	function hygiene_inventory_writer( string $database, string $ready, string $go ): void {
		$writer = new PDO('sqlite:' . $database);
		$writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$writer->exec('PRAGMA busy_timeout = 0');
		file_put_contents($ready, 'ready');
		hygiene_inventory_wait_for_file($go);
		$observed = (int) $writer->query("SELECT COUNT(*) FROM inventory WHERE handle = 'repo-000@task'")->fetchColumn();
		$writer->exec("INSERT INTO inventory (handle, metadata) VALUES ('concurrent@mutation', '{}')");
		echo 'read=' . $observed . " inserted\n";
	}

	if ( '--writer' === ( $argv[1] ?? '' ) ) {
		hygiene_inventory_writer((string) $argv[2], (string) $argv[3], (string) $argv[4]);
		exit(0);
	}

	$database  = tempnam(sys_get_temp_dir(), 'dmc-hygiene-inventory-');
	$workspace = sys_get_temp_dir() . '/dmc-hygiene-inventory-' . bin2hex(random_bytes(5));
	if ( false === $database ) {
		throw new RuntimeException('Could not allocate SQLite fixture.');
	}
	mkdir($workspace, 0700, true);
	try {
		$reader = new PDO('sqlite:' . $database);
		$reader->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$reader->exec('PRAGMA busy_timeout = 0');
		$reader->exec('CREATE TABLE inventory (handle TEXT PRIMARY KEY, metadata TEXT NOT NULL)');
		$insert = $reader->prepare('INSERT INTO inventory (handle, metadata) VALUES (?, ?)');
		for ( $index = 0; $index < 200; ++$index ) {
			$handle = 'repo-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '@task';
			mkdir($workspace . '/' . $handle, 0700, true);
			file_put_contents($workspace . '/' . $handle . '/.git', 'gitdir: /tmp/fixture');
			$insert->execute(array( $handle, json_encode(array( 'branch' => 'task-' . $index, 'created_at' => '2026-08-25T00:00:00Z' )) ));
		}

		$repository = new HygieneInventoryRepositoryFixture($reader);
		$harness    = new HygieneInventoryHarness($workspace, $repository);
		$ready      = $workspace . '/writer-ready';
		$go         = $workspace . '/writer-go';
		$process    = proc_open(array( PHP_BINARY, __FILE__, '--writer', $database, $ready, $go ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		hygiene_inventory_assert(is_resource($process), 'Could not start concurrent SQLite mutation process.');
		hygiene_inventory_wait_for_file($ready);
		$events        = array();
		$writer_proved = false;
		$started       = microtime(true);
		$result        = $harness->report(
			function ( array $event ) use ( &$events, &$writer_proved, $go, $process, $pipes ): void {
				$events[] = $event;
				if ( ! $writer_proved && 'filesystem_inventory' === $event['phase'] ) {
					file_put_contents($go, 'go');
					$output = stream_get_contents($pipes[1]);
					$error  = stream_get_contents($pipes[2]);
					fclose($pipes[1]);
					fclose($pipes[2]);
					$writer_proved = 0 === proc_close($process) && 'read=1 inserted' === trim($output) && '' === trim($error);
				}
			}
		);
		hygiene_inventory_assert(200 === ($result['worktrees']['worktrees'] ?? null) && ! $result['partial'], 'A full 200-worktree hygiene report should complete as one bounded pass.');
		hygiene_inventory_assert(1 === $repository->list_calls, 'Hygiene must read lifecycle metadata in one DB snapshot.');
		hygiene_inventory_assert($writer_proved && 201 === (int) $reader->query('SELECT COUNT(*) FROM inventory')->fetchColumn(), 'A concurrent lifecycle mutation must remain responsive during filesystem inventory.');
		hygiene_inventory_assert('database_snapshot' === $events[0]['phase'] && 'repo-000' === $events[1]['repository'], 'Progress must identify the DB phase before naming the current repository.');
		hygiene_inventory_assert(count($events) <= 15, 'High-cardinality progress must be throttled rather than emitting one callback per row.');
		hygiene_inventory_assert(microtime(true) - $started < 2, 'High-cardinality hygiene exceeded its wall-clock bound.');

		$throwing_callback = $harness->snapshot(
			WallClockBudget::from_seconds(2),
			static function (): void { throw new RuntimeException('presentation failure'); }
		);
		hygiene_inventory_assert(200 === count($throwing_callback['rows']) && ! $throwing_callback['partial'], 'Progress callback failures must not alter hygiene results.');

		$partial = $harness->snapshot(
			WallClockBudget::from_seconds(0.01),
			static function ( array $event ): void {
				if ( 'filesystem_inventory' === $event['phase'] ) {
					usleep(2000);
				}
			}
		);
		hygiene_inventory_assert($partial['partial'] && count($partial['rows']) < 200, 'A slow inventory must return bounded partial rows.');
		hygiene_inventory_assert('report_budget_exhausted' === $partial['diagnostics']['budget_exhaustion_reason'] && null !== $partial['diagnostics']['next_entry'], 'Partial inventory must expose typed aggregate-timeout and continuation evidence.');

		echo "workspace-hygiene-bounded-inventory: ok\n";
	} finally {
		hygiene_inventory_remove_tree($workspace);
		@unlink($database);
	}
}
