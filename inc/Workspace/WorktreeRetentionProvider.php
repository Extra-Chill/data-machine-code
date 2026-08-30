<?php
/**
 * Homeboy worktree-retention provider adapter.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

final class WorktreeRetentionProvider {

	public const SCHEMA      = 'homeboy/worktree-retention/v1';
	public const PROVIDER_ID = 'data-machine-code';

	private const MAX_REQUEST_BYTES = 262144;
	private const MAX_OUTPUT_BYTES  = 65536;
	private const MAX_ITEMS         = 100;

	/** @var callable():int */
	private $clock_ms;

	public function __construct(
		private ?CleanupRunService $cleanup = null,
		?callable $clock_ms = null
	) {
		$this->cleanup  ??= new CleanupRunService();
		$this->clock_ms = $clock_ms ?? static fn(): int => (int) floor( microtime( true ) * 1000 );
	}

	/**
	 * Execute one strict provider request.
	 *
	 * @return array<string,mixed>
	 */
	public function handle_json( string $json ): array {
		if ( strlen( $json ) > self::MAX_REQUEST_BYTES ) {
			return $this->failure( 'request_too_large' );
		}

		try {
			$request = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return $this->failure( 'invalid_json' );
		}
		if ( ! is_array( $request ) || array_is_list( $request ) ) {
			return $this->failure( 'invalid_request' );
		}

		$error = $this->validate_request( $request );
		if ( null !== $error ) {
			return $this->failure(
				$error,
				(string) ( $request['run_id'] ?? 'unavailable' ),
				(string) ( $request['plan_id'] ?? 'unavailable' )
			);
		}

		return match ( $request['operation'] ) {
			'plan'     => $this->plan( $request ),
			'apply'    => $this->apply( $request ),
			'status'   => $this->read( $request, false ),
			'evidence' => $this->read( $request, true ),
		};
	}

	/** Return one protocol-bounded JSON document. */
	public function encode_response( array $response ): string {
		$json = json_encode( $this->wire_response( $response ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > self::MAX_OUTPUT_BYTES ) {
			$json = json_encode( $this->wire_response( $this->failure( 'response_too_large' ) ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		}
		return $json;
	}

	/** @return array<string,mixed> */
	private function wire_response( array $response ): array {
		$response['effects'] = (object) (array) ( $response['effects'] ?? array() );
		if ( isset( $response['blockers'] ) && is_array( $response['blockers'] ) ) {
			$response['blockers']['by_reason'] = (object) (array) ( $response['blockers']['by_reason'] ?? array() );
		}
		return $response;
	}

	/** @param array<string,mixed> $request */
	private function validate_request( array $request ): ?string {
		$allowed = array( 'schema', 'provider_id', 'operation', 'request_id', 'idempotency_key', 'run_id', 'plan_id', 'bounds', 'deadline_unix_ms' );
		if ( array() !== array_diff( array_keys( $request ), $allowed ) ) {
			return 'unknown_request_field';
		}
		if ( self::SCHEMA !== ( $request['schema'] ?? null ) ) {
			return 'unexpected_schema';
		}
		if ( self::PROVIDER_ID !== ( $request['provider_id'] ?? null ) ) {
			return 'unexpected_provider';
		}
		if ( ! in_array( $request['operation'] ?? null, array( 'plan', 'apply', 'status', 'evidence' ), true ) ) {
			return 'unsupported_operation';
		}
		if ( ! is_string( $request['request_id'] ?? null ) || '' === trim( $request['request_id'] ) ) {
			return 'missing_request_id';
		}
		foreach ( array( 'idempotency_key', 'run_id', 'plan_id' ) as $field ) {
			if ( isset( $request[ $field ] ) && ( ! is_string( $request[ $field ] ) || '' === trim( $request[ $field ] ) ) ) {
				return 'invalid_' . $field;
			}
		}
		if ( 'plan' !== $request['operation'] && ( empty( $request['run_id'] ) || empty( $request['plan_id'] ) ) ) {
			return 'missing_plan_identity';
		}
		if ( isset( $request['bounds'] ) ) {
			if ( ! is_array( $request['bounds'] ) || array_is_list( $request['bounds'] ) || array() !== array_diff( array_keys( $request['bounds'] ), array( 'max_items', 'timeout_ms' ) ) ) {
				return 'invalid_bounds';
			}
			foreach ( array( 'max_items', 'timeout_ms' ) as $field ) {
				if ( isset( $request['bounds'][ $field ] ) && ( ! is_int( $request['bounds'][ $field ] ) || $request['bounds'][ $field ] <= 0 ) ) {
					return 'invalid_' . $field;
				}
			}
		}
		if ( isset( $request['deadline_unix_ms'] ) ) {
			if ( ! is_int( $request['deadline_unix_ms'] ) || $request['deadline_unix_ms'] <= 0 ) {
				return 'invalid_deadline';
			}
			if ( $request['deadline_unix_ms'] <= ( $this->clock_ms )() ) {
				return 'deadline_elapsed';
			}
		}
		return null;
	}

	/** @param array<string,mixed> $request @return array<string,mixed> */
	private function plan( array $request ): array {
		$options = array(
			'mode'              => 'retention',
			'include_artifacts' => false,
			'include_worktrees' => true,
			'include_resolvers' => false,
			'limit'             => $this->limit( $request ),
		);
		$budget = $this->remaining_budget_seconds( $request );
		if ( null !== $budget ) {
			$options['until_budget'] = $budget . 's';
		}

		$plan = $this->cleanup->plan( $options );
		if ( $plan instanceof \WP_Error ) {
			return $this->failure( (string) $plan->get_error_code() );
		}
		$run_id  = (string) ( $plan['run_id'] ?? '' );
		$plan_id = (string) ( $plan['plan_id'] ?? '' );
		if ( '' === $run_id || '' === $plan_id ) {
			return $this->failure( 'provider_plan_identity_missing' );
		}

		return $this->response(
			$run_id,
			$plan_id,
			'planned',
			! empty( $plan['continuation']['partial'] )
				? array( 'complete' => false, 'resume_operation' => 'plan', 'reason' => 'inventory_page' )
				: null,
			array(),
			$this->blockers( (array) ( $plan['summary']['blockers'] ?? array() ) )
		);
	}

	/** @param array<string,mixed> $request @return array<string,mixed> */
	private function apply( array $request ): array {
		$identity = $this->verified_status( $request );
		if ( $identity instanceof \WP_Error ) {
			return $this->failure( (string) $identity->get_error_code(), (string) $request['run_id'], (string) $request['plan_id'] );
		}

		$result = $this->cleanup->apply( (string) $request['run_id'], array( 'limit' => $this->limit( $request ) ) );
		if ( $result instanceof \WP_Error ) {
			return $this->failure( (string) $result->get_error_code(), (string) $request['run_id'], (string) $request['plan_id'] );
		}

		return $this->from_status( $result, $identity );
	}

	/** @param array<string,mixed> $request @return array<string,mixed> */
	private function read( array $request, bool $evidence ): array {
		$status = $this->verified_status( $request, $evidence );
		if ( $status instanceof \WP_Error ) {
			return $this->failure( (string) $status->get_error_code(), (string) $request['run_id'], (string) $request['plan_id'] );
		}
		return $this->from_status( $status, $status );
	}

	/** @param array<string,mixed> $request @return array<string,mixed>|\WP_Error */
	private function verified_status( array $request, bool $evidence = false ): array|\WP_Error {
		$status = $evidence
			? $this->cleanup->evidence( (string) $request['run_id'] )
			: $this->cleanup->status( (string) $request['run_id'] );
		if ( $status instanceof \WP_Error ) {
			return $status;
		}
		$persisted = (string) ( $status['run']['policy']['plan_id'] ?? '' );
		if ( '' === $persisted || ! hash_equals( $persisted, (string) $request['plan_id'] ) ) {
			return new \WP_Error( 'plan_identity_mismatch', 'The reviewed cleanup plan identity does not match the persisted run.', array( 'status' => 409 ) );
		}
		return $status;
	}

	/** @param array<string,mixed> $status @param array<string,mixed> $identity @return array<string,mixed> */
	private function from_status( array $status, array $identity ): array {
		$run          = (array) ( $status['run'] ?? $identity['run'] ?? array() );
		$policy       = (array) ( $run['policy'] ?? array() );
		$run_id       = (string) ( $status['run_id'] ?? $run['run_id'] ?? '' );
		$plan_id      = (string) ( $policy['plan_id'] ?? '' );
		$state        = (string) ( $status['state'] ?? $status['status'] ?? $run['status'] ?? '' );
		$pending      = (int) ( $status['summary']['pending_or_failed'] ?? 0 );
		$continuation = null;
		if ( in_array( $state, array( 'failed', 'planning_failed', 'cancelled' ), true ) ) {
			$state = 'failed';
		} elseif ( $pending > 0 || in_array( $state, array( 'applying', 'needs_resume' ), true ) ) {
			$state        = 'continuing';
			$continuation = array( 'complete' => false, 'resume_operation' => 'apply', 'reason' => 'pending_rows' );
		} elseif ( 'planned' === $state && ! empty( $policy['inventory_continuation']['partial'] ) ) {
			$continuation = array( 'complete' => false, 'resume_operation' => 'plan', 'reason' => 'inventory_page' );
		} elseif ( ! empty( $policy['inventory_continuation']['partial'] ) ) {
			$state        = 'continuing';
			$continuation = array( 'complete' => false, 'resume_operation' => 'plan', 'reason' => 'inventory_page' );
		} elseif ( 'planned' !== $state && 'completed' !== $state ) {
			$state = 'blocked';
		}

		$summary = (array) ( $status['summary'] ?? array() );
		return $this->response(
			$run_id,
			$plan_id,
			$state,
			$continuation,
			array(
				'worktrees_removed' => (int) ( $summary['items_by_status']['applied'] ?? 0 ),
				'bytes_reclaimed'   => (int) ( $summary['bytes_reclaimed'] ?? 0 ),
			),
			$this->blockers( (array) ( $policy['retention_blockers'] ?? array() ) )
		);
	}

	/** @return array{count:int,by_reason:array<string,int>} */
	private function blockers( array $blockers ): array {
		$by_reason = array();
		foreach ( $blockers as $reason => $bucket ) {
			$count = max( 0, (int) ( is_array( $bucket ) ? ( $bucket['count'] ?? 0 ) : 0 ) );
			if ( $count > 0 ) {
				$by_reason[ (string) $reason ] = $count;
			}
		}
		ksort( $by_reason );
		return array( 'count' => array_sum( $by_reason ), 'by_reason' => $by_reason );
	}

	/** @param array<string,mixed> $request */
	private function limit( array $request ): int {
		return max( 1, min( self::MAX_ITEMS, (int) ( $request['bounds']['max_items'] ?? 25 ) ) );
	}

	/** @param array<string,mixed> $request */
	private function remaining_budget_seconds( array $request ): ?int {
		$milliseconds = isset( $request['deadline_unix_ms'] ) ? (int) $request['deadline_unix_ms'] - ( $this->clock_ms )() : 0;
		if ( isset( $request['bounds']['timeout_ms'] ) ) {
			$bound        = (int) $request['bounds']['timeout_ms'];
			$milliseconds = $milliseconds > 0 ? min( $milliseconds, $bound ) : $bound;
		}
		return $milliseconds > 0 ? max( 1, (int) floor( $milliseconds / 1000 ) ) : null;
	}

	/** @return array<string,mixed> */
	private function response( string $run_id, string $plan_id, string $state, mixed $continuation, array $effects, array $blockers ): array {
		$partial = is_array( $continuation ) && empty( $continuation['complete'] );
		return array(
			'schema'                 => self::SCHEMA,
			'provider_id'            => self::PROVIDER_ID,
			'run_id'                 => '' !== $run_id ? $run_id : 'unavailable',
			'plan_id'                => '' !== $plan_id ? $plan_id : 'unavailable',
			'state'                  => $state,
			'inventory_completeness' => $partial ? 'partial' : 'complete',
			'continuation'           => $partial ? $continuation : null,
			'status_ref'             => array( 'command' => sprintf( 'studio wp datamachine-code workspace cleanup status %s --format=json', $run_id ) ),
			'evidence_ref'           => array( 'command' => sprintf( 'studio wp datamachine-code workspace cleanup evidence %s --format=json', $run_id ) ),
			'effects'                => array_filter( $effects, static fn( $value ): bool => null !== $value ),
			'blockers'               => $blockers,
		);
	}

	/** @return array<string,mixed> */
	private function failure( string $reason, string $run_id = 'unavailable', string $plan_id = 'unavailable' ): array {
		return $this->response(
			$run_id,
			$plan_id,
			'failed',
			null,
			array(),
			array( 'count' => 1, 'by_reason' => array( $reason => 1 ) )
		);
	}
}
