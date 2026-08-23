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

namespace DataMachine\Cli {
	if ( ! class_exists(BaseCommand::class) ) {
		abstract class BaseCommand {
			protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {}
		}
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

	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode($value, $flags, $depth);
		}
	}

	if ( ! class_exists('WP_CLI') ) {
		class WP_CLI {
			public static string $output = '';

			public static function line( string $message ): void {
				self::$output .= $message;
			}
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

		public function list_runs( array $filters = array() ): array {
			return array_values(array_filter($this->runs, static fn( array $run ): bool => ! isset($filters['request_id']) || (string) ( $run['policy']['request_id'] ?? '' ) === (string) $filters['request_id']));
		}
	}

	final class SafeCleanupCliAbility {
		/** @param array<string,mixed> $input */
		public function execute( array $input ): array {
			if ( isset($input['progress_callback']) ) {
				$input['progress_callback'](array(
					'state'   => 'applying',
					'summary' => array( 'cycles' => 0, 'removed' => 0 ),
				));
			}

			return array(
				'state'   => 'complete',
				'summary' => array( 'cycles' => 1, 'removed' => 2 ),
			);
		}
	}

	final class SafeCleanupQueuedCliAbility {
		public function execute( array $input ): array {
			return array(
				'success'    => true,
				'state'      => 'queued',
				'run_id'     => 'cleanup-run-disconnected-safe',
				'mode'       => 'safe_workspace_cleanup',
				'request_id' => (string) ( $input['request_id'] ?? '' ),
				'commands'   => array( 'status' => 'studio wp datamachine-code workspace cleanup status cleanup-run-disconnected-safe --format=json' ),
			);
		}
	}

	$cleanup_safe_cli_ability = new SafeCleanupCliAbility();
	$cleanup_safe_queued_cli_ability = new SafeCleanupQueuedCliAbility();
	if ( ! function_exists('wp_get_ability') ) {
		function wp_get_ability( string $name ): mixed {
			return match ( $name ) {
				'datamachine-code/workspace-cleanup-safe' => $GLOBALS['cleanup_safe_cli_ability'],
				'datamachine-code/workspace-cleanup-safe-run' => $GLOBALS['cleanup_safe_queued_cli_ability'],
				default => null,
			};
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
				'state'    => 'complete',
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
				'state'   => 'complete_with_blockers',
				'summary' => array( 'blocker_count' => 2 ),
			),
		),
	);
	$blocked = $service->status('cleanup-run-blocked-safe');
	safe_status_assert_same('complete_with_blockers', $blocked['state'] ?? null, 'Empty safe cleanup with saved blockers should finalize distinctly.');

	$repo->runs['cleanup-run-historical-blockers'] = array(
		'run_id'  => 'cleanup-run-historical-blockers',
		'mode'    => 'safe_workspace_cleanup',
		'status'  => 'applying',
		'summary' => array(
			'safe_cleanup_progress' => array(
				'state'   => 'complete',
				'summary' => array(
					'blocker_count'       => 2,
					'blocker_count_scope' => 'sum_of_per_reason_maximum_observations_across_stages',
				),
			),
		),
	);
	$historical_blockers = $service->status('cleanup-run-historical-blockers');
	safe_status_assert_same('complete', $historical_blockers['state'] ?? null, 'Historical blocker observations must not finalize a safe cleanup as currently blocked.');

	$repo->runs['cleanup-run-current-blockers'] = array(
		'run_id'  => 'cleanup-run-current-blockers',
		'mode'    => 'safe_workspace_cleanup',
		'status'  => 'applying',
		'summary' => array(
			'safe_cleanup_progress' => array(
				'state'   => 'complete_with_blockers',
				'summary' => array(
					'blocker_count'         => 2,
					'blocker_count_scope'   => 'sum_of_per_reason_maximum_observations_across_stages',
					'current_blocker_count' => 1,
				),
			),
		),
	);
	$current_blockers = $service->status('cleanup-run-current-blockers');
	safe_status_assert_same('complete_with_blockers', $current_blockers['state'] ?? null, 'Current safe cleanup blockers must finalize distinctly from historical maxima.');

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

	$repo->runs['cleanup-run-active-safe'] = array(
		'run_id' => 'cleanup-run-active-safe', 'mode' => 'safe_workspace_cleanup', 'status' => 'applying',
		'summary' => array( 'safe_cleanup_progress' => array( 'state' => 'applying', 'summary' => array( 'blocker_count' => 0 ) ) ),
	);
	$active_safe = $service->status('cleanup-run-active-safe');
	safe_status_assert_same('applying', $active_safe['state'] ?? null, 'Concurrent polling must not terminalize an item-less safe run while its task is active.');

	$repo->runs['cleanup-run-queued-safe'] = array(
		'run_id' => 'cleanup-run-queued-safe', 'mode' => 'safe_workspace_cleanup', 'status' => 'queued',
		'summary' => array( 'safe_cleanup_progress' => array( 'state' => 'queued', 'summary' => array() ) ),
	);
	$queued_safe = $service->status('cleanup-run-queued-safe');
	safe_status_assert_same('queued', $queued_safe['state'] ?? null, 'Concurrent polling must preserve a queued item-less safe run.');

	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';
	WP_CLI::$output = '';
	$command = new DataMachineCode\Cli\Commands\WorkspaceCommand();
	$command->cleanup(array( 'safe' ), array( 'format' => 'json', 'request-id' => 'disconnected-client-1' ));
	$json_output = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	safe_status_assert_same('queued', $json_output['state'] ?? null, 'Safe cleanup JSON stdout must return the durable initial envelope before child execution.');
	safe_status_assert_same('cleanup-run-disconnected-safe', $json_output['run_id'] ?? null, 'Disconnected JSON callers must receive a resolvable safe cleanup run ID.');
	safe_status_assert_same('disconnected-client-1', $json_output['request_id'] ?? null, 'Initial envelope must preserve request correlation.');

	$repo->runs['cleanup-run-disconnected-safe'] = array(
		'run_id' => 'cleanup-run-disconnected-safe', 'mode' => 'safe_workspace_cleanup', 'status' => 'queued', 'created_at' => gmdate('Y-m-d H:i:s'),
		'policy' => array( 'dry_run' => true, 'source' => 'workspace_cleanup_cli', 'request_id' => 'disconnected-client-1' ),
	);
	$discovery = $service->list(array( 'request_id' => 'disconnected-client-1', 'limit' => 1 ));
	safe_status_assert_same('cleanup-run-disconnected-safe', $discovery['runs'][0]['run_id'] ?? null, 'Request correlation must deterministically recover a disconnected safe run.');
	safe_status_assert_same(true, $discovery['runs'][0]['preview'] ?? null, 'Discovery must distinguish preview safe runs.');
	safe_status_assert_same("studio wp datamachine-code workspace cleanup safe --format=json --request-id='disconnected-client-1'", $discovery['runs'][0]['commands']['resume'] ?? null, 'Safe discovery must resume through a new bounded safe pass with preserved correlation.');
	safe_status_assert_same('studio wp datamachine-code workspace cleanup cancel cleanup-run-disconnected-safe --format=json', $discovery['runs'][0]['commands']['cancel'] ?? null, 'Discovery rows must include canonical cancel commands.');

	echo "cleanup safe status terminal test passed.\n";
}
