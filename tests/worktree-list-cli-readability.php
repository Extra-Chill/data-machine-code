<?php
/**
 * User-facing coverage for readable worktree list output.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace DataMachineCode\Workspace {
	class Workspace {
		public function sanitize_repo_name( string $name ): string { return preg_replace('/[^a-zA-Z0-9._-]/', '', $name); }
		public static function normalize_workspace_list_limit( mixed $limit ): int|\WP_Error { return (int) $limit; }
	}
}

namespace WP_CLI\Utils {
	function format_items( string $format, array $items, array $fields ): void {
		$GLOBALS['dmc_readability_formats'][] = compact('format', 'items', 'fields');
		if ( 'table' !== $format ) {
			return;
		}
		\WP_CLI::line(implode("\t", $fields));
		foreach ( $items as $item ) {
			\WP_CLI::line(implode("\t", array_map(static fn( string $field ): string => (string) ( $item[ $field ] ?? '' ), $fields)));
		}
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/fixtures/');

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

	final class WP_Error {}
	final class WP_CLI {
		public static array $lines = array();
		public static array $logs = array();
		public static function line( string $message ): void { self::$lines[] = $message; }
		public static function log( string $message ): void { self::$logs[] = $message; }
		public static function warning( string $message ): void { self::$logs[] = $message; }
		public static function error( string $message ): void { throw new RuntimeException($message); }
	}

	final class ReadableWorktreeListAbility {
		public array $inputs = array();
		public function execute( array $input ): array {
			$this->inputs[] = $input;
			$managed = array(
				'handle' => 'homeboy@fix-13702-envelope-truthfulness',
				'repo' => 'homeboy',
				'is_worktree' => true,
				'branch' => 'fix/13702-envelope-truthfulness',
				'head' => '252ba9f123456789',
				'lifecycle_state' => 'active',
				'liveness' => 'live',
				'task' => array( 'task_url' => 'https://github.com/Extra-Chill/homeboy/issues/13702' ),
				'owner' => array( 'agent' => 'intelligence-chubes4' ),
				'metadata' => array( 'lifecycle_state' => 'active' ),
				'path' => '/Users/chubes/Developer/homeboy@fix-13702-envelope-truthfulness',
			);
			$debris_path = '/Users/chubes/.local/share/homeboy/controller-scratch/attempts/task/workspace';
			$debris = array(
				'handle' => $debris_path,
				'repo' => 'homeboy',
				'is_worktree' => true,
				'external' => true,
				'branch' => null,
				'head' => '0e9f140123456789',
				'liveness' => 'unknown',
				'metadata' => null,
				'path' => $debris_path,
			);
			$rows = ! empty($input['include_unmanaged']) ? array( $managed, $debris ) : array( $managed );
			return array( 'success' => true, 'total' => count($rows), 'returned' => count($rows), 'next_cursor' => null, 'fields_skipped' => array( 'status', 'disk' ), 'worktrees' => $rows );
		}
	}

	$GLOBALS['dmc_readability_ability'] = new ReadableWorktreeListAbility();
	$GLOBALS['dmc_readability_formats'] = array();
	function wp_get_ability( string $name ): ?ReadableWorktreeListAbility { return 'datamachine-code/workspace-worktree-list' === $name ? $GLOBALS['dmc_readability_ability'] : null; }

	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachineCode\Cli\Commands\WorkspaceCommand;

	function readability_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}
	function invoke_readable_list( WorkspaceCommand $command, array $assoc_args ): void {
		WP_CLI::$lines = array();
		WP_CLI::$logs = array();
		$GLOBALS['dmc_readability_formats'] = array();
		(new ReflectionMethod($command, 'worktree'))->invoke($command, array( 'list', 'homeboy' ), $assoc_args);
	}

	$command = new WorkspaceCommand();
	invoke_readable_list($command, array());
	readability_assert(false === ($GLOBALS['dmc_readability_ability']->inputs[0]['include_unmanaged'] ?? true), 'Default table must request managed inventory only.');
	readability_assert("handle\tbranch\thead\tlifecycle\tactivity\ttask\tpath" === (WP_CLI::$lines[0] ?? null), 'Default output must have a compact, meaningful header.');
	readability_assert(2 === count(WP_CLI::$lines) && ! str_contains(implode("\n", WP_CLI::$lines), 'controller-scratch'), 'Default output must omit controller-scratch debris.');

	invoke_readable_list($command, array( 'include-unmanaged' => true ));
	$unmanaged_output = implode("\n", WP_CLI::$lines);
	readability_assert(true === ($GLOBALS['dmc_readability_ability']->inputs[1]['include_unmanaged'] ?? false), '--include-unmanaged must request debris rows.');
	readability_assert(1 === substr_count($unmanaged_output, '/Users/chubes/.local/share/homeboy/controller-scratch/attempts/task/workspace'), 'An unmanaged row must print its path only once.');
	readability_assert(str_contains($unmanaged_output, "-\t-\t0e9f140\t-\t-\t-\t/Users/chubes/.local"), 'Unmanaged empty values must consistently render as dashes.');

	invoke_readable_list($command, array( 'format' => 'json' ));
	$json = json_decode(WP_CLI::$lines[0] ?? '', true, 512, JSON_THROW_ON_ERROR);
	readability_assert(true === ($GLOBALS['dmc_readability_ability']->inputs[2]['include_unmanaged'] ?? false) && 2 === count($json), 'JSON must retain unmanaged rows.');
	readability_assert(array( 'lifecycle_state' => 'active' ) === ($json[0]['metadata'] ?? null) && isset($json[0]['owner_full'], $json[0]['task_full'], $json[0]['path']), 'JSON must retain the complete structured field set.');

	invoke_readable_list($command, array( 'format' => 'csv' ));
	$csv_fields = $GLOBALS['dmc_readability_formats'][0]['fields'] ?? array();
	readability_assert(in_array('metadata', $csv_fields, true) && in_array('owner_full', $csv_fields, true) && 'path' === end($csv_fields), 'CSV must retain the complete machine field set.');

	echo "worktree-list-cli-readability: ok\n";
}
