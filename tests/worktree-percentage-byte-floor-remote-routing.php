<?php

declare(strict_types=1);

const ABSPATH = __DIR__ . '/fixtures/';
define('DATAMACHINE_WORKSPACE_PATH', sys_get_temp_dir());

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return 'datamachine_code_remote_workspace_backend_should_handle' === $hook ? true : $value; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['percentage_remote_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, mixed ...$unused ): bool { $GLOBALS['percentage_remote_options'][ $name ] = $value; return true; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }

$GLOBALS['percentage_remote_options'] = array();

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';
require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

use DataMachineCode\Abilities\WorkspaceAbilities;

function percentage_remote_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$result = WorkspaceAbilities::worktreeAdd(array(
	'repo' => 'remote-repo', 'branch' => 'small-demand', 'inject_context' => false, 'bootstrap' => false,
	'require_task_tracker' => false, 'allow_percentage_byte_floor_exception' => true,
));
percentage_remote_assert(is_wp_error($result) && 'remote_worktree_percentage_byte_floor_exception_unsupported' === $result->get_error_code(), 'Remote routing must refuse the local-only percentage capacity exception.');
percentage_remote_assert('local_workspace_capacity_required' === ($result->get_error_data()['remediation']['code'] ?? null), 'Remote refusal must return typed local-capacity remediation.');
percentage_remote_assert(array() === $GLOBALS['percentage_remote_options'], 'Remote exception refusal must not create remote workspace state.');

echo "worktree-percentage-byte-floor-remote-routing: ok\n";
