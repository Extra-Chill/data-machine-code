<?php
/**
 * Worktree Disk Budget
 *
 * Cheap pre-create guardrails for workspace worktree growth.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

final class WorktreeDiskBudget {

	private const BYTES_PER_GIB = 1073741824;

	/**
	 * Default warning threshold: 20 GiB free.
	 */
	private const DEFAULT_WARN_FREE_GIB = 20;

	/**
	 * Default refusal threshold: 5 GiB free.
	 */
	private const DEFAULT_REFUSE_FREE_GIB = 5;

	/**
	 * Default worktree-count warning threshold.
	 */
	private const DEFAULT_WARN_WORKTREE_COUNT = 100;

	/**
	 * Inspect workspace disk budget without walking file contents.
	 *
	 * @param string $workspace_path Workspace root path.
	 * @param array  $thresholds     Optional threshold override for tests.
	 * @param bool   $forced         Whether the caller explicitly forced creation.
	 * @return array<string,mixed>
	 */
	public static function inspect( string $workspace_path, array $thresholds = array(), bool $forced = false ): array {
		$thresholds = self::normalize_thresholds( $thresholds );
		$free_bytes = is_dir( $workspace_path ) ? disk_free_space( $workspace_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_free_space
		$free_bytes = is_float( $free_bytes ) ? (int) $free_bytes : null;
		$worktrees  = self::count_worktree_like_dirs( $workspace_path );

		return self::evaluate(
			array(
				'workspace_path' => $workspace_path,
				'free_bytes'     => $free_bytes,
				'worktree_count' => $worktrees,
			),
			$thresholds,
			$forced
		);
	}

	/**
	 * Evaluate disk-budget status from already-measured values.
	 *
	 * @param array $metrics    Measured values.
	 * @param array $thresholds Threshold values.
	 * @param bool  $forced     Whether the caller explicitly forced creation.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $metrics, array $thresholds = array(), bool $forced = false ): array {
		$thresholds = self::normalize_thresholds( $thresholds );
		$free_bytes = isset( $metrics['free_bytes'] ) && is_numeric( $metrics['free_bytes'] ) ? (int) $metrics['free_bytes'] : null;
		$count      = isset( $metrics['worktree_count'] ) && is_numeric( $metrics['worktree_count'] ) ? (int) $metrics['worktree_count'] : 0;
		$warnings   = array();
		$refused    = false;

		if ( null !== $free_bytes ) {
			if ( $free_bytes < $thresholds['refuse_free_bytes'] ) {
				$refused    = ! $forced;
				$warnings[] = sprintf(
					'Free disk space is %.1f GiB, below the %.1f GiB refusal threshold.',
					self::bytes_to_gib( $free_bytes ),
					self::bytes_to_gib( $thresholds['refuse_free_bytes'] )
				);
			} elseif ( $free_bytes < $thresholds['warn_free_bytes'] ) {
				$warnings[] = sprintf(
					'Free disk space is %.1f GiB, below the %.1f GiB warning threshold.',
					self::bytes_to_gib( $free_bytes ),
					self::bytes_to_gib( $thresholds['warn_free_bytes'] )
				);
			}
		} else {
			$warnings[] = 'Free disk space could not be measured.';
		}

		if ( $count > $thresholds['warn_worktree_count'] ) {
			$warnings[] = sprintf(
				'Workspace has %d worktree-like directories, above the %d warning threshold.',
				$count,
				$thresholds['warn_worktree_count']
			);
		}

		$status = $refused ? 'refused' : ( empty( $warnings ) ? 'ok' : 'warning' );

		return array(
			'workspace_path'          => (string) ( $metrics['workspace_path'] ?? '' ),
			'free_bytes'              => $free_bytes,
			'free_gib'                => null === $free_bytes ? null : round( self::bytes_to_gib( $free_bytes ), 2 ),
			'workspace_size_bytes'    => null,
			'workspace_size_exact'    => false,
			'worktree_count'          => $count,
			'warn_free_bytes'         => $thresholds['warn_free_bytes'],
			'warn_free_gib'           => round( self::bytes_to_gib( $thresholds['warn_free_bytes'] ), 2 ),
			'refuse_free_bytes'       => $thresholds['refuse_free_bytes'],
			'refuse_free_gib'         => round( self::bytes_to_gib( $thresholds['refuse_free_bytes'] ), 2 ),
			'warn_worktree_count'     => $thresholds['warn_worktree_count'],
			'forced'                  => $forced,
			'status'                  => $status,
			'warnings'                => $warnings,
			'cleanup_dry_run_command' => 'studio wp datamachine-code workspace worktree cleanup --dry-run',
			'force_override_required' => $refused,
			'force_override_applied'  => $forced && ! empty( $warnings ),
		);
	}

	/**
	 * Get filterable thresholds.
	 *
	 * @param string $repo   Repository name.
	 * @param string $branch Branch name.
	 * @return array<string,int>
	 */
	public static function thresholds( string $repo, string $branch ): array {
		$thresholds = array(
			'warn_free_bytes'     => self::DEFAULT_WARN_FREE_GIB * self::BYTES_PER_GIB,
			'refuse_free_bytes'   => self::DEFAULT_REFUSE_FREE_GIB * self::BYTES_PER_GIB,
			'warn_worktree_count' => self::DEFAULT_WARN_WORKTREE_COUNT,
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters pre-create worktree disk-budget thresholds.
			 *
			 * @param array  $thresholds Default thresholds.
			 * @param string $repo       Repository name.
			 * @param string $branch     Branch being materialized.
			 */
			// @phpstan-ignore-next-line WordPress accepts context args beyond the filtered value.
			$thresholds = apply_filters( 'datamachine_worktree_disk_budget_thresholds', $thresholds, $repo, $branch );
		}

		return self::normalize_thresholds( (array) $thresholds );
	}

	/**
	 * Format a short human-readable summary.
	 *
	 * @param array $budget Budget report.
	 * @return string
	 */
	public static function format_summary( array $budget ): string {
		$free = null === ( $budget['free_gib'] ?? null ) ? 'unknown' : sprintf( '%.1f GiB', (float) $budget['free_gib'] );
		return sprintf(
			'Disk budget: %s free, %d worktree-like dirs, status=%s.',
			$free,
			(int) ( $budget['worktree_count'] ?? 0 ),
			(string) ( $budget['status'] ?? 'unknown' )
		);
	}

	/**
	 * Normalize threshold inputs.
	 *
	 * @param array $thresholds Raw thresholds.
	 * @return array<string,int>
	 */
	private static function normalize_thresholds( array $thresholds ): array {
		$warn_free   = isset( $thresholds['warn_free_bytes'] ) && is_numeric( $thresholds['warn_free_bytes'] )
			? max( 0, (int) $thresholds['warn_free_bytes'] )
			: self::DEFAULT_WARN_FREE_GIB * self::BYTES_PER_GIB;
		$refuse_free = isset( $thresholds['refuse_free_bytes'] ) && is_numeric( $thresholds['refuse_free_bytes'] )
			? max( 0, (int) $thresholds['refuse_free_bytes'] )
			: self::DEFAULT_REFUSE_FREE_GIB * self::BYTES_PER_GIB;
		$count       = isset( $thresholds['warn_worktree_count'] ) && is_numeric( $thresholds['warn_worktree_count'] )
			? max( 0, (int) $thresholds['warn_worktree_count'] )
			: self::DEFAULT_WARN_WORKTREE_COUNT;

		if ( $refuse_free > $warn_free ) {
			$warn_free = $refuse_free;
		}

		return array(
			'warn_free_bytes'     => $warn_free,
			'refuse_free_bytes'   => $refuse_free,
			'warn_worktree_count' => $count,
		);
	}

	/**
	 * Count worktree-like directories cheaply without consulting every primary.
	 *
	 * @param string $workspace_path Workspace root path.
	 * @return int
	 */
	private static function count_worktree_like_dirs( string $workspace_path ): int {
		if ( ! is_dir( $workspace_path ) ) {
			return 0;
		}

		$entries = scandir( $workspace_path ); // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found,WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		if ( false === $entries ) {
			return 0;
		}

		$count = 0;
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! str_contains( $entry, '@' ) ) {
				continue;
			}

			if ( is_dir( $workspace_path . '/' . $entry ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Convert bytes to GiB.
	 *
	 * @param int $bytes Bytes.
	 * @return float
	 */
	private static function bytes_to_gib( int $bytes ): float {
		return $bytes / self::BYTES_PER_GIB;
	}
}
