<?php
/**
 * Cleanup run evidence store backed by DMC workspace cleanup rows.
 *
 * @package DataMachineCode\Cleanup
 */

namespace DataMachineCode\Cleanup;

use DataMachineCode\Workspace\CleanupRunService;

defined('ABSPATH') || exit;

class WorkspaceCleanupRunEvidenceStore implements CleanupRunEvidenceStoreInterface {



	public function __construct(
		private ?CleanupRunService $cleanup_run_service = null
	) {
		$this->cleanup_run_service ??= new CleanupRunService();
	}

	/**
	 * Read one DMC workspace cleanup run.
	 *
	 * @param  string $run_id           Stable cleanup run identifier.
	 * @param  bool   $include_evidence Whether to include raw evidence records.
	 * @param  bool   $include_details  Whether to include verbose diagnostic details.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error {
		unset($include_details);

		return $include_evidence ? $this->cleanup_run_service->evidence($run_id) : $this->cleanup_run_service->status($run_id);
	}
}
