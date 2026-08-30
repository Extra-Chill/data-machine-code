<?php

declare(strict_types=1);

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

require_once dirname(__DIR__) . '/inc/Workspace/CleanupRunService.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeRetentionProvider.php';

final class RetentionProviderCleanupService extends DataMachineCode\Workspace\CleanupRunService {
	public array $plan_options = array();
	public int $apply_calls = 0;
	public int $status_calls = 0;
	public int $evidence_calls = 0;
	public string $persisted_plan_id = 'plan-1';
	public string $apply_state = 'completed';
	public int $pending = 0;
	public bool $inventory_partial = true;

	public function __construct() {}

	public function plan(array $opts = array()): array|WP_Error {
		$this->plan_options = $opts;
		return array(
			'run_id' => 'run-1',
			'plan_id' => 'plan-1',
			'continuation' => $this->inventory_partial ? array('partial' => true, 'next_offset' => 25) : array(),
			'summary' => array(
				'blockers' => array(
					'dirty_worktree' => array('count' => 2),
				),
			),
		);
	}

	public function apply(string $run_id, array $opts = array()): array|WP_Error {
		++$this->apply_calls;
		return $this->status_result($this->apply_state);
	}

	public function status(string $run_id): array|WP_Error {
		++$this->status_calls;
		return $this->status_result('planned');
	}

	public function evidence(string $run_id): array|WP_Error {
		++$this->evidence_calls;
		return $this->status_result('completed');
	}

	private function status_result(string $state): array {
		return array(
			'success' => true,
			'state' => $state,
			'run_id' => 'run-1',
			'run' => array(
				'run_id' => 'run-1',
				'status' => $state,
				'policy' => array(
					'plan_id' => $this->persisted_plan_id,
					'inventory_continuation' => $this->inventory_partial ? array('partial' => true) : array(),
					'retention_blockers' => array('dirty_worktree' => array('count' => 2)),
				),
			),
			'summary' => array(
				'pending_or_failed' => $this->pending,
				'items_by_status' => array('applied' => 3),
				'bytes_reclaimed' => 4096,
			),
		);
	}
}

function retention_provider_assert(mixed $expected, mixed $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
	}
}

function retention_provider_request(string $operation, array $extra = array()): string {
	$request = array(
		'schema' => 'homeboy/worktree-retention/v1',
		'provider_id' => 'data-machine-code',
		'operation' => $operation,
		'request_id' => 'request-1',
		'bounds' => array('max_items' => 7, 'timeout_ms' => 5000),
		'deadline_unix_ms' => 15000,
	);
	if ('plan' !== $operation) {
		$request['run_id'] = 'run-1';
		$request['plan_id'] = 'plan-1';
	}
	return json_encode(array_merge($request, $extra), JSON_THROW_ON_ERROR);
}

$service = new RetentionProviderCleanupService();
$provider = new DataMachineCode\Workspace\WorktreeRetentionProvider($service, static fn(): int => 10000);

$plan = $provider->handle_json(retention_provider_request('plan'));
$plan_wire = json_decode($provider->encode_response($plan));
retention_provider_assert(true, is_object($plan_wire->effects ?? null), 'Empty effects must encode as a JSON object for the strict Homeboy contract.');
retention_provider_assert(true, is_object($plan_wire->blockers->by_reason ?? null), 'Empty blocker maps must encode as JSON objects for the strict Homeboy contract.');
retention_provider_assert('planned', $plan['state'] ?? null, 'Plan must expose a reviewed provider state.');
retention_provider_assert('partial', $plan['inventory_completeness'] ?? null, 'A bounded plan must preserve partial inventory evidence.');
retention_provider_assert('plan', $plan['continuation']['resume_operation'] ?? null, 'A bounded inventory page must point to the next plan pass.');
retention_provider_assert(2, $plan['blockers']['by_reason']['dirty_worktree'] ?? null, 'Plan blockers must be normalized by reason.');
retention_provider_assert(false, $service->plan_options['include_artifacts'] ?? null, 'Provider planning must not route through artifact cleanup.');
retention_provider_assert(true, $service->plan_options['include_worktrees'] ?? null, 'Provider planning must delegate to worktree cleanup.');
retention_provider_assert(7, $service->plan_options['limit'] ?? null, 'Homeboy item bounds must reach CleanupRunService.');
retention_provider_assert('5s', $service->plan_options['until_budget'] ?? null, 'Homeboy deadlines must bound provider discovery.');

$apply = $provider->handle_json(retention_provider_request('apply'));
retention_provider_assert(1, $service->apply_calls, 'Matching apply must delegate exactly once.');
retention_provider_assert('continuing', $apply['state'] ?? null, 'A completed page with more inventory must request another plan pass.');
retention_provider_assert('plan', $apply['continuation']['resume_operation'] ?? null, 'Completed bounded apply must continue with planning.');
retention_provider_assert(3, $apply['effects']['worktrees_removed'] ?? null, 'Apply receipt must normalize removed worktrees.');
retention_provider_assert(4096, $apply['effects']['bytes_reclaimed'] ?? null, 'Apply receipt must normalize reclaimed bytes.');

$service->persisted_plan_id = 'different-plan';
$mismatch = $provider->handle_json(retention_provider_request('apply'));
retention_provider_assert('failed', $mismatch['state'] ?? null, 'Mismatched plans must fail closed.');
retention_provider_assert(1, $service->apply_calls, 'Mismatched plans must fail before mutation.');
retention_provider_assert(1, $mismatch['blockers']['by_reason']['plan_identity_mismatch'] ?? null, 'Mismatch failure must remain machine-readable.');
$service->persisted_plan_id = 'plan-1';

$status = $provider->handle_json(retention_provider_request('status'));
retention_provider_assert('planned', $status['state'] ?? null, 'Status must preserve reviewed plan state.');
$evidence = $provider->handle_json(retention_provider_request('evidence'));
retention_provider_assert(1, $service->evidence_calls, 'Evidence must delegate to the durable cleanup evidence service.');
retention_provider_assert(4096, $evidence['effects']['bytes_reclaimed'] ?? null, 'Evidence must remain bounded and normalized.');

$unknown = $provider->handle_json(retention_provider_request('plan', array('unexpected' => true)));
retention_provider_assert(1, $unknown['blockers']['by_reason']['unknown_request_field'] ?? null, 'Unknown request fields must be rejected.');
$expired = $provider->handle_json(retention_provider_request('plan', array('deadline_unix_ms' => 9999)));
retention_provider_assert(1, $expired['blockers']['by_reason']['deadline_elapsed'] ?? null, 'Expired deadlines must fail before planning.');
$oversized = $provider->handle_json(str_repeat('x', 262145));
retention_provider_assert(1, $oversized['blockers']['by_reason']['request_too_large'] ?? null, 'Oversized input must fail before decoding.');

$encoded = $provider->encode_response(array('oversized' => str_repeat('x', 65536)));
$bounded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
retention_provider_assert(1, $bounded['blockers']['by_reason']['response_too_large'] ?? null, 'Oversized output must collapse to a bounded failure receipt.');

fwrite(STDOUT, "worktree-retention-provider ok\n");
