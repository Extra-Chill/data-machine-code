<?php
/**
 * Generic worktree plan policy and apply-intent identity.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreePlanPolicy {

	public const REUSE_POLICIES = array( 'reuse_compatible', 'isolated', 'recycle_terminal', 'claim_expired' );

	public const CLEANUP_POLICY_MANUAL              = 'manual';
	public const CLEANUP_POLICY_REMOVE_ON_SUCCESS   = 'remove_on_success';
	public const CLEANUP_POLICY_PRESERVE_ON_FAILURE = 'preserve_on_failure';
	public const CLEANUP_POLICIES                   = array(
		self::CLEANUP_POLICY_MANUAL,
		self::CLEANUP_POLICY_REMOVE_ON_SUCCESS,
		self::CLEANUP_POLICY_PRESERVE_ON_FAILURE,
	);
	public const SAME_TASK_CANDIDATE_LIMIT = 5;

	public static function scalar( mixed $value ): ?string {
		if ( ! is_scalar($value) ) {
			return null;
		}
		$value = trim((string) $value);
		return '' === $value ? null : $value;
	}

	public static function normalize_cleanup_policy( mixed $policy ): ?string {
		$policy = self::scalar($policy);
		if ( null === $policy ) {
			return null;
		}
		$policy = strtolower($policy);
		return in_array($policy, self::CLEANUP_POLICIES, true) ? $policy : null;
	}

	/**
	 * @param array<string,mixed> $intent
	 * @return array<string,string>
	 */
	public static function normalize_intent( array $intent ): array {
		return array_filter(
			array(
				'purpose'        => self::optional_intent_value($intent['purpose'] ?? null),
				'owner_run_ref'  => self::optional_intent_value($intent['owner_run_ref'] ?? null),
				'cleanup_policy' => self::normalize_cleanup_policy($intent['cleanup_policy'] ?? null),
			),
			static fn( $value ) => null !== $value
		);
	}

	/** @param array<string,mixed> $task */
	public static function task_identity( array $task ): string {
		return isset($task['task_url']) ? (string) $task['task_url'] : strtolower((string) ( $task['task_ref'] ?? '' ));
	}

	/**
	 * @param array<string,mixed> $task
	 * @return array<string,string>
	 */
	public static function normalize_task( array $task ): array {
		$normalized = array();
		$url        = TaskUrl::canonicalize_for_replay($task['task_url'] ?? null);
		if ( null !== $url ) {
			$normalized['task_url'] = $url;
		}
		$ref = self::scalar($task['task_ref'] ?? null);
		if ( null !== $ref && ! preg_match('/\s/', $ref) ) {
			$normalized['task_ref'] = strtolower($ref);
		}
		return $normalized;
	}

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $intent
	 * @return array<string,mixed>
	 */
	public static function apply_intent(
		string $repo,
		string $branch,
		?string $from,
		bool $inject_context,
		bool $bootstrap,
		bool $allow_stale,
		bool $rebase_base,
		array $task,
		array $intent,
		string $reuse_policy,
		bool $allow_unverified_freshness = false,
		bool $require_task_tracker = true,
		bool $force = false,
		bool $allow_percentage_byte_floor_exception = false
	): array {
		if ( isset($task['task_url']) ) {
			$task_url = TaskUrl::canonicalize_for_replay($task['task_url']);
			if ( null === $task_url ) {
				unset($task['task_url']);
			} else {
				$task['task_url'] = $task_url;
			}
		}
		return array(
			'repo'                                  => $repo,
			'branch'                                => $branch,
			'from'                                  => $from,
			'inject_context'                        => $inject_context,
			'bootstrap'                             => $bootstrap,
			'allow_stale'                           => $allow_stale,
			'rebase_base'                           => $rebase_base,
			'task'                                  => $task,
			'intent'                                => $intent,
			'reuse_policy'                          => $reuse_policy,
			'allow_unverified_freshness'            => $allow_unverified_freshness,
			'require_task_tracker'                  => $require_task_tracker,
			'force'                                 => $force,
			'allow_percentage_byte_floor_exception' => $allow_percentage_byte_floor_exception,
		);
	}

	private static function optional_intent_value( mixed $value ): ?string {
		return self::scalar($value);
	}
}
