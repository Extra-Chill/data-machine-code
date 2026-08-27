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

function staleness_retry_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}

$calls = 0;
$recovered = WorktreeStalenessProbe::fetch(
	'/repo',
	static function () use ( &$calls ): array|WP_Error {
		++$calls;
		return 1 === $calls ? new WP_Error('git_command_failed', 'fetch failed', array( 'output' => 'temporary DNS failure' )) : array( 'output' => '' );
	}
);
staleness_retry_assert_same(2, $calls, 'A transient fetch failure must be retried once.');
staleness_retry_assert_same(array( 'ok' => true, 'attempts' => 2, 'attempted_transports' => array( 'configured', 'configured' ), 'successful_transport' => 'configured', 'transport_fallback_used' => false ), $recovered, 'A successful retry must verify freshness.');

$calls = 0;
$failed = WorktreeStalenessProbe::fetch(
	'/repo',
	static function () use ( &$calls ): WP_Error {
		++$calls;
		return new WP_Error('git_command_failed', 'fetch failed', array( 'output' => 'fatal: unable to access origin' ));
	}
);
staleness_retry_assert_same(2, $calls, 'Persistent fetch failures must remain bounded to two attempts.');
staleness_retry_assert_same(false, $failed['ok'], 'Persistent fetch failures must fail freshness verification.');
staleness_retry_assert_same(2, $failed['attempts'], 'Persistent fetch failures must report the retry count.');
staleness_retry_assert_same('fatal: unable to access origin', $failed['error'], 'Persistent fetch failures must expose Git stderr.');

$missing = WorktreeStalenessProbe::fetch(
	'/repo',
	static fn(): WP_Error => new WP_Error('git_command_failed', 'fetch failed', array( 'output' => "fatal: couldn't find remote ref refs/heads/not-a-ref" )),
	null,
	null,
	'origin/not-a-ref'
);
staleness_retry_assert_same(true, $missing['missing_remote_ref'] ?? false, 'A targeted missing remote ref must not be classified as a transport failure.');
staleness_retry_assert_same('origin/not-a-ref', $missing['remote_ref'] ?? null, 'Missing-ref evidence must retain the requested ref.');

fwrite(STDOUT, "worktree-staleness-fetch-retry: ok\n");
