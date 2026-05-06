<?php
/**
 * Integration smoke test for worktree_bounded_cleanup_eligible_apply.
 *
 * Builds a real workspace in a tempdir, seeds inventory rows with a mix of
 * lifecycle states (cleanup_eligible, active, pr_opened with finalized_at,
 * dirty, unpushed, missing-metadata, primary), then runs the bounded cleanup-eligible apply
 * synchronously and asserts:
 *
 *   - Only worktrees with explicit lifecycle cleanup_eligible metadata
 *     surface as a bounded batch.
 *   - The cheap inventory path is used (no full git worktree_list call).
 *   - Each candidate is revalidated immediately before mutation: dirty,
 *     unpushed, missing-metadata, primary, and external rows are skipped.
 *   - Evidence reports processed/removed/skipped/bytes_reclaimed/continuation
 *     with stable shapes.
 *   - The bounded `limit` actually bounds the batch and surfaces remaining
 *     handles in `continuation`.
 *
 * Run: php tests/smoke-worktree-bounded-cleanup-eligible-apply.php
 *
 * Skips entirely if `git` is unavailable on $PATH.
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists( __NAMESPACE__ . '\\FilesystemHelper' ) ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace DataMachineCode\Abilities {
	if ( ! class_exists( __NAMESPACE__ . '\\GitHubAbilities' ) ) {
		class GitHubAbilities {
			public static function getPat(): string {
				return '';
			}
			public static function apiGet( string $url, array $params, string $pat ) {
				return array( 'data' => array() );
			}
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;
			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = (array) $data;
			}
			public function get_error_code(): string {
				return $this->code;
			}
			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $path ): bool {
			return is_dir( $path ) || mkdir( $path, 0755, true );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default_value = false ) {
			global $datamachine_code_test_options;
			return $datamachine_code_test_options[ $name ] ?? $default_value;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			global $datamachine_code_test_options;
			$datamachine_code_test_options[ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'size_format' ) ) {
		function size_format( $bytes ): string {
			return (string) $bytes;
		}
	}

	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	require __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

	exec( 'git --version 2>&1', $_gv, $gv_exit );
	if ( 0 !== $gv_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	$tmp = sys_get_temp_dir() . '/dmc-bounded-cleanup-eligible-apply-smoke-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	register_shutdown_function( function () use ( $tmp ) {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
	} );

	define( 'DATAMACHINE_WORKSPACE_PATH', realpath( $tmp ) ? realpath( $tmp ) : $tmp );

	$failures = 0;
	$total    = 0;
	$datamachine_code_test_options = array();

	$assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $expected === $actual ) {
			echo "  ✓ {$message}\n";
			return;
		}
		$failures++;
		echo "  ✗ {$message}\n";
		echo "    expected: " . var_export( $expected, true ) . "\n";
		echo "    actual:   " . var_export( $actual, true ) . "\n";
	};

	$assert_contains = function ( array $haystack, string $needle, string $message ) use ( &$failures, &$total ): void {
		$total++;
		foreach ( $haystack as $item ) {
			$handle = is_array( $item ) ? ( $item['handle'] ?? '' ) : (string) $item;
			if ( $handle === $needle ) {
				echo "  ✓ {$message}\n";
				return;
			}
		}
		$failures++;
		echo "  ✗ {$message}\n";
		echo "    needle:  {$needle}\n";
		echo "    haystack: " . implode( ', ', array_map( fn( $i ) => is_array( $i ) ? ( $i['handle'] ?? '?' ) : $i, $haystack ) ) . "\n";
	};

	$assert_skipped = function ( array $skipped, string $handle, string $reason_code, string $message ) use ( &$failures, &$total ): void {
		$total++;
		foreach ( $skipped as $row ) {
			if ( ( $row['handle'] ?? '' ) === $handle && ( $row['reason_code'] ?? '' ) === $reason_code ) {
				echo "  ✓ {$message}\n";
				return;
			}
		}
		$failures++;
		echo "  ✗ {$message}\n";
		echo "    needle:  {$handle} / {$reason_code}\n";
		echo "    haystack: " . implode( ', ', array_map( fn( $r ) => sprintf( '%s/%s', $r['handle'] ?? '?', $r['reason_code'] ?? '?' ), $skipped ) ) . "\n";
	};

	$run = function ( string $cmd, string $cwd = '' ) {
		$full = '' === $cwd ? $cmd : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $cmd );
		exec( $full . ' 2>&1', $out, $rc );
		return array(
			'output' => implode( "\n", $out ),
			'exit'   => $rc,
		);
	};

	echo "Setting up workspace at {$tmp}\n";

	$remote = $tmp . '/remote.git';
	$run( sprintf( 'git init --bare %s', escapeshellarg( $remote ) ) );

	$primary = $tmp . '/demo';
	$run( sprintf( 'git clone %s %s', escapeshellarg( $remote ), escapeshellarg( $primary ) ) );
	$run( 'git config user.email test@example.com', $primary );
	$run( 'git config user.name test', $primary );
	file_put_contents( $primary . '/README.md', "demo\n" );
	$run( 'git add README.md && git commit -m init', $primary );
	$run( 'git branch -M main', $primary );
	$run( 'git push -u origin main', $primary );

	$make_branch = function ( string $branch ) use ( $primary, $run ) {
		$run( sprintf( 'git checkout -b %s', escapeshellarg( $branch ) ), $primary );
		file_put_contents( $primary . '/' . str_replace( '/', '-', $branch ) . '.txt', $branch );
		$run( sprintf( 'git add . && git commit -m %s', escapeshellarg( 'work on ' . $branch ) ), $primary );
		$run( sprintf( 'git push -u origin %s', escapeshellarg( $branch ) ), $primary );
		$run( 'git checkout main', $primary );
	};

	// Real worktree-backed branches that simulate the canonical states the
	// bounded cleanup-eligible apply must handle.
	foreach ( array( 'eligible-clean', 'eligible-dirty', 'eligible-unpushed', 'eligible-bounded-extra-1', 'eligible-bounded-extra-2', 'eligible-bounded-extra-3', 'repaired-metadata-clean', 'repaired-metadata-dirty', 'repaired-metadata-recent' ) as $b ) {
		$make_branch( $b );
	}

	$run( sprintf( 'git worktree add %s eligible-clean', escapeshellarg( $tmp . '/demo@eligible-clean' ) ), $primary );
	$run( sprintf( 'git worktree add %s eligible-dirty', escapeshellarg( $tmp . '/demo@eligible-dirty' ) ), $primary );
	$run( sprintf( 'git worktree add %s eligible-unpushed', escapeshellarg( $tmp . '/demo@eligible-unpushed' ) ), $primary );
	$run( sprintf( 'git worktree add %s eligible-bounded-extra-1', escapeshellarg( $tmp . '/demo@eligible-bounded-extra-1' ) ), $primary );
	$run( sprintf( 'git worktree add %s eligible-bounded-extra-2', escapeshellarg( $tmp . '/demo@eligible-bounded-extra-2' ) ), $primary );
	$run( sprintf( 'git worktree add %s eligible-bounded-extra-3', escapeshellarg( $tmp . '/demo@eligible-bounded-extra-3' ) ), $primary );
	$run( sprintf( 'git worktree add %s repaired-metadata-clean', escapeshellarg( $tmp . '/demo@repaired-metadata-clean' ) ), $primary );
	$run( sprintf( 'git worktree add %s repaired-metadata-dirty', escapeshellarg( $tmp . '/demo@repaired-metadata-dirty' ) ), $primary );
	$run( sprintf( 'git worktree add %s repaired-metadata-recent', escapeshellarg( $tmp . '/demo@repaired-metadata-recent' ) ), $primary );

	// Mark cleanup-eligible lifecycle metadata so the cheap inventory picks
	// these up.
	foreach (
		array(
			'demo@eligible-clean',
			'demo@eligible-dirty',
			'demo@eligible-unpushed',
			'demo@eligible-bounded-extra-1',
			'demo@eligible-bounded-extra-2',
			'demo@eligible-bounded-extra-3',
		) as $handle
	) {
		\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
			$handle,
			array(
				'created_at'      => '2026-04-01T00:00:00+00:00',
				'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
				'pr_url'          => 'https://github.com/acme/demo/pull/42',
				'pr_number'       => 42,
			)
		);
	}

	// Make eligible-dirty actually dirty so the revalidation gate kicks in.
	file_put_contents( $tmp . '/demo@eligible-dirty/scratch.txt', 'dirty' );
	file_put_contents( $tmp . '/demo@repaired-metadata-dirty/scratch.txt', 'dirty' );

	// Make eligible-unpushed have an unpushed local commit (drop upstream so
	// `count_unpushed_commits` reports >0 only when the branch is ahead of its
	// upstream — easiest is one extra commit).
	file_put_contents( $tmp . '/demo@eligible-unpushed/local-only.txt', 'local' );
	$run( 'git add local-only.txt && git commit -m "unpushed change"', $tmp . '/demo@eligible-unpushed' );

	foreach (
		array(
			'demo@repaired-metadata-clean'  => '2026-04-01T00:00:00+00:00',
			'demo@repaired-metadata-dirty'  => '2026-04-01T00:00:00+00:00',
			'demo@repaired-metadata-recent' => gmdate( 'c' ),
		) as $handle => $created_at
	) {
		\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
			$handle,
			array(
				'created_at'        => $created_at,
				'lifecycle_state'   => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
				'metadata_repaired' => true,
			)
		);
	}

	// Active inventory row (no cleanup signal) — must never be a candidate.
	mkdir( $tmp . '/demo@active-row', 0755, true );
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@active-row',
		array(
			'created_at'      => '2026-04-01T00:00:00+00:00',
			'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
		)
	);

	// Inventory row with no metadata at all — bounded cleanup-eligible apply must never act on
	// it (inventory_only already skips it as `requires_full_scan`).
	mkdir( $tmp . '/demo@no-metadata', 0755, true );

	class Bounded_Apply_Inventory_Workspace extends \DataMachineCode\Workspace\Workspace {
		public int $full_listing_calls = 0;
		public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			++$this->full_listing_calls;
			return parent::worktree_list( $repo, $state, $opts );
		}
	}

	$ws = new Bounded_Apply_Inventory_Workspace();

	echo "\nDry-run scenario\n";
	$dry = $ws->worktree_bounded_cleanup_eligible_apply( array( 'dry_run' => true, 'limit' => 2 ) );
	$assert( true, ! is_wp_error( $dry ) && ( $dry['success'] ?? false ), 'dry-run returns success' );
	$assert( true, $dry['dry_run'] ?? false, 'dry-run flag echoes back true' );
	$assert( 'bounded_cleanup_eligible_apply', $dry['mode'] ?? '', 'mode is bounded_cleanup_eligible_apply' );
	$assert( false, $dry['destructive'] ?? true, 'dry-run is non-destructive' );
	$assert( 0, $ws->full_listing_calls, 'bounded cleanup-eligible apply dry-run does not call full worktree_list' );
	$assert( 2, count( $dry['candidates'] ?? array() ), 'bounded limit caps dry-run candidates' );
	$remaining = (int) ( $dry['continuation']['remaining_total'] ?? 0 );
	$assert( true, $remaining >= 4, 'continuation reports remaining cleanup-eligible candidates' );
	$assert_skipped( $dry['skipped'] ?? array(), 'demo@repaired-metadata-clean', 'active_no_signal', 'repaired metadata rows require explicit include flag' );

	$repaired_dry = $ws->worktree_bounded_cleanup_eligible_apply( array( 'dry_run' => true, 'limit' => 20, 'include_repaired_metadata' => true, 'older_than' => '24h' ) );
	$assert( true, ! is_wp_error( $repaired_dry ) && ( $repaired_dry['success'] ?? false ), 'repaired-metadata dry-run returns success' );
	$assert_contains( $repaired_dry['candidates'] ?? array(), 'demo@repaired-metadata-clean', 'repaired metadata clean worktree is candidate with explicit flag' );
	$assert_contains( $repaired_dry['candidates'] ?? array(), 'demo@repaired-metadata-dirty', 'repaired metadata dirty worktree is reviewed before apply' );
	$repaired_recent = array_values( array_filter( $repaired_dry['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@repaired-metadata-recent' ) );
	$assert( 'age_filter', $repaired_recent[0]['reason_code'] ?? '', 'repaired metadata rows honor older_than before apply' );

	// Active and missing-metadata rows must never appear as candidates.
	foreach ( $dry['candidates'] ?? array() as $cand ) {
		$h = (string) ( $cand['handle'] ?? '' );
		if ( 'demo@active-row' === $h || 'demo@no-metadata' === $h || 'demo' === $h ) {
			$failures++;
			$total++;
			echo "  ✗ bounded cleanup-eligible apply listed unsafe candidate: {$h}\n";
		}
	}

	echo "\nLimit validation\n";
	$bad_limit = $ws->worktree_bounded_cleanup_eligible_apply( array( 'dry_run' => true, 'limit' => 0 ) );
	$assert( true, is_wp_error( $bad_limit ), 'invalid limit returns WP_Error' );

	$bad_via_jobs_dry_run = $ws->worktree_bounded_cleanup_eligible_apply( array( 'dry_run' => true, 'via_jobs' => true ) );
	$assert( true, is_wp_error( $bad_via_jobs_dry_run ), 'via_jobs combined with dry_run is rejected' );

	echo "\nApply scenario (synchronous)\n";
	$apply = $ws->worktree_bounded_cleanup_eligible_apply( array( 'limit' => 10 ) );
	$assert( true, ! is_wp_error( $apply ) && ( $apply['success'] ?? false ), 'apply returns success' );
	$assert( false, $apply['dry_run'] ?? true, 'apply is not dry_run' );
	$assert( true, $apply['destructive'] ?? false, 'apply marks destructive=true' );
	$assert_contains( $apply['removed'] ?? array(), 'demo@eligible-clean', 'clean cleanup-eligible worktree was removed' );
	$assert_contains( $apply['removed'] ?? array(), 'demo@eligible-bounded-extra-1', 'bounded-extra-1 cleanup-eligible worktree was removed' );

	$assert_skipped( $apply['skipped'] ?? array(), 'demo@eligible-dirty', 'dirty_worktree', 'dirty worktree skipped on revalidation' );
	$assert_skipped( $apply['skipped'] ?? array(), 'demo@eligible-unpushed', 'unpushed_commits', 'unpushed worktree skipped on revalidation' );
	$assert_skipped( $apply['skipped'] ?? array(), 'demo@no-metadata', 'needs_metadata_reconcile', 'missing-metadata row skipped via inventory gate' );
	$assert_skipped( $apply['skipped'] ?? array(), 'demo@active-row', 'active_no_signal', 'active row skipped via inventory gate' );
	$assert_skipped( $apply['skipped'] ?? array(), 'demo@repaired-metadata-clean', 'active_no_signal', 'repaired metadata row skipped without explicit flag on apply' );

	$assert( true, is_dir( $primary . '/.git' ), 'primary survives bounded cleanup-eligible apply' );
	$assert( false, is_dir( $tmp . '/demo@eligible-clean' ), 'clean cleanup-eligible directory removed from disk' );
	$assert( true, is_dir( $tmp . '/demo@eligible-dirty' ), 'dirty cleanup-eligible directory survives bounded cleanup-eligible apply' );
	$assert( true, is_dir( $tmp . '/demo@eligible-unpushed' ), 'unpushed cleanup-eligible directory survives bounded cleanup-eligible apply' );

	$summary = (array) ( $apply['summary'] ?? array() );
	$assert( true, (int) ( $summary['removed'] ?? 0 ) >= 4, 'summary records removed count' );
	$assert( true, (int) ( $summary['skipped'] ?? 0 ) >= 4, 'summary records skipped count' );
	$assert( true, isset( $summary['bytes_reclaimed'] ), 'summary exposes bytes_reclaimed' );
	$assert( true, isset( $apply['continuation']['remaining_total'] ), 'apply exposes continuation envelope' );

	$repaired_apply = $ws->worktree_bounded_cleanup_eligible_apply( array( 'limit' => 20, 'include_repaired_metadata' => true, 'older_than' => '24h' ) );
	$assert( true, ! is_wp_error( $repaired_apply ) && ( $repaired_apply['success'] ?? false ), 'repaired-metadata apply returns success' );
	$assert_contains( $repaired_apply['removed'] ?? array(), 'demo@repaired-metadata-clean', 'repaired metadata clean worktree was removed after fresh probes' );
	$assert_skipped( $repaired_apply['skipped'] ?? array(), 'demo@repaired-metadata-dirty', 'dirty_worktree', 'repaired metadata dirty worktree skipped on fresh probe' );
	$assert_skipped( $repaired_apply['skipped'] ?? array(), 'demo@repaired-metadata-recent', 'age_filter', 'repaired metadata recent worktree skipped by age gate on apply' );
	$assert( false, is_dir( $tmp . '/demo@repaired-metadata-clean' ), 'repaired metadata clean directory removed from disk' );
	$assert( true, is_dir( $tmp . '/demo@repaired-metadata-dirty' ), 'repaired metadata dirty directory survives apply' );

	echo "\nForce gate (dirty allowed, unpushed never allowed)\n";
	$force_apply = $ws->worktree_bounded_cleanup_eligible_apply( array( 'limit' => 10, 'force' => true ) );
	$assert( true, ! is_wp_error( $force_apply ) && ( $force_apply['success'] ?? false ), 'force apply returns success' );
	// Dirty worktree should now be removed.
	$assert_contains( $force_apply['removed'] ?? array(), 'demo@eligible-dirty', 'force=true allows dirty cleanup-eligible removal' );
	// Unpushed worktree must still be skipped — even with force.
	$assert_skipped( $force_apply['skipped'] ?? array(), 'demo@eligible-unpushed', 'unpushed_commits', 'unpushed gate not overridden by force=true' );
	$assert( true, is_dir( $tmp . '/demo@eligible-unpushed' ), 'unpushed cleanup-eligible directory survives force apply' );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
