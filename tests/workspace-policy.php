<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

final class WP_Error {
	private string $code;
	private string $message;
	private mixed $data;

	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	return $value;
}

$GLOBALS['datamachine_code_test_options'] = array();
function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['datamachine_code_test_options'][ $name ] ?? $default;
}

require_once dirname(__DIR__) . '/inc/Support/PathSecurity.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspacePolicy.php';

use DataMachineCode\Workspace\WorkspacePolicy;

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$policy = new WorkspacePolicy();

$GLOBALS['datamachine_code_test_options']['datamachine_workspace_git_policies'] = array(
	'repos' => array(
		'example' => array(
			'writable_roots' => array( '/src/', 'docs\\guides', '', 'src' ),
		),
		'legacy'  => array(
			'allowed_paths' => array( 'legacy' ),
		),
	),
);

assert_true(
	array( 'src', 'docs/guides' ) === $policy->writable_roots_for_repo('example'),
	'writable_roots should be normalized and de-duplicated.'
);

assert_true(
	array( 'legacy' ) === $policy->writable_roots_for_repo('legacy'),
	'allowed_paths should remain the fallback writable-root policy.'
);

assert_true(
	true === $policy->assert_paths_writable('example', array( 'src/File.php', '/docs/guides/index.md' )),
	'Paths under writable_roots should pass.'
);

$blocked = $policy->assert_paths_writable('example', array( 'README.md', 'other/file.php' ));
assert_true(is_wp_error($blocked), 'Paths outside writable_roots should fail.');
assert_true('path_not_allowed' === $blocked->get_error_code(), 'Blocked paths should use the existing error code.');

$data = $blocked->get_error_data();
assert_true(
	is_array($data) && array( 'README.md', 'other/file.php' ) === $data['rejected_paths'],
	'Blocked paths should report normalized rejected paths.'
);

echo "workspace-policy ok\n";
