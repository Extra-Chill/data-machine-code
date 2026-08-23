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
	assert_true(str_contains($budget['warnings'][0] ?? '', 'Projected free filesystem space'), 'threshold messaging should identify projected filesystem free space');
	assert_true(98 * $gib === $budget['filesystem_used_bytes'], 'filesystem used bytes should be explicit');
	assert_true(2 * $gib === $budget['filesystem_free_bytes'], 'filesystem free bytes should be explicit');
	assert_true(100 * $gib === $budget['filesystem_total_bytes'], 'filesystem total bytes should be explicit');
	assert_true(( 8 * $gib ) + 1 === $budget['cleanup_recommendations'][0]['target_recovery_bytes'], 'recommendations should include the bytes needed to move above the inclusive floor');
	assert_true(is_int($budget['cleanup_recommendations'][0]['expected_reclaim_bytes']), 'expected reclaim bytes must preserve its public scalar type');
	assert_true(is_string($budget['cleanup_recommendations'][0]['expected_reclaim']), 'expected reclaim must preserve its public scalar type');
	assert_true(( 8 * $gib ) + 1 === $budget['cleanup_recommendations'][0]['expected_reclaim_bytes'], 'expected reclaim bytes must preserve the established recovery value');
	assert_true($budget['cleanup_recommendations'][0]['target_recovery'] === $budget['cleanup_recommendations'][0]['expected_reclaim'], 'expected reclaim must preserve the established human-readable recovery value');
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
	assert_true(in_array('projected_free_inodes_absolute_refusal_floor', $inode_refused['trigger_reasons'], true), 'inode refusal should have an independent trigger reason');
	assert_true(false === $inode_refused['creation_allowed'], 'inode-only refusal must deny creation');
	assert_true(true === $inode_refused['force_override_required'], 'inode-only refusal must require force');
	assert_true('inodes' === ($inode_refused['typed_trigger_reasons'][0]['resource'] ?? null), 'inode-only refusal must expose its typed resource');
	assert_true(12607200 === $inode_refused['filesystem_used_inodes'], 'used inode count should be explicit');
	assert_true(500000 === $inode_refused['filesystem_free_inodes'], 'free inode count should be explicit');
	assert_true(13107200 === $inode_refused['filesystem_total_inodes'], 'total inode count should be explicit');
	assert_true(810721 === $inode_refused['target_recovery_inodes'], 'global remediation should state exact inode recovery needed above the stricter inclusive floor');
	assert_true(null === $inode_refused['cleanup_recommendations'][0]['target_recovery_inodes'], 'unmeasured candidates must not claim the global inode deficit as reclaim evidence');
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
	assert_true(in_array('projected_free_inodes_absolute_warning_floor', $inode_warning['trigger_reasons'], true), 'inode warning should have a stable trigger reason');
	assert_true(500001 === $inode_warning['target_recovery_inodes'], 'warning pressure should target recovery through the inclusive warning floor');
	assert_true(str_contains(WorktreeDiskBudget::format_trigger_reasons($inode_warning)[0] ?? '', '1,500,000'), 'inode warning rendering should identify the observed inode value');
	assert_true(str_contains(WorktreeDiskBudget::format_trigger_reasons($inode_warning)[0] ?? '', '2,000,000'), 'inode warning rendering should identify the configured inode threshold');
	assert_true(str_contains(WorktreeDiskBudget::format_trigger_reasons($inode_warning)[0] ?? '', 'advisory'), 'inode warning rendering should identify advisory meaning');

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
	assert_true(in_array('projected_free_bytes_absolute_refusal_floor', $byte_only_pressure['trigger_reasons'], true), 'byte refusal remains independently visible');
	assert_true(! in_array('projected_free_inodes_absolute_refusal_floor', $byte_only_pressure['trigger_reasons'], true), 'healthy inodes should not be mislabeled under byte pressure');
	assert_true(false === $byte_only_pressure['creation_allowed'], 'byte-only refusal must deny creation');
	assert_true(true === $byte_only_pressure['force_override_required'], 'byte-only refusal must require force');
	assert_true('bytes' === ($byte_only_pressure['typed_trigger_reasons'][0]['resource'] ?? null), 'byte-only refusal must expose its typed resource');
	assert_true(str_contains(WorktreeDiskBudget::format_trigger_reasons($byte_only_pressure)[0] ?? '', 'blocking'), 'byte refusal rendering should identify blocking meaning');

	$worktree_count_warning = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'     => 40 * $gib,
			'total_bytes'    => 100 * $gib,
			'free_inodes'    => 4000000,
			'total_inodes'   => 13107200,
			'worktree_count' => 224,
		),
		$inode_thresholds
	);
	assert_true('warning' === $worktree_count_warning['status'], 'worktree count above its threshold should warn with healthy byte and inode capacity');
	assert_true(array( 'worktree_count_warning_threshold' ) === $worktree_count_warning['trigger_reasons'], 'worktree count warning should retain its stable reason code');
	assert_true(true === $worktree_count_warning['creation_allowed'], 'count-only pressure must allow creation');
	assert_true(false === $worktree_count_warning['force_override_required'], 'count-only pressure must not require force');
	assert_true(array( array( 'code' => 'worktree_count_warning_threshold', 'severity' => 'advisory', 'resource' => 'worktree_count', 'threshold' => 'warning_floor' ) ) === $worktree_count_warning['typed_trigger_reasons'], 'count-only pressure must expose a typed advisory trigger');
	$count_reason = WorktreeDiskBudget::format_trigger_reasons($worktree_count_warning)[0] ?? '';
	assert_true(str_contains($count_reason, '224'), 'worktree count rendering should identify the observed count');
	assert_true(str_contains($count_reason, '100'), 'worktree count rendering should identify the configured threshold');
	assert_true(str_contains($count_reason, 'advisory'), 'worktree count rendering should identify advisory meaning');
	$forced_count_warning = WorktreeDiskBudget::evaluate(
		array( 'free_bytes' => 40 * $gib, 'total_bytes' => 100 * $gib, 'free_inodes' => 4000000, 'total_inodes' => 13107200, 'worktree_count' => 224 ),
		$inode_thresholds,
		true
	);
	assert_true(true === $forced_count_warning['creation_allowed'], 'force must preserve count-only advisory admission');
	assert_true(false === $forced_count_warning['force_override_required'], 'count-only advisory pressure must not require force');
	assert_true(false === $forced_count_warning['force_override_applied'], 'force must not report an override when count-only advisory pressure is already allowed');

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
	assert_true(true === $forced_inode_pressure['creation_allowed'], 'force must allow otherwise-blocked inode admission');
	assert_true(true === $forced_inode_pressure['force_override_required'], 'forced inode admission must retain that force was required');
	assert_true('blocking' === ($forced_inode_pressure['typed_trigger_reasons'][0]['severity'] ?? null), 'forced inode admission must retain its blocking trigger severity');

	$projected_equality = WorktreeDiskBudget::evaluate(
		array(
			'free_bytes'   => 11 * $gib,
			'total_bytes'  => 100 * $gib,
			'free_inodes'  => 1000256,
			'total_inodes' => 10000000,
		),
		$inode_thresholds,
		false,
		array( 'bytes' => $gib, 'inodes' => 256, 'source' => 'test_plan' )
	);
	assert_true('refused' === $projected_equality['status'], 'projected equality with refusal floors must refuse');
	assert_true(10 * $gib === $projected_equality['projected_free_bytes'], 'projected free bytes should subtract demand');
	assert_true(1000000 === $projected_equality['projected_free_inodes'], 'projected free inodes should subtract demand');
	assert_true(1 === $projected_equality['refuse_byte_shortfall'], 'equality byte shortfall should be exactly one byte');
	assert_true(1 === $projected_equality['refuse_inode_shortfall'], 'equality inode shortfall should be exactly one inode');
	assert_true('test_plan' === $projected_equality['demand_source'], 'demand source should remain explicit');
	$git_lock_boundary = WorktreeDiskBudget::evaluate(
		array( 'free_bytes' => ( 10 * $gib ) + 16777517, 'total_bytes' => 100 * $gib, 'free_inodes' => 1000557, 'total_inodes' => 10000000 ),
		$inode_thresholds,
		false,
		array( 'bytes' => 16777516, 'inodes' => 556, 'source' => 'target_tree_plus_git_margin' )
	);
	assert_true('warning' === $git_lock_boundary['status'], 'One byte and inode beyond refusal floors should preserve tracked materialization plus Git lock margin while warning.');
	assert_true(1000001 === $git_lock_boundary['projected_free_inodes'], 'Boundary projection must subtract tracked entries and the separate Git safety margin.');
	$git_fixture = sys_get_temp_dir() . '/dmc-git-lock-boundary-' . bin2hex(random_bytes(6));
	mkdir($git_fixture . '/.git', 0777, true);
	for ( $index = 0; $index < 555; ++$index ) {
		file_put_contents($git_fixture . '/modeled-' . $index, '');
	}
	assert_true(false !== file_put_contents($git_fixture . '/.git/index.lock', ''), 'The bounded fixture must consume its modeled final inode with the Git index lock.');
	unlink($git_fixture . '/.git/index.lock');
	for ( $index = 0; $index < 555; ++$index ) {
		unlink($git_fixture . '/modeled-' . $index);
	}
	rmdir($git_fixture . '/.git');
	rmdir($git_fixture);

	$large_percentage_floor = WorktreeDiskBudget::evaluate(
		array( 'free_bytes' => 50 * $gib, 'total_bytes' => 1000 * $gib ),
		$inode_thresholds
	);
	assert_true('refused' === $large_percentage_floor['status'], 'large filesystems must retain the independent percentage refusal floor');
	assert_true(in_array('projected_free_bytes_percentage_refusal_floor', $large_percentage_floor['trigger_reasons'], true), 'percentage refusal reason should be independently exposed');
	assert_true(! in_array('projected_free_bytes_absolute_refusal_floor', $large_percentage_floor['trigger_reasons'], true), 'percentage pressure must not fabricate an absolute-floor reason');
	assert_true(array( 'code' => 'projected_free_bytes_percentage_refusal_floor', 'severity' => 'blocking', 'resource' => 'bytes', 'threshold' => 'refusal_floor' ) === $large_percentage_floor['typed_trigger_reasons'][0], 'percentage byte refusal must expose a typed blocking trigger');

	$percentage_thresholds = array(
		'warn_free_bytes'           => 0,
		'refuse_free_bytes'         => 0,
		'warn_free_percent'         => 15.0,
		'refuse_free_percent'       => 10.0,
		'warn_free_inodes'          => 0,
		'refuse_free_inodes'        => 0,
		'warn_free_inode_percent'   => 15.0,
		'refuse_free_inode_percent' => 10.0,
		'warn_worktree_count'       => 100,
	);
	$percentage_warning = WorktreeDiskBudget::evaluate(array( 'free_bytes' => 14 * $gib, 'total_bytes' => 100 * $gib, 'free_inodes' => 140, 'total_inodes' => 1000 ), $percentage_thresholds);
	assert_true(array( 'code' => 'projected_free_bytes_percentage_warning_floor', 'severity' => 'advisory', 'resource' => 'bytes', 'threshold' => 'warning_floor' ) === $percentage_warning['typed_trigger_reasons'][0], 'percentage byte warning must expose a typed advisory trigger');
	assert_true(array( 'code' => 'projected_free_inodes_percentage_warning_floor', 'severity' => 'advisory', 'resource' => 'inodes', 'threshold' => 'warning_floor' ) === $percentage_warning['typed_trigger_reasons'][1], 'percentage inode warning must expose a typed advisory trigger');
	$percentage_refusal = WorktreeDiskBudget::evaluate(array( 'free_bytes' => 9 * $gib, 'total_bytes' => 100 * $gib, 'free_inodes' => 90, 'total_inodes' => 1000 ), $percentage_thresholds);
	assert_true(array( 'code' => 'projected_free_bytes_percentage_refusal_floor', 'severity' => 'blocking', 'resource' => 'bytes', 'threshold' => 'refusal_floor' ) === $percentage_refusal['typed_trigger_reasons'][0], 'percentage byte refusal must expose a typed blocking trigger');
	assert_true(array( 'code' => 'projected_free_inodes_percentage_refusal_floor', 'severity' => 'blocking', 'resource' => 'inodes', 'threshold' => 'refusal_floor' ) === $percentage_refusal['typed_trigger_reasons'][1], 'percentage inode refusal must expose a typed blocking trigger');
	$forced_percentage_refusal = WorktreeDiskBudget::evaluate(array( 'free_bytes' => 9 * $gib, 'total_bytes' => 100 * $gib, 'free_inodes' => 90, 'total_inodes' => 1000 ), $percentage_thresholds, true);
	assert_true(true === $forced_percentage_refusal['creation_allowed'], 'force must allow percentage byte and inode refusal admission');
	assert_true(true === $forced_percentage_refusal['force_override_required'], 'forced percentage refusal must retain that force was required');
	assert_true(true === $forced_percentage_refusal['force_override_applied'], 'forced percentage refusal must record the applied override');
	assert_true(array( 'code' => 'projected_free_bytes_percentage_refusal_floor', 'severity' => 'blocking', 'resource' => 'bytes', 'threshold' => 'refusal_floor' ) === $forced_percentage_refusal['typed_trigger_reasons'][0], 'forced percentage byte refusal must retain typed blocking evidence');
	assert_true(array( 'code' => 'projected_free_inodes_percentage_refusal_floor', 'severity' => 'blocking', 'resource' => 'inodes', 'threshold' => 'refusal_floor' ) === $forced_percentage_refusal['typed_trigger_reasons'][1], 'forced percentage inode refusal must retain typed blocking evidence');

	$combined_thresholds = array_merge($inode_thresholds, array(
		'warn_free_percent'         => 0.0,
		'refuse_free_percent'       => 0.0,
		'warn_free_inode_percent'   => 0.0,
		'refuse_free_inode_percent' => 0.0,
	));
	$combined_pressure = WorktreeDiskBudget::evaluate(
		array( 'free_bytes' => 2 * $gib, 'total_bytes' => 100 * $gib, 'free_inodes' => 500000, 'total_inodes' => 13107200, 'worktree_count' => 224 ),
		$combined_thresholds
	);
	assert_true('refused' === $combined_pressure['status'], 'combined byte, inode, and count pressure must refuse admission');
	assert_true(false === $combined_pressure['creation_allowed'], 'combined blocking pressure must deny creation');
	assert_true(true === $combined_pressure['force_override_required'], 'combined byte or inode pressure must require force');
	assert_true(array( 'blocking', 'advisory', 'blocking' ) === array_column($combined_pressure['typed_trigger_reasons'], 'severity'), 'combined pressure must preserve each trigger severity in stable reason order');
	$combined_summary = WorktreeDiskBudget::format_summary($combined_pressure);
	assert_true(str_contains($combined_summary, 'Admission: blocked; force override required=yes; advisory triggers=worktree_count_warning_threshold; blocking triggers=projected_free_bytes_absolute_refusal_floor,projected_free_inodes_absolute_refusal_floor.'), 'human summary must distinguish advisory count pressure from byte and inode refusal');
	$legacy_summary = WorktreeDiskBudget::format_summary(array( 'status' => 'warning', 'trigger_reasons' => array( 'worktree_count_warning_threshold' ) ));
	assert_true(str_contains($legacy_summary, 'Admission: allowed; force override required=no; advisory triggers=worktree_count_warning_threshold; blocking triggers=none.'), 'legacy budget payloads must derive allowed advisory admission when explicit decision fields are absent');
	$legacy_forced_summary = WorktreeDiskBudget::format_summary(array( 'status' => 'warning', 'forced' => true, 'trigger_reasons' => array( 'projected_free_bytes_percentage_refusal_floor' ) ));
	assert_true(str_contains($legacy_forced_summary, 'Admission: allowed; force override required=yes; advisory triggers=none; blocking triggers=projected_free_bytes_percentage_refusal_floor. Force override applied=yes.'), 'legacy forced warning payloads must derive blocking override semantics from refusal reasons');

	$gnu_fixture = "Filesystem Inodes IUsed IFree IUse% Mounted on\n/dev/sda 1000 750 250 75% /workspace\n";
	$gnu = WorktreeDiskBudget::parse_inode_probe_output($gnu_fixture, 'gnu_df_i');
	assert_true(array( 'total_inodes' => 1000, 'free_inodes' => 250, 'probe' => 'gnu_df_i' ) === $gnu, 'GNU df inode output should parse deterministically');
	$bsd_fixture = "Filesystem 512-blocks Used Available Capacity iused ifree %iused Mounted on\n/dev/disk1 100 20 80 20% 500 1500 25% /workspace\n";
	$bsd = WorktreeDiskBudget::parse_inode_probe_output($bsd_fixture, 'bsd_df_i');
	assert_true('bsd_df_i' === $bsd['probe'] && 2000 === $bsd['total_inodes'] && 1500 === $bsd['free_inodes'], 'BSD df inode output should derive total inodes deterministically.');
	foreach ( array( '1000', '1000:2:1', 'x:1', '10:11' ) as $malformed ) {
		assert_true('unavailable' === WorktreeDiskBudget::parse_inode_probe_output($malformed, 'gnu_df_i')['probe'], 'malformed or impossible telemetry must remain unavailable');
	}
	assert_true('unavailable' === WorktreeDiskBudget::parse_inode_probe_output('10:1', 'unsupported')['probe'], 'unsupported probes must remain unavailable');
	$bsd_fallback = WorktreeDiskBudget::probe_inode_capacity(
		__DIR__,
		static fn( array $argv, string $probe ): array => array( 'success' => 'bsd_df_i' === $probe, 'output' => "Filesystem 512-blocks Used Available Capacity iused ifree %iused Mounted on\n/dev/disk1 100 20 80 20% 1800 1200 60% /workspace" )
	);
	assert_true('bsd_df_i' === $bsd_fallback['probe'], 'failed GNU probing should fall through to BSD df.');

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
