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

namespace DataMachine\Engine\Tasks {
	class TaskScheduler {
		public static array $batches = array();

		public static function scheduleBatch( string $task_type, array $items, array $context = array() ) {
			self::$batches[] = array(
				'task_type' => $task_type,
				'items'     => $items,
				'context'   => $context,
			);

			return array(
				'batch_job_id' => 301,
				'job_ids'      => range( 401, 400 + count( $items ) ),
			);
		}
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public static array $artifact_opts = array();

		public function get_path(): string {
			return '/tmp/dmc-retention-task-workspace';
		}

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

		public function worktree_cleanup_artifacts( array $opts = array() ): array {
			self::$artifact_opts[] = $opts;
			return array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => array(),
				'skipped'    => array(),
				'summary'    => array(
					'pagination' => array(
						'total' => 25,
					),
				),
				'pagination' => array(
					'total' => 25,
				),
			);
		}

		public function worktree_reconcile_metadata( array $opts = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array( 'proposals' => array() );
		}

		public function worktree_cleanup_merged( array $opts = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array( 'candidates' => array() );
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

	echo "\n[3] Explicit CLI run bypasses global disabled setting\n";
	$GLOBALS['__retention_task_logs'] = array();
	$settings_class::$$enabled_prop = false;
	$task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
	$task->executeTask( 203, array( 'source' => 'workspace_cleanup_cli', 'dry_run' => true ) );
	$completed = $task->{$completed_prop};
	datamachine_code_retention_task_assert( empty( $completed[0][1]['skipped'] ), 'explicit CLI run bypasses disabled recurring schedule' );
	datamachine_code_retention_task_assert( true === (bool) ( $completed[0][1]['dry_run'] ?? false ), 'explicit CLI run forwards task params' );

	echo "\n[4] Artifact cleanup schedules bounded discovery chunks\n";
	\DataMachine\Engine\Tasks\TaskScheduler::$batches = array();
	\DataMachineCode\Workspace\Workspace::$artifact_opts = array();
	$task = new \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask();
	$task->executeTask(
		204,
		array(
			'source'              => 'workspace_cleanup_cli',
			'artifact_cleanup'    => true,
			'worktree_cleanup'    => false,
			'metadata_repair'     => false,
			'artifact_chunk_size' => 10,
		)
	);
	$completed = $task->{$completed_prop};
	$batch     = \DataMachine\Engine\Tasks\TaskScheduler::$batches[0] ?? array();
	datamachine_code_retention_task_assert( 'worktree_cleanup_chunk' === ( $batch['task_type'] ?? '' ), 'retention task schedules cleanup chunk batch' );
	datamachine_code_retention_task_assert( 3 === count( $batch['items'] ?? array() ), 'artifact inventory total fans out into bounded discovery pages' );
	datamachine_code_retention_task_assert( 'artifact_discovery' === ( $batch['items'][0]['chunk_type'] ?? '' ), 'artifact cleanup uses discovery chunks instead of prebuilt artifact rows' );
	datamachine_code_retention_task_assert( array( 0, 10, 20 ) === array_column( $batch['items'], 'offset' ), 'discovery chunks carry stable offsets' );
	datamachine_code_retention_task_assert( empty( \DataMachineCode\Workspace\Workspace::$artifact_opts[0]['exhaustive'] ), 'parent does not run exhaustive artifact dry-run' );
	datamachine_code_retention_task_assert( 3 === (int) ( $completed[0][1]['chunk_row_counts']['artifact_discovery'] ?? 0 ), 'completion report exposes discovery chunk count' );

	echo "\nAll workspace retention task smoke tests passed.\n";
}
