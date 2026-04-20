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

		$binding->last_commit = $this->readHead( $absolute );
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

		$previous_head = $this->readHead( $absolute );
		$dirty_count   = $this->countDirty( $absolute );
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
		$current_branch = $this->readBranch( $absolute );
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

		$new_head             = $this->readHead( $absolute );
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

		$data['branch'] = $this->readBranch( $absolute );
		$data['head']   = $this->readHead( $absolute );
		$data['dirty']  = $this->countDirty( $absolute );

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

	private function readHead( string $absolute ): ?string {
		$result = GitRunner::run( $absolute, 'rev-parse --short HEAD' );
		if ( is_wp_error( $result ) ) {
			return null;
		}
		$head = trim( (string) $result['output'] );
		return '' === $head ? null : $head;
	}

	private function readBranch( string $absolute ): ?string {
		$result = GitRunner::run( $absolute, 'rev-parse --abbrev-ref HEAD' );
		if ( is_wp_error( $result ) ) {
			return null;
		}
		$branch = trim( (string) $result['output'] );
		return '' === $branch ? null : $branch;
	}

	private function countDirty( string $absolute ): int {
		$result = GitRunner::run( $absolute, 'status --porcelain' );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $result['output'] ) ) );
		return count( $lines );
	}

	private function notFound( string $slug ): \WP_Error {
		return new \WP_Error(
			'binding_not_found',
			sprintf( 'No GitSync binding with slug "%s".', $slug ),
			array( 'status' => 404 )
		);
	}
}
