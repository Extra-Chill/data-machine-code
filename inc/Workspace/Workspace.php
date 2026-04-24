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

defined( 'ABSPATH' ) || exit;

class Workspace {

	/**
	 * Maximum file size for reading (1 MB).
	 */
	const MAX_READ_SIZE = 1048576;

	/**
	 * @var string Resolved workspace path.
	 */
	private string $workspace_path;

	public function __construct() {
		$this->workspace_path = self::resolve_workspace_directory();
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
			return new \WP_Error( 'workspace_unavailable', 'Workspace unavailable: no writable path outside the web root. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php, ensure /var/lib/datamachine/ is writable, or ensure $HOME is set.', array( 'status' => 500 ) );
		}

		if ( is_dir( $path ) ) {
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
	 * @return array{success: bool, repos: array, path: string}
	 */
	public function list_repos(): array {
		$path = $this->workspace_path;

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

			$git_path  = $entry_path . '/.git';
			$is_git    = is_dir( $git_path ) || is_file( $git_path );
			$is_wt     = is_file( $git_path );
			$parsed    = $this->parse_handle( $entry );

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
	 * Remove a repository from the workspace.
	 *
	 * @param string $name Repository directory name.
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
				$linked = array_filter( $worktrees['worktrees'] ?? array(), fn( $wt ) => ! empty( $wt['is_worktree'] ) );
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
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
				exec( sprintf( 'git -C %s worktree prune 2>&1', escapeshellarg( $primary_path ) ) );
			}
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Removed "%s" from workspace.', $parsed['dir_name'] ),
		);
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * @param string $name Repository directory name.
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
	 * @param string $name Repository directory name.
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
	 * @param string $name        Repository directory name.
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
	 * @param string $name  Repository directory name.
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
	 * `.gitmodules` is present, a package-manager install if a lockfile is
	 * present (pnpm/bun/yarn/npm), and `composer install` if `composer.lock`
	 * is present. Steps are independent and each one is skipped gracefully
	 * when its tool is unavailable. A failing step is surfaced in the result
	 * but does not roll back the worktree — the checkout exists either way.
	 * Pass `$bootstrap = false` (or `--no-bootstrap` on the CLI) for a bare
	 * checkout when you only need to read code on that branch.
	 *
	 * @param string      $repo           Primary repo name (no @-suffix).
	 * @param string      $branch         Branch to check out (e.g. "fix/foo-bar").
	 * @param string|null $from           Base ref when creating the branch.
	 * @param bool        $inject_context Whether to inject site-agent context (default true).
	 * @param bool        $bootstrap      Whether to run submodule/package/composer install after creation (default true).
	 * @return array{success: bool, handle: string, path: string, branch: string, slug: string, created_branch: bool, message: string, context_injected?: bool, context_files?: string[], context_skip_reason?: string, bootstrap?: array, fetch_failed?: bool, fetch_error?: string, stale_commits_behind?: int, upstream?: string, base_stale_commits_behind?: int, base_upstream?: string}|\WP_Error
	 */
	public function worktree_add( string $repo, string $branch, ?string $from = null, bool $inject_context = true, bool $bootstrap = true ): array|\WP_Error {
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
			$base          = $from && '' !== trim( $from ) ? trim( $from ) : $this->resolve_default_base( $primary_path );
			$resolved_base = $base;
			$cmd           = sprintf( 'worktree add -b %s %s %s', escapeshellarg( $branch ), escapeshellarg( $wt_path ), escapeshellarg( $base ) );
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
					$response['context_injected'] = true;
					$response['context_files']    = $injection['written'];
					if ( ! empty( $injection['exclude_path'] ) ) {
						$response['context_exclude_path'] = $injection['exclude_path'];
					}
				}
			}
		}

		if ( $bootstrap ) {
			$response['bootstrap'] = WorktreeBootstrapper::bootstrap( $wt_path );
		}

		return $response;
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
	 * @param string|null $repo Optional repo filter (only this primary's worktrees).
	 * @return array{success: bool, worktrees: array}|\WP_Error
	 */
	public function worktree_list( ?string $repo = null ): array|\WP_Error {
		if ( ! is_dir( $this->workspace_path ) ) {
			return array(
				'success'   => true,
				'worktrees' => array(),
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

				$dirty_result = $this->run_git( $wt['path'], 'status --porcelain' );
				$dirty_files  = is_wp_error( $dirty_result )
					? 0
					: count( array_filter( array_map( 'trim', explode( "\n", $dirty_result['output'] ?? '' ) ) ) );

				$worktrees[] = array(
					'handle'      => $handle,
					'repo'        => $primary,
					'is_worktree' => ! $is_primary,
					'is_primary'  => $is_primary,
					'external'    => ! $is_primary && ! $inside_ws,
					'branch_slug' => $is_primary ? null : ( $parsed['branch_slug'] ?? null ),
					'branch'      => $wt['branch'],
					'head'        => $wt['head'],
					'path'        => $wt['path'],
					'dirty'       => $dirty_files,
				);
			}
		}

		return array(
			'success'   => true,
			'worktrees' => $worktrees,
		);
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

		$cmd    = sprintf( 'worktree remove %s%s', $force ? '--force ' : '', escapeshellarg( $wt_path ) );
		$result = $this->run_git( $primary_path, $cmd );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WorktreeContextInjector::forget_metadata( $wt_handle );

		return array(
			'success' => true,
			'handle'  => $wt_handle,
			'message' => sprintf( 'Worktree "%s" removed.', $wt_handle ),
		);
	}

	/**
	 * Prune stale worktree registry entries across all primaries.
	 *
	 * @return array{success: bool, pruned: array}
	 */
	public function worktree_prune(): array {
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
			$this->run_git( $primary_path, 'worktree prune -v' );
			$pruned[] = $entry;
		}

		return array(
			'success' => true,
			'pruned'  => $pruned,
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
		$dry_run     = ! empty( $opts['dry_run'] );
		$force       = ! empty( $opts['force'] );
		$skip_github = ! empty( $opts['skip_github'] );

		$listing = $this->worktree_list();
		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$protected_branches = array( 'main', 'master', 'trunk', 'develop', 'HEAD' );
		$candidates         = array();
		$skipped            = array();

		// Fetch + prune each primary once up-front so upstream-gone signals are fresh.
		$fetched = array();

		foreach ( $listing['worktrees'] ?? array() as $wt ) {
			if ( ! empty( $wt['is_primary'] ) ) {
				continue;
			}
			if ( ! empty( $wt['external'] ) ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => 'external worktree (outside workspace)',
				);
				continue;
			}

			$repo        = $wt['repo'] ?? '';
			$branch      = $wt['branch'] ?? '';
			$wt_path     = $wt['path'] ?? '';
			$dirty_count = (int) ( $wt['dirty'] ?? 0 );

			if ( '' === $repo || '' === $branch || '' === $wt_path ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => 'missing repo/branch/path',
				);
				continue;
			}

			if ( in_array( $branch, $protected_branches, true ) ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => sprintf( 'protected branch (%s)', $branch ),
				);
				continue;
			}

			if ( $dirty_count > 0 && ! $force ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => sprintf( 'working tree dirty (%d files) — pass force=true to override', $dirty_count ),
				);
				continue;
			}

			// Hard stop: worktrees with local commits the remote hasn't seen.
			// Upstream may be "gone" because a human manually deleted the
			// remote branch without merging — nuking the worktree would lose
			// those commits. `force` does NOT override this: data loss is a
			// harder problem than dirty-file loss, and this guard is cheap.
			$unpushed = $this->count_unpushed_commits( $wt_path );
			if ( $unpushed > 0 ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => sprintf( '%d unpushed commit(s) — refusing to delete even with force (push or reset explicitly)', $unpushed ),
				);
				continue;
			}

			$primary_path = $this->get_primary_path( $repo );
			if ( ! is_dir( $primary_path . '/.git' ) ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => 'primary checkout missing',
				);
				continue;
			}

			if ( empty( $fetched[ $repo ] ) ) {
				$this->run_git( $primary_path, 'fetch --prune --quiet origin' );
				$fetched[ $repo ] = true;
			}

			$signal = $this->detect_merge_signal( $primary_path, $repo, $branch, $skip_github );
			if ( null === $signal ) {
				$skipped[] = array(
					'handle' => $wt['handle'] ?? '?',
					'reason' => 'no merge signal — leaving in place',
				);
				continue;
			}

			$candidates[] = array(
				'handle'  => $wt['handle'],
				'repo'    => $repo,
				'branch'  => $branch,
				'path'    => $wt_path,
				'dirty'   => $dirty_count,
				'signal'  => $signal['signal'],
				'reason'  => $signal['reason'],
				'pr_url'  => $signal['pr_url'] ?? null,
			);
		}

		if ( $dry_run ) {
			return array(
				'success'    => true,
				'dry_run'    => true,
				'candidates' => $candidates,
				'removed'    => array(),
				'skipped'    => $skipped,
			);
		}

		$removed = array();

		foreach ( $candidates as $cand ) {
			$remove = $this->remove_worktree_by_path( $cand['repo'], $cand['branch'], $cand['path'], $force );
			if ( is_wp_error( $remove ) ) {
				$skipped[] = array(
					'handle' => $cand['handle'],
					'reason' => 'remove failed: ' . $remove->get_error_message(),
				);
				continue;
			}

			// Delete the now-detached local branch.
			$primary_path = $this->get_primary_path( $cand['repo'] );
			$this->run_git( $primary_path, sprintf( 'branch -D %s', escapeshellarg( $cand['branch'] ) ) );

			$removed[] = $cand;
		}

		// Final sweep to drop any remaining registry entries.
		$this->worktree_prune();

		return array(
			'success'    => true,
			'dry_run'    => false,
			'candidates' => $candidates,
			'removed'    => $removed,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Remove a worktree at an explicit path.
	 *
	 * Path-aware counterpart to `worktree_remove()`, which reconstructs the
	 * path from `<repo>@<slug>` convention. Cleanup code must use this so
	 * legacy worktrees (created before the @-slug convention landed, or via
	 * raw `git worktree add`) can still be removed.
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
	 * @return array{signal: string, reason: string, pr_url?: string}|null
	 */
	private function detect_merge_signal( string $primary_path, string $repo, string $branch, bool $skip_github ): ?array {
		$ref    = 'refs/heads/' . $branch;
		$format = '%(upstream:track)';
		$result = $this->run_git( $primary_path, sprintf( 'for-each-ref --format=%s %s', escapeshellarg( $format ), escapeshellarg( $ref ) ) );

		if ( ! is_wp_error( $result ) ) {
			$track = trim( (string) ( $result['output'] ?? '' ) );
			if ( str_contains( $track, 'gone' ) ) {
				return array(
					'signal' => 'upstream-gone',
					'reason' => 'remote branch deleted (likely merged + auto-deleted)',
				);
			}
		}

		if ( $skip_github ) {
			return null;
		}

		$gh_slug = $this->resolve_github_slug( $primary_path );
		if ( null === $gh_slug ) {
			return null;
		}

		$pr = $this->find_merged_pr_for_branch( $gh_slug, $branch );
		if ( null === $pr ) {
			return null;
		}

		return array(
			'signal' => 'pr-merged',
			'reason' => sprintf( 'PR #%d merged (%s)', $pr['number'], $pr['state'] ),
			'pr_url' => $pr['html_url'] ?? null,
		);
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
	 * Look up a merged PR for a branch via the GitHub API.
	 *
	 * Queries `GET /repos/{slug}/pulls?state=closed&head={owner}:{branch}`
	 * and returns the first entry whose `merged_at` is non-null.
	 *
	 * @param string $slug   owner/repo.
	 * @param string $branch Branch name.
	 * @return array|null PR data or null.
	 */
	private function find_merged_pr_for_branch( string $slug, string $branch ): ?array {
		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			return null;
		}

		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat();
		if ( empty( $pat ) ) {
			return null;
		}

		$owner = explode( '/', $slug )[0] ?? '';
		if ( '' === $owner ) {
			return null;
		}

		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'pulls' ),
			array(
				'state'    => 'closed',
				'head'     => $owner . ':' . $branch,
				'per_page' => 5,
			),
			$pat
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		foreach ( (array) ( $response['data'] ?? array() ) as $pr ) {
			if ( ! empty( $pr['merged_at'] ) ) {
				return array(
					'number'    => (int) ( $pr['number'] ?? 0 ),
					'state'     => (string) ( $pr['state'] ?? 'closed' ),
					'merged_at' => (string) $pr['merged_at'],
					'html_url'  => (string) ( $pr['html_url'] ?? '' ),
				);
			}
		}

		return null;
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
	 * Parse a `git worktree list --porcelain` block.
	 *
	 * @param string $block Newline-separated key/value lines.
	 * @return array{path: string, head: string, branch: string|null}|null
	 */
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
				$ref            = substr( $line, strlen( 'branch ' ) );
				$out['branch']  = preg_replace( '#^refs/heads/#', '', $ref );
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
	 * @param string $git_args  Git arguments (without leading "git").
	 * @return array
	 */
	private function run_git( string $repo_path, string $git_args ): array|\WP_Error {
		return GitRunner::run( $repo_path, $git_args );
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

		if ( ! is_array( $repo ) ) {
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

		if ( ! is_array( $settings ) || ! isset( $settings['repos'] ) || ! is_array( $settings['repos'] ) ) {
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
	 * @param string $wt_path Worktree path.
	 * @return int Number of unpushed commits (0 when safe or indeterminate).
	 */
	private function count_unpushed_commits( string $wt_path ): int {
		if ( '' === $wt_path || ! is_dir( $wt_path ) ) {
			return 0;
		}

		$escaped = escapeshellarg( $wt_path );

		// Prefer `@{push}` (respects push.default / push.remote mapping); fall
		// back to `@{upstream}` for the common case where they're the same.
		// Both expand to the tracked remote ref; if that ref is gone, this
		// returns non-zero exit and we can't compute unpushed — treat as 0
		// and let dirty / merge-signal checks handle it.
		$commands = array(
			'git -C %s rev-list --count @{push}..HEAD 2>/dev/null',
			'git -C %s rev-list --count @{upstream}..HEAD 2>/dev/null',
		);

		foreach ( $commands as $template ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			$output = exec( sprintf( $template, $escaped ), $_, $exit_code );
			if ( 0 === $exit_code && '' !== $output && ctype_digit( (string) $output ) ) {
				return (int) $output;
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
