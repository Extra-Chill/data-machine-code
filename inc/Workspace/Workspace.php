<?php
/**
 * Agent Workspace
 *
 * Provides a managed directory for agent file operations — cloning repos,
 * storing working files, etc. Lives outside the web root when possible
 * for security.
 *
 * @package DataMachineCode\Workspace
 * @since   0.31.0
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WorkspaceCoreUtilities.php';
require_once __DIR__ . '/WorkspaceAliasResolver.php';
require_once __DIR__ . '/WorkspaceActiveNoSignalCleanup.php';
require_once __DIR__ . '/WorkspaceArtifactCleanup.php';
require_once __DIR__ . '/WorkspaceCleanupPlan.php';
require_once __DIR__ . '/WorkspaceGitOperations.php';
require_once __DIR__ . '/WorkspaceHygieneReport.php';
require_once __DIR__ . '/WorkspaceMetadataReconciliation.php';
require_once __DIR__ . '/WorkspaceRepositoryLifecycle.php';
require_once __DIR__ . '/WorkspaceWorktreeLifecycle.php';
require_once __DIR__ . '/WorkspaceWorktreeCleanupEngine.php';
require_once __DIR__ . '/WorkspaceWorktreeInventoryCleanup.php';
require_once __DIR__ . '/WorkspaceWorktreeEmergencyCleanup.php';
require_once __DIR__ . '/WorktreeCleanupClassifier.php';

class Workspace {
	use WorkspaceCoreUtilities;
	use WorkspaceActiveNoSignalCleanup;
	use WorkspaceArtifactCleanup;
	use WorkspaceCleanupPlan;
	use WorkspaceGitOperations;
	use WorkspaceHygieneReport;
	use WorkspaceMetadataReconciliation;
	use WorkspaceRepositoryLifecycle;
	use WorkspaceWorktreeLifecycle;
	use WorkspaceWorktreeCleanupEngine;
	use WorkspaceWorktreeInventoryCleanup;
	use WorkspaceWorktreeEmergencyCleanup;

	/**
	 * Maximum file size for reading (1 MB).
	 */
	const MAX_READ_SIZE = 1048576;

	/**
	 * Bound GitHub cleanup checks so one slow repo cannot stall cleanup.
	 */
	protected const CLEANUP_GITHUB_TIMEOUT = 5;

	/**
	 * Bound per-worktree git cleanup probes so one wedged checkout cannot stall cleanup.
	 */
	protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;

	/**
	 * Closed PR pages to inspect per repo during cleanup.
	 */
	protected const CLEANUP_GITHUB_MAX_PAGES = 3;

	/**
	 * Number of largest/oldest rows to expose in cleanup summaries.
	 */
	protected const CLEANUP_SUMMARY_TOP_LIMIT = 10;

	/**
	 * Default cap on worktrees scanned for an artifact cleanup dry-run when no
	 * `limit` is provided. Keeps dry-run bounded and fast on huge workspaces.
	 */
	public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;

	/**
	 * Default cap on top-level workspace entries sized by hygiene reports.
	 */
	public const HYGIENE_DEFAULT_SIZE_LIMIT = 1000;

	/**
	 * @var string Resolved workspace path.
	 */
	private string $workspace_path;

	public function __construct() {
		$this->workspace_path = self::resolve_workspace_directory();
	}

	/**
	 * Resolve the remote default branch ref for local cleanup checks.
	 *
	 * @param  string $primary_path     Path to the primary git checkout.
	 * @param  int    $timeout_seconds Optional timeout in seconds.
	 * @return string|\WP_Error|null Fully-qualified remote default ref, timeout error, or null when unavailable.
	 */
	protected function resolve_remote_default_ref( string $primary_path, int $timeout_seconds = 0 ): string|\WP_Error|null {
		$result = $this->run_git($primary_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD', $timeout_seconds);
		if ( is_wp_error($result) ) {
			return $this->is_git_timeout_error($result) ? $result : null;
		}

		$ref = trim( (string) ( $result['output'] ?? '' ));
		return '' === $ref ? null : $ref;
	}
}
