<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

final class WP_Error {
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

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function get_option( string $name, mixed $default = false ): mixed {
	return $default;
}

function sanitize_text_field( mixed $value ): string {
	return trim((string) $value);
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode($value, $flags, $depth);
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function datamachine_code_test_response( int $status, array $body ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode($body),
	);
}

$GLOBALS['datamachine_code_github_requests']        = array();
$GLOBALS['datamachine_code_github_fail_blob_index'] = null;
$GLOBALS['datamachine_code_github_blob_count']      = 0;

function wp_remote_get( string $url, array $args = array() ): array {
	$GLOBALS['datamachine_code_github_requests'][] = array(
		'method' => 'GET',
		'url'    => $url,
		'body'   => null,
	);

	if ( str_contains($url, '/repos/owner/repo/git/ref/heads/main') ) {
		return datamachine_code_test_response(200, array( 'object' => array( 'sha' => 'base-commit' ) ));
	}

	if ( str_contains($url, '/repos/owner/repo/git/commits/base-commit') ) {
		return datamachine_code_test_response(200, array( 'tree' => array( 'sha' => 'base-tree' ) ));
	}

	if ( str_ends_with($url, '/repos/owner/repo') ) {
		return datamachine_code_test_response(200, array( 'default_branch' => 'main' ));
	}

	return datamachine_code_test_response(404, array( 'message' => 'not found' ));
}

function wp_remote_request( string $url, array $args = array() ): array {
	$body = isset($args['body']) ? json_decode((string) $args['body'], true) : null;
	$GLOBALS['datamachine_code_github_requests'][] = array(
		'method' => (string) ( $args['method'] ?? 'GET' ),
		'url'    => $url,
		'body'   => $body,
	);

	if ( str_contains($url, '/git/blobs') ) {
		++$GLOBALS['datamachine_code_github_blob_count'];
		if ( $GLOBALS['datamachine_code_github_fail_blob_index'] === $GLOBALS['datamachine_code_github_blob_count'] ) {
			return datamachine_code_test_response(500, array( 'message' => 'blob failed' ));
		}

		return datamachine_code_test_response(201, array( 'sha' => 'blob-' . $GLOBALS['datamachine_code_github_blob_count'] ));
	}

	if ( str_contains($url, '/git/trees') ) {
		return datamachine_code_test_response(201, array( 'sha' => 'new-tree' ));
	}

	if ( str_contains($url, '/git/commits') ) {
		return datamachine_code_test_response(
			201,
			array(
				'sha'      => 'new-commit',
				'html_url' => 'https://github.com/owner/repo/commit/new-commit',
				'message'  => (string) ( $body['message'] ?? '' ),
			)
		);
	}

	if ( str_contains($url, '/git/ref/heads/main') && 'PATCH' === ( $args['method'] ?? '' ) ) {
		return datamachine_code_test_response(200, array( 'object' => array( 'sha' => $body['sha'] ?? '' ) ));
	}

	return datamachine_code_test_response(404, array( 'message' => 'not found' ));
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function matching_requests( string $method, string $needle ): array {
	return array_values(
		array_filter(
			$GLOBALS['datamachine_code_github_requests'],
			static fn( array $request ): bool => $method === $request['method'] && str_contains($request['url'], $needle)
		)
	);
}

function reset_github_stubs( ?int $fail_blob_index = null ): void {
	$GLOBALS['datamachine_code_github_requests']        = array();
	$GLOBALS['datamachine_code_github_fail_blob_index'] = $fail_blob_index;
	$GLOBALS['datamachine_code_github_blob_count']      = 0;
}

putenv('GITHUB_TOKEN=stub-token');

require_once dirname(__DIR__) . '/inc/Support/PluginSettings.php';
require_once dirname(__DIR__) . '/inc/Support/GitHubCredentialResolver.php';
require_once dirname(__DIR__) . '/inc/Abilities/GitHubAbilities.php';

use DataMachineCode\Abilities\GitHubAbilities;

try {
	reset_github_stubs();
	$result = GitHubAbilities::commitFiles(
		array(
			'repo'           => 'owner/repo',
			'branch'         => 'main',
			'commit_message' => 'test: atomic commit',
			'files'          => array(
				'a.txt' => 'alpha',
				'b.txt' => 'bravo',
			),
		)
	);

	assert_true(! is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : 'atomic commit failed');
	assert_true('new-commit' === ( $result['commit']['sha'] ?? '' ), 'atomic commit did not return the new commit SHA');
	assert_true(2 === count(matching_requests('POST', '/git/blobs')), 'expected one blob per file');
	assert_true(1 === count(matching_requests('POST', '/git/trees')), 'expected one tree creation');
	assert_true(1 === count(matching_requests('POST', '/git/commits')), 'expected one commit creation');
	assert_true(1 === count(matching_requests('PATCH', '/git/ref/heads/main')), 'expected one branch ref update');
	assert_true(0 === count(matching_requests('PUT', '/contents/')), 'must not use Contents API writes');

	$tree_request = matching_requests('POST', '/git/trees')[0];
	assert_true('base-tree' === ( $tree_request['body']['base_tree'] ?? '' ), 'tree must be based on the branch base tree');
	assert_true(array( 'a.txt', 'b.txt' ) === array_column($tree_request['body']['tree'] ?? array(), 'path'), 'tree must contain both file paths');

	reset_github_stubs(2);
	$failed = GitHubAbilities::commitFiles(
		array(
			'repo'           => 'owner/repo',
			'branch'         => 'main',
			'commit_message' => 'test: atomic failure',
			'files'          => array(
				'a.txt' => 'alpha',
				'b.txt' => 'bravo',
			),
		)
	);

	assert_true(is_wp_error($failed), 'blob failure should fail the atomic commit');
	assert_true(0 === count(matching_requests('POST', '/git/trees')), 'failure before tree creation must not create a tree');
	assert_true(0 === count(matching_requests('POST', '/git/commits')), 'failure before commit creation must not create a commit');
	assert_true(0 === count(matching_requests('PATCH', '/git/ref/heads/main')), 'failure before ref update must not publish changes');

	fwrite(STDOUT, "github-atomic-commit ok\n");
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
