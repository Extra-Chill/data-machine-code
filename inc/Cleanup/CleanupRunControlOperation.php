<?php
/**
 * Job-backed cleanup run drain and control orchestration.
 *
 * @package DataMachineCode\Cleanup
 */

namespace DataMachineCode\Cleanup;

use DataMachineCode\Support\SystemTaskDrainability;

defined('ABSPATH') || exit;

final class CleanupRunControlOperation {

	private \Closure $command_runner;
	private \Closure $ability_resolver;

	public function __construct(
		private CleanupRunEvidenceStoreInterface $evidence_store,
		callable $command_runner,
		callable $ability_resolver
	) {
		$this->command_runner   = \Closure::fromCallable($command_runner);
		$this->ability_resolver = \Closure::fromCallable($ability_resolver);
	}

	/** Drain a queued parent and its active child jobs, then return terminal evidence. */
	public function drain( array $result, bool $verbose = false ): array {
		$job_id = (int) ( $result['job_id'] ?? 0 );
		$run_id = (string) ( $result['run_id'] ?? ( $job_id > 0 ? self::run_id($job_id) : '' ) );
		if ( $job_id <= 0 || '' === $run_id ) {
			$result['drain'] = array(
				'success' => false,
				'error'   => 'Cleanup run did not return a job id to drain.',
			);
			return $result;
		}

		$commands             = array();
		$errors               = array();
		$drainability_repairs = array();
		$repaired_child_ids   = array();
		$parent_command       = sprintf('datamachine drain --job-id=%d', $job_id);
		$commands[]           = 'studio wp ' . $parent_command;
		$error                = (string) ( $this->command_runner )($parent_command);
		if ( '' !== $error ) {
			$errors[] = $error;
		}

		for ( $pass = 0; $pass < 10; ++$pass ) {
			$status = $this->evidence_store->read($run_id, true, true);
			if ( $status instanceof \WP_Error ) {
				$errors[] = $status->get_error_message();
				break;
			}

			$children              = (array) ( $status['evidence']['children'] ?? array() );
			$undrainable_child_ids = self::job_ids((array) ( $children['pending_without_drainable_action_job_ids'] ?? array() ));
			if ( array() !== $undrainable_child_ids ) {
				$repair                  = SystemTaskDrainability::ensure_jobs_have_execute_step_actions($undrainable_child_ids);
				$pass_repaired_child_ids = array_values(array_diff($undrainable_child_ids, (array) $repair['unrepairable']));
				$repaired_child_ids      = array_values(array_unique(array_merge($repaired_child_ids, $pass_repaired_child_ids)));
				$drainability_repairs[]  = array(
					'pass'                       => $pass + 1,
					'detected_child_job_ids'     => $undrainable_child_ids,
					'repaired_child_job_ids'     => $pass_repaired_child_ids,
					'unrepairable_child_job_ids' => (array) $repair['unrepairable'],
				);
			}

			$active_child_ids = self::job_ids(array_merge(
				(array) ( $children['pending_job_ids'] ?? array() ),
				(array) ( $children['processing_job_ids'] ?? array() )
			));
			if ( array() === $active_child_ids ) {
				break;
			}

			$child_command = sprintf('datamachine drain --job-id=%s', implode(',', $active_child_ids));
			$commands[]    = 'studio wp ' . $child_command;
			$error         = (string) ( $this->command_runner )($child_command);
			if ( '' !== $error ) {
				$errors[] = $error;
				break;
			}
		}

		$final                 = $this->evidence_store->read($run_id, false, $verbose);
		$output                = $final instanceof \WP_Error ? $result : $final;
		$output['initial_run'] = $result;
		$output['drain']       = array(
			'success'                => array() === $errors,
			'commands'               => $commands,
			'errors'                 => $errors,
			'verify_command'         => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
			'bytes_reclaimed'        => (int) ( $output['cleanup_items']['bytes_reclaimed'] ?? 0 ),
			'freed_human'            => (string) ( $output['cleanup_items']['freed_human'] ?? '0 B' ),
			'completion_state'       => (string) ( $output['state'] ?? 'unknown' ),
			'drainability_repairs'   => $drainability_repairs,
			'repaired_child_job_ids' => $repaired_child_ids,
		);

		return $output;
	}

	/** Resume or cancel the parent and active child jobs for a cleanup run. */
	public function control( string $operation, int $job_id, bool $force = false ): array|\WP_Error {
		if ( ! in_array($operation, array( 'resume', 'cancel' ), true) || $job_id <= 0 ) {
			return new \WP_Error('invalid_cleanup_run_control', 'A supported cleanup control operation and positive job id are required.', array( 'status' => 400 ));
		}

		$ability_name = 'resume' === $operation ? 'datamachine-code/retry-job' : 'datamachine-code/fail-job';
		$ability      = ( $this->ability_resolver )($ability_name);
		if ( ! is_object($ability) || ! method_exists($ability, 'execute') ) {
			return new \WP_Error('cleanup_job_control_ability_missing', sprintf('Job control ability not registered: %s', $ability_name), array( 'status' => 500 ));
		}

		$target_job_ids = $this->control_job_ids($operation, $job_id);
		$results        = array();
		foreach ( $target_job_ids as $target_job_id ) {
			$input = array( 'job_id' => $target_job_id );
			if ( 'resume' === $operation ) {
				$input['force'] = $force;
			} else {
				$input['reason'] = 'cleanup_cancelled';
			}

			$result = $ability->execute($input);
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			if ( ! is_array($result) || ! ( $result['success'] ?? false ) ) {
				$message = is_array($result) ? (string) ( $result['error'] ?? 'Cleanup run control failed.' ) : 'Cleanup run control returned an invalid result.';
				return new \WP_Error('cleanup_run_control_failed', $message, array( 'status' => 500 ));
			}
			$results[] = $result;
		}

		$output                       = $results[0] ?? array( 'success' => true, 'job_id' => $job_id );
		$output['run_id']             = self::run_id($job_id);
		$output['state']              = 'resume' === $operation ? 'running' : 'cancelled';
		$output['controlled_job_ids'] = $target_job_ids;
		$output['results']            = $results;
		return $output;
	}

	/** Resolve which Data Machine jobs should be controlled for the cleanup run. */
	private function control_job_ids( string $operation, int $job_id ): array {
		$output = $this->evidence_store->read(self::run_id($job_id), true, true);
		if ( $output instanceof \WP_Error ) {
			return array( $job_id );
		}

		$children        = (array) ( $output['evidence']['children'] ?? array() );
		$processing_ids  = self::job_ids((array) ( $children['processing_job_ids'] ?? array() ));
		$failed_ids      = self::job_ids((array) ( $children['failed_job_ids'] ?? array() ));
		$pending_ids     = self::job_ids((array) ( $children['pending_job_ids'] ?? array() ));
		$undrainable_ids = self::job_ids((array) ( $children['pending_without_drainable_action_job_ids'] ?? array() ));

		if ( 'resume' === $operation ) {
			$repair        = SystemTaskDrainability::ensure_jobs_have_execute_step_actions($undrainable_ids);
			$child_targets = self::job_ids(array_merge($processing_ids, $failed_ids));
			if ( array() === $child_targets && (int) $repair['repaired'] > 0 ) {
				return array();
			}
			return array() !== $child_targets ? $child_targets : array( $job_id );
		}

		return self::job_ids(array_merge(array( $job_id ), $pending_ids, $processing_ids));
	}

	/** @return array<int,int> */
	private static function job_ids( array $job_ids ): array {
		return array_values(array_unique(array_filter(array_map('intval', $job_ids))));
	}

	private static function run_id( int $job_id ): string {
		return 'cleanup-run-' . $job_id;
	}
}
