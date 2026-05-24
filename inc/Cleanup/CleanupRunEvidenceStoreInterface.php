<?php
/**
 * Cleanup run evidence storage contract.
 *
 * @package DataMachineCode\Cleanup
 */

namespace DataMachineCode\Cleanup;

defined('ABSPATH') || exit;

interface CleanupRunEvidenceStoreInterface
{

    /**
     * Build status/evidence for a cleanup run from persistent records.
     *
     * The canonical implementation should read DMC-owned cleanup run/item rows
     * once the #244 schema tables are present. Until then, the production store
     * reconstructs the same contract from Data Machine job rows and task results,
     * never from `.dmc-cleanup-plans` or `/tmp` JSON files.
     *
     * @param  string $run_id           Stable cleanup run identifier.
     * @param  bool   $include_evidence Whether to include raw evidence records.
     * @param  bool   $include_details  Whether to include verbose diagnostic details.
     * @return array<string,mixed>|\WP_Error
     */
    public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error;
}
