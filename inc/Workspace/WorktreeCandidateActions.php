<?php

declare(strict_types=1);

namespace DataMachineCode\Workspace;

/** Projects bounded same-task candidates into fail-closed recovery actions. */
final class WorktreeCandidateActions {
	/** @return array{candidates:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>} */
	public static function project( array $candidates, string $repo, string $branch, ?string $from, array $task, array $intent ): array {
		$classified = array();
		$adoptable  = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array($candidate) ) {
				continue;
			}
			$classification = self::classify($candidate, $branch, $task);
			$row = array(
				'handle'         => $candidate['handle'] ?? null,
				'owner'          => $candidate['owner'] ?? array(),
				'state'          => $candidate['state'] ?? null,
				'cleanup_policy' => $candidate['cleanup_policy'] ?? null,
				'branch'         => $candidate['branch'] ?? null,
				'dirty'          => $candidate['dirty'] ?? null,
				'unpushed'       => $candidate['unpushed'] ?? null,
				'liveness'       => $candidate['liveness'] ?? null,
				'classification' => $classification,
			);
			$classified[] = $row;
			if ( 'exact_head_clean' === $classification ) {
				$adoptable[] = $candidate;
			}
		}

		$actions = array();
		// More than one exact candidate is identity-ambiguous, even if each is clean.
		if ( 1 === count($adoptable) && 1 === count($classified) ) {
			$candidate = $adoptable[0];
			$path      = (string) $candidate['path'];
			$actions[] = array(
				'action'      => 'adopt_worktree',
				'handle'      => $candidate['handle'],
				'cwd'         => $path,
				'to_worktree' => $candidate['handle'],
				'command'     => 'cd ' . escapeshellarg($path),
			);
		}

		if ( array() === WorktreeContextInjector::missing_isolation_intent($intent) ) {
			$actions[] = array(
				'action' => 'isolate_worktree',
				'command' => self::isolation_command($repo, $branch, $from, $task, $intent),
			);
		}

		return array( 'candidates' => $classified, 'actions' => $actions );
	}

	private static function classify( array $candidate, string $branch, array $task ): string {
		if ( ! self::identity_matches($candidate, $task) ) {
			return 'identity_ambiguous';
		}
		if ( (int) ($candidate['dirty'] ?? 0) > 0 ) {
			return 'dirty';
		}
		if ( (int) ($candidate['unpushed'] ?? 0) > 0 ) {
			return 'unpushed';
		}
		if ( ! in_array($candidate['liveness'] ?? null, array( WorktreeContextInjector::LIVENESS_STALE, WorktreeContextInjector::LIVENESS_STOPPED ), true) ) {
			return 'stale_live';
		}
		if ( '' === trim((string) ($candidate['head'] ?? '')) ) {
			return 'identity_ambiguous';
		}
		return (string) ($candidate['branch'] ?? '') === $branch ? 'exact_head_clean' : 'compatible_clean';
	}

	private static function identity_matches( array $candidate, array $task ): bool {
		$candidate_task = is_array($candidate['task'] ?? null) ? $candidate['task'] : array();
		foreach ( array( 'task_url', 'task_ref' ) as $field ) {
			$requested = trim((string) ($task[ $field ] ?? ''));
			$recorded  = trim((string) ($candidate_task[ $field ] ?? ''));
			if ( '' !== $requested && '' !== $recorded && $requested !== $recorded ) {
				return false;
			}
		}
		return '' !== trim((string) ($candidate_task['task_url'] ?? $candidate_task['task_ref'] ?? ''));
	}

	private static function isolation_command( string $repo, string $branch, ?string $from, array $task, array $intent ): string {
		$parts = array( 'studio', 'wp', 'datamachine-code', 'workspace', 'worktree', 'add', escapeshellarg($repo), escapeshellarg($branch) );
		foreach ( array( 'from' => $from, 'task-url' => $task['task_url'] ?? null, 'task-ref' => $task['task_ref'] ?? null, 'purpose' => $intent['purpose'] ?? null, 'owner-run-ref' => $intent['owner_run_ref'] ?? null, 'cleanup-policy' => $intent['cleanup_policy'] ?? null, 'reuse-policy' => 'isolated' ) as $flag => $value ) {
			if ( is_string($value) && '' !== trim($value) ) {
				$parts[] = '--' . $flag . '=' . escapeshellarg($value);
			}
		}
		return implode(' ', $parts);
	}
}
