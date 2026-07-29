<?php

declare(strict_types=1);

namespace DataMachine\Core {
	class PluginSettings {
		public static function get( string $key, mixed $default ): mixed {
			return $default;
		}
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	class SystemTask {
		public array $completed = array();

		public function completeJob( int $job_id, array $result ): void {
			$this->completed[ $job_id ] = $result;
		}

		public function failJob( int $job_id, string $message ): void {}
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public static array $result;

		public function workspace_disk_emergency_cleanup( array $opts ): array {
			return self::$result;
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	$GLOBALS['emergency_cleanup_logs'] = array();
	function do_action( string $hook, mixed ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['emergency_cleanup_logs'][] = $args;
		}
	}

	require_once dirname(__DIR__) . '/inc/Tasks/WorkspaceDiskEmergencyCleanupTask.php';

	function durable_result_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	$candidates = array();
	for ( $index = 0; $index < 128; ++$index ) {
		$candidates[] = array(
			'handle'   => 'repo@candidate-' . $index,
			'evidence' => str_repeat('candidate-evidence-', 1024),
		);
	}

	DataMachineCode\Workspace\Workspace::$result = array(
		'success'                  => true,
		'triggered'                => true,
		'dry_run'                  => true,
		'generated_at'             => '2026-07-28T00:00:00+00:00',
		'disk_budget'              => array(
			'trigger_reasons'       => array( 'filesystem_free_bytes_below_threshold' ),
			'target_recovery_bytes' => 123456,
			'target_recovery_inodes' => 789,
		),
		'emergency_plan'           => array( 'artifact_candidates' => $candidates, 'worktree_candidates' => $candidates ),
		'apply_plan'               => array( 'artifact_candidates' => $candidates, 'worktree_candidates' => $candidates ),
		'selected_artifact_count'  => 128,
		'selected_worktree_count'  => 0,
		'inode_recovery_plan'      => array( 'planned_measured_recovery_bytes' => 123456, 'planned_measured_recovery_inodes' => 789, 'target_met' => true ),
		'action_required'           => true,
		'action_required_reasons'   => array( 'worktree_deletion_requires_human_approval' ),
		'scheduled_summary'         => array( 'scheduled_chunks' => 1, 'scheduled_artifact_rows' => 128, 'batch_job_id' => 44, 'direct_job_ids' => array( 45, 46 ) ),
		'capacity_evidence'         => array(
			'reclaimed_bytes' => 900,
			'reclaimed_inodes' => 12,
			'before' => array( 'filesystem_total_bytes' => 10000, 'filesystem_free_bytes' => 100, 'filesystem_free_inodes' => 10, 'workspace_allocated_bytes' => 9000, 'unbounded' => str_repeat('x', 1024 * 1024) ),
			'after' => array( 'filesystem_total_bytes' => 10000, 'filesystem_free_bytes' => 1000, 'filesystem_free_inodes' => 22, 'workspace_allocated_bytes' => 8000, 'unbounded' => str_repeat('x', 1024 * 1024) ),
		),
	);

	$full_size = strlen((string) json_encode(DataMachineCode\Workspace\Workspace::$result, JSON_THROW_ON_ERROR));
	durable_result_assert($full_size > 4 * 1024 * 1024, 'Fixture must contain a multi-megabyte explicit workspace report.');

	$task = new DataMachineCode\Tasks\WorkspaceDiskEmergencyCleanupTask();
	$task->executeTask(42, array( 'dry_run' => true ));
	$log_context = $GLOBALS['emergency_cleanup_logs'][0][2];
	$completed = $task->completed[42];
	$log_size = strlen((string) json_encode($log_context, JSON_THROW_ON_ERROR));
	$completed_size = strlen((string) json_encode($completed, JSON_THROW_ON_ERROR));

	durable_result_assert($log_size <= 8192, 'Durable log context must remain at or below 8192 serialized bytes.');
	durable_result_assert($completed_size <= 8192, 'Completed job result must remain at or below 8192 serialized bytes.');
	durable_result_assert(! isset($log_context['report']['emergency_plan']) && ! isset($completed['apply_plan']), 'Durable task records must not persist candidate plans.');
	durable_result_assert(128 === $completed['selected_artifact_count'] && array( 45, 46 ) === $completed['scheduled']['direct_job_ids'], 'Durable result must retain selected counts and scheduled job IDs.');
	durable_result_assert(array( 'filesystem_free_bytes_below_threshold' ) === $log_context['report']['trigger_reasons'], 'Durable log context must retain trigger reasons.');
	durable_result_assert(900 === $completed['measured_recovery']['reclaimed_bytes'] && 12 === $completed['measured_recovery']['reclaimed_inodes'], 'Durable result must retain measured recovery totals.');
	durable_result_assert(true === $completed['action_required'] && array( 'worktree_deletion_requires_human_approval' ) === $completed['action_required_reasons'], 'Durable result must retain action-required evidence.');
	durable_result_assert(100 === $completed['capacity_evidence']['before']['filesystem_free_bytes'] && 1000 === $completed['capacity_evidence']['after']['filesystem_free_bytes'], 'Durable result must retain compact capacity evidence.');

	echo "workspace-disk-emergency-cleanup-durable-result: log={$log_size} completed={$completed_size} bytes\n";
}
