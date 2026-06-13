<?php
/**
 * Workspace cleanup plan operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

if ( ! class_exists(WorktreeCleanupClassifier::class) ) {
	require_once __DIR__ . '/WorktreeCleanupClassifier.php';
}

trait WorkspaceCleanupPlan {



	/**
	 * Freeze a non-destructive workspace cleanup plan for chunked execution.
	 *
	 * The plan deliberately stops at reviewable data. It does not apply any
	 * cleanup operation; later jobs can consume the emitted chunks and revalidate
	 * each row against current state before mutating the workspace.
	 *
	 * @param  array<string,mixed> $opts Plan options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_cleanup_plan( array $opts = array() ): array|\WP_Error {
		$inputs = array(
			'force_artifact_cleanup' => ! empty($opts['force_artifact_cleanup']),
			'include_artifacts'      => array_key_exists('include_artifacts', $opts) ? (bool) $opts['include_artifacts'] : true,
			'include_worktrees'      => array_key_exists('include_worktrees', $opts) ? (bool) $opts['include_worktrees'] : true,
			'include_resolvers'      => ! empty($opts['include_resolvers']),
			'top_n'                  => isset($opts['top_n']) ? max(1, min(50, (int) $opts['top_n'])) : 10,
			'worktree_older_than'    => isset($opts['worktree_older_than']) ? trim( (string) $opts['worktree_older_than']) : '',
			'worktree_sort'          => isset($opts['worktree_sort']) ? trim( (string) $opts['worktree_sort']) : '',
		);

		$artifact_plan = array(
			'candidates' => array(),
			'skipped'    => array(),
			'summary'    => array(),
		);
		if ( $inputs['include_artifacts'] ) {
			// Workspace cleanup plan is the source-of-truth orchestrator that
			// later chunks/jobs consume — opt into the exhaustive scan so all
			// safe worktrees are reviewed, not just the bounded dry-run page.
			$artifact_plan = $this->worktree_cleanup_artifacts(
				array(
					'dry_run'    => true,
					'force'      => $inputs['force_artifact_cleanup'],
					'exhaustive' => true,
				)
			);
			if ( $artifact_plan instanceof \WP_Error ) {
				return $artifact_plan;
			}
		}

		$worktree_plan = array(
			'candidates' => array(),
			'skipped'    => array(),
			'summary'    => array(),
		);
		if ( $inputs['include_worktrees'] ) {
			$worktree_plan = $this->worktree_cleanup_merged(
				array(
					'dry_run'     => true,
					'skip_github' => true,
					'older_than'  => $inputs['worktree_older_than'],
					'sort'        => $inputs['worktree_sort'],
				)
			);
			if ( $worktree_plan instanceof \WP_Error ) {
				return $worktree_plan;
			}
		}

		$rows = array(
			'artifact_cleanup' => $this->prepare_cleanup_plan_rows('artifact_cleanup', (array) ( $artifact_plan['candidates'] ?? array() ), 'safe'),
			'worktree_removal' => $this->prepare_cleanup_plan_rows('worktree_removal', (array) ( $worktree_plan['candidates'] ?? array() ), 'reviewed_destructive'),
			'resolver'         => $inputs['include_resolvers'] ? $this->build_cleanup_plan_resolver_rows( (array) ( $worktree_plan['skipped'] ?? array() )) : array(),
		);

		$summary         = $this->build_cleanup_plan_summary($rows, $artifact_plan, $worktree_plan, $inputs);
		$plan            = array(
			'success'        => true,
			'mode'           => 'cleanup_plan',
			'generated_at'   => gmdate('c'),
			'workspace_path' => $this->workspace_path,
			'inputs'         => $inputs,
			'safety_policy'  => array(
				'applies_inline'               => false,
				'artifact_cleanup'             => 'apply-plan must revalidate profile-derived artifact paths before deletion',
				'worktree_removal'             => 'apply-plan must re-run dirty, unpushed, identity, lifecycle, containment, and primary protections before deletion',
				'resolver'                     => 'resolver rows may gather merge signals but cannot delete worktrees',
				'destructive_rows_need_review' => true,
			),
			'plans'          => array(
				'artifact_cleanup' => $artifact_plan,
				'worktree_removal' => $worktree_plan,
			),
			'rows'           => $rows,
			'summary'        => $summary,
		);
		$plan['plan_id'] = $this->stable_cleanup_hash(
			array(
				'inputs' => $inputs,
				'rows'   => $this->cleanup_row_ids($rows),
			),
			'cleanup-plan'
		);

		return $plan;
	}

	/**
	 * Build bounded cleanup chunks from a frozen cleanup plan.
	 *
	 * @param  array<string,mixed> $opts Chunk options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_cleanup_plan_chunks( array $opts = array() ): array|\WP_Error {
		$chunk_size = isset($opts['chunk_size']) ? (int) $opts['chunk_size'] : 10;
		$chunk_size = max(1, min(50, $chunk_size));
		$plan       = isset($opts['plan']) && is_array($opts['plan']) ? $opts['plan'] : $this->workspace_cleanup_plan($opts);
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}

		$plan_id = (string) ( $plan['plan_id'] ?? '' );
		if ( '' === $plan_id ) {
			$plan_id         = $this->stable_cleanup_hash(array( 'rows' => $this->cleanup_row_ids( (array) ( $plan['rows'] ?? array() )) ), 'cleanup-plan');
			$plan['plan_id'] = $plan_id;
		}

		$chunks = array();
		foreach ( (array) ( $plan['rows'] ?? array() ) as $type => $rows ) {
			$groups = array();
			foreach ( (array) $rows as $row ) {
				if ( ! is_array($row) ) {
					continue;
				}
				if ( empty($row['row_id']) ) {
					$row['row_id'] = $this->stable_cleanup_row_id( (string) $type, $row);
				}
				$class              = (string) ( $row['safety_class'] ?? 'unknown' );
				$groups[ $class ][] = $row;
			}

			ksort($groups);
			foreach ( $groups as $safety_class => $group_rows ) {
				usort($group_rows, fn( $a, $b ) => (string) ( $a['row_id'] ?? '' ) <=> (string) ( $b['row_id'] ?? '' ));
				$index = 0;
				foreach ( array_chunk($group_rows, $chunk_size) as $chunk_rows ) {
					++$index;
					$row_ids  = array_map(fn( $row ) => (string) ( $row['row_id'] ?? '' ), $chunk_rows);
					$chunks[] = array(
						'chunk_id'       => $this->stable_cleanup_hash(array( $plan_id, $type, $safety_class, $index, $row_ids ), 'cleanup-chunk'),
						'plan_id'        => $plan_id,
						'type'           => (string) $type,
						'safety_class'   => $safety_class,
						'index'          => $index,
						'chunk_size'     => count($chunk_rows),
						'max_rows'       => $chunk_size,
						'row_ids'        => $row_ids,
						'rows'           => $chunk_rows,
						'idempotency'    => array(
							'key'                     => $this->stable_cleanup_hash(array( $plan_id, $row_ids ), 'cleanup-idempotency'),
							'revalidate_before_apply' => true,
						),
						'workspace_path' => $plan['workspace_path'] ?? $this->workspace_path,
					);
				}
			}
		}

		return array(
			'success'      => true,
			'mode'         => 'cleanup_plan_chunks',
			'generated_at' => gmdate('c'),
			'plan_id'      => $plan_id,
			'chunk_size'   => $chunk_size,
			'plan'         => $plan,
			'chunks'       => $chunks,
			'summary'      => $this->build_cleanup_chunk_summary($chunks, $plan),
		);
	}

	/**
	 * Add stable row metadata to cleanup plan rows.
	 *
	 * @param  string           $type         Cleanup row type.
	 * @param  array<int,array> $rows         Raw plan rows.
	 * @param  string           $safety_class Safety class.
	 * @return array<int,array<string,mixed>>
	 */
	private function prepare_cleanup_plan_rows( string $type, array $rows, string $safety_class ): array {
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$row['row_type']     = $type;
			$row['safety_class'] = $safety_class;
			$row['row_id']       = $this->stable_cleanup_row_id($type, $row);
			$result[]            = $row;
		}
		return $result;
	}

	/**
	 * Build optional resolver rows from ambiguous inventory-only cleanup skips.
	 *
	 * @param  array<int,array> $skipped Inventory skipped rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_cleanup_plan_resolver_rows( array $skipped ): array {
		$rows = array();
		foreach ( $skipped as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$reason = (string) ( $row['reason_code'] ?? '' );
			if ( ! WorktreeCleanupClassifier::is_resolver_reason($reason) ) {
				continue;
			}

			$resolver_type = WorktreeCleanupClassifier::resolver_type($reason);
			$next_action   = match ( $resolver_type ) {
				'metadata_reconciliation'  => sprintf('workspace worktree reconcile-metadata --dry-run --limit=%d --offset=0 --until-budget=%s --format=json', self::METADATA_RECONCILE_DEFAULT_LIMIT, self::METADATA_RECONCILE_DEFAULT_BUDGET),
				'lifecycle_reconciliation' => 'workspace worktree cleanup --dry-run --format=json',
				default                    => 'workspace worktree cleanup --dry-run --skip-github --format=json',
			};

			$resolver           = array(
				'handle'       => (string) ( $row['handle'] ?? '' ),
				'repo'         => (string) ( $row['repo'] ?? '' ),
				'branch'       => (string) ( $row['branch'] ?? '' ),
				'path'         => (string) ( $row['path'] ?? '' ),
				'created_at'   => $row['created_at'] ?? null,
				'metadata'     => $row['metadata'] ?? null,
				'resolver'     => $resolver_type,
				'next_action'  => $next_action,
				'reason_code'  => $reason,
				'reason'       => 'resolve merge or lifecycle cleanup signal before any worktree removal chunk is emitted',
				'row_type'     => 'resolver',
				'safety_class' => 'read_only',
			);
			$resolver['row_id'] = $this->stable_cleanup_row_id('resolver', $resolver);
			$rows[]             = $resolver;
		}
		return $rows;
	}

	/**
	 * Build stable cleanup plan summary counts.
	 *
	 * @param  array<string,array<int,array>> $rows Rows keyed by type.
	 * @return array<string,mixed>
	 */
	private function build_cleanup_plan_summary( array $rows, array $artifact_plan = array(), array $worktree_plan = array(), array $inputs = array() ): array {
		$counts      = array();
		$byte_totals = array();
		$total_rows  = 0;
		$total_bytes = 0;

		foreach ( $rows as $type => $typed_rows ) {
			$counts[ $type ]      = count( (array) $typed_rows);
			$byte_totals[ $type ] = 0;
			$total_rows          += $counts[ $type ];
			foreach ( (array) $typed_rows as $row ) {
				$bytes                 = max(0, (int) ( $row['artifact_size_bytes'] ?? $row['size_bytes'] ?? 0 ));
				$byte_totals[ $type ] += $bytes;
				$total_bytes          += $bytes;
			}
		}

		ksort($counts);
		ksort($byte_totals);
		$category_totals = $this->cleanup_plan_category_totals($rows);
		$category_total  = array_sum(array_map('intval', $category_totals));

		return array(
			'total_rows'       => $total_rows,
			'rows_by_type'     => $counts,
			'byte_totals'      => $byte_totals,
			'total_size_bytes' => $category_total > 0 ? $category_total : $total_bytes,
			'category_totals'  => $category_totals,
			'top_reclaimable'  => $this->cleanup_plan_top_reclaimable_paths($rows, (int) ( $inputs['top_n'] ?? 10 )),
			'blockers'         => $this->cleanup_plan_blockers($artifact_plan, $worktree_plan),
			'recommended_commands' => $this->cleanup_plan_recommended_commands($inputs),
		);
	}

	/**
	 * Summarize reclaimable bytes by operator-facing category.
	 *
	 * @param  array<string,array<int,array>> $rows Cleanup rows keyed by type.
	 * @return array<string,int>
	 */
	private function cleanup_plan_category_totals( array $rows ): array {
		$totals = array(
			'whole_worktrees'       => 0,
			'dependency_artifacts'  => 0,
			'build_outputs'         => 0,
			'caches'                => 0,
		);

		foreach ( (array) ( $rows['worktree_removal'] ?? array() ) as $row ) {
			if ( is_array($row) ) {
				$totals['whole_worktrees'] += max(0, (int) ( $row['size_bytes'] ?? 0 ));
			}
		}

		foreach ( (array) ( $rows['artifact_cleanup'] ?? array() ) as $row ) {
			foreach ( (array) ( is_array($row) ? ( $row['artifacts'] ?? array() ) : array() ) as $artifact ) {
				if ( ! is_array($artifact) ) {
					continue;
				}
				$category            = $this->cleanup_artifact_category( (string) ( $artifact['path'] ?? '' ));
				$totals[ $category ] += max(0, (int) ( $artifact['size_bytes'] ?? 0 ));
			}
		}

		return $totals;
	}

	/**
	 * Classify a reconstructable artifact path for high-level reporting.
	 *
	 * @param  string $path Artifact path.
	 * @return string
	 */
	private function cleanup_artifact_category( string $path ): string {
		$name = strtolower(basename($path));
		if ( in_array($name, array( 'node_modules', 'vendor', '.pnpm-store', '.yarn', 'bower_components' ), true) ) {
			return 'dependency_artifacts';
		}

		if ( in_array($name, array( '.cache', 'cache', 'caches', '.npm', '.composer', '.turbo' ), true) ) {
			return 'caches';
		}

		return 'build_outputs';
	}

	/**
	 * Return the largest reclaimable paths across worktree and artifact rows.
	 *
	 * @param  array<string,array<int,array>> $rows Cleanup rows keyed by type.
	 * @param  int                            $limit Maximum paths to return.
	 * @return array<int,array<string,mixed>>
	 */
	private function cleanup_plan_top_reclaimable_paths( array $rows, int $limit ): array {
		$paths = array();
		foreach ( (array) ( $rows['worktree_removal'] ?? array() ) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$paths[] = array(
				'path'          => (string) ( $row['path'] ?? '' ),
				'handle'        => (string) ( $row['handle'] ?? '' ),
				'repo'          => (string) ( $row['repo'] ?? '' ),
				'category'      => 'whole_worktrees',
				'row_type'      => 'worktree_removal',
				'safety_class'  => (string) ( $row['safety_class'] ?? 'reviewed_destructive' ),
				'size_bytes'    => max(0, (int) ( $row['size_bytes'] ?? 0 )),
			);
		}

		foreach ( (array) ( $rows['artifact_cleanup'] ?? array() ) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				if ( ! is_array($artifact) ) {
					continue;
				}
				$paths[] = array(
					'path'          => (string) ( $artifact['path'] ?? '' ),
					'handle'        => (string) ( $row['handle'] ?? '' ),
					'repo'          => (string) ( $row['repo'] ?? '' ),
					'category'      => $this->cleanup_artifact_category( (string) ( $artifact['path'] ?? '' )),
					'row_type'      => 'artifact_cleanup',
					'safety_class'  => (string) ( $row['safety_class'] ?? 'safe' ),
					'size_bytes'    => max(0, (int) ( $artifact['size_bytes'] ?? 0 )),
				);
			}
		}

		usort($paths, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ));
		return array_slice($paths, 0, max(1, $limit));
	}

	/**
	 * Group blocked cleanup opportunities by reason and repo.
	 *
	 * @param  array<string,mixed> $artifact_plan Artifact plan.
	 * @param  array<string,mixed> $worktree_plan Worktree plan.
	 * @return array<string,array<string,mixed>>
	 */
	private function cleanup_plan_blockers( array $artifact_plan, array $worktree_plan ): array {
		$blockers = array();
		foreach ( array( 'artifact_cleanup' => $artifact_plan, 'worktree_removal' => $worktree_plan ) as $type => $plan ) {
			foreach ( (array) ( $plan['skipped'] ?? array() ) as $row ) {
				if ( ! is_array($row) ) {
					continue;
				}
				$reason = (string) ( $row['reason_code'] ?? 'unknown' );
				$repo   = (string) ( $row['repo'] ?? 'unknown' );
				if ( '' === $repo ) {
					$repo = 'unknown';
				}
				$bytes = max(0, (int) ( $row['artifact_size_bytes'] ?? $row['size_bytes'] ?? 0 ));
				$blockers[ $reason ] ??= array(
					'count'      => 0,
					'size_bytes' => 0,
					'repos'      => array(),
					'examples'   => array(),
				);
				$blockers[ $reason ]['count']      = (int) $blockers[ $reason ]['count'] + 1;
				$blockers[ $reason ]['size_bytes'] += $bytes;
				$blockers[ $reason ]['repos'][ $repo ] ??= array(
					'count'      => 0,
					'size_bytes' => 0,
					'examples'   => array(),
				);
				$blockers[ $reason ]['repos'][ $repo ]['count']      = (int) $blockers[ $reason ]['repos'][ $repo ]['count'] + 1;
				$blockers[ $reason ]['repos'][ $repo ]['size_bytes'] += $bytes;
				if ( count($blockers[ $reason ]['examples']) < 5 ) {
					$blockers[ $reason ]['examples'][] = (string) ( $row['handle'] ?? $row['path'] ?? '' );
				}
				if ( count($blockers[ $reason ]['repos'][ $repo ]['examples']) < 3 ) {
					$blockers[ $reason ]['repos'][ $repo ]['examples'][] = (string) ( $row['handle'] ?? $row['path'] ?? '' );
				}
			}
		}

		uasort($blockers, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ) ?: (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 ));
		foreach ( $blockers as &$bucket ) {
			uasort($bucket['repos'], fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ) ?: (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 ));
		}
		unset($bucket);

		return $blockers;
	}

	/**
	 * Build directly executable cleanup recommendations with risk labels.
	 *
	 * @param  array<string,mixed> $inputs Plan inputs.
	 * @return array<int,array<string,string>>
	 */
	private function cleanup_plan_recommended_commands( array $inputs ): array {
		$commands = array(
			array(
				'label'      => 'apply_reviewed_plan',
				'risk'       => 'reviewed_destructive',
				'command'    => 'studio wp datamachine-code workspace cleanup apply <run-id>',
				'when'       => 'after reviewing this plan; revalidates every destructive row before removal',
			),
			array(
				'label'      => 'inspect_full_plan_json',
				'risk'       => 'none',
				'command'    => 'studio wp datamachine-code workspace cleanup plan --mode=retention --format=json',
				'when'       => 'export the full plan for review or archival',
			),
			array(
				'label'      => 'resolve_metadata_blockers',
				'risk'       => 'none',
				'command'    => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json',
				'when'       => 'metadata blockers prevent classification',
			),
			array(
				'label'      => 'refresh_merge_signals',
				'risk'       => 'none',
				'command'    => 'studio wp datamachine-code workspace worktree cleanup --dry-run --format=json',
				'when'       => 'active or lifecycle rows need full merge/PR signal review',
			),
		);

		if ( empty($inputs['include_artifacts']) ) {
			$commands[] = array(
				'label'   => 'audit_artifacts',
				'risk'    => 'none',
				'command' => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts',
				'when'    => 'include dependency artifacts, build outputs, and caches in a separate full plan',
			);
		}

		$commands[] = array(
			'label'   => 'force_dirty_artifacts_only',
			'risk'    => 'high_destructive',
			'command' => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --force',
			'when'    => 'operator explicitly accepts artifact cleanup in dirty worktrees; source edits remain protected from worktree removal',
		);

		return $commands;
	}

	/**
	 * Build chunk summary counts.
	 *
	 * @param  array<int,array>    $chunks Chunks.
	 * @param  array<string,mixed> $plan   Frozen plan.
	 * @return array<string,mixed>
	 */
	private function build_cleanup_chunk_summary( array $chunks, array $plan ): array {
		$chunks_by_type   = array();
		$chunks_by_safety = array();
		$rows_by_type     = array();
		foreach ( $chunks as $chunk ) {
			$type                       = (string) ( $chunk['type'] ?? 'unknown' );
			$class                      = (string) ( $chunk['safety_class'] ?? 'unknown' );
			$chunks_by_type[ $type ]    = ( $chunks_by_type[ $type ] ?? 0 ) + 1;
			$chunks_by_safety[ $class ] = ( $chunks_by_safety[ $class ] ?? 0 ) + 1;
			$rows_by_type[ $type ]      = ( $rows_by_type[ $type ] ?? 0 ) + (int) ( $chunk['chunk_size'] ?? 0 );
		}
		ksort($chunks_by_type);
		ksort($chunks_by_safety);
		ksort($rows_by_type);

		return array(
			'total_chunks'     => count($chunks),
			'chunks_by_type'   => $chunks_by_type,
			'chunks_by_safety' => $chunks_by_safety,
			'rows_by_type'     => $rows_by_type,
			'plan_summary'     => $plan['summary'] ?? array(),
		);
	}

	/**
	 * Return row IDs keyed by type for deterministic plan IDs.
	 *
	 * @param  array<string,array<int,array>> $rows Rows keyed by type.
	 * @return array<string,array<int,string>>
	 */
	private function cleanup_row_ids( array $rows ): array {
		$ids = array();
		foreach ( $rows as $type => $typed_rows ) {
			$ids[ $type ] = array_map(fn( $row ) => (string) ( is_array($row) ? ( $row['row_id'] ?? '' ) : '' ), (array) $typed_rows);
			sort($ids[ $type ]);
		}
		ksort($ids);
		return $ids;
	}

	/**
	 * Build a stable ID for one cleanup row.
	 *
	 * @param  string              $type Row type.
	 * @param  array<string,mixed> $row  Row payload.
	 * @return string
	 */
	private function stable_cleanup_row_id( string $type, array $row ): string {
		$fingerprint = array(
			'type'      => $type,
			'handle'    => (string) ( $row['handle'] ?? '' ),
			'repo'      => (string) ( $row['repo'] ?? '' ),
			'branch'    => (string) ( $row['branch'] ?? '' ),
			'path'      => (string) ( $row['path'] ?? '' ),
			'artifacts' => array(),
			'fields'    => array_values( (array) ( $row['missing_fields'] ?? array() )),
			'reason'    => (string) ( $row['reason_code'] ?? '' ),
		);
		foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
			if ( is_array($artifact) ) {
				$fingerprint['artifacts'][] = (string) ( $artifact['path'] ?? '' );
			}
		}
		sort($fingerprint['artifacts']);
		sort($fingerprint['fields']);

		return $this->stable_cleanup_hash($fingerprint, 'cleanup-row');
	}

	/**
	 * Hash cleanup data after stable key sorting.
	 *
	 * @param  mixed  $value  Value to hash.
	 * @param  string $prefix Identifier prefix.
	 * @return string
	 */
	private function stable_cleanup_hash( mixed $value, string $prefix ): string {
		$this->ksort_recursive($value);
		$encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
		if ( false === $encoded || '' === $encoded ) {
			$encoded = $prefix . '-json-error-' . json_last_error_msg();
		}
		return $prefix . '-' . substr(hash('sha256', $encoded), 0, 24);
	}

	/**
	 * Sort array keys recursively for stable hashing.
	 *
	 * @param  mixed $value Value to normalize.
	 * @return void
	 */
	private function ksort_recursive( mixed &$value ): void {
		if ( ! is_array($value) ) {
			return;
		}
		foreach ( $value as &$child ) {
			$this->ksort_recursive($child);
		}
		if ( array_keys($value) !== range(0, count($value) - 1) ) {
			ksort($value);
		}
	}
}
