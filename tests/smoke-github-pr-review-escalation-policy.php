<?php
/**
 * Pure-PHP smoke for GitHub PR review escalation policy.
 *
 * Run: php tests/smoke-github-pr-review-escalation-policy.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-pr-review-escalation-policy/' );
	}

	require __DIR__ . '/../inc/GitHub/PrReviewEscalationPolicy.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( bool $cond, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $cond ) {
			echo "  ✓ {$message}\n";
			return;
		}

		++$failures;
		echo "  ✗ {$message}\n";
	};

	$has_reason = static fn( array $policy, string $reason ): bool => in_array( $reason, $policy['reasons'] ?? array(), true );
	$pull       = array(
		'number'   => 112,
		'head_sha' => 'abc123head',
	);

	echo "GitHub PR review escalation policy - smoke\n\n";

	$clean = \DataMachineCode\GitHub\PrReviewEscalationPolicy::evaluate(
		'Extra-Chill/data-machine-code',
		$pull,
		array(
			array(
				'filename'    => 'inc/GitHub/PrReviewFlowScaffold.php',
				'changes'     => 10,
				'patch_bytes' => 300,
			),
		),
		array(
			'check_runs'         => array( 'summary' => array( 'state' => 'success' ) ),
			'commit_statuses'    => array( 'summary' => array( 'state' => 'success' ) ),
			'homeboy_ci_results' => array( 'workflow' => array( 'head_sha' => 'abc123head' ) ),
		)
	);
	$assert( 'data-machine-code/pr-review-escalation/v1' === $clean['schema'], 'schema is versioned' );
	$assert( 'v1' === $clean['policy_version'], 'policy_version is v1' );
	$assert( false === $clean['should_escalate'], 'clean PR does not escalate' );
	$assert( array() === $clean['reasons'], 'clean PR has no reasons' );
	$assert( 'github_context_only_review_is_sufficient' === $clean['action_recommendation'], 'clean PR recommends GitHub-context-only review' );

	$risky = \DataMachineCode\GitHub\PrReviewEscalationPolicy::evaluate(
		'Extra-Chill/data-machine-code',
		$pull,
		array(
			array( 'filename' => 'inc/Support/GitHubWebhookValidator.php', 'changes' => 12, 'patch_bytes' => 800 ),
			array( 'filename' => 'inc/Abilities/GitHubAbilities.php', 'changes' => 20, 'patch_bytes' => 900 ),
			array( 'filename' => 'inc/migrations/github-webhook-auth.php', 'changes' => 40, 'patch_bytes' => 1000 ),
			array( 'filename' => 'docs/github-review-flow.md', 'changes' => 8, 'patch_bytes' => 500 ),
		),
		array(
			'errors'      => array( 'homeboy_ci_results' => 'No artifact found.' ),
			'error_codes' => array( 'homeboy_ci_results' => 'github_homeboy_ci_artifact_not_found' ),
		)
	);
	$assert( true === $risky['should_escalate'], 'risk-path PR escalates' );
	$assert( $has_reason( $risky, 'missing_homeboy_artifact' ), 'missing Homeboy artifact escalates' );
	$assert( $has_reason( $risky, 'touches_webhook_auth' ), 'webhook/auth path escalates' );
	$assert( $has_reason( $risky, 'touches_public_api_surface' ), 'ability/tool/CLI public surface escalates' );
	$assert( $has_reason( $risky, 'touches_migrations_jobs_queues' ), 'migration/jobs/queue path escalates' );
	$assert( $has_reason( $risky, 'touches_docs_user_facing_content' ), 'docs/user-facing path escalates' );
	$assert( 'run_checkout_backed_validation_when_available' === $risky['action_recommendation'], 'risky PR recommends checkout-backed validation' );
	$assert( 'not_run_issue_111_pending' === $risky['execution_result_source'], 'policy names placeholder execution source while issue 111 is unavailable' );

	$stale = \DataMachineCode\GitHub\PrReviewEscalationPolicy::evaluate(
		'Extra-Chill/data-machine-code',
		$pull,
		array( array( 'filename' => 'README.md', 'changes' => 1, 'patch_bytes' => 50 ) ),
		array( 'homeboy_ci_results' => array( 'workflow' => array( 'head_sha' => 'oldsha' ) ) )
	);
	$assert( $has_reason( $stale, 'stale_homeboy_artifact' ), 'stale Homeboy artifact head SHA escalates' );

	$failing = \DataMachineCode\GitHub\PrReviewEscalationPolicy::evaluate(
		'Extra-Chill/data-machine-code',
		$pull,
		array( array( 'filename' => 'inc/GitHub/PrReviewFlowScaffold.php', 'changes' => 1, 'patch_bytes' => 50 ) ),
		array(
			'homeboy_ci_results' => array( 'workflow' => array( 'head_sha' => 'abc123head' ) ),
			'check_runs'         => array( 'summary' => array( 'state' => 'pending' ) ),
			'commit_statuses'    => array( 'summary' => array( 'state' => 'failure' ) ),
		)
	);
	$assert( $has_reason( $failing, 'failing_or_unknown_checks' ), 'failing or pending checks/statuses escalate' );
	$assert( 1 === count( array_keys( $failing['reasons'], 'failing_or_unknown_checks', true ) ), 'check/status reason is de-duplicated' );

	$threshold = \DataMachineCode\GitHub\PrReviewEscalationPolicy::evaluate(
		'Extra-Chill/data-machine-code',
		$pull,
		array(
			array( 'filename' => 'src/a.php', 'changes' => 60, 'patch_bytes' => 600 ),
			array( 'filename' => 'src/b.php', 'changes' => 60, 'patch_bytes' => 600 ),
			array( 'filename' => 'src/c.php', 'changes' => 60, 'patch_bytes' => 600 ),
		),
		array( 'homeboy_ci_results' => array( 'workflow' => array( 'head_sha' => 'abc123head' ) ) ),
		array( 'max_files' => 2, 'max_patch_bytes' => 1000, 'max_total_changes' => 100 )
	);
	$assert( $has_reason( $threshold, 'file_count_threshold' ), 'file count threshold escalates' );
	$assert( $has_reason( $threshold, 'patch_size_threshold' ), 'patch size threshold escalates' );
	$assert( $has_reason( $threshold, 'change_count_threshold' ), 'total change threshold escalates' );
	$assert( 3 === $threshold['metrics']['file_count'], 'metrics include file count' );
	$assert( 1800 === $threshold['metrics']['patch_bytes'], 'metrics include patch bytes' );
	$assert( 180 === $threshold['metrics']['total_changes'], 'metrics include total changes' );

	$ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/GitHubAbilities.php' );
	$scaffold_source = file_get_contents( __DIR__ . '/../inc/GitHub/PrReviewFlowScaffold.php' );
	$assert( str_contains( $ability_source, 'PrReviewEscalationPolicy::evaluate' ), 'review context normalization calls the policy' );
	$assert( str_contains( $scaffold_source, "'include_escalation_policy'" ), 'scaffold opts into escalation policy output' );
	$assert( str_contains( $scaffold_source, 'should_escalate=true' ), 'scaffold prompt teaches AI how to handle escalation output' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
