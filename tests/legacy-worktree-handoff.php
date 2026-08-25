<?php

declare(strict_types=1);

namespace {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

namespace DataMachineCode\Workspace {
	final class WorktreeContextInjector {
		public const LIVENESS_LIVE = 'live';
		public const LIVENESS_STOPPED = 'stopped';
		public const LIVENESS_STALE = 'stale';

		public static function normalize_scalar_metadata_value( mixed $value ): ?string {
			if ( ! is_scalar($value) ) {
				return null;
			}
			$value = trim((string) $value);
			return '' === $value ? null : $value;
		}
	}
}

namespace {
	require_once dirname(__DIR__) . '/inc/Workspace/LegacyWorktreeHandoff.php';

	use DataMachineCode\Workspace\LegacyWorktreeHandoff;

	function legacy_handoff_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	function legacy_handoff_candidate( array $overrides = array() ): array {
		return array_merge(array(
			'handle' => 'repo@legacy', 'path' => '/workspace/repo@legacy', 'branch' => 'fix/1122', 'head' => 'abc',
			'same_repository' => true, 'task_identity' => 'https://example.test/issues/1122', 'is_primary' => false,
			'dirty' => 0, 'unpushed' => 0, 'liveness' => 'stale', 'locked' => false,
			'no_active_process' => true, 'verifiable' => true,
			'metadata' => array( 'reuse_contract' => array( 'inject_context' => false, 'bootstrap' => false ) ),
		), $overrides);
	}

	$request = array( 'task_identity' => 'https://example.test/issues/1122', 'inject_context' => true, 'bootstrap' => true, 'owner_run_ref' => 'homeboy/run/1122', 'purpose' => 'issue-1122' );
	$allowed = LegacyWorktreeHandoff::plan(legacy_handoff_candidate(), $request);
	legacy_handoff_assert('legacy_handoff_required' === $allowed['status'], 'Clean stale legacy mismatch requires a handoff plan.');
	legacy_handoff_assert(2 === count($allowed['actions']), 'Eligible plan exposes adoption and isolated replacement actions.');
	legacy_handoff_assert('unknown_legacy' === $allowed['owner']['classification'], 'Unknown owner is recorded in lineage.');

	$foreign = LegacyWorktreeHandoff::plan(legacy_handoff_candidate(array( 'metadata' => array( 'owner_run_ref' => 'foreign/run', 'reuse_contract' => array( 'inject_context' => false, 'bootstrap' => false ) ) )), $request);
	legacy_handoff_assert('legacy_handoff_required' === $foreign['status'] && 'foreign_legacy' === $foreign['owner']['classification'], 'Foreign legacy ownership remains eligible after safety proof.');

	foreach ( array(
		'dirty' => array( 'dirty' => 1 ), 'unpushed' => array( 'unpushed' => 1 ), 'live' => array( 'liveness' => 'live' ),
		'ambiguous_task' => array( 'task_identity' => '' ), 'locked' => array( 'locked' => true ),
		'active_process' => array( 'no_active_process' => false ), 'unverifiable' => array( 'verifiable' => false ),
		'primary' => array( 'is_primary' => true ), 'runtime_compatible' => array( 'metadata' => array( 'reuse_contract' => array( 'inject_context' => true, 'bootstrap' => true ) ) ),
	) as $name => $overrides ) {
		$plan = LegacyWorktreeHandoff::plan(legacy_handoff_candidate($overrides), $request);
		legacy_handoff_assert('legacy_handoff_refused' === $plan['status'], sprintf('%s must fail closed.', $name));
		legacy_handoff_assert(array() === $plan['actions'], sprintf('%s must not offer a mutation action.', $name));
	}

	print "legacy worktree handoff tests passed\n";
}
