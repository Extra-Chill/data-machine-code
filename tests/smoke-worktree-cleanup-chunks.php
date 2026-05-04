<?php
/**
 * Smoke test for job-backed cleanup chunks.
 *
 *   php tests/smoke-worktree-cleanup-chunks.php
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

namespace DataMachine\Engine\AI\System\Tasks {
	if ( ! class_exists( __NAMESPACE__ . '\\SystemTask' ) ) {
		abstract class SystemTask {
			abstract public function executeTask( int $jobId, array $params ): void;
			abstract public function getTaskType(): string;
			protected function completeJob( int $jobId, array $data ): void {
				$GLOBALS['datamachine_code_chunk_jobs'][ $jobId ] = $data;
			}
			protected function failJob( int $jobId, string $message ): void {
				$GLOBALS['datamachine_code_chunk_jobs'][ $jobId ] = array( 'error' => $message );
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
	$tmp      = sys_get_temp_dir() . '/dmc-cleanup-chunk-smoke-' . bin2hex( random_bytes( 4 ) );
	$site_tmp = $tmp . '-site';
	mkdir( $tmp, 0755, true );
	mkdir( $site_tmp . '/wp-content/plugins', 0755, true );
	mkdir( $site_tmp . '/wp-content/themes', 0755, true );

	register_shutdown_function( function () use ( $tmp, $site_tmp ): void {
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
	require __DIR__ . '/../inc/Tasks/WorktreeCleanupChunkTask.php';

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec( 'git --version 2>&1', $_gv, $gv_exit );
	if ( 0 !== $gv_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	$failures = 0;
	$total    = 0;
	$datamachine_code_test_options = array();
	$GLOBALS['datamachine_code_chunk_jobs'] = array();

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
	$run( 'git checkout -b clean', $primary );
	file_put_contents( $primary . '/clean.txt', 'clean' );
	$run( 'git add clean.txt && git commit -m clean', $primary );
	$run( 'git push -u origin clean', $primary );
	$run( 'git checkout main', $primary );
	$run( sprintf( 'git worktree add %s clean', escapeshellarg( $tmp . '/demo@clean' ) ), $primary );
	mkdir( $tmp . '/demo@clean/target', 0755, true );
	file_put_contents( $tmp . '/demo@clean/target/artifact.bin', str_repeat( 'x', 2048 ) );

	$workspace = new \DataMachineCode\Workspace\Workspace();
	$plan      = $workspace->worktree_cleanup_artifacts( array( 'dry_run' => true ) );
	$task      = new \DataMachineCode\Tasks\WorktreeCleanupChunkTask();

	echo "=== smoke-worktree-cleanup-chunks ===\n";

	$assert( false, is_wp_error( $plan ), 'artifact dry-run plan succeeds' );
	$rows = array_values( (array) ( $plan['candidates'] ?? array() ) );
	$assert( 1, count( $rows ), 'plan contains one artifact row' );

	$task->executeTask( 101, array( 'chunk_type' => 'artifacts', 'rows' => $rows ) );
	$first = $GLOBALS['datamachine_code_chunk_jobs'][101] ?? array();
	$assert( true, (bool) ( $first['success'] ?? false ), 'first chunk succeeds' );
	$assert( 1, (int) ( $first['applied_count'] ?? 0 ), 'first chunk records applied row' );
	$assert( 0, (int) ( $first['failed_count'] ?? -1 ), 'first chunk records zero failed rows' );
	$assert( true, (int) ( $first['bytes_reclaimed'] ?? 0 ) > 0, 'first chunk records reclaimed bytes' );
	$assert( true, (int) ( $first['elapsed_ms'] ?? -1 ) >= 0, 'first chunk records elapsed time' );
	$assert( array( 'demo@clean' ), $first['evidence']['planned_handles'] ?? array(), 'first chunk evidence includes planned handle' );
	$assert( false, is_dir( $tmp . '/demo@clean/target' ), 'artifact directory removed' );

	$task->executeTask( 102, array( 'chunk_type' => 'artifacts', 'rows' => $rows ) );
	$retry = $GLOBALS['datamachine_code_chunk_jobs'][102] ?? array();
	$assert( true, (bool) ( $retry['success'] ?? false ), 'retry chunk succeeds' );
	$assert( 0, (int) ( $retry['applied_count'] ?? -1 ), 'retry does not reapply missing artifact' );
	$assert( 1, (int) ( $retry['skipped_count'] ?? 0 ), 'retry records skipped row' );
	$assert( 'artifact_already_removed', $retry['skipped'][0]['reason_code'] ?? '', 'missing artifact is treated as already complete' );
	$assert( array( 'demo@clean' ), $retry['evidence']['skipped_handles'] ?? array(), 'retry evidence includes skipped handle' );

	mkdir( $tmp . '/demo@clean/target', 0755, true );
	file_put_contents( $tmp . '/demo@clean/target/artifact.bin', str_repeat( 'y', 1024 ) );
	$task->executeTask( 103, array( 'chunk_type' => 'artifact_discovery', 'limit' => 10, 'offset' => 0 ) );
	$discovery = $GLOBALS['datamachine_code_chunk_jobs'][103] ?? array();
	$assert( true, (bool) ( $discovery['success'] ?? false ), 'artifact discovery chunk succeeds' );
	$assert( 1, (int) ( $discovery['planned_count'] ?? 0 ), 'artifact discovery chunk plans bounded safe rows' );
	$assert( 1, (int) ( $discovery['applied_count'] ?? 0 ), 'artifact discovery chunk applies planned artifact rows' );
	$assert( false, is_dir( $tmp . '/demo@clean/target' ), 'artifact discovery chunk removes artifact directory' );
	$assert( 'bounded_inventory_safety', $discovery['evidence']['pagination']['mode'] ?? '', 'artifact discovery chunk uses bounded inventory pagination with safety probes' );

	if ( $failures > 0 ) {
		echo "\nFAILURES: {$failures}/{$total}\n";
		exit( 1 );
	}

	echo "\nAll {$total} cleanup chunk smoke assertions passed.\n";
}
