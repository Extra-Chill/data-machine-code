<?php

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorkspaceAliasResolver {
		public static function is_context_repository( string $handle ): bool { return false; }
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function get_option( string $name, mixed $default = false ): mixed { return array( 'repos' => array() ); }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return 'datamachine_code_github_allowed_hosts' === $hook ? array( 'github.com', 'forge.example.test' ) : $value;
	}

	require_once dirname(__DIR__) . '/inc/Support/GitHubRemote.php';
	require_once dirname(__DIR__) . '/inc/Support/GitTransportPreflight.php';
	require_once dirname(__DIR__) . '/inc/Support/GitRunner.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitOperations.php';

	use DataMachineCode\Support\GitTransportPreflight;
	use DataMachineCode\Workspace\WorkspaceGitOperations;

	final class GitPushTransportHarness {
		use WorkspaceGitOperations;

		private string $workspace_path;
		/** @var list<string> */
		public array $commands = array();

		public function __construct( string $workspace_path ) {
			$this->workspace_path = $workspace_path;
		}

		private function parse_handle( string $handle ): array {
			$parts = explode('@', $handle, 2);
			return array( 'repo' => $parts[0], 'dir_name' => $handle, 'is_worktree' => isset($parts[1]) );
		}

		private function validate_containment( string $path, string $container ): array {
			$real_path = realpath($path);
			return array(
				'valid'     => is_string($real_path) && str_starts_with($real_path, realpath($container) . '/'),
				'real_path' => $real_path ?: '',
				'message'   => 'outside workspace',
			);
		}

		private function run_git( string $path, string $command, int $timeout_seconds = 0 ): array {
			$this->commands[] = $command;
			return array( 'success' => true, 'output' => 'topic' );
		}
	}

	function push_transport_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	function push_transport_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	$root = sys_get_temp_dir() . '/dmc-push-transport-' . bin2hex(random_bytes(4));
	$repo = $root . '/repo@topic';
	$agent_pid = '';
	mkdir($repo, 0700, true);
	try {
		exec('git init ' . escapeshellarg($repo) . ' >/dev/null 2>&1', $output, $exit_code); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The integration fixture requires a real remote configuration.
		push_transport_assert_same(0, $exit_code, 'Git fixture initialization failed.');
		exec('git -C ' . escapeshellarg($repo) . ' remote add origin ' . escapeshellarg('ssh://git@forge.example.test:2222/owner/repository.git'), $output, $exit_code); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The integration fixture requires a real remote configuration.
		push_transport_assert_same(0, $exit_code, 'Git fixture remote setup failed.');

		putenv('SSH_AUTH_SOCK=/missing-agent-socket');
		$diagnostic = GitTransportPreflight::diagnose('ssh://git@forge.example.test:2222/owner/repository.git');
		push_transport_assert_same('ssh_agent_unavailable', $diagnostic['code'] ?? null, 'diagnose() must classify a missing socket.');
		push_transport_assert_same(2222, $diagnostic['ssh_port'] ?? null, 'diagnose() must retain the SSH port.');
		push_transport_assert_same('unverified', $diagnostic['https_authenticated'] ?? null, 'The HTTPS alternative must remain unverified.');

		$harness = new GitPushTransportHarness($root);
		$result  = $harness->git_push('repo@topic');
		push_transport_assert(is_wp_error($result), 'git_push() must return typed preflight failure before push.');
		push_transport_assert_same('git_ssh_transport_unavailable', $result->get_error_code(), 'git_push() must preserve the transport error type.');
		push_transport_assert(! in_array("push 'origin' 'topic'", $harness->commands, true), 'git_push() must not invoke the push runner after preflight failure.');

		exec('ssh-agent -s', $agent_output, $exit_code); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The integration fixture requires a real empty SSH agent.
		push_transport_assert_same(0, $exit_code, 'Empty SSH agent fixture failed to start.');
		$agent = implode("\n", $agent_output);
		push_transport_assert(1 === preg_match('/SSH_AUTH_SOCK=([^;]+);.*SSH_AGENT_PID=(\d+);/s', $agent, $agent_matches), 'Empty SSH agent fixture did not return its environment.');
		putenv('SSH_AUTH_SOCK=' . $agent_matches[1]);
		$agent_pid = $agent_matches[2];
		putenv('SSH_AGENT_PID=' . $agent_pid);
		$diagnostic = GitTransportPreflight::diagnose('ssh://git@forge.example.test:2222/owner/repository.git');
		push_transport_assert_same('ssh_agent_no_identities', $diagnostic['code'] ?? null, 'diagnose() must classify an empty agent.');

		$harness = new GitPushTransportHarness($root);
		$result  = $harness->git_push('repo@topic');
		push_transport_assert(is_wp_error($result), 'git_push() must reject an empty agent before push.');
		push_transport_assert_same('git_ssh_transport_unavailable', $result->get_error_code(), 'Empty agents must preserve the transport error type.');
		push_transport_assert(! in_array("push 'origin' 'topic'", $harness->commands, true), 'Empty agents must not reach the push runner.');

		exec('rm -rf ' . escapeshellarg($root)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Removes only this test-owned temporary fixture.
		$root = '';
	} finally {
		if ( '' !== $agent_pid ) {
			exec('ssh-agent -k >/dev/null 2>&1'); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Stops only the test-owned agent selected by SSH_AGENT_PID.
		}
		putenv('SSH_AGENT_PID');
		putenv('SSH_AUTH_SOCK');
		if ( '' !== $root ) {
			exec('rm -rf ' . escapeshellarg($root)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Removes only this test-owned temporary fixture.
		}
	}

	echo "workspace-git-push-transport-preflight: ok\n";
}
