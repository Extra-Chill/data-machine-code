<?php
/**
 * Worktree Cleanup — Recurring Action Scheduler schedule.
 *
 * Registers a recurring Action Scheduler action that enqueues the
 * `worktree_cleanup` Data Machine system task once per day. Pairs with
 * {@see \DataMachineCode\Tasks\WorktreeCleanupTask} — the task owns the
 * "what", this file owns the "when".
 *
 * Gated on the same PluginSettings key the React UI toggles
 * (`WorktreeCleanupTask::SETTING_KEY`). Flip the toggle in the admin
 * (or via REST / `wp datamachine settings set`) and the next
 * `action_scheduler_init` run — which fires on every admin pageload —
 * schedules or unschedules the recurring action accordingly. No code
 * changes, no custom filters, no parallel config paths: the UI toggle
 * is the single source of truth.
 *
 * When the AS action fires, the handler enqueues a DM job via
 * `TaskScheduler::schedule()` — the same code path manual runs via
 * `wp datamachine system run worktree_cleanup` use. Scheduled and
 * manual runs share the DM job runner, the SystemTask execution
 * contract, retry semantics, and the `datamachine_log` audit pipe.
 *
 * Mirrors the pattern in DM core's
 * `SystemAgentServiceProvider::manageDailyMemorySchedule()` — keeps the
 * dm-code extension consistent with the rest of the ecosystem rather
 * than inventing a parallel scheduler.
 *
 * @package DataMachineCode\ActionScheduler
 * @since   0.8.0
 * @see     https://github.com/Extra-Chill/data-machine-code/issues/33
 */

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachineCode\Tasks\WorktreeCleanupTask;

/**
 * AS hook handler — enqueue a DM `worktree_cleanup` job.
 *
 * Each recurring tick creates a single DM job that the standard job
 * runner executes. Keeps the recurring layer thin: this handler owns
 * only the "when", the SystemTask owns the "what".
 *
 * Defensive: re-checks the enable setting at fire time. If the user
 * disabled cleanup between scheduling and firing, short-circuit
 * silently rather than racing the unschedule path.
 */
add_action(
	'datamachine_code_recurring_worktree_cleanup',
	static function (): void {
		if ( ! class_exists( '\DataMachine\Core\PluginSettings' ) ) {
			return;
		}

		if ( ! (bool) PluginSettings::get( WorktreeCleanupTask::SETTING_KEY, false ) ) {
			return;
		}

		if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskScheduler' ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Worktree cleanup recurring tick skipped: DataMachine\Engine\Tasks\TaskScheduler not available.'
			);
			return;
		}

		$job_id = TaskScheduler::schedule(
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
 * Runs at `action_scheduler_init` (fires after AS has its tables set up)
 * and only in admin context to avoid hitting the DB on every frontend
 * request. Admin-only matches the pattern DM core uses for its own
 * recurring actions (see `JobsCleanup`, `LogCleanup`, etc.).
 *
 * Idempotent in both directions:
 *   - Enabled + not scheduled → schedule it.
 *   - Disabled + scheduled   → unschedule it.
 *   - Already in desired state → no-op.
 *
 * Admin-only means: when a user toggles the React UI off, the next
 * admin pageload runs this callback and unschedules. The user
 * doesn't have to wait until AS's normal tick to notice — any admin
 * pageview is enough.
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

		if ( ! class_exists( '\DataMachine\Core\PluginSettings' ) ) {
			return;
		}

		$hook    = 'datamachine_code_recurring_worktree_cleanup';
		$group   = 'data-machine-code';
		$enabled = (bool) PluginSettings::get( WorktreeCleanupTask::SETTING_KEY, false );

		$is_scheduled = (bool) as_next_scheduled_action( $hook, array(), $group );

		if ( $enabled && ! $is_scheduled ) {
			/**
			 * Filter the recurring interval for worktree cleanup.
			 *
			 * Developer knob, not a user setting — hence a filter rather
			 * than a PluginSetting. Most installs should never touch it;
			 * the daily default is the sensible cadence for
			 * "branch merged upstream" detection.
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
