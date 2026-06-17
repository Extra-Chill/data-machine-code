<?php
/**
 * Workspace inventory-only worktree cleanup operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

trait WorkspaceWorktreeInventoryCleanup {



	/**
	 * Build a bounded cleanup review from top-level inventory only.
	 *
	 * This mode is deliberately dry-run-only. It avoids git worktree discovery,
	 * per-worktree status, unpushed-commit checks, fetches, and GitHub lookups.
	 * Only explicit lifecycle cleanup signals become candidates; every ambiguous
	 * worktree is skipped with stable reason codes for review.
	 *
	 * @param  string   $older_than                Optional age filter duration.
	 * @param  string   $sort                      Optional candidate sort.
	 * @param  bool     $include_repaired_metadata Whether repaired metadata rows can be candidates.
	 * @param  int|null $limit                     Optional worktree page size.
	 * @param  int      $offset                    Optional worktree page offset.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function worktree_cleanup_inventory_only( string $older_than, string $sort, bool $include_repaired_metadata = false, ?int $limit = null, int $offset = 0 ): array|\WP_Error {
		$age_filter = null;
		if ( '' !== $older_than ) {
			$duration_seconds = $this->parse_worktree_cleanup_duration($older_than);
			if ( is_wp_error($duration_seconds) ) {
				return $duration_seconds;
			}

			$age_filter = WorktreeAgeFilter::build($older_than, $duration_seconds);
		}

		$candidates = array();
		$skipped    = array();

		$inventory_rows = array_values(array_filter($this->build_workspace_inventory_rows(), fn( $wt ) => ! empty($wt['is_worktree']) ));
		$total          = count($inventory_rows);
		$offset         = max(0, $offset);
		$page_rows      = null === $limit ? array_slice($inventory_rows, $offset) : array_slice($inventory_rows, $offset, max(1, $limit));
		$processed      = 0;

		foreach ( $page_rows as $wt ) {
			++$processed;
			if ( empty($wt['is_worktree']) ) {
				continue;
			}

			$handle      = (string) ( $wt['handle'] ?? '?' );
			$repo        = (string) ( $wt['repo'] ?? '' );
			$branch_slug = (string) ( $wt['branch_slug'] ?? '' );
			$metadata    = $wt['metadata'] ?? null;
			$branch      = (string) ( $wt['branch'] ?? ( is_array($metadata) ? ( $metadata['branch'] ?? '' ) : '' ) );
			if ( '' === $branch ) {
				$branch = $branch_slug;
			}
			$path       = (string) ( $wt['path'] ?? '' );
			$created_at = $wt['created_at'] ?? null;
			$base_row   = array(
				'handle'      => $handle,
				'repo'        => $repo,
				'branch'      => $branch,
				'branch_slug' => $branch_slug,
				'path'        => $path,
				'created_at'  => $created_at,
				'metadata'    => $metadata,
			);

			if ( is_array($metadata) && array() !== $metadata ) {
				$triage_status = $this->workspace_row_triage_status_from_metadata($metadata);
				if ( in_array($triage_status, array( 'ignored', 'quarantined' ), true) ) {
					$skipped[] = array_merge(
						$base_row,
						array(
							'reason_code'   => 'triage_' . $triage_status,
							'reason'        => sprintf('operator marked row %s: %s', $triage_status, (string) ( $metadata['triage_reason'] ?? '' )),
							'hint'          => 'Intentional triage metadata excludes this row from unresolved cleanup blockers.',
							'triage_status' => $triage_status,
							'triage_reason' => $metadata['triage_reason'] ?? null,
							'triaged_at'    => $metadata['triage_updated_at'] ?? null,
						)
					);
					continue;
				}
			}

			if ( ! is_array($metadata) || array() === $metadata ) {
				$skipped[] = array_merge(
					$base_row, array(
						'reason_code' => 'needs_metadata_reconcile',
						'reason'      => 'inventory row has no lifecycle metadata; metadata reconciliation is required before cleanup planning can classify it',
						'hint'        => 'Run workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json to generate reviewed metadata reconciliation rows.',
					)
				);
				continue;
			}

			if ( ! WorktreeContextInjector::has_cleanup_signal($metadata) ) {
				$repaired = ! empty($metadata['metadata_repaired']);
				if ( $include_repaired_metadata && $repaired ) {
					$age_decision = null;
					if ( null !== $age_filter ) {
						$age_decision = WorktreeAgeFilter::decide($created_at, $age_filter);
						if ( 'unknown_age' === $age_decision['decision'] ) {
							$skipped[] = array_merge(
								$base_row,
								WorktreeAgeFilter::skip_fields($age_decision)
							);
							continue;
						}

						if ( 'excluded' === $age_decision['decision'] ) {
							$skipped[] = array_merge(
								$base_row,
								WorktreeAgeFilter::skip_fields($age_decision)
							);
							continue;
						}
					}

					$signal    = WorktreeCleanupSignal::from_metadata($metadata, true);
					$candidate = array_merge(
						$base_row, array(
							'dirty'         => 0,
							'repair_status' => 'repaired_metadata',
						), WorktreeCleanupSignal::candidate_fields($signal ?? array())
					);
					if ( null !== $age_decision ) {
						$candidate['age_filter'] = $age_decision['age_filter'];
					}
					$candidates[] = $candidate;
					continue;
				}
				$skipped[] = $this->build_inventory_cleanup_no_signal_skip($base_row, $wt, $metadata);
				continue;
			}

			$submodule_skip = $this->build_submodule_worktree_cleanup_skip($base_row);
			if ( null !== $submodule_skip ) {
				$skipped[] = $submodule_skip;
				continue;
			}

			$age_decision = null;
			if ( null !== $age_filter ) {
				$age_decision = WorktreeAgeFilter::decide($created_at, $age_filter);
				if ( 'unknown_age' === $age_decision['decision'] ) {
					$skipped[] = array_merge(
						$base_row,
						WorktreeAgeFilter::skip_fields($age_decision)
					);
					continue;
				}

				if ( 'excluded' === $age_decision['decision'] ) {
					$skipped[] = array_merge(
						$base_row,
						WorktreeAgeFilter::skip_fields($age_decision)
					);
					continue;
				}
			}

			$signal    = WorktreeCleanupSignal::from_metadata($metadata);
			$candidate = array_merge(
				$base_row, array(
					'dirty'       => 0,
				), WorktreeCleanupSignal::candidate_fields($signal ?? array(), true)
			);
			if ( null !== $age_decision ) {
				$candidate['age_filter'] = $age_decision['age_filter'];
			}

			$candidates[] = $candidate;
		}

		$candidates = $this->sort_worktree_cleanup_rows($candidates, $sort);
		$pagination = $this->build_worktree_cleanup_pagination($offset, $limit, $processed, $total, false, null);
		$summary    = $this->build_worktree_cleanup_summary($candidates, array(), $skipped, $age_filter);
		if ( null !== $pagination ) {
			$summary['pagination'] = $pagination;
		}
		if ( ! empty($candidates) ) {
			$summary['bounded_cleanup_eligible_apply'] = $this->build_bounded_cleanup_eligible_apply_hint(count($candidates), $older_than, $sort, $include_repaired_metadata);
			$summary['apply_command']                  = $summary['bounded_cleanup_eligible_apply']['apply_command'];
		}

		$response = array(
			'success'        => true,
			'dry_run'        => true,
			'inventory_only' => true,
			'candidates'     => $candidates,
			'removed'        => array(),
			'skipped'        => $skipped,
			'summary'        => $summary,
		);
		if ( null !== $pagination ) {
			$response['pagination'] = $pagination;
		}
		return $response;
	}

	/**
	 * Build copy-paste commands for applying a reviewed inventory-only candidate set.
	 *
	 * @param  int    $limit                     Candidate count reviewed by inventory-only cleanup.
	 * @param  string $older_than                Optional age filter from the review.
	 * @param  string $sort                      Optional sort from the review.
	 * @param  bool   $include_repaired_metadata Whether repaired metadata rows were included.
	 * @return array<string,mixed>
	 */
	private function build_bounded_cleanup_eligible_apply_hint( int $limit, string $older_than, string $sort, bool $include_repaired_metadata ): array {
		$base = sprintf(
			'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=%d',
			max(1, $limit)
		);
		if ( '' !== $older_than ) {
			$base .= ' --older-than=' . escapeshellarg($older_than);
		}
		if ( '' !== $sort ) {
			$base .= ' --sort=' . escapeshellarg($sort);
		}
		if ( $include_repaired_metadata ) {
			$base .= ' --include-repaired-metadata';
		}

		return array(
			'candidate_count' => max(1, $limit),
			'review_command'  => $base . ' --dry-run',
			'apply_command'   => $base,
			'scope'           => 'Only inventory cleanup-eligible candidates from this bounded review; apply revalidates dirty state, unpushed commits, containment, and primary safety before deletion.',
		);
	}

	/**
	 * Build an inventory-only skip row when lifecycle metadata has no cleanup signal.
	 *
	 * @param  array<string,mixed> $base_row Base worktree row.
	 * @param  array<string,mixed> $wt       Inventory row.
	 * @param  array<string,mixed> $metadata Lifecycle metadata.
	 * @return array<string,mixed>
	 */
	private function build_inventory_cleanup_no_signal_skip( array $base_row, array $wt, array $metadata ): array {
		$liveness         = (string) ( $wt['liveness'] ?? WorktreeContextInjector::LIVENESS_UNKNOWN );
		$liveness_reason  = (string) ( $wt['liveness_reason'] ?? '' );
		$state            = isset($metadata['lifecycle_state']) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state']) : null;
		$has_pr_context   = ! empty($metadata['pr_url']) || ! empty($metadata['pr_number']) || ! empty($metadata['pr_ref']);
		$has_task_context = is_array($metadata['origin_task'] ?? null) && ! empty($metadata['origin_task']['task_url']);

		if ( WorktreeContextInjector::LIVENESS_LIVE !== $liveness && ( $has_pr_context || $has_task_context ) ) {
			return array_merge(
				$base_row, array(
					'reason_code'     => 'lifecycle_reconciliation_candidate',
					'reason'          => 'stale or PR/task-backed lifecycle metadata has no cleanup signal; reconcile lifecycle state before cleanup eligibility is decided',
					'hint'            => 'Run workspace worktree cleanup --dry-run --format=json for merge/PR signal detection, then persist lifecycle state through DMC-owned finalization.',
					'lifecycle_state' => $state,
					'liveness'        => $liveness,
					'liveness_reason' => $liveness_reason,
					'pr_url'          => $metadata['pr_url'] ?? null,
					'pr_number'       => $metadata['pr_number'] ?? null,
					'origin_task'     => $metadata['origin_task'] ?? null,
				)
			);
		}

		return array_merge(
			$base_row, array(
				'reason_code'     => 'active_no_signal',
				'reason'          => 'active lifecycle metadata has no cleanup signal; leaving in place',
				'hint'            => 'No cleanup action is safe from inventory alone. Keep active, or run full cleanup when you need merge-signal detection.',
				'lifecycle_state' => $state,
				'liveness'        => $liveness,
				'liveness_reason' => $liveness_reason,
			)
		);
	}
}
