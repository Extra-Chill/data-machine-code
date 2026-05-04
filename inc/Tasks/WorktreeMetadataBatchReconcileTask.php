<?php
/**
 * Worktree Metadata Batch Reconcile System Task.
 *
 * Resumable, bounded reconciliation of legacy missing-metadata worktrees.
 * Persists progress through Data Machine jobs by re-scheduling itself with the
 * `next_cursor` returned by {@see Workspace::worktree_reconcile_metadata_batch()}
 * until the candidate list is exhausted (or the per-run chunk count is reached).
 *
 * Disabled by default. Operators opt in via the `worktree_metadata_batch_reconcile_enabled`
 * PluginSettings key, or invoke the underlying ability/CLI directly.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeMetadataBatchReconcileTask extends SystemTask {

	/**
	 * PluginSettings key that gates recurring/manual task execution.
	 */
	public const SETTING_KEY = 'worktree_metadata_batch_reconcile_enabled';

	/**
	 * Default per-batch limit when none is supplied.
	 */
	public const DEFAULT_BATCH_LIMIT = 25;

	/**
	 * Hard cap on chained batches per task invocation.
	 */
	public const MAX_CHAINED_BATCHES = 1;

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'worktree_metadata_batch_reconcile';
	}

	/**
	 * Task metadata for Data Machine system-task surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Worktree Metadata Batch Reconcile',
			'description'     => 'Bounded, resumable lifecycle metadata backfill for legacy missing-metadata worktrees. Each run processes one batch and re-schedules itself with the next cursor until the candidate list is exhausted.',
			'setting_key'     => self::SETTING_KEY,
			'default_enabled' => false,
			'supports_run'    => true,
		);
	}

	/**
	 * Execute one bounded reconciliation batch.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params Task params: limit, cursor, offset, dry_run, source.
	 * @return void
	 */
	public function executeTask( int $jobId, array $params ): void {
		$source  = (string) ( $params['source'] ?? '' );
		$enabled = (bool) PluginSettings::get( self::SETTING_KEY, false )
			|| 'workspace_metadata_cli' === $source
			|| 'workspace_metadata_chain' === $source;
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf( 'Worktree metadata batch reconcile disabled (PluginSettings: %s=false).', self::SETTING_KEY ),
				)
			);
			return;
		}

		$limit = isset( $params['limit'] ) ? (int) $params['limit'] : self::DEFAULT_BATCH_LIMIT;
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_BATCH_LIMIT;
		}

		$opts = array(
			'dry_run' => ! empty( $params['dry_run'] ),
			'limit'   => $limit,
		);
		if ( isset( $params['cursor'] ) && '' !== trim( (string) $params['cursor'] ) ) {
			$opts['cursor'] = trim( (string) $params['cursor'] );
		}
		if ( isset( $params['offset'] ) && '' === trim( (string) ( $params['cursor'] ?? '' ) ) ) {
			$opts['offset'] = (int) $params['offset'];
		}

		$workspace = new Workspace();
		$result    = $workspace->worktree_reconcile_metadata_batch( $opts );

		if ( $result instanceof \WP_Error ) {
			do_action(
				'datamachine_log',
				'error',
				'Worktree metadata batch reconcile failed',
				array(
					'task'  => $this->getTaskType(),
					'jobId' => $jobId,
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				)
			);
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$next_cursor = $result['next_cursor'] ?? null;
		$exhausted   = ! empty( $result['exhausted'] );
		$rescheduled = false;

		if ( ! $exhausted && null !== $next_cursor && '' !== (string) $next_cursor && empty( $params['no_chain'] ) ) {
			$chain_depth = (int) ( $params['chain_depth'] ?? 0 );
			if ( $chain_depth < self::MAX_CHAINED_BATCHES && class_exists( '\\DataMachine\\Engine\\Tasks\\TaskScheduler' ) ) {
				try {
					$scheduler = new TaskScheduler();
					$scheduler->schedule(
						$this->getTaskType(),
						array(
							'source'      => 'workspace_metadata_chain',
							'limit'       => $limit,
							'cursor'      => (string) $next_cursor,
							'dry_run'     => ! empty( $params['dry_run'] ),
							'chain_depth' => $chain_depth + 1,
						)
					);
					$rescheduled = true;
				} catch ( \Throwable $e ) {
					do_action(
						'datamachine_log',
						'warning',
						'Failed to chain next worktree metadata reconcile batch',
						array(
							'task'  => $this->getTaskType(),
							'jobId' => $jobId,
							'error' => $e->getMessage(),
						)
					);
				}
			}
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Worktree metadata batch reconcile: processed=%d remaining=%d candidate_total=%d exhausted=%s rescheduled=%s',
				(int) ( $result['processed'] ?? 0 ),
				(int) ( $result['remaining'] ?? 0 ),
				(int) ( $result['candidate_total'] ?? 0 ),
				$exhausted ? 'yes' : 'no',
				$rescheduled ? 'yes' : 'no'
			),
			array(
				'task'    => $this->getTaskType(),
				'jobId'   => $jobId,
				'summary' => $result['summary'] ?? array(),
			)
		);

		$this->completeJob(
			$jobId,
			array(
				'success'         => true,
				'mode'            => $result['mode'] ?? 'batch',
				'dry_run'         => (bool) ( $result['dry_run'] ?? false ),
				'applied'         => (bool) ( $result['applied'] ?? false ),
				'candidate_total' => (int) ( $result['candidate_total'] ?? 0 ),
				'processed'       => (int) ( $result['processed'] ?? 0 ),
				'remaining'       => (int) ( $result['remaining'] ?? 0 ),
				'exhausted'       => $exhausted,
				'next_cursor'     => $next_cursor,
				'rescheduled'     => $rescheduled,
				'summary'         => $result['summary'] ?? array(),
				'last_handle'     => $result['last_handle'] ?? null,
			)
		);
	}
}
