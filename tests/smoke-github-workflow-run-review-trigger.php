<?php
/**
 * Pure-PHP smoke for GitHub workflow_run review trigger support.
 *
 * Run: php tests/smoke-github-workflow-run-review-trigger.php
 */

declare( strict_types=1 );

namespace DataMachine\Api {
	if ( ! class_exists( __NAMESPACE__ . '\\WebhookVerificationResult' ) ) {
		class WebhookVerificationResult {
			const OK                = 'ok';
			const BAD_SIGNATURE     = 'bad_signature';
			const MISSING_SIGNATURE = 'missing_signature';
			const NO_ACTIVE_SECRET  = 'no_active_secret';
			const PAYLOAD_TOO_LARGE = 'payload_too_large';

			public function __construct( public bool $ok, public string $reason, public ?string $secret_id = null, public ?string $detail = null ) {}

			public static function ok( ?string $secret_id = null, ?int $timestamp = null, ?int $skew = null ): self {
				unset( $timestamp, $skew );
				return new self( true, self::OK, $secret_id );
			}

			public static function fail( string $reason, ?string $detail = null, ?int $timestamp = null, ?int $skew = null ): self {
				unset( $timestamp, $skew );
				return new self( false, $reason, null, $detail );
			}
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-github-workflow-run-review-trigger/' );
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	}

	require __DIR__ . '/../inc/Support/GitHubWebhookValidator.php';
	require __DIR__ . '/../inc/GitHub/PrReviewFlowScaffold.php';

	use DataMachineCode\GitHub\PrReviewFlowScaffold;
	use DataMachineCode\Support\GitHubWebhookValidator;

	$failures = array();
	$total    = 0;
	$assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	$secret  = 'workflow-run-secret';
	$payload = array(
		'action'       => 'completed',
		'repository'   => array( 'full_name' => 'Extra-Chill/data-machine-code' ),
		'workflow_run' => array(
			'id'            => 987654,
			'name'          => 'Homeboy CI',
			'path'          => '.github/workflows/homeboy.yml',
			'head_sha'      => 'abc123def456',
			'conclusion'    => 'success',
			'display_title' => 'PR checks',
			'html_url'      => 'https://github.com/Extra-Chill/data-machine-code/actions/runs/987654',
			'pull_requests' => array(
				array( 'number' => 139 ),
			),
		),
	);
	$headers = function ( string $request_body, string $event = 'workflow_run' ) use ( $secret ): array {
		return array(
			'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $request_body, $secret ),
			'X-GitHub-Event'       => $event,
		);
	};
	$config  = GitHubWebhookValidator::buildVerifierConfig(
		$secret,
		array(
			'mode'           => 'github_workflow_run',
			'repo'           => 'Extra-Chill/data-machine-code',
			'workflow_names' => array( 'Homeboy CI' ),
			'workflow_paths' => array( '.github/workflows/homeboy.yml' ),
		)
	);
	$verify  = function ( array $request_payload, array $override = array(), string $event = 'workflow_run' ) use ( $config, $headers ) {
		$request_body = (string) json_encode( $request_payload );
		return GitHubWebhookValidator::verify(
			$request_body,
			$headers( $request_body, $event ),
			array(),
			array(),
			'https://example.test/wp-json/datamachine/v1/trigger/4',
			array_merge( $config, $override ),
			1700000000
		);
	};

	echo "GitHub workflow_run review trigger - smoke\n";

	$valid = $verify( $payload );
	$assert( 'valid workflow_run.completed payload passes', $valid->ok );
	$assert( 'valid workflow_run carries secret id only', 'github_webhook' === $valid->secret_id );

	$wrong_action = $payload;
	$wrong_action['action'] = 'requested';
	$wrong_action_result = $verify( $wrong_action );
	$assert( 'wrong workflow_run action is rejected clearly', ! $wrong_action_result->ok && GitHubWebhookValidator::DISALLOWED_ACTION === $wrong_action_result->reason && str_contains( (string) $wrong_action_result->detail, 'requested' ) );

	$wrong_repo = $payload;
	$wrong_repo['repository']['full_name'] = 'Extra-Chill/data-machine';
	$wrong_repo_result = $verify( $wrong_repo );
	$assert( 'wrong repo is rejected', ! $wrong_repo_result->ok && GitHubWebhookValidator::REPO_MISMATCH === $wrong_repo_result->reason );

	$wrong_workflow = $payload;
	$wrong_workflow['workflow_run']['name'] = 'Other CI';
	$wrong_workflow_result = $verify( $wrong_workflow );
	$assert( 'workflow name filter rejects non-matching workflow', ! $wrong_workflow_result->ok && GitHubWebhookValidator::WORKFLOW_MISMATCH === $wrong_workflow_result->reason );

	$no_pr = $payload;
	$no_pr['workflow_run']['pull_requests'] = array();
	$no_pr_result = $verify( $no_pr );
	$assert( 'workflow_run without associated PR returns clear no-op reason', ! $no_pr_result->ok && GitHubWebhookValidator::NO_PULL_REQUEST === $no_pr_result->reason );

	$wrong_event = $verify( $payload, array(), 'pull_request' );
	$assert( 'wrong GitHub event is rejected', ! $wrong_event->ok && GitHubWebhookValidator::WRONG_EVENT === $wrong_event->reason );

	$definition = PrReviewFlowScaffold::build(
		array(
			'repo'           => 'Extra-Chill/data-machine-code',
			'trigger'        => 'workflow_run',
			'workflow_names' => 'Homeboy CI',
			'workflow_paths' => '.github/workflows/homeboy.yml',
		)
	);
	$webhook = $definition['webhook'];
	$payload_config = $definition['workflow']['steps'][0]['handler_config'];
	$review_config = $definition['workflow']['steps'][1]['handler_config'];

	$assert( 'scaffold event is workflow_run', 'workflow_run' === $webhook['event'] );
	$assert( 'scaffold default action is completed', array( 'completed' ) === $webhook['actions'] );
	$assert( 'scaffold dedupe remains repo plus PR number plus head SHA', '{repository.full_name}#{workflow_run.pull_requests.0.number}@{workflow_run.head_sha}' === $webhook['dedupe_key'] );
	$assert( 'scaffold maps repo metadata path', 'repository.full_name' === $webhook['payload_paths']['repo'] );
	$assert( 'scaffold maps workflow_run PR number metadata path', 'workflow_run.pull_requests.0.number' === $webhook['payload_paths']['pull_number'] );
	$assert( 'scaffold maps workflow_run head SHA metadata path', 'workflow_run.head_sha' === $webhook['payload_paths']['head_sha'] );
	$assert( 'scaffold maps workflow run id metadata path', 'workflow_run.id' === $webhook['payload_paths']['workflow_run_id'] );
	$assert( 'scaffold maps workflow name metadata path', 'workflow_run.name' === $webhook['payload_paths']['workflow_name'] );
	$assert( 'scaffold maps conclusion metadata path', 'workflow_run.conclusion' === $webhook['payload_paths']['conclusion'] );
	$assert( 'scaffold records workflow filters', array( 'Homeboy CI' ) === $webhook['workflow_filters']['names'] && array( '.github/workflows/homeboy.yml' ) === $webhook['workflow_filters']['paths'] );
	$assert( 'webhook payload source type is workflow_run specific', 'github_workflow_run_webhook' === $payload_config['source_type'] );
	$assert( 'webhook payload metadata mirrors workflow_run payload paths', $webhook['payload_paths'] === $payload_config['metadata'] );
	$assert( 'pull review context still uses existing webhook-derived placeholders', '{{metadata.pull_number}}' === $review_config['pull_number'] && '{{metadata.head_sha}}' === $review_config['head_sha'] );
	$assert( 'import hint carries workflow_run event and completed action', 'workflow_run' === $definition['import_hint']['scheduling_config']['webhook_event'] && array( 'completed' ) === $definition['import_hint']['scheduling_config']['webhook_actions'] );

	$plugin_source = (string) file_get_contents( __DIR__ . '/../data-machine-code.php' );
	$assert( 'plugin registers github_workflow_run verifier mode', str_contains( $plugin_source, "'github_workflow_run'" ) );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s) failed out of {$total}\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK ({$total} assertions)\n";
	exit( 0 );
}
