<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

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
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupCandidateClassifier.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeActiveNoSignalTriagePreview.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceRowTriage.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeInventoryCleanup.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

function bounded_cleanup_scope_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$cleanup = new class {
	use DataMachineCode\Workspace\WorkspaceCoreUtilities;
	use DataMachineCode\Workspace\WorkspaceRowTriage;
	use DataMachineCode\Workspace\WorkspaceWorktreeInventoryCleanup;
	use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

	private const CLEANUP_GIT_PROBE_TIMEOUT = 15;
	private const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
	private const METADATA_RECONCILE_DEFAULT_LIMIT = 25;
	private const METADATA_RECONCILE_DEFAULT_BUDGET = '30s';
	private const CLEANUP_SUMMARY_TOP_LIMIT = 10;
	private string $workspace_path = '/tmp/workspace';

	public function preview( string $repo ): array|WP_Error {
		return $this->worktree_bounded_cleanup_eligible_apply(array( 'dry_run' => true, 'repo' => $repo, 'limit' => 1 ));
	}

	private function build_workspace_inventory_rows(): array {
		return array(
			$this->row('target@eligible-one', 'target', 'cleanup_eligible'),
			$this->row('target@blocked', 'target', 'active'),
			$this->row('unrelated@eligible-two', 'unrelated', 'cleanup_eligible'),
		);
	}

	private function row( string $handle, string $repo, string $state ): array {
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
				'lifecycle_state' => $state,
			),
		);
	}
};

$result = $cleanup->preview('target');
if ( is_wp_error($result) ) {
	throw new RuntimeException($result->get_error_message());
}

bounded_cleanup_scope_assert_same(array( 'target@eligible-one' ), array_column($result['candidates'], 'handle'), 'scoped preview only selects target repository candidates');
bounded_cleanup_scope_assert_same(array( 'target@blocked' ), array_column($result['skipped'], 'handle'), 'scoped preview only reports target repository blockers');
bounded_cleanup_scope_assert_same(0, $result['continuation']['remaining_total'], 'unrelated eligible worktrees are not deferred into scoped continuation');
bounded_cleanup_scope_assert_same('target', $result['scope']['repo'], 'result reports normalized repository scope');
bounded_cleanup_scope_assert_same('target', $result['continuation']['scope']['repo'], 'continuation preserves normalized repository scope');
bounded_cleanup_scope_assert_same('target', $result['evidence']['scope']['repo'], 'JSON evidence reports normalized repository scope');

$cli_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
if ( false === $cli_source || ! str_contains($cli_source, "case 'bounded-cleanup-eligible-apply':") || ! str_contains($cli_source, "\$input['repo'] = (string) \$args[1];") ) {
	throw new RuntimeException('bounded cleanup CLI must forward its positional repository scope');
}

echo "bounded-cleanup-repository-scope: ok\n";
