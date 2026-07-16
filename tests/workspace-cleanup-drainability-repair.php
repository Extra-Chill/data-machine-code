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

		public static function runcommand( string $command, array $args ): string {
			self::$commands[] = $command;
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
		/** @var array<int,array<string,mixed>> */
		private array $responses;

		public function __construct() {
			$this->responses = array(
				array( 'evidence' => array( 'children' => array( 'pending_job_ids' => array( 2095 ), 'pending_without_drainable_action_job_ids' => array( 2095 ) ) ) ),
				array( 'evidence' => array( 'children' => array() ) ),
				array( 'state' => 'completed', 'cleanup_items' => array( 'bytes_reclaimed' => 42, 'freed_human' => '42 B' ) ),
			);
		}

		public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error {
			return array_shift($this->responses) ?? array();
		}
	}

	function workspace_cleanup_drainability_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
		}
	}

	$command = new WorkspaceCommand();
	$property = new \ReflectionProperty($command, 'cleanup_run_evidence_store');
	$property->setValue($command, new WorkspaceCleanupDrainabilityEvidenceStore());
	$method = new \ReflectionMethod($command, 'drain_cleanup_run_to_status');
	$result = $method->invoke($command, array( 'job_id' => 71, 'run_id' => 'cleanup-run-71' ), array());

	workspace_cleanup_drainability_assert_same(array( 2095 ), $result['drain']['repaired_child_job_ids'] ?? null, 'Drain output should expose repaired child IDs.');
	workspace_cleanup_drainability_assert_same(array( 2095 ), $result['drain']['drainability_repairs'][0]['repaired_child_job_ids'] ?? null, 'Drain evidence should retain repaired child IDs per pass.');
	workspace_cleanup_drainability_assert_same(1, count($GLOBALS['workspace_cleanup_drainability_actions']), 'Drain repair should schedule one missing child action.');
	workspace_cleanup_drainability_assert_same('datamachine drain --job-id=2095', WP_CLI::$commands[1] ?? null, 'Drain should process the repaired child job.');

	echo "workspace cleanup drainability repair test passed.\n";
}
