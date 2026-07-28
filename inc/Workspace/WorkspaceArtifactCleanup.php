<?php
/**
 * Workspace artifact cleanup operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WorktreeContextInjector.php';

trait WorkspaceArtifactCleanup {

	/** @var array<string,mixed>|null Request-local process path snapshot. */
	private ?array $artifact_process_path_snapshot = null;

	/**
	 * Cleanup reconstructable artifact directories inside workspace worktrees.
	 *
	 * Unlike whole-worktree cleanup, this intentionally does not require a merge
	 * signal: clean active worktrees can safely shed build outputs. Applying is
	 * plan-only so every destructive run revalidates the exact worktree and
	 * profile-derived artifact paths from a reviewed dry-run.
	 *
	 * Direct low-level dry-run is bounded by default to keep huge workspaces
	 * (~hundreds of worktrees) responsive. Operators should apply through the
	 * high-level cleanup plan/apply commands, which persist reviewed rows by run
	 * ID instead of replaying a mutable inventory offset.
	 *
	 * Apply paths revalidate the planned subset only — they pass `only_handles`
	 * derived from the plan into the builder so safety probes run against the
	 * planned worktrees rather than the entire workspace.
	 *
	 * @param  array $opts Cleanup options (dry_run, force,
	 *                     allow_active_artifact_cleanup, apply_plan, limit,
	 *                     offset, exhaustive, safety_probes).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_cleanup_artifacts( array $opts = array() ): array|\WP_Error {
		$dry_run        = ! empty($opts['dry_run']);
		$force          = ! empty($opts['force']);
		$allow_active   = ! empty($opts['allow_active_artifact_cleanup']);
		$apply_plan     = isset($opts['apply_plan']) && is_array($opts['apply_plan']) ? $opts['apply_plan'] : null;
		$exhaustive     = ! empty($opts['exhaustive']);
		$full_workspace = ! empty($opts['full_workspace']);
		$sort           = isset($opts['sort']) ? strtolower(trim( (string) $opts['sort'])) : '';
		$limit          = isset($opts['limit']) ? (int) $opts['limit'] : self::ARTIFACT_CLEANUP_DEFAULT_LIMIT;
		$offset         = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;
		if ( $limit < 0 ) {
			return new \WP_Error('invalid_artifact_cleanup_limit', 'Artifact cleanup --limit must be greater than 0. Use --exhaustive for an unbounded full artifact audit.', array( 'status' => 400 ));
		}
		if ( ! $exhaustive && ! $full_workspace && $limit <= 0 ) {
			return new \WP_Error('invalid_artifact_cleanup_limit', 'Artifact cleanup --limit must be greater than 0. Use --exhaustive for an unbounded full artifact audit, or the high-level workspace cleanup plan for full-workspace inventory planning.', array( 'status' => 400 ));
		}
		// Allow callers to opt out of bounded mode entirely only through the
		// explicit exhaustive path, which also enables safety probes.
		if ( $exhaustive || $full_workspace ) {
			$limit = 0;
		}
		$review_command  = $this->build_artifact_cleanup_review_command();
		$apply_command   = $this->build_artifact_cleanup_apply_command($force, $allow_active);
		$preview_command = $this->build_artifact_cleanup_preview_command($opts);
		// Apply paths default to safety probing (small subset). Dry-run defaults
		// to skipping the per-worktree git probes unless explicitly requested or
		// the caller asked for exhaustive mode.
		$safety_probes = array_key_exists('safety_probes', $opts)
		? (bool) $opts['safety_probes']
		: ( $exhaustive || null !== $apply_plan );

		if ( null !== $apply_plan ) {
			$dry_run = false;
		}

		if ( ! $dry_run && null === $apply_plan ) {
			return new \WP_Error('artifact_cleanup_plan_required', sprintf('Artifact cleanup requires a reviewed DB-backed plan. Run `%s`, note its run_id, then run `%s`. Use --dry-run first and --apply-plan=<file> only as a low-level escape hatch.', $review_command, $apply_command), array( 'status' => 400 ));
		}

		$only_handles = null;
		$planned      = null;
		if ( null !== $apply_plan ) {
			$planned = $this->extract_worktree_artifact_cleanup_plan_candidates($apply_plan);
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
			$only_handles = array_keys($only_handles);
		}

		$rank_by_size = $dry_run && null === $apply_plan && ! $exhaustive && in_array($sort, array( 'size', 'bytes' ), true);
		$plan_limit   = $rank_by_size ? 0 : $limit;

		$plan = $this->build_worktree_artifact_cleanup_plan(
			$force,
			array(
				'allow_active_artifact_cleanup' => $allow_active,
				'limit'         => $plan_limit,
				'offset'        => $rank_by_size ? 0 : $offset,
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
			$scoped     = $this->scope_worktree_artifact_cleanup_to_plan($planned, $candidates, $skipped);
			$candidates = $scoped['candidates'];
			$skipped    = $scoped['skipped'];
		}

		if ( $rank_by_size ) {
			usort($candidates, function ( $a, $b ): int {
				$size = (int) ( $b['artifact_size_bytes'] ?? 0 ) <=> (int) ( $a['artifact_size_bytes'] ?? 0 );
				return 0 !== $size ? $size : strcmp( (string) ( $a['handle'] ?? '' ), (string) ( $b['handle'] ?? '' ));
			});
			$total_ranked = count($candidates);
			$rank_offset  = min($offset, $total_ranked);
			if ( $limit > 0 ) {
				$candidates = array_slice($candidates, $rank_offset, $limit);
			}
			$rank_end   = $limit > 0 ? min($rank_offset + $limit, $total_ranked) : $total_ranked;
			$pagination = array(
				'mode'          => 'ranked_inventory',
				'limit'         => $limit,
				'offset'        => $rank_offset,
				'scanned'       => (int) ( $pagination['scanned'] ?? 0 ),
				'total'         => (int) ( $pagination['total'] ?? 0 ),
				'complete'      => $rank_end >= $total_ranked,
				'partial'       => $rank_end < $total_ranked,
				'next_offset'   => $rank_end < $total_ranked ? $rank_end : null,
				'safety_probes' => $safety_probes,
				'sort'          => 'size',
				'ranked_total'  => $total_ranked,
			);
		}

		$summary = $this->build_worktree_artifact_cleanup_summary($candidates, array(), $skipped);
		if ( null !== $pagination ) {
			$summary['pagination'] = $pagination;
		}

		if ( $dry_run ) {
			$response = array(
				'success'               => true,
				'dry_run'               => true,
				'review_command'        => $review_command,
				'apply_command'         => $apply_command,
				'preview_command'       => $preview_command,
				'rerun_preview_command' => $preview_command,
				'candidates'            => $candidates,
				'removed'               => array(),
				'partial'               => array(),
				'skipped'               => $skipped,
				'summary'               => array(
					'review_command'        => $review_command,
					'apply_command'         => $apply_command,
					'preview_command'       => $preview_command,
					'rerun_preview_command' => $preview_command,
				) + $summary,
			);
			if ( null !== $pagination ) {
				$response['pagination'] = $pagination;
			}
			return $response;
		}

		$removed = array();
		$partial = array();
		foreach ( $candidates as $candidate ) {
			$removed_artifacts = array();
			$artifacts         = array_values((array) ( $candidate['artifacts'] ?? array() ));
			$failed            = false;
			$process_guard     = $this->active_artifact_process_protection( (string) ( $candidate['path'] ?? '' ), $artifacts, true);
			if ( null !== $process_guard ) {
				if ( ! $allow_active ) {
					$skipped[] = array_merge($candidate, $process_guard);
					continue;
				}
				$candidate['safety_overrides'][] = $process_guard;
			}

			foreach ( $artifacts as $artifact_index => $artifact ) {
				$liveness_guard = $this->current_artifact_liveness_protection($candidate);
				if ( null !== $liveness_guard && ! $allow_active ) {
					$blocked = array_merge($candidate, $liveness_guard, array( 'artifacts' => array_slice($artifacts, $artifact_index) ));
					if ( array() === $removed_artifacts ) {
						$skipped[] = $blocked;
					} else {
						$partial[] = $this->build_partial_artifact_cleanup_row($candidate, $removed_artifacts, array_slice($artifacts, $artifact_index), $liveness_guard);
					}
					$failed = true;
					break;
				}
				if ( null !== $liveness_guard ) {
					$candidate['safety_overrides'][] = $liveness_guard;
				}

				$remove = $this->remove_worktree_artifact_path( (string) $candidate['path'], (string) ( $artifact['path'] ?? '' ));
				if ( $remove instanceof \WP_Error ) {
					$blocker = array(
						'handle'      => $candidate['handle'] ?? '',
						'repo'        => $candidate['repo'] ?? '',
						'branch'      => $candidate['branch'] ?? '',
						'path'        => $candidate['path'] ?? '',
						'reason_code' => 'artifact_remove_failed',
						'reason'      => sprintf('failed to remove artifact %s: %s', (string) ( $artifact['path'] ?? '' ), $remove->get_error_message()),
						'artifacts'   => array( $artifact ),
					);
					if ( array() === $removed_artifacts ) {
						$skipped[] = $blocker;
					} else {
						$partial[] = $this->build_partial_artifact_cleanup_row($candidate, $removed_artifacts, array_slice($artifacts, $artifact_index), $blocker);
					}
					$failed = true;
					break;
				}

				$removed_artifacts[] = is_array($remove) ? array_merge($artifact, array( 'removal' => $remove )) : $artifact;
				$this->after_artifact_cleanup_mutation($candidate, $artifact, count($removed_artifacts));
			}

			if ( $failed ) {
				continue;
			}

			$removed[] = array_merge($candidate, array( 'artifacts' => $removed_artifacts ));
		}

		$removed       = $this->observe_artifact_reclamation_rows($removed);
		$partial       = $this->observe_artifact_reclamation_rows($partial);
		$apply_summary = $this->build_worktree_artifact_cleanup_summary($candidates, $removed, $skipped, $partial);
		if ( null !== $pagination ) {
			$apply_summary['pagination'] = $pagination;
		}
		$response = array(
			'success'    => true,
			'dry_run'    => false,
			'candidates' => $candidates,
			'removed'    => $removed,
			'partial'    => $partial,
			'skipped'    => $skipped,
			'summary'    => $apply_summary,
		);
		if ( null !== $pagination ) {
			$response['pagination'] = $pagination;
		}
		return $response;
	}

	/**
	 * Build the high-level command that persists a snapshot-safe artifact plan.
	 * @return string
	 */
	private function build_artifact_cleanup_review_command(): string {
		return 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json';
	}

	/**
	 * Build the command that applies a reviewed DB-backed artifact plan.
	 *
	 * @return string
	 */
	private function build_artifact_cleanup_apply_command( bool $force = false, bool $allow_active = false ): string {
		return 'studio wp datamachine-code workspace cleanup apply <run-id>'
			. ( $force ? ' --force' : '' )
			. ( $allow_active ? ' --allow-active-artifact-cleanup' : '' );
	}

	/**
	 * Build the preview command for the current artifact cleanup dry-run.
	 *
	 * @param  array<string,mixed> $opts Dry-run options.
	 * @return string
	 */
	private function build_artifact_cleanup_preview_command( array $opts ): string {
		$parts = array( 'studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run' );
		if ( ! empty($opts['force']) ) {
			$parts[] = '--force';
		}
		if ( ! empty($opts['allow_active_artifact_cleanup']) ) {
			$parts[] = '--allow-active-artifact-cleanup';
		}
		if ( isset($opts['limit']) ) {
			$parts[] = '--limit=' . (int) $opts['limit'];
		}
		if ( isset($opts['offset']) && (int) $opts['offset'] > 0 ) {
			$parts[] = '--offset=' . (int) $opts['offset'];
		}
		if ( ! empty($opts['exhaustive']) ) {
			$parts[] = '--exhaustive';
		}
		if ( ! empty($opts['safety_probes']) ) {
			$parts[] = '--safety-probes';
		}
		if ( isset($opts['sort']) && '' !== trim( (string) $opts['sort']) ) {
			$parts[] = '--sort=' . preg_replace('/[^a-z0-9_\-]/i', '', (string) $opts['sort']);
		}
		$parts[] = '--format=json';
		return implode(' ', $parts);
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
	 * Liveness and active-process protections apply in both modes. Exhaustive
	 * mode (`exhaustive=true` or `safety_probes=true`) additionally uses
	 * `worktree_list()` and runs the full per-worktree dirty + unpushed probes.
	 *
	 * Pagination via `limit` + `offset` always operates on the inventory ordering
	 * after `only_handles` filtering. The returned plan includes a `pagination`
	 * envelope describing total worktrees considered, the scanned slice, and
	 * `next_offset` continuation when the scan is partial.
	 *
	 * @param  bool  $force Whether to allow dirty/unpushed worktrees.
	 * @param  array $opts  Options: `limit` (0 = unbounded internal exhaustive mode), `offset`,
	 *                      `only_handles` (array<string>|null), `safety_probes`.
	 * @return array{candidates: array<int,array>, skipped: array<int,array>, pagination: ?array<string,mixed>}|\WP_Error
	 */
	private function build_worktree_artifact_cleanup_plan( bool $force, array $opts = array() ): array|\WP_Error {
		$limit         = isset($opts['limit']) ? (int) $opts['limit'] : 0;
		$offset        = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;
		$allow_active  = ! empty($opts['allow_active_artifact_cleanup']);
		$only_handles  = isset($opts['only_handles']) && is_array($opts['only_handles'])
		? array_values(array_filter(array_map('strval', $opts['only_handles']), fn( $h ) => '' !== $h))
		: null;
		$safety_probes = ! empty($opts['safety_probes']);

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
			$rows = array_values(array_filter($rows, fn( $wt ) => empty($wt['is_primary'])));
		} else {
			$rows = array_values(
				array_filter(
					$this->build_workspace_inventory_rows(),
					fn( $wt ) => empty($wt['is_primary']) && ! empty($wt['is_worktree'])
				)
			);
		}

		// Stable ordering so `offset` is deterministic across calls and matches
		// what the operator saw in the previous page.
		usort($rows, fn( $a, $b ) => strcmp( (string) ( $a['handle'] ?? '' ), (string) ( $b['handle'] ?? '' )));

		if ( null !== $only_index ) {
			$rows = array_values(array_filter($rows, fn( $wt ) => isset($only_index[ (string) ( $wt['handle'] ?? '' ) ])));
		}

		$total       = count($rows);
		$bounded     = $limit > 0;
		$slice_start = $bounded ? min($offset, $total) : 0;
		$slice_end   = $bounded ? min($slice_start + $limit, $total) : $total;
		$slice       = $bounded ? array_slice($rows, $slice_start, $slice_end - $slice_start) : $rows;

		$candidates = array();
		$skipped    = array();

		foreach ( $slice as $wt ) {
			$handle                = (string) ( $wt['handle'] ?? '?' );
			$repo                  = (string) ( $wt['repo'] ?? '' );
			$wt_path               = (string) ( $wt['path'] ?? '' );
			$resolved_branch       = '' !== $wt_path ? $this->resolve_worktree_branch_from_head_file($wt_path) : null;
			$stale_marker_recovery = null;
			if ( $safety_probes ) {
				$branch = (string) ( $resolved_branch ?? $wt['branch'] ?? $wt['branch_slug'] ?? '' );
			} else {
				// Inventory rows only carry `branch_slug` (the directory slug,
				// e.g. `fix-foo`). The plan apply path revalidates against the
				// real git branch from `worktree_list()` (e.g. `fix/foo`), so
				// resolve it cheaply here from the per-worktree `.git/HEAD`
				// pointer file. This is two file reads vs a `git` invocation.
				$branch = (string) ( $resolved_branch ?? $wt['branch'] ?? $wt['branch_slug'] ?? '' );
			}

			// Inventory rows don't include detected artifacts; detect them on
			// the fly so the bounded path stays focused on artifact-bearing
			// worktrees only.
			if ( $safety_probes && isset($wt['artifacts']) ) {
				$artifacts = array_values(array_filter( (array) ( $wt['artifacts'] ?? array() ), fn( $artifact ) => is_array($artifact)));
			} else {
				$artifacts = '' !== $wt_path ? $this->detect_worktree_artifacts($repo, $wt_path) : array();
			}

			$base_row         = array(
				'handle'     => $handle,
				'repo'       => $repo,
				'branch'     => $branch,
				'path'       => $wt_path,
				'created_at' => $wt['created_at'] ?? null,
			);
			$safety_overrides = array();

			if ( empty($artifacts) ) {
				continue;
			}

			if ( ! empty($wt['external']) ) {
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => 'external_worktree',
						'reason'      => 'external worktree (outside workspace) - artifact cleanup only operates inside the DMC workspace',
						'artifacts'   => $artifacts,
					)
				);
				continue;
			}

			if ( '' === $repo || '' === $branch || '' === $wt_path ) {
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => 'missing_metadata',
						'reason'      => 'missing repo/branch/path',
						'artifacts'   => $artifacts,
					)
				);
				continue;
			}

			if ( $this->is_active_studio_symlink_target($wt_path) ) {
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => 'active_symlink_target',
						'reason'      => 'worktree is the target of a wp-content plugin/theme symlink - leaving artifacts in place',
						'artifacts'   => $artifacts,
					)
				);
				continue;
			}

			$liveness_protection = $this->artifact_liveness_protection($wt);
			if ( null !== $liveness_protection ) {
				if ( ! $allow_active ) {
					$skipped[] = array_merge($base_row, $liveness_protection, array( 'artifacts' => $artifacts ));
					continue;
				}
				$safety_overrides[] = $liveness_protection;
			}

			$process_protection = $this->active_artifact_process_protection($wt_path, $artifacts);
			if ( null !== $process_protection ) {
				if ( ! $allow_active ) {
					$skipped[] = array_merge($base_row, $process_protection, array( 'artifacts' => $artifacts ));
					continue;
				}
				$safety_overrides[] = $process_protection;
			}

			if ( $safety_probes ) {
				if ( null === $only_handles && null !== ( $wt['dirty'] ?? null ) ) {
					$dirty_count = (int) ( $wt['dirty'] ?? 0 );
				} else {
					$dirty_probe = $this->probe_worktree_dirty_count($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
					if ( is_wp_error($dirty_probe) ) {
						$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $wt_path, $dirty_probe, 'artifact cleanup dirty-state probe', 'leaving artifacts in place');
						if ( $force && $this->is_stale_worktree_marker_diagnostic($diagnostic) ) {
							$stale_marker_recovery = $diagnostic;
							$dirty_count           = 0;
						} else {
							$skipped[] = array_merge(
								$base_row,
								$diagnostic,
								array( 'artifacts' => $artifacts )
							);
							continue;
						}
					} else {
						$dirty_count = (int) $dirty_probe;
					}
				}
				if ( $dirty_count > 0 && ! $force ) {
					$artifact_dirty = $this->classify_artifact_only_dirty_worktree($repo, $wt_path);
					if ( null === $artifact_dirty ) {
						$skipped[] = array_merge(
							$base_row, array(
								'reason_code' => 'dirty_worktree',
								'reason'      => sprintf('working tree contains source changes (%d dirty paths) - leaving artifacts in place', $dirty_count),
								'dirty'       => $dirty_count,
								'artifacts'   => $artifacts,
							)
						);
						continue;
					}
					$base_row['artifact_dirty_paths'] = $artifact_dirty['paths'];
				}

				if ( null === $stale_marker_recovery ) {
					$unpushed = $this->count_unpushed_commits($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
					if ( is_wp_error($unpushed) ) {
						$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $wt_path, $unpushed, 'artifact cleanup safety probe', 'leaving artifacts in place');
						if ( $force && $this->is_stale_worktree_marker_diagnostic($diagnostic) ) {
							$stale_marker_recovery = $diagnostic;
						} else {
							$skipped[] = array_merge(
								$base_row,
								$diagnostic,
								array( 'artifacts' => $artifacts )
							);
							continue;
						}
					}
					if ( isset($unpushed) && ! is_wp_error($unpushed) && $unpushed > 0 && ! $force ) {
						$skipped[] = array_merge(
							$base_row, array(
								'reason_code' => 'unpushed_commits',
								'reason'      => sprintf('%d unpushed commit(s) - pass force=true to override artifact cleanup only', $unpushed),
								'unpushed'    => $unpushed,
								'artifacts'   => $artifacts,
							)
						);
						continue;
					}
				}
			}

			$candidate = array_merge(
				$base_row, array(
					'artifacts'           => $artifacts,
					'artifact_count'      => count($artifacts),
					'artifact_size_bytes' => array_sum(array_map(fn( $artifact ) => (int) ( $artifact['size_bytes'] ?? 0 ), $artifacts)),
					'reason_code'         => 'profile_artifacts',
					'reason'              => 'profile-derived reconstructable artifacts can be removed',
				)
			);
			if ( ! $safety_probes ) {
				// Surface that bounded dry-run did not run per-worktree git
				// safety probes. Apply paths revalidate with safety_probes=true
				// before deletion, so the candidate is reviewable but not
				// destructible from a bounded plan alone.
				$candidate['safety_probes_deferred'] = true;
			}
			if ( null !== $stale_marker_recovery ) {
				$candidate['reason_code']                  = 'profile_artifacts_stale_worktree_marker';
				$candidate['reason']                       = 'profile-derived reconstructable artifacts can be removed; git worktree marker is stale, but explicit force allows artifact-only cleanup after path containment validation';
				$candidate['git_metadata_warning']         = $stale_marker_recovery['reason'] ?? 'git worktree metadata marker is stale or missing';
				$candidate['metadata_reconciliation_hint'] = 'Run studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json to repair stale worktree metadata after artifact cleanup.';
			}
			if ( array() !== $safety_overrides ) {
				$candidate['safety_overrides'] = $safety_overrides;
			}
			$candidates[] = $candidate;
		}

		$pagination = array(
			'mode'          => $safety_probes ? ( $uses_git_listing ? 'exhaustive' : 'bounded_inventory_safety' ) : 'bounded_inventory',
			'limit'         => $bounded ? $limit : 0,
			'offset'        => $slice_start,
			'scanned'       => count($slice),
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
	 * Return authoritative liveness protection from a cache-evicting metadata read.
	 *
	 * @param  array<string,mixed> $candidate Worktree candidate.
	 * @return array<string,mixed>|null
	 */
	private function current_artifact_liveness_protection( array $candidate ): ?array {
		$handle   = (string) ( $candidate['handle'] ?? '' );
		$metadata = '' !== $handle ? WorktreeContextInjector::get_metadata_fresh($handle) : null;
		$liveness = WorktreeContextInjector::classify_liveness(is_array($metadata) ? $metadata : null);
		$row      = array_merge(
			$candidate,
			array(
				'liveness'              => $liveness['liveness'],
				'liveness_reason'       => $liveness['reason'],
				'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
				'last_seen_at'          => $liveness['last_seen_at'],
				'metadata'              => $metadata,
				'owner'                 => WorktreeContextInjector::summarize_owner(is_array($metadata) ? $metadata : null),
				'session'               => WorktreeContextInjector::summarize_session(is_array($metadata) ? $metadata : null),
			)
		);

		return $this->artifact_liveness_protection($row);
	}

	/**
	 * Build a typed protection row from normalized worktree liveness.
	 *
	 * @param  array<string,mixed> $row Worktree inventory row.
	 * @return array<string,mixed>|null
	 */
	private function artifact_liveness_protection( array $row ): ?array {
		if ( WorktreeContextInjector::LIVENESS_LIVE !== (string) ( $row['liveness'] ?? '' ) ) {
			return null;
		}

		return array(
			'reason_code'       => 'live_worktree',
			'protecting_reason' => 'live_worktree',
			'reason'            => 'authoritative liveness evidence reports a live owner; leaving reconstructable artifacts in place',
			'liveness'          => WorktreeContextInjector::LIVENESS_LIVE,
			'liveness_reason'   => (string) ( $row['liveness_reason'] ?? '' ),
			'liveness_evidence' => array(
				'state'                 => WorktreeContextInjector::LIVENESS_LIVE,
				'reason'                => (string) ( $row['liveness_reason'] ?? '' ),
				'last_seen_at'          => $row['last_seen_at'] ?? null,
				'heartbeat_age_seconds' => $row['heartbeat_age_seconds'] ?? null,
				'owner'                 => $row['owner'] ?? array(),
				'session'               => $row['session'] ?? array(),
			),
		);
	}

	/**
	 * Build a typed protection row when a process uses the worktree or artifact roots.
	 *
	 * @param  string           $worktree_path Worktree root.
	 * @param  array<int,array> $artifacts     Profile-derived artifact rows.
	 * @param  bool             $fresh         Whether to bypass the request-local process snapshot.
	 * @return array<string,mixed>|null
	 */
	private function active_artifact_process_protection( string $worktree_path, array $artifacts, bool $fresh = false ): ?array {
		$probe    = $this->detect_active_artifact_processes($worktree_path, $artifacts, $fresh);
		$evidence = (array) ( $probe['evidence'] ?? array() );
		if ( array() !== $evidence ) {
			return array(
				'reason_code'       => 'active_build',
				'protecting_reason' => 'active_build',
				'reason'            => 'active process cwd or open file intersects the worktree artifacts; leaving reconstructable artifacts in place',
				'process_probe'      => $probe,
				'process_evidence'   => $evidence,
			);
		}

		$status = (string) ( $probe['status'] ?? 'unavailable' );
		if ( 'available' === $status ) {
			return null;
		}

		return array(
			'reason_code'       => 'uncertain' === $status ? 'active_process_probe_uncertain' : 'active_process_probe_unavailable',
			'protecting_reason' => 'active_process_probe_' . $status,
			'reason'            => 'active process use could not be authoritatively excluded; safe cleanup is failing closed',
			'process_probe'      => $probe,
		);
	}

	/**
	 * Conservatively inspect Linux process cwd/open-file paths without naming runtimes or tools.
	 *
	 * @param  string           $worktree_path Worktree root.
	 * @param  array<int,array> $artifacts     Profile-derived artifact rows.
	 * @param  bool             $fresh         Whether to rebuild the process snapshot.
	 * @return array{status:string,evidence:array<int,array<string,mixed>>,diagnostics:array<string,mixed>}
	 */
	protected function detect_active_artifact_processes( string $worktree_path, array $artifacts, bool $fresh = false ): array {
		$worktree_real = realpath($worktree_path);
		if ( false === $worktree_real ) {
			return array(
				'status'      => 'unavailable',
				'evidence'    => array(),
				'diagnostics' => array( 'reason' => 'worktree_path_unresolved' ),
			);
		}

		$roots = array( rtrim($worktree_real, '/') );
		foreach ( $artifacts as $artifact ) {
			$relative = is_array($artifact) ? trim( (string) ( $artifact['path'] ?? '' ), '/') : '';
			$real     = '' !== $relative ? realpath($worktree_real . '/' . $relative) : false;
			if ( false !== $real ) {
				$roots[] = rtrim($real, '/');
			}
		}

		$snapshot = $this->artifact_process_path_records($fresh);
		$records  = (array) ( $snapshot['records'] ?? array() );
		$matches = array();
		foreach ( $records as $record ) {
			$path = rtrim( (string) ( $record['path'] ?? '' ), '/');
			foreach ( $roots as $root ) {
				if ( $path === $root || str_starts_with($path, $root . '/') ) {
					$matches[] = $record;
					break;
				}
			}
			if ( count($matches) >= 10 ) {
				break;
			}
		}

		return array(
			'status'      => (string) ( $snapshot['status'] ?? 'unavailable' ),
			'evidence'    => $matches,
			'diagnostics' => (array) ( $snapshot['diagnostics'] ?? array() ),
		);
	}

	/**
	 * Snapshot process paths once for preview, or freshly for final apply revalidation.
	 *
	 * @return array{status:string,records:array<int,array<string,mixed>>,diagnostics:array<string,mixed>}
	 */
	protected function artifact_process_path_records( bool $fresh ): array {
		if ( ! $fresh && null !== $this->artifact_process_path_snapshot ) {
			return $this->artifact_process_path_snapshot;
		}

		$proc_root = $this->artifact_process_root();
		if ( ! is_dir($proc_root) || ! is_readable($proc_root) ) {
			return array(
				'status'      => 'unavailable',
				'records'     => array(),
				'diagnostics' => array( 'reason' => 'process_filesystem_unavailable', 'path' => $proc_root ),
			);
		}

		$records = array();
		$entries = scandir($proc_root);
		if ( false === $entries ) {
			return array(
				'status'      => 'unavailable',
				'records'     => array(),
				'diagnostics' => array( 'reason' => 'process_filesystem_unreadable', 'path' => $proc_root ),
			);
		}
		$scanned              = 0;
		$unreadable           = array();
		$foreign_namespaces   = array();
		$self_mount_namespace = @readlink($proc_root . '/self/ns/mnt'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Procfs namespace links may be unavailable on non-Linux hosts.
		foreach ( $entries as $entry ) {
			if ( ! ctype_digit( (string) $entry ) || getmypid() === (int) $entry ) {
				continue;
			}
			$proc = $proc_root . '/' . $entry;
			++$scanned;
			$mount_namespace = @readlink($proc . '/ns/mnt'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Processes can exit between procfs enumeration and readlink.
			if ( is_string($self_mount_namespace) && is_string($mount_namespace) && $mount_namespace !== $self_mount_namespace ) {
				$foreign_namespaces[] = array( 'pid' => (int) $entry, 'mount_namespace' => $mount_namespace );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Processes can exit between procfs enumeration and ownership lookup.
			$owner_uid = @fileowner($proc);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local procfs metadata, not a remote request.
			$command = is_readable($proc . '/comm') ? trim( (string) file_get_contents($proc . '/comm')) : '';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Processes can exit between procfs enumeration and readlink.
			$cwd = @readlink($proc . '/cwd');
			if ( is_string($cwd) && '' !== $cwd ) {
				$records[] = array(
					'pid'        => (int) $entry,
					'command'    => $command,
					'owner_uid'  => false === $owner_uid ? null : $owner_uid,
					'match_type' => 'cwd',
					'path'       => preg_replace('/ \(deleted\)$/', '', $cwd),
				);
			} elseif ( is_dir($proc) ) {
				$unreadable[] = array( 'pid' => (int) $entry, 'resource' => 'cwd' );
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Processes can exit between procfs enumeration and scandir.
			$fd_entries = @scandir($proc . '/fd');
			if ( false === $fd_entries ) {
				if ( is_dir($proc) ) {
					$unreadable[] = array( 'pid' => (int) $entry, 'resource' => 'fd' );
				}
				continue;
			}
			foreach ( $fd_entries as $fd ) {
				if ( ! ctype_digit( (string) $fd ) ) {
					continue;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File descriptors can close during procfs inspection.
				$target = @readlink($proc . '/fd/' . $fd);
				if ( ! is_string($target) || ! str_starts_with($target, '/') ) {
					continue;
				}
				$records[] = array(
					'pid'        => (int) $entry,
					'command'    => $command,
					'owner_uid'  => false === $owner_uid ? null : $owner_uid,
					'match_type' => 'open_file',
					'fd'         => (int) $fd,
					'path'       => preg_replace('/ \(deleted\)$/', '', $target),
				);
			}
		}

		$status = array() === $unreadable && array() === $foreign_namespaces ? 'available' : 'uncertain';
		$result = array(
			'status'      => $status,
			'records'     => $records,
			'diagnostics' => array(
				'process_root'             => $proc_root,
				'scanned_processes'        => $scanned,
				'path_records'             => count($records),
				'unreadable_processes'     => array_slice($unreadable, 0, 10),
				'foreign_mount_namespaces' => array_slice($foreign_namespaces, 0, 10),
			),
		);
		if ( ! $fresh ) {
			$this->artifact_process_path_snapshot = $result;
		}
		return $result;
	}

	/**
	 * Resolve the process filesystem root. Overridable for deterministic tests.
	 */
	protected function artifact_process_root(): string {
		return '/proc';
	}

	/**
	 * Preserve measured mutation when a later artifact in the row becomes blocked.
	 *
	 * @param  array<string,mixed> $candidate         Reviewed candidate.
	 * @param  array<int,array>    $removed_artifacts Artifacts already removed.
	 * @param  array<int,array>    $remaining         Artifacts left in place.
	 * @param  array<string,mixed> $blocker           Current typed protection.
	 * @return array<string,mixed>
	 */
	private function build_partial_artifact_cleanup_row( array $candidate, array $removed_artifacts, array $remaining, array $blocker ): array {
		$bytes = array_sum(
			array_map(
				fn( $artifact ) => max(0, (int) ( $artifact['removal']['bytes_reclaimed'] ?? 0 )),
				$removed_artifacts
			)
		);

		return array_merge(
			$candidate,
			array(
				'reason_code'       => 'partial_artifact_cleanup',
				'reason'            => 'one or more artifacts were removed before a later artifact became blocked',
				'artifacts'         => $removed_artifacts,
				'remaining_artifacts' => $remaining,
				'bytes_reclaimed'   => $bytes,
				'blocker'           => $blocker,
			)
		);
	}

	/**
	 * Observe whether removed artifacts stayed absent through the cleanup window.
	 *
	 * @param  array<int,array> $rows Removed or partially removed rows.
	 * @return array<int,array>
	 */
	private function observe_artifact_reclamation_rows( array $rows ): array {
		foreach ( $rows as &$row ) {
			$observations = array();
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$relative = is_array($artifact) ? (string) ( $artifact['path'] ?? '' ) : '';
				if ( '' === $relative ) {
					continue;
				}
				$observations[] = $this->observe_artifact_reclamation_path( (string) ( $row['path'] ?? '' ), $relative);
			}
			$row['reclamation_observation'] = array(
				'observed_at'  => gmdate('c'),
				'window'       => 'through_cleanup_completion',
				'observations' => $observations,
				'durable'      => ! in_array(false, array_column($observations, 'durable'), true),
			);
		}
		unset($row);

		return $rows;
	}

	/**
	 * Observe one removed path at cleanup completion.
	 *
	 * @return array<string,mixed>
	 */
	protected function observe_artifact_reclamation_path( string $worktree_path, string $relative ): array {
		$path    = rtrim($worktree_path, '/') . '/' . trim($relative, '/');
		$rebuilt = is_dir($path);

		return array(
			'path'          => $relative,
			'status'        => $rebuilt ? 'rebuilt_before_cleanup_completed' : 'absent_at_cleanup_completion',
			'durable'       => ! $rebuilt,
			'rebuilt_bytes' => $rebuilt ? max(0, $this->estimate_path_size_bytes($path)) : 0,
		);
	}

	/**
	 * Test/integration seam invoked after each successful artifact mutation.
	 *
	 * @param array<string,mixed> $candidate Worktree candidate.
	 * @param array<string,mixed> $artifact  Removed artifact row.
	 * @param int                 $count     Number removed from the row so far.
	 */
	protected function after_artifact_cleanup_mutation( array $candidate, array $artifact, int $count ): void {
		unset($candidate, $artifact, $count);
	}

	/**
	 * Build stable artifact cleanup counts.
	 *
	 * @param  array<int,array> $candidates Candidate rows.
	 * @param  array<int,array> $removed    Removed rows.
	 * @param  array<int,array> $skipped    Skipped rows.
	 * @param  array<int,array> $partial    Partially removed rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_artifact_cleanup_summary( array $candidates, array $removed, array $skipped, array $partial = array() ): array {
		$skipped_by_reason = array();
		$artifact_by_repo  = array();
		$would_bytes       = 0;
		$removed_bytes     = 0;
		$would_count       = 0;
		$removed_count     = 0;
		$durable_bytes     = 0;
		$rebuilt_bytes     = 0;

		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}

		foreach ( $candidates as $row ) {
			$repo = (string) ( $row['repo'] ?? 'unknown' );
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$bytes        = (int) ( is_array($artifact) ? ( $artifact['size_bytes'] ?? 0 ) : 0 );
				$would_bytes += max(0, $bytes);
				++$would_count;
				$artifact_by_repo[ $repo ] = ( $artifact_by_repo[ $repo ] ?? 0 ) + max(0, $bytes);
			}
		}

		foreach ( array_merge($removed, $partial) as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$removed_bytes += max(0, (int) ( is_array($artifact) ? ( $artifact['removal']['bytes_reclaimed'] ?? $artifact['size_bytes'] ?? 0 ) : 0 ));
				++$removed_count;
			}
			$observation = (array) ( $row['reclamation_observation'] ?? array() );
			foreach ( (array) ( $observation['observations'] ?? array() ) as $item ) {
				$rebuilt_bytes += max(0, (int) ( $item['rebuilt_bytes'] ?? 0 ));
			}
			if ( ! empty($observation['durable']) ) {
				$durable_bytes += array_sum(array_map(fn( $artifact ) => max(0, (int) ( $artifact['removal']['bytes_reclaimed'] ?? 0 )), (array) ( $row['artifacts'] ?? array() )));
			}
		}

		ksort($skipped_by_reason);
		arsort($artifact_by_repo);

		return array(
			'would_remove_worktrees' => count($candidates),
			'would_remove_artifacts' => $would_count,
			'removed_worktrees'      => count($removed),
			'partially_removed_worktrees' => count($partial),
			'removed_artifacts'      => $removed_count,
			'skipped'                => count($skipped),
			'skipped_by_reason'      => $skipped_by_reason,
			'artifact_count'         => 0 === $removed_count ? $would_count : $removed_count,
			'artifact_size_bytes'    => 0 === $removed_count ? $would_bytes : $removed_bytes,
			'removed_size_bytes'     => $removed_bytes,
			'durable_reclaimed_bytes' => $durable_bytes,
			'rebuilt_artifact_bytes' => $rebuilt_bytes,
			'artifact_size_by_repo'  => $artifact_by_repo,
		);
	}

	/**
	 * Check whether a git probe diagnostic represents stale worktree metadata.
	 *
	 * @param  array<string,mixed> $diagnostic Classified git probe diagnostic.
	 * @return bool
	 */
	private function is_stale_worktree_marker_diagnostic( array $diagnostic ): bool {
		return 'stale_worktree_marker' === (string) ( $diagnostic['reason_code'] ?? '' );
	}

	/**
	 * Extract artifact cleanup candidates from a dry-run JSON report.
	 *
	 * @param  array $plan Decoded artifact cleanup report.
	 * @return array<int,array>|\WP_Error
	 */
	private function extract_worktree_artifact_cleanup_plan_candidates( array $plan ): array|\WP_Error {
		$candidates = $plan['candidates'] ?? null;
		if ( ! is_array($candidates) ) {
			return new \WP_Error('invalid_artifact_cleanup_plan', 'Artifact cleanup plan must contain a candidates array.', array( 'status' => 400 ));
		}

		foreach ( $candidates as $index => $row ) {
			if ( ! is_array($row) ) {
				return new \WP_Error('invalid_artifact_cleanup_plan', sprintf('Artifact cleanup candidate #%d is not an object.', (int) $index), array( 'status' => 400 ));
			}

			foreach ( array( 'handle', 'repo', 'branch', 'path', 'artifacts' ) as $field ) {
				$value = $row[ $field ] ?? null;
				if ( 'artifacts' === $field ? ! is_array($value) || array() === $value : '' === trim( (string) $value) ) {
					return new \WP_Error('invalid_artifact_cleanup_plan', sprintf('Artifact cleanup candidate #%d is missing %s.', (int) $index, $field), array( 'status' => 400 ));
				}
			}

			foreach ( $row['artifacts'] as $artifact_index => $artifact ) {
				if ( ! is_array($artifact) || '' === trim( (string) ( $artifact['path'] ?? '' )) ) {
					return new \WP_Error('invalid_artifact_cleanup_plan', sprintf('Artifact cleanup candidate #%d artifact #%d is missing path.', (int) $index, (int) $artifact_index), array( 'status' => 400 ));
				}
			}
		}

		return array_values($candidates);
	}

	/**
	 * Restrict current artifact cleanup candidates to a reviewed plan.
	 *
	 * @param  array<int,array> $planned_candidates Planned rows.
	 * @param  array<int,array> $current_candidates Fresh candidates.
	 * @param  array<int,array> $current_skipped    Fresh skips.
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
				$path      = (string) ( $plan_row['path'] ?? '' );
				$artifacts = (array) ( $plan_row['artifacts'] ?? array() );
				$complete  = '' !== $path;
				foreach ( $artifacts as $artifact ) {
					$relative = is_array($artifact) ? trim( (string) ( $artifact['path'] ?? '' ), '/') : '';
					if ( '' !== $relative && is_dir(rtrim($path, '/') . '/' . $relative) ) {
						$complete = false;
						break;
					}
				}

				$skip                      = $complete ? array(
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
				if ( is_array($artifact) ) {
					$current_artifacts[ (string) ( $artifact['path'] ?? '' ) ] = $artifact;
				}
			}

			$artifacts = array();
			foreach ( (array) ( $plan_row['artifacts'] ?? array() ) as $planned_artifact ) {
				$relative = (string) ( is_array($planned_artifact) ? ( $planned_artifact['path'] ?? '' ) : '' );
				if ( '' === $relative || ! isset($current_artifacts[ $relative ]) ) {
					$mismatches[] = 'artifact:' . $relative;
					continue;
				}
				if ( is_array($planned_artifact) && array_key_exists('size_bytes', $planned_artifact)
					&& (int) ( $current_artifacts[ $relative ]['size_bytes'] ?? -1 ) !== (int) $planned_artifact['size_bytes'] ) {
					$mismatches[] = 'artifact_size:' . $relative;
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
					'reason'            => 'planned artifact cleanup row no longer matches current state: ' . implode(', ', $mismatches),
					'planned_artifacts' => $plan_row['artifacts'] ?? array(),
					'artifacts'         => $current['artifacts'] ?? array(),
				);
				continue;
			}

			$scoped_candidates[] = array_merge($current, array( 'artifacts' => $artifacts ));
		}

		return array(
			'candidates' => $scoped_candidates,
			'skipped'    => $scoped_skipped,
		);
	}

	/**
	 * Remove one artifact directory after exact profile/path revalidation.
	 *
	 * @param  string $worktree_path Worktree root path.
	 * @param  string $relative      Profile-relative artifact path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function remove_worktree_artifact_path( string $worktree_path, string $relative ): array|\WP_Error {
		$relative = trim($relative, '/');
		if ( '' === $relative || str_contains($relative, '..') ) {
			return new \WP_Error('invalid_artifact_path', sprintf('Invalid artifact path: %s', $relative), array( 'status' => 400 ));
		}

		if ( '' === $worktree_path || ! is_dir($worktree_path) ) {
			return new \WP_Error('worktree_path_missing', sprintf('Worktree path does not exist: %s', $worktree_path), array( 'status' => 404 ));
		}

		$worktree_validation = $this->validate_containment($worktree_path, $this->workspace_path);
		if ( ! $worktree_validation['valid'] ) {
			return new \WP_Error('path_outside_workspace', sprintf('Refusing artifact cleanup outside workspace: %s', $worktree_validation['message'] ?? ''), array( 'status' => 403 ));
		}
		$worktree_real = (string) ( $worktree_validation['real_path'] ?? '' );
		if ( '' === $worktree_real ) {
			return new \WP_Error('path_resolution_failed', sprintf('Unable to resolve worktree path: %s', $worktree_path), array( 'status' => 403 ));
		}

		$artifact_path = rtrim($worktree_real, '/') . '/' . $relative;
		if ( ! is_dir($artifact_path) ) {
			return new \WP_Error('artifact_path_missing', sprintf('Artifact path does not exist: %s', $relative), array( 'status' => 404 ));
		}

		$artifact_validation = $this->validate_containment($artifact_path, $worktree_real);
		$artifact_real       = (string) ( $artifact_validation['real_path'] ?? '' );
		if ( ! $artifact_validation['valid'] || '' === $artifact_real || $artifact_real === $worktree_real ) {
			return new \WP_Error('artifact_path_outside_worktree', sprintf('Refusing artifact cleanup for %s: %s', $relative, $artifact_validation['message'] ?? ''), array( 'status' => 403 ));
		}
		$bytes_reclaimed = max(0, $this->estimate_path_size_bytes($artifact_real));

		$output = array();
		$exit   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('rm -rf %s 2>&1', escapeshellarg($artifact_real)), $output, $exit);
		if ( 0 !== $exit ) {
			$message = trim(implode("\n", array_map('strval', $output)));
			return new \WP_Error(
				'artifact_remove_failed',
				sprintf('Artifact removal command failed for %s%s', $relative, '' !== $message ? ': ' . $message : '.'),
				array( 'status' => 500 )
			);
		}

		clearstatcache(true, $artifact_real);
		if ( file_exists($artifact_real) ) {
			return new \WP_Error('artifact_remove_failed', sprintf('Artifact path still exists after removal: %s', $relative), array( 'status' => 500 ));
		}

		return array(
			'resolved_path'   => $artifact_real,
			'bytes_reclaimed' => $bytes_reclaimed,
			'exit_code'       => $exit,
			'exists_after'    => false,
			'verified_at'     => gmdate('c'),
		);
	}

	/**
	 * Check whether a worktree is currently targeted by a Studio plugin/theme symlink.
	 *
	 * @param  string $worktree_path Worktree path.
	 * @return bool True when a wp-content plugin/theme symlink points at the path.
	 */
	private function is_active_studio_symlink_target( string $worktree_path ): bool {
		$worktree_real = realpath($worktree_path);
		if ( false === $worktree_real || ! defined('ABSPATH') ) {
			return false;
		}

		foreach ( array( 'wp-content/plugins', 'wp-content/themes' ) as $relative_dir ) {
			$dir = rtrim(ABSPATH, '/') . '/' . $relative_dir;
			if ( ! is_dir($dir) ) {
				continue;
			}

			$entries = scandir($dir);
			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = $dir . '/' . $entry;
				if ( ! is_link($path) ) {
					continue;
				}

				$target_real = realpath($path);
				if ( false !== $target_real && rtrim($target_real, '/') === rtrim($worktree_real, '/') ) {
					return true;
				}
			}
		}

		return false;
	}
}
