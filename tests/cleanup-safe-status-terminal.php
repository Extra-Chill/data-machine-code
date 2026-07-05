<?php
/**
 * Standalone coverage for safe cleanup status terminalization.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	if ( ! class_exists(Workspace::class) ) {
		class Workspace {}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( string $code = '', string $message = '', array $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
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

	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepository.php';
	require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';
	require_once dirname(__DIR__) . '/inc/Workspace/CleanupRunService.php';

	final class SafeCleanupStatusRepository extends DataMachineCode\Storage\CleanupRunRepository {
		/** @var array<string,array<string,mixed>> */
		public array $runs = array();

		/** @var array<string,array<int,array<string,mixed>>> */
		public array $items = array();

		/** @var array<int,array<string,mixed>> */
		public array $updates = array();

		public function get_run( string $run_id ): ?array {
			return $this->runs[ $run_id ] ?? null;
		}

		public function get_items( string $run_id ): array {
			return $this->items[ $run_id ] ?? array();
		}

		public function update_run( string $run_id, array $fields ): bool {
			$this->updates[] = array( 'run_id' => $run_id, 'fields' => $fields );
			$this->runs[ $run_id ] = array_merge($this->runs[ $run_id ] ?? array(), $fields);
			return true;
		}
	}

	function safe_status_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	function safe_status_assert_false_contains( array $values, string $needle, string $message ): void {
		foreach ( $values as $value ) {
			if ( is_string($value) && str_contains($value, $needle) ) {
				throw new RuntimeException($message . "\nUnexpected value: " . $value);
			}
		}
	}

	$repo = new SafeCleanupStatusRepository();
	$repo->runs['cleanup-run-empty-safe'] = array(
		'run_id'       => 'cleanup-run-empty-safe',
		'mode'         => 'safe_workspace_cleanup',
		'status'       => 'applying',
		'started_at'   => gmdate('Y-m-d H:i:s', time() - 600),
		'completed_at' => null,
		'summary'      => array(
			'safe_cleanup_progress' => array(
				'state'    => 'applying',
				'summary'  => array( 'blocker_count' => 0 ),
				'commands' => array(
					'status' => 'studio wp datamachine-code workspace cleanup status cleanup-run-empty-safe --format=json',
					'resume' => 'studio wp datamachine-code workspace cleanup safe --format=json',
				),
			),
		),
	);

	$service = new DataMachineCode\Workspace\CleanupRunService($repo);
	$status  = $service->status('cleanup-run-empty-safe');
	safe_status_assert_same(false, is_wp_error($status), 'Empty safe cleanup status should succeed.');
	safe_status_assert_same('complete', $status['state'] ?? null, 'Empty safe cleanup should finalize to complete.');
	safe_status_assert_same('complete', $repo->runs['cleanup-run-empty-safe']['status'] ?? null, 'Empty safe cleanup terminal status should be persisted.');
	safe_status_assert_same(false, $status['progress']['resumable'] ?? null, 'Empty safe cleanup should not be resumable.');
	safe_status_assert_false_contains((array) ( $status['remaining_work_summary']['next_commands'] ?? array() ), 'workspace cleanup safe', 'Empty safe cleanup should not recommend safe resume.');
	safe_status_assert_false_contains((array) ( $status['remaining_work_summary']['next_commands'] ?? array() ), 'workspace cleanup resume', 'Empty safe cleanup should not recommend DB resume.');

	$evidence = $service->evidence('cleanup-run-empty-safe');
	safe_status_assert_same(false, is_wp_error($evidence), 'Empty safe cleanup evidence should succeed.');
	safe_status_assert_same('complete', $evidence['state'] ?? null, 'Evidence should report terminal state.');
	safe_status_assert_same(array(), $evidence['items'] ?? null, 'Evidence should keep empty item list explicit.');

	$repo->runs['cleanup-run-reclaimed-safe'] = array(
		'run_id'       => 'cleanup-run-reclaimed-safe',
		'mode'         => 'safe_workspace_cleanup',
		'status'       => 'complete',
		'started_at'   => gmdate('Y-m-d H:i:s', time() - 300),
		'completed_at' => gmdate('Y-m-d H:i:s'),
		'summary'      => array(
			'safe_cleanup_progress' => array(
				'state'   => 'complete',
				'summary' => array(
					'removed'         => 2,
					'bytes_reclaimed' => 4096,
				),
			),
		),
	);
	$reclaimed_status = $service->status('cleanup-run-reclaimed-safe');
	safe_status_assert_same(false, is_wp_error($reclaimed_status), 'Safe cleanup status with reclaimed bytes should succeed.');
	safe_status_assert_same(2, $reclaimed_status['summary']['removed'] ?? null, 'Safe cleanup status summary should preserve removed count.');
	safe_status_assert_same(4096, $reclaimed_status['summary']['bytes_reclaimed'] ?? null, 'Safe cleanup status summary should preserve reclaimed bytes.');
	safe_status_assert_same(2, $reclaimed_status['cleanup_items']['applied_rows'] ?? null, 'Safe cleanup cleanup_items should expose removed count as applied rows.');
	safe_status_assert_same(4096, $reclaimed_status['cleanup_items']['bytes_reclaimed'] ?? null, 'Safe cleanup cleanup_items should expose reclaimed bytes.');
	safe_status_assert_same(4096, $reclaimed_status['remaining_work_summary']['total_bytes_reclaimed'] ?? null, 'Safe cleanup remaining summary should expose reclaimed bytes.');
	safe_status_assert_same(2, $reclaimed_status['remaining_work_summary']['applied_by_type']['safe_workspace_cleanup']['count'] ?? null, 'Safe cleanup remaining summary should expose removed count by type.');

	$reclaimed_evidence = $service->evidence('cleanup-run-reclaimed-safe');
	safe_status_assert_same(false, is_wp_error($reclaimed_evidence), 'Safe cleanup evidence with reclaimed bytes should succeed.');
	safe_status_assert_same(2, $reclaimed_evidence['cleanup_items']['applied_rows'] ?? null, 'Safe cleanup evidence should preserve removed count.');
	safe_status_assert_same(4096, $reclaimed_evidence['cleanup_items']['bytes_reclaimed'] ?? null, 'Safe cleanup evidence should preserve reclaimed bytes.');

	$repo->runs['cleanup-run-blocked-safe'] = array(
		'run_id'  => 'cleanup-run-blocked-safe',
		'mode'    => 'safe_workspace_cleanup',
		'status'  => 'applying',
		'summary' => array(
			'safe_cleanup_progress' => array(
				'summary' => array( 'blocker_count' => 2 ),
			),
		),
	);
	$blocked = $service->status('cleanup-run-blocked-safe');
	safe_status_assert_same('complete_with_blockers', $blocked['state'] ?? null, 'Empty safe cleanup with saved blockers should finalize distinctly.');

	$repo->runs['cleanup-run-pending'] = array(
		'run_id'  => 'cleanup-run-pending',
		'mode'    => 'cleanup_plan',
		'status'  => 'applying',
		'summary' => array(),
	);
	$repo->items['cleanup-run-pending'] = array(
		array(
			'id'        => 1,
			'handle'    => 'repo@branch',
			'item_type' => 'worktree_removal',
			'status'    => 'pending',
			'evidence'  => array(),
		),
	);
	$pending = $service->status('cleanup-run-pending');
	safe_status_assert_same('applying', $pending['state'] ?? null, 'Applying run with pending work should stay applying.');
	safe_status_assert_same(true, $pending['progress']['resumable'] ?? null, 'Applying run with pending work should remain resumable.');

	echo "cleanup safe status terminal test passed.\n";
}
