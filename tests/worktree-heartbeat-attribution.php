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

$now = 1700000000;
$unattributed = WorktreeContextInjector::classify_liveness(array( 'last_seen_at' => gmdate('c', $now - 20) ), $now, 60);
heartbeat_attribution_assert_same(WorktreeContextInjector::LIVENESS_LIVE, $unattributed['liveness'], 'fresh anonymous heartbeat remains live');
heartbeat_attribution_assert_same('unattributed', $unattributed['attribution'], 'anonymous heartbeat is explicitly unattributed');
heartbeat_attribution_assert_same(20, $unattributed['heartbeat_age_seconds'], 'heartbeat age is surfaced');
heartbeat_attribution_assert_same(60, $unattributed['heartbeat_ttl_seconds'], 'heartbeat ttl is surfaced');
heartbeat_attribution_assert_same(gmdate('c', $now + 40), $unattributed['review_after'], 'heartbeat review time is surfaced');
heartbeat_attribution_assert_same(array( 'origin_agent', 'origin_session', 'origin_user', 'owner_run_ref' ), $unattributed['missing_ownership_fields'], 'missing ownership fields are deterministic');

$attributed = WorktreeContextInjector::classify_liveness(array( 'last_seen_at' => gmdate('c', $now - 61), 'origin_agent' => 'agent', 'origin_session' => 'session', 'origin_user' => 'user', 'owner_run_ref' => 'run-1' ), $now, 60);
heartbeat_attribution_assert_same(WorktreeContextInjector::LIVENESS_STALE, $attributed['liveness'], 'expired attributed heartbeat is stale');
heartbeat_attribution_assert_same('attributable', $attributed['attribution'], 'complete ownership is attributable');
heartbeat_attribution_assert_same(array(), $attributed['missing_ownership_fields'], 'complete ownership has no missing fields');

echo "worktree-heartbeat-attribution: ok\n";
