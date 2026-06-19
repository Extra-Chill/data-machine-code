<?php
/**
 * Regression test for native runtimes where /workspace is outside open_basedir.
 */

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
require_once dirname(__DIR__) . '/inc/Runtime/MountedSandboxBootstrap.php';

$warnings = array();
set_error_handler(
	static function (int $severity, string $message) use (&$warnings): bool {
		if (str_contains($message, 'open_basedir')) {
			$warnings[] = $message;
		}

		return true;
	}
);

$method = new ReflectionMethod(DataMachineCode\Runtime\MountedSandboxBootstrap::class, 'discover_context');
$method->setAccessible(true);
$context = $method->invoke(null);

restore_error_handler();

if (array() !== $warnings) {
	fwrite(STDERR, implode(PHP_EOL, $warnings) . PHP_EOL);
	exit(1);
}

if (array() !== $context) {
	fwrite(STDERR, 'Expected no mounted sandbox context when /workspace is outside open_basedir.' . PHP_EOL);
	exit(1);
}

echo "Mounted sandbox open_basedir regression passed.\n";
