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

	$GLOBALS['artifact_guard_registered_abilities'] = array();

	function wp_register_ability( string $slug, array $args ): void {
		$GLOBALS['artifact_guard_registered_abilities'][ $slug ] = $args;
	}

	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook;
	}
}

	namespace DataMachineCode\Workspace {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';
	require_once dirname(__DIR__) . '/inc/Cli/WorkspaceCompactOutput.php';

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

	final class Workspace {
		public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
		public const MAX_READ_SIZE = 1048576;
		public static array $artifact_cleanup_options = array();

		public function worktree_cleanup_artifacts( array $opts = array() ): array {
			self::$artifact_cleanup_options = $opts;
			return array( 'success' => true, 'dry_run' => ! empty($opts['dry_run']), 'candidates' => array(), 'skipped' => array() );
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

	final class MacOSArtifactCleanupGuardHarness extends ArtifactCleanupGuardHarness {
		public function __construct( string $workspace_path, private \DataMachineCode\Support\ProcessPathProbeInterface $probe ) {
			parent::__construct($workspace_path);
		}

		protected function detect_active_artifact_processes( string $worktree_path, array $artifacts, bool $fresh = false ): array {
			$snapshot = $this->probe->snapshot();
			return array(
				'status'      => (string) ( $snapshot['status'] ?? 'unavailable' ),
				'evidence'    => (array) ( $snapshot['records'] ?? array() ),
				'diagnostics' => (array) ( $snapshot['diagnostics'] ?? array() ),
			);
		}
	}

	final class ScopedArtifactCleanupGuardHarness extends ArtifactCleanupGuardHarness {
		public function __construct( string $workspace_path, private \DataMachineCode\Support\ProcessPathProbeInterface $probe ) {
			parent::__construct($workspace_path);
		}

		protected function artifact_process_path_probe(): \DataMachineCode\Support\ProcessPathProbeInterface {
			return $this->probe;
		}
	}
}

namespace {
	use DataMachineCode\Workspace\ArtifactCleanupGuardHarness;
	use DataMachineCode\Workspace\ControlledArtifactCleanupGuardHarness;
	use DataMachineCode\Workspace\MacOSArtifactCleanupGuardHarness;
	use DataMachineCode\Workspace\ScopedArtifactCleanupGuardHarness;
	use DataMachineCode\Workspace\WorktreeContextInjector;
	use DataMachineCode\Support\MacOSLsofProcessPathProbe;
	use DataMachineCode\Support\ProcessPathProbeInterface;
	use DataMachineCode\Cli\WorkspaceCompactOutput;
	use DataMachineCode\Abilities\WorkspaceAbilities;

	function artifact_guard_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
		}
	}

	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';
	new WorkspaceAbilities();
	$artifact_ability = $GLOBALS['artifact_guard_registered_abilities']['datamachine-code/workspace-worktree-cleanup-artifacts'] ?? array();
	artifact_guard_assert_same(true, isset($artifact_ability['input_schema']['properties']['only_handle'], $artifact_ability['input_schema']['properties']['only_handles']), 'artifact cleanup ability must register singular and plural retry scopes');
	WorkspaceAbilities::worktreeCleanupArtifacts(array( 'dry_run' => true, 'only_handle' => 'repo@z-blocked', 'only_handles' => array( 'repo@z-blocked', 'repo@z-blocked' ) ));
	artifact_guard_assert_same(array( 'repo@z-blocked' ), \DataMachineCode\Workspace\Workspace::$artifact_cleanup_options['only_handles'] ?? null, 'artifact cleanup ability must normalize retry scopes into the planner option');

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

	final class ScopedProcessProbe implements ProcessPathProbeInterface {
		public function __construct(private array $global, private array $scoped) {}
		public function snapshot(): array { return $this->global; }
		public function snapshot_for_paths(array $paths): array {
			foreach ($paths as $path) {
				if (str_contains($path, '/repo@active')) {
					return $this->scoped['active'];
				}
				if (str_contains($path, '/repo@inactive')) {
					return $this->scoped['inactive'];
				}
			}
			return array('status' => 'uncertain', 'records' => array(), 'diagnostics' => array('reason' => 'process_path_probe_incomplete'));
		}
	}

	$scoped_root     = sys_get_temp_dir() . '/dmc-artifact-scoped-' . getmypid();
	$active_target   = $scoped_root . '/repo@active/target';
	$inactive_target = $scoped_root . '/repo@inactive/target';
	foreach (array($active_target, $inactive_target) as $target) {
		mkdir($target, 0777, true);
		file_put_contents(dirname($target) . '/Cargo.toml', '[package]');
		file_put_contents($target . '/generated.bin', str_repeat('x', 1024));
	}
	$scoped_probe = new ScopedProcessProbe(
		array(
			'status' => 'uncertain',
			'records' => array(array('pid' => 4242, 'command' => 'cargo', 'match_type' => 'open_file', 'path' => $active_target)),
			'diagnostics' => array('provider' => 'lsof', 'reason' => 'process_path_probe_incomplete'),
		),
		array(
			'active' => array('status' => 'available', 'records' => array(array('pid' => 4242, 'command' => 'cargo', 'match_type' => 'open_file', 'path' => $active_target)), 'diagnostics' => array('provider' => 'lsof', 'path_records' => 1)),
			'inactive' => array('status' => 'available', 'records' => array(), 'diagnostics' => array('provider' => 'lsof', 'path_records' => 0)),
		)
	);
	$scoped_harness = new ScopedArtifactCleanupGuardHarness($scoped_root, $scoped_probe);
	$scoped_harness->rows = array(
		array('handle' => 'repo@active', 'repo' => 'repo', 'branch' => 'test/active', 'path' => dirname($active_target), 'is_worktree' => true, 'is_primary' => false, 'liveness' => WorktreeContextInjector::LIVENESS_STALE),
		array('handle' => 'repo@inactive', 'repo' => 'repo', 'branch' => 'test/inactive', 'path' => dirname($inactive_target), 'is_worktree' => true, 'is_primary' => false, 'liveness' => WorktreeContextInjector::LIVENESS_STALE),
	);
	$scoped_active_probe = $scoped_harness->probe_processes(dirname($active_target), array(array('path' => 'target')));
	artifact_guard_assert_same(4242, $scoped_active_probe['evidence'][0]['pid'] ?? null, 'candidate-scoped probe must retain active target evidence');
	$scoped_preview = $scoped_harness->worktree_cleanup_artifacts(array('dry_run' => true, 'safety_probes' => true));
	artifact_guard_assert_same(array('repo@inactive'), array_column($scoped_preview['candidates'], 'handle'), 'a sibling Cargo process must not make an inactive target probe uncertain');
	artifact_guard_assert_same('active_build', $scoped_preview['skipped'][0]['reason_code'] ?? null, 'the active Cargo target must remain protected');
	artifact_guard_assert_same(4242, $scoped_preview['skipped'][0]['process_evidence'][0]['pid'] ?? null, 'active-process skips must report the PID');
	artifact_guard_assert_same(dirname($active_target), $scoped_preview['skipped'][0]['process_evidence'][0]['candidate_path'] ?? null, 'active-process skips must report the candidate path');
	artifact_guard_assert_same('open_file', $scoped_preview['skipped'][0]['process_evidence'][0]['match_method'] ?? null, 'active-process skips must report the match method');
	artifact_guard_assert_same('high', $scoped_preview['skipped'][0]['process_evidence'][0]['confidence'] ?? null, 'active-process skips must report match confidence');
	$mixed_apply = $scoped_harness->worktree_cleanup_artifacts(array('apply_plan' => array('candidates' => array(
		array('handle' => 'repo@active', 'repo' => 'repo', 'branch' => 'test/guard', 'path' => dirname($active_target), 'artifacts' => array(array('path' => 'target'))),
		array('handle' => 'repo@inactive', 'repo' => 'repo', 'branch' => 'test/guard', 'path' => dirname($inactive_target), 'artifacts' => array(array('path' => 'target'))),
	))));
	artifact_guard_assert_same(1, count($mixed_apply['removed']), 'mixed apply must reclaim the independent inactive target');
	artifact_guard_assert_same(1, count($mixed_apply['skipped']), 'mixed apply must retain the active target');
	artifact_guard_assert_same(true, (int) ($mixed_apply['summary']['removed_size_bytes'] ?? 0) > 0, 'mixed apply must report nonzero reclaimed bytes');
	artifact_guard_assert_same(true, is_dir($active_target), 'mixed apply must retain the active Cargo target');
	artifact_guard_assert_same(false, is_dir($inactive_target), 'mixed apply must remove the inactive Cargo target');
	artifact_guard_remove_tree($scoped_root);

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
	artifact_guard_assert_same('unknown', $unavailable['skipped'][0]['process_probe_diagnostics']['provider'] ?? null, 'unavailable process skips must report the provider');
	artifact_guard_assert_same('unavailable', $unavailable['skipped'][0]['process_probe_diagnostics']['classification'] ?? null, 'unavailable process skips must use a stable classification');
	artifact_guard_assert_same($path, $unavailable['skipped'][0]['process_probe_diagnostics']['candidate_path'] ?? null, 'unavailable process skips must identify the candidate path');
	artifact_guard_assert_same(0, $unavailable['skipped'][0]['process_probe_diagnostics']['inspected_path_count'] ?? null, 'unavailable process skips must report inspected path count');
	artifact_guard_assert_same("studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run --safety-probes --limit=1 --only-handle='repo@guard' --format=json", $unavailable['skipped'][0]['process_probe_diagnostics']['retry_command'] ?? null, 'unavailable process skips must include a targeted bounded retry command');
	$unavailable_compact = WorkspaceCompactOutput::cleanup_result($unavailable);
	artifact_guard_assert_same('unavailable', $unavailable_compact['samples']['skipped'][0]['process_probe_diagnostics']['classification'] ?? null, 'compact output must preserve cleanup process-probe diagnostics');
	artifact_guard_assert_same(true, in_array($unavailable['skipped'][0]['process_probe_diagnostics']['retry_command'], (array) ( $unavailable_compact['next_commands'] ?? array() ), true), 'compact output must promote process-probe retry commands to next_commands');
	$first_path = $root . '/repo@a-first';
	mkdir($first_path, 0777, true);
	artifact_guard_create_artifacts($first_path);
	$first_row = $base;
	$first_row['handle'] = 'repo@a-first';
	$first_row['branch'] = 'test/first';
	$first_row['path']   = $first_path;
	$blocked_row = $base;
	$blocked_row['handle'] = 'repo@z-blocked';
	$blocked_row['branch'] = 'test/blocked';
	$harness->rows          = array($blocked_row, $first_row);
	$harness->process_probe = array( 'status' => 'unavailable', 'evidence' => array(), 'diagnostics' => array( 'reason' => 'process_filesystem_unavailable' ) );
	$blocked_preview = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'only_handles' => array( 'repo@z-blocked' ) ));
	artifact_guard_assert_same('repo@z-blocked', $blocked_preview['skipped'][0]['handle'] ?? null, 'targeted retry must inspect the blocked handle rather than the first inventory row');
	artifact_guard_assert_same(1, $blocked_preview['pagination']['scanned'] ?? null, 'targeted retry must stay bounded to the selected handle');
	$harness->process_probe = array( 'status' => 'available', 'evidence' => array(), 'diagnostics' => array() );
	$retried = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'only_handles' => array( 'repo@z-blocked' ) ));
	artifact_guard_assert_same('repo@z-blocked', $retried['candidates'][0]['handle'] ?? null, 'retry command scope must select the intended blocked worktree');
	$harness->rows          = array($base);
	$harness->process_probe = array( 'status' => 'unavailable', 'evidence' => array(), 'diagnostics' => array( 'reason' => 'process_filesystem_unavailable' ) );
	$forced = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'force' => true ));
	artifact_guard_assert_same(0, count($forced['candidates']), 'legacy force must not override unavailable active-process evidence');
	$active_override = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'allow_active_artifact_cleanup' => true ));
	artifact_guard_assert_same(0, count($active_override['candidates']), 'live-owner eviction override must not waive unavailable process evidence');
	$probe_override = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true, 'allow_unavailable_process_probe' => true ));
	artifact_guard_assert_same(1, count($probe_override['candidates']), 'process-probe override must permit reviewed cleanup without waiving live-owner eviction');
	artifact_guard_assert_same('active_process_probe_unavailable', $probe_override['candidates'][0]['safety_overrides'][0]['reason_code'] ?? null, 'probe override must retain typed unavailable evidence');

	$mac_no_match = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0cnode\0f3\0n/tmp/unrelated\0" ));
	artifact_guard_assert_same('available', $mac_no_match->snapshot()['status'], 'macOS lsof no-match snapshot must be available');
	$mac_scoped_no_match = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => false, 'exit_code' => 1, 'output' => '' ));
	artifact_guard_assert_same('available', $mac_scoped_no_match->snapshot_for_paths(array($path))['status'], 'macOS scoped lsof exit 1 without output must be available no-match evidence');
	$mac_no_process_harness = new MacOSArtifactCleanupGuardHarness($root, new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => '' )));
	$mac_no_process_harness->rows = array($base);
	$mac_no_process = $mac_no_process_harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same(1, count($mac_no_process['candidates']), 'macOS-shaped Node no-process evidence must leave cleanup eligible');
	$mac_cwd = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0ctest\0fcwd\0n{$path}\0" ));
	$mac_cwd_records = $mac_cwd->snapshot()['records'];
	artifact_guard_assert_same('cwd', $mac_cwd_records[0]['match_type'] ?? null, 'macOS lsof cwd evidence must retain its match type');
	$mac_open_file = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0ctest\0f12\0n{$path}/vendor/generated.php\0" ));
	$mac_open_file_records = $mac_open_file->snapshot()['records'];
	artifact_guard_assert_same('open_file', $mac_open_file_records[0]['match_type'] ?? null, 'macOS lsof open-file evidence must retain its match type');
	$mac_active_probe = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0cnode\0fcwd\0n{$path}\0" ));
	$mac_active_snapshot = $mac_active_probe->snapshot();
	artifact_guard_assert_same('cwd', $mac_active_snapshot['records'][0]['match_type'] ?? null, 'macOS-shaped Node cwd output must parse as cwd evidence');
	artifact_guard_assert_same($path, $mac_active_snapshot['records'][0]['path'] ?? null, 'macOS-shaped Node cwd output must preserve the candidate path');
	$mac_active_harness = new MacOSArtifactCleanupGuardHarness($root, $mac_active_probe);
	$mac_active_harness->rows = array($base);
	$mac_active = $mac_active_harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('active_build', $mac_active['skipped'][0]['reason_code'] ?? null, 'macOS-shaped Node cwd evidence must block cleanup');
	$mac_timeout = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => false, 'timeout' => 2 ));
	artifact_guard_assert_same('uncertain', $mac_timeout->snapshot()['status'], 'macOS lsof timeout must fail closed as uncertain');
	artifact_guard_assert_same('process_path_probe_timeout', $mac_timeout->snapshot()['diagnostics']['reason'] ?? null, 'macOS lsof timeouts must retain a stable error code');
	$mac_failure = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => false, 'output' => 'permission denied: secret command argument' ));
	artifact_guard_assert_same('process_path_probe_permission_denied', $mac_failure->snapshot()['diagnostics']['reason'] ?? null, 'macOS lsof permission failures must retain a stable error code');
	artifact_guard_assert_same(false, isset($mac_failure->snapshot()['diagnostics']['details']), 'macOS lsof failures must not expose raw process output');
	$mac_operation_not_permitted = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => false, 'stderr' => 'lsof: Operation not permitted' ));
	artifact_guard_assert_same('process_path_probe_permission_denied', $mac_operation_not_permitted->snapshot()['diagnostics']['reason'] ?? null, 'macOS operation-not-permitted failures must classify as permission denied');
	$mac_malformed = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "pbad\0n{$path}\0" ));
	artifact_guard_assert_same('process_path_probe_malformed_output', $mac_malformed->snapshot()['diagnostics']['reason'] ?? null, 'macOS malformed lsof output must fail closed with a stable error code');
	$mac_truncated = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0cnode\0fcwd\0" ));
	artifact_guard_assert_same('process_path_probe_malformed_output', $mac_truncated->snapshot()['diagnostics']['reason'] ?? null, 'truncated macOS lsof records must fail closed as malformed');
	$mac_cross_process = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0cnode\0fcwd\0p43\0cnode\0n{$path}\0" ));
	artifact_guard_assert_same('process_path_probe_malformed_output', $mac_cross_process->snapshot()['diagnostics']['reason'] ?? null, 'cross-process macOS lsof records with incomplete predecessors must fail closed as malformed');
	$harness->process_probe = array( 'status' => 'uncertain', 'evidence' => array(), 'diagnostics' => array( 'provider' => 'lsof', 'reason' => 'process_path_probe_timeout', 'path_records' => 3 ) );
	$timed_out = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('active_process_probe_uncertain', $timed_out['skipped'][0]['reason_code'] ?? null, 'uncertain process evidence must fail closed');
	artifact_guard_assert_same('timed_out', $timed_out['skipped'][0]['process_probe_diagnostics']['classification'] ?? null, 'timed out lsof evidence must be classified for operators');
	artifact_guard_assert_same(3, $timed_out['skipped'][0]['process_probe_diagnostics']['inspected_path_count'] ?? null, 'timed out lsof evidence must preserve inspected path count');
	artifact_guard_assert_same(true, str_contains((string) ( $timed_out['skipped'][0]['process_probe_diagnostics']['guidance'] ?? '' ), '--limit=1'), 'process-probe guidance must remain bounded and non-destructive');
	$harness->process_probe = array( 'status' => 'uncertain', 'evidence' => array(), 'diagnostics' => array( 'provider' => 'lsof', 'reason' => 'process_path_probe_permission_denied' ) );
	$permission_denied = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('permission_denied', $permission_denied['skipped'][0]['process_probe_diagnostics']['classification'] ?? null, 'permission failures must be classified for operators');
	$harness->process_probe = array( 'status' => 'uncertain', 'evidence' => array(), 'diagnostics' => array( 'provider' => 'procfs', 'reason' => 'process_path_probe_incomplete' ) );
	$ambiguous = $harness->worktree_cleanup_artifacts(array( 'dry_run' => true, 'safety_probes' => true ));
	artifact_guard_assert_same('ambiguous_evidence', $ambiguous['skipped'][0]['process_probe_diagnostics']['classification'] ?? null, 'unresolved probe evidence must remain explicitly ambiguous');
	$mac_exit_race = new MacOSLsofProcessPathProbe(fn( array $argv ) => array( 'success' => true, 'output' => "p42\0ctest\0" ));
	artifact_guard_assert_same(array(), $mac_exit_race->snapshot()['records'], 'macOS lsof process-exit races without path records must remain safe no-match evidence');

	// Linux procfs exposes the child cwd deterministically. macOS lsof visibility
	// varies with host privacy policy; its provider contract is covered above with
	// injected lsof snapshots instead of making this portable suite host-dependent.
	if ( 'Linux' === PHP_OS_FAMILY ) {
		$real_scanner = new ArtifactCleanupGuardHarness($root);
		$descriptors  = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) );
		$process      = proc_open(array( '/bin/sleep', '5' ), $descriptors, $pipes, $path);
		if ( is_resource($process) ) {
			try {
				$deadline   = microtime(true) + 2;
				$seen_alive = false;
				$seen_cwd   = 'Linux' !== PHP_OS_FAMILY;
				$real_probe = array( 'evidence' => array() );
				do {
					$status     = proc_get_status($process);
					$seen_alive = $seen_alive || ! empty($status['running']);
					if ( 'Linux' === PHP_OS_FAMILY && ! empty($status['pid']) ) {
						$child_cwd = @readlink('/proc/' . (int) $status['pid'] . '/cwd'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Child cwd can race process startup and exit.
						$seen_cwd  = $seen_cwd || $path === $child_cwd;
					}
					if ( ! empty($status['running']) && $seen_cwd ) {
						$real_probe = $real_scanner->probe_processes($path, array( array( 'path' => 'vendor' ) ));
						if ( array() !== (array) ( $real_probe['evidence'] ?? array() ) ) {
							break;
						}
					}
					usleep(50000);
				} while ( microtime(true) < $deadline );
				artifact_guard_assert_same(true, $seen_alive, 'host process scanner test child must remain alive while probing');
				artifact_guard_assert_same(true, $seen_cwd, 'host process scanner test child must report the requested cwd before probing');
				artifact_guard_assert_same(true, array() !== (array) ( $real_probe['evidence'] ?? array() ), 'host process scanner must detect a ready child process cwd in the worktree');
			} finally {
				proc_terminate($process);
				proc_close($process);
			}
		}
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
