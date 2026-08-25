<?php
/**
 * End-to-end JSON rendering coverage for bounded worktree-add output.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	final class Worktree_Add_Cli_Halt extends \RuntimeException {
		public function __construct( public readonly int $status ) { parent::__construct('WP-CLI halted.'); }
	}

	final class WP_CLI {
		/** @var list<string> */
		public static array $lines = array();
		/** @var list<string> */
		public static array $warnings = array();
		public static function line( string $message ): void { self::$lines[] = $message; }
		public static function warning( string $message ): void { self::$warnings[] = $message; }
		public static function error( string $message ): void { throw new \RuntimeException($message); }
		public static function halt( int $status ): never { throw new Worktree_Add_Cli_Halt($status); }
	}

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function wp_get_ability( string $name ): ?object { return $GLOBALS['worktree_add_cli_abilities'][ $name ] ?? null; }

	final class Worktree_Add_Cli_Ability {
		public function __construct( private array|WP_Error $result ) {}
		public function execute( array $input ): array|WP_Error {
			if ( isset($input['progress_callback']) && is_callable($input['progress_callback']) ) {
				$input['progress_callback']( array( 'operation' => 'workspace_mutation_lock', 'phase' => 'lock_request', 'request_id' => 'request-123', 'scope' => 'repo', 'queue_position' => 2 ) );
				$input['progress_callback']( array( 'operation' => 'workspace_mutation_lock', 'phase' => 'lock_wait', 'request_id' => 'request-123', 'scope' => 'repo', 'queue_position' => 2, 'owner' => array( 'owner' => 'run-456' ), 'elapsed_seconds' => 5.001, 'estimated_wait_seconds' => 7 ) );
				foreach ( array( 'post_create_validation', 'staleness_probe', 'rebase', 'default_branch_probe', 'post_rebase_demand_planning', 'post_rebase_capacity_inspection', 'post_rebase_artifact_reclamation', 'bootstrap_start', 'bootstrap_complete' ) as $phase ) {
					$input['progress_callback']( array( 'operation' => 'worktree_add', 'phase' => $phase ) );
				}
			}
			return $this->result;
		}
	}

	function worktree_add_cli_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	define('ABSPATH', __DIR__ . '/fixtures/');
	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/WorkspaceCompactOutput.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$raw_success = array(
		'success'        => true,
		'handle'         => 'repo@budgeted-output',
		'path'           => '/workspace/repo@budgeted-output',
		'branch'         => 'budgeted-output',
		'base'           => 'origin/main',
		'created_branch' => true,
		'disk_budget'    => array(
			'status'                 => 'warning',
			'worktree_count'         => 10000,
			'free_bytes'             => 123456789,
			'free_inodes'            => 987654321,
			'projected_demand_bytes' => 999999999,
			'trigger_reasons'        => array( 'worktree_count_warning_threshold' ),
			'calibration'            => array_fill(0, 100, str_repeat('capacity detail ', 100)),
		),
		'bootstrap' => array(
			'success'     => true,
			'ran_any'     => true,
			'duration_ms' => 123,
			'steps'       => array_fill(0, 20, array(
				'step'            => 'composer',
				'status'          => 'ran',
				'duration_ms'     => 120,
				'output_tail'     => str_repeat('bootstrap output ', 1000),
				'output_evidence' => array( 'retained_bytes' => 4096, 'sha256' => str_repeat('a', 64), 'cap_bytes' => 4096 ),
			)),
		),
	);
	$GLOBALS['worktree_add_cli_abilities'] = array(
		'datamachine-code/workspace-worktree-add' => new Worktree_Add_Cli_Ability( \DataMachineCode\Cli\WorkspaceCompactOutput::worktree_add_result($raw_success) ),
	);

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$command->__worktree_operation('add', array( 'repo', 'budgeted-output' ), array( 'format' => 'json', 'skip-bootstrap' => true ));
	$output = implode("\n", WP_CLI::$lines);
	$payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	worktree_add_cli_assert(strlen($output) <= 4096, 'Successful worktree-add JSON exceeded its 4 KiB output budget.');
	worktree_add_cli_assert(count((array) ( $payload['bootstrap']['steps'] ?? array() )) <= 5, 'Successful worktree-add JSON exceeded its bootstrap step item budget.');
	worktree_add_cli_assert(in_array('worktree_count_warning_threshold', (array) ( $payload['warning_codes'] ?? array() ), true), 'Successful worktree-add JSON did not retain the stable worktree-count warning code.');
	worktree_add_cli_assert(! isset($payload['capacity']['worktree_count']) && ! isset($payload['capacity']['free_bytes']) && ! isset($payload['capacity']['projected_demand_bytes']), 'Successful worktree-add JSON exposed detailed capacity projections.');
	worktree_add_cli_assert(! isset($payload['bootstrap']['steps'][0]['output_tail']) && ! isset($payload['bootstrap']['steps'][0]['output_evidence']), 'Successful worktree-add JSON exposed bootstrap command evidence.');
	worktree_add_cli_assert(array( 'Worktree add progress: lock request (request=request-123; scope=repo; queue=2).', 'Worktree add progress: lock wait (request=request-123; scope=repo; queue=2; owner=run-456; waited=5.001s; eta=7s).', 'Worktree add progress: post create validation.', 'Worktree add progress: staleness probe.', 'Worktree add progress: rebase.', 'Worktree add progress: default branch probe.', 'Worktree add progress: post rebase demand planning.', 'Worktree add progress: post rebase capacity inspection.', 'Worktree add progress: post rebase artifact reclamation.', 'Worktree add progress: bootstrap start.', 'Worktree add progress: bootstrap complete.' ) === WP_CLI::$warnings, 'Worktree-add JSON progress was not routed to the stderr warning channel with lock identity and queue evidence.');

	WP_CLI::$lines = array();
	$GLOBALS['worktree_add_cli_abilities']['datamachine-code/workspace-worktree-add'] = new Worktree_Add_Cli_Ability(
		new WP_Error('worktree_disk_budget_exceeded', 'Capacity admission failed.', array( 'trigger_reasons' => array( 'projected_free_bytes_absolute_refusal_floor' ), 'disk_budget' => array( 'projected_free_bytes' => 1 ) ))
	);
	try {
		$command->__worktree_operation('add', array( 'repo', 'refused-output' ), array( 'format' => 'json' ));
		throw new \RuntimeException('Refused worktree-add JSON did not halt.');
	} catch (Worktree_Add_Cli_Halt $halt) {
		worktree_add_cli_assert(1 === $halt->status, 'Refused worktree-add JSON returned the wrong exit status.');
	}
	$failure = json_decode(implode("\n", WP_CLI::$lines), true, 512, JSON_THROW_ON_ERROR);
	worktree_add_cli_assert(false === ( $failure['success'] ?? true ) && 'worktree_disk_budget_exceeded' === ( $failure['error']['code'] ?? null ), 'Refused worktree-add JSON lost its typed diagnostic code.');
	worktree_add_cli_assert(1 === ( $failure['error']['data']['disk_budget']['projected_free_bytes'] ?? null ), 'Refused worktree-add JSON lost its detailed diagnostic evidence.');

	WP_CLI::$lines = array();
	$GLOBALS['worktree_add_cli_abilities']['datamachine-code/workspace-worktree-add'] = new Worktree_Add_Cli_Ability(
		new WP_Error(
			'workspace_sqlite_lock_contention',
			'SQLite remained locked while updating workspace ownership.',
			array(
				'operation'     => 'workspace_lock_register',
				'blocker_phase' => 'workspace_lock_register',
				'request_id'    => 'request-sqlite',
				'retryable'     => true,
				'wpdb_error'    => '<div>SQLSTATE[HY000]: database is locked</div>',
				'debug'         => array( 'backtrace' => '/local/site/wp-content/plugins/sqlite.php:123' ),
			)
		)
	);
	try {
		$command->__worktree_operation('add', array( 'repo', 'contended-output' ), array( 'format' => 'json' ));
		throw new \RuntimeException('Contended worktree-add JSON did not halt.');
	} catch (Worktree_Add_Cli_Halt $halt) {
		worktree_add_cli_assert(1 === $halt->status, 'Contended worktree-add JSON returned the wrong exit status.');
	}
	$contended_output = implode("\n", WP_CLI::$lines);
	$contended = json_decode($contended_output, true, 512, JSON_THROW_ON_ERROR);
	worktree_add_cli_assert('workspace_sqlite_lock_contention' === ($contended['error']['code'] ?? null), 'Public CLI envelope lost typed SQLite contention.');
	worktree_add_cli_assert('workspace_lock_register' === ($contended['error']['data']['blocker_phase'] ?? null) && 'request-sqlite' === ($contended['error']['data']['request_id'] ?? null), 'Public CLI envelope lost blocker or request identity.');
	worktree_add_cli_assert(!str_contains(strtolower($contended_output), 'sqlstate') && !str_contains($contended_output, '<div>') && !str_contains($contended_output, '/local/site'), 'Public CLI envelope leaked WordPress database diagnostics.');

	echo "worktree-add-cli-output-budget: ok\n";
}
