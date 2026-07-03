<?php
/**
 * Smoke coverage for workspace hygiene reporting contracts.
 *
 * Runs without WordPress. It stubs the option API used by lifecycle metadata.
 */

declare(strict_types=1);

$workspace_root = sys_get_temp_dir() . '/dmc-hygiene-reporting-' . getmypid();
mkdir($workspace_root, 0777, true);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', dirname(__DIR__) . '/');
}
if ( ! defined('DATAMACHINE_WORKSPACE_PATH') ) {
	define('DATAMACHINE_WORKSPACE_PATH', $workspace_root);
}

$GLOBALS['datamachine_test_options'] = array();

if ( ! function_exists('get_option') ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['datamachine_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists('update_option') ) {
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['datamachine_test_options'][ $name ] = $value;
		return true;
	}
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeContextInjector;

$tests  = 0;
$passed = 0;

$assert = static function ( bool $condition, string $message ) use ( &$tests, &$passed ): void {
	++$tests;
	if ( $condition ) {
		++$passed;
		return;
	}
	fwrite(STDERR, "FAIL: {$message}\n");
};

$invoke = static function ( object $object, string $method, array $args = array() ): mixed {
	$ref = new ReflectionMethod($object, $method);
	return $ref->invokeArgs($object, $args);
};

try {
	$workspace = new Workspace();

	$disabled_size = $invoke($workspace, 'empty_workspace_size_report', array( 100, false ));
	$assert('not_measured' === $disabled_size['mode'], 'disabled size mode reports not_measured');
	$assert(null === $disabled_size['total_bytes'], 'disabled size total_bytes is null, not zero');
	$assert('unknown' === $disabled_size['total_human'], 'disabled size total_human is unknown');
	$assert(false === $disabled_size['measured'], 'disabled size explicitly reports measured=false');

	$warning = $invoke(
		$workspace,
		'build_size_scan_disabled_warning',
		array(
			false,
			array( 'worktrees' => 101 ),
			array(
				'free_bytes'  => 100 * 1073741824,
				'total_bytes' => 200 * 1073741824,
			),
		)
	);
	$assert(str_contains($warning, 'cleanup-artifacts --dry-run --sort=size'), 'disabled size warning points at bounded hotspot command');

	$handle = 'repo@example-branch';
	$path   = $workspace_root . '/' . $handle;
	mkdir($path, 0777, true);
	file_put_contents($path . '/.git', 'gitdir: /tmp/nonexistent');
	WorktreeContextInjector::store_lifecycle_metadata(
		$handle,
		array(
			'repo'                 => 'repo',
			'branch'               => 'example-branch',
			'path'                 => $path,
			'lifecycle_state'      => 'cleanup_eligible',
			'created_at'           => '2026-01-01T00:00:00+00:00',
			'last_cleanup_blocker' => array(
				'reason_code' => 'dirty_worktree',
				'reason'      => 'working tree dirty (2 entries)',
				'observed_at' => '2026-07-03T00:00:00+00:00',
				'source'      => 'workspace_bounded_cleanup_eligible_apply',
			),
		)
	);

	$inventory = $invoke($workspace, 'worktree_cleanup_inventory_only', array( '', 'age', false, null, 0 ));
	$candidate = $inventory['candidates'][0] ?? array();
	$assert($handle === ( $candidate['handle'] ?? '' ), 'cleanup-eligible inventory candidate is reported');
	$assert('dirty_worktree' === ( $candidate['last_cleanup_blocker']['reason_code'] ?? '' ), 'last cleanup blocker is surfaced on cleanup-eligible candidates');
} finally {
	if ( is_dir($workspace_root) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($workspace_root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($workspace_root);
	}
}

if ( $tests !== $passed ) {
	fwrite(STDERR, sprintf("%d/%d passed\n", $passed, $tests));
	exit(1);
}

printf("%d/%d passed\n", $passed, $tests);
