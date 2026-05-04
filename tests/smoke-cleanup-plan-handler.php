<?php
/**
 * Smoke test for cleanup plan handler registration surface.
 *
 * Run: php tests/smoke-cleanup-plan-handler.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\Steps\Fetch\Handlers {
	if ( ! class_exists( __NAMESPACE__ . '\\FetchHandler' ) ) {
		abstract class FetchHandler {
			public function __construct( string $handler_type ) {}
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\FetchHandlerSettings' ) ) {
		abstract class FetchHandlerSettings {}
	}
}

namespace DataMachine\Core\Steps {
	if ( ! trait_exists( __NAMESPACE__ . '\\HandlerRegistrationTrait' ) ) {
		trait HandlerRegistrationTrait {
			protected static function registerHandler( string $slug, string $type, string $class_name, string $label, string $description, bool $requiresAuth = false, ?string $authClass = null, ?string $settingsClass = null, ?callable $aiToolCallback = null, ?string $authProviderKey = null, array $meta = array() ): void {
				$GLOBALS['datamachine_code_test_registered_handlers'][ $slug ] = compact( 'slug', 'type', 'class_name', 'label', 'description', 'requiresAuth', 'authClass', 'settingsClass', 'meta' );
			}
		}
	}
}

namespace DataMachine\Core {
	if ( ! class_exists( __NAMESPACE__ . '\\ExecutionContext' ) ) {
		class ExecutionContext {}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $text;
		}
	}

	require __DIR__ . '/../inc/Handlers/Workspace/CleanupPlanSettings.php';
	require __DIR__ . '/../inc/Handlers/Workspace/CleanupPlan.php';

	$failures = 0;
	$total    = 0;
	$assert   = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $expected === $actual ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	echo "=== smoke-cleanup-plan-handler ===\n";

	new \DataMachineCode\Handlers\Workspace\CleanupPlan();
	$registered = $GLOBALS['datamachine_code_test_registered_handlers']['workspace_cleanup_plan'] ?? array();
	$assert( 'fetch', $registered['type'] ?? '', 'cleanup plan handler registers as fetch handler' );
	$assert( \DataMachineCode\Handlers\Workspace\CleanupPlanSettings::class, $registered['settingsClass'] ?? '', 'cleanup plan handler registers settings class' );

	$options = \DataMachineCode\Handlers\Workspace\CleanupPlan::normalizeOptions(
		array(
			'emit_chunks'         => true,
			'chunk_size'          => '3',
			'include_artifacts'   => false,
			'include_resolvers'   => true,
			'worktree_older_than' => '14d',
		)
	);
	$assert( true, $options['emit_chunks'] ?? false, 'handler options preserve chunk emission flag' );
	$assert( 3, $options['chunk_size'] ?? 0, 'handler options normalize chunk size' );
	$assert( false, $options['include_artifacts'] ?? true, 'handler options preserve include toggles' );
	$assert( true, $options['include_resolvers'] ?? false, 'handler options preserve resolver flag' );

	$fields = \DataMachineCode\Handlers\Workspace\CleanupPlanSettings::get_fields();
	$assert( true, isset( $fields['chunk_size'] ), 'handler settings expose chunk_size' );
	$assert( true, isset( $fields['include_resolvers'] ), 'handler settings expose resolver rows toggle' );

	if ( $failures > 0 ) {
		echo "\nFAILURES: {$failures}/{$total}\n";
		exit( 1 );
	}

	echo "\nAll {$total} cleanup plan handler smoke assertions passed.\n";
}
