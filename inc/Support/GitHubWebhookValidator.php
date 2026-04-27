<?php
/**
 * GitHub webhook verifier mode for pull-request review flows.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

use DataMachine\Api\WebhookVerificationResult;

defined( 'ABSPATH' ) || exit;

/**
 * Validates GitHub pull_request webhooks before Data Machine runs a flow.
 */
class GitHubWebhookValidator {

	const DEFAULT_ALLOWED_ACTIONS = array( 'opened', 'reopened', 'synchronize', 'ready_for_review' );

	const WRONG_EVENT       = 'github_wrong_event';
	const DISALLOWED_ACTION = 'github_disallowed_action';
	const REPO_MISMATCH     = 'github_repo_mismatch';
	const DRAFT_SKIPPED     = 'github_draft_skipped';
	const MALFORMED_PAYLOAD = 'github_malformed_payload';

	/**
	 * Verify a GitHub pull_request webhook.
	 *
	 * This method matches Data Machine's pluggable webhook verifier mode
	 * signature, so DMC can own GitHub-specific policy without changing DM core.
	 *
	 * @param string              $raw_body     Raw request body bytes.
	 * @param array<string,mixed> $headers      Request headers.
	 * @param array<string,mixed> $query_params Query parameters.
	 * @param array<string,mixed> $post_params  Form body parameters.
	 * @param string              $url          Request URL.
	 * @param array               $config       Verifier config.
	 * @param int|null            $now          Current timestamp override.
	 * @return WebhookVerificationResult
	 */
	public static function verify(
		string $raw_body,
		array $headers,
		array $query_params,
		array $post_params,
		string $url,
		array $config,
		?int $now = null
	): WebhookVerificationResult {
		unset( $query_params, $post_params, $url );

		$headers = self::lowerCaseKeys( $headers );
		$now     = $now ?? time();

		$max_body_bytes = (int) ( $config['max_body_bytes'] ?? 1048576 );
		if ( $max_body_bytes > 0 && strlen( $raw_body ) > $max_body_bytes ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::PAYLOAD_TOO_LARGE, 'body too large' );
		}

		$signature = trim( (string) ( $headers['x-hub-signature-256'] ?? '' ) );
		if ( '' === $signature ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MISSING_SIGNATURE, 'header=x-hub-signature-256' );
		}

		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::BAD_SIGNATURE, 'signature format mismatch' );
		}

		$secrets = self::activeSecrets( $config, $now );
		if ( empty( $secrets ) ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::NO_ACTIVE_SECRET );
		}

		$matched_secret_id = null;
		foreach ( $secrets as $secret ) {
			$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret['value'] );
			if ( hash_equals( $expected, $signature ) ) {
				$matched_secret_id = $secret['id'];
				break;
			}
		}

		if ( null === $matched_secret_id ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::BAD_SIGNATURE, 'signature mismatch' );
		}

		$event = trim( (string) ( $headers['x-github-event'] ?? '' ) );
		if ( 'pull_request' !== $event ) {
			return WebhookVerificationResult::fail( self::WRONG_EVENT, 'event is not pull_request' );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return WebhookVerificationResult::fail( self::MALFORMED_PAYLOAD, 'payload is not valid JSON' );
		}

		$action = (string) ( $payload['action'] ?? '' );
		if ( '' === $action ) {
			return WebhookVerificationResult::fail( self::MALFORMED_PAYLOAD, 'payload action missing' );
		}

		$allowed_actions = self::allowedActions( $config );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return WebhookVerificationResult::fail( self::DISALLOWED_ACTION, 'action=' . $action );
		}

		$repo = (string) ( $payload['repository']['full_name'] ?? '' );
		if ( '' === $repo ) {
			return WebhookVerificationResult::fail( self::MALFORMED_PAYLOAD, 'repository.full_name missing' );
		}

		$allowed_repos = self::allowedRepos( $config );
		if ( ! empty( $allowed_repos ) && ! in_array( strtolower( $repo ), $allowed_repos, true ) ) {
			return WebhookVerificationResult::fail( self::REPO_MISMATCH, 'repo=' . $repo );
		}

		$allow_drafts = ! empty( $config['allow_drafts'] );
		$is_draft     = ! empty( $payload['pull_request']['draft'] );
		if ( $is_draft && ! $allow_drafts ) {
			return WebhookVerificationResult::fail( self::DRAFT_SKIPPED, 'draft pull request' );
		}

		return WebhookVerificationResult::ok( $matched_secret_id );
	}

	/**
	 * Build a Data Machine webhook verifier config for GitHub PR review flows.
	 *
	 * @param string $secret  Webhook secret from GitHub.
	 * @param array  $options Optional allowed_actions, repo, allowed_repos, allow_drafts.
	 * @return array
	 */
	public static function buildVerifierConfig( string $secret, array $options = array() ): array {
		$config = array_merge(
			array(
				'mode'            => 'github_pull_request',
				'secrets'         => array(
					array(
						'id'    => 'github_webhook',
						'value' => $secret,
					),
				),
				'allowed_actions' => self::DEFAULT_ALLOWED_ACTIONS,
				'allow_drafts'    => false,
			),
			$options
		);

		return $config;
	}

	/**
	 * @param array<string,mixed> $headers
	 * @return array<string,string>
	 */
	private static function lowerCaseKeys( array $headers ): array {
		$out = array();
		foreach ( $headers as $name => $value ) {
			$key         = strtolower( str_replace( '_', '-', (string) $name ) );
			$out[ $key ] = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
		}
		return $out;
	}

	/**
	 * @return array<int,array{id:string,value:string}>
	 */
	private static function activeSecrets( array $config, int $now ): array {
		$secrets = array();

		if ( isset( $config['secret'] ) && is_string( $config['secret'] ) && '' !== $config['secret'] ) {
			$secrets[] = array(
				'id'    => 'github_webhook',
				'value' => $config['secret'],
			);
		}

		foreach ( (array) ( $config['secrets'] ?? array() ) as $index => $secret ) {
			if ( ! is_array( $secret ) ) {
				continue;
			}
			$value = (string) ( $secret['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$expires_at = (string) ( $secret['expires_at'] ?? '' );
			if ( '' !== $expires_at && strtotime( $expires_at ) < $now ) {
				continue;
			}
			$secrets[] = array(
				'id'    => (string) ( $secret['id'] ?? 'secret_' . $index ),
				'value' => $value,
			);
		}

		return $secrets;
	}

	/**
	 * @return array<int,string>
	 */
	private static function allowedActions( array $config ): array {
		$actions = $config['allowed_actions'] ?? self::DEFAULT_ALLOWED_ACTIONS;
		if ( is_string( $actions ) ) {
			$actions = array_map( 'trim', explode( ',', $actions ) );
		}
		$actions = array_values( array_filter( array_map( 'strval', (array) $actions ) ) );
		return ! empty( $actions ) ? $actions : self::DEFAULT_ALLOWED_ACTIONS;
	}

	/**
	 * @return array<int,string>
	 */
	private static function allowedRepos( array $config ): array {
		$repos = array();
		if ( isset( $config['repo'] ) ) {
			$repos[] = (string) $config['repo'];
		}
		foreach ( (array) ( $config['allowed_repos'] ?? array() ) as $repo ) {
			$repos[] = (string) $repo;
		}
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $repos ) ) ) );
	}
}
