<?php
/**
 * Smoke test for GitHub-backed worktree cleanup lookup caching.
 *
 * Run: php tests/smoke-worktree-cleanup-github-cache.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists( __NAMESPACE__ . '\\FilesystemHelper' ) ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace DataMachineCode\Abilities {
	class GitHubAbilities {
		public static string $pat = 'test-token';
		public static array $calls = array();
		public static array $responses = array();

		public static function getPat(): string {
			return self::$pat;
		}

		public static function apiGet( string $url, array $params, string $pat, int $timeout = 30 ) {
			self::$calls[] = array(
				'url'     => $url,
				'params'  => $params,
				'pat'     => $pat,
				'timeout' => $timeout,
			);

			$response = array_shift( self::$responses );
			return null === $response ? array( 'data' => array() ) : $response;
		}
	}
}

namespace {
	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Workspace\Workspace;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;
			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = (array) $data;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_data(): array {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists( 'size_format' ) ) {
		function size_format( $bytes ): string {
			return (string) $bytes;
		}
	}

	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

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
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$assert_true = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $condition ) {
			echo "  ✓ {$message}\n";
			return;
		}
		$failures++;
		echo "  ✗ {$message}\n";
	};

	$find = new \ReflectionMethod( Workspace::class, 'find_merged_pr_for_branch' );
	$detect = new \ReflectionMethod( Workspace::class, 'detect_merge_signal' );
	$workspace = new Workspace();
	$run = function ( string $command, string $cwd = '' ): string {
		$full = '' === $cwd ? $command : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $command );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $full . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( "Command failed (%d): %s\n%s", $code, $full, implode( "\n", $output ) ) );
		}
		return implode( "\n", $output );
	};
	$rm_rf = function ( string $path ): void {
		if ( '' === $path || '/' === $path || ! file_exists( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'rm -rf %s', escapeshellarg( $path ) ) );
	};

	$merged_pr = array(
		'number'    => 42,
		'state'     => 'closed',
		'merged_at' => '2026-04-28T00:00:00Z',
		'html_url'  => 'https://github.com/Extra-Chill/data-machine-code/pull/42',
		'head'      => array(
			'ref'  => 'merged-branch',
			'repo' => array( 'full_name' => 'Extra-Chill/data-machine-code' ),
		),
	);
	$fork_pr = array(
		'number'    => 43,
		'state'     => 'closed',
		'merged_at' => '2026-04-28T00:00:00Z',
		'html_url'  => 'https://github.com/fork/data-machine-code/pull/43',
		'head'      => array(
			'ref'  => 'fork-branch',
			'repo' => array( 'full_name' => 'someone/data-machine-code' ),
		),
	);

	echo "=== smoke-worktree-cleanup-github-cache ===\n";

	echo "\n[1] one repo lookup is cached across branch checks\n";
	GitHubAbilities::$pat       = 'test-token';
	GitHubAbilities::$calls     = array();
	GitHubAbilities::$responses = array( array( 'data' => array( $merged_pr, $fork_pr ) ) );
	$cache = array();

	$hit  = $find->invokeArgs( $workspace, array( 'Extra-Chill/data-machine-code', 'merged-branch', &$cache ) );
	$miss = $find->invokeArgs( $workspace, array( 'Extra-Chill/data-machine-code', 'missing-branch', &$cache ) );
	$fork = $find->invokeArgs( $workspace, array( 'Extra-Chill/data-machine-code', 'fork-branch', &$cache ) );

	$assert( 42, $hit['number'] ?? null, 'merged same-repo branch is found' );
	$assert( null, $miss, 'missing branch returns null' );
	$assert( null, $fork, 'fork branch with same ref is ignored' );
	$assert( 1, count( GitHubAbilities::$calls ), 'GitHub API called once for three branch checks' );
	$assert( 5, GitHubAbilities::$calls[0]['timeout'] ?? null, 'GitHub lookup uses bounded timeout' );
	$assert( 'closed', GitHubAbilities::$calls[0]['params']['state'] ?? null, 'lookup lists closed PRs' );
	$assert( false, array_key_exists( 'head', GitHubAbilities::$calls[0]['params'] ?? array() ), 'lookup is repo-level, not branch-specific' );

	echo "\n[2] lookup failures are cached and returned as per-repo errors\n";
	GitHubAbilities::$calls     = array();
	GitHubAbilities::$responses = array( new \WP_Error( 'github_request_failed', 'timeout after 5 seconds' ) );
	$cache = array();

	$error_a = $find->invokeArgs( $workspace, array( 'Extra-Chill/data-machine-code', 'a', &$cache ) );
	$error_b = $find->invokeArgs( $workspace, array( 'Extra-Chill/data-machine-code', 'b', &$cache ) );

	$assert_true( is_wp_error( $error_a ), 'first failed lookup returns WP_Error' );
	$assert_true( is_wp_error( $error_b ), 'cached failed lookup returns WP_Error' );
	$assert( 1, count( GitHubAbilities::$calls ), 'failed GitHub lookup is cached' );
	$assert_true( str_contains( $error_a->get_error_message(), 'GitHub cleanup lookup failed' ), 'error message names cleanup lookup' );

	echo "\n[3] missing GitHub credentials skip API calls\n";
	GitHubAbilities::$pat       = '';
	GitHubAbilities::$calls     = array();
	GitHubAbilities::$responses = array( array( 'data' => array( $merged_pr ) ) );
	$cache = array();

	$disabled = $find->invokeArgs( $workspace, array( 'Extra-Chill/data-machine-code', 'merged-branch', &$cache ) );
	$assert( null, $disabled, 'no PAT returns no GitHub signal' );
	$assert( 0, count( GitHubAbilities::$calls ), 'no PAT avoids API call entirely' );

	echo "\n[4] locally merged branches skip GitHub lookup\n";
	GitHubAbilities::$pat       = 'test-token';
	GitHubAbilities::$calls     = array();
	GitHubAbilities::$responses = array( array( 'data' => array( $merged_pr ) ) );
	$cache = array();
	$tmp   = sys_get_temp_dir() . '/dmc-cleanup-local-merged-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );

	try {
		$run( 'git init -q --initial-branch=main', $tmp );
		$run( 'git config user.email test@example.test', $tmp );
		$run( 'git config user.name Test', $tmp );
		$run( 'git remote add origin https://github.com/Extra-Chill/data-machine-code.git', $tmp );
		file_put_contents( $tmp . '/README.md', "# Demo\n" );
		$run( 'git add README.md && git commit -q -m initial', $tmp );
		$run( 'git checkout -q -b merged-local', $tmp );
		file_put_contents( $tmp . '/feature.txt', "feature\n" );
		$run( 'git add feature.txt && git commit -q -m feature', $tmp );
		$run( 'git checkout -q main && git merge -q --no-ff merged-local -m merge-feature', $tmp );
		$run( 'git update-ref refs/remotes/origin/main HEAD', $tmp );
		$run( 'git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main', $tmp );

		$signal = $detect->invokeArgs( $workspace, array( $tmp, 'data-machine-code', 'merged-local', true, &$cache ) );
		$assert( 'local-merged', $signal['signal'] ?? null, 'local ancestry produces cleanup signal' );
		$assert( 0, count( GitHubAbilities::$calls ), 'local ancestry signal avoids GitHub API call even with skip_github enabled' );

		$run( 'git checkout -q -b not-merged main', $tmp );
		file_put_contents( $tmp . '/unmerged.txt', "unmerged\n" );
		$run( 'git add unmerged.txt && git commit -q -m unmerged', $tmp );
		$unmerged = $detect->invokeArgs( $workspace, array( $tmp, 'data-machine-code', 'not-merged', false, &$cache ) );
		$assert( null, $unmerged, 'unmerged local branch falls through to missing GitHub signal' );
		$assert( 1, count( GitHubAbilities::$calls ), 'unmerged local branch still performs bounded GitHub lookup' );
	} finally {
		$rm_rf( $tmp );
	}

	if ( $failures > 0 ) {
		echo "\nFAIL: {$failures}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nPASS: {$total} assertions passed.\n";
}
