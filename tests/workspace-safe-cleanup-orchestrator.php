<?php
/**
 * Standalone coverage for WorkspaceSafeCleanupOrchestrator.
 */

define('ABSPATH', dirname(__DIR__));

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

		public function get_error_code(): string {
			return $this->code;
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

$safe_cleanup_registered_abilities = array();

if ( ! function_exists('wp_register_ability') ) {
	function wp_register_ability( string $slug, array $args ): void {
		$GLOBALS['safe_cleanup_registered_abilities'][ $slug ] = $args;
	}
}

if ( ! function_exists('doing_action') ) {
	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook;
	}
}

if ( ! function_exists('add_action') ) {
	function add_action( string $hook, callable $callback ): void {
		if ( 'wp_abilities_api_init' === $hook ) {
			$callback();
		}
	}
}

require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceSafeCleanupOrchestrator.php';
require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

final class SafeCleanupQueuedAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/** @param array<int,array<string,mixed>> $responses */
	public function __construct( private array $responses ) {}

	/** @return array<string,mixed> */
	public function execute( array $input ): array {
		$this->calls[] = $input;
		return array_shift($this->responses) ?: array(
			'success' => true,
			'mode'    => 'empty',
			'summary' => array(),
		);
	}
}

final class SafeCleanupFakeRunRepository implements \DataMachineCode\Storage\CleanupRunRepositoryInterface {
	/** @var array<string,array<string,mixed>> */
	public array $runs = array();

	/** @var array<int,array<string,mixed>> */
	public array $updates = array();

	public function create_run( array $run ): string {
		$run_id = 'cleanup-run-safe-test';
		$this->runs[ $run_id ] = $run + array( 'run_id' => $run_id );
		return $run_id;
	}

	public function update_run( string $run_id, array $fields ): bool {
		$this->updates[] = array( 'run_id' => $run_id, 'fields' => $fields );
		$this->runs[ $run_id ] = array_merge($this->runs[ $run_id ] ?? array( 'run_id' => $run_id ), $fields);
		return true;
	}
}

function safe_cleanup_assert( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite(STDERR, 'failed: ' . $label . PHP_EOL);
		exit(1);
	}
}

new DataMachineCode\Abilities\WorkspaceAbilities();
$safe_cleanup_ability = $GLOBALS['safe_cleanup_registered_abilities']['datamachine-code/workspace-cleanup-safe'] ?? null;
safe_cleanup_assert(is_array($safe_cleanup_ability), 'safe cleanup ability is registered');
safe_cleanup_assert(array( DataMachineCode\Abilities\WorkspaceAbilities::class, 'workspaceCleanupSafe' ) === $safe_cleanup_ability['execute_callback'], 'safe cleanup ability uses canonical callback');
safe_cleanup_assert(isset($safe_cleanup_ability['input_schema']['properties']['dry_run']), 'safe cleanup ability accepts dry_run');
safe_cleanup_assert(isset($safe_cleanup_ability['input_schema']['properties']['force']), 'safe cleanup ability documents force refusal');
safe_cleanup_assert(isset($safe_cleanup_ability['input_schema']['properties']['discard_unpushed']), 'safe cleanup ability documents discard refusal');
safe_cleanup_assert(isset($safe_cleanup_ability['output_schema']['properties']['summary']), 'safe cleanup ability documents summary output');
safe_cleanup_assert(isset($safe_cleanup_ability['output_schema']['properties']['blockers']), 'safe cleanup ability documents blockers output');
safe_cleanup_assert(isset($safe_cleanup_ability['output_schema']['properties']['run_id']), 'safe cleanup ability documents run_id output');
safe_cleanup_assert(isset($safe_cleanup_ability['output_schema']['properties']['continuation']), 'safe cleanup ability documents continuation output');

$ability_force_result = DataMachineCode\Abilities\WorkspaceAbilities::workspaceCleanupSafe(array( 'force' => true ));
safe_cleanup_assert(is_wp_error($ability_force_result), 'safe cleanup ability callback executes orchestrator refusal');
safe_cleanup_assert('safe_cleanup_refuses_force' === $ability_force_result->code, 'safe cleanup ability force refusal code');

$empty_ability = new SafeCleanupQueuedAbility(array());
$orchestrator  = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $empty_ability,
	static fn( bool $dry_run ) => array( 'dry_run' => $dry_run, 'after' => array( 'active' => 0, 'stale' => 0 ), 'filesystem' => array( 'removed_count' => 0 ) )
);
$force_result  = $orchestrator->run(array( 'force' => true ));
safe_cleanup_assert(is_wp_error($force_result), 'force is refused');
safe_cleanup_assert('safe_cleanup_refuses_force' === $force_result->code, 'force refusal code');

$discard_result = $orchestrator->run(array( 'discard_unpushed' => true ));
safe_cleanup_assert(is_wp_error($discard_result), 'discard_unpushed is refused');
safe_cleanup_assert('safe_cleanup_refuses_unpushed_discard' === $discard_result->code, 'discard refusal code');

$cleanup_eligible = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'mode'    => 'cleanup_eligible_drain',
			'summary' => array(
				'removed'          => 1,
				'bytes_reclaimed'  => 1024,
			),
			'pass_results' => array(
				array( 'skipped_by_reason' => array( 'dirty_worktree' => 1 ) ),
			),
		),
		array(
			'success' => true,
			'mode'    => 'cleanup_eligible_drain',
			'summary' => array( 'removed' => 0 ),
		),
	)
);
$active_no_signal = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'mode'    => 'active_no_signal_drain',
			'summary' => array(
				'marked_cleanup_eligible' => 1,
				'removed'                 => 1,
				'blocked_by_reason'       => array( 'unpushed_commits' => 2 ),
			),
		),
		array(
			'success' => true,
			'mode'    => 'active_no_signal_drain',
			'summary' => array( 'marked_cleanup_eligible' => 0, 'removed' => 0 ),
			'remaining_active_no_signal_backlog' => array(
				'by_actionable_reason' => array(
					'insufficient_signal' => array( 'count' => 3 ),
				),
			),
		),
	)
);
$lock_calls = array();
$run_repository = new SafeCleanupFakeRunRepository();
$progress_envelopes = array();
$orchestrator = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => match ( $name ) {
		'datamachine-code/workspace-worktree-cleanup-eligible-drain' => $cleanup_eligible,
		'datamachine-code/workspace-worktree-active-no-signal-drain' => $active_no_signal,
		default => null,
	},
	static function ( bool $dry_run ) use ( &$lock_calls ): array {
		$lock_calls[] = $dry_run;
		return array(
			'dry_run'    => $dry_run,
			'after'      => array( 'active' => 0, 'stale' => 0 ),
			'filesystem' => array( 'removed_count' => $dry_run ? 0 : 1, 'skipped_count' => 0 ),
		);
	},
	$run_repository
);

$result = $orchestrator->run(
	array(
		'limit'             => 7,
		'passes'            => 4,
		'cycles'            => 3,
		'progress_callback' => static function ( array $progress ) use ( &$progress_envelopes ): void {
			$progress_envelopes[] = $progress;
		},
	)
);
safe_cleanup_assert(! is_wp_error($result), 'safe cleanup succeeds');
safe_cleanup_assert(true === $result['applied'], 'safe cleanup applies by default');
safe_cleanup_assert('cleanup-run-safe-test' === ( $result['run_id'] ?? null ), 'safe cleanup returns durable run id');
safe_cleanup_assert('cleanup-run-safe-test' === ( $progress_envelopes[0]['run_id'] ?? null ), 'safe cleanup emits early run id before long child work');
safe_cleanup_assert('applying' === ( $progress_envelopes[0]['state'] ?? null ), 'early progress reports applying state');
safe_cleanup_assert(str_contains((string) ( $result['continuation']['status_command'] ?? '' ), 'workspace cleanup status cleanup-run-safe-test'), 'continuation exposes status command');
safe_cleanup_assert(str_contains((string) ( $result['continuation']['resume_command'] ?? '' ), 'workspace cleanup safe --limit=7 --passes=4 --cycles=3'), 'continuation exposes safe resume command');
safe_cleanup_assert(2 === count($lock_calls), 'stale locks pruned before and after cleanup');
safe_cleanup_assert(false === $lock_calls[0] && false === $lock_calls[1], 'lock pruning is destructive only in apply mode');
safe_cleanup_assert(2 === count($cleanup_eligible->calls), 'cleanup eligible drain repeats until no progress');
safe_cleanup_assert(false === $cleanup_eligible->calls[0]['force'], 'child force is false');
safe_cleanup_assert(false === $cleanup_eligible->calls[0]['discard_unpushed'], 'child discard_unpushed is false');
safe_cleanup_assert(2 === ( $result['summary']['removed'] ?? null ), 'removed rows are accumulated');
safe_cleanup_assert(1 === ( $result['summary']['marked_cleanup_eligible'] ?? null ), 'marked cleanup eligible rows are accumulated');
safe_cleanup_assert(2 === ( $result['summary']['lock_files_removed'] ?? null ), 'lock removals are accumulated');
safe_cleanup_assert(6 === ( $result['summary']['blocker_count'] ?? null ), 'compact blockers are counted');
safe_cleanup_assert(1 === ( $result['summary']['blockers_by_reason']['dirty_worktree'] ?? null ), 'dirty blocker count is preserved');
safe_cleanup_assert(2 === ( $result['summary']['blockers_by_reason']['unpushed_commits'] ?? null ), 'unpushed blocker count is preserved');
safe_cleanup_assert(3 === ( $result['summary']['blockers_by_reason']['insufficient_signal'] ?? null ), 'active backlog blocker count is preserved');
safe_cleanup_assert(count($run_repository->updates) >= 5, 'safe cleanup checkpoints progress repeatedly');
safe_cleanup_assert('complete_with_blockers' === ( $run_repository->runs['cleanup-run-safe-test']['status'] ?? null ), 'safe cleanup persists final run state');
safe_cleanup_assert(2 === ( $run_repository->runs['cleanup-run-safe-test']['summary']['safe_cleanup_progress']['summary']['removed'] ?? null ), 'safe cleanup persists reclaimed progress summary');

$preview_lock_calls = array();
$preview = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => new SafeCleanupQueuedAbility(array( array( 'success' => true, 'summary' => array( 'would_remove' => 1 ) ) )),
	static function ( bool $dry_run ) use ( &$preview_lock_calls ): array {
		$preview_lock_calls[] = $dry_run;
		return array( 'dry_run' => $dry_run, 'after' => array( 'active' => 0, 'stale' => 1 ), 'filesystem' => array( 'removed_count' => 0 ) );
	},
	new SafeCleanupFakeRunRepository()
);
$preview_result = $preview->run(array( 'dry_run' => true, 'cycles' => 3 ));
safe_cleanup_assert(! is_wp_error($preview_result), 'preview succeeds');
safe_cleanup_assert(false === $preview_result['applied'], 'preview does not apply');
safe_cleanup_assert(array( true, true ) === $preview_lock_calls, 'preview lock pruning stays dry-run');
safe_cleanup_assert(1 === ( $preview_result['summary']['cycles'] ?? null ), 'preview runs one cycle');

fwrite(STDOUT, "workspace safe cleanup orchestrator test passed\n");
