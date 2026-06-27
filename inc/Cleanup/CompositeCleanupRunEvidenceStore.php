<?php
/**
 * Cleanup run evidence store that routes across DMC-owned storage adapters.
 *
 * @package DataMachineCode\Cleanup
 */

namespace DataMachineCode\Cleanup;

defined('ABSPATH') || exit;

class CompositeCleanupRunEvidenceStore implements CleanupRunEvidenceStoreInterface {



	public function __construct(
		private ?CleanupRunEvidenceStoreInterface $workspace_store = null,
		private ?CleanupRunEvidenceStoreInterface $job_store = null
	) {
		$this->workspace_store ??= new WorkspaceCleanupRunEvidenceStore();
		$this->job_store       ??= new DataMachineJobCleanupRunEvidenceStore();
	}

	/**
	 * Read one cleanup run from the store implied by its identifier.
	 *
	 * @param  string $run_id           Stable cleanup run identifier.
	 * @param  bool   $include_evidence Whether to include raw evidence records.
	 * @param  bool   $include_details  Whether to include verbose diagnostic details.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error {
		if ( $this->is_job_cleanup_run_id($run_id) ) {
			return $this->job_store->read($run_id, $include_evidence, $include_details);
		}

		return $this->workspace_store->read($run_id, $include_evidence, $include_details);
	}

	private function is_job_cleanup_run_id( string $run_id ): bool {
		$run_id = trim($run_id);
		return is_numeric($run_id) || 1 === preg_match('/^cleanup-run-\d+$/', $run_id);
	}
}
