<?php
/**
 * Killable child probe used by WorkspaceTargetInspector.
 *
 * @package DataMachineCode\Workspace
 */

if ( 'cli' !== PHP_SAPI || ! isset($argv[1]) ) {
	exit(2);
}

$path = (string) $argv[1];
$filesystem_probe = (string) ( $argv[2] ?? '' );
$git_command      = (string) ( $argv[3] ?? 'git' );

fwrite(STDERR, "DMC_BOUNDARY:filesystem:is_dir\n");
if ( '' !== $filesystem_probe ) {
	$output = array();
	$exit   = 0;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Injected read-only probe remains outer-deadline-controlled.
	exec($filesystem_probe . ' ' . escapeshellarg($path), $output, $exit);
	$exists = 0 === $exit && '1' === trim(implode("\n", $output));
} else {
	$exists = is_dir($path);
}
if ( ! $exists ) {
	fwrite(STDOUT, json_encode(array( 'exists' => false ), JSON_UNESCAPED_SLASHES));
	exit(0);
}

/** @return string|null */
$git_probe = static function ( string $operation, string $args ) use ( $path, $git_command ): ?string {
	fwrite(STDERR, 'DMC_BOUNDARY:git:' . $operation . "\n");
	$command = $git_command . ' --no-optional-locks -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1';
	$output  = array();
	$exit    = 0;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated, outer-deadline-controlled read-only probe.
	exec($command, $output, $exit);
	if ( 0 !== $exit ) {
		return null;
	}

	return trim(implode("\n", $output));
};

$branch        = $git_probe('branch', 'rev-parse --abbrev-ref HEAD');
$remote        = $git_probe('remote', 'config --get ' . escapeshellarg('remote.origin.url'));
$commit        = $git_probe('commit', 'log -1 --format=' . escapeshellarg('%h %s'));
$branch_status = $git_probe('status', 'status --porcelain=v1 --branch');
$status_lines  = null === $branch_status ? array() : array_filter(array_map('trim', explode("\n", $branch_status)));
$dirty         = count(array_filter($status_lines, static fn ( string $line ): bool => ! str_starts_with($line, '## ')));

fwrite(
	STDOUT,
	json_encode(
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
