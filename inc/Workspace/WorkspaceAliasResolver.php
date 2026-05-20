<?php
/**
 * Workspace alias resolver for agent-facing tool calls.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

class WorkspaceAliasResolver {

	private const OPTION = 'datamachine_code_workspace_aliases';

	/**
	 * Return normalized alias specs keyed by agent-facing alias.
	 *
	 * Runners can persist aliases in the option, or inject them just-in-time via
	 * the filter when a single conversation should see an opaque project name.
	 *
	 * @return array<string,array{target:string,root:string}>
	 */
	public static function aliases(): array {
		$aliases = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $aliases ) ) {
			$aliases = array();
		}

		if ( function_exists( 'apply_filters' ) ) {
			$aliases = apply_filters( 'datamachine_code_workspace_aliases', $aliases );
		}

		$normalized = array();
		foreach ( $aliases as $alias => $spec ) {
			$alias = trim( (string) $alias );
			if ( is_array( $spec ) ) {
				$handle = trim( (string) ( $spec['target'] ?? $spec['handle'] ?? $spec['name'] ?? '' ) );
				$root   = self::normalize_root( (string) ( $spec['root'] ?? $spec['agent_root'] ?? $spec['path_prefix'] ?? '' ) );
			} else {
				$handle = trim( (string) $spec );
				$root   = '';
			}

			if ( '' === $alias || '' === $handle ) {
				continue;
			}
			$normalized[ $alias ] = array(
				'target' => $handle,
				'root'   => $root,
			);
		}

		return $normalized;
	}

	public static function resolve( string $handle ): string {
		$aliases = self::aliases();
		return $aliases[ $handle ]['target'] ?? $handle;
	}

	/**
	 * Return the normalized alias spec for an agent-facing handle.
	 *
	 * @return array{target:string,root:string}|null
	 */
	public static function spec( string $handle ): ?array {
		return self::aliases()[ $handle ] ?? null;
	}

	public static function alias_for( string $handle ): ?string {
		foreach ( self::aliases() as $alias => $spec ) {
			if ( $spec['target'] === $handle ) {
				return $alias;
			}
		}

		return null;
	}

	public static function has_alias( string $handle ): bool {
		return isset( self::aliases()[ $handle ] );
	}

	public static function root_for( string $handle ): string {
		return self::aliases()[ $handle ]['root'] ?? '';
	}

	public static function scope_path( string $path, string $root ): string|false {
		$root = self::normalize_root( $root );
		if ( '' === $root ) {
			return $path;
		}

		$virtual = str_replace( '\\', '/', $path );
		if ( str_starts_with( $virtual, '/' ) || preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $virtual ) ) {
			return false;
		}

		$segments = array();
		foreach ( explode( '/', $virtual ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment || str_contains( $segment, "\0" ) ) {
				return false;
			}

			$segments[] = $segment;
		}

		return empty( $segments ) ? $root : $root . '/' . implode( '/', $segments );
	}

	public static function unscope_path( string $path, string $root ): string {
		$root = self::normalize_root( $root );
		if ( '' === $root ) {
			return $path;
		}

		$normalized = str_replace( '\\', '/', $path );
		if ( $normalized === $root ) {
			return '';
		}

		$prefix = $root . '/';
		if ( str_starts_with( $normalized, $prefix ) ) {
			return substr( $normalized, strlen( $prefix ) );
		}

		return $path;
	}

	/**
	 * Sanitize model-facing workspace metadata while leaving content/diff fields intact.
	 *
	 * @param mixed  $value  Tool result value.
	 * @param string $alias  Agent-facing alias.
	 * @param string $handle Canonical workspace handle.
	 * @return mixed
	 */
	public static function sanitize_result( mixed $value, string $alias, string $handle, string $root = '' ): mixed {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				if ( in_array( $key, array( 'content', 'patch' ), true ) ) {
					$sanitized[ $key ] = $item;
					continue;
				}
				$sanitized[ $key ] = self::sanitize_result( $item, $alias, $handle, $root );
			}
			return $sanitized;
		}

		if ( ! is_string( $value ) || '' === $handle ) {
			return $value;
		}

		$sanitized = self::sanitize_scoped_string( $value, $root );
		$sanitized = str_replace( $handle, $alias, $sanitized );
		if ( str_contains( $handle, '@' ) ) {
			list( $repo, $slug ) = explode( '@', $handle, 2 );
			$sanitized = str_replace( array( $repo, $slug ), array( $alias, $alias ), $sanitized );
		}

		return $sanitized;
	}

	private static function normalize_root( string $root ): string {
		$root = trim( str_replace( '\\', '/', $root ), '/' );
		$segments = array();
		foreach ( explode( '/', $root ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	private static function sanitize_scoped_string( string $value, string $root ): string {
		$root = self::normalize_root( $root );
		if ( '' === $root ) {
			return $value;
		}

		$sanitized = str_replace( array( 'a/' . $root . '/', 'b/' . $root . '/' ), array( 'a/', 'b/' ), $value );
		$sanitized = str_replace( $root . '/', '', $sanitized );

		return self::unscope_path( $sanitized, $root );
	}
}
