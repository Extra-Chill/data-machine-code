<?php
/**
 * Public CLI coverage for concurrent SQLite-backed worktree provisioning.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_Error {
		public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	final class Cli_Concurrency_Halt extends \RuntimeException {
		public function __construct(public readonly int $status) { parent::__construct('WP-CLI halted.'); }
	}

	final class WP_CLI {
		public static function line(string $message): void { fwrite(STDOUT, $message . "\n"); }
		public static function warning(string $message): void { fwrite(STDERR, $message . "\n"); }
		public static function error(string $message): void { throw new \RuntimeException($message); }
		public static function halt(int $status): never { throw new Cli_Concurrency_Halt($status); }
	}

	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
	function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $GLOBALS['cli_concurrency_filters'][$hook] ?? $value; }
	function wp_get_ability(string $name): ?object { return $GLOBALS['cli_concurrency_ability'] ?? null; }

	define('ABSPATH', __DIR__ . '/fixtures/');
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/WorkspaceCompactOutput.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachineCode\Cli\Commands\WorkspaceCommand;
	use DataMachineCode\Storage\SqliteBusyRetry;

	final class Cli_Concurrency_Wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		private bool $errors_suppressed = false;

		public function __construct(public readonly PDO $pdo) {}
		public function db_server_info(): string { return 'SQLite'; }
		public function suppress_errors(bool $suppress = true): bool {
			$previous = $this->errors_suppressed;
			$this->errors_suppressed = $suppress;
			return $previous;
		}
	}

	final class Cli_Concurrency_Add_Ability {
		public function __construct(private string $workspace) {}

		public function execute(array $input): array|WP_Error {
			$repo   = (string) ($input['repo'] ?? '');
			$branch = (string) ($input['branch'] ?? '');
			$handle = $repo . '@' . preg_replace('/[^a-z0-9]+/', '-', strtolower($branch));
			$path   = $this->workspace . '/' . $handle;

			$journal = SqliteBusyRetry::run('worktree_creation_intent_store', function () use ($handle, $repo, $branch, $path): bool {
				return $this->writeRegistry(static function (PDO $pdo) use ($handle, $repo, $branch, $path): bool {
					$statement = $pdo->prepare('INSERT INTO registry(handle, repo, branch, path, state) VALUES (?, ?, ?, ?, ?) ON CONFLICT(handle) DO NOTHING');
					$statement->execute(array($handle, $repo, $branch, $path, 'intent'));
					return true;
				});
			});
			if ( is_wp_error($journal) ) {
				return $journal;
			}

			if ( ! is_dir($path) ) {
				$result = cli_concurrency_run(array('git', 'worktree', 'add', '-b', $branch, $path, 'HEAD'), $this->workspace . '/' . $repo);
				if ( 0 !== $result['status'] ) {
					return new WP_Error('git_worktree_add_failed', $result['error'], array('handle' => $handle, 'mutation_committed' => false));
				}
			}

			$persisted = SqliteBusyRetry::run('worktree_inventory_upsert', function () use ($handle): bool {
				return $this->writeRegistry(static function (PDO $pdo) use ($handle): bool {
					$statement = $pdo->prepare('UPDATE registry SET state = ? WHERE handle = ?');
					$statement->execute(array('created', $handle));
					return 1 === $statement->rowCount();
				});
			});
			if ( is_wp_error($persisted) ) {
				$data = array_merge((array) $persisted->get_error_data(), array('handle' => $handle, 'path' => $path, 'creation_intent_persisted' => true));
				return new WP_Error($persisted->get_error_code(), $persisted->get_error_message(), $data);
			}

			return array('success' => true, 'handle' => $handle, 'path' => $path, 'branch' => $branch, 'created_branch' => true);
		}

		private function writeRegistry(callable $write): bool {
			global $wpdb;
			try {
				$result = $write($wpdb->pdo);
				usleep(75000);
				$wpdb->last_error = '';
				return $result;
			} catch (PDOException $error) {
				$wpdb->last_error = $error->getMessage();
				return false;
			}
		}
	}

	function cli_concurrency_assert(bool $condition, string $message): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}

	/** @return array{status:int,output:string,error:string} */
	function cli_concurrency_run(array $command, ?string $cwd = null): array {
		$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
		cli_concurrency_assert(is_resource($process), 'Could not start process: ' . implode(' ', $command));
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array('status' => proc_close($process), 'output' => trim($output), 'error' => trim($error));
	}

	function cli_concurrency_remove_tree(string $path): void {
		if ( ! is_dir($path) || is_link($path) ) { @unlink($path); return; }
		foreach (scandir($path) ?: array() as $entry) {
			if ('.' !== $entry && '..' !== $entry) { cli_concurrency_remove_tree($path . '/' . $entry); }
		}
		@rmdir($path);
	}

	function cli_concurrency_wait(string $path): void {
		$deadline = microtime(true) + 5;
		while ( ! is_file($path) && microtime(true) < $deadline ) { usleep(10000); }
		cli_concurrency_assert(is_file($path), 'Timed out waiting for process signal: ' . $path);
	}

	function cli_concurrency_start(array $arguments): array {
		$process = proc_open(array_merge(array(PHP_BINARY, __FILE__), $arguments), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		cli_concurrency_assert(is_resource($process), 'Could not start CLI concurrency worker.');
		return array($process, $pipes);
	}

	function cli_concurrency_finish(array $worker, int $expected_status = 0): array {
		[$process, $pipes] = $worker;
		$output = stream_get_contents($pipes[1]);
		$error  = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		cli_concurrency_assert($expected_status === $status, sprintf('CLI worker exited %d instead of %d: %s', $status, $expected_status, $error));
		cli_concurrency_assert(! str_contains(strtolower($output . $error), 'database is locked') && ! str_contains(strtolower($output . $error), 'sqlstate'), 'Raw SQLite diagnostics escaped the public CLI boundary.');
		return array('output' => trim($output), 'error' => trim($error));
	}

	function cli_concurrency_configure(string $database, string $workspace, int $max_wait_ms): void {
		$pdo = new PDO('sqlite:' . $database);
		$pdo->exec('PRAGMA busy_timeout = 0');
		$GLOBALS['wpdb'] = new Cli_Concurrency_Wpdb($pdo);
		$GLOBALS['cli_concurrency_filters'] = array(
			'datamachine_code_sqlite_busy_retry_max_wait_ms' => $max_wait_ms,
			'datamachine_code_sqlite_registry_lock_path'     => $workspace . '/.locks/workspace-registry-writer.lock',
		);
	}

	$mode = (string) ($argv[1] ?? 'test');
	if ( 'cli' === $mode ) {
		[$database, $workspace, $repo, $branch, $max_wait_ms] = array_slice($argv, 2);
		cli_concurrency_configure($database, $workspace, (int) $max_wait_ms);
		$GLOBALS['cli_concurrency_ability'] = new Cli_Concurrency_Add_Ability($workspace);
		try {
			(new WorkspaceCommand())->__worktree_operation('add', array($repo, $branch), array('format' => 'json', 'skip-bootstrap' => true, 'skip-context-injection' => true));
			exit(0);
		} catch (Cli_Concurrency_Halt $halt) {
			exit($halt->status);
		}
	}

	if ( 'holder' === $mode ) {
		[$database, $workspace, $ready, $release] = array_slice($argv, 2);
		cli_concurrency_configure($database, $workspace, 5000);
		$result = SqliteBusyRetry::run('competing_registry_writer', static function () use ($ready, $release): bool {
			file_put_contents($ready, 'ready');
			$deadline = microtime(true) + 5;
			while ( ! is_file($release) && microtime(true) < $deadline ) { usleep(10000); }
			return is_file($release);
		});
		exit(true === $result ? 0 : 2);
	}

	$database = tempnam(sys_get_temp_dir(), 'dmc-cli-concurrency-');
	$workspace = sys_get_temp_dir() . '/dmc-cli-concurrency-' . bin2hex(random_bytes(6));
	cli_concurrency_assert(false !== $database && mkdir($workspace), 'Could not create CLI concurrency fixtures.');
	mkdir($workspace . '/.locks');

	try {
		$setup = new PDO('sqlite:' . $database);
		$setup->exec('CREATE TABLE registry (handle TEXT PRIMARY KEY, repo TEXT, branch TEXT, path TEXT, state TEXT)');
		foreach (array('homeboy', 'blocks-engine', 'static-site-importer') as $repo) {
			mkdir($workspace . '/' . $repo);
			cli_concurrency_assert(0 === cli_concurrency_run(array('git', 'init', '--initial-branch=main'), $workspace . '/' . $repo)['status'], 'Could not initialize ' . $repo);
			cli_concurrency_run(array('git', 'config', 'user.email', 'test@example.test'), $workspace . '/' . $repo);
			cli_concurrency_run(array('git', 'config', 'user.name', 'DMC Test'), $workspace . '/' . $repo);
			cli_concurrency_assert(0 === cli_concurrency_run(array('git', 'commit', '--allow-empty', '-m', 'fixture'), $workspace . '/' . $repo)['status'], 'Could not commit ' . $repo);
		}

		$workers = array();
		foreach (array('homeboy', 'blocks-engine', 'static-site-importer') as $repo) {
			$workers[$repo] = cli_concurrency_start(array('cli', $database, $workspace, $repo, 'fix/1268-' . $repo, '2000'));
		}
		foreach ($workers as $repo => $worker) {
			$result = cli_concurrency_finish($worker);
			$payload = json_decode($result['output'], true);
			cli_concurrency_assert(true === ($payload['success'] ?? null), 'Concurrent public CLI allocation failed for ' . $repo . ': ' . $result['output']);
			cli_concurrency_assert(is_dir((string) ($payload['path'] ?? '')) && is_file((string) ($payload['path'] ?? '') . '/.git'), 'Public CLI did not provision a Git worktree for ' . $repo);
		}
		cli_concurrency_assert(3 === (int) $setup->query("SELECT COUNT(*) FROM registry WHERE state = 'created'")->fetchColumn(), 'Concurrent public CLI allocations left incomplete registry identities.');

		$ready   = $workspace . '/holder-ready';
		$release = $workspace . '/holder-release';
		$holder  = cli_concurrency_start(array('holder', $database, $workspace, $ready, $release));
		cli_concurrency_wait($ready);
		$queued = cli_concurrency_finish(cli_concurrency_start(array('cli', $database, $workspace, 'homeboy', 'fix/1268-queued-retry', '100')), 1);
		$receipt = json_decode($queued['output'], true);
		$data    = (array) ($receipt['error']['data'] ?? array());
		cli_concurrency_assert('workspace_sqlite_lock_contention' === ($receipt['error']['code'] ?? null), 'Public CLI lost the typed registry queue result.');
		cli_concurrency_assert('workspace_registry_writer' === ($data['blocker_phase'] ?? null) && 'queued' === ($data['queue_state'] ?? null), 'Public CLI lost the registry blocker phase or queue state.');
		cli_concurrency_assert('competing_registry_writer' === ($data['blocker']['operation'] ?? null) && 0 < (int) ($data['blocker']['pid'] ?? 0), 'Public CLI omitted compact blocker ownership.');
		cli_concurrency_assert(1 === ($data['retry_after_seconds'] ?? null) && false === ($data['mutation_committed'] ?? null), 'Public CLI omitted retry-after or fail-closed mutation evidence.');
		file_put_contents($release, 'release');
		cli_concurrency_finish($holder);

		$replayed = cli_concurrency_finish(cli_concurrency_start(array('cli', $database, $workspace, 'homeboy', 'fix/1268-queued-retry', '1000')));
		$replayed_payload = json_decode($replayed['output'], true);
		cli_concurrency_assert(true === ($replayed_payload['success'] ?? null), 'Serial CLI retry remained blocked after the competing writer exited.');
		cli_concurrency_assert(1 === (int) $setup->query("SELECT COUNT(*) FROM registry WHERE handle = 'homeboy@fix-1268-queued-retry' AND state = 'created'")->fetchColumn(), 'Serial recovery duplicated or omitted the allocation identity.');

		fwrite(STDOUT, "worktree-add-cli-sqlite-concurrency ok\n");
	} finally {
		cli_concurrency_remove_tree($workspace);
		@unlink($database);
	}
}
