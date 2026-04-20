<?php
/**
 * Git Repo
 *
 * Read-only helpers for inspecting a local working tree — HEAD, branch,
 * dirty count. Shared by GitSync + GitSyncSubmitter so these three
 * operations have one canonical shell invocation.
 *
 * Parallel to Support/GitRunner (which wraps `git -C <path> <args>`):
 * this class builds on GitRunner for the narrow set of queries GitSync
 * needs repeatedly, returning plain scalars instead of the runner's
 * `{success, output}` envelope.
 *
 * @package DataMachineCode\GitSync
 * @since   0.7.0
 */

namespace DataMachineCode\GitSync;

use DataMachineCode\Support\GitRunner;

defined( 'ABSPATH' ) || exit;

final class GitRepo {

	/**
	 * Short HEAD SHA, or null on any failure.
	 *
	 * Explicitly tolerates errors — callers use this to annotate
	 * bindings with their current commit for the registry, and a null
	 * value there just means "we couldn't read it this time."
	 */
	public static function head( string $path ): ?string {
		$result = GitRunner::run( $path, 'rev-parse --short HEAD' );
		if ( is_wp_error( $result ) ) {
			return null;
		}
		$head = trim( (string) $result['output'] );
		return '' === $head ? null : $head;
	}

	/**
	 * Current branch name, or null on failure/detached HEAD.
	 *
	 * `git rev-parse --abbrev-ref HEAD` returns `HEAD` for detached
	 * checkouts; callers treat that as null here so a detached tree
	 * doesn't silently pass branch-match checks.
	 */
	public static function branch( string $path ): ?string {
		$result = GitRunner::run( $path, 'rev-parse --abbrev-ref HEAD' );
		if ( is_wp_error( $result ) ) {
			return null;
		}
		$branch = trim( (string) $result['output'] );
		if ( '' === $branch || 'HEAD' === $branch ) {
			return null;
		}
		return $branch;
	}

	/**
	 * Count entries in `git status --porcelain`.
	 *
	 * Zero on any error (shell-out failed, path isn't a repo, etc.) —
	 * the conservative default that keeps callers from treating a
	 * readback failure as "dirty."
	 */
	public static function dirtyCount( string $path ): int {
		$result = GitRunner::run( $path, 'status --porcelain' );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		return count( array_filter( array_map( 'trim', explode( "\n", (string) $result['output'] ) ) ) );
	}
}
