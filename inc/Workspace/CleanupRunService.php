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

		$this->repository->update_run(
			$run_id, array(
				'status'     => 'applying',
				'started_at' => gmdate('Y-m-d H:i:s'),
			)
		);

		$items          = $this->repository->get_items($run_id);
		$artifact_rows  = $this->pending_rows_of_type($items, 'artifact_cleanup');
		$worktree_rows  = $this->pending_rows_of_type($items, 'worktree_removal');
		$batch_type     = '';
		$processed_rows = 0;
		$remaining_rows = max(0, count($artifact_rows) + count($worktree_rows));
		$results        = array();

		if ( array() !== $artifact_rows ) {
			$artifact_batch = array_slice($artifact_rows, 0, $limit);
			$processed_rows += count($artifact_batch);
			$batch_type      = 'artifact_cleanup';
			$results['artifact_cleanup'] = $this->workspace->worktree_cleanup_artifacts(
				array(
					'apply_plan' => array( 'candidates' => array_map(fn( $item ) => $item['evidence'], $artifact_batch) ),
					'force'      => ! empty($opts['force']),
					'limit'      => count($artifact_batch),
				)
			);
			$this->record_apply_result($artifact_batch, $results['artifact_cleanup'], 'removed');
		}

		$remaining_capacity = max(0, $limit - $processed_rows);
		if ( $remaining_capacity > 0 && array() !== $worktree_rows ) {
			$worktree_batch = array_slice($worktree_rows, 0, $remaining_capacity);
			$processed_rows += count($worktree_batch);
			$batch_type      = '' === $batch_type ? 'worktree_removal' : 'mixed';
			$results['worktree_removal'] = $this->workspace->worktree_cleanup_merged(
				array(
					'apply_plan'  => array( 'candidates' => array_map(fn( $item ) => $item['evidence'], $worktree_batch) ),
					'skip_github' => true,
				)
			);
			$this->record_apply_result($worktree_batch, $results['worktree_removal'], 'removed');
		}

		$status          = $this->status($run_id);
		$summary         = $status instanceof \WP_Error ? array() : ( $status['summary'] ?? array() );
		$pending_or_fail = (int) ( $summary['pending_or_failed'] ?? 0 );
		$next_status     = $pending_or_fail > 0 ? 'needs_resume' : 'completed';
		$completed_at    = 'completed' === $next_status ? gmdate('Y-m-d H:i:s') : null;

		$this->repository->update_run(
			$run_id, array(
				'status'       => $next_status,
				'completed_at' => $completed_at,
				'summary'      => $summary,
			)
		);

		$status = $this->status($run_id);
		if ( $status instanceof \WP_Error ) {
			return $status;
		}

		return array(
			'success' => true,
			'state'   => $next_status,
			'run_id'  => $run_id,
			'status'  => $next_status,
			'batch'   => array(
				'type'             => $batch_type,
				'limit'            => $limit,
				'processed_rows'   => $processed_rows,
				'remaining_before' => $remaining_rows,
				'remaining_after'  => $pending_or_fail,
			),
			'results'                => $results,
			'summary'                => $status['summary'] ?? array(),
			'remaining_work_summary' => $status['remaining_work_summary'] ?? array(),
			'next'                   => $pending_or_fail > 0 ? array(
				'resume_command' => sprintf('studio wp datamachine-code workspace cleanup resume %s --limit=%d', $run_id, $limit),
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
			if ( in_array($status, array( 'pending', 'failed' ), true) ) {
				++$summary['pending_or_failed'];
			}
		}
		ksort($summary['items_by_status']);
		ksort($summary['items_by_type']);

		return array(
			'success'                => true,
			'state'                  => (string) ( $run['status'] ?? 'unknown' ),
			'run_id'                 => $run_id,
			'status'                 => $run['status'] ?? 'unknown',
			'mode'                   => $run['mode'] ?? '',
			'run'                    => $run,
			'summary'                => $summary,
			'remaining_work_summary' => CleanupRemainingWorkSummary::from_items($items),
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
		return array_values(array_filter($items, fn( $item ) => (string) ( $item['item_type'] ?? '' ) === $type && in_array( (string) ( $item['status'] ?? '' ), array( 'pending', 'failed' ), true)));
	}

	private function apply_limit( array $opts ): int {
		$limit = isset($opts['limit']) ? (int) $opts['limit'] : self::DEFAULT_APPLY_LIMIT;
		return max(1, min(self::MAX_APPLY_LIMIT, $limit));
	}

	private function record_apply_result( array $items, mixed $result, string $applied_key ): void {
		if ( $result instanceof \WP_Error ) {
			foreach ( $items as $item ) {
				$this->repository->update_item(
					(int) $item['id'], array(
						'status'      => 'failed',
						'reason_code' => $result->get_error_code(),
						'reason'      => $result->get_error_message(),
					)
				);
			}
			return;
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
				$this->repository->update_item(
					(int) $item['id'],
					array(
						'status'          => 'applied',
						'applied_at'      => gmdate('Y-m-d H:i:s'),
						'bytes_reclaimed' => max(0, (int) ( $applied['artifact_size_bytes'] ?? $applied['size_bytes'] ?? $item['evidence']['artifact_size_bytes'] ?? $item['evidence']['size_bytes'] ?? 0 )),
						'evidence'        => array_merge( (array) $item['evidence'], array( 'applied' => $applied )),
					)
				);
				continue;
			}
			$skip = $skipped_by_handle[ $handle ] ?? null;
			$this->repository->update_item(
				(int) $item['id'],
				array(
					'status'      => 'skipped',
					'reason_code' => is_array($skip) ? (string) ( $skip['reason_code'] ?? 'apply_skipped' ) : 'apply_skipped',
					'reason'      => is_array($skip) ? (string) ( $skip['reason'] ?? '' ) : 'row was not applied',
					'evidence'    => is_array($skip) ? $skip : $item['evidence'],
				)
			);
		}
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
