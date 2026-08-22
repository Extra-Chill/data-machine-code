<?php
/**
 * Workspace emergency worktree cleanup operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

trait WorkspaceWorktreeEmergencyCleanup {



	/**
	 * Build or apply a disk-pressure emergency cleanup plan.
	 *
	 * The dry-run path intentionally uses only top-level workspace inventory,
	 * lifecycle metadata, directory size estimates, and artifact profiles. It does
	 * not run per-worktree git status, fetch, or GitHub checks before returning a
	 * reviewable plan. Applying a reviewed plan revalidates the current worktree
	 * list and keeps dirty/unpushed worktree deletion behind explicit force.
	 *
	 * @param  array $opts Emergency cleanup options (dry_run, force, apply_plan).
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_emergency_cleanup( array $opts = array() ): array|\WP_Error {
		$force      = ! empty($opts['force']);
		$apply_plan = isset($opts['apply_plan']) && is_array($opts['apply_plan']) ? $opts['apply_plan'] : null;
		$dry_run    = null === $apply_plan;

		if ( null !== $apply_plan ) {
			return $this->apply_worktree_emergency_cleanup_plan($apply_plan, $force);
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
		$artifact_candidates  = array();
		$worktree_candidates  = array();
		$skipped              = array();
		$entry_count_started  = microtime(true);
		$entry_count_deadline = $entry_count_started + self::HYGIENE_DEFAULT_SIZE_TOTAL_TIMEOUT;

		foreach ( $this->build_workspace_inventory_rows() as $wt ) {
			if ( ! empty($wt['is_primary']) ) {
				continue;
			}

			$handle     = (string) ( $wt['handle'] ?? '' );
			$repo       = (string) ( $wt['repo'] ?? '' );
			$branch     = $this->emergency_inventory_branch($wt);
			$path       = (string) ( $wt['path'] ?? '' );
			$metadata   = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : null;
			$created_at = is_string($wt['created_at'] ?? null) ? (string) $wt['created_at'] : null;
			$disk       = $this->build_worktree_disk_report($repo, $path, true, $created_at, $metadata);
			$disk       = $this->annotate_emergency_disk_entry_counts($disk, $path, $entry_count_deadline);
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
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => 'missing_inventory_identity',
						'reason'      => 'inventory row is missing handle, repo, branch, or path',
					)
				);
					continue;
			}

			if ( $this->is_active_studio_symlink_target($path) ) {
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => 'active_symlink_target',
						'reason'      => 'worktree is an active plugin/theme symlink target',
					)
				);
				continue;
			}

			if ( ! empty($disk['artifacts']) ) {
				$artifact_candidates[] = array_merge(
					$base_row, array(
						'artifacts'           => $disk['artifacts'],
						'artifact_count'      => count( (array) $disk['artifacts']),
						'artifact_size_bytes' => (int) ( $disk['artifact_size_bytes'] ?? 0 ),
						'entry_count'         => $disk['artifact_entry_count'] ?? null,
						'entry_count_minimum' => (int) ( $disk['artifact_entry_count_minimum'] ?? 0 ),
						'entry_count_status'  => (string) ( $disk['artifact_entry_count_status'] ?? 'unknown' ),
						'reason_code'         => 'profile_artifacts',
						'reason'              => 'profile-derived reconstructable artifacts can be removed first under disk pressure',
					)
				);
			}

			$lifecycle_state = is_array($metadata) ? (string) ( $metadata['lifecycle_state'] ?? '' ) : '';
			if ( in_array($lifecycle_state, $this->emergency_cleanup_lifecycle_states(), true) ) {
				$worktree_candidates[] = array_merge(
					$base_row, array(
						'dirty'               => 0,
						'entry_count'         => $disk['worktree_entry_count'] ?? null,
						'entry_count_minimum' => (int) ( $disk['worktree_entry_count'] ?? 0 ),
						'entry_count_status'  => (string) ( $disk['worktree_entry_count_status'] ?? 'unknown' ),
						'signal'              => $lifecycle_state,
						'reason_code'         => $lifecycle_state,
						'reason'              => sprintf('worktree lifecycle state is %s; deletion requires reviewed apply-plan revalidation', $lifecycle_state),
						'pr_url'              => $metadata['pr_url'] ?? null,
					)
				);
			} else {
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => is_array($metadata) ? 'no_emergency_worktree_signal' : 'requires_metadata_or_full_scan',
						'reason'      => is_array($metadata) ? 'no finalized or cleanup-eligible lifecycle signal for worktree deletion' : 'missing lifecycle metadata; emergency mode will only consider artifacts from this row',
					)
				);
			}
		}

		usort($artifact_candidates, static function ( $a, $b ): int {
			$a_measured = 'measured' === (string) ( $a['entry_count_status'] ?? '' );
			$b_measured = 'measured' === (string) ( $b['entry_count_status'] ?? '' );
			if ( $a_measured !== $b_measured ) {
				return $b_measured <=> $a_measured;
			}
			$count = (int) ( $b['entry_count'] ?? -1 ) <=> (int) ( $a['entry_count'] ?? -1 );
			return 0 !== $count ? $count : (int) ( $b['artifact_size_bytes'] ?? 0 ) <=> (int) ( $a['artifact_size_bytes'] ?? 0 );
		});
		usort($worktree_candidates, fn( $a, $b ) => (int) ( $b['age_days'] ?? -1 ) <=> (int) ( $a['age_days'] ?? -1 ));

		return array(
			'success'             => true,
			'mode'                => 'emergency',
			'dry_run'             => true,
			'generated_at'        => gmdate('c'),
			'workspace_path'      => $this->workspace_path,
			'artifact_candidates' => $artifact_candidates,
			'worktree_candidates' => $worktree_candidates,
			'removed_artifacts'   => array(),
			'removed_worktrees'   => array(),
			'skipped'             => $skipped,
			'entry_count_probe'   => array(
				'per_entry_timeout_seconds' => self::HYGIENE_DEFAULT_SIZE_ENTRY_TIMEOUT,
				'total_timeout_seconds'     => self::HYGIENE_DEFAULT_SIZE_TOTAL_TIMEOUT,
				'duration_seconds'          => round(microtime(true) - $entry_count_started, 3),
			),
			'summary'             => $this->build_worktree_emergency_cleanup_summary($artifact_candidates, $worktree_candidates, array(), array(), $skipped),
		);
	}

	/** Attach bounded count evidence to artifact paths and the containing worktree. */
	private function annotate_emergency_disk_entry_counts( array $disk, string $path, float $deadline ): array {
		$artifact_total    = 0;
		$artifact_complete = true;
		foreach ( (array) ( $disk['artifacts'] ?? array() ) as $index => $artifact ) {
			$relative                                   = (string) ( $artifact['path'] ?? '' );
			$remaining                                  = $deadline - microtime(true);
			$probe                                      = $remaining > 0
				? $this->directory_entry_count_best_effort(rtrim($path, '/') . '/' . $relative, max(1, min(self::HYGIENE_DEFAULT_SIZE_ENTRY_TIMEOUT, (int) ceil($remaining))))
				: array(
					'success' => false,
					'reason'  => 'total_timeout',
				);
			$measured                                   = ! empty($probe['success']);
			$disk['artifacts'][ $index ]['entry_count'] = $measured ? (int) $probe['entry_count'] : null;
			$disk['artifacts'][ $index ]['entry_count_status'] = $measured ? 'measured' : 'unknown';
			$disk['artifacts'][ $index ]['entry_count_reason'] = $measured ? null : (string) ( $probe['reason'] ?? 'probe_failed' );
			$artifact_total                                   += $measured ? (int) $probe['entry_count'] : 0;
			$artifact_complete                                 = $artifact_complete && $measured;
		}
		$disk['artifact_entry_count']         = $artifact_complete ? $artifact_total : null;
		$disk['artifact_entry_count_minimum'] = $artifact_total;
		$disk['artifact_entry_count_status']  = $artifact_complete ? 'measured' : ( $artifact_total > 0 ? 'partial' : 'unknown' );

		$remaining                           = $deadline - microtime(true);
		$probe                               = $remaining > 0
			? $this->directory_entry_count_best_effort($path, max(1, min(self::HYGIENE_DEFAULT_SIZE_ENTRY_TIMEOUT, (int) ceil($remaining))))
			: array(
				'success' => false,
				'reason'  => 'total_timeout',
			);
		$disk['worktree_entry_count']        = ! empty($probe['success']) ? (int) $probe['entry_count'] : null;
		$disk['worktree_entry_count_status'] = ! empty($probe['success']) ? 'measured' : 'unknown';
		$disk['worktree_entry_count_reason'] = ! empty($probe['success']) ? null : (string) ( $probe['reason'] ?? 'probe_failed' );
		$disk['entry_count']                 = ! empty($disk['artifacts']) ? $disk['artifact_entry_count'] : $disk['worktree_entry_count'];
		$disk['entry_count_minimum']         = ! empty($disk['artifacts']) ? $artifact_total : (int) ( $disk['worktree_entry_count'] ?? 0 );
		$disk['entry_count_status']          = ! empty($disk['artifacts']) ? $disk['artifact_entry_count_status'] : $disk['worktree_entry_count_status'];

		return $disk;
	}

	/**
	 * Apply a reviewed emergency cleanup plan.
	 *
	 * @param  array<string,mixed> $plan  Reviewed emergency cleanup plan.
	 * @param  bool                $force Whether to allow dirty/unpushed worktree deletion.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_worktree_emergency_cleanup_plan( array $plan, bool $force ): array|\WP_Error {
		$planned_artifacts = $this->extract_emergency_plan_rows($plan, 'artifact_candidates');
		if ( $planned_artifacts instanceof \WP_Error ) {
			return $planned_artifacts;
		}
		$planned_worktrees = $this->extract_emergency_plan_rows($plan, 'worktree_candidates');
		if ( $planned_worktrees instanceof \WP_Error ) {
			return $planned_worktrees;
		}

		$removed_artifacts = array();
		$removed_worktrees = array();
		$skipped           = array();

		foreach ( $planned_artifacts as $row ) {
			$result = WorkspaceMutationLock::with_repo($this->workspace_path, (string) $row['repo'], function () use ( $row ) {
				$current = $this->emergency_current_worktree_row($row);
				if ( null === $current || ! $this->emergency_row_identity_matches_current($row, $current, false) ) {
					return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'emergency_artifact_plan_not_current', 'planned artifact row no longer matches the current worktree') );
				}
				$protection = $this->emergency_current_liveness_protection($current);
				if ( null !== $protection ) {
					return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, (string) $protection['reason_code'], (string) $protection['reason']) );
				}

				$removed = array();
				foreach ( (array) $row['artifacts'] as $artifact ) {
					$relative = is_array($artifact) ? (string) ( $artifact['path'] ?? '' ) : '';
					$remove = $this->remove_worktree_artifact_path((string) $current['path'], $relative);
					if ( $remove instanceof \WP_Error ) {
						return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'emergency_artifact_remove_failed', sprintf('artifact %s removal failed: %s', $relative, $remove->get_error_message())) );
					}
					$removed[] = $artifact;
				}
				return array( 'current' => $current, 'artifacts' => $removed );
			});
			if ( $result instanceof \WP_Error ) {
				$skipped[] = $this->build_emergency_apply_skip($row, null, 'emergency_artifact_lock_failed', $result->get_error_message());
			} elseif ( isset($result['skipped']) ) {
				$skipped[] = $result['skipped'];
			} else {
				$removed_artifacts[] = array_merge($row, array( 'artifacts' => $result['artifacts'] ));
			}
		}

		foreach ( $planned_worktrees as $row ) {
			$result = WorkspaceMutationLock::with_repo($this->workspace_path, (string) $row['repo'], function () use ( $row, $force ) {
				$current = $this->emergency_current_worktree_row($row);
				if ( null === $current || ! $this->emergency_row_identity_matches_current($row, $current, true) ) {
					return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'emergency_worktree_plan_not_current', 'planned worktree row no longer matches the current worktree') );
				}
				$protection = $this->emergency_current_liveness_protection($current);
				if ( null !== $protection ) {
					return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, (string) $protection['reason_code'], (string) $protection['reason']) );
				}

				$metadata = is_array($current['metadata'] ?? null) ? (array) $current['metadata'] : array();
			$state    = (string) ( $metadata['lifecycle_state'] ?? '' );
			if ( ! in_array($state, $this->emergency_cleanup_lifecycle_states(), true) ) {
				return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'emergency_lifecycle_not_current', 'current lifecycle state is no longer finalized or cleanup-eligible') );
			}

			$dirty = (int) ( $current['dirty'] ?? 0 );
			if ( $dirty > 0 && ! $force ) {
				return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'dirty_worktree', sprintf('working tree dirty (%d files) - pass --force only after human review', $dirty)) );
			}

			$unpushed = $this->count_unpushed_commits( (string) ( $current['path'] ?? '' ));
			if ( $unpushed > 0 && ! $force ) {
				return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'unpushed_commits', sprintf('%d unpushed commit(s) - pass --force only after human review', $unpushed)) );
			}

			$remove = $this->remove_worktree_by_path( (string) $current['repo'], (string) $current['branch'], (string) $current['path'], $force);
			if ( $remove instanceof \WP_Error ) {
				return array( 'skipped' => $this->build_emergency_apply_skip($row, $current, 'emergency_worktree_remove_failed', 'worktree removal failed: ' . $remove->get_error_message()) );
			}
				return array( 'current' => $current );
			});

			if ( $result instanceof \WP_Error ) {
				$skipped[] = $this->build_emergency_apply_skip($row, null, 'emergency_worktree_lock_failed', $result->get_error_message());
			} elseif ( isset($result['skipped']) ) {
				$skipped[] = $result['skipped'];
			} else {
				$removed_worktrees[] = array_merge($row, array( 'current' => $result['current'] ));
			}
		}

		$this->worktree_prune();

		return array(
			'success'             => true,
			'mode'                => 'emergency',
			'dry_run'             => false,
			'generated_at'        => gmdate('c'),
			'workspace_path'      => $this->workspace_path,
			'artifact_candidates' => $planned_artifacts,
			'worktree_candidates' => $planned_worktrees,
			'removed_artifacts'   => $removed_artifacts,
			'removed_worktrees'   => $removed_worktrees,
			'skipped'             => $skipped,
			'summary'             => $this->build_worktree_emergency_cleanup_summary($planned_artifacts, $planned_worktrees, $removed_artifacts, $removed_worktrees, $skipped),
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
	 * @param  array<string,mixed> $wt Inventory row.
	 * @return string
	 */
	private function emergency_inventory_branch( array $wt ): string {
		$metadata = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : array();
		foreach ( array( $metadata['branch'] ?? '', $metadata['branch_name'] ?? '', $wt['branch_slug'] ?? '' ) as $candidate ) {
			$candidate = trim( (string) $candidate);
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Extract emergency cleanup rows from a reviewed plan.
	 *
	 * @param  array<string,mixed> $plan Plan object.
	 * @param  string              $key  Plan row key.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function extract_emergency_plan_rows( array $plan, string $key ): array|\WP_Error {
		$rows = $plan[ $key ] ?? null;
		if ( ! is_array($rows) ) {
			return new \WP_Error('invalid_emergency_cleanup_plan', sprintf('Emergency cleanup plan must contain a %s array.', $key), array( 'status' => 400 ));
		}

		foreach ( $rows as $index => $row ) {
			if ( ! is_array($row) ) {
				return new \WP_Error('invalid_emergency_cleanup_plan', sprintf('Emergency cleanup %s row #%d is not an object.', $key, (int) $index), array( 'status' => 400 ));
			}
			foreach ( array( 'handle', 'repo', 'branch', 'path' ) as $field ) {
				if ( '' === trim( (string) ( $row[ $field ] ?? '' )) ) {
					return new \WP_Error('invalid_emergency_cleanup_plan', sprintf('Emergency cleanup %s row #%d is missing %s.', $key, (int) $index, $field), array( 'status' => 400 ));
				}
			}
			if ( 'artifact_candidates' === $key && ( ! isset($row['artifacts']) || ! is_array($row['artifacts']) || array() === $row['artifacts'] ) ) {
				return new \WP_Error('invalid_emergency_cleanup_plan', sprintf('Emergency cleanup artifact row #%d is missing artifacts.', (int) $index), array( 'status' => 400 ));
			}
		}

		return array_values($rows);
	}

	/** Read one current row only after its repository lock is held. */
	private function emergency_current_worktree_row( array $planned ): ?array {
		$listing = $this->worktree_list(
			(string) ( $planned['repo'] ?? '' ),
			null,
			array(
				'handle'         => (string) ( $planned['handle'] ?? '' ),
				'include_status' => true,
				'include_disk'   => false,
			)
		);
		if ( $listing instanceof \WP_Error ) {
			return null;
		}

		$current = (array) ( $listing['worktrees'] ?? array() );
		return isset($current[0]) && is_array($current[0]) ? $current[0] : null;
	}

	/** Refuse emergency mutation when the locked row has been reactivated. */
	private function emergency_current_liveness_protection( array $current ): ?array {
		$metadata = is_array($current['metadata'] ?? null) ? $current['metadata'] : null;
		$liveness = WorktreeContextInjector::classify_liveness($metadata);

		return WorktreeCleanupCandidateClassifier::live_protection(
			array(
				'liveness'        => $liveness['liveness'],
				'liveness_reason' => $liveness['reason'],
			)
		);
	}

	/**
	 * Compare a reviewed emergency row to current worktree state.
	 *
	 * @param  array<string,mixed> $planned        Reviewed row.
	 * @param  array<string,mixed> $current        Current worktree row.
	 * @param  bool                $require_branch Whether branch identity must match exactly.
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
	 * @param  array<string,mixed>      $planned Planned row.
	 * @param  array<string,mixed>|null $current Current row.
	 * @param  string                   $code    Stable reason code.
	 * @param  string                   $reason  Human-readable reason.
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
	 * @param  array<int,array> $artifact_candidates Artifact rows planned.
	 * @param  array<int,array> $worktree_candidates Worktree rows planned.
	 * @param  array<int,array> $removed_artifacts   Artifact rows removed.
	 * @param  array<int,array> $removed_worktrees   Worktree rows removed.
	 * @param  array<int,array> $skipped             Skip rows.
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
				$artifact_bytes += max(0, (int) ( is_array($artifact) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ));
				++$artifact_count;
			}
		}

		foreach ( $removed_artifacts as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				$removed_bytes += max(0, (int) ( is_array($artifact) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ));
				++$removed_count;
			}
		}

		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}
		ksort($skipped_by_reason);

		return array(
			'would_remove_artifacts' => $artifact_count,
			'would_remove_worktrees' => count($worktree_candidates),
			'removed_artifacts'      => $removed_count,
			'removed_worktrees'      => count($removed_worktrees),
			'skipped'                => count($skipped),
			'skipped_by_reason'      => $skipped_by_reason,
			'artifact_size_bytes'    => 0 === $removed_count ? $artifact_bytes : $removed_bytes,
			'removed_artifact_bytes' => $removed_bytes,
			'worktree_size_bytes'    => array_sum(array_map(fn( $row ) => max(0, (int) ( $row['size_bytes'] ?? 0 )), $worktree_candidates)),
			'top_artifacts_by_size'  => $this->summarize_top_worktree_rows($artifact_candidates, 'artifact_size_bytes'),
			'top_artifacts_by_count' => $this->summarize_top_worktree_rows($artifact_candidates, 'entry_count'),
			'top_worktrees_by_age'   => $this->summarize_top_worktree_rows($worktree_candidates, 'age_days'),
		);
	}
}
