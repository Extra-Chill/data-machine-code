<?php
/**
 * Pure-PHP smoke test for handle parsing and branch slugification.
 *
 * Run: php tests/smoke-worktree-handles.php
 *
 * Avoids a WP bootstrap so it can run anywhere. Full end-to-end coverage
 * (clone → worktree add → commit → push) is in TESTING.md and exercised live
 * via WP-CLI.
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

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( $code = '', $message = '', $data = array() ) {}
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

	echo "Workspace handle parsing\n";
	$ws = new \DataMachineCode\Workspace\Workspace();

	$cases = array(
		'data-machine'                => array( 'data-machine', null, false, 'data-machine' ),
		'data-machine@fix-foo'        => array( 'data-machine', 'fix-foo', true, 'data-machine@fix-foo' ),
		'data-machine@fix-foo-bar'    => array( 'data-machine', 'fix-foo-bar', true, 'data-machine@fix-foo-bar' ),
		'intelligence@feat.with.dots' => array( 'intelligence', 'feat.with.dots', true, 'intelligence@feat.with.dots' ),
		'repo@'                       => array( 'repo', null, false, 'repo' ),
		// Malformed handles fall back to the bare-name parse (the `@` is stripped by sanitize_name).
		'@branch'                     => array( 'branch', null, false, 'branch' ),
	);

	foreach ( $cases as $handle => $expected ) {
		$parsed = $ws->parse_handle( $handle );
		$assert( $expected[0], $parsed['repo'], "{$handle} → repo" );
		$assert( $expected[1], $parsed['branch_slug'], "{$handle} → branch_slug" );
		$assert( $expected[2], $parsed['is_worktree'], "{$handle} → is_worktree" );
		$assert( $expected[3], $parsed['dir_name'], "{$handle} → dir_name" );
	}

	echo "\nBranch slug generation\n";
	$slug_cases = array(
		'main'                 => 'main',
		'fix/foo'              => 'fix-foo',
		'fix/foo-bar'          => 'fix-foo-bar',
		'feat/auth/oauth'      => 'feat-auth-oauth',
		'release/1.2.3'        => 'release-1.2.3',
		'fix//double-slash'    => 'fix-double-slash',
		'  spaced/branch  '    => 'spaced-branch',
		'fix/foo:bad/chars!@#' => 'fix-foobad-chars',
	);

	foreach ( $slug_cases as $branch => $expected ) {
		$assert( $expected, $ws->slugify_branch( $branch ), "slugify '{$branch}' → '{$expected}'" );
	}

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
