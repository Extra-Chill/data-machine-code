<?php
/**
 * Smoke test for workspace worktree cleanup CLI report rendering.
 *
 *   php tests/smoke-worktree-cleanup-cli.php
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

	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
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

	function datamachine_code_cleanup_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_code_cleanup_report(): array {
		$skipped = array();
		for ( $i = 1; $i <= 11; $i++ ) {
			$skipped[] = array(
				'handle'      => 'repo@feature-' . $i,
				'repo'        => 'repo',
				'branch'      => 'feature-' . $i,
				'path'        => '/workspace/repo@feature-' . $i,
				'reason_code' => 1 === $i ? 'dirty_worktree' : 'no_merge_signal',
				'reason'      => 1 === $i ? 'working tree dirty (1 files) - pass force=true to override' : 'no merge signal - leaving in place',
			);
		}

		$skipped[] = array(
			'handle'         => 'broken@metadata',
			'repo'           => '',
			'branch'         => '',
			'path'           => '',
			'reason_code'    => 'missing_metadata',
			'reason'         => 'missing repo/branch/path',
			'missing_fields' => array( 'repo', 'branch', 'path' ),
			'hint'           => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
		);

		return array(
			'success'    => true,
			'dry_run'    => true,
			'candidates' => array(
				array(
					'handle'      => 'repo@merged',
					'repo'        => 'repo',
					'branch'      => 'merged',
					'path'        => '/workspace/repo@merged',
					'signal'      => 'upstream-gone',
					'reason_code' => 'upstream-gone',
					'reason'      => 'remote branch deleted (likely merged + auto-deleted)',
				),
			),
			'removed'    => array(),
			'skipped'    => $skipped,
			'summary'    => array(
				'would_remove'         => 1,
				'removed'              => 0,
				'skipped'              => count( $skipped ),
				'skipped_by_reason'    => array(
					'dirty_worktree'   => 1,
					'missing_metadata' => 1,
					'no_merge_signal'  => 10,
				),
				'candidates_by_signal' => array(
					'upstream-gone' => 1,
				),
			),
		);
	}

	class FakeCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return datamachine_code_cleanup_report();
		}
	}

	echo "=== smoke-worktree-cleanup-cli ===\n";

	$ability = new FakeCleanupAbility();
	$GLOBALS['__abilities'] = array(
		'datamachine/workspace-worktree-cleanup' => $ability,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();

	echo "\n[1] JSON output is one parseable document\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false, 'skip_github' => true ) === $ability->last_input, 'cleanup flags forwarded to ability' );
	datamachine_code_cleanup_assert( 1 === count( WP_CLI::$logs ), 'JSON path writes exactly one stdout log entry' );
	datamachine_code_cleanup_assert( array() === WP_CLI::$successes, 'JSON path emits no success suffix' );
	$decoded = json_decode( WP_CLI::$logs[0], true );
	datamachine_code_cleanup_assert( JSON_ERROR_NONE === json_last_error(), 'JSON output parses cleanly' );
	datamachine_code_cleanup_assert( 'dirty_worktree' === ( $decoded['skipped'][0]['reason_code'] ?? '' ), 'JSON rows include stable reason code' );
	datamachine_code_cleanup_assert( array( 'repo', 'branch', 'path' ) === ( $decoded['skipped'][11]['missing_fields'] ?? array() ), 'JSON missing_metadata includes missing fields' );
	datamachine_code_cleanup_assert( str_contains( $decoded['skipped'][11]['hint'] ?? '', 'workspace worktree prune' ), 'JSON missing_metadata includes remediation hint' );
	datamachine_code_cleanup_assert( 10 === (int) ( $decoded['summary']['skipped_by_reason']['no_merge_signal'] ?? 0 ), 'JSON summary includes reason counts' );

	echo "\n[1b] --apply-plan decodes JSON and forbids force\n";
	$plan_file = sys_get_temp_dir() . '/dmc-cleanup-plan-' . bin2hex( random_bytes( 3 ) ) . '.json';
	file_put_contents( $plan_file, wp_json_encode( datamachine_code_cleanup_report() ) );
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'apply-plan' => $plan_file, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( false === ( $ability->last_input['dry_run'] ?? null ), '--apply-plan does not imply dry-run' );
	datamachine_code_cleanup_assert( false === ( $ability->last_input['force'] ?? null ), '--apply-plan forwards force=false' );
	datamachine_code_cleanup_assert( 'repo@merged' === ( $ability->last_input['apply_plan']['candidates'][0]['handle'] ?? '' ), '--apply-plan forwards decoded plan' );
	try {
		$command->worktree( array( 'cleanup' ), array( 'apply-plan' => $plan_file, 'force' => true ) );
		datamachine_code_cleanup_assert( false, '--apply-plan --force throws' );
	} catch ( RuntimeException $e ) {
		datamachine_code_cleanup_assert( str_contains( $e->getMessage(), 'Do not combine --apply-plan with --force' ), '--apply-plan --force is rejected' );
	}
	unlink( $plan_file );

	echo "\n[2] default output is concise and summary-first\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true ) );
	datamachine_code_cleanup_assert( 'Summary:' === ( WP_CLI::$logs[0] ?? '' ), 'human output starts with summary' );
	datamachine_code_cleanup_assert( in_array( 'table:1:handle,branch,signal,reason_code', WP_CLI::$logs, true ), 'default candidate table uses compact fields' );
	datamachine_code_cleanup_assert( in_array( 'table:10:handle,reason_code,reason', WP_CLI::$logs, true ), 'default skipped table omits path and hint fields' );
	datamachine_code_cleanup_assert( in_array( 'Showing 10 of 12 skipped rows. Re-run with --verbose for all rows or --only=<reason_code> to filter.', WP_CLI::$logs, true ), 'human output truncates skipped rows with hint' );
	datamachine_code_cleanup_assert( 1 === count( WP_CLI::$successes ), 'human output keeps success suffix' );

	echo "\n[3] verbose output keeps detailed human fields\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'verbose' => true ) );
	datamachine_code_cleanup_assert( in_array( 'table:1:handle,branch,signal,reason', WP_CLI::$logs, true ), 'verbose candidate table keeps full reason field' );
	datamachine_code_cleanup_assert( in_array( 'table:12:handle,reason_code,reason,repo,branch,path,primary_path,missing,hint', WP_CLI::$logs, true ), 'verbose skipped table keeps diagnostic fields' );

	echo "\n[4] --only filters rows while keeping full summary\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'only' => 'missing-metadata', 'format' => 'json' ) );
	$filtered = json_decode( WP_CLI::$logs[0], true );
	datamachine_code_cleanup_assert( array() === ( $filtered['candidates'] ?? null ), '--only reason hides candidates' );
	datamachine_code_cleanup_assert( 1 === count( $filtered['skipped'] ?? array() ), '--only reason keeps matching skipped rows only' );
	datamachine_code_cleanup_assert( 'missing_metadata' === ( $filtered['skipped'][0]['reason_code'] ?? '' ), '--only alias resolves to reason code' );
	datamachine_code_cleanup_assert( 12 === (int) ( $filtered['summary']['skipped'] ?? 0 ), '--only leaves summary counts unfiltered' );

	echo "\n[5] --only aliases resolve candidate section\n";
	foreach ( array( 'candidates', 'would-remove', 'would_remove' ) as $alias ) {
		WP_CLI::$logs      = array();
		WP_CLI::$successes = array();
		$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'only' => $alias, 'format' => 'json' ) );
		$filtered = json_decode( WP_CLI::$logs[0], true );
		datamachine_code_cleanup_assert( 1 === count( $filtered['candidates'] ?? array() ), "--only={$alias} keeps candidates" );
		datamachine_code_cleanup_assert( array() === ( $filtered['removed'] ?? null ), "--only={$alias} hides removed" );
		datamachine_code_cleanup_assert( array() === ( $filtered['skipped'] ?? null ), "--only={$alias} hides skipped" );
		datamachine_code_cleanup_assert( 1 === (int) ( $filtered['summary']['would_remove'] ?? 0 ), "--only={$alias} keeps summary counts" );
	}

	echo "\nAll worktree cleanup CLI smoke tests passed.\n";
}
