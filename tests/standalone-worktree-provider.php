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
$remote  = $root . '/remote.git';
$script  = dirname(__DIR__) . '/bin/dmc-worktree-provider';

mkdir($root, 0777, true);
mkdir($primary);
try {
	standalone_provider_git($primary, array( 'init', '-b', 'main' ));
	standalone_provider_git($primary, array( 'config', 'user.name', 'Fixture' ));
	standalone_provider_git($primary, array( 'config', 'user.email', 'fixture@example.test' ));
	standalone_provider_assert(0 === standalone_provider_run(array( 'git', 'init', '--bare', $remote ))['status'], 'Could not create fixture remote.');
	standalone_provider_git($primary, array( 'remote', 'add', 'origin', $remote ));
	file_put_contents($primary . '/README.md', "fixture\n");
	standalone_provider_git($primary, array( 'add', 'README.md' ));
	standalone_provider_git($primary, array( 'commit', '-m', 'fixture' ));
	standalone_provider_git($primary, array( 'push', '-u', 'origin', 'main' ));
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

	file_put_contents($primary . '/base.txt', "base\n");
	standalone_provider_git($primary, array( 'add', 'base.txt' ));
	standalone_provider_git($primary, array( 'commit', '-m', 'base' ));
	standalone_provider_git($primary, array( 'push' ));
	$base = trim(standalone_provider_run(array( 'git', '-C', $primary, 'rev-parse', 'HEAD' ))['stdout']);
	$converged = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $base ));
	$converged_payload = json_decode($converged['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert(0 === $converged['status'], 'Convergence failed: ' . $converged['stderr']);
	standalone_provider_assert('converged' === $converged_payload['status'], 'Behind worktree did not converge.');
	standalone_provider_assert($identity_payload['token'] === $converged_payload['identity_token'], 'Convergence changed the identity token.');
	standalone_provider_assert($base === $converged_payload['after_head'], 'Convergence did not reach the requested base.');
	standalone_provider_assert(true === $converged_payload['changed'], 'Convergence did not record its HEAD change.');
	$retry = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $base ));
	$retry_payload = json_decode($retry['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('converged' === $retry_payload['status'] && false === $retry_payload['changed'], 'Idempotent convergence retry was not a no-op.');

	file_put_contents($path . '/dirty.txt', "dirty\n");
	$dirty = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $base ));
	$dirty_payload = json_decode($dirty['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('dirty_worktree' === $dirty_payload['code'], 'Dirty worktree was not refused.');
	unlink($path . '/dirty.txt');
	$invalid = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], 'short' ));
	$invalid_payload = json_decode($invalid['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('invalid_base_sha' === $invalid_payload['code'], 'Short base SHA was not refused.');
	$missing_base = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], str_repeat('a', 40) ));
	$missing_base_payload = json_decode($missing_base['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('base_not_found' === $missing_base_payload['code'], 'Absent base SHA was not refused.');

	standalone_provider_git($path, array( 'push', '-u', 'origin', 'fix/example' ));
	file_put_contents($path . '/unpushed.txt', "unpushed\n");
	standalone_provider_git($path, array( 'add', 'unpushed.txt' ));
	standalone_provider_git($path, array( 'commit', '-m', 'unpushed' ));
	$unpushed = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $base ));
	$unpushed_payload = json_decode($unpushed['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('unpushed_commits' === $unpushed_payload['code'], 'Unpushed commit was not refused.');
	standalone_provider_git($path, array( 'push', '-u', 'origin', 'fix/example' ));
	$ahead = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $base ));
	$ahead_payload = json_decode($ahead['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('destination_ahead' === $ahead_payload['code'], 'Destination ahead of base was not refused.');

	file_put_contents($primary . '/next-base.txt', "next\n");
	standalone_provider_git($primary, array( 'add', 'next-base.txt' ));
	standalone_provider_git($primary, array( 'commit', '-m', 'next base' ));
	standalone_provider_git($primary, array( 'push' ));
	$diverged_base = trim(standalone_provider_run(array( 'git', '-C', $primary, 'rev-parse', 'HEAD' ))['stdout']);
	$diverged = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $diverged_base ));
	$diverged_payload = json_decode($diverged['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('destination_diverged' === $diverged_payload['code'], 'Diverged destination was not refused.');
	$primary_identity = standalone_provider_run(array( PHP_BINARY, $script, 'identity', $root, 'fixture' ));
	$primary_token = json_decode($primary_identity['stdout'], true, 512, JSON_THROW_ON_ERROR)['token'];
	$primary_refusal = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $primary_token, $diverged_base ));
	standalone_provider_assert('primary_worktree' === json_decode($primary_refusal['stdout'], true, 512, JSON_THROW_ON_ERROR)['code'], 'Primary worktree was not refused.');

	standalone_provider_git($path, array( 'branch', '-m', 'fix/renamed' ));
	$stale = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $identity_payload['token'], $diverged_base ));
	$stale_payload = json_decode($stale['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('identity_drift' === $stale_payload['code'], 'Branch drift did not invalidate the identity token.');
	$invalid_token = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, 'invalid', $diverged_base ));
	standalone_provider_assert('invalid_identity_token' === json_decode($invalid_token['stdout'], true, 512, JSON_THROW_ON_ERROR)['code'], 'Invalid token was not refused.');

	$race_handle = 'fixture@race';
	$race_path   = $root . '/' . $race_handle;
	standalone_provider_git($primary, array( 'worktree', 'add', '-b', 'fix/race', $race_path ));
	file_put_contents($primary . '/race-base.txt', "race\n");
	standalone_provider_git($primary, array( 'add', 'race-base.txt' ));
	standalone_provider_git($primary, array( 'commit', '-m', 'race base' ));
	standalone_provider_git($primary, array( 'push' ));
	$race_base = trim(standalone_provider_run(array( 'git', '-C', $primary, 'rev-parse', 'HEAD' ))['stdout']);
	$race_identity = standalone_provider_run(array( PHP_BINARY, $script, 'identity', $root, $race_handle ));
	$race_token = json_decode($race_identity['stdout'], true, 512, JSON_THROW_ON_ERROR)['token'];
	$race_hook = $root . '/race-hook.php';
	file_put_contents($race_hook, "#!/usr/bin/env php\n<?php file_put_contents(\$argv[1] . '/race.txt', 'race');\n");
	chmod($race_hook, 0700);
	putenv('DMC_WORKTREE_PROVIDER_TEST_CONVERGE_HOOK=' . $race_hook);
	$race = standalone_provider_run(array( PHP_BINARY, $script, 'converge', $root, $race_token, $race_base ));
	putenv('DMC_WORKTREE_PROVIDER_TEST_CONVERGE_HOOK');
	$race_payload = json_decode($race['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('dirty_worktree' === $race_payload['code'], 'State changed before merge was not refused.');
	standalone_provider_assert($race_payload['before_head'] === $race_payload['after_head'], 'Race refusal mutated HEAD.');

	$outside = standalone_provider_run(array( PHP_BINARY, $script, 'identity', dirname($root), $handle ));
	$outside_payload = json_decode($outside['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_assert('not_owned' === $outside_payload['status'], 'Resolver escaped its explicit workspace root.');
} finally {
	standalone_provider_remove($root);
}

echo "standalone-worktree-provider: ok\n";
