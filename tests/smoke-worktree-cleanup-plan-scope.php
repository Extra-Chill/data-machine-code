<?php
/**
 * Smoke test for cleanup apply-plan revalidation branch/slug matching.
 *
 * Run: php tests/smoke-worktree-cleanup-plan-scope.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists(__NAMESPACE__ . '\\FilesystemHelper') ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	if ( ! class_exists('WP_Error') ) {
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

	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists('apply_filters') ) {
		function apply_filters( string $hook_name, $value ) {
			return $value;
		}
	}

	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode($value, $flags, $depth);
		}
	}

	include __DIR__ . '/../inc/Support/GitHubRemote.php';
	include __DIR__ . '/../inc/Support/GitRunner.php';
	include __DIR__ . '/../inc/Support/PathSecurity.php';
	include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	include __DIR__ . '/../inc/Workspace/Workspace.php';

	$failures = 0;
	$total    = 0;
	$assert   = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $expected === $actual ) {
			echo "  ✓ {$message}\n";
			return;
		}

		++$failures;
		echo "  ✗ {$message}\n";
		echo '    expected: ' . var_export($expected, true) . "\n";
		echo '    actual:   ' . var_export($actual, true) . "\n";
	};

	$ws               = new \DataMachineCode\Workspace\Workspace();
	$scope_reflection = new \ReflectionMethod(\DataMachineCode\Workspace\Workspace::class, 'scope_worktree_cleanup_to_plan');

	$scoped = $scope_reflection->invoke(
		$ws,
		array(
			array(
				'handle' => 'demo@fix-foo',
				'repo'   => 'demo',
				'branch' => 'fix-foo',
				'path'   => '/workspace/demo@fix-foo',
				'signal' => 'pr-merged',
			),
		),
		array(
			array(
				'handle' => 'demo@fix-foo',
				'repo'   => 'demo',
				'branch' => 'fix/foo',
				'path'   => '/workspace/demo@fix-foo',
				'signal' => 'pr-merged',
			),
		),
		array()
	);

	$assert(1, count($scoped['candidates'] ?? array()), 'slugified planned branch matches current slash branch');
	$assert(array(), $scoped['skipped'] ?? array(), 'matching branch slug is not skipped as plan_mismatch');

	$scoped_mismatch = $scope_reflection->invoke(
		$ws,
		array(
			array(
				'handle' => 'demo@fix-foo',
				'repo'   => 'demo',
				'branch' => 'fix-foo',
				'path'   => '/workspace/demo@fix-foo',
				'signal' => 'pr-merged',
			),
		),
		array(
			array(
				'handle' => 'demo@fix-foo',
				'repo'   => 'demo',
				'branch' => 'other/foo',
				'path'   => '/workspace/demo@fix-foo',
				'signal' => 'pr-merged',
			),
		),
		array()
	);

	$assert(0, count($scoped_mismatch['candidates'] ?? array()), 'different branch slug remains blocked');
	$assert('plan_mismatch', $scoped_mismatch['skipped'][0]['reason_code'] ?? '', 'different branch slug reports plan_mismatch');

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit($failures > 0 ? 1 : 0);
}
