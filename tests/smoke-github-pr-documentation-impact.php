<?php
/**
 * Pure-PHP smoke for GitHub PR documentation-impact packet heuristics.
 *
 * Run: php tests/smoke-github-pr-documentation-impact.php
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-pr-doc-impact/' );
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

	$failures = array();
	$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
		if ( $cond ) {
			echo "  ok {$label}\n";
			return;
		}
		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "GitHub PR documentation impact - smoke\n";

	$pull = array(
		'number'   => 110,
		'title'    => 'Produce PR documentation impact packets',
		'base_ref' => 'main',
		'head_ref' => 'feat-pr-doc-impact-packet',
		'head_sha' => 'abc123head',
	);

	$files = array(
		array(
			'filename'  => 'inc/Cli/Commands/GitHubCommand.php',
			'status'    => 'modified',
			'additions' => 12,
			'deletions' => 1,
			'patch'     => "+\t\t\WP_CLI::add_command( 'datamachine-code github docs-impact', array( self::class, 'docs_impact' ) );\n+\t\tif ( ! empty( \$assoc_args['base-ref'] ) ) {\n",
		),
		array(
			'filename'  => 'inc/Abilities/GitHubAbilities.php',
			'status'    => 'modified',
			'additions' => 80,
			'deletions' => 0,
			'patch'     => "+\t\t\twp_register_ability(\n+\t\t\t\t'datamachine/get-github-pr-documentation-impact',\n+\t\t\t\tarray( 'input_schema' => array(), 'output_schema' => array() )\n+\t\t\t);\n+\t\t\t\$key = PluginSettings::get( 'github_default_repo', '' );\n+\t\t\tpublic const PACKET_SCHEMA = 'data-machine-code/pr-documentation-impact/v1';\n",
		),
		array(
			'filename'  => 'inc/Support/GitHubWebhookValidator.php',
			'status'    => 'modified',
			'additions' => 10,
			'deletions' => 2,
			'patch'     => "+\t\t'mode' => 'github_pull_request',\n+\t\t'payload' => 'webhook contract',\n",
		),
		array(
			'filename'  => 'README.md',
			'status'    => 'modified',
			'additions' => 3,
			'deletions' => 1,
			'patch'     => '+Document the new packet.',
		),
	);

	$packet = \DataMachineCode\Abilities\GitHubAbilities::buildDocumentationImpactPacket(
		'Extra-Chill/data-machine-code',
		$pull,
		$files,
		array(
			'head_sha'  => 'abc123head',
			'repo_docs' => array(
				'README.md',
				'docs/cli.md',
				'docs/abilities.md',
				'docs/webhooks.md',
				'docs/settings.md',
				'docs/development.md',
			),
		)
	);

	$assert( 'packet builds', ! is_wp_error( $packet ) );
	$assert( 'schema is v1', 'data-machine-code/pr-documentation-impact/v1' === ( $packet['schema'] ?? '' ) );
	$assert( 'repo is carried', 'Extra-Chill/data-machine-code' === ( $packet['repo'] ?? '' ) );
	$assert( 'pull number is carried', 110 === ( $packet['pull_number'] ?? 0 ) );
	$assert( 'head SHA is carried', 'abc123head' === ( $packet['head_sha'] ?? '' ) );
	$assert( 'changed docs are detected', array( 'README.md' ) === array_column( $packet['changed_docs'], 'path' ) );

	$categories = array_column( $packet['impacts'], 'category' );
	$assert( 'WP-CLI impact detected', in_array( 'wp_cli', $categories, true ) );
	$assert( 'ability/tool impact detected', in_array( 'abilities_tools', $categories, true ) );
	$assert( 'REST/webhook impact detected', in_array( 'rest_webhook', $categories, true ) );
	$assert( 'settings/config impact detected', in_array( 'settings_config', $categories, true ) );
	$assert( 'public PHP impact detected', in_array( 'public_php', $categories, true ) );
	$assert( 'each impact carries evidence', count( $packet['impacts'] ) === count( array_filter( $packet['impacts'], fn( $impact ) => ! empty( $impact['evidence'] ) ) ) );
	$assert( 'evidence list is populated', count( $packet['evidence'] ) >= 5 );

	$stale_docs = array_column( $packet['likely_stale_docs'], 'path' );
	$assert( 'changed README not suggested as stale', ! in_array( 'README.md', $stale_docs, true ) );
	$assert( 'CLI docs suggested as stale', in_array( 'docs/cli.md', $stale_docs, true ) );
	$assert( 'ability docs suggested as stale', in_array( 'docs/abilities.md', $stale_docs, true ) );
	$assert( 'webhook docs suggested as stale', in_array( 'docs/webhooks.md', $stale_docs, true ) );
	$assert( 'settings docs suggested as stale', in_array( 'docs/settings.md', $stale_docs, true ) );
	$assert( 'development docs suggested as stale', in_array( 'docs/development.md', $stale_docs, true ) );
	$assert( 'search queries are suggested', count( $packet['suggested_search_queries'] ?? array() ) >= 3 );

	$mismatch = \DataMachineCode\Abilities\GitHubAbilities::buildDocumentationImpactPacket(
		'Extra-Chill/data-machine-code',
		$pull,
		array(),
		array( 'head_sha' => 'stalehead' )
	);
	$assert( 'head SHA mismatch errors', is_wp_error( $mismatch ) && 'github_pr_head_sha_mismatch' === $mismatch->get_error_code() );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK\n";
	exit( 0 );
}
