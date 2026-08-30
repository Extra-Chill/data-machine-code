<?php
/**
 * Standalone worktree identity and safety provider.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

if ( ! class_exists(WorktreePlanEnvelope::class) ) {
	require_once __DIR__ . '/WorktreePlanEnvelope.php';
}
if ( ! class_exists(WorktreePlanDecision::class) ) {
	require_once __DIR__ . '/WorktreePlanDecision.php';
}
if ( ! class_exists(WorktreePlanPolicy::class) ) {
	require_once __DIR__ . '/WorktreePlanPolicy.php';
}
if ( ! class_exists(WorktreeFreshnessEvidence::class) ) {
	require_once __DIR__ . '/WorktreeFreshnessEvidence.php';
}
if ( ! class_exists(WorktreeTargetTreeDemand::class) ) {
	require_once __DIR__ . '/WorktreeTargetTreeDemand.php';
}
if ( ! class_exists(WorktreeDiskBudget::class) ) {
	require_once __DIR__ . '/WorktreeDiskBudget.php';
}
if ( ! class_exists(StandalonePrimaryRefresher::class) ) {
	require_once __DIR__ . '/StandalonePrimaryRefresher.php';
}
if ( ! class_exists(StandaloneFileLock::class) ) {
	require_once __DIR__ . '/StandaloneFileLock.php';
}

final class StandaloneWorktreeProvider {

	private const IDENTITY_SCHEMA = 'datamachine-code/worktree-identity/v1';
	private const TASK_SCHEMA     = 'datamachine-code/worktree-task-resolution/v1';
	private const SAFETY_SCHEMA   = 'datamachine-code/worktree-safety/v1';
	private const CONVERGE_SCHEMA = 'datamachine-code/worktree-convergence/v1';
	private const CAPABILITIES_SCHEMA = 'datamachine-code/worktree-provider-capabilities/v1';
	private const TOKEN_PREFIX    = 'dmc-worktree-v1.';
	private const PROBE_TIMEOUT   = 2.0;
	private const LOCK_TIMEOUT    = 2.0;
	private const TASK_MAX_MATCHES = 200;
	private const TASK_MAX_ENTRIES = 10000;

	/** @return array<string,mixed> */
	public function capabilities(): array {
		return array(
			'schema'                => self::CAPABILITIES_SCHEMA,
			'operations'            => array( 'capabilities', 'identity', 'task', 'safety', 'converge', 'plan', 'primary-refresh' ),
			'identity_schema'       => self::IDENTITY_SCHEMA,
			'task_resolution_schema' => self::TASK_SCHEMA,
			'plan_schema'           => WorktreePlanEnvelope::SCHEMA,
			'plan_dispositions'     => array( 'create', 'exact_reuse', 'adoptable', 'legacy_handoff_required', 'owner_conflict', 'unsafe', 'stale', 'capacity_blocked' ),
			'plan_apply_ability'    => WorktreePlanEnvelope::APPLY_ABILITY,
			'plan_mutating'         => false,
			'task_resolution_limit' => self::TASK_MAX_MATCHES,
			'tracker_fields'        => array( 'task_url', 'task_ref' ),
			'attachment_operation'  => 'datamachine-code/workspace-worktree-attach-tracker',
			'attachment_preview_input' => array( 'dry_run' => true ),
			'attachment_apply_input'   => array( 'dry_run' => false ),
			'attachment_preview_statuses' => array( 'eligible', 'already_attached' ),
			'attachment_apply_statuses'   => array( 'attached', 'already_attached' ),
			'attachment_identity_fields'  => array( 'handle', 'path', 'branch', 'worktree_sha', 'task_identity' ),
			'attachment_apply_receipt'    => true,
			'attachment_standalone' => false,
			'authorization_bearing' => false,
			'primary_refresh_schema' => StandalonePrimaryRefresher::SCHEMA,
			'primary_refresh_statuses' => array( 'current', 'refreshed', 'refused', 'error' ),
			'primary_refresh_mutating' => true,
			'primary_refresh_remote' => 'origin',
		);
	}

	/** @return array<string,mixed> */
	public function refresh_primary( string $workspace, string $repo, string $remote = 'origin' ): array {
		return ( new StandalonePrimaryRefresher() )->refresh($workspace, $repo, $remote);
	}

	/**
	 * Produce a non-mutating, digest-addressed allocation plan without WordPress.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function plan( string $workspace, array $input ): array {
		$started        = microtime(true);
		$workspace_real = realpath($workspace);
		if ( false === $workspace_real || ! is_dir($workspace_real) ) {
			return $this->error('workspace_not_found', 'The canonical workspace root does not exist.', $started);
		}

		$repo   = trim((string) ( $input['repo'] ?? '' ));
		$branch = trim((string) ( $input['branch'] ?? '' ));
		$parsed = WorkspaceHandle::parse($repo)->to_array();
		if ( '' === $repo || $repo !== $parsed['dir_name'] || $parsed['is_worktree'] || '' === $branch ) {
			return $this->error('invalid_worktree_intent', 'Repository name and branch are required.', $started);
		}

		$from                                  = array_key_exists('from', $input) ? (string) $input['from'] : null;
		$inject_context                        = array_key_exists('inject_context', $input) ? (bool) $input['inject_context'] : true;
		$bootstrap                             = array_key_exists('bootstrap', $input) ? (bool) $input['bootstrap'] : true;
		$allow_stale                           = ! empty($input['allow_stale']);
		$rebase_base                           = ! empty($input['rebase_base']);
		$force                                 = ! empty($input['force']);
		$allow_unverified_freshness            = ! empty($input['allow_unverified_freshness']);
		$require_task_tracker                  = array_key_exists('require_task_tracker', $input) ? (bool) $input['require_task_tracker'] : true;
		$allow_percentage_byte_floor_exception = ! empty($input['allow_percentage_byte_floor_exception']);
		$reuse_policy                          = strtolower(trim(isset($input['reuse_policy']) ? (string) $input['reuse_policy'] : 'reuse_compatible'));
		$task_input                            = is_array($input['task'] ?? null) ? $input['task'] : $input;
		$task                                  = WorktreePlanPolicy::normalize_task(
			array_filter(
				array(
					'task_url' => $task_input['task_url'] ?? null,
					'task_ref' => $task_input['task_ref'] ?? null,
				),
				static fn( mixed $value ): bool => is_string($value) && '' !== trim($value)
			)
		);
		$intent_input = is_array($input['intent'] ?? null) ? $input['intent'] : $input;
		$intent       = array();
		foreach ( array( 'purpose', 'owner_run_ref', 'cleanup_policy' ) as $key ) {
			if ( array_key_exists($key, $intent_input) ) {
				$intent[ $key ] = $intent_input[ $key ];
			}
		}

		if ( ! in_array($reuse_policy, WorktreePlanPolicy::REUSE_POLICIES, true) ) {
			return $this->error('invalid_worktree_reuse_policy', 'reuse_policy must be one of: ' . implode(', ', WorktreePlanPolicy::REUSE_POLICIES) . '.', $started);
		}
		if ( $force && $allow_percentage_byte_floor_exception ) {
			return $this->error('worktree_capacity_policy_conflict', '--force bypasses capacity admission; use it separately from --allow-percentage-byte-floor.', $started);
		}
		if ( array_key_exists('cleanup_policy', $intent) && null === WorktreePlanPolicy::normalize_cleanup_policy($intent['cleanup_policy']) ) {
			return $this->error('invalid_cleanup_policy', 'cleanup_policy must be one of: ' . implode(', ', WorktreePlanPolicy::CLEANUP_POLICIES) . '.', $started);
		}
		$intent = WorktreePlanPolicy::normalize_intent($intent);
		if ( $require_task_tracker && array() === $task ) {
			return $this->error('worktree_task_tracker_required', 'Refusing to plan a managed worktree without a valid task URL or task reference.', $started);
		}

		$slug = WorkspaceHandle::slugify_branch($branch);
		if ( '' === $slug ) {
			return $this->error('invalid_branch', sprintf('Branch "%s" produced an empty slug.', $branch), $started);
		}

		$primary_path = $workspace_real . DIRECTORY_SEPARATOR . $parsed['dir_name'];
		if ( ! is_dir($primary_path) || ( ! is_dir($primary_path . '/.git') && ! is_file($primary_path . '/.git') ) ) {
			return $this->error('primary_not_found', sprintf('Primary checkout for "%s" does not exist. Clone it first.', $repo), $started);
		}

		$handle = $repo . '@' . $slug;
		$path   = $workspace_real . '/' . $handle;
		$apply  = WorktreePlanPolicy::apply_intent(
			$repo,
			$branch,
			$from,
			$inject_context,
			$bootstrap,
			$allow_stale,
			$rebase_base,
			$task,
			$intent,
			$reuse_policy,
			$allow_unverified_freshness,
			$require_task_tracker,
			$force,
			$allow_percentage_byte_floor_exception
		);

		if ( is_dir($path) ) {
			return $this->plan_existing_destination($workspace_real, $apply, $handle, $path, $slug, $branch, $started);
		}

		return $this->plan_create($workspace_real, $primary_path, $apply, $handle, $path, $slug, $repo, $branch, $from, $bootstrap, $allow_stale, $rebase_base, $force, $allow_percentage_byte_floor_exception, $reuse_policy, $task, $intent, $started);
	}

	/**
	 * Resolve exact task ownership from DMC's local tracker files without WordPress.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve_task( string $workspace, string $task_url ): array {
		$started        = microtime(true);
		$workspace_real = realpath($workspace);
		$canonical      = TaskUrl::canonicalize_for_replay($task_url);
		if ( false === $workspace_real || ! is_dir($workspace_real) ) {
			return $this->error('workspace_not_found', 'The canonical workspace root does not exist.', $started);
		}
		if ( null === $canonical ) {
			return $this->error('invalid_task_url', 'Task lookup requires a canonical replay-safe HTTP or HTTPS URL.', $started);
		}

		$identities = array();
		$scanned    = 0;
		foreach ( new \FilesystemIterator($workspace_real, \FilesystemIterator::SKIP_DOTS) as $entry ) {
			if ( ++$scanned > self::TASK_MAX_ENTRIES ) {
				return $this->error('task_workspace_entries_overflow', 'Task lookup exceeded the bounded workspace entry limit.', $started);
			}
			if ( ! $entry->isDir() || $entry->isLink() ) {
				continue;
			}
			$identity = $this->resolve_identity($workspace_real, $entry->getBasename());
			if ( 'owned' !== ( $identity['status'] ?? '' ) || $canonical !== ( $identity['task_url'] ?? null ) ) {
				continue;
			}
			$identities[] = $identity;
			if ( count($identities) > self::TASK_MAX_MATCHES ) {
				return $this->error('task_candidates_overflow', 'Task lookup exceeded the complete matching candidate limit.', $started);
			}
		}

		usort($identities, static fn( array $left, array $right ): int => strcmp((string) $left['handle'], (string) $right['handle']));
		$candidates = array();
		foreach ( $identities as $identity ) {
			$safety = $this->attest_safety($workspace_real, (string) $identity['token']);
			if ( 'error' === ( $safety['status'] ?? '' ) ) {
				return $this->error('task_candidate_safety_failed', 'Could not attest a matching task candidate.', $started);
			}
			if ( true !== ( $safety['fresh'] ?? false ) ) {
				continue;
			}
			$candidates[] = array(
				'handle'   => $identity['handle'],
				'path'     => $identity['path'],
				'branch'   => $identity['branch'],
				'task_url' => $canonical,
				'safety'   => array(
					'dirty'    => $safety['dirty'],
					'unpushed' => $safety['unpushed'],
					'primary'  => $identity['primary'],
				),
			);
		}

		return array(
			'schema'          => self::TASK_SCHEMA,
			'status'          => 'complete',
			'task_url'        => $canonical,
			'candidates'      => $candidates,
			'total'           => count($candidates),
			'entries_scanned' => $scanned,
			'latency_ms'      => $this->elapsed_ms($started),
		);
	}

	/**
	 * Resolve immutable local identity without loading WordPress or probing safety.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve_identity( string $workspace, string $handle ): array {
		$started        = microtime(true);
		$workspace_real = realpath($workspace);
		$parsed         = WorkspaceHandle::parse($handle)->to_array();

		if ( false === $workspace_real || ! is_dir($workspace_real) ) {
			return $this->error('workspace_not_found', 'The canonical workspace root does not exist.', $started);
		}
		if ( $handle !== $parsed['dir_name'] || '' === $parsed['repo'] ) {
			return $this->not_owned('invalid_handle', $handle, $started);
		}

		$path = $workspace_real . DIRECTORY_SEPARATOR . $parsed['dir_name'];
		$real = realpath($path);
		if ( false === $real || dirname($real) !== $workspace_real || ! file_exists($real . '/.git') ) {
			return $this->not_owned('worktree_not_found', $parsed['dir_name'], $started);
		}

		$branch = $this->read_branch($real);
		if ( null === $branch || '' === $branch ) {
			return $this->not_owned('branch_not_found', $parsed['dir_name'], $started);
		}
		$git_dir = $this->git_directory($real);
		if ( null === $git_dir ) {
			return $this->not_owned('git_directory_not_found', $parsed['dir_name'], $started);
		}

		$tracker  = $this->read_tracker($git_dir);
		$identity = array(
			'handle'  => $parsed['dir_name'],
			'path'    => $real,
			'branch'  => $branch,
			'primary' => ! $parsed['is_worktree'],
			'git_dir' => $git_dir,
			'task_url' => $tracker['task_url'],
			'task_ref' => $tracker['task_ref'],
			'tracker'  => $tracker,
		);

		return array_merge(
			array(
				'schema'     => self::IDENTITY_SCHEMA,
				'status'     => 'owned',
				'ownership'  => 'owned',
				'token'      => $this->encode_token($identity),
				'latency_ms' => $this->elapsed_ms($started),
			),
			$identity
		);
	}

	/**
	 * Attest mutable local safety for an identity token returned above.
	 *
	 * @return array<string,mixed>
	 */
	public function attest_safety( string $workspace, string $token ): array {
		$started  = microtime(true);
		$identity = $this->decode_token($token);
		if ( null === $identity ) {
			return $this->error('invalid_identity_token', 'The worktree identity token is invalid.', $started);
		}

		$current = $this->resolve_identity($workspace, $identity['handle']);
		$fresh   = $this->identity_is_fresh($identity, $current);
		if ( ! $fresh ) {
			return $this->safety_result($token, false, false, false, $started);
		}

		$status = $this->run_git($identity['path'], array( 'status', '--porcelain=v1', '--branch', '--untracked-files=normal' ));
		if ( ! $status['success'] ) {
			return $this->error($status['timed_out'] ? 'safety_probe_timeout' : 'safety_probe_failed', 'Could not inspect worktree status.', $started);
		}

		$lines    = preg_split('/\r?\n/', trim($status['stdout'])) ?: array();
		$header   = str_starts_with((string) ( $lines[0] ?? '' ), '## ') ? (string) array_shift($lines) : '';
		$dirty    = array_filter($lines, static fn( string $line ): bool => '' !== trim($line)) !== array();
		$unpushed = 0;
		if ( preg_match('/ahead (\d+)/', $header, $match) ) {
			$unpushed = (int) $match[1];
		} else {
			foreach ( array( '@{push}..HEAD', '@{upstream}..HEAD' ) as $range ) {
				$result = $this->run_git($identity['path'], array( 'rev-list', '--count', $range ));
				if ( $result['timed_out'] ) {
					return $this->error('safety_probe_timeout', 'The unpushed commit probe timed out.', $started);
				}
				$count = trim($result['stdout']);
				if ( $result['success'] && '' !== $count && ctype_digit($count) ) {
					$unpushed = (int) $count;
					break;
				}
			}
		}

		return $this->safety_result($token, $dirty, 0 !== $unpushed, true, $started);
	}

	/**
	 * Fast-forward a token-bound linked worktree to an already-local base commit.
	 *
	 * @return array<string,mixed>
	 */
	public function converge( string $workspace, string $token, string $base_sha ): array {
		$started = microtime(true);
		if ( ! preg_match('/^[a-fA-F0-9]{40}$/D', $base_sha) ) {
			return $this->convergence_result('refused', 'invalid_base_sha', $token, $base_sha, null, null, $started);
		}

		$identity = $this->decode_token($token);
		if ( null === $identity ) {
			return $this->convergence_result('refused', 'invalid_identity_token', $token, $base_sha, null, null, $started);
		}
		$current = $this->resolve_identity($workspace, $identity['handle']);
		if ( ! $this->identity_is_fresh($identity, $current) ) {
			return $this->convergence_result('refused', 'identity_drift', $token, $base_sha, null, null, $started);
		}

		$lock = $this->acquire_convergence_lock($identity['git_dir']);
		if ( null === $lock ) {
			return $this->convergence_result('refused', 'convergence_lock_unavailable', $token, $base_sha, null, null, $started);
		}

		try {
			// Tests use this hook to mutate state after lock acquisition but before admission.
			$this->run_convergence_test_hook($identity['path']);
			$validation = $this->validate_convergence($workspace, $token, $base_sha, $started);
			if ( null !== $validation['result'] ) {
				return $validation['result'];
			}

			$merge = $this->run_git($validation['path'], array( 'merge', '--ff-only', $base_sha ));
			if ( ! $merge['success'] ) {
				return $this->failed_merge_result($workspace, $validation['path'], $merge, $token, $base_sha, $validation['head'], $started);
			}
			$after = $this->read_head($validation['path']);
			if ( null === $after ) {
				return $this->convergence_result('error', 'convergence_ambiguous', $token, $base_sha, $validation['head'], null, $started);
			}
			if ( $base_sha !== $after ) {
				return $this->convergence_result('error', 'unexpected_post_merge_head', $token, $base_sha, $validation['head'], $after, $started);
			}
			$post_identity = $this->decode_token($token);
			$current       = null === $post_identity ? array() : $this->resolve_identity($workspace, $post_identity['handle']);
			if ( null === $post_identity || ! $this->identity_is_fresh($post_identity, $current) || $post_identity['primary'] ) {
				return $this->convergence_result('error', 'post_merge_identity_drift', $token, $base_sha, $validation['head'], $after, $started);
			}
			$post_status = $this->run_git($validation['path'], array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
			if ( ! $post_status['success'] || '' !== trim($post_status['stdout']) ) {
				return $this->convergence_result('error', ! $post_status['success'] ? 'post_merge_safety_probe_failed' : 'post_merge_dirty', $token, $base_sha, $validation['head'], $after, $started);
			}

			return $this->convergence_result('converged', null, $token, $base_sha, $validation['head'], $after, $started);
		} finally {
			$this->release_convergence_lock($lock);
		}
	}

	/**
	 * @param array<string,mixed> $apply
	 * @return array<string,mixed>
	 */
	private function plan_existing_destination(
		string $workspace,
		array $apply,
		string $handle,
		string $path,
		string $slug,
		string $branch,
		float $started
	): array {
		$identity = $this->resolve_identity($workspace, $handle);
		if ( 'owned' !== ( $identity['status'] ?? '' ) ) {
			return $this->error('worktree_plan_unsafe', 'The planned destination exists but cannot be safely inspected.', $started);
		}
		$safety = $this->attest_safety($workspace, (string) $identity['token']);
		if ( 'error' === ( $safety['status'] ?? '' ) || true !== ( $safety['fresh'] ?? false ) ) {
			return $this->error('worktree_plan_unsafe', 'The planned destination exists but cannot be safely inspected.', $started);
		}

		$existing = array(
			'handle'   => $identity['handle'],
			'path'     => $identity['path'],
			'branch'   => $identity['branch'],
			'dirty'    => ! empty($safety['dirty']) ? 1 : 0,
			'unpushed' => ! empty($safety['unpushed']) ? 1 : 0,
			'task'     => array_filter(
				array(
					'task_url' => $identity['task_url'] ?? null,
					'task_ref' => $identity['task_ref'] ?? null,
				),
				static fn( mixed $value ): bool => null !== $value
			),
			'metadata' => array(),
			'liveness' => 'unknown',
		);
		$plan     = WorktreePlanEnvelope::build(
			$apply,
			$handle,
			$path,
			$slug,
			WorktreePlanDecision::existing($identity['branch'] === $branch && 0 === (int) $existing['dirty'] && 0 === (int) $existing['unpushed'] && array() !== (array) ( $existing['metadata']['reuse_contract'] ?? array() ), false, false, null),
			array(
				'destination'    => $existing,
				'ownership'      => array(),
				'legacy_handoff' => null,
			)
		);
		$plan['latency_ms'] = $this->elapsed_ms($started);
		return $plan;
	}

	/**
	 * @param array<string,mixed> $apply
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $intent
	 * @return array<string,mixed>
	 */
	private function plan_create(
		string $workspace,
		string $primary_path,
		array $apply,
		string $handle,
		string $path,
		string $slug,
		string $repo,
		string $branch,
		?string $from,
		bool $bootstrap,
		bool $allow_stale,
		bool $rebase_base,
		bool $force,
		bool $allow_percentage_byte_floor_exception,
		string $reuse_policy,
		array $task,
		array $intent,
		float $started
	): array {
		$exists_local = $this->run_git($primary_path, array( 'show-ref', '--verify', '--quiet', 'refs/heads/' . $branch ));
		$target_ref   = $exists_local['success'] ? 'refs/heads/' . $branch : ( is_string($from) && '' !== trim($from) ? trim($from) : $this->default_base($primary_path) );
		$remote_refs  = $this->remote_refs_digest($primary_path);
		$identity     = $this->freshness_identity($primary_path, $target_ref, $remote_refs);
		$evidence     = is_string($remote_refs) ? WorktreeFreshnessEvidence::matching($primary_path, $remote_refs) : null;
		if ( null === $evidence || null === $identity ) {
			$refresh_action  = $this->primary_refresh_action($workspace, $repo);
			$refresh_command = $refresh_action['command'];
			return array_merge(
				$this->error(
					'freshness_refresh_required',
					sprintf('Refusing to plan worktree creation without verified freshness evidence. Refresh the primary explicitly with `%s`, then re-run this plan.', $refresh_command),
					$started
				),
				array(
					'refresh_command' => $refresh_command,
					'refresh_action'  => $refresh_action,
					'freshness'       => array(
						'status'     => 'refresh_required',
						'verified'   => false,
						'target_ref' => $target_ref,
					),
				)
			);
		}

		$freshness = array(
			'verified'    => true,
			'evidence'    => $evidence,
			'identity'    => $identity,
			'target_ref'  => $target_ref,
			'target_head' => $identity['target_head'],
		);
		$demand_target_ref = $target_ref;
		if ( ! $allow_stale && ! $rebase_base ) {
			$stale = $this->stale_against_default($primary_path, $target_ref);
			if ( is_array($stale) && 'stale' === ( $stale['disposition'] ?? '' ) ) {
				$plan = WorktreePlanEnvelope::build($apply, $handle, $path, $slug, 'stale', array(
					'freshness' => $freshness,
					'safety'    => array(
						'code'    => 'worktree_behind_default_branch',
						'message' => (string) ( $stale['message'] ?? 'The planned ref is behind the remote default branch.' ),
					),
				));
				$plan['latency_ms'] = $this->elapsed_ms($started);
				return $plan;
			}
			if ( is_array($stale) && 'fast_forwardable' === ( $stale['status'] ?? '' ) ) {
				$freshness['default_branch_update'] = $stale;
				$demand_target_ref                   = (string) $stale['default_branch_ref'];
			}
		}

		$demand_plan = $this->bootstrap_demand($primary_path, $demand_target_ref, $bootstrap);
		if ( isset($demand_plan['status']) && 'error' === $demand_plan['status'] ) {
			$demand_plan['latency_ms'] = $this->elapsed_ms($started);
			return $demand_plan;
		}
		$demand_plan['allow_percentage_byte_floor_exception'] = $allow_percentage_byte_floor_exception;
		$capacity   = $this->capacity_budget($workspace, $repo, $branch, $force, $demand_plan);
		$candidates = $this->reuse_candidates($workspace, $task);
		$plan       = WorktreePlanEnvelope::build($apply, $handle, $path, $slug, WorktreePlanDecision::create($capacity, $candidates, $reuse_policy, $intent, null), array(
			'freshness'        => $freshness,
			'capacity'         => $capacity,
			'bootstrap_demand' => $demand_plan,
			'reuse_candidates' => $candidates,
			'ownership'        => $intent,
			'legacy_handoff'   => null,
		));
		$plan['latency_ms'] = $this->elapsed_ms($started);
		return $plan;
	}

	/** @return array{executable:string,arguments:array<int,string>,command:string} */
	private function primary_refresh_action( string $workspace, string $repo ): array {
		$script     = realpath(dirname(__DIR__, 2) . '/bin/dmc-worktree-provider');
		$executable = false === $script ? dirname(__DIR__, 2) . '/bin/dmc-worktree-provider' : $script;
		$arguments = array( 'primary-refresh', $workspace, $repo );
		if ( ! is_executable($executable) ) {
			array_unshift($arguments, $executable);
			$executable = PHP_BINARY;
		}
		return array(
			'executable' => $executable,
			'arguments'  => $arguments,
			'command'    => implode(' ', array_map('escapeshellarg', array_merge(array( $executable ), $arguments))),
		);
	}

	private function default_base( string $primary_path ): string {
		$result = $this->run_git($primary_path, array( 'symbolic-ref', '--quiet', 'refs/remotes/origin/HEAD' ));
		$ref    = $result['success'] ? trim($result['stdout']) : '';
		return '' !== $ref ? $ref : 'HEAD';
	}

	private function remote_refs_digest( string $primary_path ): ?string {
		$refs = $this->run_git($primary_path, array( 'for-each-ref', '--format=%(refname) %(objectname)', 'refs/remotes/origin' ));
		return $refs['success'] ? hash('sha256', rtrim($refs['stdout'], "\r\n")) : null;
	}

	/**
	 * @return array{target_ref:string,target_head:string,remote_refs_digest:string}|null
	 */
	private function freshness_identity( string $primary_path, string $target_ref, ?string $remote_refs ): ?array {
		$target = $this->run_git($primary_path, array( 'rev-parse', '--verify', $target_ref . '^{commit}' ));
		$head   = $target['success'] ? trim($target['stdout']) : '';
		if ( '' === $head || null === $remote_refs ) {
			return null;
		}
		return array(
			'target_ref'         => $target_ref,
			'target_head'        => $head,
			'remote_refs_digest' => $remote_refs,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function stale_against_default( string $primary_path, string $ref ): ?array {
		$default = $this->default_base($primary_path);
		if ( 'HEAD' === $default ) {
			return null;
		}
		$behind = $this->rev_count($primary_path, $ref . '..' . $default);
		if ( null === $behind || 0 === $behind ) {
			return null;
		}
		$ahead = $this->rev_count($primary_path, $default . '..' . $ref);
		if ( 0 === $ahead ) {
			return array(
				'status'             => 'fast_forwardable',
				'commits'            => $behind,
				'default_branch_ref' => $default,
			);
		}
		return array(
			'disposition' => 'stale',
			'message'     => sprintf('Worktree base for branch is %d commits behind the remote default branch %s. Refusing to create a stale worktree.', $behind, $default),
		);
	}

	private function rev_count( string $path, string $range ): ?int {
		$result = $this->run_git($path, array( 'rev-list', '--count', $range ));
		$count  = trim($result['stdout']);
		return $result['success'] && '' !== $count && ctype_digit($count) ? (int) $count : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function bootstrap_demand( string $primary_path, string $target_ref, bool $bootstrap ): array {
		$commit = $this->run_git($primary_path, array( 'rev-parse', '--verify', $target_ref . '^{commit}' ));
		$sha    = $commit['success'] ? trim($commit['stdout']) : '';
		if ( 1 !== preg_match('/^[0-9a-f]{40,64}$/D', $sha) ) {
			return $this->error('worktree_target_ref_invalid', sprintf('Could not resolve target ref "%s" before capacity admission.', $target_ref), microtime(true));
		}
		$blobless = $this->is_blobless_partial_clone($primary_path);
		$tree     = $this->run_git($primary_path, array_merge(
			array( 'ls-tree', '-r', '-t' ),
			$blobless ? array() : array( '-l' ),
			array( '-z', '--full-tree', $sha )
		));
		if ( ! $tree['success'] ) {
			return $this->error('worktree_target_tree_unavailable', 'Target tree inspection did not return parseable output.', microtime(true));
		}
		return WorktreeTargetTreeDemand::assemble(
			WorktreeTargetTreeDemand::parse($tree['stdout']),
			$target_ref,
			$sha,
			$bootstrap,
			$blobless,
			WorktreeTargetTreeDemand::BLOBLESS_TRACKED_ENTRY_BYTES,
			WorktreeTargetTreeDemand::DEFAULTS
		);
	}

	private function is_blobless_partial_clone( string $primary_path ): bool {
		$config = $this->run_git($primary_path, array( 'config', '--get-regexp', '^remote\..*\.(promisor|partialclonefilter)$' ));
		if ( ! $config['success'] || '' === trim($config['stdout']) ) {
			return false;
		}
		$remotes = array();
		foreach ( preg_split('/\r?\n/', $config['stdout']) ?: array() as $line ) {
			if ( 1 !== preg_match('/^remote\.([A-Za-z0-9._-]+)\.(promisor|partialclonefilter)\s+(.+)$/D', $line, $matches) ) {
				continue;
			}
			$remotes[ $matches[1] ][ $matches[2] ] = strtolower(trim($matches[3]));
		}
		foreach ( $remotes as $remote ) {
			if ( in_array($remote['promisor'] ?? '', array( 'true', 'yes', 'on', '1' ), true) && 'blob:none' === ( $remote['partialclonefilter'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $demand_plan
	 * @return array<string,mixed>
	 */
	private function capacity_budget( string $workspace, string $repo, string $branch, bool $force, array $demand_plan ): array {
		$free_bytes  = is_dir($workspace) ? disk_free_space($workspace) : false;
		$total_bytes = is_dir($workspace) ? disk_total_space($workspace) : false;
		$count       = 0;
		$entries     = is_dir($workspace) ? scandir($workspace) : false;
		if ( is_array($entries) ) {
			foreach ( $entries as $entry ) {
				if ( '.' !== $entry && '..' !== $entry && str_contains($entry, '@') ) {
					++$count;
				}
			}
		}
		return WorktreeDiskBudget::evaluate(
			array(
				'workspace_path'            => $workspace,
				'free_bytes'                => is_float($free_bytes) ? (int) $free_bytes : null,
				'total_bytes'               => is_float($total_bytes) ? (int) $total_bytes : null,
				'worktree_count'            => $count,
				'total_inodes'              => null,
				'free_inodes'               => null,
				'inode_probe'               => 'unavailable',
				'workspace_usage_probe'     => 'disabled',
				'workspace_allocated_bytes' => null,
			),
			WorktreeDiskBudget::thresholds($repo, $branch),
			$force,
			$demand_plan
		);
	}

	/**
	 * @param array<string,mixed> $task
	 * @return array<int,array<string,mixed>>
	 */
	private function reuse_candidates( string $workspace, array $task ): array {
		$task_identity = WorktreePlanPolicy::task_identity($task);
		if ( '' === $task_identity ) {
			return array();
		}
		$candidates = array();
		$scanned    = 0;
		foreach ( new \FilesystemIterator($workspace, \FilesystemIterator::SKIP_DOTS) as $entry ) {
			if ( ++$scanned > self::TASK_MAX_ENTRIES ) {
				break;
			}
			if ( ! $entry->isDir() || $entry->isLink() ) {
				continue;
			}
			$identity = $this->resolve_identity($workspace, $entry->getBasename());
			$candidate_task = array_filter(
				array(
					'task_url' => $identity['task_url'] ?? null,
					'task_ref' => $identity['task_ref'] ?? null,
				),
				static fn( mixed $value ): bool => null !== $value
			);
			if ( 'owned' !== ( $identity['status'] ?? '' ) || $task_identity !== WorktreePlanPolicy::task_identity($candidate_task) ) {
				continue;
			}
			if ( true === ( $identity['primary'] ?? false ) ) {
				continue;
			}
			$safety = $this->attest_safety($workspace, (string) $identity['token']);
			if ( 'error' === ( $safety['status'] ?? '' ) || true !== ( $safety['fresh'] ?? false ) ) {
				continue;
			}
			$candidates[] = array(
				'handle'   => $identity['handle'],
				'path'     => $identity['path'],
				'branch'   => $identity['branch'],
				'dirty'    => ! empty($safety['dirty']) ? 1 : 0,
				'unpushed' => ! empty($safety['unpushed']) ? 1 : 0,
				'task'     => $candidate_task,
			);
			if ( count($candidates) >= WorktreePlanPolicy::SAME_TASK_CANDIDATE_LIMIT ) {
				break;
			}
		}
		usort($candidates, static fn( array $left, array $right ): int => strcmp((string) $left['handle'], (string) $right['handle']));
		return $candidates;
	}

	/**
	 * @return array{path:string,head:string,result:array<string,mixed>|null}
	 */
	private function validate_convergence( string $workspace, string $token, string $base_sha, float $started ): array {
		$identity = $this->decode_token($token);
		if ( null === $identity ) {
			return $this->convergence_validation('', '', $this->convergence_result('refused', 'invalid_identity_token', $token, $base_sha, null, null, $started));
		}

		$current = $this->resolve_identity($workspace, $identity['handle']);
		if ( ! $this->identity_is_fresh($identity, $current) ) {
			return $this->convergence_validation('', '', $this->convergence_result('refused', 'identity_drift', $token, $base_sha, null, null, $started));
		}
		if ( $identity['primary'] ) {
			return $this->convergence_validation($identity['path'], '', $this->convergence_result('refused', 'primary_worktree', $token, $base_sha, null, null, $started));
		}

		$head = $this->read_head($identity['path']);
		if ( null === $head ) {
			return $this->convergence_validation($identity['path'], '', $this->convergence_result('error', 'head_probe_failed', $token, $base_sha, null, null, $started));
		}
		$base = $this->run_git($identity['path'], array( 'rev-parse', '--verify', $base_sha . '^{commit}' ));
		if ( ! $base['success'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'base_not_found', $token, $base_sha, $head, $head, $started));
		}
		if ( $base_sha !== trim($base['stdout']) ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'noncanonical_base_sha', $token, $base_sha, $head, $head, $started));
		}

		$status = $this->run_git($identity['path'], array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		if ( ! $status['success'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('error', $status['timed_out'] ? 'safety_probe_timeout' : 'safety_probe_failed', $token, $base_sha, $head, $head, $started));
		}
		if ( '' !== trim($status['stdout']) ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'dirty_worktree', $token, $base_sha, $head, $head, $started));
		}
		$push_safety = $this->has_unpushed_commits($identity['path']);
		if ( ! $push_safety['proven'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', $push_safety['code'], $token, $base_sha, $head, $head, $started));
		}
		if ( $push_safety['unpushed'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'unpushed_commits', $token, $base_sha, $head, $head, $started));
		}
		if ( $head === $base_sha ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('converged', null, $token, $base_sha, $head, $head, $started));
		}

		$behind = $this->run_git($identity['path'], array( 'merge-base', '--is-ancestor', 'HEAD', $base_sha ));
		if ( $behind['success'] ) {
			return $this->convergence_validation($identity['path'], $head, null);
		}
		$ahead = $this->run_git($identity['path'], array( 'merge-base', '--is-ancestor', $base_sha, 'HEAD' ));
		$code  = $ahead['success'] ? 'destination_ahead' : 'destination_diverged';
		return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', $code, $token, $base_sha, $head, $head, $started));
	}

	/** @return array{path:string,head:string,result:array<string,mixed>|null} */
	private function convergence_validation( string $path, string $head, ?array $result ): array {
		return array( 'path' => $path, 'head' => $head, 'result' => $result );
	}

	/** @return array{proven:bool,unpushed:bool,code:string} */
	private function has_unpushed_commits( string $path ): array {
		$timed_out = false;
		foreach ( array( '@{push}..HEAD', '@{upstream}..HEAD' ) as $range ) {
			$result = $this->run_git($path, array( 'rev-list', '--count', $range ));
			$count  = trim($result['stdout']);
			if ( $result['success'] && '' !== $count && ctype_digit($count) ) {
				return array( 'proven' => true, 'unpushed' => 0 < (int) $count, 'code' => '' );
			}
			$timed_out = $timed_out || $result['timed_out'];
		}
		return array( 'proven' => false, 'unpushed' => false, 'code' => $timed_out ? 'unpushed_probe_timeout' : 'unpushed_probe_failed' );
	}

	/** @param array<string,mixed> $identity @param array<string,mixed> $current */
	private function identity_is_fresh( array $identity, array $current ): bool {
		return 'owned' === ( $current['status'] ?? '' )
			&& $identity['path'] === ( $current['path'] ?? null )
			&& $identity['branch'] === ( $current['branch'] ?? null )
			&& $identity['primary'] === ( $current['primary'] ?? null )
			&& $identity['git_dir'] === ( $current['git_dir'] ?? null )
			&& $identity['task_url'] === ( $current['task_url'] ?? null )
			&& $identity['task_ref'] === ( $current['task_ref'] ?? null );
	}

	/** @return array{task_url:?string,task_ref:?string} */
	private function read_tracker( string $git_dir ): array {
		$payload = @file_get_contents($git_dir . '/datamachine-code-task.json');
		$data    = is_string($payload) ? json_decode($payload, true) : null;
		$url     = is_array($data) && is_string($data['task_url'] ?? null) ? $data['task_url'] : null;
		$ref     = is_array($data) && is_string($data['task_ref'] ?? null) ? trim($data['task_ref']) : '';
		return array(
			'task_url' => TaskUrl::canonicalize($url),
			'task_ref' => '' !== $ref && ! preg_match('/\s/', $ref) ? strtolower($ref) : null,
		);
	}

	/** @return resource|null */
	private function acquire_convergence_lock( string $git_dir ) {
		$stat = @stat($git_dir);
		if ( false === $stat ) {
			return null;
		}
		$key  = hash('sha256', $git_dir . ':' . $stat['dev'] . ':' . $stat['ino']);
		$file = sys_get_temp_dir() . '/dmc-worktree-converge-' . $key . '.lock';
		return StandaloneFileLock::acquire($file, self::LOCK_TIMEOUT, 10000);
	}

	/** @param resource $lock */
	private function release_convergence_lock( $lock ): void {
		StandaloneFileLock::release($lock);
	}

	/**
	 * A failed merge can have changed the worktree before returning. Report only
	 * freshly observed state rather than reusing the admission snapshot.
	 *
	 * @param array{success:bool,stdout:string,stderr:string,timed_out:bool} $merge
	 * @return array<string,mixed>
	 */
	private function failed_merge_result( string $workspace, string $path, array $merge, string $token, string $base_sha, string $before, float $started ): array {
		$after  = $this->read_head($path);
		$status = $this->run_git($path, array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		if ( null === $after || ! $status['success'] ) {
			return $this->convergence_result('error', 'convergence_ambiguous', $token, $base_sha, $before, $after, $started);
		}
		$identity    = $this->decode_token($token);
		$current     = null === $identity ? array() : $this->resolve_identity($workspace, $identity['handle']);
		$push_safety = $this->has_unpushed_commits($path);
		if ( null === $identity || ! $this->identity_is_fresh($identity, $current) || ! $push_safety['proven'] ) {
			return $this->convergence_result('error', 'convergence_ambiguous', $token, $base_sha, $before, $after, $started);
		}
		if ( $before !== $after || '' !== trim($status['stdout']) || $push_safety['unpushed'] ) {
			return $this->convergence_result('error', 'convergence_mutated_failure', $token, $base_sha, $before, $after, $started);
		}
		return $this->convergence_result('error', $merge['timed_out'] ? 'convergence_timeout' : 'convergence_failed', $token, $base_sha, $before, $after, $started);
	}

	private function read_head( string $path ): ?string {
		$result = $this->run_git($path, array( 'rev-parse', '--verify', 'HEAD^{commit}' ));
		return $result['success'] ? trim($result['stdout']) : null;
	}

	private function run_convergence_test_hook( string $path ): void {
		$hook = getenv('DMC_WORKTREE_PROVIDER_TEST_CONVERGE_HOOK');
		if ( false !== $hook && '' !== $hook ) {
			$process = proc_open(array( $hook, $path ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes);
			if ( is_resource($process) ) {
				proc_close($process);
			}
		}
	}

	private function read_branch( string $path ): ?string {
		$git_dir = $this->git_directory($path);
		if ( null === $git_dir ) {
			return null;
		}
		$head = trim((string) @file_get_contents($git_dir . '/HEAD'));
		return str_starts_with($head, 'ref: refs/heads/') ? substr($head, strlen('ref: refs/heads/')) : null;
	}

	private function git_directory( string $path ): ?string {
		$git_entry = $path . '/.git';
		$git_dir   = $git_entry;
		if ( is_file($git_entry) ) {
			$pointer = trim((string) file_get_contents($git_entry));
			if ( ! str_starts_with($pointer, 'gitdir:') ) {
				return null;
			}
			$target  = trim(substr($pointer, strlen('gitdir:')));
			$git_dir = str_starts_with($target, '/') ? $target : $path . '/' . $target;
		}

		$git_dir = realpath($git_dir);
		return false === $git_dir ? null : $git_dir;
	}

	/**
	 * @param array{handle:string,path:string,branch:string,primary:bool,git_dir:string,task_url:?string,task_ref:?string,tracker:array} $identity
	 */
	private function encode_token( array $identity ): string {
		$payload = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		return self::TOKEN_PREFIX . hash('sha256', $payload) . '.' . $encoded;
	}

	/**
	 * @return array{handle:string,path:string,branch:string,primary:bool,git_dir:string,task_url:?string,task_ref:?string,tracker:array}|null
	 */
	private function decode_token( string $token ): ?array {
		if ( ! str_starts_with($token, self::TOKEN_PREFIX) ) {
			return null;
		}
		$parts = explode('.', substr($token, strlen(self::TOKEN_PREFIX)), 2);
		if ( 2 !== count($parts) || 64 !== strlen($parts[0]) ) {
			return null;
		}
		$payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
		if ( false === $payload || ! hash_equals($parts[0], hash('sha256', $payload)) ) {
			return null;
		}
		$decoded = json_decode($payload, true);
		if ( ! is_array($decoded)
			|| ! is_string($decoded['handle'] ?? null)
			|| ! is_string($decoded['path'] ?? null)
			|| ! is_string($decoded['branch'] ?? null)
			|| ! is_bool($decoded['primary'] ?? null)
			|| ! is_string($decoded['git_dir'] ?? null)
			|| ( ! is_string($decoded['task_url'] ?? null) && null !== ( $decoded['task_url'] ?? null ) )
			|| ( ! is_string($decoded['task_ref'] ?? null) && null !== ( $decoded['task_ref'] ?? null ) ) ) {
			return null;
		}
		return array(
			'handle'   => $decoded['handle'],
			'path'     => $decoded['path'],
			'branch'   => $decoded['branch'],
			'primary'  => $decoded['primary'],
			'git_dir'  => $decoded['git_dir'],
			'task_url' => $decoded['task_url'] ?? null,
			'task_ref' => $decoded['task_ref'] ?? null,
			'tracker'  => array(
				'task_url' => $decoded['task_url'] ?? null,
				'task_ref' => $decoded['task_ref'] ?? null,
			),
		);
	}

	/**
	 * @param array<int,string> $arguments
	 * @return array{success:bool,stdout:string,stderr:string,timed_out:bool}
	 */
	private function run_git( string $path, array $arguments ): array {
		$command = array_merge(array( 'git', '--no-optional-locks', '-C', $path ), $arguments);
		$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		if ( ! is_resource($process) ) {
			return array( 'success' => false, 'stdout' => '', 'stderr' => 'Could not start Git.', 'timed_out' => false );
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$started = microtime(true);
		$stdout  = '';
		$stderr  = '';
		$exit    = -1;
		$timeout = false;

		while ( true ) {
			$stdout .= stream_get_contents($pipes[1]);
			$stderr .= stream_get_contents($pipes[2]);
			$status  = proc_get_status($process);
			if ( ! $status['running'] ) {
				$exit = (int) $status['exitcode'];
				break;
			}
			if ( microtime(true) - $started >= self::PROBE_TIMEOUT ) {
				$timeout = true;
				proc_terminate($process, 15);
				usleep(50000);
				$status = proc_get_status($process);
				if ( $status['running'] ) {
					proc_terminate($process, 9);
				}
				break;
			}
			usleep(10000);
		}

		$stdout .= stream_get_contents($pipes[1]);
		$stderr .= stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		return array( 'success' => ! $timeout && 0 === $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timeout );
	}

	/** @return array<string,mixed> */
	private function not_owned( string $reason, string $handle, float $started ): array {
		return array(
			'schema'     => self::IDENTITY_SCHEMA,
			'status'     => 'not_owned',
			'ownership'  => 'not_owned',
			'reason'     => $reason,
			'handle'     => $handle,
			'latency_ms' => $this->elapsed_ms($started),
		);
	}

	/** @return array<string,mixed> */
	private function safety_result( string $token, bool $dirty, bool $unpushed, bool $fresh, float $started ): array {
		return array(
			'schema'         => self::SAFETY_SCHEMA,
			'status'         => 'attested',
			'identity_token' => $token,
			'observed_at'    => gmdate('c'),
			'dirty'          => $dirty,
			'unpushed'       => $unpushed,
			'fresh'          => $fresh,
			'latency_ms'     => $this->elapsed_ms($started),
		);
	}

	/** @return array<string,mixed> */
	private function convergence_result( string $status, ?string $code, string $token, string $base_sha, ?string $before, ?string $after, float $started ): array {
		$result = array(
			'schema'         => self::CONVERGE_SCHEMA,
			'status'         => $status,
			'identity_token' => $token,
			'base_sha'       => $base_sha,
			'before_head'    => $before,
			'after_head'     => $after,
			'changed'        => null !== $before && null !== $after ? $before !== $after : null,
			'latency_ms'     => $this->elapsed_ms($started),
		);
		if ( null !== $code ) {
			$result['code'] = $code;
		}
		return $result;
	}

	/** @return array<string,mixed> */
	private function error( string $code, string $message, float $started ): array {
		return array(
			'schema'     => 'datamachine-code/worktree-provider-error/v1',
			'status'     => 'error',
			'code'       => $code,
			'message'    => $message,
			'latency_ms' => $this->elapsed_ms($started),
		);
	}

	private function elapsed_ms( float $started ): int {
		return (int) round(( microtime(true) - $started ) * 1000);
	}
}
