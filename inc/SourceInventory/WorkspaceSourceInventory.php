<?php
/**
 * Workspace-backed source inventory executor.
 *
 * @package DataMachineCode\SourceInventory
 */

namespace DataMachineCode\SourceInventory;

use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

class WorkspaceSourceInventory {

	private const KIND = 'workspace_files';

	public static function register(): void {
		add_filter('datamachine_source_inventory_capabilities', array( self::class, 'capabilities' ), 10, 2);
		add_filter('datamachine_source_aggregate_page_callback', array( self::class, 'page_callback' ), 10, 3);
		add_filter('datamachine_source_inventory_page_callback', array( self::class, 'page_callback' ), 10, 3);
	}

	/**
	 * @param array<string,mixed> $capabilities Source capabilities.
	 * @param array<string,mixed> $source Source descriptor.
	 * @return array<string,mixed>
	 */
	public static function capabilities( array $capabilities, array $source ): array {
		if ( self::KIND !== ( $source['kind'] ?? '' ) ) {
			return $capabilities;
		}

		return array_merge(
			$capabilities,
			array(
				'enumerable'      => true,
				'has_total_count' => true,
				'stable_ids'      => true,
			)
		);
	}

	/**
	 * @param callable|null       $callback Existing callback.
	 * @param array<string,mixed> $source Source descriptor.
	 * @param array<string,mixed> $input Ability input.
	 */
	public static function page_callback( $callback, array $source, array $_input ): ?callable {
		unset( $_input );

		if ( is_callable( $callback ) || self::KIND !== ( $source['kind'] ?? '' ) ) {
			return $callback;
		}

		return static function ( array $params, array $state ) use ( $source ): array {
			return self::page( $source, $params, $state );
		};
	}

	/**
	 * @param array<string,mixed> $source Source descriptor.
	 * @param array<string,mixed> $params Page params.
	 * @param array<string,mixed> $state Page state.
	 * @return array<string,mixed>
	 */
	private static function page( array $source, array $params, array $state ): array {
		$workspace = new Workspace();
		$handle    = sanitize_text_field( (string) ( $source['handle'] ?? $source['repo'] ?? '' ) );
		$base_path = ltrim( (string) ( $source['path'] ?? '' ), '/' );

		if ( '' === $handle ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => 'workspace_handle_required',
			);
		}

		$repo_path = $workspace->get_repo_path( $handle );
		if ( ! is_dir( $repo_path ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => 'workspace_repo_not_found',
			);
		}

		$repo_real = realpath( $repo_path );
		if ( false === $repo_real ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => 'workspace_repo_not_found',
			);
		}

		$target = '' === $base_path ? $repo_real : $repo_real . '/' . $base_path;
		$check  = $workspace->validate_containment( $target, $repo_real );
		if ( empty( $check['valid'] ) || empty( $check['real_path'] ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => 'workspace_path_not_allowed',
			);
		}

		$target_real = (string) $check['real_path'];
		if ( ! is_file( $target_real ) && ! is_dir( $target_real ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => 'workspace_path_not_found',
			);
		}

		$include_patterns = self::string_list( $source['include'] ?? array( '*.php' ) );
		$exclude_patterns = self::string_list( $source['exclude'] ?? array( '.git/*', 'node_modules/*', 'vendor/*' ) );
		$max              = max( 1, min( 10000, (int) ( $source['max_files'] ?? 1000 ) ) );
		$files            = self::collect_files( $repo_real, $target_real, $include_patterns, $exclude_patterns, $max );

		$offset = max( 0, (int) ( $params['offset'] ?? $state['offset'] ?? 0 ) );
		$limit  = max( 1, (int) ( $params['limit'] ?? $state['limit'] ?? 100 ) );

		return array(
			'items' => array_slice( $files, $offset, $limit ),
			'total' => count( $files ),
		);
	}

	/**
	 * @param string[] $include_patterns Include glob patterns.
	 * @param string[] $exclude_patterns Exclude glob patterns.
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect_files( string $repo_real, string $target_real, array $include_patterns, array $exclude_patterns, int $max ): array {
		$paths = is_file( $target_real )
			? array( $target_real )
			: new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $target_real, \FilesystemIterator::SKIP_DOTS )
			);

		$items = array();
		foreach ( $paths as $path ) {
			$file_path = is_string( $path ) ? $path : $path->getPathname();
			if ( ! is_file( $file_path ) ) {
				continue;
			}

			$relative = ltrim( substr( $file_path, strlen( $repo_real ) ), '/' );
			if ( ! self::matches( $relative, $include_patterns ) || self::matches( $relative, $exclude_patterns ) ) {
				continue;
			}

			$items[] = array(
				'id'          => $relative,
				'item_type'   => 'source-file',
				'source_path' => $relative,
				'size'        => filesize( $file_path ),
			);

			if ( count( $items ) >= $max ) {
				break;
			}
		}

		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) $a['source_path'], (string) $b['source_path'] ) );
		return $items;
	}

	/** @return string[] */
	private static function string_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $value ), static fn( string $item ): bool => '' !== trim( $item ) ) );
	}

	/** @param string[] $patterns Patterns. */
	private static function matches( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( fnmatch( $pattern, $path ) || fnmatch( $pattern, basename( $path ) ) ) {
				return true;
			}
		}

		return false;
	}
}
