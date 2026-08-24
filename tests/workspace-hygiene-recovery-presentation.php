<?php
/**
 * Covers the public recovery contract in schema and default table output.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	abstract class BaseCommand {
		public static array $tables = array();

		protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {
			self::$tables[] = array( 'items' => $items, 'fields' => $fields );
		}

	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	class WP_CLI {
		public static array $logs = array();

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}
	}

	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function recovery_presentation_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	$ability_source = file_get_contents(dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php');
	recovery_presentation_assert(false !== $ability_source && 1 === preg_match("/'recovery'\\s*=>\\s*array\\(\\s*'type'\\s*=>\\s*'object'\\s*\\)/", $ability_source), 'Hygiene ability schema must declare recovery as an object.');
	recovery_presentation_assert(! str_contains((string) $ability_source, "'suggested_cleanup_command' => array( 'type' => 'string' )"), 'Hygiene ability schema must not retain independent cleanup suggestions.');

	$command = new DataMachineCode\Cli\Commands\WorkspaceCommand();
	$render  = new ReflectionMethod($command, 'render_workspace_hygiene_report');
	$report  = array(
		'workspace_path' => '/workspace',
		'size'           => array(),
		'disk'           => array(),
		'inventory'      => array( 'freshness' => array() ),
		'worktrees'      => array( 'by_liveness' => array() ),
		'locks'          => array(),
		'cleanup'        => array( 'summary' => array() ),
		'recovery'       => array(
			'status'         => 'warning',
			'lanes'          => array( 'cleanup' => 'unknown', 'stale_locks' => 'attention' ),
			'commands'       => array( array( 'label' => 'Stale-lock preview', 'command' => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json' ) ),
			'detail_command' => 'wp datamachine-code workspace hygiene --format=json',
		),
	);
	$render->invoke($command, $report, array( 'format' => 'table' ));

	$lane_table = end(DataMachine\Cli\BaseCommand::$tables);
	recovery_presentation_assert(array( 'lane', 'state' ) === ( $lane_table['fields'] ?? null ), 'Default hygiene table must render recovery lanes.');
	recovery_presentation_assert('unknown' === ( $lane_table['items'][0]['state'] ?? null ), 'Default hygiene table must preserve unknown lane state.');
	recovery_presentation_assert('attention' === ( $lane_table['items'][1]['state'] ?? null ), 'Default hygiene table must preserve observed attention lane state.');
	recovery_presentation_assert(in_array('Stale-lock preview: wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json', WP_CLI::$logs, true), 'Default hygiene table must render shared recovery commands.');
	recovery_presentation_assert(! in_array('Suggested cleanup review:', WP_CLI::$logs, true), 'Default hygiene table must not render legacy cleanup guidance.');

	echo "workspace-hygiene-recovery-presentation: ok\n";
}
