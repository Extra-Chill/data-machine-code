<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '' ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}
	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}

	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeEmergencyCleanup.php';

	use DataMachineCode\Workspace\WorkspaceMutationLock;
	use DataMachineCode\Workspace\WorkspaceWorktreeEmergencyCleanup;
	use DataMachineCode\Workspace\WorktreeContextInjector;

	function emergency_lock_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	final class EmergencyCleanupLockHarness {
		use WorkspaceWorktreeEmergencyCleanup;

		public string $workspace_path;
		public array $rows = array();
		public bool $reactivate_on_locked_read = true;
		public bool $read_under_lock = false;
		public array $removed_artifacts = array();
		public array $removed_worktrees = array();

		public function __construct( string $workspace_path ) {
			$this->workspace_path = $workspace_path;
		}

		public function apply( array $plan ): array|WP_Error {
			$method = new ReflectionMethod($this, 'apply_worktree_emergency_cleanup_plan');
			return $method->invoke($this, $plan, false);
		}

		public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array {
			$this->read_under_lock = ( WorkspaceMutationLock::status($this->workspace_path)['active'] ?? 0 ) > 0;
			if ( $this->reactivate_on_locked_read && $this->read_under_lock ) {
				$this->rows[0]['metadata']['lifecycle_state'] = WorktreeContextInjector::STATE_ACTIVE;
				$this->rows[0]['metadata']['last_seen_at'] = gmdate('c');
			}
			return array( 'success' => true, 'worktrees' => $this->rows );
		}

		public function remove_worktree_artifact_path( string $path, string $relative ): array {
			$this->removed_artifacts[] = $relative;
			return array( 'success' => true );
		}

		public function remove_worktree_by_path( string $repo, string $branch, string $path, bool $force ): array {
			$this->removed_worktrees[] = $path;
			return array( 'success' => true );
		}

		public function count_unpushed_commits( string $path ): int { return 0; }
		public function worktree_prune( array $opts = array() ): array { return array( 'success' => true ); }
		public function summarize_top_worktree_rows( array $rows, string $field ): array { return array(); }
	}

	$workspace = sys_get_temp_dir() . '/dmc-emergency-lock-' . bin2hex(random_bytes(6));
	mkdir($workspace, 0777, true);
	try {
		$row = array(
			'handle' => 'repo@candidate', 'repo' => 'repo', 'branch' => 'candidate', 'path' => $workspace . '/repo@candidate',
			'metadata' => array( 'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE, 'last_seen_at' => gmdate('c', time() - 172800) ),
		);
		$harness = new EmergencyCleanupLockHarness($workspace);
		$harness->rows = array( $row );
		$result = $harness->apply(array( 'artifact_candidates' => array( array_merge($row, array( 'artifacts' => array( array( 'path' => 'vendor' ) ) ) ) ), 'worktree_candidates' => array( $row ) ));
		emergency_lock_assert($harness->read_under_lock, 'Emergency apply must read the reviewed row while its repository lock is held.');
		emergency_lock_assert(array() === $harness->removed_artifacts && array() === $harness->removed_worktrees, 'A worktree reactivated after plan review must not lose artifacts or its checkout.');
		emergency_lock_assert(2 === count($result['skipped'] ?? array()), 'Both emergency artifact and worktree paths must report the locked reactivation skip.');
		echo "worktree-emergency-cleanup-lock-reactivation: ok\n";
	} finally {
		if ( is_dir($workspace . '/.locks') ) {
			foreach ( glob($workspace . '/.locks/requests/*.json') ?: array() as $request ) { unlink($request); }
			if ( is_dir($workspace . '/.locks/requests') ) { rmdir($workspace . '/.locks/requests'); }
			foreach ( glob($workspace . '/.locks/*.lock') ?: array() as $lock ) { unlink($lock); }
			rmdir($workspace . '/.locks');
		}
		rmdir($workspace);
	}
}
