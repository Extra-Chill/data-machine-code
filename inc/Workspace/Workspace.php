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

class Workspace {

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
	 * 4. Empty string (no workspace available)
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
				'Workspace unavailable: no writable path outside the web root. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php, ensure /var/lib/datamachine/ is writable, or ensure $HOME is set.',
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

	/**
	 * List repositories in the workspace.
	 *
	 * @return array{success: bool, repos: array, path: string}|\WP_Error
	 */
	public function list_repos(): array|\WP_Error {
		$path    = $this->workspace_path;
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		if ( ! is_dir( $path ) ) {
			return array(
				'success' => true,
				'repos'   => array(),
				'path'    => $path,
			);
		}

		$repos   = array();
		$entries = scandir( $path );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $path . '/' . $entry;
			if ( ! is_dir( $entry_path ) ) {
				continue;
			}

			$git_path = $entry_path . '/.git';
			$is_git   = is_dir( $git_path ) || is_file( $git_path );
			$is_wt    = is_file( $git_path );
			$parsed   = $this->parse_handle( $entry );

			$repo_info = array(
				'name'        => $entry,
				'path'        => $entry_path,
				'git'         => $is_git,
				'is_worktree' => $is_wt || $parsed['is_worktree'],
				'repo'        => $parsed['repo'],
			);

			if ( $parsed['is_worktree'] ) {
				$repo_info['branch_slug'] = $parsed['branch_slug'];
			}

			// Get git remote if available.
			if ( $is_git ) {
				$remote = $this->git_get_remote( $entry_path );
				if ( null !== $remote ) {
					$repo_info['remote'] = $remote;
				}

				$branch = $this->git_get_branch( $entry_path );
				if ( null !== $branch ) {
					$repo_info['branch'] = $branch;
				}
			}

			$repos[] = $repo_info;
		}

		return array(
			'success' => true,
			'repos'   => $repos,
			'path'    => $path,
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * @param string      $url  Git clone URL.
	 * @param string|null $name Directory name override (derived from URL if null).
	 * @return array{success: bool, name?: string, path?: string, message?: string}|\WP_Error
	 */
	public function clone_repo( string $url, ?string $name = null ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		// Validate URL.
		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', 'Repository URL is required.', array( 'status' => 400 ) );
		}

		// Derive name from URL if not provided.
		if ( null === $name || '' === $name ) {
			$name = $this->derive_repo_name( $url );
			if ( null === $name ) {
				return new \WP_Error( 'invalid_url', sprintf( 'Could not derive repository name from URL: %s. Use --name to specify.', $url ), array( 'status' => 400 ) );
			}
		}

		// Reject @-suffixed names — those are reserved for worktrees.
		if ( str_contains( $name, '@' ) ) {
			return new \WP_Error( 'invalid_clone_name', 'Repository names cannot contain "@". The "@<branch-slug>" suffix is reserved for worktrees (use "workspace worktree add" instead).', array( 'status' => 400 ) );
		}

		$name      = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $name;

		// Check if already exists.
		if ( is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_exists', sprintf( 'Directory already exists: %s. Use "remove" first to re-clone.', $name ), array( 'status' => 400 ) );
		}

		// Ensure workspace exists.
		$ensure = $this->ensure_exists();
		if ( is_wp_error( $ensure ) ) {
			return $ensure;
		}

		// Clone.
		$escaped_url  = escapeshellarg( $url );
		$escaped_path = escapeshellarg( $repo_path );
		$command      = sprintf( 'git clone %s %s 2>&1', $escaped_url, $escaped_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return new \WP_Error( 'clone_failed', sprintf( 'Git clone failed (exit %d): %s', $exit_code, implode( "\n", $output ) ), array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'name'    => $name,
			'path'    => $repo_path,
			'message' => sprintf( 'Cloned %s into workspace as "%s".', $url, $name ),
		);
	}

	/**
	 * Adopt an existing primary checkout already located in the workspace.
	 *
	 * There is no persistent primary registry today; primary checkouts are
	 * discovered by their on-disk directory names. Adoption is therefore a
	 * non-destructive validation step that makes that convention explicit.
	 *
	 * @param string      $path Existing checkout path.
	 * @param string|null $name Workspace name override (derived from basename if null).
	 * @return array{success: bool, name?: string, path?: string, already_adopted?: bool, message?: string}|\WP_Error
	 */
	public function adopt_repo( string $path, ?string $name = null ): array|\WP_Error {
		$path = rtrim( trim( $path ), '/' );
		if ( '' === $path ) {
			return new \WP_Error( 'missing_path', 'Checkout path is required.', array( 'status' => 400 ) );
		}

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'adopt_path_unreadable', sprintf( 'Checkout path does not exist or is not readable: %s', $path ), array( 'status' => 400 ) );
		}

		$ensure = $this->ensure_exists();
		if ( is_wp_error( $ensure ) ) {
			return $ensure;
		}

		$validation = $this->validate_containment( $path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return new \WP_Error( 'adopt_outside_workspace', 'Only checkouts already under DATAMACHINE_WORKSPACE_PATH can be adopted.', array( 'status' => 400 ) );
		}

		$real_path = $validation['real_path'] ?? '';
		if ( '' === $real_path ) {
			return new \WP_Error( 'adopt_path_unresolved', sprintf( 'Could not resolve checkout path: %s', $path ), array( 'status' => 400 ) );
		}

		$git_path = $real_path . '/.git';
		if ( is_file( $git_path ) ) {
			return new \WP_Error( 'adopt_linked_worktree', 'Cannot adopt a linked worktree as a primary checkout. Pass the primary checkout path instead.', array( 'status' => 400 ) );
		}

		if ( ! is_dir( $git_path ) ) {
			return new \WP_Error( 'adopt_not_git_primary', sprintf( 'Path is not a git primary checkout: %s', $real_path ), array( 'status' => 400 ) );
		}

		if ( null === $name || '' === trim( $name ) ) {
			$name = basename( $real_path );
		}

		if ( str_contains( $name, '@' ) ) {
			return new \WP_Error( 'invalid_adopt_name', 'Repository names cannot contain "@". The "@<branch-slug>" suffix is reserved for worktrees.', array( 'status' => 400 ) );
		}

		$name = $this->sanitize_name( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_adopt_name', 'Adopted repository name is empty after sanitization.', array( 'status' => 400 ) );
		}

		$expected_path = $this->workspace_path . '/' . $name;
		if ( is_dir( $expected_path ) ) {
			$expected_real = realpath( $expected_path );
			if ( false !== $expected_real && $expected_real !== $real_path ) {
				return new \WP_Error( 'adopt_name_collision', sprintf( 'Workspace name "%s" already points at a different directory: %s', $name, $expected_real ), array( 'status' => 400 ) );
			}
		} else {
			return new \WP_Error( 'adopt_requires_workspace_path', sprintf( 'Adoption is non-destructive: %s must already be located at %s. Move or symlink operations are intentionally not performed by v1.', $real_path, $expected_path ), array( 'status' => 400 ) );
		}

		return array(
			'success'         => true,
			'name'            => $name,
			'path'            => $real_path,
			'already_adopted' => true,
			'message'         => sprintf( 'Workspace checkout "%s" is already adopted at %s. No filesystem changes were made.', $name, $real_path ),
		);
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * @param string $handle Workspace handle.
	 * @return array{success: bool, message: string}|\WP_Error
	 */
	public function remove_repo( string $handle ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Workspace handle "%s" not found.', $parsed['dir_name'] ), array( 'status' => 404 ) );
		}

		// Safety: ensure path is within workspace.
		$validation = $this->validate_containment( $repo_path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return new \WP_Error( 'path_traversal', $validation['message'], array( 'status' => 403 ) );
		}

		// Refuse to remove a primary that still has live worktrees attached.
		if ( ! $parsed['is_worktree'] ) {
			$worktrees = $this->worktree_list( $parsed['repo'] );
			if ( ! is_wp_error( $worktrees ) ) {
				$linked = array_filter( $worktrees['worktrees'], fn( $wt ) => ! empty( $wt['is_worktree'] ) );
				if ( ! empty( $linked ) ) {
					$slugs = array_map( fn( $wt ) => $wt['branch_slug'] ?? '?', $linked );
					return new \WP_Error( 'has_worktrees', sprintf( 'Cannot remove primary "%s": linked worktrees exist (%s). Remove them first with "workspace worktree remove".', $parsed['repo'], implode( ', ', $slugs ) ), array( 'status' => 400 ) );
				}
			}
		}

		// Remove recursively.
		$escaped = escapeshellarg( $validation['real_path'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'rm -rf %s 2>&1', $escaped ), $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return new \WP_Error( 'remove_failed', sprintf( 'Failed to remove (exit %d): %s', $exit_code, implode( "\n", $output ) ), array( 'status' => 500 ) );
		}

		// If we removed a worktree directory but didn't go through `git worktree remove`,
		// prune the registry on the primary so it doesn't keep stale entries.
		if ( $parsed['is_worktree'] ) {
			$primary_path = $this->get_primary_path( $parsed['repo'] );
			if ( is_dir( $primary_path . '/.git' ) ) {
				WorkspaceMutationLock::with_repo(
					$this->workspace_path,
					$parsed['repo'],
					fn() => $this->run_git( $primary_path, 'worktree prune' )
				);
			}
			$this->worktree_inventory()->delete( $parsed['dir_name'] );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Removed "%s" from workspace.', $parsed['dir_name'] ),
		);
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * @param string $handle Workspace handle.
	 * @return array{success: bool, name?: string, path?: string, branch?: string, remote?: string, commit?: string, dirty?: int}|\WP_Error
	 */
	public function show_repo( string $handle ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Workspace handle "%s" not found.', $parsed['dir_name'] ), array( 'status' => 404 ) );
		}

		$escaped = escapeshellarg( $repo_path );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = trim( (string) exec( sprintf( 'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped ) ) );
		$remote = trim( (string) exec( sprintf( 'git -C %s config --get remote.origin.url 2>/dev/null', $escaped ) ) );
		$commit = trim( (string) exec( sprintf( 'git -C %s log -1 --format="%%h %%s" 2>/dev/null', $escaped ) ) );
		$status = trim( (string) exec( sprintf( 'git -C %s status --porcelain 2>/dev/null | wc -l', $escaped ) ) );
		// phpcs:enable

		return array(
			'success'     => true,
			'name'        => $parsed['dir_name'],
			'repo'        => $parsed['repo'],
			'is_worktree' => $parsed['is_worktree'],
			'path'        => $repo_path,
			'branch'      => $branch ? $branch : null,
			'remote'      => $remote ? $remote : null,
			'commit'      => $commit ? $commit : null,
			'dirty'       => (int) $status,
		);
	}

	/**
	 * Get git status details for a workspace repository.
	 *
	 * @param string $handle Workspace handle.
	 * @return array
	 */
	public function git_status( string $handle ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_path = $this->resolve_repo_path( $handle );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$status_result = $this->run_git( $repo_path, 'status --porcelain' );
		if ( is_wp_error( $status_result ) ) {
			return $status_result;
		}

		$branch_result = $this->run_git( $repo_path, 'rev-parse --abbrev-ref HEAD' );
		$remote_result = $this->run_git( $repo_path, 'config --get remote.origin.url' );
		$latest_result = $this->run_git( $repo_path, 'log -1 --format="%h %s"' );

		$files = array_filter( array_map( 'trim', explode( "\n", $status_result['output'] ?? '' ) ) );

		return array(
			'success'     => true,
			'name'        => $parsed['dir_name'],
			'repo'        => $parsed['repo'],
			'is_worktree' => $parsed['is_worktree'],
			'path'        => $repo_path,
			'branch'      => ! is_wp_error( $branch_result ) ? trim( (string) $branch_result['output'] ) : null,
			'remote'      => ! is_wp_error( $remote_result ) ? trim( (string) $remote_result['output'] ) : null,
			'commit'      => ! is_wp_error( $latest_result ) ? trim( (string) $latest_result['output'] ) : null,
			'dirty'       => count( $files ),
			'files'       => array_values( $files ),
		);
	}

	/**
	 * Pull latest changes for a workspace repository.
	 *
	 * @param string $handle      Workspace handle.
	 * @param bool   $allow_dirty Allow pull with dirty working tree.
	 * @return array
	 */
	public function git_pull( string $handle, bool $allow_dirty = false, bool $allow_primary_mutation = false ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_path = $this->resolve_repo_path( $handle );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $parsed['repo'] );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed( $parsed, $allow_primary_mutation );
		if ( is_wp_error( $primary_check ) ) {
			return $primary_check;
		}

		$status = $this->git_status( $handle );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		if ( ! $allow_dirty && ( $status['dirty'] ?? 0 ) > 0 ) {
			return new \WP_Error( 'dirty_working_tree', 'Working tree is dirty. Commit/stash changes first or pass allow_dirty=true.', array( 'status' => 400 ) );
		}

		$result = $this->run_git( $repo_path, 'pull --ff-only' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => trim( (string) $result['output'] ),
			'name'    => $parsed['dir_name'],
		);
	}

	/**
	 * Stage paths in a workspace repository.
	 *
	 * @param string $handle Workspace handle.
	 * @param array  $paths Relative paths to stage.
	 * @return array
	 */
	public function git_add( string $handle, array $paths, bool $allow_primary_mutation = false ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path( $handle );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed( $parsed, $allow_primary_mutation );
		if ( is_wp_error( $primary_check ) ) {
			return $primary_check;
		}

		if ( empty( $paths ) ) {
			return new \WP_Error( 'missing_paths', 'At least one path is required for git add.', array( 'status' => 400 ) );
		}

		// Allowed paths are opt-in: when configured, they restrict which relative
		// paths may be staged; when absent, any path within the repo is allowed.
		// This mirrors ensure_git_mutation_allowed's permissive-by-default model.
		// Sensitive-path + traversal checks still apply unconditionally.
		$allowed_roots = $this->get_repo_allowed_paths( $repo_name );

		$clean_paths = array();
		foreach ( $paths as $path ) {
			$relative = trim( (string) $path );
			if ( '' === $relative ) {
				continue;
			}

			if ( $this->has_traversal( $relative ) || str_starts_with( $relative, '/' ) ) {
				return new \WP_Error( 'invalid_path', sprintf( 'Invalid path for git add: %s', $relative ), array( 'status' => 400 ) );
			}

			if ( $this->is_sensitive_path( $relative ) ) {
				return new \WP_Error( 'sensitive_path', sprintf( 'Refusing to stage sensitive path: %s', $relative ), array( 'status' => 403 ) );
			}

			// Only enforce the allowlist when one has been configured.
			if ( ! empty( $allowed_roots ) && ! $this->is_path_allowed( $relative, $allowed_roots ) ) {
				return new \WP_Error( 'path_not_allowed', sprintf( 'Path "%s" is outside configured allowlist.', $relative ), array( 'status' => 403 ) );
			}

			$clean_paths[] = $relative;
		}

		if ( empty( $clean_paths ) ) {
			return new \WP_Error( 'no_valid_paths', 'No valid paths provided for git add.', array( 'status' => 400 ) );
		}

		$escaped_paths = array_map( 'escapeshellarg', $clean_paths );
		$result        = $this->run_git( $repo_path, 'add -- ' . implode( ' ', $escaped_paths ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $repo_name,
			'paths'   => $clean_paths,
			'message' => 'Paths staged successfully.',
		);
	}

	/**
	 * Delete a tracked or untracked path inside a workspace repository.
	 *
	 * Tracked paths are removed via `git rm` so the deletion lands in the
	 * working tree and the index in one shot. Untracked paths fall back to a
	 * filesystem wp_delete_file( file ) or a recursive directory removal (directory,
	 * only when $recursive is true). Sensitive-path, traversal, allowlist,
	 * and primary-mutation gates mirror `git_add`.
	 *
	 * @param string $handle                 Workspace handle.
	 * @param string $path                   Relative path within the repo.
	 * @param bool   $recursive              Required when target is a directory.
	 * @param bool   $allow_primary_mutation Whether the primary checkout may be mutated.
	 * @return array{success: bool, name: string, repo: string, path: string, deleted: array<int,string>, was_tracked: bool}|\WP_Error
	 */
	public function delete_path( string $handle, string $path, bool $recursive = false, bool $allow_primary_mutation = false ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path( $handle );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed( $parsed, $allow_primary_mutation );
		if ( is_wp_error( $primary_check ) ) {
			return $primary_check;
		}

		$relative = trim( $path );
		if ( '' === $relative ) {
			return new \WP_Error( 'missing_path', 'Path is required for delete.', array( 'status' => 400 ) );
		}

		if ( $this->has_traversal( $relative ) || str_starts_with( $relative, '/' ) ) {
			return new \WP_Error( 'invalid_path', sprintf( 'Invalid path for delete: %s', $relative ), array( 'status' => 400 ) );
		}

		if ( $this->is_sensitive_path( $relative ) ) {
			return new \WP_Error( 'sensitive_path', sprintf( 'Refusing to delete sensitive path: %s', $relative ), array( 'status' => 403 ) );
		}

		$allowed_roots = $this->get_repo_allowed_paths( $repo_name );
		if ( ! empty( $allowed_roots ) && ! $this->is_path_allowed( $relative, $allowed_roots ) ) {
			return new \WP_Error( 'path_not_allowed', sprintf( 'Path "%s" is outside configured allowlist.', $relative ), array( 'status' => 403 ) );
		}

		$absolute = $repo_path . '/' . $relative;
		if ( ! file_exists( $absolute ) && ! is_link( $absolute ) ) {
			return new \WP_Error( 'not_found', sprintf( 'Path not found: %s', $relative ), array( 'status' => 404 ) );
		}

		$is_dir = is_dir( $absolute ) && ! is_link( $absolute );
		if ( $is_dir && ! $recursive ) {
			return new \WP_Error( 'directory_requires_recursive', sprintf( 'Path "%s" is a directory; pass recursive=true to delete.', $relative ), array( 'status' => 400 ) );
		}

		$ls_files   = $this->run_git( $repo_path, 'ls-files --error-unmatch -- ' . escapeshellarg( $relative ) );
		$is_tracked = ! is_wp_error( $ls_files );

		$deleted = array();
		if ( $is_tracked ) {
			$flags  = $is_dir ? '-r ' : '';
			$result = $this->run_git( $repo_path, 'rm ' . $flags . '-- ' . escapeshellarg( $relative ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( explode( "\n", $result['output'] ?? '' ) as $line ) {
				if ( preg_match( '/^rm \'(.+)\'$/', trim( $line ), $matches ) ) {
					$deleted[] = $matches[1];
				}
			}
			if ( empty( $deleted ) ) {
				$deleted[] = $relative;
			}
		} elseif ( $is_dir ) {
			$removed = $this->remove_directory_recursive( $absolute, $repo_path );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
			$deleted = $removed;
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! unlink( $absolute ) ) {
				return new \WP_Error( 'delete_failed', sprintf( 'Failed to delete file: %s', $relative ), array( 'status' => 500 ) );
			}
			$deleted[] = $relative;
		}

		return array(
			'success'     => true,
			'name'        => $parsed['dir_name'],
			'repo'        => $repo_name,
			'path'        => $relative,
			'deleted'     => $deleted,
			'was_tracked' => $is_tracked,
		);
	}

	/**
	 * Recursively remove an untracked directory under a repo, returning the
	 * list of relative paths removed (deepest first).
	 *
	 * @param string $absolute  Absolute path to remove.
	 * @param string $repo_path Repo root for relative-path computation.
	 * @return array<int,string>|\WP_Error
	 */
	private function remove_directory_recursive( string $absolute, string $repo_path ): array|\WP_Error {
		$deleted = array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is converted into a WP_Error below.
		$entries = @scandir( $absolute );
		if ( false === $entries ) {
			return new \WP_Error( 'scandir_failed', sprintf( 'Failed to read directory: %s', $absolute ), array( 'status' => 500 ) );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $absolute . '/' . $entry;
			if ( is_dir( $child ) && ! is_link( $child ) ) {
				$nested = $this->remove_directory_recursive( $child, $repo_path );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
				$deleted = array_merge( $deleted, $nested );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				if ( ! unlink( $child ) ) {
					return new \WP_Error( 'delete_failed', sprintf( 'Failed to delete file: %s', $child ), array( 'status' => 500 ) );
				}
				$deleted[] = ltrim( substr( $child, strlen( $repo_path ) ), '/' );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( ! rmdir( $absolute ) ) {
			return new \WP_Error( 'delete_failed', sprintf( 'Failed to remove directory: %s', $absolute ), array( 'status' => 500 ) );
		}
		$deleted[] = ltrim( substr( $absolute, strlen( $repo_path ) ), '/' );
		return $deleted;
	}

	/**
	 * Commit staged changes in a workspace repository.
	 *
	 * @param string $handle               Workspace handle.
	 * @param string $message              Commit message.
	 * @param bool   $allow_primary_mutation Whether the primary checkout may be mutated.
	 * @return array
	 */
	public function git_commit( string $handle, string $message, bool $allow_primary_mutation = false ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path( $handle );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name, true );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed( $parsed, $allow_primary_mutation );
		if ( is_wp_error( $primary_check ) ) {
			return $primary_check;
		}

		$message = trim( $message );
		if ( '' === $message ) {
			return new \WP_Error( 'missing_message', 'Commit message is required.', array( 'status' => 400 ) );
		}

		if ( strlen( $message ) < 8 ) {
			return new \WP_Error( 'message_too_short', 'Commit message must be at least 8 characters.', array( 'status' => 400 ) );
		}

		if ( strlen( $message ) > 200 ) {
			return new \WP_Error( 'message_too_long', 'Commit message must be 200 characters or fewer.', array( 'status' => 400 ) );
		}

		$staged = $this->run_git( $repo_path, 'diff --cached --name-only' );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		$staged_files = array_filter( array_map( 'trim', explode( "\n", $staged['output'] ?? '' ) ) );
		if ( empty( $staged_files ) ) {
			return new \WP_Error( 'nothing_staged', 'No staged changes to commit.', array( 'status' => 400 ) );
		}

		$commit = $this->run_git( $repo_path, 'commit -m ' . escapeshellarg( $message ) );
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}

		return array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $repo_name,
			'commit'  => trim( (string) $commit['output'] ),
			'message' => 'Commit created successfully.',
		);
	}

	/**
	 * Push commits for a workspace repository.
	 *
	 * `fixed_branch` policy applies only to primary checkouts. Worktrees
	 * may push any branch (they exist precisely for feature work).
	 *
	 * @param string      $handle               Workspace handle.
	 * @param string      $remote               Remote name.
	 * @param string|null $branch               Branch override.
	 * @param bool        $allow_primary_mutation Whether the primary may be pushed.
	 * @return array
	 */
	public function git_push( string $handle, string $remote = 'origin', ?string $branch = null, bool $allow_primary_mutation = false ): array|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path( $handle );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name, true );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed( $parsed, $allow_primary_mutation );
		if ( is_wp_error( $primary_check ) ) {
			return $primary_check;
		}

		$current_branch_result = $this->run_git( $repo_path, 'rev-parse --abbrev-ref HEAD' );
		if ( is_wp_error( $current_branch_result ) ) {
			return $current_branch_result;
		}

		$current_branch = trim( (string) $current_branch_result['output'] );
		$target_branch  = $branch ? trim( $branch ) : $current_branch;

		// fixed_branch only constrains the primary checkout.
		if ( ! $parsed['is_worktree'] ) {
			$fixed_branch = $this->get_repo_fixed_branch( $repo_name );
			if ( null !== $fixed_branch && $target_branch !== $fixed_branch ) {
				return new \WP_Error( 'branch_restricted', sprintf( 'Push blocked: primary checkout of "%s" is restricted to branch "%s". Use a worktree for other branches.', $repo_name, $fixed_branch ), array( 'status' => 403 ) );
			}
		}

		$cmd    = sprintf( 'push %s %s', escapeshellarg( $remote ), escapeshellarg( $target_branch ) );
		$result = $this->run_git( $repo_path, $cmd );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $repo_name,
			'remote'  => $remote,
			'branch'  => $target_branch,
			'message' => trim( (string) $result['output'] ),
		);
	}

	/**
	 * Read git log entries for a workspace repository.
	 *
	 * @param string $name  Repository directory name.
	 * @param int    $limit Number of entries.
	 * @return array
	 */
	public function git_log( string $name, int $limit = 20 ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$limit = max( 1, min( 100, $limit ) );
		$cmd   = sprintf( 'log -n %d --pretty=format:%s', $limit, escapeshellarg( '%h|%an|%ad|%s' ) );
		$log   = $this->run_git( $repo_path, $cmd );

		if ( is_wp_error( $log ) ) {
			return $log;
		}

		$entries = array();
		$lines   = array_filter( array_map( 'trim', explode( "\n", $log['output'] ?? '' ) ) );
		foreach ( $lines as $line ) {
			$parts = explode( '|', $line, 4 );
			if ( count( $parts ) < 4 ) {
				continue;
			}

			$entries[] = array(
				'hash'    => $parts[0],
				'author'  => $parts[1],
				'date'    => $parts[2],
				'subject' => $parts[3],
			);
		}

		$parsed = $this->parse_handle( $name );
		return array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $parsed['repo'],
			'entries' => $entries,
		);
	}

	/**
	 * Read git diff for a workspace repository.
	 *
	 * @param string      $name   Repository directory name.
	 * @param string|null $from   Optional from ref.
	 * @param string|null $to     Optional to ref.
	 * @param bool        $staged Whether to diff staged changes.
	 * @param string|null $path   Optional relative path filter.
	 * @return array
	 */
	public function git_diff( string $name, ?string $from = null, ?string $to = null, bool $staged = false, ?string $path = null ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$args = array( 'diff' );
		if ( $staged ) {
			$args[] = '--cached';
		}

		if ( ! empty( $from ) ) {
			$args[] = escapeshellarg( $from );
		}

		if ( ! empty( $to ) ) {
			$args[] = escapeshellarg( $to );
		}

		if ( ! empty( $path ) ) {
			$relative = trim( $path );
			if ( $this->has_traversal( $relative ) || str_starts_with( $relative, '/' ) ) {
				return new \WP_Error( 'invalid_path', sprintf( 'Invalid diff path: %s', $relative ), array( 'status' => 400 ) );
			}

			$args[] = '--';
			$args[] = escapeshellarg( $relative );
		}

		$diff = $this->run_git( $repo_path, implode( ' ', $args ) );
		if ( is_wp_error( $diff ) ) {
			return $diff;
		}

		$parsed = $this->parse_handle( $name );
		return array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $parsed['repo'],
			'diff'    => $diff['output'] ?? '',
		);
	}

	// =========================================================================
	// Worktree operations
	// =========================================================================

	/**
	 * Create a git worktree for a branch.
	 *
	 * Layout: `<workspace>/<repo>@<branch-slug>` is added as a worktree of
	 * `<workspace>/<repo>` checked out to `<branch>`. If the branch does not
	 * exist locally, it is created from `<from>` (default `origin/HEAD`).
	 *
	 * When `$inject_context` is true (default) and Data Machine's agent memory
	 * layer is available, the originating site's MEMORY.md / USER.md / RULES.md
	 * are snapshotted into the new worktree as runtime-agnostic local-only
	 * files (`.claude/CLAUDE.local.md`, `.opencode/AGENTS.local.md`) and added
	 * to the worktree's per-checkout `info/exclude`. When the memory layer is
	 * absent the worktree is still created successfully; injection silently
	 * skips.
	 *
	 * When `$bootstrap` is true (default), a bootstrap pass runs after the
	 * worktree is created: `git submodule update --init --recursive` if
	 * `.gitmodules` is present, package-manager installs for root or one-level
	 * nested dependency roots with lockfiles (pnpm/bun/yarn/npm), and
	 * `composer install` for root or one-level nested dependency roots with
	 * `composer.lock`. Steps are independent and each one is skipped gracefully
	 * when its tool is unavailable. A failing step is surfaced in the result
	 * but does not roll back the worktree — the checkout exists either way.
	 * Pass `$bootstrap = false` (or `--no-bootstrap` on the CLI) for a bare
	 * checkout when you only need to read code on that branch.
	 *
	 * When the materialized branch (or its local base) is more than
	 * `datamachine_worktree_stale_threshold` commits behind upstream and
	 * neither `$allow_stale` nor `$rebase_base` is set, the worktree is
	 * torn down and the call returns a `worktree_stale` WP_Error with
	 * remediation guidance. Pass `$allow_stale = true` to proceed anyway,
	 * or `$rebase_base = true` to auto-rebase onto the upstream tip before
	 * returning. On rebase conflicts the rebase is aborted (worktree stays
	 * at its pre-rebase state) and `rebase_failed: true` is surfaced in
	 * the response so the agent can resolve manually.
	 *
	 * @param string      $repo           Primary repo name (no @-suffix).
	 * @param string      $branch         Branch to check out (e.g. "fix/foo-bar").
	 * @param string|null $from           Base ref when creating the branch.
	 * @param bool        $inject_context Whether to inject site-agent context (default true).
	 * @param bool        $bootstrap      Whether to run submodule/package/composer install after creation (default true).
	 * @param bool        $allow_stale    Bypass the staleness gate (default false).
	 * @param bool        $rebase_base    Rebase onto upstream after creation (default false).
	 * @param bool        $force          Bypass the disk-budget refusal threshold (default false).
	 * @return array{success: bool, handle: string, path: string, branch: string, slug: string, created_branch: bool, message: string, disk_budget?: array, context_injected?: bool, context_files?: string[], context_skip_reason?: string, bootstrap?: array, fetch_failed?: bool, fetch_error?: string, stale_commits_behind?: int, upstream?: string, base_stale_commits_behind?: int, base_upstream?: string, gate_threshold?: int, rebase_attempted?: bool, rebase_succeeded?: bool, rebase_error?: string, rebase_target?: string}|\WP_Error
	 */
	public function worktree_add( string $repo, string $branch, ?string $from = null, bool $inject_context = true, bool $bootstrap = true, bool $allow_stale = false, bool $rebase_base = false, bool $force = false, array $task = array() ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		$repo   = $this->sanitize_name( $repo );
		$branch = trim( $branch );

		if ( '' === $repo ) {
			return new \WP_Error( 'invalid_repo', 'Repository name is required.', array( 'status' => 400 ) );
		}

		if ( '' === $branch ) {
			return new \WP_Error( 'invalid_branch', 'Branch name is required.', array( 'status' => 400 ) );
		}

		$slug = $this->slugify_branch( $branch );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_branch', sprintf( 'Branch "%s" produced an empty slug.', $branch ), array( 'status' => 400 ) );
		}

		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path ) || ! is_dir( $primary_path . '/.git' ) ) {
			return new \WP_Error( 'primary_not_found', sprintf( 'Primary checkout for "%s" does not exist. Clone it first.', $repo ), array( 'status' => 404 ) );
		}

		$wt_handle = $repo . '@' . $slug;
		$wt_path   = $this->workspace_path . '/' . $wt_handle;

		if ( is_dir( $wt_path ) ) {
			return new \WP_Error( 'worktree_exists', sprintf( 'Worktree handle "%s" already exists.', $wt_handle ), array( 'status' => 400 ) );
		}

		$disk_budget = WorktreeDiskBudget::inspect( $this->workspace_path, WorktreeDiskBudget::thresholds( $repo, $branch ), $force );
		if ( 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			return new \WP_Error(
				'worktree_disk_budget_exceeded',
				sprintf(
					"Refusing to create worktree before bootstrap/install because the workspace disk budget is unsafe.\n%s\nThreshold: keep at least %.1f GiB free and %.1f%% free; effective floor on this filesystem is %.1f GiB.\nRun %s to review cleanup candidates, run %s to review artifact cleanup, or retry with --force only when a human explicitly accepts the disk-pressure risk.",
					WorktreeDiskBudget::format_summary( $disk_budget ),
					(float) ( $disk_budget['refuse_free_gib'] ?? 0 ),
					(float) ( $disk_budget['refuse_free_percent'] ?? 0 ),
					(float) ( $disk_budget['effective_refuse_gib'] ?? 0 ),
					$disk_budget['cleanup_dry_run_command'],
					$disk_budget['artifact_cleanup_command']
				),
				array(
					'status'      => 507,
					'disk_budget' => $disk_budget,
				)
			);
		}

		$response = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			fn() => $this->worktree_add_locked(
				$repo,
				$branch,
				$from,
				$inject_context,
				$allow_stale,
				$rebase_base,
				$slug,
				$wt_handle,
				$wt_path,
				$primary_path,
				$task
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response['disk_budget'] = $disk_budget;

		if ( $bootstrap ) {
			$response['bootstrap'] = WorktreeBootstrapper::bootstrap( $wt_path );
		}

		$this->worktree_inventory()->upsert( $this->build_worktree_inventory_row_from_handle( $wt_handle ) );

		return $response;
	}

	/**
	 * Create a worktree while the primary repo lifecycle lock is held.
	 *
	 * @param string      $repo           Primary repo name.
	 * @param string      $branch         Branch to check out.
	 * @param string|null $from           Base ref when creating the branch.
	 * @param bool        $inject_context Whether to inject site-agent context.
	 * @param bool        $allow_stale    Bypass the staleness gate.
	 * @param bool        $rebase_base    Rebase onto upstream after creation.
	 * @param string      $slug           Branch slug.
	 * @param string      $wt_handle      Worktree handle.
	 * @param string      $wt_path        Worktree path.
	 * @param string      $primary_path   Primary checkout path.
	 * @return array|\WP_Error
	 */
	private function worktree_add_locked(
		string $repo,
		string $branch,
		?string $from,
		bool $inject_context,
		bool $allow_stale,
		bool $rebase_base,
		string $slug,
		string $wt_handle,
		string $wt_path,
		string $primary_path,
		array $task = array()
	): array|\WP_Error {
		if ( is_dir( $wt_path ) ) {
			return new \WP_Error( 'worktree_exists', sprintf( 'Worktree handle "%s" already exists.', $wt_handle ), array( 'status' => 400 ) );
		}

		// Always fetch first so staleness data (and the default base) reflects the
		// current remote. Failure is logged but never aborts — offline work should
		// still be possible, the agent just needs to know staleness is unknown.
		$fetch        = WorktreeStalenessProbe::fetch( $primary_path );
		$fetch_failed = ! $fetch['ok'];
		$fetch_error  = $fetch['error'] ?? null;

		// Does the branch already exist locally?
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'git -C %s show-ref --verify --quiet %s 2>&1', escapeshellarg( $primary_path ), escapeshellarg( 'refs/heads/' . $branch ) ), $_unused, $exists_local );
		$created_branch = false;
		$resolved_base  = null;

		if ( 0 === $exists_local ) {
			$cmd = sprintf( 'worktree add %s %s', escapeshellarg( $wt_path ), escapeshellarg( $branch ) );
		} else {
			$base           = $from && '' !== trim( $from ) ? trim( $from ) : $this->resolve_default_base( $primary_path );
			$resolved_base  = $base;
			$cmd            = sprintf( 'worktree add -b %s %s %s', escapeshellarg( $branch ), escapeshellarg( $wt_path ), escapeshellarg( $base ) );
			$created_branch = true;
		}

		$result = $this->run_git( $primary_path, $cmd );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = array(
			'success'        => true,
			'handle'         => $wt_handle,
			'path'           => $wt_path,
			'branch'         => $branch,
			'slug'           => $slug,
			'created_branch' => $created_branch,
			'message'        => sprintf( 'Worktree "%s" added at %s (branch %s).', $wt_handle, $wt_path, $branch ),
		);

		if ( $fetch_failed ) {
			$response['fetch_failed'] = true;
			if ( null !== $fetch_error && '' !== $fetch_error ) {
				$response['fetch_error'] = $fetch_error;
			}
		}

		// Compute staleness. Only meaningful when fetch succeeded — otherwise the
		// upstream refs are potentially stale themselves and any behind-count we
		// produce would be misleading.
		if ( ! $fetch_failed ) {
			if ( ! $created_branch ) {
				// Existing local branch: compare against its configured upstream.
				$behind = WorktreeStalenessProbe::behind_count( $wt_path, $branch, '@{upstream}' );
				if ( is_int( $behind ) ) {
					$response['stale_commits_behind'] = $behind;
					// Derive a human-readable upstream label. Best-effort; silently
					// skipped when git's plumbing doesn't cooperate.
					$upstream_name = $this->run_git(
						$wt_path,
						sprintf( 'rev-parse --abbrev-ref --symbolic-full-name %s', escapeshellarg( $branch . '@{upstream}' ) )
					);
					if ( ! is_wp_error( $upstream_name ) ) {
						$label = trim( (string) ( $upstream_name['output'] ?? '' ) );
						if ( '' !== $label ) {
							$response['upstream'] = $label;
						}
					}
				}
				// null → no upstream configured; WP_Error → unexpected failure.
				// Both cases: silently omit staleness fields.
			} elseif ( null !== $resolved_base && ! $this->is_remote_tracking_ref( $resolved_base ) && 'HEAD' !== $resolved_base ) {
				// New branch cut from a local ref: compare that ref to its origin
				// counterpart so the agent sees when the base itself was stale.
				$base_upstream = 'origin/' . $resolved_base;
				$behind        = WorktreeStalenessProbe::behind_count( $primary_path, $resolved_base, $base_upstream );
				if ( is_int( $behind ) ) {
					$response['base_stale_commits_behind'] = $behind;
					$response['base_upstream']             = $base_upstream;
				}
			}
		}

		// Rebase BEFORE gating: if the agent explicitly asked to rebase, try
		// that first. Success cancels the gate trigger entirely. Failure leaves
		// the worktree at its pre-rebase state AND still trips the gate, so
		// --rebase-base alone on a conflicting rebase isn't a silent bypass.
		if ( $rebase_base && ! $fetch_failed ) {
			$rebase_result = $this->try_rebase_worktree( $wt_path, $response, $created_branch );
			if ( null !== $rebase_result ) {
				$response = array_merge( $response, $rebase_result );
			}
		}

		// Staleness gate. Threshold filterable per-site / per-repo. Only fires
		// when fetch succeeded (otherwise behind-counts are unreliable) and
		// rebase didn't already zero out the staleness.
		if ( ! $allow_stale && ! $fetch_failed ) {
			/**
			 * Filters the staleness threshold above which `worktree_add` refuses
			 * to return a stale worktree without explicit `--allow-stale` opt-in.
			 *
			 * @param int    $threshold Default 50 commits behind upstream.
			 * @param string $repo      Repository name.
			 * @param string $branch    Branch being materialized.
			 */
			$threshold                  = (int) apply_filters( 'datamachine_worktree_stale_threshold', 50, $repo, $branch );
			$response['gate_threshold'] = $threshold;
			$effective_behind           = $this->effective_behind_count( $response );

			if ( null !== $effective_behind && $effective_behind > $threshold ) {
				// Tear the worktree down so we don't leak a half-cooked
				// checkout on the user's disk.
				$this->run_git( $primary_path, sprintf( 'worktree remove --force %s', escapeshellarg( $wt_path ) ) );

				$label    = $response['upstream'] ?? ( $response['base_upstream'] ?? 'upstream' );
				$guidance = sprintf(
					'Worktree base is %d commits behind %s (threshold: %d).' . "\n"
					. 'Options:' . "\n"
					. '  - workspace git-pull %s --allow-primary-mutation  (refresh primary first)' . "\n"
					. '  - worktree add … --from=origin/%s  (cut from remote ref directly)' . "\n"
					. '  - worktree add … --rebase-base  (auto-rebase onto upstream)' . "\n"
					. '  - worktree add … --allow-stale  (proceed with known-stale base)',
					$effective_behind,
					$label,
					$threshold,
					$repo,
					ltrim( (string) ( $response['upstream'] ?? $resolved_base ?? 'main' ), 'origin/' )
				);

				return new \WP_Error(
					'worktree_stale',
					$guidance,
					array(
						'status'                    => 409,
						'stale_commits_behind'      => $response['stale_commits_behind'] ?? null,
						'base_stale_commits_behind' => $response['base_stale_commits_behind'] ?? null,
						'upstream'                  => $response['upstream'] ?? null,
						'base_upstream'             => $response['base_upstream'] ?? null,
						'gate_threshold'            => $threshold,
						'fetch_failed'              => false,
					)
				);
			}
		}

		$lifecycle_metadata = WorktreeContextInjector::build_lifecycle_metadata(
			array(
				'handle'      => $wt_handle,
				'path'        => $wt_path,
				'repo'        => $repo,
				'branch'      => $branch,
				'base_ref'    => $created_branch ? $resolved_base : null,
				'base_source' => $created_branch ? ( null !== $from && '' !== trim( $from ) ? 'requested_ref' : 'default_base' ) : 'existing_local_branch',
				'task_url'    => isset( $task['task_url'] ) ? (string) $task['task_url'] : '',
				'task_ref'    => isset( $task['task_ref'] ) ? (string) $task['task_ref'] : '',
			)
		);
		WorktreeContextInjector::store_lifecycle_metadata( $wt_handle, $lifecycle_metadata );
		$response['created_at'] = $lifecycle_metadata['created_at'] ?? null;
		$response['metadata']   = WorktreeContextInjector::get_metadata( $wt_handle );

		if ( ! $inject_context ) {
			$response['context_injected']    = false;
			$response['context_skip_reason'] = 'inject_context flag disabled';
		} else {
			$payload = WorktreeContextInjector::build_payload();
			if ( null === $payload ) {
				$response['context_injected']    = false;
				$response['context_skip_reason'] = 'agent memory layer unavailable';
			} else {
				$injection = WorktreeContextInjector::inject( $wt_path, $payload );
				if ( is_wp_error( $injection ) ) {
					$response['context_injected']    = false;
					$response['context_skip_reason'] = 'inject failed: ' . $injection->get_error_message();
				} else {
					WorktreeContextInjector::store_metadata( $wt_handle, $payload );
					$response['metadata']         = WorktreeContextInjector::get_metadata( $wt_handle );
					$response['context_injected'] = true;
					$response['context_files']    = $injection['written'];
					if ( ! empty( $injection['exclude_path'] ) ) {
						$response['context_exclude_path'] = $injection['exclude_path'];
					}
				}
			}
		}

		return $response;
	}

	/**
	 * Attach lifecycle finalizer metadata to a worktree record.
	 *
	 * @param string      $handle Workspace worktree handle.
	 * @param string      $state  Lifecycle state.
	 * @param string|null $pr     Optional PR URL or number.
	 * @return array{success: bool, handle: string, path: string, lifecycle_state: string, metadata: array, message: string}|\WP_Error
	 */
	public function worktree_finalize( string $handle, string $state, ?string $pr = null ): array|\WP_Error {
		$parsed = $this->parse_handle( $handle );
		if ( ! $parsed['is_worktree'] ) {
			return new \WP_Error( 'not_a_worktree', sprintf( 'Handle "%s" is a primary checkout, not a worktree.', $handle ), array( 'status' => 400 ) );
		}

		$normalized_state = WorktreeContextInjector::normalize_state( $state );
		if ( null === $normalized_state ) {
			return new \WP_Error( 'invalid_lifecycle_state', sprintf( 'Invalid lifecycle state "%s". Valid states: %s.', $state, implode( ', ', WorktreeContextInjector::VALID_STATES ) ), array( 'status' => 400 ) );
		}

		$wt_path = $this->workspace_path . '/' . $parsed['dir_name'];
		if ( ! is_dir( $wt_path ) ) {
			return new \WP_Error( 'worktree_not_found', sprintf( 'Worktree "%s" does not exist on disk.', $parsed['dir_name'] ), array( 'status' => 404 ) );
		}

		$metadata = WorktreeContextInjector::build_finalizer_metadata( $normalized_state, $pr );
		$metadata = array_merge(
			array(
				'handle'       => $parsed['dir_name'],
				'path'         => $wt_path,
				'repo'         => $parsed['repo'],
				// Finalize is itself an explicit liveness signal from the owner.
				'last_seen_at' => gmdate( 'c' ),
			),
			$metadata
		);
		WorktreeContextInjector::store_lifecycle_metadata( $parsed['dir_name'], $metadata );

		$stored = WorktreeContextInjector::get_metadata( $parsed['dir_name'] ) ?? array();
		$this->worktree_inventory()->upsert( $this->build_worktree_inventory_row_from_handle( $parsed['dir_name'] ) );
		return array(
			'success'         => true,
			'handle'          => $parsed['dir_name'],
			'path'            => $wt_path,
			'lifecycle_state' => (string) ( $stored['lifecycle_state'] ?? $normalized_state ),
			'metadata'        => $stored,
			'message'         => sprintf( 'Worktree "%s" marked %s.', $parsed['dir_name'], (string) ( $stored['lifecycle_state'] ?? $normalized_state ) ),
		);
	}

	/**
	 * Rewrite a worktree's injected context files from the originating site's
	 * current memory state.
	 *
	 * Uses the site option snapshot stored at worktree-creation time for
	 * logging / diagnostics, then re-reads memory from the currently active
	 * Data Machine agent layer. Cross-machine refresh is deliberately not
	 * supported: callers must invoke this from the same site that created
	 * the worktree.
	 *
	 * @param string $handle Workspace handle (`<repo>@<branch-slug>`).
	 * @return array{success: bool, handle: string, path: string, written: string[], exclude_path: ?string, metadata: ?array, message: string}|\WP_Error
	 */
	public function worktree_refresh_context( string $handle ): array|\WP_Error {
		$parsed = $this->parse_handle( $handle );
		if ( ! $parsed['is_worktree'] ) {
			return new \WP_Error(
				'not_a_worktree',
				sprintf( 'Handle "%s" is a primary checkout, not a worktree. Context injection is worktree-only.', $handle ),
				array( 'status' => 400 )
			);
		}

		$wt_path = $this->workspace_path . '/' . $parsed['dir_name'];
		if ( ! is_dir( $wt_path ) ) {
			return new \WP_Error(
				'worktree_not_found',
				sprintf( 'Worktree "%s" does not exist on disk.', $parsed['dir_name'] ),
				array( 'status' => 404 )
			);
		}

		$payload = WorktreeContextInjector::build_payload();
		if ( null === $payload ) {
			return new \WP_Error(
				'agent_layer_unavailable',
				'Data Machine agent memory layer is not available — cannot refresh context. Ensure this command is run from the site that created the worktree.',
				array( 'status' => 500 )
			);
		}

		$injection = WorktreeContextInjector::inject( $wt_path, $payload );
		if ( is_wp_error( $injection ) ) {
			return $injection;
		}

		WorktreeContextInjector::store_metadata( $parsed['dir_name'], $payload );
		// refresh-context is a deliberate liveness signal: the originating site
		// (and therefore some agent process there) just touched this worktree.
		WorktreeContextInjector::record_heartbeat( $parsed['dir_name'] );
		$this->worktree_inventory()->upsert( $this->build_worktree_inventory_row_from_handle( $parsed['dir_name'] ) );

		return array(
			'success'      => true,
			'handle'       => $parsed['dir_name'],
			'path'         => $wt_path,
			'written'      => $injection['written'],
			'exclude_path' => $injection['exclude_path'] ?? null,
			'metadata'     => WorktreeContextInjector::get_metadata( $parsed['dir_name'] ),
			'message'      => sprintf( 'Refreshed injected context in "%s" (%d file%s).', $parsed['dir_name'], count( $injection['written'] ), 1 === count( $injection['written'] ) ? '' : 's' ),
		);
	}

	/**
	 * List worktrees in the workspace.
	 *
	 * On large workspaces (hundreds of worktrees) the per-row `git status` and
	 * `du` probes are the dominant cost. Callers that only need cheap inventory
	 * (handle, repo, branch, head, lifecycle metadata) can opt out via
	 * `$opts['include_status']` / `$opts['include_disk']`. Skipped fields are
	 * returned as `null`/`0`/`array()` and the row's `fields_skipped` array
	 * lists which probe groups were skipped, so consumers can tell the
	 * difference between "absent" and "not measured".
	 *
	 * @param string|null $repo  Optional repo filter (only this primary's worktrees).
	 * @param string|null $state Optional lifecycle state filter.
	 * @param array       $opts {
	 *     @type bool $include_status Whether to run `git status --porcelain` per worktree. Default true.
	 *     @type bool $include_disk   Whether to run size/artifact `du` probes per worktree. Default true.
	 * }
	 * @return array{success: bool, worktrees: array, fields_skipped: array<int,string>}|\WP_Error
	 */
	public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error {
		$include_status = array_key_exists( 'include_status', $opts ) ? (bool) $opts['include_status'] : true;
		$include_disk   = array_key_exists( 'include_disk', $opts ) ? (bool) $opts['include_disk'] : true;

		$skipped_groups = array();
		if ( ! $include_status ) {
			$skipped_groups[] = 'status';
		}
		if ( ! $include_disk ) {
			$skipped_groups[] = 'disk';
		}

		if ( null !== $state && '' !== trim( $state ) ) {
			$state = WorktreeContextInjector::normalize_state( (string) $state );
			if ( null === $state ) {
				return new \WP_Error( 'invalid_lifecycle_state', sprintf( 'Invalid lifecycle state. Valid states: %s.', implode( ', ', WorktreeContextInjector::VALID_STATES ) ), array( 'status' => 400 ) );
			}
		} else {
			$state = null;
		}
		if ( ! is_dir( $this->workspace_path ) ) {
			return array(
				'success'        => true,
				'worktrees'      => array(),
				'fields_skipped' => $skipped_groups,
			);
		}

		$primaries = array();
		$entries   = scandir( $this->workspace_path );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_contains( $entry, '@' ) ) {
				continue;
			}
			$entry_path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir( $entry_path . '/.git' ) ) {
				continue;
			}
			$primaries[] = $entry;
		}

		if ( null !== $repo ) {
			$repo      = $this->sanitize_name( $repo );
			$primaries = array_values( array_filter( $primaries, fn( $p ) => $p === $repo ) );
		}

		$worktrees = array();

		foreach ( $primaries as $primary ) {
			$primary_path = $this->workspace_path . '/' . $primary;
			$result       = $this->run_git( $primary_path, 'worktree list --porcelain' );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$blocks = preg_split( "/\n\n+/", trim( (string) ( $result['output'] ?? '' ) ) );
			foreach ( $blocks as $block ) {
				$wt = $this->parse_worktree_block( $block );
				if ( null === $wt ) {
					continue;
				}

				$is_primary    = ( $wt['path'] === $primary_path );
				$workspace_pfx = $this->workspace_path . '/';
				$inside_ws     = str_starts_with( $wt['path'], $workspace_pfx );
				$relative      = $inside_ws ? substr( $wt['path'], strlen( $workspace_pfx ) ) : '';
				$parsed        = $inside_ws ? $this->parse_handle( $relative ) : array( 'branch_slug' => null );

				if ( $is_primary ) {
					$handle = $primary;
				} elseif ( $inside_ws ) {
					$handle = $relative;
				} else {
					// External worktree (created via raw `git worktree add` outside the workspace).
					// Show the absolute path so it is still useful, even though it has no `<repo>@<slug>` handle.
					$handle = $wt['path'];
				}

				if ( $include_status ) {
					$dirty_result = $this->run_git( $wt['path'], 'status --porcelain' );
					$dirty_files  = is_wp_error( $dirty_result )
						? 0
						: count( array_filter( array_map( 'trim', explode( "\n", $dirty_result['output'] ?? '' ) ) ) );
				} else {
					$dirty_files = null;
				}

				$metadata        = ( ! $is_primary && $inside_ws ) ? WorktreeContextInjector::get_metadata( $relative ) : null;
				$created_at      = is_array( $metadata ) ? ( $metadata['created_at'] ?? null ) : null;
				$lifecycle_state = is_array( $metadata ) ? ( $metadata['lifecycle_state'] ?? null ) : null;
				if ( null !== $state && $lifecycle_state !== $state ) {
					continue;
				}

				if ( $include_disk ) {
					$disk = $this->build_worktree_disk_report( $primary, $wt['path'], ! $is_primary, $created_at, $metadata );
				} else {
					$disk = array(
						'size_bytes'           => null,
						'estimated_size_bytes' => null,
						'last_touched_at'      => null,
						'age_days'             => $this->calculate_age_days( $created_at ),
						'artifacts'            => array(),
						'artifact_size_bytes'  => 0,
					);
				}

				// Stale-reason detection requires both signals to be reliable; only
				// flag dirty/threshold reasons when the underlying probe ran. The
				// metadata-only signal still works without disk/status probes.
				$stale_reason = $this->detect_worktree_stale_reason(
					! $is_primary,
					(int) ( $dirty_files ?? 0 ),
					$disk['age_days'] ?? null,
					$created_at,
					array(
						'status_probed' => $include_status,
						'disk_probed'   => $include_disk,
					)
				);
				if ( null !== $stale_reason ) {
					$disk['stale_reason'] = $stale_reason;
				}

				$liveness     = WorktreeContextInjector::classify_liveness( is_array( $metadata ) ? $metadata : null );
				$owner        = WorktreeContextInjector::summarize_owner( is_array( $metadata ) ? $metadata : null );
				$session_view = WorktreeContextInjector::summarize_session( is_array( $metadata ) ? $metadata : null );
				$task_view    = is_array( $metadata ) && is_array( $metadata['origin_task'] ?? null ) ? $metadata['origin_task'] : null;

				$row = array_merge(
					array(
						'handle'                => $handle,
						'repo'                  => $primary,
						'is_worktree'           => ! $is_primary,
						'is_primary'            => $is_primary,
						'external'              => ! $is_primary && ! $inside_ws,
						'branch_slug'           => $is_primary ? null : ( $parsed['branch_slug'] ?? null ),
						'branch'                => $wt['branch'],
						'head'                  => $wt['head'],
						'path'                  => $wt['path'],
						'dirty'                 => $dirty_files,
						'created_at'            => $created_at,
						'lifecycle_state'       => $lifecycle_state,
						'pr_url'                => is_array( $metadata ) ? ( $metadata['pr_url'] ?? null ) : null,
						'pr_number'             => is_array( $metadata ) ? ( $metadata['pr_number'] ?? null ) : null,
						'last_seen_at'          => is_array( $metadata ) ? ( $metadata['last_seen_at'] ?? null ) : null,
						'liveness'              => $liveness['liveness'],
						'liveness_reason'       => $liveness['reason'],
						'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
						'owner'                 => $owner,
						'session'               => $session_view,
						'task'                  => $task_view,
						'metadata'              => $metadata,
					),
					$disk
				);

				if ( ! empty( $skipped_groups ) ) {
					$row['fields_skipped'] = $skipped_groups;
				}

				$worktrees[] = $row;
			}
		}

		$duplicates = WorktreeContextInjector::find_duplicate_task_ownership( $worktrees );

		return array(
			'success'        => true,
			'worktrees'      => $worktrees,
			'duplicates'     => $duplicates,
			'fields_skipped' => $skipped_groups,
		);
	}

	/**
	 * Refresh the DB-backed worktree inventory from the current filesystem/git view.
	 *
	 * Current rows are upserted. Previously known rows missing from the current
	 * scan are marked `missing_path` so operators can see drift explicitly.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_inventory_refresh(): array|\WP_Error {
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

		$repository      = $this->worktree_inventory();
		$current_handles = array();
		$upserted        = array();
		$marked_missing  = array();

		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' === $handle || ! empty( $row['external'] ) ) {
				continue;
			}

			$current_handles[ $handle ] = true;
			if ( $repository->upsert( $row ) ) {
				$upserted[] = $handle;
			}
		}

		foreach ( $repository->list() as $stored ) {
			$handle = (string) ( $stored['handle'] ?? '' );
			if ( '' === $handle || isset( $current_handles[ $handle ] ) ) {
				continue;
			}

			if ( $repository->mark_missing( $handle ) ) {
				$marked_missing[] = $handle;
			}
		}

		return array(
			'success'        => true,
			'refreshed_at'   => gmdate( 'c' ),
			'upserted'       => $upserted,
			'marked_missing' => $marked_missing,
			'summary'        => array(
				'upserted'       => count( $upserted ),
				'marked_missing' => count( $marked_missing ),
			),
		);
	}

	/**
	 * Build a single inventory row for a known workspace handle.
	 *
	 * @param string $handle Workspace handle.
	 * @return array<string,mixed>
	 */
	private function build_worktree_inventory_row_from_handle( string $handle ): array {
		$parsed   = $this->parse_handle( $handle );
		$path     = $this->workspace_path . '/' . $parsed['dir_name'];
		$metadata = $parsed['is_worktree'] ? WorktreeContextInjector::get_metadata( $parsed['dir_name'] ) : null;
		$metadata = is_array( $metadata ) ? $metadata : array();
		$liveness = WorktreeContextInjector::classify_liveness( $metadata );
		$owner    = WorktreeContextInjector::summarize_owner( $metadata );
		$session  = WorktreeContextInjector::summarize_session( $metadata );
		$task     = is_array( $metadata['origin_task'] ?? null ) ? (array) $metadata['origin_task'] : null;

		return array(
			'handle'                => $parsed['dir_name'],
			'repo'                  => $parsed['repo'],
			'is_worktree'           => $parsed['is_worktree'],
			'is_primary'            => ! $parsed['is_worktree'],
			'external'              => false,
			'branch_slug'           => $parsed['branch_slug'],
			'branch'                => $metadata['branch'] ?? $parsed['branch_slug'],
			'path'                  => $path,
			'primary_path'          => $this->get_primary_path( $parsed['repo'] ),
			'dirty'                 => null,
			'created_at'            => $metadata['created_at'] ?? null,
			'lifecycle_state'       => $metadata['lifecycle_state'] ?? null,
			'pr_url'                => $metadata['pr_url'] ?? null,
			'pr_number'             => $metadata['pr_number'] ?? null,
			'last_seen_at'          => $metadata['last_seen_at'] ?? null,
			'liveness'              => $liveness['liveness'],
			'liveness_reason'       => $liveness['reason'],
			'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
			'owner'                 => $owner,
			'session'               => $session,
			'task'                  => $task,
			'missing_path'          => ! is_dir( $path ),
			'metadata'              => $metadata,
		);
	}

	/**
	 * Build a non-destructive workspace hygiene report.
	 *
	 * The report intentionally defaults to local-only cleanup detection so an
	 * on-demand or scheduled run never depends on GitHub API availability. Size
	 * collection is best-effort and bounded by a top-level entry limit.
	 *
	 * @param array $opts {
	 *     @type bool $include_cleanup         Whether to include a cleanup dry-run. Default true.
	 *     @type bool $include_sizes           Whether to include best-effort `du` sizes. Default true.
	 *     @type bool $include_worktree_status Whether to include full git worktree status. Default false.
	 *     @type int  $size_limit              Maximum top-level workspace entries to size. Default 1000.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_hygiene_report( array $opts = array() ): array|\WP_Error {
		$include_cleanup         = array_key_exists( 'include_cleanup', $opts ) ? (bool) $opts['include_cleanup'] : true;
		$include_sizes           = array_key_exists( 'include_sizes', $opts ) ? (bool) $opts['include_sizes'] : true;
		$include_worktree_status = array_key_exists( 'include_worktree_status', $opts ) ? (bool) $opts['include_worktree_status'] : false;
		$refresh_inventory       = ! empty( $opts['refresh_inventory'] );
		$size_limit              = isset( $opts['size_limit'] ) ? max( 0, (int) $opts['size_limit'] ) : self::HYGIENE_DEFAULT_SIZE_LIMIT;

		$inventory_refresh = null;
		if ( $refresh_inventory ) {
			$inventory_refresh = $this->worktree_inventory_refresh();
			if ( $inventory_refresh instanceof \WP_Error ) {
				return $inventory_refresh;
			}
		}

		if ( $include_worktree_status ) {
			$listing = $this->worktree_list();
			if ( is_wp_error( $listing ) ) {
				return $listing;
			}
			$worktrees            = (array) ( $listing['worktrees'] ?? array() );
			$worktree_status_mode = 'full_git_status';
		} else {
			$worktrees            = $this->build_workspace_inventory_rows();
			$worktree_status_mode = 'top_level_inventory';
		}

		$size_report   = $include_sizes ? $this->build_workspace_size_report( $size_limit ) : $this->empty_workspace_size_report( $size_limit, false );
		$cleanup       = null;
		$cleanup_error = null;
		$locks         = WorkspaceMutationLock::status( $this->workspace_path );

		if ( $include_cleanup ) {
			$cleanup = $this->worktree_cleanup_merged(
				array(
					'dry_run'        => true,
					'force'          => false,
					'skip_github'    => true,
					'inventory_only' => true,
				)
			);
			if ( $cleanup instanceof \WP_Error ) {
				$cleanup_error = array(
					'code'    => $cleanup->get_error_code(),
					'message' => $cleanup->get_error_message(),
				);
				$cleanup       = null;
			}
		}
		return array(
			'success'                   => true,
			'generated_at'              => gmdate( 'c' ),
			'workspace_path'            => $this->workspace_path,
			'destructive'               => false,
			'size'                      => $size_report,
			'disk'                      => $this->build_workspace_disk_report(),
			'inventory'                 => array(
				'freshness' => $this->worktree_inventory()->freshness(),
				'refresh'   => $inventory_refresh,
			),
			'worktrees'                 => $this->summarize_workspace_worktrees( $worktrees, $cleanup ),
			'worktree_status_mode'      => $worktree_status_mode,
			'top_repos_by_worktrees'    => $this->top_repos_by_worktree_count( $worktrees, 10 ),
			'top_repos_by_size'         => $this->top_repos_by_size( (array) ( $size_report['entries'] ?? array() ), 10 ),
			'locks'                     => $locks,
			'cleanup'                   => $this->summarize_workspace_cleanup( $cleanup, $cleanup_error, (array) ( $size_report['entries'] ?? array() ) ),
			'suggested_cleanup_command' => 'wp datamachine-code workspace worktree cleanup --dry-run --inventory-only --skip-github --format=json',
			'notes'                     => array_values( array_filter( array(
				$include_sizes ? (string) ( $size_report['mode_note'] ?? '' ) : 'Size scan disabled by request.',
				$include_worktree_status ? 'Full worktree status enabled; this may run git status across every worktree.' : 'Worktree status uses cheap top-level inventory; pass --include-worktree-status for full git status.',
				$include_cleanup ? 'Cleanup summary uses inventory-only dry-run detection (--inventory-only --skip-github); no per-worktree git probes or GitHub API lookups are required.' : 'Cleanup dry-run disabled by request.',
			) ) ),
		);
	}

	/**
	 * Run age-gated workspace retention cleanup and return a compact report.
	 *
	 * This is the scheduled/manual orchestration layer over the lower-level
	 * cleanup primitives: whole worktrees require a merge/finalization signal,
	 * while reconstructable artifacts are removed from any currently safe
	 * non-active worktree.
	 *
	 * @param array $opts Retention options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_retention_cleanup( array $opts = array() ): array|\WP_Error {
		$dry_run             = ! empty( $opts['dry_run'] );
		$force               = ! empty( $opts['force'] );
		$skip_github         = array_key_exists( 'skip_github', $opts ) ? (bool) $opts['skip_github'] : true;
		$worktree_cleanup    = array_key_exists( 'worktree_cleanup', $opts ) ? (bool) $opts['worktree_cleanup'] : true;
		$artifact_cleanup    = array_key_exists( 'artifact_cleanup', $opts ) ? (bool) $opts['artifact_cleanup'] : true;
		$worktree_older_than = isset( $opts['worktree_older_than'] ) && '' !== trim( (string) $opts['worktree_older_than'] ) ? trim( (string) $opts['worktree_older_than'] ) : '14d';

		$worktree_result = null;
		$artifact_result = null;
		$lock_retention  = WorkspaceMutationLock::prune_stale( $this->workspace_path, $dry_run );

		if ( $worktree_cleanup ) {
			$worktree_result = $this->worktree_cleanup_merged(
				array(
					'dry_run'     => $dry_run,
					'force'       => $force,
					'skip_github' => $skip_github,
					'older_than'  => $worktree_older_than,
					'sort'        => 'age',
				)
			);
			if ( $worktree_result instanceof \WP_Error ) {
				return $worktree_result;
			}
		}

		if ( $artifact_cleanup ) {
			// Retention cleanup orchestrates the full sweep — opt into the
			// exhaustive scan so the apply plan covers every safe worktree,
			// not just the bounded dry-run page CLI consumers see by default.
			$artifact_plan = $this->worktree_cleanup_artifacts(
				array(
					'dry_run'    => true,
					'force'      => $force,
					'exhaustive' => true,
				)
			);
			if ( $artifact_plan instanceof \WP_Error ) {
				return $artifact_plan;
			}

			$artifact_result = $artifact_plan;
			if ( ! $dry_run && ! empty( $artifact_plan['candidates'] ) ) {
				$artifact_result = $this->worktree_cleanup_artifacts(
					array(
						'apply_plan' => $artifact_plan,
						'force'      => $force,
					)
				);
				if ( $artifact_result instanceof \WP_Error ) {
					return $artifact_result;
				}
			}
		}

		$report = $this->build_workspace_retention_report( $worktree_result, $artifact_result, $dry_run );

		return array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'destructive'    => ! $dry_run,
			'generated_at'   => gmdate( 'c' ),
			'workspace_path' => $this->workspace_path,
			'policy'         => array(
				'worktree_cleanup'    => $worktree_cleanup,
				'artifact_cleanup'    => $artifact_cleanup,
				'worktree_older_than' => $worktree_older_than,
				'skip_github'         => $skip_github,
				'force'               => $force,
			),
			'lock_retention' => $lock_retention,
			'storage'        => $this->cleanup_storage_status(),
			'report'         => $report,
			'worktrees'      => $worktree_result,
			'artifacts'      => $artifact_result,
			'disk'           => $this->build_workspace_disk_report(),
		);
	}

	/**
	 * Run disk-threshold-triggered emergency cleanup orchestration.
	 *
	 * This is the automation-safe layer: inspect cheap disk/worktree metrics,
	 * build the inventory-only emergency plan, and apply only reconstructable
	 * artifact chunks by default. Whole-worktree deletion requires an explicit
	 * cleanup-eligible plan plus human-approved escalation.
	 *
	 * @param array $opts Emergency automation options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_disk_emergency_cleanup( array $opts = array() ): array|\WP_Error {
		$dry_run                  = ! empty( $opts['dry_run'] );
		$artifact_chunk_size      = isset( $opts['artifact_chunk_size'] ) && is_numeric( $opts['artifact_chunk_size'] ) ? max( 1, (int) $opts['artifact_chunk_size'] ) : 10;
		$allow_worktree_deletion  = ! empty( $opts['allow_worktree_deletion'] );
		$human_approved_deletion  = ! empty( $opts['human_approved_worktree_deletion'] );
		$force_worktree_deletion  = ! empty( $opts['force'] ) && $human_approved_deletion;
		$thresholds               = isset( $opts['thresholds'] ) && is_array( $opts['thresholds'] ) ? $opts['thresholds'] : WorktreeDiskBudget::thresholds( 'workspace', 'emergency-cleanup' );
		$budget                   = WorktreeDiskBudget::inspect( $this->workspace_path, $thresholds );

		$plan = $this->worktree_emergency_cleanup( array( 'dry_run' => true ) );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$artifact_candidates             = (array) ( $plan['artifact_candidates'] ?? array() );
		$worktree_candidates             = (array) ( $plan['worktree_candidates'] ?? array() );
		$top_artifact_offenders          = $this->summarize_top_worktree_rows( $artifact_candidates, 'artifact_size_bytes' );
		$budget['top_artifact_offenders'] = $top_artifact_offenders;

		$triggered = ! empty( $budget['emergency_triggered'] );
		if ( ! $triggered ) {
			return array(
				'success'             => true,
				'triggered'           => false,
				'skipped'             => true,
				'reason'              => 'disk thresholds not crossed',
				'dry_run'             => $dry_run,
				'generated_at'        => gmdate( 'c' ),
				'workspace_path'      => $this->workspace_path,
				'disk_budget'         => $budget,
				'emergency_plan'      => $plan,
				'action_required'     => false,
				'applied'             => null,
			);
		}

		$selected_artifacts = array_slice( $artifact_candidates, 0, $artifact_chunk_size );
		$blocked_reasons    = array();
		$selected_worktrees = array();
		if ( array() === $selected_artifacts && array() !== $worktree_candidates ) {
			if ( $allow_worktree_deletion && $human_approved_deletion ) {
				$selected_worktrees = $worktree_candidates;
			} else {
				$blocked_reasons[] = 'worktree_deletion_requires_human_approval';
			}
		}

		$apply_plan = array_merge( $plan, array(
			'artifact_candidates' => $selected_artifacts,
			'worktree_candidates' => $selected_worktrees,
		) );

		$applied = null;
		if ( ! $dry_run && ( array() !== $selected_artifacts || array() !== $selected_worktrees ) ) {
			$applied = $this->worktree_emergency_cleanup(
				array(
					'apply_plan' => $apply_plan,
					'force'      => $force_worktree_deletion,
				)
			);
			if ( $applied instanceof \WP_Error ) {
				return $applied;
			}
		}

		$apply_skipped_by_reason = (array) ( $applied['summary']['skipped_by_reason'] ?? array() );
		foreach ( array( 'dirty_worktree', 'unpushed_commits', 'emergency_lifecycle_not_current', 'emergency_worktree_plan_not_current' ) as $reason ) {
			if ( ! empty( $apply_skipped_by_reason[ $reason ] ) ) {
				$blocked_reasons[] = $reason;
			}
		}
		$blocked_reasons = array_values( array_unique( $blocked_reasons ) );

		return array(
			'success'                 => true,
			'triggered'               => true,
			'skipped'                 => false,
			'dry_run'                 => $dry_run,
			'generated_at'            => gmdate( 'c' ),
			'workspace_path'          => $this->workspace_path,
			'disk_budget'             => $budget,
			'emergency_plan'          => $plan,
			'apply_plan'              => $apply_plan,
			'applied'                 => $applied,
			'artifact_chunk_size'     => $artifact_chunk_size,
			'selected_artifact_count' => count( $selected_artifacts ),
			'selected_worktree_count' => count( $selected_worktrees ),
			'action_required'         => array() !== $blocked_reasons || ( array() === $selected_artifacts && array() !== $worktree_candidates && array() === $selected_worktrees ),
			'action_required_reasons' => $blocked_reasons,
			'policy'                  => array(
				'artifact_first'                      => true,
				'allow_worktree_deletion'             => $allow_worktree_deletion,
				'human_approved_worktree_deletion'    => $human_approved_deletion,
				'force_requires_human_approval'       => true,
				'force_worktree_deletion_applied'     => $force_worktree_deletion,
			),
		);
	}

	/**
	 * Build cheap workspace inventory rows from top-level directory names.
	 *
	 * This intentionally avoids `git worktree list` and per-worktree `git status`.
	 * It is the safe default for huge workspaces where hygiene should still be
	 * able to return a bounded JSON report.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_workspace_inventory_rows(): array {
		if ( '' === $this->workspace_path || ! is_dir( $this->workspace_path ) ) {
			return array();
		}

		$entries = scandir( $this->workspace_path );
		if ( false === $entries ) {
			return array();
		}

		$rows = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$parsed      = $this->parse_handle( $entry );
			$kind        = $this->classify_workspace_entry_kind( $entry, $parsed, $path );
			$is_worktree = 'worktree' === $kind;
			$metadata    = $is_worktree ? WorktreeContextInjector::get_metadata( $parsed['dir_name'] ) : null;

			$liveness     = WorktreeContextInjector::classify_liveness( is_array( $metadata ) ? $metadata : null );
			$owner        = WorktreeContextInjector::summarize_owner( is_array( $metadata ) ? $metadata : null );
			$session_view = WorktreeContextInjector::summarize_session( is_array( $metadata ) ? $metadata : null );
			$task_view    = is_array( $metadata ) && is_array( $metadata['origin_task'] ?? null ) ? $metadata['origin_task'] : null;

			$rows[] = array(
				'handle'           => $parsed['dir_name'],
				'repo'             => $parsed['repo'],
				'kind'             => $kind,
				'is_worktree'      => $is_worktree,
				'is_primary'       => 'primary' === $kind,
				'external'         => false,
				'branch_slug'      => $parsed['branch_slug'],
				'path'             => $path,
				'dirty'            => 0,
				'created_at'       => is_array( $metadata ) ? ( $metadata['created_at'] ?? null ) : null,
				'lifecycle_state'  => is_array( $metadata ) ? ( $metadata['lifecycle_state'] ?? null ) : null,
				'pr_url'           => is_array( $metadata ) ? ( $metadata['pr_url'] ?? null ) : null,
				'pr_number'        => is_array( $metadata ) ? ( $metadata['pr_number'] ?? null ) : null,
				'last_seen_at'     => is_array( $metadata ) ? ( $metadata['last_seen_at'] ?? null ) : null,
				'liveness'         => $liveness['liveness'],
				'liveness_reason'  => $liveness['reason'],
				'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
				'owner'            => $owner,
				'session'          => $session_view,
				'task'             => $task_view,
				'missing_metadata' => $is_worktree && ! is_array( $metadata ),
				'metadata'         => $metadata,
			);
		}

		return $rows;
	}

	/**
	 * Build best-effort workspace size data from top-level entries.
	 *
	 * @param int $limit Maximum entries to size.
	 * @return array<string,mixed>
	 */
	private function build_workspace_size_report( int $limit ): array {
		if ( '' === $this->workspace_path || ! is_dir( $this->workspace_path ) ) {
			return $this->empty_workspace_size_report( $limit, true );
		}

		$entries = scandir( $this->workspace_path );
		if ( false === $entries ) {
			return $this->empty_workspace_size_report( $limit, true );
		}

		$dirs = array_values( array_filter(
			$entries,
			fn( $entry ) => '.' !== $entry && '..' !== $entry && is_dir( $this->workspace_path . '/' . $entry )
		) );
		sort( $dirs, SORT_NATURAL );

		$total_dirs = count( $dirs );
		$sample     = array_slice( $dirs, 0, $limit );
		$rows       = array();
		$total      = 0;

		foreach ( $sample as $entry ) {
			$path = $this->workspace_path . '/' . $entry;
			$size = $this->directory_size_bytes_best_effort( $path );
			if ( null === $size ) {
				continue;
			}

			$parsed = $this->parse_handle( $entry );
			$total += $size;
			$rows[] = array(
				'handle'      => $entry,
				'repo'        => $parsed['repo'],
				'is_worktree' => ! empty( $parsed['is_worktree'] ),
				'kind'        => $this->classify_workspace_entry_kind( $entry, $parsed, $path ),
				'path'        => $path,
				'bytes'       => $size,
				'human'       => $this->format_bytes( $size ),
			);
		}

		usort( $rows, fn( $a, $b ) => (int) $b['bytes'] <=> (int) $a['bytes'] );
		$scanned_count = count( $sample );

		return array(
			'mode'            => 'best_effort_top_level_du',
			'mode_note'       => 'Workspace size is best-effort: top-level entries are sized with du and capped by size_limit.',
			'size_limit'      => $limit,
			'total_entries'   => $total_dirs,
			'scanned_entries' => $scanned_count,
			'scan_complete'   => $scanned_count >= $total_dirs,
			'total_bytes'     => $total,
			'total_human'     => $this->format_bytes( $total ),
			'by_kind'         => $this->workspace_size_by_kind( $rows ),
			'entries'         => $rows,
			'top_entries'     => array_slice( $rows, 0, 10 ),
		);
	}

	/**
	 * Empty size-report envelope.
	 *
	 * @param int  $limit   Configured size limit.
	 * @param bool $enabled Whether size scanning was requested.
	 * @return array<string,mixed>
	 */
	private function empty_workspace_size_report( int $limit, bool $enabled ): array {
		return array(
			'mode'            => $enabled ? 'best_effort_top_level_du' : 'disabled',
			'mode_note'       => $enabled ? 'Workspace path is unavailable or unreadable; no size data collected.' : 'Size scan disabled by request.',
			'size_limit'      => $limit,
			'total_entries'   => 0,
			'scanned_entries' => 0,
			'scan_complete'   => true,
			'total_bytes'     => 0,
			'total_human'     => $this->format_bytes( 0 ),
			'by_kind'         => array(),
			'entries'         => array(),
			'top_entries'     => array(),
		);
	}

	/**
	 * Best-effort directory size via `du -sk`.
	 *
	 * @param string $path Directory path.
	 * @return int|null Size in bytes, or null when unavailable.
	 */
	private function directory_size_bytes_best_effort( string $path ): ?int {
		if ( ! is_dir( $path ) ) {
			return null;
		}

		$output = array();
		$exit   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Local workspace hygiene needs best-effort disk usage; input path is shell-escaped.
		exec( sprintf( 'du -sk %s 2>/dev/null', escapeshellarg( $path ) ), $output, $exit );
		if ( 0 !== $exit || empty( $output[0] ) ) {
			return null;
		}

		$parts = preg_split( '/\s+/', trim( (string) $output[0] ) );
		$kb    = isset( $parts[0] ) ? (int) $parts[0] : 0;
		return max( 0, $kb ) * 1024;
	}

	/**
	 * Build workspace disk/free-space report.
	 *
	 * @return array<string,mixed>
	 */
	private function build_workspace_disk_report(): array {
		$path  = '' !== $this->workspace_path && is_dir( $this->workspace_path ) ? $this->workspace_path : dirname( $this->workspace_path );
		$free  = '' !== $path ? disk_free_space( $path ) : false;
		$total = '' !== $path ? disk_total_space( $path ) : false;

		$free_bytes  = false === $free ? null : (int) $free;
		$total_bytes = false === $total ? null : (int) $total;

		return array(
			'path'        => $path,
			'free_bytes'  => $free_bytes,
			'free_human'  => null === $free_bytes ? null : $this->format_bytes( $free_bytes ),
			'total_bytes' => $total_bytes,
			'total_human' => null === $total_bytes ? null : $this->format_bytes( $total_bytes ),
		);
	}

	/**
	 * Summarize worktree listing and cleanup-protected counts.
	 *
	 * @param array<int,array> $worktrees Worktree rows.
	 * @param array|null       $cleanup   Cleanup dry-run report.
	 * @return array<string,mixed>
	 */
	private function summarize_workspace_worktrees( array $worktrees, ?array $cleanup ): array {
		$summary = array(
			'total'              => count( $worktrees ),
			'primaries'          => 0,
			'worktrees'          => 0,
			'artifacts'          => 0,
			'external'           => 0,
			'dirty'              => 0,
			'protected_dirty'    => 0,
			'protected_unpushed' => 0,
			'missing_metadata'   => 0,
			'by_liveness'        => array(
				WorktreeContextInjector::LIVENESS_LIVE    => 0,
				WorktreeContextInjector::LIVENESS_STOPPED => 0,
				WorktreeContextInjector::LIVENESS_STALE   => 0,
				WorktreeContextInjector::LIVENESS_UNKNOWN => 0,
			),
			'duplicate_task_groups' => 0,
		);

		foreach ( $worktrees as $row ) {
			if ( ! empty( $row['is_primary'] ) ) {
				++$summary['primaries'];
			} elseif ( ! empty( $row['is_worktree'] ) ) {
				++$summary['worktrees'];
			} elseif ( 'artifact' === (string) ( $row['kind'] ?? '' ) ) {
				++$summary['artifacts'];
			}

			if ( ! empty( $row['external'] ) ) {
				++$summary['external'];
			}

			if ( (int) ( $row['dirty'] ?? 0 ) > 0 ) {
				++$summary['dirty'];
			}

			if ( ! empty( $row['missing_metadata'] ) ) {
				++$summary['missing_metadata'];
			}

			if ( ! empty( $row['is_worktree'] ) ) {
				$liveness = (string) ( $row['liveness'] ?? WorktreeContextInjector::LIVENESS_UNKNOWN );
				if ( ! isset( $summary['by_liveness'][ $liveness ] ) ) {
					$summary['by_liveness'][ $liveness ] = 0;
				}
				++$summary['by_liveness'][ $liveness ];
			}
		}

		$duplicates = WorktreeContextInjector::find_duplicate_task_ownership( $worktrees );
		$summary['duplicate_task_groups'] = count( $duplicates );
		$summary['duplicates']            = $duplicates;

		if ( null !== $cleanup ) {
			$by_reason                     = (array) ( $cleanup['summary']['skipped_by_reason'] ?? array() );
			$summary['protected_dirty']    = (int) ( $by_reason['dirty_worktree'] ?? 0 );
			$summary['protected_unpushed'] = (int) ( $by_reason['unpushed_commits'] ?? 0 );
			$summary['missing_metadata']   = (int) ( $by_reason['missing_metadata'] ?? 0 );
			$summary['external']           = max( $summary['external'], (int) ( $by_reason['external_worktree'] ?? 0 ) );
		}

		return $summary;
	}

	/**
	 * Summarize cleanup dry-run output for hygiene reports.
	 *
	 * @param array|null $cleanup Cleanup report.
	 * @param array|null $error   Cleanup error envelope.
	 * @return array<string,mixed>
	 */
	private function summarize_workspace_cleanup( ?array $cleanup, ?array $error, array $size_entries = array() ): array {
		if ( null === $cleanup ) {
			return array(
				'included' => false,
				'error'    => $error,
			);
		}

		$candidates     = (array) ( $cleanup['candidates'] ?? array() );
		$size_by_handle = array();
		foreach ( $size_entries as $entry ) {
			$handle = (string) ( $entry['handle'] ?? '' );
			if ( '' !== $handle ) {
				$size_by_handle[ $handle ] = array(
					'bytes' => (int) ( $entry['bytes'] ?? 0 ),
					'human' => (string) ( $entry['human'] ?? '' ),
				);
			}
		}
		foreach ( $candidates as &$candidate ) {
			$handle = (string) ( $candidate['handle'] ?? '' );
			if ( isset( $size_by_handle[ $handle ] ) ) {
				$candidate['size_bytes'] = $size_by_handle[ $handle ]['bytes'];
				$candidate['size_human'] = $size_by_handle[ $handle ]['human'];
			}
		}
		unset( $candidate );
		usort( $candidates, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ) );
		return array(
			'included'             => true,
			'dry_run'              => true,
			'skip_github'          => true,
			'inventory_only'       => ! empty( $cleanup['inventory_only'] ),
			'summary'              => $cleanup['summary'] ?? array(),
			'biggest_candidates'   => array_slice( $candidates, 0, 10 ),
			'skipped_by_reason'    => $cleanup['summary']['skipped_by_reason'] ?? array(),
			'candidates_by_signal' => $cleanup['summary']['candidates_by_signal'] ?? array(),
		);
	}

	/**
	 * Report whether DB cleanup storage tables are available for retention hooks.
	 *
	 * @return array<string,mixed>
	 */
	private function cleanup_storage_status(): array {
		$status = array(
			'cleanup_runs_available'  => false,
			'cleanup_items_available' => false,
			'locks_available'         => (bool) ( WorkspaceLockStore::status()['available'] ?? false ),
			'policy_hooks'            => array(
				'datamachine_code_lock_expires_seconds',
				'datamachine_code_lock_released_ttl_seconds',
				'datamachine_code_cleanup_lock_retention_policy',
			),
			'note'                    => 'Cleanup run/item table retention is inactive until those DB tables exist; lock storage is handled separately in datamachine_code_locks.',
		);

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return $status;
		}

		$runs_table                         = $wpdb->prefix . 'datamachine_code_cleanup_runs';
		$items_table                        = $wpdb->prefix . 'datamachine_code_cleanup_items';
		$status['cleanup_runs_available']  = $this->database_table_exists( $runs_table );
		$status['cleanup_items_available'] = $this->database_table_exists( $items_table );
		if ( $status['cleanup_runs_available'] && $status['cleanup_items_available'] ) {
			$status['note'] = 'Cleanup run/item tables are available; future retention can attach to the declared cleanup storage hooks without changing lock ownership.';
		}

		return $status;
	}

	private function database_table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Build the compact retention cleanup report used by automation surfaces.
	 *
	 * @param array|null $worktree_result Whole-worktree cleanup result.
	 * @param array|null $artifact_result Artifact cleanup result.
	 * @param bool       $dry_run         Whether the retention run was non-destructive.
	 * @return array<string,mixed>
	 */
	private function build_workspace_retention_report( ?array $worktree_result, ?array $artifact_result, bool $dry_run ): array {
		$worktree_summary = (array) ( $worktree_result['summary'] ?? array() );
		$artifact_summary = (array) ( $artifact_result['summary'] ?? array() );
		$disk             = $this->build_workspace_disk_report();

		$removed_worktree_bytes = $this->sum_cleanup_rows_bytes( (array) ( $worktree_result['removed'] ?? array() ), 'size_bytes' );
		$removed_artifact_bytes = (int) ( $artifact_summary['removed_size_bytes'] ?? 0 );
		$would_free_bytes       = (int) ( $worktree_summary['total_size_bytes'] ?? 0 ) + (int) ( $artifact_summary['artifact_size_bytes'] ?? 0 );
		$freed_bytes            = $dry_run ? 0 : $removed_worktree_bytes + $removed_artifact_bytes;
		$skip_reasons           = array_merge_recursive(
			(array) ( $worktree_summary['skipped_by_reason'] ?? array() ),
			(array) ( $artifact_summary['skipped_by_reason'] ?? array() )
		);
		$dirty_skipped          = $this->sum_reason_count( $skip_reasons, 'dirty_worktree' );
		$unpushed_skipped       = $this->sum_reason_count( $skip_reasons, 'unpushed_commits' );

		return array(
			'removed_count'                 => (int) ( $worktree_summary['removed'] ?? 0 ) + (int) ( $artifact_summary['removed_artifacts'] ?? 0 ),
			'removed_worktrees'             => (int) ( $worktree_summary['removed'] ?? 0 ),
			'removed_artifacts'             => (int) ( $artifact_summary['removed_artifacts'] ?? 0 ),
			'would_remove_worktrees'        => (int) ( $worktree_summary['would_remove'] ?? 0 ),
			'would_remove_artifacts'        => (int) ( $artifact_summary['would_remove_artifacts'] ?? 0 ),
			'freed_bytes'                   => $freed_bytes,
			'freed_human'                   => $this->format_bytes( $freed_bytes ),
			'would_free_bytes'              => $would_free_bytes,
			'would_free_human'              => $this->format_bytes( $would_free_bytes ),
			'skipped_dirty_unpushed_count'  => $dirty_skipped + $unpushed_skipped,
			'skipped_dirty_count'           => $dirty_skipped,
			'skipped_unpushed_count'        => $unpushed_skipped,
			'remaining_disk_budget_bytes'   => $disk['free_bytes'] ?? null,
			'remaining_disk_budget_human'   => $disk['free_human'] ?? null,
			'remaining_disk_budget_summary' => sprintf( '%s free', (string) ( $disk['free_human'] ?? 'unknown' ) ),
			'worktree_skipped_by_reason'    => (array) ( $worktree_summary['skipped_by_reason'] ?? array() ),
			'artifact_skipped_by_reason'    => (array) ( $artifact_summary['skipped_by_reason'] ?? array() ),
		);
	}

	/**
	 * Sum an integer field across cleanup rows.
	 *
	 * @param array<int,array> $rows  Cleanup rows.
	 * @param string           $field Field to sum.
	 * @return int
	 */
	private function sum_cleanup_rows_bytes( array $rows, string $field ): int {
		$total = 0;
		foreach ( $rows as $row ) {
			$total += max( 0, (int) ( is_array( $row ) ? ( $row[ $field ] ?? 0 ) : 0 ) );
		}
		return $total;
	}

	/**
	 * Read a reason count from a possibly merge_recursive-shaped map.
	 *
	 * @param array  $reasons Reason count map.
	 * @param string $key     Reason key.
	 * @return int
	 */
	private function sum_reason_count( array $reasons, string $key ): int {
		$value = $reasons[ $key ] ?? 0;
		if ( is_array( $value ) ) {
			return array_sum( array_map( 'intval', $value ) );
		}
		return (int) $value;
	}

	/**
	 * Count worktrees by repo.
	 *
	 * @param array<int,array> $worktrees Worktree rows.
	 * @param int              $limit     Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function top_repos_by_worktree_count( array $worktrees, int $limit ): array {
		$counts = array();
		foreach ( $worktrees as $row ) {
			if ( empty( $row['is_worktree'] ) ) {
				continue;
			}
			$repo = (string) ( $row['repo'] ?? '' );
			if ( '' === $repo ) {
				continue;
			}
			$counts[ $repo ] = ( $counts[ $repo ] ?? 0 ) + 1;
		}

		arsort( $counts );
		$rows = array();
		foreach ( array_slice( $counts, 0, $limit, true ) as $repo => $count ) {
			$rows[] = array(
				'repo'           => $repo,
				'worktree_count' => (int) $count,
			);
		}
		return $rows;
	}

	/**
	 * Sum best-effort size rows by repo.
	 *
	 * @param array<int,array> $entries Size rows.
	 * @param int              $limit   Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function top_repos_by_size( array $entries, int $limit ): array {
		$sizes = array();
		foreach ( $entries as $entry ) {
			$repo = (string) ( $entry['repo'] ?? '' );
			if ( '' === $repo ) {
				continue;
			}
			$sizes[ $repo ] = ( $sizes[ $repo ] ?? 0 ) + (int) ( $entry['bytes'] ?? 0 );
		}

		arsort( $sizes );
		$rows = array();
		foreach ( array_slice( $sizes, 0, $limit, true ) as $repo => $bytes ) {
			$rows[] = array(
				'repo'  => $repo,
				'bytes' => (int) $bytes,
				'human' => $this->format_bytes( (int) $bytes ),
			);
		}
		return $rows;
	}

	/**
	 * Sum best-effort size rows by top-level workspace entry kind.
	 *
	 * @param array<int,array> $entries Size rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function workspace_size_by_kind( array $entries ): array {
		$sizes = array(
			'primary'  => 0,
			'worktree' => 0,
			'artifact' => 0,
			'other'    => 0,
		);

		foreach ( $entries as $entry ) {
			$kind = (string) ( $entry['kind'] ?? 'other' );
			if ( ! array_key_exists( $kind, $sizes ) ) {
				$kind = 'other';
			}
			$sizes[ $kind ] += (int) ( $entry['bytes'] ?? 0 );
		}

		$rows = array();
		foreach ( $sizes as $kind => $bytes ) {
			$rows[] = array(
				'kind'  => $kind,
				'bytes' => (int) $bytes,
				'human' => $this->format_bytes( (int) $bytes ),
			);
		}

		usort( $rows, fn( $a, $b ) => (int) $b['bytes'] <=> (int) $a['bytes'] );
		return $rows;
	}

	/**
	 * Classify a top-level workspace directory without probing git state.
	 *
	 * @param string $entry  Directory basename.
	 * @param array  $parsed Parsed workspace handle.
	 * @param string $path   Top-level directory path.
	 * @return string
	 */
	private function classify_workspace_entry_kind( string $entry, array $parsed, string $path ): string {
		if ( ! empty( $parsed['is_worktree'] ) ) {
			return 'worktree';
		}

		if ( is_dir( rtrim( $path, '/' ) . '/.git' ) ) {
			return 'primary';
		}

		$artifact_names = array( '.cache', '.composer', '.npm', '.pnpm-store', '.tmp', 'artifacts', 'cache', 'tmp' );
		if ( in_array( strtolower( $entry ), $artifact_names, true ) ) {
			return 'artifact';
		}

		return '' !== (string) ( $parsed['repo'] ?? '' ) ? 'primary' : 'other';
	}

	/**
	 * Format bytes for reports.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	private function format_bytes( int $bytes ): string {
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$unit_count = count( $units );
		$value      = (float) max( 0, $bytes );
		$index      = 0;
		while ( $value >= 1024 && $index < $unit_count - 1 ) {
			$value /= 1024;
			++$index;
		}

		return sprintf( $index > 0 ? '%.1f %s' : '%.0f %s', $value, $units[ $index ] );
	}

	/**
	 * Remove a worktree.
	 *
	 * Refuses if the worktree has uncommitted changes unless `$force` is true.
	 *
	 * @param string $repo   Primary repo name.
	 * @param string $branch Branch (or slug) of the worktree.
	 * @param bool   $force  Force removal even if dirty.
	 * @return array{success: bool, handle: string, message: string}|\WP_Error
	 */
	public function worktree_remove( string $repo, string $branch, bool $force = false ): array|\WP_Error {
		$repo = $this->sanitize_name( $repo );
		if ( '' === $repo ) {
			return new \WP_Error( 'invalid_repo', 'Repository name is required.', array( 'status' => 400 ) );
		}

		$slug = $this->slugify_branch( $branch );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_branch', 'Branch/slug is required.', array( 'status' => 400 ) );
		}

		$primary_path = $this->get_primary_path( $repo );
		if ( ! is_dir( $primary_path . '/.git' ) ) {
			return new \WP_Error( 'primary_not_found', sprintf( 'Primary checkout for "%s" does not exist.', $repo ), array( 'status' => 404 ) );
		}

		$wt_handle = $repo . '@' . $slug;
		$wt_path   = $this->workspace_path . '/' . $wt_handle;

		if ( ! is_dir( $wt_path ) ) {
			return new \WP_Error( 'worktree_not_found', sprintf( 'Worktree "%s" not found.', $wt_handle ), array( 'status' => 404 ) );
		}

		$result = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			function () use ( $primary_path, $wt_path, $force, $wt_handle ) {
				$cmd    = sprintf( 'worktree remove %s%s', $force ? '--force ' : '', escapeshellarg( $wt_path ) );
				$result = $this->run_git( $primary_path, $cmd );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				WorktreeContextInjector::forget_metadata( $wt_handle );
				$this->worktree_inventory()->delete( $wt_handle );
				return $result;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'handle'  => $wt_handle,
			'message' => sprintf( 'Worktree "%s" removed.', $wt_handle ),
		);
	}

	/**
	 * Prune stale worktree registry entries across all primaries.
	 *
	 * @return array{success: bool, pruned: array}|\WP_Error
	 */
	public function worktree_prune(): array|\WP_Error {
		$pruned = array();

		if ( ! is_dir( $this->workspace_path ) ) {
			return array(
				'success' => true,
				'pruned'  => $pruned,
			);
		}

		$entries = scandir( $this->workspace_path );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_contains( $entry, '@' ) ) {
				continue;
			}
			$primary_path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir( $primary_path . '/.git' ) ) {
				continue;
			}
			$result = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$entry,
				fn() => $this->run_git( $primary_path, 'worktree prune -v' )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$pruned[] = $entry;
		}

		$refresh = $this->worktree_inventory_refresh();
		if ( $refresh instanceof \WP_Error ) {
			return $refresh;
		}

		return array(
			'success' => true,
			'pruned'  => $pruned,
			'inventory' => $refresh,
		);
	}

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

		if ( '' !== $sort && ! in_array( $sort, array( 'size', 'age' ), true ) ) {
			return new \WP_Error( 'invalid_cleanup_sort', 'Invalid cleanup sort. Use size or age.', array( 'status' => 400 ) );
		}

		if ( $inventory_only ) {
			if ( ! $dry_run ) {
				return new \WP_Error( 'inventory_cleanup_requires_dry_run', 'Inventory-only cleanup is review-only. Pass --dry-run, then run full cleanup to apply a reviewed plan.', array( 'status' => 400 ) );
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

		$listing = $this->worktree_list();
		if ( $listing instanceof \WP_Error ) {
			return $listing;
		}

		$protected_branches = array( 'main', 'master', 'trunk', 'develop', 'HEAD' );
		$candidates         = array();
		$skipped            = array();
		$github_cache       = array();
		$worktrees          = array_values( array_filter( (array) $listing['worktrees'], fn( $wt ) => empty( $wt['is_primary'] ) ) );
		$checked            = 0;
		$removed_count      = 0;

		$this->emit_worktree_cleanup_progress( $progress, 'start', '', $checked, count( $worktrees ), $candidates, $skipped, $removed_count, $started_at );

		// Fetch + prune each primary once up-front so upstream-gone signals are fresh.
		$fetched = array();
		$fetch_timeouts = array();

		foreach ( $worktrees as $wt ) {
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

			$dirty_count = (int) ( $wt['dirty'] ?? 0 );

			++$checked;
			$this->emit_worktree_cleanup_progress( $progress, 'checking', (string) $handle, $checked, count( $worktrees ), $candidates, $skipped, $removed_count, $started_at );

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

			if ( $dirty_count > 0 && ! $force ) {
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

		if ( $dry_run ) {
			$this->emit_worktree_cleanup_progress( $progress, 'done', '', $checked, count( $worktrees ), $candidates, $skipped, $removed_count, $started_at );

			return array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => $candidates,
				'removed'    => array(),
				'skipped'    => $skipped,
				'summary'    => $summary,
			);
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

		$this->emit_worktree_cleanup_progress( $progress, 'done', '', $checked, count( $worktrees ), $candidates, $skipped, $removed_count, $started_at );

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
	 * Reconcile lifecycle metadata for unmanaged worktrees without removing anything.
	 *
	 * Dry-runs build a reviewed plan from the current full git worktree listing.
	 * Applying a plan revalidates handle/path/repo/branch before writing metadata.
	 *
	 * @param array $opts Options: dry_run bool, apply_plan array.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_reconcile_metadata( array $opts = array() ): array|\WP_Error {
		$dry_run    = ! empty( $opts['dry_run'] );
		$apply      = ! empty( $opts['apply'] );
		$apply_plan = isset( $opts['apply_plan'] ) && is_array( $opts['apply_plan'] ) ? $opts['apply_plan'] : null;

		if ( null !== $apply_plan ) {
			return $this->apply_worktree_metadata_reconciliation_plan( $apply_plan );
		}

		if ( ! $dry_run && ! $apply ) {
			return new \WP_Error( 'metadata_reconcile_requires_review', 'Metadata reconciliation is dry-run-first. Pass --dry-run to review JSON output, or pass apply=true for DMC-owned lifecycle reconciliation.', array( 'status' => 400 ) );
		}

		$listing = $this->worktree_list();
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$proposals    = array();
		$skipped      = array();
		$github_cache = array();
		$fetched      = array();

		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
			if ( ! empty( $wt['is_primary'] ) ) {
				continue;
			}

			$proposal = $this->build_worktree_metadata_reconciliation_row( $wt, $github_cache, $fetched );
			if ( isset( $proposal['proposal'] ) ) {
				$proposals[] = $proposal['proposal'];
			} elseif ( isset( $proposal['skip'] ) ) {
				$skipped[] = $proposal['skip'];
			}
		}

		$classified_skips = $this->classify_worktree_metadata_reconciliation_skips( $skipped );

		$plan = array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'applied'        => false,
			'generated_at'   => gmdate( 'c' ),
			'workspace_path' => $this->workspace_path,
			'proposals'      => $proposals,
			'written'        => array(),
			'skipped'        => $skipped,
			'still_unsafe'      => $classified_skips['still_unsafe'],
			'external_worktrees' => $classified_skips['external_worktrees'],
			'summary'        => $this->build_worktree_metadata_reconciliation_summary( count( (array) ( $listing['worktrees'] ?? array() ) ), $proposals, array(), $skipped ),
		);

		if ( $apply ) {
			return $this->apply_worktree_metadata_reconciliation_plan( $plan );
		}

		return $plan;
	}

	/**
	 * Build one metadata reconciliation row for a current worktree listing row.
	 *
	 * @param array<string,mixed> $wt Worktree list row.
	 * @return array{proposal?:array<string,mixed>,skip?:array<string,mixed>}
	 */
	private function build_worktree_metadata_reconciliation_row( array $wt, array &$github_cache = array(), array &$fetched = array() ): array {
		$handle   = (string) ( $wt['handle'] ?? '' );
		$repo     = (string) ( $wt['repo'] ?? '' );
		$branch   = (string) ( $wt['branch'] ?? '' );
		$path     = (string) ( $wt['path'] ?? '' );
		$metadata = is_array( $wt['metadata'] ?? null ) ? (array) $wt['metadata'] : array();

		$base_row = array(
			'handle'   => $handle,
			'repo'     => $repo,
			'branch'   => $branch,
			'path'     => $path,
			'metadata' => array() === $metadata ? null : $metadata,
		);

		if ( ! empty( $wt['external'] ) ) {
			return array(
				'skip' => array_merge(
					$base_row,
					array(
						'reason_code' => 'external_worktree',
						'reason'      => 'external worktree outside the DMC workspace',
					)
				),
			);
		}

		$missing = array_values( array_filter( array(
			'' === $handle ? 'handle' : '',
			'' === $repo ? 'repo' : '',
			'' === $branch ? 'branch' : '',
			'' === $path ? 'path' : '',
		) ) );
		if ( array() !== $missing ) {
			return array(
				'skip' => array_merge(
					$base_row,
					array(
						'reason_code'    => 'missing_identity',
						'reason'         => 'current worktree row is missing required identity fields',
						'missing_fields' => $missing,
					)
				),
			);
		}

		$parsed = $this->parse_handle( $handle );
		if ( empty( $parsed['is_worktree'] ) || $parsed['repo'] !== $repo ) {
			return array(
				'skip' => array_merge(
					$base_row,
					array(
						'reason_code' => 'noncanonical_handle',
						'reason'      => 'worktree is not represented by a canonical <repo>@<slug> workspace handle',
					)
				),
			);
		}

		$dirty   = (int) ( $wt['dirty'] ?? 0 );
		$unpushed = $this->count_unpushed_commits( $path );
		if ( is_wp_error( $unpushed ) ) {
			return array(
				'skip' => array_merge(
					$base_row,
					array(
						'reason_code' => 'probe_timeout',
						'reason'      => 'cleanup safety probe failed - leaving lifecycle unchanged: ' . $unpushed->get_error_message(),
					)
				),
			);
		}

		$finalizer_signal = $this->detect_worktree_lifecycle_finalizer_signal( $wt, $metadata, $github_cache, $fetched );
		if ( null !== $finalizer_signal && 'probe-timeout' === ( $finalizer_signal['signal'] ?? '' ) ) {
			return array(
				'skip' => array_merge(
					$base_row,
					array(
						'reason_code' => 'probe_timeout',
						'reason'      => 'merge-signal probe timed out - leaving lifecycle unchanged: ' . $finalizer_signal['reason'],
					)
				),
			);
		}

		if ( null !== $finalizer_signal && ! WorktreeContextInjector::has_cleanup_signal( $metadata ) ) {
			if ( $dirty > 0 || $unpushed > 0 ) {
				return array(
					'skip' => array_merge(
						$base_row,
						array(
							'reason_code' => 'unsafe_cleanup_eligible_state',
							'reason'      => 'merged lifecycle signal found, but dirty or unpushed worktree is not auto-finalized',
							'dirty'       => $dirty,
							'unpushed'    => $unpushed,
							'signal'      => $finalizer_signal['signal'],
						)
					),
				);
			}

			$finalizer_metadata = WorktreeContextInjector::build_finalizer_metadata(
				WorktreeContextInjector::STATE_MERGED,
				isset( $finalizer_signal['pr_url'] ) ? (string) $finalizer_signal['pr_url'] : null
			);
			$proposed           = array_merge(
				$metadata,
				array(
					'handle'       => $handle,
					'repo'         => $repo,
					'branch'       => $branch,
					'path'         => $path,
					'observed_at'  => gmdate( 'c' ),
					'last_seen_at' => gmdate( 'c' ),
				),
				$finalizer_metadata,
				array(
					'auto_finalized_by'     => 'worktree_reconcile_metadata',
					'auto_finalized_signal' => $finalizer_signal['signal'],
					'auto_finalized_reason' => $finalizer_signal['reason'],
				)
			);

			if ( empty( $proposed['created_at'] ) ) {
				$created_at = file_exists( $path ) ? filemtime( $path ) : false;
				if ( false !== $created_at ) {
					$proposed['created_at'] = gmdate( 'c', (int) $created_at );
				}
			}

			return array(
				'proposal' => array_merge(
					$base_row,
					array(
						'reason_code'       => 'auto_finalize_merged',
						'reason'            => 'merged PR or branch state proves lifecycle can be finalized as cleanup_eligible',
						'dirty'             => $dirty,
						'unpushed'          => $unpushed,
						'signal'            => $finalizer_signal['signal'],
						'pr_url'            => $finalizer_signal['pr_url'] ?? null,
						'proposed_metadata' => $proposed,
						'source_map'        => array(
							'handle'          => 'filesystem',
							'repo'            => 'filesystem',
							'branch'          => 'git',
							'path'            => 'git',
							'created_at'      => empty( $metadata['created_at'] ) ? 'filesystem' : 'metadata',
							'observed_at'     => 'reconcile_run',
							'lifecycle_state' => 'merge_signal',
						)
					)
				),
			);
		}

		$proposed   = $metadata;
		$source_map = array();
		$this->set_reconciled_metadata_field( $proposed, $source_map, 'handle', $handle, 'filesystem' );
		$this->set_reconciled_metadata_field( $proposed, $source_map, 'repo', $repo, 'filesystem' );
		$this->set_reconciled_metadata_field( $proposed, $source_map, 'branch', $branch, 'git' );
		$this->set_reconciled_metadata_field( $proposed, $source_map, 'path', $path, 'git' );
		$this->set_reconciled_metadata_field( $proposed, $source_map, 'observed_at', gmdate( 'c' ), 'reconcile_run' );

		$origin_site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$origin_site_url  = function_exists( 'home_url' ) ? (string) home_url() : '';
		if ( empty( $proposed['origin_site'] ) ) {
			$this->set_reconciled_metadata_field( $proposed, $source_map, 'origin_site', '' !== $origin_site_name ? $origin_site_name : $origin_site_url, 'current_site' );
		}
		if ( empty( $proposed['origin_site_name'] ) ) {
			$this->set_reconciled_metadata_field( $proposed, $source_map, 'origin_site_name', $origin_site_name, 'current_site' );
		}
		if ( empty( $proposed['origin_site_url'] ) ) {
			$this->set_reconciled_metadata_field( $proposed, $source_map, 'origin_site_url', $origin_site_url, 'current_site' );
		}

		$created_source = '';
		$created_at     = '';
		foreach ( array( 'created_at', 'timestamp' ) as $key ) {
			if ( ! empty( $metadata[ $key ] ) && false !== strtotime( (string) $metadata[ $key ] ) ) {
				$created_at     = gmdate( 'c', (int) strtotime( (string) $metadata[ $key ] ) );
				$created_source = 'metadata';
				break;
			}
		}
		if ( '' === $created_at ) {
			$mtime = file_exists( $path ) ? filemtime( $path ) : false;
			if ( false !== $mtime ) {
				$created_at     = gmdate( 'c', (int) $mtime );
				$created_source = 'filesystem';
			}
		}
		if ( '' !== $created_at ) {
			$this->set_reconciled_metadata_field( $proposed, $source_map, 'created_at', $created_at, $created_source );
		}

		$invalid_fields = array();
		$existing_state = isset( $metadata['lifecycle_state'] ) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state'] ) : null;
		$raw_state      = isset( $metadata['lifecycle_state'] ) ? (string) $metadata['lifecycle_state'] : '';
		if ( '' !== $raw_state && null === $existing_state ) {
			$invalid_fields[] = 'lifecycle_state';
		}
		if ( null !== $existing_state ) {
			$this->set_reconciled_metadata_field( $proposed, $source_map, 'lifecycle_state', $existing_state, 'metadata' );
		} else {
			$this->set_reconciled_metadata_field( $proposed, $source_map, 'lifecycle_state', WorktreeContextInjector::STATE_ACTIVE, 'operator_plan' );
		}
		$missing_after = array_values( array_filter( array(
			empty( $proposed['created_at'] ) ? 'created_at' : '',
			empty( $proposed['lifecycle_state'] ) ? 'lifecycle_state' : '',
		) ) );
		if ( array() !== $missing_after ) {
			return array(
				'skip' => array_merge(
					$base_row,
					array(
						'reason_code'    => 'insufficient_signal',
						'reason'         => 'not enough stable metadata could be inferred safely',
						'missing_fields' => $missing_after,
					)
				),
			);
		}

		$metadata_missing = array();
		foreach ( array( 'handle', 'repo', 'branch', 'path', 'created_at', 'observed_at', 'lifecycle_state' ) as $field ) {
			if ( ! array_key_exists( $field, $metadata ) || '' === (string) $metadata[ $field ] ) {
				$metadata_missing[] = $field;
			}
		}

		if ( array() === $metadata_missing && array() === $invalid_fields ) {
			return array();
		}

		return array(
			'proposal' => array_merge(
				$base_row,
				array(
					'reason_code'       => 'metadata_backfill',
					'reason'            => 'unmanaged worktree metadata can be reconciled without changing cleanup eligibility',
					'dirty'             => $dirty,
					'unpushed'          => $unpushed,
					'missing_fields'    => $metadata_missing,
					'invalid_fields'    => $invalid_fields,
					'proposed_metadata' => $proposed,
					'source_map'        => $source_map,
				)
			),
		);
	}

	/**
	 * Detect an unambiguous merge signal for lifecycle reconciliation.
	 *
	 * @param array<string,mixed> $wt           Current worktree listing row.
	 * @param array<string,mixed> $metadata     Persisted lifecycle metadata.
	 * @param array               $github_cache Run-local GitHub lookup cache.
	 * @param array               $fetched      Run-local fetched repo cache.
	 * @return array{signal:string,reason:string,pr_url?:string}|null
	 */
	private function detect_worktree_lifecycle_finalizer_signal( array $wt, array $metadata, array &$github_cache, array &$fetched ): ?array {
		$repo         = (string) ( $wt['repo'] ?? '' );
		$branch       = (string) ( $wt['branch'] ?? '' );
		$primary_path = '' !== $repo ? $this->get_primary_path( $repo ) : '';
		if ( '' === $repo || '' === $branch || ! is_dir( $primary_path . '/.git' ) ) {
			return null;
		}

		if ( empty( $fetched[ $repo ] ) ) {
			$fetch = $this->run_git( $primary_path, 'fetch --prune --quiet origin', self::CLEANUP_GIT_PROBE_TIMEOUT );
			if ( $this->is_git_timeout_error( $fetch ) ) {
				return array(
					'signal' => 'probe-timeout',
					'reason' => $fetch->get_error_message(),
				);
			}
			$fetched[ $repo ] = true;
		}

		$pr_signal = $this->detect_stored_pr_merged_signal( $metadata, $github_cache );
		if ( null !== $pr_signal ) {
			return $pr_signal;
		}

		$signal = $this->detect_merge_signal( $primary_path, $repo, $branch, true, $github_cache );
		if ( null === $signal ) {
			return null;
		}

		if ( in_array( (string) ( $signal['signal'] ?? '' ), array( 'upstream-gone', 'local-merged' ), true ) ) {
			return $signal;
		}

		return null;
	}

	/**
	 * Check stored PR metadata for a merged PR signal.
	 *
	 * @param array<string,mixed> $metadata     Persisted lifecycle metadata.
	 * @param array               $github_cache Run-local GitHub lookup cache.
	 * @return array{signal:string,reason:string,pr_url?:string}|null
	 */
	private function detect_stored_pr_merged_signal( array $metadata, array &$github_cache ): ?array {
		$pr_repo   = isset( $metadata['pr_repo'] ) ? (string) $metadata['pr_repo'] : '';
		$pr_number = isset( $metadata['pr_number'] ) ? (int) $metadata['pr_number'] : 0;
		$pr_url    = isset( $metadata['pr_url'] ) ? (string) $metadata['pr_url'] : '';

		if ( ( '' === $pr_repo || $pr_number <= 0 ) && '' !== $pr_url && preg_match( '~^https?://github\.com/([^/]+)/([^/]+)/pull/(\d+)(?:[/?#].*)?$~', $pr_url, $matches ) ) {
			$pr_repo   = $matches[1] . '/' . $matches[2];
			$pr_number = (int) $matches[3];
		}

		if ( '' === $pr_repo || $pr_number <= 0 ) {
			return null;
		}

		$cache_key = $pr_repo . '#' . $pr_number;
		if ( array_key_exists( $cache_key, $github_cache ) ) {
			$pr = $github_cache[ $cache_key ];
		} else {
			$pr = $this->fetch_github_pull_request( $pr_repo, $pr_number );
			$github_cache[ $cache_key ] = $pr;
		}

		if ( ! is_array( $pr ) || empty( $pr['merged_at'] ) ) {
			return null;
		}

		return array(
			'signal' => 'pr-merged',
			'reason' => sprintf( 'stored PR #%d merged (%s)', $pr_number, (string) ( $pr['state'] ?? 'closed' ) ),
			'pr_url' => (string) ( $pr['html_url'] ?? $pr_url ),
		);
	}

	/**
	 * Fetch one pull request from GitHub when API credentials are available.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_github_pull_request( string $slug, int $number ): ?array {
		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			return null;
		}

		// Pass the repo through so credential profiles with `allowed_repos`
		// can win over the global default profile when fetching merge state.
		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat( array( 'repo' => $slug ) );
		if ( empty( $pat ) ) {
			return null;
		}

		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'pulls/' . $number ),
			array(),
			$pat,
			self::CLEANUP_GITHUB_TIMEOUT
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = $response['data'] ?? null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Set a reconciled metadata field and record its source.
	 *
	 * @param array<string,mixed> $metadata Metadata under construction.
	 * @param array<string,string> $source_map Field source map.
	 * @param string $field Field name.
	 * @param mixed  $value Field value.
	 * @param string $source Source label.
	 */
	private function set_reconciled_metadata_field( array &$metadata, array &$source_map, string $field, mixed $value, string $source ): void {
		if ( null === $value || '' === (string) $value ) {
			return;
		}
		$metadata[ $field ]    = $value;
		$source_map[ $field ]  = $source;
	}

	/**
	 * Apply a reviewed metadata reconciliation plan after exact revalidation.
	 *
	 * @param array<string,mixed> $plan Dry-run plan.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_worktree_metadata_reconciliation_plan( array $plan ): array|\WP_Error {
		$planned = $this->extract_worktree_metadata_reconciliation_plan( $plan );
		if ( $planned instanceof \WP_Error ) {
			return $planned;
		}

		$listing = $this->worktree_list();
		if ( $listing instanceof \WP_Error ) {
			return $listing;
		}

		$current_by_handle = array();
		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
			$handle = (string) ( $wt['handle'] ?? '' );
			if ( '' !== $handle ) {
				$current_by_handle[ $handle ] = $wt;
			}
		}

		$written = array();
		$skipped = array();
		foreach ( $planned as $row ) {
			$handle  = (string) ( $row['handle'] ?? '' );
			$current = $current_by_handle[ $handle ] ?? null;
			if ( null === $current ) {
				$skipped[] = $this->build_reconcile_apply_skip( $row, null, 'plan_not_current', 'planned worktree is no longer present in the current listing' );
				continue;
			}

			$mismatches = array();
			foreach ( array( 'repo', 'branch', 'path' ) as $field ) {
				if ( (string) ( $row[ $field ] ?? '' ) !== (string) ( $current[ $field ] ?? '' ) ) {
					$mismatches[] = sprintf( '%s planned=%s current=%s', $field, (string) ( $row[ $field ] ?? '' ), (string) ( $current[ $field ] ?? '' ) );
				}
			}
			if ( array() !== $mismatches ) {
				$skipped[] = $this->build_reconcile_apply_skip( $row, $current, 'plan_identity_mismatch', 'planned identity does not match current row: ' . implode( '; ', $mismatches ) );
				continue;
			}

			$metadata   = (array) ( $row['proposed_metadata'] ?? array() );
			$source_map = (array) ( $row['source_map'] ?? array() );
			$state      = WorktreeContextInjector::normalize_state( (string) ( $metadata['lifecycle_state'] ?? '' ) );
			if ( null === $state ) {
				$skipped[] = $this->build_reconcile_apply_skip( $row, $current, 'invalid_lifecycle_state', 'proposed lifecycle_state is invalid' );
				continue;
			}
			$metadata['lifecycle_state'] = $state;

			if ( WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE === $state ) {
				$dirty    = (int) ( $current['dirty'] ?? 0 );
				$unpushed = $this->count_unpushed_commits( (string) ( $current['path'] ?? '' ) );
				if ( $dirty > 0 || $unpushed > 0 ) {
					$skipped[] = $this->build_reconcile_apply_skip( $row, $current, 'unsafe_cleanup_eligible_state', 'refusing to mark dirty or unpushed worktree cleanup_eligible from a reconciliation plan' );
					continue;
				}
			}

			foreach ( array( 'handle', 'repo', 'branch', 'path', 'created_at', 'observed_at', 'lifecycle_state' ) as $field ) {
				if ( ! isset( $source_map[ $field ] ) || '' === (string) $source_map[ $field ] ) {
					$skipped[] = $this->build_reconcile_apply_skip( $row, $current, 'missing_source_map', sprintf( 'proposed field %s is missing a source', $field ) );
					continue 2;
				}
			}

			$metadata['reconciled_at']      = gmdate( 'c' );
			$metadata['reconciled_sources'] = $source_map;
			WorktreeContextInjector::store_lifecycle_metadata( $handle, $metadata );

			$written[] = array(
				'handle'   => $handle,
				'repo'     => (string) ( $current['repo'] ?? '' ),
				'branch'   => (string) ( $current['branch'] ?? '' ),
				'path'     => (string) ( $current['path'] ?? '' ),
				'metadata' => WorktreeContextInjector::get_metadata( $handle ),
			);
		}

		$classified_skips = $this->classify_worktree_metadata_reconciliation_skips( $skipped );

		return array(
			'success'        => true,
			'dry_run'        => false,
			'applied'        => true,
			'generated_at'   => gmdate( 'c' ),
			'workspace_path' => $this->workspace_path,
			'proposals'      => $planned,
			'written'        => $written,
			'skipped'        => $skipped,
			'still_unsafe'      => $classified_skips['still_unsafe'],
			'external_worktrees' => $classified_skips['external_worktrees'],
			'summary'        => $this->build_worktree_metadata_reconciliation_summary( count( (array) ( $listing['worktrees'] ?? array() ) ), $planned, $written, $skipped ),
		);
	}

	/**
	 * Split reconciliation skips into stable operator-facing buckets.
	 *
	 * @param array<int,array<string,mixed>> $skipped Skipped rows.
	 * @return array{still_unsafe:array<int,array<string,mixed>>,external_worktrees:array<int,array<string,mixed>>}
	 */
	private function classify_worktree_metadata_reconciliation_skips( array $skipped ): array {
		$still_unsafe       = array();
		$external_worktrees = array();

		foreach ( $skipped as $row ) {
			$reason_code = (string) ( $row['reason_code'] ?? '' );
			if ( 'external_worktree' === $reason_code ) {
				$external_worktrees[] = $row;
			}
			if ( in_array( $reason_code, array( 'unsafe_cleanup_eligible_state', 'plan_identity_mismatch', 'plan_not_current' ), true ) ) {
				$still_unsafe[] = $row;
			}
		}

		return array(
			'still_unsafe'      => $still_unsafe,
			'external_worktrees' => $external_worktrees,
		);
	}

	/**
	 * Extract reconciliation proposals from a dry-run plan.
	 *
	 * @param array<string,mixed> $plan Plan file.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function extract_worktree_metadata_reconciliation_plan( array $plan ): array|\WP_Error {
		$proposals = $plan['proposals'] ?? null;
		if ( ! is_array( $proposals ) ) {
			return new \WP_Error( 'invalid_metadata_reconcile_plan', 'Metadata reconciliation plan must contain a proposals array.', array( 'status' => 400 ) );
		}

		foreach ( $proposals as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'invalid_metadata_reconcile_plan', sprintf( 'Plan proposal #%d is not an object.', (int) $index ), array( 'status' => 400 ) );
			}
			foreach ( array( 'handle', 'repo', 'branch', 'path', 'proposed_metadata', 'source_map' ) as $field ) {
				if ( ! array_key_exists( $field, $row ) || ( is_string( $row[ $field ] ) && '' === trim( $row[ $field ] ) ) ) {
					return new \WP_Error( 'invalid_metadata_reconcile_plan', sprintf( 'Plan proposal #%d is missing %s.', (int) $index, $field ), array( 'status' => 400 ) );
				}
			}
		}

		return array_values( $proposals );
	}

	/**
	 * Build an apply skip row.
	 *
	 * @param array<string,mixed>      $planned Planned row.
	 * @param array<string,mixed>|null $current Current row.
	 * @param string                   $code Reason code.
	 * @param string                   $reason Human reason.
	 * @return array<string,mixed>
	 */
	private function build_reconcile_apply_skip( array $planned, ?array $current, string $code, string $reason ): array {
		return array(
			'handle'      => (string) ( $planned['handle'] ?? '' ),
			'repo'        => (string) ( $current['repo'] ?? $planned['repo'] ?? '' ),
			'branch'      => (string) ( $current['branch'] ?? $planned['branch'] ?? '' ),
			'path'        => (string) ( $current['path'] ?? $planned['path'] ?? '' ),
			'reason_code' => $code,
			'reason'      => $reason,
			'planned'     => $planned,
			'current'     => $current,
		);
	}

	/**
	 * Probe the dirty file count for a single worktree path.
	 *
	 * @param string $path Worktree path.
	 * @return int|\WP_Error Dirty file count, or WP_Error when git probe failed.
	 */
	private function probe_worktree_dirty_count( string $path, int $timeout_seconds = 0 ): int|\WP_Error {
		if ( '' === $path || ! is_dir( $path ) ) {
			return new \WP_Error( 'worktree_path_missing', 'worktree path is not a directory', array( 'status' => 400 ) );
		}
		$result = $this->run_git( $path, 'status --porcelain', $timeout_seconds );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) ( $result['output'] ?? '' ) ) ) );
		return count( $lines );
	}

	/**
	 * Build stable reconciliation summary counts.
	 *
	 * @param int   $inspected Worktree rows inspected.
	 * @param array $proposals Proposal rows.
	 * @param array $written Written rows.
	 * @param array $skipped Skipped rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_metadata_reconciliation_summary( int $inspected, array $proposals, array $written, array $skipped ): array {
		$skipped_by_reason = array();
		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}
		ksort( $skipped_by_reason );

		$states = array();
		foreach ( $proposals as $row ) {
			$state            = (string) ( $row['proposed_metadata']['lifecycle_state'] ?? 'unknown' );
			$states[ $state ] = ( $states[ $state ] ?? 0 ) + 1;
		}
		ksort( $states );

		return array(
			'inspected'         => $inspected,
			'proposed'          => count( $proposals ),
			'written'           => count( $written ),
			'skipped'           => count( $skipped ),
			'skipped_by_reason' => $skipped_by_reason,
			'proposed_by_state' => $states,
		);
	}

	/**
	 * Cleanup reconstructable artifact directories inside workspace worktrees.
	 *
	 * Unlike whole-worktree cleanup, this intentionally does not require a merge
	 * signal: clean active worktrees can safely shed build outputs. Applying is
	 * plan-only so every destructive run revalidates the exact worktree and
	 * profile-derived artifact paths from a reviewed dry-run.
	 *
	 * Dry-run is bounded by default to keep huge workspaces (~hundreds of
	 * worktrees) responsive: only the cheap top-level inventory is scanned for
	 * artifact directories, per-worktree git status / unpushed-commit probes are
	 * deferred unless `safety_probes` is requested, and the result is paginated
	 * via `limit` + `offset`. Pass `exhaustive=true` to restore the full scan
	 * (full git status + unpushed checks for every worktree).
	 *
	 * Apply paths revalidate the planned subset only — they pass `only_handles`
	 * derived from the plan into the builder so safety probes run against the
	 * planned worktrees rather than the entire workspace.
	 *
	 * @param array $opts Cleanup options (dry_run, force, apply_plan, limit,
	 *                    offset, exhaustive, safety_probes).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_cleanup_artifacts( array $opts = array() ): array|\WP_Error {
		$dry_run    = ! empty( $opts['dry_run'] );
		$force      = ! empty( $opts['force'] );
		$apply_plan = isset( $opts['apply_plan'] ) && is_array( $opts['apply_plan'] ) ? $opts['apply_plan'] : null;
		$exhaustive = ! empty( $opts['exhaustive'] );
		$limit      = isset( $opts['limit'] ) ? (int) $opts['limit'] : self::ARTIFACT_CLEANUP_DEFAULT_LIMIT;
		$offset     = isset( $opts['offset'] ) ? max( 0, (int) $opts['offset'] ) : 0;
		// Allow callers to opt out of bounded mode entirely.
		if ( $exhaustive ) {
			$limit = 0;
		}
		// Apply paths default to safety probing (small subset). Dry-run defaults
		// to skipping the per-worktree git probes unless explicitly requested or
		// the caller asked for exhaustive mode.
		$safety_probes = array_key_exists( 'safety_probes', $opts )
			? (bool) $opts['safety_probes']
			: ( $exhaustive || null !== $apply_plan );

		if ( null !== $apply_plan ) {
			$dry_run = false;
		}

		if ( ! $dry_run && null === $apply_plan ) {
			return new \WP_Error( 'artifact_cleanup_plan_required', 'Artifact cleanup applies through reviewed JSON only on this low-level command. Prefer workspace cleanup run --mode=artifacts for daily cleanup; use --dry-run first and --apply-plan=<file> only as an escape hatch.', array( 'status' => 400 ) );
		}

		$only_handles = null;
		$planned      = null;
		if ( null !== $apply_plan ) {
			$planned = $this->extract_worktree_artifact_cleanup_plan_candidates( $apply_plan );
			if ( $planned instanceof \WP_Error ) {
				return $planned;
			}
			$only_handles = array();
			foreach ( $planned as $row ) {
				$handle = (string) ( $row['handle'] ?? '' );
				if ( '' !== $handle ) {
					$only_handles[ $handle ] = true;
				}
			}
			$only_handles = array_keys( $only_handles );
		}

		$plan = $this->build_worktree_artifact_cleanup_plan(
			$force,
			array(
				'limit'         => $limit,
				'offset'        => $offset,
				'only_handles'  => $only_handles,
				'safety_probes' => $safety_probes,
			)
		);
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$candidates = $plan['candidates'];
		$skipped    = $plan['skipped'];
		$pagination = $plan['pagination'] ?? null;

		if ( null !== $planned ) {
			$scoped     = $this->scope_worktree_artifact_cleanup_to_plan( $planned, $candidates, $skipped );
			$candidates = $scoped['candidates'];
			$skipped    = $scoped['skipped'];
		}

		$summary = $this->build_worktree_artifact_cleanup_summary( $candidates, array(), $skipped );
		if ( null !== $pagination ) {
			$summary['pagination'] = $pagination;
		}

		if ( $dry_run ) {
			$response = array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => $candidates,
				'removed'    => array(),
				'skipped'    => $skipped,
				'summary'    => $summary,
			);
			if ( null !== $pagination ) {
				$response['pagination'] = $pagination;
			}
			return $response;
		}

		$removed = array();
		foreach ( $candidates as $candidate ) {
			$removed_artifacts = array();
			$failed            = false;

			foreach ( (array) ( $candidate['artifacts'] ?? array() ) as $artifact ) {
				$remove = $this->remove_worktree_artifact_path( (string) $candidate['path'], (string) ( $artifact['path'] ?? '' ) );
				if ( $remove instanceof \WP_Error ) {
					$skipped[] = array(
						'handle'      => $candidate['handle'] ?? '',
						'repo'        => $candidate['repo'] ?? '',
						'branch'      => $candidate['branch'] ?? '',
						'path'        => $candidate['path'] ?? '',
						'reason_code' => 'artifact_remove_failed',
						'reason'      => sprintf( 'failed to remove artifact %s: %s', (string) ( $artifact['path'] ?? '' ), $remove->get_error_message() ),
						'artifacts'   => array( $artifact ),
					);
					$failed = true;
					break;
				}

				$removed_artifacts[] = $artifact;
			}

			if ( $failed ) {
				continue;
			}

			$removed[] = array_merge( $candidate, array( 'artifacts' => $removed_artifacts ) );
		}

		$apply_summary = $this->build_worktree_artifact_cleanup_summary( $candidates, $removed, $skipped );
		if ( null !== $pagination ) {
			$apply_summary['pagination'] = $pagination;
		}
		$response = array(
			'success'    => true,
			'dry_run'    => false,
			'candidates' => $candidates,
			'removed'    => $removed,
			'skipped'    => $skipped,
			'summary'    => $apply_summary,
		);
		if ( null !== $pagination ) {
			$response['pagination'] = $pagination;
		}
		return $response;
	}

	/**
	 * Freeze a non-destructive workspace cleanup plan for chunked execution.
	 *
	 * The plan deliberately stops at reviewable data. It does not apply any
	 * cleanup operation; later jobs can consume the emitted chunks and revalidate
	 * each row against current state before mutating the workspace.
	 *
	 * @param array<string,mixed> $opts Plan options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_cleanup_plan( array $opts = array() ): array|\WP_Error {
		$inputs = array(
			'force_artifact_cleanup' => ! empty( $opts['force_artifact_cleanup'] ),
			'include_artifacts'      => array_key_exists( 'include_artifacts', $opts ) ? (bool) $opts['include_artifacts'] : true,
			'include_worktrees'      => array_key_exists( 'include_worktrees', $opts ) ? (bool) $opts['include_worktrees'] : true,
			'include_resolvers'      => ! empty( $opts['include_resolvers'] ),
			'worktree_older_than'    => isset( $opts['worktree_older_than'] ) ? trim( (string) $opts['worktree_older_than'] ) : '',
			'worktree_sort'          => isset( $opts['worktree_sort'] ) ? trim( (string) $opts['worktree_sort'] ) : '',
		);

		$artifact_plan = array(
			'candidates' => array(),
			'skipped'    => array(),
			'summary'    => array(),
		);
		if ( $inputs['include_artifacts'] ) {
			// Workspace cleanup plan is the source-of-truth orchestrator that
			// later chunks/jobs consume — opt into the exhaustive scan so all
			// safe worktrees are reviewed, not just the bounded dry-run page.
			$artifact_plan = $this->worktree_cleanup_artifacts(
				array(
					'dry_run'    => true,
					'force'      => $inputs['force_artifact_cleanup'],
					'exhaustive' => true,
				)
			);
			if ( $artifact_plan instanceof \WP_Error ) {
				return $artifact_plan;
			}
		}

		$worktree_plan = array( 'candidates' => array(), 'skipped' => array(), 'summary' => array() );
		if ( $inputs['include_worktrees'] ) {
			$worktree_plan = $this->worktree_cleanup_merged(
				array(
					'dry_run'        => true,
					'skip_github'    => true,
					'inventory_only' => true,
					'older_than'     => $inputs['worktree_older_than'],
					'sort'           => $inputs['worktree_sort'],
				)
			);
			if ( $worktree_plan instanceof \WP_Error ) {
				return $worktree_plan;
			}
		}

		$rows = array(
			'artifact_cleanup' => $this->prepare_cleanup_plan_rows( 'artifact_cleanup', (array) ( $artifact_plan['candidates'] ?? array() ), 'safe' ),
			'worktree_removal' => $this->prepare_cleanup_plan_rows( 'worktree_removal', (array) ( $worktree_plan['candidates'] ?? array() ), 'reviewed_destructive' ),
			'resolver'         => $inputs['include_resolvers'] ? $this->build_cleanup_plan_resolver_rows( (array) ( $worktree_plan['skipped'] ?? array() ) ) : array(),
		);

		$summary = $this->build_cleanup_plan_summary( $rows );
		$plan    = array(
			'success'        => true,
			'mode'           => 'cleanup_plan',
			'generated_at'   => gmdate( 'c' ),
			'workspace_path' => $this->workspace_path,
			'inputs'         => $inputs,
			'safety_policy'  => array(
				'applies_inline'               => false,
				'artifact_cleanup'            => 'apply-plan must revalidate profile-derived artifact paths before deletion',
				'worktree_removal'            => 'apply-plan must re-run dirty, unpushed, identity, lifecycle, containment, and primary protections before deletion',
				'resolver'                    => 'resolver rows may gather merge signals but cannot delete worktrees',
				'destructive_rows_need_review' => true,
			),
			'plans'          => array(
				'artifact_cleanup' => $artifact_plan,
				'worktree_removal' => $worktree_plan,
			),
			'rows'           => $rows,
			'summary'        => $summary,
		);
		$plan['plan_id'] = $this->stable_cleanup_hash(
			array(
				'inputs' => $inputs,
				'rows'   => $this->cleanup_row_ids( $rows ),
			),
			'cleanup-plan'
		);

		return $plan;
	}

	/**
	 * Build bounded cleanup chunks from a frozen cleanup plan.
	 *
	 * @param array<string,mixed> $opts Chunk options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_cleanup_plan_chunks( array $opts = array() ): array|\WP_Error {
		$chunk_size = isset( $opts['chunk_size'] ) ? (int) $opts['chunk_size'] : 10;
		$chunk_size = max( 1, min( 50, $chunk_size ) );
		$plan       = isset( $opts['plan'] ) && is_array( $opts['plan'] ) ? $opts['plan'] : $this->workspace_cleanup_plan( $opts );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$plan_id = (string) ( $plan['plan_id'] ?? '' );
		if ( '' === $plan_id ) {
			$plan_id = $this->stable_cleanup_hash( array( 'rows' => $this->cleanup_row_ids( (array) ( $plan['rows'] ?? array() ) ) ), 'cleanup-plan' );
			$plan['plan_id'] = $plan_id;
		}

		$chunks = array();
		foreach ( (array) ( $plan['rows'] ?? array() ) as $type => $rows ) {
			$groups = array();
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( empty( $row['row_id'] ) ) {
					$row['row_id'] = $this->stable_cleanup_row_id( (string) $type, $row );
				}
				$class              = (string) ( $row['safety_class'] ?? 'unknown' );
				$groups[ $class ][] = $row;
			}

			ksort( $groups );
			foreach ( $groups as $safety_class => $group_rows ) {
				usort( $group_rows, fn( $a, $b ) => (string) ( $a['row_id'] ?? '' ) <=> (string) ( $b['row_id'] ?? '' ) );
				$index = 0;
				foreach ( array_chunk( $group_rows, $chunk_size ) as $chunk_rows ) {
					++$index;
					$row_ids  = array_map( fn( $row ) => (string) ( $row['row_id'] ?? '' ), $chunk_rows );
					$chunks[] = array(
						'chunk_id'      => $this->stable_cleanup_hash( array( $plan_id, $type, $safety_class, $index, $row_ids ), 'cleanup-chunk' ),
						'plan_id'       => $plan_id,
						'type'          => (string) $type,
						'safety_class'  => $safety_class,
						'index'         => $index,
						'chunk_size'    => count( $chunk_rows ),
						'max_rows'      => $chunk_size,
						'row_ids'       => $row_ids,
						'rows'          => $chunk_rows,
						'idempotency'   => array(
							'key'                 => $this->stable_cleanup_hash( array( $plan_id, $row_ids ), 'cleanup-idempotency' ),
							'revalidate_before_apply' => true,
						),
						'workspace_path' => $plan['workspace_path'] ?? $this->workspace_path,
					);
				}
			}
		}

		return array(
			'success'      => true,
			'mode'         => 'cleanup_plan_chunks',
			'generated_at' => gmdate( 'c' ),
			'plan_id'      => $plan_id,
			'chunk_size'   => $chunk_size,
			'plan'         => $plan,
			'chunks'       => $chunks,
			'summary'      => $this->build_cleanup_chunk_summary( $chunks, $plan ),
		);
	}

	/**
	 * Add stable row metadata to cleanup plan rows.
	 *
	 * @param string             $type         Cleanup row type.
	 * @param array<int,array>   $rows         Raw plan rows.
	 * @param string             $safety_class Safety class.
	 * @return array<int,array<string,mixed>>
	 */
	private function prepare_cleanup_plan_rows( string $type, array $rows, string $safety_class ): array {
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['row_type']     = $type;
			$row['safety_class'] = $safety_class;
			$row['row_id']       = $this->stable_cleanup_row_id( $type, $row );
			$result[]            = $row;
		}
		return $result;
	}

	/**
	 * Build optional resolver rows from ambiguous inventory-only cleanup skips.
	 *
	 * @param array<int,array> $skipped Inventory skipped rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_cleanup_plan_resolver_rows( array $skipped ): array {
		$rows = array();
		foreach ( $skipped as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$reason = (string) ( $row['reason_code'] ?? '' );
			if ( ! in_array( $reason, array( 'needs_metadata_reconcile', 'requires_full_scan', 'lifecycle_reconciliation_candidate', 'active_no_signal', 'no_inventory_cleanup_signal' ), true ) ) {
				continue;
			}

			$resolver_type = match ( $reason ) {
				'needs_metadata_reconcile', 'requires_full_scan' => 'metadata_reconciliation',
				'lifecycle_reconciliation_candidate' => 'lifecycle_reconciliation',
				default => 'merge_signal',
			};
			$next_action = match ( $resolver_type ) {
				'metadata_reconciliation'  => 'workspace worktree reconcile-metadata --dry-run --format=json',
				'lifecycle_reconciliation' => 'workspace worktree cleanup --dry-run --format=json',
				default                    => 'workspace worktree cleanup --dry-run --skip-github --format=json',
			};

			$resolver = array(
				'handle'       => (string) ( $row['handle'] ?? '' ),
				'repo'         => (string) ( $row['repo'] ?? '' ),
				'branch'       => (string) ( $row['branch'] ?? '' ),
				'path'         => (string) ( $row['path'] ?? '' ),
				'created_at'   => $row['created_at'] ?? null,
				'metadata'     => $row['metadata'] ?? null,
				'resolver'     => $resolver_type,
				'next_action'  => $next_action,
				'reason_code'  => $reason,
				'reason'       => 'resolve merge or lifecycle cleanup signal before any worktree removal chunk is emitted',
				'row_type'     => 'resolver',
				'safety_class' => 'read_only',
			);
			$resolver['row_id'] = $this->stable_cleanup_row_id( 'resolver', $resolver );
			$rows[]             = $resolver;
		}
		return $rows;
	}

	/**
	 * Build stable cleanup plan summary counts.
	 *
	 * @param array<string,array<int,array>> $rows Rows keyed by type.
	 * @return array<string,mixed>
	 */
	private function build_cleanup_plan_summary( array $rows ): array {
		$counts      = array();
		$byte_totals = array();
		$total_rows  = 0;
		$total_bytes = 0;

		foreach ( $rows as $type => $typed_rows ) {
			$counts[ $type ]      = count( (array) $typed_rows );
			$byte_totals[ $type ] = 0;
			$total_rows          += $counts[ $type ];
			foreach ( (array) $typed_rows as $row ) {
				$bytes = max( 0, (int) ( $row['artifact_size_bytes'] ?? $row['size_bytes'] ?? 0 ) );
				$byte_totals[ $type ] += $bytes;
				$total_bytes          += $bytes;
			}
		}

		ksort( $counts );
		ksort( $byte_totals );
		return array(
			'total_rows'       => $total_rows,
			'rows_by_type'     => $counts,
			'byte_totals'      => $byte_totals,
			'total_size_bytes' => $total_bytes,
		);
	}

	/**
	 * Build chunk summary counts.
	 *
	 * @param array<int,array>    $chunks Chunks.
	 * @param array<string,mixed> $plan   Frozen plan.
	 * @return array<string,mixed>
	 */
	private function build_cleanup_chunk_summary( array $chunks, array $plan ): array {
		$chunks_by_type   = array();
		$chunks_by_safety = array();
		$rows_by_type     = array();
		foreach ( $chunks as $chunk ) {
			$type                         = (string) ( $chunk['type'] ?? 'unknown' );
			$class                        = (string) ( $chunk['safety_class'] ?? 'unknown' );
			$chunks_by_type[ $type ]       = ( $chunks_by_type[ $type ] ?? 0 ) + 1;
			$chunks_by_safety[ $class ]    = ( $chunks_by_safety[ $class ] ?? 0 ) + 1;
			$rows_by_type[ $type ]         = ( $rows_by_type[ $type ] ?? 0 ) + (int) ( $chunk['chunk_size'] ?? 0 );
		}
		ksort( $chunks_by_type );
		ksort( $chunks_by_safety );
		ksort( $rows_by_type );

		return array(
			'total_chunks'     => count( $chunks ),
			'chunks_by_type'   => $chunks_by_type,
			'chunks_by_safety' => $chunks_by_safety,
			'rows_by_type'     => $rows_by_type,
			'plan_summary'     => $plan['summary'] ?? array(),
		);
	}

	/**
	 * Return row IDs keyed by type for deterministic plan IDs.
	 *
	 * @param array<string,array<int,array>> $rows Rows keyed by type.
	 * @return array<string,array<int,string>>
	 */
	private function cleanup_row_ids( array $rows ): array {
		$ids = array();
		foreach ( $rows as $type => $typed_rows ) {
			$ids[ $type ] = array_map( fn( $row ) => (string) ( is_array( $row ) ? ( $row['row_id'] ?? '' ) : '' ), (array) $typed_rows );
			sort( $ids[ $type ] );
		}
		ksort( $ids );
		return $ids;
	}

	/**
	 * Build a stable ID for one cleanup row.
	 *
	 * @param string             $type Row type.
	 * @param array<string,mixed> $row Row payload.
	 * @return string
	 */
	private function stable_cleanup_row_id( string $type, array $row ): string {
		$fingerprint = array(
			'type'      => $type,
			'handle'    => (string) ( $row['handle'] ?? '' ),
			'repo'      => (string) ( $row['repo'] ?? '' ),
			'branch'    => (string) ( $row['branch'] ?? '' ),
			'path'      => (string) ( $row['path'] ?? '' ),
			'artifacts' => array(),
			'fields'    => array_values( (array) ( $row['missing_fields'] ?? array() ) ),
			'reason'    => (string) ( $row['reason_code'] ?? '' ),
		);
		foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
			if ( is_array( $artifact ) ) {
				$fingerprint['artifacts'][] = (string) ( $artifact['path'] ?? '' );
			}
		}
		sort( $fingerprint['artifacts'] );
		sort( $fingerprint['fields'] );

		return $this->stable_cleanup_hash( $fingerprint, 'cleanup-row' );
	}

	/**
	 * Hash cleanup data after stable key sorting.
	 *
	 * @param mixed  $value  Value to hash.
	 * @param string $prefix Identifier prefix.
	 * @return string
	 */
	private function stable_cleanup_hash( mixed $value, string $prefix ): string {
		$this->ksort_recursive( $value );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) : json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded || '' === $encoded ) {
			$encoded = serialize( $value );
		}
		return $prefix . '-' . substr( hash( 'sha256', $encoded ), 0, 24 );
	}

	/**
	 * Sort array keys recursively for stable hashing.
	 *
	 * @param mixed $value Value to normalize.
	 * @return void
	 */
	private function ksort_recursive( mixed &$value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as &$child ) {
			$this->ksort_recursive( $child );
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
	}

	/**
	 * Build or apply a disk-pressure emergency cleanup plan.
	 *
	 * The dry-run path intentionally uses only top-level workspace inventory,
	 * lifecycle metadata, directory size estimates, and artifact profiles. It does
	 * not run per-worktree git status, fetch, or GitHub checks before returning a
	 * reviewable plan. Applying a reviewed plan revalidates the current worktree
	 * list and keeps dirty/unpushed worktree deletion behind explicit force.
	 *
	 * @param array $opts Emergency cleanup options (dry_run, force, apply_plan).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_emergency_cleanup( array $opts = array() ): array|\WP_Error {
		$force      = ! empty( $opts['force'] );
		$apply_plan = isset( $opts['apply_plan'] ) && is_array( $opts['apply_plan'] ) ? $opts['apply_plan'] : null;
		$dry_run    = null === $apply_plan;

		if ( null !== $apply_plan ) {
			return $this->apply_worktree_emergency_cleanup_plan( $apply_plan, $force );
		}

		$plan = $this->build_worktree_emergency_cleanup_plan();
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$plan['dry_run'] = $dry_run;
		return $plan;
	}

	/**
	 * Build a fast emergency cleanup plan from workspace inventory only.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_worktree_emergency_cleanup_plan(): array|\WP_Error {
		$artifact_candidates = array();
		$worktree_candidates = array();
		$skipped             = array();

		foreach ( $this->build_workspace_inventory_rows() as $wt ) {
			if ( ! empty( $wt['is_primary'] ) ) {
				continue;
			}

			$handle     = (string) ( $wt['handle'] ?? '' );
			$repo       = (string) ( $wt['repo'] ?? '' );
			$branch     = $this->emergency_inventory_branch( $wt );
			$path       = (string) ( $wt['path'] ?? '' );
			$metadata   = is_array( $wt['metadata'] ?? null ) ? (array) $wt['metadata'] : null;
			$created_at = is_string( $wt['created_at'] ?? null ) ? (string) $wt['created_at'] : null;
			$disk       = $this->build_worktree_disk_report( $repo, $path, true, $created_at, $metadata );
			$base_row   = array_merge(
				array(
					'handle'     => $handle,
					'repo'       => $repo,
					'branch'     => $branch,
					'path'       => $path,
					'created_at' => $created_at,
					'metadata'   => $metadata,
				),
				$disk
			);

			if ( '' === $handle || '' === $repo || '' === $branch || '' === $path ) {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => 'missing_inventory_identity',
					'reason'      => 'inventory row is missing handle, repo, branch, or path',
				) );
				continue;
			}

			if ( $this->is_active_studio_symlink_target( $path ) ) {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => 'active_symlink_target',
					'reason'      => 'worktree is an active plugin/theme symlink target',
				) );
				continue;
			}

			if ( ! empty( $disk['artifacts'] ) ) {
				$artifact_candidates[] = array_merge( $base_row, array(
					'artifacts'           => $disk['artifacts'],
					'artifact_count'      => count( (array) $disk['artifacts'] ),
					'artifact_size_bytes' => (int) ( $disk['artifact_size_bytes'] ?? 0 ),
					'reason_code'         => 'profile_artifacts',
					'reason'              => 'profile-derived reconstructable artifacts can be removed first under disk pressure',
				) );
			}

			$lifecycle_state = is_array( $metadata ) ? (string) ( $metadata['lifecycle_state'] ?? '' ) : '';
			if ( in_array( $lifecycle_state, $this->emergency_cleanup_lifecycle_states(), true ) ) {
				$worktree_candidates[] = array_merge( $base_row, array(
					'dirty'       => 0,
					'signal'      => $lifecycle_state,
					'reason_code' => $lifecycle_state,
					'reason'      => sprintf( 'worktree lifecycle state is %s; deletion requires reviewed apply-plan revalidation', $lifecycle_state ),
					'pr_url'      => $metadata['pr_url'] ?? null,
				) );
			} else {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => is_array( $metadata ) ? 'no_emergency_worktree_signal' : 'requires_metadata_or_full_scan',
					'reason'      => is_array( $metadata ) ? 'no finalized or cleanup-eligible lifecycle signal for worktree deletion' : 'missing lifecycle metadata; emergency mode will only consider artifacts from this row',
				) );
			}
		}

		usort( $artifact_candidates, fn( $a, $b ) => (int) ( $b['artifact_size_bytes'] ?? 0 ) <=> (int) ( $a['artifact_size_bytes'] ?? 0 ) );
		usort( $worktree_candidates, fn( $a, $b ) => (int) ( $b['age_days'] ?? -1 ) <=> (int) ( $a['age_days'] ?? -1 ) );

		return array(
			'success'             => true,
			'mode'                => 'emergency',
			'dry_run'             => true,
			'generated_at'        => gmdate( 'c' ),
			'workspace_path'      => $this->workspace_path,
			'artifact_candidates' => $artifact_candidates,
			'worktree_candidates' => $worktree_candidates,
			'removed_artifacts'   => array(),
			'removed_worktrees'   => array(),
			'skipped'             => $skipped,
			'summary'             => $this->build_worktree_emergency_cleanup_summary( $artifact_candidates, $worktree_candidates, array(), array(), $skipped ),
		);
	}

	/**
	 * Apply a reviewed emergency cleanup plan.
	 *
	 * @param array<string,mixed> $plan  Reviewed emergency cleanup plan.
	 * @param bool                $force Whether to allow dirty/unpushed worktree deletion.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_worktree_emergency_cleanup_plan( array $plan, bool $force ): array|\WP_Error {
		$planned_artifacts = $this->extract_emergency_plan_rows( $plan, 'artifact_candidates' );
		if ( $planned_artifacts instanceof \WP_Error ) {
			return $planned_artifacts;
		}
		$planned_worktrees = $this->extract_emergency_plan_rows( $plan, 'worktree_candidates' );
		if ( $planned_worktrees instanceof \WP_Error ) {
			return $planned_worktrees;
		}

		$listing = $this->worktree_list();
		if ( $listing instanceof \WP_Error ) {
			return $listing;
		}

		$current_by_handle = array();
		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
			if ( ! empty( $wt['is_primary'] ) ) {
				continue;
			}
			$handle = (string) ( $wt['handle'] ?? '' );
			if ( '' !== $handle ) {
				$current_by_handle[ $handle ] = $wt;
			}
		}

		$removed_artifacts = array();
		$removed_worktrees = array();
		$skipped           = array();

		foreach ( $planned_artifacts as $row ) {
			$current = $current_by_handle[ (string) ( $row['handle'] ?? '' ) ] ?? null;
			if ( null === $current || ! $this->emergency_row_identity_matches_current( $row, $current, false ) ) {
				$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'emergency_artifact_plan_not_current', 'planned artifact row no longer matches the current worktree' );
				continue;
			}

			$removed_for_row = array();
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$relative = is_array( $artifact ) ? (string) ( $artifact['path'] ?? '' ) : '';
				$remove   = $this->remove_worktree_artifact_path( (string) ( $current['path'] ?? '' ), $relative );
				if ( $remove instanceof \WP_Error ) {
					$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'emergency_artifact_remove_failed', sprintf( 'artifact %s removal failed: %s', $relative, $remove->get_error_message() ) );
					continue 2;
				}
				$removed_for_row[] = $artifact;
			}

			$removed_artifacts[] = array_merge( $row, array( 'artifacts' => $removed_for_row ) );
		}

		foreach ( $planned_worktrees as $row ) {
			$current = $current_by_handle[ (string) ( $row['handle'] ?? '' ) ] ?? null;
			if ( null === $current || ! $this->emergency_row_identity_matches_current( $row, $current, true ) ) {
				$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'emergency_worktree_plan_not_current', 'planned worktree row no longer matches the current worktree' );
				continue;
			}

			$metadata = is_array( $current['metadata'] ?? null ) ? (array) $current['metadata'] : array();
			$state    = (string) ( $metadata['lifecycle_state'] ?? '' );
			if ( ! in_array( $state, $this->emergency_cleanup_lifecycle_states(), true ) ) {
				$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'emergency_lifecycle_not_current', 'current lifecycle state is no longer finalized or cleanup-eligible' );
				continue;
			}

			$dirty = (int) ( $current['dirty'] ?? 0 );
			if ( $dirty > 0 && ! $force ) {
				$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'dirty_worktree', sprintf( 'working tree dirty (%d files) - pass --force only after human review', $dirty ) );
				continue;
			}

			$unpushed = $this->count_unpushed_commits( (string) ( $current['path'] ?? '' ) );
			if ( $unpushed > 0 && ! $force ) {
				$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'unpushed_commits', sprintf( '%d unpushed commit(s) - pass --force only after human review', $unpushed ) );
				continue;
			}

			$remove = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				(string) ( $current['repo'] ?? '' ),
				fn() => $this->remove_worktree_by_path( (string) ( $current['repo'] ?? '' ), (string) ( $current['branch'] ?? '' ), (string) ( $current['path'] ?? '' ), $force )
			);
			if ( $remove instanceof \WP_Error ) {
				$skipped[] = $this->build_emergency_apply_skip( $row, $current, 'emergency_worktree_remove_failed', 'worktree removal failed: ' . $remove->get_error_message() );
				continue;
			}

			$removed_worktrees[] = array_merge( $row, array( 'current' => $current ) );
		}

		$this->worktree_prune();

		return array(
			'success'             => true,
			'mode'                => 'emergency',
			'dry_run'             => false,
			'generated_at'        => gmdate( 'c' ),
			'workspace_path'      => $this->workspace_path,
			'artifact_candidates' => $planned_artifacts,
			'worktree_candidates' => $planned_worktrees,
			'removed_artifacts'   => $removed_artifacts,
			'removed_worktrees'   => $removed_worktrees,
			'skipped'             => $skipped,
			'summary'             => $this->build_worktree_emergency_cleanup_summary( $planned_artifacts, $planned_worktrees, $removed_artifacts, $removed_worktrees, $skipped ),
		);
	}

	/**
	 * Lifecycle states that are eligible for emergency worktree deletion review.
	 *
	 * @return array<int,string>
	 */
	private function emergency_cleanup_lifecycle_states(): array {
		return array(
			WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
			WorktreeContextInjector::STATE_MERGED,
			WorktreeContextInjector::STATE_CLOSED,
			WorktreeContextInjector::STATE_ABANDONED,
		);
	}

	/**
	 * Resolve the best branch value available from cheap inventory metadata.
	 *
	 * @param array<string,mixed> $wt Inventory row.
	 * @return string
	 */
	private function emergency_inventory_branch( array $wt ): string {
		$metadata = is_array( $wt['metadata'] ?? null ) ? (array) $wt['metadata'] : array();
		foreach ( array( $metadata['branch'] ?? '', $metadata['branch_name'] ?? '', $wt['branch_slug'] ?? '' ) as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Extract emergency cleanup rows from a reviewed plan.
	 *
	 * @param array<string,mixed> $plan Plan object.
	 * @param string              $key  Plan row key.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function extract_emergency_plan_rows( array $plan, string $key ): array|\WP_Error {
		$rows = $plan[ $key ] ?? null;
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'invalid_emergency_cleanup_plan', sprintf( 'Emergency cleanup plan must contain a %s array.', $key ), array( 'status' => 400 ) );
		}

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'invalid_emergency_cleanup_plan', sprintf( 'Emergency cleanup %s row #%d is not an object.', $key, (int) $index ), array( 'status' => 400 ) );
			}
			foreach ( array( 'handle', 'repo', 'branch', 'path' ) as $field ) {
				if ( '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
					return new \WP_Error( 'invalid_emergency_cleanup_plan', sprintf( 'Emergency cleanup %s row #%d is missing %s.', $key, (int) $index, $field ), array( 'status' => 400 ) );
				}
			}
			if ( 'artifact_candidates' === $key && ( ! isset( $row['artifacts'] ) || ! is_array( $row['artifacts'] ) || array() === $row['artifacts'] ) ) {
				return new \WP_Error( 'invalid_emergency_cleanup_plan', sprintf( 'Emergency cleanup artifact row #%d is missing artifacts.', (int) $index ), array( 'status' => 400 ) );
			}
		}

		return array_values( $rows );
	}

	/**
	 * Compare a reviewed emergency row to current worktree state.
	 *
	 * @param array<string,mixed> $planned          Reviewed row.
	 * @param array<string,mixed> $current          Current worktree row.
	 * @param bool                $require_branch   Whether branch identity must match exactly.
	 * @return bool
	 */
	private function emergency_row_identity_matches_current( array $planned, array $current, bool $require_branch ): bool {
		foreach ( array( 'handle', 'repo', 'path' ) as $field ) {
			if ( (string) ( $planned[ $field ] ?? '' ) !== (string) ( $current[ $field ] ?? '' ) ) {
				return false;
			}
		}

		return ! $require_branch || (string) ( $planned['branch'] ?? '' ) === (string) ( $current['branch'] ?? '' );
	}

	/**
	 * Build an emergency apply skip row.
	 *
	 * @param array<string,mixed>      $planned Planned row.
	 * @param array<string,mixed>|null $current Current row.
	 * @param string                   $code    Stable reason code.
	 * @param string                   $reason  Human-readable reason.
	 * @return array<string,mixed>
	 */
	private function build_emergency_apply_skip( array $planned, ?array $current, string $code, string $reason ): array {
		return array(
			'handle'      => (string) ( $planned['handle'] ?? '' ),
			'repo'        => (string) ( $current['repo'] ?? $planned['repo'] ?? '' ),
			'branch'      => (string) ( $current['branch'] ?? $planned['branch'] ?? '' ),
			'path'        => (string) ( $current['path'] ?? $planned['path'] ?? '' ),
			'reason_code' => $code,
			'reason'      => $reason,
			'planned'     => $planned,
			'current'     => $current,
		);
	}

	/**
	 * Build summary counts for emergency cleanup plans.
	 *
	 * @param array<int,array> $artifact_candidates Artifact rows planned.
	 * @param array<int,array> $worktree_candidates Worktree rows planned.
	 * @param array<int,array> $removed_artifacts   Artifact rows removed.
	 * @param array<int,array> $removed_worktrees   Worktree rows removed.
	 * @param array<int,array> $skipped             Skip rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_emergency_cleanup_summary( array $artifact_candidates, array $worktree_candidates, array $removed_artifacts, array $removed_worktrees, array $skipped ): array {
		$artifact_bytes    = 0;
		$removed_bytes     = 0;
		$artifact_count    = 0;
		$removed_count     = 0;
		$skipped_by_reason = array();

		foreach ( $artifact_candidates as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$artifact_bytes += max( 0, (int) ( is_array( $artifact ) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ) );
				++$artifact_count;
			}
		}

		foreach ( $removed_artifacts as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$removed_bytes += max( 0, (int) ( is_array( $artifact ) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ) );
				++$removed_count;
			}
		}

		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}
		ksort( $skipped_by_reason );

		return array(
			'would_remove_artifacts'  => $artifact_count,
			'would_remove_worktrees'  => count( $worktree_candidates ),
			'removed_artifacts'       => $removed_count,
			'removed_worktrees'       => count( $removed_worktrees ),
			'skipped'                 => count( $skipped ),
			'skipped_by_reason'       => $skipped_by_reason,
			'artifact_size_bytes'     => 0 === $removed_count ? $artifact_bytes : $removed_bytes,
			'removed_artifact_bytes'  => $removed_bytes,
			'worktree_size_bytes'     => array_sum( array_map( fn( $row ) => max( 0, (int) ( $row['size_bytes'] ?? 0 ) ), $worktree_candidates ) ),
			'top_artifacts_by_size'   => $this->summarize_top_worktree_rows( $artifact_candidates, 'artifact_size_bytes' ),
			'top_worktrees_by_age'    => $this->summarize_top_worktree_rows( $worktree_candidates, 'age_days' ),
		);
	}

	/**
	 * Build a bounded cleanup review from top-level inventory only.
	 *
	 * This mode is deliberately dry-run-only. It avoids git worktree discovery,
	 * per-worktree status, unpushed-commit checks, fetches, and GitHub lookups.
	 * Only explicit lifecycle cleanup signals become candidates; every ambiguous
	 * worktree is skipped with stable reason codes for review.
	 *
	 * @param string $older_than Optional age filter duration.
	 * @param string $sort       Optional candidate sort.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function worktree_cleanup_inventory_only( string $older_than, string $sort, bool $include_repaired_metadata = false ): array|\WP_Error {
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

		$candidates = array();
		$skipped    = array();

		foreach ( $this->build_workspace_inventory_rows() as $wt ) {
			if ( empty( $wt['is_worktree'] ) ) {
				continue;
			}

			$handle       = (string) ( $wt['handle'] ?? '?' );
			$repo         = (string) ( $wt['repo'] ?? '' );
			$branch       = (string) ( $wt['branch_slug'] ?? '' );
			$path         = (string) ( $wt['path'] ?? '' );
			$metadata     = $wt['metadata'] ?? null;
			$created_at   = $wt['created_at'] ?? null;
			$base_row     = array(
				'handle'     => $handle,
				'repo'       => $repo,
				'branch'     => $branch,
				'path'       => $path,
				'created_at' => $created_at,
				'metadata'   => $metadata,
			);

			if ( ! is_array( $metadata ) ) {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => 'needs_metadata_reconcile',
					'reason'      => 'inventory row has no lifecycle metadata; metadata reconciliation is required before cleanup planning can classify it',
					'hint'        => 'Run workspace worktree reconcile-metadata --dry-run --format=json to generate reviewed metadata reconciliation rows.',
				) );
				continue;
			}

			if ( ! WorktreeContextInjector::has_cleanup_signal( $metadata ) ) {
				$repaired = ! empty( $metadata['metadata_repaired'] );
				if ( $include_repaired_metadata && $repaired ) {
					$age_decision = null;
					if ( null !== $age_filter ) {
						$created_ts = is_string( $created_at ) && '' !== $created_at ? strtotime( $created_at ) : false;
						if ( false === $created_ts ) {
							++$age_filter['unknown_age'];
							$skipped[] = array_merge( $base_row, array(
								'reason_code' => 'unknown_age',
								'reason'      => 'missing or invalid created_at metadata - age filter cannot decide safely',
								'age_filter'  => array(
									'type'       => 'older_than',
									'older_than' => $age_filter['older_than'],
									'threshold'  => $age_filter['threshold'],
									'decision'   => 'unknown_age',
								),
							) );
							continue;
						}

						$age_decision = array(
							'type'        => 'older_than',
							'older_than'  => $age_filter['older_than'],
							'threshold'   => $age_filter['threshold'],
							'created_at'  => $created_at,
							'age_seconds' => time() - $created_ts,
						);
						if ( $created_ts > $age_filter['threshold_unix'] ) {
							++$age_filter['excluded'];
							$skipped[] = array_merge( $base_row, array(
								'reason_code' => 'age_filter',
								'reason'      => sprintf( 'created_at %s is newer than --older-than=%s threshold %s', $created_at, $age_filter['older_than'], $age_filter['threshold'] ),
								'age_filter'  => array_merge( $age_decision, array( 'decision' => 'excluded' ) ),
							) );
							continue;
						}
						$age_decision['decision'] = 'included';
					}

					$candidate = array_merge( $base_row, array(
						'dirty'         => 0,
						'signal'        => 'repaired_metadata',
						'reason_code'   => 'repaired_metadata',
						'reason'        => 'operator-approved cleanup of repaired metadata',
						'repair_status' => 'repaired_metadata',
					) );
					if ( null !== $age_decision ) {
						$candidate['age_filter'] = $age_decision;
					}
					$candidates[] = $candidate;
					continue;
				}
				$skipped[] = $this->build_inventory_cleanup_no_signal_skip( $base_row, $wt, $metadata );
				continue;
			}

			if ( null !== $age_filter ) {
				$created_ts = is_string( $created_at ) && '' !== $created_at ? strtotime( $created_at ) : false;
				if ( false === $created_ts ) {
					++$age_filter['unknown_age'];
					$skipped[] = array_merge( $base_row, array(
						'reason_code' => 'unknown_age',
						'reason'      => 'missing or invalid created_at metadata - age filter cannot decide safely',
						'age_filter'  => array(
							'type'       => 'older_than',
							'older_than' => $age_filter['older_than'],
							'threshold'  => $age_filter['threshold'],
							'decision'   => 'unknown_age',
						),
					) );
					continue;
				}

				if ( $created_ts > $age_filter['threshold_unix'] ) {
					++$age_filter['excluded'];
					$skipped[] = array_merge( $base_row, array(
						'reason_code' => 'age_filter',
						'reason'      => sprintf( 'created_at %s is newer than --older-than=%s threshold %s', $created_at, $age_filter['older_than'], $age_filter['threshold'] ),
						'age_filter'  => array(
							'type'        => 'older_than',
							'older_than'  => $age_filter['older_than'],
							'threshold'   => $age_filter['threshold'],
							'created_at'  => $created_at,
							'age_seconds' => time() - $created_ts,
							'decision'    => 'excluded',
						),
					) );
					continue;
				}
			}

			$candidate = array_merge( $base_row, array(
				'dirty'       => 0,
				'signal'      => 'cleanup_eligible',
				'reason_code' => 'cleanup_eligible',
				'reason'      => 'worktree finalized or explicitly marked cleanup_eligible',
				'pr_url'      => $metadata['pr_url'] ?? null,
			) );
			if ( null !== $age_filter && is_string( $created_at ) && '' !== $created_at ) {
				$candidate['age_filter'] = array(
					'type'        => 'older_than',
					'older_than'  => $age_filter['older_than'],
					'threshold'   => $age_filter['threshold'],
					'created_at'  => $created_at,
					'age_seconds' => time() - (int) strtotime( $created_at ),
					'decision'    => 'included',
				);
			}

			$candidates[] = $candidate;
		}

		$candidates = $this->sort_worktree_cleanup_rows( $candidates, $sort );
		return array(
			'success'        => true,
			'dry_run'        => true,
			'inventory_only' => true,
			'candidates'     => $candidates,
			'removed'        => array(),
			'skipped'        => $skipped,
			'summary'        => $this->build_worktree_cleanup_summary( $candidates, array(), $skipped, $age_filter ),
		);
	}

	/**
	 * Build an inventory-only skip row when lifecycle metadata has no cleanup signal.
	 *
	 * @param array<string,mixed> $base_row Base worktree row.
	 * @param array<string,mixed> $wt       Inventory row.
	 * @param array<string,mixed> $metadata Lifecycle metadata.
	 * @return array<string,mixed>
	 */
	private function build_inventory_cleanup_no_signal_skip( array $base_row, array $wt, array $metadata ): array {
		$liveness        = (string) ( $wt['liveness'] ?? WorktreeContextInjector::LIVENESS_UNKNOWN );
		$liveness_reason = (string) ( $wt['liveness_reason'] ?? '' );
		$state           = isset( $metadata['lifecycle_state'] ) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state'] ) : null;
		$has_pr_context  = ! empty( $metadata['pr_url'] ) || ! empty( $metadata['pr_number'] ) || ! empty( $metadata['pr_ref'] );
		$has_task_context = is_array( $metadata['origin_task'] ?? null ) && ! empty( $metadata['origin_task']['task_url'] );

		if ( WorktreeContextInjector::LIVENESS_LIVE !== $liveness && ( $has_pr_context || $has_task_context ) ) {
			return array_merge( $base_row, array(
				'reason_code'     => 'lifecycle_reconciliation_candidate',
				'reason'          => 'stale or PR/task-backed lifecycle metadata has no cleanup signal; reconcile lifecycle state before cleanup eligibility is decided',
				'hint'            => 'Run workspace worktree cleanup --dry-run --format=json for merge/PR signal detection, then persist lifecycle state through DMC-owned finalization.',
				'lifecycle_state' => $state,
				'liveness'        => $liveness,
				'liveness_reason' => $liveness_reason,
				'pr_url'          => $metadata['pr_url'] ?? null,
				'pr_number'       => $metadata['pr_number'] ?? null,
				'origin_task'     => $metadata['origin_task'] ?? null,
			) );
		}

		return array_merge( $base_row, array(
			'reason_code'     => 'active_no_signal',
			'reason'          => 'active lifecycle metadata has no cleanup signal; leaving in place',
			'hint'            => 'No cleanup action is safe from inventory alone. Keep active, or run full cleanup when you need merge-signal detection.',
			'lifecycle_state' => $state,
			'liveness'        => $liveness,
			'liveness_reason' => $liveness_reason,
		) );
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
		if ( $dirty_count > 0 && ! $force ) {
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

		// Unpushed gate: hard stop even with force=true (data loss > dirty loss).
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
		if ( $unpushed > 0 ) {
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

		// Primary must still exist or remove_worktree_by_path will fail noisily.
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
	 * Build current artifact cleanup candidates and safety skips.
	 *
	 * Two modes are supported:
	 * - **Bounded inventory mode** (default): scan the cheap top-level workspace
	 *   inventory, detect profile-derived artifact directories with `is_dir` /
	 *   per-artifact `du` only. Per-worktree git probes (`git status`,
	 *   `count_unpushed_commits`) are skipped unless `safety_probes` is set.
	 *   This keeps a dry-run on ~hundreds of worktrees responsive enough for a
	 *   synchronous CLI / ability call.
	 * - **Exhaustive mode** (`exhaustive=true` or `safety_probes=true`): use
	 *   `worktree_list()` and run the full per-worktree dirty + unpushed probes.
	 *   Slower but the historical, fully-validated behavior.
	 *
	 * Pagination via `limit` + `offset` always operates on the inventory ordering
	 * after `only_handles` filtering. The returned plan includes a `pagination`
	 * envelope describing total worktrees considered, the scanned slice, and
	 * `next_offset` continuation when the scan is partial.
	 *
	 * @param bool  $force Whether to allow dirty/unpushed worktrees.
	 * @param array $opts  Options: `limit` (0 = unbounded), `offset`,
	 *                     `only_handles` (array<string>|null), `safety_probes`.
	 * @return array{candidates: array<int,array>, skipped: array<int,array>, pagination: ?array<string,mixed>}|\WP_Error
	 */
	private function build_worktree_artifact_cleanup_plan( bool $force, array $opts = array() ): array|\WP_Error {
		$limit         = isset( $opts['limit'] ) ? (int) $opts['limit'] : 0;
		$offset        = isset( $opts['offset'] ) ? max( 0, (int) $opts['offset'] ) : 0;
		$only_handles  = isset( $opts['only_handles'] ) && is_array( $opts['only_handles'] )
			? array_values( array_filter( array_map( 'strval', $opts['only_handles'] ), fn( $h ) => '' !== $h ) )
			: null;
		$safety_probes = ! empty( $opts['safety_probes'] );

		$only_index = null;
		if ( null !== $only_handles ) {
			$only_index = array();
			foreach ( $only_handles as $handle ) {
				$only_index[ $handle ] = true;
			}
		}

		// Exhaustive unbounded dry-runs still use the full git-backed listing.
		// Bounded discovery/apply chunks start from cheap inventory and run probes
		// only for the current page so task fanout is not blocked by full planning.
		$uses_git_listing = $safety_probes && null === $only_handles && $limit <= 0;
		if ( $uses_git_listing ) {
			$listing = $this->worktree_list();
			if ( $listing instanceof \WP_Error ) {
				return $listing;
			}
			$rows = (array) ( $listing['worktrees'] ?? array() );
			$rows = array_values( array_filter( $rows, fn( $wt ) => empty( $wt['is_primary'] ) ) );
		} else {
			$rows = array_values( array_filter(
				$this->build_workspace_inventory_rows(),
				fn( $wt ) => empty( $wt['is_primary'] ) && ! empty( $wt['is_worktree'] )
			) );
		}

		// Stable ordering so `offset` is deterministic across calls and matches
		// what the operator saw in the previous page.
		usort( $rows, fn( $a, $b ) => strcmp( (string) ( $a['handle'] ?? '' ), (string) ( $b['handle'] ?? '' ) ) );

		if ( null !== $only_index ) {
			$rows = array_values( array_filter( $rows, fn( $wt ) => isset( $only_index[ (string) ( $wt['handle'] ?? '' ) ] ) ) );
		}

		$total       = count( $rows );
		$bounded     = $limit > 0;
		$slice_start = $bounded ? min( $offset, $total ) : 0;
		$slice_end   = $bounded ? min( $slice_start + $limit, $total ) : $total;
		$slice       = $bounded ? array_slice( $rows, $slice_start, $slice_end - $slice_start ) : $rows;

		$candidates = array();
		$skipped    = array();

		foreach ( $slice as $wt ) {
			$handle  = (string) ( $wt['handle'] ?? '?' );
			$repo    = (string) ( $wt['repo'] ?? '' );
			$wt_path = (string) ( $wt['path'] ?? '' );
			if ( $safety_probes ) {
				$branch = (string) ( $wt['branch'] ?? '' );
				if ( '' === $branch ) {
					$resolved = '' !== $wt_path ? $this->resolve_worktree_branch_from_head_file( $wt_path ) : null;
					$branch   = (string) ( $resolved ?? $wt['branch_slug'] ?? '' );
				}
			} else {
				// Inventory rows only carry `branch_slug` (the directory slug,
				// e.g. `fix-foo`). The plan apply path revalidates against the
				// real git branch from `worktree_list()` (e.g. `fix/foo`), so
				// resolve it cheaply here from the per-worktree `.git/HEAD`
				// pointer file. This is two file reads vs a `git` invocation.
				$resolved = '' !== $wt_path ? $this->resolve_worktree_branch_from_head_file( $wt_path ) : null;
				$branch   = (string) ( $resolved ?? $wt['branch'] ?? $wt['branch_slug'] ?? '' );
			}

			// Inventory rows don't include detected artifacts; detect them on
			// the fly so the bounded path stays focused on artifact-bearing
			// worktrees only.
			if ( $safety_probes && isset( $wt['artifacts'] ) ) {
				$artifacts = array_values( array_filter( (array) ( $wt['artifacts'] ?? array() ), fn( $artifact ) => is_array( $artifact ) ) );
			} else {
				$artifacts = '' !== $wt_path ? $this->detect_worktree_artifacts( $repo, $wt_path ) : array();
			}

			$base_row = array(
				'handle'     => $handle,
				'repo'       => $repo,
				'branch'     => $branch,
				'path'       => $wt_path,
				'created_at' => $wt['created_at'] ?? null,
			);

			if ( empty( $artifacts ) ) {
				continue;
			}

			if ( ! empty( $wt['external'] ) ) {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => 'external_worktree',
					'reason'      => 'external worktree (outside workspace) - artifact cleanup only operates inside the DMC workspace',
					'artifacts'   => $artifacts,
				) );
				continue;
			}

			if ( '' === $repo || '' === $branch || '' === $wt_path ) {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => 'missing_metadata',
					'reason'      => 'missing repo/branch/path',
					'artifacts'   => $artifacts,
				) );
				continue;
			}

			if ( $this->is_active_studio_symlink_target( $wt_path ) ) {
				$skipped[] = array_merge( $base_row, array(
					'reason_code' => 'active_symlink_target',
					'reason'      => 'worktree is the target of a wp-content plugin/theme symlink - leaving artifacts in place',
					'artifacts'   => $artifacts,
				) );
				continue;
			}

			if ( $safety_probes ) {
				if ( null === $only_handles && null !== ( $wt['dirty'] ?? null ) ) {
					$dirty_count = (int) ( $wt['dirty'] ?? 0 );
				} else {
					$dirty_probe = $this->probe_worktree_dirty_count( $wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
					if ( is_wp_error( $dirty_probe ) ) {
						$skipped[] = array_merge( $base_row, array(
							'reason_code' => 'probe_timeout',
							'reason'      => 'artifact cleanup dirty-state probe failed - leaving artifacts in place: ' . $dirty_probe->get_error_message(),
							'artifacts'   => $artifacts,
						) );
						continue;
					}
					$dirty_count = (int) $dirty_probe;
				}
				if ( $dirty_count > 0 && ! $force ) {
					$skipped[] = array_merge( $base_row, array(
						'reason_code' => 'dirty_worktree',
						'reason'      => sprintf( 'working tree dirty (%d files) - pass force=true to override artifact cleanup only', $dirty_count ),
						'dirty'       => $dirty_count,
						'artifacts'   => $artifacts,
					) );
					continue;
				}

				$unpushed = $this->count_unpushed_commits( $wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT );
				if ( is_wp_error( $unpushed ) ) {
					$skipped[] = array_merge( $base_row, array(
						'reason_code' => 'probe_timeout',
						'reason'      => 'artifact cleanup safety probe timed out - leaving artifacts in place: ' . $unpushed->get_error_message(),
						'artifacts'   => $artifacts,
					) );
					continue;
				}
				if ( $unpushed > 0 && ! $force ) {
					$skipped[] = array_merge( $base_row, array(
						'reason_code' => 'unpushed_commits',
						'reason'      => sprintf( '%d unpushed commit(s) - pass force=true to override artifact cleanup only', $unpushed ),
						'unpushed'    => $unpushed,
						'artifacts'   => $artifacts,
					) );
					continue;
				}
			}

			$candidate = array_merge( $base_row, array(
				'artifacts'           => $artifacts,
				'artifact_count'      => count( $artifacts ),
				'artifact_size_bytes' => array_sum( array_map( fn( $artifact ) => (int) ( $artifact['size_bytes'] ?? 0 ), $artifacts ) ),
				'reason_code'         => 'profile_artifacts',
				'reason'              => 'profile-derived reconstructable artifacts can be removed',
			) );
			if ( ! $safety_probes ) {
				// Surface that bounded dry-run did not run per-worktree git
				// safety probes. Apply paths revalidate with safety_probes=true
				// before deletion, so the candidate is reviewable but not
				// destructible from a bounded plan alone.
				$candidate['safety_probes_deferred'] = true;
			}
			$candidates[] = $candidate;
		}

		$pagination = array(
			'mode'          => $safety_probes ? ( $uses_git_listing ? 'exhaustive' : 'bounded_inventory_safety' ) : 'bounded_inventory',
			'limit'         => $bounded ? $limit : 0,
			'offset'        => $slice_start,
			'scanned'       => count( $slice ),
			'total'         => $total,
			'complete'      => ! $bounded || $slice_end >= $total,
			'partial'       => $bounded && $slice_end < $total,
			'next_offset'   => ( $bounded && $slice_end < $total ) ? $slice_end : null,
			'safety_probes' => $safety_probes,
		);

		return array(
			'candidates' => $candidates,
			'skipped'    => $skipped,
			'pagination' => $pagination,
		);
	}

	/**
	 * Build stable artifact cleanup counts.
	 *
	 * @param array<int,array> $candidates Candidate rows.
	 * @param array<int,array> $removed    Removed rows.
	 * @param array<int,array> $skipped    Skipped rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_artifact_cleanup_summary( array $candidates, array $removed, array $skipped ): array {
		$skipped_by_reason = array();
		$artifact_by_repo  = array();
		$would_bytes       = 0;
		$removed_bytes     = 0;
		$would_count       = 0;
		$removed_count     = 0;

		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}

		foreach ( $candidates as $row ) {
			$repo = (string) ( $row['repo'] ?? 'unknown' );
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$bytes = (int) ( is_array( $artifact ) ? ( $artifact['size_bytes'] ?? 0 ) : 0 );
				$would_bytes += max( 0, $bytes );
				++$would_count;
				$artifact_by_repo[ $repo ] = ( $artifact_by_repo[ $repo ] ?? 0 ) + max( 0, $bytes );
			}
		}

		foreach ( $removed as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$removed_bytes += max( 0, (int) ( is_array( $artifact ) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ) );
				++$removed_count;
			}
		}

		ksort( $skipped_by_reason );
		arsort( $artifact_by_repo );

		return array(
			'would_remove_worktrees' => count( $candidates ),
			'would_remove_artifacts' => $would_count,
			'removed_worktrees'      => count( $removed ),
			'removed_artifacts'      => $removed_count,
			'skipped'                => count( $skipped ),
			'skipped_by_reason'      => $skipped_by_reason,
			'artifact_count'         => 0 === $removed_count ? $would_count : $removed_count,
			'artifact_size_bytes'    => 0 === $removed_count ? $would_bytes : $removed_bytes,
			'removed_size_bytes'     => $removed_bytes,
			'artifact_size_by_repo'  => $artifact_by_repo,
		);
	}

	/**
	 * Extract artifact cleanup candidates from a dry-run JSON report.
	 *
	 * @param array $plan Decoded artifact cleanup report.
	 * @return array<int,array>|\WP_Error
	 */
	private function extract_worktree_artifact_cleanup_plan_candidates( array $plan ): array|\WP_Error {
		$candidates = $plan['candidates'] ?? null;
		if ( ! is_array( $candidates ) ) {
			return new \WP_Error( 'invalid_artifact_cleanup_plan', 'Artifact cleanup plan must contain a candidates array.', array( 'status' => 400 ) );
		}

		foreach ( $candidates as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'invalid_artifact_cleanup_plan', sprintf( 'Artifact cleanup candidate #%d is not an object.', (int) $index ), array( 'status' => 400 ) );
			}

			foreach ( array( 'handle', 'repo', 'branch', 'path', 'artifacts' ) as $field ) {
				$value = $row[ $field ] ?? null;
				if ( 'artifacts' === $field ? ! is_array( $value ) || array() === $value : '' === trim( (string) $value ) ) {
					return new \WP_Error( 'invalid_artifact_cleanup_plan', sprintf( 'Artifact cleanup candidate #%d is missing %s.', (int) $index, $field ), array( 'status' => 400 ) );
				}
			}

			foreach ( $row['artifacts'] as $artifact_index => $artifact ) {
				if ( ! is_array( $artifact ) || '' === trim( (string) ( $artifact['path'] ?? '' ) ) ) {
					return new \WP_Error( 'invalid_artifact_cleanup_plan', sprintf( 'Artifact cleanup candidate #%d artifact #%d is missing path.', (int) $index, (int) $artifact_index ), array( 'status' => 400 ) );
				}
			}
		}

		return array_values( $candidates );
	}

	/**
	 * Restrict current artifact cleanup candidates to a reviewed plan.
	 *
	 * @param array<int,array> $planned_candidates Planned rows.
	 * @param array<int,array> $current_candidates Fresh candidates.
	 * @param array<int,array> $current_skipped    Fresh skips.
	 * @return array{candidates: array<int,array>, skipped: array<int,array>}
	 */
	private function scope_worktree_artifact_cleanup_to_plan( array $planned_candidates, array $current_candidates, array $current_skipped ): array {
		$current_by_handle = array();
		foreach ( $current_candidates as $row ) {
			$current_by_handle[ (string) ( $row['handle'] ?? '' ) ] = $row;
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
				$path      = (string) ( $plan_row['path'] ?? '' );
				$artifacts = (array) ( $plan_row['artifacts'] ?? array() );
				$complete  = '' !== $path;
				foreach ( $artifacts as $artifact ) {
					$relative = is_array( $artifact ) ? trim( (string) ( $artifact['path'] ?? '' ), '/' ) : '';
					if ( '' !== $relative && is_dir( rtrim( $path, '/' ) . '/' . $relative ) ) {
						$complete = false;
						break;
					}
				}

				$skip                   = $complete ? array(
					'handle'      => $handle,
					'repo'        => (string) ( $plan_row['repo'] ?? '' ),
					'branch'      => (string) ( $plan_row['branch'] ?? '' ),
					'path'        => $path,
					'reason_code' => 'artifact_already_removed',
					'reason'      => 'planned artifact path is already absent; treating retry as complete',
				) : ( $skipped_by_handle[ $handle ] ?? array(
					'handle'      => $handle,
					'repo'        => (string) ( $plan_row['repo'] ?? '' ),
					'branch'      => (string) ( $plan_row['branch'] ?? '' ),
					'path'        => $path,
					'reason_code' => 'artifact_plan_not_current',
					'reason'      => 'planned artifact cleanup row is no longer a current safe candidate',
				) );
				$skip['planned_artifacts'] = $plan_row['artifacts'] ?? array();
				$scoped_skipped[]          = $skip;
				continue;
			}

			$mismatches = array();
			foreach ( array( 'repo', 'branch', 'path' ) as $field ) {
				if ( (string) ( $plan_row[ $field ] ?? '' ) !== (string) ( $current[ $field ] ?? '' ) ) {
					$mismatches[] = $field;
				}
			}

			$current_artifacts = array();
			foreach ( (array) ( $current['artifacts'] ?? array() ) as $artifact ) {
				if ( is_array( $artifact ) ) {
					$current_artifacts[ (string) ( $artifact['path'] ?? '' ) ] = $artifact;
				}
			}

			$artifacts = array();
			foreach ( (array) ( $plan_row['artifacts'] ?? array() ) as $planned_artifact ) {
				$relative = (string) ( is_array( $planned_artifact ) ? ( $planned_artifact['path'] ?? '' ) : '' );
				if ( '' === $relative || ! isset( $current_artifacts[ $relative ] ) ) {
					$mismatches[] = 'artifact:' . $relative;
					continue;
				}
				$artifacts[] = $current_artifacts[ $relative ];
			}

			if ( array() !== $mismatches ) {
				$scoped_skipped[] = array(
					'handle'            => $handle,
					'repo'              => (string) ( $current['repo'] ?? $plan_row['repo'] ?? '' ),
					'branch'            => (string) ( $current['branch'] ?? $plan_row['branch'] ?? '' ),
					'path'              => (string) ( $current['path'] ?? $plan_row['path'] ?? '' ),
					'reason_code'       => 'artifact_plan_mismatch',
					'reason'            => 'planned artifact cleanup row no longer matches current state: ' . implode( ', ', $mismatches ),
					'planned_artifacts' => $plan_row['artifacts'] ?? array(),
					'artifacts'         => $current['artifacts'] ?? array(),
				);
				continue;
			}

			$scoped_candidates[] = array_merge( $current, array( 'artifacts' => $artifacts ) );
		}

		return array(
			'candidates' => $scoped_candidates,
			'skipped'    => $scoped_skipped,
		);
	}

	/**
	 * Remove one artifact directory after exact profile/path revalidation.
	 *
	 * @param string $worktree_path Worktree root path.
	 * @param string $relative      Profile-relative artifact path.
	 * @return true|\WP_Error
	 */
	private function remove_worktree_artifact_path( string $worktree_path, string $relative ): true|\WP_Error {
		$relative = trim( $relative, '/' );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return new \WP_Error( 'invalid_artifact_path', sprintf( 'Invalid artifact path: %s', $relative ), array( 'status' => 400 ) );
		}

		if ( '' === $worktree_path || ! is_dir( $worktree_path ) ) {
			return new \WP_Error( 'worktree_path_missing', sprintf( 'Worktree path does not exist: %s', $worktree_path ), array( 'status' => 404 ) );
		}

		$worktree_validation = $this->validate_containment( $worktree_path, $this->workspace_path );
		if ( ! $worktree_validation['valid'] ) {
			return new \WP_Error( 'path_outside_workspace', sprintf( 'Refusing artifact cleanup outside workspace: %s', $worktree_validation['message'] ?? '' ), array( 'status' => 403 ) );
		}
		$worktree_real = (string) ( $worktree_validation['real_path'] ?? '' );
		if ( '' === $worktree_real ) {
			return new \WP_Error( 'path_resolution_failed', sprintf( 'Unable to resolve worktree path: %s', $worktree_path ), array( 'status' => 403 ) );
		}

		$artifact_path = rtrim( $worktree_real, '/' ) . '/' . $relative;
		if ( ! is_dir( $artifact_path ) ) {
			return new \WP_Error( 'artifact_path_missing', sprintf( 'Artifact path does not exist: %s', $relative ), array( 'status' => 404 ) );
		}

		$artifact_validation = $this->validate_containment( $artifact_path, $worktree_real );
		$artifact_real       = (string) ( $artifact_validation['real_path'] ?? '' );
		if ( ! $artifact_validation['valid'] || '' === $artifact_real || $artifact_real === $worktree_real ) {
			return new \WP_Error( 'artifact_path_outside_worktree', sprintf( 'Refusing artifact cleanup for %s: %s', $relative, $artifact_validation['message'] ?? '' ), array( 'status' => 403 ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'rm -rf %s 2>&1', escapeshellarg( $artifact_real ) ) );

		return true;
	}

	/**
	 * Check whether a worktree is currently targeted by a Studio plugin/theme symlink.
	 *
	 * @param string $worktree_path Worktree path.
	 * @return bool True when a wp-content plugin/theme symlink points at the path.
	 */
	private function is_active_studio_symlink_target( string $worktree_path ): bool {
		$worktree_real = realpath( $worktree_path );
		if ( false === $worktree_real || ! defined( 'ABSPATH' ) ) {
			return false;
		}

		foreach ( array( 'wp-content/plugins', 'wp-content/themes' ) as $relative_dir ) {
			$dir = rtrim( ABSPATH, '/' ) . '/' . $relative_dir;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$entries = scandir( $dir );
			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = $dir . '/' . $entry;
				if ( ! is_link( $path ) ) {
					continue;
				}

				$target_real = realpath( $path );
				if ( false !== $target_real && rtrim( $target_real, '/' ) === rtrim( $worktree_real, '/' ) ) {
					return true;
				}
			}
		}

		return false;
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
		arsort( $size_by_repo );
		arsort( $artifact_by_repo );

		$summary = array(
			'would_remove'          => count( $candidates ),
			'removed'               => count( $removed ),
			'skipped'               => count( $skipped ),
			'skipped_by_reason'     => $skipped_by_reason,
			'skipped_next_commands' => $this->worktree_cleanup_skipped_next_commands( $skipped_by_reason ),
			'cleanup_buckets'       => $this->worktree_cleanup_buckets( $candidates_by_signal, $skipped_by_reason ),
			'candidates_by_signal'  => $candidates_by_signal,
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
	 * @param array<string,int> $candidates_by_signal Candidate signal counts.
	 * @param array<string,int> $skipped_by_reason    Skipped reason counts.
	 * @return array<string,int>
	 */
	private function worktree_cleanup_buckets( array $candidates_by_signal, array $skipped_by_reason ): array {
		$buckets = array(
			'explicit_cleanup_candidates'         => (int) ( $candidates_by_signal['cleanup_eligible'] ?? 0 ),
			'lifecycle_reconciliation_candidates' => (int) ( $skipped_by_reason['lifecycle_reconciliation_candidate'] ?? 0 ),
			'metadata_reconciliation_candidates'  => (int) ( $skipped_by_reason['needs_metadata_reconcile'] ?? 0 ) + (int) ( $skipped_by_reason['requires_full_scan'] ?? 0 ) + (int) ( $skipped_by_reason['missing_metadata'] ?? 0 ),
			'dirty_unpushed'                      => (int) ( $skipped_by_reason['dirty_worktree'] ?? 0 ) + (int) ( $skipped_by_reason['unpushed_commits'] ?? 0 ),
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
				'signal' => 'pr-merged',
				'reason' => sprintf( 'PR #%d merged (%s)', $pr['number'], $pr['state'] ),
				'pr_url' => $pr['html_url'] ?? null,
			);
		}

		return array(
			'signal' => 'pr-closed',
			'reason' => sprintf( 'PR #%d closed without merge', $pr['number'] ),
			'pr_url' => $pr['html_url'] ?? null,
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

		if ( null === $lookup ) {
			return null;
		}

		return $lookup[ $branch ] ?? null;
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

	/**
	 * Resolve a sensible default base for new branches.
	 *
	 * Prefers `origin/HEAD` (typically `origin/main` or `origin/trunk`); falls
	 * back to plain `HEAD` if no remote default is configured.
	 *
	 * @param string $repo_path Primary repo path.
	 * @return string
	 */
	private function resolve_default_base( string $repo_path ): string {
		$result = $this->run_git( $repo_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD' );
		if ( ! is_wp_error( $result ) ) {
			$ref = trim( (string) ( $result['output'] ?? '' ) );
			if ( '' !== $ref ) {
				return $ref;
			}
		}
		return 'HEAD';
	}

	/**
	 * Does a ref look like a remote-tracking ref?
	 *
	 * `resolve_default_base()` returns fully-qualified paths
	 * (`refs/remotes/origin/main`), but callers may pass short forms like
	 * `origin/main`. Both are "already at-tip post-fetch" and staleness
	 * comparisons against them would be nonsensical.
	 *
	 * @param string $ref Ref name to classify.
	 * @return bool
	 */
	private function is_remote_tracking_ref( string $ref ): bool {
		return str_starts_with( $ref, 'refs/remotes/' ) || str_starts_with( $ref, 'origin/' );
	}

	/**
	 * Pull the single behind-count that matters for gate decisions.
	 *
	 * The staleness probe records up to two behind-counts depending on
	 * the path: `stale_commits_behind` for an existing branch vs its
	 * upstream, or `base_stale_commits_behind` for a new branch cut off a
	 * stale local base. At most one of these is present in practice;
	 * whichever exists is the one we gate on.
	 *
	 * @param array $response Accumulated response payload.
	 * @return int|null Behind-count, or null if no staleness data was collected.
	 */
	private function effective_behind_count( array $response ): ?int {
		if ( isset( $response['stale_commits_behind'] ) ) {
			return (int) $response['stale_commits_behind'];
		}
		if ( isset( $response['base_stale_commits_behind'] ) ) {
			return (int) $response['base_stale_commits_behind'];
		}
		return null;
	}

	/**
	 * Attempt to rebase the worktree onto its upstream.
	 *
	 * Target selection:
	 *   - Existing-local-branch path → rebase onto `@{upstream}` if one is
	 *     configured AND we observed stale_commits_behind > 0.
	 *   - New-branch-off-local-base path → rebase onto `<base_upstream>` if
	 *     we observed base_stale_commits_behind > 0.
	 *
	 * Returns an associative array to merge into the response:
	 *   rebase_attempted, rebase_target, rebase_succeeded [, rebase_error]
	 *
	 * On success, clears the relevant staleness field (behind-count zeroes
	 * out and the gate will not trip). On conflict the rebase is aborted
	 * so the worktree stays at its pre-rebase state, and the gate may
	 * still trip — `--rebase-base` is not a silent `--allow-stale`.
	 *
	 * Returns null when there's nothing meaningful to rebase (up to date,
	 * no upstream, or staleness couldn't be computed).
	 *
	 * @param string $wt_path        Worktree path.
	 * @param array  $response       Accumulated response payload.
	 * @param bool   $created_branch Whether this was a freshly-created branch.
	 * @return array|null
	 */
	private function try_rebase_worktree( string $wt_path, array &$response, bool $created_branch ): ?array {
		$target = null;
		$clear  = null;

		if ( ! $created_branch
			&& isset( $response['stale_commits_behind'] )
			&& (int) $response['stale_commits_behind'] > 0
		) {
			$target = '@{upstream}';
			$clear  = 'stale_commits_behind';
		} elseif ( $created_branch
			&& isset( $response['base_stale_commits_behind'] )
			&& (int) $response['base_stale_commits_behind'] > 0
			&& ! empty( $response['base_upstream'] )
		) {
			$target = (string) $response['base_upstream'];
			$clear  = 'base_stale_commits_behind';
		}

		if ( null === $target ) {
			return null;
		}

		$result = $this->run_git( $wt_path, sprintf( 'rebase %s', escapeshellarg( $target ) ) );

		if ( is_wp_error( $result ) ) {
			// Abort so the worktree stays at its pre-rebase HEAD. Agent can
			// retry manually after resolving conflicts.
			$this->run_git( $wt_path, 'rebase --abort' );

			$data  = $result->get_error_data();
			$tail  = is_array( $data ) && isset( $data['output'] ) ? trim( (string) $data['output'] ) : '';
			$error = '' !== $tail ? $tail : $result->get_error_message();

			return array(
				'rebase_attempted' => true,
				'rebase_target'    => $target,
				'rebase_succeeded' => false,
				'rebase_error'     => $error,
			);
		}

		// Success: zero out the behind-count so the gate sees a fresh worktree.
		unset( $response[ $clear ] );

		return array(
			'rebase_attempted' => true,
			'rebase_target'    => $target,
			'rebase_succeeded' => true,
		);
	}

	/**
	 * Parse a `git worktree list --porcelain` block.
	 *
	 * @param string $block Newline-separated key/value lines.
	 * @return array{path: string, head: string, branch: string|null}|null
	 */
	/**
	 * Resolve a worktree's current branch by reading its private `.git`
	 * pointer file and the linked `HEAD`. Cheap file I/O — no `git` process.
	 *
	 * Returns `null` for detached HEADs, missing pointer files, or any other
	 * shape we can't parse. Callers should fall back to the inventory's
	 * `branch_slug` so plan rows still carry an identifying value for review.
	 *
	 * @param string $wt_path Worktree directory.
	 * @return string|null Branch name (e.g. `fix/foo`), or null when unknown.
	 */
	private function resolve_worktree_branch_from_head_file( string $wt_path ): ?string {
		$git_pointer = rtrim( $wt_path, '/' ) . '/.git';
		if ( ! is_file( $git_pointer ) && ! is_dir( $git_pointer ) ) {
			return null;
		}

		$gitdir = null;
		if ( is_file( $git_pointer ) ) {
			$pointer = @file_get_contents( $git_pointer );
			if ( false === $pointer ) {
				return null;
			}
			if ( ! preg_match( '/^gitdir:\s*(.+)$/m', $pointer, $m ) ) {
				return null;
			}
			$gitdir = trim( $m[1] );
			// Pointer paths are typically absolute, but tolerate relative.
			if ( '' !== $gitdir && '/' !== $gitdir[0] ) {
				$gitdir = rtrim( $wt_path, '/' ) . '/' . $gitdir;
			}
		} else {
			$gitdir = $git_pointer;
		}

		if ( null === $gitdir || '' === $gitdir ) {
			return null;
		}

		$head_file = rtrim( $gitdir, '/' ) . '/HEAD';
		if ( ! is_file( $head_file ) ) {
			return null;
		}

		$head = @file_get_contents( $head_file );
		if ( false === $head ) {
			return null;
		}

		$head = trim( $head );
		if ( str_starts_with( $head, 'ref:' ) ) {
			$ref = trim( substr( $head, 4 ) );
			return preg_replace( '#^refs/heads/#', '', $ref );
		}

		// Detached HEAD or other unrecognized shape — surface as unknown.
		return null;
	}

	private function parse_worktree_block( string $block ): ?array {
		$lines = array_filter( array_map( 'trim', explode( "\n", $block ) ) );
		$out   = array(
			'path'   => '',
			'head'   => '',
			'branch' => null,
		);
		foreach ( $lines as $line ) {
			if ( str_starts_with( $line, 'worktree ' ) ) {
				$out['path'] = substr( $line, strlen( 'worktree ' ) );
			} elseif ( str_starts_with( $line, 'HEAD ' ) ) {
				$out['head'] = substr( $line, strlen( 'HEAD ' ) );
			} elseif ( str_starts_with( $line, 'branch ' ) ) {
				$ref           = substr( $line, strlen( 'branch ' ) );
				$out['branch'] = preg_replace( '#^refs/heads/#', '', $ref );
			} elseif ( 'detached' === $line ) {
				$out['branch'] = null;
			}
		}
		return ( '' === $out['path'] ) ? null : $out;
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
	 * Resolve and validate a workspace handle to a filesystem path.
	 *
	 * Accepts both primary handles (`<repo>`) and worktree handles
	 * (`<repo>@<branch-slug>`). For worktrees, the .git is a file
	 * pointing back at the primary's .git directory — we accept both
	 * directory and file forms.
	 *
	 * @param string $handle Workspace handle.
	 * @return string|\WP_Error Real path on success, WP_Error on failure.
	 */
	private function resolve_repo_path( string $handle ): string|\WP_Error {
		$parsed    = $this->parse_handle( $handle );
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Workspace handle "%s" not found.', $parsed['dir_name'] ), array( 'status' => 404 ) );
		}

		// .git can be a directory (primary) or a file (worktree).
		$git_path = $repo_path . '/.git';
		if ( ! is_dir( $git_path ) && ! is_file( $git_path ) ) {
			return new \WP_Error( 'not_git_repo', sprintf( 'Handle "%s" is not a git repository or worktree.', $parsed['dir_name'] ), array( 'status' => 400 ) );
		}

		$validation = $this->validate_containment( $repo_path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return new \WP_Error( 'path_traversal', $validation['message'], array( 'status' => 403 ) );
		}

		return $validation['real_path'];
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
	 * Block mutating ops on the primary checkout unless explicitly allowed.
	 *
	 * The primary is intentionally treated as the "deployed" checkout —
	 * agents should branch via worktrees, not switch the primary's HEAD.
	 * Worktree handles are always allowed.
	 *
	 * @param array{is_worktree: bool, repo: string, dir_name: string} $parsed
	 * @param bool                                                     $allow
	 * @return true|\WP_Error
	 */
	private function ensure_primary_mutation_allowed( array $parsed, bool $allow ): true|\WP_Error {
		if ( $parsed['is_worktree'] ) {
			return true;
		}
		if ( $allow ) {
			return true;
		}
		return new \WP_Error(
			'primary_mutation_blocked',
			sprintf(
				'Primary checkout "%s" is read-only by default. Pass allow_primary_mutation=true to operate on it, or use a worktree handle (e.g. %s@<branch-slug>).',
				$parsed['repo'],
				$parsed['repo']
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check if repo has git mutation permissions enabled.
	 *
	 * Unconfigured repos are permissive by default: `datamachine_workspace_git_policies`
	 * is an opt-in restriction layer. When no entry exists for $repo_name, both
	 * write and push are allowed. When an entry exists, its flags (`write_enabled`,
	 * `push_enabled`) are honored; missing flags default to `true` so a partial
	 * config doesn't accidentally lock out ops that weren't explicitly restricted.
	 *
	 * The primary-vs-worktree gate (see ensure_primary_mutation_allowed) remains
	 * the documented default safety mechanism for protecting tracked branches.
	 *
	 * To explicitly deny a repo, add an entry with `write_enabled: false` and/or
	 * `push_enabled: false`. The `datamachine_workspace_git_policies` filter also
	 * lets plugins inject policy at runtime.
	 *
	 * @param string $repo_name    Repository name.
	 * @param bool   $require_push Whether push must also be enabled.
	 * @return true|\WP_Error
	 */
	private function ensure_git_mutation_allowed( string $repo_name, bool $require_push = false ): true|\WP_Error {
		$policies = $this->get_workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? null;

		// No entry = permissive default. Callers should still respect
		// primary-vs-worktree separation via ensure_primary_mutation_allowed.
		if ( null === $repo ) {
			return true;
		}

		// Entry exists — honor explicit flags. Missing flags default to true
		// so a partial config (e.g. only setting allowed_paths) doesn't
		// accidentally disable write/push.
		$write_enabled = array_key_exists( 'write_enabled', $repo ) ? (bool) $repo['write_enabled'] : true;
		if ( ! $write_enabled ) {
			return new \WP_Error(
				'git_write_disabled',
				sprintf(
					'Git write operations are explicitly disabled for repo "%s" via datamachine_workspace_git_policies.',
					$repo_name
				),
				array( 'status' => 403 )
			);
		}

		if ( $require_push ) {
			$push_enabled = array_key_exists( 'push_enabled', $repo ) ? (bool) $repo['push_enabled'] : true;
			if ( ! $push_enabled ) {
				return new \WP_Error(
					'git_push_disabled',
					sprintf(
						'Git push is explicitly disabled for repo "%s" via datamachine_workspace_git_policies.',
						$repo_name
					),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Get allowed relative paths for staged mutations.
	 *
	 * @param string $repo_name Repository name.
	 * @return array
	 */
	private function get_repo_allowed_paths( string $repo_name ): array {
		$policies = $this->get_workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? array();

		$paths = $repo['allowed_paths'] ?? array();
		if ( ! is_array( $paths ) ) {
			return array();
		}

		$clean = array();
		foreach ( $paths as $path ) {
			$normalized = trim( (string) $path );
			if ( '' === $normalized ) {
				continue;
			}

			$normalized = ltrim( str_replace( '\\', '/', $normalized ), '/' );
			$normalized = rtrim( $normalized, '/' );
			$clean[]    = $normalized;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Get fixed branch restriction for a repo.
	 *
	 * @param string $repo_name Repository name.
	 * @return string|null
	 */
	private function get_repo_fixed_branch( string $repo_name ): ?string {
		$policies = $this->get_workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? array();
		$branch   = trim( (string) ( $repo['fixed_branch'] ?? '' ) );

		return '' === $branch ? null : $branch;
	}

	/**
	 * Check if a relative path is within the allowlist.
	 *
	 * @param string $path          Relative path.
	 * @param array  $allowed_paths Allowed roots.
	 * @return bool
	 */
	private function is_path_allowed( string $path, array $allowed_paths ): bool {
		$normalized = ltrim( str_replace( '\\', '/', $path ), '/' );

		foreach ( $allowed_paths as $allowed ) {
			$root = ltrim( str_replace( '\\', '/', (string) $allowed ), '/' );
			if ( '' === $root ) {
				continue;
			}

			if ( $normalized === $root || str_starts_with( $normalized, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a path appears sensitive.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private function is_sensitive_path( string $path ): bool {
		return PathSecurity::isSensitivePath( $path );
	}

	/**
	 * Basic traversal detection for relative paths.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private function has_traversal( string $path ): bool {
		return PathSecurity::hasTraversal( $path );
	}

	/**
	 * Read workspace git policy settings.
	 *
	 * The option defaults to an empty array, which is treated as "no
	 * restrictions" by the mutation gates (see ensure_git_mutation_allowed
	 * and git_add's allowed_paths check). To restrict a repo, configure an
	 * entry under `repos[<repo_name>]` with any combination of:
	 *
	 *   - write_enabled (bool, default true)
	 *   - push_enabled  (bool, default true)
	 *   - allowed_paths (string[], optional — if set, restricts git_add)
	 *   - fixed_branch  (string, optional — constrains push on primary)
	 *
	 * The `datamachine_workspace_git_policies` filter allows plugins and
	 * site configuration to inject or override policy at runtime without
	 * touching the stored option.
	 *
	 * @return array{repos: array<string, array<string, mixed>>}
	 */
	private function get_workspace_git_policies(): array {
		$defaults = array(
			'repos' => array(),
		);

		$settings = get_option( 'datamachine_workspace_git_policies', $defaults );
		if ( ! is_array( $settings ) ) {
			$settings = $defaults;
		}

		if ( ! isset( $settings['repos'] ) || ! is_array( $settings['repos'] ) ) {
			$settings['repos'] = array();
		}

		/**
		 * Filter the workspace git policy map.
		 *
		 * Allows plugins to inject or override per-repo git mutation policy
		 * without persisting changes to the `datamachine_workspace_git_policies`
		 * option. Useful for environment-specific policy (dev vs prod) or for
		 * tying policy to site configuration managed elsewhere.
		 *
		 * @since 0.x.0
		 *
		 * @param array $settings Current policy array, shape { repos: { <name>: {...} } }.
		 */
		$settings = apply_filters( 'datamachine_workspace_git_policies', $settings );

		if ( ! isset( $settings['repos'] ) || ! is_array( $settings['repos'] ) ) {
			return $defaults;
		}

		return $settings;
	}

	/**
	 * Get the origin remote URL for a git repo.
	 *
	 * @param string $repo_path Path to repo.
	 * @return string|null Remote URL or null.
	 */
	private function git_get_remote( string $repo_path ): ?string {
		$escaped = escapeshellarg( $repo_path );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$remote = exec( sprintf( 'git -C %s config --get remote.origin.url 2>/dev/null', $escaped ) );
		return ( '' !== $remote ) ? $remote : null;
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
	 * Get the current branch for a git repo.
	 *
	 * @param string $repo_path Path to repo.
	 * @return string|null Branch name or null.
	 */
	private function git_get_branch( string $repo_path ): ?string {
		$escaped = escapeshellarg( $repo_path );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = exec( sprintf( 'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped ) );
		return ( '' !== $branch ) ? $branch : null;
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
}
