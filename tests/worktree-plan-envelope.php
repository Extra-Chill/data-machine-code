<?php

declare(strict_types=1);

define('DATAMACHINE_CODE_STANDALONE', true);
require dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Workspace\WorktreePlanDecision;
use DataMachineCode\Workspace\WorktreePlanEnvelope;

function worktree_plan_envelope_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$intent = array(
	'repo' => 'repo',
	'branch' => 'fix/plan',
	'from' => 'origin/main',
	'inject_context' => false,
	'bootstrap' => false,
	'allow_stale' => false,
	'rebase_base' => false,
	'task' => array( 'task_url' => 'https://example.test/issues/1' ),
	'intent' => array(),
	'reuse_policy' => 'reuse_compatible',
	'allow_unverified_freshness' => false,
	'require_task_tracker' => true,
	'force' => false,
	'allow_percentage_byte_floor_exception' => false,
);
$evidence = array(
	'freshness' => array(
		'verified' => true,
		'identity' => array( 'target_ref' => 'origin/main', 'target_head' => str_repeat('a', 40), 'remote_refs_digest' => str_repeat('b', 64) ),
		'target_ref' => 'origin/main',
		'target_head' => str_repeat('a', 40),
	),
	'capacity' => array( 'status' => 'ok', 'creation_allowed' => true, 'worktree_count' => 1 ),
	'bootstrap_demand' => array( 'bytes' => 16, 'inodes' => 2, 'source' => 'target_git_tree_conservative' ),
	'reuse_candidates' => array(),
	'ownership' => array(),
	'legacy_handoff' => null,
);

$first  = WorktreePlanEnvelope::build($intent, 'repo@fix-plan', '/workspace/repo@fix-plan', 'fix-plan', 'create', $evidence);
$second = WorktreePlanEnvelope::build($intent, 'repo@fix-plan', '/workspace/repo@fix-plan', 'fix-plan', 'create', $evidence);
worktree_plan_envelope_assert($first['digest'] === $second['digest'] && 64 === strlen($first['digest']), 'Identical evidence must produce a stable digest.');
worktree_plan_envelope_assert(WorktreePlanEnvelope::SCHEMA === $first['schema'] && WorktreePlanEnvelope::APPLY_ABILITY === $first['apply']['ability'], 'Envelope must advertise the shared plan schema and apply ability.');

$changed = WorktreePlanEnvelope::build($intent, 'repo@fix-plan', '/workspace/repo@fix-plan', 'fix-plan', 'create', array_replace($evidence, array(
	'bootstrap_demand' => array_replace($evidence['bootstrap_demand'], array( 'bytes' => 17 )),
)));
worktree_plan_envelope_assert($first['digest'] !== $changed['digest'], 'Bootstrap demand changes must stale plan identity.');
worktree_plan_envelope_assert(array( 'bootstrap_demand' ) === WorktreePlanEnvelope::changed_sections($first, $changed), 'Changed-section reporting must name bootstrap demand.');

worktree_plan_envelope_assert('create' === WorktreePlanDecision::create(array( 'status' => 'ok' ), array(), 'reuse_compatible', array(), null), 'Empty candidate create must remain create.');
worktree_plan_envelope_assert('capacity_blocked' === WorktreePlanDecision::create(array( 'status' => 'refused' ), array(), 'reuse_compatible', array(), null), 'Refused capacity must block creation.');
worktree_plan_envelope_assert('owner_conflict' === WorktreePlanDecision::create(array( 'status' => 'ok' ), array( array( 'handle' => 'repo@other' ) ), 'reuse_compatible', array(), null), 'Same-task candidates must conflict under compatible reuse.');
worktree_plan_envelope_assert('unsafe' === WorktreePlanDecision::existing(false, false, false, null), 'Existing destinations without a reuse contract must be unsafe.');
worktree_plan_envelope_assert('exact_reuse' === WorktreePlanDecision::existing(true, false, false, null), 'Exact compatible destinations must reuse.');

echo "worktree-plan-envelope: ok\n";
