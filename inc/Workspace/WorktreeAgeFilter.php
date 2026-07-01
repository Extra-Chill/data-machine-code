<?php
/**
 * Shared worktree cleanup age filtering helpers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeAgeFilter {

	/**
	 * Build an age filter summary/counter array from a parsed duration.
	 *
	 * @return array<string,mixed>
	 */
	public static function build( string $older_than, int $duration_seconds, ?int $now = null ): array {
		$threshold_ts = ( $now ?? time() ) - $duration_seconds;

		return array(
			'type'             => 'older_than',
			'older_than'       => $older_than,
			'duration_seconds' => $duration_seconds,
			'threshold'        => gmdate('c', $threshold_ts),
			'threshold_unix'   => $threshold_ts,
			'excluded'         => 0,
			'unknown_age'      => 0,
		);
	}

	/**
	 * Decide whether a row passes the age filter and update summary counters.
	 *
	 * @param  mixed               $created_at Worktree creation timestamp.
	 * @param  array<string,mixed> $age_filter Mutable filter summary/counter array.
	 * @return array<string,mixed>
	 */
	public static function decide( mixed $created_at, array &$age_filter, ?int $now = null ): array {
		$created_ts = is_string($created_at) && '' !== $created_at ? strtotime($created_at) : false;
		if ( false === $created_ts ) {
			++$age_filter['unknown_age'];
			return array(
				'decision'   => 'unknown_age',
				'age_filter' => array(
					'type'       => 'older_than',
					'older_than' => $age_filter['older_than'],
					'threshold'  => $age_filter['threshold'],
					'decision'   => 'unknown_age',
				),
			);
		}

		$decision = array(
			'type'        => 'older_than',
			'older_than'  => $age_filter['older_than'],
			'threshold'   => $age_filter['threshold'],
			'created_at'  => $created_at,
			'age_seconds' => ( $now ?? time() ) - $created_ts,
		);

		if ( $created_ts > $age_filter['threshold_unix'] ) {
			++$age_filter['excluded'];
			$decision['decision'] = 'excluded';
			return array(
				'decision'   => 'excluded',
				'age_filter' => $decision,
			);
		}

		$decision['decision'] = 'included';
		return array(
			'decision'   => 'included',
			'age_filter' => $decision,
		);
	}

	/**
	 * Build the standard skip row fragment for an age filter decision.
	 *
	 * @param  array<string,mixed> $decision Age decision from decide().
	 * @return array<string,mixed>
	 */
	public static function skip_fields( array $decision ): array {
		if ( 'unknown_age' === (string) ( $decision['decision'] ?? '' ) ) {
			return array(
				'reason_code' => 'unknown_age',
				'reason'      => 'missing or invalid created_at metadata - age filter cannot decide safely',
				'age_filter'  => $decision['age_filter'],
			);
		}

		$age_filter = is_array($decision['age_filter'] ?? null) ? $decision['age_filter'] : array();
		return array(
			'reason_code' => 'age_filter',
			'reason'      => sprintf('created_at %s is newer than --older-than=%s threshold %s', (string) ( $age_filter['created_at'] ?? '' ), (string) ( $age_filter['older_than'] ?? '' ), (string) ( $age_filter['threshold'] ?? '' )),
			'age_filter'  => $age_filter,
		);
	}
}
