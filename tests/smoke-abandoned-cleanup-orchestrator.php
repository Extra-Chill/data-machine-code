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

fwrite(STDOUT, 'abandoned cleanup orchestrator smoke passed' . PHP_EOL);
