<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	if ( ! defined('ARRAY_A') ) {
		define('ARRAY_A', 'ARRAY_A');
	}

	$GLOBALS['artifact_guard_authoritative_metadata'] = array();
	$GLOBALS['artifact_guard_cached_metadata']        = array();
	$GLOBALS['artifact_guard_cache_loaded']           = false;
	$GLOBALS['artifact_guard_cache_evictions']        = 0;
	$GLOBALS['artifact_guard_inventory_row']          = null;
	$GLOBALS['wpdb']                                  = new class() {
		public string $base_prefix = 'wp_';

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}

		public function get_row( string $query, string $output ): ?array {
			return $GLOBALS['artifact_guard_inventory_row'];
		}
	};

	function get_option( string $key, mixed $default = false ): mixed {
		if ( DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION !== $key ) {
			return $default;
		}
		if ( ! $GLOBALS['artifact_guard_cache_loaded'] ) {
			$GLOBALS['artifact_guard_cached_metadata'] = $GLOBALS['artifact_guard_authoritative_metadata'];
			$GLOBALS['artifact_guard_cache_loaded']    = true;
		}
		return $GLOBALS['artifact_guard_cached_metadata'];
	}

	function wp_cache_delete( string $key, string $group = '' ): bool {
		if ( DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION === $key && 'options' === $group ) {
			$GLOBALS['artifact_guard_cache_loaded'] = false;
			++$GLOBALS['artifact_guard_cache_evictions'];
		}
		return true;
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

	class ArtifactCleanupGuardHarness {
		use WorkspaceArtifactCleanup;
		use WorkspaceWorktreeCleanupEngine;

		public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
		protected const CLEANUP_GIT_PROBE_TIMEOUT = 5;
		protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
		protected const CLEANUP_GITHUB_TIMEOUT = 5;
		protected const CLEANUP_GITHUB_MAX_PAGES = 3;
		protected const CLEANUP_SUMMARY_TOP_LIMIT = 10;

		public array $rows = array();
		public string $workspace_path;
		public ?string $process_root = null;

		public function __construct( string $workspace_path ) {
			$this->workspace_path = $workspace_path;
		}

		public function probe_processes( string $path, array $artifacts, bool $fresh = true ): array {
			return $this->detect_active_artifact_processes($path, $artifacts, $fresh);
		}

		public function probe_process_snapshot( bool $fresh = true ): array {
			return $this->artifact_process_path_records($fresh);
		}

		protected function artifact_process_root(): string {
			return $this->process_root ?? '/proc';
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
	}

	final class ControlledArtifactCleanupGuardHarness extends ArtifactCleanupGuardHarness {
		public array $process_probe = array( 'status' => 'available', 'evidence' => array(), 'diagnostics' => array() );
		public int $fresh_process_scans = 0;
		public bool $activate_after_first_removal = false;
		public bool $report_rebuild = false;

		protected function detect_active_artifact_processes( string $worktree_path, array $artifacts, bool $fresh = false ): array {
			if ( $fresh ) {
				++$this->fresh_process_scans;
			}
			return $this->process_probe;
		}

		protected function after_artifact_cleanup_mutation( array $candidate, array $artifact, int $count ): void {
			if ( $this->activate_after_first_removal && 1 === $count ) {
				$GLOBALS['artifact_guard_authoritative_metadata'][ (string) $candidate['handle'] ] = array(
					'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE,
					'last_seen_at'    => gmdate('c'),
				);
			}
		}

		protected function observe_artifact_reclamation_path( string $worktree_path, string $relative ): array {
			if ( $this->report_rebuild ) {
				return array( 'path' => $relative, 'status' => 'rebuilt_before_cleanup_completed', 'durable' => false, 'rebuilt_bytes' => 64 );
			}
			return parent::observe_artifact_reclamation_path($worktree_path, $relative);
		}
	}
}

namespace {
	use DataMachineCode\Workspace\ArtifactCleanupGuardHarness;
	use DataMachineCode\Workspace\ControlledArtifactCleanupGuardHarness;
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

	function artifact_guard_create_artifacts( string $path, bool $multiple = false ): void {
		mkdir($path . '/vendor', 0777, true);
		file_put_contents($path . '/composer.json', '{}');
		file_put_contents($path . '/vendor/generated.php', '<?php');
		if ( $multiple ) {
			mkdir($path . '/node_modules', 0777, true);
			file_put_contents($path . '/package.json', '{}');
			file_put_contents($path . '/node_modules/generated.js', 'x');
		}
	}

	$root = sys_get_temp_dir() . '/dmc-artifact-guards-' . getmypid();
	$path = $root . '/repo@guard';
	mkdir($path, 0777, true);
	artifact_guard_create_artifacts($path);

	$base = array(
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
	$harness       = new ControlledArtifactCleanupGuardHarness($root);
	$harness->rows = array($base);

	$stale_preview = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same(1, count($stale_preview['candidates']), 'stale liveness remains eligible when process inspection is available');

	$harness->process_probe = array( 'status' => 'unavailable', 'evidence' => array(), 'diagnostics' => array( 'reason' => 'process_filesystem_unavailable' ) );
	$unavailable            = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('active_process_probe_unavailable', $unavailable['skipped'][0]['reason_code'] ?? null, 'unavailable process evidence must fail closed');
	$forced = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'force' => true ));
	artifact_guard_assert_same(0, count($forced['candidates']), 'legacy force must not override unavailable active-process evidence');
	$active_override = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'allow_active_artifact_cleanup' => true ));
	artifact_guard_assert_same(1, count($active_override['candidates']), 'distinct active artifact override must permit reviewed eviction');
	artifact_guard_assert_same('active_process_probe_unavailable', $active_override['candidates'][0]['safety_overrides'][0]['reason_code'] ?? null, 'active override must retain typed unavailable evidence');

	$real_scanner = new ArtifactCleanupGuardHarness($root);
	$descriptors  = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) );
	$process      = proc_open(array( '/bin/sleep', '5' ), $descriptors, $pipes, $path);
	if ( is_resource($process) ) {
		usleep(100000);
		$real_probe = $real_scanner->probe_processes($path, array( array( 'path' => 'vendor' ) ));
		artifact_guard_assert_same(true, array() !== (array) ( $real_probe['evidence'] ?? array() ), 'real procfs scanner must detect a child process cwd in the worktree');
		proc_terminate($process);
		proc_close($process);
	}

	$fake_proc = $root . '/proc-fixture';
	mkdir($fake_proc . '/self/ns', 0777, true);
	mkdir($fake_proc . '/999999/fd', 0777, true);
	mkdir($fake_proc . '/999999/ns', 0777, true);
	symlink($path, $fake_proc . '/999999/cwd');
	file_put_contents($fake_proc . '/999999/comm', 'fixture');
	$namespace_scanner               = new ArtifactCleanupGuardHarness($root);
	$namespace_scanner->process_root = $fake_proc;
	$namespace_probe                 = $namespace_scanner->probe_process_snapshot();
	artifact_guard_assert_same('uncertain', $namespace_probe['status'] ?? null, 'missing mount namespace links must fail closed as uncertain');
	artifact_guard_assert_same(true, array() !== (array) ( $namespace_probe['diagnostics']['unknown_mount_namespaces'] ?? array() ), 'uncertain namespace probe must preserve typed diagnostics');
	unlink($fake_proc . '/999999/cwd');

	$harness->process_probe = array( 'status' => 'available', 'evidence' => array(), 'diagnostics' => array() );
	$GLOBALS['artifact_guard_authoritative_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$GLOBALS['artifact_guard_cache_loaded']    = false;
	$GLOBALS['artifact_guard_cache_evictions'] = 0;
	$plan = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	get_option(WorktreeContextInjector::METADATA_OPTION, array());
	$GLOBALS['artifact_guard_authoritative_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE,
		'last_seen_at'    => gmdate('c'),
		'origin_session'  => array( 'primary_id' => 'fresh-run', 'ids' => array() ),
	);
	$cache_race = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $plan['candidates'] ) ));
	artifact_guard_assert_same('live_worktree', $cache_race['skipped'][0]['reason_code'] ?? null, 'final revalidation must evict stale option cache and observe concurrent heartbeat');
	artifact_guard_assert_same(true, $GLOBALS['artifact_guard_cache_evictions'] > 0, 'final liveness read must explicitly evict the option cache');

	$GLOBALS['artifact_guard_inventory_row'] = array(
		'handle'   => 'repo@guard',
		'metadata' => json_encode(
			array(
				'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE,
				'last_seen_at'    => gmdate('c'),
			)
		),
	);
	$GLOBALS['artifact_guard_authoritative_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$GLOBALS['artifact_guard_cache_loaded'] = false;
	$inventory_race = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $plan['candidates'] ) ));
	artifact_guard_assert_same('live_worktree', $inventory_race['skipped'][0]['reason_code'] ?? null, 'newer inventory heartbeat must not be masked by older option metadata');
	$GLOBALS['artifact_guard_inventory_row'] = null;

	$GLOBALS['artifact_guard_authoritative_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$GLOBALS['artifact_guard_cache_loaded'] = false;
	$harness->fresh_process_scans           = 0;
	$inactive = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $plan['candidates'] ) ));
	artifact_guard_assert_same(1, count($inactive['removed']), 'inactive safe cleanup must remove reviewed artifacts');
	artifact_guard_assert_same(1, $harness->fresh_process_scans, 'apply must perform one fresh process scan per row, not per artifact');
	artifact_guard_assert_same(true, $inactive['removed'][0]['reclamation_observation']['durable'] ?? null, 'cleanup must record durable end-of-run reclamation evidence');

	artifact_guard_create_artifacts($path, true);
	$harness->rows = array($base);
	$GLOBALS['artifact_guard_authoritative_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$GLOBALS['artifact_guard_cache_loaded'] = false;
	$multi_plan = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	$harness->activate_after_first_removal = true;
	$partial = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $multi_plan['candidates'] ) ));
	artifact_guard_assert_same(1, count($partial['partial']), 'transition to live after first artifact must return a typed partial row');
	artifact_guard_assert_same('partial_artifact_cleanup', $partial['partial'][0]['reason_code'] ?? null, 'partial row must expose stable reason code');
	artifact_guard_assert_same('live_worktree', $partial['partial'][0]['blocker']['reason_code'] ?? null, 'partial row must preserve the later liveness blocker');
	artifact_guard_assert_same(true, (int) ( $partial['partial'][0]['bytes_reclaimed'] ?? 0 ) > 0, 'partial row must preserve measured reclaimed bytes');
	artifact_guard_assert_same(1, count($partial['partial'][0]['remaining_artifacts'] ?? array()), 'partial row must identify remaining blocked artifacts');

	$harness->activate_after_first_removal = false;
	$harness->report_rebuild               = true;
	$GLOBALS['artifact_guard_authoritative_metadata']['repo@guard'] = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'last_seen_at'    => gmdate('c', time() - 172800),
	);
	$GLOBALS['artifact_guard_cache_loaded'] = false;
	foreach ( (array) ( $partial['partial'][0]['remaining_artifacts'] ?? array() ) as $remaining ) {
		$remaining_path = $path . '/' . (string) ( $remaining['path'] ?? '' );
		if ( ! is_dir($remaining_path) ) {
			mkdir($remaining_path, 0777, true);
			file_put_contents($remaining_path . '/generated', 'x');
		}
	}
	$remaining_plan = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	$rebuilt = $harness->worktree_cleanup_artifacts(array( 'apply_plan' => array( 'candidates' => $remaining_plan['candidates'] ) ));
	artifact_guard_assert_same(false, $rebuilt['removed'][0]['reclamation_observation']['durable'] ?? true, 'post-cleanup observation must identify immediate rebuilds');
	artifact_guard_assert_same(64, $rebuilt['summary']['rebuilt_artifact_bytes'] ?? null, 'summary must separate rebuilt bytes from durable reclamation');

	artifact_guard_remove_tree($root);
	fwrite(STDOUT, "artifact-cleanup-live-process-guards ok\n");
}
