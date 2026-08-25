<?php

declare(strict_types=1);

namespace DataMachineCode\Abilities {
	final class GitHubAbilities {
		public static string $mode = 'none';

		public static function getPat( array $args = array() ): string {
			return 'missing_credentials' === self::$mode ? '' : 'test-token';
		}

		public static function apiGet( string $url, array $query = array(), string $pat = '', int $timeout = 0 ): array|\WP_Error {
			if ( 'error' === self::$mode ) {
				return new \WP_Error('github_down', 'GitHub unavailable');
			}

			if ( 'open' === self::$mode ) {
				return array(
					'data' => array(
						array(
							'number'   => 864,
							'state'    => 'open',
							'html_url' => 'https://github.com/Extra-Chill/example/pull/864',
							'head'     => array(
								'ref'  => 'fix/retention-safety',
								'repo' => array( 'full_name' => 'Extra-Chill/example' ),
							),
						),
					),
				);
			}

			return array( 'data' => array() );
		}
	}
}

namespace {
	$GLOBALS['retention_apply_metadata'] = array();

	function get_option( string $key, mixed $default = false ): mixed {
		if ( DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION === $key ) {
			$metadata_file = $GLOBALS['retention_apply_metadata_file'] ?? '';
			if ( is_string($metadata_file) && is_file($metadata_file) ) {
				$metadata = json_decode((string) file_get_contents($metadata_file), true);
				return is_array($metadata) ? $metadata : $default;
			}
			return $GLOBALS['retention_apply_metadata'];
		}

		return $default;
	}

	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct(
				public string $code,
				private string $message = '',
				private mixed $data = null
			) {}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';

	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Workspace\WorkspaceMutationLock;
	use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;
	use DataMachineCode\Workspace\WorktreeContextInjector;

	function retention_apply_protections_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	final class RetentionApplyProtectionHarness {
		use DataMachineCode\Workspace\WorkspaceArtifactCleanup;
		use WorkspaceWorktreeCleanupEngine;

		protected const CLEANUP_GITHUB_TIMEOUT     = 5;
		protected const CLEANUP_GIT_PROBE_TIMEOUT  = 5;
		protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
		protected const CLEANUP_GITHUB_MAX_PAGES   = 3;
		protected const CLEANUP_SUMMARY_TOP_LIMIT  = 10;

		public string $workspace_path;
		public string $status_output = '';
		public int $unpushed_count = 0;
		private string $primary_path;

		public function __construct( string $workspace_path, string $primary_path ) {
			$this->workspace_path = $workspace_path;
			$this->primary_path   = $primary_path;
		}

		public function revalidate( array $candidate ): array {
			$GLOBALS['retention_apply_metadata'][ (string) $candidate['handle'] ] = $candidate['metadata'];
			$method = new ReflectionMethod($this, 'revalidate_bounded_cleanup_eligible_candidate');
			return $method->invoke($this, $candidate, false, false, false);
		}

		public function revalidate_current( array $candidate, array $current_metadata ): array {
			$GLOBALS['retention_apply_metadata'][ (string) $candidate['handle'] ] = $current_metadata;
			$method = new ReflectionMethod($this, 'revalidate_bounded_cleanup_eligible_candidate');
			return $method->invoke($this, $candidate, false, false, false, $candidate['metadata']);
		}

		public function revalidate_reviewed( array $candidate, array $reviewed_lifecycle_snapshot ): array {
			$GLOBALS['retention_apply_metadata'][ (string) $candidate['handle'] ] = $candidate['metadata'];
			$method = new ReflectionMethod($this, 'revalidate_bounded_cleanup_eligible_candidate');
			return $method->invoke($this, $candidate, false, false, false, $reviewed_lifecycle_snapshot);
		}

		public function apply_reviewed( array $candidate, bool $discard_unpushed = false ): array {
			$method = new ReflectionMethod($this, 'apply_worktree_cleanup_plan_candidates');
			return $method->invoke($this, array( $candidate ), false, microtime(true), false, self::CLEANUP_GIT_REMOVE_TIMEOUT, $discard_unpushed);
		}

		public function remove_artifact( string $worktree_path, string $relative ): array|WP_Error {
			return $this->remove_worktree_artifact_path($worktree_path, $relative);
		}

		private function validate_containment( string $path, string $container ): array {
			$real_path  = realpath($path);
			$real_root  = realpath($container);
			$valid      = is_string($real_path) && is_string($real_root) && str_starts_with($real_path, rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
			return array(
				'valid'     => $valid,
				'real_path' => $valid ? $real_path : null,
			);
		}

		private function get_primary_path( string $repo ): string {
			return $this->primary_path;
		}

		private function run_git( string $path, string $command, int $timeout = 0 ): array|WP_Error {
			$prelock_probe = $GLOBALS['retention_apply_prelock_probe'] ?? '';
			if ( is_string($prelock_probe) && '' !== $prelock_probe && str_starts_with($command, 'status --porcelain') ) {
				file_put_contents($prelock_probe, 'entered');
			}
			return array( 'output' => $this->status_output );
		}

		private function count_unpushed_commits( string $path, int $timeout = 0 ): int|WP_Error {
			return $this->unpushed_count;
		}

		private function git_get_remote( string $path ): ?string {
			return 'https://github.com/Extra-Chill/example.git';
		}
	}

	$root    = sys_get_temp_dir() . '/dmc-retention-apply-protections-' . getmypid();
	$primary = $root . '/example';
	$work    = $root . '/example@fix-retention-safety';
	mkdir($primary . '/.git', 0777, true);
	mkdir($primary . '/.git/worktrees/fix-retention-safety', 0777, true);
	mkdir($work, 0777, true);
	file_put_contents($work . '/.git', 'gitdir: ' . $primary . '/.git/worktrees/fix-retention-safety');

	$harness = new RetentionApplyProtectionHarness($root, $primary);
	if ( 'reactivator' === ( $argv[1] ?? '' ) ) {
		$metadata_file = (string) $argv[2];
		$workspace     = (string) $argv[3];
		$ready         = (string) $argv[4];
		$prelock_probe = (string) $argv[5];
		$reactivated   = WorkspaceMutationLock::with_repo(
			$workspace,
			'example',
			static function () use ( $metadata_file, $ready, $prelock_probe ): string {
				file_put_contents($ready, 'locked');
				$deadline = microtime(true) + 2;
				while ( ! is_file($prelock_probe) && microtime(true) < $deadline ) {
					usleep(10000);
				}
				file_put_contents($metadata_file, json_encode(array( 'example@fix-retention-safety' => array( 'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE, 'last_seen_at' => gmdate('c') ) )));
				return 'reactivated';
			}
		);
		exit(is_wp_error($reactivated) ? 2 : 0);
	}
	$old     = gmdate('c', time() - 172800);

	$base_candidate = array(
		'handle'      => 'example@fix-retention-safety',
		'repo'        => 'example',
		'branch'      => 'fix/retention-safety',
		'path'        => $work,
		'signal'      => 'remote-tracking-clean',
		'reason_code' => 'remote-tracking-clean',
		'metadata'    => array(
			'lifecycle_state' => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
			'last_seen_at'    => $old,
		),
	);

	$active_candidate                         = $base_candidate;
	$active_candidate['metadata']['lifecycle_state'] = WorktreeContextInjector::STATE_ACTIVE;
	GitHubAbilities::$mode                    = 'none';
	$active                                   = $harness->revalidate($active_candidate);
	retention_apply_protections_assert('active_lifecycle' === ( $active['skipped']['reason_code'] ?? null ), 'active lifecycle rows are protected from apply removal');

	$finalized_active_candidate = $active_candidate;
	$finalized_active_candidate['metadata'] = array_merge($finalized_active_candidate['metadata'], array(
		'finalized_state' => WorktreeContextInjector::STATE_MERGED,
		'finalized_at' => $old,
		'cleanup_eligible_at' => $old,
		'pr_url' => 'https://github.com/Extra-Chill/example/pull/864',
	));
	$finalized_active = $harness->revalidate($finalized_active_candidate);
	retention_apply_protections_assert(! isset($finalized_active['skipped']), 'durably finalized active metadata is removable after normal revalidation');
	$harness->status_output = ' M dirty.php';
	$dirty_finalized = $harness->revalidate($finalized_active_candidate);
	retention_apply_protections_assert('dirty_worktree' === ( $dirty_finalized['skipped']['reason_code'] ?? null ), 'dirty finalized rows remain blocked by deletion safeguards');
	$harness->status_output = '';
	$harness->unpushed_count = 1;
	$unpushed_finalized = $harness->revalidate($finalized_active_candidate);
	retention_apply_protections_assert('unpushed_commits' === ( $unpushed_finalized['skipped']['reason_code'] ?? null ), 'unpushed finalized rows remain blocked by deletion safeguards');
	$harness->unpushed_count = 0;

	$recent_candidate                         = $base_candidate;
	$recent_candidate['metadata']['last_seen_at'] = gmdate('c', time() - 60);
	$recent_candidate['metadata']['observed_at']  = gmdate('c', time() - 60);
	$recent                                   = $harness->revalidate($recent_candidate);
	retention_apply_protections_assert(! isset($recent['skipped']), 'recent observation heartbeats do not protect cleanup-eligible rows from apply removal');

	$artifact_path = $work . '/vendor';
	mkdir($artifact_path, 0777, true);
	file_put_contents($artifact_path . '/generated.php', '<?php');
	$cleanup_started_at = time();
	touch($work, $cleanup_started_at - 172800);
	$artifact_cleanup = $harness->remove_artifact($work, 'vendor');
	retention_apply_protections_assert(is_array($artifact_cleanup), 'artifact cleanup should remove the declared artifact path');
	retention_apply_protections_assert(! is_dir($artifact_path), 'artifact cleanup should remove the artifact directory');
	clearstatcache(true, $work);
	$root_mtime = filemtime($work);
	retention_apply_protections_assert(false !== $root_mtime, 'artifact cleanup should leave worktree root mtime available for legacy metadata backfill');
	retention_apply_protections_assert((int) $root_mtime >= $cleanup_started_at, 'artifact cleanup should update the worktree root mtime');
	$artifact_maintenance_candidate                         = $base_candidate;
	$artifact_maintenance_candidate['metadata']['created_at'] = gmdate('c', (int) $root_mtime);
	$artifact_maintenance = $harness->revalidate($artifact_maintenance_candidate);
	retention_apply_protections_assert(! isset($artifact_maintenance['skipped']), 'artifact cleanup root mtime does not manufacture recent activity protection for cleanup-eligible worktrees');

	$recent_lifecycle_candidate                                      = $base_candidate;
	$recent_lifecycle_candidate['metadata']['cleanup_eligible_at'] = gmdate('c', time() - 60);
	$recent_lifecycle                                                = $harness->revalidate($recent_lifecycle_candidate);
	retention_apply_protections_assert('recent_activity' === ( $recent_lifecycle['skipped']['reason_code'] ?? null ), 'recent cleanup_eligible_at rows are protected from apply removal');
	retention_apply_protections_assert('cleanup_eligible_at' === ( $recent_lifecycle['skipped']['activity_field'] ?? null ), 'recent lifecycle protection identifies the lifecycle activity field');
	retention_apply_protections_assert(86400 === ( $recent_lifecycle['skipped']['recency_window_seconds'] ?? null ), 'recent lifecycle protection documents its bounded expiry');

	$owner_terminal_candidate = $recent_lifecycle_candidate;
	$owner_terminal_candidate['metadata'] = array_merge($owner_terminal_candidate['metadata'], array(
		'purpose' => 'test-disposable', 'owner_run_ref' => 'run-991', 'cleanup_policy' => 'remove_on_success', 'owner_terminal_outcome' => 'success',
	));
	$owner_terminal = $harness->revalidate($owner_terminal_candidate);
	retention_apply_protections_assert(! isset($owner_terminal['skipped']), 'successful owner-terminal disposable worktrees are immediately eligible despite their finalization timestamp');

	$expired_lifecycle_candidate                                      = $base_candidate;
	$expired_lifecycle_candidate['metadata']['cleanup_eligible_at'] = gmdate('c', time() - 86401);
	$expired_lifecycle                                                = $harness->revalidate($expired_lifecycle_candidate);
	retention_apply_protections_assert(! isset($expired_lifecycle['skipped']), 'cleanup eligibility becomes removable after the documented recency window expires');

	$reviewed_lifecycle_candidate                                      = $base_candidate;
	$reviewed_lifecycle_candidate['metadata']['cleanup_eligible_at'] = gmdate('c', time() - 60);
	$reviewed_lifecycle                                              = $harness->revalidate_reviewed($reviewed_lifecycle_candidate, $reviewed_lifecycle_candidate['metadata']);
	retention_apply_protections_assert(! isset($reviewed_lifecycle['skipped']), 'reviewed rows with unchanged recent lifecycle metadata remain removable');

	$changed_lifecycle_candidate                               = $reviewed_lifecycle_candidate;
	$changed_lifecycle_candidate['metadata']['finalized_at'] = gmdate('c', time() - 30);
	$changed_lifecycle                                        = $harness->revalidate_reviewed($changed_lifecycle_candidate, $reviewed_lifecycle_candidate['metadata']);
	retention_apply_protections_assert('recent_activity' === ( $changed_lifecycle['skipped']['reason_code'] ?? null ), 'reviewed rows with changed lifecycle metadata remain protected');

	GitHubAbilities::$mode = 'open';
	$open_pr              = $harness->revalidate($base_candidate);
	retention_apply_protections_assert('open_pr' === ( $open_pr['skipped']['reason_code'] ?? null ), 'open PR heads are protected from apply removal');

	GitHubAbilities::$mode = 'error';
	$unverified           = $harness->revalidate($base_candidate);
	retention_apply_protections_assert('skipped_unverified' === ( $unverified['skipped']['reason_code'] ?? null ), 'GitHub lookup failures fail safe as skipped_unverified');

	GitHubAbilities::$mode = 'none';
	$removable            = $harness->revalidate($base_candidate);
	retention_apply_protections_assert(! isset($removable['skipped']), 'finalized remote-tracking-clean rows remain removable when no open PR exists and GitHub is verified');

	$git_target = $primary . '/.git/worktrees/fix-retention-safety';
	rmdir($git_target);
	$broken_candidate                   = $base_candidate;
	$broken_candidate['signal']         = 'broken_orphan';
	$broken_candidate['reason_code']    = 'broken_orphan';
	$broken_candidate['classification'] = 'broken_orphan';
	$broken = $harness->revalidate($broken_candidate);
	retention_apply_protections_assert('broken_orphan' === ( $broken['reason_code'] ?? null ), 'fresh revalidation must classify a missing managed Git metadata target without running Git probes');
	mkdir($git_target, 0777, true);
	$restored = $harness->revalidate($broken_candidate);
	retention_apply_protections_assert('broken_orphan_revalidation_failed' === ( $restored['skipped']['reason_code'] ?? null ), 'metadata restored after planning must block broken orphan removal');

	$became_live_metadata = array(
		'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE,
		'last_seen_at'    => gmdate('c'),
	);
	$became_live          = $harness->revalidate_current($base_candidate, $became_live_metadata);
	retention_apply_protections_assert('live_worktree' === ( $became_live['skipped']['reason_code'] ?? null ), 'a worktree that becomes live after planning is protected during apply revalidation');
	retention_apply_protections_assert('heartbeat_fresh' === ( $became_live['skipped']['liveness_reason'] ?? null ), 'apply-time protection surfaces fresh liveness evidence');

	$owner_a_finalized_at = gmdate('c', time() - 172800);
	$owner_a_candidate    = $base_candidate;
	$owner_a_candidate['metadata'] = array_merge($owner_a_candidate['metadata'], array(
		'purpose'                         => 'coding-session',
		'owner_run_ref'                   => 'owner-a',
		'cleanup_policy'                  => WorktreeContextInjector::CLEANUP_POLICY_REMOVE_ON_SUCCESS,
		'owner_terminal_outcome'          => 'success',
		'owner_terminal_at'               => $owner_a_finalized_at,
		'owner_terminal_owner_run_ref'    => 'owner-a',
		'finalized_at'                    => $owner_a_finalized_at,
		'finalized_state'                 => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'finalized_owner_run_ref'         => 'owner-a',
		'cleanup_eligible_at'             => $owner_a_finalized_at,
	));
	$owner_b_claimed_at = gmdate('c', time() - 90000);
	$owner_b_active     = array_merge($owner_a_candidate['metadata'], array(
		'lifecycle_state'  => WorktreeContextInjector::STATE_ACTIVE,
		'last_seen_at'     => gmdate('c', time() - WorktreeContextInjector::DEFAULT_HEARTBEAT_TTL_SECONDS - 1),
		'owner_run_ref'    => 'owner-b',
		'ownership_lineage' => array(array(
			'claimed_at'             => $owner_b_claimed_at,
			'previous_owner_run_ref' => 'owner-a',
			'new_owner_run_ref'      => 'owner-b',
		)),
	));
	$GLOBALS['retention_apply_metadata'][ $owner_a_candidate['handle'] ] = $owner_b_active;
	retention_apply_protections_assert(! WorktreeContextInjector::has_cleanup_signal($owner_b_active), 'owner A finalization must not remain a cleanup signal after owner B claims the worktree');
	$harness->unpushed_count = 2;
	$ownership_reuse_apply   = $harness->apply_reviewed($owner_a_candidate, true);
	retention_apply_protections_assert('active_lifecycle' === ( $ownership_reuse_apply['skipped'][0]['reason_code'] ?? null ), 'owner A terminal evidence must not authorize cleanup after owner B claims the worktree');
	retention_apply_protections_assert(is_dir($work) && is_dir($primary . '/.git/worktrees/fix-retention-safety'), 'cleanup must preserve the active unpushed worktree and its branch metadata after ownership reuse');
	$harness->unpushed_count = 0;

	$metadata_file = $root . '/metadata.json';
	$ready         = $root . '/reactivator-ready';
	$prelock_probe = $root . '/prelock-probe';
	file_put_contents($metadata_file, json_encode(array( $base_candidate['handle'] => $base_candidate['metadata'] )));
	$GLOBALS['retention_apply_metadata_file'] = $metadata_file;
	$GLOBALS['retention_apply_prelock_probe'] = $prelock_probe;
	$reactivator = proc_open(array( PHP_BINARY, __FILE__, 'reactivator', $metadata_file, $root, $ready, $prelock_probe ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $reactivator_pipes);
	retention_apply_protections_assert(is_resource($reactivator), 'concurrent reuse reactivator must start');
	fclose($reactivator_pipes[0]);
	$deadline = microtime(true) + 3;
	while ( ! is_file($ready) && microtime(true) < $deadline ) {
		usleep(10000);
	}
	retention_apply_protections_assert(is_file($ready), 'concurrent reuse reactivator must acquire the repository lock');
	$reactivated_apply = $harness->apply_reviewed($base_candidate);
	stream_get_contents($reactivator_pipes[1]);
	stream_get_contents($reactivator_pipes[2]);
	fclose($reactivator_pipes[1]);
	fclose($reactivator_pipes[2]);
	retention_apply_protections_assert(0 === proc_close($reactivator), 'concurrent reuse reactivator must complete');
	retention_apply_protections_assert('live_worktree' === ( $reactivated_apply['skipped'][0]['reason_code'] ?? null ), 'reactivation while the repository lock is held must skip removal');
	retention_apply_protections_assert(! is_file($prelock_probe), 'locked revalidation must not begin Git safety probes before acquiring the repository lock');
	unset($GLOBALS['retention_apply_metadata_file'], $GLOBALS['retention_apply_prelock_probe']);

	fwrite(STDOUT, "worktree-retention-apply-protections ok\n");
}
