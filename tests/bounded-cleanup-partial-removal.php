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
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function bounded_cleanup_partial_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

final class BoundedCleanupPartialRemovalHarness {
	use WorkspaceWorktreeCleanupEngine;

	protected const CLEANUP_GIT_PROBE_TIMEOUT  = 5;
	protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;

	public bool $delete_path = true;

	public function __construct(
		private string $workspace_path,
		private string $primary_path
	) {}

	public function remove( string $path ): array|WP_Error {
		$method = new ReflectionMethod($this, 'remove_worktree_by_path');
		return $method->invoke($this, 'repo', 'fix/cleanup', $path, false, 60);
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

	private function validate_containment( string $path, string $container ): array {
		$real_path = realpath($path);
		$real_root = realpath($container);
		$valid     = is_string($real_path) && is_string($real_root) && str_starts_with($real_path, rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

		return array(
			'valid'     => $valid,
			'real_path' => $valid ? $real_path : null,
		);
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
rmdir(dirname($git_target));
rmdir($primary . '/.git');
rmdir($primary);
rmdir($root);

echo "bounded-cleanup-partial-removal: ok\n";
