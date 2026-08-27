<?php
/**
 * Canonical worktree allocation application operation.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeAllocationOperation {

	/** @var callable():bool */
	private $remote_enabled;

	/**
	 * @param callable():bool|null $remote_enabled
	 */
	public function __construct(
		private ?Workspace $workspace = null,
		private ?RemoteWorkspaceBackend $remote = null,
		?callable $remote_enabled = null
	) {
		$this->workspace      ??= new Workspace();
		$this->remote         ??= new RemoteWorkspaceBackend();
		$this->remote_enabled = $remote_enabled ?? static fn(): bool => RemoteWorkspaceBackend::should_handle();
	}

	/** Execute a read-only allocation plan. */
	public function plan( WorktreeAllocationRequest $request ): array|\WP_Error {
		return $this->workspace->worktree_plan_request($request);
	}

	/** Execute allocation against the authoritative available backend. */
	public function add( WorktreeAllocationRequest $request ): array|\WP_Error {
		$remote_enabled = ( $this->remote_enabled )();
		$local_primary  = $remote_enabled && $this->has_local_primary($request->repo);

		if ( $request->require_task_tracker && empty($request->task) && $remote_enabled && ! $local_primary ) {
			return new \WP_Error('worktree_task_tracker_required', 'Refusing to create a managed worktree without a valid task URL or task reference.', array( 'status' => 400 ));
		}

		if ( ! $remote_enabled || $local_primary ) {
			return $this->workspace->worktree_add_request($request);
		}

		if ( $request->allow_percentage_byte_floor_exception ) {
			return new \WP_Error(
				'remote_worktree_percentage_byte_floor_exception_unsupported',
				'Percentage-byte-floor admission requires a local workspace with measured capacity semantics.',
				array(
					'status'      => 400,
					'remediation' => array(
						'code'    => 'local_workspace_capacity_required',
						'message' => 'Run the request against a local managed workspace, where byte and inode capacity can be measured and revalidated.',
					),
				)
			);
		}

		if ( $request->remediate_capacity || $request->remediate_capacity_dry_run ) {
			return new \WP_Error(
				'remote_worktree_capacity_remediation_unsupported',
				'Capacity remediation requires a local workspace because remote workspace allocation has no filesystem capacity or cleanup lifecycle.',
				array(
					'status'                     => 400,
					'remediate_capacity'         => $request->remediate_capacity,
					'remediate_capacity_dry_run' => $request->remediate_capacity_dry_run,
				)
			);
		}

		$result = $this->remote->worktree_add(
			$request->repo,
			$request->branch,
			$request->from,
			$request->task,
			$request->intent,
			$request->reuse_policy,
			$request->allow_unverified_freshness
		);
		if ( $this->should_fallback_to_local($result) ) {
			return $this->workspace->worktree_add_request($request);
		}

		return $result;
	}

	private function has_local_primary( string $repo ): bool {
		if ( '' === trim($repo) ) {
			return false;
		}

		$result = $this->workspace->show_repo($repo);
		if ( is_wp_error($result) ) {
			return false;
		}

		$path = (string) ( $result['path'] ?? '' );
		return '' !== $path && ! str_starts_with($path, 'github://') && ! str_contains(basename($path), '@');
	}

	private function should_fallback_to_local( mixed $result ): bool {
		return is_wp_error($result) && in_array($result->get_error_code(), array( 'remote_workspace_repo_not_found', 'unsupported_remote_workspace_repo_argument' ), true);
	}
}
