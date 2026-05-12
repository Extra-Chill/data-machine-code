<?php
/**
 * Agent Workspace
 *
 * Provides a managed directory for agent file operations — cloning repos,
 * storing working files, etc. Lives outside the web root when possible
 * for security.
 *
 * @package DataMachineCode\Workspace
 * @since 0.31.0
 */

namespace DataMachineCode\Workspace;

use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;
use DataMachineCode\Storage\WorktreeInventoryRepository;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/WorkspaceArtifactCleanup.php';
require_once __DIR__ . '/WorkspaceCleanupPlan.php';
require_once __DIR__ . '/WorkspaceGitOperations.php';
require_once __DIR__ . '/WorkspaceHygieneReport.php';
require_once __DIR__ . '/WorkspaceMetadataReconciliation.php';
require_once __DIR__ . '/WorkspaceRepositoryLifecycle.php';
require_once __DIR__ . '/WorkspaceWorktreeLifecycle.php';
require_once __DIR__ . '/WorkspaceWorktreeInventoryCleanup.php';
require_once __DIR__ . '/WorkspaceWorktreeEmergencyCleanup.php';

class Workspace {

	use WorkspaceArtifactCleanup;
	use WorkspaceCleanupPlan;
	use WorkspaceGitOperations;
	use WorkspaceHygieneReport;
	use WorkspaceMetadataReconciliation;
	use WorkspaceRepositoryLifecycle;
	use WorkspaceWorktreeLifecycle;
	use WorkspaceWorktreeInventoryCleanup;
	use WorkspaceWorktreeEmergencyCleanup;

	/**
	 * Maximum file size for reading (1 MB).
	 */
	const MAX_READ_SIZE = 1048576;

	/**
	 * Bound GitHub cleanup checks so one slow repo cannot stall cleanup.
	 */
	private const CLEANUP_GITHUB_TIMEOUT = 5;

	/**
	 * Bound per-worktree git cleanup probes so one wedged checkout cannot stall cleanup.
	 */
	private const CLEANUP_GIT_PROBE_TIMEOUT = 5;

	/**
	 * Closed PR pages to inspect per repo during cleanup.
	 */
	private const CLEANUP_GITHUB_MAX_PAGES = 3;

	/**
	 * Number of largest/oldest rows to expose in cleanup summaries.
	 */
	private const CLEANUP_SUMMARY_TOP_LIMIT = 10;

	/**
	 * Default number of workspace entries to size in hygiene reports.
	 */
	private const HYGIENE_DEFAULT_SIZE_LIMIT = 1000;

	/**
	 * Default cap on worktrees scanned for an artifact cleanup dry-run when no
	 * `limit` is provided. Keeps dry-run bounded and fast on huge workspaces.
	 */
	public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;

	/**
	 * Default metadata reconciliation dry-run page size when pagination is used.
	 */
	private const METADATA_RECONCILE_DEFAULT_LIMIT = 100;

	/**
	 * @var string Resolved workspace path.
	 */
	private string $workspace_path;

	public function __construct() {
		$this->workspace_path = self::resolve_workspace_directory();
	}

	/**
	 * Worktree inventory repository factory.
	 */
	private function worktree_inventory(): WorktreeInventoryRepository {
		if ( ! class_exists( WorktreeInventoryRepository::class ) ) {
			require_once dirname( __DIR__ ) . '/Storage/WorktreeInventoryRepository.php';
		}

		return new WorktreeInventoryRepository();
	}

	/**
	 * Resolve the workspace directory path.
	 *
	 * Priority:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable — typical on VPS)
	 * 3. $HOME/.datamachine/workspace (local/macOS fallback)
	 * 4. sys_get_temp_dir()/datamachine/workspace (ephemeral CI/Playground fallback)
	 * 5. Empty string (no workspace available)
	 *
	 * @return string Workspace path or empty string if unavailable.
	 */
	private static function resolve_workspace_directory(): string {
		if ( defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
			return rtrim( DATAMACHINE_WORKSPACE_PATH, '/' );
		}

		$system_path   = '/var/lib/datamachine/workspace';
		$system_base   = dirname( $system_path );
		$fs            = FilesystemHelper::get();
		$base_writable = $fs
			? $fs->is_writable( $system_base )
			: is_writable( $system_base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable

		$parent_writable = ! $base_writable && ! file_exists( $system_base ) && (
			$fs
				? $fs->is_writable( dirname( $system_base ) )
				: is_writable( dirname( $system_base ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);

		if ( $base_writable || $parent_writable ) {
			return $system_path;
		}

		// Local/macOS fallback: $HOME/.datamachine/workspace.
		// Matches the path setup.sh uses in --local mode.
		$home = getenv( 'HOME' );
		if ( false !== $home && '' !== $home ) {
			$home_path = rtrim( $home, '/' ) . '/.datamachine/workspace';
			$home_base = dirname( $home_path );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( is_dir( $home_base ) && is_writable( $home_base ) ) {
				return $home_path;
			}

			// Base doesn't exist yet — check if $HOME/.datamachine can be created.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! file_exists( $home_base ) && is_writable( dirname( $home_base ) ) ) {
				return $home_path;
			}
		}

		$temp_path = rtrim( sys_get_temp_dir(), '/' ) . '/datamachine/workspace';
		$temp_base = dirname( $temp_path );
		$web_root  = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		$temp_root = realpath( sys_get_temp_dir() );

		if (
			false !== $temp_root &&
			( false === $web_root || 0 !== strpos( $temp_root . '/', rtrim( $web_root, '/' ) . '/' ) )
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( is_dir( $temp_base ) && is_writable( $temp_base ) ) {
				return $temp_path;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! file_exists( $temp_base ) && is_writable( dirname( $temp_base ) ) ) {
				return $temp_path;
			}
		}

		return '';
	}

	/**
	 * Get the workspace base path.
	 *
	 * @return string
	 */
	public function get_path(): string {
		return $this->workspace_path;
	}

	/**
	 * Inspect whether the configured workspace root is visible to PHP.
	 *
	 * @return array{path: string, configured: bool, is_dir: bool, is_readable: bool, scandir: string, readable: bool}
	 */
	public function inspect_workspace_path(): array {
		$path        = $this->workspace_path;
		$is_dir      = '' !== $path && is_dir( $path );
		$is_readable = '' !== $path && is_readable( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
		$entries     = ( $is_dir && $is_readable ) ? scandir( $path ) : false;

		return array(
			'path'        => $path,
			'configured'  => defined( 'DATAMACHINE_WORKSPACE_PATH' ),
			'is_dir'      => $is_dir,
			'is_readable' => $is_readable,
			'scandir'     => false === $entries ? 'failed' : 'ok',
			'readable'    => $is_dir && false !== $entries,
		);
	}

	/**
	 * Require the configured workspace root to be visible before substrate checks.
	 *
	 * @return \WP_Error|null
	 */
	private function require_workspace_visible(): ?\WP_Error {
		$diagnostic = $this->inspect_workspace_path();

		if ( '' === $diagnostic['path'] ) {
			return new \WP_Error(
				'workspace_unavailable',
				'Workspace unavailable: no writable path outside the web root. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php, ensure /var/lib/datamachine/ is writable, ensure $HOME is set, or ensure the system temporary directory is writable.',
				$this->workspace_visibility_error_data( $diagnostic )
			);
		}

		if ( ! $diagnostic['is_dir'] ) {
			if ( empty( $diagnostic['configured'] ) ) {
				return null;
			}

			return new \WP_Error(
				'workspace_path_invisible',
				$this->format_workspace_visibility_message( $diagnostic ),
				$this->workspace_visibility_error_data( $diagnostic )
			);
		}

		if ( ! $diagnostic['readable'] ) {
			return new \WP_Error(
				'workspace_path_unreadable',
				$this->format_workspace_visibility_message( $diagnostic ),
				$this->workspace_visibility_error_data( $diagnostic )
			);
		}

		return null;
	}

	/**
	 * Build error data for workspace visibility failures.
	 *
	 * @param array<string,mixed> $diagnostic Path visibility details.
	 * @return array{status: int, workspace: array<string,mixed>}
	 */
	private function workspace_visibility_error_data( array $diagnostic ): array {
		return array(
			'status'    => 500,
			'workspace' => $diagnostic,
		);
	}

	/**
	 * Format a workspace visibility diagnostic for CLI/API callers.
	 *
	 * @param array<string,mixed> $diagnostic Path visibility details.
	 * @return string
	 */
	private function format_workspace_visibility_message( array $diagnostic ): string {
		return sprintf(
			'Workspace path is not accessible from PHP: %s (is_dir=%s, is_readable=%s, scandir=%s). If this is a Studio/local host path, ensure the path is mounted into the PHP runtime or update DATAMACHINE_WORKSPACE_PATH to a PHP-visible workspace.',
			(string) ( $diagnostic['path'] ?? '' ),
			! empty( $diagnostic['is_dir'] ) ? 'true' : 'false',
			! empty( $diagnostic['is_readable'] ) ? 'true' : 'false',
			(string) ( $diagnostic['scandir'] ?? 'failed' )
		);
	}

	/**
	 * Get the full path to a workspace handle.
	 *
	 * Handles can be either a primary checkout (`<repo>`) or a worktree
	 * (`<repo>@<branch-slug>`). The directory name on disk equals the handle.
	 *
	 * @param string $handle Workspace handle (`<repo>` or `<repo>@<branch-slug>`).
	 * @return string Full filesystem path.
	 */
	public function get_repo_path( string $handle ): string {
		$parsed = $this->parse_handle( $handle );
		return $this->workspace_path . '/' . $parsed['dir_name'];
	}

	/**
	 * Parse a workspace handle into its components.
	 *
	 * Accepts either:
	 *   - `<repo>`           → primary checkout
	 *   - `<repo>@<slug>`    → worktree (slug = slugified branch name)
	 *
	 * @param string $handle Workspace handle.
	 * @return array{repo: string, branch_slug: string|null, is_worktree: bool, dir_name: string}
	 */
	public function parse_handle( string $handle ): array {
		$handle = trim( $handle );

		if ( str_contains( $handle, '@' ) ) {
			$parts = explode( '@', $handle, 2 );
			$repo  = $this->sanitize_name( $parts[0] );
			$slug  = $this->sanitize_slug( $parts[1] );

			if ( '' !== $repo && '' !== $slug ) {
				return array(
					'repo'        => $repo,
					'branch_slug' => $slug,
					'is_worktree' => true,
					'dir_name'    => $repo . '@' . $slug,
				);
			}
		}

		$repo = $this->sanitize_name( $handle );

		return array(
			'repo'        => $repo,
			'branch_slug' => null,
			'is_worktree' => false,
			'dir_name'    => $repo,
		);
	}

	/**
	 * Convert a branch name to a filesystem-safe slug.
	 *
	 * Slashes become dashes (`fix/foo-bar` → `fix-foo-bar`). Anything else
	 * outside [A-Za-z0-9._-] is stripped.
	 *
	 * @param string $branch Branch name.
	 * @return string Slug (empty if branch is invalid).
	 */
	public function slugify_branch( string $branch ): string {
		$branch = trim( $branch );
		if ( '' === $branch ) {
			return '';
		}

		$slug = str_replace( '/', '-', $branch );
		return $this->sanitize_slug( $slug );
	}

	/**
	 * Sanitize a branch slug. Allows alphanumerics, dots, dashes, underscores.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private function sanitize_slug( string $slug ): string {
		$slug = preg_replace( '/[^a-zA-Z0-9._-]/', '', $slug );
		// Collapse runs of dashes for readability.
		$slug = preg_replace( '/-{2,}/', '-', (string) $slug );
		return trim( (string) $slug, '-.' );
	}

	/**
	 * Get the primary checkout path for a repo.
	 *
	 * @param string $repo Repository name (no @-suffix).
	 * @return string
	 */
	public function get_primary_path( string $repo ): string {
		return $this->workspace_path . '/' . $this->sanitize_name( $repo );
	}

	/**
	 * Ensure the workspace directory exists with correct permissions.
	 *
	 * @return array{success: bool, path: string, created?: bool}|\WP_Error
	 */
	public function ensure_exists(): array|\WP_Error {
		$path = $this->workspace_path;

		if ( '' === $path ) {
			$visible = $this->require_workspace_visible();
			return null !== $visible ? $visible : new \WP_Error( 'workspace_unavailable', 'Workspace unavailable: no writable path outside the web root.', array( 'status' => 500 ) );
		}

		if ( is_dir( $path ) ) {
			$visible = $this->require_workspace_visible();
			if ( null !== $visible ) {
				return $visible;
			}

			return array(
				'success' => true,
				'path'    => $path,
				'created' => false,
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$created = wp_mkdir_p( $path );

		if ( ! $created ) {
			return new \WP_Error( 'workspace_create_failed', sprintf( 'Failed to create workspace directory: %s', $path ), array( 'status' => 500 ) );
		}

		// Set permissions for multi-user access (web server group).
		$this->ensure_group_permissions( $path );

		// Add .htaccess to block web access if inside web root.
		$this->protect_directory( $path );

		return array(
			'success' => true,
			'path'    => $path,
			'created' => true,
		);
	}

	// =========================================================================
	// Worktree operations
	// =========================================================================

	/**
	 * Cleanup merged worktrees across all primary checkouts.
	 *
	 * Scans all worktrees, consults upstream tracking + GitHub PR state,
	 * and (unless dry-run) removes worktrees whose work is already merged
	 * to the remote default branch. Also deletes the local branch and
	 * prunes the git registry afterwards.
	 *
	 * A worktree is considered prunable when ALL of:
	 *   - It is not the primary checkout
	 *   - Its branch is not main/master/trunk/develop/HEAD
	 *   - It has no uncommitted changes (unless $force)
	 *   - At least one merge signal is present:
	 *       a) `git for-each-ref` reports upstream status "gone" (branch
	 *          was deleted on the remote — typical after GitHub
	 *          "auto-delete head branches" fires on PR merge), OR
	 *       b) GitHub API reports a closed+merged PR whose head
	 *          branch matches this worktree's branch.
	 *
	 * Signal (a) is local and fast; signal (b) requires a PAT + network
	 * but catches the case where the remote branch still exists (e.g.
	 * manual merge without branch deletion).
	 *
	 * @param array $opts {
	 *     @type bool $dry_run If true, return the plan without removing anything.
	 *     @type bool $force   If true, ignore dirty working-tree safety.
	 *     @type bool $skip_github If true, only use upstream-gone signal (no API calls).
	 *     @type string $older_than Optional duration such as 7d, 24h, or 30m. Candidates newer than this are skipped.
	 * }
	 * @return array{
	 *     success: bool,
	 *     dry_run: bool,
	 *     candidates: array<int,array>,
	 *     removed: array<int,array>,
	 *     skipped: array<int,array>,
	 * }|\WP_Error
	 */
	public function worktree_cleanup_merged( array $opts = array() ): array|\WP_Error {
		$dry_run        = ! empty( $opts['dry_run'] );
		$force          = ! empty( $opts['force'] );
		$skip_github    = ! empty( $opts['skip_github'] );
		$inventory_only = ! empty( $opts['inventory_only'] );
		$include_repaired_metadata = ! empty( $opts['include_repaired_metadata'] );
		$apply_plan     = isset( $opts['apply_plan'] ) && is_array( $opts['apply_plan'] ) ? $opts['apply_plan'] : null;
		$older_than     = isset( $opts['older_than'] ) ? trim( (string) $opts['older_than'] ) : '';
		$sort           = isset( $opts['sort'] ) ? trim( (string) $opts['sort'] ) : '';
		$progress       = isset( $opts['progress_callback'] ) && is_callable( $opts['progress_callback'] ) ? $opts['progress_callback'] : null;
		$started_at     = microtime( true );
		$limit          = array_key_exists( 'limit', $opts ) ? max( 1, (int) $opts['limit'] ) : null;
		$offset         = array_key_exists( 'offset', $opts ) ? max( 0, (int) $opts['offset'] ) : 0;
		$budget_context = null;
		$budget_stopped = false;

		if ( isset( $opts['until_budget'] ) && '' !== trim( (string) $opts['until_budget'] ) ) {
			if ( ! $dry_run ) {
				return new \WP_Error( 'cleanup_budget_requires_dry_run', 'Budgeted cleanup is review-only. Use --dry-run with --until-budget, then apply a reviewed cleanup path.', array( 'status' => 400 ) );
			}

			$budget_seconds = $this->parse_worktree_metadata_reconciliation_budget( trim( (string) $opts['until_budget'] ) );
			if ( is_wp_error( $budget_seconds ) ) {
				return $budget_seconds;
			}
			$budget_context = $this->build_worktree_loop_budget_context( $opts, $started_at );
		}

		if ( ( null !== $limit || $offset > 0 ) && ! $dry_run ) {
			return new \WP_Error( 'cleanup_pagination_requires_dry_run', 'Paginated cleanup is review-only. Use --dry-run with --limit/--offset, then apply a reviewed cleanup path.', array( 'status' => 400 ) );
		}

		if ( '' !== $sort && ! in_array( $sort, array( 'size', 'age' ), true ) ) {
			return new \WP_Error( 'invalid_cleanup_sort', 'Invalid cleanup sort. Use size or age.', array( 'status' => 400 ) );
		}

		if ( $inventory_only ) {
			if ( ! $dry_run ) {
				return new \WP_Error( 'inventory_cleanup_requires_dry_run', 'Inventory-only cleanup is review-only. Run workspace worktree bounded-cleanup-eligible-apply to apply the same bounded cleanup-eligible class. Broader merge-signal cleanup is a separate, explicit review path.', array( 'status' => 400 ) );
			}
			if ( null !== $apply_plan ) {
				return new \WP_Error( 'inventory_cleanup_apply_plan_unsupported', 'Inventory-only cleanup cannot apply a plan because it intentionally skips full safety revalidation.', array( 'status' => 400 ) );
			}

			return $this->worktree_cleanup_inventory_only( $older_than, $sort, $include_repaired_metadata );
		}

		$planned_candidates = null;
		if ( null !== $apply_plan ) {
			$planned_candidates = $this->extract_worktree_cleanup_plan_candidates( $apply_plan );
			if ( is_wp_error( $planned_candidates ) ) {
				return $planned_candidates;
			}

			// Applying a stale plan must never use the dirty override. The current
			// workspace state is re-evaluated below and dirty rows stay skipped.
			$force = false;
		}

		$age_filter = null;
		if ( '' !== $older_than ) {
			$duration_seconds = $this->parse_worktree_cleanup_duration( $older_than );
			if ( is_wp_error( $duration_seconds ) ) {
				return $duration_seconds;
			}

			$threshold_ts = time() - $duration_seconds;
			$age_filter   = array(
				'type'             => 'older_than',
				'older_than'       => $older_than,
				'duration_seconds' => $duration_seconds,
				'threshold'        => gmdate( 'c', $threshold_ts ),
				'threshold_unix'   => $threshold_ts,
				'excluded'         => 0,
				'unknown_age'      => 0,
			);
		}

		$listing = $this->worktree_list(
			null,
			null,
			array(
				'include_status' => false,
				'include_disk'   => false,
			)
		);
		if ( $listing instanceof \WP_Error ) {
			return $listing;
		}

		$protected_branches = array( 'main', 'master', 'trunk', 'develop', 'HEAD' );
		$candidates         = array();
		$skipped            = array();
		$github_cache       = array();
		$all_worktrees      = array_values( array_filter( (array) $listing['worktrees'], fn( $wt ) => empty( $wt['is_primary'] ) ) );
		$total_worktrees    = count( $all_worktrees );
		$worktrees          = array_slice( $all_worktrees, $offset, $limit );
		$checked            = 0;
		$processed          = 0;
		$removed_count      = 0;

		$this->emit_worktree_cleanup_progress( $progress, 'start', '', $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at );

		// Fetch + prune each primary once per repo, but keep status/disk probes inside
		// the row loop so budgeted dry-runs can return partial evidence promptly.
		$fetched = array();
		$fetch_timeouts = array();

		foreach ( $worktrees as $wt ) {
			if ( null !== $budget_context && $this->is_worktree_loop_budget_exhausted( $budget_context ) ) {
				$budget_stopped = true;
				break;
			}

			++$processed;
			$handle       = $wt['handle'] ?? '?';
			$repo         = $wt['repo'] ?? '';
			$branch       = $wt['branch'] ?? '';
			$wt_path      = $wt['path'] ?? '';
			$metadata     = $wt['metadata'] ?? null;
			$created_at   = $wt['created_at'] ?? null;
			$disk_fields  = $this->extract_worktree_disk_fields( $wt );
			$primary_path = '' !== $repo ? $this->get_primary_path( $repo ) : '';

			if ( ! empty( $wt['external'] ) ) {
				$skipped[] = array_merge( array(
					'handle'       => $handle,
					'reason_code'  => 'external_worktree',
					'reason'       => 'external worktree (outside workspace)',
					'hint'         => 'External worktree outside the DMC workspace; remove with the owning tool or inspect with git worktree list from the primary repo.',
					'repo'         => $repo,
					'owning_repo'  => $repo,
					'branch'       => $branch,
					'path'         => $wt_path,
					'primary_path' => $primary_path,
					'created_at'   => $created_at,
					'metadata'     => $metadata,
				), $disk_fields );
				continue;
			}

			++$checked;
			$this->emit_worktree_cleanup_progress( $progress, 'checking', (string) $handle, $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at );

			if ( '' === $repo || '' === $branch || '' === $wt_path ) {
				$missing_fields = array();
				foreach (
					array(
						'repo'   => $repo,
						'branch' => $branch,
						'path'   => $wt_path,
					) as $field => $value
				) {
					if ( '' === $value ) {
						$missing_fields[] = $field;
					}
				}
				$skipped[] = array_merge( array(
					'handle'         => $handle,
					'repo'           => $repo,
					'branch'         => $branch,
					'path'           => $wt_path,
					'reason_code'    => 'missing_metadata',
					'reason'         => 'missing repo/branch/path',
					'missing_fields' => $missing_fields,
					'hint'           => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
					'created_at'     => $created_at,
					'metadata'       => $metadata,
				), $disk_fields );
				continue;
			}

			if ( in_array( $branch, $protected_branches, true ) ) {
				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'protected_branch',
					'reason'      => sprintf( 'protected branch (%s)', $branch ),
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			$dirty_probe = $this->probe_worktree_dirty_count( $wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
			if ( is_wp_error( $dirty_probe ) ) {
				$skipped[] = $this->build_worktree_probe_timeout_skip( $handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $dirty_probe );
				continue;
			}
			$dirty_count = (int) $dirty_probe;

			if ( $dirty_count > 0 && ! $force ) {
				// Before falling through to the generic dirty_worktree skip, try to
				// classify whether this is the "merged PR + obsolete dirty edits"
				// shape. That bucket is still skipped (force=false stays safe), but
				// the distinct reason_code lets reviewers spot it as a safe
				// force-cleanup candidate without manual archaeology.
				$obsolete_dirty = $this->classify_dirty_obsolete_on_default_branch(
					$repo,
					$branch,
					$wt_path,
					$skip_github,
					$github_cache,
					$fetched,
					$fetch_timeouts,
					$metadata,
					$include_repaired_metadata
				);

				if ( is_array( $obsolete_dirty ) ) {
					$skipped[] = array_merge( array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'merged_pr_with_only_obsolete_dirty_changes',
						'reason'      => sprintf(
							'merged branch with %d dirty file(s); all dirty paths already absent on default branch — safe force-cleanup candidate (review then rerun with force=true)',
							$dirty_count
						),
						'dirty'       => $dirty_count,
						'dirty_obsolete_paths' => $obsolete_dirty['paths'],
						'merge_signal' => $obsolete_dirty['merge_signal'],
						'pr_url'      => $obsolete_dirty['pr_url'] ?? null,
						'default_ref' => $obsolete_dirty['default_ref'] ?? null,
						'hint'        => 'Dirty edits only touch paths the default branch no longer has. After review, rerun cleanup with force=true to remove this worktree.',
						'created_at'  => $created_at,
						'metadata'    => $metadata,
					), $disk_fields );
					continue;
				}

				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'dirty_worktree',
					'reason'      => sprintf( 'working tree dirty (%d files) — pass force=true to override', $dirty_count ),
					'dirty'       => $dirty_count,
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			// Hard stop: worktrees with local commits the remote hasn't seen.
			// Upstream may be "gone" because a human manually deleted the
			// remote branch without merging — nuking the worktree would lose
			// those commits. `force` does NOT override this: data loss is a
			// harder problem than dirty-file loss, and this guard is cheap.
			$unpushed = $this->count_unpushed_commits( $wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
			if ( is_wp_error( $unpushed ) ) {
				$skipped[] = $this->build_worktree_probe_timeout_skip( $handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $unpushed );
				continue;
			}
			if ( $unpushed > 0 ) {
				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'unpushed_commits',
					'reason'      => sprintf( '%d unpushed commit(s) — refusing to delete even with force (push or reset explicitly)', $unpushed ),
					'unpushed'    => $unpushed,
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			$primary_path = $this->get_primary_path( $repo );
			if ( ! is_dir( $primary_path . '/.git' ) ) {
				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'missing_metadata',
					'reason'      => 'primary checkout missing',
					'hint'        => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			if ( isset( $fetch_timeouts[ $repo ] ) ) {
				$skipped[] = $this->build_worktree_probe_timeout_skip( $handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $fetch_timeouts[ $repo ] );
				continue;
			}

			if ( empty( $fetched[ $repo ] ) ) {
				$fetch = $this->run_git( $primary_path, 'fetch --prune --quiet origin', self::CLEANUP_GIT_PROBE_TIMEOUT );
				if ( $this->is_git_timeout_error( $fetch ) ) {
					$fetch_timeouts[ $repo ] = $fetch;
					$skipped[]              = $this->build_worktree_probe_timeout_skip( $handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $fetch );
					continue;
				}
				$fetched[ $repo ] = true;
			}

			$signal = null;
			if ( is_array( $metadata ) && WorktreeContextInjector::has_cleanup_signal( $metadata ) ) {
				$signal = array(
					'signal' => 'cleanup_eligible',
					'reason' => 'worktree finalized or explicitly marked cleanup_eligible',
				);
				if ( ! empty( $metadata['pr_url'] ) ) {
					$signal['pr_url'] = (string) $metadata['pr_url'];
				}
			} elseif ( $include_repaired_metadata && is_array( $metadata ) && ! empty( $metadata['metadata_repaired'] ) ) {
				$signal = array(
					'signal' => 'repaired_metadata',
					'reason' => 'operator-approved cleanup of repaired metadata',
				);
			} else {
				$signal = $this->detect_merge_signal( $primary_path, $repo, $branch, $skip_github, $github_cache );
			}
			if ( null === $signal ) {
				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'no_merge_signal',
					'reason'      => 'no merge signal — leaving in place',
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			if ( 'probe-timeout' === ( $signal['signal'] ?? '' ) ) {
				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'probe_timeout',
					'reason'      => $signal['reason'],
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			if ( 'github-unknown' === ( $signal['signal'] ?? '' ) ) {
				$skipped[] = array_merge( array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'github_unknown',
					'reason'      => $signal['reason'],
					'created_at'  => $created_at,
					'metadata'    => $metadata,
				), $disk_fields );
				continue;
			}

			$age_decision = null;
			if ( null !== $age_filter ) {
				$created_ts = is_string( $created_at ) && '' !== $created_at ? strtotime( $created_at ) : false;
				if ( false === $created_ts ) {
					++$age_filter['unknown_age'];
					$skipped[] = array_merge( array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'unknown_age',
						'reason'      => 'missing or invalid created_at metadata - age filter cannot decide safely',
						'created_at'  => $created_at,
						'metadata'    => $metadata,
						'age_filter'  => array(
							'type'       => 'older_than',
							'older_than' => $age_filter['older_than'],
							'threshold'  => $age_filter['threshold'],
							'decision'   => 'unknown_age',
						),
					), $disk_fields );
					continue;
				}

				$age_seconds  = time() - $created_ts;
				$age_decision = array(
					'type'        => 'older_than',
					'older_than'  => $age_filter['older_than'],
					'threshold'   => $age_filter['threshold'],
					'created_at'  => $created_at,
					'age_seconds' => $age_seconds,
				);

				if ( $created_ts > $age_filter['threshold_unix'] ) {
					++$age_filter['excluded'];
					$skipped[] = array_merge( array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'age_filter',
						'reason'      => sprintf( 'created_at %s is newer than --older-than=%s threshold %s', $created_at, $age_filter['older_than'], $age_filter['threshold'] ),
						'created_at'  => $created_at,
						'metadata'    => $metadata,
						'age_filter'  => array_merge( $age_decision, array( 'decision' => 'excluded' ) ),
					), $disk_fields );
					continue;
				}

				$age_decision['decision'] = 'included';
			}

			$candidate = array_merge( array(
				'handle'      => $wt['handle'],
				'repo'        => $repo,
				'branch'      => $branch,
				'path'        => $wt_path,
				'dirty'       => $dirty_count,
				'signal'      => $signal['signal'],
				'reason_code' => $signal['signal'],
				'reason'      => $signal['reason'],
				'pr_url'      => $signal['pr_url'] ?? null,
				'created_at'  => $created_at,
				'metadata'    => $metadata,
			), $disk_fields );
			if ( null !== $age_decision ) {
				$candidate['age_filter'] = $age_decision;
			}
			$candidates[] = $candidate;
		}

		$candidates = $this->sort_worktree_cleanup_rows( $candidates, $sort );

		if ( null !== $planned_candidates ) {
			$scoped     = $this->scope_worktree_cleanup_to_plan( $planned_candidates, $candidates, $skipped );
			$candidates = $scoped['candidates'];
			$skipped    = $scoped['skipped'];
		}

		$summary = $this->build_worktree_cleanup_summary( $candidates, array(), $skipped, $age_filter );
		$pagination = $this->build_worktree_cleanup_pagination( $offset, $limit, $processed, $total_worktrees, $budget_stopped, $budget_context );
		if ( null !== $pagination ) {
			$summary['pagination'] = $pagination;
		}

		if ( $dry_run ) {
			$this->emit_worktree_cleanup_progress( $progress, 'done', '', $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at );

			$result = array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => $candidates,
				'removed'    => array(),
				'skipped'    => $skipped,
				'summary'    => $summary,
			);
			if ( null !== $pagination ) {
				$result['pagination'] = $pagination;
			}
			if ( null !== $budget_context ) {
				$result['evidence'] = array(
					'elapsed_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'budget'     => $this->summarize_worktree_loop_budget_context( $budget_context, $budget_stopped ),
				);
			}

			return $result;
		}

		$removed = array();

		foreach ( $candidates as $cand ) {
			$this->emit_worktree_cleanup_progress( $progress, 'removing', (string) ( $cand['handle'] ?? '' ), $checked, count( $worktrees ), $candidates, $skipped, $removed_count, $started_at );
			$remove = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$cand['repo'],
				function () use ( $cand, $force ) {
					$remove = $this->remove_worktree_by_path( $cand['repo'], $cand['branch'], $cand['path'], $force );
					if ( is_wp_error( $remove ) ) {
						return $remove;
					}

					// Delete the now-detached local branch while the repo lock still covers
					// shared git metadata.
					$primary_path = $this->get_primary_path( $cand['repo'] );
					$branch       = $this->run_git( $primary_path, sprintf( 'branch -D %s', escapeshellarg( $cand['branch'] ) ) );
					return is_wp_error( $branch ) ? $branch : $remove;
				}
			);
			if ( is_wp_error( $remove ) ) {
				$skipped[] = array(
					'handle'      => $cand['handle'],
					'repo'        => $cand['repo'] ?? '',
					'branch'      => $cand['branch'] ?? '',
					'path'        => $cand['path'] ?? '',
					'reason_code' => 'remove_failed',
					'reason'      => 'remove failed: ' . $remove->get_error_message(),
					'created_at'  => $cand['created_at'] ?? null,
					'metadata'    => $cand['metadata'] ?? null,
					'size_bytes'  => $cand['size_bytes'] ?? null,
				);
				continue;
			}
			$removed[] = $cand;
			++$removed_count;
		}

		// Final sweep to drop any remaining registry entries.
		$this->worktree_prune();

		$this->emit_worktree_cleanup_progress( $progress, 'done', '', $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at );

		return array(
			'success'    => true,
			'dry_run'    => false,
			'candidates' => $candidates,
			'removed'    => $removed,
			'skipped'    => $skipped,
			'summary'    => $this->build_worktree_cleanup_summary( $candidates, $removed, $skipped, $age_filter ),
		);
	}

	/**
	 * Build pagination evidence for bounded full cleanup dry-runs.
	 *
	 * @param int        $offset         Inventory offset for this page.
	 * @param int|null   $limit          Optional page size.
	 * @param int        $processed      Rows consumed from this page.
	 * @param int        $total          Total non-primary worktrees.
	 * @param bool       $budget_stopped Whether the budget stopped the scan early.
	 * @param array|null $budget_context Optional budget context.
	 * @return array<string,mixed>|null
	 */
	private function build_worktree_cleanup_pagination( int $offset, ?int $limit, int $processed, int $total, bool $budget_stopped, ?array $budget_context ): ?array {
		if ( 0 === $offset && null === $limit && null === $budget_context ) {
			return null;
		}

		$next_offset = ( $offset + $processed ) < $total ? $offset + $processed : null;
		$command     = null;
		if ( null !== $next_offset ) {
			$parts = array(
				'studio wp datamachine-code workspace worktree cleanup --dry-run',
				null === $limit ? null : '--limit=' . $limit,
				'--offset=' . $next_offset,
				null === $budget_context ? null : '--until-budget=' . (string) ( $budget_context['label'] ?? '' ),
				'--format=json',
			);
			$command = implode( ' ', array_values( array_filter( $parts, fn( $part ) => null !== $part && '' !== $part ) ) );
		}

		return array(
			'total'          => $total,
			'offset'         => $offset,
			'limit'          => $limit,
			'scanned'        => $processed,
			'partial'        => null !== $next_offset,
			'complete'       => null === $next_offset,
			'next_offset'    => $next_offset,
			'next_command'   => $command,
			'budget_stopped' => $budget_stopped,
		);
	}

	/**
	 * Emit a bounded cleanup progress event for human CLI callers.
	 *
	 * @param callable|null $callback      Progress callback.
	 * @param string        $event         Event name.
	 * @param string        $handle        Current worktree handle.
	 * @param int           $checked       Checked worktree count.
	 * @param int           $total         Total worktree count.
	 * @param array         $candidates    Candidate rows.
	 * @param array         $skipped       Skipped rows.
	 * @param int           $removed_count Removed count.
	 * @param float         $started_at    Start timestamp from microtime(true).
	 * @return void
	 */
	private function emit_worktree_cleanup_progress( ?callable $callback, string $event, string $handle, int $checked, int $total, array $candidates, array $skipped, int $removed_count, float $started_at ): void {
		if ( null === $callback ) {
			return;
		}

		$callback(
			array(
				'event'      => $event,
				'handle'     => $handle,
				'checked'    => $checked,
				'total'      => $total,
				'candidates' => count( $candidates ),
				'skipped'    => count( $skipped ),
				'removed'    => $removed_count,
				'elapsed'    => round( max( 0, microtime( true ) - $started_at ), 1 ),
			)
		);
	}

	/**
	 * Build a standard cleanup skip row for timed-out safety probes.
	 *
	 * @param string    $handle     Worktree handle.
	 * @param string    $repo       Repo name.
	 * @param string    $branch     Branch name.
	 * @param string    $path       Worktree path.
	 * @param mixed     $created_at Created timestamp.
	 * @param mixed     $metadata   Worktree metadata.
	 * @param array     $disk_fields Disk summary fields.
	 * @param \WP_Error $error      Probe error.
	 * @return array<string,mixed>
	 */
	private function build_worktree_probe_timeout_skip( string $handle, string $repo, string $branch, string $path, mixed $created_at, mixed $metadata, array $disk_fields, \WP_Error $error ): array {
		return array_merge(
			array(
				'handle'      => $handle,
				'repo'        => $repo,
				'branch'      => $branch,
				'path'        => $path,
				'reason_code' => 'probe_timeout',
				'reason'      => 'cleanup safety probe timed out - leaving in place: ' . $error->get_error_message(),
				'created_at'  => $created_at,
				'metadata'    => $metadata,
			),
			$disk_fields
		);
	}

	/**
	 * Operator-safe bounded cleanup apply restricted to explicit cleanup-eligible worktrees.
	 *
	 * Skips the slow paths (full git worktree discovery, GitHub lookups, full
	 * status/upstream sweep) and works only off cheap workspace inventory rows
	 * with explicit `cleanup_eligible` lifecycle metadata. Each candidate is
	 * revalidated at apply time — dirty/unpushed/missing-metadata/external/
	 * primary rows stay skipped even when the inventory said they were eligible.
	 *
	 * Bounds:
	 *   - `limit` caps how many candidates this batch will attempt (default 25).
	 *   - `older_than` optionally filters by lifecycle `created_at` age.
	 *   - `via_jobs` schedules each candidate as its own `worktree_cleanup_chunk`
	 *     job (single-row chunks) for resumable, async apply. Synchronous mode
	 *     is the default so operators can apply now without an Action Scheduler
	 *     run between batch and result.
	 *
	 * Evidence:
	 *   - `processed`, `removed`, `skipped`, `bytes_reclaimed`.
	 *   - `continuation` reports remaining cleanup-eligible handles not in this
	 *     batch so the operator can keep going (next call without changes
	 *     re-derives the same list cheaply).
	 *
	 * @param array $opts Options: dry_run, limit, older_than, sort, force, via_jobs, source.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_bounded_cleanup_eligible_apply( array $opts = array() ): array|\WP_Error {
		$started_at = microtime( true );
		$dry_run    = ! empty( $opts['dry_run'] );
		$force      = ! empty( $opts['force'] );
		$via_jobs   = ! empty( $opts['via_jobs'] );
		$include_repaired_metadata = ! empty( $opts['include_repaired_metadata'] );
		$older_than = isset( $opts['older_than'] ) ? trim( (string) $opts['older_than'] ) : '';
		$sort       = isset( $opts['sort'] ) ? trim( (string) $opts['sort'] ) : 'age';
		$source     = isset( $opts['source'] ) ? trim( (string) $opts['source'] ) : 'workspace_bounded_cleanup_eligible_apply';

		$limit = isset( $opts['limit'] ) ? (int) $opts['limit'] : 25;
		if ( $limit < 1 ) {
			return new \WP_Error( 'invalid_bounded_cleanup_eligible_limit', 'Bounded cleanup-eligible apply limit must be a positive integer.', array( 'status' => 400 ) );
		}
		// Hard ceiling keeps a single bounded batch genuinely bounded — operators
		// who want more should run multiple batches or fall through to the full
		// retention/job-backed path.
		$limit = min( $limit, 200 );

		if ( '' !== $sort && ! in_array( $sort, array( 'size', 'age' ), true ) ) {
			return new \WP_Error( 'invalid_cleanup_sort', 'Invalid bounded cleanup-eligible apply sort. Use size or age.', array( 'status' => 400 ) );
		}

		if ( $via_jobs && $dry_run ) {
			return new \WP_Error( 'bounded_cleanup_eligible_apply_via_jobs_no_dry_run', 'Job-backed bounded cleanup-eligible apply cannot run as dry-run; use the synchronous dry-run path to review candidates first.', array( 'status' => 400 ) );
		}

		// Reuse the cheap inventory_only review path for candidate discovery so
		// the bounded cleanup-eligible apply never triggers full worktree_list / fetch / GitHub
		// API work just to plan. This intentionally does not honor `apply_plan`
		// — bounded cleanup-eligible apply IS the apply path; no separate plan file is needed.
		$inventory = $this->worktree_cleanup_inventory_only( $older_than, $sort, $include_repaired_metadata );
		if ( $inventory instanceof \WP_Error ) {
			return $inventory;
		}

		$all_candidates = array_values( (array) ( $inventory['candidates'] ?? array() ) );
		$inventory_skipped = array_values( (array) ( $inventory['skipped'] ?? array() ) );

		$batch     = array_slice( $all_candidates, 0, $limit );
		$deferred  = array_slice( $all_candidates, $limit );

		$continuation = array(
			'remaining_total'      => count( $deferred ),
			'remaining_handles'    => array_values( array_filter( array_map( fn( $row ) => is_array( $row ) ? (string) ( $row['handle'] ?? '' ) : '', $deferred ) ) ),
			'next_call_hint'       => count( $deferred ) > 0 ? sprintf( 'Run the bounded cleanup-eligible apply again to drain the next %d candidate(s).', min( $limit, count( $deferred ) ) ) : null,
			'inventory_skipped'    => count( $inventory_skipped ),
			'limit_applied'        => $limit,
		);

		if ( $dry_run ) {
			return array(
				'success'         => true,
				'mode'            => 'bounded_cleanup_eligible_apply',
				'dry_run'         => true,
				'destructive'     => false,
				'workspace_path'  => $this->workspace_path,
				'generated_at'    => gmdate( 'c' ),
				'candidates'      => $batch,
				'removed'         => array(),
				'skipped'         => array_values( $inventory_skipped ),
				'summary'         => array(
					'processed'       => count( $batch ),
					'removed'         => 0,
					'skipped'         => count( $inventory_skipped ),
					'bytes_reclaimed' => 0,
					'limit'           => $limit,
				),
				'continuation'    => $continuation,
				'evidence'        => array(
					'elapsed_ms'        => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'inventory_total'   => count( $all_candidates ),
					'planned_handles'   => array_values( array_filter( array_map( fn( $row ) => is_array( $row ) ? (string) ( $row['handle'] ?? '' ) : '', $batch ) ) ),
					'source'            => $source,
				),
			);
		}

		if ( $via_jobs ) {
			return $this->schedule_bounded_cleanup_eligible_chunks( $batch, $deferred, $force, $source, $started_at, $continuation, $include_repaired_metadata );
		}

		$processed       = 0;
		$removed         = array();
		$skipped         = array_values( $inventory_skipped );
		$bytes_reclaimed = 0;

		foreach ( $batch as $candidate ) {
			++$processed;
			$revalidated = $this->revalidate_bounded_cleanup_eligible_candidate( $candidate, $force );
			if ( is_array( $revalidated ) && isset( $revalidated['skipped'] ) ) {
				$skipped[] = $revalidated['skipped'];
				continue;
			}
			if ( $revalidated instanceof \WP_Error ) {
				$skipped[] = array(
					'handle'      => (string) ( $candidate['handle'] ?? '' ),
					'repo'        => (string) ( $candidate['repo'] ?? '' ),
					'branch'      => (string) ( $candidate['branch'] ?? '' ),
					'path'        => (string) ( $candidate['path'] ?? '' ),
					'reason_code' => $revalidated->get_error_code(),
					'reason'      => $revalidated->get_error_message(),
				);
				continue;
			}

			$validated = $revalidated;
			$repo      = (string) ( $validated['repo'] ?? '' );
			$branch    = (string) ( $validated['branch'] ?? '' );
			$wt_path   = (string) ( $validated['path'] ?? '' );
			$size      = (int) ( $validated['size_bytes'] ?? 0 );
			if ( $size <= 0 ) {
				$measured = $this->estimate_path_size_bytes( $wt_path );
				$size     = null === $measured ? 0 : (int) $measured;
			}

			$remove = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$repo,
				function () use ( $repo, $branch, $wt_path, $force ) {
					$result = $this->remove_worktree_by_path( $repo, $branch, $wt_path, $force );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$primary_path = $this->get_primary_path( $repo );
					if ( '' !== $branch ) {
						$delete = $this->run_git( $primary_path, sprintf( 'branch -D %s', escapeshellarg( $branch ) ) );
						if ( is_wp_error( $delete ) ) {
							// Branch deletion failure is non-fatal: the worktree is
							// gone, the local branch may still be useful or may have
							// already been pruned. Bubble up the original removal.
							return $result;
						}
					}

					return $result;
				}
			);

			if ( is_wp_error( $remove ) ) {
				$skipped[] = array(
					'handle'      => (string) ( $candidate['handle'] ?? '' ),
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'remove_failed',
					'reason'      => 'remove failed: ' . $remove->get_error_message(),
				);
				continue;
			}

			$removed[]        = array_merge(
				array(
					'handle'      => (string) ( $candidate['handle'] ?? '' ),
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'size_bytes'  => $size,
					'reason_code' => 'cleanup_eligible',
				),
				is_array( $candidate['metadata'] ?? null ) ? array( 'metadata' => $candidate['metadata'] ) : array()
			);
			$bytes_reclaimed += max( 0, $size );
		}

		// Best-effort prune in case any registry rows are now stale.
		$this->worktree_prune();

		return array(
			'success'         => true,
			'mode'            => 'bounded_cleanup_eligible_apply',
			'dry_run'         => false,
			'destructive'     => true,
			'workspace_path'  => $this->workspace_path,
			'generated_at'    => gmdate( 'c' ),
			'candidates'      => $batch,
			'removed'         => $removed,
			'skipped'         => $skipped,
			'summary'         => array(
				'processed'       => $processed,
				'removed'         => count( $removed ),
				'skipped'         => count( $skipped ),
				'bytes_reclaimed' => $bytes_reclaimed,
				'limit'           => $limit,
			),
			'continuation'    => $continuation,
			'evidence'        => array(
				'elapsed_ms'      => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'inventory_total' => count( $all_candidates ),
				'removed_handles' => array_values( array_filter( array_map( fn( $row ) => is_array( $row ) ? (string) ( $row['handle'] ?? '' ) : '', $removed ) ) ),
				'skipped_handles' => array_values( array_filter( array_map( fn( $row ) => is_array( $row ) ? (string) ( $row['handle'] ?? '' ) : '', $skipped ) ) ),
				'source'          => $source,
			),
		);
	}

	/**
	 * Build a bounded evidence report for active/no-signal worktrees.
	 *
	 * This is review-only. It never deletes worktrees or branches; it gathers the
	 * facts needed to separate live work from abandoned branches before a later,
	 * explicit cleanup action.
	 *
	 * @param array<string,mixed> $opts Report options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_report( array $opts = array() ): array|\WP_Error {
		$started_at = microtime( true );
		$limit  = array_key_exists( 'limit', $opts ) ? max( 1, (int) $opts['limit'] ) : 25;
		$offset = array_key_exists( 'offset', $opts ) ? max( 0, (int) $opts['offset'] ) : 0;
		if ( isset( $opts['until_budget'] ) && '' !== trim( (string) $opts['until_budget'] ) ) {
			$budget_seconds = $this->parse_worktree_metadata_reconciliation_budget( trim( (string) $opts['until_budget'] ) );
			if ( is_wp_error( $budget_seconds ) ) {
				return $budget_seconds;
			}
		}

		$inventory = $this->worktree_cleanup_inventory_only( '', '', false );
		if ( is_wp_error( $inventory ) ) {
			return $inventory;
		}

		$active = array_values(
			array_filter(
				(array) ( $inventory['skipped'] ?? array() ),
				fn( $row ) => is_array( $row ) && in_array( (string) ( $row['reason_code'] ?? '' ), array( 'active_no_signal', 'no_inventory_cleanup_signal' ), true )
			)
		);
		$total  = count( $active );
		$page   = array_slice( $active, $offset, $limit );

		$github_cache = array();
		$rows         = array();
		$summary      = array(
			'total_active_no_signal' => $total,
			'inspected'              => 0,
			'by_suggested_action'    => array(),
			'dirty_or_unpushed'      => 0,
			'with_pr'                => 0,
			'without_pr'             => 0,
		);

		$budget_context = $this->build_worktree_loop_budget_context( $opts, $started_at );
		$budget_stopped = false;
		foreach ( $page as $index => $row ) {
			if ( null !== $budget_context && $this->is_worktree_loop_budget_exhausted( $budget_context ) ) {
				$budget_stopped = true;
				$page = array_slice( $page, 0, $index );
				break;
			}
			$row_started = microtime( true );
			$evidence = $this->build_active_no_signal_evidence_row( $row, $github_cache );
			$evidence['elapsed_ms'] = (int) round( ( microtime( true ) - $row_started ) * 1000 );
			$rows[]   = $evidence;
			++$summary['inspected'];

			$action = (string) ( $evidence['suggested_action'] ?? 'insufficient_signal' );
			$summary['by_suggested_action'][ $action ] = (int) ( $summary['by_suggested_action'][ $action ] ?? 0 ) + 1;
			if ( (int) ( $evidence['dirty'] ?? 0 ) > 0 || (int) ( $evidence['unpushed'] ?? 0 ) > 0 ) {
				++$summary['dirty_or_unpushed'];
			}
			if ( ! empty( $evidence['pr']['number'] ) ) {
				++$summary['with_pr'];
			} else {
				++$summary['without_pr'];
			}
		}

		$next_offset = ( $offset + count( $page ) ) < $total ? $offset + count( $page ) : null;
		$pagination  = array(
			'total'        => $total,
			'offset'       => $offset,
			'limit'        => $limit,
			'scanned'      => count( $page ),
			'partial'      => null !== $next_offset,
			'complete'     => null === $next_offset,
			'next_offset'  => $next_offset,
			'next_command' => null === $next_offset ? null : sprintf( 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=%d --offset=%d%s --format=json', $limit, $next_offset, null !== $budget_context ? ' --until-budget=' . (string) $budget_context['label'] : '' ),
		);
		if ( $budget_stopped ) {
			$pagination['partial']  = true;
			$pagination['complete'] = false;
		}

		return array(
			'success'    => true,
			'mode'       => 'active_no_signal_report',
			'review_only' => true,
			'generated_at' => gmdate( 'c' ),
			'rows'       => $rows,
			'summary'    => array_merge( $summary, array( 'slow_rows' => $this->summarize_slow_worktree_rows( $rows ) ) ),
			'pagination' => $pagination,
			'evidence'   => array(
				'scope' => 'review-only active_no_signal worktree lifecycle evidence',
				'safety' => 'No worktrees or remote branches are deleted. Dirty and unpushed probes are evidence only.',
				'budget' => null === $budget_context ? null : $this->summarize_worktree_loop_budget_context( $budget_context, $budget_stopped ),
			),
		);
	}

	/**
	 * Promote finalized PR evidence from the active/no-signal report into cleanup metadata.
	 *
	 * This writes lifecycle metadata only. It never removes worktrees; callers use
	 * bounded cleanup-eligible apply after reviewing the written rows.
	 *
	 * @param array<string,mixed> $opts Apply options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_finalized_apply( array $opts = array() ): array|\WP_Error {
		$dry_run = ! empty( $opts['dry_run'] );
		$report  = $this->worktree_active_no_signal_report( $opts );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$written = array();
		$skipped = array();
		$planned = array();

		foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( 'finalized_pr_reconcile' !== (string) ( $row['suggested_action'] ?? '' ) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip( $row, 'not_finalized_pr', 'row is not a finalized merged PR candidate' );
				continue;
			}

			$metadata = $this->build_active_no_signal_finalized_metadata( $row );
			if ( is_wp_error( $metadata ) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip( $row, $metadata->get_error_code(), $metadata->get_error_message() );
				continue;
			}

			$planned[] = array(
				'handle'   => (string) ( $row['handle'] ?? '' ),
				'repo'     => (string) ( $row['repo'] ?? '' ),
				'branch'   => (string) ( $row['branch'] ?? '' ),
				'path'     => (string) ( $row['path'] ?? '' ),
				'pr'       => $row['pr'] ?? null,
				'metadata' => $metadata,
			);

			if ( $dry_run ) {
				continue;
			}

			$handle = (string) ( $row['handle'] ?? '' );
			WorktreeContextInjector::store_lifecycle_metadata( $handle, $metadata );
			$written[] = array(
				'handle'   => $handle,
				'repo'     => (string) ( $row['repo'] ?? '' ),
				'branch'   => (string) ( $row['branch'] ?? '' ),
				'path'     => (string) ( $row['path'] ?? '' ),
				'metadata' => WorktreeContextInjector::get_metadata( $handle ),
			);
		}

		$summary = array(
			'inspected'           => (int) ( $report['summary']['inspected'] ?? 0 ),
			'planned'             => count( $planned ),
			'written'             => count( $written ),
			'skipped'             => count( $skipped ),
			'skipped_by_reason'   => array(),
			'report_action_counts' => $report['summary']['by_suggested_action'] ?? array(),
		);
		foreach ( $skipped as $skip ) {
			$reason = (string) ( $skip['reason_code'] ?? 'unknown' );
			$summary['skipped_by_reason'][ $reason ] = (int) ( $summary['skipped_by_reason'][ $reason ] ?? 0 ) + 1;
		}

		return array(
			'success'      => true,
			'mode'         => 'active_no_signal_finalized_apply',
			'dry_run'      => $dry_run,
			'applied'      => ! $dry_run,
			'destructive'  => false,
			'generated_at' => gmdate( 'c' ),
			'planned'      => $planned,
			'written'      => $written,
			'skipped'      => $skipped,
			'summary'      => $summary,
			'pagination'   => $report['pagination'] ?? array(),
			'evidence'     => array(
				'scope' => 'promote finalized active_no_signal PR evidence into cleanup_eligible metadata',
				'safety' => 'Revalidates dirty, unpushed, identity, and closed+merged PR evidence before writing metadata. Does not delete worktrees.',
			),
		);
	}

	/**
	 * Promote effectively clean upstream-equivalent active/no-signal rows into cleanup metadata.
	 *
	 * This writes lifecycle metadata only. It never removes worktrees; callers use
	 * bounded cleanup-eligible apply after reviewing the written rows.
	 *
	 * @param array<string,mixed> $opts Apply options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_equivalent_clean_apply( array $opts = array() ): array|\WP_Error {
		$dry_run = ! empty( $opts['dry_run'] );
		$report  = $this->worktree_active_no_signal_report( $opts );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$written = array();
		$skipped = array();
		$planned = array();

		foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$effective_status = (string) ( $row['upstream_equivalence']['effective_status'] ?? '' );
			if ( 'equivalent_clean' !== $effective_status ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip( $row, 'not_equivalent_clean', 'row is not effectively clean upstream-equivalent work' );
				continue;
			}

			$metadata = $this->build_active_no_signal_equivalent_clean_metadata( $row );
			if ( is_wp_error( $metadata ) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip( $row, $metadata->get_error_code(), $metadata->get_error_message() );
				continue;
			}

			$planned[] = array(
				'handle'               => (string) ( $row['handle'] ?? '' ),
				'repo'                 => (string) ( $row['repo'] ?? '' ),
				'branch'               => (string) ( $row['branch'] ?? '' ),
				'path'                 => (string) ( $row['path'] ?? '' ),
				'upstream_equivalence' => $metadata['cleanup_eligibility_evidence']['upstream_equivalence'] ?? null,
				'metadata'             => $metadata,
			);

			if ( $dry_run ) {
				continue;
			}

			$handle = (string) ( $row['handle'] ?? '' );
			WorktreeContextInjector::store_lifecycle_metadata( $handle, $metadata );
			$written[] = array(
				'handle'   => $handle,
				'repo'     => (string) ( $row['repo'] ?? '' ),
				'branch'   => (string) ( $row['branch'] ?? '' ),
				'path'     => (string) ( $row['path'] ?? '' ),
				'metadata' => WorktreeContextInjector::get_metadata( $handle ),
			);
		}

		$summary = array(
			'inspected'            => (int) ( $report['summary']['inspected'] ?? 0 ),
			'planned'              => count( $planned ),
			'written'              => count( $written ),
			'skipped'              => count( $skipped ),
			'skipped_by_reason'    => array(),
			'report_action_counts' => $report['summary']['by_suggested_action'] ?? array(),
		);
		foreach ( $skipped as $skip ) {
			$reason = (string) ( $skip['reason_code'] ?? 'unknown' );
			$summary['skipped_by_reason'][ $reason ] = (int) ( $summary['skipped_by_reason'][ $reason ] ?? 0 ) + 1;
		}

		return array(
			'success'      => true,
			'mode'         => 'active_no_signal_equivalent_clean_apply',
			'dry_run'      => $dry_run,
			'applied'      => ! $dry_run,
			'destructive'  => false,
			'generated_at' => gmdate( 'c' ),
			'planned'      => $planned,
			'written'      => $written,
			'skipped'      => $skipped,
			'summary'      => $summary,
			'pagination'   => $report['pagination'] ?? array(),
			'evidence'     => array(
				'scope' => 'promote effectively clean upstream-equivalent active_no_signal rows into cleanup_eligible metadata',
				'safety' => 'Revalidates upstream-equivalence evidence before writing metadata. Does not delete worktrees.',
			),
		);
	}

	/**
	 * Build cleanup metadata from one finalized active/no-signal evidence row.
	 *
	 * @param array<string,mixed> $row Evidence row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_active_no_signal_finalized_metadata( array $row ): array|\WP_Error {
		$handle = (string) ( $row['handle'] ?? '' );
		$repo   = (string) ( $row['repo'] ?? '' );
		$branch = (string) ( $row['branch'] ?? '' );
		$path   = (string) ( $row['path'] ?? '' );
		$pr     = is_array( $row['pr'] ?? null ) ? $row['pr'] : array();

		foreach (
			array(
				'handle' => $handle,
				'repo'   => $repo,
				'branch' => $branch,
				'path'   => $path,
			) as $field => $value
		) {
			if ( '' === $value ) {
				return new \WP_Error( 'missing_identity', 'missing required identity field: ' . $field );
			}
		}
		if ( ! is_dir( $path ) ) {
			return new \WP_Error( 'missing_worktree', 'worktree path no longer exists' );
		}

		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path . '/.git' ) ) {
			return new \WP_Error( 'missing_primary', 'primary checkout missing' );
		}

		$dirty = $this->probe_worktree_dirty_count( $path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( is_wp_error( $dirty ) ) {
			return $dirty;
		}
		$unpushed = $this->count_unpushed_commits( $path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( is_wp_error( $unpushed ) ) {
			return $unpushed;
		}
		if ( (int) $dirty > 0 || (int) $unpushed > 0 ) {
			return new \WP_Error( 'unsafe_dirty_or_unpushed', 'refusing to mark dirty or unpushed worktree cleanup_eligible from active/no-signal evidence' );
		}

		$slug = $this->resolve_github_slug( $primary_path );
		if ( null === $slug ) {
			return new \WP_Error( 'missing_github_repo', 'primary checkout does not resolve to a GitHub repository' );
		}

		$github_cache = array();
		$current_pr   = $this->find_pr_for_branch_direct( $slug, $branch, $github_cache, true );
		if ( is_wp_error( $current_pr ) ) {
			return $current_pr;
		}
		if ( ! is_array( $current_pr ) || empty( $current_pr['merged_at'] ) ) {
			return new \WP_Error( 'missing_finalized_pr', 'exact branch-head PR is not currently closed and merged' );
		}

		$pr_url = (string) ( $current_pr['html_url'] ?? ( $pr['html_url'] ?? '' ) );
		if ( '' === $pr_url ) {
			return new \WP_Error( 'missing_pr_url', 'merged PR evidence is missing html_url' );
		}

		$base_metadata = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();
		$metadata      = array_merge(
			$base_metadata,
			array(
				'handle'       => $handle,
				'repo'         => $repo,
				'branch'       => $branch,
				'path'         => $path,
				'observed_at'  => gmdate( 'c' ),
				'last_seen_at' => gmdate( 'c' ),
			),
			WorktreeContextInjector::build_finalizer_metadata( WorktreeContextInjector::STATE_MERGED, $pr_url )
		);
		$metadata['auto_finalized_by']            = 'active_no_signal_finalized_apply';
		$metadata['auto_finalized_signal']        = 'pr-merged';
		$metadata['auto_finalized_reason']        = sprintf( 'active/no-signal report found merged PR #%d', (int) ( $current_pr['number'] ?? 0 ) );
		$metadata['cleanup_eligibility_evidence'] = array_filter(
			array(
				'signal'          => 'pr-merged',
				'finalized_state' => WorktreeContextInjector::STATE_MERGED,
				'reason'          => 'exact branch-head PR is closed and merged',
				'detected_at'     => gmdate( 'c' ),
				'dirty'           => (int) $dirty,
				'unpushed'        => (int) $unpushed,
				'pr_url'          => $pr_url,
				'pr_number'       => (int) ( $current_pr['number'] ?? 0 ),
			),
			fn( $value ) => null !== $value && '' !== $value
		);

		return $metadata;
	}

	/**
	 * Build cleanup metadata from one effectively clean upstream-equivalent row.
	 *
	 * @param array<string,mixed> $row Evidence row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_active_no_signal_equivalent_clean_metadata( array $row ): array|\WP_Error {
		$handle = (string) ( $row['handle'] ?? '' );
		$repo   = (string) ( $row['repo'] ?? '' );
		$branch = (string) ( $row['branch'] ?? '' );
		$path   = (string) ( $row['path'] ?? '' );

		foreach (
			array(
				'handle' => $handle,
				'repo'   => $repo,
				'branch' => $branch,
				'path'   => $path,
			) as $field => $value
		) {
			if ( '' === $value ) {
				return new \WP_Error( 'missing_identity', 'missing required identity field: ' . $field );
			}
		}
		if ( ! is_dir( $path ) ) {
			return new \WP_Error( 'missing_worktree', 'worktree path no longer exists' );
		}

		$equivalence = $this->build_current_effective_clean_cleanup_evidence( $repo, $path );
		if ( is_wp_error( $equivalence ) ) {
			return $equivalence;
		}

		$base_metadata = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();
		$metadata      = array_merge(
			$base_metadata,
			array(
				'handle'       => $handle,
				'repo'         => $repo,
				'branch'       => $branch,
				'path'         => $path,
				'observed_at'  => gmdate( 'c' ),
				'last_seen_at' => gmdate( 'c' ),
			),
			WorktreeContextInjector::build_finalizer_metadata( WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE )
		);
		$metadata['auto_finalized_by']            = 'active_no_signal_equivalent_clean_apply';
		$metadata['auto_finalized_signal']        = 'upstream-equivalent-clean';
		$metadata['auto_finalized_reason']        = 'active/no-signal report found patch-equivalent upstream work with no source-like dirty paths';
		$metadata['cleanup_eligibility_evidence'] = array(
			'signal'               => 'upstream-equivalent-clean',
			'finalized_state'      => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
			'reason'               => 'unpushed commits are patch-equivalent to default and dirty paths are clean against default',
			'detected_at'          => gmdate( 'c' ),
			'dirty'                => (int) ( $equivalence['dirty'] ?? 0 ),
			'unpushed'             => (int) ( $equivalence['unpushed'] ?? 0 ),
			'upstream_equivalence' => $equivalence['upstream_equivalence'] ?? array(),
		);

		return $metadata;
	}

	/**
	 * Recompute effective-clean evidence for the current worktree state.
	 *
	 * @param string $repo    Repository name.
	 * @param string $wt_path Worktree path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_current_effective_clean_cleanup_evidence( string $repo, string $wt_path ): array|\WP_Error {
		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path . '/.git' ) ) {
			return new \WP_Error( 'missing_primary', 'primary checkout missing' );
		}

		$dirty = $this->probe_worktree_dirty_count( $wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( is_wp_error( $dirty ) ) {
			return $dirty;
		}
		$unpushed = $this->count_unpushed_commits( $wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( is_wp_error( $unpushed ) ) {
			return $unpushed;
		}
		if ( 0 === (int) $dirty && 0 === (int) $unpushed ) {
			return new \WP_Error( 'no_dirty_or_unpushed_signal', 'worktree no longer has dirty or unpushed evidence requiring upstream-equivalent cleanup handling' );
		}

		$default_ref = $this->resolve_remote_default_ref( $primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( ! is_string( $default_ref ) || '' === $default_ref ) {
			return new \WP_Error( 'missing_default_ref', 'primary checkout default ref could not be resolved' );
		}

		$upstream_equivalence = $this->build_dirty_unpushed_upstream_equivalence_evidence( $primary_path, $wt_path, $default_ref );
		if ( 'equivalent_clean' !== (string) ( $upstream_equivalence['effective_status'] ?? '' ) ) {
			return new \WP_Error( 'not_equivalent_clean', 'current worktree evidence is not effectively clean upstream-equivalent' );
		}

		return array(
			'dirty'                => (int) $dirty,
			'unpushed'             => (int) $unpushed,
			'upstream_equivalence' => $upstream_equivalence,
		);
	}

	/**
	 * Build a skip row for finalized active/no-signal apply.
	 *
	 * @param array<string,mixed> $row         Evidence row.
	 * @param string              $reason_code Stable reason code.
	 * @param string              $reason      Human-readable reason.
	 * @return array<string,mixed>
	 */
	private function build_active_no_signal_finalized_apply_skip( array $row, string $reason_code, string $reason ): array {
		return array(
			'handle'      => (string) ( $row['handle'] ?? '' ),
			'repo'        => (string) ( $row['repo'] ?? '' ),
			'branch'      => (string) ( $row['branch'] ?? '' ),
			'path'        => (string) ( $row['path'] ?? '' ),
			'reason_code' => $reason_code,
			'reason'      => $reason,
			'action'      => (string) ( $row['suggested_action'] ?? '' ),
		);
	}

	/**
	 * Build one active/no-signal evidence row.
	 *
	 * @param array<string,mixed> $row          Inventory skip row.
	 * @param array<string,mixed> $github_cache Run-local GitHub cache.
	 * @return array<string,mixed>
	 */
	private function build_active_no_signal_evidence_row( array $row, array &$github_cache ): array {
		$handle       = (string) ( $row['handle'] ?? '' );
		$repo         = (string) ( $row['repo'] ?? '' );
		$branch       = (string) ( $row['branch'] ?? '' );
		$path         = (string) ( $row['path'] ?? '' );
		$primary_path = '' !== $repo ? $this->get_primary_path( $repo ) : '';
		$metadata     = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();

		$out = array(
			'handle'           => $handle,
			'repo'             => $repo,
			'branch'           => $branch,
			'path'             => $path,
			'created_at'       => $row['created_at'] ?? null,
			'lifecycle_state'  => $metadata['lifecycle_state'] ?? null,
			'metadata'         => $metadata,
			'last_seen_at'     => $metadata['last_seen_at'] ?? ( $metadata['observed_at'] ?? null ),
			'dirty'            => null,
			'unpushed'         => null,
			'pr'               => null,
			'remote_tracking'  => null,
			'default_ref'      => null,
			'commits_outside_default' => null,
			'upstream_equivalence' => null,
			'probe_timings_ms' => array(),
			'suggested_action' => 'insufficient_signal',
			'reason'           => 'not enough evidence gathered',
		);

		if ( '' === $repo || '' === $branch || '' === $path || ! is_dir( $path ) || ! is_dir( $primary_path . '/.git' ) ) {
			$out['suggested_action'] = 'insufficient_signal';
			$out['reason']           = 'missing repo, branch, path, worktree, or primary checkout';
			return $out;
		}

		$dirty = $this->time_worktree_probe( $out['probe_timings_ms'], 'dirty_count', fn() => $this->probe_worktree_dirty_count( $path, self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		if ( is_wp_error( $dirty ) ) {
			$out['dirty_error'] = $dirty->get_error_message();
		} else {
			$out['dirty'] = (int) $dirty;
		}

		$unpushed = $this->time_worktree_probe( $out['probe_timings_ms'], 'unpushed_count', fn() => $this->count_unpushed_commits( $path, self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		if ( is_wp_error( $unpushed ) ) {
			$out['unpushed_error'] = $unpushed->get_error_message();
		} else {
			$out['unpushed'] = (int) $unpushed;
		}

		$remote_ref = 'refs/remotes/origin/' . $branch;
		$remote     = $this->time_worktree_probe( $out['probe_timings_ms'], 'remote_tracking', fn() => $this->run_git( $primary_path, sprintf( 'rev-parse --verify --quiet %s', escapeshellarg( $remote_ref ) ), self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		$out['remote_tracking'] = ! is_wp_error( $remote ) && ! $this->is_git_timeout_error( $remote );

		$default_ref = $this->time_worktree_probe( $out['probe_timings_ms'], 'default_ref', fn() => $this->resolve_remote_default_ref( $primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		if ( is_string( $default_ref ) && '' !== $default_ref ) {
			$out['default_ref'] = $default_ref;
			$outside = $this->time_worktree_probe( $out['probe_timings_ms'], 'commits_outside_default', fn() => $this->run_git(
				$primary_path,
				sprintf( 'rev-list --count %s..%s', escapeshellarg( $default_ref ), escapeshellarg( 'refs/heads/' . $branch ) ),
				self::CLEANUP_GIT_PROBE_TIMEOUT
			) );
			if ( ! is_wp_error( $outside ) && ! $this->is_git_timeout_error( $outside ) ) {
				$out['commits_outside_default'] = (int) trim( (string) ( $outside['output'] ?? '' ) );
			}

			if ( (int) ( $out['dirty'] ?? 0 ) > 0 || (int) ( $out['unpushed'] ?? 0 ) > 0 ) {
				$out['upstream_equivalence'] = $this->time_worktree_probe( $out['probe_timings_ms'], 'upstream_equivalence', fn() => $this->build_dirty_unpushed_upstream_equivalence_evidence( $primary_path, $path, $default_ref ) );
			}
		}

		if ( (int) ( $out['dirty'] ?? 0 ) > 0 || (int) ( $out['unpushed'] ?? 0 ) > 0 ) {
			$out['pr_lookup_skipped'] = 'dirty_or_unpushed_rows_are_always_manual_review';
		} else {
			$slug = $this->time_worktree_probe( $out['probe_timings_ms'], 'github_slug', fn() => $this->resolve_github_slug( $primary_path ) );
			if ( null !== $slug ) {
				$pr = $this->time_worktree_probe( $out['probe_timings_ms'], 'github_pr_lookup', fn() => $this->find_pr_for_branch_direct( $slug, $branch, $github_cache, false ) );
				if ( is_wp_error( $pr ) ) {
					$out['pr_error'] = $pr->get_error_message();
				} elseif ( is_array( $pr ) ) {
					$out['pr'] = $pr;
				}
			}
		}

		$out['suggested_action'] = $this->suggest_active_no_signal_action( $out );
		$out['reason']           = $this->describe_active_no_signal_action( $out );

		return $out;
	}

	/**
	 * Time one worktree probe and record elapsed milliseconds by label.
	 *
	 * @param array<string,int> $timings Timing accumulator.
	 * @param string            $label   Probe label.
	 * @param callable          $callback Probe callback.
	 * @return mixed
	 */
	private function time_worktree_probe( array &$timings, string $label, callable $callback ): mixed {
		$started = microtime( true );
		$result  = $callback();
		$timings[ $label ] = (int) round( ( microtime( true ) - $started ) * 1000 );
		return $result;
	}

	/**
	 * Build diagnostic evidence for dirty/unpushed worktrees against remote default.
	 *
	 * This is intentionally evidence-only. Cleanup still treats dirty files and
	 * unpushed commits as hard blockers until a separate reviewed apply path proves
	 * a safe subset.
	 *
	 * @param string $primary_path Primary checkout path.
	 * @param string $wt_path      Worktree path.
	 * @param string $default_ref  Remote default ref.
	 * @return array<string,mixed>
	 */
	private function build_dirty_unpushed_upstream_equivalence_evidence( string $primary_path, string $wt_path, string $default_ref ): array {
		$path_inspection_limit = 250;
		$evidence = array(
			'default_ref' => $default_ref,
			'effective_status' => 'unknown',
			'path_inspection_limit' => $path_inspection_limit,
			'path_inspection_truncated' => false,
			'unpushed_patch_equivalent' => null,
			'unpushed_cherry' => array(
				'equivalent' => 0,
				'unmatched'  => 0,
				'unknown'    => 0,
			),
			'dirty_paths' => array(
				'total'                => 0,
				'inspected'            => 0,
				'identical_to_default' => 0,
				'different_from_default' => 0,
				'absent_on_default'    => 0,
				'generated_or_artifact' => 0,
				'source_like'          => 0,
				'untracked'            => 0,
				'unknown'              => 0,
				'samples'              => array(),
			),
			'probe_timings_ms' => array(),
		);

		$cherry = $this->time_worktree_probe( $evidence['probe_timings_ms'], 'git_cherry', fn() => $this->run_git( $wt_path, sprintf( 'cherry %s HEAD', escapeshellarg( $default_ref ) ), self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		if ( ! is_wp_error( $cherry ) && ! $this->is_git_timeout_error( $cherry ) ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $cherry['output'] ?? '' ) ) ) ) );
			foreach ( $lines as $line ) {
				if ( str_starts_with( $line, '-' ) ) {
					++$evidence['unpushed_cherry']['equivalent'];
				} elseif ( str_starts_with( $line, '+' ) ) {
					++$evidence['unpushed_cherry']['unmatched'];
				} else {
					++$evidence['unpushed_cherry']['unknown'];
				}
			}
			$evidence['unpushed_patch_equivalent'] = 0 === (int) $evidence['unpushed_cherry']['unmatched'] && 0 === (int) $evidence['unpushed_cherry']['unknown'];
		}

		$tracked = $this->time_worktree_probe( $evidence['probe_timings_ms'], 'tracked_dirty_paths', fn() => $this->run_git( $wt_path, 'diff --name-only HEAD', self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		$paths   = array();
		if ( ! is_wp_error( $tracked ) && ! $this->is_git_timeout_error( $tracked ) ) {
			$paths = array_merge( $paths, array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $tracked['output'] ?? '' ) ) ) ) ) );
		}

		$untracked = $this->time_worktree_probe( $evidence['probe_timings_ms'], 'untracked_paths', fn() => $this->run_git( $wt_path, 'ls-files --others --exclude-standard', self::CLEANUP_GIT_PROBE_TIMEOUT ) );
		if ( ! is_wp_error( $untracked ) && ! $this->is_git_timeout_error( $untracked ) ) {
			foreach ( array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $untracked['output'] ?? '' ) ) ) ) ) as $path ) {
				$paths[] = $path;
				++$evidence['dirty_paths']['untracked'];
			}
		}

		$paths = array_values( array_unique( array_filter( $paths, fn( $path ) => '' !== (string) $path ) ) );
		$evidence['dirty_paths']['total'] = count( $paths );
		$inspect_paths = array_slice( $paths, 0, $path_inspection_limit );
		$evidence['dirty_paths']['inspected'] = count( $inspect_paths );
		$evidence['path_inspection_truncated'] = count( $paths ) > count( $inspect_paths );

		$classification_started = microtime( true );
		$classifications = $this->classify_dirty_paths_against_default( $primary_path, $wt_path, $default_ref, $inspect_paths );
		foreach ( $inspect_paths as $path ) {
			$classification = $classifications[ $path ] ?? $this->classify_dirty_path_against_default( $primary_path, $wt_path, $default_ref, $path );
			$bucket = $classification['bucket'];
			if ( isset( $evidence['dirty_paths'][ $bucket ] ) ) {
				++$evidence['dirty_paths'][ $bucket ];
			} else {
				++$evidence['dirty_paths']['unknown'];
			}
			$kind = (string) ( $classification['kind'] ?? 'source_like' );
			if ( 'generated_or_artifact' === $kind ) {
				++$evidence['dirty_paths']['generated_or_artifact'];
			} else {
				++$evidence['dirty_paths']['source_like'];
			}
			if ( count( $evidence['dirty_paths']['samples'] ) < 10 ) {
				$evidence['dirty_paths']['samples'][] = $classification;
			}
		}
		$evidence['probe_timings_ms']['dirty_path_classification'] = (int) round( ( microtime( true ) - $classification_started ) * 1000 );

		$evidence['effective_status'] = $this->classify_dirty_unpushed_effective_status( $evidence );

		return $evidence;
	}

	/**
	 * Classify dirty paths against the remote default branch with batched git probes.
	 *
	 * @param string            $primary_path Primary checkout path.
	 * @param string            $wt_path      Worktree path.
	 * @param string            $default_ref  Remote default ref.
	 * @param array<int,string> $paths        Repository-relative paths.
	 * @return array<string,array<string,string>> Classifications keyed by path.
	 */
	private function classify_dirty_paths_against_default( string $primary_path, string $wt_path, string $default_ref, array $paths ): array {
		$paths = array_values( array_unique( array_filter( array_map( 'strval', $paths ), fn( $path ) => '' !== $path ) ) );
		if ( array() === $paths ) {
			return array();
		}

		$path_args = implode( ' ', array_map( 'escapeshellarg', $paths ) );
		$existing = $this->run_git( $primary_path, sprintf( 'ls-tree -r --name-only %s -- %s', escapeshellarg( $default_ref ), $path_args ), self::CLEANUP_GIT_PROBE_TIMEOUT );
		$changed  = $this->run_git( $wt_path, sprintf( 'diff --name-only %s -- %s', escapeshellarg( $default_ref ), $path_args ), self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( is_wp_error( $existing ) || is_wp_error( $changed ) || $this->is_git_timeout_error( $existing ) || $this->is_git_timeout_error( $changed ) ) {
			return array();
		}

		$existing_set = array_fill_keys( array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $existing['output'] ?? '' ) ) ) ) ), true );
		$changed_set  = array_fill_keys( array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $changed['output'] ?? '' ) ) ) ) ), true );
		$out          = array();
		foreach ( $paths as $path ) {
			$kind = $this->is_generated_or_artifact_path( $path ) ? 'generated_or_artifact' : 'source_like';
			if ( ! isset( $existing_set[ $path ] ) ) {
				$out[ $path ] = array(
					'path'   => $path,
					'bucket' => 'absent_on_default',
					'kind'   => $kind,
				);
				continue;
			}

			$out[ $path ] = array(
				'path'   => $path,
				'bucket' => isset( $changed_set[ $path ] ) ? 'different_from_default' : 'identical_to_default',
				'kind'   => $kind,
			);
		}

		return $out;
	}

	/**
	 * Derive an operator-facing effective status for dirty/unpushed evidence.
	 *
	 * @param array<string,mixed> $evidence Upstream equivalence evidence.
	 * @return string
	 */
	private function classify_dirty_unpushed_effective_status( array $evidence ): string {
		if ( true !== ( $evidence['unpushed_patch_equivalent'] ?? null ) ) {
			return false === ( $evidence['unpushed_patch_equivalent'] ?? null ) ? 'not_equivalent' : 'unknown';
		}

		$dirty = (array) ( $evidence['dirty_paths'] ?? array() );
		if ( ! empty( $evidence['path_inspection_truncated'] ) || (int) ( $dirty['unknown'] ?? 0 ) > 0 ) {
			return 'unknown';
		}
		if ( 0 === (int) ( $dirty['total'] ?? 0 ) || (int) ( $dirty['total'] ?? 0 ) === (int) ( $dirty['identical_to_default'] ?? 0 ) ) {
			return 'equivalent_clean';
		}
		$meaningful = (int) ( $dirty['different_from_default'] ?? 0 ) + (int) ( $dirty['absent_on_default'] ?? 0 ) + (int) ( $dirty['untracked'] ?? 0 );
		if ( 0 < $meaningful && (int) ( $dirty['generated_or_artifact'] ?? 0 ) === $meaningful ) {
			return 'equivalent_generated_dirty';
		}
		if ( 0 < $meaningful && (int) ( $dirty['absent_on_default'] ?? 0 ) === $meaningful ) {
			return 'equivalent_obsolete_dirty';
		}

		return 'equivalent_but_dirty';
	}

	/**
	 * Classify one dirty path against the remote default branch.
	 *
	 * @param string $primary_path Primary checkout path.
	 * @param string $wt_path      Worktree path.
	 * @param string $default_ref  Remote default ref.
	 * @param string $path         Repository-relative path.
	 * @return array<string,string>
	 */
	private function classify_dirty_path_against_default( string $primary_path, string $wt_path, string $default_ref, string $path ): array {
		$kind = $this->is_generated_or_artifact_path( $path ) ? 'generated_or_artifact' : 'source_like';
		$exists = $this->run_git( $primary_path, sprintf( 'cat-file -e %s', escapeshellarg( $default_ref . ':' . $path ) ), self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( $this->is_git_timeout_error( $exists ) ) {
			return array(
				'path'   => $path,
				'bucket' => 'unknown',
				'kind'   => $kind,
				'reason' => $exists->get_error_message(),
			);
		}
		if ( is_wp_error( $exists ) ) {
			return array(
				'path'   => $path,
				'bucket' => 'absent_on_default',
				'kind'   => $kind,
			);
		}

		$diff = $this->run_git( $wt_path, sprintf( 'diff --name-only %s -- %s', escapeshellarg( $default_ref ), escapeshellarg( $path ) ), self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( $this->is_git_timeout_error( $diff ) ) {
			return array(
				'path'   => $path,
				'bucket' => 'unknown',
				'kind'   => $kind,
				'reason' => $diff->get_error_message(),
			);
		}
		if ( is_wp_error( $diff ) ) {
			return array(
				'path'   => $path,
				'bucket' => 'unknown',
				'kind'   => $kind,
				'reason' => $diff->get_error_message(),
			);
		}

		return array(
			'path'   => $path,
			'bucket' => '' === trim( (string) ( $diff['output'] ?? '' ) ) ? 'identical_to_default' : 'different_from_default',
			'kind'   => $kind,
		);
	}

	/**
	 * Check whether a path is a known generated, cache, dependency, or build artifact.
	 *
	 * @param string $path Repository-relative path.
	 * @return bool
	 */
	private function is_generated_or_artifact_path( string $path ): bool {
		$normalized = ltrim( str_replace( '\\', '/', $path ), '/' );
		$patterns = array(
			'#(^|/)node_modules/#',
			'#(^|/)vendor/#',
			'#(^|/)\.cache/#',
			'#(^|/)\.turbo/#',
			'#(^|/)\.next/#',
			'#(^|/)dist/#',
			'#(^|/)build/#',
			'#(^|/)coverage/#',
			'#(^|/)tmp/#',
			'#(^|/)temp/#',
			'#(^|/)logs?/#',
			'#(^|/)\.pytest_cache/#',
			'#(^|/)__pycache__/#',
			'#(^|/)\.DS_Store$#',
			'#\.(log|cache|tmp|map|min\.js|min\.css)$#',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Suggest a review action from active/no-signal evidence.
	 *
	 * @param array<string,mixed> $row Evidence row.
	 * @return string
	 */
	private function suggest_active_no_signal_action( array $row ): string {
		if ( (int) ( $row['dirty'] ?? 0 ) > 0 || (int) ( $row['unpushed'] ?? 0 ) > 0 ) {
			return 'unsafe_dirty_or_unpushed';
		}

		$pr = is_array( $row['pr'] ?? null ) ? $row['pr'] : array();
		if ( ! empty( $pr ) ) {
			if ( 'closed' === (string) ( $pr['state'] ?? '' ) ) {
				return ! empty( $pr['merged_at'] ) ? 'finalized_pr_reconcile' : 'closed_pr_reconcile';
			}
			return 'active_open_pr';
		}

		if ( 0 === (int) ( $row['commits_outside_default'] ?? -1 ) ) {
			return 'merged_to_default';
		}

		if ( null === ( $row['pr'] ?? null ) && empty( $row['pr_error'] ) ) {
			return 'no_pr_branch_review';
		}

		return 'insufficient_signal';
	}

	/**
	 * Human-readable explanation for active/no-signal action suggestions.
	 *
	 * @param array<string,mixed> $row Evidence row.
	 * @return string
	 */
	private function describe_active_no_signal_action( array $row ): string {
		return match ( (string) ( $row['suggested_action'] ?? '' ) ) {
			'unsafe_dirty_or_unpushed' => 'dirty files or unpushed commits require manual handling before cleanup',
			'finalized_pr_reconcile'   => 'exact branch-head PR lookup found a merged PR; metadata reconciliation should be able to mark cleanup_eligible',
			'closed_pr_reconcile'      => 'exact branch-head PR lookup found a closed PR; review before marking cleanup_eligible',
			'active_open_pr'           => 'exact branch-head PR lookup found an open PR',
			'merged_to_default'        => 'local branch has no commits outside the remote default ref',
			'no_pr_branch_review'      => 'no exact branch-head PR was found; review age/task context before cleanup',
			default                    => 'not enough evidence gathered',
		};
	}

	/**
	 * Re-run the bounded cleanup-eligible apply safety gates against the current state.
	 *
	 * Returns an enriched candidate row when the worktree is still safe to
	 * remove, or an array with a `skipped` row when a gate fires. Errors
	 * surface as WP_Error so callers can record a remove_failed-style row.
	 *
	 * Gates (cheap, in priority order):
	 *   - missing repo/branch/path metadata
	 *   - external worktree (path outside the workspace root)
	 *   - missing/non-directory worktree path
	 *   - .git marker is a directory (i.e. primary checkout — never remove)
	 *   - dirty working tree (porcelain --short)
	 *   - unpushed commits (count_unpushed_commits)
	 *   - missing primary checkout
	 *
	 * @param array $candidate Inventory candidate row.
	 * @param bool  $force     Allow dirty worktrees.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function revalidate_bounded_cleanup_eligible_candidate( array $candidate, bool $force ): array|\WP_Error {
		$handle  = (string) ( $candidate['handle'] ?? '' );
		$repo    = (string) ( $candidate['repo'] ?? '' );
		$branch  = (string) ( $candidate['branch'] ?? '' );
		$wt_path = (string) ( $candidate['path'] ?? '' );

		$missing_fields = array();
		foreach (
			array(
				'handle' => $handle,
				'repo'   => $repo,
				'branch' => $branch,
				'path'   => $wt_path,
			) as $field => $value
		) {
			if ( '' === $value ) {
				$missing_fields[] = $field;
			}
		}
		if ( array() !== $missing_fields ) {
			return array(
				'skipped' => array(
					'handle'         => $handle,
					'repo'           => $repo,
					'branch'         => $branch,
					'path'           => $wt_path,
					'reason_code'    => 'missing_metadata',
					'reason'         => 'missing inventory metadata for bounded cleanup-eligible apply: ' . implode( ', ', $missing_fields ),
					'missing_fields' => $missing_fields,
				),
			);
		}

		// Containment: path must be inside the workspace root and resolve as a
		// real worktree marker, not a primary's `.git` directory.
		$validation = $this->validate_containment( $wt_path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'external_worktree',
					'reason'      => 'inventory row is outside the workspace root and cannot be removed by bounded cleanup-eligible apply',
				),
			);
		}

		$real_path = (string) $validation['real_path'];
		if ( '' === $real_path || ! is_dir( $real_path ) ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'path_missing',
					'reason'      => 'worktree path no longer exists on disk',
				),
			);
		}

		$git_marker = rtrim( $real_path, '/' ) . '/.git';
		if ( is_dir( $git_marker ) ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'primary_checkout',
					'reason'      => 'refusing to remove a primary checkout via bounded cleanup-eligible apply',
				),
			);
		}
		if ( ! is_file( $git_marker ) ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'not_a_worktree',
					'reason'      => 'worktree marker missing — refusing to apply bounded cleanup',
				),
			);
		}

		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path . '/.git' ) ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'primary_missing',
					'reason'      => 'primary checkout missing — bounded cleanup-eligible apply cannot route git worktree remove',
				),
			);
		}

		// Dirty gate: cheap porcelain call, bounded by the cleanup git timeout.
		$dirty = $this->run_git( $real_path, 'status --porcelain --untracked-files=normal', self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( $this->is_git_timeout_error( $dirty ) ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'probe_timeout',
					'reason'      => 'dirty-state probe timed out — refusing bounded cleanup-eligible apply: ' . $dirty->get_error_message(),
				),
			);
		}
		if ( is_wp_error( $dirty ) ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'probe_failed',
					'reason'      => 'dirty-state probe failed — refusing bounded cleanup-eligible apply: ' . $dirty->get_error_message(),
				),
			);
		}

		$dirty_lines = trim( (string) ( $dirty['output'] ?? '' ) );
		$dirty_count = '' === $dirty_lines ? 0 : substr_count( $dirty_lines, "\n" ) + 1;
		// Unpushed gate: hard stop unless metadata was promoted by the reviewed
		// upstream-equivalent apply path and the same evidence still holds now.
		$unpushed = $this->count_unpushed_commits( $real_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( $unpushed instanceof \WP_Error ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'probe_timeout',
					'reason'      => 'unpushed-commit probe timed out — refusing bounded cleanup-eligible apply: ' . $unpushed->get_error_message(),
				),
			);
		}

		$metadata         = is_array( $candidate['metadata'] ?? null ) ? $candidate['metadata'] : array();
		$cleanup_evidence = is_array( $metadata['cleanup_eligibility_evidence'] ?? null ) ? $metadata['cleanup_eligibility_evidence'] : array();
		$allow_effective_clean_removal = false;
		if ( ( $dirty_count > 0 || $unpushed > 0 ) && 'upstream-equivalent-clean' === (string) ( $cleanup_evidence['signal'] ?? '' ) ) {
			$current_equivalence = $this->build_current_effective_clean_cleanup_evidence( $repo, $real_path );
			$allow_effective_clean_removal = ! is_wp_error( $current_equivalence );
		}

		if ( $dirty_count > 0 && ! $force && ! $allow_effective_clean_removal ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'dirty_worktree',
					'reason'      => sprintf( 'working tree dirty (%d entries) — bounded cleanup-eligible apply refuses to override; rerun with force=true after review', $dirty_count ),
					'dirty'       => $dirty_count,
				),
			);
		}

		if ( $unpushed > 0 && ! $allow_effective_clean_removal ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'unpushed_commits',
					'reason'      => sprintf( '%d unpushed commit(s) — bounded cleanup-eligible apply refuses to remove even with force=true', $unpushed ),
					'unpushed'    => $unpushed,
				),
			);
		}

		return array_merge( $candidate, array( 'path' => $real_path ) );
	}

	/**
	 * Schedule each bounded-cleanup-eligible-apply candidate as its own cleanup chunk job.
	 *
	 * Single-row chunks keep cleanup conservative and lock-friendly while the
	 * existing `worktree_cleanup_chunk` task handles per-row revalidation,
	 * removal, and evidence the same way as the retention path.
	 *
	 * @param array<int,array<string,mixed>> $batch         Candidate rows in this bounded batch.
	 * @param array<int,array<string,mixed>> $deferred      Candidates not in this batch.
	 * @param bool                            $force        Whether to forward force.
	 * @param string                          $source       Caller marker.
	 * @param float                           $started_at   Start timestamp.
	 * @param array<string,mixed>             $continuation Continuation envelope.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function schedule_bounded_cleanup_eligible_chunks( array $batch, array $deferred, bool $force, string $source, float $started_at, array $continuation, bool $include_repaired_metadata = false ): array|\WP_Error {
		if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskScheduler' ) ) {
			return new \WP_Error( 'task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable; cannot schedule bounded cleanup-eligible apply chunks.', array( 'status' => 500 ) );
		}

		if ( array() === $batch ) {
			return array(
				'success'         => true,
				'mode'            => 'bounded_cleanup_eligible_apply',
				'dry_run'         => false,
				'destructive'     => false,
				'job_backed'      => true,
				'workspace_path'  => $this->workspace_path,
				'generated_at'    => gmdate( 'c' ),
				'candidates'      => array(),
				'removed'         => array(),
				'skipped'         => array(),
				'summary'         => array(
					'processed'       => 0,
					'removed'         => 0,
					'skipped'         => 0,
					'bytes_reclaimed' => 0,
					'scheduled_jobs'  => 0,
				),
				'continuation'    => $continuation,
				'evidence'        => array(
					'elapsed_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'note'       => 'No bounded-cleanup-eligible-apply candidates eligible for scheduling.',
					'source'     => $source,
				),
			);
		}

		$item_params = array();
		foreach ( $batch as $candidate ) {
			$row = array(
				'handle' => (string) ( $candidate['handle'] ?? '' ),
				'repo'   => (string) ( $candidate['repo'] ?? '' ),
				'branch' => (string) ( $candidate['branch'] ?? '' ),
				'path'   => (string) ( $candidate['path'] ?? '' ),
				'signal' => (string) ( $candidate['signal'] ?? 'cleanup_eligible' ),
			);
			if ( isset( $candidate['size_bytes'] ) ) {
				$row['size_bytes'] = (int) $candidate['size_bytes'];
			}
			if ( isset( $candidate['metadata'] ) ) {
				$row['metadata'] = $candidate['metadata'];
			}
			$item_params[] = array(
				'chunk_type'  => 'worktrees',
				'chunk_index' => count( $item_params ),
				'rows'        => array( $row ),
				'force'       => $force,
				'skip_github' => true,
				'include_repaired_metadata' => $include_repaired_metadata,
				'source'      => $source,
			);
		}

		$batch_result = \DataMachine\Engine\Tasks\TaskScheduler::scheduleBatch(
			'worktree_cleanup_chunk',
			$item_params,
			array(
				'source' => $source,
			)
		);

		if ( false === $batch_result ) {
			return new \WP_Error( 'bounded_cleanup_eligible_apply_schedule_failed', 'Failed to schedule bounded cleanup-eligible apply chunk jobs.', array( 'status' => 500 ) );
		}

		return array(
			'success'         => true,
			'mode'            => 'bounded_cleanup_eligible_apply',
			'dry_run'         => false,
			'destructive'     => true,
			'job_backed'      => true,
			'workspace_path'  => $this->workspace_path,
			'generated_at'    => gmdate( 'c' ),
			'candidates'      => $batch,
			'removed'         => array(),
			'skipped'         => array(),
			'summary'         => array(
				'processed'       => count( $batch ),
				'removed'         => 0,
				'skipped'         => 0,
				'bytes_reclaimed' => 0,
				'scheduled_jobs'  => count( $item_params ),
			),
			'continuation'    => $continuation,
			'evidence'        => array(
				'elapsed_ms'      => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'planned_handles' => array_values( array_filter( array_map( fn( $row ) => is_array( $row ) ? (string) ( $row['handle'] ?? '' ) : '', $batch ) ) ),
				'batch_job_id'    => (int) ( $batch_result['batch_job_id'] ?? 0 ),
				'direct_job_ids'  => $batch_result['job_ids'] ?? array(),
				'source'          => $source,
			),
		);
	}

	/**
	 * Build stable cleanup counts for CLI and automation consumers.
	 *
	 * @param array<int,array> $candidates Candidate rows.
	 * @param array<int,array> $removed    Removed rows.
	 * @param array<int,array> $skipped    Skipped rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_cleanup_summary( array $candidates, array $removed, array $skipped, ?array $age_filter = null ): array {
		$skipped_by_reason    = array();
		$candidates_by_signal = array();
		$stale_reasons        = array();
		$liveness             = array();
		$size_by_repo         = array();
		$artifact_by_repo     = array();
		$total_size_bytes     = 0;
		$total_artifact_bytes = 0;
		$all_rows             = array_merge( $candidates, $removed, $skipped );

		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}

		foreach ( $candidates as $row ) {
			$signal                          = (string) ( $row['signal'] ?? $row['reason_code'] ?? 'unknown' );
			$candidates_by_signal[ $signal ] = ( $candidates_by_signal[ $signal ] ?? 0 ) + 1;
		}

		foreach ( $all_rows as $row ) {
			$repo           = (string) ( $row['repo'] ?? 'unknown' );
			$size_bytes     = isset( $row['size_bytes'] ) ? (int) $row['size_bytes'] : 0;
			$artifact_bytes = isset( $row['artifact_size_bytes'] ) ? (int) $row['artifact_size_bytes'] : 0;
			$stale_reason   = (string) ( $row['stale_reason'] ?? '' );
			$liveness_state = (string) ( $row['liveness'] ?? '' );
			if ( '' !== $stale_reason ) {
				$stale_reasons[ $stale_reason ] = ( $stale_reasons[ $stale_reason ] ?? 0 ) + 1;
			}
			if ( '' !== $liveness_state ) {
				$liveness[ $liveness_state ] = ( $liveness[ $liveness_state ] ?? 0 ) + 1;
			}
			if ( $size_bytes > 0 ) {
				$size_by_repo[ $repo ] = ( $size_by_repo[ $repo ] ?? 0 ) + $size_bytes;
				$total_size_bytes     += $size_bytes;
			}
			if ( $artifact_bytes > 0 ) {
				$artifact_by_repo[ $repo ] = ( $artifact_by_repo[ $repo ] ?? 0 ) + $artifact_bytes;
				$total_artifact_bytes     += $artifact_bytes;
			}
		}

		ksort( $skipped_by_reason );
		ksort( $candidates_by_signal );
		ksort( $stale_reasons );
		ksort( $liveness );
		arsort( $size_by_repo );
		arsort( $artifact_by_repo );

		$summary = array(
			'would_remove'          => count( $candidates ),
			'removed'               => count( $removed ),
			'skipped'               => count( $skipped ),
			'skipped_by_reason'     => $skipped_by_reason,
			'skipped_next_commands' => $this->worktree_cleanup_skipped_next_commands( $skipped_by_reason ),
			'cleanup_buckets'       => $this->worktree_cleanup_buckets( count( $candidates ), $candidates_by_signal, $skipped_by_reason ),
			'candidates_by_signal'  => $candidates_by_signal,
			'stale_reasons'         => $stale_reasons,
			'liveness'              => $liveness,
			'total_size_bytes'      => $total_size_bytes,
			'artifact_size_bytes'   => $total_artifact_bytes,
			'size_by_repo'          => $size_by_repo,
			'artifact_size_by_repo' => $artifact_by_repo,
			'top_by_size'           => $this->summarize_top_worktree_rows( $all_rows, 'size_bytes' ),
			'top_by_age'            => $this->summarize_top_worktree_rows( $all_rows, 'age_days' ),
		);

		if ( null !== $age_filter ) {
			unset( $age_filter['threshold_unix'] );
			$summary['age_filter'] = $age_filter;
		}

		return $summary;
	}

	/**
	 * Build stable high-level cleanup buckets for plan/report consumers.
	 *
	 * @param int               $candidate_count      Candidate row count.
	 * @param array<string,int> $candidates_by_signal Candidate signal counts.
	 * @param array<string,int> $skipped_by_reason    Skipped reason counts.
	 * @return array<string,int>
	 */
	private function worktree_cleanup_buckets( int $candidate_count, array $candidates_by_signal, array $skipped_by_reason ): array {
		$needs_reconciliation = (int) ( $skipped_by_reason['needs_metadata_reconcile'] ?? 0 )
			+ (int) ( $skipped_by_reason['requires_full_scan'] ?? 0 )
			+ (int) ( $skipped_by_reason['missing_metadata'] ?? 0 )
			+ (int) ( $skipped_by_reason['lifecycle_reconciliation_candidate'] ?? 0 );
		$needs_full_review = (int) ( $skipped_by_reason['active_no_signal'] ?? 0 )
			+ (int) ( $skipped_by_reason['no_inventory_cleanup_signal'] ?? 0 )
			+ (int) ( $skipped_by_reason['no_merge_signal'] ?? 0 )
			+ (int) ( $skipped_by_reason['github_unknown'] ?? 0 )
			+ (int) ( $skipped_by_reason['external_worktree'] ?? 0 )
			+ (int) ( $skipped_by_reason['protected_branch'] ?? 0 )
			+ (int) ( $skipped_by_reason['probe_timeout'] ?? 0 )
			+ (int) ( $skipped_by_reason['unknown_age'] ?? 0 );
		$blocked_by_dirty_or_unpushed = (int) ( $skipped_by_reason['dirty_worktree'] ?? 0 )
			+ (int) ( $skipped_by_reason['merged_pr_with_only_obsolete_dirty_changes'] ?? 0 )
			+ (int) ( $skipped_by_reason['unpushed_commits'] ?? 0 );

		$buckets = array(
			'blocked_by_dirty_or_unpushed'       => $blocked_by_dirty_or_unpushed,
			'needs_full_review'                  => $needs_full_review,
			'needs_reconciliation'               => $needs_reconciliation,
			'safe_to_remove_now'                 => $candidate_count,

			// Legacy aliases retained for existing automation while callers migrate
			// to the explicit safety buckets above.
			'explicit_cleanup_candidates'         => (int) ( $candidates_by_signal['cleanup_eligible'] ?? 0 ),
			'lifecycle_reconciliation_candidates' => (int) ( $skipped_by_reason['lifecycle_reconciliation_candidate'] ?? 0 ),
			'metadata_reconciliation_candidates'  => (int) ( $skipped_by_reason['needs_metadata_reconcile'] ?? 0 ) + (int) ( $skipped_by_reason['requires_full_scan'] ?? 0 ) + (int) ( $skipped_by_reason['missing_metadata'] ?? 0 ),
			'dirty_unpushed'                      => $blocked_by_dirty_or_unpushed,
			'active_no_signal'                    => (int) ( $skipped_by_reason['active_no_signal'] ?? 0 ) + (int) ( $skipped_by_reason['no_inventory_cleanup_signal'] ?? 0 ),
		);

		ksort( $buckets );
		return $buckets;
	}

	/**
	 * Build copy-pasteable next commands for skipped cleanup buckets.
	 *
	 * @param array<string,int> $skipped_by_reason Skipped reason counts.
	 * @return array<int,array<string,mixed>>
	 */
	private function worktree_cleanup_skipped_next_commands( array $skipped_by_reason ): array {
		$templates = array(
			'lifecycle_reconciliation_candidate' => array(
				'label'       => 'Run DMC-owned lifecycle reconciliation before cleanup eligibility',
				'command'     => 'studio wp datamachine-code workspace worktree cleanup --dry-run --format=json',
				'alternative' => 'studio wp datamachine-code workspace cleanup plan --mode=retention --format=json',
				'why'         => 'Runs full merge/PR signal detection so DMC can persist lifecycle state instead of relying on manual agent finalization.',
				'destructive' => false,
			),
			'needs_metadata_reconcile' => array(
				'label'       => 'Repair missing lifecycle metadata in bounded batches',
				'command'     => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --format=json',
				'alternative' => 'Low-level apply still requires a reviewed --apply-plan=<file> until DB-backed cleanup runs land.',
				'why'         => 'Reconciliation writes lifecycle metadata so future inventory cleanup can classify these rows without a full scan.',
				'destructive' => false,
			),
			'active_no_signal' => array(
				'label'       => 'Keep active rows or run full merge-signal review',
				'command'     => 'studio wp datamachine-code workspace worktree cleanup --dry-run --skip-github --format=json',
				'alternative' => 'No automatic cleanup action is safe from active inventory metadata alone.',
				'why'         => 'Active rows without stale liveness or PR/task context are not cleanup candidates from inventory alone.',
				'destructive' => false,
			),
			'repaired_metadata'           => array(
				'label'       => 'Review repaired metadata with bounded safety probes',
				'command'     => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --include-repaired-metadata --limit=25 --older-than=7d',
				'alternative' => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --include-repaired-metadata --limit=25 --older-than=7d',
				'why'         => 'Runs bounded cleanup with fresh safety checks before any deletion.',
				'destructive' => true,
			),
			'requires_full_scan'          => array(
				'label'       => 'Repair missing lifecycle metadata in bounded batches',
				'command'     => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --format=json',
				'alternative' => 'Low-level apply still requires a reviewed --apply-plan=<file> until DB-backed cleanup runs land.',
				'why'         => 'Reconciliation writes lifecycle metadata so future inventory cleanup no longer needs a full scan for those rows.',
				'destructive' => false,
			),
			'no_inventory_cleanup_signal' => array(
				'label'       => 'Mark reviewed worktrees cleanup-eligible',
				'command'     => 'studio wp datamachine-code workspace worktree finalize <handle> --pr=<pr-url>',
				'alternative' => 'studio wp datamachine-code workspace worktree mark-cleanup-eligible <handle>',
				'why'         => 'Records an explicit cleanup signal; then bounded cleanup-eligible apply can remove reviewed safe rows.',
				'destructive' => false,
			),
		);

		$commands = array();
		foreach ( $templates as $reason => $template ) {
			$count = (int) ( $skipped_by_reason[ $reason ] ?? 0 );
			if ( $count <= 0 ) {
				continue;
			}
			$commands[] = array_merge(
				array(
					'reason_code' => $reason,
					'count'       => $count,
				),
				$template
			);
		}

		return $commands;
	}

	/**
	 * Determine a non-destructive stale reason for list/reporting surfaces.
	 *
	 * @param bool        $is_worktree Whether the row is a worktree.
	 * @param int         $dirty_files Dirty file count.
	 * @param int|null    $age_days    Whole-day age.
	 * @param string|null $created_at  Lifecycle timestamp.
	 * @return string|null Stale reason code.
	 */
	private function detect_worktree_stale_reason( bool $is_worktree, int $dirty_files, ?int $age_days, ?string $created_at, array $probes = array() ): ?string {
		if ( ! $is_worktree ) {
			return null;
		}

		$status_probed = array_key_exists( 'status_probed', $probes ) ? (bool) $probes['status_probed'] : true;

		if ( $status_probed && $dirty_files > 0 ) {
			return 'dirty';
		}

		if ( null === $created_at || '' === $created_at || null === $age_days ) {
			return 'missing_metadata';
		}

		$threshold = (int) apply_filters( 'datamachine_code_worktree_stale_days', 14 );
		if ( $threshold > 0 && $age_days >= $threshold ) {
			return 'older_than_threshold';
		}

		return null;
	}

	/**
	 * Build disk and artifact metadata for a worktree/list row.
	 *
	 * @param string      $repo        Primary repo name.
	 * @param string      $path        Worktree path.
	 * @param bool        $is_worktree Whether the row is a linked worktree.
	 * @param string|null $created_at Lifecycle creation timestamp.
	 * @param array|null $metadata    Stored lifecycle/context metadata.
	 * @return array<string,mixed>
	 */
	private function build_worktree_disk_report( string $repo, string $path, bool $is_worktree, ?string $created_at, ?array $metadata ): array {
		$size_bytes      = $this->estimate_path_size_bytes( $path );
		$last_touched_at = $this->detect_worktree_last_touched_at( $path, $metadata, $created_at );
		$age_days        = $this->calculate_age_days( $created_at );
		$artifacts       = $is_worktree ? $this->detect_worktree_artifacts( $repo, $path ) : array();
		$artifact_bytes  = array_sum( array_map( fn( $artifact ) => (int) ( $artifact['size_bytes'] ?? 0 ), $artifacts ) );

		return array(
			'size_bytes'           => $size_bytes,
			'estimated_size_bytes' => $size_bytes,
			'last_touched_at'      => $last_touched_at,
			'age_days'             => $age_days,
			'artifacts'            => $artifacts,
			'artifact_size_bytes'  => $artifact_bytes,
		);
	}

	/**
	 * Pull disk fields from an existing worktree row for cleanup output.
	 *
	 * @param array $wt Worktree row.
	 * @return array<string,mixed>
	 */
	private function extract_worktree_disk_fields( array $wt ): array {
		return array(
			'size_bytes'           => $wt['size_bytes'] ?? null,
			'estimated_size_bytes' => $wt['estimated_size_bytes'] ?? ( $wt['size_bytes'] ?? null ),
			'last_touched_at'      => $wt['last_touched_at'] ?? null,
			'age_days'             => $wt['age_days'] ?? null,
			'stale_reason'         => $wt['stale_reason'] ?? null,
			'artifacts'            => $wt['artifacts'] ?? array(),
			'artifact_size_bytes'  => $wt['artifact_size_bytes'] ?? 0,
		);
	}

	/**
	 * Estimate a path's on-disk size using the platform's fast `du` primitive.
	 *
	 * @param string $path Path to inspect.
	 * @return int|null Size in bytes, or null when unavailable.
	 */
	private function estimate_path_size_bytes( string $path ): ?int {
		if ( '' === $path || ( ! file_exists( $path ) && ! is_link( $path ) ) ) {
			return null;
		}

		$output = array();
		$code   = 1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'du -sk %s 2>/dev/null', escapeshellarg( $path ) ), $output, $code );
		if ( 0 !== $code || empty( $output[0] ) ) {
			return null;
		}

		$parts = preg_split( '/\s+/', trim( (string) $output[0] ) );
		$kb    = isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
		return $kb > 0 ? $kb * 1024 : 0;
	}

	/**
	 * Detect non-source artifact directories worth reporting separately.
	 *
	 * @param string $repo Repo name.
	 * @param string $path Worktree path.
	 * @return array<int,array<string,mixed>> Artifact rows.
	 */
	private function detect_worktree_artifacts( string $repo, string $path ): array {
		$patterns = $this->get_worktree_artifact_profile( $repo, $path );
		$rows     = array();

		foreach ( $patterns as $relative => $label ) {
			$relative = trim( (string) $relative, '/' );
			if ( '' === $relative || str_contains( $relative, '..' ) ) {
				continue;
			}

			$artifact_path = rtrim( $path, '/' ) . '/' . $relative;
			if ( ! is_dir( $artifact_path ) ) {
				continue;
			}

			$size   = $this->estimate_path_size_bytes( $artifact_path );
			$rows[] = array(
				'path'       => $relative,
				'label'      => (string) $label,
				'size_bytes' => $size,
			);
		}

		usort( $rows, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ) );
		return $rows;
	}

	/**
	 * Resolve repo-specific artifact profile paths.
	 *
	 * @param string $repo Repo name.
	 * @param string $path Worktree path.
	 * @return array<string,string> Relative path => label map.
	 */
	private function get_worktree_artifact_profile( string $repo, string $path ): array {
		$profile = array();

		if ( is_file( rtrim( $path, '/' ) . '/Cargo.toml' ) || 'homeboy' === $repo ) {
			$profile['target'] = 'Rust build artifacts';
		}

		if ( is_file( rtrim( $path, '/' ) . '/package.json' ) ) {
			$profile['node_modules'] = 'Node dependencies';
			$profile['.next']        = 'Next.js build cache';
			$profile['dist']         = 'JavaScript build output';
			$profile['coverage']     = 'test coverage output';
		}

		if ( is_file( rtrim( $path, '/' ) . '/composer.json' ) ) {
			$profile['vendor'] = 'Composer dependencies';
		}

		/**
		 * Filters non-source artifact paths reported for a workspace worktree.
		 *
		 * Reporting is non-destructive. Future cleanup actions should reuse this
		 * profile but add separate opt-in delete gates.
		 *
		 * @param array<string,string> $profile Relative path => label map.
		 * @param string               $repo    Repo name.
		 * @param string               $path    Worktree path.
		 */
		$filtered = apply_filters( 'datamachine_code_worktree_artifact_profile', $profile, $repo, $path );
		return is_array( $filtered ) ? $filtered : $profile;
	}

	/**
	 * Resolve the most useful last-touched timestamp for a worktree.
	 *
	 * @param string      $path       Worktree path.
	 * @param array|null  $metadata   Stored lifecycle/context metadata.
	 * @param string|null $created_at Created timestamp fallback.
	 * @return string|null ISO timestamp.
	 */
	private function detect_worktree_last_touched_at( string $path, ?array $metadata, ?string $created_at ): ?string {
		foreach ( array( 'last_touched_at', 'updated_at', 'timestamp' ) as $key ) {
			if ( is_array( $metadata ) && ! empty( $metadata[ $key ] ) && false !== strtotime( (string) $metadata[ $key ] ) ) {
				return gmdate( 'c', (int) strtotime( (string) $metadata[ $key ] ) );
			}
		}

		$git_marker = rtrim( $path, '/' ) . '/.git';
		$mtime      = file_exists( $git_marker ) ? filemtime( $git_marker ) : false;
		if ( false === $mtime && file_exists( $path ) ) {
			$mtime = filemtime( $path );
		}

		if ( false !== $mtime ) {
			return gmdate( 'c', (int) $mtime );
		}

		return $created_at;
	}

	/**
	 * Calculate whole-day age from an ISO timestamp.
	 *
	 * @param string|null $created_at Created timestamp.
	 * @return int|null Whole days old, or null when unknown.
	 */
	private function calculate_age_days( ?string $created_at ): ?int {
		if ( null === $created_at || '' === $created_at ) {
			return null;
		}

		$created_ts = strtotime( $created_at );
		if ( false === $created_ts ) {
			return null;
		}

		return max( 0, (int) floor( ( time() - $created_ts ) / 86400 ) );
	}

	/**
	 * Sort cleanup rows by requested reporting dimension.
	 *
	 * @param array<int,array> $rows Rows to sort.
	 * @param string           $sort size|age|empty.
	 * @return array<int,array>
	 */
	private function sort_worktree_cleanup_rows( array $rows, string $sort ): array {
		if ( 'size' === $sort ) {
			usort( $rows, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ) );
		} elseif ( 'age' === $sort ) {
			usort( $rows, fn( $a, $b ) => (int) ( $b['age_days'] ?? -1 ) <=> (int) ( $a['age_days'] ?? -1 ) );
		}

		return $rows;
	}

	/**
	 * Produce compact top-N rows for cleanup summaries.
	 *
	 * @param array<int,array> $rows Rows to summarize.
	 * @param string           $field Numeric field to sort by.
	 * @return array<int,array<string,mixed>>
	 */
	private function summarize_top_worktree_rows( array $rows, string $field ): array {
		$rows = array_values( array_filter( $rows, fn( $row ) => isset( $row[ $field ] ) && null !== $row[ $field ] && (int) $row[ $field ] > 0 ) );
		usort( $rows, fn( $a, $b ) => (int) ( $b[ $field ] ?? 0 ) <=> (int) ( $a[ $field ] ?? 0 ) );

		return array_map(
			fn( $row ) => array(
				'handle'              => $row['handle'] ?? '',
				'repo'                => $row['repo'] ?? '',
				'branch'              => $row['branch'] ?? '',
				'path'                => $row['path'] ?? '',
				'size_bytes'          => $row['size_bytes'] ?? null,
				'artifact_size_bytes' => $row['artifact_size_bytes'] ?? 0,
				'age_days'            => $row['age_days'] ?? null,
				'reason_code'         => $row['reason_code'] ?? '',
			),
			array_slice( $rows, 0, self::CLEANUP_SUMMARY_TOP_LIMIT )
		);
	}

	/**
	 * Parse a compact cleanup age duration.
	 *
	 * @param string $duration Duration like 7d, 24h, 30m, or 60s.
	 * @return int|\WP_Error Seconds on success.
	 */
	private function parse_worktree_cleanup_duration( string $duration ): int|\WP_Error {
		if ( ! preg_match( '/^(\d+)([smhdw])$/', trim( $duration ), $matches ) ) {
			return new \WP_Error( 'invalid_cleanup_age_filter', 'Invalid --older-than duration. Use a compact value like 7d, 24h, 30m, or 60s.', array( 'status' => 400 ) );
		}

		$value = (int) $matches[1];
		if ( $value <= 0 ) {
			return new \WP_Error( 'invalid_cleanup_age_filter', 'Invalid --older-than duration. Duration must be greater than zero.', array( 'status' => 400 ) );
		}

		$unit_seconds = array(
			's' => 1,
			'm' => 60,
			'h' => 3600,
			'd' => 86400,
			'w' => 604800,
		);

		return $value * $unit_seconds[ $matches[2] ];
	}

	/**
	 * Extract cleanup candidates from a dry-run JSON report.
	 *
	 * @param array $plan Decoded cleanup report.
	 * @return array<int,array>|\WP_Error
	 */
	private function extract_worktree_cleanup_plan_candidates( array $plan ): array|\WP_Error {
		$candidates = $plan['candidates'] ?? null;
		if ( ! is_array( $candidates ) ) {
			return new \WP_Error( 'invalid_cleanup_plan', 'Cleanup plan must contain a candidates array.', array( 'status' => 400 ) );
		}

		$required = array( 'handle', 'repo', 'branch', 'path', 'signal' );
		foreach ( $candidates as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'invalid_cleanup_plan', sprintf( 'Cleanup plan candidate #%d is not an object.', (int) $index ), array( 'status' => 400 ) );
			}
			foreach ( $required as $field ) {
				if ( '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
					return new \WP_Error( 'invalid_cleanup_plan', sprintf( 'Cleanup plan candidate #%d is missing %s.', (int) $index, $field ), array( 'status' => 400 ) );
				}
			}
		}

		return array_values( $candidates );
	}

	/**
	 * Restrict a freshly-evaluated cleanup report to a reviewed plan.
	 *
	 * The destructive pass still re-runs every cleanup gate. Only rows that are
	 * current safe candidates and exactly match the reviewed handle/path/branch/
	 * signal are removed; anything that drifted is reported as skipped.
	 *
	 * @param array<int,array> $planned_candidates Candidate rows from the plan file.
	 * @param array<int,array> $current_candidates Freshly detected safe candidates.
	 * @param array<int,array> $current_skipped    Freshly detected skipped rows.
	 * @return array{candidates: array<int,array>, skipped: array<int,array>}
	 */
	private function scope_worktree_cleanup_to_plan( array $planned_candidates, array $current_candidates, array $current_skipped ): array {
		$current_by_handle = array();
		foreach ( $current_candidates as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' !== $handle ) {
				$current_by_handle[ $handle ] = $row;
			}
		}

		$skipped_by_handle = array();
		foreach ( $current_skipped as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' !== $handle && ! isset( $skipped_by_handle[ $handle ] ) ) {
				$skipped_by_handle[ $handle ] = $row;
			}
		}

		$scoped_candidates = array();
		$scoped_skipped    = array();

		foreach ( $planned_candidates as $plan_row ) {
			$handle  = (string) ( $plan_row['handle'] ?? '' );
			$current = $current_by_handle[ $handle ] ?? null;

			if ( null === $current ) {
				$skip                   = $skipped_by_handle[ $handle ] ?? array(
					'handle'      => $handle,
					'repo'        => (string) ( $plan_row['repo'] ?? '' ),
					'branch'      => (string) ( $plan_row['branch'] ?? '' ),
					'path'        => (string) ( $plan_row['path'] ?? '' ),
					'reason_code' => 'plan_not_current',
					'reason'      => 'planned cleanup row is no longer present in the current worktree list',
				);
				$skip['planned_signal'] = (string) ( $plan_row['signal'] ?? '' );
				$scoped_skipped[]       = $skip;
				continue;
			}

			$mismatches = array();
			foreach ( array( 'repo', 'branch', 'path', 'signal' ) as $field ) {
				$planned = (string) ( $plan_row[ $field ] ?? '' );
				$actual  = (string) ( $current[ $field ] ?? '' );
				if ( $planned !== $actual ) {
					$mismatches[] = sprintf( '%s planned=%s current=%s', $field, $planned, $actual );
				}
			}

			if ( array() !== $mismatches ) {
				$scoped_skipped[] = array(
					'handle'         => $handle,
					'repo'           => (string) ( $current['repo'] ?? $plan_row['repo'] ?? '' ),
					'branch'         => (string) ( $current['branch'] ?? $plan_row['branch'] ?? '' ),
					'path'           => (string) ( $current['path'] ?? $plan_row['path'] ?? '' ),
					'reason_code'    => 'plan_mismatch',
					'reason'         => 'planned cleanup row no longer matches current state: ' . implode( '; ', $mismatches ),
					'planned_signal' => (string) ( $plan_row['signal'] ?? '' ),
					'current_signal' => (string) ( $current['signal'] ?? '' ),
				);
				continue;
			}

			$scoped_candidates[] = $current;
		}

		return array(
			'candidates' => $scoped_candidates,
			'skipped'    => $scoped_skipped,
		);
	}

	/**
	 * Remove a worktree at an explicit path.
	 *
	 * Path-aware counterpart to `worktree_remove()`, which reconstructs the
	 * path from `<repo>@<slug>` convention. Cleanup code must use this so
	 * reviewed inventory rows are removed by their safety-probed path.
	 *
	 * Hard safety rails applied here before any removal:
	 *   1. Primary repo's `.git` must exist (we're about to invoke it)
	 *   2. The worktree path must be a real directory
	 *   3. The worktree path must be inside `$workspace_path` (containment
	 *      validation — no external targets, ever)
	 *   4. The worktree's `.git` must be a file (worktree marker), not a
	 *      directory. A directory `.git` means it's a primary, not a
	 *      worktree — removing it would be catastrophic.
	 *   5. If dirty and not forcing, refuse.
	 *
	 * @param string $repo    Primary repo directory name (for routing git commands).
	 * @param string $branch  Branch the worktree is checked out to.
	 * @param string $wt_path Absolute path to the worktree directory.
	 * @param bool   $force   Pass --force to `git worktree remove`.
	 * @return array{success: bool, handle: string, message: string}|\WP_Error
	 */
	private function remove_worktree_by_path( string $repo, string $branch, string $wt_path, bool $force ): array|\WP_Error {
		$repo = $this->sanitize_name( $repo );
		if ( '' === $repo ) {
			return new \WP_Error( 'invalid_repo', 'Repository name is required.', array( 'status' => 400 ) );
		}

		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path . '/.git' ) ) {
			return new \WP_Error( 'primary_not_found', sprintf( 'Primary checkout for "%s" does not exist.', $repo ), array( 'status' => 404 ) );
		}

		if ( '' === $wt_path || ! is_dir( $wt_path ) ) {
			return new \WP_Error( 'worktree_path_missing', sprintf( 'Worktree path does not exist: %s', $wt_path ), array( 'status' => 404 ) );
		}

		// Belt-and-suspenders containment — cleanup callers already skip
		// `external` worktrees, but validate again at the blast radius.
		$validation = $this->validate_containment( $wt_path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'path_outside_workspace',
				sprintf( 'Refusing to remove "%s": path is outside workspace (%s).', $wt_path, $validation['message'] ?? '' ),
				array( 'status' => 403 )
			);
		}

		// A worktree's .git is a FILE pointing at the primary's .git dir.
		// A directory .git means we're looking at a primary checkout — never
		// touch those.
		$git_marker = rtrim( $validation['real_path'], '/' ) . '/.git';
		if ( ! is_file( $git_marker ) ) {
			return new \WP_Error(
				'not_a_worktree',
				sprintf( 'Refusing to remove "%s": .git is not a worktree marker file (got: %s). This may be a primary checkout.', $wt_path, is_dir( $git_marker ) ? 'directory' : 'missing' ),
				array( 'status' => 403 )
			);
		}

		$cmd    = sprintf( 'worktree remove %s%s', $force ? '--force ' : '', escapeshellarg( $validation['real_path'] ) );
		$result = $this->run_git( $primary_path, $cmd );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If the directory survived `git worktree remove` (can happen for
		// locked worktrees, or when the worktree was already detached), prune
		// the directory manually so cleanup is effective.
		if ( is_dir( $validation['real_path'] ) ) {
			$escaped = escapeshellarg( $validation['real_path'] );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( sprintf( 'rm -rf %s 2>&1', $escaped ) );
		}

		WorktreeContextInjector::forget_metadata( basename( $wt_path ) );
		$this->worktree_inventory()->delete( basename( $wt_path ) );

		return array(
			'success' => true,
			'handle'  => basename( $wt_path ),
			'message' => sprintf( 'Worktree at "%s" removed.', $wt_path ),
			'branch'  => $branch,
		);
	}

	/**
	 * Classify a dirty worktree as "merged + only obsolete dirty changes".
	 *
	 * Returns the classification payload when:
	 *   - The branch has a confirmed merge signal (upstream-gone, local-merged,
	 *     pr-merged, or already cleanup-eligible per metadata).
	 *   - All dirty paths reported by `git status --porcelain` are tracked
	 *     paths whose entries are absent on the remote default branch tip
	 *     (i.e. modifying or deleting files the default branch no longer has).
	 *
	 * Returns null in every other case so the caller falls back to the
	 * generic `dirty_worktree` skip:
	 *   - No merge signal, or signal cannot be confirmed.
	 *   - Any dirty path is untracked (could be new content).
	 *   - Any dirty path still exists on the default branch tip.
	 *   - Default branch ref cannot be resolved.
	 *   - Any git probe times out or fails.
	 *
	 * The classification keeps cleanup conservative: it never auto-removes
	 * dirty worktrees, but the distinct reason code lets reviewers spot the
	 * "safe to force" subset without manual archaeology.
	 *
	 * @param string                 $repo                       Repo directory name.
	 * @param string                 $branch                     Branch name.
	 * @param string                 $wt_path                    Worktree path.
	 * @param bool                   $skip_github                Whether to skip GitHub API lookups.
	 * @param array<string,mixed>    $github_cache               Run-local GitHub cache.
	 * @param array<string,bool>     $fetched                    Per-repo fetch tracker.
	 * @param array<string,\WP_Error>$fetch_timeouts             Per-repo fetch timeout tracker.
	 * @param mixed                  $metadata                   Worktree metadata.
	 * @param bool                   $include_repaired_metadata  Whether repaired metadata counts as a cleanup signal.
	 * @return array{paths: array<string,string>, merge_signal: string, pr_url?: ?string, default_ref: string}|null
	 */
	private function classify_dirty_obsolete_on_default_branch(
		string $repo,
		string $branch,
		string $wt_path,
		bool $skip_github,
		array &$github_cache,
		array &$fetched,
		array &$fetch_timeouts,
		$metadata,
		bool $include_repaired_metadata
	): ?array {
		if ( '' === $repo || '' === $branch || '' === $wt_path || ! is_dir( $wt_path ) ) {
			return null;
		}

		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path . '/.git' ) ) {
			return null;
		}

		// Refuse to classify if a previous worktree already saw this repo's
		// fetch time out — the default-ref / merge-signal probes would race
		// against stale data.
		if ( isset( $fetch_timeouts[ $repo ] ) ) {
			return null;
		}

		// Ensure remote refs are fresh once per repo per cleanup run. Reuses
		// the caller's `$fetched` tracker so this never double-fetches.
		if ( empty( $fetched[ $repo ] ) ) {
			$fetch = $this->run_git( $primary_path, 'fetch --prune --quiet origin', self::CLEANUP_GIT_PROBE_TIMEOUT );
			if ( $this->is_git_timeout_error( $fetch ) ) {
				$fetch_timeouts[ $repo ] = $fetch;
				return null;
			}
			$fetched[ $repo ] = true;
		}

		$default_ref = $this->resolve_remote_default_ref( $primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( $default_ref instanceof \WP_Error || null === $default_ref || '' === $default_ref ) {
			return null;
		}

		// Confirm the default ref actually resolves to a commit. If it doesn't,
		// every `cat-file -e <ref>:<path>` would fail and we'd mis-classify the
		// whole worktree as obsolete-on-default.
		$default_resolve = $this->run_git(
			$primary_path,
			sprintf( 'rev-parse --verify --quiet %s', escapeshellarg( $default_ref . '^{commit}' ) ),
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( $this->is_git_timeout_error( $default_resolve ) || is_wp_error( $default_resolve ) ) {
			return null;
		}

		$signal = null;
		if ( is_array( $metadata ) && WorktreeContextInjector::has_cleanup_signal( $metadata ) ) {
			$signal = array(
				'signal' => 'cleanup_eligible',
				'reason' => 'worktree finalized or explicitly marked cleanup_eligible',
			);
			if ( ! empty( $metadata['pr_url'] ) ) {
				$signal['pr_url'] = (string) $metadata['pr_url'];
			}
		} elseif ( $include_repaired_metadata && is_array( $metadata ) && ! empty( $metadata['metadata_repaired'] ) ) {
			$signal = array(
				'signal' => 'repaired_metadata',
				'reason' => 'operator-approved cleanup of repaired metadata',
			);
		} else {
			$signal = $this->detect_merge_signal( $primary_path, $repo, $branch, $skip_github, $github_cache );
		}

		if ( ! is_array( $signal ) ) {
			return null;
		}
		$signal_kind    = (string) ( $signal['signal'] ?? '' );
		$merged_signals = array( 'upstream-gone', 'local-merged', 'pr-merged', 'cleanup_eligible', 'repaired_metadata' );
		if ( ! in_array( $signal_kind, $merged_signals, true ) ) {
			return null;
		}

		// Untracked files are never "obsolete on default" — they could be new
		// content the operator wants to preserve. Bail at the first hint of
		// untracked content so this classifier stays conservative.
		$untracked = $this->run_git(
			$wt_path,
			'ls-files --others --exclude-standard',
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( $this->is_git_timeout_error( $untracked ) ) {
			return null;
		}
		if ( ! is_wp_error( $untracked ) && '' !== trim( (string) ( $untracked['output'] ?? '' ) ) ) {
			return null;
		}

		// Modified/deleted/added tracked paths against the worktree's HEAD.
		// `diff --name-only HEAD` covers staged and unstaged changes in one
		// shot and avoids the porcelain status leading-whitespace quirk that
		// `trim()`-on-output would corrupt.
		$tracked = $this->run_git(
			$wt_path,
			'diff --name-only HEAD',
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( $this->is_git_timeout_error( $tracked ) || is_wp_error( $tracked ) ) {
			return null;
		}

		$paths = array_values(
			array_filter(
				array_map( 'trim', explode( "\n", (string) ( $tracked['output'] ?? '' ) ) ),
				fn( $line ) => '' !== $line
			)
		);
		if ( array() === $paths ) {
			return null;
		}

		$obsolete_paths = array();
		foreach ( $paths as $path ) {
			// `cat-file -e <ref>:<path>` exits 0 when the path exists on the
			// default branch tip. Non-zero (missing/ambiguous) means the path
			// is absent there — exactly the case we want to classify as
			// obsolete-on-default.
			$probe = $this->run_git(
				$primary_path,
				sprintf( 'cat-file -e %s', escapeshellarg( $default_ref . ':' . $path ) ),
				self::CLEANUP_GIT_PROBE_TIMEOUT
			);
			if ( $this->is_git_timeout_error( $probe ) ) {
				return null;
			}
			if ( is_wp_error( $probe ) ) {
				$obsolete_paths[ $path ] = 'absent_on_default';
				continue;
			}
			// Path still exists on the default branch tip — dirty edit may
			// still be relevant. Refuse to classify into the new bucket.
			return null;
		}

		if ( array() === $obsolete_paths ) {
			return null;
		}

		return array(
			'paths'        => $obsolete_paths,
			'merge_signal' => $signal_kind,
			'pr_url'       => $signal['pr_url'] ?? null,
			'default_ref'  => $default_ref,
		);
	}

	/**
	 * Detect whether a branch looks merged into the remote default branch.
	 *
	 * Returns an array with `signal` and `reason`, or null if no signal is
	 * present (leave the worktree alone).
	 *
	 * Signal priority:
	 *   1. `upstream-gone` — local branch's upstream tracking ref is gone.
	 *      Typical after GitHub auto-deletes the head branch on PR merge.
	 *   2. `pr-merged` — GitHub API reports a closed+merged PR for this
	 *      branch. Requires $skip_github = false and a configured PAT.
	 *
	 * @param string $primary_path Path to the primary git checkout.
	 * @param string $repo         Primary repo directory name.
	 * @param string $branch       Branch name.
	 * @param bool   $skip_github  If true, skip GitHub API lookup.
	 * @param array  $github_cache Run-local cache for GitHub repo lookups.
	 * @return array{signal: string, reason: string, pr_url?: string}|null
	 */
	private function detect_merge_signal( string $primary_path, string $repo, string $branch, bool $skip_github, array &$github_cache = array() ): ?array {
		$ref    = 'refs/heads/' . $branch;
		$format = '%(upstream:track)';
		$result = $this->run_git( $primary_path, sprintf( 'for-each-ref --format=%s %s', escapeshellarg( $format ), escapeshellarg( $ref ) ), self::CLEANUP_GIT_PROBE_TIMEOUT );

		if ( $this->is_git_timeout_error( $result ) ) {
			return array(
				'signal' => 'probe-timeout',
				'reason' => $result->get_error_message(),
			);
		}

		if ( ! is_wp_error( $result ) ) {
			$track = trim( (string) ( $result['output'] ?? '' ) );
			if ( str_contains( $track, 'gone' ) ) {
				return array(
					'signal' => 'upstream-gone',
					'reason' => 'remote branch deleted (likely merged + auto-deleted)',
				);
			}
		}

		$local_merged = $this->detect_local_merged_signal( $primary_path, $branch );
		if ( null !== $local_merged ) {
			return $local_merged;
		}

		if ( $skip_github ) {
			return null;
		}

		$gh_slug = $this->resolve_github_slug( $primary_path );
		if ( null === $gh_slug ) {
			return null;
		}

		$pr = $this->find_closed_pr_for_branch( $gh_slug, $branch, $github_cache );
		if ( is_wp_error( $pr ) ) {
			return array(
				'signal' => 'github-unknown',
				'reason' => 'unknown_github_state — ' . $pr->get_error_message(),
			);
		}
		if ( null === $pr ) {
			return null;
		}

		if ( ! empty( $pr['merged_at'] ) ) {
			return array(
				'signal'          => 'pr-merged',
				'reason'          => sprintf( 'PR #%d merged (%s)', $pr['number'], $pr['state'] ),
				'finalized_state' => WorktreeContextInjector::STATE_MERGED,
				'pr_url'          => $pr['html_url'] ?? null,
			);
		}

		return array(
			'signal'          => 'pr-closed',
			'reason'          => sprintf( 'PR #%d closed without merge', $pr['number'] ),
			'finalized_state' => WorktreeContextInjector::STATE_CLOSED,
			'pr_url'          => $pr['html_url'] ?? null,
		);
	}

	/**
	 * Detect branches already contained in the remote default branch using local git refs only.
	 *
	 * This catches manually-merged branches before falling through to the GitHub
	 * API, which keeps GitHub-backed cleanup bounded while avoiding unnecessary
	 * network calls for branches whose merge state is already locally provable.
	 *
	 * @param string $primary_path Path to the primary git checkout.
	 * @param string $branch       Branch name.
	 * @return array{signal: string, reason: string}|null
	 */
	private function detect_local_merged_signal( string $primary_path, string $branch ): ?array {
		$default_ref = $this->resolve_remote_default_ref( $primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
		if ( $default_ref instanceof \WP_Error ) {
			return array(
				'signal' => 'probe-timeout',
				'reason' => $default_ref->get_error_message(),
			);
		}
		if ( null === $default_ref ) {
			return null;
		}

		$branch_ref = 'refs/heads/' . $branch;
		$result     = $this->run_git(
			$primary_path,
			sprintf( 'rev-list --count %s..%s', escapeshellarg( $default_ref ), escapeshellarg( $branch_ref ) ),
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( $this->is_git_timeout_error( $result ) ) {
			return array(
				'signal' => 'probe-timeout',
				'reason' => $result->get_error_message(),
			);
		}
		if ( is_wp_error( $result ) ) {
			return null;
		}

		$unique_commits = (int) trim( (string) ( $result['output'] ?? '' ) );
		if ( 0 !== $unique_commits ) {
			return null;
		}

		return array(
			'signal' => 'local-merged',
			'reason' => sprintf( 'branch has no commits outside remote default (%s)', $default_ref ),
		);
	}

	/**
	 * Resolve the remote default branch ref for local cleanup checks.
	 *
	 * @param string $primary_path Path to the primary git checkout.
	 * @return string|\WP_Error|null Fully-qualified remote default ref, timeout error, or null when unavailable.
	 */
	private function resolve_remote_default_ref( string $primary_path, int $timeout_seconds = 0 ): string|\WP_Error|null {
		$result = $this->run_git( $primary_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD', $timeout_seconds );
		if ( $this->is_git_timeout_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $result ) ) {
			return null;
		}

		$ref = trim( (string) ( $result['output'] ?? '' ) );
		return '' === $ref ? null : $ref;
	}

	/**
	 * Extract owner/repo slug from a primary checkout's origin remote.
	 *
	 * @param string $primary_path Primary checkout path.
	 * @return string|null `owner/repo` or null if origin is not a GitHub URL.
	 */
	private function resolve_github_slug( string $primary_path ): ?string {
		$remote = $this->git_get_remote( $primary_path );
		if ( null === $remote || '' === $remote ) {
			return null;
		}
		return GitHubRemote::slug( $remote );
	}

	/**
	 * Look up a closed PR for a branch via a cached GitHub API snapshot.
	 *
	 * Cleanup may inspect hundreds of worktrees for the same repo. Querying
	 * GitHub once per branch does not scale, so each repo gets one bounded
	 * closed-PR snapshot per cleanup run and branch lookups read that cache.
	 *
	 * @param string $slug         owner/repo.
	 * @param string $branch       Branch name.
	 * @param array  $github_cache Run-local cache keyed by owner/repo.
	 * @return array|null|\WP_Error PR data, null when no PR matched, or lookup failure.
	 */
	private function find_closed_pr_for_branch( string $slug, string $branch, array &$github_cache = array() ): array|\WP_Error|null {
		$lookup = $this->get_cleanup_github_lookup( $slug, $github_cache );
		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		if ( null !== $lookup && isset( $lookup[ $branch ] ) ) {
			return $lookup[ $branch ];
		}

		return $this->find_pr_for_branch_direct( $slug, $branch, $github_cache, true );
	}

	/**
	 * Look up a PR for one branch directly via GitHub's head filter.
	 *
	 * The repo-level closed-PR snapshot is intentionally bounded for cleanup runs,
	 * so older PRs can be missed. This precise fallback keeps PR lifecycle as the
	 * source of truth without treating remote branch existence as liveness.
	 *
	 * @param string $slug           owner/repo.
	 * @param string $branch         Branch name.
	 * @param array  $github_cache   Run-local cache keyed by owner/repo and branch.
	 * @param bool   $finalized_only If true, ignore open PRs.
	 * @return array|null|\WP_Error PR data, null when no matching PR exists, or lookup failure.
	 */
	private function find_pr_for_branch_direct( string $slug, string $branch, array &$github_cache = array(), bool $finalized_only = true ): array|\WP_Error|null {
		$cache_key = $slug . '#head:' . ( $finalized_only ? 'finalized:' : 'any:' ) . $branch;
		if ( array_key_exists( $cache_key, $github_cache ) ) {
			return $github_cache[ $cache_key ];
		}

		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			$github_cache[ $cache_key ] = null;
			return null;
		}

		$parts = explode( '/', $slug, 2 );
		$owner = $parts[0] ?? '';
		if ( '' === $owner || empty( $parts[1] ) ) {
			$github_cache[ $cache_key ] = null;
			return null;
		}

		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat( array( 'repo' => $slug ) );
		if ( empty( $pat ) ) {
			$github_cache[ $cache_key ] = null;
			return null;
		}

		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'pulls' ),
			array(
				'head'      => $owner . ':' . $branch,
				'sort'      => 'updated',
				'direction' => 'desc',
				'state'     => 'all',
				'per_page'  => 5,
			),
			$pat,
			self::CLEANUP_GITHUB_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			$error = new \WP_Error(
				'github_cleanup_branch_lookup_failed',
				sprintf( 'GitHub cleanup branch lookup failed for %s:%s: %s', $slug, $branch, $response->get_error_message() ),
				$response->get_error_data()
			);
			$github_cache[ $cache_key ] = $error;
			return $error;
		}

		foreach ( (array) ( $response['data'] ?? array() ) as $pr ) {
			if ( ! is_array( $pr ) ) {
				continue;
			}

			$head      = is_array( $pr['head'] ?? null ) ? $pr['head'] : array();
			$head_repo = is_array( $head['repo'] ?? null ) ? (string) ( $head['repo']['full_name'] ?? '' ) : '';
			$head_ref  = (string) ( $head['ref'] ?? '' );
			$state     = (string) ( $pr['state'] ?? '' );
			if ( $head_repo !== $slug || $head_ref !== $branch ) {
				continue;
			}
			if ( $finalized_only && 'closed' !== $state ) {
				continue;
			}

			$github_cache[ $cache_key ] = array(
				'number'    => (int) ( $pr['number'] ?? 0 ),
				'state'     => $state,
				'merged_at' => (string) ( $pr['merged_at'] ?? '' ),
				'html_url'  => (string) ( $pr['html_url'] ?? '' ),
			);

			return $github_cache[ $cache_key ];
		}

		$github_cache[ $cache_key ] = null;
		return null;
	}

	/**
	 * Load and cache closed same-repo PRs for a GitHub repo.
	 *
	 * @param string $slug         owner/repo.
	 * @param array  $github_cache Run-local cache keyed by owner/repo.
	 * @return array<string,array>|null|\WP_Error Branch-name map, null when GitHub is unavailable, or lookup failure.
	 */
	private function get_cleanup_github_lookup( string $slug, array &$github_cache ): array|\WP_Error|null {
		if ( array_key_exists( $slug, $github_cache ) ) {
			return $github_cache[ $slug ];
		}

		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			$github_cache[ $slug ] = null;
			return null;
		}

		// Pass the repo through so credential profiles with `allowed_repos`
		// can win over the global default profile when scanning closed PRs.
		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat( array( 'repo' => $slug ) );
		if ( empty( $pat ) ) {
			$github_cache[ $slug ] = null;
			return null;
		}

		$parts = explode( '/', $slug, 2 );
		$owner = $parts[0] ?? '';
		if ( '' === $owner || empty( $parts[1] ) ) {
			$github_cache[ $slug ] = null;
			return null;
		}

		$closed = array();
		$url    = GitHubRemote::apiUrl( $slug, 'pulls' );

		for ( $page = 1; $page <= self::CLEANUP_GITHUB_MAX_PAGES; $page++ ) {
			$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
				$url,
				array(
					'state'     => 'closed',
					'sort'      => 'updated',
					'direction' => 'desc',
					'per_page'  => 100,
					'page'      => $page,
				),
				$pat,
				self::CLEANUP_GITHUB_TIMEOUT
			);

			if ( is_wp_error( $response ) ) {
				$error                 = new \WP_Error(
					'github_cleanup_lookup_failed',
					sprintf( 'GitHub cleanup lookup failed for %s: %s', $slug, $response->get_error_message() ),
					$response->get_error_data()
				);
				$github_cache[ $slug ] = $error;
				return $error;
			}

			$items = (array) ( $response['data'] ?? array() );
			foreach ( $items as $pr ) {
				$head      = is_array( $pr['head'] ?? null ) ? $pr['head'] : array();
				$head_repo = is_array( $head['repo'] ?? null ) ? (string) ( $head['repo']['full_name'] ?? '' ) : '';
				$head_ref  = (string) ( $head['ref'] ?? '' );
				if ( $head_repo !== $slug || '' === $head_ref ) {
					continue;
				}

				$closed[ $head_ref ] = array(
					'number'    => (int) ( $pr['number'] ?? 0 ),
					'state'     => (string) ( $pr['state'] ?? 'closed' ),
					'merged_at' => (string) ( $pr['merged_at'] ?? '' ),
					'html_url'  => (string) ( $pr['html_url'] ?? '' ),
				);
			}

			if ( count( $items ) < 100 ) {
				break;
			}
		}

		$github_cache[ $slug ] = $closed;
		return $closed;
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Validate that a target path is contained within a parent directory.
	 *
	 * Public security primitive — used by WorkspaceReader and WorkspaceWriter
	 * to enforce path containment before (and after) file I/O. Uses realpath()
	 * for symlink-safe resolution, so the target must exist on disk.
	 *
	 * For pre-write validation of non-existent files, use has_traversal()
	 * checks on the relative path first, then call this method post-write
	 * to verify the file landed where expected.
	 *
	 * @param string $target    Path to validate.
	 * @param string $container Parent directory that must contain the target.
	 * @return array{valid: bool, real_path?: string, message?: string}
	 */
	public function validate_containment( string $target, string $container ): array {
		return PathSecurity::validateContainment( $target, $container );
	}

	/**
	 * Derive a repo name from a git URL.
	 *
	 * @param string $url Git URL.
	 * @return string|null Derived name or null.
	 */
	private function derive_repo_name( string $url ): ?string {
		// Handle https://github.com/org/repo.git and git@github.com:org/repo.git
		$name = basename( $url );
		$name = preg_replace( '/\.git$/', '', $name );
		$name = $this->sanitize_name( $name );

		return ( '' !== $name ) ? $name : null;
	}

	/**
	 * Sanitize a directory name for use in the workspace.
	 *
	 * @param string $name Raw name.
	 * @return string Sanitized name (alphanumeric, hyphens, underscores, dots).
	 */
	private function sanitize_name( string $name ): string {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name );
	}

	/**
	 * Run a git command in a repository.
	 *
	 * @param string $repo_path Resolved repository path.
	 * @param string $git_args        Git arguments (without leading "git").
	 * @param int    $timeout_seconds Optional timeout in seconds.
	 * @return array
	 */
	private function run_git( string $repo_path, string $git_args, int $timeout_seconds = 0 ): array|\WP_Error {
		return GitRunner::run( $repo_path, $git_args, $timeout_seconds );
	}

	/**
	 * Determine whether a git result is a timeout error.
	 *
	 * @param mixed $result Git result.
	 * @return bool
	 */
	private function is_git_timeout_error( mixed $result ): bool {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		if ( method_exists( $result, 'get_error_code' ) ) {
			return 'git_command_timeout' === $result->get_error_code();
		}

		return isset( $result->code ) && 'git_command_timeout' === $result->code;
	}

	/**
	 * Count commits on HEAD that the upstream branch hasn't seen.
	 *
	 * Used by `worktree_cleanup_merged()` as a hard stop against data loss:
	 * if a worktree has local commits ahead of upstream, we refuse to delete
	 * it even when the "branch merged" signal fires. This catches the case
	 * where someone manually deleted a remote branch (triggering
	 * upstream-gone) without first merging the local commits.
	 *
	 * Returns 0 when:
	 *   - Upstream is configured and HEAD is at or behind it.
	 *   - Upstream is `gone` but no local commits exist above its last-known
	 *     commit (`@{push}` or `@{upstream}` can still resolve for a gone
	 *     ref in some git versions).
	 *   - Any git command error (fail-open-ish by returning 0, since the
	 *     dirty-count and merge-signal checks still apply). The intent is
	 *     to avoid blocking cleanup on exotic repo states where we can't
	 *     definitively say commits would be lost.
	 *
	 * Returns >0 when the worktree has unambiguously unpushed commits.
	 *
	 * @param string $wt_path         Worktree path.
	 * @param int    $timeout_seconds Optional git probe timeout in seconds.
	 * @return int|\WP_Error Number of unpushed commits (0 when safe or indeterminate), or timeout error.
	 */
	private function count_unpushed_commits( string $wt_path, int $timeout_seconds = 0 ): int|\WP_Error {
		if ( '' === $wt_path || ! is_dir( $wt_path ) ) {
			return 0;
		}

		// Prefer `@{push}` (respects push.default / push.remote mapping); fall
		// back to `@{upstream}` for the common case where they're the same.
		// Both expand to the tracked remote ref; if that ref is gone, this
		// returns non-zero exit and we can't compute unpushed — treat as 0
		// and let dirty / merge-signal checks handle it.
		$commands = array(
			'rev-list --count @{push}..HEAD',
			'rev-list --count @{upstream}..HEAD',
		);

		foreach ( $commands as $command ) {
			$result = $this->run_git( $wt_path, $command, $timeout_seconds );
			if ( $this->is_git_timeout_error( $result ) ) {
				return $result;
			}
			if ( ! is_wp_error( $result ) ) {
				$output = trim( (string) ( $result['output'] ?? '' ) );
				if ( '' !== $output && ctype_digit( $output ) ) {
					return (int) $output;
				}
			}
		}

		return 0;
	}

	/**
	 * Ensure the workspace directory is group-writable.
	 *
	 * Sets group ownership to www-data (or the web server's group) and
	 * permissions to 775 so that non-root users (e.g., coding agents) can
	 * write to the workspace.
	 *
	 * @param string $path Directory path.
	 */
	private function ensure_group_permissions( string $path ): void {
		// Determine the web server group. Try common groups in order of likelihood.
		$groups    = array( 'www-data', 'apache', 'nginx', 'http' );
		$web_group = null;

		foreach ( $groups as $group ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			$exists = exec( sprintf( 'getent group %s >/dev/null 2>&1 && echo 1 || echo 0', escapeshellarg( $group ) ) );
			if ( '1' === trim( $exists ) ) {
				$web_group = $group;
				break;
			}
		}

		if ( null === $web_group ) {
			return;
		}

		// Set group ownership.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'chgrp %s %s 2>/dev/null', escapeshellarg( $web_group ), escapeshellarg( $path ) ) );

		// Set permissions to 775 (rwxrwrx).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'chmod 775 %s 2>/dev/null', escapeshellarg( $path ) ) );
	}

	/**
	 * Add .htaccess protection if the workspace is inside the web root.
	 *
	 * @param string $path Directory path.
	 */
	private function protect_directory( string $path ): void {
		$fs = FilesystemHelper::get();
		// Only needed if path is under ABSPATH (web root).
		$abspath = rtrim( ABSPATH, '/' );
		if ( 0 !== strpos( $path, $abspath ) ) {
			return;
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$fs->put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			$fs->put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Fire the shared workspace-lifecycle action after a successful mutation.
	 *
	 * Listeners use this signal to refresh derived state (e.g. invalidating
	 * the composable AGENTS.md so its workspace-inventory section reflects the
	 * change). Only emitted on success, AFTER the on-disk change is durable.
	 * The payload mirrors the workspace `name`/`repo` taxonomy used elsewhere
	 * in this class:
	 *
	 *   - `op`:   one of `clone`, `adopt`, `remove`, `worktree_add`, `worktree_remove`.
	 *   - `repo`: bare repository name (no `@<slug>` suffix).
	 *   - `name`: workspace entry name on disk (`<repo>` for primaries,
	 *             `<repo>@<slug>` for worktrees).
	 *   - `path`: absolute path of the workspace entry that changed.
	 *
	 * @since 0.31.0
	 *
	 * @param string $op   Mutation kind.
	 * @param string $repo Bare repo name.
	 * @param string $name Workspace entry name (`<repo>` or `<repo>@<slug>`).
	 * @param string $path Absolute path of the entry that changed.
	 * @return void
	 */
	private function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {
		/**
		 * Fires after a successful Workspace mutation lands on disk.
		 *
		 * @since 0.31.0
		 *
		 * @param array{op: string, repo: string, name: string, path: string} $payload {
		 *     @type string $op   One of clone|adopt|remove|worktree_add|worktree_remove.
		 *     @type string $repo Bare repository name (no @-suffix).
		 *     @type string $name Workspace entry name (<repo> or <repo>@<slug>).
		 *     @type string $path Absolute path of the workspace entry.
		 * }
		 */
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action(
			'datamachine_code_workspace_changed',
			array(
				'op'   => $op,
				'repo' => $repo,
				'name' => $name,
				'path' => $path,
			)
		);
	}
}
