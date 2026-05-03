<?php
/**
 * Smoke test for legacy worktree metadata reconciliation.
 *
 * Run: php tests/smoke-worktree-metadata-reconcile.php
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

	$tmp = sys_get_temp_dir() . '/dmc-reconcile-smoke-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	register_shutdown_function( function () use ( $tmp ) {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
		if ( is_dir( $tmp . '-external' ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp . '-external' ) );
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
	$make_branch( 'legacy-missing' );
	$make_branch( 'legacy-partial' );
	$make_branch( 'legacy-dirty' );
	$make_branch( 'legacy-invalid' );
	$make_branch( 'already-current' );
	$make_branch( 'external-branch' );

	$run( sprintf( 'git worktree add %s legacy-missing', escapeshellarg( $tmp . '/demo@legacy-missing' ) ), $primary );
	$run( sprintf( 'git worktree add %s legacy-partial', escapeshellarg( $tmp . '/demo@legacy-partial' ) ), $primary );
	$run( sprintf( 'git worktree add %s legacy-dirty', escapeshellarg( $tmp . '/demo@legacy-dirty' ) ), $primary );
	$run( sprintf( 'git worktree add %s legacy-invalid', escapeshellarg( $tmp . '/demo@legacy-invalid' ) ), $primary );
	$run( sprintf( 'git worktree add %s already-current', escapeshellarg( $tmp . '/demo@already-current' ) ), $primary );
	mkdir( $tmp . '-external', 0755, true );
	$run( sprintf( 'git worktree add %s external-branch', escapeshellarg( $tmp . '-external/demo-external' ) ), $primary );
	file_put_contents( $tmp . '/demo@legacy-dirty/scratch.txt', 'dirty' );

	\DataMachineCode\Workspace\WorktreeContextInjector::store_metadata(
		'demo@legacy-partial',
		array(
			'site_url'   => 'http://example.test',
			'site_name'  => 'Example',
			'agent_slug' => 'agent-one',
			'abspath'    => '/example',
			'timestamp'  => '2026-04-01T00:00:00+00:00',
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@legacy-invalid',
		array(
			'handle'          => 'demo@legacy-invalid',
			'repo'            => 'demo',
			'branch'          => 'legacy-invalid',
			'path'            => $tmp . '/demo@legacy-invalid',
			'created_at'      => '2026-04-02T00:00:00+00:00',
			'lifecycle_state' => 'donezo',
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@already-current',
		array(
			'handle'          => 'demo@already-current',
			'repo'            => 'demo',
			'branch'          => 'already-current',
			'path'            => $tmp . '/demo@already-current',
			'created_at'      => '2026-04-01T00:00:00+00:00',
			'observed_at'     => '2026-04-01T00:00:00+00:00',
			'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
		)
	);

	$ws = new \DataMachineCode\Workspace\Workspace();

	echo "\nDry-run reconciliation\n";
	$plan = $ws->worktree_reconcile_metadata( array( 'dry_run' => true ) );
	$assert( true, ! is_wp_error( $plan ) && ( $plan['success'] ?? false ), 'dry-run succeeds' );
	$assert( true, $plan['dry_run'] ?? false, 'dry-run flag is true' );
	$assert( 4, (int) ( $plan['summary']['proposed'] ?? 0 ), 'dry-run proposes only legacy rows' );
	$assert( 0, (int) ( $plan['summary']['written'] ?? 0 ), 'dry-run writes nothing' );
	$assert( 1, (int) ( $plan['summary']['skipped_by_reason']['external_worktree'] ?? 0 ), 'dry-run distinguishes external worktrees' );
	$assert( 1, count( $plan['external_worktrees'] ?? array() ), 'dry-run exposes external worktree bucket' );

	$by_handle = array();
	foreach ( $plan['proposals'] as $row ) {
		$by_handle[ $row['handle'] ] = $row;
	}
	$assert( 'operator_plan', $by_handle['demo@legacy-missing']['source_map']['lifecycle_state'] ?? '', 'missing metadata lifecycle source is operator_plan' );
	$assert( 'filesystem', $by_handle['demo@legacy-missing']['source_map']['created_at'] ?? '', 'missing metadata created_at source is filesystem' );
	$assert( 'reconcile_run', $by_handle['demo@legacy-missing']['source_map']['observed_at'] ?? '', 'missing metadata observed_at source is reconcile run' );
	$assert( 'current_site', $by_handle['demo@legacy-missing']['source_map']['origin_site'] ?? '', 'missing metadata origin site is inferred from current site' );
	$assert( 'metadata', $by_handle['demo@legacy-partial']['source_map']['created_at'] ?? '', 'partial metadata preserves created_at source' );
	$assert( 'git', $by_handle['demo@legacy-partial']['source_map']['branch'] ?? '', 'branch source is git' );
	$assert( array( 'lifecycle_state' ), $by_handle['demo@legacy-invalid']['invalid_fields'] ?? array(), 'invalid lifecycle state is planned for repair' );
	$assert( \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $by_handle['demo@legacy-invalid']['proposed_metadata']['lifecycle_state'] ?? '', 'invalid lifecycle state becomes conservative active proposal' );
	$assert( 1, (int) ( $by_handle['demo@legacy-dirty']['dirty'] ?? 0 ), 'dirty row is visible but not cleanup-eligible' );
	$assert( \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $by_handle['demo@legacy-dirty']['proposed_metadata']['lifecycle_state'] ?? '', 'dirty row proposal stays active' );
	$stale_plan  = $plan;
	$unsafe_plan = $plan;

	$inventory_before = $ws->worktree_cleanup_merged( array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ) );
	$assert( 2, (int) ( $inventory_before['summary']['skipped_by_reason']['requires_full_scan'] ?? 0 ), 'inventory cleanup sees missing metadata before apply' );

	echo "\nApply reviewed plan\n";
	$apply = $ws->worktree_reconcile_metadata( array( 'apply_plan' => $plan ) );
	$assert( true, ! is_wp_error( $apply ) && ( $apply['success'] ?? false ), 'apply succeeds' );
	$assert( 4, (int) ( $apply['summary']['written'] ?? 0 ), 'apply writes exact current matches' );
	$assert( 4, (int) ( $apply['summary']['repaired'] ?? 0 ), 'apply reports repaired metadata rows' );
	$assert( 0, (int) ( $apply['summary']['skipped'] ?? 0 ), 'apply skips nothing for current plan' );
	$assert( 4, count( $apply['repaired'] ?? array() ), 'apply exposes repaired rows distinctly' );
	$stored = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@legacy-missing' );
	$assert( 'demo@legacy-missing', $stored['handle'] ?? '', 'stored metadata includes handle' );
	$assert( true, ! empty( $stored['observed_at'] ), 'stored metadata includes observed_at' );
	$assert( 'Origin Test', $stored['origin_site'] ?? '', 'stored metadata includes origin site when available' );
	$assert( 'operator_plan', $stored['reconciled_sources']['lifecycle_state'] ?? '', 'stored metadata keeps source map' );

	$inventory_after = $ws->worktree_cleanup_merged( array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ) );
	$assert( 0, (int) ( $inventory_after['summary']['skipped_by_reason']['requires_full_scan'] ?? 0 ), 'inventory cleanup requires fewer full scans after apply' );
	$assert( 4, (int) ( $inventory_after['summary']['skipped_by_reason']['missing_metadata_repaired'] ?? 0 ), 'inventory cleanup distinguishes repaired legacy metadata' );
	$assert( 4, (int) ( $inventory_after['summary']['repair_status']['missing_metadata_repaired'] ?? 0 ), 'inventory cleanup summarizes repaired legacy metadata' );

	echo "\nSafety gates\n";
	$stale_plan['proposals'][0]['branch'] = 'wrong-branch';
	$stale_apply = $ws->worktree_reconcile_metadata( array( 'apply_plan' => $stale_plan ) );
	$assert( 'plan_identity_mismatch', $stale_apply['skipped'][0]['reason_code'] ?? '', 'apply revalidates branch identity before writing' );

	foreach ( $unsafe_plan['proposals'] as &$row ) {
		if ( 'demo@legacy-dirty' === $row['handle'] ) {
			$row['proposed_metadata']['lifecycle_state'] = \DataMachineCode\Workspace\WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE;
			$row['source_map']['lifecycle_state']        = 'operator_plan';
		}
	}
	unset( $row );
	$unsafe_apply = $ws->worktree_reconcile_metadata( array( 'apply_plan' => $unsafe_plan ) );
	$unsafe_skips = array_values( array_filter( $unsafe_apply['skipped'] ?? array(), fn( $row ) => 'demo@legacy-dirty' === ( $row['handle'] ?? '' ) ) );
	$assert( 'unsafe_cleanup_eligible_state', $unsafe_skips[0]['reason_code'] ?? '', 'dirty worktree cannot become cleanup_eligible through reconciliation plan' );
	$assert( 1, count( $unsafe_apply['still_unsafe'] ?? array() ), 'apply exposes still-unsafe rows distinctly' );

	if ( $failures > 0 ) {
		echo "\n{$failures} / {$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$total} worktree metadata reconciliation assertions passed.\n";
}
