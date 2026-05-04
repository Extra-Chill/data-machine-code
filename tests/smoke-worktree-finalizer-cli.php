<?php
/**
 * Smoke test for worktree finalizer CLI routing.
 *
 * Run: php tests/smoke-worktree-finalizer-cli.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	class WP_CLI {
		public static array $logs = array();
		public static array $successes = array();

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
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
		protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void {
			\WP_CLI::log( 'table:' . count( $items ) . ':' . implode( ',', $fields ) );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function datamachine_code_finalizer_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	class FakeWorktreeFinalizeAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'         => true,
				'handle'          => $input['handle'],
				'path'            => '/workspace/' . $input['handle'],
				'lifecycle_state' => $input['state'],
				'metadata'        => array(
					'lifecycle_state' => $input['state'],
					'pr_url'          => $input['pr'] ?? null,
				),
				'message'         => 'finalized',
			);
		}
	}

	class FakeWorktreeListAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'   => true,
				'worktrees' => array(
					array(
						'handle'          => 'repo@feature',
						'repo'            => 'repo',
						'is_primary'      => false,
						'branch'          => 'feature',
						'head'            => 'abcdef123',
						'dirty'           => 0,
						'lifecycle_state' => 'cleanup_eligible',
						'created_at'      => '2026-04-29T00:00:00+00:00',
						'pr_url'          => 'https://github.com/acme/repo/pull/7',
						'path'            => '/workspace/repo@feature',
						'metadata'        => array( 'lifecycle_state' => 'cleanup_eligible' ),
					),
				),
			);
		}
	}

	echo "=== smoke-worktree-finalizer-cli ===\n";

	$finalize = new FakeWorktreeFinalizeAbility();
	$list     = new FakeWorktreeListAbility();
	$GLOBALS['__abilities'] = array(
		'datamachine/workspace-worktree-finalize' => $finalize,
		'datamachine/workspace-worktree-list'     => $list,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$worktree_method = new ReflectionMethod( $command, 'worktree' );
	$worktree_docs   = (string) $worktree_method->getDocComment();
	datamachine_code_finalizer_assert( str_contains( $worktree_docs, '[--pr=<url-or-number>]' ), 'worktree synopsis documents finalizer PR flag' );
	datamachine_code_finalizer_assert( str_contains( $worktree_docs, '[--state=<state>]' ), 'worktree synopsis documents finalizer state flag' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'finalize', 'repo@feature' ), array( 'pr' => 'https://github.com/acme/repo/pull/7' ) );
	datamachine_code_finalizer_assert( array( 'handle' => 'repo@feature', 'state' => 'pr_opened', 'pr' => 'https://github.com/acme/repo/pull/7' ) === $finalize->last_input, 'finalize defaults PR-bearing records to pr_opened' );
	datamachine_code_finalizer_assert( in_array( 'State:  pr_opened', WP_CLI::$logs, true ), 'finalize output prints lifecycle state' );
	datamachine_code_finalizer_assert( in_array( 'PR:     https://github.com/acme/repo/pull/7', WP_CLI::$logs, true ), 'finalize output prints PR URL' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'mark-cleanup-eligible', 'repo@feature' ), array() );
	datamachine_code_finalizer_assert( array( 'handle' => 'repo@feature', 'state' => 'cleanup_eligible' ) === $finalize->last_input, 'mark-cleanup-eligible routes to cleanup_eligible state' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'list' ), array( 'state' => 'cleanup_eligible' ) );
	datamachine_code_finalizer_assert( array( 'state' => 'cleanup_eligible', 'include_status' => false, 'include_disk' => false ) === $list->last_input, 'list forwards lifecycle state filter and the cheap-listing defaults from #217' );
	datamachine_code_finalizer_assert( in_array( 'table:1:handle,repo,kind,branch,head,dirty,state,liveness,last_seen_at,owner,agent,session,task,pr,age_days,size,artifacts,stale,path', WP_CLI::$logs, true ), 'list human table exposes state, liveness, owner/session/task, and PR columns' );

	echo "\nAll worktree finalizer CLI smoke tests passed.\n";
}
