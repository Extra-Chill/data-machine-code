<?php
/**
 * Workspace worktree lifecycle operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitRunner;

defined('ABSPATH') || exit;

trait WorkspaceWorktreeLifecycle {



	/**
	 * Create a git worktree for a branch.
	 *
	 * Layout: `<workspace>/<repo>@<branch-slug>` is added as a worktree of
	 * `<workspace>/<repo>` checked out to `<branch>`. If the branch does not
	 * exist locally, it is created from `<from>` (default `origin/HEAD`).
	 *
	 * When `$inject_context` is true (default) and Data Machine's agent memory
	 * layer is available, the originating site's AGENTS.md is made visible to
	 * OpenCode: symlinked into the worktree root when no repo-owned AGENTS.md
	 * exists, otherwise added via local OpenCode instructions so both files load.
	 * MEMORY.md / USER.md / RULES.md are snapshotted into
	 * `.claude/CLAUDE.local.md`. Injected paths are added to the worktree's
	 * per-checkout `info/exclude`. When the memory layer is absent the worktree
	 * is still created successfully; injection silently
	 * skips.
	 *
	 * When `$bootstrap` is true (default), a bootstrap pass runs after the
	 * worktree is created: `git submodule update --init --recursive` if
	 * `.gitmodules` is present, package-manager installs for root or one-level
	 * nested dependency roots with lockfiles (pnpm/bun/yarn/npm; submodule roots
	 * are excluded unless `.datamachine/worktree-bootstrap.json` opts them in), and
	 * `composer install` for root or one-level nested dependency roots with
	 * `composer.lock`. Steps are independent and each one is skipped gracefully
	 * when its tool is unavailable. A failing step is surfaced in the result
	 * but does not roll back the worktree — the checkout exists either way.
	 * Pass `$bootstrap = false` (or `--no-bootstrap` on the CLI) for a bare
	 * checkout when you only need to read code on that branch.
	 *
	 * When remote freshness cannot be verified, worktree creation is refused
	 * unless `$allow_unverified_freshness` is set. This keeps default operation
	 * fail-closed while preserving intentional offline workflows.
	 *
	 * When the branch/base is behind the remote default branch, worktree
	 * creation is refused unless `$allow_stale` is set. This check is
	 * zero-tolerance: any default-branch commits missing from the requested
	 * branch/base mean the worktree would start stale.
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
	 * @param  string      $repo           Primary repo name (no @-suffix).
	 * @param  string      $branch         Branch to check out (e.g. "fix/foo-bar").
	 * @param  string|null $from           Base ref when creating the branch.
	 * @param  bool        $inject_context Whether to inject site-agent context (default true).
	 * @param  bool        $bootstrap      Whether to run submodule/package/composer install after creation (default true).
	 * @param  bool        $allow_stale    Bypass the staleness gate (default false).
	 * @param  bool        $rebase_base    Rebase onto upstream after creation (default false).
	 * @param  bool        $force          Bypass the disk-budget refusal threshold (default false).
	 * @param  array       $task           Optional task metadata recorded on the worktree.
	 * @param  bool        $allow_unverified_freshness Bypass fetch-failure freshness verification (default false).
	 * @return array{success: bool, handle: string, path: string, branch: string, slug: string, created_branch: bool, message: string, disk_budget?: array, context_injected?: bool, context_files?: string[], context_skip_reason?: string, bootstrap?: array, fetch_failed?: bool, fetch_error?: string, stale_commits_behind?: int, upstream?: string, base_stale_commits_behind?: int, base_upstream?: string, default_branch_commits_behind?: int, default_branch_ref?: string, gate_threshold?: int, rebase_attempted?: bool, rebase_succeeded?: bool, rebase_error?: string, rebase_target?: string}|\WP_Error
	 */
	public function worktree_add( string $repo, string $branch, ?string $from = null, bool $inject_context = true, bool $bootstrap = true, bool $allow_stale = false, bool $rebase_base = false, bool $force = false, array $task = array(), bool $allow_unverified_freshness = false ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		$repo   = $this->resolve_primary_repo_name($repo);
		$branch = trim($branch);
		if ( is_wp_error($repo) ) {
			return $repo;
		}

		if ( '' === $repo ) {
			return new \WP_Error('invalid_repo', 'Repository name is required.', array( 'status' => 400 ));
		}

		if ( '' === $branch ) {
			return new \WP_Error('invalid_branch', 'Branch name is required.', array( 'status' => 400 ));
		}

		$slug = $this->slugify_branch($branch);
		if ( '' === $slug ) {
			return new \WP_Error('invalid_branch', sprintf('Branch "%s" produced an empty slug.', $branch), array( 'status' => 400 ));
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path) || ! is_dir($primary_path . '/.git') ) {
			return new \WP_Error('primary_not_found', sprintf('Primary checkout for "%s" does not exist. Clone it first.', $repo), array( 'status' => 404 ));
		}

		$wt_handle = $repo . '@' . $slug;
		$wt_path   = $this->workspace_path . '/' . $wt_handle;

		if ( is_dir($wt_path) ) {
			return new \WP_Error('worktree_exists', sprintf('Worktree handle "%s" already exists.', $wt_handle), array( 'status' => 400 ));
		}

		$disk_budget = WorktreeDiskBudget::inspect($this->workspace_path, WorktreeDiskBudget::thresholds($repo, $branch), $force);
		if ( 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			$recommendations = array_map(
				static function ( $row ): string {
					$commands     = array_filter(
						array(
							'preview' => (string) ( $row['preview_command'] ?? $row['command'] ?? '' ),
							'apply'   => (string) ( $row['apply_command'] ?? '' ),
						)
					);
					$command_text = implode(
						'; ',
						array_map(
							static fn( string $label, string $command ): string => sprintf('%s: %s', $label, $command),
							array_keys($commands),
							array_values($commands)
						)
					);

					return sprintf(
						'%d. %s: %s (target reclaim: %s)',
						(int) ( $row['priority'] ?? 0 ),
						(string) ( $row['action'] ?? 'cleanup' ),
						$command_text,
						(string) ( $row['expected_reclaim'] ?? 'unknown' )
					);
				},
				(array) ( $disk_budget['cleanup_recommendations'] ?? array() )
			);
			return new \WP_Error(
				'worktree_disk_budget_exceeded',
				sprintf(
					"Refusing to create worktree before bootstrap/install because the workspace disk budget is unsafe.\n%s\nThreshold: keep at least %.1f GiB free and %.1f%% free; effective floor on this filesystem is %.1f GiB.\nRecommended cleanup, in order:\n%s\nRetry with --force only when a human explicitly accepts the disk-pressure risk.",
					WorktreeDiskBudget::format_summary($disk_budget),
					(float) ( $disk_budget['refuse_free_gib'] ?? 0 ),
					(float) ( $disk_budget['refuse_free_percent'] ?? 0 ),
					(float) ( $disk_budget['effective_refuse_gib'] ?? 0 ),
					implode("\n", array_filter($recommendations))
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
				$task,
				$allow_unverified_freshness
			)
		);

		if ( is_wp_error($response) ) {
			return $response;
		}

		$response['disk_budget'] = $disk_budget;

		if ( $bootstrap ) {
			$response['bootstrap'] = WorktreeBootstrapper::bootstrap($wt_path);
		}

		if ( ! is_dir($wt_path) || ! file_exists($wt_path . '/.git') ) {
			return new \WP_Error(
				'worktree_not_materialized',
				sprintf('Git reported worktree "%s" was added at %s, but the checkout is not accessible after creation.', $wt_handle, $wt_path),
				array(
					'status' => 500,
					'handle' => $wt_handle,
					'path'   => $wt_path,
				)
			);
		}

		$persisted = $this->worktree_inventory()->upsert($this->build_worktree_inventory_row_from_handle($wt_handle));
		if ( ! $persisted ) {
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
			WorktreeContextInjector::forget_metadata($wt_handle);

			return new \WP_Error(
				'worktree_inventory_persist_failed',
				sprintf('Worktree "%s" was created but could not be persisted to the workspace inventory; rolled back the checkout instead of reporting success.', $wt_handle),
				array(
					'status' => 500,
					'handle' => $wt_handle,
					'path'   => $wt_path,
				)
			);
		}

		$this->emit_workspace_changed('worktree_add', $repo, $wt_handle, $wt_path);

		return $response;
	}


	/**
	 * Create a worktree while the primary repo lifecycle lock is held.
	 *
	 * @param  string      $repo           Primary repo name.
	 * @param  string      $branch         Branch to check out.
	 * @param  string|null $from           Base ref when creating the branch.
	 * @param  bool        $inject_context Whether to inject site-agent context.
	 * @param  bool        $allow_stale    Bypass the staleness gate.
	 * @param  bool        $rebase_base    Rebase onto upstream after creation.
	 * @param  string      $slug           Branch slug.
	 * @param  string      $wt_handle      Worktree handle.
	 * @param  string      $wt_path        Worktree path.
	 * @param  string      $primary_path   Primary checkout path.
	 * @param  array       $task           Optional task metadata recorded on the worktree.
	 * @param  bool        $allow_unverified_freshness Bypass fetch-failure freshness verification.
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
		array $task = array(),
		bool $allow_unverified_freshness = false
	): array|\WP_Error {
		if ( is_dir($wt_path) ) {
			return new \WP_Error('worktree_exists', sprintf('Worktree handle "%s" already exists.', $wt_handle), array( 'status' => 400 ));
		}

		// Always fetch first so staleness data (and the default base) reflects the
		// current remote. If fetch fails, default to fail-closed unless the caller
		// explicitly opts into unverified/offline freshness.
		$fetch        = WorktreeStalenessProbe::fetch($primary_path);
		$fetch_failed = ! $fetch['ok'];
		$fetch_error  = $fetch['error'] ?? null;
		if ( $fetch_failed && ! $allow_unverified_freshness ) {
			return new \WP_Error(
				'worktree_freshness_unverified',
				'Refusing to create worktree because remote freshness could not be verified. Retry after connectivity is restored, or pass allow_unverified_freshness=true only when intentionally working offline with stale local refs.',
				array(
					'status'                     => 409,
					'fetch_failed'               => true,
					'fetch_error'                => $fetch_error,
					'allow_unverified_freshness' => false,
				)
			);
		}

		// Does the branch already exist locally?
		$exists_local   = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$created_branch = false;
		$resolved_base  = null;

		if ( $exists_local ) {
			if ( ! $allow_stale && ! $rebase_base && ! $fetch_failed ) {
				$default_guard = $this->assert_ref_current_with_default_branch($primary_path, $branch, $repo, $branch, 'branch');
				if ( is_wp_error($default_guard) ) {
					return $default_guard;
				}
			}
			$cmd = sprintf('worktree add %s %s', escapeshellarg($wt_path), escapeshellarg($branch));
		} else {
			$base          = $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path);
			$resolved_base = $base;
			if ( ! $allow_stale && ! $rebase_base && ! $fetch_failed ) {
				$default_guard = $this->assert_ref_current_with_default_branch($primary_path, $resolved_base, $repo, $branch, 'base');
				if ( is_wp_error($default_guard) ) {
					return $default_guard;
				}
			}
			$cmd            = sprintf('worktree add -b %s %s %s', escapeshellarg($branch), escapeshellarg($wt_path), escapeshellarg($base));
			$created_branch = true;
		}

		$result = $this->run_git($primary_path, $cmd);
		if ( is_wp_error($result) ) {
			return $result;
		}

		$response = array(
			'success'        => true,
			'handle'         => $wt_handle,
			'path'           => $wt_path,
			'branch'         => $branch,
			'slug'           => $slug,
			'created_branch' => $created_branch,
			'message'        => sprintf('Worktree "%s" added at %s (branch %s).', $wt_handle, $wt_path, $branch),
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
				$behind = WorktreeStalenessProbe::behind_count($wt_path, $branch, '@{upstream}');
				if ( is_int($behind) ) {
					$response['stale_commits_behind'] = $behind;
					// Derive a human-readable upstream label. Best-effort; silently
					// skipped when git's plumbing doesn't cooperate.
					$upstream_name = $this->run_git(
						$wt_path,
						sprintf('rev-parse --abbrev-ref --symbolic-full-name %s', escapeshellarg($branch . '@{upstream}'))
					);
					if ( ! is_wp_error($upstream_name) ) {
						$label = trim( (string) ( $upstream_name['output'] ?? '' ));
						if ( '' !== $label ) {
							$response['upstream'] = $label;
						}
					}
				}
				// null → no upstream configured; WP_Error → unexpected failure.
				// Both cases: silently omit staleness fields.
			} elseif ( null !== $resolved_base && ! $this->is_remote_tracking_ref($resolved_base) && 'HEAD' !== $resolved_base ) {
				// New branch cut from a local ref: compare that ref to its origin
				// counterpart so the agent sees when the base itself was stale.
				$base_upstream = 'origin/' . $resolved_base;
				$behind        = WorktreeStalenessProbe::behind_count($primary_path, $resolved_base, $base_upstream);
				if ( is_int($behind) ) {
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
			$rebase_result = $this->try_rebase_worktree($wt_path, $response, $created_branch);
			if ( null !== $rebase_result ) {
				$response = array_merge($response, $rebase_result);
			}
		}

		if ( ! $fetch_failed ) {
			$this->populate_default_branch_behind_count($primary_path, $branch, $response);
		}

		// Staleness gate. Threshold filterable per-site / per-repo. Only fires
		// when fetch succeeded (otherwise behind-counts are unreliable) and
		// rebase didn't already zero out the staleness.
		if ( ! $allow_stale && ! $fetch_failed ) {
			if ( isset($response['default_branch_commits_behind']) && (int) $response['default_branch_commits_behind'] > 0 ) {
				$this->run_git($primary_path, sprintf('worktree remove --force %s', escapeshellarg($wt_path)));

				return $this->worktree_behind_default_branch_error(
					(int) $response['default_branch_commits_behind'],
					(string) ( $response['default_branch_ref'] ?? 'origin/HEAD' ),
					$repo,
					$branch,
					'branch'
				);
			}

			/**
			 * Filters the staleness threshold above which `worktree_add` refuses
			 * to return a stale worktree without explicit `--allow-stale` opt-in.
			 *
			 * @param int    $threshold Default 50 commits behind upstream.
			 * @param string $repo      Repository name.
			 * @param string $branch    Branch being materialized.
			 */
			$threshold                  = (int) apply_filters('datamachine_worktree_stale_threshold', 50, $repo, $branch);
			$response['gate_threshold'] = $threshold;
			$effective_behind           = $this->effective_behind_count($response);

			if ( null !== $effective_behind && $effective_behind > $threshold ) {
				// Tear the worktree down so we don't leak a half-cooked
				// checkout on the user's disk.
				$this->run_git($primary_path, sprintf('worktree remove --force %s', escapeshellarg($wt_path)));

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
					ltrim( (string) ( $response['upstream'] ?? $resolved_base ?? 'main' ), 'origin/')
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
				'base_source' => $created_branch ? ( null !== $from && '' !== trim($from) ? 'requested_ref' : 'default_base' ) : 'existing_local_branch',
				'task_url'    => isset($task['task_url']) ? (string) $task['task_url'] : '',
				'task_ref'    => isset($task['task_ref']) ? (string) $task['task_ref'] : '',
			)
		);
		WorktreeContextInjector::store_lifecycle_metadata($wt_handle, $lifecycle_metadata);
		$response['created_at'] = $lifecycle_metadata['created_at'] ?? null;
		$response['metadata']   = WorktreeContextInjector::get_metadata($wt_handle);

		if ( ! $inject_context ) {
			$response['context_injected']    = false;
			$response['context_skip_reason'] = 'inject_context flag disabled';
		} else {
			$payload = WorktreeContextInjector::build_payload();
			if ( null === $payload ) {
				$response['context_injected']    = false;
				$response['context_skip_reason'] = 'agent memory layer unavailable';
			} else {
				$injection = WorktreeContextInjector::inject($wt_path, $payload);
				if ( is_wp_error($injection) ) {
					$response['context_injected']    = false;
					$response['context_skip_reason'] = 'inject failed: ' . $injection->get_error_message();
				} else {
					WorktreeContextInjector::store_metadata($wt_handle, $payload);
					$response['metadata']         = WorktreeContextInjector::get_metadata($wt_handle);
					$response['context_injected'] = true;
					$response['context_files']    = $injection['written'];
					if ( ! empty($injection['exclude_path']) ) {
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
	 * @param  string      $handle Workspace worktree handle.
	 * @param  string      $state  Lifecycle state.
	 * @param  string|null $pr     Optional PR URL or number.
	 * @return array{success: bool, handle: string, path: string, lifecycle_state: string, metadata: array, message: string}|\WP_Error
	 */
	public function worktree_finalize( string $handle, string $state, ?string $pr = null ): array|\WP_Error {
		$parsed = $this->parse_handle($handle);
		if ( ! $parsed['is_worktree'] ) {
			return new \WP_Error('not_a_worktree', sprintf('Handle "%s" is a primary checkout, not a worktree.', $handle), array( 'status' => 400 ));
		}

		$normalized_state = WorktreeContextInjector::normalize_state($state);
		if ( null === $normalized_state ) {
			return new \WP_Error('invalid_lifecycle_state', sprintf('Invalid lifecycle state "%s". Valid states: %s.', $state, implode(', ', WorktreeContextInjector::VALID_STATES)), array( 'status' => 400 ));
		}

		$wt_path = $this->workspace_path . '/' . $parsed['dir_name'];
		if ( ! is_dir($wt_path) ) {
			return new \WP_Error('worktree_not_found', sprintf('Worktree "%s" does not exist on disk.', $parsed['dir_name']), array( 'status' => 404 ));
		}

		$metadata = WorktreeContextInjector::build_finalizer_metadata($normalized_state, $pr);
		$metadata = array_merge(
			array(
				'handle'       => $parsed['dir_name'],
				'path'         => $wt_path,
				'repo'         => $parsed['repo'],
				// Finalize is itself an explicit liveness signal from the owner.
				'last_seen_at' => gmdate('c'),
			),
			$metadata
		);
		WorktreeContextInjector::store_lifecycle_metadata($parsed['dir_name'], $metadata);

		$stored = WorktreeContextInjector::get_metadata($parsed['dir_name']) ?? array();
		$this->worktree_inventory()->upsert($this->build_worktree_inventory_row_from_handle($parsed['dir_name']));
		return array(
			'success'         => true,
			'handle'          => $parsed['dir_name'],
			'path'            => $wt_path,
			'lifecycle_state' => (string) ( $stored['lifecycle_state'] ?? $normalized_state ),
			'metadata'        => $stored,
			'message'         => sprintf('Worktree "%s" marked %s.', $parsed['dir_name'], (string) ( $stored['lifecycle_state'] ?? $normalized_state )),
		);
	}

	/**
	 * Finalize and remove the local DMC worktree for a merged PR head branch.
	 *
	 * This is the targeted post-merge path used before remote branch deletion.
	 * It only touches workspace worktrees whose primary origin matches the exact
	 * GitHub `owner/repo` slug and whose checked-out branch matches the PR head.
	 *
	 * @param  string      $github_repo GitHub repository slug (`owner/repo`).
	 * @param  string      $branch      Pull request head branch.
	 * @param  string|null $pr_url      Optional pull request URL for lifecycle metadata.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cleanup_merged_pr_worktree( string $github_repo, string $branch, ?string $pr_url = null ): array|\WP_Error {
		$github_repo = trim($github_repo);
		$branch      = trim($branch);

		if ( '' === $github_repo || '' === $branch ) {
			return new \WP_Error('missing_pr_worktree_cleanup_params', 'GitHub repo and branch are required for merged PR worktree cleanup.', array( 'status' => 400 ));
		}

		if ( in_array($branch, array( 'main', 'master', 'trunk', 'develop', 'HEAD' ), true) ) {
			return new \WP_Error('protected_head_branch', sprintf('Refusing to clean up protected branch %s.', $branch), array( 'status' => 409 ));
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

		$matches = array();
		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
			if ( ! empty($wt['is_primary']) || ! empty($wt['external']) ) {
				continue;
			}

			$repo         = (string) ( $wt['repo'] ?? '' );
			$primary_path = '' !== $repo ? $this->get_primary_path($repo) : '';
			if ( '' === $primary_path || ! is_dir($primary_path . '/.git') ) {
				continue;
			}

			if ( $github_repo !== (string) $this->resolve_github_slug($primary_path) ) {
				continue;
			}

			if ( (string) ( $wt['branch'] ?? '' ) !== $branch ) {
				continue;
			}

			$matches[] = $wt;
		}

		if ( empty($matches) ) {
			return array(
				'success' => true,
				'found'   => false,
				'repo'    => $github_repo,
				'branch'  => $branch,
				'message' => sprintf('No DMC worktree found for %s:%s.', $github_repo, $branch),
			);
		}

		if ( count($matches) > 1 ) {
			return new \WP_Error(
				'ambiguous_pr_worktree_cleanup',
				sprintf('Refusing merged PR worktree cleanup because %d worktrees match %s:%s.', count($matches), $github_repo, $branch),
				array(
					'status'  => 409,
					'matches' => array_map(static fn( array $wt ): string => (string) ( $wt['handle'] ?? '' ), $matches),
				)
			);
		}

		$wt      = $matches[0];
		$repo    = (string) ( $wt['repo'] ?? '' );
		$handle  = (string) ( $wt['handle'] ?? '' );
		$wt_path = (string) ( $wt['path'] ?? '' );

		if ( '' === $repo || '' === $handle || '' === $wt_path ) {
			return new \WP_Error('invalid_pr_worktree_match', 'Matched worktree is missing repo, handle, or path metadata.', array( 'status' => 500 ));
		}

		$dirty = $this->probe_worktree_dirty_count($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $dirty instanceof \WP_Error ) {
			return $dirty;
		}
		if ( (int) $dirty > 0 ) {
			return new \WP_Error('dirty_worktree', sprintf('Refusing merged PR cleanup for %s because the worktree has %d dirty file(s).', $handle, (int) $dirty), array( 'status' => 409 ));
		}

		$unpushed = $this->count_unpushed_commits($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $unpushed instanceof \WP_Error ) {
			return $unpushed;
		}
		if ( (int) $unpushed > 0 ) {
			return new \WP_Error('unpushed_commits', sprintf('Refusing merged PR cleanup for %s because it has %d unpushed commit(s).', $handle, (int) $unpushed), array( 'status' => 409 ));
		}

		$finalized = $this->worktree_finalize($handle, WorktreeContextInjector::STATE_MERGED, $pr_url);
		if ( $finalized instanceof \WP_Error ) {
			return $finalized;
		}

		$removed = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			function () use ( $repo, $branch, $wt_path ) {
				$remove = $this->remove_worktree_by_path($repo, $branch, $wt_path, false);
				if ( $remove instanceof \WP_Error ) {
					return $remove;
				}

				$primary_path = $this->get_primary_path($repo);
				$delete       = $this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($branch)));
				if ( $delete instanceof \WP_Error ) {
					$remove['local_branch_deleted'] = false;
					$remove['local_branch_error']   = $delete->get_error_message();
					return $remove;
				}

				$remove['local_branch_deleted'] = true;
				return $remove;
			}
		);

		if ( $removed instanceof \WP_Error ) {
			return $removed;
		}

		$this->worktree_prune();

		return array(
			'success'   => true,
			'found'     => true,
			'repo'      => $github_repo,
			'branch'    => $branch,
			'handle'    => $handle,
			'path'      => $wt_path,
			'finalized' => $finalized,
			'removed'   => $removed,
			'message'   => sprintf('Cleaned up merged PR worktree %s before branch deletion.', $handle),
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
	 * @param  string $handle Workspace handle (`<repo>@<branch-slug>`).
	 * @return array{success: bool, handle: string, path: string, written: string[], exclude_path: ?string, metadata: ?array, message: string}|\WP_Error
	 */
	public function worktree_refresh_context( string $handle ): array|\WP_Error {
		$parsed = $this->parse_handle($handle);
		if ( ! $parsed['is_worktree'] ) {
			return new \WP_Error(
				'not_a_worktree',
				sprintf('Handle "%s" is a primary checkout, not a worktree. Context injection is worktree-only.', $handle),
				array( 'status' => 400 )
			);
		}

		$wt_path = $this->workspace_path . '/' . $parsed['dir_name'];
		if ( ! is_dir($wt_path) ) {
			return new \WP_Error(
				'worktree_not_found',
				sprintf('Worktree "%s" does not exist on disk.', $parsed['dir_name']),
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

		$injection = WorktreeContextInjector::inject($wt_path, $payload);
		if ( is_wp_error($injection) ) {
			return $injection;
		}

		WorktreeContextInjector::store_metadata($parsed['dir_name'], $payload);
		// refresh-context is a deliberate liveness signal: the originating site
		// (and therefore some agent process there) just touched this worktree.
		WorktreeContextInjector::record_heartbeat($parsed['dir_name']);
		$this->worktree_inventory()->upsert($this->build_worktree_inventory_row_from_handle($parsed['dir_name']));

		return array(
			'success'      => true,
			'handle'       => $parsed['dir_name'],
			'path'         => $wt_path,
			'written'      => $injection['written'],
			'exclude_path' => $injection['exclude_path'] ?? null,
			'metadata'     => WorktreeContextInjector::get_metadata($parsed['dir_name']),
			'message'      => sprintf('Refreshed injected context in "%s" (%d file%s).', $parsed['dir_name'], count($injection['written']), 1 === count($injection['written']) ? '' : 's'),
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
	 * @param  string|null $repo  Optional repo filter (only this primary's worktrees).
	 * @param  string|null $state Optional lifecycle state filter.
	 * @param  array       $opts  {
	 * @type   bool $include_status Whether to run `git status --porcelain` per worktree. Default true.
	 * @type   bool $include_disk   Whether to run size/artifact `du` probes per worktree. Default true.
	 * }
	 * @return array{success: bool, worktrees: array, fields_skipped: array<int,string>}|\WP_Error
	 */
	public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error {
		$include_status = array_key_exists('include_status', $opts) ? (bool) $opts['include_status'] : true;
		$include_disk   = array_key_exists('include_disk', $opts) ? (bool) $opts['include_disk'] : true;
		$target_handle  = isset($opts['handle']) ? trim( (string) $opts['handle']) : '';
		if ( '' !== $target_handle ) {
			// Inventory/listing handles name the on-disk worktree, not its Git branch.
			$parsed_handle = $this->parse_handle($target_handle);
			$target_handle = $parsed_handle['dir_name'];
			if ( null === $repo ) {
				$repo = $parsed_handle['repo'];
			}
		}

		$skipped_groups = array();
		if ( ! $include_status ) {
			$skipped_groups[] = 'status';
		}
		if ( ! $include_disk ) {
			$skipped_groups[] = 'disk';
		}

		if ( null !== $state && '' !== trim($state) ) {
			$state = WorktreeContextInjector::normalize_state( (string) $state);
			if ( null === $state ) {
				return new \WP_Error('invalid_lifecycle_state', sprintf('Invalid lifecycle state. Valid states: %s.', implode(', ', WorktreeContextInjector::VALID_STATES)), array( 'status' => 400 ));
			}
		} else {
			$state = null;
		}
		if ( ! is_dir($this->workspace_path) ) {
			return array(
				'success'        => true,
				'worktrees'      => array(),
				'fields_skipped' => $skipped_groups,
			);
		}

		$primaries = array();
		$entries   = scandir($this->workspace_path);
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_contains($entry, '@') ) {
				continue;
			}
			$entry_path = $this->workspace_path . '/' . $entry;
			if ( ! file_exists($entry_path . '/.git') ) {
				continue;
			}
			$primaries[] = $entry;
		}

		if ( null !== $repo ) {
			$repo      = $this->sanitize_name($repo);
			$primaries = array_values(array_filter($primaries, fn( $p ) => $p === $repo));
		}
		if ( '' !== $target_handle && is_file($this->workspace_path . '/' . $target_handle . '/.git') ) {
			// A retained worktree can be the only local Git entry point after its
			// original primary was removed or moved. Use it to resolve its own
			// canonical directory handle rather than requiring a primary directory.
			$primaries = array( $target_handle );
		}

		$worktrees = array();

		foreach ( $primaries as $primary ) {
			$primary_path      = $this->workspace_path . '/' . $primary;
			$primary_repo      = $this->parse_handle($primary)['repo'];
			$scanning_worktree = str_contains($primary, '@');
			$result            = $this->run_git($primary_path, 'worktree list --porcelain');
			if ( is_wp_error($result) ) {
				continue;
			}

			$blocks = preg_split("/\n\n+/", trim( (string) ( $result['output'] ?? '' )));
			foreach ( $blocks as $block ) {
				$wt = $this->parse_worktree_block($block);
				if ( null === $wt ) {
					continue;
				}

				$is_primary    = ! $scanning_worktree && ( $wt['path'] === $primary_path );
				$workspace_pfx = $this->workspace_path . '/';
				$inside_ws     = str_starts_with($wt['path'], $workspace_pfx);
				$relative      = $inside_ws ? substr($wt['path'], strlen($workspace_pfx)) : '';
				$parsed        = $inside_ws ? $this->parse_handle($relative) : array( 'branch_slug' => null );

				if ( $is_primary ) {
					$handle = $primary;
				} elseif ( $inside_ws ) {
					$handle = $relative;
				} else {
					// External worktree (created via raw `git worktree add` outside the workspace).
					// Show the absolute path so it is still useful, even though it has no `<repo>@<slug>` handle.
					$handle = $wt['path'];
				}
				if ( '' !== $target_handle && $handle !== $target_handle ) {
					continue;
				}

				if ( $include_status ) {
					$dirty_result     = $this->run_git($wt['path'], 'status --porcelain');
					$dirty_files      = is_wp_error($dirty_result)
					? 0
					: count(array_filter(array_map('trim', explode("\n", $dirty_result['output'] ?? ''))));
					$unpushed_commits = $this->count_unpushed_commits($wt['path']);
					if ( is_wp_error($unpushed_commits) ) {
						return $unpushed_commits;
					}
				} else {
					$dirty_files      = null;
					$unpushed_commits = null;
				}

				$metadata_key = null;
				if ( ! $is_primary && $inside_ws ) {
					$metadata_key = $relative;
				} elseif ( ! $is_primary && ! $inside_ws ) {
					$metadata_key = 'external:' . sha1($wt['path']);
				}
				$metadata        = null !== $metadata_key ? WorktreeContextInjector::get_metadata($metadata_key) : null;
				$created_at      = is_array($metadata) ? ( $metadata['created_at'] ?? null ) : null;
				$lifecycle_state = is_array($metadata) ? ( $metadata['lifecycle_state'] ?? null ) : null;
				if ( null !== $state && $lifecycle_state !== $state ) {
					continue;
				}

				if ( $include_disk ) {
					$disk = $this->build_worktree_disk_report($primary_repo, $wt['path'], ! $is_primary, $created_at, $metadata);
				} else {
					$disk = array(
						'size_bytes'           => null,
						'estimated_size_bytes' => null,
						'last_touched_at'      => null,
						'age_days'             => $this->calculate_age_days($created_at),
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

				$liveness     = WorktreeContextInjector::classify_liveness(is_array($metadata) ? $metadata : null);
				$owner        = WorktreeContextInjector::summarize_owner(is_array($metadata) ? $metadata : null);
				$session_view = WorktreeContextInjector::summarize_session(is_array($metadata) ? $metadata : null);
				$task_view    = is_array($metadata) && is_array($metadata['origin_task'] ?? null) ? $metadata['origin_task'] : null;

				$row = array_merge(
					array(
						'handle'                => $handle,
						'repo'                  => $primary_repo,
						'is_worktree'           => ! $is_primary,
						'is_primary'            => $is_primary,
						'external'              => ! $is_primary && ! $inside_ws,
						'branch_slug'           => $is_primary ? null : ( $parsed['branch_slug'] ?? null ),
						'branch'                => $wt['branch'],
						'head'                  => $wt['head'],
						'path'                  => $wt['path'],
						'dirty'                 => $dirty_files,
						'unpushed'              => $unpushed_commits,
						'created_at'            => $created_at,
						'lifecycle_state'       => $lifecycle_state,
						'pr_url'                => is_array($metadata) ? ( $metadata['pr_url'] ?? null ) : null,
						'pr_number'             => is_array($metadata) ? ( $metadata['pr_number'] ?? null ) : null,
						'last_seen_at'          => is_array($metadata) ? ( $metadata['last_seen_at'] ?? null ) : null,
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

				if ( $is_primary ) {
					$row['primary_freshness'] = $this->build_primary_freshness_report($wt['path'], $handle);
				}

				$base_branch_warning = $this->base_branch_worktree_warning($row);
				if ( null !== $base_branch_warning ) {
						$row['base_branch_warning'] = $base_branch_warning;
				}

				if ( ! empty($skipped_groups) ) {
					$row['fields_skipped'] = $skipped_groups;
				}

				$worktrees[] = $row;
			}
		}

		$duplicates            = WorktreeContextInjector::find_duplicate_task_ownership($worktrees);
		$base_branch_worktrees = array_values(
			array_filter(
				array_map(
					fn( $row ) => $row['base_branch_warning'] ?? null,
					$worktrees
				)
			)
		);

		return array(
			'success'               => true,
			'worktrees'             => $worktrees,
			'duplicates'            => $duplicates,
			'base_branch_worktrees' => $base_branch_worktrees,
			'fields_skipped'        => $skipped_groups,
		);
	}

	/**
	 * Return warning metadata when a non-primary worktree holds a base branch.
	 *
	 * GitHub CLI merge flows may try to check out or delete the PR base branch
	 * during local cleanup. If another worktree has that branch checked out, the
	 * remote merge can succeed while local cleanup reports a fatal git error.
	 *
	 * @param  array<string,mixed> $row Worktree listing row.
	 * @return array<string,string>|null
	 */
	private function base_branch_worktree_warning( array $row ): ?array {
		if ( empty($row['is_worktree']) || ! empty($row['is_primary']) || ! empty($row['external']) ) {
			return null;
		}

		$branch = (string) ( $row['branch'] ?? '' );
		if ( '' === $branch || ! in_array($branch, $this->protected_base_branch_names(), true) ) {
			return null;
		}

		return array(
			'handle'      => (string) ( $row['handle'] ?? '' ),
			'repo'        => (string) ( $row['repo'] ?? '' ),
			'branch'      => $branch,
			'path'        => (string) ( $row['path'] ?? '' ),
			'reason_code' => 'base_branch_checked_out_in_worktree',
			'message'     => sprintf('Worktree %s has base branch %s checked out; gh pr merge --delete-branch may merge remotely but fail local cleanup.', (string) ( $row['handle'] ?? '' ), $branch),
		);
	}

	/**
	 * Branch names that should normally be held by primaries, not feature worktrees.
	 *
	 * @return array<int,string>
	 */
	private function protected_base_branch_names(): array {
		return array( 'main', 'master', 'trunk', 'develop' );
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
			if ( '' === $handle || ! empty($row['external']) ) {
				continue;
			}

			$current_handles[ $handle ] = true;
			if ( $repository->upsert($row) ) {
				$upserted[] = $handle;
			}
		}

		foreach ( $repository->list() as $stored ) {
			$handle = (string) ( $stored['handle'] ?? '' );
			if ( '' === $handle || isset($current_handles[ $handle ]) ) {
				continue;
			}

			if ( $repository->mark_missing($handle) ) {
				$marked_missing[] = $handle;
			}
		}

		return array(
			'success'        => true,
			'refreshed_at'   => gmdate('c'),
			'upserted'       => $upserted,
			'marked_missing' => $marked_missing,
			'summary'        => array(
				'upserted'       => count($upserted),
				'marked_missing' => count($marked_missing),
			),
		);
	}

	/**
	 * Prune DB-backed inventory rows flagged missing_path whose path is still absent.
	 *
	 * Re-probes each candidate on disk, protects rows with unpushed work or an
	 * open PR unless forced, and deletes the confirmed-absent survivors.
	 *
	 * @param  array{dry_run?: bool, force?: bool} $opts Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_inventory_prune_missing( array $opts = array() ): array|\WP_Error {
		return $this->worktree_inventory()->pruneMissing($opts);
	}

	/**
	 * Build a single inventory row for a known workspace handle.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array<string,mixed>
	 */
	private function build_worktree_inventory_row_from_handle( string $handle ): array {
		$parsed   = $this->parse_handle($handle);
		$path     = $this->workspace_path . '/' . $parsed['dir_name'];
		$metadata = $parsed['is_worktree'] ? WorktreeContextInjector::get_metadata($parsed['dir_name']) : null;
		$metadata = is_array($metadata) ? $metadata : array();
		$liveness = WorktreeContextInjector::classify_liveness($metadata);
		$owner    = WorktreeContextInjector::summarize_owner($metadata);
		$session  = WorktreeContextInjector::summarize_session($metadata);
		$task     = is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : null;

		return array(
			'handle'                => $parsed['dir_name'],
			'repo'                  => $parsed['repo'],
			'is_worktree'           => $parsed['is_worktree'],
			'is_primary'            => ! $parsed['is_worktree'],
			'external'              => false,
			'branch_slug'           => $parsed['branch_slug'],
			'branch'                => $metadata['branch'] ?? $parsed['branch_slug'],
			'path'                  => $path,
			'primary_path'          => $this->get_primary_path($parsed['repo']),
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
			'missing_path'          => ! is_dir($path),
			'metadata'              => $metadata,
		);
	}

	/**
	 * Remove a worktree.
	 *
	 * Refuses if the worktree has uncommitted changes unless `$force` is true.
	 *
	 * @param  string $repo   Primary repo name.
	 * @param  string $branch Branch (or slug) of the worktree.
	 * @param  bool   $force  Force removal even if dirty.
	 * @return array{success: bool, handle: string, message: string}|\WP_Error
	 */
	public function worktree_remove( string $repo, string $branch, bool $force = false ): array|\WP_Error {
		$repo = $this->sanitize_name($repo);
		if ( '' === $repo ) {
			return new \WP_Error('invalid_repo', 'Repository name is required.', array( 'status' => 400 ));
		}

		$slug = $this->slugify_branch($branch);
		if ( '' === $slug ) {
			return new \WP_Error('invalid_branch', 'Branch/slug is required.', array( 'status' => 400 ));
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path . '/.git') ) {
			return new \WP_Error('primary_not_found', sprintf('Primary checkout for "%s" does not exist.', $repo), array( 'status' => 404 ));
		}

		$wt_handle = $repo . '@' . $slug;
		$wt_path   = $this->workspace_path . '/' . $wt_handle;

		if ( ! is_dir($wt_path) ) {
			return new \WP_Error('worktree_not_found', sprintf('Worktree "%s" not found.', $wt_handle), array( 'status' => 404 ));
		}

		$result = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			function () use ( $primary_path, $wt_path, $force, $wt_handle ) {
				$cmd    = sprintf('worktree remove %s%s', $force ? '--force ' : '', escapeshellarg($wt_path));
				$result = $this->run_git($primary_path, $cmd);

				if ( is_wp_error($result) ) {
					return $this->worktree_git_unavailable_with_host_commands(
						$result,
						'Remove workspace worktree',
						array(
							sprintf('git -C %s %s', escapeshellarg($primary_path), $cmd),
						)
					);
				}

				WorktreeContextInjector::forget_metadata($wt_handle);
				$this->worktree_inventory()->delete($wt_handle);
				return $result;
			}
		);

		if ( is_wp_error($result) ) {
			return $result;
		}

		$this->emit_workspace_changed('worktree_remove', $repo, $wt_handle, $wt_path);

		return array(
			'success' => true,
			'handle'  => $wt_handle,
			'message' => sprintf('Worktree "%s" removed.', $wt_handle),
		);
	}

	/**
	 * Prune stale worktree registry entries across all primaries.
	 *
	 * @return array{success: bool, pruned: array, skipped?: array, next_commands?: array, inventory?: array, stale_inventory?: array, stale_marker_blockers?: array}|\WP_Error
	 */
	public function worktree_prune(): array|\WP_Error {
		$pruned          = array();
		$skipped         = array();
		$next_commands   = array();
		$stale_rows      = array();
		$marker_blocks   = array();
		$marker_repaired = array();

		if ( ! is_dir($this->workspace_path) ) {
			return array(
				'success' => true,
				'pruned'  => $pruned,
			);
		}

		$entries = scandir($this->workspace_path);
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_contains($entry, '@') ) {
				continue;
			}
			$primary_path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir($primary_path . '/.git') ) {
				continue;
			}
			$result = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$entry,
				fn() => $this->run_git($primary_path, 'worktree prune -v')
			);
			if ( is_wp_error($result) ) {
				if ( 'datamachine_workspace_git_unavailable' === $result->get_error_code() ) {
					$skipped[]       = array(
						'repo'         => $entry,
						'primary_path' => $primary_path,
						'reason'       => $result->get_error_message(),
					);
					$next_commands[] = sprintf('git -C %s worktree prune -v', escapeshellarg($primary_path));
					continue;
				}
				return $result;
			}
			$pruned[] = $entry;
		}

		$refresh = $this->worktree_inventory_refresh();
		if ( $refresh instanceof \WP_Error ) {
			return $refresh;
		}

		$inventory_diagnostics = $this->prune_stale_worktree_inventory_rows();
		if ( $inventory_diagnostics instanceof \WP_Error ) {
			return $inventory_diagnostics;
		}

		$stale_rows      = (array) ( $inventory_diagnostics['stale_inventory'] ?? array() );
		$marker_blocks   = (array) ( $inventory_diagnostics['stale_marker_blockers'] ?? array() );
		$marker_repaired = (array) ( $inventory_diagnostics['stale_marker_repaired'] ?? array() );
		foreach ( (array) ( $inventory_diagnostics['next_commands'] ?? array() ) as $command ) {
			$next_commands[] = (string) $command;
		}

		return array(
			'success'               => true,
			'pruned'                => $pruned,
			'skipped'               => $skipped,
			'next_commands'         => array_values(array_unique($next_commands)),
			'inventory'             => $refresh,
			'stale_inventory'       => $stale_rows,
			'stale_marker_blockers' => $marker_blocks,
			'stale_marker_repaired' => $marker_repaired,
		);
	}

	/**
	 * Repair safe stale inventory rows and report marker blockers that need review.
	 *
	 * `git worktree prune` only repairs Git's own metadata. DMC can also retain
	 * cleanup-eligible inventory rows for worktrees that no longer exist, and
	 * those rows can block bounded cleanup even after Git reports nothing to
	 * prune. Missing-path rows are safe to forget because no checkout remains on
	 * disk; path-present stale markers are removed only when the inventory row has
	 * a cleanup signal and exactly matches the expected workspace worktree path.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function prune_stale_worktree_inventory_rows(): array|\WP_Error {
		$repository            = $this->worktree_inventory();
		$stale_inventory       = array();
		$stale_marker_blockers = array();
		$stale_marker_repaired = array();
		$next_commands         = array();

		foreach ( $repository->list() as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			$repo   = (string) ( $row['repo'] ?? '' );
			$path   = (string) ( $row['path'] ?? '' );
			$parsed = '' !== $handle ? $this->parse_handle($handle) : array( 'is_worktree' => false );
			if ( '' === $handle || empty($parsed['is_worktree']) ) {
				continue;
			}

			if ( ! empty($row['missing_path']) && ( '' === $path || ! is_dir($path) ) ) {
				if ( $repository->delete($handle) ) {
					WorktreeContextInjector::forget_metadata($handle);
					$stale_inventory[] = array(
						'handle'      => $handle,
						'repo'        => $repo,
						'path'        => $path,
						'reason_code' => 'registry_artifact',
						'reason'      => 'inventory row pointed at a missing worktree path and was removed from DMC metadata',
					);
				}
				continue;
			}

			$marker = rtrim($path, '/') . '/.git';
			if ( ! is_file($marker) ) {
				continue;
			}

			$contents = file_get_contents($marker); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a validated local .git marker, not a remote URL.
			if ( false === $contents || ! preg_match('/^gitdir:\s*(.+)$/mi', $contents, $matches) ) {
				continue;
			}

			$gitdir = trim($matches[1]);
			if ( ! str_contains($gitdir, '/.git/worktrees/') && ! str_contains($gitdir, '\\.git\\worktrees\\') ) {
				continue;
			}

			if ( file_exists($gitdir) ) {
				continue;
			}

			$primary_path = '' !== $repo ? $this->get_primary_path($repo) : (string) ( $row['primary_path'] ?? '' );
			$repair       = $this->repair_cleanup_eligible_stale_worktree_marker($row, $parsed, $gitdir, $primary_path);
			if ( $repair instanceof \WP_Error ) {
				return $repair;
			}

			if ( null !== $repair ) {
				$stale_marker_repaired[] = $repair;
				continue;
			}

			$remove_command          = sprintf('studio wp datamachine-code workspace remove %s --yes', escapeshellarg($handle));
			$stale_marker_blockers[] = array(
				'handle'       => $handle,
				'repo'         => $repo,
				'path'         => $path,
				'primary_path' => $primary_path,
				'gitdir'       => $gitdir,
				'reason_code'  => 'stale_worktree_marker',
				'reason'       => 'worktree path still exists, but its .git marker points at a missing primary .git/worktrees entry; leaving checkout in place because the row is not an exact cleanup-eligible stale marker candidate',
				'hint'         => 'Inspect the path before removal. If it is safe to discard, run the DMC-owned remove command returned in next_command.',
				'next_command' => $remove_command,
			);
			$next_commands[]         = $remove_command;
		}

		return array(
			'stale_inventory'       => $stale_inventory,
			'stale_marker_blockers' => $stale_marker_blockers,
			'stale_marker_repaired' => $stale_marker_repaired,
			'next_commands'         => array_values(array_unique($next_commands)),
		);
	}

	/**
	 * Remove an exact cleanup-eligible stale marker worktree path from DMC-owned state.
	 *
	 * @param array<string,mixed> $row          Inventory row.
	 * @param array<string,mixed> $parsed       Parsed worktree handle.
	 * @param string              $gitdir       Missing gitdir from the stale marker.
	 * @param string              $primary_path Primary checkout path.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	private function repair_cleanup_eligible_stale_worktree_marker( array $row, array $parsed, string $gitdir, string $primary_path ): array|null|\WP_Error {
		$handle   = (string) ( $row['handle'] ?? '' );
		$repo     = (string) ( $row['repo'] ?? '' );
		$path     = rtrim( (string) ( $row['path'] ?? '' ), '/');
		$metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : array();
		if ( empty($metadata) && ! empty($row['lifecycle_state']) ) {
			$metadata['lifecycle_state'] = (string) $row['lifecycle_state'];
		}
		if ( empty($metadata) && 'cleanup_eligible' === (string) ( $row['cleanup_signal'] ?? '' ) ) {
			$metadata['lifecycle_state'] = 'cleanup_eligible';
		}

		if ( '' === $handle || '' === $path || empty($parsed['is_worktree']) || ! WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			return null;
		}

		$expected_path = rtrim($this->workspace_path, '/') . '/' . (string) ( $parsed['dir_name'] ?? $handle );
		if ( $path !== $expected_path ) {
			return null;
		}

		$validation    = $this->validate_containment($path, $this->workspace_path);
		$expected_real = realpath($expected_path);
		if ( empty($validation['valid']) || false === $expected_real || (string) ( $validation['real_path'] ?? '' ) !== $expected_real ) {
			return null;
		}

		$removed_paths = $this->remove_contained_directory_recursive($path, $this->workspace_path, $this->workspace_path);
		if ( $removed_paths instanceof \WP_Error ) {
			return $removed_paths;
		}

		WorktreeContextInjector::forget_metadata($handle);
		$this->worktree_inventory()->delete($handle);
		if ( '' !== $primary_path && is_dir($primary_path . '/.git') ) {
			WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$repo,
				fn() => $this->run_git($primary_path, 'worktree prune')
			);
		}

		return array(
			'handle'        => $handle,
			'repo'          => $repo,
			'path'          => $path,
			'primary_path'  => $primary_path,
			'gitdir'        => $gitdir,
			'reason_code'   => 'stale_worktree_marker_repaired',
			'reason'        => 'cleanup-eligible worktree path exactly matched a stale .git marker row and was removed from DMC workspace state',
			'removed_paths' => $removed_paths,
		);
	}

	/**
	 * Attach host-shell remediation commands to local-git-unavailable worktree errors.
	 *
	 * @param \WP_Error         $error         Original git error.
	 * @param string            $operation     Human-readable operation.
	 * @param array<int,string> $next_commands Exact commands to run in a host shell.
	 * @return \WP_Error
	 */
	private function worktree_git_unavailable_with_host_commands( \WP_Error $error, string $operation, array $next_commands ): \WP_Error {
		if ( 'datamachine_workspace_git_unavailable' !== $error->get_error_code() ) {
			return $error;
		}

		$data                  = (array) $error->get_error_data();
		$data['operation']     = $operation;
		$data['next_commands'] = array_values(array_filter(array_map('strval', $next_commands)));
		$data['hint']          = 'Run the listed command from a host shell with local git access, then rerun workspace worktree prune to refresh DMC inventory.';

		$message = $error->get_error_message();
		if ( ! empty($data['next_commands'][0]) ) {
			$message .= ' Host command: ' . $data['next_commands'][0];
		}

		return new \WP_Error($error->get_error_code(), $message, $data);
	}


	/**
	 * Resolve a sensible default base for new branches.
	 *
	 * Prefers `origin/HEAD` (typically `origin/main` or `origin/trunk`); falls
	 * back to plain `HEAD` if no remote default is configured.
	 *
	 * @param  string $repo_path Primary repo path.
	 * @return string
	 */
	private function resolve_default_base( string $repo_path ): string {
		$result = $this->run_git($repo_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD');
		if ( ! is_wp_error($result) ) {
			$ref = trim( (string) ( $result['output'] ?? '' ));
			if ( '' !== $ref ) {
				return $ref;
			}
		}
		return 'HEAD';
	}

	/**
	 * Resolve the fetched remote default branch ref, if one is configured.
	 *
	 * @param  string $repo_path Primary repo path.
	 * @return string|null Fully-qualified remote default ref, or null when absent.
	 */
	private function resolve_remote_default_ref( string $repo_path ): ?string {
		$result = $this->run_git($repo_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD');
		if ( is_wp_error($result) ) {
			return null;
		}

		$ref = trim( (string) ( $result['output'] ?? '' ));
		return '' !== $ref ? $ref : null;
	}

	/**
	 * Refuse a branch/base that is behind the remote default branch.
	 *
	 * This is intentionally zero-tolerance. The older upstream staleness gate has
	 * a threshold for large-drift cleanup, but default-branch freshness protects
	 * the starting point for new agent work and should not silently allow lag.
	 *
	 * @param  string $primary_path Primary repo path.
	 * @param  string $ref          Branch or base ref to compare.
	 * @param  string $repo         Repository name.
	 * @param  string $branch       Requested worktree branch.
	 * @param  string $ref_role     Human-readable role: branch or base.
	 * @return true|\WP_Error True when current/unknown, WP_Error when behind.
	 */
	private function assert_ref_current_with_default_branch( string $primary_path, string $ref, string $repo, string $branch, string $ref_role ): true|\WP_Error {
		$default_ref = $this->resolve_remote_default_ref($primary_path);
		if ( null === $default_ref ) {
			return true;
		}

		$behind = WorktreeStalenessProbe::behind_count($primary_path, $ref, $default_ref);
		if ( ! is_int($behind) || 0 === $behind ) {
			return true;
		}

		return $this->worktree_behind_default_branch_error($behind, $default_ref, $repo, $branch, $ref_role);
	}

	/**
	 * Add default-branch freshness fields for the materialized branch.
	 *
	 * @param  string $primary_path Primary repo path.
	 * @param  string $branch       Requested worktree branch.
	 * @param  array  $response     Worktree response payload, mutated in place.
	 */
	private function populate_default_branch_behind_count( string $primary_path, string $branch, array &$response ): void {
		$default_ref = $this->resolve_remote_default_ref($primary_path);
		if ( null === $default_ref ) {
			return;
		}

		$behind = WorktreeStalenessProbe::behind_count($primary_path, $branch, $default_ref);
		if ( is_int($behind) ) {
			$response['default_branch_commits_behind'] = $behind;
			$response['default_branch_ref']            = $default_ref;
		}
	}

	/**
	 * Build the default-branch staleness error used by preflight and rollback gates.
	 *
	 * @param  int    $behind     Commits behind the remote default branch.
	 * @param  string $default_ref Remote default branch ref.
	 * @param  string $repo       Repository name.
	 * @param  string $branch     Requested worktree branch.
	 * @param  string $ref_role   Human-readable role: branch or base.
	 * @return \WP_Error
	 */
	private function worktree_behind_default_branch_error( int $behind, string $default_ref, string $repo, string $branch, string $ref_role ): \WP_Error {
		return new \WP_Error(
			'worktree_behind_default_branch',
			sprintf(
				'Worktree %s for branch "%s" is %d commits behind the remote default branch %s. Refusing to create a stale worktree. Refresh or rebase the branch first, create from the remote default ref directly, or pass --allow-stale to explicitly opt in to a known-stale checkout.',
				$ref_role,
				$branch,
				$behind,
				$default_ref
			),
			array(
				'status'                        => 409,
				'default_branch_commits_behind' => $behind,
				'default_branch_ref'            => $default_ref,
				'repo'                          => $repo,
				'branch'                        => $branch,
				'ref_role'                      => $ref_role,
				'allow_stale'                   => false,
			)
		);
	}

	/**
	 * Remove a worktree rejected after creation and delete its new local branch.
	 *
	 * @param string $primary_path   Primary checkout path.
	 * @param string $wt_path        Worktree path.
	 * @param string $branch         Branch checked out in the worktree.
	 * @param bool   $created_branch Whether the branch was created by this call.
	 * @return void
	 */
	private function rollback_rejected_worktree( string $primary_path, string $wt_path, string $branch, bool $created_branch ): void {
		$this->run_git($primary_path, sprintf('worktree remove --force %s', escapeshellarg($wt_path)));
		if ( $created_branch ) {
			$this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($branch)));
		}
	}

	/**
	 * Does a ref look like a remote-tracking ref?
	 *
	 * `resolve_default_base()` returns fully-qualified paths
	 * (`refs/remotes/origin/main`), but callers may pass short forms like
	 * `origin/main`. Both are "already at-tip post-fetch" and staleness
	 * comparisons against them would be nonsensical.
	 *
	 * @param  string $ref Ref name to classify.
	 * @return bool
	 */
	private function is_remote_tracking_ref( string $ref ): bool {
		return str_starts_with($ref, 'refs/remotes/') || str_starts_with($ref, 'origin/');
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
	 * @param  array $response Accumulated response payload.
	 * @return int|null Behind-count, or null if no staleness data was collected.
	 */
	private function effective_behind_count( array $response ): ?int {
		if ( isset($response['stale_commits_behind']) ) {
			return (int) $response['stale_commits_behind'];
		}
		if ( isset($response['base_stale_commits_behind']) ) {
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
	 * @param  string $wt_path        Worktree path.
	 * @param  array  $response       Accumulated response payload.
	 * @param  bool   $created_branch Whether this was a freshly-created branch.
	 * @return array|null
	 */
	private function try_rebase_worktree( string $wt_path, array &$response, bool $created_branch ): ?array {
		$target = null;
		$clear  = null;

		if ( ! $created_branch
			&& isset($response['stale_commits_behind'])
			&& (int) $response['stale_commits_behind'] > 0
		) {
			$target = '@{upstream}';
			$clear  = 'stale_commits_behind';
		} elseif ( $created_branch
			&& isset($response['base_stale_commits_behind'])
			&& (int) $response['base_stale_commits_behind'] > 0
			&& ! empty($response['base_upstream'])
		) {
			$target = (string) $response['base_upstream'];
			$clear  = 'base_stale_commits_behind';
		}

		if ( null === $target ) {
			return null;
		}

		$result = $this->run_git($wt_path, sprintf('rebase %s', escapeshellarg($target)));

		if ( is_wp_error($result) ) {
			// Abort so the worktree stays at its pre-rebase HEAD. Agent can
			// retry manually after resolving conflicts.
			$this->run_git($wt_path, 'rebase --abort');

			$data  = $result->get_error_data();
			$tail  = is_array($data) && isset($data['output']) ? trim( (string) $data['output']) : '';
			$error = '' !== $tail ? $tail : $result->get_error_message();

			return array(
				'rebase_attempted' => true,
				'rebase_target'    => $target,
				'rebase_succeeded' => false,
				'rebase_error'     => $error,
			);
		}

		// Success: zero out the behind-count so the gate sees a fresh worktree.
		unset($response[ $clear ]);

		return array(
			'rebase_attempted' => true,
			'rebase_target'    => $target,
			'rebase_succeeded' => true,
		);
	}

	/**
	 * Parse a `git worktree list --porcelain` block.
	 *
	 * @param  string $block Newline-separated key/value lines.
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
	 * @param  string $wt_path Worktree directory.
	 * @return string|null Branch name (e.g. `fix/foo`), or null when unknown.
	 */
	private function resolve_worktree_branch_from_head_file( string $wt_path ): ?string {
		$git_pointer = rtrim($wt_path, '/') . '/.git';
		if ( ! is_file($git_pointer) && ! is_dir($git_pointer) ) {
			return null;
		}

		$gitdir = null;
		if ( is_file($git_pointer) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading .git pointer file in a controlled worktree.
			$pointer = @file_get_contents($git_pointer);
			if ( false === $pointer ) {
				return null;
			}
			if ( ! preg_match('/^gitdir:\s*(.+)$/m', $pointer, $m) ) {
				return null;
			}
			$gitdir = trim($m[1]);
			// Pointer paths are typically absolute, but tolerate relative.
			if ( '' !== $gitdir && '/' !== $gitdir[0] ) {
				$gitdir = rtrim($wt_path, '/') . '/' . $gitdir;
			}
		} else {
			$gitdir = $git_pointer;
		}

		if ( null === $gitdir || '' === $gitdir ) {
			return null;
		}

		$head_file = rtrim($gitdir, '/') . '/HEAD';
		if ( ! is_file($head_file) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading .git HEAD file in a controlled worktree.
		$head = @file_get_contents($head_file);
		if ( false === $head ) {
			return null;
		}

		$head = trim($head);
		if ( str_starts_with($head, 'ref:') ) {
			$ref = trim(substr($head, 4));
			return preg_replace('#^refs/heads/#', '', $ref);
		}

		// Detached HEAD or other unrecognized shape — surface as unknown.
		return null;
	}

	private function parse_worktree_block( string $block ): ?array {
		$lines = array_filter(array_map('trim', explode("\n", $block)));
		$out   = array(
			'path'   => '',
			'head'   => '',
			'branch' => null,
		);
		foreach ( $lines as $line ) {
			if ( str_starts_with($line, 'worktree ') ) {
				$out['path'] = substr($line, strlen('worktree '));
			} elseif ( str_starts_with($line, 'HEAD ') ) {
				$out['head'] = substr($line, strlen('HEAD '));
			} elseif ( str_starts_with($line, 'branch ') ) {
				$ref           = substr($line, strlen('branch '));
				$out['branch'] = preg_replace('#^refs/heads/#', '', $ref);
			} elseif ( 'detached' === $line ) {
				$out['branch'] = null;
			}
		}
		return ( '' === $out['path'] ) ? null : $out;
	}
}
