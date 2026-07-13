<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class Worktree_Get_Cli_Halt extends \RuntimeException {
		public function __construct( public readonly int $status ) {
			parent::__construct('WP-CLI halted.');
		}
	}

	final class WP_CLI {
		/** @var list<string> */
		public static array $lines = array();

		public static function line( string $message ): void {
			self::$lines[] = $message;
		}

		public static function halt( int $status ): never {
			throw new Worktree_Get_Cli_Halt($status);
		}
	}

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode($value, $flags, $depth);
	}

	define('ABSPATH', __DIR__ . '/fixtures/');
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$method  = new \ReflectionMethod($command, 'renderWorktreeResult');

	try {
		$method->invoke($command, 'get', array( 'worktrees' => array() ), array( 'format' => 'json' ));
		throw new \RuntimeException('JSON worktree get did not halt for a missing handle.');
	} catch (Worktree_Get_Cli_Halt $halt) {
		if ( 1 !== $halt->status ) {
			throw new \RuntimeException(sprintf('Unexpected JSON worktree get exit status: %d.', $halt->status));
		}
	}

	$output = implode("\n", WP_CLI::$lines);
	$payload = json_decode($output, true);
	if ( ! is_array($payload) ) {
		throw new \RuntimeException('JSON worktree get not-found output was not machine-readable JSON.');
	}
	if ( false !== ( $payload['success'] ?? null ) || 'worktree_not_found' !== ( $payload['error']['code'] ?? null ) ) {
		throw new \RuntimeException('JSON worktree get not-found output did not return the typed error envelope.');
	}

	echo "worktree-get-cli-json-contract: ok\n";
}
