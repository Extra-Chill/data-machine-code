<?php
/**
 * Pure-PHP smoke for installing GitHub PR review flows from the scaffold.
 *
 * Run: php tests/smoke-github-pr-review-flow-installer.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public static array $summary_rows = array();

		public function get_all_flows_summary( int $per_page = 20, int $offset = 0, ?int $user_id = null, ?int $agent_id = null ): array {
			unset( $per_page, $user_id, $agent_id );
			return array_slice( self::$summary_rows, $offset );
		}
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public function get_by_slug( string $agent_slug ): ?array {
			return 'code-reviewer' === $agent_slug ? array( 'agent_id' => 42, 'agent_slug' => $agent_slug ) : null;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function sanitize_title( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '-', trim( (string) $value ) ) ); }
	function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }

	class TestCreatePipelineAbility {
		public array $last_input = array();
		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'       => true,
				'pipeline_id'   => 88,
				'pipeline_name' => $input['pipeline_name'],
				'flow_id'       => 144,
				'flow_name'     => $input['flow_config']['flow_name'],
				'flow_step_ids' => array( 'step-a', 'step-b', 'step-c' ),
			);
		}
	}

	class TestWebhookEnableAbility {
		public array $last_input = array();
		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'     => true,
				'flow_id'     => $input['flow_id'],
				'webhook_url' => 'https://example.test/wp-json/datamachine/v1/webhook/144',
				'auth_mode'   => 'hmac',
				'secret'      => 'generated-secret',
				'secret_ids'  => array( 'github_pr_review' ),
			);
		}
	}

	$GLOBALS['dmc_test_create_pipeline'] = new TestCreatePipelineAbility();
	$GLOBALS['dmc_test_webhook_enable']  = new TestWebhookEnableAbility();

	function wp_get_ability( string $name ) {
		return match ( $name ) {
			'datamachine/create-pipeline'        => $GLOBALS['dmc_test_create_pipeline'],
			'datamachine/webhook-trigger-enable' => $GLOBALS['dmc_test_webhook_enable'],
			default                              => null,
		};
	}

	require __DIR__ . '/../inc/Support/GitHubWebhookValidator.php';
	require __DIR__ . '/../inc/GitHub/PrReviewFlowScaffold.php';
	require __DIR__ . '/../inc/GitHub/PrReviewFlowInstaller.php';

	use DataMachine\Core\Database\Flows\Flows;
	use DataMachineCode\GitHub\PrReviewFlowInstaller;

	$failures = 0;
	$total    = 0;
	$assert   = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$message}\n";
			return;
		}
		++$failures;
		echo "  fail {$message}\n";
	};

	echo "GitHub PR review flow installer - smoke\n";

	Flows::$summary_rows = array();
	$result = PrReviewFlowInstaller::install( array(
		'repo'      => 'Extra-Chill/data-machine-code',
		'agent'     => 'code-reviewer',
		'name'      => 'DMC PR review',
		'actions'   => 'opened,synchronize',
		'secret_id' => 'github_pr_review',
	) );

	$create_input  = $GLOBALS['dmc_test_create_pipeline']->last_input;
	$webhook_input = $GLOBALS['dmc_test_webhook_enable']->last_input;

	$assert( ! is_wp_error( $result ), 'installer succeeds through public abilities' );
	$assert( 88 === ( $result['pipeline_id'] ?? null ), 'result returns pipeline id' );
	$assert( 144 === ( $result['flow_id'] ?? null ), 'result returns flow id' );
	$assert( 'https://example.test/wp-json/datamachine/v1/webhook/144' === ( $result['webhook_url'] ?? '' ), 'result returns webhook URL' );
	$assert( 'generated-secret' === ( $result['webhook_auth']['secret'] ?? '' ), 'result returns generated secret once' );
	$assert( 42 === ( $create_input['agent_id'] ?? null ), 'agent slug resolves to agent id' );
	$assert( 3 === count( $create_input['workflow']['steps'] ?? array() ), 'create-pipeline receives three scaffold workflow steps' );
	$assert( 'manual' === ( $create_input['flow_config']['scheduling_config']['interval'] ?? '' ), 'flow scheduling starts manual' );
	$assert( 'Extra-Chill/data-machine-code' === ( $create_input['flow_config']['scheduling_config']['data_machine_code_github_pr_review']['repo'] ?? '' ), 'flow scheduling carries repo marker' );
	$assert( 144 === ( $webhook_input['flow_id'] ?? null ), 'webhook enable targets created flow' );
	$assert( 'github_pull_request' === ( $webhook_input['template']['mode'] ?? '' ), 'webhook template uses GitHub PR verifier mode' );
	$assert( array( 'Extra-Chill/data-machine-code' ) === ( $webhook_input['template']['allowed_repos'] ?? array() ), 'webhook verifier scopes repo allow-list' );
	$assert( array( 'opened', 'synchronize' ) === ( $webhook_input['template']['allowed_actions'] ?? array() ), 'webhook verifier scopes allowed actions' );
	$assert( ! isset( $webhook_input['template']['secrets'] ), 'template does not carry raw secret storage' );
	$assert( true === ( $webhook_input['generate_secret'] ?? null ), 'installer generates secret by default' );
	$assert( 'github_pr_review' === ( $webhook_input['secret_id'] ?? '' ), 'installer passes requested secret id' );

	Flows::$summary_rows = array(
		array(
			'flow_id'           => 99,
			'flow_name'         => 'Existing review',
			'pipeline_id'       => 77,
			'scheduling_config' => array(
				'data_machine_code_github_pr_review' => array( 'repo' => 'extra-chill/data-machine-code' ),
			),
		),
	);
	$existing = PrReviewFlowInstaller::install( array( 'repo' => 'Extra-Chill/data-machine-code' ) );
	$assert( is_wp_error( $existing ) && 'github_pr_review_flow_exists' === $existing->get_error_code(), 'existing repo install fails clearly' );

	$forced = PrReviewFlowInstaller::install( array( 'repo' => 'Extra-Chill/data-machine-code', 'force' => true ) );
	$assert( ! is_wp_error( $forced ), '--force bypasses existing install guard' );

	$command_source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/GitHubCommand.php' );
	$assert( str_contains( $command_source, "array( 'create', 'install' )" ), 'CLI accepts install action' );
	$assert( str_contains( $command_source, "'mode'" ), 'CLI keeps scaffold/install mode option' );
	$assert( str_contains( $command_source, 'PrReviewFlowInstaller::install' ), 'CLI routes install mode to installer' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
