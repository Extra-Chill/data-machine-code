<?php
/**
 * Data Machine system-task drainability helpers.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

class SystemTaskDrainability {

	private const EXECUTE_STEP_HOOK = 'datamachine_execute_step';

	private const ACTION_GROUP = 'data-machine';

	/**
	 * Ensure a pending workflow job has the Action Scheduler action that drain can run.
	 *
	 * @param  int $job_id Data Machine job ID.
	 * @return bool True when an action already exists or was scheduled.
	 */
	public static function ensure_job_has_execute_step_action( int $job_id ): bool {
		if ( $job_id <= 0 || self::job_has_execute_step_action($job_id) || ! function_exists('as_schedule_single_action') ) {
			return $job_id > 0 && self::job_has_execute_step_action($job_id);
		}

		$flow_step_id = self::first_step_id($job_id);
		if ( '' === $flow_step_id ) {
			return false;
		}

		$action_id = as_schedule_single_action(
			time(),
			self::EXECUTE_STEP_HOOK,
			array(
				'job_id'       => $job_id,
				'flow_step_id' => $flow_step_id,
			),
			self::ACTION_GROUP
		);

		if ( false === $action_id ) {
			return false;
		}

		if ( function_exists('datamachine_merge_engine_data') ) {
			datamachine_merge_engine_data(
				$job_id,
				array(
					'drainability_repair' => array(
						'action_id'    => (int) $action_id,
						'hook'         => self::EXECUTE_STEP_HOOK,
						'flow_step_id' => $flow_step_id,
						'repaired_at'  => gmdate('c'),
					),
				)
			);
		}

		return true;
	}

	/**
	 * Ensure every supplied job has drainable work and return repair stats.
	 *
	 * @param  array<int,int|string> $job_ids Data Machine job IDs.
	 * @return array{checked:int,repaired:int,unrepairable:array<int,int>}
	 */
	public static function ensure_jobs_have_execute_step_actions( array $job_ids ): array {
		$checked      = 0;
		$repaired     = 0;
		$unrepairable = array();

		foreach ( array_values(array_unique(array_map('intval', $job_ids))) as $job_id ) {
			if ( $job_id <= 0 || ! self::is_pending_job($job_id) ) {
				continue;
			}

			++$checked;
			$had_action = self::job_has_execute_step_action($job_id);
			if ( self::ensure_job_has_execute_step_action($job_id) ) {
				if ( ! $had_action ) {
					++$repaired;
				}
				continue;
			}

			$unrepairable[] = $job_id;
		}

		return array(
			'checked'      => $checked,
			'repaired'     => $repaired,
			'unrepairable' => $unrepairable,
		);
	}

	/**
	 * Return pending jobs from a list that lack a drainable execute-step action.
	 *
	 * @param  array<int,int|string> $job_ids Data Machine job IDs.
	 * @return array<int,int>
	 */
	public static function pending_jobs_missing_execute_step_actions( array $job_ids ): array {
		$missing = array();
		foreach ( array_values(array_unique(array_map('intval', $job_ids))) as $job_id ) {
			if ( $job_id > 0 && self::is_pending_job($job_id) && ! self::job_has_execute_step_action($job_id) ) {
				$missing[] = $job_id;
			}
		}

		return $missing;
	}

	/**
	 * Determine whether a job has a pending execute-step action scoped to it.
	 *
	 * @param  int $job_id Data Machine job ID.
	 * @return bool
	 */
	public static function job_has_execute_step_action( int $job_id ): bool {
		if ( $job_id <= 0 || ! self::actions_table_available() ) {
			return false;
		}

		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Operational status check for Action Scheduler work owned by Data Machine.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE a.hook = %s
				AND a.status = 'pending'
				AND g.slug = %s
				AND (a.args LIKE %s OR a.args LIKE %s)",
				$actions_table,
				$groups_table,
				self::EXECUTE_STEP_HOOK,
				self::ACTION_GROUP,
				'%"job_id":' . $job_id . ',%',
				'%"job_id":' . $job_id . '}%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $count > 0;
	}

	private static function is_pending_job( int $job_id ): bool {
		$job = self::get_job($job_id);
		return 'pending' === (string) ( $job['status'] ?? '' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_job( int $job_id ): array {
		$ability = function_exists('wp_get_ability') ? wp_get_ability('datamachine/get-jobs') : null;
		if ( ! $ability ) {
			return array();
		}

		$result = $ability->execute(array( 'job_id' => $job_id ));
		if ( ! ( $result['success'] ?? false ) || ! is_array($result['jobs'] ?? null) ) {
			return array();
		}

		$job = $result['jobs'][0] ?? array();
		return is_array($job) ? $job : array();
	}

	private static function first_step_id( int $job_id ): string {
		if ( ! function_exists('datamachine_get_engine_data') ) {
			return '';
		}

		$engine_data = datamachine_get_engine_data($job_id);
		$flow_config = is_array($engine_data['flow_config'] ?? null) ? $engine_data['flow_config'] : array();
		if ( array() === $flow_config ) {
			return '';
		}

		if ( class_exists('\DataMachine\Engine\ExecutionPlan') ) {
			try {
				return (string) \DataMachine\Engine\ExecutionPlan::from_flow_config($flow_config)->first_step_id();
			} catch ( \InvalidArgumentException ) {
				return '';
			}
		}

		$keys = array_keys($flow_config);
		return (string) ( $keys[0] ?? '' );
	}

	private static function actions_table_available(): bool {
		global $wpdb;
		return isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare');
	}
}
