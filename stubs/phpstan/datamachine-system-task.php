<?php
/**
 * PHPStan stub for Data Machine system tasks.
 *
 * @package DataMachineCode\Stubs
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined('ABSPATH') || exit;

/**
 * Static-analysis shape for the external Data Machine SystemTask class.
 */
abstract class SystemTask {

	/**
	 * @param array<string,mixed> $result Job result payload.
	 */
	protected function completeJob( int $jobId, array $result = array() ): void {
	}

	protected function failJob( int $jobId, string $message ): void {
	}
}
