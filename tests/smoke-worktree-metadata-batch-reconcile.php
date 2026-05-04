<?php
/**
 * Smoke test for bounded/batched legacy worktree metadata reconciliation.
 *
 * Targets the new {@see Workspace::worktree_reconcile_metadata_batch()} path
 * added for issue #215. Asserts:
 *   - Inventory-only discovery (no full git worktree --porcelain walk).
 *   - Bounded `limit` slices the candidate list deterministically.
 *   - `next_cursor` resumes from the last processed handle.
 *   - Already-reconciled rows are short-circuited (`metadata_repaired=true`).
 *   - Dirty / unpushed rows stay protected from cleanup_eligible drift.
 *   - Ambiguous rows surface explicit reason codes (path_missing, etc.).
 *
 * Run: php tests/smoke-worktree-metadata-batch-reconcile.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists( __NAMESPACE__ . '\FilesystemHelper' ) ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace DataMachineCode\Abilities {
	if ( ! class_exists( __NAMESPACE__ . '\GitHubAbilities' ) ) {
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
			public function get_error_code(): string {
				return $this->code;
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

	if ( ! function_exists( 'home_url' ) ) {
		function home_url(): string {
			return 'http://origin.test';
		}
	}

	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( string $show = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return 'Origin Test';
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

	$tmp = sys_get_temp_dir() . '/dmc-batch-reconcile-smoke-' . bin2hex( random_bytes( 4 ) );
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
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$run = function ( string $cmd, string $cwd = '' ): array {
		$full = '' === $cwd ? $cmd : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $cmd );
		exec( $full . ' 2>&1', $out, $rc );
		return array(
			'output' => implode( "\n", $out ),
			'exit'   => $rc,
		);
	};

	echo "Setting up workspace at {$tmp}\n";
	$remote  = $tmp . '/remote.git';
	$primary = $tmp . '/demo';
	$run( sprintf( 'git init --bare %s', escapeshellarg( $remote ) ) );
	$run( sprintf( 'git clone %s %s', escapeshellarg( $remote ), escapeshellarg( $primary ) ) );
	$run( 'git config user.email test@example.com', $primary );
	$run( 'git config user.name test', $primary );
	file_put_contents( $primary . '/README.md', "demo\n" );
	$run( 'git add README.md && git commit -m init', $primary );
	$run( 'git branch -M main', $primary );
	$run( 'git push -u origin main', $primary );

	$make_branch = function ( string $branch ) use ( $primary, $run ): void {
		$run( sprintf( 'git checkout -b %s', escapeshellarg( $branch ) ), $primary );
		file_put_contents( $primary . '/' . str_replace( '/', '-', $branch ) . '.txt', $branch );
		$run( sprintf( 'git add . && git commit -m %s', escapeshellarg( 'work on ' . $branch ) ), $primary );
		$run( sprintf( 'git push -u origin %s', escapeshellarg( $branch ) ), $primary );
		$run( 'git checkout main', $primary );
	};
	$branches = array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta' );
	foreach ( $branches as $b ) {
		$make_branch( $b );
		$run( sprintf( 'git worktree add %s %s', escapeshellarg( $tmp . '/demo@' . $b ), escapeshellarg( $b ) ), $primary );
	}

	// Pre-seed metadata so we have a mix of legacy missing-metadata + already-reconciled rows.
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@delta',
		array(
			'handle'            => 'demo@delta',
			'repo'              => 'demo',
			'branch'            => 'delta',
			'path'              => $tmp . '/demo@delta',
			'created_at'        => '2026-04-01T00:00:00+00:00',
			'observed_at'       => '2026-04-01T00:00:00+00:00',
			'lifecycle_state'   => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
			'metadata_repaired' => true,
		)
	);

	// Make one worktree dirty so cleanup_eligible safety can be exercised separately.
	file_put_contents( $tmp . '/demo@beta/scratch.txt', 'dirty' );

	$ws = new \DataMachineCode\Workspace\Workspace();

	echo "\nDry-run, bounded discovery\n";
	$plan_dry = $ws->worktree_reconcile_metadata_batch( array( 'dry_run' => true, 'limit' => 2, 'offset' => 0 ) );
	$assert( true, ! is_wp_error( $plan_dry ) && ( $plan_dry['success'] ?? false ), 'batch dry-run succeeds' );
	$assert( 'batch', $plan_dry['mode'] ?? '', 'batch mode reported' );
	$assert( true, (bool) ( $plan_dry['dry_run'] ?? false ), 'dry_run flag is true' );
	$assert( false, (bool) ( $plan_dry['applied'] ?? false ), 'dry-run does not apply' );
	$assert( 5, (int) ( $plan_dry['candidate_total'] ?? 0 ), 'discovery skips already-reconciled delta and finds 5 legacy rows' );
	$assert( 2, (int) ( $plan_dry['processed'] ?? 0 ), 'limit=2 processes exactly two rows' );
	$assert( 3, (int) ( $plan_dry['remaining'] ?? 0 ), 'remaining accounts for unprocessed candidates' );
	$assert( false, (bool) ( $plan_dry['exhausted'] ?? false ), 'not exhausted while remaining > 0' );
	$assert( 'demo@beta', $plan_dry['next_cursor'] ?? null, 'next_cursor is the last processed handle (alphabetical sort: alpha, beta)' );

	$dry_proposals_by_handle = array();
	foreach ( (array) ( $plan_dry['proposals'] ?? array() ) as $row ) {
		$dry_proposals_by_handle[ (string) ( $row['handle'] ?? '' ) ] = $row;
	}
	$assert( true, isset( $dry_proposals_by_handle['demo@alpha'] ), 'first slice contains demo@alpha' );
	$assert( true, isset( $dry_proposals_by_handle['demo@beta'] ), 'first slice contains demo@beta' );
	$assert( 'alpha', $dry_proposals_by_handle['demo@alpha']['branch'] ?? '', 'branch is resolved via per-row git probe' );
	$assert( 1, (int) ( $dry_proposals_by_handle['demo@beta']['dirty'] ?? 0 ), 'dirty count comes from per-row git status probe' );

	$stored_alpha_dry = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@alpha' );
	$assert( null, $stored_alpha_dry, 'dry-run does not write metadata' );

	echo "\nApply, first batch\n";
	$apply_first = $ws->worktree_reconcile_metadata_batch( array( 'limit' => 2, 'offset' => 0 ) );
	$assert( true, ! is_wp_error( $apply_first ) && ( $apply_first['success'] ?? false ), 'apply batch succeeds' );
	$assert( false, (bool) ( $apply_first['dry_run'] ?? false ), 'dry_run is false on apply' );
	$assert( true, (bool) ( $apply_first['applied'] ?? false ), 'applied flag is true' );
	$assert( 2, count( (array) ( $apply_first['written'] ?? array() ) ), 'apply writes 2 rows' );
	$stored_alpha = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@alpha' );
	$assert( true, ! empty( $stored_alpha['metadata_repaired'] ), 'apply marks alpha metadata_repaired' );
	$assert( true, ! empty( $stored_alpha['observed_at'] ), 'apply records observed_at' );
	$assert( 'operator_plan', $stored_alpha['reconciled_sources']['lifecycle_state'] ?? '', 'apply persists source map for lifecycle_state' );

	echo "\nResume via next_cursor\n";
	$apply_second = $ws->worktree_reconcile_metadata_batch( array(
		'limit'  => 10,
		'cursor' => (string) ( $apply_first['next_cursor'] ?? '' ),
	) );
	$assert( true, ! is_wp_error( $apply_second ) && ( $apply_second['success'] ?? false ), 'second batch succeeds' );
	$assert( 3, count( (array) ( $apply_second['written'] ?? array() ) ), 'remaining 3 rows are processed in second batch' );
	$assert( true, (bool) ( $apply_second['exhausted'] ?? false ), 'candidate list is exhausted after second batch' );
	$assert( 0, (int) ( $apply_second['remaining'] ?? -1 ), 'remaining is zero when exhausted' );
	$assert( true, array_key_exists( 'next_cursor', $apply_second ) && null === $apply_second['next_cursor'], 'next_cursor is null when exhausted' );

	echo "\nIdempotency: re-running after apply finds no candidates\n";
	$idempotent = $ws->worktree_reconcile_metadata_batch( array( 'limit' => 25, 'dry_run' => true ) );
	$assert( 0, (int) ( $idempotent['candidate_total'] ?? -1 ), 'reconciled rows are excluded from candidate discovery' );
	$assert( 0, (int) ( $idempotent['processed'] ?? -1 ), 'no rows processed when nothing to do' );
	$assert( true, (bool) ( $idempotent['exhausted'] ?? false ), 'reports exhausted when there is nothing to reconcile' );

	echo "\nInventory cleanup downgrades requires_full_scan after batch reconciliation\n";
	$inventory = $ws->worktree_cleanup_merged( array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ) );
	$assert( 0, (int) ( $inventory['summary']['skipped_by_reason']['requires_full_scan'] ?? 0 ), 'no rows still flagged requires_full_scan' );
	$repaired_count = (int) ( $inventory['summary']['skipped_by_reason']['missing_metadata_repaired'] ?? 0 );
	$assert( true, $repaired_count >= 5, 'inventory cleanup recognizes batch-repaired rows' );

	echo "\nProtection: ambiguous path stays explicit\n";
	// Drop the worktree directory to simulate an inventory row whose path is gone.
	exec( 'rm -rf ' . escapeshellarg( $tmp . '/demo@alpha' ) );
	// Force alpha back into the candidate set by clearing its metadata_repaired flag.
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@alpha',
		array( 'metadata_repaired' => false )
	);
	$missing_path = $ws->worktree_reconcile_metadata_batch( array( 'limit' => 5, 'dry_run' => true ) );
	$missing_codes = array();
	foreach ( (array) ( $missing_path['skipped'] ?? array() ) as $row ) {
		$missing_codes[ (string) ( $row['handle'] ?? '' ) ] = (string) ( $row['reason_code'] ?? '' );
	}
	$assert( 'path_missing', $missing_codes['demo@alpha'] ?? '', 'missing worktree path produces explicit path_missing reason code' );

	echo "\nProtection: cleanup_eligible never inferred from batch run\n";
	// Force beta's stored proposal to push lifecycle_state=cleanup_eligible. The dirty
	// guard inside the batch path must reject the write on apply, but discovery itself
	// never produces cleanup_eligible from inferred state.
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@beta',
		array( 'metadata_repaired' => false, 'lifecycle_state' => '' )
	);
	$beta_plan = $ws->worktree_reconcile_metadata_batch( array( 'limit' => 1, 'dry_run' => true, 'cursor' => 'demo@alpha' ) );
	$beta_proposed_state = '';
	foreach ( (array) ( $beta_plan['proposals'] ?? array() ) as $row ) {
		if ( 'demo@beta' === (string) ( $row['handle'] ?? '' ) ) {
			$beta_proposed_state = (string) ( $row['proposed_metadata']['lifecycle_state'] ?? '' );
			break;
		}
	}
	$assert( \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $beta_proposed_state, 'dirty row stays proposed as active, never cleanup_eligible' );

	if ( $failures > 0 ) {
		echo "\n{$failures} / {$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$total} batched worktree metadata reconciliation assertions passed.\n";
}
