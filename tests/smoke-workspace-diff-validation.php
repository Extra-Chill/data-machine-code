<?php
/**
 * Smoke test for workspace diff summary and validation.
 *
 * Run: php tests/smoke-workspace-diff-validation.php
 */

declare( strict_types=1 );

namespace {
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

			public function get_error_code(): string {
				return $this->code;
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
}

namespace DataMachine\Core\FilesRepository {
	class FilesystemHelper {
		public static function get(): self {
			return new self();
		}

		public function is_writable( string $path ): bool {
			return is_writable( $path );
		}
	}
}

namespace {
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceDiff.php';

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

	$run = function ( string $command, string $cwd ): void {
		exec( 'cd ' . escapeshellarg( $cwd ) . ' && ' . $command . ' 2>&1', $output, $exit_code );
		if ( 0 !== $exit_code ) {
			throw new RuntimeException( implode( "\n", $output ) );
		}
	};

	$tmp = sys_get_temp_dir() . '/dmc-workspace-diff-validation-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	register_shutdown_function( function () use ( $tmp ) {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
	} );

	define( 'DATAMACHINE_WORKSPACE_PATH', $tmp );

	$repo = $tmp . '/demo@diff-check';
	mkdir( $repo . '/src', 0755, true );
	mkdir( $repo . '/tests', 0755, true );
	$run( 'git init -q && git config user.email t@example.com && git config user.name Test', $repo );
	file_put_contents( $repo . '/src/Foo.php', "<?php\nreturn 'old';\n" );
	file_put_contents( $repo . '/tests/FooTest.php', "<?php\nreturn true;\n" );
	$run( 'git add src/Foo.php tests/FooTest.php && git commit -q -m initial', $repo );

	file_put_contents( $repo . '/src/Foo.php', "<?php\nreturn 'new';\n" );
	file_put_contents( $repo . '/tests/FooTest.php', "<?php\nreturn 'covered';\n" );
	file_put_contents( $repo . '/notes.txt', "untracked\n" );

	echo "Workspace diff validation\n";

	$diff    = new \DataMachineCode\Workspace\WorkspaceDiff();
	$summary = $diff->summary( 'demo@diff-check' );
	$assert( false, is_wp_error( $summary ), 'summary succeeds for local worktree handle' );
	$assert( array( 'notes.txt', 'src/Foo.php', 'tests/FooTest.php' ), is_wp_error( $summary ) ? array() : ( $summary['changed_files'] ?? array() ), 'summary reports tracked and untracked changed files' );
	$assert( true, is_wp_error( $summary ) ? false : (bool) ( $summary['totals']['tests_touched'] ?? false ), 'summary detects test changes' );
	$assert( 3, is_wp_error( $summary ) ? 0 : (int) ( $summary['totals']['files'] ?? 0 ), 'summary counts changed files' );

	$valid = $diff->validate(
		'demo@diff-check',
		array(
			'allow'         => array( 'src/**', 'tests/**', 'notes.txt' ),
			'deny'          => array( 'bootstrap.php' ),
			'include_any'   => array( 'src/Foo.php' ),
			'require_tests' => true,
		)
	);
	$assert( false, is_wp_error( $valid ), 'validation succeeds' );
	$assert( true, is_wp_error( $valid ) ? false : (bool) ( $valid['valid'] ?? false ), 'validation passes matching policy' );

	$invalid = $diff->validate(
		'demo@diff-check',
		array(
			'require_changed_files' => array(
				'include_any' => array( 'missing/**' ),
				'deny'        => array( 'notes.txt' ),
			),
			'require_tests'         => true,
		)
	);
	$assert( false, is_wp_error( $invalid ), 'failing validation still returns machine-readable result' );
	$assert( false, is_wp_error( $invalid ) ? true : (bool) ( $invalid['valid'] ?? true ), 'validation fails denied and missing include policy' );

	if ( $failures > 0 ) {
		echo "\n{$failures} of {$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$total} workspace diff validation assertions passed.\n";
}
