<?php

namespace DataMachine\Core\FilesRepository {
	class FilesystemHelper {
		public static function get() {
			return null;
		}
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	$workspace_root = sys_get_temp_dir() . '/datamachine-code-file-policy-' . getmypid();
	define('DATAMACHINE_WORKSPACE_PATH', $workspace_root);

	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

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

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $default;
	}

	function apply_filters( string $hook_name, mixed $value ): mixed {
		return $value;
	}

	function size_format( int|float $bytes ): string {
		return (string) $bytes . ' B';
	}

	require_once dirname(__DIR__) . '/inc/Support/PathSecurity.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceAliasResolver.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspacePolicy.php';
	require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceReader.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceWriter.php';

	$passed = 0;
	$total  = 0;

	$assert = function ( bool $condition, string $label ) use ( &$passed, &$total ): void {
		++$total;
		if ( $condition ) {
			++$passed;
			return;
		}

		fwrite(STDERR, "FAIL: {$label}\n");
	};

	$remove_tree = function ( string $path ) use ( &$remove_tree ): void {
		if ( ! file_exists($path) ) {
			return;
		}
		if ( is_file($path) || is_link($path) ) {
			unlink($path);
			return;
		}
		foreach ( scandir($path) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$remove_tree($path . '/' . $entry);
		}
		rmdir($path);
	};

	$remove_tree($workspace_root);
	mkdir($workspace_root . '/demo', 0777, true);
	mkdir($workspace_root . '/demo@feature-policy', 0777, true);
	file_put_contents($workspace_root . '/demo/existing.txt', 'hello primary');
	file_put_contents($workspace_root . '/demo@feature-policy/existing.txt', 'hello worktree');

	$workspace = new DataMachineCode\Workspace\Workspace();
	$reader    = new DataMachineCode\Workspace\WorkspaceReader($workspace);
	$writer    = new DataMachineCode\Workspace\WorkspaceWriter($workspace);

	$missing = $workspace->require_explicit_workspace_handle('');
	$assert(is_wp_error($missing) && 'missing_workspace_handle' === $missing->get_error_code(), 'empty handles are rejected centrally');

	$read_root = $reader->read_file('', 'demo/existing.txt');
	$assert(is_wp_error($read_root) && 'missing_workspace_handle' === $read_root->get_error_code(), 'read_file rejects workspace-root handle');

	$write_primary = $writer->write_file('demo', 'new.txt', 'primary');
	$assert(is_wp_error($write_primary) && 'primary_mutation_blocked' === $write_primary->get_error_code(), 'write_file blocks primary mutation by default');
	$assert(! file_exists($workspace_root . '/demo/new.txt'), 'blocked primary write does not create file');

	$edit_primary = $writer->edit_file('demo', 'existing.txt', 'hello', 'goodbye');
	$assert(is_wp_error($edit_primary) && 'primary_mutation_blocked' === $edit_primary->get_error_code(), 'edit_file blocks primary mutation by default');
	$assert('hello primary' === file_get_contents($workspace_root . '/demo/existing.txt'), 'blocked primary edit leaves file unchanged');

	$patch_primary = $writer->apply_patch('demo', "diff --git a/new.txt b/new.txt\nnew file mode 100644\n--- /dev/null\n+++ b/new.txt\n@@ -0,0 +1 @@\n+patched\n");
	$assert(is_wp_error($patch_primary) && 'primary_mutation_blocked' === $patch_primary->get_error_code(), 'apply_patch uses the shared primary mutation preflight');

	$write_worktree = $writer->write_file('demo@feature-policy', 'new.txt', 'worktree');
	$assert(is_array($write_worktree) && ! empty($write_worktree['success']), 'write_file allows worktree mutation');
	$assert('worktree' === file_get_contents($workspace_root . '/demo@feature-policy/new.txt'), 'worktree write lands in the worktree handle');

	$large_lines = array();
	for ( $i = 1; $i <= 5000; ++$i ) {
		$large_lines[] = 'line-' . $i;
	}
	file_put_contents($workspace_root . '/demo@feature-policy/large.txt', implode("\n", $large_lines));
	$large_slice = $reader->read_file('demo@feature-policy', 'large.txt', 1024 * 1024, 4999, 2);
	$assert(is_array($large_slice) && "line-4999\nline-5000" === $large_slice['content'], 'bounded read streams the requested line slice');
	$assert(is_array($large_slice) && 2 === $large_slice['lines_read'] && 4999 === $large_slice['offset'], 'bounded read reports slice metadata');

	$write_primary_allowed = $writer->write_file('demo', 'allowed.txt', 'allowed', true);
	$assert(is_array($write_primary_allowed) && ! empty($write_primary_allowed['success']), 'write_file honors explicit primary mutation override');

	$remove_tree($workspace_root);

	fprintf(STDOUT, "%d/%d passed\n", $passed, $total);
	exit($passed === $total ? 0 : 1);
}
