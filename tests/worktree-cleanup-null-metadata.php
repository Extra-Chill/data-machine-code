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
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceCoreUtilities;
use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function worktree_cleanup_null_metadata_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

final class WorktreeCleanupNullMetadataHarness {
	use WorkspaceCoreUtilities;
	use WorkspaceWorktreeCleanupEngine;

	public const METADATA_RECONCILE_DEFAULT_LIMIT  = 25;
	public const METADATA_RECONCILE_DEFAULT_BUDGET = '30s';

	protected const CLEANUP_GITHUB_TIMEOUT     = 5;
	protected const CLEANUP_GIT_PROBE_TIMEOUT  = 5;
	protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
	protected const CLEANUP_GITHUB_MAX_PAGES   = 3;
	protected const CLEANUP_SUMMARY_TOP_LIMIT  = 10;

	public string $workspace_path;
	private string $primary_path;
	private array $row;

	public function __construct( string $workspace_path, string $primary_path, array $row ) {
		$this->workspace_path = $workspace_path;
		$this->primary_path   = $primary_path;
		$this->row            = $row;
	}

	public function review(): array|WP_Error {
		return $this->worktree_cleanup_merged(
			array(
				'dry_run'    => true,
				'skip_github' => true,
			)
		);
	}

	public function apply( array $candidate ): array {
		$method = new ReflectionMethod($this, 'apply_worktree_cleanup_plan_candidates');
		return $method->invoke($this, array( $candidate ), false, microtime(true));
	}

	public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array {
		return array( 'worktrees' => array( $this->row ) );
	}

	private function get_primary_path( string $repo ): string {
		return $this->primary_path;
	}

	private function probe_worktree_dirty_count( string $path, int $timeout_seconds = 0 ): int|WP_Error {
		return 0;
	}

	private function count_unpushed_commits( string $path, int $timeout = 0 ): int|WP_Error {
		return 0;
	}

	private function run_git( string $path, string $command, int $timeout = 0 ): array|WP_Error {
		return array( 'output' => str_starts_with($command, 'for-each-ref ') ? '[gone]' : '' );
	}
}

$root    = sys_get_temp_dir() . '/dmc-cleanup-null-metadata-' . getmypid();
$primary = $root . '/example';
$work    = $root . '/example@fix-null-metadata';
mkdir($primary . '/.git', 0777, true);
mkdir($work, 0777, true);
file_put_contents($work . '/.git', 'gitdir: ' . $primary . '/.git/worktrees/fix-null-metadata');

$row = array(
	'handle'      => 'example@fix-null-metadata',
	'repo'        => 'example',
	'branch'      => 'fix/null-metadata',
	'path'        => $work,
	'is_primary'  => false,
	'liveness'    => 'unknown',
	'metadata'    => null,
);

$harness = new WorktreeCleanupNullMetadataHarness($root, $primary, $row);
$review  = $harness->review();
worktree_cleanup_null_metadata_assert(is_array($review), 'cleanup review should accept null inventory metadata');
worktree_cleanup_null_metadata_assert(1 === count($review['candidates'] ?? array()), 'cleanup review should retain a separately proven upstream-gone candidate');
$candidate = $review['candidates'][0];
worktree_cleanup_null_metadata_assert('upstream-gone' === ( $candidate['signal'] ?? null ), 'cleanup review should preserve the merge-signal discriminator');
worktree_cleanup_null_metadata_assert(array() === ( $candidate['metadata'] ?? null ), 'cleanup review should normalize null metadata to the lifecycle predicate contract');

$candidate['metadata'] = null;
$apply                 = $harness->apply($candidate);
worktree_cleanup_null_metadata_assert(0 === ( $apply['summary']['removed'] ?? null ), 'cleanup apply must not remove a candidate without lifecycle metadata');
worktree_cleanup_null_metadata_assert('active_lifecycle' === ( $apply['skipped'][0]['reason_code'] ?? null ), 'cleanup apply should fail closed when reviewed lifecycle metadata is null');

fwrite(STDOUT, "worktree-cleanup-null-metadata: ok\n");
