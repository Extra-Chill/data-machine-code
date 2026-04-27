<?php
/**
 * Pure-PHP smoke for the GitHub PR review flow scaffold definition.
 *
 * Run: php tests/smoke-github-pr-review-flow-scaffold.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-pr-review-flow-scaffold/' );
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string { return trim( (string) $value ); }
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	}

	require __DIR__ . '/../inc/GitHub/PrReviewFlowScaffold.php';

	use DataMachineCode\GitHub\PrReviewFlowScaffold;

	$failures = array();
	$total    = 0;

	$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "GitHub PR review flow scaffold - smoke\n";

	$definition = PrReviewFlowScaffold::build( array(
		'repo'         => 'Extra-Chill/data-machine-code',
		'agent'        => 'code-reviewer',
		'name'         => 'DMC PR review',
		'comment_mode' => 'managed',
	) );

	$assert( 'schema identifies scaffold version', 'data-machine-code/github-pr-review-flow-scaffold/v1' === $definition['schema'] );
	$assert( 'definition is explicit that it is not direct runtime magic', 'definition_only' === $definition['status'] );
	$assert( 'repo carries through', 'Extra-Chill/data-machine-code' === $definition['repo'] );
	$assert( 'agent carries through', 'code-reviewer' === $definition['agent'] );
	$assert( 'name carries through', 'DMC PR review' === $definition['name'] );
	$assert( 'comment mode defaults to managed', 'managed' === $definition['comment_mode'] );

	$webhook = $definition['webhook'];
	$assert( 'webhook provider is GitHub', 'github' === $webhook['provider'] );
	$assert( 'webhook event is pull_request', 'pull_request' === $webhook['event'] );
	$assert( 'default actions include opened', in_array( 'opened', $webhook['actions'], true ) );
	$assert( 'default actions include reopened', in_array( 'reopened', $webhook['actions'], true ) );
	$assert( 'default actions include synchronize', in_array( 'synchronize', $webhook['actions'], true ) );
	$assert( 'default actions include ready_for_review', in_array( 'ready_for_review', $webhook['actions'], true ) );
	$assert( 'dedupe key is repo plus PR number plus head SHA', '{repository.full_name}#{pull_request.number}@{pull_request.head.sha}' === $webhook['dedupe_key'] );
	$assert( 'repo payload path documented', 'repository.full_name' === $webhook['payload_paths']['repo'] );
	$assert( 'PR number payload path documented', 'pull_request.number' === $webhook['payload_paths']['pull_number'] );
	$assert( 'action payload path documented', 'action' === $webhook['payload_paths']['action'] );
	$assert( 'head SHA payload path documented', 'pull_request.head.sha' === $webhook['payload_paths']['head_sha'] );

	$steps = $definition['workflow']['steps'];
	$assert( 'three scaffold steps are present', 3 === count( $steps ) );
	$assert( 'step 1 is webhook_payload fetch', 'fetch' === $steps[0]['type'] && 'webhook_payload' === $steps[0]['handler_slug'] );
	$assert( 'step 2 is GitHub pull_review_context fetch', 'fetch' === $steps[1]['type'] && 'github' === $steps[1]['handler_slug'] && 'pull_review_context' === $steps[1]['handler_config']['data_source'] );
	$assert( 'step 3 is AI review', 'ai' === $steps[2]['type'] );

	$payload_config = $steps[0]['handler_config'];
	$assert( 'webhook payload source_type is explicit', 'github_pull_request_webhook' === $payload_config['source_type'] );
	$assert( 'webhook payload maps repo metadata', 'repository.full_name' === $payload_config['metadata']['repo'] );
	$assert( 'webhook payload maps PR number metadata', 'pull_request.number' === $payload_config['metadata']['pull_number'] );
	$assert( 'webhook payload maps action metadata', 'action' === $payload_config['metadata']['action'] );
	$assert( 'webhook payload maps head SHA metadata', 'pull_request.head.sha' === $payload_config['metadata']['head_sha'] );
	$assert( 'webhook payload stores dedupe template', $webhook['dedupe_key'] === $payload_config['item_identifier_template'] );

	$review_config = $steps[1]['handler_config'];
	$assert( 'GitHub review context repo is carried through', 'Extra-Chill/data-machine-code' === $review_config['repo'] );
	$assert( 'GitHub review context uses webhook PR placeholder', '{{metadata.pull_number}}' === $review_config['pull_number'] );
	$assert( 'GitHub review context uses webhook head SHA placeholder', '{{metadata.head_sha}}' === $review_config['head_sha'] );
	$assert( 'review context expansion is conservative by default', false === $review_config['include_file_contents'] && false === $review_config['include_base_contents'] );

	$ai_step = $steps[2];
	$prompt  = $ai_step['user_message'];
	$tools   = $ai_step['enabled_tools'];
	$assert( 'prompt requires findings-first review', str_contains( $prompt, 'findings first' ) || str_contains( $prompt, 'findings' ) );
	$assert( 'prompt rejects praise spam', str_contains( $prompt, 'Do not praise' ) );
	$assert( 'prompt asks for high-confidence findings', str_contains( $prompt, 'high-confidence' ) );
	$assert( 'read-only get_github_pull tool enabled', in_array( 'get_github_pull', $tools, true ) );
	$assert( 'read-only get_github_pull_files tool enabled', in_array( 'get_github_pull_files', $tools, true ) );
	$assert( 'read-only get_github_pull_review_context tool enabled', in_array( 'get_github_pull_review_context', $tools, true ) );
	$assert( 'read-only get_github_file tool enabled', in_array( 'get_github_file', $tools, true ) );
	$assert( 'read-only list_github_tree tool enabled', in_array( 'list_github_tree', $tools, true ) );
	$assert( 'PR comment tool enabled', in_array( 'comment_github_pull_request', $tools, true ) );
	$assert( 'comment policy points at PR comment tool', 'comment_github_pull_request' === $ai_step['comment_policy']['tool'] );
	$assert( 'comment policy includes managed marker', 'data-machine-code-pr-review' === $ai_step['comment_policy']['marker'] );

	$custom = PrReviewFlowScaffold::build( array(
		'actions'      => 'opened,synchronize,invalid action',
		'comment_mode' => 'nonsense',
	) );
	$assert( 'custom actions are normalized', array( 'opened', 'synchronize', 'invalidaction' ) === $custom['webhook']['actions'] );
	$assert( 'invalid comment mode falls back to managed', 'managed' === $custom['comment_mode'] );
	$assert( 'empty repo falls back to metadata placeholder', '{{metadata.repo}}' === $custom['workflow']['steps'][1]['handler_config']['repo'] );

	$command_source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/GitHubCommand.php' );
	$assert( 'CLI exposes review-flow subcommand', str_contains( $command_source, '@subcommand review-flow' ) );
	$assert( 'CLI calls scaffold builder', str_contains( $command_source, 'PrReviewFlowScaffold::build' ) );

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
