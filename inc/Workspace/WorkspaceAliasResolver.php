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
	 * Return alias => canonical workspace handle mappings.
	 *
	 * Runners can persist aliases in the option, or inject them just-in-time via
	 * the filter when a single conversation should see an opaque project name.
	 *
	 * @return array<string,string>
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
		foreach ( $aliases as $alias => $handle ) {
			$alias  = trim( (string) $alias );
			$handle = trim( (string) $handle );
			if ( '' === $alias || '' === $handle ) {
				continue;
			}
			$normalized[ $alias ] = $handle;
		}

		return $normalized;
	}

	public static function resolve( string $handle ): string {
		$aliases = self::aliases();
		return $aliases[ $handle ] ?? $handle;
	}

	public static function alias_for( string $handle ): ?string {
		foreach ( self::aliases() as $alias => $target ) {
			if ( $target === $handle ) {
				return $alias;
			}
		}

		return null;
	}

	public static function has_alias( string $handle ): bool {
		return isset( self::aliases()[ $handle ] );
	}

	/**
	 * Sanitize model-facing workspace metadata while leaving content/diff fields intact.
	 *
	 * @param mixed  $value  Tool result value.
	 * @param string $alias  Agent-facing alias.
	 * @param string $handle Canonical workspace handle.
	 * @return mixed
	 */
	public static function sanitize_result( mixed $value, string $alias, string $handle ): mixed {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				if ( in_array( $key, array( 'content', 'diff', 'patch' ), true ) ) {
					$sanitized[ $key ] = $item;
					continue;
				}
				$sanitized[ $key ] = self::sanitize_result( $item, $alias, $handle );
			}
			return $sanitized;
		}

		if ( ! is_string( $value ) || '' === $handle ) {
			return $value;
		}

		$sanitized = str_replace( $handle, $alias, $value );
		if ( str_contains( $handle, '@' ) ) {
			list( $repo, $slug ) = explode( '@', $handle, 2 );
			$sanitized = str_replace( array( $repo, $slug ), array( $alias, $alias ), $sanitized );
		}

		return $sanitized;
	}
}
