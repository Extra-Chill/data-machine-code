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
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

namespace DataMachineCode\Tests {
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitOperations.php';

	use DataMachineCode\Workspace\WorkspaceGitOperations;

	final class GitPullWorkspaceDouble {
		use WorkspaceGitOperations;

		public array $emitted = array();
		public array $commands = array();
		public array $lock_states = array();

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

		protected function with_workspace_repo_mutation_lock( string $repo, callable $callback ): mixed {
			$this->lock_states[] = array( 'status' => 'acquired', 'repo' => $repo );
			try {
				return $callback();
			} finally {
				$this->lock_states[] = array( 'status' => 'released', 'repo' => $repo );
			}
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
			if ( str_contains($command, 'ls-remote --symref') ) {
				return array( 'output' => "ref: refs/heads/main\tHEAD\n" );
			}
			if ( str_contains($command, 'rev-list --left-right --count') ) {
				return array( 'output' => "0\t0" );
			}
			if ( str_contains($command, 'rev-parse --verify') ) {
				return array( 'output' => 'abcabcabcabcabcabcabcabcabcabcabcabcabca' );
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

	function assert_true( bool $actual, string $message ): void {
		if ( ! $actual ) {
			throw new \RuntimeException($message);
		}
	}

	function resolve_detached_default_branch( GitPullWorkspaceDouble $workspace ): array|\WP_Error {
		$method = new \ReflectionMethod($workspace, 'resolve_detached_primary_default_branch');
		return $method->invoke($workspace, '/workspace/data-machine-code', 'origin');
	}

	$primary = new GitPullWorkspaceDouble();
	$result  = $primary->git_pull('data-machine-code', false, true);
	assert_same(true, $result['success'] ?? null, 'primary pull did not succeed');
	assert_same(
		array(
			array( 'status' => 'acquired', 'repo' => 'data-machine-code' ),
			array( 'status' => 'released', 'repo' => 'data-machine-code' ),
		),
		$primary->lock_states,
		'primary pull did not release its shared mutation lock before returning'
	);
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

	// Issue #1097: refresh fetches and classifies primary freshness before raw
	// pull, keeping Git's merge/rebase advice out of primary-safe recovery.
	$current = new GitPullWorkspaceDouble();
	$current->responses = array(
		'@{upstream}'                       => array( 'output' => "origin/main\n" ),
		'rev-list --left-right --count HEAD' => array( 'output' => "0\t0\n" ),
	);
	$result = $current->git_pull('data-machine-code', false, true);
	assert_same(true, $result['success'] ?? null, 'current primary refresh did not succeed');
	assert_true($current->ran_command_containing('fetch --no-tags --prune'), 'current primary refresh did not fetch before pull');

	$behind = new GitPullWorkspaceDouble();
	$behind->responses = array(
		'@{upstream}'                       => array( 'output' => "origin/main\n" ),
		'rev-list --left-right --count HEAD' => array( 'output' => "0\t3\n" ),
	);
	$result = $behind->git_pull('data-machine-code', false, true);
	assert_same(true, $result['success'] ?? null, 'behind-only primary refresh did not fast-forward');
	assert_true($behind->ran_command_containing('pull --ff-only'), 'behind-only primary refresh did not run fast-forward pull');

	$ahead = new GitPullWorkspaceDouble();
	$ahead->responses = array(
		'@{upstream}'                       => array( 'output' => "origin/main\n" ),
		'rev-list --left-right --count HEAD' => array( 'output' => "2\t0\n" ),
	);
	$result = $ahead->git_pull('data-machine-code', false, true);
	assert_true($result instanceof \WP_Error && 'primary_refresh_ahead' === $result->code, 'ahead-only primary refresh was not classified');
	assert_same(2, $result->data['primary_freshness']['ahead'] ?? null, 'ahead-only evidence omitted ahead count');
	assert_same(0, $result->data['primary_freshness']['behind'] ?? null, 'ahead-only evidence omitted behind count');
	assert_true(! $ahead->ran_command_containing('pull --ff-only'), 'ahead-only primary refresh invoked raw pull');

	$diverged = new GitPullWorkspaceDouble();
	$diverged->responses = array(
		'@{upstream}'                       => array( 'output' => "origin/main\n" ),
		'rev-list --left-right --count HEAD' => array( 'output' => "2\t3\n" ),
	);
	$result = $diverged->git_pull('data-machine-code', false, true);
	assert_true($result instanceof \WP_Error && 'primary_refresh_diverged' === $result->code, 'diverged primary refresh was not classified');
	assert_same('diverged', $result->data['primary_freshness']['status'] ?? null, 'diverged evidence omitted state');
	assert_same(2, $result->data['primary_freshness']['ahead'] ?? null, 'diverged evidence omitted ahead count');
	assert_same(3, $result->data['primary_freshness']['behind'] ?? null, 'diverged evidence omitted behind count');
	assert_same('fresh_origin_worktree', $result->data['recommended_recovery']['kind'] ?? null, 'diverged recovery did not recommend a fresh origin worktree');
	assert_same(true, $result->data['dangerous_primary_history_mutation_requires_authorization'] ?? null, 'diverged recovery did not retain dangerous-primary authorization');
	assert_true(! str_contains($result->message, 'rebase'), 'diverged recovery exposed rebase advice');
	assert_true(! $diverged->ran_command_containing('pull --ff-only'), 'diverged primary refresh invoked raw pull');

	// Issue #987: a validated local origin/HEAD avoids a network probe during
	// default-branch selection. The later fetch remains the freshness gate.
	$local_head = new GitPullWorkspaceDouble();
	$local_head->responses = array(
		'symbolic-ref --quiet'                              => array( 'output' => "refs/remotes/origin/main\n" ),
		"rev-parse --verify 'refs/remotes/origin/main'" => array( 'output' => "abcabcabcabcabcabcabcabcabcabcabcabcabca\n" ),
	);
	$resolved = resolve_detached_default_branch($local_head);
	assert_same('main', $resolved['branch'] ?? null, 'valid local origin/HEAD did not resolve main');
	assert_same(false, $local_head->ran_command_containing('ls-remote --symref'), 'valid local origin/HEAD unexpectedly used the network');

	// Stale and malformed local symbolic refs are not trusted and use the
	// bounded remote symref fallback instead.
	$stale_head = new GitPullWorkspaceDouble();
	$stale_head->responses = array(
		'symbolic-ref --quiet'                                => array( 'output' => "refs/remotes/origin/missing\n" ),
		"rev-parse --verify 'refs/remotes/origin/missing'" => new \WP_Error('missing_ref'),
		'ls-remote --symref'                                   => array( 'output' => "ref: refs/heads/main\tHEAD\n" ),
	);
	$resolved = resolve_detached_default_branch($stale_head);
	assert_same('main', $resolved['branch'] ?? null, 'stale local origin/HEAD did not use remote fallback');
	assert_same(true, $stale_head->ran_command_containing('ls-remote --symref'), 'stale local origin/HEAD did not use remote fallback');

	$malformed_head = new GitPullWorkspaceDouble();
	$malformed_head->responses = array(
		'symbolic-ref --quiet' => array( 'output' => "refs/heads/main\n" ),
		'ls-remote --symref'    => array( 'output' => "ref: refs/heads/main\tHEAD\n" ),
	);
	$resolved = resolve_detached_default_branch($malformed_head);
	assert_same('main', $resolved['branch'] ?? null, 'malformed local origin/HEAD did not use remote fallback');

	$offline = new GitPullWorkspaceDouble();
	$offline->responses = array(
		'symbolic-ref --quiet' => new \WP_Error('missing_ref'),
		'ls-remote --symref'    => new \WP_Error('network_unavailable'),
	);
	$resolved = resolve_detached_default_branch($offline);
	assert_same('detached_primary_default_branch_unavailable', $resolved->get_error_code(), 'unavailable default-branch sources returned the wrong error');
	assert_same(
		array(
			array( 'source' => 'local_symbolic_ref', 'status' => 'unavailable' ),
			array( 'source' => 'remote_symref', 'status' => 'unavailable' ),
		),
		$resolved->get_error_data()['attempted_sources'] ?? null,
		'unavailable default-branch sources were not reported'
	);
	$ambiguous = new GitPullWorkspaceDouble();
	$ambiguous->responses = array(
		'symbolic-ref --quiet' => new \WP_Error('missing_ref'),
		'ls-remote --symref'    => array( 'output' => '' ),
	);
	$resolved = resolve_detached_default_branch($ambiguous);
	assert_same('detached_primary_default_branch_ambiguous', $resolved->get_error_code(), 'ambiguous topology returned the wrong error');

	// An explicit branch continues to bypass default-branch discovery.
	$explicit_branch = new GitPullWorkspaceDouble();
	$explicit_branch->current_branch = 'HEAD';
	$result = $explicit_branch->git_pull('data-machine-code', false, true, 'origin', 'main');
	assert_same(true, $result['success'] ?? null, 'explicit branch override did not advance pull');
	assert_same(false, $explicit_branch->ran_command_containing('symbolic-ref --quiet'), 'explicit branch override resolved the default branch');

	// Detached-primary callers retain the established fetch failure code.
	$detached_fetch = new GitPullWorkspaceDouble();
	$detached_fetch->current_branch = 'HEAD';
	$detached_fetch->responses = array(
		'fetch --no-tags' => new \WP_Error('git_command_failed', 'fetch failed'),
	);
	$result = $detached_fetch->git_pull('data-machine-code', false, true);
	assert_true($result instanceof \WP_Error && 'detached_primary_fetch_failed' === $result->code, 'detached primary fetch failure code changed');

	fwrite(STDOUT, "workspace git pull primary refresh passed\n");
}
