<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorktreeDiskBudget;

function capacity_envelope_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$budget = WorktreeDiskBudget::evaluate(
	array( 'workspace_path' => '/workspace', 'free_bytes' => 40, 'total_bytes' => 100, 'free_inodes' => 25, 'total_inodes' => 100, 'inode_probe' => 'test' ),
	array( 'warn_free_bytes' => 0, 'refuse_free_bytes' => 0, 'warn_free_percent' => 0, 'refuse_free_percent' => 0, 'warn_free_inodes' => 0, 'refuse_free_inodes' => 0, 'warn_free_inode_percent' => 0, 'refuse_free_inode_percent' => 0 )
);
foreach ( array( 'total_bytes', 'used_bytes', 'free_bytes', 'used_percent', 'free_percent', 'total_inodes', 'used_inodes', 'free_inodes', 'used_inode_percent', 'free_inode_percent', 'inode_probe', 'status', 'warnings', 'trigger_reasons', 'cleanup_dry_run_command' ) as $field ) {
	capacity_envelope_assert(array_key_exists($field, $budget), 'Capacity envelope missing ' . $field . '.');
}
$summary = WorktreeDiskBudget::format_summary($budget);
capacity_envelope_assert(str_contains($summary, 'Inodes: 75 (75.0%) used, 25 (25.0%) free, 100 total; status='), 'Human capacity summary must render inode total, used, free, percentages, and status.');

$ability_source = file_get_contents(dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php');
$cli_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
capacity_envelope_assert(str_contains((string) $ability_source, "'workspace_capacity' => array("), 'Workspace-show ability schema must declare workspace_capacity.');
capacity_envelope_assert(str_contains((string) $cli_source, "'metric' => 'inode_capacity'"), 'Human hygiene rendering must expose inode capacity.');
capacity_envelope_assert(str_contains((string) $cli_source, "'metric' => 'capacity_status'"), 'Human hygiene rendering must expose capacity status.');

echo "workspace-capacity-envelope: ok\n";
