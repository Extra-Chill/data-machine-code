<?php
/**
 * Smoke test for workspace hygiene system task gating and completion payload.
 *
 *   php tests/smoke-workspace-hygiene-task.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['__task_logs'] = array();

	function is_wp_error( $value ): bool {
		return false;
	}

	function do_action( string $hook, ...$args ): void {
		$GLOBALS['__task_logs'][] = array( $hook, $args );
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
		public function workspace_hygiene_report( array $opts = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array(
				'success'    => true,
				'size'       => array( 'total_human' => '712.6 GiB' ),
				'disk'       => array( 'free_human' => '1.1 GiB' ),
				'worktrees'  => array( 'worktrees' => 42 ),
				'cleanup'    => array( 'summary' => array( 'would_remove' => 9 ) ),
				'destructive' => false,
			);
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Tasks/WorkspaceHygieneReportTask.php';

	function datamachine_code_hygiene_task_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	echo "=== smoke-workspace-hygiene-task ===\n";

	echo "\n[1] Disabled task completes as skipped\n";
	$settings_class = '\\DataMachine\\Core\\PluginSettings';
	$enabled_prop   = 'enabled';
	$settings_class::$$enabled_prop = false;
	$task = new \DataMachineCode\Tasks\WorkspaceHygieneReportTask();
	$task->executeTask( 101, array() );
	$completed_prop = 'completed';
	$failed_prop    = 'failed';
	$completed      = $task->{$completed_prop};
	$failed         = $task->{$failed_prop};
	datamachine_code_hygiene_task_assert( 'workspace_hygiene_report' === $task->getTaskType(), 'task type is stable' );
	datamachine_code_hygiene_task_assert( true === ( $completed[0][1]['skipped'] ?? false ), 'disabled task reports skipped completion' );
	datamachine_code_hygiene_task_assert( array() === $failed, 'disabled task does not fail the job' );

	echo "\n[2] Enabled task stores report and logs concise summary\n";
	$GLOBALS['__task_logs'] = array();
	$settings_class::$$enabled_prop = true;
	$task = new \DataMachineCode\Tasks\WorkspaceHygieneReportTask();
	$task->executeTask( 102, array( 'size_limit' => 25 ) );
	$completed = $task->{$completed_prop};
	datamachine_code_hygiene_task_assert( false === ( $completed[0][1]['destructive'] ?? true ), 'enabled task completes with non-destructive report' );
	datamachine_code_hygiene_task_assert( '1.1 GiB' === ( $completed[0][1]['disk']['free_human'] ?? '' ), 'enabled task stores free disk data' );
	datamachine_code_hygiene_task_assert( 1 === count( $GLOBALS['__task_logs'] ), 'enabled task emits one datamachine_log event' );
	$first_log = $GLOBALS['__task_logs'][0] ?? array();
	datamachine_code_hygiene_task_assert( 'datamachine_log' === ( $first_log[0] ?? '' ), 'log hook is datamachine_log' );

	echo "\nAll workspace hygiene task smoke tests passed.\n";
}
