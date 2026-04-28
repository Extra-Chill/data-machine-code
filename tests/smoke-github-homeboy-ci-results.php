<?php
/**
 * Pure-PHP smoke for GitHub Homeboy CI artifact context.
 *
 * No GitHub network calls: fake API responses plus an in-memory artifact ZIP pin
 * selection, parsing, summary, fetch-handler, tool, and scaffold integration.
 *
 * Run: php tests/smoke-github-homeboy-ci-results.php
 */

declare( strict_types=1 );

namespace DataMachine\Core {
	class PluginSettings {
		public static function get( string $key, mixed $default = '' ): mixed {
			return 'github_pat' === $key ? 'test-token' : $default;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-github-homeboy-ci-results/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): array { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof \WP_Error; }
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $options = 0 ) { return json_encode( $data, $options ); }
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string { return trim( (string) $value ); }
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

	echo "GitHub Homeboy CI results - smoke\n\n";

	$review_payload = array(
		'success' => false,
		'data'    => array(
			'command' => 'review',
			'summary' => array(
				'passed'             => false,
				'status'             => 'failed',
				'component'          => 'data-machine-code',
				'scope'              => 'changed-since',
				'changed_since'      => 'origin/main',
				'total_findings'     => 3,
				'changed_file_count' => 5,
			),
			'audit'   => array(
				'ran'           => true,
				'passed'        => false,
				'exit_code'     => 1,
				'finding_count' => 2,
				'hint'          => 'Deep dive: homeboy audit data-machine-code --changed-since=origin/main',
			),
			'lint'    => array(
				'ran'           => true,
				'passed'        => false,
				'exit_code'     => 1,
				'finding_count' => 1,
			),
			'test'    => array(
				'ran'           => true,
				'passed'        => true,
				'exit_code'     => 0,
				'finding_count' => 0,
			),
		),
	);

	$manifest_payload = array(
		'schema'        => 'homeboy.ci-results.v1',
		'producer'      => 'homeboy-action',
		'repo'          => 'Extra-Chill/data-machine-code',
		'head_sha'      => 'abc123head',
		'run_id'        => '1001',
		'run_attempt'   => '2',
		'artifact_name' => 'homeboy-ci-results',
		'check_url'     => 'https://github.test/actions/runs/1001',
	);

	$make_zip = function ( array $files ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return 'zip-fixture-unavailable';
		}

		$tmp = tempnam( sys_get_temp_dir(), 'dmc-homeboy-smoke-' );
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::OVERWRITE );
		foreach ( $files as $name => $payload ) {
			$zip->addFromString( $name, json_encode( $payload ) );
		}
		$zip->close();
		$bytes = file_get_contents( $tmp );
		@unlink( $tmp );
		return false === $bytes ? '' : $bytes;
	};

	$zip_bytes = $make_zip( array(
		'homeboy-ci-results/review.json'   => $review_payload,
		'homeboy-ci-results/manifest.json' => $manifest_payload,
	) );

	if ( class_exists( '\ZipArchive' ) ) {
		$extracted = \DataMachineCode\Abilities\GitHubAbilities::extractZipJsonFiles( $zip_bytes );
		$assert( ! is_wp_error( $extracted ), 'artifact ZIP extracts successfully' );
		$assert( isset( $extracted['review.json'], $extracted['manifest.json'] ), 'ZIP extraction keeps basename JSON files' );
	} else {
		$assert( true, 'ZipArchive unavailable; direct ZIP extraction skipped' );
	}

	$artifact = array(
		'id'                   => 44,
		'name'                 => 'homeboy-ci-results',
		'archive_download_url' => 'https://api.github.test/artifacts/44/zip',
		'expired'              => false,
		'created_at'           => '2026-04-27T00:00:00Z',
		'updated_at'           => '2026-04-27T00:10:00Z',
		'workflow_run'         => array(
			'id'       => 1001,
			'head_sha' => 'abc123head',
		),
	);

	$api_calls = array();
	$api_get   = function ( string $url, array $query ) use ( &$api_calls, $artifact ): array|\WP_Error {
		$api_calls[] = array( 'url' => $url, 'query' => $query );
		if ( str_contains( $url, '/pulls/106' ) ) {
			return array( 'success' => true, 'data' => array( 'head' => array( 'sha' => 'abc123head' ) ) );
		}

		if ( str_contains( $url, '/actions/artifacts' ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'artifacts' => array(
						array_merge( $artifact, array( 'id' => 43, 'workflow_run' => array( 'id' => 1000, 'head_sha' => 'oldsha' ) ) ),
						$artifact,
					),
				),
			);
		}

		return new \WP_Error( 'unexpected_api_call', $url );
	};

	$download = function ( string $url, int $max_bytes ) use ( $zip_bytes ): string|\WP_Error {
		return str_contains( $url, '/artifacts/44/zip' ) && $max_bytes > strlen( $zip_bytes ) ? $zip_bytes : new \WP_Error( 'bad_download', 'Unexpected download.' );
	};

	$result = \DataMachineCode\Abilities\GitHubAbilities::getHomeboyCiResults(
		array(
			'repo'               => 'Extra-Chill/data-machine-code',
			'pull_number'        => 106,
			'artifact_name'      => 'homeboy-ci-results',
			'max_artifact_bytes' => 2000000,
		),
		$api_get,
		$download
	);

	$assert( ! is_wp_error( $result ), 'ability resolves PR head SHA, downloads artifact, and parses ZIP' );
	$results = $result['results'] ?? array();
	$assert( 'failure' === ( $results['state'] ?? '' ), 'review.json failure maps to aggregate failure state' );
	$assert( 'review' === ( $results['mode'] ?? '' ), 'review.json is preferred mode' );
	$assert( 3 === (int) ( $results['summary']['total_findings'] ?? -1 ), 'summary carries total findings' );
	$assert( 2 === (int) ( $results['stages']['audit']['finding_count'] ?? -1 ), 'audit stage finding count is normalized' );
	$assert( '1001' === (string) ( $results['workflow']['run_id'] ?? '' ), 'workflow run id is carried from manifest' );
	$assert( 'https://github.test/actions/runs/1001' === ( $results['workflow']['url'] ?? '' ), 'workflow URL is carried from manifest' );
	$assert( 'homeboy-ci-results' === ( $api_calls[1]['query']['name'] ?? '' ), 'artifact lookup filters by artifact name' );

	$legacy = \DataMachineCode\Abilities\GitHubAbilities::summarizeHomeboyCiArtifact(
		array(
			'audit.json' => array( 'success' => true, 'data' => array( 'finding_count' => 0 ) ),
			'lint.json'  => array( 'success' => false, 'data' => array( 'finding_count' => 4 ) ),
		),
		array( 'repo' => 'Extra-Chill/data-machine-code', 'head_sha' => 'abc123head' )
	);
	$assert( ! is_wp_error( $legacy ), 'legacy audit/lint/test fallback summarizes without review.json' );
	$assert( 'legacy' === ( $legacy['mode'] ?? '' ), 'legacy fallback mode is explicit' );
	$assert( false === ( $legacy['summary']['passed'] ?? true ), 'legacy fallback fails if any stage fails' );
	$assert( 4 === (int) ( $legacy['summary']['total_findings'] ?? -1 ), 'legacy fallback totals findings' );

	$expired = \DataMachineCode\Abilities\GitHubAbilities::selectHomeboyArtifact(
		array( array_merge( $artifact, array( 'expired' => true ) ) ),
		'homeboy-ci-results',
		'abc123head'
	);
	$assert( is_wp_error( $expired ) && 'github_homeboy_ci_artifact_expired' === $expired->get_error_code(), 'expired artifact gets explicit error code' );

	$pending_api = function ( string $url, array $query ) use ( $artifact ): array|\WP_Error {
		if ( str_contains( $url, '/actions/artifacts' ) ) {
			return array( 'success' => true, 'data' => array( 'artifacts' => array() ) );
		}
		if ( str_contains( $url, '/check-runs' ) ) {
			return array( 'success' => true, 'data' => array( 'check_runs' => array( array( 'name' => 'Homeboy Review', 'status' => 'in_progress' ) ) ) );
		}
		return array( 'success' => true, 'data' => array( 'head' => array( 'sha' => 'abc123head' ) ) );
	};
	$pending = \DataMachineCode\Abilities\GitHubAbilities::getHomeboyCiResults( array( 'repo' => 'Extra-Chill/data-machine-code', 'head_sha' => 'abc123head' ), $pending_api, $download );
	$assert( is_wp_error( $pending ) && 'github_homeboy_ci_pending' === $pending->get_error_code(), 'pending Homeboy check is distinguished from missing artifact' );

	$ability_source  = file_get_contents( __DIR__ . '/../inc/Abilities/GitHubAbilities.php' );
	$handler_source  = file_get_contents( __DIR__ . '/../inc/Handlers/GitHub/GitHub.php' );
	$settings_source = file_get_contents( __DIR__ . '/../inc/Handlers/GitHub/GitHubSettings.php' );
	$tool_source     = file_get_contents( __DIR__ . '/../inc/Tools/GitHubTools.php' );
	$scaffold_source = file_get_contents( __DIR__ . '/../inc/GitHub/PrReviewFlowScaffold.php' );

	$assert( str_contains( $ability_source, "'datamachine/get-github-homeboy-ci-results'" ), 'Homeboy CI ability is registered' );
	$assert( str_contains( $handler_source, "'homeboy_ci_results' === $" . 'data_source' ), 'GitHub fetch handler routes homeboy_ci_results data source' );
	$assert( str_contains( $settings_source, "'homeboy_ci_results'" ), 'handler settings expose homeboy_ci_results data source' );
	$assert( str_contains( $tool_source, "'get_github_homeboy_ci_results'" ), 'read-only Homeboy CI tool is registered' );
	$assert( str_contains( $scaffold_source, "'get_github_homeboy_ci_results'" ), 'PR review scaffold enables Homeboy CI tool' );
	$assert( str_contains( $scaffold_source, "'include_homeboy_ci'" ), 'PR review scaffold opts into Homeboy CI context' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
