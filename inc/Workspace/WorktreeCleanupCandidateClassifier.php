<?php
/**
 * Worktree cleanup candidate classification.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeCleanupCandidateClassifier {

	/**
	 * Classify one clean, non-primary worktree after safety probes and signal detection.
	 *
	 * @param  array<string,mixed>      $context                   Normalized worktree context.
	 * @param  array<string,mixed>|null $signal                    Cleanup signal, if any.
	 * @param  array<string,mixed>|null $age_filter                Mutable age filter summary.
	 * @param  callable                 $no_merge_signal_evidence  Lazy evidence callback for no-signal rows.
	 * @param  array<string,string>     $active_review_commands    Review/apply commands for no-signal rows.
	 * @return array{type:string,row:array<string,mixed>}
	 */
	public static function classify_merge_signal_path( array $context, ?array $signal, ?array &$age_filter, callable $no_merge_signal_evidence, array $active_review_commands ): array {
		$base        = self::base_row($context);
		$disk_fields = is_array($context['disk_fields'] ?? null) ? $context['disk_fields'] : array();

		if ( null === $signal ) {
			return self::skip(
				array_merge(
					$base,
					array(
						'reason_code'            => 'no_merge_signal',
						'reason'                 => 'no merge signal — leaving in place',
						'merge_signal_evidence'  => $no_merge_signal_evidence(),
						'active_review_command'  => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=25 --offset=0 --format=json',
						'active_review_commands' => $active_review_commands,
					),
					$disk_fields
				)
			);
		}

		if ( 'probe-timeout' === (string) ( $signal['signal'] ?? '' ) ) {
			return self::skip(
				array_merge(
					$base,
					array(
						'reason_code' => 'probe_timeout',
						'reason'      => (string) ( $signal['reason'] ?? '' ),
					),
					$disk_fields
				)
			);
		}

		if ( 'github-unknown' === (string) ( $signal['signal'] ?? '' ) ) {
			return self::skip(
				array_merge(
					$base,
					array(
						'reason_code'            => 'github_unknown',
						'reason'                 => (string) ( $signal['reason'] ?? '' ),
						'merge_signal_evidence'  => array(
							'classification' => 'github_signal_unavailable',
							'github_signal'  => 'unavailable',
							'reason'         => (string) ( $signal['reason'] ?? '' ),
						),
						'active_review_command'  => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=25 --offset=0 --format=json',
						'active_review_commands' => $active_review_commands,
					),
					$disk_fields
				)
			);
		}

		if ( 'remote-tracking-clean' === (string) ( $signal['signal'] ?? '' ) && ! self::has_removable_lifecycle($context['metadata'] ?? null) ) {
			return self::skip(
				array_merge(
					$base,
					array(
						'row_type'                   => 'protected',
						'reason_code'                => 'active_lifecycle',
						'protecting_reason'          => 'active_lifecycle',
						'reason'                     => 'remote-tracking-clean is not a standalone destructive cleanup signal; lifecycle must be finalized, abandoned, or cleanup_eligible first',
						'merge_signal_evidence'      => $signal,
						'active_review_command'      => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=25 --offset=0 --format=json',
						'active_review_commands'     => $active_review_commands,
						'remote_tracking_clean_only' => true,
					),
					$disk_fields
				)
			);
		}

		$age_decision = null;
		if ( null !== $age_filter ) {
			$age_decision = WorktreeAgeFilter::decide($context['created_at'] ?? null, $age_filter);
			if ( in_array( (string) ( $age_decision['decision'] ?? '' ), array( 'unknown_age', 'excluded' ), true) ) {
				return self::skip(
					array_merge(
						$base,
						WorktreeAgeFilter::skip_fields($age_decision),
						$disk_fields
					)
				);
			}
		}

		$candidate = array_merge(
			$base,
			array(
				'dirty'           => (int) ( $context['dirty_count'] ?? 0 ),
				'unpushed'        => 0,
				'cleanup_reasons' => array_values(array_filter(array( $signal['signal'] ?? '', $signal['reason'] ?? '' ))),
				'liveness'        => (string) ( $context['liveness'] ?? '' ),
			),
			WorktreeCleanupSignal::candidate_fields($signal, true),
			$disk_fields
		);
		if ( null !== $age_decision ) {
			$candidate['age_filter'] = $age_decision['age_filter'];
		}

		return array(
			'type' => 'candidate',
			'row'  => $candidate,
		);
	}

	/**
	 * Build common row fields used by this cleanup path.
	 *
	 * @param  array<string,mixed> $context Normalized worktree context.
	 * @return array<string,mixed>
	 */
	private static function base_row( array $context ): array {
		return array(
			'handle'     => (string) ( $context['handle'] ?? '' ),
			'repo'       => (string) ( $context['repo'] ?? '' ),
			'branch'     => (string) ( $context['branch'] ?? '' ),
			'path'       => (string) ( $context['path'] ?? '' ),
			'created_at' => $context['created_at'] ?? null,
			'metadata'   => $context['metadata'] ?? null,
		);
	}

	/**
	 * Whether lifecycle metadata explicitly allows retention cleanup to remove a worktree.
	 *
	 * @param  mixed $metadata Worktree metadata.
	 * @return bool
	 */
	private static function has_removable_lifecycle( mixed $metadata ): bool {
		if ( ! is_array($metadata) ) {
			return false;
		}

		$state           = isset($metadata['lifecycle_state']) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state']) : null;
		$finalized_state = isset($metadata['finalized_state']) ? WorktreeContextInjector::normalize_state( (string) $metadata['finalized_state']) : null;
		$removable       = array(
			WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
			WorktreeContextInjector::STATE_MERGED,
			WorktreeContextInjector::STATE_CLOSED,
			WorktreeContextInjector::STATE_ABANDONED,
		);

		return in_array($state, $removable, true) || in_array($finalized_state, $removable, true);
	}

	/**
	 * Wrap a skip row in the classifier result shape.
	 *
	 * @param  array<string,mixed> $row Skip row.
	 * @return array{type:string,row:array<string,mixed>}
	 */
	private static function skip( array $row ): array {
		return array(
			'type' => 'skip',
			'row'  => $row,
		);
	}
}
