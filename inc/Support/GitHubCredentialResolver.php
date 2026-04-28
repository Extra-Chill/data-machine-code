<?php
/**
 * GitHub credential resolver.
 *
 * Supports the legacy PAT path and GitHub App installation tokens behind the
 * same API-token contract used by GitHubAbilities.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

final class GitHubCredentialResolver {

	private const APP_TOKEN_CACHE_PREFIX = 'datamachine_code_github_app_token_';
	private const APP_TOKEN_EXPIRY_SKEW  = 60;

	/**
	 * Resolve the active GitHub credential.
	 *
	 * @param callable|null $http_request Test seam: fn(string $url, array $args): array|\WP_Error.
	 * @param int|null      $now          Test seam for cache expiry checks.
	 * @return array{mode:string,token:string,authorization:string,cached?:bool,expires_at?:string}|\WP_Error
	 */
	public static function resolve( ?callable $http_request = null, ?int $now = null ): array|\WP_Error {
		$mode = self::mode();
		if ( 'pat' === $mode ) {
			$pat = self::setting( 'github_pat' );
			if ( '' === $pat ) {
				return new \WP_Error( 'github_pat_not_configured', 'GitHub Personal Access Token not configured. Set github_pat in Data Machine settings.', array( 'status' => 403 ) );
			}

			return array(
				'mode'          => 'pat',
				'token'         => $pat,
				'authorization' => 'token ' . $pat,
			);
		}

		if ( 'app' !== $mode ) {
			return new \WP_Error( 'github_auth_mode_invalid', 'Invalid github_auth_mode. Expected "pat" or "app".', array( 'status' => 400 ) );
		}

		return self::resolveApp( $http_request, $now );
	}

	/**
	 * Return non-secret configuration status for CLI output.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$mode = self::mode();

		return array(
			'mode'                        => $mode,
			'pat_configured'              => '' !== self::setting( 'github_pat' ),
			'app_id_configured'           => '' !== self::setting( 'github_app_id' ),
			'app_installation_configured' => '' !== self::setting( 'github_app_installation_id' ),
			'app_private_key_configured'  => '' !== self::setting( 'github_app_private_key' ),
			'configured'                  => self::isConfigured(),
			'checks_permission'           => 'read',
			'commit_statuses_permission'  => 'read',
			'actions_permission'          => 'read recommended when fetching workflow artifacts',
		);
	}

	public static function isConfigured(): bool {
		$mode = self::mode();
		if ( 'pat' === $mode ) {
			return '' !== self::setting( 'github_pat' );
		}

		return 'app' === $mode
			&& '' !== self::setting( 'github_app_id' )
			&& '' !== self::setting( 'github_app_installation_id' )
			&& '' !== self::setting( 'github_app_private_key' );
	}

	public static function mode(): string {
		$mode = strtolower( self::setting( 'github_auth_mode', 'pat' ) );
		return '' === $mode ? 'pat' : $mode;
	}

	/**
	 * Mint a GitHub App JWT.
	 */
	public static function mintJwt( string $app_id, string $private_key, int $now ): string|\WP_Error {
		$private_key = self::normalizePrivateKey( $private_key );
		$header      = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$payload     = array(
			'iat' => $now - 60,
			'exp' => $now + 540,
			'iss' => $app_id,
		);

		$unsigned  = self::base64UrlEncode( wp_json_encode( $header ) ) . '.' . self::base64UrlEncode( wp_json_encode( $payload ) );
		$signature = '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- openssl_sign emits warnings for invalid keys; callers receive a WP_Error below.
		$ok = @openssl_sign( $unsigned, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) {
			return new \WP_Error( 'github_app_private_key_invalid', 'GitHub App private key is invalid or could not sign a JWT.', array( 'status' => 400 ) );
		}

		return $unsigned . '.' . self::base64UrlEncode( $signature );
	}

	/**
	 * Build standard API headers for an already-resolved credential.
	 *
	 * @param array{authorization:string} $credential
	 */
	public static function headers( array $credential ): array {
		return array(
			'Authorization' => $credential['authorization'],
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'DataMachineCode',
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * @return array{mode:string,token:string,authorization:string,cached?:bool,expires_at?:string}|\WP_Error
	 */
	private static function resolveApp( ?callable $http_request, ?int $now ): array|\WP_Error {
		$now             = $now ?? time();
		$app_id          = self::setting( 'github_app_id' );
		$installation_id = self::setting( 'github_app_installation_id' );
		$private_key     = self::setting( 'github_app_private_key' );

		if ( '' === $app_id ) {
			return new \WP_Error( 'github_app_id_missing', 'GitHub App auth selected but github_app_id is not configured.', array( 'status' => 400 ) );
		}
		if ( '' === $installation_id ) {
			return new \WP_Error( 'github_app_installation_id_missing', 'GitHub App auth selected but github_app_installation_id is not configured.', array( 'status' => 400 ) );
		}
		if ( '' === $private_key ) {
			return new \WP_Error( 'github_app_private_key_missing', 'GitHub App auth selected but github_app_private_key is not configured.', array( 'status' => 400 ) );
		}

		$cache_key = self::cacheKey( $app_id, $installation_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$token      = (string) ( $cached['token'] ?? '' );
			$expires_at = (string) ( $cached['expires_at'] ?? '' );
			$expires_ts = strtotime( $expires_at );
			if ( '' !== $token && $expires_ts && $expires_ts > ( $now + self::APP_TOKEN_EXPIRY_SKEW ) ) {
				return array(
					'mode'          => 'app',
					'token'         => $token,
					'authorization' => 'Bearer ' . $token,
					'cached'        => true,
					'expires_at'    => $expires_at,
				);
			}
		}

		$jwt = self::mintJwt( $app_id, $private_key, $now );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$http_request = $http_request ?? 'wp_remote_request';
		$response     = $http_request(
			'https://api.github.com/app/installations/' . rawurlencode( $installation_id ) . '/access_tokens',
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

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'github_app_token_request_failed', 'GitHub App installation token request failed: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status_code >= 400 ) {
			$message = is_array( $body ) ? (string) ( $body['message'] ?? 'Unknown error' ) : 'Unknown error';
			return new \WP_Error( 'github_app_token_exchange_failed', sprintf( 'GitHub App installation token exchange failed (%d): %s', $status_code, $message ), array( 'status' => $status_code ) );
		}

		$token      = is_array( $body ) ? (string) ( $body['token'] ?? '' ) : '';
		$expires_at = is_array( $body ) ? (string) ( $body['expires_at'] ?? '' ) : '';
		$expires_ts = strtotime( $expires_at );
		if ( '' === $token || ! $expires_ts ) {
			return new \WP_Error( 'github_app_token_exchange_invalid', 'GitHub App installation token exchange response did not include a token and expires_at.', array( 'status' => 502 ) );
		}

		$ttl = max( 1, $expires_ts - $now );
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
			'cached'        => false,
			'expires_at'    => $expires_at,
		);
	}

	private static function setting( string $key, string $default_value = '' ): string {
		return trim( (string) PluginSettings::get( $key, $default_value ) );
	}

	private static function cacheKey( string $app_id, string $installation_id ): string {
		return self::APP_TOKEN_CACHE_PREFIX . md5( $app_id . ':' . $installation_id );
	}

	private static function normalizePrivateKey( string $private_key ): string {
		return str_replace( '\\n', "\n", trim( $private_key ) );
	}

	private static function base64UrlEncode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}
