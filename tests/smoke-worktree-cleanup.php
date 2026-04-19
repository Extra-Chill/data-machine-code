<?php
/**
 * Integration smoke test for worktree_cleanup_merged.
 *
 * Builds a real workspace in a tempdir with real git repos + real worktrees,
 * simulates various scenarios (merged branch, unmerged branch, dirty tree,
 * protected branch, legacy non-slug directory name), then runs cleanup and
 * asserts the correct worktrees got pruned.
 *
 * Run: php tests/smoke-worktree-cleanup.php
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
	// Stub so the GitHub code path short-circuits without network calls.
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
		function get_option( string $name, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists( 'size_format' ) ) {
		function size_format( $bytes ): string {
			return (string) $bytes;
		}
	}

	require __DIR__ . '/../inc/Workspace/Workspace.php';

	// Skip if git missing.
	exec( 'git --version 2>&1', $_gv, $gv_exit );
	if ( 0 !== $gv_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	// Create isolated workspace in tmp.
	$tmp = sys_get_temp_dir() . '/dmc-cleanup-smoke-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	register_shutdown_function( function () use ( $tmp ) {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
	} );

	define( 'DATAMACHINE_WORKSPACE_PATH', $tmp );

	$failures = 0;
	$total    = 0;

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

	$run = function ( string $cmd, string $cwd = '' ) {
		$full = '' === $cwd ? $cmd : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $cmd );
		exec( $full . ' 2>&1', $out, $rc );
		return array(
			'output' => implode( "\n", $out ),
			'exit'   => $rc,
		);
	};

	// -------------------------------------------------------------------------
	// Scenario setup
	// -------------------------------------------------------------------------

	echo "Setting up workspace at {$tmp}\n";

	// "remote" bare repo that stands in for origin.
	$remote = $tmp . '/remote.git';
	$run( sprintf( 'git init --bare %s', escapeshellarg( $remote ) ) );

	// Primary checkout: clone + initial commit on main + push.
	$primary = $tmp . '/demo';
	$run( sprintf( 'git clone %s %s', escapeshellarg( $remote ), escapeshellarg( $primary ) ) );
	$run( 'git config user.email test@example.com', $primary );
	$run( 'git config user.name test', $primary );
	file_put_contents( $primary . '/README.md', "demo\n" );
	$run( 'git add README.md && git commit -m init', $primary );
	$run( 'git branch -M main', $primary );
	$run( 'git push -u origin main', $primary );

	// Helper: make a branch on the remote too so "upstream" tracking exists.
	$make_branch = function ( string $branch, string $content ) use ( $primary, $run, $remote ) {
		$run( sprintf( 'git checkout -b %s', escapeshellarg( $branch ) ), $primary );
		file_put_contents( $primary . '/' . str_replace( '/', '-', $branch ) . '.txt', $content );
		$run( sprintf( 'git add . && git commit -m %s', escapeshellarg( 'work on ' . $branch ) ), $primary );
		$run( sprintf( 'git push -u origin %s', escapeshellarg( $branch ) ), $primary );
		$run( 'git checkout main', $primary );
	};

	// Branches that have branches on the remote.
	$make_branch( 'merged-autodelete', 'a' );   // → simulate remote delete below
	$make_branch( 'merged-live-remote', 'b' );  // → simulate via PR-merged (stubbed → none)
	$make_branch( 'unmerged-feature', 'c' );    // still active
	$make_branch( 'dirty-branch', 'd' );        // will be dirty in worktree

	// Create worktrees at various paths:
	//   - canonical slug path (demo@merged-autodelete)
	//   - legacy sibling path (demo-legacy-merged)
	//   - canonical path for the unmerged one
	//   - canonical path for dirty
	$run( sprintf( 'git worktree add %s merged-autodelete', escapeshellarg( $tmp . '/demo@merged-autodelete' ) ), $primary );
	$run( sprintf( 'git worktree add %s merged-live-remote', escapeshellarg( $tmp . '/demo-legacy-merged' ) ), $primary );
	$run( sprintf( 'git worktree add %s unmerged-feature', escapeshellarg( $tmp . '/demo@unmerged-feature' ) ), $primary );
	$run( sprintf( 'git worktree add %s dirty-branch', escapeshellarg( $tmp . '/demo@dirty-branch' ) ), $primary );

	// Dirty the dirty worktree.
	file_put_contents( $tmp . '/demo@dirty-branch/scratch.txt', 'dirty' );

	// Simulate "remote deleted the merged-autodelete branch" — classic
	// GitHub auto-delete-on-merge scenario. After a fetch --prune, the
	// local branch's upstream tracking ref will be "gone".
	$run( sprintf( 'git -C %s push origin --delete merged-autodelete', escapeshellarg( $remote ) ) );
	// (The bare remote "origin" is the URL; git push --delete pushes to
	// origin. But our origin IS the bare repo — so we need to update the
	// bare ref directly.)
	$run( sprintf( 'git --git-dir=%s update-ref -d refs/heads/merged-autodelete', escapeshellarg( $remote ) ) );

	// -------------------------------------------------------------------------
	// Dry-run assertions
	// -------------------------------------------------------------------------

	echo "\nDry-run scenario\n";
	$ws   = new \DataMachineCode\Workspace\Workspace();
	$plan = $ws->worktree_cleanup_merged(
		array(
			'dry_run'     => true,
			'skip_github' => true,
		)
	);

	$assert( true, ! is_wp_error( $plan ) && ( $plan['success'] ?? false ), 'dry_run returns success' );
	$assert( true, $plan['dry_run'] ?? false, 'dry_run flag echoes back true' );

	$assert_contains( $plan['candidates'] ?? array(), 'demo@merged-autodelete', 'canonical merged worktree flagged prunable' );

	// dirty-branch should be skipped (reason: dirty)
	$dirty_skips = array_filter( $plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@dirty-branch' );
	$assert( 1, count( $dirty_skips ), 'dirty worktree skipped with exactly one entry' );
	$dirty_reason = array_values( $dirty_skips )[0]['reason'] ?? '';
	$assert( true, str_contains( $dirty_reason, 'dirty' ), 'dirty skip reason mentions dirty' );

	// unmerged-feature should be skipped (no merge signal)
	$unmerged = array_filter( $plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@unmerged-feature' );
	$assert( 1, count( $unmerged ), 'unmerged worktree skipped with exactly one entry' );

	// Primary itself should NEVER show up as a candidate.
	foreach ( $plan['candidates'] ?? array() as $c ) {
		if ( 'demo' === ( $c['handle'] ?? '' ) ) {
			$failures++;
			$total++;
			echo "  ✗ primary listed as cleanup candidate (BUG — would destroy primary)\n";
		}
	}

	// -------------------------------------------------------------------------
	// Execute
	// -------------------------------------------------------------------------

	echo "\nExecuting cleanup\n";
	$result = $ws->worktree_cleanup_merged(
		array(
			'dry_run'     => false,
			'skip_github' => true,
		)
	);

	$assert( true, ! is_wp_error( $result ) && ( $result['success'] ?? false ), 'execute returns success' );
	$assert( false, $result['dry_run'] ?? true, 'dry_run flag is false on execute' );

	// The merged-autodelete candidate should be in removed[].
	$assert_contains( $result['removed'] ?? array(), 'demo@merged-autodelete', 'canonical merged worktree was actually removed' );

	// Directory should be gone from disk.
	$assert( false, is_dir( $tmp . '/demo@merged-autodelete' ), 'merged worktree directory no longer exists on disk' );

	// Primary survives.
	$assert( true, is_dir( $primary . '/.git' ), 'primary .git survives cleanup' );

	// Protected branches: unmerged/dirty worktrees survive.
	$assert( true, is_dir( $tmp . '/demo@unmerged-feature' ), 'unmerged worktree survives cleanup' );
	$assert( true, is_dir( $tmp . '/demo@dirty-branch' ), 'dirty worktree survives cleanup' );

	// The local branch for the removed worktree should be gone.
	$branch_check = $run( 'git for-each-ref --format="%(refname:short)" refs/heads/merged-autodelete', $primary );
	$assert( '', trim( $branch_check['output'] ), 'local branch for removed worktree is deleted' );

	// -------------------------------------------------------------------------
	// Negative: primary safety
	// -------------------------------------------------------------------------

	echo "\nSafety — primary never removed\n";

	// Verify validate_containment catches a primary being passed in.
	$reflection = new \ReflectionMethod( \DataMachineCode\Workspace\Workspace::class, 'remove_worktree_by_path' );
	$reflection->setAccessible( true );
	$safety = $reflection->invoke( $ws, 'demo', 'main', $primary, false );
	$is_error = is_wp_error( $safety );
	$assert( true, $is_error, 'remove_worktree_by_path refuses primary (is WP_Error)' );
	if ( $is_error ) {
		$assert( true, str_contains( $safety->get_error_message(), 'not a worktree marker' ) || str_contains( $safety->get_error_message(), 'primary' ), 'rejection message identifies primary-like target' );
	}

	// -------------------------------------------------------------------------
	// Negative: path outside workspace
	// -------------------------------------------------------------------------

	$outside = sys_get_temp_dir() . '/dmc-outside-' . bin2hex( random_bytes( 3 ) );
	mkdir( $outside, 0755, true );
	file_put_contents( $outside . '/.git', 'gitdir: fake' );
	$outside_err = $reflection->invoke( $ws, 'demo', 'x', $outside, false );
	$assert( true, is_wp_error( $outside_err ), 'path outside workspace rejected (is WP_Error)' );
	if ( is_wp_error( $outside_err ) ) {
		$assert( true, str_contains( $outside_err->get_error_message(), 'outside workspace' ) || str_contains( $outside_err->get_error_message(), 'traversal' ), 'rejection message mentions containment' );
	}
	exec( 'rm -rf ' . escapeshellarg( $outside ) );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
