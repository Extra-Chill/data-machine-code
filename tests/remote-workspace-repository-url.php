<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct( public string $code, public string $message, public array $data = array() ) {}
		}
	}
}

namespace DataMachineCode\Workspace {
	class WorkspacePolicy {}
}

namespace {
	require_once dirname(__DIR__) . '/inc/Support/GitHubRemote.php';
	require_once dirname(__DIR__) . '/inc/Workspace/RemoteWorkspaceBackend.php';

	use DataMachineCode\Workspace\RemoteWorkspaceBackend;

	function remote_workspace_url_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	$backend = ( new ReflectionClass(RemoteWorkspaceBackend::class) )->newInstanceWithoutConstructor();
	$method  = new ReflectionMethod($backend, 'repo_from_url');

	remote_workspace_url_assert_same(
		'Extra-Chill/data-machine-code',
		$method->invoke($backend, 'https://github.com/Extra-Chill/data-machine-code.git'),
		'Public GitHub repository URLs should parse.'
	);
	remote_workspace_url_assert_same(
		'example/project',
		$method->invoke($backend, 'https://github.example.com/example/project.git'),
		'GitHub Enterprise HTTPS repository URLs should parse.'
	);
	remote_workspace_url_assert_same(
		'example/project',
		$method->invoke($backend, 'git@github.example.com:example/project.git'),
		'GitHub Enterprise SSH repository URLs should parse.'
	);

	$unsupported = $method->invoke($backend, 'https://gitlab.example.com/example/project.git');
	remote_workspace_url_assert_same('unsupported_remote_workspace_url', $unsupported->code ?? null, 'Unsupported repository hosts should remain rejected.');

	echo "Remote workspace repository URL tests passed.\n";
}
