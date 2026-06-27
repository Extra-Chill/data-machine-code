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

	/**
	 * @param callable|null $ability_resolver Resolver receiving an ability name.
	 * @param callable|null $lock_pruner      Callback receiving a dry-run bool.
	 */
	public function __construct( ?callable $ability_resolver = null, ?callable $lock_pruner = null ) {
		$this->ability_resolver = $ability_resolver ? $ability_resolver : static fn( string $name ) => function_exists('wp_get_ability') ? wp_get_ability($name) : null;
		$this->lock_pruner      = $lock_pruner ? $lock_pruner : array( $this, 'prune_locks' );
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

		$dry_run = ! empty($input['dry_run']);
		$limit   = isset($input['limit']) ? max(1, min(200, (int) $input['limit'])) : 25;
		$passes  = isset($input['passes']) ? max(1, min(100, (int) $input['passes'])) : 10;
		$cycles  = isset($input['cycles']) ? max(1, min(25, (int) $input['cycles'])) : 5;
		$source  = isset($input['source']) && '' !== trim( (string) $input['source']) ? trim( (string) $input['source']) : self::DEFAULT_SOURCE;

		$cleanup_eligible = $this->resolve_ability('datamachine-code/workspace-worktree-cleanup-eligible-drain');
		if ( is_wp_error($cleanup_eligible) ) {
			return $cleanup_eligible;
		}
		$active_no_signal = $this->resolve_ability('datamachine-code/workspace-worktree-active-no-signal-drain');
		if ( is_wp_error($active_no_signal) ) {
			return $active_no_signal;
		}

		$result = array(
			'success'      => true,
			'mode'         => 'safe_workspace_cleanup',
			'applied'      => ! $dry_run,
			'destructive'  => ! $dry_run,
			'limit'        => $limit,
			'passes'       => $passes,
			'cycles'       => $cycles,
			'generated_at' => gmdate('c'),
			'steps'        => array(),
			'summary'      => array(
				'cycles'                  => 0,
				'removed'                 => 0,
				'would_remove'            => 0,
				'marked_cleanup_eligible' => 0,
				'bytes_reclaimed'         => 0,
				'lock_files_removed'      => 0,
				'blocker_count'           => 0,
				'blockers_by_reason'      => array(),
			),
			'blockers'     => array(),
			'evidence'     => array(
				'safety' => $dry_run
					? 'Preview only. Uses DMC safe classifiers/removals and stale lock pruning in dry-run mode.'
					: 'Applies only DMC safe classifiers/removals, refuses force and unpushed discard, and prunes stale DMC locks.',
			),
		);

		$lock_start = ( $this->lock_pruner )($dry_run);
		if ( is_wp_error($lock_start) ) {
			return $lock_start;
		}
		$result['steps']['lock_prune_start'] = $this->summarize_lock_step($lock_start);
		$result['summary']['lock_files_removed'] += (int) ( $result['steps']['lock_prune_start']['removed_count'] ?? 0 );

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

		for ( $cycle = 1; $cycle <= $cycles; ++$cycle ) {
			$result['summary']['cycles'] = $cycle;
			$cycle_progress             = 0;

			$eligible = $this->execute_ability($cleanup_eligible, $common);
			if ( is_wp_error($eligible) ) {
				return $eligible;
			}
			$result['steps'][ 'cleanup_eligible_' . $cycle ] = $this->summarize_cleanup_step($eligible);
			$cycle_progress += $this->accumulate_cleanup_step($result, $eligible);

			$active = $this->execute_ability($active_no_signal, $common);
			if ( is_wp_error($active) ) {
				return $active;
			}
			$result['steps'][ 'active_no_signal_' . $cycle ] = $this->summarize_cleanup_step($active);
			$cycle_progress += $this->accumulate_cleanup_step($result, $active);

			if ( $dry_run || 0 === $cycle_progress ) {
				break;
			}
		}

		$lock_end = ( $this->lock_pruner )($dry_run);
		if ( is_wp_error($lock_end) ) {
			return $lock_end;
		}
		$result['steps']['lock_prune_end'] = $this->summarize_lock_step($lock_end);
		$result['summary']['lock_files_removed'] += (int) ( $result['steps']['lock_prune_end']['removed_count'] ?? 0 );

		$result['blockers']                    = $this->compact_blockers($result['blockers']);
		$result['summary']['blocker_count']    = array_sum(array_map(static fn( array $row ): int => (int) ( $row['count'] ?? 0 ), $result['blockers']));
		$result['summary']['blockers_by_reason'] = array_column($result['blockers'], 'count', 'reason_code');
		if ( ! $dry_run && $result['summary']['blocker_count'] > 0 ) {
			$result['state'] = 'complete_with_blockers';
		} else {
			$result['state'] = 'complete';
		}

		return $result;
	}

	private function resolve_ability( string $name ): mixed {
		$ability = ( $this->ability_resolver )($name);
		if ( ! is_object($ability) || ! is_callable(array( $ability, 'execute' )) ) {
			return new \WP_Error('safe_cleanup_ability_missing', sprintf('Safe cleanup ability not available: %s', $name), array( 'status' => 500 ));
		}

		return $ability;
	}

	private function execute_ability( object $ability, array $input ): array|\WP_Error {
		$result = $ability->execute($input);
		return is_array($result) || is_wp_error($result) ? $result : new \WP_Error('safe_cleanup_invalid_result', 'Safe cleanup child ability returned an invalid result.', array( 'status' => 500 ));
	}

	private function prune_locks( bool $dry_run ): array|\WP_Error {
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
		$counts = array();
		$summary = (array) ( $step['summary'] ?? array() );
		foreach ( (array) ( $summary['blocked_by_reason'] ?? $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$counts[ (string) $reason ] = (int) $count;
		}
		foreach ( (array) ( $step['pass_results'] ?? array() ) as $pass ) {
			if ( ! is_array($pass) ) {
				continue;
			}
			foreach ( (array) ( $pass['skipped_by_reason'] ?? array() ) as $reason => $count ) {
				$counts[ (string) $reason ] = (int) ( $counts[ (string) $reason ] ?? 0 ) + (int) $count;
			}
		}
		foreach ( (array) ( $step['remaining_active_no_signal_backlog']['by_actionable_reason'] ?? array() ) as $reason => $row ) {
			$counts[ (string) $reason ] = (int) ( $counts[ (string) $reason ] ?? 0 ) + (int) ( is_array($row) ? ( $row['count'] ?? 0 ) : 0 );
		}

		return array_filter($counts, static fn( int $count ): bool => $count > 0);
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
			$reason = (string) ( $row['reason_code'] ?? 'unknown' );
			$blockers[ $reason ] ??= array(
				'reason_code' => $reason,
				'count'       => 0,
			);
			$blockers[ $reason ]['count'] += (int) ( $row['count'] ?? 0 );
		}
		ksort($blockers);

		return array_values($blockers);
	}
}
