<?php
/**
 * Standalone coverage for WorkspaceSafeCleanupOrchestrator.
 */

define( 'ABSPATH', dirname( __DIR__ ) );
define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_Error' ) ) {
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

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

$safe_cleanup_registered_abilities = array();

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $slug, array $args ): void {
		$GLOBALS['safe_cleanup_registered_abilities'][ $slug ] = $args;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback ): void {
		if ( 'wp_abilities_api_init' === $hook ) {
			$callback();
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/Storage/CleanupRunRepositoryInterface.php';
require_once dirname( __DIR__ ) . '/inc/Support/JsonCodec.php';
require_once dirname( __DIR__ ) . '/inc/Storage/WorktreeInventoryRepository.php';
require_once dirname( __DIR__ ) . '/inc/Workspace/WorkspaceSafeCleanupOrchestrator.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/WorkspaceAbilities.php';

final class SafeCleanupInventoryWpdb {
	public string $prefix = 'wp_';

	/** @var array<string,array<string,mixed>> */
	public array $rows = array();

	public function get_results( string $sql, string $output = ARRAY_A ): array {
		$rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => ! str_contains( $sql, 'missing_path = 1' ) || ! empty( $row['missing_path'] ) ) );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $a['handle'], (string) $b['handle'] ) );
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $matches ) ) {
			return array_slice( $rows, (int) $matches[2], (int) $matches[1] );
		}
		return $rows;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function query( string $sql ): int|false {
		if ( ! preg_match( "/handle = '([^']*)' AND path = '([^']*)'/", $sql, $matches ) ) {
			return false;
		}
		$handle = stripslashes( $matches[1] );
		$path   = stripslashes( $matches[2] );
		if ( ! isset( $this->rows[ $handle ] ) || $path !== (string) $this->rows[ $handle ]['path'] ) {
			return 0;
		}
		unset( $this->rows[ $handle ] );
		return 1;
	}
}

final class SafeCleanupQueuedAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/** @param array<int,array<string,mixed>> $responses */
	public function __construct( private array $responses ) {}

	/** @return array<string,mixed> */
	public function execute( array $input ): array {
		$this->calls[] = $input;
		return array_shift( $this->responses ) ?: array(
			'success' => true,
			'mode'    => 'empty',
			'summary' => array(),
		);
	}
}

final class SafeCleanupSchemaValidatedAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/** @param array<string,mixed> $response */
	public function __construct( private array $response ) {}

	public function execute( array $input ): array|\WP_Error {
		foreach ( array(
			'limit'        => 'integer',
			'after_handle' => 'string',
			'until_budget' => 'string',
		) as $key => $type ) {
			if ( array_key_exists( $key, $input ) && gettype( $input[ $key ] ) !== $type ) {
				return new WP_Error( 'ability_invalid_input', sprintf( '%s must be a %s.', $key, $type ) );
			}
		}
		$this->calls[] = $input;
		return $this->response;
	}
}

final class SafeCleanupRealInventoryAbility {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public function execute( array $input ): array {
		$this->calls[]           = $input;
		$input['workspace_root'] = sys_get_temp_dir();
		return ( new DataMachineCode\Storage\WorktreeInventoryRepository() )->pruneMissing( $input );
	}
}

final class SafeCleanupFakeRunRepository implements \DataMachineCode\Storage\CleanupRunRepositoryInterface {
	/** @var array<string,array<string,mixed>> */
	public array $runs = array();

	/** @var array<int,array<string,mixed>> */
	public array $updates = array();

	public function create_run( array $run ): string {
		$run_id                = 'cleanup-run-safe-test';
		$this->runs[ $run_id ] = $run + array( 'run_id' => $run_id );
		return $run_id;
	}

	public function update_run( string $run_id, array $fields ): bool {
		$this->updates[]       = array(
			'run_id' => $run_id,
			'fields' => $fields,
		);
		$this->runs[ $run_id ] = array_merge( $this->runs[ $run_id ] ?? array( 'run_id' => $run_id ), $fields );
		return true;
	}

	public function get_run( string $run_id ): ?array {
		return $this->runs[ $run_id ] ?? null;
	}
}

final class SafeCleanupDurableCheckpointRepository implements \DataMachineCode\Storage\CleanupRunRepositoryInterface {
	/** @var array<string,array<string,mixed>> */
	public array $runs = array();
	/** @var array<int,array<string,mixed>> */
	public array $items = array();

	public function create_run( array $run ): string {
		$run_id = 'cleanup-run-interrupted-output';
		$this->runs[ $run_id ] = $run + array( 'run_id' => $run_id );
		return $run_id;
	}

	public function update_run( string $run_id, array $fields ): bool {
		$this->runs[ $run_id ] = array_merge($this->runs[ $run_id ] ?? array( 'run_id' => $run_id ), $fields);
		return true;
	}

	public function get_run( string $run_id ): ?array {
		return $this->runs[ $run_id ] ?? null;
	}

	public function add_items( string $run_id, array $items ): int {
		foreach ( $items as $item ) {
			$this->items[] = array( 'run_id' => $run_id ) + $item;
		}
		return count($items);
	}
}

final class SafeCleanupCommittedCandidateAbility {
	private bool $committed = false;

	public function execute( array $input ): array {
		if ( $this->committed ) {
			return array( 'success' => true, 'summary' => array( 'processed' => 0, 'removed' => 0 ) );
		}
		$this->committed = true;
		$callback = $input['progress_callback'] ?? null;
		$candidate = array( 'handle' => 'repo@interrupted', 'repo' => 'repo', 'branch' => 'interrupted', 'path' => '/workspace/repo@interrupted' );
		if ( is_callable($callback) ) {
			$callback(array( 'phase' => 'mutation_prepared', 'candidate' => $candidate, 'recovery' => array( 'recovery_ref' => 'refs/dmc/recovery/abc' ) ));
			$callback(array( 'phase' => 'mutation_committed', 'candidate' => $candidate, 'outcome' => array( 'path_exists_after' => false ) ));
			$callback(array( 'phase' => 'candidate_terminal', 'action' => 'removed', 'candidate' => $candidate, 'outcome' => array( 'reason_code' => 'cleanup_eligible' ) ));
		}
		return array(
			'success'      => true,
			'workspace_path' => '/workspace',
			'summary'      => array( 'processed' => 1, 'removed' => 1 ),
			'removed'      => array( $candidate + array( 'reason_code' => 'cleanup_eligible' ) ),
			'continuation' => array( 'remaining_total' => 0 ),
		);
	}
}

final class SafeCleanupCancellingAbility {
	public function __construct( private SafeCleanupFakeRunRepository $repository ) {}
	public function execute( array $input ): array {
		$this->repository->runs['cleanup-run-safe-test']['status'] = 'cancelled';
		return array(
			'success' => true,
			'summary' => array(),
		);
	}
}

function safe_cleanup_assert( bool $condition, string $label ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'failed: ' . $label . PHP_EOL );
		exit( 1 );
	}
}

new DataMachineCode\Abilities\WorkspaceAbilities();
$safe_cleanup_ability      = $GLOBALS['safe_cleanup_registered_abilities']['datamachine-code/workspace-cleanup-safe'] ?? null;
$inventory_prune_ability   = $GLOBALS['safe_cleanup_registered_abilities']['datamachine-code/workspace-worktree-inventory-prune-missing'] ?? null;
$abandoned_cleanup_ability = $GLOBALS['safe_cleanup_registered_abilities']['datamachine-code/workspace-worktree-abandoned-cleanup'] ?? null;
$bounded_cleanup_ability   = $GLOBALS['safe_cleanup_registered_abilities']['datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply'] ?? null;
safe_cleanup_assert( is_array( $safe_cleanup_ability ), 'safe cleanup ability is registered' );
safe_cleanup_assert( array( DataMachineCode\Abilities\WorkspaceAbilities::class, 'workspaceCleanupSafe' ) === $safe_cleanup_ability['execute_callback'], 'safe cleanup ability uses canonical callback' );
safe_cleanup_assert( isset( $safe_cleanup_ability['input_schema']['properties']['dry_run'] ), 'safe cleanup ability accepts dry_run' );
safe_cleanup_assert( isset( $safe_cleanup_ability['input_schema']['properties']['force'] ), 'safe cleanup ability documents force refusal' );
safe_cleanup_assert( isset( $safe_cleanup_ability['input_schema']['properties']['discard_unpushed'] ), 'safe cleanup ability documents discard refusal' );
safe_cleanup_assert( isset( $safe_cleanup_ability['output_schema']['properties']['summary'] ), 'safe cleanup ability documents summary output' );
safe_cleanup_assert( isset( $safe_cleanup_ability['output_schema']['properties']['blockers'] ), 'safe cleanup ability documents blockers output' );
safe_cleanup_assert( isset( $safe_cleanup_ability['output_schema']['properties']['current_blockers'] ), 'safe cleanup ability documents final current blockers output' );
safe_cleanup_assert( isset( $safe_cleanup_ability['output_schema']['properties']['run_id'] ), 'safe cleanup ability documents run_id output' );
safe_cleanup_assert( isset( $safe_cleanup_ability['output_schema']['properties']['continuation'] ), 'safe cleanup ability documents continuation output' );
safe_cleanup_assert( isset( $abandoned_cleanup_ability['input_schema']['properties']['discard_unpushed'] ), 'abandoned cleanup ability documents unpushed discard refusal' );
safe_cleanup_assert( isset( $bounded_cleanup_ability['input_schema']['properties']['scope'] ), 'bounded cleanup ability accepts scoped continuation input' );
safe_cleanup_assert( is_array( $inventory_prune_ability ), 'inventory prune ability is registered' );
safe_cleanup_assert( isset( $inventory_prune_ability['input_schema']['properties']['after_handle'] ), 'registered inventory ability accepts the keyset cursor' );
safe_cleanup_assert( array( DataMachineCode\Abilities\WorkspaceAbilities::class, 'worktreeInventoryPruneMissing' ) === $inventory_prune_ability['execute_callback'], 'registered inventory ability uses the canonical lifecycle callback' );

$ability_force_result = DataMachineCode\Abilities\WorkspaceAbilities::workspaceCleanupSafe( array( 'force' => true ) );
safe_cleanup_assert( is_wp_error( $ability_force_result ), 'safe cleanup ability callback executes orchestrator refusal' );
safe_cleanup_assert( 'safe_cleanup_refuses_force' === $ability_force_result->code, 'safe cleanup ability force refusal code' );

$empty_ability = new SafeCleanupQueuedAbility( array() );
$orchestrator  = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $empty_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(
			'active' => 0,
			'stale'  => 0,
		),
		'filesystem' => array( 'removed_count' => 0 ),
	)
);
$force_result  = $orchestrator->run( array( 'force' => true ) );
safe_cleanup_assert( is_wp_error( $force_result ), 'force is refused' );
safe_cleanup_assert( 'safe_cleanup_refuses_force' === $force_result->code, 'force refusal code' );

$discard_result = $orchestrator->run( array( 'discard_unpushed' => true ) );
safe_cleanup_assert( is_wp_error( $discard_result ), 'discard_unpushed is refused' );
safe_cleanup_assert( 'safe_cleanup_refuses_unpushed_discard' === $discard_result->code, 'discard refusal code' );

$cleanup_eligible  = new SafeCleanupQueuedAbility(
	array(
		array(
			'success'      => true,
			'mode'         => 'cleanup_eligible_drain',
			'summary'      => array(
				'removed'         => 1,
				'bytes_reclaimed' => 1024,
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
$active_no_signal  = new SafeCleanupQueuedAbility(
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
			'success'                            => true,
			'mode'                               => 'active_no_signal_drain',
			'summary'                            => array(
				'marked_cleanup_eligible' => 0,
				'removed'                 => 0,
			),
			'continuation'                       => array(
				'reason'       => 'page_incomplete',
				'next_command' => 'studio wp datamachine-code workspace worktree active-no-signal-drain --apply --stage=finalized --offset=7 --limit=7',
			),
			'remaining_active_no_signal_backlog' => array(
				'by_actionable_reason' => array(
					'insufficient_signal' => array( 'count' => 3 ),
				),
			),
		),
	)
);
$artifact_cleanup  = new SafeCleanupQueuedAbility(
	array(
		array(
			'success'                   => true,
			'state'                     => 'completed',
			'applied'                   => 2,
			'skipped'                   => 1,
			'bytes_reclaimed'           => 2048,
			'remaining_blocked_reasons' => array( 'artifact_plan_mismatch' => array( 'count' => 1 ) ),
			'passes'                    => array( array( 'planned_rows' => 3 ) ),
		),
	)
);
$inventory_wpdb    = new SafeCleanupInventoryWpdb();
$inventory_absent  = sys_get_temp_dir() . '/dmc-safe-cleanup-absent-' . getmypid();
$inventory_present = sys_get_temp_dir() . '/dmc-safe-cleanup-present-' . getmypid();
@rmdir( $inventory_absent );
@rmdir( $inventory_present );
mkdir( $inventory_present, 0777, true );
$inventory_wpdb->rows = array(
	'confirmed-absent'  => array(
		'handle'            => 'confirmed-absent',
		'repo'              => 'repo',
		'path'              => $inventory_absent,
		'missing_path'      => 1,
		'last_probe_status' => 'missing_path',
		'metadata'          => null,
	),
	'recreated-primary' => array(
		'handle'            => 'recreated-primary',
		'repo'              => 'repo',
		'path'              => $inventory_present,
		'missing_path'      => 1,
		'last_probe_status' => 'missing_path',
		'metadata'          => null,
	),
	'protected-pr'      => array(
		'handle'            => 'protected-pr',
		'repo'              => 'repo',
		'path'              => $inventory_absent,
		'missing_path'      => 1,
		'last_probe_status' => 'missing_path',
		'pr_url'            => 'https://example.test/pr/1',
		'metadata'          => null,
	),
);
$GLOBALS['wpdb']      = $inventory_wpdb;
$inventory_prune      = new SafeCleanupRealInventoryAbility();
$lock_calls           = array();
$run_repository       = new SafeCleanupFakeRunRepository();
$progress_envelopes   = array();
$orchestrator         = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => match ( $name ) {
		'datamachine-code/workspace-worktree-cleanup-eligible-drain' => $cleanup_eligible,
		'datamachine-code/workspace-worktree-active-no-signal-drain' => $active_no_signal,
		'datamachine-code/workspace-cleanup-until-empty' => $artifact_cleanup,
		'datamachine-code/workspace-worktree-inventory-prune-missing' => $inventory_prune,
		default => null,
	},
	static function ( bool $dry_run ) use ( &$lock_calls ): array {
		$lock_calls[] = $dry_run;
		return array(
			'dry_run'    => $dry_run,
			'after'      => array(
				'active' => 0,
				'stale'  => 0,
			),
			'filesystem' => array(
				'removed_count' => $dry_run ? 0 : 1,
				'skipped_count' => 0,
			),
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
safe_cleanup_assert( ! is_wp_error( $result ), 'safe cleanup succeeds' );
safe_cleanup_assert( true === $result['applied'], 'safe cleanup applies by default' );
safe_cleanup_assert( 'cleanup-run-safe-test' === ( $result['run_id'] ?? null ), 'safe cleanup returns durable run id' );
safe_cleanup_assert( 'cleanup-run-safe-test' === ( $progress_envelopes[0]['run_id'] ?? null ), 'safe cleanup emits early run id before long child work' );
safe_cleanup_assert( 'applying' === ( $progress_envelopes[0]['state'] ?? null ), 'early progress reports applying state' );
safe_cleanup_assert( str_contains( (string) ( $result['continuation']['status_command'] ?? '' ), 'workspace cleanup status cleanup-run-safe-test' ), 'continuation exposes status command' );
safe_cleanup_assert( str_contains( (string) ( $result['continuation']['resume_command'] ?? '' ), 'workspace cleanup safe --limit=7 --passes=4 --cycles=3' ), 'continuation exposes safe resume command' );
safe_cleanup_assert( 2 === count( $lock_calls ), 'stale locks pruned before and after cleanup' );
safe_cleanup_assert( false === $lock_calls[0] && false === $lock_calls[1], 'lock pruning is destructive only in apply mode' );
safe_cleanup_assert( 2 === count( $cleanup_eligible->calls ), 'cleanup eligible drain repeats until no progress' );
safe_cleanup_assert( false === $cleanup_eligible->calls[0]['force'], 'child force is false' );
safe_cleanup_assert( false === $cleanup_eligible->calls[0]['discard_unpushed'], 'child discard_unpushed is false' );
safe_cleanup_assert( 4 === ( $result['summary']['removed'] ?? null ), 'artifact and worktree removals are accumulated' );
safe_cleanup_assert( 3 === ( $result['summary']['planned'] ?? null ), 'artifact reviewed rows are counted' );
safe_cleanup_assert( 2 === ( $result['summary']['applied_rows'] ?? null ), 'artifact applied rows are counted' );
safe_cleanup_assert( 1 === ( $result['summary']['skipped_rows'] ?? null ), 'artifact skipped rows are counted' );
safe_cleanup_assert( 3072 === ( $result['summary']['bytes_reclaimed'] ?? null ), 'measured artifact and worktree bytes are accumulated' );
safe_cleanup_assert( 1 === ( $result['summary']['marked_cleanup_eligible'] ?? null ), 'marked cleanup eligible rows are accumulated' );
safe_cleanup_assert( 2 === ( $result['summary']['lock_files_removed'] ?? null ), 'lock removals are accumulated' );
safe_cleanup_assert( 1 === ( $result['summary']['inventory_rows_pruned'] ?? null ), 'confirmed missing inventory rows are reported separately from removed worktrees' );
safe_cleanup_assert( 0 === ( $result['summary']['inventory_rows_planned'] ?? null ), 'apply does not report inventory rows as planned' );
safe_cleanup_assert( 2 === ( $result['summary']['inventory_rows_skipped'] ?? null ), 'recreated and protected inventory rows are reported separately' );
safe_cleanup_assert( false === $inventory_prune->calls[0]['dry_run'], 'apply invokes inventory pruning in apply mode' );
safe_cleanup_assert( false === $inventory_prune->calls[0]['force'], 'safe cleanup preserves inventory prune force protections' );
safe_cleanup_assert( 7 === $inventory_prune->calls[0]['limit'], 'safe cleanup bounds inventory pruning to its cleanup limit' );
safe_cleanup_assert( array( array( 'handle' => 'confirmed-absent' ) ) === ( $result['steps']['inventory_prune_missing']['pruned_examples'] ?? null ), 'inventory evidence is bounded to compact examples' );
safe_cleanup_assert( ! isset( $inventory_wpdb->rows['confirmed-absent'] ), 'safe cleanup uses the real primitive to delete confirmed-absent rows' );
safe_cleanup_assert( isset( $inventory_wpdb->rows['recreated-primary'] ), 'safe cleanup real primitive preserves recreated paths' );
safe_cleanup_assert( isset( $inventory_wpdb->rows['protected-pr'] ), 'safe cleanup real primitive preserves PR-protected rows' );
safe_cleanup_assert( 9 === ( $result['summary']['blocker_count'] ?? null ), 'compact blockers are counted' );
safe_cleanup_assert( 1 === ( $result['summary']['blockers_by_reason']['artifact_plan_mismatch'] ?? null ), 'artifact blocker count is preserved' );
safe_cleanup_assert( 1 === ( $result['summary']['blockers_by_reason']['dirty_worktree'] ?? null ), 'dirty blocker count is preserved' );
safe_cleanup_assert( 2 === ( $result['summary']['blockers_by_reason']['unpushed_commits'] ?? null ), 'unpushed blocker count is preserved' );
safe_cleanup_assert( 3 === ( $result['summary']['blockers_by_reason']['insufficient_signal'] ?? null ), 'active backlog blocker count is preserved' );
safe_cleanup_assert( 1 === ( $result['summary']['blockers_by_reason']['path_present_on_disk'] ?? null ), 'recreated paths remain inventory prune skips' );
safe_cleanup_assert( 1 === ( $result['summary']['blockers_by_reason']['pr_url'] ?? null ), 'protected inventory rows remain inventory prune skips' );
safe_cleanup_assert( 'sum_of_per_reason_maximum_observations_across_stages' === ( $result['summary']['blocker_count_scope'] ?? null ), 'aggregate blocker counts document their historical aggregation scope' );
safe_cleanup_assert( 4 === ( $result['summary']['current_blocker_count'] ?? null ), 'current blocker count reports the final-cycle artifact and active backlog observations' );
safe_cleanup_assert( 3 === ( $result['summary']['current_blockers_by_reason']['insufficient_signal'] ?? null ), 'current blocker buckets expose final-cycle active backlog observations' );
safe_cleanup_assert( isset( $result['blockers_by_stage']['cleanup_eligible_1']['dirty_worktree'] ), 'per-stage blocker counts remain available' );
safe_cleanup_assert( str_contains( (string) ( $result['continuation']['next_command'] ?? '' ), 'active-no-signal-drain' ), 'child page continuation is surfaced' );
safe_cleanup_assert( count( $run_repository->updates ) >= 5, 'safe cleanup checkpoints progress repeatedly' );
safe_cleanup_assert( 'complete_with_blockers' === ( $run_repository->runs['cleanup-run-safe-test']['status'] ?? null ), 'safe cleanup persists final run state' );
safe_cleanup_assert( 4 === ( $run_repository->runs['cleanup-run-safe-test']['summary']['safe_cleanup_progress']['summary']['removed'] ?? null ), 'safe cleanup persists reclaimed progress summary' );
rmdir( $inventory_present );

$bounded_inventory        = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => 'datamachine-code/workspace-worktree-inventory-prune-missing' === $name ? $inventory_prune : new SafeCleanupQueuedAbility(
		array(
			array(
				'success' => true,
				'summary' => array(),
			),
		)
	),
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$bounded_inventory_result = $bounded_inventory->run(
	array(
		'dry_run' => true,
		'limit'   => 1,
	)
);
safe_cleanup_assert( ! is_wp_error( $bounded_inventory_result ), 'bounded real inventory prune succeeds through safe cleanup' );
safe_cleanup_assert( 'protected-pr' === ( $bounded_inventory_result['continuation']['inventory_after'] ?? null ), 'safe cleanup returns a bounded inventory keyset cursor' );
safe_cleanup_assert( str_contains( (string) ( $bounded_inventory_result['continuation']['next_command'] ?? '' ), '--inventory-after=' ), 'safe cleanup continuation resumes the next inventory keyset page' );

$schema_validated_inventory = new SafeCleanupSchemaValidatedAbility(
	array(
		'success' => true,
		'summary' => array(),
	)
);
$schema_validated_cleanup   = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => 'datamachine-code/workspace-worktree-inventory-prune-missing' === $name ? $schema_validated_inventory : new SafeCleanupQueuedAbility(
		array(
			array(
				'success' => true,
				'summary' => array(),
			),
		)
	),
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$schema_validated_result    = $schema_validated_cleanup->run( array( 'dry_run' => true ) );
safe_cleanup_assert( ! is_wp_error( $schema_validated_result ), 'safe cleanup omits unset optional inputs accepted by the inventory Ability schema' );
safe_cleanup_assert( preg_match('/^\d+s$/', (string) ( $schema_validated_inventory->calls[0]['until_budget'] ?? '' )) === 1, 'safe cleanup passes the inventory Ability a concrete remaining shared budget' );

$cursor_inventory = new SafeCleanupQueuedAbility(
	array(
		array(
			'success'      => true,
			'summary'      => array(),
			'continuation' => array(
				'reason'            => 'limit_reached',
				'next_after_handle' => 'next-cursor',
			),
		),
	)
);
$cursor_cleanup   = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => 'datamachine-code/workspace-worktree-inventory-prune-missing' === $name ? $cursor_inventory : new SafeCleanupQueuedAbility(
		array(
			array(
				'success' => true,
				'summary' => array(),
			),
		)
	),
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$cursor_result    = $cursor_cleanup->run(
	array(
		'dry_run'         => true,
		'inventory_after' => 'previous-cursor',
	)
);
$next_command     = (string) ( $cursor_result['continuation']['next_command'] ?? '' );
safe_cleanup_assert( 1 === substr_count( $next_command, '--inventory-after=' ), 'inventory continuation replaces rather than duplicates its cursor flag' );
safe_cleanup_assert( str_contains( $next_command, "--inventory-after='next-cursor'" ), 'inventory continuation uses the replacement cursor' );

$both_active         = new SafeCleanupQueuedAbility(
	array(
		array(
			'success'      => true,
			'summary'      => array(),
			'continuation' => array(
				'reason'       => 'page_incomplete',
				'next_command' => 'active-resume',
			),
		),
	)
);
$both_inventory      = new SafeCleanupQueuedAbility(
	array(
		array(
			'success'      => true,
			'summary'      => array(),
			'continuation' => array(
				'reason'            => 'limit_reached',
				'next_after_handle' => 'inventory-handle',
			),
		),
	)
);
$both_pending        = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => match ( $name ) {
		'datamachine-code/workspace-worktree-active-no-signal-drain' => $both_active,
		'datamachine-code/workspace-worktree-inventory-prune-missing' => $both_inventory,
		default => new SafeCleanupQueuedAbility(
			array(
				array(
					'success' => true,
					'summary' => array(),
				),
			)
		),
	},
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$both_pending_result = $both_pending->run( array( 'dry_run' => true ) );
safe_cleanup_assert( 'active-resume' === ( $both_pending_result['continuation']['next_command'] ?? null ), 'active/no-signal remains the primary continuation when multiple stages are incomplete' );
safe_cleanup_assert( isset( $both_pending_result['continuation']['pending_stages']['active_no_signal'] ), 'active/no-signal continuation remains visible' );
safe_cleanup_assert( isset( $both_pending_result['continuation']['pending_stages']['inventory_prune_missing'] ), 'inventory continuation remains visible alongside active/no-signal' );

$preview_lock_calls = array();
$preview            = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => new SafeCleanupQueuedAbility(
		array(
			array(
				'success' => true,
				'summary' => array( 'would_remove' => 1 ),
			),
		)
	),
	static function ( bool $dry_run ) use ( &$preview_lock_calls ): array {
		$preview_lock_calls[] = $dry_run;
		return array(
			'dry_run'    => $dry_run,
			'after'      => array(
				'active' => 0,
				'stale'  => 1,
			),
			'filesystem' => array( 'removed_count' => 0 ),
		);
	},
	new SafeCleanupFakeRunRepository()
);
$preview_result     = $preview->run(
	array(
		'dry_run' => true,
		'cycles'  => 3,
	)
);
safe_cleanup_assert( ! is_wp_error( $preview_result ), 'preview succeeds' );
safe_cleanup_assert( false === $preview_result['applied'], 'preview does not apply' );
safe_cleanup_assert( array( true, true ) === $preview_lock_calls, 'preview lock pruning stays dry-run' );
safe_cleanup_assert( 1 === ( $preview_result['summary']['cycles'] ?? null ), 'preview runs one cycle' );

$preview_prune            = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'dry_run' => true,
			'deleted' => array( array( 'handle' => 'missing-preview' ) ),
			'skipped' => array(
				array(
					'handle' => 'protected-preview',
					'reason' => 'unpushed_count',
				),
			),
			'summary' => array(
				'deleted' => 1,
				'skipped' => 1,
				'total'   => 2,
			),
		),
	)
);
$dry_run_ability          = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
	)
);
$inventory_preview        = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn( string $name ) => 'datamachine-code/workspace-worktree-inventory-prune-missing' === $name ? $preview_prune : $dry_run_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$inventory_preview_result = $inventory_preview->run( array( 'dry_run' => true ) );
safe_cleanup_assert( ! is_wp_error( $inventory_preview_result ), 'inventory prune preview succeeds' );
safe_cleanup_assert( true === $preview_prune->calls[0]['dry_run'], 'preview invokes inventory pruning in dry-run mode' );
safe_cleanup_assert( false === $preview_prune->calls[0]['force'], 'preview retains inventory prune force protections' );
safe_cleanup_assert( 1 === ( $inventory_preview_result['summary']['inventory_rows_planned'] ?? null ), 'preview reports missing inventory rows planned for pruning' );
safe_cleanup_assert( 0 === ( $inventory_preview_result['summary']['inventory_rows_pruned'] ?? null ), 'preview does not report inventory rows as pruned' );
safe_cleanup_assert( 1 === ( $inventory_preview_result['summary']['inventory_rows_skipped'] ?? null ), 'preview reports protected inventory rows as skipped' );

$duplicate_blocker_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array( 'skipped_by_reason' => array( 'lifecycle_reconciliation_candidate' => 145 ) ),
		),
		array(
			'success' => true,
			'summary' => array( 'skipped_by_reason' => array( 'lifecycle_reconciliation_candidate' => 145 ) ),
		),
	)
);
$duplicate_preview         = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $duplicate_blocker_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$duplicate_result          = $duplicate_preview->run( array( 'dry_run' => true ) );
safe_cleanup_assert( 145 === ( $duplicate_result['summary']['blocker_count'] ?? null ), 'the same inventory blockers are not double-counted across safe-cleanup stages' );

$unchanged_blocker_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array(),
			'pass_results' => array(
				array( 'skipped_by_reason' => array( 'live_worktree' => 1 ) ),
				array( 'skipped_by_reason' => array( 'live_worktree' => 1 ) ),
				array( 'skipped_by_reason' => array( 'live_worktree' => 1 ) ),
			),
		),
		array(
			'success' => true,
			'summary' => array(),
		),
	)
);
$unchanged_preview         = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $unchanged_blocker_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
);
$unchanged_result          = $unchanged_preview->run( array( 'dry_run' => true ) );
safe_cleanup_assert( 1 === ( $unchanged_result['summary']['blocker_count'] ?? null ), 'one unchanged blocker across three passes is reported once' );

$changing_blocker_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array(),
			'pass_results' => array(
				array( 'skipped_by_reason' => array( 'dirty_worktree' => 2 ) ),
				array( 'skipped_by_reason' => array( 'live_worktree' => 3 ) ),
				array( 'skipped_by_reason' => array() ),
			),
		),
		array(
			'success' => true,
			'summary' => array(),
		),
	)
);
$cleared_run_repository   = new SafeCleanupFakeRunRepository();
$changing_preview         = new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $changing_blocker_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	$cleared_run_repository
);
$changing_result          = $changing_preview->run( array() );
safe_cleanup_assert( 5 === ( $changing_result['summary']['blocker_count'] ?? null ), 'blocker count sums independent per-reason maxima without claiming a maximum total' );
safe_cleanup_assert( 2 === ( $changing_result['summary']['blockers_by_reason']['dirty_worktree'] ?? null ), 'dirty blocker bucket preserves its pass maximum' );
safe_cleanup_assert( 3 === ( $changing_result['summary']['blockers_by_reason']['live_worktree'] ?? null ), 'live blocker bucket preserves its separate pass maximum' );
safe_cleanup_assert( 'complete' === ( $changing_result['state'] ?? null ), 'historical blocker maxima do not keep a cleared cleanup run blocked' );
safe_cleanup_assert( 'complete' === ( $cleared_run_repository->runs['cleanup-run-safe-test']['status'] ?? null ), 'persisted terminal status follows final-pass blockers rather than historical maxima' );
safe_cleanup_assert( 0 === ( $changing_result['summary']['current_blocker_count'] ?? null ), 'cleared final pass exposes a machine-readable zero current blocker count' );

$entering_blocker_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array(),
			'pass_results' => array(
				array( 'skipped_by_reason' => array( 'dirty_worktree' => 2 ) ),
				array( 'skipped_by_reason' => array( 'live_worktree' => 3 ) ),
				array( 'skipped_by_reason' => array( 'live_worktree' => 1 ) ),
			),
		),
		array(
			'success' => true,
			'summary' => array(),
		),
	)
);
$entering_result          = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $entering_blocker_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
) )->run( array() );
safe_cleanup_assert( 5 === ( $entering_result['summary']['blocker_count'] ?? null ), 'historical blocker maxima retain entering and leaving observations' );
safe_cleanup_assert( 1 === ( $entering_result['summary']['current_blockers_by_reason']['live_worktree'] ?? null ), 'current blocker buckets retain only the final-pass live blocker count' );
safe_cleanup_assert( ! isset( $entering_result['summary']['current_blockers_by_reason']['dirty_worktree'] ), 'current blocker buckets exclude a blocker that left before the final pass' );
safe_cleanup_assert( 'complete_with_blockers' === ( $entering_result['state'] ?? null ), 'a blocker present in the final pass keeps cleanup terminally blocked' );

$cycle_blocker_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array( 'removed' => 1 ),
			'pass_results' => array( array( 'skipped_by_reason' => array( 'dirty_worktree' => 1 ) ) ),
		),
		array(
			'success' => true,
			'mode'    => 'active_no_signal_drain',
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array( 'removed' => 0 ),
			'pass_results' => array( array( 'skipped_by_reason' => array() ) ),
		),
		array(
			'success' => true,
			'mode'    => 'active_no_signal_drain',
			'summary' => array(),
		),
	)
);
$cycle_result          = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $cycle_blocker_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
) )->run( array( 'cycles' => 2 ) );
safe_cleanup_assert( 1 === ( $cycle_result['summary']['blocker_count'] ?? null ), 'historical maxima retain blockers seen before the final cycle' );
safe_cleanup_assert( 'complete' === ( $cycle_result['state'] ?? null ), 'a blocker cleared in the final cycle does not keep cleanup terminally blocked' );

$resolved_incomplete_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array(
				'removed'     => 1,
				'stop_reason' => 'pass_limit',
			),
			'pass_results' => array( array( 'skipped_by_reason' => array() ) ),
		),
		array(
			'success' => true,
			'mode'    => 'active_no_signal_drain',
			'summary' => array(),
		),
		array(
			'success'      => true,
			'summary'      => array(
				'removed'     => 0,
				'stop_reason' => 'empty',
			),
			'pass_results' => array( array( 'skipped_by_reason' => array() ) ),
		),
		array(
			'success' => true,
			'mode'    => 'active_no_signal_drain',
			'summary' => array(),
		),
	)
);
$resolved_incomplete_result  = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $resolved_incomplete_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
) )->run( array( 'cycles' => 2 ) );
safe_cleanup_assert( 'complete' === ( $resolved_incomplete_result['state'] ?? null ), 'an incomplete child drain resolved in the final cycle does not remain latched as blocked' );

$active_blocker_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'                            => true,
			'mode'                               => 'active_no_signal_drain',
			'summary'                            => array( 'blocked_by_reason' => array( 'unpushed_commits' => 2 ) ),
			'remaining_active_no_signal_backlog' => array( 'by_actionable_reason' => array() ),
		),
	)
);
$active_result          = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
	static fn() => $active_blocker_ability,
	static fn( bool $dry_run ) => array(
		'dry_run'    => $dry_run,
		'after'      => array(),
		'filesystem' => array(),
	),
	new SafeCleanupFakeRunRepository()
) )->run( array() );
safe_cleanup_assert( 2 === ( $active_result['summary']['blockers_by_reason']['unpushed_commits'] ?? null ), 'historical active/no-signal blocker observations remain reported' );
safe_cleanup_assert( 'complete' === ( $active_result['state'] ?? null ), 'omitted active/no-signal current reasons clear cumulative summary blockers' );

$incomplete_active_ability = new SafeCleanupQueuedAbility(
	array(
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success' => true,
			'summary' => array(),
		),
		array(
			'success'                            => true,
			'mode'                               => 'active_no_signal_drain',
			'summary'                            => array( 'blocked_by_reason' => array( 'unpushed_commits' => 2 ) ),
			'continuation'                       => array(
				'reason'       => 'page_incomplete',
				'next_command' => 'studio wp datamachine-code workspace worktree active-no-signal-drain --apply --offset=25 --limit=25',
				'next_offset'  => 25,
			),
			'remaining_active_no_signal_backlog' => array( 'by_actionable_reason' => array() ),
		),
	)
);
$incomplete_run_repository = new SafeCleanupFakeRunRepository();
	$incomplete_result     = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
		static fn() => $incomplete_active_ability,
		static fn( bool $dry_run ) => array(
			'dry_run'    => $dry_run,
			'after'      => array(),
			'filesystem' => array(),
		),
		$incomplete_run_repository
	) )->run( array() );
	safe_cleanup_assert( 'complete_with_blockers' === ( $incomplete_result['state'] ?? null ), 'active/no-signal continuation prevents terminal complete without current backlog detail' );
	safe_cleanup_assert( 'complete_with_blockers' === ( $incomplete_run_repository->runs['cleanup-run-safe-test']['status'] ?? null ), 'incomplete active/no-signal drain persists a non-complete terminal status' );
	safe_cleanup_assert( 25 === ( $incomplete_result['continuation']['active_no_signal']['next_offset'] ?? null ), 'typed active/no-signal continuation evidence is preserved' );

	$interrupted_repository = new SafeCleanupDurableCheckpointRepository();
	$committed_ability      = new SafeCleanupCommittedCandidateAbility();
	$empty_ability          = new SafeCleanupQueuedAbility(array_fill(0, 8, array( 'success' => true, 'summary' => array() )));
	$output_attempts        = 0;
	$interrupted_result     = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
		static fn( string $name ) => 'datamachine-code/workspace-worktree-cleanup-eligible-drain' === $name ? $committed_ability : $empty_ability,
		static fn( bool $dry_run ) => array( 'dry_run' => $dry_run, 'after' => array(), 'filesystem' => array() ),
		$interrupted_repository
	) )->run(array(
		'cycles' => 2,
		'progress_callback' => static function () use ( &$output_attempts ): void {
			++$output_attempts;
			throw new RuntimeException('Synthetic client output interruption.');
		},
	));
	$committed_items = array_values(array_filter($interrupted_repository->items, static fn( array $item ): bool => 'applied' === (string) ( $item['status'] ?? '' )));
	safe_cleanup_assert(1 === $output_attempts && ! is_wp_error($interrupted_result), 'an output interruption after the early durable envelope does not stop safe cleanup');
	safe_cleanup_assert('cleanup-run-interrupted-output' === ($interrupted_result['run_id'] ?? null) && array() !== $committed_items, 'committed mutation evidence remains recoverable by durable run ID after output interruption');
	safe_cleanup_assert(false === ($committed_items[0]['evidence']['outcome']['path_exists_after'] ?? true), 'durable committed evidence records the post-removal path state');

	$cancellation_repository = new SafeCleanupFakeRunRepository();
	$cancelling_ability      = new SafeCleanupCancellingAbility( $cancellation_repository );
	$cancelled_mid_run       = ( new DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator(
		static fn() => $cancelling_ability,
		static fn( bool $dry_run ) => array(
			'dry_run'    => $dry_run,
			'after'      => array(),
			'filesystem' => array(),
		),
		$cancellation_repository
	) )->run( array() );
	safe_cleanup_assert( is_wp_error( $cancelled_mid_run ), 'mid-run cancellation stops before the next child stage' );
	safe_cleanup_assert( 'safe_cleanup_cancelled' === $cancelled_mid_run->code, 'mid-run cancellation returns its durable cancellation error code' );
	safe_cleanup_assert( 'cancelled' === ( $cancellation_repository->runs['cleanup-run-safe-test']['status'] ?? null ), 'mid-run cancellation is not overwritten by later progress checkpoints' );

	fwrite( STDOUT, "workspace safe cleanup orchestrator test passed\n" );
