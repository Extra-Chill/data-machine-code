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
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeCleanupClassifier.php';
require_once dirname(__DIR__) . '/inc/Storage/WorktreeInventoryRepository.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHygieneReport.php';

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

	/** @var callable|null */
	public $before_query = null;

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
		usort($out, static fn( array $a, array $b ): int => strcmp((string) $a['handle'], (string) $b['handle']));
		if ( preg_match("/handle > '([^']*)'/", $sql, $matches) ) {
			$out = array_values(array_filter($out, static fn( array $row ): bool => strcmp((string) $row['handle'], stripslashes($matches[1])) > 0));
		}
		if ( preg_match('/LIMIT (\d+)/', $sql, $matches) ) {
			return array_slice($out, 0, (int) $matches[1]);
		}
		return $out;
	}

	public function query( string $sql ): int|false {
		if ( is_callable($this->before_query) ) {
			( $this->before_query )($this, $sql);
		}
		if ( ! preg_match("/handle = '([^']*)' AND path = '([^']*)'/", $sql, $matches) ) {
			return false;
		}
		$handle = stripslashes($matches[1]);
		$path   = stripslashes($matches[2]);
		if ( ! isset($this->rows[ $handle ]) || $path !== (string) $this->rows[ $handle ]['path'] ) {
			return 0;
		}
		$row = $this->rows[ $handle ];
		foreach ( array( 'origin_site', 'origin_agent', 'origin_session', 'owner_run_ref', 'cleanup_policy', 'task_url', 'task_ref' ) as $field ) {
			if ( '' !== trim((string) ( $row[ $field ] ?? '' )) && str_contains($sql, "TRIM(COALESCE({$field}, '')) = ''") ) {
				return 0;
			}
		}
		if ( str_contains($sql, "TRIM(COALESCE(pr_url, '')) = ''") && '' !== trim((string) ( $row['pr_url'] ?? '' )) ) {
			return 0;
		}
		if ( str_contains($sql, 'COALESCE(unpushed_count, 0) <= 0') && (int) ( $row['unpushed_count'] ?? 0 ) > 0 ) {
			return 0;
		}
		if ( str_contains($sql, 'COALESCE(dirty_count, 0) <= 0') && (int) ( $row['dirty_count'] ?? 0 ) > 0 ) {
			return 0;
		}
		unset($this->rows[ $handle ]);
		return 1;
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
			$query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
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
	$result = $repo->pruneMissing(array( 'dry_run' => true, 'workspace_root' => sys_get_temp_dir() ));

	assert_true(! empty($result['success']), 'dry-run returns success');
	assert_true(! empty($result['dry_run']), 'dry-run result flags dry_run');
	assert_same(3, $result['summary']['deleted'], 'dry-run discovers flagged and physically absent candidates');
	// Rows must still be present (no mutation on dry-run).
	assert_true(isset($wpdb->rows['homeboy']), 'dry-run did not delete homeboy');
	assert_true(isset($wpdb->rows['homeboy-action']), 'dry-run did not delete homeboy-action');
	++$GLOBALS['passed'];
};

/*
 * Test 6: bounded pages retain a continuation and only mutate the current page.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-g-' . getmypid();
	seed($wpdb, array(
		make_row(array( 'handle' => 'a', 'path' => $absent )),
		make_row(array( 'handle' => 'b', 'path' => $absent, 'pr_url' => 'https://example.test/pr/1' )),
		make_row(array( 'handle' => 'c', 'path' => $absent )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$result = ( new WorktreeInventoryRepository() )->pruneMissing(array( 'limit' => 2, 'workspace_root' => sys_get_temp_dir() ));
	assert_same(1, $result['summary']['deleted'], 'bounded page deletes only its configured limit after a protected row');
	assert_same(1, $result['summary']['skipped'], 'bounded page retains protected rows for later reconciliation');
	assert_same('b', $result['continuation']['next_after_handle'] ?? null, 'bounded page reports its keyset continuation cursor');
	assert_true(isset($wpdb->rows['b']) && isset($wpdb->rows['c']), 'protected and later rows remain for continuation');
	$result = ( new WorktreeInventoryRepository() )->pruneMissing(array( 'after_handle' => 'b', 'workspace_root' => sys_get_temp_dir() ));
	assert_same(1, $result['summary']['deleted'], 'keyset continuation processes only rows after its cursor');
	assert_true(isset($wpdb->rows['b']) && ! isset($wpdb->rows['c']), 'keyset continuation retains the earlier protected row and deletes the survivor');
	++$GLOBALS['passed'];
};

/*
 * Test 7: malformed paths remain protected, but ownership does not veto an absent path.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-h-' . getmypid();
	seed($wpdb, array(
		make_row(array( 'handle' => 'relative-path', 'path' => 'github://owner/repo' )),
		make_row(array( 'handle' => 'owner-managed', 'path' => $absent, 'origin_site' => 'remote-site' )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$result  = ( new WorktreeInventoryRepository() )->pruneMissing(array( 'workspace_root' => sys_get_temp_dir() ));
	$reasons = array_column($result['skipped'], 'reason', 'handle');
	assert_same('invalid_path', $reasons['relative-path'] ?? null, 'malformed remote path is preserved');
	assert_same(null, $reasons['owner-managed'] ?? null, 'owner-managed absent row is not skipped');
	assert_true(isset($wpdb->rows['relative-path']) && ! isset($wpdb->rows['owner-managed']), 'only the malformed row remains in inventory');
	++$GLOBALS['passed'];
};

/*
 * Test 8: final SQL conditions preserve evidence added after the locked read.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-i-' . getmypid();
	seed($wpdb, array( make_row(array( 'handle' => 'updated-pr', 'path' => $absent )) ));
	$wpdb->before_query = static function ( Prune_Test_Wpdb $database ): void {
		$database->rows['updated-pr']['pr_url'] = 'https://example.test/pr/2';
	};
	$GLOBALS['wpdb'] = $wpdb;

	$result = ( new WorktreeInventoryRepository() )->pruneMissing(array( 'workspace_root' => sys_get_temp_dir() ));
	assert_same(0, $result['summary']['deleted'], 'final SQL predicate preserves a row that gains PR evidence');
	assert_same('conditional_delete_mismatch', $result['skipped'][0]['reason'] ?? null, 'concurrent protection update reports a conditional delete mismatch');
	assert_true(isset($wpdb->rows['updated-pr']), 'concurrently protected row remains in inventory');
	++$GLOBALS['passed'];
};

/*
 * Test 9: the mutation callback recreates the path before the locked final probe.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$path = sys_get_temp_dir() . '/dmc-prune-smoke-race-' . getmypid();
	@rmdir($path);
	seed($wpdb, array( make_row(array( 'handle' => 'recreated', 'path' => $path )) ));
	$GLOBALS['wpdb'] = $wpdb;

	$result = ( new WorktreeInventoryRepository() )->pruneMissing(array(
		'workspace_root' => sys_get_temp_dir(),
		'lock_callback' => static function ( array $row, callable $mutation ) use ( $path ): array {
			mkdir($path, 0777, true);
			return $mutation();
		},
	));
	assert_same(0, $result['summary']['deleted'], 'final locked path probe prevents deletion after recreation');
	assert_same('path_present_on_disk', $result['skipped'][0]['reason'] ?? null, 'recreated path reports final revalidation skip');
	assert_true(isset($wpdb->rows['recreated']), 'recreated row remains in inventory');
	rmdir($path);
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
	$result = $repo->pruneMissing(array( 'workspace_root' => sys_get_temp_dir() ));

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
		make_row(array( 'handle' => 'dirty', 'path' => $absent, 'dirty_count' => 2 )),
		make_row(array( 'handle' => 'clean-ghost', 'path' => $absent )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array( 'workspace_root' => sys_get_temp_dir() ));

	assert_same(1, $result['summary']['deleted'], 'only the clean ghost is deleted');
	assert_same(3, $result['summary']['skipped'], 'dirty, unpushed, and PR rows are skipped');

	$reasons = array();
	foreach ( $result['skipped'] as $skip ) {
		$reasons[ $skip['handle'] ] = $skip['reason'];
	}
	assert_same('unpushed_count', $reasons['unpushed'] ?? '', 'unpushed row skipped for unpushed_count');
	assert_same('pr_url', $reasons['has-pr'] ?? '', 'PR row skipped for pr_url');
	assert_same('dirty_count', $reasons['dirty'] ?? '', 'dirty row skipped for dirty_count');
	assert_true(! isset($wpdb->rows['clean-ghost']), 'clean ghost deleted');
	assert_true(isset($wpdb->rows['unpushed']), 'unpushed row preserved');
	assert_true(isset($wpdb->rows['has-pr']), 'PR row preserved');
	assert_true(isset($wpdb->rows['dirty']), 'dirty row preserved');
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
		make_row(array( 'handle' => 'dirty', 'path' => $absent, 'dirty_count' => 2 )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array( 'force' => true, 'workspace_root' => sys_get_temp_dir() ));

	assert_same(3, $result['summary']['deleted'], 'force deletes the protected rows');
	assert_same(0, $result['summary']['skipped'], 'force leaves nothing skipped');
	assert_true(! isset($wpdb->rows['unpushed']), 'unpushed row deleted under force');
	assert_true(! isset($wpdb->rows['has-pr']), 'PR row deleted under force');
	assert_true(! isset($wpdb->rows['dirty']), 'dirty row deleted under force');
	++$GLOBALS['passed'];
};

/*
 * Test 5: physical absence is authoritative without a missing_path flag.
 */
$tests[] = static function (): void {
	$wpdb = new Prune_Test_Wpdb();
	$absent = sys_get_temp_dir() . '/dmc-prune-smoke-absent-f-' . getmypid();
	assert_true(! is_dir($absent), 'fixture absent path must be missing');

	seed($wpdb, array(
		// Physically absent row, not flagged missing, must still be discoverable.
		make_row(array( 'handle' => 'unflagged-ghost', 'path' => $absent, 'missing_path' => 0 )),
		make_row(array( 'handle' => 'ghost', 'path' => $absent, 'missing_path' => 1 )),
	));
	$GLOBALS['wpdb'] = $wpdb;

	$repo   = new WorktreeInventoryRepository();
	$result = $repo->pruneMissing(array( 'workspace_root' => sys_get_temp_dir() ));

	assert_same(2, $result['summary']['total'], 'physical absence is detected without a prior missing_path flag');
	assert_same(2, $result['summary']['deleted'], 'flagged and unflagged ghosts are deleted');
	assert_true(! isset($wpdb->rows['unflagged-ghost']), 'unflagged ghost is pruned');
	assert_true(! isset($wpdb->rows['ghost']), 'ghost row deleted');
	++$GLOBALS['passed'];
};

/*
 * Test 10: hygiene and prune-missing project the same physical-path candidates.
 */
$tests[] = static function (): void {
	$wpdb   = new Prune_Test_Wpdb();
	$root   = sys_get_temp_dir();
	$absent = $root . '/dmc-prune-shared-classifier-' . getmypid();
	$present = $root . '/dmc-prune-shared-present-' . getmypid();
	@mkdir($present, 0777, true);
	$rows = array(
		make_row(array( 'handle' => 'owner-ghost', 'repo' => 'repo', 'path' => $absent, 'missing_path' => 0, 'origin_site' => 'managed-site' )),
		make_row(array( 'handle' => 'protected-ghost', 'repo' => 'repo', 'path' => $absent, 'dirty_count' => 1 )),
		make_row(array( 'handle' => 'present', 'repo' => 'repo', 'path' => $present, 'missing_path' => 0 )),
	);
	seed($wpdb, $rows);
	$GLOBALS['wpdb'] = $wpdb;

	$prune = ( new WorktreeInventoryRepository() )->pruneMissing(array( 'dry_run' => true, 'workspace_root' => $root ));
	$hygiene = new class($root) {
		use DataMachineCode\Workspace\WorkspaceHygieneReport;

		private string $workspace_path;

		public function __construct( string $workspace_path ) {
			$this->workspace_path = $workspace_path;
		}

		public function inventory_prune_preview( array $rows ): array {
			$method = new ReflectionMethod($this, 'build_inventory_prune_preview');
			return $method->invoke($this, $rows);
		}
	};
	$preview = $hygiene->inventory_prune_preview($rows);

	assert_same(array_column($prune['deleted'], 'handle'), array_column($preview['candidates'], 'handle'), 'hygiene and prune-missing use the same candidate set');
	assert_same(array_column($prune['skipped'], 'reason', 'handle'), array_column($preview['blocked'], 'reason', 'handle'), 'hygiene and prune-missing use the same blockers');
	rmdir($present);
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
