<?php
/**
 * Worktree admission must not block on a workspace-wide allocation walk.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	final class WorktreeContextInjector {
		public static function bootstrap_capacity_reservations(): array {
			return array( 'bytes' => 0, 'inodes' => 0, 'handles' => array(), 'by_handle' => array() );
		}

		public static function capacity_reservations( string $workspace_path = '' ): array {
			return self::bootstrap_capacity_reservations();
		}
	}

	final class WorktreeDiskBudget {
		public static array $options = array();

		public static function thresholds( string $repo, string $branch ): array {
			return array();
		}

		public static function inspect( string $workspace, array $thresholds, bool $force, array $options, array $demand ): array {
			self::$options = $options;
			return array( 'status' => 'ok', 'creation_allowed' => true );
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeLifecycle.php';

	use DataMachineCode\Workspace\WorkspaceWorktreeLifecycle;
	use DataMachineCode\Workspace\WorktreeDiskBudget;

	final class WorktreePlanCapacityHotPathHarness {
		use WorkspaceWorktreeLifecycle { inspect_worktree_capacity as public inspectCapacity; }

		private string $workspace_path = '/workspace';
	}

	$result = ( new WorktreePlanCapacityHotPathHarness() )->inspectCapacity(
		'repo',
		'branch',
		false,
		array( 'bytes' => 1, 'inodes' => 1 )
	);

	if ( true !== ( $result['creation_allowed'] ?? false ) ) {
		throw new RuntimeException('Capacity admission result was not preserved.');
	}
	if ( false !== ( WorktreeDiskBudget::$options['include_workspace_usage'] ?? null ) ) {
		throw new RuntimeException('Worktree planning enabled the synchronous workspace allocation walk.');
	}

	echo "worktree-plan-capacity-hot-path: ok\n";
}
