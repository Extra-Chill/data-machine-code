<?php

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorkspaceAliasResolver {
		public static function is_context_repository( string $handle ): bool { return str_starts_with($handle, 'context'); }
		public static function mutation_error( string $handle, string $operation ): \WP_Error { return new \WP_Error('context_repository_read_only', $operation . ':' . $handle); }
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	$GLOBALS['workspace_git_mutation_policy'] = array( 'repos' => array() );
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['workspace_git_mutation_policy']; }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitOperations.php';

	use DataMachineCode\Workspace\WorkspaceGitOperations;

	final class GitMutationPreflightHarness {
		use WorkspaceGitOperations { prepare_git_mutation as public prepare; }
		public function __construct( private string $workspace_path ) {}
		private function parse_handle( string $handle ): array {
			$parts = explode('@', trim($handle), 2);
			return array( 'repo' => $parts[0], 'branch_slug' => $parts[1] ?? null, 'is_worktree' => isset($parts[1]), 'dir_name' => trim($handle) );
		}
		private function require_explicit_workspace_handle( string $handle ): array|WP_Error {
			return '' === trim($handle) ? new WP_Error('missing_workspace_handle') : $this->parse_handle($handle);
		}
		private function validate_containment( string $path, string $container ): array {
			$real = realpath($path);
			return array( 'valid' => is_string($real) && str_starts_with($real, realpath($container) . '/'), 'real_path' => $real ?: '', 'message' => 'outside' );
		}
	}

	function git_mutation_preflight_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	$root = sys_get_temp_dir() . '/dmc-git-mutation-' . bin2hex(random_bytes(4));
	mkdir($root . '/repo/.git', 0700, true);
	mkdir($root . '/repo@task', 0700, true);
	file_put_contents($root . '/repo@task/.git', 'gitdir: /tmp/repo');
	try {
		$harness = new GitMutationPreflightHarness($root);
		$worktree = $harness->prepare('repo@task', 'git add', false);
		git_mutation_preflight_assert_same(false, is_wp_error($worktree), 'Worktrees pass the shared mutation preflight');
		git_mutation_preflight_assert_same('repo', $worktree['repo_name'], 'Preflight returns the canonical repository');
		git_mutation_preflight_assert_same(realpath($root . '/repo@task'), $worktree['repo_path'], 'Preflight returns the contained checkout path');

		$primary = $harness->prepare('repo', 'git pull', false, false, 'Use the refresh override');
		git_mutation_preflight_assert_same('primary_mutation_blocked', $primary->get_error_code(), 'Primary mutation remains blocked');
		git_mutation_preflight_assert_same(true, str_contains($primary->get_error_message(), 'Use the refresh override'), 'Operation-specific primary guidance remains intact');
		git_mutation_preflight_assert_same(false, is_wp_error($harness->prepare('repo', 'git pull', true)), 'Explicit primary mutation remains allowed');

		$GLOBALS['workspace_git_mutation_policy']['repos']['repo'] = array( 'write_enabled' => false );
		git_mutation_preflight_assert_same('git_write_disabled', $harness->prepare('repo@task', 'git add', false)->get_error_code(), 'Write policy remains enforced');
		$GLOBALS['workspace_git_mutation_policy']['repos']['repo'] = array( 'push_enabled' => false );
		git_mutation_preflight_assert_same('git_push_disabled', $harness->prepare('repo@task', 'git push', false, true)->get_error_code(), 'Push policy remains enforced when requested');

		$GLOBALS['workspace_git_mutation_policy']['repos'] = array();
		git_mutation_preflight_assert_same('context_repository_read_only', $harness->prepare('context-docs', 'git reset', false)->get_error_code(), 'Context repositories remain read-only');
		git_mutation_preflight_assert_same('missing_workspace_handle', $harness->prepare('', 'delete', false, false, 'unused', true)->get_error_code(), 'Explicit-handle operations preserve validation');
	} finally {
		unlink($root . '/repo@task/.git');
		rmdir($root . '/repo@task');
		rmdir($root . '/repo/.git');
		rmdir($root . '/repo');
		rmdir($root);
	}

	echo "workspace-git-mutation-preflight: ok\n";
}
