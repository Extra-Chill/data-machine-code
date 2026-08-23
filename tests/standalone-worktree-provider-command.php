<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_CLI {
		public static string $output = '';
		public static function line( string $message ): void { self::$output .= $message; }
		public static function error( string $message ): void { throw new \RuntimeException($message); }
	}

	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode($value, $flags); }

	function standalone_provider_command_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	define('ABSPATH', __DIR__ . '/');
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$executable = \DataMachineCode\Cli\Commands\WorkspaceCommand::standalone_worktree_provider_executable();
	$expected   = realpath(dirname(__DIR__) . '/bin/dmc-worktree-provider');
	standalone_provider_command_assert(false !== $expected, 'Standalone provider fixture is missing its executable.');
	standalone_provider_command_assert($expected === $executable, 'Provider command did not resolve the executable from the DMC source contract.');
	standalone_provider_command_assert(is_file($executable), 'Provider command returned a non-file executable.');

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$command->__worktree_operation('provider', array(), array( 'format' => 'json' ));
	$payload = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	standalone_provider_command_assert('datamachine-code/standalone-worktree-provider-command/v1' === ( $payload['schema'] ?? null ), 'Provider command emitted an unexpected schema.');
	standalone_provider_command_assert($executable === ( $payload['executable'] ?? null ), 'Provider command did not emit its resolved executable.');

	echo "standalone-worktree-provider-command: ok\n";
}
