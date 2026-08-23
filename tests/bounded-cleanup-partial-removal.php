<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(
			private string $code,
			private string $message = '',
			private mixed $data = null
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceCoreUtilities;
use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function bounded_cleanup_partial_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

final class BoundedCleanupPartialRemovalHarness {
	use WorkspaceCoreUtilities;
	use WorkspaceWorktreeCleanupEngine;

	protected const CLEANUP_GIT_PROBE_TIMEOUT  = 5;
	protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;

	public bool $delete_path = true;

	public function __construct(
		private string $workspace_path,
		private string $primary_path
	) {}

	public function remove( string $path, bool $broken_orphan_only = false ): array|WP_Error {
		$method = new ReflectionMethod($this, 'remove_worktree_by_path');
		return $method->invoke($this, 'repo', 'fix/cleanup', $path, false, 60, $broken_orphan_only);
	}

	public function classify_broken_orphan( string $path ): ?array {
		$method = new ReflectionMethod($this, 'classify_broken_orphan_worktree_marker');
		return $method->invoke($this, $path);
	}

	public function reclaimed_bytes( int $known_bytes, int $unknown_paths ): array {
		$method = new ReflectionMethod($this, 'build_reclaimed_bytes_summary');
		return $method->invoke($this, $known_bytes, $unknown_paths);
	}

	private function sanitize_name( string $name ): string {
		return trim($name);
	}

	private function get_primary_path( string $repo ): string {
		return $this->primary_path;
	}

	private function run_git( string $path, string $command, int $timeout = 0 ): array|WP_Error {
		if ( $this->delete_path && str_starts_with($command, 'worktree remove ') ) {
			$worktree_path = $this->workspace_path . '/repo@fix-cleanup';
			unlink($worktree_path . '/.git');
			rmdir($worktree_path);
		}

		return new WP_Error('git_failed', 'fatal: unable to create metadata lock: No space left on device');
	}

	private function worktree_inventory(): object {
		return new class {
			public function delete( string $handle ): bool {
				return true;
			}
		};
	}
}

$root       = sys_get_temp_dir() . '/dmc-bounded-cleanup-partial-' . getmypid();
$primary    = $root . '/repo';
$worktree   = $root . '/repo@fix-cleanup';
$git_target = $primary . '/.git/worktrees/fix-cleanup';
mkdir($git_target, 0777, true);
mkdir($worktree, 0777, true);
file_put_contents($worktree . '/.git', 'gitdir: ' . $git_target);

$harness = new BoundedCleanupPartialRemovalHarness($root, $primary);
$partial = $harness->remove($worktree);

bounded_cleanup_partial_assert_same(true, is_array($partial), 'filesystem removal after a Git error must be represented as success');
bounded_cleanup_partial_assert_same('partial', $partial['removal_status'] ?? null, 'filesystem-only success must be typed as partial');
bounded_cleanup_partial_assert_same('filesystem_removed_git_metadata_failed', $partial['reason_code'] ?? null, 'partial removal must preserve its failure classification');
bounded_cleanup_partial_assert_same('git_failed', $partial['removal_error']['code'] ?? null, 'partial removal must preserve the Git error code');
bounded_cleanup_partial_assert_same(false, is_dir($worktree), 'fixture must prove the filesystem path was removed');

$unknown_bytes = $harness->reclaimed_bytes(4096, 1);
bounded_cleanup_partial_assert_same(null, $unknown_bytes['bytes_reclaimed'], 'unknown measurement must not be reported as a known byte count');
bounded_cleanup_partial_assert_same(4096, $unknown_bytes['bytes_reclaimed_minimum'], 'known reclaimed bytes must remain available as a lower bound');
bounded_cleanup_partial_assert_same(1, $unknown_bytes['bytes_reclaimed_unknown'], 'summary must count unmeasured removed paths');

mkdir($worktree, 0777, true);
file_put_contents($worktree . '/.git', 'gitdir: ' . $git_target);
$harness->delete_path = false;
$failure              = $harness->remove($worktree);
bounded_cleanup_partial_assert_same(true, is_wp_error($failure), 'Git failure before filesystem removal must remain a full failure');
bounded_cleanup_partial_assert_same(true, is_dir($worktree), 'full failure must leave the worktree path in place');

unlink($worktree . '/.git');
rmdir($worktree);
rmdir($git_target);

$orphan_path   = $root . '/repo@broken-orphan';
$orphan_target = $primary . '/.git/worktrees/broken-orphan';
mkdir($orphan_path, 0777, true);
file_put_contents($orphan_path . '/.git', 'gitdir: ' . $orphan_target);
file_put_contents($orphan_path . '/artifact.txt', str_repeat('x', 1024));
$detected = $harness->classify_broken_orphan($orphan_path);
bounded_cleanup_partial_assert_same($orphan_target, $detected['gitdir'] ?? null, 'dry-run detection must report the missing Git metadata target');

$ambiguous_path = $root . '/repo@ambiguous';
mkdir($ambiguous_path, 0777, true);
file_put_contents($ambiguous_path . '/.git', 'gitdir: ' . $root . '/unrelated/.git/worktrees/ambiguous');
bounded_cleanup_partial_assert_same(null, $harness->classify_broken_orphan($ambiguous_path), 'a pointer outside the owning primary metadata must remain ambiguous');

$removed_orphan = $harness->remove($orphan_path, true);
bounded_cleanup_partial_assert_same('broken_orphan', $removed_orphan['reason_code'] ?? null, 'guarded orphan removal must retain its stable classification');
bounded_cleanup_partial_assert_same($orphan_target, $removed_orphan['broken_target_path'] ?? null, 'guarded orphan removal must retain target-path evidence');
bounded_cleanup_partial_assert_same(false, is_dir($orphan_path), 'guarded orphan removal must remove the contained directory');

$race_path   = $root . '/repo@race-orphan';
$race_target = $primary . '/.git/worktrees/race-orphan';
mkdir($race_path, 0777, true);
file_put_contents($race_path . '/.git', 'gitdir: ' . $race_target);
bounded_cleanup_partial_assert_same($race_target, $harness->classify_broken_orphan($race_path)['gitdir'] ?? null, 'race fixture must initially classify as broken');
mkdir($race_target, 0777, true);
$race_refusal = $harness->remove($race_path, true);
bounded_cleanup_partial_assert_same('broken_orphan_revalidation_failed', $race_refusal->get_error_code(), 'metadata restored before deletion must fail fresh orphan revalidation');
bounded_cleanup_partial_assert_same(true, is_dir($race_path), 'fresh revalidation refusal must preserve the directory');

unlink($ambiguous_path . '/.git');
rmdir($ambiguous_path);
unlink($race_path . '/.git');
rmdir($race_path);
rmdir($race_target);
rmdir(dirname($git_target));
rmdir($primary . '/.git');
rmdir($primary);
rmdir($root);

echo "bounded-cleanup-partial-removal: ok\n";
