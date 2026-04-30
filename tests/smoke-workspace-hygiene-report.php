<?php
/**
 * Smoke test for bounded workspace hygiene report inventory mode.
 *
 *   php tests/smoke-workspace-hygiene-report.php
 *
 * @package DataMachineCode\Tests
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
	$workspace_root = sys_get_temp_dir() . '/dmc-hygiene-report-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $workspace_root, 0755, true );
	mkdir( $workspace_root . '/alpha', 0755, true );
	mkdir( $workspace_root . '/alpha@feat-one', 0755, true );
	mkdir( $workspace_root . '/beta@missing-metadata', 0755, true );
	file_put_contents( $workspace_root . '/not-a-dir.txt', 'ignored' );

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DATAMACHINE_WORKSPACE_PATH', $workspace_root );

	$GLOBALS['__dmc_hygiene_metadata'] = array(
		'alpha@feat-one' => array(
			'created_at'      => '2026-04-30T00:00:00+00:00',
			'lifecycle_state' => 'active',
			'pr_url'          => 'https://github.com/Extra-Chill/data-machine-code/pull/1',
			'pr_number'       => 1,
		),
	);

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( $code = '', $message = '', $data = array() ) {}
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	function get_option( string $name, $default = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $GLOBALS['__dmc_hygiene_metadata'];
	}

	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

	function datamachine_code_hygiene_report_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_code_hygiene_report_cleanup( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $path . '/' . $entry;
			if ( is_dir( $child ) ) {
				datamachine_code_hygiene_report_cleanup( $child );
				continue;
			}
			unlink( $child );
		}
		rmdir( $path );
	}

	class Hygiene_Report_Workspace extends \DataMachineCode\Workspace\Workspace {
		public int $full_listing_calls = 0;

		public function worktree_list( ?string $repo = null, ?string $state = null ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			++$this->full_listing_calls;
			return array(
				'success'   => true,
				'worktrees' => array(
					array(
						'handle'     => 'alpha',
						'repo'       => 'alpha',
						'is_primary' => true,
						'dirty'      => 0,
					),
					array(
						'handle'      => 'alpha@feat-one',
						'repo'        => 'alpha',
						'is_primary'  => false,
						'is_worktree' => true,
						'dirty'       => 7,
					),
				),
			);
		}
	}

	echo "=== smoke-workspace-hygiene-report ===\n";

	try {
		$workspace = new Hygiene_Report_Workspace();

		echo "\n[1] Minimal report uses cheap top-level inventory\n";
		$minimal = $workspace->workspace_hygiene_report(
			array(
				'include_cleanup' => false,
				'include_sizes'   => false,
			)
		);
		datamachine_code_hygiene_report_assert( ! is_wp_error( $minimal ), 'minimal report succeeds' );
		datamachine_code_hygiene_report_assert( 0 === $workspace->full_listing_calls, 'minimal report does not call full worktree_list' );
		datamachine_code_hygiene_report_assert( 'top_level_inventory' === ( $minimal['worktree_status_mode'] ?? '' ), 'minimal report declares inventory mode' );
		datamachine_code_hygiene_report_assert( 1 === (int) ( $minimal['worktrees']['primaries'] ?? 0 ), 'inventory counts primaries' );
		datamachine_code_hygiene_report_assert( 2 === (int) ( $minimal['worktrees']['worktrees'] ?? 0 ), 'inventory counts worktrees' );
		datamachine_code_hygiene_report_assert( 1 === (int) ( $minimal['worktrees']['missing_metadata'] ?? 0 ), 'inventory counts missing metadata from registry only' );
		datamachine_code_hygiene_report_assert( 'alpha' === ( $minimal['top_repos_by_worktrees'][0]['repo'] ?? '' ), 'inventory builds repo count leaders' );
		datamachine_code_hygiene_report_assert( 1 === (int) ( $minimal['top_repos_by_worktrees'][0]['worktree_count'] ?? 0 ), 'repo count leaders ignore primaries and missing-metadata repo still sorts after alpha' );

		echo "\n[2] Full report remains explicitly available\n";
		$full = $workspace->workspace_hygiene_report(
			array(
				'include_cleanup'         => false,
				'include_sizes'           => false,
				'include_worktree_status' => true,
			)
		);
		datamachine_code_hygiene_report_assert( ! is_wp_error( $full ), 'full report succeeds' );
		datamachine_code_hygiene_report_assert( 1 === $workspace->full_listing_calls, 'full report calls worktree_list once' );
		datamachine_code_hygiene_report_assert( 'full_git_status' === ( $full['worktree_status_mode'] ?? '' ), 'full report declares git status mode' );
		datamachine_code_hygiene_report_assert( 1 === (int) ( $full['worktrees']['dirty'] ?? 0 ), 'full report preserves detailed dirty status summary' );
	} finally {
		datamachine_code_hygiene_report_cleanup( $workspace_root );
	}

	echo "\nAll workspace hygiene report smoke tests passed.\n";
}
