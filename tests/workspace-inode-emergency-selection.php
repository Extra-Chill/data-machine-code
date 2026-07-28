<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHygieneReport.php';

$harness = new class {
	use DataMachineCode\Workspace\WorkspaceHygieneReport;

	public function select( array $rows, int $target, int $limit ): array {
		$method = new ReflectionMethod($this, 'select_emergency_artifact_candidates');
		return $method->invoke($this, $rows, $target, $limit);
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
$selection = $harness->select($rows, 100, 3);
inode_selection_assert(array( 'large', 'medium' ) === array_column($selection['candidates'], 'handle'), 'Inode pressure must select enough measured artifacts in ranked order.');
inode_selection_assert(110 === $selection['planned_measured_recovery_inodes'], 'Planned inode recovery must sum candidate evidence, not copy the global deficit.');
inode_selection_assert(1 === $selection['unknown_candidate_count'], 'Unknown candidate counts must remain explicitly unknown.');
inode_selection_assert(true === $selection['target_met'], 'Measured candidates meeting the global target must complete the plan.');

$bounded = $harness->select($rows, 100, 1);
inode_selection_assert(false === $bounded['target_met'] && 70 === $bounded['planned_measured_recovery_inodes'], 'Chunk bound must produce truthful unmet-target evidence.');

echo "workspace-inode-emergency-selection: ok\n";
