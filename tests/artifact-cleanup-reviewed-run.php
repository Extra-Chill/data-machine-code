<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): mixed { return $this->data; }
		}
	}

	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
	function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string { return substr(str_repeat('a', $length), 0, $length); }
	function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
}

namespace DataMachineCode\Workspace {
	require_once dirname(__DIR__) . '/inc/Support/JsonCodec.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupPlan.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

	final class Workspace {
		use WorkspaceArtifactCleanup;
		use WorkspaceCleanupPlan;
		use WorkspaceWorktreeCleanupEngine;

		public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
		public const CLEANUP_PLAN_DEFAULT_LIMIT = 100;
		public const CLEANUP_PLAN_DEFAULT_BUDGET = '30s';
		public const METADATA_RECONCILE_DEFAULT_LIMIT = 25;
		public const METADATA_RECONCILE_DEFAULT_BUDGET = '30s';
		protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;
		protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
		protected const CLEANUP_GITHUB_TIMEOUT = 5;
		protected const CLEANUP_GITHUB_MAX_PAGES = 3;
		protected const CLEANUP_SUMMARY_TOP_LIMIT = 10;

		public array $rows = array();
		public array $dirty = array();
		public array $unpushed = array();
		public string $workspace_path;

		public function __construct(string $workspace_path) { $this->workspace_path = $workspace_path; }

		public function worktree_cleanup_merged(array $opts = array()): array {
			return array('candidates' => array(), 'removed' => array(), 'skipped' => array(), 'summary' => array());
		}

		private function build_workspace_inventory_rows(): array { return $this->rows; }
		private function resolve_worktree_branch_from_head_file(string $path): ?string {
			foreach ($this->rows as $row) {
				if (($row['path'] ?? '') === $path) {
					return (string) ($row['branch'] ?? '');
				}
			}
			return null;
		}
		private function probe_worktree_dirty_count(string $path, int $timeout = 0): int|\WP_Error {
			unset($timeout);
			$status = trim((string) ($this->dirty[$path] ?? ''));
			return '' === $status ? 0 : count(explode("\n", $status));
		}
		private function count_unpushed_commits(string $path, int $timeout = 0): int|\WP_Error {
			unset($timeout);
			return (int) ($this->unpushed[$path] ?? 0);
		}
		private function run_git(string $path, string $command, int $timeout = 0): array|\WP_Error {
			unset($timeout);
			return array('output' => str_starts_with($command, 'status --porcelain') ? (string) ($this->dirty[$path] ?? '') : '');
		}
		private function classify_worktree_git_probe_failure(string $handle, string $repo, string $path, \WP_Error $error, string $probe, string $action): array {
			return array(
				'handle' => $handle,
				'repo' => $repo,
				'path' => $path,
				'reason_code' => $error->get_error_code(),
				'reason' => sprintf('%s failed; %s: %s', $probe, $action, $error->get_error_message()),
			);
		}
		public function validate_containment(string $target, string $container): array {
			$real_target = realpath($target);
			$real_root = realpath($container);
			$valid = is_string($real_target) && is_string($real_root)
				&& ($real_target === $real_root || str_starts_with($real_target, rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
			return array('valid' => $valid, 'real_path' => $valid ? $real_target : null, 'message' => $valid ? '' : 'outside fixture workspace');
		}
	}
}

namespace {
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepository.php';
	require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';
	require_once dirname(__DIR__) . '/inc/Workspace/CleanupRunService.php';

	final class ReviewedArtifactRepository extends DataMachineCode\Storage\CleanupRunRepository {
		public array $runs = array();
		public array $items = array();
		private int $next_id = 1;

		public function create_run(array $run): string|WP_Error {
			$id = 'cleanup-run-reviewed';
			$this->runs[$id] = $run + array('run_id' => $id);
			return $id;
		}
		public function add_items(string $run_id, array $items): int|WP_Error {
			foreach ($items as $item) {
				$this->items[$run_id][] = $item + array('id' => $this->next_id++);
			}
			return count($items);
		}
		public function get_run(string $run_id): ?array { return $this->runs[$run_id] ?? null; }
		public function get_items(string $run_id): array { return $this->items[$run_id] ?? array(); }
		public function update_run(string $run_id, array $fields): bool {
			$this->runs[$run_id] = array_merge($this->runs[$run_id], $fields);
			return true;
		}
		public function update_item(int $id, array $fields): bool {
			foreach ($this->items as &$items) {
				foreach ($items as &$item) {
					if ($id === $item['id']) {
						$item = array_merge($item, $fields);
						return true;
					}
				}
			}
			return false;
		}
	}

	function reviewed_artifact_assert(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
		}
	}

	function reviewed_artifact_remove_tree(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $entry) {
			$child = $path . '/' . $entry;
			is_dir($child) ? reviewed_artifact_remove_tree($child) : unlink($child);
		}
		rmdir($path);
	}

	$root = sys_get_temp_dir() . '/dmc-reviewed-artifact-' . getmypid();
	mkdir($root, 0777, true);
	$workspace = new DataMachineCode\Workspace\Workspace($root);

	$specs = array(
		'a-empty' => 0,
		'b-empty' => 0,
		'v-changed' => 70000,
		'w-unpushed' => 60000,
		'x-source' => 50000,
		'y-artifact-dirty' => 40000,
		'z-clean' => 30000,
	);
	foreach ($specs as $slug => $bytes) {
		$path = $root . '/repo@' . $slug;
		mkdir($path, 0777, true);
		if ($bytes > 0) {
			file_put_contents($path . '/composer.json', '{}');
			mkdir($path . '/vendor', 0777, true);
			file_put_contents($path . '/vendor/generated.bin', str_repeat('x', $bytes));
		}
		$workspace->rows[] = array(
			'handle' => 'repo@' . $slug,
			'repo' => 'repo',
			'branch' => 'test/' . $slug,
			'path' => $path,
			'is_worktree' => true,
			'is_primary' => false,
		);
	}

	$preview = $workspace->worktree_cleanup_artifacts(array('dry_run' => true, 'sort' => 'size', 'limit' => 2));
	$plan_page = $workspace->workspace_cleanup_plan(array('mode' => 'artifacts', 'include_worktrees' => false, 'limit' => 2));
	reviewed_artifact_assert(
		array_column($preview['candidates'], 'artifact_size_bytes', 'handle'),
		array_column($plan_page['rows']['artifact_cleanup'], 'artifact_size_bytes', 'handle'),
		'size-ranked preview and high-level plan must preserve identical candidate identities and sizes'
	);
	reviewed_artifact_assert(2, $plan_page['continuation']['next_offset'] ?? null, 'ranked candidate pagination should continue at the next candidate');
	$page_two = $workspace->workspace_cleanup_plan(array('mode' => 'artifacts', 'include_worktrees' => false, 'limit' => 2, 'offset' => 2));
	reviewed_artifact_assert(
		array('repo@x-source', 'repo@y-artifact-dirty'),
		array_column($page_two['rows']['artifact_cleanup'], 'handle'),
		'second ranked page should contain the next artifact candidates, not the next raw worktree handles'
	);

	$workspace->dirty[$root . '/repo@y-artifact-dirty'] = "?? vendor/\n";
	$workspace->dirty[$root . '/repo@x-source'] = " M source.php\n?? vendor/\n";
	$workspace->unpushed[$root . '/repo@w-unpushed'] = 1;
	file_put_contents($root . '/repo@x-source/source.php', '<?php // preserve');

	$repository = new ReviewedArtifactRepository();
	$service = new DataMachineCode\Workspace\CleanupRunService($repository, $workspace);
	$reviewed = $service->plan(array(
		'mode' => 'artifacts',
		'include_artifacts' => true,
		'include_worktrees' => false,
		'include_resolvers' => false,
		'limit' => 10,
	));
	reviewed_artifact_assert(5, $reviewed['cleanup_storage']['item_count'] ?? null, 'all ranked artifact candidates should persist as reviewed cleanup rows');

	file_put_contents($root . '/repo@v-changed/vendor/generated.bin', str_repeat('y', 140000));
	$applied = $service->apply('cleanup-run-reviewed', array('limit' => 10));
	reviewed_artifact_assert(2, $applied['applied'] ?? null, 'clean and artifact-only dirty rows should apply: ' . json_encode($applied['results'] ?? array()));
	reviewed_artifact_assert(3, $applied['skipped'] ?? null, 'changed, source-dirty, and unpushed rows should be skipped');
	reviewed_artifact_assert(false, is_dir($root . '/repo@z-clean/vendor'), 'clean reconstructable artifact should be removed');
	reviewed_artifact_assert(false, is_dir($root . '/repo@y-artifact-dirty/vendor'), 'artifact-only dirty path should be removed');
	reviewed_artifact_assert(true, is_file($root . '/repo@x-source/source.php'), 'dirty source file must be preserved');
	reviewed_artifact_assert(true, is_dir($root . '/repo@x-source/vendor'), 'source-dirty worktree artifact must remain blocked');
	reviewed_artifact_assert(true, is_dir($root . '/repo@w-unpushed/vendor'), 'unpushed worktree artifact must remain blocked');
	reviewed_artifact_assert(true, is_dir($root . '/repo@v-changed/vendor'), 'artifact changed after review must remain blocked');

	$items = $repository->get_items('cleanup-run-reviewed');
	$reasons = array_column($items, 'reason_code', 'handle');
	reviewed_artifact_assert('artifact_plan_mismatch', $reasons['repo@v-changed'] ?? null, 'changed artifact size should fail reviewed snapshot revalidation');
	reviewed_artifact_assert('dirty_worktree', $reasons['repo@x-source'] ?? null, 'source dirt should retain the dirty-worktree protection');
	reviewed_artifact_assert('unpushed_commits', $reasons['repo@w-unpushed'] ?? null, 'unpushed commits should retain their protection');
	reviewed_artifact_assert(
		(int) ($applied['results']['artifact_cleanup']['summary']['removed_size_bytes'] ?? 0),
		(int) ($applied['summary']['bytes_reclaimed'] ?? 0),
		'persisted status bytes should match measured artifact removal bytes'
	);
	reviewed_artifact_assert(5, $applied['cleanup_items']['planned_rows'] ?? null, 'status should report persisted planned rows');
	reviewed_artifact_assert(2, $applied['cleanup_items']['applied_rows'] ?? null, 'status should report applied rows');
	reviewed_artifact_assert(3, $applied['cleanup_items']['skipped_rows'] ?? null, 'status should report skipped rows');

	$drained = $service->apply('cleanup-run-reviewed', array('limit' => 10));
	reviewed_artifact_assert('completed', $drained['state'] ?? null, 'repeated drain/apply should remain terminal after all reviewed rows are recorded');
	reviewed_artifact_assert(0, $drained['processed'] ?? null, 'terminal drain/apply should process no duplicate rows');

	reviewed_artifact_remove_tree($root);
	fwrite(STDOUT, "artifact-cleanup-reviewed-run ok\n");
}
