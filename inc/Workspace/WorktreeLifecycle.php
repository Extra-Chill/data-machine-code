<?php
/**
 * Local worktree allocation surface used by the application operation.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

interface WorktreeLifecycle {

	/** Produce a non-mutating allocation decision from one typed request. */
	public function worktree_plan_request( WorktreeAllocationRequest $request ): array|\WP_Error;

	/** Execute allocation from one typed request. */
	public function worktree_add_request( WorktreeAllocationRequest $request ): array|\WP_Error;

	/** Resolve a local repository or worktree handle for primary detection. */
	public function show_repo( string $handle, bool $refresh = false ): array|\WP_Error;
}
