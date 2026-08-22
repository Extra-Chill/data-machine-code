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
} finally {
	runtime_doctor_remove($root);
}

echo "runtime-source-doctor-fixtures: ok\n";
