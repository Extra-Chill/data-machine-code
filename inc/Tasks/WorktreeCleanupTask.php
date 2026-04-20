<?php
/**
 * Worktree Cleanup System Task.
 *
 * Wraps `Workspace::worktree_cleanup_merged()` as a Data Machine system task
 * so merged-branch cleanup can be scheduled and run through the standard DM
 * orchestration surface:
 *
 *   wp datamachine system run worktree_cleanup
 *   wp datamachine system health --types=worktree_cleanup
 *
 * The task is disabled by default — cleanup is destructive (removes a
 * worktree directory and deletes the local branch) and agents should opt
 * in explicitly. Once enabled, it respects the usual safety rails:
 *
 *   - Protected branches (main, master, trunk, develop, HEAD) are never touched.
 *   - Worktrees outside the workspace path are skipped.
 *   - Dirty worktrees are skipped unless `force` is passed.
 *   - Worktrees with unpushed commits are skipped regardless of `force`
 *     (the unpushed-commits check is a hard stop — data loss trumps
 *     convenience).
 *   - Only branches with a clear merge signal (upstream branch deleted on
 *     origin, or GitHub PR closed+merged) are candidates.
 *
 * Auto-scheduling via Action Scheduler is intentionally out of scope for
 * this task class — it just handles the execution contract. See the
 * follow-up issue for opt-in daily/weekly scheduling.
 *
 * @package DataMachineCode\Tasks
 * @since   0.7.0
 * @see     https://github.com/Extra-Chill/data-machine-code/issues/33
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

/**
 * PluginSettings key that gates both manual and scheduled runs.
 *
 * Declared on the class so the task, the schedule file, and any external
 * admin tooling all reference the same constant. The React UI surfaces
 * this key as a toggle via `TaskRegistry::getRegistry()` → the `enabled`
 * field resolves through `PluginSettings::get( $setting_key,
 * $default_enabled )`.
 */

class WorktreeCleanupTask extends SystemTask {

	/**
	 * PluginSettings key that gates both manual and scheduled runs.
	 *
	 * Toggled by the React admin UI via `TaskRegistry::getRegistry()`.
	 */
	public const SETTING_KEY = 'worktree_cleanup_enabled';

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'worktree_cleanup';
	}

	/**
	 * Task metadata for the Data Machine system surface.
	 *
	 * `setting_key` wires this task into the standard DM settings plumbing:
	 * the React admin UI renders an enable/disable toggle, the
	 * TaskRegistry resolves live state from `PluginSettings::get()`, and
	 * the recurring Action Scheduler hook in
	 * `WorktreeCleanupSchedule.php` reads the same key to schedule or
	 * unschedule its daily tick.
	 *
	 * `trigger_type => 'cron'` signals to the admin UI that this task runs
	 * on a schedule (as opposed to 'manual' / 'tool' / 'event'). The
	 * schedule itself is owned by `WorktreeCleanupSchedule.php`.
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Worktree Cleanup',
			'description'     => 'Remove worktrees whose branches have been merged and deleted upstream. Destructive: removes worktree directories and deletes local branches. Runs daily when enabled.',
			'setting_key'     => self::SETTING_KEY,
			// Opt-in only — cleanup is destructive, agents should flip this
			// on explicitly (via React UI, REST, or `wp datamachine settings
			// set worktree_cleanup_enabled true`).
			'default_enabled' => false,
			'trigger'         => 'Daily via Action Scheduler when enabled; manual via CLI/REST/MCP anytime.',
			'trigger_type'    => 'cron',
			'supports_run'    => true,
		);
	}

	/**
	 * Execute cleanup for a job.
	 *
	 * Params (all optional):
	 *   - `dry_run` (bool)      — plan without removing anything. Default: false.
	 *   - `force` (bool)        — override dirty-worktree safety. Default: false.
	 *                             Does NOT override the unpushed-commits safety.
	 *   - `skip_github` (bool)  — only use local `upstream-gone` signal. Default: false.
	 *
	 * Opt-out:
	 *   Apply the `datamachine_code_worktree_cleanup_enabled` filter with
	 *   a falsy return to disable the task entirely. A disabled task
	 *   reports `completeJob` with `skipped=true` so schedulers don't
	 *   interpret it as a failure.
	 *
	 * Audit logging: every removal, skip, and the final summary go through
	 * the standard `datamachine_log` action so the DM log surface picks
	 * them up — the same pipe used by every other system task.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		// Gate on the same PluginSetting the React UI toggles and the
		// recurring schedule reads. Defensive — scheduled runs already
		// wouldn't fire when the setting is off, but manual runs via
		// `wp datamachine system run worktree_cleanup` bypass the
		// schedule layer, so the task itself has to self-police too.
		$enabled = (bool) PluginSettings::get( self::SETTING_KEY, false );
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf( 'Worktree cleanup disabled (PluginSettings: %s=false).', self::SETTING_KEY ),
				)
			);
			return;
		}

		$opts = array(
			'dry_run'     => ! empty( $params['dry_run'] ),
			'force'       => ! empty( $params['force'] ),
			'skip_github' => ! empty( $params['skip_github'] ),
		);

		$workspace = new Workspace();
		$result    = $workspace->worktree_cleanup_merged( $opts );

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Worktree cleanup task failed',
				array(
					'task'   => $this->getTaskType(),
					'jobId'  => $jobId,
					'error'  => $result->get_error_message(),
					'code'   => $result->get_error_code(),
				)
			);
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$removed_count  = count( $result['removed'] ?? array() );
		$skipped_count  = count( $result['skipped'] ?? array() );
		$candidate_count = count( $result['candidates'] ?? array() );

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Worktree cleanup %s: %d candidate(s), %d removed, %d skipped.',
				$opts['dry_run'] ? 'dry-run' : 'executed',
				$candidate_count,
				$removed_count,
				$skipped_count
			),
			array(
				'task'      => $this->getTaskType(),
				'jobId'     => $jobId,
				'dry_run'   => $opts['dry_run'],
				'removed'   => $result['removed'] ?? array(),
				'skipped'   => $result['skipped'] ?? array(),
			)
		);

		$this->completeJob(
			$jobId,
			array(
				'dry_run'        => $opts['dry_run'],
				'candidates'     => $candidate_count,
				'removed_count'  => $removed_count,
				'skipped_count'  => $skipped_count,
				'removed'        => $result['removed'] ?? array(),
				'skipped'        => $result['skipped'] ?? array(),
			)
		);
	}
}
