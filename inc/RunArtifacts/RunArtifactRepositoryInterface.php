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
	 * Read the artifact payload for a completed runtime job.
	 *
	 * @param  int $job_id Runtime job identifier.
	 * @return array<string,mixed>
	 */
	public function artifacts_for_job( int $job_id ): array;

	/**
	 * Read the artifact egress policy captured with a runtime job.
	 *
	 * @param  int $job_id Runtime job identifier.
	 * @return array<string,array<string,mixed>>
	 */
	public function egress_policy_for_job( int $job_id ): array;
}
