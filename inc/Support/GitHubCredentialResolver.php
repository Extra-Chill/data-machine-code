<?php
/**
 * GitHub credential resolver.
 *
 * Supports the classic PAT path and GitHub App installation tokens behind the
 * same API-token contract used by GitHubAbilities. Credentials are organized
 * as **profiles**: a list of named credential entries (`id`, `label`, `mode`,
 * `pat` or App fields, optional `default_repo`, optional `allowed_repos`)
 * stored in `github_credential_profiles`, with `github_default_profile_id`
 * pointing at the fallback profile.
 *
 * Selectors:
 *   - Pass `profile_id` to resolve a specific named profile. Unknown ids
 *     fail closed — never silently fall back to the default.
 *   - Pass `repo` (owner/repo) to resolve the profile whose
 *     `allowed_repos` contains the repo, or whose `default_repo` matches.
 *     Unmatched repos fall through to the default profile.
 *   - Zero-arg `resolve()` returns the default profile.
 *
 * Backward compatibility:
 *   - Existing installs that stored a single global credential under
 *     `github_pat`, `github_auth_mode`, and `github_app_*` keep working —
 *     when `github_credential_profiles` is empty or missing, the legacy
 *     shape is synthesized into an implicit `default` profile on read.
 *   - New writes should populate `github_credential_profiles` directly via
 *     the `datamachine/update-settings` ability. The legacy keys remain
 *     readable (and writable) so existing tooling and per-key reads keep
 *     functioning until operators migrate.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

use DataMachine\Core\PluginSettings;

defined('ABSPATH') || exit;

final class GitHubCredentialResolver
{

    private const APP_TOKEN_CACHE_PREFIX = 'datamachine_code_github_app_token_';
    private const APP_TOKEN_EXPIRY_SKEW  = 60;

    public const DEFAULT_PROFILE_ID = 'default';

    /**
     * Resolve the active GitHub credential.
     *
     * @param  callable|null            $http_request Test seam: fn(string $url, array $args): array|\WP_Error.
     * @param  int|null                 $now          Test seam for cache expiry checks.
     * @param  array<string,mixed>|null $selector     Optional selector: `profile_id` or `repo`.
     * @return array{mode:string,token:string,authorization:string,profile_id:string,cached?:bool,expires_at?:string}|\WP_Error
     */
    public static function resolve( ?callable $http_request = null, ?int $now = null, ?array $selector = null ): array|\WP_Error
    {
        $profile = self::selectProfile($selector);
        if (is_wp_error($profile) ) {
            return $profile;
        }

        $profile_id = (string) $profile['id'];
        $mode       = strtolower((string) ( $profile['mode'] ?? 'pat' ));

        if ('pat' === $mode ) {
            $pat = trim((string) ( $profile['pat'] ?? '' ));
            if ('' === $pat ) {
                return new \WP_Error(
                    'github_pat_not_configured',
                    sprintf('GitHub Personal Access Token not configured for profile "%s".', $profile_id),
                    array( 'status' => 403 )
                );
            }

            return array(
            'mode'          => 'pat',
            'token'         => $pat,
            'authorization' => 'token ' . $pat,
            'profile_id'    => $profile_id,
            );
        }

        if ('app' !== $mode ) {
            return new \WP_Error(
                'github_auth_mode_invalid',
                sprintf('Invalid mode "%s" for profile "%s". Expected "pat" or "app".', $mode, $profile_id),
                array( 'status' => 400 )
            );
        }

        return self::resolveApp($profile, $http_request, $now);
    }

    /**
     * Return non-secret configuration status for CLI output.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $profiles = self::profiles();
        $default  = self::resolveDefaultProfile($profiles);
        $mode     = strtolower((string) ( $default['mode'] ?? 'pat' ));

        $profile_summaries = array();
        foreach ( $profiles as $profile ) {
            $profile_summaries[] = self::summarizeProfile($profile);
        }

        return array(
        // Top-level fields preserve the historical surface for back-compat with existing CLI/status callers.
        'mode'                        => $mode,
        'pat_configured'              => '' !== trim((string) ( $default['pat'] ?? '' )),
        'app_id_configured'           => '' !== trim((string) ( $default['app_id'] ?? '' )),
        'app_installation_configured' => '' !== trim((string) ( $default['app_installation_id'] ?? '' )),
        'app_private_key_configured'  => '' !== trim((string) ( $default['app_private_key'] ?? '' )),
        'configured'                  => self::isConfigured(),
        'checks_permission'           => 'read',
        'commit_statuses_permission'  => 'read',
        'actions_permission'          => 'read recommended when fetching workflow artifacts',
        // New profile-aware fields.
        'default_profile_id'          => (string) $default['id'],
        'profiles'                    => $profile_summaries,
        );
    }

    public static function isConfigured(): bool
    {
        $profile = self::resolveDefaultProfile(self::profiles());
        return self::profileIsConfigured($profile);
    }

    /**
     * Active mode for the default profile (back-compat helper).
     */
    public static function mode(): string
    {
        $profile = self::resolveDefaultProfile(self::profiles());
        $mode    = strtolower((string) ( $profile['mode'] ?? 'pat' ));
        return '' === $mode ? 'pat' : $mode;
    }

    /**
     * Mint a GitHub App JWT.
     */
    public static function mintJwt( string $app_id, string $private_key, int $now ): string|\WP_Error
    {
        $private_key = self::normalizePrivateKey($private_key);
        $header      = array(
        'alg' => 'RS256',
        'typ' => 'JWT',
        );
        $payload     = array(
        'iat' => $now - 60,
        'exp' => $now + 540,
        'iss' => $app_id,
        );

        $unsigned  = self::base64UrlEncode(wp_json_encode($header)) . '.' . self::base64UrlEncode(wp_json_encode($payload));
        $signature = '';
     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- openssl_sign emits warnings for invalid keys; callers receive a WP_Error below.
        $ok = @openssl_sign($unsigned, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (! $ok ) {
            return new \WP_Error('github_app_private_key_invalid', 'GitHub App private key is invalid or could not sign a JWT.', array( 'status' => 400 ));
        }

        return $unsigned . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Build standard API headers for an already-resolved credential.
     *
     * @param array{authorization:string} $credential
     */
    public static function headers( array $credential ): array
    {
        return array(
        'Authorization' => $credential['authorization'],
        'Accept'        => 'application/vnd.github.v3+json',
        'User-Agent'    => 'DataMachineCode',
        'Content-Type'  => 'application/json',
        );
    }

    /**
     * Return the configured profile list, synthesizing a legacy "default"
     * profile from the legacy `github_pat` / `github_app_*` keys when the
     * new structure is empty.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function profiles(): array
    {
        $raw = PluginSettings::get('github_credential_profiles', array());
        if (is_array($raw) && ! empty($raw) ) {
            $normalized = array();
            foreach ( $raw as $entry ) {
                if (! is_array($entry) ) {
                    continue;
                }
                $profile = self::normalizeProfile($entry);
                if (null !== $profile ) {
                    $normalized[] = $profile;
                }
            }
            if (! empty($normalized) ) {
                return $normalized;
            }
        }

        return self::synthesizeLegacyProfiles();
    }

    /**
     * Resolve the default profile id from settings, falling back to the
     * first profile when the configured id is unknown or missing.
     *
     * @param  array<int,array<string,mixed>> $profiles
     * @return array<string,mixed>
     */
    public static function resolveDefaultProfile( array $profiles ): array
    {
        if (empty($profiles) ) {
            return self::emptyDefaultProfile();
        }

        $configured = trim((string) PluginSettings::get('github_default_profile_id', ''));
        if ('' !== $configured ) {
            foreach ( $profiles as $profile ) {
                if ((string) ( $profile['id'] ?? '' ) === $configured ) {
                    return $profile;
                }
            }
        }

        return $profiles[0];
    }

    /**
     * @return array{mode:string,token:string,authorization:string,profile_id:string,cached?:bool,expires_at?:string}|\WP_Error
     */
    private static function resolveApp( array $profile, ?callable $http_request, ?int $now ): array|\WP_Error
    {
        $now             = $now ?? time();
        $profile_id      = (string) $profile['id'];
        $app_id          = trim((string) ( $profile['app_id'] ?? '' ));
        $installation_id = trim((string) ( $profile['app_installation_id'] ?? '' ));
        $private_key     = (string) ( $profile['app_private_key'] ?? '' );

        if ('' === $app_id ) {
            return new \WP_Error('github_app_id_missing', sprintf('GitHub App auth selected but app_id is not configured for profile "%s".', $profile_id), array( 'status' => 400 ));
        }
        if ('' === $installation_id ) {
            return new \WP_Error('github_app_installation_id_missing', sprintf('GitHub App auth selected but app_installation_id is not configured for profile "%s".', $profile_id), array( 'status' => 400 ));
        }
        if ('' === trim($private_key) ) {
            return new \WP_Error('github_app_private_key_missing', sprintf('GitHub App auth selected but app_private_key is not configured for profile "%s".', $profile_id), array( 'status' => 400 ));
        }

        $cache_key = self::cacheKey($profile_id, $app_id, $installation_id);
        $cached    = get_transient($cache_key);
        if (is_array($cached) ) {
            $token      = (string) ( $cached['token'] ?? '' );
            $expires_at = (string) ( $cached['expires_at'] ?? '' );
            $expires_ts = strtotime($expires_at);
            if ('' !== $token && $expires_ts && $expires_ts > ( $now + self::APP_TOKEN_EXPIRY_SKEW ) ) {
                return array(
                'mode'          => 'app',
                'token'         => $token,
                'authorization' => 'Bearer ' . $token,
                'profile_id'    => $profile_id,
                'cached'        => true,
                'expires_at'    => $expires_at,
                );
            }
        }

        $jwt = self::mintJwt($app_id, $private_key, $now);
        if (is_wp_error($jwt) ) {
            return $jwt;
        }

        $http_request = $http_request ?? 'wp_remote_request';
        $response     = $http_request(
            'https://api.github.com/app/installations/' . rawurlencode($installation_id) . '/access_tokens',
            array(
            'method'  => 'POST',
            'headers' => array(
                    'Authorization' => 'Bearer ' . $jwt,
                    'Accept'        => 'application/vnd.github.v3+json',
                    'User-Agent'    => 'DataMachineCode',
                    'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
            )
        );

        if (is_wp_error($response) ) {
            return new \WP_Error('github_app_token_request_failed', 'GitHub App installation token request failed: ' . $response->get_error_message(), array( 'status' => 500 ));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = json_decode(wp_remote_retrieve_body($response), true);
        if ($status_code >= 400 ) {
            $message = is_array($body) ? (string) ( $body['message'] ?? 'Unknown error' ) : 'Unknown error';
            return new \WP_Error('github_app_token_exchange_failed', sprintf('GitHub App installation token exchange failed (%d): %s', $status_code, $message), array( 'status' => $status_code ));
        }

        $token      = is_array($body) ? (string) ( $body['token'] ?? '' ) : '';
        $expires_at = is_array($body) ? (string) ( $body['expires_at'] ?? '' ) : '';
        $expires_ts = strtotime($expires_at);
        if ('' === $token || ! $expires_ts ) {
            return new \WP_Error('github_app_token_exchange_invalid', 'GitHub App installation token exchange response did not include a token and expires_at.', array( 'status' => 502 ));
        }

        $ttl = max(1, $expires_ts - $now);
        set_transient(
            $cache_key,
            array(
            'token'      => $token,
            'expires_at' => $expires_at,
            ),
            $ttl
        );

        return array(
        'mode'          => 'app',
        'token'         => $token,
        'authorization' => 'Bearer ' . $token,
        'profile_id'    => $profile_id,
        'cached'        => false,
        'expires_at'    => $expires_at,
        );
    }

    /**
     * Pick a profile based on the optional selector.
     *
     * - Explicit `profile_id` fails closed when the id is unknown.
     * - `repo` selects the profile whose `allowed_repos` contains the
     *   repo, falling back to the profile whose `default_repo` matches,
     *   then to the default profile.
     * - No selector → default profile.
     *
     * @param  array<string,mixed>|null $selector
     * @return array<string,mixed>|\WP_Error
     */
    private static function selectProfile( ?array $selector ): array|\WP_Error
    {
        $profiles = self::profiles();

        if (is_array($selector) ) {
            $profile_id = isset($selector['profile_id']) ? trim((string) $selector['profile_id']) : '';
            if ('' !== $profile_id ) {
                foreach ( $profiles as $profile ) {
                    if ((string) ( $profile['id'] ?? '' ) === $profile_id ) {
                        return $profile;
                    }
                }
                return new \WP_Error(
                    'github_profile_not_found',
                    sprintf('GitHub credential profile "%s" is not configured.', $profile_id),
                    array( 'status' => 404 )
                );
            }

            $repo = isset($selector['repo']) ? strtolower(trim((string) $selector['repo'])) : '';
            if ('' !== $repo ) {
                // Prefer profiles that explicitly list the repo in allowed_repos.
                foreach ( $profiles as $profile ) {
                    $allowed = isset($profile['allowed_repos']) && is_array($profile['allowed_repos'])
                     ? array_map('strtolower', array_map('strval', $profile['allowed_repos']))
                     : array();
                    if (in_array($repo, $allowed, true) ) {
                        return $profile;
                    }
                }
                // Fall back to a profile whose default_repo matches.
                foreach ( $profiles as $profile ) {
                    $default_repo = strtolower(trim((string) ( $profile['default_repo'] ?? '' )));
                    if ('' !== $default_repo && $default_repo === $repo ) {
                        return $profile;
                    }
                }
                // No repo match → fall through to default profile.
            }
        }

        return self::resolveDefaultProfile($profiles);
    }

    /**
     * Synthesize the legacy single-credential shape into a one-profile list.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function synthesizeLegacyProfiles(): array
    {
        $raw_mode            = strtolower(trim((string) PluginSettings::get('github_auth_mode', '')));
        $mode                = '' === $raw_mode ? 'pat' : $raw_mode;
        $pat                 = trim((string) PluginSettings::get('github_pat', ''));
        $app_id              = trim((string) PluginSettings::get('github_app_id', ''));
        $app_installation_id = trim((string) PluginSettings::get('github_app_installation_id', ''));
        $app_private_key     = (string) PluginSettings::get('github_app_private_key', '');
        $default_repo        = trim((string) PluginSettings::get('github_default_repo', ''));

        // Always synthesize a default profile that carries the legacy mode
        // even when no credentials are populated yet. This keeps error paths
        // like "app mode but app_id missing" intact for installs that set
        // github_auth_mode without populating the credentials.
        return array(
        array(
        'id'                  => self::DEFAULT_PROFILE_ID,
        'label'               => 'Default',
        'mode'                => $mode,
        'pat'                 => $pat,
        'app_id'              => $app_id,
        'app_installation_id' => $app_installation_id,
        'app_private_key'     => $app_private_key,
        'default_repo'        => $default_repo,
        'allowed_repos'       => array(),
        ),
        );
    }

    /**
     * Normalize a raw profile entry from settings — accepts loose arrays
     * and produces a consistent shape, dropping entries without an id.
     *
     * @return array<string,mixed>|null
     */
    private static function normalizeProfile( array $entry ): ?array
    {
        $id = trim((string) ( $entry['id'] ?? '' ));
        if ('' === $id ) {
            return null;
        }

        $allowed = array();
        if (isset($entry['allowed_repos']) && is_array($entry['allowed_repos']) ) {
            foreach ( $entry['allowed_repos'] as $repo ) {
                $repo = trim((string) $repo);
                if ('' !== $repo ) {
                    $allowed[] = $repo;
                }
            }
        }

        return array(
        'id'                  => $id,
        'label'               => (string) ( $entry['label'] ?? $id ),
        'mode'                => strtolower((string) ( $entry['mode'] ?? 'pat' )),
        'pat'                 => (string) ( $entry['pat'] ?? '' ),
        'app_id'              => (string) ( $entry['app_id'] ?? '' ),
        'app_installation_id' => (string) ( $entry['app_installation_id'] ?? '' ),
        'app_private_key'     => (string) ( $entry['app_private_key'] ?? '' ),
        'default_repo'        => (string) ( $entry['default_repo'] ?? '' ),
        'allowed_repos'       => $allowed,
        );
    }

    private static function emptyDefaultProfile(): array
    {
        return array(
        'id'                  => self::DEFAULT_PROFILE_ID,
        'label'               => 'Default',
        'mode'                => 'pat',
        'pat'                 => '',
        'app_id'              => '',
        'app_installation_id' => '',
        'app_private_key'     => '',
        'default_repo'        => '',
        'allowed_repos'       => array(),
        );
    }

    private static function profileIsConfigured( array $profile ): bool
    {
        $mode = strtolower((string) ( $profile['mode'] ?? 'pat' ));
        if ('pat' === $mode ) {
            return '' !== trim((string) ( $profile['pat'] ?? '' ));
        }

        return 'app' === $mode
        && '' !== trim((string) ( $profile['app_id'] ?? '' ))
        && '' !== trim((string) ( $profile['app_installation_id'] ?? '' ))
        && '' !== trim((string) ( $profile['app_private_key'] ?? '' ));
    }

    private static function summarizeProfile( array $profile ): array
    {
        $mode = strtolower((string) ( $profile['mode'] ?? 'pat' ));
        return array(
        'id'                          => (string) $profile['id'],
        'label'                       => (string) ( $profile['label'] ?? $profile['id'] ),
        'mode'                        => $mode,
        'pat_configured'              => '' !== trim((string) ( $profile['pat'] ?? '' )),
        'app_id_configured'           => '' !== trim((string) ( $profile['app_id'] ?? '' )),
        'app_installation_configured' => '' !== trim((string) ( $profile['app_installation_id'] ?? '' )),
        'app_private_key_configured'  => '' !== trim((string) ( $profile['app_private_key'] ?? '' )),
        'default_repo'                => (string) ( $profile['default_repo'] ?? '' ),
        'allowed_repos'               => isset($profile['allowed_repos']) && is_array($profile['allowed_repos'])
        ? array_values(array_map('strval', $profile['allowed_repos']))
        : array(),
        'configured'                  => self::profileIsConfigured($profile),
        );
    }

    private static function cacheKey( string $profile_id, string $app_id, string $installation_id ): string
    {
        return self::APP_TOKEN_CACHE_PREFIX . md5($profile_id . ':' . $app_id . ':' . $installation_id);
    }

    private static function normalizePrivateKey( string $private_key ): string
    {
        return str_replace('\\n', "\n", trim($private_key));
    }

    private static function base64UrlEncode( string $value ): string
    {
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
