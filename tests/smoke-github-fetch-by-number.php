<?php
/**
 * Smoke tests for GitHub fetch handler targeted fetch by issue_number / pull_number.
 *
 * Run with: php tests/smoke-github-fetch-by-number.php
 *
 * @package DataMachineCode\Tests
 */

declare(strict_types=1);

$root     = dirname( __DIR__ );
$handler  = file_get_contents( $root . '/inc/Handlers/GitHub/GitHub.php' );
$settings = file_get_contents( $root . '/inc/Handlers/GitHub/GitHubSettings.php' );

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	echo "FAIL: {$message}\n";
	$failures[] = $message;
};

// Handler config reads.
$assert( false !== strpos( $handler, "\$issue_number  = (int) ( \$config['issue_number'] ?? 0 )" ), 'handler reads issue_number from config' );
$assert( false !== strpos( $handler, "\$pull_number   = (int) ( \$config['pull_number'] ?? 0 )" ), 'handler reads pull_number from config' );

// Targeted-fetch dispatch.
$assert( false !== strpos( $handler, 'fetchSingleIssueOrPull' ), 'handler dispatches to fetchSingleIssueOrPull when a number is set' );
$assert( false !== strpos( $handler, 'if ( $issue_number > 0 || $pull_number > 0 )' ), 'handler branches on either number being set' );

// Abilities calls (use the actual method names that exist in GitHubAbilities).
$assert( false !== strpos( $handler, 'GitHubAbilities::getIssue' ), 'handler calls GitHubAbilities::getIssue for targeted issue fetch' );
$assert( false !== strpos( $handler, 'GitHubAbilities::getPull' ), 'handler calls GitHubAbilities::getPull for targeted pull fetch' );

// Mutual exclusion error path.
$assert( false !== strpos( $handler, 'mutually exclusive' ), 'handler enforces issue_number/pull_number mutual exclusion with an error log' );

// data_source alignment checks.
$assert( false !== strpos( $handler, "issue_number > 0 && 'issues' !== \$data_source" ), 'handler validates issue_number requires data_source=issues' );
$assert( false !== strpos( $handler, "pull_number > 0 && 'pulls' !== \$data_source" ), 'handler validates pull_number requires data_source=pulls' );

// List-filter ignore log.
$assert( false !== strpos( $handler, 'targeted fetch ignores list filters' ), 'handler logs ignored list filters in targeted mode' );

// State drop log.
$assert( false !== strpos( $handler, 'does not match configured state' ), 'handler drops items whose state does not match configured state' );

// DataPacket shape parity (same dedup_key format as list path).
$assert( false !== strpos( $handler, "sprintf( 'github_%s_%s_%d', \$repo, \$data_source, \$item['number'] )" ), 'targeted packet uses the same dedup_key shape as the list path' );
$assert( false !== strpos( $handler, "'source_type'       => 'github'" ), 'targeted packet metadata uses source_type=github' );
$assert( false !== strpos( $handler, 'buildIssueContent' ) && false !== strpos( $handler, 'buildPrContent' ), 'targeted path reuses buildIssueContent / buildPrContent for parity' );

// Settings schema fields.
$assert( false !== strpos( $settings, "'issue_number'" ), 'settings expose issue_number field' );
$assert( false !== strpos( $settings, "'pull_number'" ), 'settings expose pull_number field' );
$assert( false !== strpos( $settings, 'Mutually exclusive with Pull Request Number' ), 'issue_number help text mentions mutual exclusion' );
$assert( false !== strpos( $settings, 'Mutually exclusive with Issue Number' ), 'pull_number help text mentions mutual exclusion' );
$assert( false !== strpos( $settings, 'Takes precedence over list filters' ), 'settings help text documents precedence over list filters' );

echo "\n";
if ( empty( $failures ) ) {
	echo "OK: GitHub fetch-by-number smoke assertions passed.\n";
	exit( 0 );
}

echo sprintf( "FAILED: %d assertion(s) failed.\n", count( $failures ) );
exit( 1 );
