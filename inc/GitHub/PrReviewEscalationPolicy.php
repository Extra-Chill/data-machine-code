<?php
/**
 * GitHub PR review escalation policy.
 *
 * @package DataMachineCode\GitHub
 */

namespace DataMachineCode\GitHub;

defined( 'ABSPATH' ) || exit;

/**
 * Decides when a PR review needs checkout-backed validation.
 */
class PrReviewEscalationPolicy {

	private const SCHEMA  = 'data-machine-code/pr-review-escalation/v1';
	private const VERSION = 'v1';

	private const DEFAULT_MAX_FILES         = 20;
	private const DEFAULT_MAX_PATCH_BYTES   = 100000;
	private const DEFAULT_MAX_TOTAL_CHANGES = 1000;

	/**
	 * Evaluate checkout-backed validation risk signals.
	 *
	 * @param string $repo Repository in owner/repo format.
	 * @param array  $pull Normalized pull request payload.
	 * @param array  $files Normalized review-context changed files.
	 * @param array  $checks Optional check/Homeboy context.
	 * @param array  $options Optional thresholds.
	 * @return array<string,mixed>
	 */
	public static function evaluate( string $repo, array $pull, array $files, array $checks = array(), array $options = array() ): array {
		$head_sha = (string) ( $pull['head_sha'] ?? '' );
		$reasons  = array();
		$metrics  = array(
			'file_count'     => count( $files ),
			'patch_bytes'    => 0,
			'total_changes'  => 0,
			'touched_groups' => array(),
		);

		$thresholds = array(
			'max_files'         => max( 1, (int) ( $options['max_files'] ?? self::DEFAULT_MAX_FILES ) ),
			'max_patch_bytes'   => max( 1, (int) ( $options['max_patch_bytes'] ?? self::DEFAULT_MAX_PATCH_BYTES ) ),
			'max_total_changes' => max( 1, (int) ( $options['max_total_changes'] ?? self::DEFAULT_MAX_TOTAL_CHANGES ) ),
		);

		foreach ( $files as $file ) {
			$path = (string) ( $file['filename'] ?? '' );
			if ( '' === $path ) {
				continue;
			}

			$metrics['patch_bytes']   += (int) ( $file['patch_bytes'] ?? strlen( (string) ( $file['patch'] ?? '' ) ) );
			$metrics['total_changes'] += (int) ( $file['changes'] ?? 0 );

			foreach ( self::classifyPath( $path ) as $group => $reason ) {
				$metrics['touched_groups'][ $group ] = true;
				$reasons[]                           = $reason;
			}
		}

		if ( $metrics['file_count'] > $thresholds['max_files'] ) {
			$reasons[] = 'file_count_threshold';
		}

		if ( $metrics['patch_bytes'] > $thresholds['max_patch_bytes'] ) {
			$reasons[] = 'patch_size_threshold';
		}

		if ( $metrics['total_changes'] > $thresholds['max_total_changes'] ) {
			$reasons[] = 'change_count_threshold';
		}

		$reasons = array_merge( $reasons, self::checkReasons( $checks, $head_sha ) );
		$reasons = array_values( array_unique( array_filter( $reasons ) ) );

		$metrics['touched_groups'] = array_keys( $metrics['touched_groups'] );

		return array(
			'schema'                  => self::SCHEMA,
			'should_escalate'         => ! empty( $reasons ),
			'reasons'                 => $reasons,
			'policy_version'          => self::VERSION,
			'action_recommendation'   => ! empty( $reasons ) ? 'run_checkout_backed_validation_when_available' : 'github_context_only_review_is_sufficient',
			'execution_result_source' => ! empty( $reasons ) ? 'not_run_issue_111_pending' : 'not_required',
			'thresholds'              => $thresholds,
			'metrics'                 => $metrics,
			'repo'                    => $repo,
			'head_sha'                => $head_sha,
		);
	}

	/**
	 * Classify a changed path into risk buckets.
	 *
	 * @return array<string,string> Map of bucket => reason.
	 */
	private static function classifyPath( string $path ): array {
		$path_lower = strtolower( $path );
		$groups     = array();

		if (
			preg_match( '#(^|/)(auth|permissions?|secrets?|tokens?|webhooks?)(/|[-_.]|$)#', $path_lower )
			|| preg_match( '#(credential|hmac|oauth|jwt|permission|capabilit|secret|token|webhook)#', $path_lower )
		) {
			$groups['webhook_auth'] = 'touches_webhook_auth';
		}

		if (
			preg_match( '#(^|/)(migrations?|schema|jobs?|queues?|action-scheduler|actionscheduler)(/|[-_.]|$)#', $path_lower )
			|| preg_match( '#(migration|schema|job|queue|action_scheduler|actionscheduler)#', $path_lower )
		) {
			$groups['migration_jobs_queue'] = 'touches_migrations_jobs_queues';
		}

		if (
			preg_match( '#(^|/)(inc/abilities|inc/tools|inc/cli|inc/rest|rest|cli|abilities?|tools?)(/|[-_.]|$)#', $path_lower )
		) {
			$groups['public_api'] = 'touches_public_api_surface';
		}

		if (
			preg_match( '#(^|/)(readme|docs?|documentation|examples?|assets|templates?)(/|[-_.]|$)#', $path_lower )
			|| preg_match( '#\.(md|mdx|txt|rst)$#', $path_lower )
		) {
			$groups['docs_user_facing'] = 'touches_docs_user_facing_content';
		}

		return $groups;
	}

	/**
	 * Convert check/Homeboy context into escalation reasons.
	 *
	 * @return string[]
	 */
	private static function checkReasons( array $checks, string $head_sha ): array {
		$reasons = array();

		$homeboy = $checks['homeboy_ci_results'] ?? null;
		if ( is_array( $homeboy ) && ! empty( $homeboy ) ) {
			$artifact_sha = (string) ( $homeboy['workflow']['head_sha'] ?? $homeboy['head_sha'] ?? '' );
			if ( '' !== $head_sha && '' !== $artifact_sha && $head_sha !== $artifact_sha ) {
				$reasons[] = 'stale_homeboy_artifact';
			}
		} else {
			$homeboy_error = (string) ( $checks['error_codes']['homeboy_ci_results'] ?? '' );
			$reasons[]     = str_contains( $homeboy_error, 'expired' ) || str_contains( $homeboy_error, 'stale' ) ? 'stale_homeboy_artifact' : 'missing_homeboy_artifact';
		}

		foreach ( array( 'check_runs', 'commit_statuses' ) as $key ) {
			$state = (string) ( $checks[ $key ]['summary']['state'] ?? '' );
			if ( in_array( $state, array( 'failure', 'pending', 'cancelled' ), true ) ) {
				$reasons[] = 'failing_or_unknown_checks';
			}
		}

		if ( isset( $checks['errors']['check_runs'] ) || isset( $checks['errors']['commit_statuses'] ) ) {
			$reasons[] = 'failing_or_unknown_checks';
		}

		return $reasons;
	}
}
