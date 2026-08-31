<?php
/**
 * Run artifact repository contract.
 *
 * @package DataMachineCode\RunArtifacts
 */

namespace DataMachineCode\RunArtifacts;

defined('ABSPATH') || exit;

interface RunArtifactRepositoryInterface {



	/**
	 * Read the normalized public artifact result for a runtime job.
	 *
	 * @param  int $job_id Runtime job identifier.
	 * @return array{status:string,job_id:int,artifacts:array<string,mixed>,run_artifact_egress_policy:array<string,array<string,mixed>>,policy_provenance:array<string,mixed>,error_code?:string,error?:string}
	 */
	public function result_for_job( int $job_id ): array;
}
