<?php

/**
 * Smoke test for WorktreeInventoryRepository::pruneMissing().
 *
 * Covers:
 *   - dry-run returns candidates without deleting
 *   - re-probe guard skips rows whose path is present on disk
 *   - unpushed_count > 0 and non-empty pr_url are skipped unless force is set
 *   - a real run deletes only confirmed-absent, unprotected rows
 *
 * Standalone: no WordPress, no PHPUnit. Uses an in-memory $wpdb stub whose
 * get_results() honors the `WHERE missing_path = 1` filter so the production
 * SQL path is exercised faithfully.
 *
 * @package DataMachineCode\Storage
 */

declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Support/JsonCodec.php';
require_once dirname(__DIR__) . '/inc/Storage/WorktreeInventoryRepository.php';

use DataMachineCode\Storage\WorktreeInventoryRepository;

/**
 * In-memory $wpdb stub.
 *
 * get_results() filters rows by the missing_path = 1 predicate when present,
 * mirroring the production SQL query path.
 */
final class Prune_Test_Wpdb {
	public string $prefix = 'wp_';

	/** @var array<string,array<string,mixed>> handle => row */
	public array $rows = array();

	public function get_charset_collate(): string {
		return '';
	}

	public function get_results( string $sql, string $output = ARRAY_A ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( str_contains($sql, 'missing_path = 1') && empty($row['missing_path']) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	public function delete( string $table, array $where ): int|false {
		$handle = (string) ( $where['handle'] ?? '' );
		if ( ! isset($this->rows[ $handle ]) ) {
			return false;
		}
		unset($this->rows[ $handle ]);
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace('/%s/', addslashes((string) $arg), $query, 1) ?? $query;
		}
		return $query;
	}
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate('Y-m-d H:i:s');
}

/**
 * Build an inventory row with sensible defaults.
 *
 * @return array<string,mixed>
 */
function make_row( array $overrides = array() ): array {
	return array_merge(
		array(
			'id'              => 0,
			'handle'          => '',
			'repo'            => '',
			'branch'          => null,
			'path'            => '',
			'primary_path'    => null,
			'is_primary'      => 0,
			'lifecycle_state' => null,
			'pr_url'          => null,
			'unpushed_count'  => null,
			'dirty_count'     => null,
			'missing_path'    => 1,
			'last_probe_at'   => null,
			'last_probe_status' => 'missing_path',
			'metadata'        => null,
		),
		$overrides
	);
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true))
		);
	}
}

$passed = 0;

/**
 * Seed the stub from a list of row definitions.
 *
 * @param Prune_Test_Wpdb                                   $wpdb
 * @param array<int,array<string,mixed>> $rows
 */
function seed( Prune_Test_Wpdb $wpdb, array $rows ): void {
	$wpdb->rows = array();
	foreach ( $rows as $row ) {
		$handle = (string) ( $row['handle'] ?? '' );
		if ( '' === $handle ) {
			throw new RuntimeException('seed row missing handle');
		}
		$wpdb->rows[ $handle ] = $row;
	}
}

$tests = array();

/*
 * Test 1: dry-run returns candidates without deleting anything.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	// Two ghost rows: paths point at nonexistent temp locations.
	$ghost_a = sys_get_temp_dir() . '/dmc-prune-smoke-absent-a-' . getmypid();
	$ghost_b = sys_get_temp_dir() . '/dmc-prune-smoke-absent-b-' . getmypid();
	@unlink($ghost_a);
	@unlink($ghost_b);
	assert_true(! is_dir($ghost_a), 'fixture ghost_a path must be absent');
	assert_true(! is_dir($ghost_b), 'fixture ghost_b path must be absent');

	seed($wpdb, array(
		make_row(array( 'handle' => 'homeboy', 'repo' => 'homeboy', 'path' => $ghost_a )),
		make_row(array( 'handle' => 'homeboy-action', 'repo' => 'homeboy-action', 'path' => $ghost_b )),
		make_row(array( 'handle' => 'data-machine', 'repo' => 'data-machine', 'path' => $ghost_a, 'missing_path' => 0 )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array( 'dry_run' => true ));

	assert_true(! empty($result['success']), 'dry-run returns success');
	assert_true(! empty($result['dry_run']), 'dry-run result flags dry_run');
	assert_same(2, $result['summary']['deleted'], 'dry-run reports 2 would-delete candidates');
	// Rows must still be present (no mutation on dry-run).
	assert_true(isset($wpdb->rows['homeboy']), 'dry-run did not delete homeboy');
	assert_true(isset($wpdb->rows['homeboy-action']), 'dry-run did not delete homeboy-action');
	++$GLOBALS['passed'];
};

/*
 * Test 2: re-probe guard skips rows whose path is present on disk
 * (stale missing_path flag must not be trusted).
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	// A real directory on disk — flagged missing_path=1 but actually present.
	$present = sys_get_temp_dir() . '/dmc-prune-smoke-present-' . getmypid();
	@mkdir($present, 0777, true);
	assert_true(is_dir($present), 'fixture present path must exist');

	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-c-' . getmypid();
	assert_true(! is_dir($absent), 'fixture absent path must be missing');

	seed($wpdb, array(
		make_row(array( 'handle' => 'stale-flag', 'repo' => 'r', 'path' => $present )),
		make_row(array( 'handle' => 'real-ghost', 'repo' => 'r', 'path' => $absent )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array());

	assert_same(1, $result['summary']['deleted'], 'only the truly-absent row is deleted');
	assert_same(1, $result['summary']['skipped'], 'the present-on-disk row is skipped');

	$skipped_handles = array_map(fn( $s ) => $s['handle'], $result['skipped']);
	assert_true(in_array('stale-flag', $skipped_handles, true), 'stale-flag skipped');
	assert_true(! isset($wpdb->rows['real-ghost']), 'real-ghost deleted from store');
	assert_true(isset($wpdb->rows['stale-flag']), 'stale-flag preserved in store');

	rmdir($present);
	++$GLOBALS['passed'];
};

/*
 * Test 3: unpushed_count > 0 and non-empty pr_url are skipped without --force.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-d-' . getmypid();
	assert_true(! is_dir($absent), 'fixture absent path must be missing');

	seed($wpdb, array(
		make_row(array( 'handle' => 'unpushed', 'path' => $absent, 'unpushed_count' => 3 )),
		make_row(array( 'handle' => 'has-pr', 'path' => $absent, 'pr_url' => 'https://github.com/o/r/pull/1' )),
		make_row(array( 'handle' => 'clean-ghost', 'path' => $absent )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array());

	assert_same(1, $result['summary']['deleted'], 'only the clean ghost is deleted');
	assert_same(2, $result['summary']['skipped'], 'unpushed + PR rows are skipped');

	$reasons = array();
	foreach ( $result['skipped'] as $skip ) {
		$reasons[ $skip['handle'] ] = $skip['reason'];
	}
	assert_same('unpushed_count', $reasons['unpushed'] ?? '', 'unpushed row skipped for unpushed_count');
	assert_same('pr_url', $reasons['has-pr'] ?? '', 'PR row skipped for pr_url');
	assert_true(! isset($wpdb->rows['clean-ghost']), 'clean ghost deleted');
	assert_true(isset($wpdb->rows['unpushed']), 'unpushed row preserved');
	assert_true(isset($wpdb->rows['has-pr']), 'PR row preserved');
	++$GLOBALS['passed'];
};

/*
 * Test 4: --force overrides the unpushed_count / pr_url guards.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-e-' . getmypid();
	assert_true(! is_dir($absent), 'fixture absent path must be missing');

	seed($wpdb, array(
		make_row(array( 'handle' => 'unpushed', 'path' => $absent, 'unpushed_count' => 3 )),
		make_row(array( 'handle' => 'has-pr', 'path' => $absent, 'pr_url' => 'https://github.com/o/r/pull/2' )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array( 'force' => true ));

	assert_same(2, $result['summary']['deleted'], 'force deletes the protected rows');
	assert_same(0, $result['summary']['skipped'], 'force leaves nothing skipped');
	assert_true(! isset($wpdb->rows['unpushed']), 'unpushed row deleted under force');
	assert_true(! isset($wpdb->rows['has-pr']), 'PR row deleted under force');
	++$GLOBALS['passed'];
};

/*
 * Test 5: rows flagged missing_path=0 are never even considered.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-f-' . getmypid();
	assert_true(! is_dir($absent), 'fixture absent path must be missing');

	seed($wpdb, array(
		// Present-on-disk row, not flagged missing — must be invisible to prune.
		make_row(array( 'handle' => 'healthy', 'path' => $absent, 'missing_path' => 0 )),
		make_row(array( 'handle' => 'ghost', 'path' => $absent, 'missing_path' => 1 )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array());

	assert_same(1, $result['summary']['total'], 'only missing_path=1 rows are candidates');
	assert_same(1, $result['summary']['deleted'], 'only the ghost is deleted');
	assert_true(isset($wpdb->rows['healthy']), 'healthy row untouched');
	assert_true(! isset($wpdb->rows['ghost']), 'ghost row deleted');
	++$GLOBALS['passed'];
};

$GLOBALS['passed'] = 0;
foreach ( $tests as $i => $test ) {
	try {
		$test();
	} catch ( Throwable $e ) {
		fwrite(STDERR, sprintf("Test %d FAILED: %s\n", $i + 1, $e->getMessage()));
		exit(1);
	}
}

printf("worktree-inventory-prune-missing OK — %d/%d passed\n", $GLOBALS['passed'], count($tests));
