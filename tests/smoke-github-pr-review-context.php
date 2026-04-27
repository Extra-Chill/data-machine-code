<?php
/**
 * Pure-PHP smoke for GitHub PR review context normalization.
 *
 * No GitHub network calls: this pins the DataPacket shape produced from
 * normalized pull + files payloads.
 *
 * Run: php tests/smoke-github-pr-review-context.php
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-pr-review-context/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( string $code = '', string $message = '', array $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

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

	$assert = function ( $cond, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $cond ) {
			echo "  ✓ {$message}\n";
			return;
		}
		$failures++;
		echo "  ✗ {$message}\n";
	};

	echo "GitHub PR review context — smoke\n\n";

	$raw_pull = array(
		'number'     => 85,
		'title'      => 'Add PR review context primitive',
		'state'      => 'open',
		'body'       => 'Review context body.',
		'html_url'   => 'https://github.com/Extra-Chill/data-machine-code/pull/85',
		'user'       => array( 'login' => 'octocat' ),
		'head'       => array(
			'ref' => 'feat-pr-review-context',
			'sha' => 'abc123head',
		),
		'base'       => array(
			'ref' => 'main',
			'sha' => 'def456base',
		),
		'labels'     => array( array( 'name' => 'enhancement' ) ),
		'created_at' => '2026-04-27T00:00:00Z',
		'updated_at' => '2026-04-27T01:00:00Z',
	);

	$raw_files = array(
		array(
			'filename'  => 'inc/Handlers/GitHub/GitHub.php',
			'status'    => 'modified',
			'additions' => 50,
			'deletions' => 2,
			'changes'   => 52,
			'patch'     => "@@ -1 +1 @@\n-old\n+new\n",
		),
		array(
			'filename'  => 'tests/huge-fixture.php',
			'status'    => 'modified',
			'additions' => 5000,
			'deletions' => 0,
			'changes'   => 5000,
			'patch'     => str_repeat( '+line\n', 20 ),
		),
		array(
			'filename'  => 'assets/logo.png',
			'status'    => 'added',
			'additions' => 0,
			'deletions' => 0,
			'changes'   => 0,
		),
	);

	$pull  = \DataMachineCode\Abilities\GitHubAbilities::normalizePull( $raw_pull );
	$files = array_map( array( \DataMachineCode\Abilities\GitHubAbilities::class, 'normalizePullFile' ), $raw_files );

	$packet = \DataMachineCode\Abilities\GitHubAbilities::normalizePullReviewContext(
		'Extra-Chill/data-machine-code',
		$pull,
		$files,
		array(
			'head_sha'        => 'abc123head',
			'max_patch_chars' => 40,
		)
	);

	$assert( ! is_wp_error( $packet ), 'normalization succeeds with matching head SHA' );
	$assert( 'github_pr_review' === $packet['metadata']['source_type'], 'metadata source_type is github_pr_review' );
	$assert( 'Extra-Chill/data-machine-code#85@abc123head' === $packet['metadata']['item_identifier'], 'item identifier includes repo, PR number, head SHA' );
	$assert( $packet['metadata']['item_identifier'] === $packet['metadata']['dedup_key'], 'dedup_key mirrors item identifier' );
	$assert( 'pull_review_context' === $packet['metadata']['github_type'], 'github_type names the review context source' );
	$assert( 'abc123head' === $packet['metadata']['github_head_sha'], 'metadata includes head SHA' );

	$content = json_decode( $packet['content'], true );
	$assert( is_array( $content ), 'content is JSON review context' );
	$assert( 'Extra-Chill/data-machine-code' === $content['repo'], 'content includes repo' );
	$assert( 85 === $content['pull_number'], 'content includes PR number' );
	$assert( 'https://github.com/Extra-Chill/data-machine-code/pull/85' === $content['url'], 'content includes PR URL' );
	$assert( 'Add PR review context primitive' === $content['title'], 'content includes title' );
	$assert( 'Review context body.' === $content['body'], 'content includes body' );
	$assert( 'octocat' === $content['author'], 'content includes author' );
	$assert( 'main' === $content['base_ref'], 'content includes base ref' );
	$assert( 'def456base' === $content['base_sha'], 'content includes base SHA' );
	$assert( 'feat-pr-review-context' === $content['head_ref'], 'content includes head ref' );
	$assert( 'abc123head' === $content['head_sha'], 'content includes head SHA' );
	$assert( 3 === count( $content['changed_files'] ), 'content includes every changed file' );
	$assert( 'inc/Handlers/GitHub/GitHub.php' === $content['changed_files'][0]['filename'], 'changed file includes filename' );
	$assert( 'modified' === $content['changed_files'][0]['status'], 'changed file includes status' );
	$assert( 50 === $content['changed_files'][0]['additions'], 'changed file includes additions' );
	$assert( 2 === $content['changed_files'][0]['deletions'], 'changed file includes deletions' );
	$assert( str_contains( $content['changed_files'][0]['patch'], '+new' ), 'small patch is included' );
	$assert( true === $content['changed_files'][0]['patch_included'], 'small patch marks patch_included true' );
	$assert( '' === $content['changed_files'][1]['patch'], 'large patch is omitted after cumulative limit' );
	$assert( false === $content['changed_files'][1]['patch_included'], 'large patch marks patch_included false' );
	$assert( false === $content['changed_files'][2]['patch_included'], 'missing binary patch marks patch_included false' );
	$assert( 40 === $content['truncation']['max_patch_chars'], 'truncation records configured limit' );
	$assert( 1 === $content['truncation']['truncated_files'], 'truncation counts omitted patches' );
	$assert( true === $content['truncation']['truncated'], 'truncation boolean is true when a patch is omitted' );
	$assert( $content === $packet['metadata']['review_context'], 'metadata carries same review context for downstream consumers' );

	$mismatch = \DataMachineCode\Abilities\GitHubAbilities::normalizePullReviewContext(
		'Extra-Chill/data-machine-code',
		$pull,
		$files,
		array( 'head_sha' => 'stalehead' )
	);
	$assert( is_wp_error( $mismatch ), 'head SHA mismatch returns WP_Error' );
	$assert( is_wp_error( $mismatch ) && 'github_pr_head_sha_mismatch' === $mismatch->get_error_code(), 'head SHA mismatch uses clear error code' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
