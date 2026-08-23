<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/dmc-broken-orphan-inventory-' . getmypid();
define('DATAMACHINE_WORKSPACE_PATH', $root);
define('ABSPATH', __DIR__ . '/fixtures/');

$GLOBALS['broken_orphan_metadata'] = array();

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['broken_orphan_metadata'] ?: $default;
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value;
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct(
			private string $code,
			private string $message = '',
			private mixed $data = null
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeContextInjector;

function broken_orphan_inventory_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$primary = $root . '/example';
$worktree = $root . '/example@fix-broken-orphan';
$target = $primary . '/.git/worktrees/fix-broken-orphan';
mkdir($primary . '/.git/worktrees', 0777, true);
mkdir($worktree, 0777, true);
file_put_contents($worktree . '/.git', 'gitdir: ' . $target);
file_put_contents($worktree . '/payload.bin', str_repeat('x', 4096));
$GLOBALS['broken_orphan_metadata'] = array(
	'example@fix-broken-orphan' => array(
		'branch'              => 'fix/broken-orphan',
		'lifecycle_state'     => WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
		'cleanup_eligible_at' => gmdate('c', time() - 172800),
		'created_at'          => gmdate('c', time() - 172800),
	),
);

$report = ( new Workspace() )->worktree_cleanup_merged(array( 'dry_run' => true, 'inventory_only' => true ));
broken_orphan_inventory_assert_same(true, is_array($report), 'inventory-only cleanup must return a report');
broken_orphan_inventory_assert_same(1, count($report['candidates'] ?? array()), 'cleanup-eligible broken orphan must be reported as a candidate');
$candidate = $report['candidates'][0];
broken_orphan_inventory_assert_same('broken_orphan', $candidate['reason_code'] ?? null, 'dry-run candidate must use the stable broken orphan reason');
broken_orphan_inventory_assert_same($target, $candidate['broken_target_path'] ?? null, 'dry-run candidate must report the missing metadata target');
broken_orphan_inventory_assert_same(true, (int) ( $candidate['size_bytes'] ?? 0 ) > 0, 'dry-run candidate must include allocated directory size evidence');
broken_orphan_inventory_assert_same(1, $report['summary']['broken_orphans']['candidates'] ?? null, 'summary must count removable broken orphans');
broken_orphan_inventory_assert_same(0, $report['summary']['broken_orphans']['blocked'] ?? null, 'summary must separate blocked broken orphans');

$GLOBALS['broken_orphan_metadata']['example@fix-broken-orphan']['lifecycle_state'] = WorktreeContextInjector::STATE_ACTIVE;
$blocked = ( new Workspace() )->worktree_cleanup_merged(array( 'dry_run' => true, 'inventory_only' => true ));
broken_orphan_inventory_assert_same(0, count($blocked['candidates'] ?? array()), 'active broken orphan must remain blocked');
broken_orphan_inventory_assert_same(1, $blocked['summary']['broken_orphans']['blocked'] ?? null, 'summary must count blocked broken orphans');

unlink($worktree . '/payload.bin');
unlink($worktree . '/.git');
rmdir($worktree);
rmdir($primary . '/.git/worktrees');
rmdir($primary . '/.git');
rmdir($primary);
rmdir($root);

fwrite(STDOUT, "worktree-broken-orphan-inventory-report: ok\n");
