<?php
/**
 * Canonical task URL identity shared by WordPress and standalone providers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class TaskUrl {

	/** Match Homeboy: trim, discard query/fragment, trim slash, preserve casing. */
	public static function canonicalize( mixed $task_url ): ?string {
		if ( ! is_scalar($task_url) ) {
			return null;
		}
		$task_url = trim((string) $task_url);
		$task_url = explode('#', explode('?', $task_url, 2)[0], 2)[0];
		$task_url = rtrim($task_url, '/');
		return '' !== $task_url && filter_var($task_url, FILTER_VALIDATE_URL) ? $task_url : null;
	}
}
