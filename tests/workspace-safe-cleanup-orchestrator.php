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

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceSafeCleanupOrchestrator.php';

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

function safe_cleanup_assert( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite(STDERR, 'failed: ' . $label . PHP_EOL);
		exit(1);
	}
}

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
	}
);

$result = $orchestrator->run(array( 'limit' => 7, 'passes' => 4, 'cycles' => 3 ));
safe_cleanup_assert(! is_wp_error($result), 'safe cleanup succeeds');
safe_cleanup_assert(true === $result['applied'], 'safe cleanup applies by default');
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

$preview_lock_calls = array();
$preview = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => new SafeCleanupQueuedAbility(array( array( 'success' => true, 'summary' => array( 'would_remove' => 1 ) ) )),
	static function ( bool $dry_run ) use ( &$preview_lock_calls ): array {
		$preview_lock_calls[] = $dry_run;
		return array( 'dry_run' => $dry_run, 'after' => array( 'active' => 0, 'stale' => 1 ), 'filesystem' => array( 'removed_count' => 0 ) );
	}
);
$preview_result = $preview->run(array( 'dry_run' => true, 'cycles' => 3 ));
safe_cleanup_assert(! is_wp_error($preview_result), 'preview succeeds');
safe_cleanup_assert(false === $preview_result['applied'], 'preview does not apply');
safe_cleanup_assert(array( true, true ) === $preview_lock_calls, 'preview lock pruning stays dry-run');
safe_cleanup_assert(1 === ( $preview_result['summary']['cycles'] ?? null ), 'preview runs one cycle');

fwrite(STDOUT, "workspace safe cleanup orchestrator test passed\n");
