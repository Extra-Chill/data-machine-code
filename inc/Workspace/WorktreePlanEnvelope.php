<?php
/**
 * Digest-addressed worktree plan envelope shared by WordPress and standalone surfaces.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreePlanEnvelope {

	public const SCHEMA        = 'datamachine-code/worktree-plan/v1';
	public const APPLY_ABILITY = 'datamachine-code/workspace-worktree-apply-plan';

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $evidence
	 * @return array<string,mixed>
	 */
	public static function build( array $input, string $handle, string $path, string $slug, string $disposition, array $evidence ): array {
		$plan        = array(
			'schema'       => self::SCHEMA,
			'version'      => 1,
			'handle'       => $handle,
			'path'         => $path,
			'branch'       => $input['branch'],
			'slug'         => $slug,
			'disposition'  => $disposition,
			'apply_intent' => $input,
		) + $evidence;
		$digest_plan = array(
			'version'          => $plan['version'],
			'handle'           => $handle,
			'path'             => $path,
			'branch'           => $input['branch'],
			'disposition'      => $disposition,
			'apply_intent'     => $input,
			'freshness'        => array(
				'verified'    => $plan['freshness']['verified'] ?? null,
				'identity'    => $plan['freshness']['identity'] ?? null,
				'target_ref'  => $plan['freshness']['target_ref'] ?? null,
				'target_head' => $plan['freshness']['target_head'] ?? null,
			),
			'capacity'         => self::capacity_identity((array) ( $plan['capacity'] ?? array() )),
			'bootstrap_demand' => $plan['bootstrap_demand'] ?? null,
			'destination'      => $plan['destination'] ?? null,
			'ownership'        => $plan['ownership'] ?? null,
			'reuse_candidates' => $plan['reuse_candidates'] ?? null,
			'legacy_handoff'   => $plan['legacy_handoff'] ?? null,
		);
		$digest_json    = self::encode(self::sort($digest_plan));
		$plan['digest'] = hash('sha256', $digest_json);
		$plan['apply']  = array(
			'ability' => self::APPLY_ABILITY,
			'intent'  => array(
				'digest'       => $plan['digest'],
				'apply_intent' => $input,
			),
		);
		return $plan;
	}

	/**
	 * @param array<string,mixed> $capacity
	 * @return array<string,mixed>
	 */
	public static function capacity_identity( array $capacity ): array {
		$exception           = (array) ( $capacity['admission_exception'] ?? array() );
		$projected_exception = (array) ( $exception['projected_post_create_capacity'] ?? array() );
		$bind_measurements   = ! empty($exception['operator_intent']);
		if ( $bind_measurements && array() !== $projected_exception ) {
			$exception['projected_post_create_capacity'] = array(
				'free_bytes'  => self::capacity_measurement($projected_exception['free_bytes'] ?? null, 64 * 1024 * 1024),
				'free_inodes' => self::capacity_measurement($projected_exception['free_inodes'] ?? null, 1000000),
			);
		} else {
			unset($exception['projected_post_create_capacity']);
		}

		$identity = array(
			'status'                     => $capacity['status'] ?? null,
			'creation_allowed'           => $capacity['creation_allowed'] ?? null,
			'filesystem_total_bytes'     => $capacity['filesystem_total_bytes'] ?? null,
			'refuse_free_bytes'          => $capacity['refuse_free_bytes'] ?? null,
			'refuse_percent_bytes_floor' => $capacity['refuse_percent_bytes_floor'] ?? null,
			'effective_refuse_bytes'     => $capacity['effective_refuse_bytes'] ?? null,
			'refuse_free_inodes'         => $capacity['refuse_free_inodes'] ?? null,
			'refuse_percent_inode_floor' => $capacity['refuse_percent_inode_floor'] ?? null,
			'effective_refuse_inodes'    => $capacity['effective_refuse_inodes'] ?? null,
			'trigger_reasons'            => $capacity['trigger_reasons'] ?? null,
			'typed_trigger_reasons'      => $capacity['typed_trigger_reasons'] ?? null,
			'admission_exception'        => $exception,
			'force_override_required'    => $capacity['force_override_required'] ?? null,
			'force_override_applied'     => $capacity['force_override_applied'] ?? null,
			'worktree_count'             => $capacity['worktree_count'] ?? null,
		);
		if ( $bind_measurements ) {
			$identity['filesystem_free_bytes']  = self::capacity_measurement($capacity['filesystem_free_bytes'] ?? null, 64 * 1024 * 1024);
			$identity['projected_free_bytes']   = self::capacity_measurement($capacity['projected_free_bytes'] ?? null, 64 * 1024 * 1024);
			$identity['filesystem_free_inodes'] = self::capacity_measurement($capacity['filesystem_free_inodes'] ?? null, 1000000);
			$identity['projected_free_inodes']  = self::capacity_measurement($capacity['projected_free_inodes'] ?? null, 1000000);
		}
		return $identity;
	}

	public static function capacity_measurement( mixed $value, int $quantum ): mixed {
		if ( ! is_numeric($value) ) {
			return $value;
		}
		$value = (int) $value;
		return abs($value) < $quantum ? $value : intdiv($value, $quantum);
	}

	/**
	 * @param array<string,mixed> $expected
	 * @param array<string,mixed> $actual
	 * @return list<string>
	 */
	public static function changed_sections( array $expected, array $actual ): array {
		$sections = array(
			'apply_intent'     => array( $expected['apply_intent'] ?? null, $actual['apply_intent'] ?? null ),
			'freshness'        => array(
				array_intersect_key((array) ( $expected['freshness'] ?? array() ), array_flip(array( 'verified', 'identity', 'target_ref', 'target_head' ))),
				array_intersect_key((array) ( $actual['freshness'] ?? array() ), array_flip(array( 'verified', 'identity', 'target_ref', 'target_head' ))),
			),
			'capacity'         => array( self::capacity_identity((array) ( $expected['capacity'] ?? array() )), self::capacity_identity((array) ( $actual['capacity'] ?? array() )) ),
			'bootstrap_demand' => array( $expected['bootstrap_demand'] ?? null, $actual['bootstrap_demand'] ?? null ),
			'destination'      => array( $expected['destination'] ?? null, $actual['destination'] ?? null ),
			'ownership'        => array( $expected['ownership'] ?? null, $actual['ownership'] ?? null ),
			'reuse_candidates' => array( $expected['reuse_candidates'] ?? null, $actual['reuse_candidates'] ?? null ),
			'legacy_handoff'   => array( $expected['legacy_handoff'] ?? null, $actual['legacy_handoff'] ?? null ),
		);

		return array_keys(array_filter($sections, static fn( array $pair ): bool => $pair[0] !== $pair[1]));
	}

	public static function sort( mixed $value ): mixed {
		if ( ! is_array($value) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort($item);
		}
		if ( array_keys($value) !== range(0, count($value) - 1) ) {
			ksort($value);
		}
		return $value;
	}

	private static function encode( mixed $value ): string {
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
		return is_string($json) ? $json : '';
	}
}
