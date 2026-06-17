<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Support/GitHubRemote.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';

use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Workspace\WorktreeContextInjector;

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
	}
}

$public = GitHubRemote::descriptor('https://github.com/Extra-Chill/data-machine-code.git');
assert_same('github.com', $public['host'] ?? null, 'Public GitHub host should parse.');
assert_same('Extra-Chill/data-machine-code', $public['slug'] ?? null, 'Public GitHub slug should parse.');
assert_same('https://github.com/Extra-Chill/data-machine-code.git', $public['https_clone_url'] ?? null, 'Public GitHub HTTPS clone URL should render.');
assert_same('git@github.com:Extra-Chill/data-machine-code.git', $public['ssh_clone_url'] ?? null, 'Public GitHub SSH clone URL should render.');
assert_same('https://api.github.com/repos/Extra-Chill/data-machine-code/pulls/1', GitHubRemote::apiUrl('Extra-Chill/data-machine-code', 'pulls/1'), 'Public GitHub repo API URL should render from a slug.');
assert_same('https://github.com/Extra-Chill/data-machine-code/tree/refactor%2Fdescriptor', GitHubRemote::branchUrl('git@github.com:Extra-Chill/data-machine-code.git', 'refactor/descriptor'), 'Public GitHub branch URL should render from SSH remote.');

$enterprise = GitHubRemote::descriptor('git@github.a8c.com:Automattic/data-machine-code.git');
assert_same('github.a8c.com', $enterprise['host'] ?? null, 'GitHub Enterprise host should parse.');
assert_same('Automattic/data-machine-code', $enterprise['slug'] ?? null, 'GitHub Enterprise slug should parse.');
assert_same('https://github.a8c.com/api/v3', $enterprise['api_base_url'] ?? null, 'GitHub Enterprise API base URL should render.');
assert_same('https://github.a8c.com/Automattic/data-machine-code.git', $enterprise['https_clone_url'] ?? null, 'GitHub Enterprise HTTPS clone URL should render.');
assert_same('https://github.a8c.com/api/v3/repos/Automattic/data-machine-code/issues', GitHubRemote::apiUrl('https://github.a8c.com/Automattic/data-machine-code', 'issues'), 'GitHub Enterprise repo API URL should render from web URL.');
assert_same('https://github.a8c.com/Automattic/data-machine-code/tree/feature%2Fbranch', GitHubRemote::branchUrl('git@github.a8c.com:Automattic/data-machine-code.git', 'feature/branch'), 'GitHub Enterprise branch URL should render from SSH remote.');

$pr_metadata = WorktreeContextInjector::parse_pr_reference('https://github.a8c.com/Automattic/data-machine-code/pull/42');
assert_same('https://github.a8c.com/Automattic/data-machine-code/pull/42', $pr_metadata['pr_url'] ?? null, 'GitHub Enterprise PR URL should round-trip.');
assert_same(42, $pr_metadata['pr_number'] ?? null, 'GitHub Enterprise PR number should parse.');
assert_same('Automattic/data-machine-code', $pr_metadata['pr_repo'] ?? null, 'GitHub Enterprise PR repo should parse.');

assert_same(null, GitHubRemote::descriptor('https://gitlab.com/example/project.git'), 'Non-GitHub hosts should not parse.');

echo "GitHubRemote descriptor tests passed.\n";
