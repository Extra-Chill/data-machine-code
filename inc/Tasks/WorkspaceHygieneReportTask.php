<?php
/**
 * Workspace Hygiene Report System Task.
 *
 * Runs the non-destructive workspace hygiene report through Data Machine's
 * recurring system-task surface. Disabled by default so installs opt into
 * notification/reporting cadence explicitly.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\Workspace;

defined('ABSPATH') || exit;

class WorkspaceHygieneReportTask extends SystemTask {



	/**
	 * PluginSettings key that gates recurring/manual task execution.
	 */
	public const SETTING_KEY = 'workspace_hygiene_report_enabled';

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'workspace_hygiene_report';
	}

	/**
	 * Pure workspace/disk maintenance — runs without agent ownership context.
	 *
	 * This task produces a non-destructive disk/worktree hygiene report via the
	 * Workspace service. It never acts as an agent or invokes an agent-scoped
	 * ability. It is registered as an agent-less weekly recurring schedule, so it
	 * must opt out of the SystemTask agent-context gate or
	 * TaskScheduler::schedule() rejects it before it runs.
	 *
	 * @return bool
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	/**
	 * Task metadata for Data Machine system-task surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Workspace Hygiene Report',
			'description'     => 'Non-destructive report for workspace disk usage, free space, worktree counts, and local cleanup candidates. Runs weekly when enabled.',
			'setting_key'     => self::SETTING_KEY,
			'default_enabled' => false,
			'supports_run'    => true,
		);
	}

	/**
	 * Execute the hygiene report.
	 *
	 * Params are optional and mirror `Workspace::workspace_hygiene_report()`.
	 *
	 * @param  int   $jobId  Job ID.
	 * @param  array $params Task params.
	 * @return void
	 */
	public function executeTask( int $jobId, array $params ): void {
		$enabled = (bool) PluginSettings::get(self::SETTING_KEY, false);
		if ( ! $enabled ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf('Workspace hygiene report disabled (PluginSettings: %s=false).', self::SETTING_KEY),
				)
			);
			return;
		}

		$workspace = new Workspace();
		$result    = $workspace->workspace_hygiene_report(
			array(
				'include_cleanup'         => array_key_exists('include_cleanup', $params) ? (bool) $params['include_cleanup'] : true,
				'include_sizes'           => array_key_exists('include_sizes', $params) ? (bool) $params['include_sizes'] : true,
				'include_worktree_status' => array_key_exists('include_worktree_status', $params) ? (bool) $params['include_worktree_status'] : false,
				'size_limit'              => isset($params['size_limit']) ? (int) $params['size_limit'] : 200,
			)
		);

		if ( $result instanceof \WP_Error ) {
			do_action(
				'datamachine_log',
				'error',
				'Workspace hygiene report failed',
				array(
					'task'  => $this->getTaskType(),
					'jobId' => $jobId,
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				)
			);
			$this->failJob($jobId, $result->get_error_message());
			return;
		}
		$worktrees = (array) ( $result['worktrees'] ?? array() );
		$cleanup   = (array) ( $result['cleanup']['summary'] ?? array() );
		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Workspace hygiene report: %s used, %s free, %d worktree(s), %d cleanup candidate(s).',
				$result['size']['total_human'] ?? 'unknown size',
				$result['disk']['free_human'] ?? 'unknown disk',
				(int) ( $worktrees['worktrees'] ?? 0 ),
				(int) ( $cleanup['would_remove'] ?? 0 )
			),
			array(
				'task'   => $this->getTaskType(),
				'jobId'  => $jobId,
				'report' => $result,
			)
		);

		$this->completeJob($jobId, $result);
	}
}
