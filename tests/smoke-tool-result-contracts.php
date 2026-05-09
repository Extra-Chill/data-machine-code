<?php
/**
 * Pure-PHP smoke test for AI tool result contracts.
 *
 * Run: php tests/smoke-tool-result-contracts.php
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
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

$GLOBALS['dmc_remote_workspace_state'] = array(
	'worktrees' => array(
		'demo@fix-contracts' => array(
			'repo_name' => 'demo',
			'repo'      => 'Extra-Chill/data-machine-code',
			'branch'    => 'fix/tool-result-contracts',
		),
	),
);

function get_option( string $name, $default = null ) {
	return 'datamachine_code_remote_workspace_state' === $name ? $GLOBALS['dmc_remote_workspace_state'] : $default;
}

require __DIR__ . '/../inc/Workspace/RemoteWorkspaceBackend.php';

use DataMachineCode\Workspace\RemoteWorkspaceBackend;

$failures = array();
$assert   = function ( string $label, bool $condition ) use ( &$failures ): void {
	if ( $condition ) {
		echo "  ok {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  fail {$label}\n";
};

echo "Tool result contracts - smoke\n";

$push = ( new RemoteWorkspaceBackend() )->git_push( 'demo@fix-contracts' );

$assert( 'workspace_git_push succeeds', is_array( $push ) && true === ( $push['success'] ?? false ) );
$assert( 'workspace_git_push identifies branch pushes', is_array( $push ) && 'branch_push' === ( $push['kind'] ?? '' ) );
$assert( 'workspace_git_push exposes repo slug', is_array( $push ) && 'Extra-Chill/data-machine-code' === ( $push['repo'] ?? '' ) );
$assert( 'workspace_git_push exposes branch URL', is_array( $push ) && 'https://github.com/Extra-Chill/data-machine-code/tree/fix%2Ftool-result-contracts' === ( $push['url'] ?? '' ) );
$assert( 'workspace_git_push URL is distinguishable from PR URL', is_array( $push ) && str_contains( $push['url'] ?? '', '/tree/' ) && ! str_contains( $push['url'] ?? '', '/pull/' ) );
$assert( 'workspace_git_push next args point to PR creation', is_array( $push ) && 'create_github_pull_request' === ( $push['next_required_tool'] ?? '' ) );

if ( ! empty( $failures ) ) {
	echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure}\n";
	}
	exit( 1 );
}

echo "\nOK\n";
exit( 0 );
