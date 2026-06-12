<?php
/**
 * Workspace repository lifecycle operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\ProcessRunner;

defined('ABSPATH') || exit;

if ( ! class_exists(ProcessRunner::class) ) {
	require_once dirname(__DIR__) . '/Support/ProcessRunner.php';
}

trait WorkspaceRepositoryLifecycle {



	/**
	 * List repositories in the workspace.
	 *
	 * @param  string|null $repo Optional primary repository name to include.
	 * @param  string|null $type Optional checkout type filter: primary or worktree.
	 * @return array{success: bool, repos: array, path: string}|\WP_Error
	 */
	public function list_repos( ?string $repo = null, ?string $type = null ): array|\WP_Error {
		$path    = $this->workspace_path;
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		$repo_filter = null !== $repo && '' !== trim($repo) ? $this->parse_handle($repo)['repo'] : null;
		$type_filter = null !== $type && '' !== trim($type) ? strtolower(trim($type)) : null;
		if ( null !== $type_filter && ! in_array($type_filter, array( 'primary', 'worktree', 'context' ), true) ) {
			return new \WP_Error('invalid_workspace_type', 'Workspace list type must be "primary", "worktree", or "context".', array( 'status' => 400 ));
		}

		if ( ! is_dir($path) ) {
			return array(
				'success' => true,
				'repos'   => array(),
				'path'    => $path,
			);
		}

		$repos   = array();
		$entries = scandir($path);

		if ( 'context' !== $type_filter ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$entry_path = $path . '/' . $entry;
				if ( ! is_dir($entry_path) ) {
					continue;
				}

				$git_path = $entry_path . '/.git';
				$is_git   = is_dir($git_path) || is_file($git_path);
				$is_wt    = is_file($git_path);
				$parsed   = $this->parse_handle($entry);

				if ( null !== $repo_filter && $parsed['repo'] !== $repo_filter ) {
					continue;
				}

				$is_worktree = $is_wt || $parsed['is_worktree'];
				if ( 'primary' === $type_filter && $is_worktree ) {
					continue;
				}
				if ( 'worktree' === $type_filter && ! $is_worktree ) {
					continue;
				}

				$repo_info = array(
					'name'        => $entry,
					'path'        => $entry_path,
					'git'         => $is_git,
					'is_worktree' => $is_worktree,
					'repo'        => $parsed['repo'],
				);

				if ( $parsed['is_worktree'] ) {
					$repo_info['branch_slug'] = $parsed['branch_slug'];
				}

				// Get git remote if available.
				if ( $is_git ) {
					$remote = $this->git_get_remote($entry_path);
					if ( null !== $remote ) {
						$repo_info['remote'] = $remote;
					}

					$branch = $this->git_get_branch($entry_path);
					if ( null !== $branch ) {
						$repo_info['branch'] = $branch;
					}

					if ( ! $is_worktree ) {
						$repo_info['primary_freshness'] = $this->build_primary_freshness_report($entry_path, $entry);
					}
				}

				$repos[] = $repo_info;
			}
		}

		if ( null === $type_filter || 'context' === $type_filter ) {
			foreach ( WorkspaceAliasResolver::context_repositories() as $alias => $context ) {
				if ( null !== $repo_filter && $this->parse_handle( (string) ( $context['target'] ?? $alias ) )['repo'] !== $repo_filter && $alias !== $repo_filter ) {
					continue;
				}

				$target  = (string) ( $context['target'] ?? $alias );
				$path    = $this->workspace_path . '/' . $this->parse_handle($target)['dir_name'];
				$repos[] = array(
					'name'             => $alias,
					'path'             => is_dir($path) ? $path : null,
					'git'              => is_dir($path . '/.git') || is_file($path . '/.git'),
					'is_worktree'      => false,
					'is_context'       => true,
					'repo'             => (string) ( $context['repo'] ?? $target ),
					'ref'              => (string) ( $context['ref'] ?? '' ),
					'workspace_policy' => WorkspaceAliasResolver::policy_attestation($alias),
				);
			}
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
	 * @param  string      $url     Git clone URL.
	 * @param  string|null $name    Directory name override (derived from URL if null).
	 * @param  array       $options Optional clone options.
	 * @return array{success: bool, name?: string, path?: string, message?: string}|\WP_Error
	 */
	public function clone_repo( string $url, ?string $name = null, array $options = array() ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		// Validate URL.
		if ( empty($url) ) {
			return new \WP_Error('missing_url', 'Repository URL is required.', array( 'status' => 400 ));
		}

		// Derive name from URL if not provided.
		if ( null === $name || '' === $name ) {
			$name = $this->derive_repo_name($url);
			if ( null === $name ) {
				return new \WP_Error('invalid_url', sprintf('Could not derive repository name from URL: %s. Use --name to specify.', $url), array( 'status' => 400 ));
			}
		}

		// Reject @-suffixed names — those are reserved for worktrees.
		if ( str_contains($name, '@') ) {
			return new \WP_Error('invalid_clone_name', 'Repository names cannot contain "@". The "@<branch-slug>" suffix is reserved for worktrees (use "workspace worktree add" instead).', array( 'status' => 400 ));
		}

		$name                   = $this->sanitize_name($name);
		$repo_path              = $this->workspace_path . '/' . $name;
		$allow_duplicate_remote = ! empty($options['allow_duplicate_remote']);

		// Check if already exists.
		if ( is_dir($repo_path) ) {
			$existing_remote = file_exists($repo_path . '/.git') ? $this->git_get_remote($repo_path) : null;
			if ( null !== $existing_remote && ! $allow_duplicate_remote && $this->normalize_git_remote_url($url) === $this->normalize_git_remote_url($existing_remote) ) {
				return $this->clone_remote_exists_error(
					$url,
					$name,
					array(
						'name'   => $name,
						'path'   => $repo_path,
						'remote' => $existing_remote,
					)
				);
			}
			return $this->clone_target_exists_error($name, $repo_path);
		}

		// Ensure workspace exists.
		$ensure = $this->ensure_exists();
		if ( is_wp_error($ensure) ) {
			return $ensure;
		}

		$existing_primary = $this->find_primary_by_remote($url, $name);
		if ( null !== $existing_primary && ! $allow_duplicate_remote ) {
			return $this->clone_remote_exists_error($url, $name, $existing_primary);
		}

		if ( ! GitRunner::supports_streaming() ) {
			return GitRunner::unavailable_error('Clone workspace repository', true);
		}

		$partial_clone     = ! (bool) ( $options['full'] ?? false ) && $this->should_use_partial_clone($url);
		$progress_callback = is_callable($options['progress_callback'] ?? null) ? $options['progress_callback'] : null;
		$started_at        = microtime(true);

		$this->emit_clone_progress(
			$progress_callback,
			'start',
			sprintf(
				'Cloning %s into %s%s.',
				$url,
				$repo_path,
				$partial_clone ? ' using partial clone (--filter=blob:none)' : ''
			),
			$started_at
		);

		$env = $this->build_clone_environment($url, $options);
		if ( is_wp_error($env) ) {
			return $env;
		}

		$command = $this->build_clone_command($url, $repo_path, $partial_clone);
		$result  = $this->run_clone_command($command, $progress_callback, $started_at, $env);

		if ( is_wp_error($result) ) {
			return $this->clone_failed_error($result, $name, $repo_path, $url);
		}

		$this->emit_workspace_changed('clone', $name, $name, $repo_path);

		return array(
			'success' => true,
			'name'    => $name,
			'path'    => $repo_path,
			'message' => sprintf('Cloned %s into workspace as "%s".', $url, $name),
		);
	}

	/**
	 * Build a git clone command.
	 *
	 * @param  string $url           Git clone URL.
	 * @param  string $repo_path     Destination path.
	 * @param  bool   $partial_clone Whether to request blobless partial clone.
	 * @return string Shell command.
	 */
	private function build_clone_command( string $url, string $repo_path, bool $partial_clone ): string {
		$args = array( 'clone', '--progress' );
		if ( $partial_clone ) {
			$args[] = '--filter=blob:none';
		}

		$args[] = escapeshellarg($url);
		$args[] = escapeshellarg($repo_path);

		return 'GIT_TERMINAL_PROMPT=0 git ' . implode(' ', $args);
	}

	/**
	 * Build additional environment values for git clone.
	 *
	 * @param  string $url     Git clone URL.
	 * @param  array  $options Optional clone options.
	 * @return array<string,string>|null|\WP_Error Extra environment values, null for default environment, or error.
	 */
	private function build_clone_environment( string $url, array $options ): array|null|\WP_Error {
		$auth_token_env = isset($options['auth_token_env']) && is_scalar($options['auth_token_env']) ? trim( (string) $options['auth_token_env']) : '';
		if ( '' === $auth_token_env ) {
			return null;
		}

		if ( ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $auth_token_env) ) {
			return new \WP_Error('invalid_auth_token_env', 'Clone auth token environment variable name is invalid.', array( 'status' => 400 ));
		}

		$token = trim( (string) getenv($auth_token_env));
		if ( '' === $token ) {
			return new \WP_Error('missing_auth_token_env', sprintf('Clone auth token environment variable %s is empty or unavailable.', $auth_token_env), array( 'status' => 400 ));
		}

		$parts = wp_parse_url($url);
		$host  = is_array($parts) && isset($parts['host']) ? strtolower( (string) $parts['host']) : '';
		if ( '' === $host ) {
			return new \WP_Error('unsupported_auth_token_url', 'Clone auth token support requires an HTTPS repository URL.', array( 'status' => 400 ));
		}

		$env = getenv();
		if ( ! is_array($env) ) {
			$env = array();
		}

		$env['GIT_CONFIG_COUNT']   = '1';
		$env['GIT_CONFIG_KEY_0']   = sprintf('http.https://%s/.extraheader', $host);
		$env['GIT_CONFIG_VALUE_0'] = 'AUTHORIZATION: bearer ' . $token;

		return $env;
	}

	/**
	 * Remote HTTP(S) and SSH hosts generally support safe blobless clones; local
	 * paths and file URLs often do not, and are usually test fixtures anyway.
	 *
	 * @param  string $url Git clone URL.
	 * @return bool True when a partial clone should be attempted.
	 */
	private function should_use_partial_clone( string $url ): bool {
		return (bool) preg_match('#^(https?://|git@|ssh://)#', $url);
	}

	/**
	 * Stream a clone command to an optional progress callback.
	 *
	 * @param  string        $command           Shell command.
	 * @param  callable|null $progress_callback Optional progress callback.
	 * @param  float         $started_at        Clone start timestamp.
	 * @return array{success: true, output: string}|\WP_Error
	 */
	private function run_clone_command( string $command, ?callable $progress_callback, float $started_at, ?array $env = null ): array|\WP_Error {
		$result = ProcessRunner::run(
			$command,
			array(
				'env'                        => $env,
				'error_code'                 => 'clone_failed',
				'poll_interval_microseconds' => 100000,
				'on_output'                  => function ( string $chunk ) use ( $progress_callback, $started_at ): void {
					$this->emit_clone_output($progress_callback, $chunk, $started_at);
				},
			)
		);

		if ( is_wp_error($result) ) {
			$data      = $result->get_error_data();
			$exit_code = is_array($data) ? (int) ( $data['exit_code'] ?? 1 ) : 1;
			$output    = is_array($data) ? (string) ( $data['output'] ?? $result->get_error_message() ) : $result->get_error_message();
			return new \WP_Error(
				'clone_failed',
				sprintf('Git clone failed (exit %d): %s', $exit_code, $output),
				array(
					'status' => 500,
					'output' => $output,
				)
			);
		}

		return array(
			'success' => true,
			'output'  => $result['output'],
		);
	}

	/**
	 * Emit normalized clone output chunks.
	 *
	 * @param callable|null $progress_callback Optional progress callback.
	 * @param string        $chunk             Raw process output chunk.
	 * @param float         $started_at        Clone start timestamp.
	 */
	private function emit_clone_output( ?callable $progress_callback, string $chunk, float $started_at ): void {
		$lines = preg_split('/[\r\n]+/', $chunk);
		if ( ! is_array($lines) ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim($line);
			if ( '' === $line ) {
				continue;
			}

			$this->emit_clone_progress($progress_callback, 'git', $line, $started_at);
		}
	}

	/**
	 * Emit one structured clone progress message.
	 *
	 * @param callable|null $progress_callback Optional progress callback.
	 * @param string        $phase             Progress phase.
	 * @param string        $message           Progress message.
	 * @param float         $started_at        Clone start timestamp.
	 */
	private function emit_clone_progress( ?callable $progress_callback, string $phase, string $message, float $started_at ): void {
		if ( null === $progress_callback ) {
			return;
		}

		$progress_callback(
			array(
				'phase'   => $phase,
				'elapsed' => max(0.0, microtime(true) - $started_at),
				'message' => $message,
			)
		);
	}

	/**
	 * Build a recovery-focused error when a clone target exists already.
	 *
	 * @param  string $name      Workspace repo name.
	 * @param  string $repo_path Target path.
	 * @return \WP_Error Error with remediation data.
	 */
	private function clone_target_exists_error( string $name, string $repo_path ): \WP_Error {
		$looks_like_git = is_dir($repo_path . '/.git');
		$state          = $looks_like_git ? 'existing checkout' : 'partial or non-git directory';
		$next_steps     = array(
			sprintf('Inspect the target: %s', $repo_path),
			sprintf('If it is safe to discard, remove it explicitly: wp datamachine-code workspace remove %s', $name),
			'Then retry the clone command.',
		);

		return new \WP_Error(
			'repo_exists',
			sprintf('Clone target already exists as %s: %s. Next steps: %s', $state, $repo_path, implode(' ', $next_steps)),
			array(
				'status'     => 400,
				'path'       => $repo_path,
				'state'      => $state,
				'next_steps' => $next_steps,
			)
		);
	}

	/**
	 * Add recovery guidance when the same remote already has a primary checkout.
	 *
	 * @param  string              $url      Requested clone URL.
	 * @param  string              $name     Requested workspace name.
	 * @param  array<string,mixed> $existing Existing primary checkout summary.
	 * @return \WP_Error Error with remediation data.
	 */
	private function clone_remote_exists_error( string $url, string $name, array $existing ): \WP_Error {
		$existing_name = (string) ( $existing['name'] ?? '' );
		$next_steps    = array(
			sprintf('Reuse existing primary checkout: %s', $existing_name),
			sprintf('Refresh it when needed: %s', $this->primary_refresh_command($existing_name)),
			sprintf('Then create an isolated branch: wp datamachine-code workspace worktree add %s <branch>', $existing_name),
		);

		return new \WP_Error(
			'repo_remote_exists',
			sprintf('A primary checkout for %s already exists as "%s" at %s. Do not clone the same remote as "%s"; refresh/reuse the existing primary instead. Next steps: %s', $url, $existing_name, (string) ( $existing['path'] ?? '' ), $name, implode(' ', $next_steps)),
			array(
				'status'     => 409,
				'url'        => $url,
				'name'       => $name,
				'existing'   => $existing,
				'next_steps' => $next_steps,
			)
		);
	}

	/**
	 * Add recovery guidance to git clone failures.
	 *
	 * @param  \WP_Error $error     Clone process error.
	 * @param  string    $name      Workspace repo name.
	 * @param  string    $repo_path Target path.
	 * @param  string    $url       Git clone URL.
	 * @return \WP_Error Error with remediation data.
	 */
	private function clone_failed_error( \WP_Error $error, string $name, string $repo_path, string $url ): \WP_Error {
		$next_steps = array(
			sprintf('Confirm the repository URL is reachable: %s', $url),
			sprintf('Inspect any partial target: %s', $repo_path),
			sprintf('If the target is safe to discard, remove it explicitly: wp datamachine-code workspace remove %s', $name),
			'Then retry the clone command.',
		);

		$data                 = (array) $error->get_error_data();
		$data['path']         = $repo_path;
		$data['next_steps']   = $next_steps;
		$data['partial_path'] = is_dir($repo_path) ? $repo_path : null;

		return new \WP_Error(
			'clone_failed',
			$error->get_error_message() . ' Next steps: ' . implode(' ', $next_steps),
			$data
		);
	}

	/**
	 * Adopt an existing primary checkout already located in the workspace.
	 *
	 * There is no persistent primary registry today; primary checkouts are
	 * discovered by their on-disk directory names. Adoption is therefore a
	 * non-destructive validation step that makes that convention explicit.
	 *
	 * @param  string      $path Existing checkout path.
	 * @param  string|null $name Workspace name override (derived from basename if null).
	 * @return array{success: bool, name?: string, path?: string, already_adopted?: bool, message?: string}|\WP_Error
	 */
	public function adopt_repo( string $path, ?string $name = null ): array|\WP_Error {
		$path = rtrim(trim($path), '/');
		if ( '' === $path ) {
			return new \WP_Error('missing_path', 'Checkout path is required.', array( 'status' => 400 ));
		}

		if ( ! is_dir($path) || ! is_readable($path) ) {
			return new \WP_Error('adopt_path_unreadable', sprintf('Checkout path does not exist or is not readable: %s', $path), array( 'status' => 400 ));
		}

		$ensure = $this->ensure_exists();
		if ( is_wp_error($ensure) ) {
			return $ensure;
		}

		$validation = $this->validate_containment($path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error('adopt_outside_workspace', 'Only checkouts already under DATAMACHINE_WORKSPACE_PATH can be adopted.', array( 'status' => 400 ));
		}

		$real_path = $validation['real_path'] ?? '';
		if ( '' === $real_path ) {
			return new \WP_Error('adopt_path_unresolved', sprintf('Could not resolve checkout path: %s', $path), array( 'status' => 400 ));
		}

		$git_path = $real_path . '/.git';
		if ( is_file($git_path) ) {
			return new \WP_Error('adopt_linked_worktree', 'Cannot adopt a linked worktree as a primary checkout. Pass the primary checkout path instead.', array( 'status' => 400 ));
		}

		if ( ! is_dir($git_path) ) {
			return new \WP_Error('adopt_not_git_primary', sprintf('Path is not a git primary checkout: %s', $real_path), array( 'status' => 400 ));
		}

		if ( null === $name || '' === trim($name) ) {
			$name = basename($real_path);
		}

		if ( str_contains($name, '@') ) {
			return new \WP_Error('invalid_adopt_name', 'Repository names cannot contain "@". The "@<branch-slug>" suffix is reserved for worktrees.', array( 'status' => 400 ));
		}

		$name = $this->sanitize_name($name);
		if ( '' === $name ) {
			return new \WP_Error('invalid_adopt_name', 'Adopted repository name is empty after sanitization.', array( 'status' => 400 ));
		}

		$expected_path = $this->workspace_path . '/' . $name;
		if ( is_dir($expected_path) ) {
			$expected_real = realpath($expected_path);
			if ( false !== $expected_real && $expected_real !== $real_path ) {
				return new \WP_Error('adopt_name_collision', sprintf('Workspace name "%s" already points at a different directory: %s', $name, $expected_real), array( 'status' => 400 ));
			}
		} else {
			return new \WP_Error('adopt_requires_workspace_path', sprintf('Adoption is non-destructive: %s must already be located at %s. Move or symlink operations are intentionally not performed by v1.', $real_path, $expected_path), array( 'status' => 400 ));
		}

		$this->emit_workspace_changed('adopt', $name, $name, $real_path);

		return array(
			'success'         => true,
			'name'            => $name,
			'path'            => $real_path,
			'already_adopted' => true,
			'message'         => sprintf('Workspace checkout "%s" is already adopted at %s. No filesystem changes were made.', $name, $real_path),
		);
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array{success: bool, message: string}|\WP_Error
	 */
	public function remove_repo( string $handle ): array|\WP_Error {
		$parsed    = $this->parse_handle($handle);
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir($repo_path) ) {
			return new \WP_Error('repo_not_found', sprintf('Workspace handle "%s" not found.', $parsed['dir_name']), array( 'status' => 404 ));
		}

		// Safety: ensure path is within workspace.
		$validation = $this->validate_containment($repo_path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error('path_traversal', $validation['message'], array( 'status' => 403 ));
		}

		// Refuse to remove a primary that still has live worktrees attached.
		if ( ! $parsed['is_worktree'] ) {
			$worktrees = $this->worktree_list($parsed['repo']);
			if ( ! is_wp_error($worktrees) ) {
				$linked = array_filter($worktrees['worktrees'], fn( $wt ) => ! empty($wt['is_worktree']));
				if ( ! empty($linked) ) {
					$slugs = array_map(fn( $wt ) => $wt['branch_slug'] ?? '?', $linked);
					return new \WP_Error('has_worktrees', sprintf('Cannot remove primary "%s": linked worktrees exist (%s). Remove them first with "workspace worktree remove".', $parsed['repo'], implode(', ', $slugs)), array( 'status' => 400 ));
				}
			}
		}

		// Remove recursively.
		$escaped = escapeshellarg($validation['real_path']);
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('rm -rf %s 2>&1', $escaped), $output, $exit_code);

		if ( 0 !== $exit_code ) {
			return new \WP_Error('remove_failed', sprintf('Failed to remove (exit %d): %s', $exit_code, implode("\n", $output)), array( 'status' => 500 ));
		}

		// If we removed a worktree directory but didn't go through `git worktree remove`,
		// prune the registry on the primary so it doesn't keep stale entries.
		if ( $parsed['is_worktree'] ) {
			$primary_path = $this->get_primary_path($parsed['repo']);
			if ( is_dir($primary_path . '/.git') ) {
				WorkspaceMutationLock::with_repo(
					$this->workspace_path,
					$parsed['repo'],
					fn() => $this->run_git($primary_path, 'worktree prune')
				);
			}
			$this->worktree_inventory()->delete($parsed['dir_name']);
		}

		$this->emit_workspace_changed(
			$parsed['is_worktree'] ? 'worktree_remove' : 'remove',
			$parsed['repo'],
			$parsed['dir_name'],
			$repo_path
		);

		return array(
			'success' => true,
			'message' => sprintf('Removed "%s" from workspace.', $parsed['dir_name']),
		);
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array{success: bool, name?: string, path?: string, branch?: string, remote?: string, commit?: string, dirty?: int}|\WP_Error
	 */
	public function show_repo( string $handle ): array|\WP_Error {
		$context_policy = WorkspaceAliasResolver::context_policy_for($handle);
		if ( null !== $context_policy ) {
			$target    = (string) ( $context_policy['target'] ?? $handle );
			$parsed    = $this->parse_handle($target);
			$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];
			$ref       = (string) ( $context_policy['ref'] ?? '' );
			if ( ! is_dir($repo_path) ) {
				return array(
					'success'          => true,
					'name'             => (string) $context_policy['alias'],
					'repo'             => (string) ( $context_policy['repo'] ?? $target ),
					'is_worktree'      => false,
					'is_context'       => true,
					'path'             => null,
					'branch'           => '' !== $ref ? $ref : null,
					'remote'           => '' !== (string) ( $context_policy['repo'] ?? '' ) ? 'https://github.com/' . (string) $context_policy['repo'] . '.git' : null,
					'commit'           => null,
					'dirty'            => 0,
					'workspace_policy' => WorkspaceAliasResolver::policy_attestation($handle),
				);
			}
			$handle = $target;
		}

		$resolved_handle = $this->resolve_primary_repo_name($handle);
		if ( ! is_wp_error($resolved_handle) ) {
			$handle = $resolved_handle;
		}

		$parsed    = $this->parse_handle($handle);
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir($repo_path) ) {
			return new \WP_Error('repo_not_found', sprintf('Workspace handle "%s" not found.', $parsed['dir_name']), array( 'status' => 404 ));
		}

		$escaped = escapeshellarg($repo_path);

     // phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$branch = trim( (string) exec(sprintf('git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', $escaped)));
		$remote = trim( (string) exec(sprintf('git -C %s config --get remote.origin.url 2>/dev/null', $escaped)));
		$commit = trim( (string) exec(sprintf('git -C %s log -1 --format="%%h %%s" 2>/dev/null', $escaped)));
		$status = trim( (string) exec(sprintf('git -C %s status --porcelain 2>/dev/null | wc -l', $escaped)));
     // phpcs:enable

		$result = array(
			'success'           => true,
			'name'              => null !== $context_policy ? (string) $context_policy['alias'] : $parsed['dir_name'],
			'repo'              => $parsed['repo'],
			'is_worktree'       => $parsed['is_worktree'],
			'is_context'        => null !== $context_policy,
			'path'              => $repo_path,
			'branch'            => $branch ? $branch : null,
			'remote'            => $remote ? $remote : null,
			'commit'            => $commit ? $commit : null,
			'dirty'             => (int) $status,
			'primary_freshness' => ! $parsed['is_worktree'] ? $this->build_primary_freshness_report($repo_path, $parsed['dir_name']) : null,
		);
		if ( null !== $context_policy ) {
			$result['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($handle);
		}

		return $result;
	}
}
