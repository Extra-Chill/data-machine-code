<?php
/**
 * CLI output coverage for workspace show machine formats.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace DataMachineCode\Abilities {
	class WorkspaceAbilities {
		public static array|\WP_Error $result;
		public static function showRepo( array $input ): array|\WP_Error { return self::$result; }
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public static function workspace_hygiene_recovery_suggestion( array $capacity ): array { return array(); }
	}

	class WorktreeDiskBudget {
		public static function format_summary( array $capacity ): string { return (string) ( $capacity['summary'] ?? '' ); }
		public static function format_trigger_reasons( array $capacity ): array { return $capacity['trigger_reasons'] ?? array(); }
	}
}

namespace {
	final class Workspace_Show_Cli_Halt extends \RuntimeException {
		public function __construct( public readonly int $status ) { parent::__construct( 'WP-CLI halted.' ); }
	}

	final class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

	final class WP_CLI {
		public static array $lines = array();
		public static array $logs = array();
		public static function line( string $message ): void { self::$lines[] = $message; }
		public static function log( string $message ): void { self::$logs[] = $message; }
		public static function warning( string $message ): void { self::$logs[] = $message; }
		public static function error( string $message ): void { throw new \RuntimeException( $message ); }
		public static function halt( int $status ): never { throw new Workspace_Show_Cli_Halt( $status ); }
	}

	define( 'ABSPATH', __DIR__ . '/fixtures/' );
	require_once dirname( __DIR__ ) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachineCode\Abilities\WorkspaceAbilities;
	use DataMachineCode\Cli\Commands\WorkspaceCommand;

	function workspace_show_cli_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}

	WorkspaceAbilities::$result = array(
		'success'     => true,
		'name'        => 'example',
		'path'        => '/workspace/example',
		'branch'      => 'main',
		'remote'      => 'https://github.com/example/example.git',
		'commit'      => 'abc1234 subject',
		'dirty'       => 0,
		'is_worktree' => false,
		'workspace_capacity' => array(
			'summary'         => 'Disk budget: 10 GiB free; status=warning.',
			'trigger_reasons' => array( 'Warning: workspace count is above the advisory threshold.' ),
		),
	);
	$command = new WorkspaceCommand();
	$command->show( array( 'example' ), array() );
	workspace_show_cli_assert(
		array(
			'Name:     example',
			'Path:     /workspace/example',
			'Branch:   main',
			'Remote:   https://github.com/example/example.git',
			'Latest:   abc1234 subject',
			'Disk budget: 10 GiB free; status=warning.',
			'Warning: workspace count is above the advisory threshold.',
			'Dirty:    no',
		) === WP_CLI::$logs,
		'Default workspace show output changed.'
	);

	WP_CLI::$logs = array();
	$command->show( array( 'example' ), array( 'format' => 'json' ) );
	$payload = json_decode( WP_CLI::$lines[0] ?? '', true );
	workspace_show_cli_assert( WorkspaceAbilities::$result === $payload, 'Workspace show JSON did not retain the ability result.' );

	WP_CLI::$lines = array();
	WorkspaceAbilities::$result = new WP_Error( 'workspace_not_found', 'Repository "missing" not found.', array( 'name' => 'missing' ) );
	try {
		$command->show( array( 'missing' ), array( 'format' => 'json' ) );
		throw new \RuntimeException( 'Workspace show JSON failure did not halt.' );
	} catch ( Workspace_Show_Cli_Halt $halt ) {
		workspace_show_cli_assert( 1 === $halt->status, 'Workspace show JSON failure used the wrong exit status.' );
	}
	$error = json_decode( WP_CLI::$lines[0] ?? '', true );
	workspace_show_cli_assert(
		false === ( $error['success'] ?? true ) && 'workspace_not_found' === ( $error['error']['code'] ?? null ) && 'missing' === ( $error['error']['data']['name'] ?? null ),
		'Workspace show JSON failure did not return the typed error envelope.'
	);

	echo "workspace-show-cli-format-contract: ok\n";
}
