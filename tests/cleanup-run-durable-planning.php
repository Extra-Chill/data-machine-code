<?php

declare(strict_types=1);

	namespace DataMachineCode\Workspace {
	final class Workspace {
		public bool $run_created_before_discovery = false;
		public string $outcome = 'timeout';

		public function __construct(private \DataMachineCode\Storage\CleanupRunRepository $repository) {}

		public function workspace_cleanup_plan(array $opts): array|\WP_Error {
			$this->run_created_before_discovery = null !== $this->repository->get_run('cleanup-run-timeout');
			if ('exception' === $this->outcome) {
				throw new \RuntimeException('Workspace discovery crashed.');
			}
			if ('success' === $this->outcome) {
				return array(
					'safety_policy' => array('applies_inline' => false),
					'rows' => array(),
					'summary' => array('apply_command' => 'studio wp datamachine-code workspace cleanup apply <run-id>'),
				);
			}
			return new \WP_Error('workspace_cleanup_plan_timeout', 'Workspace discovery exceeded its deadline.', array('status' => 504));
		}
	}
}

namespace {
	if (! defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if (! class_exists('WP_Error')) {
		class WP_Error {
			public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): mixed { return $this->data; }
		}
	}

	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepository.php';
	require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';
	require_once dirname(__DIR__) . '/inc/Workspace/CleanupRunService.php';

	final class DurablePlanningRepository extends DataMachineCode\Storage\CleanupRunRepository {
		public array $runs = array();

		public function create_run(array $run): string|WP_Error {
			$this->runs['cleanup-run-timeout'] = $run + array('run_id' => 'cleanup-run-timeout');
			return 'cleanup-run-timeout';
		}
		public function add_items(string $run_id, array $items): int|WP_Error { return count($items); }
		public function get_run(string $run_id): ?array { return $this->runs[$run_id] ?? null; }
		public function get_items(string $run_id): array { return array(); }
		public function update_run(string $run_id, array $fields): bool {
			if (isset($fields['expected_status']) && $fields['expected_status'] !== ($this->runs[$run_id]['status'] ?? null)) {
				return false;
			}
			$this->runs[$run_id] = array_merge($this->runs[$run_id], $fields);
			return true;
		}
	}

	function durable_planning_assert(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
		}
	}

	$repository = new DurablePlanningRepository();
	$workspace = new DataMachineCode\Workspace\Workspace($repository);
	$service = new DataMachineCode\Workspace\CleanupRunService($repository, $workspace);
	$result = $service->plan(array('mode' => 'artifacts'));

	durable_planning_assert(true, $workspace->run_created_before_discovery, 'The durable run must exist before blocking discovery starts.');
	durable_planning_assert('workspace_cleanup_plan_timeout', $result instanceof WP_Error ? $result->get_error_code() : null, 'Timeout discovery should preserve the planner error code.');
	durable_planning_assert('cleanup-run-timeout', $result instanceof WP_Error ? $result->get_error_data()['run_id'] ?? null : null, 'Timeout failure must return the durable run ID.');
	durable_planning_assert(504, $result instanceof WP_Error ? $result->get_error_data()['status'] ?? null : null, 'Timeout response must preserve the planner status.');
	durable_planning_assert('planning_failed', $repository->runs['cleanup-run-timeout']['status'] ?? null, 'Timeout discovery must retain a terminal recovery state.');
	durable_planning_assert('workspace_cleanup_plan_timeout', $repository->runs['cleanup-run-timeout']['summary']['planning']['error']['code'] ?? null, 'Recovery evidence must retain the timeout cause.');
	durable_planning_assert(false, $repository->runs['cleanup-run-timeout']['summary']['recovery']['apply_authorized'] ?? null, 'An incomplete plan must never authorize apply.');
	$apply = $service->apply('cleanup-run-timeout');
	durable_planning_assert('cleanup_run_not_ready', $apply instanceof WP_Error ? $apply->get_error_code() : null, 'Apply must reject incomplete planning runs.');
	durable_planning_assert('planning_failed', $repository->runs['cleanup-run-timeout']['status'] ?? null, 'Rejected apply must preserve recovery state.');

	$exception_repository = new DurablePlanningRepository();
	$exception_workspace = new DataMachineCode\Workspace\Workspace($exception_repository);
	$exception_workspace->outcome = 'exception';
	$exception = (new DataMachineCode\Workspace\CleanupRunService($exception_repository, $exception_workspace))->plan(array('mode' => 'artifacts'));
	durable_planning_assert('cleanup_plan_discovery_exception', $exception instanceof WP_Error ? $exception->get_error_code() : null, 'Thrown discovery exceptions must return durable recovery evidence.');
	durable_planning_assert('planning_failed', $exception_repository->runs['cleanup-run-timeout']['status'] ?? null, 'Thrown discovery exceptions must checkpoint planning failure.');

	$success_repository = new DurablePlanningRepository();
	$success_workspace = new DataMachineCode\Workspace\Workspace($success_repository);
	$success_workspace->outcome = 'success';
	$success_service = new DataMachineCode\Workspace\CleanupRunService($success_repository, $success_workspace);
	$success_plan = $success_service->plan(array('mode' => 'artifacts'));
	durable_planning_assert('planned', $success_repository->runs['cleanup-run-timeout']['status'] ?? null, 'Successful discovery must transition the run to planned.');
	durable_planning_assert('cleanup-run-timeout', $success_plan['run_id'] ?? null, 'Successful planning must return its durable run ID.');
	$success_apply = $success_service->apply('cleanup-run-timeout');
	durable_planning_assert('completed', $success_apply['state'] ?? null, 'A successfully planned empty run must transition through apply to completed.');

	fwrite(STDOUT, "cleanup-run-durable-planning ok\n");
}
