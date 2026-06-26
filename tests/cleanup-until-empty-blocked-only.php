<?php

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	require_once dirname(__DIR__) . '/inc/Workspace/CleanupRunService.php';

	final class FakeUntilEmptyCleanupRunService extends CleanupRunService {
		/** @var array<int,array<string,mixed>> */
		private array $plans;

		/** @var array<int,array<string,mixed>> */
		private array $applies;

		/**
		 * @param array<int,array<string,mixed>> $plans   Plan responses.
		 * @param array<int,array<string,mixed>> $applies Apply responses.
		 */
		public function __construct( array $plans, array $applies ) {
			$this->plans   = $plans;
			$this->applies = $applies;
		}

		public function plan( array $opts = array() ): array|\WP_Error {
			return array_shift($this->plans);
		}

		public function apply( string $run_id, array $opts = array() ): array|\WP_Error {
			return array_shift($this->applies);
		}
	}

	function cleanup_until_empty_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	function cleanup_until_empty_plan(): array {
		return array(
			'run_id' => 'cleanup-run-20260625000000-blocked',
			'rows'   => array(
				'artifact_cleanup' => array(
					array(
						'handle'    => 'repo@dirty',
						'path'      => '/tmp/repo@dirty',
						'artifacts' => array( array( 'path' => 'vendor' ) ),
					),
					array(
						'handle'    => 'repo@unpushed',
						'path'      => '/tmp/repo@unpushed',
						'artifacts' => array( array( 'path' => 'node_modules' ) ),
					),
				),
			),
		);
	}

	$blocked_summary = array(
		'dirty_worktree'   => array( 'count' => 1 ),
		'unpushed_commits' => array( 'count' => 1 ),
	);
	$service         = new FakeUntilEmptyCleanupRunService(
		array( cleanup_until_empty_plan() ),
		array(
			array(
				'status'                 => 'completed',
				'applied'                => 0,
				'skipped'                => 2,
				'summary'                => array( 'bytes_reclaimed' => 0 ),
				'remaining_work_summary' => array( 'skipped_by_reason' => $blocked_summary ),
			),
		)
	);
	$result          = $service->until_empty(array( 'mode' => 'artifacts' ));

	cleanup_until_empty_assert_same(true, $result['success'] ?? null, 'Blocked-only artifact cleanup should be a successful terminal result.');
	cleanup_until_empty_assert_same('complete_with_blockers', $result['state'] ?? null, 'Blocked-only artifact cleanup should report a distinct terminal state.');
	cleanup_until_empty_assert_same(2, $result['remaining_blocked_count'] ?? null, 'Blocked-only result should include the blocker count.');
	cleanup_until_empty_assert_same($blocked_summary, $result['remaining_blocked_reasons'] ?? null, 'Blocked-only result should include blocker reasons.');

	$service = new FakeUntilEmptyCleanupRunService(
		array( cleanup_until_empty_plan() ),
		array(
			array(
				'status'                 => 'completed',
				'applied'                => 0,
				'skipped'                => 2,
				'summary'                => array( 'bytes_reclaimed' => 0 ),
				'remaining_work_summary' => array(
					'skipped_by_reason' => array(
						'dirty_worktree'         => array( 'count' => 1 ),
						'artifact_plan_mismatch' => array( 'count' => 1 ),
					),
				),
			),
		)
	);
	$result  = $service->until_empty(array( 'mode' => 'artifacts' ));

	cleanup_until_empty_assert_same(false, $result['success'] ?? null, 'Mixed unexpected skips should preserve no-progress failure semantics.');
	cleanup_until_empty_assert_same('no_progress', $result['state'] ?? null, 'Mixed unexpected skips should remain no_progress.');

	echo "cleanup until-empty blocked-only test passed.\n";
}
