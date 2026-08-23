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
		public static function get_metadata( string $key ): ?array {
			$metadata = array( 'lifecycle_state' => 'active' );
			if ( str_contains($key, 'branch-300') || str_contains($key, 'branch-301') ) {
				$metadata['task'] = 'duplicate-task';
				$metadata['origin_task'] = array( 'task_ref' => 'tracker-42' );
				$metadata['owner_run_ref'] = str_contains($key, 'branch-300') ? 'run-alpha' : 'run-beta';
			}
			return $metadata;
		}
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
	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value ): string|false { return json_encode($value); }
	}
	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
	}

	require_once dirname(__DIR__) . '/inc/Support/ListCursor.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

	use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;

	final class BoundedWorktreeListHarness {
		use WorkspaceWorktreeLifecycle { worktree_list_insert_bounded_row as private insert_bounded_row; }

		public int $expensive_probes = 0;
		public int $max_bounded_rows = 0;
		public function __construct( private string $workspace_path ) {}
		private function parse_handle( string $handle ): array {
			$parts = explode('@', $handle, 2);
			return array( 'repo' => $parts[0], 'branch_slug' => $parts[1] ?? null );
		}
		private function sanitize_name( string $name ): string { return trim($name); }
		private function worktree_get( string $handle, array $opts ): WP_Error { return new WP_Error( 'worktree_not_found' ); }
		private function run_git( string $path, string $command ): array {
			if ( 'worktree list --porcelain' === $command ) {
				$blocks = array( "worktree {$this->workspace_path}/repo\nHEAD primary\nbranch refs/heads/main" );
				for ( $index = 0; $index < 338; ++$index ) {
					$branch = 330 === $index ? 'main' : sprintf('branch-%03d', $index);
					$blocks[] = sprintf("worktree %s/repo@branch-%03d\nHEAD %040d\nbranch refs/heads/%s", $this->workspace_path, $index, $index, $branch);
				}
				return array( 'output' => implode("\n\n", $blocks) );
			}
			++$this->expensive_probes;
			return array( 'output' => '' );
		}
		private function count_unpushed_commits( string $path ): int { ++$this->expensive_probes; return 0; }
		private function build_primary_freshness_report( string $path, string $handle ): array { ++$this->expensive_probes; return array( 'status' => 'current' ); }
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
		$task_filtered = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'task_ref' => 'tracker-42', 'limit' => 1 ));
		bounded_worktree_assert(2 === $task_filtered['total'] && 'repo@branch-300' === ($task_filtered['worktrees'][0]['handle'] ?? null), 'Task filtering must select exact aggregate task references before pagination.');
		$task_next = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'task_ref' => 'tracker-42', 'limit' => 1, 'cursor' => $task_filtered['next_cursor'] ));
		bounded_worktree_assert('repo@branch-301' === ($task_next['worktrees'][0]['handle'] ?? null), 'Task-filtered cursors must preserve aggregate pagination.');
		$intersection = $harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'task_ref' => 'tracker-42', 'owner_run_ref' => 'run-alpha', 'limit' => 50 ));
		bounded_worktree_assert(1 === $intersection['total'] && 'repo@branch-300' === ($intersection['worktrees'][0]['handle'] ?? null), 'Task and owner filters must intersect exactly.');
		bounded_worktree_assert(is_wp_error($harness->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false, 'task_ref' => 'tracker-42', 'owner_run_ref' => 'run-alpha', 'limit' => 1, 'cursor' => $task_filtered['next_cursor'] ))), 'A cursor must reject changed task or owner filters.');
		$missing = $harness->worktree_list(null, null, array( 'handle' => 'repo@missing', 'include_status' => false, 'include_disk' => false, 'limit' => 50 ));
		bounded_worktree_assert(0 === $missing['total'] && 0 === $missing['returned'] && null === $missing['next_cursor'] && array() === $missing['summary']['repos'], 'Missing handles must return the advertised empty envelope shape.');

		$with_status = $harness->worktree_list(null, null, array( 'include_status' => true, 'include_disk' => false, 'limit' => 2 ));
		bounded_worktree_assert(true === $with_status['status_requested'] && 5 === $harness->expensive_probes, 'Explicit status requests must probe only returned rows, including primary freshness.');
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
