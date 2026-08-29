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
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$ability = new WorktreeCommandFakeAbility();
	$active_drain_ability = new WorktreeCommandFakeAbility();
	$bounded_ability = new WorktreeCommandFakeAbility();
	$artifact_ability = new WorktreeCommandFakeAbility();
	$cleanup_eligible_drain_ability = new WorktreeCommandFakeAbility();
	$handoff_resume_ability = new WorktreeCommandFakeAbility();
	$attach_tracker_ability = new WorktreeCommandFakeAbility();
	$prune_ability = new WorktreeCommandFakeAbility();
	$GLOBALS['worktree_command_abilities'] = array(
		'datamachine-code/workspace-worktree-abandoned-cleanup' => $ability,
		'datamachine-code/workspace-worktree-active-no-signal-drain' => $active_drain_ability,
		'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => $bounded_ability,
		'datamachine-code/workspace-worktree-cleanup-artifacts' => $artifact_ability,
		'datamachine-code/workspace-worktree-cleanup-eligible-drain' => $cleanup_eligible_drain_ability,
		'datamachine-code/workspace-worktree-handoff-resume' => $handoff_resume_ability,
		'datamachine-code/workspace-worktree-attach-tracker' => $attach_tracker_ability,
		'datamachine-code/workspace-worktree-prune' => $prune_ability,
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
		$command->__worktree_operation('active-no-signal-drain', array(), array( 'apply' => true, 'limit' => '100', 'passes' => '2', 'until-budget' => '300s', 'format' => 'json' ));
		throw new \RuntimeException('unscoped active/no-signal drain did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'unscoped active/no-signal drain did not render the routed ability result.');
	}
	worktree_command_routing_assert(! array_key_exists('repo', $active_drain_ability->calls[0]), 'unscoped active/no-signal drain must omit repo from ability input.');
	worktree_command_routing_assert(true === ( $active_drain_ability->calls[0]['apply'] ?? false ), 'unscoped active/no-signal drain lost --apply.');
	worktree_command_routing_assert('100' === ( $active_drain_ability->calls[0]['limit'] ?? null ), 'unscoped active/no-signal drain lost --limit.');
	worktree_command_routing_assert('2' === ( $active_drain_ability->calls[0]['passes'] ?? null ), 'unscoped active/no-signal drain lost --passes.');
	worktree_command_routing_assert('300s' === ( $active_drain_ability->calls[0]['until_budget'] ?? null ), 'unscoped active/no-signal drain lost --until-budget.');

	try {
		$command->__worktree_operation('active-no-signal-drain', array( 'data-machine-code' ), array( 'apply' => true, 'format' => 'json' ));
		throw new \RuntimeException('scoped active/no-signal drain did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'scoped active/no-signal drain did not render the routed ability result.');
	}
	worktree_command_routing_assert('data-machine-code' === ( $active_drain_ability->calls[1]['repo'] ?? null ), 'active/no-signal drain positional repo was not routed to its ability input.');
	worktree_command_routing_assert('data-machine-code' === ( $active_drain_ability->calls[1]['scope'] ?? null ), 'active/no-signal drain positional repo did not preserve continuation scope.');

	try {
		$command->__worktree_operation('bounded-cleanup-eligible-apply', array(), array( 'scope' => 'repo:stage-finalized', 'format' => 'json' ));
		throw new \RuntimeException('bounded cleanup command did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'bounded cleanup command did not render the routed ability result.');
	}
	worktree_command_routing_assert('repo:stage-finalized' === ( $bounded_ability->calls[0]['scope'] ?? null ), 'bounded cleanup --scope was not routed to its ability input.');

	$retry_command = "studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run --safety-probes --limit=1 --only-handle='repo@blocked' --format=json";
	$artifact_definition = \DataMachineCode\Cli\Commands\WorkspaceCommand::worktree_command_definitions()['cleanup-artifacts'];
	$registered_options = array_column($artifact_definition['synopsis'], 'name');
	preg_match_all("/(?:[^\\s']+|'[^']*')+/", $retry_command, $matches);
	$parsed_args = array();
	foreach ( array_slice($matches[0], 6) as $token ) {
		if ( ! str_starts_with($token, '--') ) {
			continue;
		}
		$parts = explode('=', substr($token, 2), 2);
		worktree_command_routing_assert(in_array($parts[0], $registered_options, true), sprintf('generated artifact retry command uses unregistered --%s.', $parts[0]));
		$parsed_args[ $parts[0] ] = isset($parts[1]) ? trim($parts[1], "'") : true;
	}
	try {
		$command->__worktree_operation('cleanup-artifacts', array(), $parsed_args);
		throw new \RuntimeException('artifact retry command did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), sprintf('generated artifact retry command did not dispatch: %s', $retry_command));
	}
	worktree_command_routing_assert('repo@blocked' === ( $artifact_ability->calls[0]['only_handle'] ?? null ), 'generated artifact retry command did not preserve its exact worktree handle.');
	worktree_command_routing_assert(1 === ( $artifact_ability->calls[0]['limit'] ?? null ), 'generated artifact retry command did not preserve its one-worktree limit.');

	try {
		$command->__worktree_operation('cleanup-eligible-drain', array(), array( 'apply' => true, 'limit' => '25', 'passes' => '2', 'verbose' => true, 'format' => 'json' ));
		throw new \RuntimeException('cleanup-eligible drain command did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'cleanup-eligible drain --verbose did not route to the owning ability.');
	}
	worktree_command_routing_assert(true === ( $cleanup_eligible_drain_ability->calls[0]['apply'] ?? false ), 'cleanup-eligible drain lost --apply.');
	worktree_command_routing_assert(25 === ( $cleanup_eligible_drain_ability->calls[0]['limit'] ?? null ), 'cleanup-eligible drain lost --limit.');
	worktree_command_routing_assert(2 === ( $cleanup_eligible_drain_ability->calls[0]['passes'] ?? null ), 'cleanup-eligible drain lost --passes.');

	$allocation_identity = array(
		'version'           => 1,
		'allocation_id'     => 'allocation-1205',
		'handle'            => 'data-machine-code@fix-1205',
		'path'              => '/workspace/data-machine-code@fix-1205',
		'branch'            => 'fix/1205',
		'worktree_sha'      => str_repeat('a', 40),
		'resolved_base_ref' => 'origin/main',
		'digest'            => str_repeat('b', 64),
	);
	try {
		$command->__worktree_operation('handoff-resume', array( 'data-machine-code@fix-1205' ), array( 'allocation-identity' => json_encode($allocation_identity) ));
		throw new \RuntimeException('handoff resume command did not execute its owning ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'handoff resume command did not render the routed ability result.');
	}
	worktree_command_routing_assert('data-machine-code@fix-1205' === ( $handoff_resume_ability->calls[0]['handle'] ?? null ), 'handoff resume lost the exact committed handle.');
	worktree_command_routing_assert($allocation_identity === ( $handoff_resume_ability->calls[0]['allocation_identity'] ?? null ), 'handoff resume lost the exact server-issued allocation identity.');

	try {
		$command->__worktree_operation('attach-tracker', array( 'data-machine-code@feat-1221' ), array( 'task-url' => 'https://github.com/Extra-Chill/data-machine-code/issues/1221', 'dry-run' => true ));
		throw new \RuntimeException('attach tracker command did not execute its owning ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'attach tracker command did not render the routed ability result.');
	}
	worktree_command_routing_assert('data-machine-code@feat-1221' === ( $attach_tracker_ability->calls[0]['handle'] ?? null ), 'attach tracker lost the exact managed handle.');
	worktree_command_routing_assert('https://github.com/Extra-Chill/data-machine-code/issues/1221' === ( $attach_tracker_ability->calls[0]['task_url'] ?? null ), 'attach tracker lost the task URL.');
	worktree_command_routing_assert(true === ( $attach_tracker_ability->calls[0]['dry_run'] ?? false ), 'attach tracker lost the dry-run preview input.');

	try {
		$command->__worktree_operation('prune', array(), array( 'dry-run' => true, 'until-budget' => '10s', 'format' => 'json' ));
		throw new \RuntimeException('prune command did not execute its ability.');
	} catch ( \RuntimeException $error ) {
		worktree_command_routing_assert('Recorded abandoned routing input.' === $error->getMessage(), 'prune --dry-run did not route to the owning ability.');
	}
	worktree_command_routing_assert(true === ( $prune_ability->calls[0]['dry_run'] ?? false ), 'prune lost the dry-run preview input.');
	worktree_command_routing_assert('10s' === ( $prune_ability->calls[0]['until_budget'] ?? null ), 'prune lost the wall-clock budget input.');

	echo "worktree-command-routing: ok\n";
}
