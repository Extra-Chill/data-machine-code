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

use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;
use DataMachineCode\Storage\WorktreeInventoryRepository;

defined('ABSPATH') || exit;

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
	 * Default cap on worktrees scanned for an artifact cleanup dry-run when no
	 * `limit` is provided. Keeps dry-run bounded and fast on huge workspaces.
	 */
	public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;

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
		if ( ! class_exists(WorktreeInventoryRepository::class) ) {
			include_once dirname(__DIR__) . '/Storage/WorktreeInventoryRepository.php';
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
		if ( defined('DATAMACHINE_WORKSPACE_PATH') ) {
			return rtrim(DATAMACHINE_WORKSPACE_PATH, '/');
		}

		$system_path   = '/var/lib/datamachine/workspace';
		$system_base   = dirname($system_path);
		$fs            = FilesystemHelper::get();
		$base_writable = $fs
		? $fs->is_writable($system_base)
		: is_writable($system_base); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable

		$parent_writable = ! $base_writable && ! file_exists($system_base) && (
		$fs
		? $fs->is_writable(dirname($system_base))
		: is_writable(dirname($system_base)) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);

		if ( $base_writable || $parent_writable ) {
			return $system_path;
		}

		// Local/macOS fallback: $HOME/.datamachine/workspace.
		// Matches the path setup.sh uses in --local mode.
		$home = getenv('HOME');
		if ( false !== $home && '' !== $home ) {
			$home_path = rtrim($home, '/') . '/.datamachine/workspace';
			$home_base = dirname($home_path);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( is_dir($home_base) && is_writable($home_base) ) {
				return $home_path;
			}

			// Base doesn't exist yet — check if $HOME/.datamachine can be created.
         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! file_exists($home_base) && is_writable(dirname($home_base)) ) {
				return $home_path;
			}
		}

		$temp_path = rtrim(sys_get_temp_dir(), '/') . '/datamachine/workspace';
		$temp_base = dirname($temp_path);
		$web_root  = defined('ABSPATH') ? realpath(ABSPATH) : false;
		$temp_root = realpath(sys_get_temp_dir());

		if ( false !== $temp_root
			&& ( false === $web_root || 0 !== strpos($temp_root . '/', rtrim($web_root, '/') . '/') )
		) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( is_dir($temp_base) && is_writable($temp_base) ) {
				return $temp_path;
			}

         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! file_exists($temp_base) && is_writable(dirname($temp_base)) ) {
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
		$is_dir      = '' !== $path && is_dir($path);
		$is_readable = '' !== $path && is_readable($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
		$entries     = ( $is_dir && $is_readable ) ? scandir($path) : false;

		return array(
			'path'        => $path,
			'configured'  => defined('DATAMACHINE_WORKSPACE_PATH'),
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
				$this->workspace_visibility_error_data($diagnostic)
			);
		}

		if ( ! $diagnostic['is_dir'] ) {
			if ( empty($diagnostic['configured']) ) {
				return null;
			}

			return new \WP_Error(
				'workspace_path_invisible',
				$this->format_workspace_visibility_message($diagnostic),
				$this->workspace_visibility_error_data($diagnostic)
			);
		}

		if ( ! $diagnostic['readable'] ) {
			return new \WP_Error(
				'workspace_path_unreadable',
				$this->format_workspace_visibility_message($diagnostic),
				$this->workspace_visibility_error_data($diagnostic)
			);
		}

		return null;
	}

	/**
	 * Build error data for workspace visibility failures.
	 *
	 * @param  array<string,mixed> $diagnostic Path visibility details.
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
	 * @param  array<string,mixed> $diagnostic Path visibility details.
	 * @return string
	 */
	private function format_workspace_visibility_message( array $diagnostic ): string {
		return sprintf(
			'Workspace path is not accessible from PHP: %s (is_dir=%s, is_readable=%s, scandir=%s). If this is a Studio/local host path, ensure the path is mounted into the PHP runtime or update DATAMACHINE_WORKSPACE_PATH to a PHP-visible workspace.',
			(string) ( $diagnostic['path'] ?? '' ),
			! empty($diagnostic['is_dir']) ? 'true' : 'false',
			! empty($diagnostic['is_readable']) ? 'true' : 'false',
			(string) ( $diagnostic['scandir'] ?? 'failed' )
		);
	}

	/**
	 * Get the full path to a workspace handle.
	 *
	 * Handles can be either a primary checkout (`<repo>`) or a worktree
	 * (`<repo>@<branch-slug>`). The directory name on disk equals the handle.
	 *
	 * @param  string $handle Workspace handle (`<repo>` or `<repo>@<branch-slug>`).
	 * @return string Full filesystem path.
	 */
	public function get_repo_path( string $handle ): string {
		$parsed = $this->parse_handle($handle);
		return $this->workspace_path . '/' . $parsed['dir_name'];
	}

	/**
	 * Parse a workspace handle into its components.
	 *
	 * Accepts either:
	 *   - `<repo>`           → primary checkout
	 *   - `<repo>@<slug>`    → worktree (slug = slugified branch name)
	 *
	 * @param  string $handle Workspace handle.
	 * @return array{repo: string, branch_slug: string|null, is_worktree: bool, dir_name: string}
	 */
	public function parse_handle( string $handle ): array {
		$handle = trim($handle);

		if ( str_contains($handle, '@') ) {
			$parts = explode('@', $handle, 2);
			$repo  = $this->sanitize_name($parts[0]);
			$slug  = $this->sanitize_slug($parts[1]);

			if ( '' !== $repo && '' !== $slug ) {
				return array(
					'repo'        => $repo,
					'branch_slug' => $slug,
					'is_worktree' => true,
					'dir_name'    => $repo . '@' . $slug,
				);
			}
		}

		$repo = $this->sanitize_name($handle);

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
	 * @param  string $branch Branch name.
	 * @return string Slug (empty if branch is invalid).
	 */
	public function slugify_branch( string $branch ): string {
		$branch = trim($branch);
		if ( '' === $branch ) {
			return '';
		}

		$slug = str_replace('/', '-', $branch);
		return $this->sanitize_slug($slug);
	}

	/**
	 * Recover blank worktree identity fields from trusted stored metadata.
	 *
	 * @param  array<string,mixed> $wt Worktree row.
	 * @return array<string,mixed>
	 */
	private function recover_worktree_identity_from_metadata( array $wt ): array {
		$handle   = (string) ( $wt['handle'] ?? '' );
		$repo     = (string) ( $wt['repo'] ?? '' );
		$branch   = (string) ( $wt['branch'] ?? '' );
		$path     = (string) ( $wt['path'] ?? '' );
		$metadata = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : array();
		$parsed   = '' !== $handle ? $this->parse_handle($handle) : array(
			'repo'        => '',
			'branch_slug' => null,
			'is_worktree' => false,
			'dir_name'    => '',
		);

		$stored    = array(
			'repo'   => isset($metadata['repo']) ? trim( (string) $metadata['repo'] ) : '',
			'branch' => isset($metadata['branch']) ? trim( (string) $metadata['branch'] ) : '',
			'path'   => isset($metadata['path']) ? rtrim(trim( (string) $metadata['path'] ), '/') : '',
		);
		$conflicts = array();
		$hydrated  = array();

		if ( '' === $repo && '' !== $stored['repo'] ) {
			if ( $parsed['repo'] === $stored['repo'] ) {
				$repo       = $stored['repo'];
				$hydrated[] = 'repo';
			} else {
				$conflicts['repo'] = array(
					'reason'      => 'metadata_repo_does_not_match_handle',
					'handle_repo' => $parsed['repo'],
					'metadata'    => $stored['repo'],
				);
			}
		} elseif ( '' !== $repo && '' !== $stored['repo'] && $repo !== $stored['repo'] ) {
			$conflicts['repo'] = array(
				'reason'   => 'metadata_repo_does_not_match_row',
				'row'      => $repo,
				'metadata' => $stored['repo'],
			);
		}

		if ( '' === $branch && '' !== $stored['branch'] ) {
			$branch_slug = (string) ( $parsed['branch_slug'] ?? '' );
			if ( '' !== $branch_slug && $this->slugify_branch($stored['branch']) === $branch_slug ) {
				$branch     = $stored['branch'];
				$hydrated[] = 'branch';
			} else {
				$conflicts['branch'] = array(
					'reason'        => 'metadata_branch_does_not_match_handle_slug',
					'handle_slug'   => $branch_slug,
					'metadata'      => $stored['branch'],
					'metadata_slug' => $this->slugify_branch($stored['branch']),
				);
			}
		} elseif ( '' !== $branch && '' !== $stored['branch'] && $branch !== $stored['branch'] ) {
			$conflicts['branch'] = array(
				'reason'   => 'metadata_branch_does_not_match_row',
				'row'      => $branch,
				'metadata' => $stored['branch'],
			);
		}

		if ( '' === $path && '' !== $stored['path'] ) {
			$stored_basename = basename($stored['path']);
			$stored_real     = realpath($stored['path']);
			$stored_real     = false !== $stored_real ? $stored_real : $stored['path'];
			$workspace_real  = realpath($this->workspace_path);
			$workspace_real  = false !== $workspace_real ? $workspace_real : $this->workspace_path;
			if ( $stored_basename === $handle && str_starts_with(rtrim($stored_real, '/'), rtrim($workspace_real, '/') . '/') ) {
				$path       = $stored['path'];
				$hydrated[] = 'path';
			} else {
				$conflicts['path'] = array(
					'reason'            => 'metadata_path_does_not_match_workspace_handle',
					'handle'            => $handle,
					'metadata'          => $stored['path'],
					'metadata_basename' => $stored_basename,
				);
			}
		} elseif ( '' !== $path && '' !== $stored['path'] ) {
			$row_path      = rtrim($path, '/');
			$metadata_path = rtrim($stored['path'], '/');
			$row_real      = realpath($row_path);
			$row_real      = false !== $row_real ? $row_real : $row_path;
			$metadata_real = realpath($metadata_path);
			$metadata_real = false !== $metadata_real ? $metadata_real : $metadata_path;
			if ( rtrim($row_real, '/') !== rtrim($metadata_real, '/') ) {
				$conflicts['path'] = array(
					'reason'   => 'metadata_path_does_not_match_row',
					'row'      => $row_path,
					'metadata' => $metadata_path,
				);
			}
		}

		return array(
			'repo'            => $repo,
			'branch'          => $branch,
			'path'            => $path,
			'hydrated_fields' => $hydrated,
			'conflicts'       => $conflicts,
			'stored_identity' => array_filter($stored, fn( $value ) => '' !== $value),
			'detached_branch' => '' === (string) ( $wt['branch'] ?? '' ) && in_array('branch', $hydrated, true),
		);
	}

	/**
	 * Sanitize a branch slug. Allows alphanumerics, dots, dashes, underscores.
	 *
	 * @param  string $slug Raw slug.
	 * @return string
	 */
	private function sanitize_slug( string $slug ): string {
		$slug = preg_replace('/[^a-zA-Z0-9._-]/', '', $slug);
		// Collapse runs of dashes for readability.
		$slug = preg_replace('/-{2,}/', '-', (string) $slug);
		return trim( (string) $slug, '-.');
	}

	/**
	 * Get the primary checkout path for a repo.
	 *
	 * @param  string $repo Repository name (no @-suffix).
	 * @return string
	 */
	public function get_primary_path( string $repo ): string {
		return $this->workspace_path . '/' . $this->sanitize_name($repo);
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
			return null !== $visible ? $visible : new \WP_Error('workspace_unavailable', 'Workspace unavailable: no writable path outside the web root.', array( 'status' => 500 ));
		}

		if ( is_dir($path) ) {
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
		$created = wp_mkdir_p($path);

		if ( ! $created ) {
			return new \WP_Error('workspace_create_failed', sprintf('Failed to create workspace directory: %s', $path), array( 'status' => 500 ));
		}

		// Set permissions for multi-user access (web server group).
		$this->ensure_group_permissions($path);

		// Add .htaccess to block web access if inside web root.
		$this->protect_directory($path);

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
	 * @param  string $repo    Primary repo directory name (for routing git commands).
	 * @param  string $branch  Branch the worktree is checked out to.
	 * @param  string $wt_path Absolute path to the worktree directory.
	 * @param  bool   $force   Pass --force to `git worktree remove`.
	 * @return array{success: bool, handle: string, message: string}|\WP_Error
	 */
	private function remove_worktree_by_path( string $repo, string $branch, string $wt_path, bool $force ): array|\WP_Error {
		$repo = $this->sanitize_name($repo);
		if ( '' === $repo ) {
			return new \WP_Error('invalid_repo', 'Repository name is required.', array( 'status' => 400 ));
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path . '/.git') ) {
			return new \WP_Error('primary_not_found', sprintf('Primary checkout for "%s" does not exist.', $repo), array( 'status' => 404 ));
		}

		if ( '' === $wt_path || ! is_dir($wt_path) ) {
			return new \WP_Error('worktree_path_missing', sprintf('Worktree path does not exist: %s', $wt_path), array( 'status' => 404 ));
		}

		// Belt-and-suspenders containment — cleanup callers already skip
		// `external` worktrees, but validate again at the blast radius.
		$validation = $this->validate_containment($wt_path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'path_outside_workspace',
				sprintf('Refusing to remove "%s": path is outside workspace (%s).', $wt_path, $validation['message'] ?? ''),
				array( 'status' => 403 )
			);
		}

		// A worktree's .git is a FILE pointing at the primary's .git dir.
		// A directory .git means we're looking at a primary checkout — never
		// touch those.
		$real_path  = (string) ( $validation['real_path'] ?? '' );
		$git_marker = rtrim($real_path, '/') . '/.git';
		if ( '' === $real_path ) {
			return new \WP_Error(
				'path_outside_workspace',
				sprintf('Refusing to remove "%s": path did not resolve inside workspace.', $wt_path),
				array( 'status' => 403 )
			);
		}
		if ( ! is_file($git_marker) ) {
			return new \WP_Error(
				'not_a_worktree',
				sprintf('Refusing to remove "%s": .git is not a worktree marker file (got: %s). This may be a primary checkout.', $wt_path, is_dir($git_marker) ? 'directory' : 'missing'),
				array( 'status' => 403 )
			);
		}

		$cmd    = sprintf('worktree remove %s%s', $force ? '--force ' : '', escapeshellarg($real_path));
		$result = $this->run_git($primary_path, $cmd);

		if ( is_wp_error($result) ) {
			return $result;
		}

		// If the directory survived `git worktree remove` (can happen for
		// locked worktrees, or when the worktree was already detached), prune
		// the directory manually so cleanup is effective.
		if ( is_dir($real_path) ) {
			$escaped = escapeshellarg($real_path);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec(sprintf('rm -rf %s 2>&1', $escaped));
		}

		WorktreeContextInjector::forget_metadata(basename($wt_path));
		$this->worktree_inventory()->delete(basename($wt_path));

		return array(
			'success' => true,
			'handle'  => basename($wt_path),
			'message' => sprintf('Worktree at "%s" removed.', $wt_path),
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
	 * @param  string                  $repo                      Repo directory name.
	 * @param  string                  $branch                    Branch name.
	 * @param  string                  $wt_path                   Worktree path.
	 * @param  bool                    $skip_github               Whether to skip GitHub API lookups.
	 * @param  array<string,mixed>     $github_cache              Run-local GitHub cache.
	 * @param  array<string,bool>      $fetched                   Per-repo fetch tracker.
	 * @param  array<string,\WP_Error> $fetch_timeouts            Per-repo fetch timeout tracker.
	 * @param  mixed                   $metadata                  Worktree metadata.
	 * @param  bool                    $include_repaired_metadata Whether repaired metadata counts as a cleanup signal.
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
		if ( '' === $repo || '' === $branch || '' === $wt_path || ! is_dir($wt_path) ) {
			return null;
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path . '/.git') ) {
			return null;
		}

		// Refuse to classify if a previous worktree already saw this repo's
		// fetch time out — the default-ref / merge-signal probes would race
		// against stale data.
		if ( isset($fetch_timeouts[ $repo ]) ) {
			return null;
		}

		// Ensure remote refs are fresh once per repo per cleanup run. Reuses
		// the caller's `$fetched` tracker so this never double-fetches.
		if ( empty($fetched[ $repo ]) ) {
			$fetch = $this->run_git($primary_path, 'fetch --prune --quiet origin', self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( is_wp_error($fetch) && $this->is_git_timeout_error($fetch) ) {
				$fetch_timeouts[ $repo ] = $fetch;
				return null;
			}
			$fetched[ $repo ] = true;
		}

		$default_ref = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $default_ref instanceof \WP_Error || null === $default_ref || '' === $default_ref ) {
			return null;
		}

		// Confirm the default ref actually resolves to a commit. If it doesn't,
		// every `cat-file -e <ref>:<path>` would fail and we'd mis-classify the
		// whole worktree as obsolete-on-default.
		$default_resolve = $this->run_git(
			$primary_path,
			sprintf('rev-parse --verify --quiet %s', escapeshellarg($default_ref . '^{commit}')),
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( is_wp_error($default_resolve) ) {
			return null;
		}

		$signal = null;
		if ( is_array($metadata) && WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			$signal = array(
				'signal' => 'cleanup_eligible',
				'reason' => 'worktree finalized or explicitly marked cleanup_eligible',
			);
			if ( ! empty($metadata['pr_url']) ) {
				$signal['pr_url'] = (string) $metadata['pr_url'];
			}
		} elseif ( $include_repaired_metadata && is_array($metadata) && ! empty($metadata['metadata_repaired']) ) {
			$signal = array(
				'signal' => 'repaired_metadata',
				'reason' => 'operator-approved cleanup of repaired metadata',
			);
		} else {
			$signal = $this->detect_merge_signal($primary_path, $repo, $branch, $skip_github, $github_cache);
		}

		if ( ! is_array($signal) ) {
			return null;
		}
		$signal_kind    = (string) $signal['signal'];
		$merged_signals = array( 'upstream-gone', 'local-merged', 'pr-merged', 'cleanup_eligible', 'repaired_metadata' );
		if ( ! in_array($signal_kind, $merged_signals, true) ) {
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
		if ( $this->is_git_timeout_error($untracked) ) {
			return null;
		}
		if ( ! is_wp_error($untracked) && '' !== trim( (string) ( $untracked['output'] ?? '' )) ) {
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
		if ( is_wp_error($tracked) ) {
			return null;
		}

		$paths = array_values(
			array_filter(
				array_map('trim', explode("\n", (string) ( $tracked['output'] ?? '' ))),
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
				sprintf('cat-file -e %s', escapeshellarg($default_ref . ':' . $path)),
				self::CLEANUP_GIT_PROBE_TIMEOUT
			);
			if ( is_wp_error($probe) && $this->is_git_timeout_error($probe) ) {
				return null;
			}
			if ( is_wp_error($probe) ) {
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
	 * @param  string $primary_path Path to the primary git checkout.
	 * @param  string $repo         Primary repo directory name.
	 * @param  string $branch       Branch name.
	 * @param  bool   $skip_github  If true, skip GitHub API lookup.
	 * @param  array<string,mixed> $github_cache Run-local cache for GitHub repo lookups.
	 * @return array{signal: string, reason: string, pr_url?: string}|null
	 */
	private function detect_merge_signal( string $primary_path, string $repo, string $branch, bool $skip_github, array &$github_cache = array() ): ?array {
		$ref    = 'refs/heads/' . $branch;
		$format = '%(upstream:track)';
		$result = $this->run_git($primary_path, sprintf('for-each-ref --format=%s %s', escapeshellarg($format), escapeshellarg($ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);

		if ( is_wp_error($result) && $this->is_git_timeout_error($result) ) {
			return array(
				'signal' => 'probe-timeout',
				'reason' => $result->get_error_message(),
			);
		}

		if ( ! is_wp_error($result) ) {
			$track = trim( (string) ( $result['output'] ?? '' ));
			if ( str_contains($track, 'gone') ) {
				return array(
					'signal' => 'upstream-gone',
					'reason' => 'remote branch deleted (likely merged + auto-deleted)',
				);
			}
		}

		$local_merged = $this->detect_local_merged_signal($primary_path, $branch);
		if ( null !== $local_merged ) {
			return $local_merged;
		}

		if ( $skip_github ) {
			return null;
		}

		$gh_slug = $this->resolve_github_slug($primary_path);
		if ( null === $gh_slug ) {
			return null;
		}

		$pr = $this->find_closed_pr_for_branch($gh_slug, $branch, $github_cache);
		if ( is_wp_error($pr) ) {
			return array(
				'signal' => 'github-unknown',
				'reason' => 'unknown_github_state — ' . $pr->get_error_message(),
			);
		}
		if ( null === $pr ) {
			return null;
		}

		if ( ! empty($pr['merged_at']) ) {
			return array(
				'signal'          => 'pr-merged',
				'reason'          => sprintf('PR #%d merged (%s)', $pr['number'], $pr['state']),
				'finalized_state' => WorktreeContextInjector::STATE_MERGED,
				'pr_url'          => $pr['html_url'] ?? null,
			);
		}

		return array(
			'signal'          => 'pr-closed',
			'reason'          => sprintf('PR #%d closed without merge', $pr['number']),
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
	 * @param  string $primary_path Path to the primary git checkout.
	 * @param  string $branch       Branch name.
	 * @return array{signal: string, reason: string}|null
	 */
	private function detect_local_merged_signal( string $primary_path, string $branch ): ?array {
		$default_ref = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
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
			sprintf('rev-list --count %s..%s', escapeshellarg($default_ref), escapeshellarg($branch_ref)),
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( is_wp_error($result) && $this->is_git_timeout_error($result) ) {
			return array(
				'signal' => 'probe-timeout',
				'reason' => $result->get_error_message(),
			);
		}
		if ( is_wp_error($result) ) {
			return null;
		}

		$unique_commits = (int) trim( (string) ( $result['output'] ?? '' ));
		if ( 0 !== $unique_commits ) {
			return null;
		}

		return array(
			'signal' => 'local-merged',
			'reason' => sprintf('branch has no commits outside remote default (%s)', $default_ref),
		);
	}

	/**
	 * Resolve the remote default branch ref for local cleanup checks.
	 *
	 * @param  string $primary_path Path to the primary git checkout.
	 * @return string|\WP_Error|null Fully-qualified remote default ref, timeout error, or null when unavailable.
	 */
	private function resolve_remote_default_ref( string $primary_path, int $timeout_seconds = 0 ): string|\WP_Error|null {
		$result = $this->run_git($primary_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD', $timeout_seconds);
		if ( is_wp_error($result) ) {
			return $this->is_git_timeout_error($result) ? $result : null;
		}

		$ref = trim( (string) ( $result['output'] ?? '' ));
		return '' === $ref ? null : $ref;
	}

	/**
	 * Extract owner/repo slug from a primary checkout's origin remote.
	 *
	 * @param  string $primary_path Primary checkout path.
	 * @return string|null `owner/repo` or null if origin is not a GitHub URL.
	 */
	private function resolve_github_slug( string $primary_path ): ?string {
		$remote = $this->git_get_remote($primary_path);
		if ( null === $remote || '' === $remote ) {
			return null;
		}
		return GitHubRemote::slug($remote);
	}

	/**
	 * Look up a closed PR for a branch via a cached GitHub API snapshot.
	 *
	 * Cleanup may inspect hundreds of worktrees for the same repo. Querying
	 * GitHub once per branch does not scale, so each repo gets one bounded
	 * closed-PR snapshot per cleanup run and branch lookups read that cache.
	 *
	 * @param  string $slug         owner/repo.
	 * @param  string $branch       Branch name.
	 * @param  array<string,mixed> $github_cache Run-local cache keyed by owner/repo.
	 * @return array|null|\WP_Error PR data, null when no PR matched, or lookup failure.
	 */
	private function find_closed_pr_for_branch( string $slug, string $branch, array &$github_cache = array() ): array|\WP_Error|null {
		$lookup = $this->get_cleanup_github_lookup($slug, $github_cache);
		if ( is_wp_error($lookup) ) {
			return $lookup;
		}

		if ( null !== $lookup && isset($lookup[ $branch ]) ) {
			return $lookup[ $branch ];
		}

		return $this->find_pr_for_branch_direct($slug, $branch, $github_cache, true);
	}

	/**
	 * Look up a PR for one branch directly via GitHub's head filter.
	 *
	 * The repo-level closed-PR snapshot is intentionally bounded for cleanup runs,
	 * so older PRs can be missed. This precise fallback keeps PR lifecycle as the
	 * source of truth without treating remote branch existence as liveness.
	 *
	 * @param  string $slug           owner/repo.
	 * @param  string $branch         Branch name.
	 * @param  array<string,mixed> $github_cache   Run-local cache keyed by owner/repo and branch.
	 * @param  bool   $finalized_only If true, ignore open PRs.
	 * @return array|null|\WP_Error PR data, null when no matching PR exists, or lookup failure.
	 */
	private function find_pr_for_branch_direct( string $slug, string $branch, array &$github_cache = array(), bool $finalized_only = true ): array|\WP_Error|null {
		$cache_key = $slug . '#head:' . ( $finalized_only ? 'finalized:' : 'any:' ) . $branch;
		if ( array_key_exists($cache_key, $github_cache) ) {
			return $github_cache[ $cache_key ];
		}

		if ( ! class_exists('\DataMachineCode\Abilities\GitHubAbilities') ) {
			$github_cache[ $cache_key ] = null;
			return null;
		}

		$parts = explode('/', $slug, 2);
		$owner = $parts[0];
		if ( '' === $owner || empty($parts[1]) ) {
			$github_cache[ $cache_key ] = null;
			return null;
		}

		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat(array( 'repo' => $slug ));
		if ( empty($pat) ) {
			$github_cache[ $cache_key ] = null;
			return null;
		}

		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl($slug, 'pulls'),
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

		if ( is_wp_error($response) ) {
			$error                      = new \WP_Error(
				'github_cleanup_branch_lookup_failed',
				sprintf('GitHub cleanup branch lookup failed for %s:%s: %s', $slug, $branch, $response->get_error_message()),
				$response->get_error_data()
			);
			$github_cache[ $cache_key ] = $error;
			return $error;
		}

		foreach ( (array) ( $response['data'] ?? array() ) as $pr ) {
			if ( ! is_array($pr) ) {
				continue;
			}

			$head      = is_array($pr['head'] ?? null) ? $pr['head'] : array();
			$head_repo = is_array($head['repo'] ?? null) ? (string) ( $head['repo']['full_name'] ?? '' ) : '';
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
	 * @param  string $slug         owner/repo.
	 * @param  array<string,mixed> $github_cache Run-local cache keyed by owner/repo.
	 * @return array<string,array>|null|\WP_Error Branch-name map, null when GitHub is unavailable, or lookup failure.
	 */
	private function get_cleanup_github_lookup( string $slug, array &$github_cache ): array|\WP_Error|null {
		if ( array_key_exists($slug, $github_cache) ) {
			return $github_cache[ $slug ];
		}

		if ( ! class_exists('\DataMachineCode\Abilities\GitHubAbilities') ) {
			$github_cache[ $slug ] = null;
			return null;
		}

		// Pass the repo through so credential profiles with `allowed_repos`
		// can win over the global default profile when scanning closed PRs.
		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat(array( 'repo' => $slug ));
		if ( empty($pat) ) {
			$github_cache[ $slug ] = null;
			return null;
		}

		$parts = explode('/', $slug, 2);
		$owner = $parts[0];
		if ( '' === $owner || empty($parts[1]) ) {
			$github_cache[ $slug ] = null;
			return null;
		}

		$closed = array();
		$url    = GitHubRemote::apiUrl($slug, 'pulls');

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

			if ( is_wp_error($response) ) {
				$error                     = new \WP_Error(
					'github_cleanup_lookup_failed',
					sprintf('GitHub cleanup lookup failed for %s: %s', $slug, $response->get_error_message()),
					$response->get_error_data()
				);
					$github_cache[ $slug ] = $error;
					return $error;
			}

			$items = (array) ( $response['data'] ?? array() );
			foreach ( $items as $pr ) {
				$head      = is_array($pr['head'] ?? null) ? $pr['head'] : array();
				$head_repo = is_array($head['repo'] ?? null) ? (string) ( $head['repo']['full_name'] ?? '' ) : '';
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

			if ( count($items) < 100 ) {
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
	 * @param  string $target    Path to validate.
	 * @param  string $container Parent directory that must contain the target.
	 * @return array{valid: bool, real_path?: string, message?: string}
	 */
	public function validate_containment( string $target, string $container ): array {
		return PathSecurity::validateContainment($target, $container);
	}

	/**
	 * Derive a repo name from a git URL.
	 *
	 * @param  string $url Git URL.
	 * @return string|null Derived name or null.
	 */
	protected function derive_repo_name( string $url ): ?string {
		// Handle https://github.com/org/repo.git and git@github.com:org/repo.git
		$name = basename($url);
		$name = preg_replace('/\.git$/', '', $name);
		$name = $this->sanitize_name($name);

		return ( '' !== $name ) ? $name : null;
	}

	/**
	 * Sanitize a directory name for use in the workspace.
	 *
	 * @param  string $name Raw name.
	 * @return string Sanitized name (alphanumeric, hyphens, underscores, dots).
	 */
	private function sanitize_name( string $name ): string {
		return preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
	}

	/**
	 * Run a git command in a repository.
	 *
	 * @param  string $repo_path       Resolved repository path.
	 * @param  string $git_args        Git arguments (without leading "git").
	 * @param  int    $timeout_seconds Optional timeout in seconds.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_git( string $repo_path, string $git_args, int $timeout_seconds = 0 ): array|\WP_Error {
		return GitRunner::run($repo_path, $git_args, $timeout_seconds);
	}

	/**
	 * Determine whether a git result is a timeout error.
	 *
	 * @param  mixed $result Git result.
		 * @return bool
		 */
	private function is_git_timeout_error( mixed $result ): bool {
		if ( ! is_wp_error($result) ) {
			return false;
		}

		return 'git_command_timeout' === $result->get_error_code();
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
	 * @param  string $wt_path         Worktree path.
	 * @param  int    $timeout_seconds Optional git probe timeout in seconds.
	 * @return int|\WP_Error Number of unpushed commits (0 when safe or indeterminate), or timeout error.
	 */
	private function count_unpushed_commits( string $wt_path, int $timeout_seconds = 0 ): int|\WP_Error {
		if ( '' === $wt_path || ! is_dir($wt_path) ) {
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
			$result = $this->run_git($wt_path, $command, $timeout_seconds);
			if ( is_wp_error($result) ) {
				if ( $this->is_git_timeout_error($result) ) {
					return $result;
				}
				continue;
			}

			$output = trim( (string) ( $result['output'] ?? '' ));
			if ( '' !== $output && ctype_digit($output) ) {
				return (int) $output;
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
			$exists = exec(sprintf('getent group %s >/dev/null 2>&1 && echo 1 || echo 0', escapeshellarg($group)));
			$exists = false === $exists ? '' : $exists;
			if ( '1' === trim($exists) ) {
				$web_group = $group;
				break;
			}
		}

		if ( null === $web_group ) {
			return;
		}

		// Set group ownership.
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('chgrp %s %s 2>/dev/null', escapeshellarg($web_group), escapeshellarg($path)));

		// Set permissions to 775 (rwxrwrx).
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('chmod 775 %s 2>/dev/null', escapeshellarg($path)));
	}

	/**
	 * Add .htaccess protection if the workspace is inside the web root.
	 *
	 * @param string $path Directory path.
	 */
	private function protect_directory( string $path ): void {
		$fs = FilesystemHelper::get();
		// Only needed if path is under ABSPATH (web root).
		$abspath = rtrim(ABSPATH, '/');
		if ( 0 !== strpos($path, $abspath) ) {
			return;
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists($htaccess) ) {
			$fs->put_contents($htaccess, "Deny from all\n");
		}

		$index = $path . '/index.php';
		if ( ! file_exists($index) ) {
			$fs->put_contents($index, "<?php\n// Silence is golden.\n");
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
	 * @param  string $op   Mutation kind.
	 * @param  string $repo Bare repo name.
	 * @param  string $name Workspace entry name (`<repo>` or `<repo>@<slug>`).
	 * @param  string $path Absolute path of the entry that changed.
	 * @return void
	 */
	protected function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {
		/**
		 * Fires after a successful Workspace mutation lands on disk.
		 *
		 * @since 0.31.0
		 *
		 * @param array{op: string, repo: string, name: string, path: string} $payload {
		 *     @type  string $op   One of clone|adopt|remove|worktree_add|worktree_remove.
		 *     @type  string $repo Bare repository name (no @-suffix).
		 *     @type  string $name Workspace entry name (<repo> or <repo>@<slug>).
		 *     @type  string $path Absolute path of the workspace entry.
		 * }
		 */
		if ( ! function_exists('do_action') ) {
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
