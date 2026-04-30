<?php
/**
 * Smoke test for artifact-only worktree cleanup.
 *
 *   php tests/smoke-worktree-cleanup-artifacts.php
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
	$tmp      = sys_get_temp_dir() . '/dmc-artifact-cleanup-smoke-' . bin2hex( random_bytes( 4 ) );
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

	$run = function ( string $cmd, string $cwd = '' ): void {
		$full = '' === $cwd ? $cmd : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $cmd );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $full . ' 2>&1', $out, $rc );
		if ( 0 !== $rc ) {
			throw new RuntimeException( "Command failed: {$full}\n" . implode( "\n", $out ) );
		}
	};

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

	$make_branch = function ( string $branch ) use ( $primary, $run ): void {
		$run( sprintf( 'git checkout -b %s', escapeshellarg( $branch ) ), $primary );
		file_put_contents( $primary . '/' . $branch . '.txt', $branch );
		$run( sprintf( 'git add . && git commit -m %s', escapeshellarg( 'work ' . $branch ) ), $primary );
		$run( sprintf( 'git push -u origin %s', escapeshellarg( $branch ) ), $primary );
		$run( 'git checkout main', $primary );
	};

	foreach ( array( 'clean', 'dirty', 'unpushed', 'active' ) as $branch ) {
		$make_branch( $branch );
		$run( sprintf( 'git worktree add %s %s', escapeshellarg( $tmp . '/demo@' . $branch ), escapeshellarg( $branch ) ), $primary );
		mkdir( $tmp . '/demo@' . $branch . '/target', 0755, true );
		file_put_contents( $tmp . '/demo@' . $branch . '/target/artifact.bin', str_repeat( $branch, 128 ) );
	}

	file_put_contents( $tmp . '/demo@dirty/scratch.txt', 'dirty' );
	file_put_contents( $tmp . '/demo@unpushed/local.txt', 'local' );
	$run( 'git add local.txt && git commit -m local', $tmp . '/demo@unpushed' );
	symlink( $tmp . '/demo@active', $site_tmp . '/wp-content/plugins/demo-active' );

	$workspace = new \DataMachineCode\Workspace\Workspace();

	echo "=== smoke-worktree-cleanup-artifacts ===\n";

	$plan = $workspace->worktree_cleanup_artifacts( array( 'dry_run' => true ) );
	$assert( false, is_wp_error( $plan ), 'dry-run returns a plan' );
	$assert( true, (bool) ( $plan['dry_run'] ?? false ), 'dry-run flag is true' );
	$assert( 1, count( $plan['candidates'] ?? array() ), 'only clean non-active worktree is candidate by default' );
	$assert( 'demo@clean', $plan['candidates'][0]['handle'] ?? '', 'clean worktree is candidate' );
	$assert( 'target', $plan['candidates'][0]['artifacts'][0]['path'] ?? '', 'candidate artifact path comes from profile' );
	$assert( true, is_dir( $tmp . '/demo@clean/target' ), 'dry-run does not delete target directory' );

	$skip_reasons = array_column( $plan['skipped'] ?? array(), 'reason_code', 'handle' );
	$assert( 'dirty_worktree', $skip_reasons['demo@dirty'] ?? '', 'dirty worktree is protected' );
	$assert( 'unpushed_commits', $skip_reasons['demo@unpushed'] ?? '', 'unpushed worktree is protected' );
	$assert( 'active_symlink_target', $skip_reasons['demo@active'] ?? '', 'active plugin symlink target is protected' );

	$direct_apply = $workspace->worktree_cleanup_artifacts( array() );
	$assert( true, is_wp_error( $direct_apply ), 'direct apply without plan is rejected' );
	$assert( 'artifact_cleanup_plan_required', $direct_apply->code ?? '', 'direct apply error is explicit' );

	$source_plan = $plan;
	$source_plan['candidates'][0]['artifacts'] = array( array( 'path' => 'README.md', 'size_bytes' => 4 ) );
	$source_apply = $workspace->worktree_cleanup_artifacts( array( 'apply_plan' => $source_plan ) );
	$assert( false, is_wp_error( $source_apply ), 'source-file-shaped artifact plan returns a report, not deletion' );
	$assert( 'artifact_plan_mismatch', $source_apply['skipped'][0]['reason_code'] ?? '', 'source-file path is rejected by profile revalidation' );
	$assert( true, is_file( $tmp . '/demo@clean/README.md' ), 'source file remains after mismatched plan' );
	$assert( true, is_dir( $tmp . '/demo@clean/target' ), 'real artifact remains after mismatched plan' );

	$apply = $workspace->worktree_cleanup_artifacts( array( 'apply_plan' => $plan ) );
	$assert( false, is_wp_error( $apply ), 'apply-plan returns report' );
	$assert( false, (bool) ( $apply['dry_run'] ?? true ), 'apply-plan is destructive mode' );
	$assert( 1, (int) ( $apply['summary']['removed_artifacts'] ?? 0 ), 'apply-plan reports removed artifact count' );
	$assert( false, is_dir( $tmp . '/demo@clean/target' ), 'apply-plan removes artifact directory' );
	$assert( true, is_dir( $tmp . '/demo@clean' ), 'apply-plan leaves worktree directory in place' );

	$force_plan = $workspace->worktree_cleanup_artifacts( array( 'dry_run' => true, 'force' => true ) );
	$force_handles = array_column( $force_plan['candidates'] ?? array(), 'handle' );
	$assert( true, in_array( 'demo@dirty', $force_handles, true ), 'force permits dirty artifact candidate' );
	$assert( true, in_array( 'demo@unpushed', $force_handles, true ), 'force permits unpushed artifact candidate' );
	$force_skip_reasons = array_column( $force_plan['skipped'] ?? array(), 'reason_code', 'handle' );
	$assert( 'active_symlink_target', $force_skip_reasons['demo@active'] ?? '', 'force still protects active symlink target' );

	if ( $failures > 0 ) {
		echo "\nFAILURES: {$failures}/{$total}\n";
		exit( 1 );
	}

	echo "\nAll {$total} artifact cleanup smoke assertions passed.\n";
}
