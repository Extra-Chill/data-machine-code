<?php
/**
 * Disposition decisions shared by WordPress and standalone worktree planning.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreePlanDecision {

	/**
	 * @param array<string,mixed>|null $legacy_handoff
	 */
	public static function existing( bool $exact, bool $owner_conflict, bool $adoptable, ?array $legacy_handoff ): string {
		$disposition = $exact ? 'exact_reuse' : ( $adoptable ? 'adoptable' : 'unsafe' );
		if ( $exact && $owner_conflict ) {
			$disposition = 'owner_conflict';
		}
		if ( 'legacy_handoff_required' === ( $legacy_handoff['status'] ?? null ) ) {
			return 'legacy_handoff_required';
		}
		return $disposition;
	}

	/**
	 * @param array<string,mixed>      $capacity
	 * @param array<int,mixed>         $candidates
	 * @param array<string,mixed>      $intent
	 * @param array<string,mixed>|null $legacy_handoff
	 */
	public static function create( array $capacity, array $candidates, string $reuse_policy, array $intent, ?array $legacy_handoff ): string {
		$disposition = 'create';
		if ( 'refused' === ( $capacity['status'] ?? '' ) ) {
			$disposition = 'capacity_blocked';
		} elseif ( array() !== $candidates && 'isolated' !== $reuse_policy ) {
			$disposition = 'owner_conflict';
		} elseif ( array() !== $candidates && self::missing_isolation_intent($intent) ) {
			$disposition = 'unsafe';
		}
		if ( 'legacy_handoff_required' === ( $legacy_handoff['status'] ?? null ) ) {
			return 'legacy_handoff_required';
		}
		return $disposition;
	}

	/** @param array<string,mixed> $intent */
	public static function missing_isolation_intent( array $intent ): bool {
		return null === WorktreePlanPolicy::scalar($intent['purpose'] ?? null)
			|| null === WorktreePlanPolicy::scalar($intent['owner_run_ref'] ?? null)
			|| WorktreePlanPolicy::CLEANUP_POLICY_REMOVE_ON_SUCCESS !== ( $intent['cleanup_policy'] ?? null );
	}
}
