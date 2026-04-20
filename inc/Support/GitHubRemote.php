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
	 * Rewrite an https://github.com/... URL to include a PAT for auth.
	 *
	 * Non-https URLs (git@ SSH, file://, non-GitHub) pass through untouched
	 * — SSH uses ssh-agent, file:// has no auth surface, and non-GitHub
	 * hosts should rely on system credential.helper.
	 *
	 * The PAT is rawurlencoded before injection so exotic token characters
	 * don't break URL parsing downstream.
	 */
	public static function pushUrlWithPat( string $url, string $pat ): string {
		if ( '' === $pat ) {
			return $url;
		}
		if ( ! str_starts_with( $url, 'https://github.com/' ) ) {
			return $url;
		}
		return 'https://' . rawurlencode( $pat ) . '@' . substr( $url, strlen( 'https://' ) );
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
