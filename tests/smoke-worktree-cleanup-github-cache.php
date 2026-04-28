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
	$workspace = new Workspace();

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

	if ( $failures > 0 ) {
		echo "\nFAIL: {$failures}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nPASS: {$total} assertions passed.\n";
}
