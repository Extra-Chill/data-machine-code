<?php
/**
 * High-cardinality bounded worktree inventory contract.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorktreeContextInjector {
		public const VALID_STATES = array( 'active' );
		public static function normalize_state( string $state ): ?string { return 'active' === strtolower(trim($state)) ? 'active' : null; }
		public static function project_lifecycle_state( array $metadata ): ?string { return self::normalize_state((string) ( $metadata['lifecycle_state'] ?? '' )); }
		public static function get_metadata( string $key ): ?array { $task = ! empty($GLOBALS['dmc_task_every_row']) || str_contains($key, 'branch-300') || str_contains($key, 'branch-301'); return array( 'lifecycle_state' => 'active' ) + ( $task ? array( 'task' => 'duplicate-task', 'origin_task' => array( 'task_url' => 'https://github.com/example/repo/issues/300', 'task_ref' => 'example/repo#300' ), 'owner_run_ref' => 'run-300' ) : array() ); }
		public static function classify_liveness( ?array $metadata ): array { return array( 'liveness' => 'unknown', 'reason' => 'metadata_missing', 'heartbeat_age_seconds' => null ); }
		public static function summarize_owner( ?array $metadata ): array { return array( 'site' => 'unknown', 'agent' => 'unknown', 'user' => 'unknown' ); }
		public static function summarize_session( ?array $metadata ): array { return array( 'primary_id' => null, 'ids' => array() ); }
		public static function find_duplicate_task_ownership( array $worktrees ): array { return array(); }
		public static function task_ownership_keys( array $row, array $metadata ): array { return isset($metadata['task']) ? array( 'task_ref' => (string) $metadata['task'] ) : array(); }
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	require_once dirname(__DIR__) . '/inc/Workspace/TaskUrl.php';
	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value ): string|false { return json_encode($value); }
	}
	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): array { return $this->data; }
	}

	require_once dirname(__DIR__) . '/inc/Support/ListCursor.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

	use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;

	final class BoundedWorktreeListHarness {
		use WorkspaceWorktreeLifecycle { worktree_list_insert_bounded_row as private insert_bounded_row; }

		public int $expensive_probes = 0;
		public int $inventory_probes = 0;
		public int $task_inventory_queries = 0;
		public int $max_bounded_rows = 0;
		public bool $fail_probes = false;
		public bool $timeout_inventory_probe = false;
		public bool $advance_probe_clock = false;
		public float $probe_clock = 0.0;
		public function __construct( private string $workspace_path ) {}
		private function parse_handle( string $handle ): array {
			$parts = explode('@', $handle, 2);
			return array( 'repo' => $parts[0], 'branch_slug' => $parts[1] ?? null );
		}
		private function sanitize_name( string $name ): string { return trim($name); }
		private function worktree_get( string $handle, array $opts ): array|WP_Error { if ( 'repo@branch-300' !== $handle ) { return new WP_Error( 'worktree_not_found' ); } $metadata = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata($handle); if ( ! empty($opts['task_ref']) && \DataMachineCode\Workspace\TaskUrl::canonicalize($opts['task_ref']) !== \DataMachineCode\Workspace\TaskUrl::canonicalize($metadata['origin_task']['task_url'] ?? null) ) { return array( 'worktrees' => array() ); } if ( $this->fail_probes && ! empty($opts['include_status']) ) { throw new RuntimeException('A mismatched handle must not start a status probe.'); } return array( 'worktrees' => array( array( 'handle' => $handle, 'metadata' => $metadata, 'lifecycle_state' => 'active' ) ) ); }
		private function run_git( string $path, string $command, int $timeout_seconds = 0 ): array|WP_Error {
			if ( 'worktree list --porcelain' === $command ) {
				++$this->inventory_probes;
				if ( $this->timeout_inventory_probe ) { return new WP_Error( 'git_command_timeout', 'Synthetic timed-out inventory probe.', array( 'timeout' => $timeout_seconds, 'cleanup' => array( 'verified' => true ) ) ); }
				$blocks = array( "worktree {$this->workspace_path}/repo\nHEAD primary\nbranch refs/heads/main" );
				for ( $index = 0; $index < 338; ++$index ) {
					$branch = 330 === $index ? 'main' : sprintf('branch-%03d', $index);
					$blocks[] = sprintf("worktree %s/repo@branch-%03d\nHEAD %040d\nbranch refs/heads/%s", $this->workspace_path, $index, $index, $branch);
				}
				return array( 'output' => implode("\n\n", $blocks) );
			}
			if ( $this->fail_probes ) { throw new RuntimeException('A task overflow must not run a probe.'); }
			if ( $this->advance_probe_clock ) { $this->probe_clock += 0.6; }
			++$this->expensive_probes;
			return array( 'output' => '' );
		}
		private function count_unpushed_commits( string $path ): int { if ( $this->advance_probe_clock ) { $this->probe_clock += 0.6; } ++$this->expensive_probes; return 0; }
		private function build_primary_freshness_report( string $path, string $handle ): array { if ( $this->advance_probe_clock ) { $this->probe_clock += 0.6; } ++$this->expensive_probes; return array( 'status' => 'current' ); }
		protected function worktree_list_budget_clock(): ?callable { return $this->advance_probe_clock ? fn(): float => $this->probe_clock : null; }
		protected function worktree_list_task_inventory_rows( string $task_ref, int $limit ): array {
			++$this->task_inventory_queries;
			if ( 'https://github.com/example/repo/issues/998' === $task_ref ) { return array( array( 'handle' => 'repo@stale-task-owner', 'repo' => 'repo' ) ); }
			if ( 'https://github.com/example/repo/issues/300' !== $task_ref ) { return array(); }
			$count = ! empty($GLOBALS['dmc_task_every_row']) ? 339 : 2;
			$rows  = array();
			for ( $index = 0; $index < min($count, $limit); ++$index ) {
				$branch = ! empty($GLOBALS['dmc_task_every_row']) ? $index : 300 + $index;
				$rows[] = array( 'handle' => sprintf('repo@branch-%03d', $branch), 'repo' => 'repo' );
			}
			return $rows;
		}
		private function calculate_age_days( ?string $created_at ): ?int { return null; }
		protected function detect_worktree_stale_reason( bool $is_worktree, int $dirty, ?int $age, ?string $created, array $probes = array() ): ?string { return null; }
		protected function worktree_list_insert_bounded_row( array &$rows, array $row, int $limit ): void {
			$this->insert_bounded_row($rows, $row, $limit);
			$this->max_bounded_rows = max($this->max_bounded_rows, count($rows));
		}
	}

	function bounded_worktree_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}

	$workspace = sys_get_temp_dir() . '/dmc-worktree-list-' . bin2hex(random_bytes(4));
	mkdir($workspace . '/repo', 0700, true);
	file_put_contents($workspace . '/repo/.git', 'gitdir: /tmp/none');
	try {
		$harness = new BoundedWorktreeListHarness($workspace);
		$started = microtime(true);
		$first = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 50 ));
		$elapsed = microtime(true) - $started;
		bounded_worktree_assert(339 === $first['total'] && 50 === $first['returned'], 'Default worktree list must return a bounded first page and complete total.');
		bounded_worktree_assert(50 >= $harness->max_bounded_rows, 'Bounded worktree listing must retain no more than one page of candidates.');
		bounded_worktree_assert(1 === ($first['summary']['primary'] ?? null) && 338 === ($first['summary']['worktree'] ?? null), 'Summary must represent the complete inventory before pagination.');
		bounded_worktree_assert(is_string($first['next_cursor']), 'A bounded worktree page must provide a continuation cursor.');
		$legacy_cursor = rtrim(strtr(base64_encode(wp_json_encode(array( 'v' => 1, 'after' => "repo@branch-048\0{$workspace}/repo@branch-048", 'repo' => null, 'state' => null, 'handle' => '' ))), '+/', '-_'), '=');
		bounded_worktree_assert($legacy_cursor === $first['next_cursor'], 'Shared cursor encoding must preserve the existing serialized worktree cursor.');
		bounded_worktree_assert(1 === ($first['summary']['repo_count'] ?? null) && 1 === ($first['summary']['duplicate_task_groups_total'] ?? null) && 1 === ($first['summary']['base_branch_worktrees_total'] ?? null), 'Global summary diagnostics must include rows beyond the first page.');
		bounded_worktree_assert(1 === ($first['summary']['repos'][0]['primary'] ?? null) && 338 === ($first['summary']['repos'][0]['worktree'] ?? null), 'Selected repository summaries must aggregate every matching worktree.');
		bounded_worktree_assert(array( 'repo@branch-300', 'repo@branch-301' ) === (($first['duplicates'][0]['handles'] ?? null)), 'Duplicate task diagnostics must include off-page handles.');
		bounded_worktree_assert('repo@branch-330' === ($first['base_branch_worktrees'][0]['handle'] ?? null), 'Base branch diagnostics must include off-page worktrees.');
		bounded_worktree_assert(0 === $harness->expensive_probes, 'Default worktree discovery must skip status, unpushed, disk, and freshness probes.');
		bounded_worktree_assert($elapsed < 2.0, sprintf('Bounded worktree response exceeded deadline: %.3fs.', $elapsed));

		$second = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 50, 'cursor' => $legacy_cursor ));
		bounded_worktree_assert('repo@branch-049' === ($second['worktrees'][0]['handle'] ?? null), 'Cursor continuation must resume after the stable first page.');
		$wrong_scope = $harness->worktree_list('other', null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 50, 'cursor' => $first['next_cursor'] ));
		bounded_worktree_assert(is_wp_error($wrong_scope), 'A cursor must reject changed worktree filters.');
		$normalized = $harness->worktree_list(' repo ', 'ACTIVE', array( 'include_status' => false, 'include_disk' => false, 'limit' => 50 ));
		$normalized_next = $harness->worktree_list('repo', 'active', array( 'include_status' => false, 'include_disk' => false, 'limit' => 50, 'cursor' => $normalized['next_cursor'] ));
		bounded_worktree_assert('repo@branch-050' === ($normalized_next['worktrees'][0]['handle'] ?? null), 'Cursor validation must use normalized repository and state filters.');
		$probes_before_task = $harness->expensive_probes;
		$inventory_before_task = $harness->inventory_probes;
		$task_candidates = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 1, 'task_ref' => 'https://github.com/example/repo/issues/300?source=homeboy#candidate', 'owner_run_ref' => 'run-300' ));
		bounded_worktree_assert(2 === $task_candidates['total'] && 'repo@branch-300' === ($task_candidates['worktrees'][0]['handle'] ?? null), 'Task and owner filters must select candidates beyond the first unfiltered page.');
		bounded_worktree_assert($probes_before_task === $harness->expensive_probes, 'Task and owner filtering must run before requested probes.');
		bounded_worktree_assert($inventory_before_task + 1 === $harness->inventory_probes && 1 === $harness->task_inventory_queries, 'Task lookup must enumerate Git worktrees only for inventory-selected repositories.');
		$unrelated = array();
		for ( $index = 0; $index < 205; ++$index ) {
			$path = sprintf('%s/unrelated-%03d', $workspace, $index);
			mkdir($path);
			file_put_contents($path . '/.git', 'gitdir: /tmp/none');
			$unrelated[] = $path;
		}
		$inventory_before_absent = $harness->inventory_probes;
		$started_absent = microtime(true);
		$absent = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'limit' => 200, 'task_ref' => 'https://github.com/example/repo/issues/999' ));
		$absent_elapsed = microtime(true) - $started_absent;
		bounded_worktree_assert(0 === $absent['total'] && $inventory_before_absent === $harness->inventory_probes, 'An absent task must not enumerate any unrelated Git repository.');
		bounded_worktree_assert($absent_elapsed < 0.5, sprintf('Absent task lookup exceeded its bounded inventory deadline: %.3fs.', $absent_elapsed));
		$stale = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'limit' => 200, 'task_ref' => 'https://github.com/example/repo/issues/998' ));
		bounded_worktree_assert(0 === $stale['total'], 'A stale inventory candidate absent from the repository worktree list must not be returned as a task owner.');
		foreach ( $unrelated as $path ) {
			unlink($path . '/.git');
			rmdir($path);
		}
		$task_next = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 1, 'task_ref' => 'https://github.com/example/repo/issues/300', 'owner_run_ref' => 'run-300', 'cursor' => $task_candidates['next_cursor'] ));
		bounded_worktree_assert('repo@branch-301' === ($task_next['worktrees'][0]['handle'] ?? null), 'Task-filtered cursor continuation must be deterministic.');
		bounded_worktree_assert(is_wp_error($harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 1, 'task_ref' => 'https://github.com/example/repo/issues/300', 'owner_run_ref' => 'other-run', 'cursor' => $task_candidates['next_cursor'] ))), 'Task cursor must reject a changed owner scope.');
		$handle_match = $harness->worktree_list(null, null, array( 'handle' => 'repo@branch-300', 'include_status' => false, 'include_disk' => false, 'task_ref' => ' https://github.com/example/repo/issues/300/?source=provider#candidate ', 'owner_run_ref' => 'run-300' ));
		bounded_worktree_assert(1 === $handle_match['total'], 'Exact handle lookup must accept its canonical task and owner identity.');
		$handle_mismatch = $harness->worktree_list(null, null, array( 'handle' => 'repo@branch-300', 'include_status' => false, 'include_disk' => false, 'task_ref' => 'https://github.com/example/repo/issues/other', 'owner_run_ref' => 'run-300' ));
		bounded_worktree_assert(0 === $handle_mismatch['total'], 'Exact handle lookup must enforce a mismatched task filter.');
		$harness->fail_probes = true;
		$handle_mismatch_status = $harness->worktree_list(null, null, array( 'handle' => 'repo@branch-300', 'include_status' => true, 'include_disk' => false, 'task_ref' => 'https://github.com/example/repo/issues/other', 'owner_run_ref' => 'run-300' ));
		$harness->fail_probes = false;
		bounded_worktree_assert(0 === $handle_mismatch_status['total'], 'A mismatched exact handle must be rejected before its requested status probe.');
		$GLOBALS['dmc_task_every_row'] = true;
		$harness->fail_probes = true;
		$overflow = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'all' => true, 'task_ref' => 'https://github.com/example/repo/issues/300', 'owner_run_ref' => 'run-300' ));
		$harness->fail_probes = false;
		unset($GLOBALS['dmc_task_every_row']);
		bounded_worktree_assert(is_wp_error($overflow) && 'worktree_task_candidates_overflow' === $overflow->get_error_code(), 'The wp-coding-agents complete task lookup must overflow before any status probe can run.');
		$harness->timeout_inventory_probe = true;
		$timed_out_inventory = $harness->worktree_list('repo', null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 1 ));
		$harness->timeout_inventory_probe = false;
		bounded_worktree_assert(is_wp_error($timed_out_inventory) && 'worktree_list_probe_timeout' === $timed_out_inventory->get_error_code(), 'A timed-out filtered inventory probe must return a typed error.');
		bounded_worktree_assert(5 === ($timed_out_inventory->get_error_data()['timeout_seconds'] ?? null) && 'worktree_inventory' === ($timed_out_inventory->get_error_data()['phase'] ?? null) && true === ($timed_out_inventory->get_error_data()['cleanup']['verified'] ?? null), 'A timed-out inventory probe must retain its budget, phase, and cleanup evidence.');
		$harness->timeout_inventory_probe = true;
		$shared_timeout = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'all' => true, 'wall_clock_budget' => \DataMachineCode\Support\WallClockBudget::from_seconds(5) ));
		$harness->timeout_inventory_probe = false;
		bounded_worktree_assert(! is_wp_error($shared_timeout) && true === ($shared_timeout['partial'] ?? false), 'A repository timeout under the shared hygiene budget must preserve a typed partial envelope.');
		bounded_worktree_assert('repo' === ($shared_timeout['diagnostics']['inventory_probe_failures'][0]['repo'] ?? null) && 'repository_probe_timeout' === ($shared_timeout['diagnostics']['partial_reason'] ?? null), 'Shared hygiene timeout evidence must identify the failed repository without changing targeted list errors.');
		$missing = $harness->worktree_list(null, null, array( 'handle' => 'repo@missing', 'include_status' => false, 'include_disk' => false, 'limit' => 50 ));
		bounded_worktree_assert(0 === $missing['total'] && 0 === $missing['returned'] && null === $missing['next_cursor'] && array() === $missing['summary']['repos'], 'Missing handles must return the advertised empty envelope shape.');

		$with_status = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'limit' => 2 ));
		bounded_worktree_assert(true === $with_status['status_requested'] && 5 === $harness->expensive_probes, 'Explicit status requests must probe only returned rows, including primary freshness.');
		$harness->advance_probe_clock = true;
		$harness->probe_clock         = 0.0;
		$slow_partial = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'limit' => 2, 'until_budget' => '1s' ));
		$harness->advance_probe_clock = false;
		bounded_worktree_assert(true === ($slow_partial['partial'] ?? false) && 339 === ($slow_partial['total'] ?? null), 'Slow requested probes must return a typed partial envelope without losing the complete 339-row inventory total.');
		bounded_worktree_assert('status' === ($slow_partial['diagnostics']['phase'] ?? null) && 'budget_exhausted_status' === ($slow_partial['continuation']['reason'] ?? null), 'Slow probe exhaustion must identify its stage and return continuation evidence.');
		$harness->advance_probe_clock = true;
		$harness->probe_clock         = 0.0;
		$progress                     = array();
		$shared_budget                = \DataMachineCode\Support\WallClockBudget::from_seconds(1, '1s', fn(): float => $harness->probe_clock);
		$all_status_partial           = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'all' => true, 'wall_clock_budget' => $shared_budget, 'progress_callback' => static function ( array $event ) use ( &$progress ): void { $progress[] = $event; } ));
		$harness->advance_probe_clock = false;
		bounded_worktree_assert(true === ($all_status_partial['partial'] ?? false) && 339 === ($all_status_partial['total'] ?? null), 'A shared hygiene budget must bound explicitly requested all-row status hydration without discarding the complete cheap inventory.');
		bounded_worktree_assert('worktree_inventory' === ($progress[0]['phase'] ?? null) && 'repo' === ($progress[0]['repository'] ?? null) && 'status' === ($progress[1]['phase'] ?? null), 'All-row status progress must name the repository and phase before each expensive probe.');
		$all = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'all' => true ));
		bounded_worktree_assert(339 === $all['returned'] && null === $all['next_cursor'], 'Explicit all must retain exhaustive inventory access.');
		$all_with_cursor = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'all' => true, 'cursor' => $first['next_cursor'] ));
		bounded_worktree_assert(is_wp_error($all_with_cursor), 'All and cursor must be rejected as an ambiguous worktree pagination request.');
		$cursor = null;
		$handles = array();
		do {
			$page = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => 50 ) + ( null === $cursor ? array() : array( 'cursor' => $cursor ) ));
			$handles = array_merge($handles, array_column($page['worktrees'], 'handle'));
			$cursor = $page['next_cursor'];
		} while ( null !== $cursor );
		bounded_worktree_assert(339 === count($handles) && 339 === count(array_unique($handles)), 'Cursor pages must return every worktree exactly once in stable order.');
		foreach ( array( 0, -1, 201, 1.0, '1.5', 'junk', array( 1 ), true ) as $limit ) {
			bounded_worktree_assert(is_wp_error($harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'limit' => $limit ))), 'Invalid worktree list limits must be rejected before coercion.');
		}
	} finally {
		unlink($workspace . '/repo/.git');
		rmdir($workspace . '/repo');
		rmdir($workspace);
	}

	echo "worktree-list-bounded-contract: ok\n";
}
