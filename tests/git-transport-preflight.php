<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Support/GitHubRemote.php';
require_once dirname(__DIR__) . '/inc/Support/GitTransportPreflight.php';

use DataMachineCode\Support\GitTransportPreflight;

function transport_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
	}
}

$ssh_remote = 'git@github.example:owner/repository.git';
$missing     = GitTransportPreflight::classify($ssh_remote, false, null);
transport_assert_same('ssh_agent_unavailable', $missing['code'] ?? null, 'A missing SSH agent must be classified before Git runs.');
transport_assert_same('https://github.example/owner/repository.git', $missing['https_alternative'] ?? null, 'GitHub-compatible SSH remotes must provide their HTTPS alternative.');
transport_assert_same('unverified', $missing['https_authenticated'] ?? null, 'The diagnostic must not claim HTTPS authentication is available.');

$empty = GitTransportPreflight::classify($ssh_remote, true, 1);
transport_assert_same('ssh_agent_no_identities', $empty['code'] ?? null, 'An empty SSH agent must be distinguished from a missing agent.');

$ready = GitTransportPreflight::classify($ssh_remote, true, 0);
transport_assert_same(true, $ready['ready'] ?? null, 'An SSH agent with identities must pass the preflight.');

$refused = GitTransportPreflight::signing_failure($ssh_remote, 'sign_and_send_pubkey: signing failed: agent refused operation');
transport_assert_same('ssh_agent_signing_refused', $refused['code'] ?? null, 'A signing refusal must remain actionable after Git starts.');

transport_assert_same(null, GitTransportPreflight::classify('https://example.com/owner/repository.git', false, null), 'HTTPS remotes must bypass SSH preflight.');
transport_assert_same(null, GitTransportPreflight::classify('git@example.com:owner/repository.git', false, null), 'Non-GitHub SSH remotes must not advertise an HTTPS fallback.');
transport_assert_same(null, GitTransportPreflight::signing_failure('git@example.com:owner/repository.git', 'sign_and_send_pubkey: signing failed: agent refused operation'), 'Non-GitHub SSH signing failures must retain existing handling.');

echo "Git transport preflight tests passed.\n";
