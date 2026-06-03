<?php
/**
 * Smoke test for zero-tolerance worktree default-branch freshness guard.
 *
 * Run: php tests/smoke-worktree-default-branch-guard.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists(__NAMESPACE__ . '\FilesystemHelper') ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace DataMachineCode\Abilities {
	if ( ! class_exists(__NAMESPACE__ . '\GitHubAbilities') ) {
		class GitHubAbilities {
			public static function getPat(): string {
				return '';
			}
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	if ( ! defined('ARRAY_A') ) {
		define('ARRAY_A', 'ARRAY_A');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = (array) $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): array {
				return $this->data;
			}
		}
	}

	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists('wp_mkdir_p') ) {
		function wp_mkdir_p( string $path ): bool {
			return is_dir($path) || mkdir($path, 0755, true);
		}
	}

	if ( ! function_exists('get_option') ) {
		function get_option( string $_name, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists('update_option') ) {
		function update_option( string $_name, $_value, $_autoload = null ): bool {
			return true;
		}
	}

	if ( ! function_exists('current_time') ) {
		function current_time( string $_type, bool $_gmt = false ): string {
			return gmdate('Y-m-d H:i:s');
		}
	}

	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode($value, $flags, $depth);
		}
	}

	if ( ! function_exists('apply_filters') ) {
		function apply_filters( string $_hook_name, $value, ...$_args ) {
			return $value;
		}
	}

	class DatamachineCodeDefaultGuardWpdb {
		public string $prefix = 'wp_';
		public array $rows = array();
		public int $insert_id = 1;

		public function replace( string $_table, array $data ): int {
			$this->rows[ (string) $data['handle'] ] = $data;
			return 1;
		}

		public function insert( string $_table, array $_data, ?array $_format = null ): int {
			++$this->insert_id;
			return 1;
		}

		public function update( string $_table, array $_data, array $_where ): int {
			return 1;
		}

		public function get_var( string $sql ): ?string {
			return str_contains($sql, 'datamachine_code_locks') ? $this->prefix . 'datamachine_code_locks' : null;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'", $query, 1) ?? $query;
			}
			return $query;
		}
	}

	include __DIR__ . '/../inc/Support/GitHubRemote.php';
	include __DIR__ . '/../inc/Support/GitRunner.php';
	include __DIR__ . '/../inc/Support/PathSecurity.php';
	include __DIR__ . '/../inc/Storage/WorktreeInventoryRepository.php';
	include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	include __DIR__ . '/../inc/Workspace/WorktreeStalenessProbe.php';
	include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	include __DIR__ . '/../inc/Workspace/Workspace.php';

	exec('git --version 2>&1', $_git_version, $git_exit);
	if ( 0 !== $git_exit ) {
		echo "SKIP: git not available\n";
		exit(0);
	}

	$failures = 0;
	$total    = 0;
	$assert   = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
	};

	$run = function ( string $command, string $cwd ) use ( $assert ): string {
		exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $output, $code);
		$stdout = trim(implode("\n", $output));
		$assert(0 === $code, 'command succeeds: ' . $command . ( '' !== $stdout ? "\n{$stdout}" : '' ));
		return $stdout;
	};

	echo "=== smoke-worktree-default-branch-guard ===\n";

	$tmp = sys_get_temp_dir() . '/dmc-worktree-default-guard-' . bin2hex(random_bytes(4));
	mkdir($tmp, 0755, true);
	$tmp = (string) realpath($tmp);
	register_shutdown_function(
		function () use ( $tmp ): void {
			if ( is_dir($tmp) ) {
				exec('rm -rf ' . escapeshellarg($tmp) . ' 2>&1');
			}
		}
	);

	define('DATAMACHINE_WORKSPACE_PATH', $tmp . '/workspace');
	mkdir(DATAMACHINE_WORKSPACE_PATH, 0755, true);

	$origin = $tmp . '/origin';
	mkdir($origin, 0755, true);
	$run('git init -q --initial-branch=main', $origin);
	$run('git config user.email test@example.test', $origin);
	$run('git config user.name Test', $origin);
	file_put_contents($origin . '/README.md', "v1\n");
	$run('git add README.md', $origin);
	$run('git commit -q -m initial', $origin);

	$primary = DATAMACHINE_WORKSPACE_PATH . '/demo';
	exec(sprintf('git clone -q %s %s 2>&1', escapeshellarg($origin), escapeshellarg($primary)), $clone_output, $clone_code);
	$assert(0 === $clone_code, 'primary clone succeeds');
	$run('git config user.email test@example.test', $primary);
	$run('git config user.name Test', $primary);

	$old_sha = trim($run('git rev-parse HEAD', $primary));
	file_put_contents($origin . '/README.md', "v2\n");
	$run('git add README.md', $origin);
	$run('git commit -q -m second', $origin);
	$run('git fetch -q origin', $primary);
	$run('git branch feature/stale ' . escapeshellarg($old_sha), $primary);
	$run('git branch feature/rebase ' . escapeshellarg($old_sha), $primary);
	$run('git branch --set-upstream-to origin/main feature/rebase', $primary);

	$GLOBALS['wpdb'] = new DatamachineCodeDefaultGuardWpdb();
	$workspace       = new \DataMachineCode\Workspace\Workspace();

	$refused = $workspace->worktree_add('demo', 'feature/stale', null, false, false);
	$assert(is_wp_error($refused), 'stale branch is refused');
	$assert(is_wp_error($refused) && 'worktree_behind_default_branch' === $refused->get_error_code(), 'refusal uses default-branch error code');
	$assert(! is_dir(DATAMACHINE_WORKSPACE_PATH . '/demo@feature-stale'), 'refused worktree directory is not created');

	$rebased = $workspace->worktree_add('demo', 'feature/rebase', null, false, false, false, true);
	$assert(! is_wp_error($rebased), '--rebase-base permits branch that can rebase onto origin/main');
	$assert(0 === (int) ( $rebased['default_branch_commits_behind'] ?? -1 ), 'rebased result reports no default-branch lag');
	$assert(true === ( $rebased['rebase_succeeded'] ?? false ), 'rebased result reports successful rebase');

	$allowed = $workspace->worktree_add('demo', 'feature/stale', null, false, false, true);
	$assert(! is_wp_error($allowed), '--allow-stale permits explicit stale worktree');
	$assert(1 === (int) ( $allowed['default_branch_commits_behind'] ?? -1 ), 'allowed result reports default-branch behind count');

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit($failures > 0 ? 1 : 0);
}
