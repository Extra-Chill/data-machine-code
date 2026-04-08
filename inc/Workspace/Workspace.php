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
	 * Get the full path to a repo within the workspace.
	 *
	 * @param string $name Repository name (directory name).
	 * @return string Full path.
	 */
	public function get_repo_path( string $name ): string {
		return $this->workspace_path . '/' . $this->sanitize_name( $name );
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

			$repo_info = array(
				'name' => $entry,
				'path' => $entry_path,
				'git'  => is_dir( $entry_path . '/.git' ),
			);

			// Get git remote if available.
			if ( $repo_info['git'] ) {
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
	public function remove_repo( string $name ): array|\WP_Error {
		$name      = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $name;

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $name ), array( 'status' => 404 ) );
		}

		// Safety: ensure path is within workspace.
		$validation = $this->validate_containment( $repo_path, $this->workspace_path );
		if ( ! $validation['valid'] ) {
			return new \WP_Error( 'path_traversal', $validation['message'], array( 'status' => 403 ) );
		}

		// Remove recursively.
		$escaped = escapeshellarg( $validation['real_path'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'rm -rf %s 2>&1', $escaped ), $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return new \WP_Error( 'remove_failed', sprintf( 'Failed to remove (exit %d): %s', $exit_code, implode( "\n", $output ) ), array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Removed "%s" from workspace.', $name ),
		);
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * @param string $name Repository directory name.
	 * @return array{success: bool, name?: string, path?: string, branch?: string, remote?: string, commit?: string, dirty?: int}|\WP_Error
	 */
	public function show_repo( string $name ): array|\WP_Error {
		$name      = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $name;

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $name ), array( 'status' => 404 ) );
		}

		$escaped = escapeshellarg( $repo_path );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = trim( (string) exec( sprintf( 'git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped ) ) );
		$remote = trim( (string) exec( sprintf( 'git -C %s config --get remote.origin.url 2>/dev/null', $escaped ) ) );
		$commit = trim( (string) exec( sprintf( 'git -C %s log -1 --format="%%h %%s" 2>/dev/null', $escaped ) ) );
		$status = trim( (string) exec( sprintf( 'git -C %s status --porcelain 2>/dev/null | wc -l', $escaped ) ) );
		// phpcs:enable

		return array(
			'success' => true,
			'name'    => $name,
			'path'    => $repo_path,
			'branch'  => $branch ? $branch : null,
			'remote'  => $remote ? $remote : null,
			'commit'  => $commit ? $commit : null,
			'dirty'   => (int) $status,
		);
	}

	/**
	 * Get git status details for a workspace repository.
	 *
	 * @param string $name Repository directory name.
	 * @return array
	 */
	public function git_status( string $name ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path( $name );
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
			'success' => true,
			'name'    => $this->sanitize_name( $name ),
			'path'    => $repo_path,
			'branch'  => ! is_wp_error( $branch_result ) ? trim( (string) $branch_result['output'] ) : null,
			'remote'  => ! is_wp_error( $remote_result ) ? trim( (string) $remote_result['output'] ) : null,
			'commit'  => ! is_wp_error( $latest_result ) ? trim( (string) $latest_result['output'] ) : null,
			'dirty'   => count( $files ),
			'files'   => array_values( $files ),
		);
	}

	/**
	 * Pull latest changes for a workspace repository.
	 *
	 * @param string $name        Repository directory name.
	 * @param bool   $allow_dirty Allow pull with dirty working tree.
	 * @return array
	 */
	public function git_pull( string $name, bool $allow_dirty = false ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $this->sanitize_name( $name ) );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$status = $this->git_status( $name );
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
			'name'    => $this->sanitize_name( $name ),
		);
	}

	/**
	 * Stage paths in a workspace repository.
	 *
	 * @param string $name  Repository directory name.
	 * @param array  $paths Relative paths to stage.
	 * @return array
	 */
	public function git_add( string $name, array $paths ): array|\WP_Error {
		$repo_name = $this->sanitize_name( $name );
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		if ( empty( $paths ) ) {
			return new \WP_Error( 'missing_paths', 'At least one path is required for git add.', array( 'status' => 400 ) );
		}

		$allowed_roots = $this->get_repo_allowed_paths( $repo_name );
		if ( empty( $allowed_roots ) ) {
			return new \WP_Error( 'no_allowed_paths', sprintf( 'No allowed paths configured for repo "%s".', $repo_name ), array( 'status' => 403 ) );
		}

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

			if ( ! $this->is_path_allowed( $relative, $allowed_roots ) ) {
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
			'name'    => $repo_name,
			'paths'   => $clean_paths,
			'message' => 'Paths staged successfully.',
		);
	}

	/**
	 * Commit staged changes in a workspace repository.
	 *
	 * @param string $name    Repository directory name.
	 * @param string $message Commit message.
	 * @return array
	 */
	public function git_commit( string $name, string $message ): array|\WP_Error {
		$repo_name = $this->sanitize_name( $name );
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name, true );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
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
			'name'    => $repo_name,
			'commit'  => trim( (string) $commit['output'] ),
			'message' => 'Commit created successfully.',
		);
	}

	/**
	 * Push commits for a workspace repository.
	 *
	 * @param string      $name   Repository directory name.
	 * @param string      $remote Remote name.
	 * @param string|null $branch Branch override.
	 * @return array
	 */
	public function git_push( string $name, string $remote = 'origin', ?string $branch = null ): array|\WP_Error {
		$repo_name = $this->sanitize_name( $name );
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed( $repo_name, true );
		if ( is_wp_error( $policy_check ) ) {
			return $policy_check;
		}

		$current_branch_result = $this->run_git( $repo_path, 'rev-parse --abbrev-ref HEAD' );
		if ( is_wp_error( $current_branch_result ) ) {
			return $current_branch_result;
		}

		$current_branch = trim( (string) $current_branch_result['output'] );
		$target_branch  = $branch ? trim( $branch ) : $current_branch;

		$fixed_branch = $this->get_repo_fixed_branch( $repo_name );
		if ( null !== $fixed_branch && $target_branch !== $fixed_branch ) {
			return new \WP_Error( 'branch_restricted', sprintf( 'Push blocked: repo "%s" is restricted to branch "%s".', $repo_name, $fixed_branch ), array( 'status' => 403 ) );
		}

		$cmd    = sprintf( 'push %s %s', escapeshellarg( $remote ), escapeshellarg( $target_branch ) );
		$result = $this->run_git( $repo_path, $cmd );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'name'    => $repo_name,
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

		return array(
			'success' => true,
			'name'    => $this->sanitize_name( $name ),
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

		return array(
			'success' => true,
			'name'    => $this->sanitize_name( $name ),
			'diff'    => $diff['output'] ?? '',
		);
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
		$real_container = realpath( $container );
		$real_target    = realpath( $target );

		if ( false === $real_container || false === $real_target ) {
			return array(
				'valid'   => false,
				'message' => 'Path does not exist.',
			);
		}

		if ( 0 !== strpos( $real_target, $real_container . '/' ) && $real_target !== $real_container ) {
			return array(
				'valid'   => false,
				'message' => 'Path traversal detected. Access denied.',
			);
		}

		return array(
			'valid'     => true,
			'real_path' => $real_target,
		);
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
	 * Resolve and validate repository path by name.
	 *
	 * @param string $name Repository name.
	 * @return string|\WP_Error String path on success, WP_Error on failure.
	 */
	private function resolve_repo_path( string $name ): string|\WP_Error {
		$sanitized = $this->sanitize_name( $name );
		$repo_path = $this->workspace_path . '/' . $sanitized;

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $sanitized ), array( 'status' => 404 ) );
		}

		if ( ! is_dir( $repo_path . '/.git' ) ) {
			return new \WP_Error( 'not_git_repo', sprintf( 'Repository "%s" is not a git repository.', $sanitized ), array( 'status' => 400 ) );
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
		$escaped_repo = escapeshellarg( $repo_path );
		$command      = sprintf( 'git -C %s %s 2>&1', $escaped_repo, $git_args );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return new \WP_Error(
				'git_command_failed',
				sprintf( 'Git command failed (exit %d): %s', $exit_code, implode( "\n", $output ) ),
				array(
					'status' => 500,
					'output' => implode( "\n", $output ),
				)
			);
		}

		return array(
			'success' => true,
			'output'  => implode( "\n", $output ),
		);
	}

	/**
	 * Check if repo has git mutation permissions enabled.
	 *
	 * @param string $repo_name    Repository name.
	 * @param bool   $require_push Whether push must also be enabled.
	 * @return true|\WP_Error
	 */
	private function ensure_git_mutation_allowed( string $repo_name, bool $require_push = false ): true|\WP_Error {
		$policies = $this->get_workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? null;

		if ( ! is_array( $repo ) || empty( $repo['write_enabled'] ) ) {
			return new \WP_Error( 'git_write_disabled', sprintf( 'Git write operations are disabled for repo "%s".', $repo_name ), array( 'status' => 403 ) );
		}

		if ( $require_push && empty( $repo['push_enabled'] ) ) {
			return new \WP_Error( 'git_push_disabled', sprintf( 'Git push is disabled for repo "%s".', $repo_name ), array( 'status' => 403 ) );
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
		$normalized = strtolower( ltrim( str_replace( '\\', '/', $path ), '/' ) );

		$sensitive_patterns = array(
			'.env',
			'credentials.json',
			'id_rsa',
			'id_ed25519',
			'.pem',
			'.key',
			'secrets',
		);

		foreach ( $sensitive_patterns as $pattern ) {
			if ( str_contains( $normalized, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Basic traversal detection for relative paths.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private function has_traversal( string $path ): bool {
		$parts = explode( '/', str_replace( '\\', '/', $path ) );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '.' === $part ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read workspace git policy settings.
	 *
	 * @return array
	 */
	private function get_workspace_git_policies(): array {
		$defaults = array(
			'repos' => array(),
		);

		$settings = get_option( 'datamachine_workspace_git_policies', $defaults );
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		if ( ! isset( $settings['repos'] ) || ! is_array( $settings['repos'] ) ) {
			$settings['repos'] = array();
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
