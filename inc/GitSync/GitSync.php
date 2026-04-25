<?php
/**
 * GitSync
 *
 * API-first primitive for syncing site-owned directories with GitHub
 * repositories. Uses the GitHub Contents + Git Data APIs instead of a
 * local git binary, so bindings work identically on self-hosted,
 * managed hosts (WordPress.com Business, WP Engine, Pantheon, etc.)
 * and local dev.
 *
 * Scope:
 *   - bind/unbind   → register/remove a binding (no disk work done at
 *                     bind time; the first pull materializes files).
 *   - list/status   → read-only queries over the registry.
 *   - pull          → delegates to GitSyncFetcher.
 *   - submit        → delegates to GitSyncProposer::submit (PR flow).
 *   - push          → delegates to GitSyncProposer::push (direct-to-pinned).
 *   - updatePolicy  → modify policy fields on an existing binding.
 *
 * Deliberately smaller than the shell-git iteration: no staging (`add`,
 * `commit`) — consumers write files to disk and call submit/push
 * directly. The API flow has no separate stage step.
 *
 * @package DataMachineCode\GitSync
 * @since   0.8.0
 * @see     https://github.com/Extra-Chill/data-machine-code/issues/38
 */

namespace DataMachineCode\GitSync;

use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

final class GitSync {

	private GitSyncRegistry $registry;

	public function __construct( ?GitSyncRegistry $registry = null ) {
		$this->registry = $registry ?? new GitSyncRegistry();
	}

	// =========================================================================
	// Registry operations
	// =========================================================================

	/**
	 * Register a new binding.
	 *
	 * Bind does NOT materialize files — it just records the binding. The
	 * first `pull` call fetches upstream content to disk. This lets
	 * managed-host installs bind bindings in wp-admin without blocking
	 * on a potentially slow tree-wide download.
	 *
	 * @param array<string, mixed> $input See GitSyncBinding::create().
	 * @return array<string, mixed>|\WP_Error
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

		$path_err = $this->validateLocalPath( $binding );
		if ( is_wp_error( $path_err ) ) {
			return $path_err;
		}

		$this->registry->save( $binding );

		return array(
			'success' => true,
			'binding' => $binding->toArray(),
			'message' => sprintf( 'Bound "%s" → %s. Run `gitsync pull %s` to materialize files.', $binding->slug, $binding->remote_url, $binding->slug ),
		);
	}

	/**
	 * Remove a binding.
	 *
	 * Directory is preserved by default — the binding is metadata, not
	 * ownership of the filesystem. `$purge = true` removes the directory
	 * too, with a re-validated realpath containment check right before
	 * `rm -rf` to defend against symlink escapes.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function unbind( string $slug, bool $purge = false ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$absolute = $binding->resolveAbsolutePath();
		$purged   = false;

		if ( $purge && is_dir( $absolute ) ) {
			$abspath    = rtrim( ABSPATH, '/' );
			$validation = PathSecurity::validateContainment( $absolute, $abspath );
			if ( ! $validation['valid'] ) {
				return new \WP_Error(
					'path_outside_abspath',
					sprintf( 'Refusing to purge "%s": %s', $absolute, $validation['message'] ?? 'containment failed' ),
					array( 'status' => 403 )
				);
			}

			// Native PHP recursion so this works on managed hosts where
			// exec() is disabled. RecursiveIteratorIterator with
			// CHILD_FIRST lets unlink/rmdir happen depth-first.
			$purge_err = $this->removeTree( $validation['real_path'] );
			if ( is_wp_error( $purge_err ) ) {
				return $purge_err;
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

	public function status( string $slug ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$absolute = $binding->resolveAbsolutePath();
		return array(
			'success'        => true,
			'slug'           => $slug,
			'local_path'     => $absolute,
			'remote_url'     => $binding->remote_url,
			'tracked_branch' => $binding->branch,
			'exists'         => is_dir( $absolute ),
			'last_pulled'    => $binding->last_pulled,
			'last_commit'    => $binding->last_commit,
			'pulled_count'   => count( $binding->pulled_paths ),
			'policy'         => $binding->policy,
		);
	}

	public function list_bindings(): array {
		$out = array();
		foreach ( $this->registry->all() as $binding ) {
			$absolute = $binding->resolveAbsolutePath();
			$out[]    = array(
				'slug'          => $binding->slug,
				'local_path'    => $binding->local_path,
				'absolute_path' => $absolute,
				'remote_url'    => $binding->remote_url,
				'branch'        => $binding->branch,
				'exists'        => is_dir( $absolute ),
				'write_enabled' => (bool) ( $binding->policy['write_enabled'] ?? false ),
				'push_enabled'  => (bool) ( $binding->policy['safe_direct_push'] ?? false ),
				'last_pulled'   => $binding->last_pulled,
				'last_commit'   => $binding->last_commit,
				'pulled_count'  => count( $binding->pulled_paths ),
			);
		}
		return array(
			'success'  => true,
			'bindings' => $out,
		);
	}

	// =========================================================================
	// Delegating operations
	// =========================================================================

	public function pull( string $slug, array $args = array() ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}
		return ( new GitSyncFetcher( $this->registry ) )->pull( $binding, $args );
	}

	public function submit( string $slug, array $args ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}
		return ( new GitSyncProposer( $this->registry ) )->submit( $binding, $args );
	}

	public function push( string $slug, array $args ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}
		return ( new GitSyncProposer( $this->registry ) )->push( $binding, $args );
	}

	/**
	 * Update a subset of policy fields.
	 *
	 * Whitelisted keys only; unknown keys refused. Same validation
	 * applied at bind time runs here so bad states never reach storage.
	 */
	public function updatePolicy( string $slug, array $patch ): array|\WP_Error {
		$binding = $this->registry->get( $slug );
		if ( null === $binding ) {
			return $this->notFound( $slug );
		}

		$merged = $binding->policy;
		foreach ( $patch as $key => $value ) {
			if ( ! array_key_exists( $key, GitSyncBinding::DEFAULT_POLICY ) ) {
				return new \WP_Error( 'unknown_policy_key', sprintf( 'Unknown policy key: %s', $key ), array( 'status' => 400 ) );
			}
			$merged[ $key ] = $value;
		}

		$validation = GitSyncBinding::validatePolicy( $merged );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$binding->policy = $merged;
		$this->registry->save( $binding );

		return array(
			'success' => true,
			'slug'    => $slug,
			'policy'  => $binding->policy,
		);
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Pre-bind validation of a local path.
	 *
	 * The path doesn't have to exist yet (pull materializes it), so we
	 * can't rely on realpath containment. Check segments instead: must
	 * be leading-slash, no traversal, not sensitive.
	 */
	private function validateLocalPath( GitSyncBinding $binding ): true|\WP_Error {
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

		// If the target already exists, run the symlink-safe check too.
		$abspath  = rtrim( ABSPATH, '/' );
		$absolute = $abspath . '/' . rtrim( $relative, '/' );
		if ( is_dir( $absolute ) ) {
			$containment = PathSecurity::validateContainment( $absolute, $abspath );
			if ( ! $containment['valid'] ) {
				return new \WP_Error( 'path_outside_abspath', sprintf( 'local_path resolves outside ABSPATH: %s', $containment['message'] ?? '' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Recursively remove a directory using native PHP — no shell.
	 *
	 * `$path` is expected to have already cleared `validateContainment`
	 * by the caller; this method trusts that and only performs the
	 * recursive unlink/rmdir. Returns a WP_Error if any step fails,
	 * leaving the partially-removed tree behind for the admin to
	 * inspect rather than attempting further cleanup.
	 */
	private function removeTree( string $path ): true|\WP_Error {
		if ( ! is_dir( $path ) ) {
			return true;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $entry ) {
			/** @var \SplFileInfo $entry */
			$target = $entry->getPathname();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Recursive purge inside a policy-gated local binding.
			$ok = $entry->isDir() ? @rmdir( $target ) : @unlink( $target );
			if ( ! $ok ) {
				return new \WP_Error(
					'purge_failed',
					sprintf( 'Failed to remove %s during purge of %s.', $target, $path ),
					array( 'status' => 500 )
				);
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive purge inside a policy-gated local binding.
		if ( ! @rmdir( $path ) ) {
			return new \WP_Error(
				'purge_failed',
				sprintf( 'Failed to remove top-level directory %s.', $path ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	private function notFound( string $slug ): \WP_Error {
		return new \WP_Error(
			'binding_not_found',
			sprintf( 'No GitSync binding with slug "%s".', $slug ),
			array( 'status' => 404 )
		);
	}
}
