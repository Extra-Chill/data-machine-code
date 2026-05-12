<?php
/**
 * Workspace hygiene, retention, and disk report operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

trait WorkspaceHygieneReport {

	/**
	 * Build a non-destructive workspace hygiene report.
	 *
	 * The report intentionally defaults to local-only cleanup detection so an
	 * on-demand or scheduled run never depends on GitHub API availability. Size
	 * collection is best-effort and bounded by a top-level entry limit.
	 *
	 * @param array $opts {
	 *     @type bool $include_cleanup         Whether to include a cleanup dry-run. Default true.
	 *     @type bool $include_sizes           Whether to include best-effort `du` sizes. Default true.
	 *     @type bool $include_worktree_status Whether to include full git worktree status. Default false.
	 *     @type int  $size_limit              Maximum top-level workspace entries to size. Default 1000.
	 * }
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_hygiene_report( array $opts = array() ): array|\WP_Error {
		$include_cleanup         = array_key_exists( 'include_cleanup', $opts ) ? (bool) $opts['include_cleanup'] : true;
		$include_sizes           = array_key_exists( 'include_sizes', $opts ) ? (bool) $opts['include_sizes'] : true;
		$include_worktree_status = array_key_exists( 'include_worktree_status', $opts ) ? (bool) $opts['include_worktree_status'] : false;
		$refresh_inventory       = ! empty( $opts['refresh_inventory'] );
		$size_limit              = isset( $opts['size_limit'] ) ? max( 0, (int) $opts['size_limit'] ) : self::HYGIENE_DEFAULT_SIZE_LIMIT;

		$inventory_refresh = null;
		if ( $refresh_inventory ) {
			$inventory_refresh = $this->worktree_inventory_refresh();
			if ( $inventory_refresh instanceof \WP_Error ) {
				return $inventory_refresh;
			}
		}

		if ( $include_worktree_status ) {
			$listing = $this->worktree_list();
			if ( is_wp_error( $listing ) ) {
				return $listing;
			}
			$worktrees            = (array) ( $listing['worktrees'] ?? array() );
			$worktree_status_mode = 'full_git_status';
		} else {
			$worktrees            = $this->build_workspace_inventory_rows();
			$worktree_status_mode = 'top_level_inventory';
		}

		$size_report   = $include_sizes ? $this->build_workspace_size_report( $size_limit ) : $this->empty_workspace_size_report( $size_limit, false );
		$cleanup       = null;
		$cleanup_error = null;
		$locks         = WorkspaceMutationLock::status( $this->workspace_path );

		if ( $include_cleanup ) {
			$cleanup = $this->worktree_cleanup_merged(
				array(
					'dry_run'        => true,
					'force'          => false,
					'skip_github'    => true,
					'inventory_only' => true,
				)
			);
			if ( $cleanup instanceof \WP_Error ) {
				$cleanup_error = array(
					'code'    => $cleanup->get_error_code(),
					'message' => $cleanup->get_error_message(),
				);
				$cleanup       = null;
			}
		}
		return array(
			'success'                   => true,
			'generated_at'              => gmdate( 'c' ),
			'workspace_path'            => $this->workspace_path,
			'destructive'               => false,
			'size'                      => $size_report,
			'disk'                      => $this->build_workspace_disk_report(),
			'inventory'                 => array(
				'freshness' => $this->worktree_inventory()->freshness(),
				'refresh'   => $inventory_refresh,
			),
			'worktrees'                 => $this->summarize_workspace_worktrees( $worktrees, $cleanup ),
			'worktree_status_mode'      => $worktree_status_mode,
			'top_repos_by_worktrees'    => $this->top_repos_by_worktree_count( $worktrees, 10 ),
			'top_repos_by_size'         => $this->top_repos_by_size( (array) ( $size_report['entries'] ?? array() ), 10 ),
			'locks'                     => $locks,
			'cleanup'                   => $this->summarize_workspace_cleanup( $cleanup, $cleanup_error, (array) ( $size_report['entries'] ?? array() ) ),
			'suggested_cleanup_command' => 'wp datamachine-code workspace worktree cleanup --dry-run --inventory-only --skip-github --format=json',
			'notes'                     => array_values( array_filter( array(
				$include_sizes ? (string) ( $size_report['mode_note'] ?? '' ) : 'Size scan disabled by request.',
				$include_worktree_status ? 'Full worktree status enabled; this may run git status across every worktree.' : 'Worktree status uses cheap top-level inventory; pass --include-worktree-status for full git status.',
				$include_cleanup ? 'Cleanup summary uses inventory-only dry-run detection (--inventory-only --skip-github); no per-worktree git probes or GitHub API lookups are required.' : 'Cleanup dry-run disabled by request.',
			) ) ),
		);
	}

	/**
	 * Run age-gated workspace retention cleanup and return a compact report.
	 *
	 * This is the scheduled/manual orchestration layer over the lower-level
	 * cleanup primitives: whole worktrees require a merge/finalization signal,
	 * while reconstructable artifacts are removed from any currently safe
	 * non-active worktree.
	 *
	 * @param array $opts Retention options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_retention_cleanup( array $opts = array() ): array|\WP_Error {
		$dry_run             = ! empty( $opts['dry_run'] );
		$force               = ! empty( $opts['force'] );
		$skip_github         = array_key_exists( 'skip_github', $opts ) ? (bool) $opts['skip_github'] : true;
		$worktree_cleanup    = array_key_exists( 'worktree_cleanup', $opts ) ? (bool) $opts['worktree_cleanup'] : true;
		$artifact_cleanup    = array_key_exists( 'artifact_cleanup', $opts ) ? (bool) $opts['artifact_cleanup'] : true;
		$worktree_older_than = isset( $opts['worktree_older_than'] ) && '' !== trim( (string) $opts['worktree_older_than'] ) ? trim( (string) $opts['worktree_older_than'] ) : '14d';

		$worktree_result = null;
		$artifact_result = null;
		$lock_retention  = WorkspaceMutationLock::prune_stale( $this->workspace_path, $dry_run );

		if ( $worktree_cleanup ) {
			$worktree_result = $this->worktree_cleanup_merged(
				array(
					'dry_run'     => $dry_run,
					'force'       => $force,
					'skip_github' => $skip_github,
					'older_than'  => $worktree_older_than,
					'sort'        => 'age',
				)
			);
			if ( $worktree_result instanceof \WP_Error ) {
				return $worktree_result;
			}
		}

		if ( $artifact_cleanup ) {
			// Retention cleanup orchestrates the full sweep — opt into the
			// exhaustive scan so the apply plan covers every safe worktree,
			// not just the bounded dry-run page CLI consumers see by default.
			$artifact_plan = $this->worktree_cleanup_artifacts(
				array(
					'dry_run'    => true,
					'force'      => $force,
					'exhaustive' => true,
				)
			);
			if ( $artifact_plan instanceof \WP_Error ) {
				return $artifact_plan;
			}

			$artifact_result = $artifact_plan;
			if ( ! $dry_run && ! empty( $artifact_plan['candidates'] ) ) {
				$artifact_result = $this->worktree_cleanup_artifacts(
					array(
						'apply_plan' => $artifact_plan,
						'force'      => $force,
					)
				);
				if ( $artifact_result instanceof \WP_Error ) {
					return $artifact_result;
				}
			}
		}

		$report = $this->build_workspace_retention_report( $worktree_result, $artifact_result, $dry_run );

		return array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'destructive'    => ! $dry_run,
			'generated_at'   => gmdate( 'c' ),
			'workspace_path' => $this->workspace_path,
			'policy'         => array(
				'worktree_cleanup'    => $worktree_cleanup,
				'artifact_cleanup'    => $artifact_cleanup,
				'worktree_older_than' => $worktree_older_than,
				'skip_github'         => $skip_github,
				'force'               => $force,
			),
			'lock_retention' => $lock_retention,
			'storage'        => $this->cleanup_storage_status(),
			'report'         => $report,
			'worktrees'      => $worktree_result,
			'artifacts'      => $artifact_result,
			'disk'           => $this->build_workspace_disk_report(),
		);
	}

	/**
	 * Run disk-threshold-triggered emergency cleanup orchestration.
	 *
	 * This is the automation-safe layer: inspect cheap disk/worktree metrics,
	 * build the inventory-only emergency plan, and apply only reconstructable
	 * artifact chunks by default. Whole-worktree deletion requires an explicit
	 * cleanup-eligible plan plus human-approved escalation.
	 *
	 * @param array $opts Emergency automation options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_disk_emergency_cleanup( array $opts = array() ): array|\WP_Error {
		$dry_run                  = ! empty( $opts['dry_run'] );
		$artifact_chunk_size      = isset( $opts['artifact_chunk_size'] ) && is_numeric( $opts['artifact_chunk_size'] ) ? max( 1, (int) $opts['artifact_chunk_size'] ) : 10;
		$allow_worktree_deletion  = ! empty( $opts['allow_worktree_deletion'] );
		$human_approved_deletion  = ! empty( $opts['human_approved_worktree_deletion'] );
		$force_worktree_deletion  = ! empty( $opts['force'] ) && $human_approved_deletion;
		$thresholds               = isset( $opts['thresholds'] ) && is_array( $opts['thresholds'] ) ? $opts['thresholds'] : WorktreeDiskBudget::thresholds( 'workspace', 'emergency-cleanup' );
		$budget                   = WorktreeDiskBudget::inspect( $this->workspace_path, $thresholds );

		$plan = $this->worktree_emergency_cleanup( array( 'dry_run' => true ) );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$artifact_candidates             = (array) ( $plan['artifact_candidates'] ?? array() );
		$worktree_candidates             = (array) ( $plan['worktree_candidates'] ?? array() );
		$top_artifact_offenders          = $this->summarize_top_worktree_rows( $artifact_candidates, 'artifact_size_bytes' );
		$budget['top_artifact_offenders'] = $top_artifact_offenders;

		$triggered = ! empty( $budget['emergency_triggered'] );
		if ( ! $triggered ) {
			return array(
				'success'             => true,
				'triggered'           => false,
				'skipped'             => true,
				'reason'              => 'disk thresholds not crossed',
				'dry_run'             => $dry_run,
				'generated_at'        => gmdate( 'c' ),
				'workspace_path'      => $this->workspace_path,
				'disk_budget'         => $budget,
				'emergency_plan'      => $plan,
				'action_required'     => false,
				'applied'             => null,
			);
		}

		$selected_artifacts = array_slice( $artifact_candidates, 0, $artifact_chunk_size );
		$blocked_reasons    = array();
		$selected_worktrees = array();
		if ( array() === $selected_artifacts && array() !== $worktree_candidates ) {
			if ( $allow_worktree_deletion && $human_approved_deletion ) {
				$selected_worktrees = $worktree_candidates;
			} else {
				$blocked_reasons[] = 'worktree_deletion_requires_human_approval';
			}
		}

		$apply_plan = array_merge( $plan, array(
			'artifact_candidates' => $selected_artifacts,
			'worktree_candidates' => $selected_worktrees,
		) );

		$applied = null;
		if ( ! $dry_run && ( array() !== $selected_artifacts || array() !== $selected_worktrees ) ) {
			$applied = $this->worktree_emergency_cleanup(
				array(
					'apply_plan' => $apply_plan,
					'force'      => $force_worktree_deletion,
				)
			);
			if ( $applied instanceof \WP_Error ) {
				return $applied;
			}
		}

		$apply_skipped_by_reason = (array) ( $applied['summary']['skipped_by_reason'] ?? array() );
		foreach ( array( 'dirty_worktree', 'unpushed_commits', 'emergency_lifecycle_not_current', 'emergency_worktree_plan_not_current' ) as $reason ) {
			if ( ! empty( $apply_skipped_by_reason[ $reason ] ) ) {
				$blocked_reasons[] = $reason;
			}
		}
		$blocked_reasons = array_values( array_unique( $blocked_reasons ) );

		return array(
			'success'                 => true,
			'triggered'               => true,
			'skipped'                 => false,
			'dry_run'                 => $dry_run,
			'generated_at'            => gmdate( 'c' ),
			'workspace_path'          => $this->workspace_path,
			'disk_budget'             => $budget,
			'emergency_plan'          => $plan,
			'apply_plan'              => $apply_plan,
			'applied'                 => $applied,
			'artifact_chunk_size'     => $artifact_chunk_size,
			'selected_artifact_count' => count( $selected_artifacts ),
			'selected_worktree_count' => count( $selected_worktrees ),
			'action_required'         => array() !== $blocked_reasons || ( array() === $selected_artifacts && array() !== $worktree_candidates && array() === $selected_worktrees ),
			'action_required_reasons' => $blocked_reasons,
			'policy'                  => array(
				'artifact_first'                      => true,
				'allow_worktree_deletion'             => $allow_worktree_deletion,
				'human_approved_worktree_deletion'    => $human_approved_deletion,
				'force_requires_human_approval'       => true,
				'force_worktree_deletion_applied'     => $force_worktree_deletion,
			),
		);
	}

	/**
	 * Build cheap workspace inventory rows from top-level directory names.
	 *
	 * This intentionally avoids `git worktree list` and per-worktree `git status`.
	 * It is the safe default for huge workspaces where hygiene should still be
	 * able to return a bounded JSON report.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_workspace_inventory_rows(): array {
		if ( '' === $this->workspace_path || ! is_dir( $this->workspace_path ) ) {
			return array();
		}

		$entries = scandir( $this->workspace_path );
		if ( false === $entries ) {
			return array();
		}

		$rows = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$parsed      = $this->parse_handle( $entry );
			$kind        = $this->classify_workspace_entry_kind( $entry, $parsed, $path );
			$is_worktree = 'worktree' === $kind;
			$metadata    = $is_worktree ? WorktreeContextInjector::get_metadata( $parsed['dir_name'] ) : null;

			$liveness     = WorktreeContextInjector::classify_liveness( is_array( $metadata ) ? $metadata : null );
			$owner        = WorktreeContextInjector::summarize_owner( is_array( $metadata ) ? $metadata : null );
			$session_view = WorktreeContextInjector::summarize_session( is_array( $metadata ) ? $metadata : null );
			$task_view    = is_array( $metadata ) && is_array( $metadata['origin_task'] ?? null ) ? $metadata['origin_task'] : null;

			$rows[] = array(
				'handle'           => $parsed['dir_name'],
				'repo'             => $parsed['repo'],
				'kind'             => $kind,
				'is_worktree'      => $is_worktree,
				'is_primary'       => 'primary' === $kind,
				'external'         => false,
				'branch_slug'      => $parsed['branch_slug'],
				'path'             => $path,
				'dirty'            => 0,
				'created_at'       => is_array( $metadata ) ? ( $metadata['created_at'] ?? null ) : null,
				'lifecycle_state'  => is_array( $metadata ) ? ( $metadata['lifecycle_state'] ?? null ) : null,
				'pr_url'           => is_array( $metadata ) ? ( $metadata['pr_url'] ?? null ) : null,
				'pr_number'        => is_array( $metadata ) ? ( $metadata['pr_number'] ?? null ) : null,
				'last_seen_at'     => is_array( $metadata ) ? ( $metadata['last_seen_at'] ?? null ) : null,
				'liveness'         => $liveness['liveness'],
				'liveness_reason'  => $liveness['reason'],
				'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
				'owner'            => $owner,
				'session'          => $session_view,
				'task'             => $task_view,
				'missing_metadata' => $is_worktree && ! is_array( $metadata ),
				'metadata'         => $metadata,
			);
		}

		return $rows;
	}

	/**
	 * Build best-effort workspace size data from top-level entries.
	 *
	 * @param int $limit Maximum entries to size.
	 * @return array<string,mixed>
	 */
	private function build_workspace_size_report( int $limit ): array {
		if ( '' === $this->workspace_path || ! is_dir( $this->workspace_path ) ) {
			return $this->empty_workspace_size_report( $limit, true );
		}

		$entries = scandir( $this->workspace_path );
		if ( false === $entries ) {
			return $this->empty_workspace_size_report( $limit, true );
		}

		$dirs = array_values( array_filter(
			$entries,
			fn( $entry ) => '.' !== $entry && '..' !== $entry && is_dir( $this->workspace_path . '/' . $entry )
		) );
		sort( $dirs, SORT_NATURAL );

		$total_dirs = count( $dirs );
		$sample     = array_slice( $dirs, 0, $limit );
		$rows       = array();
		$total      = 0;

		foreach ( $sample as $entry ) {
			$path = $this->workspace_path . '/' . $entry;
			$size = $this->directory_size_bytes_best_effort( $path );
			if ( null === $size ) {
				continue;
			}

			$parsed = $this->parse_handle( $entry );
			$total += $size;
			$rows[] = array(
				'handle'      => $entry,
				'repo'        => $parsed['repo'],
				'is_worktree' => ! empty( $parsed['is_worktree'] ),
				'kind'        => $this->classify_workspace_entry_kind( $entry, $parsed, $path ),
				'path'        => $path,
				'bytes'       => $size,
				'human'       => $this->format_bytes( $size ),
			);
		}

		usort( $rows, fn( $a, $b ) => (int) $b['bytes'] <=> (int) $a['bytes'] );
		$scanned_count = count( $sample );

		return array(
			'mode'            => 'best_effort_top_level_du',
			'mode_note'       => 'Workspace size is best-effort: top-level entries are sized with du and capped by size_limit.',
			'size_limit'      => $limit,
			'total_entries'   => $total_dirs,
			'scanned_entries' => $scanned_count,
			'scan_complete'   => $scanned_count >= $total_dirs,
			'total_bytes'     => $total,
			'total_human'     => $this->format_bytes( $total ),
			'by_kind'         => $this->workspace_size_by_kind( $rows ),
			'entries'         => $rows,
			'top_entries'     => array_slice( $rows, 0, 10 ),
		);
	}

	/**
	 * Empty size-report envelope.
	 *
	 * @param int  $limit   Configured size limit.
	 * @param bool $enabled Whether size scanning was requested.
	 * @return array<string,mixed>
	 */
	private function empty_workspace_size_report( int $limit, bool $enabled ): array {
		return array(
			'mode'            => $enabled ? 'best_effort_top_level_du' : 'disabled',
			'mode_note'       => $enabled ? 'Workspace path is unavailable or unreadable; no size data collected.' : 'Size scan disabled by request.',
			'size_limit'      => $limit,
			'total_entries'   => 0,
			'scanned_entries' => 0,
			'scan_complete'   => true,
			'total_bytes'     => 0,
			'total_human'     => $this->format_bytes( 0 ),
			'by_kind'         => array(),
			'entries'         => array(),
			'top_entries'     => array(),
		);
	}

	/**
	 * Best-effort directory size via `du -sk`.
	 *
	 * @param string $path Directory path.
	 * @return int|null Size in bytes, or null when unavailable.
	 */
	private function directory_size_bytes_best_effort( string $path ): ?int {
		if ( ! is_dir( $path ) ) {
			return null;
		}

		$output = array();
		$exit   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Local workspace hygiene needs best-effort disk usage; input path is shell-escaped.
		exec( sprintf( 'du -sk %s 2>/dev/null', escapeshellarg( $path ) ), $output, $exit );
		if ( 0 !== $exit || empty( $output[0] ) ) {
			return null;
		}

		$parts = preg_split( '/\s+/', trim( (string) $output[0] ) );
		$kb    = isset( $parts[0] ) ? (int) $parts[0] : 0;
		return max( 0, $kb ) * 1024;
	}

	/**
	 * Build workspace disk/free-space report.
	 *
	 * @return array<string,mixed>
	 */
	private function build_workspace_disk_report(): array {
		$path  = '' !== $this->workspace_path && is_dir( $this->workspace_path ) ? $this->workspace_path : dirname( $this->workspace_path );
		$free  = '' !== $path ? disk_free_space( $path ) : false;
		$total = '' !== $path ? disk_total_space( $path ) : false;

		$free_bytes  = false === $free ? null : (int) $free;
		$total_bytes = false === $total ? null : (int) $total;

		return array(
			'path'        => $path,
			'free_bytes'  => $free_bytes,
			'free_human'  => null === $free_bytes ? null : $this->format_bytes( $free_bytes ),
			'total_bytes' => $total_bytes,
			'total_human' => null === $total_bytes ? null : $this->format_bytes( $total_bytes ),
		);
	}

	/**
	 * Summarize worktree listing and cleanup-protected counts.
	 *
	 * @param array<int,array> $worktrees Worktree rows.
	 * @param array|null       $cleanup   Cleanup dry-run report.
	 * @return array<string,mixed>
	 */
	private function summarize_workspace_worktrees( array $worktrees, ?array $cleanup ): array {
		$summary = array(
			'total'              => count( $worktrees ),
			'primaries'          => 0,
			'worktrees'          => 0,
			'artifacts'          => 0,
			'external'           => 0,
			'dirty'              => 0,
			'protected_dirty'    => 0,
			'protected_unpushed' => 0,
			'missing_metadata'   => 0,
			'by_liveness'        => array(
				WorktreeContextInjector::LIVENESS_LIVE    => 0,
				WorktreeContextInjector::LIVENESS_STOPPED => 0,
				WorktreeContextInjector::LIVENESS_STALE   => 0,
				WorktreeContextInjector::LIVENESS_UNKNOWN => 0,
			),
			'duplicate_task_groups' => 0,
		);

		foreach ( $worktrees as $row ) {
			if ( ! empty( $row['is_primary'] ) ) {
				++$summary['primaries'];
			} elseif ( ! empty( $row['is_worktree'] ) ) {
				++$summary['worktrees'];
			} elseif ( 'artifact' === (string) ( $row['kind'] ?? '' ) ) {
				++$summary['artifacts'];
			}

			if ( ! empty( $row['external'] ) ) {
				++$summary['external'];
			}

			if ( (int) ( $row['dirty'] ?? 0 ) > 0 ) {
				++$summary['dirty'];
			}

			if ( ! empty( $row['missing_metadata'] ) ) {
				++$summary['missing_metadata'];
			}

			if ( ! empty( $row['is_worktree'] ) ) {
				$liveness = (string) ( $row['liveness'] ?? WorktreeContextInjector::LIVENESS_UNKNOWN );
				if ( ! isset( $summary['by_liveness'][ $liveness ] ) ) {
					$summary['by_liveness'][ $liveness ] = 0;
				}
				++$summary['by_liveness'][ $liveness ];
			}
		}

		$duplicates = WorktreeContextInjector::find_duplicate_task_ownership( $worktrees );
		$summary['duplicate_task_groups'] = count( $duplicates );
		$summary['duplicates']            = $duplicates;

		if ( null !== $cleanup ) {
			$by_reason                     = (array) ( $cleanup['summary']['skipped_by_reason'] ?? array() );
			$summary['protected_dirty']    = (int) ( $by_reason['dirty_worktree'] ?? 0 );
			$summary['protected_unpushed'] = (int) ( $by_reason['unpushed_commits'] ?? 0 );
			$summary['missing_metadata']   = (int) ( $by_reason['missing_metadata'] ?? 0 );
			$summary['external']           = max( $summary['external'], (int) ( $by_reason['external_worktree'] ?? 0 ) );
		}

		return $summary;
	}

	/**
	 * Summarize cleanup dry-run output for hygiene reports.
	 *
	 * @param array|null $cleanup Cleanup report.
	 * @param array|null $error   Cleanup error envelope.
	 * @return array<string,mixed>
	 */
	private function summarize_workspace_cleanup( ?array $cleanup, ?array $error, array $size_entries = array() ): array {
		if ( null === $cleanup ) {
			return array(
				'included' => false,
				'error'    => $error,
			);
		}

		$candidates     = (array) ( $cleanup['candidates'] ?? array() );
		$size_by_handle = array();
		foreach ( $size_entries as $entry ) {
			$handle = (string) ( $entry['handle'] ?? '' );
			if ( '' !== $handle ) {
				$size_by_handle[ $handle ] = array(
					'bytes' => (int) ( $entry['bytes'] ?? 0 ),
					'human' => (string) ( $entry['human'] ?? '' ),
				);
			}
		}
		foreach ( $candidates as &$candidate ) {
			$handle = (string) ( $candidate['handle'] ?? '' );
			if ( isset( $size_by_handle[ $handle ] ) ) {
				$candidate['size_bytes'] = $size_by_handle[ $handle ]['bytes'];
				$candidate['size_human'] = $size_by_handle[ $handle ]['human'];
			}
		}
		unset( $candidate );
		usort( $candidates, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ) );
		return array(
			'included'             => true,
			'dry_run'              => true,
			'skip_github'          => true,
			'inventory_only'       => ! empty( $cleanup['inventory_only'] ),
			'summary'              => $cleanup['summary'] ?? array(),
			'biggest_candidates'   => array_slice( $candidates, 0, 10 ),
			'skipped_by_reason'    => $cleanup['summary']['skipped_by_reason'] ?? array(),
			'candidates_by_signal' => $cleanup['summary']['candidates_by_signal'] ?? array(),
		);
	}

	/**
	 * Report whether DB cleanup storage tables are available for retention hooks.
	 *
	 * @return array<string,mixed>
	 */
	private function cleanup_storage_status(): array {
		$status = array(
			'cleanup_runs_available'  => false,
			'cleanup_items_available' => false,
			'locks_available'         => (bool) ( WorkspaceLockStore::status()['available'] ?? false ),
			'policy_hooks'            => array(
				'datamachine_code_lock_expires_seconds',
				'datamachine_code_lock_released_ttl_seconds',
				'datamachine_code_cleanup_lock_retention_policy',
			),
			'note'                    => 'Cleanup run/item table retention is inactive until those DB tables exist; lock storage is handled separately in datamachine_code_locks.',
		);

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return $status;
		}

		$runs_table                         = $wpdb->prefix . 'datamachine_code_cleanup_runs';
		$items_table                        = $wpdb->prefix . 'datamachine_code_cleanup_items';
		$status['cleanup_runs_available']  = $this->database_table_exists( $runs_table );
		$status['cleanup_items_available'] = $this->database_table_exists( $items_table );
		if ( $status['cleanup_runs_available'] && $status['cleanup_items_available'] ) {
			$status['note'] = 'Cleanup run/item tables are available; future retention can attach to the declared cleanup storage hooks without changing lock ownership.';
		}

		return $status;
	}

	private function database_table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Build the compact retention cleanup report used by automation surfaces.
	 *
	 * @param array|null $worktree_result Whole-worktree cleanup result.
	 * @param array|null $artifact_result Artifact cleanup result.
	 * @param bool       $dry_run         Whether the retention run was non-destructive.
	 * @return array<string,mixed>
	 */
	private function build_workspace_retention_report( ?array $worktree_result, ?array $artifact_result, bool $dry_run ): array {
		$worktree_summary = (array) ( $worktree_result['summary'] ?? array() );
		$artifact_summary = (array) ( $artifact_result['summary'] ?? array() );
		$disk             = $this->build_workspace_disk_report();

		$removed_worktree_bytes = $this->sum_cleanup_rows_bytes( (array) ( $worktree_result['removed'] ?? array() ), 'size_bytes' );
		$removed_artifact_bytes = (int) ( $artifact_summary['removed_size_bytes'] ?? 0 );
		$would_free_bytes       = (int) ( $worktree_summary['total_size_bytes'] ?? 0 ) + (int) ( $artifact_summary['artifact_size_bytes'] ?? 0 );
		$freed_bytes            = $dry_run ? 0 : $removed_worktree_bytes + $removed_artifact_bytes;
		$skip_reasons           = array_merge_recursive(
			(array) ( $worktree_summary['skipped_by_reason'] ?? array() ),
			(array) ( $artifact_summary['skipped_by_reason'] ?? array() )
		);
		$dirty_skipped          = $this->sum_reason_count( $skip_reasons, 'dirty_worktree' );
		$unpushed_skipped       = $this->sum_reason_count( $skip_reasons, 'unpushed_commits' );

		return array(
			'removed_count'                 => (int) ( $worktree_summary['removed'] ?? 0 ) + (int) ( $artifact_summary['removed_artifacts'] ?? 0 ),
			'removed_worktrees'             => (int) ( $worktree_summary['removed'] ?? 0 ),
			'removed_artifacts'             => (int) ( $artifact_summary['removed_artifacts'] ?? 0 ),
			'would_remove_worktrees'        => (int) ( $worktree_summary['would_remove'] ?? 0 ),
			'would_remove_artifacts'        => (int) ( $artifact_summary['would_remove_artifacts'] ?? 0 ),
			'freed_bytes'                   => $freed_bytes,
			'freed_human'                   => $this->format_bytes( $freed_bytes ),
			'would_free_bytes'              => $would_free_bytes,
			'would_free_human'              => $this->format_bytes( $would_free_bytes ),
			'skipped_dirty_unpushed_count'  => $dirty_skipped + $unpushed_skipped,
			'skipped_dirty_count'           => $dirty_skipped,
			'skipped_unpushed_count'        => $unpushed_skipped,
			'remaining_disk_budget_bytes'   => $disk['free_bytes'] ?? null,
			'remaining_disk_budget_human'   => $disk['free_human'] ?? null,
			'remaining_disk_budget_summary' => sprintf( '%s free', (string) ( $disk['free_human'] ?? 'unknown' ) ),
			'worktree_skipped_by_reason'    => (array) ( $worktree_summary['skipped_by_reason'] ?? array() ),
			'artifact_skipped_by_reason'    => (array) ( $artifact_summary['skipped_by_reason'] ?? array() ),
		);
	}

	/**
	 * Sum an integer field across cleanup rows.
	 *
	 * @param array<int,array> $rows  Cleanup rows.
	 * @param string           $field Field to sum.
	 * @return int
	 */
	private function sum_cleanup_rows_bytes( array $rows, string $field ): int {
		$total = 0;
		foreach ( $rows as $row ) {
			$total += max( 0, (int) ( is_array( $row ) ? ( $row[ $field ] ?? 0 ) : 0 ) );
		}
		return $total;
	}

	/**
	 * Read a reason count from a possibly merge_recursive-shaped map.
	 *
	 * @param array  $reasons Reason count map.
	 * @param string $key     Reason key.
	 * @return int
	 */
	private function sum_reason_count( array $reasons, string $key ): int {
		$value = $reasons[ $key ] ?? 0;
		if ( is_array( $value ) ) {
			return array_sum( array_map( 'intval', $value ) );
		}
		return (int) $value;
	}

	/**
	 * Count worktrees by repo.
	 *
	 * @param array<int,array> $worktrees Worktree rows.
	 * @param int              $limit     Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function top_repos_by_worktree_count( array $worktrees, int $limit ): array {
		$counts = array();
		foreach ( $worktrees as $row ) {
			if ( empty( $row['is_worktree'] ) ) {
				continue;
			}
			$repo = (string) ( $row['repo'] ?? '' );
			if ( '' === $repo ) {
				continue;
			}
			$counts[ $repo ] = ( $counts[ $repo ] ?? 0 ) + 1;
		}

		arsort( $counts );
		$rows = array();
		foreach ( array_slice( $counts, 0, $limit, true ) as $repo => $count ) {
			$rows[] = array(
				'repo'           => $repo,
				'worktree_count' => (int) $count,
			);
		}
		return $rows;
	}

	/**
	 * Sum best-effort size rows by repo.
	 *
	 * @param array<int,array> $entries Size rows.
	 * @param int              $limit   Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function top_repos_by_size( array $entries, int $limit ): array {
		$sizes = array();
		foreach ( $entries as $entry ) {
			$repo = (string) ( $entry['repo'] ?? '' );
			if ( '' === $repo ) {
				continue;
			}
			$sizes[ $repo ] = ( $sizes[ $repo ] ?? 0 ) + (int) ( $entry['bytes'] ?? 0 );
		}

		arsort( $sizes );
		$rows = array();
		foreach ( array_slice( $sizes, 0, $limit, true ) as $repo => $bytes ) {
			$rows[] = array(
				'repo'  => $repo,
				'bytes' => (int) $bytes,
				'human' => $this->format_bytes( (int) $bytes ),
			);
		}
		return $rows;
	}

	/**
	 * Sum best-effort size rows by top-level workspace entry kind.
	 *
	 * @param array<int,array> $entries Size rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function workspace_size_by_kind( array $entries ): array {
		$sizes = array(
			'primary'  => 0,
			'worktree' => 0,
			'artifact' => 0,
			'other'    => 0,
		);

		foreach ( $entries as $entry ) {
			$kind = (string) ( $entry['kind'] ?? 'other' );
			if ( ! array_key_exists( $kind, $sizes ) ) {
				$kind = 'other';
			}
			$sizes[ $kind ] += (int) ( $entry['bytes'] ?? 0 );
		}

		$rows = array();
		foreach ( $sizes as $kind => $bytes ) {
			$rows[] = array(
				'kind'  => $kind,
				'bytes' => (int) $bytes,
				'human' => $this->format_bytes( (int) $bytes ),
			);
		}

		usort( $rows, fn( $a, $b ) => (int) $b['bytes'] <=> (int) $a['bytes'] );
		return $rows;
	}

	/**
	 * Classify a top-level workspace directory without probing git state.
	 *
	 * @param string $entry  Directory basename.
	 * @param array  $parsed Parsed workspace handle.
	 * @param string $path   Top-level directory path.
	 * @return string
	 */
	private function classify_workspace_entry_kind( string $entry, array $parsed, string $path ): string {
		if ( ! empty( $parsed['is_worktree'] ) ) {
			return 'worktree';
		}

		if ( is_dir( rtrim( $path, '/' ) . '/.git' ) ) {
			return 'primary';
		}

		$artifact_names = array( '.cache', '.composer', '.npm', '.pnpm-store', '.tmp', 'artifacts', 'cache', 'tmp' );
		if ( in_array( strtolower( $entry ), $artifact_names, true ) ) {
			return 'artifact';
		}

		return '' !== (string) ( $parsed['repo'] ?? '' ) ? 'primary' : 'other';
	}

	/**
	 * Format bytes for reports.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	private function format_bytes( int $bytes ): string {
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$unit_count = count( $units );
		$value      = (float) max( 0, $bytes );
		$index      = 0;
		while ( $value >= 1024 && $index < $unit_count - 1 ) {
			$value /= 1024;
			++$index;
		}

		return sprintf( $index > 0 ? '%.1f %s' : '%.0f %s', $value, $units[ $index ] );
	}

}
