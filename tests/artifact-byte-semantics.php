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
	public function capacity_evidence( array $before, array $after, int $predicted, int $durable ): array {
		$method = new ReflectionMethod($this, 'artifact_capacity_evidence');
		return $method->invoke($this, $before, $after, $predicted, $durable);
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
	artifact_byte_semantics_assert('durable_reclaimed_bytes is scoped durable cleanup recovery; filesystem_free_bytes_delta is signed host telemetry that may include concurrent activity; observed_reclaimed_bytes is deprecated compatibility telemetry' === ($summary['reclamation_telemetry_semantics'] ?? null), 'Cleanup JSON summary must distinguish durable recovery from host telemetry and deprecate the compatibility field.');
	artifact_byte_semantics_assert((int) ($artifact['allocated_bytes'] ?? -1) === ($summary['predicted_allocated_reclaim_bytes'] ?? null), 'Cleanup prediction must use allocated rather than apparent bytes.');

	$evidence = $harness->capacity_evidence(array( 'filesystem_free_bytes' => 100 ), array( 'filesystem_free_bytes' => 140 ), 4096, 4096);
	artifact_byte_semantics_assert(4096 === ($evidence['predicted_allocated_reclaim_bytes'] ?? null), 'Capacity evidence must retain predicted allocated bytes separately.');
	artifact_byte_semantics_assert(4096 === ($evidence['durable_reclaimed_bytes'] ?? null), 'Capacity evidence must retain scoped durable recovery separately from host telemetry.');
	artifact_byte_semantics_assert(100 === ($evidence['filesystem_free_bytes_before'] ?? null) && 140 === ($evidence['filesystem_free_bytes_after'] ?? null), 'Capacity evidence must expose raw before and after filesystem values.');
	artifact_byte_semantics_assert(40 === ($evidence['filesystem_free_bytes_delta'] ?? null), 'Capacity evidence must preserve a positive signed host filesystem delta.');
	artifact_byte_semantics_assert(40 === ($evidence['observed_reclaimed_bytes'] ?? null), 'Compatibility observed recovery must retain a positive host delta.');
	artifact_byte_semantics_assert('host_filesystem_noisy_concurrent_telemetry_not_scoped_cleanup_proof' === ($evidence['filesystem_free_bytes_delta_semantics'] ?? null), 'Capacity evidence must label host filesystem deltas as noisy concurrent telemetry rather than cleanup proof.');
	artifact_byte_semantics_assert('scoped_artifact_paths_absent_at_cleanup_completion' === ($evidence['durable_reclaimed_bytes_semantics'] ?? null), 'Capacity evidence must label durable recovery as scoped artifact-path evidence.');
	artifact_byte_semantics_assert(true === ($evidence['observed_reclaimed_bytes_deprecated'] ?? null) && 'deprecated_nonnegative_projection_of_filesystem_free_bytes_delta' === ($evidence['observed_reclaimed_bytes_semantics'] ?? null), 'Capacity evidence must explicitly deprecate the compatibility observed recovery field.');
	artifact_byte_semantics_assert('filesystem_free_bytes_before_after' === ($evidence['observation_basis'] ?? null), 'Observed reclamation must name its capacity evidence basis.');

	$zero_delta = $harness->capacity_evidence(array( 'filesystem_free_bytes' => 100 ), array( 'filesystem_free_bytes' => 100 ), 4096, 4096);
	artifact_byte_semantics_assert(0 === ($zero_delta['filesystem_free_bytes_delta'] ?? null) && 0 === ($zero_delta['observed_reclaimed_bytes'] ?? null), 'Capacity evidence must preserve a zero host filesystem delta.');
	artifact_byte_semantics_assert(4096 === ($zero_delta['durable_reclaimed_bytes'] ?? null), 'Zero host movement must not erase durable scoped recovery.');

	$negative_delta = $harness->capacity_evidence(array( 'filesystem_free_bytes' => 140 ), array( 'filesystem_free_bytes' => 100 ), 4096, 4096);
	artifact_byte_semantics_assert(is_int($negative_delta['filesystem_free_bytes_delta'] ?? null) && -40 === ($negative_delta['filesystem_free_bytes_delta'] ?? null), 'Capacity evidence must preserve a negative signed integer host filesystem delta caused by concurrent pressure.');
	artifact_byte_semantics_assert(0 === ($negative_delta['observed_reclaimed_bytes'] ?? null), 'Compatibility observed recovery must continue to clamp negative host movement.');
	artifact_byte_semantics_assert(4096 === ($negative_delta['durable_reclaimed_bytes'] ?? null), 'Negative host movement must not obscure durable scoped recovery.');

	$missing_probe = $harness->capacity_evidence(array(), array( 'filesystem_free_bytes' => 100 ), 4096, 4096);
	artifact_byte_semantics_assert(array_key_exists('filesystem_free_bytes_before', $missing_probe) && null === $missing_probe['filesystem_free_bytes_before'] && array_key_exists('filesystem_free_bytes_delta', $missing_probe) && null === $missing_probe['filesystem_free_bytes_delta'] && array_key_exists('observed_reclaimed_bytes', $missing_probe) && null === $missing_probe['observed_reclaimed_bytes'], 'Missing filesystem probes must remain explicit null telemetry rather than inferred recovery.');

	$cli_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
	artifact_byte_semantics_assert(false !== strpos((string) $cli_source, 'allocated_artifact_bytes (not guaranteed reclaimable)'), 'Human output must not label allocated artifact bytes as guaranteed reclaimable capacity.');

	echo "artifact-byte-semantics ok\n";
} finally {
	exec(sprintf('rm -rf %s', escapeshellarg($root))); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Removes this test-owned temporary fixture.
}
