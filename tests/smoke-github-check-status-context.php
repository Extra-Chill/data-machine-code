<?php
/**
 * Pure-PHP smoke for GitHub check/status context normalization.
 *
 * Run: php tests/smoke-github-check-status-context.php
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-github-check-status-context/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof \WP_Error; }
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $options = 0 ) { return json_encode( $data, $options ); }
	}

	require __DIR__ . '/../inc/Abilities/GitHubAbilities.php';

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

	echo "GitHub check/status context - smoke\n\n";

	$raw_check_runs = array(
		array(
			'id'           => 11,
			'name'         => 'lint',
			'status'       => 'completed',
			'conclusion'   => 'success',
			'html_url'     => 'https://github.test/checks/11',
			'details_url'  => 'https://ci.test/lint',
			'started_at'   => '2026-04-27T00:00:00Z',
			'completed_at' => '2026-04-27T00:01:00Z',
			'app'          => array( 'slug' => 'github-actions' ),
		),
		array(
			'id'         => 12,
			'name'       => 'test',
			'status'     => 'completed',
			'conclusion' => 'failure',
			'html_url'   => 'https://github.test/checks/12',
			'output'     => array(
				'title'   => 'Tests failed',
				'summary' => '2 failed assertions',
				'text'    => str_repeat( 'failure detail ', 500 ),
			),
		),
		array(
			'id'     => 13,
			'name'   => 'build',
			'status' => 'in_progress',
		),
	);

	$check_runs = array_map(
		fn( $run ) => \DataMachineCode\Abilities\GitHubAbilities::normalizeCheckRun( $run, true ),
		$raw_check_runs
	);
	$check_summary = \DataMachineCode\Abilities\GitHubAbilities::summarizeCheckRuns( $check_runs );

	$assert( 'github-actions' === $check_runs[0]['app'], 'check run keeps app slug' );
	$assert( '2 failed assertions' === $check_runs[1]['output']['summary'], 'check output summary is included when requested' );
	$assert( 4000 === strlen( $check_runs[1]['output']['text'] ), 'check output text is bounded' );
	$assert( 'failure' === $check_summary['state'], 'check summary prefers failure over pending' );
	$assert( 1 === $check_summary['counts']['success'], 'check summary counts success' );
	$assert( 1 === $check_summary['counts']['failure'], 'check summary counts failure' );
	$assert( 1 === $check_summary['counts']['pending'], 'check summary counts pending' );
	$assert( 'test' === $check_summary['failing'][0]['name'], 'check summary lists failing check name' );
	$assert( 'https://github.test/checks/12' === $check_summary['failing'][0]['html_url'], 'check summary lists failing check URL' );

	$raw_statuses = array(
		array(
			'id'          => 21,
			'context'     => 'ci/legacy',
			'state'       => 'error',
			'description' => 'legacy CI errored',
			'target_url'  => 'https://ci.test/legacy',
		),
		array(
			'id'      => 22,
			'context' => 'ci/docs',
			'state'   => 'success',
		),
	);
	$statuses       = array_map( array( \DataMachineCode\Abilities\GitHubAbilities::class, 'normalizeCommitStatus' ), $raw_statuses );
	$status_summary = \DataMachineCode\Abilities\GitHubAbilities::summarizeCommitStatuses( $statuses, 'failure' );

	$assert( 'ci/legacy' === $statuses[0]['context'], 'commit status keeps context' );
	$assert( 'failure' === $status_summary['state'], 'commit status summary maps combined failure' );
	$assert( 1 === $status_summary['counts']['failure'], 'commit status summary maps error to failure count' );
	$assert( 'ci/legacy' === $status_summary['failing'][0]['name'], 'commit status summary lists failing context' );

	$pull = array(
		'number'    => 101,
		'title'     => 'Add check context',
		'html_url'  => 'https://github.test/pull/101',
		'user'      => 'octocat',
		'head_ref'  => 'feat-checks',
		'head_sha'  => 'abc123head',
		'base_ref'  => 'main',
		'base_sha'  => 'def456base',
		'updated_at' => '2026-04-27T00:00:00Z',
	);
	$packet = \DataMachineCode\Abilities\GitHubAbilities::normalizePullReviewContext(
		'Extra-Chill/data-machine-code',
		$pull,
		array(),
		array(
			'checks' => array(
				'sha'               => 'abc123head',
				'check_runs'        => array(
					'summary' => $check_summary,
					'items'   => $check_runs,
					'count'   => count( $check_runs ),
				),
				'commit_statuses'   => array(
					'summary' => $status_summary,
					'items'   => $statuses,
					'count'   => count( $statuses ),
				),
			),
		)
	);
	$content = json_decode( $packet['content'], true );

	$assert( isset( $content['checks'] ), 'PR review context carries checks block when supplied' );
	$assert( 'failure' === $content['checks']['check_runs']['summary']['state'], 'PR review context carries check-run summary' );
	$assert( 'failure' === $content['checks']['commit_statuses']['summary']['state'], 'PR review context carries commit-status summary' );
	$assert( $content['checks'] === $packet['metadata']['review_context']['checks'], 'metadata mirrors checks context' );

	$ability_source  = file_get_contents( __DIR__ . '/../inc/Abilities/GitHubAbilities.php' );
	$handler_source  = file_get_contents( __DIR__ . '/../inc/Handlers/GitHub/GitHub.php' );
	$settings_source = file_get_contents( __DIR__ . '/../inc/Handlers/GitHub/GitHubSettings.php' );
	$tool_source     = file_get_contents( __DIR__ . '/../inc/Tools/GitHubTools.php' );

	$assert( str_contains( $ability_source, "'datamachine/get-github-check-runs'" ), 'check-runs ability is registered' );
	$assert( str_contains( $ability_source, "'datamachine/get-github-commit-statuses'" ), 'commit-statuses ability is registered' );
	$assert( str_contains( $handler_source, "'check_runs' === $" . 'data_source' ), 'fetch handler routes check_runs data source' );
	$assert( str_contains( $handler_source, "'commit_statuses' === $" . 'data_source' ), 'fetch handler routes commit_statuses data source' );
	$assert( str_contains( $settings_source, "'include_checks'" ), 'handler settings expose include_checks' );
	$assert( str_contains( $settings_source, "'include_statuses'" ), 'handler settings expose include_statuses' );
	$assert( str_contains( $tool_source, "'get_github_check_runs'" ), 'read-only check-runs tool is registered' );
	$assert( str_contains( $tool_source, "'get_github_commit_statuses'" ), 'read-only commit-statuses tool is registered' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
