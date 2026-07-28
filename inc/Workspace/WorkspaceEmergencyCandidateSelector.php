<?php
/**
 * Resource-aware emergency cleanup candidate selection.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorkspaceEmergencyCandidateSelector {

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
	private static function find_viable_set( array $rows, int $target_bytes, int $target_inodes, int $limit, int $offset = 0, array $selected = array(), int $bytes = 0, int $inodes = 0 ): ?array {
		if ( $bytes >= $target_bytes && $inodes >= $target_inodes ) {
			return $selected;
		}
		$slots = $limit - count($selected);
		if ( $slots <= 0 || $offset >= count($rows) ) {
			return null;
		}
		$remaining  = array_slice($rows, $offset);
		$byte_caps  = array_column($remaining, 'bytes');
		$inode_caps = array_column($remaining, 'inodes');
		rsort($byte_caps, SORT_NUMERIC);
		rsort($inode_caps, SORT_NUMERIC);
		if ( $bytes + array_sum(array_slice($byte_caps, 0, $slots)) < $target_bytes || $inodes + array_sum(array_slice($inode_caps, 0, $slots)) < $target_inodes ) {
			return null;
		}
		$row_count = count($rows);
		for ( $index = $offset; $index < $row_count; ++$index ) {
			$next   = array_merge($selected, array( $rows[ $index ] ));
			$result = self::find_viable_set($rows, $target_bytes, $target_inodes, $limit, $index + 1, $next, $bytes + $rows[ $index ]['bytes'], $inodes + $rows[ $index ]['inodes']);
			if ( null !== $result ) {
				return $result;
			}
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
