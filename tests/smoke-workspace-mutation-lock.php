<?php
/**
 * Pure-PHP smoke test for WorkspaceMutationLock.
 *
 * Run: php tests/smoke-workspace-mutation-lock.php
 */

declare( strict_types=1 );

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

			public function get_error_data(): array {
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

	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';

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
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$tmp = sys_get_temp_dir() . '/dmc-workspace-lock-smoke-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	register_shutdown_function( function () use ( $tmp ) {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
	} );

	echo "Workspace mutation lock\n";

	$first = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire( $tmp, 'demo', 1 );
	$assert( false, is_wp_error( $first ), 'first acquisition succeeds' );
	$assert( true, is_file( $tmp . '/.locks/worktree-demo.lock' ), 'lock file is created under workspace .locks directory' );

	$busy = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire( $tmp, 'demo', 0 );
	$assert( true, is_wp_error( $busy ), 'same repo acquisition fails fast while held' );
	$assert( 'workspace_repo_busy', is_wp_error( $busy ) ? $busy->get_error_code() : '', 'busy failure uses DMC-shaped retryable code' );
	$assert( true, is_wp_error( $busy ) ? (bool) ( $busy->get_error_data()['retryable'] ?? false ) : false, 'busy failure is marked retryable' );

	$other = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire( $tmp, 'other', 0 );
	$assert( false, is_wp_error( $other ), 'different repo acquisition is independent' );
	if ( ! is_wp_error( $other ) ) {
		$other->release();
	}

	if ( ! is_wp_error( $first ) ) {
		$first->release();
	}

	$after_release = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire( $tmp, 'demo', 0 );
	$assert( false, is_wp_error( $after_release ), 'same repo acquisition succeeds after release' );
	if ( ! is_wp_error( $after_release ) ) {
		$after_release->release();
	}

	$result = \DataMachineCode\Workspace\WorkspaceMutationLock::with_repo(
		$tmp,
		'demo',
		fn() => array( 'success' => true ),
		0
	);
	$assert( array( 'success' => true ), $result, 'with_repo returns callback result' );

	$post_callback = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire( $tmp, 'demo', 0 );
	$assert( false, is_wp_error( $post_callback ), 'with_repo releases after normal callback return' );
	if ( ! is_wp_error( $post_callback ) ) {
		$post_callback->release();
	}

	try {
		\DataMachineCode\Workspace\WorkspaceMutationLock::with_repo(
			$tmp,
			'demo',
			function () {
				throw new \RuntimeException( 'boom' );
			},
			0
		);
		$assert( true, false, 'with_repo exception path throws' );
	} catch ( \RuntimeException $e ) {
		$assert( 'boom', $e->getMessage(), 'with_repo propagates callback exception' );
	}

	$post_exception = \DataMachineCode\Workspace\WorkspaceMutationLock::acquire( $tmp, 'demo', 0 );
	$assert( false, is_wp_error( $post_exception ), 'with_repo releases after callback exception' );
	if ( ! is_wp_error( $post_exception ) ) {
		$post_exception->release();
	}

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
