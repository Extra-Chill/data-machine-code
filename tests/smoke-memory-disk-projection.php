<?php
/**
 * Pure-PHP smoke test for memory disk projection helpers.
 *
 * Run: php tests/smoke-memory-disk-projection.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
}

require __DIR__ . '/../inc/Environment.php';
require __DIR__ . '/../inc/MemoryDiskProjection.php';

use DataMachineCode\MemoryDiskProjection;

$failures = array();
$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "  [FAIL] {$label}\n";
};

echo "Memory disk projection helper -- smoke\n";

$assert( 'relative path kept', 'MEMORY.md' === MemoryDiskProjection::normalize_relative_path( 'MEMORY.md' ) );
$assert( 'nested relative path kept', 'contexts/code.md' === MemoryDiskProjection::normalize_relative_path( 'contexts//code.md' ) );
$assert( 'leading dot slash trimmed', 'AGENTS.md' === MemoryDiskProjection::normalize_relative_path( './AGENTS.md' ) );
$assert( 'absolute path rejected', null === MemoryDiskProjection::normalize_relative_path( '/tmp/MEMORY.md' ) );
$assert( 'parent traversal rejected', null === MemoryDiskProjection::normalize_relative_path( '../MEMORY.md' ) );
$assert( 'nested parent traversal rejected', null === MemoryDiskProjection::normalize_relative_path( 'contexts/../MEMORY.md' ) );
$assert( 'empty path rejected', null === MemoryDiskProjection::normalize_relative_path( '' ) );
$assert( 'nul byte rejected', null === MemoryDiskProjection::normalize_relative_path( "MEMORY.md\0" ) );

$assert(
	'safe site path resolved',
	'/wordpress/AGENTS.md' === MemoryDiskProjection::resolve_site_projection_path( '/wordpress/', 'AGENTS.md' )
);
$assert(
	'unsafe site path rejected',
	null === MemoryDiskProjection::resolve_site_projection_path( '/wordpress', '../../etc/passwd' )
);

$hooks = MemoryDiskProjection::expected_event_hooks();
$assert( 'memory update hook named', 'datamachine_agent_memory_updated' === ( $hooks['memory_updated'] ?? '' ) );
$assert( 'memory delete hook named', 'datamachine_agent_memory_deleted' === ( $hooks['memory_deleted'] ?? '' ) );
$assert( 'guideline update hook named', 'datamachine_guideline_updated' === ( $hooks['guideline_updated'] ?? '' ) );

$assert( 'guideline substrate absent without WP helpers', false === MemoryDiskProjection::has_guideline_substrate() );

if ( ! empty( $failures ) ) {
	echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure}\n";
	}
	exit( 1 );
}

echo "\nOK\n";
exit( 0 );
