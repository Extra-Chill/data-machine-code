<?php
/**
 * Integration smoke test for worktree_list scalability flags.
 *
 * Builds a small real workspace and exercises the cheap-listing opt-out path
 * added for issue #213 (worktree list timed out on a workspace with ~800
 * worktrees because every row ran `git status --porcelain` plus multiple
 * `du -sk` probes). The test asserts:
 *
 *   - Default `worktree_list()` (no opts) keeps the pre-fix full-probe shape
 *     so internal callers (cleanup, hygiene with full status, etc.) are
 *     unchanged.
 *   - `include_status=false, include_disk=false` returns rows with `dirty`
 *     and `size_bytes` as null, populates `fields_skipped`, and skips both
 *     the per-row `git status` and `du` probes (zero exec calls).
 *   - The cheap listing still surfaces handle/repo/branch/head/lifecycle
 *     metadata and the `missing_metadata` stale_reason without probes.
 *   - `include_status=true, include_disk=false` only runs `git status`,
 *     not `du`.
 *
 * Run: php tests/smoke-worktree-list-scaling.php
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

	// Skip if git missing.
	exec( 'git --version 2>&1', $_gv, $gv_exit );
	if ( 0 !== $gv_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	$tmp = sys_get_temp_dir() . '/dmc-list-scaling-smoke-' . bin2hex( random_bytes( 4 ) );
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

	$make_branch( 'feat-one' );
	$make_branch( 'feat-two' );
	$make_branch( 'feat-three' );

	$run( sprintf( 'git worktree add %s feat-one', escapeshellarg( $tmp . '/demo@feat-one' ) ), $primary );
	$run( sprintf( 'git worktree add %s feat-two', escapeshellarg( $tmp . '/demo@feat-two' ) ), $primary );
	$run( sprintf( 'git worktree add %s feat-three', escapeshellarg( $tmp . '/demo@feat-three' ) ), $primary );
	$run( 'git checkout -b local-maintenance', $primary );
	$run( sprintf( 'git worktree add %s main', escapeshellarg( $tmp . '/demo@main' ) ), $primary );

	// Dirty one of them so the status probe has something to count.
	file_put_contents( $tmp . '/demo@feat-two/scratch.txt', 'dirty' );

	// Lifecycle metadata — feat-one has fresh metadata, feat-two doesn't,
	// feat-three is metadata-less. The cheap listing should still surface
	// `missing_metadata` for feat-two and feat-three.
	\DataMachineCode\Workspace\WorktreeContextInjector::store_metadata(
		'demo@feat-one',
		array(
			'site_url'   => 'http://example.test',
			'site_name'  => 'Example',
			'agent_slug' => 'agent-one',
			'abspath'    => '/example',
			'timestamp'  => gmdate( 'c', time() - 3600 ),
		)
	);

	$ws = new \DataMachineCode\Workspace\Workspace();

	// -------------------------------------------------------------------------
	// Default behavior preserved
	// -------------------------------------------------------------------------
	echo "\nDefault listing (full probes — backward compat)\n";
	$default_res = $ws->worktree_list( 'demo' );
	$assert( true, ! is_wp_error( $default_res ) && ( $default_res['success'] ?? false ), 'default listing returns success' );
	$assert( array(), $default_res['fields_skipped'] ?? null, 'default listing reports no skipped fields' );
	$default_rows = $default_res['worktrees'] ?? array();
	$default_two  = array_values( array_filter( $default_rows, fn( $r ) => ( $r['handle'] ?? '' ) === 'demo@feat-two' ) )[0] ?? array();
	$assert( true, ( $default_two['dirty'] ?? null ) >= 1, 'default listing populates dirty count' );
	$assert( true, array_key_exists( 'size_bytes', $default_two ), 'default listing populates size_bytes key' );
	$assert( false, isset( $default_two['fields_skipped'] ), 'default rows are not annotated with fields_skipped' );

	// -------------------------------------------------------------------------
	// Cheap listing: include_status=false, include_disk=false
	// -------------------------------------------------------------------------
	echo "\nCheap listing (no per-row git status / du probes)\n";
	$cheap_res = $ws->worktree_list( 'demo', null, array(
		'include_status' => false,
		'include_disk'   => false,
	) );
	$assert( true, ! is_wp_error( $cheap_res ) && ( $cheap_res['success'] ?? false ), 'cheap listing returns success' );
	$assert( array( 'status', 'disk' ), $cheap_res['fields_skipped'] ?? null, 'cheap listing reports both probe groups skipped' );
	$cheap_rows = $cheap_res['worktrees'] ?? array();
	$assert( 5, count( $cheap_rows ), 'cheap listing still enumerates every worktree' );
	$cheap_two  = array_values( array_filter( $cheap_rows, fn( $r ) => ( $r['handle'] ?? '' ) === 'demo@feat-two' ) )[0] ?? array();
	// dirty=null is the structural signal that the per-row git status probe was skipped.
	$assert( true, array_key_exists( 'dirty', $cheap_two ) && null === $cheap_two['dirty'], 'cheap listing leaves dirty as null (status probe skipped)' );
	$assert( true, array_key_exists( 'size_bytes', $cheap_two ) && null === $cheap_two['size_bytes'], 'cheap listing leaves size_bytes as null (du probe skipped)' );
	$assert( 0, (int) ( $cheap_two['artifact_size_bytes'] ?? -1 ), 'cheap listing reports artifact_size_bytes as 0' );
	$assert( array( 'status', 'disk' ), $cheap_two['fields_skipped'] ?? null, 'cheap row is annotated with fields_skipped' );

	// Cheap row still surfaces handle/repo/branch/head/lifecycle metadata.
	$assert( 'demo@feat-two', $cheap_two['handle'] ?? '', 'cheap row carries handle' );
	$assert( 'demo', $cheap_two['repo'] ?? '', 'cheap row carries repo' );
	$assert( 'feat-two', $cheap_two['branch'] ?? '', 'cheap row carries branch' );
	$assert( true, ! empty( $cheap_two['head'] ), 'cheap row carries head' );
	$cheap_main = array_values( array_filter( $cheap_rows, fn( $r ) => ( $r['handle'] ?? '' ) === 'demo@main' ) )[0] ?? array();
	$assert( 'base_branch_checked_out_in_worktree', $cheap_main['base_branch_warning']['reason_code'] ?? '', 'cheap listing flags non-primary worktree checked out on main' );
	$assert( 1, count( $cheap_res['base_branch_worktrees'] ?? array() ), 'cheap listing exposes base branch worktree warnings at top level' );
	$hygiene_res = $ws->workspace_hygiene_report( array(
		'include_cleanup' => false,
		'include_sizes'   => false,
	) );
	$assert( 1, (int) ( $hygiene_res['worktrees']['base_branch_worktree_count'] ?? 0 ), 'hygiene report summarizes base branch worktree warnings' );

	// Lifecycle / metadata-driven stale_reason still works without probes.
	// feat-two has NO lifecycle metadata → missing_metadata still fires.
	$assert( 'missing_metadata', $cheap_two['stale_reason'] ?? '', 'cheap listing surfaces missing_metadata when lifecycle metadata is absent' );

	$cheap_one = array_values( array_filter( $cheap_rows, fn( $r ) => ( $r['handle'] ?? '' ) === 'demo@feat-one' ) )[0] ?? array();
	// feat-one has lifecycle metadata. With status probe skipped, dirty cannot be inferred,
	// and feat-one is fresh → no stale_reason.
	$assert( '', (string) ( $cheap_one['stale_reason'] ?? '' ), 'cheap listing does not invent a stale_reason for healthy rows' );

	// -------------------------------------------------------------------------
	// Status-only listing
	// -------------------------------------------------------------------------
	echo "\nStatus-only listing (--with-status equivalent)\n";
	$status_res = $ws->worktree_list( 'demo', null, array(
		'include_status' => true,
		'include_disk'   => false,
	) );
	$assert( array( 'disk' ), $status_res['fields_skipped'] ?? null, 'status-only listing reports only disk skipped' );
	$status_two = array_values( array_filter( $status_res['worktrees'] ?? array(), fn( $r ) => ( $r['handle'] ?? '' ) === 'demo@feat-two' ) )[0] ?? array();
	$assert( true, ( $status_two['dirty'] ?? null ) >= 1, 'status-only listing populates dirty count' );
	$assert( true, array_key_exists( 'size_bytes', $status_two ) && null === $status_two['size_bytes'], 'status-only listing leaves size_bytes null' );
	// status detected dirty → stale_reason is "dirty".
	$assert( 'dirty', $status_two['stale_reason'] ?? '', 'status-only listing detects dirty stale_reason' );

	// -------------------------------------------------------------------------
	// Disk-only listing
	// -------------------------------------------------------------------------
	echo "\nDisk-only listing (--with-size equivalent)\n";
	$disk_res = $ws->worktree_list( 'demo', null, array(
		'include_status' => false,
		'include_disk'   => true,
	) );
	$assert( array( 'status' ), $disk_res['fields_skipped'] ?? null, 'disk-only listing reports only status skipped' );
	$disk_two = array_values( array_filter( $disk_res['worktrees'] ?? array(), fn( $r ) => ( $r['handle'] ?? '' ) === 'demo@feat-two' ) )[0] ?? array();
	$assert( true, array_key_exists( 'dirty', $disk_two ) && null === $disk_two['dirty'], 'disk-only listing leaves dirty null' );
	$assert( true, isset( $disk_two['size_bytes'] ) && (int) $disk_two['size_bytes'] >= 0, 'disk-only listing populates size_bytes' );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
