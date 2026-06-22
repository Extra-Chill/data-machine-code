<?php
/**
 * Regression coverage for mounted workspace path aliases.
 */

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
require_once dirname(__DIR__) . '/inc/Runtime/MountedRuntimeBootstrap.php';
require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

use DataMachineCode\Abilities\WorkspaceAbilities;
use DataMachineCode\Runtime\MountedRuntimeBootstrap;

$context_property = new ReflectionProperty(MountedRuntimeBootstrap::class, 'context');
$context_property->setValue(
	null,
	array(
		'workspace_root'    => '/workspace',
		'runtime_workspace' => array(
			'root'   => '/workspace',
			'mounts' => array(
				array(
					'target'       => '/workspace/wp-site-generator',
					'sourceMode'   => 'repo-backed',
					'workspaceRef' => 'wp-site-generator@proof-worktree',
				),
			),
		),
	)
);

$method = new ReflectionMethod(WorkspaceAbilities::class, 'split_workspace_root_path');
$result = $method->invoke(null, '/workspace/wp-site-generator/static-sites/example/index.html', '/workspace');

if ( array( 'repo' => 'wp-site-generator@proof-worktree', 'path' => 'static-sites/example/index.html' ) !== $result ) {
	throw new RuntimeException(sprintf('Mounted path alias was not resolved to workspaceRef. Got %s.', var_export($result, true)));
}

echo "Mounted workspace path alias regression passed.\n";
