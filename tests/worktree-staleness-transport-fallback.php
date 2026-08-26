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

$fallback = WorktreeStalenessProbe::equivalent_transport_fallback_for_remote(
	'https://github.com/Extra-Chill/data-machine-code.git',
	array( 'ready' => true, 'code' => 'ssh_agent_ready' )
);
transport_fallback_assert_same('https', $fallback['configured_transport'] ?? null, 'HTTPS must remain the configured transport.');
transport_fallback_assert_same('ssh', $fallback['transport'] ?? null, 'The equivalent retry must use SSH.');
transport_fallback_assert_same(
	"-c 'remote.origin.url=git@github.com:Extra-Chill/data-machine-code.git' fetch --quiet origin",
	$fallback['args'] ?? null,
	'The SSH retry must override only the command-local origin URL and preserve the origin fetch refspec.'
);

$unavailable = WorktreeStalenessProbe::equivalent_transport_fallback_for_remote(
	'https://github.com/Extra-Chill/data-machine-code.git',
	array( 'ready' => false, 'code' => 'ssh_agent_no_identities' )
);
transport_fallback_assert_same('ssh_agent_no_identities', $unavailable['unavailable_code'] ?? null, 'Unavailable SSH must remain structured evidence.');
transport_fallback_assert_same(false, isset($unavailable['args']), 'Unavailable SSH must not produce a fallback command.');
transport_fallback_assert_same(null, WorktreeStalenessProbe::equivalent_transport_fallback_for_remote('https://git.example.test/acme/repo.git', array( 'ready' => true )), 'Unauthorized non-GitHub remotes must not receive inferred transport fallbacks.');

$calls = array();
$result = WorktreeStalenessProbe::fetch(
	'/repo',
	static function ( string $path, string $args, int $timeout ) use ( &$calls ): array|WP_Error {
		$calls[] = array( $path, $args, $timeout );
		return 1 === count($calls)
			? new WP_Error('git_command_timeout', 'configured transport timed out', array( 'output' => 'configured transport timed out' ))
			: array( 'success' => true, 'output' => '' );
	},
	null,
	static fn(): array => array(
		'configured_transport' => 'https',
		'transport'            => 'ssh',
		'args'                 => "-c 'remote.origin.url=git@example.test:acme/repo.git' fetch --quiet origin",
	)
);
transport_fallback_assert_same(
	array(
		array( '/repo', 'fetch --quiet origin', 5 ),
		array( '/repo', "-c 'remote.origin.url=git@example.test:acme/repo.git' fetch --quiet origin", 5 ),
	),
	$calls,
	'Freshness verification must retry through the equivalent transport after configured-origin failure.'
);
transport_fallback_assert_same(array( 'https', 'ssh' ), $result['attempted_transports'] ?? null, 'Both transport kinds must be reported.');
transport_fallback_assert_same('ssh', $result['successful_transport'] ?? null, 'Successful fallback transport must be reported.');
transport_fallback_assert_same(true, $result['transport_fallback_used'] ?? null, 'Fallback use must be explicit.');

$calls = array();
$failed = WorktreeStalenessProbe::fetch(
	'/repo',
	static function ( string $path, string $args ) use ( &$calls ): WP_Error {
		$calls[] = array( $path, $args );
		return new WP_Error('git_command_failed', 'fetch failed', array( 'output' => 'fetch failed' ));
	},
	null,
	static fn(): array => array(
		'configured_transport' => 'https',
		'transport'            => 'ssh',
		'unavailable_code'     => 'ssh_agent_no_identities',
	)
);
transport_fallback_assert_same(array( array( '/repo', 'fetch --quiet origin' ), array( '/repo', 'fetch --quiet origin' ) ), $calls, 'Unavailable fallback must preserve the bounded configured-origin retry.');
transport_fallback_assert_same('ssh_agent_no_identities', $failed['fallback_unavailable'] ?? null, 'Unavailable fallback evidence must survive final refusal.');
transport_fallback_assert_same(false, $failed['transport_fallback_used'] ?? null, 'Unavailable fallback must not be reported as used.');

fwrite(STDOUT, "worktree-staleness-transport-fallback: ok\n");
