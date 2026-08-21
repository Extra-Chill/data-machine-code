<?php
/**
 * CLI output coverage for bounded workspace list summaries and machine formats.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {
		public static array $formatted = array();
		protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {
			self::$formatted[] = array( 'items' => $items, 'fields' => $fields, 'args' => $assoc_args );
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode($value, $flags); }
	}
	final class WP_Error {
		public function __construct( private string $message = '' ) {}
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	final class WP_CLI {
		public static array $lines = array();
		public static array $logs = array();
		public static function line( string $message ): void { self::$lines[] = $message; }
		public static function log( string $message ): void { self::$logs[] = $message; }
		public static function error( string $message ): void { throw new RuntimeException($message); }
	}
	final class WorkspaceListAbility {
		public function __construct( private array $result ) {}
		public function execute( array $input ): array { return $this->result; }
	}
	$GLOBALS['dmc_workspace_list_ability'] = null;
	function wp_get_ability( string $name ): ?WorkspaceListAbility { return $GLOBALS['dmc_workspace_list_ability']; }

	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachine\Cli\BaseCommand;
	use DataMachineCode\Cli\Commands\WorkspaceCommand;

	function cli_format_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}
	function cli_format_reset(): void {
		WP_CLI::$lines = array();
		WP_CLI::$logs = array();
		BaseCommand::$formatted = array();
	}

	$result = array(
		'success' => true,
		'path' => '/workspace',
		'total' => 100,
		'returned' => 2,
		'next_cursor' => 'cursor-2',
		'status_requested' => false,
		'repos' => array(
			array( 'name' => 'repo-a', 'repo' => 'repo-a', 'git' => true, 'path' => '/workspace/repo-a' ),
			array( 'name' => 'repo-b@task', 'repo' => 'repo-b', 'is_worktree' => true, 'git' => true, 'path' => '/workspace/repo-b@task' ),
		),
		'summary' => array( 'workspace' => '/workspace', 'total' => 100, 'primary' => 20, 'worktree' => 70, 'context' => 10, 'non_git' => 0, 'repos' => array() ),
	);
	$GLOBALS['dmc_workspace_list_ability'] = new WorkspaceListAbility($result);
	$command = new WorkspaceCommand();

	cli_format_reset();
	$command->list_repos(array(), array( 'summary' => true, 'format' => 'json' ));
	$summary_json = json_decode(WP_CLI::$lines[0] ?? '', true);
	cli_format_assert(100 === ($summary_json['total'] ?? null) && ! isset($summary_json['repos'][0]['name']), 'Summary JSON must serialize the full aggregate summary, not the current page.');
	cli_format_assert(2 === ($summary_json['returned'] ?? null) && 'cursor-2' === ($summary_json['next_cursor'] ?? null), 'Summary JSON must retain page continuation metadata.');

	cli_format_reset();
	$command->list_repos(array(), array( 'summary' => true ));
	cli_format_assert(in_array('Workspace: /workspace', WP_CLI::$logs, true), 'Table summary must identify the workspace.');
	cli_format_assert(100 === (BaseCommand::$formatted[0]['items'][0]['count'] ?? null), 'Table summary must report total inventory, not the current page.');

	foreach ( array( 'csv', 'yaml' ) as $format ) {
		cli_format_reset();
		$command->list_repos(array(), array( 'format' => $format ));
		cli_format_assert(array() === WP_CLI::$logs && array() === WP_CLI::$lines, strtoupper($format) . ' row output must not include a pagination preamble.');
		cli_format_assert($format === (BaseCommand::$formatted[0]['args']['format'] ?? null), strtoupper($format) . ' rows must use native machine serialization.');

		cli_format_reset();
		$command->list_repos(array(), array( 'summary' => true, 'format' => $format ));
		cli_format_assert(array() === WP_CLI::$logs && array() === WP_CLI::$lines, strtoupper($format) . ' summary must remain pure machine serialization.');
		cli_format_assert(100 === (BaseCommand::$formatted[0]['items'][0]['count'] ?? null), strtoupper($format) . ' summary must use complete aggregate counts.');
	}

	echo "workspace-list-cli-format-contract: ok\n";
}
