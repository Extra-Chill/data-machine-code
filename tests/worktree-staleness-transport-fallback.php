<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeStalenessProbe.php';

use DataMachineCode\Workspace\WorktreeStalenessProbe;

function transport_fallback_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}

$calls = array();
$result = WorktreeStalenessProbe::fetch(
	'/repo',
	static function ( string $path, string $args, int $timeout ) use ( &$calls ): array|WP_Error {
		$calls[] = array( $path, $args, $timeout );
		return 1 === count($calls) ? new WP_Error('git_command_failed', 'configured transport failed', array( 'output' => 'configured transport failed' )) : array( 'success' => true, 'output' => '' );
	},
	null
);
transport_fallback_assert_same(
	array(
		array( '/repo', 'fetch --quiet origin', 5 ),
		array( '/repo', 'fetch --quiet origin', 5 ),
	),
	$calls,
	'Freshness verification must retry only through the registered remote transport.'
);
transport_fallback_assert_same(array( 'configured', 'configured' ), $result['attempted_transports'] ?? null, 'Both attempts must retain the configured transport.');
transport_fallback_assert_same('configured', $result['successful_transport'] ?? null, 'The registered transport must be reported.');
transport_fallback_assert_same(false, $result['transport_fallback_used'] ?? null, 'Transport fallback must never be inferred.');

$calls = array();
$failed = WorktreeStalenessProbe::fetch(
	'/repo',
	static function ( string $path, string $args ) use ( &$calls ): WP_Error {
		$calls[] = array( $path, $args );
		return new WP_Error('git_command_failed', 'fetch failed', array( 'output' => 'fetch failed' ));
	},
	null
);
transport_fallback_assert_same(array( array( '/repo', 'fetch --quiet origin' ), array( '/repo', 'fetch --quiet origin' ) ), $calls, 'Failed verification must not synthesize a different transport.');
transport_fallback_assert_same(false, $failed['transport_fallback_used'] ?? null, 'Failed verification must preserve configured transport only.');

$expired = WorktreeStalenessProbe::fetch(
	'/repo',
	static function (): never {
		throw new RuntimeException('Expired freshness verification must not invoke Git.');
	},
	microtime(true) - 1
);
transport_fallback_assert_same(array(), $expired['attempted_transports'], 'Deadline expiry before the first fetch must return an empty transport evidence list.');

fwrite(STDOUT, "worktree-staleness-transport-fallback: ok\n");
