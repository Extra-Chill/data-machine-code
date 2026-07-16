<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	abstract class BaseCommand {}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	final class WP_CLI {
		/** @var array<int,string> */
		public static array $commands = array();
		public static int $child_drain_count = 0;

		public static function runcommand( string $command, array $args ): string {
			self::$commands[] = $command;
			if ( 'datamachine drain --job-id=2095' === $command ) {
				$action = $GLOBALS['workspace_cleanup_drainability_actions'][0] ?? array();
				if (
					'datamachine_execute_step' !== (string) ( $action['hook'] ?? '' )
					|| 2095 !== (int) ( $action['args']['job_id'] ?? 0 )
				) {
					throw new \RuntimeException('Child drain requires the repaired execute-step action.');
				}
				++self::$child_drain_count;
				$GLOBALS['workspace_cleanup_drainability_actions'][0]['executed'] = true;
				$GLOBALS['workspace_cleanup_drainability_jobs'][2095]['status'] = 'completed';
			}
			return '';
		}
	}

	require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRunEvidenceStoreInterface.php';
	require_once dirname(__DIR__) . '/inc/Support/SystemTaskDrainability.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachineCode\Cleanup\CleanupRunEvidenceStoreInterface;
	use DataMachineCode\Cli\Commands\WorkspaceCommand;

	$GLOBALS['workspace_cleanup_drainability_jobs'] = array(
		2095 => array(
			'job_id' => 2095,
			'status' => 'pending',
			'engine_data' => array(
				'flow_config' => array( 'step-system-task-1' => array( 'step_type' => 'system_task' ) ),
			),
		),
	);
	$GLOBALS['workspace_cleanup_drainability_actions'] = array();

	final class WorkspaceCleanupDrainabilityWpdb {
		public string $prefix = 'wp_';

		public function prepare( string $query, mixed ...$args ): array {
			return array( 'args' => $args );
		}

		public function get_var( mixed $prepared ): int {
			$args = (array) ( $prepared['args'] ?? array() );
			preg_match('/"job_id":(\d+)/', (string) ( $args[4] ?? '' ), $matches);
			$job_id = (int) ( $matches[1] ?? 0 );
			foreach ( $GLOBALS['workspace_cleanup_drainability_actions'] as $action ) {
				if ( $job_id === (int) ( $action['args']['job_id'] ?? 0 ) ) {
					return 1;
				}
			}

			return 0;
		}
	}

	$GLOBALS['wpdb'] = new WorkspaceCleanupDrainabilityWpdb();

	final class WorkspaceCleanupDrainabilityJobsAbility {
		public function execute( array $input ): array {
			$job = $GLOBALS['workspace_cleanup_drainability_jobs'][ (int) ( $input['job_id'] ?? 0 ) ] ?? null;
			return array( 'success' => null !== $job, 'jobs' => null === $job ? array() : array( $job ) );
		}
	}

	function wp_get_ability( string $name ): ?WorkspaceCleanupDrainabilityJobsAbility {
		return 'datamachine/get-jobs' === $name ? new WorkspaceCleanupDrainabilityJobsAbility() : null;
	}

	function datamachine_get_engine_data( int $job_id ): array {
		return (array) ( $GLOBALS['workspace_cleanup_drainability_jobs'][ $job_id ]['engine_data'] ?? array() );
	}

	function datamachine_merge_engine_data( int $job_id, array $data ): void {}

	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
		$GLOBALS['workspace_cleanup_drainability_actions'][] = compact('timestamp', 'hook', 'args', 'group');
		return count($GLOBALS['workspace_cleanup_drainability_actions']);
	}

	final class WorkspaceCleanupDrainabilityEvidenceStore implements CleanupRunEvidenceStoreInterface {
		public bool $child_completion_observed = false;

		public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error {
			$child_status    = (string) $GLOBALS['workspace_cleanup_drainability_jobs'][2095]['status'];
			$has_action      = array() !== $GLOBALS['workspace_cleanup_drainability_actions'];
			$action_executed = (bool) ( $GLOBALS['workspace_cleanup_drainability_actions'][0]['executed'] ?? false );
			if ( 'pending' === $child_status ) {
				return array(
					'evidence' => array(
						'children' => array(
							'pending_job_ids'                          => array( 2095 ),
							'pending_without_drainable_action_job_ids' => $has_action ? array() : array( 2095 ),
						),
					),
				);
			}

			if ( 'completed' !== $child_status || 1 !== WP_CLI::$child_drain_count || ! $action_executed ) {
				throw new \RuntimeException('Parent completion requires the repaired child action to be drained.');
			}

			$this->child_completion_observed = true;
			if ( $include_evidence ) {
				return array(
					'evidence' => array(
						'children' => array(
							'completed' => 1,
							'statuses'  => array( 'completed' => 1 ),
						),
					),
				);
			}

			return array( 'state' => 'completed', 'cleanup_items' => array( 'bytes_reclaimed' => 42, 'freed_human' => '42 B' ) );
		}
	}

	function workspace_cleanup_drainability_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
		}
	}

	$command = new WorkspaceCommand();
	$property = new \ReflectionProperty($command, 'cleanup_run_evidence_store');
	$store = new WorkspaceCleanupDrainabilityEvidenceStore();
	$property->setValue($command, $store);
	$method = new \ReflectionMethod($command, 'drain_cleanup_run_to_status');
	$result = $method->invoke($command, array( 'job_id' => 71, 'run_id' => 'cleanup-run-71' ), array());

	workspace_cleanup_drainability_assert_same(array( 2095 ), $result['drain']['repaired_child_job_ids'] ?? null, 'Drain output should expose repaired child IDs.');
	workspace_cleanup_drainability_assert_same(array( 2095 ), $result['drain']['drainability_repairs'][0]['repaired_child_job_ids'] ?? null, 'Drain evidence should retain repaired child IDs per pass.');
	workspace_cleanup_drainability_assert_same(1, count($GLOBALS['workspace_cleanup_drainability_actions']), 'Drain repair should schedule one missing child action.');
	workspace_cleanup_drainability_assert_same('datamachine drain --job-id=2095', WP_CLI::$commands[1] ?? null, 'Drain should process the repaired child job.');
	workspace_cleanup_drainability_assert_same(1, WP_CLI::$child_drain_count, 'The repaired child action should be drained exactly once.');
	workspace_cleanup_drainability_assert_same(true, $GLOBALS['workspace_cleanup_drainability_actions'][0]['executed'] ?? null, 'Child drain should execute the repaired action.');
	workspace_cleanup_drainability_assert_same('completed', $GLOBALS['workspace_cleanup_drainability_jobs'][2095]['status'] ?? null, 'Child must complete before parent completion is returned.');
	workspace_cleanup_drainability_assert_same(true, $store->child_completion_observed, 'Drain should observe child terminal completion before reading terminal parent status.');
	workspace_cleanup_drainability_assert_same('completed', $result['state'] ?? null, 'Terminal parent status should follow child completion.');

	echo "workspace cleanup drainability repair test passed.\n";
}
