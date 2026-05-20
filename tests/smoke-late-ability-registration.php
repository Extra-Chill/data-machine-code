<?php
/**
 * Smoke test for ability classes instantiated after wp_abilities_api_init.
 *
 * Run: php tests/smoke-late-ability-registration.php
 */

declare( strict_types=1 );

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public const ARTIFACT_CLEANUP_DEFAULT_LIMIT = 100;
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/data-machine-code-late-ability-registration/' );
	}

	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {}
	}

	$GLOBALS['datamachine_code_registered_abilities'] = array();
	$GLOBALS['datamachine_code_added_actions']        = array();

	function wp_register_ability( string $name, array $definition ): void {
		$GLOBALS['datamachine_code_registered_abilities'][ $name ] = $definition;
	}

	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook;
	}

	function did_action( string $hook ): int {
		return 'wp_abilities_api_init' === $hook ? 1 : 0;
	}

	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		$GLOBALS['datamachine_code_added_actions'][] = compact( 'hook', 'callback', 'priority' );
	}

	require __DIR__ . '/../inc/Abilities/WorkspaceAbilities.php';
	require __DIR__ . '/../inc/Abilities/CodeTaskAbilities.php';

	new \DataMachineCode\Abilities\WorkspaceAbilities();
	new \DataMachineCode\Abilities\CodeTaskAbilities();

	$failures = array();
	$assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Data Machine Code late ability registration - smoke\n";

	$assert( 'workspace-show registers after wp_abilities_api_init fired', isset( $GLOBALS['datamachine_code_registered_abilities']['datamachine/workspace-show'] ) );
	$assert( 'workspace-worktree-add registers after wp_abilities_api_init fired', isset( $GLOBALS['datamachine_code_registered_abilities']['datamachine/workspace-worktree-add'] ) );
	$assert( 'create-code-task registers after wp_abilities_api_init fired', isset( $GLOBALS['datamachine_code_registered_abilities']['datamachine/create-code-task'] ) );
	$assert( 'late constructors do not add inert wp_abilities_api_init callbacks', array() === $GLOBALS['datamachine_code_added_actions'] );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s) failed\n";
		exit( 1 );
	}

	echo "\nOK\n";
}
