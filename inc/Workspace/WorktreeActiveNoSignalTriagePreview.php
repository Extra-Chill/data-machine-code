<?php
/**
 * Concise active/no-signal triage preview for bounded cleanup output.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeActiveNoSignalTriagePreview {

	private const ACTIVE_REASON_CODES = array(
		'active_no_signal',
		'no_inventory_cleanup_signal',
		'lifecycle_reconciliation_candidate',
	);

	/**
	 * Build a bounded operator preview for unresolved active/no-signal rows.
	 *
	 * @param  array<int,array<string,mixed>> $rows Skipped cleanup rows.
	 * @param  int                           $limit Suggested command page size.
	 * @param  int                           $now   Current timestamp for age buckets.
	 * @return array<string,mixed>
	 */
	public static function build( array $rows, int $limit = 25, ?int $now = null ): array {
		$now     = $now ?? time();
		$limit   = max(1, min(200, $limit));
		$preview = array(
			'total'       => 0,
			'by_age'      => array(
				'lt_1d'   => 0,
				'1_7d'    => 0,
				'7_30d'   => 0,
				'gte_30d' => 0,
				'unknown' => 0,
			),
			'by_liveness' => array(),
			'by_repo'     => array(),
			'commands'    => self::commands($limit),
			'safety'      => 'Commands classify active/no-signal rows into cleanup_eligible metadata only; they do not remove worktrees or branches.',
		);

		foreach ( $rows as $row ) {
			if ( ! is_array($row) || ! in_array((string) ( $row['reason_code'] ?? '' ), self::ACTIVE_REASON_CODES, true) ) {
				continue;
			}

			++$preview['total'];
			++$preview['by_age'][ self::age_bucket($row['created_at'] ?? null, $now) ];

			$liveness = (string) ( $row['liveness'] ?? 'unknown' );
			if ( '' === $liveness ) {
				$liveness = 'unknown';
			}
			$preview['by_liveness'][ $liveness ] = (int) ( $preview['by_liveness'][ $liveness ] ?? 0 ) + 1;

			$repo = (string) ( $row['repo'] ?? 'unknown' );
			if ( '' === $repo ) {
				$repo = 'unknown';
			}
			$preview['by_repo'][ $repo ] = (int) ( $preview['by_repo'][ $repo ] ?? 0 ) + 1;
		}

		arsort($preview['by_liveness']);
		arsort($preview['by_repo']);
		$preview['by_repo'] = array_slice($preview['by_repo'], 0, 10, true);

		return $preview;
	}

	/**
	 * @param mixed $created_at Created-at value from inventory metadata.
	 */
	private static function age_bucket( mixed $created_at, int $now ): string {
		if ( ! is_string($created_at) || '' === trim($created_at) ) {
			return 'unknown';
		}

		$created = strtotime($created_at);
		if ( false === $created ) {
			return 'unknown';
		}

		$age = max(0, $now - $created);
		$day = 86400;
		if ( $age < $day ) {
			return 'lt_1d';
		}
		if ( $age < 7 * $day ) {
			return '1_7d';
		}
		if ( $age < 30 * $day ) {
			return '7_30d';
		}

		return 'gte_30d';
	}

	/**
	 * @return array<string,string>
	 */
	private static function commands( int $limit ): array {
		$base = sprintf('--limit=%d --offset=0 --until-budget=60s --format=json', $limit);

		return array(
			'report'                    => 'studio wp datamachine-code workspace worktree active-no-signal-report ' . $base,
			'equivalent_clean_dry_run'  => 'studio wp datamachine-code workspace worktree active-no-signal-equivalent-clean-apply --dry-run ' . $base,
			'equivalent_clean_apply'    => 'studio wp datamachine-code workspace worktree active-no-signal-equivalent-clean-apply ' . $base,
			'merged_to_default_dry_run' => 'studio wp datamachine-code workspace worktree active-no-signal-merged-apply --dry-run ' . $base,
			'merged_to_default_apply'   => 'studio wp datamachine-code workspace worktree active-no-signal-merged-apply ' . $base,
			'remote_clean_dry_run'      => 'studio wp datamachine-code workspace worktree active-no-signal-remote-clean-apply --dry-run ' . $base,
			'remote_clean_apply'        => 'studio wp datamachine-code workspace worktree active-no-signal-remote-clean-apply ' . $base,
		);
	}
}
