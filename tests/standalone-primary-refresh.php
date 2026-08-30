<?php

declare(strict_types=1);

define('DATAMACHINE_CODE_STANDALONE', true);
require_once dirname(__DIR__) . '/vendor/autoload.php';

function standalone_refresh_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

/** @return array{status:int,stdout:string,stderr:string} */
function standalone_refresh_run( array $command ): array {
	$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
	standalone_refresh_assert(is_resource($process), 'Could not start fixture process.');
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return array( 'status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr );
}

function standalone_refresh_git( string $path, array $arguments ): string {
	$result = standalone_refresh_run(array_merge(array( 'git', '-C', $path ), $arguments));
	standalone_refresh_assert(0 === $result['status'], 'Git fixture command failed: ' . implode(' ', $arguments) . "\n" . $result['stderr']);
	return trim($result['stdout']);
}

/** @return array{workspace:string,primary:string,seed:string,remote:string,branch:string} */
function standalone_refresh_fixture( string $case, string $branch = 'main' ): array {
	$workspace = $case . '/workspace';
	$primary   = $workspace . '/fixture';
	$seed      = $case . '/seed';
	$remote    = $case . '/origin.git';
	mkdir($workspace, 0777, true);
	mkdir($seed, 0777, true);
	standalone_refresh_assert(0 === standalone_refresh_run(array( 'git', 'init', '--bare', '-b', $branch, $remote ))['status'], 'Could not initialize fixture remote.');
	standalone_refresh_git($seed, array( 'init', '-b', $branch ));
	standalone_refresh_git($seed, array( 'config', 'user.name', 'Fixture' ));
	standalone_refresh_git($seed, array( 'config', 'user.email', 'fixture@example.test' ));
	file_put_contents($seed . '/base.txt', "base\n");
	standalone_refresh_git($seed, array( 'add', 'base.txt' ));
	standalone_refresh_git($seed, array( 'commit', '-m', 'base' ));
	standalone_refresh_git($seed, array( 'remote', 'add', 'origin', $remote ));
	standalone_refresh_git($seed, array( 'push', '-u', 'origin', $branch ));
	standalone_refresh_assert(0 === standalone_refresh_run(array( 'git', 'clone', $remote, $primary ))['status'], 'Could not clone fixture primary.');
	standalone_refresh_git($primary, array( 'config', 'user.name', 'Fixture' ));
	standalone_refresh_git($primary, array( 'config', 'user.email', 'fixture@example.test' ));
	return compact('workspace', 'primary', 'seed', 'remote', 'branch');
}

function standalone_refresh_advance( array $fixture, string $name ): string {
	file_put_contents($fixture['seed'] . '/' . $name . '.txt', $name . "\n");
	standalone_refresh_git($fixture['seed'], array( 'add', $name . '.txt' ));
	standalone_refresh_git($fixture['seed'], array( 'commit', '-m', $name ));
	standalone_refresh_git($fixture['seed'], array( 'push', 'origin', $fixture['branch'] ));
	return standalone_refresh_git($fixture['seed'], array( 'rev-parse', 'HEAD' ));
}

/** @return array{process:array{status:int,stdout:string,stderr:string},payload:array<string,mixed>} */
function standalone_refresh_invoke( string $script, string $workspace, string $repo = 'fixture' ): array {
	$command = array( PHP_BINARY, $script, 'primary-refresh', $workspace, $repo );
	$process = standalone_refresh_run($command);
	standalone_refresh_assert(json_validate($process['stdout']), 'Refresh emitted invalid JSON: ' . $process['stdout'] . $process['stderr']);
	return array( 'process' => $process, 'payload' => json_decode($process['stdout'], true, 512, JSON_THROW_ON_ERROR) );
}

function standalone_refresh_remove( string $path ): void {
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

$root   = sys_get_temp_dir() . '/dmc-standalone-refresh-' . bin2hex(random_bytes(6));
$script = dirname(__DIR__) . '/bin/dmc-worktree-provider';
mkdir($root, 0777, true);

try {
	$fixture = standalone_refresh_fixture($root . '/refresh-plan');
	$marker  = $root . '/wordpress-bootstrap-marker';
	file_put_contents($fixture['workspace'] . '/wp-load.php', '<?php file_put_contents(' . var_export($marker, true) . ", 'loaded');");
	$intent = array(
		'repo'     => 'fixture',
		'branch'   => 'fix/standalone-plan',
		'from'     => 'origin/main',
		'task_ref' => 'generic-task-1314',
	);
	$missing = standalone_refresh_run(array( PHP_BINARY, $script, 'plan', $fixture['workspace'], json_encode($intent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ));
	$missing_payload = json_decode($missing['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_refresh_assert(1 === $missing['status'] && 'freshness_refresh_required' === ($missing_payload['code'] ?? null), 'Planning without standalone freshness did not fail closed.');
	standalone_refresh_assert(! str_contains((string) ($missing_payload['refresh_command'] ?? ''), 'wp '), 'Standalone remediation still requires WordPress.');
	standalone_refresh_assert(array( 'primary-refresh', realpath($fixture['workspace']), 'fixture' ) === ($missing_payload['refresh_action']['arguments'] ?? null), 'Standalone remediation arguments are not canonical and executable as returned.');
	standalone_refresh_assert($script === ($missing_payload['refresh_action']['executable'] ?? null), 'Standalone remediation selected the wrong installed executable.');

	$old_sha    = standalone_refresh_git($fixture['primary'], array( 'rev-parse', 'HEAD' ));
	$remote_sha = standalone_refresh_advance($fixture, 'remote-behind');
	$refresh    = standalone_refresh_invoke($script, $fixture['workspace']);
	$receipt    = $refresh['payload'];
	standalone_refresh_assert(0 === $refresh['process']['status'] && 'refreshed' === ($receipt['status'] ?? null), 'Behind primary did not refresh: ' . $refresh['process']['stderr'] . var_export($receipt, true));
	standalone_refresh_assert(\DataMachineCode\Workspace\StandalonePrimaryRefresher::SCHEMA === ($receipt['schema'] ?? null), 'Refresh receipt schema mismatch.');
	standalone_refresh_assert($old_sha === ($receipt['old_sha'] ?? null) && $remote_sha === ($receipt['new_sha'] ?? null), 'Refresh receipt omitted old/new commit identity.');
	standalone_refresh_assert('main' === ($receipt['branch'] ?? null) && 'origin/main' === ($receipt['upstream'] ?? null), 'Refresh receipt omitted branch/upstream identity.');
	standalone_refresh_assert($remote_sha === ($receipt['fetched']['default_sha'] ?? null), 'Refresh receipt omitted fetched default-branch evidence.');
	standalone_refresh_assert($remote_sha === ($receipt['freshness_evidence']['ref_heads']['refs/remotes/origin/main'] ?? null), 'Refresh did not persist exact remote-ref freshness evidence.');
	$canonical_refs = standalone_refresh_run(array( 'git', '-C', $fixture['primary'], 'for-each-ref', '--format=%(refname) %(objectname)', 'refs/remotes/origin' ));
	standalone_refresh_assert(hash('sha256', rtrim($canonical_refs['stdout'], "\r\n")) === ($receipt['freshness_evidence']['remote_refs_digest'] ?? null), 'Refresh evidence did not use the canonical remote-ref digest.');
	standalone_refresh_assert(! file_exists($marker), 'Standalone refresh loaded the available WordPress bootstrap.');

	$plan = standalone_refresh_run(array( PHP_BINARY, $script, 'plan', $fixture['workspace'], json_encode($intent, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ));
	$plan_payload = json_decode($plan['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_refresh_assert(0 === $plan['status'] && 'create' === ($plan_payload['disposition'] ?? null), 'Plan did not accept standalone refresh evidence.');
	standalone_refresh_assert(true === ($plan_payload['freshness']['verified'] ?? null) && $remote_sha === ($plan_payload['freshness']['target_head'] ?? null), 'Plan freshness identity did not bind the standalone-refreshed target.');

	$current = standalone_refresh_invoke($script, $fixture['workspace']);
	standalone_refresh_assert(0 === $current['process']['status'] && 'current' === ($current['payload']['status'] ?? null) && false === ($current['payload']['changed'] ?? null), 'Idempotent refresh was not a typed no-op.');
	file_put_contents($fixture['primary'] . '/dirty.txt', "dirty\n");
	$dirty = standalone_refresh_invoke($script, $fixture['workspace']);
	standalone_refresh_assert(1 === $dirty['process']['status'] && 'dirty_working_tree' === ($dirty['payload']['reason'] ?? null), 'Dirty primary was not refused.');
	unlink($fixture['primary'] . '/dirty.txt');

	file_put_contents($fixture['primary'] . '/local.txt', "local\n");
	standalone_refresh_git($fixture['primary'], array( 'add', 'local.txt' ));
	standalone_refresh_git($fixture['primary'], array( 'commit', '-m', 'local-only' ));
	$local_sha = standalone_refresh_git($fixture['primary'], array( 'rev-parse', 'HEAD' ));
	$ahead     = standalone_refresh_invoke($script, $fixture['workspace']);
	standalone_refresh_assert(1 === $ahead['process']['status'] && 'primary_refresh_ahead' === ($ahead['payload']['reason'] ?? null), 'Ahead-only primary was not refused.');
	standalone_refresh_assert($local_sha === standalone_refresh_git($fixture['primary'], array( 'rev-parse', 'HEAD' )) && null === ($ahead['payload']['recovery'] ?? null), 'Ahead refusal mutated or unnecessarily preserved the primary.');

	$diverged_remote = standalone_refresh_advance($fixture, 'remote-diverged');
	$diverged        = standalone_refresh_invoke($script, $fixture['workspace']);
	$diverged_receipt = $diverged['payload'];
	$recovery_path    = (string) ($diverged_receipt['recovery']['preservation']['path'] ?? '');
	standalone_refresh_assert(0 === $diverged['process']['status'] && 'refreshed' === ($diverged_receipt['status'] ?? null), 'Default-branch divergence was not safely recovered: ' . var_export($diverged_receipt, true));
	standalone_refresh_assert($diverged_remote === standalone_refresh_git($fixture['primary'], array( 'rev-parse', 'HEAD' )), 'Divergence recovery did not refresh the authoritative primary.');
	standalone_refresh_assert(is_dir($recovery_path) && $local_sha === standalone_refresh_git($recovery_path, array( 'rev-parse', 'HEAD' )), 'Divergence recovery did not preserve the local tip in a worktree.');
	standalone_refresh_assert('preserved' === ($diverged_receipt['recovery']['preservation']['status'] ?? null), 'Divergence receipt omitted preservation status.');
	standalone_refresh_assert('datamachine-code/primary-recovery/v1' === ($diverged_receipt['recovery']['preservation']['metadata']['schema'] ?? null), 'Divergence recovery did not persist generic recovery metadata before refreshing the primary.');
	$recovery_git_dir = standalone_refresh_git($recovery_path, array( 'rev-parse', '--absolute-git-dir' ));
	$recovery_metadata = json_decode((string) file_get_contents($recovery_git_dir . '/datamachine-code-primary-recovery.json'), true, 512, JSON_THROW_ON_ERROR);
	standalone_refresh_assert($local_sha === ($recovery_metadata['commit'] ?? null) && 'manual' === ($recovery_metadata['cleanup_policy'] ?? null), 'Durable recovery metadata did not bind the preserved commit and fail-closed cleanup policy.');
	$after_recovery_plan = standalone_refresh_run(array( PHP_BINARY, $script, 'plan', $fixture['workspace'], json_encode(array_merge($intent, array( 'branch' => 'fix/after-recovery' )), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ));
	standalone_refresh_assert(0 === $after_recovery_plan['status'] && 'create' === (json_decode($after_recovery_plan['stdout'], true, 512, JSON_THROW_ON_ERROR)['disposition'] ?? null), 'Planning rejected freshness evidence after divergence recovery.');

	$collision = standalone_refresh_fixture($root . '/recovery-collision');
	file_put_contents($collision['primary'] . '/local.txt', "local\n");
	standalone_refresh_git($collision['primary'], array( 'add', 'local.txt' ));
	standalone_refresh_git($collision['primary'], array( 'commit', '-m', 'local-only' ));
	$collision_local = standalone_refresh_git($collision['primary'], array( 'rev-parse', 'HEAD' ));
	$collision_path  = realpath($collision['workspace']) . '/fixture@primary-recovery-' . substr($collision_local, 0, 12);
	mkdir($collision_path);
	standalone_refresh_advance($collision, 'remote');
	$collision_refusal = standalone_refresh_invoke($script, $collision['workspace']);
	standalone_refresh_assert(1 === $collision_refusal['process']['status'] && 'primary_divergence_recovery_path_conflict' === ($collision_refusal['payload']['reason'] ?? null), 'Occupied deterministic recovery path did not fail closed.');
	standalone_refresh_assert($collision_local === standalone_refresh_git($collision['primary'], array( 'rev-parse', 'HEAD' )), 'Recovery path collision changed the primary.');
	standalone_refresh_assert('' === standalone_refresh_git($collision['primary'], array( 'branch', '--list', 'recovery/primary-recovery-' . substr($collision_local, 0, 12) )), 'Recovery path collision retained a newly-created recovery branch.');

	$missing_upstream = standalone_refresh_fixture($root . '/missing-upstream');
	standalone_refresh_git($missing_upstream['primary'], array( 'branch', '--unset-upstream' ));
	$missing_upstream_sha = standalone_refresh_advance($missing_upstream, 'remote');
	$upstream_refresh     = standalone_refresh_invoke($script, $missing_upstream['workspace']);
	standalone_refresh_assert(0 === $upstream_refresh['process']['status'] && $missing_upstream_sha === ($upstream_refresh['payload']['new_sha'] ?? null), 'Missing-upstream primary did not refresh.');
	standalone_refresh_assert('origin/main' === standalone_refresh_git($missing_upstream['primary'], array( 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}' )), 'Missing upstream was not restored to the same-named remote branch.');

	$detached = standalone_refresh_fixture($root . '/detached-default', 'trunk');
	standalone_refresh_git($detached['primary'], array( 'checkout', '--detach' ));
	$detached_sha = standalone_refresh_advance($detached, 'remote');
	$detached_refresh = standalone_refresh_invoke($script, $detached['workspace']);
	standalone_refresh_assert(0 === $detached_refresh['process']['status'] && 'trunk' === ($detached_refresh['payload']['detached_repair']['branch'] ?? null), 'Detached primary did not resolve the non-main remote default.');
	standalone_refresh_assert('trunk' === standalone_refresh_git($detached['primary'], array( 'branch', '--show-current' )) && $detached_sha === standalone_refresh_git($detached['primary'], array( 'rev-parse', 'HEAD' )), 'Detached primary did not reattach and refresh.');

	$detached_diverged = standalone_refresh_fixture($root . '/detached-diverged');
	standalone_refresh_git($detached_diverged['primary'], array( 'checkout', '--detach' ));
	file_put_contents($detached_diverged['primary'] . '/local.txt', "local\n");
	standalone_refresh_git($detached_diverged['primary'], array( 'add', 'local.txt' ));
	standalone_refresh_git($detached_diverged['primary'], array( 'commit', '-m', 'detached-local' ));
	$detached_local = standalone_refresh_git($detached_diverged['primary'], array( 'rev-parse', 'HEAD' ));
	standalone_refresh_advance($detached_diverged, 'remote');
	$detached_refusal = standalone_refresh_invoke($script, $detached_diverged['workspace']);
	standalone_refresh_assert(1 === $detached_refusal['process']['status'] && 'detached_primary_diverged' === ($detached_refusal['payload']['reason'] ?? null), 'Unpreserved detached history was not refused.');
	standalone_refresh_assert('' === standalone_refresh_git($detached_diverged['primary'], array( 'branch', '--show-current' )) && $detached_local === standalone_refresh_git($detached_diverged['primary'], array( 'rev-parse', 'HEAD' )), 'Detached divergence refusal changed the primary.');

	$non_default = standalone_refresh_fixture($root . '/non-default');
	standalone_refresh_git($non_default['seed'], array( 'checkout', '-b', 'feature' ));
	file_put_contents($non_default['seed'] . '/feature.txt', "feature\n");
	standalone_refresh_git($non_default['seed'], array( 'add', 'feature.txt' ));
	standalone_refresh_git($non_default['seed'], array( 'commit', '-m', 'feature' ));
	standalone_refresh_git($non_default['seed'], array( 'push', '-u', 'origin', 'feature' ));
	standalone_refresh_git($non_default['primary'], array( 'fetch', 'origin' ));
	standalone_refresh_git($non_default['primary'], array( 'checkout', '-b', 'feature', '--track', 'origin/feature' ));
	file_put_contents($non_default['primary'] . '/local.txt', "local\n");
	standalone_refresh_git($non_default['primary'], array( 'add', 'local.txt' ));
	standalone_refresh_git($non_default['primary'], array( 'commit', '-m', 'feature-local' ));
	$feature_local = standalone_refresh_git($non_default['primary'], array( 'rev-parse', 'HEAD' ));
	file_put_contents($non_default['seed'] . '/remote.txt', "remote\n");
	standalone_refresh_git($non_default['seed'], array( 'add', 'remote.txt' ));
	standalone_refresh_git($non_default['seed'], array( 'commit', '-m', 'feature-remote' ));
	standalone_refresh_git($non_default['seed'], array( 'push', 'origin', 'feature' ));
	$feature_refusal = standalone_refresh_invoke($script, $non_default['workspace']);
	standalone_refresh_assert(1 === $feature_refusal['process']['status'] && 'primary_refresh_diverged' === ($feature_refusal['payload']['reason'] ?? null), 'Non-default divergence was incorrectly eligible for primary recovery.');
	standalone_refresh_assert(null === ($feature_refusal['payload']['recovery'] ?? null) && $feature_local === standalone_refresh_git($non_default['primary'], array( 'rev-parse', 'HEAD' )), 'Non-default divergence created recovery state or changed HEAD.');

	$invalid = standalone_refresh_invoke($script, $fixture['workspace'], 'fixture@linked');
	standalone_refresh_assert(1 === $invalid['process']['status'] && 'invalid_primary_handle' === ($invalid['payload']['reason'] ?? null), 'Linked-worktree handle bypassed authoritative-primary validation.');

	fwrite(STDOUT, "standalone-primary-refresh: ok\n");
} finally {
	standalone_refresh_remove($root);
}
