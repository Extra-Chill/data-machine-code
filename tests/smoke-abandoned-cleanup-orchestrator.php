<?php
/**
 * Standalone smoke coverage for WorkspaceAbandonedCleanupOrchestrator.
 */

define('ABSPATH', dirname(__DIR__));
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);

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

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceAbandonedCleanupOrchestrator.php';

final class AbandonedCleanupFakeAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/**
	 * @param array<string,mixed> $summary    Summary payload to return.
	 * @param array<string,mixed> $pagination Pagination payload to return.
	 * @param array<int,array<string,mixed>> $skipped Skipped rows to return.
	 */
	public function __construct(
		private string $mode,
		private array $summary = array(),
		private array $pagination = array(),
		private array $skipped = array()
	) {}

	/** @return array<string,mixed> */
	public function execute( array $input ): array {
		$this->calls[] = $input;

		return array(
			'success'    => true,
			'mode'       => $this->mode,
			'dry_run'    => ! empty($input['dry_run']),
			'applied'    => empty($input['dry_run']),
			'summary'    => $this->summary,
			'pagination' => $this->pagination,
			'skipped'    => $this->skipped,
			'rows'       => $this->skipped,
		);
	}
}

final class AbandonedCleanupQueuedAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/** @param array<int,array<string,mixed>> $responses */
	public function __construct( private array $responses ) {}

	/** @return array<string,mixed> */
	public function execute( array $input ): array {
		$this->calls[] = $input;
		$response      = array_shift($this->responses) ?: array();

		return array_merge(
			array(
				'success'    => true,
				'mode'       => 'queued',
				'dry_run'    => ! empty($input['dry_run']),
				'applied'    => empty($input['dry_run']),
				'summary'    => array(),
				'pagination' => array( 'complete' => true ),
				'skipped'    => array(),
				'rows'       => array(),
			),
			$response
		);
	}
}

function abandoned_cleanup_assert( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite(STDERR, 'failed: ' . $label . PHP_EOL);
		exit(1);
	}
}

$abilities = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array( 'inspected' => 2, 'proposed' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => new AbandonedCleanupFakeAbility('finalized', array( 'inspected' => 1, 'planned' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => new AbandonedCleanupFakeAbility('equivalent_clean', array( 'inspected' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array( 'inspected' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array( 'inspected' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility(
		'bounded',
		array( 'processed' => 1, 'would_remove' => 1 ),
		array(),
		array(
			array(
				'handle'      => 'repo@branch',
				'reason_code' => 'dirty',
			),
		)
	),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);

$orchestrator = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $abilities[ $name ] ?? null,
	static fn(): float => 1000.0
);

$invalid = $orchestrator->run(array( 'stage' => 'unknown' ));
abandoned_cleanup_assert(is_wp_error($invalid), 'invalid stage returns WP_Error');
abandoned_cleanup_assert('invalid_worktree_abandoned_stage' === $invalid->code, 'invalid stage code');

$result = $orchestrator->run(array( 'limit' => 10, 'passes' => 3 ));
abandoned_cleanup_assert(! is_wp_error($result), 'preview result succeeds');
abandoned_cleanup_assert(false === $result['applied'], 'preview is not applied');
abandoned_cleanup_assert(1 === $result['executed_passes'], 'preview runs one pass');
abandoned_cleanup_assert(1 === $result['summary']['would_mark_cleanup_eligible'], 'planned metadata is counted');
abandoned_cleanup_assert(1 === $result['summary']['would_remove'], 'planned removal is counted');
abandoned_cleanup_assert(1 === $result['summary']['blocked'], 'blocked rows are counted');
abandoned_cleanup_assert(! empty($result['next_commands'][0]), 'next apply command is present');

$reconcile_restart = new AbandonedCleanupQueuedAbility(
	array(
		array(
			'mode'       => 'reconcile_metadata',
			'summary'    => array( 'inspected' => 1, 'written' => 1 ),
			'pagination' => array( 'offset' => 0, 'limit' => 10, 'scanned' => 1, 'partial' => true, 'complete' => false, 'next_offset' => 0 ),
		),
		array(
			'mode'       => 'reconcile_metadata',
			'summary'    => array( 'inspected' => 1, 'written' => 0 ),
			'pagination' => array( 'offset' => 0, 'limit' => 10, 'scanned' => 1, 'partial' => false, 'complete' => true, 'next_offset' => null ),
		),
	)
);
$restart_abilities = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => $reconcile_restart,
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => new AbandonedCleanupFakeAbility('finalized', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => new AbandonedCleanupFakeAbility('equivalent_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 0, 'removed' => 0 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$orchestrator      = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $restart_abilities[ $name ] ?? null,
	static fn(): float => 1000.0
);
$restart_result    = $orchestrator->run(array( 'apply' => true, 'limit' => 10, 'passes' => 1 ));
abandoned_cleanup_assert(! is_wp_error($restart_result), 'restart apply succeeds');
abandoned_cleanup_assert(2 === count($reconcile_restart->calls), 'mutating reconcile pagination restarts instead of stopping');
abandoned_cleanup_assert(0 === $reconcile_restart->calls[1]['offset'], 'second reconcile page restarts at offset zero');

$active_abilities = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array( 'inspected' => 99 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => new AbandonedCleanupFakeAbility('finalized', array( 'inspected' => 1, 'written' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => new AbandonedCleanupFakeAbility('equivalent_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-report' => new AbandonedCleanupFakeAbility(
		'active_no_signal_report',
		array(
			'total_active_no_signal' => 5,
			'inspected'              => 2,
			'by_suggested_action'    => array(
				'inspect_unpushed_or_dirty' => 1,
				'insufficient_signal'       => 1,
			),
		),
		array( 'complete' => false, 'total' => 5, 'next_command' => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=10 --offset=2 --format=json' ),
		array(
			array(
				'handle'           => 'repo@dirty',
				'repo'             => 'repo',
				'branch'           => 'dirty',
				'suggested_action' => 'inspect_unpushed_or_dirty',
				'dirty'            => 1,
				'unpushed'         => 0,
			),
			array(
				'handle'           => 'repo@unknown',
				'repo'             => 'repo',
				'branch'           => 'unknown',
				'suggested_action' => 'insufficient_signal',
			),
		)
	),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 1, 'removed' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$orchestrator     = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $active_abilities[ $name ] ?? null,
	static fn(): float => 1000.0
);
$active_result    = $orchestrator->run(array( 'active_no_signal_drain' => true, 'apply' => true, 'limit' => 10, 'passes' => 1 ));
abandoned_cleanup_assert(! is_wp_error($active_result), 'active/no-signal drain succeeds');
abandoned_cleanup_assert('active_no_signal_drain' === $active_result['mode'], 'active/no-signal drain mode is explicit');
abandoned_cleanup_assert(0 === count($active_abilities['datamachine-code/workspace-worktree-reconcile-metadata']->calls), 'active/no-signal drain skips reconcile metadata');
abandoned_cleanup_assert(1 === $active_result['summary']['marked_cleanup_eligible'], 'active/no-signal drain counts metadata promotions');
abandoned_cleanup_assert(2 === $active_result['summary']['removed'], 'active/no-signal drain removes bounded eligible rows before and after classification');
abandoned_cleanup_assert(5 === $active_result['remaining_active_no_signal_backlog']['total_active_no_signal'], 'active/no-signal drain summarizes remaining backlog total');
abandoned_cleanup_assert(2 === $active_result['remaining_active_no_signal_backlog']['sampled'], 'active/no-signal drain summarizes sampled backlog rows');
abandoned_cleanup_assert(1 === $active_result['remaining_active_no_signal_backlog']['by_actionable_reason']['inspect_unpushed_or_dirty']['count'], 'active/no-signal drain groups backlog by actionable reason');
abandoned_cleanup_assert(3 === $active_result['remaining_active_no_signal_backlog']['unreviewed_count'], 'active/no-signal drain reports unreviewed backlog count');
abandoned_cleanup_assert('bounded_post_drain_sample_only' === $active_result['remaining_active_no_signal_backlog']['counts_scope'], 'active/no-signal drain documents bounded counts scope');
abandoned_cleanup_assert(in_array('studio wp datamachine-code workspace worktree active-no-signal-report --limit=10 --offset=2 --format=json', $active_result['next_commands'], true), 'active/no-signal drain includes next report page command');

$force_result = $orchestrator->run(array( 'active_no_signal_drain' => true, 'apply' => true, 'force' => true ));
abandoned_cleanup_assert(is_wp_error($force_result), 'active/no-signal drain refuses force');
abandoned_cleanup_assert('active_no_signal_drain_refuses_force' === $force_result->code, 'active/no-signal drain force refusal code');

$candidate_set_changed = new AbandonedCleanupFakeAbility(
	'finalized',
	array( 'inspected' => 1, 'written' => 1 ),
	array( 'offset' => 10, 'limit' => 10, 'scanned' => 1, 'partial' => true, 'complete' => false, 'next_offset' => 10 )
);
$restart_abilities     = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => $candidate_set_changed,
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => new AbandonedCleanupFakeAbility('equivalent_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-report' => new AbandonedCleanupFakeAbility('active_no_signal_report', array( 'total_active_no_signal' => 0, 'inspected' => 0, 'by_suggested_action' => array() ), array( 'complete' => true, 'total' => 0 )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 0, 'removed' => 0 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$clock_index           = 0;
$clock_values          = array( 1000.0, 1000.0, 1000.0, 1000.0, 1002.0, 1002.0 );
$orchestrator          = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $restart_abilities[ $name ] ?? null,
	static function () use ( &$clock_index, $clock_values ): float {
		$value = $clock_values[ min($clock_index, count($clock_values) - 1) ];
		++$clock_index;
		return $value;
	}
);
$restart_result        = $orchestrator->run(array( 'active_no_signal_drain' => true, 'apply' => true, 'limit' => 10, 'passes' => 1, 'until_budget' => '1s' ));
abandoned_cleanup_assert(! is_wp_error($restart_result), 'active/no-signal restart result succeeds');
abandoned_cleanup_assert(! empty($restart_result['continuation']['candidate_set_changed_restart_required']), 'restart continuation exposes candidate set changed evidence');
abandoned_cleanup_assert('candidate_set_changed_restart_required' === $restart_result['continuation']['reason'], 'restart continuation exposes machine-readable restart reason');
abandoned_cleanup_assert(str_contains((string) $restart_result['continuation']['reason_description'], 'candidate set'), 'restart continuation explains why offset zero is expected');
abandoned_cleanup_assert(1 === (int) $restart_result['continuation']['progress_delta']['written'], 'restart continuation exposes written progress delta');
abandoned_cleanup_assert(1 === (int) $restart_result['continuation']['progress_delta']['total_mutations'], 'restart continuation exposes total mutation progress delta');
abandoned_cleanup_assert(10 === (int) $restart_result['continuation']['progress_delta']['previous_offset'], 'restart continuation documents the previous offset');
abandoned_cleanup_assert(10 === (int) $restart_result['continuation']['progress_delta']['next_offset'], 'restart continuation documents the unsafe candidate-set continuation offset');
abandoned_cleanup_assert(0 === (int) $restart_result['continuation']['progress_delta']['restart_offset'], 'restart continuation exposes the intentional restart offset');
abandoned_cleanup_assert(0 === (int) $restart_result['continuation']['offset'], 'restart continuation top-level offset matches the restart command offset');
abandoned_cleanup_assert(str_contains((string) $restart_result['continuation']['next_command_label'], 'candidate set changed'), 'restart continuation labels the restart command');
abandoned_cleanup_assert('active-no-signal-drain' === explode(' ', (string) $restart_result['continuation']['next_command'])[5], 'restart next command uses active/no-signal drain');
abandoned_cleanup_assert(str_contains((string) $restart_result['continuation']['next_command'], '--offset=0'), 'restart next command restarts active/no-signal drain at offset zero');

$stage_budget_abilities = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => new AbandonedCleanupFakeAbility('finalized', array( 'inspected' => 1, 'written' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => new AbandonedCleanupFakeAbility('equivalent_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-report' => new AbandonedCleanupFakeAbility('active_no_signal_report', array( 'total_active_no_signal' => 0, 'inspected' => 0, 'by_suggested_action' => array() ), array( 'complete' => true, 'total' => 0 )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 0, 'removed' => 0 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$clock_index            = 0;
$clock_values           = array( 1000.0, 1000.0, 1000.0, 1000.0, 1000.0, 1002.0, 1002.0 );
$orchestrator           = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $stage_budget_abilities[ $name ] ?? null,
	static function () use ( &$clock_index, $clock_values ): float {
		$value = $clock_values[ min($clock_index, count($clock_values) - 1) ];
		++$clock_index;
		return $value;
	}
);
$stage_budget_result    = $orchestrator->run(array( 'active_no_signal_drain' => true, 'apply' => true, 'limit' => 10, 'passes' => 1, 'until_budget' => '1s' ));
abandoned_cleanup_assert(! is_wp_error($stage_budget_result), 'active/no-signal stage budget result succeeds');
abandoned_cleanup_assert('budget_exhausted_before_stage' === $stage_budget_result['continuation']['reason'], 'stage budget continuation explains boundary');
abandoned_cleanup_assert('equivalent-clean' === $stage_budget_result['continuation']['stage'], 'stage budget continuation resumes at next safe stage');
abandoned_cleanup_assert(0 === count($stage_budget_abilities['datamachine-code/workspace-worktree-prune']->calls), 'stage budget continuation skips prune after budget exhaustion');
abandoned_cleanup_assert(str_contains((string) $stage_budget_result['continuation']['next_command'], '--stage=equivalent-clean --offset=0'), 'stage budget continuation command resumes exact safe boundary');

$bounded_budget_abilities = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => new AbandonedCleanupFakeAbility('finalized', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => new AbandonedCleanupFakeAbility('equivalent_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-report' => new AbandonedCleanupFakeAbility('active_no_signal_report', array( 'total_active_no_signal' => 0, 'inspected' => 0, 'by_suggested_action' => array() ), array( 'complete' => true, 'total' => 0 )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 1, 'removed' => 1 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$clock_index              = 0;
$orchestrator             = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $bounded_budget_abilities[ $name ] ?? null,
	static function () use ( &$clock_index ): float {
		$value = $clock_index < 14 ? 1000.0 : 1002.0;
		++$clock_index;
		return $value;
	}
);
$bounded_budget_result    = $orchestrator->run(array( 'active_no_signal_drain' => true, 'apply' => true, 'limit' => 10, 'passes' => 2, 'until_budget' => '1s' ));
abandoned_cleanup_assert(! is_wp_error($bounded_budget_result), 'active/no-signal bounded budget result succeeds');
abandoned_cleanup_assert('budget_exhausted_after_bounded_apply' === $bounded_budget_result['continuation']['reason'], 'bounded budget continuation explains boundary');
abandoned_cleanup_assert('finalized' === $bounded_budget_result['continuation']['stage'], 'bounded budget continuation resumes as full safe drain');
abandoned_cleanup_assert(0 === count($bounded_budget_abilities['datamachine-code/workspace-worktree-prune']->calls), 'bounded budget continuation skips prune after budget exhaustion');
abandoned_cleanup_assert(str_contains((string) $bounded_budget_result['continuation']['hint'], 'Re-run next_command'), 'bounded budget continuation has operator hint');

$zero_yield_finalized = new AbandonedCleanupQueuedAbility(
	array(
		array(
			'mode'       => 'finalized',
			'summary'    => array( 'inspected' => 10, 'written' => 0 ),
			'pagination' => array( 'offset' => 0, 'limit' => 10, 'scanned' => 10, 'partial' => true, 'complete' => false, 'next_offset' => 10, 'total' => 30 ),
		),
	)
);
$zero_yield_equivalent = new AbandonedCleanupFakeAbility('equivalent_clean', array( 'inspected' => 10, 'written' => 1 ), array( 'complete' => true ));
$zero_yield_abilities  = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => $zero_yield_finalized,
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => $zero_yield_equivalent,
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-report' => new AbandonedCleanupFakeAbility('active_no_signal_report', array( 'total_active_no_signal' => 0, 'inspected' => 0, 'by_suggested_action' => array() ), array( 'complete' => true, 'total' => 0 )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 0, 'removed' => 0 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$orchestrator          = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $zero_yield_abilities[ $name ] ?? null,
	static fn(): float => 1000.0
);
$zero_yield_result     = $orchestrator->run(array( 'active_no_signal_drain' => true, 'apply' => true, 'limit' => 10, 'passes' => 3, 'until_budget' => '300s' ));
abandoned_cleanup_assert(! is_wp_error($zero_yield_result), 'active/no-signal zero-yield result succeeds');
abandoned_cleanup_assert('no_progress_in_stage' === $zero_yield_result['continuation']['reason'], 'zero-yield continuation uses no-progress reason');
abandoned_cleanup_assert(false === (bool) ( $zero_yield_result['evidence']['budget_exhausted'] ?? true ), 'zero-yield stop is distinct from budget exhaustion');
abandoned_cleanup_assert('no_progress_in_stage' === ( $zero_yield_result['summary']['stop_reason'] ?? null ), 'zero-yield summary exposes stop reason');
abandoned_cleanup_assert(10 === (int) ( $zero_yield_result['continuation']['progress_delta']['inspected'] ?? 0 ), 'zero-yield continuation exposes inspected count');
abandoned_cleanup_assert(0 === (int) ( $zero_yield_result['continuation']['progress_delta']['total_mutations'] ?? -1 ), 'zero-yield continuation exposes zero mutations');
abandoned_cleanup_assert(str_contains((string) $zero_yield_result['continuation']['recommendation'], 'Stop this drain'), 'zero-yield continuation recommends stopping');
abandoned_cleanup_assert(str_contains((string) $zero_yield_result['continuation']['next_command'], '--stage=finalized --offset=10'), 'zero-yield continuation resumes same stage next page');
abandoned_cleanup_assert(0 === count($zero_yield_equivalent->calls), 'zero-yield stop avoids advancing into later active/no-signal stages');
abandoned_cleanup_assert(0 === count($zero_yield_abilities['datamachine-code/workspace-worktree-prune']->calls), 'zero-yield stop skips prune while continuation remains');

$abandoned_zero_yield_finalized = new AbandonedCleanupQueuedAbility(
	array(
		array(
			'mode'       => 'finalized',
			'summary'    => array( 'inspected' => 10, 'written' => 0 ),
			'pagination' => array( 'offset' => 0, 'limit' => 10, 'scanned' => 10, 'partial' => true, 'complete' => false, 'next_offset' => 10, 'total' => 30 ),
		),
	)
);
$abandoned_zero_yield_equivalent = new AbandonedCleanupFakeAbility('equivalent_clean', array( 'inspected' => 10, 'written' => 1 ), array( 'complete' => true ));
$abandoned_zero_yield_abilities  = array(
	'datamachine-code/workspace-worktree-reconcile-metadata' => new AbandonedCleanupFakeAbility('reconcile_metadata', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => $abandoned_zero_yield_finalized,
	'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => $abandoned_zero_yield_equivalent,
	'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => new AbandonedCleanupFakeAbility('merged', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => new AbandonedCleanupFakeAbility('remote_clean', array(), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => new AbandonedCleanupFakeAbility('bounded', array( 'processed' => 0, 'removed' => 0 ), array( 'complete' => true )),
	'datamachine-code/workspace-worktree-prune' => new AbandonedCleanupFakeAbility('prune'),
);
$orchestrator                   = new DataMachineCode\Workspace\WorkspaceAbandonedCleanupOrchestrator(
	static fn( string $name ) => $abandoned_zero_yield_abilities[ $name ] ?? null,
	static fn(): float => 1000.0
);
$abandoned_zero_yield_result    = $orchestrator->run(array( 'apply' => true, 'limit' => 10, 'passes' => 3, 'until_budget' => '300s', 'scope' => 'repo:stage-finalized' ));
abandoned_cleanup_assert(! is_wp_error($abandoned_zero_yield_result), 'abandoned zero-yield result succeeds');
abandoned_cleanup_assert('abandoned_worktree_cleanup' === $abandoned_zero_yield_result['mode'], 'abandoned zero-yield keeps abandoned mode');
abandoned_cleanup_assert('repo:stage-finalized' === $abandoned_zero_yield_result['scope'], 'abandoned zero-yield preserves operator scope');
abandoned_cleanup_assert('no_progress_in_stage' === $abandoned_zero_yield_result['continuation']['reason'], 'abandoned zero-yield continuation uses no-progress reason');
abandoned_cleanup_assert('no_progress_in_stage' === ( $abandoned_zero_yield_result['summary']['stop_reason'] ?? null ), 'abandoned zero-yield summary exposes stop reason');
abandoned_cleanup_assert(str_contains((string) $abandoned_zero_yield_result['continuation']['recommendation'], 'Stop this drain'), 'abandoned zero-yield continuation recommends stopping');
abandoned_cleanup_assert(str_contains((string) $abandoned_zero_yield_result['continuation']['priority_hint'], 'Prioritize already cleanup-eligible rows'), 'abandoned zero-yield continuation includes priority hint');
abandoned_cleanup_assert(in_array('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=10 --scope=repo:stage-finalized --format=json', (array) $abandoned_zero_yield_result['continuation']['alternative_commands'], true), 'abandoned zero-yield suggests scoped bounded cleanup review');
abandoned_cleanup_assert(str_contains((string) $abandoned_zero_yield_result['continuation']['next_command'], ' worktree abandoned --apply --stage=finalized --offset=10 --limit=10 --passes=3 --until-budget=300s --scope=repo:stage-finalized --format=json'), 'abandoned zero-yield continuation preserves stage, budget, scope, and next page');
abandoned_cleanup_assert('repo:stage-finalized' === (string) ( $abandoned_zero_yield_finalized->calls[0]['scope'] ?? '' ), 'abandoned zero-yield forwards scope to child stage ability');
abandoned_cleanup_assert(! array_key_exists('repo', $abandoned_zero_yield_finalized->calls[0]), 'abandoned zero-yield does not treat free-form operator scope as a repo filter');
abandoned_cleanup_assert(0 === count($abandoned_zero_yield_equivalent->calls), 'abandoned zero-yield stop avoids advancing into later active/no-signal stages');
abandoned_cleanup_assert(0 === count($abandoned_zero_yield_abilities['datamachine-code/workspace-worktree-prune']->calls), 'abandoned zero-yield stop skips prune while continuation remains');

fwrite(STDOUT, 'abandoned cleanup orchestrator smoke passed' . PHP_EOL);
