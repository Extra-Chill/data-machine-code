<?php
/**
 * Worktree cleanup engine, safety gates, and reporting helpers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitHubRemote;

defined('ABSPATH') || exit;

trait WorkspaceWorktreeCleanupEngine {

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
	 *   - At least one cleanup signal is present:
	 *       a) `git for-each-ref` reports upstream status "gone" (branch
	 *          was deleted on the remote — typical after GitHub
	 *          "auto-delete head branches" fires on PR merge), OR
	 *       b) GitHub API reports a closed+merged PR whose head
	 *          branch matches this worktree's branch, OR
	 *       c) the clean local worktree has no unpushed commits and is backed
	 *          by an existing remote branch, so removing the local checkout does
	 *          not delete recoverable Git state.
	 *
	 * Signals (a) and (c) are local and fast; signal (b) requires a PAT + network
	 * but catches the case where the remote branch still exists (e.g.
	 * manual merge without branch deletion).
	 *
	 * @param  array $opts {
	 * @type   bool $dry_run If true, return the plan without removing anything.
	 * @type   bool $force   If true, ignore dirty working-tree safety.
	 * @type   bool $skip_github If true, only use upstream-gone signal (no API calls).
	 * @type   string $older_than Optional duration such as 7d, 24h, or 30m. Candidates newer than this are skipped.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_cleanup_merged( array $opts = array() ): array|\WP_Error {
		$dry_run                   = ! empty($opts['dry_run']);
		$force                     = ! empty($opts['force']);
		$discard_unpushed          = ! empty($opts['discard_unpushed']);
		$skip_github               = ! empty($opts['skip_github']);
		$direct_apply_plan         = ! empty($opts['direct_apply_plan']);
		$inventory_only            = ! empty($opts['inventory_only']);
		$include_repaired_metadata = ! empty($opts['include_repaired_metadata']);
		$stale_liveness_only       = ! empty($opts['stale_liveness_only']);
		$apply_plan                = isset($opts['apply_plan']) && is_array($opts['apply_plan']) ? $opts['apply_plan'] : null;
		$older_than                = isset($opts['older_than']) ? trim( (string) $opts['older_than']) : '';
		$sort                      = isset($opts['sort']) ? trim( (string) $opts['sort']) : '';
		$progress                  = isset($opts['progress_callback']) && is_callable($opts['progress_callback']) ? $opts['progress_callback'] : null;
		$started_at                = microtime(true);
		$limit                     = array_key_exists('limit', $opts) ? max(1, (int) $opts['limit']) : null;
		$offset                    = array_key_exists('offset', $opts) ? max(0, (int) $opts['offset']) : 0;
		$budget_context            = null;
		$budget_stopped            = false;
		$remove_timeout_seconds    = $this->normalize_worktree_cleanup_remove_timeout($opts['remove_timeout'] ?? null);
		if ( is_wp_error($remove_timeout_seconds) ) {
			return $remove_timeout_seconds;
		}

		if ( isset($opts['until_budget']) && '' !== trim( (string) $opts['until_budget']) ) {
			if ( ! $dry_run ) {
				return new \WP_Error('cleanup_budget_requires_dry_run', 'Budgeted cleanup is review-only. Use --dry-run with --until-budget, then apply a reviewed cleanup path.', array( 'status' => 400 ));
			}

			$budget_seconds = $this->parse_worktree_metadata_reconciliation_budget(trim( (string) $opts['until_budget']));
			if ( is_wp_error($budget_seconds) ) {
				return $budget_seconds;
			}
			$budget_context = $this->build_worktree_loop_budget_context($opts, $started_at);
		}

		if ( ( null !== $limit || $offset > 0 ) && ! $dry_run ) {
			return new \WP_Error('cleanup_pagination_requires_dry_run', 'Paginated cleanup is review-only. Use --dry-run with --limit/--offset, then apply a reviewed cleanup path.', array( 'status' => 400 ));
		}

		if ( '' !== $sort && ! in_array($sort, array( 'size', 'age' ), true) ) {
			return new \WP_Error('invalid_cleanup_sort', 'Invalid cleanup sort. Use size or age.', array( 'status' => 400 ));
		}

		if ( $inventory_only ) {
			if ( ! $dry_run ) {
				return new \WP_Error('inventory_cleanup_requires_dry_run', 'Inventory-only cleanup is review-only. Run workspace worktree bounded-cleanup-eligible-apply to apply the same bounded cleanup-eligible class. Broader merge-signal cleanup is a separate, explicit review path.', array( 'status' => 400 ));
			}
			if ( null !== $apply_plan ) {
				return new \WP_Error('inventory_cleanup_apply_plan_unsupported', 'Inventory-only cleanup cannot apply a plan because it intentionally skips full safety revalidation.', array( 'status' => 400 ));
			}

			return $this->worktree_cleanup_inventory_only($older_than, $sort, $include_repaired_metadata, $limit, $offset);
		}

		$planned_candidates = null;
		if ( null !== $apply_plan ) {
			$planned_candidates = $this->extract_worktree_cleanup_plan_candidates($apply_plan);
			if ( is_wp_error($planned_candidates) ) {
				return $planned_candidates;
			}

			// Applying a stale plan must never use the dirty override. The current
			// workspace state is re-evaluated below and dirty rows stay skipped.
			$force = false;

			if ( $direct_apply_plan && ! $dry_run ) {
				return $this->apply_worktree_cleanup_plan_candidates($planned_candidates, $force, $started_at, $stale_liveness_only, $remove_timeout_seconds, $discard_unpushed);
			}
		}

		$age_filter = null;
		if ( '' !== $older_than ) {
			$duration_seconds = $this->parse_worktree_cleanup_duration($older_than);
			if ( is_wp_error($duration_seconds) ) {
				return $duration_seconds;
			}

			$age_filter = WorktreeAgeFilter::build($older_than, $duration_seconds);
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

		/** @var array<string,mixed> $github_cache */
		$github_cache = array();

		$all_worktrees   = $this->dedupe_worktree_cleanup_scan_rows(
			array_merge(
				array_values(array_filter( (array) $listing['worktrees'], fn( $wt ) => empty($wt['is_primary']))),
				$this->discover_broken_orphan_worktree_markers( (array) $listing['worktrees'])
			)
		);
		$total_worktrees = count($all_worktrees);
		$worktrees       = array_slice($all_worktrees, $offset, $limit);
		$checked         = 0;
		$processed       = 0;
		$removed_count   = 0;

		$this->emit_worktree_cleanup_progress($progress, 'start', '', $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at);

		// Fetch + prune each primary once per repo, but keep status/disk probes inside
		// the row loop so budgeted dry-runs can return partial evidence promptly.
		/** @var array<string,bool> $fetched */
		$fetched = array();

		/** @var array<string,\WP_Error> $fetch_timeouts */
		$fetch_timeouts = array();

		foreach ( $worktrees as $wt ) {
			if ( null !== $budget_context && $this->is_worktree_loop_budget_exhausted($budget_context) ) {
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
			$liveness     = (string) ( $wt['liveness'] ?? '' );
			$disk_fields  = $this->extract_worktree_disk_fields($wt);
			$identity     = $this->recover_worktree_identity_from_metadata($wt);
			$repo         = (string) $identity['repo'];
			$branch       = (string) $identity['branch'];
			$wt_path      = (string) $identity['path'];
			$primary_path = '' !== $repo ? $this->get_primary_path($repo) : '';

			if ( ! empty($wt['broken_orphan_marker']) ) {
				$candidates[] = array_merge(
					array(
						'handle'             => $handle,
						'repo'               => $repo,
						'branch'             => '',
						'path'               => $wt_path,
						'dirty'              => null,
						'signal'             => 'broken_orphan_worktree_marker',
						'reason_code'        => 'broken_orphan_worktree_marker',
						'reason'             => 'managed worktree directory has a .git pointer to missing Git worktree metadata',
						'broken_target_path' => (string) ( $wt['broken_target_path'] ?? '' ),
						'hint'               => 'Cleanup apply will remove this orphan directory only after revalidating that the .git pointer still targets missing worktree metadata.',
						'created_at'         => $created_at,
						'liveness'           => $liveness,
						'metadata'           => $metadata,
					),
					$disk_fields
				);
				continue;
			}

			if ( ! empty($wt['external']) ) {
				$skipped[] = array_merge(
					array(
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
					), $disk_fields
				);
				continue;
			}

			++$checked;
			$this->emit_worktree_cleanup_progress($progress, 'checking', (string) $handle, $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at);

			if ( $stale_liveness_only && 'stale' !== $liveness ) {
				$skipped[] = array_merge(
					array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'active_or_recent_worktree',
						'reason'      => sprintf('worktree liveness is %s; stale-worktrees mode only removes stale/inactive worktrees', '' !== $liveness ? $liveness : 'unknown'),
						'liveness'    => $liveness,
						'created_at'  => $created_at,
						'metadata'    => $metadata,
					),
					$disk_fields
				);
				continue;
			}

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
				$skipped[] = array_merge(
					array(
						'handle'             => $handle,
						'repo'               => $repo,
						'branch'             => $branch,
						'path'               => $wt_path,
						'reason_code'        => 'missing_metadata',
						'reason'             => 'missing repo/branch/path',
						'missing_fields'     => $missing_fields,
						'hydrated_fields'    => $identity['hydrated_fields'],
						'identity_conflicts' => $identity['conflicts'],
						'stored_identity'    => $identity['stored_identity'],
						'hint'               => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
						'created_at'         => $created_at,
						'metadata'           => $metadata,
					), $disk_fields
				);
				continue;
			}

			if ( ! empty($identity['detached_branch']) ) {
				$detached_reason = in_array($branch, $protected_branches, true) ? 'detached_protected_branch' : 'detached_worktree';
				$skipped[]       = array_merge(
					array(
						'handle'          => $handle,
						'repo'            => $repo,
						'branch'          => $branch,
						'path'            => $wt_path,
						'reason_code'     => $detached_reason,
						'reason'          => sprintf('git reports detached HEAD; stored metadata identifies branch %s', $branch),
						'actual_branch'   => '',
						'hydrated_fields' => $identity['hydrated_fields'],
						'stored_identity' => $identity['stored_identity'],
						'hint'            => 'Inspect the detached worktree and either reattach it to the stored branch, finalize lifecycle metadata, or remove it manually after review.',
						'created_at'      => $created_at,
						'metadata'        => $metadata,
					), $disk_fields
				);
				continue;
			}

			if ( in_array($branch, $protected_branches, true) ) {
				$skipped[] = array_merge(
					array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'protected_base_branch_worktree',
						'reason'      => sprintf('worktree has protected/base branch %s checked out', $branch),
						'hint'        => 'Protected/base branches should live in primary checkouts. Inspect this worktree, move any needed changes, then remove it explicitly if safe.',
						'created_at'  => $created_at,
						'metadata'    => $metadata,
					), $disk_fields
				);
				continue;
			}

			$dirty_probe = $this->probe_worktree_dirty_count($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( is_wp_error($dirty_probe) ) {
				$skipped[] = $this->build_worktree_probe_failure_skip($handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $dirty_probe);
				continue;
			}
			$dirty_count = (int) $dirty_probe;

			if ( $dirty_count > 0 && ! $force ) {
				$artifact_dirty = $this->classify_artifact_only_dirty_worktree($repo, $wt_path);
				if ( is_array($artifact_dirty) ) {
					$skipped[] = array_merge(
						array(
							'handle'      => $handle,
							'repo'        => $repo,
							'branch'      => $branch,
							'path'        => $wt_path,
							'reason_code' => 'artifact_only_dirty_worktree',
							'reason'      => sprintf('working tree dirty only from declared/generated artifact paths (%d files) - run artifact cleanup instead of force-removing the worktree', $dirty_count),
							'dirty'       => $dirty_count,
							'hint'        => 'Run studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run to review reconstructable artifact cleanup; source edits are still protected by dirty_worktree.',
							'created_at'  => $created_at,
							'metadata'    => $metadata,
						), $disk_fields, array(
							'artifact_dirty_paths' => $artifact_dirty['paths'],
							'artifacts'            => $artifact_dirty['artifacts'],
							'artifact_size_bytes'  => $artifact_dirty['artifact_size_bytes'],
							'size_bytes'           => max( (int) ( $disk_fields['size_bytes'] ?? 0 ), (int) $artifact_dirty['artifact_size_bytes'] ),
							'estimated_size_bytes' => max( (int) ( $disk_fields['estimated_size_bytes'] ?? 0 ), (int) $artifact_dirty['artifact_size_bytes'] ),
						)
					);
					continue;
				}

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

				if ( is_array($obsolete_dirty) ) {
					$skipped[] = array_merge(
						array(
							'handle'               => $handle,
							'repo'                 => $repo,
							'branch'               => $branch,
							'path'                 => $wt_path,
							'reason_code'          => 'merged_pr_with_only_obsolete_dirty_changes',
							'reason'               => sprintf(
								'merged branch with %d dirty file(s); all dirty paths already absent on default branch — safe force-cleanup candidate (review then rerun with force=true)',
								$dirty_count
							),
							'dirty'                => $dirty_count,
							'dirty_obsolete_paths' => $obsolete_dirty['paths'],
							'merge_signal'         => $obsolete_dirty['merge_signal'],
							'pr_url'               => $obsolete_dirty['pr_url'] ?? null,
							'default_ref'          => $obsolete_dirty['default_ref'],
							'hint'                 => 'Dirty edits only touch paths the default branch no longer has. After review, rerun cleanup with force=true to remove this worktree.',
							'created_at'           => $created_at,
							'metadata'             => $metadata,
						), $disk_fields
					);
						continue;
				}

				$skipped[] = array_merge(
					array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'dirty_worktree',
						'reason'      => sprintf('working tree dirty (%d files) — pass force=true to override', $dirty_count),
						'dirty'       => $dirty_count,
						'created_at'  => $created_at,
						'metadata'    => $metadata,
					), $disk_fields
				);
				continue;
			}

			// Hard stop: worktrees with local commits the remote hasn't seen.
			// Upstream may be "gone" because a human manually deleted the
			// remote branch without merging — nuking the worktree would lose
			// those commits. `force` does NOT override this: data loss is a
			// harder problem than dirty-file loss, and this guard is cheap.
			$unpushed = $this->count_unpushed_commits($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( is_wp_error($unpushed) ) {
				$skipped[] = $this->build_worktree_probe_failure_skip($handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $unpushed);
				continue;
			}
			if ( $unpushed > 0 ) {
				$skipped[] = array_merge(
					array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'unpushed_commits',
						'reason'      => sprintf('%d unpushed commit(s) — refusing to delete even with force (push or reset explicitly)', $unpushed),
						'unpushed'    => $unpushed,
						'created_at'  => $created_at,
						'metadata'    => $metadata,
					), $disk_fields
				);
				continue;
			}

			$primary_path = $this->get_primary_path($repo);
			if ( ! is_dir($primary_path . '/.git') ) {
				$skipped[] = array_merge(
					array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'missing_metadata',
						'reason'      => 'primary checkout missing',
						'hint'        => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
						'created_at'  => $created_at,
						'metadata'    => $metadata,
					), $disk_fields
				);
				continue;
			}

			if ( isset($fetch_timeouts[ $repo ]) ) {
				$skipped[] = $this->build_worktree_probe_failure_skip($handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $fetch_timeouts[ $repo ]);
				continue;
			}

			if ( empty($fetched[ $repo ]) ) {
				$fetch = $this->run_git($primary_path, 'fetch --prune --quiet origin', self::CLEANUP_GIT_PROBE_TIMEOUT);
				if ( is_wp_error($fetch) && $this->is_git_timeout_error($fetch) ) {
					$fetch_timeouts[ $repo ] = $fetch;
					$skipped[]               = $this->build_worktree_probe_failure_skip($handle, $repo, $branch, $wt_path, $created_at, $metadata, $disk_fields, $fetch);
					continue;
				}
				$fetched[ $repo ] = true;
			}

			$signal = is_array($metadata) ? WorktreeCleanupSignal::from_metadata($metadata, $include_repaired_metadata) : null;
			if ( null === $signal ) {
				$signal = $this->detect_merge_signal($primary_path, $repo, $branch, $skip_github, $github_cache);
			}
			$classification = WorktreeCleanupCandidateClassifier::classify_merge_signal_path(
				array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'dirty_count' => $dirty_count,
					'created_at'  => $created_at,
					'liveness'    => $liveness,
					'metadata'    => $metadata,
					'disk_fields' => $disk_fields,
				),
				$signal,
				$age_filter,
				fn() => $this->build_no_merge_signal_evidence($primary_path, $branch, $skip_github),
				$this->build_active_no_signal_next_commands(25, 0)
			);

			if ( 'candidate' === $classification['type'] ) {
				$candidates[] = $classification['row'];
				continue;
			}

			$skipped[] = $classification['row'];
		}

		$candidates = $this->dedupe_worktree_cleanup_rows($candidates);
		$skipped    = $this->dedupe_worktree_cleanup_rows($skipped);
		$candidates = $this->sort_worktree_cleanup_rows($candidates, $sort);

		if ( null !== $planned_candidates ) {
			$scoped     = $this->scope_worktree_cleanup_to_plan($planned_candidates, $candidates, $skipped);
			$candidates = $scoped['candidates'];
			$skipped    = $scoped['skipped'];
		}

		$summary    = $this->build_worktree_cleanup_summary($candidates, array(), $skipped, $age_filter);
		$pagination = $this->build_worktree_cleanup_pagination($offset, $limit, $processed, $total_worktrees, $budget_stopped, $budget_context);
		if ( null !== $pagination ) {
			$summary['pagination'] = $pagination;
		}

		if ( $dry_run ) {
			$this->emit_worktree_cleanup_progress($progress, 'done', '', $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at);

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
					'elapsed_ms' => (int) round(( microtime(true) - $started_at ) * 1000),
					'budget'     => $this->summarize_worktree_loop_budget_context($budget_context, $budget_stopped),
				);
			}

			return $result;
		}

		$removed = array();

		foreach ( $candidates as $cand ) {
			$this->emit_worktree_cleanup_progress($progress, 'removing', (string) ( $cand['handle'] ?? '' ), $checked, count($worktrees), $candidates, $skipped, $removed_count, $started_at);
			$remove = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$cand['repo'],
				function () use ( $cand, $force, $remove_timeout_seconds ) {
					$remove = $this->remove_worktree_by_path($cand['repo'], $cand['branch'], $cand['path'], $force, $remove_timeout_seconds);
					if ( is_wp_error($remove) ) {
						return $remove;
					}

					// Delete the now-detached local branch while the repo lock still covers
					// shared git metadata.
					$primary_path = $this->get_primary_path($cand['repo']);
					if ( '' === (string) ( $cand['branch'] ?? '' ) ) {
						return $remove;
					}
					$branch = $this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($cand['branch'])), self::CLEANUP_GIT_PROBE_TIMEOUT);
					return is_wp_error($branch) ? $branch : $remove;
				}
			);
			if ( is_wp_error($remove) ) {
				$skipped[] = $this->build_worktree_remove_failure_skip($cand, $remove, $remove_timeout_seconds);
				continue;
			}
			$removed[] = array_merge(
				$cand,
				array(
					'removed_path'      => (string) ( $cand['path'] ?? '' ),
					'path_exists_after' => is_dir( (string) ( $cand['path'] ?? '' ) ),
				)
			);
			++$removed_count;
		}

		// Final sweep to drop any remaining registry entries.
		$this->worktree_prune();

		$this->emit_worktree_cleanup_progress($progress, 'done', '', $checked, $total_worktrees, $candidates, $skipped, $removed_count, $started_at);

		return array(
			'success'    => true,
			'dry_run'    => false,
			'candidates' => $candidates,
			'removed'    => $removed,
			'skipped'    => $skipped,
			'summary'    => $this->build_worktree_cleanup_summary($candidates, $removed, $skipped, $age_filter),
		);
	}

	/**
	 * Build pagination evidence for bounded full cleanup dry-runs.
	 *
	 * @param  int        $offset         Inventory offset for this page.
	 * @param  int|null   $limit          Optional page size.
	 * @param  int        $processed      Rows consumed from this page.
	 * @param  int        $total          Total non-primary worktrees.
	 * @param  bool       $budget_stopped Whether the budget stopped the scan early.
	 * @param  array|null $budget_context Optional budget context.
	 * @return array<string,mixed>|null
	 */
	private function build_worktree_cleanup_pagination( int $offset, ?int $limit, int $processed, int $total, bool $budget_stopped, ?array $budget_context ): ?array {
		if ( 0 === $offset && null === $limit && null === $budget_context ) {
			return null;
		}

		$next_offset = ( $offset + $processed ) < $total ? $offset + $processed : null;
		$command     = null;
		if ( null !== $next_offset ) {
			$parts   = array(
				'studio wp datamachine-code workspace worktree cleanup --dry-run',
				null === $limit ? null : '--limit=' . $limit,
				'--offset=' . $next_offset,
				null === $budget_context ? null : '--until-budget=' . (string) ( $budget_context['label'] ?? '' ),
				'--format=json',
			);
			$command = implode(' ', array_values(array_filter($parts, fn( $part ) => null !== $part)));
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
	 * Collapse duplicate cleanup rows emitted by overlapping inventory/git sources.
	 *
	 * @param  array<int,array<string,mixed>> $rows Cleanup rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function dedupe_worktree_cleanup_rows( array $rows ): array {
		$seen   = array();
		$result = array();
		foreach ( $rows as $row ) {
			$key = implode('|', array(
				(string) ( $row['handle'] ?? '' ),
				(string) ( $row['path'] ?? '' ),
				(string) ( $row['reason_code'] ?? $row['signal'] ?? '' ),
			));
			if ( isset($seen[ $key ]) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = $row;
		}

		return $result;
	}

	/**
	 * Find workspace-local managed directories whose .git file points at missing worktree metadata.
	 *
	 * @param  array<int,array<string,mixed>> $listed_worktrees Rows already returned by git worktree list.
	 * @return array<int,array<string,mixed>>
	 */
	private function discover_broken_orphan_worktree_markers( array $listed_worktrees ): array {
		$known = array();
		foreach ( $listed_worktrees as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			$path   = (string) ( $row['path'] ?? '' );
			if ( '' !== $handle ) {
				$known[ $handle ] = true;
			}
			if ( '' !== $path ) {
				$known[ rtrim($path, '/') ] = true;
			}
		}

		if ( ! is_dir($this->workspace_path) ) {
			return array();
		}

		$entries = scandir($this->workspace_path);
		if ( false === $entries ) {
			return array();
		}

		$rows = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! str_contains($entry, '@') || isset($known[ $entry ]) ) {
				continue;
			}

			$parsed = $this->parse_handle($entry);
			if ( empty($parsed['is_worktree']) || '' === (string) $parsed['repo'] ) {
				continue;
			}

			$path = rtrim($this->workspace_path, '/') . '/' . $entry;
			if ( isset($known[ $path ]) || ! is_dir($path) ) {
				continue;
			}

			$broken = $this->classify_broken_orphan_worktree_marker($path);
			if ( null === $broken ) {
				continue;
			}

			$repo       = (string) $parsed['repo'];
			$metadata   = WorktreeContextInjector::get_metadata($entry);
			$created_at = is_array($metadata) ? ( $metadata['created_at'] ?? null ) : null;
			$liveness   = WorktreeContextInjector::classify_liveness(is_array($metadata) ? $metadata : null);
			$disk       = array(
				'size_bytes'           => null,
				'estimated_size_bytes' => null,
				'last_touched_at'      => $this->detect_worktree_last_touched_at($path, is_array($metadata) ? $metadata : null, is_string($created_at) ? $created_at : null),
				'age_days'             => $this->calculate_age_days(is_string($created_at) ? $created_at : null),
				'artifacts'            => array(),
				'artifact_size_bytes'  => 0,
			);

			$rows[] = array_merge(
				array(
					'handle'               => $entry,
					'repo'                 => $repo,
					'is_worktree'          => true,
					'is_primary'           => false,
					'external'             => false,
					'branch_slug'          => $parsed['branch_slug'] ?? null,
					'branch'               => '',
					'head'                 => '',
					'path'                 => $path,
					'dirty'                => null,
					'created_at'           => $created_at,
					'lifecycle_state'      => is_array($metadata) ? ( $metadata['lifecycle_state'] ?? null ) : null,
					'liveness'             => $liveness['liveness'],
					'liveness_reason'      => $liveness['reason'],
					'metadata'             => $metadata,
					'broken_orphan_marker' => true,
					'broken_target_path'   => $broken['gitdir'],
				),
				$disk
			);
		}

		return $rows;
	}

	/**
	 * Return broken gitdir evidence when a worktree marker targets missing metadata.
	 *
	 * @return array{gitdir:string}|null
	 */
	private function classify_broken_orphan_worktree_marker( string $path ): ?array {
		$marker = rtrim($path, '/') . '/.git';
		if ( ! is_file($marker) ) {
			return null;
		}

		$contents = file_get_contents($marker); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local .git marker.
		if ( false === $contents || ! preg_match('/^gitdir:\s*(.+)$/mi', $contents, $matches) ) {
			return null;
		}

		$gitdir = trim($matches[1]);
		if ( '' === $gitdir ) {
			return null;
		}
		if ( ! str_starts_with($gitdir, '/') ) {
			$gitdir = rtrim($path, '/') . '/' . $gitdir;
		}

		$normalized = str_replace('\\', '/', $gitdir);
		if ( ! str_contains($normalized, '/.git/worktrees/') || file_exists($gitdir) ) {
			return null;
		}

		return array( 'gitdir' => $gitdir );
	}

	/**
	 * Emit a bounded cleanup progress event for human CLI callers.
	 *
	 * @param  callable|null $callback      Progress callback.
	 * @param  string        $event         Event name.
	 * @param  string        $handle        Current worktree handle.
	 * @param  int           $checked       Checked worktree count.
	 * @param  int           $total         Total worktree count.
	 * @param  array         $candidates    Candidate rows.
	 * @param  array         $skipped       Skipped rows.
	 * @param  int           $removed_count Removed count.
	 * @param  float         $started_at    Start timestamp from microtime(true).
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
				'candidates' => count($candidates),
				'skipped'    => count($skipped),
				'removed'    => $removed_count,
				'elapsed'    => round(max(0, microtime(true) - $started_at), 1),
			)
		);
	}

	/**
	 * Build a standard cleanup skip row for failed safety probes.
	 *
	 * @param  string    $handle      Worktree handle.
	 * @param  string    $repo        Repo name.
	 * @param  string    $branch      Branch name.
	 * @param  string    $path        Worktree path.
	 * @param  mixed     $created_at  Created timestamp.
	 * @param  mixed     $metadata    Worktree metadata.
	 * @param  array     $disk_fields Disk summary fields.
	 * @param  \WP_Error $error       Probe error.
	 * @return array<string,mixed>
	 */
	private function build_worktree_probe_failure_skip( string $handle, string $repo, string $branch, string $path, mixed $created_at, mixed $metadata, array $disk_fields, \WP_Error $error ): array {
		$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $path, $error, 'cleanup safety probe', 'leaving in place');

		return array_merge(
			array(
				'handle'     => $handle,
				'repo'       => $repo,
				'branch'     => $branch,
				'path'       => $path,
				'created_at' => $created_at,
				'metadata'   => $metadata,
			),
			$diagnostic,
			$disk_fields
		);
	}

	/**
	 * Classify a failed git safety probe without weakening cleanup safety.
	 *
	 * @param  string    $handle          Worktree handle.
	 * @param  string    $repo            Repo name from the inventory row.
	 * @param  string    $path            Worktree path.
	 * @param  \WP_Error $error           Probe error.
	 * @param  string    $probe_label     Human-readable probe label.
	 * @param  string    $safety_outcome  Human-readable safety outcome.
	 * @return array<string,mixed>
	 */
	private function classify_worktree_git_probe_failure( string $handle, string $repo, string $path, \WP_Error $error, string $probe_label, string $safety_outcome ): array {
		$message    = $error->get_error_message();
		$error_code = $this->get_wp_error_code($error);
		$error_data = $this->get_wp_error_data($error);
		$output     = is_array($error_data) ? (string) ( $error_data['output'] ?? '' ) : '';
		$haystack   = strtolower($message . "\n" . $output);

		if ( $this->is_git_timeout_error($error) ) {
			return array(
				'reason_code'    => 'probe_timeout',
				'reason'         => sprintf('%s timed out - %s: %s', $probe_label, $safety_outcome, $message),
				'hint'           => 'Retry after reducing cleanup scope or increasing the git probe timeout budget.',
				'git_error_code' => $error_code,
			);
		}

		$parsed = $this->parse_handle($handle);
		if ( ! empty($parsed['is_worktree']) && '' !== $repo && $parsed['repo'] !== $repo ) {
			return array(
				'reason_code'    => 'owner_repo_mismatch',
				'reason'         => sprintf('worktree handle repo (%s) does not match inventory repo (%s) - %s: %s', $parsed['repo'], $repo, $safety_outcome, $message),
				'hint'           => 'Run workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json to review stale registry ownership, then prune or repair the mismatched row.',
				'handle_repo'    => $parsed['repo'],
				'inventory_repo' => $repo,
				'git_error_code' => $error_code,
			);
		}

		if ( str_contains($haystack, '/.git/worktrees/') || str_contains($haystack, '\\.git\\worktrees\\') ) {
			return array(
				'reason_code'    => 'stale_worktree_marker',
				'reason'         => sprintf('git worktree metadata marker is stale or missing - %s: %s', $safety_outcome, $message),
				'hint'           => 'Inspect the worktree gitfile/common-dir and run git worktree prune or workspace worktree prune after confirming the row is stale.',
				'git_error_code' => $error_code,
			);
		}

		if (
			str_contains($haystack, 'not a git repository')
			|| str_contains($haystack, 'not a git worktree')
			|| str_contains($haystack, 'invalid gitfile format')
			|| str_contains($haystack, 'unable to read')
			|| ( str_contains($haystack, 'no such file or directory') && str_contains($haystack, '.git') )
		) {
			return array(
				'reason_code'    => 'broken_worktree_metadata',
				'reason'         => sprintf('git worktree metadata is broken - %s: %s', $safety_outcome, $message),
				'hint'           => 'Inspect the worktree path and git metadata, then repair metadata or prune the stale registry entry before rerunning cleanup.',
				'git_error_code' => $error_code,
			);
		}

		return array(
			'reason_code'    => 'git_probe_failed',
			'reason'         => sprintf('%s failed - %s: %s', $probe_label, $safety_outcome, $message),
			'hint'           => 'Inspect the git probe error before rerunning cleanup; this was not an execution timeout.',
			'git_error_code' => $error_code,
		);
	}

	/**
	 * Get a WP_Error code across real WordPress and smoke-test stubs.
	 *
	 * @param  \WP_Error $error Error object.
	 * @return string
	 */
	private function get_wp_error_code( \WP_Error $error ): string {
		// @phpstan-ignore-next-line Smoke tests provide a minimal WP_Error stub.
		return method_exists($error, 'get_error_code') ? (string) $error->get_error_code() : (string) ( $error->code ?? '' );
	}

	/**
	 * Get WP_Error data across real WordPress and smoke-test stubs.
	 *
	 * @param  \WP_Error $error Error object.
	 * @return mixed
	 */
	private function get_wp_error_data( \WP_Error $error ): mixed {
		// @phpstan-ignore-next-line Smoke tests provide a minimal WP_Error stub.
		return method_exists($error, 'get_error_data') ? $error->get_error_data() : ( $error->data ?? null );
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
	 * @param  array $opts Options: dry_run, limit, older_than, sort, force, discard_unpushed, via_jobs, source.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_bounded_cleanup_eligible_apply( array $opts = array() ): array|\WP_Error {
		$started_at                = microtime(true);
		$dry_run                   = ! empty($opts['dry_run']);
		$force                     = ! empty($opts['force']);
		$discard_unpushed          = ! empty($opts['discard_unpushed']);
		$via_jobs                  = ! empty($opts['via_jobs']);
		$include_repaired_metadata = ! empty($opts['include_repaired_metadata']);
		$older_than                = isset($opts['older_than']) ? trim( (string) $opts['older_than']) : '';
		$sort                      = isset($opts['sort']) ? trim( (string) $opts['sort']) : 'age';
		$source                    = isset($opts['source']) ? trim( (string) $opts['source']) : 'workspace_bounded_cleanup_eligible_apply';
		$remove_timeout_seconds    = $this->normalize_worktree_cleanup_remove_timeout($opts['remove_timeout'] ?? null);
		if ( is_wp_error($remove_timeout_seconds) ) {
			return $remove_timeout_seconds;
		}

		$limit = isset($opts['limit']) ? (int) $opts['limit'] : 25;
		if ( $limit < 1 ) {
			return new \WP_Error('invalid_bounded_cleanup_eligible_limit', 'Bounded cleanup-eligible apply limit must be a positive integer.', array( 'status' => 400 ));
		}
		// Hard ceiling keeps a single bounded batch genuinely bounded — operators
		// who want more should run multiple batches or fall through to the full
		// retention/job-backed path.
		$limit = min($limit, 200);

		if ( '' !== $sort && ! in_array($sort, array( 'size', 'age' ), true) ) {
			return new \WP_Error('invalid_cleanup_sort', 'Invalid bounded cleanup-eligible apply sort. Use size or age.', array( 'status' => 400 ));
		}

		if ( $via_jobs && $dry_run ) {
			return new \WP_Error('bounded_cleanup_eligible_apply_via_jobs_no_dry_run', 'Job-backed bounded cleanup-eligible apply cannot run as dry-run; use the synchronous dry-run path to review candidates first.', array( 'status' => 400 ));
		}

		// Reuse the cheap inventory_only review path for candidate discovery so
		// the bounded cleanup-eligible apply never triggers full worktree_list / fetch / GitHub
		// API work just to plan. This intentionally does not honor `apply_plan`
		// — bounded cleanup-eligible apply IS the apply path; no separate plan file is needed.
		$inventory = $this->worktree_cleanup_inventory_only($older_than, $sort, $include_repaired_metadata);
		if ( $inventory instanceof \WP_Error ) {
			return $inventory;
		}

		$all_candidates    = array_values( (array) ( $inventory['candidates'] ?? array() ));
		$inventory_skipped = array_values( (array) ( $inventory['skipped'] ?? array() ));

		$batch    = array_slice($all_candidates, 0, $limit);
		$deferred = array_slice($all_candidates, $limit);

		$continuation            = array(
			'remaining_total'   => count($deferred),
			'remaining_handles' => array_values(array_filter(array_map(fn( $row ) => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '', $deferred))),
			'next_call_hint'    => count($deferred) > 0 ? sprintf('Run the bounded cleanup-eligible apply again to drain the next %d candidate(s).', min($limit, count($deferred))) : null,
			'inventory_skipped' => count($inventory_skipped),
			'limit_applied'     => $limit,
			'remove_timeout'    => $remove_timeout_seconds,
		);
		$active_no_signal_triage = WorktreeActiveNoSignalTriagePreview::build($inventory_skipped, min($limit, 25));

		if ( $dry_run ) {
			return array(
				'success'                 => true,
				'mode'                    => 'bounded_cleanup_eligible_apply',
				'dry_run'                 => true,
				'destructive'             => false,
				'workspace_path'          => $this->workspace_path,
				'generated_at'            => gmdate('c'),
				'candidates'              => $batch,
				'removed'                 => array(),
				'skipped'                 => $inventory_skipped,
				'summary'                 => array(
					'processed'       => count($batch),
					'removed'         => 0,
					'skipped'         => count($inventory_skipped),
					'bytes_reclaimed' => 0,
					'limit'           => $limit,
				),
				'continuation'            => $continuation,
				'active_no_signal_triage' => $active_no_signal_triage,
				'evidence'                => array(
					'elapsed_ms'       => (int) round(( microtime(true) - $started_at ) * 1000),
					'inventory_total'  => count($all_candidates),
					'planned_handles'  => array_values(array_filter(array_map(fn( $row ) => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '', $batch))),
					'discard_unpushed' => $discard_unpushed,
					'remove_timeout'   => $remove_timeout_seconds,
					'source'           => $source,
				),
			);
		}

		if ( $via_jobs ) {
			return $this->schedule_bounded_cleanup_eligible_chunks($batch, $deferred, $force, $source, $started_at, $continuation, $include_repaired_metadata, $remove_timeout_seconds, $discard_unpushed);
		}

		$processed            = 0;
		$processed_candidates = array();
		$removed              = array();
		$skipped              = $inventory_skipped;
		$bytes_reclaimed      = 0;
		$timeout_handles      = array();
		$discarded_unpushed   = array();

		foreach ( $batch as $candidate ) {
			++$processed;
			$revalidated = $this->revalidate_bounded_cleanup_eligible_candidate($candidate, $force, false, $discard_unpushed);
			if ( isset($revalidated['skipped']) ) {
				$processed_candidates[] = $this->build_bounded_cleanup_processed_candidate($candidate, 'skipped', $revalidated['skipped']);
				$skipped[]              = $revalidated['skipped'];
				continue;
			}

			$validated = $revalidated;
			$repo      = (string) ( $validated['repo'] ?? '' );
			$branch    = (string) ( $validated['branch'] ?? '' );
			$wt_path   = (string) ( $validated['path'] ?? '' );
			$size      = (int) ( $validated['size_bytes'] ?? 0 );
			if ( $size <= 0 ) {
				$measured = $this->estimate_path_size_bytes($wt_path);
				$size     = null === $measured ? 0 : (int) $measured;
			}

			$remove = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$repo,
				function () use ( $repo, $branch, $wt_path, $force, $remove_timeout_seconds ) {
					$result = $this->remove_worktree_by_path($repo, $branch, $wt_path, $force, $remove_timeout_seconds);
					if ( is_wp_error($result) ) {
							return $result;
					}

					$primary_path = $this->get_primary_path($repo);
					if ( '' !== $branch ) {
						$delete = $this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($branch)), self::CLEANUP_GIT_PROBE_TIMEOUT);
						if ( is_wp_error($delete) ) {
							// Branch deletion failure is non-fatal: the worktree is
							// gone, the local branch may still be useful or may have
							// already been pruned. Bubble up the original removal.
							return $result;
						}
					}

					return $result;
				}
			);

			if ( is_wp_error($remove) ) {
				$skip                   = $this->build_worktree_remove_failure_skip($candidate, $remove, $remove_timeout_seconds);
				$processed_candidates[] = $this->build_bounded_cleanup_processed_candidate($validated, 'skipped', $skip);
				$skipped[]              = $skip;
				if ( 'remove_timeout' === (string) ( $skip['reason_code'] ?? '' ) ) {
					$timeout_handles[] = (string) ( $skip['handle'] ?? '' );
				}
				continue;
			}

			$unpushed_count         = (int) ( $validated['unpushed'] ?? 0 );
			$removed_row            = array_merge(
				array(
					'handle'                     => (string) ( $candidate['handle'] ?? '' ),
					'repo'                       => $repo,
					'branch'                     => $branch,
					'path'                       => $wt_path,
					'size_bytes'                 => $size,
					'reason_code'                => 'cleanup_eligible',
					'unpushed_before_remove'     => $unpushed_count,
					'discarded_unpushed_commits' => $discard_unpushed && $unpushed_count > 0,
					'path_exists_after'          => is_dir($wt_path),
				),
				is_array($candidate['metadata'] ?? null) ? array( 'metadata' => $candidate['metadata'] ) : array()
			);
			$removed[]              = $removed_row;
			$processed_candidates[] = $this->build_bounded_cleanup_processed_candidate($validated, 'removed', $removed_row);
			if ( $discard_unpushed && $unpushed_count > 0 ) {
				$discarded_unpushed[] = array(
					'handle'                 => (string) ( $candidate['handle'] ?? '' ),
					'repo'                   => $repo,
					'branch'                 => $branch,
					'path'                   => $wt_path,
					'unpushed_before_remove' => $unpushed_count,
					'path_exists_after'      => is_dir($wt_path),
				);
			}
			$bytes_reclaimed += max(0, $size);
		}

		if ( array() !== array_filter($timeout_handles) ) {
			$continuation['timeout_handles']        = array_values(array_filter($timeout_handles));
			$continuation['timeout_resume_command'] = $this->build_bounded_cleanup_resume_command($limit, $opts, $this->next_worktree_cleanup_remove_timeout($remove_timeout_seconds));
			$continuation['timeout_hint']           = 'Removal timed out after passing safety checks; rerun with a larger --remove-timeout to resume the remaining cleanup-eligible rows.';
		}

		// Best-effort prune in case any registry rows are now stale.
		$this->worktree_prune();

		return array(
			'success'                 => true,
			'mode'                    => 'bounded_cleanup_eligible_apply',
			'dry_run'                 => false,
			'destructive'             => true,
			'workspace_path'          => $this->workspace_path,
			'generated_at'            => gmdate('c'),
			'planned_candidates'      => $batch,
			'candidates'              => $processed_candidates,
			'removed'                 => $removed,
			'skipped'                 => $skipped,
			'summary'                 => array(
				'processed'          => $processed,
				'removed'            => count($removed),
				'skipped'            => count($skipped),
				'bytes_reclaimed'    => $bytes_reclaimed,
				'limit'              => $limit,
				'discarded_unpushed' => count($discarded_unpushed),
			),
			'continuation'            => $continuation,
			'active_no_signal_triage' => $active_no_signal_triage,
			'evidence'                => array(
				'elapsed_ms'         => (int) round(( microtime(true) - $started_at ) * 1000),
				'inventory_total'    => count($all_candidates),
				'removed_handles'    => array_values(array_filter(array_map(fn( $row ) => (string) $row['handle'], $removed))),
				'skipped_handles'    => array_values(array_filter(array_map(fn( $row ) => (string) ( $row['handle'] ?? '' ), $skipped))),
				'discard_unpushed'   => $discard_unpushed,
				'discarded_unpushed' => $discarded_unpushed,
				'remove_timeout'     => $remove_timeout_seconds,
				'source'             => $source,
			),
		);
	}

	/**
	 * Attach final revalidation/removal outcome to a processed bounded cleanup row.
	 *
	 * @param  array<string,mixed> $candidate Planned or revalidated candidate row.
	 * @param  string              $action    Final action: removed or skipped.
	 * @param  array<string,mixed> $outcome   Fresh removal or blocker row.
	 * @return array<string,mixed>
	 */
	private function build_bounded_cleanup_processed_candidate( array $candidate, string $action, array $outcome ): array {
		$row = $candidate;
		foreach ( array( 'dirty', 'unpushed', 'path', 'size_bytes' ) as $field ) {
			if ( array_key_exists($field, $outcome) ) {
				$row[ $field ] = $outcome[ $field ];
			}
		}

		$row['final_action']      = $action;
		$row['final_reason_code'] = (string) ( $outcome['reason_code'] ?? $action );
		if ( isset($outcome['reason']) ) {
			$row['final_reason'] = (string) $outcome['reason'];
		}

		return $row;
	}

	/**
	 * Apply DB-backed cleanup rows without rebuilding a full workspace scan.
	 *
	 * @param  array<int,array<string,mixed>> $candidates Reviewed cleanup rows.
	 * @param  bool                           $force      Whether dirty worktrees may be removed.
	 * @param  float                          $started_at Start timestamp.
	 * @return array<string,mixed>
	 */
	private function apply_worktree_cleanup_plan_candidates( array $candidates, bool $force, float $started_at, bool $stale_liveness_only = false, int $remove_timeout_seconds = self::CLEANUP_GIT_REMOVE_TIMEOUT, bool $discard_unpushed = false ): array {
		$processed       = 0;
		$removed         = array();
		$skipped         = array();
		$bytes_reclaimed = 0;

		foreach ( $candidates as $candidate ) {
			++$processed;
			$revalidated = $this->revalidate_bounded_cleanup_eligible_candidate($candidate, $force, $stale_liveness_only, $discard_unpushed);
			if ( isset($revalidated['skipped']) ) {
				$skipped[] = $revalidated['skipped'];
				continue;
			}

			$validated = $revalidated;
			$repo      = (string) ( $validated['repo'] ?? '' );
			$branch    = (string) ( $validated['branch'] ?? '' );
			$wt_path   = (string) ( $validated['path'] ?? '' );
			$size      = (int) ( $validated['size_bytes'] ?? 0 );
			if ( $size <= 0 ) {
				$measured = $this->estimate_path_size_bytes($wt_path);
				$size     = null === $measured ? 0 : (int) $measured;
			}

			$remove = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$repo,
				function () use ( $repo, $branch, $wt_path, $force, $remove_timeout_seconds ) {
					$result = $this->remove_worktree_by_path($repo, $branch, $wt_path, $force, $remove_timeout_seconds);
					if ( is_wp_error($result) ) {
						return $result;
					}

					$primary_path = $this->get_primary_path($repo);
					if ( '' !== $branch ) {
						$delete = $this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($branch)), self::CLEANUP_GIT_PROBE_TIMEOUT);
						if ( is_wp_error($delete) ) {
							return $result;
						}
					}

					return $result;
				}
			);

			if ( is_wp_error($remove) ) {
				$skipped[] = array_merge(
					$this->build_worktree_remove_failure_skip($candidate, $remove, $remove_timeout_seconds),
					array( 'size_bytes' => $size )
				);
				continue;
			}

			$removed[]        = array_merge(
				$validated,
				array(
					'size_bytes'        => $size,
					'removed_path'      => $wt_path,
					'path_exists_after' => is_dir($wt_path),
				)
			);
			$bytes_reclaimed += max(0, $size);
		}

		return array(
			'success'    => true,
			'dry_run'    => false,
			'candidates' => $candidates,
			'removed'    => $removed,
			'skipped'    => $skipped,
			'summary'    => array(
				'processed'       => $processed,
				'removed'         => count($removed),
				'skipped'         => count($skipped),
				'bytes_reclaimed' => $bytes_reclaimed,
			),
			'evidence'   => array(
				'elapsed_ms'        => (int) round(( microtime(true) - $started_at ) * 1000),
				'direct_apply_plan' => true,
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
	 * @param  array $candidate Inventory candidate row.
	 * @param  bool  $force     Allow dirty worktrees.
		 * @return array<string,mixed>
		 */
	private function revalidate_bounded_cleanup_eligible_candidate( array $candidate, bool $force, bool $stale_liveness_only = false, bool $discard_unpushed = false ): array {
		$handle   = (string) ( $candidate['handle'] ?? '' );
		$repo     = (string) ( $candidate['repo'] ?? '' );
		$branch   = (string) ( $candidate['branch'] ?? '' );
		$wt_path  = (string) ( $candidate['path'] ?? '' );
		$liveness = (string) ( $candidate['liveness'] ?? '' );

		if ( $stale_liveness_only && 'stale' !== $liveness ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'active_or_recent_worktree',
					'reason'      => sprintf('planned row liveness is %s; stale-worktrees apply only removes stale/inactive worktrees', '' !== $liveness ? $liveness : 'unknown'),
					'liveness'    => $liveness,
				),
			);
		}

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
					'reason'         => 'missing inventory metadata for bounded cleanup-eligible apply: ' . implode(', ', $missing_fields),
					'missing_fields' => $missing_fields,
				),
			);
		}

		// Containment: path must be inside the workspace root and resolve as a
		// real worktree marker, not a primary's `.git` directory.
		$validation = $this->validate_containment($wt_path, $this->workspace_path);
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

		$real_path = (string) ( $validation['real_path'] ?? '' );
		if ( '' === $real_path || ! is_dir($real_path) ) {
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

		$git_marker = rtrim($real_path, '/') . '/.git';
		if ( is_dir($git_marker) ) {
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
		if ( ! is_file($git_marker) ) {
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

		if ( 'broken_orphan_worktree_marker' === (string) ( $candidate['signal'] ?? $candidate['reason_code'] ?? '' ) ) {
			$broken = $this->classify_broken_orphan_worktree_marker($real_path);
			if ( null === $broken ) {
				return array(
					'skipped' => array(
						'handle'      => $handle,
						'repo'        => $repo,
						'branch'      => $branch,
						'path'        => $wt_path,
						'reason_code' => 'plan_not_current',
						'reason'      => 'planned broken orphan marker no longer points at missing Git worktree metadata',
					),
				);
			}

			return array_merge(
				$candidate,
				array(
					'branch'             => '',
					'path'               => $real_path,
					'broken_target_path' => $broken['gitdir'],
				)
			);
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path . '/.git') ) {
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

		$submodule_skip = $this->build_submodule_worktree_cleanup_skip($candidate, $real_path);
		if ( null !== $submodule_skip ) {
			return array( 'skipped' => $submodule_skip );
		}

		// Dirty gate: cheap porcelain call, bounded by the cleanup git timeout.
		$dirty = $this->run_git($real_path, 'status --porcelain --untracked-files=normal', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($dirty) ) {
			$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $real_path, $dirty, 'dirty-state probe', 'refusing bounded cleanup-eligible apply');
			return array(
				'skipped' => array_merge(
					array(
						'handle' => $handle,
						'repo'   => $repo,
						'branch' => $branch,
						'path'   => $wt_path,
					),
					$diagnostic
				),
			);
		}

		$dirty_lines = trim( (string) ( $dirty['output'] ?? '' ));
		$dirty_count = '' === $dirty_lines ? 0 : substr_count($dirty_lines, "\n") + 1;
		// Unpushed gate: hard stop unless metadata was promoted by the reviewed
		// upstream-equivalent apply path and the same evidence still holds now.
		$unpushed = $this->count_unpushed_commits($real_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $unpushed instanceof \WP_Error ) {
			$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $real_path, $unpushed, 'unpushed-commit probe', 'refusing bounded cleanup-eligible apply');
			return array(
				'skipped' => array_merge(
					array(
						'handle' => $handle,
						'repo'   => $repo,
						'branch' => $branch,
						'path'   => $wt_path,
					),
					$diagnostic
				),
			);
		}

		$metadata                      = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : array();
		$cleanup_evidence              = is_array($metadata['cleanup_eligibility_evidence'] ?? null) ? $metadata['cleanup_eligibility_evidence'] : array();
		$allow_effective_clean_removal = false;
		if ( ( $dirty_count > 0 || $unpushed > 0 ) && 'upstream-equivalent-clean' === (string) ( $cleanup_evidence['signal'] ?? '' ) ) {
			$current_equivalence           = $this->build_current_effective_clean_cleanup_evidence($repo, $real_path);
			$allow_effective_clean_removal = ! is_wp_error($current_equivalence);
		}

		if ( $dirty_count > 0 && ! $force && ! $allow_effective_clean_removal ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'dirty_worktree',
					'reason'      => sprintf('working tree dirty (%d entries) — bounded cleanup-eligible apply refuses to override; rerun with force=true after review', $dirty_count),
					'dirty'       => $dirty_count,
					'unpushed'    => (int) $unpushed,
				),
			);
		}

		if ( $unpushed > 0 && ! $allow_effective_clean_removal && ! $discard_unpushed ) {
			return array(
				'skipped' => array(
					'handle'      => $handle,
					'repo'        => $repo,
					'branch'      => $branch,
					'path'        => $wt_path,
					'reason_code' => 'unpushed_commits',
					'reason'      => sprintf('%d unpushed commit(s) — bounded cleanup-eligible apply refuses to remove without discard_unpushed=true', $unpushed),
					'dirty'       => $dirty_count,
					'unpushed'    => $unpushed,
				),
			);
		}

		return array_merge(
			$candidate,
			array(
				'path'     => $real_path,
				'dirty'    => $dirty_count,
				'unpushed' => (int) $unpushed,
			)
		);
	}

	/**
	 * Schedule each bounded-cleanup-eligible-apply candidate as its own cleanup chunk job.
	 *
	 * Single-row chunks keep cleanup conservative and lock-friendly while the
	 * existing `worktree_cleanup_chunk` task handles per-row revalidation,
	 * removal, and evidence the same way as the retention path.
	 *
	 * @param  array<int,array<string,mixed>> $batch        Candidate rows in this bounded batch.
	 * @param  array<int,array<string,mixed>> $deferred     Candidates not in this batch.
	 * @param  bool                           $force        Whether to forward force.
	 * @param  string                         $source       Caller marker.
	 * @param  float                          $started_at   Start timestamp.
	 * @param  array<string,mixed>            $continuation Continuation envelope.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function schedule_bounded_cleanup_eligible_chunks( array $batch, array $deferred, bool $force, string $source, float $started_at, array $continuation, bool $include_repaired_metadata = false, int $remove_timeout_seconds = self::CLEANUP_GIT_REMOVE_TIMEOUT, bool $discard_unpushed = false ): array|\WP_Error {
		if ( ! class_exists('\DataMachine\Engine\Tasks\TaskScheduler') ) {
			return new \WP_Error('task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable; cannot schedule bounded cleanup-eligible apply chunks.', array( 'status' => 500 ));
		}

		if ( array() === $batch ) {
			return array(
				'success'        => true,
				'mode'           => 'bounded_cleanup_eligible_apply',
				'dry_run'        => false,
				'destructive'    => false,
				'job_backed'     => true,
				'workspace_path' => $this->workspace_path,
				'generated_at'   => gmdate('c'),
				'candidates'     => array(),
				'removed'        => array(),
				'skipped'        => array(),
				'summary'        => array(
					'processed'       => 0,
					'removed'         => 0,
					'skipped'         => 0,
					'bytes_reclaimed' => 0,
					'scheduled_jobs'  => 0,
				),
				'continuation'   => $continuation,
				'evidence'       => array(
					'elapsed_ms' => (int) round(( microtime(true) - $started_at ) * 1000),
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
			if ( isset($candidate['size_bytes']) ) {
				$row['size_bytes'] = (int) $candidate['size_bytes'];
			}
			if ( isset($candidate['metadata']) ) {
				$row['metadata'] = $candidate['metadata'];
			}
			$item_params[] = array(
				'chunk_type'                => 'worktrees',
				'chunk_index'               => count($item_params),
				'rows'                      => array( $row ),
				'force'                     => $force,
				'discard_unpushed'          => $discard_unpushed,
				'skip_github'               => true,
				'include_repaired_metadata' => $include_repaired_metadata,
				'remove_timeout'            => $remove_timeout_seconds,
				'source'                    => $source,
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
			return new \WP_Error('bounded_cleanup_eligible_apply_schedule_failed', 'Failed to schedule bounded cleanup-eligible apply chunk jobs.', array( 'status' => 500 ));
		}

		return array(
			'success'        => true,
			'mode'           => 'bounded_cleanup_eligible_apply',
			'dry_run'        => false,
			'destructive'    => true,
			'job_backed'     => true,
			'workspace_path' => $this->workspace_path,
			'generated_at'   => gmdate('c'),
			'candidates'     => $batch,
			'removed'        => array(),
			'skipped'        => array(),
			'summary'        => array(
				'processed'       => count($batch),
				'removed'         => 0,
				'skipped'         => 0,
				'bytes_reclaimed' => 0,
				'scheduled_jobs'  => count($item_params),
			),
			'continuation'   => $continuation,
			'evidence'       => array(
				'elapsed_ms'       => (int) round(( microtime(true) - $started_at ) * 1000),
				'planned_handles'  => array_values(array_filter(array_map(fn( $row ) => (string) ( $row['handle'] ?? '' ), $batch))),
				'batch_job_id'     => (int) ( $batch_result['batch_job_id'] ?? 0 ),
				'direct_job_ids'   => $batch_result['job_ids'] ?? array(),
				'discard_unpushed' => $discard_unpushed,
				'remove_timeout'   => $remove_timeout_seconds,
				'source'           => $source,
			),
		);
	}

	/**
	 * Normalize a cleanup worktree removal timeout.
	 *
	 * @param  mixed $value Raw option value.
	 * @return int|\WP_Error
	 */
	private function normalize_worktree_cleanup_remove_timeout( mixed $value ): int|\WP_Error {
		if ( null === $value || '' === $value ) {
			return self::CLEANUP_GIT_REMOVE_TIMEOUT;
		}

		$timeout = (int) $value;
		if ( $timeout < self::CLEANUP_GIT_PROBE_TIMEOUT ) {
			return new \WP_Error('invalid_cleanup_remove_timeout', sprintf('Cleanup remove timeout must be at least %d seconds.', self::CLEANUP_GIT_PROBE_TIMEOUT), array( 'status' => 400 ));
		}

		return min($timeout, 3600);
	}

	/**
	 * Suggest a larger timeout while keeping reruns bounded.
	 *
	 * @param  int $timeout Current timeout in seconds.
	 * @return int
	 */
	private function next_worktree_cleanup_remove_timeout( int $timeout ): int {
		return min(3600, max($timeout + 30, $timeout * 2));
	}

	/**
	 * Build a resumable bounded cleanup command for timeout rows.
	 *
	 * @param  int   $limit                  Candidate limit.
	 * @param  array $opts                   Original apply options.
	 * @param  int   $remove_timeout_seconds Timeout to include.
	 * @return string
	 */
	private function build_bounded_cleanup_resume_command( int $limit, array $opts, int $remove_timeout_seconds ): string {
		$parts = array(
			'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply',
			'--limit=' . max(1, $limit),
			'--remove-timeout=' . $remove_timeout_seconds,
		);

		if ( ! empty($opts['force']) ) {
			$parts[] = '--force';
		}
		if ( ! empty($opts['discard_unpushed']) ) {
			$parts[] = '--discard-unpushed';
		}
		if ( ! empty($opts['include_repaired_metadata']) ) {
			$parts[] = '--include-repaired-metadata';
		}
		if ( isset($opts['older_than']) && '' !== trim( (string) $opts['older_than']) ) {
			$parts[] = '--older-than=' . escapeshellarg(trim( (string) $opts['older_than']));
		}
		if ( isset($opts['sort']) && '' !== trim( (string) $opts['sort']) ) {
			$parts[] = '--sort=' . escapeshellarg(trim( (string) $opts['sort']));
		}

		return implode(' ', $parts);
	}

	/**
	 * Build a stable skipped row for git worktree removal failures.
	 *
	 * @param  array     $candidate              Candidate row.
	 * @param  \WP_Error $error                  Removal error.
	 * @param  int       $remove_timeout_seconds Timeout used.
	 * @return array<string,mixed>
	 */
	private function build_worktree_remove_failure_skip( array $candidate, \WP_Error $error, int $remove_timeout_seconds ): array {
		$is_timeout = $this->is_git_timeout_error($error);
		$row        = array(
			'handle'      => (string) ( $candidate['handle'] ?? '' ),
			'repo'        => (string) ( $candidate['repo'] ?? '' ),
			'branch'      => (string) ( $candidate['branch'] ?? '' ),
			'path'        => (string) ( $candidate['path'] ?? '' ),
			'reason_code' => $is_timeout ? 'remove_timeout' : 'remove_failed',
			'reason'      => ( $is_timeout ? sprintf('remove timed out after %d second(s): ', $remove_timeout_seconds) : 'remove failed: ' ) . $error->get_error_message(),
			'created_at'  => $candidate['created_at'] ?? null,
			'metadata'    => $candidate['metadata'] ?? null,
		);

		if ( isset($candidate['size_bytes']) ) {
			$row['size_bytes'] = $candidate['size_bytes'];
		}

		if ( $is_timeout ) {
			$row['remove_timeout']       = $remove_timeout_seconds;
			$row['hint']                 = 'Safety checks passed, but git worktree remove exceeded the cleanup removal timeout. Rerun bounded cleanup with a larger --remove-timeout value.';
			$row['continuation_command'] = $this->build_bounded_cleanup_resume_command(25, array(), $this->next_worktree_cleanup_remove_timeout($remove_timeout_seconds));
		}

		return $row;
	}

	/**
	 * Build stable cleanup counts for CLI and automation consumers.
	 *
	 * @param  array<int,array> $candidates Candidate rows.
	 * @param  array<int,array> $removed    Removed rows.
	 * @param  array<int,array> $skipped    Skipped rows.
	 * @param  array|null       $age_filter Optional age filter summary.
	 * @param  string           $candidate_bucket Bucket to use for candidate rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_cleanup_summary(
		array $candidates,
		array $removed,
		array $skipped,
		?array $age_filter = null,
		string $candidate_bucket = WorktreeCleanupClassifier::BUCKET_SAFE_TO_REMOVE_NOW
	): array {
		$skipped_by_reason    = array();
		$candidates_by_signal = array();
		$stale_reasons        = array();
		$liveness             = array();
		$size_by_repo         = array();
		$artifact_by_repo     = array();
		$total_size_bytes     = 0;
		$total_artifact_bytes = 0;
		$all_rows             = array_merge($candidates, $removed, $skipped);

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
			$size_bytes     = isset($row['size_bytes']) ? (int) $row['size_bytes'] : 0;
			$artifact_bytes = isset($row['artifact_size_bytes']) ? (int) $row['artifact_size_bytes'] : 0;
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

		ksort($skipped_by_reason);
		ksort($candidates_by_signal);
		ksort($stale_reasons);
		ksort($liveness);
		arsort($size_by_repo);
		arsort($artifact_by_repo);

		$candidate_count = count($candidates);
		$summary         = array(
			'would_remove'                      => $candidate_count,
			'inventory_cleanup_candidate_count' => WorktreeCleanupClassifier::BUCKET_CLEANUP_ELIGIBLE_UNPROBED === $candidate_bucket ? $candidate_count : 0,
			'fresh_safe_removable_count'        => WorktreeCleanupClassifier::BUCKET_SAFE_TO_REMOVE_NOW === $candidate_bucket ? $candidate_count : 0,
			'removed'                           => count($removed),
			'skipped'                           => count($skipped),
			'skipped_by_reason'                 => $skipped_by_reason,
			'skipped_next_commands'             => $this->worktree_cleanup_skipped_next_commands($skipped_by_reason),
			'cleanup_buckets'                   => $this->worktree_cleanup_buckets($candidate_count, $candidates_by_signal, $skipped_by_reason, $candidate_bucket),
			'candidates_by_signal'              => $candidates_by_signal,
			'stale_reasons'                     => $stale_reasons,
			'liveness'                          => $liveness,
			'total_size_bytes'                  => $total_size_bytes,
			'artifact_size_bytes'               => $total_artifact_bytes,
			'size_by_repo'                      => $size_by_repo,
			'artifact_size_by_repo'             => $artifact_by_repo,
			'top_by_size'                       => $this->summarize_top_worktree_rows($all_rows, 'size_bytes'),
			'top_by_age'                        => $this->summarize_top_worktree_rows($all_rows, 'age_days'),
		);

		if ( null !== $age_filter ) {
			unset($age_filter['threshold_unix']);
			$summary['age_filter'] = $age_filter;
		}

		return $summary;
	}

	/**
	 * Remove duplicate rows from a cleanup scan while preserving inventory order.
	 *
	 * Worktree inventory can contain both git-discovered and DMC-registry views of
	 * the same directory. Cleanup classification should report one candidate or
	 * blocker per directory so high-volume summaries do not double-count bytes.
	 *
	 * @param  array<int,array<string,mixed>> $rows Worktree rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function dedupe_worktree_cleanup_scan_rows( array $rows ): array {
		$seen   = array();
		$result = array();
		foreach ( $rows as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			$path   = (string) ( $row['path'] ?? '' );
			$key    = '' !== $handle || '' !== $path ? $handle . '|' . $path : wp_json_encode($row);
			if ( isset($seen[ $key ]) ) {
				continue;
			}

			$seen[ $key ] = true;
			$result[]     = $row;
		}

		return $result;
	}

	/**
	 * Build a conservative skip row for worktrees that declare submodules.
	 *
	 * @param  array<string,mixed> $row       Candidate row.
	 * @param  string              $real_path Optional resolved worktree path.
	 * @return array<string,mixed>|null
	 */
	private function build_submodule_worktree_cleanup_skip( array $row, string $real_path = '' ): ?array {
		$path = '' !== $real_path ? $real_path : (string) ( $row['path'] ?? '' );
		if ( ! $this->worktree_declares_submodules($path) ) {
			return null;
		}

		$handle    = (string) ( $row['handle'] ?? '' );
		$review    = sprintf('git -C %s submodule status --recursive', escapeshellarg($path));
		$remediate = '' !== $handle
		? sprintf(
			'git -C %s submodule deinit --all --force && studio wp datamachine-code workspace worktree remove %s --force',
			escapeshellarg($path),
			escapeshellarg($handle)
		)
		: '';

		return array_merge(
			$row, array(
				'path'                => $path,
				'reason_code'         => 'submodule_worktree',
				'reason'              => 'worktree declares submodules; bounded cleanup leaves it in place because git cannot remove submodule-containing worktrees through the normal cleanup path',
				'hint'                => '' !== $remediate ? 'Review submodules first, then deinitialize them if appropriate and run the remediation command.' : 'Review submodules before attempting manual worktree removal.',
				'review_command'      => $review,
				'remediation_command' => $remediate,
			)
		);
	}

	/**
	 * Detect submodule declarations without mutating the worktree.
	 *
	 * @param  string $path Worktree path.
	 * @return bool
	 */
	private function worktree_declares_submodules( string $path ): bool {
		if ( '' === $path || ! is_dir($path) ) {
			return false;
		}

		$gitmodules = rtrim($path, '/') . '/.gitmodules';
		return is_file($gitmodules) && filesize($gitmodules) > 0;
	}

	/**
	 * Build stable high-level cleanup buckets for plan/report consumers.
	 *
	 * @param  int               $candidate_count      Candidate row count.
	 * @param  array<string,int> $candidates_by_signal Candidate signal counts.
	 * @param  array<string,int> $skipped_by_reason    Skipped reason counts.
	 * @param  string            $candidate_bucket     Bucket to use for candidate rows.
	 * @return array<string,int>
	 */
	private function worktree_cleanup_buckets(
		int $candidate_count,
		array $candidates_by_signal,
		array $skipped_by_reason,
		string $candidate_bucket = WorktreeCleanupClassifier::BUCKET_SAFE_TO_REMOVE_NOW
	): array {
		return WorktreeCleanupClassifier::buckets($candidate_count, $candidates_by_signal, $skipped_by_reason, $candidate_bucket);
	}

	/**
	 * Build the review/apply command set for stale active/no-merge-signal rows.
	 *
	 * @param  int $limit  Review page size.
	 * @param  int $offset Review page offset.
	 * @return array<string,string>
	 */
	private function build_active_no_signal_next_commands( int $limit, int $offset ): array {
		$base = sprintf('--limit=%d --offset=%d --format=json', max(1, $limit), max(0, $offset));

		return array(
			'review_command'                 => 'studio wp datamachine-code workspace worktree active-no-signal-report ' . $base,
			'finalized_apply_dry_run'        => 'studio wp datamachine-code workspace worktree active-no-signal-finalized-apply --dry-run ' . $base,
			'equivalent_clean_apply_dry_run' => 'studio wp datamachine-code workspace worktree active-no-signal-equivalent-clean-apply --dry-run ' . $base,
			'merged_apply_dry_run'           => 'studio wp datamachine-code workspace worktree active-no-signal-merged-apply --dry-run ' . $base,
		);
	}

	/**
	 * Build lightweight evidence explaining why cleanup produced no merge signal.
	 *
	 * @param  string $primary_path Path to the primary checkout.
	 * @param  string $branch       Worktree branch.
	 * @param  bool   $skip_github  Whether GitHub PR lookup was skipped.
	 * @return array<string,mixed>
	 */
	private function build_no_merge_signal_evidence( string $primary_path, string $branch, bool $skip_github ): array {
		$evidence = array(
			'classification'         => 'no_cleanup_signal',
			'remote_branch'          => 'unknown',
			'local_default_relation' => 'unknown',
			'github_signal'          => $skip_github ? 'skipped' : 'not_found',
		);

		$remote_ref = 'refs/remotes/origin/' . $branch;
		$remote     = $this->run_git($primary_path, sprintf('rev-parse --verify --quiet %s', escapeshellarg($remote_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( ! is_wp_error($remote) ) {
			$evidence['remote_branch']  = 'still_exists';
			$evidence['classification'] = 'remote_branch_still_exists';
		} elseif ( $this->is_git_timeout_error($remote) ) {
			$evidence['remote_branch'] = 'probe_timeout';
			$evidence['remote_error']  = $remote->get_error_message();
		} else {
			$evidence['remote_branch'] = 'missing_or_untracked';
		}

		$default_ref = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_string($default_ref) && '' !== $default_ref ) {
			$evidence['default_ref'] = $default_ref;
			$outside                 = $this->run_git(
				$primary_path,
				sprintf('rev-list --count %s..%s', escapeshellarg($default_ref), escapeshellarg('refs/heads/' . $branch)),
				self::CLEANUP_GIT_PROBE_TIMEOUT
			);
			if ( ! is_wp_error($outside) ) {
				$outside_count                       = (int) trim( (string) ( $outside['output'] ?? '' ));
				$evidence['commits_outside_default'] = $outside_count;
				$evidence['local_default_relation']  = 0 === $outside_count ? 'default_contained' : 'has_unique_commits';
				$evidence['classification']          = 0 === $outside_count ? 'local_equivalent_default_contained' : $evidence['classification'];
			} elseif ( $this->is_git_timeout_error($outside) ) {
				$evidence['local_default_relation'] = 'probe_timeout';
				$evidence['local_default_error']    = $outside->get_error_message();
			}
		} elseif ( is_wp_error($default_ref) ) {
			$evidence['local_default_relation'] = 'probe_timeout';
			$evidence['local_default_error']    = $default_ref->get_error_message();
		} else {
			$evidence['local_default_relation'] = 'default_ref_unavailable';
		}

		return $evidence;
	}

	/**
	 * Build copy-pasteable next commands for skipped cleanup buckets.
	 *
	 * @param  array<string,int> $skipped_by_reason Skipped reason counts.
	 * @return array<int,array<string,mixed>>
	 */
	private function worktree_cleanup_skipped_next_commands( array $skipped_by_reason ): array {
		$active_no_signal_commands  = $this->build_active_no_signal_next_commands(25, 0);
		$metadata_reconcile_command = sprintf(
			'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=%d --offset=0 --until-budget=%s --format=json',
			self::METADATA_RECONCILE_DEFAULT_LIMIT,
			self::METADATA_RECONCILE_DEFAULT_BUDGET
		);
		$templates                  = array(
			'artifact_only_dirty_worktree'       => array(
				'label'       => 'Review generated artifact cleanup separately',
				'command'     => 'studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run --format=json',
				'alternative' => 'studio wp datamachine-code workspace cleanup run --mode=artifacts --dry-run',
				'why'         => 'Dirty paths are limited to declared reconstructable artifact directories, so artifact cleanup can shed them without force-removing source worktrees.',
				'destructive' => false,
			),
			'dirty_worktree'                     => array(
				'label'       => 'Inspect dirty files before retrying cleanup',
				'command'     => 'git -C <worktree-path> status --short --branch --untracked-files=normal',
				'alternative' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=dirty_worktree --verbose --format=json',
				'why'         => 'Shows the exact dirty paths so operators can distinguish generated artifacts from source edits and decide whether to clean, commit, or preserve the worktree.',
				'destructive' => false,
			),
			'unpushed_commits'                   => array(
				'label'       => 'Inspect commits ahead of upstream before cleanup',
				'command'     => 'git -C <worktree-path> log --oneline --decorate @{u}..HEAD',
				'alternative' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=unpushed_commits --verbose --format=json',
				'why'         => 'Lists the protected commits so operators can push, merge, preserve, or intentionally abandon them before retrying cleanup.',
				'destructive' => false,
			),
			'stale_worktree_marker'              => array(
				'label'       => 'Preview stale git worktree marker pruning',
				'command'     => 'git -C <primary-path> worktree prune --dry-run --verbose',
				'alternative' => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --format=json',
				'why'         => 'Confirms stale git metadata before any prune or registry repair, keeping cleanup non-destructive by default.',
				'destructive' => false,
			),
			'primary_missing'                    => array(
				'label'       => 'Recover or adopt the missing primary checkout',
				'command'     => 'studio wp datamachine-code workspace show <repo>',
				'alternative' => 'Recreate with `studio wp datamachine-code workspace clone <remote-url> --name=<repo>` or adopt an existing checkout with `studio wp datamachine-code workspace adopt <path> --name=<repo>`.',
				'why'         => 'Git worktree removal must be routed through the primary checkout, so operators need primary path and remote evidence before repairing or preserving rows.',
				'destructive' => false,
			),
			'lifecycle_reconciliation_candidate' => array(
				'label'       => 'Run DMC-owned lifecycle reconciliation before cleanup eligibility',
				'command'     => 'studio wp datamachine-code workspace worktree cleanup --dry-run --format=json',
				'alternative' => 'studio wp datamachine-code workspace cleanup plan --mode=retention --format=json',
				'why'         => 'Runs full merge/PR signal detection so DMC can persist lifecycle state instead of relying on manual agent finalization.',
				'destructive' => false,
			),
			'needs_metadata_reconcile'           => array(
				'label'       => 'Repair missing lifecycle metadata in bounded batches',
				'command'     => $metadata_reconcile_command,
				'alternative' => 'Low-level apply still requires a reviewed --apply-plan=<file> until DB-backed cleanup runs land.',
				'why'         => 'Reconciliation writes lifecycle metadata so future inventory cleanup can classify these rows without a full scan.',
				'destructive' => false,
			),
			'active_no_signal'                   => array(
				'label'       => 'Batch-review active rows with no cleanup signal',
				'command'     => $active_no_signal_commands['review_command'],
				'alternative' => $active_no_signal_commands['merged_apply_dry_run'],
				'commands'    => $active_no_signal_commands,
				'why'         => 'Review-only evidence distinguishes active remote branches, merged/closed PRs, local default containment, patch-equivalence, and unavailable GitHub signal before any metadata is promoted.',
				'destructive' => false,
			),
			'no_merge_signal'                    => array(
				'label'       => 'Batch-review stale active rows with no merge signal',
				'command'     => $active_no_signal_commands['review_command'],
				'alternative' => $active_no_signal_commands['finalized_apply_dry_run'],
				'commands'    => $active_no_signal_commands,
				'why'         => 'Cleanup keeps these rows by default. The active/no-signal report gathers bounded evidence and routes finalized PR, merged-to-default, and patch-equivalent rows to reviewed metadata-promotion apply commands.',
				'destructive' => false,
			),
			'github_unknown'                     => array(
				'label'       => 'Retry cleanup or review active rows when GitHub signal was unavailable',
				'command'     => 'studio wp datamachine-code workspace worktree cleanup --dry-run --format=json',
				'alternative' => $active_no_signal_commands['review_command'],
				'commands'    => $active_no_signal_commands,
				'why'         => 'GitHub PR state could not be checked, so cleanup leaves the worktree in place until PR state is available or the active/no-signal review produces local evidence.',
				'destructive' => false,
			),
			'submodule_worktree'                 => array(
				'label'       => 'Review submodules before removing the worktree',
				'command'     => 'git -C <worktree-path> submodule status --recursive',
				'alternative' => 'After review, deinitialize submodules and rerun the DMC cleanup apply, or remove the worktree with an explicit git worktree remove command.',
				'why'         => 'Git refuses to remove worktrees containing submodules through the normal cleanup path; DMC leaves them in place until submodule state is explicitly reviewed.',
				'destructive' => false,
			),
			'detached_worktree'                  => array(
				'label'       => 'Review detached worktrees with stored branch metadata',
				'command'     => 'git -C <worktree-path> status --short --branch',
				'alternative' => 'Reattach the worktree to the stored branch or remove it manually after review.',
				'why'         => 'Git reports detached HEAD, so DMC will not treat stored branch metadata as a deletion target without explicit operator review.',
				'destructive' => false,
			),
			'detached_protected_branch'          => array(
				'label'       => 'Review detached protected-branch worktrees',
				'command'     => 'git -C <worktree-path> status --short --branch',
				'alternative' => 'Move any required state to the primary checkout, then remove the worktree manually if safe.',
				'why'         => 'Stored metadata points at a protected branch while Git reports detached HEAD; automatic cleanup would be ambiguous.',
				'destructive' => false,
			),
			'protected_base_branch_worktree'     => array(
				'label'       => 'Review protected/base branch worktrees',
				'command'     => 'git -C <worktree-path> status --short --branch',
				'alternative' => 'Move work back to the primary checkout or remove the worktree manually after review.',
				'why'         => 'Protected/base branches should be represented by primary checkouts, not removable feature worktrees.',
				'destructive' => false,
			),
			'repaired_metadata'                  => array(
				'label'       => 'Review repaired metadata with bounded safety probes',
				'command'     => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --include-repaired-metadata --limit=25 --older-than=7d',
				'alternative' => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --include-repaired-metadata --limit=25 --older-than=7d',
				'why'         => 'Runs bounded cleanup with fresh safety checks before any deletion.',
				'destructive' => true,
			),
			'requires_full_scan'                 => array(
				'label'       => 'Repair missing lifecycle metadata in bounded batches',
				'command'     => $metadata_reconcile_command,
				'alternative' => 'Low-level apply still requires a reviewed --apply-plan=<file> until DB-backed cleanup runs land.',
				'why'         => 'Reconciliation writes lifecycle metadata so future inventory cleanup no longer needs a full scan for those rows.',
				'destructive' => false,
			),
			'no_inventory_cleanup_signal'        => array(
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
	 * @param  bool        $is_worktree Whether the row is a worktree.
	 * @param  int         $dirty_files Dirty file count.
	 * @param  int|null    $age_days    Whole-day age.
	 * @param  string|null $created_at  Lifecycle timestamp.
	 * @return string|null Stale reason code.
	 */
	protected function detect_worktree_stale_reason( bool $is_worktree, int $dirty_files, ?int $age_days, ?string $created_at, array $probes = array() ): ?string {
		if ( ! $is_worktree ) {
			return null;
		}

		$status_probed = array_key_exists('status_probed', $probes) ? (bool) $probes['status_probed'] : true;

		if ( $status_probed && $dirty_files > 0 ) {
			return 'dirty';
		}

		if ( null === $created_at || '' === $created_at || null === $age_days ) {
			return 'missing_metadata';
		}

		$threshold = (int) apply_filters('datamachine_code_worktree_stale_days', 14);
		if ( $threshold > 0 && $age_days >= $threshold ) {
			return 'older_than_threshold';
		}

		return null;
	}

	/**
	 * Build disk and artifact metadata for a worktree/list row.
	 *
	 * @param  string      $repo        Primary repo name.
	 * @param  string      $path        Worktree path.
	 * @param  bool        $is_worktree Whether the row is a linked worktree.
	 * @param  string|null $created_at  Lifecycle creation timestamp.
	 * @param  array|null  $metadata    Stored lifecycle/context metadata.
	 * @return array<string,mixed>
	 */
	protected function build_worktree_disk_report( string $repo, string $path, bool $is_worktree, ?string $created_at, ?array $metadata ): array {
		$size_bytes      = $this->estimate_path_size_bytes($path);
		$last_touched_at = $this->detect_worktree_last_touched_at($path, $metadata, $created_at);
		$age_days        = $this->calculate_age_days($created_at);
		$artifacts       = $is_worktree ? $this->detect_worktree_artifacts($repo, $path) : array();
		$artifact_bytes  = array_sum(array_map(fn( $artifact ) => (int) ( $artifact['size_bytes'] ?? 0 ), $artifacts));

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
	 * @param  array $wt Worktree row.
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
	 * @param  string $path Path to inspect.
	 * @return int|null Size in bytes, or null when unavailable.
	 */
	private function estimate_path_size_bytes( string $path ): ?int {
		if ( '' === $path || ( ! file_exists($path) && ! is_link($path) ) ) {
			return null;
		}

		$output = array();
		$code   = 1;
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('du -sk %s 2>/dev/null', escapeshellarg($path)), $output, $code);
		if ( 0 !== $code || empty($output[0]) ) {
			return null;
		}

		$parts = preg_split('/\s+/', trim( (string) $output[0]));
		$kb    = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : 0;
		return $kb > 0 ? $kb * 1024 : 0;
	}

	/**
	 * Detect non-source artifact directories worth reporting separately.
	 *
	 * @param  string $repo Repo name.
	 * @param  string $path Worktree path.
	 * @return array<int,array<string,mixed>> Artifact rows.
	 */
	private function detect_worktree_artifacts( string $repo, string $path ): array {
		$patterns = $this->get_worktree_artifact_profile($repo, $path);
		$rows     = array();

		foreach ( $patterns as $relative => $label ) {
			$relative = trim( (string) $relative, '/');
			if ( '' === $relative || str_contains($relative, '..') ) {
				continue;
			}

			$artifact_path = rtrim($path, '/') . '/' . $relative;
			if ( ! is_dir($artifact_path) ) {
				continue;
			}

			$size   = $this->estimate_path_size_bytes($artifact_path);
			$rows[] = array(
				'path'       => $relative,
				'label'      => (string) $label,
				'size_bytes' => $size,
			);
		}

		usort($rows, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ));
		return $rows;
	}

	/**
	 * Classify dirty worktrees whose dirty paths are only generated artifacts.
	 *
	 * @param  string $repo Repo name.
	 * @param  string $path Worktree path.
	 * @return array{paths: array<int,string>, artifacts: array<int,array<string,mixed>>, artifact_size_bytes: int}|null Artifact-only classification.
	 */
	private function classify_artifact_only_dirty_worktree( string $repo, string $path ): ?array {
		if ( '' === $repo || '' === $path || ! is_dir($path) ) {
			return null;
		}

		$artifacts = $this->detect_worktree_artifacts($repo, $path);
		if ( array() === $artifacts ) {
			return null;
		}

		$status = $this->run_git($path, 'status --porcelain', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($status) ) {
			return null;
		}

		$dirty_paths = array();
		foreach ( explode("\n", (string) ( $status['output'] ?? '' )) as $line ) {
			$line = rtrim($line, "\r");
			if ( '' === $line ) {
				continue;
			}

			$path_part = trim(substr($line, 3));
			if ( str_contains($path_part, ' -> ') ) {
				$rename_target = strrchr($path_part, '>');
				$path_part     = false === $rename_target ? '' : trim(substr($rename_target, 1));
			}
			$path_part = trim($path_part, ' /');
			if ( '' === $path_part ) {
				return null;
			}
			$dirty_paths[] = $path_part;
		}

		if ( array() === $dirty_paths ) {
			return null;
		}

		$artifact_paths = array_map(fn( $artifact ) => trim( (string) ( $artifact['path'] ?? '' ), '/'), $artifacts);
		foreach ( $dirty_paths as $dirty_path ) {
			$matched = false;
			foreach ( $artifact_paths as $artifact_path ) {
				if ( '' !== $artifact_path && ( $dirty_path === $artifact_path || str_starts_with($dirty_path, $artifact_path . '/') ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return null;
			}
		}

		return array(
			'paths'               => $dirty_paths,
			'artifacts'           => $artifacts,
			'artifact_size_bytes' => array_sum(array_map(fn( $artifact ) => (int) ( $artifact['size_bytes'] ?? 0 ), $artifacts)),
		);
	}

	/**
	 * Resolve repo-specific artifact profile paths.
	 *
	 * @param  string $repo Repo name.
	 * @param  string $path Worktree path.
	 * @return array<string,string> Relative path => label map.
	 */
	private function get_worktree_artifact_profile( string $repo, string $path ): array {
		$profile = array();

		if ( is_file(rtrim($path, '/') . '/Cargo.toml') ) {
			$profile['target'] = 'Rust build artifacts';
		}

		if ( is_file(rtrim($path, '/') . '/package.json') ) {
			$profile['node_modules'] = 'Node dependencies';
			$profile['build']        = 'JavaScript build output';
			$profile['.next']        = 'Next.js build cache';
			$profile['dist']         = 'JavaScript build output';
			$profile['coverage']     = 'test coverage output';
		}

		if ( is_file(rtrim($path, '/') . '/composer.json') ) {
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
		return apply_filters('datamachine_code_worktree_artifact_profile', $profile, $repo, $path);
	}

	/**
	 * Resolve the most useful last-touched timestamp for a worktree.
	 *
	 * @param  string      $path       Worktree path.
	 * @param  array|null  $metadata   Stored lifecycle/context metadata.
	 * @param  string|null $created_at Created timestamp fallback.
	 * @return string|null ISO timestamp.
	 */
	private function detect_worktree_last_touched_at( string $path, ?array $metadata, ?string $created_at ): ?string {
		foreach ( array( 'last_touched_at', 'updated_at', 'timestamp' ) as $key ) {
			if ( is_array($metadata) && ! empty($metadata[ $key ]) && false !== strtotime( (string) $metadata[ $key ]) ) {
				return gmdate('c', (int) strtotime( (string) $metadata[ $key ]));
			}
		}

		$git_marker = rtrim($path, '/') . '/.git';
		$mtime      = file_exists($git_marker) ? filemtime($git_marker) : false;
		if ( false === $mtime && file_exists($path) ) {
			$mtime = filemtime($path);
		}

		if ( false !== $mtime ) {
			return gmdate('c', (int) $mtime);
		}

		return $created_at;
	}

	/**
	 * Calculate whole-day age from an ISO timestamp.
	 *
	 * @param  string|null $created_at Created timestamp.
	 * @return int|null Whole days old, or null when unknown.
	 */
	private function calculate_age_days( ?string $created_at ): ?int {
		if ( null === $created_at || '' === $created_at ) {
			return null;
		}

		$created_ts = strtotime($created_at);
		if ( false === $created_ts ) {
			return null;
		}

		return max(0, (int) floor(( time() - $created_ts ) / 86400));
	}

	/**
	 * Sort cleanup rows by requested reporting dimension.
	 *
	 * @param  array<int,array> $rows Rows to sort.
	 * @param  string           $sort size|age|empty.
	 * @return array<int,array>
	 */
	private function sort_worktree_cleanup_rows( array $rows, string $sort ): array {
		if ( 'size' === $sort ) {
			usort($rows, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ));
		} elseif ( 'age' === $sort ) {
			usort($rows, fn( $a, $b ) => (int) ( $b['age_days'] ?? -1 ) <=> (int) ( $a['age_days'] ?? -1 ));
		}

		return $rows;
	}

	/**
	 * Produce compact top-N rows for cleanup summaries.
	 *
	 * @param  array<int,array> $rows  Rows to summarize.
	 * @param  string           $field Numeric field to sort by.
	 * @return array<int,array<string,mixed>>
	 */
	private function summarize_top_worktree_rows( array $rows, string $field ): array {
		$rows = array_values(array_filter($rows, fn( $row ) => isset($row[ $field ]) && (int) $row[ $field ] > 0));
		usort($rows, fn( $a, $b ) => (int) ( $b[ $field ] ?? 0 ) <=> (int) ( $a[ $field ] ?? 0 ));

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
			array_slice($rows, 0, self::CLEANUP_SUMMARY_TOP_LIMIT)
		);
	}

	/**
	 * Parse a compact cleanup age duration.
	 *
	 * @param  string $duration Duration like 7d, 24h, 30m, or 60s.
	 * @return int|\WP_Error Seconds on success.
	 */
	private function parse_worktree_cleanup_duration( string $duration ): int|\WP_Error {
		if ( ! preg_match('/^(\d+)([smhdw])$/', trim($duration), $matches) ) {
			return new \WP_Error('invalid_cleanup_age_filter', 'Invalid --older-than duration. Use a compact value like 7d, 24h, 30m, or 60s.', array( 'status' => 400 ));
		}

		$value = (int) $matches[1];
		if ( $value <= 0 ) {
			return new \WP_Error('invalid_cleanup_age_filter', 'Invalid --older-than duration. Duration must be greater than zero.', array( 'status' => 400 ));
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
	 * @param  array $plan Decoded cleanup report.
	 * @return array<int,array>|\WP_Error
	 */
	private function extract_worktree_cleanup_plan_candidates( array $plan ): array|\WP_Error {
		$candidates = $plan['candidates'] ?? null;
		if ( ! is_array($candidates) ) {
			return new \WP_Error('invalid_cleanup_plan', 'Cleanup plan must contain a candidates array.', array( 'status' => 400 ));
		}

		$required = array( 'handle', 'repo', 'branch', 'path', 'signal' );
		foreach ( $candidates as $index => $row ) {
			if ( ! is_array($row) ) {
				return new \WP_Error('invalid_cleanup_plan', sprintf('Cleanup plan candidate #%d is not an object.', (int) $index), array( 'status' => 400 ));
			}
			foreach ( $required as $field ) {
				if ( 'branch' === $field && 'broken_orphan_worktree_marker' === (string) ( $row['signal'] ?? $row['reason_code'] ?? '' ) ) {
					continue;
				}
				if ( '' === trim( (string) ( $row[ $field ] ?? '' )) ) {
					return new \WP_Error('invalid_cleanup_plan', sprintf('Cleanup plan candidate #%d is missing %s.', (int) $index, $field), array( 'status' => 400 ));
				}
			}
		}

		return array_values($candidates);
	}

	/**
	 * Restrict a freshly-evaluated cleanup report to a reviewed plan.
	 *
	 * The destructive pass still re-runs every cleanup gate. Only rows that are
	 * current safe candidates and exactly match the reviewed handle/path/branch/
	 * signal are removed; anything that drifted is reported as skipped.
	 *
	 * @param  array<int,array> $planned_candidates Candidate rows from the plan file.
	 * @param  array<int,array> $current_candidates Freshly detected safe candidates.
	 * @param  array<int,array> $current_skipped    Freshly detected skipped rows.
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
			if ( '' !== $handle && ! isset($skipped_by_handle[ $handle ]) ) {
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
				if ( 'branch' === $field ) {
					$planned_slug = $this->slugify_branch($planned);
					$actual_slug  = $this->slugify_branch($actual);
					if ( '' !== $planned_slug && $planned_slug === $actual_slug ) {
						continue;
					}
				}
				if ( $planned !== $actual ) {
					$mismatches[] = sprintf('%s planned=%s current=%s', $field, $planned, $actual);
				}
			}

			if ( array() !== $mismatches ) {
				$scoped_skipped[] = array(
					'handle'         => $handle,
					'repo'           => (string) ( $current['repo'] ?? $plan_row['repo'] ?? '' ),
					'branch'         => (string) ( $current['branch'] ?? $plan_row['branch'] ?? '' ),
					'path'           => (string) ( $current['path'] ?? $plan_row['path'] ?? '' ),
					'reason_code'    => 'plan_mismatch',
					'reason'         => 'planned cleanup row no longer matches current state: ' . implode('; ', $mismatches),
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
	 * @param  string $repo    Primary repo directory name (for routing git commands).
	 * @param  string $branch  Branch the worktree is checked out to.
	 * @param  string $wt_path Absolute path to the worktree directory.
	 * @param  bool   $force   Pass --force to `git worktree remove`.
	 * @return array{success: bool, handle: string, message: string}|\WP_Error
	 */
	private function remove_worktree_by_path( string $repo, string $branch, string $wt_path, bool $force, int $remove_timeout_seconds = self::CLEANUP_GIT_REMOVE_TIMEOUT ): array|\WP_Error {
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

		$broken_marker = $this->classify_broken_orphan_worktree_marker($real_path);
		if ( null !== $broken_marker ) {
			$removed_paths = $this->remove_contained_directory_recursive($real_path, $this->workspace_path, $this->workspace_path);
			if ( is_wp_error($removed_paths) ) {
				return $removed_paths;
			}

			WorktreeContextInjector::forget_metadata(basename($wt_path));
			$this->worktree_inventory()->delete(basename($wt_path));

			return array(
				'success'            => true,
				'handle'             => basename($wt_path),
				'message'            => sprintf('Broken orphan worktree directory at "%s" removed.', $wt_path),
				'branch'             => '',
				'broken_target_path' => $broken_marker['gitdir'],
				'removed_paths'      => $removed_paths,
			);
		}

		$cmd    = sprintf('worktree remove %s%s', $force ? '--force ' : '', escapeshellarg($real_path));
		$result = $this->run_git($primary_path, $cmd, $remove_timeout_seconds);

		if ( is_wp_error($result) ) {
			return $result;
		}

		// `git worktree remove` is the destructive boundary. If Git reports
		// success but the path survives, fail the row instead of falling back to
		// an unbounded recursive delete that can wedge cleanup apply.
		if ( is_dir($real_path) ) {
			return new \WP_Error(
				'worktree_remove_incomplete',
				sprintf('Git reported worktree removal success, but the directory still exists: %s', $real_path),
				array(
					'status'       => 500,
					'path'         => $real_path,
					'primary_path' => $primary_path,
				)
			);
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
	 *   2. `local-merged` — branch has no commits outside remote default.
	 *   3. `remote-tracking-clean` — branch still exists on origin, while the
	 *      local worktree is clean and has no unpushed commits. Age gates are
	 *      enforced by the caller before removal when retention cleanup passes
	 *      `older_than`.
	 *   4. `pr-merged` — GitHub API reports a closed+merged PR for this
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

		$remote_tracking_clean = $this->detect_remote_tracking_clean_signal($primary_path, $branch);
		if ( null !== $remote_tracking_clean ) {
			return $remote_tracking_clean;
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
	 * Detect clean local-only checkouts whose work is preserved by a remote branch.
	 *
	 * This is intentionally less strict than a merge signal: the remote branch may
	 * still be open or unmerged, but the local checkout is only a disposable copy
	 * when it is clean, has no unpushed commits, and `origin/<branch>` exists.
	 * Removing the local worktree and local branch does not delete the remote
	 * branch or close any PR, so the work can be picked up from Git later.
	 *
	 * @param  string $primary_path Path to the primary git checkout.
	 * @param  string $branch       Branch name.
	 * @return array{signal: string, reason: string, remote_ref: string}|null
	 */
	private function detect_remote_tracking_clean_signal( string $primary_path, string $branch ): ?array {
		$remote_ref = 'refs/remotes/origin/' . $branch;
		$result     = $this->run_git($primary_path, sprintf('rev-parse --verify --quiet %s', escapeshellarg($remote_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($result) ) {
			return null;
		}

		return array(
			'signal'     => 'remote-tracking-clean',
			'reason'     => 'clean local worktree has no unpushed commits and the branch exists on origin; removing the local checkout preserves remote Git state',
			'remote_ref' => $remote_ref,
		);
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
}
