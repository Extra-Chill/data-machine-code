<?php
/**
 * Safe Workspace Cleanup System Task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Workspace\WorkspaceSafeCleanupOrchestrator;

defined('ABSPATH') || exit;

/**
 * Runs the bounded safe-cleanup orchestration on the shared maintenance schedule.
 */
class WorkspaceSafeCleanupTask extends SystemTask {

	public const SETTING_KEY = 'workspace_safe_cleanup_enabled';

	public function getTaskType(): string {
		return 'workspace_safe_cleanup';
	}

	public function requiresAgentContext(): bool {
		return false;
	}

	/** @return array<string,mixed> */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Safe Workspace Cleanup',
			'description'     => 'Bounded recurring cleanup of DMC workspaces. Revalidates every candidate and preserves dirty, unpushed, and live worktrees.',
			'setting_key'     => self::SETTING_KEY,
			'default_enabled' => true,
			'supports_run'    => true,
		);
	}

	/** @return array<string,mixed> */
	public static function recurringSchedule(): array {
		return array(
			'task_type'       => 'workspace_safe_cleanup',
			'interval'        => 'hourly',
			'enabled_setting' => self::SETTING_KEY,
			'default_enabled' => true,
			'label'           => 'Hourly — applies bounded safe workspace cleanup',
			'task_params'     => array(
				'source'       => 'recurring_schedule',
				'limit'        => 25,
				'passes'       => 5,
				'cycles'       => 5,
				'until_budget' => '45s',
			),
		);
	}

	/**
	 * Execute the canonical safe cleanup flow.
	 *
	 * Schedule parameters bound execution only. Force and unpushed-discard are
	 * intentionally not forwarded, so maintenance cannot weaken safety policy.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params Task parameters.
	 */
	public function executeTask( int $jobId, array $params ): void {
		if ( ! PluginSettings::get(self::SETTING_KEY, true) ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf('Safe workspace cleanup disabled (PluginSettings: %s=false).', self::SETTING_KEY),
				)
			);
			return;
		}

		$input  = array(
			'source'           => (string) ( $params['source'] ?? 'system_task' ),
			'limit'            => isset($params['limit']) ? (int) $params['limit'] : 25,
			'passes'           => isset($params['passes']) ? (int) $params['passes'] : 5,
			'cycles'           => isset($params['cycles']) ? (int) $params['cycles'] : 5,
			'until_budget'     => isset($params['until_budget']) ? (string) $params['until_budget'] : '45s',
			'dry_run'          => ! empty($params['dry_run']),
			'force'            => false,
			'discard_unpushed' => false,
		);
		$result = $this->run_safe_cleanup($input);

		if ( $result instanceof \WP_Error ) {
			do_action('datamachine_log', 'error', 'Safe workspace cleanup failed', array(
				'task'  => $this->getTaskType(),
				'jobId' => $jobId,
				'error' => $result->get_error_message(),
				'code'  => $result->get_error_code(),
			));
			$this->failJob($jobId, $result->get_error_message());
			return;
		}

		do_action('datamachine_log', 'info', 'Safe workspace cleanup completed.', array(
			'task'   => $this->getTaskType(),
			'jobId'  => $jobId,
			'result' => $result,
		));
		$this->completeJob($jobId, $result);
	}

	/** @return array<string,mixed>|\WP_Error */
	protected function run_safe_cleanup( array $input ): array|\WP_Error {
		return ( new WorkspaceSafeCleanupOrchestrator() )->run($input);
	}
}
