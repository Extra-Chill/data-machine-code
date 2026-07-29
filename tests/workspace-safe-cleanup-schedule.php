<?php

declare(strict_types=1);

namespace DataMachine\Core {
	final class PluginSettings {
		public static array $settings = array();

		public static function get( string $key, mixed $default = null ): mixed {
			return self::$settings[ $key ] ?? $default;
		}
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {
		public array $completed = array();
		public array $failed = array();

		protected function completeJob( int $job_id, array $result ): void {
			$this->completed[] = array( $job_id, $result );
		}

		protected function failJob( int $job_id, string $message ): void {
			$this->failed[] = array( $job_id, $message );
		}
	}
}

namespace {
	define('ABSPATH', dirname(__DIR__) . '/');
	function do_action( string $hook, mixed ...$args ): void {}

	final class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}

	function workspace_safe_schedule_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	require_once dirname(__DIR__) . '/inc/Tasks/WorkspaceSafeCleanupTask.php';

	final class TestWorkspaceSafeCleanupTask extends \DataMachineCode\Tasks\WorkspaceSafeCleanupTask {
		public array $inputs = array();
		protected function run_safe_cleanup( array $input ): array|\WP_Error {
			$this->inputs[] = $input;
			return array( 'success' => true, 'run_id' => 'cleanup-run-safe-task', 'summary' => array( 'removed' => 1 ) );
		}
	}

	$meta = \DataMachineCode\Tasks\WorkspaceSafeCleanupTask::getTaskMeta();
	workspace_safe_schedule_assert('workspace_safe_cleanup_enabled' === $meta['setting_key'], 'task exposes its owned setting key');
	workspace_safe_schedule_assert(true === $meta['default_enabled'], 'safe maintenance is enabled by default');

	\DataMachine\Core\PluginSettings::$settings = array( 'workspace_safe_cleanup_enabled' => false );
	$task = new TestWorkspaceSafeCleanupTask();
	$task->executeTask(10, array());
	workspace_safe_schedule_assert(array() === $task->inputs, 'disabled task does not invoke cleanup');
	workspace_safe_schedule_assert(true === ($task->completed[0][1]['skipped'] ?? false), 'disabled task reports visible skipped state');

	\DataMachine\Core\PluginSettings::$settings = array( 'workspace_safe_cleanup_enabled' => true );
	$task = new TestWorkspaceSafeCleanupTask();
	$task->executeTask(11, array( 'source' => 'recurring_schedule', 'limit' => 9, 'passes' => 2, 'cycles' => 3, 'until_budget' => '20s', 'force' => true, 'discard_unpushed' => true ));
	$input = $task->inputs[0] ?? array();
	workspace_safe_schedule_assert(false === ($input['force'] ?? true), 'scheduled cleanup cannot enable force');
	workspace_safe_schedule_assert(false === ($input['discard_unpushed'] ?? true), 'scheduled cleanup cannot discard unpushed work');
	workspace_safe_schedule_assert(9 === ($input['limit'] ?? 0) && 2 === ($input['passes'] ?? 0) && 3 === ($input['cycles'] ?? 0), 'schedule bounds are forwarded');
	workspace_safe_schedule_assert('20s' === ($input['until_budget'] ?? ''), 'schedule time budget is forwarded');
	workspace_safe_schedule_assert('cleanup-run-safe-task' === ($task->completed[0][1]['run_id'] ?? ''), 'task persists cleanup evidence in its completed result');
	$schedule = \DataMachineCode\Tasks\WorkspaceSafeCleanupTask::recurringSchedule();
	workspace_safe_schedule_assert('hourly' === ($schedule['interval'] ?? ''), 'maintenance schedule is hourly');
	workspace_safe_schedule_assert(true === ($schedule['default_enabled'] ?? false), 'maintenance schedule defaults to enabled');
	workspace_safe_schedule_assert(\DataMachineCode\Tasks\WorkspaceSafeCleanupTask::SETTING_KEY === ($schedule['enabled_setting'] ?? ''), 'maintenance schedule uses the task setting');
	workspace_safe_schedule_assert(array( 'source' => 'recurring_schedule', 'limit' => 25, 'passes' => 5, 'cycles' => 5, 'until_budget' => '45s' ) === ($schedule['task_params'] ?? null), 'maintenance schedule declares bounded invocation parameters');
	$source = file_get_contents(dirname(__DIR__) . '/data-machine-code.php');
	workspace_safe_schedule_assert(false !== strpos($source, '$tasks[\'workspace_safe_cleanup\']           = \\DataMachineCode\\Tasks\\WorkspaceSafeCleanupTask::class'), 'task is registered with DMC');
	workspace_safe_schedule_assert(false !== strpos($source, '$schedules[\'workspace_safe_cleanup\']           = \\DataMachineCode\\Tasks\\WorkspaceSafeCleanupTask::recurringSchedule()'), 'DMC binds the owned recurring schedule');

	echo "workspace safe cleanup schedule test passed.\n";
}
