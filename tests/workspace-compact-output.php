<?php
/**
 * Standalone coverage for compact workspace cleanup/hygiene/lock JSON output.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Cli/WorkspaceCompactOutput.php';

use DataMachineCode\Cli\WorkspaceCompactOutput;

function compact_output_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function compact_output_large_rows( int $count ): array {
	$rows = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$rows[] = array(
			'handle'              => 'repo@branch-' . $i,
			'repo'                => 'repo',
			'branch'              => 'branch-' . $i,
			'path'                => '/tmp/repo@branch-' . $i,
			'reason_code'         => 0 === $i % 2 ? 'dirty_worktree' : 'unpushed_commits',
			'reason'              => str_repeat('large evidence ', 80),
			'size_bytes'          => 1024 * ( $i + 1 ),
			'artifact_size_bytes' => 512 * ( $i + 1 ),
			'evidence'            => array_fill(0, 20, str_repeat('x', 100)),
		);
	}
	return $rows;
}

$large_rows = compact_output_large_rows(40);
$cleanup    = WorkspaceCompactOutput::cleanup_result(
	array(
		'success'    => true,
		'dry_run'    => true,
		'candidates' => $large_rows,
		'skipped'    => $large_rows,
		'summary'    => array(
			'would_remove'          => 40,
			'skipped'               => 40,
			'total_size_bytes'      => 123456,
			'artifact_size_bytes'   => 654321,
			'skipped_by_reason'     => array(
				'dirty_worktree'   => 20,
				'unpushed_commits' => 20,
			),
			'skipped_next_commands' => array(
				array(
					'reason_code' => 'dirty_worktree',
					'command'     => 'git -C <worktree-path> status --short',
				),
			),
		),
	)
);

compact_output_assert(! isset($cleanup['candidates']), 'Compact cleanup output must omit full candidates array.');
compact_output_assert(! isset($cleanup['skipped']), 'Compact cleanup output must omit full skipped array.');
compact_output_assert(40 === ( $cleanup['row_counts']['candidates'] ?? null ), 'Compact cleanup output must preserve candidate count.');
compact_output_assert(123456 === ( $cleanup['bytes']['total_size_bytes'] ?? null ), 'Compact cleanup output must preserve total bytes.');
compact_output_assert(20 === ( $cleanup['blockers']['dirty_worktree']['count'] ?? null ), 'Compact cleanup output must preserve blocker counts.');
compact_output_assert(count((array) ( $cleanup['samples']['skipped'] ?? array() )) <= 5, 'Compact cleanup output must sample skipped rows.');
compact_output_assert(! empty($cleanup['next_commands']), 'Compact cleanup output must preserve next commands.');

$active_report = WorkspaceCompactOutput::cleanup_result(
	array(
		'success'    => true,
		'mode'       => 'active_no_signal_report',
		'rows'       => $large_rows,
		'summary'    => array(
			'total_active_no_signal' => 40,
			'inspected'              => 40,
			'by_suggested_action'    => array( 'remote_tracking_clean' => 40 ),
		),
		'pagination' => array(
			'total'        => 80,
			'offset'       => 0,
			'limit'        => 40,
			'next_offset'  => 40,
			'next_command' => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=40 --offset=40 --format=json',
		),
	)
);

compact_output_assert(! isset($active_report['rows']), 'Compact active/no-signal report must omit full rows array.');
compact_output_assert(40 === ( $active_report['row_counts']['rows'] ?? null ), 'Compact active/no-signal report must preserve row count.');
compact_output_assert(40 === ( $active_report['pagination']['next_offset'] ?? null ), 'Compact active/no-signal report must preserve pagination.');
compact_output_assert(in_array('studio wp datamachine-code workspace worktree active-no-signal-report --limit=40 --offset=40 --format=json', (array) ( $active_report['next_commands'] ?? array() ), true), 'Compact active/no-signal report must expose next page command.');

$active_apply = WorkspaceCompactOutput::cleanup_result(
	array(
		'success'    => true,
		'mode'       => 'active_no_signal_remote_clean_apply',
		'dry_run'    => true,
		'planned'    => $large_rows,
		'written'    => $large_rows,
		'skipped'    => $large_rows,
		'summary'    => array(
			'inspected'         => 40,
			'planned'           => 40,
			'written'           => 40,
			'skipped'           => 40,
			'skipped_by_reason' => array( 'not_remote_tracking_clean' => 40 ),
		),
		'pagination' => array(
			'next_command' => 'studio wp datamachine-code workspace worktree active-no-signal-remote-clean-apply --dry-run --limit=40 --offset=40 --format=json',
		),
	)
);

compact_output_assert(! isset($active_apply['planned']), 'Compact active/no-signal apply must omit full planned array.');
compact_output_assert(! isset($active_apply['written']), 'Compact active/no-signal apply must omit full written array.');
compact_output_assert(40 === ( $active_apply['row_counts']['planned'] ?? null ), 'Compact active/no-signal apply must preserve planned count.');
compact_output_assert(40 === ( $active_apply['row_counts']['written'] ?? null ), 'Compact active/no-signal apply must preserve written count.');
compact_output_assert(40 === ( $active_apply['blockers']['not_remote_tracking_clean']['count'] ?? null ), 'Compact active/no-signal apply must preserve blocker counts from summary.');
compact_output_assert(in_array('studio wp datamachine-code workspace worktree active-no-signal-remote-clean-apply --dry-run --limit=40 --offset=40 --format=json', (array) ( $active_apply['next_commands'] ?? array() ), true), 'Compact active/no-signal apply must expose next page command.');

$locks = WorkspaceCompactOutput::lock_result(
	array(
		'active'     => 2,
		'stale'      => 40,
		'database'   => array(
			'total'  => 40,
			'active' => 1,
			'stale'  => 39,
			'locks'  => $large_rows,
		),
		'filesystem' => array(
			'total'    => 40,
			'active'   => 1,
			'stale'    => 39,
			'locks'    => $large_rows,
			'guidance' => array(
				'dry_run_command' => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			),
		),
		'stale_locks' => array(
			'count'           => 40,
			'preview_command' => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			'apply_command'   => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			'database'        => $large_rows,
			'filesystem'      => $large_rows,
		),
	)
);

compact_output_assert(40 === ( $locks['database']['total'] ?? null ), 'Compact lock output must preserve database lock count.');
compact_output_assert(count((array) ( $locks['database']['lock_samples'] ?? array() )) <= 5, 'Compact lock output must sample database locks.');
compact_output_assert(! isset($locks['database']['locks']), 'Compact lock output must omit full database locks array.');
compact_output_assert('wp datamachine-code workspace worktree locks --prune-stale --format=json' === ( $locks['stale_locks']['apply_command'] ?? null ), 'Compact lock output must keep prune command.');

$hygiene = WorkspaceCompactOutput::hygiene_report(
	array(
		'success'                   => true,
		'workspace_path'            => '/workspace',
		'disk'                      => array( 'free_bytes' => 999 ),
		'fast_stats'                => array(
			'counts'              => array(
				'cleanup_eligible_unprobed_count' => 40,
				'dirty_probe_skipped_count'       => 40,
			),
			'safety_probe_status' => 'not_run_inventory_only',
		),
		'worktrees'                 => array( 'worktrees' => 40, 'protected_dirty' => 20 ),
		'locks'                     => array( 'active' => 2, 'stale' => 40, 'database' => array( 'locks' => $large_rows ) ),
		'cleanup'                   => array(
			'summary'            => array(
				'would_remove'        => 40,
				'artifact_size_bytes' => 654321,
				'cleanup_buckets'     => array(
					'cleanup_eligible_pending_revalidation' => 40,
					'safe_to_remove_now'                    => 0,
				),
			),
			'biggest_candidates' => $large_rows,
		),
		'size'                      => array(
			'total_bytes' => 123456,
			'entries'     => $large_rows,
			'top_entries' => $large_rows,
		),
		'suggested_cleanup_command' => 'wp datamachine-code workspace worktree cleanup --dry-run --format=json',
	)
);

compact_output_assert(40 === ( $hygiene['worktrees']['worktrees'] ?? null ), 'Compact hygiene output must preserve worktree counts.');
compact_output_assert(40 === ( $hygiene['fast_stats']['counts']['cleanup_eligible_unprobed_count'] ?? null ), 'Compact hygiene output must label cheap cleanup candidates as unprobed.');
compact_output_assert(! isset($hygiene['fast_stats']['counts']['safe_removable_count']), 'Compact hygiene output must not expose misleading safe_removable_count for cheap inventory.');
compact_output_assert(40 === ( $hygiene['cleanup']['summary']['cleanup_buckets']['cleanup_eligible_pending_revalidation'] ?? null ), 'Compact cleanup summary must preserve pending-revalidation bucket.');
compact_output_assert(0 === ( $hygiene['cleanup']['summary']['cleanup_buckets']['safe_to_remove_now'] ?? null ), 'Compact cleanup summary must not mark unprobed inventory candidates safe.');
compact_output_assert(123456 === ( $hygiene['size']['total_bytes'] ?? null ), 'Compact hygiene output must preserve size bytes.');
compact_output_assert(40 === ( $hygiene['size']['entry_count'] ?? null ), 'Compact hygiene output must preserve size entry count.');
compact_output_assert(count((array) ( $hygiene['cleanup']['biggest_candidates'] ?? array() )) <= 5, 'Compact hygiene output must sample cleanup candidates.');

echo "workspace compact output test passed.\n";
