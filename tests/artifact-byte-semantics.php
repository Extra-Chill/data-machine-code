<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}
if ( ! function_exists('apply_filters') ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';

use DataMachineCode\Workspace\WorkspaceArtifactCleanup;
use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function artifact_byte_semantics_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

final class ArtifactByteSemanticsHarness {
	use WorkspaceWorktreeCleanupEngine;
	use WorkspaceArtifactCleanup;

	public string $workspace_path;

	public function __construct( string $workspace_path ) {
		$this->workspace_path = $workspace_path;
	}

	/** @return array<int,array<string,mixed>> */
	public function artifacts( string $path ): array {
		return $this->detect_worktree_artifacts('fixture', $path);
	}

	/** @return array<string,mixed> */
	public function capacity_evidence( array $before, array $after, int $predicted ): array {
		$method = new ReflectionMethod($this, 'artifact_capacity_evidence');
		return $method->invoke($this, $before, $after, $predicted);
	}

	/** @return array<string,mixed> */
	public function cleanup_summary( array $candidates ): array {
		$method = new ReflectionMethod($this, 'build_worktree_artifact_cleanup_summary');
		return $method->invoke($this, $candidates, array(), array());
	}
}

$root      = sys_get_temp_dir() . '/dmc-artifact-byte-semantics-' . bin2hex(random_bytes(6));
$worktree  = $root . '/fixture@bytes';
$target    = $worktree . '/target';
mkdir($target, 0777, true);
file_put_contents($worktree . '/Cargo.toml', "[package]\nname = \"fixture\"\nversion = \"0.1.0\"\n");
$sparse = fopen($target . '/sparse.bin', 'wb');
artifact_byte_semantics_assert(false !== $sparse, 'Sparse fixture should be writable.');
ftruncate($sparse, 16 * 1024 * 1024);
fclose($sparse);

try {
	$harness  = new ArtifactByteSemanticsHarness($root);
	$artifact = $harness->artifacts($worktree)[0] ?? array();

	artifact_byte_semantics_assert('target' === ($artifact['path'] ?? null), 'Cargo fixture should expose target as an artifact.');
	artifact_byte_semantics_assert(array_key_exists('apparent_bytes', $artifact) && array_key_exists('allocated_bytes', $artifact), 'JSON artifact fields must name apparent and allocated byte semantics explicitly.');
	artifact_byte_semantics_assert('clone_or_hardlink_sensitive' === ($artifact['allocation_accounting'] ?? null), 'Directory allocation must disclose clone/hardlink-sensitive reclaim accounting.');
	artifact_byte_semantics_assert(array_key_exists('reclaimable_bytes', $artifact) && null === $artifact['reclaimable_bytes'], 'Directory allocation must not claim guaranteed physically reclaimable bytes.');
	artifact_byte_semantics_assert((int) ($artifact['apparent_bytes'] ?? 0) > (int) ($artifact['allocated_bytes'] ?? PHP_INT_MAX), 'Sparse fixture must prove apparent bytes can exceed allocated bytes.');
	$summary = $harness->cleanup_summary(array( array( 'repo' => 'fixture', 'artifacts' => array( $artifact ) ) ));
	artifact_byte_semantics_assert('allocated_bytes; clone_or_hardlink_sensitive estimates are not guaranteed reclaimable capacity' === ($summary['artifact_byte_semantics'] ?? null), 'Cleanup JSON summary must name its byte semantics.');
	artifact_byte_semantics_assert((int) ($artifact['allocated_bytes'] ?? -1) === ($summary['predicted_allocated_reclaim_bytes'] ?? null), 'Cleanup prediction must use allocated rather than apparent bytes.');

	$evidence = $harness->capacity_evidence(array( 'filesystem_free_bytes' => 100 ), array( 'filesystem_free_bytes' => 140 ), 4096);
	artifact_byte_semantics_assert(4096 === ($evidence['predicted_allocated_reclaim_bytes'] ?? null), 'Capacity evidence must retain predicted allocated bytes separately.');
	artifact_byte_semantics_assert(40 === ($evidence['observed_reclaimed_bytes'] ?? null), 'Capacity evidence must record observed reclamation from before/after free capacity.');
	artifact_byte_semantics_assert('filesystem_free_bytes_before_after' === ($evidence['observation_basis'] ?? null), 'Observed reclamation must name its capacity evidence basis.');

	$cli_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
	artifact_byte_semantics_assert(false !== strpos((string) $cli_source, 'allocated_artifact_bytes (not guaranteed reclaimable)'), 'Human output must not label allocated artifact bytes as guaranteed reclaimable capacity.');

	echo "artifact-byte-semantics ok\n";
} finally {
	exec(sprintf('rm -rf %s', escapeshellarg($root))); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Removes this test-owned temporary fixture.
}
