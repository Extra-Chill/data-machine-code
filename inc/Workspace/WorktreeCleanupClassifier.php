<?php
/**
 * Worktree cleanup classification.
 *
 * Centralizes the stable reason-code to bucket mapping used by worktree cleanup
 * reports, cleanup plans, and resolver rows. This class is intentionally
 * non-mutating: apply paths still perform their own fresh safety revalidation.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeCleanupClassifier {

	public const BUCKET_SAFE_TO_REMOVE_NOW           = 'safe_to_remove_now';
	public const BUCKET_CLEANUP_ELIGIBLE_UNPROBED    = 'cleanup_eligible_pending_revalidation';
	public const BUCKET_NEEDS_RECONCILIATION         = 'needs_reconciliation';
	public const BUCKET_NEEDS_FULL_REVIEW            = 'needs_full_review';
	public const BUCKET_BLOCKED_BY_DIRTY_OR_UNPUSHED = 'blocked_by_dirty_or_unpushed';
	public const BUCKET_ARTIFACT_ONLY_DIRTY          = 'artifact_only_dirty_worktree';
	public const BUCKET_INTENTIONAL_TRIAGE           = 'intentional_triage';

	/**
	 * Reason codes that indicate metadata/lifecycle reconciliation should run
	 * before cleanup eligibility can be decided.
	 *
	 * @var string[]
	 */
	private const RECONCILIATION_REASONS = array(
		'needs_metadata_reconcile',
		'requires_full_scan',
		'missing_metadata',
		'lifecycle_reconciliation_candidate',
	);

	/**
	 * Reason codes that are not deletion candidates and require human/full review.
	 *
	 * @var string[]
	 */
	private const FULL_REVIEW_REASONS = array(
		'live_worktree',
		'active_no_signal',
		'no_inventory_cleanup_signal',
		'no_merge_signal',
		'github_unknown',
		'external_worktree',
		'protected_branch',
		'protected_base_branch_worktree',
		'detached_worktree',
		'detached_protected_branch',
		'submodule_worktree',
		'probe_timeout',
		'unknown_age',
	);

	/**
	 * Reason codes blocked by uncommitted or unpushed user work.
	 *
	 * @var string[]
	 */
	private const DIRTY_OR_UNPUSHED_REASONS = array(
		'dirty_worktree',
		'merged_pr_with_only_obsolete_dirty_changes',
		'unpushed_commits',
	);

	/**
	 * Reason codes intentionally resolved by operator triage metadata.
	 *
	 * @var string[]
	 */
	private const TRIAGE_REASONS = array(
		'triage_ignored',
		'triage_quarantined',
	);

	/**
	 * Reason codes that can generate read-only resolver plan rows.
	 *
	 * @var string[]
	 */
	private const RESOLVER_REASONS = array(
		'needs_metadata_reconcile',
		'requires_full_scan',
		'lifecycle_reconciliation_candidate',
		'active_no_signal',
		'no_inventory_cleanup_signal',
	);

	/**
	 * Classify one skipped reason code into a stable high-level cleanup bucket.
	 */
	public static function bucket_for_reason( string $reason_code ): string {
		if ( 'artifact_only_dirty_worktree' === $reason_code ) {
			return self::BUCKET_ARTIFACT_ONLY_DIRTY;
		}

		if ( in_array($reason_code, self::DIRTY_OR_UNPUSHED_REASONS, true) ) {
			return self::BUCKET_BLOCKED_BY_DIRTY_OR_UNPUSHED;
		}

		if ( in_array($reason_code, self::RECONCILIATION_REASONS, true) ) {
			return self::BUCKET_NEEDS_RECONCILIATION;
		}

		if ( in_array($reason_code, self::TRIAGE_REASONS, true) ) {
			return self::BUCKET_INTENTIONAL_TRIAGE;
		}

		if ( in_array($reason_code, self::FULL_REVIEW_REASONS, true) ) {
			return self::BUCKET_NEEDS_FULL_REVIEW;
		}

		return self::BUCKET_NEEDS_FULL_REVIEW;
	}

	/**
	 * Build stable high-level bucket counts from cleanup summary primitives.
	 *
	 * @param  int               $candidate_count      Candidate row count.
	 * @param  array<string,int> $candidates_by_signal Candidate signal counts.
	 * @param  array<string,int> $skipped_by_reason    Skipped reason counts.
	 * @param  string            $candidate_bucket     Bucket to use for candidate rows.
	 * @return array<string,int>
	 */
	public static function buckets(
		int $candidate_count,
		array $candidates_by_signal,
		array $skipped_by_reason,
		string $candidate_bucket = self::BUCKET_SAFE_TO_REMOVE_NOW
	): array {
		$buckets                      = array(
			self::BUCKET_ARTIFACT_ONLY_DIRTY          => 0,
			self::BUCKET_BLOCKED_BY_DIRTY_OR_UNPUSHED => 0,
			self::BUCKET_CLEANUP_ELIGIBLE_UNPROBED    => 0,
			self::BUCKET_NEEDS_FULL_REVIEW            => 0,
			self::BUCKET_NEEDS_RECONCILIATION         => 0,
			self::BUCKET_SAFE_TO_REMOVE_NOW           => 0,
		);
		$buckets[ $candidate_bucket ] = ( $buckets[ $candidate_bucket ] ?? 0 ) + $candidate_count;

		foreach ( $skipped_by_reason as $reason_code => $count ) {
			$bucket             = self::bucket_for_reason( (string) $reason_code );
			$buckets[ $bucket ] = ( $buckets[ $bucket ] ?? 0 ) + (int) $count;
		}

		$buckets['explicit_cleanup_candidates']         = (int) ( $candidates_by_signal['cleanup_eligible'] ?? 0 );
		$buckets['lifecycle_reconciliation_candidates'] = (int) ( $skipped_by_reason['lifecycle_reconciliation_candidate'] ?? 0 );
		$buckets['metadata_reconciliation_candidates']  = (int) ( $skipped_by_reason['needs_metadata_reconcile'] ?? 0 ) + (int) ( $skipped_by_reason['requires_full_scan'] ?? 0 ) + (int) ( $skipped_by_reason['missing_metadata'] ?? 0 );
		$buckets['dirty_unpushed']                      = $buckets[ self::BUCKET_BLOCKED_BY_DIRTY_OR_UNPUSHED ];
		$buckets['active_no_signal']                    = (int) ( $skipped_by_reason['active_no_signal'] ?? 0 ) + (int) ( $skipped_by_reason['no_inventory_cleanup_signal'] ?? 0 );
		$buckets['intentional_triage']                  = (int) ( $skipped_by_reason['triage_ignored'] ?? 0 ) + (int) ( $skipped_by_reason['triage_quarantined'] ?? 0 );

		ksort($buckets);
		return $buckets;
	}

	/**
	 * Whether a skip row reason can produce a read-only resolver plan row.
	 */
	public static function is_resolver_reason( string $reason_code ): bool {
		return in_array($reason_code, self::RESOLVER_REASONS, true);
	}

	/**
	 * Return resolver type for a skip reason.
	 */
	public static function resolver_type( string $reason_code ): string {
		return match ( $reason_code ) {
			'needs_metadata_reconcile', 'requires_full_scan' => 'metadata_reconciliation',
			'lifecycle_reconciliation_candidate' => 'lifecycle_reconciliation',
			default => 'merge_signal',
		};
	}
}
