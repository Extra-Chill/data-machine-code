<?php
/**
 * GitHub PR review flow installer.
 *
 * @package DataMachineCode\GitHub
 */

namespace DataMachineCode\GitHub;

use DataMachineCode\Support\GitHubWebhookValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Installs repo-scoped PR review flows through Data Machine public abilities.
 */
class PrReviewFlowInstaller {

	const SCHEDULING_MARKER_KEY = 'data_machine_code_github_pr_review';

	/**
	 * Install a repo-specific PR review pipeline + flow.
	 *
	 * @param array<string,mixed> $args Installer options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function install( array $args ): array|\WP_Error {
		$definition = PrReviewFlowScaffold::build( $args );
		$repo       = (string) ( $definition['repo'] ?? '' );
		if ( '' === $repo ) {
			return new \WP_Error( 'missing_repo', 'Repository is required.' );
		}

		$force = ! empty( $args['force'] );
		if ( ! $force ) {
			$existing = self::findExistingInstall( $repo );
			if ( $existing ) {
				return new \WP_Error(
					'github_pr_review_flow_exists',
					sprintf( 'A GitHub PR review flow already exists for %s (flow_id=%d). Pass --force to create another one.', $repo, (int) $existing['flow_id'] ),
					array( 'existing' => $existing )
				);
			}
		}

		$agent_id = self::resolveAgentId( (string) ( $args['agent'] ?? '' ) );
		if ( is_wp_error( $agent_id ) ) {
			return $agent_id;
		}

		$create_pipeline = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/create-pipeline' ) : null;
		if ( ! $create_pipeline ) {
			return new \WP_Error( 'create_pipeline_missing', 'Data Machine create-pipeline ability is not available. Ensure Data Machine 0.86.0 or newer is active.' );
		}

		$actions = $definition['webhook']['actions'] ?? GitHubWebhookValidator::DEFAULT_ALLOWED_ACTIONS;
		$name    = (string) ( $definition['name'] ?? 'GitHub PR review' );

		$create_input = array(
			'pipeline_name' => $name,
			'workflow'      => $definition['workflow'] ?? array(),
			'flow_config'   => array(
				'flow_name'         => $name,
				'scheduling_config' => self::buildSchedulingConfig( $repo, $actions, $definition ),
			),
		);

		if ( null !== $agent_id ) {
			$create_input['agent_id'] = $agent_id;
		}

		$created = $create_pipeline->execute( $create_input );
		if ( empty( $created['success'] ) || empty( $created['flow_id'] ) ) {
			return new \WP_Error( 'create_pipeline_failed', (string) ( $created['error'] ?? 'Failed to create Data Machine pipeline/flow.' ), $created );
		}

		$webhook = self::enableWebhook( (int) $created['flow_id'], $repo, $actions, $args );
		if ( is_wp_error( $webhook ) ) {
			return $webhook;
		}

		return array(
			'success'         => true,
			'status'          => 'installed',
			'repo'            => $repo,
			'pipeline_id'     => (int) $created['pipeline_id'],
			'pipeline_name'   => (string) $created['pipeline_name'],
			'flow_id'         => (int) $created['flow_id'],
			'flow_name'       => (string) $created['flow_name'],
			'flow_step_ids'   => $created['flow_step_ids'] ?? array(),
			'webhook_url'     => (string) ( $webhook['webhook_url'] ?? '' ),
			'webhook_event'   => 'pull_request',
			'webhook_actions' => array_values( (array) $actions ),
			'webhook_auth'    => array(
				'mode'         => 'github_pull_request',
				'secret_ids'   => $webhook['secret_ids'] ?? array(),
				'secret'       => $webhook['secret'] ?? null,
				'instructions' => 'Configure the GitHub repository webhook for pull_request events, use the webhook_url, and set the secret shown here or stored under the listed secret id.',
			),
			'scaffold_schema' => (string) ( $definition['schema'] ?? '' ),
		);
	}

	/**
	 * @param array<int,string> $actions
	 * @param array<string,mixed> $definition
	 * @return array<string,mixed>
	 */
	private static function buildSchedulingConfig( string $repo, array $actions, array $definition ): array {
		$config = array(
			'interval'        => 'manual',
			'webhook_event'   => 'pull_request',
			'webhook_actions' => array_values( $actions ),
		);

		$config[ self::SCHEDULING_MARKER_KEY ] = array(
			'schema' => (string) ( $definition['schema'] ?? '' ),
			'repo'   => $repo,
		);

		return $config;
	}

	/**
	 * @param array<int,string> $actions
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function enableWebhook( int $flow_id, string $repo, array $actions, array $args ): array|\WP_Error {
		$enable_webhook = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/webhook-trigger-enable' ) : null;
		if ( ! $enable_webhook ) {
			return new \WP_Error( 'webhook_enable_missing', 'Data Machine webhook-trigger-enable ability is not available.' );
		}

		$secret = isset( $args['secret'] ) ? (string) $args['secret'] : '';
		$input  = array(
			'flow_id'         => $flow_id,
			'auth_mode'       => 'hmac',
			'template'        => GitHubWebhookValidator::buildVerifierConfig(
				'',
				array(
					'repo'            => $repo,
					'allowed_repos'   => array( $repo ),
					'allowed_actions' => array_values( $actions ),
					'allow_drafts'    => ! empty( $args['allow_drafts'] ),
				)
			),
			'secret_id'       => self::sanitizeSecretId( (string) ( $args['secret_id'] ?? 'github_webhook' ) ),
			'generate_secret' => '' === $secret,
		);

		// WebhookTriggerAbility owns the secret roster; the template only carries policy.
		unset( $input['template']['secrets'] );
		if ( '' !== $secret ) {
			$input['secret'] = $secret;
		}

		$result = $enable_webhook->execute( $input );
		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'webhook_enable_failed', (string) ( $result['error'] ?? 'Failed to enable webhook trigger.' ), $result );
		}

		return $result;
	}

	private static function sanitizeSecretId( string $secret_id ): string {
		$secret_id = function_exists( 'sanitize_key' ) ? sanitize_key( $secret_id ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $secret_id ) );
		return '' !== $secret_id ? $secret_id : 'github_webhook';
	}

	/**
	 * @return int|null|\WP_Error
	 */
	private static function resolveAgentId( string $agent ): int|null|\WP_Error {
		$agent = trim( $agent );
		if ( '' === $agent ) {
			return null;
		}

		if ( ctype_digit( $agent ) ) {
			return (int) $agent;
		}

		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
			return new \WP_Error( 'agent_repository_missing', 'Data Machine agent repository is not available; pass a numeric --agent ID or omit --agent.' );
		}

		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $agent ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '-', $agent ) );
		$repo = new \DataMachine\Core\Database\Agents\Agents();
		$row  = $repo->get_by_slug( $slug );
		if ( ! $row ) {
			return new \WP_Error( 'agent_not_found', sprintf( 'Agent "%s" was not found.', $agent ) );
		}

		return (int) $row['agent_id'];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function findExistingInstall( string $repo ): ?array {
		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			return null;
		}

		$flows_repo = new \DataMachine\Core\Database\Flows\Flows();
		$offset     = 0;
		$per_page   = 100;
		$flow_count = 0;
		do {
			$flows      = $flows_repo->get_all_flows_summary( $per_page, $offset );
			$flow_count = count( $flows );
			foreach ( $flows as $flow ) {
				$config = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
				$marker = is_array( $config[ self::SCHEDULING_MARKER_KEY ] ?? null ) ? $config[ self::SCHEDULING_MARKER_KEY ] : array();
				if ( strtolower( $repo ) === strtolower( (string) ( $marker['repo'] ?? '' ) ) ) {
					return array(
						'flow_id'     => (int) $flow['flow_id'],
						'flow_name'   => (string) $flow['flow_name'],
						'pipeline_id' => (int) $flow['pipeline_id'],
					);
				}
			}
			$offset += $per_page;
		} while ( $flow_count === $per_page );

		return null;
	}
}
