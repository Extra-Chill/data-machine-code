<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);

	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCandidateActions.php';

	use DataMachineCode\Workspace\WorktreeCandidateActions;
	use DataMachineCode\Workspace\WorktreeContextInjector;

	function candidate_actions_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	function candidate_action( array $overrides = array() ): array {
		return $overrides + array(
			'handle' => 'repo@fix-1196', 'path' => '/workspace/repo@fix-1196', 'branch' => 'fix/1196', 'head' => 'abc123',
			'dirty' => 0, 'unpushed' => 0, 'liveness' => WorktreeContextInjector::LIVENESS_STALE,
			'task' => array( 'task_url' => 'https://example.test/issues/1196' ),
		);
	}

	$task = array( 'task_url' => 'https://example.test/issues/1196' );
	$intent = array( 'purpose' => 'parallel-review', 'owner_run_ref' => 'run-1196', 'cleanup_policy' => 'remove_on_success' );
	$safe = WorktreeCandidateActions::project(array( candidate_action() ), 'repo', 'fix/1196', 'origin/main', $task, $intent);
	candidate_actions_assert_same('exact_head_clean', $safe['candidates'][0]['classification'] ?? null, 'One exact clean candidate must be classified for adoption.');
	candidate_actions_assert_same('repo@fix-1196', $safe['actions'][0]['to_worktree'] ?? null, 'Safe adoption must bind the exact worktree handle.');
	candidate_actions_assert_same('/workspace/repo@fix-1196', $safe['actions'][0]['cwd'] ?? null, 'Safe adoption must bind the candidate cwd.');
	candidate_actions_assert_same('isolate_worktree', $safe['actions'][1]['action'] ?? null, 'Complete ownership intent must expose an explicit isolation action.');
	candidate_actions_assert_same(true, str_contains((string) ($safe['actions'][1]['command'] ?? ''), '--reuse-policy='), 'Isolation action must be executable as a worktree add command.');

	$ambiguous = WorktreeCandidateActions::project(array( candidate_action(), candidate_action(array( 'handle' => 'repo@other', 'branch' => 'fix/other' )) ), 'repo', 'fix/1196', 'origin/main', $task, array());
	candidate_actions_assert_same('compatible_clean', $ambiguous['candidates'][1]['classification'] ?? null, 'A clean non-exact candidate must remain informational.');
	candidate_actions_assert_same(array(), $ambiguous['actions'], 'Several candidates must not select an adoption target.');

	$none = WorktreeCandidateActions::project(array( candidate_action(array( 'dirty' => 1 )), candidate_action(array( 'handle' => 'repo@unpushed', 'unpushed' => 1 )), candidate_action(array( 'handle' => 'repo@live', 'liveness' => WorktreeContextInjector::LIVENESS_LIVE )), candidate_action(array( 'handle' => 'repo@ambiguous', 'task' => array( 'task_url' => 'https://example.test/issues/other' ) )) ), 'repo', 'fix/1196', 'origin/main', $task, array());
	candidate_actions_assert_same(array( 'dirty', 'unpushed', 'stale_live', 'identity_ambiguous' ), array_column($none['candidates'], 'classification'), 'Unsafe candidates must retain typed fail-closed classifications.');
	candidate_actions_assert_same(array(), $none['actions'], 'Dirty, unpushed, live, and identity-ambiguous candidates must never be adopted.');

	echo "worktree-candidate-actions: ok\n";
}
