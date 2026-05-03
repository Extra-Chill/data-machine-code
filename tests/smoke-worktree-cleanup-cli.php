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
				'age_days'    => 20 + $i,
				'size_bytes'          => 1024 * 1024 * $i,
				'artifact_size_bytes' => 512 * 1024 * $i,
				'reason_code' => 1 === $i ? 'dirty_worktree' : 'no_merge_signal',
				'reason'      => 1 === $i ? 'working tree dirty (1 files) - pass force=true to override' : 'no merge signal - leaving in place',
			);
		}

		$skipped[] = array(
			'handle'         => 'broken@metadata',
			'repo'           => '',
			'branch'         => '',
			'path'           => '',
			'reason_code'         => 'missing_metadata',
			'reason'              => 'missing repo/branch/path',
			'missing_fields'      => array( 'repo', 'branch', 'path' ),
			'hint'                => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
			'size_bytes'          => 0,
			'artifact_size_bytes' => 0,
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
					'age_days'    => 42,
					'size_bytes'          => 4 * 1024 * 1024 * 1024,
					'artifact_size_bytes' => 3 * 1024 * 1024 * 1024,
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
				'total_size_bytes'      => 10 * 1024 * 1024 * 1024,
				'artifact_size_bytes'   => 7 * 1024 * 1024 * 1024,
				'size_by_repo'          => array( 'repo' => 10 * 1024 * 1024 * 1024 ),
				'artifact_size_by_repo' => array( 'repo' => 7 * 1024 * 1024 * 1024 ),
			),
		);
	}

	class FakeCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			if ( isset( $input['progress_callback'] ) && is_callable( $input['progress_callback'] ) ) {
				$input['progress_callback'](
					array(
						'event'      => 'checking',
						'handle'     => 'repo@merged',
						'checked'    => 1,
						'total'      => 13,
						'candidates' => 1,
						'skipped'    => 0,
						'removed'    => 0,
						'elapsed'    => 1.2,
					)
				);
			}
			$report = datamachine_code_cleanup_report();
			if ( isset( $input['older_than'] ) ) {
				$report['summary']['age_filter'] = array(
					'type'             => 'older_than',
					'older_than'       => $input['older_than'],
					'duration_seconds' => 604800,
					'threshold'        => '2026-04-21T00:00:00+00:00',
					'excluded'         => 2,
					'unknown_age'      => 1,
				);
			}
			return $report;
		}
	}

	class FakeArtifactCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'    => true,
				'dry_run'    => ! empty( $input['dry_run'] ),
				'candidates' => array(
					array(
						'handle'    => 'repo@old',
						'repo'      => 'repo',
						'branch'    => 'old',
						'path'      => '/workspace/repo@old',
						'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ),
					),
				),
				'removed'    => array(),
				'skipped'    => array(
					array(
						'handle'      => 'repo@active',
						'repo'        => 'repo',
						'branch'      => 'active',
						'artifacts'   => array( array( 'path' => 'target', 'size_bytes' => 2048 ) ),
						'reason_code' => 'active_symlink_target',
						'reason'      => 'worktree is an active symlink target',
					),
				),
				'summary'    => array(
					'would_remove_artifacts' => 1,
					'removed_artifacts'      => 0,
					'skipped'                => 1,
					'artifact_size_bytes'    => 1024,
				),
			);
		}
	}

	class FakeEmergencyCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'             => true,
				'mode'                => 'emergency',
				'dry_run'             => empty( $input['apply_plan'] ),
				'artifact_candidates' => array(
					array(
						'handle'    => 'repo@old',
						'repo'      => 'repo',
						'branch'    => 'old',
						'path'      => '/workspace/repo@old',
						'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ),
					),
				),
				'worktree_candidates' => array(
					array(
						'handle'      => 'repo@eligible',
						'repo'        => 'repo',
						'branch'      => 'eligible',
						'path'        => '/workspace/repo@eligible',
						'age_days'    => 90,
						'size_bytes'  => 4096,
						'signal'      => 'cleanup_eligible',
						'reason_code' => 'cleanup_eligible',
					),
				),
				'removed_artifacts'   => empty( $input['apply_plan'] ) ? array() : array( array( 'handle' => 'repo@old', 'repo' => 'repo', 'branch' => 'old', 'path' => '/workspace/repo@old', 'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ) ) ),
				'removed_worktrees'   => array(),
				'skipped'             => array(),
				'summary'             => array(
					'would_remove_artifacts' => 1,
					'would_remove_worktrees' => 1,
					'removed_artifacts'      => empty( $input['apply_plan'] ) ? 0 : 1,
					'removed_worktrees'      => 0,
					'skipped'                => 0,
					'artifact_size_bytes'    => 1024,
					'worktree_size_bytes'    => 4096,
				),
			);
		}
	}

	class FakeListAbility {
		public function execute( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array(
				'success'   => true,
				'worktrees' => array(
					array(
						'handle'      => 'repo',
						'repo'        => 'repo',
						'is_primary'  => true,
						'branch'      => 'main',
						'head'        => 'abcdef123',
						'dirty'       => 0,
						'path'        => '/workspace/repo',
					),
					array(
						'handle'              => 'repo@old',
						'repo'                => 'repo',
						'is_primary'          => false,
						'branch'              => 'old',
						'head'                => 'abcdef456',
						'dirty'               => 0,
						'created_at'          => '2026-04-01T00:00:00+00:00',
						'age_days'            => 28,
						'size_bytes'          => 4 * 1024 * 1024,
						'artifact_size_bytes' => 3 * 1024 * 1024,
						'artifacts'           => array( array( 'path' => 'target', 'size_bytes' => 3 * 1024 * 1024 ) ),
						'stale_reason'        => 'older_than_threshold',
						'path'                => '/workspace/repo@old',
					),
					array(
						'handle'              => 'repo@dirty',
						'repo'                => 'repo',
						'is_primary'          => false,
						'branch'              => 'dirty',
						'head'                => 'abcdef789',
						'dirty'               => 1,
						'age_days'            => null,
						'size_bytes'          => 1024,
						'artifact_size_bytes' => 0,
						'artifacts'           => array(),
						'stale_reason'        => 'dirty',
						'path'                => '/workspace/repo@dirty',
					),
				),
			);
		}
	}

	echo "=== smoke-worktree-cleanup-cli ===\n";

	$ability = new FakeCleanupAbility();
	$artifact_ability = new FakeArtifactCleanupAbility();
	$emergency_ability = new FakeEmergencyCleanupAbility();
	$list_ability = new FakeListAbility();
	$GLOBALS['__abilities'] = array(
		'datamachine/workspace-worktree-cleanup'           => $ability,
		'datamachine/workspace-worktree-cleanup-artifacts' => $artifact_ability,
		'datamachine/workspace-worktree-emergency-cleanup' => $emergency_ability,
		'datamachine/workspace-worktree-list'              => $list_ability,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$doc_comment = ( new ReflectionMethod( $command, 'worktree' ) )->getDocComment() ?: '';

	echo "\n[0a] WP-CLI synopsis exposes cleanup flags\n";
	datamachine_code_cleanup_assert( str_contains( $doc_comment, "\n\t * [--inventory-only]" ), 'worktree synopsis declares --inventory-only at top level' );
	datamachine_code_cleanup_assert( ! str_contains( $doc_comment, "\n\t\t * [--apply-plan=<file>]" ), 'cleanup flags are not hidden behind nested docblock indentation' );

	echo "\n[0] list stale output exposes disk fields\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'list' ), array( 'stale' => true ) );
	datamachine_code_cleanup_assert( in_array( 'table:2:handle,repo,kind,branch,head,dirty,state,created_at,pr,age_days,size,artifacts,stale,path', WP_CLI::$logs, true ), 'worktree list --stale filters to stale rows and includes disk fields' );

	echo "\n[1] JSON output is one parseable document\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false, 'skip_github' => true, 'inventory_only' => false ) === $ability->last_input, 'cleanup flags forwarded to ability' );
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
	datamachine_code_cleanup_assert( str_starts_with( WP_CLI::$logs[0] ?? '', 'Cleanup progress:' ), 'human output streams cleanup progress before summary' );
	datamachine_code_cleanup_assert( in_array( 'Summary:', WP_CLI::$logs, true ), 'human output includes summary after progress' );
	datamachine_code_cleanup_assert( in_array( 'table:1:handle,branch,age_days,size,artifacts,signal,reason_code', WP_CLI::$logs, true ), 'default candidate table uses compact disk fields' );
	datamachine_code_cleanup_assert( in_array( 'table:10:handle,reason_code,age_days,size,artifacts,reason', WP_CLI::$logs, true ), 'default skipped table omits path and hint fields but keeps disk fields' );
	datamachine_code_cleanup_assert( in_array( 'Top repos by worktree size:', WP_CLI::$logs, true ), 'human output includes top repo size summary' );
	datamachine_code_cleanup_assert( in_array( 'Showing 10 of 12 skipped rows. Re-run with --verbose for all rows or --only=<reason_code> to filter.', WP_CLI::$logs, true ), 'human output truncates skipped rows with hint' );
	datamachine_code_cleanup_assert( 1 === count( WP_CLI::$successes ), 'human output keeps success suffix' );

	echo "\n[3] verbose output keeps detailed human fields\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'verbose' => true ) );
	datamachine_code_cleanup_assert( in_array( 'table:1:handle,branch,age_days,size,artifacts,signal,reason', WP_CLI::$logs, true ), 'verbose candidate table keeps full reason field' );
	datamachine_code_cleanup_assert( in_array( 'table:12:handle,reason_code,reason,age_days,size,artifacts,repo,branch,path,primary_path,missing,hint', WP_CLI::$logs, true ), 'verbose skipped table keeps diagnostic fields' );

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

	echo "\n[6] --older-than forwards and renders age summary\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'older-than' => '7d' ) );
	datamachine_code_cleanup_assert( '7d' === ( $ability->last_input['older_than'] ?? null ), 'older-than forwards to cleanup ability as older_than' );
	datamachine_code_cleanup_assert( is_callable( $ability->last_input['progress_callback'] ?? null ), 'human cleanup passes progress callback to ability' );
	datamachine_code_cleanup_assert( in_array( 'table:10:metric,count', WP_CLI::$logs, true ), 'age filter and disk summary rows are rendered' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'older-than' => '7d', 'format' => 'json' ) );
	$older_than_json = json_decode( WP_CLI::$logs[0], true );
	datamachine_code_cleanup_assert( '7d' === ( $older_than_json['summary']['age_filter']['older_than'] ?? '' ), 'JSON summary exposes older_than filter value' );
	datamachine_code_cleanup_assert( 2 === (int) ( $older_than_json['summary']['age_filter']['excluded'] ?? 0 ), 'JSON summary exposes age-filter excluded count' );

	echo "\n[7] --sort forwards cleanup sorting field\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'sort' => 'size', 'format' => 'json' ) );
	datamachine_code_cleanup_assert( 'size' === ( $ability->last_input['sort'] ?? null ), '--sort forwards to cleanup ability' );

	echo "\n[8] --inventory-only forwards bounded cleanup review flag\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'inventory-only' => true, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( true === ( $ability->last_input['inventory_only'] ?? null ), '--inventory-only forwards to cleanup ability' );

	echo "\n[8b] emergency-cleanup emits fast plan and apply-plan path\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'emergency-cleanup' ), array( 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false ) === $emergency_ability->last_input, 'emergency-cleanup defaults to dry-run and no force' );
	$emergency_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'emergency' === ( $emergency_json['mode'] ?? '' ), 'emergency-cleanup JSON includes mode' );
	datamachine_code_cleanup_assert( 'target' === ( $emergency_json['artifact_candidates'][0]['artifacts'][0]['path'] ?? '' ), 'emergency-cleanup JSON includes artifact candidates first' );

	$emergency_plan_file = sys_get_temp_dir() . '/dmc-emergency-cleanup-plan-' . bin2hex( random_bytes( 3 ) ) . '.json';
	file_put_contents( $emergency_plan_file, wp_json_encode( $emergency_json ) );
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'emergency-cleanup' ), array( 'apply-plan' => $emergency_plan_file, 'force' => true ) );
	datamachine_code_cleanup_assert( false === ( $emergency_ability->last_input['dry_run'] ?? null ), 'emergency-cleanup apply-plan enters apply mode' );
	datamachine_code_cleanup_assert( true === ( $emergency_ability->last_input['force'] ?? null ), 'emergency-cleanup forwards explicit force for human-reviewed apply' );
	datamachine_code_cleanup_assert( 'repo@old' === ( $emergency_ability->last_input['apply_plan']['artifact_candidates'][0]['handle'] ?? '' ), 'emergency-cleanup forwards decoded apply plan' );
	datamachine_code_cleanup_assert( 'Emergency cleanup summary:' === ( WP_CLI::$logs[0] ?? '' ), 'emergency-cleanup human output uses emergency summary' );
	unlink( $emergency_plan_file );

	echo "\n[9] cleanup-artifacts forwards plan-first flags and renders separately\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup-artifacts' ), array( 'dry-run' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false ) === $artifact_ability->last_input, 'cleanup-artifacts dry-run flags forwarded to ability' );
	$artifact_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'target' === ( $artifact_json['candidates'][0]['artifacts'][0]['path'] ?? '' ), 'cleanup-artifacts JSON includes artifact paths' );

	$artifact_plan_file = sys_get_temp_dir() . '/dmc-artifact-cleanup-plan-' . bin2hex( random_bytes( 3 ) ) . '.json';
	file_put_contents( $artifact_plan_file, wp_json_encode( $artifact_json ) );
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup-artifacts' ), array( 'apply-plan' => $artifact_plan_file, 'force' => true ) );
	datamachine_code_cleanup_assert( false === ( $artifact_ability->last_input['dry_run'] ?? null ), 'cleanup-artifacts apply-plan enters apply mode' );
	datamachine_code_cleanup_assert( true === ( $artifact_ability->last_input['force'] ?? null ), 'cleanup-artifacts forwards explicit force for dirty/unpushed artifacts' );
	datamachine_code_cleanup_assert( 'repo@old' === ( $artifact_ability->last_input['apply_plan']['candidates'][0]['handle'] ?? '' ), 'cleanup-artifacts forwards decoded apply plan' );
	datamachine_code_cleanup_assert( 'Artifact cleanup summary:' === ( WP_CLI::$logs[0] ?? '' ), 'cleanup-artifacts human output uses artifact-specific summary' );
	unlink( $artifact_plan_file );

	echo "\nAll worktree cleanup CLI smoke tests passed.\n";
}
