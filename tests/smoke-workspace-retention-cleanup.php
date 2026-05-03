<?php
/**
 * Smoke test for workspace retention cleanup orchestration.
 *
 *   php tests/smoke-workspace-retention-cleanup.php
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
}

namespace {
	$workspace_root = sys_get_temp_dir() . '/dmc-retention-cleanup-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $workspace_root, 0755, true );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
		define( 'DATAMACHINE_WORKSPACE_PATH', $workspace_root );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( $code = '', $message = '', $data = array() ) {}
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	require __DIR__ . '/../inc/Workspace/Workspace.php';

	function datamachine_code_retention_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	class Retention_Cleanup_Workspace extends \DataMachineCode\Workspace\Workspace {
		public array $worktree_opts = array();
		public array $artifact_opts = array();

		public function worktree_cleanup_merged( array $opts = array() ): array|\WP_Error {
			$this->worktree_opts = $opts;
			return array(
				'success'    => true,
				'dry_run'    => ! empty( $opts['dry_run'] ),
				'candidates' => array(),
				'removed'    => empty( $opts['dry_run'] ) ? array( array( 'handle' => 'repo@old', 'size_bytes' => 2048 ) ) : array(),
				'skipped'    => array(),
				'summary'    => array(
					'would_remove'      => 1,
					'removed'           => empty( $opts['dry_run'] ) ? 1 : 0,
					'total_size_bytes'  => 2048,
					'skipped_by_reason' => array( 'dirty_worktree' => 1 ),
				),
			);
		}

		public function worktree_cleanup_artifacts( array $opts = array() ): array|\WP_Error {
			$this->artifact_opts[] = $opts;
			$is_apply = isset( $opts['apply_plan'] );
			return array(
				'success'    => true,
				'dry_run'    => ! $is_apply,
				'candidates' => array( array( 'handle' => 'repo@active' ) ),
				'removed'    => $is_apply ? array( array( 'handle' => 'repo@active', 'artifacts' => array( array( 'path' => 'vendor', 'size_bytes' => 512 ) ) ) ) : array(),
				'skipped'    => array(),
				'summary'    => array(
					'would_remove_artifacts' => 1,
					'removed_artifacts'      => $is_apply ? 1 : 0,
					'artifact_size_bytes'    => 512,
					'removed_size_bytes'     => $is_apply ? 512 : 0,
					'skipped_by_reason'      => array( 'unpushed_commits' => 2 ),
				),
			);
		}
	}

	echo "=== smoke-workspace-retention-cleanup ===\n";

	try {
		$workspace = new Retention_Cleanup_Workspace();
		$result    = $workspace->workspace_retention_cleanup( array( 'worktree_older_than' => '30d' ) );

		datamachine_code_retention_assert( ! is_wp_error( $result ), 'retention cleanup succeeds' );
		datamachine_code_retention_assert( false === (bool) ( $result['dry_run'] ?? true ), 'retention cleanup defaults to destructive apply mode' );
		datamachine_code_retention_assert( '30d' === ( $workspace->worktree_opts['older_than'] ?? '' ), 'worktree age policy is forwarded' );
		datamachine_code_retention_assert( true === ( $workspace->worktree_opts['skip_github'] ?? false ), 'scheduled-safe cleanup skips GitHub by default' );
		datamachine_code_retention_assert( 2 === count( $workspace->artifact_opts ), 'artifact cleanup dry-runs then applies reviewed plan' );
		datamachine_code_retention_assert( 2 === (int) ( $result['report']['removed_count'] ?? 0 ), 'report includes removed worktree plus artifact counts' );
		datamachine_code_retention_assert( 2560 === (int) ( $result['report']['freed_bytes'] ?? 0 ), 'report sums freed worktree and artifact bytes' );
		datamachine_code_retention_assert( 3 === (int) ( $result['report']['skipped_dirty_unpushed_count'] ?? 0 ), 'report sums dirty and unpushed safety skips' );
		datamachine_code_retention_assert( isset( $result['report']['remaining_disk_budget_human'] ), 'report exposes remaining disk budget' );
	} finally {
		rmdir( $workspace_root );
	}

	echo "\nAll workspace retention cleanup smoke tests passed.\n";
}
