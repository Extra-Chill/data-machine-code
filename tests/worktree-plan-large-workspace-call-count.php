<?php
/**
 * Deterministic worktree-plan candidate discovery profile for a large workspace.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorktreeContextInjector {
		public const VALID_STATES = array( 'active' );
		public const SAME_TASK_CANDIDATE_EVIDENCE_LIMIT = 5;

		public static int $metadata_reads = 0;

		public static function get_metadata( string $key ): ?array {
			++self::$metadata_reads;
			$task_url = in_array($key, array( 'repo@branch-007', 'repo@branch-111' ), true)
				? 'https://example.test/issues/1203'
				: 'https://example.test/issues/' . $key;
			return array(
				'lifecycle_state' => 'active',
				'origin_task'     => array( 'task_url' => $task_url ),
			);
		}

		public static function classify_liveness( ?array $metadata ): array {
			return array( 'liveness' => 'unknown', 'reason' => 'fixture', 'heartbeat_age_seconds' => null );
		}

		public static function summarize_owner( ?array $metadata ): array {
			return array( 'site' => 'unknown', 'agent' => 'unknown', 'user' => 'unknown' );
		}

		public static function project_lifecycle_state( array $metadata ): string {
			return (string) ( $metadata['lifecycle_state'] ?? 'active' );
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}

	final class WP_Error {
		public function __construct( public string $code = '' ) {}
	}

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

	use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;
	use DataMachineCode\Workspace\WorktreeContextInjector;

	final class WorktreePlanLargeWorkspaceHarness {
		use WorkspaceWorktreeLifecycle { worktree_reuse_candidates as public reuse_candidates; }

		public int $worktree_list_calls = 0;
		public int $status_calls = 0;
		public int $unpushed_calls = 0;

		public function __construct( private string $workspace_path ) {}

		private function run_git( string $path, string $command ): array {
			if ( 'worktree list --porcelain' === $command ) {
				++$this->worktree_list_calls;
				$blocks = array( "worktree {$this->workspace_path}/repo\nHEAD primary\nbranch refs/heads/main" );
				for ( $index = 0; $index < 128; ++$index ) {
					$blocks[] = sprintf("worktree %s/repo@branch-%03d\nHEAD %040d\nbranch refs/heads/branch-%03d", $this->workspace_path, $index, $index, $index);
				}
				return array( 'output' => implode("\n\n", $blocks) );
			}

			++$this->status_calls;
			return array( 'output' => str_contains($path, 'branch-111') ? " M fixture.txt\n" : '' );
		}

		private function count_unpushed_commits( string $path ): int {
			++$this->unpushed_calls;
			return str_contains($path, 'branch-007') ? 1 : 0;
		}
	}

	function worktree_plan_profile_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	$harness    = new WorktreePlanLargeWorkspaceHarness('/workspace');
	$candidates = $harness->reuse_candidates('repo', array( 'task_url' => 'https://example.test/issues/1203' ));

	worktree_plan_profile_assert(2 === count($candidates), 'Large-workspace candidate discovery lost a matching owner.');
	worktree_plan_profile_assert(array( 'repo@branch-007', 'repo@branch-111' ) === array_column($candidates, 'handle'), 'Candidates must retain deterministic handle ordering.');
	worktree_plan_profile_assert(1 === $candidates[0]['unpushed'] && 0 === $candidates[0]['dirty'], 'Candidate safety evidence changed for the first match.');
	worktree_plan_profile_assert(0 === $candidates[1]['unpushed'] && 1 === $candidates[1]['dirty'], 'Candidate safety evidence changed for the second match.');
	worktree_plan_profile_assert(1 === $harness->worktree_list_calls, 'Candidate discovery must use one live Git topology scan.');
	worktree_plan_profile_assert(2 === $harness->status_calls && 2 === $harness->unpushed_calls, 'Only matching candidates may receive status and unpushed probes.');
	worktree_plan_profile_assert(128 === WorktreeContextInjector::$metadata_reads, 'Each live managed worktree must receive one ownership metadata check.');

	$baseline = array(
		'worktree_list_calls' => 9,
		'status_calls'        => 129,
		'unpushed_calls'      => 129,
		'metadata_reads'      => 1152,
	);
	$optimized = array(
		'worktree_list_calls' => $harness->worktree_list_calls,
		'status_calls'        => $harness->status_calls,
		'unpushed_calls'      => $harness->unpushed_calls,
		'metadata_reads'      => WorktreeContextInjector::$metadata_reads,
	);

	echo 'worktree-plan-large-workspace-call-count: ok ' . json_encode(array( 'worktrees' => 128, 'baseline' => $baseline, 'optimized' => $optimized )) . "\n";
}
