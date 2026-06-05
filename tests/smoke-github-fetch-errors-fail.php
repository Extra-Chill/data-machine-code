<?php
/**
 * Smoke tests for GitHub fetch handler API/auth error propagation.
 *
 * Run with: php tests/smoke-github-fetch-errors-fail.php
 *
 * @package DataMachineCode\Tests
 */

declare(strict_types=1);

$root    = dirname(__DIR__);
$handler = file_get_contents($root . '/inc/Handlers/GitHub/GitHub.php') ?: '';

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ($condition ) {
        echo "PASS: {$message}\n";
        return;
    }

    echo "FAIL: {$message}\n";
    $failures[] = $message;
};

$assert(false !== strpos($handler, "throw new \\RuntimeException('GitHub: Authentication is not configured.')"), 'missing GitHub auth throws');
$assert(false !== strpos($handler, "throw new \\RuntimeException('GitHub: API error — ' . esc_html(\$result->get_error_message()))"), 'issues and pulls API errors throw');
$assert(false !== strpos($handler, "throw new \\RuntimeException('GitHub: Tree API error — ' . esc_html(\$result->get_error_message()))"), 'file tree API errors throw');
$assert(false !== strpos($handler, 'GitHub: No items found.') && false !== strpos($handler, 'return array();'), 'true empty issue/pull lists remain no-item results');

echo "\n";
if (empty($failures) ) {
    echo "OK: GitHub fetch error propagation smoke assertions passed.\n";
    exit(0);
}

echo sprintf("FAILED: %d assertion(s) failed.\n", count($failures));
exit(1);
