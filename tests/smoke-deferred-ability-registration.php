<?php
/**
 * Smoke test for ability classes instantiated before WP_Ability is loaded.
 *
 * Run: php tests/smoke-deferred-ability-registration.php
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
		define( 'ABSPATH', sys_get_temp_dir() . '/data-machine-code-deferred-ability-registration/' );
	}

	$GLOBALS['datamachine_code_registered_abilities'] = array();
	$GLOBALS['datamachine_code_added_actions']        = array();

	function wp_register_ability( string $name, array $definition ): void {
		$GLOBALS['datamachine_code_registered_abilities'][ $name ] = $definition;
	}

	function doing_action( string $hook ): bool {
		return false;
	}

	function did_action( string $hook ): int {
		return 0;
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

	echo "Data Machine Code deferred ability registration - smoke\n";

	$assert( 'constructors defer when WP_Ability is unavailable', 2 === count( $GLOBALS['datamachine_code_added_actions'] ) );
	$assert( 'constructors do not register before WP_Ability is available', array() === $GLOBALS['datamachine_code_registered_abilities'] );

	class WP_Ability {}

	foreach ( $GLOBALS['datamachine_code_added_actions'] as $action ) {
		if ( 'wp_abilities_api_init' === $action['hook'] ) {
			$action['callback']();
		}
	}

	$assert( 'workspace-show registers on deferred wp_abilities_api_init', isset( $GLOBALS['datamachine_code_registered_abilities']['datamachine/workspace-show'] ) );
	$assert( 'workspace-worktree-add registers on deferred wp_abilities_api_init', isset( $GLOBALS['datamachine_code_registered_abilities']['datamachine/workspace-worktree-add'] ) );
	$assert( 'create-code-task registers on deferred wp_abilities_api_init', isset( $GLOBALS['datamachine_code_registered_abilities']['datamachine/create-code-task'] ) );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s) failed\n";
		exit( 1 );
	}

	echo "\nOK\n";
}
