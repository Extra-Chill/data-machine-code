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

function wp_mkdir_p( string $path ): bool {
	return is_dir($path) || mkdir($path, 0777, true);
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode($value, $flags, $depth);
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	$filters = $GLOBALS['datamachine_code_projection_test_filters'][ $hook_name ] ?? array();
	foreach ( $filters as $filter ) {
		$value = $filter($value, ...$args);
	}
	return $value;
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';

use DataMachineCode\Workspace\WorktreeContextInjector;

function remove_tree( string $path ): void {
	if ( ! file_exists($path) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$temp_root = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), '/') . '/datamachine-code-context-projection-' . getmypid();
remove_tree($temp_root);
mkdir($temp_root, 0777, true);

try {
	$GLOBALS['datamachine_code_projection_test_filters'] = array(
		WorktreeContextInjector::PROJECTION_TARGETS_FILTER => array(
			static function ( array $targets ): array {
				$targets = array();
				$targets['custom_context'] = array(
					'path'     => '.custom/context.md',
					'renderer' => static fn( array $payload ): string => 'custom:' . (string) ( $payload['site_name'] ?? '' ),
					'exclude'  => true,
				);
				return $targets;
			},
		),
		WorktreeContextInjector::PROJECTION_CLEANUP_FILTER => array(
			static function ( array $cleanup ): array {
				return array(
					'custom_context' => array(
						'paths' => array( '.custom/context.md' ),
					),
				);
			},
		),
	);

	$result = WorktreeContextInjector::inject(
		$temp_root,
		array(
			'site_name' => 'Projection Test',
			'files'     => array(),
		)
	);
	assert_true(! is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'inject failed');
	assert_true(is_file($temp_root . '/.custom/context.md'), 'custom projection was not written');
	assert_true('custom:Projection Test' === trim((string) file_get_contents($temp_root . '/.custom/context.md')), 'custom renderer output mismatch');
	assert_true(! is_file($temp_root . '/.claude/CLAUDE.local.md'), 'default Claude projection was not replaceable');

	$removed = WorktreeContextInjector::uninject($temp_root);
	assert_true(! is_file($temp_root . '/.custom/context.md'), 'custom cleanup path was not removed');
	assert_true(in_array($temp_root . '/.custom/context.md', $removed['removed'], true), 'custom cleanup did not report removed path');
} finally {
	remove_tree($temp_root);
}

echo "worktree-context-projection-registry ok\n";
