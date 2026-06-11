<?php
/**
 * Smoke coverage for runner workspace command execution ability.
 *
 * Run: php tests/smoke-runner-workspace-command.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}
	if ( ! defined('DATAMACHINE_WORKSPACE_PATH') ) {
		define('DATAMACHINE_WORKSPACE_PATH', sys_get_temp_dir() . '/dmc-runner-command-workspace');
	}

	$GLOBALS['dmc_runner_command_registered_abilities'] = array();
	$GLOBALS['dmc_runner_command_options'] = array();
	$GLOBALS['dmc_runner_command_filters'] = array();

	function wp_register_ability( string $name, array $definition ): void {
		$GLOBALS['dmc_runner_command_registered_abilities'][ $name ] = $definition;
	}

	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		unset($hook, $callback, $priority);
	}

	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook;
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['dmc_runner_command_options'][ $name ] ?? $default;
	}

	function update_option( string $name, mixed $value, bool $autoload = true ): bool {
		unset($autoload);
		$GLOBALS['dmc_runner_command_options'][ $name ] = $value;
		return true;
	}

	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset($accepted_args);
		$GLOBALS['dmc_runner_command_filters'][ $tag ][ $priority ][] = $callback;
	}

	function apply_filters( string $tag, mixed $value ): mixed {
		if ( empty($GLOBALS['dmc_runner_command_filters'][ $tag ]) ) {
			return $value;
		}

		ksort($GLOBALS['dmc_runner_command_filters'][ $tag ]);
		foreach ( $GLOBALS['dmc_runner_command_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback($value);
			}
		}

		return $value;
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

namespace DataMachineCode\Abilities {
	class GitHubAbilities {}
}

namespace DataMachineCode\Support {
	class PermissionHelper {
		public static function can_manage(): bool { return true; }
	}
}

namespace DataMachine\Core\FilesRepository {
	class FilesystemHelper {
		public static function get(): ?self { return new self(); }
		public function is_writable( string $path ): bool { return is_writable($path); }
		public function put_contents( string $path, string $content ): bool { return false !== file_put_contents($path, $content); }
	}
}

namespace {
	include __DIR__ . '/../inc/Support/RuntimeCapabilities.php';
	include __DIR__ . '/../inc/Support/ProcessRunner.php';
	include __DIR__ . '/../inc/Support/GitRunner.php';
	include __DIR__ . '/../inc/Support/PathSecurity.php';
	include __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';
	include __DIR__ . '/../inc/Workspace/RemoteWorkspaceBackend.php';
	include __DIR__ . '/../inc/Workspace/Workspace.php';
	include __DIR__ . '/../inc/Abilities/WorkspaceAbilities.php';
	include __DIR__ . '/../inc/Tools/AbilityToolProjections.php';

	use DataMachineCode\Abilities\WorkspaceAbilities;
	use DataMachineCode\Tools\AbilityToolProjections;

	$failures = array();
	$total    = 0;
	$assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Runner workspace command - smoke\n";

	new WorkspaceAbilities();
	$assert('canonical ability is registered', isset($GLOBALS['dmc_runner_command_registered_abilities']['datamachine-code/run-runner-workspace-command']));
	$assert('tool projection exposes runner command', 'datamachine-code/run-runner-workspace-command' === ( AbilityToolProjections::projected_tools()['workspace_run_runner_command']['ability'] ?? null ));

	$workspace_root = DATAMACHINE_WORKSPACE_PATH;
	$repo_path      = $workspace_root . '/example@runner-command';
	if ( ! is_dir($repo_path) ) {
		mkdir($repo_path, 0777, true);
	}
	if ( ! is_dir($repo_path . '/.git') ) {
		exec(sprintf('git -C %s init --quiet 2>&1', escapeshellarg($repo_path)));
	}
	if ( ! is_dir($repo_path . '/subdir') ) {
		mkdir($repo_path . '/subdir');
	}

	$local = WorkspaceAbilities::runRunnerWorkspaceCommand(
		array(
			'workspace_handle' => 'example@runner-command',
			'command'          => 'printf "%s" "$DMC_SMOKE"',
			'description'      => 'verify env forwarding',
			'timeout'          => 10,
			'env'              => array( 'DMC_SMOKE' => 'runner-ok' ),
		)
	);
	$assert('local command succeeds', is_array($local) && true === ( $local['success'] ?? false ));
	$assert('local command uses local backend', is_array($local) && 'local_git' === ( $local['backend'] ?? null ));
	$assert('local command captures stdout', is_array($local) && 'runner-ok' === ( $local['stdout'] ?? null ));

	$cwd = WorkspaceAbilities::runRunnerWorkspaceCommand(
		array(
			'workspace_handle' => 'example@runner-command',
			'command'          => 'pwd',
			'cwd'              => 'subdir',
		)
	);
	$assert('relative cwd is honored', is_array($cwd) && str_ends_with(trim((string) ( $cwd['stdout'] ?? '' )), '/subdir'));

	$failed = WorkspaceAbilities::runRunnerWorkspaceCommand(
		array(
			'workspace_handle' => 'example@runner-command',
			'command'          => 'printf "nope" && printf "bad" >&2 && exit 7',
		)
	);
	$assert('non-zero command is typed result', is_array($failed) && false === ( $failed['success'] ?? true ) && 7 === ( $failed['exit_code'] ?? null ) && 'command_failed' === ( $failed['failure_type'] ?? null ));
	$assert('non-zero command separates stderr', is_array($failed) && 'nope' === ( $failed['stdout'] ?? null ) && 'bad' === ( $failed['stderr'] ?? null ));

	update_option(
		'datamachine_code_remote_workspace_state',
		array(
			'repos'      => array( 'example' => array( 'repo' => 'Extra-Chill/example', 'url' => 'https://github.com/Extra-Chill/example.git' ) ),
			'repo_names' => array( 'Extra-Chill/example' => 'example' ),
			'worktrees' => array(
				'example@runner-command' => array(
					'repo_name'       => 'example',
					'repo'            => 'Extra-Chill/example',
					'branch'          => 'runner/command',
					'base_ref'        => 'main',
					'pending_files'   => array(),
					'changed_files'   => array(),
					'last_commit_sha' => '',
				),
			),
		)
	);

	$remote = WorkspaceAbilities::runRunnerWorkspaceCommand(
		array(
			'workspace_handle' => 'example@runner-command',
			'command'          => 'npm test',
		)
	);
	$assert('remote backend returns typed unavailable result', is_array($remote) && false === ( $remote['success'] ?? true ) && 'github_api' === ( $remote['backend'] ?? null ) && 'unavailable' === ( $remote['failure_type'] ?? null ));
	$assert('remote result does not leak local path', is_array($remote) && str_starts_with((string) ( $remote['path'] ?? '' ), 'github://'));

	if ( $failures ) {
		echo "\n" . count($failures) . " of {$total} assertions failed.\n";
		exit(1);
	}

	echo "\nAll {$total} runner command assertions passed.\n";
}
