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

require_once dirname(__DIR__) . '/inc/Workspace/RunnerWorkspacePublisher.php';

use DataMachineCode\Workspace\RunnerWorkspacePublisher;

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function resolve_target( array $input, string $handle = 'data-machine-code@fix-publisher' ): array|WP_Error {
	$publisher = new RunnerWorkspacePublisher();
	$method    = new ReflectionMethod($publisher, 'resolve_publication_target');

	return $method->invoke(
		$publisher,
		$input,
		$handle,
		(string) ( $input['target_repo'] ?? 'Extra-Chill/data-machine-code' ),
		(string) ( $input['base'] ?? 'main' )
	);
}

$direct = resolve_target(array( 'head' => 'fix/publisher-targets' ));
assert_same(false, is_wp_error($direct), 'direct target resolves');
assert_same('Extra-Chill/data-machine-code', $direct['base_repo'], 'direct base repo');
assert_same('main', $direct['base_ref'], 'direct base ref');
assert_same('Extra-Chill/data-machine-code', $direct['head_repo'], 'direct head repo');
assert_same('fix/publisher-targets', $direct['head_ref'], 'direct PR head');
assert_same('origin', $direct['push_remote'], 'direct push remote');
assert_same('fix/publisher-targets', $direct['push_branch'], 'direct push branch');

$fork = resolve_target(array( 'head' => 'chubes4:fix/publisher-targets' ));
assert_same(false, is_wp_error($fork), 'fork target resolves');
assert_same('Extra-Chill/data-machine-code', $fork['base_repo'], 'fork base repo');
assert_same('chubes4/data-machine-code', $fork['head_repo'], 'fork head repo');
assert_same('chubes4:fix/publisher-targets', $fork['head_ref'], 'fork PR head preserves owner');
assert_same('chubes4', $fork['push_remote'], 'fork push remote maps to head owner');
assert_same('fix/publisher-targets', $fork['push_branch'], 'fork push branch strips owner');

$explicit_remote = resolve_target(array( 'head' => 'chubes4:fix/publisher-targets', 'remote' => 'fork' ));
assert_same(false, is_wp_error($explicit_remote), 'explicit fork remote resolves');
assert_same('fork', $explicit_remote['push_remote'], 'explicit fork remote is preserved');
assert_same('chubes4:fix/publisher-targets', $explicit_remote['head_ref'], 'explicit remote keeps owner-qualified PR head');

$invalid = resolve_target(array( 'head' => 'chubes4:' ));
assert_same(true, is_wp_error($invalid), 'invalid owner-qualified head is rejected');
assert_same('runner_workspace_publish_invalid_head_branch', $invalid->get_error_code(), 'invalid head error code');

echo "runner-workspace-publisher-target: ok\n";
