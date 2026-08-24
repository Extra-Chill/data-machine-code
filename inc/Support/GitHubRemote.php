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

use DataMachineCode\Workspace\RemoteWorkspaceBackend;

defined('ABSPATH') || exit;

final class GitHubRemote {

	public const PUBLIC_WEB_BASE_URL = 'https://github.com';
	public const PUBLIC_API_BASE_URL = 'https://api.github.com';
	public const PUBLIC_SSH_HOST     = 'github.com';


	/**
	 * Detect a supported GitHub remote.
	 *
	 * GitHub.com is always supported. Explicit credential-profile hosts and the
	 * `datamachine_code_github_allowed_hosts` filter authorize a whole host. A
	 * configured repository reference authorizes only its parsed host/owner/repo
	 * identity; an SSH remote alone never classifies an arbitrary service as GitHub.
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
	 *     ssh_port:int|null,
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

		$identity = self::parseRemote($value);
		if ( null === $identity || ! self::isAuthorized($identity) ) {
			return null;
		}

		$host     = $identity['host'];
		$owner    = $identity['owner'];
		$repo     = $identity['repo'];
		$ssh_port = $identity['ssh_port'];
		$slug     = $owner . '/' . $repo;

		return array(
			'host'            => $host,
			'web_base_url'    => self::webBaseUrl($host),
			'api_base_url'    => self::apiBaseUrl($host),
			'ssh_host'        => $host,
			'ssh_port'        => $ssh_port,
			'owner'           => $owner,
			'repo'            => $repo,
			'slug'            => $slug,
			'https_clone_url' => self::webBaseUrl($host) . '/' . $slug . '.git',
			'ssh_clone_url'   => null === $ssh_port ? 'git@' . $host . ':' . $slug . '.git' : sprintf('ssh://git@%s:%d/%s.git', $host, $ssh_port, $slug),
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

	/**
	 * Classify a parsed remote against host-wide and repository-specific authority.
	 *
	 * @param array{host:string,owner:string,repo:string,ssh_port:int|null} $identity
	 */
	private static function isAuthorized( array $identity ): bool {
		$hosts = self::configuredHosts();
		if ( function_exists('apply_filters') ) {
			$hosts = apply_filters('datamachine_code_github_allowed_hosts', $hosts);
		}

		if ( ! is_array($hosts) ) {
			return false;
		}

		foreach ( $hosts as $allowed_host ) {
			if ( is_string($allowed_host) && strtolower(trim($allowed_host)) === $identity['host'] ) {
				return true;
			}
		}

		foreach ( self::configuredRepositories() as $repository ) {
			if ( self::sameIdentity($identity, $repository) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return hosts explicitly authorized by persisted DMC configuration.
	 *
	 * Only explicit profile `host` values authorize every repository on a host.
	 *
	 * @return array<int,string>
	 */
	private static function configuredHosts(): array {
		$profiles = self::configuredSetting('github_credential_profiles', array());
		$hosts    = array( self::PUBLIC_SSH_HOST );
		if ( is_array($profiles) ) {
			foreach ( $profiles as $profile ) {
				if ( ! is_array($profile) ) {
					continue;
				}
				$host = self::hostFromConfiguredReference($profile['host'] ?? '');
				if ( null !== $host ) {
					$hosts[] = $host;
				}
			}
		}

		return array_values(array_unique($hosts));
	}

	/**
	 * Return repository identities explicitly named by DMC configuration.
	 *
	 * @return array<int,array{host:string,owner:string,repo:string,ssh_port:int|null}>
	 */
	private static function configuredRepositories(): array {
		$references = array( self::configuredSetting('github_default_repo', '') );
		$profiles   = self::configuredSetting('github_credential_profiles', array());
		if ( is_array($profiles) ) {
			foreach ( $profiles as $profile ) {
				if ( ! is_array($profile) ) {
					continue;
				}
				$references[] = $profile['default_repo'] ?? '';
				if ( isset($profile['allowed_repos']) && is_array($profile['allowed_repos']) ) {
					$references = array_merge($references, $profile['allowed_repos']);
				}
			}
		}

		if ( ! class_exists(RemoteWorkspaceBackend::class) ) {
			require_once dirname(__DIR__) . '/Workspace/RemoteWorkspaceBackend.php';
		}
		$state = function_exists('get_option') ? get_option(RemoteWorkspaceBackend::OPTION, array()) : array();
		if ( is_array($state) && isset($state['repos']) && is_array($state['repos']) ) {
			foreach ( $state['repos'] as $repository ) {
				if ( is_array($repository) ) {
					$references[] = $repository['url'] ?? '';
					$references[] = $repository['remote'] ?? '';
				}
			}
		}

		$repositories = array();
		foreach ( $references as $reference ) {
			$identity = self::parseRemote($reference);
			if ( null !== $identity ) {
				$repositories[] = $identity;
			}
		}

		return $repositories;
	}

	private static function configuredSetting( string $key, mixed $fallback ): mixed {
		if ( function_exists('apply_filters') ) {
			if ( ! class_exists(PluginSettings::class) ) {
				require_once __DIR__ . '/PluginSettings.php';
			}
			return PluginSettings::get($key, $fallback);
		}

		return function_exists('get_option') ? get_option($key, $fallback) : $fallback;
	}

	private static function hostFromConfiguredReference( mixed $reference ): ?string {
		if ( ! is_string($reference) ) {
			return null;
		}

		$reference = trim($reference);
		if ( '' === $reference ) {
			return null;
		}
		if ( 1 === preg_match('#^(?:https?|ssh)://(?:[^@/]+@)?([A-Za-z0-9.-]+)(?::\d+)?(?:/|$)#i', $reference, $matches) ) {
			return strtolower($matches[1]);
		}
		if ( 1 === preg_match('#^[^@\s]+@([A-Za-z0-9.-]+):#', $reference, $matches) ) {
			return strtolower($matches[1]);
		}
		if ( 1 === preg_match('#^[A-Za-z0-9.-]+$#', $reference) ) {
			return strtolower($reference);
		}

		return null;
	}

	/**
	 * Parse a supported repository URL without authorizing its host.
	 *
	 * @return array{host:string,owner:string,repo:string,ssh_port:int|null}|null
	 */
	private static function parseRemote( mixed $reference ): ?array {
		if ( ! is_string($reference) ) {
			return null;
		}

		$value    = trim($reference);
		$host     = self::PUBLIC_SSH_HOST;
		$owner    = '';
		$repo     = '';
		$ssh_port = null;
		if ( preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $value, $m) ) {
			$owner = $m[1];
			$repo  = $m[2];
		} elseif ( preg_match('#^https?://([A-Za-z0-9.-]+)(?::\d+)?/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?(?:/.*)?$#', $value, $m) ) {
			$host  = strtolower($m[1]);
			$owner = $m[2];
			$repo  = $m[3];
		} elseif ( preg_match('#^ssh://git@([A-Za-z0-9.-]+)(?::([1-9]\d{0,4}))?/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $value, $m) ) {
			$host     = strtolower($m[1]);
			$ssh_port = '' !== $m[2] ? (int) $m[2] : null;
			$owner    = $m[3];
			$repo     = $m[4];
		} elseif ( preg_match('#^git@([A-Za-z0-9.-]+):([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $value, $m) ) {
			$host  = strtolower($m[1]);
			$owner = $m[2];
			$repo  = $m[3];
		} elseif ( '' === $value ) {
			return null;
		}

		$repo = preg_replace('/\.git$/', '', $repo) ?? $repo;
		if ( '' === $owner || '' === $repo || ! self::isValidPort($ssh_port) ) {
			return null;
		}

		return compact('host', 'owner', 'repo', 'ssh_port');
	}

	/**
	 * @param array{host:string,owner:string,repo:string,ssh_port:int|null} $left
	 * @param array{host:string,owner:string,repo:string,ssh_port:int|null} $right
	 */
	private static function sameIdentity( array $left, array $right ): bool {
		return strtolower($left['host']) === strtolower($right['host'])
			&& strtolower($left['owner']) === strtolower($right['owner'])
			&& strtolower($left['repo']) === strtolower($right['repo']);
	}

	private static function isValidPort( ?int $port ): bool {
		return null === $port || ( $port >= 1 && $port <= 65535 );
	}
}
