<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';

use DataMachineCode\Workspace\WorktreeContextInjector;

function heartbeat_attribution_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
	}
}

set_error_handler(static function ( int $severity, string $message, string $file, int $line ): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
	$now = 1700000000;
	$unattributed = WorktreeContextInjector::classify_liveness(array( 'last_seen_at' => gmdate('c', $now - 20) ), $now, 60);
	heartbeat_attribution_assert_same(WorktreeContextInjector::LIVENESS_LIVE, $unattributed['liveness'], 'fresh anonymous heartbeat remains live');
	heartbeat_attribution_assert_same('unattributed', $unattributed['attribution'], 'anonymous heartbeat is explicitly unattributed');
	heartbeat_attribution_assert_same(20, $unattributed['heartbeat_age_seconds'], 'heartbeat age is surfaced');
	heartbeat_attribution_assert_same(60, $unattributed['heartbeat_ttl_seconds'], 'heartbeat ttl is surfaced');
	heartbeat_attribution_assert_same(gmdate('c', $now + 40), $unattributed['review_after'], 'heartbeat review time is surfaced');
	heartbeat_attribution_assert_same(array( 'origin_agent', 'origin_session', 'origin_user', 'owner_run_ref' ), $unattributed['missing_ownership_fields'], 'missing ownership fields are deterministic');
	heartbeat_attribution_assert_same(array(), $unattributed['malformed_ownership_fields'], 'absent ownership fields are not malformed');

	$attributed = WorktreeContextInjector::classify_liveness(array( 'last_seen_at' => gmdate('c', $now - 61), 'origin_agent' => 'agent', 'origin_session' => 'session', 'origin_user' => 'user', 'owner_run_ref' => 'run-1' ), $now, 60);
	heartbeat_attribution_assert_same(WorktreeContextInjector::LIVENESS_STALE, $attributed['liveness'], 'expired attributed heartbeat is stale');
	heartbeat_attribution_assert_same('attributable', $attributed['attribution'], 'complete ownership is attributable');
	heartbeat_attribution_assert_same(array(), $attributed['missing_ownership_fields'], 'complete ownership has no missing fields');
	heartbeat_attribution_assert_same(array(), $attributed['malformed_ownership_fields'], 'scalar ownership fields are not malformed');

	$null_ownership = WorktreeContextInjector::classify_liveness(array( 'origin_agent' => null, 'origin_session' => 'session', 'origin_user' => 'user', 'owner_run_ref' => 'run-1' ));
	heartbeat_attribution_assert_same(array( 'origin_agent' ), $null_ownership['missing_ownership_fields'], 'null ownership is missing');
	heartbeat_attribution_assert_same(array(), $null_ownership['malformed_ownership_fields'], 'null ownership is not malformed');

	$empty_ownership = WorktreeContextInjector::classify_liveness(array( 'origin_agent' => ' ', 'origin_session' => 'session', 'origin_user' => 'user', 'owner_run_ref' => 'run-1' ));
	heartbeat_attribution_assert_same(array( 'origin_agent' ), $empty_ownership['missing_ownership_fields'], 'empty scalar ownership is missing');
	heartbeat_attribution_assert_same(array(), $empty_ownership['malformed_ownership_fields'], 'empty scalar ownership is not malformed');

	$array_ownership = WorktreeContextInjector::classify_liveness(array( 'origin_agent' => array( 'agent' ), 'origin_session' => 'session', 'origin_user' => 'user', 'owner_run_ref' => 'run-1' ));
	heartbeat_attribution_assert_same('unattributed', $array_ownership['attribution'], 'array ownership cannot make a worktree attributable');
	heartbeat_attribution_assert_same(array( 'origin_agent' ), $array_ownership['missing_ownership_fields'], 'array ownership is treated as missing');
	heartbeat_attribution_assert_same(array( 'origin_agent' ), $array_ownership['malformed_ownership_fields'], 'array ownership is surfaced as malformed');
	heartbeat_attribution_assert_same('unknown', WorktreeContextInjector::summarize_owner(array( 'origin_agent' => array( 'agent' ) ))['agent'], 'malformed agent metadata renders safely as unknown');

	$canonical_ownership = array(
		'origin_agent'   => 'agent',
		'origin_session' => array( 'primary_id' => 'session-1', 'ids' => array( 'runtime' => array( 'session_id' => 'session-1' ) ) ),
		'origin_user'    => array( 'id' => 1, 'login' => 'chris', 'display_name' => 'Chris Huber' ),
		'owner_run_ref'  => 'run-1',
	);
	$canonical_liveness = WorktreeContextInjector::classify_liveness($canonical_ownership);
	heartbeat_attribution_assert_same('attributable', $canonical_liveness['attribution'], 'canonical structured ownership remains attributable');
	heartbeat_attribution_assert_same(array(), $canonical_liveness['missing_ownership_fields'], 'canonical structured ownership has no missing fields');
	heartbeat_attribution_assert_same(array(), $canonical_liveness['malformed_ownership_fields'], 'canonical structured ownership is not malformed');
	heartbeat_attribution_assert_same('chris', WorktreeContextInjector::summarize_owner($canonical_ownership)['user'], 'canonical user metadata renders for listings');
	heartbeat_attribution_assert_same('session-1', WorktreeContextInjector::summarize_session($canonical_ownership)['primary_id'], 'canonical session metadata renders for listings');

	$malformed_structured_ownership = $canonical_ownership;
	$malformed_structured_ownership['origin_session'] = array( 'primary_id' => 'session-1', 'ids' => array() );
	$malformed_structured_ownership['origin_user'] = array( 'id' => 1 );
	$malformed_structured_liveness = WorktreeContextInjector::classify_liveness($malformed_structured_ownership);
	heartbeat_attribution_assert_same('unattributed', $malformed_structured_liveness['attribution'], 'malformed structured ownership remains fail-closed');
	heartbeat_attribution_assert_same(array( 'origin_session', 'origin_user' ), $malformed_structured_liveness['missing_ownership_fields'], 'malformed structured ownership is treated as missing');
	heartbeat_attribution_assert_same(array( 'origin_session', 'origin_user' ), $malformed_structured_liveness['malformed_ownership_fields'], 'malformed structured ownership is surfaced deterministically');
} finally {
	restore_error_handler();
}

echo "worktree-heartbeat-attribution: ok\n";
