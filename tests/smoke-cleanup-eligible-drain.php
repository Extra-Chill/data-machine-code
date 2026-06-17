<?php
/**
 * Standalone smoke coverage for WorkspaceCleanupEligibleDrainOrchestrator.
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

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupEligibleDrainOrchestrator.php';

final class CleanupEligibleDrainFakeAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/** @param array<int,array<string,mixed>> $responses */
	public function __construct( private array $responses ) {}

	/** @return array<string,mixed> */
	public function execute( array $input ): array {
		$this->calls[] = $input;
		$response      = array_shift($this->responses) ?: array( 'processed' => 0, 'removed' => 0, 'remaining_total' => 0 );
		$removed_count = (int) ( $response['removed'] ?? 0 );
		$candidates    = array();
		$removed       = array();
		for ( $i = 0; $i < (int) ( $response['processed'] ?? $removed_count ); ++$i ) {
			$candidates[] = array( 'handle' => 'repo@candidate-' . count($this->calls) . '-' . $i );
		}
		for ( $i = 0; $i < $removed_count; ++$i ) {
			$removed[] = array(
				'handle'     => 'repo@removed-' . count($this->calls) . '-' . $i,
				'size_bytes' => 100,
			);
		}

		return array(
			'success'        => true,
			'mode'           => 'bounded_cleanup_eligible_apply',
			'dry_run'        => ! empty($input['dry_run']),
			'destructive'    => empty($input['dry_run']),
			'workspace_path' => '/tmp/workspace',
			'candidates'     => $candidates,
			'removed'        => $removed,
			'skipped'        => array(),
			'summary'        => array(
				'processed'       => (int) ( $response['processed'] ?? count($candidates) ),
				'removed'         => $removed_count,
				'skipped'         => 0,
				'bytes_reclaimed' => $removed_count * 100,
			),
			'continuation'   => array( 'remaining_total' => (int) ( $response['remaining_total'] ?? 0 ) ),
			'evidence'       => array( 'elapsed_ms' => 1 ),
		);
	}
}

function cleanup_eligible_drain_assert( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite(STDERR, 'failed: ' . $label . PHP_EOL);
		exit(1);
	}
}

$disk_reporter = static fn(): array => array(
	'path'       => '/tmp/workspace',
	'free_bytes' => 123,
	'free_human' => '123.0 B',
);

$preview_ability = new CleanupEligibleDrainFakeAbility(array( array( 'processed' => 2, 'removed' => 0, 'remaining_total' => 3 ) ));
$orchestrator    = new DataMachineCode\Workspace\WorkspaceCleanupEligibleDrainOrchestrator(
	static fn( string $name ) => 'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' === $name ? $preview_ability : null,
	static fn(): float => 1000.0,
	$disk_reporter
);
$preview         = $orchestrator->run(array( 'passes' => 5 ));
cleanup_eligible_drain_assert(! is_wp_error($preview), 'preview succeeds');
cleanup_eligible_drain_assert(false === $preview['applied'], 'preview is non-destructive');
cleanup_eligible_drain_assert(1 === count($preview_ability->calls), 'preview runs one pass');
cleanup_eligible_drain_assert('preview' === $preview['summary']['stop_reason'], 'preview stop reason');
cleanup_eligible_drain_assert(2 === $preview['summary']['would_remove'], 'preview counts would-remove candidates');

$empty_ability = new CleanupEligibleDrainFakeAbility(
	array(
		array( 'processed' => 2, 'removed' => 2, 'remaining_total' => 1 ),
		array( 'processed' => 1, 'removed' => 1, 'remaining_total' => 0 ),
	)
);
$orchestrator  = new DataMachineCode\Workspace\WorkspaceCleanupEligibleDrainOrchestrator(
	static fn() => $empty_ability,
	static fn(): float => 1000.0,
	$disk_reporter
);
$empty         = $orchestrator->run(array( 'apply' => true, 'limit' => 25, 'passes' => 5 ));
cleanup_eligible_drain_assert(! is_wp_error($empty), 'empty drain succeeds');
cleanup_eligible_drain_assert(2 === count($empty_ability->calls), 'drain stops after empty pass');
cleanup_eligible_drain_assert('empty' === $empty['summary']['stop_reason'], 'empty stop reason');
cleanup_eligible_drain_assert(3 === $empty['summary']['removed'], 'removed count accumulates');
cleanup_eligible_drain_assert(123 === $empty['summary']['final_free_space']['free_bytes'], 'final free space included');

$limit_ability = new CleanupEligibleDrainFakeAbility(
	array(
		array( 'processed' => 1, 'removed' => 1, 'remaining_total' => 10 ),
		array( 'processed' => 1, 'removed' => 1, 'remaining_total' => 9 ),
	)
);
$orchestrator  = new DataMachineCode\Workspace\WorkspaceCleanupEligibleDrainOrchestrator(
	static fn() => $limit_ability,
	static fn(): float => 1000.0,
	$disk_reporter
);
$limited       = $orchestrator->run(array( 'apply' => true, 'passes' => 2 ));
cleanup_eligible_drain_assert(! is_wp_error($limited), 'pass-limit drain succeeds');
cleanup_eligible_drain_assert('pass_limit' === $limited['summary']['stop_reason'], 'pass-limit stop reason');
cleanup_eligible_drain_assert(2 === count($limit_ability->calls), 'pass-limit call count');

$budget_ability = new CleanupEligibleDrainFakeAbility(
	array(
		array( 'processed' => 1, 'removed' => 1, 'remaining_total' => 10 ),
		array( 'processed' => 1, 'removed' => 1, 'remaining_total' => 9 ),
	)
);
$ticks        = array( 1000.0, 1000.0, 1002.0, 1002.0 );
$orchestrator = new DataMachineCode\Workspace\WorkspaceCleanupEligibleDrainOrchestrator(
	static fn() => $budget_ability,
	static function () use ( &$ticks ): float {
		return array_shift($ticks) ?? 1002.0;
	},
	$disk_reporter
);
$budget      = $orchestrator->run(array( 'apply' => true, 'passes' => 5, 'until_budget' => '1s' ));
cleanup_eligible_drain_assert(! is_wp_error($budget), 'budget drain succeeds');
cleanup_eligible_drain_assert('budget_exhausted' === $budget['summary']['stop_reason'], 'budget stop reason');
cleanup_eligible_drain_assert(1 === count($budget_ability->calls), 'budget stops before second pass');

$discard = $orchestrator->run(array( 'apply' => true, 'discard_unpushed' => true ));
cleanup_eligible_drain_assert(is_wp_error($discard), 'discard unpushed refused');
cleanup_eligible_drain_assert('cleanup_eligible_drain_refuses_unpushed_discard' === $discard->code, 'discard refusal code');

fwrite(STDOUT, 'cleanup eligible drain smoke passed' . PHP_EOL);
