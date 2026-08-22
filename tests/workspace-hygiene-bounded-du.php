<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = array()
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
require_once dirname(__DIR__) . '/inc/Support/RuntimeCapabilities.php';
require_once dirname(__DIR__) . '/inc/Support/ProcessRunner.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceHygieneReport.php';

final class WorkspaceHygieneBoundedDuHarness {
	public const HYGIENE_DEFAULT_SIZE_ENTRY_TIMEOUT = 5;
	public const HYGIENE_DEFAULT_SIZE_TOTAL_TIMEOUT = 30;

	use \DataMachineCode\Workspace\WorkspaceHygieneReport {
		directory_size_bytes_best_effort as public probeDirectorySize;
		directory_entry_count_best_effort as public probeEntryCount;
		build_workspace_size_report as public buildSizeReport;
		empty_workspace_size_report as public emptySizeReport;
	}

	private string $workspace_path;

	public function __construct( string $workspace_path = '' ) {
		$this->workspace_path = $workspace_path;
	}

	private function parse_handle( string $handle ): array {
		return array( 'repo' => $handle, 'is_worktree' => false );
	}

	private function classify_workspace_entry_kind(): string {
		return 'primary';
	}

	private function format_bytes( int $bytes ): string {
		return (string) $bytes;
	}

	private function workspace_size_by_kind(): array {
		return array();
	}
}

function workspace_hygiene_du_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

function workspace_hygiene_du_assert_less_than( float $expected, float $actual, string $message ): void {
	if ( $actual >= $expected ) {
		throw new RuntimeException(sprintf('%s Expected less than %.2f, got %.2f.', $message, $expected, $actual));
	}
}

$root = sys_get_temp_dir() . '/dmc-hygiene-du-' . bin2hex(random_bytes(6));
$bin  = $root . '/bin';
$data = $root . '/data';
mkdir($bin, 0700, true);
mkdir($data, 0700, true);
mkdir($data . '/a', 0700, true);
mkdir($data . '/b', 0700, true);
$du = $bin . '/du';
$original_path = (string) getenv('PATH');
putenv('PATH=' . $bin . PATH_SEPARATOR . $original_path);

try {
	$normal_recovery = WorkspaceHygieneBoundedDuHarness::workspace_hygiene_recovery_suggestion(array( 'status' => 'ok' ));
	workspace_hygiene_du_assert_same(array(), $normal_recovery['commands'], 'Normal capacity must not suggest recovery commands.');
	$unknown_recovery = WorkspaceHygieneBoundedDuHarness::workspace_hygiene_recovery_suggestion(array( 'status' => 'warning' ));
	workspace_hygiene_du_assert_same(array( 'cleanup' => 'unknown', 'stale_locks' => 'unknown' ), $unknown_recovery['lanes'], 'Omitted lane evidence must remain unknown.');
	workspace_hygiene_du_assert_same(1, count($unknown_recovery['commands']), 'Capacity-only recovery must only suggest bounded sizing.');
	workspace_hygiene_du_assert_same('wp datamachine-code workspace hygiene --format=json', $unknown_recovery['detail_command'], 'Capacity-only recovery must offer hygiene as the generic next step.');
	$clear_recovery = WorkspaceHygieneBoundedDuHarness::workspace_hygiene_recovery_suggestion(array( 'status' => 'refused' ), array( 'cleanup' => array( 'count' => 0 ), 'stale_locks' => array( 'count' => 0 ) ));
	workspace_hygiene_du_assert_same(array( 'cleanup' => 'clear', 'stale_locks' => 'clear' ), $clear_recovery['lanes'], 'Observed zero lane counts must remain distinct from unknown.');
	workspace_hygiene_du_assert_same(1, count($clear_recovery['commands']), 'Clear observed lanes must not suggest lane-specific recovery.');
	$observed_recovery = WorkspaceHygieneBoundedDuHarness::workspace_hygiene_recovery_suggestion(array( 'status' => 'refused' ), array( 'cleanup' => array( 'count' => 1 ), 'stale_locks' => array( 'count' => 1 ) ));
	workspace_hygiene_du_assert_same(3, count($observed_recovery['commands']), 'Observed recovery lanes must expose their matching commands.');
	workspace_hygiene_du_assert_same(true, str_contains($observed_recovery['commands'][1]['command'], '--limit=25'), 'Observed cleanup preview must remain bounded.');

	$disabled = ( new WorkspaceHygieneBoundedDuHarness() )->emptySizeReport(100, false);
	workspace_hygiene_du_assert_same(true, array_key_exists('total_bytes', $disabled), 'Disabled size scans must expose byte-total state.');
	workspace_hygiene_du_assert_same(null, $disabled['total_bytes'], 'Disabled size scans must leave byte totals unknown.');
	workspace_hygiene_du_assert_same('not scanned', $disabled['total_human'] ?? null, 'Disabled size scans must report not scanned.');
	workspace_hygiene_du_assert_same(false, $disabled['scan_complete'] ?? null, 'Disabled size scans must not report a complete measurement.');

	file_put_contents($du, "#!/bin/sh\nexec sleep 5\n");
	chmod($du, 0700);

	$started = microtime(true);
	$timeout = ( new WorkspaceHygieneBoundedDuHarness() )->probeDirectorySize($data, 1);
	$elapsed = microtime(true) - $started;
	workspace_hygiene_du_assert_same(false, $timeout['success'] ?? null, 'Hanging du must fail closed.');
	workspace_hygiene_du_assert_same('entry_timeout', $timeout['reason'] ?? null, 'Hanging du must expose a stable timeout reason.');
	workspace_hygiene_du_assert_same(1, $timeout['timeout_seconds'] ?? null, 'Hanging du must report its deadline.');
	workspace_hygiene_du_assert_same(true, is_array($timeout['cleanup'] ?? null), 'Hanging du must preserve process cleanup evidence.');
	workspace_hygiene_du_assert_less_than(3.0, $elapsed, 'Hanging du must not occupy the worker beyond its deadline.');
	$count_timeout = ( new WorkspaceHygieneBoundedDuHarness() )->probeEntryCount($data, 1);
	workspace_hygiene_du_assert_same('entry_timeout', $count_timeout['reason'] ?? null, 'Hanging inode-count probe must remain typed unknown by timeout reason.');

	$started = microtime(true);
	$report  = ( new WorkspaceHygieneBoundedDuHarness($data) )->buildSizeReport(2, 5, 1);
	$elapsed = microtime(true) - $started;
	workspace_hygiene_du_assert_same(1, $report['attempted_entries'] ?? null, 'Whole-pass deadline must stop before starting another probe.');
	workspace_hygiene_du_assert_same(2, $report['timed_out_entries'] ?? null, 'Whole-pass report must account for the timed-out probe and unattempted path.');
	workspace_hygiene_du_assert_same('entry_timeout', $report['skipped_entries'][0]['reason'] ?? null, 'Whole-pass report must retain the probe timeout reason.');
	workspace_hygiene_du_assert_same('total_timeout', $report['skipped_entries'][1]['reason'] ?? null, 'Whole-pass report must classify remaining paths under the total deadline.');
	workspace_hygiene_du_assert_less_than(3.0, $elapsed, 'Whole size pass must honor its total deadline.');

	file_put_contents($du, "#!/bin/sh\nprintf '7\\t%s\\n' \"\$4\"\n");
	chmod($du, 0700);
	$success = ( new WorkspaceHygieneBoundedDuHarness() )->probeDirectorySize($data, 1);
	workspace_hygiene_du_assert_same(true, $success['success'] ?? null, 'Successful du must remain available.');
	workspace_hygiene_du_assert_same(7 * 1024, $success['bytes'] ?? null, 'Successful du must preserve KiB-to-byte conversion.');

	file_put_contents($du, "#!/bin/sh\ncase \"\$1\" in\n  --inodes) case \"\$4\" in */a) printf '90\\t%s\\n' \"\$4\" ;; *) printf '3\\t%s\\n' \"\$4\" ;; esac ;;\n  *) case \"\$3\" in */b) printf '100\\t%s\\n' \"\$3\" ;; *) printf '1\\t%s\\n' \"\$3\" ;; esac ;;\nesac\n");
	chmod($du, 0700);
	$ranked = ( new WorkspaceHygieneBoundedDuHarness($data) )->buildSizeReport(2, 2, 10);
	workspace_hygiene_du_assert_same('complete', $ranked['entry_count_scan']['status'] ?? null, 'Successful bounded count pass must report complete telemetry.');
	workspace_hygiene_du_assert_same('a', $ranked['top_entries_by_count'][0]['handle'] ?? null, 'Entry-count ranking must be independent from byte ranking.');
	workspace_hygiene_du_assert_same('b', $ranked['top_entries'][0]['handle'] ?? null, 'Byte ranking must remain independent from entry-count ranking.');
} finally {
	putenv('PATH=' . $original_path);
	if ( is_file($du) ) {
		unlink($du);
	}
	if ( is_dir($data . '/a') ) {
		rmdir($data . '/a');
	}
	if ( is_dir($data . '/b') ) {
		rmdir($data . '/b');
	}
	if ( is_dir($data) ) {
		rmdir($data);
	}
	if ( is_dir($bin) ) {
		rmdir($bin);
	}
	if ( is_dir($root) ) {
		rmdir($root);
	}
}

echo "workspace-hygiene-bounded-du: ok\n";
