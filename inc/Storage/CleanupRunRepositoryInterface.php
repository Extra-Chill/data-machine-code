<?php
/**
 * Cleanup run repository contract.
 *
 * @package DataMachineCode\Storage
 */

namespace DataMachineCode\Storage;

defined('ABSPATH') || exit;

interface CleanupRunRepositoryInterface {

	/**
	 * Create a cleanup run.
	 *
	 * @param  array<string,mixed> $run Run fields.
	 * @return string|\WP_Error
	 */
	public function create_run( array $run ): string|\WP_Error;

	/**
	 * Update a cleanup run.
	 *
	 * @param  string              $run_id Run ID.
	 * @param  array<string,mixed> $fields Run fields.
	 */
	public function update_run( string $run_id, array $fields ): bool;
}
