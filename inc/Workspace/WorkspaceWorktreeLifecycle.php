<?php
/**
 * Workspace worktree lifecycle operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\ListCursor;
use DataMachineCode\Support\MacOSLsofProcessPathProbe;
use DataMachineCode\Support\WallClockBudget;

defined('ABSPATH') || exit;

if ( ! class_exists(MacOSLsofProcessPathProbe::class) ) {
	require_once dirname(__DIR__) . '/Support/ProcessPathProbe.php';
}
if ( ! class_exists(WallClockBudget::class) ) {
	require_once dirname(__DIR__) . '/Support/WallClockBudget.php';
}

trait WorkspaceWorktreeLifecycle {

	/** Keep each primary's read-only worktree inventory probe within the CLI budget. */
	private const WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS = 5;

	/** One deadline covers finalizer admission, probes, persistence, and readback. */
	private const WORKTREE_FINALIZE_DEFAULT_BUDGET = '10s';

	/**
	 * Produce a non-mutating, digest-addressed worktree allocation decision.
	 *
	 * This deliberately shares add's validation, target resolution, capacity, and
	 * exact-handle policy inputs. Apply re-runs this method immediately before add
	 * so remote refs, capacity, ownership, and destination changes fail closed.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_plan_request( WorktreeAllocationRequest $request ): array|\WP_Error {
		$repo                                  = $request->repo;
		$branch                                = $request->branch;
		$from                                  = $request->from;
		$inject_context                        = $request->inject_context;
		$bootstrap                             = $request->bootstrap;
		$allow_stale                           = $request->allow_stale;
		$rebase_base                           = $request->rebase_base;
		$force                                 = $request->force;
		$task                                  = $request->task;
		$allow_unverified_freshness            = $request->allow_unverified_freshness;
		$require_task_tracker                  = $request->require_task_tracker;
		$intent                                = $request->intent;
		$reuse_policy                          = $request->reuse_policy;
		$allow_percentage_byte_floor_exception = $request->allow_percentage_byte_floor_exception;
		$visible                               = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}
		$repo   = $this->resolve_primary_repo_name($repo);
		$branch = trim($branch);
		if ( is_wp_error($repo) ) {
			return $repo;
		}
		if ( '' === $repo || '' === $branch ) {
			return new \WP_Error('invalid_worktree_intent', 'Repository name and branch are required.', array( 'status' => 400 ));
		}
		$task         = WorktreeContextInjector::resolve_task_metadata($task) ?? array();
		$reuse_policy = strtolower(trim($reuse_policy));
		if ( ! in_array($reuse_policy, WorktreeContextInjector::VALID_REUSE_POLICIES, true) ) {
			return new \WP_Error('invalid_worktree_reuse_policy', 'reuse_policy must be one of: ' . implode(', ', WorktreeContextInjector::VALID_REUSE_POLICIES) . '.', array( 'status' => 400 ));
		}
		if ( $force && $allow_percentage_byte_floor_exception ) {
			return new \WP_Error('worktree_capacity_policy_conflict', '--force bypasses capacity admission; use it separately from --allow-percentage-byte-floor.', array( 'status' => 400 ));
		}
		if ( array_key_exists('cleanup_policy', $intent) && null === WorktreeContextInjector::normalize_cleanup_policy($intent['cleanup_policy']) ) {
			return new \WP_Error('invalid_cleanup_policy', 'cleanup_policy must be one of: ' . implode(', ', WorktreeContextInjector::VALID_CLEANUP_POLICIES) . '.', array( 'status' => 400 ));
		}
		$intent = WorktreeContextInjector::normalize_disposable_intent($intent);
		if ( $require_task_tracker && empty($task) ) {
			return new \WP_Error('worktree_task_tracker_required', 'Refusing to plan a managed worktree without a valid task URL or task reference.', array( 'status' => 400 ));
		}
		$slug = $this->slugify_branch($branch);
		if ( '' === $slug ) {
			return new \WP_Error('invalid_branch', sprintf('Branch "%s" produced an empty slug.', $branch), array( 'status' => 400 ));
		}
		$primary_path = $this->get_primary_path($repo);
		if ( ! GitCheckout::exists($primary_path) ) {
			return new \WP_Error('primary_not_found', sprintf('Primary checkout for "%s" does not exist. Clone it first.', $repo), array( 'status' => 404 ));
		}

		$handle                              = $repo . '@' . $slug;
		$path                                = $this->workspace_path . '/' . $handle;
		$input                               = $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy);
		$input['allow_unverified_freshness'] = $allow_unverified_freshness;
		$input['require_task_tracker']       = $require_task_tracker;
		$input['force']                      = $force;
		$input['allow_percentage_byte_floor_exception'] = $allow_percentage_byte_floor_exception;

		if ( is_dir($path) ) {
			$inspection = $this->worktree_get($handle, array(
				'include_status' => true,
				'include_disk'   => false,
			));
			if ( is_wp_error($inspection) || empty($inspection['worktrees'][0]) ) {
				return new \WP_Error('worktree_plan_unsafe', 'The planned destination exists but cannot be safely inspected.', array(
					'status' => 409,
					'handle' => $handle,
				));
			}
			$existing      = (array) $inspection['worktrees'][0];
			$metadata      = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : array();
			$contract      = is_array($metadata['reuse_contract'] ?? null) ? $metadata['reuse_contract'] : array();
			$stored_intent = WorktreeContextInjector::normalize_disposable_intent($contract + $metadata);
			$exact         = ( $existing['branch'] ?? null ) === $branch
				&& 0 === (int) ( $existing['dirty'] ?? 0 )
				&& 0 === (int) ( $existing['unpushed'] ?? 0 )
				&& array() !== $contract
				&& ( $contract['base_ref'] ?? null ) === ( null !== $from && '' !== trim($from) ? trim($from) : ( $contract['base_ref'] ?? null ) )
				&& (bool) ( $contract['inject_context'] ?? null ) === $inject_context
				&& (bool) ( $contract['bootstrap'] ?? null ) === $bootstrap
				&& $this->worktree_reuse_task_identity($task) === $this->worktree_reuse_task_identity( (array) ( $existing['task'] ?? array() ))
				&& $intent === $stored_intent;
			$disposition   = $exact ? 'exact_reuse' : ( null !== WorktreeContextInjector::get_creation_intent($handle) && array() === $contract ? 'adoptable' : 'unsafe' );
			if ( $exact && WorktreeContextInjector::LIVENESS_LIVE === ( $existing['liveness'] ?? null ) && empty($intent['owner_run_ref']) ) {
				$disposition = 'owner_conflict';
			}
			$legacy_handoff = $this->legacy_handoff_plan($existing, $repo, $task, $intent, $inject_context, $bootstrap);
			if ( 'legacy_handoff_required' === ( $legacy_handoff['status'] ?? null ) ) {
				$disposition = 'legacy_handoff_required';
			}
			return $this->worktree_plan_result($input, $handle, $path, $slug, $disposition, array(
				'destination'    => $existing,
				'ownership'      => $stored_intent,
				'legacy_handoff' => $legacy_handoff,
			));
		}

		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$evidence     = $this->primary_freshness_evidence($primary_path, $repo);
		$identity     = $this->primary_freshness_identity($primary_path, $target_ref);
		if ( null === $evidence || null === $identity ) {
			$refresh_command = $this->primary_refresh_command($repo);
			return new \WP_Error(
				'freshness_refresh_required',
				sprintf('Refusing to plan worktree creation without verified freshness evidence. Refresh the primary explicitly with `%s`, then re-run this plan.', $refresh_command),
				array(
					'status'          => 409,
					'refresh_command' => $refresh_command,
					'freshness'       => array(
						'status'     => 'refresh_required',
						'verified'   => false,
						'target_ref' => $target_ref,
					),
				)
			);
		}
		$freshness    = array(
			'verified'    => true,
			'evidence'    => $evidence,
			'identity'    => $identity,
			'target_ref'  => $target_ref,
			'target_head' => $identity['target_head'],
		);
		if ( ! $allow_stale && ! $rebase_base ) {
			$guard = $this->assert_ref_current_with_default_branch($primary_path, $target_ref, $repo, $branch, $exists_local ? 'branch' : 'base');
			if ( is_wp_error($guard) ) {
				return $this->worktree_plan_result($input, $handle, $path, $slug, 'stale', array(
					'freshness' => $freshness,
					'safety'    => array(
						'code'    => $guard->get_error_code(),
						'message' => $guard->get_error_message(),
					),
				));
			}
		}
		$demand_plan = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
		if ( $demand_plan instanceof \WP_Error ) {
			return $demand_plan;
		}
		if ( ! class_exists(WorktreeDemandCalibration::class) ) {
			require_once __DIR__ . '/WorktreeDemandCalibration.php';
		}
		$demand_plan = WorktreeDemandCalibration::forecast($repo, $demand_plan);
		$demand_plan['allow_percentage_byte_floor_exception'] = $allow_percentage_byte_floor_exception;
		$disk_budget = $this->inspect_worktree_capacity($repo, $branch, $force, $demand_plan);
		$candidates  = $this->worktree_reuse_candidates($repo, $task);
		$disposition = 'create';
		if ( 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			$disposition = 'capacity_blocked';
		} elseif ( array() !== $candidates && 'isolated' !== $reuse_policy ) {
			$disposition = 'owner_conflict';
		} elseif ( array() !== $candidates && ( null === WorktreeContextInjector::normalize_scalar_metadata_value($intent['purpose'] ?? null) || null === WorktreeContextInjector::normalize_scalar_metadata_value($intent['owner_run_ref'] ?? null) || WorktreeContextInjector::CLEANUP_POLICY_REMOVE_ON_SUCCESS !== ( $intent['cleanup_policy'] ?? null ) ) ) {
			$disposition = 'unsafe';
		}
		$legacy_handoff = $this->legacy_handoff_plan_for_candidates($repo, $candidates, $task, $intent, $inject_context, $bootstrap);
		if ( 'legacy_handoff_required' === ( $legacy_handoff['status'] ?? null ) ) {
			$disposition = 'legacy_handoff_required';
		}
		return $this->worktree_plan_result($input, $handle, $path, $slug, $disposition, array(
			'freshness'        => $freshness,
			'capacity'         => $disk_budget,
			'bootstrap_demand' => $demand_plan,
			'reuse_candidates' => $candidates,
			'ownership'        => $intent,
			'legacy_handoff'   => $legacy_handoff,
		));
	}

	/** Build a fail-closed handoff plan for an inspected exact destination. */
	private function legacy_handoff_plan( array $existing, string $repo, array $task, array $intent, bool $inject_context, bool $bootstrap ): array {
		$process_probe = $this->artifact_process_path_probe()->snapshot_for_paths(array( (string) ( $existing['path'] ?? '' ) ));
		$lock          = WorkspaceLockStore::active_lock('worktree-' . $repo, $repo);
		return LegacyWorktreeHandoff::plan($existing + array(
			'same_repository'   => true,
			'task_identity'     => $this->worktree_reuse_task_identity( (array) ( $existing['task'] ?? array() )),
			'verifiable'        => true,
			'locked'            => is_wp_error($lock) ? null : is_array($lock),
			'no_active_process' => 'available' === ( $process_probe['status'] ?? null ) && array() === (array) ( $process_probe['evidence'] ?? array() ),
		), array(
			'task_identity'  => $this->worktree_reuse_task_identity($task),
			'inject_context' => $inject_context,
			'bootstrap'      => $bootstrap,
		) + $intent);
	}

	/** Existing same-task listings lack process proof, so they remain plan-only vetoes. */
	private function legacy_handoff_plan_for_candidates( string $repo, array $candidates, array $task, array $intent, bool $inject_context, bool $bootstrap ): ?array {
		foreach ( $candidates as $candidate ) {
			$inspection = ! empty($candidate['handle']) ? $this->worktree_get( (string) $candidate['handle'], array(
				'include_status' => true,
				'include_disk'   => false,
			)) : null;
			if ( is_wp_error($inspection) || empty($inspection['worktrees'][0]) ) {
				continue;
			}
			$plan = $this->legacy_handoff_plan( (array) $inspection['worktrees'][0], $repo, $task, $intent, $inject_context, $bootstrap);
			if ( 'legacy_handoff_required' === ( $plan['status'] ?? null ) || ! empty($plan['vetoes']) ) {
				return $plan;
			}
		}
		return null;
	}

	/** Apply a previously reviewed plan only if the live replan is byte-for-byte identical. */
	public function worktree_apply_plan( array $plan ): array|\WP_Error {
		$expected = (string) ( $plan['digest'] ?? '' );
		$input    = (array) ( $plan['apply_intent'] ?? array() );
		if ( '' === $expected || array() === $input ) {
			return new \WP_Error('invalid_worktree_plan', 'A digest-addressed worktree plan with apply_intent is required.', array( 'status' => 400 ));
		}
		$current = $this->worktree_plan_request(WorktreeAllocationRequest::from_input($input));
		if ( is_wp_error($current) ) {
			return $current;
		}
		if ( ! hash_equals($expected, (string) ( $current['digest'] ?? '' )) || ! in_array($current['disposition'] ?? '', array( 'create', 'exact_reuse', 'adoptable' ), true) ) {
			return new \WP_Error('stale_worktree_plan', 'The worktree plan no longer matches live remote, capacity, ownership, or destination state.', array(
				'status'          => 409,
				'expected_digest' => $expected,
				'actual_digest'   => $current['digest'] ?? null,
				'disposition'     => $current['disposition'] ?? null,
				'changed_sections' => $this->worktree_plan_changed_sections($plan, $current),
			));
		}
		$result = $this->worktree_add_request(WorktreeAllocationRequest::from_input($input + array(
			'expected_freshness_identity' => (array) ( $plan['freshness']['identity'] ?? array() ),
		)));
		if ( is_wp_error($result) && 'stale_worktree_freshness' === $result->get_error_code() ) {
			$error_data = (array) $result->get_error_data();
			return new \WP_Error(
				'stale_worktree_plan',
				'The worktree plan no longer matches live remote freshness state.',
				array(
					'status'                      => 409,
					'expected_freshness_identity' => $plan['freshness']['identity'] ?? null,
					'actual_freshness_identity'   => $error_data['actual_freshness_identity'] ?? null,
				)
			);
		}
		return $result;
	}

	private const HANDOFF_REMOTE_PROBE_TIMEOUT = 25;
	private const HANDOFF_CONTINUATION_RESERVE_SECONDS = 2;

	/**
	 * Revalidate the exact server-issued proof with a bounded remote probe.
	 *
	 * A current result is a bounded observation for an immediate consumer to
	 * converge or refuse. It is not a cross-process lease across later external
	 * admission. One deadline covers lock acquisition, metadata lookup, fetch,
	 * and proof construction. Metadata storage cannot be interrupted, so an
	 * overdue lookup is refused before it can start a Git operation.
	 */
	public function worktree_handoff_revalidate( string $handle, array $proof ): array|\WP_Error {
		$deadline = microtime(true) + self::HANDOFF_REMOTE_PROBE_TIMEOUT;
		$parsed   = $this->parse_handle($handle);
		if ( ! $parsed['is_worktree'] || (string) ( $proof['handle'] ?? '' ) !== $handle ) {
			return new \WP_Error('invalid_worktree_handoff_proof', 'A matching managed worktree handoff proof is required.', array( 'status' => 400 ));
		}

		$result = WorkspaceMutationLock::with_repo($this->workspace_path, (string) $parsed['repo'], function () use ( $handle, $proof, $deadline ) {
			if ( $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
				return $this->worktree_handoff_timeout();
			}
			$metadata = WorktreeContextInjector::get_metadata_fresh($handle);
			if ( $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
				return $this->worktree_handoff_timeout();
			}
			$stored = is_array($metadata) ? (array) ( $metadata['handoff_freshness_proof'] ?? array() ) : array();
			if ( 3 !== (int) ( $stored['version'] ?? 0 ) || 3 !== (int) ( $proof['version'] ?? 0 ) || array() === $stored || ! hash_equals($this->worktree_handoff_proof_digest($stored), (string) ( $stored['digest'] ?? '' )) || ! hash_equals( (string) ( $stored['digest'] ?? '' ), (string) ( $proof['digest'] ?? '' )) || $this->worktree_handoff_proof_canonical_json($stored) !== $this->worktree_handoff_proof_canonical_json($proof) ) {
				return new \WP_Error('untrusted_worktree_handoff_proof', 'The supplied proof is not the active metadata-bound managed proof.', array( 'status' => 409 ));
			}
			$primary  = $this->get_primary_path( (string) $this->parse_handle($handle)['repo']);
			$base_ref = $this->worktree_handoff_base_ref($metadata);
			if ( is_wp_error($base_ref) ) {
				return $base_ref;
			}
			$fetch = WorktreeStalenessProbe::fetch($primary, null, $deadline, null, $base_ref);
			if ( empty($fetch['ok']) ) {
				return array(
					'success' => false,
					'status'  => 'fetch_failed',
					'handle'  => $handle,
					'fetch'   => $fetch,
				);
			}
			$current = $this->worktree_handoff_proof($handle, $this->workspace_path . '/' . $this->parse_handle($handle)['dir_name'], $primary, $base_ref, $deadline, (string) $stored['proof_id']);
			if ( is_wp_error($current) ) {
				return $current;
			}
			$drift = array();
			foreach ( array( 'worktree_sha', 'resolved_base_ref', 'resolved_base_sha', 'remote_default_ref', 'remote_default_sha' ) as $field ) {
				if ( ! hash_equals( (string) $proof[ $field ], (string) $current[ $field ]) ) {
					$drift[ $field ] = array(
						'expected' => $proof[ $field ],
						'actual'   => $current[ $field ],
					);
				}
			}
			return array(
				'success' => array() === $drift,
				'status'  => array() === $drift ? 'current' : 'drift',
				'handle'  => $handle,
				'proof'   => $current,
				'drift'   => $drift,
			);
		}, self::HANDOFF_REMOTE_PROBE_TIMEOUT);

		if ( is_wp_error($result) && 'workspace_repo_busy' === $result->get_error_code() ) {
			return array(
				'success'    => false,
				'status'     => 'contention',
				'handle'     => $handle,
				'contention' => $result->get_error_data(),
			);
		}
		return $result;
	}

	/** Resume only the read-only handoff boundary for one exact committed allocation. */
	public function worktree_handoff_resume( string $handle, array $allocation_identity ): array|\WP_Error {
		$deadline = microtime(true) + self::HANDOFF_REMOTE_PROBE_TIMEOUT;
		$parsed   = $this->parse_handle($handle);
		if ( ! $parsed['is_worktree']
			|| (string) ( $allocation_identity['handle'] ?? '' ) !== $handle
			|| 1 !== (int) ( $allocation_identity['version'] ?? 0 )
			|| ! hash_equals($this->worktree_handoff_allocation_identity_digest($allocation_identity), (string) ( $allocation_identity['digest'] ?? '' ))
		) {
			return new \WP_Error('invalid_worktree_handoff_allocation_identity', 'An exact server-issued committed allocation identity is required.', array( 'status' => 400 ));
		}

		$result = WorkspaceMutationLock::with_repo($this->workspace_path, (string) $parsed['repo'], function () use ( $handle, $parsed, $allocation_identity, $deadline ) {
			if ( $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
				return $this->worktree_handoff_resume_failure($this->worktree_handoff_timeout(), $allocation_identity);
			}
			$metadata        = WorktreeContextInjector::get_metadata_fresh($handle);
			$path            = $this->workspace_path . '/' . $parsed['dir_name'];
			$base_ref        = $this->worktree_handoff_base_ref($metadata);
			$readiness       = WorktreeContextInjector::bootstrap_readiness($metadata);
			$stored_identity = is_array($metadata) && is_array($metadata['handoff_continuation_identity'] ?? null) ? $metadata['handoff_continuation_identity'] : array();
			$stored_matches  = $this->worktree_handoff_proof_canonical_json($stored_identity) === $this->worktree_handoff_proof_canonical_json($allocation_identity);
			$binding_current = is_array($metadata)
				&& hash_equals($this->worktree_handoff_allocation_metadata_digest($metadata), (string) ( $allocation_identity['metadata_digest'] ?? '' ))
				&& ! is_wp_error($base_ref)
				&& hash_equals( (string) $base_ref, (string) ( $allocation_identity['resolved_base_ref'] ?? '' ) );
			if ( ! is_array($metadata)
				|| ! $stored_matches
				|| ! hash_equals( (string) ( $metadata['allocation_id'] ?? '' ), (string) ( $allocation_identity['allocation_id'] ?? '' ) )
				|| ! hash_equals( (string) ( $metadata['path'] ?? '' ), (string) ( $allocation_identity['path'] ?? '' ) )
				|| ! hash_equals($path, (string) ( $allocation_identity['path'] ?? '' ))
				|| ! hash_equals( (string) ( $metadata['branch'] ?? '' ), (string) ( $allocation_identity['branch'] ?? '' ) )
				|| is_wp_error($base_ref)
				|| ! $readiness['ready']
				|| ! is_dir($path)
				|| ! file_exists($path . '/.git')
			) {
				return $this->worktree_handoff_resume_failure(
					new \WP_Error(
						'worktree_handoff_allocation_drift',
						'The committed allocation no longer matches its exact handoff continuation identity.',
						array( 'status' => 409, 'readiness' => $readiness )
					),
					$allocation_identity
				);
			}

			$head   = $this->worktree_handoff_git($path, 'rev-parse --verify HEAD^{commit}', $deadline);
			$branch = $this->worktree_handoff_git($path, 'branch --show-current', $deadline);
			$status = $this->worktree_handoff_git($path, 'status --porcelain --untracked-files=all', $deadline);
			foreach ( array( $head, $branch, $status ) as $probe ) {
				if ( is_wp_error($probe) ) {
					return $this->worktree_handoff_resume_failure($probe, $allocation_identity);
				}
			}
			if ( ! hash_equals( (string) ( $allocation_identity['worktree_sha'] ?? '' ), trim( (string) $head['output'] ) )
				|| ! hash_equals( (string) ( $allocation_identity['branch'] ?? '' ), trim( (string) $branch['output'] ) )
				|| '' !== trim( (string) $status['output'] )
			) {
				return $this->worktree_handoff_resume_failure(
					new \WP_Error('worktree_handoff_allocation_drift', 'The committed allocation changed after handoff was deferred; refusing to issue a freshness observation.', array( 'status' => 409 )),
					$allocation_identity
				);
			}
			if ( ! $binding_current ) {
				if ( $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
					return $this->worktree_handoff_resume_unbound_failure($this->worktree_handoff_timeout(), $allocation_identity);
				}
				$refreshed = $this->worktree_bind_handoff_allocation_identity(array(
					'handle'           => $handle,
					'path'             => $path,
					'branch'           => (string) $metadata['branch'],
					'worktree_sha'     => trim( (string) $head['output']),
					'handoff_deadline' => $deadline,
				));
				if ( is_wp_error($refreshed) ) {
					return $this->worktree_handoff_resume_unbound_failure($refreshed, $allocation_identity);
				}
				return $this->worktree_handoff_resume_failure(
					new \WP_Error(
						'worktree_handoff_allocation_identity_refreshed',
						'The committed allocation metadata changed without changing the clean allocation; use the refreshed exact handoff continuation.',
						array( 'status' => 409, 'supplied_allocation_identity' => $allocation_identity )
					),
					$refreshed
				);
			}

			$proof = $this->worktree_handoff_proof($handle, $path, $this->get_primary_path( (string) $parsed['repo']), (string) $base_ref, $deadline, (string) $allocation_identity['digest']);
			if ( is_wp_error($proof) ) {
				return $this->worktree_handoff_resume_failure($proof, $allocation_identity);
			}
			$remote_base = $this->worktree_handoff_resume_remote_base_current($this->get_primary_path( (string) $parsed['repo']), (string) $base_ref, $proof, $deadline);
			if ( is_wp_error($remote_base) ) {
				return $this->worktree_handoff_resume_failure($remote_base, $allocation_identity);
			}
			$final_head   = $this->worktree_handoff_git($path, 'rev-parse --verify HEAD^{commit}', $deadline);
			$final_branch = $this->worktree_handoff_git($path, 'branch --show-current', $deadline);
			$final_status = $this->worktree_handoff_git($path, 'status --porcelain --untracked-files=all', $deadline);
			if ( is_wp_error($final_head) || is_wp_error($final_branch) || is_wp_error($final_status)
				|| ! hash_equals( (string) $allocation_identity['worktree_sha'], trim( (string) ( $final_head['output'] ?? '' ) ) )
				|| ! hash_equals( (string) $allocation_identity['branch'], trim( (string) ( $final_branch['output'] ?? '' ) ) )
				|| '' !== trim( (string) ( $final_status['output'] ?? '' ) )
			) {
				return $this->worktree_handoff_resume_failure(
					new \WP_Error('worktree_handoff_allocation_drift', 'The worktree branch, HEAD, or cleanliness changed during handoff continuation verification.', array( 'status' => 409 )),
					$allocation_identity
				);
			}

			return array(
				'success'             => true,
				'status'              => 'current',
				'handle'              => $handle,
				'mutation_committed'  => true,
				'allocation_identity' => $allocation_identity,
				'observation'         => $proof,
				'read_only'           => true,
			);
		}, self::HANDOFF_REMOTE_PROBE_TIMEOUT);

		if ( is_wp_error($result) && 'workspace_repo_busy' === $result->get_error_code() ) {
			return array(
				'success'             => false,
				'status'              => 'contention',
				'handle'              => $handle,
				'mutation_committed'  => true,
				'allocation_identity' => $allocation_identity,
				'continuation'        => $this->worktree_handoff_continuation($allocation_identity),
				'contention'          => $result->get_error_data(),
			);
		}
		return $result;
	}

	/** Issue the proof while the caller still holds the allocation's repository lock. */
	private function worktree_add_handoff_proof( array|\WP_Error $result, bool $allow_unverified_freshness = false, ?float $operation_deadline = null ): array|\WP_Error {
		if ( is_wp_error($result) || empty($result['success']) ) {
			return $result;
		}
		$continuation_deadline = min($operation_deadline ?? INF, microtime(true) + self::HANDOFF_REMOTE_PROBE_TIMEOUT);
		$deadline              = $continuation_deadline - self::HANDOFF_CONTINUATION_RESERVE_SECONDS;
		if ( $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
			$result['handoff_freshness'] = array(
				'status' => 'unverified',
				'reason' => 'worktree_handoff_revalidation_timeout',
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		if ( empty($result['handle']) || empty($result['path']) ) {
			$result['handoff_freshness'] = array(
				'status' => 'unverified',
				'reason' => 'allocation_identity_missing',
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		$primary  = $this->get_primary_path(explode('@', (string) $result['handle'], 2)[0]);
		$metadata = WorktreeContextInjector::get_metadata_fresh( (string) $result['handle']) ?? array();
		if ( $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
			$result['handoff_freshness'] = array(
				'status' => 'unverified',
				'reason' => 'worktree_handoff_revalidation_timeout',
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		$base_ref = $this->worktree_handoff_base_ref($metadata);
		if ( is_wp_error($base_ref) ) {
			$result['handoff_freshness'] = array(
				'status' => 'unverified',
				'reason' => $base_ref->get_error_code(),
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		$fetch    = WorktreeStalenessProbe::fetch($primary, null, $deadline, null, $base_ref);
		if ( empty($fetch['ok']) ) {
			$result['handoff_freshness'] = array(
				'status' => 'unverified',
				'reason' => 'fetch_failed',
				'fetch'  => $fetch,
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		$proof = $this->worktree_handoff_proof( (string) $result['handle'], (string) $result['path'], $primary, $base_ref, $deadline, bin2hex(random_bytes(16)));
		if ( is_wp_error($proof) ) {
			$reason                      = in_array($proof->get_error_code(), array( 'worktree_handoff_revalidation_timeout', 'remote_default_unresolved', 'worktree_handoff_base_unresolved' ), true ) ? $proof->get_error_code() : 'proof_generation_failed';
			$result['handoff_freshness'] = array(
				'status'     => 'unverified',
				'reason'     => $reason,
				'error_code' => $proof->get_error_code(),
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		$stored = WorktreeContextInjector::store_lifecycle_metadata( (string) $result['handle'], array( 'handoff_freshness_proof' => $proof ));
		if ( is_wp_error($stored) || ! $stored ) {
			$result['handoff_freshness'] = array(
				'status'     => 'unverified',
				'reason'     => 'metadata_persist_failed',
				'error_code' => is_wp_error($stored) ? $stored->get_error_code() : null,
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $continuation_deadline);
		}
		$result['metadata']          = array_merge($metadata, array( 'handoff_freshness_proof' => $proof ));
		$result['handoff_freshness'] = array(
			'status' => 'verified',
			'proof'  => $proof,
		);
		return $result;
	}

	/** Refuse downstream use after a mutation when its handoff proof is absent. */
	private function worktree_unverified_handoff_error( array $result, bool $allow_unverified_freshness, ?float $deadline = null ): array|\WP_Error {
		if ( $allow_unverified_freshness ) {
			return $result;
		}
		$allocation_identity = $this->worktree_bind_handoff_allocation_identity($result, $deadline);
		$continuation        = is_wp_error($allocation_identity) ? null : $this->worktree_handoff_continuation($allocation_identity);
		$message             = null === $continuation
			? 'The worktree allocation is committed but no verified handoff freshness proof or exact continuation is available. Do not repeat allocation or bootstrap; inspect the preserved allocation.'
			: 'The worktree allocation is committed but no verified handoff freshness proof is available. Do not repeat allocation or bootstrap; run the exact handoff continuation.';

		return new \WP_Error(
			'worktree_handoff_freshness_unverified',
			$message,
			array_filter(array(
				'status'                     => 409,
				'partial_success'            => true,
				'mutation_committed'         => true,
				'mutation_boundary'          => 'worktree_allocation_committed',
				'state'                      => 'allocation_committed_handoff_pending',
				'handle'                     => $result['handle'] ?? null,
				'path'                       => $result['path'] ?? null,
				'allocation'                 => $result,
				'allocation_identity'        => is_wp_error($allocation_identity) ? null : $allocation_identity,
				'handoff_freshness'          => $result['handoff_freshness'] ?? null,
				'allow_unverified_freshness' => false,
				'continuation'               => $continuation,
				'next_commands'              => null === $continuation ? array() : array( $continuation['command'] ),
				'retry'                      => array(
					'allocation_preserved' => true,
					'repeat_allocation'    => false,
					'repeat_bootstrap'     => false,
				),
			), static fn( $value ): bool => null !== $value)
		);
	}

	/** Bind and confirm the continuation against the final persisted allocation record. */
	private function worktree_bind_handoff_allocation_identity( array $result, ?float $deadline = null ): array|\WP_Error {
		$deadline = $deadline ?? ( isset($result['handoff_deadline']) ? (float) $result['handoff_deadline'] : null );
		$identity = $this->worktree_handoff_allocation_identity($result, $deadline);
		if ( is_wp_error($identity) ) {
			return $identity;
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			if ( null !== $deadline && $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
				return $this->worktree_handoff_timeout();
			}
			$bound = WorktreeContextInjector::store_lifecycle_metadata( (string) $identity['handle'], array( 'handoff_continuation_identity' => $identity ));
			if ( is_wp_error($bound) || ! $bound ) {
				return new \WP_Error('worktree_handoff_allocation_identity_persist_failed', 'The exact handoff continuation identity could not be persisted.', array( 'status' => 500 ));
			}

			$confirmation_result = $result;
			unset($confirmation_result['worktree_sha']);
			$confirmed = $this->worktree_handoff_allocation_identity($confirmation_result, $deadline);
			if ( is_wp_error($confirmed) ) {
				return $confirmed;
			}
			if ( $this->worktree_handoff_proof_canonical_json($confirmed) === $this->worktree_handoff_proof_canonical_json($identity) ) {
				$metadata = WorktreeContextInjector::get_metadata_fresh( (string) $identity['handle']);
				if ( null !== $deadline && $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
					return $this->worktree_handoff_timeout();
				}
				if ( is_array($metadata) && $this->worktree_handoff_proof_canonical_json($identity) === $this->worktree_handoff_proof_canonical_json( (array) ( $metadata['handoff_continuation_identity'] ?? array() )) ) {
					return $identity;
				}
			}
			$identity = $confirmed;
		}

		return new \WP_Error('worktree_handoff_allocation_identity_unstable', 'The committed allocation changed while its exact handoff continuation identity was being persisted.', array( 'status' => 409 ));
	}

	/** Bind a continuation to one allocation and its exact post-bootstrap HEAD. */
	private function worktree_handoff_allocation_identity( array $result, ?float $deadline = null ): array|\WP_Error {
		if ( null !== $deadline && $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
			return $this->worktree_handoff_timeout();
		}
		$handle   = (string) ( $result['handle'] ?? '' );
		$path     = (string) ( $result['path'] ?? '' );
		$metadata = WorktreeContextInjector::get_metadata_fresh($handle);
		$readiness = WorktreeContextInjector::bootstrap_readiness($metadata);
		if ( ! $readiness['ready'] ) {
			return new \WP_Error(
				'worktree_handoff_allocation_not_ready',
				'The committed allocation has not persisted terminal bootstrap readiness; refusing to issue a contradictory handoff continuation.',
				array( 'status' => 409, 'readiness' => $readiness )
			);
		}
		if ( is_array($metadata) && '' === (string) ( $metadata['allocation_id'] ?? '' ) ) {
			if ( null !== $deadline && $this->worktree_handoff_remaining_seconds($deadline) <= 0 ) {
				return $this->worktree_handoff_timeout();
			}
			$allocation_id = bin2hex(random_bytes(16));
			$stored        = WorktreeContextInjector::store_lifecycle_metadata($handle, array( 'allocation_id' => $allocation_id ));
			$metadata      = is_wp_error($stored) || ! $stored ? $metadata : WorktreeContextInjector::get_metadata_fresh($handle);
		}
		$base_ref = $this->worktree_handoff_base_ref($metadata);
		if ( '' === $handle || '' === $path || ! is_array($metadata) || '' === (string) ( $metadata['allocation_id'] ?? '' ) || '' === (string) ( $metadata['branch'] ?? '' ) || ! hash_equals( (string) ( $metadata['path'] ?? '' ), $path ) || is_wp_error($base_ref) ) {
			return new \WP_Error('worktree_handoff_allocation_identity_missing', 'The committed allocation lacks exact continuation identity metadata.', array( 'status' => 409 ));
		}
		$worktree_sha = (string) ( $result['worktree_sha'] ?? '' );
		if ( 1 !== preg_match('/^[0-9a-f]{40,64}$/D', $worktree_sha) ) {
			$head = null !== $deadline
				? $this->worktree_handoff_git($path, 'rev-parse --verify HEAD^{commit}', $deadline)
				: $this->run_git($path, 'rev-parse --verify HEAD^{commit}', self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( is_wp_error($head) ) {
				return $head;
			}
			$worktree_sha = trim( (string) ( $head['output'] ?? '' ));
		}

		$identity = array(
			'version'           => 1,
			'allocation_id'     => (string) $metadata['allocation_id'],
			'handle'            => $handle,
			'path'              => $path,
			'branch'            => (string) ( $metadata['branch'] ?? $result['branch'] ?? '' ),
			'worktree_sha'      => $worktree_sha,
			'resolved_base_ref' => (string) $base_ref,
			'metadata_digest'   => $this->worktree_handoff_allocation_metadata_digest($metadata),
		);
		$identity['digest'] = $this->worktree_handoff_allocation_identity_digest($identity);
		return $identity;
	}

	private function worktree_handoff_allocation_identity_digest( array $identity ): string {
		unset($identity['digest']);
		return hash('sha256', $this->worktree_handoff_proof_canonical_json($identity));
	}

	/** Bind continuation ownership without including the continuation itself. */
	private function worktree_handoff_allocation_metadata_digest( array $metadata ): string {
		return hash('sha256', $this->worktree_handoff_proof_canonical_json(array(
			'allocation_id'  => $metadata['allocation_id'] ?? null,
			'origin_task'    => $metadata['origin_task'] ?? null,
			'purpose'        => $metadata['purpose'] ?? null,
			'owner_run_ref'  => $metadata['owner_run_ref'] ?? null,
			'cleanup_policy' => $metadata['cleanup_policy'] ?? null,
			'reuse_contract' => $metadata['reuse_contract'] ?? null,
		)));
	}

	/** Verify a non-default remote base without mutating remote-tracking refs. */
	private function worktree_handoff_resume_remote_base_current( string $primary, string $base_ref, array $proof, float $deadline ): bool|\WP_Error {
		$prefix = 'refs/remotes/origin/';
		if ( ! str_starts_with($base_ref, $prefix) || $base_ref === (string) ( $proof['remote_default_ref'] ?? '' ) ) {
			return true;
		}
		$remote_ref = 'refs/heads/' . substr($base_ref, strlen($prefix));
		$remote     = $this->worktree_handoff_git($primary, 'ls-remote origin ' . escapeshellarg($remote_ref), $deadline);
		if ( is_wp_error($remote) ) {
			return $remote;
		}
		if ( ! preg_match('/^([0-9a-f]{40,64})\s+' . preg_quote($remote_ref, '/') . '$/mi', trim( (string) ( $remote['output'] ?? '' ) ), $matches)
			|| ! hash_equals(strtolower($matches[1]), strtolower( (string) ( $proof['resolved_base_sha'] ?? '' ) ))
		) {
			return new \WP_Error('worktree_handoff_base_changed', 'The remote base changed after allocation; refresh and create a new handoff observation.', array( 'status' => 409 ));
		}
		return true;
	}

	/** Return the one non-allocating continuation accepted for a committed allocation. */
	private function worktree_handoff_continuation( array $allocation_identity ): array {
		$encoded = wp_json_encode($allocation_identity, JSON_UNESCAPED_SLASHES);
		$input   = array(
			'handle'              => (string) $allocation_identity['handle'],
			'allocation_identity' => $allocation_identity,
		);
		return array(
			'operation' => 'worktree_handoff_resume',
			'ability'   => 'datamachine-code/workspace-worktree-handoff-resume',
			'input'     => $input,
			'command'   => 'studio wp datamachine-code workspace worktree handoff-resume ' . escapeshellarg( (string) $allocation_identity['handle']) . ' --allocation-identity=' . escapeshellarg(is_string($encoded) ? $encoded : '{}') . ' --format=json',
			'read_only' => true,
			'idempotent' => true,
		);
	}

	/** Preserve the prior committed mutation boundary when continuation refuses. */
	private function worktree_handoff_resume_failure( \WP_Error $error, array $allocation_identity ): \WP_Error {
		$data         = (array) $error->get_error_data();
		$continuation = $this->worktree_handoff_continuation($allocation_identity);
		return new \WP_Error($error->get_error_code(), $error->get_error_message(), array_merge($data, array(
			'partial_success'     => true,
			'mutation_committed'  => true,
			'mutation_boundary'   => 'worktree_allocation_committed',
			'state'               => 'allocation_committed_handoff_pending',
			'handle'              => $allocation_identity['handle'] ?? null,
			'path'                => $allocation_identity['path'] ?? null,
			'allocation_identity' => $allocation_identity,
			'continuation'        => $continuation,
			'next_commands'       => array( $continuation['command'] ),
		)));
	}

	/** Preserve the mutation boundary without repeating an identity that could not be rebound. */
	private function worktree_handoff_resume_unbound_failure( \WP_Error $error, array $allocation_identity ): \WP_Error {
		$data = (array) $error->get_error_data();
		return new \WP_Error($error->get_error_code(), $error->get_error_message(), array_merge($data, array(
			'partial_success'              => true,
			'mutation_committed'           => true,
			'mutation_boundary'            => 'worktree_allocation_committed',
			'state'                        => 'allocation_committed_handoff_pending',
			'handle'                       => $allocation_identity['handle'] ?? null,
			'path'                         => $allocation_identity['path'] ?? null,
			'supplied_allocation_identity' => $allocation_identity,
			'continuation'                 => null,
			'next_commands'                => array(),
		)));
	}

	private function worktree_handoff_proof( string $handle, string $path, string $primary, string $base_ref, float $deadline, string $proof_id ): array|\WP_Error {
		$remaining = $this->worktree_handoff_remaining_seconds($deadline);
		if ( $remaining <= 0 ) {
			return $this->worktree_handoff_timeout();
		}
		$remote_default = $this->worktree_handoff_remote_default($primary, $remaining);
		if ( is_wp_error($remote_default) ) {
			return $remote_default;
		}
		$remote_default_ref = $remote_default['ref'];
		$head               = $this->worktree_handoff_git($path, 'rev-parse --verify HEAD^{commit}', $deadline);
		$base               = $this->worktree_handoff_git($primary, 'rev-parse --verify ' . escapeshellarg($base_ref . '^{commit}'), $deadline);
		$default            = $this->worktree_handoff_git($primary, 'rev-parse --verify ' . escapeshellarg($remote_default_ref . '^{commit}'), $deadline);
		if ( is_wp_error($head) || is_wp_error($base) || is_wp_error($default) ) {
			return is_wp_error($head) ? $head : ( is_wp_error($base) ? $base : $default );
		}
		if ( ! hash_equals($remote_default['sha'], trim( (string) $default['output'] )) ) {
			return new \WP_Error('remote_default_changed_during_verification', 'The remote default branch changed after the bounded fetch. Retry to obtain a proof for one remote advertisement.', array( 'status' => 409 ));
		}
		$proof           = array(
			'version'                       => 3,
			'proof_id'                      => $proof_id,
			'handle'                        => $handle,
			'worktree_sha'                  => trim( (string) $head['output']),
			'resolved_base_ref'             => $base_ref,
			'resolved_base_sha'             => trim( (string) $base['output']),
			'remote_default_ref'            => $remote_default_ref,
			'remote_default_sha'            => $remote_default['sha'],
			'remote_default_advertised_sha' => $remote_default['sha'],
			'verified_at'                   => gmdate('c'),
		);
		$proof['digest'] = $this->worktree_handoff_proof_digest($proof);
		return $proof;
	}

	/** @return array{ref:string,sha:string}|\WP_Error */
	private function worktree_handoff_remote_default( string $primary, int $timeout_seconds ): array|\WP_Error {
		$remote = $this->run_git($primary, 'ls-remote --symref origin HEAD', $timeout_seconds);
		if ( is_wp_error($remote) ) {
			if ( $this->is_git_timeout_error($remote) ) {
				return $this->worktree_handoff_timeout();
			}
			return new \WP_Error('remote_default_unresolved', 'The remote default branch could not be verified. Check remote network, proxy, and credentials, then retry.', array( 'status' => 409 ));
		}
		$output = (string) ( $remote['output'] ?? '' );
		if ( ! preg_match('/^ref: refs\/heads\/([^\s]+)\s+HEAD$/m', $output, $ref_matches) || ! preg_match('/^([0-9a-f]{40,64})\s+HEAD$/mi', $output, $sha_matches) ) {
			return new \WP_Error('remote_default_unresolved', 'The remote did not advertise an unambiguous default branch and commit. Configure the remote HEAD or retry with an explicit base branch.', array( 'status' => 409 ));
		}
		return array(
			'ref' => 'refs/remotes/origin/' . $ref_matches[1],
			'sha' => strtolower($sha_matches[1]),
		);
	}

	/** Resolve metadata-dependent base state before starting a bounded Git probe. */
	private function worktree_handoff_base_ref( ?array $metadata ): string|\WP_Error {
		$base_ref = (string) ( $metadata['reuse_contract']['base_ref'] ?? '' );
		if ( 'existing_local_branch' === $base_ref ) {
			$base_ref = 'refs/heads/' . (string) ( $metadata['branch'] ?? '' );
		}
		if ( '' === $base_ref ) {
			return new \WP_Error('worktree_handoff_base_unresolved', 'The managed worktree has no resolved base ref.', array( 'status' => 409 ));
		}
		return $base_ref;
	}

	/** Never pass a zero timeout to GitRunner, where zero means unbounded. */
	private function worktree_handoff_git( string $path, string $arguments, float $deadline ): array|\WP_Error {
		$remaining = $this->worktree_handoff_remaining_seconds($deadline);
		if ( $remaining <= 0 ) {
			return $this->worktree_handoff_timeout();
		}
		return $this->run_git($path, $arguments, $remaining);
	}

	private function worktree_handoff_proof_digest( array $proof ): string {
		unset($proof['digest']);
		return hash('sha256', $this->worktree_handoff_proof_canonical_json($proof));
	}

	/** Canonical JSON makes proof object-key order irrelevant while retaining exact values. */
	private function worktree_handoff_proof_canonical_json( array $proof ): string {
		$encoded = wp_json_encode($this->worktree_handoff_proof_canonicalize($proof));
		return is_string($encoded) ? $encoded : '';
	}

	private function worktree_handoff_proof_canonicalize( mixed $value ): mixed {
		if ( ! is_array($value) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->worktree_handoff_proof_canonicalize($item);
		}
		if ( array_keys($value) !== range(0, count($value) - 1) ) {
			ksort($value, SORT_STRING);
		}
		return $value;
	}

	/** GitRunner accepts whole seconds, so refuse partial time rather than extend the deadline. */
	private function worktree_handoff_remaining_seconds( float $deadline ): int {
		return max(0, (int) floor($deadline - microtime(true)));
	}

	private function worktree_handoff_timeout(): \WP_Error {
		return new \WP_Error('worktree_handoff_revalidation_timeout', 'The bounded handoff remote probe has less than one safe Git execution second remaining.', array( 'status' => 409 ));
	}

	/** Apply a reviewed legacy handoff after re-planning and taking the repo lock. */
	public function worktree_apply_legacy_handoff( array $plan, string $mode ): array|\WP_Error {
		$expected = (string) ( $plan['digest'] ?? '' );
		$input    = (array) ( $plan['apply_intent'] ?? array() );
		if ( '' === $expected || ! in_array($mode, array( 'adopt_runtime', 'replace_isolated' ), true) ) {
			return new \WP_Error('invalid_legacy_handoff_plan', 'A digest-addressed legacy handoff plan and supported mode are required.', array( 'status' => 400 ));
		}
		if ( 'replace_isolated' === $mode && array() !== WorktreeContextInjector::missing_isolation_intent( (array) ( $input['intent'] ?? array() )) ) {
			return new \WP_Error('legacy_handoff_isolation_intent_required', 'An isolated replacement requires purpose, owner_run_ref, and cleanup_policy=remove_on_success before the old candidate can be superseded.', array( 'status' => 400 ));
		}
		$current = $this->worktree_plan_request(WorktreeAllocationRequest::from_input($input));
		if ( is_wp_error($current) || ! hash_equals($expected, (string) ( $current['digest'] ?? '' )) || 'legacy_handoff_required' !== ( $current['disposition'] ?? null ) ) {
			return new \WP_Error('stale_legacy_handoff_plan', 'The legacy handoff plan no longer has complete safety proof.', array(
				'status'  => 409,
				'current' => is_wp_error($current) ? $current->get_error_code() : $current['disposition'] ?? null,
			));
		}
		$handoff    = (array) ( $current['legacy_handoff'] ?? array() );
		$old_handle = (string) ( $handoff['candidate']['handle'] ?? '' );
		$repo       = (string) ( $input['repo'] ?? '' );
		$apply      = function () use ( $mode, $current, $input, $handoff, $old_handle ): array|\WP_Error {
			$metadata = WorktreeContextInjector::get_metadata($old_handle);
			if ( ! is_array($metadata) ) {
				return new \WP_Error('legacy_handoff_metadata_missing', 'Legacy handoff metadata disappeared before mutation.', array( 'status' => 409 ));
			}
			$lineage = (array) ( $handoff['lineage'] ?? array() ) + array(
				'handoff_at' => gmdate('c'),
				'mode'       => $mode,
			);
			if ( 'adopt_runtime' === $mode ) {
				$metadata['reuse_contract']['inject_context'] = ! empty($input['inject_context']);
				$metadata['reuse_contract']['bootstrap']      = ! empty($input['bootstrap']);
				foreach ( array( 'purpose', 'owner_run_ref', 'cleanup_policy' ) as $field ) {
					if ( array_key_exists($field, (array) ( $input['intent'] ?? array() )) ) {
						$metadata['reuse_contract'][ $field ] = $input['intent'][ $field ];
						$metadata[ $field ]                   = $input['intent'][ $field ];
					}
				}
				$metadata['handoff_lineage'] = array_merge( (array) ( $metadata['handoff_lineage'] ?? array() ), array( $lineage ));
				$stored                      = WorktreeContextInjector::store_lifecycle_metadata($old_handle, $metadata);
				if ( is_wp_error($stored) ) {
					return $stored;
				}
				return $this->worktree_add_handoff_proof(array(
					'success'  => true,
					'type'     => 'legacy_handoff',
					'mode'     => $mode,
					'handle'   => $old_handle,
					'path'     => (string) ( $handoff['candidate']['path'] ?? $metadata['path'] ?? '' ),
					'lineage'  => $lineage,
					'metadata' => WorktreeContextInjector::get_metadata_fresh($old_handle),
				), ! empty($input['allow_unverified_freshness']));
			}
			if ( (string) ( $current['handle'] ?? '' ) === $old_handle ) {
				return new \WP_Error('legacy_handoff_replacement_requires_new_handle', 'An isolated replacement must use a different worktree handle.', array( 'status' => 409 ));
			}
			$metadata['lifecycle_state'] = WorktreeContextInjector::STATE_ABANDONED;
			$metadata['handoff_lineage'] = array_merge( (array) ( $metadata['handoff_lineage'] ?? array() ), array( $lineage + array( 'terminal_classification' => 'superseded' ) ));
			$stored                      = WorktreeContextInjector::store_lifecycle_metadata($old_handle, $metadata);
			if ( is_wp_error($stored) ) {
				return $stored;
			}
			$result = $this->worktree_add_request(WorktreeAllocationRequest::from_input(array_merge($input, array(
				'reuse_policy' => 'isolated',
			))));
			if ( is_wp_error($result) ) {
				return $result;
			}
			return $result + array(
				'legacy_handoff' => array(
					'mode'                    => $mode,
					'old_handle'              => $old_handle,
					'terminal_classification' => 'superseded',
					'lineage'                 => $lineage,
				),
			);
		};
		return 'adopt_runtime' === $mode ? WorkspaceMutationLock::with_repo($this->workspace_path, $repo, $apply) : $apply();
	}

	/** @return array<string,mixed> */
	private function worktree_plan_result( array $input, string $handle, string $path, string $slug, string $disposition, array $evidence ): array {
		$plan           = array(
			'version'      => 1,
			'handle'       => $handle,
			'path'         => $path,
			'branch'       => $input['branch'],
			'slug'         => $slug,
			'disposition'  => $disposition,
			'apply_intent' => $input,
		) + $evidence;
		$digest_plan    = array(
			'version'          => $plan['version'],
			'handle'           => $handle,
			'path'             => $path,
			'branch'           => $input['branch'],
			'disposition'      => $disposition,
			'apply_intent'     => $input,
			'freshness'        => array(
				'verified'    => $plan['freshness']['verified'] ?? null,
				'identity'    => $plan['freshness']['identity'] ?? null,
				'target_ref'  => $plan['freshness']['target_ref'] ?? null,
				'target_head' => $plan['freshness']['target_head'] ?? null,
			),
			'capacity'         => $this->worktree_plan_capacity_identity((array) ( $plan['capacity'] ?? array() )),
			'bootstrap_demand' => $plan['bootstrap_demand'] ?? null,
			'destination'      => $plan['destination'] ?? null,
			'ownership'        => $plan['ownership'] ?? null,
			'reuse_candidates' => $plan['reuse_candidates'] ?? null,
			'legacy_handoff'   => $plan['legacy_handoff'] ?? null,
		);
		$digest_json    = wp_json_encode($this->worktree_plan_sort($digest_plan));
		$plan['digest'] = hash('sha256', is_string($digest_json) ? $digest_json : '');
		$plan['apply']  = array(
			'ability' => 'datamachine-code/workspace-worktree-apply-plan',
			'intent'  => array(
				'digest'       => $plan['digest'],
				'apply_intent' => $input,
			),
		);
		return $plan;
	}

	/** Bind the measured admission decision while tolerating bounded ambient capacity drift. */
	private function worktree_plan_capacity_identity( array $capacity ): array {
		$exception           = (array) ( $capacity['admission_exception'] ?? array() );
		$projected_exception = (array) ( $exception['projected_post_create_capacity'] ?? array() );
		$bind_measurements    = ! empty($exception['operator_intent']);
		if ( $bind_measurements && array() !== $projected_exception ) {
			$exception['projected_post_create_capacity'] = array(
				'free_bytes'  => $this->worktree_plan_capacity_measurement($projected_exception['free_bytes'] ?? null, 64 * 1024 * 1024),
				'free_inodes' => $this->worktree_plan_capacity_measurement($projected_exception['free_inodes'] ?? null, 1000000),
			);
		} else {
			unset($exception['projected_post_create_capacity']);
		}

		$identity = array(
			'status'                       => $capacity['status'] ?? null,
			'creation_allowed'             => $capacity['creation_allowed'] ?? null,
			'filesystem_total_bytes'       => $capacity['filesystem_total_bytes'] ?? null,
			'refuse_free_bytes'            => $capacity['refuse_free_bytes'] ?? null,
			'refuse_percent_bytes_floor'   => $capacity['refuse_percent_bytes_floor'] ?? null,
			'effective_refuse_bytes'       => $capacity['effective_refuse_bytes'] ?? null,
			'refuse_free_inodes'           => $capacity['refuse_free_inodes'] ?? null,
			'refuse_percent_inode_floor'   => $capacity['refuse_percent_inode_floor'] ?? null,
			'effective_refuse_inodes'      => $capacity['effective_refuse_inodes'] ?? null,
			'trigger_reasons'              => $capacity['trigger_reasons'] ?? null,
			'typed_trigger_reasons'        => $capacity['typed_trigger_reasons'] ?? null,
			'admission_exception'          => $exception,
			'force_override_required'      => $capacity['force_override_required'] ?? null,
			'force_override_applied'       => $capacity['force_override_applied'] ?? null,
			'worktree_count'               => $capacity['worktree_count'] ?? null,
		);
		if ( $bind_measurements ) {
			$identity['filesystem_free_bytes']  = $this->worktree_plan_capacity_measurement($capacity['filesystem_free_bytes'] ?? null, 64 * 1024 * 1024);
			$identity['projected_free_bytes']   = $this->worktree_plan_capacity_measurement($capacity['projected_free_bytes'] ?? null, 64 * 1024 * 1024);
			$identity['filesystem_free_inodes'] = $this->worktree_plan_capacity_measurement($capacity['filesystem_free_inodes'] ?? null, 1000000);
			$identity['projected_free_inodes']  = $this->worktree_plan_capacity_measurement($capacity['projected_free_inodes'] ?? null, 1000000);
		}
		return $identity;
	}

	private function worktree_plan_capacity_measurement( mixed $value, int $quantum ): mixed {
		if ( ! is_numeric($value) ) {
			return $value;
		}
		$value = (int) $value;
		return abs($value) < $quantum ? $value : intdiv($value, $quantum);
	}

	/** Identify normalized evidence sections that invalidated a reviewed plan. */
	private function worktree_plan_changed_sections( array $expected, array $actual ): array {
		$sections = array(
			'apply_intent'     => array( $expected['apply_intent'] ?? null, $actual['apply_intent'] ?? null ),
			'freshness'        => array(
				array_intersect_key((array) ( $expected['freshness'] ?? array() ), array_flip(array( 'verified', 'identity', 'target_ref', 'target_head' ))),
				array_intersect_key((array) ( $actual['freshness'] ?? array() ), array_flip(array( 'verified', 'identity', 'target_ref', 'target_head' ))),
			),
			'capacity'         => array( $this->worktree_plan_capacity_identity((array) ( $expected['capacity'] ?? array() )), $this->worktree_plan_capacity_identity((array) ( $actual['capacity'] ?? array() )) ),
			'bootstrap_demand' => array( $expected['bootstrap_demand'] ?? null, $actual['bootstrap_demand'] ?? null ),
			'destination'      => array( $expected['destination'] ?? null, $actual['destination'] ?? null ),
			'ownership'        => array( $expected['ownership'] ?? null, $actual['ownership'] ?? null ),
			'reuse_candidates' => array( $expected['reuse_candidates'] ?? null, $actual['reuse_candidates'] ?? null ),
			'legacy_handoff'   => array( $expected['legacy_handoff'] ?? null, $actual['legacy_handoff'] ?? null ),
		);

		return array_keys(array_filter($sections, static fn( array $pair ): bool => $pair[0] !== $pair[1]));
	}

	private function worktree_plan_sort( mixed $value ): mixed {
		if ( ! is_array($value) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->worktree_plan_sort($item);
		}
		if ( array_keys($value) !== range(0, count($value) - 1) ) {
			ksort($value);
		}
		return $value;
	}



	/** Execute worktree allocation from one explicit allocation contract. */
	public function worktree_add_request( WorktreeAllocationRequest $request ): array|\WP_Error {
		$repo                                  = $request->repo;
		$branch                                = $request->branch;
		$from                                  = $request->from;
		$inject_context                        = $request->inject_context;
		$bootstrap                             = $request->bootstrap;
		$allow_stale                           = $request->allow_stale;
		$rebase_base                           = $request->rebase_base;
		$force                                 = $request->force;
		$task                                  = $request->task;
		$allow_unverified_freshness            = $request->allow_unverified_freshness;
		$require_task_tracker                  = $request->require_task_tracker;
		$intent                                = $request->intent;
		$reuse_policy                          = $request->reuse_policy;
		$remediate_capacity                    = $request->remediate_capacity;
		$remediate_capacity_dry_run            = $request->remediate_capacity_dry_run;
		$progress_callback                     = is_callable($request->progress_callback) ? $request->progress_callback : null;
		$expected_freshness_identity           = $request->expected_freshness_identity;
		$allow_percentage_byte_floor_exception = $request->allow_percentage_byte_floor_exception;
		$visible                               = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		$repo   = $this->resolve_primary_repo_name($repo);
		$branch = trim($branch);
		if ( is_wp_error($repo) ) {
			return $repo;
		}

		if ( '' === $repo ) {
			return new \WP_Error('invalid_repo', 'Repository name is required.', array( 'status' => 400 ));
		}

		if ( '' === $branch ) {
			return new \WP_Error('invalid_branch', 'Branch name is required.', array( 'status' => 400 ));
		}

		$from         = null !== $from && '' !== trim($from) ? trim($from) : null;
		$task         = WorktreeContextInjector::resolve_task_metadata($task) ?? array();
		$reuse_policy = strtolower(trim($reuse_policy));
		if ( ! in_array($reuse_policy, WorktreeContextInjector::VALID_REUSE_POLICIES, true) ) {
			return new \WP_Error('invalid_worktree_reuse_policy', 'reuse_policy must be one of: ' . implode(', ', WorktreeContextInjector::VALID_REUSE_POLICIES) . '.', array( 'status' => 400 ));
		}
		if ( $force && ( $remediate_capacity || $allow_percentage_byte_floor_exception ) ) {
			return new \WP_Error('worktree_capacity_policy_conflict', '--force bypasses capacity admission; use it separately from --remediate-capacity and --allow-percentage-byte-floor.', array( 'status' => 400 ));
		}
		if ( $remediate_capacity && $allow_percentage_byte_floor_exception ) {
			return new \WP_Error('worktree_capacity_policy_conflict', '--allow-percentage-byte-floor admits a narrow exception; use it separately from --remediate-capacity.', array( 'status' => 400 ));
		}
		if ( $remediate_capacity_dry_run && ! $remediate_capacity ) {
			return new \WP_Error('worktree_capacity_remediation_dry_run_requires_remediation', 'Capacity remediation dry-run requires remediate_capacity=true.', array( 'status' => 400 ));
		}
		if ( array_key_exists('cleanup_policy', $intent) && null === WorktreeContextInjector::normalize_cleanup_policy($intent['cleanup_policy']) ) {
			return new \WP_Error('invalid_cleanup_policy', 'cleanup_policy must be one of: ' . implode(', ', WorktreeContextInjector::VALID_CLEANUP_POLICIES) . '.', array( 'status' => 400 ));
		}
		$intent = WorktreeContextInjector::normalize_disposable_intent($intent);
		if ( $require_task_tracker && empty($task) ) {
			return new \WP_Error(
				'worktree_task_tracker_required',
				'Refusing to create a managed worktree without a valid task URL or task reference. Supply task_url or task_ref, or use an operator-local creation path that does not require a tracker.',
				array( 'status' => 400 )
			);
		}
		$retry_request = array(
			'repo'                       => $repo,
			'branch'                     => $branch,
			'from'                       => $from,
			'inject_context'             => $inject_context,
			'bootstrap'                  => $bootstrap,
			'allow_stale'                => $allow_stale,
			'allow_unverified_freshness' => $allow_unverified_freshness,
			'rebase_base'                => $rebase_base,
			'force'                      => $force,
			'remediate_capacity'         => $remediate_capacity,
			'remediate_capacity_dry_run' => $remediate_capacity_dry_run,
			'allow_percentage_byte_floor_exception' => $allow_percentage_byte_floor_exception,
			'task'                       => $task,
			'require_task_tracker'       => $require_task_tracker,
			'intent'                     => $intent,
			'reuse_policy'               => $reuse_policy,
		);

		$slug = $this->slugify_branch($branch);
		if ( '' === $slug ) {
			return new \WP_Error('invalid_branch', sprintf('Branch "%s" produced an empty slug.', $branch), array( 'status' => 400 ));
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! GitCheckout::exists($primary_path) ) {
			return new \WP_Error('primary_not_found', sprintf('Primary checkout for "%s" does not exist. Clone it first.', $repo), array( 'status' => 404 ));
		}

		$wt_handle = $repo . '@' . $slug;
		$wt_path   = $this->workspace_path . '/' . $wt_handle;

		if ( $remediate_capacity_dry_run ) {
			return $this->worktree_capacity_dry_run($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent, $reuse_policy, $wt_handle, $primary_path, $allow_percentage_byte_floor_exception);
		}

		// A remediation dry-run must reach capacity planning before any existing
		// handle path can reset a terminal checkout or rewrite its metadata.
		if ( is_dir($wt_path) && ! $remediate_capacity_dry_run ) {
			if ( 'recycle_terminal' === $reuse_policy ) {
				return $this->decorate_worktree_add_lock_contention(WorkspaceMutationLock::with_repo($this->workspace_path, $repo, fn() => $this->worktree_add_handoff_proof($this->recycle_terminal_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $primary_path), $allow_unverified_freshness), 30, array(), $progress_callback), $retry_request);
			}
			if ( 'claim_expired' === $reuse_policy ) {
				return $this->decorate_worktree_add_lock_contention(WorkspaceMutationLock::with_repo($this->workspace_path, $repo, fn() => $this->worktree_add_handoff_proof($this->claim_expired_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $primary_path), $allow_unverified_freshness), 30, array(), $progress_callback), $retry_request);
			}
			$reuse  = fn() => WorkspaceMutationLock::with_repo($this->workspace_path, $repo, fn() => $this->worktree_add_handoff_proof($this->reuse_existing_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $reuse_policy, $primary_path), $allow_unverified_freshness), 30, array(), $progress_callback);
			$reused = $bootstrap
				? WorkspaceMutationLock::with_repo($this->workspace_path, 'workspace-capacity-admission', $reuse, self::worktree_capacity_admission_timeout_seconds(true), array(), $progress_callback)
				: $reuse();
			if ( is_wp_error($reused) || ! $bootstrap || empty($reused['bootstrap_deferred']) ) {
				return $this->decorate_worktree_add_lock_contention($reused, $retry_request);
			}
			return $this->complete_resumed_bootstrap($reused);
		}

		$operation_timeout  = self::worktree_capacity_operation_timeout_seconds($bootstrap);
		$operation_started  = microtime(true);
		$operation_deadline = $operation_started + $operation_timeout;
		$capacity_timeout   = self::worktree_capacity_admission_wait_seconds($operation_deadline, null, $bootstrap);
		if ( $capacity_timeout <= 0 ) {
			return $this->worktree_operation_timeout('capacity_lock_wait', $operation_timeout, $operation_started);
		}

		// Fetch and demand planning only touch this primary. Keep them out of the
		// global capacity critical section so unrelated repositories can prepare in
		// parallel; capacity-changing checkout remains globally fenced. Bootstrap
		// demand is reserved durably before its child processes run without locks.
		$this->worktree_add_progress($progress_callback, 'repo_preflight');
		$preflight = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			fn() => $this->worktree_capacity_preflight($primary_path, $repo, $branch, $from, $bootstrap, $operation_deadline, $progress_callback),
			$capacity_timeout,
			array( '_acquisition_deadline' => $operation_deadline ),
			$progress_callback
		);
		$preflight = $this->worktree_operation_lock_result($preflight, 'repo_preflight_lock_wait', $operation_timeout, $operation_started);
		if ( is_wp_error($preflight) ) {
			return $this->decorate_worktree_add_lock_contention($preflight, $retry_request);
		}

		// Preflight shares the operation budget. Recompute before joining the
		// global queue so a slow fetch/plan cannot receive a second full wait.
		$capacity_timeout = self::worktree_capacity_admission_wait_seconds($operation_deadline, null, $bootstrap);
		if ( $capacity_timeout <= 0 ) {
			return $this->worktree_operation_timeout('capacity_lock_wait', $operation_timeout, $operation_started);
		}

		$this->worktree_add_progress($progress_callback, 'capacity_lock_wait');
		$locked = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			'workspace-capacity-admission',
			fn( WorkspaceMutationLock $capacity_lock ) => $this->worktree_add_with_capacity_lock(
				$repo,
				$branch,
				$from,
				$inject_context,
				$bootstrap,
				$allow_stale,
				$rebase_base,
				$force,
				$task,
				$allow_unverified_freshness,
				$intent,
				$slug,
				$wt_handle,
				$wt_path,
				$primary_path,
				$reuse_policy,
				$remediate_capacity,
				$remediate_capacity_dry_run,
				$operation_deadline,
				$operation_timeout,
				$operation_started,
				$preflight,
				$capacity_lock,
				$progress_callback,
				$expected_freshness_identity,
				$allow_percentage_byte_floor_exception,
				$retry_request
			),
			$capacity_timeout,
			array(
				'_acquisition_deadline'       => $operation_deadline,
				'lease_duration_seconds'     => $operation_timeout,
				'operation_timeout_seconds'  => $operation_timeout,
				'aggregate_timeout_seconds'  => self::worktree_capacity_aggregate_timeout_seconds($bootstrap),
				'operation_requested_at'     => gmdate('c', (int) floor($operation_started)),
				'lease_strategy'             => 'acquisition_bounded',
			),
			$progress_callback
		);

		$locked = $this->worktree_operation_lock_result($locked, 'capacity_lock_wait', $operation_timeout, $operation_started);
		if ( is_wp_error($locked) ) {
			return $this->decorate_worktree_add_lock_contention($locked, $retry_request);
		}
		if ( ! empty($locked['bootstrap_noop_completed']) ) {
			$this->worktree_add_progress($progress_callback, 'bootstrap_start');
			$this->worktree_add_progress($progress_callback, 'bootstrap_complete');
			unset($locked['bootstrap_noop_completed']);
		}

		// Carry the acquired deadline across with_repo(), then strip the internal
		// field before either deferred bootstrap or the public response can see it.
		if ( isset($locked['_capacity_operation_deadline']) ) {
			$operation_deadline = (float) $locked['_capacity_operation_deadline'];
			$operation_started  = $operation_deadline - $operation_timeout;
			unset($locked['_capacity_operation_deadline']);
		}
		$result = ! $bootstrap || empty($locked['bootstrap_deferred'])
			? $locked
			: $this->complete_deferred_bootstrap($locked, $repo, $branch, $operation_deadline, $operation_timeout, $operation_started, $progress_callback);
		if ( is_wp_error($result) ) {
			return $result;
		}

		// The proof is the consumer handoff boundary, so issue it only after the
		// complete allocation lifecycle, including any deferred bootstrap, finishes.
		$proof_timeout = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $proof_timeout <= 0 ) {
			return $this->worktree_add_handoff_proof($result, $allow_unverified_freshness, $operation_deadline);
		}
		$proof = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			fn() => $this->worktree_add_handoff_proof($result, $allow_unverified_freshness, $operation_deadline),
			$proof_timeout,
			array( '_acquisition_deadline' => $operation_deadline ),
			$progress_callback
		);
		if ( is_wp_error($proof) && 'workspace_repo_busy' === $proof->get_error_code() ) {
			$result['handoff_freshness'] = array(
				'status'     => 'unverified',
				'reason'     => 'handoff_proof_lock_wait',
				'contention' => $proof->get_error_data(),
			);
			return $this->worktree_unverified_handoff_error($result, $allow_unverified_freshness, $operation_deadline);
		}
		return $proof;
	}

	/** Prepare repo-local freshness and projected demand before global admission. */
	private function worktree_capacity_preflight( string $primary_path, string $repo, string $branch, ?string $from, bool $bootstrap, float $operation_deadline, ?callable $progress_callback = null ): array|\WP_Error {
		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$proof        = $this->primary_freshness_proof_for_ref($primary_path, $repo, $target_ref);
		if ( null !== $proof ) {
			$this->worktree_add_progress($progress_callback, 'freshness_proof_reused');
			$demand_plan = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
			if ( $demand_plan instanceof \WP_Error ) {
				return $demand_plan;
			}
			if ( ! class_exists(WorktreeDemandCalibration::class) ) {
				require_once __DIR__ . '/WorktreeDemandCalibration.php';
			}
			return array(
				'fetch'        => array( 'ok' => true, 'attempts' => 0, 'attempted_transports' => array( 'registered_remote' ), 'successful_transport' => 'registered_remote', 'transport_fallback_used' => false, 'proof_reused' => $proof ),
				'exists_local' => $exists_local,
				'target_ref'   => $target_ref,
				'demand_plan'  => WorktreeDemandCalibration::forecast($repo, $demand_plan),
			);
		}
		$this->worktree_add_progress($progress_callback, 'freshness_fetch');
		$fetch = WorktreeStalenessProbe::fetch($primary_path, null, $operation_deadline, null, $from);
		if ( ! $fetch['ok'] ) {
			if ( ! empty($fetch['missing_remote_ref']) ) {
				$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
				$target_ref   = $exists_local ? 'refs/heads/' . $branch : (string) $from;
				$demand_plan  = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
				if ( $demand_plan instanceof \WP_Error ) {
					return array(
						'fetch'        => $fetch,
						'exists_local' => $exists_local,
						'target_ref'   => $target_ref,
						'demand_plan'  => $demand_plan,
					);
				}
			}
			return array( 'fetch' => $fetch );
		}

		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$this->worktree_add_progress($progress_callback, 'demand_planning');
		$demand_plan = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
		if ( $demand_plan instanceof \WP_Error ) {
			// Preserve the existing capacity-path wrapper for explicit missing bases;
			// it adds detected default-ref evidence and a replayable retry command.
			return array(
				'fetch'        => $fetch,
				'exists_local' => $exists_local,
				'target_ref'   => $target_ref,
				'demand_plan'  => $demand_plan,
			);
		}
		if ( ! class_exists(WorktreeDemandCalibration::class) ) {
			require_once __DIR__ . '/WorktreeDemandCalibration.php';
		}

		return array(
			'fetch'        => $fetch,
			'exists_local' => $exists_local,
			'target_ref'   => $target_ref,
			'demand_plan'  => WorktreeDemandCalibration::forecast($repo, $demand_plan),
		);
	}

	/**
	 * Preview capacity and bounded cleanup strictly from local, read-only state.
	 *
	 * In particular, this deliberately avoids remote fetches and mutation locks:
	 * lock acquisition records durable request/lifecycle evidence, which a preview
	 * must not create.
	 */
	private function worktree_capacity_dry_run( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force, array $task, array $intent, string $reuse_policy, string $wt_handle, string $primary_path, bool $allow_percentage_byte_floor_exception ): array|\WP_Error {
		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = $exists_local
			? 'refs/heads/' . $branch
			: ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$demand_plan  = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
		if ( $demand_plan instanceof \WP_Error ) {
			return $demand_plan;
		}

		$demand_plan['allow_percentage_byte_floor_exception'] = $allow_percentage_byte_floor_exception;
		$disk_budget          = $this->inspect_worktree_capacity($repo, $branch, $force, $demand_plan);
		$capacity_remediation = 'refused' === ( $disk_budget['status'] ?? '' )
			? $this->remediate_capacity_refusal($repo, $branch, $demand_plan, $disk_budget, true)
			: null;
		if ( isset($capacity_remediation['failure']) ) {
			$failure = (array) $capacity_remediation['failure'];
			return new \WP_Error('worktree_capacity_remediation_failed', (string) ( $failure['message'] ?? 'Bounded capacity remediation preview failed.' ), array(
				'status'               => 507,
				'failure'              => $failure,
				'capacity_remediation' => $capacity_remediation,
			));
		}

		return array(
			'success'              => true,
			'dry_run'              => true,
			'created'              => false,
			'handoff_freshness'    => array(
				'status' => 'not_applicable',
				'reason' => 'non_allocation_dry_run',
			),
			'handle'               => $wt_handle,
			'branch'               => $branch,
			'disk_budget'          => $disk_budget,
			'capacity_reclaim'     => array(
				'attempted'   => false,
				'skip_reason' => 'remediation_dry_run',
			),
			'capacity_remediation' => $capacity_remediation ?? array(
				'mode'    => 'not_required',
				'dry_run' => true,
				'before'  => $disk_budget,
				'after'   => $disk_budget,
			),
			'add_intent'           => $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy),
		);
	}

	/**
	 * Resolve the explicit global-capacity lock wait budget.
	 *
	 * Lock order is always global capacity first, then the repository lock. The
	 * global lock remains held through checkout and durable bootstrap reservation.
	 * Later admissions include that reservation while dependency children run
	 * without inheriting this lock descriptor.
	 */
	public static function worktree_capacity_wait_timeout_seconds( bool $bootstrap = true ): int {
		$timeout = self::worktree_capacity_operation_timeout_seconds($bootstrap) + 60;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_capacity_wait_timeout_seconds', $timeout, $bootstrap);
		}

		return max(1, $timeout);
	}

	/** Return the bounded wait used to turn capacity contention into an observable result. */
	public static function worktree_capacity_admission_timeout_seconds( bool $bootstrap = true ): int {
		$timeout = self::worktree_capacity_wait_timeout_seconds($bootstrap);
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_capacity_admission_timeout_seconds', $timeout, $bootstrap);
		}

		return max(1, $timeout);
	}

	/** Bound capacity-lock admission by both the operation deadline and its wait cap. */
	private static function worktree_capacity_admission_wait_seconds( float $operation_deadline, ?float $now = null, bool $bootstrap = true ): int {
		$now       = $now ?? microtime(true);
		$remaining = max(0, (int) ceil($operation_deadline - $now));

		return min($remaining, self::worktree_capacity_admission_timeout_seconds($bootstrap));
	}

	/** Resolve the aggregate deadline covering create, rebase, and bootstrap. */
	public static function worktree_capacity_operation_timeout_seconds( bool $bootstrap = true ): int {
		$timeout = $bootstrap ? WorktreeBootstrapper::total_timeout_seconds() + 540 : 540;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_capacity_operation_timeout_seconds', $timeout, $bootstrap);
		}
		return max(1, $timeout);
	}

	/** Declare the maximum pre-admission plus acquisition-bounded lifecycle time. */
	public static function worktree_capacity_aggregate_timeout_seconds( bool $bootstrap = true ): int {
		$operation = self::worktree_capacity_operation_timeout_seconds($bootstrap);
		return ( 2 * $operation ) + 1;
	}

	/**
	 * Inspect, create, and reserve bootstrap demand while holding the workspace-
	 * wide capacity lock. A later admission includes the durable reservation while
	 * the dependency process runs outside mutation lock boundaries.
	 */
	private function worktree_add_with_capacity_lock(
		string $repo,
		string $branch,
		?string $from,
		bool $inject_context,
		bool $bootstrap,
		bool $allow_stale,
		bool $rebase_base,
		bool $force,
		array $task,
		bool $allow_unverified_freshness,
		array $intent,
		string $slug,
		string $wt_handle,
		string $wt_path,
		string $primary_path,
		string $reuse_policy = 'reuse_compatible',
		bool $remediate_capacity = false,
		bool $remediate_capacity_dry_run = false,
		?float $operation_deadline = null,
		int $operation_timeout = 0,
		float $operation_started = 0.0,
		array $preflight = array(),
		?WorkspaceMutationLock $capacity_lock = null,
		?callable $progress_callback = null,
		array $expected_freshness_identity = array(),
		bool $allow_percentage_byte_floor_exception = false,
		array $retry_request = array()
	): array|\WP_Error {
		$operation_timeout  = $operation_timeout > 0 ? $operation_timeout : self::worktree_capacity_operation_timeout_seconds($bootstrap);
		$operation_started  = $operation_started > 0.0 ? $operation_started : microtime(true);
		$operation_deadline = $operation_deadline ?? ( $operation_started + $operation_timeout );
		$lease_deadline     = $capacity_lock?->lease_deadline();
		if ( null !== $lease_deadline ) {
			$operation_deadline = (float) $lease_deadline;
			$operation_started  = $operation_deadline - $operation_timeout;
		}
		$deadline_error     = $this->worktree_operation_deadline_error('freshness', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		$this->worktree_add_progress($progress_callback, 'capacity_admitted');
		$heartbeat_error = $this->worktree_capacity_lock_heartbeat($capacity_lock, 'capacity_admitted', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $heartbeat_error ) {
			return $heartbeat_error;
		}
		if ( is_dir($wt_path) && ! $remediate_capacity_dry_run ) {
			// Capacity is already held. Take the repo lock once for reuse and proof
			// issuance; do not re-enter the capacity lock from this callback.
			$repo_timeout = $this->worktree_operation_remaining_seconds($operation_deadline);
			if ( $repo_timeout <= 0 ) {
				return $this->worktree_operation_timeout('repo_lock_wait', $operation_timeout, $operation_started);
			}
			$reused = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$repo,
				fn() => $this->worktree_add_handoff_proof($this->reuse_existing_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $reuse_policy, $primary_path), $allow_unverified_freshness, $operation_deadline),
				$repo_timeout,
				array( '_acquisition_deadline' => $operation_deadline ),
				$progress_callback
			);
			if ( is_array($reused) ) {
				$reused['_capacity_operation_deadline'] = $operation_deadline;
			}
			return $reused;
		}
		// The workspace capacity lock serializes admission. The target repo lock is
		// acquired only for final creation, so remediation can safely take per-repo
		// cleanup locks without self-deadlocking.
		$reuse_candidates = $this->worktree_reuse_candidates($repo, $task);
		$candidate_actions = array( 'candidates' => array(), 'actions' => array() );
		if ( array() !== $reuse_candidates ) {
			if ( ! class_exists(WorktreeCandidateActions::class) ) {
				require_once __DIR__ . '/WorktreeCandidateActions.php';
			}
			$candidate_actions = WorktreeCandidateActions::project($reuse_candidates, $repo, $branch, $from, $task, $intent);
		}
		if ( null !== $from && '' !== trim($from) && ! GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch) && ! GitRunner::ref_exists($primary_path, trim($from)) ) {
			$invalid_demand_plan = WorktreeBootstrapper::demand_plan_for_target($primary_path, trim($from), $bootstrap);
			if ( $invalid_demand_plan instanceof \WP_Error && 'worktree_target_ref_invalid' === $invalid_demand_plan->get_error_code() ) {
				return $this->worktree_missing_explicit_base_error($invalid_demand_plan, $primary_path, $repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent);
			}
		}
		if ( array() !== $reuse_candidates && 'isolated' !== $reuse_policy ) {
			return $this->worktree_reuse_refused(
				$wt_handle,
				'same_task_candidate_requires_explicit_isolation',
				array(
					'reuse_policy'            => $reuse_policy,
					'canonical_task_identity' => $this->worktree_reuse_task_identity($task),
					'candidates'               => $candidate_actions['candidates'],
					'candidate_evidence_limit' => WorktreeContextInjector::SAME_TASK_CANDIDATE_EVIDENCE_LIMIT,
					'candidate_actions'       => $candidate_actions['actions'],
				) + WorktreeContextInjector::same_task_isolation_refusal($retry_request)
			);
		}
		if ( array() !== $reuse_candidates && 'isolated' === $reuse_policy ) {
			$missing_intent = WorktreeContextInjector::missing_isolation_intent($intent);
			if ( array() !== $missing_intent ) {
				return $this->worktree_reuse_refused(
					$wt_handle,
					'same_task_isolation_intent_required',
					array(
						'reuse_policy'            => $reuse_policy,
						'canonical_task_identity' => $this->worktree_reuse_task_identity($task),
						'missing_intent'          => $missing_intent,
						'candidates'               => $candidate_actions['candidates'],
						'candidate_evidence_limit' => WorktreeContextInjector::SAME_TASK_CANDIDATE_EVIDENCE_LIMIT,
					) + WorktreeContextInjector::same_task_isolation_refusal($retry_request)
				);
			}
		}
		$exists_local = array_key_exists('exists_local', $preflight) ? (bool) $preflight['exists_local'] : GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = (string) ( $preflight['target_ref'] ?? ( $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) ) ) );
		$demand_plan  = $preflight['demand_plan'] ?? null;
		if ( $demand_plan instanceof \WP_Error ) {
			if ( 'worktree_target_ref_invalid' === $demand_plan->get_error_code() && ! $exists_local && null !== $from && '' !== trim($from) ) {
				return $this->worktree_missing_explicit_base_error($demand_plan, $primary_path, $repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent);
			}
			return $demand_plan;
		}

		$fetch                 = (array) ( $preflight['fetch'] ?? WorktreeStalenessProbe::fetch($primary_path, null, $operation_deadline) );
		$fetch_failed          = ! $fetch['ok'];
		$fetch_error           = $fetch['error'] ?? null;
		$fetch_attempts        = (int) ( $fetch['attempts'] ?? 1 );
		$fetch_timed_out       = ! empty($fetch['timed_out']);
		$fetch_timeout_seconds = $fetch['timeout_seconds'] ?? null;
		$fetch_transport       = array(
			'attempted_transports'    => array_values((array) ( $fetch['attempted_transports'] ?? array( 'configured' ) )),
			'successful_transport'    => $fetch['successful_transport'] ?? null,
			'transport_fallback_used' => ! empty($fetch['transport_fallback_used']),
			'fallback_preflight_code' => $fetch['fallback_preflight_code'] ?? null,
		);
		if ( $fetch_timed_out && $this->worktree_operation_remaining_seconds($operation_deadline) <= 0 ) {
			return $this->worktree_operation_timeout('freshness', $operation_timeout, $operation_started, array( 'fetch' => $fetch ));
		}
		if ( $fetch_failed && ! $allow_unverified_freshness ) {
			return new \WP_Error(
				'worktree_freshness_unverified',
				sprintf("Refusing to create worktree because remote freshness could not be verified after %d fetch attempt(s).\nGit fetch stderr:\n%s\nRefresh the primary and retry with the safe commands below. Use allow_unverified_freshness=true only when intentionally working offline with stale local refs.", $fetch_attempts, (string) $fetch_error),
				array(
					'status'                     => 409,
					'fetch_failed'               => true,
					'fetch_error'                => $fetch_error,
					'fetch_attempts'             => $fetch_attempts,
					'fetch_timed_out'            => $fetch_timed_out,
					'fetch_timeout_seconds'      => $fetch_timeout_seconds,
					'freshness_transport'        => array_filter($fetch_transport, static fn( mixed $value ): bool => null !== $value),
					'allow_unverified_freshness' => false,
					'next_commands'              => array_values(array_filter(array(
						$this->primary_refresh_command($repo),
						$this->worktree_freshness_retry_command($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent),
					))),
				)
			);
		}

		if ( array() !== $expected_freshness_identity ) {
			$actual_freshness_identity = $this->primary_freshness_identity($primary_path, $target_ref);
			if ( $expected_freshness_identity !== $actual_freshness_identity ) {
				return new \WP_Error(
					'stale_worktree_freshness',
					'The reviewed freshness identity changed after apply refreshed remote refs.',
					array(
						'status'                      => 409,
						'expected_freshness_identity' => $expected_freshness_identity,
						'actual_freshness_identity'   => $actual_freshness_identity,
					)
				);
			}
		}
		$demand_plan ??= WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
		if ( $demand_plan instanceof \WP_Error ) {
			if ( 'worktree_target_ref_invalid' === $demand_plan->get_error_code() && ! $exists_local && null !== $from && '' !== trim($from) ) {
				return $this->worktree_missing_explicit_base_error(
					$demand_plan,
					$primary_path,
					$repo,
					$branch,
					$from,
					$inject_context,
					$bootstrap,
					$allow_stale,
					$rebase_base,
					$force,
					$task,
					$intent
				);
			}
			return $demand_plan;
		}
		if ( ! isset($preflight['demand_plan']) ) {
			if ( ! class_exists(WorktreeDemandCalibration::class) ) {
				require_once __DIR__ . '/WorktreeDemandCalibration.php';
			}
			$demand_plan = WorktreeDemandCalibration::forecast($repo, $demand_plan);
		}
		$demand_plan['allow_percentage_byte_floor_exception'] = $allow_percentage_byte_floor_exception;
		$deadline_error = $this->worktree_operation_deadline_error('demand_disk_planning', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		$heartbeat_error = $this->worktree_capacity_lock_heartbeat($capacity_lock, 'capacity_reclaim', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $heartbeat_error ) {
			return $heartbeat_error;
		}
		$disk_budget      = $this->inspect_worktree_capacity($repo, $branch, $force, $demand_plan);
		$capacity_reclaim = ( $remediate_capacity || $remediate_capacity_dry_run )
			? array(
				'after'    => $disk_budget,
				'evidence' => array(
					'attempted'   => false,
					'skip_reason' => $remediate_capacity_dry_run ? 'remediation_dry_run' : 'remediation_preview_then_apply',
				),
			)
			: $this->reclaim_capacity_eligible_artifacts($repo, $branch, $force, $demand_plan, $disk_budget);
		$deadline_error   = $this->worktree_operation_deadline_error('demand_disk_planning', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		$disk_budget     = $capacity_reclaim['after'];
		$heartbeat_error = $this->worktree_capacity_lock_heartbeat($capacity_lock, 'capacity_reclaim_complete', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $heartbeat_error ) {
			return $heartbeat_error;
		}
		$capacity_remediation = null;
		if ( $remediate_capacity && 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			$capacity_remediation                     = $this->remediate_capacity_refusal($repo, $branch, $demand_plan, $disk_budget, $remediate_capacity_dry_run);
			$capacity_remediation['artifact_reclaim'] = $capacity_reclaim['evidence'];
			if ( isset($capacity_remediation['failure']) ) {
				$failure = (array) $capacity_remediation['failure'];
				return new \WP_Error(
					'worktree_capacity_remediation_failed',
					(string) ( $failure['message'] ?? 'Bounded capacity remediation failed before worktree creation.' ),
					array(
						'status'               => 507,
						'failure'              => $failure,
						'capacity_reclaim'     => $capacity_reclaim['evidence'],
						'capacity_remediation' => $capacity_remediation,
						'add_intent'           => $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy),
					)
				);
			}
			$disk_budget = $capacity_remediation['after'];
		}
		$deadline_error = $this->worktree_operation_deadline_error('demand_disk_planning', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		if ( $remediate_capacity_dry_run ) {
			return array(
				'success'              => true,
				'dry_run'              => true,
				'created'              => false,
				'handoff_freshness'    => array(
					'status' => 'not_applicable',
					'reason' => 'non_allocation_dry_run',
				),
				'handle'               => $wt_handle,
				'branch'               => $branch,
				'disk_budget'          => $disk_budget,
				'capacity_reclaim'     => $capacity_reclaim['evidence'],
				'capacity_remediation' => $capacity_remediation ?? array(
					'mode'    => 'not_required',
					'dry_run' => true,
					'before'  => $disk_budget,
					'after'   => $disk_budget,
				),
				'add_intent'           => $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy),
			);
		}
		if ( 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			$reclaim_evidence = (array) $capacity_reclaim['evidence'];
			$recommendations  = array_map(
				static function ( $row ): string {
					$commands     = array_filter(
						array(
							'preview' => (string) ( $row['preview_command'] ?? $row['command'] ?? '' ),
							'apply'   => (string) ( $row['apply_command'] ?? '' ),
						)
					);
					$command_text = implode(
						'; ',
						array_map(
							static fn( string $label, string $command ): string => sprintf('%s: %s', $label, $command),
							array_keys($commands),
							array_values($commands)
						)
					);

					return sprintf(
						'%d. %s: %s (capacity recovery target: %s; inodes: %s; candidates are measured by the command)',
						(int) ( $row['priority'] ?? 0 ),
						(string) ( $row['action'] ?? 'cleanup' ),
						$command_text,
						(string) ( $row['target_recovery'] ?? 'unknown' ),
						null === ( $row['target_recovery_inodes'] ?? null ) ? 'unknown' : number_format( (int) $row['target_recovery_inodes'] )
					);
				},
				(array) ( $disk_budget['cleanup_recommendations'] ?? array() )
			);
			if ( 'no_actionable_rows' === ( $reclaim_evidence['actionability_status'] ?? '' ) ) {
				$force_retry = $this->worktree_freshness_retry_command($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, true, $task, $intent);
				$recommendations = array(
					sprintf(
						'1. Automatic safe artifact recovery found 0 actionable rows (0 B); gross inspected candidate bytes were %s. The capacity recovery target is not a reclaim forecast.',
						WorktreeDiskBudget::format_bytes_for_operator( (int) ( $reclaim_evidence['gross_candidate_bytes'] ?? 0 ))
					),
					null !== $force_retry ? sprintf(
						'2. If a human accepts this one worktree\'s projected demand of %s, retry only this request with --force: %s',
						WorktreeDiskBudget::format_bytes_for_operator( (int) ( $disk_budget['projected_demand_bytes'] ?? 0 )),
						$force_retry
					) : '2. Retry receipt suppressed because the task URL contains credential-bearing userinfo; remove credentials before retrying.',
					'3. If a capacity exception is not approved, run bounded metadata reconciliation and a fresh DB-backed replan; it returns an apply command only for currently actionable rows: studio wp datamachine-code workspace worktree capacity-recovery --limit=25 --until-budget=30s --format=json',
				);
			}
			return new \WP_Error(
				'worktree_disk_budget_exceeded',
				sprintf(
					"Refusing to create worktree before bootstrap/install because the workspace capacity budget is unsafe.\n%s\nByte threshold: keep at least %.1f GiB free and %.1f%% free; effective floor is %.1f GiB.\nInode threshold: keep at least %s free and %.1f%% free; effective floor is %s.\nRecommended cleanup, in order:\n%s\nRetry with --force only when a human explicitly accepts the capacity risk.",
					WorktreeDiskBudget::format_summary($disk_budget),
					(float) ( $disk_budget['refuse_free_gib'] ?? 0 ),
					(float) ( $disk_budget['refuse_free_percent'] ?? 0 ),
					(float) ( $disk_budget['effective_refuse_gib'] ?? 0 ),
					number_format( (int) ( $disk_budget['refuse_free_inodes'] ?? 0 ) ),
					(float) ( $disk_budget['refuse_free_inode_percent'] ?? 0 ),
					number_format( (int) ( $disk_budget['effective_refuse_inodes'] ?? 0 ) ),
					implode("\n", array_filter($recommendations))
				),
				array(
					'status'               => 507,
					'disk_budget'          => $disk_budget,
					'capacity_reclaim'     => $capacity_reclaim['evidence'],
					'capacity_remediation' => $capacity_remediation,
					'add_intent'           => $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy),
				)
			);
		}

		$repo_timeout = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $repo_timeout <= 0 ) {
			return $this->worktree_operation_timeout('repo_lock_wait', $operation_timeout, $operation_started);
		}
		$heartbeat_error = $this->worktree_capacity_lock_heartbeat($capacity_lock, 'worktree_create', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $heartbeat_error ) {
			return $heartbeat_error;
		}
		$response = WorkspaceMutationLock::with_repo($this->workspace_path, $repo, fn() => $this->worktree_add_locked(
				$repo,
				$branch,
				$from,
				$inject_context,
				$allow_stale,
				$rebase_base,
				$slug,
				$wt_handle,
				$wt_path,
				$primary_path,
				$bootstrap,
				$task,
				$allow_unverified_freshness,
				$intent,
				array(
					'fetch_failed'          => $fetch_failed,
					'fetch_error'           => $fetch_error,
					'fetch_attempts'        => $fetch_attempts,
					'fetch_timed_out'       => $fetch_timed_out,
					'fetch_timeout_seconds' => $fetch_timeout_seconds,
					'freshness_transport'   => array_filter($fetch_transport, static fn( mixed $value ): bool => null !== $value),
					'exists_local'          => $exists_local,
					'target_ref'            => $target_ref,
					'operation_deadline'    => $operation_deadline,
					'operation_timeout'     => $operation_timeout,
					'operation_started'     => $operation_started,
				), $progress_callback), $repo_timeout, array( '_acquisition_deadline' => $operation_deadline ), $progress_callback);
		$response = $this->worktree_operation_lock_result($response, 'repo_lock_wait', $operation_timeout, $operation_started);

		if ( is_wp_error($response) ) {
			return $response;
		}
		$heartbeat_error = $this->worktree_capacity_lock_heartbeat($capacity_lock, 'worktree_create_complete', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $heartbeat_error ) {
			return $heartbeat_error;
		}
		if ( ! empty($response['rebase_cleanup_failed']) ) {
			return new \WP_Error(
				'worktree_rebase_cleanup_failed',
				'Rebase failed and its cleanup could not be verified; refusing bootstrap until the worktree is repaired.',
				array(
					'status' => 500,
					'path'   => $wt_path,
					'rebase' => $response,
				)
			);
		}

		$response['disk_budget']      = $disk_budget;
		$response['capacity_reclaim'] = $capacity_reclaim['evidence'];
		$response['_capacity_operation_deadline'] = $operation_deadline;
		$measurement_plan             = $demand_plan;
		if ( is_array($capacity_remediation) ) {
			$response['capacity_remediation'] = $capacity_remediation;
			$response['capacity_retry']       = array(
				'disposition' => 'retried_once_admitted',
				'attempts'    => 1,
			);
		}
		if ( array() !== $reuse_candidates ) {
			$response['reuse_candidates'] = $reuse_candidates;
		}
		if ( ! empty($response['rebase_succeeded']) ) {
			$this->worktree_add_progress($progress_callback, 'post_rebase_demand_planning');
			$post_rebase_demand = WorktreeBootstrapper::demand_plan_for_target($wt_path, 'HEAD', $bootstrap);
			if ( $post_rebase_demand instanceof \WP_Error ) {
				$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
				return $post_rebase_demand;
			}
			$post_rebase_demand = WorktreeDemandCalibration::forecast($repo, $post_rebase_demand);
			$measurement_plan   = $post_rebase_demand;
			$post_rebase_demand = WorktreeBootstrapper::remaining_demand_after_materialization($post_rebase_demand);
			$post_rebase_demand['allow_percentage_byte_floor_exception'] = $allow_percentage_byte_floor_exception;
			$this->worktree_add_progress($progress_callback, 'post_rebase_capacity_inspection');
			$post_rebase_budget = $this->inspect_worktree_capacity($repo, $branch, $force, $post_rebase_demand);
			$this->worktree_add_progress($progress_callback, 'post_rebase_artifact_reclamation');
			$post_rebase_capacity_reclaim             = $this->reclaim_capacity_eligible_artifacts(
				$repo,
				$branch,
				$force,
				$post_rebase_demand,
				$post_rebase_budget
			);
			$post_rebase_budget                       = $post_rebase_capacity_reclaim['after'];
			$response['post_rebase_disk_budget']      = $post_rebase_budget;
			$response['post_rebase_capacity_reclaim'] = $post_rebase_capacity_reclaim['evidence'];
			$response['disk_budget']                  = $post_rebase_budget;
			if ( 'refused' === ( $post_rebase_budget['status'] ?? '' ) ) {
				$rollback_evidence = $this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
				WorktreeContextInjector::forget_metadata($wt_handle);
				return new \WP_Error(
					'worktree_disk_budget_exceeded',
					'Refusing dependency bootstrap because the effective post-rebase target exceeds the workspace capacity budget.',
					array(
						'status'            => 507,
						'disk_budget'       => $post_rebase_budget,
						'capacity_reclaim'  => $post_rebase_capacity_reclaim['evidence'],
						'capacity_evidence' => $rollback_evidence,
						'phase'             => 'post_rebase_admission',
					)
				);
			}
		}

		if ( $bootstrap ) {
			$bootstrap_before_capacity = $this->inspect_worktree_capacity($repo, $branch, false, array());
			$remaining_seconds         = $this->worktree_operation_remaining_seconds($operation_deadline);
			if ( $remaining_seconds <= 0 ) {
				$recorded = $this->record_bootstrap_outcome($wt_handle, 'failed', array(), 'operation_timeout');
				if ( is_wp_error($recorded) ) {
					return $recorded;
				}
				return $this->worktree_operation_timeout('bootstrap', $operation_timeout, $operation_started, array( 'readiness' => 'incomplete' ));
			}
			$reservation = WorktreeBootstrapper::remaining_demand_after_materialization($measurement_plan);
			if ( $this->worktree_bootstrap_plan_is_noop($measurement_plan) ) {
				$response['bootstrap'] = WorktreeBootstrapper::bootstrap($wt_path, $remaining_seconds);
				$response              = $this->record_completed_bootstrap($response);
				if ( is_wp_error($response) ) {
					return $response;
				}
				$after_capacity                       = $this->inspect_worktree_capacity($repo, $branch, false, array());
				$response['capacity_evidence']        = WorktreeDemandCalibration::record_bootstrap($repo, $measurement_plan, $bootstrap_before_capacity, $after_capacity, true);
				$response['bootstrap_noop_completed'] = true;
			} else {
				$recorded = $this->record_bootstrap_outcome($wt_handle, 'running', array(), null, $reservation);
				if ( is_wp_error($recorded) ) {
					return $recorded;
				}
				$response['bootstrap_deferred']    = true;
				$response['bootstrap_reservation'] = $reservation;
			}
		}
		if ( ! is_dir($wt_path) || ! file_exists($wt_path . '/.git') ) {
			return new \WP_Error(
				'worktree_not_materialized',
				sprintf('Git reported worktree "%s" was added at %s, but the checkout is not accessible after creation.', $wt_handle, $wt_path),
				array(
					'status' => 500,
					'handle' => $wt_handle,
					'path'   => $wt_path,
				)
			);
		}
		if ( $bootstrap ) {
			if ( empty($response['bootstrap_noop_completed']) ) {
				$response['bootstrap_capacity_before']  = $bootstrap_before_capacity;
				$response['bootstrap_measurement_plan'] = $measurement_plan;
			}
		} else {
			$response['capacity_evidence'] = array(
				'outcome'  => 'bootstrap_disabled',
				'recorded' => false,
				'reason'   => 'bootstrap_disabled',
			);
		}

		$deadline_error = $this->worktree_operation_deadline_error('metadata', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
			WorktreeContextInjector::forget_metadata($wt_handle);
			return $deadline_error;
		}
		$this->worktree_add_progress($progress_callback, 'inventory_metadata');
		$inventory = $this->worktree_inventory();
		$persisted = $inventory->upsert($this->build_worktree_inventory_row_from_handle($wt_handle));
		if ( ! $persisted ) {
			$inventory_error = $inventory->last_error();
			if ( $inventory_error instanceof \WP_Error ) {
				if ( 'workspace_sqlite_lock_contention' === $inventory_error->get_error_code() ) {
					return $this->worktree_post_create_registry_error($inventory_error, $wt_handle, $wt_path, 'inventory_metadata');
				}
				$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
				WorktreeContextInjector::forget_metadata($wt_handle);
				return $inventory_error;
			}
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
			WorktreeContextInjector::forget_metadata($wt_handle);

			return new \WP_Error(
				'worktree_inventory_persist_failed',
				sprintf('Worktree "%s" was created but could not be persisted to the workspace inventory; rolled back the checkout instead of reporting success.', $wt_handle),
				array(
					'status' => 500,
					'handle' => $wt_handle,
					'path'   => $wt_path,
				)
			);
		}

		$this->emit_workspace_changed('worktree_add', $repo, $wt_handle, $wt_path);

		return $response;
	}

	/** Whether target-tree planning proves bootstrap has no dependency work. */
	private function worktree_bootstrap_plan_is_noop( array $plan ): bool {
		$counts = (array) ( $plan['counts'] ?? array() );
		return 0 === (int) ( $counts['submodules'] ?? 0 )
			&& 0 === (int) ( $counts['package_roots'] ?? 0 )
			&& 0 === (int) ( $counts['composer_roots'] ?? 0 );
	}

	/** Renew the capacity lease at observable lifecycle boundaries only. */
	private function worktree_capacity_lock_heartbeat( ?WorkspaceMutationLock $lock, string $phase, float $operation_deadline, int $operation_timeout, float $operation_started ): ?\WP_Error {
		if ( null === $lock ) {
			return null;
		}
		if ( $this->worktree_operation_remaining_seconds($operation_deadline) <= 0 ) {
			return $this->worktree_operation_timeout($phase, $operation_timeout, $operation_started);
		}
		$renewed = $lock->heartbeat(array(
			'expected_release_at' => gmdate('c', (int) ceil($operation_deadline)),
			'capacity_phase'      => $phase,
		));
		if ( is_wp_error($renewed) ) {
			return $renewed;
		}
		if ( false === $renewed ) {
			return new \WP_Error(
				'workspace_capacity_lock_heartbeat_lost',
				sprintf('The workspace capacity lock DB lease is no longer active during %s (OS ownership %s, deadline %s, timeout %d); refusing to continue allocation without current ownership evidence.', str_replace('_', ' ', $phase), $lock->is_active() ? 'active' : 'inactive', gmdate('c', (int) ceil($operation_deadline)), $operation_timeout),
				array(
					'status'             => 423,
					'retryable'          => true,
					'phase'              => $phase,
					'ownership'          => $lock->lease_evidence(),
					'operation_timeout'  => $operation_timeout,
					'operation_started'  => $operation_started,
					'operation_deadline' => gmdate('c', (int) ceil($operation_deadline)),
				)
			);
		}
		return null;
	}

	/** Add an exact allocation receipt to fail-closed admission or durable post-create contention. */
	private function decorate_worktree_add_lock_contention( mixed $result, array $request ): mixed {
		if ( ! is_wp_error($result) ) {
			return $result;
		}
		$data = (array) $result->get_error_data();
		$admission_blocked = 'workspace_lock_register' === ( $data['operation'] ?? null )
			&& false === ( $data['lock_callback_started'] ?? null )
			&& empty($data['lock_callback_completed']);
		$post_create      = 'workspace_sqlite_lock_contention' === $result->get_error_code()
			&& ! empty($data['creation_identity_persisted'])
			&& ! empty($data['mutation_committed']);
		$admission_timeout = 'worktree_operation_timeout' === $result->get_error_code()
			&& str_ends_with((string) ( $data['phase'] ?? '' ), '_lock_wait')
			&& false === ( $data['admission']['mutation_committed'] ?? null );
		if ( empty($data['retryable']) || ( ! $admission_blocked && ! $post_create && ! $admission_timeout ) ) {
			return $result;
		}

		$command = $this->worktree_add_retry_command($request);
		if ( null === $command ) {
			unset($data['retry_command']);
		} else {
			$data['retry_command'] = $command;
		}
		$result->add_data($data);
		return $result;
	}

	/** Preserve replay identity when SQLite blocks metadata after Git creation. */
	private function worktree_post_create_registry_error( \WP_Error $error, string $handle, string $path, string $phase ): \WP_Error {
		$data = array_merge(
			(array) $error->get_error_data(),
			array(
				'handle'                      => $handle,
				'path'                        => $path,
				'blocker_phase'               => $phase,
				'creation_identity_persisted' => true,
				'mutation_committed'          => true,
				'reconciliation'              => 'retry_same_allocation',
			)
		);
		return new \WP_Error($error->get_error_code(), 'Worktree creation completed and its durable identity is awaiting SQLite registry reconciliation.', $data);
	}

	/** Build a safe command from a normalized worktree allocation request. */
	private function worktree_add_retry_command( array $request ): ?string {
		$task = (array) ( $request['task'] ?? array() );
		if ( isset($task['task_url']) ) {
			$task_url = TaskUrl::canonicalize_for_replay($task['task_url']);
			if ( null === $task_url ) {
				return null;
			}
			$task['task_url'] = $task_url;
		}
		$parts = array(
			'wp datamachine-code workspace worktree add',
			escapeshellarg( (string) $request['repo']),
			escapeshellarg( (string) $request['branch']),
		);
		if ( null !== ( $request['from'] ?? null ) ) {
			$parts[] = '--from=' . escapeshellarg( (string) $request['from']);
		}
		if ( empty($request['inject_context']) ) {
			$parts[] = '--skip-context-injection';
		}
		if ( empty($request['bootstrap']) ) {
			$parts[] = '--skip-bootstrap';
		}
		foreach ( array(
			'allow_stale'                => '--allow-stale',
			'allow_unverified_freshness' => '--allow-unverified-freshness',
			'rebase_base'                => '--rebase-base',
			'force'                      => '--force',
			'remediate_capacity'         => '--remediate-capacity',
			'remediate_capacity_dry_run' => '--remediate-capacity-dry-run',
		) as $key => $flag ) {
			if ( ! empty($request[ $key ]) ) {
				$parts[] = $flag;
			}
		}
		$reuse_policy = (string) ( $request['reuse_policy'] ?? 'reuse_compatible' );
		if ( 'reuse_compatible' !== $reuse_policy ) {
			$parts[] = '--reuse-policy=' . escapeshellarg($reuse_policy);
		}
		foreach ( array(
			'task_url' => 'task-url',
			'task_ref' => 'task-ref',
		) as $key => $flag ) {
			if ( ! empty($task[ $key ]) ) {
				$parts[] = '--' . $flag . '=' . escapeshellarg( (string) $task[ $key ]);
			}
		}
		if ( ! empty($request['require_task_tracker']) ) {
			$parts[] = '--require-task-tracker';
		}
		$intent = (array) ( $request['intent'] ?? array() );
		foreach ( array(
			'purpose'        => 'purpose',
			'owner_run_ref'  => 'owner-run-ref',
			'cleanup_policy' => 'cleanup-policy',
		) as $key => $flag ) {
			if ( ! empty($intent[ $key ]) ) {
				$parts[] = '--' . $flag . '=' . escapeshellarg( (string) $intent[ $key ]);
			}
		}
		return implode(' ', $parts);
	}

	/** Build a safe, task-preserving retry command after freshness verification fails. */
	private function worktree_freshness_retry_command( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force, array $task, array $intent ): ?string {
		return $this->worktree_add_retry_command(array(
			'repo'                 => $repo,
			'branch'               => $branch,
			'from'                 => null !== $from && '' !== trim($from) ? trim($from) : null,
			'inject_context'       => $inject_context,
			'bootstrap'            => $bootstrap,
			'allow_stale'          => $allow_stale,
			'rebase_base'          => $rebase_base,
			'force'                => $force,
			'task'                 => $task,
			'require_task_tracker' => ! empty($task),
			'intent'               => $intent,
		));
	}

	/**
	 * Add default-base evidence to an invalid explicit-ref error without replacing it.
	 *
	 * Fetch failures return before ref resolution, so this path means freshness was
	 * verified and only the requested ref could not be resolved locally.
	 */
	private function worktree_missing_explicit_base_error( \WP_Error $error, string $primary_path, string $repo, string $branch, string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force, array $task, array $intent ): \WP_Error {
		$default                      = $this->detect_workspace_default_base($primary_path);
		$data                         = (array) $error->get_error_data();
		$data['requested_ref']        = trim($from);
		$data['detected_default_ref'] = $default['ref'];
		$data['default_ref_source']   = $default['source'];
		$retry_command                = null === $default['ref'] ? null : $this->worktree_freshness_retry_command($repo, $branch, $default['ref'], $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent);
		$data['next_commands']        = null === $retry_command ? array() : array( $retry_command );

		$message = $error->get_error_message();
		if ( null !== $retry_command ) {
			$message .= sprintf(' The configured default ref is "%s". Retry with: %s', $default['ref'], $retry_command);
		} elseif ( null !== $default['ref'] ) {
			$message .= sprintf(' The configured default ref is "%s". Retry receipt suppressed because the task URL contains credential-bearing userinfo.', $default['ref']);
		} else {
			$message .= ' Remote default metadata is unavailable. Inspect the configured upstream or remote HEAD, then retry with an explicit --from ref.';
		}

		return new \WP_Error($error->get_error_code(), $message, $data);
	}

	/**
	 * Detect a replayable default base from remote metadata or the primary's upstream.
	 *
	 * @return array{ref: string|null, source: 'remote_head'|'workspace_upstream'|'unavailable'}
	 */
	private function detect_workspace_default_base( string $repo_path ): array {
		$remote_head = $this->resolve_remote_default_ref($repo_path);
		if ( is_wp_error($remote_head) ) {
			$remote_head = null;
		}
		$remote_prefix = 'refs/remotes/origin/';
		if ( null !== $remote_head && str_starts_with($remote_head, $remote_prefix) && strlen($remote_head) > strlen($remote_prefix) && GitRunner::ref_exists($repo_path, $remote_head) ) {
			return array(
				'ref'    => substr($remote_head, strlen('refs/remotes/')),
				'source' => 'remote_head',
			);
		}

		$upstream = $this->run_git($repo_path, 'rev-parse --abbrev-ref --symbolic-full-name @{upstream}');
		if ( ! is_wp_error($upstream) ) {
			$ref = trim( (string) ( $upstream['output'] ?? '' ) );
			if ( '' !== $ref ) {
				return array(
					'ref'    => $ref,
					'source' => 'workspace_upstream',
				);
			}
		}

		return array(
			'ref'    => null,
			'source' => 'unavailable',
		);
	}


	/**
	 * Create a worktree while the primary repo lifecycle lock is held.
	 *
	 * @param  string      $repo           Primary repo name.
	 * @param  string      $branch         Branch to check out.
	 * @param  string|null $from           Base ref when creating the branch.
	 * @param  bool        $inject_context Whether to inject site-agent context.
	 * @param  bool        $allow_stale    Bypass the staleness gate.
	 * @param  bool        $rebase_base    Rebase onto upstream after creation.
	 * @param  string      $slug           Branch slug.
	 * @param  string      $wt_handle      Worktree handle.
	 * @param  string      $wt_path        Worktree path.
	 * @param  string      $primary_path   Primary checkout path.
	 * @param  bool        $bootstrap      Whether dependency bootstrap was requested.
	 * @param  array       $task           Optional task metadata recorded on the worktree.
	 * @param  bool        $allow_unverified_freshness Bypass fetch-failure freshness verification.
	 * @return array|\WP_Error
	 */
	private function worktree_add_locked(
		string $repo,
		string $branch,
		?string $from,
		bool $inject_context,
		bool $allow_stale,
		bool $rebase_base,
		string $slug,
		string $wt_handle,
		string $wt_path,
		string $primary_path,
		bool $bootstrap,
		array $task = array(),
		bool $allow_unverified_freshness = false,
		array $intent = array(),
		array $preflight = array(),
		?callable $progress_callback = null
	): array|\WP_Error {
		if ( is_dir($wt_path) ) {
			return new \WP_Error('worktree_exists', sprintf('Worktree handle "%s" already exists.', $wt_handle), array( 'status' => 400 ));
		}

		// Always fetch first so staleness data (and the default base) reflects the
		// current remote. If fetch fails, default to fail-closed unless the caller
		// explicitly opts into unverified/offline freshness.
		$fetch_failed          = ! empty($preflight['fetch_failed']);
		$fetch_error           = $preflight['fetch_error'] ?? null;
		$fetch_timed_out       = ! empty($preflight['fetch_timed_out']);
		$fetch_timeout_seconds = $preflight['fetch_timeout_seconds'] ?? null;
		$freshness_transport   = (array) ( $preflight['freshness_transport'] ?? array() );

		// Does the branch already exist locally?
		$exists_local   = ! empty($preflight['exists_local']);
		$created_branch = false;
		$resolved_base  = null;

		if ( $exists_local ) {
			if ( ! $allow_stale && ! $rebase_base && ! $fetch_failed ) {
				$default_guard = $this->assert_ref_current_with_default_branch($primary_path, $branch, $repo, $branch, 'branch');
				if ( is_wp_error($default_guard) ) {
					return $default_guard;
				}
			}
			$cmd = sprintf('worktree add %s %s', escapeshellarg($wt_path), escapeshellarg($branch));
		} else {
			$base          = (string) ( $preflight['target_ref'] ?? ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) ) );
			$resolved_base = $base;
			if ( ! $allow_stale && ! $rebase_base && ! $fetch_failed ) {
				$default_guard = $this->assert_ref_current_with_default_branch($primary_path, $resolved_base, $repo, $branch, 'base');
				if ( is_wp_error($default_guard) ) {
					return $default_guard;
				}
			}
			$cmd            = sprintf('worktree add -b %s %s %s', escapeshellarg($branch), escapeshellarg($wt_path), escapeshellarg($base));
			$created_branch = true;
		}
		$intent_base_ref  = $created_branch ? (string) $resolved_base : 'existing_local_branch';
		$intent_base_head = $this->run_git($primary_path, 'rev-parse --verify ' . escapeshellarg(( $created_branch ? (string) $resolved_base : $branch ) . '^{commit}'), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($intent_base_head) ) {
			return $intent_base_head;
		}
		$creation_intent = $this->worktree_creation_intent(
			$repo,
			$branch,
			$intent_base_ref,
			trim( (string) ( $intent_base_head['output'] ?? '' )),
			$task,
			$inject_context,
			$bootstrap,
			$intent
		);
		$intent_stored   = WorktreeContextInjector::store_creation_intent($wt_handle, $creation_intent);
		if ( is_wp_error($intent_stored) || ! $intent_stored ) {
			return is_wp_error($intent_stored)
				? $intent_stored
				: $this->worktree_creation_intent_conflict($wt_handle, $wt_path, $repo, $branch);
		}

		$operation_deadline = (float) ( $preflight['operation_deadline'] ?? 0.0 );
		$operation_timeout  = (int) ( $preflight['operation_timeout'] ?? 0 );
		$operation_started  = (float) ( $preflight['operation_started'] ?? 0.0 );
		$add_remaining      = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $add_remaining <= 0 ) {
			WorktreeContextInjector::forget_creation_intent($wt_handle, $creation_intent);
			return $this->worktree_operation_timeout('git_worktree_add', $operation_timeout, $operation_started, array( 'cleanup' => 'no_checkout_created' ));
		}
		$this->worktree_add_progress($progress_callback, 'git_worktree_add');
		$result = $this->run_git($primary_path, $cmd, min(300, $add_remaining));
		if ( is_wp_error($result) ) {
			if ( $this->worktree_operation_remaining_seconds($operation_deadline) <= 0 ) {
				$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, $created_branch, $wt_handle, $creation_intent);
				return $this->worktree_operation_timeout('git_worktree_add', $operation_timeout, $operation_started, array( 'cleanup' => 'rollback_requested' ));
			}
			WorktreeContextInjector::forget_creation_intent($wt_handle, $creation_intent);
			return $result;
		}
		$this->worktree_add_progress($progress_callback, 'post_create_validation');
		$deadline_error = $this->worktree_operation_deadline_error('git_worktree_add', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, $created_branch, $wt_handle, $creation_intent);
			return $deadline_error;
		}

		$identity_configuration = $this->configure_repository_git_identity($wt_path);
		if ( null !== $identity_configuration ) {
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, $created_branch, $wt_handle, $creation_intent);
			return $identity_configuration;
		}

		$response = array(
			'success'        => true,
			'handle'         => $wt_handle,
			'path'           => $wt_path,
			'branch'         => $branch,
			'base'           => $created_branch ? $resolved_base : null,
			'slug'           => $slug,
			'created_branch' => $created_branch,
			'message'        => sprintf('Worktree "%s" added at %s (branch %s).', $wt_handle, $wt_path, $branch),
			'freshness_transport' => $freshness_transport,
		);

		if ( $fetch_failed ) {
			$response['fetch_failed']   = true;
			$response['fetch_attempts'] = (int) ( $preflight['fetch_attempts'] ?? 1 );
			if ( $fetch_timed_out ) {
				$response['fetch_timed_out']       = true;
				$response['fetch_timeout_seconds'] = $fetch_timeout_seconds;
			}
			if ( null !== $fetch_error && '' !== $fetch_error ) {
				$response['fetch_error'] = $fetch_error;
			}
		}

		// Compute staleness. Only meaningful when fetch succeeded — otherwise the
		// upstream refs are potentially stale themselves and any behind-count we
		// produce would be misleading.
		if ( ! $fetch_failed ) {
			$this->worktree_add_progress($progress_callback, 'staleness_probe');
			$probe_timeout = $this->worktree_operation_remaining_seconds($operation_deadline);
			if ( $probe_timeout <= 0 ) {
				return $this->worktree_post_create_probe_timeout('staleness_probe', $operation_timeout, $operation_started, $wt_handle, $wt_path);
			}
			if ( ! $created_branch ) {
				// Existing local branch: compare against its configured upstream.
				$behind = $this->worktree_behind_count($wt_path, $branch, '@{upstream}', $probe_timeout);
				if ( $this->is_git_timeout_error($behind) ) {
					return $this->worktree_post_create_probe_timeout('staleness_probe', $operation_timeout, $operation_started, $wt_handle, $wt_path, $behind);
				}
				if ( is_int($behind) ) {
					$response['stale_commits_behind'] = $behind;
					// Derive a human-readable upstream label. Best-effort; silently
					// skipped when git's plumbing doesn't cooperate.
					$upstream_name = $this->run_git(
						$wt_path,
						sprintf('rev-parse --abbrev-ref --symbolic-full-name %s', escapeshellarg($branch . '@{upstream}'))
					);
					if ( ! is_wp_error($upstream_name) ) {
						$label = trim( (string) ( $upstream_name['output'] ?? '' ));
						if ( '' !== $label ) {
							$response['upstream'] = $label;
						}
					}
				}
				// null → no upstream configured; WP_Error → unexpected failure.
				// Both cases: silently omit staleness fields.
			} elseif ( null !== $resolved_base && ! $this->is_remote_tracking_ref($resolved_base) && 'HEAD' !== $resolved_base ) {
				// New branch cut from a local ref: compare that ref to its origin
				// counterpart so the agent sees when the base itself was stale.
				$base_upstream = 'origin/' . $resolved_base;
				$behind        = $this->worktree_behind_count($primary_path, $resolved_base, $base_upstream, $probe_timeout);
				if ( $this->is_git_timeout_error($behind) ) {
					return $this->worktree_post_create_probe_timeout('staleness_probe', $operation_timeout, $operation_started, $wt_handle, $wt_path, $behind);
				}
				if ( is_int($behind) ) {
					$response['base_stale_commits_behind'] = $behind;
					$response['base_upstream']             = $base_upstream;
				}
			}
		}

		// Rebase BEFORE gating: if the agent explicitly asked to rebase, try
		// that first. Success cancels the gate trigger entirely. Failure leaves
		// the worktree at its pre-rebase state AND still trips the gate, so
		// --rebase-base alone on a conflicting rebase isn't a silent bypass.
		if ( $rebase_base && ! $fetch_failed ) {
			$this->worktree_add_progress($progress_callback, 'rebase');
			$rebase_result = $this->try_rebase_worktree($wt_path, $response, $created_branch, (float) ( $preflight['operation_deadline'] ?? 0.0 ));
			if ( null !== $rebase_result ) {
				$response = array_merge($response, $rebase_result);
			}
		}

		if ( ! $fetch_failed ) {
			$this->worktree_add_progress($progress_callback, 'default_branch_probe');
			$probe_timeout = $this->worktree_operation_remaining_seconds($operation_deadline);
			if ( $probe_timeout <= 0 ) {
				return $this->worktree_post_create_probe_timeout('default_branch_probe', $operation_timeout, $operation_started, $wt_handle, $wt_path);
			}
			$default_branch_probe = $this->populate_default_branch_behind_count($primary_path, $branch, $response, $probe_timeout);
			if ( $this->is_git_timeout_error($default_branch_probe) ) {
				return $this->worktree_post_create_probe_timeout('default_branch_probe', $operation_timeout, $operation_started, $wt_handle, $wt_path, $default_branch_probe);
			}
		}

		// Staleness gate. Threshold filterable per-site / per-repo. Only fires
		// when fetch succeeded (otherwise behind-counts are unreliable) and
		// rebase didn't already zero out the staleness.
		if ( ! $allow_stale && ! $fetch_failed ) {
			if ( isset($response['default_branch_commits_behind']) && (int) $response['default_branch_commits_behind'] > 0 ) {
				$this->run_git($primary_path, sprintf('worktree remove --force %s', escapeshellarg($wt_path)));
				WorktreeContextInjector::forget_creation_intent($wt_handle, $creation_intent);

				return $this->worktree_behind_default_branch_error(
					(int) $response['default_branch_commits_behind'],
					(string) ( $response['default_branch_ref'] ?? 'origin/HEAD' ),
					$repo,
					$branch,
					'branch'
				);
			}

			/**
			 * Filters the staleness threshold above which `worktree_add` refuses
			 * to return a stale worktree without explicit `--allow-stale` opt-in.
			 *
			 * @param int    $threshold Default 50 commits behind upstream.
			 * @param string $repo      Repository name.
			 * @param string $branch    Branch being materialized.
			 */
			$threshold                  = (int) apply_filters('datamachine_worktree_stale_threshold', 50, $repo, $branch);
			$response['gate_threshold'] = $threshold;
			$effective_behind           = $this->effective_behind_count($response);

			if ( null !== $effective_behind && $effective_behind > $threshold ) {
				// Tear the worktree down so we don't leak a half-cooked
				// checkout on the user's disk.
				$this->run_git($primary_path, sprintf('worktree remove --force %s', escapeshellarg($wt_path)));
				WorktreeContextInjector::forget_creation_intent($wt_handle, $creation_intent);

				$label    = $response['upstream'] ?? ( $response['base_upstream'] ?? 'upstream' );
				$guidance = sprintf(
					'Worktree base is %d commits behind %s (threshold: %d).' . "\n"
					. 'Options:' . "\n"
					. '  - workspace git-pull %s --allow-primary-mutation  (refresh primary first)' . "\n"
					. '  - worktree add … --from=origin/%s  (cut from remote ref directly)' . "\n"
					. '  - worktree add … --rebase-base  (auto-rebase onto upstream)' . "\n"
					. '  - worktree add … --allow-stale  (proceed with known-stale base)',
					$effective_behind,
					$label,
					$threshold,
					$repo,
					ltrim( (string) ( $response['upstream'] ?? $resolved_base ?? 'main' ), 'origin/')
				);

				return new \WP_Error(
					'worktree_stale',
					$guidance,
					array(
						'status'                    => 409,
						'stale_commits_behind'      => $response['stale_commits_behind'] ?? null,
						'base_stale_commits_behind' => $response['base_stale_commits_behind'] ?? null,
						'upstream'                  => $response['upstream'] ?? null,
						'base_upstream'             => $response['base_upstream'] ?? null,
						'gate_threshold'            => $threshold,
						'fetch_failed'              => false,
					)
				);
			}
		}

		$lifecycle_metadata                   = WorktreeContextInjector::build_lifecycle_metadata(
			array(
				'handle'         => $wt_handle,
				'path'           => $wt_path,
				'repo'           => $repo,
				'branch'         => $branch,
				'base_ref'       => $created_branch ? $resolved_base : null,
				'base_source'    => $created_branch ? ( null !== $from && '' !== trim( $from ) ? 'requested_ref' : 'default_base' ) : 'existing_local_branch',
				'task_url'       => isset( $task['task_url'] ) ? (string) $task['task_url'] : '',
				'task_ref'       => isset( $task['task_ref'] ) ? (string) $task['task_ref'] : '',
				'purpose'        => $intent['purpose'] ?? null,
				'owner_run_ref'  => $intent['owner_run_ref'] ?? null,
				'cleanup_policy' => $intent['cleanup_policy'] ?? null,
			)
		);
		$lifecycle_metadata['reuse_contract'] = array(
			'branch'         => $branch,
			'base_ref'       => $created_branch ? $resolved_base : 'existing_local_branch',
			'inject_context' => $inject_context,
			'bootstrap'      => $bootstrap,
			'purpose'        => $intent['purpose'] ?? null,
			'owner_run_ref'  => $intent['owner_run_ref'] ?? null,
			'cleanup_policy' => $intent['cleanup_policy'] ?? null,
		);
		$lifecycle_metadata['provisioning']   = array(
			'create'    => array(
				'outcome'      => 'succeeded',
				'completed_at' => gmdate('c'),
			),
			'bootstrap' => array(
				'requested'      => $bootstrap,
				'outcome'        => $bootstrap ? 'pending' : 'not_requested',
				'resume_command' => $bootstrap ? $this->worktree_freshness_retry_command($repo, $branch, $from, $inject_context, $bootstrap, false, false, false, $task, $intent) : null,
			),
			'context'   => array(
				'requested' => $inject_context,
				'outcome'   => $inject_context ? 'pending' : 'not_requested',
			),
		);
		$this->worktree_add_progress($progress_callback, 'lifecycle_metadata');
		$metadata_stored = WorktreeContextInjector::promote_creation_intent( $wt_handle, $creation_intent, $lifecycle_metadata );
		if ( is_wp_error( $metadata_stored ) ) {
			if ( 'workspace_sqlite_lock_contention' === $metadata_stored->get_error_code() ) {
				return $this->worktree_post_create_registry_error($metadata_stored, $wt_handle, $wt_path, 'lifecycle_metadata');
			}
			if ( null !== WorktreeContextInjector::get_creation_intent($wt_handle) ) {
				$this->rollback_rejected_worktree( $primary_path, $wt_path, $branch, $created_branch, $wt_handle, $creation_intent );
			} else {
				$this->rollback_rejected_worktree( $primary_path, $wt_path, $branch, $created_branch );
				WorktreeContextInjector::forget_metadata($wt_handle);
			}
			return $metadata_stored;
		}
		$response['created_at'] = $lifecycle_metadata['created_at'] ?? null;
		$response['metadata']   = WorktreeContextInjector::get_metadata($wt_handle);

		if ( ! $inject_context ) {
			$response['context_injected']    = false;
			$response['context_skip_reason'] = 'inject_context flag disabled';
		} else {
			$this->worktree_add_progress($progress_callback, 'context_injection');
			$payload = WorktreeContextInjector::build_payload();
			if ( null === $payload ) {
				$response['context_injected']    = false;
				$response['context_skip_reason'] = 'agent memory layer unavailable';
			} else {
				$injection = WorktreeContextInjector::inject($wt_path, $payload);
				if ( is_wp_error($injection) ) {
					$response['context_injected']    = false;
					$response['context_skip_reason'] = 'inject failed: ' . $injection->get_error_message();
				} else {
					$provisioning = (array) ( $lifecycle_metadata['provisioning'] ?? array() );
					$provisioning['context'] = array( 'requested' => true, 'outcome' => 'completed', 'completed_at' => gmdate('c') );
					$metadata_stored = WorktreeContextInjector::store_metadata($wt_handle, $payload, array( 'provisioning' => $provisioning ));
					if ( is_wp_error($metadata_stored) ) {
						if ( 'workspace_sqlite_lock_contention' === $metadata_stored->get_error_code() ) {
							return $this->worktree_post_create_registry_error($metadata_stored, $wt_handle, $wt_path, 'context_metadata');
						}
						$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, $created_branch);
						return $metadata_stored;
					}
					$response['metadata']         = WorktreeContextInjector::get_metadata($wt_handle);
					$response['context_injected'] = true;
					$response['context_files']    = $injection['written'];
					if ( ! empty($injection['exclude_path']) ) {
						$response['context_exclude_path'] = $injection['exclude_path'];
					}
				}
			}
		}

		return $response;
	}

	/** Emit best-effort phase visibility without allowing a presentation failure to alter creation. */
	private function worktree_add_progress( ?callable $callback, string $phase ): void {
		if ( null === $callback ) {
			return;
		}
		try {
			$callback(
				array(
					'operation' => 'worktree_add',
					'phase'     => $phase,
				)
			);
		} catch ( \Throwable $error ) {
			unset($error);
			// Progress reporting must never interrupt a protected workspace mutation.
		}
	}

	/**
	 * Reuse an exact managed handle only when its persisted contract proves it is
	 * safe. This intentionally does not search for equivalent task candidates or
	 * recycle terminal worktrees; those need explicit caller policy.
	 *
	 * @return array{success: bool, handle: string, path: string, branch: string, slug: string, created_branch: bool, message: string, disk_budget?: array, context_injected?: bool, context_files?: string[], context_skip_reason?: string, bootstrap?: array, fetch_failed?: bool, fetch_error?: string, stale_commits_behind?: int, upstream?: string, base_stale_commits_behind?: int, base_upstream?: string, default_branch_commits_behind?: int, default_branch_ref?: string, gate_threshold?: int, rebase_attempted?: bool, rebase_succeeded?: bool, rebase_error?: string, rebase_target?: string}|\WP_Error
	 */
	private function reuse_existing_worktree( string $handle, string $branch, ?string $from, bool $inject_context, bool $bootstrap, array $task, array $intent = array(), string $reuse_policy = 'reuse_compatible', string $primary_path = '' ): array|\WP_Error {
		$inspection = $this->worktree_get($handle, array(
			'include_status' => true,
			'include_disk'   => false,
		));
		if ( is_wp_error($inspection) || empty($inspection['worktrees'][0]) ) {
			return $this->worktree_reuse_refused($handle, 'inspection_failed', array(
				'error_code' => is_wp_error($inspection) ? $inspection->get_error_code() : 'worktree_not_found',
			));
		}

		$existing = $inspection['worktrees'][0];
		$metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : array();
		$evidence = $this->worktree_reuse_evidence($handle, $existing, $metadata);
		if ( ( $existing['branch'] ?? null ) !== $branch ) {
			return $this->worktree_reuse_refused($handle, 'branch_mismatch', $evidence + array( 'requested_branch' => $branch ));
		}
		if ( (int) ( $existing['dirty'] ?? 0 ) > 0 ) {
			if ( in_array($metadata['provisioning']['bootstrap']['reason'] ?? null, array( 'bootstrap_created_dirty_paths', 'bootstrap_git_state_unavailable' ), true) ) {
				return $this->worktree_reuse_refused($handle, (string) $metadata['provisioning']['bootstrap']['reason'], $evidence + array(
					'bootstrap' => $metadata['provisioning']['bootstrap'],
				));
			}
			return $this->worktree_reuse_refused($handle, 'dirty_worktree', $evidence);
		}
		if ( (int) ( $existing['unpushed'] ?? 0 ) > 0 ) {
			return $this->worktree_reuse_refused($handle, 'unpushed_commits', $evidence);
		}
		$contract = is_array($metadata['reuse_contract'] ?? null) ? $metadata['reuse_contract'] : array();
		if ( array() === $contract ) {
			$creation_intent = WorktreeContextInjector::get_creation_intent($handle);
			if ( null === ( $existing['metadata'] ?? null ) || null !== $creation_intent ) {
				return $this->adopt_interrupted_worktree($handle, $existing, $branch, $from, $inject_context, $bootstrap, $task, $intent, $primary_path, $creation_intent);
			}
			return $this->worktree_reuse_refused($handle, 'reuse_contract_missing', $evidence);
		}
		$requested_base = null !== $from && '' !== trim($from) ? trim($from) : ( $contract['base_ref'] ?? null );
		if ( ( $contract['base_ref'] ?? null ) !== $requested_base ) {
			return $this->worktree_reuse_refused($handle, 'base_mismatch', $evidence + array(
				'requested_base_ref' => $requested_base,
				'stored_base_ref'    => $contract['base_ref'] ?? null,
			));
		}
		if ( (bool) ( $contract['inject_context'] ?? null ) !== $inject_context || (bool) ( $contract['bootstrap'] ?? null ) !== $bootstrap ) {
			return $this->worktree_reuse_refused($handle, 'runtime_incompatible', $evidence + array(
				'requested_runtime' => array(
					'inject_context' => $inject_context,
					'bootstrap'      => $bootstrap,
				),
				'stored_runtime'    => array(
					'inject_context' => $contract['inject_context'] ?? null,
					'bootstrap'      => $contract['bootstrap'] ?? null,
				),
			));
		}
		if ( $this->worktree_reuse_task_identity($task) !== $this->worktree_reuse_task_identity( (array) ( $existing['task'] ?? array() )) ) {
			return $this->worktree_reuse_refused($handle, 'task_mismatch', $evidence + array( 'requested_task' => $task ));
		}
		$stored_intent = WorktreeContextInjector::normalize_disposable_intent($contract + $metadata);
		if ( 'isolated' === $reuse_policy && empty($intent['owner_run_ref']) ) {
			return $this->worktree_reuse_refused($handle, 'isolated_requested', $evidence + array( 'reuse_policy' => $reuse_policy ));
		}
		if ( $intent !== $stored_intent ) {
			return $this->worktree_reuse_refused($handle, 'disposable_intent_mismatch', $evidence + array(
				'requested_intent' => $intent,
				'stored_intent'    => $stored_intent,
			));
		}
		$live_owner_retry = WorktreeContextInjector::LIVENESS_LIVE === ( $existing['liveness'] ?? null ) && ! empty($intent['owner_run_ref']);
		if ( WorktreeContextInjector::LIVENESS_LIVE === ( $existing['liveness'] ?? null ) && ! $live_owner_retry ) {
			return $this->worktree_reuse_refused($handle, 'live_worktree', $evidence);
		}
		$readiness = WorktreeContextInjector::bootstrap_readiness($metadata);
		$context   = (array) ( $metadata['provisioning']['context'] ?? array() );
		if ( $inject_context && 'pending' === ( $context['outcome'] ?? null ) ) {
			$resumed_context = $this->resume_incomplete_context($handle, $existing, $metadata);
			if ( is_wp_error($resumed_context) ) {
				return $resumed_context;
			}
			$metadata = (array) ( $resumed_context['metadata'] ?? $metadata );
		}
		if ( ! $readiness['ready'] && $bootstrap ) {
			return $this->resume_incomplete_bootstrap($handle, $existing, $metadata, $branch);
		}

		$response = array(
			'success'        => true,
			'handle'         => $handle,
			'path'           => $existing['path'],
			'branch'         => $branch,
			'slug'           => $this->slugify_branch($branch),
			'created_branch' => false,
			'reused'         => true,
			'reuse'          => array(
				'status'      => 'accepted',
				'reason_code' => $live_owner_retry ? 'owner_identical_live_retry' : 'exact_compatible_handle',
			) + $evidence,
			'metadata'       => $metadata,
			'message'        => sprintf('Reused clean compatible worktree "%s" at %s.', $handle, $existing['path']),
		);
		if ( isset($resumed_context) ) {
			$response['context_injected'] = ! empty($resumed_context['context_injected']);
			$response['context_files']    = (array) ( $resumed_context['context_files'] ?? array() );
		}
		return $response;
	}

	/** Complete context injection left pending by post-create registry contention. */
	private function resume_incomplete_context( string $handle, array $existing, array $metadata ): array|\WP_Error {
		$payload = WorktreeContextInjector::build_payload();
		if ( null === $payload ) {
			return array( 'metadata' => $metadata, 'context_injected' => false );
		}
		$injection = WorktreeContextInjector::inject((string) $existing['path'], $payload);
		if ( is_wp_error($injection) ) {
			return $injection;
		}
		$provisioning = (array) ( $metadata['provisioning'] ?? array() );
		$provisioning['context'] = array( 'requested' => true, 'outcome' => 'completed', 'completed_at' => gmdate('c') );
		$stored = WorktreeContextInjector::store_metadata($handle, $payload, array( 'provisioning' => $provisioning ));
		if ( is_wp_error($stored) ) {
			return 'workspace_sqlite_lock_contention' === $stored->get_error_code()
				? $this->worktree_post_create_registry_error($stored, $handle, (string) $existing['path'], 'context_metadata')
				: $stored;
		}
		return array(
			'metadata'         => WorktreeContextInjector::get_metadata($handle) ?? $metadata,
			'context_injected' => true,
			'context_files'    => (array) ( $injection['written'] ?? array() ),
		);
	}

	/** Resume a durable incomplete bootstrap for an otherwise compatible handle. */
	private function resume_incomplete_bootstrap( string $handle, array $existing, array $metadata, string $branch ): array|\WP_Error {
		$bootstrap = (array) ( $metadata['provisioning']['bootstrap'] ?? array() );
		if ( 'running' === ( $bootstrap['outcome'] ?? null ) && is_array($bootstrap['capacity_reservation'] ?? null) ) {
			$coordinator = WorktreeContextInjector::bootstrap_owner_state($bootstrap['coordinator'] ?? $bootstrap['owner'] ?? null);
			$child       = isset($bootstrap['active_child']) ? WorktreeContextInjector::bootstrap_owner_state($bootstrap['active_child']) : array(
				'state'  => 'stale',
				'reason' => 'no_active_child',
			);
			if ( 'active' === $coordinator['state'] || 'active' === $child['state'] ) {
				return new \WP_Error(
					'worktree_bootstrap_in_progress',
					'Refusing to start a second dependency bootstrap while its coordinator or active child is still live.',
					array(
						'status'       => 409,
						'retryable'    => true,
						'handle'       => $handle,
						'coordinator'  => $coordinator,
						'active_child' => $child,
					)
				);
			}
			if ( 'unverifiable' === $coordinator['state'] || 'unverifiable' === $child['state'] ) {
				return new \WP_Error(
					'worktree_bootstrap_owner_unverifiable',
					'Cannot safely resume dependency bootstrap because its coordinator or active child cannot be verified. Inspect both processes and retry after confirming they have exited.',
					array(
						'status'              => 423,
						'retryable'           => true,
						'handle'              => $handle,
						'coordinator'         => $coordinator,
						'active_child'        => $child,
						'remediation_command' => 'ps -p ' . (int) ( ( $bootstrap['coordinator']['pid'] ?? $bootstrap['owner']['pid'] ?? 0 ) ) . ',' . (int) ( ( $bootstrap['active_child']['pid'] ?? 0 ) ) . ' -o pid=,lstart=,command=',
					)
				);
			}
			$reconciled = $this->record_bootstrap_outcome($handle, 'interrupted', array(), 'coordinator_' . (string) $coordinator['reason'] . ';active_child_' . (string) $child['reason']);
			if ( is_wp_error($reconciled) ) {
				return $reconciled;
			}
			$metadata = WorktreeContextInjector::get_metadata($handle) ?? $metadata;
		}
		$demand_plan = WorktreeBootstrapper::demand_plan_for_target( (string) $existing['path'], 'HEAD', true);
		if ( $demand_plan instanceof \WP_Error ) {
			return $demand_plan;
		}
		$reservation = WorktreeBootstrapper::remaining_demand_after_materialization($demand_plan);
		$capacity    = $this->inspect_worktree_capacity( (string) ( $metadata['repo'] ?? '' ), $branch, false, $reservation);
		if ( 'refused' === ( $capacity['status'] ?? null ) ) {
			return new \WP_Error(
				'worktree_disk_budget_exceeded',
				'Refusing to resume dependency bootstrap because the current workspace capacity budget is unsafe.',
				array(
					'status'      => 507,
					'handle'      => $handle,
					'disk_budget' => $capacity,
				)
			);
		}
		$stored = $this->record_bootstrap_outcome($handle, 'running', array(), null, $reservation);
		if ( is_wp_error($stored) ) {
			return $stored;
		}
		return array(
			'success'               => true,
			'handle'                => $handle,
			'path'                  => $existing['path'],
			'branch'                => $branch,
			'slug'                  => $this->slugify_branch($branch),
			'created_branch'        => false,
			'resumed'               => true,
			'bootstrap_deferred'    => true,
			'bootstrap_reservation' => $reservation,
			'metadata'              => WorktreeContextInjector::get_metadata($handle) ?? $metadata,
			'message'               => sprintf('Resumed incomplete bootstrap for worktree "%s".', $handle),
		);
	}

	/** Run a claimed resume only after the short repository-lock claim has ended. */
	private function complete_resumed_bootstrap( array $response ): array|\WP_Error {
		$response['bootstrap'] = WorktreeBootstrapper::bootstrap( (string) $response['path'], null, fn( array $process ) => $this->record_bootstrap_active_child( (string) $response['handle'], (int) ( $process['pid'] ?? 0 )), fn( array $process ) => $this->clear_bootstrap_active_child( (string) $response['handle'], (int) ( $process['pid'] ?? 0 )));
		$response              = $this->record_completed_bootstrap($response);
		if ( is_wp_error($response) ) {
			return $response;
		}
		unset($response['bootstrap_deferred'], $response['bootstrap_reservation']);
		return $response;
	}

	/** Complete a reserved bootstrap after the capacity and repository locks are released. */
	private function complete_deferred_bootstrap( array $response, string $repo, string $branch, float $operation_deadline, int $operation_timeout, float $operation_started, ?callable $progress_callback = null ): array|\WP_Error {
		$remaining_seconds = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $remaining_seconds <= 0 ) {
			$recorded = $this->record_bootstrap_outcome( (string) $response['handle'], 'failed', array(), 'operation_timeout');
			return is_wp_error($recorded) ? $recorded : $this->worktree_operation_timeout('bootstrap', $operation_timeout, $operation_started, array( 'readiness' => 'incomplete' ));
		}

		$this->worktree_add_progress($progress_callback, 'bootstrap_start');
		$response['bootstrap'] = WorktreeBootstrapper::bootstrap( (string) $response['path'], $remaining_seconds, fn( array $process ) => $this->record_bootstrap_active_child( (string) $response['handle'], (int) ( $process['pid'] ?? 0 )), fn( array $process ) => $this->clear_bootstrap_active_child( (string) $response['handle'], (int) ( $process['pid'] ?? 0 )));
		$response = $this->record_completed_bootstrap($response);
		if ( is_wp_error($response) ) {
			return $response;
		}
		$deadline_error = $this->worktree_operation_deadline_error('bootstrap_complete', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		$this->worktree_add_progress($progress_callback, 'bootstrap_complete');
		$after_capacity                = $this->inspect_worktree_capacity($repo, $branch, false, array());
		$measurement_plan              = (array) ( $response['bootstrap_measurement_plan'] ?? array() );
		$bootstrap_before_capacity     = (array) ( $response['bootstrap_capacity_before'] ?? array() );
		$response['capacity_evidence'] = WorktreeDemandCalibration::record_bootstrap($repo, $measurement_plan, $bootstrap_before_capacity, $after_capacity, ! empty($response['bootstrap']['success']));
		$deadline_error                = $this->worktree_operation_deadline_error('bootstrap_finalize', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		unset($response['bootstrap_deferred'], $response['bootstrap_reservation'], $response['bootstrap_capacity_before'], $response['bootstrap_measurement_plan']);
		return $response;
	}

	/** Persist bootstrap cleanliness evidence and retain generated dirty paths for review. */
	private function record_completed_bootstrap( array $response ): array|\WP_Error {
		$bootstrap_created_dirty_paths = (array) ( $response['bootstrap']['git_state']['bootstrap_created_dirty_paths'] ?? array() );
		$git_state_inspected           = ! empty($response['bootstrap']['git_state']['inspected']);
		$bootstrap_outcome             = ! empty($response['bootstrap']['success']) && $git_state_inspected && array() === $bootstrap_created_dirty_paths ? 'succeeded' : 'failed';
		$bootstrap_reason              = ! $git_state_inspected ? 'bootstrap_git_state_unavailable' : ( array() !== $bootstrap_created_dirty_paths ? 'bootstrap_created_dirty_paths' : null );
		$recorded                      = $this->record_bootstrap_outcome( (string) $response['handle'], $bootstrap_outcome, (array) $response['bootstrap'], $bootstrap_reason);
		if ( is_wp_error($recorded) ) {
			return $recorded;
		}
		$response['metadata'] = WorktreeContextInjector::get_metadata( (string) $response['handle']);
		if ( $git_state_inspected && array() === $bootstrap_created_dirty_paths ) {
			return $response;
		}

		$error_code = $git_state_inspected ? 'bootstrap_created_dirty_paths' : 'bootstrap_git_state_unavailable';
		$message    = $git_state_inspected
			? sprintf('Bootstrap created %d new dirty path(s) in worktree "%s". The worktree was retained without deleting files that may need review.', count($bootstrap_created_dirty_paths), (string) $response['handle'])
			: sprintf('Could not verify post-bootstrap Git cleanliness for worktree "%s". The worktree was retained without deleting files.', (string) $response['handle']);
		return new \WP_Error(
			$error_code,
			$message,
			array(
				'status'                        => 409,
				'handle'                        => $response['handle'],
				'path'                          => $response['path'],
				'bootstrap'                     => $response['bootstrap'],
				'bootstrap_created_dirty_paths' => $bootstrap_created_dirty_paths,
				'rollback'                      => array(
					'git_materialization_rolled_back' => false,
					'lifecycle_metadata_rolled_back'  => false,
					'reason'                          => 'new bootstrap outputs are retained for review',
				),
				'remediation_command'           => 'git -C ' . escapeshellarg( (string) $response['path']) . ' status --short --branch --untracked-files=all',
			)
		);
	}

	/** Persist a bootstrap phase transition without coupling to any dependency manager. */
	private function record_bootstrap_outcome( string $handle, string $outcome, array $result = array(), ?string $reason = null, ?array $reservation = null ): bool|\WP_Error {
		$metadata             = WorktreeContextInjector::get_metadata($handle) ?? array();
		$bootstrap            = is_array($metadata['provisioning']['bootstrap'] ?? null) ? $metadata['provisioning']['bootstrap'] : array();
		$bootstrap['outcome'] = $outcome;
		if ( 'running' === $outcome ) {
			$bootstrap['started_at']           = gmdate('c');
			$bootstrap['capacity_reservation'] = $reservation;
			$bootstrap['coordinator']          = WorktreeContextInjector::bootstrap_owner();
			unset($bootstrap['active_child'], $bootstrap['owner']);
		} else {
			$bootstrap['completed_at'] = gmdate('c');
			unset($bootstrap['capacity_reservation']);
			unset($bootstrap['coordinator'], $bootstrap['active_child'], $bootstrap['owner']);
			$bootstrap['steps'] = array_map(
				static fn( array $step ): array => array_filter(array(
					'step'    => $step['step'] ?? null,
					'status'  => $step['status'] ?? null,
					'reason'  => $step['reason'] ?? null,
					'command' => $step['command'] ?? null,
				)),
				(array) ( $result['steps'] ?? array() )
			);
			if ( null !== $reason ) {
				$bootstrap['reason'] = $reason;
			}
			if ( is_array($result['git_state'] ?? null) ) {
				$bootstrap['git_state'] = $result['git_state'];
			}
		}
		$metadata['provisioning']['bootstrap'] = $bootstrap;
		return WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);
	}

	/** Record the current ProcessRunner child without replacing the coordinator. */
	private function record_bootstrap_active_child( string $handle, int $pid ): void {
		if ( $pid <= 0 ) {
			return;
		}
		$metadata = WorktreeContextInjector::get_metadata($handle);
		if ( ! is_array($metadata) || 'running' !== ( $metadata['provisioning']['bootstrap']['outcome'] ?? null ) ) {
			return;
		}
		$metadata['provisioning']['bootstrap']['active_child'] = WorktreeContextInjector::bootstrap_owner($pid);
		WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);
	}

	/** Clear only the child that completed, preserving a newer sequential child. */
	private function clear_bootstrap_active_child( string $handle, int $pid ): void {
		$metadata = WorktreeContextInjector::get_metadata($handle);
		if ( ! is_array($metadata) || (int) ( $metadata['provisioning']['bootstrap']['active_child']['pid'] ?? 0 ) !== $pid ) {
			return;
		}
		unset($metadata['provisioning']['bootstrap']['active_child']);
		WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);
	}

	/**
	 * Complete only the metadata half of an interrupted exact add operation.
	 *
	 * The journal is the only ownership proof for a metadata-less directory.
	 * Its creation contract must exactly match the retry and the Git checkout
	 * must remain clean at the journal's requested base tip.
	 */
	private function adopt_interrupted_worktree( string $handle, array $existing, string $branch, ?string $from, bool $inject_context, bool $bootstrap, array $task, array $intent, string $primary_path, ?array $creation_intent ): array|\WP_Error {
		$evidence = $this->worktree_reuse_evidence($handle, $existing, null);
		if ( ( $existing['branch'] ?? null ) !== $branch ) {
			return $this->worktree_reuse_refused($handle, 'branch_mismatch', $evidence + array( 'requested_branch' => $branch ));
		}
		if ( (int) ( $existing['dirty'] ?? 0 ) > 0 ) {
			return $this->worktree_reuse_refused($handle, 'dirty_worktree', $evidence);
		}
		if ( (int) ( $existing['unpushed'] ?? 0 ) > 0 ) {
			return $this->worktree_reuse_refused($handle, 'unpushed_commits', $evidence);
		}
		if ( WorktreeContextInjector::LIVENESS_LIVE === ( $existing['liveness'] ?? null ) ) {
			return $this->worktree_reuse_refused($handle, 'live_worktree', $evidence);
		}
		if ( '' === $primary_path || ! GitCheckout::exists($primary_path) ) {
			return $this->worktree_reuse_refused($handle, 'primary_unavailable', $evidence);
		}

		if ( null === $creation_intent ) {
			return $this->worktree_reuse_refused($handle, 'interrupted_recovery_intent_missing', $evidence);
		}
		$base      = (string) ( $creation_intent['base_ref'] ?? '' );
		$base_head = (string) ( $creation_intent['base_head'] ?? '' );
		if ( '' === $base || '' === $base_head ) {
			return $this->worktree_reuse_refused($handle, 'base_unresolved', $evidence);
		}
		if ( 'existing_local_branch' !== $base ) {
			$requested_base = null !== $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path);
			if ( $requested_base !== $base ) {
				return $this->worktree_reuse_refused($handle, 'interrupted_recovery_intent_mismatch', $evidence + array( 'requested_base_ref' => $requested_base, 'stored_base_ref' => $base ));
			}
		}
		$requested_intent = $this->worktree_creation_intent(explode('@', $handle, 2)[0], $branch, $base, $base_head, $task, $inject_context, $bootstrap, $intent);
		if ( $creation_intent !== $requested_intent ) {
			return $this->worktree_reuse_refused($handle, 'interrupted_recovery_intent_mismatch', $evidence + array( 'requested_creation_intent' => $requested_intent ));
		}
		$ancestry = $this->run_git( (string) $existing['path'], 'merge-base --is-ancestor HEAD ' . escapeshellarg($base_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($ancestry) || (string) ( $existing['head'] ?? '' ) !== $base_head ) {
			return $this->worktree_reuse_refused($handle, 'interrupted_recovery_head_mismatch', $evidence + array(
				'requested_base_ref'  => $base,
				'requested_base_head' => $base_head,
			));
		}

		$metadata                   = WorktreeContextInjector::build_lifecycle_metadata(array(
			'handle'         => $handle,
			'path'           => $existing['path'],
			'repo'           => explode('@', $handle, 2)[0],
			'branch'         => $branch,
			'base_ref'       => $base,
			'base_source'    => 'existing_local_branch' === $base ? 'existing_local_branch' : 'requested_ref',
			'task_url'       => (string) ( $task['task_url'] ?? '' ),
			'task_ref'       => (string) ( $task['task_ref'] ?? '' ),
			'purpose'        => $intent['purpose'] ?? null,
			'owner_run_ref'  => $intent['owner_run_ref'] ?? null,
			'cleanup_policy' => $intent['cleanup_policy'] ?? null,
		));
		$metadata['reuse_contract'] = array(
			'branch'         => $branch,
			'base_ref'       => $base,
			'inject_context' => $inject_context,
			'bootstrap'      => $bootstrap,
			'purpose'        => $intent['purpose'] ?? null,
			'owner_run_ref'  => $intent['owner_run_ref'] ?? null,
			'cleanup_policy' => $intent['cleanup_policy'] ?? null,
		);
		$metadata['provisioning'] = array(
			'create'    => array( 'outcome' => 'succeeded', 'completed_at' => gmdate('c') ),
			'bootstrap' => array( 'requested' => $bootstrap, 'outcome' => $bootstrap ? 'pending' : 'not_requested' ),
			'context'   => array( 'requested' => $inject_context, 'outcome' => $inject_context ? 'pending' : 'not_requested' ),
		);
		$stored                     = WorktreeContextInjector::promote_creation_intent($handle, $creation_intent, $metadata);
		if ( is_wp_error($stored) ) {
			return $stored;
		}
		if ( $inject_context ) {
			$resumed_context = $this->resume_incomplete_context($handle, $existing, $metadata);
			if ( is_wp_error($resumed_context) ) {
				return $resumed_context;
			}
			$metadata = (array) ( $resumed_context['metadata'] ?? $metadata );
		}
		if ( $bootstrap ) {
			return $this->resume_incomplete_bootstrap($handle, $existing, $metadata, $branch);
		}
		$this->emit_workspace_changed('worktree_adopt_interrupted', explode('@', $handle, 2)[0], $handle, (string) $existing['path']);

		return array(
			'success'        => true,
			'handle'         => $handle,
			'path'           => $existing['path'],
			'branch'         => $branch,
			'slug'           => $this->slugify_branch($branch),
			'created_branch' => false,
			'adopted'        => true,
			'recovery'       => array(
				'status'              => 'adopted',
				'reason_code'         => 'interrupted_exact_handle',
				'requested_base_ref'  => $base,
				'requested_base_head' => $base_head,
				'task_identity'       => $this->worktree_reuse_task_identity($task),
			),
			'metadata'         => WorktreeContextInjector::get_metadata($handle),
			'context_injected' => ! empty($resumed_context['context_injected']),
			'context_files'    => (array) ( $resumed_context['context_files'] ?? array() ),
			'message'          => sprintf('Adopted interrupted worktree "%s" at %s after exact journal, branch, base, HEAD, and task verification.', $handle, $existing['path']),
		);
	}

	/** Build the immutable contract recorded before a Git worktree mutation. */
	private function worktree_creation_intent( string $repo, string $branch, string $base_ref, string $base_head, array $task, bool $inject_context, bool $bootstrap, array $intent ): array {
		return array(
			'repo'           => $repo,
			'branch'         => $branch,
			'base_ref'       => $base_ref,
			'base_head'      => $base_head,
			'task'           => $task,
			'inject_context' => $inject_context,
			'bootstrap'      => $bootstrap,
			'intent'         => $intent,
		);
	}

	/** Report the durable record that fenced a concurrent creation attempt. */
	private function worktree_creation_intent_conflict( string $handle, string $expected_path, string $repo, string $branch ): \WP_Error {
		$metadata      = WorktreeContextInjector::get_metadata_fresh($handle) ?? array();
		$record_path   = is_string($metadata['path'] ?? null) && '' !== trim((string) $metadata['path']) ? (string) $metadata['path'] : $expected_path;
		$lifecycle     = is_string($metadata['lifecycle_state'] ?? null) && '' !== trim((string) $metadata['lifecycle_state']) ? (string) $metadata['lifecycle_state'] : 'not recorded';
		$reconciled_at = is_string($metadata['reconciled_at'] ?? null) && '' !== trim((string) $metadata['reconciled_at']) ? (string) $metadata['reconciled_at'] : 'not recorded';
		$path_exists   = is_dir($record_path);
		$recovery_verb = sprintf('workspace worktree add %s %s', $repo, $branch);

		return new \WP_Error(
			'worktree_creation_intent_conflict',
			sprintf(
				'Refusing to create worktree "%s": record kind creation_intent is fencing an in-flight creation. Evidence: lifecycle_state=%s; path=%s; path_exists=%s; reconciled_at=%s. Retry `%s` after the current creation finishes; that verb clears the journal by adopting or completing the exact worktree.',
				$handle,
				$lifecycle,
				$record_path,
				$path_exists ? 'yes' : 'no',
				$reconciled_at,
				$recovery_verb
			),
			array(
				'status'          => 409,
				'record_kind'     => 'creation_intent',
				'lifecycle_state' => 'not recorded' === $lifecycle ? null : $lifecycle,
				'path'            => $record_path,
				'path_exists'     => $path_exists,
				'reconciled_at'   => 'not recorded' === $reconciled_at ? null : $reconciled_at,
				'recovery_verb'   => $recovery_verb,
			)
		);
	}

	/**
	 * Report same-task managed worktrees before creating a new handle. Default
	 * admission refuses these candidates; explicit isolation permits allocation.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function worktree_reuse_candidates( string $repo, array $task ): array {
		$task_identity = $this->worktree_reuse_task_identity($task);
		if ( '' === $task_identity ) {
			return array();
		}

		$primary_path = $this->workspace_path . '/' . $repo;
		$listing      = $this->run_git($primary_path, 'worktree list --porcelain');
		if ( is_wp_error($listing) ) {
			return array();
		}
		$candidates = array();
		foreach ( $this->worktree_list_blocks( (string) ( $listing['output'] ?? '' )) as $block ) {
			$worktree = $this->parse_worktree_block($block);
			if ( null === $worktree || $primary_path === $worktree['path'] ) {
				continue;
			}
			$inside_workspace = str_starts_with($worktree['path'], $this->workspace_path . '/');
			$handle           = $inside_workspace ? substr($worktree['path'], strlen($this->workspace_path . '/')) : $worktree['path'];
			$metadata_key     = $inside_workspace ? $handle : 'external:' . sha1($worktree['path']);
			$metadata         = WorktreeContextInjector::get_metadata($metadata_key);
			$metadata         = is_array($metadata) ? $metadata : null;
			$candidate_task   = is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : array();
			if ( $task_identity !== $this->worktree_reuse_task_identity($candidate_task) ) {
				continue;
			}

			$dirty_result = $this->run_git($worktree['path'], 'status --porcelain');
			$dirty        = is_wp_error($dirty_result) ? 0 : count(array_filter(array_map('trim', explode("\n", $dirty_result['output'] ?? ''))));
			$unpushed     = $this->count_unpushed_commits($worktree['path']);
			if ( is_wp_error($unpushed) ) {
				return array();
			}
			$liveness     = WorktreeContextInjector::classify_liveness($metadata);
			$candidates[] = array(
				'handle'   => $handle,
				'path'     => $worktree['path'],
				'branch'   => $worktree['branch'],
				'head'     => $worktree['head'],
				'dirty'    => $dirty,
				'unpushed' => $unpushed,
				'liveness' => $liveness['liveness'],
				'owner'    => array_merge(WorktreeContextInjector::summarize_owner($metadata), array( 'owner_run_ref' => is_array($metadata) ? ( $metadata['owner_run_ref'] ?? null ) : null )),
				'state'    => is_array($metadata) ? WorktreeContextInjector::project_lifecycle_state($metadata) : null,
				'cleanup_policy' => is_array($metadata) ? ( $metadata['cleanup_policy'] ?? null ) : null,
				'task'     => $candidate_task,
			);
		}
		usort($candidates, static fn( array $left, array $right ): int => strcmp( (string) $left['handle'], (string) $right['handle']));
		return array_slice($candidates, 0, WorktreeContextInjector::SAME_TASK_CANDIDATE_EVIDENCE_LIMIT);
	}

	/** Reset an exact terminal handle only after proving its pushed HEAD is in the requested base. */
	private function recycle_terminal_worktree( string $handle, string $branch, ?string $from, bool $inject_context, bool $bootstrap, array $task, array $intent, string $primary_path ): array|\WP_Error {
		$inspection = $this->worktree_get($handle, array(
			'include_status' => true,
			'include_disk'   => false,
		));
		if ( is_wp_error($inspection) || empty($inspection['worktrees'][0]) ) {
			return $this->worktree_reuse_refused($handle, 'inspection_failed', array( 'error_code' => is_wp_error($inspection) ? $inspection->get_error_code() : 'worktree_not_found' ));
		}
		$existing = $inspection['worktrees'][0];
		$metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : array();
		$evidence = $this->worktree_reuse_evidence($handle, $existing, $metadata);
		if ( ( $existing['branch'] ?? null ) !== $branch ) {
			return $this->worktree_reuse_refused($handle, 'branch_mismatch', $evidence + array( 'requested_branch' => $branch ));
		}
		if ( ! WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			return $this->worktree_reuse_refused($handle, 'not_terminal', $evidence);
		}
		foreach ( array(
			'dirty'    => 'dirty_worktree',
			'unpushed' => 'unpushed_commits',
		) as $field => $reason ) {
			if ( (int) ( $existing[ $field ] ?? 0 ) > 0 ) {
				return $this->worktree_reuse_refused($handle, $reason, $evidence);
			}
		}
		if ( WorktreeContextInjector::LIVENESS_LIVE === ( $existing['liveness'] ?? null ) ) {
			return $this->worktree_reuse_refused($handle, 'live_worktree', $evidence);
		}
		if ( ! in_array($existing['liveness'] ?? null, array( WorktreeContextInjector::LIVENESS_STALE, WorktreeContextInjector::LIVENESS_STOPPED ), true) ) {
			return $this->worktree_reuse_refused($handle, 'liveness_unverified', $evidence);
		}
		$contract = is_array($metadata['reuse_contract'] ?? null) ? $metadata['reuse_contract'] : array();
		$base     = null !== $from && '' !== trim($from) ? trim($from) : ( $contract['base_ref'] ?? null );
		if ( ! is_string($base) || '' === $base || 'existing_local_branch' === $base || ( $contract['base_ref'] ?? null ) !== $base ) {
			return $this->worktree_reuse_refused($handle, 'base_mismatch', $evidence + array(
				'requested_base_ref' => $base,
				'stored_base_ref'    => $contract['base_ref'] ?? null,
			));
		}
		if ( (bool) ( $contract['inject_context'] ?? null ) !== $inject_context || (bool) ( $contract['bootstrap'] ?? null ) !== $bootstrap ) {
			return $this->worktree_reuse_refused($handle, 'runtime_incompatible', $evidence);
		}
		$target = $this->run_git($primary_path, 'rev-parse --verify ' . escapeshellarg($base . '^{commit}'), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($target) ) {
			return $this->worktree_reuse_refused($handle, 'base_unresolved', $evidence + array( 'requested_base_ref' => $base ));
		}
		$target_head = trim( (string) ( $target['output'] ?? '' ));
		$contained   = $this->run_git( (string) $existing['path'], 'merge-base --is-ancestor HEAD ' . escapeshellarg($target_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($contained) ) {
			return $this->worktree_reuse_refused($handle, 'terminal_head_not_in_base', $evidence + array(
				'requested_base_ref'  => $base,
				'requested_base_head' => $target_head,
			));
		}
		$path          = (string) $existing['path'];
		$previous_head = (string) ( $existing['head'] ?? '' );
		$reset         = $this->run_git($path, 'reset --hard ' . escapeshellarg($target_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($reset) ) {
			return $reset;
		}
		$lineage            = array(
			'recycled_at'     => gmdate('c'),
			'previous_head'   => $existing['head'] ?? null,
			'new_head'        => $target_head,
			'previous_branch' => $existing['branch'] ?? null,
			'new_branch'      => $branch,
			'previous_task'   => $existing['task'] ?? null,
			'new_task'        => $task,
			'base_ref'        => $base,
		);
		$metadata           = array_merge($metadata, array(
			'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE,
			'last_seen_at'    => gmdate('c'),
			'observed_at'     => gmdate('c'),
			'origin_task'     => $task,
			'purpose'         => $intent['purpose'] ?? null,
			'owner_run_ref'   => $intent['owner_run_ref'] ?? null,
			'cleanup_policy'  => $intent['cleanup_policy'] ?? null,
			'recycle_lineage' => array_merge( (array) ( $metadata['recycle_lineage'] ?? array() ), array( $lineage )),
		));
		$metadata_preflight = function_exists('apply_filters') ? apply_filters('datamachine_code_worktree_recycle_metadata_preflight', null, $metadata, $handle) : null;
		if ( $metadata_preflight instanceof \WP_Error ) {
			return $this->worktree_recycle_rollback_error($handle, $path, $previous_head, $existing['metadata'] ?? array(), 'metadata_persistence', $metadata_preflight);
		}
		$stored = WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);
		if ( is_wp_error($stored) ) {
			return $this->worktree_recycle_rollback_error($handle, $path, $previous_head, $existing['metadata'] ?? array(), 'metadata_persistence', $stored);
		}
		return array(
			'success'        => true,
			'handle'         => $handle,
			'path'           => $existing['path'],
			'branch'         => $branch,
			'slug'           => $this->slugify_branch($branch),
			'created_branch' => false,
			'recycled'       => true,
			'recycle'        => array(
				'status'      => 'accepted',
				'reason_code' => 'terminal_exact_handle',
				'lineage'     => $lineage,
				'context'     => 'preserved',
				'bootstrap'   => 'preserved',
			),
			'metadata'       => WorktreeContextInjector::get_metadata($handle),
			'message'        => sprintf('Recycled terminal worktree "%s" at %s; compatible context and bootstrap assets were preserved.', $handle, $existing['path']),
		);
	}

	/**
	 * Claim an exact worktree whose anonymous heartbeat has expired, or whose
	 * lifecycle has an authoritative terminal signal. This is intentionally a
	 * named operation: ordinary compatible reuse never transfers ownership.
	 */
	private function claim_expired_worktree( string $handle, string $branch, ?string $from, bool $inject_context, bool $bootstrap, array $task, array $intent, string $primary_path ): array|\WP_Error {
		$inspection = $this->worktree_get($handle, array(
			'include_status' => true,
			'include_disk'   => false,
		));
		if ( is_wp_error($inspection) || empty($inspection['worktrees'][0]) ) {
			return $this->worktree_reuse_refused($handle, 'inspection_failed', array( 'error_code' => is_wp_error($inspection) ? $inspection->get_error_code() : 'worktree_not_found' ));
		}
		$existing                      = $inspection['worktrees'][0];
		$metadata                      = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : array();
		$evidence                      = $this->worktree_reuse_evidence($handle, $existing, $metadata);
		$liveness                      = WorktreeContextInjector::classify_liveness($metadata);
		$evidence['liveness_evidence'] = $liveness;

		if ( array() !== WorktreeContextInjector::missing_isolation_intent($intent) ) {
			return $this->worktree_reuse_refused($handle, 'claim_ownership_required', $evidence + array( 'missing_ownership_fields' => WorktreeContextInjector::missing_isolation_intent($intent) ));
		}
		if ( ( $existing['branch'] ?? null ) !== $branch ) {
			return $this->worktree_reuse_refused($handle, 'branch_mismatch', $evidence + array( 'requested_branch' => $branch ));
		}
		foreach ( array(
			'dirty'    => 'dirty_worktree',
			'unpushed' => 'unpushed_commits',
		) as $field => $reason ) {
			if ( (int) ( $existing[ $field ] ?? 0 ) > 0 ) {
				return $this->worktree_reuse_refused($handle, $reason, $evidence);
			}
		}
		$contract = is_array($metadata['reuse_contract'] ?? null) ? $metadata['reuse_contract'] : array();
		$base     = null !== $from && '' !== trim($from) ? trim($from) : ( $contract['base_ref'] ?? null );
		if ( ! is_string($base) || '' === $base || ( $contract['base_ref'] ?? null ) !== $base ) {
			return $this->worktree_reuse_refused($handle, 'base_mismatch', $evidence + array(
				'requested_base_ref' => $base,
				'stored_base_ref'    => $contract['base_ref'] ?? null,
			));
		}
		if ( (bool) ( $contract['inject_context'] ?? null ) !== $inject_context || (bool) ( $contract['bootstrap'] ?? null ) !== $bootstrap ) {
			return $this->worktree_reuse_refused($handle, 'runtime_incompatible', $evidence);
		}
		if ( $this->worktree_reuse_task_identity($task) !== $this->worktree_reuse_task_identity( (array) ( $existing['task'] ?? array() )) ) {
			return $this->worktree_reuse_refused($handle, 'task_mismatch', $evidence + array( 'requested_task' => $task ));
		}
		$terminal = WorktreeContextInjector::has_cleanup_signal($metadata);
		if ( 'malformed' === ( $liveness['attribution'] ?? null ) ) {
			return $this->worktree_reuse_refused($handle, 'malformed_ownership_metadata', $evidence);
		}
		if ( ! $terminal && 'unattributable' !== (string) $liveness['attribution'] && 'unattributed' !== (string) $liveness['attribution'] ) {
			return $this->worktree_reuse_refused($handle, 'foreign_owned_worktree', $evidence);
		}
		if ( ! $terminal && WorktreeContextInjector::LIVENESS_STALE !== ( $liveness['liveness'] ?? null ) ) {
			return $this->worktree_reuse_refused($handle, 'fresh_unattributed_heartbeat', $evidence);
		}
		$process_probe = ( new MacOSLsofProcessPathProbe() )->snapshot_for_paths(array( (string) $existing['path'] ));
		if ( 'available' !== (string) ( $process_probe['status'] ?? '' ) ) {
			return $this->worktree_reuse_refused($handle, 'active_process_probe_unavailable', $evidence + array( 'process_probe' => $process_probe ));
		}
		if ( array() !== (array) ( $process_probe['records'] ?? array() ) ) {
			return $this->worktree_reuse_refused($handle, 'active_process', $evidence + array( 'process_probe' => $process_probe ));
		}
		$target = $this->run_git($primary_path, 'rev-parse --verify ' . escapeshellarg($base . '^{commit}'), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($target) ) {
			return $this->worktree_reuse_refused($handle, 'base_unresolved', $evidence + array( 'requested_base_ref' => $base ));
		}
		$target_head = trim( (string) ( $target['output'] ?? '' ));
		$contained   = $this->run_git( (string) $existing['path'], 'merge-base --is-ancestor HEAD ' . escapeshellarg($target_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($contained) ) {
			return $this->worktree_reuse_refused($handle, 'claim_head_not_in_base', $evidence + array(
				'requested_base_ref'  => $base,
				'requested_base_head' => $target_head,
			));
		}

		$lineage                    = array(
			'claimed_at'             => gmdate('c'),
			'previous_owner'         => WorktreeContextInjector::summarize_owner($metadata),
			'previous_owner_run_ref' => $metadata['owner_run_ref'] ?? null,
			'previous_liveness'      => $liveness,
			'new_owner_run_ref'      => $intent['owner_run_ref'],
			'new_purpose'            => $intent['purpose'],
			'base_ref'               => $base,
		);
		$metadata                   = WorktreeContextInjector::reactivate_for_reuse($metadata, array(
			'lifecycle_state'   => WorktreeContextInjector::STATE_ACTIVE,
			'last_seen_at'      => gmdate('c'),
			'observed_at'       => gmdate('c'),
			'purpose'           => $intent['purpose'],
			'owner_run_ref'     => $intent['owner_run_ref'],
			'cleanup_policy'    => $intent['cleanup_policy'],
			'ownership_lineage' => array_merge( (array) ( $metadata['ownership_lineage'] ?? array() ), array( $lineage )),
		));
		$metadata['reuse_contract'] = array_merge($contract, $intent);
		$stored                     = WorktreeContextInjector::restore_lifecycle_metadata($handle, $metadata);
		if ( is_wp_error($stored) ) {
			return new \WP_Error('worktree_claim_metadata_persistence_failed', 'Claim ownership metadata could not be persisted.', array(
				'status' => 500,
				'claim'  => array(
					'status'      => 'failed',
					'reason_code' => 'metadata_persistence',
				),
			));
		}
		return array(
			'success'        => true,
			'handle'         => $handle,
			'path'           => $existing['path'],
			'branch'         => $branch,
			'slug'           => $this->slugify_branch($branch),
			'created_branch' => false,
			'claimed'        => true,
			'claim'          => array(
				'status'      => 'accepted',
				'reason_code' => $terminal ? 'terminal_exact_handle' : 'expired_unattributed_heartbeat',
				'lineage'     => $lineage,
			),
			'metadata'       => WorktreeContextInjector::get_metadata($handle),
			'message'        => sprintf('Claimed expired or terminal worktree "%s" after exact clean branch, task, and base verification.', $handle),
		);
	}

	/** Build the shared evidence snapshot for worktree reuse decisions. */
	private function worktree_reuse_evidence( string $handle, array $existing, mixed $metadata ): array {
		$liveness = WorktreeContextInjector::classify_liveness(is_array($metadata) ? $metadata : null);
		return array(
			'handle'            => $handle,
			'branch'            => $existing['branch'] ?? null,
			'head'              => $existing['head'] ?? null,
			'dirty'             => $existing['dirty'] ?? null,
			'unpushed'          => $existing['unpushed'] ?? null,
			'liveness'          => $existing['liveness'] ?? null,
			'liveness_evidence' => $liveness,
			'task'              => $existing['task'] ?? null,
			'metadata'          => $metadata,
		);
	}

	/** Restore the old checkout and lifecycle record after a post-reset recycle failure. */
	private function worktree_recycle_rollback_error( string $handle, string $path, string $previous_head, array $previous_metadata, string $phase, \WP_Error $cause ): \WP_Error {
		$head_rollback     = '' !== $previous_head ? $this->run_git($path, 'reset --hard ' . escapeshellarg($previous_head), self::CLEANUP_GIT_PROBE_TIMEOUT) : new \WP_Error('previous_head_missing', 'Previous HEAD was unavailable for rollback.');
		$metadata_rollback = WorktreeContextInjector::restore_lifecycle_metadata($handle, $previous_metadata);
		$rollback          = array(
			'head_restored'     => ! is_wp_error($head_rollback),
			'metadata_restored' => ! is_wp_error($metadata_rollback),
		);
		return new \WP_Error(
			'worktree_recycle_' . $phase . '_failed',
			sprintf('Terminal recycle %s failed; rollback %s.', str_replace('_', ' ', $phase), in_array(false, $rollback, true) ? 'was incomplete' : 'restored the prior state'),
			array(
				'status'     => 409,
				'phase'      => $phase,
				'cause_code' => $cause->get_error_code(),
				'cause_data' => $cause->get_error_data(),
				'recycle'    => array(
					'status'      => 'failed',
					'reason_code' => $phase,
					'rollback'    => $rollback,
				),
			)
		);
	}

	/** @return \WP_Error Typed evidence for a non-reusable exact handle. */
	private function worktree_reuse_refused( string $handle, string $reason_code, array $evidence ): \WP_Error {
		$conflicting_handle = '';
		if ( 'same_task_candidate_requires_explicit_isolation' === $reason_code && ! empty($evidence['candidates'][0]['handle']) ) {
			$conflicting_handle                 = (string) $evidence['candidates'][0]['handle'];
			$evidence['conflicting_handle']     = $conflicting_handle;
			$evidence['supported_reuse_policy'] = 'isolated';
		}
		$message = '' !== $conflicting_handle
			? sprintf('Refusing to create worktree "%s": same-task candidate "%s" requires --reuse-policy=isolated with --purpose, --owner-run-ref, and --cleanup-policy=remove_on_success.', $handle, $conflicting_handle)
			: ( 'same_task_isolation_intent_required' === $reason_code
				? sprintf('Refusing to create worktree "%s": --reuse-policy=isolated requires --purpose, --owner-run-ref, and --cleanup-policy=remove_on_success for same-task work.', $handle)
				: sprintf('Refusing to reuse worktree "%s": %s.', $handle, str_replace('_', ' ', $reason_code)) );

		return new \WP_Error(
			'worktree_reuse_refused',
			$message,
			array(
				'status' => 409,
				'reuse'  => array(
					'status'      => 'refused',
					'reason_code' => $reason_code,
				) + $evidence,
			)
		);
	}

	/** @return string Stable task identity used only to guard exact-handle reuse. */
	private function worktree_reuse_task_identity( array $task ): string {
		return isset($task['task_url']) ? (string) $task['task_url'] : strtolower((string) ( $task['task_ref'] ?? '' ));
	}

	/** Preview or attach tracker ownership for one exact active allocation without changing Git state. */
	public function worktree_attach_tracker( string $handle, array $task, bool $dry_run = false ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}
		$parsed = $this->parse_handle(trim($handle));
		if ( ! $parsed['is_worktree'] || $handle !== $parsed['dir_name'] ) {
			return new \WP_Error('not_a_worktree', 'An exact canonical managed worktree handle is required.', array( 'status' => 400 ));
		}
		$task = array_filter(array(
			'task_url' => TaskUrl::canonicalize($task['task_url'] ?? null),
			'task_ref' => null !== WorktreeContextInjector::normalize_scalar_metadata_value($task['task_ref'] ?? null) && ! preg_match('/\s/', (string) $task['task_ref']) ? strtolower(trim((string) $task['task_ref'])) : null,
		), static fn( mixed $value ): bool => null !== $value);
		$task_identity = $this->worktree_reuse_task_identity($task);
		if ( '' === $task_identity ) {
			return new \WP_Error('worktree_tracker_required', 'A valid task_url or task_ref is required.', array( 'status' => 400 ));
		}

		$assess = fn(): array|\WP_Error => $this->worktree_tracker_attachment_assessment($parsed, $task, $task_identity);
		if ( $dry_run ) {
			$assessment = $assess();
			if ( is_wp_error($assessment) ) {
				return $assessment;
			}
			return array(
				'success'             => true,
				'dry_run'             => true,
				'status'              => '' === $assessment['existing_identity'] ? 'eligible' : 'already_attached',
				'handle'              => $assessment['handle'],
				'path'                => $assessment['path'],
				'branch'              => $assessment['branch'],
				'worktree_sha'        => $assessment['worktree_sha'],
				'tracker'             => $task,
				'task_identity'       => $task_identity,
				'allocation_identity' => $assessment['allocation_identity'],
				'provider_resolution' => $assessment['provider_resolution'],
			);
		}

		return WorkspaceMutationLock::with_repo($this->workspace_path, (string) $parsed['repo'], function () use ( $assess, $task, $task_identity ) {
			$assessment = $assess();
			if ( is_wp_error($assessment) ) {
				return $assessment;
			}
			$handle            = $assessment['handle'];
			$path              = $assessment['path'];
			$metadata          = $assessment['metadata'];
			$authorization     = $assessment['authorization'];
			$existing_identity = $assessment['existing_identity'];
			$before_head       = $assessment['worktree_sha'];
			$before_branch     = $assessment['branch'];

			$status = '' === $existing_identity ? 'attached' : 'already_attached';
			if ( 'attached' === $status ) {
				$stored = WorktreeContextInjector::store_lifecycle_metadata($handle, array(
					'origin_task'         => $task,
					'tracker_attached_at' => gmdate('c'),
					'tracker_attached_by' => $authorization,
				));
				if ( is_wp_error($stored) || ! $stored ) {
					$readback  = WorktreeContextInjector::get_metadata_fresh($handle);
					$committed = is_array($readback) && $task_identity === $this->worktree_reuse_task_identity((array) ( $readback['origin_task'] ?? array() ));
					return new \WP_Error('worktree_tracker_persist_failed', 'Tracker metadata could not be completely persisted.', array( 'status' => 500, 'handle' => $handle, 'mutation_committed' => $committed, 'retry_safe' => true, 'cause_code' => is_wp_error($stored) ? $stored->get_error_code() : null ));
				}
			}

			$readback = WorktreeContextInjector::get_metadata_fresh($handle);
			if ( ! is_array($readback) || $task_identity !== $this->worktree_reuse_task_identity((array) ( $readback['origin_task'] ?? array() )) || ! WorktreeContextInjector::standalone_worktree_tracker_is_current($readback) ) {
				return new \WP_Error('worktree_tracker_readback_failed', 'The attached tracker could not be verified across managed metadata stores.', array( 'status' => 500, 'handle' => $handle, 'mutation_committed' => true, 'retry_safe' => true ));
			}

			$final_head   = $this->run_git($path, 'rev-parse --verify HEAD^{commit}', self::CLEANUP_GIT_PROBE_TIMEOUT);
			$final_branch = $this->run_git($path, 'branch --show-current', self::CLEANUP_GIT_PROBE_TIMEOUT);
			$final_dirty  = $this->worktree_tracker_dirty_paths($path);
			if ( is_wp_error($final_head) || is_wp_error($final_branch) || is_wp_error($final_dirty)
				|| ! hash_equals($before_head, trim((string) ( $final_head['output'] ?? '' )))
				|| ! hash_equals($before_branch, trim((string) ( $final_branch['output'] ?? '' )))
				|| array() !== $final_dirty ) {
				return new \WP_Error('worktree_tracker_git_state_changed', 'The worktree Git state changed during tracker attachment.', array( 'status' => 409, 'handle' => $handle, 'mutation_committed' => 'attached' === $status ));
			}

			$provider = ( new StandaloneWorktreeProvider() )->resolve_identity($this->workspace_path, $handle);
			if ( 'owned' !== ( $provider['status'] ?? null ) || $task_identity !== (string) ( $provider['task_url'] ?? $provider['task_ref'] ?? '' ) ) {
				return new \WP_Error('worktree_tracker_provider_resolution_failed', 'A fresh standalone provider resolution could not prove the attached tracker.', array( 'status' => 500, 'handle' => $handle, 'mutation_committed' => 'attached' === $status, 'retry_safe' => true, 'provider_resolution' => $provider ));
			}

			$receipt = array(
				'version'       => 1,
				'proof_id'      => bin2hex(random_bytes(16)),
				'allocation_id' => (string) $readback['allocation_id'],
				'handle'        => $handle,
				'path'          => $path,
				'branch'        => $before_branch,
				'worktree_sha'  => $before_head,
				'tracker'       => $task,
				'provider_token' => (string) $provider['token'],
				'resolved_at'   => gmdate('c'),
			);
			$receipt['digest'] = hash('sha256', $this->worktree_handoff_proof_canonical_json($receipt));
			return array(
				'success'             => true,
				'status'              => $status,
				'handle'              => $handle,
				'tracker'             => $task,
				'allocation_identity' => array_intersect_key($receipt, array_flip(array( 'allocation_id', 'handle', 'path', 'branch', 'worktree_sha' ))),
				'provider_resolution' => $provider,
				'receipt'             => $receipt,
			);
		});
	}

	/** Run every non-mutating eligibility predicate shared by preview and apply. */
	private function worktree_tracker_attachment_assessment( array $parsed, array $task, string $task_identity ): array|\WP_Error {
		$handle   = (string) $parsed['dir_name'];
		$path     = $this->workspace_path . '/' . $handle;
		$metadata = WorktreeContextInjector::get_metadata_fresh($handle);
		if ( ! is_array($metadata) || '' === (string) ( $metadata['allocation_id'] ?? '' ) ) {
			return new \WP_Error('worktree_tracker_metadata_missing', 'The exact worktree has no durable managed allocation identity.', array( 'status' => 409, 'handle' => $handle ));
		}
		if ( WorktreeContextInjector::STATE_ACTIVE !== (string) ( $metadata['lifecycle_state'] ?? '' ) ) {
			return new \WP_Error('worktree_tracker_lifecycle_not_active', 'Tracker attachment requires an active managed allocation.', array( 'status' => 409, 'handle' => $handle, 'lifecycle_state' => $metadata['lifecycle_state'] ?? null ));
		}
		if ( ! is_dir($path) || ! file_exists($path . '/.git') || $path !== (string) ( $metadata['path'] ?? '' ) ) {
			return new \WP_Error('worktree_not_found', 'The metadata-bound exact worktree is not present at its canonical path.', array( 'status' => 404, 'handle' => $handle, 'path' => $path ));
		}

		$authorization = $this->worktree_tracker_authorization($metadata);
		if ( is_wp_error($authorization) ) {
			return $authorization;
		}
		$existing_task     = is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : array();
		$existing_identity = $this->worktree_reuse_task_identity($existing_task);
		if ( '' !== $existing_identity && ! hash_equals($existing_identity, $task_identity) ) {
			return new \WP_Error('worktree_tracker_conflict', 'The exact worktree already has conflicting tracker ownership.', array( 'status' => 409, 'handle' => $handle, 'existing_task' => $existing_task, 'requested_task' => $task ));
		}

		$allocations = $this->worktree_tracker_allocations((string) $parsed['repo'], $task_identity);
		if ( is_wp_error($allocations) ) {
			return $allocations;
		}
		$duplicates = array_values(array_filter(
			$allocations,
			static fn( array $candidate ): bool => (string) ( $candidate['handle'] ?? '' ) !== $handle
		));
		if ( array() !== $duplicates ) {
			return new \WP_Error('worktree_tracker_allocation_ambiguous', 'Another managed allocation already owns the requested tracker.', array( 'status' => 409, 'handle' => $handle, 'conflicts' => $duplicates ));
		}

		$head   = $this->run_git($path, 'rev-parse --verify HEAD^{commit}', self::CLEANUP_GIT_PROBE_TIMEOUT);
		$branch = $this->run_git($path, 'branch --show-current', self::CLEANUP_GIT_PROBE_TIMEOUT);
		$dirty  = $this->worktree_tracker_dirty_paths($path);
		if ( is_wp_error($head) || is_wp_error($branch) || is_wp_error($dirty) ) {
			return new \WP_Error('worktree_tracker_probe_failed', 'Could not prove the exact worktree Git state before attachment.', array( 'status' => 409, 'handle' => $handle ));
		}
		if ( array() !== $dirty ) {
			return new \WP_Error('worktree_dirty', 'Tracker attachment requires a clean worktree.', array( 'status' => 409, 'handle' => $handle, 'dirty_count' => count($dirty), 'dirty_paths' => array_slice($dirty, 0, 25) ));
		}
		$before_head   = trim((string) ( $head['output'] ?? '' ));
		$before_branch = trim((string) ( $branch['output'] ?? '' ));
		if ( ! hash_equals((string) ( $metadata['branch'] ?? '' ), $before_branch) ) {
			return new \WP_Error('worktree_tracker_identity_drift', 'The metadata branch does not match the exact worktree.', array( 'status' => 409, 'handle' => $handle ));
		}

		$provider = ( new StandaloneWorktreeProvider() )->resolve_identity($this->workspace_path, $handle);
		if ( 'owned' !== ( $provider['status'] ?? null ) || $handle !== (string) ( $provider['handle'] ?? '' ) || $path !== (string) ( $provider['path'] ?? '' ) || ! hash_equals($before_branch, (string) ( $provider['branch'] ?? '' )) ) {
			return new \WP_Error('worktree_tracker_provider_resolution_failed', 'A fresh standalone provider resolution could not prove the exact worktree identity.', array( 'status' => 500, 'handle' => $handle, 'mutation_committed' => false, 'retry_safe' => true, 'provider_resolution' => $provider ));
		}
		$provider_tracker  = array(
			'task_url' => TaskUrl::canonicalize($provider['task_url'] ?? null),
			'task_ref' => null !== WorktreeContextInjector::normalize_scalar_metadata_value($provider['task_ref'] ?? null) ? strtolower(trim((string) $provider['task_ref'])) : null,
		);
		$provider_identity = $this->worktree_reuse_task_identity(array_filter($provider_tracker, static fn( mixed $value ): bool => null !== $value));
		if ( '' !== $existing_identity && ( ! hash_equals($existing_identity, $provider_identity) || ! WorktreeContextInjector::standalone_worktree_tracker_is_current($metadata) ) ) {
			return new \WP_Error('worktree_tracker_provider_mismatch', 'The standalone provider tracker does not match durable lifecycle ownership.', array( 'status' => 409, 'handle' => $handle, 'lifecycle_tracker' => $existing_task, 'provider_tracker' => $provider_tracker ));
		}
		if ( '' !== $provider_identity ) {
			foreach ( $task as $field => $value ) {
				if ( $value !== ( $provider_tracker[ $field ] ?? null ) ) {
					return new \WP_Error('worktree_tracker_conflict', 'The standalone provider already has conflicting tracker ownership.', array( 'status' => 409, 'handle' => $handle, 'existing_task' => $provider_tracker, 'requested_task' => $task ));
				}
			}
		}

		return array(
			'handle'              => $handle,
			'path'                => $path,
			'branch'              => $before_branch,
			'worktree_sha'        => $before_head,
			'metadata'            => $metadata,
			'authorization'       => $authorization,
			'existing_identity'   => $existing_identity,
			'provider_resolution' => $provider,
			'allocation_identity' => array(
				'allocation_id' => (string) $metadata['allocation_id'],
				'handle'        => $handle,
				'path'          => $path,
				'branch'        => $before_branch,
				'worktree_sha'  => $before_head,
			),
		);
	}

	/** Probe attachment cleanliness without allowing Git to refresh index metadata. */
	private function worktree_tracker_dirty_paths( string $path ): array|\WP_Error {
		$result = $this->run_git($path, '--no-optional-locks status --porcelain', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($result) ) {
			return $result;
		}
		return array_values(array_filter(array_map('trim', explode("\n", (string) ( $result['output'] ?? '' )))));
	}

	/** Require complete exact site, agent, and session ownership. */
	private function worktree_tracker_authorization( array $metadata ): array|\WP_Error {
		$current = WorktreeContextInjector::current_ownership_identity();
		$stored  = array_intersect_key($metadata, array_flip(array( 'origin_site_url', 'origin_agent', 'origin_session' )));
		foreach ( array( 'origin_site_url', 'origin_agent', 'origin_session' ) as $field ) {
			if ( empty($stored[ $field ]) || empty($current[ $field ]) ) {
				return new \WP_Error('worktree_tracker_owner_unattributed', 'Tracker attachment requires complete current and stored site, agent, and session ownership.', array( 'status' => 409, 'field' => $field ));
			}
			if ( $this->worktree_handoff_proof_canonical_json((array) array( $stored[ $field ] )) !== $this->worktree_handoff_proof_canonical_json((array) array( $current[ $field ] )) ) {
				return new \WP_Error('worktree_tracker_owner_mismatch', 'The current site, agent, or session does not own this worktree allocation.', array( 'status' => 409, 'field' => $field, 'stored' => $stored[ $field ], 'current' => $current[ $field ] ));
			}
		}
		return $current;
	}

	/** @return array<int,array{handle:string,path:string}>|\WP_Error */
	private function worktree_tracker_allocations( string $repo, string $task_identity ): array|\WP_Error {
		$listing = $this->run_git($this->workspace_path . '/' . $repo, 'worktree list --porcelain', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($listing) ) {
			return new \WP_Error('worktree_tracker_allocations_unverified', 'Could not prove that the requested tracker has no conflicting managed allocation.', array( 'status' => 409, 'repo' => $repo ));
		}
		$matches = array();
		foreach ( $this->worktree_list_blocks((string) ( $listing['output'] ?? '' )) as $block ) {
			$worktree = $this->parse_worktree_block($block);
			if ( null === $worktree || ! str_starts_with($worktree['path'], $this->workspace_path . '/') ) {
				continue;
			}
			$handle   = substr($worktree['path'], strlen($this->workspace_path . '/'));
			$metadata  = WorktreeContextInjector::get_metadata_fresh($handle);
			$candidate = is_array($metadata) && is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : array();
			if ( $task_identity === $this->worktree_reuse_task_identity($candidate) ) {
				$matches[] = array( 'handle' => $handle, 'path' => $worktree['path'] );
			}
		}
		return $matches;
	}

	/**
	 * Attach lifecycle finalizer metadata to a worktree record.
	 *
	 * @param  string      $handle Workspace worktree handle.
	 * @param  string      $state  Lifecycle state.
	 * @param  string|null $pr     Optional PR URL or number.
	 * @return array{success: bool, handle: string, path: string, lifecycle_state: string, metadata: array, message: string}|\WP_Error
	 */
	public function worktree_finalize( string $handle, string $state, ?string $pr = null, ?string $owner_terminal_outcome = null, mixed $until_budget = null, ?callable $progress_callback = null ): array|\WP_Error {
		$parsed = $this->parse_handle($handle);
		if ( ! $parsed['is_worktree'] ) {
			return new \WP_Error('not_a_worktree', sprintf('Handle "%s" is a primary checkout, not a worktree.', $handle), array( 'status' => 400 ));
		}

		$normalized_state = WorktreeContextInjector::normalize_state($state);
		if ( null === $normalized_state ) {
			return new \WP_Error('invalid_lifecycle_state', sprintf('Invalid lifecycle state "%s". Valid states: %s.', $state, implode(', ', WorktreeContextInjector::VALID_STATES)), array( 'status' => 400 ));
		}

		$wt_path = $this->workspace_path . '/' . $parsed['dir_name'];
		if ( ! is_dir($wt_path) ) {
			return new \WP_Error('worktree_not_found', sprintf('Worktree "%s" does not exist on disk.', $parsed['dir_name']), array( 'status' => 404 ));
		}
		$budget = WallClockBudget::from_duration($until_budget, self::WORKTREE_FINALIZE_DEFAULT_BUDGET, 'invalid_worktree_finalize_budget');
		if ( is_wp_error($budget) ) {
			return $budget;
		}
		$timings     = array();
		$deadline    = microtime(true) + $budget->remaining_seconds();
		$lock_started = hrtime(true);
		$this->worktree_finalize_progress($progress_callback, 'lock_wait', 'started', $lock_started, $budget);

		$result = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$parsed['repo'],
			function () use ( $parsed, $wt_path, $normalized_state, $pr, $owner_terminal_outcome, $budget, &$timings, $progress_callback, $lock_started ) {
				$this->worktree_finalize_phase_complete('lock_wait', $lock_started, $timings, $progress_callback, $budget);
				return $this->worktree_finalize_locked($parsed, $wt_path, $normalized_state, $pr, $owner_terminal_outcome, $budget, $timings, $progress_callback);
			},
			max(1, (int) ceil($budget->remaining_seconds())),
			array(
				'_acquisition_deadline'  => $deadline,
				'_operation_deadline'    => $deadline,
				'lease_duration_seconds' => max(1, (int) ceil($budget->remaining_seconds())),
			),
			function ( array $event ) use ( $progress_callback, $lock_started, $budget ): void {
				if ( 'lock_wait' === ( $event['phase'] ?? null ) ) {
					$this->worktree_finalize_progress($progress_callback, 'lock_wait', (string) ( $event['state'] ?? 'waiting' ), $lock_started, $budget, $event);
				}
			}
		);
		if ( ! isset($timings['lock_wait']) ) {
			$this->worktree_finalize_phase_complete('lock_wait', $lock_started, $timings, $progress_callback, $budget, is_wp_error($result) ? 'failed' : 'completed');
		}

		return $this->decorate_worktree_finalize_recovery($result, $parsed['dir_name'], $normalized_state, $pr, $owner_terminal_outcome, $until_budget, $timings, $budget);
	}

	/** Run finalizer safety checks and metadata persistence under repository ownership. */
	private function worktree_finalize_locked( array $parsed, string $wt_path, string $normalized_state, ?string $pr, ?string $owner_terminal_outcome, WallClockBudget $budget, array &$timings, ?callable $progress_callback ): array|\WP_Error {
		$metadata_started = hrtime(true);
		$this->worktree_finalize_progress($progress_callback, 'metadata_persistence', 'preparing', $metadata_started, $budget);
		$retry_options = $this->worktree_finalize_retry_options($budget);
		if ( null === $retry_options ) {
			$this->worktree_finalize_phase_complete('metadata_persistence', $metadata_started, $timings, $progress_callback, $budget, 'budget_exhausted');
			return $this->worktree_finalize_budget_error('metadata_persistence', $parsed['dir_name'], $wt_path, false, $budget);
		}
		$existing_metadata = WorktreeContextInjector::get_lifecycle_metadata($parsed['dir_name'], $retry_options);
		if ( is_wp_error($existing_metadata) ) {
			$this->worktree_finalize_phase_complete('metadata_persistence', $metadata_started, $timings, $progress_callback, $budget, 'failed');
			return $this->worktree_finalize_phase_error('metadata_persistence', $parsed['dir_name'], $wt_path, $existing_metadata);
		}
		$existing_metadata = is_array($existing_metadata) ? $existing_metadata : array();
		if ( $budget->expired() ) {
			$this->worktree_finalize_phase_complete('metadata_persistence', $metadata_started, $timings, $progress_callback, $budget, 'budget_exhausted');
			return $this->worktree_finalize_budget_error('metadata_persistence', $parsed['dir_name'], $wt_path, false, $budget);
		}
		$metadata = WorktreeContextInjector::build_finalizer_metadata($normalized_state, $pr, $owner_terminal_outcome, $existing_metadata);
		$metadata = array_merge(
			array(
				'handle' => $parsed['dir_name'],
				'path'   => $wt_path,
				'repo'   => $parsed['repo'],
			),
			$metadata
		);
		$this->worktree_finalize_phase_complete('metadata_persistence', $metadata_started, $timings, $progress_callback, $budget, 'prepared');
		if ( WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			$phase_started       = hrtime(true);
			$this->worktree_finalize_progress($progress_callback, 'dirty_probe', 'started', $phase_started, $budget);
			$dirty_probe_timeout = $budget->probe_timeout_seconds(WorkspaceTargetInspector::timeout_seconds($parsed['dir_name']));
			if ( 0 === $dirty_probe_timeout ) {
				$this->worktree_finalize_phase_complete('dirty_probe', $phase_started, $timings, $progress_callback, $budget, 'budget_exhausted');
				return $this->worktree_finalize_budget_error('dirty_probe', $parsed['dir_name'], $wt_path, false, $budget);
			}
			$dirty_paths         = $this->probe_worktree_dirty_paths($wt_path, $dirty_probe_timeout);
			if ( $dirty_paths instanceof \WP_Error ) {
				$this->worktree_finalize_phase_complete('dirty_probe', $phase_started, $timings, $progress_callback, $budget, 'failed');
				return $this->worktree_finalize_phase_error('dirty_probe', $parsed['dir_name'], $wt_path, $dirty_paths, false, $dirty_probe_timeout);
			}
			$this->worktree_finalize_phase_complete('dirty_probe', $phase_started, $timings, $progress_callback, $budget);
			if ( array() !== $dirty_paths ) {
				return new \WP_Error(
					'worktree_dirty',
					sprintf('Refusing to mark worktree "%s" terminal because git status reports %d dirty path(s). Commit, stash, or discard the changes, then finalize again.', $parsed['dir_name'], count($dirty_paths)),
					array(
						'status'      => 409,
						'handle'      => $parsed['dir_name'],
						'path'        => $wt_path,
						'dirty_count' => count($dirty_paths),
						'dirty_paths' => array_slice($dirty_paths, 0, 25),
						'hint'        => 'Run git status --short in the worktree, resolve every listed change, then retry finalization.',
					)
				);
			}
		} else {
			$timings['dirty_probe'] = array( 'elapsed_ms' => 0, 'state' => 'skipped' );
			$this->worktree_finalize_progress($progress_callback, 'dirty_probe', 'skipped', hrtime(true), $budget);
		}

		$phase_started = hrtime(true);
		$this->worktree_finalize_progress($progress_callback, 'metadata_persistence', 'started', $phase_started, $budget);
		$retry_options = $this->worktree_finalize_retry_options($budget);
		if ( null === $retry_options ) {
			$this->worktree_finalize_phase_complete('metadata_persistence', $phase_started, $timings, $progress_callback, $budget, 'budget_exhausted');
			return $this->worktree_finalize_budget_error('metadata_persistence', $parsed['dir_name'], $wt_path, false, $budget);
		}
		$stored_metadata = WorktreeContextInjector::store_lifecycle_metadata_record($parsed['dir_name'], $metadata, $retry_options, $existing_metadata);
		if ( is_wp_error($stored_metadata) ) {
			$this->worktree_finalize_phase_complete('metadata_persistence', $phase_started, $timings, $progress_callback, $budget, 'failed');
			return $this->worktree_finalize_phase_error('metadata_persistence', $parsed['dir_name'], $wt_path, $stored_metadata);
		}
		$metadata_committed = WorktreeContextInjector::lifecycle_metadata_record_is_durable();
		if ( $metadata_committed ) {
			$tracker = WorktreeContextInjector::store_standalone_worktree_tracker($stored_metadata);
			if ( is_wp_error($tracker) ) {
				$this->worktree_finalize_phase_complete('metadata_persistence', $phase_started, $timings, $progress_callback, $budget, 'failed');
				return $this->worktree_finalize_phase_error('metadata_persistence', $parsed['dir_name'], $wt_path, $tracker, true);
			}
		}
		$this->worktree_finalize_phase_complete('metadata_persistence', $phase_started, $timings, $progress_callback, $budget);

		$phase_started = hrtime(true);
		$this->worktree_finalize_progress($progress_callback, 'inventory_upsert', 'started', $phase_started, $budget);
		$retry_options = $this->worktree_finalize_retry_options($budget);
		if ( null === $retry_options ) {
			$this->worktree_finalize_phase_complete('inventory_upsert', $phase_started, $timings, $progress_callback, $budget, 'budget_exhausted');
			return $this->worktree_finalize_budget_error('inventory_upsert', $parsed['dir_name'], $wt_path, $metadata_committed, $budget);
		}
		$inventory = WorktreeContextInjector::upsert_lifecycle_metadata_inventory($parsed['dir_name'], $stored_metadata, $retry_options);
		if ( is_wp_error($inventory) ) {
			$this->worktree_finalize_phase_complete('inventory_upsert', $phase_started, $timings, $progress_callback, $budget, 'failed');
			return $this->worktree_finalize_phase_error('inventory_upsert', $parsed['dir_name'], $wt_path, $inventory, $metadata_committed);
		}
		$metadata_committed = true;
		if ( ! WorktreeContextInjector::lifecycle_metadata_record_is_durable() ) {
			$tracker = WorktreeContextInjector::store_standalone_worktree_tracker($stored_metadata);
			if ( is_wp_error($tracker) ) {
				$this->worktree_finalize_phase_complete('inventory_upsert', $phase_started, $timings, $progress_callback, $budget, 'failed');
				return $this->worktree_finalize_phase_error('inventory_upsert', $parsed['dir_name'], $wt_path, $tracker, true);
			}
		}
		$this->worktree_finalize_phase_complete('inventory_upsert', $phase_started, $timings, $progress_callback, $budget);

		$phase_started = hrtime(true);
		$this->worktree_finalize_progress($progress_callback, 'readback', 'started', $phase_started, $budget);
		$retry_options = $this->worktree_finalize_retry_options($budget);
		if ( null === $retry_options ) {
			$this->worktree_finalize_phase_complete('readback', $phase_started, $timings, $progress_callback, $budget, 'budget_exhausted');
			return $this->worktree_finalize_budget_error('readback', $parsed['dir_name'], $wt_path, true, $budget);
		}
		$stored = WorktreeContextInjector::get_lifecycle_inventory_metadata($parsed['dir_name'], $retry_options);
		if ( is_wp_error($stored) ) {
			$this->worktree_finalize_phase_complete('readback', $phase_started, $timings, $progress_callback, $budget, 'failed');
			return $this->worktree_finalize_phase_error('readback', $parsed['dir_name'], $wt_path, $stored, true);
		}
		$stored = is_array($stored) ? $stored : array();
		if ( ! $this->worktree_metadata_contains($stored, $metadata) ) {
			$this->worktree_finalize_phase_complete('readback', $phase_started, $timings, $progress_callback, $budget, 'failed');
			return $this->worktree_finalize_phase_error('readback', $parsed['dir_name'], $wt_path, new \WP_Error('worktree_metadata_readback_incomplete', 'Lifecycle metadata could not be read back after finalization. Retry finalization; no cleanup should proceed until the lifecycle state is visible.', array( 'status' => 500 )), true);
		}
		$this->worktree_finalize_phase_complete('readback', $phase_started, $timings, $progress_callback, $budget);
		return array(
			'success'         => true,
			'handle'          => $parsed['dir_name'],
			'path'            => $wt_path,
			'lifecycle_state' => (string) ( $stored['lifecycle_state'] ?? $normalized_state ),
			'metadata'        => $stored,
			'message'         => sprintf('Worktree "%s" marked %s.', $parsed['dir_name'], (string) ( $stored['lifecycle_state'] ?? $normalized_state )),
		);
	}

	/** Attach one exact idempotent replay command to a retry-safe finalization failure. */
	private function decorate_worktree_finalize_recovery( mixed $result, string $handle, string $state, ?string $pr, ?string $owner_terminal_outcome, mixed $until_budget, array $timings, WallClockBudget $budget ): mixed {
		foreach ( array( 'lock_wait', 'dirty_probe', 'metadata_persistence', 'inventory_upsert', 'readback' ) as $phase ) {
			$timings[ $phase ] = $timings[ $phase ] ?? array( 'elapsed_ms' => 0, 'state' => 'not_started' );
		}
		if ( is_array($result) ) {
			$result['phase_timings']    = $timings;
			$result['wall_clock_budget'] = $budget->evidence();
			return $result;
		}
		if ( ! is_wp_error($result) ) {
			return $result;
		}

		$data                                 = (array) $result->get_error_data();
		$data['phase']                        = $data['phase'] ?? 'lock_wait';
		$data['phase_timings']                = $timings;
		$data['wall_clock_budget']            = $budget->evidence();
		$data['lifecycle_metadata_committed'] = (bool) ( $data['lifecycle_metadata_committed'] ?? ! empty($data['lock_callback_completed']) );
		$data['metadata_committed']           = $data['lifecycle_metadata_committed'];
		$data['blocker_owner']                = $data['owner'] ?? null;
		$data['retry_after_seconds']          = (int) ( $data['retry_after_seconds'] ?? 1 );
		$data['retry_safe']                   = true;

		$command = $this->worktree_finalize_retry_command($handle, $state, $pr, $owner_terminal_outcome, $until_budget);
		if ( null !== $command ) {
			$data['retry_command'] = $command;
			$data['recovery']      = array(
				'type'               => 'worktree_finalize_replay',
				'idempotent'         => true,
				'blocked_phase'      => $data['phase'],
				'metadata_committed' => $data['lifecycle_metadata_committed'],
				'command'            => $command,
			);
		}
		return new \WP_Error($result->get_error_code(), $result->get_error_message(), $data);
	}

	/** Build a secret-safe replay of the normalized finalizer request. */
	private function worktree_finalize_retry_command( string $handle, string $state, ?string $pr, ?string $owner_terminal_outcome, mixed $until_budget = null ): ?string {
		$parts = array(
			'wp datamachine-code workspace worktree finalize',
			escapeshellarg($handle),
			'--state=' . escapeshellarg($state),
		);
		if ( null !== $pr && '' !== trim($pr) ) {
			$parsed_url = parse_url($pr);
			if ( is_array($parsed_url) && ( isset($parsed_url['user']) || isset($parsed_url['pass']) ) ) {
				return null;
			}
			$parts[] = '--pr=' . escapeshellarg(trim($pr));
		}
		if ( null !== $owner_terminal_outcome && '' !== trim($owner_terminal_outcome) ) {
			$parts[] = '--owner-terminal-outcome=' . escapeshellarg(trim($owner_terminal_outcome));
		}
		if ( is_scalar($until_budget) && '' !== trim((string) $until_budget) ) {
			$parts[] = '--until-budget=' . escapeshellarg(trim((string) $until_budget));
		}

		return implode(' ', $parts);
	}

	/**
	 * Resolve one local worktree without enumerating workspace primaries.
	 *
	 * `$handle_or_path` accepts an exact workspace handle or an exact canonical
	 * path to a direct child of the canonical workspace root.
	 *
	 * @param array{include_status?: bool, include_disk?: bool} $opts Probe options.
	 * @return array{success: bool, worktrees: array, fields_skipped: array<int,string>}|\WP_Error
	 */
	public function worktree_get( string $handle_or_path, array $opts = array() ): array|\WP_Error {
		$target = trim($handle_or_path);
		$path   = '';
		$parsed = null;

		if ( str_starts_with($target, '/') ) {
			$workspace_path = realpath($this->workspace_path);
			$path           = realpath($target);
			if ( false !== $workspace_path && false !== $path && $target === $path && dirname($path) === $workspace_path ) {
				$candidate = basename($path);
				$parsed    = $this->parse_handle($candidate);
				if ( $candidate !== $parsed['dir_name'] ) {
					$parsed = null;
				}
			}
		} else {
			$parsed = $this->parse_handle($target);
			if ( $target !== $parsed['dir_name'] ) {
				$parsed = null;
			} else {
				$path = $this->workspace_path . '/' . $parsed['dir_name'];
			}
		}

		if ( ! is_array($parsed) || '' === $parsed['dir_name'] || false === $path || ! is_dir($path) || ! file_exists($path . '/.git') ) {
			$not_found_handle = is_array($parsed) ? $parsed['dir_name'] : $target;
			return new \WP_Error(
				'worktree_not_found',
				sprintf('Worktree "%s" does not exist on disk.', $not_found_handle),
				array(
					'status' => 404,
					'handle' => $not_found_handle,
					'path'   => '' !== $path ? $path : $target,
				)
			);
		}

		$include_status = array_key_exists('include_status', $opts) ? (bool) $opts['include_status'] : true;
		$include_disk   = array_key_exists('include_disk', $opts) ? (bool) $opts['include_disk'] : false;
		$budget         = $opts['wall_clock_budget'] ?? null;
		$budget         = $budget instanceof WallClockBudget ? $budget : WallClockBudget::from_duration($opts['until_budget'] ?? null, '30s', 'invalid_worktree_get_budget');
		if ( is_wp_error($budget) ) {
			return $budget;
		}
		$skipped_groups = array();
		if ( ! $include_status ) {
			$skipped_groups[] = 'status';
		}
		if ( ! $include_disk ) {
			$skipped_groups[] = 'disk';
		}
		$metadata = $parsed['is_worktree'] ? WorktreeContextInjector::get_metadata($parsed['dir_name']) : null;
		$metadata = is_array($metadata) ? $metadata : null;
		$task_ref = $this->normalize_worktree_list_task_ref($opts['task_ref'] ?? null);
		$owner_run_ref = $this->normalize_worktree_list_owner_run_ref($opts['owner_run_ref'] ?? null);
		if ( ! $this->worktree_list_matches_metadata_filters($metadata, $task_ref, $owner_run_ref) ) {
			return array(
				'success'               => true,
				'worktrees'             => array(),
				'duplicates'            => array(),
				'base_branch_worktrees' => array(),
				'fields_skipped'        => $skipped_groups,
			);
		}

		$timeout = $budget->probe_timeout_seconds(self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( 0 === $timeout ) {
			return $this->worktree_get_budget_error('identity', $parsed['dir_name'], $path, $budget);
		}
		$head = $this->run_git($path, 'rev-parse --verify HEAD', $timeout);
		if ( $head instanceof \WP_Error ) {
			return $this->worktree_get_probe_error('identity', $parsed['dir_name'], $path, $head);
		}
		$timeout = $budget->probe_timeout_seconds(self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( 0 === $timeout ) {
			return $this->worktree_get_budget_error('identity', $parsed['dir_name'], $path, $budget);
		}
		$branch = $this->run_git($path, 'branch --show-current', $timeout);
		if ( $branch instanceof \WP_Error ) {
			return $this->worktree_get_probe_error('identity', $parsed['dir_name'], $path, $branch);
		}

		if ( $include_status ) {
			$timeout = $budget->probe_timeout_seconds(self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( 0 === $timeout ) {
				return $this->worktree_get_budget_error('status', $parsed['dir_name'], $path, $budget);
			}
			$dirty_paths = $this->probe_worktree_dirty_paths($path, $timeout);
			if ( $dirty_paths instanceof \WP_Error ) {
				return $this->worktree_get_probe_error('status', $parsed['dir_name'], $path, $dirty_paths);
			}
			$timeout = $budget->probe_timeout_seconds(self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( 0 === $timeout ) {
				return $this->worktree_get_budget_error('unpushed', $parsed['dir_name'], $path, $budget);
			}
			$unpushed = $this->count_unpushed_commits($path, $timeout);
			if ( $unpushed instanceof \WP_Error ) {
				return $this->worktree_get_probe_error('unpushed', $parsed['dir_name'], $path, $unpushed);
			}
			$dirty = count($dirty_paths);
		} else {
			$dirty    = null;
			$unpushed = null;
		}

		$created_at   = $metadata['created_at'] ?? null;
		$liveness     = WorktreeContextInjector::classify_liveness($metadata);
		$disk         = $include_disk ? $this->build_worktree_disk_report($parsed['repo'], $path, $parsed['is_worktree'], $created_at, $metadata, $budget) : array(
			'size_bytes'           => null,
			'estimated_size_bytes' => null,
			'last_touched_at'      => null,
			'age_days'             => $this->calculate_age_days($created_at),
			'artifacts'            => array(),
			'artifact_size_bytes'  => 0,
		);
		$row          = array_merge(
			array(
				'handle'                 => $parsed['dir_name'],
				'repo'                   => $parsed['repo'],
				'is_worktree'            => $parsed['is_worktree'],
				'is_primary'             => ! $parsed['is_worktree'],
				'external'               => false,
				'branch_slug'            => $parsed['branch_slug'],
				'branch'                 => trim( (string) ( $branch['output'] ?? '' )),
				'head'                   => trim( (string) ( $head['output'] ?? '' )),
				'path'                   => $path,
				'dirty'                  => $dirty,
				'unpushed'               => $unpushed,
				'created_at'             => $created_at,
				'lifecycle_state'        => null === $metadata ? null : WorktreeContextInjector::project_lifecycle_state($metadata),
				'readiness'              => WorktreeContextInjector::bootstrap_readiness($metadata),
				'pr_url'                 => $metadata['pr_url'] ?? null,
				'pr_number'              => $metadata['pr_number'] ?? null,
				'purpose'                => $metadata['purpose'] ?? null,
				'owner_run_ref'          => $metadata['owner_run_ref'] ?? null,
				'cleanup_policy'         => $metadata['cleanup_policy'] ?? null,
				'owner_terminal_outcome' => $metadata['owner_terminal_outcome'] ?? null,
				'last_seen_at'           => $metadata['last_seen_at'] ?? null,
				'liveness'               => $liveness['liveness'],
				'liveness_reason'        => $liveness['reason'],
				'heartbeat_age_seconds'  => $liveness['heartbeat_age_seconds'],
				'owner'                  => WorktreeContextInjector::summarize_owner($metadata),
				'session'                => WorktreeContextInjector::summarize_session($metadata),
				'task'                   => is_array($metadata['origin_task'] ?? null) ? $metadata['origin_task'] : null,
				'metadata'               => $metadata,
			),
			$disk
		);
		$stale_reason = $this->detect_worktree_stale_reason(
			$parsed['is_worktree'],
			(int) ( $dirty ?? 0 ),
			$disk['age_days'] ?? null,
			$created_at,
			array(
				'status_probed' => $include_status,
				'disk_probed'   => $include_disk,
			)
		);
		if ( null !== $stale_reason ) {
			$row['stale_reason'] = $stale_reason;
		}
		if ( ! empty($skipped_groups) ) {
			$row['fields_skipped'] = $skipped_groups;
		}

		return array(
			'success'               => true,
			'worktrees'             => array( $row ),
			'duplicates'            => array(),
			'base_branch_worktrees' => array(),
			'fields_skipped'        => $skipped_groups,
		);
	}

	private function worktree_get_budget_error( string $phase, string $handle, string $path, WallClockBudget $budget ): \WP_Error {
		return new \WP_Error(
			'worktree_get_budget_exhausted',
			'Exact worktree inspection stopped before starting another probe because its shared wall-clock budget was exhausted.',
			array(
				'status'       => 504,
				'phase'        => $phase,
				'handle'       => $handle,
				'path'         => $path,
				'retryable'    => true,
				'continuation' => array( 'command' => sprintf('wp datamachine-code workspace worktree get %s --format=json', escapeshellarg($handle)) ),
				'evidence'     => array( 'wall_clock_budget' => $budget->evidence() ),
			)
		);
	}

	/** Remaining SQLite retry allowance without permitting a phase to exceed the shared deadline. */
	private function worktree_finalize_retry_options( WallClockBudget $budget ): ?array {
		$remaining_ms = (int) floor($budget->remaining_seconds() * 1000);
		return $remaining_ms < 1 ? null : array( 'hard_max_wait_ms' => $remaining_ms );
	}

	private function worktree_finalize_budget_error( string $phase, string $handle, string $path, bool $metadata_committed, WallClockBudget $budget ): \WP_Error {
		return $this->worktree_finalize_phase_error(
			$phase,
			$handle,
			$path,
			new \WP_Error('worktree_finalize_budget_exhausted', 'The shared worktree finalization wall-clock budget was exhausted before this phase could proceed.', array(
				'status'              => 504,
				'retryable'           => true,
				'wall_clock_budget'   => $budget->evidence(),
				'retry_after_seconds' => 1,
			)),
			$metadata_committed
		);
	}

	/** Record and emit a terminal phase timing. */
	private function worktree_finalize_phase_complete( string $phase, int $started, array &$timings, ?callable $progress_callback, WallClockBudget $budget, string $state = 'completed' ): void {
		$elapsed_ms        = (int) ( $timings[ $phase ]['elapsed_ms'] ?? 0 ) + max(0, (int) round(( hrtime(true) - $started ) / 1000000));
		$timings[ $phase ] = array(
			'elapsed_ms' => $elapsed_ms,
			'state'      => $state,
		);
		$this->worktree_finalize_progress($progress_callback, $phase, $state, $started, $budget, array( 'elapsed_ms' => $elapsed_ms ));
	}

	/** Best-effort phase observability cannot alter finalization ownership. */
	private function worktree_finalize_progress( ?callable $callback, string $phase, string $state, int $started, WallClockBudget $budget, array $extra = array() ): void {
		if ( null === $callback ) {
			return;
		}
		try {
			$callback(array_merge($extra, array(
				'operation'         => 'worktree_finalize',
				'phase'             => $phase,
				'state'             => $state,
				'elapsed_ms'        => isset($extra['elapsed_ms']) ? (int) $extra['elapsed_ms'] : max(0, (int) round(( hrtime(true) - $started ) / 1000000)),
				'wall_clock_budget' => $budget->evidence(),
			)));
		} catch ( \Throwable ) {
			// Presentation failures cannot alter lifecycle metadata or lock ownership.
		}
	}

	/**
	 * Preserve the original failure while making the blocked finalization phase explicit.
	 */
	private function worktree_finalize_phase_error( string $phase, string $handle, string $path, \WP_Error $error, bool $metadata_committed = false, ?int $timeout_seconds = null ): \WP_Error {
		$data = (array) $error->get_error_data();
		$data = array_merge(
			$data,
			array(
				'status'                       => (int) ( $data['status'] ?? 500 ),
				'phase'                        => $phase,
				'handle'                       => $handle,
				'path'                         => $path,
				'cause_code'                   => $error->get_error_code(),
				'lifecycle_metadata_committed' => $metadata_committed,
				'retry_safe'                   => true,
			)
		);
		if ( null !== $timeout_seconds ) {
			$data['timeout_seconds'] = $timeout_seconds;
		}
		if ( $metadata_committed ) {
			$data['hint'] = 'Lifecycle metadata is committed but the inventory is incomplete. Retry the same finalize command; it is idempotent.';
		}

		return new \WP_Error('worktree_finalize_' . $phase . '_failed', sprintf('Worktree finalization %s failed: %s', str_replace('_', ' ', $phase), $error->get_error_message()), $data);
	}

	/**
	 * Verify that every field requested by a finalizer write is visible on readback.
	 *
	 * @param array<string,mixed> $stored   Persisted metadata.
	 * @param array<string,mixed> $expected Requested metadata.
	 */
	private function worktree_metadata_contains( array $stored, array $expected ): bool {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists($key, $stored) || $stored[ $key ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Preserve the original failure while identifying the bounded targeted-get probe.
	 */
	private function worktree_get_probe_error( string $phase, string $handle, string $path, \WP_Error $error ): \WP_Error {
		$cause_data = (array) $error->get_error_data();
		$data       = array_merge(
			$cause_data,
			array(
				'status'          => (int) ( $cause_data['status'] ?? 500 ),
				'phase'           => $phase,
				'handle'          => $handle,
				'path'            => $path,
				'cause_code'      => $error->get_error_code(),
				'timeout_seconds' => self::CLEANUP_GIT_PROBE_TIMEOUT,
			)
		);

		return new \WP_Error('worktree_get_' . $phase . '_probe_failed', sprintf('Targeted worktree lookup %s probe failed: %s', $phase, $error->get_error_message()), $data);
	}

	/**
	 * Finalize and remove the local DMC worktree for a merged PR head branch.
	 *
	 * This is the targeted post-merge path used before remote branch deletion.
	 * It only touches workspace worktrees whose primary origin matches the exact
	 * GitHub `owner/repo` slug and whose checked-out branch matches the PR head.
	 *
	 * @param  string      $github_repo GitHub repository slug (`owner/repo`).
	 * @param  string      $branch      Pull request head branch.
	 * @param  string|null $pr_url      Optional pull request URL for lifecycle metadata.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cleanup_merged_pr_worktree( string $github_repo, string $branch, ?string $pr_url = null ): array|\WP_Error {
		$github_repo = trim($github_repo);
		$branch      = trim($branch);

		if ( '' === $github_repo || '' === $branch ) {
			return new \WP_Error('missing_pr_worktree_cleanup_params', 'GitHub repo and branch are required for merged PR worktree cleanup.', array( 'status' => 400 ));
		}

		if ( in_array($branch, array( 'main', 'master', 'trunk', 'develop', 'HEAD' ), true) ) {
			return new \WP_Error('protected_head_branch', sprintf('Refusing to clean up protected branch %s.', $branch), array( 'status' => 409 ));
		}

		$listing = $this->worktree_list(
			null,
			null,
			array(
				'include_status' => false,
				'include_disk'   => false,
			)
		);
		if ( $listing instanceof \WP_Error ) {
			return $listing;
		}

		$matches = array();
		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
			if ( ! empty($wt['is_primary']) || ! empty($wt['external']) ) {
				continue;
			}

			$repo         = (string) ( $wt['repo'] ?? '' );
			$primary_path = '' !== $repo ? $this->get_primary_path($repo) : '';
			if ( '' === $primary_path || ! GitCheckout::exists($primary_path) ) {
				continue;
			}

			if ( $github_repo !== (string) $this->resolve_github_slug($primary_path) ) {
				continue;
			}

			if ( (string) ( $wt['branch'] ?? '' ) !== $branch ) {
				continue;
			}

			$matches[] = $wt;
		}

		if ( empty($matches) ) {
			return array(
				'success' => true,
				'found'   => false,
				'repo'    => $github_repo,
				'branch'  => $branch,
				'message' => sprintf('No DMC worktree found for %s:%s.', $github_repo, $branch),
			);
		}

		if ( count($matches) > 1 ) {
			return new \WP_Error(
				'ambiguous_pr_worktree_cleanup',
				sprintf('Refusing merged PR worktree cleanup because %d worktrees match %s:%s.', count($matches), $github_repo, $branch),
				array(
					'status'  => 409,
					'matches' => array_map(static fn( array $wt ): string => (string) ( $wt['handle'] ?? '' ), $matches),
				)
			);
		}

		$wt      = $matches[0];
		$repo    = (string) ( $wt['repo'] ?? '' );
		$handle  = (string) ( $wt['handle'] ?? '' );
		$wt_path = (string) ( $wt['path'] ?? '' );

		if ( '' === $repo || '' === $handle || '' === $wt_path ) {
			return new \WP_Error('invalid_pr_worktree_match', 'Matched worktree is missing repo, handle, or path metadata.', array( 'status' => 500 ));
		}

		$dirty = $this->probe_worktree_dirty_count($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $dirty instanceof \WP_Error ) {
			return $dirty;
		}
		if ( (int) $dirty > 0 ) {
			return new \WP_Error('dirty_worktree', sprintf('Refusing merged PR cleanup for %s because the worktree has %d dirty file(s).', $handle, (int) $dirty), array( 'status' => 409 ));
		}

		$unpushed = $this->count_unpushed_commits($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $unpushed instanceof \WP_Error ) {
			return $unpushed;
		}
		if ( (int) $unpushed > 0 ) {
			return new \WP_Error('unpushed_commits', sprintf('Refusing merged PR cleanup for %s because it has %d unpushed commit(s).', $handle, (int) $unpushed), array( 'status' => 409 ));
		}

		$finalized = $this->worktree_finalize($handle, WorktreeContextInjector::STATE_MERGED, $pr_url);
		if ( $finalized instanceof \WP_Error ) {
			return $finalized;
		}

		$removed = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			function () use ( $repo, $branch, $wt_path ) {
				$remove = $this->remove_worktree_by_path($repo, $branch, $wt_path, false);
				if ( $remove instanceof \WP_Error ) {
					return $remove;
				}

				$primary_path = $this->get_primary_path($repo);
				$delete       = $this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($branch)));
				if ( $delete instanceof \WP_Error ) {
					$remove['local_branch_deleted'] = false;
					$remove['local_branch_error']   = $delete->get_error_message();
					return $remove;
				}

				$remove['local_branch_deleted'] = true;
				return $remove;
			}
		);

		if ( $removed instanceof \WP_Error ) {
			return $removed;
		}

		$this->worktree_prune();

		return array(
			'success'   => true,
			'found'     => true,
			'repo'      => $github_repo,
			'branch'    => $branch,
			'handle'    => $handle,
			'path'      => $wt_path,
			'finalized' => $finalized,
			'removed'   => $removed,
			'message'   => sprintf('Cleaned up merged PR worktree %s before branch deletion.', $handle),
		);
	}

	/**
	 * Rewrite a worktree's injected context files from the originating site's
	 * current memory state.
	 *
	 * Uses the site option snapshot stored at worktree-creation time for
	 * logging / diagnostics, then re-reads memory from the currently active
	 * Data Machine agent layer. Cross-machine refresh is deliberately not
	 * supported: callers must invoke this from the same site that created
	 * the worktree.
	 *
	 * @param  string $handle Workspace handle (`<repo>@<branch-slug>`).
	 * @return array{success: bool, handle: string, path: string, written: string[], exclude_path: ?string, metadata: ?array, message: string}|\WP_Error
	 */
	public function worktree_refresh_context( string $handle ): array|\WP_Error {
		$parsed = $this->parse_handle($handle);
		if ( ! $parsed['is_worktree'] ) {
			return new \WP_Error(
				'not_a_worktree',
				sprintf('Handle "%s" is a primary checkout, not a worktree. Context injection is worktree-only.', $handle),
				array( 'status' => 400 )
			);
		}

		$wt_path = $this->workspace_path . '/' . $parsed['dir_name'];
		if ( ! is_dir($wt_path) ) {
			return new \WP_Error(
				'worktree_not_found',
				sprintf('Worktree "%s" does not exist on disk.', $parsed['dir_name']),
				array( 'status' => 404 )
			);
		}

		$payload = WorktreeContextInjector::build_payload();
		if ( null === $payload ) {
			return new \WP_Error(
				'agent_layer_unavailable',
				'Data Machine agent memory layer is not available — cannot refresh context. Ensure this command is run from the site that created the worktree.',
				array( 'status' => 500 )
			);
		}

		$injection = WorktreeContextInjector::inject($wt_path, $payload);
		if ( is_wp_error($injection) ) {
			return $injection;
		}

		WorktreeContextInjector::store_metadata($parsed['dir_name'], $payload);
		// refresh-context is a deliberate liveness signal: the originating site
		// (and therefore some agent process there) just touched this worktree.
		WorktreeContextInjector::record_heartbeat($parsed['dir_name']);
		$this->worktree_inventory()->upsert($this->build_worktree_inventory_row_from_handle($parsed['dir_name']));

		return array(
			'success'      => true,
			'handle'       => $parsed['dir_name'],
			'path'         => $wt_path,
			'written'      => $injection['written'],
			'exclude_path' => $injection['exclude_path'] ?? null,
			'metadata'     => WorktreeContextInjector::get_metadata($parsed['dir_name']),
			'message'      => sprintf('Refreshed injected context in "%s" (%d file%s).', $parsed['dir_name'], count($injection['written']), 1 === count($injection['written']) ? '' : 's'),
		);
	}

	/**
	 * List worktrees in the workspace.
	 *
	 * On large workspaces (hundreds of worktrees) the per-row `git status` and
	 * `du` probes are the dominant cost. Callers that only need cheap inventory
	 * (handle, repo, branch, head, lifecycle metadata) can opt out via
	 * `$opts['include_status']` / `$opts['include_disk']`. Skipped fields are
	 * returned as `null`/`0`/`array()` and the row's `fields_skipped` array
	 * lists which probe groups were skipped, so consumers can tell the
	 * difference between "absent" and "not measured".
	 *
	 * @param  string|null $repo  Optional repo filter (only this primary's worktrees).
	 * @param  string|null $state Optional lifecycle state filter.
	 * @param  array       $opts  {
	 * @type   bool $include_status Whether to run `git status --porcelain` per worktree. Default true.
	 * @type   bool $include_disk   Whether to run size/artifact `du` probes per worktree. Default true.
	 * @type   int  $limit          Bounded response page size when supplied.
	 * @type   string $cursor       Continuation cursor for a bounded response.
	 * @type   bool $all            Return every row when using bounded response options.
	 * @type   string $task_ref      Exact task URL or reference predicate.
	 * @type   string $owner_run_ref Exact owner-run predicate.
	 * }
	 * @return array{success: bool, worktrees: array, fields_skipped: array<int,string>, total?:int, returned?:int, next_cursor?:string|null, status_requested?:bool, disk_requested?:bool, summary?:array}|\WP_Error
	 */
	public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error {
		$include_status = array_key_exists('include_status', $opts) ? (bool) $opts['include_status'] : true;
		$include_disk   = array_key_exists('include_disk', $opts) ? (bool) $opts['include_disk'] : true;
		$target_handle  = isset($opts['handle']) ? trim( (string) $opts['handle']) : '';
		$task_ref       = $this->normalize_worktree_list_task_ref($opts['task_ref'] ?? null);
		$owner_run_ref  = $this->normalize_worktree_list_owner_run_ref($opts['owner_run_ref'] ?? null);
		$progress       = isset($opts['progress_callback']) && is_callable($opts['progress_callback']) ? $opts['progress_callback'] : null;
		$repo           = null !== $repo && '' !== trim($repo) ? $this->sanitize_name($repo) : null;
		if ( null !== $state && '' !== trim($state) ) {
			$state = WorktreeContextInjector::normalize_state($state);
			if ( null === $state ) {
				return new \WP_Error('invalid_lifecycle_state', sprintf('Invalid lifecycle state. Valid states: %s.', implode(', ', WorktreeContextInjector::VALID_STATES)), array( 'status' => 400 ));
			}
		} else {
			$state = null;
		}
		$bounded        = array_key_exists('limit', $opts) || array_key_exists('cursor', $opts) || array_key_exists('all', $opts);
		$all            = ! empty($opts['all']);
		$budget         = $opts['wall_clock_budget'] ?? null;
		$budget         = $budget instanceof WallClockBudget ? $budget : WallClockBudget::from_duration($opts['until_budget'] ?? null, $all ? '30s' : '5s', 'invalid_worktree_list_budget', $this->worktree_list_budget_clock());
		if ( is_wp_error($budget) ) {
			return $budget;
		}
		$task_lookup     = null !== $task_ref;
		$task_limit      = 200;
		$task_candidates = null;
		$task_repos      = null;
		if ( $task_lookup ) {
			$inventory_rows = $this->worktree_list_task_inventory_rows($task_ref, $task_limit + 1);
			if ( count($inventory_rows) > $task_limit ) {
				return new \WP_Error('worktree_task_candidates_overflow', 'Task worktree lookup exceeded the complete bounded candidate limit.', array( 'status' => 409, 'task_ref' => $task_ref, 'total' => count($inventory_rows), 'limit' => $task_limit ));
			}
			$task_candidates = array();
			$task_repos      = array();
			foreach ( $inventory_rows as $inventory_row ) {
				$handle = trim( (string) ( $inventory_row['handle'] ?? '' ));
				$repo_name = trim( (string) ( $inventory_row['repo'] ?? '' ));
				if ( '' !== $handle && '' !== $repo_name ) {
					$task_candidates[ $handle ] = true;
					$task_repos[ $repo_name ]   = true;
				}
			}
		}
		if ( $all && isset($opts['cursor']) ) {
			return new \WP_Error('invalid_worktree_list_pagination', 'Worktree list --all cannot be combined with --cursor.', array( 'status' => 400 ));
		}
		// Complete task lookups admit their bounded candidate set before any
		// requested probe. Overflow must never spend work or hide ambiguity.
		$shared_budget_supplied = ( $opts['wall_clock_budget'] ?? null ) instanceof WallClockBudget;
		$defer_probes           = ( $bounded && ! $all ) || ( $task_lookup && $all ) || ( $all && $shared_budget_supplied );
		$run_status     = $include_status && ! $defer_probes;
		$run_disk       = $include_disk && ! $defer_probes;
		$limit = $this->normalize_worktree_list_limit($opts['limit'] ?? 50);
		if ( is_wp_error($limit) ) {
			return new \WP_Error('invalid_worktree_list_limit', 'Worktree list limit must be an integer between 1 and 200.', array( 'status' => 400 ));
		}
		$cursor = isset($opts['cursor']) ? $this->decode_worktree_list_cursor((string) $opts['cursor'], $repo, $state, $target_handle, $task_ref, $owner_run_ref) : null;
		if ( is_wp_error($cursor) ) {
			return $cursor;
		}
		$skipped_groups = array();
		if ( ! $include_status ) {
			$skipped_groups[] = 'status';
		}
		if ( ! $include_disk ) {
			$skipped_groups[] = 'disk';
		}

		if ( '' !== $target_handle ) {
			// A handle is a single-checkout query, not a filtered workspace scan.
			$opts['wall_clock_budget'] = $budget;
			$result = $this->worktree_get($target_handle, $opts);
			if ( $result instanceof \WP_Error ) {
				if ( 'worktree_not_found' !== $result->get_error_code() ) {
					return $result;
				}
				return $this->worktree_list_add_response_metadata(array(
					'success'               => true,
					'worktrees'             => array(),
					'duplicates'            => array(),
					'base_branch_worktrees' => array(),
					'fields_skipped'        => $skipped_groups,
				), $include_status, $include_disk);
			}
			if ( null === $state ) {
				$result['worktrees'] = array_values(array_filter((array) $result['worktrees'], fn( array $row ): bool => $this->worktree_list_matches_metadata_filters(is_array($row['metadata'] ?? null) ? $row['metadata'] : null, $task_ref, $owner_run_ref)));
				return $this->worktree_list_add_response_metadata($result, $include_status, $include_disk);
			}
			if ( ( $result['worktrees'][0]['lifecycle_state'] ?? null ) !== $state || ! $this->worktree_list_matches_metadata_filters(is_array($result['worktrees'][0]['metadata'] ?? null) ? $result['worktrees'][0]['metadata'] : null, $task_ref, $owner_run_ref) ) {
				$result['worktrees'] = array();
			}
			return $this->worktree_list_add_response_metadata($result, $include_status, $include_disk);
		}
		if ( ! is_dir($this->workspace_path) ) {
			return $this->worktree_list_add_response_metadata(array(
				'success'        => true,
				'worktrees'      => array(),
				'fields_skipped' => $skipped_groups,
			), $include_status, $include_disk);
		}

		$worktrees = array();
		$summary   = $this->worktree_list_empty_summary();
		$remaining = 0;
		$diagnostic_state = $this->worktree_list_empty_diagnostic_state();
		$budget_stopped   = false;
		$budget_phase     = null;
		$partial_reason   = null;
		$budget_exhausted = false;
		$inventory_probe_failures = array();

		foreach ( new \DirectoryIterator($this->workspace_path) as $entry ) {
			$primary = $entry->getFilename();
			if ( $entry->isDot() || str_contains($primary, '@') || ! $entry->isDir() || ! file_exists($entry->getPathname() . '/.git') || ( null !== $repo && $primary !== $repo ) || ( is_array($task_repos) && ! isset($task_repos[ $primary ]) ) ) {
				continue;
			}
			$primary_path      = $this->workspace_path . '/' . $primary;
			$primary_repo      = $this->parse_handle($primary)['repo'];
			$scanning_worktree = str_contains($primary, '@');
			$this->worktree_list_progress($progress, 'worktree_inventory', $primary_repo, $primary);
			$inventory_timeout = $budget->probe_timeout_seconds(self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS);
			if ( 0 === $inventory_timeout ) {
				$budget_stopped = true;
				$budget_phase   = 'worktree_inventory';
				$partial_reason = 'scan_budget_exhausted';
				$budget_exhausted = true;
				break;
			}
			$result            = $this->run_git($primary_path, 'worktree list --porcelain', $inventory_timeout);
			if ( is_wp_error($result) ) {
				if ( 'git_command_timeout' === $result->get_error_code() ) {
					if ( $all && $shared_budget_supplied ) {
						$inventory_probe_failures[] = array( 'repo' => $primary, 'reason' => 'git_command_timeout', 'timeout_seconds' => $inventory_timeout );
						$budget_phase   = 'worktree_inventory';
						$partial_reason = 'repository_probe_timeout';
						continue;
					}
					$data = $result->get_error_data();
					return new \WP_Error(
						'worktree_list_probe_timeout',
						sprintf('Worktree inventory for "%s" timed out after %d second(s).', $primary, self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS),
						array_merge(
							is_array($data) ? $data : array(),
							array(
								'status'          => 504,
								'phase'           => 'worktree_inventory',
								'repo'            => $primary,
								'timeout_seconds' => self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS,
								'retry_command'   => sprintf('studio wp datamachine-code workspace worktree list %s', $primary),
							)
						)
					);
				}
				continue;
			}

			foreach ( $this->worktree_list_blocks( (string) ( $result['output'] ?? '' )) as $block ) {
				$wt = $this->parse_worktree_block($block);
				if ( null === $wt ) {
					continue;
				}

				$is_primary    = ! $scanning_worktree && ( $wt['path'] === $primary_path );
				$workspace_pfx = $this->workspace_path . '/';
				$inside_ws     = str_starts_with($wt['path'], $workspace_pfx);
				$relative      = $inside_ws ? substr($wt['path'], strlen($workspace_pfx)) : '';
				$parsed        = $inside_ws ? $this->parse_handle($relative) : array( 'branch_slug' => null );

				if ( $is_primary ) {
					$handle = $primary;
				} elseif ( $inside_ws ) {
					$handle = $relative;
				} else {
					// External worktree (created via raw `git worktree add` outside the workspace).
					// Show the absolute path so it is still useful, even though it has no `<repo>@<slug>` handle.
					$handle = $wt['path'];
				}
				if ( is_array($task_candidates) && ! isset($task_candidates[ $handle ]) ) {
					continue;
				}
				if ( '' !== $target_handle && $handle !== $target_handle ) {
					continue;
				}

				$metadata_key = null;
				if ( ! $is_primary && $inside_ws ) {
					$metadata_key = $relative;
				} elseif ( ! $is_primary && ! $inside_ws ) {
					$metadata_key = 'external:' . sha1($wt['path']);
				}
				$metadata        = null !== $metadata_key ? WorktreeContextInjector::get_metadata($metadata_key) : null;
				$created_at      = is_array($metadata) ? ( $metadata['created_at'] ?? null ) : null;
				$lifecycle_state = is_array($metadata) ? WorktreeContextInjector::project_lifecycle_state($metadata) : null;
				if ( ( null !== $state && $lifecycle_state !== $state ) || ! $this->worktree_list_matches_metadata_filters($metadata, $task_ref, $owner_run_ref) ) {
					continue;
				}

				if ( $run_status ) {
					$dirty_result     = $this->run_git($wt['path'], 'status --porcelain');
					$dirty_files      = is_wp_error($dirty_result)
					? 0
					: count(array_filter(array_map('trim', explode("\n", $dirty_result['output'] ?? ''))));
					$unpushed_commits = $this->count_unpushed_commits($wt['path']);
					if ( is_wp_error($unpushed_commits) ) {
						return $unpushed_commits;
					}
				} else {
					$dirty_files      = null;
					$unpushed_commits = null;
				}

				if ( $run_disk ) {
					$disk = $this->build_worktree_disk_report($primary_repo, $wt['path'], ! $is_primary, $created_at, $metadata);
				} else {
					$disk = array(
						'size_bytes'           => null,
						'estimated_size_bytes' => null,
						'last_touched_at'      => null,
						'age_days'             => $this->calculate_age_days($created_at),
						'artifacts'            => array(),
						'artifact_size_bytes'  => 0,
					);
				}

				// Stale-reason detection requires both signals to be reliable; only
				// flag dirty/threshold reasons when the underlying probe ran. The
				// metadata-only signal still works without disk/status probes.
				$stale_reason = $this->detect_worktree_stale_reason(
					! $is_primary,
					(int) ( $dirty_files ?? 0 ),
					$disk['age_days'] ?? null,
					$created_at,
					array(
						'status_probed' => $run_status,
						'disk_probed'   => $run_disk,
					)
				);
				if ( null !== $stale_reason ) {
						$disk['stale_reason'] = $stale_reason;
				}

				$liveness     = WorktreeContextInjector::classify_liveness(is_array($metadata) ? $metadata : null);
				$owner        = WorktreeContextInjector::summarize_owner(is_array($metadata) ? $metadata : null);
				$session_view = WorktreeContextInjector::summarize_session(is_array($metadata) ? $metadata : null);
				$task_view    = is_array($metadata) && is_array($metadata['origin_task'] ?? null) ? $metadata['origin_task'] : null;

				$row = array_merge(
					array(
						'handle'                => $handle,
						'repo'                  => $primary_repo,
						'is_worktree'           => ! $is_primary,
						'is_primary'            => $is_primary,
						'external'              => ! $is_primary && ! $inside_ws,
						'branch_slug'           => $is_primary ? null : ( $parsed['branch_slug'] ?? null ),
						'branch'                => $wt['branch'],
						'head'                  => $wt['head'],
						'path'                  => $wt['path'],
						'dirty'                 => $dirty_files,
						'unpushed'              => $unpushed_commits,
						'created_at'            => $created_at,
						'lifecycle_state'       => $lifecycle_state,
						'pr_url'                => is_array($metadata) ? ( $metadata['pr_url'] ?? null ) : null,
						'pr_number'             => is_array($metadata) ? ( $metadata['pr_number'] ?? null ) : null,
						'last_seen_at'          => is_array($metadata) ? ( $metadata['last_seen_at'] ?? null ) : null,
						'liveness'              => $liveness['liveness'],
						'liveness_reason'       => $liveness['reason'],
						'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
						'owner'                 => $owner,
						'session'               => $session_view,
						'task'                  => $task_view,
						'metadata'              => $metadata,
					),
					$disk
				);

				if ( $run_status && $is_primary ) {
					$row['primary_freshness'] = $this->build_primary_freshness_report($wt['path'], $handle);
				}

				$base_branch_warning = $this->base_branch_worktree_warning($row);
				if ( null !== $base_branch_warning ) {
						$row['base_branch_warning'] = $base_branch_warning;
				}

				if ( ! empty($skipped_groups) ) {
					$row['fields_skipped'] = $skipped_groups;
				}

				$this->worktree_list_count_summary($summary, $row);
				$this->worktree_list_accumulate_diagnostic_state($diagnostic_state, $row);
				if ( null === $cursor || strcmp($this->worktree_list_row_key($row), $cursor) > 0 ) {
					++$remaining;
					if ( $bounded && ( ! $all || $task_lookup ) ) {
						$this->worktree_list_insert_bounded_row($worktrees, $row, $task_lookup && $all ? $task_limit : $limit);
					} else {
						$worktrees[] = $row;
					}
				}
			}
		}

		if ( ! $bounded || $all ) {
			usort($worktrees, fn( array $left, array $right ): int => strcmp($this->worktree_list_row_key($left), $this->worktree_list_row_key($right)));
		}
		if ( $task_lookup && $all && $summary['total'] > $task_limit ) {
			return new \WP_Error('worktree_task_candidates_overflow', 'Task worktree lookup exceeded the complete bounded candidate limit.', array( 'status' => 409, 'task_ref' => $task_ref, 'total' => $summary['total'], 'limit' => $task_limit ));
		}
		$diagnostics = $this->worktree_list_finalize_diagnostic_state($diagnostic_state);
		$summary     = array_merge($summary, $diagnostics['summary']);
		$duplicates            = $diagnostics['duplicates'];
		$base_branch_worktrees = $diagnostics['base_branch_worktrees'];
		$inventory_complete = ! $budget_stopped && array() === $inventory_probe_failures;
		if ( $inventory_complete && $defer_probes && ( $include_status || $include_disk ) ) {
			foreach ( $worktrees as &$worktree ) {
				$probe_result = $this->hydrate_worktree_list_probes($worktree, $include_status, $include_disk, $budget, $progress);
				if ( is_wp_error($probe_result) ) {
					if ( ! in_array($probe_result->get_error_code(), array( 'worktree_list_budget_exhausted', 'worktree_list_probe_incomplete' ), true) && ! $budget->expired() ) {
						unset($worktree);
						return $probe_result;
					}
					$budget_stopped = true;
					$probe_data     = (array) $probe_result->get_error_data();
					$budget_phase   = (string) ( $probe_data['phase'] ?? 'requested_probes' );
					$budget_exhausted = 'worktree_list_budget_exhausted' === $probe_result->get_error_code() || $budget->expired();
					$partial_reason = (string) ( $probe_data['reason'] ?? ( $budget_exhausted ? 'budget_exhausted_' . $budget_phase : 'probe_incomplete_' . $budget_phase ) );
					$skipped_group  = 'disk' === $budget_phase ? 'disk' : 'status';
					if ( ! in_array($skipped_group, $skipped_groups, true) ) {
						$skipped_groups[] = $skipped_group;
					}
					break;
				}
			}
			unset($worktree);
		}
		$next_cursor = null;
		if ( $inventory_complete && $bounded && ! $all && $remaining > count($worktrees) && ! empty($worktrees) ) {
			$next_cursor = $this->encode_worktree_list_cursor($this->worktree_list_row_key($worktrees[ count($worktrees) - 1 ]), $repo, $state, $target_handle, $task_ref, $owner_run_ref);
		}
		if ( ! $inventory_complete ) {
			$summary['observed'] = array_intersect_key($summary, array_flip(array( 'total', 'primary', 'worktree', 'external', 'dirty', 'unpushed', 'stale', 'active', 'stopped', 'unknown' )));
			foreach ( array_keys($summary['observed']) as $field ) {
				$summary[ $field ] = null;
			}
			$summary['repo_count'] = null;
			$summary['repos_omitted'] = null;
		}

		return array(
			'success'               => true,
			'worktrees'             => $worktrees,
			'duplicates'            => $duplicates,
			'base_branch_worktrees' => $base_branch_worktrees,
			'fields_skipped'        => $skipped_groups,
			'total'                 => $inventory_complete ? $summary['total'] : null,
			'returned'              => count($worktrees),
			'next_cursor'           => $next_cursor,
			'status_requested'      => $include_status,
			'disk_requested'        => $include_disk,
			'summary'               => $summary,
			'partial'               => $budget_stopped || array() !== $inventory_probe_failures,
			'continuation'          => array(
				'available' => null !== $next_cursor,
				'cursor'    => $next_cursor,
				'reason'    => $budget_stopped || array() !== $inventory_probe_failures ? $partial_reason : ( null === $next_cursor ? null : 'more_rows' ),
			),
			'diagnostics'           => array(
				'budget_exhausted'  => $budget_exhausted,
				'budget_exhaustion_reason' => $budget_exhausted ? $partial_reason : null,
				'partial_reason'   => $budget_stopped || array() !== $inventory_probe_failures ? $partial_reason : null,
				'phase'             => $budget_phase,
				'wall_clock_budget' => $budget->evidence(),
				'inventory_probe_failures' => $inventory_probe_failures,
			),
		);
	}

	/** @return array<int,array<string,mixed>> */
	protected function worktree_list_task_inventory_rows( string $task_ref, int $limit ): array {
		return $this->worktree_inventory()->findByTaskRef($task_ref, $limit);
	}

	/** Monotonic clock seam for deterministic budget contract tests. */
	protected function worktree_list_budget_clock(): ?callable {
		return null;
	}

	/**
	 * Run requested expensive probes only after bounded pagination selected a row.
	 *
	 * @param array<string,mixed> $worktree Worktree row to enrich in place.
	 * @return \WP_Error|null
	 */
	private function hydrate_worktree_list_probes( array &$worktree, bool $include_status, bool $include_disk, ?WallClockBudget $budget = null, ?callable $progress = null ): ?\WP_Error {
		$path = (string) ( $worktree['path'] ?? '' );
		if ( '' === $path ) {
			return null;
		}
		if ( $include_status ) {
			$this->worktree_list_progress($progress, 'status', (string) ( $worktree['repo'] ?? '' ), (string) ( $worktree['handle'] ?? '' ));
			$timeout = null === $budget ? self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS : $budget->probe_timeout_seconds(self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS);
			if ( 0 === $timeout ) {
				return new \WP_Error('worktree_list_budget_exhausted', 'Worktree list budget expired before requested status probes completed.', array( 'status' => 504, 'phase' => 'status', 'handle' => $worktree['handle'] ?? null ));
			}
			$dirty_result      = $this->run_git($path, 'status --porcelain', $timeout);
			if ( is_wp_error($dirty_result) && null !== $budget ) {
				$worktree['dirty'] = null;
				return new \WP_Error('worktree_list_probe_incomplete', 'Requested worktree status probe did not complete inside its child timeout.', array( 'status' => 504, 'phase' => 'status', 'reason' => 'probe_incomplete_status', 'handle' => $worktree['handle'] ?? null, 'cause_code' => $dirty_result->get_error_code() ));
			}
			$worktree['dirty'] = is_wp_error($dirty_result) ? 0 : count(array_filter(array_map('trim', explode("\n", $dirty_result['output'] ?? ''))));
			$timeout             = null === $budget ? self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS : $budget->probe_timeout_seconds(self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS);
			$unpushed_commits    = 0 === $timeout ? new \WP_Error('worktree_list_budget_exhausted', 'Worktree list budget expired before requested unpushed probes completed.', array( 'status' => 504, 'phase' => 'status', 'handle' => $worktree['handle'] ?? null )) : $this->count_unpushed_commits($path, $timeout);
			if ( is_wp_error($unpushed_commits) ) {
				if ( null !== $budget && 'worktree_list_budget_exhausted' !== $unpushed_commits->get_error_code() ) {
					return new \WP_Error('worktree_list_probe_incomplete', 'Requested unpushed-commit probe did not complete inside its child timeout.', array( 'status' => 504, 'phase' => 'status', 'reason' => 'probe_incomplete_status', 'handle' => $worktree['handle'] ?? null, 'cause_code' => $unpushed_commits->get_error_code() ));
				}
				return $unpushed_commits;
			}
			$worktree['unpushed'] = $unpushed_commits;
			if ( ! empty($worktree['is_primary']) ) {
				$timeout = null === $budget ? self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS : $budget->probe_timeout_seconds(self::WORKTREE_LIST_GIT_PROBE_TIMEOUT_SECONDS);
				$worktree['primary_freshness'] = 0 === $timeout ? array( 'status' => 'unknown', 'reason' => 'budget_exhausted' ) : $this->build_primary_freshness_report($path, (string) ( $worktree['handle'] ?? '' ), $timeout);
			}
		}
		if ( $include_disk ) {
			$this->worktree_list_progress($progress, 'disk', (string) ( $worktree['repo'] ?? '' ), (string) ( $worktree['handle'] ?? '' ));
			if ( null !== $budget && $budget->expired() ) {
				return new \WP_Error('worktree_list_budget_exhausted', 'Worktree list budget expired before requested disk probes completed.', array( 'status' => 504, 'phase' => 'disk', 'handle' => $worktree['handle'] ?? null ));
			}
			$worktree = array_merge(
				$worktree,
				$this->build_worktree_disk_report(
					(string) ( $worktree['repo'] ?? '' ),
					$path,
					! empty($worktree['is_worktree']),
					isset($worktree['created_at']) ? (string) $worktree['created_at'] : null,
					is_array($worktree['metadata'] ?? null) ? $worktree['metadata'] : null,
					$budget
				)
			);
		}
		$stale_reason = $this->detect_worktree_stale_reason(
			! empty($worktree['is_worktree']),
			(int) ( $worktree['dirty'] ?? 0 ),
			$worktree['age_days'] ?? null,
			isset($worktree['created_at']) ? (string) $worktree['created_at'] : null,
			array(
				'status_probed' => $include_status,
				'disk_probed'   => $include_disk,
			)
		);
		if ( null === $stale_reason ) {
			unset($worktree['stale_reason']);
		} else {
			$worktree['stale_reason'] = $stale_reason;
		}
		return null;
	}

	/** Emit best-effort visibility before a worktree-list probe. */
	private function worktree_list_progress( ?callable $callback, string $phase, string $repository, string $handle ): void {
		if ( null === $callback ) {
			return;
		}
		try {
			$callback(array( 'operation' => 'workspace_hygiene', 'phase' => $phase, 'repository' => $repository, 'handle' => $handle, 'message' => 'Inspecting ' . $handle . '.' ));
		} catch ( \Throwable $error ) {
			unset($error);
		}
	}

	/** @return array<string,mixed> */
	private function worktree_list_empty_summary(): array {
		return array(
			'total'    => 0,
			'primary'  => 0,
			'worktree' => 0,
			'external' => 0,
			'repos'    => array(),
		);
	}

	private function normalize_worktree_list_limit( mixed $limit ): int|\WP_Error {
		if ( is_int($limit) || ( is_string($limit) && ctype_digit($limit) ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_int($limit) || $limit < 1 || $limit > 200 ) {
			return new \WP_Error('invalid_worktree_list_limit', 'Worktree list limit must be an integer between 1 and 200.', array( 'status' => 400 ));
		}
		return $limit;
	}

	/** @param array<string,mixed> $summary @param array<string,mixed> $row */
	private function worktree_list_count_summary( array &$summary, array $row ): void {
		++$summary['total'];
		++$summary[ ! empty($row['is_primary']) ? 'primary' : 'worktree' ];
		if ( ! empty($row['external']) ) {
			++$summary['external'];
		}
	}

	/** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $row */
	protected function worktree_list_insert_bounded_row( array &$rows, array $row, int $limit ): void {
		$key      = $this->worktree_list_row_key($row);
		$position = count($rows);
		foreach ( $rows as $index => $existing ) {
			if ( strcmp($key, $this->worktree_list_row_key($existing)) < 0 ) {
				$position = $index;
				break;
			}
		}
		if ( $position >= $limit && count($rows) === $limit ) {
			return;
		}
		array_splice($rows, $position, 0, array( $row ));
		if ( count($rows) > $limit ) {
			array_pop($rows);
		}
	}

	/** @return \Generator<int,string> */
	private function worktree_list_blocks( string $output ): \Generator {
		$offset = 0;
		while ( preg_match("/\n\n+/", $output, $match, PREG_OFFSET_CAPTURE, $offset) ) {
			$block = trim(substr($output, $offset, $match[0][1] - $offset));
			if ( '' !== $block ) {
				yield $block;
			}
			$offset = $match[0][1] + strlen($match[0][0]);
		}
		$block = trim(substr($output, $offset));
		if ( '' !== $block ) {
			yield $block;
		}
	}

	/** @return array<string,mixed> */
	private function worktree_list_empty_diagnostic_state(): array {
		return array(
			'repos'      => array(),
			'base'       => array(),
			'base_total' => 0,
			'tasks'      => array(),
		);
	}

	/** Accumulate complete diagnostics during the owning inventory pass. */
	private function worktree_list_accumulate_diagnostic_state( array &$state, array $row ): void {
		$repo = (string) ( $row['repo'] ?? '' );
		if ( ! isset($state['repos'][ $repo ]) ) {
			$state['repos'][ $repo ] = array( 'repo' => $repo, 'primary' => 0, 'worktree' => 0, 'external' => 0, 'total' => 0 );
		}
		++$state['repos'][ $repo ][ ! empty($row['is_primary']) ? 'primary' : 'worktree' ];
		++$state['repos'][ $repo ]['total'];
		if ( ! empty($row['external']) ) {
			++$state['repos'][ $repo ]['external'];
		}

		$warning = $this->base_branch_worktree_warning($row);
		if ( null !== $warning ) {
			++$state['base_total'];
			$this->worktree_list_insert_bounded_row($state['base'], $warning, 25);
		}

		$metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : array();
		foreach ( WorktreeContextInjector::task_ownership_keys($row, $metadata) as $kind => $key ) {
			$id = $kind . '|' . $key;
			if ( ! isset($state['tasks'][ $id ]) ) {
				$state['tasks'][ $id ] = array( 'kind' => $kind, 'key' => $key, 'handles' => array(), 'handle_count' => 0 );
			}
			++$state['tasks'][ $id ]['handle_count'];
			if ( count($state['tasks'][ $id ]['handles']) < 25 ) {
				$state['tasks'][ $id ]['handles'][] = (string) ( $row['handle'] ?? '' );
			}
		}
	}

	/** @return array{summary:array<string,mixed>,duplicates:array<int,array<string,mixed>>,base_branch_worktrees:array<int,array<string,mixed>>} */
	private function worktree_list_finalize_diagnostic_state( array $state ): array {
		$repos = (array) $state['repos'];
		ksort($repos);
		$repo_count = count($repos);
		$repos      = array_slice(array_values($repos), 0, 25);

		$duplicate_total = 0;
		$duplicates      = array();
		$tasks           = (array) $state['tasks'];
		ksort($tasks);
		foreach ( $tasks as $group ) {
			if ( (int) ( $group['handle_count'] ?? 0 ) < 2 ) {
				continue;
			}
			++$duplicate_total;
			if ( count($duplicates) < 25 ) {
				sort($group['handles'], SORT_STRING);
				$group['handles_omitted'] = (int) $group['handle_count'] - count($group['handles']);
				$duplicates[]             = $group;
			}
		}

		return array(
			'summary'               => array(
				'repos'                          => $repos,
				'repo_count'                     => $repo_count,
				'repos_returned'                 => count($repos),
				'repos_omitted'                  => $repo_count - count($repos),
				'duplicate_task_groups_total'    => $duplicate_total,
				'duplicate_task_groups_returned' => count($duplicates),
				'duplicate_task_groups_omitted'  => $duplicate_total - count($duplicates),
				'base_branch_worktrees_total'    => (int) ( $state['base_total'] ?? 0 ),
				'base_branch_worktrees_returned' => count((array) $state['base']),
				'base_branch_worktrees_omitted'  => (int) ( $state['base_total'] ?? 0 ) - count((array) $state['base']),
			),
			'duplicates'            => $duplicates,
			'base_branch_worktrees' => array_values((array) $state['base']),
		);
	}

	/** @param array<int,array<string,mixed>> $worktrees */
	private function worktree_list_summary( array $worktrees ): array {
		$summary = array(
			'total'    => count($worktrees),
			'primary'  => 0,
			'worktree' => 0,
			'external' => 0,
			'repos'    => array(),
		);
		foreach ( $worktrees as $worktree ) {
			$kind = ! empty($worktree['is_primary']) ? 'primary' : 'worktree';
			++$summary[ $kind ];
			if ( ! empty($worktree['external']) ) {
				++$summary['external'];
			}
			$repo                      = (string) ( $worktree['repo'] ?? 'unknown' );
			$summary['repos'][ $repo ] = 1 + ( $summary['repos'][ $repo ] ?? 0 );
		}
		ksort($summary['repos']);
		return $summary;
	}

	/** @param array<string,mixed> $result */
	private function worktree_list_add_response_metadata( array $result, bool $include_status, bool $include_disk ): array {
		$worktrees                  = (array) ( $result['worktrees'] ?? array() );
		$result['total']            = count($worktrees);
		$result['returned']         = count($worktrees);
		$result['next_cursor']      = null;
		$result['status_requested'] = $include_status;
		$result['disk_requested']   = $include_disk;
		$result['summary']          = $this->worktree_list_summary($worktrees);
		return $result;
	}

	/** @param array<string,mixed> $row */
	private function worktree_list_row_key( array $row ): string {
		return (string) ( $row['handle'] ?? '' ) . "\0" . (string) ( $row['path'] ?? '' );
	}

	private function encode_worktree_list_cursor( string $after, ?string $repo, ?string $state, string $handle, ?string $task_ref = null, ?string $owner_run_ref = null ): string {
		$scope = array( 'repo' => $repo, 'state' => $state, 'handle' => $handle );
		if ( null !== $task_ref ) { $scope['task_ref'] = $task_ref; }
		if ( null !== $owner_run_ref ) { $scope['owner_run_ref'] = $owner_run_ref; }
		return ListCursor::encode($after, $scope);
	}

	private function decode_worktree_list_cursor( string $cursor, ?string $repo, ?string $state, string $handle, ?string $task_ref = null, ?string $owner_run_ref = null ): string|\WP_Error {
		$scope = array( 'repo' => $repo, 'state' => $state, 'handle' => $handle );
		if ( null !== $task_ref ) { $scope['task_ref'] = $task_ref; }
		if ( null !== $owner_run_ref ) { $scope['owner_run_ref'] = $owner_run_ref; }
		return ListCursor::decode(
			$cursor,
			$scope,
			'invalid_worktree_list_cursor',
			'Worktree list cursor is invalid for the requested filters.'
		);
	}

	private function normalize_worktree_list_task_ref( mixed $task_ref ): ?string {
		return TaskUrl::canonicalize($task_ref) ?? ( is_scalar($task_ref) && '' !== trim((string) $task_ref) ? strtolower(trim((string) $task_ref)) : null );
	}

	private function normalize_worktree_list_owner_run_ref( mixed $owner_run_ref ): ?string {
		return is_scalar($owner_run_ref) && '' !== trim((string) $owner_run_ref) ? trim((string) $owner_run_ref) : null;
	}

	private function worktree_list_matches_metadata_filters( ?array $metadata, ?string $task_ref, ?string $owner_run_ref ): bool {
		if ( null === $task_ref && null === $owner_run_ref ) { return true; }
		if ( ! is_array($metadata) ) { return false; }
		$task = is_array($metadata['origin_task'] ?? null) ? $metadata['origin_task'] : array();
		if ( null !== $task_ref && $task_ref !== TaskUrl::canonicalize($task['task_url'] ?? null) && $task_ref !== strtolower(trim((string) ( $task['task_ref'] ?? '' ))) ) { return false; }
		return null === $owner_run_ref || $owner_run_ref === ( is_scalar($metadata['owner_run_ref'] ?? null) ? trim((string) $metadata['owner_run_ref']) : '' );
	}

	/**
	 * Return warning metadata when a non-primary worktree holds a base branch.
	 *
	 * GitHub CLI merge flows may try to check out or delete the PR base branch
	 * during local cleanup. If another worktree has that branch checked out, the
	 * remote merge can succeed while local cleanup reports a fatal git error.
	 *
	 * @param  array<string,mixed> $row Worktree listing row.
	 * @return array<string,string>|null
	 */
	private function base_branch_worktree_warning( array $row ): ?array {
		if ( empty($row['is_worktree']) || ! empty($row['is_primary']) || ! empty($row['external']) ) {
			return null;
		}

		$branch = (string) ( $row['branch'] ?? '' );
		if ( '' === $branch || ! in_array($branch, $this->protected_base_branch_names(), true) ) {
			return null;
		}

		return array(
			'handle'      => (string) ( $row['handle'] ?? '' ),
			'repo'        => (string) ( $row['repo'] ?? '' ),
			'branch'      => $branch,
			'path'        => (string) ( $row['path'] ?? '' ),
			'reason_code' => 'base_branch_checked_out_in_worktree',
			'message'     => sprintf('Worktree %s has base branch %s checked out; gh pr merge --delete-branch may merge remotely but fail local cleanup.', (string) ( $row['handle'] ?? '' ), $branch),
		);
	}

	/**
	 * Branch names that should normally be held by primaries, not feature worktrees.
	 *
	 * @return array<int,string>
	 */
	private function protected_base_branch_names(): array {
		return array( 'main', 'master', 'trunk', 'develop' );
	}

	/**
	 * Refresh the DB-backed worktree inventory from the current filesystem/git view.
	 *
	 * Current rows are upserted. Previously known rows missing from the current
	 * scan are marked `missing_path` so operators can see drift explicitly.
	 *
	 * @param WallClockBudget|null $budget   Optional shared wall-clock budget.
	 * @param callable|null        $progress Optional best-effort phase observer.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_inventory_refresh( ?WallClockBudget $budget = null, ?callable $progress = null ): array|\WP_Error {
		$budget  = $budget ?? WallClockBudget::from_seconds(30, '30s');
		$listing = $this->worktree_list(
			null,
			null,
			array(
				'include_status' => false,
				'include_disk'   => false,
				'all'            => true,
				'wall_clock_budget' => $budget,
				'progress_callback' => $progress,
			)
		);
		if ( $listing instanceof \WP_Error ) {
			return $listing;
		}

		$repository      = $this->worktree_inventory();
		$current_handles = array();
		$upserted        = array();
		$marked_missing  = array();
		$observed_rows   = 0;

		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $row ) {
			if ( $budget->expired() ) {
				break;
			}
			++$observed_rows;
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' === $handle || ! empty($row['external']) ) {
				continue;
			}

			$current_handles[ $handle ] = true;
			if ( $repository->upsert($row) ) {
				$upserted[] = $handle;
			}
		}

		$scan_complete = empty($listing['partial']) && ! $budget->expired() && $observed_rows === count((array) ( $listing['worktrees'] ?? array() ));
		if ( $scan_complete ) {
			foreach ( $repository->list() as $stored ) {
				if ( $budget->expired() ) {
					$scan_complete = false;
					break;
				}
				$handle = (string) ( $stored['handle'] ?? '' );
				if ( '' === $handle || isset($current_handles[ $handle ]) ) {
					continue;
				}

				if ( $repository->mark_missing($handle) ) {
					$marked_missing[] = $handle;
				}
			}
		}

		return array(
			'success'        => true,
			'refreshed_at'   => gmdate('c'),
			'upserted'       => $upserted,
			'marked_missing' => $marked_missing,
			'partial'        => ! $scan_complete,
			'continuation'   => array(
				'available'    => ! $scan_complete,
				'reason'       => $scan_complete ? null : 'inventory_refresh_budget_exhausted',
				'next_command' => $scan_complete ? null : 'wp datamachine-code workspace inventory refresh --format=json',
			),
			'evidence'       => array( 'wall_clock_budget' => $budget->evidence() ),
			'summary'        => array(
				'upserted'       => count($upserted),
				'marked_missing' => count($marked_missing),
			),
		);
	}

	/**
	 * Prune DB-backed inventory rows flagged missing_path whose path is still absent.
	 *
	 * Re-probes each candidate on disk, protects rows with unpushed work or an
	 * open PR unless forced, and deletes the confirmed-absent survivors.
	 *
	 * @param  array{dry_run?: bool, force?: bool, limit?: int, after_handle?: string, until_budget?: string} $opts Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_inventory_prune_missing( array $opts = array() ): array|\WP_Error {
		$opts['lock_callback']  = function ( array $row, callable $mutation ): mixed {
			$repo = trim( (string) ( $row['repo'] ?? '' ) );
			if ( '' === $repo ) {
				return new \WP_Error('workspace_lock_invalid_target', 'Missing repository handle for inventory pruning.', array( 'status' => 400 ));
			}

			return WorkspaceMutationLock::with_repo($this->workspace_path, $repo, $mutation);
		};
		$opts['workspace_root'] = $this->workspace_path;
		return $this->worktree_inventory()->pruneMissing($opts);
	}

	/**
	 * Build a single inventory row for a known workspace handle.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array<string,mixed>
	 */
	private function build_worktree_inventory_row_from_handle( string $handle ): array {
		$parsed   = $this->parse_handle($handle);
		$path     = $this->workspace_path . '/' . $parsed['dir_name'];
		$metadata = $parsed['is_worktree'] ? WorktreeContextInjector::get_metadata($parsed['dir_name']) : null;
		$metadata = is_array($metadata) ? $metadata : array();
		$liveness = WorktreeContextInjector::classify_liveness($metadata);
		$owner    = WorktreeContextInjector::summarize_owner($metadata);
		$session  = WorktreeContextInjector::summarize_session($metadata);
		$task     = is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : null;

		return array(
			'handle'                => $parsed['dir_name'],
			'repo'                  => $parsed['repo'],
			'is_worktree'           => $parsed['is_worktree'],
			'is_primary'            => ! $parsed['is_worktree'],
			'external'              => false,
			'branch_slug'           => $parsed['branch_slug'],
			'branch'                => $metadata['branch'] ?? $parsed['branch_slug'],
			'path'                  => $path,
			'primary_path'          => $this->get_primary_path($parsed['repo']),
			'dirty'                 => null,
			'created_at'            => $metadata['created_at'] ?? null,
			'lifecycle_state'       => $metadata['lifecycle_state'] ?? null,
			'pr_url'                => $metadata['pr_url'] ?? null,
			'pr_number'             => $metadata['pr_number'] ?? null,
			'last_seen_at'          => $metadata['last_seen_at'] ?? null,
			'liveness'              => $liveness['liveness'],
			'liveness_reason'       => $liveness['reason'],
			'heartbeat_age_seconds' => $liveness['heartbeat_age_seconds'],
			'owner'                 => $owner,
			'session'               => $session,
			'task'                  => $task,
			'missing_path'          => ! is_dir($path),
			'metadata'              => $metadata,
		);
	}

	/**
	 * Remove a worktree.
	 *
	 * Refuses if the worktree has uncommitted changes unless `$force` is true.
	 *
	 * @param  string $repo   Primary repo name.
	 * @param  string $branch Branch (or slug) of the worktree.
	 * @param  bool   $force  Force removal even if dirty.
	 * @return array{success: bool, handle: string, message: string}|\WP_Error
	 */
	public function worktree_remove( string $repo, string $branch, bool $force = false ): array|\WP_Error {
		$repo = $this->sanitize_name($repo);
		if ( '' === $repo ) {
			return new \WP_Error('invalid_repo', 'Repository name is required.', array( 'status' => 400 ));
		}

		$slug = $this->slugify_branch($branch);
		if ( '' === $slug ) {
			return new \WP_Error('invalid_branch', 'Branch/slug is required.', array( 'status' => 400 ));
		}

		$primary_path = $this->get_primary_path($repo);
		if ( ! GitCheckout::exists($primary_path) ) {
			return new \WP_Error('primary_not_found', sprintf('Primary checkout for "%s" does not exist.', $repo), array( 'status' => 404 ));
		}

		$wt_handle = $repo . '@' . $slug;
		$wt_path   = $this->workspace_path . '/' . $wt_handle;

		if ( ! is_dir($wt_path) ) {
			return new \WP_Error('worktree_not_found', sprintf('Worktree "%s" not found.', $wt_handle), array( 'status' => 404 ));
		}

		$result = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			function () use ( $primary_path, $wt_path, $force, $wt_handle ) {
				$protection = GitCheckout::deletion_protection($wt_path, $this->workspace_path);
				if ( null !== $protection ) {
					return new \WP_Error($protection['code'], $protection['message'], array( 'status' => 409 ) + $protection);
				}
				$cmd    = sprintf('worktree remove %s%s', $force ? '--force ' : '', escapeshellarg($wt_path));
				$result = $this->run_git($primary_path, $cmd);

				if ( is_wp_error($result) ) {
					return $this->worktree_git_unavailable_with_host_commands(
						$result,
						'Remove workspace worktree',
						array(
							sprintf('git -C %s %s', escapeshellarg($primary_path), $cmd),
						)
					);
				}
				clearstatcache(true, $wt_path);
				if ( is_dir($wt_path) ) {
					return new \WP_Error('worktree_remove_incomplete', sprintf('Git reported worktree removal success, but the directory still exists: %s', $wt_path), array(
						'status' => 500,
						'path'   => $wt_path,
					));
				}

				// Commit metadata removal only after the destructive mutation is proven.
				WorktreeContextInjector::forget_metadata($wt_handle);
				$this->worktree_inventory()->delete($wt_handle);
				return $result;
			}
		);

		if ( is_wp_error($result) ) {
			return $result;
		}

		$this->emit_workspace_changed('worktree_remove', $repo, $wt_handle, $wt_path);

		return array(
			'success' => true,
			'handle'  => $wt_handle,
			'message' => sprintf('Worktree "%s" removed.', $wt_handle),
		);
	}

	/**
	 * Prune stale worktree registry entries across all primaries.
	 *
	 * Git removes only registrations whose worktree path is absent. Expiring now
	 * makes that reconciliation immediate without touching existing checkouts,
	 * including dirty or unpushed worktrees.
	 *
	 * @return array{success: bool, dry_run: bool, pruned: array, would_prune: array, skipped?: array, next_commands?: array, inventory?: array, stale_inventory?: array, stale_marker_blockers?: array}|\WP_Error
	 */
	public function worktree_prune( bool $dry_run = false, mixed $until_budget = null ): array|\WP_Error {
		$budget = WallClockBudget::from_duration($until_budget, '30s', 'invalid_worktree_prune_budget');
		if ( $budget instanceof \WP_Error ) {
			return $budget;
		}
		$pruned          = array();
		$would_prune     = array();
		$skipped         = array();
		$next_commands   = array();
		$partial         = false;

		if ( ! is_dir($this->workspace_path) ) {
			return array(
				'success'     => true,
				'dry_run'     => $dry_run,
				'pruned'      => $pruned,
				'would_prune' => $would_prune,
			);
		}

		$entries = scandir($this->workspace_path);
		foreach ( $entries as $entry ) {
			if ( $budget->expired() ) {
				$partial = true;
				break;
			}
			if ( '.' === $entry || '..' === $entry || str_contains($entry, '@') ) {
				continue;
			}
			$primary_path = $this->workspace_path . '/' . $entry;
			if ( ! GitCheckout::exists($primary_path) ) {
				continue;
			}
			$command = $dry_run ? 'worktree prune --dry-run --verbose --expire=now' : 'worktree prune -v --expire=now';
			$timeout = $budget->probe_timeout_seconds(5);
			if ( $timeout < 1 ) {
				$partial = true;
				break;
			}
			$result  = $dry_run
				? $this->run_git($primary_path, $command, $timeout)
				: WorkspaceMutationLock::with_repo(
					$this->workspace_path,
					$entry,
					fn() => $this->run_git($primary_path, $command, max(1, $budget->probe_timeout_seconds(5))),
					$timeout
				);
			if ( is_wp_error($result) ) {
				if ( 'datamachine_workspace_git_unavailable' === $result->get_error_code() ) {
					$skipped[]       = array(
						'repo'         => $entry,
						'primary_path' => $primary_path,
						'reason'       => $result->get_error_message(),
					);
					$next_commands[] = sprintf('git -C %s worktree prune --dry-run --verbose --expire=now', escapeshellarg($primary_path));
					continue;
				}
				return $result;
			}
			// Git emits a verbose line for every stale registration. Report only
			// primaries with candidates instead of every primary merely scanned.
			if ( '' !== trim( (string) ( $result['output'] ?? '' )) ) {
				if ( $dry_run ) {
					$would_prune[] = $entry;
				} else {
					$pruned[] = $entry;
				}
			}
		}
		return array(
			'success'               => true,
			'dry_run'               => $dry_run,
			'pruned'                => $dry_run ? array() : $pruned,
			'would_prune'            => $dry_run ? $would_prune : array(),
			'skipped'               => $skipped,
			'next_commands'         => array_values(array_unique($next_commands)),
			'partial'               => $partial,
			'budget'                => $budget->evidence(),
			'stale_inventory'       => array(),
			'stale_marker_blockers' => array(),
		);
	}

	/**
	 * Inspect capacity through a testable admission seam.
	 *
	 * @param array<string,mixed> $demand_plan
	 * @return array<string,mixed>
	 */
	protected function inspect_worktree_capacity( string $repo, string $branch, bool $force, array $demand_plan ): array {
		$reservations                         = WorktreeContextInjector::bootstrap_capacity_reservations();
		$demand_plan['bytes']                 = max(0, (int) ( $demand_plan['bytes'] ?? 0 )) + (int) $reservations['bytes'];
		$demand_plan['inodes']                = max(0, (int) ( $demand_plan['inodes'] ?? 0 )) + (int) $reservations['inodes'];
		$demand_plan['capacity_reservations'] = $reservations;
		return WorktreeDiskBudget::inspect(
			$this->workspace_path,
			WorktreeDiskBudget::thresholds($repo, $branch),
			$force,
			array( 'include_workspace_usage' => true ),
			$demand_plan
		);
	}

	/**
	 * Run one bounded, already-classified remediation pass while the caller owns
	 * the workspace capacity lock. This never broadens cleanup policy or retries
	 * creation recursively: the caller continues its original immutable request.
	 *
	 * @param array<string,mixed> $demand_plan
	 * @param array<string,mixed> $before
	 * @return array<string,mixed>
	 */
	protected function remediate_capacity_refusal( string $repo, string $branch, array $demand_plan, array $before, bool $dry_run ): array {
		if ( ! class_exists(WorkspaceCleanupEligibleDrainOrchestrator::class) ) {
			require_once __DIR__ . '/WorkspaceCleanupEligibleDrainOrchestrator.php';
		}

		$artifact_preview = $this->worktree_cleanup_artifacts(
			array(
				'dry_run'       => true,
				'limit'         => 25,
				'safety_probes' => true,
			)
		);
		if ( $artifact_preview instanceof \WP_Error ) {
			return $this->capacity_remediation_failure($before, $dry_run, 'artifact_preview', $artifact_preview);
		}

		$artifact_apply = null;
		if ( ! $dry_run ) {
			$repos          = array_values(array_unique(array_filter(array_map(
				static fn( $candidate ): string => is_array($candidate) ? (string) ( $candidate['repo'] ?? '' ) : '',
				(array) ( $artifact_preview['candidates'] ?? array() )
			))));
			$artifact_apply = WorkspaceMutationLock::with_repos(
				$this->workspace_path,
				$repos,
				fn() => $this->worktree_cleanup_artifacts(
					array(
						'apply_plan'    => array( 'candidates' => (array) ( $artifact_preview['candidates'] ?? array() ) ),
						'limit'         => 25,
						'safety_probes' => true,
					)
				)
			);
			if ( $artifact_apply instanceof \WP_Error ) {
				return $this->capacity_remediation_failure($before, $dry_run, 'artifact_apply', $artifact_apply, $artifact_preview);
			}
		}

		$drain_preview = ( new WorkspaceCleanupEligibleDrainOrchestrator() )->run(
			array(
				'apply'        => false,
				'limit'        => 25,
				'passes'       => 1,
				'until_budget' => '30s',
				'source'       => 'worktree_capacity_admission_remediation',
			)
		);
		if ( $drain_preview instanceof \WP_Error ) {
			return $this->capacity_remediation_failure($before, $dry_run, 'cleanup_drain', $drain_preview, $artifact_preview, $artifact_apply);
		}
		$drain = $drain_preview;
		if ( ! $dry_run ) {
			$drain = ( new WorkspaceCleanupEligibleDrainOrchestrator() )->run(
				array(
					'apply'        => true,
					'limit'        => 25,
					'passes'       => 1,
					'until_budget' => '30s',
					'source'       => 'worktree_capacity_admission_remediation',
					'apply_plan'   => (array) ( $drain_preview['apply_plan'] ?? array() ),
				)
			);
			if ( $drain instanceof \WP_Error ) {
				return $this->capacity_remediation_failure($before, false, 'cleanup_drain', $drain, $artifact_preview, $artifact_apply);
			}
		}

		$after = $dry_run ? $before : $this->inspect_worktree_capacity($repo, $branch, false, $demand_plan);
		return array(
			'mode'              => 'bounded_safe_remediation',
			'dry_run'           => $dry_run,
			'before'            => $before,
			'after'             => $after,
			'artifact_preview'  => $artifact_preview,
			'artifact_apply'    => $artifact_apply,
			'cleanup_drain'     => $drain,
			'reclaimed_bytes'   => (int) ( $drain['summary']['bytes_reclaimed'] ?? 0 ),
			'reclaimed_inodes'  => max(0, (int) ( $after['free_inodes'] ?? 0 ) - (int) ( $before['free_inodes'] ?? 0 )),
			'retry_disposition' => $dry_run
				? 'dry_run_no_retry'
				: ( 'refused' === ( $after['status'] ?? '' ) ? 'insufficient_safe_reclaim' : 'retry_once' ),
		);
	}

	/** @return array<string,mixed> */
	private function capacity_remediation_failure( array $before, bool $dry_run, string $stage, \WP_Error $error, ?array $artifact_preview = null, ?array $artifact_apply = null ): array {
		$error_data = $error->get_error_data();
		return array(
			'mode'              => 'bounded_safe_remediation',
			'dry_run'           => $dry_run,
			'before'            => $before,
			'after'             => $before,
			'artifact_preview'  => $artifact_preview,
			'artifact_apply'    => $artifact_apply,
			'cleanup_drain'     => is_array($error_data) && is_array($error_data['cleanup_drain'] ?? null) ? $error_data['cleanup_drain'] : null,
			'failure'           => array(
				'stage'   => $stage,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error_data,
			),
			'retry_disposition' => 'no_retry_remediation_failed',
		);
	}

	/** @return array<string,mixed> */
	private function capacity_add_intent( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, array $task, array $intent, string $reuse_policy ): array {
		if ( isset($task['task_url']) ) {
			$task_url = TaskUrl::canonicalize_for_replay($task['task_url']);
			if ( null === $task_url ) {
				unset($task['task_url']);
			} else {
				$task['task_url'] = $task_url;
			}
		}
		return array(
			'repo'           => $repo,
			'branch'         => $branch,
			'from'           => $from,
			'inject_context' => $inject_context,
			'bootstrap'      => $bootstrap,
			'allow_stale'    => $allow_stale,
			'rebase_base'    => $rebase_base,
			'task'           => $task,
			'intent'         => $intent,
			'reuse_policy'   => $reuse_policy,
		);
	}

	/**
	 * Reclaim only already-eligible reconstructable artifacts before refusing
	 * admission. The caller holds the global capacity lock, so the follow-up
	 * measurement and admission decision cannot race another worktree creation.
	 *
	 * @param array<string,mixed> $demand_plan
	 * @param array<string,mixed> $before
	 * @return array{after:array<string,mixed>,evidence:array<string,mixed>}
	 */
	protected function reclaim_capacity_eligible_artifacts( string $repo, string $branch, bool $force, array $demand_plan, array $before ): array {
		$evidence = array(
			'attempted'       => false,
			'reclaimed_bytes' => 0,
			'skipped'         => array(),
			'final_decision'  => 'admitted_without_reclaim',
		);
		if ( $force || 'refused' !== ( $before['status'] ?? '' ) ) {
			$evidence['skip_reason'] = $force ? 'force_override' : 'capacity_not_refused';
			return array(
				'after'    => $before,
				'evidence' => $evidence,
			);
		}

		$evidence['attempted'] = true;
		if ( ! class_exists(CleanupRunService::class) ) {
			require_once __DIR__ . '/CleanupRunService.php';
		}
		$reclaim = $this->run_capacity_artifact_reclaim();
		$after   = $this->inspect_worktree_capacity($repo, $branch, false, $demand_plan);
		if ( $reclaim instanceof \WP_Error ) {
			$evidence['error_code']     = $reclaim->get_error_code();
			$evidence['final_decision'] = 'refused_after_reclaim_error';
			return array(
				'after'    => $after,
				'evidence' => $evidence,
			);
		}

		$evidence['state']           = (string) ( $reclaim['state'] ?? 'unknown' );
		$evidence['run_ids']         = array_values(array_filter(array_map(static fn( $pass ): string => is_array($pass) ? (string) ( $pass['run_id'] ?? '' ) : '', (array) ( $reclaim['passes'] ?? array() ))));
		$evidence['applied']         = (int) ( $reclaim['applied'] ?? 0 );
		$evidence['reclaimed_bytes'] = (int) ( $reclaim['bytes_reclaimed'] ?? 0 );
		$evidence['skipped']         = $this->capacity_reclaim_skipped_categories($reclaim);
		$final_summary               = (array) ( $reclaim['final_plan_summary'] ?? array() );
		if ( 'completed' === ( $reclaim['state'] ?? '' ) && array() !== $final_summary ) {
			$evidence['gross_candidate_bytes']    = max(0, (int) ( $final_summary['gross_candidate_bytes'] ?? 0 ));
			$evidence['actionable_reclaim_bytes'] = max(0, (int) ( $final_summary['actionable_reclaim_bytes'] ?? $final_summary['total_reclaimable_bytes'] ?? 0 ));
			$evidence['actionable_rows']          = max(0, (int) ( $final_summary['total_rows'] ?? 0 ));
			$evidence['actionability_status']     = 0 === $evidence['actionable_rows'] ? 'no_actionable_rows' : 'actionable_rows_available';
		} elseif ( array() !== $final_summary ) {
			$evidence['actionability_status'] = 'pagination_incomplete';
		}
		$evidence['final_decision'] = 'refused' === ( $after['status'] ?? '' ) ? 'refused_after_reclaim' : 'admitted_after_reclaim';

		return array(
			'after'    => $after,
			'evidence' => $evidence,
		);
	}

	/** @return array<string,int> */
	protected function capacity_reclaim_skipped_categories( array $reclaim ): array {
		$categories = array();
		foreach ( (array) ( $reclaim['remaining_blocked_reasons'] ?? array() ) as $reason => $bucket ) {
			$count = is_array($bucket) ? (int) ( $bucket['count'] ?? 0 ) : (int) $bucket;
			if ( $count > 0 ) {
				$categories[ (string) $reason ] = $count;
			}
		}
		return $categories;
	}

	/** @return array<string,mixed>|\WP_Error */
	protected function run_capacity_artifact_reclaim(): array|\WP_Error {
		return ( new CleanupRunService() )->until_empty(
			array(
				'mode'           => 'artifacts',
				'force'          => false,
				'limit'          => 25,
				'max_passes'     => 3,
				'budget_seconds' => 30,
			)
		);
	}

	/**
	 * Reconcile bounded lifecycle metadata, then freeze a fresh non-destructive cleanup plan.
	 *
	 * Metadata writes never remove a worktree. Any cleanup remains behind the returned
	 * DB-backed apply command and its existing fresh-state revalidation.
	 *
	 * @param array<string,mixed> $opts Bounded recovery options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_capacity_recovery( array $opts = array() ): array|\WP_Error {
		$limit         = max(1, min(100, (int) ( $opts['limit'] ?? 25 )));
		$offset        = max(0, (int) ( $opts['offset'] ?? 0 ));
		$replan_offset = max(0, (int) ( $opts['replan_offset'] ?? 0 ));
		$until_budget  = trim( (string) ( $opts['until_budget'] ?? '30s' ));
		$reconcile     = array(
			'apply'        => true,
			'limit'        => $limit,
			'offset'       => $offset,
			'until_budget' => '' === $until_budget ? '30s' : $until_budget,
		);
		$metadata      = $this->worktree_reconcile_metadata($reconcile);
		if ( $metadata instanceof \WP_Error ) {
			return $metadata;
		}
		$metadata_continuation = (array) ( $metadata['pagination'] ?? array() );
		if ( ! empty($metadata_continuation['partial']) || empty($metadata_continuation['complete']) ) {
			$next_offset                           = max(0, (int) ( $metadata_continuation['next_offset'] ?? ( $offset + $limit ) ));
			$next_command                          = $this->worktree_capacity_recovery_command($limit, $next_offset, 0, $until_budget);
			$metadata_continuation['next_offset']  = $next_offset;
			$metadata_continuation['next_command'] = $next_command;
			return array(
				'success'                 => true,
				'mode'                    => 'capacity_recovery',
				'metadata_reconciliation' => $metadata,
				'replan'                  => null,
				'next_approval'           => null,
				'continuation'            => $metadata_continuation,
				'next_command'            => $next_command,
			);
		}

		$plan_options = array(
			'mode'              => 'cleanup_plan',
			'include_artifacts' => true,
			'include_worktrees' => true,
			'include_resolvers' => false,
			'limit'             => $limit,
			'offset'            => $replan_offset,
			'until_budget'      => '' === $until_budget ? '30s' : $until_budget,
		);
		$plan         = $this->run_capacity_recovery_plan($plan_options);
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		$result            = array(
			'success'                 => true,
			'mode'                    => 'capacity_recovery',
			'metadata_reconciliation' => $metadata,
			'replan'                  => $plan,
			'next_approval'           => null,
			'continuation'            => null,
			'next_command'            => null,
		);
		$plan_continuation = (array) ( $plan['continuation'] ?? array() );
		if ( ! empty($plan_continuation['partial']) || null !== ( $plan_continuation['next_offset'] ?? null ) ) {
			$next_replan_offset                = max(0, (int) ( $plan_continuation['next_offset'] ?? ( $replan_offset + $limit ) ));
			$next_command                      = $this->worktree_capacity_recovery_command($limit, 0, $next_replan_offset, $until_budget);
			$plan_continuation['next_offset']  = $next_replan_offset;
			$plan_continuation['next_command'] = $next_command;
			$result['continuation']            = $plan_continuation;
			$result['next_command']            = $next_command;
			return $result;
		}

		$actionable_rows = (int) ( $plan['summary']['total_rows'] ?? 0 );
		$next_approval   = $actionable_rows > 0
			? array(
				'command'                  => (string) ( $plan['summary']['apply_command'] ?? '' ),
				'destructive'              => true,
				'actionable_rows'          => $actionable_rows,
				'actionable_reclaim_bytes' => max(0, (int) ( $plan['summary']['actionable_reclaim_bytes'] ?? 0 )),
			)
			: null;

		$result['next_approval'] = $next_approval;
		$result['continuation']  = $plan['continuation'] ?? null;
		$result['next_command']  = $plan['continuation']['next_command'] ?? null;
		return $result;
	}

	private function worktree_capacity_recovery_command( int $limit, int $offset, int $replan_offset, string $until_budget ): string {
		return sprintf(
			'studio wp datamachine-code workspace worktree capacity-recovery --limit=%d --offset=%d --replan-offset=%d --until-budget=%s --format=json',
			$limit,
			$offset,
			$replan_offset,
			escapeshellarg('' === $until_budget ? '30s' : $until_budget)
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	protected function run_capacity_recovery_plan( array $options ): array|\WP_Error {
		return ( new CleanupRunService(null, $this) )->plan($options);
	}

	/**
	 * Attach host-shell remediation commands to local-git-unavailable worktree errors.
	 *
	 * @param \WP_Error         $error         Original git error.
	 * @param string            $operation     Human-readable operation.
	 * @param array<int,string> $next_commands Exact commands to run in a host shell.
	 * @return \WP_Error
	 */
	private function worktree_git_unavailable_with_host_commands( \WP_Error $error, string $operation, array $next_commands ): \WP_Error {
		if ( 'datamachine_workspace_git_unavailable' !== $error->get_error_code() ) {
			return $error;
		}

		$data                  = (array) $error->get_error_data();
		$data['operation']     = $operation;
		$data['next_commands'] = array_values(array_filter(array_map('strval', $next_commands)));
		$data['hint']          = 'Run the listed command from a host shell with local git access, then rerun workspace worktree prune to refresh DMC inventory.';

		$message = $error->get_error_message();
		if ( ! empty($data['next_commands'][0]) ) {
			$message .= ' Host command: ' . $data['next_commands'][0];
		}

		return new \WP_Error($error->get_error_code(), $message, $data);
	}


	/**
	 * Resolve a sensible default base for new branches.
	 *
	 * Prefers `origin/HEAD` (typically `origin/main` or `origin/trunk`); falls
	 * back to plain `HEAD` if no remote default is configured.
	 *
	 * @param  string $repo_path Primary repo path.
	 * @return string
	 */
	private function resolve_default_base( string $repo_path ): string {
		$result = $this->run_git($repo_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD');
		if ( ! is_wp_error($result) ) {
			$ref = trim( (string) ( $result['output'] ?? '' ));
			if ( '' !== $ref ) {
				return $ref;
			}
		}
		return 'HEAD';
	}

	/**
	 * Resolve the fetched remote default branch ref, if one is configured.
	 *
	 * @param  string $repo_path Primary repo path.
	 * @return string|null Fully-qualified remote default ref, or null when absent.
	 */
	private function resolve_remote_default_ref( string $repo_path, int $timeout_seconds = 0 ): string|\WP_Error|null {
		if ( $timeout_seconds > 0 ) {
			$remote = $this->run_git($repo_path, 'ls-remote --symref origin HEAD', $timeout_seconds);
			if ( is_wp_error($remote) ) {
				if ( $this->is_git_timeout_error($remote) ) {
					return new \WP_Error('worktree_handoff_revalidation_timeout', 'The bounded handoff remote probe deadline expired while resolving the remote default ref.', array( 'status' => 409 ));
				}
				return new \WP_Error('remote_default_unresolved', 'The remote default branch could not be verified. Check remote network, proxy, and credentials, then retry.', array( 'status' => 409 ));
			}
			if ( ! preg_match('/^ref: refs\/heads\/([^\s]+)\s+HEAD$/m', (string) ( $remote['output'] ?? '' ), $matches) ) {
				return new \WP_Error('remote_default_unresolved', 'The remote did not advertise an unambiguous default branch. Configure the remote HEAD or retry with an explicit base branch.', array( 'status' => 409 ));
			}
			return 'refs/remotes/origin/' . $matches[1];
		}

		$result = $this->run_git($repo_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD', $timeout_seconds);
		if ( is_wp_error($result) ) {
			if ( $timeout_seconds > 0 && $this->is_git_timeout_error($result) ) {
				return new \WP_Error('worktree_handoff_revalidation_timeout', 'The bounded handoff remote probe deadline expired while resolving the remote default ref.', array( 'status' => 409 ));
			}
			return null;
		}

		$ref = trim( (string) ( $result['output'] ?? '' ));
		return '' !== $ref ? $ref : null;
	}

	/**
	 * Refuse a branch/base that is behind the remote default branch.
	 *
	 * This is intentionally zero-tolerance. The older upstream staleness gate has
	 * a threshold for large-drift cleanup, but default-branch freshness protects
	 * the starting point for new agent work and should not silently allow lag.
	 *
	 * @param  string $primary_path Primary repo path.
	 * @param  string $ref          Branch or base ref to compare.
	 * @param  string $repo         Repository name.
	 * @param  string $branch       Requested worktree branch.
	 * @param  string $ref_role     Human-readable role: branch or base.
	 * @return true|\WP_Error True when current/unknown, WP_Error when behind.
	 */
	private function assert_ref_current_with_default_branch( string $primary_path, string $ref, string $repo, string $branch, string $ref_role ): true|\WP_Error {
		$default_ref = $this->resolve_remote_default_ref($primary_path);
		if ( is_wp_error($default_ref) ) {
			return $default_ref;
		}
		if ( null === $default_ref ) {
			return true;
		}

		$behind = WorktreeStalenessProbe::behind_count($primary_path, $ref, $default_ref);
		if ( ! is_int($behind) || 0 === $behind ) {
			return true;
		}

		return $this->worktree_behind_default_branch_error($behind, $default_ref, $repo, $branch, $ref_role);
	}

	/**
	 * Add default-branch freshness fields for the materialized branch.
	 *
	 * @param  string $primary_path Primary repo path.
	 * @param  string $branch       Requested worktree branch.
	 * @param  array  $response     Worktree response payload, mutated in place.
	 */
	private function populate_default_branch_behind_count( string $primary_path, string $branch, array &$response, int $timeout_seconds = 0 ): ?\WP_Error {
		$default_ref = $this->resolve_remote_default_ref($primary_path, $timeout_seconds);
		if ( is_wp_error($default_ref) ) {
			return $default_ref;
		}
		if ( null === $default_ref ) {
			return null;
		}

		$behind = $this->worktree_behind_count($primary_path, $branch, $default_ref, $timeout_seconds);
		if ( is_wp_error($behind) ) {
			return $behind;
		}
		if ( is_int($behind) ) {
			$response['default_branch_commits_behind'] = $behind;
			$response['default_branch_ref']            = $default_ref;
		}
		return null;
	}

	/**
	 * Build the default-branch staleness error used by preflight and rollback gates.
	 *
	 * @param  int    $behind     Commits behind the remote default branch.
	 * @param  string $default_ref Remote default branch ref.
	 * @param  string $repo       Repository name.
	 * @param  string $branch     Requested worktree branch.
	 * @param  string $ref_role   Human-readable role: branch or base.
	 * @return \WP_Error
	 */
	private function worktree_behind_default_branch_error( int $behind, string $default_ref, string $repo, string $branch, string $ref_role ): \WP_Error {
		return new \WP_Error(
			'worktree_behind_default_branch',
			sprintf(
				'Worktree %s for branch "%s" is %d commits behind the remote default branch %s. Refusing to create a stale worktree. Refresh or rebase the branch first, create from the remote default ref directly, or pass --allow-stale to explicitly opt in to a known-stale checkout.',
				$ref_role,
				$branch,
				$behind,
				$default_ref
			),
			array(
				'status'                        => 409,
				'default_branch_commits_behind' => $behind,
				'default_branch_ref'            => $default_ref,
				'repo'                          => $repo,
				'branch'                        => $branch,
				'ref_role'                      => $ref_role,
				'allow_stale'                   => false,
			)
		);
	}

	/**
	 * Remove a worktree rejected after creation and delete its new local branch.
	 *
	 * @param string $primary_path   Primary checkout path.
	 * @param string $wt_path        Worktree path.
	 * @param string $branch         Branch checked out in the worktree.
	 * @param bool   $created_branch Whether the branch was created by this call.
	 * @return array<string,mixed>
	 */
	private function rollback_rejected_worktree( string $primary_path, string $wt_path, string $branch, bool $created_branch, ?string $handle = null, ?array $creation_intent = null ): array {
		$before  = WorktreeDiskBudget::inspect($this->workspace_path);
		$timeout = function_exists('apply_filters') ? (int) apply_filters('datamachine_code_worktree_rollback_timeout_seconds', 5) : 5;
		$timeout = max(1, $timeout);
		$this->run_git($primary_path, sprintf('worktree remove --force %s', escapeshellarg($wt_path)), $timeout);
		if ( $created_branch ) {
			$this->run_git($primary_path, sprintf('branch -D %s', escapeshellarg($branch)), $timeout);
		}
		if ( null !== $handle && null !== $creation_intent ) {
			WorktreeContextInjector::forget_creation_intent($handle, $creation_intent);
		}
		$after = WorktreeDiskBudget::inspect($this->workspace_path);
		return array(
			'before'  => array(
				'filesystem_free_bytes'  => $before['filesystem_free_bytes'] ?? null,
				'filesystem_free_inodes' => $before['filesystem_free_inodes'] ?? null,
			),
			'after'   => array(
				'filesystem_free_bytes'  => $after['filesystem_free_bytes'] ?? null,
				'filesystem_free_inodes' => $after['filesystem_free_inodes'] ?? null,
			),
			'outcome' => 'rollback',
		);
	}

	/** Return whole seconds left in the operation-wide deadline. */
	private function worktree_operation_remaining_seconds( float $deadline ): int {
		return max(0, (int) ceil($deadline - microtime(true)));
	}

	/** Create typed timeout evidence shared by every lifecycle phase. */
	private function worktree_operation_timeout( string $phase, int $timeout, float $started, array $extra = array() ): \WP_Error {
		$elapsed = $started > 0.0 ? max(0.0, microtime(true) - $started) : null;
		return new \WP_Error(
			'worktree_operation_timeout',
			sprintf('The aggregate worktree operation deadline expired during %s.', str_replace('_', ' ', $phase)),
			array_merge(
				array(
					'status'                    => 504,
					'phase'                     => $phase,
					'timed_out'                 => true,
					'operation_timeout_seconds' => $timeout,
					'elapsed_seconds'           => null === $elapsed ? null : round($elapsed, 3),
					'retryable'                 => true,
					'progress'                  => array(
						'phase' => $phase,
						'state' => 'timed_out',
					),
				),
				$extra
			)
		);
	}

	/** Preserve an exact post-create journal when a bounded safety probe times out. */
	private function worktree_post_create_probe_timeout( string $phase, int $timeout, float $started, string $handle, string $path, ?\WP_Error $probe_error = null ): \WP_Error {
		$probe = null;
		if ( null !== $probe_error ) {
			$probe = array(
				'code' => $probe_error->get_error_code(),
				'data' => $probe_error->get_error_data(),
			);
		}
		return $this->worktree_operation_timeout(
			$phase,
			$timeout,
			$started,
			array(
				'handle'   => $handle,
				'path'     => $path,
				'probe'    => $probe,
				'recovery' => array(
					'status'             => 'creation_journal_retained',
					'reason_code'        => 'post_create_probe_timeout',
					'retry_same_request' => true,
				),
			)
		);
	}

	/** Run a bounded post-create staleness probe; overridable by lifecycle fixtures. */
	protected function worktree_behind_count( string $repo_path, string $ref, string $upstream, int $timeout_seconds ): int|null|\WP_Error {
		return WorktreeStalenessProbe::behind_count($repo_path, $ref, $upstream, $timeout_seconds);
	}

	/** Return typed timeout evidence only after the shared deadline expires. */
	private function worktree_operation_deadline_error( string $phase, float $deadline, int $timeout, float $started ): ?\WP_Error {
		return $this->worktree_operation_remaining_seconds($deadline) <= 0
			? $this->worktree_operation_timeout($phase, $timeout, $started)
			: null;
	}

	/** Normalize lock wait expiry into the public aggregate timeout contract. */
	private function worktree_operation_lock_result( mixed $result, string $phase, int $timeout, float $started ): mixed {
		$data = is_wp_error($result) ? (array) $result->get_error_data() : array();
		if ( ! is_wp_error($result) || 'workspace_repo_busy' !== $result->get_error_code() || empty($data['timed_out']) ) {
			return $result;
		}
		$owner     = array_filter(array(
			'active_lock'          => $data['active_lock'] ?? null,
			'filesystem_lock'      => $data['filesystem_lock'] ?? null,
			'lock_key'             => $data['lock_key'] ?? null,
			'wait_timeout_seconds' => $data['wait_timeout_seconds'] ?? null,
		));
		$admission = array_filter(
			array(
				'request_id'             => $data['request_id'] ?? null,
				'queue_position'         => $data['queue_position'] ?? null,
				'owner'                  => $data['owner'] ?? null,
				'retry_after_seconds'    => $data['retry_after_seconds'] ?? null,
				'estimated_wait_seconds' => $data['estimated_wait_seconds'] ?? null,
				'eta_status'             => $data['eta_status'] ?? null,
				'retry_command'          => $data['retry_command'] ?? null,
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
		return $this->worktree_operation_timeout(
			$phase,
			$timeout,
			$started,
			array(
				'lock_owner' => $owner,
				'admission'  => array_merge(array( 'mutation_committed' => false ), $admission),
			)
		);
	}

	/**
	 * Does a ref look like a remote-tracking ref?
	 *
	 * `resolve_default_base()` returns fully-qualified paths
	 * (`refs/remotes/origin/main`), but callers may pass short forms like
	 * `origin/main`. Both are "already at-tip post-fetch" and staleness
	 * comparisons against them would be nonsensical.
	 *
	 * @param  string $ref Ref name to classify.
	 * @return bool
	 */
	private function is_remote_tracking_ref( string $ref ): bool {
		return str_starts_with($ref, 'refs/remotes/') || str_starts_with($ref, 'origin/');
	}

	/**
	 * Pull the single behind-count that matters for gate decisions.
	 *
	 * The staleness probe records up to two behind-counts depending on
	 * the path: `stale_commits_behind` for an existing branch vs its
	 * upstream, or `base_stale_commits_behind` for a new branch cut off a
	 * stale local base. At most one of these is present in practice;
	 * whichever exists is the one we gate on.
	 *
	 * @param  array $response Accumulated response payload.
	 * @return int|null Behind-count, or null if no staleness data was collected.
	 */
	private function effective_behind_count( array $response ): ?int {
		if ( isset($response['stale_commits_behind']) ) {
			return (int) $response['stale_commits_behind'];
		}
		if ( isset($response['base_stale_commits_behind']) ) {
			return (int) $response['base_stale_commits_behind'];
		}
		return null;
	}

	/**
	 * Attempt to rebase the worktree onto its upstream.
	 *
	 * Target selection:
	 *   - Existing-local-branch path → rebase onto `@{upstream}` if one is
	 *     configured AND we observed stale_commits_behind > 0.
	 *   - New-branch-off-local-base path → rebase onto `<base_upstream>` if
	 *     we observed base_stale_commits_behind > 0.
	 *
	 * Returns an associative array to merge into the response:
	 *   rebase_attempted, rebase_target, rebase_succeeded [, rebase_error]
	 *
	 * On success, clears the relevant staleness field (behind-count zeroes
	 * out and the gate will not trip). On conflict the rebase is aborted
	 * so the worktree stays at its pre-rebase state, and the gate may
	 * still trip — `--rebase-base` is not a silent `--allow-stale`.
	 *
	 * Returns null when there's nothing meaningful to rebase (up to date,
	 * no upstream, or staleness couldn't be computed).
	 *
	 * @param  string $wt_path        Worktree path.
	 * @param  array  $response       Accumulated response payload.
	 * @param  bool   $created_branch Whether this was a freshly-created branch.
	 * @return array|null
	 */
	private function try_rebase_worktree( string $wt_path, array &$response, bool $created_branch, float $operation_deadline ): ?array {
		$target = null;
		$clear  = null;

		if ( ! $created_branch
			&& isset($response['stale_commits_behind'])
			&& (int) $response['stale_commits_behind'] > 0
		) {
			$target = '@{upstream}';
			$clear  = 'stale_commits_behind';
		} elseif ( $created_branch
			&& isset($response['base_stale_commits_behind'])
			&& (int) $response['base_stale_commits_behind'] > 0
			&& ! empty($response['base_upstream'])
		) {
			$target = (string) $response['base_upstream'];
			$clear  = 'base_stale_commits_behind';
		}

		if ( null === $target ) {
			return null;
		}

		$remaining = (int) floor($operation_deadline - microtime(true));
		if ( $remaining <= 5 ) {
			return array(
				'rebase_attempted'      => false,
				'rebase_target'         => $target,
				'rebase_succeeded'      => false,
				'rebase_cleanup_failed' => true,
				'rebase_error'          => 'The operation deadline left no bounded window for rebase and verified abort.',
			);
		}
		$result = $this->run_git($wt_path, sprintf('rebase %s', escapeshellarg($target)), min(300, $remaining - 5));

		if ( is_wp_error($result) ) {
			// Abort so the worktree stays at its pre-rebase HEAD. Agent can
			// retry manually after resolving conflicts.
			$abort_remaining = (int) floor($operation_deadline - microtime(true));
			$abort           = $abort_remaining > 0
				? $this->run_git($wt_path, 'rebase --abort', min(5, $abort_remaining))
				: new \WP_Error('worktree_rebase_abort_timeout', 'No operation deadline remained for rebase cleanup.');

			$data  = $result->get_error_data();
			$tail  = is_array($data) && isset($data['output']) ? trim( (string) $data['output']) : '';
			$error = '' !== $tail ? $tail : $result->get_error_message();

			return array(
				'rebase_attempted'      => true,
				'rebase_target'         => $target,
				'rebase_succeeded'      => false,
				'rebase_cleanup_failed' => is_wp_error($abort),
				'rebase_error'          => $error,
				'rebase_abort_error'    => is_wp_error($abort) ? $abort->get_error_message() : null,
			);
		}

		// Success: zero out the behind-count so the gate sees a fresh worktree.
		unset($response[ $clear ]);

		return array(
			'rebase_attempted' => true,
			'rebase_target'    => $target,
			'rebase_succeeded' => true,
		);
	}

	/**
	 * Parse a `git worktree list --porcelain` block.
	 *
	 * @param  string $block Newline-separated key/value lines.
	 * @return array{path: string, head: string, branch: string|null}|null
	 */
	/**
	 * Resolve a worktree's current branch by reading its private `.git`
	 * pointer file and the linked `HEAD`. Cheap file I/O — no `git` process.
	 *
	 * Returns `null` for detached HEADs, missing pointer files, or any other
	 * shape we can't parse. Callers should fall back to the inventory's
	 * `branch_slug` so plan rows still carry an identifying value for review.
	 *
	 * @param  string $wt_path Worktree directory.
	 * @return string|null Branch name (e.g. `fix/foo`), or null when unknown.
	 */
	private function resolve_worktree_branch_from_head_file( string $wt_path ): ?string {
		$git_pointer = rtrim($wt_path, '/') . '/.git';
		if ( ! is_file($git_pointer) && ! is_dir($git_pointer) ) {
			return null;
		}

		$gitdir = null;
		if ( is_file($git_pointer) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading .git pointer file in a controlled worktree.
			$pointer = @file_get_contents($git_pointer);
			if ( false === $pointer ) {
				return null;
			}
			if ( ! preg_match('/^gitdir:\s*(.+)$/m', $pointer, $m) ) {
				return null;
			}
			$gitdir = trim($m[1]);
			// Pointer paths are typically absolute, but tolerate relative.
			if ( '' !== $gitdir && '/' !== $gitdir[0] ) {
				$gitdir = rtrim($wt_path, '/') . '/' . $gitdir;
			}
		} else {
			$gitdir = $git_pointer;
		}

		if ( null === $gitdir || '' === $gitdir ) {
			return null;
		}

		$head_file = rtrim($gitdir, '/') . '/HEAD';
		if ( ! is_file($head_file) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading .git HEAD file in a controlled worktree.
		$head = @file_get_contents($head_file);
		if ( false === $head ) {
			return null;
		}

		$head = trim($head);
		if ( str_starts_with($head, 'ref:') ) {
			$ref = trim(substr($head, 4));
			return preg_replace('#^refs/heads/#', '', $ref);
		}

		// Detached HEAD or other unrecognized shape — surface as unknown.
		return null;
	}

	private function parse_worktree_block( string $block ): ?array {
		$lines = array_filter(array_map('trim', explode("\n", $block)));
		$out   = array(
			'path'   => '',
			'head'   => '',
			'branch' => null,
		);
		foreach ( $lines as $line ) {
			if ( str_starts_with($line, 'worktree ') ) {
				$out['path'] = substr($line, strlen('worktree '));
			} elseif ( str_starts_with($line, 'HEAD ') ) {
				$out['head'] = substr($line, strlen('HEAD '));
			} elseif ( str_starts_with($line, 'branch ') ) {
				$ref           = substr($line, strlen('branch '));
				$out['branch'] = preg_replace('#^refs/heads/#', '', $ref);
			} elseif ( 'detached' === $line ) {
				$out['branch'] = null;
			}
		}
		return ( '' === $out['path'] ) ? null : $out;
	}
}
