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
		$runtime       = self::identity($runtime_path, $runtime_version, false);
		$source        = self::sourceIdentity($source_path, false);
		$release       = self::releaseIdentity($source_path, $release_ref);
		$trusted_provenance = is_array($config['trusted_release_provenance'] ?? null) ? $config['trusted_release_provenance'] : array();
		$runtime['package_provenance'] = self::packageProvenance($runtime, $source_path, $release, $trusted_provenance);
		$contract      = self::contract($source_path, (array) ($config['command_contract'] ?? array()));
		$drift         = self::classify($runtime, $source, $contract);

		// File fingerprints are a fallback for copied deployments. Avoid walking
		// large plugin trees when version or Git evidence already identifies drift.
		if ( 'runtime_source_drift' === $drift['classification'] && 'verified' !== ($runtime['package_provenance']['state'] ?? '') ) {
			$runtime['fingerprint'] = self::fingerprint($runtime_path);
			$source['fingerprint']  = ! empty($source['available']) ? self::fingerprint($source_path) : '';
			$drift = self::classify($runtime, $source, $contract);
		}

		return array(
			'success'                => true,
			'read_only'              => true,
			'runtime'                => $runtime,
			'authoritative_source'   => $source,
			'release_deploy_source'  => $release,
			'trusted_release_provenance' => self::trustedProvenanceIdentity($trusted_provenance),
			'command_contract'       => $contract,
			'drift'                  => $drift,
			'reconciliation_command' => 'studio wp datamachine-code runtime doctor --apply',
			'apply_safety'           => 'The default command is read-only. --apply requires a configured source_path and synchronizes only the active plugin directory.',
		);
	}

	/** @return array<string,mixed> */
	public static function apply( string $runtime_file, array $config = array() ): array|\WP_Error {
		$source = trim((string) ($config['source_path'] ?? ''));
		$target = dirname($runtime_file);
		if ( ! self::isSource($source) ) {
			return new \WP_Error('runtime_source_unavailable', 'A readable authoritative source_path with data-machine-code.php is required before reconciliation.');
		}
		if ( realpath($source) === realpath($target) ) {
			return array( 'success' => true, 'changed' => false, 'message' => 'Runtime already resolves to the authoritative source.' );
		}
		if ( is_link($target) ) {
			$replacement = $target . '.dmc-reconcile-' . bin2hex(random_bytes(8));
			if ( ! symlink($source, $replacement) || realpath($replacement) !== realpath($source) || ! is_readable($replacement . '/data-machine-code.php') ) {
				if ( is_link($replacement) ) {
					unlink($replacement); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return new \WP_Error('runtime_reconciliation_failed', 'Could not create and validate a replacement runtime symlink.');
			}
			$rename = $config['rename'] ?? 'rename';
			if ( ! is_callable($rename) || ! call_user_func($rename, $replacement, $target) ) {
				unlink($replacement); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				return new \WP_Error('runtime_reconciliation_failed', 'Could not atomically replace the runtime symlink. The existing runtime was preserved.');
			}
			return self::verifiedApplyResult($runtime_file, $config, true, 'Runtime symlink atomically repointed to the authoritative source.');
		}

		$reconciler = self::externalReconciler($config);
		if ( ! is_callable($reconciler['reconcile']) ) {
			return array(
				'success' => false,
				'state' => 'handoff_required',
				'changed' => false,
				'message' => 'This copied or Git runtime has no registered external reconciler.',
				'action' => $reconciler['action'],
				'owner' => $reconciler['owner'],
			);
		}

		try {
			$result = call_user_func($reconciler['reconcile'], array(
				'runtime' => self::identity($target, '', false),
				'authoritative_source' => self::sourceIdentity($source, false),
				'release_deploy_source' => self::releaseIdentity($source, trim((string) ($config['release_ref'] ?? 'release-latest'))),
			));
		} catch ( \Throwable $error ) {
			return array( 'success' => false, 'state' => 'failed', 'changed' => false, 'message' => 'External runtime reconciliation failed: ' . $error->getMessage(), 'action' => $reconciler['action'], 'owner' => $reconciler['owner'] );
		}
		if ( ! is_array($result) || true !== ($result['success'] ?? false) ) {
			return array( 'success' => false, 'state' => 'failed', 'changed' => false, 'message' => is_array($result) ? (string) ($result['message'] ?? 'External runtime reconciler reported failure.') : 'External runtime reconciler returned an invalid result.', 'action' => $reconciler['action'], 'owner' => $reconciler['owner'] );
		}
		return self::verifiedApplyResult($runtime_file, $config, (bool) ($result['changed'] ?? true), (string) ($result['message'] ?? 'External runtime reconciliation completed.'), $reconciler);
	}

	/** @return array<string,mixed> */
	private static function verifiedApplyResult( string $runtime_file, array $config, bool $changed, string $message, array $reconciler = array() ): array {
		$after = self::inspect($runtime_file, '', $config);
		if ( 'aligned' !== ($after['drift']['classification'] ?? '') ) {
			return array( 'success' => false, 'state' => 'verification_failed', 'changed' => $changed, 'message' => 'Runtime reconciliation completed but active runtime identity did not verify.', 'verification' => $after, 'action' => $reconciler['action'] ?? null, 'owner' => $reconciler['owner'] ?? null );
		}
		return array( 'success' => true, 'state' => 'converged', 'changed' => $changed, 'message' => $message, 'verification' => $after );
	}

	/** @return array{owner:string,action:array<string,mixed>,reconcile:mixed,authorized:bool} */
	private static function externalReconciler( array $config ): array {
		$reconciler = is_array($config['external_reconciler'] ?? null) ? $config['external_reconciler'] : array();
		$action = is_array($reconciler['action'] ?? null) ? $reconciler['action'] : array();
		$authorized = false;
		if ( is_callable($action['authorize_callback'] ?? null) ) {
			try { $authorized = true === call_user_func($action['authorize_callback']); } catch ( \Throwable ) { $authorized = false; }
		} elseif ( true === ($action['authorize_callback'] ?? false) ) {
			$authorized = true;
		}
		if ( 'command' !== ($action['type'] ?? '') || '' === trim((string) ($action['command'] ?? '')) || ! $authorized ) {
			$action = array( 'type' => 'handoff', 'code' => 'runtime_external_reconciliation_required', 'message' => 'Use the runtime owner\'s deployment integration to reconcile this copied or Git runtime.' );
			$reconciler['reconcile'] = null;
		}
		return array( 'owner' => trim((string) ($reconciler['owner'] ?? 'runtime owner')), 'action' => $action, 'reconcile' => $reconciler['reconcile'] ?? null, 'authorized' => $authorized );
	}

	/** @param array<string,mixed> $runtime @param array<string,mixed> $source @param array<string,mixed> $contract @return array<string,string> */
	public static function classify( array $runtime, array $source, array $contract ): array {
		if ( ! empty($contract['drift']) ) {
			return array( 'classification' => 'command_contract_drift', 'reason' => 'The source advertises a command flag that the active WP-CLI runtime does not register.' );
		}
		if ( empty($source['available']) ) {
			return array( 'classification' => 'source_unavailable', 'reason' => (string) ($source['reason'] ?? 'authoritative source is unavailable') );
		}
		if ( self::isVersion($runtime['version'] ?? '') && self::isVersion($source['version'] ?? '') ) {
			$comparison = version_compare((string) $runtime['version'], (string) $source['version']);
			if ( $comparison < 0 ) {
				return array( 'classification' => 'runtime_predates_source', 'reason' => 'The installed runtime version predates the authoritative source version.' );
			}
		}
		if ( ($runtime['head'] ?? '') !== '' && ($runtime['head'] ?? '') === ($source['head'] ?? '') ) {
			return array( 'classification' => 'aligned', 'reason' => 'Runtime and authoritative source resolve to the same Git head.' );
		}
		if ( 'verified' === ($runtime['package_provenance']['state'] ?? '') ) {
			return array( 'classification' => 'aligned', 'reason' => 'Runtime package provenance matches the configured release source.' );
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
	private static function identity( string $path, string $version, bool $include_fingerprint = true ): array {
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
		return array( 'available' => true, 'path' => $path, 'real_path' => $real, 'deployment' => $deployment, 'version' => $version, 'branch' => $branch, 'head' => $head, 'fingerprint' => $include_fingerprint ? self::fingerprint($real) : '', 'package_headers' => self::packageHeaders($real) );
	}

	/** @return array<string,mixed> */
	private static function sourceIdentity( string $path, bool $include_fingerprint = true ): array {
		if ( '' === $path ) {
			return array( 'available' => false, 'reason' => 'source_path_not_configured' );
		}
		if ( ! self::isSource($path) ) {
			return array( 'available' => false, 'path' => $path, 'reason' => 'source_entrypoint_missing' );
		}
		return self::identity($path, '', $include_fingerprint);
	}

	private static function isSource( string $path ): bool {
		return is_dir($path) && is_readable(rtrim($path, '/') . '/data-machine-code.php');
	}

	/** @return array<string,mixed> */
	private static function releaseIdentity( string $source_path, string $release_ref ): array {
		if ( '' === $source_path || ! is_dir($source_path) || '' === self::git($source_path, 'rev-parse --git-dir') ) {
			return array( 'available' => false, 'ref' => $release_ref, 'reason' => 'source_git_checkout_unavailable' );
		}
		$tag_ref = 'refs/tags/' . ltrim($release_ref, '/');
		$tag_head = self::git($source_path, 'rev-parse --verify ' . escapeshellarg($tag_ref . '^{commit}'));
		if ( '' !== $tag_head ) {
			$body = self::git($source_path, 'show ' . escapeshellarg($tag_head . ':data-machine-code.php'));
			return array( 'available' => true, 'ref' => $release_ref, 'resolved_ref' => $tag_ref, 'kind' => 'immutable_tag', 'head' => $tag_head, 'version' => self::versionFromBody($body) );
		}

		$branch = preg_replace('#^origin/#', '', $release_ref) ?: '';
		$remote_ref = 'refs/remotes/origin/' . $branch;
		$head = self::git($source_path, 'rev-parse --verify ' . escapeshellarg($remote_ref));
		$local = self::git($source_path, 'rev-parse --verify ' . escapeshellarg('refs/heads/' . $branch));
		if ( '' === $head ) {
			return array( 'available' => false, 'ref' => $release_ref, 'resolved_ref' => $remote_ref, 'reason' => '' === $local ? 'remote_tracking_ref_unavailable' : 'local_ref_not_accepted', 'local_ref' => $local );
		}
		$body = self::git($source_path, 'show ' . escapeshellarg($head . ':data-machine-code.php'));
		return array( 'available' => true, 'ref' => $release_ref, 'resolved_ref' => $remote_ref, 'kind' => 'remote_tracking', 'head' => $head, 'version' => self::versionFromBody($body), 'local_ref' => $local, 'local_ref_state' => self::localRefState($source_path, $local, $head) );
	}

	private static function localRefState( string $path, string $local, string $remote ): string {
		if ( '' === $local ) { return 'absent'; }
		if ( $local === $remote ) { return 'aligned'; }
		if ( $local === self::git($path, 'merge-base ' . escapeshellarg($local) . ' ' . escapeshellarg($remote)) ) { return 'stale'; }
		if ( $remote === self::git($path, 'merge-base ' . escapeshellarg($local) . ' ' . escapeshellarg($remote)) ) { return 'ahead'; }
		return 'diverged';
	}

	/** @return array<string,string> */
	private static function packageHeaders( string $path ): array {
		$body = is_readable($path . '/data-machine-code.php') ? (string) file_get_contents($path . '/data-machine-code.php') : '';
		$headers = array();
		foreach ( array( 'Package-Version', 'Package-Source-Tag', 'Package-Source-Commit', 'Package-Digest' ) as $name ) {
			if ( preg_match('/^\s*\*\s*' . preg_quote($name, '/') . ':\s*(.+)$/mi', $body, $matches) ) { $headers[$name] = trim($matches[1]); }
		}
		return $headers;
	}

	/** @return array<string,mixed> */
	private static function packageProvenance( array $runtime, string $source_path, array $release, array $trusted ): array {
		$headers = (array) ($runtime['package_headers'] ?? array());
		$required = array( 'Package-Version', 'Package-Source-Tag', 'Package-Source-Commit', 'Package-Digest' );
		foreach ( $required as $field ) { if ( '' === trim((string) ($headers[$field] ?? '')) ) { return array( 'state' => 'not_present' ); } }
		if ( ! self::isTrustedProvenance($trusted) ) { return array( 'state' => 'untrusted', 'reason' => 'trusted_release_provenance_unavailable' ); }
		$digest = self::fingerprint((string) ($runtime['real_path'] ?? ''), true);
		$tag = self::git($source_path, 'rev-parse --verify ' . escapeshellarg('refs/tags/' . $headers['Package-Source-Tag'] . '^{commit}'));
		if ( ! hash_equals((string) $trusted['package_digest'], $digest) || ! hash_equals((string) $trusted['package_digest'], (string) $headers['Package-Digest']) ) { return array( 'state' => 'invalid', 'reason' => 'package_digest_mismatch' ); }
		if ( empty($release['available']) || $headers['Package-Source-Commit'] !== ($release['head'] ?? '') || $tag !== $headers['Package-Source-Commit'] || $headers['Package-Version'] !== ($runtime['version'] ?? '') || $headers['Package-Version'] !== ($release['version'] ?? '') || $headers['Package-Version'] !== $trusted['version'] || $headers['Package-Source-Tag'] !== $trusted['source_tag'] || $headers['Package-Source-Commit'] !== $trusted['source_commit'] ) {
			return array( 'state' => 'invalid', 'reason' => 'package_source_mismatch' );
		}
		return array( 'state' => 'verified', 'version' => $headers['Package-Version'], 'source_tag' => $headers['Package-Source-Tag'], 'source_commit' => $headers['Package-Source-Commit'], 'package_digest' => $headers['Package-Digest'] );
	}

	/** @return array<string,mixed> */
	private static function trustedProvenanceIdentity( array $provenance ): array {
		return self::isTrustedProvenance($provenance) ? array( 'state' => 'configured', 'version' => $provenance['version'], 'source_tag' => $provenance['source_tag'], 'source_commit' => $provenance['source_commit'], 'package_digest' => $provenance['package_digest'] ) : array( 'state' => 'unavailable' );
	}

	private static function isTrustedProvenance( array $provenance ): bool {
		return self::isVersion($provenance['version'] ?? '') && is_string($provenance['source_tag'] ?? null) && '' !== trim($provenance['source_tag']) && 1 === preg_match('/^[0-9a-f]{40}$/', (string) ($provenance['source_commit'] ?? '')) && 1 === preg_match('/^[0-9a-f]{64}$/', (string) ($provenance['package_digest'] ?? ''));
	}

	/** @return array<string,string>|\WP_Error */
	public static function injectPackageProvenance( string $package_path, string $version, string $source_tag, string $source_commit ): array|\WP_Error {
		$file = rtrim($package_path, '/') . '/data-machine-code.php';
		if ( ! is_dir($package_path) || ! is_readable($file) || ! self::isVersion($version) || '' === trim($source_tag) || 1 !== preg_match('/^[0-9a-f]{40}$/', $source_commit) ) {
			return new \WP_Error('invalid_package_provenance_input', 'A readable package directory plus version, source tag, and 40-character source commit are required.');
		}
		$body = (string) file_get_contents($file);
		foreach ( array( 'Package-Version' => $version, 'Package-Source-Tag' => $source_tag, 'Package-Source-Commit' => $source_commit, 'Package-Digest' => '' ) as $name => $value ) {
			$line = ' * ' . $name . ': ' . $value;
			if ( preg_match('/^\s*\*\s*' . preg_quote($name, '/') . ':.*$/mi', $body) ) { $body = preg_replace('/^\s*\*\s*' . preg_quote($name, '/') . ':.*$/mi', $line, $body) ?? $body; }
			else { $body = preg_replace('/\*\//', $line . "\n */", $body, 1) ?? $body; }
		}
		if ( false === file_put_contents($file, $body) ) { return new \WP_Error('package_provenance_write_failed', 'Could not write package provenance headers.'); }
		$digest = self::fingerprint($package_path, true);
		$body = (string) file_get_contents($file);
		$body = preg_replace('/^(\s*\*\s*Package-Digest:).*$/mi', '$1 ' . $digest, $body) ?? $body;
		if ( false === file_put_contents($file, $body) ) { return new \WP_Error('package_provenance_write_failed', 'Could not write package provenance digest.'); }
		return array( 'version' => $version, 'source_tag' => $source_tag, 'source_commit' => $source_commit, 'package_digest' => $digest );
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

	private static function isVersion( mixed $version ): bool {
		return is_string($version) && 1 === preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version);
	}

	private static function fingerprint( string $path, bool $package_digest = false ): string {
		try {
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		} catch ( \UnexpectedValueException ) {
			return '';
		}
		$paths = array();
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) ) {
				continue;
			}
			$paths[] = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($path)), '/\\'));
		}
		sort($paths, SORT_STRING);
		$hash = hash_init('sha256');
		foreach ( $paths as $relative ) {
			$contents = file_get_contents($path . '/' . $relative);
			if ( false === $contents ) {
				return '';
			}
			if ( $package_digest && 'data-machine-code.php' === $relative ) {
				$contents = preg_replace('/^(\s*\*\s*Package-Digest:).*$/mi', '$1', $contents) ?? $contents;
			}
			hash_update($hash, $relative . "\0" . $contents . "\0");
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
