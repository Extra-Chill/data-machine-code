<?php
/**
 * Workspace-backed code-task workspace facade.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeAllocationRequest;

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

	public function worktree_add( WorktreeAllocationRequest $request ): array|\WP_Error {
		return $this->workspace->worktree_add_request($request);
	}

	public function get_repo_path( string $handle ): string {
		return $this->workspace->get_repo_path($handle);
	}
}
