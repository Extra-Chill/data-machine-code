<?php
/**
 * Smoke test for workspace hygiene CLI rendering.
 *
 *   php tests/smoke-workspace-hygiene-cli.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['__cli_logs'] = array();

	class WP_CLI {
		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function success( string $message ): void {
			$GLOBALS['__cli_logs'][] = 'success: ' . $message;
		}

		public static function log( string $message ): void {
			$GLOBALS['__cli_logs'][] = $message;
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
		protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			\WP_CLI::log( 'table:' . count( $items ) . ':' . implode( ',', $fields ) );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function datamachine_code_hygiene_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_code_hygiene_report(): array {
		return array(
			'success'                   => true,
			'generated_at'              => '2026-04-29T00:00:00+00:00',
			'workspace_path'            => '/workspace',
			'destructive'               => false,
			'size'                      => array(
				'mode'            => 'best_effort_top_level_du',
				'mode_note'       => 'Workspace size is best-effort.',
				'total_human'     => '712.6 GiB',
				'scan_complete'   => true,
				'by_kind'         => array(
					array( 'kind' => 'worktree', 'bytes' => 4096, 'human' => '4.0 KiB' ),
				),
				'top_entries'     => array(
					array( 'handle' => 'data-machine@big', 'kind' => 'worktree', 'repo' => 'data-machine', 'bytes' => 4096, 'human' => '4.0 KiB' ),
				),
			),
			'disk'                      => array(
				'free_human' => '1.1 GiB',
			),
			'worktrees'                 => array(
				'worktrees'          => 42,
				'artifacts'          => 1,
				'protected_dirty'    => 3,
				'protected_unpushed' => 2,
				'missing_metadata'   => 4,
				'external'           => 7,
			),
			'worktree_status_mode'      => 'top_level_inventory',
			'top_repos_by_worktrees'    => array(
				array( 'repo' => 'data-machine', 'worktree_count' => 17 ),
			),
			'top_repos_by_size'         => array(
				array( 'repo' => 'data-machine', 'bytes' => 4096, 'human' => '4.0 KiB' ),
			),
			'cleanup'                   => array(
				'included'           => true,
				'dry_run'            => true,
				'skip_github'        => true,
				'inventory_only'     => true,
				'summary'            => array( 'would_remove' => 9 ),
				'biggest_candidates' => array(
					array( 'handle' => 'data-machine@merged', 'branch' => 'merged', 'signal' => 'upstream-gone' ),
				),
			),
			'suggested_cleanup_command' => 'wp datamachine-code workspace worktree cleanup --dry-run --inventory-only --skip-github --format=json',
			'notes'                     => array( 'Cleanup summary uses inventory-only dry-run detection (--inventory-only --skip-github); no per-worktree git probes or GitHub API lookups are required.' ),
		);
	}

	class FakeHygieneAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return datamachine_code_hygiene_report();
		}
	}

	echo "=== smoke-workspace-hygiene-cli ===\n";

	$ability                 = new FakeHygieneAbility();
	$GLOBALS['__abilities'] = array(
		'datamachine/workspace-hygiene-report' => $ability,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();

	echo "\n[1] JSON output is parseable and forwards bounded flags\n";
	$GLOBALS['__cli_logs'] = array();
	$command->hygiene( array(), array( 'format' => 'json', 'skip-cleanup' => true, 'skip-sizes' => true, 'size-limit' => '25' ) );
	datamachine_code_hygiene_assert( array( 'include_cleanup' => false, 'include_sizes' => false, 'include_worktree_status' => false, 'size_limit' => 25 ) === $ability->last_input, 'CLI forwards skip flags, status mode, and size limit' );
	datamachine_code_hygiene_assert( 1 === count( $GLOBALS['__cli_logs'] ), 'JSON path writes one stdout document' );
	$decoded = json_decode( (string) $GLOBALS['__cli_logs'][0], true );
	datamachine_code_hygiene_assert( JSON_ERROR_NONE === json_last_error(), 'JSON output parses cleanly' );
	datamachine_code_hygiene_assert( false === ( $decoded['destructive'] ?? true ), 'JSON report is explicitly non-destructive' );
	datamachine_code_hygiene_assert( '1.1 GiB' === ( $decoded['disk']['free_human'] ?? '' ), 'JSON report includes free disk space' );
	datamachine_code_hygiene_assert( 'top_level_inventory' === ( $decoded['worktree_status_mode'] ?? '' ), 'JSON report exposes cheap worktree status mode' );

	echo "\n[2] Full status flag is opt-in\n";
	$GLOBALS['__cli_logs'] = array();
	$command->hygiene( array(), array( 'format' => 'json', 'include-worktree-status' => true ) );
	datamachine_code_hygiene_assert( true === ( $ability->last_input['include_worktree_status'] ?? false ), 'CLI forwards explicit worktree status opt-in' );

	echo "\n[3] Human output is summary-first and includes actionable sections\n";
	$GLOBALS['__cli_logs'] = array();
	$command->hygiene( array(), array() );
	datamachine_code_hygiene_assert( 'Workspace hygiene:' === ( $GLOBALS['__cli_logs'][0] ?? '' ), 'human output starts with report heading' );
	datamachine_code_hygiene_assert( in_array( 'table:18:metric,value', $GLOBALS['__cli_logs'], true ), 'human output renders summary table (now includes 4 liveness counts + duplicate_task_groups)' );
	datamachine_code_hygiene_assert( in_array( 'Workspace size by kind:', $GLOBALS['__cli_logs'], true ), 'human output renders size kind grouping' );
	datamachine_code_hygiene_assert( in_array( 'Top workspace entries by size:', $GLOBALS['__cli_logs'], true ), 'human output renders top offenders' );
	datamachine_code_hygiene_assert( in_array( 'Top repos by size:', $GLOBALS['__cli_logs'], true ), 'human output renders size leaders' );
	datamachine_code_hygiene_assert( in_array( 'Top repos by worktree count:', $GLOBALS['__cli_logs'], true ), 'human output renders worktree-count leaders' );
	datamachine_code_hygiene_assert( in_array( 'Cleanup candidates:', $GLOBALS['__cli_logs'], true ), 'human output renders cleanup candidates' );
	datamachine_code_hygiene_assert( in_array( 'Suggested cleanup review:', $GLOBALS['__cli_logs'], true ), 'human output renders cleanup command' );

	echo "\nAll workspace hygiene CLI smoke tests passed.\n";
}
