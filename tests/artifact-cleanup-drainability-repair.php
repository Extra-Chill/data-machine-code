<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	require_once dirname(__DIR__) . '/inc/Support/SystemTaskDrainability.php';

	use DataMachineCode\Support\SystemTaskDrainability;

	$GLOBALS['artifact_cleanup_test_jobs'] = array(
		2095 => array(
			'job_id'      => 2095,
			'source'      => 'system',
			'status'      => 'pending',
			'engine_data' => array(
				'task_type'   => 'worktree_cleanup_chunk',
				'task_params' => array(
					'chunk_type' => 'artifacts',
				),
				'flow_config' => array(
					'step-system-task-1' => array(
						'step_type' => 'system_task',
					),
				),
			),
		),
	);
	$GLOBALS['artifact_cleanup_test_actions'] = array();
	$GLOBALS['artifact_cleanup_test_merges']  = array();

	final class ArtifactCleanupDrainabilityWpdb {
		public string $prefix = 'wp_';

		public function prepare( string $query, mixed ...$args ): array {
			return array(
				'query' => $query,
				'args'  => $args,
			);
		}

		public function get_var( mixed $prepared ): int {
			$args       = is_array($prepared) ? (array) ( $prepared['args'] ?? array() ) : array();
			$hook       = (string) ( $args[2] ?? '' );
			$group      = (string) ( $args[3] ?? '' );
			$job_pattern = (string) ( $args[4] ?? '' );
			preg_match('/"job_id":(\d+)/', $job_pattern, $matches);
			$job_id = (int) ( $matches[1] ?? 0 );

			$count = 0;
			foreach ( (array) $GLOBALS['artifact_cleanup_test_actions'] as $action ) {
				if (
					$hook === (string) ( $action['hook'] ?? '' )
					&& $group === (string) ( $action['group'] ?? '' )
					&& $job_id === (int) ( $action['args']['job_id'] ?? 0 )
				) {
					++$count;
				}
			}

			return $count;
		}
	}

	$GLOBALS['wpdb'] = new ArtifactCleanupDrainabilityWpdb();

	final class ArtifactCleanupDrainabilityJobsAbility {
		public function execute( array $input ): array {
			$job_id = (int) ( $input['job_id'] ?? 0 );
			$job    = $GLOBALS['artifact_cleanup_test_jobs'][ $job_id ] ?? null;

			return array(
				'success' => null !== $job,
				'jobs'    => null !== $job ? array( $job ) : array(),
			);
		}
	}

	function wp_get_ability( string $name ): ?ArtifactCleanupDrainabilityJobsAbility {
		return 'datamachine/get-jobs' === $name ? new ArtifactCleanupDrainabilityJobsAbility() : null;
	}

	function datamachine_get_engine_data( int $job_id ): array {
		return (array) ( $GLOBALS['artifact_cleanup_test_jobs'][ $job_id ]['engine_data'] ?? array() );
	}

	function datamachine_merge_engine_data( int $job_id, array $data ): void {
		$GLOBALS['artifact_cleanup_test_merges'][ $job_id ][] = $data;
	}

	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
		$GLOBALS['artifact_cleanup_test_actions'][] = compact('timestamp', 'hook', 'args', 'group');
		return count($GLOBALS['artifact_cleanup_test_actions']);
	}

	function artifact_cleanup_drainability_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	artifact_cleanup_drainability_assert_same(
		array( 2095 ),
		SystemTaskDrainability::pending_jobs_missing_execute_step_actions(array( 2095 )),
		'Pending artifact cleanup chunk should be reported when no drainable execute-step action exists.'
	);

	$result = SystemTaskDrainability::ensure_jobs_have_execute_step_actions(array( 2095 ));
	artifact_cleanup_drainability_assert_same(1, $result['checked'], 'Repair should inspect the pending child job.');
	artifact_cleanup_drainability_assert_same(1, $result['repaired'], 'Repair should schedule the missing execute-step action.');
	artifact_cleanup_drainability_assert_same('datamachine_execute_step', $GLOBALS['artifact_cleanup_test_actions'][0]['hook'] ?? null, 'Repair should use the drainable Data Machine execute-step hook.');
	artifact_cleanup_drainability_assert_same(2095, $GLOBALS['artifact_cleanup_test_actions'][0]['args']['job_id'] ?? null, 'Repair action should target the stuck child job.');
	artifact_cleanup_drainability_assert_same('step-system-task-1', $GLOBALS['artifact_cleanup_test_actions'][0]['args']['flow_step_id'] ?? null, 'Repair action should resume the job through its workflow first step.');
	artifact_cleanup_drainability_assert_same(array(), SystemTaskDrainability::pending_jobs_missing_execute_step_actions(array( 2095 )), 'Repaired child job should no longer be reported as missing drainable work.');

	$result = SystemTaskDrainability::ensure_jobs_have_execute_step_actions(array( 2095 ));
	artifact_cleanup_drainability_assert_same(0, $result['repaired'], 'Existing execute-step action should not be duplicated.');
	artifact_cleanup_drainability_assert_same(1, count($GLOBALS['artifact_cleanup_test_actions']), 'Repair should schedule at most one action for the child job.');

	echo "artifact cleanup drainability repair test passed.\n";
}
