<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(private string $code, private string $message = '') {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function bounded_cleanup_processed_candidates_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

final class BoundedCleanupProcessedCandidateHarness {
	use WorkspaceWorktreeCleanupEngine;

	public function processed( array $candidate, string $action, array $outcome ): array {
		return $this->build_bounded_cleanup_processed_candidate($candidate, $action, $outcome);
	}
}

final class BoundedCleanupRemovalCallbackHarness {
	use WorkspaceWorktreeCleanupEngine;
	private const RECOVERY_COMMIT = '0123456789abcdef0123456789abcdef01234567';

	protected const CLEANUP_GIT_PROBE_TIMEOUT  = 5;
	protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;

	public mixed $remove_result = array(
		'success'        => true,
		'removal_status' => 'complete',
	);
	public bool $fail_recovery_write = false;

	public function remove( array $candidate ): array|WP_Error {
		return $this->remove_revalidated_cleanup_candidate($candidate, false, false, 60, false);
	}

	private function revalidate_bounded_cleanup_eligible_candidate( array $candidate, bool $force, bool $stale_liveness_only = false, bool $discard_unpushed = false, ?array $reviewed_lifecycle_snapshot = null, bool $require_removable_lifecycle = true ): array {
		return $candidate;
	}

	private function estimate_path_size_bytes( string $path ): ?int {
		return 1024;
	}

	private function remove_worktree_by_path( string $repo, string $branch, string $path, bool $force, int $timeout, bool $broken_orphan_only = false ): mixed {
		rmdir($path);
		return $this->remove_result;
	}

	private function get_primary_path( string $repo ): string {
		return '/tmp/repo';
	}

	private function run_git( string $path, string $command, int $timeout = 0 ): array|WP_Error {
		if ( str_starts_with($command, 'rev-parse --verify') ) {
			if ( $this->fail_recovery_write && str_contains($command, 'refs/dmc/recovery/') ) {
				return new WP_Error('missing_ref', 'recovery ref does not exist');
			}
			return array( 'output' => self::RECOVERY_COMMIT );
		}
		if ( str_starts_with($command, 'update-ref ') ) {
			return $this->fail_recovery_write ? new WP_Error('cannot_write_ref', 'cannot write recovery ref') : array( 'output' => '' );
		}
		return new WP_Error('git_failed', 'cannot lock ref');
	}
}

$harness = new BoundedCleanupProcessedCandidateHarness();

$candidate = array(
	'handle'      => 'repo@stale-cleanup-row',
	'repo'        => 'repo',
	'branch'      => 'stale-cleanup-row',
	'path'        => '/tmp/repo@stale-cleanup-row',
	'reason_code' => 'cleanup_eligible',
	'dirty'       => 0,
);

$processed = $harness->processed(
	$candidate,
	'skipped',
	array(
		'handle'      => 'repo@stale-cleanup-row',
		'repo'        => 'repo',
		'branch'      => 'stale-cleanup-row',
		'path'        => '/tmp/repo@stale-cleanup-row',
		'reason_code' => 'dirty_worktree',
		'reason'      => 'working tree dirty (2 entries)',
		'dirty'       => 2,
		'unpushed'    => 1,
	)
);

bounded_cleanup_processed_candidates_assert_same(2, $processed['dirty'], 'processed candidate carries fresh dirty count');
bounded_cleanup_processed_candidates_assert_same(1, $processed['unpushed'], 'processed candidate carries fresh unpushed count');
bounded_cleanup_processed_candidates_assert_same('skipped', $processed['final_action'], 'processed candidate records final action');
bounded_cleanup_processed_candidates_assert_same('dirty_worktree', $processed['final_reason_code'], 'processed candidate records blocker bucket');
bounded_cleanup_processed_candidates_assert_same('cleanup_eligible', $processed['reason_code'], 'planned reason remains available separately');

$callback_harness = new BoundedCleanupRemovalCallbackHarness();
$callback_path    = sys_get_temp_dir() . '/dmc-bounded-cleanup-callback-' . getmypid();
$callback_candidate = array_merge($candidate, array( 'path' => $callback_path ));
$callback_harness->fail_recovery_write = true;
mkdir($callback_path);
$unpreserved = $callback_harness->remove($callback_candidate);
bounded_cleanup_processed_candidates_assert_same('cleanup_recovery_ref_failed', is_wp_error($unpreserved) ? $unpreserved->get_error_code() : null, 'cleanup fails closed when durable recovery cannot be written');
bounded_cleanup_processed_candidates_assert_same(true, is_dir($callback_path), 'failed recovery preservation crossed the worktree removal boundary');
$callback_harness->fail_recovery_write = false;
$locked              = $callback_harness->remove($callback_candidate);
$branch_delete_error = array(
	'code'    => 'git_failed',
	'message' => 'cannot lock ref',
);
bounded_cleanup_processed_candidates_assert_same(false, is_dir($callback_path), 'fixture proves worktree removal completed before branch deletion failed');
bounded_cleanup_processed_candidates_assert_same($callback_candidate, $locked['validated'] ?? null, 'branch deletion failure retains the normalized callback envelope');
bounded_cleanup_processed_candidates_assert_same(false, $locked['remove']['local_branch_deleted'] ?? null, 'normalized removal records retained local branch');
bounded_cleanup_processed_candidates_assert_same($branch_delete_error, $locked['remove']['branch_delete_error'] ?? null, 'normalized removal records branch deletion failure');
bounded_cleanup_processed_candidates_assert_same('refs/dmc/recovery/0123456789abcdef0123456789abcdef01234567', $locked['remove']['recovery_ref'] ?? null, 'removal records retain the durable recovery ref');
bounded_cleanup_processed_candidates_assert_same(true, str_contains((string) ($locked['remove']['recovery_command'] ?? ''), 'worktree add --detach'), 'removal records expose a reconstruction command');

$removed_outcome = array_merge($locked['remove'], array( 'path_exists_after' => false ));
$removed_processed = $harness->processed($locked['validated'], 'removed', $removed_outcome);
bounded_cleanup_processed_candidates_assert_same('removed', $removed_processed['final_action'], 'branch deletion failure does not discard successful worktree removal');
bounded_cleanup_processed_candidates_assert_same(false, $removed_processed['local_branch_deleted'], 'processed evidence records retained local branch');
bounded_cleanup_processed_candidates_assert_same($branch_delete_error, $removed_processed['branch_delete_error'], 'processed evidence records the branch deletion failure');
bounded_cleanup_processed_candidates_assert_same($locked['remove']['recovery_ref'], $removed_processed['recovery_ref'], 'processed evidence retains the durable recovery ref');

$callback_harness->remove_result = null;
mkdir($callback_path);
$invalid = $callback_harness->remove($callback_candidate);
bounded_cleanup_processed_candidates_assert_same(false, is_dir($callback_path), 'null-output fixture proves the removal helper already removed the worktree');
bounded_cleanup_processed_candidates_assert_same('cleanup_callback_invalid_result', $invalid['skipped']['reason_code'] ?? null, 'null removal output fails closed with a stable reason');
bounded_cleanup_processed_candidates_assert_same($candidate['handle'], $invalid['skipped']['handle'] ?? null, 'invalid callback evidence retains exact candidate identity');

echo "bounded-cleanup-processed-candidates: ok\n";
