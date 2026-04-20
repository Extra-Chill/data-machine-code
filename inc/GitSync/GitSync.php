<?php
/**
 * GitSync
 *
 * Binds site-owned local directories under ABSPATH to remote git
 * repositories, keeping them in lockstep via pull (and, in later phases,
 * push + scheduled sync). Parallel to `Workspace\Workspace` — Workspace
 * manages agent-owned checkouts under `~/.datamachine/workspace/`;
 * GitSync manages site-owned subtrees (wiki content, synced agent
 * definitions, etc.) where the site chooses the path.
 *
 * Phase 1 surface (this file):
 *   - bind()    — register a binding and either clone or adopt an
 *                 existing git checkout at the target path.
 *   - unbind()  — remove binding metadata; directory is preserved by
 *                 default (pass `$purge = true` to wipe it).
 *   - pull()    — fast-forward pull, honoring conflict policy.
 *   - status()  — dirty count, branch, HEAD, upstream gap.
 *   - list_bindings() — every registered binding.
 *
 * Push, policy-update, and the scheduled pull task land in follow-up
 * PRs (DMC#38 phases 2 and 3).
 *
 * @package DataMachineCode\GitSync
 * @since   0.7.0
 * @see     https://github.com/Extra-Chill/data-machine-code/issues/38
 */

namespace DataMachineCode\GitSync;

use DataMachineCode\GitSync\GitRepo;
use DataMachineCode\GitSync\GitSyncSubmitter;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

final class GitSync {

	private GitSyncRegistry $registry;

	public function __construct( ?GitSyncRegistry $registry = null ) {
		$this->registry = $registry ?? new GitSyncRegistry();
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Register a new binding and materialize it on disk.
	 *
	 * On disk behavior:
	 *   - Path missing            → git clone into it.
	 *   - Path exists, empty      → git clone into it.
	 *   - Path exists, has `.git` → adopt if origin matches the binding's
	 *                               remote_url; error otherwise.
	 *   - Path exists, non-empty, no `.git` → refuse. Safer to make the
	 *                               user decide than to overlay a clone
	 *                               on whatever's already there.
	 *
	 * @param array<string, mixed> $input {
	 *     @type string $slug       Unique binding identifier.
	 *     @type string $local_path ABSPATH-relative path (leading slash).
	 *     @type string $remote_url Git remote URL (https:// or git@).
	 *     @type string $branch     Branch to track. Default 'main'.
	 *     @type array  $policy     Policy overrides (see GitSyncBinding::DEFAULT_POLICY).
	 * }
	 * @return array{success: true, binding: array<string, mixed>, cloned: bool, adopted: bool, local_path: string}|\WP_Error
	 */
	public function bind( array $input ): array|\WP_Error {
		$binding = GitSyncBinding::create( $input );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}

		if ( $this->registry->exists( $binding->slug ) ) {
			return new \WP_Error(
				'binding_exists',
				sprintf( 'A binding with slug "%s" already exists. Unbind it first.', $binding->slug ),
				array( 'status' => 409 )
			);
		}

		$containment = $this->validateBindingPath( $binding );
		if ( is_wp_error( $containment ) ) {
			return $containment;
		}
		$absolute = $containment;

		$materialize = $this->materializeClone( $binding, $absolute );
		if ( is_wp_error( $materialize ) ) {
			return $materialize;
		}

		$binding->last_commit = GitRepo::head( $absolute );
		$binding->last_pulled = gmdate( 'c' );
		$this->registry->save( $binding );

		return array(
			'success'    => true,
			'binding'    => $binding->toArray(),
			'cloned'     => ! $materialize['adopted'],
			'adopted'    => $materialize['adopted'],
			'local_path' => $absolute,
		);
	}

	/**
	 * Remove a binding.
	 *
	 * By default the on-disk working tree is preserved — the binding is
	 * metadata, not ownership of the directory. `$purge = true` opts in
	 * to deleting the directory (with strict containment re-validation
	 * at the blast radius).
	 *
	 * @param string $slug  Binding slug.
	 * @param bool   $purge If true, remove the on-disk directory too.
	 * @return array{success: true, slug: string, purged: bool, local_path: string}|\WP_Error
	 */
	public function unbind( string $slug, bool $purge = false ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$absolute = $binding->resolveAbsolutePath();
		$purged   = false;

		if ( $purge && is_dir( $absolute ) ) {
			// Belt-and-suspenders: re-validate containment right before rm.
			$abspath    = rtrim( ABSPATH, '/' );
			$validation = PathSecurity::validateContainment( $absolute, $abspath );
			if ( ! $validation['valid'] ) {
				return new \WP_Error(
					'path_outside_abspath',
					sprintf( 'Refusing to purge "%s": %s', $absolute, $validation['message'] ?? 'containment failed' ),
					array( 'status' => 403 )
				);
			}

			$escaped = escapeshellarg( $validation['real_path'] );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( sprintf( 'rm -rf %s 2>&1', $escaped ), $_unused, $exit_code );
			if ( 0 !== $exit_code ) {
				return new \WP_Error( 'purge_failed', sprintf( 'Failed to remove directory %s (exit %d).', $absolute, $exit_code ), array( 'status' => 500 ) );
			}
			$purged = true;
		}

		$this->registry->delete( $slug );

		return array(
			'success'    => true,
			'slug'       => $slug,
			'purged'     => $purged,
			'local_path' => $absolute,
		);
	}

	/**
	 * Fast-forward pull a binding.
	 *
	 * Conflict handling:
	 *   - `fail`          (default): refuse pull when working tree is dirty.
	 *   - `upstream_wins`: reset hard to the remote tip, discarding local
	 *                      changes. Non-reversible; opt-in per binding.
	 *   - `manual`:        allow the dirty pull to run and surface the
	 *                      failure for admin review.
	 *
	 * `$allow_dirty` can override a `fail` policy one-off (CLI convenience).
	 *
	 * @param string $slug        Binding slug.
	 * @param bool   $allow_dirty Bypass dirty-tree safety for this pull.
	 * @return array{success: true, slug: string, branch: string, previous_head: ?string, head: ?string, message: string}|\WP_Error
	 */
	public function pull( string $slug, bool $allow_dirty = false ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$absolute = $binding->resolveAbsolutePath();
		$repo_err = $this->assertRepo( $absolute, $slug );
		if ( is_wp_error( $repo_err ) ) {
			return $repo_err;
		}

		$previous_head = GitRepo::head( $absolute );
		$dirty_count   = GitRepo::dirtyCount( $absolute );
		$conflict      = (string) ( $binding->policy['conflict'] ?? 'fail' );

		if ( $dirty_count > 0 && ! $allow_dirty ) {
			if ( 'fail' === $conflict ) {
				return new \WP_Error(
					'dirty_working_tree',
					sprintf( 'Working tree for "%s" is dirty (%d file(s)). Pass allow_dirty=true or change conflict policy.', $slug, $dirty_count ),
					array( 'status' => 400, 'dirty' => $dirty_count )
				);
			}
			if ( 'upstream_wins' === $conflict ) {
				// Reset hard before fetching to ensure a clean fast-forward.
				$reset = GitRunner::run( $absolute, 'reset --hard HEAD' );
				if ( is_wp_error( $reset ) ) {
					return $reset;
				}
			}
			// 'manual' — fall through and let the pull attempt happen; git
			// will refuse on conflict and the error surfaces to the caller.
		}

		// Refuse any pull that would change branches.
		$current_branch = GitRepo::branch( $absolute );
		if ( null !== $current_branch && $current_branch !== $binding->branch ) {
			return new \WP_Error(
				'branch_mismatch',
				sprintf(
					'Binding "%s" is pinned to branch "%s" but the working tree is on "%s". Refusing to pull.',
					$slug,
					$binding->branch,
					$current_branch
				),
				array( 'status' => 409 )
			);
		}

		$pull = GitRunner::run( $absolute, 'pull --ff-only origin ' . escapeshellarg( $binding->branch ) );
		if ( is_wp_error( $pull ) ) {
			return $pull;
		}

		$new_head             = GitRepo::head( $absolute );
		$binding->last_pulled = gmdate( 'c' );
		$binding->last_commit = $new_head;
		$this->registry->save( $binding );

		return array(
			'success'       => true,
			'slug'          => $slug,
			'branch'        => $binding->branch,
			'previous_head' => $previous_head,
			'head'          => $new_head,
			'message'       => trim( (string) ( $pull['output'] ?? '' ) ),
		);
	}

	/**
	 * Status snapshot for a binding.
	 *
	 * Reports the working-tree state (branch, HEAD, dirty files) and any
	 * drift between local HEAD and the tracked upstream ref. Drift is
	 * computed against `origin/<binding->branch>` via `rev-list --count`,
	 * so a stale local ref produces a conservative number rather than
	 * failing the call.
	 *
	 * @param string $slug Binding slug.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function status( string $slug ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$absolute = $binding->resolveAbsolutePath();
		$exists   = is_dir( $absolute );
		$is_repo  = $exists && ( is_dir( $absolute . '/.git' ) || is_file( $absolute . '/.git' ) );

		$data = array(
			'success'     => true,
			'slug'        => $slug,
			'local_path'  => $absolute,
			'remote_url'  => $binding->remote_url,
			'tracked_branch' => $binding->branch,
			'exists'      => $exists,
			'is_repo'     => $is_repo,
			'branch'      => null,
			'head'        => null,
			'dirty'       => 0,
			'ahead'       => null,
			'behind'      => null,
			'last_pulled' => $binding->last_pulled,
			'policy'      => $binding->policy,
		);

		if ( ! $is_repo ) {
			return $data;
		}

		$data['branch'] = GitRepo::branch( $absolute );
		$data['head']   = GitRepo::head( $absolute );
		$data['dirty']  = GitRepo::dirtyCount( $absolute );

		$upstream = 'origin/' . $binding->branch;
		$ahead    = GitRunner::run( $absolute, 'rev-list --count ' . escapeshellarg( $upstream ) . '..HEAD' );
		$behind   = GitRunner::run( $absolute, 'rev-list --count HEAD..' . escapeshellarg( $upstream ) );
		$data['ahead']  = is_wp_error( $ahead ) ? null : (int) trim( (string) $ahead['output'] );
		$data['behind'] = is_wp_error( $behind ) ? null : (int) trim( (string) $behind['output'] );

		return $data;
	}

	/**
	 * List every registered binding with a lightweight status snapshot.
	 *
	 * @return array{success: true, bindings: array<int, array<string, mixed>>}
	 */
	public function list_bindings(): array {
		$bindings = $this->registry->all();
		$out      = array();
		foreach ( $bindings as $binding ) {
			$absolute = $binding->resolveAbsolutePath();
			$is_repo  = is_dir( $absolute ) && ( is_dir( $absolute . '/.git' ) || is_file( $absolute . '/.git' ) );
			$out[]    = array(
				'slug'           => $binding->slug,
				'local_path'     => $binding->local_path,
				'absolute_path'  => $absolute,
				'remote_url'     => $binding->remote_url,
				'branch'         => $binding->branch,
				'exists'         => is_dir( $absolute ),
				'is_repo'        => $is_repo,
				'auto_pull'      => (bool) ( $binding->policy['auto_pull'] ?? false ),
				'pull_interval'  => (string) ( $binding->policy['pull_interval'] ?? 'hourly' ),
				'last_pulled'    => $binding->last_pulled,
				'last_commit'    => $binding->last_commit,
			);
		}

		return array(
			'success'  => true,
			'bindings' => $out,
		);
	}

	// =========================================================================
	// Phase 2 — write path
	// =========================================================================

	/**
	 * Stage one or more relative paths for commit on a binding's working tree.
	 *
	 * Every path runs through the same checkpoint:
	 *   1. Not absolute, no traversal, not sensitive.
	 *   2. Under at least one of the binding's `allowed_paths` roots.
	 *   3. Actually exists under the binding's working tree.
	 *
	 * A single rejected path fails the whole call — we never partially stage.
	 * This keeps the outcome predictable: either all requested paths are
	 * staged, or none are.
	 *
	 * @param string   $slug  Binding slug.
	 * @param string[] $paths Relative paths inside the binding.
	 * @return array{success: true, slug: string, paths: string[], message: string}|\WP_Error
	 */
	public function add( string $slug, array $paths ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		if ( empty( $binding->policy['write_enabled'] ) ) {
			return new \WP_Error(
				'write_disabled',
				sprintf( 'Writes are disabled for binding "%s" (policy.write_enabled=false).', $slug ),
				array( 'status' => 403 )
			);
		}

		$allowed_roots = is_array( $binding->policy['allowed_paths'] ?? null )
			? $binding->policy['allowed_paths']
			: array();
		if ( empty( $allowed_roots ) ) {
			return new \WP_Error(
				'no_allowed_paths',
				sprintf( 'Binding "%s" has no allowed_paths configured — nothing can be staged.', $slug ),
				array( 'status' => 403 )
			);
		}

		$absolute = $binding->resolveAbsolutePath();
		$repo_err = $this->assertRepo( $absolute, $slug );
		if ( is_wp_error( $repo_err ) ) {
			return $repo_err;
		}

		$clean = array();
		foreach ( $paths as $raw ) {
			$relative = ltrim( trim( (string) $raw ), '/' );
			if ( '' === $relative ) {
				continue;
			}

			if ( PathSecurity::hasTraversal( $relative ) ) {
				return new \WP_Error( 'path_traversal', sprintf( 'Invalid path (traversal): %s', $relative ), array( 'status' => 400 ) );
			}
			if ( PathSecurity::isSensitivePath( $relative ) ) {
				return new \WP_Error( 'sensitive_path', sprintf( 'Refusing to stage sensitive path: %s', $relative ), array( 'status' => 403 ) );
			}
			if ( ! PathSecurity::isPathAllowed( $relative, $allowed_roots ) ) {
				return new \WP_Error(
					'path_not_allowed',
					sprintf( 'Path "%s" is outside the binding\'s allowed_paths allowlist.', $relative ),
					array( 'status' => 403, 'allowed_paths' => $allowed_roots )
				);
			}

			$clean[] = $relative;
		}

		if ( empty( $clean ) ) {
			return new \WP_Error( 'no_paths', 'At least one non-empty path is required.', array( 'status' => 400 ) );
		}

		$escaped = array_map( 'escapeshellarg', $clean );
		$result  = GitRunner::run( $absolute, 'add -- ' . implode( ' ', $escaped ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'slug'    => $slug,
			'paths'   => $clean,
			'message' => sprintf( 'Staged %d path(s) on "%s".', count( $clean ), $slug ),
		);
	}

	/**
	 * Commit staged changes on a binding's working tree.
	 *
	 * Message constraints mirror Workspace for consistency: 8–200 characters.
	 * Refuses when nothing is staged so empty commits never sneak through.
	 *
	 * @param string $slug    Binding slug.
	 * @param string $message Commit message.
	 * @return array{success: true, slug: string, commit: ?string, message: string}|\WP_Error
	 */
	public function commit( string $slug, string $message ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		if ( empty( $binding->policy['write_enabled'] ) ) {
			return new \WP_Error( 'write_disabled', sprintf( 'Writes are disabled for binding "%s".', $slug ), array( 'status' => 403 ) );
		}

		$message = trim( $message );
		if ( '' === $message ) {
			return new \WP_Error( 'missing_message', 'Commit message is required.', array( 'status' => 400 ) );
		}
		if ( strlen( $message ) < 8 ) {
			return new \WP_Error( 'message_too_short', 'Commit message must be at least 8 characters.', array( 'status' => 400 ) );
		}
		if ( strlen( $message ) > 200 ) {
			return new \WP_Error( 'message_too_long', 'Commit message must be 200 characters or fewer.', array( 'status' => 400 ) );
		}

		$absolute = $binding->resolveAbsolutePath();
		$repo_err = $this->assertRepo( $absolute, $slug );
		if ( is_wp_error( $repo_err ) ) {
			return $repo_err;
		}

		$staged = GitRunner::run( $absolute, 'diff --cached --name-only' );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$staged_files = array_filter( array_map( 'trim', explode( "\n", (string) ( $staged['output'] ?? '' ) ) ) );
		if ( empty( $staged_files ) ) {
			return new \WP_Error( 'nothing_staged', 'No staged changes to commit.', array( 'status' => 400 ) );
		}

		$result = GitRunner::run( $absolute, 'commit -m ' . escapeshellarg( $message ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'slug'    => $slug,
			'commit'  => GitRepo::head( $absolute ),
			'message' => sprintf( 'Committed %d file(s) on "%s".', count( $staged_files ), $slug ),
		);
	}

	/**
	 * Push the binding's pinned branch to origin.
	 *
	 * Direct push requires two keys: `push_enabled=true` AND
	 * `safe_direct_push=true`. Missing either refuses — use submit() for
	 * the PR-based flow if you don't want to flip both.
	 *
	 * `--force` is honored but uses `--force-with-lease` under the hood so
	 * a concurrent upstream move isn't silently overwritten.
	 *
	 * @param string $slug  Binding slug.
	 * @param bool   $force Force push (uses --force-with-lease).
	 * @return array{success: true, slug: string, branch: string, head: ?string, message: string}|\WP_Error
	 */
	public function push( string $slug, bool $force = false ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		if ( empty( $binding->policy['push_enabled'] ) ) {
			return new \WP_Error( 'push_disabled', sprintf( 'Pushes are disabled for binding "%s" (policy.push_enabled=false).', $slug ), array( 'status' => 403 ) );
		}

		if ( empty( $binding->policy['safe_direct_push'] ) ) {
			return new \WP_Error(
				'direct_push_blocked',
				sprintf(
					'Direct push to the pinned branch is blocked for binding "%s". Set policy.safe_direct_push=true, or use submit() to open a PR instead.',
					$slug
				),
				array( 'status' => 403 )
			);
		}

		$absolute = $binding->resolveAbsolutePath();
		$repo_err = $this->assertRepo( $absolute, $slug );
		if ( is_wp_error( $repo_err ) ) {
			return $repo_err;
		}

		$current = GitRepo::branch( $absolute );
		if ( null !== $current && $current !== $binding->branch ) {
			return new \WP_Error(
				'branch_mismatch',
				sprintf(
					'Binding "%s" is pinned to "%s" but working tree is on "%s". Refusing to push.',
					$slug, $binding->branch, $current
				),
				array( 'status' => 409 )
			);
		}

		$push_url = $this->resolveAuthenticatedPushUrl( $binding );
		$flag     = $force ? '--force-with-lease ' : '';
		$cmd      = sprintf(
			'push %s%s %s',
			$flag,
			escapeshellarg( $push_url ),
			escapeshellarg( $binding->branch . ':' . $binding->branch )
		);

		$result = GitRunner::run( $absolute, $cmd );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$binding->last_commit = GitRepo::head( $absolute );
		$this->registry->save( $binding );

		return array(
			'success' => true,
			'slug'    => $slug,
			'branch'  => $binding->branch,
			'head'    => $binding->last_commit,
			'message' => trim( (string) ( $result['output'] ?? '' ) ),
		);
	}

	/**
	 * Submit local edits as a pull request.
	 *
	 * Delegates the orchestration to GitSyncSubmitter — this method is a
	 * thin wrapper so the ability callback has a short spelling.
	 *
	 * @param string $slug Binding slug.
	 * @param array<string, mixed> $args Submit args (message, paths, title, body).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function submit( string $slug, array $args ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		return ( new GitSyncSubmitter( $this->registry ) )->submit( $binding, $args );
	}

	/**
	 * Update policy fields on an existing binding.
	 *
	 * Accepts a subset of policy keys and merges them into the current
	 * policy. Validates the same way `GitSyncBinding::create()` does so
	 * bad values are refused before they hit storage.
	 *
	 * @param string               $slug  Binding slug.
	 * @param array<string, mixed> $patch Policy keys to update.
	 * @return array{success: true, slug: string, policy: array<string, mixed>}|\WP_Error
	 */
	public function updatePolicy( string $slug, array $patch ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$merged = array_merge( $binding->policy, array() );
		foreach ( $patch as $key => $value ) {
			if ( ! array_key_exists( $key, GitSyncBinding::DEFAULT_POLICY ) ) {
				return new \WP_Error( 'unknown_policy_key', sprintf( 'Unknown policy key: %s', $key ), array( 'status' => 400 ) );
			}
			$merged[ $key ] = $value;
		}

		if ( ! in_array( $merged['conflict'] ?? 'fail', GitSyncBinding::CONFLICT_STRATEGIES, true ) ) {
			return new \WP_Error(
				'invalid_conflict_strategy',
				sprintf( 'conflict must be one of: %s.', implode( ', ', GitSyncBinding::CONFLICT_STRATEGIES ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $merged['allowed_paths'] ?? null ) ) {
			return new \WP_Error( 'invalid_allowed_paths', 'allowed_paths must be an array.', array( 'status' => 400 ) );
		}

		// Safety coupling: safe_direct_push only meaningful with push_enabled.
		if ( ! empty( $merged['safe_direct_push'] ) && empty( $merged['push_enabled'] ) ) {
			return new \WP_Error(
				'policy_conflict',
				'safe_direct_push=true requires push_enabled=true.',
				array( 'status' => 400 )
			);
		}

		$binding->policy = $merged;
		$this->registry->save( $binding );

		return array(
			'success' => true,
			'slug'    => $slug,
			'policy'  => $binding->policy,
		);
	}

	/**
	 * Resolve the push URL, injecting a GitHub PAT when the remote is
	 * github.com and DMC has one registered. Non-GitHub remotes pass
	 * through unchanged and rely on the system's credential.helper.
	 *
	 * Thin wrapper around Support\GitHubRemote — the actual rewriting
	 * and host detection live there so every GitHub-aware caller
	 * (GitSync, GitSyncSubmitter, future write paths) stays consistent.
	 */
	private function resolveAuthenticatedPushUrl( GitSyncBinding $binding ): string {
		if ( ! GitHubRemote::isGitHubRemote( $binding->remote_url ) ) {
			return $binding->remote_url;
		}
		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			return $binding->remote_url;
		}
		return GitHubRemote::pushUrlWithPat( $binding->remote_url, \DataMachineCode\Abilities\GitHubAbilities::getPat() );
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Validate the binding's `local_path` against ABSPATH containment rules.
	 *
	 * Pre-write validation — the target may not exist yet, so we check the
	 * relative segments first (no `..`, no absolute prefix after stripping
	 * the leading slash) and then compute the absolute path without calling
	 * `realpath()` on a non-existent target. Existing targets get an extra
	 * `realpath`-based containment check as a belt-and-suspenders.
	 *
	 * @return string|\WP_Error Absolute path on success.
	 */
	private function validateBindingPath( GitSyncBinding $binding ): string|\WP_Error {
		$relative = ltrim( str_replace( '\\', '/', $binding->local_path ), '/' );

		if ( '' === $relative ) {
			return new \WP_Error( 'invalid_local_path', 'local_path cannot resolve to ABSPATH itself.', array( 'status' => 400 ) );
		}

		if ( PathSecurity::hasTraversal( $relative ) ) {
			return new \WP_Error( 'path_traversal', 'local_path contains traversal segments (`.`, `..`).', array( 'status' => 400 ) );
		}

		if ( PathSecurity::isSensitivePath( $relative ) ) {
			return new \WP_Error( 'sensitive_path', sprintf( 'Refusing to bind sensitive path: %s', $relative ), array( 'status' => 403 ) );
		}

		$abspath  = rtrim( ABSPATH, '/' );
		$absolute = $abspath . '/' . rtrim( $relative, '/' );

		// If it already exists, confirm it really resolves under ABSPATH via
		// realpath (defends against symlink escapes).
		if ( is_dir( $absolute ) ) {
			$validation = PathSecurity::validateContainment( $absolute, $abspath );
			if ( ! $validation['valid'] ) {
				return new \WP_Error(
					'path_outside_abspath',
					sprintf( 'local_path resolves outside ABSPATH: %s', $validation['message'] ?? '' ),
					array( 'status' => 403 )
				);
			}
			$absolute = $validation['real_path'];
		}

		return $absolute;
	}

	/**
	 * Either clone the remote into `$absolute` or adopt an existing checkout.
	 *
	 * Returns `['adopted' => bool]` on success so the caller can report
	 * whether the working tree was materialized fresh or already present.
	 *
	 * @return array{adopted: bool}|\WP_Error
	 */
	private function materializeClone( GitSyncBinding $binding, string $absolute ): array|\WP_Error {
		$git_path = $absolute . '/.git';
		$exists   = is_dir( $absolute );

		if ( $exists && ( is_dir( $git_path ) || is_file( $git_path ) ) ) {
			$remote = GitRunner::run( $absolute, 'config --get remote.origin.url' );
			if ( is_wp_error( $remote ) ) {
				return $remote;
			}
			$current_origin = trim( (string) $remote['output'] );
			if ( $current_origin !== $binding->remote_url ) {
				return new \WP_Error(
					'origin_mismatch',
					sprintf(
						'Existing checkout at "%s" has origin "%s", but binding expects "%s". Refusing to adopt.',
						$absolute,
						$current_origin,
						$binding->remote_url
					),
					array( 'status' => 409 )
				);
			}

			// Optional: verify branch alignment. Not fatal (caller can pull/fetch
			// to re-align), but we warn by returning success so the CLI can report.
			return array( 'adopted' => true );
		}

		if ( $exists && ! $this->isDirEmpty( $absolute ) ) {
			return new \WP_Error(
				'dirty_target',
				sprintf(
					'Target "%s" exists and is non-empty without a .git directory. Refusing to clone over it.',
					$absolute
				),
				array( 'status' => 409 )
			);
		}

		// Create parent directory if missing.
		$parent = dirname( $absolute );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return new \WP_Error( 'parent_mkdir_failed', sprintf( 'Failed to create parent directory: %s', $parent ), array( 'status' => 500 ) );
		}

		$cmd = sprintf(
			'git clone --branch %s %s %s 2>&1',
			escapeshellarg( $binding->branch ),
			escapeshellarg( $binding->remote_url ),
			escapeshellarg( $absolute )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $cmd, $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return new \WP_Error(
				'clone_failed',
				sprintf( 'Git clone failed (exit %d): %s', $exit_code, implode( "\n", $output ) ),
				array( 'status' => 500 )
			);
		}

		return array( 'adopted' => false );
	}

	private function isDirEmpty( string $path ): bool {
		$handle = @opendir( $path );
		if ( false === $handle ) {
			return false;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				closedir( $handle );
				return false;
			}
		}
		closedir( $handle );
		return true;
	}

	private function assertRepo( string $absolute, string $slug ): ?\WP_Error {
		if ( ! is_dir( $absolute ) ) {
			return new \WP_Error( 'missing_working_tree', sprintf( 'Binding "%s" has no working tree on disk at %s.', $slug, $absolute ), array( 'status' => 404 ) );
		}
		$git_path = $absolute . '/.git';
		if ( ! is_dir( $git_path ) && ! is_file( $git_path ) ) {
			return new \WP_Error( 'not_a_repo', sprintf( 'Binding "%s" is not a git repository (no .git at %s).', $slug, $absolute ), array( 'status' => 409 ) );
		}
		return null;
	}

	private function notFound( string $slug ): \WP_Error {
		return new \WP_Error(
			'binding_not_found',
			sprintf( 'No GitSync binding with slug "%s".', $slug ),
			array( 'status' => 404 )
		);
	}
}
