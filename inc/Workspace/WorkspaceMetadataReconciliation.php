<?php
/**
 * Workspace metadata reconciliation operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitHubRemote;

defined('ABSPATH') || exit;

trait WorkspaceMetadataReconciliation {



	/**
	 * Reconcile lifecycle metadata for unmanaged worktrees without removing anything.
	 *
	 * Dry-runs build a reviewed plan from the current git worktree listing.
	 * Passing `limit` and/or `offset` bounds expensive per-worktree probes to
	 * only that page; CLI defaults provide a bounded first page for operators.
	 * Applying a plan revalidates handle/path/repo/branch before writing metadata.
	 *
	 * @param  array $opts Options: dry_run bool, apply_plan array, limit int, offset int, until_budget string.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_reconcile_metadata( array $opts = array() ): array|\WP_Error {
		$started_at   = microtime(true);
		$dry_run      = ! empty($opts['dry_run']);
		$apply        = ! empty($opts['apply']);
		$via_jobs     = ! empty($opts['via_jobs']);
		$source       = isset($opts['source']) ? trim( (string) $opts['source']) : 'workspace_metadata_reconcile';
		$apply_plan   = isset($opts['apply_plan']) && is_array($opts['apply_plan']) ? $opts['apply_plan'] : null;
		$until_budget = isset($opts['until_budget']) ? trim( (string) $opts['until_budget']) : '';
		$paged        = array_key_exists('limit', $opts) || array_key_exists('offset', $opts) || '' !== $until_budget;
		$limit        = $paged ? ( array_key_exists('limit', $opts) ? (int) $opts['limit'] : self::METADATA_RECONCILE_DEFAULT_LIMIT ) : 0;
		$offset       = $paged ? max(0, (int) ( $opts['offset'] ?? 0 )) : 0;

		if ( null !== $apply_plan ) {
			return $this->apply_worktree_metadata_reconciliation_plan($apply_plan);
		}

		if ( ! $dry_run && ! $apply ) {
			return new \WP_Error('metadata_reconcile_requires_review', 'Metadata reconciliation is dry-run-first. Pass --dry-run to review JSON output, or pass apply=true for DMC-owned lifecycle reconciliation.', array( 'status' => 400 ));
		}
		if ( $via_jobs && ( $dry_run || ! $apply || null !== $apply_plan ) ) {
			return new \WP_Error('metadata_reconcile_via_jobs_requires_apply', 'Job-backed metadata reconciliation requires apply=true without dry_run or apply_plan.', array( 'status' => 400 ));
		}
		if ( $via_jobs && '' !== $until_budget ) {
			return new \WP_Error('metadata_reconcile_budget_via_jobs_unsupported', 'Metadata reconciliation --until-budget cannot be combined with --via-jobs.', array( 'status' => 400 ));
		}
		if ( $paged && $limit <= 0 ) {
			return new \WP_Error('invalid_metadata_reconcile_limit', 'Metadata reconciliation --limit must be greater than 0.', array( 'status' => 400 ));
		}
		if ( '' !== $until_budget ) {
			$budget_seconds = $this->parse_worktree_metadata_reconciliation_budget($until_budget);
			if ( is_wp_error($budget_seconds) ) {
				return $budget_seconds;
			}

			if ( $apply && ! $dry_run ) {
				return $this->drain_worktree_metadata_reconciliation_budget($limit, $offset, $until_budget, $budget_seconds);
			}
		}
		if ( $via_jobs && ! $paged ) {
			$limit  = self::METADATA_RECONCILE_DEFAULT_LIMIT;
			$offset = 0;
			$paged  = true;
		}

		$listing = $this->worktree_list(
			null,
			null,
			$paged ? array(
				'include_status' => false,
				'include_disk'   => false,
			) : array()
		);
		if ( is_wp_error($listing) ) {
			return $listing;
		}

		$all_worktrees   = array_values(
			array_filter(
				(array) ( $listing['worktrees'] ?? array() ),
				fn( $wt ) => empty($wt['is_primary'])
			)
		);
		$prefilter       = $this->prefilter_worktree_metadata_reconciliation_rows($all_worktrees);
		$page_scope      = $prefilter['candidates'];
		$total_worktrees = count($page_scope);
		$page_worktrees  = $paged ? array_slice($page_scope, $offset, $limit) : $page_scope;

		$proposals    = array();
		$skipped      = array();
		$github_cache = array();
		$fetched      = array();

		$budget_context = $this->build_worktree_loop_budget_context($opts, $started_at);
		$budget_stopped = false;
		foreach ( $page_worktrees as $index => $wt ) {
			if ( null !== $budget_context && $this->is_worktree_loop_budget_exhausted($budget_context) ) {
				$budget_stopped = true;
				$page_worktrees = array_slice($page_worktrees, 0, $index);
				break;
			}
			$row_started = microtime(true);
			$proposal    = $this->build_worktree_metadata_reconciliation_row($wt, $github_cache, $fetched);
			$elapsed_ms  = (int) round(( microtime(true) - $row_started ) * 1000);
			if ( isset($proposal['proposal']) ) {
				$proposal['proposal']['elapsed_ms'] = $elapsed_ms;
				$proposals[]                        = $proposal['proposal'];
			} elseif ( isset($proposal['skip']) ) {
				$proposal['skip']['elapsed_ms'] = $elapsed_ms;
				$skipped[]                      = $proposal['skip'];
			}
		}

		$classified_skips = $this->classify_worktree_metadata_reconciliation_skips($skipped);
		$pagination       = $paged ? $this->build_worktree_metadata_reconciliation_pagination($total_worktrees, count($page_worktrees), $limit, $offset) : null;
		if ( null !== $pagination && null !== ( $pagination['next_offset'] ?? null ) ) {
			$pagination['next_command'] = sprintf('studio wp datamachine-code workspace worktree reconcile-metadata --%s --limit=%d --offset=%d%s --format=json', $apply ? 'apply' : 'dry-run', $limit, (int) $pagination['next_offset'], null !== $budget_context ? ' --until-budget=' . (string) $budget_context['label'] : '');
		}
		if ( null !== $pagination && $budget_stopped ) {
			$pagination['partial']      = true;
			$pagination['complete']     = false;
			$pagination['next_offset']  = $offset + count($page_worktrees);
			$pagination['next_command'] = sprintf('studio wp datamachine-code workspace worktree reconcile-metadata --%s --limit=%d --offset=%d%s --format=json', $apply ? 'apply' : 'dry-run', $limit, (int) $pagination['next_offset'], null !== $budget_context ? ' --until-budget=' . (string) $budget_context['label'] : '');
		}

		$plan                           = array(
			'success'            => true,
			'dry_run'            => $dry_run,
			'applied'            => false,
			'generated_at'       => gmdate('c'),
			'workspace_path'     => $this->workspace_path,
			'proposals'          => $proposals,
			'written'            => array(),
			'skipped'            => $skipped,
			'still_unsafe'       => $classified_skips['still_unsafe'],
			'external_worktrees' => $classified_skips['external_worktrees'],
			'summary'            => $this->build_worktree_metadata_reconciliation_summary($paged ? count($page_worktrees) : count($page_scope), $proposals, array(), $skipped),
		);
		$plan['summary']['prefiltered'] = $prefilter['summary'];
		if ( null !== $pagination ) {
			$plan['pagination'] = $pagination;
			$plan['evidence']   = array(
				'scope'                     => 'paginated metadata reconciliation dry-run',
				'note'                      => 'Only candidate rows with missing, incomplete, invalid, or finalizable metadata ran per-worktree dirty, unpushed, merge-signal, and GitHub probes. Run the next_offset page until complete for full inventory review.',
				'fields_skipped_by_listing' => (array) ( $listing['fields_skipped'] ?? array() ),
				'prefilter'                 => $prefilter['summary'],
			);
			if ( null !== $budget_context ) {
				$plan['evidence']['budget'] = $this->summarize_worktree_loop_budget_context($budget_context, $budget_stopped);
			}
		}

		if ( $apply ) {
			if ( $via_jobs ) {
				return $this->schedule_worktree_metadata_reconciliation_pages($plan, $limit, $source, $started_at);
			}
			$plan['direct_apply'] = true;
			return $this->apply_worktree_metadata_reconciliation_plan($plan);
		}

		return $plan;
	}

	/**
	 * Drain paged direct metadata reconciliation until the time budget is nearly exhausted.
	 *
	 * @param  int    $limit          Page size.
	 * @param  int    $offset         Starting offset.
	 * @param  string $budget_label   Original compact budget label.
	 * @param  int    $budget_seconds Parsed budget in seconds.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function drain_worktree_metadata_reconciliation_budget( int $limit, int $offset, string $budget_label, int $budget_seconds ): array|\WP_Error {
		$started_at      = microtime(true);
		$reserve_seconds = min(5.0, max(1.0, $budget_seconds * 0.1));
		$pages           = array();
		$proposals       = array();
		$written         = array();
		$skipped         = array();
		$scanned         = 0;
		$next_offset     = $offset;
		$last_pagination = null;

		do {
			$page = $this->worktree_reconcile_metadata(
				array(
					'apply'                   => true,
					'limit'                   => $limit,
					'offset'                  => $next_offset,
					'internal_budget_label'   => $budget_label,
					'internal_budget_seconds' => $budget_seconds,
					'internal_budget_started' => $started_at,
				)
			);
			if ( is_wp_error($page) ) {
					return $page;
			}

			$page_pagination = (array) ( $page['pagination'] ?? array() );
			$page_scanned    = (int) ( $page_pagination['scanned'] ?? 0 );
			$last_pagination = $page_pagination;
			$scanned        += $page_scanned;
			$proposals       = array_merge($proposals, (array) ( $page['proposals'] ?? array() ));
			$written         = array_merge($written, (array) ( $page['written'] ?? array() ));
			$skipped         = array_merge($skipped, (array) ( $page['skipped'] ?? array() ));
			$pages[]         = array(
				'offset'   => (int) ( $page_pagination['offset'] ?? $next_offset ),
				'limit'    => (int) ( $page_pagination['limit'] ?? $limit ),
				'scanned'  => $page_scanned,
				'written'  => count( (array) ( $page['written'] ?? array() )),
				'skipped'  => count( (array) ( $page['skipped'] ?? array() )),
				'complete' => (bool) ( $page_pagination['complete'] ?? false ),
			);

			if ( ! empty($page_pagination['complete']) || null === ( $page_pagination['next_offset'] ?? null ) || $page_scanned <= 0 ) {
				break;
			}

			$next_offset = (int) $page_pagination['next_offset'];
		} while ( ( $budget_seconds - ( microtime(true) - $started_at ) ) > $reserve_seconds );

		$elapsed        = microtime(true) - $started_at;
		$complete       = ! empty($last_pagination['complete']);
		$partial        = ! $complete;
		$mutation_count = count($written);
		$restart_offset = $mutation_count > 0 ? 0 : (int) ( $last_pagination['next_offset'] ?? $next_offset );
		$next_command   = $partial ? sprintf(
			'studio wp datamachine-code workspace worktree reconcile-metadata --apply --limit=%d --offset=%d --until-budget=%s --format=json',
			$limit,
			$restart_offset,
			$budget_label
		) : null;

		$classified_skips = $this->classify_worktree_metadata_reconciliation_skips($skipped);
		$pagination       = array(
			'total'       => (int) ( $last_pagination['total'] ?? 0 ),
			'offset'      => $offset,
			'limit'       => $limit,
			'scanned'     => $scanned,
			'partial'     => $partial,
			'complete'    => $complete,
			'next_offset' => $partial ? $restart_offset : null,
		);
		if ( null !== $next_command ) {
			$pagination['next_command'] = $next_command;
		}

		return array(
			'success'            => true,
			'dry_run'            => false,
			'applied'            => true,
			'direct_apply'       => true,
			'generated_at'       => gmdate('c'),
			'workspace_path'     => $this->workspace_path,
			'proposals'          => $proposals,
			'written'            => $written,
			'skipped'            => $skipped,
			'still_unsafe'       => $classified_skips['still_unsafe'],
			'external_worktrees' => $classified_skips['external_worktrees'],
			'summary'            => $this->build_worktree_metadata_reconciliation_summary($scanned, $proposals, $written, $skipped),
			'pagination'         => $pagination,
			'evidence'           => array_filter(
				array(
					'scope'                   => 'time-budgeted metadata reconciliation direct apply',
					'apply_source'            => 'direct_apply',
					'budget'                  => $budget_label,
					'budget_seconds'          => $budget_seconds,
					'reserve_seconds'         => $reserve_seconds,
					'elapsed_seconds'         => round($elapsed, 3),
					'budget_nearly_exhausted' => $partial,
					'pages'                   => $pages,
					'next_command'            => $next_command,
				)
			),
		);
	}

	/**
	 * Schedule bounded metadata reconciliation apply pages as system jobs.
	 *
	 * @param  array<string,mixed> $first_page First bounded dry-run page.
	 * @param  int                 $limit      Page size.
	 * @param  string              $source     Caller marker.
	 * @param  float               $started_at Start timestamp.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function schedule_worktree_metadata_reconciliation_pages( array $first_page, int $limit, string $source, float $started_at ): array|\WP_Error {
		if ( ! class_exists('\DataMachine\Engine\Tasks\TaskScheduler') ) {
			return new \WP_Error('task_scheduler_unavailable', 'Data Machine TaskScheduler is unavailable; cannot schedule metadata reconciliation jobs.', array( 'status' => 500 ));
		}

		$pagination = (array) ( $first_page['pagination'] ?? array() );
		$total      = max(0, (int) ( $pagination['total'] ?? $pagination['scanned'] ?? 0 ));
		$start      = max(0, (int) ( $pagination['offset'] ?? 0 ));
		$limit      = max(1, $limit);
		$items      = array();
		for ( $offset = $start; $offset < $total; $offset += $limit ) {
			$items[] = array(
				'chunk_type'  => 'metadata_reconciliation_page',
				'chunk_index' => count($items),
				'limit'       => $limit,
				'offset'      => $offset,
				'source'      => $source,
			);
		}

		if ( array() === $items ) {
			return array(
				'success'            => true,
				'dry_run'            => false,
				'applied'            => false,
				'job_backed'         => true,
				'generated_at'       => gmdate('c'),
				'workspace_path'     => $this->workspace_path,
				'proposals'          => array(),
				'written'            => array(),
				'skipped'            => array(),
				'still_unsafe'       => array(),
				'external_worktrees' => array(),
				'summary'            => array(
					'inspected'      => 0,
					'proposed'       => 0,
					'written'        => 0,
					'skipped'        => 0,
					'scheduled_jobs' => 0,
					'limit'          => $limit,
				),
				'pagination'         => $pagination,
				'evidence'           => array(
					'elapsed_ms' => (int) round(( microtime(true) - $started_at ) * 1000),
					'note'       => 'No metadata reconciliation pages eligible for scheduling.',
					'source'     => $source,
				),
			);
		}

		$batch_result = \DataMachine\Engine\Tasks\TaskScheduler::scheduleBatch(
			'worktree_cleanup_chunk',
			$items,
			array( 'source' => $source )
		);

		if ( false === $batch_result ) {
			return new \WP_Error('metadata_reconcile_schedule_failed', 'Failed to schedule metadata reconciliation page jobs.', array( 'status' => 500 ));
		}

		return array(
			'success'            => true,
			'dry_run'            => false,
			'applied'            => false,
			'job_backed'         => true,
			'generated_at'       => gmdate('c'),
			'workspace_path'     => $this->workspace_path,
			'proposals'          => array_values( (array) ( $first_page['proposals'] ?? array() )),
			'written'            => array(),
			'skipped'            => array(),
			'still_unsafe'       => array_values( (array) ( $first_page['still_unsafe'] ?? array() )),
			'external_worktrees' => array_values( (array) ( $first_page['external_worktrees'] ?? array() )),
			'summary'            => array_merge(
				(array) ( $first_page['summary'] ?? array() ),
				array(
					'written'        => 0,
					'scheduled_jobs' => count($items),
					'limit'          => $limit,
				)
			),
			'pagination'         => $pagination,
			'evidence'           => array(
				'elapsed_ms'     => (int) round(( microtime(true) - $started_at ) * 1000),
				'scope'          => 'job-backed metadata reconciliation apply',
				'page_offsets'   => array_column($items, 'offset'),
				'batch_job_id'   => (int) ( $batch_result['batch_job_id'] ?? 0 ),
				'direct_job_ids' => $batch_result['job_ids'] ?? array(),
				'source'         => $source,
			),
		);
	}

	/**
	 * Parse a compact metadata reconciliation time budget.
	 *
	 * @param  string $duration Duration like 60s, 10m, or 1h.
	 * @return int|\WP_Error Seconds on success.
	 */
	private function parse_worktree_metadata_reconciliation_budget( string $duration ): int|\WP_Error {
		if ( ! preg_match('/^(\d+)([smh])$/', trim($duration), $matches) ) {
			return new \WP_Error('invalid_metadata_reconcile_budget', 'Invalid --until-budget duration. Use a compact value like 60s, 10m, or 1h.', array( 'status' => 400 ));
		}

		$value = (int) $matches[1];
		if ( $value <= 0 ) {
			return new \WP_Error('invalid_metadata_reconcile_budget', 'Invalid --until-budget duration. Duration must be greater than zero.', array( 'status' => 400 ));
		}

		$unit_seconds = array(
			's' => 1,
			'm' => 60,
			'h' => 3600,
		);

		return $value * $unit_seconds[ $matches[2] ];
	}

	/**
	 * Build a shared wall-clock budget context for expensive worktree loops.
	 *
	 * @param  array<string,mixed> $opts       Operation options.
	 * @param  float               $started_at Operation start timestamp.
	 * @return array<string,mixed>|null
	 */
	private function build_worktree_loop_budget_context( array $opts, float $started_at ): ?array {
		$label = isset($opts['internal_budget_label']) ? trim( (string) $opts['internal_budget_label']) : ( isset($opts['until_budget']) ? trim( (string) $opts['until_budget']) : '' );
		if ( '' === $label && ! isset($opts['internal_budget_seconds']) ) {
			return null;
		}

		$seconds = isset($opts['internal_budget_seconds']) ? (int) $opts['internal_budget_seconds'] : $this->parse_worktree_metadata_reconciliation_budget($label);
		if ( is_wp_error($seconds) || $seconds <= 0 ) {
			return null;
		}

		$started = isset($opts['internal_budget_started']) ? (float) $opts['internal_budget_started'] : $started_at;
		$reserve = min(5.0, max(0.1, $seconds * 0.1));

		return array(
			'label'           => '' === $label ? $seconds . 's' : $label,
			'seconds'         => $seconds,
			'started_at'      => $started,
			'reserve_seconds' => $reserve,
		);
	}

	/**
	 * Determine whether an expensive loop should stop before another row starts.
	 *
	 * @param  array<string,mixed> $context Budget context.
	 * @return bool
	 */
	private function is_worktree_loop_budget_exhausted( array $context ): bool {
		$remaining = (float) ( $context['seconds'] ?? 0 ) - ( microtime(true) - (float) ( $context['started_at'] ?? microtime(true) ) );
		return $remaining <= (float) ( $context['reserve_seconds'] ?? 1.0 );
	}

	/**
	 * Summarize budget evidence for JSON responses.
	 *
	 * @param  array<string,mixed> $context   Budget context.
	 * @param  bool                $exhausted Whether the loop stopped for budget.
	 * @return array<string,mixed>
	 */
	private function summarize_worktree_loop_budget_context( array $context, bool $exhausted ): array {
		$elapsed = max(0.0, microtime(true) - (float) ( $context['started_at'] ?? microtime(true) ));
		return array(
			'label'             => (string) ( $context['label'] ?? '' ),
			'budget_seconds'    => (int) ( $context['seconds'] ?? 0 ),
			'reserve_seconds'   => (float) ( $context['reserve_seconds'] ?? 0 ),
			'elapsed_seconds'   => round($elapsed, 3),
			'budget_exhausted'  => $exhausted,
			'remaining_seconds' => round(max(0.0, (float) ( $context['seconds'] ?? 0 ) - $elapsed), 3),
		);
	}

	/**
	 * Keep expensive reconciliation probes focused on rows that can change.
	 *
	 * @param  array<int,array<string,mixed>> $worktrees Non-primary worktree rows.
	 * @return array{candidates:array<int,array<string,mixed>>,summary:array<string,mixed>}
	 */
	private function prefilter_worktree_metadata_reconciliation_rows( array $worktrees ): array {
		$candidates = array();
		$skipped    = 0;
		$reasons    = array();

		foreach ( $worktrees as $wt ) {
			$reason = $this->worktree_metadata_reconciliation_candidate_reason($wt);
			if ( null !== $reason ) {
				$candidates[]       = $wt;
				$reasons[ $reason ] = (int) ( $reasons[ $reason ] ?? 0 ) + 1;
				continue;
			}

			$metadata      = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : array();
			$triage_status = $this->workspace_row_triage_status_from_metadata($metadata);
			$skip_reason   = in_array($triage_status, array( 'ignored', 'quarantined' ), true) ? 'triage_' . $triage_status : 'complete_metadata';
			++$skipped;
			$reasons[ $skip_reason ] = (int) ( $reasons[ $skip_reason ] ?? 0 ) + 1;
		}

		return array(
			'candidates' => array_values($candidates),
			'summary'    => array(
				'input_rows'      => count($worktrees),
				'candidate_rows'  => count($candidates),
				'skipped_rows'    => $skipped,
				'reasons'         => $reasons,
				'candidate_scope' => 'missing_or_incomplete_metadata_and_stored_finalizer_signals',
			),
		);
	}

	/**
	 * Return a cheap candidate reason when reconciliation may write metadata.
	 *
	 * @param  array<string,mixed> $wt Worktree list row.
	 */
	private function worktree_metadata_reconciliation_candidate_reason( array $wt ): ?string {
		$metadata      = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : array();
		$triage_status = $this->workspace_row_triage_status_from_metadata($metadata);
		if ( in_array($triage_status, array( 'ignored', 'quarantined' ), true) ) {
			return null;
		}

		if ( ! empty($wt['external']) ) {
			return 'external_worktree';
		}

		$identity = $this->recover_worktree_identity_from_metadata($wt);
		if ( array() !== (array) $identity['conflicts'] ) {
			return 'inconsistent_identity_metadata';
		}
		if ( array() !== (array) $identity['hydrated_fields'] ) {
			return 'recovered_identity_metadata';
		}

		$metadata = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : null;
		if ( null === $metadata || array() === $metadata ) {
			return 'missing_metadata';
		}

		foreach ( array( 'handle', 'repo', 'branch', 'path', 'created_at', 'observed_at', 'lifecycle_state' ) as $field ) {
			if ( ! array_key_exists($field, $metadata) || '' === trim( (string) $metadata[ $field ] ) ) {
				return 'incomplete_metadata';
			}
		}

		$state = WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state'] );
		if ( null === $state ) {
			return 'invalid_lifecycle_state';
		}

		if ( WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE !== $state && ( ! empty($metadata['pr_url']) || ! empty($metadata['pr_number']) ) ) {
			return 'stored_pr_signal';
		}

		return null;
	}

	/**
	 * Build one metadata reconciliation row for a current worktree listing row.
	 *
	 * @param  array<string,mixed> $wt Worktree list row.
	 * @return array{proposal?:array<string,mixed>,skip?:array<string,mixed>}
	 */
	private function build_worktree_metadata_reconciliation_row( array $wt, array &$github_cache = array(), array &$fetched = array() ): array {
		$handle   = (string) ( $wt['handle'] ?? '' );
		$identity = $this->recover_worktree_identity_from_metadata($wt);
		$repo     = (string) $identity['repo'];
		$branch   = (string) $identity['branch'];
		$path     = (string) $identity['path'];
		$metadata = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : array();
		$base_row = $this->build_worktree_metadata_reconciliation_base_row($handle, $repo, $branch, $path, $identity, $metadata);

		if ( ! empty($wt['external']) ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code' => 'external_worktree',
					'reason'      => 'external worktree outside the DMC workspace',
				)
			);
		}

		if ( array() !== (array) $identity['conflicts'] ) {
			$identity_classification = $this->classify_worktree_identity_metadata_conflict($wt, $identity);
			if ( ! empty($identity_classification['repairable']) && 'stale_identity_metadata' === (string) $identity_classification['reason_code'] ) {
				return $this->build_stale_worktree_identity_metadata_repair_row($base_row, $metadata, $identity_classification);
			}

			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code'              => (string) $identity_classification['reason_code'],
					'reason'                   => (string) $identity_classification['reason'],
					'identity_conflicts'       => $identity['conflicts'],
					'identity_classification'  => (string) $identity_classification['classification'],
					'proposed_source_of_truth' => $identity_classification['proposed_source_of_truth'],
					'next_command'             => (string) $identity_classification['next_command'],
				)
			);
		}

		$missing = $this->get_missing_worktree_identity_fields($handle, $repo, $branch, $path);
		if ( array() !== $missing ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code'    => 'missing_identity',
					'reason'         => 'current worktree row is missing required identity fields',
					'missing_fields' => $missing,
				)
			);
		}

		$parsed = $this->parse_handle($handle);
		if ( empty($parsed['is_worktree']) || $parsed['repo'] !== $repo ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code' => 'noncanonical_handle',
					'reason'      => 'worktree is not represented by a canonical <repo>@<slug> workspace handle',
				)
			);
		}

		if ( ! empty($identity['detached_branch']) && ! $this->has_stored_lifecycle_finalizer_context($metadata) ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code'   => 'detached_worktree',
					'reason'        => sprintf('git reports detached HEAD; stored metadata identifies branch %s', $branch),
					'actual_branch' => '',
					'hint'          => 'Reattach the worktree to the stored branch before applying metadata reconciliation, or remove it manually after review.',
				)
			);
		}

		$dirty = $wt['dirty'] ?? null;
		if ( null === $dirty ) {
			$dirty = $this->probe_worktree_dirty_count($path, self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( is_wp_error($dirty) ) {
				$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $path, $dirty, 'dirty-state probe', 'leaving lifecycle unchanged');
				return $this->build_worktree_metadata_reconciliation_skip($base_row, $diagnostic);
			}
		}
		$dirty    = (int) $dirty;
		$unpushed = $this->count_unpushed_commits($path);
		if ( is_wp_error($unpushed) ) {
			$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $path, $unpushed, 'cleanup safety probe', 'leaving lifecycle unchanged');
			return $this->build_worktree_metadata_reconciliation_skip($base_row, $diagnostic);
		}

		$resolved_wt      = array_merge(
			$wt,
			array(
				'repo'   => $repo,
				'branch' => $branch,
				'path'   => $path,
			)
		);
		$finalizer_signal = $this->detect_worktree_lifecycle_finalizer_signal($resolved_wt, $metadata, $github_cache, $fetched);
		if ( null !== $finalizer_signal && 'probe-timeout' === ( $finalizer_signal['signal'] ?? '' ) ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code' => 'probe_timeout',
					'reason'      => 'merge-signal probe timed out - leaving lifecycle unchanged: ' . $finalizer_signal['reason'],
				)
			);
		}

		if ( null !== $finalizer_signal && ! $this->has_explicit_cleanup_eligible_state($metadata) ) {
			if ( $dirty > 0 || $unpushed > 0 ) {
				return $this->build_worktree_metadata_reconciliation_skip(
					$base_row,
					array(
						'reason_code' => 'unsafe_cleanup_eligible_state',
						'reason'      => 'merged lifecycle signal found, but dirty or unpushed worktree is not auto-finalized',
						'dirty'       => $dirty,
						'unpushed'    => $unpushed,
						'signal'      => $finalizer_signal['signal'],
					)
				);
			}

			return $this->build_worktree_metadata_reconciliation_finalizer_proposal($base_row, $metadata, $handle, $repo, $branch, $path, $dirty, $unpushed, $finalizer_signal);
		}

		if ( $this->has_stored_lifecycle_finalizer_context($metadata) ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code' => 'insufficient_finalizer_signal',
					'reason'      => 'stored PR or finalizer metadata is present, but no definitive finalizer signal was available; leaving lifecycle unchanged',
				)
			);
		}

		$classification = $this->build_worktree_metadata_backfill_classification($metadata, $handle, $repo, $branch, $path);
		$proposed       = $classification['proposed_metadata'];
		$source_map     = $classification['source_map'];
		$invalid_fields = $classification['invalid_fields'];
		$missing_after  = array_values(
			array_filter(
				array(
					empty($proposed['created_at']) ? 'created_at' : '',
					empty($proposed['lifecycle_state']) ? 'lifecycle_state' : '',
				)
			)
		);
		if ( array() !== $missing_after ) {
			return $this->build_worktree_metadata_reconciliation_skip(
				$base_row,
				array(
					'reason_code'    => 'insufficient_signal',
					'reason'         => 'not enough stable metadata could be inferred safely',
					'missing_fields' => $missing_after,
				)
			);
		}

		$metadata_missing = $this->get_missing_worktree_metadata_fields($metadata);

		if ( array() === $metadata_missing && array() === $invalid_fields ) {
			return array();
		}

		return $this->build_worktree_metadata_backfill_proposal($base_row, $dirty, $unpushed, $metadata_missing, $invalid_fields, $proposed, $source_map);
	}

	/**
	 * Build the common row fields shared by skip rows and proposals.
	 *
	 * @param  string              $handle   Worktree handle.
	 * @param  string              $repo     Repository handle.
	 * @param  string              $branch   Branch name.
	 * @param  string              $path     Worktree path.
	 * @param  array<string,mixed> $identity Recovered identity metadata.
	 * @param  array<string,mixed> $metadata Persisted metadata.
	 * @return array<string,mixed>
	 */
	private function build_worktree_metadata_reconciliation_base_row( string $handle, string $repo, string $branch, string $path, array $identity, array $metadata ): array {
		return array(
			'handle'          => $handle,
			'repo'            => $repo,
			'branch'          => $branch,
			'path'            => $path,
			'hydrated_fields' => $identity['hydrated_fields'],
			'stored_identity' => $identity['stored_identity'],
			'metadata'        => array() === $metadata ? null : $metadata,
		);
	}

	/**
	 * Wrap a metadata reconciliation skip row.
	 *
	 * @param  array<string,mixed> $base_row Common reconciliation row fields.
	 * @param  array<string,mixed> $details  Skip-specific details.
	 * @return array{skip:array<string,mixed>}
	 */
	private function build_worktree_metadata_reconciliation_skip( array $base_row, array $details ): array {
		return array(
			'skip' => array_merge($base_row, $details),
		);
	}

	/**
	 * Identify missing current worktree identity fields.
	 *
	 * @param  string $handle Worktree handle.
	 * @param  string $repo   Repository handle.
	 * @param  string $branch Branch name.
	 * @param  string $path   Worktree path.
	 * @return string[]
	 */
	private function get_missing_worktree_identity_fields( string $handle, string $repo, string $branch, string $path ): array {
		return array_values(
			array_filter(
				array(
					'' === $handle ? 'handle' : '',
					'' === $repo ? 'repo' : '',
					'' === $branch ? 'branch' : '',
					'' === $path ? 'path' : '',
				)
			)
		);
	}

	/**
	 * Build an auto-finalization proposal from a merge signal.
	 *
	 * @param  array<string,mixed> $base_row         Common reconciliation row fields.
	 * @param  array<string,mixed> $metadata         Persisted metadata.
	 * @param  string              $handle           Worktree handle.
	 * @param  string              $repo             Repository handle.
	 * @param  string              $branch           Branch name.
	 * @param  string              $path             Worktree path.
	 * @param  int                 $dirty            Dirty file count.
	 * @param  int                 $unpushed         Unpushed commit count.
	 * @param  array<string,mixed> $finalizer_signal Finalizer evidence.
	 * @return array{proposal:array<string,mixed>}
	 */
	private function build_worktree_metadata_reconciliation_finalizer_proposal( array $base_row, array $metadata, string $handle, string $repo, string $branch, string $path, int $dirty, int $unpushed, array $finalizer_signal ): array {
		$finalized_state    = (string) ( $finalizer_signal['finalized_state'] ?? WorktreeContextInjector::STATE_MERGED );
		$finalizer_metadata = WorktreeContextInjector::build_finalizer_metadata(
			$finalized_state,
			isset($finalizer_signal['pr_url']) ? (string) $finalizer_signal['pr_url'] : null
		);
		$evidence           = array_filter(
			array(
				'signal'          => $finalizer_signal['signal'],
				'finalized_state' => $finalized_state,
				'reason'          => $finalizer_signal['reason'],
				'detected_at'     => gmdate('c'),
				'dirty'           => $dirty,
				'unpushed'        => $unpushed,
				'pr_url'          => $finalizer_signal['pr_url'] ?? null,
			),
			fn( $value ) => null !== $value && '' !== $value
		);
		$proposed           = array_merge(
			$metadata,
			array(
				'handle'       => $handle,
				'repo'         => $repo,
				'branch'       => $branch,
				'path'         => $path,
				'observed_at'  => gmdate('c'),
				'last_seen_at' => gmdate('c'),
			),
			$finalizer_metadata,
			array(
				'auto_finalized_by'            => 'worktree_reconcile_metadata',
				'auto_finalized_signal'        => $finalizer_signal['signal'],
				'auto_finalized_reason'        => $finalizer_signal['reason'],
				'cleanup_eligibility_evidence' => $evidence,
			)
		);

		if ( empty($proposed['created_at']) ) {
			$created_at = file_exists($path) ? filemtime($path) : false;
			if ( false !== $created_at ) {
				$proposed['created_at'] = gmdate('c', (int) $created_at);
			}
		}

		return array(
			'proposal' => array_merge(
				$base_row,
				array(
					'reason_code'       => 'auto_finalize_merged',
					'reason'            => 'merged PR or branch state proves lifecycle can be finalized as cleanup_eligible',
					'dirty'             => $dirty,
					'unpushed'          => $unpushed,
					'signal'            => $finalizer_signal['signal'],
					'pr_url'            => $finalizer_signal['pr_url'] ?? null,
					'proposed_metadata' => $proposed,
					'source_map'        => array(
						'handle'                       => 'filesystem',
						'repo'                         => 'filesystem',
						'branch'                       => 'git',
						'path'                         => 'git',
						'created_at'                   => empty($metadata['created_at']) ? 'filesystem' : 'metadata',
						'observed_at'                  => 'reconcile_run',
						'lifecycle_state'              => 'merge_signal',
						'finalized_state'              => 'merge_signal',
						'cleanup_eligibility_evidence' => 'merge_signal',
					),
				),
			),
		);
	}

	/**
	 * Build proposed metadata and field classifications for backfill proposals.
	 *
	 * @param  array<string,mixed> $metadata Persisted metadata.
	 * @param  string              $handle   Worktree handle.
	 * @param  string              $repo     Repository handle.
	 * @param  string              $branch   Branch name.
	 * @param  string              $path     Worktree path.
	 * @return array{proposed_metadata:array<string,mixed>,source_map:array<string,string>,invalid_fields:string[]}
	 */
	private function build_worktree_metadata_backfill_classification( array $metadata, string $handle, string $repo, string $branch, string $path ): array {
		$proposed   = $metadata;
		$source_map = array();
		$this->set_reconciled_metadata_field($proposed, $source_map, 'handle', $handle, 'filesystem');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'repo', $repo, 'filesystem');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'branch', $branch, 'git');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'path', $path, 'git');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'observed_at', gmdate('c'), 'reconcile_run');

		$origin_site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
		$origin_site_url  = function_exists('home_url') ? (string) home_url() : '';
		if ( empty($proposed['origin_site']) ) {
			$this->set_reconciled_metadata_field($proposed, $source_map, 'origin_site', '' !== $origin_site_name ? $origin_site_name : $origin_site_url, 'current_site');
		}
		if ( empty($proposed['origin_site_name']) ) {
			$this->set_reconciled_metadata_field($proposed, $source_map, 'origin_site_name', $origin_site_name, 'current_site');
		}
		if ( empty($proposed['origin_site_url']) ) {
			$this->set_reconciled_metadata_field($proposed, $source_map, 'origin_site_url', $origin_site_url, 'current_site');
		}

		$created_source = '';
		$created_at     = '';
		foreach ( array( 'created_at', 'timestamp' ) as $key ) {
			if ( ! empty($metadata[ $key ]) && false !== strtotime( (string) $metadata[ $key ]) ) {
				$created_at     = gmdate('c', (int) strtotime( (string) $metadata[ $key ]));
				$created_source = 'metadata';
				break;
			}
		}
		if ( '' === $created_at ) {
			$mtime = file_exists($path) ? filemtime($path) : false;
			if ( false !== $mtime ) {
				$created_at     = gmdate('c', (int) $mtime);
				$created_source = 'filesystem';
			}
		}
		if ( '' !== $created_at ) {
			$this->set_reconciled_metadata_field($proposed, $source_map, 'created_at', $created_at, $created_source);
		}

		$invalid_fields = array();
		$existing_state = isset($metadata['lifecycle_state']) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state']) : null;
		$raw_state      = isset($metadata['lifecycle_state']) ? (string) $metadata['lifecycle_state'] : '';
		if ( '' !== $raw_state && null === $existing_state ) {
			$invalid_fields[] = 'lifecycle_state';
		}
		if ( null !== $existing_state ) {
			$this->set_reconciled_metadata_field($proposed, $source_map, 'lifecycle_state', $existing_state, 'metadata');
		} else {
			$this->set_reconciled_metadata_field($proposed, $source_map, 'lifecycle_state', WorktreeContextInjector::STATE_ACTIVE, 'operator_plan');
		}

		return array(
			'proposed_metadata' => $proposed,
			'source_map'        => $source_map,
			'invalid_fields'    => $invalid_fields,
		);
	}

	/**
	 * Identify missing required persisted metadata fields.
	 *
	 * @param  array<string,mixed> $metadata Persisted metadata.
	 * @return string[]
	 */
	private function get_missing_worktree_metadata_fields( array $metadata ): array {
		$metadata_missing = array();
		foreach ( array( 'handle', 'repo', 'branch', 'path', 'created_at', 'observed_at', 'lifecycle_state' ) as $field ) {
			if ( ! array_key_exists($field, $metadata) || '' === (string) $metadata[ $field ] ) {
				$metadata_missing[] = $field;
			}
		}

		return $metadata_missing;
	}

	/**
	 * Build a metadata backfill proposal row.
	 *
	 * @param  array<string,mixed> $base_row         Common reconciliation row fields.
	 * @param  int                 $dirty            Dirty file count.
	 * @param  int                 $unpushed         Unpushed commit count.
	 * @param  string[]            $metadata_missing Missing metadata fields.
	 * @param  string[]            $invalid_fields   Invalid metadata fields.
	 * @param  array<string,mixed> $proposed         Proposed metadata.
	 * @param  array<string,mixed> $source_map       Proposed metadata source map.
	 * @return array{proposal:array<string,mixed>}
	 */
	private function build_worktree_metadata_backfill_proposal( array $base_row, int $dirty, int $unpushed, array $metadata_missing, array $invalid_fields, array $proposed, array $source_map ): array {
		return array(
			'proposal' => array_merge(
				$base_row,
				array(
					'reason_code'       => 'metadata_backfill',
					'reason'            => 'unmanaged worktree metadata can be reconciled without changing cleanup eligibility',
					'dirty'             => $dirty,
					'unpushed'          => $unpushed,
					'missing_fields'    => $metadata_missing,
					'invalid_fields'    => $invalid_fields,
					'proposed_metadata' => $proposed,
					'source_map'        => $source_map,
				)
			),
		);
	}

	/**
	 * Check whether metadata already stores the current explicit cleanup state.
	 *
	 * Legacy finalized records are still cleanup signals, but reconciliation should
	 * promote them to explicit cleanup_eligible metadata so later inventory-only
	 * cleanup has durable evidence to inspect.
	 *
	 * @param  array<string,mixed> $metadata Worktree metadata.
	 * @return bool
	 */
	private function has_explicit_cleanup_eligible_state( array $metadata ): bool {
		$state = isset($metadata['lifecycle_state']) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state']) : null;
		return WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE === $state;
	}

	/**
	 * Whether stored metadata indicates this row needs finalizer review, not active backfill.
	 *
	 * @param  array<string,mixed> $metadata Worktree metadata.
	 * @return bool
	 */
	private function has_stored_lifecycle_finalizer_context( array $metadata ): bool {
		foreach ( array( 'pr_url', 'pr_number', 'pr_repo', 'origin_task', 'finalized_state', 'cleanup_eligible_at' ) as $field ) {
			if ( ! empty($metadata[ $field ]) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect an unambiguous merge signal for lifecycle reconciliation.
	 *
	 * @param  array<string,mixed> $wt           Current worktree listing row.
	 * @param  array<string,mixed> $metadata     Persisted lifecycle metadata.
	 * @param  array               $github_cache Run-local GitHub lookup cache.
	 * @param  array               $fetched      Run-local fetched repo cache.
	 * @return array{signal:string,reason:string,finalized_state?:string,pr_url?:string}|null
	 */
	private function detect_worktree_lifecycle_finalizer_signal( array $wt, array $metadata, array &$github_cache, array &$fetched ): ?array {
		$repo      = (string) ( $wt['repo'] ?? '' );
		$branch    = (string) ( $wt['branch'] ?? '' );
		$pr_signal = $this->detect_stored_pr_merged_signal($metadata, $github_cache);
		if ( null !== $pr_signal ) {
			return $pr_signal;
		}

		$primary_path = '' !== $repo ? $this->get_primary_path($repo) : '';
		if ( '' === $repo || '' === $branch || ! is_dir($primary_path . '/.git') ) {
			return null;
		}

		if ( empty($fetched[ $repo ]) ) {
			$fetch = $this->run_git($primary_path, 'fetch --prune --quiet origin', self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( $this->is_git_timeout_error($fetch) ) {
				return array(
					'signal' => 'probe-timeout',
					'reason' => $fetch->get_error_message(),
				);
			}
			$fetched[ $repo ] = true;
		}

		$signal = $this->detect_merge_signal($primary_path, $repo, $branch, false, $github_cache);
		if ( null === $signal ) {
			return null;
		}

		if ( in_array( (string) ( $signal['signal'] ?? '' ), array( 'upstream-gone', 'local-merged' ), true) ) {
			return $signal;
		}

		return null;
	}

	/**
	 * Check stored PR metadata for a merged PR signal.
	 *
	 * @param  array<string,mixed> $metadata     Persisted lifecycle metadata.
	 * @param  array               $github_cache Run-local GitHub lookup cache.
	 * @return array{signal:string,reason:string,finalized_state?:string,pr_url?:string}|null
	 */
	private function detect_stored_pr_merged_signal( array $metadata, array &$github_cache ): ?array {
		$pr_repo   = isset($metadata['pr_repo']) ? (string) $metadata['pr_repo'] : '';
		$pr_number = isset($metadata['pr_number']) ? (int) $metadata['pr_number'] : 0;
		$pr_url    = isset($metadata['pr_url']) ? (string) $metadata['pr_url'] : '';

		if ( ( '' === $pr_repo || $pr_number <= 0 ) && '' !== $pr_url && preg_match('~^https?://github\.com/([^/]+)/([^/]+)/pull/(\d+)(?:[/?#].*)?$~', $pr_url, $matches) ) {
			$pr_repo   = $matches[1] . '/' . $matches[2];
			$pr_number = (int) $matches[3];
		}

		if ( '' === $pr_repo || $pr_number <= 0 ) {
			return null;
		}

		$cache_key = $pr_repo . '#' . $pr_number;
		if ( array_key_exists($cache_key, $github_cache) ) {
			$pr = $github_cache[ $cache_key ];
		} else {
			$pr                         = $this->fetch_github_pull_request($pr_repo, $pr_number);
			$github_cache[ $cache_key ] = $pr;
		}

		if ( ! is_array($pr) ) {
			return null;
		}

		$state = (string) ( $pr['state'] ?? '' );
		if ( empty($pr['merged_at']) && 'closed' !== $state ) {
			return null;
		}

		$merged = ! empty($pr['merged_at']);

		return array(
			'signal'          => $merged ? 'pr-merged' : 'pr-closed',
			'reason'          => $merged ? sprintf('stored PR #%d merged (%s)', $pr_number, $state) : sprintf('stored PR #%d closed without merge', $pr_number),
			'finalized_state' => $merged ? WorktreeContextInjector::STATE_MERGED : WorktreeContextInjector::STATE_CLOSED,
			'pr_url'          => (string) ( $pr['html_url'] ?? $pr_url ),
		);
	}

	/**
	 * Fetch one pull request from GitHub when API credentials are available.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_github_pull_request( string $slug, int $number ): ?array {
		if ( ! class_exists('\DataMachineCode\Abilities\GitHubAbilities') ) {
			return null;
		}

		// Pass the repo through so credential profiles with `allowed_repos`
		// can win over the global default profile when fetching merge state.
		$pat = \DataMachineCode\Abilities\GitHubAbilities::getPat(array( 'repo' => $slug ));
		if ( empty($pat) ) {
			return null;
		}

		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl($slug, 'pulls/' . $number),
			array(),
			$pat,
			self::CLEANUP_GITHUB_TIMEOUT
		);
		if ( is_wp_error($response) ) {
			return null;
		}

		$data = $response['data'] ?? null;
		return is_array($data) ? $data : null;
	}

	/**
	 * Set a reconciled metadata field and record its source.
	 *
	 * @param array<string,mixed>  $metadata   Metadata under construction.
	 * @param array<string,string> $source_map Field source map.
	 * @param string               $field      Field name.
	 * @param mixed                $value      Field value.
	 * @param string               $source     Source label.
	 */
	private function set_reconciled_metadata_field( array &$metadata, array &$source_map, string $field, mixed $value, string $source ): void {
		if ( null === $value || '' === (string) $value ) {
			return;
		}
		$metadata[ $field ]   = $value;
		$source_map[ $field ] = $source;
	}

	/**
	 * Classify identity metadata conflicts into operator-actionable buckets.
	 *
	 * @param  array<string,mixed> $wt       Worktree row.
	 * @param  array<string,mixed> $identity Recovered identity data.
	 * @return array<string,mixed>
	 */
	private function classify_worktree_identity_metadata_conflict( array $wt, array $identity ): array {
		$handle                        = (string) ( $wt['handle'] ?? '' );
		$repo                          = (string) ( $wt['repo'] ?? '' );
		$branch                        = (string) ( $wt['branch'] ?? '' );
		$path                          = rtrim( (string) ( $wt['path'] ?? '' ), '/' );
		$parsed                        = '' !== $handle ? $this->parse_handle( $handle ) : array(
			'repo'        => '',
			'branch_slug' => '',
			'is_worktree' => false,
		);
		$handle_branch                 = (string) ( $parsed['branch_slug'] ?? '' );
		$branch_slug                   = $this->slugify_branch($branch);
		$path_basename                 = '' !== $path ? basename($path) : '';
		$handle_path                   = '' !== $handle && $path_basename === $handle;
		$handle_repo                   = ! empty($parsed['is_worktree']) && (string) ( $parsed['repo'] ?? '' ) === $repo;
		$handle_branch_matches_current = '' !== $branch_slug && $branch_slug === $handle_branch;
		$default_branch                = $this->resolve_worktree_identity_default_branch( (string) ( $identity['repo'] ?? $repo ) );

		$base = array(
			'classification'           => 'manual_review_identity_metadata',
			'reason_code'              => 'manual_review_identity_metadata',
			'reason'                   => 'stored worktree identity metadata conflicts with the current row and no safe automatic source of truth is available',
			'repairable'               => false,
			'proposed_source_of_truth' => array(
				'handle' => 'manual_review',
				'repo'   => 'manual_review',
				'branch' => 'manual_review',
				'path'   => 'manual_review',
			),
			'next_command'             => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --format=json',
		);

		if ( $handle_repo && $handle_path && $handle_branch_matches_current ) {
			return array_merge(
				$base,
				array(
					'classification'           => 'stale_identity_metadata',
					'reason_code'              => 'stale_identity_metadata',
					'reason'                   => 'stored identity metadata is stale; current handle, path, and git branch agree',
					'repairable'               => true,
					'proposed_source_of_truth' => array(
						'handle' => 'filesystem_handle',
						'repo'   => 'filesystem_handle',
						'branch' => 'current_git_branch',
						'path'   => 'git_worktree_path',
					),
					'next_command'             => 'studio wp datamachine-code workspace worktree reconcile-metadata --apply --format=json',
				)
			);
		}

		if ( $handle_repo && $handle_path && '' !== $branch && $default_branch === $branch ) {
			return array_merge(
				$base,
				array(
					'classification'           => 'default_branch_checkout_in_feature_worktree',
					'reason_code'              => 'default_branch_checkout_in_feature_worktree',
					'reason'                   => sprintf('worktree handle is feature-scoped, but git is currently on the default branch %s', $branch),
					'proposed_source_of_truth' => array(
						'handle' => 'filesystem_handle',
						'repo'   => 'filesystem_handle',
						'branch' => 'operator_review_required',
						'path'   => 'git_worktree_path',
					),
					'next_command'             => sprintf('git -C %s switch <intended-feature-branch>', escapeshellarg($path)),
				)
			);
		}

		if ( $handle_repo && $handle_path && '' !== $branch && ! $handle_branch_matches_current ) {
			return array_merge(
				$base,
				array(
					'classification'           => 'branch_renamed_worktree',
					'reason_code'              => 'branch_renamed_worktree',
					'reason'                   => 'git branch no longer matches the canonical branch slug encoded in the worktree handle/path',
					'proposed_source_of_truth' => array(
						'handle' => 'filesystem_handle',
						'repo'   => 'filesystem_handle',
						'branch' => 'current_git_branch',
						'path'   => 'git_worktree_path',
					),
					'next_command'             => sprintf('studio wp datamachine-code workspace worktree add %s %s --from=%s', escapeshellarg($repo), escapeshellarg($branch), escapeshellarg($branch)),
				)
			);
		}

		return $base;
	}

	/**
	 * Resolve the short default branch name for identity diagnostics.
	 */
	private function resolve_worktree_identity_default_branch( string $repo ): string {
		if ( '' === $repo ) {
			return '';
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path . '/.git') ) {
			return '';
		}

		$default_ref = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($default_ref) || ! is_string($default_ref) || '' === $default_ref ) {
			return '';
		}

		$prefix = 'refs/remotes/origin/';
		return str_starts_with($default_ref, $prefix) ? substr($default_ref, strlen($prefix)) : basename($default_ref);
	}

	/**
	 * Build a metadata-only repair proposal for stale stored identity metadata.
	 *
	 * @param  array<string,mixed> $base_row       Shared reconciliation row data.
	 * @param  array<string,mixed> $metadata       Stored metadata.
	 * @param  array<string,mixed> $classification Identity classification.
	 * @return array{proposal?:array<string,mixed>,skip?:array<string,mixed>}
	 */
	private function build_stale_worktree_identity_metadata_repair_row( array $base_row, array $metadata, array $classification ): array {
		$handle = (string) ( $base_row['handle'] ?? '' );
		$repo   = (string) ( $base_row['repo'] ?? '' );
		$branch = (string) ( $base_row['branch'] ?? '' );
		$path   = (string) ( $base_row['path'] ?? '' );

		$dirty = $this->probe_worktree_dirty_count($path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($dirty) ) {
			$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $path, $dirty, 'dirty-state probe', 'leaving stale identity metadata unchanged');
			return array( 'skip' => array_merge($base_row, $classification, $diagnostic) );
		}

		$unpushed = $this->count_unpushed_commits($path);
		if ( is_wp_error($unpushed) ) {
			$diagnostic = $this->classify_worktree_git_probe_failure($handle, $repo, $path, $unpushed, 'cleanup safety probe', 'leaving stale identity metadata unchanged');
			return array( 'skip' => array_merge($base_row, $classification, $diagnostic) );
		}

		if ( (int) $dirty > 0 || (int) $unpushed > 0 ) {
			return array(
				'skip' => array_merge(
					$base_row,
					$classification,
					array(
						'reason_code' => 'unsafe_stale_identity_metadata',
						'reason'      => 'stale identity metadata is repairable, but dirty or unpushed worktree state blocks automatic metadata writes',
						'dirty'       => (int) $dirty,
						'unpushed'    => (int) $unpushed,
					)
				),
			);
		}

		$proposed   = $metadata;
		$source_map = array();
		$this->set_reconciled_metadata_field($proposed, $source_map, 'handle', $handle, 'filesystem');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'repo', $repo, 'filesystem');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'branch', $branch, 'git');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'path', $path, 'git');
		$this->set_reconciled_metadata_field($proposed, $source_map, 'observed_at', gmdate('c'), 'reconcile_run');

		$created_at = '';
		if ( ! empty($metadata['created_at']) && false !== strtotime( (string) $metadata['created_at'] ) ) {
			$created_at = gmdate('c', (int) strtotime( (string) $metadata['created_at'] ) );
			$this->set_reconciled_metadata_field($proposed, $source_map, 'created_at', $created_at, 'metadata');
		} else {
			$mtime = file_exists($path) ? filemtime($path) : false;
			if ( false !== $mtime ) {
				$created_at = gmdate('c', (int) $mtime);
				$this->set_reconciled_metadata_field($proposed, $source_map, 'created_at', $created_at, 'filesystem');
			}
		}

		$state = isset($metadata['lifecycle_state']) ? WorktreeContextInjector::normalize_state( (string) $metadata['lifecycle_state'] ) : null;
		$this->set_reconciled_metadata_field($proposed, $source_map, 'lifecycle_state', $state ?? WorktreeContextInjector::STATE_ACTIVE, null === $state ? 'operator_plan' : 'metadata');

		return array(
			'proposal' => array_merge(
				$base_row,
				array(
					'reason_code'              => 'stale_identity_metadata',
					'reason'                   => (string) $classification['reason'],
					'dirty'                    => (int) $dirty,
					'unpushed'                 => (int) $unpushed,
					'identity_conflicts'       => $base_row['identity_conflicts'] ?? array(),
					'identity_classification'  => 'stale_identity_metadata',
					'proposed_source_of_truth' => $classification['proposed_source_of_truth'],
					'next_command'             => (string) $classification['next_command'],
					'proposed_metadata'        => $proposed,
					'source_map'               => $source_map,
				)
			),
		);
	}

	/**
	 * Apply a reviewed metadata reconciliation plan after exact revalidation.
	 *
	 * @param  array<string,mixed> $plan Dry-run plan.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_worktree_metadata_reconciliation_plan( array $plan ): array|\WP_Error {
		$planned = $this->extract_worktree_metadata_reconciliation_plan($plan);
		if ( $planned instanceof \WP_Error ) {
			return $planned;
		}

		$current_by_handle = array();
		if ( ! empty($plan['direct_apply']) ) {
			foreach ( $planned as $row ) {
				$handle = (string) ( $row['handle'] ?? '' );
				if ( '' === $handle ) {
					continue;
				}
				$current_by_handle[ $handle ] = array(
					'handle' => $handle,
					'repo'   => (string) ( $row['repo'] ?? '' ),
					'branch' => (string) ( $row['branch'] ?? '' ),
					'path'   => (string) ( $row['path'] ?? '' ),
					'dirty'  => (int) ( $row['dirty'] ?? 0 ),
				);
			}
		} else {
			$listing = $this->worktree_list();
			if ( $listing instanceof \WP_Error ) {
				return $listing;
			}

			foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
				$handle = (string) ( $wt['handle'] ?? '' );
				if ( '' !== $handle ) {
					$current_by_handle[ $handle ] = $wt;
				}
			}
		}

		$written = array();
		$skipped = array();
		foreach ( $planned as $row ) {
			$handle  = (string) ( $row['handle'] ?? '' );
			$current = $current_by_handle[ $handle ] ?? null;
			if ( null === $current ) {
				$skipped[] = $this->build_reconcile_apply_skip($row, null, 'plan_not_current', 'planned worktree is no longer present in the current listing');
				continue;
			}

			$mismatches = array();
			foreach ( array( 'repo', 'branch', 'path' ) as $field ) {
				if ( (string) ( $row[ $field ] ?? '' ) !== (string) ( $current[ $field ] ?? '' ) ) {
					$mismatches[] = sprintf('%s planned=%s current=%s', $field, (string) ( $row[ $field ] ?? '' ), (string) ( $current[ $field ] ?? '' ));
				}
			}
			if ( array() !== $mismatches ) {
				$skipped[] = $this->build_reconcile_apply_skip($row, $current, 'plan_identity_mismatch', 'planned identity does not match current row: ' . implode('; ', $mismatches));
				continue;
			}

			$metadata   = (array) ( $row['proposed_metadata'] ?? array() );
			$source_map = (array) ( $row['source_map'] ?? array() );
			$state      = WorktreeContextInjector::normalize_state( (string) ( $metadata['lifecycle_state'] ?? '' ));
			if ( null === $state ) {
				$skipped[] = $this->build_reconcile_apply_skip($row, $current, 'invalid_lifecycle_state', 'proposed lifecycle_state is invalid');
				continue;
			}
			$metadata['lifecycle_state'] = $state;

			if ( WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE === $state ) {
				$path  = (string) ( $current['path'] ?? '' );
				$dirty = ! empty($plan['direct_apply']) ? $this->probe_worktree_dirty_count($path, self::CLEANUP_GIT_PROBE_TIMEOUT) : (int) ( $current['dirty'] ?? 0 );
				if ( is_wp_error($dirty) ) {
					$diagnostic = $this->classify_worktree_git_probe_failure($handle, (string) ( $current['repo'] ?? $row['repo'] ?? '' ), $path, $dirty, 'dirty-state probe', 'refusing cleanup_eligible metadata write');
					$skipped[]  = array_merge(
						$this->build_reconcile_apply_skip($row, $current, (string) $diagnostic['reason_code'], (string) $diagnostic['reason']),
						$diagnostic
					);
					continue;
				}
				$unpushed = $this->count_unpushed_commits($path);
				if ( is_wp_error($unpushed) ) {
					$diagnostic = $this->classify_worktree_git_probe_failure($handle, (string) ( $current['repo'] ?? $row['repo'] ?? '' ), $path, $unpushed, 'cleanup safety probe', 'refusing cleanup_eligible metadata write');
					$skipped[]  = array_merge(
						$this->build_reconcile_apply_skip($row, $current, (string) $diagnostic['reason_code'], (string) $diagnostic['reason']),
						$diagnostic
					);
					continue;
				}
				if ( $dirty > 0 || $unpushed > 0 ) {
					$skipped[] = $this->build_reconcile_apply_skip($row, $current, 'unsafe_cleanup_eligible_state', 'refusing to mark dirty or unpushed worktree cleanup_eligible from a reconciliation plan');
					continue;
				}
			}

			foreach ( array( 'handle', 'repo', 'branch', 'path', 'created_at', 'observed_at', 'lifecycle_state' ) as $field ) {
				if ( ! isset($source_map[ $field ]) || '' === (string) $source_map[ $field ] ) {
					$skipped[] = $this->build_reconcile_apply_skip($row, $current, 'missing_source_map', sprintf('proposed field %s is missing a source', $field));
					continue 2;
				}
			}

			$metadata['reconciled_at']      = gmdate('c');
			$metadata['reconciled_sources'] = $source_map;
			WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);

			$written[] = array(
				'handle'   => $handle,
				'repo'     => (string) ( $current['repo'] ?? '' ),
				'branch'   => (string) ( $current['branch'] ?? '' ),
				'path'     => (string) ( $current['path'] ?? '' ),
				'metadata' => WorktreeContextInjector::get_metadata($handle),
			);
		}

		$classified_skips = $this->classify_worktree_metadata_reconciliation_skips($skipped);

		$inspected = isset($plan['summary']['inspected']) ? (int) $plan['summary']['inspected'] : count( (array) ( $listing['worktrees'] ?? array() ));
		$result    = array(
			'success'            => true,
			'dry_run'            => false,
			'applied'            => true,
			'direct_apply'       => ! empty($plan['direct_apply']),
			'generated_at'       => gmdate('c'),
			'workspace_path'     => $this->workspace_path,
			'proposals'          => $planned,
			'written'            => $written,
			'skipped'            => $skipped,
			'still_unsafe'       => $classified_skips['still_unsafe'],
			'external_worktrees' => $classified_skips['external_worktrees'],
			'summary'            => $this->build_worktree_metadata_reconciliation_summary($inspected, $planned, $written, $skipped),
		);

		if ( isset($plan['pagination']) && is_array($plan['pagination']) ) {
			$result['pagination'] = $plan['pagination'];
			if ( ! empty($plan['direct_apply']) && count($written) > 0 ) {
				$result['pagination'] = $this->restart_worktree_metadata_reconciliation_pagination( (array) $result['pagination'] );
			}
		}
		if ( isset($plan['evidence']) && is_array($plan['evidence']) ) {
			$result['evidence'] = array_merge(
				$plan['evidence'],
				array(
					'scope'        => ! empty($plan['direct_apply']) ? 'paginated metadata reconciliation direct apply' : (string) ( $plan['evidence']['scope'] ?? 'paginated metadata reconciliation apply-plan' ),
					'apply_source' => ! empty($plan['direct_apply']) ? 'direct_apply' : 'apply_plan',
				)
			);
		}

		return $result;
	}

	/**
	 * Split reconciliation skips into stable operator-facing buckets.
	 *
	 * @param  array<int,array<string,mixed>> $skipped Skipped rows.
	 * @return array{still_unsafe:array<int,array<string,mixed>>,external_worktrees:array<int,array<string,mixed>>}
	 */
	private function classify_worktree_metadata_reconciliation_skips( array $skipped ): array {
		$still_unsafe       = array();
		$external_worktrees = array();

		foreach ( $skipped as $row ) {
			$reason_code = (string) ( $row['reason_code'] ?? '' );
			if ( 'external_worktree' === $reason_code ) {
				$external_worktrees[] = $row;
			}
			if ( in_array($reason_code, array( 'unsafe_cleanup_eligible_state', 'plan_identity_mismatch', 'plan_not_current' ), true) ) {
				$still_unsafe[] = $row;
			}
		}

		return array(
			'still_unsafe'       => $still_unsafe,
			'external_worktrees' => $external_worktrees,
		);
	}

	/**
	 * Extract reconciliation proposals from a dry-run plan.
	 *
	 * @param  array<string,mixed> $plan Plan file.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function extract_worktree_metadata_reconciliation_plan( array $plan ): array|\WP_Error {
		$proposals = $plan['proposals'] ?? null;
		if ( ! is_array($proposals) ) {
			return new \WP_Error('invalid_metadata_reconcile_plan', 'Metadata reconciliation plan must contain a proposals array.', array( 'status' => 400 ));
		}

		foreach ( $proposals as $index => $row ) {
			if ( ! is_array($row) ) {
				return new \WP_Error('invalid_metadata_reconcile_plan', sprintf('Plan proposal #%d is not an object.', (int) $index), array( 'status' => 400 ));
			}
			foreach ( array( 'handle', 'repo', 'branch', 'path', 'proposed_metadata', 'source_map' ) as $field ) {
				if ( ! array_key_exists($field, $row) || ( is_string($row[ $field ]) && '' === trim($row[ $field ]) ) ) {
					return new \WP_Error('invalid_metadata_reconcile_plan', sprintf('Plan proposal #%d is missing %s.', (int) $index, $field), array( 'status' => 400 ));
				}
			}
		}

		return array_values($proposals);
	}

	/**
	 * Build an apply skip row.
	 *
	 * @param  array<string,mixed>      $planned Planned row.
	 * @param  array<string,mixed>|null $current Current row.
	 * @param  string                   $code    Reason code.
	 * @param  string                   $reason  Human reason.
	 * @return array<string,mixed>
	 */
	private function build_reconcile_apply_skip( array $planned, ?array $current, string $code, string $reason ): array {
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
	 * Build metadata reconciliation pagination evidence.
	 *
	 * @return array<string,mixed>
	 */
	private function build_worktree_metadata_reconciliation_pagination( int $total, int $scanned, int $limit, int $offset ): array {
		$next_offset = $offset + $scanned;
		$complete    = $next_offset >= $total;

		return array(
			'total'       => $total,
			'offset'      => $offset,
			'limit'       => $limit,
			'scanned'     => $scanned,
			'partial'     => ! $complete,
			'complete'    => $complete,
			'next_offset' => $complete ? null : $next_offset,
		);
	}

	/**
	 * Restart follow-up apply pages after writes because the candidate set changed.
	 *
	 * @param  array<string,mixed> $pagination Pagination payload.
	 * @return array<string,mixed>
	 */
	private function restart_worktree_metadata_reconciliation_pagination( array $pagination ): array {
		if ( empty($pagination['partial']) || null === ( $pagination['next_offset'] ?? null ) ) {
			return $pagination;
		}

		$pagination['next_offset']  = 0;
		$pagination['next_command'] = sprintf(
			'studio wp datamachine-code workspace worktree reconcile-metadata --apply --limit=%d --offset=0%s --format=json',
			(int) ( $pagination['limit'] ?? self::METADATA_RECONCILE_DEFAULT_LIMIT ),
			$this->worktree_metadata_reconciliation_budget_arg( (string) ( $pagination['next_command'] ?? '' ) )
		);

		return $pagination;
	}

	/**
	 * Extract the existing budget argument from a generated continuation command.
	 */
	private function worktree_metadata_reconciliation_budget_arg( string $command ): string {
		if ( preg_match('/ --until-budget=([^ ]+)/', $command, $matches) ) {
			return ' --until-budget=' . $matches[1];
		}

		return '';
	}

	/**
	 * Probe the dirty file count for a single worktree path.
	 *
	 * @param  string $path Worktree path.
	 * @return int|\WP_Error Dirty file count, or WP_Error when git probe failed.
	 */
	private function probe_worktree_dirty_count( string $path, int $timeout_seconds = 0 ): int|\WP_Error {
		if ( '' === $path || ! is_dir($path) ) {
			return new \WP_Error('worktree_path_missing', 'worktree path is not a directory', array( 'status' => 400 ));
		}
		$result = $this->run_git($path, 'status --porcelain', $timeout_seconds);
		if ( is_wp_error($result) ) {
			return $result;
		}
		$lines = array_filter(array_map('trim', explode("\n", (string) ( $result['output'] ?? '' ))));
		return count($lines);
	}

	/**
	 * Build stable reconciliation summary counts.
	 *
	 * @param  int   $inspected Worktree rows inspected.
	 * @param  array $proposals Proposal rows.
	 * @param  array $written   Written rows.
	 * @param  array $skipped   Skipped rows.
	 * @return array<string,mixed>
	 */
	private function build_worktree_metadata_reconciliation_summary( int $inspected, array $proposals, array $written, array $skipped ): array {
		$skipped_by_reason = array();
		foreach ( $skipped as $row ) {
			$code                       = (string) ( $row['reason_code'] ?? 'unknown' );
			$skipped_by_reason[ $code ] = ( $skipped_by_reason[ $code ] ?? 0 ) + 1;
		}
		ksort($skipped_by_reason);

		$states = array();
		foreach ( $proposals as $row ) {
			$state            = (string) ( $row['proposed_metadata']['lifecycle_state'] ?? 'unknown' );
			$states[ $state ] = ( $states[ $state ] ?? 0 ) + 1;
		}
		ksort($states);

		return array(
			'inspected'         => $inspected,
			'proposed'          => count($proposals),
			'written'           => count($written),
			'skipped'           => count($skipped),
			'skipped_by_reason' => $skipped_by_reason,
			'proposed_by_state' => $states,
			'slow_rows'         => $this->summarize_slow_worktree_rows(array_merge($proposals, $skipped)),
		);
	}

	/**
	 * Summarize the slowest worktree rows from an expensive report page.
	 *
	 * @param  array<int,array<string,mixed>> $rows Timed rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function summarize_slow_worktree_rows( array $rows ): array {
		$timed = array_values(array_filter($rows, fn( $row ) => is_array($row) && isset($row['elapsed_ms'])));
		usort($timed, fn( $a, $b ) => (int) ( $b['elapsed_ms'] ?? 0 ) <=> (int) ( $a['elapsed_ms'] ?? 0 ));

		return array_map(
			fn( $row ) => array_filter(
				array(
					'handle'      => (string) ( $row['handle'] ?? '' ),
					'repo'        => (string) ( $row['repo'] ?? '' ),
					'branch'      => (string) ( $row['branch'] ?? '' ),
					'elapsed_ms'  => (int) ( $row['elapsed_ms'] ?? 0 ),
					'action'      => (string) ( $row['suggested_action'] ?? '' ),
					'reason_code' => (string) ( $row['reason_code'] ?? '' ),
				),
				fn( $value ) => '' !== $value
			),
			array_slice($timed, 0, 10)
		);
	}
}
