<?php
/**
 * Cleanup run evidence store backed by Data Machine job records.
 *
 * @package DataMachineCode\Cleanup
 */

namespace DataMachineCode\Cleanup;

defined('ABSPATH') || exit;

class DataMachineJobCleanupRunEvidenceStore implements CleanupRunEvidenceStoreInterface {



	/**
	 * Read one cleanup run.
	 *
	 * @param  string $run_id           Stable cleanup run identifier.
	 * @param  bool   $include_evidence Whether to include raw evidence records.
	 * @param  bool   $include_details  Whether to include verbose diagnostic details.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error {
		$job_id = $this->cleanup_run_job_id($run_id);
		if ( $job_id <= 0 ) {
			return new \WP_Error('invalid_cleanup_run_id', 'Cleanup run id must be a numeric job id or cleanup-run-<job_id>.', array( 'status' => 400 ));
		}

		$job = $this->get_cleanup_run_job($job_id);
		if ( array() === $job ) {
			return new \WP_Error('cleanup_run_not_found', sprintf('Cleanup run not found: %s', $this->cleanup_run_id($job_id)), array( 'status' => 404 ));
		}

		$engine_data   = $this->normalize_engine_data($job['engine_data'] ?? array());
		$parent_result = $this->extract_system_task_result($engine_data);
		$child_jobs    = $this->get_cleanup_run_descendant_jobs($job_id);
		$aggregate     = $this->aggregate_cleanup_child_jobs($child_jobs);
		$children      = $aggregate['children'];
		$state         = $this->cleanup_run_state( (string) ( $job['status'] ?? '' ), $children);

		$children_for_output = ( $include_evidence || $include_details ) ? $children : $this->summarize_cleanup_children($children);
		$output              = array(
			'success'                => true,
			'state'                  => $state,
			'run_id'                 => $this->cleanup_run_id($job_id),
			'job_id'                 => $job_id,
			'status'                 => in_array($state, array( 'children_processing', 'partial_failed' ), true) ? $state : ( $job['status'] ?? '' ),
			'created_at'             => $job['created_at'] ?? '',
			'parent_completed_at'    => $job['completed_at'] ?? '',
			'artifact_cleanup'       => $aggregate['artifact_cleanup'],
			'cleanup_items'          => $aggregate['cleanup_items'],
			'remaining_work_summary' => CleanupRemainingWorkSummary::from_job_aggregate($aggregate),
			'drain'                  => $this->cleanup_run_drain_summary($job_id, $state, $children, $aggregate),
			'children'               => $children_for_output,
		);

		$output_aggregate             = $aggregate;
		$output_aggregate['children'] = $children_for_output;

		if ( array() !== $parent_result ) {
			$engine_data['system_task_result'] = $this->with_cleanup_aggregate_report($parent_result, $output_aggregate);
		}

		if ( $include_evidence ) {
			$output['evidence'] = array(
				'storage'          => array(
					'source'            => 'datamachine_jobs',
					'filesystem_plans'  => false,
					'schema_dependency' => 'datamachine_code_cleanup_runs and datamachine_code_cleanup_items from issue #244 become the canonical store when available.',
				),
				'artifact_cleanup' => $aggregate['artifact_cleanup'],
				'cleanup_items'    => $aggregate['cleanup_items'],
				'children'         => $children,
				'engine_data'      => $engine_data,
				'job'              => $job,
				'child_jobs'       => $child_jobs,
			);
		} else {
			$output['mode'] = $engine_data['cleanup_run']['mode'] ?? '';
			if ( isset($engine_data['system_task_result']) ) {
				$output['system_task_result'] = $engine_data['system_task_result'];
			}
		}

		return $output;
	}

	/**
	 * Aggregate cleanup child job results for status/evidence output.
	 *
	 * @param  array<int,array<string,mixed>> $child_jobs Descendant jobs.
	 * @return array{artifact_cleanup:array<string,mixed>,cleanup_items:array<string,mixed>,children:array<string,mixed>}
	 */
	private function aggregate_cleanup_child_jobs( array $child_jobs ): array {
		$summary = array(
			'artifact_cleanup' => array(
				'planned_rows'                         => 0,
				'applied_rows'                         => 0,
				'skipped_rows'                         => 0,
				'failed_rows'                          => 0,
				'bytes_reclaimed'                      => 0,
				'freed_human'                          => $this->format_bytes(0),
				'skipped_by_reason'                    => array(),
				'failed_by_reason'                     => array(),
				'skipped_examples_by_reason'           => array(),
				'failed_examples_by_reason'            => array(),
				'remaining_reclaimable_artifact_bytes' => 0,
				'remaining_safely_removable_worktrees' => 0,
			),
			'cleanup_items'    => array(
				'planned_rows'                         => 0,
				'applied_rows'                         => 0,
				'skipped_rows'                         => 0,
				'failed_rows'                          => 0,
				'bytes_reclaimed'                      => 0,
				'freed_human'                          => $this->format_bytes(0),
				'by_type'                              => array(),
				'skipped_by_reason'                    => array(),
				'failed_by_reason'                     => array(),
				'skipped_examples_by_reason'           => array(),
				'failed_examples_by_reason'            => array(),
				'remaining_reclaimable_artifact_bytes' => 0,
				'remaining_safely_removable_worktrees' => 0,
			),
			'children'         => array(
				'batch_job_ids'      => array(),
				'chunk_job_ids'      => array(),
				'pending_job_ids'    => array(),
				'processing_job_ids' => array(),
				'failed_job_ids'     => array(),
				'processing'         => 0,
				'completed'          => 0,
				'failed'             => 0,
				'running'            => 0,
				'total'              => 0,
				'statuses'           => array(),
				'job_ids'            => array(),
			),
		);

		foreach ( $child_jobs as $child ) {
			$child_job_id = (int) ( $child['job_id'] ?? 0 );
			$status       = (string) ( $child['status'] ?? '' );
			$engine_data  = $this->normalize_engine_data($child['engine_data'] ?? array());
			$result       = $this->extract_system_task_result($engine_data);

			++$summary['children']['total'];
			if ( $child_job_id > 0 ) {
				$summary['children']['job_ids'][] = $child_job_id;
				if ( 'pending' === $status ) {
					$summary['children']['pending_job_ids'][] = $child_job_id;
				} elseif ( 'processing' === $status ) {
					$summary['children']['processing_job_ids'][] = $child_job_id;
				} elseif ( str_starts_with($status, 'failed') ) {
					$summary['children']['failed_job_ids'][] = $child_job_id;
				}
			}

			$this->count_cleanup_child_status($summary['children'], $status);
			if ( isset($summary['children']['statuses'][ $status ]) ) {
				++$summary['children']['statuses'][ $status ];
			} elseif ( '' !== $status ) {
				$summary['children']['statuses'][ $status ] = 1;
			}

			if ( 'batch' === (string) ( $child['source'] ?? '' ) || isset($engine_data['batch_id']) ) {
				if ( $child_job_id > 0 ) {
					$summary['children']['batch_job_ids'][] = $child_job_id;
				}
				continue;
			}

			if ( 'worktree_cleanup_chunk' !== (string) ( $engine_data['task_type'] ?? '' ) && ! isset($result['chunk_type']) ) {
				continue;
			}
			if ( array() === $result ) {
				continue;
			}

			if ( $child_job_id > 0 ) {
				$summary['children']['chunk_job_ids'][] = $child_job_id;
			}

			$this->merge_cleanup_item_result($summary['cleanup_items'], $result);
			if ( in_array( (string) ( $result['chunk_type'] ?? '' ), array( 'artifacts', 'artifact_discovery' ), true) ) {
				$this->merge_cleanup_item_result($summary['artifact_cleanup'], $result);
			}
		}

		$summary['artifact_cleanup']['freed_human'] = $this->format_bytes($summary['artifact_cleanup']['bytes_reclaimed']);
		$summary['cleanup_items']['freed_human']    = $this->format_bytes($summary['cleanup_items']['bytes_reclaimed']);
		$summary['children']['batch_job_ids']       = array_values(array_unique($summary['children']['batch_job_ids']));
		$summary['children']['chunk_job_ids']       = array_values(array_unique($summary['children']['chunk_job_ids']));
		$summary['children']['pending_job_ids']     = array_values(array_unique($summary['children']['pending_job_ids']));
		$summary['children']['processing_job_ids']  = array_values(array_unique($summary['children']['processing_job_ids']));
		$summary['children']['failed_job_ids']      = array_values(array_unique($summary['children']['failed_job_ids']));
		$summary['children']['job_ids']             = array_values(array_unique($summary['children']['job_ids']));
		$summary['children']['running']             = (int) $summary['children']['processing'];

		return $summary;
	}

	/**
	 * Return operator-focused child status without unbounded diagnostic ID lists.
	 *
	 * @param  array<string,mixed> $children Full child aggregate.
	 * @return array<string,mixed>
	 */
	private function summarize_cleanup_children( array $children ): array {
		$limit      = 10;
		$batch_ids  = (array) ( $children['batch_job_ids'] ?? array() );
		$chunk_ids  = (array) ( $children['chunk_job_ids'] ?? array() );
		$pending    = (array) ( $children['pending_job_ids'] ?? array() );
		$processing = (array) ( $children['processing_job_ids'] ?? array() );

		return array(
			'processing'              => (int) ( $children['processing'] ?? 0 ),
			'completed'               => (int) ( $children['completed'] ?? 0 ),
			'failed'                  => (int) ( $children['failed'] ?? 0 ),
			'running'                 => (int) ( $children['running'] ?? 0 ),
			'total'                   => (int) ( $children['total'] ?? 0 ),
			'statuses'                => (array) ( $children['statuses'] ?? array() ),
			'batch_total'             => count($batch_ids),
			'chunk_total'             => count($chunk_ids),
			'failed_job_ids'          => (array) ( $children['failed_job_ids'] ?? array() ),
			'pending_job_ids'         => array_slice($pending, 0, $limit),
			'processing_job_ids'      => array_slice($processing, 0, $limit),
			'pending_truncated'       => count($pending) > $limit,
			'processing_truncated'    => count($processing) > $limit,
			'diagnostic_job_id_lists' => 'Re-run status with --verbose or use cleanup evidence for full child job IDs.',
		);
	}

	/**
	 * Build exact Data Machine drain and verification commands for a cleanup run.
	 *
	 * @param  int    $job_id    Parent cleanup job ID.
	 * @param  string $state     Computed cleanup state.
	 * @param  array  $children  Full child job aggregate.
	 * @param  array  $aggregate Full cleanup aggregate.
	 * @return array<string,mixed>
	 */
	private function cleanup_run_drain_summary( int $job_id, string $state, array $children, array $aggregate ): array {
		$active_child_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						'intval',
						array_merge(
							(array) ( $children['pending_job_ids'] ?? array() ),
							(array) ( $children['processing_job_ids'] ?? array() )
						)
					)
				)
			)
		);
		$run_id        = $this->cleanup_run_id($job_id);
		$cleanup_items = (array) ( $aggregate['cleanup_items'] ?? array() );
		$commands      = array(
			'parent' => sprintf('studio wp datamachine drain --job-id=%d', $job_id),
			'verify' => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
		);
		if ( array() !== $active_child_ids ) {
			$commands['active_children'] = sprintf('studio wp datamachine drain --job-id=%s', implode(',', $active_child_ids));
		}

		return array(
			'needed'               => in_array($state, array( 'running', 'children_processing' ), true),
			'commands'             => $commands,
			'active_child_job_ids' => $active_child_ids,
			'bytes_reclaimed'      => (int) ( $cleanup_items['bytes_reclaimed'] ?? 0 ),
			'freed_human'          => (string) ( $cleanup_items['freed_human'] ?? $this->format_bytes(0) ),
		);
	}

	/**
	 * Merge one chunk task result into aggregate cleanup item counters.
	 *
	 * @param  array $summary Cleanup item summary.
	 * @param  array $result  Chunk task result.
	 * @return void
	 */
	private function merge_cleanup_item_result( array &$summary, array $result ): void {
		$chunk_type = (string) ( $result['chunk_type'] ?? 'unknown' );
		if ( ! isset($summary['by_type']) ) {
			$summary['by_type'] = array();
		}
		if ( ! isset($summary['by_type'][ $chunk_type ]) ) {
			$summary['by_type'][ $chunk_type ] = array(
				'planned_rows'    => 0,
				'applied_rows'    => 0,
				'skipped_rows'    => 0,
				'failed_rows'     => 0,
				'bytes_reclaimed' => 0,
			);
		}

		$planned         = max(0, (int) ( $result['planned_count'] ?? 0 ));
		$applied         = max(0, (int) ( $result['applied_count'] ?? 0 ));
		$skipped         = max(0, (int) ( $result['skipped_count'] ?? 0 ));
		$failed          = max(0, (int) ( $result['failed_count'] ?? 0 ));
		$bytes_reclaimed = max(0, (int) ( $result['bytes_reclaimed'] ?? 0 ));

		$summary['planned_rows']    += $planned;
		$summary['applied_rows']    += $applied;
		$summary['skipped_rows']    += $skipped;
		$summary['failed_rows']     += $failed;
		$summary['bytes_reclaimed'] += $bytes_reclaimed;

		$summary['by_type'][ $chunk_type ]['planned_rows']    += $planned;
		$summary['by_type'][ $chunk_type ]['applied_rows']    += $applied;
		$summary['by_type'][ $chunk_type ]['skipped_rows']    += $skipped;
		$summary['by_type'][ $chunk_type ]['failed_rows']     += $failed;
		$summary['by_type'][ $chunk_type ]['bytes_reclaimed'] += $bytes_reclaimed;

		$skipped_rows = (array) ( $result['skipped'] ?? array() );
		$failed_rows  = (array) ( $result['failed'] ?? array() );
		$this->merge_cleanup_reason_counts($summary['skipped_by_reason'], $skipped_rows);
		$this->merge_cleanup_reason_counts($summary['failed_by_reason'], $failed_rows);
		$this->merge_cleanup_reason_examples($summary['skipped_examples_by_reason'], $skipped_rows);
		$this->merge_cleanup_reason_examples($summary['failed_examples_by_reason'], $failed_rows);

		if ( in_array($chunk_type, array( 'artifacts', 'artifact_discovery' ), true) ) {
			$summary['remaining_reclaimable_artifact_bytes'] += $this->sum_cleanup_rows_bytes(array_merge($skipped_rows, $failed_rows), array( 'artifact_size_bytes', 'size_bytes' ));
		}
		if ( 'worktrees' === $chunk_type ) {
			$summary['remaining_safely_removable_worktrees'] += $failed;
		}
	}

	/**
	 * Replace placeholder parent report fields with child aggregate totals.
	 *
	 * @param  array $result    Parent system task result.
	 * @param  array $aggregate Child aggregate.
	 * @return array<string,mixed>
	 */
	private function with_cleanup_aggregate_report( array $result, array $aggregate ): array {
		$cleanup_items = (array) ( $aggregate['cleanup_items'] ?? array() );
		if ( ! empty($result['job_backed']) && isset($result['report']) && is_array($result['report']) ) {
			$result['report']['bytes_reclaimed'] = (int) ( $cleanup_items['bytes_reclaimed'] ?? 0 );
			$result['report']['freed_human']     = (string) ( $cleanup_items['freed_human'] ?? $this->format_bytes(0) );
		}

		$result['artifact_cleanup'] = (array) ( $aggregate['artifact_cleanup'] ?? array() );
		$result['cleanup_items']    = $cleanup_items;
		$result['children']         = (array) ( $aggregate['children'] ?? array() );

		return $result;
	}

	/**
	 * Fetch the parent cleanup run job.
	 *
	 * @param  int $job_id Job ID.
	 * @return array<string,mixed>
	 */
	private function get_cleanup_run_job( int $job_id ): array {
		$ability = wp_get_ability('datamachine/get-jobs');
		if ( ! $ability ) {
			return array();
		}

		$result = $ability->execute(array( 'job_id' => $job_id ));
		if ( ! ( $result['success'] ?? false ) ) {
			return array();
		}

		$jobs = $result['jobs'] ?? array();
		return is_array($jobs) && ! empty($jobs[0]) && is_array($jobs[0]) ? $jobs[0] : array();
	}

	/**
	 * Return every job linked below a cleanup run job.
	 *
	 * @param  int             $job_id Parent job ID.
	 * @param  array<int,bool> $seen   Seen job IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_cleanup_run_descendant_jobs( int $job_id, array &$seen = array() ): array {
		$ability = wp_get_ability('datamachine/get-jobs');
		if ( ! $ability || isset($seen[ $job_id ]) ) {
			return array();
		}

		$seen[ $job_id ] = true;
		$children        = array();
		$offset          = 0;
		$per_page        = 100;

		do {
			$result = $ability->execute(
				array(
					'parent_job_id' => $job_id,
					'per_page'      => $per_page,
					'offset'        => $offset,
					'orderby'       => 'j.job_id',
					'order'         => 'ASC',
				)
			);
			if ( ! ( $result['success'] ?? false ) ) {
				break;
			}

			$page = is_array($result['jobs'] ?? null) ? $result['jobs'] : array();
			foreach ( $page as $child ) {
				if ( ! is_array($child) ) {
					continue;
				}
				$child_id = (int) ( $child['job_id'] ?? 0 );
				if ( $child_id <= 0 || isset($seen[ $child_id ]) ) {
					continue;
				}

				$children[] = $child;
				$children   = array_merge($children, $this->get_cleanup_run_descendant_jobs($child_id, $seen));
			}

			$offset += $per_page;
			$total   = (int) ( $result['total'] ?? count($page) );
		} while ( $offset < $total && ! empty($page) );

		return $children;
	}

	/**
	 * Normalize engine_data stored as either decoded array or JSON string.
	 *
	 * @param  mixed $engine_data Engine data.
	 * @return array<string,mixed>
	 */
	private function normalize_engine_data( mixed $engine_data ): array {
		if ( is_array($engine_data) ) {
			return $engine_data;
		}
		if ( is_string($engine_data) && '' !== trim($engine_data) ) {
			$decoded = json_decode($engine_data, true);
			return is_array($decoded) ? $decoded : array();
		}

		return array();
	}

	/**
	 * Extract a task result from either nested packets or direct task engine data.
	 *
	 * @param  array $engine_data Job engine data.
	 * @return array<string,mixed>
	 */
	private function extract_system_task_result( array $engine_data ): array {
		if ( isset($engine_data['system_task_result']) && is_array($engine_data['system_task_result']) ) {
			return $engine_data['system_task_result'];
		}

		foreach ( array( 'chunk_type', 'planned_count', 'applied_count', 'skipped_count', 'failed_count', 'bytes_reclaimed', 'report', 'job_backed' ) as $key ) {
			if ( array_key_exists($key, $engine_data) ) {
				return $engine_data;
			}
		}

		return array();
	}

	/**
	 * Count a child job status into stable status buckets.
	 *
	 * @param  array  $children Child aggregate.
	 * @param  string $status   Job status.
	 * @return void
	 */
	private function count_cleanup_child_status( array &$children, string $status ): void {
		if ( in_array($status, array( 'pending', 'processing' ), true) ) {
			++$children['processing'];
			return;
		}
		if ( str_starts_with($status, 'failed') ) {
			++$children['failed'];
			return;
		}
		if ( str_starts_with($status, 'completed') ) {
			++$children['completed'];
		}
	}

	/**
	 * Merge skipped/failed reason counts from chunk rows.
	 *
	 * @param  array $counts Reason counts.
	 * @param  array $rows   Rows with reason_code/reason.
	 * @return void
	 */
	private function merge_cleanup_reason_counts( array &$counts, array $rows ): void {
		foreach ( $rows as $row ) {
			$reason = is_array($row) ? (string) ( $row['reason_code'] ?? $row['reason'] ?? 'unknown' ) : 'unknown';
			if ( '' === $reason ) {
				$reason = 'unknown';
			}
			$counts[ $reason ] = (int) ( $counts[ $reason ] ?? 0 ) + 1;
		}
	}

	/**
	 * Merge bounded examples by cleanup reason.
	 *
	 * @param  array $examples Reason examples.
	 * @param  array $rows     Rows with reason_code/reason.
	 * @return void
	 */
	private function merge_cleanup_reason_examples( array &$examples, array $rows ): void {
		$limit = 3;
		foreach ( $rows as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$reason = (string) ( $row['reason_code'] ?? $row['reason'] ?? 'unknown' );
			if ( '' === $reason ) {
				$reason = 'unknown';
			}
			$examples[ $reason ] ??= array(
				'count'    => 0,
				'examples' => array(),
			);
			++$examples[ $reason ]['count'];
			if ( count($examples[ $reason ]['examples']) >= $limit ) {
				continue;
			}
			$examples[ $reason ]['examples'][] = array_filter(
				array(
					'handle' => (string) ( $row['handle'] ?? '' ),
					'repo'   => (string) ( $row['repo'] ?? '' ),
					'branch' => (string) ( $row['branch'] ?? '' ),
					'reason' => (string) ( $row['reason'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Sum byte fields across cleanup rows.
	 *
	 * @param  array<int,array<string,mixed>> $rows   Rows.
	 * @param  array<int,string>              $fields Byte field preference order.
	 * @return int
	 */
	private function sum_cleanup_rows_bytes( array $rows, array $fields ): int {
		$total = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$found = false;
			foreach ( $fields as $field ) {
				if ( isset($row[ $field ]) ) {
					$total += max(0, (int) $row[ $field ]);
					$found  = true;
					break;
				}
			}
			if ( $found ) {
				continue;
			}
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$total += max(0, (int) ( is_array($artifact) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ));
			}
		}
		return $total;
	}

	/**
	 * Compute aggregate cleanup run state from parent and child jobs.
	 *
	 * @param  string $status   Parent job status.
	 * @param  array  $children Child summary.
	 * @return string
	 */
	private function cleanup_run_state( string $status, array $children ): string {
		$parent_state = $this->cleanup_job_state($status);
		if ( in_array($parent_state, array( 'cancelled', 'partial_failure' ), true) ) {
			return $parent_state;
		}

		if ( (int) ( $children['running'] ?? 0 ) > 0 ) {
			return 'children_processing';
		}
		if ( (int) ( $children['failed'] ?? 0 ) > 0 ) {
			return 'partial_failed';
		}

		return $parent_state;
	}

	/**
	 * Convert job status into cleanup run state.
	 *
	 * @param  string $status Job status.
	 * @return string
	 */
	private function cleanup_job_state( string $status ): string {
		if ( in_array($status, array( 'pending', 'processing' ), true) ) {
			return 'running';
		}
		if ( str_starts_with($status, 'failed - cleanup_cancelled') ) {
			return 'cancelled';
		}
		if ( str_starts_with($status, 'failed') ) {
			return 'partial_failure';
		}
		if ( str_starts_with($status, 'completed') ) {
			return 'complete';
		}
		return 'unknown';
	}

	/**
	 * Build stable run id from a Data Machine job id.
	 *
	 * @param  int $job_id Job ID.
	 * @return string
	 */
	private function cleanup_run_id( int $job_id ): string {
		return 'cleanup-run-' . $job_id;
	}

	/**
	 * Parse stable run id into a Data Machine job id.
	 *
	 * @param  string $run_id Run ID.
	 * @return int
	 */
	private function cleanup_run_job_id( string $run_id ): int {
		$run_id = trim($run_id);
		if ( is_numeric($run_id) ) {
			return (int) $run_id;
		}
		if ( preg_match('/cleanup-run-(\d+)/', $run_id, $matches) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * Format bytes using binary units.
	 *
	 * @param  int $bytes Bytes.
	 * @return string
	 */
	private function format_bytes( int $bytes ): string {
		$bytes    = max(0, $bytes);
		$units    = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$max_unit = count($units) - 1;
		$value    = (float) $bytes;
		$unit     = 0;
		while ( $value >= 1024 && $unit < $max_unit ) {
			$value /= 1024;
			++$unit;
		}
		return 0 === $unit ? sprintf('%d B', (int) $value) : sprintf('%.1f %s', $value, $units[ $unit ]);
	}
}
