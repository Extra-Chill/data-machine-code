<?php

declare(strict_types=1);

final class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}

define('ABSPATH', __DIR__ . '/fixtures/');
require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Runtime\RuntimeSourceDoctor;

function runtime_doctor_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function runtime_doctor_tree( string $path, string $version, string $payload ): void {
	mkdir($path, 0777, true);
	file_put_contents($path . '/data-machine-code.php', "<?php\n/*\n * Version: {$version}\n */\n");
	mkdir($path . '/inc', 0777, true);
	file_put_contents($path . '/inc/payload.php', $payload);
}

function runtime_doctor_remove( string $path ): void {
	if ( is_link($path) || is_file($path) ) { unlink($path); return; }
	if ( ! is_dir($path) ) { return; }
	foreach ( scandir($path) ?: array() as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) { runtime_doctor_remove($path . '/' . $entry); }
	}
	rmdir($path);
}

function runtime_doctor_git( string $path, string $args ): string {
	$output = array();
	$status = 0;
	exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1', $output, $status);
	if ( 0 !== $status ) { throw new RuntimeException(implode("\n", $output)); }
	return trim(implode("\n", $output));
}

function runtime_doctor_package_digest( string $path ): string {
	$paths = array();
	foreach ( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file ) {
		if ( $file->isFile() ) { $paths[] = substr($file->getPathname(), strlen($path) + 1); }
	}
	sort($paths, SORT_STRING);
	$hash = hash_init('sha256');
	foreach ( $paths as $relative ) {
		$contents = file_get_contents($path . '/' . $relative);
		if ( 'data-machine-code.php' === $relative ) { $contents = preg_replace('/^(\s*\*\s*Package-Digest:).*$/mi', '$1', $contents); }
		hash_update($hash, $relative . "\0" . $contents . "\0");
	}
	return hash_final($hash);
}

$root = sys_get_temp_dir() . '/dmc-runtime-doctor-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
try {
	$source = $root . '/source';
	$copy = $root . '/copy';
	$other = $root . '/other';
	runtime_doctor_tree($source, '1.2.0', 'new');
	runtime_doctor_tree($copy, '1.2.0', 'new');
	runtime_doctor_tree($other, '1.1.0', 'old');

	// A copied deployment fingerprint is independent of creation order and detects content drift.
	$copy_report = RuntimeSourceDoctor::inspect($copy . '/data-machine-code.php', '1.2.0', array( 'source_path' => $source ));
	runtime_doctor_assert('copied_deploy' === $copy_report['runtime']['deployment'], 'Copy deployment mode was not detected.');
	runtime_doctor_assert('aligned' === $copy_report['drift']['classification'], 'Identical copied deployment was not aligned.');
	file_put_contents($copy . '/inc/payload.php', 'changed');
	$diverged = RuntimeSourceDoctor::inspect($copy . '/data-machine-code.php', '1.2.0', array( 'source_path' => $source ));
	runtime_doctor_assert('runtime_source_drift' === $diverged['drift']['classification'], 'Copied deployment divergence was not detected.');
	for ( $index = 0; $index < 500; ++$index ) {
		file_put_contents($source . '/inc/unused-' . $index . '.php', 'unused');
	}
	$started = microtime(true);
	$predates = RuntimeSourceDoctor::inspect($copy . '/data-machine-code.php', '1.1.0', array( 'source_path' => $source ));
	runtime_doctor_assert('runtime_predates_source' === $predates['drift']['classification'], 'Older runtime version was not classified without an unbounded fingerprint walk.');
	runtime_doctor_assert(microtime(true) - $started < 1.0, 'Older runtime diagnostic scanned the authoritative source tree.');

	// Git mode only requires a checkout marker for deployment detection; no repository mutation occurs.
	mkdir($other . '/.git');
	$git = RuntimeSourceDoctor::inspect($other . '/data-machine-code.php', '1.1.0', array( 'source_path' => $source ));
	runtime_doctor_assert('git_checkout' === $git['runtime']['deployment'], 'Git checkout mode was not detected.');

	$runtime = $root . '/runtime';
	symlink($other, $runtime);
	$before = array( 'target' => readlink($runtime), 'payload' => file_get_contents($runtime . '/inc/payload.php') );
	$symlink = RuntimeSourceDoctor::inspect($runtime . '/data-machine-code.php', '1.1.0', array( 'source_path' => $source ));
	runtime_doctor_assert('symlink' === $symlink['runtime']['deployment'], 'Symlink deployment mode was not detected.');
	runtime_doctor_assert($before === array( 'target' => readlink($runtime), 'payload' => file_get_contents($runtime . '/inc/payload.php') ), 'Inspect mutated runtime state.');

	// Open readers retain the old target while the directory entry changes atomically.
	$reader = fopen($runtime . '/inc/payload.php', 'r');
	$result = RuntimeSourceDoctor::apply($runtime . '/data-machine-code.php', array( 'source_path' => $source ));
	runtime_doctor_assert(is_array($result) && ! empty($result['changed']), 'Atomic symlink replacement did not succeed.');
	runtime_doctor_assert('old' === stream_get_contents($reader), 'Concurrent reader lost its original target.');
	fclose($reader);
	runtime_doctor_assert('new' === file_get_contents($runtime . '/inc/payload.php'), 'New readers did not resolve the replacement target.');

	// A failed rename leaves the original runtime link in place and cleans the sibling.
	symlink($other, $runtime . '-failure');
	$failed = RuntimeSourceDoctor::apply($runtime . '-failure/data-machine-code.php', array( 'source_path' => $source, 'rename' => static fn(): bool => false ));
	runtime_doctor_assert($failed instanceof WP_Error, 'Replacement failure did not return WP_Error.');
	runtime_doctor_assert(readlink($runtime . '-failure') === $other, 'Replacement failure changed the active runtime target.');
	runtime_doctor_assert(0 === count(glob($runtime . '-failure.dmc-reconcile-*') ?: array()), 'Replacement failure left a sibling link behind.');

	$dangling = RuntimeSourceDoctor::apply($runtime . '/data-machine-code.php', array( 'source_path' => $root . '/missing' ));
	runtime_doctor_assert($dangling instanceof WP_Error && 'runtime_source_unavailable' === $dangling->code, 'Dangling source was accepted.');
	$missing_entrypoint = RuntimeSourceDoctor::inspect($runtime . '/data-machine-code.php', '1.2.0', array( 'source_path' => $root ));
	runtime_doctor_assert('source_unavailable' === $missing_entrypoint['drift']['classification'], 'Source without DMC entrypoint was authoritative.');

	file_put_contents($source . '/inc/flag.php', '--allow-primary-refresh');
	$contract = RuntimeSourceDoctor::inspect($runtime . '/data-machine-code.php', '1.2.0', array( 'source_path' => $source, 'command_contract' => array( 'flag' => '--allow-primary-refresh', 'runtime_supports' => false ) ));
	runtime_doctor_assert('command_contract_drift' === $contract['drift']['classification'], 'Command-contract drift was not detected.');

	// Copied/Git runtimes hand off only to an explicitly registered reconciler and
	// cannot claim success until a fresh identity inspection proves convergence.
	$external = $root . '/external';
	runtime_doctor_tree($external, '1.1.0', 'old');
	$absent = RuntimeSourceDoctor::apply($external . '/data-machine-code.php', array( 'source_path' => $source ));
	runtime_doctor_assert(is_array($absent) && 'handoff_required' === $absent['state'] && 'handoff' === $absent['action']['type'], 'Missing external reconciler did not return a typed handoff.');
	$not_called = false;
	$unauthorized = RuntimeSourceDoctor::apply($external . '/data-machine-code.php', array( 'source_path' => $source, 'external_reconciler' => array( 'action' => array( 'type' => 'command', 'command' => 'test deploy', 'authorize_callback' => false ), 'reconcile' => static function () use ( &$not_called ): array { $not_called = true; return array( 'success' => true ); } ) ));
	runtime_doctor_assert(is_array($unauthorized) && 'handoff_required' === $unauthorized['state'] && ! $not_called, 'Unauthorized external reconciliation mutated through a handoff.');
	$failed_external = RuntimeSourceDoctor::apply($external . '/data-machine-code.php', array( 'source_path' => $source, 'external_reconciler' => array( 'owner' => 'test deployer', 'action' => array( 'type' => 'command', 'command' => 'test deploy', 'authorize_callback' => true ), 'reconcile' => static fn(): array => array( 'success' => false, 'message' => 'deployer rejected request' ) ) ));
	runtime_doctor_assert(is_array($failed_external) && 'failed' === $failed_external['state'], 'Failed external reconciler was not reported.');
	$mismatch = RuntimeSourceDoctor::apply($external . '/data-machine-code.php', array( 'source_path' => $source, 'external_reconciler' => array( 'action' => array( 'type' => 'command', 'command' => 'test deploy', 'authorize_callback' => static fn(): bool => true ), 'reconcile' => static fn(): array => array( 'success' => true ) ) ));
	runtime_doctor_assert(is_array($mismatch) && 'verification_failed' === $mismatch['state'], 'External reconciler success was accepted without runtime verification.');
	$successful = RuntimeSourceDoctor::apply($external . '/data-machine-code.php', array( 'source_path' => $source, 'external_reconciler' => array( 'owner' => 'test deployer', 'action' => array( 'type' => 'command', 'command' => 'test deploy', 'authorize_callback' => true ), 'reconcile' => static function () use ( $external, $source ): array { foreach ( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file ) { if ( ! $file->isFile() ) { continue; } $destination = $external . '/' . substr($file->getPathname(), strlen($source) + 1); if ( ! is_dir(dirname($destination)) ) { mkdir(dirname($destination), 0777, true); } copy($file->getPathname(), $destination); } return array( 'success' => true, 'changed' => true ); } ) ));
	runtime_doctor_assert(is_array($successful) && true === $successful['success'] && 'converged' === $successful['state'], 'Successful external reconciler was not post-apply verified.');

	// Release provenance compares an immutable package identity to a remote-tracking
	// release ref, rather than incorrectly comparing the package and source trees.
	$repository = $root . '/repository';
	runtime_doctor_tree($repository, '2.0.0', 'source-only');
	runtime_doctor_git($repository, 'init -q');
	runtime_doctor_git($repository, 'config user.email test@example.test');
	runtime_doctor_git($repository, 'config user.name Test');
	runtime_doctor_git($repository, 'add .');
	runtime_doctor_git($repository, 'commit -qm initial');
	runtime_doctor_git($repository, 'tag v2.0.0');
	$release_head = runtime_doctor_git($repository, 'rev-parse v2.0.0');
	runtime_doctor_git($repository, 'branch release-latest');
	runtime_doctor_git($repository, 'update-ref refs/remotes/origin/release-latest ' . escapeshellarg($release_head));
	$package = $root . '/package';
	runtime_doctor_tree($package, '2.0.0', 'packaged-only');
	$missing_provenance = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository ));
	runtime_doctor_assert('not_present' === $missing_provenance['runtime']['package_provenance']['state'], 'Package without provenance headers was accepted.');
	$injected = RuntimeSourceDoctor::injectPackageProvenance($package, '2.0.0', 'v2.0.0', $release_head);
	runtime_doctor_assert(is_array($injected) && $injected['package_digest'] === runtime_doctor_package_digest($package), 'Production package provenance injection did not write a normalized digest.');
	$cli_package = $root . '/cli-package';
	runtime_doctor_tree($cli_package, '2.0.0', 'cli-packaged-only');
	$cli_output = array(); $cli_status = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/dmc-package-provenance') . ' --package-dir=' . escapeshellarg($cli_package) . ' --version=2.0.0 --source-tag=v2.0.0 --source-commit=' . escapeshellarg($release_head), $cli_output, $cli_status);
	$cli_provenance = json_decode(implode("\n", $cli_output), true);
	runtime_doctor_assert(0 === $cli_status && is_array($cli_provenance) && ($cli_provenance['package_digest'] ?? '') === runtime_doctor_package_digest($cli_package), 'Production package provenance CLI did not inject verifiable headers.');
	$untrusted = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository ));
	runtime_doctor_assert('untrusted' === $untrusted['runtime']['package_provenance']['state'], 'Embedded package provenance was trusted without an independent release record.');
	$trusted = $injected;
	$invalid_trusted = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository, 'trusted_release_provenance' => array( 'version' => '2.0.0', 'source_tag' => 'v2.0.0', 'source_commit' => $release_head, 'package_digest' => 'invalid' ) ));
	runtime_doctor_assert('untrusted' === $invalid_trusted['runtime']['package_provenance']['state'], 'Invalid trusted provenance was accepted.');
	$provenance = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository, 'trusted_release_provenance' => $trusted ));
	runtime_doctor_assert('verified' === $provenance['runtime']['package_provenance']['state'] && 'aligned' === $provenance['drift']['classification'], 'Verified package provenance was not aligned independently of source-tree equality.');
	$entry = file_get_contents($package . '/data-machine-code.php'); $payload = file_get_contents($package . '/inc/payload.php');
	file_put_contents($package . '/data-machine-code.php', str_replace('Package-Version: 2.0.0', 'Package-Version: 9.9.9', $entry));
	runtime_doctor_assert('invalid' === RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository, 'trusted_release_provenance' => $trusted ))['runtime']['package_provenance']['state'], 'Mismatched package header provenance was accepted.');
	file_put_contents($package . '/data-machine-code.php', $entry); file_put_contents($package . '/inc/payload.php', 'altered');
	runtime_doctor_assert('package_digest_mismatch' === RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository, 'trusted_release_provenance' => $trusted ))['runtime']['package_provenance']['reason'], 'Altered package content was accepted.');
	file_put_contents($package . '/inc/payload.php', $payload); file_put_contents($package . '/data-machine-code.php', str_replace($trusted['package_digest'], str_repeat('a', 64), $entry));
	runtime_doctor_assert('package_digest_mismatch' === RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository, 'trusted_release_provenance' => $trusted ))['runtime']['package_provenance']['reason'], 'Altered package digest header was accepted.');
	file_put_contents($package . '/data-machine-code.php', $entry);
	runtime_doctor_assert('remote_tracking' === $provenance['release_deploy_source']['kind'] && 'aligned' === $provenance['release_deploy_source']['local_ref_state'], 'Release ref did not resolve the remote-tracking ref.');
	runtime_doctor_git($repository, 'commit --allow-empty -qm local-only');
	runtime_doctor_git($repository, 'update-ref refs/remotes/origin/release-latest HEAD');
	$stale = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository ));
	runtime_doctor_assert('stale' === $stale['release_deploy_source']['local_ref_state'], 'Stale local release ref was not diagnosed without changing the working tree.');
	runtime_doctor_git($repository, 'checkout -q release-latest');
	runtime_doctor_git($repository, 'commit --allow-empty -qm local-divergence');
	$diverged = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository ));
	runtime_doctor_assert('diverged' === $diverged['release_deploy_source']['local_ref_state'], 'Diverged local release ref was not diagnosed without changing the remote-tracking ref.');
	$tag = RuntimeSourceDoctor::inspect($package . '/data-machine-code.php', '2.0.0', array( 'source_path' => $repository, 'release_ref' => 'v2.0.0' ));
	runtime_doctor_assert('immutable_tag' === $tag['release_deploy_source']['kind'], 'Immutable release tag was not resolved read-only.');
} finally {
	runtime_doctor_remove($root);
}

echo "runtime-source-doctor-fixtures: ok\n";
