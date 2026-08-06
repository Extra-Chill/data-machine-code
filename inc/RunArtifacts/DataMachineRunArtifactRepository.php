<?php
/**
 * Data Machine-backed run artifact repository.
 *
 * @package DataMachineCode\RunArtifacts
 */

namespace DataMachineCode\RunArtifacts;

defined('ABSPATH') || exit;

class DataMachineRunArtifactRepository implements RunArtifactRepositoryInterface {

	public const STATUS_INTEGRATION_UNAVAILABLE = 'integration_unavailable';
	public const STATUS_INVALID_JOB_ID          = 'invalid_job_id';
	public const STATUS_MISSING_RUN             = 'missing_run';
	public const STATUS_UNAUTHORIZED            = 'unauthorized';
	public const STATUS_OWNER_ERROR             = 'owner_error';
	public const STATUS_MALFORMED_RESPONSE      = 'malformed_owner_response';
	public const STATUS_VALID_EMPTY             = 'valid_empty';
	public const STATUS_POPULATED               = 'populated';

	/** @var callable */
	private $ability_resolver;

	/**
	 * @param callable|null $ability_resolver Resolver receiving an ability name.
	 */
	public function __construct( ?callable $ability_resolver = null ) {
		$this->ability_resolver = $ability_resolver ?? static fn( string $name ) => function_exists('wp_get_ability') ? wp_get_ability($name) : null;
	}

	/**
	 * @return array{status:string,job_id:int,artifacts:array<string,mixed>,run_artifact_egress_policy:array<string,array<string,mixed>>,policy_provenance:array<string,mixed>,error_code?:string,error?:string}
	 */
	public function result_for_job( int $job_id ): array {
		if ( $job_id <= 0 ) {
			return $this->failure(self::STATUS_INVALID_JOB_ID, $job_id, 'invalid_job_id', 'job_id must be a positive integer.');
		}

		$ability = ( $this->ability_resolver )( 'datamachine/get-run-artifacts' );
		if ( ! is_object($ability) || ! is_callable(array( $ability, 'execute' )) ) {
			return $this->failure(self::STATUS_INTEGRATION_UNAVAILABLE, $job_id, 'ability_unavailable', 'The Data Machine run artifact ability is unavailable.');
		}

		$result = $ability->execute(array( 'job_id' => $job_id ));
		if ( function_exists('is_wp_error') && is_wp_error($result) ) {
			$error_code = (string) $result->get_error_code();
			if ( 'ability_invalid_permissions' === $error_code ) {
				$status = self::STATUS_UNAUTHORIZED;
			} elseif ( 'ability_invalid_output' === $error_code ) {
				$status = self::STATUS_MALFORMED_RESPONSE;
			} else {
				$status = self::STATUS_OWNER_ERROR;
			}

			return $this->failure($status, $job_id, $error_code, $result->get_error_message());
		}

		if ( ! is_array($result) || ! array_key_exists('success', $result) ) {
			return $this->malformed($job_id);
		}

		if ( false === $result['success'] ) {
			$error_code = is_string($result['error_code'] ?? null) ? $result['error_code'] : '';
			if ( 'job_not_found' === $error_code ) {
				$status = self::STATUS_MISSING_RUN;
			} elseif ( 'job_access_denied' === $error_code ) {
				$status = self::STATUS_UNAUTHORIZED;
			} elseif ( '' !== $error_code ) {
				$status = self::STATUS_OWNER_ERROR;
			} else {
				return $this->malformed($job_id);
			}

			return $this->failure($status, $job_id, $error_code, is_string($result['error'] ?? null) ? $result['error'] : '');
		}

		if (
			true !== $result['success']
			|| 1 !== ( $result['schema_version'] ?? null )
			|| ( $result['job_id'] ?? null ) !== $job_id
			|| ! is_array($result['artifacts'] ?? null)
			|| ! is_array($result['run_artifact_egress_policy'] ?? null)
			|| ! is_array($result['policy_provenance'] ?? null)
		) {
			return $this->malformed($job_id);
		}

		return array(
			'status'                     => $this->hasArtifacts($result['artifacts']) ? self::STATUS_POPULATED : self::STATUS_VALID_EMPTY,
			'job_id'                     => $job_id,
			'artifacts'                  => $result['artifacts'],
			'run_artifact_egress_policy' => $result['run_artifact_egress_policy'],
			'policy_provenance'          => $result['policy_provenance'],
		);
	}

	/**
	 * @return array{status:string,job_id:int,artifacts:array{},run_artifact_egress_policy:array{},policy_provenance:array{},error_code:string,error:string}
	 */
	private function failure( string $status, int $job_id, string $error_code, string $error ): array {
		return array(
			'status'                     => $status,
			'job_id'                     => $job_id,
			'artifacts'                  => array(),
			'run_artifact_egress_policy' => array(),
			'policy_provenance'          => array(),
			'error_code'                 => $error_code,
			'error'                      => $error,
		);
	}

	/**
	 * @return array{status:string,job_id:int,artifacts:array{},run_artifact_egress_policy:array{},policy_provenance:array{},error_code:string,error:string}
	 */
	private function malformed( int $job_id ): array {
		return $this->failure(self::STATUS_MALFORMED_RESPONSE, $job_id, 'malformed_owner_response', 'The Data Machine run artifact ability returned a malformed response.');
	}

	/** @param array<string,mixed> $artifacts */
	private function hasArtifacts( array $artifacts ): bool {
		foreach ( $artifacts as $value ) {
			if ( ! empty($value) ) {
				return true;
			}
		}

		return false;
	}
}
