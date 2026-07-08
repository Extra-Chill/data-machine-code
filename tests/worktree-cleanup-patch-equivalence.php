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
require_once dirname(__DIR__) . '/inc/Support/PathSecurity.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Support/GitRunner.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHandle.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
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
worktree_cleanup_patch_equivalence_run($primary, 'git remote set-head origin -a');

$cleanup = new class {
	use WorkspaceCoreUtilities;
	use WorkspaceActiveNoSignalCleanup;
	use WorkspaceWorktreeCleanupEngine {
		WorkspaceWorktreeCleanupEngine::classify_unpushed_patch_equivalent_to_default as public classifyPatchEquivalent;
		WorkspaceWorktreeCleanupEngine::detect_local_merged_signal as public detectLocalMerged;
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

$suggest_action = new ReflectionMethod($cleanup, 'suggest_active_no_signal_action');
$merged_action = $suggest_action->invoke(
	$cleanup,
	array(
		'dirty'                   => 0,
		'unpushed'                => 0,
		'commits_outside_default' => 0,
		'remote_tracking'         => true,
		'upstream_equivalence'    => array(
			'effective_status' => 'equivalent_clean',
		),
	)
);
worktree_cleanup_patch_equivalence_assert_same('merged_to_default', $merged_action, 'exact clean containment in the remote default branch should win over patch-equivalence and remote-tracking labels');

// Simulate the real squash-merge workflow: the feature branch was pushed to
// origin before being squash-merged into main. After the merge the local branch
// is still clean and fully pushed relative to its remote tracking ref.
worktree_cleanup_patch_equivalence_run($wt, 'git push -u origin feature');

$merged = $root . '/merged-worktree';
worktree_cleanup_patch_equivalence_run($primary, 'git worktree add -b merged-feature ../merged-worktree origin/main');
worktree_cleanup_patch_equivalence_run($merged, 'git config user.email test@example.com');
worktree_cleanup_patch_equivalence_run($merged, 'git config user.name Test');
file_put_contents($merged . '/merged.txt', "merged\n");
worktree_cleanup_patch_equivalence_run($merged, 'git add merged.txt');
worktree_cleanup_patch_equivalence_run($merged, 'git commit -m merged-feature');
worktree_cleanup_patch_equivalence_run($primary, 'git merge --ff-only merged-feature');
worktree_cleanup_patch_equivalence_run($primary, 'git push origin main');

$merged_cleanup = new class($root) {
	use WorkspaceCoreUtilities;
	use WorkspaceActiveNoSignalCleanup;
	use WorkspaceWorktreeCleanupEngine;

	protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;

	protected string $workspace_path;

	public function __construct( string $workspace_path ) {
		$this->workspace_path = $workspace_path;
	}

	public function get_primary_path( string $repo ): string {
		return $this->workspace_path . '/primary';
	}

	protected function resolve_remote_default_ref( string $primary_path, int $timeout_seconds = 0 ): string|WP_Error|null {
		return 'refs/remotes/origin/main';
	}

	/** @return array<int,string> */
	protected function protected_base_branch_names(): array {
		return array( 'main', 'master', 'trunk', 'develop' );
	}

	protected function resolve_worktree_branch_from_head_file( string $wt_path ): ?string {
		$result = $this->run_git($wt_path, 'branch --show-current', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($result) ) {
			return null;
		}

		$branch = trim((string) ( $result['output'] ?? '' ));
		return '' === $branch ? null : $branch;
	}

	protected function probe_worktree_dirty_count( string $path, int $timeout_seconds = 0 ): int|WP_Error {
		$result = $this->run_git($path, 'status --porcelain', $timeout_seconds);
		if ( is_wp_error($result) ) {
			return $result;
		}

		$lines = array_filter(array_map('trim', explode("\n", (string) ( $result['output'] ?? '' ))));
		return count($lines);
	}

	/** @return array<string,mixed> */
	public function worktree_active_no_signal_report( array $opts = array() ): array {
		return array(
			'success' => true,
			'rows'    => array(
				array(
					'handle'                  => 'repo@merged-feature',
					'repo'                    => 'repo',
					'branch'                  => 'merged-feature',
					'path'                    => $this->workspace_path . '/merged-worktree',
					'dirty'                   => 0,
					'unpushed'                => 0,
					'commits_outside_default' => 0,
					'suggested_action'        => 'remote_tracking_clean',
				),
			),
			'summary' => array( 'inspected' => 1 ),
		);
	}
};

$merged_apply = $merged_cleanup->worktree_active_no_signal_merged_apply(array( 'dry_run' => true ));
worktree_cleanup_patch_equivalence_assert_same(true, is_array($merged_apply), 'merged apply should return a dry-run result');
worktree_cleanup_patch_equivalence_assert_same(1, $merged_apply['summary']['planned'], 'merged apply should plan clean rows with zero commits outside default even when an older report label was remote_tracking_clean');
worktree_cleanup_patch_equivalence_assert_same('repo@merged-feature', $merged_apply['planned'][0]['handle'], 'merged apply should preserve the planned handle');

$local_merged_signal = $cleanup->detectLocalMerged($primary, 'feature');
worktree_cleanup_patch_equivalence_assert_same(true, is_array($local_merged_signal), 'squash-merged branch should produce a local merge signal via patch equivalence');
worktree_cleanup_patch_equivalence_assert_same('patch-equivalent-merged', $local_merged_signal['signal'], 'signal name should reflect patch-equivalent squash-merge detection');
worktree_cleanup_patch_equivalence_assert_same(true, $local_merged_signal['preserve_local_branch'], 'patch-equivalent cleanup should preserve the local branch');

$patch_equivalent_action = $suggest_action->invoke(
	$cleanup,
	array(
		'dirty'                   => 0,
		'unpushed'                => 0,
		'commits_outside_default' => 1,
		'upstream_equivalence'    => array(
			'effective_status' => 'equivalent_clean',
		),
	)
);
worktree_cleanup_patch_equivalence_assert_same('patch_equivalent_default', $patch_equivalent_action, 'clean patch-equivalent branch with no open PR should suggest patch_equivalent_default');

$open_pr_action = $suggest_action->invoke(
	$cleanup,
	array(
		'dirty'                   => 0,
		'unpushed'                => 0,
		'commits_outside_default' => 1,
		'upstream_equivalence'    => array(
			'effective_status' => 'equivalent_clean',
		),
		'pr'                      => array(
			'state' => 'open',
		),
	)
);
worktree_cleanup_patch_equivalence_assert_same('active_open_pr', $open_pr_action, 'open PR must block patch_equivalent_default and keep the row as active_open_pr');

$dirty_action = $suggest_action->invoke(
	$cleanup,
	array(
		'dirty'                   => 1,
		'unpushed'                => 0,
		'commits_outside_default' => 1,
		'upstream_equivalence'    => array(
			'effective_status' => 'equivalent_clean',
		),
	)
);
worktree_cleanup_patch_equivalence_assert_same('unsafe_dirty_or_unpushed', $dirty_action, 'dirty patch-equivalent branch must stay blocked');

$patch_cleanup = new class($root) {
	use WorkspaceCoreUtilities;
	use WorkspaceActiveNoSignalCleanup;
	use WorkspaceWorktreeCleanupEngine;

	protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;

	protected string $workspace_path;

	public function __construct( string $workspace_path ) {
		$this->workspace_path = $workspace_path;
	}

	public function get_primary_path( string $repo ): string {
		return $this->workspace_path . '/primary';
	}

	protected function resolve_remote_default_ref( string $primary_path, int $timeout_seconds = 0 ): string|WP_Error|null {
		return 'refs/remotes/origin/main';
	}

	/** @return array<int,string> */
	protected function protected_base_branch_names(): array {
		return array( 'main', 'master', 'trunk', 'develop' );
	}

	protected function resolve_worktree_branch_from_head_file( string $wt_path ): ?string {
		$result = $this->run_git($wt_path, 'branch --show-current', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($result) ) {
			return null;
		}

		$branch = trim((string) ( $result['output'] ?? '' ));
		return '' === $branch ? null : $branch;
	}

	protected function probe_worktree_dirty_count( string $path, int $timeout_seconds = 0 ): int|WP_Error {
		$result = $this->run_git($path, 'status --porcelain', $timeout_seconds);
		if ( is_wp_error($result) ) {
			return $result;
		}

		$lines = array_filter(array_map('trim', explode("\n", (string) ( $result['output'] ?? '' ))));
		return count($lines);
	}

	/** @return array<string,mixed> */
	public function worktree_active_no_signal_report( array $opts = array() ): array {
		return array(
			'success' => true,
			'rows'    => array(
				array(
					'handle'                  => 'repo@feature',
					'repo'                    => 'repo',
					'branch'                  => 'feature',
					'path'                    => $this->workspace_path . '/feature-worktree',
					'dirty'                   => 0,
					'unpushed'                => 0,
					'commits_outside_default' => 1,
					'suggested_action'        => 'patch_equivalent_default',
				),
			),
			'summary' => array( 'inspected' => 1 ),
		);
	}
};

$patch_apply = $patch_cleanup->worktree_active_no_signal_merged_apply(array( 'dry_run' => true ));
worktree_cleanup_patch_equivalence_assert_same(true, is_array($patch_apply), 'patch-equivalent apply should return a dry-run result');
worktree_cleanup_patch_equivalence_assert_same(1, $patch_apply['summary']['planned'], 'patch-equivalent apply should plan clean patch_equivalent_default rows');
worktree_cleanup_patch_equivalence_assert_same('repo@feature', $patch_apply['planned'][0]['handle'], 'patch-equivalent apply should preserve the planned handle');
worktree_cleanup_patch_equivalence_assert_same('patch-equivalent-to-default', $patch_apply['planned'][0]['metadata']['auto_finalized_signal'], 'patch-equivalent metadata should record the correct signal');

echo "worktree-cleanup-patch-equivalence: ok\n";
