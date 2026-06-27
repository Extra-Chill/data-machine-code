<?php
/**
 * Workspace git and file mutation operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;
use DataMachineCode\Support\ProcessRunner;

defined('ABSPATH') || exit;

trait WorkspaceGitOperations {



	/**
	 * Get git status details for a workspace repository.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array
	 */
	public function git_status( string $handle ): array|\WP_Error {
		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$status_result = $this->run_git($repo_path, 'status --porcelain');
		if ( is_wp_error($status_result) ) {
			return $status_result;
		}

		$policy_attestation = $this->build_workspace_policy_attestation($repo_name, $repo_path);
		if ( is_wp_error($policy_attestation) ) {
			return $policy_attestation;
		}

		$files = array_filter(array_map('trim', explode("\n", $status_result['output'] ?? '')));

		$response = array(
			'success'     => true,
			'name'        => $parsed['dir_name'],
			'repo'        => $parsed['repo'],
			'is_worktree' => $parsed['is_worktree'],
			'path'        => $repo_path,
			'branch'      => GitRunner::current_branch($repo_path),
			'remote'      => GitRunner::remote_url($repo_path),
			'commit'      => GitRunner::latest_commit_summary($repo_path),
			'dirty'       => count($files),
			'files'       => array_values($files),
		);

		if ( null !== $policy_attestation ) {
			$response['workspace_policy'] = $policy_attestation;
		}
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			$response['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($handle);
		}

		return $response;
	}

	/**
	 * Pull latest changes for a workspace repository.
	 *
	 * @param  string $handle      Workspace handle.
	 * @param  bool   $allow_dirty Allow pull with dirty working tree.
	 * @return array
	 */
	public function git_pull( string $handle, bool $allow_dirty = false, bool $allow_primary_mutation = false, string $remote = 'origin', ?string $branch = null ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git pull');
		}

		$parsed    = $this->parse_handle($handle);
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($parsed['repo']);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation, 'Pass allow_primary_refresh=true to refresh it');
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		$status = $this->git_status($handle);
		if ( is_wp_error($status) ) {
			return $status;
		}

		if ( ! $allow_dirty && ( $status['dirty'] ?? 0 ) > 0 ) {
			return new \WP_Error('dirty_working_tree', 'Working tree is dirty. Commit/stash changes first or pass allow_dirty=true.', array( 'status' => 400 ));
		}

		$remote = trim($remote);
		$branch = null !== $branch ? trim($branch) : null;
		if ( '' === $remote ) {
			$remote = 'origin';
		}

		$command = 'pull --ff-only';
		if ( null !== $branch && '' !== $branch ) {
			$command .= ' ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch);
		}

		$result = $this->run_git($repo_path, $command);

		if ( is_wp_error($result) ) {
			return $result;
		}

		if ( empty($parsed['is_worktree']) ) {
			$this->emit_workspace_changed('primary_refresh', $parsed['repo'], $parsed['dir_name'], $repo_path);
		}

		return array(
			'success' => true,
			'message' => trim( (string) $result['output']),
			'name'    => $parsed['dir_name'],
		);
	}

	/**
	 * Run a bounded shell command in a runner workspace.
	 *
	 * @param  string              $handle          Workspace handle.
	 * @param  string              $command         Shell command to execute.
	 * @param  string              $description     Human-readable reason for the command.
	 * @param  int                 $timeout_seconds Timeout in seconds.
	 * @param  array<string,mixed> $env             Extra environment variables.
	 * @param  string|null         $cwd             Optional relative working directory.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run_runner_workspace_command( string $handle, string $command, string $description = '', int $timeout_seconds = 300, array $env = array(), ?string $cwd = null ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'command execution');
		}

		$command = trim($command);
		if ( '' === $command ) {
			return new \WP_Error('runner_workspace_command_missing_command', 'command is required.', array( 'status' => 400 ));
		}

		$parsed    = $this->parse_handle($handle);
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$workdir = $repo_path;
		if ( null !== $cwd && '' !== trim($cwd) ) {
			$relative = trim(str_replace('\\', '/', $cwd), '/');
			if ( '' === $relative || str_contains($relative, '..') ) {
				return new \WP_Error('runner_workspace_command_invalid_cwd', 'cwd must be a relative path inside the workspace.', array( 'status' => 400 ));
			}

			$target = $repo_path . '/' . $relative;
			if ( ! is_dir($target) ) {
				return new \WP_Error('runner_workspace_command_cwd_not_found', sprintf('cwd "%s" was not found inside the workspace.', $relative), array( 'status' => 404 ));
			}

			$validation = $this->validate_containment($target, $repo_path);
			if ( ! $validation['valid'] ) {
				return new \WP_Error('path_traversal', $validation['message'], array( 'status' => 403 ));
			}
			$workdir = $validation['real_path'];
		}

		$clean_env = array();
		foreach ( $env as $key => $value ) {
			$key = trim( (string) $key );
			if ( '' === $key || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) ) {
				return new \WP_Error('runner_workspace_command_invalid_env', sprintf('Invalid environment variable name "%s".', $key), array( 'status' => 400 ));
			}
			$clean_env[ $key ] = (string) $value;
		}

		$timeout_seconds = max(1, min(1800, $timeout_seconds));
		$started         = microtime(true);
		$base_env        = getenv();
		if ( ! is_array($base_env) ) {
			$base_env = $_ENV;
		}

		$result     = ProcessRunner::run(
			$command,
			array(
				'cwd'              => $workdir,
				'env'              => empty($clean_env) ? null : array_merge($base_env, $clean_env),
				'timeout_seconds'  => $timeout_seconds,
				'output_cap_bytes' => 1048576,
				'error_as_result'  => true,
				'separate_streams' => true,
			)
		);
		$elapsed_ms = max(0, (int) round(( microtime(true) - $started ) * 1000));

		if ( is_wp_error($result) ) {
			return $result;
		}

		$stdout    = (string) ( $result['stdout'] ?? ( $result['output'] ?? '' ) );
		$stderr    = (string) ( $result['stderr'] ?? '' );
		$exit_code = (int) ( $result['exit_code'] ?? 1 );
		$success   = 0 === $exit_code;

		return array(
			'success'      => $success,
			'kind'         => 'runner_workspace_command',
			'backend'      => 'local_git',
			'failure_type' => $success ? null : 'command_failed',
			'name'         => $parsed['dir_name'],
			'repo'         => $parsed['repo'],
			'path'         => $repo_path,
			'cwd'          => $workdir,
			'command'      => $command,
			'description'  => $description,
			'exit_code'    => $exit_code,
			'stdout'       => $stdout,
			'stderr'       => $stderr,
			'elapsed_ms'   => $elapsed_ms,
			'timed_out'    => false,
			'workspace'    => array(
				'handle'      => $parsed['dir_name'],
				'repo'        => $parsed['repo'],
				'is_worktree' => $parsed['is_worktree'],
				'backend'     => 'local_git',
			),
			'message'      => $success ? 'Runner workspace command completed successfully.' : 'Runner workspace command failed.',
		);
	}

	/**
	 * Stage paths in a workspace repository.
	 *
	 * @param  string $handle Workspace handle.
	 * @param  array  $paths  Relative paths to stage.
	 * @return array
	 */
	public function git_add( string $handle, array $paths, bool $allow_primary_mutation = false ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git add');
		}

		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation);
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		if ( empty($paths) ) {
			return new \WP_Error('missing_paths', 'At least one path is required for git add.', array( 'status' => 400 ));
		}

		$writable_roots = $this->get_repo_writable_roots($repo_name);

		$clean_paths = array();
		foreach ( $paths as $path ) {
			$relative = trim( (string) $path);
			if ( '' === $relative ) {
				continue;
			}

			if ( $this->has_traversal($relative) || str_starts_with($relative, '/') ) {
				return new \WP_Error('invalid_path', sprintf('Invalid path for git add: %s', $relative), array( 'status' => 400 ));
			}

			if ( $this->is_sensitive_path($relative) ) {
				return new \WP_Error('sensitive_path', sprintf('Refusing to stage sensitive path: %s', $relative), array( 'status' => 403 ));
			}

			if ( ! empty($writable_roots) && ! $this->is_path_allowed($relative, $writable_roots) ) {
				return new \WP_Error('path_not_allowed', sprintf('Path "%s" is outside configured writable_roots.', $relative), array( 'status' => 403 ));
			}

			$clean_paths[] = $relative;
		}

		if ( empty($clean_paths) ) {
			return new \WP_Error('no_valid_paths', 'No valid paths provided for git add.', array( 'status' => 400 ));
		}

		$preflight = $this->enforce_workspace_policy($repo_name, $repo_path, $clean_paths);
		if ( is_wp_error($preflight) ) {
			return $preflight;
		}

		$escaped_paths = array_map('escapeshellarg', $clean_paths);
		$result        = $this->run_git($repo_path, 'add -- ' . implode(' ', $escaped_paths));

		if ( is_wp_error($result) ) {
			return $result;
		}

		$attestation = $this->enforce_workspace_policy($repo_name, $repo_path);
		if ( is_wp_error($attestation) ) {
			return $attestation;
		}

		$response = array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $repo_name,
			'paths'   => $clean_paths,
			'message' => 'Paths staged successfully.',
		);

		if ( null !== $attestation ) {
			$response['workspace_policy'] = $attestation;
		}

		return $response;
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
	 * @param  string $handle                 Workspace handle.
	 * @param  string $path                   Relative path within the repo.
	 * @param  bool   $recursive              Required when target is a directory.
	 * @param  bool   $allow_primary_mutation Whether the primary checkout may be mutated.
	 * @return array{success: bool, name: string, repo: string, path: string, deleted: array<int,string>, was_tracked: bool}|\WP_Error
	 */
	public function delete_path( string $handle, string $path, bool $recursive = false, bool $allow_primary_mutation = false ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'delete');
		}

		$parsed = $this->require_explicit_workspace_handle($handle);
		if ( is_wp_error($parsed) ) {
			return $parsed;
		}

		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation);
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		$relative = trim($path);
		if ( '' === $relative ) {
			return new \WP_Error('missing_path', 'Path is required for delete.', array( 'status' => 400 ));
		}

		if ( $this->has_traversal($relative) || str_starts_with($relative, '/') ) {
			return new \WP_Error('invalid_path', sprintf('Invalid path for delete: %s', $relative), array( 'status' => 400 ));
		}

		if ( $this->is_sensitive_path($relative) ) {
			return new \WP_Error('sensitive_path', sprintf('Refusing to delete sensitive path: %s', $relative), array( 'status' => 403 ));
		}

		$writable_roots = $this->get_repo_writable_roots($repo_name);
		if ( ! empty($writable_roots) && ! $this->is_path_allowed($relative, $writable_roots) ) {
			return new \WP_Error('path_not_allowed', sprintf('Path "%s" is outside configured writable_roots.', $relative), array( 'status' => 403 ));
		}

		$preflight = $this->enforce_workspace_policy($repo_name, $repo_path, array( $relative ));
		if ( is_wp_error($preflight) ) {
			return $preflight;
		}

		$absolute = $repo_path . '/' . $relative;
		if ( ! file_exists($absolute) && ! is_link($absolute) ) {
			return new \WP_Error('not_found', sprintf('Path not found: %s', $relative), array( 'status' => 404 ));
		}

		$is_dir = is_dir($absolute) && ! is_link($absolute);
		if ( $is_dir && ! $recursive ) {
			return new \WP_Error('directory_requires_recursive', sprintf('Path "%s" is a directory; pass recursive=true to delete.', $relative), array( 'status' => 400 ));
		}

		$ls_files   = $this->run_git($repo_path, 'ls-files --error-unmatch -- ' . escapeshellarg($relative));
		$is_tracked = ! is_wp_error($ls_files);

		$deleted = array();
		if ( $is_tracked ) {
			$flags  = $is_dir ? '-r ' : '';
			$result = $this->run_git($repo_path, 'rm ' . $flags . '-- ' . escapeshellarg($relative));
			if ( is_wp_error($result) ) {
				return $result;
			}
			foreach ( explode("\n", $result['output'] ?? '') as $line ) {
				if ( preg_match('/^rm \'(.+)\'$/', trim($line), $matches) ) {
					$deleted[] = $matches[1];
				}
			}
			if ( empty($deleted) ) {
				$deleted[] = $relative;
			}
		} elseif ( $is_dir ) {
			$removed = $this->remove_contained_directory_recursive($absolute, $repo_path, $repo_path);
			if ( is_wp_error($removed) ) {
				return $removed;
			}
			$deleted = $removed;
		} else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! unlink($absolute) ) {
				return new \WP_Error('delete_failed', sprintf('Failed to delete file: %s', $relative), array( 'status' => 500 ));
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
	 * Commit staged changes in a workspace repository.
	 *
	 * @param  string $handle                 Workspace handle.
	 * @param  string $message                Commit message.
	 * @param  bool   $allow_primary_mutation Whether the primary checkout may be mutated.
	 * @return array
	 */
	public function git_commit( string $handle, string $message, bool $allow_primary_mutation = false ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git commit');
		}

		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name, true);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation, 'Pass allow_dangerous_primary_mutation=true to commit on it');
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		$message = trim($message);
		if ( '' === $message ) {
			return new \WP_Error('missing_message', 'Commit message is required.', array( 'status' => 400 ));
		}

		if ( strlen($message) < 8 ) {
			return new \WP_Error('message_too_short', 'Commit message must be at least 8 characters.', array( 'status' => 400 ));
		}

		if ( strlen($message) > 200 ) {
			return new \WP_Error('message_too_long', 'Commit message must be 200 characters or fewer.', array( 'status' => 400 ));
		}

		$staged = $this->run_git($repo_path, 'diff --cached --name-only');
		if ( is_wp_error($staged) ) {
			return $staged;
		}

		$staged_files = array_filter(array_map('trim', explode("\n", $staged['output'] ?? '')));
		if ( empty($staged_files) ) {
			return new \WP_Error('nothing_staged', 'No staged changes to commit.', array( 'status' => 400 ));
		}

		$attestation = $this->enforce_workspace_policy($repo_name, $repo_path);
		if ( is_wp_error($attestation) ) {
			return $attestation;
		}

		$commit = $this->run_git($repo_path, 'commit -m ' . escapeshellarg($message));
		if ( is_wp_error($commit) ) {
			return $commit;
		}

		$response = array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $repo_name,
			'commit'  => trim( (string) $commit['output']),
			'message' => 'Commit created successfully.',
		);

		if ( null !== $attestation ) {
			$response['workspace_policy'] = $attestation;
		}

		return $response;
	}

	/**
	 * Push commits for a workspace repository.
	 *
	 * `fixed_branch` policy applies only to primary checkouts. Worktrees
	 * may push any branch (they exist precisely for feature work).
	 *
	 * @param  string      $handle                 Workspace handle.
	 * @param  string      $remote                 Remote name.
	 * @param  string|null $branch                 Branch override.
	 * @param  bool        $allow_primary_mutation Whether the primary may be pushed.
	 * @return array
	 */
	public function git_push( string $handle, string $remote = 'origin', ?string $branch = null, bool $allow_primary_mutation = false, bool $force_with_lease = false, ?string $expected_sha = null ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git push');
		}

		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name, true);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation, 'Pass allow_dangerous_primary_mutation=true to push from it');
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		$current_branch_result = $this->run_git($repo_path, 'rev-parse --abbrev-ref HEAD');
		if ( is_wp_error($current_branch_result) ) {
			return $current_branch_result;
		}

		$current_branch = trim( (string) $current_branch_result['output']);
		$target_branch  = $branch ? trim($branch) : $current_branch;
		if ( '' === $target_branch || 'HEAD' === $target_branch ) {
			return new \WP_Error('invalid_branch', 'Cannot push from a detached HEAD without an explicit branch.', array( 'status' => 400 ));
		}

		if ( $force_with_lease ) {
			$force_guard = $this->ensure_force_push_branch_allowed($repo_name, $target_branch);
			if ( is_wp_error($force_guard) ) {
				return $force_guard;
			}
		}

		$publish_files = $this->get_workspace_policy_publish_files($repo_path, $current_branch);
		if ( is_wp_error($publish_files) ) {
			return $publish_files;
		}

		$attestation = $this->enforce_workspace_policy($repo_name, $repo_path, $publish_files);
		if ( is_wp_error($attestation) ) {
			return $attestation;
		}

		// fixed_branch only constrains the primary checkout.
		if ( ! $parsed['is_worktree'] ) {
			$fixed_branch = $this->get_repo_fixed_branch($repo_name);
			if ( null !== $fixed_branch && $target_branch !== $fixed_branch ) {
				return new \WP_Error('branch_restricted', sprintf('Push blocked: primary checkout of "%s" is restricted to branch "%s". Use a worktree for other branches.', $repo_name, $fixed_branch), array( 'status' => 403 ));
			}
		}

		$lease_flag = '';
		if ( $force_with_lease ) {
			$expected_sha = $expected_sha ? trim($expected_sha) : $this->git_remote_branch_sha($repo_path, $remote, $target_branch);
			$lease_ref    = 'refs/heads/' . $target_branch;
			$lease_flag   = '' !== (string) $expected_sha
			? sprintf(' --force-with-lease=%s:%s', escapeshellarg($lease_ref), escapeshellarg( (string) $expected_sha))
			: sprintf(' --force-with-lease=%s', escapeshellarg($lease_ref));
		}

		$refspec = $force_with_lease ? 'HEAD:refs/heads/' . $target_branch : $target_branch;
		$cmd     = sprintf('push%s %s %s', $lease_flag, escapeshellarg($remote), escapeshellarg($refspec));
		$result  = $this->run_git($repo_path, $cmd);

		if ( is_wp_error($result) ) {
			return $result;
		}

		$github_repo = null;
		$branch_url  = null;
		$remote_url  = $this->git_get_remote($repo_path, $remote);
		if ( null !== $remote_url ) {
			$github_repo = GitHubRemote::slug($remote_url);
			if ( null !== $github_repo ) {
				$branch_url = GitHubRemote::branchUrl($remote_url, $target_branch);
			}
		}

		$response = array(
			'success'            => true,
			'kind'               => 'branch_push',
			'name'               => $parsed['dir_name'],
			'repo'               => $github_repo ?? $repo_name,
			'workspace_repo'     => $repo_name,
			'github_repo'        => $github_repo,
			'remote'             => $remote,
			'branch'             => $target_branch,
			'force_with_lease'   => $force_with_lease,
			'expected_sha'       => $expected_sha,
			'url'                => $branch_url,
			'html_url'           => $branch_url,
			'next_required_tool' => null !== $github_repo ? 'create_github_pull_request' : null,
			'next_required_args' => null !== $github_repo ? array(
				'repo' => $github_repo,
				'head' => $target_branch,
			) : null,
			'message'            => trim( (string) $result['output']),
		);

		if ( null !== $attestation ) {
			$response['workspace_policy'] = $attestation;
		}

		return $response;
	}

	/**
	 * Rebase a workspace checkout and report conflicts without resolving them.
	 *
	 * @param  string      $handle                 Workspace handle.
	 * @param  string|null $onto                   Base ref to rebase onto.
	 * @param  string|null $strategy_option        Optional git rebase strategy option.
	 * @param  bool        $continue_rebase        Continue an in-progress rebase.
	 * @param  bool        $allow_primary_mutation Whether the primary checkout may be mutated.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_rebase( string $handle, ?string $onto = null, ?string $strategy_option = null, bool $continue_rebase = false, bool $allow_primary_mutation = false ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git rebase');
		}

		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation, 'Pass allow_dangerous_primary_mutation=true to rebase it');
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		if ( $continue_rebase ) {
			$result = $this->run_git($repo_path, '-c core.editor=true rebase --continue');
			if ( is_wp_error($result) ) {
				$conflicts = $this->git_conflicts($repo_path);
				if ( ! empty($conflicts) ) {
					return $this->git_rebase_result($parsed, $repo_path, 'conflicting', $conflicts);
				}
				$output = (string) ( $result->get_error_data()['output'] ?? $result->get_error_message() );
				if ( str_contains($output, 'No changes') || str_contains($output, 'patch contents already upstream') ) {
					$skip = $this->run_git($repo_path, 'rebase --skip');
					if ( is_wp_error($skip) ) {
						return $skip;
					}

					return $this->git_rebase_result($parsed, $repo_path, 'clean', array(), $onto);
				}
				return $result;
			}

			return $this->git_rebase_result($parsed, $repo_path, 'clean', array(), $onto);
		}

		$onto  = $onto && '' !== trim($onto) ? trim($onto) : $this->default_rebase_onto($repo_path);
		$fetch = $this->run_git($repo_path, 'fetch origin');
		if ( is_wp_error($fetch) ) {
			return $fetch;
		}

		$args = array( 'rebase' );
		if ( null !== $strategy_option && '' !== trim($strategy_option) ) {
			$args[] = '-X';
			$args[] = escapeshellarg(trim($strategy_option));
		}
		$args[] = escapeshellarg($onto);

		$result = $this->run_git($repo_path, implode(' ', $args));
		if ( is_wp_error($result) ) {
			$conflicts = $this->git_conflicts($repo_path);
			if ( ! empty($conflicts) ) {
				return $this->git_rebase_result($parsed, $repo_path, 'conflicting', $conflicts, $onto);
			}
			return $result;
		}

		return $this->git_rebase_result($parsed, $repo_path, 'clean', array(), $onto);
	}

	/**
	 * Reset a workspace checkout.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_reset( string $handle, string $mode = 'mixed', ?string $target = null, bool $allow_destructive = false, bool $allow_primary_mutation = false ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git reset');
		}

		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation, 'Pass allow_dangerous_primary_mutation=true to reset it');
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		$mode = trim($mode);
		if ( ! in_array($mode, array( 'soft', 'mixed', 'hard' ), true) ) {
			return new \WP_Error('invalid_reset_mode', 'Reset mode must be one of: soft, mixed, hard.', array( 'status' => 400 ));
		}

		if ( 'hard' === $mode && ! $allow_destructive ) {
			return new \WP_Error('destructive_reset_blocked', 'Hard reset requires allow_destructive=true.', array( 'status' => 403 ));
		}

		$target = $target && '' !== trim($target) ? trim($target) : $this->default_rebase_onto($repo_path);
		$before = $this->run_git($repo_path, 'rev-parse HEAD');
		if ( is_wp_error($before) ) {
			return $before;
		}
		$affected = $this->run_git($repo_path, 'diff --name-only ' . escapeshellarg(trim( (string) $before['output'])) . ' ' . escapeshellarg($target));

		$result = $this->run_git($repo_path, sprintf('reset --%s %s', $mode, escapeshellarg($target)));
		if ( is_wp_error($result) ) {
			return $result;
		}

		$after = $this->run_git($repo_path, 'rev-parse HEAD');
		if ( is_wp_error($after) ) {
			return $after;
		}

		$paths = is_wp_error($affected) ? array() : array_filter(array_map('trim', explode("\n", (string) ( $affected['output'] ?? '' ))));
		return array(
			'success'        => true,
			'name'           => $parsed['dir_name'],
			'repo'           => $repo_name,
			'mode'           => $mode,
			'previous_head'  => trim( (string) $before['output']),
			'new_head'       => trim( (string) $after['output']),
			'paths_affected' => count($paths),
		);
	}

	/**
	 * Return PR freshness details from GitHub.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function pr_status( string $handle, string|int|null $pr = null, ?string $branch = null ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$resolved = $this->resolve_pull_request($repo_path, $pr, $branch);
		if ( is_wp_error($resolved) ) {
			return $resolved;
		}

		$base = (array) ( $resolved['base'] ?? array() );
		$head = (array) ( $resolved['head'] ?? array() );

		return array(
			'success'     => true,
			'pr'          => (int) ( $resolved['number'] ?? 0 ),
			'pr_url'      => (string) ( $resolved['html_url'] ?? '' ),
			'title'       => (string) ( $resolved['title'] ?? '' ),
			'base_branch' => (string) ( $base['ref'] ?? '' ),
			'head_branch' => (string) ( $head['ref'] ?? '' ),
			'mergeable'   => $resolved['mergeable'] ?? null,
			'merge_state' => $resolved['mergeable_state'] ?? null,
			'behind'      => 'behind' === ( $resolved['mergeable_state'] ?? '' ),
			'ahead'       => 'behind' !== ( $resolved['mergeable_state'] ?? '' ),
			'conflicting' => false === ( $resolved['mergeable'] ?? null ) || 'dirty' === ( $resolved['mergeable_state'] ?? '' ),
			'head_sha'    => (string) ( $head['sha'] ?? '' ),
			'base_sha'    => (string) ( $base['sha'] ?? '' ),
		);
	}

	/**
	 * Bring a pull request branch up to date with its base branch.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function pr_rebase( string $handle, string|int|null $pr = null, bool $squash = false, array $drop_paths = array(), bool $allow_primary_mutation = false ): array|\WP_Error {
		$parsed    = $this->parse_handle($handle);
		$repo_name = $parsed['repo'];
		$repo_path = $this->resolve_repo_path($handle);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$policy_check = $this->ensure_git_mutation_allowed($repo_name, true);
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$primary_check = $this->ensure_primary_mutation_allowed($parsed, $allow_primary_mutation, 'Pass allow_dangerous_primary_mutation=true to rebase it');
		if ( is_wp_error($primary_check) ) {
			return $primary_check;
		}

		$resolved = $this->resolve_pull_request($repo_path, $pr, null);
		if ( is_wp_error($resolved) ) {
			return $resolved;
		}

		$base_branch = (string) ( $resolved['base']['ref'] ?? '' );
		if ( '' === $base_branch ) {
			return new \WP_Error('missing_pr_base', 'Pull request base branch could not be resolved.', array( 'status' => 400 ));
		}

		$fetch = $this->run_git($repo_path, 'fetch origin ' . escapeshellarg($base_branch));
		if ( is_wp_error($fetch) ) {
			return $fetch;
		}

		$onto   = 'origin/' . $base_branch;
		$rebase = $this->git_rebase($handle, $onto, null, false, $allow_primary_mutation);
		if ( is_wp_error($rebase) ) {
			return $rebase;
		}

		$dropped = array();
		if ( 'conflicting' === ( $rebase['state'] ?? '' ) && ! empty($drop_paths) ) {
			foreach ( $rebase['conflicts'] as $conflict ) {
				$path = (string) ( $conflict['path'] ?? '' );
				if ( '' === $path || ! $this->path_matches_any_glob($path, $drop_paths) ) {
					continue;
				}
				$checkout = $this->run_git($repo_path, 'checkout --ours -- ' . escapeshellarg($path));
				if ( is_wp_error($checkout) ) {
					return $checkout;
				}
				$add = $this->run_git($repo_path, 'add -- ' . escapeshellarg($path));
				if ( is_wp_error($add) ) {
					return $add;
				}
				$dropped[] = $path;
			}

			$remaining = $this->git_conflicts($repo_path);
			if ( ! empty($remaining) ) {
				return array(
					'success'              => false,
					'state'                => 'conflicting',
					'dropped_paths'        => count($dropped),
					'unresolved_conflicts' => $remaining,
					'squashed'             => false,
					'pushed'               => false,
					'head_sha'             => $this->git_head_sha($repo_path),
					'pr_url'               => (string) ( $resolved['html_url'] ?? '' ),
				);
			}

			$continue = $this->git_rebase($handle, $onto, null, true, $allow_primary_mutation);
			if ( is_wp_error($continue) ) {
				return $continue;
			}
			if ( 'conflicting' === ( $continue['state'] ?? '' ) ) {
				return array(
					'success'              => false,
					'state'                => 'conflicting',
					'dropped_paths'        => count($dropped),
					'unresolved_conflicts' => $continue['conflicts'] ?? array(),
					'squashed'             => false,
					'pushed'               => false,
					'head_sha'             => $this->git_head_sha($repo_path),
					'pr_url'               => (string) ( $resolved['html_url'] ?? '' ),
				);
			}
		}

		$squashed = false;
		if ( $squash ) {
			$reset = $this->run_git($repo_path, 'reset --soft ' . escapeshellarg($onto));
			if ( is_wp_error($reset) ) {
				return $reset;
			}
			$title = trim( (string) ( $resolved['title'] ?? '' ));
			if ( '' === $title ) {
				$title = 'Update pull request branch';
			}
			$body       = trim( (string) ( $resolved['body'] ?? '' ));
			$commit_cmd = 'commit -m ' . escapeshellarg($title);
			if ( '' !== $body ) {
				$commit_cmd .= ' -m ' . escapeshellarg($body);
			}
			$commit = $this->run_git($repo_path, $commit_cmd);
			if ( is_wp_error($commit) ) {
				return $commit;
			}
			$squashed = true;
		}

		$head_branch = (string) ( $resolved['head']['ref'] ?? '' );
		$push        = $this->git_push($handle, 'origin', $head_branch, $allow_primary_mutation, true);
		if ( is_wp_error($push) ) {
			return $push;
		}

		return array(
			'success'              => true,
			'state'                => 'clean',
			'dropped_paths'        => count($dropped),
			'unresolved_conflicts' => array(),
			'squashed'             => $squashed,
			'pushed'               => true,
			'head_sha'             => $this->git_head_sha($repo_path),
			'pr_url'               => (string) ( $resolved['html_url'] ?? '' ),
		);
	}

	/**
	 * Read git log entries for a workspace repository.
	 *
	 * @param  string $name  Repository directory name.
	 * @param  int    $limit Number of entries.
	 * @return array
	 */
	public function git_log( string $name, int $limit = 20 ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path($name);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$limit = max(1, min(100, $limit));
		$cmd   = sprintf('log -n %d --pretty=format:%s', $limit, escapeshellarg('%h|%an|%ad|%s'));
		$log   = $this->run_git($repo_path, $cmd);

		if ( is_wp_error($log) ) {
			return $log;
		}

		$entries = array();
		$lines   = array_filter(array_map('trim', explode("\n", $log['output'] ?? '')));
		foreach ( $lines as $line ) {
			$parts = explode('|', $line, 4);
			if ( count($parts) < 4 ) {
				continue;
			}

			$entries[] = array(
				'hash'    => $parts[0],
				'author'  => $parts[1],
				'date'    => $parts[2],
				'subject' => $parts[3],
			);
		}

		$parsed = $this->parse_handle($name);
		$result = array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $parsed['repo'],
			'entries' => $entries,
		);
		if ( WorkspaceAliasResolver::is_context_repository($name) ) {
			$result['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($name);
		}

		return $result;
	}

	/**
	 * Read git diff for a workspace repository.
	 *
	 * @param  string      $name   Repository directory name.
	 * @param  string|null $from   Optional from ref.
	 * @param  string|null $to     Optional to ref.
	 * @param  bool        $staged Whether to diff staged changes.
	 * @param  string|null $path   Optional relative path filter.
	 * @return array
	 */
	public function git_diff( string $name, ?string $from = null, ?string $to = null, bool $staged = false, ?string $path = null ): array|\WP_Error {
		$policy_error = WorkspaceAliasResolver::read_error_if_disallowed($name, $path ?? '');
		if ( null !== $policy_error ) {
			return $policy_error;
		}

		$repo_path = $this->resolve_repo_path($name);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$args = array( 'diff' );
		if ( $staged ) {
			$args[] = '--cached';
		}

		if ( ! empty($from) ) {
			$args[] = escapeshellarg($from);
		}

		if ( ! empty($to) ) {
			$args[] = escapeshellarg($to);
		}

		if ( ! empty($path) ) {
			$relative = trim($path);
			if ( $this->has_traversal($relative) || str_starts_with($relative, '/') ) {
				return new \WP_Error('invalid_path', sprintf('Invalid diff path: %s', $relative), array( 'status' => 400 ));
			}

			$args[] = '--';
			$args[] = escapeshellarg($relative);
		}

		$diff = $this->run_git($repo_path, implode(' ', $args));
		if ( is_wp_error($diff) ) {
			return $diff;
		}

		$parsed = $this->parse_handle($name);
		$result = array(
			'success' => true,
			'name'    => $parsed['dir_name'],
			'repo'    => $parsed['repo'],
			'diff'    => $diff['output'] ?? '',
		);
		if ( WorkspaceAliasResolver::is_context_repository($name) ) {
			$result['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($name);
		}

		return $result;
	}

	/**
	 * Resolve and validate a workspace handle to a filesystem path.
	 *
	 * Accepts both primary handles (`<repo>`) and worktree handles
	 * (`<repo>@<branch-slug>`). For worktrees, the .git is a file
	 * pointing back at the primary's .git directory — we accept both
	 * directory and file forms.
	 *
	 * @param  string $handle Workspace handle.
	 * @return string|\WP_Error Real path on success, WP_Error on failure.
	 */
	private function resolve_repo_path( string $handle ): string|\WP_Error {
		$parsed    = $this->parse_handle($handle);
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir($repo_path) ) {
			return new \WP_Error('repo_not_found', sprintf('Workspace handle "%s" not found.', $parsed['dir_name']), array( 'status' => 404 ));
		}

		// .git can be a directory (primary) or a file (worktree).
		$git_path = $repo_path . '/.git';
		if ( ! is_dir($git_path) && ! is_file($git_path) ) {
			return new \WP_Error('not_git_repo', sprintf('Handle "%s" is not a git repository or worktree.', $parsed['dir_name']), array( 'status' => 400 ));
		}

		$validation = $this->validate_containment($repo_path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error('path_traversal', $validation['message'], array( 'status' => 403 ));
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
	 * @param  array{is_worktree: bool, repo: string, dir_name: string} $parsed
	 * @param  bool                                                     $allow
	 * @return true|\WP_Error
	 */
	private function ensure_primary_mutation_allowed( array $parsed, bool $allow, string $allow_guidance = 'Pass allow_primary_mutation=true to operate on it' ): true|\WP_Error {
		if ( $parsed['is_worktree'] ) {
			return true;
		}
		if ( $allow ) {
			return true;
		}
		return new \WP_Error(
			'primary_mutation_blocked',
			sprintf(
				'Primary checkout "%s" is read-only by default. %s, or use a worktree handle (e.g. %s@<branch-slug>).',
				$parsed['repo'],
				$allow_guidance,
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
	 * @param  string $repo_name    Repository name.
	 * @param  bool   $require_push Whether push must also be enabled.
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
		$write_enabled = array_key_exists('write_enabled', $repo) ? (bool) $repo['write_enabled'] : true;
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
			$push_enabled = array_key_exists('push_enabled', $repo) ? (bool) $repo['push_enabled'] : true;
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
	 * @param  string $repo_name Repository name.
	 * @return array
	 */
	private function get_repo_allowed_paths( string $repo_name ): array {
		$policies = $this->get_workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? array();

		$paths = $repo['allowed_paths'] ?? array();
		return $this->normalize_policy_paths($paths);
	}

	/**
	 * Get writable relative roots for runner/workspace policy enforcement.
	 *
	 * `writable_roots` is the runner-facing contract. `allowed_paths` remains
	 * supported as the existing workspace git policy name and fallback.
	 *
	 * @param  string $repo_name Repository name.
	 * @return array<int,string>
	 */
	private function get_repo_writable_roots( string $repo_name ): array {
		return ( new WorkspacePolicy() )->writable_roots_for_repo($repo_name);
	}

	/**
	 * Get hidden relative paths for runner/workspace policy enforcement.
	 *
	 * @param  string $repo_name Repository name.
	 * @return array<int,string>
	 */
	private function get_repo_hidden_paths( string $repo_name ): array {
		return ( new WorkspacePolicy() )->hidden_paths_for_repo($repo_name);
	}

	/**
	 * Normalize policy path lists.
	 *
	 * @param  mixed $paths Raw policy value.
	 * @return array<int,string>
	 */
	private function normalize_policy_paths( mixed $paths ): array {
		return ( new WorkspacePolicy() )->normalize_paths($paths);
	}

	/**
	 * Enforce the configured workspace policy and return its attestation.
	 *
	 * @param  string                 $repo_name Repository name.
	 * @param  string                 $repo_path Repository path.
	 * @param  array<int,string>|null $paths     Optional paths to check instead of all changed files.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	private function enforce_workspace_policy( string $repo_name, string $repo_path, ?array $paths = null ): array|null|\WP_Error {
		$attestation = $this->build_workspace_policy_attestation($repo_name, $repo_path, $paths);
		if ( is_wp_error($attestation) ) {
			return $attestation;
		}

		if ( null !== $attestation && ! empty($attestation['violations']) ) {
			return new \WP_Error(
				'workspace_policy_violation',
				sprintf('Workspace policy violation: %d violation(s) found.', count($attestation['violations'])),
				array(
					'status'               => 403,
					'workspace_policy'     => $attestation,
					'policy_hash'          => $attestation['policy_hash'],
					'workspace_violations' => $attestation['violations'],
				)
			);
		}

		return $attestation;
	}

	/**
	 * Build a machine-readable workspace policy attestation.
	 *
	 * @param  string                 $repo_name Repository name.
	 * @param  string                 $repo_path Repository path.
	 * @param  array<int,string>|null $paths     Optional paths to check instead of all changed files.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	private function build_workspace_policy_attestation( string $repo_name, string $repo_path, ?array $paths = null ): array|null|\WP_Error {
		$policy = new WorkspacePolicy();
		if ( empty($policy->writable_roots_for_repo($repo_name)) && empty($policy->hidden_paths_for_repo($repo_name)) ) {
			return null;
		}

		$changed_files = null === $paths ? $this->get_workspace_policy_changed_files($repo_path) : $this->normalize_policy_paths($paths);
		if ( is_wp_error($changed_files) ) {
			return $changed_files;
		}

		$ignored_files = $this->get_workspace_policy_ignored_files($repo_path);
		if ( is_wp_error($ignored_files) ) {
			return $ignored_files;
		}

		return $policy->attest_changed_paths(
			$repo_name,
			$repo_path,
			$changed_files,
			$ignored_files,
			function ( string $path ) use ( $repo_path ): string {
				$stage = $this->run_git($repo_path, 'ls-files --stage -- ' . escapeshellarg($path));
				return is_wp_error($stage) ? '' : (string) ( $stage['output'] ?? '' );
			}
		);
	}

	/**
	 * Return changed files visible to a workspace policy check.
	 *
	 * @param  string $repo_path Repository path.
	 * @return array<int,string>|\WP_Error
	 */
	private function get_workspace_policy_changed_files( string $repo_path ): array|\WP_Error {
		$commands = array(
			'diff --name-only',
			'diff --cached --name-only',
			'ls-files --others --exclude-standard',
		);

		$files = array();
		foreach ( $commands as $command ) {
			$result = $this->run_git($repo_path, $command);
			if ( is_wp_error($result) ) {
				return $result;
			}
			$files = array_merge($files, $this->normalize_policy_paths(explode("\n", (string) ( $result['output'] ?? '' ))));
		}

		return array_values(array_unique($files));
	}

	/**
	 * Return ignored workspace files so policy reports can flag them explicitly.
	 *
	 * @param  string $repo_path Repository path.
	 * @return array<int,string>|\WP_Error
	 */
	private function get_workspace_policy_ignored_files( string $repo_path ): array|\WP_Error {
		$result = $this->run_git($repo_path, 'ls-files --others --ignored --exclude-standard');
		if ( is_wp_error($result) ) {
			return $result;
		}

		return $this->normalize_policy_paths(explode("\n", (string) ( $result['output'] ?? '' )));
	}

	/**
	 * Return files that a push could publish, plus dirty policy-visible files.
	 *
	 * @param  string $repo_path      Repository path.
	 * @param  string $current_branch Current branch name.
	 * @return array<int,string>|\WP_Error
	 */
	private function get_workspace_policy_publish_files( string $repo_path, string $current_branch ): array|\WP_Error {
		$files = $this->get_workspace_policy_changed_files($repo_path);
		if ( is_wp_error($files) ) {
			return $files;
		}

		$base_refs = array();
		$upstream  = $this->run_git($repo_path, 'rev-parse --abbrev-ref --symbolic-full-name @{upstream}');
		if ( ! is_wp_error($upstream) && '' !== trim( (string) ( $upstream['output'] ?? '' )) ) {
			$base_refs[] = trim( (string) $upstream['output']);
		}

		$origin_head = $this->run_git($repo_path, 'symbolic-ref refs/remotes/origin/HEAD');
		if ( ! is_wp_error($origin_head) && '' !== trim( (string) ( $origin_head['output'] ?? '' )) ) {
			$base_refs[] = preg_replace('#^refs/remotes/#', '', trim( (string) $origin_head['output']));
		}

		$base_refs[] = 'origin/main';
		$base_refs[] = 'origin/master';

		foreach ( array_values(array_unique(array_filter($base_refs))) as $base_ref ) {
			$diff = $this->run_git($repo_path, 'diff --name-only ' . escapeshellarg($base_ref . '...' . $current_branch));
			if ( is_wp_error($diff) ) {
				continue;
			}

			$files = array_merge($files, $this->normalize_policy_paths(explode("\n", (string) ( $diff['output'] ?? '' ))));
			break;
		}

		return array_values(array_unique($files));
	}

	/**
	 * Get fixed branch restriction for a repo.
	 *
	 * @param  string $repo_name Repository name.
	 * @return string|null
	 */
	private function get_repo_fixed_branch( string $repo_name ): ?string {
		$policies = $this->get_workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? array();
		$branch   = trim( (string) ( $repo['fixed_branch'] ?? '' ));

		return '' === $branch ? null : $branch;
	}

	/**
	 * Refuse force-with-lease pushes to base/fixed branches.
	 *
	 * @return true|\WP_Error
	 */
	private function ensure_force_push_branch_allowed( string $repo_name, string $target_branch ): true|\WP_Error {
		$fixed_branch  = $this->get_repo_fixed_branch($repo_name);
		$base_branches = array_filter(
			array_unique(
				array(
					$fixed_branch,
					'main',
					'master',
					'trunk',
					'develop',
					'dev',
				)
			)
		);

		if ( in_array($target_branch, $base_branches, true) ) {
			return new \WP_Error(
				'force_push_base_branch_blocked',
				sprintf('Force-with-lease push blocked for protected/base branch "%s".', $target_branch),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function git_conflicts( string $repo_path ): array {
		$result = $this->run_git($repo_path, 'diff --name-only --diff-filter=U');
		if ( is_wp_error($result) ) {
			return array();
		}

		$paths     = array_filter(array_map('trim', explode("\n", (string) ( $result['output'] ?? '' ))));
		$conflicts = array();
		foreach ( $paths as $path ) {
			$absolute = $repo_path . '/' . $path;
			$content  = '';
			if ( is_file($absolute) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workspace file read for conflict-marker counting.
				$content = (string) file_get_contents($absolute);
			}
			$conflicts[] = array(
				'path'                 => $path,
				'conflict_markers'     => substr_count($content, '<<<<<<< '),
				'has_conflict_markers' => str_contains($content, '<<<<<<< '),
			);
		}

		return $conflicts;
	}

	/**
	 * @param array<string,mixed> $parsed @param array<int,array<string,mixed>> $conflicts @return array<string,mixed>
	 */
	private function git_rebase_result( array $parsed, string $repo_path, string $state, array $conflicts, ?string $onto = null ): array {
		return array(
			'success'   => 'clean' === $state,
			'state'     => $state,
			'name'      => $parsed['dir_name'],
			'repo'      => $parsed['repo'],
			'onto'      => $onto,
			'conflicts' => $conflicts,
			'applied'   => $this->git_rebase_count($repo_path, 'done'),
			'pending'   => $this->git_rebase_count($repo_path, 'git-rebase-todo'),
			'head_sha'  => $this->git_head_sha($repo_path),
		);
	}

	private function git_rebase_count( string $repo_path, string $file ): int {
		$path = $this->run_git($repo_path, 'rev-parse --git-path rebase-merge/' . escapeshellarg($file));
		if ( is_wp_error($path) ) {
			return 0;
		}

		$file_path = trim( (string) ( $path['output'] ?? '' ));
		if ( '' !== $file_path && ! str_starts_with($file_path, '/') ) {
			$file_path = $repo_path . '/' . $file_path;
		}
		if ( '' === $file_path || ! is_file($file_path) ) {
			return 0;
		}

		$lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		return is_array($lines) ? count($lines) : 0;
	}

	private function default_rebase_onto( string $repo_path ): string {
		$origin_head = $this->run_git($repo_path, 'symbolic-ref --short refs/remotes/origin/HEAD');
		if ( ! is_wp_error($origin_head) ) {
			$ref = trim( (string) ( $origin_head['output'] ?? '' ));
			if ( '' !== $ref ) {
				return $ref;
			}
		}

		$branch = $this->git_get_branch($repo_path);
		return $branch ? 'origin/' . $branch : 'origin/main';
	}

	private function git_head_sha( string $repo_path ): string {
		$result = $this->run_git($repo_path, 'rev-parse HEAD');
		return is_wp_error($result) ? '' : trim( (string) ( $result['output'] ?? '' ));
	}

	private function git_remote_branch_sha( string $repo_path, string $remote, string $branch ): ?string {
		$result = $this->run_git($repo_path, 'ls-remote --heads ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch));
		if ( is_wp_error($result) ) {
			return null;
		}

		$line = trim( (string) ( $result['output'] ?? '' ));
		if ( preg_match('/^([a-f0-9]{40})\s+refs\/heads\//', $line, $matches) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function resolve_pull_request( string $repo_path, string|int|null $pr = null, ?string $branch = null ): array|\WP_Error {
		$remote_url = $this->git_get_remote($repo_path);
		$slug       = $remote_url ? GitHubRemote::slug($remote_url) : null;

		if ( is_string($pr) && preg_match('#github\.com/([\w.-]+/[\w.-]+)/pull/(\d+)#', $pr, $matches) ) {
			$slug = $matches[1];
			$pr   = (int) $matches[2];
		}

		if ( null === $slug ) {
			return new \WP_Error('missing_github_repo', 'Workspace remote does not resolve to a GitHub repository.', array( 'status' => 400 ));
		}

		if ( ! class_exists('\DataMachineCode\Abilities\GitHubAbilities') ) {
			return new \WP_Error('github_abilities_unavailable', 'GitHubAbilities class is not loaded.', array( 'status' => 500 ));
		}

		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat(array( 'repo' => $slug ));
		if ( empty($pat) ) {
			return new \WP_Error('missing_github_token', sprintf('No GitHub token is configured for %s.', $slug), array( 'status' => 403 ));
		}

		if ( null !== $pr && '' !== (string) $pr ) {
			$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(GitHubRemote::apiUrl($slug, 'pulls/' . (int) $pr), array(), $pat);
			if ( is_wp_error($response) ) {
				return $response;
			}
			$data = $response['data'] ?? null;
			return is_array($data) ? $data : new \WP_Error('invalid_github_response', 'GitHub PR response was not an object.', array( 'status' => 502 ));
		}

		$branch = $branch && '' !== trim($branch) ? trim($branch) : (string) $this->git_get_branch($repo_path);
		if ( '' === $branch ) {
			return new \WP_Error('missing_branch', 'Cannot resolve a pull request without a branch.', array( 'status' => 400 ));
		}

		$owner    = explode('/', $slug, 2)[0];
		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl($slug, 'pulls'),
			array(
				'head'  => $owner . ':' . $branch,
				'state' => 'open',
			),
			$pat
		);
		if ( is_wp_error($response) ) {
			return $response;
		}

		$data = $response['data'] ?? null;
		if ( ! is_array($data) || empty($data[0]) || ! is_array($data[0]) ) {
			return new \WP_Error('pr_not_found', sprintf('No open pull request found for %s:%s.', $owner, $branch), array( 'status' => 404 ));
		}

		return $data[0];
	}

	/**
	 * @param array<int,string> $patterns
	 */
	private function path_matches_any_glob( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern);
			if ( '' === $pattern ) {
				continue;
			}
			$regex = '#^' . str_replace('\*\*', '.*', str_replace('\*', '[^/]*', preg_quote($pattern, '#'))) . '$#';
			if ( preg_match($regex, $path) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a relative path is within the allowlist.
	 *
	 * @param  string $path          Relative path.
	 * @param  array  $allowed_paths Allowed roots.
	 * @return bool
	 */
	private function is_path_allowed( string $path, array $allowed_paths ): bool {
		return PathSecurity::isPathAllowed($path, $allowed_paths);
	}

	/**
	 * Check whether a path appears sensitive.
	 *
	 * @param  string $path Relative path.
	 * @return bool
	 */
	private function is_sensitive_path( string $path ): bool {
		return PathSecurity::isSensitivePath($path);
	}

	/**
	 * Basic traversal detection for relative paths.
	 *
	 * @param  string $path Relative path.
	 * @return bool
	 */
	private function has_traversal( string $path ): bool {
		return PathSecurity::hasTraversal($path);
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

		$settings = get_option('datamachine_workspace_git_policies', $defaults);
		if ( ! is_array($settings) ) {
			$settings = $defaults;
		}

		if ( ! isset($settings['repos']) || ! is_array($settings['repos']) ) {
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
		$settings = apply_filters('datamachine_workspace_git_policies', $settings);

		if ( ! isset($settings['repos']) || ! is_array($settings['repos']) ) {
			return $defaults;
		}

		return $settings;
	}

	/**
	 * Get the origin remote URL for a git repo.
	 *
	 * @param  string $repo_path Path to repo.
	 * @return string|null Remote URL or null.
	 */
	private function git_get_remote( string $repo_path, string $remote_name = 'origin' ): ?string {
		return GitRunner::remote_url($repo_path, $remote_name);
	}

	/**
	 * Get the current branch for a git repo.
	 *
	 * @param  string $repo_path Path to repo.
	 * @return string|null Branch name or null.
	 */
	private function git_get_branch( string $repo_path ): ?string {
		return GitRunner::current_branch($repo_path);
	}
}
