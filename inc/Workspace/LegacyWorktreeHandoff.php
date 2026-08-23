<?php
/**
 * Typed, non-mutating classification for legacy same-task worktree handoffs.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class LegacyWorktreeHandoff {

	/**
	 * A legacy candidate is eligible only when every safety observation is
	 * affirmative. Missing evidence is a veto, not an inferred safe state.
	 *
	 * @return array<string,mixed>
	 */
	public static function plan( array $candidate, array $requested ): array {
		$metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : array();
		$contract = is_array($metadata['reuse_contract'] ?? null) ? $metadata['reuse_contract'] : array();
		$stored_runtime = array(
			'inject_context' => $contract['inject_context'] ?? null,
			'bootstrap'      => $contract['bootstrap'] ?? null,
		);
		$requested_runtime = array(
			'inject_context' => $requested['inject_context'] ?? null,
			'bootstrap'      => $requested['bootstrap'] ?? null,
		);
		$runtime_mismatch = $stored_runtime !== $requested_runtime;
		$owner = array(
			'owner_run_ref' => $contract['owner_run_ref'] ?? $metadata['owner_run_ref'] ?? null,
			'origin_session' => $metadata['origin_session'] ?? null,
		);
		$owner['classification'] = '' === trim((string) $owner['owner_run_ref']) ? 'unknown_legacy' : 'foreign_legacy';
		$task_identity = (string) ($requested['task_identity'] ?? '');
		$candidate_task_identity = (string) ($candidate['task_identity'] ?? '');
		$checks = array(
			'same_repository'       => ! empty($candidate['same_repository']),
			'same_task'             => '' !== $task_identity && hash_equals($task_identity, $candidate_task_identity),
			'non_primary'           => empty($candidate['is_primary']),
			'clean'                 => 0 === (int) ($candidate['dirty'] ?? -1),
			'pushed'                => 0 === (int) ($candidate['unpushed'] ?? -1),
			'stopped_or_stale'      => in_array($candidate['liveness'] ?? null, array(WorktreeContextInjector::LIVENESS_STOPPED, WorktreeContextInjector::LIVENESS_STALE), true),
			'unlocked'              => false === ($candidate['locked'] ?? null),
			'no_active_process'     => true === ($candidate['no_active_process'] ?? false),
			'candidate_verifiable'  => true === ($candidate['verifiable'] ?? false),
			'runtime_mismatch'      => $runtime_mismatch,
		);
		$vetoes = array_keys(array_filter($checks, static fn( bool $passed ): bool => ! $passed));
		$status = array() === $vetoes ? 'legacy_handoff_required' : 'legacy_handoff_refused';
		$lineage = array(
			'old_handle' => $candidate['handle'] ?? null,
			'old_owner'  => $owner,
			'new_owner'  => array(
				'owner_run_ref' => $requested['owner_run_ref'] ?? null,
				'purpose'       => $requested['purpose'] ?? null,
			),
			'task_identity' => $task_identity,
		);

		return array(
			'type'          => 'legacy_handoff',
			'status'        => $status,
			'candidate'     => array_intersect_key($candidate, array_flip(array( 'handle', 'path', 'branch', 'head', 'dirty', 'unpushed', 'liveness', 'is_primary' ))),
			'task_identity' => $task_identity,
			'owner'         => $owner,
			'runtime_delta' => array( 'stored' => $stored_runtime, 'requested' => $requested_runtime ),
			'checks'        => $checks,
			'vetoes'        => $vetoes,
			'lineage'       => $lineage,
			'actions'       => array() === $vetoes ? array(
				array( 'type' => 'adopt_runtime', 'ability' => 'datamachine-code/workspace-worktree-legacy-handoff-apply', 'mode' => 'adopt_runtime', 'old_handle' => $candidate['handle'] ?? null, 'lineage' => $lineage ),
				array( 'type' => 'replace_isolated', 'ability' => 'datamachine-code/workspace-worktree-legacy-handoff-apply', 'mode' => 'replace_isolated', 'old_handle' => $candidate['handle'] ?? null, 'terminal_classification' => 'superseded', 'lineage' => $lineage ),
			) : array(),
		);
	}
}
