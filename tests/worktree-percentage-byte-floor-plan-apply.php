<?php

declare(strict_types=1);

const ABSPATH = __DIR__ . '/fixtures/';
define('DATAMACHINE_WORKSPACE_PATH', sys_get_temp_dir());

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/Workspace.php';

use DataMachineCode\Workspace\Workspace;

function percentage_plan_apply_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$intent = array(
	'repo' => 'repo', 'branch' => 'small-demand', 'from' => 'origin/main', 'inject_context' => false, 'bootstrap' => false,
	'allow_stale' => false, 'rebase_base' => true, 'force' => false, 'task' => array(), 'allow_unverified_freshness' => false,
	'require_task_tracker' => false, 'intent' => array(), 'reuse_policy' => 'reuse_compatible', 'allow_percentage_byte_floor_exception' => true,
);
$capacity = array(
	'status' => 'warning', 'creation_allowed' => true, 'filesystem_free_bytes' => 40, 'projected_free_bytes' => 30,
	'filesystem_total_inodes' => 1000000, 'filesystem_free_inodes' => 500, 'projected_free_inodes' => 400, 'refuse_free_bytes' => 20, 'effective_refuse_bytes' => 100,
	'refuse_percent_bytes_floor' => 100, 'refuse_free_inodes' => 100, 'effective_refuse_inodes' => 100,
	'trigger_reasons' => array( 'projected_free_bytes_percentage_refusal_floor' ),
	'typed_trigger_reasons' => array( array( 'code' => 'projected_free_bytes_percentage_refusal_floor', 'severity' => 'blocking' ) ),
	'admission_exception' => array( 'type' => 'percentage_byte_floor_demand_bounded', 'operator_intent' => true, 'status' => 'admitted', 'waived_trigger' => 'projected_free_bytes_percentage_refusal_floor', 'demand_source' => 'conservative_defaults', 'trusted_demand_source' => true ),
);
$evidence = array( 'capacity' => $capacity, 'bootstrap_demand' => array( 'bytes' => 10, 'inodes' => 2, 'source' => 'conservative_defaults', 'calibration' => array( 'confidence' => 'established' ) ) );
$workspace = new Workspace();
$result = new ReflectionMethod(Workspace::class, 'worktree_plan_result');
$planned = $result->invoke($workspace, $intent, 'repo@small-demand', '/tmp/repo@small-demand', 'small-demand', 'create', $evidence);
percentage_plan_apply_assert('reviewed-capacity' !== ($planned['digest'] ?? null) && true === ($planned['apply_intent']['allow_percentage_byte_floor_exception'] ?? false), 'Production plan digest must retain reviewed exception intent.');

foreach ( array(
	'free_capacity' => static fn( array $value ): array => array_replace($value, array( 'filesystem_free_bytes' => 39 )),
	'projected_capacity' => static fn( array $value ): array => array_replace($value, array( 'projected_free_bytes' => 29 )),
	'floor' => static fn( array $value ): array => array_replace($value, array( 'effective_refuse_bytes' => 101 )),
	'trigger_set' => static fn( array $value ): array => array_replace($value, array( 'trigger_reasons' => array( 'projected_free_bytes_percentage_refusal_floor', 'projected_free_inodes_absolute_refusal_floor' ) )),
	'exception_proof' => static fn( array $value ): array => array_replace($value, array( 'admission_exception' => array_replace($value['admission_exception'], array( 'status' => 'rejected' )) )),
) as $name => $mutate ) {
	$changed = $result->invoke($workspace, $intent, 'repo@small-demand', '/tmp/repo@small-demand', 'small-demand', 'create', array_replace($evidence, array( 'capacity' => $mutate($capacity) )));
	percentage_plan_apply_assert(($planned['digest'] ?? '') !== ($changed['digest'] ?? ''), sprintf('Changed %s must produce a stale plan digest before apply.', $name));
}

$dynamic_inode_supply = $result->invoke($workspace, $intent, 'repo@small-demand', '/tmp/repo@small-demand', 'small-demand', 'create', array_replace($evidence, array(
	'capacity' => array_replace($capacity, array( 'filesystem_total_inodes' => 2000000 )),
)));
percentage_plan_apply_assert(($planned['digest'] ?? '') === ($dynamic_inode_supply['digest'] ?? ''), 'Dynamic filesystem total inode supply must not stale an unchanged capacity decision.');

$ordinary_capacity = array_replace($capacity, array(
	'admission_exception' => array( 'operator_intent' => false, 'status' => 'not_requested' ),
));
$ordinary_plan = $result->invoke($workspace, $intent, 'repo@small-demand', '/tmp/repo@small-demand', 'small-demand', 'create', array_replace($evidence, array( 'capacity' => $ordinary_capacity )));
$ordinary_drift = $result->invoke($workspace, $intent, 'repo@small-demand', '/tmp/repo@small-demand', 'small-demand', 'create', array_replace($evidence, array(
	'capacity' => array_replace($ordinary_capacity, array( 'filesystem_free_bytes' => 39, 'projected_free_bytes' => 29 )),
)));
percentage_plan_apply_assert(($ordinary_plan['digest'] ?? '') === ($ordinary_drift['digest'] ?? ''), 'Ambient free-space drift must not stale an unchanged ordinary admission decision.');

$changed_provenance = $result->invoke($workspace, $intent, 'repo@small-demand', '/tmp/repo@small-demand', 'small-demand', 'create', array_replace($evidence, array( 'bootstrap_demand' => array_replace($evidence['bootstrap_demand'], array( 'source' => 'not_provided' ) ) )));
percentage_plan_apply_assert(($planned['digest'] ?? '') !== ($changed_provenance['digest'] ?? ''), 'Changed trusted demand provenance must produce a stale plan digest before apply.');

echo "worktree-percentage-byte-floor-plan-apply: ok\n";
