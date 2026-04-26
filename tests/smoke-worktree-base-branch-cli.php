<?php
/**
 * Smoke test for the workspace worktree --base-branch CLI alias.
 *
 *   php tests/smoke-worktree-base-branch-cli.php
 *
 * @package DataMachineCode\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	class WP_CLI {
		public static array $logs = array();

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function success( string $message ): void {
			self::$logs[] = 'success: ' . $message;
		}

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}

		public static function warning( string $message ): void {
			self::$logs[] = 'warning: ' . $message;
		}
	}

	function is_wp_error( $value ): bool {
		return false;
	}

	function wp_get_ability( string $name ) {
		return $GLOBALS['__abilities'][ $name ] ?? null;
	}
}

namespace DataMachine\Cli {
	class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void {}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function datamachine_code_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	class FakeWorktreeAddAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'        => true,
				'message'        => 'created',
				'handle'         => 'data-machine@feat-test',
				'path'           => '/tmp/worktree',
				'branch'         => $input['branch'] ?? '',
				'created_branch' => true,
			);
		}
	}

	echo "=== smoke-worktree-base-branch-cli ===\n";

	$ability = new FakeWorktreeAddAbility();
	$GLOBALS['__abilities'] = array(
		'datamachine/workspace-worktree-add' => $ability,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();

	echo "\n[1] bare base branch maps to origin ref\n";
	$command->worktree(
		array( 'add', 'data-machine', 'feat/test' ),
		array( 'base-branch' => 'main', 'skip-bootstrap' => true, 'skip-context-injection' => true )
	);
	datamachine_code_assert( 'origin/main' === $ability->last_input['from'], 'main maps to origin/main' );
	datamachine_code_assert( false === $ability->last_input['bootstrap'], 'skip-bootstrap still forwarded' );
	datamachine_code_assert( false === $ability->last_input['inject_context'], 'skip-context-injection still forwarded' );

	echo "\n[2] branch names with slashes still map to origin refs\n";
	$command->worktree(
		array( 'add', 'data-machine', 'feat/test' ),
		array( 'base-branch' => 'release/next' )
	);
	datamachine_code_assert( 'origin/release/next' === $ability->last_input['from'], 'release/next maps to origin/release/next' );

	echo "\n[3] full ref is preserved\n";
	$command->worktree(
		array( 'add', 'data-machine', 'feat/test' ),
		array( 'base-branch' => 'upstream/develop' )
	);
	datamachine_code_assert( 'upstream/develop' === $ability->last_input['from'], 'remote ref preserved' );

	echo "\n[4] --from wins by rejecting ambiguous input\n";
	try {
		$command->worktree(
			array( 'add', 'data-machine', 'feat/test' ),
			array( 'from' => 'origin/main', 'base-branch' => 'develop' )
		);
		datamachine_code_assert( false, 'ambiguous flags should throw' );
	} catch ( RuntimeException $e ) {
		datamachine_code_assert( str_contains( $e->getMessage(), 'not both' ), 'ambiguous flags fail clearly' );
	}

	echo "\nAll worktree base-branch CLI smoke tests passed.\n";
}
