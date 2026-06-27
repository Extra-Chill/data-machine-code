<?php
/**
 * Self-configuration for mounted runtimes.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

use DataMachineCode\Abilities\WorkspaceAbilities;
use DataMachineCode\Support\RuntimeCapabilities;

defined('ABSPATH') || exit;

if ( ! class_exists(RuntimeCapabilities::class) ) {
	require_once dirname(__DIR__) . '/Support/RuntimeCapabilities.php';
}

final class MountedRuntimeBootstrap {

	private const DEFAULT_WORKSPACE_ROOT = '/workspace';

	/** @var array<string,mixed> */
	private static array $context = array();

	public static function register(): void {
		add_action('mounted_runtime_bootstrap', array( self::class, 'configure' ), 10, 1);
		add_action('wordpress_runtime_bootstrap', array( self::class, 'configure' ), 10, 1);

		$context = self::discover_context();
		if ( self::should_configure($context) ) {
			self::configure($context);
		}
	}

	/**
	 * Configure DMC from a generic mounted-runtime context.
	 *
	 * @param array<string,mixed> $context Runtime context supplied by the mounted runtime substrate.
	 */
	public static function configure( array $context = array() ): void {
		self::$context = self::normalize_workspace_context($context);
		self::configure_workspace_root($context);
		self::force_mounted_workspace_backend();

		add_action('wp_abilities_api_init', array( self::class, 'adopt_workspace_mounts' ), 100);
	}

	/** @return array<string,mixed> */
	public static function context(): array {
		return self::$context;
	}

	/** @return array<string,mixed> */
	private static function discover_context(): array {
		foreach ( array( 'mounted_runtime_context', 'wordpress_runtime_context' ) as $global_key ) {
			$context = $GLOBALS[ $global_key ] ?? null;
			if ( is_array($context) ) {
				return self::normalize_context($context);
			}
		}

		foreach ( array( 'MOUNTED_RUNTIME_CONTEXT', 'WORDPRESS_RUNTIME_CONTEXT' ) as $env_key ) {
			$encoded = getenv($env_key);
			if ( is_string($encoded) && '' !== $encoded ) {
				$decoded = json_decode($encoded, true);
				if ( is_array($decoded) ) {
					return self::normalize_context($decoded);
				}
			}
		}

		$workspace_root = getenv('MOUNTED_RUNTIME_WORKSPACE_ROOT');
		if ( is_string($workspace_root) && '' !== $workspace_root ) {
			return array( 'workspace_root' => $workspace_root );
		}

		return self::path_allowed_by_open_basedir(self::DEFAULT_WORKSPACE_ROOT) && is_dir(self::DEFAULT_WORKSPACE_ROOT)
			? array( 'workspace_root' => self::DEFAULT_WORKSPACE_ROOT )
			: array();
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function normalize_context( array $context ): array {
		$schema = (string) ( $context['schema'] ?? '' );
		if ( ! RuntimeCapabilities::supports_runtime_context_schema($schema) ) {
			return self::normalize_workspace_context($context);
		}

		$payload = is_array($context['payload'] ?? null) ? $context['payload'] : $context;
		if ( ! isset($payload['schema']) ) {
			$payload['schema'] = $schema;
		}

		return self::normalize_workspace_context($payload);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function normalize_workspace_context( array $context ): array {
		return $context;
	}

	private static function path_allowed_by_open_basedir( string $path ): bool {
		$open_basedir = ini_get('open_basedir');
		if ( false === $open_basedir || '' === $open_basedir ) {
			return true;
		}

		$path = rtrim($path, '/');
		foreach ( explode(PATH_SEPARATOR, $open_basedir) as $allowed_path ) {
			$allowed_path = rtrim($allowed_path, '/');
			if ( '' === $allowed_path ) {
				continue;
			}

			if ( $path === $allowed_path || str_starts_with($path . '/', $allowed_path . '/') ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $context */
	private static function should_configure( array $context ): bool {
		return '' !== self::workspace_root_from_context($context);
	}

	/** @param array<string,mixed> $context */
	private static function configure_workspace_root( array $context ): void {
		if ( defined('DATAMACHINE_WORKSPACE_PATH') ) {
			return;
		}

		$root = self::workspace_root_from_context($context);
		if ( '' === $root ) {
			return;
		}

		if ( ! is_dir($root) ) {
			if ( function_exists('wp_mkdir_p') ) {
				wp_mkdir_p($root);
			} else {
				@mkdir($root, 0775, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
		}

		if ( is_dir($root) ) {
			define('DATAMACHINE_WORKSPACE_PATH', rtrim($root, '/'));
		}
	}

	private static function force_mounted_workspace_backend(): void {
		add_filter('datamachine_code_remote_workspace_backend_should_handle', '__return_false', 100);
	}

	public static function adopt_workspace_mounts(): void {
		if ( ! defined('DATAMACHINE_WORKSPACE_PATH') || ! class_exists(WorkspaceAbilities::class) ) {
			return;
		}

		$workspace_root = rtrim( (string) DATAMACHINE_WORKSPACE_PATH, '/');
		$mounts         = self::workspace_mounts(self::$context, $workspace_root);
		foreach ( $mounts as $mount ) {
			if ( ! empty($mount['repo_backed']) ) {
				continue;
			}

			$path = (string) $mount['path'];
			if ( '' === $path || ! is_dir($path) || ! is_dir($path . '/.git') ) {
				continue;
			}

			$workspace_ref = (string) ( $mount['workspace_ref'] ?? '' );
			WorkspaceAbilities::adoptRepo(
				array(
					'path' => $path,
					'name' => '' !== $workspace_ref ? $workspace_ref : basename($path),
				)
			);
		}
	}

	/** @param array<string,mixed> $context */
	private static function workspace_root_from_context( array $context ): string {
		$root = isset($context['workspace_root']) ? (string) $context['workspace_root'] : '';
		if ( '' === $root ) {
			$workspace = self::runtime_workspace_from_context($context);
			$root      = isset($workspace['root']) ? (string) $workspace['root'] : '';
		}

		return '' !== $root ? rtrim($root, '/') : '';
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<int,array{path:string,repo_backed:bool,workspace_ref?:string}>
	 */
	private static function workspace_mounts( array $context, string $workspace_root ): array {
		$workspace = self::runtime_workspace_from_context($context);
		$mounts    = array();
		if ( is_array($workspace['mounts'] ?? null) ) {
			foreach ( $workspace['mounts'] as $mount ) {
				if ( ! is_array($mount) ) {
					continue;
				}
				$target = rtrim( (string) ( $mount['target'] ?? '' ), '/');
				if ( '' === $target || 0 !== strpos($target . '/', $workspace_root . '/') ) {
					continue;
				}
				$workspace_ref = trim( (string) ( $mount['workspaceRef'] ?? '' ) );
				$mounts[]      = array_filter(
					array(
						'path'          => $target,
						'repo_backed'   => 'repo-backed' === (string) ( $mount['sourceMode'] ?? '' ),
						'workspace_ref' => $workspace_ref,
					),
					static fn ( $value ): bool => '' !== $value
				);
			}
		}

		if ( ! empty($mounts) ) {
			return $mounts;
		}

		$paths = glob($workspace_root . '/*', GLOB_ONLYDIR);
		foreach ( false !== $paths ? $paths : array() as $path ) {
			$mounts[] = array(
				'path'        => $path,
				'repo_backed' => is_file($path . '/.git'),
			);
		}

		return $mounts;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function runtime_workspace_from_context( array $context ): array {
		return is_array($context['runtime_workspace'] ?? null) ? $context['runtime_workspace'] : array();
	}
}
