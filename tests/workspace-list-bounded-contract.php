<?php
/**
 * Deterministic high-cardinality coverage for bounded workspace discovery.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorkspaceAliasResolver {
		public static function context_repositories(): array { return array(); }
		public static function policy_attestation( string $alias ): array { return array(); }
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
	}

	require_once dirname(__DIR__) . '/inc/Support/ListCursor.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceRepositoryLifecycle.php';

	use DataMachineCode\Workspace\WorkspaceRepositoryLifecycle;

	final class BoundedWorkspaceListHarness {
		use WorkspaceRepositoryLifecycle { workspace_list_insert_bounded_row as private insert_bounded_row; }

		public int $git_probes = 0;
		public int $scan_passes = 0;
		public int $max_bounded_rows = 0;
		public function __construct( private string $workspace_path ) {}
		private function require_workspace_visible(): ?WP_Error { return null; }
		private function parse_handle( string $handle ): array {
			$parts = explode('@', $handle, 2);
			return array( 'repo' => $parts[0], 'is_worktree' => isset($parts[1]), 'branch_slug' => $parts[1] ?? '', 'dir_name' => $handle );
		}
		private function git_get_remote( string $path ): ?string { ++$this->git_probes; return 'https://example.test/repo.git'; }
		private function git_get_branch( string $path ): ?string { ++$this->git_probes; return 'main'; }
		private function build_primary_freshness_report( string $path, string $handle ): ?array { ++$this->git_probes; return array( 'status' => 'current' ); }
		protected function workspace_list_insert_bounded_row( array &$rows, array $row, int $limit ): void {
			$this->insert_bounded_row($rows, $row, $limit);
			$this->max_bounded_rows = max($this->max_bounded_rows, count($rows));
		}
		protected function workspace_list_scan_started(): void { ++$this->scan_passes; }
	}

	final class BudgetExhaustedWorkspaceListHarness {
		use WorkspaceRepositoryLifecycle;

		public int $scan_passes = 0;
		public function __construct( private string $workspace_path ) {}
		private function require_workspace_visible(): ?WP_Error { return null; }
		private function parse_handle( string $handle ): array { return array( 'repo' => $handle, 'is_worktree' => false, 'branch_slug' => '', 'dir_name' => $handle ); }
		protected function workspace_list_scan_budget_seconds(): float { return 0.001; }
		protected function workspace_list_scan_started(): void { ++$this->scan_passes; }
		protected function workspace_list_rows( string $path, ?string $repo_filter, ?string $type_filter ): \Generator {
			$this->workspace_list_scan_started();
			for ( $index = 0; $index < 3; ++$index ) {
				usleep(2000);
				yield array( 'name' => 'slow-' . $index, 'path' => $path . '/slow-' . $index, 'git' => true, 'is_worktree' => true, 'repo' => 'slow-' . $index );
			}
		}
	}

	function bounded_list_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}
	function bounded_list_remove_tree( string $path ): void {
		foreach ( scandir($path) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) { continue; }
			$child = $path . '/' . $entry;
			is_dir($child) ? bounded_list_remove_tree($child) : unlink($child);
		}
		rmdir($path);
	}

	$workspace = sys_get_temp_dir() . '/dmc-workspace-list-' . bin2hex(random_bytes(4));
	mkdir($workspace, 0700, true);
	try {
		for ( $index = 0; $index < 338; ++$index ) {
			$path = $workspace . '/repo-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '@branch';
			mkdir($path, 0700, true);
			file_put_contents($path . '/.git', 'gitdir: /tmp/none');
		}

		$harness = new BoundedWorkspaceListHarness($workspace);
		$started = microtime(true);
		$first = $harness->list_repos();
		$elapsed = microtime(true) - $started;
		bounded_list_assert(338 === $first['total'], 'Bounded list must report the complete filtered total.');
		bounded_list_assert(338 === ($first['summary']['total'] ?? null) && 338 === ($first['summary']['worktree'] ?? null), 'Summary must count the complete result before pagination.');
		bounded_list_assert(338 === ($first['summary']['repo_count'] ?? null) && 25 === ($first['summary']['repos_returned'] ?? null) && 313 === ($first['summary']['repos_omitted'] ?? null), 'Summary repository samples must be bounded and report omitted repositories.');
		bounded_list_assert(50 === $first['returned'] && 50 === count($first['repos']), 'Default list must never exceed its 50-row bound.');
		bounded_list_assert(50 >= $harness->max_bounded_rows, 'Default list must retain no more than one bounded page or summary sample set.');
		bounded_list_assert(is_string($first['next_cursor']), 'First bounded page must provide a continuation cursor.');
		$legacy_cursor = rtrim(strtr(base64_encode(wp_json_encode(array( 'v' => 1, 'after' => "repo-049@branch\0{$workspace}/repo-049@branch", 'repo' => null, 'type' => null ))), '+/', '-_'), '=');
		bounded_list_assert($legacy_cursor === $first['next_cursor'], 'Shared cursor encoding must preserve the existing serialized workspace cursor.');
		bounded_list_assert(false === $first['status_requested'] && 0 === $harness->git_probes, 'Default discovery must not run per-row Git probes.');
		bounded_list_assert(1 === $harness->scan_passes && false === $first['partial'] && 1 === $first['diagnostics']['scan_passes'], 'Complete default discovery must use one scan pass and report its diagnostics.');
		bounded_list_assert($elapsed < 2.0, sprintf('Bounded high-cardinality response exceeded deadline: %.3fs.', $elapsed));

		$second = $harness->list_repos(null, null, array( 'cursor' => $legacy_cursor ));
		bounded_list_assert('repo-050@branch' === ($second['repos'][0]['name'] ?? null), 'Cursor continuation must resume after the previous stable row.');
		bounded_list_assert(50 === $second['returned'], 'Cursor continuation must preserve the declared output bound.');
		$scoped_cursor = $harness->list_repos('other-repo', null, array( 'cursor' => $first['next_cursor'] ));
		bounded_list_assert(is_wp_error($scoped_cursor), 'A cursor must reject changed repository scope.');

		$cursor = null;
		$names  = array();
		do {
			$page = $harness->list_repos(null, null, null === $cursor ? array() : array( 'cursor' => $cursor ));
			$names = array_merge($names, array_column($page['repos'], 'name'));
			$cursor = $page['next_cursor'];
		} while ( null !== $cursor );
		bounded_list_assert(338 === count($names) && 338 === count(array_unique($names)), 'Cursor pagination must return every matching row exactly once.');

		$status = $harness->list_repos(null, null, array( 'limit' => 2, 'include_status' => true ));
		bounded_list_assert(true === $status['status_requested'] && 4 === $harness->git_probes, 'Explicit status requests must scope Git probes to returned rows.');
		$all = $harness->list_repos(null, null, array( 'all' => true ));
		bounded_list_assert(338 === $all['returned'] && null === $all['next_cursor'], 'Explicit all must return the complete inventory without a continuation cursor.');
		$all_with_cursor = $harness->list_repos(null, null, array( 'all' => true, 'cursor' => $first['next_cursor'] ));
		bounded_list_assert(is_wp_error($all_with_cursor), 'All and cursor must be rejected as an ambiguous pagination request.');
		mkdir($workspace . '/aggregate/.git', 0700, true);
		for ( $index = 0; $index < 50; ++$index ) {
			$path = $workspace . '/aggregate@task-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
			mkdir($path, 0700, true);
			file_put_contents($path . '/.git', 'gitdir: /tmp/none');
		}
		$duplicate_summary = $harness->list_repos();
		$aggregate = array_values(array_filter($duplicate_summary['summary']['repos'], fn( array $repo ): bool => 'aggregate' === $repo['repo']));
		bounded_list_assert(339 === ($duplicate_summary['summary']['repo_count'] ?? null) && 25 === ($duplicate_summary['summary']['repos_returned'] ?? null) && 314 === ($duplicate_summary['summary']['repos_omitted'] ?? null), 'Summary repository counts must use unique repository units.');
		bounded_list_assert(1 === ($aggregate[0]['primary'] ?? null) && 50 === ($aggregate[0]['worktree'] ?? null) && 51 === ($aggregate[0]['total'] ?? null), 'Summary samples must aggregate every checkout for a selected repository.');
		foreach ( array( 1, 200, '1', '050' ) as $limit ) {
			bounded_list_assert(! is_wp_error(BoundedWorkspaceListHarness::normalize_workspace_list_limit($limit)), 'Documented integer limit representations must be accepted.');
		}
		foreach ( array( 0, -1, 201, 1.0, '1.5', '1x', '', array( 1 ), true, false ) as $limit ) {
			bounded_list_assert(is_wp_error(BoundedWorkspaceListHarness::normalize_workspace_list_limit($limit)), 'Non-integer workspace list limits must be rejected before coercion.');
		}

		$slow = new BudgetExhaustedWorkspaceListHarness($workspace);
		$partial = $slow->list_repos();
		bounded_list_assert(true === $partial['partial'] && null === $partial['total'] && null === $partial['summary']['total'], 'Budget exhaustion must not present observed rows as a complete total.');
		bounded_list_assert(1 === $slow->scan_passes && 1 === $partial['diagnostics']['scan_passes'] && true === $partial['diagnostics']['budget_exhausted'] && 'scan_budget_exhausted' === $partial['diagnostics']['budget_exhaustion_reason'], 'Budget exhaustion must stop after one scan pass and expose diagnostic evidence.');
		bounded_list_assert(1 === $partial['summary']['observed']['total'] && false === $partial['continuation']['available'] && 'scan_budget_exhausted' === $partial['continuation']['reason'], 'Partial results must retain observed lower bounds and truthful continuation state.');
	} finally {
		bounded_list_remove_tree($workspace);
	}

	echo "workspace-list-bounded-contract: ok\n";
}
