<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeActiveNoSignalTriagePreview.php';

use DataMachineCode\Workspace\WorktreeActiveNoSignalTriagePreview;

function active_no_signal_triage_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$now     = strtotime('2026-06-17T00:00:00+00:00');
$preview = WorktreeActiveNoSignalTriagePreview::build(
	array(
		array(
			'reason_code' => 'active_no_signal',
			'repo'        => 'repo-a',
			'liveness'    => 'stale',
			'created_at'  => '2026-06-16T12:00:00+00:00',
		),
		array(
			'reason_code' => 'lifecycle_reconciliation_candidate',
			'repo'        => 'repo-a',
			'liveness'    => 'unknown',
			'created_at'  => '2026-06-01T00:00:00+00:00',
		),
		array(
			'reason_code' => 'active_no_signal',
			'repo'        => 'repo-b',
			'liveness'    => '',
			'created_at'  => 'not-a-date',
		),
		array(
			'reason_code' => 'dirty_worktree',
			'repo'        => 'repo-b',
			'liveness'    => 'stale',
			'created_at'  => '2026-06-16T12:00:00+00:00',
		),
	),
	10,
	$now
);

active_no_signal_triage_assert_same(3, $preview['total'], 'active/no-signal total excludes non-active blockers');
active_no_signal_triage_assert_same(1, $preview['by_age']['lt_1d'], 'recent rows are counted');
active_no_signal_triage_assert_same(1, $preview['by_age']['7_30d'], 'older rows are counted');
active_no_signal_triage_assert_same(1, $preview['by_age']['unknown'], 'invalid dates are counted as unknown age');
active_no_signal_triage_assert_same(1, $preview['by_liveness']['stale'], 'stale liveness is counted');
active_no_signal_triage_assert_same(2, $preview['by_liveness']['unknown'], 'blank liveness is normalized to unknown');
active_no_signal_triage_assert_same(2, $preview['by_repo']['repo-a'], 'repo counts are aggregated');
active_no_signal_triage_assert_same(
	'studio wp datamachine-code workspace worktree active-no-signal-equivalent-clean-apply --dry-run --limit=10 --offset=0 --until-budget=60s --format=json',
	$preview['commands']['equivalent_clean_dry_run'],
	'equivalent-clean dry-run command is exact and non-destructive'
);
active_no_signal_triage_assert_same(
	'studio wp datamachine-code workspace worktree active-no-signal-remote-clean-apply --limit=10 --offset=0 --until-budget=60s --format=json',
	$preview['commands']['remote_clean_apply'],
	'remote-clean metadata apply command is exact'
);

echo "worktree-active-no-signal-triage-preview: ok\n";
