<?php
/**
 * Smoke test for snapshot-safe cleanup pagination CLI routing.
 *
 *   php tests/smoke-workspace-cleanup-snapshot-pagination.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__);
	}

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class WP_CLI {
		public static array $logs = array();

		public static function error( string $message ): void {
			throw new RuntimeException($message);
		}

		public static function success( string $message ): void {
			self::$logs[] = $message;
		}

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
		return json_encode($data, $flags);
	}

	function wp_get_ability( string $name ): ?object {
		return $GLOBALS['datamachine_code_snapshot_abilities'][ $name ] ?? null;
	}

	class DataMachineCodeSnapshotFakeAbility {
		public array $calls = array();

		public function __construct( private mixed $result ) {}

		public function execute( array $input ): mixed {
			$this->calls[] = $input;
			return $this->result;
		}
	}
}

namespace DataMachine\Cli {
	class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			\WP_CLI::log('table:' . count($items));
		}
	}
}

namespace {
	include_once dirname(__DIR__) . '/inc/Cleanup/CleanupRunEvidenceStoreInterface.php';
	include_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';
	include_once dirname(__DIR__) . '/inc/Cleanup/DataMachineJobCleanupRunEvidenceStore.php';
	include_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function datamachine_code_snapshot_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
		echo "ok - {$message}\n";
	}

	$plan_ability = new DataMachineCodeSnapshotFakeAbility(
		array(
			'success'         => true,
			'run_id'          => 'cleanup-run-snapshot-page',
			'plan_id'         => 'cleanup-plan-stable',
			'inputs'          => array( 'include_artifacts' => true, 'include_worktrees' => false ),
			'summary'         => array( 'total_rows' => 4, 'total_size_bytes' => 185204736 ),
			'cleanup_storage' => array( 'type' => 'database', 'item_count' => 4 ),
		)
	);
	$run_ability  = new DataMachineCodeSnapshotFakeAbility(
		array(
			'success' => true,
			'state'   => 'jobs_queued',
			'run_id'  => 'cleanup-run-123',
			'job_id'  => 123,
		)
	);

	$GLOBALS['datamachine_code_snapshot_abilities'] = array(
		'datamachine-code/workspace-cleanup-plan' => $plan_ability,
		'datamachine-code/workspace-cleanup-run'  => $run_ability,
	);

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$command->cleanup(
		array( 'run' ),
		array(
			'mode'    => 'artifacts',
			'dry-run' => true,
			'limit'   => 100,
			'offset'  => 400,
			'format'  => 'json',
		)
	);

	datamachine_code_snapshot_assert(1 === count($plan_ability->calls), 'artifact dry-run uses DB-backed cleanup plan ability');
	datamachine_code_snapshot_assert(true === (bool) ( $plan_ability->calls[0]['include_artifacts'] ?? false ), 'artifact dry-run includes artifacts in persisted plan');
	datamachine_code_snapshot_assert(false === (bool) ( $plan_ability->calls[0]['include_worktrees'] ?? true ), 'artifact dry-run excludes worktree deletion rows');
	datamachine_code_snapshot_assert(! array_key_exists('offset', $plan_ability->calls[0]), 'artifact dry-run does not pass mutable offset into plan');
	datamachine_code_snapshot_assert(str_contains(implode("\n", WP_CLI::$logs), 'cleanup-run-snapshot-page'), 'artifact dry-run output exposes run_id for stable apply');

	WP_CLI::$logs = array();
	$command->cleanup(
		array( 'run' ),
		array(
			'mode'   => 'artifacts',
			'force'  => true,
			'limit'  => 100,
			'offset' => 400,
			'format' => 'json',
		)
	);

	datamachine_code_snapshot_assert(1 === count($run_ability->calls), 'artifact apply schedules background cleanup once');
	datamachine_code_snapshot_assert(! array_key_exists('offset', $run_ability->calls[0]), 'artifact apply does not forward mutable offset to background cleanup');
	datamachine_code_snapshot_assert(! array_key_exists('limit', $run_ability->calls[0]), 'artifact apply does not forward page limit to background cleanup');
	datamachine_code_snapshot_assert(true === (bool) ( $run_ability->calls[0]['force'] ?? false ), 'artifact apply preserves force flag');

	echo "Workspace cleanup snapshot pagination smoke passed.\n";
}
