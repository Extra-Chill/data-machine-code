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

function staleness_timeout_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}

$calls = array();
$result = WorktreeStalenessProbe::fetch(
	'/repo',
	static function ( string $path, string $args, int $timeout ) use ( &$calls ): WP_Error {
		$calls[] = array( $path, $args, $timeout );
		return new WP_Error('git_command_timeout', 'Process command timed out after 5 second(s).', array( 'timeout' => 5, 'output' => 'fatal: could not read https://user:secret@example.test/repo?access_token=secret-token' ));
	}
);

staleness_timeout_assert_same(array( array( '/repo', 'fetch --quiet origin', 5 ), array( '/repo', 'fetch --quiet origin', 5 ) ), $calls, 'Freshness fetch must retry once within the bounded GitRunner timeout contract.');
staleness_timeout_assert_same(false, $result['ok'], 'Timed-out freshness fetch must fail verification.');
staleness_timeout_assert_same(2, $result['attempts'] ?? null, 'Timed-out freshness fetch must report the bounded retry count.');
staleness_timeout_assert_same(true, $result['timed_out'] ?? null, 'Timed-out freshness fetch must remain distinct from stale evidence.');
staleness_timeout_assert_same(5, $result['timeout_seconds'] ?? null, 'Timed-out freshness fetch must surface its budget.');
staleness_timeout_assert_same(true, str_contains((string) ( $result['error'] ?? '' ), 'allow_unverified_freshness=true'), 'Timed-out freshness fetch must provide an actionable opt-in diagnostic.');
staleness_timeout_assert_same(false, str_contains((string) ( $result['error'] ?? '' ), 'user:secret'), 'Timed-out freshness fetch leaked URL credentials.');
staleness_timeout_assert_same(false, str_contains((string) ( $result['error'] ?? '' ), 'secret-token'), 'Timed-out freshness fetch leaked query credentials.');
staleness_timeout_assert_same(true, str_contains((string) ( $result['error'] ?? '' ), 'https://***@example.test/repo?access_token=***'), 'Timed-out freshness fetch did not retain a sanitized remote diagnostic.');

$calls = array();
$behind = WorktreeStalenessProbe::behind_count(
	'/repo',
	'feature',
	'origin/main',
	7,
	static function ( string $path, string $args, int $timeout ) use ( &$calls ): WP_Error {
		$calls[] = array( $path, $args, $timeout );
		return new WP_Error('git_command_timeout', 'Process command timed out after 7 second(s).', array( 'timeout' => 7 ));
	}
);
staleness_timeout_assert_same(array( array( '/repo', "rev-list --count 'feature'..'origin/main'", 7 ) ), $calls, 'Behind-count probes must use their caller-provided bounded GitRunner timeout.');
staleness_timeout_assert_same('git_command_timeout', $behind instanceof WP_Error ? $behind->get_error_code() : null, 'Timed-out behind-count probes must preserve the Git timeout type.');

fwrite(STDOUT, "worktree-staleness-fetch-timeout: ok\n");
