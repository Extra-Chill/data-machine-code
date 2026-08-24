<?php
/**
 * Canonical task URL identity shared by WordPress and standalone providers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class TaskUrl {

	/** Match Homeboy: trim, discard query/fragment/trailing slash, lowercase scheme/host only. */
	public static function canonicalize( mixed $task_url ): ?string {
		if ( ! is_scalar($task_url) ) {
			return null;
		}
		$task_url = trim((string) $task_url);
		$task_url = explode('#', explode('?', $task_url, 2)[0], 2)[0];
		$parts    = parse_url($task_url);
		if ( ! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower((string) $parts['scheme']), array( 'http', 'https' ), true) ) {
			return null;
		}
		$canonical = strtolower((string) $parts['scheme']) . '://';
		if ( isset($parts['user']) ) {
			$canonical .= $parts['user'] . ( isset($parts['pass']) ? ':' . $parts['pass'] : '' ) . '@';
		}
		$canonical .= strtolower((string) $parts['host']) . ( isset($parts['port']) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
		$canonical = rtrim($canonical, '/');
		return '' !== $canonical && filter_var($canonical, FILTER_VALIDATE_URL) ? $canonical : null;
	}
}
