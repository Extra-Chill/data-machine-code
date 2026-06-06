<?php
/**
 * Base class for site-scoped workspace-maintenance system tasks.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

/**
 * Thin base that centralizes the agent-context default for this plugin.
 *
 * Every Data Machine Code task is site-scoped workspace maintenance —
 * disk/file/git cleanup driven by the Workspace service and gated by
 * PluginSettings. None of them act as an agent or invoke an agent-scoped
 * ability, so the core SystemTask default of `requiresAgentContext() === true`
 * (the safe default for content-mutating agent tasks in data-machine core) is
 * wrong for this domain.
 *
 * Forgetting to override that default fails quietly: an agent-less recurring
 * schedule (`per_agent => false`) is rejected at TaskScheduler::schedule()'s
 * agent-context gate and the cleanup silently stops running (see #564 / #566).
 * Making the correct-for-DMC choice the default here means that failure mode
 * cannot recur by omission.
 *
 * This mirrors core's `DataMachine\Engine\AI\System\Tasks\Retention\RetentionTask`,
 * which performs the same opt-out for retention cleanup. Unlike RetentionTask,
 * this base intentionally stays a pure default — it does not finalize
 * executeTask() or add any other behavior; we only want the agent-context
 * default centralized.
 *
 * A task that genuinely needs agent context can override
 * `requiresAgentContext()` back to true.
 */
abstract class MaintenanceTask extends SystemTask {

	/**
	 * DMC tasks are site-scoped workspace maintenance with no agent owner.
	 *
	 * @return bool
	 */
	public function requiresAgentContext(): bool {
		return false;
	}
}
