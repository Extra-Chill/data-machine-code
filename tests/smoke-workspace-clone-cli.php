<?php
/**
 * Pure-PHP smoke for workspace clone CLI routing.
 *
 * Run: php tests/smoke-workspace-clone-cli.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__);
	}

	class WP_CLI
	{
		public static array $logs = array();
		public static array $successes = array();

		public static function error( string $message ): void
		{
			throw new RuntimeException($message);
		}

		public static function success( string $message ): void
		{
			self::$successes[] = $message;
		}

		public static function log( string $message ): void
		{
			self::$logs[] = $message;
		}
	}

	class WP_Error
	{
		public function __construct( private string $code, private string $message, private array $data = array() )
		{
		}

		public function get_error_code(): string
		{
			return $this->code;
		}

		public function get_error_message(): string
		{
			return $this->message;
		}

		public function get_error_data(): array
		{
			return $this->data;
		}
	}

	function is_wp_error( $value ): bool
	{
		return $value instanceof WP_Error;
	}

	function wp_get_ability( string $name )
	{
		return $GLOBALS['__abilities'][ $name ] ?? null;
	}
}

namespace DataMachine\Cli {
	class BaseCommand
	{
	}
}

namespace {
	include_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	final class WorkspaceCloneTestAbility
	{
		public array $input = array();

		public function execute( array $input ): array
		{
			$this->input = $input;
			return array(
				'success' => true,
				'backend' => 'github_api',
				'name'    => (string) $input['name'],
				'path'    => 'github://Extra-Chill/data-machine-code',
				'message' => 'Registered remote workspace.',
			);
		}
	}

	$failures = array();
	$assert   = function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Workspace clone CLI - smoke\n";

	$ability                                     = new WorkspaceCloneTestAbility();
	$GLOBALS['__abilities']['datamachine-code/workspace-clone'] = $ability;

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$command->clone_repo(
		array( 'https://github.com/Extra-Chill/data-machine-code.git' ),
		array(
			'name'                   => 'data-machine-code',
			'allow-duplicate-remote' => true,
		)
	);

	$assert('uses workspace clone ability', 'https://github.com/Extra-Chill/data-machine-code.git' === ( $ability->input['url'] ?? '' ));
	$assert('passes explicit clone name', 'data-machine-code' === ( $ability->input['name'] ?? '' ));
	$assert('passes duplicate remote opt-in', true === ( $ability->input['allow_duplicate_remote'] ?? false ));
	$assert('does not force full clone by default', false === ( $ability->input['full'] ?? true ));
	$assert('prints ability success message', in_array('Registered remote workspace.', WP_CLI::$successes, true));
	$assert('prints returned workspace path', in_array('Path: github://Extra-Chill/data-machine-code', WP_CLI::$logs, true));

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK\n";
}
