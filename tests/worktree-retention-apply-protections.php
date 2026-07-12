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
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';

	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Workspace\WorkspaceWorktreeCleanupEngine;
	use DataMachineCode\Workspace\WorktreeContextInjector;

	function retention_apply_protections_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	final class RetentionApplyProtectionHarness {
		use WorkspaceWorktreeCleanupEngine;

		protected const CLEANUP_GITHUB_TIMEOUT     = 5;
		protected const CLEANUP_GIT_PROBE_TIMEOUT  = 5;
		protected const CLEANUP_GIT_REMOVE_TIMEOUT = 60;
		protected const CLEANUP_GITHUB_MAX_PAGES   = 3;
		protected const CLEANUP_SUMMARY_TOP_LIMIT  = 10;

		public string $workspace_path;
		private string $primary_path;

		public function __construct( string $workspace_path, string $primary_path ) {
			$this->workspace_path = $workspace_path;
			$this->primary_path   = $primary_path;
		}

		public function revalidate( array $candidate ): array {
			$method = new ReflectionMethod($this, 'revalidate_bounded_cleanup_eligible_candidate');
			return $method->invoke($this, $candidate, false, false, false);
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
			return array( 'output' => '' );
		}

		private function count_unpushed_commits( string $path, int $timeout = 0 ): int|WP_Error {
			return 0;
		}

		private function git_get_remote( string $path ): ?string {
			return 'https://github.com/Extra-Chill/example.git';
		}
	}

	$root    = sys_get_temp_dir() . '/dmc-retention-apply-protections-' . getmypid();
	$primary = $root . '/example';
	$work    = $root . '/example@fix-retention-safety';
	mkdir($primary . '/.git', 0777, true);
	mkdir($work, 0777, true);
	file_put_contents($work . '/.git', 'gitdir: ' . $primary . '/.git/worktrees/fix-retention-safety');

	$harness = new RetentionApplyProtectionHarness($root, $primary);
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

	$recent_candidate                         = $base_candidate;
	$recent_candidate['metadata']['last_seen_at'] = gmdate('c', time() - 60);
	$recent_candidate['metadata']['observed_at']  = gmdate('c', time() - 60);
	$recent                                   = $harness->revalidate($recent_candidate);
	retention_apply_protections_assert(! isset($recent['skipped']), 'recent observation heartbeats do not protect cleanup-eligible rows from apply removal');

	$recent_lifecycle_candidate                                      = $base_candidate;
	$recent_lifecycle_candidate['metadata']['cleanup_eligible_at'] = gmdate('c', time() - 60);
	$recent_lifecycle                                                = $harness->revalidate($recent_lifecycle_candidate);
	retention_apply_protections_assert('recent_activity' === ( $recent_lifecycle['skipped']['reason_code'] ?? null ), 'recent cleanup_eligible_at rows are protected from apply removal');
	retention_apply_protections_assert('cleanup_eligible_at' === ( $recent_lifecycle['skipped']['activity_field'] ?? null ), 'recent lifecycle protection identifies the lifecycle activity field');

	GitHubAbilities::$mode = 'open';
	$open_pr              = $harness->revalidate($base_candidate);
	retention_apply_protections_assert('open_pr' === ( $open_pr['skipped']['reason_code'] ?? null ), 'open PR heads are protected from apply removal');

	GitHubAbilities::$mode = 'error';
	$unverified           = $harness->revalidate($base_candidate);
	retention_apply_protections_assert('skipped_unverified' === ( $unverified['skipped']['reason_code'] ?? null ), 'GitHub lookup failures fail safe as skipped_unverified');

	GitHubAbilities::$mode = 'none';
	$removable            = $harness->revalidate($base_candidate);
	retention_apply_protections_assert(! isset($removable['skipped']), 'finalized remote-tracking-clean rows remain removable when no open PR exists and GitHub is verified');

	fwrite(STDOUT, "worktree-retention-apply-protections ok\n");
}
