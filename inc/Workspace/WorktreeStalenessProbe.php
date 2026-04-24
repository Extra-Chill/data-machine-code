<?php
/**
 * Worktree Staleness Probe
 *
 * Helper for `Workspace::worktree_add()` to always fetch remote refs first
 * and compute how far behind upstream the new worktree's branch (or the
 * local base it was cut from) is. The result is surfaced verbatim in the
 * ability response and CLI output so agents can decide whether to rebase
 * before cooking more changes on a stale base.
 *
 * Design choices:
 *
 * - `fetch()` never aborts the caller. Network failures, missing `origin`
 *   remote, or transient DNS hiccups are logged into the result so the
 *   agent knows staleness data is untrustworthy, but the worktree is still
 *   created.
 * - `behind_count()` returns null when no upstream is configured (fresh
 *   local branch with no tracking). Callers MUST distinguish null from 0 —
 *   0 means "up to date", null means "cannot tell".
 * - All git invocations route through `GitRunner` so stderr handling and
 *   exit-code semantics stay consistent with the rest of the plugin.
 *
 * @package DataMachineCode\Workspace
 * @since   0.7.x
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitRunner;

defined( 'ABSPATH' ) || exit;

final class WorktreeStalenessProbe {

	/**
	 * Fetch `origin` in a repository. Returns structured result instead of
	 * throwing so the caller can decide whether to continue on failure.
	 *
	 * @param string $repo_path Primary repo path (passed to `git -C`).
	 * @return array{ok: bool, error?: string}
	 */
	public static function fetch( string $repo_path ): array {
		$result = GitRunner::run( $repo_path, 'fetch --quiet origin' );
		if ( is_wp_error( $result ) ) {
			$data  = $result->get_error_data();
			$tail  = is_array( $data ) && isset( $data['output'] ) ? trim( (string) $data['output'] ) : '';
			$error = '' !== $tail ? $tail : $result->get_error_message();
			return array(
				'ok'    => false,
				'error' => $error,
			);
		}
		return array( 'ok' => true );
	}

	/**
	 * Count commits `$ref` is behind `$upstream` via `git rev-list --count`.
	 *
	 * Returns an integer ≥ 0 on success, null when `$upstream` is not
	 * configured / does not exist, and a WP_Error on any other git failure
	 * so the caller can surface it without conflating "no upstream" with
	 * "command errored".
	 *
	 * @param string $repo_path Repository path (worktree path or primary).
	 * @param string $ref       Left-hand revision (e.g. current branch name).
	 * @param string $upstream  Right-hand revision (e.g. `@{upstream}` or `origin/main`).
	 * @return int|null|\WP_Error
	 */
	public static function behind_count( string $repo_path, string $ref, string $upstream ): int|null|\WP_Error {
		$args   = sprintf( 'rev-list --count %s..%s', escapeshellarg( $ref ), escapeshellarg( $upstream ) );
		$result = GitRunner::run( $repo_path, $args );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$out  = is_array( $data ) && isset( $data['output'] ) ? (string) $data['output'] : '';

			// Missing upstream configuration. Treated as "unknown", not an error.
			if ( self::is_missing_upstream( $out ) ) {
				return null;
			}
			return $result;
		}

		$count = self::parse_count( (string) ( $result['output'] ?? '' ) );
		return $count;
	}

	/**
	 * Parse a `rev-list --count` stdout payload into an int. Tolerant of
	 * trailing whitespace / empty output (returns 0 for empty, matching git).
	 *
	 * @param string $output Raw stdout.
	 * @return int
	 */
	public static function parse_count( string $output ): int {
		$trimmed = trim( $output );
		if ( '' === $trimmed ) {
			return 0;
		}
		if ( ! preg_match( '/^\d+$/', $trimmed ) ) {
			return 0;
		}
		return (int) $trimmed;
	}

	/**
	 * Heuristic: does a git error blob signal "no upstream configured" rather
	 * than a real failure? Matches the common phrasings git uses across
	 * versions.
	 *
	 * @param string $output Git stderr/stdout.
	 * @return bool
	 */
	public static function is_missing_upstream( string $output ): bool {
		$needles = array(
			'no upstream configured',
			'unknown revision',
			'bad revision',
			'ambiguous argument',
			'No such ref',
			'Needed a single revision',
		);
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $output, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
