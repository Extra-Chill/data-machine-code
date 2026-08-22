<?php

declare(strict_types=1);

function standalone_provider_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function standalone_provider_run( array $command ): array {
	$started = microtime(true);
	$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
	standalone_provider_assert(is_resource($process), 'Could not start provider process.');
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	return array( 'status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'elapsed' => microtime(true) - $started );
}

function standalone_provider_git( string $path, array $arguments ): void {
	$command = array_merge(array( 'git', '-C', $path ), $arguments);
	$result  = standalone_provider_run($command);
	standalone_provider_assert(0 === $result['status'], 'Git fixture command failed: ' . $result['stderr']);
}

function standalone_provider_remove( string $path ): void {
	if ( ! is_dir($path) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

$root    = sys_get_temp_dir() . '/dmc-standalone-provider-' . bin2hex(random_bytes(6));
$primary = $root . '/fixture';
$handle  = 'fixture@fix-example';
$path    = $root . '/' . $handle;
$script  = dirname(__DIR__) . '/bin/dmc-worktree-provider';

mkdir($root, 0777, true);
mkdir($primary);
try {
	standalone_provider_git($primary, array( 'init', '-b', 'main' ));
	standalone_provider_git($primary, array( 'config', 'user.name', 'Fixture' ));
	standalone_provider_git($primary, array( 'config', 'user.email', 'fixture@example.test' ));
	file_put_contents($primary . '/README.md', "fixture\n");
	standalone_provider_git($primary, array( 'add', 'README.md' ));
	standalone_provider_git($primary, array( 'commit', '-m', 'fixture' ));
	standalone_provider_git($primary, array( 'worktree', 'add', '-b', 'fix/example', $path ));

	$missing = standalone_provider_run(array( PHP_BINARY, $script, 'identity', $root, 'fixture@missing' ));
	standalone_provider_assert(0 === $missing['status'], 'Missing identity must be a successful typed decline.');
	standalone_provider_assert($missing['elapsed'] < 1.0, 'Missing identity exceeded one second.');
	$missing_payload = json_decode($missing['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('not_owned' === $missing_payload['status'], 'Missing identity did not return not_owned.');

	$identity = standalone_provider_run(array( PHP_BINARY, $script, 'identity', $root, $handle ));
	standalone_provider_assert(0 === $identity['status'], 'Existing identity failed: ' . $identity['stderr']);
	standalone_provider_assert($identity['elapsed'] < 1.0, 'Existing identity exceeded one second.');
	$identity_payload = json_decode($identity['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('datamachine-code/worktree-identity/v1' === $identity_payload['schema'], 'Identity schema mismatch.');
	standalone_provider_assert($handle === $identity_payload['handle'], 'Identity handle mismatch.');
	standalone_provider_assert(realpath($path) === $identity_payload['path'], 'Identity path is not canonical.');
	standalone_provider_assert('fix/example' === $identity_payload['branch'], 'Identity branch mismatch.');
	standalone_provider_assert(false === $identity_payload['primary'], 'Linked worktree was classified as primary.');

	$safety = standalone_provider_run(array( PHP_BINARY, $script, 'safety', $root, $identity_payload['token'] ));
	standalone_provider_assert(0 === $safety['status'], 'Clean safety attestation failed: ' . $safety['stderr']);
	standalone_provider_assert($safety['elapsed'] < 1.0, 'Clean safety attestation exceeded one second.');
	$safety_payload = json_decode($safety['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert(true === $safety_payload['fresh'], 'Clean identity was not fresh.');
	standalone_provider_assert(false === $safety_payload['dirty'], 'Clean worktree was reported dirty.');
	standalone_provider_assert(false === $safety_payload['unpushed'], 'Clean worktree was reported unpushed.');

	file_put_contents($path . '/dirty.txt', "dirty\n");
	$dirty = standalone_provider_run(array( PHP_BINARY, $script, 'safety', $root, $identity_payload['token'] ));
	$dirty_payload = json_decode($dirty['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert(true === $dirty_payload['dirty'], 'Untracked file was not reported dirty.');
	unlink($path . '/dirty.txt');

	standalone_provider_git($path, array( 'branch', '-m', 'fix/renamed' ));
	$stale = standalone_provider_run(array( PHP_BINARY, $script, 'safety', $root, $identity_payload['token'] ));
	$stale_payload = json_decode($stale['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert(false === $stale_payload['fresh'], 'Branch drift did not invalidate the identity token.');

	$outside = standalone_provider_run(array( PHP_BINARY, $script, 'identity', dirname($root), $handle ));
	$outside_payload = json_decode($outside['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('not_owned' === $outside_payload['status'], 'Resolver escaped its explicit workspace root.');
} finally {
	standalone_provider_remove($root);
}

echo "standalone-worktree-provider: ok\n";
