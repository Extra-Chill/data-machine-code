<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	$GLOBALS['artifact_guard_metadata'] = array();

	function get_option( string $key, mixed $default = false ): mixed {
		if ( DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION === $key ) {
			return $GLOBALS['artifact_guard_metadata'];
		}
		return $default;
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): mixed { return $this->data; }
		}
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

namespace DataMachineCode\Workspace {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';

	final class ArtifactCleanupGuardHarness {
		use WorkspaceArtifactCleanup;
		use WorkspaceWorktreeCleanupEngine;

		public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
		protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;
		protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
		protected const CLEANUP_GITHUB_TIMEOUT = 5;
		protected const CLEANUP_GITHUB_MAX_PAGES = 3;
		protected const CLEANUP_SUMMARY_TOP_LIMIT = 10;

		public array $rows = array();
		public array $process_evidence = array();
		public bool $become_active_on_fresh_probe = false;
		public string $workspace_path;

		public function __construct( string $workspace_path ) {
			$this->workspace_path = $workspace_path;
		}

		private function build_workspace_inventory_rows(): array {
			return $this->rows;
		}

		private function resolve_worktree_branch_from_head_file( string $path ): ?string {
			return 'test/guard';
		}

		private function probe_worktree_dirty_count( string $path, int $timeout = 0 ): int|\WP_Error {
			return 0;
		}

		private function count_unpushed_commits( string $path, int $timeout = 0 ): int|\WP_Error {
			return 0;
		}

		private function run_git( string $path, string $command, int $timeout = 0 ): array|\WP_Error {
			return array( 'output' => '' );
		}

		private function validate_containment( string $target, string $container ): array {
			$target_real = realpath($target);
			$root_real   = realpath($container);
			$valid       = is_string($target_real) && is_string($root_real) && str_starts_with($target_real, rtrim($root_real, '/') . '/');
			return array( 'valid' => $valid, 'real_path' => $valid ? $target_real : null, 'message' => $valid ? '' : 'outside workspace' );
		}

		protected function detect_active_artifact_processes( string $worktree_path, array $artifacts, bool $fresh = false ): array {
			if ( $fresh && $this->become_active_on_fresh_probe ) {
				return array(
					array(
						'pid'        => 4321,
						'command'    => 'worker',
						'owner_uid'  => 1000,
						'match_type' => 'open_file',
						'path'       => $worktree_path . '/' . (string) ( $artifacts[0]['path'] ?? '' ) . '/lock',
					),
				);
			}
			return $this->process_evidence;
		}
	}
}

namespace {
	use DataMachineCode\Workspace\ArtifactCleanupGuardHarness;
	use DataMachineCode\Workspace\WorktreeContextInjector;

	function artifact_guard_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
		}
	}

	function artifact_guard_remove_tree( string $path ): void {
		if ( ! is_dir($path) ) {
			return;
		}
		foreach ( array_diff(scandir($path) ?: array(), array( '.', '..' )) as $entry ) {
			$child = $path . '/' . $entry;
			is_dir($child) ? artifact_guard_remove_tree($child) : unlink($child);
		}
		rmdir($path);
	}

	$root     = sys_get_temp_dir() . '/dmc-artifact-guards-' . getmypid();
	$path     = $root . '/repo@guard';
	$artifact = $path . '/vendor';
	mkdir($artifact, 0777, true);
	file_put_contents($path . '/composer.json', '{}');
	file_put_contents($artifact . '/generated.php', '<?php');

	$harness = new ArtifactCleanupGuardHarness($root);
	$base    = array(
		'handle'          => 'repo@guard',
		'repo'            => 'repo',
		'branch'          => 'test/guard',
		'branch_slug'     => 'guard',
		'path'            => $path,
		'is_worktree'     => true,
		'is_primary'      => false,
		'liveness'        => WorktreeContextInjector::LIVENESS_STALE,
		'liveness_reason' => 'heartbeat_stale',
	);

	$harness->rows = array(array_merge($base, array(
		'liveness'              => WorktreeContextInjector::LIVENESS_LIVE,
		'liveness_reason'       => 'heartbeat_fresh',
		'heartbeat_age_seconds' => 10,
		'owner'                 => array( 'agent' => 'test-agent' ),
		'session'               => array( 'primary_id' => 'run-1', 'ids' => array() ),
	)));
	$live_preview  = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('live_worktree', $live_preview['skipped'][0]['reason_code'] ?? null, 'clean live worktree must be skipped');
	artifact_guard_assert_same('run-1', $live_preview['skipped'][0]['liveness_evidence']['session']['primary_id'] ?? null, 'live skip must retain session evidence');

	$harness->rows = array($base);
	$stale_preview = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same(1, count($stale_preview['candidates']), 'stale liveness must not manufacture a cleanup veto');

	$harness->process_evidence = array(
		array( 'pid' => 1234, 'command' => 'worker', 'owner_uid' => 1000, 'match_type' => 'cwd', 'path' => $path ),
		array( 'pid' => 1235, 'command' => 'analyzer', 'owner_uid' => 1000, 'match_type' => 'open_file', 'path' => $artifact . '/generated.php' ),
	);
	$active_preview = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('active_build', $active_preview['skipped'][0]['reason_code'] ?? null, 'active cwd or open artifact file must be skipped');
	artifact_guard_assert_same(1234, $active_preview['skipped'][0]['process_evidence'][0]['pid'] ?? null, 'active skip must retain process evidence');

	$forced_preview = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'force' => true ));
	artifact_guard_assert_same(1, count($forced_preview['candidates']), 'explicit artifact force must permit intentional active eviction');
	artifact_guard_assert_same('active_build', $forced_preview['candidates'][0]['safety_overrides'][0]['reason_code'] ?? null, 'forced candidate must disclose its active-process override');

	$harness->process_evidence            = array();
	$harness->become_active_on_fresh_probe = true;
	$GLOBALS['artifact_guard_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$plan = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	$race = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $plan['candidates'] ) ));
	artifact_guard_assert_same('active_build', $race['skipped'][0]['reason_code'] ?? null, 'apply must detect a process that appears after planning');
	artifact_guard_assert_same(true, is_dir($artifact), 'race protection must preserve the artifact directory');

	$harness->become_active_on_fresh_probe = false;
	$GLOBALS['artifact_guard_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE,
		'last_seen_at'    => gmdate('c'),
		'origin_session'  => array( 'primary_id' => 'run-2', 'ids' => array() ),
	);
	$live_race = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $plan['candidates'] ) ));
	artifact_guard_assert_same('live_worktree', $live_race['skipped'][0]['reason_code'] ?? null, 'apply must detect liveness that appears after planning');
	artifact_guard_assert_same(true, is_dir($artifact), 'live transition protection must preserve the artifact directory');

	$GLOBALS['artifact_guard_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$inactive = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $plan['candidates'] ) ));
	artifact_guard_assert_same(1, count($inactive['removed']), 'inactive safe cleanup must still remove reviewed artifacts');
	artifact_guard_assert_same(false, is_dir($artifact), 'inactive safe cleanup must remove the artifact directory');

	artifact_guard_remove_tree($root);
	fwrite(STDOUT, "artifact-cleanup-live-process-guards ok\n");
}
