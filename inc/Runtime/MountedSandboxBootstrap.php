<?php
/**
 * Self-configuration for mounted sandbox runtimes.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

use DataMachineCode\Abilities\WorkspaceAbilities;

defined('ABSPATH') || exit;

final class MountedSandboxBootstrap {

	private const DEFAULT_WORKSPACE_ROOT = '/workspace';

	/** @var array<string,mixed> */
	private static array $context = array();

	public static function register(): void {
		add_action('wp_codebox_sandbox_runtime_bootstrap', array( self::class, 'configure' ), 10, 1);
	}

	/**
	 * Configure DMC from a generic mounted-runtime context.
	 *
	 * @param array<string,mixed> $context Runtime context supplied by the sandbox substrate.
	 */
	public static function configure( array $context = array() ): void {
		self::$context = $context;
		self::configure_workspace_root($context);
		self::force_mounted_workspace_backend();

		add_action('wp_abilities_api_init', array( self::class, 'adopt_workspace_mounts' ), 100);
	}

	/** @return array<string,mixed> */
	public static function context(): array {
		return self::$context;
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

		$workspace_root = rtrim((string) DATAMACHINE_WORKSPACE_PATH, '/');
		$mounts         = self::workspace_mounts(self::$context, $workspace_root);
		foreach ( $mounts as $mount ) {
			if ( ! empty($mount['repo_backed']) ) {
				continue;
			}

			$path = (string) ( $mount['path'] ?? '' );
			if ( '' === $path || ! is_dir($path) || ! is_dir($path . '/.git') ) {
				continue;
			}

			WorkspaceAbilities::adoptRepo(
				array(
					'path' => $path,
					'name' => basename($path),
				)
			);
		}
	}

	/** @param array<string,mixed> $context */
	private static function workspace_root_from_context( array $context ): string {
		$root = isset($context['workspace_root']) ? (string) $context['workspace_root'] : '';
		if ( '' === $root ) {
			$workspace = is_array($context['sandbox_workspace'] ?? null) ? $context['sandbox_workspace'] : array();
			$root      = isset($workspace['root']) ? (string) $workspace['root'] : '';
		}

		return '' !== $root ? rtrim($root, '/') : self::DEFAULT_WORKSPACE_ROOT;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<int,array{path:string,repo_backed:bool}>
	 */
	private static function workspace_mounts( array $context, string $workspace_root ): array {
		$workspace = is_array($context['sandbox_workspace'] ?? null) ? $context['sandbox_workspace'] : array();
		$mounts    = array();
		if ( is_array($workspace['mounts'] ?? null) ) {
			foreach ( $workspace['mounts'] as $mount ) {
				if ( ! is_array($mount) ) {
					continue;
				}
				$target = rtrim((string) ( $mount['target'] ?? '' ), '/');
				if ( '' === $target || 0 !== strpos($target . '/', $workspace_root . '/') ) {
					continue;
				}
				$mounts[] = array(
					'path'        => $target,
					'repo_backed' => 'repo-backed' === (string) ( $mount['sourceMode'] ?? '' ),
				);
			}
		}

		if ( ! empty($mounts) ) {
			return $mounts;
		}

		foreach ( glob($workspace_root . '/*', GLOB_ONLYDIR) ?: array() as $path ) {
			$mounts[] = array(
				'path'        => $path,
				'repo_backed' => is_file($path . '/.git'),
			);
		}

		return $mounts;
	}
}
