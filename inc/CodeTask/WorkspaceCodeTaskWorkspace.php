<?php
/**
 * Workspace-backed code-task workspace facade.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

class WorkspaceCodeTaskWorkspace implements CodeTaskWorkspaceInterface {


	public function __construct( private Workspace $workspace ) {
	}

	public function workspace(): Workspace {
		return $this->workspace;
	}

	public function get_primary_path( string $repo ): string {
		return $this->workspace->get_primary_path($repo);
	}

	public function clone_repo( string $url, string $name ): array|\WP_Error {
		return $this->workspace->clone_repo($url, $name);
	}

	public function worktree_add( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force ): array|\WP_Error {
		return $this->workspace->worktree_add($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force);
	}

	public function get_repo_path( string $handle ): string {
		return $this->workspace->get_repo_path($handle);
	}
}
