<?php
/**
 * Bounded identity comparison for the loaded plugin and one managed source.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

defined('ABSPATH') || exit;

final class RuntimeSourceSkewDiagnostic {

	/** @return array<string,mixed> */
	public static function inspect( string $runtime_file, string $runtime_version, string $source_path = '' ): array {
		$runtime = self::identity(dirname($runtime_file), $runtime_version);
		$source  = self::source_identity($source_path);

		return array(
			'read_only'       => true,
			'bounded'         => true,
			'active_runtime'  => $runtime,
			'managed_source'  => $source,
			'skew'            => self::classify($runtime, $source),
			'source_note'     => 'Managed source identity is comparison evidence only; it does not establish what is deployed.',
		);
	}

	/** @param array<string,mixed> $runtime @param array<string,mixed> $source @return array<string,mixed> */
	public static function classify( array $runtime, array $source ): array {
		if ( empty($runtime['available']) || empty($source['available']) || ! self::is_version($runtime['version'] ?? null) || ! self::is_version($source['version'] ?? null) ) {
			return self::recovery('unknown', 'Runtime and managed source versions are not both available.');
		}

		$comparison = version_compare((string) $runtime['version'], (string) $source['version']);
		if ( $comparison < 0 ) {
			return self::recovery('older', 'The active runtime version is older than the managed source version.');
		}
		if ( $comparison > 0 ) {
			return self::recovery('newer', 'The active runtime version is newer than the managed source version.');
		}
		if ( '' !== ( $runtime['build'] ?? '' ) && '' !== ( $source['build'] ?? '' ) ) {
			if ( $runtime['build'] === $source['build'] ) {
				return self::recovery('aligned', 'Runtime and managed source resolve to the same build.');
			}
			return self::recovery('diverged', 'Runtime and managed source have the same version but different builds.');
		}

		return self::recovery('unknown', 'Runtime and managed source versions match, but build identity is unavailable.');
	}

	/** @return array<string,mixed> */
	private static function identity( string $path, string $version ): array {
		$path = rtrim($path, '/');
		$file = $path . '/data-machine-code.php';
		if ( '' === $path || ! is_readable($file) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Local entrypoint metadata.
			return array( 'available' => false, 'path' => $path, 'reason' => 'entrypoint_unavailable' );
		}
		$real_path = realpath($path);
		$real_path = false === $real_path ? $path : $real_path;
		$body      = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local entrypoint metadata.
		$version   = '' === $version ? self::header($body, 'Version') : $version;
		$git       = is_dir($real_path . '/.git') || is_file($real_path . '/.git');
		$head      = $git ? self::git_head($real_path) : '';
		$build     = $head ?: self::header($body, 'Package-Source-Commit');
		$build     = '' === $build ? (string) hash_file('sha256', $file) : $build;

		return array(
			'available' => true,
			'path'      => $path,
			'real_path' => $real_path,
			'version'   => $version,
			'build'     => $build,
			'build_kind' => '' !== $head ? 'git_head' : ( '' !== self::header($body, 'Package-Source-Commit') ? 'package_source_commit' : 'entrypoint_digest' ),
			'kind'      => is_link($path) ? 'symlink' : ( $git ? 'git_checkout' : 'plugin_directory' ),
		);
	}

	/** @return array<string,mixed> */
	private static function source_identity( string $path ): array {
		if ( '' === trim($path) ) {
			return array( 'available' => false, 'reason' => 'managed_source_not_configured' );
		}
		return self::identity($path, '');
	}

	/** @return array<string,mixed> */
	private static function recovery( string $classification, string $reason ): array {
		$recovery = match ($classification) {
			'older' => array( 'kind' => 'runtime_refresh', 'guidance' => 'Refresh the active plugin through its owning update or deployment mechanism, then rerun this diagnostic.' ),
			'newer' => array( 'kind' => 'source_repair', 'guidance' => 'Refresh or repair the managed source checkout, then rerun this diagnostic before treating it as comparison evidence.' ),
			'diverged' => array( 'kind' => 'source_or_runtime_repair', 'guidance' => 'Compare the two builds and refresh the intended runtime or repair the managed source checkout.' ),
			default => array( 'kind' => 'inspect', 'guidance' => 'Configure or restore one managed source path and rerun this diagnostic.' ),
		};
		return array( 'classification' => $classification, 'reason' => $reason, 'recovery' => $recovery );
	}

	private static function header( string $body, string $name ): string {
		return preg_match('/^\s*\*\s*' . preg_quote($name, '/') . ':\s*(.+)$/mi', $body, $matches) ? trim($matches[1]) : '';
	}

	private static function is_version( mixed $version ): bool {
		return is_string($version) && 1 === preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version);
	}

	private static function git_head( string $path ): string {
		$output = array();
		$status = 1;
		exec('git -C ' . escapeshellarg($path) . ' rev-parse HEAD 2>/dev/null', $output, $status); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A single bounded Git metadata probe.
		return 0 === $status ? trim(implode("\n", $output)) : '';
	}
}
