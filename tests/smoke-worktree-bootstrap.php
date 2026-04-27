<?php
/**
 * Pure-PHP smoke test for WorktreeBootstrapper detection + execution.
 *
 * Run: php tests/smoke-worktree-bootstrap.php
 *
 * Covers:
 *   - detect() returns the expected booleans / package-manager slug for
 *     synthetic worktree fixtures with root and nested lockfile combinations
 *   - bootstrap() skips gracefully when nothing is present (all STATUS_SKIPPED)
 *   - bootstrap() runs `git submodule update` successfully against a real
 *     on-disk repo with an empty `.gitmodules`
 *   - format() produces a human-readable summary
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require __DIR__ . '/../inc/Workspace/WorktreeBootstrapper.php';

use DataMachineCode\Workspace\WorktreeBootstrapper;

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

// -----------------------------------------------------------------------------
// Fixture helpers
// -----------------------------------------------------------------------------

$make_fixture = function ( array $files ): string {
	$root = sys_get_temp_dir() . '/dmc-bootstrap-smoke-' . bin2hex( random_bytes( 4 ) );
	mkdir( $root, 0755, true );
	foreach ( $files as $relative => $body ) {
		$abs = $root . '/' . $relative;
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $abs, $body );
	}
	return $root;
};

$cleanup = function ( string $path ): void {
	if ( ! is_dir( $path ) ) {
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
// detect()
// -----------------------------------------------------------------------------

echo "detect()\n";

$empty = $make_fixture( array() );
$d     = WorktreeBootstrapper::detect( $empty );
$assert( false, $d['submodules'], 'empty dir: submodules=false' );
$assert( null, $d['packages'], 'empty dir: packages=null' );
$assert( false, $d['composer'], 'empty dir: composer=false' );
$cleanup( $empty );

$pnpm = $make_fixture(
	array(
		'package.json'    => '{"name":"x"}',
		'pnpm-lock.yaml'  => "lockfileVersion: '6.0'\n",
		'.gitmodules'     => "[submodule \"vendor/foo\"]\n\tpath = vendor/foo\n\turl = https://example.com/foo.git\n",
		'composer.lock'   => '{"packages":[]}',
	)
);
$d = WorktreeBootstrapper::detect( $pnpm );
$assert( true, $d['submodules'], 'pnpm fixture: submodules detected' );
$assert( 'pnpm', $d['packages'], 'pnpm fixture: packages=pnpm' );
$assert( true, $d['composer'], 'pnpm fixture: composer detected' );
$cleanup( $pnpm );

// Priority: pnpm beats bun beats yarn beats npm when multiple are present.
$multi = $make_fixture(
	array(
		'pnpm-lock.yaml'    => '',
		'bun.lockb'         => '',
		'yarn.lock'         => '',
		'package-lock.json' => '{}',
	)
);
$assert( 'pnpm', WorktreeBootstrapper::detect( $multi )['packages'], 'pnpm wins over bun/yarn/npm' );
$cleanup( $multi );

$bun = $make_fixture( array( 'bun.lockb' => '', 'yarn.lock' => '', 'package-lock.json' => '{}' ) );
$assert( 'bun', WorktreeBootstrapper::detect( $bun )['packages'], 'bun wins over yarn/npm' );
$cleanup( $bun );

$bun_text = $make_fixture( array( 'bun.lock' => '', 'package-lock.json' => '{}' ) );
$assert( 'bun', WorktreeBootstrapper::detect( $bun_text )['packages'], 'bun.lock (text) detected' );
$cleanup( $bun_text );

$yarn = $make_fixture( array( 'yarn.lock' => '', 'package-lock.json' => '{}' ) );
$assert( 'yarn', WorktreeBootstrapper::detect( $yarn )['packages'], 'yarn wins over npm' );
$cleanup( $yarn );

$npm = $make_fixture( array( 'package-lock.json' => '{}' ) );
$assert( 'npm', WorktreeBootstrapper::detect( $npm )['packages'], 'npm when only package-lock.json' );
$cleanup( $npm );

// No lockfile → null even with package.json present.
$pkg_only = $make_fixture( array( 'package.json' => '{}' ) );
$assert( null, WorktreeBootstrapper::detect( $pkg_only )['packages'], 'package.json alone → null (no lockfile)' );
$cleanup( $pkg_only );

$monorepo = $make_fixture(
	array(
		'wordpress/package.json'       => '{"name":"wordpress-extension"}',
		'wordpress/package-lock.json'  => '{}',
		'wordpress/composer.json'      => '{"name":"example/wordpress-extension"}',
		'wordpress/composer.lock'      => '{"packages":[]}',
		'node_modules/package-lock.json' => '{}',
		'vendor/composer.lock'         => '{"packages":[]}',
	)
);
$d = WorktreeBootstrapper::detect( $monorepo );
$assert( null, $d['packages'], 'monorepo fixture: root packages still null' );
$assert( false, $d['composer'], 'monorepo fixture: root composer still false' );
$assert( 1, count( $d['package_roots'] ), 'monorepo fixture: one nested package root' );
$assert( 'wordpress', $d['package_roots'][0]['relative'], 'monorepo fixture: package root relative path' );
$assert( 'npm', $d['package_roots'][0]['manager'], 'monorepo fixture: nested package manager detected' );
$assert( 1, count( $d['composer_roots'] ), 'monorepo fixture: one nested composer root' );
$assert( 'wordpress', $d['composer_roots'][0]['relative'], 'monorepo fixture: composer root relative path' );
$cleanup( $monorepo );

// -----------------------------------------------------------------------------
// bootstrap() skip behavior
// -----------------------------------------------------------------------------

echo "\nbootstrap() on empty dir → all skipped\n";

$empty = $make_fixture( array() );
$r     = WorktreeBootstrapper::bootstrap( $empty );
$assert( true, $r['success'], 'empty dir: success=true' );
$assert( false, $r['ran_any'], 'empty dir: ran_any=false' );
$assert( 3, count( $r['steps'] ), 'empty dir: 3 step results' );
foreach ( $r['steps'] as $step ) {
	$assert( WorktreeBootstrapper::STATUS_SKIPPED, $step['status'], sprintf( 'step %s skipped', $step['step'] ) );
}
$cleanup( $empty );

// -----------------------------------------------------------------------------
// bootstrap() submodule path (real git repo)
// -----------------------------------------------------------------------------

echo "\nbootstrap() runs submodule update on a real repo\n";

// Build a real git repo with an empty `.gitmodules` so `git submodule update`
// has something to run against (empty but valid). We deliberately skip
// packages/composer steps by omitting lockfiles.
$repo = sys_get_temp_dir() . '/dmc-bootstrap-git-' . bin2hex( random_bytes( 4 ) );
mkdir( $repo, 0755, true );
exec( sprintf( 'cd %s && git init -q --initial-branch=main 2>&1', escapeshellarg( $repo ) ), $_u, $init_exit );
if ( 0 !== $init_exit ) {
	echo "  - skipped: git init failed (git unavailable?)\n";
} else {
	file_put_contents( $repo . '/.gitmodules', "" );
	file_put_contents( $repo . '/README.md', "x\n" );
	exec( sprintf( 'cd %s && git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m init 2>&1', escapeshellarg( $repo ) ), $_u, $commit_exit );
	$assert( 0, $commit_exit, 'seed commit created' );

	$r = WorktreeBootstrapper::bootstrap( $repo );
	$assert( true, $r['success'], 'submodule repo: success' );
	$assert( true, $r['ran_any'], 'submodule repo: ran_any=true' );

	$by_step = array();
	foreach ( $r['steps'] as $step ) {
		$by_step[ $step['step'] ] = $step;
	}

	$assert( WorktreeBootstrapper::STATUS_RAN, $by_step['submodules']['status'], 'submodules: ran' );
	$assert_true( str_contains( $by_step['submodules']['command'] ?? '', 'submodule update --init --recursive' ), 'submodules: correct command' );
	$assert( WorktreeBootstrapper::STATUS_SKIPPED, $by_step['packages']['status'], 'packages: skipped (no lockfile)' );
	$assert( WorktreeBootstrapper::STATUS_SKIPPED, $by_step['composer']['status'], 'composer: skipped (no composer.lock)' );
}
$cleanup( $repo );

// -----------------------------------------------------------------------------
// bootstrap() nested monorepo dependency roots
// -----------------------------------------------------------------------------

echo "\nbootstrap() discovers nested monorepo roots\n";

$mono = $make_fixture(
	array(
		'wordpress/package-lock.json' => '{}',
		'wordpress/composer.lock'     => '{"packages":[]}',
	)
);
$r = WorktreeBootstrapper::bootstrap( $mono );

$by_step = array();
foreach ( $r['steps'] as $step ) {
	$key             = $step['step'] . ':' . ( $step['relative'] ?? '.' );
	$by_step[ $key ] = $step;
}

$assert_true( isset( $by_step['packages:wordpress'] ), 'nested package root produces package step' );
$assert_true( isset( $by_step['composer:wordpress'] ), 'nested composer root produces composer step' );
$assert_true(
	in_array( $by_step['packages:wordpress']['status'], array( WorktreeBootstrapper::STATUS_RAN, WorktreeBootstrapper::STATUS_SKIPPED, WorktreeBootstrapper::STATUS_FAILED ), true ),
	'nested package step has valid status'
);
$assert_true(
	in_array( $by_step['composer:wordpress']['status'], array( WorktreeBootstrapper::STATUS_RAN, WorktreeBootstrapper::STATUS_SKIPPED, WorktreeBootstrapper::STATUS_FAILED ), true ),
	'nested composer step has valid status'
);

$mono_format = WorktreeBootstrapper::format( $r );
$assert_true( str_contains( $mono_format, 'packages[wordpress]' ), 'format: nested package label includes relative path' );
$assert_true( str_contains( $mono_format, 'composer[wordpress]' ), 'format: nested composer label includes relative path' );
$cleanup( $mono );

// -----------------------------------------------------------------------------
// bootstrap() nvm fallback for non-interactive shells
// -----------------------------------------------------------------------------

echo "\nbootstrap() resolves npm from nvm when PATH is sparse\n";

$old_home = getenv( 'HOME' );
$old_path = getenv( 'PATH' );
$home     = $make_fixture( array() );
$npm_bin  = $home . '/.nvm/versions/node/v99.0.0/bin';
mkdir( $npm_bin, 0755, true );
file_put_contents( $npm_bin . '/npm', "#!/bin/sh\nexit 0\n" );
chmod( $npm_bin . '/npm', 0755 );

$npm_fixture = $make_fixture( array( 'package-lock.json' => '{}' ) );
putenv( 'HOME=' . $home );
putenv( 'PATH=/nonexistent' );
$r = WorktreeBootstrapper::bootstrap( $npm_fixture );

$by_step = array();
foreach ( $r['steps'] as $step ) {
	$by_step[ $step['step'] ] = $step;
}
$assert( WorktreeBootstrapper::STATUS_RAN, $by_step['packages']['status'], 'npm runs from nvm fallback path' );

false === $old_home ? putenv( 'HOME' ) : putenv( 'HOME=' . $old_home );
false === $old_path ? putenv( 'PATH' ) : putenv( 'PATH=' . $old_path );
$cleanup( $npm_fixture );
$cleanup( $home );

// -----------------------------------------------------------------------------
// format() output shape
// -----------------------------------------------------------------------------

echo "\nformat() output\n";

$result = array(
	'success' => false,
	'ran_any' => true,
	'steps'   => array(
		array( 'step' => 'submodules', 'status' => WorktreeBootstrapper::STATUS_RAN, 'command' => 'git submodule update --init --recursive' ),
		array( 'step' => 'packages', 'status' => WorktreeBootstrapper::STATUS_FAILED, 'command' => 'npm ci', 'exit_code' => 1, 'output_tail' => 'npm ERR! missing:  foo@1' ),
		array( 'step' => 'composer', 'status' => WorktreeBootstrapper::STATUS_SKIPPED, 'reason' => 'no composer.lock' ),
	),
);
$out = WorktreeBootstrapper::format( $result );
$assert_true( str_contains( $out, '✓' ), 'format: contains ran marker' );
$assert_true( str_contains( $out, '✗' ), 'format: contains failed marker' );
$assert_true( str_contains( $out, 'skipped (no composer.lock)' ), 'format: contains skip reason' );
$assert_true( str_contains( $out, 'npm ERR! missing' ), 'format: contains failure output tail' );

// Empty-steps case.
$empty_fmt = WorktreeBootstrapper::format( array( 'success' => true, 'ran_any' => false, 'steps' => array() ) );
$assert_true( str_contains( $empty_fmt, 'no steps attempted' ), 'format: empty steps message' );

// -----------------------------------------------------------------------------
echo "\n{$total} total, ";
echo ( 0 === $failures ) ? "{$total}/{$total} passed\n" : "{$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
