<?php
/**
 * Pure-PHP smoke test for GitHub App auth resolution.
 *
 * Run: php tests/smoke-github-app-auth.php
 */

declare( strict_types=1 );

namespace DataMachine\Core {
	class PluginSettings {
		public static function get( string $key, string $default = '' ): string {
			return $GLOBALS['dmc_settings'][ $key ] ?? $default;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code, string $message, array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}

	function get_transient( string $key ) {
		return $GLOBALS['dmc_transients'][ $key ] ?? false;
	}

	function set_transient( string $key, $value, int $expiration ): bool {
		$GLOBALS['dmc_transients'][ $key ] = $value;
		$GLOBALS['dmc_transient_ttls'][ $key ] = $expiration;
		return true;
	}

	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}

	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}

	require __DIR__ . '/../inc/Support/GitHubCredentialResolver.php';
	require __DIR__ . '/../inc/Abilities/GitHubAbilities.php';

	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Support\GitHubCredentialResolver;

	$failures = array();
	$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
		if ( $cond ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	$reset = function ( array $settings = array() ): void {
		$GLOBALS['dmc_settings']       = $settings;
		$GLOBALS['dmc_transients']     = array();
		$GLOBALS['dmc_transient_ttls'] = array();
	};

	$key_resource = openssl_pkey_new( array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	) );
	openssl_pkey_export( $key_resource, $private_key );
	$escaped_private_key = str_replace( "\n", '\\n', $private_key );

	echo "GitHub App auth — smoke\n";

	$reset( array( 'github_pat' => 'pat-token' ) );
	$pat_credential = GitHubCredentialResolver::resolve();
	$assert( 'PAT mode resolves configured PAT', ! is_wp_error( $pat_credential ) && 'pat-token' === $pat_credential['token'] );
	$assert( 'PAT mode keeps legacy token authorization scheme', ! is_wp_error( $pat_credential ) && 'token pat-token' === $pat_credential['authorization'] );
	$assert( 'PAT status defaults mode to pat', 'pat' === GitHubCredentialResolver::status()['mode'] );

	$reset( array( 'github_auth_mode' => 'app' ) );
	$missing = GitHubCredentialResolver::resolve();
	$assert( 'app mode reports missing app id', is_wp_error( $missing ) && 'github_app_id_missing' === $missing->get_error_code() );

	$jwt = GitHubCredentialResolver::mintJwt( '12345', $private_key, 1700000000 );
	$assert( 'JWT mint succeeds with valid private key', ! is_wp_error( $jwt ) && 3 === count( explode( '.', $jwt ) ) );
	if ( ! is_wp_error( $jwt ) ) {
		$parts   = explode( '.', $jwt );
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		$assert( 'JWT payload carries app id issuer', '12345' === (string) ( $payload['iss'] ?? '' ) );
		$assert( 'JWT expires within GitHub max window', 1700000540 === (int) ( $payload['exp'] ?? 0 ) );
	}

	$invalid = GitHubCredentialResolver::mintJwt( '12345', 'not a key', 1700000000 );
	$assert( 'invalid private key returns actionable error', is_wp_error( $invalid ) && 'github_app_private_key_invalid' === $invalid->get_error_code() );

	$settings = array(
		'github_auth_mode'                => 'app',
		'github_app_id'                   => '12345',
		'github_app_installation_id'      => '67890',
		'github_app_private_key'          => $escaped_private_key,
	);
	$reset( $settings );
	$requests = array();
	$fake_http = function ( string $url, array $args ) use ( &$requests ): array {
		$requests[] = array( $url, $args );
		return array(
			'response' => array( 'code' => 201 ),
			'body'     => json_encode( array(
				'token'      => 'installation-token-' . count( $requests ),
				'expires_at' => gmdate( 'c', 1700003600 ),
			) ),
		);
	};

	$app_credential = GitHubCredentialResolver::resolve( $fake_http, 1700000000 );
	$assert( 'app mode exchanges JWT for installation token', ! is_wp_error( $app_credential ) && 'installation-token-1' === $app_credential['token'] );
	$assert( 'app mode uses bearer authorization for API calls', ! is_wp_error( $app_credential ) && 'Bearer installation-token-1' === $app_credential['authorization'] );
	$assert( 'token exchange hits installation access-token endpoint', false !== strpos( $requests[0][0] ?? '', '/app/installations/67890/access_tokens' ) );
	$assert( 'token exchange authenticates with app JWT bearer', str_starts_with( $requests[0][1]['headers']['Authorization'] ?? '', 'Bearer ' ) );
	$assert( 'installation token is cached', 1 === count( $GLOBALS['dmc_transients'] ) );
	$assert( 'cache ttl follows expires_at', 3600 === array_values( $GLOBALS['dmc_transient_ttls'] )[0] );

	$cached_credential = GitHubCredentialResolver::resolve( $fake_http, 1700000100 );
	$assert( 'cached token is reused before early-expiry window', ! is_wp_error( $cached_credential ) && ! empty( $cached_credential['cached'] ) && 'installation-token-1' === $cached_credential['token'] );
	$assert( 'cached token avoids second HTTP request', 1 === count( $requests ) );

	$cache_key = array_key_first( $GLOBALS['dmc_transients'] );
	$GLOBALS['dmc_transients'][ $cache_key ]['expires_at'] = gmdate( 'c', 1700000150 );
	$refreshed = GitHubCredentialResolver::resolve( $fake_http, 1700000100 );
	$assert( 'token refreshes inside early-expiry window', ! is_wp_error( $refreshed ) && empty( $refreshed['cached'] ) && 'installation-token-2' === $refreshed['token'] );
	$assert( 'early-expiry refresh makes second HTTP request', 2 === count( $requests ) );

	$headers = GitHubCredentialResolver::headers( $refreshed );
	$assert( 'resolver headers select bearer token for app credential', 'Bearer installation-token-2' === $headers['Authorization'] );

	$reset( $settings );
	$exchange_failure = GitHubCredentialResolver::resolve(
		fn(): array => array(
			'response' => array( 'code' => 403 ),
			'body'     => json_encode( array( 'message' => 'Resource not accessible by integration' ) ),
		),
		1700000000
	);
	$assert( 'token exchange failure keeps GitHub message', is_wp_error( $exchange_failure ) && str_contains( $exchange_failure->get_error_message(), 'Resource not accessible by integration' ) );

	$reset( $settings );
	$ability_now = time();
	$fake_http_for_ability = function () use ( $ability_now ): array {
		return array(
			'response' => array( 'code' => 201 ),
			'body'     => json_encode( array(
				'token'      => 'ability-installation-token',
				'expires_at' => gmdate( 'c', $ability_now + 3600 ),
			) ),
		);
	};
	GitHubCredentialResolver::resolve( $fake_http_for_ability, $ability_now );
	$ability_token = GitHubAbilities::getPat();
	$assert( 'GitHubAbilities getPat resolves active app token for existing callers', 'ability-installation-token' === $ability_token );
	$assert( 'GitHubAbilities reports app mode configured without printing secrets', GitHubAbilities::isConfigured() && GitHubAbilities::getAuthStatus()['app_private_key_configured'] );

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "All assertions passed.\n";
}
