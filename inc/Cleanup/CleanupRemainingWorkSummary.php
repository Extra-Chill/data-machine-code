<?php
/**
 * Compact cleanup remaining-work summaries.
 *
 * @package DataMachineCode\Cleanup
 */

namespace DataMachineCode\Cleanup;

defined('ABSPATH') || exit;

class CleanupRemainingWorkSummary {



	private const EXAMPLE_LIMIT              = 3;
	private const METADATA_RECONCILE_COMMAND = 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json';

	/**
	 * Build a concise summary from DB-backed cleanup items.
	 *
	 * @param  array<int,array<string,mixed>> $items Cleanup items.
	 * @return array<string,mixed>
	 */
	public static function from_items( array $items ): array {
		$summary = self::empty_summary();

		foreach ( $items as $item ) {
			$type     = (string) ( $item['item_type'] ?? 'unknown' );
			$status   = (string) ( $item['status'] ?? 'unknown' );
			$evidence = (array) ( $item['evidence'] ?? array() );
			$row      = array_merge($evidence, $item);

			if ( 'applied' === $status ) {
				self::increment_type($summary['applied_by_type'], $type, (int) ( $item['bytes_reclaimed'] ?? 0 ));
				continue;
			}

			if ( 'skipped' === $status || 'failed' === $status ) {
				self::increment_reason($summary['skipped_by_reason'], self::reason_code($item), $row);
			}

			if ( 'blocked' === $status || 'resolver' === $type ) {
				self::increment_reason($summary['blocked_resolvers_by_reason'], self::reason_code($item), $row);
			}

			if ( 'artifact_cleanup' === $type && 'applied' !== $status ) {
				$summary['remaining_reclaimable_artifact_bytes'] += self::row_bytes($row, array( 'artifact_size_bytes', 'size_bytes' ));
			}
			if ( 'worktree_removal' === $type && in_array($status, array( 'pending', 'failed', 'applying' ), true) ) {
				++$summary['remaining_safely_removable_worktrees'];
			}
		}

		return self::finalize($summary);
	}

	/**
	 * Build a concise summary from job-backed cleanup run aggregates.
	 *
	 * @param  array<string,mixed> $aggregate Cleanup child aggregate.
	 * @return array<string,mixed>
	 */
	public static function from_job_aggregate( array $aggregate ): array {
		$summary = self::empty_summary();
		$items   = (array) ( $aggregate['cleanup_items'] ?? array() );

		foreach ( (array) ( $items['by_type'] ?? array() ) as $type => $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			self::increment_type($summary['applied_by_type'], (string) $type, (int) ( $row['bytes_reclaimed'] ?? 0 ), (int) ( $row['applied_rows'] ?? 0 ));
		}

		foreach ( (array) ( $items['skipped_examples_by_reason'] ?? array() ) as $reason => $bucket ) {
			self::copy_reason_bucket($summary['skipped_by_reason'], (string) $reason, (array) $bucket);
		}
		foreach ( (array) ( $items['failed_examples_by_reason'] ?? array() ) as $reason => $bucket ) {
			self::copy_reason_bucket($summary['skipped_by_reason'], (string) $reason, (array) $bucket);
		}

		$summary['remaining_reclaimable_artifact_bytes'] = max(0, (int) ( $items['remaining_reclaimable_artifact_bytes'] ?? 0 ));
		$summary['remaining_safely_removable_worktrees'] = max(0, (int) ( $items['remaining_safely_removable_worktrees'] ?? 0 ));

		return self::finalize($summary);
	}

	private static function empty_summary(): array {
		return array(
			'applied_by_type'                      => array(),
			'skipped_by_reason'                    => array(),
			'blocked_resolvers_by_reason'          => array(),
			'total_bytes_reclaimed'                => 0,
			'remaining_reclaimable_artifact_bytes' => 0,
			'remaining_safely_removable_worktrees' => 0,
			'remaining_safe_candidates'            => 0,
			'protected_unpushed_candidates'        => 0,
			'recommended_commands'                 => array(),
			'next_commands'                        => array(),
		);
	}

	private static function increment_type( array &$types, string $type, int $bytes, int $count = 1 ): void {
		if ( '' === $type ) {
			$type = 'unknown';
		}
		$types[ $type ]                   ??= array(
			'count'           => 0,
			'bytes_reclaimed' => 0,
		);
		$types[ $type ]['count']           += max(0, $count);
		$types[ $type ]['bytes_reclaimed'] += max(0, $bytes);
	}

	private static function increment_reason( array &$reasons, string $reason, array $row ): void {
		$reasons[ $reason ] ??= array(
			'count'    => 0,
			'examples' => array(),
		);
		++$reasons[ $reason ]['count'];
		if ( count($reasons[ $reason ]['examples']) < self::EXAMPLE_LIMIT ) {
			$reasons[ $reason ]['examples'][] = self::example_row($row);
		}
	}

	private static function copy_reason_bucket( array &$reasons, string $reason, array $bucket ): void {
		if ( '' === $reason ) {
			$reason = 'unknown';
		}
		$reasons[ $reason ]         ??= array(
			'count'    => 0,
			'examples' => array(),
		);
		$reasons[ $reason ]['count'] += max(0, (int) ( $bucket['count'] ?? 0 ));
		foreach ( (array) ( $bucket['examples'] ?? array() ) as $example ) {
			if ( count($reasons[ $reason ]['examples']) >= self::EXAMPLE_LIMIT ) {
				break;
			}
			$reasons[ $reason ]['examples'][] = is_array($example) ? self::example_row($example) : array( 'handle' => (string) $example );
		}
	}

	private static function reason_code( array $row ): string {
		$reason = (string) ( $row['reason_code'] ?? $row['reason'] ?? 'unknown' );
		return '' === $reason ? 'unknown' : $reason;
	}

	private static function example_row( array $row ): array {
		$example = array(
			'handle' => (string) ( $row['handle'] ?? '' ),
		);
		foreach ( array( 'repo', 'branch', 'reason', 'path' ) as $field ) {
			if ( isset($row[ $field ]) && '' !== (string) $row[ $field ] ) {
				$example[ $field ] = (string) $row[ $field ];
			}
		}
		return $example;
	}

	private static function row_bytes( array $row, array $fields ): int {
		foreach ( $fields as $field ) {
			if ( isset($row[ $field ]) ) {
				return max(0, (int) $row[ $field ]);
			}
		}
		$total = 0;
		foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
			$total += max(0, (int) ( is_array($artifact) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ));
		}
		return $total;
	}

	private static function finalize( array $summary ): array {
		ksort($summary['applied_by_type']);
		ksort($summary['skipped_by_reason']);
		ksort($summary['blocked_resolvers_by_reason']);
		$summary['total_bytes_reclaimed']         = self::total_applied_bytes( (array) $summary['applied_by_type']);
		$summary['remaining_safe_candidates']     = (int) ( $summary['remaining_safely_removable_worktrees'] ?? 0 );
		$summary['protected_unpushed_candidates'] = self::reason_count($summary, 'unpushed_commits');
		$summary['recommended_commands']          = self::recommended_commands($summary);
		$summary['next_commands']                 = self::next_commands( (array) $summary['recommended_commands']);
		return $summary;
	}

	private static function total_applied_bytes( array $types ): int {
		$total = 0;
		foreach ( $types as $row ) {
			$total += max(0, (int) ( is_array($row) ? ( $row['bytes_reclaimed'] ?? 0 ) : 0 ));
		}
		return $total;
	}

	private static function reason_count( array $summary, string $reason ): int {
		$total = 0;
		foreach ( array( 'skipped_by_reason', 'blocked_resolvers_by_reason' ) as $bucket ) {
			$row    = (array) ( $summary[ $bucket ][ $reason ] ?? array() );
			$total += max(0, (int) ( $row['count'] ?? 0 ));
		}
		return $total;
	}

	private static function next_commands( array $commands ): array {
		$next = array();
		foreach ( $commands as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			foreach ( array( 'command', 'apply', 'alternative' ) as $field ) {
				$value = (string) ( $row[ $field ] ?? '' );
				if ( '' !== $value ) {
					$next[] = $value;
				}
			}
		}
		return array_values(array_unique($next));
	}

	private static function recommended_commands( array $summary ): array {
		$commands = array();
		if ( (int) $summary['remaining_reclaimable_artifact_bytes'] > 0 ) {
			$commands[] = array(
				'bucket'            => 'remaining_artifacts',
				'command'           => 'studio wp datamachine-code workspace cleanup run --mode=artifacts --dry-run --format=json',
				'apply'             => 'studio wp datamachine-code workspace cleanup run --mode=artifacts',
				'destructive'       => false,
				'apply_destructive' => true,
				'why'               => 'Preview remaining artifact cleanup first; the apply command removes revalidated artifacts.',
			);
		}
		if ( (int) $summary['remaining_safely_removable_worktrees'] > 0 ) {
			$commands[] = array(
				'bucket'            => 'remaining_worktrees',
				'command'           => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25',
				'apply'             => 'studio wp datamachine-code workspace cleanup run --mode=retention',
				'destructive'       => false,
				'apply_destructive' => true,
				'why'               => 'Preview cleanup-eligible worktrees first; the apply command removes revalidated worktrees.',
			);
		}
		foreach ( array_keys( (array) $summary['skipped_by_reason'] ) as $reason ) {
			$commands[] = self::command_for_reason( (string) $reason, 'skipped');
		}
		foreach ( array_keys( (array) $summary['blocked_resolvers_by_reason'] ) as $reason ) {
			$commands[] = self::command_for_reason( (string) $reason, 'blocked_resolver');
		}

		$seen = array();
		return array_values(array_filter(
			$commands,
			function ( array $command ) use ( &$seen ): bool {
				$key = (string) ( $command['bucket'] ?? '' ) . '|' . (string) ( $command['command'] ?? '' );
				if ( isset($seen[ $key ]) ) {
					return false;
				}
				$seen[ $key ] = true;
				return true;
			}
		));
	}

	private static function command_for_reason( string $reason, string $bucket ): array {
		$command = match ( $reason ) {
			'needs_metadata_reconcile', 'lifecycle_reconciliation_candidate', 'repaired_metadata' => self::METADATA_RECONCILE_COMMAND,
			'dirty_worktree' => 'git -C <worktree-path> status --short --branch --untracked-files=normal',
			'unpushed_commits' => 'git -C <worktree-path> log --oneline --decorate @{u}..HEAD',
			'stale_worktree_marker' => 'git -C <primary-path> worktree prune --dry-run --verbose',
			'primary_missing' => 'studio wp datamachine-code workspace show <repo>',
			'probe_timeout', 'plan_mismatch' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --format=json',
			'artifact_already_removed', 'artifact_plan_mismatch' => 'studio wp datamachine-code workspace cleanup run --mode=artifacts --dry-run --format=json',
			default => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --format=json',
		};

		$entry = array(
			'bucket'            => $bucket . ':' . $reason,
			'command'           => $command,
			'destructive'       => false,
			'apply_destructive' => false,
			'why'               => self::reason_remediation($reason),
		);

		$alternative = self::reason_alternative($reason);
		if ( '' !== $alternative ) {
			$entry['alternative'] = $alternative;
		}

		return $entry;
	}

	private static function reason_remediation( string $reason ): string {
		return match ( $reason ) {
			'dirty_worktree' => 'Inspect dirty files before applying cleanup; classify artifact-only dirt versus source edits before preserving, committing, or cleaning up.',
			'artifact_plan_mismatch', 'plan_mismatch' => 'Regenerate a fresh plan because the saved row no longer matches current filesystem or branch state.',
			'artifact_plan_not_current', 'artifact_already_removed' => 'Regenerate artifact cleanup evidence; the saved artifact row is no longer a current candidate.',
			'needs_metadata_reconcile' => 'Run metadata reconciliation so DMC can classify the worktree without a full cleanup scan.',
			'lifecycle_reconciliation_candidate' => 'Run lifecycle reconciliation to collect PR/merge signals before emitting removal rows.',
			'unpushed_commits' => 'Inspect commits ahead of upstream so the operator can push, merge, preserve, or intentionally abandon before retrying cleanup.',
			'stale_worktree_marker' => 'Preview stale git worktree metadata pruning and repair registry metadata only after confirming the marker is stale.',
			'primary_missing' => 'Recover, adopt, or recreate the missing primary checkout before worktree removal can be routed through git safely.',
			'probe_timeout' => 'Retry the review path with a smaller bounded page or investigate the git probe timeout.',
			default => 'Run the review command to refresh evidence before applying cleanup.',
		};
	}

	private static function reason_alternative( string $reason ): string {
		return match ( $reason ) {
			'dirty_worktree' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=dirty_worktree --verbose --format=json',
			'unpushed_commits' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=unpushed_commits --verbose --format=json',
			'stale_worktree_marker' => self::METADATA_RECONCILE_COMMAND,
			'primary_missing' => 'If the checkout is gone, recreate it with `studio wp datamachine-code workspace clone <remote-url> --name=<repo>` or adopt the existing primary checkout with `studio wp datamachine-code workspace adopt <path> --name=<repo>`.',
			default => '',
		};
	}
}
