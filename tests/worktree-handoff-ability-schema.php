<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');

$GLOBALS['worktree_handoff_ability_actions'] = array();
$GLOBALS['worktree_handoff_abilities'] = array();
$GLOBALS['worktree_handoff_registering'] = false;
$GLOBALS['worktree_handoff_registered'] = false;

function add_action( string $hook, callable|array|string $callback ): void { $GLOBALS['worktree_handoff_ability_actions'][ $hook ][] = $callback; }
function doing_action( string $hook ): bool { return 'wp_abilities_api_init' === $hook && $GLOBALS['worktree_handoff_registering']; }
function did_action( string $hook ): int { return 'wp_abilities_api_init' === $hook && $GLOBALS['worktree_handoff_registered'] ? 1 : 0; }
function wp_register_ability( string $name, array $definition ): void { $GLOBALS['worktree_handoff_abilities'][ $name ] = $definition; }
function wp_has_ability( string $name ): bool { return isset($GLOBALS['worktree_handoff_abilities'][ $name ]); }

function worktree_handoff_schema_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

require_once dirname(__DIR__) . '/inc/Abilities/AbilityRegistry.php';
require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

new DataMachineCode\Abilities\WorkspaceAbilities();
$GLOBALS['worktree_handoff_registering'] = true;
foreach ( $GLOBALS['worktree_handoff_ability_actions']['wp_abilities_api_init'] ?? array() as $callback ) {
	$callback();
}
$GLOBALS['worktree_handoff_registering'] = false;
$GLOBALS['worktree_handoff_registered'] = true;

$add = $GLOBALS['worktree_handoff_abilities']['datamachine-code/workspace-worktree-add'] ?? array();
$revalidate = $GLOBALS['worktree_handoff_abilities']['datamachine-code/workspace-worktree-handoff-revalidate'] ?? array();
$fields = array( 'version', 'proof_id', 'handle', 'worktree_sha', 'resolved_base_ref', 'resolved_base_sha', 'remote_default_ref', 'remote_default_sha', 'digest' );
foreach ( array(
	$add['output_schema']['properties']['handoff_freshness_proof'] ?? array(),
	$revalidate['input_schema']['properties']['proof'] ?? array(),
	$revalidate['output_schema']['properties']['proof'] ?? array(),
) as $proof ) {
	worktree_handoff_schema_assert($fields === array_keys((array) ( $proof['properties'] ?? array() )), 'Proof schema fields are incomplete or reordered.');
	worktree_handoff_schema_assert($fields === (array) ( $proof['required'] ?? array() ), 'Proof schema does not require every bound field.');
}
worktree_handoff_schema_assert(array( 'current', 'drift', 'fetch_failed', 'contention' ) === ( $revalidate['output_schema']['properties']['status']['enum'] ?? array() ), 'Revalidation schema omitted typed statuses.');
worktree_handoff_schema_assert(array( 'invalid_worktree_handoff_proof', 'untrusted_worktree_handoff_proof', 'worktree_handoff_revalidation_timeout', 'remote_default_unresolved', 'worktree_handoff_base_unresolved' ) === ( $revalidate['output_schema']['properties']['error']['properties']['code']['enum'] ?? array() ), 'Revalidation schema omitted typed errors.');

fwrite(STDOUT, "worktree-handoff-ability-schema: ok\n");
