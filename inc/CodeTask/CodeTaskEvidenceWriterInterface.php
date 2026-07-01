<?php
/**
 * Evidence writer facade for code-task scaffolding.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

defined('ABSPATH') || exit;

interface CodeTaskEvidenceWriterInterface {


	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function write_file( string $handle, string $path, string $content ): array|\WP_Error;
}
