<?php
/**
 * Smoke test for read-only workspace context repositories.
 *
 * Run: php tests/smoke-workspace-context-repositories.php
 */

declare( strict_types=1 );

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', sys_get_temp_dir() . '/dmc-workspace-context-repositories-abspath/');
}

if ( ! class_exists('WP_Error') ) {
	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}

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

if ( ! function_exists('is_wp_error') ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists('get_option') ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['dmc_workspace_context_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists('apply_filters') ) {
	function apply_filters( string $tag, mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists('size_format') ) {
	function size_format( int|float $bytes ): string {
		return (string) $bytes . ' B';
	}
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../inc/Workspace/Workspace.php';
require __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';
require __DIR__ . '/../inc/Workspace/WorkspaceReader.php';
require __DIR__ . '/../inc/Workspace/WorkspaceWriter.php';

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorkspaceReader;
use DataMachineCode\Workspace\WorkspaceWriter;

$failures = array();
$total    = 0;
$assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  ok {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  fail {$label}\n";
};

$run = static function ( string $command, string $cwd ): string {
	$output = array();
	$code   = 0;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $output, $code);
	if ( 0 !== $code ) {
		throw new RuntimeException(sprintf("Command failed (%d): %s\n%s", $code, $command, implode("\n", $output)));
	}
	return implode("\n", $output);
};

$write = static function ( string $path, string $content ): void {
	$dir = dirname($path);
	if ( ! is_dir($dir) ) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($path, $content);
};

echo "Workspace context repositories - smoke\n";

$tmp = sys_get_temp_dir() . '/dmc-workspace-context-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
if ( ! defined('DATAMACHINE_WORKSPACE_PATH') ) {
	define('DATAMACHINE_WORKSPACE_PATH', $tmp);
}

$context_repo = $tmp . '/studio';
mkdir($context_repo, 0777, true);
$run('git init -q', $context_repo);
$run('git config user.email tester@example.com', $context_repo);
$run('git config user.name Tester', $context_repo);
$write($context_repo . '/README.md', "Studio contract\n");
$write($context_repo . '/docs/contract.md', "Tool contract\n");
$write($context_repo . '/src/private.php', "private contract\n");
$run('git add README.md docs/contract.md src/private.php && git commit -q -m initial', $context_repo);

$target = $tmp . '/target@feature';
mkdir($target, 0777, true);
$run('git init -q', $target);
$run('git config user.email tester@example.com', $target);
$run('git config user.name Tester', $target);
$write($target . '/README.md', "target\n");
$run('git add README.md && git commit -q -m initial', $target);

$GLOBALS['dmc_workspace_context_options'] = array(
	'datamachine_code_context_repositories' => array(
		array(
			'repo'  => 'Automattic/studio',
			'ref'   => 'trunk',
			'alias' => 'studio',
			'paths' => array( 'README.md', 'docs/**' ),
		),
	),
);

$workspace = new Workspace();
$reader    = new WorkspaceReader($workspace);
$writer    = new WorkspaceWriter($workspace);

$list = $workspace->list_repos(null, 'context');
$assert('workspace_list exposes context repository row', ! is_wp_error($list) && 'studio' === ( $list['repos'][0]['name'] ?? '' ));
$assert('context list row is read-only', ! is_wp_error($list) && false === ( $list['repos'][0]['workspace_policy']['writable'] ?? true ));

$show = $workspace->show_repo('studio');
$assert('workspace_show emits context attestation', ! is_wp_error($show) && true === ( $show['workspace_policy']['read_only'] ?? false ));

$read = $reader->read_file('studio', 'README.md');
$assert('allowed context file can be read', ! is_wp_error($read) && str_contains((string) ( $read['content'] ?? '' ), 'Studio contract'));
$assert('read result includes context policy', ! is_wp_error($read) && false === ( $read['workspace_policy']['writable'] ?? true ));

$blocked_read = $reader->read_file('studio', 'src/private.php');
$assert('disallowed context file is rejected', is_wp_error($blocked_read) && 'context_repository_path_not_allowed' === $blocked_read->get_error_code());

$root_list = $reader->list_directory('studio');
$root_names = ! is_wp_error($root_list) ? array_column($root_list['entries'] ?? array(), 'name') : array();
$assert('context directory listing includes allowed root entry', in_array('docs', $root_names, true) && in_array('README.md', $root_names, true));
$assert('context directory listing hides disallowed root entry', ! in_array('src', $root_names, true));

$grep = $reader->grep('studio', 'contract');
$grep_paths = ! is_wp_error($grep) ? array_column($grep['matches'] ?? array(), 'path') : array();
$assert('context grep searches allowed paths', in_array('README.md', $grep_paths, true) && in_array('docs/contract.md', $grep_paths, true));
$assert('context grep skips disallowed paths', ! in_array('src/private.php', $grep_paths, true));

$write_blocked = $writer->write_file('studio', 'docs/new.md', "nope\n");
$assert('context write is rejected', is_wp_error($write_blocked) && 'context_repository_read_only' === $write_blocked->get_error_code());

$edit_blocked = $writer->edit_file('studio', 'README.md', 'Studio', 'Edited');
$assert('context edit is rejected', is_wp_error($edit_blocked) && 'context_repository_read_only' === $edit_blocked->get_error_code());

$patch_blocked = $writer->apply_patch('studio', "diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-Studio contract\n+Edited contract\n");
$assert('context apply-patch is rejected', is_wp_error($patch_blocked) && 'context_repository_read_only' === $patch_blocked->get_error_code());

$git_add_blocked = $workspace->git_add('studio', array( 'README.md' ), true);
$assert('context git add is rejected', is_wp_error($git_add_blocked) && 'context_repository_read_only' === $git_add_blocked->get_error_code());

$git_commit_blocked = $workspace->git_commit('studio', 'Update context', true);
$assert('context git commit is rejected', is_wp_error($git_commit_blocked) && 'context_repository_read_only' === $git_commit_blocked->get_error_code());

$git_push_blocked = $workspace->git_push('studio', 'origin', 'trunk', true);
$assert('context git push is rejected', is_wp_error($git_push_blocked) && 'context_repository_read_only' === $git_push_blocked->get_error_code());

$target_write = $writer->write_file('target@feature', 'changed.txt', "target remains writable\n");
$assert('target worktree remains writable', ! is_wp_error($target_write) && true === ( $target_write['success'] ?? false ));

if ( ! empty($failures) ) {
	echo "\nFAIL: " . count($failures) . " assertion(s) failed out of {$total}\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure}\n";
	}
	exit(1);
}

echo "\nOK ({$total} assertions)\n";
exit(0);
