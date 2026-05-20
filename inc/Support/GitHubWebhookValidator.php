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

	const DEFAULT_ALLOWED_ACTIONS              = array( 'opened', 'reopened', 'synchronize', 'ready_for_review' );
	const DEFAULT_WORKFLOW_RUN_ALLOWED_ACTIONS = array( 'completed' );

	const WRONG_EVENT       = 'github_wrong_event';
	const DISALLOWED_ACTION = 'github_disallowed_action';
	const REPO_MISMATCH     = 'github_repo_mismatch';
	const DRAFT_SKIPPED     = 'github_draft_skipped';
	const MALFORMED_PAYLOAD = 'github_malformed_payload';
	const WORKFLOW_MISMATCH = 'github_workflow_mismatch';
	const NO_PULL_REQUEST   = 'github_workflow_run_no_pull_request';

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

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return WebhookVerificationResult::fail( self::MALFORMED_PAYLOAD, 'payload is not valid JSON' );
		}

		$mode = (string) ( $config['mode'] ?? 'github_pull_request' );
		return 'github_workflow_run' === $mode
			? self::verifyWorkflowRunPayload( $payload, $headers, $config, $matched_secret_id )
			: self::verifyPullRequestPayload( $payload, $headers, $config, $matched_secret_id );
	}

	/**
	 * Verify a pull_request payload after HMAC validation.
	 *
	 * @param array<string,mixed> $payload Payload body.
	 * @param array<string,string> $headers Lower-cased request headers.
	 * @param array<string,mixed> $config Verifier config.
	 * @param string $matched_secret_id Matched secret id.
	 */
	private static function verifyPullRequestPayload( array $payload, array $headers, array $config, string $matched_secret_id ): WebhookVerificationResult {
		$event = trim( (string) ( $headers['x-github-event'] ?? '' ) );
		if ( 'pull_request' !== $event ) {
			return WebhookVerificationResult::fail( self::WRONG_EVENT, 'event is not pull_request' );
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
	 * Verify a workflow_run payload after HMAC validation.
	 *
	 * @param array<string,mixed> $payload Payload body.
	 * @param array<string,string> $headers Lower-cased request headers.
	 * @param array<string,mixed> $config Verifier config.
	 * @param string $matched_secret_id Matched secret id.
	 */
	private static function verifyWorkflowRunPayload( array $payload, array $headers, array $config, string $matched_secret_id ): WebhookVerificationResult {
		$event = trim( (string) ( $headers['x-github-event'] ?? '' ) );
		if ( 'workflow_run' !== $event ) {
			return WebhookVerificationResult::fail( self::WRONG_EVENT, 'event is not workflow_run' );
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

		$workflow_run = is_array( $payload['workflow_run'] ?? null ) ? $payload['workflow_run'] : array();
		if ( empty( $workflow_run ) ) {
			return WebhookVerificationResult::fail( self::MALFORMED_PAYLOAD, 'workflow_run missing' );
		}

		$workflow_name   = (string) ( $workflow_run['name'] ?? '' );
		$workflow_path   = (string) ( $workflow_run['path'] ?? '' );
		$allowed_names   = self::stringList( $config['workflow_names'] ?? $config['allowed_workflow_names'] ?? array() );
		$allowed_paths   = self::stringList( $config['workflow_paths'] ?? $config['allowed_workflow_paths'] ?? array() );
		$normalized_name = strtolower( $workflow_name );
		$normalized_path = strtolower( $workflow_path );

		if ( ! empty( $allowed_names ) && ! in_array( $normalized_name, array_map( 'strtolower', $allowed_names ), true ) ) {
			return WebhookVerificationResult::fail( self::WORKFLOW_MISMATCH, 'workflow=' . $workflow_name );
		}

		if ( ! empty( $allowed_paths ) && ! in_array( $normalized_path, array_map( 'strtolower', $allowed_paths ), true ) ) {
			return WebhookVerificationResult::fail( self::WORKFLOW_MISMATCH, 'workflow_path=' . $workflow_path );
		}

		$pull_requests = is_array( $workflow_run['pull_requests'] ?? null ) ? $workflow_run['pull_requests'] : array();
		if ( empty( $pull_requests ) || ! is_array( $pull_requests[0] ?? null ) || empty( $pull_requests[0]['number'] ) ) {
			return WebhookVerificationResult::fail( self::NO_PULL_REQUEST, 'workflow_run.pull_requests is empty' );
		}

		return WebhookVerificationResult::ok( $matched_secret_id );
	}

	/**
	 * Build a Data Machine webhook verifier config for GitHub PR review flows.
	 *
	 * @param string $secret  Webhook secret from GitHub.
	 * @param array  $options Optional mode, allowed_actions, repo, allowed_repos, allow_drafts, workflow filters.
	 * @return array
	 */
	public static function buildVerifierConfig( string $secret, array $options = array() ): array {
		$config = array_merge(
			array(
				'mode'            => (string) ( $options['mode'] ?? 'github_pull_request' ),
				'secrets'         => array(
					array(
						'id'    => 'github_webhook',
						'value' => $secret,
					),
				),
				'allowed_actions' => 'github_workflow_run' === ( $options['mode'] ?? '' )
					? self::DEFAULT_WORKFLOW_RUN_ALLOWED_ACTIONS
					: self::DEFAULT_ALLOWED_ACTIONS,
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
		$default = 'github_workflow_run' === ( $config['mode'] ?? '' ) ? self::DEFAULT_WORKFLOW_RUN_ALLOWED_ACTIONS : self::DEFAULT_ALLOWED_ACTIONS;
		$actions = $config['allowed_actions'] ?? $default;
		if ( is_string( $actions ) ) {
			$actions = array_map( 'trim', explode( ',', $actions ) );
		}
		$actions = array_values( array_filter( array_map( 'strval', (array) $actions ) ) );
		return ! empty( $actions ) ? $actions : $default;
	}

	/**
	 * @param mixed $value Raw list value.
	 * @return array<int,string>
	 */
	private static function stringList( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}
		return array_values( array_filter( array_map( 'strval', (array) $value ) ) );
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
