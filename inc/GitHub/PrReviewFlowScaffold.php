<?php
/**
 * GitHub PR review flow scaffold.
 *
 * @package DataMachineCode\GitHub
 */

namespace DataMachineCode\GitHub;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a machine-readable Data Machine workflow scaffold for PR reviews.
 */
class PrReviewFlowScaffold {

	public const DEFAULT_ACTIONS              = array( 'opened', 'reopened', 'synchronize', 'ready_for_review' );
	public const DEFAULT_WORKFLOW_RUN_ACTIONS = array( 'completed' );

	/**
	 * Build the scaffold definition.
	 *
	 * @param array $args Scaffold options.
	 * @return array<string,mixed>
	 */
	public static function build( array $args ): array {
		$repo         = self::clean_string( $args['repo'] ?? '' );
		$name         = self::clean_string( $args['name'] ?? 'GitHub PR review' );
		$agent        = self::clean_string( $args['agent'] ?? '' );
		$comment_mode = self::normalize_comment_mode( $args['comment_mode'] ?? 'managed' );
		$trigger      = self::normalize_trigger( $args['trigger'] ?? 'pull_request' );

		$actions = $args['actions'] ?? self::default_actions_for_trigger( $trigger );
		if ( is_string( $actions ) ) {
			$actions = array_filter( array_map( 'trim', explode( ',', $actions ) ) );
		}
		$actions = array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $actions ) ? $actions : self::default_actions_for_trigger( $trigger ) ) ) ) );
		if ( empty( $actions ) ) {
			$actions = self::default_actions_for_trigger( $trigger );
		}

		$dedupe_key     = self::dedupe_key_for_trigger( $trigger );
		$workflow_names = self::string_list( $args['workflow_names'] ?? $args['workflow-name'] ?? array() );
		$workflow_paths = self::string_list( $args['workflow_paths'] ?? $args['workflow-path'] ?? array() );

		return array(
			'schema'       => 'data-machine-code/github-pr-review-flow-scaffold/v1',
			'name'         => $name,
			'description'  => 'Template for a webhook-triggered GitHub pull request review flow.',
			'repo'         => $repo,
			'agent'        => $agent,
			'comment_mode' => $comment_mode,
			'status'       => 'definition_only',
			'notes'        => array(
				'This scaffold is intentionally a normal Data Machine workflow definition, not runtime magic.',
				'Import/create the pipeline and flow through Data Machine, then inspect and edit the generated steps normally.',
				'The GitHub pull_review_context step uses webhook-derived placeholders for the PR number and head SHA, including Homeboy CI artifacts when available.',
				'The AI review step can call github_repo_review_profile once for bounded repository rules and architecture context before making findings.',
			),
			'webhook'      => array(
				'provider'         => 'github',
				'event'            => $trigger,
				'actions'          => $actions,
				'dedupe_key'       => $dedupe_key,
				'auth_mode'        => 'hmac',
				'preset_hint'      => 'github',
				'payload_paths'    => self::payload_paths_for_trigger( $trigger ),
				'workflow_filters' => array(
					'names' => $workflow_names,
					'paths' => $workflow_paths,
				),
			),
			'workflow'     => array(
				'type'  => 'pipeline_template',
				'steps' => array(
					self::webhook_payload_step( $dedupe_key, $trigger ),
					self::pull_review_context_step( $repo ),
					self::ai_review_step( $comment_mode ),
				),
			),
			'import_hint'  => array(
				'pipeline_name'     => $name,
				'flow_name'         => $name,
				'scheduling_config' => array(
					'interval'        => 'manual',
					'webhook_enabled' => true,
					'webhook_event'   => $trigger,
					'webhook_actions' => $actions,
				),
			),
		);
	}

	/**
	 * Get the default PR review prompt.
	 */
	public static function default_prompt(): string {
		return implode( "\n", array(
			'You are reviewing a GitHub pull request. Return findings first, ordered by severity.',
			'Read the initial pull_review_context packet first, then identify what you still need to know before reviewing.',
			'Call github_repo_review_profile once for stable repo-level architecture, command, and review-rule context before judging project-specific conventions.',
			'Use read-only GitHub tools on demand to inspect specific PR metadata, changed files, base/head file contents, neighboring files, dependency files, or repository tree paths that are necessary to verify a finding.',
			'Read escalation_policy from the pull_review_context packet. Treat should_escalate=true as a deterministic recommendation for checkout-backed validation; if no checkout execution result is present yet, say that deeper validation is recommended instead of pretending it ran.',
			'Keep context gathering bounded: fetch only targeted paths, avoid broad repository scans, and stop when you have enough evidence for or against high-confidence findings.',
			'Only report high-confidence bugs, security risks, behavioral regressions, or missing tests that directly matter for this PR.',
			'Do not praise the change, summarize obvious edits, or leave generic style feedback.',
			'Each finding must include a concise title, severity, affected file/path when known, and why it matters.',
			'If there are no findings, say exactly: No high-confidence findings.',
			'Use the managed PR review comment tool exactly once, after all context gathering is complete, to publish the final review.',
			'When calling that final tool, pass repo, pull_number, body, marker "data-machine-code-pr-review", mode "per_head_sha", and the PR head_sha so repeated runs update the managed comment for the same head only.',
		) );
	}

	/**
	 * Build webhook payload fetch step.
	 *
	 * @param string $dedupe_key Dedupe key template.
	 * @return array<string,mixed>
	 */
	private static function webhook_payload_step( string $dedupe_key, string $trigger ): array {
		$workflow_run = 'workflow_run' === $trigger;
		return array(
			'id'             => $workflow_run ? 'github_workflow_run_payload' : 'github_pull_request_payload',
			'type'           => 'fetch',
			'label'          => $workflow_run ? 'GitHub workflow run webhook payload' : 'GitHub pull request webhook payload',
			'handler_slug'   => 'webhook_payload',
			'handler_config' => array(
				'source_type'              => $workflow_run ? 'github_workflow_run_webhook' : 'github_pull_request_webhook',
				'title_path'               => $workflow_run ? 'workflow_run.display_title' : 'pull_request.title',
				'content_path'             => $workflow_run ? 'workflow_run.html_url' : 'pull_request.body',
				'item_identifier_template' => $dedupe_key,
				'ignore_missing_paths'     => false,
				'metadata'                 => self::payload_paths_for_trigger( $trigger ),
			),
		);
	}

	/**
	 * Build PR review context fetch step.
	 *
	 * @param string $repo GitHub repo.
	 * @return array<string,mixed>
	 */
	private static function pull_review_context_step( string $repo ): array {
		return array(
			'id'             => 'github_pull_review_context',
			'type'           => 'fetch',
			'label'          => 'GitHub PR review context',
			'handler_slug'   => 'github',
			'handler_config' => array(
				'repo'                      => '' !== $repo ? $repo : '{{metadata.repo}}',
				'data_source'               => 'pull_review_context',
				'pull_number'               => '{{metadata.pull_number}}',
				'head_sha'                  => '{{metadata.head_sha}}',
				'max_patch_chars'           => 200000,
				'include_file_contents'     => false,
				'include_base_contents'     => false,
				'context_paths'             => array(),
				'max_file_content_chars'    => 20000,
				'max_context_files'         => 10,
				'max_total_context_chars'   => 100000,
				'include_checks'            => true,
				'include_statuses'          => true,
				'include_homeboy_ci'        => true,
				'include_escalation_policy' => true,
				'max_check_runs'            => 30,
				'include_check_output'      => false,
				'artifact_name'             => 'homeboy-ci-results',
				'escalation_policy'         => array(
					'max_files'         => 20,
					'max_patch_bytes'   => 100000,
					'max_total_changes' => 1000,
				),
			),
		);
	}

	/**
	 * Build AI review step.
	 *
	 * @param string $comment_mode Comment mode.
	 * @return array<string,mixed>
	 */
	private static function ai_review_step( string $comment_mode ): array {
		return array(
			'id'             => 'ai_pr_review',
			'type'           => 'ai',
			'label'          => 'AI PR review',
			'user_message'   => self::default_prompt(),
			'enabled_tools'  => array(
				'get_github_pull',
				'get_github_pull_files',
				'get_github_pull_review_context',
				'get_github_check_runs',
				'get_github_commit_statuses',
				'get_github_homeboy_ci_results',
				'github_repo_review_profile',
				'get_github_file',
				'list_github_tree',
				'upsert_github_pull_review_comment',
			),
			'comment_policy' => array(
				'tool'      => 'upsert_github_pull_review_comment',
				'mode'      => 'per_head_sha',
				'head_sha'  => '{{metadata.head_sha}}',
				'marker'    => 'data-machine-code-pr-review',
				'requested' => $comment_mode,
			),
		);
	}

	/**
	 * Normalize comment mode.
	 *
	 * @param mixed $mode Raw mode.
	 */
	private static function normalize_comment_mode( mixed $mode ): string {
		$mode = function_exists( 'sanitize_key' )
			? sanitize_key( (string) $mode )
			: strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $mode ) );
		return in_array( $mode, array( 'managed', 'append', 'dry_run' ), true ) ? $mode : 'managed';
	}

	/**
	 * Normalize trigger mode.
	 *
	 * @param mixed $trigger Raw trigger mode.
	 */
	private static function normalize_trigger( mixed $trigger ): string {
		$trigger = function_exists( 'sanitize_key' )
			? sanitize_key( (string) $trigger )
			: strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $trigger ) );
		return in_array( $trigger, array( 'pull_request', 'workflow_run' ), true ) ? $trigger : 'pull_request';
	}

	/**
	 * @return array<int,string>
	 */
	private static function default_actions_for_trigger( string $trigger ): array {
		return 'workflow_run' === $trigger ? self::DEFAULT_WORKFLOW_RUN_ACTIONS : self::DEFAULT_ACTIONS;
	}

	/**
	 * Build the existing processed-items dedupe template for a trigger.
	 */
	private static function dedupe_key_for_trigger( string $trigger ): string {
		return 'workflow_run' === $trigger
			? '{repository.full_name}#{workflow_run.pull_requests.0.number}@{workflow_run.head_sha}'
			: '{repository.full_name}#{pull_request.number}@{pull_request.head.sha}';
	}

	/**
	 * @return array<string,string>
	 */
	private static function payload_paths_for_trigger( string $trigger ): array {
		if ( 'workflow_run' === $trigger ) {
			return array(
				'repo'            => 'repository.full_name',
				'pull_number'     => 'workflow_run.pull_requests.0.number',
				'action'          => 'action',
				'head_sha'        => 'workflow_run.head_sha',
				'workflow_run_id' => 'workflow_run.id',
				'workflow_name'   => 'workflow_run.name',
				'conclusion'      => 'workflow_run.conclusion',
			);
		}

		return array(
			'repo'        => 'repository.full_name',
			'pull_number' => 'pull_request.number',
			'action'      => 'action',
			'head_sha'    => 'pull_request.head.sha',
		);
	}

	/**
	 * @param mixed $value Raw list value.
	 * @return array<int,string>
	 */
	private static function string_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}
		return array_values( array_filter( array_map( 'strval', (array) $value ) ) );
	}

	/**
	 * Clean a string with WordPress helpers when available.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function clean_string( mixed $value ): string {
		$value = trim( (string) $value );
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : $value;
	}
}
