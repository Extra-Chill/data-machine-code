<?php
/**
 * Memory Disk Projection helpers.
 *
 * DMC owns local runtime/file projection for coding agents. Data Machine owns
 * memory semantics and storage backends. This helper intentionally stops at
 * capability checks and path contracts until Data Machine emits explicit
 * memory/guideline change events.
 *
 * @package DataMachineCode
 */

namespace DataMachineCode;

defined( 'ABSPATH' ) || exit;

class MemoryDiskProjection {

	public const GUIDELINE_POST_TYPE = 'wp_guideline';
	public const GUIDELINE_TAXONOMY  = 'wp_guideline_type';

	public const HOOK_MEMORY_UPDATED    = 'datamachine_agent_memory_updated';
	public const HOOK_MEMORY_DELETED    = 'datamachine_agent_memory_deleted';
	public const HOOK_GUIDELINE_UPDATED = 'datamachine_guideline_updated';

	/**
	 * Whether DMC can write projection files for a co-located coding runtime.
	 */
	public static function is_available(): bool {
		return Environment::is_available() && Environment::has_writable_fs();
	}

	/**
	 * Whether the optional WordPress Guidelines substrate is present.
	 *
	 * `wp_guideline` is not guaranteed in WordPress core. It may come from
	 * Gutenberg, a future core merge, or an explicit plugin/polyfill.
	 */
	public static function has_guideline_substrate(): bool {
		return function_exists( 'post_type_exists' )
			&& function_exists( 'taxonomy_exists' )
			&& post_type_exists( self::GUIDELINE_POST_TYPE )
			&& taxonomy_exists( self::GUIDELINE_TAXONOMY );
	}

	/**
	 * Future Data Machine events DMC expects before live projection is safe.
	 *
	 * @return array<string,string>
	 */
	public static function expected_event_hooks(): array {
		return array(
			'memory_updated'    => self::HOOK_MEMORY_UPDATED,
			'memory_deleted'    => self::HOOK_MEMORY_DELETED,
			'guideline_updated' => self::HOOK_GUIDELINE_UPDATED,
		);
	}

	/**
	 * Normalize a relative projection path.
	 *
	 * Projection paths are always relative to the WordPress site root. Absolute
	 * paths, parent-directory traversal, empty paths, and NUL bytes are rejected.
	 */
	public static function normalize_relative_path( string $relative_path ): ?string {
		if ( str_contains( $relative_path, "\0" ) ) {
			return null;
		}

		$relative_path = str_replace( '\\', '/', trim( $relative_path ) );
		if ( '' === $relative_path || str_starts_with( $relative_path, '/' ) ) {
			return null;
		}

		$relative_path = preg_replace( '#/+#', '/', $relative_path );
		$relative_path = null === $relative_path ? '' : $relative_path;
		while ( str_starts_with( $relative_path, './' ) ) {
			$relative_path = substr( $relative_path, 2 );
		}

		if ( '' === $relative_path ) {
			return null;
		}

		$parts = explode( '/', $relative_path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return null;
			}
		}

		return implode( '/', $parts );
	}

	/**
	 * Resolve a safe absolute projection path below the site root.
	 */
	public static function resolve_site_projection_path( string $site_root, string $relative_path ): ?string {
		$relative_path = self::normalize_relative_path( $relative_path );
		$site_root     = rtrim( str_replace( '\\', '/', trim( $site_root ) ), '/' );

		if ( null === $relative_path || '' === $site_root ) {
			return null;
		}

		return $site_root . '/' . $relative_path;
	}
}
