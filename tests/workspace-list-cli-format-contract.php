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

namespace DataMachine\Engine\AI\Tools {
	class BaseTool {
		protected function buildErrorResponse( string $message, string $tool ): array { return array( 'success' => false, 'message' => $message, 'tool_name' => $tool ); }
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public static array $limit_inputs = array();
		public static function normalize_workspace_list_limit( mixed $limit ): int|\WP_Error {
			self::$limit_inputs[] = $limit;
			if ( ! is_int($limit) && ! ( is_string($limit) && ctype_digit($limit) ) ) { return new \WP_Error('invalid limit'); }
			return (int) $limit;
		}
	}
	class WorkspaceAliasResolver {}
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
	require_once dirname(__DIR__) . '/inc/Tools/WorkspaceTools.php';
	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

	use DataMachine\Cli\BaseCommand;
	use DataMachineCode\Cli\Commands\WorkspaceCommand;
	use DataMachineCode\Tools\WorkspaceTools;
	use DataMachineCode\Abilities\WorkspaceAbilities;
	use DataMachineCode\Workspace\Workspace;

	final class WorkspaceListToolContract extends WorkspaceTools { public function __construct() {} }

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
	$tool = new WorkspaceListToolContract();

	foreach ( array( '1.5', 'junk', array( 1 ), true ) as $invalid_limit ) {
		Workspace::$limit_inputs = array();
		try {
			$command->list_repos(array(), array( 'limit' => $invalid_limit ));
			throw new RuntimeException('CLI must reject invalid limits.');
		} catch ( RuntimeException $error ) {
			cli_format_assert($invalid_limit === (Workspace::$limit_inputs[0] ?? null), 'CLI must validate the raw limit before coercion.');
		}
		Workspace::$limit_inputs = array();
		$tool_result = $tool->handleList(array( 'limit' => $invalid_limit ));
		cli_format_assert(false === ($tool_result['success'] ?? true) && $invalid_limit === (Workspace::$limit_inputs[0] ?? null), 'Tool must validate the raw limit before coercion.');
		Workspace::$limit_inputs = array();
		$ability_result = WorkspaceAbilities::listRepos(array( 'limit' => $invalid_limit ));
		cli_format_assert(is_wp_error($ability_result) && $invalid_limit === (Workspace::$limit_inputs[0] ?? null), 'Ability must validate the raw limit before coercion.');
		Workspace::$limit_inputs = array();
		$worktree_ability_result = WorkspaceAbilities::worktreeList(array( 'limit' => $invalid_limit ));
		cli_format_assert(is_wp_error($worktree_ability_result) && $invalid_limit === (Workspace::$limit_inputs[0] ?? null), 'Worktree ability must validate the raw limit before coercion.');
	}

	cli_format_reset();
	$command->list_repos(array(), array( 'format' => 'json' ));
	$rows_json = json_decode(WP_CLI::$lines[0] ?? '', true);
	cli_format_assert(2 === count($rows_json) && 'repo-a' === ($rows_json[0]['name'] ?? null), 'Default JSON must preserve the legacy row array.');

	cli_format_reset();
	$command->list_repos(array(), array( 'format' => 'json', 'envelope' => true ));
	$envelope_json = json_decode(WP_CLI::$lines[0] ?? '', true);
	cli_format_assert(100 === ($envelope_json['total'] ?? null) && 'cursor-2' === ($envelope_json['next_cursor'] ?? null), 'Envelope JSON must explicitly expose pagination metadata.');

	cli_format_reset();
	$command->list_repos(array(), array( 'summary' => true, 'format' => 'json' ));
	$summary_json = json_decode(WP_CLI::$lines[0] ?? '', true);
	cli_format_assert(100 === ($summary_json['total'] ?? null) && ! isset($summary_json['repos'][0]['name']), 'Summary JSON must serialize the full aggregate summary, not the current page.');
	cli_format_assert(2 === ($summary_json['returned'] ?? null) && 'cursor-2' === ($summary_json['next_cursor'] ?? null), 'Summary JSON must retain page continuation metadata.');

	cli_format_reset();
	$command->list_repos(array(), array( 'summary' => true ));
	cli_format_assert(in_array('Workspace: /workspace', WP_CLI::$logs, true), 'Table summary must identify the workspace.');
	cli_format_assert(100 === (BaseCommand::$formatted[0]['items'][0]['count'] ?? null), 'Table summary must report total inventory, not the current page.');

	$GLOBALS['dmc_workspace_list_ability'] = new WorkspaceListAbility(array_merge($result, array( 'total' => null, 'partial' => true, 'diagnostics' => array( 'scan_elapsed_seconds' => 5.0, 'budget_exhaustion_reason' => 'scan_budget_exhausted' ) )));
	cli_format_reset();
	$command->list_repos(array(), array());
	cli_format_assert(str_contains(WP_CLI::$logs[0] ?? '', 'totals incomplete after 5.00s (scan_budget_exhausted)'), 'Partial table output must not coerce an unknown total to zero.');
	$GLOBALS['dmc_workspace_list_ability'] = new WorkspaceListAbility($result);

	foreach ( array( 'csv', 'yaml' ) as $format ) {
		cli_format_reset();
		$command->list_repos(array(), array( 'format' => $format ));
		cli_format_assert(array() === WP_CLI::$logs && array() === WP_CLI::$lines, strtoupper($format) . ' row output must not include a pagination preamble.');
		cli_format_assert($format === (BaseCommand::$formatted[0]['args']['format'] ?? null), strtoupper($format) . ' rows must use native machine serialization.');

		cli_format_reset();
		$command->list_repos(array(), array( 'summary' => true, 'format' => $format ));
		cli_format_assert(array() === WP_CLI::$logs && array() === WP_CLI::$lines, strtoupper($format) . ' summary must remain pure machine serialization.');
		cli_format_assert(100 === (BaseCommand::$formatted[0]['items'][0]['count'] ?? null), strtoupper($format) . ' summary must use complete aggregate counts.');

		$GLOBALS['dmc_workspace_list_ability'] = new WorkspaceListAbility(array_merge($result, array( 'repos' => array(), 'returned' => 0, 'next_cursor' => null )));
		cli_format_reset();
		$command->list_repos(array(), array( 'format' => $format ));
		cli_format_assert(array() === WP_CLI::$logs && array() === WP_CLI::$lines, strtoupper($format) . ' empty output must not include human text.');
		cli_format_assert(array() === (BaseCommand::$formatted[0]['items'] ?? null), strtoupper($format) . ' empty output must use native machine serialization.');
		$GLOBALS['dmc_workspace_list_ability'] = new WorkspaceListAbility($result);
	}

	echo "workspace-list-cli-format-contract: ok\n";
}
