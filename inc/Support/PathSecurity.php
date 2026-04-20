<?php
/**
 * Path Security
 *
 * Shared containment + sensitive-file primitives used by both
 * `Workspace\Workspace` and `GitSync\GitSync`. Kept in one place so the
 * block list, traversal detection, and realpath-based containment stay
 * consistent across every code path that touches the filesystem.
 *
 * @package DataMachineCode\Support
 * @since   0.7.0
 */

namespace DataMachineCode\Support;

defined( 'ABSPATH' ) || exit;

final class PathSecurity {

	/**
	 * Sensitive filename fragments — any path containing one is refused
	 * for mutations (staging, writing). Matched against the lower-cased
	 * normalized path so case variants (e.g. `.ENV`) can't sneak through.
	 *
	 * @var string[]
	 */
	private const SENSITIVE_PATTERNS = array(
		'.env',
		'credentials.json',
		'id_rsa',
		'id_ed25519',
		'.pem',
		'.key',
		'secrets',
	);

	/**
	 * Validate that a target path is contained within a parent directory.
	 *
	 * Uses `realpath()` so symlinks cannot escape the container. Both paths
	 * must exist on disk — use `hasTraversal()` for pre-existence checks on
	 * relative paths before writing.
	 *
	 * @param string $target    Path to validate.
	 * @param string $container Parent directory that must contain the target.
	 * @return array{valid: bool, real_path?: string, message?: string}
	 */
	public static function validateContainment( string $target, string $container ): array {
		$real_container = realpath( $container );
		$real_target    = realpath( $target );

		if ( false === $real_container || false === $real_target ) {
			return array(
				'valid'   => false,
				'message' => 'Path does not exist.',
			);
		}

		if ( 0 !== strpos( $real_target, $real_container . '/' ) && $real_target !== $real_container ) {
			return array(
				'valid'   => false,
				'message' => 'Path traversal detected. Access denied.',
			);
		}

		return array(
			'valid'     => true,
			'real_path' => $real_target,
		);
	}

	/**
	 * Detect `.` or `..` components in a relative path.
	 *
	 * Pre-write companion to `validateContainment()` — use this to reject
	 * relative paths *before* resolving them, so we never write to an
	 * escaping location even transiently.
	 *
	 * @param string $path Relative path.
	 * @return bool True if any segment is `.` or `..`.
	 */
	public static function hasTraversal( string $path ): bool {
		$parts = explode( '/', str_replace( '\\', '/', $path ) );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '.' === $part ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a path looks sensitive (env files, credentials, keys).
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public static function isSensitivePath( string $path ): bool {
		$normalized = strtolower( ltrim( str_replace( '\\', '/', $path ), '/' ) );

		foreach ( self::SENSITIVE_PATTERNS as $pattern ) {
			if ( str_contains( $normalized, $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
