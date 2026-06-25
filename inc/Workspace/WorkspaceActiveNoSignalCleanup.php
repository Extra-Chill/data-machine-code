<?php
/**
 * Active/no-signal worktree cleanup evidence and apply helpers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

trait WorkspaceActiveNoSignalCleanup {

	/**
	 * Build a bounded evidence report for active/no-signal worktrees.
	 *
	 * This is review-only. It never deletes worktrees or branches; it gathers the
	 * facts needed to separate live work from abandoned branches before a later,
	 * explicit cleanup action.
	 *
	 * @param  array<string,mixed> $opts Report options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_report( array $opts = array() ): array|\WP_Error {
		$started_at     = microtime(true);
		$limit          = array_key_exists('limit', $opts) ? (int) $opts['limit'] : 25;
		$offset         = array_key_exists('offset', $opts) ? max(0, (int) $opts['offset']) : 0;
		$next_operation = isset($opts['next_command_operation']) && is_string($opts['next_command_operation']) && preg_match('/^active-no-signal-[a-z-]+$/', $opts['next_command_operation'])
			? $opts['next_command_operation']
			: 'active-no-signal-report';
		$next_dry_run   = 'active-no-signal-report' !== $next_operation && ! empty($opts['dry_run']);
		if ( $limit <= 0 ) {
			return new \WP_Error('invalid_active_no_signal_limit', 'Active/no-signal report --limit must be greater than 0.', array( 'status' => 400 ));
		}
		if ( isset($opts['until_budget']) && '' !== trim( (string) $opts['until_budget']) ) {
			$budget_seconds = $this->parse_worktree_metadata_reconciliation_budget(trim( (string) $opts['until_budget']));
			if ( is_wp_error($budget_seconds) ) {
				return $budget_seconds;
			}
		}

		$inventory = $this->worktree_cleanup_inventory_only('', '', false);
		if ( is_wp_error($inventory) ) {
			return $inventory;
		}

		$active = array_values(
			array_filter(
				(array) ( $inventory['skipped'] ?? array() ),
				fn( $row ) => is_array($row) && in_array( (string) ( $row['reason_code'] ?? '' ), array( 'active_no_signal', 'no_inventory_cleanup_signal', 'lifecycle_reconciliation_candidate' ), true)
			)
		);
		$total  = count($active);
		$page   = array_slice($active, $offset, $limit);

		/** @var array<string,mixed> $github_cache */
		$github_cache = array();
		$probe_cache  = array(
			'default_ref'             => array(),
			'github_slug'             => array(),
			'remote_tracking'         => array(),
			'commits_outside_default' => array(),
			'stats'                   => array(
				'default_ref'             => array(
					'hits'   => 0,
					'misses' => 0,
				),
				'github_slug'             => array(
					'hits'   => 0,
					'misses' => 0,
				),
				'remote_tracking'         => array(
					'hits'   => 0,
					'misses' => 0,
				),
				'commits_outside_default' => array(
					'hits'   => 0,
					'misses' => 0,
				),
			),
		);
		$rows         = array();
		$summary      = array(
			'total_active_no_signal' => $total,
			'inspected'              => 0,
			'by_suggested_action'    => array(),
			'dirty_or_unpushed'      => 0,
			'with_pr'                => 0,
			'without_pr'             => 0,
		);

		$budget_context = $this->build_worktree_loop_budget_context($opts, $started_at);
		$budget_stopped = false;
		foreach ( $page as $index => $row ) {
			if ( null !== $budget_context && $this->is_worktree_loop_budget_exhausted($budget_context) ) {
				$budget_stopped = true;
				$page           = array_slice($page, 0, $index);
				break;
			}
			$row_started            = microtime(true);
			$evidence               = $this->build_active_no_signal_evidence_row($row, $github_cache, $probe_cache);
			$evidence['elapsed_ms'] = (int) round(( microtime(true) - $row_started ) * 1000);
			$rows[]                 = $evidence;
			++$summary['inspected'];

			$action                                    = (string) ( $evidence['suggested_action'] ?? 'insufficient_signal' );
			$summary['by_suggested_action'][ $action ] = (int) ( $summary['by_suggested_action'][ $action ] ?? 0 ) + 1;
			if ( (int) ( $evidence['dirty'] ?? 0 ) > 0 || (int) ( $evidence['unpushed'] ?? 0 ) > 0 ) {
				++$summary['dirty_or_unpushed'];
			}
			if ( ! empty($evidence['pr']['number']) ) {
				++$summary['with_pr'];
			} else {
				++$summary['without_pr'];
			}
		}

		$next_offset = ( $offset + count($page) ) < $total ? $offset + count($page) : null;
		$pagination  = array(
			'total'        => $total,
			'offset'       => $offset,
			'limit'        => $limit,
			'scanned'      => count($page),
			'partial'      => null !== $next_offset,
			'complete'     => null === $next_offset,
			'next_offset'  => $next_offset,
			'next_command' => null === $next_offset ? null : sprintf('studio wp datamachine-code workspace worktree %s%s --limit=%d --offset=%d%s --format=json', $next_operation, $next_dry_run ? ' --dry-run' : '', $limit, $next_offset, null !== $budget_context ? ' --until-budget=' . (string) $budget_context['label'] : ''),
		);
		if ( $budget_stopped ) {
			$pagination['partial']  = true;
			$pagination['complete'] = false;
		}

		return array(
			'success'      => true,
			'mode'         => 'active_no_signal_report',
			'review_only'  => true,
			'generated_at' => gmdate('c'),
			'rows'         => $rows,
			'summary'      => array_merge($summary, array( 'slow_rows' => $this->summarize_slow_worktree_rows($rows) )),
			'pagination'   => $pagination,
			'evidence'     => array(
				'scope'       => 'review-only active/no-signal and lifecycle reconciliation worktree evidence',
				'safety'      => 'No worktrees or remote branches are deleted. Dirty and unpushed probes are evidence only.',
				'budget'      => null === $budget_context ? null : $this->summarize_worktree_loop_budget_context($budget_context, $budget_stopped),
				'probe_cache' => $probe_cache['stats'],
			),
		);
	}

	/**
	 * Promote finalized PR evidence from the active/no-signal report into cleanup metadata.
	 *
	 * This writes lifecycle metadata only. It never removes worktrees; callers use
	 * bounded cleanup-eligible apply after reviewing the written rows.
	 *
	 * @param  array<string,mixed> $opts Apply options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_finalized_apply( array $opts = array() ): array|\WP_Error {
		return $this->apply_active_no_signal_metadata(
			$opts,
			array(
				'operation'      => 'active-no-signal-finalized-apply',
				'mode'           => 'active_no_signal_finalized_apply',
				'destructive'    => false,
				'summary_extra'  => static function ( array $report ): array {
					return array( 'report_action_counts' => $report['summary']['by_suggested_action'] ?? array() );
				},
				'prepare_row'    => function ( array $row ): array|\WP_Error {
					if ( 'finalized_pr_reconcile' !== (string) ( $row['suggested_action'] ?? '' ) ) {
						return new \WP_Error('not_finalized_pr', 'row is not a finalized merged PR candidate');
					}

					return $row;
				},
				'build_metadata' => fn( array $row ): array|\WP_Error => $this->build_active_no_signal_finalized_metadata($row),
				'build_planned'  => static function ( array $row, array $metadata ): array {
					return array(
						'handle'   => (string) ( $row['handle'] ?? '' ),
						'repo'     => (string) ( $row['repo'] ?? '' ),
						'branch'   => (string) ( $row['branch'] ?? '' ),
						'path'     => (string) ( $row['path'] ?? '' ),
						'pr'       => $row['pr'] ?? null,
						'metadata' => $metadata,
					);
				},
				'evidence'       => array(
					'scope'  => 'promote finalized active_no_signal PR evidence into cleanup_eligible metadata',
					'safety' => 'Revalidates dirty, unpushed, identity, and closed+merged PR evidence before writing metadata. Does not delete worktrees.',
				),
			)
		);
	}

	/**
	 * Promote effectively clean upstream-equivalent active/no-signal rows into cleanup metadata.
	 *
	 * This writes lifecycle metadata only. It never removes worktrees; callers use
	 * bounded cleanup-eligible apply after reviewing the written rows.
	 *
	 * @param  array<string,mixed> $opts Apply options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_equivalent_clean_apply( array $opts = array() ): array|\WP_Error {
		return $this->apply_active_no_signal_metadata(
			$opts,
			array(
				'operation'      => 'active-no-signal-equivalent-clean-apply',
				'mode'           => 'active_no_signal_equivalent_clean_apply',
				'destructive'    => false,
				'summary_extra'  => static function ( array $report ): array {
					return array( 'report_action_counts' => $report['summary']['by_suggested_action'] ?? array() );
				},
				'prepare_row'    => function ( array $row ): array|\WP_Error {
					$effective_status = (string) ( $row['upstream_equivalence']['effective_status'] ?? '' );
					if ( ! in_array($effective_status, array( 'equivalent_clean', 'contained_non_default_remote' ), true) ) {
						return new \WP_Error('not_equivalent_clean', 'row is not effectively clean upstream-equivalent work');
					}

					return $row;
				},
				'build_metadata' => fn( array $row ): array|\WP_Error => $this->build_active_no_signal_equivalent_clean_metadata($row),
				'build_planned'  => static function ( array $row, array $metadata ): array {
					return array(
						'handle'               => (string) ( $row['handle'] ?? '' ),
						'repo'                 => (string) ( $row['repo'] ?? '' ),
						'branch'               => (string) ( $row['branch'] ?? '' ),
						'path'                 => (string) ( $row['path'] ?? '' ),
						'upstream_equivalence' => $metadata['cleanup_eligibility_evidence']['upstream_equivalence'] ?? null,
						'metadata'             => $metadata,
					);
				},
				'evidence'       => array(
					'scope'  => 'promote effectively clean upstream-equivalent active_no_signal rows into cleanup_eligible metadata',
					'safety' => 'Revalidates upstream-equivalence evidence before writing metadata. Does not delete worktrees.',
				),
			)
		);
	}

	/**
	 * Promote clean active/no-signal rows already merged to default into cleanup metadata.
	 *
	 * This writes lifecycle metadata only. It never removes worktrees; callers use
	 * bounded cleanup-eligible apply after reviewing the written rows.
	 *
	 * @param  array<string,mixed> $opts Apply options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_merged_apply( array $opts = array() ): array|\WP_Error {
		return $this->apply_active_no_signal_metadata(
			$opts,
			array(
				'operation'        => 'active-no-signal-merged-apply',
				'mode'             => 'active_no_signal_merged_apply',
				'destructive'      => false,
				'upsert_inventory' => true,
				'summary_extra'    => static function ( array $report ): array {
					return array( 'report_action_counts' => $report['summary']['by_suggested_action'] ?? array() );
				},
				'prepare_row'      => function ( array $row ): array|\WP_Error {
					if ( 'merged_to_default' !== (string) ( $row['suggested_action'] ?? '' ) ) {
						return new \WP_Error('not_merged_to_default', 'row is not a clean merged-to-default candidate');
					}

					return $row;
				},
				'build_metadata'   => fn( array $row ): array|\WP_Error => $this->build_active_no_signal_merged_to_default_metadata($row),
				'build_planned'    => static function ( array $row, array $metadata ): array {
					return array(
						'handle'   => (string) ( $row['handle'] ?? '' ),
						'repo'     => (string) ( $row['repo'] ?? '' ),
						'branch'   => (string) ( $row['branch'] ?? '' ),
						'path'     => (string) ( $row['path'] ?? '' ),
						'evidence' => $metadata['cleanup_eligibility_evidence'] ?? null,
						'metadata' => $metadata,
					);
				},
				'evidence'         => array(
					'scope'  => 'promote clean active_no_signal rows contained in remote default into cleanup_eligible metadata',
					'safety' => 'Revalidates clean worktree, no unpushed commits, containment, primary protection, branch identity, and merged-to-default evidence before writing metadata. Does not delete worktrees.',
				),
			)
		);
	}

	/**
	 * Promote clean remote-tracking active/no-signal rows into cleanup metadata.
	 *
	 * @param  array<string,mixed> $opts Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_active_no_signal_remote_clean_apply( array $opts = array() ): array|\WP_Error {
		return $this->apply_active_no_signal_metadata(
			$opts,
			array(
				'operation'          => 'active-no-signal-remote-clean-apply',
				'mode'               => 'active_no_signal_remote_clean_apply',
				'review_only'        => false,
				'written_as_planned' => true,
				'inspected_fallback' => 'rows',
				'summary_extra'      => static function (): array {
					return array(
						'candidate_action'   => 'remote_tracking_clean',
						'candidate_evidence' => 'clean worktree with no unpushed commits and an existing remote tracking branch',
					);
				},
				'prepare_row'        => function ( array $row ): array|\WP_Error {
					if ( 'remote_tracking_clean' !== (string) ( $row['suggested_action'] ?? '' ) ) {
						return new \WP_Error('not_remote_tracking_clean', 'row is not a clean remote-tracking candidate');
					}

					$handle           = (string) ( $row['handle'] ?? '' );
					$current_metadata = '' !== $handle ? WorktreeContextInjector::get_metadata($handle) : array();
					if ( WorktreeContextInjector::STATE_ACTIVE !== (string) ( $current_metadata['lifecycle_state'] ?? '' ) ) {
						return new \WP_Error('not_active_lifecycle_state', 'row is no longer active lifecycle metadata');
					}

					$row['metadata'] = $current_metadata;
					return $row;
				},
				'build_metadata'     => fn( array $row ): array|\WP_Error => $this->build_active_no_signal_remote_clean_metadata($row),
				'build_planned'      => static function ( array $row, array $metadata ): array {
					return array(
						'handle'   => (string) ( $row['handle'] ?? '' ),
						'repo'     => (string) ( $row['repo'] ?? '' ),
						'branch'   => (string) ( $row['branch'] ?? '' ),
						'path'     => (string) ( $row['path'] ?? '' ),
						'metadata' => $metadata,
					);
				},
				'evidence'           => array(
					'scope'  => 'promote clean remote-tracking active_no_signal rows into cleanup_eligible metadata',
					'safety' => 'Revalidates clean worktree, no unpushed commits, remote branch existence, primary protection, and branch identity before writing metadata. Does not delete worktrees or remote branches.',
				),
			)
		);
	}

	/**
	 * Apply active/no-signal report rows into cleanup metadata for one signal type.
	 *
	 * @param  array<string,mixed> $opts   Apply options.
	 * @param  array<string,mixed> $config Apply behavior callbacks and output labels.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function apply_active_no_signal_metadata( array $opts, array $config ): array|\WP_Error {
		$dry_run = ! empty($opts['dry_run']);
		$report  = $this->worktree_active_no_signal_report(array_merge($opts, array( 'next_command_operation' => (string) $config['operation'] )));
		if ( is_wp_error($report) ) {
			return $report;
		}

		$prepare_row    = $config['prepare_row'] ?? null;
		$build_metadata = $config['build_metadata'] ?? null;
		$build_planned  = $config['build_planned'] ?? null;
		if ( ! is_callable($prepare_row) || ! is_callable($build_metadata) || ! is_callable($build_planned) ) {
			return new \WP_Error('invalid_active_no_signal_apply_config', 'Active/no-signal apply config is missing required callbacks.');
		}

		$planned = array();
		$written = array();
		$skipped = array();
		foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}

			$prepared_row = $prepare_row($row);
			if ( is_wp_error($prepared_row) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip($row, (string) $prepared_row->get_error_code(), $prepared_row->get_error_message());
				continue;
			}
			if ( ! is_array($prepared_row) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip($row, 'invalid_prepared_row', 'active/no-signal apply prepared an invalid row');
				continue;
			}

			$metadata = $build_metadata($prepared_row);
			if ( is_wp_error($metadata) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip($prepared_row, (string) $metadata->get_error_code(), $metadata->get_error_message());
				continue;
			}
			if ( ! is_array($metadata) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip($prepared_row, 'invalid_metadata', 'active/no-signal apply built invalid metadata');
				continue;
			}

			$planned_row = $build_planned($prepared_row, $metadata);
			if ( ! is_array($planned_row) ) {
				$skipped[] = $this->build_active_no_signal_finalized_apply_skip($prepared_row, 'invalid_planned_row', 'active/no-signal apply built an invalid planned row');
				continue;
			}
			$planned[] = $planned_row;

			if ( $dry_run ) {
				continue;
			}

			$handle = (string) ( $prepared_row['handle'] ?? '' );
			WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);
			if ( ! empty($config['upsert_inventory']) ) {
				$this->worktree_inventory()->upsert($this->build_worktree_inventory_row_from_handle($handle));
			}

			$written[] = ! empty($config['written_as_planned'])
				? $planned_row
				: array(
					'handle'   => $handle,
					'repo'     => (string) ( $prepared_row['repo'] ?? '' ),
					'branch'   => (string) ( $prepared_row['branch'] ?? '' ),
					'path'     => (string) ( $prepared_row['path'] ?? '' ),
					'metadata' => WorktreeContextInjector::get_metadata($handle),
				);
		}

		$summary_inspected = (int) ( $report['summary']['inspected'] ?? 0 );
		if ( 'rows' === (string) ( $config['inspected_fallback'] ?? '' ) && ! isset($report['summary']['inspected']) ) {
			$summary_inspected = count( (array) ( $report['rows'] ?? array() ) );
		}

		$summary = array(
			'inspected'         => $summary_inspected,
			'planned'           => count($planned),
			'written'           => count($written),
			'skipped'           => count($skipped),
			'skipped_by_reason' => array(),
		);
		foreach ( $skipped as $skip ) {
			$reason                                  = (string) ( $skip['reason_code'] ?? 'unknown' );
			$summary['skipped_by_reason'][ $reason ] = (int) ( $summary['skipped_by_reason'][ $reason ] ?? 0 ) + 1;
		}
		$summary_extra = $config['summary_extra'] ?? null;
		if ( is_callable($summary_extra) ) {
			$extra = $summary_extra($report);
			if ( is_array($extra) ) {
				$summary = array_merge($summary, $extra);
			}
		}

		$result = array(
			'success'      => true,
			'mode'         => (string) $config['mode'],
			'dry_run'      => $dry_run,
			'applied'      => ! $dry_run,
			'generated_at' => gmdate('c'),
			'planned'      => $planned,
			'written'      => $written,
			'skipped'      => $skipped,
			'summary'      => $summary,
			'pagination'   => $this->build_active_no_signal_apply_pagination( (array) ( $report['pagination'] ?? array() ), (string) $config['operation'], $dry_run, $opts, count( $written ) ),
			'evidence'     => (array) $config['evidence'],
		);
		if ( array_key_exists('review_only', $config) ) {
			$result['review_only'] = (bool) $config['review_only'];
		}
		if ( array_key_exists('destructive', $config) ) {
			$result['destructive'] = (bool) $config['destructive'];
		}

		return $result;
	}

	/**
	 * Build apply-specific pagination from the underlying active/no-signal report.
	 *
	 * @param  array<string,mixed> $pagination Report pagination payload.
	 * @param  string              $operation  Active/no-signal apply operation.
	 * @param  bool                $dry_run    Whether the current apply is a dry-run.
	 * @param  array<string,mixed> $opts       Original operation options.
	 * @return array<string,mixed>
	 */
	private function build_active_no_signal_apply_pagination( array $pagination, string $operation, bool $dry_run, array $opts, int $written_count = 0 ): array {
		if ( null === ( $pagination['next_offset'] ?? null ) ) {
			$pagination['next_command'] = null;
			return $pagination;
		}

		$budget_label = isset($opts['internal_budget_label'])
			? trim( (string) $opts['internal_budget_label'] )
			: ( isset($opts['until_budget']) ? trim( (string) $opts['until_budget'] ) : '' );
		if ( '' === $budget_label && is_string($pagination['next_command'] ?? null) && preg_match('/ --until-budget=([^ ]+)/', (string) $pagination['next_command'], $matches) ) {
			$budget_label = $matches[1];
		}

		$budget_arg  = '' !== $budget_label ? ' --until-budget=' . $budget_label : '';
		$dry_run_arg = $dry_run ? ' --dry-run' : '';
		$limit       = (int) ( $pagination['limit'] ?? 25 );
		$next_offset = (int) $pagination['next_offset'];
		if ( ! $dry_run && $written_count > 0 ) {
			$next_offset               = 0;
			$pagination['next_offset'] = $next_offset;
		}

		$pagination['next_command'] = sprintf(
			'studio wp datamachine-code workspace worktree %s%s --limit=%d --offset=%d%s --format=json',
			$operation,
			$dry_run_arg,
			$limit,
			$next_offset,
			$budget_arg
		);

		return $pagination;
	}

	/**
	 * Build cleanup metadata from one finalized active/no-signal evidence row.
	 *
	 * @param  array<string,mixed> $row Evidence row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_active_no_signal_finalized_metadata( array $row ): array|\WP_Error {
		$handle = (string) ( $row['handle'] ?? '' );
		$repo   = (string) ( $row['repo'] ?? '' );
		$branch = (string) ( $row['branch'] ?? '' );
		$path   = (string) ( $row['path'] ?? '' );
		$pr     = is_array($row['pr'] ?? null) ? $row['pr'] : array();

		foreach (
		array(
			'handle' => $handle,
			'repo'   => $repo,
			'branch' => $branch,
			'path'   => $path,
		) as $field => $value
		) {
			if ( '' === $value ) {
				return new \WP_Error('missing_identity', 'missing required identity field: ' . $field);
			}
		}
		if ( ! is_dir($path) ) {
			return new \WP_Error('missing_worktree', 'worktree path no longer exists');
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! is_dir($primary_path . '/.git') ) {
			return new \WP_Error('missing_primary', 'primary checkout missing');
		}

		$dirty = $this->probe_worktree_dirty_count($path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($dirty) ) {
			return $dirty;
		}
		$unpushed = $this->count_unpushed_commits($path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($unpushed) ) {
			return $unpushed;
		}
		if ( (int) $dirty > 0 || (int) $unpushed > 0 ) {
			return new \WP_Error('unsafe_dirty_or_unpushed', 'refusing to mark dirty or unpushed worktree cleanup_eligible from active/no-signal evidence');
		}

		$slug = $this->resolve_github_slug($primary_path);
		if ( null === $slug ) {
			return new \WP_Error('missing_github_repo', 'primary checkout does not resolve to a GitHub repository');
		}

		$github_cache = array();
		$current_pr   = $this->find_pr_for_branch_direct($slug, $branch, $github_cache, true);
		if ( is_wp_error($current_pr) ) {
			return $current_pr;
		}
		if ( ! is_array($current_pr) || empty($current_pr['merged_at']) ) {
			return new \WP_Error('missing_finalized_pr', 'exact branch-head PR is not currently closed and merged');
		}

		$pr_url = (string) ( $current_pr['html_url'] ?? ( $pr['html_url'] ?? '' ) );
		if ( '' === $pr_url ) {
			return new \WP_Error('missing_pr_url', 'merged PR evidence is missing html_url');
		}

		$metadata                                 = $this->build_active_no_signal_cleanup_metadata(
			$row,
			$handle,
			$repo,
			$branch,
			$path,
			WorktreeContextInjector::STATE_MERGED,
			$pr_url
		);
		$metadata['auto_finalized_by']            = 'active_no_signal_finalized_apply';
		$metadata['auto_finalized_signal']        = 'pr-merged';
		$metadata['auto_finalized_reason']        = sprintf('active/no-signal report found merged PR #%d', (int) ( $current_pr['number'] ?? 0 ));
		$metadata['cleanup_eligibility_evidence'] = array_filter(
			array(
				'signal'          => 'pr-merged',
				'finalized_state' => WorktreeContextInjector::STATE_MERGED,
				'reason'          => 'exact branch-head PR is closed and merged',
				'detected_at'     => gmdate('c'),
				'dirty'           => (int) $dirty,
				'unpushed'        => (int) $unpushed,
				'pr_url'          => $pr_url,
				'pr_number'       => (int) ( $current_pr['number'] ?? 0 ),
			),
			fn( $value ) => '' !== $value
		);

		return $metadata;
	}

	/**
	 * Build cleanup metadata from one effectively clean upstream-equivalent row.
	 *
	 * @param  array<string,mixed> $row Evidence row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_active_no_signal_equivalent_clean_metadata( array $row ): array|\WP_Error {
		$handle = (string) ( $row['handle'] ?? '' );
		$repo   = (string) ( $row['repo'] ?? '' );
		$branch = (string) ( $row['branch'] ?? '' );
		$path   = (string) ( $row['path'] ?? '' );

		foreach (
		array(
			'handle' => $handle,
			'repo'   => $repo,
			'branch' => $branch,
			'path'   => $path,
		) as $field => $value
		) {
			if ( '' === $value ) {
				return new \WP_Error('missing_identity', 'missing required identity field: ' . $field);
			}
		}
		if ( ! is_dir($path) ) {
			return new \WP_Error('missing_worktree', 'worktree path no longer exists');
		}

		$equivalence = $this->build_current_effective_clean_cleanup_evidence($repo, $path);
		if ( is_wp_error($equivalence) ) {
			return $equivalence;
		}

		$metadata                                 = $this->build_active_no_signal_cleanup_metadata(
			$row,
			$handle,
			$repo,
			$branch,
			$path,
			WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE
		);
		$effective_status                         = (string) ( $equivalence['upstream_equivalence']['effective_status'] ?? '' );
		$signal                                   = 'contained_non_default_remote' === $effective_status ? 'contained-non-default-remote' : 'upstream-equivalent-clean';
		$reason                                   = 'contained_non_default_remote' === $effective_status
			? 'active/no-signal report found clean work contained in a non-default remote branch'
			: 'active/no-signal report found patch-equivalent upstream work with no source-like dirty paths';
		$metadata['auto_finalized_by']            = 'active_no_signal_equivalent_clean_apply';
		$metadata['auto_finalized_signal']        = $signal;
		$metadata['auto_finalized_reason']        = $reason;
		$metadata['cleanup_eligibility_evidence'] = array(
			'signal'               => $signal,
			'finalized_state'      => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
			'reason'               => $reason,
			'detected_at'          => gmdate('c'),
			'dirty'                => (int) ( $equivalence['dirty'] ?? 0 ),
			'unpushed'             => (int) ( $equivalence['unpushed'] ?? 0 ),
			'upstream_equivalence' => $equivalence['upstream_equivalence'] ?? array(),
		);

		return $metadata;
	}

	/**
	 * Build cleanup metadata from one clean merged-to-default evidence row.
	 *
	 * @param  array<string,mixed> $row Evidence row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_active_no_signal_merged_to_default_metadata( array $row ): array|\WP_Error {
		$handle = (string) ( $row['handle'] ?? '' );
		$repo   = (string) ( $row['repo'] ?? '' );
		$branch = (string) ( $row['branch'] ?? '' );
		$path   = (string) ( $row['path'] ?? '' );

		$evidence = $this->build_current_merged_to_default_cleanup_evidence($handle, $repo, $branch, $path);
		if ( is_wp_error($evidence) ) {
			return $evidence;
		}

		$metadata                                 = $this->build_active_no_signal_cleanup_metadata(
			$row,
			$handle,
			$repo,
			$branch,
			(string) ( $evidence['path'] ?? $path ),
			WorktreeContextInjector::STATE_MERGED
		);
		$metadata['auto_finalized_by']            = 'active_no_signal_merged_apply';
		$metadata['auto_finalized_signal']        = 'merged-to-default';
		$metadata['auto_finalized_reason']        = sprintf('active/no-signal report found branch contained in %s', (string) ( $evidence['default_ref'] ?? 'remote default' ));
		$metadata['cleanup_eligibility_evidence'] = $evidence;

		return $metadata;
	}

	/**
	 * Build cleanup metadata from one clean remote-tracking evidence row.
	 *
	 * @param  array<string,mixed> $row Evidence row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_active_no_signal_remote_clean_metadata( array $row ): array|\WP_Error {
		$handle = (string) ( $row['handle'] ?? '' );
		$repo   = (string) ( $row['repo'] ?? '' );
		$branch = (string) ( $row['branch'] ?? '' );
		$path   = (string) ( $row['path'] ?? '' );

		$evidence = $this->build_current_remote_tracking_clean_cleanup_evidence($handle, $repo, $branch, $path);
		if ( is_wp_error($evidence) ) {
			return $evidence;
		}

		$metadata                                 = $this->build_active_no_signal_cleanup_metadata(
			$row,
			$handle,
			$repo,
			$branch,
			(string) ( $evidence['path'] ?? $path ),
			WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE
		);
		$metadata['auto_finalized_by']            = 'active_no_signal_remote_clean_apply';
		$metadata['auto_finalized_signal']        = 'remote-tracking-clean';
		$metadata['auto_finalized_reason']        = 'active/no-signal report found a clean local worktree whose work is preserved by its remote branch';
		$metadata['cleanup_eligibility_evidence'] = $evidence;

		return $metadata;
	}

	/**
	 * Build the common cleanup metadata envelope for active/no-signal apply paths.
	 *
	 * @param  array<string,mixed> $row             Evidence row.
	 * @param  string              $handle          Worktree handle.
	 * @param  string              $repo            Repository name.
	 * @param  string              $branch          Branch name.
	 * @param  string              $path            Canonical worktree path to store.
	 * @param  string              $finalizer_state Lifecycle finalizer state.
	 * @param  string|null         $finalizer_url   Optional finalizer URL.
	 * @return array<string,mixed>
	 */
	private function build_active_no_signal_cleanup_metadata(
		array $row,
		string $handle,
		string $repo,
		string $branch,
		string $path,
		string $finalizer_state,
		?string $finalizer_url = null
	): array {
		$base_metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : array();

		return array_merge(
			$base_metadata,
			array(
				'handle'       => $handle,
				'repo'         => $repo,
				'branch'       => $branch,
				'path'         => $path,
				'observed_at'  => gmdate('c'),
				'last_seen_at' => gmdate('c'),
			),
			WorktreeContextInjector::build_finalizer_metadata($finalizer_state, $finalizer_url)
		);
	}

	/**
	 * Recompute clean remote-tracking evidence for the current worktree state.
	 *
	 * @param  string $handle Worktree handle.
	 * @param  string $repo   Repository name.
	 * @param  string $branch Branch name.
	 * @param  string $path   Worktree path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_current_remote_tracking_clean_cleanup_evidence( string $handle, string $repo, string $branch, string $path ): array|\WP_Error {
		foreach (
		array(
			'handle' => $handle,
			'repo'   => $repo,
			'branch' => $branch,
			'path'   => $path,
		) as $field => $value
		) {
			if ( '' === $value ) {
				return new \WP_Error('missing_identity', 'missing required identity field: ' . $field);
			}
		}

		$facts = $this->validate_current_cleanup_worktree(
			$repo,
			$path,
			$branch,
			array(
				'require_clean'          => true,
				'missing_primary_code'   => 'primary_missing',
				'dirty_error_message'    => 'worktree is dirty',
				'unpushed_error_message' => 'worktree has unpushed commits',
			)
		);
		if ( is_wp_error($facts) ) {
			return $facts;
		}

		$dirty        = (int) $facts['dirty'];
		$unpushed     = (int) $facts['unpushed'];
		$primary_path = (string) $facts['primary_path'];

		$remote_ref = 'refs/remotes/origin/' . $branch;
		$remote     = $this->run_git($primary_path, sprintf('rev-parse --verify --quiet %s', escapeshellarg($remote_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($remote) ) {
			return new \WP_Error('remote_tracking_missing', 'remote tracking branch no longer exists');
		}

		$evidence                    = array();
		$evidence['signal']          = 'remote-tracking-clean';
		$evidence['handle']          = $handle;
		$evidence['repo']            = $repo;
		$evidence['branch']          = $branch;
		$evidence['dirty']           = (int) $dirty;
		$evidence['unpushed']        = (int) $unpushed;
		$evidence['remote_ref']      = $remote_ref;
		$evidence['remote_tracking'] = true;
		$evidence['reason']          = 'clean local worktree has no unpushed commits and the branch exists on origin; removing the local checkout does not delete the remote branch';
		$evidence['detected_at']     = gmdate('c');

		return $evidence;
	}

	/**
	 * Recompute effective-clean evidence for the current worktree state.
	 *
	 * @param  string $repo    Repository name.
	 * @param  string $wt_path Worktree path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_current_effective_clean_cleanup_evidence( string $repo, string $wt_path ): array|\WP_Error {
		$facts = $this->validate_current_cleanup_worktree($repo, $wt_path);
		if ( is_wp_error($facts) ) {
			return $facts;
		}

		$real_path    = (string) $facts['real_path'];
		$primary_path = (string) $facts['primary_path'];
		$dirty        = (int) $facts['dirty'];
		$unpushed     = (int) $facts['unpushed'];
		$branch       = (string) $facts['branch'];

		$default_ref = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( ! is_string($default_ref) || '' === $default_ref ) {
			return new \WP_Error('missing_default_ref', 'primary checkout default ref could not be resolved');
		}

		$upstream_equivalence = ( 0 === (int) $dirty && 0 === (int) $unpushed )
			? $this->build_clean_upstream_equivalence_evidence($primary_path, $real_path, $default_ref, $branch)
			: $this->build_dirty_unpushed_upstream_equivalence_evidence($primary_path, $real_path, $default_ref);
		if ( ! in_array( (string) ( $upstream_equivalence['effective_status'] ?? '' ), array( 'equivalent_clean', 'contained_non_default_remote' ), true) ) {
			return new \WP_Error('not_equivalent_clean', 'current worktree evidence is not effectively clean upstream-equivalent');
		}

		return array(
			'dirty'                => (int) $dirty,
			'unpushed'             => (int) $unpushed,
			'upstream_equivalence' => $upstream_equivalence,
		);
	}

	/**
	 * Recompute merged-to-default evidence for the current worktree state.
	 *
	 * @param  string $handle Workspace handle.
	 * @param  string $repo   Repository name.
	 * @param  string $branch Branch name.
	 * @param  string $path   Worktree path.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_current_merged_to_default_cleanup_evidence( string $handle, string $repo, string $branch, string $path ): array|\WP_Error {
		foreach (
		array(
			'handle' => $handle,
			'repo'   => $repo,
			'branch' => $branch,
			'path'   => $path,
		) as $field => $value
		) {
			if ( '' === $value ) {
				return new \WP_Error('missing_identity', 'missing required identity field: ' . $field);
			}
		}

		$facts = $this->validate_current_cleanup_worktree(
			$repo,
			$path,
			$branch,
			array(
				'require_clean'          => true,
				'dirty_error_message'    => 'refusing to mark dirty worktree cleanup_eligible from merged-to-default evidence',
				'unpushed_error_message' => 'refusing to mark worktree with unpushed commits cleanup_eligible from merged-to-default evidence',
			)
		);
		if ( is_wp_error($facts) ) {
			return $facts;
		}

		$real_path    = (string) $facts['real_path'];
		$primary_path = (string) $facts['primary_path'];
		$dirty        = (int) $facts['dirty'];
		$unpushed     = (int) $facts['unpushed'];

		$default_ref = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( ! is_string($default_ref) || '' === $default_ref ) {
			return new \WP_Error('missing_default_ref', 'primary checkout default ref could not be resolved');
		}

		$branch_ref = 'refs/heads/' . $branch;
		$outside    = $this->run_git(
			$primary_path,
			sprintf('rev-list --count %s..%s', escapeshellarg($default_ref), escapeshellarg($branch_ref)),
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		if ( is_wp_error($outside) ) {
			return $outside;
		}

		$commits_outside_default = (int) trim( (string) ( $outside['output'] ?? '' ));
		if ( 0 !== $commits_outside_default ) {
			return new \WP_Error('not_merged_to_default', 'current branch still has commits outside remote default');
		}

		$branch_head = $this->run_git($primary_path, sprintf('rev-parse --verify %s', escapeshellarg($branch_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($branch_head) ) {
			return $branch_head;
		}
		$default_head = $this->run_git($primary_path, sprintf('rev-parse --verify %s', escapeshellarg($default_ref . '^{commit}')), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($default_head) ) {
			return $default_head;
		}

		return array(
			'signal'                  => 'merged-to-default',
			'finalized_state'         => WorktreeContextInjector::STATE_MERGED,
			'reason'                  => 'branch has no commits outside the remote default ref',
			'detected_at'             => gmdate('c'),
			'handle'                  => $handle,
			'repo'                    => $repo,
			'branch'                  => $branch,
			'path'                    => $real_path,
			'default_ref'             => $default_ref,
			'branch_ref'              => $branch_ref,
			'branch_head'             => trim( (string) ( $branch_head['output'] ?? '' )),
			'default_head'            => trim( (string) ( $default_head['output'] ?? '' )),
			'commits_outside_default' => $commits_outside_default,
			'dirty'                   => (int) $dirty,
			'unpushed'                => (int) $unpushed,
		);
	}

	/**
	 * Revalidate the current worktree state before writing cleanup metadata.
	 *
	 * @param  string              $repo            Repository name.
	 * @param  string              $path            Worktree path.
	 * @param  string|null         $expected_branch Expected branch, or null to resolve it from the worktree.
	 * @param  array<string,mixed> $opts            Validation options.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_current_cleanup_worktree( string $repo, string $path, ?string $expected_branch = null, array $opts = array() ): array|\WP_Error {
		if ( null !== $expected_branch && in_array($expected_branch, $this->protected_base_branch_names(), true) ) {
			return new \WP_Error('primary_protected_branch', 'refusing to auto-finalize a protected primary branch worktree');
		}

		$validation = $this->validate_containment($path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error('external_worktree', 'worktree path is outside the workspace root');
		}

		$real_path = (string) ( $validation['real_path'] ?? '' );
		if ( '' === $real_path || ! is_dir($real_path) ) {
			return new \WP_Error('missing_worktree', 'worktree path no longer exists');
		}

		$git_marker = rtrim($real_path, '/') . '/.git';
		if ( is_dir($git_marker) ) {
			return new \WP_Error('primary_checkout', 'refusing to mark a primary checkout cleanup_eligible');
		}
		if ( ! is_file($git_marker) ) {
			return new \WP_Error('not_a_worktree', 'worktree marker missing');
		}

		$current_branch = (string) $this->resolve_worktree_branch_from_head_file($real_path);
		if ( null !== $expected_branch && ! $this->cleanup_branch_identity_matches($expected_branch, $current_branch) ) {
			return new \WP_Error('branch_identity_mismatch', 'worktree branch identity changed before apply');
		}
		if ( null === $expected_branch && '' === $current_branch ) {
			return new \WP_Error('missing_branch_identity', 'worktree branch identity could not be resolved');
		}
		if ( null === $expected_branch && in_array($current_branch, $this->protected_base_branch_names(), true) ) {
			return new \WP_Error('primary_protected_branch', 'refusing to auto-finalize a protected primary branch worktree');
		}

		$primary_path         = $this->get_primary_path($repo);
		$missing_primary_code = (string) ( $opts['missing_primary_code'] ?? 'missing_primary' );
		if ( '' === $primary_path || ! is_dir($primary_path . '/.git') ) {
			return new \WP_Error($missing_primary_code, 'primary checkout missing');
		}

		$dirty = $this->probe_worktree_dirty_count($real_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($dirty) ) {
			return $dirty;
		}

		$unpushed = $this->count_unpushed_commits($real_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($unpushed) ) {
			return $unpushed;
		}

		if ( ! empty($opts['require_clean']) ) {
			if ( 0 !== (int) $dirty ) {
				return new \WP_Error('dirty_worktree', (string) ( $opts['dirty_error_message'] ?? 'worktree is dirty' ));
			}
			if ( 0 !== (int) $unpushed ) {
				return new \WP_Error('unpushed_commits', (string) ( $opts['unpushed_error_message'] ?? 'worktree has unpushed commits' ));
			}
		}

		return array(
			'real_path'    => $real_path,
			'primary_path' => $primary_path,
			'branch'       => '' !== $current_branch ? $current_branch : (string) $expected_branch,
			'dirty'        => (int) $dirty,
			'unpushed'     => (int) $unpushed,
		);
	}

	/**
	 * Compare a report branch identity against the live worktree branch.
	 *
	 * Active/no-signal rows may originate from stale metadata or handle slugs where
	 * `fix/foo` is represented as `fix-foo`. Treat that as the same branch while
	 * still rejecting detached/missing heads and unrelated branch changes.
	 *
	 * @param  string $expected_branch Branch carried by the report row.
	 * @param  string $current_branch  Branch resolved from the live worktree HEAD.
	 * @return bool Whether the identities are compatible.
	 */
	private function cleanup_branch_identity_matches( string $expected_branch, string $current_branch ): bool {
		if ( '' === $expected_branch || '' === $current_branch ) {
			return false;
		}

		return $expected_branch === $current_branch || $expected_branch === $this->slugify_branch($current_branch);
	}

	/**
	 * Build a skip row for finalized active/no-signal apply.
	 *
	 * @param  array<string,mixed> $row         Evidence row.
	 * @param  string              $reason_code Stable reason code.
	 * @param  string              $reason      Human-readable reason.
	 * @return array<string,mixed>
	 */
	private function build_active_no_signal_finalized_apply_skip( array $row, string $reason_code, string $reason ): array {
		return array(
			'handle'      => (string) ( $row['handle'] ?? '' ),
			'repo'        => (string) ( $row['repo'] ?? '' ),
			'branch'      => (string) ( $row['branch'] ?? '' ),
			'path'        => (string) ( $row['path'] ?? '' ),
			'reason_code' => $reason_code,
			'reason'      => $reason,
			'action'      => (string) ( $row['suggested_action'] ?? '' ),
		);
	}

	/**
	 * Build one active/no-signal evidence row.
	 *
	 * @param  array<string,mixed> $row          Inventory skip row.
	 * @param  array<string,mixed> $github_cache Run-local GitHub cache.
	 * @param  array<string,mixed> $probe_cache  Run-local git probe cache.
	 * @return array<string,mixed>
	 */
	private function build_active_no_signal_evidence_row( array $row, array &$github_cache, array &$probe_cache ): array {
		$handle       = (string) ( $row['handle'] ?? '' );
		$repo         = (string) ( $row['repo'] ?? '' );
		$branch       = (string) ( $row['branch'] ?? '' );
		$branch_slug  = (string) ( $row['branch_slug'] ?? '' );
		$path         = (string) ( $row['path'] ?? '' );
		$primary_path = '' !== $repo ? $this->get_primary_path($repo) : '';
		$metadata     = is_array($row['metadata'] ?? null) ? $row['metadata'] : array();
		$branch_probe = null;
		if ( '' !== $path && is_dir($path) ) {
			$head_branch = $this->resolve_worktree_branch_from_head_file($path);
			if ( null !== $head_branch && '' !== $head_branch ) {
				$branch = $head_branch;
			} else {
				$branch_probe = $this->run_git($path, 'branch --show-current', self::CLEANUP_GIT_PROBE_TIMEOUT);
				if ( ! is_wp_error($branch_probe) ) {
					$actual_branch = trim( (string) ( $branch_probe['output'] ?? '' ) );
					if ( '' !== $actual_branch ) {
						$branch = $actual_branch;
					}
				}
			}
		}
		$metadata_branch             = is_string($metadata['branch'] ?? null) ? (string) $metadata['branch'] : '';
		$branch_identity             = array(
			'actual_branch'                  => $branch,
			'branch_slug'                    => $branch_slug,
			'metadata_branch'                => '' !== $metadata_branch ? $metadata_branch : null,
			'branch_slug_matches_actual'     => '' === $branch_slug || $this->slugify_branch($branch) === $branch_slug,
			'metadata_branch_matches_actual' => '' === $metadata_branch || $metadata_branch === $branch,
		);
		$branch_identity['mismatch'] = ! $branch_identity['branch_slug_matches_actual'] || ! $branch_identity['metadata_branch_matches_actual'];

		$out = array(
			'handle'                  => $handle,
			'repo'                    => $repo,
			'branch'                  => $branch,
			'branch_slug'             => $branch_slug,
			'branch_identity'         => $branch_identity,
			'path'                    => $path,
			'created_at'              => $row['created_at'] ?? null,
			'inventory_reason_code'   => $row['reason_code'] ?? null,
			'lifecycle_state'         => $metadata['lifecycle_state'] ?? null,
			'metadata'                => $metadata,
			'last_seen_at'            => $metadata['last_seen_at'] ?? ( $metadata['observed_at'] ?? null ),
			'dirty'                   => null,
			'unpushed'                => null,
			'pr'                      => null,
			'remote_tracking'         => null,
			'default_ref'             => null,
			'commits_outside_default' => null,
			'upstream_equivalence'    => null,
			'probe_timings_ms'        => array(),
			'suggested_action'        => 'insufficient_signal',
			'reason'                  => 'not enough evidence gathered',
		);
		if ( is_wp_error($branch_probe) ) {
			$out['branch_probe_error'] = $branch_probe->get_error_message();
		}

		if ( '' === $repo || '' === $branch || '' === $path || ! is_dir($path) || ! is_dir($primary_path . '/.git') ) {
			$out['suggested_action'] = 'insufficient_signal';
			$out['reason']           = 'missing repo, branch, path, worktree, or primary checkout';
			return $out;
		}

		$dirty = $this->time_worktree_probe($out['probe_timings_ms'], 'dirty_count', fn() => $this->probe_worktree_dirty_count($path, self::CLEANUP_GIT_PROBE_TIMEOUT));
		if ( is_wp_error($dirty) ) {
			$out['dirty_error'] = $dirty->get_error_message();
		} else {
			$out['dirty'] = (int) $dirty;
		}

		$unpushed = $this->time_worktree_probe($out['probe_timings_ms'], 'unpushed_count', fn() => $this->count_unpushed_commits($path, self::CLEANUP_GIT_PROBE_TIMEOUT));
		if ( is_wp_error($unpushed) ) {
			$out['unpushed_error'] = $unpushed->get_error_message();
		} else {
			$out['unpushed'] = (int) $unpushed;
		}

		$remote_ref             = 'refs/remotes/origin/' . $branch;
		$remote                 = $this->time_worktree_probe(
			$out['probe_timings_ms'],
			'remote_tracking',
			function () use ( $primary_path, $remote_ref, &$probe_cache ) {
				return $this->cached_active_no_signal_remote_tracking_probe($primary_path, $remote_ref, $probe_cache);
			}
		);
		$out['remote_tracking'] = ! is_wp_error($remote);

		$default_ref = $this->time_worktree_probe(
			$out['probe_timings_ms'],
			'default_ref',
			function () use ( $primary_path, &$probe_cache ) {
				return $this->cached_active_no_signal_default_ref_probe($primary_path, $probe_cache);
			}
		);
		if ( is_string($default_ref) && '' !== $default_ref ) {
			$out['default_ref'] = $default_ref;
			$outside            = $this->time_worktree_probe(
				$out['probe_timings_ms'],
				'commits_outside_default',
				function () use ( $primary_path, $default_ref, $branch, &$probe_cache ) {
					return $this->cached_active_no_signal_commits_outside_default_probe($primary_path, $default_ref, $branch, $probe_cache);
				}
			);
			if ( ! is_wp_error($outside) ) {
				$out['commits_outside_default'] = (int) trim( (string) ( $outside['output'] ?? '' ));
			}

			if ( (int) ( $out['dirty'] ?? 0 ) > 0 || (int) ( $out['unpushed'] ?? 0 ) > 0 ) {
				$out['upstream_equivalence'] = $this->time_worktree_probe($out['probe_timings_ms'], 'upstream_equivalence', fn() => $this->build_dirty_unpushed_upstream_equivalence_evidence($primary_path, $path, $default_ref));
			} else {
				$out['upstream_equivalence'] = $this->time_worktree_probe($out['probe_timings_ms'], 'clean_upstream_equivalence', fn() => $this->build_clean_upstream_equivalence_evidence($primary_path, $path, $default_ref, $branch));
			}
		}

		if ( (int) ( $out['dirty'] ?? 0 ) > 0 || (int) ( $out['unpushed'] ?? 0 ) > 0 ) {
			$out['pr_lookup_skipped'] = 'dirty_or_unpushed_rows_are_always_manual_review';
		} else {
			$slug = $this->time_worktree_probe(
				$out['probe_timings_ms'],
				'github_slug',
				function () use ( $primary_path, &$probe_cache ) {
					return $this->cached_active_no_signal_github_slug_probe($primary_path, $probe_cache);
				}
			);
			if ( null !== $slug ) {
				$pr = $this->time_worktree_probe($out['probe_timings_ms'], 'github_pr_lookup', fn() => $this->find_pr_for_branch_direct($slug, $branch, $github_cache, false));
				if ( is_wp_error($pr) ) {
					$out['pr_error'] = $pr->get_error_message();
				} elseif ( is_array($pr) ) {
					$out['pr'] = $pr;
				}
			}
		}

		$out['suggested_action'] = $this->suggest_active_no_signal_action($out);
		$out['reason']           = $this->describe_active_no_signal_action($out);

		return $out;
	}

	/**
	 * Cache a remote-tracking ref existence probe for an active/no-signal report run.
	 *
	 * @param  string              $primary_path Primary checkout path.
	 * @param  string              $remote_ref   Fully-qualified remote-tracking ref.
	 * @param  array<string,mixed> $probe_cache  Run-local git probe cache.
	 * @return array<string,mixed>|\WP_Error Git result or timeout/error.
	 */
	private function cached_active_no_signal_remote_tracking_probe( string $primary_path, string $remote_ref, array &$probe_cache ): array|\WP_Error {
		$key = $primary_path . '#' . $remote_ref;
		if ( array_key_exists($key, $probe_cache['remote_tracking'] ?? array()) ) {
			$this->record_active_no_signal_probe_cache_stat($probe_cache, 'remote_tracking', true);
			return $probe_cache['remote_tracking'][ $key ];
		}

		$this->record_active_no_signal_probe_cache_stat($probe_cache, 'remote_tracking', false);
		$result                                 = $this->run_git($primary_path, sprintf('rev-parse --verify --quiet %s', escapeshellarg($remote_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		$probe_cache['remote_tracking'][ $key ] = $result;
		return $result;
	}

	/**
	 * Cache the remote default ref for an active/no-signal report run.
	 *
	 * @param  string              $primary_path Primary checkout path.
	 * @param  array<string,mixed> $probe_cache  Run-local git probe cache.
	 * @return string|\WP_Error|null Fully-qualified remote default ref, timeout/error, or null.
	 */
	private function cached_active_no_signal_default_ref_probe( string $primary_path, array &$probe_cache ): string|\WP_Error|null {
		if ( array_key_exists($primary_path, $probe_cache['default_ref'] ?? array()) ) {
			$this->record_active_no_signal_probe_cache_stat($probe_cache, 'default_ref', true);
			return $probe_cache['default_ref'][ $primary_path ];
		}

		$this->record_active_no_signal_probe_cache_stat($probe_cache, 'default_ref', false);
		$result                                      = $this->resolve_remote_default_ref($primary_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		$probe_cache['default_ref'][ $primary_path ] = $result;
		return $result;
	}

	/**
	 * Cache commits-outside-default probes for an active/no-signal report run.
	 *
	 * @param  string              $primary_path Primary checkout path.
	 * @param  string              $default_ref  Fully-qualified remote default ref.
	 * @param  string              $branch       Local branch name.
	 * @param  array<string,mixed> $probe_cache  Run-local git probe cache.
	 * @return array<string,mixed>|\WP_Error Git result or timeout/error.
	 */
	private function cached_active_no_signal_commits_outside_default_probe( string $primary_path, string $default_ref, string $branch, array &$probe_cache ): array|\WP_Error {
		$key = $primary_path . '#' . $default_ref . '#' . $branch;
		if ( array_key_exists($key, $probe_cache['commits_outside_default'] ?? array()) ) {
			$this->record_active_no_signal_probe_cache_stat($probe_cache, 'commits_outside_default', true);
			return $probe_cache['commits_outside_default'][ $key ];
		}

		$this->record_active_no_signal_probe_cache_stat($probe_cache, 'commits_outside_default', false);
		$result = $this->run_git(
			$primary_path,
			sprintf('rev-list --count %s..%s', escapeshellarg($default_ref), escapeshellarg('refs/heads/' . $branch)),
			self::CLEANUP_GIT_PROBE_TIMEOUT
		);
		$probe_cache['commits_outside_default'][ $key ] = $result;
		return $result;
	}

	/**
	 * Cache the GitHub slug for an active/no-signal report run.
	 *
	 * @param  string              $primary_path Primary checkout path.
	 * @param  array<string,mixed> $probe_cache  Run-local git probe cache.
	 * @return string|null owner/repo or null when origin is not GitHub.
	 */
	private function cached_active_no_signal_github_slug_probe( string $primary_path, array &$probe_cache ): ?string {
		if ( array_key_exists($primary_path, $probe_cache['github_slug'] ?? array()) ) {
			$this->record_active_no_signal_probe_cache_stat($probe_cache, 'github_slug', true);
			return $probe_cache['github_slug'][ $primary_path ];
		}

		$this->record_active_no_signal_probe_cache_stat($probe_cache, 'github_slug', false);
		$result                                      = $this->resolve_github_slug($primary_path);
		$probe_cache['github_slug'][ $primary_path ] = $result;
		return $result;
	}

	/**
	 * Record active/no-signal probe cache hit/miss counts.
	 *
	 * @param array<string,mixed> $probe_cache Run-local git probe cache.
	 * @param string              $bucket      Probe cache bucket.
	 * @param bool                $hit         Whether the lookup was a cache hit.
	 */
	private function record_active_no_signal_probe_cache_stat( array &$probe_cache, string $bucket, bool $hit ): void {
		$field = $hit ? 'hits' : 'misses';
		if ( ! isset($probe_cache['stats'][ $bucket ][ $field ]) ) {
			$probe_cache['stats'][ $bucket ][ $field ] = 0;
		}
		++$probe_cache['stats'][ $bucket ][ $field ];
	}

	/**
	 * Build patch-equivalence evidence for clean active/no-signal worktrees.
	 *
	 * @param  string $primary_path Primary checkout path.
	 * @param  string $wt_path      Worktree path.
	 * @param  string $default_ref  Remote default ref.
	 * @param  string $branch       Current worktree branch.
	 * @return array<string,mixed>
	 */
	private function build_clean_upstream_equivalence_evidence( string $primary_path, string $wt_path, string $default_ref, string $branch ): array {
		$evidence = array(
			'default_ref'                     => $default_ref,
			'effective_status'                => 'unknown',
			'git_cherry'                      => array(
				'equivalent' => 0,
				'unmatched'  => 0,
				'unknown'    => 0,
			),
			'contained_by_non_default_remote' => array(),
		);

		$cherry = $this->run_git($wt_path, sprintf('cherry %s HEAD', escapeshellarg($default_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( ! is_wp_error($cherry) ) {
			$evidence['git_cherry'] = $this->parse_git_cherry_counts( (string) ( $cherry['output'] ?? '' ) );
			if ( 0 < (int) $evidence['git_cherry']['total'] && 0 === (int) $evidence['git_cherry']['unmatched'] && 0 === (int) $evidence['git_cherry']['unknown'] ) {
				$evidence['effective_status'] = 'equivalent_clean';
				return $evidence;
			}
		}

		$head = $this->run_git($wt_path, 'rev-parse --verify HEAD', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($head) ) {
			return $evidence;
		}

		$default_short = '';
		if ( str_starts_with($default_ref, 'refs/remotes/origin/') ) {
			$default_short = substr($default_ref, strlen('refs/remotes/origin/'));
		}

		$contains = $this->run_git($primary_path, sprintf('branch -r --contains %s', escapeshellarg(trim( (string) ( $head['output'] ?? '' )))), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($contains) ) {
			return $evidence;
		}

		$remote_branches = array_values(array_filter(array_map('trim', explode("\n", (string) ( $contains['output'] ?? '' )))));
		foreach ( $remote_branches as $remote_branch ) {
			$normalized_remote_branch = preg_replace('/^origin\//', '', $remote_branch);
			$remote_branch            = null === $normalized_remote_branch ? $remote_branch : $normalized_remote_branch;
			if ( '' === $remote_branch || str_starts_with($remote_branch, 'HEAD -> ') || $remote_branch === $default_short || $remote_branch === $branch ) {
				continue;
			}
			$evidence['contained_by_non_default_remote'][] = $remote_branch;
		}

		$evidence['contained_by_non_default_remote'] = array_values(array_unique($evidence['contained_by_non_default_remote']));
		if ( array() !== $evidence['contained_by_non_default_remote'] ) {
			$evidence['effective_status'] = 'contained_non_default_remote';
		}

		return $evidence;
	}

	/**
	 * Time one worktree probe and record elapsed milliseconds by label.
	 *
	 * @param  array<string,int> $timings  Timing accumulator.
	 * @param  string            $label    Probe label.
	 * @param  callable          $callback Probe callback.
	 * @return mixed
	 */
	private function time_worktree_probe( array &$timings, string $label, callable $callback ): mixed {
		$started           = microtime(true);
		$result            = $callback();
		$timings[ $label ] = (int) round(( microtime(true) - $started ) * 1000);
		return $result;
	}

	/**
	 * Build diagnostic evidence for dirty/unpushed worktrees against remote default.
	 *
	 * This is intentionally evidence-only. Cleanup still treats dirty files and
	 * unpushed commits as hard blockers until a separate reviewed apply path proves
	 * a safe subset.
	 *
	 * @param  string $primary_path Primary checkout path.
	 * @param  string $wt_path      Worktree path.
	 * @param  string $default_ref  Remote default ref.
	 * @return array<string,mixed>
	 */
	private function build_dirty_unpushed_upstream_equivalence_evidence( string $primary_path, string $wt_path, string $default_ref ): array {
		$path_inspection_limit = 250;
		$evidence              = array(
			'default_ref'               => $default_ref,
			'effective_status'          => 'unknown',
			'path_inspection_limit'     => $path_inspection_limit,
			'path_inspection_truncated' => false,
			'unpushed_patch_equivalent' => null,
			'unpushed_cherry'           => array(
				'equivalent' => 0,
				'unmatched'  => 0,
				'unknown'    => 0,
			),
			'dirty_paths'               => array(
				'total'                  => 0,
				'inspected'              => 0,
				'identical_to_default'   => 0,
				'different_from_default' => 0,
				'absent_on_default'      => 0,
				'generated_or_artifact'  => 0,
				'source_like'            => 0,
				'untracked'              => 0,
				'unknown'                => 0,
				'samples'                => array(),
			),
			'probe_timings_ms'          => array(),
		);

		$cherry = $this->time_worktree_probe($evidence['probe_timings_ms'], 'git_cherry', fn() => $this->run_git($wt_path, sprintf('cherry %s HEAD', escapeshellarg($default_ref)), self::CLEANUP_GIT_PROBE_TIMEOUT));
		if ( ! is_wp_error($cherry) ) {
			$evidence['unpushed_cherry']           = $this->parse_git_cherry_counts( (string) ( $cherry['output'] ?? '' ) );
			$evidence['unpushed_patch_equivalent'] = 0 === (int) $evidence['unpushed_cherry']['unmatched'] && 0 === (int) $evidence['unpushed_cherry']['unknown'];
		}

		$tracked = $this->time_worktree_probe($evidence['probe_timings_ms'], 'tracked_dirty_paths', fn() => $this->run_git($wt_path, 'diff --name-only HEAD', self::CLEANUP_GIT_PROBE_TIMEOUT));
		$paths   = array();
		if ( ! is_wp_error($tracked) ) {
			$paths = array_merge($paths, array_values(array_filter(array_map('trim', explode("\n", (string) ( $tracked['output'] ?? '' ))))));
		}

		$untracked = $this->time_worktree_probe($evidence['probe_timings_ms'], 'untracked_paths', fn() => $this->run_git($wt_path, 'ls-files --others --exclude-standard', self::CLEANUP_GIT_PROBE_TIMEOUT));
		if ( ! is_wp_error($untracked) ) {
			foreach ( array_values(array_filter(array_map('trim', explode("\n", (string) ( $untracked['output'] ?? '' ))))) as $path ) {
				$paths[] = $path;
				++$evidence['dirty_paths']['untracked'];
			}
		}

		$paths                                 = array_values(array_unique($paths));
		$evidence['dirty_paths']['total']      = count($paths);
		$inspect_paths                         = array_slice($paths, 0, $path_inspection_limit);
		$evidence['dirty_paths']['inspected']  = count($inspect_paths);
		$evidence['path_inspection_truncated'] = count($paths) > count($inspect_paths);

		$classification_started = microtime(true);
		$classifications        = $this->classify_dirty_paths_against_default($primary_path, $wt_path, $default_ref, $inspect_paths);
		foreach ( $inspect_paths as $path ) {
			$classification = $classifications[ $path ] ?? $this->classify_dirty_path_against_default($primary_path, $wt_path, $default_ref, $path);
			$bucket         = $classification['bucket'];
			if ( isset($evidence['dirty_paths'][ $bucket ]) ) {
				++$evidence['dirty_paths'][ $bucket ];
			} else {
				++$evidence['dirty_paths']['unknown'];
			}
			$kind = (string) ( $classification['kind'] ?? 'source_like' );
			if ( 'generated_or_artifact' === $kind ) {
				++$evidence['dirty_paths']['generated_or_artifact'];
			} else {
				++$evidence['dirty_paths']['source_like'];
			}
			if ( count($evidence['dirty_paths']['samples']) < 10 ) {
				$evidence['dirty_paths']['samples'][] = $classification;
			}
		}
		$evidence['probe_timings_ms']['dirty_path_classification'] = (int) round(( microtime(true) - $classification_started ) * 1000);

		$evidence['effective_status'] = $this->classify_dirty_unpushed_effective_status($evidence);

		return $evidence;
	}

	/**
	 * Parse `git cherry` output into equivalent/unmatched/unknown counters.
	 *
	 * @param  string $output Raw `git cherry` output.
	 * @return array<string,int>
	 */
	private function parse_git_cherry_counts( string $output ): array {
		$counts = array(
			'equivalent' => 0,
			'unmatched'  => 0,
			'unknown'    => 0,
			'total'      => 0,
		);

		$lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
		foreach ( $lines as $line ) {
			if ( str_starts_with($line, '-') ) {
				++$counts['equivalent'];
			} elseif ( str_starts_with($line, '+') ) {
				++$counts['unmatched'];
			} else {
				++$counts['unknown'];
			}
		}
		$counts['total'] = count($lines);

		return $counts;
	}

	/**
	 * Classify dirty paths against the remote default branch with batched git probes.
	 *
	 * @param  string            $primary_path Primary checkout path.
	 * @param  string            $wt_path      Worktree path.
	 * @param  string            $default_ref  Remote default ref.
	 * @param  array<int,string> $paths        Repository-relative paths.
	 * @return array<string,array<string,string>> Classifications keyed by path.
	 */
	private function classify_dirty_paths_against_default( string $primary_path, string $wt_path, string $default_ref, array $paths ): array {
		$paths = array_values(array_unique(array_filter(array_map('strval', $paths), fn( $path ) => '' !== $path)));
		if ( array() === $paths ) {
			return array();
		}

		$path_args = implode(' ', array_map('escapeshellarg', $paths));
		$existing  = $this->run_git($primary_path, sprintf('ls-tree -r --name-only %s -- %s', escapeshellarg($default_ref), $path_args), self::CLEANUP_GIT_PROBE_TIMEOUT);
		$changed   = $this->run_git($wt_path, sprintf('diff --name-only %s -- %s', escapeshellarg($default_ref), $path_args), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($existing) || is_wp_error($changed) ) {
			return array();
		}

		$existing_set = array_fill_keys(array_values(array_filter(array_map('trim', explode("\n", (string) ( $existing['output'] ?? '' ))))), true);
		$changed_set  = array_fill_keys(array_values(array_filter(array_map('trim', explode("\n", (string) ( $changed['output'] ?? '' ))))), true);
		$out          = array();
		foreach ( $paths as $path ) {
			$kind = $this->is_generated_or_artifact_path($path) ? 'generated_or_artifact' : 'source_like';
			if ( ! isset($existing_set[ $path ]) ) {
				$out[ $path ] = array(
					'path'   => $path,
					'bucket' => 'absent_on_default',
					'kind'   => $kind,
				);
				continue;
			}

			$out[ $path ] = array(
				'path'   => $path,
				'bucket' => isset($changed_set[ $path ]) ? 'different_from_default' : 'identical_to_default',
				'kind'   => $kind,
			);
		}

		return $out;
	}

	/**
	 * Derive an operator-facing effective status for dirty/unpushed evidence.
	 *
	 * @param  array<string,mixed> $evidence Upstream equivalence evidence.
	 * @return string
	 */
	private function classify_dirty_unpushed_effective_status( array $evidence ): string {
		if ( true !== ( $evidence['unpushed_patch_equivalent'] ?? null ) ) {
			return false === ( $evidence['unpushed_patch_equivalent'] ?? null ) ? 'not_equivalent' : 'unknown';
		}

		$dirty = (array) ( $evidence['dirty_paths'] ?? array() );
		if ( ! empty($evidence['path_inspection_truncated']) || (int) ( $dirty['unknown'] ?? 0 ) > 0 ) {
			return 'unknown';
		}
		if ( 0 === (int) ( $dirty['total'] ?? 0 ) || (int) ( $dirty['total'] ?? 0 ) === (int) ( $dirty['identical_to_default'] ?? 0 ) ) {
			return 'equivalent_clean';
		}
		$meaningful = (int) ( $dirty['different_from_default'] ?? 0 ) + (int) ( $dirty['absent_on_default'] ?? 0 ) + (int) ( $dirty['untracked'] ?? 0 );
		if ( 0 < $meaningful && (int) ( $dirty['generated_or_artifact'] ?? 0 ) === $meaningful ) {
			return 'equivalent_generated_dirty';
		}
		if ( 0 < $meaningful && (int) ( $dirty['absent_on_default'] ?? 0 ) === $meaningful ) {
			return 'equivalent_obsolete_dirty';
		}

		return 'equivalent_but_dirty';
	}

	/**
	 * Classify one dirty path against the remote default branch.
	 *
	 * @param  string $primary_path Primary checkout path.
	 * @param  string $wt_path      Worktree path.
	 * @param  string $default_ref  Remote default ref.
	 * @param  string $path         Repository-relative path.
	 * @return array<string,string>
	 */
	private function classify_dirty_path_against_default( string $primary_path, string $wt_path, string $default_ref, string $path ): array {
		$kind   = $this->is_generated_or_artifact_path($path) ? 'generated_or_artifact' : 'source_like';
		$exists = $this->run_git($primary_path, sprintf('cat-file -e %s', escapeshellarg($default_ref . ':' . $path)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($exists) && $this->is_git_timeout_error($exists) ) {
			return array(
				'path'   => $path,
				'bucket' => 'unknown',
				'kind'   => $kind,
				'reason' => $exists->get_error_message(),
			);
		}
		if ( is_wp_error($exists) ) {
			return array(
				'path'   => $path,
				'bucket' => 'absent_on_default',
				'kind'   => $kind,
			);
		}

		$diff = $this->run_git($wt_path, sprintf('diff --name-only %s -- %s', escapeshellarg($default_ref), escapeshellarg($path)), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($diff) && $this->is_git_timeout_error($diff) ) {
			return array(
				'path'   => $path,
				'bucket' => 'unknown',
				'kind'   => $kind,
				'reason' => $diff->get_error_message(),
			);
		}
		if ( is_wp_error($diff) ) {
			return array(
				'path'   => $path,
				'bucket' => 'unknown',
				'kind'   => $kind,
				'reason' => $diff->get_error_message(),
			);
		}

		return array(
			'path'   => $path,
			'bucket' => '' === trim( (string) ( $diff['output'] ?? '' )) ? 'identical_to_default' : 'different_from_default',
			'kind'   => $kind,
		);
	}

	/**
	 * Check whether a path is a known generated, cache, dependency, or build artifact.
	 *
	 * @param  string $path Repository-relative path.
	 * @return bool
	 */
	private function is_generated_or_artifact_path( string $path ): bool {
		$normalized = ltrim(str_replace('\\', '/', $path), '/');
		$patterns   = array(
			'#(^|/)node_modules/#',
			'#(^|/)vendor/#',
			'#(^|/)\.cache/#',
			'#(^|/)\.turbo/#',
			'#(^|/)\.next/#',
			'#(^|/)dist/#',
			'#(^|/)build/#',
			'#(^|/)coverage/#',
			'#(^|/)tmp/#',
			'#(^|/)temp/#',
			'#(^|/)logs?/#',
			'#(^|/)\.pytest_cache/#',
			'#(^|/)__pycache__/#',
			'#(^|/)\.DS_Store$#',
			'#\.(log|cache|tmp|map|min\.js|min\.css)$#',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match($pattern, $normalized) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Suggest a review action from active/no-signal evidence.
	 *
	 * @param  array<string,mixed> $row Evidence row.
	 * @return string
	 */
	private function suggest_active_no_signal_action( array $row ): string {
		if ( (int) ( $row['dirty'] ?? 0 ) > 0 || (int) ( $row['unpushed'] ?? 0 ) > 0 ) {
			return 'unsafe_dirty_or_unpushed';
		}

		$pr = is_array($row['pr'] ?? null) ? $row['pr'] : array();
		if ( ! empty($pr) ) {
			if ( 'closed' === (string) ( $pr['state'] ?? '' ) ) {
				return ! empty($pr['merged_at']) ? 'finalized_pr_reconcile' : 'closed_pr_reconcile';
			}
			return 'active_open_pr';
		}

		if ( 0 === (int) ( $row['commits_outside_default'] ?? -1 ) ) {
			return 'merged_to_default';
		}

		$effective_status = (string) ( $row['upstream_equivalence']['effective_status'] ?? '' );
		if ( 'equivalent_clean' === $effective_status ) {
			return 'patch_equivalent_default';
		}
		if ( 'contained_non_default_remote' === $effective_status ) {
			return 'contained_non_default_remote';
		}

		if ( true === ( $row['remote_tracking'] ?? null ) ) {
			return 'remote_tracking_clean';
		}

		if ( null === ( $row['pr'] ?? null ) && empty($row['pr_error']) ) {
			return 'no_pr_branch_review';
		}

		return 'insufficient_signal';
	}

	/**
	 * Human-readable explanation for active/no-signal action suggestions.
	 *
	 * @param  array<string,mixed> $row Evidence row.
	 * @return string
	 */
	private function describe_active_no_signal_action( array $row ): string {
		return match ( (string) ( $row['suggested_action'] ?? '' ) ) {
			'unsafe_dirty_or_unpushed' => 'dirty files or unpushed commits require manual handling before cleanup',
			'finalized_pr_reconcile'   => 'exact branch-head PR lookup found a merged PR; metadata reconciliation should be able to mark cleanup_eligible',
			'closed_pr_reconcile'      => 'exact branch-head PR lookup found a closed PR; review before marking cleanup_eligible',
			'active_open_pr'           => 'exact branch-head PR lookup found an open PR',
			'merged_to_default'        => 'local branch has no commits outside the remote default ref',
			'patch_equivalent_default' => 'local commits are patch-equivalent to the remote default ref',
			'contained_non_default_remote' => 'worktree HEAD is contained in a non-default remote branch',
			'remote_tracking_clean'    => 'clean local worktree has no unpushed commits and the branch exists on origin',
			'no_pr_branch_review'      => 'no exact branch-head PR was found; review age/task context before cleanup',
			default                    => 'not enough evidence gathered',
		};
	}
}
