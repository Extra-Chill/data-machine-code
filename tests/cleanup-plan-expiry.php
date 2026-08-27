<?php
/** Unapplied cleanup plans expire on a bounded, age-gated pass. */

declare(strict_types=1);

namespace {
	if (! defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if (! class_exists('WP_Error')) {
		class WP_Error {
			public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): mixed { return $this->data; }
		}
	}

	/** Minimal wpdb capturing the statements the expiry pass issues. */
	final class ExpiryFakeWpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array{sql:string,values:array<int,mixed>}> */
		public array $queries = array();
		/** @var array<int,string> */
		public array $selectable = array();
		public int $deleted_items = 0;
		public int $deleted_runs = 0;

		public function prepare(string $sql, ...$values): array {
			return array('sql' => $sql, 'values' => $values);
		}

		/** @param array{sql:string,values:array<int,mixed>} $prepared */
		public function get_col($prepared): array {
			$this->queries[] = $prepared;
			$limit = (int) end($prepared['values']);
			return array_slice($this->selectable, 0, max(0, $limit));
		}

		/** @param array{sql:string,values:array<int,mixed>} $prepared */
		public function query($prepared): int {
			$this->queries[] = $prepared;
			if (str_contains($prepared['sql'], 'cleanup_items')) {
				$this->deleted_items = count($prepared['values']) * 2;
				return $this->deleted_items;
			}
			$this->deleted_runs = count($prepared['values']);
			return $this->deleted_runs;
		}
	}

	global $wpdb;
	$wpdb = new ExpiryFakeWpdb();

	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupSchema.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepository.php';

	$repository = new DataMachineCode\Storage\CleanupRunRepository();
	$checks = array();

	// Nothing eligible must not issue a delete.
	$wpdb->selectable = array();
	$empty = $repository->expire_runs('planned', '2026-08-20 00:00:00', 100);
	$checks['an empty candidate set deletes nothing'] = array('runs' => 0, 'items' => 0) === $empty
		&& 0 === $wpdb->deleted_runs;

	// Eligible plans delete their items and then the runs themselves.
	$wpdb->queries = array();
	$wpdb->selectable = array('cleanup-run-a', 'cleanup-run-b', 'cleanup-run-c');
	$expired = $repository->expire_runs('planned', '2026-08-20 00:00:00', 100);
	$statements = array_map(static fn(array $q): string => $q['sql'], $wpdb->queries);

	$checks['expiry reports the runs and items it removed'] = 3 === $expired['runs'] && 6 === $expired['items'];
	$checks['candidates are selected by status, age, and bound'] = str_contains($statements[0] ?? '', 'WHERE status = %s AND created_at < %s')
		&& str_contains($statements[0] ?? '', 'ORDER BY created_at ASC LIMIT %d')
		&& array('planned', '2026-08-20 00:00:00', 100) === ($wpdb->queries[0]['values'] ?? array());
	$checks['items are removed before their runs'] = str_contains($statements[1] ?? '', 'cleanup_items')
		&& str_contains($statements[2] ?? '', 'cleanup_runs');
	$checks['deletes are scoped to the selected run ids'] = array('cleanup-run-a', 'cleanup-run-b', 'cleanup-run-c') === ($wpdb->queries[2]['values'] ?? array());

	// The per-pass bound is clamped rather than trusted.
	$wpdb->queries = array();
	$wpdb->selectable = array('cleanup-run-a');
	$repository->expire_runs('planned', '2026-08-20 00:00:00', 100000);
	$checks['an oversized bound is clamped'] = 5000 === (int) end($wpdb->queries[0]['values']);

	$wpdb->queries = array();
	$repository->expire_runs('planned', '2026-08-20 00:00:00', 0);
	$checks['a zero bound is raised to one'] = 1 === (int) end($wpdb->queries[0]['values']);

	$passed = ! in_array(false, $checks, true);
	foreach ($checks as $description => $result) {
		fwrite($passed ? STDOUT : STDERR, sprintf("%s: %s\n", $result ? 'PASS' : 'FAIL', $description));
	}
	exit($passed ? 0 : 1);
}
