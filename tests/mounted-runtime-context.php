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
mounted_runtime_context_assert_same(false, array_key_exists('sandbox_' . 'workspace', $context), 'Mounted runtime context does not emit the deprecated workspace alias.');
mounted_runtime_context_assert_same(false, class_exists('DataMachineCode\\Runtime\\Mounted' . 'SandboxBootstrap'), 'Deprecated mounted sandbox bootstrap alias is not registered.');

$method = new ReflectionMethod(MountedRuntimeBootstrap::class, 'workspace_mounts');

$mounts = $method->invoke(
	null,
	array(
		'runtime_workspace' => array(
			'root'   => '/tmp/mounted-workspace',
			'mounts' => array(
				array(
					'target'       => '/tmp/mounted-workspace/acme-builder',
					'sourceMode'   => 'mounted',
					'workspaceRef' => 'acme-builder@fixture-proof-20260622-2102',
				),
			),
		),
	),
	'/tmp/mounted-workspace'
);

mounted_runtime_context_assert_same('acme-builder@fixture-proof-20260622-2102', $mounts[0]['workspace_ref'] ?? '', 'Mounted workspace adoption preserves the full worktree handle.');

$context_property = new ReflectionProperty(MountedRuntimeBootstrap::class, 'context');
$context_property->setValue(
	null,
	array(
		'workspace_root'    => '/tmp/mounted-workspace',
		'runtime_workspace' => array(
			'root'   => '/tmp/mounted-workspace',
			'mounts' => array(
				array(
					'target'       => '/tmp/mounted-workspace/wp-site-generator',
					'sourceMode'   => 'mounted',
					'workspaceRef' => 'wp-site-generator@wpsg-lab-proof-20260622-2102',
				),
			),
		),
	)
);

$aliases = MountedRuntimeBootstrap::mounted_workspace_path_aliases();
mounted_runtime_context_assert_same('wp-site-generator@wpsg-lab-proof-20260622-2102', $aliases['/tmp/mounted-workspace/wp-site-generator'] ?? '', 'Mounted runtime exposes target path aliases to full workspace refs.');

$method = new ReflectionMethod(MountedRuntimeBootstrap::class, 'discover_context');

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

$legacy_global_key              = 'legacy_vendor_runtime_context';
$legacy_env_key                 = 'LEGACY_VENDOR_RUNTIME_CONTEXT';
$legacy_schema                  = 'legacy-vendor/runtime-context/v1';
$GLOBALS[ $legacy_global_key ]  = array( 'workspace_root' => '/tmp/legacy-global-workspace' );
$generic_fallback_env_context   = json_encode(array( 'workspace_root' => '/tmp/generic-fallback-workspace' ));
putenv('MOUNTED_RUNTIME_CONTEXT=' . $generic_fallback_env_context);

$context = $method->invoke(null);
unset($GLOBALS[ $legacy_global_key ]);
putenv('MOUNTED_RUNTIME_CONTEXT');

mounted_runtime_context_assert_same('/tmp/generic-fallback-workspace', $context['workspace_root'] ?? '', 'Legacy vendor global is ignored in favor of generic runtime context.');

putenv($legacy_env_key . '=' . json_encode(array( 'workspace_root' => '/tmp/legacy-env-workspace' )));
putenv('MOUNTED_RUNTIME_WORKSPACE_ROOT=/tmp/generic-env-fallback-workspace');

$context = $method->invoke(null);
putenv($legacy_env_key);
putenv('MOUNTED_RUNTIME_WORKSPACE_ROOT');

mounted_runtime_context_assert_same('/tmp/generic-env-fallback-workspace', $context['workspace_root'] ?? '', 'Legacy vendor env context is ignored in favor of generic runtime context.');

$GLOBALS['mounted_runtime_context'] = array(
	'schema'  => $legacy_schema,
	'payload' => array( 'workspace_root' => '/tmp/legacy-schema-payload-workspace' ),
);

$context = $method->invoke(null);
unset($GLOBALS['mounted_runtime_context']);

mounted_runtime_context_assert_same('', $context['workspace_root'] ?? '', 'Unsupported legacy vendor schema payload is not unwrapped.');

$runtime_support_files = array(
	dirname(__DIR__) . '/inc/Runtime/MountedRuntimeBootstrap.php',
	dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php',
	dirname(__DIR__) . '/README.md',
);
$forbidden_runtime_tokens = array(
	$legacy_global_key,
	$legacy_env_key,
	'ACME_RUNNER_RUNTIME_CONTEXT',
	'acme_runner_runtime_context',
	'acme-runner/runtime-context/v1',
	'ACME_RUNNER_RUNTIME_CONTEXT_SCHEMA',
	'Acme Runner',
	'acme-runner',
	'ACME_RUNNER',
	$legacy_schema,
	'sandbox_' . 'workspace',
	'Mounted' . 'SandboxBootstrap',
);

foreach ( $runtime_support_files as $runtime_support_file ) {
	$contents = file_get_contents($runtime_support_file);
	foreach ( $forbidden_runtime_tokens as $forbidden_runtime_token ) {
		if ( false !== strpos((string) $contents, $forbidden_runtime_token) ) {
			throw new RuntimeException(sprintf('Forbidden runtime token %s found in %s.', $forbidden_runtime_token, $runtime_support_file));
		}
	}
}

echo "Mounted runtime context regression passed.\n";
