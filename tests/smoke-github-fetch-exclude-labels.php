<?php
/**
 * Smoke tests for GitHub fetch handler `exclude_labels` post-fetch filter.
 *
 * Static-text assertions on the handler source plus a behavioral check of the
 * post-fetch filter loop run inline against an in-process stub of the
 * fetchIssuesOrPulls() filter tier.
 *
 * Run with: php tests/smoke-github-fetch-exclude-labels.php
 *
 * @package DataMachineCode\Tests
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$handler  = file_get_contents($root . '/inc/Handlers/GitHub/GitHub.php');
$settings = file_get_contents($root . '/inc/Handlers/GitHub/GitHubSettings.php');
$readme   = file_get_contents($root . '/README.md');

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ($condition ) {
        echo "PASS: {$message}\n";
        return;
    }

    echo "FAIL: {$message}\n";
    $failures[] = $message;
};

// ----------------------------------------------------------------------------
// Static-source assertions on inc/Handlers/GitHub/GitHub.php.
// ----------------------------------------------------------------------------

$assert(
    false !== strpos($handler, "\$exclude_labels_raw  = \$config['exclude_labels'] ?? '';")
    || false !== strpos($handler, "\$exclude_labels_raw = \$config['exclude_labels'] ?? '';"),
    'handler reads exclude_labels from config alongside the existing post-fetch filters'
);

$assert(
    false !== strpos($handler, "explode( ',', (string) \$exclude_labels_raw )"),
    'handler explodes exclude_labels on commas like the rest of the comma-separated config fields'
);

$assert(
    false !== strpos($handler, 'strtolower( trim( (string) $label ) )'),
    'handler lowercases and trims each parsed exclude_labels entry'
);

$assert(
    false !== strpos($handler, 'array_intersect( $item_labels_lower, $exclude_labels )'),
    'handler uses array_intersect for ANY-match exclusion semantics'
);

$assert(
    false !== strpos($handler, "\$context->log( 'debug',")
    && false !== strpos($handler, 'excluded by label(s):'),
    'handler logs dropped items at debug level with the offending label(s)'
);

$assert(
    (int) strpos($handler, '$exclude_keywords') > 0
    && (int) strpos($handler, '$exclude_labels_raw') > (int) strpos($handler, '$exclude_keywords'),
    'exclude_labels is parsed alongside exclude_keywords (cohesive post-fetch filter tier)'
);

$assert(
    (int) strpos($handler, 'array_intersect( $item_labels_lower, $exclude_labels )')
    < (int) strpos($handler, "if ( 'all_time' !== \$timeframe_limit"),
    'exclude_labels filter runs before timeframe_limit, matching issue #281 implementation sketch'
);

// Targeted-fetch path warns about ignored exclude_labels too.
$assert(
    false !== strpos(
        $handler,
        "foreach ( array( 'search', 'exclude_keywords', 'exclude_labels', 'timeframe_limit', 'timeframe', 'labels' ) as \$field )"
    ),
    'targeted-fetch path lists exclude_labels in its ignored-list-filters warning'
);

// ----------------------------------------------------------------------------
// Static-source assertions on inc/Handlers/GitHub/GitHubSettings.php.
// ----------------------------------------------------------------------------

$assert(
    false !== strpos($settings, "'exclude_labels'"),
    'settings expose exclude_labels field for bundle import/export round-trip'
);

$assert(
    false !== strpos($settings, "Comma-separated label names to exclude"),
    'settings describe exclude_labels with comma-separated semantics'
);

$assert(
    false !== strpos($settings, 'ANY-match') && false !== strpos($settings, 'case-insensitive'),
    'settings document ANY-match drop and case-insensitive matching'
);

$assert(
    false !== strpos($settings, 'Applies to both issues and pulls'),
    'settings document that exclude_labels applies to both issues and pulls'
);

// Mutual-exclusion / precedence help text mentions exclude_labels.
$assert(
    false !== strpos($settings, 'exclude_labels, timeframe_limit'),
    'targeted-fetch help text lists exclude_labels in its list-filter precedence note'
);

// ----------------------------------------------------------------------------
// README assertions.
// ----------------------------------------------------------------------------

$assert(
    false !== strpos($readme, '`exclude_labels`'),
    'README documents exclude_labels'
);

$assert(
    false !== strpos($readme, '`labels`') && false !== strpos($readme, '`exclude_keywords`'),
    'README documents exclude_labels alongside labels and exclude_keywords'
);

// ----------------------------------------------------------------------------
// Behavioral coverage of the post-fetch filter logic.
// We rebuild the exact array_intersect filter the handler runs and exercise
// the documented cases. This is the source-of-truth for exclude_labels
// semantics — keep it byte-for-byte aligned with the handler.
// ----------------------------------------------------------------------------

/**
 * Mirror of the parse step inside fetchIssuesOrPulls().
 */
$parse_exclude_labels = static function ( $raw ): array {
    if (empty($raw) ) {
        return array();
    }
    return array_values(
        array_filter(
            array_map(
                static fn( $label ) => strtolower(trim((string) $label)),
                explode(',', (string) $raw)
            ), static fn( $label ) => '' !== $label 
        ) 
    );
};

/**
 * Mirror of the per-item check inside the foreach loop.
 *
 * @return array{kept: bool, hit: array<int,string>}
 */
$apply_exclude_labels = static function ( array $item_labels, array $exclude_labels ): array {
    if (empty($exclude_labels) || empty($item_labels) ) {
        return array( 'kept' => true, 'hit' => array() );
    }

    $item_labels_lower = array_map(
        static fn( $label ) => strtolower((string) $label),
        $item_labels
    );
    $hit = array_values(array_intersect($item_labels_lower, $exclude_labels));
    if (! empty($hit) ) {
        return array( 'kept' => false, 'hit' => $hit );
    }
    return array( 'kept' => true, 'hit' => array() );
};

// Case 1: empty / missing config is a no-op.
$assert(
    $parse_exclude_labels('') === array(),
    'empty exclude_labels parses to empty array (no-op)'
);
$assert(
    $apply_exclude_labels(array( 'bug', 'status:built' ), array())['kept'] === true,
    'empty exclude_labels keeps items with any labels (full backward compatibility)'
);
$assert(
    $apply_exclude_labels(array(), array( 'status:built' ))['kept'] === true,
    'item with no labels is never dropped by exclude_labels'
);

// Case 2: single excluded label — exact match drops.
$single = $apply_exclude_labels(
    array( 'status:idea-ready', 'status:built' ),
    $parse_exclude_labels('status:built')
);
$assert(
    false === $single['kept'] && in_array('status:built', $single['hit'], true),
    'single excluded label: matching item is dropped and hit reports the offending label'
);

// Case 3: multiple excluded labels — ANY-match drops.
$multi_drop = $apply_exclude_labels(
    array( 'status:idea-ready', 'status:abandoned' ),
    $parse_exclude_labels('status:built,status:abandoned')
);
$assert(
    false === $multi_drop['kept'] && in_array('status:abandoned', $multi_drop['hit'], true),
    'multiple excluded labels: ANY-match drops on second label'
);

$multi_keep = $apply_exclude_labels(
    array( 'status:idea-ready' ),
    $parse_exclude_labels('status:built,status:abandoned')
);
$assert(
    true === $multi_keep['kept'],
    'multiple excluded labels: item with no excluded labels is kept'
);

// Case 4: case-insensitive match.
$ci = $apply_exclude_labels(
    array( 'Status:Built' ),
    $parse_exclude_labels('STATUS:built')
);
$assert(
    false === $ci['kept'] && in_array('status:built', $ci['hit'], true),
    'case-insensitive match: mixed-case item label and mixed-case config both fold to lower'
);

// Whitespace tolerance (consistent with comma-separated config in this handler).
$assert(
    $parse_exclude_labels(' status:built , status:abandoned ')
        === array( 'status:built', 'status:abandoned' ),
    'parse trims whitespace around each comma-separated entry'
);

// Empty entries from stray commas drop out.
$assert(
    $parse_exclude_labels('status:built,,status:abandoned,')
        === array( 'status:built', 'status:abandoned' ),
    'parse skips empty entries from stray commas'
);

// Case 5: interaction with positive labels filter.
//   Positive `labels` filter is server-side (forwarded to GitHub list endpoint).
//   `exclude_labels` runs post-fetch on the returned set. Behaviorally, items
//   that came back because they matched the positive filter are still subject
//   to the negative filter and can be dropped.
$included_by_server = array(
    array( 'number' => 1, 'labels' => array( 'status:idea-ready' ) ),                          // kept
    array( 'number' => 2, 'labels' => array( 'status:idea-ready', 'status:built' ) ),          // dropped
    array( 'number' => 3, 'labels' => array( 'status:idea-ready', 'status:abandoned' ) ),      // dropped
    array( 'number' => 4, 'labels' => array( 'status:idea-ready', 'priority:high' ) ),         // kept
);
$exclude       = $parse_exclude_labels('status:built,status:abandoned');
$kept_numbers  = array();
foreach ( $included_by_server as $item ) {
    $result = $apply_exclude_labels($item['labels'], $exclude);
    if ($result['kept'] ) {
        $kept_numbers[] = $item['number'];
    }
}
$assert(
    $kept_numbers === array( 1, 4 ),
    'interaction with positive labels filter: server-included items are still negatively filtered post-fetch'
);

// ----------------------------------------------------------------------------
// PHP lint check on the touched files.
// ----------------------------------------------------------------------------

foreach ( array(
    'inc/Handlers/GitHub/GitHub.php',
    'inc/Handlers/GitHub/GitHubSettings.php',
) as $relative ) {
    $path   = $root . '/' . $relative;
    $output = array();
    $status = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    $assert(
        0 === $status,
        sprintf('php -l clean for %s (output: %s)', $relative, trim(implode("\n", $output)))
    );
}

echo "\n";
if (empty($failures) ) {
    echo "OK: GitHub exclude_labels smoke assertions passed.\n";
    exit(0);
}

echo sprintf("FAILED: %d assertion(s) failed.\n", count($failures));
exit(1);
