<?php
/**
 * Pure-PHP smoke test for the Homeboy capability helper.
 *
 * Run: php tests/smoke-homeboy.php
 *
 * Exercises the shell-probe path against a synthetic PATH so the
 * result is deterministic regardless of whether the host actually
 * has `homeboy` installed. Avoids a WP bootstrap.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require __DIR__ . '/../inc/Environment.php';
require __DIR__ . '/../inc/Homeboy.php';

use DataMachineCode\Environment;
use DataMachineCode\Homeboy;

$failures = array();
$assert = function ( string $label, bool $cond ) use ( &$failures ): void {
	if ( $cond ) {
		echo "  ✓ {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "  ✗ {$label}\n";
};

echo "Homeboy capability helper — smoke\n";

// ── Branch 1: shell unavailable ────────────────────────────────
// Skip when the host disabled shell_exec entirely.
if ( Environment::has_shell() ) {
	echo "  (host has shell — exercising probe paths)\n";
} else {
	echo "  (host has no shell — only the absent branch is testable)\n";
	Homeboy::reset_cache();
	$assert( 'no shell: is_available() returns false', false === Homeboy::is_available() );
	echo "\nOK\n";
	exit( 0 );
}

$original_path = getenv( 'PATH' ) ?: '';
$tmp_root      = sys_get_temp_dir() . '/dmc-homeboy-smoke-' . uniqid( '', true );
mkdir( $tmp_root, 0755, true );

// ── Branch 2: empty PATH → binary not found ────────────────────
putenv( 'PATH=' . $tmp_root );
Homeboy::reset_cache();
$assert( 'empty PATH: is_available() returns false', false === Homeboy::is_available() );
$assert( 'absent: result is memoized (second call same as first)', false === Homeboy::is_available() );

// ── Branch 3: synthetic homeboy on PATH → detected ─────────────
$fake_bin = $tmp_root . '/homeboy';
file_put_contents( $fake_bin, "#!/bin/sh\nexit 0\n" );
chmod( $fake_bin, 0755 );

putenv( 'PATH=' . $tmp_root );
Homeboy::reset_cache();
$assert( 'synthetic on PATH: is_available() returns true', true === Homeboy::is_available() );

// Removing the binary mid-request should NOT flip the result —
// detection is memoized exactly once.
unlink( $fake_bin );
$assert( 'memoization: result sticky after PATH change', true === Homeboy::is_available() );

// reset_cache() lets tests re-probe.
Homeboy::reset_cache();
$assert( 'reset_cache: re-probe sees current state', false === Homeboy::is_available() );

// ── Cleanup ────────────────────────────────────────────────────
@rmdir( $tmp_root );
putenv( 'PATH=' . $original_path );

if ( ! empty( $failures ) ) {
	echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
	foreach ( $failures as $f ) {
		echo "  - {$f}\n";
	}
	exit( 1 );
}

echo "\nOK\n";
exit( 0 );
