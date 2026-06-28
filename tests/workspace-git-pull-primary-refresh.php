<?php

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
		define('ABSPATH', '/var/www/html');
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	}
}

namespace DataMachineCode\Tests {
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitOperations.php';

	use DataMachineCode\Workspace\WorkspaceGitOperations;

	final class GitPullWorkspaceDouble {
		use WorkspaceGitOperations;

		public array $emitted = array();
		public array $commands = array();

		/**
		 * Optional canned responses keyed by a substring of the git command.
		 * Value may be an array (returned as-is) or a WP_Error.
		 *
		 * @var array<string,mixed>
		 */
		public array $responses = array();

		/** @var string|null Current branch reported for HEAD. */
		public ?string $current_branch = 'main';

		protected function parse_handle( string $handle ): array {
			if ( str_contains($handle, '@') ) {
				return array(
					'repo'        => strtok($handle, '@'),
					'dir_name'    => $handle,
					'is_worktree' => true,
				);
			}

			return array(
				'repo'        => $handle,
				'dir_name'    => $handle,
				'is_worktree' => false,
			);
		}

		protected function resolve_repo_path( string $handle ): string {
			return '/workspace/' . $handle;
		}

		protected function ensure_git_mutation_allowed( string $repo ): true {
			return true;
		}

		protected function ensure_primary_mutation_allowed( array $parsed, bool $allow_primary_mutation, string $message ): true {
			return true;
		}

		protected function git_get_branch( string $repo_path ): ?string {
			return $this->current_branch;
		}

		public function git_status( string $handle ): array {
			return array( 'dirty' => 0 );
		}

		protected function run_git( string $repo_path, string $command, int $timeout_seconds = 0 ): array|\WP_Error {
			$this->commands[] = compact('repo_path', 'command', 'timeout_seconds');

			foreach ( $this->responses as $needle => $response ) {
				if ( str_contains($command, $needle) ) {
					return $response;
				}
			}

			return array( 'output' => 'Already up to date.' );
		}

		protected function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {
			$this->emitted[] = compact('op', 'repo', 'name', 'path');
		}

		/** Convenience: did any recorded command contain this substring? */
		public function ran_command_containing( string $needle ): bool {
			foreach ( $this->commands as $entry ) {
				if ( str_contains( (string) ( $entry['command'] ?? '' ), $needle) ) {
					return true;
				}
			}
			return false;
		}
	}

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
		}
	}

	$primary = new GitPullWorkspaceDouble();
	$result  = $primary->git_pull('data-machine-code', false, true);
	assert_same(true, $result['success'] ?? null, 'primary pull did not succeed');
	assert_same(
		array(
			array(
				'op'   => 'primary_refresh',
				'repo' => 'data-machine-code',
				'name' => 'data-machine-code',
				'path' => '/workspace/data-machine-code',
			),
		),
		$primary->emitted,
		'primary pull did not emit primary_refresh'
	);

	$worktree = new GitPullWorkspaceDouble();
	$result   = $worktree->git_pull('data-machine-code@fix-example', false, false);
	assert_same(true, $result['success'] ?? null, 'worktree pull did not succeed');
	assert_same(array(), $worktree->emitted, 'worktree pull should not emit primary_refresh');

	// Issue #833: a primary whose default branch has no upstream must be
	// recoverable — set the upstream from origin/<branch>, then fast-forward.
	$no_upstream = new GitPullWorkspaceDouble();
	$no_upstream->current_branch = 'main';
	$no_upstream->responses      = array(
		// No tracking ref configured for the current branch.
		'@{upstream}'        => new \WP_Error('no_upstream', 'no tracking information'),
		// origin has a same-named branch, so recovery is possible.
		'ls-remote --heads'  => array( 'output' => "abcabcabcabcabcabcabcabcabcabcabcabcabca\trefs/heads/main\n" ),
	);
	$result = $no_upstream->git_pull('data-machine-code', false, true);
	assert_same(true, $result['success'] ?? null, 'no_upstream primary pull did not succeed');
	assert_same(
		true,
		$no_upstream->ran_command_containing("branch --set-upstream-to='origin/main' 'main'"),
		'no_upstream pull did not set the missing upstream'
	);
	assert_same(
		true,
		$no_upstream->ran_command_containing('pull --ff-only'),
		'no_upstream pull did not fast-forward after setting upstream'
	);

	// When the remote has no matching branch, leave state untouched and let the
	// pull command surface the accurate error (no set-upstream attempt).
	$no_remote_branch = new GitPullWorkspaceDouble();
	$no_remote_branch->current_branch = 'main';
	$no_remote_branch->responses      = array(
		'@{upstream}'       => new \WP_Error('no_upstream', 'no tracking information'),
		'ls-remote --heads' => array( 'output' => '' ),
	);
	$no_remote_branch->git_pull('data-machine-code', false, true);
	assert_same(
		false,
		$no_remote_branch->ran_command_containing('--set-upstream-to'),
		'pull set an upstream even though origin had no matching branch'
	);

	// Already-tracking branch must not trigger a redundant set-upstream.
	$tracked = new GitPullWorkspaceDouble();
	$tracked->current_branch = 'main';
	$tracked->responses      = array(
		'@{upstream}' => array( 'output' => "origin/main\n" ),
	);
	$tracked->git_pull('data-machine-code', false, true);
	assert_same(
		false,
		$tracked->ran_command_containing('--set-upstream-to'),
		'pull set an upstream on an already-tracking branch'
	);

	fwrite(STDOUT, "workspace git pull primary refresh passed\n");
}
