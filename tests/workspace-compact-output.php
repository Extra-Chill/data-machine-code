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
		'worktrees'                 => array( 'worktrees' => 40, 'protected_dirty' => 20 ),
		'locks'                     => array( 'active' => 2, 'stale' => 40, 'database' => array( 'locks' => $large_rows ) ),
		'cleanup'                   => array(
			'summary'            => array( 'would_remove' => 40, 'artifact_size_bytes' => 654321 ),
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
compact_output_assert(123456 === ( $hygiene['size']['total_bytes'] ?? null ), 'Compact hygiene output must preserve size bytes.');
compact_output_assert(40 === ( $hygiene['size']['entry_count'] ?? null ), 'Compact hygiene output must preserve size entry count.');
compact_output_assert(count((array) ( $hygiene['cleanup']['biggest_candidates'] ?? array() )) <= 5, 'Compact hygiene output must sample cleanup candidates.');

echo "workspace compact output test passed.\n";
