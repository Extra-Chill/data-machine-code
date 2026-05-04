<?php
/**
 * Smoke test for bounded artifact cleanup on a synthetic huge workspace.
 *
 * Builds an inventory of many fake worktrees plus one real git worktree with
 * a Cargo profile artifact, then asserts:
 *
 * - The bounded dry-run only scans up to `limit` worktrees.
 * - Pagination metadata reports total / scanned / next_offset correctly.
 * - The bounded scan does not invoke per-worktree git probes (it remains fast
 *   regardless of how many fake worktrees are in the workspace).
 *
 *   php tests/smoke-worktree-cleanup-artifacts-bounded.php
 *
 * @package DataMachineCode\Tests
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
			public static function apiGet( string $url, array $params, string $pat ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return array( 'data' => array() );
			}
		}
	}
}

namespace {
	$tmp      = sys_get_temp_dir() . '/dmc-artifact-bounded-smoke-' . bin2hex( random_bytes( 4 ) );
	$site_tmp = $tmp . '-site';
	mkdir( $tmp, 0755, true );
	mkdir( $site_tmp . '/wp-content/plugins', 0755, true );
	mkdir( $site_tmp . '/wp-content/themes', 0755, true );

	register_shutdown_function( function () use ( $tmp, $site_tmp ) {
		foreach ( array( $tmp, $site_tmp ) as $path ) {
			if ( is_dir( $path ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
				exec( 'rm -rf ' . escapeshellarg( $path ) );
			}
		}
	} );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $site_tmp . '/' );
	}
	if ( ! defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
		define( 'DATAMACHINE_WORKSPACE_PATH', realpath( $tmp ) ?: $tmp );
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
		function update_option( string $name, $value, $autoload = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			global $datamachine_code_test_options;
			$datamachine_code_test_options[ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $value;
		}
	}

	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	require __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec( 'git --version 2>&1', $_gv, $gv_exit );
	if ( 0 !== $gv_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	$failures = 0;
	$total    = 0;
	$datamachine_code_test_options = array();

	$assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $expected === $actual ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$assert_lt = function ( $bound, $actual, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $actual < $bound ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
		echo '    bound:  < ' . var_export( $bound, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$run = function ( string $cmd, string $cwd = '' ): void {
		$full = '' === $cwd ? $cmd : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $cmd );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $full . ' 2>&1', $out, $rc );
		if ( 0 !== $rc ) {
			throw new RuntimeException( "Command failed: {$full}\n" . implode( "\n", $out ) );
		}
	};

	echo "=== smoke-worktree-cleanup-artifacts-bounded ===\n";

	// Build one real git worktree with Cargo artifact, then surround it with
	// many synthetic worktree directories that look like `<repo>@<slug>` to
	// exercise the bounded inventory scan.
	$remote = $tmp . '/remote.git';
	$run( sprintf( 'git init --bare %s', escapeshellarg( $remote ) ) );

	$primary = $tmp . '/demo';
	$run( sprintf( 'git clone %s %s', escapeshellarg( $remote ), escapeshellarg( $primary ) ) );
	$run( 'git config user.email test@example.com', $primary );
	$run( 'git config user.name test', $primary );
	file_put_contents( $primary . '/README.md', "demo\n" );
	file_put_contents( $primary . '/Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n" );
	file_put_contents( $primary . '/.gitignore', "target/\n" );
	$run( 'git add README.md Cargo.toml .gitignore && git commit -m init', $primary );
	$run( 'git branch -M main', $primary );
	$run( 'git push -u origin main', $primary );

	// One real worktree with an artifact directory.
	$run( 'git checkout -b feature-real', $primary );
	file_put_contents( $primary . '/feature-real.txt', 'real' );
	$run( 'git add . && git commit -m feature', $primary );
	$run( 'git push -u origin feature-real', $primary );
	$run( 'git checkout main', $primary );
	$run( sprintf( 'git worktree add %s feature-real', escapeshellarg( $tmp . '/demo@feature-real' ) ), $primary );
	mkdir( $tmp . '/demo@feature-real/target', 0755, true );
	file_put_contents( $tmp . '/demo@feature-real/target/artifact.bin', str_repeat( 'x', 1024 ) );

	// Synthetic inventory entries — many `<repo>@<slug>` directories without
	// real git state. They satisfy the bounded inventory scan but would crash
	// the exhaustive `git status` path, which is why the bounded mode must
	// cap at `limit` and never call git for unscanned rows.
	$synthetic_count = 200;
	for ( $i = 0; $i < $synthetic_count; ++$i ) {
		$dir = sprintf( '%s/demo@synthetic-%03d', $tmp, $i );
		mkdir( $dir, 0755, true );
		// Sprinkle a Cargo profile + target dir on a slice of them so they
		// would surface as bounded candidates if scanned.
		if ( 0 === $i % 5 ) {
			file_put_contents( $dir . '/Cargo.toml', "[package]\nname = \"x\"\nversion = \"0.0.1\"\n" );
			mkdir( $dir . '/target', 0755, true );
			file_put_contents( $dir . '/target/.keep', 'a' );
		}
	}

	$workspace = new \DataMachineCode\Workspace\Workspace();

	// Default bounded dry-run honors the default cap.
	$start    = microtime( true );
	$default  = $workspace->worktree_cleanup_artifacts( array( 'dry_run' => true ) );
	$elapsed  = microtime( true ) - $start;
	$assert( false, is_wp_error( $default ), 'default bounded dry-run succeeds on huge synthetic workspace' );
	$assert( 'bounded_inventory', $default['pagination']['mode'] ?? '', 'default mode is bounded_inventory' );
	$assert( \DataMachineCode\Workspace\Workspace::ARTIFACT_CLEANUP_DEFAULT_LIMIT, (int) ( $default['pagination']['limit'] ?? 0 ), 'default limit is ARTIFACT_CLEANUP_DEFAULT_LIMIT' );
	$assert_lt( 5.0, $elapsed, 'default bounded scan completes well under five seconds (' . number_format( $elapsed, 3 ) . 's)' );
	$assert_lt( \DataMachineCode\Workspace\Workspace::ARTIFACT_CLEANUP_DEFAULT_LIMIT + 1, (int) ( $default['pagination']['scanned'] ?? PHP_INT_MAX ), 'default scan capped at <= default limit' );

	// Explicit limit/offset returns a small page and continuation.
	$start    = microtime( true );
	$page     = $workspace->worktree_cleanup_artifacts( array( 'dry_run' => true, 'limit' => 25, 'offset' => 0 ) );
	$elapsed  = microtime( true ) - $start;
	$assert( false, is_wp_error( $page ), 'limit=25 dry-run succeeds' );
	$assert( 25, (int) ( $page['pagination']['scanned'] ?? 0 ), 'limit=25 scanned exactly 25 rows' );
	$assert( true, (bool) ( $page['pagination']['partial'] ?? false ), 'limit=25 reports partial=true' );
	$assert( 25, (int) ( $page['pagination']['next_offset'] ?? 0 ), 'limit=25 next_offset advances by limit' );
	$assert_lt( 2.0, $elapsed, 'limit=25 page completes quickly (' . number_format( $elapsed, 3 ) . 's)' );

	// only_handles surfaces from apply_plan path: passing apply_plan with a
	// single planned candidate should restrict the scan to just that handle.
	// Re-build the plan from a real exhaustive dry-run so the planned path
	// matches the canonical realpath form `worktree_list` returns (`/private/...`
	// on macOS) — we can't construct the path string by hand without recreating
	// the symlink resolution that the apply revalidation does internally.
	$exhaustive_plan = $workspace->worktree_cleanup_artifacts(
		array( 'dry_run' => true, 'exhaustive' => true, 'limit' => 0 )
	);
	$assert( false, is_wp_error( $exhaustive_plan ), 'exhaustive scan succeeds for apply path setup' );
	$apply_plan = array( 'candidates' => array_values( array_filter(
		(array) ( $exhaustive_plan['candidates'] ?? array() ),
		fn( $row ) => 'demo@feature-real' === ( $row['handle'] ?? '' )
	) ) );
	$assert( 1, count( $apply_plan['candidates'] ), 'extracted exactly one planned candidate' );

	$start   = microtime( true );
	$apply   = $workspace->worktree_cleanup_artifacts( array( 'apply_plan' => $apply_plan ) );
	$elapsed = microtime( true ) - $start;
	$assert( false, is_wp_error( $apply ), 'apply_plan revalidation succeeds on huge workspace' );
	$assert( 'exhaustive', $apply['pagination']['mode'] ?? '', 'apply_plan revalidation runs in exhaustive (safety_probes=true) mode' );
	$assert( 1, (int) ( $apply['summary']['removed_artifacts'] ?? 0 ), 'apply_plan removes the planned artifact' );
	$assert( false, is_dir( $tmp . '/demo@feature-real/target' ), 'apply_plan revalidation removes the target directory' );
	$assert_lt( 5.0, $elapsed, 'apply_plan revalidation stays fast because only_handles narrows the scan (' . number_format( $elapsed, 3 ) . 's)' );

	if ( $failures > 0 ) {
		echo "\nFAILURES: {$failures}/{$total}\n";
		exit( 1 );
	}

	echo "\nAll {$total} bounded artifact cleanup smoke assertions passed.\n";
}
