<?php
/**
 * GitHub Remote
 *
 * One place for GitHub-specific repository URL manipulation:
 *   - detect supported GitHub/GitHub Enterprise remotes
 *   - parse owner/repo/host descriptors out of clone or web URLs
 *   - render clone, web, and API URLs from the same descriptor
 *
 * @package DataMachineCode\Support
 * @since   0.7.0
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class GitHubRemote {

	public const PUBLIC_WEB_BASE_URL = 'https://github.com';
	public const PUBLIC_API_BASE_URL = 'https://api.github.com';
	public const PUBLIC_SSH_HOST     = 'github.com';


	/**
	 * Detect a supported GitHub remote. Matches public GitHub and
	 * GitHub Enterprise-style hosts such as github.a8c.com.
	 */
	public static function isGitHubRemote( string $url ): bool {
		return null !== self::descriptor($url);
	}

	/**
	 * Build a repository descriptor from a slug, clone URL, or web URL.
	 *
	 * @return array{
	 *     host:string,
	 *     web_base_url:string,
	 *     api_base_url:string,
	 *     ssh_host:string,
	 *     owner:string,
	 *     repo:string,
	 *     slug:string,
	 *     https_clone_url:string,
	 *     ssh_clone_url:string,
	 *     web_url:string
	 * }|null
	 */
	public static function descriptor( string $remote_or_slug ): ?array {
		$value = trim($remote_or_slug);
		if ( '' === $value ) {
			return null;
		}

		$host  = self::PUBLIC_SSH_HOST;
		$owner = '';
		$repo  = '';

		if ( preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $value, $m) ) {
			$owner = $m[1];
			$repo  = $m[2];
		} elseif ( preg_match('#^https?://([^/]+)/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?(?:/.*)?$#', $value, $m) ) {
			$host  = strtolower($m[1]);
			$owner = $m[2];
			$repo  = $m[3];
		} elseif ( preg_match('#^(?:ssh://)?git@([^:/]+)[:/]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $value, $m) ) {
			$host  = strtolower($m[1]);
			$owner = $m[2];
			$repo  = $m[3];
		} else {
			return null;
		}

		if ( ! self::isGitHubHost($host) || '' === $owner || '' === $repo ) {
			return null;
		}

		$repo = preg_replace('/\.git$/', '', $repo) ?? $repo;
		$slug = $owner . '/' . $repo;

		return array(
			'host'            => $host,
			'web_base_url'    => self::webBaseUrl($host),
			'api_base_url'    => self::apiBaseUrl($host),
			'ssh_host'        => $host,
			'owner'           => $owner,
			'repo'            => $repo,
			'slug'            => $slug,
			'https_clone_url' => self::webBaseUrl($host) . '/' . $slug . '.git',
			'ssh_clone_url'   => 'git@' . $host . ':' . $slug . '.git',
			'web_url'         => self::webBaseUrl($host) . '/' . $slug,
		);
	}

	/**
	 * Extract `owner/repo` from a GitHub URL or slug.
	 *
	 * Accepts both `https://github.com/owner/repo(.git)(/)?` and
	 * `git@github.com:owner/repo(.git)`. Returns null for any URL that
	 * isn't recognizable as GitHub, or where owner/repo can't be cleanly
	 * extracted — callers must defend against null.
	 *
	 * @return string|null `owner/repo` or null.
	 */
	public static function slug( string $url ): ?string {
		$descriptor = self::descriptor($url);
		return null !== $descriptor ? $descriptor['slug'] : null;
	}

	/**
	 * Build a clone URL from a GitHub descriptor input.
	 */
	public static function cloneUrl( string $remote_or_slug, string $protocol = 'https' ): ?string {
		$descriptor = self::descriptor($remote_or_slug);
		if ( null === $descriptor ) {
			return null;
		}

		return 'ssh' === $protocol ? $descriptor['ssh_clone_url'] : $descriptor['https_clone_url'];
	}

	/**
	 * Build a GitHub web URL for a repo, optionally under a path.
	 */
	public static function webUrl( string $remote_or_slug, string $path = '' ): ?string {
		$descriptor = self::descriptor($remote_or_slug);
		if ( null === $descriptor ) {
			return null;
		}

		if ( '' === $path ) {
			return $descriptor['web_url'];
		}

		return $descriptor['web_url'] . '/' . ltrim($path, '/');
	}

	/**
	 * Build a GitHub branch web URL for a repo.
	 */
	public static function branchUrl( string $remote_or_slug, string $branch ): ?string {
		return self::webUrl($remote_or_slug, 'tree/' . rawurlencode($branch));
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
	public static function apiUrl( string $remote_or_slug, string $path = '' ): string {
		$descriptor = self::descriptor($remote_or_slug);
		$slug       = null !== $descriptor ? $descriptor['slug'] : $remote_or_slug;
		$base       = ( null !== $descriptor ? $descriptor['api_base_url'] : self::PUBLIC_API_BASE_URL ) . '/repos/' . $slug;
		if ( '' === $path ) {
			return $base;
		}
		return $base . '/' . ltrim($path, '/');
	}

	/**
	 * Build a GitHub REST API URL not scoped to a repository.
	 */
	public static function apiBaseUrl( string $host = self::PUBLIC_SSH_HOST ): string {
		return self::PUBLIC_SSH_HOST === strtolower($host) ? self::PUBLIC_API_BASE_URL : self::webBaseUrl($host) . '/api/v3';
	}

	private static function webBaseUrl( string $host ): string {
		return 'https://' . strtolower($host);
	}

	private static function isGitHubHost( string $host ): bool {
		$host = strtolower($host);
		return self::PUBLIC_SSH_HOST === $host || str_starts_with($host, 'github.');
	}
}
