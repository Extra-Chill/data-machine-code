<?php
/**
 * Deterministic cached-versus-network freshness coverage for workspace show.
 */

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	final class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}

namespace DataMachineCode\Workspace {
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCoreUtilities.php';

	final class PrimaryNetworkFreshnessHarness {
		use WorkspaceCoreUtilities {
			build_primary_freshness_report_from_status_output as public classify;
			refresh_primary_freshness_report as public refresh;
		}

		/** @var list<array{command:string,timeout:int}> */
		public array $commands = array();
		public bool $fail_fetch = false;
		public bool $fail_status = false;

		private function run_git( string $repo_path, string $git_args, int $timeout_seconds = 0 ): array|\WP_Error {
			$this->commands[] = array( 'command' => $git_args, 'timeout' => $timeout_seconds );
			if ( $this->fail_fetch && str_starts_with($git_args, 'fetch ') ) {
				return new \WP_Error('git_command_timeout', 'bounded fetch timed out');
			}
			if ( $this->fail_status && str_starts_with($git_args, 'status ') ) {
				return new \WP_Error('git_command_timeout', 'bounded status timed out');
			}

			$output = array();
			$status = 0;
			exec('git -C ' . escapeshellarg($repo_path) . ' ' . $git_args . ' 2>&1', $output, $status);
			if ( 0 !== $status ) {
				return new \WP_Error('git_command_failed', implode("\n", $output), array( 'output' => implode("\n", $output) ));
			}

			return array( 'success' => true, 'output' => implode("\n", $output) );
		}

		protected function primary_freshness_now(): string {
			return '2026-08-24T12:00:00+00:00';
		}
	}
}

namespace {
	use DataMachineCode\Workspace\PrimaryNetworkFreshnessHarness;

	function network_freshness_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
		}
	}

	function network_freshness_run( string $path, string $args ): string {
		$output = array();
		$status = 0;
		exec('git -C ' . escapeshellarg($path) . ' ' . $args . ' 2>&1', $output, $status);
		if ( 0 !== $status ) {
			throw new RuntimeException(sprintf('Git fixture command failed (%s): %s', $args, implode("\n", $output)));
		}
		return trim(implode("\n", $output));
	}

	function network_freshness_remove_tree( string $path ): void {
		foreach ( scandir($path) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $path . '/' . $entry;
			is_dir($child) && ! is_link($child) ? network_freshness_remove_tree($child) : unlink($child);
		}
		rmdir($path);
	}

	$root    = sys_get_temp_dir() . '/dmc-primary-network-freshness-' . bin2hex(random_bytes(4));
	$origin  = $root . '/origin.git';
	$seed    = $root . '/seed';
	$primary = $root . '/primary';
	mkdir($root, 0700, true);

	try {
		network_freshness_run($root, 'init -q --bare ' . escapeshellarg($origin));
		mkdir($seed, 0700);
		network_freshness_run($seed, 'init -q -b main');
		network_freshness_run($seed, 'config user.email test@example.com');
		network_freshness_run($seed, 'config user.name Test');
		file_put_contents($seed . '/fixture.txt', "one\n");
		network_freshness_run($seed, 'add fixture.txt');
		network_freshness_run($seed, 'commit -qm one');
		network_freshness_run($seed, 'remote add origin ' . escapeshellarg($origin));
		network_freshness_run($seed, 'push -qu origin main');
		network_freshness_run($root, '--git-dir=' . escapeshellarg($origin) . ' symbolic-ref HEAD refs/heads/main');
		network_freshness_run($root, 'clone -q ' . escapeshellarg($origin) . ' ' . escapeshellarg($primary));

		$observed_timestamp = 1704067200;
		touch($primary . '/.git/FETCH_HEAD', $observed_timestamp);
		$probe_output = shell_exec(
			escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/inc/Workspace/workspace-target-probe.php') . ' ' . escapeshellarg($primary)
		);
		$inspection = json_decode((string) $probe_output, true, 512, JSON_THROW_ON_ERROR);
		network_freshness_assert_same('2024-01-01T00:00:00+00:00', $inspection['tracking_ref_observed_at'] ?? null, 'Target inspection omitted the cached tracking observation time.');

		$harness = new PrimaryNetworkFreshnessHarness();
		$cached  = $harness->classify(
			(string) $inspection['branch_status'],
			'repo',
			(string) $inspection['tracking_ref_observed_at']
		);
		network_freshness_assert_same('local_tracking_current', $cached['status'] ?? null, 'Cached zero divergence used an authoritative current status.');
		network_freshness_assert_same('local_tracking', $cached['verification_scope'] ?? null, 'Cached freshness omitted its local tracking scope.');
		network_freshness_assert_same(false, $cached['network_verification_attempted'] ?? null, 'Default classification claimed a network attempt.');
		network_freshness_assert_same(null, $cached['remote_verified_at'] ?? null, 'Default classification claimed a remote verification time.');

		$cached_remote_sha = network_freshness_run($primary, 'rev-parse origin/main');
		file_put_contents($seed . '/fixture.txt', "two\n");
		network_freshness_run($seed, 'add fixture.txt');
		network_freshness_run($seed, 'commit -qm two');
		network_freshness_run($seed, 'push -q origin main');
		$advanced_remote_sha = network_freshness_run($seed, 'rev-parse HEAD');
		network_freshness_assert_same($cached_remote_sha, network_freshness_run($primary, 'rev-parse origin/main'), 'Fixture unexpectedly refreshed the cached tracking ref.');
		if ( $advanced_remote_sha === $cached_remote_sha ) {
			throw new RuntimeException('Fixture remote did not advance.');
		}

		$still_cached = $harness->classify(network_freshness_run($primary, 'status --porcelain=v1 --branch --untracked-files=no'), 'repo', '2024-01-01T00:00:00+00:00');
		network_freshness_assert_same('local_tracking_current', $still_cached['status'] ?? null, 'Stale tracking refs did not retain their explicitly cached classification.');

		$verified = $harness->refresh($primary, 'repo', $still_cached);
		network_freshness_assert_same('stale', $verified['status'] ?? null, 'Bounded refresh did not reveal the remote advancement.');
		network_freshness_assert_same(1, $verified['behind'] ?? null, 'Remote-verified behind count was incorrect.');
		network_freshness_assert_same('remote_verified', $verified['verification_scope'] ?? null, 'Successful refresh omitted remote verification scope.');
		network_freshness_assert_same(true, $verified['network_verification_attempted'] ?? null, 'Successful refresh omitted its network attempt.');
		network_freshness_assert_same('2026-08-24T12:00:00+00:00', $verified['remote_verified_at'] ?? null, 'Successful refresh omitted deterministic verification time.');
		network_freshness_assert_same(array( 8, 2 ), array_column(array_slice($harness->commands, -2), 'timeout'), 'Refresh did not compose fetch and status inside the 10-second budget.');

		network_freshness_run($primary, 'merge -q --ff-only origin/main');
		$local_after_merge = $harness->classify(network_freshness_run($primary, 'status --porcelain=v1 --branch --untracked-files=no'), 'repo');
		$current_verified  = $harness->refresh($primary, 'repo', $local_after_merge);
		network_freshness_assert_same('remote_verified_current', $current_verified['status'] ?? null, 'Successful fetch did not produce the qualified remote-current status.');

		$offline = new PrimaryNetworkFreshnessHarness();
		$offline->fail_fetch = true;
		$unverified = $offline->refresh($primary, 'repo', $local_after_merge);
		network_freshness_assert_same('local_tracking_current', $unverified['status'] ?? null, 'Failed fetch upgraded cached evidence.');
		network_freshness_assert_same('local_tracking', $unverified['verification_scope'] ?? null, 'Failed fetch changed the verification scope.');
		network_freshness_assert_same(true, $unverified['network_verification_attempted'] ?? null, 'Failed fetch omitted its attempted network verification.');
		network_freshness_assert_same(true, $unverified['verification_error']['timed_out'] ?? null, 'Failed bounded fetch omitted timeout evidence.');
		network_freshness_assert_same(10, $unverified['verification_timeout_seconds'] ?? null, 'Failed fetch omitted the aggregate timeout contract.');

		$status_failure = new PrimaryNetworkFreshnessHarness();
		$status_failure->fail_status = true;
		$indeterminate = $status_failure->refresh($primary, 'repo', $local_after_merge);
		network_freshness_assert_same('unknown', $indeterminate['status'] ?? null, 'A failed post-fetch comparison retained a cached current classification.');
		network_freshness_assert_same(null, $indeterminate['behind'] ?? null, 'A failed post-fetch comparison retained a cached behind count.');
		network_freshness_assert_same(true, $indeterminate['fetch_checked'] ?? null, 'A successful fetch was omitted from failed comparison evidence.');
		network_freshness_assert_same('2026-08-24T12:00:00+00:00', $indeterminate['tracking_ref_observed_at'] ?? null, 'A successful fetch omitted its deterministic tracking observation time.');
		network_freshness_assert_same(null, $indeterminate['remote_verified_at'] ?? null, 'A failed comparison claimed remote-verified freshness.');

		$no_upstream = $harness->classify('## main', 'repo');
		$no_upstream_result = $harness->refresh($primary, 'repo', $no_upstream);
		network_freshness_assert_same(false, $no_upstream_result['network_verification_attempted'] ?? null, 'Refresh without an upstream attempted an arbitrary remote.');
		network_freshness_assert_same('no_upstream', $no_upstream_result['verification_error']['code'] ?? null, 'Refresh without an upstream omitted its typed verification error.');
	} finally {
		network_freshness_remove_tree($root);
	}

	echo "workspace-primary-network-freshness: ok\n";
}
