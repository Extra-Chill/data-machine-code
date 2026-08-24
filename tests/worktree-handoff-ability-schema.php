<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');

$GLOBALS['worktree_handoff_ability_actions'] = array();
$GLOBALS['worktree_handoff_abilities'] = array();
$GLOBALS['worktree_handoff_registered_abilities'] = array();
$GLOBALS['worktree_handoff_registering'] = false;
$GLOBALS['worktree_handoff_registered'] = false;
$GLOBALS['worktree_handoff_next_output'] = null;

final class Worktree_Handoff_Ability_Error {
	public function __construct( private string $code ) {}

	public function get_error_code(): string {
		return $this->code;
	}
}

final class Worktree_Handoff_Registered_Ability {
	public function __construct( private array $definition ) {}

	public function execute(): mixed {
		$output = $GLOBALS['worktree_handoff_next_output'];
		return worktree_handoff_schema_valid($output, $this->definition['output_schema'] ?? array())
			? $output
			: new Worktree_Handoff_Ability_Error('ability_invalid_output');
	}
}

function add_action( string $hook, callable|array|string $callback ): void { $GLOBALS['worktree_handoff_ability_actions'][ $hook ][] = $callback; }
function doing_action( string $hook ): bool { return 'wp_abilities_api_init' === $hook && $GLOBALS['worktree_handoff_registering']; }
function did_action( string $hook ): int { return 'wp_abilities_api_init' === $hook && $GLOBALS['worktree_handoff_registered'] ? 1 : 0; }
function wp_register_ability( string $name, array $definition ): void {
	$GLOBALS['worktree_handoff_abilities'][ $name ] = $definition;
	$GLOBALS['worktree_handoff_registered_abilities'][ $name ] = new Worktree_Handoff_Registered_Ability($definition);
}
function wp_has_ability( string $name ): bool { return isset($GLOBALS['worktree_handoff_abilities'][ $name ]); }
function wp_get_ability( string $name ): ?Worktree_Handoff_Registered_Ability { return $GLOBALS['worktree_handoff_registered_abilities'][ $name ] ?? null; }

function worktree_handoff_schema_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

/** Validate the registered schema subset used by WP_Ability::execute(). */
function worktree_handoff_schema_valid( mixed $value, array $schema ): bool {
	if ( isset($schema['oneOf']) ) {
		$matches = 0;
		foreach ( $schema['oneOf'] as $candidate ) {
			if ( ! isset($candidate['type']) && isset($schema['type']) ) {
				$candidate['type'] = $schema['type'];
			}
			$matches += worktree_handoff_schema_valid($value, $candidate) ? 1 : 0;
		}
		if ( 1 !== $matches ) {
			return false;
		}
	}

	$types = (array) ($schema['type'] ?? array());
	$type = null;
	foreach ( $types as $candidate ) {
		$matches = match ( $candidate ) {
			'null' => null === $value,
			'object' => is_array($value) || is_object($value),
			'array' => is_array($value) && array_is_list($value),
			'string' => is_string($value),
			'integer' => is_int($value),
			'boolean' => is_bool($value),
			default => false,
		};
		if ( $matches ) {
			$type = $candidate;
			break;
		}
	}
	if ( null === $type ) {
		return false;
	}
	if ( isset($schema['enum']) && ! in_array($value, $schema['enum'], true) ) {
		return false;
	}
	if ( 'null' === $type ) {
		return true;
	}
	if ( 'object' === $type ) {
		$value = (array) $value;
		foreach ( $schema['required'] ?? array() as $required ) {
			if ( ! array_key_exists($required, $value) ) {
				return false;
			}
		}
		foreach ( $value as $property => $property_value ) {
			if ( isset($schema['properties'][ $property ]) && ! worktree_handoff_schema_valid($property_value, $schema['properties'][ $property ]) ) {
				return false;
			}
		}
	}
	if ( 'array' === $type ) {
		if ( isset($schema['minItems']) && count($value) < $schema['minItems'] ) {
			return false;
		}
		if ( isset($schema['maxItems']) && count($value) > $schema['maxItems'] ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( isset($schema['items']) && ! worktree_handoff_schema_valid($item, $schema['items']) ) {
				return false;
			}
		}
	}

	return true;
}

function worktree_handoff_execute_output( Worktree_Handoff_Registered_Ability $ability, array $output ): mixed {
	$GLOBALS['worktree_handoff_next_output'] = $output;
	return $ability->execute();
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
$fields = array( 'version', 'proof_id', 'handle', 'worktree_sha', 'resolved_base_ref', 'resolved_base_sha', 'remote_default_ref', 'remote_default_sha', 'remote_default_advertised_sha', 'verified_at', 'digest' );
$freshness = $add['output_schema']['properties']['handoff_freshness'] ?? array();
worktree_handoff_schema_assert(array( 'success', 'handoff_freshness' ) === (array) ( $add['output_schema']['required'] ?? array() ), 'Add schema does not require the handoff freshness contract on successful output.');
worktree_handoff_schema_assert(array( 'status', 'proof', 'reason', 'error_code' ) === array_keys((array) ( $freshness['properties'] ?? array() )), 'Add schema omitted the uniform handoff freshness contract.');
worktree_handoff_schema_assert(array( 'status' ) === (array) ( $freshness['required'] ?? array() ), 'Add schema does not require a handoff freshness status.');
worktree_handoff_schema_assert(array( 'verified', 'unverified', 'not_applicable' ) === ( $freshness['properties']['status']['enum'] ?? array() ), 'Add schema omitted typed handoff freshness statuses.');
worktree_handoff_schema_assert(array( 'allocation_identity_missing', 'fetch_failed', 'worktree_handoff_revalidation_timeout', 'remote_default_unresolved', 'worktree_handoff_base_unresolved', 'proof_generation_failed', 'metadata_persist_failed', 'remote_freshness_probe_unsupported', 'non_allocation_dry_run' ) === ( $freshness['properties']['reason']['enum'] ?? array() ), 'Add schema omitted typed unverified freshness reasons.');
foreach ( array(
	$freshness['properties']['proof'] ?? array(),
	$revalidate['input_schema']['properties']['proof'] ?? array(),
	$revalidate['output_schema']['properties']['proof'] ?? array(),
) as $proof ) {
	worktree_handoff_schema_assert($fields === array_keys((array) ( $proof['properties'] ?? array() )), 'Proof schema fields are incomplete or reordered.');
	worktree_handoff_schema_assert($fields === (array) ( $proof['required'] ?? array() ), 'Proof schema does not require every bound field.');
}
worktree_handoff_schema_assert(array( 3 ) === ( $freshness['properties']['proof']['properties']['version']['enum'] ?? array() ), 'Proof schema does not declare its version 3 compatibility boundary.');
worktree_handoff_schema_assert(array( 'current', 'drift', 'fetch_failed', 'contention' ) === ( $revalidate['output_schema']['properties']['status']['enum'] ?? array() ), 'Revalidation schema omitted typed statuses.');
worktree_handoff_schema_assert(array( 'invalid_worktree_handoff_proof', 'untrusted_worktree_handoff_proof', 'worktree_handoff_revalidation_timeout', 'remote_default_unresolved', 'remote_default_changed_during_verification', 'worktree_handoff_base_unresolved' ) === ( $revalidate['output_schema']['properties']['error']['properties']['code']['enum'] ?? array() ), 'Revalidation schema omitted typed errors.');

$plan_ability = wp_get_ability('datamachine-code/workspace-worktree-plan');
worktree_handoff_schema_assert($plan_ability instanceof Worktree_Handoff_Registered_Ability, 'Worktree plan was not registered as an executable ability.');
$lineage = array(
	'old_handle' => 'repo@legacy',
	'old_owner' => array( 'owner_run_ref' => null, 'origin_session' => null, 'classification' => 'unknown_legacy' ),
	'new_owner' => array( 'owner_run_ref' => 'run/1204', 'purpose' => 'issue-1204' ),
	'task_identity' => 'https://example.test/issues/1204',
);
$legacy_handoff = array(
	'type' => 'legacy_handoff',
	'status' => 'legacy_handoff_required',
	'candidate' => array( 'handle' => 'repo@legacy', 'path' => '/workspace/repo@legacy', 'branch' => 'fix/legacy', 'head' => 'abc123', 'dirty' => 0, 'unpushed' => 0, 'liveness' => 'stale', 'is_primary' => false ),
	'task_identity' => 'https://example.test/issues/1204',
	'owner' => $lineage['old_owner'],
	'runtime_delta' => array(
		'stored' => array( 'inject_context' => false, 'bootstrap' => false ),
		'requested' => array( 'inject_context' => true, 'bootstrap' => true ),
	),
	'checks' => array_fill_keys( array( 'same_repository', 'same_task', 'non_primary', 'clean', 'pushed', 'stopped_or_stale', 'unlocked', 'no_active_process', 'candidate_verifiable', 'runtime_mismatch' ), true ),
	'vetoes' => array(),
	'lineage' => $lineage,
	'actions' => array(
		array( 'type' => 'adopt_runtime', 'ability' => 'datamachine-code/workspace-worktree-legacy-handoff-apply', 'mode' => 'adopt_runtime', 'old_handle' => 'repo@legacy', 'lineage' => $lineage ),
		array( 'type' => 'replace_isolated', 'ability' => 'datamachine-code/workspace-worktree-legacy-handoff-apply', 'mode' => 'replace_isolated', 'old_handle' => 'repo@legacy', 'terminal_classification' => 'superseded', 'lineage' => $lineage ),
	),
);
$base_plan = array(
	'digest' => str_repeat('a', 64),
	'apply_intent' => array( 'repo' => 'repo', 'branch' => 'fix/1204' ),
	'apply' => array( 'ability' => 'datamachine-code/workspace-worktree-apply-plan' ),
);
$refused_handoff = $legacy_handoff;
$refused_handoff['status'] = 'legacy_handoff_refused';
$refused_handoff['checks']['runtime_mismatch'] = false;
$refused_handoff['vetoes'] = array( 'runtime_mismatch' );
$refused_handoff['actions'] = array();

foreach ( array(
	'create' => null,
	'exact_reuse' => $refused_handoff,
	'owner_conflict' => $refused_handoff,
	'legacy_handoff_required' => $legacy_handoff,
) as $disposition => $handoff ) {
	$output = $base_plan + array( 'disposition' => $disposition, 'legacy_handoff' => $handoff );
	worktree_handoff_schema_assert(! worktree_handoff_execute_output($plan_ability, $output) instanceof Worktree_Handoff_Ability_Error, sprintf('%s output failed registered ability validation.', $disposition));
}

$missing_handoff = $base_plan + array( 'disposition' => 'legacy_handoff_required', 'legacy_handoff' => null );
worktree_handoff_schema_assert('ability_invalid_output' === worktree_handoff_execute_output($plan_ability, $missing_handoff)->get_error_code(), 'Legacy handoff disposition accepted a null handoff.');
$incomplete_handoff = $legacy_handoff;
unset($incomplete_handoff['checks']);
$incomplete_plan = $base_plan + array( 'disposition' => 'legacy_handoff_required', 'legacy_handoff' => $incomplete_handoff );
worktree_handoff_schema_assert('ability_invalid_output' === worktree_handoff_execute_output($plan_ability, $incomplete_plan)->get_error_code(), 'Legacy handoff disposition accepted incomplete safety evidence.');
$misclassified_plan = $base_plan + array( 'disposition' => 'owner_conflict', 'legacy_handoff' => $legacy_handoff );
worktree_handoff_schema_assert('ability_invalid_output' === worktree_handoff_execute_output($plan_ability, $misclassified_plan)->get_error_code(), 'Ordinary conflict disposition accepted a required handoff envelope.');
$actionless_handoff = $legacy_handoff;
$actionless_handoff['actions'] = array( array(), array() );
$actionless_plan = $base_plan + array( 'disposition' => 'legacy_handoff_required', 'legacy_handoff' => $actionless_handoff );
worktree_handoff_schema_assert('ability_invalid_output' === worktree_handoff_execute_output($plan_ability, $actionless_plan)->get_error_code(), 'Legacy handoff disposition accepted incomplete actions.');
$contradictory_refusal = $refused_handoff;
$contradictory_refusal['vetoes'] = array();
$contradictory_plan = $base_plan + array( 'disposition' => 'owner_conflict', 'legacy_handoff' => $contradictory_refusal );
worktree_handoff_schema_assert('ability_invalid_output' === worktree_handoff_execute_output($plan_ability, $contradictory_plan)->get_error_code(), 'Ordinary conflict disposition accepted a refused handoff without a veto.');

fwrite(STDOUT, "worktree-handoff-ability-schema: ok\n");
