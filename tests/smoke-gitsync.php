<?php
/**
 * Pure-PHP smoke for the API-first GitSync.
 *
 * Zero git binary, zero network: GitHub API responses are mocked via
 * wp_remote_request, and local file I/O happens inside a scratch temp
 * directory that plays the role of ABSPATH. The same harness covers
 * Phase 1 (bind/pull/status/list/unbind) and Phase 2 (submit/push/
 * policy-update) because the API-first rebuild unifies them.
 *
 * Run: php tests/smoke-gitsync.php
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		$scratch = sys_get_temp_dir() . '/dmc-gitsync-api-' . getmypid();
		@mkdir( $scratch, 0755, true );
		define( 'ABSPATH', $scratch . '/' );
	}

	$GLOBALS['__dmc_options']      = array();
	$GLOBALS['__dmc_http_mock']    = array();
	$GLOBALS['__dmc_http_capture'] = array();

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;
			public function __construct( string $code = '', string $message = '', array $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof \WP_Error; }
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0755, true ); }
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return $GLOBALS['__dmc_options'][ $name ] ?? $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['__dmc_options'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $options = 0 ) { return json_encode( $data, $options ); }
	}

	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( array $args, string $url ): string {
			$qs = http_build_query( $args );
			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $qs;
		}
	}

	if ( ! function_exists( 'wp_remote_request' ) ) {
		function wp_remote_request( string $url, array $args = array() ) {
			$method = strtoupper( $args['method'] ?? 'GET' );
			$GLOBALS['__dmc_http_capture'][] = array(
				'url'     => $url,
				'method'  => $method,
				'body'    => $args['body'] ?? null,
				'headers' => $args['headers'] ?? array(),
			);
			// Match on "METHOD <url-without-query>" first, then on exact URL
			// with query so tests can be specific when needed.
			$clean_url = strtok( $url, '?' );
			$keys      = array(
				$method . ' ' . $url,
				$method . ' ' . $clean_url,
			);
			foreach ( $keys as $key ) {
				if ( isset( $GLOBALS['__dmc_http_mock'][ $key ] ) ) {
					$mock = $GLOBALS['__dmc_http_mock'][ $key ];
					// Allow mocks to be callables for dynamic responses.
					if ( is_callable( $mock ) ) {
						return $mock( $url, $args );
					}
					return $mock;
				}
			}
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => json_encode( array( 'message' => 'Not mocked: ' . $method . ' ' . $clean_url ) ),
			);
		}
	}

	if ( ! function_exists( 'wp_remote_get' ) ) {
		function wp_remote_get( string $url, array $args = array() ) {
			$args['method'] = 'GET';
			return wp_remote_request( $url, $args );
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ): int {
			return (int) ( $response['response']['code'] ?? 0 );
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( $response ): string {
			return (string) ( $response['body'] ?? '' );
		}
	}

	// Stub GitHubAbilities — real class has too many dependencies to load
	// here. We mirror just the static surface GitSync's path calls.
	if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
		eval( 'namespace DataMachineCode\\Abilities;
		class GitHubAbilities {
			public static function getPat(): string { return "test-pat"; }
			public static function apiGet( string $url, array $query, string $pat ) {
				if ( ! empty( $query ) ) { $url = add_query_arg( $query, $url ); }
				$resp = wp_remote_get( $url, array( "headers" => array( "Authorization" => "token " . $pat ) ) );
				$code = (int) wp_remote_retrieve_response_code( $resp );
				$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
				if ( $code >= 400 ) {
					$err_code = 404 === $code ? "github_not_found" : "github_api_error";
					return new \\WP_Error( $err_code, is_array( $body ) && isset( $body["message"] ) ? (string) $body["message"] : "stub error", array( "status" => $code ) );
				}
				return array( "success" => true, "data" => $body );
			}
			public static function apiRequest( string $method, string $url, array $body, string $pat ) {
				$resp = wp_remote_request( $url, array( "method" => $method, "headers" => array( "Authorization" => "token " . $pat ), "body" => json_encode( $body ) ) );
				$code = (int) wp_remote_retrieve_response_code( $resp );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
				if ( $code >= 400 ) {
					$err_code = 404 === $code ? "github_not_found" : "github_api_error";
					return new \\WP_Error( $err_code, is_array( $decoded ) && isset( $decoded["message"] ) ? (string) $decoded["message"] : "stub error", array( "status" => $code ) );
				}
				return array( "success" => true, "data" => $decoded );
			}
		}' );
	}

	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/GitSync/GitSyncBinding.php';
	require __DIR__ . '/../inc/GitSync/GitSyncRegistry.php';
	require __DIR__ . '/../inc/GitSync/GitSyncFetcher.php';
	require __DIR__ . '/../inc/GitSync/GitSyncProposer.php';
	require __DIR__ . '/../inc/GitSync/GitSync.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( $cond, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $cond ) { echo "  ✓ {$message}\n"; return; }
		$failures++;
		echo "  ✗ {$message}\n";
	};

	register_shutdown_function( fn() => is_dir( $scratch ) && exec( 'rm -rf ' . escapeshellarg( $scratch ) ) );

	echo "GitSync API-first — smoke\n";
	echo "Scratch ABSPATH: " . ABSPATH . "\n\n";

	// Helper: build a git-blob SHA for mocked tree responses.
	$blob_sha = fn( string $content ): string => sha1( 'blob ' . strlen( $content ) . "\0" . $content );

	// =========================================================================
	// 1. Input validation at bind time.
	// =========================================================================
	echo "Input validation\n";
	$gs = new \DataMachineCode\GitSync\GitSync();

	$r = $gs->bind( array( 'slug' => 'Bad Slug!', 'local_path' => '/x/', 'remote_url' => 'https://github.com/a/b' ) );
	$assert( is_wp_error( $r ) && 'invalid_slug' === $r->get_error_code(), 'rejects invalid slug' );

	$r = $gs->bind( array( 'slug' => 's', 'local_path' => '/x/', 'remote_url' => 'https://gitlab.com/a/b' ) );
	$assert( is_wp_error( $r ) && 'invalid_remote_url' === $r->get_error_code(), 'rejects non-GitHub remote' );

	$r = $gs->bind( array( 'slug' => 's', 'local_path' => '/../etc/', 'remote_url' => 'https://github.com/a/b' ) );
	$assert( is_wp_error( $r ) && 'path_traversal' === $r->get_error_code(), 'rejects traversal in local_path' );

	$r = $gs->bind( array( 'slug' => 's', 'local_path' => '/.env/', 'remote_url' => 'https://github.com/a/b' ) );
	$assert( is_wp_error( $r ) && 'sensitive_path' === $r->get_error_code(), 'rejects sensitive local_path' );

	// =========================================================================
	// 2. Bind (registry only — no HTTP, no disk).
	// =========================================================================
	echo "\nBind\n";
	$bound = $gs->bind( array(
		'slug'       => 'wiki',
		'local_path' => '/content/wiki/',
		'remote_url' => 'https://github.com/Automattic/a8c-wiki-woocommerce',
	) );
	$assert( ! is_wp_error( $bound ), 'bind succeeded' );
	$assert( false === is_dir( ABSPATH . 'content/wiki/' ), 'bind did not create directory' );

	$dup = $gs->bind( array( 'slug' => 'wiki', 'local_path' => '/x/', 'remote_url' => 'https://github.com/a/b' ) );
	$assert( is_wp_error( $dup ) && 'binding_exists' === $dup->get_error_code(), 'refuses duplicate slug' );

	// =========================================================================
	// 3. Pull — initial sync materializes files on disk.
	// =========================================================================
	echo "\nPull (initial)\n";
	$content_a = "article a v1\n";
	$content_b = "article b v1\n";
	$sha_a     = $blob_sha( $content_a );
	$sha_b     = $blob_sha( $content_b );

	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/trees/main'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'sha'       => 'tree-sha-1',
			'truncated' => false,
			'tree'      => array(
				array( 'path' => 'articles/a.md', 'type' => 'blob', 'sha' => $sha_a ),
				array( 'path' => 'articles/b.md', 'type' => 'blob', 'sha' => $sha_b ),
			),
		) ),
	);
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/contents/articles/a.md'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'content' => base64_encode( $content_a ), 'encoding' => 'base64', 'sha' => $sha_a ) ),
	);
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/contents/articles/b.md'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'content' => base64_encode( $content_b ), 'encoding' => 'base64', 'sha' => $sha_b ) ),
	);

	$pull = $gs->pull( 'wiki' );
	$assert( ! is_wp_error( $pull ), 'pull succeeded — ' . ( is_wp_error( $pull ) ? $pull->get_error_message() : '' ) );
	$assert( 2 === count( (array) ( $pull['updated'] ?? array() ) ), 'pulled 2 files' );
	$assert( file_get_contents( ABSPATH . 'content/wiki/articles/a.md' ) === $content_a, 'articles/a.md content matches' );
	$assert( file_get_contents( ABSPATH . 'content/wiki/articles/b.md' ) === $content_b, 'articles/b.md content matches' );

	// =========================================================================
	// 4. Pull again — nothing should change (SHAs match on disk).
	// =========================================================================
	echo "\nPull (idempotent)\n";
	$GLOBALS['__dmc_http_capture'] = array();
	$pull2 = $gs->pull( 'wiki' );
	$assert( ! is_wp_error( $pull2 ), 'second pull succeeded' );
	$assert( 0 === count( (array) ( $pull2['updated'] ?? array() ) ), 'second pull updated 0 files (already in sync)' );
	$assert( 2 === ( $pull2['unchanged'] ?? 0 ), 'second pull reports 2 unchanged' );

	// =========================================================================
	// 5. Upstream delete → local file removed, pulled_paths shrinks.
	// =========================================================================
	echo "\nPull (upstream deleted a file)\n";
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/trees/main'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'sha'  => 'tree-sha-2',
			'tree' => array(
				array( 'path' => 'articles/a.md', 'type' => 'blob', 'sha' => $sha_a ),
				// b.md removed upstream
			),
		) ),
	);
	$pull3 = $gs->pull( 'wiki' );
	$assert( ! is_wp_error( $pull3 ), 'pull after upstream delete succeeded' );
	$assert( in_array( 'articles/b.md', (array) ( $pull3['deleted'] ?? array() ), true ), 'reports articles/b.md deleted' );
	$assert( ! is_file( ABSPATH . 'content/wiki/articles/b.md' ), 'articles/b.md removed from disk' );

	// Untracked local file (consumer-owned proposal) is left alone.
	file_put_contents( ABSPATH . 'content/wiki/articles/local-only.md', "proposal\n" );
	$pull4 = $gs->pull( 'wiki' );
	$assert( is_file( ABSPATH . 'content/wiki/articles/local-only.md' ), 'untracked local file preserved across pull' );

	// =========================================================================
	// 6. Conflict: tracked file modified locally, upstream also changed.
	// =========================================================================
	echo "\nPull conflict (fail policy)\n";
	file_put_contents( ABSPATH . 'content/wiki/articles/a.md', "article a local-edit\n" );
	$content_a_v2 = "article a v2\n";
	$sha_a_v2     = $blob_sha( $content_a_v2 );
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/trees/main'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'sha'  => 'tree-sha-3',
			'tree' => array(
				array( 'path' => 'articles/a.md', 'type' => 'blob', 'sha' => $sha_a_v2 ),
			),
		) ),
	);
	$conflicted = $gs->pull( 'wiki' );
	$assert( is_array( $conflicted['conflicts'] ?? null ) && 1 === count( $conflicted['conflicts'] ), 'conflict surfaced under fail policy' );
	$assert( false === ( $conflicted['success'] ?? true ), 'conflicted pull reports success=false' );
	$assert( "article a local-edit\n" === file_get_contents( ABSPATH . 'content/wiki/articles/a.md' ), 'local edit preserved under fail policy' );

	// upstream_wins overrides.
	$gs->updatePolicy( 'wiki', array( 'conflict' => 'upstream_wins' ) );
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/contents/articles/a.md'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'content' => base64_encode( $content_a_v2 ), 'encoding' => 'base64', 'sha' => $sha_a_v2 ) ),
	);
	$wins = $gs->pull( 'wiki' );
	$assert( ! is_wp_error( $wins ), 'pull under upstream_wins succeeded' );
	$assert( $content_a_v2 === file_get_contents( ABSPATH . 'content/wiki/articles/a.md' ), 'upstream_wins overwrote local edit' );

	// =========================================================================
	// 7. Submit — write path, gated by policy.
	// =========================================================================
	echo "\nSubmit (gated)\n";
	$r = $gs->submit( 'wiki', array( 'message' => 'update article a content' ) );
	$assert( is_wp_error( $r ) && 'write_disabled' === $r->get_error_code(), 'submit refused without write_enabled' );

	$gs->updatePolicy( 'wiki', array( 'write_enabled' => true ) );
	$r = $gs->submit( 'wiki', array( 'message' => 'update article a content' ) );
	$assert( is_wp_error( $r ) && 'no_allowed_paths' === $r->get_error_code(), 'submit refused without allowed_paths' );

	$gs->updatePolicy( 'wiki', array( 'allowed_paths' => array( 'articles/' ) ) );
	// Clean up the untracked-probe file from the earlier pull test — it
	// would otherwise become a valid "new file" candidate for submit and
	// require its own PUT mock.
	@unlink( ABSPATH . 'content/wiki/articles/local-only.md' );
	// Edit locally, then submit.
	file_put_contents( ABSPATH . 'content/wiki/articles/a.md', "article a v3 proposal\n" );
	$sha_a_v3 = $blob_sha( "article a v3 proposal\n" );

	// Mock GitHub ref + branch + PR endpoints for submit flow.
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/ref/heads/main'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'object' => array( 'sha' => 'base-sha-1' ) ) ),
	);
	// Tree for submit's context — same as last pull.
	// (Already mocked above.)
	// Feature branch doesn't exist yet → 404
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/ref/heads/gitsync/wiki'] = array(
		'response' => array( 'code' => 404 ),
		'body'     => json_encode( array( 'message' => 'Not Found' ) ),
	);
	$GLOBALS['__dmc_http_mock']['POST https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/refs'] = array(
		'response' => array( 'code' => 201 ),
		'body'     => json_encode( array( 'ref' => 'refs/heads/gitsync/wiki', 'object' => array( 'sha' => 'base-sha-1' ) ) ),
	);
	$GLOBALS['__dmc_http_mock']['PUT https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/contents/articles/a.md'] = array(
		'response' => array( 'code' => 201 ),
		'body'     => json_encode( array( 'content' => array( 'sha' => $sha_a_v3 ), 'commit' => array( 'sha' => 'commit-1' ) ) ),
	);
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/pulls'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => '[]',
	);
	$GLOBALS['__dmc_http_mock']['POST https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/pulls'] = array(
		'response' => array( 'code' => 201 ),
		'body'     => json_encode( array( 'number' => 7, 'html_url' => 'https://github.com/a/b/pull/7', 'state' => 'open' ) ),
	);

	$submit = $gs->submit( 'wiki', array( 'message' => 'update article a content' ) );
	$assert( ! is_wp_error( $submit ), 'submit succeeded — ' . ( is_wp_error( $submit ) ? $submit->get_error_message() : '' ) );
	$assert( 'gitsync/wiki' === ( $submit['branch'] ?? null ), 'feature branch is gitsync/wiki' );
	$assert( 7 === ( $submit['pr']['number'] ?? null ), 'PR #7 opened' );
	$assert( 'opened' === ( $submit['pr']['action'] ?? null ), 'PR reported as opened' );

	// =========================================================================
	// 8. Submit again — existing branch + PR is updated in place.
	// =========================================================================
	echo "\nSubmit (update existing PR)\n";
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/ref/heads/gitsync/wiki'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'object' => array( 'sha' => 'feature-old-sha' ) ) ),
	);
	$GLOBALS['__dmc_http_mock']['PATCH https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/refs/heads/gitsync/wiki'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'object' => array( 'sha' => 'base-sha-1' ) ) ),
	);
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/pulls'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			array( 'number' => 7, 'html_url' => 'https://github.com/a/b/pull/7', 'state' => 'open' ),
		) ),
	);
	$GLOBALS['__dmc_http_mock']['PATCH https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/pulls/7'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'number' => 7, 'html_url' => 'https://github.com/a/b/pull/7', 'state' => 'open' ) ),
	);
	file_put_contents( ABSPATH . 'content/wiki/articles/a.md', "article a v4 proposal\n" );
	$submit2 = $gs->submit( 'wiki', array( 'message' => 'further update article a' ) );
	$assert( ! is_wp_error( $submit2 ), 'second submit succeeded' );
	$assert( 'updated' === ( $submit2['pr']['action'] ?? null ), 'PR reported as updated' );

	// =========================================================================
	// 9. Submit with nothing changed → nothing_to_submit.
	// =========================================================================
	echo "\nSubmit (nothing to propose)\n";
	// File's current SHA must match upstream tree's reported SHA.
	$current = file_get_contents( ABSPATH . 'content/wiki/articles/a.md' );
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/trees/main'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'sha'  => 'tree-sha-4',
			'tree' => array(
				array( 'path' => 'articles/a.md', 'type' => 'blob', 'sha' => $blob_sha( $current ) ),
			),
		) ),
	);
	$nothing = $gs->submit( 'wiki', array( 'message' => 'should have nothing' ) );
	$assert( is_wp_error( $nothing ) && 'nothing_to_submit' === $nothing->get_error_code(), 'submit refuses when nothing changed' );

	// =========================================================================
	// 10. Direct push — two-key auth.
	// =========================================================================
	echo "\nPush (two-key auth)\n";
	file_put_contents( ABSPATH . 'content/wiki/articles/a.md', "article a v5 direct\n" );
	$r = $gs->push( 'wiki', array( 'message' => 'direct update' ) );
	$assert( is_wp_error( $r ) && 'direct_push_blocked' === $r->get_error_code(), 'push refused without safe_direct_push' );

	$gs->updatePolicy( 'wiki', array( 'safe_direct_push' => true ) );
	$GLOBALS['__dmc_http_mock']['PUT https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/contents/articles/a.md'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'content' => array( 'sha' => 'sha-after-direct' ), 'commit' => array( 'sha' => 'commit-2' ) ) ),
	);
	// Submit refreshed upstream tree mock so push's context reads it as changed.
	$sha_old_on_upstream = $blob_sha( "article a v3 proposal\n" ); // still v3 upstream
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/Automattic/a8c-wiki-woocommerce/git/trees/main'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'sha'  => 'tree-sha-5',
			'tree' => array(
				array( 'path' => 'articles/a.md', 'type' => 'blob', 'sha' => $sha_old_on_upstream ),
			),
		) ),
	);
	$push = $gs->push( 'wiki', array( 'message' => 'direct update' ) );
	$assert( ! is_wp_error( $push ), 'push with both keys succeeded — ' . ( is_wp_error( $push ) ? $push->get_error_message() : '' ) );
	$assert( 1 === count( (array) ( $push['commits'] ?? array() ) ), 'push recorded 1 commit' );

	// =========================================================================
	// 11. Status + list + unbind round-trip.
	// =========================================================================
	echo "\nStatus + list + unbind\n";
	$st = $gs->status( 'wiki' );
	$assert( ! is_wp_error( $st ) && 'wiki' === $st['slug'], 'status returns binding' );
	$assert( true === ( $st['exists'] ?? false ), 'status reports exists=true' );

	$l = $gs->list_bindings();
	$assert( 1 === count( $l['bindings'] ?? array() ), 'list returns 1 binding' );

	$u = $gs->unbind( 'wiki' );
	$assert( ! is_wp_error( $u ) && false === ( $u['purged'] ?? true ), 'unbind preserved directory by default' );
	$assert( is_dir( ABSPATH . 'content/wiki/' ), 'directory still exists after non-purge unbind' );

	// Re-bind and purge.
	$gs->bind( array( 'slug' => 'wiki', 'local_path' => '/content/wiki/', 'remote_url' => 'https://github.com/Automattic/a8c-wiki-woocommerce' ) );
	$p = $gs->unbind( 'wiki', true );
	$assert( ! is_wp_error( $p ) && true === ( $p['purged'] ?? false ), 'purge unbind succeeded' );
	$assert( ! is_dir( ABSPATH . 'content/wiki/' ), 'directory removed by purge' );

	// =========================================================================
	// 12. Policy validation.
	// =========================================================================
	echo "\nPolicy validation\n";
	$gs->bind( array( 'slug' => 'p', 'local_path' => '/content/p/', 'remote_url' => 'https://github.com/Automattic/a8c-wiki-woocommerce' ) );
	$bad = $gs->updatePolicy( 'p', array( 'bogus' => true ) );
	$assert( is_wp_error( $bad ) && 'unknown_policy_key' === $bad->get_error_code(), 'rejects unknown policy key' );

	$orphan = $gs->updatePolicy( 'p', array( 'safe_direct_push' => true ) );
	$assert( is_wp_error( $orphan ) && 'policy_conflict' === $orphan->get_error_code(), 'rejects safe_direct_push without write_enabled' );

	$bad_conflict = $gs->updatePolicy( 'p', array( 'conflict' => 'nuke' ) );
	$assert( is_wp_error( $bad_conflict ) && 'invalid_conflict_strategy' === $bad_conflict->get_error_code(), 'rejects unknown conflict strategy' );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
