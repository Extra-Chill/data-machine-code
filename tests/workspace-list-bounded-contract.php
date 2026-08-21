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

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceRepositoryLifecycle.php';

	use DataMachineCode\Workspace\WorkspaceRepositoryLifecycle;

	final class BoundedWorkspaceListHarness {
		use WorkspaceRepositoryLifecycle;

		public int $git_probes = 0;
		public function __construct( private string $workspace_path ) {}
		private function require_workspace_visible(): ?WP_Error { return null; }
		private function parse_handle( string $handle ): array {
			$parts = explode('@', $handle, 2);
			return array( 'repo' => $parts[0], 'is_worktree' => isset($parts[1]), 'branch_slug' => $parts[1] ?? '', 'dir_name' => $handle );
		}
		private function git_get_remote( string $path ): ?string { ++$this->git_probes; return 'https://example.test/repo.git'; }
		private function git_get_branch( string $path ): ?string { ++$this->git_probes; return 'main'; }
		private function build_primary_freshness_report( string $path, string $handle ): ?array { ++$this->git_probes; return array( 'status' => 'current' ); }
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
			$path = $workspace . '/repo@branch-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
			mkdir($path, 0700, true);
			file_put_contents($path . '/.git', 'gitdir: /tmp/none');
		}

		$harness = new BoundedWorkspaceListHarness($workspace);
		$started = microtime(true);
		$first = $harness->list_repos();
		$elapsed = microtime(true) - $started;
		bounded_list_assert(338 === $first['total'], 'Bounded list must report the complete filtered total.');
		bounded_list_assert(338 === ($first['summary']['total'] ?? null) && 338 === ($first['summary']['worktree'] ?? null), 'Summary must count the complete result before pagination.');
		bounded_list_assert(50 === $first['returned'] && 50 === count($first['repos']), 'Default list must never exceed its 50-row bound.');
		bounded_list_assert(is_string($first['next_cursor']), 'First bounded page must provide a continuation cursor.');
		bounded_list_assert(false === $first['status_requested'] && 0 === $harness->git_probes, 'Default discovery must not run per-row Git probes.');
		bounded_list_assert($elapsed < 2.0, sprintf('Bounded high-cardinality response exceeded deadline: %.3fs.', $elapsed));

		$second = $harness->list_repos(null, null, array( 'cursor' => $first['next_cursor'] ));
		bounded_list_assert('repo@branch-050' === ($second['repos'][0]['name'] ?? null), 'Cursor continuation must resume after the previous stable row.');
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
	} finally {
		bounded_list_remove_tree($workspace);
	}

	echo "workspace-list-bounded-contract: ok\n";
}
