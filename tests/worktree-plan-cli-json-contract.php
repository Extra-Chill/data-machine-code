<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	abstract class BaseCommand {}
}

namespace {
	define('ABSPATH', __DIR__ . '/fixtures/');

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode($value, $flags, $depth);
	}

	final class WP_CLI {
		/** @var list<string> */
		public static array $lines = array();

		public static function line( string $message ): void { self::$lines[] = $message; }
		public static function log( string $message ): void { self::$lines[] = $message; }
		public static function success( string $message ): void { self::$lines[] = 'Success: ' . $message; }
	}

	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$plan = array(
		'version'     => 1,
		'handle'      => 'repo@fix-plan',
		'path'        => '/workspace/repo@fix-plan',
		'branch'      => 'fix/plan',
		'disposition' => 'create',
		'digest'      => str_repeat('a', 64),
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$render  = new \ReflectionMethod($command, 'renderWorktreeResult');
	$render->invoke($command, 'plan', $plan, array( 'format' => 'json' ));

	$decoded = json_decode(implode("\n", WP_CLI::$lines), true, 512, JSON_THROW_ON_ERROR);
	if ( $plan !== $decoded ) {
		throw new \RuntimeException('JSON worktree plan output did not preserve the typed plan envelope.');
	}

	WP_CLI::$lines = array();
	$render->invoke($command, 'plan', $plan, array());
	$output = implode("\n", WP_CLI::$lines);
	if ( ! str_contains($output, 'Success: Worktree plan generated.') || str_contains($output, 'Worktree created') ) {
		throw new \RuntimeException('Human worktree plan output did not describe the read-only operation accurately.');
	}

	echo "worktree-plan-cli-json-contract: ok\n";
}
