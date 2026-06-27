<?php
/**
 * Shared worktree cleanup signal helpers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeCleanupSignal {

	/**
	 * Build a cleanup signal from lifecycle metadata when one is explicit enough.
	 *
	 * @param  array<string,mixed>|null $metadata Lifecycle metadata.
	 * @return array<string,mixed>|null
	 */
	public static function from_metadata( ?array $metadata, bool $include_repaired_metadata = false ): ?array {
		if ( is_array($metadata) && WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			$signal = array(
				'signal' => 'cleanup_eligible',
				'reason' => 'worktree finalized or explicitly marked cleanup_eligible',
			);
			if ( ! empty($metadata['pr_url']) ) {
				$signal['pr_url'] = (string) $metadata['pr_url'];
			}

			return $signal;
		}

		if ( $include_repaired_metadata && is_array($metadata) && ! empty($metadata['metadata_repaired']) ) {
			return array(
				'signal' => 'repaired_metadata',
				'reason' => 'operator-approved cleanup of repaired metadata',
			);
		}

		return null;
	}

	/**
	 * Build the common cleanup candidate fields for a cleanup signal.
	 *
	 * @param  array<string,mixed> $signal Cleanup signal.
	 * @return array<string,mixed>
	 */
	public static function candidate_fields( array $signal, bool $include_null_pr_url = false ): array {
		$fields = array(
			'signal'      => (string) ( $signal['signal'] ?? '' ),
			'reason_code' => (string) ( $signal['signal'] ?? '' ),
			'reason'      => (string) ( $signal['reason'] ?? '' ),
		);

		if ( $include_null_pr_url || array_key_exists('pr_url', $signal) ) {
			$fields['pr_url'] = $signal['pr_url'] ?? null;
		}

		return $fields;
	}
}
