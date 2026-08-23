<?php
/**
 * Safe workspace cleanup orchestration.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

/**
 * Composes existing DMC-safe cleanup primitives into one bounded entrypoint.
 */
class WorkspaceSafeCleanupOrchestrator {

	private const DEFAULT_SOURCE = 'workspace_safe_cleanup';

	/** @var callable */
	private $ability_resolver;

	/** @var callable */
	private $lock_pruner;

	/** @var \DataMachineCode\Storage\CleanupRunRepositoryInterface|null */
	private $run_repository;

	private string $active_run_id = '';

	/**
	 * @param callable|null $ability_resolver Resolver receiving an ability name.
	 * @param callable|null $lock_pruner      Callback receiving a dry-run bool.
	 * @param \DataMachineCode\Storage\CleanupRunRepositoryInterface|null $run_repository Cleanup run repository override for tests.
	 */
	public function __construct( ?callable $ability_resolver = null, ?callable $lock_pruner = null, ?\DataMachineCode\Storage\CleanupRunRepositoryInterface $run_repository = null ) {
		$this->ability_resolver = $ability_resolver ? $ability_resolver : static fn( string $name ) => function_exists('wp_get_ability') ? wp_get_ability($name) : null;
		$this->lock_pruner      = $lock_pruner ? $lock_pruner : array( $this, 'prune_locks' );
		$this->run_repository   = $run_repository;
	}

	/**
	 * Run the safe workspace cleanup flow.
	 *
	 * @param  array<string,mixed> $input Orchestration input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( array $input ): array|\WP_Error {
		if ( ! empty($input['force']) ) {
			return new \WP_Error('safe_cleanup_refuses_force', 'Safe workspace cleanup refuses force. Dirty worktrees remain blockers.', array( 'status' => 400 ));
		}
		if ( ! empty($input['discard_unpushed']) ) {
			return new \WP_Error('safe_cleanup_refuses_unpushed_discard', 'Safe workspace cleanup refuses unpushed commit discard. Unpushed worktrees remain blockers.', array( 'status' => 400 ));
		}

		$dry_run           = ! empty($input['dry_run']);
		$limit             = isset($input['limit']) ? max(1, min(200, (int) $input['limit'])) : 25;
		$passes            = isset($input['passes']) ? max(1, min(100, (int) $input['passes'])) : 10;
		$cycles            = isset($input['cycles']) ? max(1, min(25, (int) $input['cycles'])) : 5;
		$inventory_after   = isset($input['inventory_after']) ? trim( (string) $input['inventory_after'] ) : '';
		$source            = isset($input['source']) && '' !== trim( (string) $input['source']) ? trim( (string) $input['source']) : self::DEFAULT_SOURCE;
		$progress_callback = isset($input['progress_callback']) && is_callable($input['progress_callback']) ? $input['progress_callback'] : null;

		$cleanup_eligible = $this->resolve_ability('datamachine-code/workspace-worktree-cleanup-eligible-drain');
		if ( is_wp_error($cleanup_eligible) ) {
			return $cleanup_eligible;
		}
		$active_no_signal = $this->resolve_ability('datamachine-code/workspace-worktree-active-no-signal-drain');
		if ( is_wp_error($active_no_signal) ) {
			return $active_no_signal;
		}
		$artifact_cleanup = $this->resolve_ability($dry_run ? 'datamachine-code/workspace-cleanup-plan' : 'datamachine-code/workspace-cleanup-until-empty');
		if ( is_wp_error($artifact_cleanup) ) {
			return $artifact_cleanup;
		}
		$inventory_prune = $this->resolve_ability('datamachine-code/workspace-worktree-inventory-prune-missing');
		if ( is_wp_error($inventory_prune) ) {
			return $inventory_prune;
		}

		$result = array(
			'success'           => true,
			'mode'              => 'safe_workspace_cleanup',
			'applied'           => ! $dry_run,
			'destructive'       => ! $dry_run,
			'limit'             => $limit,
			'passes'            => $passes,
			'cycles'            => $cycles,
			'generated_at'      => gmdate('c'),
			'steps'             => array(),
			'summary'           => array(
				'cycles'                  => 0,
				'planned'                 => 0,
				'applied_rows'            => 0,
				'skipped_rows'            => 0,
				'would_reclaim_bytes'     => 0,
				'removed'                 => 0,
				'would_remove'            => 0,
				'marked_cleanup_eligible' => 0,
				'bytes_reclaimed'         => 0,
				'lock_files_removed'      => 0,
				'inventory_rows_pruned'   => 0,
				'inventory_rows_planned'  => 0,
				'inventory_rows_skipped'  => 0,
				'blocker_count'           => 0,
				'blockers_by_reason'      => array(),
			),
			'blockers'          => array(),
			'current_blockers'  => array(),
			'blockers_by_stage' => array(),
			'evidence'          => array(
				'safety' => $dry_run
					? 'Preview only. Uses DMC safe classifiers/removals and stale lock pruning in dry-run mode.'
					: 'Applies only DMC safe classifiers/removals, refuses force and unpushed discard, and prunes stale DMC locks.',
			),
		);

		$run_id = $this->create_progress_run($result, $dry_run, $limit, $passes, $cycles, $source, (string) ( $input['run_id'] ?? '' ), (string) ( $input['request_id'] ?? '' ));
		if ( $run_id instanceof \WP_Error ) {
			return $run_id;
		}
		$result['run_id']       = $run_id;
		$this->active_run_id    = $run_id;
		$result['commands']     = $this->progress_commands($run_id, $dry_run, $limit, $passes, $cycles, $input);
		$result['continuation'] = array(
			'run_id'           => $run_id,
			'status_command'   => $result['commands']['status'],
			'evidence_command' => $result['commands']['evidence'],
			'resume_command'   => $result['commands']['resume'],
			'note'             => 'If the client disconnects, inspect this run_id and rerun the resume command. Safe cleanup remains bounded and preserves dirty/unpushed blockers.',
			'pending_stages'   => array(),
		);
		$this->checkpoint_progress($run_id, $result, 'applying');
		if ( null !== $progress_callback ) {
			$progress_callback($this->early_progress_result($result));
		}

		$lock_start = ( $this->lock_pruner )($dry_run);
		if ( is_wp_error($lock_start) ) {
			return $lock_start;
		}
		$result['steps']['lock_prune_start']      = $this->summarize_lock_step($lock_start);
		$result['summary']['lock_files_removed'] += (int) ( $result['steps']['lock_prune_start']['removed_count'] ?? 0 );
		$this->checkpoint_progress($run_id, $result, 'applying');

		$artifact_input = $dry_run
			? array(
				'mode'              => 'artifacts',
				'include_artifacts' => true,
				'include_worktrees' => false,
				'include_resolvers' => false,
				'limit'             => $limit,
			)
			: array(
				'mode'       => 'artifacts',
				'force'      => false,
				'limit'      => $limit,
				'max_passes' => $passes,
			);
		$artifacts      = $this->execute_ability($artifact_cleanup, $artifact_input);
		if ( is_wp_error($artifacts) ) {
			return $artifacts;
		}
		$result['steps']['artifact_cleanup']             = $this->summarize_artifact_step($artifacts, $dry_run);
		$result['blockers_by_stage']['artifact_cleanup'] = (array) ( $result['steps']['artifact_cleanup']['blockers'] ?? array() );
		$current_artifact_blockers                       = $result['blockers_by_stage']['artifact_cleanup'];
		$this->accumulate_artifact_step($result, $result['steps']['artifact_cleanup']);
		$this->checkpoint_progress($run_id, $result, 'applying');

		$common = array(
			'apply'            => ! $dry_run,
			'force'            => false,
			'discard_unpushed' => false,
			'limit'            => $limit,
			'passes'           => $passes,
			'source'           => $source,
		);
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$common['until_budget'] = trim( (string) $input['until_budget']);
		}
		$current_cycle_blockers     = array();
		$has_incomplete_child_drain = false;

		for ( $cycle = 1; $cycle <= $cycles; ++$cycle ) {
			$result['summary']['cycles'] = $cycle;
			$cycle_progress              = 0;
			$current_cycle_blockers      = array();
			$has_incomplete_child_drain  = false;

			$eligible = $this->execute_ability($cleanup_eligible, $common);
			if ( is_wp_error($eligible) ) {
				return $eligible;
			}
			$result['steps'][ 'cleanup_eligible_' . $cycle ]             = $this->summarize_cleanup_step($eligible);
			$result['blockers_by_stage'][ 'cleanup_eligible_' . $cycle ] = (array) ( $result['steps'][ 'cleanup_eligible_' . $cycle ]['blockers'] ?? array() );

			$current_cycle_blockers      = $this->merge_blocker_counts($current_cycle_blockers, $this->extract_current_blocker_counts($eligible));
			$has_incomplete_child_drain  = $this->child_drain_is_incomplete($eligible);

			$cycle_progress += $this->accumulate_cleanup_step($result, $eligible);
			$this->checkpoint_progress($run_id, $result, 'applying');

			$active = $this->execute_ability($active_no_signal, $common);
			if ( is_wp_error($active) ) {
				return $active;
			}
			$result['steps'][ 'active_no_signal_' . $cycle ]             = $this->summarize_cleanup_step($active);
			$result['blockers_by_stage'][ 'active_no_signal_' . $cycle ] = (array) ( $result['steps'][ 'active_no_signal_' . $cycle ]['blockers'] ?? array() );

			$current_cycle_blockers      = $this->merge_blocker_counts($current_cycle_blockers, $this->extract_current_blocker_counts($active));
			$has_incomplete_child_drain = $has_incomplete_child_drain || $this->child_drain_is_incomplete($active);

			$cycle_progress += $this->accumulate_cleanup_step($result, $active);
			if ( is_array($active['continuation'] ?? null) && array() !== $active['continuation'] ) {
				$result['continuation']['active_no_signal'] = $active['continuation'];
				$result['continuation']['pending_stages']['active_no_signal'] = $active['continuation'];
				if ( ! empty($active['continuation']['next_command']) ) {
					$result['continuation']['next_command'] = (string) $active['continuation']['next_command'];
					$result['continuation']['reason']       = (string) ( $active['continuation']['reason'] ?? 'active_no_signal_page_incomplete' );
				}
			}
			$this->checkpoint_progress($run_id, $result, 'applying');

			if ( $dry_run || 0 === $cycle_progress ) {
				break;
			}
		}

		// This owning-layer primitive rechecks each path immediately before deletion.
		$inventory_input = array(
			'dry_run'      => $dry_run,
			'force'        => false,
			'limit'        => $limit,
			'after_handle' => $inventory_after,
		);
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$inventory_input['until_budget'] = trim( (string) $input['until_budget']);
		}
		$inventory = $this->execute_ability($inventory_prune, $inventory_input);
		if ( is_wp_error($inventory) ) {
			return $inventory;
		}
		$result['steps']['inventory_prune_missing']             = $this->summarize_inventory_prune_step($inventory, $dry_run);
		$result['blockers_by_stage']['inventory_prune_missing'] = (array) ( $result['steps']['inventory_prune_missing']['blockers'] ?? array() );
		$this->accumulate_inventory_prune_step($result, $result['steps']['inventory_prune_missing']);
		if ( isset($inventory['continuation']['next_after_handle']) ) {
			$next_after                                  = (string) $inventory['continuation']['next_after_handle'];
			$result['continuation']['inventory_after']  = $next_after;
			$inventory_continuation = array(
				'reason'       => (string) ( $inventory['continuation']['reason'] ?? 'inventory_prune_incomplete' ),
				'after_handle' => $next_after,
				'next_command' => $this->progress_commands($run_id, $dry_run, $limit, $passes, $cycles, array_merge($input, array( 'inventory_after' => $next_after )))['resume'],
			);
			$result['continuation']['pending_stages']['inventory_prune_missing'] = $inventory_continuation;
			if ( empty($result['continuation']['next_command']) ) {
				$result['continuation']['reason']       = $inventory_continuation['reason'];
				$result['continuation']['next_command'] = $inventory_continuation['next_command'];
			}
		}
		ksort($result['continuation']['pending_stages']);
		$this->checkpoint_progress($run_id, $result, 'applying');

		$lock_end = ( $this->lock_pruner )($dry_run);
		if ( is_wp_error($lock_end) ) {
			return $lock_end;
		}
		$result['steps']['lock_prune_end']        = $this->summarize_lock_step($lock_end);
		$result['summary']['lock_files_removed'] += (int) ( $result['steps']['lock_prune_end']['removed_count'] ?? 0 );
		$this->checkpoint_progress($run_id, $result, 'applying');

		$result['blockers']                       = $this->compact_blockers($result['blockers']);
		$result['summary']['blocker_count']       = array_sum(array_map(static fn( array $row ): int => (int) ( $row['count'] ?? 0 ), $result['blockers']));
		$result['summary']['blockers_by_reason']  = array_column($result['blockers'], 'count', 'reason_code');
		$result['summary']['blocker_count_scope'] = 'sum_of_per_reason_maximum_observations_across_stages';
		$current_blocker_counts                    = $this->merge_blocker_counts($current_artifact_blockers, $current_cycle_blockers);
		$result['current_blockers']                 = $this->blocker_rows($current_blocker_counts);
		$result['summary']['current_blocker_count'] = array_sum($current_blocker_counts);
		$result['summary']['current_blockers_by_reason'] = $current_blocker_counts;
		$result['summary']['current_blocker_count_scope'] = 'final_cycle_per_reason_observations';

		$has_current_blockers = array() !== $current_blocker_counts;
		if ( ! $dry_run && ( $has_current_blockers || $has_incomplete_child_drain ) ) {
			$result['state'] = 'complete_with_blockers';
		} else {
			$result['state'] = 'complete';
		}
		$this->checkpoint_progress($run_id, $result, $result['state']);

		return $result;
	}

	/** @return string|\WP_Error */
	private function create_progress_run( array $result, bool $dry_run, int $limit, int $passes, int $cycles, string $source, string $existing_run_id = '', string $request_id = '' ): string|\WP_Error {
		if ( '' !== trim($existing_run_id) ) {
			return trim($existing_run_id);
		}
		$repository = $this->progress_repository();
		$run_id     = $repository->create_run(
			array(
				'mode'       => 'safe_workspace_cleanup',
				'status'     => 'applying',
				'started_at' => gmdate('Y-m-d H:i:s'),
				'policy'     => array(
					'dry_run'          => $dry_run,
					'force'            => false,
					'discard_unpushed' => false,
					'limit'            => $limit,
					'passes'           => $passes,
					'cycles'           => $cycles,
					'source'           => $source,
					'request_id'       => $request_id,
				),
				'summary'    => $this->progress_summary($result),
			)
		);

		return $run_id;
	}

	private function checkpoint_progress( string $run_id, array $result, string $status ): void {
		$repository = $this->progress_repository();
		if ( method_exists($repository, 'get_run') ) {
			$existing = $repository->get_run($run_id);
			if ( is_array($existing) && 'cancelled' === (string) ( $existing['status'] ?? '' ) ) {
				return;
			}
		}
		$fields     = array(
			'status'  => $status,
			'summary' => $this->progress_summary($result),
		);
		if ( in_array($status, array( 'complete', 'complete_with_blockers' ), true) ) {
			$fields['completed_at'] = gmdate('Y-m-d H:i:s');
		}
		$repository->update_run($run_id, $fields);
	}

	private function progress_repository(): \DataMachineCode\Storage\CleanupRunRepositoryInterface {
		if ( null === $this->run_repository ) {
			$this->run_repository = new \DataMachineCode\Storage\CleanupRunRepository();
		}

		return $this->run_repository;
	}

	/** @return array<string,mixed> */
	private function progress_summary( array $result ): array {
		return array(
			'safe_cleanup_progress' => array(
				'generated_at' => gmdate('c'),
				'state'        => (string) ( $result['state'] ?? 'applying' ),
				'applied'      => (bool) ( $result['applied'] ?? false ),
				'destructive'  => (bool) ( $result['destructive'] ?? false ),
				'summary'      => (array) ( $result['summary'] ?? array() ),
				'blockers'     => (array) ( $result['blockers'] ?? array() ),
				'steps'        => (array) ( $result['steps'] ?? array() ),
				'commands'     => (array) ( $result['commands'] ?? array() ),
				'continuation' => (array) ( $result['continuation'] ?? array() ),
			),
		);
	}

	/** @return array<string,string> */
	private function progress_commands( string $run_id, bool $dry_run, int $limit, int $passes, int $cycles, array $input ): array {
		$resume = sprintf('studio wp datamachine-code workspace cleanup safe --limit=%d --passes=%d --cycles=%d', $limit, $passes, $cycles);
		if ( $dry_run ) {
			$resume .= ' --dry-run';
		}
		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$resume .= ' --until-budget=' . trim( (string) $input['until_budget']);
		}
		if ( isset($input['inventory_after']) && '' !== trim( (string) $input['inventory_after']) ) {
			$resume .= ' --inventory-after=' . escapeshellarg(trim( (string) $input['inventory_after']));
		}

		return array(
			'status'   => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
			'evidence' => sprintf('studio wp datamachine-code workspace cleanup evidence %s --format=json', $run_id),
			'resume'   => $resume . ' --format=json',
			'cancel'   => sprintf('studio wp datamachine-code workspace cleanup cancel %s --format=json', $run_id),
		);
	}

	/** @return array<string,mixed> */
	private function early_progress_result( array $result ): array {
		return array(
			'success'      => true,
			'mode'         => (string) ( $result['mode'] ?? 'safe_workspace_cleanup' ),
			'state'        => 'applying',
			'run_id'       => (string) ( $result['run_id'] ?? '' ),
			'applied'      => (bool) ( $result['applied'] ?? false ),
			'destructive'  => (bool) ( $result['destructive'] ?? false ),
			'generated_at' => gmdate('c'),
			'summary'      => (array) ( $result['summary'] ?? array() ),
			'commands'     => (array) ( $result['commands'] ?? array() ),
			'continuation' => (array) ( $result['continuation'] ?? array() ),
		);
	}

	private function resolve_ability( string $name ): mixed {
		$ability = ( $this->ability_resolver )($name);
		if ( ! is_object($ability) || ! is_callable(array( $ability, 'execute' )) ) {
			return new \WP_Error('safe_cleanup_ability_missing', sprintf('Safe cleanup ability not available: %s', $name), array( 'status' => 500 ));
		}

		return $ability;
	}

	private function execute_ability( object $ability, array $input ): array|\WP_Error {
		if ( '' !== $this->active_run_id && $this->run_is_cancelled($this->active_run_id) ) {
			return new \WP_Error('safe_cleanup_cancelled', 'Safe workspace cleanup was cancelled while running.', array( 'status' => 409 ));
		}
		$executor = array( $ability, 'execute' );
		if ( ! is_callable($executor) ) {
			return new \WP_Error('safe_cleanup_ability_missing', 'Safe cleanup ability is not executable.', array( 'status' => 500 ));
		}
		$result = $executor($input);
		return is_array($result) || is_wp_error($result) ? $result : new \WP_Error('safe_cleanup_invalid_result', 'Safe cleanup child ability returned an invalid result.', array( 'status' => 500 ));
	}

	private function run_is_cancelled( string $run_id ): bool {
		$repository = $this->progress_repository();
		if ( ! method_exists($repository, 'get_run') ) {
			return false;
		}
		$run = $repository->get_run($run_id);
		return is_array($run) && 'cancelled' === (string) ( $run['status'] ?? '' );
	}

	private function prune_locks( bool $dry_run ): array {
		$workspace = new Workspace();
		return WorkspaceMutationLock::prune_stale($workspace->get_path(), $dry_run);
	}

	/** @return array<string,mixed> */
	private function summarize_cleanup_step( array $step ): array {
		$summary = (array) ( $step['summary'] ?? array() );
		return array(
			'mode'                    => (string) ( $step['mode'] ?? '' ),
			'applied'                 => ! empty($step['applied']) || ! empty($step['destructive']),
			'passes'                  => (int) ( $summary['passes'] ?? $step['executed_passes'] ?? 0 ),
			'processed'               => (int) ( $summary['processed'] ?? $summary['scanned'] ?? 0 ),
			'removed'                 => (int) ( $summary['removed'] ?? 0 ),
			'would_remove'            => (int) ( $summary['would_remove'] ?? 0 ),
			'marked_cleanup_eligible' => (int) ( $summary['marked_cleanup_eligible'] ?? 0 ),
			'bytes_reclaimed'         => (int) ( $summary['bytes_reclaimed'] ?? 0 ),
			'blockers'                => $this->extract_blocker_counts($step),
		);
	}

	/** @return array<string,mixed> */
	private function summarize_artifact_step( array $step, bool $dry_run ): array {
		$rows     = (array) ( $step['rows']['artifact_cleanup'] ?? array() );
		$blocked  = (array) ( $step['blocked']['artifact_cleanup'] ?? array() );
		$passes   = (array) ( $step['passes'] ?? array() );
		$planned  = $dry_run ? count($rows) : array_sum(array_map(static fn( array $pass ): int => (int) ( $pass['planned_rows'] ?? 0 ), $passes));
		$blockers = array();
		if ( $dry_run ) {
			foreach ( $blocked as $row ) {
				$reason              = (string) ( is_array($row) ? ( $row['reason_code'] ?? 'unknown' ) : 'unknown' );
				$blockers[ $reason ] = ( $blockers[ $reason ] ?? 0 ) + 1;
			}
		} else {
			foreach ( (array) ( $step['remaining_blocked_reasons'] ?? array() ) as $reason => $bucket ) {
				$blockers[ (string) $reason ] = max(0, (int) ( is_array($bucket) ? ( $bucket['count'] ?? 0 ) : $bucket ));
			}
		}

		return array(
			'mode'            => 'artifacts',
			'applied'         => ! $dry_run,
			'planned'         => $planned,
			'applied_rows'    => $dry_run ? 0 : (int) ( $step['applied'] ?? 0 ),
			'skipped_rows'    => $dry_run ? count($blocked) : (int) ( $step['skipped'] ?? 0 ),
			'bytes_reclaimed' => $dry_run ? 0 : (int) ( $step['bytes_reclaimed'] ?? 0 ),
			'would_reclaim'   => $dry_run ? (int) ( $step['summary']['total_reclaimable_bytes'] ?? 0 ) : 0,
			'blockers'        => array_filter($blockers),
			'state'           => (string) ( $step['state'] ?? $step['status'] ?? 'planned' ),
		);
	}

	/** @param array<string,mixed> $step */
	private function accumulate_artifact_step( array &$result, array $step ): void {
		$result['summary']['planned']             += (int) ( $step['planned'] ?? 0 );
		$result['summary']['applied_rows']        += (int) ( $step['applied_rows'] ?? 0 );
		$result['summary']['skipped_rows']        += (int) ( $step['skipped_rows'] ?? 0 );
		$result['summary']['would_reclaim_bytes'] += (int) ( $step['would_reclaim'] ?? 0 );
		$result['summary']['removed']             += (int) ( $step['applied_rows'] ?? 0 );
		$result['summary']['would_remove']        += (int) ( $step['planned'] ?? 0 );
		$result['summary']['bytes_reclaimed']     += (int) ( $step['bytes_reclaimed'] ?? 0 );
		foreach ( (array) ( $step['blockers'] ?? array() ) as $reason => $count ) {
			$result['blockers'][] = array(
				'reason_code' => (string) $reason,
				'count'       => (int) $count,
			);

		}
	}

	/** @return array<string,mixed> */
	private function summarize_inventory_prune_step( array $step, bool $dry_run ): array {
		$summary  = (array) ( $step['summary'] ?? array() );
		$deleted  = (array) ( $step['deleted'] ?? array() );
		$skipped  = (array) ( $step['skipped'] ?? array() );
		$blockers = array();
		foreach ( $skipped as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$reason              = (string) ( $row['reason'] ?? 'unknown' );
			$blockers[ $reason ] = ( $blockers[ $reason ] ?? 0 ) + 1;
		}

		return array(
			'mode'             => 'inventory_prune_missing',
			'dry_run'          => $dry_run,
			'planned_rows'     => $dry_run ? (int) ( $summary['deleted'] ?? count($deleted) ) : 0,
			'pruned_rows'      => $dry_run ? 0 : (int) ( $summary['deleted'] ?? count($deleted) ),
			'skipped_rows'     => (int) ( $summary['skipped'] ?? count($skipped) ),
			'candidate_rows'   => (int) ( $summary['total'] ?? ( count($deleted) + count($skipped) ) ),
			'continuation'     => (array) ( $step['continuation'] ?? array() ),
			'blockers'         => $blockers,
			'pruned_examples'  => $this->inventory_prune_examples($deleted),
			'skipped_examples' => $this->inventory_prune_examples($skipped),
		);
	}

	/** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
	private function inventory_prune_examples( array $rows ): array {
		$examples = array();
		foreach ( array_slice($rows, 0, 10) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$examples[] = array_filter(
				array(
					'handle' => isset($row['handle']) ? (string) $row['handle'] : '',
					'reason' => isset($row['reason']) ? (string) $row['reason'] : '',
				),
				static fn( string $value ): bool => '' !== $value
			);
		}

		return $examples;
	}

	private function accumulate_inventory_prune_step( array &$result, array $step ): void {
		$result['summary']['inventory_rows_pruned']  += (int) ( $step['pruned_rows'] ?? 0 );
		$result['summary']['inventory_rows_planned'] += (int) ( $step['planned_rows'] ?? 0 );
		$result['summary']['inventory_rows_skipped'] += (int) ( $step['skipped_rows'] ?? 0 );
		foreach ( (array) ( $step['blockers'] ?? array() ) as $reason => $count ) {
			$result['blockers'][] = array(
				'reason_code' => (string) $reason,
				'count'       => (int) $count,
			);
		}
	}

	private function accumulate_cleanup_step( array &$result, array $step ): int {
		$summary = (array) ( $step['summary'] ?? array() );
		foreach ( array( 'removed', 'would_remove', 'marked_cleanup_eligible', 'bytes_reclaimed' ) as $field ) {
			$result['summary'][ $field ] += (int) ( $summary[ $field ] ?? 0 );
		}

		foreach ( $this->extract_blocker_counts($step) as $reason => $count ) {
			$result['blockers'][] = array(
				'reason_code' => (string) $reason,
				'count'       => (int) $count,
			);
		}
		return (int) ( $summary['removed'] ?? 0 ) + (int) ( $summary['marked_cleanup_eligible'] ?? 0 );
	}

	/** @return array<string,int> */
	private function extract_blocker_counts( array $step ): array {
		$counts  = array();
		$summary = (array) ( $step['summary'] ?? array() );
		foreach ( (array) ( $summary['blocked_by_reason'] ?? $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$counts[ (string) $reason ] = (int) $count;
		}
		foreach ( (array) ( $step['pass_results'] ?? array() ) as $pass ) {
			if ( ! is_array($pass) ) {
				continue;
			}
			foreach ( (array) ( $pass['skipped_by_reason'] ?? array() ) as $reason => $count ) {
				$counts[ (string) $reason ] = max( (int) ( $counts[ (string) $reason ] ?? 0 ), (int) $count );
			}
		}
		foreach ( (array) ( $step['remaining_active_no_signal_backlog']['by_actionable_reason'] ?? array() ) as $reason => $row ) {
			$counts[ (string) $reason ] = max( (int) ( $counts[ (string) $reason ] ?? 0 ), (int) ( is_array($row) ? ( $row['count'] ?? 0 ) : 0 ) );
		}

		return array_filter($counts, static fn( int $count ): bool => $count > 0);
	}

	/** @return array<string,int> */
	private function extract_current_blocker_counts( array $step ): array {
		$passes = (array) ( $step['pass_results'] ?? array() );
		if ( array() !== $passes ) {
			for ( $index = count($passes) - 1; $index >= 0; --$index ) {
				if ( ! is_array($passes[ $index ]) ) {
					continue;
				}
				return array_filter(array_map('intval', (array) ( $passes[ $index ]['skipped_by_reason'] ?? array() )));
			}
		}
		if ( 'active_no_signal_drain' === (string) ( $step['mode'] ?? '' ) ) {
			return $this->extract_active_no_signal_current_blocker_counts($step);
		}

		$summary = (array) ( $step['summary'] ?? array() );
		$counts  = (array) ( $summary['blocked_by_reason'] ?? $summary['skipped_by_reason'] ?? array() );
		return array_filter(array_map('intval', $counts));
	}

	private function child_drain_is_incomplete( array $step ): bool {
		if ( array() !== (array) ( $step['continuation'] ?? array() ) || ! empty($step['evidence']['budget_exhausted']) ) {
			return true;
		}

		$stop_reason = (string) ( $step['summary']['stop_reason'] ?? '' );
		return in_array($stop_reason, array( 'pass_limit', 'budget_exhausted' ), true);
	}

	/** @param array<string,int> $left @param array<string,int> $right @return array<string,int> */
	private function merge_blocker_counts( array $left, array $right ): array {
		foreach ( $right as $reason => $count ) {
			$left[ (string) $reason ] = max( (int) ( $left[ (string) $reason ] ?? 0 ), (int) $count );
		}
		ksort($left);
		return array_filter($left, static fn( int $count ): bool => $count > 0);
	}

	/** @param array<string,int> $counts @return array<int,array<string,int|string>> */
	private function blocker_rows( array $counts ): array {
		$rows = array();
		foreach ( $counts as $reason => $count ) {
			$rows[] = array(
				'reason_code' => (string) $reason,
				'count'       => (int) $count,
			);
		}
		return $rows;
	}

	/** @return array<string,int> */
	private function extract_active_no_signal_current_blocker_counts( array $step ): array {
		$counts = array();
		foreach ( (array) ( $step['remaining_active_no_signal_backlog']['by_actionable_reason'] ?? array() ) as $reason => $row ) {
			$counts[ (string) $reason ] = (int) ( is_array($row) ? ( $row['count'] ?? 0 ) : $row );
		}

		return array_filter(array_map('intval', $counts));
	}

	/** @return array<string,mixed> */
	private function summarize_lock_step( array $step ): array {
		$status = (array) ( $step['after'] ?? $step );
		$fs     = (array) ( $step['filesystem'] ?? $status['filesystem'] ?? array() );
		return array(
			'dry_run'       => ! empty($step['dry_run']),
			'active'        => (int) ( $status['active'] ?? 0 ),
			'stale'         => (int) ( $status['stale'] ?? 0 ),
			'removed_count' => (int) ( $fs['removed_count'] ?? 0 ),
			'skipped_count' => (int) ( $fs['skipped_count'] ?? 0 ),
		);
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function compact_blockers( array $rows ): array {
		$blockers = array();
		foreach ( $rows as $row ) {
			$reason                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$blockers[ $reason ]        ??= array(
				'reason_code' => $reason,
				'count'       => 0,
			);
			$blockers[ $reason ]['count'] = max($blockers[ $reason ]['count'], (int) ( $row['count'] ?? 0 ));
		}
		ksort($blockers);

		return array_values($blockers);
	}
}
