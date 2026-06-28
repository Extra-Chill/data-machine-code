<?php
/**
 * Integration smoke: real-git verification of issue #833.
 *
 * Exercises WorkspaceGitOperations::git_pull() against real git repositories to
 * prove a primary whose default branch has NO upstream tracking ref can still be
 * refreshed via the `--allow-primary-refresh` path: the helper sets the missing
 * upstream from origin/<branch>, then the fast-forward pull lands the new commit.
 *
 * Requires `git` on PATH. Creates throwaway repos under a temp dir and cleans up.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorkspaceAliasResolver {
		public static function is_context_repository( string $handle ): bool {
			return false;
		}
		public static function mutation_error( string $handle, string $operation ): array {
			return array( 'error' => $operation );
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', '/tmp');
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	if ( ! class_exists('WP_Error') ) {
		final class WP_Error {
			public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
			public function get_error_code(): string {
				return $this->code;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_data() {
				return $this->data;
			}
		}
	}
}

namespace DataMachineCode\Tests\NoUpstream {
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitOperations.php';

	use DataMachineCode\Workspace\WorkspaceGitOperations;

	/**
	 * Minimal real-git harness around the git_pull() trait.
	 */
	final class RealGitWorkspace {
		use WorkspaceGitOperations;

		public function __construct( private string $primary_path ) {}

		protected function parse_handle( string $handle ): array {
			return array(
				'repo'        => $handle,
				'dir_name'    => $handle,
				'is_worktree' => false,
			);
		}

		protected function resolve_repo_path( string $handle ): string {
			return $this->primary_path;
		}

		protected function ensure_git_mutation_allowed( string $repo ): true {
			return true;
		}

		protected function ensure_primary_mutation_allowed( array $parsed, bool $allow, string $message = '' ): true {
			return true;
		}

		// Bypass the policy-attestation machinery (WorkspacePolicy etc.) — this
		// test targets the upstream-recovery + fast-forward git behavior, which
		// runs after the clean-tree check.
		public function git_status( string $handle ): array {
			return array( 'dirty' => 0 );
		}

		// Resolve the branch with real git (production helper delegates to the
		// GitRunner support class, which isn't loaded in this lean harness).
		protected function git_get_branch( string $repo_path ): ?string {
			$res = $this->run_git($repo_path, 'rev-parse --abbrev-ref HEAD');
			if ( $res instanceof \WP_Error ) {
				return null;
			}
			$branch = trim( (string) ( $res['output'] ?? '' ));
			return '' === $branch ? null : $branch;
		}

		protected function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {}

		/**
		 * Real git invocation (the production helper lives in a sibling trait).
		 *
		 * @return array{output:string}|\WP_Error
		 */
		protected function run_git( string $repo_path, string $git_args, int $timeout_seconds = 0 ): array|\WP_Error {
			$out  = array();
			$code = 0;
			exec(sprintf('cd %s && git %s 2>&1', escapeshellarg($repo_path), $git_args), $out, $code);
			$output = implode("\n", $out);
			if ( 0 !== $code ) {
				return new \WP_Error('git_command_failed', $output, array( 'exit_code' => $code, 'output' => $output ));
			}
			return array( 'output' => $output );
		}
	}

	function run( string $cwd, string $args ): string {
		$out  = array();
		$code = 0;
		exec(sprintf('cd %s && git %s 2>&1', escapeshellarg($cwd), $args), $out, $code);
		if ( 0 !== $code ) {
			throw new \RuntimeException("git $args failed in $cwd:\n" . implode("\n", $out));
		}
		return implode("\n", $out);
	}

	function rmrf( string $path ): void {
		if ( ! is_dir($path) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
		}
		rmdir($path);
	}

	// --- Skip cleanly if git is unavailable. ---
	exec('git --version 2>/dev/null', $probe, $git_code);
	if ( 0 !== $git_code ) {
		fwrite(STDOUT, "no-upstream refresh smoke skipped (git unavailable)\n");
		exit(0);
	}

	// Root throwaway repos in a unique, self-removing dir under this worktree so
	// the test never reaches outside the workspace sandbox and leaves no residue.
	$root = dirname(__DIR__) . '/.dmc-833-tmp-' . bin2hex(random_bytes(4));
	mkdir($root, 0700, true);

	try {
		$origin  = $root . '/origin.git';
		$seed    = $root . '/seed';
		$primary = $root . '/primary';

		// Bare origin defaults its HEAD to the initial branch; pin it to main so
		// a later `git clone` checks out main rather than a phantom master.
		run($root, 'init -q -b main --bare ' . escapeshellarg($origin));
		mkdir($seed, 0700, true);
		run($seed, 'init -q -b main');
		file_put_contents($seed . '/a.txt', "one\n");
		run($seed, 'add a.txt');
		run($seed, '-c user.email=t@t -c user.name=t commit -q -m init');
		run($seed, 'remote add origin ' . escapeshellarg($origin));
		run($seed, 'push -q origin main');

		// Land a second commit on origin so the primary has something to fast-forward to.
		file_put_contents($seed . '/b.txt', "two\n");
		run($seed, 'add b.txt');
		run($seed, '-c user.email=t@t -c user.name=t commit -q -m second');
		run($seed, 'push -q origin main');

		// Clone the primary, then strip its upstream tracking to reproduce no_upstream.
		run($root, 'clone -q ' . escapeshellarg($origin) . ' ' . escapeshellarg($primary));
		// Move the primary back one commit so a fast-forward is actually possible.
		run($primary, 'reset -q --hard HEAD~1');
		run($primary, 'branch --unset-upstream');

		$branch_vv = run($primary, 'branch -vv');
		if ( str_contains($branch_vv, '[origin/main') ) {
			throw new \RuntimeException("precondition failed: upstream still set:\n$branch_vv");
		}

		// Sanity: a raw `git pull --ff-only` must hard-fail with no tracking info.
		$raw = array();
		$raw_code = 0;
		exec(sprintf('cd %s && git pull --ff-only 2>&1', escapeshellarg($primary)), $raw, $raw_code);
		if ( 0 === $raw_code ) {
			throw new \RuntimeException('precondition failed: raw pull --ff-only unexpectedly succeeded');
		}

		$before = run($primary, 'rev-parse HEAD');

		// The fix under test: git_pull() should set the missing upstream and fast-forward.
		$ws     = new RealGitWorkspace($primary);
		$result = $ws->git_pull('primary', false, true);

		if ( $result instanceof \WP_Error ) {
			throw new \RuntimeException('git_pull returned WP_Error: ' . $result->get_error_message());
		}
		if ( true !== ( $result['success'] ?? null ) ) {
			throw new \RuntimeException('git_pull did not report success: ' . var_export($result, true));
		}

		$after = run($primary, 'rev-parse HEAD');
		if ( $before === $after ) {
			throw new \RuntimeException('HEAD did not advance; fast-forward did not happen');
		}

		$post_vv = run($primary, 'branch -vv');
		if ( ! str_contains($post_vv, '[origin/main') ) {
			throw new \RuntimeException("upstream was not set after refresh:\n$post_vv");
		}

		fwrite(STDOUT, "no-upstream refresh smoke passed (real git: upstream recovered + fast-forwarded)\n");
	} finally {
		rmrf($root);
	}
}
