<?php
/**
 * Regression coverage for the versioned WP Codebox mounted runtime context.
 */

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
require_once dirname(__DIR__) . '/inc/Runtime/MountedSandboxBootstrap.php';

use DataMachineCode\Runtime\MountedSandboxBootstrap;
use DataMachineCode\Support\RuntimeCapabilities;

function mounted_sandbox_codebox_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$method = new ReflectionMethod(MountedSandboxBootstrap::class, 'discover_context');

$GLOBALS['wp_codebox_runtime_context'] = array(
	'schema'  => RuntimeCapabilities::CODEBOX_RUNTIME_CONTEXT_SCHEMA,
	'payload' => array(
		'workspace_root'    => '/tmp/wp-codebox-workspace',
		'sandbox_workspace' => array(
			'root'   => '/tmp/wp-codebox-workspace',
			'mounts' => array(
				array(
					'target'     => '/tmp/wp-codebox-workspace/example',
					'sourceMode' => 'mounted',
				),
			),
		),
	),
);

$context = $method->invoke(null);
unset($GLOBALS['wp_codebox_runtime_context']);

mounted_sandbox_codebox_assert_same(RuntimeCapabilities::CODEBOX_RUNTIME_CONTEXT_SCHEMA, $context['schema'] ?? '', 'Codebox context keeps its versioned schema.');
mounted_sandbox_codebox_assert_same('/tmp/wp-codebox-workspace', $context['workspace_root'] ?? '', 'Codebox payload is unwrapped for workspace discovery.');
mounted_sandbox_codebox_assert_same('/tmp/wp-codebox-workspace', $context['sandbox_workspace']['root'] ?? '', 'Codebox sandbox workspace is preserved.');

$GLOBALS['mounted_runtime_context'] = array( 'workspace_root' => '/tmp/generic-mounted-workspace' );
$context                           = $method->invoke(null);
unset($GLOBALS['mounted_runtime_context']);

mounted_sandbox_codebox_assert_same('/tmp/generic-mounted-workspace', $context['workspace_root'] ?? '', 'Generic mounted runtime global still works.');

$encoded = json_encode(
	array(
		'schema'  => RuntimeCapabilities::CODEBOX_RUNTIME_CONTEXT_SCHEMA,
		'payload' => array( 'workspace_root' => '/tmp/wp-codebox-env-workspace' ),
	)
);
putenv('WP_CODEBOX_RUNTIME_CONTEXT=' . $encoded);

$context = $method->invoke(null);
putenv('WP_CODEBOX_RUNTIME_CONTEXT');

mounted_sandbox_codebox_assert_same('/tmp/wp-codebox-env-workspace', $context['workspace_root'] ?? '', 'Codebox env context is discovered.');

putenv('MOUNTED_RUNTIME_CONTEXT=' . json_encode(array( 'workspace_root' => '/tmp/generic-mounted-env-workspace' )));

$context = $method->invoke(null);
putenv('MOUNTED_RUNTIME_CONTEXT');

mounted_sandbox_codebox_assert_same('/tmp/generic-mounted-env-workspace', $context['workspace_root'] ?? '', 'Generic mounted runtime env still works.');

echo "Mounted sandbox Codebox runtime context regression passed.\n";
