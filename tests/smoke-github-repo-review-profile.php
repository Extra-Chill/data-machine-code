<?php
/**
 * Pure-PHP smoke for bounded GitHub repository review profiles.
 *
 * Run: php tests/smoke-github-repo-review-profile.php
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-repo-review-profile/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '' ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
	}

	require __DIR__ . '/../inc/Abilities/GitHubAbilities.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$message}\n";
			return;
		}

		++$failures;
		echo "  fail {$message}\n";
	};

	echo "GitHub repository review profile - smoke\n";

	$fixtures = array(
		'AGENTS.md'                   => "# Agents\n- Never edit CHANGELOG.md\n- No version bumps\n",
		'README.md'                   => "# Data Machine Code\nBridge between WordPress and coding agents.\n",
		'homeboy.json'                => '{"id":"data-machine-code","audit":{"changed_since":"origin/main"},"test":{"commands":["php tests/smoke.php"]}}',
		'docs/review-context.md'      => "# Review Context\nFocus on repository conventions.\n",
		'.dmc/review-profile.json'    => '{"review_rules":["Prefer bounded GitHub reads."],"public_surfaces":["inc/Abilities"],"docs_surfaces":["docs/review-context.md"],"commands":{"review":"homeboy review"}}',
		'docs/architecture.md'        => "# Architecture\nDMC owns the bridge.\n",
		'docs/development-guide.md'   => str_repeat( 'Development rules. ', 10 ),
		'docs/nested/development.md'  => "# Nested Development\nUse smoke tests.\n",
	);

	$fetcher = function ( string $path ) use ( $fixtures ): array|WP_Error {
		if ( ! isset( $fixtures[ $path ] ) ) {
			return new WP_Error( 'github_not_found', 'Not found.' );
		}

		return array(
			'file' => array(
				'path'     => $path,
				'sha'      => substr( sha1( $path ), 0, 12 ),
				'size'     => strlen( $fixtures[ $path ] ),
				'html_url' => 'https://github.test/' . $path,
				'content'  => $fixtures[ $path ],
			),
		);
	};

	$tree_fetcher = function ( string $path ): array {
		return array(
			'success' => true,
			'files'   => array(
				array( 'path' => 'docs/random.md' ),
				array( 'path' => 'docs/development-guide.md' ),
				array( 'path' => 'docs/architecture.md' ),
				array( 'path' => 'docs/nested/development.md' ),
				array( 'path' => 'src/development.md' ),
			),
			'count'   => 5,
		);
	};

	$profile = \DataMachineCode\Abilities\GitHubAbilities::buildRepoReviewProfile(
		'Extra-Chill/data-machine-code',
		array(
			'max_profile_files'     => 8,
			'max_file_chars'        => 40,
			'max_total_chars'       => 400,
			'max_architecture_docs' => 3,
		),
		$fetcher,
		$tree_fetcher
	);

	$paths = array_column( $profile['profile_files'], 'path' );

	$assert( 'data-machine-code/repo-review-profile/v1' === $profile['schema'], 'schema is stable' );
	$assert( 'Extra-Chill/data-machine-code' === $profile['repo'], 'repo is carried through' );
	$assert( in_array( 'AGENTS.md', $paths, true ), 'AGENTS.md is included when present' );
	$assert( in_array( 'README.md', $paths, true ), 'README.md is included when present' );
	$assert( in_array( 'homeboy.json', $paths, true ), 'homeboy.json is included when present' );
	$assert( in_array( '.dmc/review-profile.json', $paths, true ), 'explicit review profile is included when present' );
	$assert( in_array( 'docs/architecture.md', $paths, true ), 'architecture docs are selected from docs tree' );
	$assert( in_array( 'docs/development-guide.md', $paths, true ), 'development docs are selected from docs tree' );
	$assert( in_array( 'docs/nested/development.md', $paths, true ), 'nested development docs are selected from docs tree' );
	$assert( ! in_array( 'docs/random.md', $paths, true ), 'unmatched docs are not included' );
	$assert( ! in_array( 'src/development.md', $paths, true ), 'docs selection is scoped under docs/' );
	$assert( isset( $profile['commands']['audit'] ) && isset( $profile['commands']['test'] ), 'homeboy commands are extracted' );
	$assert( 'homeboy review' === $profile['commands']['review'], 'review-profile commands merge in' );
	$assert( in_array( 'Prefer bounded GitHub reads.', $profile['review_rules'], true ), 'review rules merge in' );
	$assert( in_array( 'inc/Abilities', $profile['public_surfaces'], true ), 'public surfaces merge in' );
	$assert( in_array( 'docs/review-context.md', $profile['docs_surfaces'], true ), 'docs surfaces include explicit review-context doc' );
	$assert( $profile['truncation']['included_files'] === count( $profile['profile_files'] ), 'included file count is tracked' );
	$assert( $profile['truncation']['truncated_files'] > 0, 'per-file truncation is tracked' );
	$assert( true === $profile['truncation']['truncated'], 'truncation boolean is true when content is trimmed' );
	$assert( empty( $profile['truncation']['errors'] ), 'missing optional files do not create errors' );

	$without_docs_tree = \DataMachineCode\Abilities\GitHubAbilities::buildRepoReviewProfile(
		'Extra-Chill/no-docs',
		array( 'max_architecture_docs' => 8 ),
		$fetcher,
		fn(): WP_Error => new WP_Error( 'github_not_found', 'Tree not found.' )
	);
	$assert( empty( $without_docs_tree['truncation']['errors'] ), 'missing docs tree is optional and does not create errors' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
