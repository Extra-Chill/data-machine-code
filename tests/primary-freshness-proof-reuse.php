<?php

declare(strict_types=1);

const ABSPATH = __DIR__ . '/fixtures/';

final class WP_Error {
	public function __construct() {}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

$options = array();
function get_option( string $name, mixed $default = false ): mixed { global $options; return $options[ $name ] ?? $default; }
function update_option( string $name, mixed $value ): bool { global $options; $options[ $name ] = $value; return true; }

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';

use DataMachineCode\Workspace\WorkspaceCoreUtilities;

final class Primary_Freshness_Proof_Harness {
	use WorkspaceCoreUtilities;
	protected string $workspace_path = '/workspace';
	private string $now = '2026-08-29T12:00:00+00:00';
	public const CLEANUP_GIT_PROBE_TIMEOUT = 5;
	public function remember( string $path, string $handle ): void { $this->remember_primary_freshness_evidence($path, $handle); }
	public function proof( string $path, string $handle, string $ref ): ?array { return $this->primary_freshness_proof_for_ref($path, $handle, $ref); }
	public function clock( string $now ): void { $this->now = $now; }
	protected function primary_freshness_now(): string { return $this->now; }
	protected function run_git( string $path, string $args, int $timeout = 30 ): array|WP_Error {
		$lines = array();
		exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1', $lines, $status);
		return 0 === $status ? array( 'output' => implode("\n", $lines) ) : new WP_Error();
	}
}

function proof_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException($message); } }

$root = sys_get_temp_dir() . '/dmc-proof-' . bin2hex(random_bytes(6));
$remote = $root . '/remote.git';
$repo = $root . '/repo';
mkdir($root, 0700, true);
try {
	exec('git init --bare ' . escapeshellarg($remote) . ' >/dev/null 2>&1');
	exec('git init -b main ' . escapeshellarg($repo) . ' >/dev/null 2>&1');
	exec('git -C ' . escapeshellarg($repo) . ' config user.email test@example.test');
	exec('git -C ' . escapeshellarg($repo) . ' config user.name test');
	file_put_contents($repo . '/README.md', "fixture\n");
	exec('git -C ' . escapeshellarg($repo) . ' add README.md && git -C ' . escapeshellarg($repo) . ' commit -m initial >/dev/null 2>&1');
	exec('git -C ' . escapeshellarg($repo) . ' remote add origin ' . escapeshellarg($remote));
	exec('git -C ' . escapeshellarg($repo) . ' push -u origin main >/dev/null 2>&1');

	$harness = new Primary_Freshness_Proof_Harness();
	$harness->remember($repo, 'repo');
	$proof = $harness->proof($repo, 'repo', 'origin/main');
	proof_assert(is_array($proof) && 'origin/main' === ($proof['target_ref'] ?? null) && '' !== ($proof['target_head'] ?? ''), 'Immediate refresh proof did not authorize its exact ref and SHA.');
	proof_assert(null === $harness->proof($repo, 'repo', 'origin/other'), 'A refresh proof authorized a different ref.');
	$harness->clock('2026-08-29T12:05:01+00:00');
	proof_assert(null === $harness->proof($repo, 'repo', 'origin/main'), 'An expired refresh proof remained reusable.');
	fwrite(STDOUT, "primary-freshness-proof-reuse: ok\n");
} finally {
	exec('rm -rf ' . escapeshellarg($root));
}
