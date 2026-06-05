<?php
/**
 * Smoke tests for GitHub issue publish handler registration and schema.
 *
 * Run with: php tests/smoke-github-issue-publish-handler.php
 *
 * @package DataMachineCode\Tests
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$handler  = file_get_contents($root . '/inc/Handlers/GitHub/GitHubIssuePublish.php');
$settings = file_get_contents($root . '/inc/Handlers/GitHub/GitHubIssuePublishSettings.php');
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

$assert(false !== strpos($handler, "registerHandler(\n\t\t\t'github_issue',\n\t\t\t'publish'"), 'github_issue registers as a publish handler');
$assert(false !== strpos($handler, "'handler'                 => 'github_issue'"), 'AI tool is marked as the github_issue handler tool');
$assert(false !== strpos($handler, "'github_issue_publish'"), 'handler exposes github_issue_publish tool');
$assert(false !== strpos($handler, "'client_context_bindings' => array( 'job_id' )"), 'publish tool binds job_id from runtime context');
$assert(false !== strpos($handler, "'items'       => array( 'type' => 'string' )"), 'array parameters include item schemas');
$assert(false !== strpos($handler, 'GitHubAbilities::createIssue'), 'handler delegates issue creation to GitHubAbilities');
$assert(false !== strpos($settings, "'labels'") && false !== strpos($settings, "'assignees'"), 'settings expose labels and assignees defaults');
$assert(false !== strpos($plugin, 'new \\DataMachineCode\\Handlers\\GitHub\\GitHubIssuePublish();'), 'plugin bootstraps GitHub issue publish handler');
$assert(false !== strpos($plugin, "did_action('plugins_loaded')") && false !== strpos($plugin, 'datamachine_code_bootstrap();'), 'plugin bootstraps immediately when loaded after plugins_loaded');
$assert(false !== strpos($plugin, "'activated_plugin'") && false !== strpos($plugin, 'datamachine_code_bootstrap();'), 'plugin retries bootstrap after late activation completes');

echo "\n";
if (empty($failures) ) {
    echo "OK: GitHub issue publish handler smoke assertions passed.\n";
    exit(0);
}

echo sprintf("FAILED: %d assertion(s) failed.\n", count($failures));
exit(1);
