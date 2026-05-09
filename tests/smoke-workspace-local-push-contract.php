<?php
/**
 * Pure-PHP smoke test for local workspace git push result contracts.
 *
 * Run: php tests/smoke-workspace-local-push-contract.php
 */

declare( strict_types=1 );

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
	$root = sys_get_temp_dir() . '/dmc-local-push-contract-' . getmypid();
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
		define( 'DATAMACHINE_WORKSPACE_PATH', $root . '/workspace' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message, private array $data = array() ) {}

			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): array { return $this->data; }
		}
	}

	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function get_option( string $name, $default = null ) {
		return $default;
	}

	function apply_filters( string $name, $value ) {
		return $value;
	}

	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

	use DataMachineCode\Workspace\Workspace;

	$failures = array();
	$assert   = function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	$run = function ( string $command ) use ( &$failures ): void {
		$output = array();
		$code   = 0;
		exec( $command . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			$failures[] = 'command failed: ' . $command . ' :: ' . implode( "\n", $output );
		}
	};

	echo "Workspace local push contract - smoke\n";

	@mkdir( DATAMACHINE_WORKSPACE_PATH . '/demo', 0777, true );
	@mkdir( $root . '/remote.git', 0777, true );

	$repo = DATAMACHINE_WORKSPACE_PATH . '/demo';
	$bare = $root . '/remote.git';
	$run( 'git init --bare ' . escapeshellarg( $bare ) );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' init' );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' config user.email test@example.com' );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' config user.name Test' );
	file_put_contents( $repo . '/README.md', "# Demo\n" );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' add README.md' );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' commit -m initial' );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' branch -M fix/tool-result-contracts' );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' remote add publish https://github.com/Extra-Chill/data-machine-code.git' );
	$run( 'git -C ' . escapeshellarg( $repo ) . ' remote set-url --push publish ' . escapeshellarg( $bare ) );

	$result = ( new Workspace() )->git_push( 'demo', 'publish', 'fix/tool-result-contracts', true );

	$assert( 'local workspace_git_push succeeds', is_array( $result ) && true === ( $result['success'] ?? false ) );
	$assert( 'local workspace_git_push identifies branch pushes', is_array( $result ) && 'branch_push' === ( $result['kind'] ?? '' ) );
	$assert( 'local workspace_git_push keeps workspace repo explicit', is_array( $result ) && 'demo' === ( $result['workspace_repo'] ?? '' ) );
	$assert( 'local workspace_git_push uses canonical GitHub slug as top-level repo', is_array( $result ) && 'Extra-Chill/data-machine-code' === ( $result['repo'] ?? '' ) );
	$assert( 'local workspace_git_push exposes github_repo', is_array( $result ) && 'Extra-Chill/data-machine-code' === ( $result['github_repo'] ?? '' ) );
	$assert( 'local workspace_git_push exposes branch URL', is_array( $result ) && 'https://github.com/Extra-Chill/data-machine-code/tree/fix%2Ftool-result-contracts' === ( $result['url'] ?? '' ) );
	$assert( 'local workspace_git_push next PR args use GitHub slug', is_array( $result ) && 'Extra-Chill/data-machine-code' === ( $result['next_required_args']['repo'] ?? '' ) );
	$assert( 'local workspace_git_push next PR args use pushed branch', is_array( $result ) && 'fix/tool-result-contracts' === ( $result['next_required_args']['head'] ?? '' ) );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK\n";
	exit( 0 );
}
