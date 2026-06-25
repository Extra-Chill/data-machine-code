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

	public static function cleanup_result( array $result ): array {
		$summary    = (array) ( $result['summary'] ?? array() );
		$candidates = (array) ( $result['candidates'] ?? $result['artifact_candidates'] ?? array() );
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
				'inventory'                 => $report['inventory'] ?? null,
				'worktrees'                 => $report['worktrees'] ?? null,
				'worktree_status_mode'      => $report['worktree_status_mode'] ?? null,
				'locks'                     => isset( $report['locks'] ) ? self::lock_result( (array) $report['locks'] ) : null,
				'cleanup'                   => array(
					'summary'            => (array) ( $cleanup['summary'] ?? array() ),
					'biggest_candidates' => self::compact_rows( (array) ( $cleanup['biggest_candidates'] ?? array() ) ),
				),
				'size'                      => array(
					'mode'          => $size['mode'] ?? null,
					'total_bytes'   => $size['total_bytes'] ?? null,
					'total_human'   => $size['total_human'] ?? null,
					'scan_complete' => $size['scan_complete'] ?? null,
					'entry_count'   => count( (array) ( $size['entries'] ?? array() ) ),
					'top_entries'   => self::compact_rows( (array) ( $size['top_entries'] ?? array() ) ),
				),
				'suggested_cleanup_command' => $report['suggested_cleanup_command'] ?? null,
				'suggested_size_command'    => $report['suggested_size_command'] ?? null,
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
		foreach ( array( 'candidates', 'artifact_candidates', 'worktree_candidates', 'removed', 'removed_artifacts', 'removed_worktrees', 'skipped', 'written', 'proposals', 'pass_results' ) as $key ) {
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
				'count'    => (int) $count,
				'examples' => array(),
			);
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$reason               = (string) ( $row['reason_code'] ?? $row['reason'] ?? 'unknown' );
			$buckets[ $reason ] ??= array(
				'count'    => 0,
				'examples' => array(),
			);
			if ( ! isset( $counts[ $reason ] ) ) {
				++$buckets[ $reason ]['count'];
			}
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
		foreach ( array( 'apply_command', 'next_command', 'status_command', 'suggested_cleanup_command' ) as $field ) {
			if ( ! empty($result[ $field ]) ) {
				$commands[] = (string) $result[ $field ];
			}
			if ( ! empty($summary[ $field ]) ) {
				$commands[] = (string) $summary[ $field ];
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
		foreach ( array( 'size_bytes', 'artifact_size_bytes', 'bytes_reclaimed', 'dirty', 'unpushed', 'age_days', 'created_at', 'liveness' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$compact[ $field ] = $row[ $field ];
			}
		}
		return self::filter_empty( $compact );
	}

	private static function filter_empty( array $data ): array {
		return array_filter( $data, static fn( $value ) => null !== $value && '' !== $value && array() !== $value );
	}
}
