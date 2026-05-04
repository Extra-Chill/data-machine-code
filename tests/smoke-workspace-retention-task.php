<?php
/**
 * Smoke test for workspace retention cleanup system task.
 *
 *   php tests/smoke-workspace-retention-task.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['__retention_task_logs'] = array();

	function is_wp_error( $value ): bool {
		return false;
	}

	function do_action( string $hook, ...$args ): void {
		$GLOBALS['__retention_task_logs'][] = array( $hook, $args );
	}
}

namespace DataMachine\Core {
	class PluginSettings {
		public static bool $enabled = false;

		public static function get( string $key, $default = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return self::$enabled;
		}
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {
		public array $completed = array();
		public array $failed = array();

		abstract public function getTaskType(): string;

		protected function completeJob( int $jobId, array $data ): void {
			$this->completed[] = array( $jobId, $data );
		}

		protected function failJob( int $jobId, string $message ): void {
			$this->failed[] = array( $jobId, $message );
		}
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public function workspace_retention_cleanup( array $opts = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array(
				'success' => true,
				'dry_run' => ! empty( $opts['dry_run'] ),
				'report'  => array(
					'removed_count'                => 2,
					'freed_human'                  => '16.0 MiB',
					'skipped_dirty_unpushed_count' => 3,
					'remaining_disk_budget_human'  => '400.0 GiB',
				),
			);
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Tasks/WorkspaceRetentionCleanupTask.php';

	function datamachine_code_retention_task_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	echo "=== smoke-workspace-retention-task ===\n";

	echo "\n[1] Disabled task completes as skipped\n";
	$settings_class = '\\DataMachine\\Core\\PluginSettings';
	$enabled_prop   = 'enabled';
	$settings_class::$$enabled_prop = false;
	$task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
	$task->executeTask( 201, array() );
	$completed_prop = 'completed';
	$failed_prop    = 'failed';
	$completed      = $task->{$completed_prop};
	$failed         = $task->{$failed_prop};
	datamachine_code_retention_task_assert( 'workspace_retention_cleanup' === $task->getTaskType(), 'task type is stable' );
	datamachine_code_retention_task_assert( true === ( $completed[0][1]['skipped'] ?? false ), 'disabled task reports skipped completion' );
	datamachine_code_retention_task_assert( array() === $failed, 'disabled task does not fail the job' );

	echo "\n[2] Enabled task stores report and logs concise summary\n";
	$GLOBALS['__retention_task_logs'] = array();
	$settings_class::$$enabled_prop = true;
	$task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
	$task->executeTask( 202, array( 'dry_run' => true ) );
	$completed = $task->{$completed_prop};
	datamachine_code_retention_task_assert( true === (bool) ( $completed[0][1]['dry_run'] ?? false ), 'enabled task forwards dry-run flag' );
	datamachine_code_retention_task_assert( 2 === (int) ( $completed[0][1]['report']['removed_count'] ?? 0 ), 'enabled task stores compact report' );
	datamachine_code_retention_task_assert( 1 === count( $GLOBALS['__retention_task_logs'] ), 'enabled task emits one datamachine_log event' );
	$first_log = $GLOBALS['__retention_task_logs'][0] ?? array();
	datamachine_code_retention_task_assert( str_contains( (string) ( $first_log[1][1] ?? '' ), 'removed 2 item(s)' ), 'log summary includes removed count' );

	echo "\n[3] Agent maintenance flow bypasses global disabled setting\n";
	$GLOBALS['__retention_task_logs'] = array();
	$settings_class::$$enabled_prop = false;
	$task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
	$task->executeTask( 203, array( 'source' => 'agent_maintenance_flow', 'dry_run' => true ) );
	$completed = $task->{$completed_prop};
	datamachine_code_retention_task_assert( empty( $completed[0][1]['skipped'] ), 'agent maintenance flow runs through its own flow schedule' );
	datamachine_code_retention_task_assert( true === (bool) ( $completed[0][1]['dry_run'] ?? false ), 'agent maintenance flow forwards task params' );

	echo "\nAll workspace retention task smoke tests passed.\n";
}
