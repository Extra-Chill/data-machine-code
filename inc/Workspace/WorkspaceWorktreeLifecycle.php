<?php
/**
 * Workspace worktree lifecycle operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\ListCursor;

defined('ABSPATH') || exit;

trait WorkspaceWorktreeLifecycle {

	/**
	 * Produce a non-mutating, digest-addressed worktree allocation decision.
	 *
	 * This deliberately shares add's validation, target resolution, capacity, and
	 * exact-handle policy inputs. Apply re-runs this method immediately before add
	 * so remote refs, capacity, ownership, and destination changes fail closed.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_plan( string $repo, string $branch, ?string $from = null, bool $inject_context = true, bool $bootstrap = true, bool $allow_stale = false, bool $rebase_base = false, bool $force = false, array $task = array(), bool $allow_unverified_freshness = false, bool $require_task_tracker = false, array $intent = array(), string $reuse_policy = 'reuse_compatible' ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}
		$repo = $this->resolve_primary_repo_name($repo);
		$branch = trim($branch);
		if ( is_wp_error($repo) ) {
			return $repo;
		}
		if ( '' === $repo || '' === $branch ) {
			return new \WP_Error('invalid_worktree_intent', 'Repository name and branch are required.', array( 'status' => 400 ));
		}
		$task = WorktreeContextInjector::resolve_task_metadata($task) ?? array();
		$reuse_policy = strtolower(trim($reuse_policy));
		if ( ! in_array($reuse_policy, array( 'reuse_compatible', 'isolated', 'recycle_terminal' ), true) ) {
			return new \WP_Error('invalid_worktree_reuse_policy', 'reuse_policy must be one of: reuse_compatible, isolated, recycle_terminal.', array( 'status' => 400 ));
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

		$handle = $repo . '@' . $slug;
		$path = $this->workspace_path . '/' . $handle;
		$input = $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy);
		$input['allow_unverified_freshness'] = $allow_unverified_freshness;
		$input['require_task_tracker'] = $require_task_tracker;

		if ( is_dir($path) ) {
			$inspection = $this->worktree_get($handle, array( 'include_status' => true, 'include_disk' => false ));
			if ( is_wp_error($inspection) || empty($inspection['worktrees'][0]) ) {
				return new \WP_Error('worktree_plan_unsafe', 'The planned destination exists but cannot be safely inspected.', array( 'status' => 409, 'handle' => $handle ));
			}
			$existing = (array) $inspection['worktrees'][0];
			$metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : array();
			$contract = is_array($metadata['reuse_contract'] ?? null) ? $metadata['reuse_contract'] : array();
			$stored_intent = WorktreeContextInjector::normalize_disposable_intent($contract + $metadata);
			$exact = ( $existing['branch'] ?? null ) === $branch
				&& 0 === (int) ( $existing['dirty'] ?? 0 )
				&& 0 === (int) ( $existing['unpushed'] ?? 0 )
				&& array() !== $contract
				&& ( $contract['base_ref'] ?? null ) === ( null !== $from && '' !== trim($from) ? trim($from) : ( $contract['base_ref'] ?? null ) )
				&& (bool) ( $contract['inject_context'] ?? null ) === $inject_context
				&& (bool) ( $contract['bootstrap'] ?? null ) === $bootstrap
				&& $this->worktree_reuse_task_identity($task) === $this->worktree_reuse_task_identity((array) ($existing['task'] ?? array()))
				&& $intent === $stored_intent;
			$disposition = $exact ? 'exact_reuse' : ( null !== WorktreeContextInjector::get_creation_intent($handle) && array() === $contract ? 'adoptable' : 'unsafe' );
			if ( $exact && WorktreeContextInjector::LIVENESS_LIVE === ( $existing['liveness'] ?? null ) && empty($intent['owner_run_ref']) ) {
				$disposition = 'owner_conflict';
			}
			return $this->worktree_plan_result($input, $handle, $path, $slug, $disposition, array( 'destination' => $existing, 'ownership' => $stored_intent ));
		}

		$fetch = WorktreeStalenessProbe::fetch($primary_path);
		if ( ! $fetch['ok'] && ! $allow_unverified_freshness ) {
			return new \WP_Error('worktree_freshness_unverified', 'Refusing to plan worktree creation because remote freshness could not be verified.', array( 'status' => 409, 'fetch' => $fetch ));
		}
		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref = $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$target_head = $this->run_git($primary_path, 'rev-parse --verify ' . escapeshellarg($target_ref . '^{commit}'), self::CLEANUP_GIT_PROBE_TIMEOUT);
		$freshness = array( 'verified' => ! empty($fetch['ok']), 'fetch' => $fetch, 'target_ref' => $target_ref, 'target_head' => is_wp_error($target_head) ? null : trim((string) ($target_head['output'] ?? '')) );
		if ( ! $allow_stale && ! $rebase_base && ! empty($fetch['ok']) ) {
			$guard = $this->assert_ref_current_with_default_branch($primary_path, $target_ref, $repo, $branch, $exists_local ? 'branch' : 'base');
			if ( is_wp_error($guard) ) {
				return $this->worktree_plan_result($input, $handle, $path, $slug, 'stale', array( 'freshness' => $freshness, 'safety' => array( 'code' => $guard->get_error_code(), 'message' => $guard->get_error_message() ) ));
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
		$disk_budget = $this->inspect_worktree_capacity($repo, $branch, $force, $demand_plan);
		$candidates = $this->worktree_reuse_candidates($repo, $task);
		$disposition = 'create';
		if ( 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			$disposition = 'capacity_blocked';
		} elseif ( array() !== $candidates && 'isolated' !== $reuse_policy ) {
			$disposition = 'owner_conflict';
		} elseif ( array() !== $candidates && ( '' === trim((string) ($intent['purpose'] ?? '')) || '' === trim((string) ($intent['owner_run_ref'] ?? '')) || WorktreeContextInjector::CLEANUP_POLICY_REMOVE_ON_SUCCESS !== ( $intent['cleanup_policy'] ?? null ) ) ) {
			$disposition = 'unsafe';
		}
		return $this->worktree_plan_result($input, $handle, $path, $slug, $disposition, array(
			'freshness' => $freshness,
			'capacity' => $disk_budget,
			'bootstrap_demand' => $demand_plan,
			'reuse_candidates' => $candidates,
			'ownership' => $intent,
		));
	}

	/** Apply a previously reviewed plan only if the live replan is byte-for-byte identical. */
	public function worktree_apply_plan( array $plan ): array|\WP_Error {
		$expected = (string) ($plan['digest'] ?? '');
		$input = (array) ($plan['apply_intent'] ?? array());
		if ( '' === $expected || array() === $input ) {
			return new \WP_Error('invalid_worktree_plan', 'A digest-addressed worktree plan with apply_intent is required.', array( 'status' => 400 ));
		}
		$current = $this->worktree_plan((string) ($input['repo'] ?? ''), (string) ($input['branch'] ?? ''), $input['from'] ?? null, ! empty($input['inject_context']), ! empty($input['bootstrap']), ! empty($input['allow_stale']), ! empty($input['rebase_base']), ! empty($input['force']), (array) ($input['task'] ?? array()), ! empty($input['allow_unverified_freshness']), ! empty($input['require_task_tracker']), (array) ($input['intent'] ?? array()), (string) ($input['reuse_policy'] ?? 'reuse_compatible'));
		if ( is_wp_error($current) ) {
			return $current;
		}
		if ( ! hash_equals($expected, (string) ($current['digest'] ?? '')) || ! in_array($current['disposition'] ?? '', array( 'create', 'exact_reuse', 'adoptable' ), true) ) {
			return new \WP_Error('stale_worktree_plan', 'The worktree plan no longer matches live remote, capacity, ownership, or destination state.', array( 'status' => 409, 'expected_digest' => $expected, 'actual_digest' => $current['digest'] ?? null, 'disposition' => $current['disposition'] ?? null ));
		}
		return $this->worktree_add((string) $input['repo'], (string) $input['branch'], $input['from'] ?? null, ! empty($input['inject_context']), ! empty($input['bootstrap']), ! empty($input['allow_stale']), ! empty($input['rebase_base']), ! empty($input['force']), (array) ($input['task'] ?? array()), ! empty($input['allow_unverified_freshness']), ! empty($input['require_task_tracker']), (array) ($input['intent'] ?? array()), (string) ($input['reuse_policy'] ?? 'reuse_compatible'));
	}

	/** @return array<string,mixed> */
	private function worktree_plan_result( array $input, string $handle, string $path, string $slug, string $disposition, array $evidence ): array {
		$plan = array( 'version' => 1, 'handle' => $handle, 'path' => $path, 'branch' => $input['branch'], 'slug' => $slug, 'disposition' => $disposition, 'apply_intent' => $input ) + $evidence;
		$digest_plan = array(
			'version' => $plan['version'], 'handle' => $handle, 'path' => $path, 'branch' => $input['branch'], 'disposition' => $disposition, 'apply_intent' => $input,
			'freshness' => array( 'verified' => $plan['freshness']['verified'] ?? null, 'target_ref' => $plan['freshness']['target_ref'] ?? null, 'target_head' => $plan['freshness']['target_head'] ?? null ),
			'capacity' => array( 'status' => $plan['capacity']['status'] ?? null, 'projected_demand_bytes' => $plan['capacity']['projected_demand_bytes'] ?? null, 'projected_demand_inodes' => $plan['capacity']['projected_demand_inodes'] ?? null ),
			'destination' => $plan['destination'] ?? null, 'ownership' => $plan['ownership'] ?? null, 'reuse_candidates' => $plan['reuse_candidates'] ?? null,
		);
		$plan['digest'] = hash('sha256', wp_json_encode($this->worktree_plan_sort($digest_plan)) ?: '');
		$plan['apply'] = array( 'ability' => 'datamachine-code/workspace-worktree-apply-plan', 'intent' => array( 'digest' => $plan['digest'], 'apply_intent' => $input ) );
		return $plan;
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



	/**
	 * Create a git worktree for a branch.
	 *
	 * Layout: `<workspace>/<repo>@<branch-slug>` is added as a worktree of
	 * `<workspace>/<repo>` checked out to `<branch>`. If the branch does not
	 * exist locally, it is created from `<from>` (default `origin/HEAD`).
	 *
	 * When `$inject_context` is true (default) and Data Machine's agent memory
	 * layer is available, the originating site context is rendered into the
	 * runtime projections registered by installed integrations. Projected paths
	 * are added to the worktree's per-checkout `info/exclude`. When the memory
	 * layer is absent the worktree is still created successfully; injection
	 * silently skips.
	 *
	 * When `$bootstrap` is true (default), a bootstrap pass runs after the
	 * worktree is created: `git submodule update --init --recursive` if
	 * `.gitmodules` is present, package-manager installs for root or one-level
	 * nested dependency roots with lockfiles (pnpm/bun/yarn/npm; submodule roots
	 * are excluded unless `.datamachine/worktree-bootstrap.json` opts them in), and
	 * `composer install` for root or one-level nested dependency roots with
	 * `composer.lock`. Steps are independent and each one is skipped gracefully
	 * when its tool is unavailable. A failing step is surfaced in the result
	 * but does not roll back the worktree — the checkout exists either way.
	 * Pass `$bootstrap = false` (or `--no-bootstrap` on the CLI) for a bare
	 * checkout when you only need to read code on that branch.
	 *
	 * When remote freshness cannot be verified, worktree creation is refused
	 * unless `$allow_unverified_freshness` is set. This keeps default operation
	 * fail-closed while preserving intentional offline workflows.
	 *
	 * When the branch/base is behind the remote default branch, worktree
	 * creation is refused unless `$allow_stale` is set. This check is
	 * zero-tolerance: any default-branch commits missing from the requested
	 * branch/base mean the worktree would start stale.
	 *
	 * When the materialized branch (or its local base) is more than
	 * `datamachine_worktree_stale_threshold` commits behind upstream and
	 * neither `$allow_stale` nor `$rebase_base` is set, the worktree is
	 * torn down and the call returns a `worktree_stale` WP_Error with
	 * remediation guidance. Pass `$allow_stale = true` to proceed anyway,
	 * or `$rebase_base = true` to auto-rebase onto the upstream tip before
	 * returning. On rebase conflicts the rebase is aborted (worktree stays
	 * at its pre-rebase state) and `rebase_failed: true` is surfaced in
	 * the response so the agent can resolve manually.
	 *
	 * @param  string      $repo           Primary repo name (no @-suffix).
	 * @param  string      $branch         Branch to check out (e.g. "fix/foo-bar").
	 * @param  string|null $from           Base ref when creating the branch.
	 * @param  bool        $inject_context Whether to inject site-agent context (default true).
	 * @param  bool        $bootstrap      Whether to run submodule/package/composer install after creation (default true).
	 * @param  bool        $allow_stale    Bypass the staleness gate (default false).
	 * @param  bool        $rebase_base    Rebase onto upstream after creation (default false).
	 * @param  bool        $force          Bypass the disk-budget refusal threshold (default false).
	 * @param  array       $task           Optional task metadata recorded on the worktree.
	 * @param  bool        $allow_unverified_freshness Bypass fetch-failure freshness verification (default false).
	 * @param  bool        $require_task_tracker Reject creation without task metadata (default false).
	 * @param  string      $reuse_policy Existing-handle and same-task allocation policy.
	 * @return array{success: bool, handle: string, path: string, branch: string, slug: string, created_branch: bool, message: string, disk_budget?: array, context_injected?: bool, context_files?: string[], context_skip_reason?: string, bootstrap?: array, fetch_failed?: bool, fetch_error?: string, fetch_attempts?: int, stale_commits_behind?: int, upstream?: string, base_stale_commits_behind?: int, base_upstream?: string, default_branch_commits_behind?: int, default_branch_ref?: string, gate_threshold?: int, rebase_attempted?: bool, rebase_succeeded?: bool, rebase_error?: string, rebase_target?: string}|\WP_Error
	 */
	public function worktree_add( string $repo, string $branch, ?string $from = null, bool $inject_context = true, bool $bootstrap = true, bool $allow_stale = false, bool $rebase_base = false, bool $force = false, array $task = array(), bool $allow_unverified_freshness = false, bool $require_task_tracker = false, array $intent = array(), string $reuse_policy = 'reuse_compatible', bool $remediate_capacity = false, bool $remediate_capacity_dry_run = false ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
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

		$task = WorktreeContextInjector::resolve_task_metadata($task) ?? array();
		$reuse_policy = strtolower(trim($reuse_policy));
		if ( ! in_array($reuse_policy, array( 'reuse_compatible', 'isolated', 'recycle_terminal' ), true) ) {
			return new \WP_Error('invalid_worktree_reuse_policy', 'reuse_policy must be one of: reuse_compatible, isolated, recycle_terminal.', array( 'status' => 400 ));
		}
		if ( $force && $remediate_capacity ) {
			return new \WP_Error('worktree_capacity_policy_conflict', '--force bypasses capacity admission; use it separately from --remediate-capacity.', array( 'status' => 400 ));
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
			return $this->worktree_capacity_dry_run($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent, $reuse_policy, $wt_handle, $primary_path);
		}

		// A remediation dry-run must reach capacity planning before any existing
		// handle path can reset a terminal checkout or rewrite its metadata.
		if ( is_dir($wt_path) && ! $remediate_capacity_dry_run ) {
			if ( 'recycle_terminal' === $reuse_policy ) {
				return WorkspaceMutationLock::with_repo($this->workspace_path, $repo, fn() => $this->recycle_terminal_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $primary_path));
			}
			return WorkspaceMutationLock::with_repo($this->workspace_path, $repo, fn() => $this->reuse_existing_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $reuse_policy, $primary_path));
		}

		$operation_timeout  = self::worktree_capacity_operation_timeout_seconds($bootstrap);
		$operation_started  = microtime(true);
		$operation_deadline = $operation_started + $operation_timeout;
		$capacity_timeout   = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $capacity_timeout <= 0 ) {
			return $this->worktree_operation_timeout('capacity_lock_wait', $operation_timeout, $operation_started);
		}

		// Fetch and demand planning only touch this primary. Keep them out of the
		// global capacity critical section so unrelated repositories can prepare in
		// parallel; capacity-changing checkout and bootstrap remain globally fenced.
		$preflight = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			$repo,
			fn() => $this->worktree_capacity_preflight($primary_path, $repo, $branch, $from, $bootstrap, $operation_deadline),
			$capacity_timeout
		);
		$preflight = $this->worktree_operation_lock_result($preflight, 'repo_preflight_lock_wait', $operation_timeout, $operation_started);
		if ( is_wp_error($preflight) ) {
			return $preflight;
		}

		$locked = WorkspaceMutationLock::with_repo(
			$this->workspace_path,
			'workspace-capacity-admission',
			fn() => $this->worktree_add_with_capacity_lock(
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
				$preflight
			),
			$capacity_timeout
		);

		return $this->worktree_operation_lock_result($locked, 'capacity_lock_wait', $operation_timeout, $operation_started);
	}

	/** Prepare repo-local freshness and projected demand before global admission. */
	private function worktree_capacity_preflight( string $primary_path, string $repo, string $branch, ?string $from, bool $bootstrap, float $operation_deadline ): array|\WP_Error {
		$fetch = WorktreeStalenessProbe::fetch($primary_path, null, $operation_deadline);
		if ( ! $fetch['ok'] ) {
			return array( 'fetch' => $fetch );
		}

		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$demand_plan  = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
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
	private function worktree_capacity_dry_run( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force, array $task, array $intent, string $reuse_policy, string $wt_handle, string $primary_path ): array|\WP_Error {
		$exists_local = GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = $exists_local
			? 'refs/heads/' . $branch
			: ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) );
		$demand_plan  = WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
		if ( $demand_plan instanceof \WP_Error ) {
			return $demand_plan;
		}

		$disk_budget          = $this->inspect_worktree_capacity($repo, $branch, $force, $demand_plan);
		$capacity_remediation = 'refused' === ( $disk_budget['status'] ?? '' )
			? $this->remediate_capacity_refusal($repo, $branch, $demand_plan, $disk_budget, true)
			: null;
		if ( isset($capacity_remediation['failure']) ) {
			$failure = (array) $capacity_remediation['failure'];
			return new \WP_Error('worktree_capacity_remediation_failed', (string) ( $failure['message'] ?? 'Bounded capacity remediation preview failed.' ), array( 'status' => 507, 'failure' => $failure, 'capacity_remediation' => $capacity_remediation ));
		}

		return array(
			'success'              => true,
			'dry_run'              => true,
			'created'              => false,
			'handle'               => $wt_handle,
			'branch'               => $branch,
			'disk_budget'          => $disk_budget,
			'capacity_reclaim'     => array( 'attempted' => false, 'skip_reason' => 'remediation_dry_run' ),
			'capacity_remediation' => $capacity_remediation ?? array( 'mode' => 'not_required', 'dry_run' => true, 'before' => $disk_budget, 'after' => $disk_budget ),
			'add_intent'           => $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy),
		);
	}

	/**
	 * Resolve the explicit global-capacity lock wait budget.
	 *
	 * Lock order is always global capacity first, then the repository lock. The
	 * global lock remains held through bounded bootstrap so concurrent admissions
	 * cannot inspect stale capacity. Its default wait exceeds the default bounded
	 * dependency command timeout and can be tuned for installations with many
	 * bootstrap roots.
	 */
	public static function worktree_capacity_wait_timeout_seconds( bool $bootstrap = true ): int {
		$timeout = self::worktree_capacity_operation_timeout_seconds($bootstrap) + 60;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_capacity_wait_timeout_seconds', $timeout, $bootstrap);
		}

		return max(1, $timeout);
	}

	/** Resolve the aggregate deadline covering create, rebase, and bootstrap. */
	public static function worktree_capacity_operation_timeout_seconds( bool $bootstrap = true ): int {
		$timeout = $bootstrap ? WorktreeBootstrapper::total_timeout_seconds() + 540 : 540;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_capacity_operation_timeout_seconds', $timeout, $bootstrap);
		}
		return max(1, $timeout);
	}

	/**
	 * Inspect, create, and bootstrap while holding the workspace-wide capacity
	 * lock. A later concurrent admission therefore measures the first one's
	 * completed dependency allocation rather than overcommitting stale capacity.
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
		array $preflight = array()
	): array|\WP_Error {
		$operation_timeout  = $operation_timeout > 0 ? $operation_timeout : self::worktree_capacity_operation_timeout_seconds($bootstrap);
		$operation_started  = $operation_started > 0.0 ? $operation_started : microtime(true);
		$operation_deadline = $operation_deadline ?? ( $operation_started + $operation_timeout );
		$deadline_error     = $this->worktree_operation_deadline_error('freshness', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		if ( is_dir($wt_path) && ! $remediate_capacity_dry_run ) {
			return $this->reuse_existing_worktree($wt_handle, $branch, $from, $inject_context, $bootstrap, $task, $intent, $reuse_policy, $primary_path);
		}
		// The workspace capacity lock serializes admission. The target repo lock is
		// acquired only for final creation, so remediation can safely take per-repo
		// cleanup locks without self-deadlocking.
		$reuse_candidates = $this->worktree_reuse_candidates($repo, $task);
		if ( array() !== $reuse_candidates && 'isolated' !== $reuse_policy ) {
			return $this->worktree_reuse_refused(
				$wt_handle,
				'same_task_candidate_requires_explicit_isolation',
				array(
					'reuse_policy'            => $reuse_policy,
					'canonical_task_identity' => $this->worktree_reuse_task_identity($task),
					'candidates'              => $reuse_candidates,
				)
			);
		}
		if ( array() !== $reuse_candidates && 'isolated' === $reuse_policy ) {
			$missing_intent = array();
			foreach ( array( 'purpose', 'owner_run_ref' ) as $field ) {
				if ( '' === trim((string) ($intent[ $field ] ?? '')) ) {
					$missing_intent[] = $field;
				}
			}
			if ( WorktreeContextInjector::CLEANUP_POLICY_REMOVE_ON_SUCCESS !== ( $intent['cleanup_policy'] ?? null ) ) {
				$missing_intent[] = 'cleanup_policy=remove_on_success';
			}
			if ( array() !== $missing_intent ) {
				return $this->worktree_reuse_refused(
					$wt_handle,
					'same_task_isolation_intent_required',
					array(
						'reuse_policy'            => $reuse_policy,
						'canonical_task_identity' => $this->worktree_reuse_task_identity($task),
						'missing_intent'          => $missing_intent,
						'candidates'              => $reuse_candidates,
					)
				);
			}
		}

		$fetch                 = (array) ( $preflight['fetch'] ?? WorktreeStalenessProbe::fetch($primary_path, null, $operation_deadline) );
		$fetch_failed          = ! $fetch['ok'];
		$fetch_error           = $fetch['error'] ?? null;
		$fetch_attempts        = (int) ( $fetch['attempts'] ?? 1 );
		$fetch_timed_out       = ! empty($fetch['timed_out']);
		$fetch_timeout_seconds = $fetch['timeout_seconds'] ?? null;
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
					'allow_unverified_freshness' => false,
					'next_commands'              => array(
						$this->primary_refresh_command($repo),
						$this->worktree_freshness_retry_command($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent),
					),
				)
			);
		}

		$exists_local = array_key_exists('exists_local', $preflight) ? (bool) $preflight['exists_local'] : GitRunner::ref_exists($primary_path, 'refs/heads/' . $branch);
		$target_ref   = (string) ( $preflight['target_ref'] ?? ( $exists_local ? 'refs/heads/' . $branch : ( $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path) ) ) );
		$demand_plan  = $preflight['demand_plan'] ?? WorktreeBootstrapper::demand_plan_for_target($primary_path, $target_ref, $bootstrap);
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
		$deadline_error = $this->worktree_operation_deadline_error('demand_disk_planning', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		$disk_budget      = $this->inspect_worktree_capacity($repo, $branch, $force, $demand_plan);
		$capacity_reclaim = ( $remediate_capacity || $remediate_capacity_dry_run )
			? array( 'after' => $disk_budget, 'evidence' => array( 'attempted' => false, 'skip_reason' => $remediate_capacity_dry_run ? 'remediation_dry_run' : 'remediation_preview_then_apply' ) )
			: $this->reclaim_capacity_eligible_artifacts($repo, $branch, $force, $demand_plan, $disk_budget);
		$deadline_error   = $this->worktree_operation_deadline_error('demand_disk_planning', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			return $deadline_error;
		}
		$disk_budget      = $capacity_reclaim['after'];
		$capacity_remediation = null;
		if ( $remediate_capacity && 'refused' === ( $disk_budget['status'] ?? '' ) ) {
			$capacity_remediation = $this->remediate_capacity_refusal($repo, $branch, $demand_plan, $disk_budget, $remediate_capacity_dry_run);
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
				'handle'               => $wt_handle,
				'branch'               => $branch,
				'disk_budget'          => $disk_budget,
				'capacity_reclaim'     => $capacity_reclaim['evidence'],
				'capacity_remediation' => $capacity_remediation ?? array( 'mode' => 'not_required', 'dry_run' => true, 'before' => $disk_budget, 'after' => $disk_budget ),
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
				$recommendations = array(
					sprintf(
						'1. Automatic safe artifact recovery found 0 actionable rows (0 B); gross inspected candidate bytes were %s. The capacity recovery target is not a reclaim forecast.',
						WorktreeDiskBudget::format_bytes_for_operator( (int) ( $reclaim_evidence['gross_candidate_bytes'] ?? 0 ))
					),
					sprintf(
						'2. If a human accepts this one worktree\'s projected demand of %s, retry only this request with --force: %s',
						WorktreeDiskBudget::format_bytes_for_operator( (int) ( $disk_budget['projected_demand_bytes'] ?? 0 )),
						$this->worktree_freshness_retry_command($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, true, $task, $intent)
					),
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
					'status'           => 507,
					'disk_budget'      => $disk_budget,
					'capacity_reclaim' => $capacity_reclaim['evidence'],
					'capacity_remediation' => $capacity_remediation,
					'add_intent'       => $this->capacity_add_intent($repo, $branch, $from, $inject_context, $bootstrap, $allow_stale, $rebase_base, $task, $intent, $reuse_policy),
				)
			);
		}

		$repo_timeout = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $repo_timeout <= 0 ) {
			return $this->worktree_operation_timeout('repo_lock_wait', $operation_timeout, $operation_started);
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
					'exists_local'          => $exists_local,
					'target_ref'            => $target_ref,
					'operation_deadline'    => $operation_deadline,
					'operation_timeout'     => $operation_timeout,
					'operation_started'     => $operation_started,
			)), $repo_timeout);
		$response = $this->worktree_operation_lock_result($response, 'repo_lock_wait', $operation_timeout, $operation_started);

		if ( is_wp_error($response) ) {
			return $response;
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
		$measurement_plan             = $demand_plan;
		if ( is_array($capacity_remediation) ) {
			$response['capacity_remediation'] = $capacity_remediation;
			$response['capacity_retry']       = array( 'disposition' => 'retried_once_admitted', 'attempts' => 1 );
		}
		if ( array() !== $reuse_candidates ) {
			$response['reuse_candidates'] = $reuse_candidates;
		}
		if ( ! empty($response['rebase_succeeded']) ) {
			$post_rebase_demand = WorktreeBootstrapper::demand_plan_for_target($wt_path, 'HEAD', $bootstrap);
			if ( $post_rebase_demand instanceof \WP_Error ) {
				$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
				return $post_rebase_demand;
			}
			$post_rebase_demand                       = WorktreeDemandCalibration::forecast($repo, $post_rebase_demand);
			$measurement_plan                         = $post_rebase_demand;
			$post_rebase_demand                       = WorktreeBootstrapper::remaining_demand_after_materialization($post_rebase_demand);
			$post_rebase_budget                       = $this->inspect_worktree_capacity($repo, $branch, $force, $post_rebase_demand);
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
						'status'           => 507,
						'disk_budget'      => $post_rebase_budget,
						'capacity_reclaim'  => $post_rebase_capacity_reclaim['evidence'],
						'capacity_evidence' => $rollback_evidence,
						'phase'             => 'post_rebase_admission',
					)
				);
			}
		}

		if ( $bootstrap ) {
			$bootstrap_before_capacity = $this->inspect_worktree_capacity($repo, $branch, false, array());
			$remaining_seconds = $this->worktree_operation_remaining_seconds($operation_deadline);
			if ( $remaining_seconds <= 0 ) {
				$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
				return $this->worktree_operation_timeout('bootstrap', $operation_timeout, $operation_started, array( 'cleanup' => 'rollback_requested' ));
			}
			$response['bootstrap'] = WorktreeBootstrapper::bootstrap($wt_path, $remaining_seconds);
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
			$after_capacity = $this->inspect_worktree_capacity($repo, $branch, false, array());
			$response['capacity_evidence'] = WorktreeDemandCalibration::record_bootstrap($repo, $measurement_plan, $bootstrap_before_capacity, $after_capacity, ! empty($response['bootstrap']['success']));
		} else {
			$response['capacity_evidence'] = array( 'outcome' => 'bootstrap_disabled', 'recorded' => false, 'reason' => 'bootstrap_disabled' );
		}

		$deadline_error = $this->worktree_operation_deadline_error('metadata', $operation_deadline, $operation_timeout, $operation_started);
		if ( null !== $deadline_error ) {
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
			WorktreeContextInjector::forget_metadata($wt_handle);
			return $deadline_error;
		}
		$inventory = $this->worktree_inventory();
		$persisted = $inventory->upsert($this->build_worktree_inventory_row_from_handle($wt_handle));
		if ( ! $persisted ) {
			$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, ! empty($response['created_branch']));
			WorktreeContextInjector::forget_metadata($wt_handle);

			$inventory_error = $inventory->last_error();
			if ( $inventory_error instanceof \WP_Error ) {
				return $inventory_error;
			}

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

	/** Build a safe, task-preserving retry command after freshness verification fails. */
	private function worktree_freshness_retry_command( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force, array $task, array $intent ): string {
		$parts = array(
			'wp datamachine-code workspace worktree add',
			escapeshellarg($repo),
			escapeshellarg($branch),
		);
		if ( null !== $from && '' !== trim($from) ) {
			$parts[] = '--from=' . escapeshellarg(trim($from));
		}
		if ( ! $inject_context ) {
			$parts[] = '--skip-context-injection';
		}
		if ( ! $bootstrap ) {
			$parts[] = '--skip-bootstrap';
		}
		if ( $allow_stale ) {
			$parts[] = '--allow-stale';
		}
		if ( $rebase_base ) {
			$parts[] = '--rebase-base';
		}
		if ( $force ) {
			$parts[] = '--force';
		}
		foreach ( array(
			'task_url' => 'task-url',
			'task_ref' => 'task-ref',
		) as $key => $flag ) {
			if ( ! empty($task[ $key ]) ) {
				$parts[] = '--' . $flag . '=' . escapeshellarg( (string) $task[ $key ]);
			}
		}
		if ( ! empty($task) ) {
			$parts[] = '--require-task-tracker';
		}
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

	/**
	 * Add default-base evidence to an invalid explicit-ref error without replacing it.
	 *
	 * Fetch failures return before ref resolution, so this path means freshness was
	 * verified and only the requested ref could not be resolved locally.
	 */
	private function worktree_missing_explicit_base_error( \WP_Error $error, string $primary_path, string $repo, string $branch, string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force, array $task, array $intent ): \WP_Error {
		$default = $this->detect_workspace_default_base($primary_path);
		$data    = (array) $error->get_error_data();
		$data['requested_ref']        = trim($from);
		$data['detected_default_ref'] = $default['ref'];
		$data['default_ref_source']   = $default['source'];
		$data['next_commands']        = null === $default['ref']
			? array()
			: array( $this->worktree_freshness_retry_command($repo, $branch, $default['ref'], $inject_context, $bootstrap, $allow_stale, $rebase_base, $force, $task, $intent) );

		$message = $error->get_error_message();
		if ( null !== $default['ref'] ) {
			$message .= sprintf(' The configured default ref is "%s". Retry with: %s', $default['ref'], $data['next_commands'][0]);
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
		array $preflight = array()
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
			trim((string) ($intent_base_head['output'] ?? '')),
			$task,
			$inject_context,
			$bootstrap,
			$intent
		);
		$intent_stored = WorktreeContextInjector::store_creation_intent($wt_handle, $creation_intent);
		if ( is_wp_error($intent_stored) || ! $intent_stored ) {
			return is_wp_error($intent_stored)
				? $intent_stored
				: new \WP_Error('worktree_creation_intent_conflict', sprintf('Refusing to create worktree "%s" because a creation intent already exists.', $wt_handle), array( 'status' => 409 ));
		}

		$operation_deadline = (float) ( $preflight['operation_deadline'] ?? 0.0 );
		$operation_timeout  = (int) ( $preflight['operation_timeout'] ?? 0 );
		$operation_started  = (float) ( $preflight['operation_started'] ?? 0.0 );
		$add_remaining      = $this->worktree_operation_remaining_seconds($operation_deadline);
		if ( $add_remaining <= 0 ) {
			WorktreeContextInjector::forget_creation_intent($wt_handle, $creation_intent);
			return $this->worktree_operation_timeout('git_worktree_add', $operation_timeout, $operation_started, array( 'cleanup' => 'no_checkout_created' ));
		}
		$result        = $this->run_git($primary_path, $cmd, min(300, $add_remaining));
		if ( is_wp_error($result) ) {
			if ( $this->worktree_operation_remaining_seconds($operation_deadline) <= 0 ) {
				$this->rollback_rejected_worktree($primary_path, $wt_path, $branch, $created_branch, $wt_handle, $creation_intent);
				return $this->worktree_operation_timeout('git_worktree_add', $operation_timeout, $operation_started, array( 'cleanup' => 'rollback_requested' ));
			}
			WorktreeContextInjector::forget_creation_intent($wt_handle, $creation_intent);
			return $result;
		}
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
			'slug'           => $slug,
			'created_branch' => $created_branch,
			'message'        => sprintf('Worktree "%s" added at %s (branch %s).', $wt_handle, $wt_path, $branch),
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
			if ( ! $created_branch ) {
				// Existing local branch: compare against its configured upstream.
				$behind = WorktreeStalenessProbe::behind_count($wt_path, $branch, '@{upstream}');
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
				$behind        = WorktreeStalenessProbe::behind_count($primary_path, $resolved_base, $base_upstream);
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
			$rebase_result = $this->try_rebase_worktree($wt_path, $response, $created_branch, (float) ( $preflight['operation_deadline'] ?? 0.0 ));
			if ( null !== $rebase_result ) {
				$response = array_merge($response, $rebase_result);
			}
		}

		if ( ! $fetch_failed ) {
			$this->populate_default_branch_behind_count($primary_path, $branch, $response);
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
		$metadata_stored                      = WorktreeContextInjector::promote_creation_intent( $wt_handle, $creation_intent, $lifecycle_metadata );
		if ( is_wp_error( $metadata_stored ) ) {
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
					$metadata_stored = WorktreeContextInjector::store_metadata($wt_handle, $payload);
					if ( is_wp_error($metadata_stored) ) {
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
		$evidence = $this->worktree_reuse_evidence($handle, $existing, $existing['metadata'] ?? null);
		if ( ( $existing['branch'] ?? null ) !== $branch ) {
			return $this->worktree_reuse_refused($handle, 'branch_mismatch', $evidence + array( 'requested_branch' => $branch ));
		}
		if ( (int) ( $existing['dirty'] ?? 0 ) > 0 ) {
			return $this->worktree_reuse_refused($handle, 'dirty_worktree', $evidence);
		}
		if ( (int) ( $existing['unpushed'] ?? 0 ) > 0 ) {
			return $this->worktree_reuse_refused($handle, 'unpushed_commits', $evidence);
		}
		$metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : array();
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

		return array(
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

		$base = null !== $from && '' !== trim($from) ? trim($from) : $this->resolve_default_base($primary_path);
		if ( '' === $base ) {
			return $this->worktree_reuse_refused($handle, 'base_unresolved', $evidence);
		}
		$base_head = $this->run_git($primary_path, 'rev-parse --verify ' . escapeshellarg($base . '^{commit}'), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($base_head) ) {
			return $this->worktree_reuse_refused($handle, 'base_unresolved', $evidence + array( 'requested_base_ref' => $base ));
		}
		$base_head = trim((string) ($base_head['output'] ?? ''));
		$requested_intent = $this->worktree_creation_intent(explode('@', $handle, 2)[0], $branch, $base, $base_head, $task, $inject_context, $bootstrap, $intent);
		if ( null === $creation_intent ) {
			return $this->worktree_reuse_refused($handle, 'interrupted_recovery_intent_missing', $evidence + array( 'requested_creation_intent' => $requested_intent ));
		}
		if ( $creation_intent !== $requested_intent ) {
			return $this->worktree_reuse_refused($handle, 'interrupted_recovery_intent_mismatch', $evidence + array( 'requested_creation_intent' => $requested_intent ));
		}
		$ancestry = $this->run_git((string) $existing['path'], 'merge-base --is-ancestor HEAD ' . escapeshellarg($base_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($ancestry) || $base_head !== (string) ( $existing['head'] ?? '' ) ) {
			return $this->worktree_reuse_refused($handle, 'interrupted_recovery_head_mismatch', $evidence + array( 'requested_base_ref' => $base, 'requested_base_head' => $base_head ));
		}

		$metadata = WorktreeContextInjector::build_lifecycle_metadata(array(
			'handle' => $handle, 'path' => $existing['path'], 'repo' => explode('@', $handle, 2)[0], 'branch' => $branch, 'base_ref' => $base, 'base_source' => 'requested_ref',
			'task_url' => (string) ( $task['task_url'] ?? '' ), 'task_ref' => (string) ( $task['task_ref'] ?? '' ), 'purpose' => $intent['purpose'] ?? null, 'owner_run_ref' => $intent['owner_run_ref'] ?? null, 'cleanup_policy' => $intent['cleanup_policy'] ?? null,
		));
		$metadata['reuse_contract'] = array( 'branch' => $branch, 'base_ref' => $base, 'inject_context' => $inject_context, 'bootstrap' => $bootstrap, 'purpose' => $intent['purpose'] ?? null, 'owner_run_ref' => $intent['owner_run_ref'] ?? null, 'cleanup_policy' => $intent['cleanup_policy'] ?? null );
		$stored = WorktreeContextInjector::promote_creation_intent($handle, $creation_intent, $metadata);
		if ( is_wp_error($stored) ) {
			return $stored;
		}
		$this->emit_workspace_changed('worktree_adopt_interrupted', explode('@', $handle, 2)[0], $handle, (string) $existing['path']);

		return array( 'success' => true, 'handle' => $handle, 'path' => $existing['path'], 'branch' => $branch, 'slug' => $this->slugify_branch($branch), 'created_branch' => false, 'adopted' => true, 'recovery' => array( 'status' => 'adopted', 'reason_code' => 'interrupted_exact_handle', 'requested_base_ref' => $base, 'requested_base_head' => $base_head, 'task_identity' => $this->worktree_reuse_task_identity($task) ), 'metadata' => WorktreeContextInjector::get_metadata($handle), 'message' => sprintf('Adopted interrupted worktree "%s" at %s after exact journal, branch, base, HEAD, and task verification.', $handle, $existing['path']) );
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
		$listing = $this->worktree_list($repo, null, array( 'include_status' => true, 'include_disk' => false ));
		if ( is_wp_error($listing) ) {
			return array();
		}
		$candidates = array();
		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $row ) {
			if ( ! empty($row['is_primary']) || $task_identity !== $this->worktree_reuse_task_identity((array) ($row['task'] ?? array())) ) {
				continue;
			}
			$candidates[] = array(
				'handle'   => $row['handle'] ?? null,
				'path'     => $row['path'] ?? null,
				'branch'   => $row['branch'] ?? null,
				'head'     => $row['head'] ?? null,
				'dirty'    => $row['dirty'] ?? null,
				'unpushed' => $row['unpushed'] ?? null,
				'liveness' => $row['liveness'] ?? null,
				'task'     => $row['task'] ?? null,
			);
		}
		usort($candidates, static fn( array $left, array $right ): int => strcmp((string) $left['handle'], (string) $right['handle']));
		return $candidates;
	}

	/** Reset an exact terminal handle only after proving its pushed HEAD is in the requested base. */
	private function recycle_terminal_worktree( string $handle, string $branch, ?string $from, bool $inject_context, bool $bootstrap, array $task, array $intent, string $primary_path ): array|\WP_Error {
		$inspection = $this->worktree_get($handle, array( 'include_status' => true, 'include_disk' => false ));
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
		foreach ( array( 'dirty' => 'dirty_worktree', 'unpushed' => 'unpushed_commits' ) as $field => $reason ) {
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
		$base = null !== $from && '' !== trim($from) ? trim($from) : ( $contract['base_ref'] ?? null );
		if ( ! is_string($base) || '' === $base || 'existing_local_branch' === $base || $base !== ( $contract['base_ref'] ?? null ) ) {
			return $this->worktree_reuse_refused($handle, 'base_mismatch', $evidence + array( 'requested_base_ref' => $base, 'stored_base_ref' => $contract['base_ref'] ?? null ));
		}
		if ( $inject_context !== (bool) ( $contract['inject_context'] ?? null ) || $bootstrap !== (bool) ( $contract['bootstrap'] ?? null ) ) {
			return $this->worktree_reuse_refused($handle, 'runtime_incompatible', $evidence);
		}
		$target = $this->run_git($primary_path, 'rev-parse --verify ' . escapeshellarg($base . '^{commit}'), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($target) ) {
			return $this->worktree_reuse_refused($handle, 'base_unresolved', $evidence + array( 'requested_base_ref' => $base ));
		}
		$target_head = trim((string) ($target['output'] ?? ''));
		$contained = $this->run_git((string) $existing['path'], 'merge-base --is-ancestor HEAD ' . escapeshellarg($target_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($contained) ) {
			return $this->worktree_reuse_refused($handle, 'terminal_head_not_in_base', $evidence + array( 'requested_base_ref' => $base, 'requested_base_head' => $target_head ));
		}
		$path = (string) $existing['path'];
		$previous_head = (string) ( $existing['head'] ?? '' );
		$reset = $this->run_git($path, 'reset --hard ' . escapeshellarg($target_head), self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( is_wp_error($reset) ) {
			return $reset;
		}
		$lineage = array( 'recycled_at' => gmdate('c'), 'previous_head' => $existing['head'] ?? null, 'new_head' => $target_head, 'previous_branch' => $existing['branch'] ?? null, 'new_branch' => $branch, 'previous_task' => $existing['task'] ?? null, 'new_task' => $task, 'base_ref' => $base );
		$metadata = array_merge($metadata, array( 'lifecycle_state' => WorktreeContextInjector::STATE_ACTIVE, 'last_seen_at' => gmdate('c'), 'observed_at' => gmdate('c'), 'origin_task' => $task, 'purpose' => $intent['purpose'] ?? null, 'owner_run_ref' => $intent['owner_run_ref'] ?? null, 'cleanup_policy' => $intent['cleanup_policy'] ?? null, 'recycle_lineage' => array_merge((array) ($metadata['recycle_lineage'] ?? array()), array( $lineage )) ));
		$metadata_preflight = function_exists('apply_filters') ? apply_filters('datamachine_code_worktree_recycle_metadata_preflight', null, $metadata, $handle) : null;
		if ( $metadata_preflight instanceof \WP_Error ) {
			return $this->worktree_recycle_rollback_error($handle, $path, $previous_head, $existing['metadata'] ?? array(), 'metadata_persistence', $metadata_preflight);
		}
		$stored = WorktreeContextInjector::store_lifecycle_metadata($handle, $metadata);
		if ( is_wp_error($stored) ) {
			return $this->worktree_recycle_rollback_error($handle, $path, $previous_head, $existing['metadata'] ?? array(), 'metadata_persistence', $stored);
		}
		return array( 'success' => true, 'handle' => $handle, 'path' => $existing['path'], 'branch' => $branch, 'slug' => $this->slugify_branch($branch), 'created_branch' => false, 'recycled' => true, 'recycle' => array( 'status' => 'accepted', 'reason_code' => 'terminal_exact_handle', 'lineage' => $lineage, 'context' => 'preserved', 'bootstrap' => 'preserved' ), 'metadata' => WorktreeContextInjector::get_metadata($handle), 'message' => sprintf('Recycled terminal worktree "%s" at %s; compatible context and bootstrap assets were preserved.', $handle, $existing['path']) );
	}

	/** Build the shared evidence snapshot for worktree reuse decisions. */
	private function worktree_reuse_evidence( string $handle, array $existing, mixed $metadata ): array {
		return array(
			'handle'   => $handle,
			'branch'   => $existing['branch'] ?? null,
			'head'     => $existing['head'] ?? null,
			'dirty'    => $existing['dirty'] ?? null,
			'unpushed' => $existing['unpushed'] ?? null,
			'liveness' => $existing['liveness'] ?? null,
			'task'     => $existing['task'] ?? null,
			'metadata' => $metadata,
		);
	}

	/** Restore the old checkout and lifecycle record after a post-reset recycle failure. */
	private function worktree_recycle_rollback_error( string $handle, string $path, string $previous_head, array $previous_metadata, string $phase, \WP_Error $cause ): \WP_Error {
		$head_rollback = '' !== $previous_head ? $this->run_git($path, 'reset --hard ' . escapeshellarg($previous_head), self::CLEANUP_GIT_PROBE_TIMEOUT) : new \WP_Error('previous_head_missing', 'Previous HEAD was unavailable for rollback.');
		$metadata_rollback = WorktreeContextInjector::restore_lifecycle_metadata($handle, $previous_metadata);
		$rollback = array(
			'head_restored'     => ! is_wp_error($head_rollback),
			'metadata_restored' => ! is_wp_error($metadata_rollback),
		);
		return new \WP_Error(
			'worktree_recycle_' . $phase . '_failed',
			sprintf('Terminal recycle %s failed; rollback %s.', str_replace('_', ' ', $phase), in_array(false, $rollback, true) ? 'was incomplete' : 'restored the prior state'),
			array( 'status' => 409, 'phase' => $phase, 'cause_code' => $cause->get_error_code(), 'cause_data' => $cause->get_error_data(), 'recycle' => array( 'status' => 'failed', 'reason_code' => $phase, 'rollback' => $rollback ) )
		);
	}

	/** @return \WP_Error Typed evidence for a non-reusable exact handle. */
	private function worktree_reuse_refused( string $handle, string $reason_code, array $evidence ): \WP_Error {
		return new \WP_Error(
			'worktree_reuse_refused',
			sprintf('Refusing to reuse worktree "%s": %s.', $handle, str_replace('_', ' ', $reason_code)),
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
		return (string) ( $task['task_url'] ?? $task['task_ref'] ?? '' );
	}

	/**
	 * Attach lifecycle finalizer metadata to a worktree record.
	 *
	 * @param  string      $handle Workspace worktree handle.
	 * @param  string      $state  Lifecycle state.
	 * @param  string|null $pr     Optional PR URL or number.
	 * @return array{success: bool, handle: string, path: string, lifecycle_state: string, metadata: array, message: string}|\WP_Error
	 */
	public function worktree_finalize( string $handle, string $state, ?string $pr = null, ?string $owner_terminal_outcome = null ): array|\WP_Error {
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

		$existing_metadata = WorktreeContextInjector::get_metadata($parsed['dir_name']) ?? array();
		$metadata          = WorktreeContextInjector::build_finalizer_metadata($normalized_state, $pr, $owner_terminal_outcome, $existing_metadata);
		$metadata          = array_merge(
			array(
				'handle' => $parsed['dir_name'],
				'path'   => $wt_path,
				'repo'   => $parsed['repo'],
			),
			$metadata
		);
		if ( WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			$dirty_paths = $this->probe_worktree_dirty_paths($wt_path, self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( $dirty_paths instanceof \WP_Error ) {
				return $this->worktree_finalize_phase_error('dirty_probe', $parsed['dir_name'], $wt_path, $dirty_paths);
			}
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
		}
		$metadata_persisted = WorktreeContextInjector::store_lifecycle_metadata($parsed['dir_name'], $metadata);
		if ( $metadata_persisted instanceof \WP_Error ) {
			$stored    = WorktreeContextInjector::get_metadata($parsed['dir_name']) ?? array();
			$committed = $this->worktree_metadata_contains($stored, $metadata);
			$phase     = $committed && 'worktree_inventory_persist_failed' === $metadata_persisted->get_error_code() ? 'inventory_upsert' : 'lifecycle_metadata_persistence';
			return $this->worktree_finalize_phase_error(
				$phase,
				$parsed['dir_name'],
				$wt_path,
				$metadata_persisted,
				$committed
			);
		}

		$stored = WorktreeContextInjector::get_metadata($parsed['dir_name']) ?? array();
		if ( ! $this->worktree_metadata_contains($stored, $metadata) ) {
			return new \WP_Error(
				'worktree_finalize_metadata_readback_failed',
				'Lifecycle metadata could not be read back after finalization. Retry finalization; no cleanup should proceed until the lifecycle state is visible.',
				array(
					'status'                       => 500,
					'phase'                        => 'lifecycle_metadata_readback',
					'handle'                       => $parsed['dir_name'],
					'path'                         => $wt_path,
					'lifecycle_metadata_committed' => false,
				)
			);
		}
		return array(
			'success'         => true,
			'handle'          => $parsed['dir_name'],
			'path'            => $wt_path,
			'lifecycle_state' => (string) ( $stored['lifecycle_state'] ?? $normalized_state ),
			'metadata'        => $stored,
			'message'         => sprintf('Worktree "%s" marked %s.', $parsed['dir_name'], (string) ( $stored['lifecycle_state'] ?? $normalized_state )),
		);
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
		$skipped_groups = array();
		if ( ! $include_status ) {
			$skipped_groups[] = 'status';
		}
		if ( ! $include_disk ) {
			$skipped_groups[] = 'disk';
		}

		$head = $this->run_git($path, 'rev-parse --verify HEAD', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $head instanceof \WP_Error ) {
			return $this->worktree_get_probe_error('identity', $parsed['dir_name'], $path, $head);
		}
		$branch = $this->run_git($path, 'branch --show-current', self::CLEANUP_GIT_PROBE_TIMEOUT);
		if ( $branch instanceof \WP_Error ) {
			return $this->worktree_get_probe_error('identity', $parsed['dir_name'], $path, $branch);
		}

		if ( $include_status ) {
			$dirty_paths = $this->probe_worktree_dirty_paths($path, self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( $dirty_paths instanceof \WP_Error ) {
				return $this->worktree_get_probe_error('status', $parsed['dir_name'], $path, $dirty_paths);
			}
			$unpushed = $this->count_unpushed_commits($path, self::CLEANUP_GIT_PROBE_TIMEOUT);
			if ( $unpushed instanceof \WP_Error ) {
				return $this->worktree_get_probe_error('unpushed', $parsed['dir_name'], $path, $unpushed);
			}
			$dirty = count($dirty_paths);
		} else {
			$dirty    = null;
			$unpushed = null;
		}

		$metadata     = $parsed['is_worktree'] ? WorktreeContextInjector::get_metadata($parsed['dir_name']) : null;
		$metadata     = is_array($metadata) ? $metadata : null;
		$created_at   = $metadata['created_at'] ?? null;
		$liveness     = WorktreeContextInjector::classify_liveness($metadata);
		$disk         = $include_disk ? $this->build_worktree_disk_report($parsed['repo'], $path, $parsed['is_worktree'], $created_at, $metadata) : array(
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

	/**
	 * Preserve the original failure while making the blocked finalization phase explicit.
	 */
	private function worktree_finalize_phase_error( string $phase, string $handle, string $path, \WP_Error $error, bool $metadata_committed = false ): \WP_Error {
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
	 * }
	 * @return array{success: bool, worktrees: array, fields_skipped: array<int,string>, total?:int, returned?:int, next_cursor?:string|null, status_requested?:bool, disk_requested?:bool, summary?:array}|\WP_Error
	 */
	public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error {
		$include_status = array_key_exists('include_status', $opts) ? (bool) $opts['include_status'] : true;
		$include_disk   = array_key_exists('include_disk', $opts) ? (bool) $opts['include_disk'] : true;
		$target_handle  = isset($opts['handle']) ? trim( (string) $opts['handle']) : '';
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
		if ( $all && isset($opts['cursor']) ) {
			return new \WP_Error('invalid_worktree_list_pagination', 'Worktree list --all cannot be combined with --cursor.', array( 'status' => 400 ));
		}
		$defer_probes   = $bounded && ! $all;
		$run_status     = $include_status && ! $defer_probes;
		$run_disk       = $include_disk && ! $defer_probes;
		$limit = $this->normalize_worktree_list_limit($opts['limit'] ?? 50);
		if ( is_wp_error($limit) ) {
			return new \WP_Error('invalid_worktree_list_limit', 'Worktree list limit must be an integer between 1 and 200.', array( 'status' => 400 ));
		}
		$cursor = isset($opts['cursor']) ? $this->decode_worktree_list_cursor((string) $opts['cursor'], $repo, $state, $target_handle) : null;
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
				return $this->worktree_list_add_response_metadata($result, $include_status, $include_disk);
			}
			if ( ( $result['worktrees'][0]['lifecycle_state'] ?? null ) !== $state ) {
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

		foreach ( new \DirectoryIterator($this->workspace_path) as $entry ) {
			$primary = $entry->getFilename();
			if ( $entry->isDot() || str_contains($primary, '@') || ! $entry->isDir() || ! file_exists($entry->getPathname() . '/.git') || ( null !== $repo && $primary !== $repo ) ) {
				continue;
			}
			$primary_path      = $this->workspace_path . '/' . $primary;
			$primary_repo      = $this->parse_handle($primary)['repo'];
			$scanning_worktree = str_contains($primary, '@');
			$result            = $this->run_git($primary_path, 'worktree list --porcelain');
			if ( is_wp_error($result) ) {
				continue;
			}

			foreach ( $this->worktree_list_blocks((string) ( $result['output'] ?? '' )) as $block ) {
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
				if ( '' !== $target_handle && $handle !== $target_handle ) {
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

				$metadata_key = null;
				if ( ! $is_primary && $inside_ws ) {
					$metadata_key = $relative;
				} elseif ( ! $is_primary && ! $inside_ws ) {
					$metadata_key = 'external:' . sha1($wt['path']);
				}
				$metadata        = null !== $metadata_key ? WorktreeContextInjector::get_metadata($metadata_key) : null;
				$created_at      = is_array($metadata) ? ( $metadata['created_at'] ?? null ) : null;
				$lifecycle_state = is_array($metadata) ? WorktreeContextInjector::project_lifecycle_state($metadata) : null;
				if ( null !== $state && $lifecycle_state !== $state ) {
					continue;
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
				if ( null === $cursor || strcmp($this->worktree_list_row_key($row), $cursor) > 0 ) {
					++$remaining;
					if ( $bounded && ! $all ) {
						$this->worktree_list_insert_bounded_row($worktrees, $row, $limit);
					} else {
						$worktrees[] = $row;
					}
				}
			}
		}

		if ( ! $bounded || $all ) {
			usort($worktrees, fn( array $left, array $right ): int => strcmp($this->worktree_list_row_key($left), $this->worktree_list_row_key($right)));
		}
		$diagnostics = $this->worktree_list_global_diagnostics($repo, $state);
		$summary     = array_merge($summary, $diagnostics['summary']);
		$duplicates            = $diagnostics['duplicates'];
		$base_branch_worktrees = $diagnostics['base_branch_worktrees'];
		if ( $defer_probes && ( $include_status || $include_disk ) ) {
			foreach ( $worktrees as &$worktree ) {
				$probe_result = $this->hydrate_worktree_list_probes($worktree, $include_status, $include_disk);
				if ( is_wp_error($probe_result) ) {
					unset($worktree);
					return $probe_result;
				}
			}
			unset($worktree);
		}
		$next_cursor = null;
		if ( $bounded && ! $all && $remaining > count($worktrees) && ! empty($worktrees) ) {
			$next_cursor = $this->encode_worktree_list_cursor($this->worktree_list_row_key($worktrees[ count($worktrees) - 1 ]), $repo, $state, $target_handle);
		}

		return array(
			'success'               => true,
			'worktrees'             => $worktrees,
			'duplicates'            => $duplicates,
			'base_branch_worktrees' => $base_branch_worktrees,
			'fields_skipped'        => $skipped_groups,
			'total'                 => $summary['total'],
			'returned'              => count($worktrees),
			'next_cursor'           => $next_cursor,
			'status_requested'      => $include_status,
			'disk_requested'        => $include_disk,
			'summary'               => $summary,
		);
	}

	/**
	 * Run requested expensive probes only after bounded pagination selected a row.
	 *
	 * @param array<string,mixed> $worktree Worktree row to enrich in place.
	 * @return \WP_Error|null
	 */
	private function hydrate_worktree_list_probes( array &$worktree, bool $include_status, bool $include_disk ): ?\WP_Error {
		$path = (string) ( $worktree['path'] ?? '' );
		if ( '' === $path ) {
			return null;
		}
		if ( $include_status ) {
			$dirty_result        = $this->run_git($path, 'status --porcelain');
			$worktree['dirty']   = is_wp_error($dirty_result) ? 0 : count(array_filter(array_map('trim', explode("\n", $dirty_result['output'] ?? ''))));
			$unpushed_commits    = $this->count_unpushed_commits($path);
			if ( is_wp_error($unpushed_commits) ) {
				return $unpushed_commits;
			}
			$worktree['unpushed'] = $unpushed_commits;
			if ( ! empty($worktree['is_primary']) ) {
				$worktree['primary_freshness'] = $this->build_primary_freshness_report($path, (string) ( $worktree['handle'] ?? '' ));
			}
		}
		if ( $include_disk ) {
			$worktree = array_merge(
				$worktree,
				$this->build_worktree_disk_report(
					(string) ( $worktree['repo'] ?? '' ),
					$path,
					! empty($worktree['is_worktree']),
					isset($worktree['created_at']) ? (string) $worktree['created_at'] : null,
					is_array($worktree['metadata'] ?? null) ? $worktree['metadata'] : null
				)
			);
		}
		$stale_reason = $this->detect_worktree_stale_reason(
			! empty($worktree['is_worktree']),
			(int) ( $worktree['dirty'] ?? 0 ),
			$worktree['age_days'] ?? null,
			isset($worktree['created_at']) ? (string) $worktree['created_at'] : null,
			array( 'status_probed' => $include_status, 'disk_probed' => $include_disk )
		);
		if ( null === $stale_reason ) {
			unset($worktree['stale_reason']);
		} else {
			$worktree['stale_reason'] = $stale_reason;
		}
		return null;
	}

	/** @return array<string,mixed> */
	private function worktree_list_empty_summary(): array {
		return array( 'total' => 0, 'primary' => 0, 'worktree' => 0, 'external' => 0, 'repos' => array() );
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
		$key = $this->worktree_list_row_key($row);
		$position = count($rows);
		foreach ( $rows as $index => $existing ) {
			if ( strcmp($key, $this->worktree_list_row_key($existing)) < 0 ) {
				$position = $index;
				break;
			}
		}
		if ( $position >= $limit && $limit === count($rows) ) {
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

	/** @return \Generator<int,array<string,mixed>> */
	private function worktree_list_diagnostic_rows( ?string $repo_filter, ?string $state_filter ): \Generator {
		foreach ( new \DirectoryIterator($this->workspace_path) as $entry ) {
			$primary = $entry->getFilename();
			if ( $entry->isDot() || str_contains($primary, '@') || ! $entry->isDir() || ! file_exists($entry->getPathname() . '/.git') || ( null !== $repo_filter && $primary !== $repo_filter ) ) { continue; }
			$primary_path = $entry->getPathname();
			$result = $this->run_git($primary_path, 'worktree list --porcelain');
			if ( is_wp_error($result) ) { continue; }
			foreach ( $this->worktree_list_blocks((string) ( $result['output'] ?? '' )) as $block ) {
				$wt = $this->parse_worktree_block($block);
				if ( null === $wt ) { continue; }
				$is_primary = $wt['path'] === $primary_path;
				$inside = str_starts_with($wt['path'], $this->workspace_path . '/');
				$handle = $is_primary ? $primary : ( $inside ? substr($wt['path'], strlen($this->workspace_path . '/')) : $wt['path'] );
				$metadata_key = ! $is_primary ? ( $inside ? $handle : 'external:' . sha1($wt['path']) ) : null;
				$metadata = null !== $metadata_key ? WorktreeContextInjector::get_metadata($metadata_key) : null;
				if ( null !== $state_filter && ( ! is_array($metadata) || ( $metadata['lifecycle_state'] ?? null ) !== $state_filter ) ) { continue; }
				yield array( 'handle' => $handle, 'repo' => $this->parse_handle($primary)['repo'], 'is_primary' => $is_primary, 'is_worktree' => ! $is_primary, 'external' => ! $is_primary && ! $inside, 'branch' => $wt['branch'], 'path' => $wt['path'], 'metadata' => $metadata, 'pr_url' => is_array($metadata) ? ( $metadata['pr_url'] ?? null ) : null, 'pr_number' => is_array($metadata) ? ( $metadata['pr_number'] ?? null ) : null );
			}
		}
	}

	/** @return array{summary:array<string,mixed>,duplicates:array<int,array<string,mixed>>,base_branch_worktrees:array<int,array<string,mixed>>} */
	private function worktree_list_global_diagnostics( ?string $repo_filter, ?string $state_filter ): array {
		$repo_names = array();
		$base = array();
		$base_total = 0;
		foreach ( $this->worktree_list_diagnostic_rows($repo_filter, $state_filter) as $row ) {
			$this->worktree_list_insert_bounded_key($repo_names, (string) $row['repo'], 25);
			$warning = $this->base_branch_worktree_warning($row);
			if ( null !== $warning ) { ++$base_total; $this->worktree_list_insert_bounded_row($base, $warning, 25); }
		}
		$repos = array();
		foreach ( array_keys($repo_names) as $name ) { $repos[ $name ] = array( 'repo' => $name, 'primary' => 0, 'worktree' => 0, 'external' => 0, 'total' => 0 ); }
		foreach ( $this->worktree_list_diagnostic_rows($repo_filter, $state_filter) as $row ) {
			$name = (string) $row['repo'];
			if ( isset($repos[ $name ]) ) { ++$repos[ $name ][ ! empty($row['is_primary']) ? 'primary' : 'worktree' ]; ++$repos[ $name ]['total']; if ( ! empty($row['external']) ) { ++$repos[ $name ]['external']; } }
		}
		$duplicates = $this->worktree_list_duplicate_groups($repo_filter, $state_filter);
		$repo_count = $this->worktree_list_unique_repository_count($repo_filter, $state_filter, $repo_names);
		return array( 'summary' => array( 'repos' => array_values($repos), 'repo_count' => $repo_count, 'repos_returned' => count($repos), 'repos_omitted' => $repo_count - count($repos), 'duplicate_task_groups_total' => $duplicates['total'], 'duplicate_task_groups_returned' => count($duplicates['groups']), 'duplicate_task_groups_omitted' => $duplicates['total'] - count($duplicates['groups']), 'base_branch_worktrees_total' => $base_total, 'base_branch_worktrees_returned' => count($base), 'base_branch_worktrees_omitted' => $base_total - count($base) ), 'duplicates' => $duplicates['groups'], 'base_branch_worktrees' => $base );
	}

	/** @param array<string,bool> $first_batch */
	private function worktree_list_unique_repository_count( ?string $repo_filter, ?string $state_filter, array $first_batch ): int {
		$count = count($first_batch);
		if ( $count < 25 ) { return $count; }
		$after = (string) array_key_last($first_batch);
		do {
			$batch = array();
			foreach ( $this->worktree_list_diagnostic_rows($repo_filter, $state_filter) as $row ) { if ( (string) $row['repo'] > $after ) { $this->worktree_list_insert_bounded_key($batch, (string) $row['repo'], 26); } }
			$size = count($batch); $count += min(25, $size);
			if ( $size > 25 ) { $after = (string) array_keys($batch)[24]; }
		} while ( $size > 25 );
		return $count;
	}

	/** @return array{total:int,groups:array<int,array<string,mixed>>} */
	private function worktree_list_duplicate_groups( ?string $repo_filter, ?string $state_filter ): array {
		$after = ''; $total = 0; $samples = array();
		do {
			$batch = array();
			foreach ( $this->worktree_list_diagnostic_rows($repo_filter, $state_filter) as $row ) {
				foreach ( WorktreeContextInjector::task_ownership_keys($row, is_array($row['metadata'] ?? null) ? $row['metadata'] : array()) as $kind => $key ) {
					$id = $kind . '|' . $key;
					if ( $id <= $after ) { continue; }
					if ( ! isset($batch[ $id ]) ) { $batch[ $id ] = array( 'kind' => $kind, 'key' => $key, 'handles' => array(), 'handle_count' => 0 ); ksort($batch); if ( count($batch) > 26 ) { array_pop($batch); } }
					if ( isset($batch[ $id ]) ) { ++$batch[ $id ]['handle_count']; if ( count($batch[ $id ]['handles']) < 25 ) { $batch[ $id ]['handles'][] = (string) $row['handle']; } }
				}
			}
			$size = count($batch); $keys = array_keys($batch);
			foreach ( array_slice($batch, 0, min(25, $size), true) as $id => $group ) { if ( $group['handle_count'] > 1 ) { ++$total; $samples[ $id ] = $group; ksort($samples); if ( count($samples) > 25 ) { array_pop($samples); } } }
			if ( $size > 25 ) { $after = (string) $keys[24]; }
		} while ( $size > 25 );
		foreach ( $samples as &$sample ) {
			$sample['handles_omitted'] = $sample['handle_count'] - count($sample['handles']);
		}
		unset($sample);
		return array( 'total' => $total, 'groups' => array_values($samples) );
	}

	/** @param array<string,bool> $keys */
	private function worktree_list_insert_bounded_key( array &$keys, string $key, int $limit ): void {
		$keys[ $key ] = true;
		ksort($keys);
		if ( count($keys) > $limit ) { array_pop($keys); }
	}

	/** @param array<int,array<string,mixed>> $worktrees */
	private function worktree_list_summary( array $worktrees ): array {
		$summary = array( 'total' => count($worktrees), 'primary' => 0, 'worktree' => 0, 'external' => 0, 'repos' => array() );
		foreach ( $worktrees as $worktree ) {
			$kind = ! empty($worktree['is_primary']) ? 'primary' : 'worktree';
			++$summary[ $kind ];
			if ( ! empty($worktree['external']) ) {
				++$summary['external'];
			}
			$repo = (string) ( $worktree['repo'] ?? 'unknown' );
			$summary['repos'][ $repo ] = 1 + ( $summary['repos'][ $repo ] ?? 0 );
		}
		ksort($summary['repos']);
		return $summary;
	}

	/** @param array<string,mixed> $result */
	private function worktree_list_add_response_metadata( array $result, bool $include_status, bool $include_disk ): array {
		$worktrees = (array) ( $result['worktrees'] ?? array() );
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

	private function encode_worktree_list_cursor( string $after, ?string $repo, ?string $state, string $handle ): string {
		return ListCursor::encode($after, array( 'repo' => $repo, 'state' => $state, 'handle' => $handle ));
	}

	private function decode_worktree_list_cursor( string $cursor, ?string $repo, ?string $state, string $handle ): string|\WP_Error {
		return ListCursor::decode(
			$cursor,
			array( 'repo' => $repo, 'state' => $state, 'handle' => $handle ),
			'invalid_worktree_list_cursor',
			'Worktree list cursor is invalid for the requested filters.'
		);
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
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_inventory_refresh(): array|\WP_Error {
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

		$repository      = $this->worktree_inventory();
		$current_handles = array();
		$upserted        = array();
		$marked_missing  = array();

		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' === $handle || ! empty($row['external']) ) {
				continue;
			}

			$current_handles[ $handle ] = true;
			if ( $repository->upsert($row) ) {
				$upserted[] = $handle;
			}
		}

		foreach ( $repository->list() as $stored ) {
			$handle = (string) ( $stored['handle'] ?? '' );
			if ( '' === $handle || isset($current_handles[ $handle ]) ) {
				continue;
			}

			if ( $repository->mark_missing($handle) ) {
				$marked_missing[] = $handle;
			}
		}

		return array(
			'success'        => true,
			'refreshed_at'   => gmdate('c'),
			'upserted'       => $upserted,
			'marked_missing' => $marked_missing,
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
	 * @return array{success: bool, pruned: array, skipped?: array, next_commands?: array, inventory?: array, stale_inventory?: array, stale_marker_blockers?: array}|\WP_Error
	 */
	public function worktree_prune(): array|\WP_Error {
		$pruned          = array();
		$skipped         = array();
		$next_commands   = array();
		$stale_rows      = array();
		$marker_blocks   = array();
		$marker_repaired = array();

		if ( ! is_dir($this->workspace_path) ) {
			return array(
				'success' => true,
				'pruned'  => $pruned,
			);
		}

		$entries = scandir($this->workspace_path);
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_contains($entry, '@') ) {
				continue;
			}
			$primary_path = $this->workspace_path . '/' . $entry;
			if ( ! GitCheckout::exists($primary_path) ) {
				continue;
			}
			$result = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$entry,
				fn() => $this->run_git($primary_path, 'worktree prune -v --expire=now')
			);
			if ( is_wp_error($result) ) {
				if ( 'datamachine_workspace_git_unavailable' === $result->get_error_code() ) {
					$skipped[]       = array(
						'repo'         => $entry,
						'primary_path' => $primary_path,
						'reason'       => $result->get_error_message(),
					);
					$next_commands[] = sprintf('git -C %s worktree prune -v --expire=now', escapeshellarg($primary_path));
					continue;
				}
				return $result;
			}
			// Git emits a verbose line for every removed registration. Preserve the
			// existing `pruned` result as evidence of actual reconciliation instead
			// of reporting every primary that was merely scanned.
			if ( '' !== trim( (string) ( $result['output'] ?? '' )) ) {
				$pruned[] = $entry;
			}
		}

		$refresh = $this->worktree_inventory_refresh();
		if ( $refresh instanceof \WP_Error ) {
			return $refresh;
		}

		$inventory_diagnostics = $this->prune_stale_worktree_inventory_rows();
		if ( $inventory_diagnostics instanceof \WP_Error ) {
			return $inventory_diagnostics;
		}

		$stale_rows      = (array) ( $inventory_diagnostics['stale_inventory'] ?? array() );
		$marker_blocks   = (array) ( $inventory_diagnostics['stale_marker_blockers'] ?? array() );
		$marker_repaired = (array) ( $inventory_diagnostics['stale_marker_repaired'] ?? array() );
		foreach ( (array) ( $inventory_diagnostics['next_commands'] ?? array() ) as $command ) {
			$next_commands[] = (string) $command;
		}

		return array(
			'success'               => true,
			'pruned'                => $pruned,
			'skipped'               => $skipped,
			'next_commands'         => array_values(array_unique($next_commands)),
			'inventory'             => $refresh,
			'stale_inventory'       => $stale_rows,
			'stale_marker_blockers' => $marker_blocks,
			'stale_marker_repaired' => $marker_repaired,
		);
	}

	/**
	 * Repair safe stale inventory rows and report marker blockers that need review.
	 *
	 * `git worktree prune` only repairs Git's own metadata. DMC can also retain
	 * cleanup-eligible inventory rows for worktrees that no longer exist, and
	 * those rows can block bounded cleanup even after Git reports nothing to
	 * prune. Missing-path rows are safe to forget because no checkout remains on
	 * disk; path-present stale markers are removed only when the inventory row has
	 * a cleanup signal and exactly matches the expected workspace worktree path.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function prune_stale_worktree_inventory_rows(): array|\WP_Error {
		$repository            = $this->worktree_inventory();
		$stale_inventory       = array();
		$stale_marker_blockers = array();
		$stale_marker_repaired = array();
		$next_commands         = array();

		foreach ( $repository->list() as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			$repo   = (string) ( $row['repo'] ?? '' );
			$path   = (string) ( $row['path'] ?? '' );
			$parsed = '' !== $handle ? $this->parse_handle($handle) : array( 'is_worktree' => false );
			if ( '' === $handle || empty($parsed['is_worktree']) ) {
				continue;
			}

			if ( ! empty($row['missing_path']) && ( '' === $path || ! is_dir($path) ) ) {
				if ( $repository->delete($handle) ) {
					WorktreeContextInjector::forget_metadata($handle);
					$stale_inventory[] = array(
						'handle'      => $handle,
						'repo'        => $repo,
						'path'        => $path,
						'reason_code' => 'registry_artifact',
						'reason'      => 'inventory row pointed at a missing worktree path and was removed from DMC metadata',
					);
				}
				continue;
			}

			$marker = rtrim($path, '/') . '/.git';
			if ( ! is_file($marker) ) {
				continue;
			}

			$contents = file_get_contents($marker); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a validated local .git marker, not a remote URL.
			if ( false === $contents || ! preg_match('/^gitdir:\s*(.+)$/mi', $contents, $matches) ) {
				continue;
			}

			$gitdir = trim($matches[1]);
			if ( ! str_contains($gitdir, '/.git/worktrees/') && ! str_contains($gitdir, '\\.git\\worktrees\\') ) {
				continue;
			}

			if ( file_exists($gitdir) ) {
				continue;
			}

			$repo         = '' !== $repo ? $repo : (string) ( $parsed['repo'] ?? '' );
			$primary_path = '' !== $repo ? $this->get_primary_path($repo) : (string) ( $row['primary_path'] ?? '' );
			$repair       = WorkspaceMutationLock::with_repo(
				$this->workspace_path,
				$repo,
				fn() => $this->repair_cleanup_eligible_stale_worktree_marker($row, $parsed, $gitdir, $primary_path)
			);
			if ( $repair instanceof \WP_Error ) {
				return $repair;
			}

			if ( null !== $repair ) {
				$stale_marker_repaired[] = $repair;
				continue;
			}

			$remove_command          = sprintf('studio wp datamachine-code workspace remove %s --yes', escapeshellarg($handle));
			$stale_marker_blockers[] = array(
				'handle'       => $handle,
				'repo'         => $repo,
				'path'         => $path,
				'primary_path' => $primary_path,
				'gitdir'       => $gitdir,
				'reason_code'  => 'stale_worktree_marker',
				'reason'       => 'worktree path still exists, but its .git marker points at a missing primary .git/worktrees entry; leaving checkout in place because the row is not an exact cleanup-eligible stale marker candidate',
				'hint'         => 'Inspect the path before removal. If it is safe to discard, run the DMC-owned remove command returned in next_command.',
				'next_command' => $remove_command,
			);
			$next_commands[]         = $remove_command;
		}

		return array(
			'stale_inventory'       => $stale_inventory,
			'stale_marker_blockers' => $stale_marker_blockers,
			'stale_marker_repaired' => $stale_marker_repaired,
			'next_commands'         => array_values(array_unique($next_commands)),
		);
	}

	/**
	 * Remove an exact cleanup-eligible stale marker worktree path from DMC-owned state.
	 *
	 * @param array<string,mixed> $row          Inventory row.
	 * @param array<string,mixed> $parsed       Parsed worktree handle.
	 * @param string              $gitdir       Missing gitdir from the stale marker.
	 * @param string              $primary_path Primary checkout path.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	private function repair_cleanup_eligible_stale_worktree_marker( array $row, array $parsed, string $gitdir, string $primary_path ): array|null|\WP_Error {
		$handle   = (string) ( $row['handle'] ?? '' );
		$repo     = (string) ( $row['repo'] ?? $parsed['repo'] ?? '' );
		$path     = rtrim( (string) ( $row['path'] ?? '' ), '/');
		$current  = $this->worktree_inventory()->get($handle);
		if ( ! is_array($current)
			|| $repo !== (string) ( $current['repo'] ?? '' )
			|| $path !== rtrim((string) ( $current['path'] ?? '' ), '/') ) {
			return null;
		}
		$row      = $current;
		$metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : array();
		if ( empty($metadata) && ! empty($row['lifecycle_state']) ) {
			$metadata['lifecycle_state'] = (string) $row['lifecycle_state'];
		}
		if ( empty($metadata) && 'cleanup_eligible' === (string) ( $row['cleanup_signal'] ?? '' ) ) {
			$metadata['lifecycle_state'] = 'cleanup_eligible';
		}

		if ( '' === $handle || '' === $path || empty($parsed['is_worktree']) || ! WorktreeContextInjector::has_cleanup_signal($metadata) ) {
			return null;
		}

		$expected_path = rtrim($this->workspace_path, '/') . '/' . (string) ( $parsed['dir_name'] ?? $handle );
		if ( $path !== $expected_path ) {
			return null;
		}

		$validation    = $this->validate_containment($path, $this->workspace_path);
		$expected_real = realpath($expected_path);
		if ( empty($validation['valid']) || false === $expected_real || (string) ( $validation['real_path'] ?? '' ) !== $expected_real ) {
			return null;
		}
		$current_marker = $path . '/.git';
		$current_contents = is_file($current_marker) ? file_get_contents($current_marker) : false;
		if ( false === $current_contents || ! str_contains($current_contents, $gitdir) || file_exists($gitdir) ) {
			return null;
		}

		$removed_paths = $this->remove_contained_directory_recursive($path, $this->workspace_path, $this->workspace_path);
		if ( $removed_paths instanceof \WP_Error ) {
			return $removed_paths;
		}

		WorktreeContextInjector::forget_metadata($handle);
		$this->worktree_inventory()->delete($handle);
		if ( '' !== $primary_path && GitCheckout::exists($primary_path) ) {
			$this->run_git($primary_path, 'worktree prune');
		}

		return array(
			'handle'        => $handle,
			'repo'          => $repo,
			'path'          => $path,
			'primary_path'  => $primary_path,
			'gitdir'        => $gitdir,
			'reason_code'   => 'stale_worktree_marker_repaired',
			'reason'        => 'cleanup-eligible worktree path exactly matched a stale .git marker row and was removed from DMC workspace state',
			'removed_paths' => $removed_paths,
		);
	}

	/**
	 * Inspect capacity through a testable admission seam.
	 *
	 * @param array<string,mixed> $demand_plan
	 * @return array<string,mixed>
	 */
	protected function inspect_worktree_capacity( string $repo, string $branch, bool $force, array $demand_plan ): array {
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
			$repos = array_values(array_unique(array_filter(array_map(
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
			'mode'            => 'bounded_safe_remediation',
			'dry_run'         => $dry_run,
			'before'          => $before,
			'after'           => $after,
			'artifact_preview' => $artifact_preview,
			'artifact_apply'   => $artifact_apply,
			'cleanup_drain'   => $drain,
			'reclaimed_bytes' => (int) ( $drain['summary']['bytes_reclaimed'] ?? 0 ),
			'reclaimed_inodes' => max(0, (int) ( $after['free_inodes'] ?? 0 ) - (int) ( $before['free_inodes'] ?? 0 )),
			'retry_disposition' => $dry_run
				? 'dry_run_no_retry'
				: ( 'refused' === ( $after['status'] ?? '' ) ? 'insufficient_safe_reclaim' : 'retry_once' ),
		);
	}

	/** @return array<string,mixed> */
	private function capacity_remediation_failure( array $before, bool $dry_run, string $stage, \WP_Error $error, ?array $artifact_preview = null, ?array $artifact_apply = null ): array {
		$error_data = $error->get_error_data();
		return array(
			'mode'             => 'bounded_safe_remediation',
			'dry_run'          => $dry_run,
			'before'           => $before,
			'after'            => $before,
			'artifact_preview' => $artifact_preview,
			'artifact_apply'   => $artifact_apply,
			'cleanup_drain'    => is_array($error_data) && is_array($error_data['cleanup_drain'] ?? null) ? $error_data['cleanup_drain'] : null,
			'failure'          => array(
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
			$result['continuation'] = $plan_continuation;
			$result['next_command'] = $next_command;
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
	private function resolve_remote_default_ref( string $repo_path ): ?string {
		$result = $this->run_git($repo_path, 'symbolic-ref --quiet refs/remotes/origin/HEAD');
		if ( is_wp_error($result) ) {
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
	private function populate_default_branch_behind_count( string $primary_path, string $branch, array &$response ): void {
		$default_ref = $this->resolve_remote_default_ref($primary_path);
		if ( null === $default_ref ) {
			return;
		}

		$behind = WorktreeStalenessProbe::behind_count($primary_path, $branch, $default_ref);
		if ( is_int($behind) ) {
			$response['default_branch_commits_behind'] = $behind;
			$response['default_branch_ref']            = $default_ref;
		}
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
		$before = WorktreeDiskBudget::inspect($this->workspace_path);
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
			'before'  => array( 'filesystem_free_bytes' => $before['filesystem_free_bytes'] ?? null, 'filesystem_free_inodes' => $before['filesystem_free_inodes'] ?? null ),
			'after'   => array( 'filesystem_free_bytes' => $after['filesystem_free_bytes'] ?? null, 'filesystem_free_inodes' => $after['filesystem_free_inodes'] ?? null ),
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
					'progress'                  => array( 'phase' => $phase, 'state' => 'timed_out' ),
				),
				$extra
			)
		);
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
		$owner = array_filter(array(
			'active_lock'         => $data['active_lock'] ?? null,
			'filesystem_lock'     => $data['filesystem_lock'] ?? null,
			'lock_key'            => $data['lock_key'] ?? null,
			'wait_timeout_seconds' => $data['wait_timeout_seconds'] ?? null,
		));
		return $this->worktree_operation_timeout($phase, $timeout, $started, array( 'lock_owner' => $owner ));
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
