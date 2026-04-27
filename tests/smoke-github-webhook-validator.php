<?php
/**
 * Pure-PHP smoke test for GitHub pull request webhook validation.
 *
 * Run: php tests/smoke-github-webhook-validator.php
 */

namespace DataMachine\Api {
	if ( ! class_exists( __NAMESPACE__ . '\\WebhookVerificationResult' ) ) {
		class WebhookVerificationResult {
			const OK                = 'ok';
			const BAD_SIGNATURE     = 'bad_signature';
			const MISSING_SIGNATURE = 'missing_signature';
			const NO_ACTIVE_SECRET  = 'no_active_secret';
			const PAYLOAD_TOO_LARGE = 'payload_too_large';

			public bool $ok;
			public string $reason;
			public ?string $secret_id;
			public ?int $timestamp;
			public ?int $skew_seconds;
			public ?string $detail;

			public function __construct( bool $ok, string $reason, ?string $secret_id = null, ?int $timestamp = null, ?int $skew_seconds = null, ?string $detail = null ) {
				$this->ok           = $ok;
				$this->reason       = $reason;
				$this->secret_id    = $secret_id;
				$this->timestamp    = $timestamp;
				$this->skew_seconds = $skew_seconds;
				$this->detail       = $detail;
			}

			public static function ok( ?string $secret_id = null, ?int $timestamp = null, ?int $skew = null ): self {
				return new self( true, self::OK, $secret_id, $timestamp, $skew );
			}

			public static function fail( string $reason, ?string $detail = null, ?int $timestamp = null, ?int $skew = null ): self {
				return new self( false, $reason, null, $timestamp, $skew, $detail );
			}
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-github-webhook-validator/' );
	}

	require __DIR__ . '/../inc/Support/GitHubWebhookValidator.php';

	use DataMachine\Api\WebhookVerificationResult;
	use DataMachineCode\Support\GitHubWebhookValidator;

	$passes   = 0;
	$failures = 0;

	$assert = function ( bool $condition, string $message ) use ( &$passes, &$failures ): void {
		if ( $condition ) {
			++$passes;
			echo "  PASS: {$message}\n";
			return;
		}
		++$failures;
		echo "  FAIL: {$message}\n";
	};

	$secret = 'test-webhook-secret';
	$body   = json_encode(
		array(
			'action'       => 'opened',
			'repository'   => array( 'full_name' => 'Extra-Chill/data-machine-code' ),
			'pull_request' => array(
				'number' => 96,
				'draft'  => false,
			),
		)
	);
	$signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	$config    = GitHubWebhookValidator::buildVerifierConfig(
		$secret,
		array( 'repo' => 'Extra-Chill/data-machine-code' )
	);

	$verify = function ( string $request_body, array $headers, array $override = array() ) use ( $config ) {
		return GitHubWebhookValidator::verify(
			$request_body,
			$headers,
			array(),
			array(),
			'https://example.test/wp-json/datamachine/v1/trigger/1',
			array_merge( $config, $override ),
			1700000000
		);
	};

	echo "GitHub webhook validator smoke\n\n";

	$valid = $verify(
		$body,
		array(
			'X-Hub-Signature-256' => $signature,
			'X-GitHub-Event'       => 'pull_request',
		)
	);
	$assert( $valid->ok, 'valid signature passes' );
	$assert( 'github_webhook' === $valid->secret_id, 'valid result carries secret id only' );

	$missing = $verify( $body, array( 'X-GitHub-Event' => 'pull_request' ) );
	$assert( ! $missing->ok && WebhookVerificationResult::MISSING_SIGNATURE === $missing->reason, 'missing signature fails' );

	$invalid = $verify(
		$body,
		array(
			'X-Hub-Signature-256' => 'sha256=' . str_repeat( '0', 64 ),
			'X-GitHub-Event'       => 'pull_request',
		)
	);
	$assert( ! $invalid->ok && WebhookVerificationResult::BAD_SIGNATURE === $invalid->reason, 'invalid signature fails' );

	$wrong_event = $verify(
		$body,
		array(
			'X-Hub-Signature-256' => $signature,
			'X-GitHub-Event'       => 'issues',
		)
	);
	$assert( ! $wrong_event->ok && GitHubWebhookValidator::WRONG_EVENT === $wrong_event->reason, 'wrong event fails' );

	$closed_body = str_replace( '"opened"', '"closed"', $body );
	$closed_sig  = 'sha256=' . hash_hmac( 'sha256', $closed_body, $secret );
	$closed      = $verify(
		$closed_body,
		array(
			'X-Hub-Signature-256' => $closed_sig,
			'X-GitHub-Event'       => 'pull_request',
		)
	);
	$assert( ! $closed->ok && GitHubWebhookValidator::DISALLOWED_ACTION === $closed->reason, 'disallowed action fails' );

	$repo_mismatch = $verify(
		$body,
		array(
			'X-Hub-Signature-256' => $signature,
			'X-GitHub-Event'       => 'pull_request',
		),
		array( 'repo' => 'Extra-Chill/data-machine' )
	);
	$assert( ! $repo_mismatch->ok && GitHubWebhookValidator::REPO_MISMATCH === $repo_mismatch->reason, 'repo mismatch fails' );

	$draft_body = str_replace( '"draft":false', '"draft":true', $body );
	$draft_sig  = 'sha256=' . hash_hmac( 'sha256', $draft_body, $secret );
	$draft      = $verify(
		$draft_body,
		array(
			'X-Hub-Signature-256' => $draft_sig,
			'X-GitHub-Event'       => 'pull_request',
		)
	);
	$assert( ! $draft->ok && GitHubWebhookValidator::DRAFT_SKIPPED === $draft->reason, 'draft PRs skip by default' );

	$draft_allowed = $verify(
		$draft_body,
		array(
			'X-Hub-Signature-256' => $draft_sig,
			'X-GitHub-Event'       => 'pull_request',
		),
		array( 'allow_drafts' => true )
	);
	$assert( $draft_allowed->ok, 'draft PR behavior can be explicitly enabled' );

	foreach ( array( $missing, $invalid, $wrong_event, $closed, $repo_mismatch, $draft ) as $result ) {
		$encoded = json_encode( $result );
		$assert( false === strpos( $encoded, $secret ), 'errors do not leak secret' );
		$assert( false === strpos( $encoded, $signature ), 'errors do not leak full signature' );
	}

	$plugin_source = file_get_contents( __DIR__ . '/../data-machine-code.php' );
	$assert( str_contains( $plugin_source, "'github_pull_request'" ), 'plugin registers github_pull_request verifier mode' );

	echo "\n{$passes} passed, {$failures} failed\n";
	if ( $failures > 0 ) {
		exit( 1 );
	}
}
