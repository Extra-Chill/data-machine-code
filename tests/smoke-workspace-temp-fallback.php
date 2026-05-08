<?php
/**
 * Pure-PHP smoke test for the ephemeral temp workspace fallback.
 *
 * Run: php tests/smoke-workspace-temp-fallback.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	class FilesystemHelper {
		public static function get() {
			return null;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/fixtures/web-root/' );
	}

	putenv( 'HOME=' );

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;
			private $data;

			public function __construct( string $code = '', string $message = '', $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
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

	require __DIR__ . '/../inc/Workspace/Workspace.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		$failures++;
		echo "  [FAIL] {$message}\n";
	};

	$assert_same = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $expected === $actual ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		$failures++;
		echo "  [FAIL] {$message}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	echo "=== smoke-workspace-temp-fallback ===\n";

	$expected  = rtrim( sys_get_temp_dir(), '/' ) . '/datamachine/workspace';
	$workspace = new \DataMachineCode\Workspace\Workspace();

	echo "\n[1] Resolve temp fallback when no configured/system/home workspace is available\n";
	$assert_same( $expected, $workspace->get_path(), 'workspace resolves to sys_get_temp_dir fallback' );
	$assert( 0 !== strpos( $workspace->get_path() . '/', rtrim( ABSPATH, '/' ) . '/' ), 'fallback is outside ABSPATH' );

	echo "\n[2] Workspace operations no longer fail as unavailable\n";
	$list = $workspace->list_repos();
	$assert( ! is_wp_error( $list ), 'list_repos does not return workspace_unavailable' );
	$assert_same( $expected, $list['path'] ?? null, 'list_repos reports temp workspace path' );

	echo "\n[3] Temp workspace can be created on demand\n";
	$ensure = $workspace->ensure_exists();
	$assert( ! is_wp_error( $ensure ), 'ensure_exists succeeds for temp fallback' );
	$assert_same( $expected, $ensure['path'] ?? null, 'ensure_exists creates expected path' );
	$assert( is_dir( $expected ), 'temp workspace directory exists' );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
