<?php
/**
 * Workspace git and file mutation operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

trait WorkspaceGitOperations {

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

		$github_repo = null;
		$branch_url  = null;
		$remote_url  = $this->git_get_remote( $repo_path, $remote );
		if ( null !== $remote_url ) {
			$github_repo = GitHubRemote::slug( $remote_url );
			if ( null !== $github_repo ) {
				$branch_url = 'https://github.com/' . $github_repo . '/tree/' . rawurlencode( $target_branch );
			}
		}

		return array(
			'success'            => true,
			'kind'               => 'branch_push',
			'name'               => $parsed['dir_name'],
			'repo'               => $github_repo ?? $repo_name,
			'workspace_repo'     => $repo_name,
			'github_repo'        => $github_repo,
			'remote'             => $remote,
			'branch'             => $target_branch,
			'url'                => $branch_url,
			'html_url'           => $branch_url,
			'next_required_tool' => null !== $github_repo ? 'create_github_pull_request' : null,
			'next_required_args' => null !== $github_repo ? array(
				'repo' => $github_repo,
				'head' => $target_branch,
			) : null,
			'message'            => trim( (string) $result['output'] ),
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
	private function git_get_remote( string $repo_path, string $remote_name = 'origin' ): ?string {
		$escaped = escapeshellarg( $repo_path );
		$remote_name = preg_replace( '/[^A-Za-z0-9._-]/', '', $remote_name );
		if ( '' === $remote_name ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$remote = exec( sprintf( 'git -C %s config --get %s 2>/dev/null', $escaped, escapeshellarg( 'remote.' . $remote_name . '.url' ) ) );
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

}
