<?php
/**
 * Data Machine-backed run artifact repository.
 *
 * @package DataMachineCode\RunArtifacts
 */

namespace DataMachineCode\RunArtifacts;

defined('ABSPATH') || exit;

class DataMachineRunArtifactRepository implements RunArtifactRepositoryInterface {



	/**
	 * @return array<string,mixed>
	 */
	public function artifacts_for_job( int $job_id ): array {
		if ( $job_id <= 0 || ! class_exists('\\DataMachine\\Core\\JobArtifacts') ) {
			return array();
		}

		$result = ( new \DataMachine\Core\JobArtifacts() )->get($job_id);
		if ( empty($result['success']) || ! is_array($result['artifacts'] ?? null) ) {
			return array();
		}

		return $result['artifacts'];
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function egress_policy_for_job( int $job_id ): array {
		if ( $job_id <= 0 || ! class_exists('\\DataMachine\\Core\\Database\\Jobs\\Jobs') ) {
			return array();
		}

		$jobs = new \DataMachine\Core\Database\Jobs\Jobs();
		if ( ! method_exists($jobs, 'retrieve_engine_data') ) {
			return array();
		}

		$engine_data = $jobs->retrieve_engine_data($job_id);
		return is_array($engine_data['run_artifact_egress_policy'] ?? null) ? $engine_data['run_artifact_egress_policy'] : array();
	}
}
