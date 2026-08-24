<?php
/**
 * Compatibility coverage for CLI worktree list machine formats.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {}
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public static array $limit_inputs = array();
		public static function normalize_workspace_list_limit( mixed $limit ): int|\WP_Error {
			self::$limit_inputs[] = $limit;
			return ( is_int($limit) || ( is_string($limit) && ctype_digit($limit) ) ) ? (int) $limit : new \WP_Error();
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }
	final class WP_Error {}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	final class WP_CLI {
		public static string $output = '';
		public static function line( string $message ): void { self::$output .= $message; }
		public static function log( string $message ): void {}
		public static function warning( string $message ): void {}
		public static function success( string $message ): void {}
		public static function error( string $message ): void { throw new \RuntimeException($message); }
	}
	final class WorktreeListAbility {
		public array $inputs = array();
		public function execute( array $input ): array {
			$this->inputs[] = $input;
			return array(
				'success' => true,
				'total' => 100,
				'returned' => 1,
				'next_cursor' => 'next',
				'fields_skipped' => array( 'status', 'disk' ),
				'worktrees' => array( array( 'handle' => 'repo@task', 'repo' => 'repo', 'branch' => 'task', 'head' => '1234567', 'owner' => array(), 'session' => array() ) ),
			);
		}
	}
	$GLOBALS['dmc_worktree_list_ability'] = new WorktreeListAbility();
	function wp_get_ability( string $name ): ?WorktreeListAbility { return 'datamachine-code/workspace-worktree-list' === $name ? $GLOBALS['dmc_worktree_list_ability'] : null; }

	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachineCode\Cli\Commands\WorkspaceCommand;

	function pagination_compat_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}
	function invoke_worktree_list( WorkspaceCommand $command, array $assoc_args ): void {
		$method = new ReflectionMethod($command, 'worktree');
		$method->invoke($command, array( 'list' ), $assoc_args);
	}

	$command = new WorkspaceCommand();
	invoke_worktree_list($command, array( 'format' => 'json' ));
	pagination_compat_assert(true === ($GLOBALS['dmc_worktree_list_ability']->inputs[0]['all'] ?? false), 'Legacy JSON must request the exhaustive row stream.');
	$legacy = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	pagination_compat_assert('repo@task' === ($legacy[0]['handle'] ?? null), 'Legacy JSON must remain a row array.');

	WP_CLI::$output = '';
	invoke_worktree_list($command, array( 'format' => 'json', 'envelope' => true ));
	pagination_compat_assert(false === ($GLOBALS['dmc_worktree_list_ability']->inputs[1]['all'] ?? true), 'Envelope JSON must retain the bounded default.');
	$envelope = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	pagination_compat_assert(100 === ($envelope['total'] ?? null) && 'next' === ($envelope['next_cursor'] ?? null), 'Envelope JSON must expose continuation metadata.');

	WP_CLI::$output = '';
	invoke_worktree_list($command, array( 'format' => 'json', 'envelope' => true, 'task-ref' => 'https://github.com/example/repo/issues/100', 'owner-run-ref' => 'cook/run/100' ));
	pagination_compat_assert('https://github.com/example/repo/issues/100' === ($GLOBALS['dmc_worktree_list_ability']->inputs[2]['task_ref'] ?? null) && 'cook/run/100' === ($GLOBALS['dmc_worktree_list_ability']->inputs[2]['owner_run_ref'] ?? null), 'CLI did not forward task and owner filters to the worktree ability.');

	WP_CLI::$output = '';
	invoke_worktree_list($command, array( 'format' => 'json', 'task-ref' => '{task_url}', 'with-status' => true ));
	pagination_compat_assert(true === ($GLOBALS['dmc_worktree_list_ability']->inputs[3]['all'] ?? false) && true === ($GLOBALS['dmc_worktree_list_ability']->inputs[3]['include_status'] ?? false) && '{task_url}' === ($GLOBALS['dmc_worktree_list_ability']->inputs[3]['task_ref'] ?? null), 'wp-coding-agents resolve_task invocation must request the complete task-scoped safety rows.');

	invoke_worktree_list($command, array( 'format' => 'csv' ));
	invoke_worktree_list($command, array( 'format' => 'yaml' ));
	pagination_compat_assert(true === ($GLOBALS['dmc_worktree_list_ability']->inputs[4]['all'] ?? false) && true === ($GLOBALS['dmc_worktree_list_ability']->inputs[5]['all'] ?? false), 'CSV and YAML must request exhaustive row streams.');

	try {
		invoke_worktree_list($command, array( 'format' => 'json', 'limit' => 10 ));
		throw new RuntimeException('Legacy JSON pagination must fail explicitly.');
	} catch (RuntimeException $error) {
		pagination_compat_assert(str_contains($error->getMessage(), '--envelope'), 'Legacy JSON pagination failure must direct callers to the envelope contract.');
	}
	DataMachineCode\Workspace\Workspace::$limit_inputs = array();
	try {
		invoke_worktree_list($command, array( 'format' => 'json', 'envelope' => true, 'limit' => '1.5' ));
		throw new RuntimeException('Worktree CLI must reject invalid limits.');
	} catch (RuntimeException $error) {
		pagination_compat_assert('1.5' === (DataMachineCode\Workspace\Workspace::$limit_inputs[0] ?? null), 'Worktree CLI must validate the raw limit before coercion.');
	}

	echo "worktree-list-cli-pagination-compatibility: ok\n";
}
