<?php
/**
 * Pure-PHP smoke test for GitSync Phase 2 — the write path.
 *
 * Exercises policy gates, add, commit, push, submit (sans GitHub call),
 * and updatePolicy against a **local bare repo as the fake remote**.
 * Zero network, zero credentials — everything runs under a temp
 * directory that's torn down on exit.
 *
 * Run: php tests/smoke-gitsync-write.php
 *
 * The `submit` flow is exercised up to the `git push` step; the
 * GitHubAbilities PR call is stubbed via the `pre_http_request` filter
 * so we can assert request shape without hitting the network.
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		$scratch = sys_get_temp_dir() . '/dmc-gitsync-write-' . getmypid();
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

	// wp_remote_request stub — routes through the in-memory mock table so
	// tests can assert request shape without network access.
	if ( ! function_exists( 'wp_remote_request' ) ) {
		function wp_remote_request( string $url, array $args = array() ) {
			$GLOBALS['__dmc_http_capture'][] = array(
				'url'     => $url,
				'method'  => $args['method'] ?? 'GET',
				'body'    => $args['body'] ?? null,
				'headers' => $args['headers'] ?? array(),
			);
			$method = strtoupper( $args['method'] ?? 'GET' );
			$key    = $method . ' ' . strtok( $url, '?' );
			if ( isset( $GLOBALS['__dmc_http_mock'][ $key ] ) ) {
				return $GLOBALS['__dmc_http_mock'][ $key ];
			}
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => json_encode( array( 'message' => 'Not mocked: ' . $key ) ),
			);
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

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ): void {}
	}

	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/GitSync/GitRepo.php';
	require __DIR__ . '/../inc/GitSync/GitSyncBinding.php';
	require __DIR__ . '/../inc/GitSync/GitSyncRegistry.php';
	require __DIR__ . '/../inc/GitSync/GitSyncSubmitter.php';
	require __DIR__ . '/../inc/GitSync/GitSync.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( $cond, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $cond ) { echo "  ✓ {$message}\n"; return; }
		$failures++;
		echo "  ✗ {$message}\n";
	};

	$cleanup = function () use ( $scratch ): void {
		if ( is_dir( $scratch ) ) {
			exec( 'rm -rf ' . escapeshellarg( $scratch ) );
		}
	};
	register_shutdown_function( $cleanup );

	echo "GitSync Phase 2 — write-path smoke\n";
	echo "Scratch ABSPATH: " . ABSPATH . "\n\n";

	// -------------------------------------------------------------------------
	// Fake remote: a local bare repo seeded with an initial commit on `main`.
	// -------------------------------------------------------------------------
	$fake_remote_path = sys_get_temp_dir() . '/dmc-gitsync-fake-remote-' . getmypid() . '.git';
	$fake_remote      = 'file://' . $fake_remote_path;
	$seed_dir         = sys_get_temp_dir() . '/dmc-gitsync-fake-seed-' . getmypid();
	exec( 'git init --bare ' . escapeshellarg( $fake_remote_path ) . ' 2>&1', $_out, $init_ok );
	if ( 0 !== $init_ok ) { echo "  ! could not init bare repo\n"; exit( 1 ); }
	register_shutdown_function( fn() => exec( 'rm -rf ' . escapeshellarg( $fake_remote_path ) ) );
	register_shutdown_function( fn() => exec( 'rm -rf ' . escapeshellarg( $seed_dir ) ) );

	// Seed with one commit so origin/main exists.
	mkdir( $seed_dir, 0755, true );
	exec( 'git -C ' . escapeshellarg( $seed_dir ) . ' init -b main 2>&1' );
	exec( 'git -C ' . escapeshellarg( $seed_dir ) . ' -c user.email=smoke@example.com -c user.name=Smoke commit --allow-empty -m "init" 2>&1' );
	exec( 'git -C ' . escapeshellarg( $seed_dir ) . ' remote add origin ' . escapeshellarg( $fake_remote ) . ' 2>&1' );
	exec( 'git -C ' . escapeshellarg( $seed_dir ) . ' push -u origin main 2>&1', $_out, $seed_ok );
	if ( 0 !== $seed_ok ) { echo "  ! could not seed bare repo\n"; exit( 1 ); }

	// Configure git identity on the test binding so commits don't fail in
	// CI-like envs where user.email isn't set globally.
	putenv( 'GIT_AUTHOR_NAME=Smoke' );
	putenv( 'GIT_AUTHOR_EMAIL=smoke@example.com' );
	putenv( 'GIT_COMMITTER_NAME=Smoke' );
	putenv( 'GIT_COMMITTER_EMAIL=smoke@example.com' );

	$gs = new \DataMachineCode\GitSync\GitSync();

	// -------------------------------------------------------------------------
	// 1. Bind (reuses Phase 1 code path).
	// -------------------------------------------------------------------------
	echo "Bind against fake remote\n";
	$bind = $gs->bind( array(
		'slug'       => 'w',
		'local_path' => '/bound/',
		'remote_url' => $fake_remote,
	) );
	$assert( ! is_wp_error( $bind ), 'bind succeeded' );
	$absolute = $bind['local_path'] ?? '';

	// Configure local git identity inside the clone.
	exec( 'git -C ' . escapeshellarg( $absolute ) . ' config user.email smoke@example.com' );
	exec( 'git -C ' . escapeshellarg( $absolute ) . ' config user.name Smoke' );

	// -------------------------------------------------------------------------
	// 2. add/commit/push refuse when write_enabled=false (Phase 1 default).
	// -------------------------------------------------------------------------
	echo "\nPolicy gates (write_enabled=false)\n";
	file_put_contents( $absolute . '/foo.md', "hello\n" );
	$add_blocked = $gs->add( 'w', array( 'foo.md' ) );
	$assert( is_wp_error( $add_blocked ) && 'write_disabled' === $add_blocked->get_error_code(), 'add refused without write_enabled' );

	$commit_blocked = $gs->commit( 'w', 'nope nope nope' );
	$assert( is_wp_error( $commit_blocked ) && 'write_disabled' === $commit_blocked->get_error_code(), 'commit refused without write_enabled' );

	$push_blocked = $gs->push( 'w' );
	$assert( is_wp_error( $push_blocked ) && 'push_disabled' === $push_blocked->get_error_code(), 'push refused without push_enabled' );

	// -------------------------------------------------------------------------
	// 3. updatePolicy: turn on writes but not allowed_paths yet.
	// -------------------------------------------------------------------------
	echo "\nupdatePolicy\n";
	$p1 = $gs->updatePolicy( 'w', array( 'write_enabled' => true ) );
	$assert( ! is_wp_error( $p1 ), 'policy update succeeded' );
	$assert( true === ( $p1['policy']['write_enabled'] ?? false ), 'write_enabled now true' );

	$add_noroots = $gs->add( 'w', array( 'foo.md' ) );
	$assert( is_wp_error( $add_noroots ) && 'no_allowed_paths' === $add_noroots->get_error_code(), 'add refused without allowed_paths' );

	// Policy key whitelist.
	$unknown = $gs->updatePolicy( 'w', array( 'bogus' => true ) );
	$assert( is_wp_error( $unknown ) && 'unknown_policy_key' === $unknown->get_error_code(), 'rejects unknown policy key' );

	// safe_direct_push without push_enabled errors.
	$sdp_orphan = $gs->updatePolicy( 'w', array( 'safe_direct_push' => true ) );
	$assert( is_wp_error( $sdp_orphan ) && 'policy_conflict' === $sdp_orphan->get_error_code(), 'rejects safe_direct_push without push_enabled' );

	// -------------------------------------------------------------------------
	// 4. Set allowed_paths + push_enabled and exercise add/commit path restrictions.
	// -------------------------------------------------------------------------
	echo "\nallowed_paths enforcement\n";
	mkdir( $absolute . '/articles', 0755, true );
	file_put_contents( $absolute . '/articles/a.md', "aaa\n" );
	file_put_contents( $absolute . '/secrets.env', "KEY=val\n" );
	file_put_contents( $absolute . '/README.md', "readme\n" );

	$p2 = $gs->updatePolicy( 'w', array(
		'allowed_paths' => array( 'articles/' ),
		'push_enabled'  => true,
	) );
	$assert( ! is_wp_error( $p2 ), 'allowed_paths + push_enabled set' );

	$add_outside = $gs->add( 'w', array( 'README.md' ) );
	$assert( is_wp_error( $add_outside ) && 'path_not_allowed' === $add_outside->get_error_code(), 'add refuses path outside allowed_paths' );

	$add_sensitive = $gs->add( 'w', array( 'secrets.env' ) );
	$assert( is_wp_error( $add_sensitive ) && 'sensitive_path' === $add_sensitive->get_error_code(), 'add refuses sensitive filename' );

	$add_ok = $gs->add( 'w', array( 'articles/a.md' ) );
	$assert( ! is_wp_error( $add_ok ) && array( 'articles/a.md' ) === $add_ok['paths'], 'add accepts allowed path' );

	// -------------------------------------------------------------------------
	// 5. Commit validation.
	// -------------------------------------------------------------------------
	echo "\nCommit\n";
	$short = $gs->commit( 'w', 'nope' );
	$assert( is_wp_error( $short ) && 'message_too_short' === $short->get_error_code(), 'rejects short commit message' );

	$too_long = $gs->commit( 'w', str_repeat( 'x', 201 ) );
	$assert( is_wp_error( $too_long ) && 'message_too_long' === $too_long->get_error_code(), 'rejects overlong commit message' );

	$commit = $gs->commit( 'w', 'Add first article' );
	$assert( ! is_wp_error( $commit ), 'commit succeeded' );
	$assert( is_string( $commit['commit'] ?? null ) && '' !== $commit['commit'], 'commit returns hash' );

	// Clean-tree commit refuses.
	$empty = $gs->commit( 'w', 'another commit message' );
	$assert( is_wp_error( $empty ) && 'nothing_staged' === $empty->get_error_code(), 'refuses commit when nothing staged' );

	// -------------------------------------------------------------------------
	// 6. Direct push: push_enabled alone still refuses.
	// -------------------------------------------------------------------------
	echo "\nDirect push (two-key auth)\n";
	$dp_blocked = $gs->push( 'w' );
	$assert( is_wp_error( $dp_blocked ) && 'direct_push_blocked' === $dp_blocked->get_error_code(), 'direct push needs safe_direct_push' );

	$gs->updatePolicy( 'w', array( 'safe_direct_push' => true ) );
	$push_ok = $gs->push( 'w' );
	$assert( ! is_wp_error( $push_ok ), 'direct push with both keys set' );

	// Verify the commit actually landed on the fake remote.
	exec( 'git -C ' . escapeshellarg( $fake_remote_path ) . ' rev-parse HEAD', $remote_head );
	$assert( ! empty( $remote_head[0] ), 'bare remote advanced after push' );

	// -------------------------------------------------------------------------
	// 7. Submit flow — fake GitHub remote slug via a fs path. We can't run
	//    openOrUpdatePullRequest against an fs path, so point submit at a
	//    pretend github.com URL that pushes to the same fs bare repo via a
	//    separate mock. Instead: verify the pre-GitHub steps complete and
	//    the PR call hits the mock table.
	// -------------------------------------------------------------------------
	echo "\nSubmit (mocked PR API)\n";

	// Re-bind to a github.com-shaped URL for submit — but actually push to
	// our fake remote by monkey-patching via environment: we rewrite the
	// binding's remote_url after bind so it passes the github.com gate while
	// pointing at the real bare repo for git operations.
	$gs2 = $gs; // reuse
	$gs->unbind( 'w', true );

	$bind2 = $gs->bind( array(
		'slug'       => 's',
		'local_path' => '/submitbound/',
		'remote_url' => $fake_remote,
	) );
	$assert( ! is_wp_error( $bind2 ), 'submit binding created' );
	$absolute2 = $bind2['local_path'];
	exec( 'git -C ' . escapeshellarg( $absolute2 ) . ' config user.email smoke@example.com' );
	exec( 'git -C ' . escapeshellarg( $absolute2 ) . ' config user.name Smoke' );

	// Swap remote_url to github-looking value so submit() passes the host
	// check. The git remote stays pointed at the bare repo (origin).
	$opts                               = $GLOBALS['__dmc_options']['datamachine_gitsync_bindings'];
	$opts['s']['remote_url']            = 'https://github.com/example/repo';
	$opts['s']['policy']['write_enabled'] = true;
	$opts['s']['policy']['push_enabled']  = true;
	$opts['s']['policy']['allowed_paths'] = array( 'articles/' );
	$GLOBALS['__dmc_options']['datamachine_gitsync_bindings'] = $opts;

	// Route the PAT-injected push URL back to the local bare repo via git's
	// insteadOf config. Scoped to this clone only (no global pollution).
	$injected_url = 'https://test-pat@github.com/example/repo';
	exec( sprintf(
		'git -C %s config url.%s.insteadOf %s',
		escapeshellarg( $absolute2 ),
		escapeshellarg( $fake_remote ),
		escapeshellarg( $injected_url )
	) );
	// Also the un-injected URL, in case PAT resolution short-circuits.
	exec( sprintf(
		'git -C %s config --add url.%s.insteadOf %s',
		escapeshellarg( $absolute2 ),
		escapeshellarg( $fake_remote ),
		escapeshellarg( 'https://github.com/example/repo' )
	) );

	if ( ! is_dir( $absolute2 . '/articles' ) ) {
		mkdir( $absolute2 . '/articles', 0755, true );
	}
	file_put_contents( $absolute2 . '/articles/new.md', "new\n" );

	// Mock GitHub API: GET existing pulls returns [], POST creates PR #42.
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/example/repo/pulls']  = array(
		'response' => array( 'code' => 200 ),
		'body'     => '[]',
	);
	$GLOBALS['__dmc_http_mock']['POST https://api.github.com/repos/example/repo/pulls'] = array(
		'response' => array( 'code' => 201 ),
		'body'     => json_encode( array(
			'number'   => 42,
			'html_url' => 'https://github.com/example/repo/pull/42',
			'state'    => 'open',
		) ),
	);

	// Stub GitHubAbilities so the PAT resolves and apiGet/apiRequest
	// route through the mocked wp_remote_request. Mirrors the real class's
	// response envelope (`['success' => true, 'data' => $decoded_body]`)
	// so callers don't need test-specific unwrapping.
	if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
		eval( 'namespace DataMachineCode\\Abilities;
		class GitHubAbilities {
			public static function getPat(): string { return "test-pat"; }
			public static function apiGet( string $url, array $query, string $pat ) {
				if ( ! empty( $query ) ) { $url = add_query_arg( $query, $url ); }
				$resp = wp_remote_request( $url, array( "method" => "GET", "headers" => array( "Authorization" => "token " . $pat ) ) );
				$code = (int) wp_remote_retrieve_response_code( $resp );
				$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
				if ( $code >= 400 ) { return new \\WP_Error( "github_api_error", "stub error", array( "status" => $code ) ); }
				return array( "success" => true, "data" => $body );
			}
			public static function apiRequest( string $method, string $url, array $body, string $pat ) {
				$resp = wp_remote_request( $url, array( "method" => $method, "headers" => array( "Authorization" => "token " . $pat ), "body" => json_encode( $body ) ) );
				$code = (int) wp_remote_retrieve_response_code( $resp );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
				if ( $code >= 400 ) { return new \\WP_Error( "github_api_error", "stub error", array( "status" => $code ) ); }
				return array( "success" => true, "data" => $decoded );
			}
		}' );
	}

	$submit = $gs->submit( 's', array(
		'message' => 'Propose a new article',
	) );

	$assert( ! is_wp_error( $submit ), 'submit succeeded — ' . ( is_wp_error( $submit ) ? $submit->get_error_message() : '' ) );
	$assert( 42 === ( $submit['pr']['number'] ?? null ), 'submit returned PR #42' );
	$assert( 'opened' === ( $submit['pr']['action'] ?? null ), 'submit reports PR as opened' );
	$assert( 'gitsync/s' === ( $submit['branch'] ?? null ), 'feature branch is gitsync/s' );

	// Verify working tree returned to pinned branch (main).
	exec( 'git -C ' . escapeshellarg( $absolute2 ) . ' rev-parse --abbrev-ref HEAD', $br_out );
	$assert( 'main' === trim( (string) ( $br_out[0] ?? '' ) ), 'working tree returned to pinned branch after submit' );

	// Verify feature branch pushed to bare remote.
	$feat_out = array();
	exec( 'git -C ' . escapeshellarg( $fake_remote_path ) . ' rev-parse refs/heads/gitsync/s 2>&1', $feat_out, $feat_exit );
	$assert( 0 === $feat_exit, 'feature branch gitsync/s exists on bare remote' );

	// Verify API was called in the expected order.
	$captured = $GLOBALS['__dmc_http_capture'];
	$assert( count( $captured ) >= 2, 'at least 2 API calls captured' );
	$assert( 'GET' === ( $captured[0]['method'] ?? '' ), 'first API call is GET' );
	$assert( 'POST' === ( $captured[1]['method'] ?? '' ), 'second API call is POST' );
	$assert( str_contains( (string) ( $captured[0]['headers']['Authorization'] ?? '' ), 'test-pat' ), 'PAT sent in Authorization header' );

	// -------------------------------------------------------------------------
	// 8. Submit is idempotent — second run updates PR in place.
	// -------------------------------------------------------------------------
	echo "\nSubmit update (second run)\n";
	$GLOBALS['__dmc_http_capture'] = array();
	$GLOBALS['__dmc_http_mock']['GET https://api.github.com/repos/example/repo/pulls']  = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			array(
				'number'   => 42,
				'html_url' => 'https://github.com/example/repo/pull/42',
				'state'    => 'open',
			),
		) ),
	);
	$GLOBALS['__dmc_http_mock']['PATCH https://api.github.com/repos/example/repo/pulls/42'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'number'   => 42,
			'html_url' => 'https://github.com/example/repo/pull/42',
			'state'    => 'open',
		) ),
	);

	if ( ! is_dir( $absolute2 . '/articles' ) ) {
		mkdir( $absolute2 . '/articles', 0755, true );
	}
	file_put_contents( $absolute2 . '/articles/new.md', "new v2\n" );
	$submit2 = $gs->submit( 's', array( 'message' => 'Update the new article' ) );
	$assert(
		! is_wp_error( $submit2 ),
		'second submit succeeded' . ( is_wp_error( $submit2 ) ? ' — ' . $submit2->get_error_code() . ': ' . $submit2->get_error_message() : '' )
	);
	if ( is_wp_error( $submit2 ) ) {
		// Skip remaining assertions to keep the run readable.
		echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
		exit( $failures > 0 ? 1 : 0 );
	}
	$assert( 'updated' === ( $submit2['pr']['action'] ?? null ), 'second submit reports PR as updated' );

	// -------------------------------------------------------------------------
	// 9. Submit with no changes under allowed_paths errors cleanly.
	// -------------------------------------------------------------------------
	echo "\nSubmit with nothing to propose\n";
	$clean_submit = $gs->submit( 's', array( 'message' => 'nothing changed here' ) );
	$assert( is_wp_error( $clean_submit ) && 'nothing_to_submit' === $clean_submit->get_error_code(), 'submit refuses when nothing is staged' );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
