<?php
/**
 * Compact, deduplicated workspace capacity advisory coverage.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');

require_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

use DataMachineCode\Workspace\WorktreeDiskBudget;

function capacity_advisory_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$metrics = array(
	'workspace_path' => '/workspace',
	'free_bytes'     => 50 * 1073741824,
	'total_bytes'    => 100 * 1073741824,
	'free_inodes'    => 5000000,
	'total_inodes'   => 10000000,
	'worktree_count' => 121,
);
$warning      = WorktreeDiskBudget::evaluate($metrics);
$same_warning = WorktreeDiskBudget::evaluate(array_merge($metrics, array( 'worktree_count' => 150 )));
$new_threshold = WorktreeDiskBudget::evaluate($metrics, array( 'warn_worktree_count' => 110 ));
$blocked       = WorktreeDiskBudget::evaluate(array_merge($metrics, array( 'free_bytes' => 5 * 1073741824 )));
$measurement_warning = WorktreeDiskBudget::evaluate(array_merge($metrics, array( 'free_bytes' => null )));

capacity_advisory_assert('workspace_capacity' === ($warning['diagnostic_id'] ?? null), 'Capacity evidence must expose a stable diagnostic ID.');
capacity_advisory_assert(($warning['advisory_fingerprint'] ?? null) === ($same_warning['advisory_fingerprint'] ?? null), 'Unchanged warning state must retain a suppressible fingerprint.');
capacity_advisory_assert(($warning['advisory_fingerprint'] ?? null) !== ($new_threshold['advisory_fingerprint'] ?? null), 'A changed active threshold must produce a new fingerprint.');
capacity_advisory_assert(($warning['advisory_fingerprint'] ?? null) !== ($blocked['advisory_fingerprint'] ?? null), 'A blocking state change must produce a new fingerprint.');
capacity_advisory_assert(str_starts_with((string) ($warning['evidence_reference'] ?? ''), 'workspace_capacity@'), 'Capacity evidence must expose a compact reference.');
capacity_advisory_assert(2 === count((array) ($warning['recovery_actions'] ?? array())), 'Structured capacity evidence must retain bounded recovery actions.');
capacity_advisory_assert(50 * 1073741824 === ($warning['filesystem_free_bytes'] ?? null) && 5000000 === ($warning['filesystem_free_inodes'] ?? null), 'Advisory metadata must not replace full byte and inode evidence.');

$line = WorktreeDiskBudget::format_advisory($warning);
capacity_advisory_assert(1 === count(explode("\n", $line)), 'Default capacity advisory must fit on one line.');
capacity_advisory_assert(str_contains($line, (string) $warning['evidence_reference']) && str_contains($line, 'admission allowed'), 'Compact advisory must expose its evidence reference and admission state.');
capacity_advisory_assert(str_contains(WorktreeDiskBudget::format_summary($blocked), 'Admission: blocked'), 'Blocking capacity must retain the complete immediate summary.');
capacity_advisory_assert(str_contains(WorktreeDiskBudget::format_trigger_reasons($blocked)[0] ?? '', 'Creation is blocked unless --force is explicit.'), 'Blocking capacity must retain immediate remediation.');
capacity_advisory_assert(in_array('filesystem_free_bytes_measurement_unavailable', $measurement_warning['trigger_reasons'] ?? array(), true), 'Failed capacity measurement must retain a compact typed advisory.');
capacity_advisory_assert('' !== WorktreeDiskBudget::format_advisory($measurement_warning), 'Failed capacity measurement must remain visible in default human output.');

echo "workspace-capacity-advisory: ok\n";
