<?php
/**
 * Pure-PHP smoke test for WorktreeStalenessProbe + related staleness helpers.
 *
 * Run: php tests/smoke-worktree-staleness.php
 *
 * Covers:
 *   - fetch() success returns ok=true and no error field
 *   - fetch() failure populates ok=false + trimmed error message
 *     (synthetic repo with no `origin` remote configured)
 *   - behind_count() parses the rev-list output to an int on success
 *   - behind_count() returns null when upstream is not configured
 *   - parse_count() handles empty / whitespace / non-numeric output
 *   - is_missing_upstream() matches common git phrasings
 *   - rebase into an upstream tip clears behind-count to 0
 *   - rebase conflict aborts cleanly (pre-rebase HEAD preserved)
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// The probe depends on GitRunner and WP_Error. Stub WP_Error minimally so the
// probe can run without a WP bootstrap, mirroring smoke-worktree-bootstrap.php.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public $data;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

require __DIR__ . '/../inc/Support/GitRunner.php';
require __DIR__ . '/../inc/Workspace/WorktreeStalenessProbe.php';

use DataMachineCode\Workspace\WorktreeStalenessProbe;

$failures = 0;
$total    = 0;

$assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
	$total++;
	if ( $expected === $actual ) {
		echo "  ✓ {$message}\n";
		return;
	}
	$failures++;
	echo "  ✗ {$message}\n";
	echo "    expected: " . var_export( $expected, true ) . "\n";
	echo "    actual:   " . var_export( $actual, true ) . "\n";
};

$assert_true = function ( $condition, string $message ) use ( &$failures, &$total ): void {
	$total++;
	if ( $condition ) {
		echo "  ✓ {$message}\n";
		return;
	}
	$failures++;
	echo "  ✗ {$message}\n";
};

$cleanup = function ( string $path ): void {
	if ( ! is_dir( $path ) && ! is_file( $path ) ) {
		return;
	}
	if ( is_file( $path ) ) {
		unlink( $path );
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $path );
};

// -----------------------------------------------------------------------------
// parse_count()
// -----------------------------------------------------------------------------

echo "parse_count()\n";
$assert( 0, WorktreeStalenessProbe::parse_count( '' ), 'empty string → 0' );
$assert( 0, WorktreeStalenessProbe::parse_count( "\n" ), 'newline only → 0' );
$assert( 0, WorktreeStalenessProbe::parse_count( "  \t\n" ), 'whitespace only → 0' );
$assert( 3, WorktreeStalenessProbe::parse_count( '3' ), 'plain "3"' );
$assert( 42, WorktreeStalenessProbe::parse_count( "42\n" ), 'trailing newline' );
$assert( 7, WorktreeStalenessProbe::parse_count( "  7  " ), 'surrounding whitespace' );
$assert( 0, WorktreeStalenessProbe::parse_count( 'abc' ), 'non-numeric → 0' );
$assert( 0, WorktreeStalenessProbe::parse_count( '3 extra' ), 'mixed tokens → 0' );

// -----------------------------------------------------------------------------
// is_missing_upstream()
// -----------------------------------------------------------------------------

echo "\nis_missing_upstream()\n";
$assert( true, WorktreeStalenessProbe::is_missing_upstream( "fatal: no upstream configured for branch 'foo'" ), 'no upstream configured' );
$assert( true, WorktreeStalenessProbe::is_missing_upstream( "fatal: ambiguous argument '@{upstream}': unknown revision" ), 'unknown revision' );
$assert( true, WorktreeStalenessProbe::is_missing_upstream( "fatal: bad revision 'main..@{u}'" ), 'bad revision' );
$assert( true, WorktreeStalenessProbe::is_missing_upstream( "fatal: Needed a single revision" ), 'needed a single revision' );
$assert( false, WorktreeStalenessProbe::is_missing_upstream( "fatal: not a git repository" ), 'generic failure → false' );
$assert( false, WorktreeStalenessProbe::is_missing_upstream( '' ), 'empty string → false' );

// -----------------------------------------------------------------------------
// fetch() + behind_count() against real git fixtures
// -----------------------------------------------------------------------------

echo "\nfetch() + behind_count() against real git fixtures\n";

$which_git = trim( (string) shell_exec( 'command -v git 2>/dev/null' ) );
if ( '' === $which_git ) {
	echo "  - skipped: git binary not available on PATH\n";
} else {
	// Build a tiny upstream repo with 3 commits on main.
	$upstream_repo = sys_get_temp_dir() . '/dmc-stale-upstream-' . bin2hex( random_bytes( 4 ) );
	mkdir( $upstream_repo, 0755, true );
	exec( sprintf( 'cd %s && git init -q --initial-branch=main', escapeshellarg( $upstream_repo ) ) );
	for ( $i = 1; $i <= 3; $i++ ) {
		file_put_contents( $upstream_repo . '/f.txt', "v{$i}\n" );
		exec( sprintf(
			'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m "c%d"',
			escapeshellarg( $upstream_repo ),
			$i
		) );
	}

	// Clone into a consumer repo, then reset it back 2 commits so it is
	// demonstrably "behind" origin/main.
	$consumer = sys_get_temp_dir() . '/dmc-stale-consumer-' . bin2hex( random_bytes( 4 ) );
	exec( sprintf( 'git clone -q %s %s', escapeshellarg( $upstream_repo ), escapeshellarg( $consumer ) ) );
	exec( sprintf( 'cd %s && git reset -q --hard HEAD~2', escapeshellarg( $consumer ) ) );

	// fetch() against a working `origin` → ok.
	$ok = WorktreeStalenessProbe::fetch( $consumer );
	$assert( true, $ok['ok'], 'fetch() ok against real origin' );
	$assert_true( ! isset( $ok['error'] ), 'fetch() ok: no error field set' );

	// behind_count() with upstream configured → 2.
	$behind = WorktreeStalenessProbe::behind_count( $consumer, 'main', '@{upstream}' );
	$assert( 2, $behind, 'behind_count main..@{upstream} = 2 (reset back 2)' );

	// behind_count() on a fresh local branch with no tracking → null.
	exec( sprintf( 'cd %s && git checkout -q -b orphan', escapeshellarg( $consumer ) ) );
	$no_upstream = WorktreeStalenessProbe::behind_count( $consumer, 'orphan', '@{upstream}' );
	$assert( null, $no_upstream, 'behind_count() with no @{upstream} → null (not 0, not WP_Error)' );

	// behind_count() against a non-existent ref → null (matches is_missing_upstream heuristic).
	$bogus = WorktreeStalenessProbe::behind_count( $consumer, 'main', 'origin/does-not-exist' );
	$assert( null, $bogus, 'behind_count() with unknown upstream ref → null' );

	// behind_count() where ref is up to date with upstream → 0, not null.
	exec( sprintf( 'cd %s && git checkout -q main && git reset -q --hard origin/main', escapeshellarg( $consumer ) ) );
	$tip = WorktreeStalenessProbe::behind_count( $consumer, 'main', '@{upstream}' );
	$assert( 0, $tip, 'behind_count() at tip → 0 (distinct from null)' );

	$cleanup( $consumer );
	$cleanup( $upstream_repo );

	// fetch() against a repo with NO `origin` remote → ok=false + error populated.
	$no_origin = sys_get_temp_dir() . '/dmc-stale-no-origin-' . bin2hex( random_bytes( 4 ) );
	mkdir( $no_origin, 0755, true );
	exec( sprintf( 'cd %s && git init -q --initial-branch=main', escapeshellarg( $no_origin ) ) );
	file_put_contents( $no_origin . '/x.txt', "x\n" );
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m init',
		escapeshellarg( $no_origin )
	) );

	$failed = WorktreeStalenessProbe::fetch( $no_origin );
	$assert( false, $failed['ok'], 'fetch() ok=false when no origin remote' );
	$assert_true( isset( $failed['error'] ) && '' !== $failed['error'], 'fetch() failure: error field populated' );
	// The error message should mention origin or remote or repository.
	$assert_true(
		isset( $failed['error'] ) && (
			false !== stripos( $failed['error'], 'origin' )
			|| false !== stripos( $failed['error'], 'remote' )
			|| false !== stripos( $failed['error'], 'repository' )
		),
		'fetch() failure: error mentions origin/remote/repository'
	);
	$cleanup( $no_origin );

	// -----------------------------------------------------------------------------
	// Rebase behavior — smoke coverage for the gate+rebase PR
	// -----------------------------------------------------------------------------

	echo "\nrebase behavior (real git fixtures)\n";

	// Scenario A: clean rebase — a branch 2 behind its upstream rebases cleanly
	// onto the tip and behind_count afterwards is 0.
	$upstream_a = sys_get_temp_dir() . '/dmc-rebase-up-' . bin2hex( random_bytes( 4 ) );
	mkdir( $upstream_a, 0755, true );
	exec( sprintf( 'cd %s && git init -q --initial-branch=main', escapeshellarg( $upstream_a ) ) );
	for ( $i = 1; $i <= 3; $i++ ) {
		file_put_contents( $upstream_a . '/a.txt', "a{$i}\n" );
		exec( sprintf(
			'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m "up-%d"',
			escapeshellarg( $upstream_a ),
			$i
		) );
	}

	$consumer_a = sys_get_temp_dir() . '/dmc-rebase-con-' . bin2hex( random_bytes( 4 ) );
	exec( sprintf( 'git clone -q %s %s', escapeshellarg( $upstream_a ), escapeshellarg( $consumer_a ) ) );
	// Consumer lags 2 behind, and has an independent commit to rebase.
	exec( sprintf( 'cd %s && git reset -q --hard HEAD~2', escapeshellarg( $consumer_a ) ) );
	file_put_contents( $consumer_a . '/consumer.txt', "c\n" );
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m "consumer work"',
		escapeshellarg( $consumer_a )
	) );

	// Pre-rebase: 2 behind.
	$pre = WorktreeStalenessProbe::behind_count( $consumer_a, 'main', '@{upstream}' );
	$assert( 2, $pre, 'pre-rebase: 2 behind @{upstream}' );

	// Clean rebase.
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t rebase @{upstream} 2>&1',
		escapeshellarg( $consumer_a )
	), $rebase_out, $rebase_code );
	$assert( 0, $rebase_code, 'clean rebase onto @{upstream} exit 0' );

	// Post-rebase: 0 behind, and consumer commit still present.
	$post = WorktreeStalenessProbe::behind_count( $consumer_a, 'main', '@{upstream}' );
	$assert( 0, $post, 'post-rebase: 0 behind @{upstream}' );

	$log_out = shell_exec( sprintf( 'cd %s && git log --oneline | head -5', escapeshellarg( $consumer_a ) ) );
	$assert_true(
		false !== strpos( (string) $log_out, 'consumer work' ),
		'post-rebase: consumer commit still present on HEAD'
	);

	$cleanup( $consumer_a );
	$cleanup( $upstream_a );

	// Scenario B: conflicting rebase — rebase fails, we abort, and pre-rebase
	// HEAD is preserved.
	$upstream_b = sys_get_temp_dir() . '/dmc-conflict-up-' . bin2hex( random_bytes( 4 ) );
	mkdir( $upstream_b, 0755, true );
	exec( sprintf( 'cd %s && git init -q --initial-branch=main', escapeshellarg( $upstream_b ) ) );
	file_put_contents( $upstream_b . '/f.txt', "original\n" );
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m "base"',
		escapeshellarg( $upstream_b )
	) );

	$consumer_b = sys_get_temp_dir() . '/dmc-conflict-con-' . bin2hex( random_bytes( 4 ) );
	exec( sprintf( 'git clone -q %s %s', escapeshellarg( $upstream_b ), escapeshellarg( $consumer_b ) ) );

	// Consumer and upstream edit the same line differently.
	file_put_contents( $consumer_b . '/f.txt', "consumer side\n" );
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m "consumer edit"',
		escapeshellarg( $consumer_b )
	) );

	file_put_contents( $upstream_b . '/f.txt', "upstream side\n" );
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m "upstream edit"',
		escapeshellarg( $upstream_b )
	) );

	// Fetch so the consumer sees the conflict-inducing upstream commit.
	exec( sprintf( 'cd %s && git fetch -q origin', escapeshellarg( $consumer_b ) ) );

	$pre_sha = trim( (string) shell_exec( sprintf( 'cd %s && git rev-parse HEAD', escapeshellarg( $consumer_b ) ) ) );

	// Attempt the rebase; expect non-zero exit.
	exec( sprintf(
		'cd %s && git -c user.email=t@t -c user.name=t rebase @{upstream} 2>&1',
		escapeshellarg( $consumer_b )
	), $conflict_out, $conflict_code );
	$assert_true( 0 !== $conflict_code, 'conflicting rebase: non-zero exit' );

	// Abort and confirm we're back on the consumer commit.
	exec( sprintf( 'cd %s && git rebase --abort 2>&1', escapeshellarg( $consumer_b ) ) );
	$post_sha = trim( (string) shell_exec( sprintf( 'cd %s && git rev-parse HEAD', escapeshellarg( $consumer_b ) ) ) );
	$assert( $pre_sha, $post_sha, 'rebase --abort: HEAD restored to pre-rebase SHA' );

	// And behind-count still reflects the pre-rebase lag (1 behind).
	$still_behind = WorktreeStalenessProbe::behind_count( $consumer_b, 'main', '@{upstream}' );
	$assert( 1, $still_behind, 'post-abort: still 1 behind @{upstream}' );

	$cleanup( $consumer_b );
	$cleanup( $upstream_b );
}

// -----------------------------------------------------------------------------
echo "\n{$total} total, ";
echo ( 0 === $failures ) ? "{$total}/{$total} passed\n" : "{$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
