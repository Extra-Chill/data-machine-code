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
					'dry_run'        => true,
					'skip_github'    => true,
					'inventory_only' => true,
					'older_than'     => $inputs['worktree_older_than'],
					'sort'           => $inputs['worktree_sort'],
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

		$summary         = $this->build_cleanup_plan_summary($rows);
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
				'metadata_reconciliation'  => 'workspace worktree reconcile-metadata --dry-run --format=json',
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
	private function build_cleanup_plan_summary( array $rows ): array {
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
		return array(
			'total_rows'       => $total_rows,
			'rows_by_type'     => $counts,
			'byte_totals'      => $byte_totals,
			'total_size_bytes' => $total_bytes,
		);
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
