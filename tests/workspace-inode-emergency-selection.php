<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHygieneReport.php';

$harness = new class {
	use DataMachineCode\Workspace\WorkspaceHygieneReport;

	public function select( array $rows, int $bytes, int $inodes, int $limit ): array {
		$method = new ReflectionMethod($this, 'select_emergency_candidates');
		return $method->invoke($this, $rows, $bytes, $inodes, $limit, 'artifact_size_bytes');
	}
};

function inode_selection_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$rows = array(
	array( 'handle' => 'large', 'entry_count' => 70, 'entry_count_status' => 'measured', 'artifact_size_bytes' => 1 ),
	array( 'handle' => 'medium', 'entry_count' => 40, 'entry_count_status' => 'measured', 'artifact_size_bytes' => 2 ),
	array( 'handle' => 'unknown', 'entry_count' => null, 'entry_count_status' => 'unknown', 'artifact_size_bytes' => 999999 ),
);
$selection = $harness->select($rows, 0, 100, 3);
inode_selection_assert(array( 'large', 'medium' ) === array_column($selection['candidates'], 'handle'), 'Inode pressure must select enough measured artifacts in ranked order.');
inode_selection_assert(110 === $selection['planned_measured_recovery_inodes'], 'Planned inode recovery must sum candidate evidence, not copy the global deficit.');
inode_selection_assert(1 === $selection['unknown_candidate_count'], 'Unknown candidate counts must remain explicitly unknown.');
inode_selection_assert(true === $selection['target_met'], 'Measured candidates meeting the global target must complete the plan.');

$bounded = $harness->select($rows, 0, 100, 1);
inode_selection_assert(false === $bounded['target_met'] && 70 === $bounded['planned_measured_recovery_inodes'], 'Chunk bound must produce truthful unmet-target evidence.');

$byte_rows = array(
	array( 'handle' => 'inode-heavy', 'entry_count' => 1000, 'entry_count_status' => 'measured', 'artifact_size_bytes' => 10 ),
	array( 'handle' => 'byte-heavy', 'entry_count' => 2, 'entry_count_status' => 'measured', 'artifact_size_bytes' => 1000 ),
);
$byte_selection = $harness->select($byte_rows, 900, 0, 1);
inode_selection_assert('byte-heavy' === $byte_selection['candidates'][0]['handle'], 'Byte-only pressure must not destructively prefer inode-heavy candidates.');
inode_selection_assert(1000 === $byte_selection['planned_measured_recovery_bytes'], 'Byte recovery evidence must use the selected candidate bytes.');

$mixed = $harness->select($byte_rows, 900, 900, 2);
inode_selection_assert(true === $mixed['target_met'], 'Mixed pressure must select enough measured candidates to satisfy both resources.');
inode_selection_assert($mixed['planned_measured_recovery_bytes'] >= 900 && $mixed['planned_measured_recovery_inodes'] >= 900, 'Mixed recovery evidence must satisfy both targets independently.');

echo "workspace-inode-emergency-selection: ok\n";
