<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}
final class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceText.php';

use DataMachineCode\Workspace\WorkspaceText;

function workspace_text_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$missing = WorkspaceText::compile_search_pattern('');
workspace_text_assert_same('missing_pattern', $missing->get_error_code(), 'Empty patterns retain their error');
$invalid = WorkspaceText::compile_search_pattern('[');
workspace_text_assert_same('invalid_pattern', $invalid->get_error_code(), 'Invalid patterns retain their error');
workspace_text_assert_same('~foo\\~bar~u', WorkspaceText::compile_search_pattern('foo~bar'), 'Pattern delimiter escaping remains compatible');

workspace_text_assert_same(true, WorkspaceText::path_matches_include('src/file.php', null), 'Missing include accepts every path');
workspace_text_assert_same(true, WorkspaceText::path_matches_include('src/file.php', '*.php'), 'Basename include matching remains supported');
workspace_text_assert_same(false, WorkspaceText::path_matches_include('src/file.php', '*.js'), 'Non-matching includes remain excluded');

$content = "zero\nneedle one\ntwo\nneedle three\nfour";
$matches = WorkspaceText::grep_content($content, 'repo', 'src/file.php', '~needle~u', 1, 1);
workspace_text_assert_same(1, count($matches), 'Search result limit remains enforced');
workspace_text_assert_same(substr(hash('sha256', 'src/file.php:2:needle one'), 0, 16), $matches[0]['match_id'], 'Stable match IDs remain compatible');
workspace_text_assert_same("1: zero\n2: needle one\n3: two", $matches[0]['preview'], 'Search previews remain compatible');
workspace_text_assert_same(array( 'repo' => 'repo', 'path' => 'src/file.php', 'offset' => 1, 'limit' => 3 ), $matches[0]['read_args'], 'Search hydration arguments remain compatible');
workspace_text_assert_same(array( array( 'line' => 1, 'text' => 'zero' ), array( 'line' => 2, 'text' => 'needle one' ), array( 'line' => 3, 'text' => 'two' ) ), $matches[0]['context'], 'Search context remains compatible');

$suggestions = WorkspaceText::build_edit_suggestions("alpha\nlong target value\nbeta\nlong target value\ngamma\nlong target value\ndelta\nlong target value", "short\nlong target value");
workspace_text_assert_same(3, count($suggestions), 'Edit suggestions remain bounded to three');
workspace_text_assert_same(2, $suggestions[0]['line'], 'Edit suggestions retain one-based lines');
workspace_text_assert_same("1: alpha\n2: long target value\n3: beta\n4: long target value", $suggestions[0]['preview'], 'Edit suggestion previews remain compatible');

echo "workspace-text: ok\n";
