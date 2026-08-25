<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
$GLOBALS['bootstrap_demand_filters'] = array();
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$filter = $GLOBALS['bootstrap_demand_filters'][ $hook ] ?? null;
	return is_callable($filter) ? $filter($value, ...$args) : $value;
}

require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
require_once dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeBootstrapper.php';

use DataMachineCode\Workspace\WorktreeBootstrapper;

function bootstrap_demand_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$fixture = sys_get_temp_dir() . '/dmc-bootstrap-demand-' . bin2hex(random_bytes(6));
$bin = $fixture . '/bin';
$git_log = $fixture . '/git.log';
$repo = $fixture . '/repo';
$rebase_worktree = $fixture . '/rebased';
mkdir($fixture . '/frontend', 0777, true);
mkdir($fixture . '/php', 0777, true);
mkdir($bin, 0777, true);
mkdir($repo, 0777, true);
file_put_contents($fixture . '/package-lock.json', '{}');
file_put_contents($fixture . '/frontend/pnpm-lock.yaml', '');
file_put_contents($fixture . '/php/composer.lock', '{}');
file_put_contents($fixture . '/.gitmodules', "[submodule \"dependency\"]\n\tpath = dependency\n\turl = https://example.invalid/dependency.git\n");

try {
	$bare = WorktreeBootstrapper::demand_plan($fixture, false);
	bootstrap_demand_assert(256 === $bare['inodes'], 'Bare creation must retain the Git mutation/index-lock inode reserve.');
	bootstrap_demand_assert(0 === $bare['counts']['package_roots'], 'Bare creation must not budget disabled package bootstrap.');

	$bootstrap = WorktreeBootstrapper::demand_plan($fixture, true);
	bootstrap_demand_assert(1 === $bootstrap['counts']['submodules'], 'Demand must derive declared submodules from detect planning.');
	bootstrap_demand_assert(2 === $bootstrap['counts']['package_roots'], 'Demand must derive package roots from detect planning.');
	bootstrap_demand_assert(1 === $bootstrap['counts']['composer_roots'], 'Demand must derive Composer roots from detect planning.');
	bootstrap_demand_assert($bootstrap['inodes'] > $bare['inodes'], 'Bootstrap demand must exceed the Git-only reserve.');
	bootstrap_demand_assert('conservative_defaults' === $bootstrap['source'], 'Standalone fallback source must be explicit.');
	bootstrap_demand_assert(str_contains($bootstrap['fallback_semantics'], 'without_wordpress'), 'Fallback semantics must be explicit.');

	exec('git -C ' . escapeshellarg($repo) . ' init -q && git -C ' . escapeshellarg($repo) . ' config user.email test@example.com && git -C ' . escapeshellarg($repo) . ' config user.name Test');
	file_put_contents($repo . '/README.md', "primary\n");
	exec('git -C ' . escapeshellarg($repo) . ' add README.md && git -C ' . escapeshellarg($repo) . ' commit -qm primary');
	$primary_branch = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' branch --show-current'));
	exec('git -C ' . escapeshellarg($repo) . ' checkout -qb target-tree');
	mkdir($repo . '/frontend', 0777, true);
	file_put_contents($repo . '/frontend/package-lock.json', '{}');
	file_put_contents($repo . '/composer.lock', '{}');
	for ( $index = 0; $index < 300; ++$index ) {
		file_put_contents($repo . '/frontend/tracked-' . $index . '.txt', 'x');
	}
	$submodule_commit = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD'));
	exec('git -C ' . escapeshellarg($repo) . ' add frontend composer.lock && git -C ' . escapeshellarg($repo) . ' update-index --add --cacheinfo 160000,' . $submodule_commit . ',dependency && git -C ' . escapeshellarg($repo) . ' commit -qm target');
	exec('git -C ' . escapeshellarg($repo) . ' checkout -q ' . escapeshellarg($primary_branch));

	$primary_tree = WorktreeBootstrapper::demand_plan_for_target($repo, $primary_branch, true);
	$target_tree  = WorktreeBootstrapper::demand_plan_for_target($repo, 'target-tree', true);
	bootstrap_demand_assert(! is_wp_error($primary_tree) && ! is_wp_error($target_tree), 'Both primary and differing target refs must resolve before admission.');
	bootstrap_demand_assert(0 === $primary_tree['counts']['package_roots'] && 1 === $target_tree['counts']['package_roots'], 'Dependency demand must come from the target tree rather than the current primary checkout.');
	bootstrap_demand_assert(1 === $target_tree['counts']['composer_roots'] && 1 === $target_tree['counts']['submodules'], 'Target-tree planning must detect Composer and submodule capacity from Git metadata.');
	bootstrap_demand_assert($target_tree['counts']['tracked_entries'] > 256, 'Target-tree tracked entry demand fixture must exceed the old fixed Git reserve.');
	bootstrap_demand_assert($target_tree['inodes'] >= $target_tree['counts']['tracked_entries'] + $target_tree['git_safety_margin']['inodes'], 'Admission must reserve tracked materialization plus an explicit Git lock margin.');
	bootstrap_demand_assert($target_tree['target_commit'] !== $primary_tree['target_commit'], 'Differing refs must retain their exact resolved commits in demand evidence.');
	bootstrap_demand_assert('exact_git_blob_sizes' === $target_tree['tracked_bytes_source'], 'Full clones must retain exact tracked-byte accounting.');

	$system_git = trim((string) shell_exec('command -v git'));
	file_put_contents($bin . '/git', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> " . escapeshellarg($git_log) . "\nexec " . escapeshellarg($system_git) . " \"$@\"\n");
	chmod($bin . '/git', 0700);
	$original_path = (string) getenv('PATH');
	putenv('PATH=' . $bin . PATH_SEPARATOR . $original_path);
	$logged_full_tree = WorktreeBootstrapper::demand_plan_for_target($repo, 'target-tree', true);
	putenv('PATH=' . $original_path);
	bootstrap_demand_assert(! is_wp_error($logged_full_tree), 'Full-clone command fixture must remain planable.');
	$full_commands = (string) file_get_contents($git_log);
	bootstrap_demand_assert(str_contains($full_commands, 'ls-tree -r -t -l -z --full-tree ' . $logged_full_tree['target_commit']), 'Full clones must inspect target trees with exact blob sizes.');

	exec('git -C ' . escapeshellarg($repo) . ' config remote.origin.promisor true');
	exec('git -C ' . escapeshellarg($repo) . ' config remote.origin.partialclonefilter blob:none');
	file_put_contents($git_log, '');
	putenv('PATH=' . $bin . PATH_SEPARATOR . $original_path);
	$blobless_tree = WorktreeBootstrapper::demand_plan_for_target($repo, 'target-tree', true);
	putenv('PATH=' . $original_path);
	bootstrap_demand_assert(! is_wp_error($blobless_tree), 'Blobless target-tree command fixture must remain planable.');
	$blobless_commands = (string) file_get_contents($git_log);
	bootstrap_demand_assert(str_contains($blobless_commands, 'config --get-regexp ^remote\\..*\\.(promisor|partialclonefilter)$'), 'Blobless detection must inspect only promisor filter metadata.');
	bootstrap_demand_assert(str_contains($blobless_commands, 'ls-tree -r -t -z --full-tree ' . $blobless_tree['target_commit']) && ! str_contains($blobless_commands, 'ls-tree -r -t -l -z --full-tree '), 'Blobless clones must inspect target metadata without requesting blob sizes.');
	bootstrap_demand_assert('conservative_blobless_entry_estimate' === $blobless_tree['tracked_bytes_source'], 'Blobless plans must expose their conservative tracked-byte contract.');
	bootstrap_demand_assert($blobless_tree['tracked_bytes'] === $blobless_tree['counts']['tracked_entries'] * 65536, 'Blobless plans must reserve the documented conservative byte estimate for every tracked entry.');
	bootstrap_demand_assert(65536 === $blobless_tree['tracked_bytes_per_entry'], 'Blobless plans must expose the per-entry estimate used for capacity review.');
	bootstrap_demand_assert($blobless_tree['counts']['package_roots'] === $target_tree['counts']['package_roots'] && $blobless_tree['counts']['composer_roots'] === $target_tree['counts']['composer_roots'] && $blobless_tree['counts']['submodules'] === $target_tree['counts']['submodules'], 'Metadata-only plans must preserve package, Composer, and submodule capacity detection.');
	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_blobless_tracked_entry_bytes'] = static fn() => 32768;
	$filtered_blobless_tree = WorktreeBootstrapper::demand_plan_for_target($repo, 'target-tree', true);
	unset($GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_blobless_tracked_entry_bytes']);
	bootstrap_demand_assert($filtered_blobless_tree['tracked_bytes'] === $filtered_blobless_tree['counts']['tracked_entries'] * 32768, 'Installations must be able to tune the conservative blobless estimate without changing core.');
	bootstrap_demand_assert(300 === WorktreeBootstrapper::target_tree_timeout_seconds($repo), 'Large target-tree inspection must use its operation-appropriate default budget.');
	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_target_tree_timeout_seconds'] = static fn() => 77;
	bootstrap_demand_assert(77 === WorktreeBootstrapper::target_tree_timeout_seconds($repo), 'Target-tree inspection timeout must be explicitly filterable.');
	unset($GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_target_tree_timeout_seconds']);

	exec('git -C ' . escapeshellarg($repo) . ' checkout -qb stale-branch');
	file_put_contents($repo . '/stale.txt', 'stale');
	exec('git -C ' . escapeshellarg($repo) . ' add stale.txt && git -C ' . escapeshellarg($repo) . ' commit -qm stale');
	exec('git -C ' . escapeshellarg($repo) . ' checkout -q ' . escapeshellarg($primary_branch));
	mkdir($repo . '/upstream-package', 0777, true);
	file_put_contents($repo . '/upstream-package/composer.lock', '{}');
	file_put_contents($repo . '/upstream-package/upstream.txt', 'upstream');
	exec('git -C ' . escapeshellarg($repo) . ' add upstream-package && git -C ' . escapeshellarg($repo) . ' commit -qm upstream');
	$stale_tree = WorktreeBootstrapper::demand_plan_for_target($repo, 'stale-branch', true);
	exec('git -C ' . escapeshellarg($repo) . ' worktree add -q ' . escapeshellarg($rebase_worktree) . ' stale-branch');
	exec('git -C ' . escapeshellarg($rebase_worktree) . ' rebase ' . escapeshellarg($primary_branch));
	$rebased_tree = WorktreeBootstrapper::demand_plan_for_target($rebase_worktree, 'HEAD', true);
	$remaining_rebased = WorktreeBootstrapper::remaining_demand_after_materialization($rebased_tree);
	bootstrap_demand_assert(0 === $stale_tree['counts']['composer_roots'] && 1 === $rebased_tree['counts']['composer_roots'], 'Post-rebase demand must include dependency roots introduced only by upstream.');
	bootstrap_demand_assert($rebased_tree['counts']['tracked_entries'] > $stale_tree['counts']['tracked_entries'], 'Post-rebase target inspection must include tracked upstream materialization.');
	bootstrap_demand_assert($remaining_rebased['inodes'] === $rebased_tree['inodes'] - $rebased_tree['counts']['tracked_entries'], 'Post-rebase re-admission must project only future dependency and Git-margin demand after remeasuring materialized files.');
	exec('git -C ' . escapeshellarg($repo) . ' worktree remove --force ' . escapeshellarg($rebase_worktree));

	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_bootstrap_command_timeout_seconds'] = static fn() => 1;
	bootstrap_demand_assert(1 === WorktreeBootstrapper::command_timeout_seconds('submodules'), 'Bootstrap command timeout must be explicitly filterable.');
	$GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_capacity_wait_timeout_seconds'] = static fn() => 77;
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';
	$lock_policy = new class { use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle; };
	bootstrap_demand_assert(77 === $lock_policy::worktree_capacity_wait_timeout_seconds(true), 'Capacity admission wait must use its explicit filter instead of the lock default.');
	$lifecycle_source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php');
	bootstrap_demand_assert(str_contains((string) $lifecycle_source, '$operation_deadline = $operation_started + $operation_timeout;') && str_contains((string) $lifecycle_source, 'worktree_capacity_admission_wait_seconds($operation_deadline, null, $bootstrap)'), 'Admission must derive its capacity lock wait from the shared operation deadline.');
	bootstrap_demand_assert(str_contains((string) $lifecycle_source, 'remaining_demand_after_materialization') && str_contains((string) $lifecycle_source, "'post_rebase_admission'"), 'Successful rebase must remeasure and re-run admission before bootstrap.');
	bootstrap_demand_assert(str_contains((string) $lifecycle_source, "'rebase --abort', min(5, \$abort_remaining)") && str_contains((string) $lifecycle_source, "'rebase_cleanup_failed' => is_wp_error(\$abort)"), 'Rebase cleanup must be bounded and its failure must be surfaced.');
	unset($GLOBALS['bootstrap_demand_filters']['datamachine_code_worktree_capacity_wait_timeout_seconds']);
	bootstrap_demand_assert(WorktreeBootstrapper::total_timeout_seconds() + 600 === $lock_policy::worktree_capacity_wait_timeout_seconds(true), 'Capacity wait must exceed the complete bounded bootstrap lifecycle.');

	$git = $bin . '/git';
	file_put_contents($git, "#!/bin/sh\nexec sleep 5\n");
	chmod($git, 0700);
	$original_path = (string) getenv('PATH');
	putenv('PATH=' . $bin . PATH_SEPARATOR . $original_path);
	$started = microtime(true);
	$run_command = new ReflectionMethod(WorktreeBootstrapper::class, 'run_command');
	$submodule = $run_command->invoke(null, 'submodules', $fixture, 'git submodule update --init --recursive');
	putenv('PATH=' . $original_path);
	bootstrap_demand_assert('command_timeout' === ( $submodule['reason'] ?? '' ), 'Bounded bootstrap failure must expose a stable timeout reason.');
	bootstrap_demand_assert(true === ( $submodule['timed_out'] ?? false ) && 1 === ( $submodule['timeout_seconds'] ?? null ), 'Bootstrap timeout evidence must be structured.');
	bootstrap_demand_assert(microtime(true) - $started < 3, 'Bootstrap command must stop within its finite deadline.');

	echo "worktree-bootstrap-demand: ok\n";
} finally {
	if ( is_dir($rebase_worktree) ) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rebase_worktree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ( $iterator as $entry ) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
		rmdir($rebase_worktree);
	}
	if ( is_dir($repo) ) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ( $iterator as $entry ) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($repo);
	}
	foreach ( array( 'package-lock.json', 'frontend/pnpm-lock.yaml', 'php/composer.lock', '.gitmodules' ) as $file ) {
		unlink($fixture . '/' . $file);
	}
	if ( is_file($bin . '/git') ) { unlink($bin . '/git'); }
	if ( is_file($git_log) ) { unlink($git_log); }
	rmdir($bin);
	rmdir($fixture . '/frontend');
	rmdir($fixture . '/php');
	rmdir($fixture);
}
