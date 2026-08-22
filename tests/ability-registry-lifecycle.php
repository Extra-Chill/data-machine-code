<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');

$GLOBALS['ability_lifecycle_doing'] = false;
$GLOBALS['ability_lifecycle_did'] = 0;
$GLOBALS['ability_lifecycle_callbacks'] = array();

function wp_register_ability( string $name, array $args ): void {}
function doing_action( string $hook ): bool { return 'wp_abilities_api_init' === $hook && $GLOBALS['ability_lifecycle_doing']; }
function did_action( string $hook ): int { return 'wp_abilities_api_init' === $hook ? $GLOBALS['ability_lifecycle_did'] : 0; }
function add_action( string $hook, callable $callback ): void { $GLOBALS['ability_lifecycle_callbacks'][ $hook ][] = $callback; }

function ability_lifecycle_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
	}
}

require_once dirname(__DIR__) . '/inc/Abilities/AbilityRegistry.php';

$calls = 0;
\DataMachineCode\Abilities\AbilityRegistry::when_ready(static function () use ( &$calls ): void { ++$calls; });
ability_lifecycle_assert_same(0, $calls, 'Registration ran before the abilities lifecycle window.');
ability_lifecycle_assert_same(1, count($GLOBALS['ability_lifecycle_callbacks']['wp_abilities_api_init'] ?? array()), 'Registration was not deferred to the abilities lifecycle window.');

$GLOBALS['ability_lifecycle_doing'] = true;
foreach ( $GLOBALS['ability_lifecycle_callbacks']['wp_abilities_api_init'] as $callback ) {
	$callback();
}
ability_lifecycle_assert_same(1, $calls, 'Deferred registration did not run during the abilities lifecycle window.');

\DataMachineCode\Abilities\AbilityRegistry::when_ready(static function () use ( &$calls ): void { ++$calls; });
ability_lifecycle_assert_same(2, $calls, 'Registration did not run immediately inside the abilities lifecycle window.');

$GLOBALS['ability_lifecycle_doing'] = false;
$GLOBALS['ability_lifecycle_did'] = 1;
\DataMachineCode\Abilities\AbilityRegistry::when_ready(static function () use ( &$calls ): void { ++$calls; });
ability_lifecycle_assert_same(2, $calls, 'Registration ran after the abilities lifecycle window had closed.');
ability_lifecycle_assert_same(1, count($GLOBALS['ability_lifecycle_callbacks']['wp_abilities_api_init'] ?? array()), 'Late registration queued an unreachable callback.');

echo "ability-registry-lifecycle: ok\n";
