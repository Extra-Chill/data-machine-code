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
	function fixture( string $root ): array { $origin = $root . '/origin.git'; $seed = $root . '/seed'; $primary = $root . '/primary'; run($root, 'init -q -b main --bare ' . escapeshellarg($origin)); mkdir($seed, 0700, true); run($seed, 'init -q -b main'); file_put_contents($seed . '/a', 'one'); run($seed, 'add a'); run($seed, '-c user.email=t@t -c user.name=t commit -qm one'); run($seed, 'remote add origin ' . escapeshellarg($origin)); run($seed, 'push -q origin main'); run($root, 'clone -q ' . escapeshellarg($origin) . ' ' . escapeshellarg($primary)); return array( $origin, $seed, $primary ); }
	function advance( string $seed, string $name ): void { file_put_contents($seed . '/' . $name, $name); run($seed, 'add ' . escapeshellarg($name)); run($seed, '-c user.email=t@t -c user.name=t commit -qm ' . escapeshellarg($name)); run($seed, 'push -q origin main'); }

	$root = dirname(__DIR__) . '/.dmc-987-tmp-' . bin2hex(random_bytes(4));
	mkdir($root, 0700, true);
	try {
		// A detached stale primary reattaches to origin/main and fast-forwards.
		mkdir($root . '/stale'); [$_, $seed, $primary] = fixture($root . '/stale'); run($primary, 'checkout -q --detach'); $before = run($primary, 'rev-parse HEAD'); advance($seed, 'two'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ($result['primary_repair']['head_before'] ?? '') === $before, 'stale detached primary was not repaired'); assert_true('main' === trim(run($primary, 'branch --show-current')), 'stale detached primary was not attached to main');

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
		mkdir($root . '/missing'); [$origin, $_seed, $primary] = fixture($root . '/missing'); run($primary, 'checkout -q --detach'); run($root . '/missing', '--git-dir=' . escapeshellarg($origin) . ' symbolic-ref HEAD refs/heads/missing'); $missing = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true($missing instanceof \WP_Error && in_array($missing->get_error_code(), array( 'detached_primary_default_branch_ambiguous', 'detached_primary_fetch_failed', 'detached_primary_default_ref_missing' ), true), 'missing default ref did not return an actionable block');

		// Checked-out branches retain the existing fast-forward pull behavior.
		mkdir($root . '/branch'); [$_, $seed, $primary] = fixture($root . '/branch'); advance($seed, 'two'); $result = (new RealGitWorkspace($primary))->git_pull('repo', false, true); assert_true(! $result instanceof \WP_Error && ! isset($result['primary_repair']), 'normal branch pull behavior changed');
		fwrite(STDOUT, "detached primary refresh smoke passed\n");
	} finally { rmrf($root); }
}
