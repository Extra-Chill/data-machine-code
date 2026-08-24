<?php
/**
 * GitHub-backed workspace backend for constrained PHP runtimes.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachineCode\Support\GitHubRemote;

defined('ABSPATH') || exit;

if ( ! class_exists(WorkspaceText::class) ) {
	require_once __DIR__ . '/WorkspaceText.php';
}

class RemoteWorkspaceBackend {

	/**
	 * @var WorkspacePolicy
	 */
	private WorkspacePolicy $policy;

	public function __construct( ?WorkspacePolicy $policy = null ) {
		$this->policy = $policy ?? new WorkspacePolicy();
	}



	public const OPTION         = 'datamachine_code_remote_workspace_state';
	private const MAX_READ_SIZE = 1048576;

	/**
	 * Whether the remote backend should handle workspace operations.
	 */
	public static function should_handle(): bool {
		if ( self::has_registered_state() ) {
			return true;
		}

		$diagnostic = \DataMachineCode\Support\GitRunner::diagnose();
		$default    = self::should_handle_for_local_capabilities(
			! empty($diagnostic['git_available']),
			! empty($diagnostic['git_available']) && ! empty($diagnostic['proc_open_available'])
		);

		return (bool) apply_filters(
			'datamachine_code_remote_workspace_backend_should_handle',
			$default
		);
	}

	/**
	 * Decide whether constrained runtimes need the GitHub-backed backend.
	 */
	public static function should_handle_for_local_capabilities( bool $git_available, bool $streaming_available ): bool {
		return ! ( $git_available && $streaming_available );
	}

	/**
	 * Whether remote workspace state already exists for this runtime.
	 */
	public static function has_registered_state(): bool {
		$state = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
		if ( ! is_array($state) ) {
			return false;
		}

		return ! empty($state['repos']) || ! empty($state['worktrees']);
	}

	/**
	 * Clone/register a GitHub repository as a remote workspace primary.
	 *
	 * @param  string      $url  GitHub repository URL.
	 * @param  string|null $name Optional workspace repo name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function clone_repo( string $url, ?string $name = null ): array|\WP_Error {
		$repo = $this->repo_from_url($url);
		if ( is_wp_error($repo) ) {
			return $repo;
		}

		$name = $this->sanitize_name(null !== $name && '' !== $name ? $name : basename( (string) $repo));
		if ( '' === $name ) {
			return new \WP_Error('invalid_clone_name', 'Could not derive a workspace name for the remote repository.', array( 'status' => 400 ));
		}

		$state                        = $this->state();
		$state['repos'][ $name ]      = array(
			'repo' => $repo,
			'url'  => $url,
		);
		$state['repo_names'][ $repo ] = $name;
		$this->save_state($state);

		return array(
			'success' => true,
			'backend' => 'github_api',
			'name'    => $name,
			'path'    => 'github://' . $repo,
			'message' => sprintf('Registered %s as remote workspace "%s".', $repo, $name),
		);
	}

	/**
	 * Create/register a remote worktree branch.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_add( string $repo_name, string $branch, ?string $from = null, array $task = array(), array $intent = array(), string $reuse_policy = 'reuse_compatible' ): array|\WP_Error {
		$repo_name = $this->resolve_alias(trim($repo_name));
		$repo = $this->resolve_repo($repo_name);
		if ( is_wp_error($repo) ) {
			return $repo;
		}

		$branch = trim($branch);
		if ( '' === $branch ) {
			return new \WP_Error('missing_branch', 'Branch is required.', array( 'status' => 400 ));
		}
		if ( array_key_exists('cleanup_policy', $intent) && null === WorktreeContextInjector::normalize_cleanup_policy($intent['cleanup_policy']) ) {
			return new \WP_Error('invalid_cleanup_policy', 'cleanup_policy must be one of: ' . implode(', ', WorktreeContextInjector::VALID_CLEANUP_POLICIES) . '.', array( 'status' => 400 ));
		}
		$intent       = WorktreeContextInjector::normalize_disposable_intent($intent);
		$reuse_policy = strtolower(trim($reuse_policy));
		if ( ! in_array($reuse_policy, WorktreeContextInjector::VALID_REUSE_POLICIES, true) ) {
			return new \WP_Error('invalid_worktree_reuse_policy', 'reuse_policy must be one of: ' . implode(', ', WorktreeContextInjector::VALID_REUSE_POLICIES) . '.', array( 'status' => 400 ));
		}
		$lock = $this->acquire_state_lock($repo_name);
		if ( is_wp_error($lock) ) {
			return $lock;
		}

		try {
		$slug                          = $this->branch_slug($branch);
		$handle                        = $repo_name . '@' . $slug;
		$state                         = $this->state();
		if ( isset($state['worktrees'][ $handle ]) && is_array($state['worktrees'][ $handle ]) ) {
			$existing = $state['worktrees'][ $handle ];
			if ( 'isolated' === $reuse_policy ) {
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to reuse remote worktree "%s": isolated allocation requires a new handle.', $handle), array(
					'status' => 409,
					'reuse'  => array( 'status' => 'refused', 'reason_code' => 'isolated_requested' ),
				));
			}
			if ( 'recycle_terminal' === $reuse_policy ) {
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to recycle remote worktree "%s": terminal safety proof is unavailable.', $handle), array(
					'status' => 409,
					'reuse'  => array( 'status' => 'refused', 'reason_code' => 'remote_recycle_terminal_unsupported' ),
				));
			}
			$existing_intent = WorktreeContextInjector::normalize_disposable_intent((array) $state['worktrees'][ $handle ]);
			if ( $intent !== $existing_intent ) {
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to reuse remote worktree "%s": disposable intent mismatch.', $handle), array(
					'status' => 409,
					'reuse' => array( 'status' => 'refused', 'reason_code' => 'disposable_intent_mismatch', 'requested_intent' => $intent, 'stored_intent' => $existing_intent ),
				));
			}
			if ( ( $existing['branch'] ?? null ) !== $branch ) {
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to reuse remote worktree "%s": branch mismatch.', $handle), array(
					'status' => 409,
					'reuse'  => array( 'status' => 'refused', 'reason_code' => 'branch_mismatch', 'requested_branch' => $branch, 'stored_branch' => $existing['branch'] ?? null ),
				));
			}
			$requested_base = null !== $from && '' !== trim($from) ? trim($from) : '';
			if ( (string) ( $existing['base_ref'] ?? '' ) !== $requested_base ) {
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to reuse remote worktree "%s": base mismatch.', $handle), array(
					'status' => 409,
					'reuse'  => array( 'status' => 'refused', 'reason_code' => 'base_mismatch', 'requested_base_ref' => $requested_base, 'stored_base_ref' => $existing['base_ref'] ?? null ),
				));
			}
			$existing_task = is_array($state['worktrees'][ $handle ]['task'] ?? null) ? $state['worktrees'][ $handle ]['task'] : array();
			if ( (string) ( $task['task_url'] ?? $task['task_ref'] ?? '' ) !== (string) ( $existing_task['task_url'] ?? $existing_task['task_ref'] ?? '' ) ) {
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to reuse remote worktree "%s": task mismatch.', $handle), array(
					'status' => 409,
					'reuse'  => array( 'status' => 'refused', 'reason_code' => 'task_mismatch', 'requested_task' => $task, 'stored_task' => $existing_task ),
				));
			}
			return array(
				'success'        => true,
				'backend'        => 'github_api',
				'handle'         => $handle,
				'path'           => 'github://' . $repo . '#' . $branch,
				'branch'         => $branch,
				'slug'           => $slug,
				'created_branch' => false,
				'reused'         => true,
				'reuse'          => array( 'status' => 'accepted', 'reason_code' => 'exact_compatible_handle' ),
				'message'        => sprintf('Reused remote workspace %s for %s.', $handle, $repo),
			);
		} else {
			$task_identity = (string) ( $task['task_url'] ?? $task['task_ref'] ?? '' );
			$candidates    = array();
			if ( '' !== $task_identity ) {
				foreach ( (array) ( $state['worktrees'] ?? array() ) as $candidate_handle => $candidate ) {
					if ( ! is_array($candidate) || ( $candidate['repo_name'] ?? null ) !== $repo_name ) {
						continue;
					}
					$candidate_task = is_array($candidate['task'] ?? null) ? $candidate['task'] : array();
					if ( $task_identity === (string) ( $candidate_task['task_url'] ?? $candidate_task['task_ref'] ?? '' ) ) {
						$candidates[] = array(
							'handle' => (string) $candidate_handle,
							'branch' => $candidate['branch'] ?? null,
							'task'   => $candidate_task,
						);
					}
				}
				usort($candidates, static fn( array $left, array $right ): int => strcmp((string) $left['handle'], (string) $right['handle']));
			}
			if ( array() !== $candidates && 'isolated' !== $reuse_policy ) {
				$conflicting_handle = (string) $candidates[0]['handle'];
				return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to create remote worktree "%s": same-task candidate "%s" requires --reuse-policy=isolated with purpose, owner_run_ref, and cleanup_policy=remove_on_success.', $handle, $conflicting_handle), array(
					'status' => 409,
					'reuse'  => array( 'status' => 'refused', 'reason_code' => 'same_task_candidate_requires_explicit_isolation', 'canonical_task_identity' => $task_identity, 'conflicting_handle' => $conflicting_handle, 'supported_reuse_policy' => 'isolated', 'candidates' => $candidates ),
				));
			} elseif ( array() !== $candidates ) {
				$missing_intent = WorktreeContextInjector::missing_isolation_intent($intent);
				if ( array() !== $missing_intent ) {
					return new \WP_Error('worktree_reuse_refused', sprintf('Refusing to create remote worktree "%s": same task isolation intent is incomplete.', $handle), array(
						'status' => 409,
						'reuse'  => array( 'status' => 'refused', 'reason_code' => 'same_task_isolation_intent_required', 'missing_intent' => $missing_intent, 'candidates' => $candidates ),
					));
				}
			}
		}
		$state['worktrees'][ $handle ] = array(
			'repo_name'       => $repo_name,
			'repo'            => $repo,
			'branch'          => $branch,
			'base_ref'        => null !== $from && '' !== $from ? $from : '',
			'task'            => $task,
			'purpose'         => $intent['purpose'] ?? null,
			'owner_run_ref'   => $intent['owner_run_ref'] ?? null,
			'cleanup_policy'  => $intent['cleanup_policy'] ?? null,
			'pending_files'   => array(),
			'changed_files'   => array(),
			'last_commit_sha' => '',
		);
		if ( ! $this->save_state($state) ) {
			return new \WP_Error('remote_workspace_state_persist_failed', sprintf('Remote worktree "%s" was not registered because its lifecycle state could not be persisted.', $handle), array( 'status' => 500, 'handle' => $handle ));
		}

		return array(
			'success'        => true,
			'backend'        => 'github_api',
			'handle'         => $handle,
			'path'           => 'github://' . $repo . '#' . $branch,
			'branch'         => $branch,
			'slug'           => $slug,
			'created_branch' => true,
			'purpose'        => $intent['purpose'] ?? null,
			'owner_run_ref'  => $intent['owner_run_ref'] ?? null,
			'cleanup_policy' => $intent['cleanup_policy'] ?? null,
			'message'        => sprintf('Registered remote workspace %s for %s.', $handle, $repo),
		);
		} finally {
			$this->release_state_lock($lock);
		}
	}

	/** Acquire an atomic option-backed lease around remote state admission. */
	private function acquire_state_lock( string $repo_name ): array|\WP_Error {
		$key      = 'datamachine_code_remote_workspace_lock_' . md5($repo_name);
		$token    = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('dmc-', true);
		$deadline = microtime(true) + 10;
		do {
			$lease = array( 'token' => $token, 'expires_at' => time() + 30 );
			if ( add_option($key, $lease, '', false) ) {
				return array( 'key' => $key, 'token' => $token );
			}
			$current = get_option($key, array());
			if ( is_array($current) && (int) ( $current['expires_at'] ?? 0 ) < time() ) {
				$this->compare_delete_option($key, $current);
				continue;
			}
			usleep(50000);
		} while ( microtime(true) < $deadline );

		return new \WP_Error('remote_workspace_lock_timeout', sprintf('Timed out waiting for remote workspace admission lock for "%s".', $repo_name), array( 'status' => 409 ));
	}

	/** Release only the lease owned by this request. */
	private function release_state_lock( array $lock ): void {
		$current = get_option((string) $lock['key'], array());
		if ( is_array($current) && ( $current['token'] ?? null ) === ( $lock['token'] ?? null ) ) {
			$this->compare_delete_option((string) $lock['key'], $current);
		}
	}

	/** Atomically delete only the exact lease value that was observed. */
	private function compare_delete_option( string $key, array $expected ): bool {
		global $wpdb;
		if ( isset($wpdb) && is_object($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'delete') ) {
			$deleted = $wpdb->delete(
				$wpdb->options,
				array( 'option_name' => $key, 'option_value' => maybe_serialize($expected) ),
				array( '%s', '%s' )
			);
			if ( false !== $deleted && function_exists('wp_cache_delete') ) {
				wp_cache_delete($key, 'options');
			}
			return 1 === $deleted;
		}

		$current = get_option($key, array());
		return $current === $expected && delete_option($key);
	}

	/**
	 * Remove a registered remote worktree branch from local remote-workspace state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_remove( string $repo_name, string $branch ): array|\WP_Error {
		$repo_name = $this->resolve_alias($repo_name);
		$branch    = trim($branch);
		if ( '' === $repo_name || '' === $branch ) {
			return new \WP_Error('remote_workspace_worktree_remove_missing_args', 'Repository and branch are required.', array( 'status' => 400 ));
		}

		$handle = $repo_name . '@' . $this->branch_slug($branch);
		$state  = $this->state();
		if ( ! isset($state['worktrees'][ $handle ]) ) {
			$stored_handle = $this->find_worktree_handle_by_repo_branch($state, $repo_name, $branch);
			if ( null === $stored_handle ) {
				return new \WP_Error('remote_workspace_worktree_not_found', sprintf('Remote workspace worktree "%s" is not registered.', $handle), array( 'status' => 404 ));
			}
			$handle = $stored_handle;
		}

		unset($state['worktrees'][ $handle ]);
		$this->save_state($state);

		return array(
			'success' => true,
			'backend' => 'github_api',
			'handle'  => $handle,
			'message' => sprintf('Remote workspace worktree "%s" removed from runtime state.', $handle),
		);
	}

	/**
	 * Find the stored worktree handle for a repo/branch pair.
	 *
	 * Remote worktree handles can outlive branch changes when an existing
	 * worktree is reused for a fresh branch. Remove by the current branch should
	 * still clear that registered row instead of requiring operators to know the
	 * stale handle slug.
	 *
	 * @param  array<string,mixed> $state     Remote workspace state.
	 * @param  string              $repo_name Workspace repo name.
	 * @param  string              $branch    Current branch name.
	 * @return string|null Stored handle, if exactly matched.
	 */
	private function find_worktree_handle_by_repo_branch( array $state, string $repo_name, string $branch ): ?string {
		foreach ( (array) ( $state['worktrees'] ?? array() ) as $stored_handle => $worktree ) {
			if ( ! is_array($worktree) ) {
				continue;
			}
			if ( (string) ( $worktree['repo_name'] ?? '' ) !== $repo_name ) {
				continue;
			}
			if ( (string) ( $worktree['branch'] ?? '' ) !== $branch ) {
				continue;
			}

			return (string) $stored_handle;
		}

		return null;
	}

	/**
	 * Prune remote worktree state whose primary repo registration disappeared.
	 *
	 * @return array<string,mixed>
	 */
	public function worktree_prune(): array {
		$state  = $this->state();
		$pruned = array();
		foreach ( $state['worktrees'] as $handle => $worktree ) {
			$repo_name = is_array($worktree) ? (string) ( $worktree['repo_name'] ?? '' ) : '';
			if ( '' !== $repo_name && isset($state['repos'][ $repo_name ]) ) {
				continue;
			}

			unset($state['worktrees'][ $handle ]);
			$pruned[] = (string) $handle;
		}

		if ( array() !== $pruned ) {
			$this->save_state($state);
		}

		return array(
			'success' => true,
			'backend' => 'github_api',
			'pruned'  => $pruned,
		);
	}

	/**
	 * Read a file from GitHub or pending remote workspace state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read_file( string $handle, string $path, int $max_size, ?int $offset = null, ?int $limit = null ): array|\WP_Error {
		$policy_error = WorkspaceAliasResolver::read_error_if_disallowed($handle, $path);
		if ( null !== $policy_error ) {
			return $policy_error;
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$path = $this->normalize_path($path);
		if ( is_wp_error($path) ) {
			return $path;
		}

		$content = $context['pending_files'][ $path ] ?? null;
		if ( null === $content ) {
			$file = $this->get_file_contents_with_fallback($context, $path);
			if ( is_wp_error($file) ) {
				return $file;
			}
			if ( empty($file['files'][0]) ) {
				$error = $file['errors'][0]['message'] ?? sprintf('File not found: %s.', $path);
				return new \WP_Error('remote_workspace_file_unavailable', $error, array( 'status' => 404 ));
			}
			$content = (string) ( $file['files'][0]['content'] ?? '' );
		}

		$size = strlen($content);
		if ( $size > $max_size ) {
			return new \WP_Error('file_too_large', sprintf('File too large: %s.', $path), array( 'status' => 400 ));
		}

		$result_content = $content;
		if ( null !== $offset || null !== $limit ) {
			$start_line     = max(1, (int) ( $offset ?? 1 ));
			$lines          = explode("\n", $content);
			$lines          = array_slice($lines, $start_line - 1, null === $limit ? null : max(0, $limit));
			$result_content = implode("\n", $lines);
		}

		$result = array(
			'success' => true,
			'backend' => 'github_api',
			'content' => $result_content,
			'path'    => $path,
			'size'    => $size,
		);
		return $this->with_context_policy($handle, $result);
	}

	/**
	 * List repository files under a path prefix.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function list_directory( string $handle, ?string $path = null ): array|\WP_Error {
		$policy_error = WorkspaceAliasResolver::read_error_if_disallowed($handle, $path ?? '');
		if ( null !== $policy_error ) {
			return $policy_error;
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$prefix = null === $path ? '' : trim(ltrim($path, '/'), '/');
		$tree   = $this->get_repo_tree_with_fallback($context);
		if ( is_wp_error($tree) ) {
			return $tree;
		}

		$entries = array();
		foreach ( (array) ( $tree['files'] ?? array() ) as $file ) {
			$file_path = (string) ( $file['path'] ?? '' );
			if ( '' !== $prefix && ! str_starts_with($file_path, $prefix . '/') ) {
				continue;
			}

			$relative = '' === $prefix ? $file_path : substr($file_path, strlen($prefix) + 1);
			if ( '' === $relative || str_contains($relative, '/') ) {
				continue;
			}

			$entries[] = array(
				'name' => $relative,
				'type' => (string) ( $file['type'] ?? 'file' ),
				'size' => (int) ( $file['size'] ?? 0 ),
			);
		}

		$entries = WorkspaceAliasResolver::filter_context_entries($handle, '' === $prefix ? '/' : $prefix, $entries);

		$result = array(
			'success' => true,
			'backend' => 'github_api',
			'repo'    => $handle,
			'path'    => '' === $prefix ? '/' : $prefix,
			'entries' => $entries,
		);
		return $this->with_context_policy($handle, $result);
	}

	/**
	 * Search text files through the GitHub-backed workspace backend.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function grep( string $handle, string $pattern, ?string $path = null, ?string $include_pattern = null, int $max_results = 100, int $context_lines = 0 ): array|\WP_Error {
		$policy_error = WorkspaceAliasResolver::read_error_if_disallowed($handle, $path ?? '');
		if ( null !== $policy_error ) {
			return $policy_error;
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$prefix = null === $path ? '' : trim(ltrim($path, '/'), '/');
		if ( str_contains($prefix, '..') ) {
			return new \WP_Error('path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ));
		}

		$regex = WorkspaceText::compile_search_pattern($pattern);
		if ( is_wp_error($regex) ) {
			return $regex;
		}

		$tree = $this->get_repo_tree_with_fallback($context, $prefix);
		if ( is_wp_error($tree) ) {
			return $tree;
		}

		$max_results   = max(1, min(500, $max_results));
		$context_lines = max(0, min(10, $context_lines));
		$matches       = array();
		$seen          = array();
		$files         = (array) ( $tree['files'] ?? array() );

		foreach ( array_keys( (array) $context['pending_files']) as $pending_path ) {
			if ( '' === $prefix || $pending_path === $prefix || str_starts_with($pending_path, $prefix . '/') ) {
				array_unshift(
					$files, array(
						'path' => $pending_path,
						'type' => 'file',
						'size' => strlen( (string) $context['pending_files'][ $pending_path ]),
					)
				);
			}
		}

		foreach ( $files as $file ) {
			$file_path      = (string) ( $file['path'] ?? '' );
			$context_policy = WorkspaceAliasResolver::context_policy_for($handle);
			if ( null !== $context_policy && ! WorkspaceAliasResolver::path_allowed_by_policy($file_path, $context_policy) ) {
				continue;
			}
			if ( '' === $file_path || isset($seen[ $file_path ]) || ! WorkspaceText::path_matches_include($file_path, $include_pattern) ) {
				continue;
			}
			$seen[ $file_path ] = true;

			if ( (int) ( $file['size'] ?? 0 ) > self::MAX_READ_SIZE ) {
				continue;
			}

			$read = $this->read_file($handle, $file_path, self::MAX_READ_SIZE);
			if ( is_wp_error($read) ) {
				continue;
			}

			$content = (string) ( $read['content'] ?? '' );
			if ( false !== strpos(substr($content, 0, 8192), "\0") ) {
				continue;
			}

			$file_matches = WorkspaceText::grep_content($content, $handle, $file_path, $regex, $context_lines, $max_results - count($matches));
			$matches      = array_merge($matches, $file_matches);
			if ( count($matches) >= $max_results ) {
				break;
			}
		}

		$result = array(
			'success'   => true,
			'backend'   => 'github_api',
			'repo'      => $handle,
			'path'      => '' === $prefix ? '/' : $prefix,
			'pattern'   => $pattern,
			'matches'   => $matches,
			'count'     => count($matches),
			'truncated' => count($matches) >= $max_results,
		);
		return $this->with_context_policy($handle, $result);
	}

	/**
	 * Stage file content in the remote workspace.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function write_file( string $handle, string $path, string $content ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'write');
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}
		$path = $this->normalize_path($path);
		if ( is_wp_error($path) ) {
			return $path;
		}

		$policy_check = $this->policy->assert_paths_writable( (string) $context['repo_name'], array( $path ) );
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$state = $this->state();
		$state['worktrees'][ $context['handle'] ]['pending_files'][ $path ] = $content;
		$state['worktrees'][ $context['handle'] ]['changed_files'][ $path ] = $path;
		$this->save_state($state);

		return array(
			'success' => true,
			'backend' => 'github_api',
			'name'    => $context['handle'],
			'path'    => $path,
			'size'    => strlen($content),
			'created' => true,
		);
	}

	/**
	 * Stage a find-and-replace edit in the remote workspace.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function edit_file( string $handle, string $path, string $old_string, string $new_string, bool $replace_all = false ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'edit');
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$normalized_path = $this->normalize_path($path);
		if ( is_wp_error($normalized_path) ) {
			return $normalized_path;
		}

		$policy_check = $this->policy->assert_paths_writable( (string) $context['repo_name'], array( $normalized_path ) );
		if ( is_wp_error($policy_check) ) {
			return $policy_check;
		}

		$current = $this->read_file($handle, $normalized_path, PHP_INT_MAX);
		if ( is_wp_error($current) ) {
			return $current;
		}

		$content = (string) ( $current['content'] ?? '' );
		$count   = substr_count($content, $old_string);
		if ( 0 === $count ) {
			return new \WP_Error(
				'string_not_found', 'old_string not found in file content.', array(
					'status'      => 400,
					'path'        => (string) ( $current['path'] ?? $path ),
					'suggestions' => WorkspaceText::build_edit_suggestions($content, $old_string),
				)
			);
		}
		if ( $count > 1 && ! $replace_all ) {
			return new \WP_Error('multiple_matches', sprintf('Found %d matches for old_string.', $count), array( 'status' => 400 ));
		}

		if ( $replace_all ) {
			$new_content = str_replace($old_string, $new_string, $content);
		} else {
			$offset = strpos($content, $old_string);
			// $count > 0 above guarantees strpos cannot return false here.
			$new_content = false === $offset
			? $content
			: substr_replace($content, $new_string, $offset, strlen($old_string));
		}

		$write = $this->write_file($handle, $normalized_path, $new_content);
		if ( is_wp_error($write) ) {
			return $write;
		}

		return array(
			'success'      => true,
			'backend'      => 'github_api',
			'name'         => $context['handle'],
			'path'         => $write['path'],
			'replacements' => $replace_all ? $count : 1,
		);
	}

	/**
	 * Show remote workspace details.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function show( string $handle ): array|\WP_Error {
		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$files = array_values(array_unique(array_values( (array) $context['changed_files'])));

		$result = array(
			'success'     => true,
			'backend'     => 'github_api',
			'name'        => $handle,
			'repo'        => $context['repo_name'],
			'is_worktree' => empty($context['read_only_context']) && isset($context['branch']) && '' !== (string) $context['branch'],
			'is_context'  => ! empty($context['read_only_context']),
			'path'        => 'github://' . $context['repo'] . ( '' !== (string) $context['branch']
			? '#' . $context['branch']
			: '' ),
			'branch'      => '' !== (string) $context['branch'] ? (string) $context['branch'] : null,
			'remote'      => GitHubRemote::cloneUrl( (string) $context['repo'] ),
			'commit'      => '' !== $context['last_commit_sha'] ? $context['last_commit_sha'] : null,
			'dirty'       => count($files),
			'files'       => $files,
		);
		return $this->with_context_policy($handle, $result);
	}

	/**
	 * Return registered remote state needed to materialize a local checkout.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function materialization_context( string $handle ): array|\WP_Error {
		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}
		if ( ! empty($context['read_only_context']) ) {
			return new \WP_Error('remote_workspace_materialization_unsupported', 'Read-only context repositories cannot be materialized as editable workspaces.', array( 'status' => 400 ));
		}

		$state     = $this->state();
		$repo_name = (string) ( $context['repo_name'] ?? '' );
		$repo      = (string) ( $context['repo'] ?? '' );
		$url       = (string) ( $state['repos'][ $repo_name ]['url'] ?? GitHubRemote::cloneUrl($repo) );

		return array(
			'handle'    => (string) ( $context['handle'] ?? $handle ),
			'repo_name' => $repo_name,
			'repo'      => $repo,
			'url'       => $url,
			'branch'    => (string) ( $context['branch'] ?? '' ),
			'base_ref'  => (string) ( $context['base_ref'] ?? '' ),
			'task'      => (array) ( $context['task'] ?? array() ),
		);
	}

	/**
	 * Return a diff of pending remote workspace changes.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_diff( string $handle, ?string $from = null, ?string $to = null, bool $staged = false, ?string $path = null ): array|\WP_Error {
		unset($staged);
		$policy_error = WorkspaceAliasResolver::read_error_if_disallowed($handle, $path ?? '');
		if ( null !== $policy_error ) {
			return $policy_error;
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		if ( ( null !== $from && '' !== trim($from) ) || ( null !== $to && '' !== trim($to) ) ) {
			return new \WP_Error('remote_workspace_diff_refs_unsupported', 'Remote workspace diff currently supports pending workspace changes only; omit from/to refs.', array( 'status' => 400 ));
		}

		$path_filter = null;
		if ( null !== $path && '' !== trim($path) ) {
			$normalized = $this->normalize_path($path);
			if ( is_wp_error($normalized) ) {
				return $normalized;
			}
			$path_filter = $normalized;
		}

		$diff = '';
		foreach ( (array) $context['pending_files'] as $changed_path => $new_content ) {
			$changed_path = (string) $changed_path;
			if ( null !== $path_filter && $changed_path !== $path_filter ) {
				continue;
			}

			$old_content = '';
			$current     = $this->get_file_contents_with_fallback($context, $changed_path);
			if ( ! is_wp_error($current) && ! empty($current['files'][0]) ) {
				$old_content = (string) ( $current['files'][0]['content'] ?? '' );
			}

			$diff .= $this->build_unified_file_diff($changed_path, $old_content, (string) $new_content);
		}

		$result = array(
			'success' => true,
			'backend' => 'github_api',
			'name'    => $handle,
			'repo'    => $context['repo_name'],
			'diff'    => $diff,
		);
		return $this->with_context_policy($handle, $result);
	}

	/**
	 * Return pending remote workspace changes.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_status( string $handle ): array|\WP_Error {
		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$files  = array_values(array_unique(array_values( (array) $context['changed_files'])));
		$result = array(
			'success'     => true,
			'backend'     => 'github_api',
			'name'        => $handle,
			'repo'        => $context['repo_name'],
			'is_worktree' => empty($context['read_only_context']),
			'is_context'  => ! empty($context['read_only_context']),
			'path'        => 'github://' . $context['repo'] . '#' . $context['branch'],
			'branch'      => $context['branch'],
			'remote'      => GitHubRemote::cloneUrl( (string) $context['repo'] ),
			'commit'      => '' !== $context['last_commit_sha'] ? $context['last_commit_sha'] : null,
			'dirty'       => count($files),
			'files'       => $files,
		);
		return $this->with_context_policy($handle, $result);
	}

	/**
	 * Remote/GitHub workspaces do not expose shell command execution.
	 *
	 * @param  string              $handle          Workspace handle.
	 * @param  string              $command         Shell command requested by caller.
	 * @param  string              $description     Human-readable reason for the command.
	 * @param  int                 $timeout_seconds Timeout in seconds.
	 * @param  array<string,mixed> $env             Environment variables, accepted for API symmetry.
	 * @param  string|null         $cwd             Optional relative cwd, accepted for API symmetry.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run_command( string $handle, string $command, string $description = '', int $timeout_seconds = 300, array $env = array(), ?string $cwd = null ): array|\WP_Error {
		unset($timeout_seconds, $env, $cwd);

		if ( '' === trim($command) ) {
			return new \WP_Error('runner_workspace_command_missing_command', 'command is required.', array( 'status' => 400 ));
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$result = array(
			'success'      => false,
			'kind'         => 'runner_workspace_command',
			'backend'      => 'github_api',
			'failure_type' => 'unavailable',
			'name'         => $handle,
			'repo'         => $context['repo_name'],
			'path'         => 'github://' . $context['repo'] . ( '' !== (string) $context['branch'] ? '#' . $context['branch'] : '' ),
			'command'      => trim($command),
			'description'  => $description,
			'exit_code'    => null,
			'stdout'       => '',
			'stderr'       => '',
			'elapsed_ms'   => 0,
			'timed_out'    => false,
			'workspace'    => array(
				'handle'      => $handle,
				'repo'        => $context['repo_name'],
				'github_repo' => $context['repo'],
				'branch'      => $context['branch'],
				'backend'     => 'github_api',
				'is_context'  => ! empty($context['read_only_context']),
			),
			'message'      => 'Runner workspace command execution is unavailable for GitHub API remote workspaces; use a local runner workspace backend for shell commands.',
		);

		return $this->with_context_policy($handle, $result);
	}

	/**
	 * Compatibility no-op: files are tracked by pending remote workspace state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_add( string $handle, array $paths ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git add');
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		return array(
			'success' => true,
			'backend' => 'github_api',
			'name'    => $handle,
			'paths'   => array_values(array_map('strval', $paths)),
			'message' => 'Remote workspace changes are staged automatically.',
		);
	}

	/**
	 * Commit pending remote workspace changes through one GitHub Git-data commit.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_commit( string $handle, string $message ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git commit');
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}
		if ( '' === trim($message) ) {
			return new \WP_Error('missing_commit_message', 'Commit message is required.', array( 'status' => 400 ));
		}

		$pending = (array) $context['pending_files'];
		if ( empty($pending) ) {
			return new \WP_Error('nothing_to_commit', 'No remote workspace changes to commit.', array( 'status' => 400 ));
		}

		$result = GitHubAbilities::commitFiles(
			array(
				'repo'           => $context['repo'],
				'files'          => $pending,
				'commit_message' => $message,
				'branch'         => $context['branch'],
			)
		);
		if ( is_wp_error($result) ) {
			return $result;
		}

		$commit_sha = (string) ( $result['commit']['sha'] ?? '' );

		$state = $this->state();
		$state['worktrees'][ $context['handle'] ]['pending_files']   = array();
		$state['worktrees'][ $context['handle'] ]['last_commit_sha'] = $commit_sha;
		$this->save_state($state);

		return array(
			'success' => true,
			'backend' => 'github_api',
			'name'    => $handle,
			'branch'  => $context['branch'],
			'commit'  => $commit_sha,
			'message' => sprintf('Committed remote workspace changes to %s.', $context['branch']),
		);
	}

	/**
	 * Read GitHub contents for a worktree path, falling back to the default branch
	 * when the remote worktree branch has not been materialized yet.
	 *
	 * @param  array<string,mixed> $context Remote workspace context.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_file_contents_with_fallback( array $context, string $path ): array|\WP_Error {
		$file_input = array(
			'repo' => $context['repo'],
			'path' => $path,
		);
		if ( '' !== $context['read_ref'] ) {
			$file_input['ref'] = $context['read_ref'];
		}

		$file = GitHubAbilities::getFileContents($file_input);
		if ( '' === $context['read_ref'] || ! $this->should_retry_default_ref($file) ) {
			return $file;
		}

		return GitHubAbilities::getFileContents(
			array(
				'repo' => $context['repo'],
				'path' => $path,
			)
		);
	}

	/**
	 * GitHub file reads can report missing refs either as a WP_Error or as a
	 * normalized `{ success: false, files: [], errors: [...] }` payload.
	 */
	private function should_retry_default_ref( array|\WP_Error $file ): bool {
		if ( is_wp_error($file) ) {
			return 404 === (int) ( $file->get_error_data()['status'] ?? 0 );
		}

		if ( ! empty($file['files'][0]) ) {
			return false;
		}

		foreach ( (array) ( $file['errors'] ?? array() ) as $error ) {
			if ( 404 === (int) ( $error['status'] ?? 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a unified diff for a single file's pending change.
	 *
	 * Uses Myers' algorithm to find a minimal edit script, then groups adjacent
	 * edits into hunks with surrounding context (default 3 lines). Output matches
	 * the format produced by `git diff --no-color`, so consumers that scan the
	 * diff for `-foo` / `+bar` lines see actual changed lines rather than a fake
	 * whole-file replace.
	 *
	 * Previously this method emitted every old line as `-` followed by every new
	 * line as `+`, regardless of how small the actual change was. That misled
	 * agents into thinking surgical edits had rewritten the entire file.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-code/issues/429
	 */
	private function build_unified_file_diff( string $path, string $old_content, string $new_content, int $context_lines = 3 ): string {
		$header  = 'diff --git a/' . $path . ' b/' . $path . "\n";
		$header .= '--- a/' . $path . "\n";
		$header .= '+++ b/' . $path . "\n";

		if ( $old_content === $new_content ) {
			return $header;
		}

		$old_lines = $this->diff_lines($old_content);
		$new_lines = $this->diff_lines($new_content);

		$ops = $this->myers_diff($old_lines, $new_lines);
		if ( empty($ops) ) {
			return $header;
		}

		$hunks = $this->group_diff_hunks($ops, $context_lines);
		if ( empty($hunks) ) {
			return $header;
		}

		$body = '';
		foreach ( $hunks as $hunk ) {
			$body .= sprintf(
				"@@ -%d,%d +%d,%d @@\n",
				$hunk['old_start'],
				$hunk['old_count'],
				$hunk['new_start'],
				$hunk['new_count']
			);
			foreach ( $hunk['lines'] as $line ) {
				$body .= $line . "\n";
			}
		}

		return $header . $body;
	}

	/**
	 * @return array<int,string>
	 */
	private function diff_lines( string $content ): array {
		if ( '' === $content ) {
			return array();
		}

		return explode("\n", rtrim($content, "\n"));
	}

	/**
	 * Myers' diff algorithm producing an edit script over two arrays of lines.
	 *
	 * Returns an ordered list of operations:
	 *   ['op' => '=', 'line' => string]  (unchanged)
	 *   ['op' => '-', 'line' => string]  (removed from old)
	 *   ['op' => '+', 'line' => string]  (added in new)
	 *
	 * Trims common prefix/suffix first so the O(ND) core only runs on the
	 * actually-different middle window — typical "surgical edit in large file"
	 * cases finish in O(N) instead of O(N^2).
	 *
	 * @param  array<int,string> $a Old lines.
	 * @param  array<int,string> $b New lines.
	 * @return array<int,array{op:string,line:string}>
	 */
	private function myers_diff( array $a, array $b ): array {
		$ops = array();

		$prefix = 0;
		$a_len  = count($a);
		$b_len  = count($b);
		$min    = min($a_len, $b_len);
		while ( $prefix < $min && $a[ $prefix ] === $b[ $prefix ] ) {
			$ops[] = array(
				'op'   => '=',
				'line' => $a[ $prefix ],
			);
			++$prefix;
		}

		$suffix     = 0;
		$max_suffix = $min - $prefix;
		while ( $suffix < $max_suffix && $a[ $a_len - 1 - $suffix ] === $b[ $b_len - 1 - $suffix ] ) {
			++$suffix;
		}

		$middle_a = array_slice($a, $prefix, $a_len - $prefix - $suffix);
		$middle_b = array_slice($b, $prefix, $b_len - $prefix - $suffix);

		foreach ( $this->myers_middle_diff($middle_a, $middle_b) as $op ) {
			$ops[] = $op;
		}

		for ( $i = $a_len - $suffix; $i < $a_len; $i++ ) {
			$ops[] = array(
				'op'   => '=',
				'line' => $a[ $i ],
			);
		}

		return $ops;
	}

	/**
	 * Core Myers algorithm over a (presumably small) middle window.
	 *
	 * Implements Eugene Myers' O(ND) algorithm, recording trace V-arrays at each
	 * D-step and walking back to reconstruct the edit script. Falls back to a
	 * simple "remove-all then add-all" emission if the middle is degenerate
	 * (one side empty), which is both faster and produces the same result.
	 *
	 * @param  array<int,string> $a
	 * @param  array<int,string> $b
	 * @return array<int,array{op:string,line:string}>
	 */
	private function myers_middle_diff( array $a, array $b ): array {
		$n = count($a);
		$m = count($b);

		if ( 0 === $n && 0 === $m ) {
			return array();
		}
		if ( 0 === $n ) {
			$ops = array();
			foreach ( $b as $line ) {
				$ops[] = array(
					'op'   => '+',
					'line' => $line,
				);
			}
			return $ops;
		}
		if ( 0 === $m ) {
			$ops = array();
			foreach ( $a as $line ) {
				$ops[] = array(
					'op'   => '-',
					'line' => $line,
				);
			}
			return $ops;
		}

		$max    = $n + $m;
		$offset = $max;
		$trace  = array();
		$v      = array_fill(0, 2 * $max + 1, 0);

		for ( $d = 0; $d <= $max; $d++ ) {
			for ( $k = -$d; $k <= $d; $k += 2 ) {
				if ( -$d === $k || ( $d !== $k && $v[ $k - 1 + $offset ] < $v[ $k + 1 + $offset ] ) ) {
					$x = $v[ $k + 1 + $offset ];
				} else {
					$x = $v[ $k - 1 + $offset ] + 1;
				}
				$y = $x - $k;
				while ( $x < $n && $y < $m && $a[ $x ] === $b[ $y ] ) {
					++$x;
					++$y;
				}
				$v[ $k + $offset ] = $x;
				if ( $x >= $n && $y >= $m ) {
					$trace[] = $v;
					return $this->myers_backtrack($trace, $a, $b, $d, $offset);
				}
			}
			$trace[] = $v;
		}

		return array();
	}

	/**
	 * Walk the recorded Myers trace backwards to build the ordered edit script.
	 *
	 * @param  array<int,array<int,int>> $trace
	 * @param  array<int,string>         $a
	 * @param  array<int,string>         $b
	 * @return array<int,array{op:string,line:string}>
	 */
	private function myers_backtrack( array $trace, array $a, array $b, int $d, int $offset ): array {
		$ops = array();
		$x   = count($a);
		$y   = count($b);

		for ( ; $d > 0; $d-- ) {
			$v = $trace[ $d - 1 ];
			$k = $x - $y;
			if ( -$d === $k || ( $d !== $k && $v[ $k - 1 + $offset ] < $v[ $k + 1 + $offset ] ) ) {
				$prev_k = $k + 1;
			} else {
				$prev_k = $k - 1;
			}
			$prev_x = $v[ $prev_k + $offset ];
			$prev_y = $prev_x - $prev_k;

			while ( $x > $prev_x && $y > $prev_y ) {
				$ops[] = array(
					'op'   => '=',
					'line' => $a[ $x - 1 ],
				);
				--$x;
				--$y;
			}
			if ( $x === $prev_x ) {
				$ops[] = array(
					'op'   => '+',
					'line' => $b[ $y - 1 ],
				);
			} else {
				$ops[] = array(
					'op'   => '-',
					'line' => $a[ $x - 1 ],
				);
			}
			$x = $prev_x;
			$y = $prev_y;
		}
		while ( $x > 0 && $y > 0 ) {
			$ops[] = array(
				'op'   => '=',
				'line' => $a[ $x - 1 ],
			);
			--$x;
			--$y;
		}
		while ( $x > 0 ) {
			$ops[] = array(
				'op'   => '-',
				'line' => $a[ $x - 1 ],
			);
			--$x;
		}
		while ( $y > 0 ) {
			$ops[] = array(
				'op'   => '+',
				'line' => $b[ $y - 1 ],
			);
			--$y;
		}

		return array_reverse($ops);
	}

	/**
	 * Group consecutive non-context edit operations into unified-diff hunks.
	 *
	 * Each hunk has up to `$context_lines` of unchanged context on each side.
	 * Returns one entry per hunk with `old_start`, `old_count`, `new_start`,
	 * `new_count`, and a list of `+line` / `-line` / ` line` strings ready to
	 * emit between `@@` markers.
	 *
	 * @param  array<int,array{op:string,line:string}> $ops
	 * @return array<int,array{old_start:int,old_count:int,new_start:int,new_count:int,lines:array<int,string>}>
	 */
	private function group_diff_hunks( array $ops, int $context_lines ): array {
		$hunks = array();
		$count = count($ops);

		$old_line = 1;
		$new_line = 1;
		$i        = 0;

		while ( $i < $count ) {
			if ( '=' === $ops[ $i ]['op'] ) {
				++$old_line;
				++$new_line;
				++$i;
				continue;
			}

			$context_before = min($context_lines, $i);
			$hunk_start_i   = $i - $context_before;
			$hunk_old_start = $old_line - $context_before;
			$hunk_new_start = $new_line - $context_before;

			$lines     = array();
			$old_count = 0;
			$new_count = 0;
			for ( $j = $hunk_start_i; $j < $i; $j++ ) {
				$lines[] = ' ' . $ops[ $j ]['line'];
				++$old_count;
				++$new_count;
			}

			$tail_eq = 0;
			while ( $i < $count ) {
				$op = $ops[ $i ]['op'];
				if ( '=' === $op ) {
					++$tail_eq;
					if ( $tail_eq > 2 * $context_lines ) {
						--$tail_eq;
						break;
					}
					$lines[] = ' ' . $ops[ $i ]['line'];
					++$old_count;
					++$new_count;
					++$old_line;
					++$new_line;
					++$i;
					continue;
				}
				$tail_eq = 0;
				if ( '-' === $op ) {
					$lines[] = '-' . $ops[ $i ]['line'];
					++$old_count;
					++$old_line;
				} else {
					$lines[] = '+' . $ops[ $i ]['line'];
					++$new_count;
					++$new_line;
				}
				++$i;
			}

			$keep_tail = min($context_lines, $tail_eq);
			$drop_tail = $tail_eq - $keep_tail;
			if ( $drop_tail > 0 ) {
				$lines      = array_slice($lines, 0, count($lines) - $drop_tail);
				$old_count -= $drop_tail;
				$new_count -= $drop_tail;
				$old_line  -= $drop_tail;
				$new_line  -= $drop_tail;
			}

			$hunks[] = array(
				'old_start' => 0 === $old_count ? max(0, $hunk_old_start - 1) : $hunk_old_start,
				'old_count' => $old_count,
				'new_start' => 0 === $new_count ? max(0, $hunk_new_start - 1) : $hunk_new_start,
				'new_count' => $new_count,
				'lines'     => $lines,
			);
		}

		return $hunks;
	}

	/**
	 * Compatibility no-op: commit already wrote to the remote branch.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_push( string $handle, string $remote = 'origin', ?string $branch = null ): array|\WP_Error {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			return WorkspaceAliasResolver::mutation_error($handle, 'git push');
		}

		$context = $this->resolve_handle($handle);
		if ( is_wp_error($context) ) {
			return $context;
		}

		$push_branch = null !== $branch && '' !== $branch ? $branch : $context['branch'];
		$branch_url  = '' !== $push_branch ? GitHubRemote::branchUrl( (string) $context['repo'], (string) $push_branch ) : null;

		return array(
			'success'        => true,
			'kind'           => 'branch_push',
			'backend'        => 'github_api',
			'name'           => $handle,
			'repo'           => $context['repo'],
			'workspace_repo' => $context['repo_name'] ?? $handle,
			'github_repo'    => $context['repo'],
			'remote'         => $remote,
			'branch'         => $push_branch,
			'url'            => $branch_url,
			'html_url'       => $branch_url,
			'message'        => 'Remote workspace branch already updated via GitHub API.',
		);
	}

	/** Read a repository tree at the requested ref, falling back to its default ref. */
	private function get_repo_tree_with_fallback( array $context, string $path = '' ): array|\WP_Error {
		$input = array(
			'repo' => $context['repo'],
			'ref'  => $context['read_ref'],
		);
		if ( '' !== $path ) {
			$input['path'] = $path;
		}

		$tree = GitHubAbilities::getRepoTree($input);
		if ( is_wp_error($tree) && '' !== (string) $context['read_ref'] ) {
			unset($input['ref']);
			$tree = GitHubAbilities::getRepoTree($input);
		}

		return $tree;
	}

	/** Attach the read-only context policy attestation when the handle requires one. */
	private function with_context_policy( string $handle, array $result ): array {
		if ( WorkspaceAliasResolver::is_context_repository($handle) ) {
			$result['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($handle);
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolve_handle( string $handle ): array|\WP_Error {
		$context_policy = WorkspaceAliasResolver::context_policy_for($handle);
		if ( null !== $context_policy ) {
			$repo = (string) ( $context_policy['repo'] ?? '' );
			if ( '' === $repo ) {
				$repo = (string) ( $context_policy['target'] ?? $handle );
			}

			return array(
				'handle'            => (string) $context_policy['alias'],
				'repo_name'         => (string) $context_policy['alias'],
				'repo'              => $repo,
				'branch'            => (string) ( $context_policy['ref'] ?? '' ),
				'read_ref'          => (string) ( $context_policy['ref'] ?? '' ),
				'pending_files'     => array(),
				'changed_files'     => array(),
				'last_commit_sha'   => '',
				'read_only_context' => true,
			);
		}

		$handle = $this->resolve_alias($handle);
		$state  = $this->state();
		if ( isset($state['worktrees'][ $handle ]) ) {
			$worktree             = (array) $state['worktrees'][ $handle ];
			$worktree['handle']   = $handle;
			$worktree['read_ref'] = (string) ( $worktree['branch'] ?? '' );
			return $worktree;
		}

		$repo = $this->resolve_repo($handle);
		if ( is_wp_error($repo) ) {
			return $repo;
		}

		return array(
			'handle'          => $handle,
			'repo_name'       => $handle,
			'repo'            => $repo,
			'branch'          => '',
			'read_ref'        => '',
			'pending_files'   => array(),
			'changed_files'   => array(),
			'last_commit_sha' => '',
		);
	}

	private function resolve_repo( string $repo_name ): string|\WP_Error {
		$repo_name = $this->resolve_alias($repo_name);
		$state     = $this->state();
		if ( isset($state['repos'][ $repo_name ]['repo']) ) {
			return (string) $state['repos'][ $repo_name ]['repo'];
		}

		if ( $this->looks_like_url_or_path($repo_name) ) {
			return new \WP_Error('unsupported_remote_workspace_repo_argument', sprintf('Remote workspace worktree add requires a registered workspace name or owner/repo slug, not URL/path argument "%s".', $repo_name), array( 'status' => 400 ));
		}

		if ( preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo_name) ) {
			return $repo_name;
		}

		return new \WP_Error('remote_workspace_repo_not_found', sprintf('Remote workspace repository "%s" is not registered. Call workspace_clone first.', $repo_name), array( 'status' => 404 ));
	}

	private function looks_like_url_or_path( string $value ): bool {
		$value = trim($value);
		return str_starts_with($value, '/')
			|| str_starts_with($value, './')
			|| str_starts_with($value, '../')
			|| str_starts_with($value, '~/')
			|| (bool) preg_match('#^(?:https?|ssh|git)://#i', $value)
			|| (bool) preg_match('/^[^@\s]+@[^:\s]+:.+$/', $value);
	}

	private function resolve_alias( string $handle ): string {
		if ( class_exists(WorkspaceAliasResolver::class) ) {
			return WorkspaceAliasResolver::resolve($handle);
		}

		return $handle;
	}

	private function repo_from_url( string $url ): string|\WP_Error {
		$repo = GitHubRemote::slug($url);
		if ( null !== $repo ) {
			return $repo;
		}

		return new \WP_Error('unsupported_remote_workspace_url', 'Remote workspace backend currently supports GitHub repository URLs only.', array( 'status' => 400 ));
	}

	private function normalize_path( string $path ): string|\WP_Error {
		$path = trim(ltrim($path, '/'));
		if ( '' === $path ) {
			return new \WP_Error('missing_path', 'File path is required.', array( 'status' => 400 ));
		}
		foreach ( explode('/', $path) as $part ) {
			if ( '.' === $part || '..' === $part || '' === $part ) {
				return new \WP_Error('path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ));
			}
		}
		return $path;
	}

	private function sanitize_name( string $name ): string {
		return trim(strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name)), '-');
	}

	private function branch_slug( string $branch ): string {
		return trim(strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $branch)), '-');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
		if ( ! is_array($state) ) {
			$state = array();
		}
		$state['repos']      = is_array($state['repos'] ?? null) ? $state['repos'] : array();
		$state['repo_names'] = is_array($state['repo_names'] ?? null) ? $state['repo_names'] : array();
		$state['worktrees']  = is_array($state['worktrees'] ?? null) ? $state['worktrees'] : array();
		return $state;
	}

	/**
	 * @param array<string,mixed> $state State to persist.
	 */
	private function save_state( array $state ): bool {
		return ! function_exists('update_option') || update_option(self::OPTION, $state, false);
	}
}
