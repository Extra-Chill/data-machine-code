<?php
/**
 * Worktree Cleanup — Recurring Action Scheduler schedule.
 *
 * Registers a recurring Action Scheduler action that enqueues the
 * `worktree_cleanup` Data Machine system task once per day. When fired,
 * the AS action creates a DM job via `TaskScheduler::schedule()` — the
 * same path `wp datamachine system run worktree_cleanup` uses — which
 * means every automatic run flows through the normal DM job runner,
 * respects `SystemTask` semantics, and emits the usual audit logs.
 *
 * Disabled by default. Opt in with:
 *
 *     add_filter( 'datamachine_code_worktree_cleanup_schedule_enabled',
 *                 '__return_true' );
 *
 * The filter is re-evaluated on every `action_scheduler_init`, so
 * toggling it off also unschedules the recurring action — no stale
 * timers, no manual cleanup needed.
 *
 * Disabled AS action is registered unconditionally so if the filter is
 * toggled on later, the hook handler is already wired up.
 *
 * @package DataMachineCode\ActionScheduler
 * @since   0.8.0
 * @see     https://github.com/Extra-Chill/data-machine-code/issues/33
 */

defined( 'ABSPATH' ) || exit;

/**
 * AS hook handler — enqueue a DM `worktree_cleanup` job.
 *
 * Each recurring tick creates a single DM job that the standard job
 * runner executes. Keeps the recurring layer thin: this handler owns
 * only the "when", the SystemTask owns the "what".
 *
 * Defensive: re-checks the opt-in filter at fire time. If the filter
 * flipped off between scheduling and firing, short-circuit silently
 * rather than racing the unschedule path.
 */
add_action(
	'datamachine_code_recurring_worktree_cleanup',
	static function (): void {
		$enabled = (bool) apply_filters( 'datamachine_code_worktree_cleanup_schedule_enabled', false );
		if ( ! $enabled ) {
			return;
		}

		if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskScheduler' ) ) {
			// DM core not available for some reason — log and bail. AS will
			// retry on its own cadence; no need to fail hard.
			do_action(
				'datamachine_log',
				'warning',
				'Worktree cleanup recurring tick skipped: DataMachine\Engine\Tasks\TaskScheduler not available.'
			);
			return;
		}

		$job_id = \DataMachine\Engine\Tasks\TaskScheduler::schedule(
			'worktree_cleanup',
			array(
				'source' => 'recurring_schedule',
			)
		);

		do_action(
			'datamachine_log',
			$job_id ? 'info' : 'warning',
			$job_id
				? sprintf( 'Worktree cleanup recurring tick scheduled DM job #%d.', (int) $job_id )
				: 'Worktree cleanup recurring tick failed to schedule a DM job.',
			array(
				'task' => 'worktree_cleanup',
				'hook' => 'datamachine_code_recurring_worktree_cleanup',
			)
		);
	}
);

/**
 * Register / unregister the recurring action once AS is ready.
 *
 * Runs at `action_scheduler_init` (which fires after AS has its tables
 * set up) and only in admin context to avoid hitting the DB on every
 * frontend request. Admin-only matches the pattern DM core uses for its
 * own recurring cleanup actions (see `JobsCleanup`, `LogCleanup`, etc.).
 *
 * Idempotent in both directions:
 *   - Enabled + not scheduled → schedule it.
 *   - Disabled + scheduled   → unschedule it.
 *   - Already in desired state → no-op.
 */
add_action(
	'action_scheduler_init',
	static function (): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		$hook    = 'datamachine_code_recurring_worktree_cleanup';
		$group   = 'data-machine-code';
		$enabled = (bool) apply_filters( 'datamachine_code_worktree_cleanup_schedule_enabled', false );

		$is_scheduled = (bool) as_next_scheduled_action( $hook, array(), $group );

		if ( $enabled && ! $is_scheduled ) {
			/**
			 * Filter the recurring interval for worktree cleanup.
			 *
			 * @since 0.8.0
			 *
			 * @param int $interval Interval in seconds. Default DAY_IN_SECONDS.
			 */
			$interval = (int) apply_filters( 'datamachine_code_worktree_cleanup_schedule_interval', DAY_IN_SECONDS );
			$interval = max( HOUR_IN_SECONDS, $interval );

			as_schedule_recurring_action(
				time() + $interval,
				$interval,
				$hook,
				array(),
				$group
			);
			return;
		}

		if ( ! $enabled && $is_scheduled ) {
			as_unschedule_all_actions( $hook, array(), $group );
		}
	}
);
