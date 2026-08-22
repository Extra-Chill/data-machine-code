<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CLI child runs without WordPress loaded.
/**
 * Killable child probe used by WorkspaceTargetInspector.
 *
 * @package DataMachineCode\Workspace
 */

if ( 'cli' !== PHP_SAPI || ! isset($argv[1]) ) {
	exit(2);
}

$workspace_path   = (string) $argv[1];
$filesystem_probe = (string) ( $argv[2] ?? '' );
$git_command      = (string) ( $argv[3] ?? 'git' );
$probe_group_pid  = 0;
$probe_process    = null;

// ProcessRunner normally creates this group. Establish it here as well so its
// direct-process fallback can interrupt this worker without orphaning a probe.
if ( function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('posix_getpid') && function_exists('posix_getpgrp') && function_exists('posix_setsid') && function_exists('posix_kill') ) {
	$pid = posix_getpid();
	if ( $pid === posix_getpgrp() || posix_setsid() >= 0 ) {
		$probe_group_pid = $pid;
	}
}
if ( function_exists('pcntl_async_signals') && function_exists('pcntl_signal') ) {
	pcntl_async_signals(true);
	pcntl_signal(
		SIGTERM,
		static function () use ( &$probe_process, $probe_group_pid ): void {
			if ( $probe_group_pid > 0 ) {
				posix_kill(-$probe_group_pid, SIGKILL);
			}
			if ( is_resource($probe_process) ) {
				proc_terminate($probe_process, 9);
			}
			exit(143);
		}
	);
}

/**
 * Run one nested probe while keeping it killable with this worker.
 *
 * @return array{output:list<string>,exit:int}
 */
$run_probe = static function ( string $command, bool $merge_stderr = false ) use ( &$probe_process ): array {
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => $merge_stderr ? array( 'redirect', 1 ) : STDERR,
	);
	$process_command = 'Windows' === PHP_OS_FAMILY ? $command : array( '/bin/sh', '-c', 'exec ' . $command );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Nested probes must remain interruptible by the bounded worker.
	$probe_process   = proc_open($process_command, $descriptors, $pipes);
	if ( ! is_resource($probe_process) ) {
		return array( 'output' => array(), 'exit' => 1 );
	}

	stream_set_blocking($pipes[1], false);
	$raw_output = '';
	$exit       = -1;
	while ( true ) {
		$raw_output .= (string) stream_get_contents($pipes[1]);
		$status      = proc_get_status($probe_process);
		if ( empty($status['running']) ) {
			$exit = (int) $status['exitcode'];
			break;
		}
		usleep(10000);
	}
	$raw_output .= (string) stream_get_contents($pipes[1]);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes are not WordPress filesystem paths.
	fclose($pipes[1]);
	$close_exit    = proc_close($probe_process);
	$probe_process = null;
	if ( -1 === $exit ) {
		$exit = $close_exit;
	}

	$output = '' === $raw_output ? array() : preg_split('/\r?\n/', rtrim($raw_output, "\r\n"));
	return array(
		'output' => is_array($output) ? $output : array(),
		'exit'   => $exit,
	);
};

fwrite(STDERR, "DMC_BOUNDARY:filesystem:is_dir\n");
if ( '' !== $filesystem_probe ) {
	$result = $run_probe($filesystem_probe . ' ' . escapeshellarg($workspace_path));
	$exists = 0 === $result['exit'] && '1' === trim(implode("\n", $result['output']));
} else {
	$exists = is_dir($workspace_path);
}
if ( ! $exists ) {
	fwrite(STDOUT, (string) json_encode(array( 'exists' => false ), JSON_UNESCAPED_SLASHES));
	exit(0);
}

/** @return string|null */
$git_probe = static function ( string $operation, string $args ) use ( $workspace_path, $git_command, $run_probe ): ?string {
	fwrite(STDERR, 'DMC_BOUNDARY:git:' . $operation . "\n");
	$command = $git_command . ' --no-optional-locks -C ' . escapeshellarg($workspace_path) . ' ' . $args;
	$result  = $run_probe($command, true);
	if ( 0 !== $result['exit'] ) {
		return null;
	}

	return trim(implode("\n", $result['output']));
};

$branch        = $git_probe('branch', 'rev-parse --abbrev-ref HEAD');
$remote        = $git_probe('remote', 'config --get ' . escapeshellarg('remote.origin.url'));
$commit        = $git_probe('commit', 'log -1 --format=' . escapeshellarg('%h %s'));
$branch_status = $git_probe('status', 'status --porcelain=v1 --branch');
$status_lines  = null === $branch_status ? array() : array_filter(array_map('trim', explode("\n", $branch_status)));
$dirty         = count(array_filter($status_lines, static fn ( string $line ): bool => ! str_starts_with($line, '## ')));

fwrite(
	STDOUT,
	(string) json_encode(
		array(
			'exists'        => true,
			'branch'        => '' !== (string) $branch ? $branch : null,
			'remote'        => '' !== (string) $remote ? $remote : null,
			'commit'        => '' !== (string) $commit ? $commit : null,
			'dirty'         => $dirty,
			'branch_status' => $branch_status,
		),
		JSON_UNESCAPED_SLASHES
	)
);
