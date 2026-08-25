<?php
/**
 * Bounded identity comparison for the loaded plugin and one managed source.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

use DataMachineCode\Support\RuntimeCapabilities;

defined('ABSPATH') || exit;

final class RuntimeSourceSkewDiagnostic {
	private const MAX_ENTRYPOINT_SIZE = 1048576;

	/** @return array<string,mixed> */
	public static function inspect( string $runtime_file, string $runtime_version, string $source_path = '', array $source_resolution = array() ): array {
		$runtime = self::identity(dirname($runtime_file), $runtime_version);
		$source  = self::source_identity($source_path, $source_resolution);

		return array(
			'read_only'         => true,
			'bounded'           => true,
			'active_runtime'    => $runtime,
			'managed_source'    => $source,
			'source_resolution' => $source_resolution,
			'skew'              => self::classify($runtime, $source),
			'source_note'       => 'Managed source identity is comparison evidence only; it does not establish what is deployed.',
		);
	}

	/** @param array<string,mixed> $runtime @param array<string,mixed> $source @return array<string,mixed> */
	public static function classify( array $runtime, array $source ): array {
		if ( 'managed_source_ambiguous' === ( $source['reason'] ?? '' ) ) {
			return self::recovery('ambiguous', 'More than one registered source checkout matches the runtime repository.');
		}
		if ( empty($runtime['available']) || empty($source['available']) || ! self::is_version($runtime['version'] ?? null) || ! self::is_version($source['version'] ?? null) ) {
			return self::recovery('unknown', 'Runtime and managed source versions are not both available.');
		}

		$comparison = version_compare( (string) $runtime['version'], (string) $source['version'] );
		if ( $comparison < 0 ) {
			return self::recovery('older', 'The active runtime version is older than the managed source version.');
		}
		if ( $comparison > 0 ) {
			return self::recovery('newer', 'The active runtime version is newer than the managed source version.');
		}
		if ( '' !== ( $runtime['build'] ?? '' ) && '' !== ( $source['build'] ?? '' ) ) {
			if ( self::build_family($runtime) !== self::build_family($source) ) {
				return self::recovery('unknown', 'Runtime and managed source versions match, but their build identity kinds are not comparable.');
			}
			if ( $runtime['build'] === $source['build'] ) {
				return self::recovery('current', 'Runtime and managed source resolve to the same build.');
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
			return array(
				'available' => false,
				'path'      => $path,
				'reason'    => 'entrypoint_unavailable',
			);
		}
		$size = filesize($file);
		if ( false === $size || $size > self::MAX_ENTRYPOINT_SIZE ) {
			return array(
				'available' => false,
				'path'      => $path,
				'reason'    => 'entrypoint_size_unavailable',
			);
		}
		$real_path = realpath($path);
		$real_path = false === $real_path ? $path : $real_path;
		$body      = file_get_contents($file, false, null, 0, self::MAX_ENTRYPOINT_SIZE); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local entrypoint metadata.
		if ( ! is_string($body) ) {
			return array(
				'available' => false,
				'path'      => $path,
				'reason'    => 'entrypoint_unreadable',
			);
		}
		$version        = '' === $version ? self::header($body, 'Version') : $version;
		$git            = is_dir($real_path . '/.git') || is_file($real_path . '/.git');
		$head           = $git ? self::git_head($real_path) : '';
		$package_commit = self::header($body, 'Package-Source-Commit');
		$build          = '' !== $head ? $head : $package_commit;
		$build          = '' === $build ? hash('sha256', $body) : $build;

		return array(
			'available'  => true,
			'path'       => $path,
			'real_path'  => $real_path,
			'version'    => $version,
			'build'      => $build,
			'build_kind' => '' !== $head ? 'git_head' : ( '' !== $package_commit ? 'package_source_commit' : 'entrypoint_digest' ),
			'kind'       => is_link($path) ? 'symlink' : ( $git ? 'git_checkout' : 'plugin_directory' ),
		);
	}

	/** @return array<string,mixed> */
	private static function source_identity( string $path, array $resolution ): array {
		$state = (string) ( $resolution['state'] ?? '' );
		if ( 'ambiguous' === $state ) {
			return array(
				'available'  => false,
				'reason'     => 'managed_source_ambiguous',
				'candidates' => array_values( (array) ( $resolution['candidates'] ?? array() ) ),
			);
		}
		if ( '' !== $state && 'resolved' !== $state ) {
			return array(
				'available' => false,
				'reason'    => (string) ( $resolution['reason'] ?? 'managed_source_unavailable' ),
			);
		}
		if ( '' === trim($path) ) {
			return array(
				'available' => false,
				'reason'    => 'managed_source_not_configured',
			);
		}
		$identity = self::identity($path, '');
		if ( 'resolved' === $state ) {
			$identity['authority'] = (string) ( $resolution['authority'] ?? 'registered_workspace' );
			foreach ( (array) ( $resolution['candidates'] ?? array() ) as $candidate ) {
				if ( is_array($candidate) && ( $candidate['path'] ?? null ) === $path ) {
					$identity['workspace_handle'] = (string) ( $candidate['handle'] ?? '' );
					$identity['repository']       = (string) ( $candidate['repository'] ?? '' );
					break;
				}
			}
		}
		return $identity;
	}

	/** @return array<string,mixed> */
	private static function recovery( string $classification, string $reason ): array {
		$recovery = match ( $classification ) {
			'older' => array(
				'kind'     => 'runtime_refresh',
				'guidance' => 'Refresh the active plugin through its owning update or deployment mechanism, then rerun this diagnostic.',
			),
			'newer' => array(
				'kind'     => 'source_repair',
				'guidance' => 'Refresh or repair the managed source checkout, then rerun this diagnostic before treating it as comparison evidence.',
			),
			'diverged' => array(
				'kind'     => 'source_or_runtime_repair',
				'guidance' => 'Compare the two builds and refresh the intended runtime or repair the managed source checkout.',
			),
			'ambiguous' => array(
				'kind'     => 'source_ambiguity',
				'guidance' => 'Remove or rename duplicate registered source primaries so one authoritative checkout remains.',
			),
			'current' => null,
			default => array(
				'kind'     => 'inspect',
				'guidance' => 'Configure or restore one managed source path and rerun this diagnostic.',
			),
		};
		return array(
			'classification' => $classification,
			'reason'         => $reason,
			'recovery'       => $recovery,
		);
	}

	private static function header( string $body, string $name ): string {
		return preg_match('/^\s*\*\s*' . preg_quote($name, '/') . ':\s*(.+)$/mi', $body, $matches) ? trim($matches[1]) : '';
	}

	private static function is_version( mixed $version ): bool {
		return is_string($version) && 1 === preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version);
	}

	/** @param array<string,mixed> $identity */
	private static function build_family( array $identity ): string {
		$kind = (string) ( $identity['build_kind'] ?? '' );
		return in_array($kind, array( 'git_head', 'package_source_commit' ), true) ? 'source_commit' : $kind;
	}

	private static function git_head( string $path ): string {
		$shell = RuntimeCapabilities::shell_diagnostic();
		if ( empty($shell['exec_available']) ) {
			return '';
		}
		$output = array();
		$status = 1;
		exec('git -C ' . escapeshellarg($path) . ' rev-parse HEAD 2>/dev/null', $output, $status); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A single bounded Git metadata probe.
		return 0 === $status ? trim(implode("\n", $output)) : '';
	}
}
