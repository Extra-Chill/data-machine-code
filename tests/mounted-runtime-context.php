<?php
/**
 * Regression coverage for the versioned mounted runtime context.
 */

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
require_once dirname(__DIR__) . '/inc/Runtime/MountedRuntimeBootstrap.php';

use DataMachineCode\Runtime\MountedRuntimeBootstrap;
use DataMachineCode\Support\RuntimeCapabilities;

function mounted_runtime_context_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$method = new ReflectionMethod(MountedRuntimeBootstrap::class, 'discover_context');

$GLOBALS['mounted_runtime_context'] = array(
	'schema'  => RuntimeCapabilities::RUNTIME_CONTEXT_SCHEMA,
	'payload' => array(
		'workspace_root'    => '/tmp/mounted-workspace',
		'runtime_workspace' => array(
			'root'   => '/tmp/mounted-workspace',
			'mounts' => array(
				array(
					'target'     => '/tmp/mounted-workspace/example',
					'sourceMode' => 'mounted',
				),
			),
		),
	),
);

$context = $method->invoke(null);
unset($GLOBALS['mounted_runtime_context']);

mounted_runtime_context_assert_same(RuntimeCapabilities::RUNTIME_CONTEXT_SCHEMA, $context['schema'] ?? '', 'Mounted context keeps its versioned schema.');
mounted_runtime_context_assert_same('/tmp/mounted-workspace', $context['workspace_root'] ?? '', 'Mounted payload is unwrapped for workspace discovery.');
mounted_runtime_context_assert_same('/tmp/mounted-workspace', $context['runtime_workspace']['root'] ?? '', 'Runtime workspace is preserved.');
mounted_runtime_context_assert_same('/tmp/mounted-workspace', $context['sandbox_workspace']['root'] ?? '', 'Deprecated sandbox workspace alias is preserved.');

$GLOBALS['mounted_runtime_context'] = array(
	'schema'  => RuntimeCapabilities::RUNTIME_CONTEXT_SCHEMA,
	'payload' => array(
		'sandbox_workspace' => array(
			'root' => '/tmp/legacy-mounted-workspace',
		),
	),
);

$context = $method->invoke(null);
unset($GLOBALS['mounted_runtime_context']);

mounted_runtime_context_assert_same('/tmp/legacy-mounted-workspace', $context['runtime_workspace']['root'] ?? '', 'Deprecated sandbox workspace input is normalized to runtime workspace.');

$GLOBALS['wordpress_runtime_context'] = array( 'workspace_root' => '/tmp/generic-wordpress-workspace' );
$context                              = $method->invoke(null);
unset($GLOBALS['wordpress_runtime_context']);

mounted_runtime_context_assert_same('/tmp/generic-wordpress-workspace', $context['workspace_root'] ?? '', 'Generic WordPress runtime global works.');

$encoded = json_encode(
	array(
		'schema'  => RuntimeCapabilities::RUNTIME_CONTEXT_SCHEMA,
		'payload' => array( 'workspace_root' => '/tmp/mounted-env-workspace' ),
	)
);
putenv('MOUNTED_RUNTIME_CONTEXT=' . $encoded);

$context = $method->invoke(null);
putenv('MOUNTED_RUNTIME_CONTEXT');

mounted_runtime_context_assert_same('/tmp/mounted-env-workspace', $context['workspace_root'] ?? '', 'Mounted env context is discovered.');

putenv('WORDPRESS_RUNTIME_CONTEXT=' . json_encode(array( 'workspace_root' => '/tmp/generic-wordpress-env-workspace' )));

$context = $method->invoke(null);
putenv('WORDPRESS_RUNTIME_CONTEXT');

mounted_runtime_context_assert_same('/tmp/generic-wordpress-env-workspace', $context['workspace_root'] ?? '', 'Generic WordPress env context works.');

echo "Mounted runtime context regression passed.\n";
