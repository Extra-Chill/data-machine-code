<?php
/**
 * Compact workspace operator JSON output.
 *
 * @package DataMachineCode\Cli
 */

namespace DataMachineCode\Cli;

defined('ABSPATH') || exit;

class WorkspaceCompactOutput {

	private const ROW_SAMPLE_LIMIT = 5;
	private const WORKTREE_BOOTSTRAP_STEP_LIMIT = 5;
	private const WORKTREE_WARNING_CODE_LIMIT = 10;

	/** Project a worktree-add result for bounded public JSON responses. */
	public static function worktree_add_result( array $result ): array {
		$bootstrap = (array) ( $result['bootstrap'] ?? array() );
		$capacity  = (array) ( $result['disk_budget'] ?? array() );

		return self::filter_empty(
			array(
				'success'        => (bool) ( $result['success'] ?? true ),
				'handle'         => $result['handle'] ?? null,
				'path'           => $result['path'] ?? null,
				'branch'         => $result['branch'] ?? null,
				'base'           => $result['base'] ?? $result['metadata']['base_ref'] ?? null,
				'slug'           => $result['slug'] ?? null,
				'created_branch' => isset( $result['created_branch'] ) ? (bool) $result['created_branch'] : null,
				'reused'         => isset( $result['reused'] ) ? (bool) $result['reused'] : null,
				'recycled'       => isset( $result['recycled'] ) ? (bool) $result['recycled'] : null,
				'adopted'        => isset( $result['adopted'] ) ? (bool) $result['adopted'] : null,
				'handoff_freshness_proof' => $result['handoff_freshness_proof'] ?? null,
				'handoff_freshness' => $result['handoff_freshness'] ?? null,
				'message'        => $result['message'] ?? null,
				'capacity'       => self::worktree_capacity_summary( $capacity ),
				'bootstrap'      => self::worktree_bootstrap_summary( $bootstrap ),
				'warning_codes'  => self::worktree_warning_codes( $result, $capacity, $bootstrap ),
				'evidence'       => array(
					'verbose' => array(
						'input'    => array( 'verbose' => true ),
						'includes' => array( 'capacity_model', 'capacity_reclaim', 'bootstrap_step_output', 'metadata' ),
					),
				),
			)
		);
	}

	private static function worktree_capacity_summary( array $capacity ): array {
		if ( array() === $capacity ) {
			return array();
		}
		$typed_trigger_reasons = isset($capacity['typed_trigger_reasons']) && is_array($capacity['typed_trigger_reasons'])
			? $capacity['typed_trigger_reasons']
			: array_map(
				static function ( string $code ): array {
					$is_blocking = str_contains($code, '_refusal_floor');
					return array(
						'code'      => $code,
						'severity'  => $is_blocking ? 'blocking' : 'advisory',
						'resource'  => str_contains($code, '_inodes_') ? 'inodes' : ( 'worktree_count_warning_threshold' === $code ? 'worktree_count' : 'bytes' ),
						'threshold' => $is_blocking ? 'refusal_floor' : 'warning_floor',
					);
				},
				array_values(array_map('strval', (array) ( $capacity['trigger_reasons'] ?? array() )))
			);
		$has_blocking_trigger = array() !== array_filter($typed_trigger_reasons, static fn( array $trigger ): bool => 'blocking' === $trigger['severity']);

		return self::filter_empty(array(
			'status'                  => $capacity['status'] ?? null,
			'force_override'          => isset( $capacity['force_override'] ) ? (bool) $capacity['force_override'] : null,
			'creation_allowed'        => array_key_exists('creation_allowed', $capacity) ? (bool) $capacity['creation_allowed'] : ( 'refused' !== ( $capacity['status'] ?? '' ) ),
			'force_override_required' => array_key_exists('force_override_required', $capacity) ? (bool) $capacity['force_override_required'] : $has_blocking_trigger,
			'force_override_applied'  => array_key_exists('force_override_applied', $capacity) ? (bool) $capacity['force_override_applied'] : ( ! empty($capacity['forced']) && $has_blocking_trigger ),
			'typed_trigger_reasons'   => $typed_trigger_reasons,
		));
	}

	private static function worktree_bootstrap_summary( array $bootstrap ): array {
		if ( array() === $bootstrap ) {
			return array();
		}
		$steps = array();
		foreach ( array_slice( (array) ( $bootstrap['steps'] ?? array() ), 0, self::WORKTREE_BOOTSTRAP_STEP_LIMIT ) as $step ) {
			$step = (array) $step;
			$steps[] = self::filter_empty(array(
				'step'             => $step['step'] ?? null,
				'relative'         => $step['relative'] ?? null,
				'status'           => $step['status'] ?? null,
				'reason'           => $step['reason'] ?? null,
				'exit_code'        => $step['exit_code'] ?? null,
				'timed_out'        => $step['timed_out'] ?? null,
				'duration_ms'      => $step['duration_ms'] ?? null,
			));
		}

		return self::filter_empty(array(
			'success'     => isset( $bootstrap['success'] ) ? (bool) $bootstrap['success'] : null,
			'ran_any'     => isset( $bootstrap['ran_any'] ) ? (bool) $bootstrap['ran_any'] : null,
			'duration_ms' => $bootstrap['duration_ms'] ?? null,
			'step_count'  => count( (array) ( $bootstrap['steps'] ?? array() ) ),
			'steps'       => $steps,
		));
	}

	private static function worktree_warning_codes( array $result, array $capacity, array $bootstrap ): array {
		$codes = array();
		foreach ( (array) ( $capacity['trigger_reasons'] ?? array() ) as $warning ) {
			$codes[] = (string) $warning;
		}
		foreach ( (array) ( $capacity['warnings'] ?? array() ) as $warning ) {
			if ( is_array($warning) ) {
				$codes[] = (string) ( $warning['code'] ?? $warning['reason_code'] ?? '' );
			}
		}
		foreach ( (array) ( $bootstrap['steps'] ?? array() ) as $step ) {
			if ( 'failed' === ( $step['status'] ?? '' ) ) {
				$codes[] = 'bootstrap_' . (string) ( $step['reason'] ?? 'failed' );
			}
		}
		foreach ( array( 'fetch_failed', 'fetch_timed_out', 'rebase_succeeded' ) as $field ) {
			if ( array_key_exists($field, $result) && false === $result[ $field ] ) {
				$codes[] = $field;
			}
		}
		return array_slice(array_values(array_unique(array_filter($codes))), 0, self::WORKTREE_WARNING_CODE_LIMIT);
	}

	public static function cleanup_result( array $result ): array {
		$summary    = (array) ( $result['summary'] ?? array() );
		$candidates = (array) ( $result['candidates'] ?? $result['artifact_candidates'] ?? $result['worktree_candidates'] ?? $result['rows'] ?? array() );
		$planned    = (array) ( $result['planned'] ?? array() );
		$written    = (array) ( $result['written'] ?? array() );
		$removed    = (array) ( $result['removed'] ?? $result['removed_worktrees'] ?? $result['removed_artifacts'] ?? array() );
		$skipped    = (array) ( $result['skipped'] ?? array() );

		return self::filter_empty(
			array(
				'success'          => (bool) ( $result['success'] ?? true ),
				'mode'             => $result['mode'] ?? null,
				'dry_run'          => isset( $result['dry_run'] ) ? (bool) $result['dry_run'] : null,
				'destructive'      => isset( $result['destructive'] ) ? (bool) $result['destructive'] : null,
				'workspace_path'   => $result['workspace_path'] ?? null,
				'generated_at'     => $result['generated_at'] ?? null,
				'summary'          => $summary,
				'row_counts'       => self::row_counts( $result ),
				'blockers'         => self::blocker_buckets( $skipped, (array) ( $summary['skipped_by_reason'] ?? array() ) ),
				'bytes'            => self::byte_summary( $summary ),
				'samples'          => array(
					'candidates' => self::compact_rows( $candidates ),
					'planned'    => self::compact_rows( $planned ),
					'written'    => self::compact_rows( $written ),
					'removed'    => self::compact_rows( $removed ),
					'skipped'    => self::compact_rows( $skipped ),
				),
				'pagination'       => self::compact_pagination( (array) ( $result['pagination'] ?? $summary['pagination'] ?? array() ) ),
				'continuation'     => self::compact_pagination( (array) ( $result['continuation'] ?? array() ) ),
				'next_commands'    => self::next_commands( $result, $summary ),
				'full_detail_hint' => 'Re-run with --verbose --format=json for full row arrays and evidence.',
			)
		);
	}

	public static function cleanup_control_result( array $result ): array {
		$cleanup_items = (array) ( $result['cleanup_items'] ?? $result['evidence']['cleanup_items'] ?? array() );
		$remaining     = (array) ( $result['remaining_work_summary'] ?? array() );

		return self::filter_empty(
			array(
				'success'                => (bool) ( $result['success'] ?? true ),
				'run_id'                 => $result['run_id'] ?? null,
				'job_id'                 => $result['job_id'] ?? null,
				'mode'                   => $result['mode'] ?? $result['evidence']['engine_data']['cleanup_run']['mode'] ?? null,
				'state'                  => $result['state'] ?? null,
				'status'                 => $result['status'] ?? null,
				'progress'               => $result['progress'] ?? null,
				'cleanup_counts'         => array(
					'planned'         => (int) ( $cleanup_items['planned_rows'] ?? 0 ),
					'applied'         => (int) ( $cleanup_items['applied_rows'] ?? 0 ),
					'skipped'         => (int) ( $cleanup_items['skipped_rows'] ?? 0 ),
					'failed'          => (int) ( $cleanup_items['failed_rows'] ?? 0 ),
					'bytes_reclaimed' => (int) ( $cleanup_items['bytes_reclaimed'] ?? 0 ),
				),
				'remaining_work_summary' => $remaining,
				'commands'               => $result['commands'] ?? $remaining['recommended_commands'] ?? null,
				'locks'                  => isset( $result['locks'] ) ? self::lock_result( (array) $result['locks'] ) : null,
				'full_detail_hint'       => 'Use workspace cleanup evidence <run-id> --format=json for full evidence, or status with --verbose for detailed status.',
			)
		);
	}

	public static function hygiene_report( array $report ): array {
		$cleanup = (array) ( $report['cleanup'] ?? array() );
		$size    = (array) ( $report['size'] ?? array() );

		return self::filter_empty(
			array(
				'success'                   => (bool) ( $report['success'] ?? true ),
				'generated_at'              => $report['generated_at'] ?? null,
				'workspace_path'            => $report['workspace_path'] ?? null,
				'destructive'               => (bool) ( $report['destructive'] ?? false ),
				'fast_stats'                => $report['fast_stats'] ?? null,
				'disk'                      => $report['disk'] ?? null,
				'recovery'                  => $report['recovery'] ?? null,
				'inventory'                 => $report['inventory'] ?? null,
				'worktrees'                 => $report['worktrees'] ?? null,
				'worktree_status_mode'      => $report['worktree_status_mode'] ?? null,
				'locks'                     => isset( $report['locks'] ) ? self::lock_result( (array) $report['locks'] ) : null,
				'cleanup'                   => array(
					'blocker_probe_source' => $cleanup['blocker_probe_source'] ?? null,
					'blocker_counts'       => $cleanup['blocker_counts'] ?? null,
					'expected_outcome'     => $cleanup['expected_outcome'] ?? null,
					'summary'              => (array) ( $cleanup['summary'] ?? array() ),
					'biggest_candidates'   => self::compact_rows( (array) ( $cleanup['biggest_candidates'] ?? array() ) ),
				),
				'size'                      => array(
					'mode'                 => $size['mode'] ?? null,
					'total_bytes'          => $size['total_bytes'] ?? null,
					'total_human'          => $size['total_human'] ?? null,
					'scan_complete'        => $size['scan_complete'] ?? null,
					'entry_count'          => count( (array) ( $size['entries'] ?? array() ) ),
					'top_entries'          => self::compact_rows( (array) ( $size['top_entries'] ?? array() ) ),
					'total_entry_count'    => $size['total_entry_count'] ?? null,
					'entry_count_minimum'  => $size['entry_count_minimum'] ?? null,
					'entry_count_scan'     => $size['entry_count_scan'] ?? null,
					'top_entries_by_count' => self::compact_rows( (array) ( $size['top_entries_by_count'] ?? array() ) ),
				),
				'notes'                     => $report['notes'] ?? null,
				'full_detail_hint'          => 'Re-run with --verbose --format=json for full hygiene arrays.',
			)
		);
	}

	public static function lock_result( array $result ): array {
		$status = isset( $result['after'] ) && is_array( $result['after'] ) ? (array) $result['after'] : $result;
		$fs     = (array) ( $status['filesystem'] ?? array() );
		$db     = (array) ( $status['database'] ?? array() );

		return self::filter_empty(
			array(
				'success'           => $result['success'] ?? null,
				'dry_run'           => $result['dry_run'] ?? null,
				'active'            => (int) ( $status['active'] ?? 0 ),
				'stale'             => (int) ( $status['stale'] ?? 0 ),
				'database'          => array(
					'total'        => (int) ( $db['total'] ?? count( (array) ( $db['locks'] ?? array() ) ) ),
					'active'       => (int) ( $db['active'] ?? 0 ),
					'stale'        => (int) ( $db['stale'] ?? 0 ),
					'lock_samples' => self::compact_lock_rows( (array) ( $db['locks'] ?? array() ) ),
				),
				'filesystem'        => array(
					'total'         => (int) ( $fs['total'] ?? count( (array) ( $fs['locks'] ?? array() ) ) ),
					'active'        => (int) ( $fs['active'] ?? 0 ),
					'stale'         => (int) ( $fs['stale'] ?? 0 ),
					'recent'        => (int) ( $fs['recent'] ?? 0 ),
					'lock_samples'  => self::compact_lock_rows( (array) ( $fs['locks'] ?? array() ) ),
					'guidance'      => $fs['guidance'] ?? null,
					'removed_count' => $result['filesystem']['removed_count'] ?? null,
					'skipped_count' => $result['filesystem']['skipped_count'] ?? null,
				),
				'stale_locks'       => self::compact_stale_locks( (array) ( $status['stale_locks'] ?? array() ) ),
				'recovery_guidance' => $status['recovery_guidance'] ?? null,
				'full_detail_hint'  => 'Re-run with --verbose --format=json for full lock evidence arrays.',
			)
		);
	}

	private static function row_counts( array $result ): array {
		$counts = array();
		foreach ( array( 'candidates', 'artifact_candidates', 'worktree_candidates', 'rows', 'planned', 'removed', 'removed_artifacts', 'removed_worktrees', 'written', 'skipped', 'proposals', 'pass_results' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				$counts[ $key ] = count( $result[ $key ] );
			}
		}
		return $counts;
	}

	private static function byte_summary( array $summary ): array {
		$bytes = array();
		foreach ( array( 'bytes_reclaimed', 'total_size_bytes', 'artifact_size_bytes', 'worktree_size_bytes', 'removed_size_bytes' ) as $field ) {
			if ( array_key_exists( $field, $summary ) ) {
				$bytes[ $field ] = (int) $summary[ $field ];
			}
		}
		return $bytes;
	}

	private static function blocker_buckets( array $rows, array $counts = array() ): array {
		$buckets = array();
		foreach ( $counts as $reason => $count ) {
			$buckets[ (string) $reason ] = array(
				'count'           => (int) $count,
				'size_accounting' => self::empty_size_accounting(),
				'examples'        => array(),
			);
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$reason               = (string) ( $row['reason_code'] ?? $row['reason'] ?? 'unknown' );
			$buckets[ $reason ] ??= array(
				'count'           => 0,
				'size_accounting' => self::empty_size_accounting(),
				'examples'        => array(),
			);
			if ( ! isset( $counts[ $reason ] ) ) {
				++$buckets[ $reason ]['count'];
			}
			$buckets[ $reason ]['size_accounting'] = self::add_row_size_accounting( (array) $buckets[ $reason ]['size_accounting'], $row );
			if ( count( $buckets[ $reason ]['examples'] ) < 3 ) {
				$buckets[ $reason ]['examples'][] = self::compact_row( $row );
			}
		}
		ksort( $buckets );
		return $buckets;
	}

	private static function next_commands( array $result, array $summary ): array {
		$commands = array_merge(
			(array) ( $result['next_commands'] ?? array() ),
			(array) ( $summary['next_commands'] ?? array() ),
			(array) ( $summary['skipped_next_commands'] ?? array() )
		);
		foreach ( (array) ( $result['skipped'] ?? array() ) as $row ) {
			$retry_command = is_array($row) ? (string) ( $row['process_probe_diagnostics']['retry_command'] ?? '' ) : '';
			if ( '' !== $retry_command ) {
				$commands[] = $retry_command;
			}
		}
		foreach ( array( 'apply_command', 'next_command', 'status_command', 'suggested_cleanup_command' ) as $field ) {
			if ( ! empty($result[ $field ]) ) {
				$commands[] = (string) $result[ $field ];
			}
			if ( ! empty($summary[ $field ]) ) {
				$commands[] = (string) $summary[ $field ];
			}
		}
		foreach ( array( 'pagination', 'continuation' ) as $bucket ) {
			if ( ! empty($result[ $bucket ]['next_command']) ) {
				$commands[] = (string) $result[ $bucket ]['next_command'];
			}
			if ( ! empty($summary[ $bucket ]['next_command']) ) {
				$commands[] = (string) $summary[ $bucket ]['next_command'];
			}
		}
		$deduped = array();
		$seen    = array();
		foreach ( $commands as $command ) {
			if ( is_array( $command ) ) {
				$key = (string) ( $command['reason_code'] ?? $command['bucket'] ?? '' ) . '|' . (string) ( $command['command'] ?? '' ) . '|' . (string) ( $command['apply'] ?? '' );
				if ( '||' === $key ) {
					continue;
				}
			} else {
				$key = (string) $command;
				if ( '' === $key ) {
					continue;
				}
			}
			if ( isset($seen[ $key ]) ) {
				continue;
			}
			$seen[ $key ] = true;
			$deduped[]    = $command;
		}

		return $deduped;
	}

	private static function compact_pagination( array $pagination ): array {
		foreach ( array( 'handles', 'remaining_handles' ) as $field ) {
			$handles = array_values( array_filter( array_map( 'strval', (array) ( $pagination[ $field ] ?? array() ) ) ) );
			if ( array() === $handles ) {
				unset($pagination[ $field ]);
				continue;
			}
			$pagination[ $field . '_count' ]     = count( $handles );
			$pagination[ $field . '_examples' ]  = array_slice( $handles, 0, self::ROW_SAMPLE_LIMIT );
			$pagination[ $field . '_truncated' ] = count( $handles ) > self::ROW_SAMPLE_LIMIT;
			unset($pagination[ $field ]);
		}
		return $pagination;
	}

	private static function compact_stale_locks( array $report ): array {
		if ( array() === $report ) {
			return array();
		}
		return self::filter_empty(
			array(
				'count'              => (int) ( $report['count'] ?? 0 ),
				'database_count'     => (int) ( $report['database_count'] ?? count( (array) ( $report['database'] ?? array() ) ) ),
				'filesystem_count'   => (int) ( $report['filesystem_count'] ?? count( (array) ( $report['filesystem'] ?? array() ) ) ),
				'preview_command'    => $report['preview_command'] ?? null,
				'apply_command'      => $report['apply_command'] ?? null,
				'safety'             => $report['safety'] ?? null,
				'database_samples'   => self::compact_lock_rows( (array) ( $report['database'] ?? array() ) ),
				'filesystem_samples' => self::compact_lock_rows( (array) ( $report['filesystem'] ?? array() ) ),
			)
		);
	}

	private static function compact_lock_rows( array $rows ): array {
		return array_map(
			static function ( $row ): array {
				$row = (array) $row;
				return self::filter_empty(
					array(
						'lock_key'           => $row['lock_key'] ?? null,
						'scope'              => $row['scope'] ?? null,
						'state'              => $row['state'] ?? $row['status'] ?? null,
						'owner'              => $row['owner'] ?? null,
						'age_seconds'        => $row['age_seconds'] ?? null,
						'safe_to_prune'      => $row['safe_to_prune'] ?? null,
						'live_flock_present' => $row['live_flock_present'] ?? null,
					)
				);
			},
			array_slice( $rows, 0, self::ROW_SAMPLE_LIMIT )
		);
	}

	private static function compact_rows( array $rows ): array {
		return array_map( static fn( $row ) => self::compact_row( (array) $row ), array_slice( $rows, 0, self::ROW_SAMPLE_LIMIT ) );
	}

	private static function compact_row( array $row ): array {
		$compact = array(
			'handle'      => $row['handle'] ?? null,
			'repo'        => $row['repo'] ?? null,
			'branch'      => $row['branch'] ?? null,
			'reason_code' => $row['reason_code'] ?? $row['signal'] ?? null,
			'path'        => $row['path'] ?? null,
			'pr_url'      => $row['pr_url'] ?? null,
		);
		foreach ( array( 'size_bytes', 'artifact_size_bytes', 'bytes_reclaimed', 'dirty', 'unpushed', 'age_days', 'created_at', 'liveness', 'fresh_revalidation_status' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$compact[ $field ] = $row[ $field ];
			}
		}
		foreach ( array( 'size_status', 'size_accounting', 'fields_skipped', 'fresh_revalidation_blockers', 'fresh_revalidation_checks' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$compact[ $field ] = $row[ $field ];
			}
		}
		if ( isset( $row['process_probe_diagnostics'] ) && is_array( $row['process_probe_diagnostics'] ) ) {
			$compact['process_probe_diagnostics'] = $row['process_probe_diagnostics'];
		}
		return self::filter_empty( $compact );
	}

	private static function empty_size_accounting(): array {
		return array(
			'known_bytes'      => 0,
			'known_count'      => 0,
			'known_zero_count' => 0,
			'skipped_count'    => 0,
			'unknown_count'    => 0,
		);
	}

	private static function add_row_size_accounting( array $accounting, array $row ): array {
		$accounting = array_merge( self::empty_size_accounting(), array_map( 'intval', $accounting ) );
		$status     = self::row_size_status( $row );
		if ( 'known' === $status || 'known_zero' === $status ) {
			++$accounting['known_count'];
			$accounting['known_bytes'] += self::row_known_bytes( $row );
			if ( 'known_zero' === $status ) {
				++$accounting['known_zero_count'];
			}
		} elseif ( 'skipped' === $status ) {
			++$accounting['skipped_count'];
		} else {
			++$accounting['unknown_count'];
		}

		return $accounting;
	}

	private static function row_size_status( array $row ): string {
		if ( isset( $row['size_accounting'] ) && is_array( $row['size_accounting'] ) && isset( $row['size_accounting']['status'] ) ) {
			return (string) $row['size_accounting']['status'];
		}
		foreach ( array( 'artifact_size_bytes', 'size_bytes', 'bytes_reclaimed' ) as $field ) {
			if ( array_key_exists( $field, $row ) && is_numeric( $row[ $field ] ) ) {
				return 0 === max( 0, (int) $row[ $field ] ) ? 'known_zero' : 'known';
			}
		}
		$skipped = array_map( 'strval', (array) ( $row['fields_skipped'] ?? array() ) );
		return array_intersect( $skipped, array( 'disk', 'size', 'sizes' ) ) ? 'skipped' : 'unknown';
	}

	private static function row_known_bytes( array $row ): int {
		if ( isset( $row['size_accounting'] ) && is_array( $row['size_accounting'] ) && isset( $row['size_accounting']['bytes'] ) && is_numeric( $row['size_accounting']['bytes'] ) ) {
			return max( 0, (int) $row['size_accounting']['bytes'] );
		}
		foreach ( array( 'artifact_size_bytes', 'size_bytes', 'bytes_reclaimed' ) as $field ) {
			if ( array_key_exists( $field, $row ) && is_numeric( $row[ $field ] ) ) {
				return max( 0, (int) $row[ $field ] );
			}
		}
		return 0;
	}

	private static function filter_empty( array $data ): array {
		return array_filter( $data, static fn( $value ) => null !== $value && '' !== $value && array() !== $value );
	}
}
