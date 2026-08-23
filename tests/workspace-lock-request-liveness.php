<?php

declare(strict_types=1);

const ABSPATH = __DIR__ . '/fixtures/';

final class WP_Error {
	public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
	if ('datamachine_code_workspace_lock_request_time' === $hook) {
		return $GLOBALS['request_liveness_time'];
	}
	if ('datamachine_code_workspace_lock_request_liveness' === $hook) {
		return $GLOBALS['request_liveness_override'];
	}
	return $value;
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;

function request_liveness_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }

$workspace = sys_get_temp_dir() . '/dmc-request-liveness-' . bin2hex(random_bytes(6));
mkdir($workspace . '/.locks/requests', 0777, true);
try {
	foreach (array(
		'eperm' => array( 'state' => 'unknown', 'reason' => 'eperm' ),
		'no-posix' => array( 'state' => 'unknown', 'reason' => 'posix_unavailable' ),
		'pid-reused' => array( 'state' => 'live', 'reason' => 'pid_signalable' ),
	) as $name => $liveness) {
		$GLOBALS['request_liveness_time'] = 1000;
		$GLOBALS['request_liveness_override'] = $liveness;
		$path = $workspace . '/.locks/requests/' . $name . '.json';
		file_put_contents($path, json_encode(array(
			'request_id' => $name . '-request',
			'resource' => $workspace . '/.locks/worktree-workspace-capacity-admission.lock',
			'state' => 'queued',
			'pid' => 12345,
			'created_at' => gmdate('c', 900),
			'heartbeat_at' => gmdate('c', 1000),
			'expires_at' => gmdate('c', 1010),
			'queue_order' => '00000000001000.000000-' . $name,
		)));
		$blocked = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'acquired', 0);
		request_liveness_assert(is_wp_error($blocked) && 'workspace_repo_busy' === $blocked->get_error_code() && is_file($path), $name . ' must preserve a fresh uncertain or live-looking queue owner.');
		$GLOBALS['request_liveness_time'] = 1011;
		$admitted = WorkspaceMutationLock::with_repo($workspace, 'workspace-capacity-admission', static fn(): string => 'acquired', 1);
		request_liveness_assert('acquired' === $admitted && ! is_file($path), $name . ' expired queue token must not permanently block FIFO admission.');
	}
	echo "workspace-lock-request-liveness ok\n";
} finally {
	foreach (glob($workspace . '/.locks/requests/*.json') ?: array() as $file) { @unlink($file); }
	@rmdir($workspace . '/.locks/requests');
	foreach (glob($workspace . '/.locks/*.lock') ?: array() as $file) { @unlink($file); }
	@rmdir($workspace . '/.locks');
	@rmdir($workspace);
}
