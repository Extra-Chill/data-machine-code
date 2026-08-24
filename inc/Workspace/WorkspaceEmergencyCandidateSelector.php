<?php
/**
 * Resource-aware emergency cleanup candidate selection.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorkspaceEmergencyCandidateSelector {
	private const MAX_SEARCH_CANDIDATES = 256;
	private const MAX_SEARCH_STATES     = 4096;

	/** Select a viable bounded recovery set, or the strongest truthful fallback. */
	public static function select( array $candidates, int $target_bytes, int $target_inodes, int $limit, string $bytes_field ): array {
		$target_bytes  = max(0, $target_bytes);
		$target_inodes = max(0, $target_inodes);
		$limit         = max(1, $limit);
		$rows          = array_map(
			static function ( array $candidate ) use ( $bytes_field ): array {
				return array(
					'candidate' => $candidate,
					'bytes'     => is_numeric($candidate[ $bytes_field ] ?? null) ? max(0, (int) $candidate[ $bytes_field ]) : 0,
					'inodes'    => 'measured' === (string) ( $candidate['entry_count_status'] ?? '' ) && is_numeric($candidate['entry_count'] ?? null) ? max(0, (int) $candidate['entry_count']) : 0,
				);
			},
			$candidates
		);

		usort($rows, static function ( array $left, array $right ) use ( $target_bytes, $target_inodes ): int {
			if ( $target_bytes > 0 && 0 === $target_inodes ) {
				return $right['bytes'] <=> $left['bytes'];
			}
			if ( $target_inodes > 0 && 0 === $target_bytes ) {
				return $right['inodes'] <=> $left['inodes'];
			}
			$left_total  = min($target_bytes, $left['bytes']) + min($target_inodes, $left['inodes']);
			$right_total = min($target_bytes, $right['bytes']) + min($target_inodes, $right['inodes']);
			return $right_total <=> $left_total;
		});

		$selected       = self::find_viable_set($rows, $target_bytes, $target_inodes, $limit);
		$selected       = null === $selected ? self::marginal_fallback($rows, $target_bytes, $target_inodes, $limit) : $selected;
		$planned_bytes  = array_sum(array_column($selected, 'bytes'));
		$planned_inodes = array_sum(array_column($selected, 'inodes'));

		return array(
			'candidates'                       => array_column($selected, 'candidate'),
			'target_recovery_bytes'            => $target_bytes,
			'target_recovery_inodes'           => $target_inodes,
			'planned_measured_recovery_bytes'  => $planned_bytes,
			'planned_measured_recovery_inodes' => $planned_inodes,
			'target_met'                       => $planned_bytes >= $target_bytes && $planned_inodes >= $target_inodes,
			'chunk_limit'                      => $limit,
			'measured_candidate_count'         => count(array_filter($rows, static fn( array $row ): bool => 'measured' === (string) ( $row['candidate']['entry_count_status'] ?? '' ))),
			'unknown_candidate_count'          => count(array_filter($rows, static fn( array $row ): bool => 'measured' !== (string) ( $row['candidate']['entry_count_status'] ?? '' ))),
			'evidence_semantics'               => 'A viable bounded set is selected before marginal best effort; byte and inode targets remain independent.',
		);
	}

	/** @return array<int,array<string,mixed>>|null */
	private static function find_viable_set( array $rows, int $target_bytes, int $target_inodes, int $limit ): ?array {
		$states = array(
			array(
				'selected' => array(),
				'bytes'    => 0,
				'inodes'   => 0,
			),
		);
		foreach ( array_slice($rows, 0, self::MAX_SEARCH_CANDIDATES) as $row ) {
			$next_states = $states;
			foreach ( $states as $state ) {
				if ( count($state['selected']) >= $limit ) {
					continue;
				}
				$next = array(
					'selected' => array_merge($state['selected'], array( $row )),
					'bytes'    => $state['bytes'] + $row['bytes'],
					'inodes'   => $state['inodes'] + $row['inodes'],
				);
				if ( $next['bytes'] >= $target_bytes && $next['inodes'] >= $target_inodes ) {
					return $next['selected'];
				}
				$next_states[] = $next;
			}

			$unique_states = array();
			foreach ( $next_states as $state ) {
				$key                     = sprintf(
					'%d:%d:%d',
					min($target_bytes, $state['bytes']),
					min($target_inodes, $state['inodes']),
					count($state['selected'])
				);
				$unique_states[ $key ] ??= $state;
			}
			$next_states = array_values($unique_states);

			usort(
				$next_states,
				static function ( array $left, array $right ) use ( $target_bytes, $target_inodes ): int {
					$left_score  = min($target_bytes, $left['bytes']) + min($target_inodes, $left['inodes']);
					$right_score = min($target_bytes, $right['bytes']) + min($target_inodes, $right['inodes']);
					$score_order = $right_score <=> $left_score;
					return 0 !== $score_order ? $score_order : count($left['selected']) <=> count($right['selected']);
				}
			);
			$states = array_slice($next_states, 0, self::MAX_SEARCH_STATES);
		}

		return null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function marginal_fallback( array $rows, int $target_bytes, int $target_inodes, int $limit ): array {
		$selected       = array();
		$selected_count = 0;
		$bytes          = 0;
		$inodes         = 0;
		while ( $selected_count < $limit && array() !== $rows ) {
			$remaining_bytes  = max(0, $target_bytes - $bytes);
			$remaining_inodes = max(0, $target_inodes - $inodes);
			usort($rows, static function ( array $left, array $right ) use ( $remaining_bytes, $remaining_inodes ): int {
				$left_score  = min($remaining_bytes, $left['bytes']) + min($remaining_inodes, $left['inodes']);
				$right_score = min($remaining_bytes, $right['bytes']) + min($remaining_inodes, $right['inodes']);
				return $right_score <=> $left_score;
			});
			$row        = array_shift($rows);
			$selected[] = $row;
			++$selected_count;
			$bytes  += $row['bytes'];
			$inodes += $row['inodes'];
		}
		return $selected;
	}
}
