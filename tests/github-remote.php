<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function get_option( string $name, mixed $default = false ): mixed {
	if ( 'github_credential_profiles' === $name ) {
		return array(
			array(
				'id'           => 'enterprise',
				'default_repo' => 'ssh://git@enterprise.example.test:2222/owner/repository.git',
			),
		);
	}
	return $default;
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

$enterprise = GitHubRemote::descriptor('git@enterprise.example.test:owner/repository.git');
assert_same('enterprise.example.test', $enterprise['host'] ?? null, 'Configured GitHub Enterprise profile host should parse.');
assert_same('owner/repository', $enterprise['slug'] ?? null, 'GitHub Enterprise slug should parse.');
assert_same('https://enterprise.example.test/api/v3', $enterprise['api_base_url'] ?? null, 'GitHub Enterprise API base URL should render.');
assert_same('https://enterprise.example.test/owner/repository.git', $enterprise['https_clone_url'] ?? null, 'GitHub Enterprise HTTPS clone URL should render.');
assert_same('https://enterprise.example.test/api/v3/repos/owner/repository/issues', GitHubRemote::apiUrl('https://enterprise.example.test/owner/repository', 'issues'), 'GitHub Enterprise repo API URL should render from web URL.');
assert_same('https://enterprise.example.test/owner/repository/tree/feature%2Fbranch', GitHubRemote::branchUrl('git@enterprise.example.test:owner/repository.git', 'feature/branch'), 'GitHub Enterprise branch URL should render from SSH remote.');

$custom_port = GitHubRemote::descriptor('ssh://git@enterprise.example.test:2222/owner/repository.git');
assert_same('enterprise.example.test', $custom_port['host'] ?? null, 'Configured GitHub Enterprise hosts should parse without hostname heuristics.');
assert_same(2222, $custom_port['ssh_port'] ?? null, 'SSH URI ports should parse.');
assert_same('https://enterprise.example.test/owner/repository.git', $custom_port['https_clone_url'] ?? null, 'HTTPS alternatives must not inherit SSH ports.');
assert_same('ssh://git@enterprise.example.test:2222/owner/repository.git', $custom_port['ssh_clone_url'] ?? null, 'SSH URI ports should round-trip.');
assert_same('enterprise.example.test', GitHubRemote::descriptor('git@enterprise.example.test:owner/repository.git')['host'] ?? null, 'SCP-style configured GitHub Enterprise remotes should parse.');

$pr_metadata = WorktreeContextInjector::parse_pr_reference('https://enterprise.example.test/owner/repository/pull/42');
assert_same('https://enterprise.example.test/owner/repository/pull/42', $pr_metadata['pr_url'] ?? null, 'GitHub Enterprise PR URL should round-trip.');
assert_same(42, $pr_metadata['pr_number'] ?? null, 'GitHub Enterprise PR number should parse.');
assert_same('owner/repository', $pr_metadata['pr_repo'] ?? null, 'GitHub Enterprise PR repo should parse.');

assert_same(null, GitHubRemote::descriptor('https://gitlab.com/example/project.git'), 'Non-GitHub hosts should not parse.');
assert_same(null, GitHubRemote::descriptor('git@ssh.example.test:owner/repository.git'), 'Arbitrary SSH remotes must not be classified as GitHub.');

echo "GitHubRemote descriptor tests passed.\n";
