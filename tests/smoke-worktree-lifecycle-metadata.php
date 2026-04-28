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

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value ) {
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
	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	require __DIR__ . '/../inc/Workspace/WorktreeStalenessProbe.php';
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

	$ws     = new \DataMachineCode\Workspace\Workspace();
	$result = $ws->worktree_add( 'demo', 'feature/metadata', 'HEAD', false, false, true, false );
	$assert( true, ! is_wp_error( $result ) && ( $result['success'] ?? false ), 'worktree_add succeeds without context injection' );

	$metadata = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@feature-metadata' );
	$assert( true, is_array( $metadata ), 'lifecycle metadata recorded at add time' );
	$assert( 'Origin Site', $metadata['origin_site'] ?? null, 'origin site is recorded' );
	$assert( 'agent-one', $metadata['origin_agent'] ?? null, 'origin agent is recorded' );
	$assert( 42, $metadata['origin_user']['id'] ?? null, 'origin user id is recorded without sensitive fields' );
	$assert( 'HEAD', $metadata['base_ref'] ?? null, 'base ref is recorded' );
	$assert( 'requested_ref', $metadata['base_source'] ?? null, 'base source is recorded' );

	$list  = $ws->worktree_list( 'demo' );
	$items = array_values( array_filter( $list['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-metadata' ) );
	$item  = $items[0] ?? array();
	$assert( $metadata['created_at'] ?? null, $item['created_at'] ?? null, 'list data exposes created_at metadata' );
	$assert( 'Origin Site', $item['metadata']['origin_site'] ?? null, 'list data exposes nested lifecycle metadata' );

	$result_old = $ws->worktree_add( 'demo', 'feature/old-record', 'HEAD', false, false, true, false );
	$assert( true, ! is_wp_error( $result_old ) && ( $result_old['success'] ?? false ), 'second worktree_add succeeds' );
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
	$feature_skip = array_values( array_filter( $plan['skipped'] ?? array(), fn( $skip ) => ( $skip['handle'] ?? '' ) === 'demo@feature-metadata' ) );
	$assert( 'Origin Site', $feature_skip[0]['metadata']['origin_site'] ?? null, 'cleanup dry-run exposes metadata on skipped worktree records' );

	if ( $failures > 0 ) {
		echo "\n{$failures} failure(s) across {$assertions} assertion(s).\n";
		exit( 1 );
	}

	echo "\nAll {$assertions} worktree lifecycle metadata assertions passed.\n";
}
