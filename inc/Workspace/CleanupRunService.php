<?php
/**
 * DB-backed cleanup run service.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Cleanup\CleanupRemainingWorkSummary;
use DataMachineCode\Storage\CleanupRunRepository;

defined('ABSPATH') || exit;

class CleanupRunService {

	private const DEFAULT_APPLY_LIMIT = 25;
	private const MAX_APPLY_LIMIT     = 100;



	public function __construct(
		private ?CleanupRunRepository $repository = null,
		private ?Workspace $workspace = null
	) {
		$this->repository ??= new CleanupRunRepository();
		$this->workspace  ??= new Workspace();
	}

	/**
	 * Create and persist a cleanup plan run.
	 *
	 * @param  array<string,mixed> $opts Plan options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function plan( array $opts = array() ): array|\WP_Error {
		$plan = $this->workspace->workspace_cleanup_plan($opts);
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$items  = $this->plan_items($plan);
		$run_id = $this->repository->create_run(
			array(
				'mode'                  => (string) ( $opts['mode'] ?? $plan['mode'] ?? 'cleanup_plan' ),
				'status'                => 'planned',
				'policy'                => $plan['safety_policy'] ?? array(),
				'requested_by_user_id'  => isset($opts['user_id']) ? (int) $opts['user_id'] : null,
				'requested_by_agent_id' => isset($opts['agent_id']) ? (int) $opts['agent_id'] : null,
				'summary'               => $plan['summary'] ?? array(),
			)
		);
		if ( $run_id instanceof \WP_Error ) {
			return $run_id;
		}
		$plan['summary'] = $this->materialize_plan_recommended_commands( (array) ( $plan['summary'] ?? array() ), $run_id );
		$updated         = $this->update_run_or_error($run_id, array( 'summary' => $plan['summary'] ), 'planned');
		if ( $updated instanceof \WP_Error ) {
			return $updated;
		}

		$inserted = $this->repository->add_items($run_id, $items);
		if ( $inserted instanceof \WP_Error ) {
			return $inserted;
		}

		$plan['run_id']          = $run_id;
		$plan['cleanup_storage'] = array(
			'type'         => 'database',
			'item_count'   => $inserted,
			'plan_id'      => $plan['plan_id'] ?? null,
			'escape_hatch' => 'filesystem apply-plan import remains available on lower-level worktree commands only',
		);

		return $plan;
	}

	/**
	 * Replace run-id placeholders in persisted plan command recommendations.
	 *
	 * @param  array<string,mixed> $summary Plan summary.
	 * @param  string              $run_id  Cleanup run ID.
	 * @return array<string,mixed>
	 */
	private function materialize_plan_recommended_commands( array $summary, string $run_id ): array {
		if ( isset($summary['apply_command']) ) {
			$summary['apply_command'] = str_replace('<run-id>', $run_id, (string) $summary['apply_command']);
		}
		$commands = array();
		foreach ( (array) ( $summary['recommended_commands'] ?? array() ) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$row['command'] = str_replace('<run-id>', $run_id, (string) ( $row['command'] ?? '' ));
			$commands[]     = $row;
		}
		$summary['recommended_commands'] = $commands;
		return $summary;
	}

	/**
	 * Apply pending rows from a DB-backed run.
	 *
	 * @param  string              $run_id Run ID.
	 * @param  array<string,mixed> $opts   Apply options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function apply( string $run_id, array $opts = array() ): array|\WP_Error {
		$run = $this->repository->get_run($run_id);
		if ( null === $run ) {
			return new \WP_Error('cleanup_run_not_found', sprintf('Cleanup run not found: %s', $run_id), array( 'status' => 404 ));
		}

		$limit = $this->apply_limit($opts);

		$updated = $this->update_run_or_error(
			$run_id, array(
				'status'     => 'applying',
				'started_at' => gmdate('Y-m-d H:i:s'),
			),
			'applying'
		);
		if ( $updated instanceof \WP_Error ) {
			return $updated;
		}

		$items                  = $this->repository->get_items($run_id);
		$policy                  = (array) ( $run['policy'] ?? array() );
		$force_artifact_cleanup = ! empty($policy['force_artifact_cleanup']);
		$artifact_rows          = $this->pending_rows_of_type($items, 'artifact_cleanup');
		$worktree_rows          = $this->pending_rows_of_type($items, 'worktree_removal');
		$stale_worktrees_only   = 'stale-worktrees' === (string) ( $run['mode'] ?? '' );
		$batch_type             = '';
		$processed_rows         = 0;
		$applied_rows           = 0;
		$skipped_rows           = 0;
		$remaining_rows         = max(0, count($artifact_rows) + count($worktree_rows));
		$results                = array();

		if ( array() !== $artifact_rows ) {
			$artifact_batch  = array_slice($artifact_rows, 0, $limit);
			$processed_rows += count($artifact_batch);
			$batch_type      = 'artifact_cleanup';
			$marked          = $this->mark_batch_applying($artifact_batch, $run_id, $batch_type, $limit, $remaining_rows);
			if ( $marked instanceof \WP_Error ) {
				return $marked;
			}
			$results['artifact_cleanup'] = $this->workspace->worktree_cleanup_artifacts(
				array(
					'apply_plan' => array( 'candidates' => array_map(fn( $item ) => $item['evidence'], $artifact_batch) ),
					// The reviewed run policy can force only reconstructable artifact deletion.
					'force'      => $force_artifact_cleanup,
					'limit'      => count($artifact_batch),
				)
			);
			if ( $results['artifact_cleanup'] instanceof \WP_Error ) {
				return $results['artifact_cleanup'];
			}
			$recorded = $this->record_apply_result($artifact_batch, $results['artifact_cleanup'], 'removed');
			if ( $recorded instanceof \WP_Error ) {
				return $recorded;
			}
			$applied_rows += count( (array) ( $results['artifact_cleanup']['removed'] ?? array() ) );
			$skipped_rows += count( (array) ( $results['artifact_cleanup']['skipped'] ?? array() ) );
		}

		$remaining_capacity = max(0, $limit - $processed_rows);
		if ( $remaining_capacity > 0 && array() !== $worktree_rows ) {
			$worktree_batch  = array_slice($worktree_rows, 0, $remaining_capacity);
			$processed_rows += count($worktree_batch);
			$batch_type      = '' === $batch_type ? 'worktree_removal' : 'mixed';
			$marked          = $this->mark_batch_applying($worktree_batch, $run_id, $batch_type, $limit, $remaining_rows);
			if ( $marked instanceof \WP_Error ) {
				return $marked;
			}
			$results['worktree_removal'] = $this->workspace->worktree_cleanup_merged(
				array(
					'apply_plan'          => array( 'candidates' => array_map(fn( $item ) => $item['evidence'], $worktree_batch) ),
					'direct_apply_plan'   => true,
					'skip_github'         => true,
					'stale_liveness_only' => $stale_worktrees_only,
				)
			);
			if ( $results['worktree_removal'] instanceof \WP_Error ) {
				return $results['worktree_removal'];
			}
			$recorded = $this->record_apply_result($worktree_batch, $results['worktree_removal'], 'removed');
			if ( $recorded instanceof \WP_Error ) {
				return $recorded;
			}
			$applied_rows += count( (array) ( $results['worktree_removal']['removed'] ?? array() ) );
			$skipped_rows += count( (array) ( $results['worktree_removal']['skipped'] ?? array() ) );
		}

		$status          = $this->status($run_id);
		$summary         = $status instanceof \WP_Error ? array() : ( $status['summary'] ?? array() );
		$pending_or_fail = (int) ( $summary['pending_or_failed'] ?? 0 );
		$next_status     = $pending_or_fail > 0 ? 'needs_resume' : 'completed';
		$completed_at    = 'completed' === $next_status ? gmdate('Y-m-d H:i:s') : null;

		$updated = $this->update_run_or_error(
			$run_id, array(
				'status'       => $next_status,
				'completed_at' => $completed_at,
				'summary'      => $summary,
			),
			$next_status
		);
		if ( $updated instanceof \WP_Error ) {
			return $updated;
		}

		$status = $this->status($run_id);
		if ( $status instanceof \WP_Error ) {
			return $status;
		}

		$next_command = $pending_or_fail > 0 ? $this->cleanup_run_resume_command($run_id, $limit, $force_artifact_cleanup) : null;

		return array(
			'success'                => true,
			'state'                  => $next_status,
			'run_id'                 => $run_id,
			'status'                 => $next_status,
			'processed'              => $processed_rows,
			'applied'                => $applied_rows,
			'skipped'                => $skipped_rows,
			'next_command'           => $next_command,
			'batch'                  => array(
				'type'             => $batch_type,
				'limit'            => $limit,
				'processed_rows'   => $processed_rows,
				'remaining_before' => $remaining_rows,
				'remaining_after'  => $pending_or_fail,
			),
			'results'                => $results,
			'summary'                => $status['summary'] ?? array(),
			'cleanup_items'          => $status['cleanup_items'] ?? array(),
			'remaining_work_summary' => $status['remaining_work_summary'] ?? array(),
			'next'                   => $pending_or_fail > 0 ? array(
				'resume_command' => $next_command,
				'pending_rows'   => $pending_or_fail,
			) : null,
		);
	}

	/**
	 * Return aggregate run status.
	 *
	 * @param  string $run_id Run ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function status( string $run_id ): array|\WP_Error {
		$run = $this->repository->get_run($run_id);
		if ( null === $run ) {
			return new \WP_Error('cleanup_run_not_found', sprintf('Cleanup run not found: %s', $run_id), array( 'status' => 404 ));
		}

		$items   = $this->repository->get_items($run_id);
		$summary = array(
			'total_items'       => count($items),
			'items_by_status'   => array(),
			'items_by_type'     => array(),
			'bytes_reclaimed'   => 0,
			'pending_or_failed' => 0,
		);
		foreach ( $items as $item ) {
			$status                                = (string) ( $item['status'] ?? 'unknown' );
			$type                                  = (string) ( $item['item_type'] ?? 'unknown' );
			$summary['items_by_status'][ $status ] = ( $summary['items_by_status'][ $status ] ?? 0 ) + 1;
			$summary['items_by_type'][ $type ]     = ( $summary['items_by_type'][ $type ] ?? 0 ) + 1;
			$summary['bytes_reclaimed']           += max(0, (int) ( $item['bytes_reclaimed'] ?? 0 ));
			if ( in_array($status, array( 'pending', 'failed', 'applying' ), true) ) {
				++$summary['pending_or_failed'];
			}
		}
		ksort($summary['items_by_status']);
		ksort($summary['items_by_type']);
		$this->apply_safe_cleanup_progress_summary($run, $summary);

		$terminal_safe_state = $this->terminal_empty_safe_cleanup_state($run, $summary);
		if ( null !== $terminal_safe_state ) {
			$completed_at = (string) ( $run['completed_at'] ?? '' );
			if ( '' === $completed_at ) {
				$completed_at = gmdate('Y-m-d H:i:s');
			}
			$updated = $this->update_run_or_error(
				$run_id,
				array(
					'status'       => $terminal_safe_state,
					'completed_at' => $completed_at,
					'summary'      => (array) ( $run['summary'] ?? array() ),
				),
				$terminal_safe_state
			);
			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
			$run['status']       = $terminal_safe_state;
			$run['completed_at'] = $completed_at;
		}

		$progress               = $this->run_progress($run, $items, $summary);
		$cleanup_items          = $this->cleanup_items_summary($run, $items, $summary);
		$remaining_work_summary = $this->remaining_work_summary($run, $run_id, $items, $progress);
		$this->apply_safe_cleanup_remaining_summary($run, $remaining_work_summary);

		return array(
			'success'                => true,
			'state'                  => (string) ( $run['status'] ?? 'unknown' ),
			'run_id'                 => $run_id,
			'status'                 => $run['status'] ?? 'unknown',
			'mode'                   => $run['mode'] ?? '',
			'run'                    => $run,
			'summary'                => $summary,
			'cleanup_items'          => $cleanup_items,
			'progress'               => $progress,
			'remaining_work_summary' => $remaining_work_summary,
		);
	}

	/**
	 * Return bounded evidence for a run.
	 *
	 * @param  string $run_id Run ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function evidence( string $run_id ): array|\WP_Error {
		$status = $this->status($run_id);
		if ( $status instanceof \WP_Error ) {
			return $status;
		}

		$status['items'] = $this->repository->get_items($run_id);
		return $status;
	}

	/**
	 * Mark pending rows cancelled.
	 *
	 * @param  string $run_id Run ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cancel( string $run_id ): array|\WP_Error {
		$items = $this->repository->get_items($run_id);
		foreach ( $items as $item ) {
			if ( 'pending' === (string) ( $item['status'] ?? '' ) ) {
				$this->repository->update_item( (int) $item['id'], array( 'status' => 'cancelled' ));
			}
		}
		$this->repository->update_run(
			$run_id, array(
				'status'       => 'cancelled',
				'completed_at' => gmdate('Y-m-d H:i:s'),
			)
		);
		return $this->status($run_id);
	}

	/**
	 * Resume by applying pending/failed rows.
	 *
	 * @param  string              $run_id Run ID.
	 * @param  array<string,mixed> $opts   Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resume( string $run_id, array $opts = array() ): array|\WP_Error {
		return $this->apply($run_id, $opts);
	}

	/**
	 * Repeatedly plan/apply artifact cleanup until no safe rows remain or progress stalls.
	 *
	 * @param  array<string,mixed> $opts Loop options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function until_empty( array $opts = array() ): array|\WP_Error {
		$mode = (string) ( $opts['mode'] ?? 'artifacts' );
		if ( 'artifacts' !== $mode ) {
			return new \WP_Error('cleanup_until_empty_unsupported_mode', 'Cleanup until-empty currently supports mode=artifacts only.', array( 'status' => 400 ));
		}

		$max_passes     = max(1, min(25, (int) ( $opts['max_passes'] ?? 10 )));
		$limit          = $this->apply_limit($opts);
		$started        = microtime(true);
		$budget_seconds = isset($opts['budget_seconds']) ? max(1, (int) $opts['budget_seconds']) : 0;
		$seen           = array();
		$passes         = array();
		$total_bytes    = 0;
		$total_applied  = 0;
		$total_skipped  = 0;

		for ( $pass = 1; $pass <= $max_passes; ++$pass ) {
			if ( $budget_seconds > 0 && microtime(true) - $started >= $budget_seconds ) {
				return $this->until_empty_result('budget_exhausted', $passes, $total_bytes, $total_applied, $total_skipped, array( 'budget_seconds' => $budget_seconds ));
			}

			$plan = $this->plan(
				array(
					'mode'                   => 'artifacts',
					'include_artifacts'      => true,
					'include_worktrees'      => false,
					'include_resolvers'      => false,
					'force_artifact_cleanup' => ! empty($opts['force']),
				)
			);
			if ( $plan instanceof \WP_Error ) {
				return $plan;
			}

			$rows        = (array) ( $plan['rows']['artifact_cleanup'] ?? array() );
			$fingerprint = $this->cleanup_rows_fingerprint($rows);
			if ( array() === $rows ) {
				return $this->until_empty_result('completed', $passes, $total_bytes, $total_applied, $total_skipped, array( 'final_run_id' => $plan['run_id'] ?? null ));
			}
			if ( isset($seen[ $fingerprint ]) ) {
				return $this->until_empty_result(
					'repeated_candidates',
					$passes,
					$total_bytes,
					$total_applied,
					$total_skipped,
					array(
						'run_id'      => $plan['run_id'] ?? null,
						'fingerprint' => $fingerprint,
						'reason'      => 'The same artifact candidate set appeared after a previous apply; stopping to avoid a cleanup loop.',
					)
				);
			}
			$seen[ $fingerprint ] = true;

			$run_id        = (string) ( $plan['run_id'] ?? '' );
			$run_applied   = 0;
			$run_skipped   = 0;
			$run_reclaimed = 0;
			$status        = null;
			do {
				$apply = $this->apply(
					$run_id,
					array(
						'force' => ! empty($opts['force']),
						'limit' => $limit,
					)
				);
				if ( $apply instanceof \WP_Error ) {
					return $apply;
				}
				$run_applied  += (int) ( $apply['applied'] ?? 0 );
				$run_skipped  += (int) ( $apply['skipped'] ?? 0 );
				$run_reclaimed = (int) ( $apply['summary']['bytes_reclaimed'] ?? $run_reclaimed );
				$status        = (string) ( $apply['status'] ?? '' );
			} while ( 'needs_resume' === $status );

			$total_applied += $run_applied;
			$total_skipped += $run_skipped;
			$total_bytes   += $run_reclaimed;
			$passes[]       = array(
				'pass'            => $pass,
				'run_id'          => $run_id,
				'planned_rows'    => count($rows),
				'applied'         => $run_applied,
				'skipped'         => $run_skipped,
				'bytes_reclaimed' => $run_reclaimed,
				'fingerprint'     => $fingerprint,
			);

			if ( 0 === $run_applied ) {
				$blocked_summary = (array) ( $apply['remaining_work_summary']['skipped_by_reason'] ?? array() );
				if ( $run_skipped > 0 && $this->artifact_cleanup_blocked_only($blocked_summary, $run_skipped) ) {
					return $this->until_empty_result(
						'complete_with_blockers',
						$passes,
						$total_bytes,
						$total_applied,
						$total_skipped,
						array(
							'run_id'                    => $run_id,
							'remaining_blocked_count'   => $run_skipped,
							'remaining_blocked_reasons' => $blocked_summary,
						)
					);
				}
				if ( ( $total_applied > 0 || $total_bytes > 0 ) && $run_skipped > 0 ) {
					return $this->until_empty_result(
						'completed_with_skips',
						$passes,
						$total_bytes,
						$total_applied,
						$total_skipped,
						array(
							'run_id'                    => $run_id,
							'remaining_blocked_count'   => $run_skipped,
							'remaining_blocked_reasons' => $blocked_summary,
						)
					);
				}

				return $this->until_empty_result('no_progress', $passes, $total_bytes, $total_applied, $total_skipped, array( 'run_id' => $run_id ));
			}
		}

		return $this->until_empty_result('max_passes_reached', $passes, $total_bytes, $total_applied, $total_skipped, array( 'max_passes' => $max_passes ));
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Cleanup rows.
	 */
	private function cleanup_rows_fingerprint( array $rows ): string {
		$parts = array();
		foreach ( $rows as $row ) {
			$artifacts = array();
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				if ( is_array($artifact) ) {
					$artifacts[] = (string) ( $artifact['path'] ?? '' );
				}
			}
			sort($artifacts);
			$parts[] = implode('|', array( (string) ( $row['handle'] ?? '' ), (string) ( $row['path'] ?? '' ), implode(',', $artifacts) ));
		}
		sort($parts);
		return sha1(implode("\n", $parts));
	}

	/**
	 * @param array<string,array<string,mixed>> $skipped_by_reason Skipped summary bucket.
	 */
	private function artifact_cleanup_blocked_only( array $skipped_by_reason, int $skipped_count ): bool {
		if ( $skipped_count <= 0 || array() === $skipped_by_reason ) {
			return false;
		}

		$blocked_reasons = array(
			'dirty_worktree'   => true,
			'unpushed_commits' => true,
		);
		$count           = 0;
		foreach ( $skipped_by_reason as $reason => $bucket ) {
			if ( ! isset($blocked_reasons[ (string) $reason ]) ) {
				return false;
			}
			$count += max(0, (int) ( $bucket['count'] ?? 0 ));
		}

		return $count >= $skipped_count;
	}

	/**
	 * @param array<int,array<string,mixed>> $passes Loop pass summaries.
	 * @param array<string,mixed>            $extra  Extra result fields.
	 */
	private function until_empty_result( string $state, array $passes, int $bytes, int $applied, int $skipped, array $extra = array() ): array {
		return array(
			'success'         => in_array($state, array( 'completed', 'completed_with_skips', 'complete_with_blockers', 'budget_exhausted', 'max_passes_reached' ), true),
			'state'           => $state,
			'status'          => $state,
			'passes'          => $passes,
			'pass_count'      => count($passes),
			'applied'         => $applied,
			'skipped'         => $skipped,
			'bytes_reclaimed' => $bytes,
		) + $extra;
	}

	private function plan_items( array $plan ): array {
		$items = array();
		foreach ( (array) ( $plan['rows'] ?? array() ) as $type => $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! is_array($row) ) {
					continue;
				}
				$items[] = array(
					'handle'         => (string) ( $row['handle'] ?? '' ),
					'item_type'      => (string) $type,
					'planned_action' => $this->planned_action_for_type( (string) $type),
					'reason_code'    => (string) ( $row['reason_code'] ?? '' ),
					'reason'         => (string) ( $row['reason'] ?? '' ),
					'evidence'       => $row,
					'status'         => 'resolver' === $type ? 'blocked' : 'pending',
				);
			}
		}
		return $items;
	}

	private function pending_rows_of_type( array $items, string $type ): array {
		return array_values(array_filter($items, fn( $item ) => (string) ( $item['item_type'] ?? '' ) === $type && in_array( (string) ( $item['status'] ?? '' ), array( 'pending', 'failed', 'applying' ), true)));
	}

	/**
	 * Mark rows as in-progress before invoking destructive cleanup so interrupted
	 * operator runs leave a visible, resumable checkpoint instead of silent state.
	 *
	 * @param array<int,array<string,mixed>> $items Batch rows.
	 * @param string                         $run_id Run ID.
	 * @param string                         $batch_type Batch type label.
	 * @param int                            $limit Requested apply limit.
	 * @param int                            $remaining_rows Rows remaining before this batch.
	 */
	private function mark_batch_applying( array $items, string $run_id, string $batch_type, int $limit, int $remaining_rows ): ?\WP_Error {
		$started_at = gmdate('Y-m-d H:i:s');
		foreach ( $items as $item ) {
			$updated = $this->update_item_or_error(
				(int) $item['id'],
				array(
					'status'   => 'applying',
					'evidence' => array_merge(
						(array) ( $item['evidence'] ?? array() ),
						array(
							'applying_started_at' => $started_at,
							'applying_batch_type' => $batch_type,
						)
					),
				),
				'applying'
			);
			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
		}

		$updated = $this->update_run_or_error(
			$run_id,
			array(
				'summary' => array(
					'applying_batch' => array(
						'type'             => $batch_type,
						'limit'            => $limit,
						'row_count'        => count($items),
						'remaining_before' => $remaining_rows,
						'started_at'       => $started_at,
					),
				),
			),
			'applying'
		);
		return $updated instanceof \WP_Error ? $updated : null;
	}

	/**
	 * Build operator progress metadata for status/evidence output.
	 *
	 * @param array<string,mixed>            $run Run row.
	 * @param array<int,array<string,mixed>> $items Item rows.
	 * @param array<string,mixed>            $summary Aggregate summary.
	 * @return array<string,mixed>
	 */
	private function run_progress( array $run, array $items, array $summary ): array {
		$applying = array_values(array_filter($items, fn( $item ) => 'applying' === (string) ( $item['status'] ?? '' )));
		$examples = array_slice(array_map(fn( $item ) => array(
			'handle' => (string) ( $item['handle'] ?? '' ),
			'type'   => (string) ( $item['item_type'] ?? '' ),
		), $applying), 0, 3);

		$started_at = (string) ( $run['started_at'] ?? '' );
		$age        = '' !== $started_at ? max(0, time() - strtotime($started_at)) : 0;
		$run_status = (string) ( $run['status'] ?? '' );
		$resumable  = (int) ( $summary['pending_or_failed'] ?? 0 ) > 0 && in_array($run_status, array( 'applying', 'needs_resume' ), true);

		return array(
			'applying_rows'     => count($applying),
			'applying_examples' => $examples,
			'pending_or_failed' => (int) ( $summary['pending_or_failed'] ?? 0 ),
			'safe_cleanup'      => $run['summary']['safe_cleanup_progress'] ?? null,
			'started_at'        => $started_at,
			'age_seconds'       => $age,
			'resumable'         => $resumable,
			'note'              => count($applying) > 0 ? 'Rows marked applying are safe to retry with workspace cleanup resume if the previous apply process was interrupted.' : '',
		);
	}

	/**
	 * Fold item-less safe cleanup progress into the same counters used by evidence summaries.
	 *
	 * @param array<string,mixed> $run     Run row.
	 * @param array<string,mixed> $summary Mutable status summary.
	 */
	private function apply_safe_cleanup_progress_summary( array $run, array &$summary ): void {
		$safe_summary = $this->safe_cleanup_summary($run);
		if ( array() === $safe_summary || (int) ( $summary['total_items'] ?? 0 ) > 0 ) {
			return;
		}

		$summary['removed']         = max(0, (int) ( $safe_summary['removed'] ?? 0 ));
		$summary['bytes_reclaimed'] = max( (int) ( $summary['bytes_reclaimed'] ?? 0 ), max(0, (int) ( $safe_summary['bytes_reclaimed'] ?? 0 )));
		$summary['would_remove']    = max(0, (int) ( $safe_summary['would_remove'] ?? 0 ));
		$summary['blocker_count']   = max(0, (int) ( $safe_summary['blocker_count'] ?? 0 ));
	}

	/**
	 * Build cleanup_items-compatible rollups for DB and safe-cleanup runs.
	 *
	 * @param array<string,mixed>            $run     Run row.
	 * @param array<int,array<string,mixed>> $items   Item rows.
	 * @param array<string,mixed>            $summary Status summary.
	 * @return array<string,mixed>
	 */
	private function cleanup_items_summary( array $run, array $items, array $summary ): array {
		$planned = count($items);
		$applied = (int) ( $summary['items_by_status']['applied'] ?? 0 );
		$bytes   = max(0, (int) ( $summary['bytes_reclaimed'] ?? 0 ));
		$by_type = array();

		foreach ( $items as $item ) {
			$type               = (string) ( $item['item_type'] ?? 'unknown' );
			$by_type[ $type ] ??= array(
				'planned_rows'    => 0,
				'applied_rows'    => 0,
				'skipped_rows'    => 0,
				'failed_rows'     => 0,
				'bytes_reclaimed' => 0,
			);
			++$by_type[ $type ]['planned_rows'];
			$status = (string) ( $item['status'] ?? '' );
			if ( 'applied' === $status ) {
				++$by_type[ $type ]['applied_rows'];
				$by_type[ $type ]['bytes_reclaimed'] += max(0, (int) ( $item['bytes_reclaimed'] ?? 0 ));
			} elseif ( 'skipped' === $status ) {
				++$by_type[ $type ]['skipped_rows'];
			} elseif ( 'failed' === $status ) {
				++$by_type[ $type ]['failed_rows'];
			}
		}

		$safe_summary = $this->safe_cleanup_summary($run);
		if ( array() === $items && array() !== $safe_summary ) {
			$applied = max(0, (int) ( $safe_summary['removed'] ?? 0 ));
			$planned = $applied;
			$bytes   = max(0, (int) ( $safe_summary['bytes_reclaimed'] ?? 0 ));
			$by_type = array(
				'safe_workspace_cleanup' => array(
					'planned_rows'    => $planned,
					'applied_rows'    => $applied,
					'skipped_rows'    => 0,
					'failed_rows'     => 0,
					'bytes_reclaimed' => $bytes,
				),
			);
		}

		return array(
			'planned_rows'    => $planned,
			'applied_rows'    => $applied,
			'skipped_rows'    => (int) ( $summary['items_by_status']['skipped'] ?? 0 ),
			'failed_rows'     => (int) ( $summary['items_by_status']['failed'] ?? 0 ),
			'bytes_reclaimed' => $bytes,
			'freed_human'     => $this->format_bytes($bytes),
			'by_type'         => $by_type,
		);
	}

	/**
	 * Fold safe cleanup progress into remaining-work summary totals used by --summary output.
	 *
	 * @param array<string,mixed> $run     Run row.
	 * @param array<string,mixed> $summary Mutable remaining-work summary.
	 */
	private function apply_safe_cleanup_remaining_summary( array $run, array &$summary ): void {
		$safe_summary = $this->safe_cleanup_summary($run);
		if ( array() === $safe_summary ) {
			return;
		}

		$removed = max(0, (int) ( $safe_summary['removed'] ?? 0 ));
		$bytes   = max(0, (int) ( $safe_summary['bytes_reclaimed'] ?? 0 ));
		if ( $removed <= 0 && $bytes <= 0 ) {
			return;
		}

		$summary['applied_by_type']['safe_workspace_cleanup'] = array(
			'count'           => $removed,
			'bytes_reclaimed' => $bytes,
		);
		$summary['total_bytes_reclaimed']                     = max( (int) ( $summary['total_bytes_reclaimed'] ?? 0 ), $bytes);
	}

	/**
	 * Return the persisted safe cleanup summary, if this is a safe cleanup run.
	 *
	 * @param array<string,mixed> $run Run row.
	 * @return array<string,mixed>
	 */
	private function safe_cleanup_summary( array $run ): array {
		if ( 'safe_workspace_cleanup' !== (string) ( $run['mode'] ?? '' ) ) {
			return array();
		}

		$safe_cleanup = (array) ( $run['summary']['safe_cleanup_progress'] ?? array() );
		$summary      = (array) ( $safe_cleanup['summary'] ?? array() );
		return array() === $summary ? array() : $summary;
	}

	private function format_bytes( int $bytes ): string {
		$bytes      = max(0, $bytes);
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$value      = (float) $bytes;
		$unit       = 0;
		$unit_count = count($units);
		while ( $value >= 1024 && $unit < $unit_count - 1 ) {
			$value /= 1024;
			++$unit;
		}

		return 0 === $unit ? sprintf('%d %s', $bytes, $units[ $unit ]) : sprintf('%.1f %s', $value, $units[ $unit ]);
	}

	private function terminal_empty_safe_cleanup_state( array $run, array $summary ): ?string {
		if ( 'safe_workspace_cleanup' !== (string) ( $run['mode'] ?? '' ) || 'applying' !== (string) ( $run['status'] ?? '' ) ) {
			return null;
		}
		if ( (int) ( $summary['total_items'] ?? 0 ) > 0 || (int) ( $summary['pending_or_failed'] ?? 0 ) > 0 ) {
			return null;
		}

		$safe_cleanup = (array) ( $run['summary']['safe_cleanup_progress'] ?? array() );
		if ( array() === $safe_cleanup ) {
			return null;
		}

		$state = (string) ( $safe_cleanup['state'] ?? '' );
		if ( in_array($state, array( 'complete', 'complete_with_blockers' ), true) ) {
			return $state;
		}

		$safe_summary  = (array) ( $safe_cleanup['summary'] ?? array() );
		$blocker_count = (int) ( $safe_summary['blocker_count'] ?? 0 );
		return $blocker_count > 0 ? 'complete_with_blockers' : 'complete';
	}

	/**
	 * Build remaining-work summary and prepend the current run resume command.
	 *
	 * @param string                         $run_id Run ID.
	 * @param array<int,array<string,mixed>> $items Item rows.
	 * @param array<string,mixed>            $progress Progress metadata.
	 * @return array<string,mixed>
	 */
	private function remaining_work_summary( array $run, string $run_id, array $items, array $progress ): array {
		$summary       = CleanupRemainingWorkSummary::from_items($items);
		$policy        = (array) ( $run['policy'] ?? array() );
		$safe_cleanup  = is_array($progress['safe_cleanup'] ?? null) ? (array) $progress['safe_cleanup'] : array();
		$safe_commands = is_array($safe_cleanup['commands'] ?? null) ? (array) $safe_cleanup['commands'] : array();
		if ( ! empty($progress['resumable']) && isset($safe_commands['status'], $safe_commands['resume']) ) {
			$resume_command = array(
				'bucket'            => 'safe_cleanup_continuation',
				'command'           => (string) $safe_commands['status'],
				'apply'             => (string) $safe_commands['resume'],
				'destructive'       => false,
				'apply_destructive' => true,
				'why'               => 'Inspect or continue the DMC safe cleanup run from durable progress evidence.',
			);
			array_unshift($summary['recommended_commands'], $resume_command);
			array_unshift($summary['next_commands'], (string) $resume_command['command'], (string) $resume_command['apply']);
			$summary['next_commands'] = array_values(array_unique($summary['next_commands']));
		}
		if ( ! empty($progress['resumable']) ) {
			$resume_command = array(
				'bucket'            => 'current_run_resume',
				'command'           => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
				'apply'             => $this->cleanup_run_resume_command($run_id, self::DEFAULT_APPLY_LIMIT, ! empty($policy['force_artifact_cleanup'])),
				'destructive'       => false,
				'apply_destructive' => true,
				'why'               => 'Resume the reviewed DB-backed cleanup run from persisted pending/failed/applying rows.',
			);
			array_unshift(
				$summary['recommended_commands'],
				$resume_command
			);
			array_unshift($summary['next_commands'], (string) $resume_command['command'], (string) $resume_command['apply']);
			$summary['next_commands'] = array_values(array_unique($summary['next_commands']));
		}

		return $summary;
	}

	private function cleanup_run_resume_command( string $run_id, int $limit, bool $force_artifact_cleanup ): string {
		return sprintf('studio wp datamachine-code workspace cleanup resume %s --limit=%d', $run_id, $limit) . ( $force_artifact_cleanup ? ' --force' : '' );
	}

	private function apply_limit( array $opts ): int {
		$limit = isset($opts['limit']) ? (int) $opts['limit'] : self::DEFAULT_APPLY_LIMIT;
		return max(1, min(self::MAX_APPLY_LIMIT, $limit));
	}

	private function record_apply_result( array $items, mixed $result, string $applied_key ): ?\WP_Error {
		if ( $result instanceof \WP_Error ) {
			foreach ( $items as $item ) {
				$updated = $this->update_item_or_error(
					(int) $item['id'], array(
						'status'      => 'failed',
						'reason_code' => $result->get_error_code(),
						'reason'      => $result->get_error_message(),
					),
					'failed'
				);
				if ( $updated instanceof \WP_Error ) {
					return $updated;
				}
			}
			return null;
		}

		$applied_by_handle = array();
		foreach ( (array) ( $result[ $applied_key ] ?? array() ) as $row ) {
			$applied_by_handle[ (string) ( $row['handle'] ?? '' ) ] = is_array($row) ? $row : array();
		}
		$skipped_by_handle = array();
		foreach ( (array) ( $result['skipped'] ?? array() ) as $skip ) {
			$skipped_by_handle[ (string) ( $skip['handle'] ?? '' ) ] = $skip;
		}

		foreach ( $items as $item ) {
			$handle = (string) ( $item['handle'] ?? '' );
			if ( isset($applied_by_handle[ $handle ]) ) {
				$applied = $applied_by_handle[ $handle ];
				$updated = $this->update_item_or_error(
					(int) $item['id'],
					array(
						'status'          => 'applied',
						'applied_at'      => gmdate('Y-m-d H:i:s'),
						'bytes_reclaimed' => max(0, (int) ( $applied['artifact_size_bytes'] ?? $applied['size_bytes'] ?? $item['evidence']['artifact_size_bytes'] ?? $item['evidence']['size_bytes'] ?? 0 )),
						'evidence'        => array_merge( (array) $item['evidence'], array( 'applied' => $applied )),
					),
					'applied'
				);
				if ( $updated instanceof \WP_Error ) {
					return $updated;
				}
				continue;
			}
			$skip    = $skipped_by_handle[ $handle ] ?? null;
			$updated = $this->update_item_or_error(
				(int) $item['id'],
				array(
					'status'      => 'skipped',
					'reason_code' => is_array($skip) ? (string) ( $skip['reason_code'] ?? 'apply_skipped' ) : 'apply_skipped',
					'reason'      => is_array($skip) ? (string) ( $skip['reason'] ?? '' ) : 'row was not applied',
					'evidence'    => is_array($skip) ? $skip : $item['evidence'],
				),
				'skipped'
			);
			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $fields Run fields.
	 */
	private function update_run_or_error( string $run_id, array $fields, string $state ): ?\WP_Error {
		if ( $this->repository->update_run($run_id, $fields) ) {
			return null;
		}

		return new \WP_Error(
			'cleanup_run_update_failed',
			sprintf('Failed to persist cleanup run %s state for run %s.', $state, $run_id),
			array(
				'status' => 500,
				'run_id' => $run_id,
				'state'  => $state,
			)
		);
	}

	/**
	 * @param array<string,mixed> $fields Item fields.
	 */
	private function update_item_or_error( int $item_id, array $fields, string $state ): ?\WP_Error {
		if ( $this->repository->update_item($item_id, $fields) ) {
			return null;
		}

		return new \WP_Error(
			'cleanup_item_update_failed',
			sprintf('Failed to persist cleanup item %s state for item %d.', $state, $item_id),
			array(
				'status'  => 500,
				'item_id' => $item_id,
				'state'   => $state,
			)
		);
	}

	private function planned_action_for_type( string $type ): string {
		return match ( $type ) {
			'artifact_cleanup' => 'remove_artifacts',
			'worktree_removal' => 'remove_worktree',
			'resolver'         => 'resolve_signal',
			default            => 'review',
		};
	}
}
