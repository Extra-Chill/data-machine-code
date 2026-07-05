<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
require_once dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Support/GitRunner.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHandle.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceActiveNoSignalCleanup.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

use DataMachineCode\Workspace\WorkspaceActiveNoSignalCleanup;
use DataMachineCode\Workspace\WorkspaceCoreUtilities;
use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;

function worktree_cleanup_patch_equivalence_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function worktree_cleanup_patch_equivalence_run( string $cwd, string $command ): void {
	$descriptor_spec = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process         = proc_open($command, $descriptor_spec, $pipes, $cwd);
	if ( ! is_resource($process) ) {
		throw new RuntimeException('Failed to start command: ' . $command);
	}
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ( 0 !== $status ) {
		throw new RuntimeException(sprintf("Command failed (%d): %s\n%s\n%s", $status, $command, $output, $error));
	}
}

$root    = sys_get_temp_dir() . '/dmc-patch-equivalence-' . bin2hex(random_bytes(4));
$origin  = $root . '/origin.git';
$primary = $root . '/primary';
$wt      = $root . '/feature-worktree';

mkdir($root, 0700, true);
worktree_cleanup_patch_equivalence_run($root, 'git init --bare origin.git');
worktree_cleanup_patch_equivalence_run($root, 'git clone origin.git primary');
worktree_cleanup_patch_equivalence_run($primary, 'git config user.email test@example.com');
worktree_cleanup_patch_equivalence_run($primary, 'git config user.name Test');
worktree_cleanup_patch_equivalence_run($primary, 'git checkout -b main');
file_put_contents($primary . '/recipe.txt', "base\n");
worktree_cleanup_patch_equivalence_run($primary, 'git add recipe.txt');
worktree_cleanup_patch_equivalence_run($primary, 'git commit -m base');
worktree_cleanup_patch_equivalence_run($primary, 'git push -u origin main');
worktree_cleanup_patch_equivalence_run($origin, 'git symbolic-ref HEAD refs/heads/main');
worktree_cleanup_patch_equivalence_run($primary, 'git worktree add -b feature ../feature-worktree origin/main');
worktree_cleanup_patch_equivalence_run($wt, 'git config user.email test@example.com');
worktree_cleanup_patch_equivalence_run($wt, 'git config user.name Test');
file_put_contents($wt . '/recipe.txt', "base\nsquash-equivalent\n");
worktree_cleanup_patch_equivalence_run($wt, 'git add recipe.txt');
worktree_cleanup_patch_equivalence_run($wt, 'git commit -m feature-change');
file_put_contents($primary . '/recipe.txt', "base\nsquash-equivalent\n");
worktree_cleanup_patch_equivalence_run($primary, 'git add recipe.txt');
worktree_cleanup_patch_equivalence_run($primary, 'git commit -m squash-feature-change');
worktree_cleanup_patch_equivalence_run($primary, 'git push origin main');

$cleanup = new class {
	use WorkspaceCoreUtilities;
	use WorkspaceActiveNoSignalCleanup;
	use WorkspaceWorktreeCleanupEngine {
		WorkspaceWorktreeCleanupEngine::classify_unpushed_patch_equivalent_to_default as public classifyPatchEquivalent;
	}

	protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;

	protected function resolve_remote_default_ref( string $primary_path, int $timeout_seconds = 0 ): string|WP_Error|null {
		$result = $this->run_git($primary_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD', $timeout_seconds);
		if ( is_wp_error($result) ) {
			return null;
		}

		$ref = trim( (string) ( $result['output'] ?? '' ));
		return '' === $ref ? null : $ref;
	}
};

$fetched        = array();
$fetch_timeouts = array();
$evidence       = $cleanup->classifyPatchEquivalent($primary, $wt, 'repo', $fetched, $fetch_timeouts);

worktree_cleanup_patch_equivalence_assert_same(true, is_array($evidence), 'squash-equivalent commit should be promoted with evidence');
worktree_cleanup_patch_equivalence_assert_same('equivalent_clean', $evidence['effective_status'], 'git cherry proves patch equivalence');
worktree_cleanup_patch_equivalence_assert_same('refs/remotes/origin/main', $evidence['compared_refs']['left'], 'evidence records default ref');
worktree_cleanup_patch_equivalence_assert_same('HEAD', $evidence['compared_refs']['right'], 'evidence records worktree ref');
worktree_cleanup_patch_equivalence_assert_same(1, $evidence['git_cherry']['equivalent'], 'squash-equivalent commit is counted as equivalent');
worktree_cleanup_patch_equivalence_assert_same(0, $evidence['git_cherry']['unmatched'], 'no unmatched commits are promoted');
worktree_cleanup_patch_equivalence_assert_same('preserve_local_branch', $evidence['local_branch_handling'], 'cleanup preserves non-contained local branch');

echo "worktree-cleanup-patch-equivalence: ok\n";
