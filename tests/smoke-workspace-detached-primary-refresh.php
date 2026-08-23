<?php
/**
 * Real-git integration coverage for safe detached authoritative-primary refresh.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorkspaceAliasResolver {
		public static function is_context_repository( string $handle ): bool { return false; }
		public static function mutation_error( string $handle, string $operation ): array { return array( 'error' => $operation ); }
	}
	final class WorktreeContextInjector {
		public static array $metadata = array();
		public static bool $fail_store = false;
		public static function build_lifecycle_metadata( array $metadata ): array { return $metadata; }
		public static function store_lifecycle_metadata( string $handle, array $metadata ): bool|\WP_Error { if ( self::$fail_store ) { return new \WP_Error('metadata_failed', 'metadata failed'); } self::$metadata[$handle] = $metadata; return true; }
		public static function get_metadata( string $handle ): ?array { return self::$metadata[$handle] ?? null; }
	}
}

namespace {
	if ( ! defined('ABSPATH') ) { define('ABSPATH', '/tmp'); }
	function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): array { return $this->data; }
	}
}

namespace DataMachineCode\Tests\DetachedPrimary {
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceGitOperations.php';

	use DataMachineCode\Workspace\WorkspaceGitOperations;

	final class RealGitWorkspace {
		use WorkspaceGitOperations;
		public function __construct( private string $path ) {}
		protected function parse_handle( string $handle ): array { return array( 'repo' => $handle, 'dir_name' => $handle, 'is_worktree' => false ); }
		protected function resolve_repo_path( string $handle ): string { return $this->path; }
		protected function ensure_git_mutation_allowed( string $repo ): true { return true; }
		protected function ensure_primary_mutation_allowed( array $parsed, bool $allow, string $message = '' ): true { return true; }
		public function git_status( string $handle ): array { return array( 'dirty' => '' === trim(run($this->path, 'status --porcelain')) ? 0 : 1 ); }
		protected function git_get_branch( string $path ): ?string { $branch = run_allow_fail($path, 'rev-parse --abbrev-ref HEAD'); return 0 === $branch['code'] ? trim($branch['output']) : null; }
		protected function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {}
		protected function run_git( string $path, string $args, int $timeout_seconds = 0 ): array|\WP_Error { $result = run_allow_fail($path, $args); return 0 === $result['code'] ? array( 'output' => $result['output'] ) : new \WP_Error('git_command_failed', $result['output']); }
	}

	function run_allow_fail( string $cwd, string $args ): array { exec(sprintf('cd %s && git %s 2>&1', escapeshellarg($cwd), $args), $out, $code); return array( 'code' => $code, 'output' => implode("\n", $out) ); }
	function run( string $cwd, string $args ): string { $result = run_allow_fail($cwd, $args); if ( 0 !== $result['code'] ) { throw new \RuntimeException("git $args failed: " . $result['output']); } return $result['output']; }
	function assert_true( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException($message); } }
	function assert_error( mixed $result, string $code ): void { assert_true($result instanceof \WP_Error && $result->get_error_code() === $code, 'expected ' . $code); }
	function rmrf( string $path ): void { if ( ! is_dir($path) ) { return; } $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST); foreach ( $it as $file ) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); } rmdir($path); }
	function fixture( string $root, string $branch = 'main' ): array { $origin = $root . '/origin.git'; $seed = $root . '/seed'; $primary = $root . '/primary'; run($root, 'init -q -b ' . escapeshellarg($branch) . ' --bare ' . escapeshellarg($origin)); mkdir($seed, 0700, true); run($seed, 'init -q -b ' . escapeshellarg($branch)); file_put_contents($seed . '/a', 'one'); run($seed, 'add a'); run($seed, '-c user.email=t@t -c user.name=t commit -qm one'); run($seed, 'remote add origin ' . escapeshellarg($origin)); run($seed, 'push -q origin ' . escapeshellarg($branch)); run($root, 'clone -q ' . escapeshellarg($origin) . ' ' . escapeshellarg($primary)); return array( $origin, $seed, $primary ); }
	function advance( string $seed, string $name, string $branch = 'main' ): void { file_put_contents($seed . '/' . $name, $name); run($seed, 'add ' . escapeshellarg($name)); run($seed, '-c user.email=t@t -c user.name=t commit -qm ' . escapeshellarg($name)); run($seed, 'push -q origin ' . escapeshellarg($branch)); }

	$root = dirname(__DIR__) . '/.dmc-987-tmp-' . bin2hex(random_bytes(4));
	mkdir($root, 0700, true);
	try {
		// A clean attached default branch that is both ahead and behind is retained
		// in a deterministic managed worktree before the primary is refreshed.
		mkdir($root . '/attached-diverged');
		[$_, $seed, $primary] = fixture($root . '/attached-diverged');
		file_put_contents($primary . '/local', 'local');
		run($primary, 'add local');
		run($primary, '-c user.email=t@t -c user.name=t commit -qm local');
		$local = trim(run($primary, 'rev-parse HEAD'));
		advance($seed, 'remote');
		$result   = (new RealGitWorkspace($primary))->git_pull('repo', false, true);
		$recovery = $result['primary_diverged'] ?? array();
		assert_true(! $result instanceof \WP_Error && $local === ($recovery['local_sha'] ?? '') && 1 === ($recovery['ahead'] ?? 0) && 1 === ($recovery['behind'] ?? 0), 'attached divergence was not classified: ' . var_export($result, true));
		assert_true(trim(run($primary, 'rev-parse HEAD')) === trim(run($primary, 'rev-parse origin/main')), 'attached divergent primary was not refreshed');
		assert_true(is_dir((string) ($recovery['preservation']['path'] ?? '')) && $local === trim(run((string) $recovery['preservation']['path'], 'rev-parse HEAD')), 'local-only tip was not preserved in recovery worktree');
		assert_true(('refs/heads/' . ($recovery['preservation']['branch'] ?? '')) === ($recovery['preservation']['ref'] ?? '') && $local === ($recovery['preservation']['commit'] ?? ''), 'recovery did not expose canonical preservation ref evidence');
		assert_true('active' === ($recovery['preservation']['metadata']['lifecycle_state'] ?? null), 'recovery worktree lifecycle metadata was not recorded');
		assert_true('origin/main' === trim(run($primary, 'rev-parse --abbrev-ref --symbolic-full-name @{upstream}')), 'implicit recovery changed primary tracking');

		// A matching explicit branch is equally eligible for managed recovery and
		// restores missing tracking for the refreshed default branch.
		mkdir($root . '/attached-explicit');
		[$_, $seed, $primary] = fixture($root . '/attached-explicit');
		run($primary, 'branch --unset-upstream'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); $local = trim(run($primary, 'rev-parse HEAD')); advance($seed, 'remote');
		$result = (new RealGitWorkspace($primary))->git_pull('repo', false, true, 'origin', 'main');
		assert_true(! $result instanceof \WP_Error && $local === ($result['primary_diverged']['preservation']['commit'] ?? '') && 'origin/main' === trim(run($primary, 'rev-parse --abbrev-ref --symbolic-full-name @{upstream}')), 'explicit default-branch recovery changed tracking or failed');

		// Ahead-only history remains a typed #1101 block, never an automatic reset.
		mkdir($root . '/attached-ahead');
		[$_, $_seed, $primary] = fixture($root . '/attached-ahead');
		file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); $ahead = trim(run($primary, 'rev-parse HEAD'));
		$ahead_result = (new RealGitWorkspace($primary))->git_pull('repo', false, true);
		assert_error($ahead_result, 'primary_refresh_ahead'); assert_true($ahead === trim(run($primary, 'rev-parse HEAD')) && 1 === ($ahead_result->get_error_data()['primary_freshness']['ahead'] ?? 0), 'ahead-only primary was not preserved as a typed block');

		// A custom remote and non-main remote default are eligible when the attached
		// checkout and requested branch match that remote default.
		mkdir($root . '/custom-default');
		[$_, $seed, $primary] = fixture($root . '/custom-default', 'trunk'); run($primary, 'remote rename origin upstream'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); advance($seed, 'remote', 'trunk');
		$result = (new RealGitWorkspace($primary))->git_pull('repo', false, true, 'upstream', 'trunk');
		assert_true(! $result instanceof \WP_Error && 'trunk' === trim(run($primary, 'branch --show-current')) && trim(run($primary, 'rev-parse HEAD')) === trim(run($primary, 'rev-parse upstream/trunk')), 'custom remote default recovery failed');

		// Non-default attached branches keep the #1101 typed divergence block.
		mkdir($root . '/non-default');
		[$_, $seed, $primary] = fixture($root . '/non-default'); run($seed, 'checkout -qb feature'); advance($seed, 'feature-local', 'feature'); run($primary, 'fetch -q origin'); run($primary, 'checkout -qb feature --track origin/feature'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); advance($seed, 'feature-remote', 'feature');
		$non_default = (new RealGitWorkspace($primary))->git_pull('repo', false, true);
		assert_error($non_default, 'primary_refresh_diverged');

		// An unrelated deterministic directory never counts as a preservation worktree.
		mkdir($root . '/collision');
		[$_, $seed, $primary] = fixture($root . '/collision'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); $local = trim(run($primary, 'rev-parse HEAD')); mkdir(dirname($primary) . '/repo@primary-recovery-' . substr($local, 0, 12)); advance($seed, 'remote');
		$collision = (new RealGitWorkspace($primary))->git_pull('repo', false, true);
		assert_error($collision, 'primary_divergence_recovery_path_conflict'); assert_true($local === trim(run($primary, 'rev-parse HEAD')) && ! trim(run($primary, 'branch --list recovery/primary-recovery-' . substr($local, 0, 12))), 'collision changed the primary or left a newly-created recovery ref');

		// Metadata persistence failures retain typed partial evidence. Retrying
		// verifies and reuses the exact existing recovery worktree.
		mkdir($root . '/metadata-retry');
		[$_, $seed, $primary] = fixture($root . '/metadata-retry'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); $local = trim(run($primary, 'rev-parse HEAD')); advance($seed, 'remote'); \DataMachineCode\Workspace\WorktreeContextInjector::$fail_store = true;
		$partial = (new RealGitWorkspace($primary))->git_pull('repo', false, true); \DataMachineCode\Workspace\WorktreeContextInjector::$fail_store = false;
		assert_error($partial, 'primary_divergence_recovery_metadata_failed'); $evidence = $partial->get_error_data()['preservation'] ?? array(); assert_true($local === ($evidence['commit'] ?? '') && is_dir((string) ($evidence['path'] ?? '')) && $local === trim(run($primary, 'rev-parse HEAD')), 'metadata failure omitted preserved partial state');
		$result = (new RealGitWorkspace($primary))->git_pull('repo', false, true);
		assert_true(! $result instanceof \WP_Error && true === ($result['primary_diverged']['preservation']['reused'] ?? false) && trim(run($primary, 'rev-parse HEAD')) === trim(run($primary, 'rev-parse origin/main')), 'recovery retry did not safely reuse the verified worktree');
		mkdir($root . '/attached-dirty');
		[$_, $seed, $primary] = fixture($root . '/attached-dirty');
		file_put_contents($primary . '/dirty', 'dirty');
		advance($seed, 'remote');
		assert_error((new RealGitWorkspace($primary))->git_pull('repo', false, true), 'dirty_working_tree');
		assert_true(! is_dir(dirname($primary) . '/repo@primary-recovery-' . substr(trim(run($primary, 'rev-parse HEAD')), 0, 12)), 'dirty attached primary created a recovery worktree');

		// A detached stale primary reattaches to origin/main and fast-forwards.
		mkdir($root . '/stale'); [$_, $seed, $primary] = fixture($root . '/stale'); run($primary, 'checkout -q --detach'); $before = run($primary, 'rev-parse HEAD'); advance($seed, 'two'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ($result['primary_repair']['head_before'] ?? '') === $before, 'stale detached primary was not repaired'); assert_true('main' === trim(run($primary, 'branch --show-current')), 'stale detached primary was not attached to main');

		// A valid local origin/HEAD is enough to select main before the network fetch.
		mkdir($root . '/local-head'); [$_, $seed, $primary] = fixture($root . '/local-head'); run($primary, 'checkout -q --detach'); advance($seed, 'two'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && 'main' === ($result['primary_repair']['branch'] ?? '') && 'validated' === ($result['primary_repair']['default_branch_sources'][0]['status'] ?? ''), 'valid local origin/HEAD did not resolve and report the default branch source');

		// A detached commit already at tip reattaches without moving backward.
		mkdir($root . '/tip'); [$_, $_seed, $primary] = fixture($root . '/tip'); run($primary, 'checkout -q --detach'); $before = run($primary, 'rev-parse HEAD'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && $before === trim(run($primary, 'rev-parse HEAD')), 'tip detached primary changed commit');

		// Differing detached and local branch ancestors can both advance to the verified remote tip.
		mkdir($root . '/contained'); [$_, $seed, $primary] = fixture($root . '/contained'); run($primary, 'checkout -q --detach'); advance($seed, 'two'); run($primary, 'fetch -q origin'); run($primary, 'branch -f main origin/main'); advance($seed, 'three'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ! empty($result['primary_repair']['branch_repointed']), 'contained local branch was not safely repointed'); assert_true(trim(run($primary, 'rev-parse main')) === trim(run($primary, 'rev-parse origin/main')), 'contained branch was not refreshed to origin');

		// A detached commit outside main is recoverable only when a freshly fetched remote ref preserves it.
		mkdir($root . '/remote-branch'); [$_, $_seed, $primary] = fixture($root . '/remote-branch'); run($primary, 'checkout -q --detach'); file_put_contents($primary . '/preserved', 'branch'); run($primary, 'add preserved'); run($primary, '-c user.email=t@t -c user.name=t commit -qm preserved'); $preserved = run($primary, 'rev-parse HEAD'); run($primary, 'push -q origin HEAD:refs/heads/preserve-detached'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ($result['primary_repair']['preservation']['ref'] ?? '') === 'refs/heads/preserve-detached' && ($result['primary_repair']['preservation']['commit'] ?? '') === $preserved, 'remote branch preservation was not reported: ' . var_export($result, true));
		mkdir($root . '/remote-tag'); [$_, $_seed, $primary] = fixture($root . '/remote-tag'); run($primary, 'checkout -q --detach'); file_put_contents($primary . '/tagged', 'tag'); run($primary, 'add tagged'); run($primary, '-c user.email=t@t -c user.name=t commit -qm tagged'); run($primary, 'tag preserve-detached'); run($primary, 'push -q origin refs/tags/preserve-detached'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ($result['primary_repair']['preservation']['ref'] ?? '') === 'refs/tags/preserve-detached', 'lightweight tag preservation was not reported');

		// Stale local tracking and local-only names are not preservation evidence.
		mkdir($root . '/deleted'); [$origin, $seed, $primary] = fixture($root . '/deleted'); run($primary, 'checkout -q --detach'); file_put_contents($primary . '/deleted', 'deleted'); run($primary, 'add deleted'); run($primary, '-c user.email=t@t -c user.name=t commit -qm deleted'); run($primary, 'push -q origin HEAD:refs/heads/deleted-preserver'); run($primary, 'fetch -q origin'); run($seed, 'push -q origin --delete deleted-preserver'); assert_error((new RealGitWorkspace($primary))->git_pull('repo', false, true), 'detached_primary_diverged');
		mkdir($root . '/local-only'); [$_, $_seed, $primary] = fixture($root . '/local-only'); run($primary, 'checkout -q --detach'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); run($primary, 'branch local-only'); assert_error((new RealGitWorkspace($primary))->git_pull('repo', false, true), 'detached_primary_diverged');

		// A holder, including a dirty holder, must remain untouched until explicitly removed; retry is idempotent after removal.
		mkdir($root . '/held'); [$_, $seed, $primary] = fixture($root . '/held'); run($primary, 'checkout -q --detach'); advance($seed, 'two'); run($primary, 'fetch -q origin'); run($primary, 'branch -f main origin/main'); $holder = $root . '/held/holder'; run($primary, 'worktree add -q ' . escapeshellarg($holder) . ' main'); file_put_contents($holder . '/dirty', 'dirty'); advance($seed, 'three'); $held = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_error($held, 'detached_primary_default_branch_held'); assert_true(($held->get_error_data()['holder']['path'] ?? '') === $holder && str_contains((string) ($held->get_error_data()['holder']['retry_command'] ?? ''), 'workspace git pull'), 'holder remediation is incomplete'); assert_true(file_exists($holder . '/dirty'), 'dirty holder was modified'); run($primary, 'worktree remove --force ' . escapeshellarg($holder)); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && trim(run($primary, 'rev-parse main')) === trim(run($primary, 'rev-parse origin/main')), 'retry after holder removal was not idempotent');

		// Divergent history, dirty trees, and missing remote HEAD must leave detached state untouched.
		mkdir($root . '/divergent'); [$_, $seed, $primary] = fixture($root . '/divergent'); run($primary, 'checkout -q --detach'); file_put_contents($primary . '/local', 'local'); run($primary, 'add local'); run($primary, '-c user.email=t@t -c user.name=t commit -qm local'); advance($seed, 'remote'); assert_error((new RealGitWorkspace($primary))->git_pull('repo', false, true), 'detached_primary_diverged'); assert_true('HEAD' === trim(run($primary, 'rev-parse --abbrev-ref HEAD')), 'divergent primary was changed');
		mkdir($root . '/dirty'); [$_, $seed, $primary] = fixture($root . '/dirty'); run($primary, 'checkout -q --detach'); file_put_contents($primary . '/dirty', 'dirty'); advance($seed, 'remote'); assert_error((new RealGitWorkspace($primary))->git_pull('repo', false, true), 'dirty_working_tree');
		// A remote HEAD change cannot invalidate an already validated local
		// origin/HEAD target; the mandatory fetch still verifies origin/main.
		mkdir($root . '/missing'); [$origin, $_seed, $primary] = fixture($root . '/missing'); run($primary, 'checkout -q --detach'); run($root . '/missing', '--git-dir=' . escapeshellarg($origin) . ' symbolic-ref HEAD refs/heads/missing'); $missing = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $missing instanceof \WP_Error && 'main' === ($missing['primary_repair']['branch'] ?? ''), 'valid local origin/HEAD did not survive a changed remote HEAD');
		mkdir($root . '/stale-head'); [$_, $_seed, $primary] = fixture($root . '/stale-head'); run($primary, 'checkout -q --detach'); run($primary, 'symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/missing'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && 'main' === ($result['primary_repair']['branch'] ?? ''), 'stale local origin/HEAD did not fall back to the remote default');
		mkdir($root . '/malformed-head'); [$_, $_seed, $primary] = fixture($root . '/malformed-head'); run($primary, 'checkout -q --detach'); run($primary, 'symbolic-ref refs/remotes/origin/HEAD refs/heads/main'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && 'main' === ($result['primary_repair']['branch'] ?? ''), 'malformed local origin/HEAD did not fall back to the remote default');

		// Checked-out branches retain the existing fast-forward pull behavior.
		mkdir($root . '/branch'); [$_, $seed, $primary] = fixture($root . '/branch'); advance($seed, 'two'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ! isset($result['primary_repair']), 'normal branch pull behavior changed');
		fwrite(STDOUT, "detached primary refresh smoke passed\n");
	} finally { rmrf($root); }
}
