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

		public function git_status( string $handle ): array {
			return array( 'dirty' => 0 );
		}

		protected function run_git( string $repo_path, string $command, int $timeout_seconds = 0 ): array {
			$this->commands[] = compact('repo_path', 'command', 'timeout_seconds');

			return array( 'output' => 'Already up to date.' );
		}

		protected function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {
			$this->emitted[] = compact('op', 'repo', 'name', 'path');
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

	fwrite(STDOUT, "workspace git pull primary refresh passed\n");
}
