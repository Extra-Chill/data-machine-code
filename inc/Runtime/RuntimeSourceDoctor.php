<?php
/**
 * Read-only provenance and drift inspection for a plugin runtime.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

defined('ABSPATH') || exit;

final class RuntimeSourceDoctor {

	/** @return array<string,mixed> */
	public static function inspect( string $runtime_file, string $runtime_version, array $config = array() ): array {
		$runtime_path = dirname($runtime_file);
		$source_path  = trim((string) ($config['source_path'] ?? ''));
		$release_ref  = trim((string) ($config['release_ref'] ?? 'release-latest'));
		$runtime       = self::identity($runtime_path, $runtime_version);
		$source        = '' === $source_path ? array( 'available' => false, 'reason' => 'source_path_not_configured' ) : self::identity($source_path, '');
		$release       = self::releaseIdentity($source_path, $release_ref);
		$contract      = self::contract($source_path, (array) ($config['command_contract'] ?? array()));

		return array(
			'success'                => true,
			'read_only'              => true,
			'runtime'                => $runtime,
			'authoritative_source'   => $source,
			'release_deploy_source'  => $release,
			'command_contract'       => $contract,
			'drift'                  => self::classify($runtime, $source, $contract),
			'reconciliation_command' => 'studio wp datamachine-code runtime doctor --apply',
			'apply_safety'           => 'The default command is read-only. --apply requires a configured source_path and synchronizes only the active plugin directory.',
		);
	}

	/** @return array<string,mixed> */
	public static function apply( string $runtime_file, array $config = array() ): array|\WP_Error {
		$source = trim((string) ($config['source_path'] ?? ''));
		$target = dirname($runtime_file);
		if ( '' === $source || ! is_dir($source) ) {
			return new \WP_Error('runtime_source_unavailable', 'A readable authoritative source_path is required before reconciliation.');
		}
		if ( realpath($source) === realpath($target) ) {
			return array( 'success' => true, 'changed' => false, 'message' => 'Runtime already resolves to the authoritative source.' );
		}
		if ( is_link($target) ) {
			if ( ! unlink($target) || ! symlink($source, $target) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				return new \WP_Error('runtime_reconciliation_failed', 'Could not repoint the runtime symlink.');
			}
			return array( 'success' => true, 'changed' => true, 'message' => 'Runtime symlink repointed to the authoritative source.' );
		}
		return new \WP_Error('runtime_reconciliation_requires_deployer', 'Copied and git runtime deployments require the configured deployer reconciliation command; DMC will not overwrite them generically.');
	}

	/** @param array<string,mixed> $runtime @param array<string,mixed> $source @param array<string,mixed> $contract @return array<string,string> */
	public static function classify( array $runtime, array $source, array $contract ): array {
		if ( ! empty($contract['drift']) ) {
			return array( 'classification' => 'command_contract_drift', 'reason' => 'The source advertises a command flag that the active WP-CLI runtime does not register.' );
		}
		if ( empty($source['available']) ) {
			return array( 'classification' => 'source_unavailable', 'reason' => (string) ($source['reason'] ?? 'authoritative source is unavailable') );
		}
		if ( ($runtime['head'] ?? '') !== '' && ($runtime['head'] ?? '') === ($source['head'] ?? '') ) {
			return array( 'classification' => 'aligned', 'reason' => 'Runtime and authoritative source resolve to the same Git head.' );
		}
		if ( ($runtime['fingerprint'] ?? '') !== '' && ($runtime['fingerprint'] ?? '') === ($source['fingerprint'] ?? '') ) {
			return array( 'classification' => 'aligned', 'reason' => 'Runtime and authoritative source have identical file content.' );
		}
		if ( 'git_checkout' === ($runtime['deployment'] ?? '') && 'git_checkout' === ($source['deployment'] ?? '') ) {
			$relation = (string) ($source['relation'] ?? self::gitRelation((string) $runtime['path'], (string) $source['path']));
			if ( in_array($relation, array( 'behind', 'ahead', 'diverged' ), true) ) {
				return array( 'classification' => 'runtime_' . $relation . '_source', 'reason' => 'Git ancestry comparison identified runtime/source drift.' );
			}
		}
		return array( 'classification' => 'runtime_source_drift', 'reason' => 'Runtime and authoritative source identities differ.' );
	}

	/** @return array<string,mixed> */
	private static function identity( string $path, string $version ): array {
		$path = rtrim($path, '/');
		if ( ! is_dir($path) && ! is_link($path) ) {
			return array( 'available' => false, 'path' => $path, 'reason' => 'path_missing' );
		}
		$real       = realpath($path) ?: $path;
		$deployment = is_link($path) ? 'symlink' : ( is_dir($real . '/.git') || is_file($real . '/.git') ? 'git_checkout' : 'copied_deploy' );
		$head       = 'git_checkout' === $deployment ? self::git($real, 'rev-parse HEAD') : '';
		$branch     = 'git_checkout' === $deployment ? self::git($real, 'branch --show-current') : '';
		if ( '' === $version ) {
			$version = self::pluginVersion($real);
		}
		return array( 'available' => true, 'path' => $path, 'real_path' => $real, 'deployment' => $deployment, 'version' => $version, 'branch' => $branch, 'head' => $head, 'fingerprint' => self::fingerprint($real) );
	}

	/** @return array<string,mixed> */
	private static function releaseIdentity( string $source_path, string $release_ref ): array {
		if ( '' === $source_path || ! is_dir($source_path) || '' === self::git($source_path, 'rev-parse --git-dir') ) {
			return array( 'available' => false, 'ref' => $release_ref, 'reason' => 'source_git_checkout_unavailable' );
		}
		$head = self::git($source_path, 'rev-parse ' . escapeshellarg($release_ref));
		$body = self::git($source_path, 'show ' . escapeshellarg($release_ref . ':data-machine-code.php'));
		return '' === $head ? array( 'available' => false, 'ref' => $release_ref, 'reason' => 'release_ref_unavailable' ) : array( 'available' => true, 'ref' => $release_ref, 'head' => $head, 'version' => self::versionFromBody($body) );
	}

	/** @return array<string,mixed> */
	private static function contract( string $source_path, array $contract ): array {
		$flag = trim((string) ($contract['flag'] ?? ''));
		if ( '' === $flag ) {
			return array( 'checked' => false );
		}
		$source_supports  = is_dir($source_path) && false !== strpos((string) @shell_exec('grep -R -- ' . escapeshellarg($flag) . ' ' . escapeshellarg($source_path) . ' 2>/dev/null'), $flag); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$runtime_supports = isset($contract['runtime_supports']) ? (bool) $contract['runtime_supports'] : null;
		return array( 'checked' => null !== $runtime_supports, 'flag' => $flag, 'source_supports' => $source_supports, 'runtime_supports' => $runtime_supports, 'drift' => $source_supports && false === $runtime_supports );
	}

	private static function pluginVersion( string $path ): string {
		$file = $path . '/data-machine-code.php';
		$body = is_readable($file) ? (string) file_get_contents($file) : '';
		return self::versionFromBody($body);
	}

	private static function versionFromBody( string $body ): string {
		return preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $body, $matches) ? trim($matches[1]) : '';
	}

	private static function fingerprint( string $path ): string {
		try {
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		} catch ( \UnexpectedValueException ) {
			return '';
		}
		$hash = hash_init('sha256');
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) ) {
				continue;
			}
			hash_update($hash, substr($file->getPathname(), strlen($path)) . "\0" . hash_file('sha256', $file->getPathname()) . "\0");
		}
		return hash_final($hash);
	}

	private static function git( string $path, string $args ): string {
		return trim((string) @shell_exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>/dev/null')); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
	}

	private static function gitRelation( string $runtime, string $source ): string {
		$runtime_head = self::git($runtime, 'rev-parse HEAD');
		$source_head  = self::git($source, 'rev-parse HEAD');
		if ( '' === $runtime_head || '' === $source_head ) { return 'unknown'; }
		if ( $runtime_head === $source_head ) { return 'aligned'; }
		if ( $runtime_head === self::git($source, 'merge-base HEAD ' . escapeshellarg($runtime_head)) ) { return 'behind'; }
		if ( $source_head === self::git($runtime, 'merge-base HEAD ' . escapeshellarg($source_head)) ) { return 'ahead'; }
		return 'diverged';
	}
}
