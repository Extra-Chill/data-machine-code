<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Workspace\WorktreeDiskBudget;

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$gib = 1073741824;

try {
	$budget = WorktreeDiskBudget::evaluate(
		array(
			'workspace_path' => '/tmp/dmc-test-workspace',
			'free_bytes'     => 2 * $gib,
			'total_bytes'    => 100 * $gib,
			'worktree_count' => 12,
		),
		array(
			'warn_free_bytes'     => 20 * $gib,
			'refuse_free_bytes'   => 10 * $gib,
			'warn_free_percent'   => 15.0,
			'refuse_free_percent' => 10.0,
			'warn_worktree_count' => 100,
		)
	);

	assert_true('refused' === $budget['status'], 'low free space should refuse worktree creation');
	assert_true('independent_filesystem_bytes_and_inodes' === $budget['safety_basis'], 'response should identify independent byte and inode safeguards');
	assert_true(str_contains($budget['warnings'][0] ?? '', 'Free filesystem space'), 'threshold messaging should identify filesystem free space');
	assert_true(98 * $gib === $budget['filesystem_used_bytes'], 'filesystem used bytes should be explicit');
	assert_true(2 * $gib === $budget['filesystem_free_bytes'], 'filesystem free bytes should be explicit');
	assert_true(100 * $gib === $budget['filesystem_total_bytes'], 'filesystem total bytes should be explicit');
	assert_true(8 * $gib === $budget['cleanup_recommendations'][0]['expected_reclaim_bytes'], 'recommendations should include the bytes needed to clear the effective floor');
	assert_true(str_contains(WorktreeDiskBudget::format_summary($budget), '2.0 GiB (2.0%) free'), 'summary should include current free GiB and percent');
	assert_true('studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json' === $budget['artifact_cleanup_command'], 'disk-budget artifact cleanup command should create a DB-backed review plan');

	$commands = array_column($budget['cleanup_recommendations'], 'command');
	assert_true(in_array('studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json', $commands, true), 'DB-backed artifact cleanup plan command is missing');
	assert_true(in_array('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25', $commands, true), 'bounded cleanup-eligible dry-run command is missing');
	assert_true(in_array('studio wp datamachine-code workspace worktree emergency-cleanup --format=json', $commands, true), 'emergency cleanup report command is missing');

	$bounded = $budget['cleanup_recommendations'][1];
	assert_true(str_contains($bounded['action'], 'apply revalidates'), 'bounded cleanup action must explain apply revalidation');
	assert_true('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25' === $bounded['preview_command'], 'bounded cleanup preview command is missing');
	assert_true('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=25' === $bounded['apply_command'], 'bounded cleanup apply command is missing');
	assert_true(str_contains($bounded['apply_note'], 'may skip rows'), 'bounded cleanup apply note must explain dirty/unpushed revalidation can block removal');

	$artifacts = $budget['cleanup_recommendations'][0];
	assert_true('studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json' === $artifacts['preview_command'], 'artifact cleanup preview should create a DB-backed plan');
	assert_true('studio wp datamachine-code workspace cleanup apply <run-id>' === $artifacts['apply_command'], 'artifact cleanup guidance should expose the DB-backed apply command');
	assert_true(str_contains($artifacts['apply_note'], 'run_id'), 'artifact cleanup guidance should explain how to obtain the apply run id');

	$healthy = WorktreeDiskBudget::evaluate(
		array(
			'workspace_path' => '/tmp/dmc-test-workspace',
			'free_bytes'     => 40 * $gib,
			'total_bytes'    => 100 * $gib,
			'worktree_count' => 12,
		),
		array(
			'warn_free_bytes'     => 20 * $gib,
			'refuse_free_bytes'   => 10 * $gib,
			'warn_free_percent'   => 15.0,
			'refuse_free_percent' => 10.0,
			'warn_worktree_count' => 100,
		)
	);

	assert_true('ok' === $healthy['status'], 'healthy free space should pass the worktree disk budget gate');
	assert_true(array() === $healthy['warnings'], 'healthy free space should not emit disk budget warnings');
	assert_true(array_key_exists('workspace_size_bytes', $healthy) && null === $healthy['workspace_size_bytes'], 'legacy workspace size field should remain present when diagnostics are unavailable');

	$inode_thresholds = array(
		'warn_free_bytes'          => 20 * $gib,
		'refuse_free_bytes'        => 10 * $gib,
		'warn_free_percent'        => 15.0,
		'refuse_free_percent'      => 10.0,
		'warn_free_inodes'         => 2000000,
		'refuse_free_inodes'       => 1000000,
		'warn_free_inode_percent'  => 15.0,
		'refuse_free_inode_percent'=> 10.0,
		'warn_worktree_count'      => 100,
	);
	$inode_refused = WorktreeDiskBudget::evaluate(
		array(
			'workspace_path' => '/tmp/dmc-test-workspace',
			'free_bytes'     => 40 * $gib,
			'total_bytes'    => 100 * $gib,
			'free_inodes'    => 500000,
			'total_inodes'   => 13107200,
		),
		$inode_thresholds
	);
	assert_true('refused' === $inode_refused['status'], 'healthy bytes with insufficient inodes should refuse admission');
	assert_true(in_array('free_inode_refusal_threshold', $inode_refused['trigger_reasons'], true), 'inode refusal should have an independent trigger reason');
	assert_true(12607200 === $inode_refused['filesystem_used_inodes'], 'used inode count should be explicit');
	assert_true(500000 === $inode_refused['filesystem_free_inodes'], 'free inode count should be explicit');
	assert_true(13107200 === $inode_refused['filesystem_total_inodes'], 'total inode count should be explicit');
	assert_true(500000 === $inode_refused['cleanup_recommendations'][0]['expected_reclaim_inodes'], 'remediation should state exact inode recovery needed');
	assert_true(str_contains(WorktreeDiskBudget::format_summary($inode_refused), '500,000'), 'summary should include free inode capacity');

	$inode_warning = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'   => 40 * $gib,
			'total_bytes'  => 100 * $gib,
			'free_inodes'  => 1500000,
			'total_inodes' => 13107200,
		),
		$inode_thresholds
	);
	assert_true('warning' === $inode_warning['status'], 'inode warning threshold should not refuse admission');
	assert_true(in_array('free_inode_warning_threshold', $inode_warning['trigger_reasons'], true), 'inode warning should have a stable trigger reason');

	$inode_recovered = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'   => 40 * $gib,
			'total_bytes'  => 100 * $gib,
			'free_inodes'  => 3915099,
			'total_inodes' => 13107200,
		),
		$inode_thresholds
	);
	assert_true('ok' === $inode_recovered['status'], 'successful cleanup recovery above both inode thresholds should pass');

	$byte_only_pressure = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'   => 2 * $gib,
			'total_bytes'  => 100 * $gib,
			'free_inodes'  => 4000000,
			'total_inodes' => 13107200,
		),
		$inode_thresholds
	);
	assert_true('refused' === $byte_only_pressure['status'], 'healthy inodes must not weaken byte refusal');
	assert_true(in_array('free_space_refusal_threshold', $byte_only_pressure['trigger_reasons'], true), 'byte refusal remains independently visible');
	assert_true(! in_array('free_inode_refusal_threshold', $byte_only_pressure['trigger_reasons'], true), 'healthy inodes should not be mislabeled under byte pressure');

	$unsupported_inodes = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'  => 40 * $gib,
			'total_bytes' => 100 * $gib,
		),
		$inode_thresholds
	);
	assert_true('ok' === $unsupported_inodes['status'], 'unsupported inode telemetry should preserve healthy byte admission');
	assert_true(null === $unsupported_inodes['filesystem_free_inodes'], 'unsupported inode telemetry should use null, not an estimate');
	assert_true('unavailable' === $unsupported_inodes['inode_probe'], 'unsupported inode telemetry should expose a typed probe state');
	assert_true(str_contains(implode(' ', $unsupported_inodes['diagnostic_messages']), 'byte safeguards remain enforced'), 'unsupported inode telemetry should explain byte-only enforcement');

	$forced_inode_pressure = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'   => 40 * $gib,
			'total_bytes'  => 100 * $gib,
			'free_inodes'  => 1,
			'total_inodes' => 13107200,
		),
		$inode_thresholds,
		true
	);
	assert_true('warning' === $forced_inode_pressure['status'], 'explicit force should downgrade inode refusal to a warning');
	assert_true(true === $forced_inode_pressure['force_override_applied'], 'forced inode admission should remain explicit in evidence');

	$root_mount = WorktreeDiskBudget::parse_mountinfo(
		'32 24 8:2 / / rw,relatime - ext4 /dev/sdb rw',
		'/var/lib/datamachine/workspace'
	);
	assert_true('/' === ( $root_mount['mount_target'] ?? null ), 'normal root mount target should be discovered');
	assert_true('/dev/sdb' === ( $root_mount['mount_source'] ?? null ), 'normal root mount source should be discovered');
	assert_true('/' === ( $root_mount['mount_source_subdirectory'] ?? null ), 'normal root mount should report the device root');

	$subdirectory_mount = WorktreeDiskBudget::parse_mountinfo(
		implode(
			"\n",
			array(
				'32 24 8:2 / /mnt/storage rw,relatime - ext4 /dev/sdb rw',
				'41 32 8:2 /dmc-workspace /var/lib/datamachine/workspace rw,relatime - ext4 /dev/sdb rw',
			)
		),
		'/var/lib/datamachine/workspace/repository'
	);
	assert_true('/var/lib/datamachine/workspace' === ( $subdirectory_mount['mount_target'] ?? null ), 'most specific mount target should be selected');
	assert_true('/dev/sdb' === ( $subdirectory_mount['mount_source'] ?? null ), 'subdirectory mount source should be discovered');
	assert_true('/dmc-workspace' === ( $subdirectory_mount['mount_source_subdirectory'] ?? null ), 'source subdirectory should be explicit');

	$shared = WorktreeDiskBudget::evaluate(
		array(
			'workspace_path'            => '/var/lib/datamachine/workspace',
			'free_bytes'                => 20 * $gib,
			'total_bytes'               => 200 * $gib,
			'workspace_allocated_bytes' => 125 * $gib,
			'workspace_usage_probe'     => 'provided',
			'mount_target'              => '/var/lib/datamachine/workspace',
			'mount_source'              => '/dev/sdb',
			'mount_source_subdirectory' => '/dmc-workspace',
			'worktree_count'            => 12,
		),
		array(
			'warn_free_bytes'     => 20 * $gib,
			'refuse_free_bytes'   => 10 * $gib,
			'warn_free_percent'   => 15.0,
			'refuse_free_percent' => 10.0,
			'warn_worktree_count' => 100,
		)
	);
	assert_true(55 * $gib === $shared['shared_usage_estimate_bytes'], 'shared usage should be filesystem used minus measured workspace usage');
	assert_true(true === $shared['shared_usage_detected'], 'material shared usage should be detected');
	assert_true(str_contains($shared['diagnostic_messages'][0] ?? '', 'outside the measured workspace subtree'), 'shared usage should have explicit diagnostic messaging');
	assert_true(str_contains(WorktreeDiskBudget::format_summary($shared), 'Estimated usage outside'), 'summary should explain the shared usage delta');

	$unavailable = WorktreeDiskBudget::inspect(
		__DIR__,
		array(),
		false,
		array(
			'mountinfo'              => null,
			'include_workspace_usage' => false,
		)
	);
	assert_true(null === $unavailable['mount_target'], 'unavailable mount probe should return null fields');
	assert_true(null === $unavailable['workspace_allocated_bytes'], 'disabled usage probe should return null allocated bytes');
	assert_true('disabled' === $unavailable['workspace_usage_probe'], 'disabled usage probe state should be explicit');

	$bounded_probe = WorktreeDiskBudget::inspect(
		__DIR__,
		array(),
		false,
		array(
			'mountinfo'                  => '',
			'include_workspace_usage'     => true,
			'usage_probe_timeout_seconds' => 1,
		)
	);
	assert_true(is_int($bounded_probe['workspace_allocated_bytes']), 'bounded subtree probe should report allocated bytes for a small readable directory');
	assert_true('bounded_du' === $bounded_probe['workspace_usage_probe'], 'successful bounded subtree probe should identify its mode');

	$refusal_with_diagnostics = WorktreeDiskBudget::evaluate(
		array(
			'workspace_path'            => '/tmp/dmc-test-workspace',
			'free_bytes'                => 2 * $gib,
			'total_bytes'               => 100 * $gib,
			'workspace_allocated_bytes' => 40 * $gib,
			'worktree_count'            => 12,
		),
		array(
			'warn_free_bytes'     => 20 * $gib,
			'refuse_free_bytes'   => 10 * $gib,
			'warn_free_percent'   => 15.0,
			'refuse_free_percent' => 10.0,
			'warn_worktree_count' => 100,
		)
	);
	assert_true($budget['status'] === $refusal_with_diagnostics['status'], 'diagnostics must not change refusal behavior');
	assert_true($budget['effective_refuse_bytes'] === $refusal_with_diagnostics['effective_refuse_bytes'], 'diagnostics must not change the refusal threshold');
	assert_true($budget['trigger_reasons'] === $refusal_with_diagnostics['trigger_reasons'], 'diagnostics must not change refusal reasons');

	fwrite(STDOUT, "worktree-disk-budget ok\n");
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
