<?php

declare(strict_types=1);

namespace DataMachineCode\Abilities {
	final class AbilityRegistry {
		public static array $registered = array();
		public static function when_ready( callable $register ): void { $register(); }
		public static function register( string $name, array $definition ): void { self::$registered[ $name ] = $definition; }
	}
	final class GitHubAbilities {}
}

namespace DataMachineCode\Support {
	final class RuntimeCapabilities {}
}

namespace DataMachineCode\Workspace {
	final class RunnerWorkspacePublisher {}
	final class WorktreeContextInjector {
		public const VALID_CLEANUP_POLICIES = array( 'manual', 'remove_on_success', 'preserve_on_failure' );
	}

	final class Workspace {
		public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
		public const MAX_READ_SIZE = 1048576;
		public function worktree_active_no_signal_report( array $opts ): array { return array( 'variant' => 'report', 'opts' => $opts ); }
		public function worktree_active_no_signal_finalized_apply( array $opts ): array { return array( 'variant' => 'finalized', 'opts' => $opts ); }
		public function worktree_active_no_signal_equivalent_clean_apply( array $opts ): array { return array( 'variant' => 'equivalent_clean', 'opts' => $opts ); }
		public function worktree_active_no_signal_merged_apply( array $opts ): array { return array( 'variant' => 'merged', 'opts' => $opts ); }
		public function worktree_active_no_signal_remote_clean_apply( array $opts ): array { return array( 'variant' => 'remote_clean', 'opts' => $opts ); }
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	function wp_register_ability(): void {}
	function doing_action( string $action ): bool { return 'wp_abilities_api_init' === $action; }

	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	}

	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

	use DataMachineCode\Abilities\WorkspaceAbilities;
	use DataMachineCode\Abilities\AbilityRegistry;

	function active_no_signal_ability_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	$input = array(
		'dry_run'     => true,
		'limit'       => '25',
		'offset'      => '7',
		'until_budget' => ' 60s ',
		'repo'        => ' repo ',
	);
	$options = array( 'dry_run' => true, 'limit' => 25, 'offset' => 7, 'until_budget' => '60s', 'repo' => 'repo' );

	$operations = array(
		'finalized'        => 'worktreeActiveNoSignalFinalizedApply',
		'equivalent_clean' => 'worktreeActiveNoSignalEquivalentCleanApply',
		'merged'           => 'worktreeActiveNoSignalMergedApply',
		'remote_clean'     => 'worktreeActiveNoSignalRemoteCleanApply',
	);
	foreach ( $operations as $variant => $method ) {
		$result = WorkspaceAbilities::$method($input);
		active_no_signal_ability_assert_same($variant, $result['variant'], $method . ' preserves its workspace operation');
		active_no_signal_ability_assert_same($options, $result['opts'], $method . ' preserves normalized options');
	}

	$report = WorkspaceAbilities::worktreeActiveNoSignalReport($input);
	active_no_signal_ability_assert_same('report', $report['variant'], 'Report preserves its workspace operation');
	$report_options = $options;
	unset($report_options['dry_run']);
	active_no_signal_ability_assert_same($report_options, $report['opts'], 'Report shares bounded options without apply-only dry_run');

	new WorkspaceAbilities();
	$ability_contracts = array(
		'finalized'        => array( 'Promote Finalized Active Worktrees', 'worktreeActiveNoSignalFinalizedApply', 'Positive maximum' ),
		'equivalent-clean' => array( 'Promote Equivalent Clean Active Worktrees', 'worktreeActiveNoSignalEquivalentCleanApply', 'Positive maximum' ),
		'merged'           => array( 'Promote Merged Active Worktrees', 'worktreeActiveNoSignalMergedApply', 'Maximum' ),
		'remote-clean'     => array( 'Promote Clean Remote Active Worktrees', 'worktreeActiveNoSignalRemoteCleanApply', 'Maximum' ),
	);
	foreach ( $ability_contracts as $classification => $contract ) {
		$ability = AbilityRegistry::$registered[ 'datamachine-code/workspace-worktree-active-no-signal-' . $classification . '-apply' ] ?? null;
		active_no_signal_ability_assert_same(true, is_array($ability), $classification . ' ability remains registered');
		active_no_signal_ability_assert_same($contract[0], $ability['label'], $classification . ' label remains compatible');
		active_no_signal_ability_assert_same(array( WorkspaceAbilities::class, $contract[1] ), $ability['execute_callback'], $classification . ' callback remains compatible');
		active_no_signal_ability_assert_same(array( 'dry_run', 'limit', 'offset', 'until_budget', 'repo' ), array_keys($ability['input_schema']['properties']), $classification . ' input schema remains compatible');
		active_no_signal_ability_assert_same(array( 'success', 'dry_run', 'planned', 'written', 'skipped', 'summary' ), array_keys($ability['output_schema']['properties']), $classification . ' output schema remains compatible');
		active_no_signal_ability_assert_same(true, str_starts_with($ability['input_schema']['properties']['limit']['description'], $contract[2]), $classification . ' limit description remains compatible');
		active_no_signal_ability_assert_same(false, $ability['meta']['show_in_rest'], $classification . ' REST visibility remains compatible');
	}

	echo "active-no-signal-ability-routing: ok\n";
}
