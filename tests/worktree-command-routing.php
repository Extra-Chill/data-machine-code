<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	final class WP_CLI {
		public static function error( string $message ): void { throw new \RuntimeException($message); }
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function wp_get_ability( string $name ): ?object { return $GLOBALS['worktree_command_abilities'][ $name ] ?? null; }

	final class WorktreeCommandFakeAbility {
		/** @var list<array<string,mixed>> */
		public array $calls = array();

		public function execute( array $input ): WP_Error {
			$this->calls[] = $input;
			return new WP_Error('test_abandoned_route', 'Recorded abandoned routing input.');
		}
	}

	function worktree_command_routing_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	define('ABSPATH', __DIR__ . '/');
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$ability = new WorktreeCommandFakeAbility();
	$bounded_ability = new WorktreeCommandFakeAbility();
	$GLOBALS['worktree_command_abilities'] = array(
		'datamachine-code/workspace-worktree-abandoned-cleanup' => $ability,
		'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => $bounded_ability,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	try {
		$command->__worktree_operation('abandoned', array( 'data-machine-code' ), array( 'format' => 'json' ));
		throw new \RuntimeException('abandoned command did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'abandoned command did not render the routed ability result.');
	}
	worktree_command_routing_assert('data-machine-code' === ( $ability->calls[0]['repo'] ?? null ), 'abandoned positional repo was not routed to its ability input.');

	try {
		$command->__worktree_operation('abandoned', array(), array( 'discard-unpushed' => true ));
		throw new \RuntimeException('abandoned --discard-unpushed did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'abandoned --discard-unpushed did not route to the owning ability.');
	}
	worktree_command_routing_assert(true === ( $ability->calls[1]['discard_unpushed'] ?? false ), 'abandoned --discard-unpushed was not forwarded to the owning ability.');

	try {
		$command->__worktree_operation('bounded-cleanup-eligible-apply', array(), array( 'scope' => 'repo:stage-finalized', 'format' => 'json' ));
		throw new \RuntimeException('bounded cleanup command did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'bounded cleanup command did not render the routed ability result.');
	}
	worktree_command_routing_assert('repo:stage-finalized' === ( $bounded_ability->calls[0]['scope'] ?? null ), 'bounded cleanup --scope was not routed to its ability input.');

	echo "worktree-command-routing: ok\n";
}
