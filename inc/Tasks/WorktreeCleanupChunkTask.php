<?php
/**
 * Worktree cleanup chunk system task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

class WorktreeCleanupChunkTask extends SystemTask {



	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'worktree_cleanup_chunk';
	}

	/**
	 * Pure workspace/disk maintenance — runs without agent ownership context.
	 *
	 * This task applies one reviewed cleanup chunk (artifact deletion or worktree
	 * removal) via the Workspace service. It is fanned out by the recurring
	 * workspace-maintenance parent tasks, which forward agent_id from their own
	 * params (0 under an agent-less recurring schedule). Without opting out, every
	 * child chunk scheduled by an agent-less parent would be rejected by the
	 * SystemTask agent-context gate in TaskScheduler::schedule(), so the actual
	 * destructive cleanup would never run even after the parents are fixed.
	 *
	 * @return bool
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	/**
	 * Task metadata for Data Machine job surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Worktree Cleanup Chunk',
			'description'     => 'Applies one reviewed cleanup chunk for artifacts or worktree removal.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => false,
		);
	}

	/**
	 * Execute one cleanup chunk.
	 *
	 * @param  int   $jobId  Job ID.
	 * @param  array $params Task params.
	 * @return void
	 */
	public function executeTask( int $jobId, array $params ): void {
		$started_at = microtime(true);
		$chunk_type = (string) ( $params['chunk_type'] ?? '' );
		$rows       = is_array($params['rows'] ?? null) ? array_values( (array) $params['rows']) : array();

		if ( 'artifact_discovery' === $chunk_type ) {
			$this->execute_artifact_discovery_chunk($jobId, $params, $started_at);
			return;
		}

		if ( 'metadata_reconciliation_page' === $chunk_type ) {
			$this->execute_metadata_reconciliation_page($jobId, $params, $started_at);
			return;
		}

		if ( '' === $chunk_type || array() === $rows ) {
			$this->completeJob(
				$jobId,
				$this->build_chunk_result(
					$chunk_type,
					$rows,
					array(),
					array(),
					$this->rows_to_failed($rows, 'invalid_chunk', 'chunk_type and rows are required'),
					0,
					$started_at,
					array( 'error' => 'chunk_type and rows are required' )
				)
			);
			return;
		}

		$workspace = new Workspace();
		$result    = match ( $chunk_type ) {
			'artifacts' => $workspace->worktree_cleanup_artifacts(
				array(
					'apply_plan' => array( 'candidates' => $rows ),
					'force'      => ! empty($params['force']),
					'limit'      => count($rows),
				)
			),
			'worktrees' => $workspace->worktree_cleanup_merged(
				array(
					'apply_plan'                => array( 'candidates' => $rows ),
					'direct_apply_plan'         => true,
					'force'                     => ! empty($params['force']),
					'discard_unpushed'          => ! empty($params['discard_unpushed']),
					'skip_github'               => array_key_exists('skip_github', $params) ? (bool) $params['skip_github'] : true,
					'include_repaired_metadata' => ! empty($params['include_repaired_metadata']),
					'stale_liveness_only'       => ! empty($params['stale_liveness_only']),
					'remove_timeout'            => isset($params['remove_timeout']) ? (int) $params['remove_timeout'] : null,
				)
			),
			default     => new \WP_Error('invalid_cleanup_chunk_type', sprintf('Unknown cleanup chunk type: %s', $chunk_type), array( 'status' => 400 )),
		};

		if ( $result instanceof \WP_Error ) {
			$failed = $this->rows_to_failed($rows, (string) $result->get_error_code(), $result->get_error_message());
			$this->completeJob(
				$jobId,
				$this->build_chunk_result(
					$chunk_type,
					$rows,
					array(),
					array(),
					$failed,
					0,
					$started_at,
					array(
						'error_code' => $result->get_error_code(),
						'error'      => $result->get_error_message(),
					)
				)
			);
			return;
		}

		$applied         = $this->extract_applied_rows($chunk_type, $result);
		$skipped         = array_values( (array) ( $result['skipped'] ?? array() ));
		$bytes_reclaimed = $this->bytes_reclaimed($chunk_type, $applied, $result);

		$this->completeJob(
			$jobId,
			$this->build_chunk_result(
				$chunk_type,
				$rows,
				$applied,
				$skipped,
				array(),
				$bytes_reclaimed,
				$started_at,
				array(
					'summary' => $result['summary'] ?? array(),
					'result'  => $this->compact_evidence_result($result),
				)
			)
		);
	}

	/**
	 * Discover and apply one bounded artifact cleanup page.
	 *
	 * @param  int   $jobId      Job ID.
	 * @param  array $params     Task params.
	 * @param  float $started_at Start timestamp.
	 * @return void
	 */
	private function execute_artifact_discovery_chunk( int $jobId, array $params, float $started_at ): void {
		$limit     = max(1, (int) ( $params['limit'] ?? 10 ));
		$offset    = max(0, (int) ( $params['offset'] ?? 0 ));
		$force     = ! empty($params['force']);
		$workspace = new Workspace();

		$plan = $workspace->worktree_cleanup_artifacts(
			array(
				'dry_run'       => true,
				'force'         => $force,
				'limit'         => $limit,
				'offset'        => $offset,
				'safety_probes' => true,
			)
		);

		if ( $plan instanceof \WP_Error ) {
			$this->completeJob(
				$jobId,
				$this->build_chunk_result(
					'artifact_discovery',
					array(),
					array(),
					array(),
					array(
						array(
							'handle'      => '',
							'reason_code' => $plan->get_error_code(),
							'reason'      => $plan->get_error_message(),
						),
					),
					0,
					$started_at,
					array(
						'offset' => $offset,
						'limit'  => $limit,
					)
				)
			);
			return;
		}

		$planned = array_values( (array) ( $plan['candidates'] ?? array() ));
		$skipped = array_values( (array) ( $plan['skipped'] ?? array() ));
		if ( array() === $planned ) {
			$this->completeJob(
				$jobId,
				$this->build_chunk_result(
					'artifact_discovery',
					array(),
					array(),
					$skipped,
					array(),
					0,
					$started_at,
					array(
						'pagination' => $plan['pagination'] ?? null,
						'summary'    => $plan['summary'] ?? array(),
					)
				)
			);
			return;
		}

		$result = $workspace->worktree_cleanup_artifacts(
			array(
				'apply_plan' => array( 'candidates' => $planned ),
				'force'      => $force,
				'limit'      => count($planned),
			)
		);

		if ( $result instanceof \WP_Error ) {
			$this->completeJob(
				$jobId,
				$this->build_chunk_result(
					'artifact_discovery',
					$planned,
					array(),
					$skipped,
					$this->rows_to_failed($planned, (string) $result->get_error_code(), $result->get_error_message()),
					0,
					$started_at,
					array(
						'pagination' => $plan['pagination'] ?? null,
						'summary'    => $plan['summary'] ?? array(),
					)
				)
			);
			return;
		}

		$applied = $this->extract_applied_rows('artifacts', $result);
		$skipped = array_merge($skipped, array_values( (array) ( $result['skipped'] ?? array() )));

		$this->completeJob(
			$jobId,
			$this->build_chunk_result(
				'artifact_discovery',
				$planned,
				$applied,
				$skipped,
				array(),
				$this->bytes_reclaimed('artifacts', $applied, $result),
				$started_at,
				array(
					'pagination' => $plan['pagination'] ?? null,
					'summary'    => $result['summary'] ?? array(),
					'result'     => $this->compact_evidence_result($result),
				)
			)
		);
	}

	/**
	 * Apply one bounded metadata reconciliation page.
	 *
	 * @param  int   $jobId      Job ID.
	 * @param  array $params     Task params.
	 * @param  float $started_at Start timestamp.
	 * @return void
	 */
	private function execute_metadata_reconciliation_page( int $jobId, array $params, float $started_at ): void {
		$limit     = max(1, (int) ( $params['limit'] ?? 50 ));
		$offset    = max(0, (int) ( $params['offset'] ?? 0 ));
		$workspace = new Workspace();

		$result = $workspace->worktree_reconcile_metadata(
			array(
				'apply'  => true,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);

		if ( $result instanceof \WP_Error ) {
			$this->completeJob(
				$jobId,
				$this->build_chunk_result(
					'metadata_reconciliation_page',
					array(),
					array(),
					array(),
					array(
						array(
							'handle'      => '',
							'reason_code' => $result->get_error_code(),
							'reason'      => $result->get_error_message(),
						),
					),
					0,
					$started_at,
					array(
						'limit'  => $limit,
						'offset' => $offset,
					)
				)
			);
			return;
		}

		$this->completeJob(
			$jobId,
			$this->build_chunk_result(
				'metadata_reconciliation_page',
				array_values( (array) ( $result['proposals'] ?? array() )),
				array_values( (array) ( $result['written'] ?? array() )),
				array_values( (array) ( $result['skipped'] ?? array() )),
				array(),
				0,
				$started_at,
				array(
					'pagination' => $result['pagination'] ?? null,
					'summary'    => $result['summary'] ?? array(),
					'result'     => $this->compact_evidence_result($result),
				)
			)
		);
	}

	/**
	 * Build the persisted chunk result.
	 *
	 * @param  string $chunk_type      Cleanup chunk type.
	 * @param  array  $planned         Planned rows.
	 * @param  array  $applied         Applied rows.
	 * @param  array  $skipped         Skipped rows.
	 * @param  array  $failed          Failed rows.
	 * @param  int    $bytes_reclaimed Bytes reclaimed.
	 * @param  float  $started_at      Start timestamp.
	 * @param  array  $evidence        Evidence payload.
	 * @return array<string,mixed>
	 */
	private function build_chunk_result( string $chunk_type, array $planned, array $applied, array $skipped, array $failed, int $bytes_reclaimed, float $started_at, array $evidence ): array {
		$elapsed_ms = (int) round(( microtime(true) - $started_at ) * 1000);

		return array(
			'success'         => array() === $failed,
			'chunk_type'      => $chunk_type,
			'planned_count'   => count($planned),
			'applied_count'   => count($applied),
			'skipped_count'   => count($skipped),
			'failed_count'    => count($failed),
			'bytes_reclaimed' => $bytes_reclaimed,
			'elapsed_ms'      => $elapsed_ms,
			'applied'         => $applied,
			'skipped'         => $skipped,
			'failed'          => $failed,
			'evidence'        => array_merge(
				array(
					'planned_handles' => $this->row_handles($planned),
					'applied_handles' => $this->row_handles($applied),
					'skipped_handles' => $this->row_handles($skipped),
					'failed_handles'  => $this->row_handles($failed),
				),
				$evidence
			),
		);
	}

	/**
	 * Extract applied rows from a workspace result.
	 *
	 * @param  string $chunk_type Cleanup chunk type.
	 * @param  array  $result     Workspace result.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_applied_rows( string $chunk_type, array $result ): array {
		return match ( $chunk_type ) {
			'worktrees' => array_values( (array) ( $result['removed'] ?? array() )),
			default     => array_values( (array) ( $result['removed'] ?? array() )),
		};
	}

	/**
	 * Calculate reclaimed bytes for applied rows.
	 *
	 * @param  string $chunk_type Cleanup chunk type.
	 * @param  array  $applied    Applied rows.
	 * @param  array  $result     Workspace result.
	 * @return int
	 */
	private function bytes_reclaimed( string $chunk_type, array $applied, array $result ): int {
		if ( 'artifacts' === $chunk_type ) {
			return max(0, (int) ( $result['summary']['removed_size_bytes'] ?? 0 ));
		}

		if ( 'worktrees' !== $chunk_type ) {
			return 0;
		}

		$total = 0;
		foreach ( $applied as $row ) {
			$total += max(0, (int) ( is_array($row) ? ( $row['size_bytes'] ?? 0 ) : 0 ));
		}

		return $total;
	}

	/**
	 * Convert rows to failed row payloads.
	 *
	 * @param  array  $rows   Rows.
	 * @param  string $code   Error code.
	 * @param  string $reason Error reason.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows_to_failed( array $rows, string $code, string $reason ): array {
		$failed = array();
		foreach ( $rows as $row ) {
			$failed[] = array(
				'handle'      => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '',
				'reason_code' => $code,
				'reason'      => $reason,
				'row'         => $row,
			);
		}

		return $failed;
	}

	/**
	 * Return stable row handles for summaries.
	 *
	 * @param  array $rows Rows.
	 * @return array<int,string>
	 */
	private function row_handles( array $rows ): array {
		$handles = array();
		foreach ( $rows as $row ) {
			if ( is_array($row) && '' !== (string) ( $row['handle'] ?? '' ) ) {
				$handles[] = (string) $row['handle'];
			}
		}

		return array_values(array_unique($handles));
	}

	/**
	 * Keep evidence bounded while retaining operator-relevant summaries.
	 *
	 * @param  array $result Workspace result.
	 * @return array<string,mixed>
	 */
	private function compact_evidence_result( array $result ): array {
		return array(
			'success'      => (bool) ( $result['success'] ?? true ),
			'dry_run'      => (bool) ( $result['dry_run'] ?? false ),
			'generated_at' => $result['generated_at'] ?? null,
		);
	}
}
