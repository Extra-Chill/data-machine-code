<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHandle.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeAgeFilter.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupSignal.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeInventoryCleanup.php';

function active_no_signal_scope_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$cleanup = new class {
	use DataMachineCode\Workspace\WorkspaceCoreUtilities;
	use DataMachineCode\Workspace\WorkspaceWorktreeInventoryCleanup;

	public function expose( string $scope ): array|WP_Error {
		return $this->worktree_cleanup_inventory_only('', '', false, 10, 0, $scope);
	}

	private function build_workspace_inventory_rows(): array {
		return array(
			$this->row('homeboy@one', 'homeboy'),
			$this->row('homeboy@two', 'homeboy'),
			$this->row('homeboy-action@one', 'homeboy-action'),
		);
	}

	private function row( string $handle, string $repo ): array {
		return array(
			'is_worktree' => true,
			'handle'      => $handle,
			'repo'        => $repo,
			'branch'      => 'feature',
			'path'        => '/tmp/' . $handle,
			'created_at'  => '2026-07-01T00:00:00+00:00',
			'liveness'    => DataMachineCode\Workspace\WorktreeContextInjector::LIVENESS_STALE,
			'metadata'    => array(
				'handle'          => $handle,
				'repo'            => $repo,
				'branch'          => 'feature',
				'path'            => '/tmp/' . $handle,
				'lifecycle_state' => DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
			),
		);
	}

	private function workspace_row_triage_status_from_metadata( array $metadata ): string {
		return 'active';
	}

	private function build_submodule_worktree_cleanup_skip( array $row, string $real_path = '' ): ?array {
		return null;
	}

	private function build_worktree_cleanup_pagination( int $offset, ?int $limit, int $processed, int $total, bool $budget_stopped, ?array $budget_context ): ?array {
		return array(
			'total'       => $total,
			'offset'      => $offset,
			'limit'       => $limit,
			'scanned'     => $processed,
			'partial'     => false,
			'complete'    => true,
			'next_offset' => null,
		);
	}

	private function build_worktree_cleanup_summary( array $candidates, array $removed, array $skipped, ?array $age_filter, string $bucket ): array {
		return array(
			'candidates' => count($candidates),
			'skipped'    => count($skipped),
		);
	}

	private function sort_worktree_cleanup_rows( array $rows, string $sort ): array {
		return $rows;
	}
};

$repo_scoped = $cleanup->expose('homeboy');
if ( is_wp_error($repo_scoped) ) {
	throw new RuntimeException($repo_scoped->get_error_message());
}
active_no_signal_scope_assert_same(2, $repo_scoped['pagination']['total'], 'repo-scoped active/no-signal inventory totals only matching repo rows');
active_no_signal_scope_assert_same(array( 'homeboy@one', 'homeboy@two' ), array_column($repo_scoped['skipped'], 'handle'), 'repo-scoped active/no-signal examples only include matching repo rows');

$handle_scoped = $cleanup->expose('homeboy@two');
if ( is_wp_error($handle_scoped) ) {
	throw new RuntimeException($handle_scoped->get_error_message());
}
active_no_signal_scope_assert_same(1, $handle_scoped['pagination']['total'], 'handle-scoped active/no-signal inventory totals only exact handle rows');
active_no_signal_scope_assert_same(array( 'homeboy@two' ), array_column($handle_scoped['skipped'], 'handle'), 'handle-scoped active/no-signal examples only include exact handle row');

echo "worktree-active-no-signal-scope: ok\n";
