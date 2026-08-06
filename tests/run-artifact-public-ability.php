<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/inc/RunArtifacts/RunArtifactRepositoryInterface.php';
require_once dirname(__DIR__) . '/inc/RunArtifacts/DataMachineRunArtifactRepository.php';

use DataMachineCode\RunArtifacts\DataMachineRunArtifactRepository;

final class RunArtifactAbilityFake {
	/** @var mixed */
	private $result;

	public function __construct( mixed $result ) {
		$this->result = $result;
	}

	public function execute( array $input ): mixed {
		return $this->result;
	}
}

function artifact_repository_for( mixed $result ): DataMachineRunArtifactRepository {
	return new DataMachineRunArtifactRepository(
		static fn( string $name ) => 'datamachine/get-run-artifacts' === $name ? new RunArtifactAbilityFake($result) : null
	);
}

function artifact_result( array $overrides = array() ): array {
	return array_merge(
		array(
			'success'                    => true,
			'schema_version'             => 1,
			'job_id'                     => 42,
			'artifacts'                  => array( 'daily_memory_artifacts' => array() ),
			'run_artifact_egress_policy' => array(),
			'policy_provenance'          => array(
				'source'     => 'none',
				'path'       => '',
				'normalized' => true,
			),
		),
		$overrides
	);
}

function artifact_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
	}
}

$unavailable = ( new DataMachineRunArtifactRepository(static fn() => null) )->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_INTEGRATION_UNAVAILABLE, $unavailable['status'], 'An unavailable integration must be explicit.');

$invalid = artifact_repository_for(artifact_result())->result_for_job(0);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_INVALID_JOB_ID, $invalid['status'], 'An invalid job ID must be explicit.');

$missing = artifact_repository_for(
	array(
		'success'    => false,
		'error_code' => 'job_not_found',
		'error'      => 'Job 42 was not found.',
		'status'     => 404,
	)
)->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_MISSING_RUN, $missing['status'], 'A missing run must be distinct from empty artifacts.');

$unauthorized = artifact_repository_for(
	array(
		'success'    => false,
		'error_code' => 'job_access_denied',
		'error'      => 'Access denied.',
		'status'     => 403,
	)
)->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_UNAUTHORIZED, $unauthorized['status'], 'An unauthorized run must be explicit.');

$owner_error = artifact_repository_for(
	array(
		'success'    => false,
		'error_code' => 'artifacts_unavailable',
		'error'      => 'Artifacts unavailable.',
		'status'     => 500,
	)
)->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_OWNER_ERROR, $owner_error['status'], 'A valid owner error must not be classified as malformed.');

$empty = artifact_repository_for(artifact_result())->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_VALID_EMPTY, $empty['status'], 'A valid empty artifact payload must remain successful.');
artifact_assert_same(array( 'daily_memory_artifacts' => array() ), $empty['artifacts'], 'The normalized empty artifact model must be preserved.');

$malformed = artifact_repository_for(artifact_result(array( 'schema_version' => 2 )))->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_MALFORMED_RESPONSE, $malformed['status'], 'An unsupported owner schema must be rejected as malformed.');

$invalid_output = artifact_repository_for(new WP_Error('ability_invalid_output', 'Output failed schema validation.'))->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_MALFORMED_RESPONSE, $invalid_output['status'], 'Core output validation failures must be classified as malformed owner responses.');

$populated_payload = artifact_result(
	array(
		'artifacts'                  => array(
			'daily_memory_artifacts' => array(
				array( 'type' => 'daily_memory', 'content' => '# Evidence' ),
			),
		),
		'run_artifact_egress_policy' => array(
			'daily_memory' => array( 'egress' => array( 'bundle-file' ) ),
		),
		'policy_provenance'          => array(
			'source'     => 'job_snapshot',
			'path'       => 'run_artifact_egress_policy',
			'normalized' => true,
		),
	)
);
$populated = artifact_repository_for($populated_payload)->result_for_job(42);
artifact_assert_same(DataMachineRunArtifactRepository::STATUS_POPULATED, $populated['status'], 'Populated artifacts must be explicit.');
artifact_assert_same($populated_payload['artifacts'], $populated['artifacts'], 'The normalized artifact payload must pass through unchanged.');
artifact_assert_same($populated_payload['run_artifact_egress_policy'], $populated['run_artifact_egress_policy'], 'The public normalized policy must pass through unchanged.');

echo "run artifact public ability test passed.\n";
