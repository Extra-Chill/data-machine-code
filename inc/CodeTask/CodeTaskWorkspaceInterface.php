<?php
/**
 * Workspace facade for code-task scaffolding.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeAllocationRequest;

defined('ABSPATH') || exit;

interface CodeTaskWorkspaceInterface {


	public function workspace(): Workspace;

	public function get_primary_path( string $repo ): string;

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function clone_repo( string $url, string $name ): array|\WP_Error;

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_add( WorktreeAllocationRequest $request ): array|\WP_Error;

	public function get_repo_path( string $handle ): string;
}
