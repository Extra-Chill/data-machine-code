<?php
/**
 * Smoke test for local workspace grep.
 *
 * Run: php tests/smoke-workspace-grep.php
 */

declare( strict_types=1 );

namespace {
	$workspace_root = sys_get_temp_dir() . '/dmc-workspace-grep';
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $workspace_root . '/site/' );
	}
	if ( ! defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
		define( 'DATAMACHINE_WORKSPACE_PATH', $workspace_root . '/workspace' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message, private array $data = array() ) {}

			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): array { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	}

	if ( ! function_exists( 'size_format' ) ) {
		function size_format( $bytes ): string { return (string) $bytes . ' B'; }
	}

	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceReader.php';

	use DataMachineCode\Workspace\Workspace;
	use DataMachineCode\Workspace\WorkspaceReader;

	$failures = array();
	$total    = 0;
	$assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}
		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Workspace grep - smoke\n";

	@mkdir( DATAMACHINE_WORKSPACE_PATH . '/example/src', 0777, true );
	file_put_contents( DATAMACHINE_WORKSPACE_PATH . '/example/src/example.php', "<?php\nfunction workspace_grep_anchor() {\n\treturn 'needle';\n}\n" );
	file_put_contents( DATAMACHINE_WORKSPACE_PATH . '/example/src/skip.txt', "needle\n" );

	$reader = new WorkspaceReader( new Workspace() );
	$grep   = $reader->grep( 'example', 'workspace_grep_anchor', 'src', '*.php', 10, 1 );
	$assert( 'grep finds matching symbol in primary workspace', ! is_wp_error( $grep ) && 1 === $grep['count'] && 'src/example.php' === $grep['matches'][0]['path'] && 2 === $grep['matches'][0]['line'] );
	$assert( 'grep includes requested context', ! is_wp_error( $grep ) && ! empty( $grep['matches'][0]['context'] ) );

	$included = $reader->grep( 'example', 'needle', 'src', '*.php' );
	$assert( 'include glob filters file paths', ! is_wp_error( $included ) && 1 === $included['count'] && 'src/example.php' === $included['matches'][0]['path'] );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s) failed out of {$total}\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK ({$total} assertions)\n";
	exit( 0 );
}
