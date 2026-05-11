<?php
/**
 * Smoke assertions for GitHub Actions artifact item fetch support.
 *
 * Run with: php tests/smoke-github-actions-artifact-fetch-handler.php
 */

$root     = dirname( __DIR__ );
$handler  = file_get_contents( $root . '/inc/Handlers/GitHub/GitHub.php' );
$settings = file_get_contents( $root . '/inc/Handlers/GitHub/GitHubSettings.php' );

$assert = static function ( string $message, bool $condition ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$assert(
	'github fetch handler routes actions_artifact_items data source',
	false !== strpos( $handler, "'actions_artifact_items' === \$data_source" )
);

$assert(
	'artifact fetch delegates to generic GitHub Actions artifact ability',
	false !== strpos( $handler, 'GitHubAbilities::getActionsArtifact' )
);

$assert(
	'artifact item packets include dedupe item identifiers',
	false !== strpos( $handler, "'item_identifier'" ) && false !== strpos( $handler, 'github_actions_artifact:' )
);

$assert(
	'artifact item packets preserve the original item payload in metadata',
	false !== strpos( $handler, "'artifact_item'" )
);

$assert(
	'settings expose actions_artifact_items as a GitHub data source',
	false !== strpos( $settings, "'actions_artifact_items'" )
);

$assert(
	'settings expose json_file and items_path controls',
	false !== strpos( $settings, "'json_file'" ) && false !== strpos( $settings, "'items_path'" )
);

echo "OK: GitHub Actions artifact fetch handler smoke assertions passed.\n";
