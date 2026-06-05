<?php
/**
 * Smoke tests for GitHub pull request publish handler registration and schema.
 *
 * Run with: php tests/smoke-github-pr-publish-handler.php
 *
 * @package DataMachineCode\Tests
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$handler  = file_get_contents($root . '/inc/Handlers/GitHub/GitHubPullRequestPublish.php');
$settings = file_get_contents($root . '/inc/Handlers/GitHub/GitHubPullRequestPublishSettings.php');
$plugin   = file_get_contents($root . '/data-machine-code.php');

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ($condition ) {
        echo "PASS: {$message}\n";
        return;
    }

    echo "FAIL: {$message}\n";
    $failures[] = $message;
};

$assert(false !== strpos($handler, "registerHandler(\n\t\t\t'github_pull_request',\n\t\t\t'publish'"), 'github_pull_request registers as a publish handler');
$assert(false !== strpos($handler, "'handler'                 => 'github_pull_request'"), 'AI tool is marked as the github_pull_request handler tool');
$assert(false !== strpos($handler, "'github_pull_request_publish'"), 'handler exposes github_pull_request_publish tool');
$assert(false !== strpos($handler, "'client_context_bindings' => array( 'job_id' )"), 'PR publish tool binds job_id from runtime context');
$assert(false !== strpos($handler, "'required'   => array( 'title', 'head' )"), 'PR publish requires title and head');
$assert(false !== strpos($handler, 'GitHubAbilities::createPullRequest'), 'handler delegates PR creation to GitHubAbilities');
$assert(false !== strpos($handler, "'files'") && false !== strpos($handler, "'items'"), 'PR publish accepts a files array');
$assert(false !== strpos($handler, 'GitHubAbilities::createOrUpdateFile'), 'handler commits files before opening the PR');
$assert(false !== strpos($handler, '\'branch\'         => $input[\'head\']'), 'handler commits files to the PR head branch');
$assert(false !== strpos($handler, '\'files\'          => $committed_files'), 'handler returns committed file metadata');
$assert(false !== strpos($handler, 'RunArtifactBundleFileWriter'), 'handler prepares Data Machine run artifacts for bundle-file writes');
$assert(false !== strpos($handler, 'run_artifact_egress_policy'), 'handler honors run artifact egress policy');
$assert(false !== strpos($settings, "'bundle_root'"), 'settings expose bundle_root for repo bundle artifact placement');
$assert(false !== strpos($handler, "'labels'                => array("), 'tool schema exposes labels parameter');
$assert(false !== strpos($handler, 'GitHubAbilities::addLabels'), 'handler applies labels via GitHubAbilities::addLabels');
$assert(false !== strpos($handler, "'applied_labels' => \$applied_labels"), 'handler returns applied_labels in result');
$assert(false !== strpos($handler, 'resolveListParameter'), 'handler resolves labels via shared helper');
$assert(false !== strpos($handler, "'label_error'"), 'handler reports label_error on partial failure');
$assert(false !== strpos($settings, "'base'") && false !== strpos($settings, "'draft'"), 'settings expose base and draft defaults');
$assert(false !== strpos($settings, "'labels'"), 'settings expose labels default');
$assert(false !== strpos($plugin, 'new \\DataMachineCode\\Handlers\\GitHub\\GitHubPullRequestPublish();'), 'plugin bootstraps GitHub PR publish handler');

echo "\n";
if (empty($failures) ) {
    echo "OK: GitHub PR publish handler smoke assertions passed.\n";
    exit(0);
}

echo sprintf("FAILED: %d assertion(s) failed.\n", count($failures));
exit(1);
