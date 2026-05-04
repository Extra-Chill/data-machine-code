<?php
/**
 * Smoke test for DMC worktree lifecycle metadata.
 *
 * Run: php tests/smoke-worktree-lifecycle-metadata.php
 *
 * @package DataMachineCode\Tests
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

	if ( ! class_exists( __NAMESPACE__ . '\DirectoryManager' ) ) {
		class DirectoryManager {
			public function get_effective_user_id( int $fallback ): int {
				return 7;
			}

			public function resolve_agent_slug( array $args ): string {
				return 'agent-one';
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

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
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

			public function get_error_data() {
				return $this->data;
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
			global $datamachine_code_test_options;
			return $datamachine_code_test_options[ $name ] ?? $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			global $datamachine_code_test_options;
			$datamachine_code_test_options[ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return gmdate( 'Y-m-d H:i:s' );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode( $value, $flags, $depth );
		}
	}

	class DatamachineCodeLifecycleInventoryWpdb {
		public string $prefix = 'wp_';

		/** @var array<string,array<string,mixed>> */
		public array $rows = array();

		/** @param array<string,mixed> $data */
		public function replace( string $table, array $data ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$this->rows[ (string) $data['handle'] ] = $data;
			return 1;
		}

		/** @param array<string,mixed> $where */
		public function delete( string $table, array $where ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			unset( $this->rows[ (string) $where['handle'] ] );
			return 1;
		}

		/** @param array<string,mixed> $data @param array<string,mixed> $where */
		public function update( string $table, array $data, array $where ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$handle = (string) $where['handle'];
			if ( ! isset( $this->rows[ $handle ] ) ) {
				return 0;
			}
			$this->rows[ $handle ] = array_merge( $this->rows[ $handle ], $data );
			return 1;
		}

		/** @return array<int,array<string,mixed>> */
		public function get_results( string $sql, string $output ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return array_values( $this->rows );
		}

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value, ...$args ) {
			global $datamachine_code_test_filters;
			if ( isset( $datamachine_code_test_filters[ $hook_name ] ) && is_callable( $datamachine_code_test_filters[ $hook_name ] ) ) {
				return $datamachine_code_test_filters[ $hook_name ]( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		function home_url(): string {
			return 'https://origin.example.test';
		}
	}

	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( string $show ): string {
			return 'Origin Site';
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int {
			return 42;
		}
	}

	if ( ! function_exists( 'get_userdata' ) ) {
		function get_userdata( int $user_id ): object {
			return (object) array(
				'user_login'   => 'chris',
				'display_name' => 'Chris',
			);
		}
	}

	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Storage/WorktreeInventoryRepository.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	require __DIR__ . '/../inc/Workspace/WorktreeStalenessProbe.php';
	require __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

	exec( 'git --version 2>&1', $_git_version, $git_exit );
	if ( 0 !== $git_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	$assertions = 0;
	$failures   = 0;

	$assert = function ( $expected, $actual, string $message ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( $expected === $actual ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
		echo '         expected: ' . var_export( $expected, true ) . "\n";
		echo '         actual:   ' . var_export( $actual, true ) . "\n";
	};

	$run = function ( string $command, ?string $cwd = null ) use ( &$assert ): string {
		$full = null === $cwd ? $command : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $command );
		exec( $full . ' 2>&1', $output, $code );
		$stdout = trim( implode( "\n", $output ) );
		$assert( 0, $code, 'command succeeds: ' . $command . ( '' !== $stdout ? "\n{$stdout}" : '' ) );
		return $stdout;
	};

	echo "=== smoke-worktree-lifecycle-metadata ===\n";

	$tmp = sys_get_temp_dir() . '/dmc-worktree-metadata-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	$tmp = (string) realpath( $tmp );
	register_shutdown_function( function () use ( $tmp ): void {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) . ' 2>&1' );
		}
	} );

	define( 'DATAMACHINE_WORKSPACE_PATH', $tmp . '/workspace' );
	mkdir( DATAMACHINE_WORKSPACE_PATH, 0755, true );
	$primary = DATAMACHINE_WORKSPACE_PATH . '/demo';
	mkdir( $primary, 0755, true );

	$run( 'git init', $primary );
	$run( 'git config user.email test@example.test', $primary );
	$run( 'git config user.name Test', $primary );
	file_put_contents( $primary . '/README.md', "# Demo\n" );
	$run( 'git add README.md', $primary );
	$run( 'git commit -m initial', $primary );
	putenv( 'OPENCODE_RUN_ID=smoke-run-123' );
	putenv( 'OPENCODE_PID=12345' );

	$ws = new \DataMachineCode\Workspace\Workspace();
	$GLOBALS['wpdb'] = new DatamachineCodeLifecycleInventoryWpdb();

	$GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] = function ( array $thresholds ): array {
		$thresholds['refuse_free_bytes'] = PHP_INT_MAX;
		$thresholds['warn_free_bytes']   = PHP_INT_MAX;
		return $thresholds;
	};
	$refused = $ws->worktree_add( 'demo', 'feature/disk-budget-refusal', 'HEAD', true, true, true, false, false );
	unset( $GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] );
	$assert( true, is_wp_error( $refused ), 'worktree_add refuses before creation when disk budget is unsafe' );
	$assert( false, is_dir( DATAMACHINE_WORKSPACE_PATH . '/demo@feature-disk-budget-refusal' ), 'refused disk budget does not leave a worktree directory' );
	$assert( true, str_contains( $refused->get_error_message(), DATAMACHINE_WORKSPACE_PATH ), 'disk budget refusal names workspace root' );
	$assert( true, str_contains( $refused->get_error_message(), 'cleanup-artifacts --dry-run' ), 'disk budget refusal suggests artifact cleanup' );

	$result = $ws->worktree_add( 'demo', 'feature/metadata', 'HEAD', false, false, true, false );
	$assert( true, ! is_wp_error( $result ) && ( $result['success'] ?? false ), 'worktree_add succeeds without context injection' );

	$metadata = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@feature-metadata' );
	$assert( true, isset( $GLOBALS['wpdb']->rows['demo@feature-metadata'] ), 'worktree_add writes DB inventory row' );
	$assert( 'active', $GLOBALS['wpdb']->rows['demo@feature-metadata']['lifecycle_state'] ?? null, 'worktree_add inventory row starts active' );
	$assert( true, is_array( $metadata ), 'lifecycle metadata recorded at add time' );
	$assert( 'Origin Site', $metadata['origin_site'] ?? null, 'origin site is recorded' );
	$assert( 'agent-one', $metadata['origin_agent'] ?? null, 'origin agent is recorded' );
	$assert( 42, $metadata['origin_user']['id'] ?? null, 'origin user id is recorded without sensitive fields' );
	$assert( 'HEAD', $metadata['base_ref'] ?? null, 'base ref is recorded' );
	$assert( 'requested_ref', $metadata['base_source'] ?? null, 'base source is recorded' );
	$assert( 'active', $metadata['lifecycle_state'] ?? null, 'new worktree lifecycle state defaults to active' );
	$assert( 'demo@feature-metadata', $metadata['handle'] ?? null, 'worktree handle is recorded' );
	$assert( DATAMACHINE_WORKSPACE_PATH . '/demo@feature-metadata', $metadata['path'] ?? null, 'worktree path is recorded' );
	$assert( true, isset( $metadata['origin_session'] ) && is_array( $metadata['origin_session'] ), 'origin session metadata is recorded when runtime env is available' );

	$list  = $ws->worktree_list( 'demo' );
	$items = array_values( array_filter( $list['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-metadata' ) );
	$item  = $items[0] ?? array();
	$assert( $metadata['created_at'] ?? null, $item['created_at'] ?? null, 'list data exposes created_at metadata' );
	$assert( 'Origin Site', $item['metadata']['origin_site'] ?? null, 'list data exposes nested lifecycle metadata' );
	$assert( 'active', $item['lifecycle_state'] ?? null, 'list data exposes lifecycle state' );

	$finalized = $ws->worktree_finalize( 'demo@feature-metadata', 'pr_opened', 'https://github.com/acme/demo/pull/123' );
	$assert( true, ! is_wp_error( $finalized ) && ( $finalized['success'] ?? false ), 'worktree_finalize succeeds with PR URL' );
	$assert( 'cleanup_eligible', $finalized['lifecycle_state'] ?? null, 'PR finalizer safely transitions lifecycle state to cleanup_eligible' );
	$assert( 'pr_opened', $finalized['metadata']['finalized_state'] ?? null, 'PR finalizer preserves requested finalizer state' );
	$assert( true, isset( $finalized['metadata']['cleanup_eligible_at'] ), 'PR finalizer records cleanup eligibility timestamp' );
	$assert( 123, $finalized['metadata']['pr_number'] ?? null, 'finalizer extracts PR number' );
	$assert( 'https://github.com/acme/demo/pull/123', $finalized['metadata']['pr_url'] ?? null, 'finalizer stores normalized PR URL' );
	$assert( 'cleanup_eligible', $GLOBALS['wpdb']->rows['demo@feature-metadata']['lifecycle_state'] ?? null, 'worktree_finalize updates DB inventory lifecycle state' );
	$assert( 'cleanup_eligible', $GLOBALS['wpdb']->rows['demo@feature-metadata']['cleanup_signal'] ?? null, 'worktree_finalize records cleanup signal in DB inventory' );

	$filtered = $ws->worktree_list( 'demo', 'cleanup_eligible' );
	$filtered_items = array_values( array_filter( $filtered['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-metadata' ) );
	$assert( 1, count( $filtered_items ), 'worktree_list can filter by lifecycle state' );

	$invalid_state = $ws->worktree_finalize( 'demo@feature-metadata', 'donezo' );
	$assert( true, is_wp_error( $invalid_state ), 'invalid finalizer state returns WP_Error' );

	$eligible = $ws->worktree_finalize( 'demo@feature-metadata', 'cleanup_eligible' );
	$assert( true, ! is_wp_error( $eligible ) && ( $eligible['success'] ?? false ), 'worktree can be marked cleanup_eligible' );

	$merged = $ws->worktree_finalize( 'demo@feature-metadata', 'merged' );
	$assert( true, ! is_wp_error( $merged ) && ( $merged['success'] ?? false ), 'merged finalizer succeeds' );
	$assert( 'cleanup_eligible', $merged['lifecycle_state'] ?? null, 'merged finalizer safely transitions lifecycle state to cleanup_eligible' );
	$assert( 'merged', $merged['metadata']['finalized_state'] ?? null, 'merged finalizer preserves requested finalizer state' );

	$result_old = $ws->worktree_add( 'demo', 'feature/old-record', 'HEAD', false, false, true, false );
	$assert( true, ! is_wp_error( $result_old ) && ( $result_old['success'] ?? false ), 'second worktree_add succeeds' );
	$refresh = $ws->worktree_inventory_refresh();
	$assert( true, ! is_wp_error( $refresh ) && ( $refresh['success'] ?? false ), 'inventory refresh reconciles current worktrees' );
	$assert( true, in_array( 'demo@feature-old-record', $refresh['upserted'] ?? array(), true ), 'inventory refresh upserts newly observed worktree' );
	$all_metadata = get_option( \DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, array() );
	unset( $all_metadata['demo@feature-old-record'] );
	update_option( \DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, $all_metadata, false );

	$list_old  = $ws->worktree_list( 'demo' );
	$old_items = array_values( array_filter( $list_old['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-old-record' ) );
	$old_item  = $old_items[0] ?? array();
	$assert( true, isset( $old_item['handle'] ), 'old worktree with missing metadata still lists' );
	$assert( null, $old_item['metadata'] ?? null, 'old worktree missing metadata degrades to null' );

	$plan = $ws->worktree_cleanup_merged(
		array(
			'dry_run'     => true,
			'skip_github' => true,
		)
	);
	$assert( true, ! is_wp_error( $plan ) && ( $plan['success'] ?? false ), 'cleanup dry-run succeeds with mixed metadata records' );
	$eligible_candidates = array_values( array_filter( $plan['candidates'] ?? array(), fn( $cand ) => ( $cand['handle'] ?? '' ) === 'demo@feature-metadata' ) );
	$assert( 1, count( $eligible_candidates ), 'cleanup dry-run treats cleanup_eligible metadata as a candidate signal' );
	$assert( 'cleanup_eligible', $eligible_candidates[0]['signal'] ?? null, 'cleanup_eligible candidate exposes stable signal' );
	$feature_skip = array_values( array_filter( $plan['skipped'] ?? array(), fn( $skip ) => ( $skip['handle'] ?? '' ) === 'demo@feature-metadata' ) );
	$old_skip = array_values( array_filter( $plan['skipped'] ?? array(), fn( $skip ) => ( $skip['handle'] ?? '' ) === 'demo@feature-old-record' ) );
	$assert( null, $old_skip[0]['metadata'] ?? null, 'cleanup dry-run tolerates worktrees with missing metadata' );

	$removed_old = $ws->worktree_remove( 'demo', 'feature/old-record', true );
	$assert( true, ! is_wp_error( $removed_old ) && ( $removed_old['success'] ?? false ), 'worktree_remove succeeds for old record' );
	$assert( false, isset( $GLOBALS['wpdb']->rows['demo@feature-old-record'] ), 'worktree_remove deletes DB inventory row' );

	if ( $failures > 0 ) {
		echo "\n{$failures} failure(s) across {$assertions} assertion(s).\n";
		exit( 1 );
	}

	echo "\nAll {$assertions} worktree lifecycle metadata assertions passed.\n";
}
