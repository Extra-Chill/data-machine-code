<?php
/**
 * GitHub Remote
 *
 * One place for GitHub-specific URL manipulation:
 *   - "is this a GitHub remote?"
 *   - "parse owner/repo out of the URL"
 *   - "rewrite the URL with a PAT injected for authenticated push"
 *
 * Shared by GitSync (push + submit) and Workspace (merge-signal lookup)
 * so the regex + host-detection logic has a single source of truth.
 *
 * @package DataMachineCode\Support
 * @since   0.7.0
 */

namespace DataMachineCode\Support;

defined( 'ABSPATH' ) || exit;

final class GitHubRemote {

	/**
	 * Detect a GitHub remote. Matches https://github.com/... and
	 * git@github.com:... Both forms count.
	 */
	public static function isGitHubRemote( string $url ): bool {
		return (bool) preg_match( '#(https?://github\.com/|git@github\.com:)#', $url );
	}

	/**
	 * Extract `owner/repo` from a GitHub URL.
	 *
	 * Accepts both `https://github.com/owner/repo(.git)(/)?` and
	 * `git@github.com:owner/repo(.git)`. Returns null for any URL that
	 * isn't recognizable as GitHub, or where owner/repo can't be cleanly
	 * extracted — callers must defend against null.
	 *
	 * @return string|null `owner/repo` or null.
	 */
	public static function slug( string $url ): ?string {
		if ( preg_match( '#github\.com[:/]([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#', $url, $m ) ) {
			return $m[1] . '/' . $m[2];
		}
		return null;
	}

	/**
	 * Compute a git-blob SHA for a string of content.
	 *
	 * Git hashes blobs as `sha1("blob " + len + "\0" + content)`. Matching
	 * this lets us compare local files against the `sha` field GitHub's
	 * tree API returns without needing a git binary, so both read (Fetcher
	 * deciding which files to download) and write (Proposer deciding
	 * which files to upload) can short-circuit when SHAs already match.
	 */
	public static function blobSha( string $content ): string {
		return sha1( 'blob ' . strlen( $content ) . "\0" . $content );
	}

	/**
	 * Fetch a branch's recursive tree and flatten it into a path → sha map.
	 *
	 * Pulls `GET /repos/:slug/git/trees/:branch?recursive=1`, skips
	 * non-blob entries and paths containing traversal segments, and
	 * returns the blob map plus metadata (raw tree SHA + truncation flag).
	 *
	 * Used by both Fetcher (pull) and Proposer (submit/push context) so
	 * the tree-fetch-and-flatten step has one implementation.
	 *
	 * **Why this exists next to `GitHubAbilities::getRepoTree`.** That
	 * ability returns the normalized `{success, files, count}` shape
	 * designed for MCP / REST callers, and strips `tree_sha` + the
	 * `truncated` flag during normalization. GitSync needs both: the
	 * tree SHA seeds `binding->last_commit` so we can detect upstream
	 * drift between pulls, and the truncation flag drives a CLI warning
	 * when the repo is too large for a single-response tree. Keeping
	 * this helper alongside the ability (rather than widening the
	 * ability's output schema and churning a public contract) is the
	 * smaller blast radius.
	 *
	 * @return array{blobs: array<string, string>, tree_sha: string, truncated: bool}|\WP_Error
	 */
	public static function fetchTree( string $slug, string $branch, string $pat ): array|\WP_Error {
		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			return new \WP_Error( 'github_abilities_unavailable', 'GitHubAbilities class is not loaded.', array( 'status' => 500 ) );
		}

		$response = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			self::apiUrl( $slug, 'git/trees/' . rawurlencode( $branch ) ),
			array( 'recursive' => '1' ),
			$pat
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data      = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$tree_sha  = (string) ( $data['sha'] ?? '' );
		$truncated = ! empty( $data['truncated'] );
		$blobs     = array();

		foreach ( (array) ( $data['tree'] ?? array() ) as $entry ) {
			if ( 'blob' !== ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$path = (string) ( $entry['path'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			// Defense-in-depth: refuse traversal even though GitHub would
			// never ship such a path. Keeping the filter here means both
			// Fetcher and Proposer inherit it without having to remember.
			if ( PathSecurity::hasTraversal( $path ) ) {
				continue;
			}
			$blobs[ $path ] = (string) ( $entry['sha'] ?? '' );
		}

		return array(
			'blobs'     => $blobs,
			'tree_sha'  => $tree_sha,
			'truncated' => $truncated,
		);
	}

	/**
	 * Build a GitHub REST API URL for a repo.
	 *
	 *   apiUrl('foo/bar')                 → https://api.github.com/repos/foo/bar
	 *   apiUrl('foo/bar', 'pulls')        → https://api.github.com/repos/foo/bar/pulls
	 *   apiUrl('foo/bar', 'pulls/42')     → https://api.github.com/repos/foo/bar/pulls/42
	 *
	 * `$slug` is expected to be validated ahead of time (output of
	 * `slug()` or a value the caller already trusts). No sanitization
	 * is attempted here beyond string concatenation.
	 */
	public static function apiUrl( string $slug, string $path = '' ): string {
		$base = 'https://api.github.com/repos/' . $slug;
		if ( '' === $path ) {
			return $base;
		}
		return $base . '/' . ltrim( $path, '/' );
	}
}
