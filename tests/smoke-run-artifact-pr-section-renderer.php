<?php
/**
 * Pure-PHP smoke test for run artifact PR section rendering.
 *
 * Run: php tests/smoke-run-artifact-pr-section-renderer.php
 */

declare( strict_types=1 );

if (! defined('ABSPATH') ) {
    define('ABSPATH', __DIR__ . '/');
}

require __DIR__ . '/../inc/Support/SecretRedactor.php';
require __DIR__ . '/../inc/Support/RunArtifactPrSectionRenderer.php';

use DataMachineCode\Support\RunArtifactPrSectionRenderer;

$failures = array();
$assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
    if ($condition ) {
        echo "  ok {$label}\n";
        return;
    }

    $failures[] = $label;
    echo "  fail {$label}\n";
};

$artifacts = array(
    array(
        'type'    => 'agent_daily_memory',
        'content' => "2026-05-09\n- Created branch and implemented renderer.\n- token=ghp_abcdefghijklmnopqrstuvwxyz123456",
        'secret'  => 'never-render-me',
    ),
    array(
        'type' => 'completion_evidence',
        'data' => array(
            'completion_assertions' => array(
                array(
                    'tool'      => 'create_or_update_github_file',
                    'satisfied' => true,
                ),
                array(
                    'tool_name' => 'create_github_pull_request',
                    'status'    => 'satisfied',
                ),
                'agent_daily_memory' => 'satisfied',
            ),
            'api_key' => 'sk-test-secret-value',
        ),
    ),
    array(
        'name' => 'transcript_summary',
        'data' => array(
            'summary'     => 'Ran smoke tests and PHP syntax checks. runtime used Bearer caller-secret-token-1234567890.',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
        ),
    ),
);

echo "Run artifact PR section renderer - smoke\n";

$section = RunArtifactPrSectionRenderer::render($artifacts);

$assert('renders start marker', str_contains($section, RunArtifactPrSectionRenderer::START_MARKER));
$assert('renders end marker', str_contains($section, RunArtifactPrSectionRenderer::END_MARKER));
$assert('renders agent journal heading', str_contains($section, '### Agent Journal'));
$assert('renders journal content', str_contains($section, 'Created branch and implemented renderer.'));
$assert('renders completion evidence heading', str_contains($section, '### Completion Evidence'));
$assert('renders boolean completion assertion as satisfied', str_contains($section, '- `create_or_update_github_file`: satisfied'));
$assert('renders explicit completion status', str_contains($section, '- `create_github_pull_request`: satisfied'));
$assert('renders keyed completion assertion', str_contains($section, '- `agent_daily_memory`: satisfied'));
$assert('renders transcript summary when provided', str_contains($section, 'Ran smoke tests and PHP syntax checks.'));
$assert('omits secret-like keys', ! str_contains($section, 'never-render-me') && ! str_contains($section, 'BEGIN PRIVATE KEY'));
$assert('redacts secret-like strings', str_contains($section, 'token: [redacted]') && ! str_contains($section, 'ghp_abcdefghijklmnopqrstuvwxyz123456'));
$assert('does not leak api key values', ! str_contains($section, 'sk-test-secret-value'));
$assert('redacts bearer token values', ! str_contains($section, 'caller-secret-token-1234567890') && str_contains($section, 'Bearer [redacted]'));

$body    = "# Existing PR\n\nHuman-authored description.";
$updated = RunArtifactPrSectionRenderer::replaceSection($body, $artifacts);
$assert('appends section to body without existing markers', str_starts_with($updated, $body) && str_contains($updated, RunArtifactPrSectionRenderer::START_MARKER));

$rerendered = RunArtifactPrSectionRenderer::replaceSection(
    $updated,
    array(
        array(
            'type'    => 'agent_daily_memory',
            'content' => 'Replacement journal entry.',
        ),
        array(
            'type'     => 'completion_evidence',
            'evidence' => array(
                'agent_daily_memory' => 'satisfied',
            ),
        ),
    )
);

$assert('replacement preserves existing body prefix', str_starts_with($rerendered, $body));
$assert('replacement removes stale artifact content', ! str_contains($rerendered, 'Created branch and implemented renderer.'));
$assert('replacement adds fresh artifact content', str_contains($rerendered, 'Replacement journal entry.'));
$assert('replacement is idempotent with one section', 1 === substr_count($rerendered, RunArtifactPrSectionRenderer::START_MARKER) && 1 === substr_count($rerendered, RunArtifactPrSectionRenderer::END_MARKER));

$comment_body = RunArtifactPrSectionRenderer::replaceSection('', $artifacts);
$assert('empty comment mode body renders just the managed section', str_starts_with($comment_body, RunArtifactPrSectionRenderer::START_MARKER));

$mode_body = RunArtifactPrSectionRenderer::renderForMode(RunArtifactPrSectionRenderer::MODE_BODY_SECTION, $artifacts, $body);
$assert('body-section mode updates existing PR body', str_starts_with($mode_body, $body) && str_contains($mode_body, RunArtifactPrSectionRenderer::START_MARKER));

$mode_comment = RunArtifactPrSectionRenderer::renderForMode(RunArtifactPrSectionRenderer::MODE_COMMENT, $artifacts, $body);
$assert('comment mode returns only managed comment body', str_starts_with($mode_comment, RunArtifactPrSectionRenderer::START_MARKER) && ! str_starts_with($mode_comment, $body));

$empty_section = RunArtifactPrSectionRenderer::render(array());
$assert('empty artifacts render no managed section', '' === $empty_section);
$assert('empty artifacts omit artifact heading', ! str_contains($empty_section, '## Agent Run Artifacts'));
$assert('empty artifacts omit journal placeholder', ! str_contains($empty_section, '_No agent journal artifacts provided._'));
$assert('empty artifacts omit completion placeholder', ! str_contains($empty_section, '_No completion evidence artifacts provided._'));

$removed_section = RunArtifactPrSectionRenderer::replaceSection($updated, array());
$assert('empty replacement removes stale managed section', str_starts_with($removed_section, $body) && ! str_contains($removed_section, RunArtifactPrSectionRenderer::START_MARKER));

$empty_mode_body = RunArtifactPrSectionRenderer::renderForMode(RunArtifactPrSectionRenderer::MODE_BODY_SECTION, array(), $updated);
$assert('body-section mode removes stale section for empty artifacts', str_starts_with($empty_mode_body, $body) && ! str_contains($empty_mode_body, RunArtifactPrSectionRenderer::START_MARKER));

$empty_mode_comment = RunArtifactPrSectionRenderer::renderForMode(RunArtifactPrSectionRenderer::MODE_COMMENT, array(), $body);
$assert('comment mode renders nothing for empty artifacts', '' === $empty_mode_comment);

if (! empty($failures) ) {
    echo "\nFAIL: " . count($failures) . " assertion(s)\n";
    foreach ( $failures as $failure ) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nOK\n";
exit(0);
