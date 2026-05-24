<?php
/**
 * Pure-PHP smoke test for run artifact bundle-file write preparation.
 *
 * Run: php tests/smoke-run-artifact-bundle-file-writer.php
 */

declare( strict_types=1 );

if (! defined('ABSPATH') ) {
    define('ABSPATH', __DIR__ . '/');
}

if (! function_exists('sanitize_key') ) {
    function sanitize_key( $key ): string
    {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}

if (! function_exists('sanitize_title') ) {
    function sanitize_title( $title ): string
    {
        $title = strtolower(trim((string) $title));
        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
        return trim($title, '-');
    }
}

require __DIR__ . '/../inc/Support/RunArtifactBundleFileWriter.php';

use DataMachineCode\Support\RunArtifactBundleFileWriter;

$failures = array();
$assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
    if ($condition ) {
        echo "  ok {$label}\n";
        return;
    }

    $failures[] = $label;
    echo "  fail {$label}\n";
};

echo "Run artifact bundle-file writer - smoke\n";

$payload = array(
    'job_id'                 => 844,
    'daily_memory_artifacts' => array(
        array(
            'type'                 => 'agent_daily_memory',
            'agent_slug'           => 'world-creator',
            'date'                 => '2026-05-09',
            'bundle_relative_path' => 'memory/agent/daily/2026/05/09.md',
            'content'              => "# 2026-05-09\n\n- Cooked memory artifacts.\n",
        ),
    ),
);

$disabled = RunArtifactBundleFileWriter::fileWritesFromArtifacts(
    $payload,
    array(
        'daily_memory' => array( 'egress' => array( 'pr-body' ) ),
    )
);
$assert('does not write without bundle-file egress', array() === $disabled);

$writes = RunArtifactBundleFileWriter::fileWritesFromArtifacts(
    $payload,
    array(
        'daily_memory' => array( 'egress' => array( 'bundle-file', 'pr-body' ) ),
    )
);

$assert('creates one file write', 1 === count($writes));
$assert('uses default bundles/{agent_slug} root', 'bundles/world-creator/memory/agent/daily/2026/05/09.md' === ( $writes[0]['file_path'] ?? '' ));
$assert('preserves artifact content', str_contains((string) ( $writes[0]['content'] ?? '' ), 'Cooked memory artifacts.'));
$assert('uses memory-specific commit message', 'chore: persist agent daily memory' === ( $writes[0]['commit_message'] ?? '' ));

$custom_root = RunArtifactBundleFileWriter::fileWritesFromArtifacts(
    $payload,
    array(
        'daily_memory' => array( 'egress' => array( 'bundle-file' ) ),
    ),
    'agent-bundles/{agent_slug}'
);
$assert('supports configured placeholder bundle root', 'agent-bundles/world-creator/memory/agent/daily/2026/05/09.md' === ( $custom_root[0]['file_path'] ?? '' ));

$repo_path_payload = array(
    'artifacts' => array(
        array(
            'type'      => 'daily_memory',
            'repo_path' => 'bundles/custom/memory/agent/daily/2026/05/09.md',
            'content'   => 'repo path wins',
        ),
    ),
);
$repo_path_writes = RunArtifactBundleFileWriter::fileWritesFromArtifacts(
    $repo_path_payload,
    array(
        'daily_memory' => array( 'egress' => array( 'bundle-file' ) ),
    )
);
$assert('accepts explicit repo path artifacts', 'bundles/custom/memory/agent/daily/2026/05/09.md' === ( $repo_path_writes[0]['file_path'] ?? '' ));

$unsafe_payload = array(
    'daily_memory_artifacts' => array(
        array(
            'type'                 => 'agent_daily_memory',
            'agent_slug'           => 'world-creator',
            'bundle_relative_path' => '../outside.md',
            'content'              => 'nope',
        ),
    ),
);
$unsafe_writes = RunArtifactBundleFileWriter::fileWritesFromArtifacts(
    $unsafe_payload,
    array(
        'daily_memory' => array( 'egress' => array( 'bundle-file' ) ),
    )
);
$assert('rejects unsafe relative paths', array() === $unsafe_writes);

if (! empty($failures) ) {
    echo "\nFAIL: " . count($failures) . " assertion(s)\n";
    foreach ( $failures as $failure ) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nOK\n";
exit(0);
