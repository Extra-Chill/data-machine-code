<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

final class WP_Error {
	public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

$GLOBALS['workspace_git_identity_filter'] = null;
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$filter = $GLOBALS['workspace_git_identity_filter'];
	return 'datamachine_code_git_identity_policy' === $hook && is_callable($filter) ? $filter($value, ...$args) : $value;
}

require_once dirname(__DIR__) . '/inc/Support/GitHubRemote.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitIdentityPolicy.php';

use DataMachineCode\Workspace\WorkspaceGitIdentityPolicy;

function identity_policy_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function identity_policy_run( string $path, string $command ): array|WP_Error {
	$lines = array();
	$status = 0;
	exec('GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null git -C ' . escapeshellarg($path) . ' ' . $command . ' 2>&1', $lines, $status);
	return 0 === $status ? array( 'output' => implode("\n", $lines) ) : new WP_Error('git_failed', implode("\n", $lines));
}

function identity_policy_output( array|WP_Error $result ): string {
	return is_wp_error($result) ? '' : (string) ($result['output'] ?? '');
}

$harness = new class {
	use WorkspaceGitIdentityPolicy;
	public function enforce( string $path ): ?WP_Error { return $this->enforce_repository_git_identity($path); }
	public function configure( string $path ): ?WP_Error { return $this->configure_repository_git_identity($path); }
	private function run_git( string $path, string $command ): array|WP_Error { return identity_policy_run($path, $command); }
};

$root    = sys_get_temp_dir() . '/dmc-git-identity-' . getmypid() . '-' . bin2hex(random_bytes(4));
$primary = $root . '/primary';
$worktree = $root . '/primary@identity';

try {
	mkdir($primary, 0777, true);
	identity_policy_run($primary, 'init -b main');
	identity_policy_run($primary, '-c user.name=Fixture -c user.email=fixture@example.test commit --allow-empty -m initial');
	identity_policy_run($primary, 'remote add origin git@github.com:Example-Owner/example-repo.git');
	identity_policy_run($primary, 'worktree add -b feature/identity ' . escapeshellarg($worktree));

	$GLOBALS['workspace_git_identity_filter'] = static function ( mixed $identity, array $descriptor, string $remote ): ?array {
		identity_policy_assert('Example-Owner/example-repo' === $descriptor['slug'], 'Policy receives the parsed GitHub remote descriptor.');
		identity_policy_assert('git@github.com:Example-Owner/example-repo.git' === $remote, 'Policy receives the original remote URL.');
		return array( 'name' => 'Managed Identity', 'email' => 'managed@example.test' );
	};
	identity_policy_assert(null === $harness->configure($worktree), 'Policy-backed managed worktree configures a valid identity.');
	identity_policy_assert('Managed Identity' === trim(identity_policy_output(identity_policy_run($worktree, 'config --get user.name'))), 'Managed worktree uses the policy name.');
	identity_policy_assert('managed@example.test' === trim(identity_policy_output(identity_policy_run($worktree, 'config --get user.email'))), 'Managed worktree uses the policy email.');
	identity_policy_assert('' === trim(identity_policy_output(identity_policy_run($primary, 'config --get user.name'))), 'Worktree policy identity does not alter the primary checkout.');
	identity_policy_assert(null === $harness->enforce($worktree), 'Configured host accepts its configured effective identity.');

	identity_policy_run($worktree, 'config --worktree user.name ' . escapeshellarg('Wrong Identity'));
	identity_policy_assert('repository_git_identity_mismatch' === $harness->enforce($worktree)?->get_error_code(), 'Configured host rejects a mismatched effective identity.');
	identity_policy_run($worktree, 'config --worktree --unset user.name');
	identity_policy_assert('repository_git_identity_mismatch' === $harness->enforce($worktree)?->get_error_code(), 'Configured host rejects a missing effective identity.');

	$GLOBALS['workspace_git_identity_filter'] = null;
	$bare = $root . '/bare';
	mkdir($bare, 0777, true);
	identity_policy_run($bare, 'init -b main');
	identity_policy_run($bare, 'remote add origin git@github.com:Example-Owner/unconfigured-repo.git');
	identity_policy_assert(null === $harness->enforce($bare), 'Unconfigured host preserves ambient Git identity behavior.');

	echo "workspace-git-identity-policy: ok\n";
} finally {
	if ( is_dir($root) ) {
		exec('rm -rf ' . escapeshellarg($root));
	}
}
