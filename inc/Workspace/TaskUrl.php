<?php
/**
 * Canonical task URL identity shared by WordPress and standalone providers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class TaskUrl {

	/** Match Homeboy: trim, discard query/fragment/trailing slash, normalize authority only. */
	public static function canonicalize( mixed $task_url ): ?string {
		if ( ! is_scalar($task_url) ) {
			return null;
		}
		$task_url = trim((string) $task_url);
		$task_url = explode('#', explode('?', $task_url, 2)[0], 2)[0];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone provider use has no WordPress bootstrap.
		$parts    = function_exists('wp_parse_url') ? wp_parse_url($task_url) : parse_url($task_url);
		if ( ! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower((string) $parts['scheme']), array( 'http', 'https' ), true) ) {
			return null;
		}
		$canonical = strtolower((string) $parts['scheme']) . '://';
		if ( isset($parts['user']) ) {
			$canonical .= $parts['user'] . ( isset($parts['pass']) ? ':' . $parts['pass'] : '' ) . '@';
		}
		$port      = isset($parts['port']) ? (int) $parts['port'] : null;
		$default_port = ( 'http' === strtolower((string) $parts['scheme']) && 80 === $port ) || ( 'https' === strtolower((string) $parts['scheme']) && 443 === $port );
		$canonical .= strtolower((string) $parts['host']) . ( null !== $port && ! $default_port ? ':' . $port : '' ) . ( $parts['path'] ?? '' );
		$canonical = rtrim($canonical, '/');
		return '' !== $canonical && filter_var($canonical, FILTER_VALIDATE_URL) ? $canonical : null;
	}

	/** Return canonical tracker identity only when replay cannot expose URL userinfo. */
	public static function canonicalize_for_replay( mixed $task_url ): ?string {
		$canonical = self::canonicalize($task_url);
		if ( null === $canonical ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone provider use has no WordPress bootstrap.
		$parts = function_exists('wp_parse_url') ? wp_parse_url($canonical) : parse_url($canonical);
		return is_array($parts) && ! isset($parts['user']) && ! isset($parts['pass']) ? $canonical : null;
	}
}
