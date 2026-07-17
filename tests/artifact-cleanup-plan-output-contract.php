<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupPlan.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';

use DataMachineCode\Workspace\WorkspaceArtifactCleanup;
use DataMachineCode\Workspace\WorkspaceCleanupPlan;

function artifact_cleanup_plan_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

final class ArtifactCleanupPlanContractWorkspace {
	use WorkspaceCleanupPlan;

	public const CLEANUP_PLAN_DEFAULT_LIMIT  = 100;
	public const CLEANUP_PLAN_DEFAULT_BUDGET = '30s';

	private string $workspace_path = '/tmp/dmc-artifact-cleanup-contract';

	public function worktree_cleanup_artifacts( array $opts = array() ): array {
		$preview_command = 'studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run --format=json';

		return array(
			'success'               => true,
			'dry_run'               => true,
			'review_command'        => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
			'apply_command'         => 'studio wp datamachine-code workspace cleanup apply <run-id>',
			'preview_command'       => $preview_command,
			'rerun_preview_command' => $preview_command,
			'candidates'    => array(
				array(
					'handle'              => 'repo@example',
					'repo'                => 'repo',
					'branch'              => 'example',
					'path'                => '/tmp/dmc-artifact-cleanup-contract/repo@example',
					'artifact_size_bytes' => 123,
					'artifacts'           => array(
						array(
							'path'       => 'vendor',
							'size_bytes' => 123,
						),
					),
				),
			),
			'skipped'       => array(),
			'summary'               => array(
				'review_command'         => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
				'apply_command'          => 'studio wp datamachine-code workspace cleanup apply <run-id>',
				'preview_command'        => $preview_command,
				'rerun_preview_command'  => $preview_command,
				'would_remove_artifacts' => 1,
				'artifact_size_bytes'    => 123,
			),
		);
	}

	public function worktree_cleanup_merged( array $opts = array() ): array {
		return array(
			'success'    => true,
			'dry_run'    => true,
			'candidates' => array(),
			'skipped'    => array(
				array(
					'handle'         => 'repo@skipped-size',
					'repo'           => 'repo',
					'branch'         => 'skipped-size',
					'path'           => '/tmp/dmc-artifact-cleanup-contract/repo@skipped-size',
					'reason_code'    => 'dirty_worktree',
					'fields_skipped' => array( 'disk' ),
				),
				array(
					'handle'      => 'repo@unknown-size',
					'repo'        => 'repo',
					'branch'      => 'unknown-size',
					'path'        => '/tmp/dmc-artifact-cleanup-contract/repo@unknown-size',
					'reason_code' => 'dirty_worktree',
				),
				array(
					'handle'      => 'repo@zero-size',
					'repo'        => 'repo',
					'branch'      => 'zero-size',
					'path'        => '/tmp/dmc-artifact-cleanup-contract/repo@zero-size',
					'reason_code' => 'dirty_worktree',
					'size_bytes'  => 0,
				),
				array(
					'handle'      => 'repo@known-size',
					'repo'        => 'repo',
					'branch'      => 'known-size',
					'path'        => '/tmp/dmc-artifact-cleanup-contract/repo@known-size',
					'reason_code' => 'dirty_worktree',
					'size_bytes'  => 2048,
				),
			),
			'summary'    => array(),
		);
	}

	private function stable_cleanup_hash( array $data, string $prefix ): string {
		return $prefix . '-' . substr(hash('sha256', wp_json_encode($data)), 0, 12);
	}
}

final class ArtifactCleanupPreviewContractWorkspace {
	use WorkspaceArtifactCleanup;
}

if ( ! function_exists('wp_json_encode') ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode($data, $options, $depth);
	}
}

$workspace = new ArtifactCleanupPlanContractWorkspace();
$plan      = $workspace->workspace_cleanup_plan(array( 'include_worktrees' => false ));

artifact_cleanup_plan_contract_assert(is_array($plan), 'cleanup plan should return an array');
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup apply <run-id>' === ( $plan['summary']['apply_command'] ?? '' ),
	'cleanup plan summary should expose the authoritative apply command'
);

$forced_plan = $workspace->workspace_cleanup_plan(array( 'include_worktrees' => false, 'force_artifact_cleanup' => true ));
artifact_cleanup_plan_contract_assert(
	true === ( $forced_plan['safety_policy']['force_artifact_cleanup'] ?? null ),
	'forced artifact plans should persist the reviewed artifact-only force policy'
);
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup apply <run-id> --force' === ( $forced_plan['summary']['apply_command'] ?? '' ),
	'forced artifact plans should recommend the exact forced apply command'
);
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup apply <run-id> --force' === ( $forced_plan['summary']['recommended_commands'][1]['command'] ?? '' ),
	'forced artifact plans should retain force in every reviewed-plan recommendation'
);

$artifact_plan = $plan['plans']['artifact_cleanup'] ?? array();
artifact_cleanup_plan_contract_assert(! array_key_exists('apply_command', $artifact_plan), 'nested artifact plan should not expose apply_command');
artifact_cleanup_plan_contract_assert(! array_key_exists('apply_command', $artifact_plan['summary'] ?? array()), 'nested artifact summary should not expose apply_command');
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run --format=json' === ( $artifact_plan['preview_command'] ?? '' ),
	'nested artifact plan should expose preview_command'
);
artifact_cleanup_plan_contract_assert(
	( $artifact_plan['preview_command'] ?? null ) === ( $artifact_plan['rerun_preview_command'] ?? null ),
	'nested artifact plan should expose matching rerun_preview_command'
);
artifact_cleanup_plan_contract_assert(
	( $artifact_plan['summary']['preview_command'] ?? null ) === ( $artifact_plan['summary']['rerun_preview_command'] ?? null ),
	'nested artifact summary should expose matching preview commands'
);

$blocked_plan = $workspace->workspace_cleanup_plan(array( 'include_artifacts' => false, 'limit' => 25, 'offset' => 0 ));
artifact_cleanup_plan_contract_assert(is_array($blocked_plan), 'blocked cleanup plan should return an array');

$blocked_rows = (array) ( $blocked_plan['blocked']['worktree_removal'] ?? array() );
artifact_cleanup_plan_contract_assert(4 === count($blocked_rows), 'blocked plan should preserve skipped rows');
$statuses = array_column($blocked_rows, 'size_status', 'handle');
artifact_cleanup_plan_contract_assert('skipped' === ( $statuses['repo@skipped-size'] ?? null ), 'disk-skipped rows should be marked skipped, not 0 B');
artifact_cleanup_plan_contract_assert('unknown' === ( $statuses['repo@unknown-size'] ?? null ), 'missing size rows should be marked unknown, not 0 B');
artifact_cleanup_plan_contract_assert('known_zero' === ( $statuses['repo@zero-size'] ?? null ), 'explicit zero rows should remain distinguishable as true zero');
artifact_cleanup_plan_contract_assert('known' === ( $statuses['repo@known-size'] ?? null ), 'positive byte rows should be marked known');

$blocker_accounting = $blocked_plan['summary']['blockers']['dirty_worktree']['size_accounting'] ?? array();
artifact_cleanup_plan_contract_assert(2048 === ( $blocker_accounting['known_bytes'] ?? null ), 'blocker summary should sum only known bytes');
artifact_cleanup_plan_contract_assert(2 === ( $blocker_accounting['known_count'] ?? null ), 'blocker summary should count known rows including true zero');
artifact_cleanup_plan_contract_assert(1 === ( $blocker_accounting['known_zero_count'] ?? null ), 'blocker summary should count true zero separately');
artifact_cleanup_plan_contract_assert(1 === ( $blocker_accounting['skipped_count'] ?? null ), 'blocker summary should count skipped size probes');
artifact_cleanup_plan_contract_assert(1 === ( $blocker_accounting['unknown_count'] ?? null ), 'blocker summary should count unknown size rows');
artifact_cleanup_plan_contract_assert(
	'bounded_size_aware_review' === ( $blocked_plan['summary']['recommended_commands'][0]['label'] ?? null ),
	'cleanup plan should suggest a bounded size-aware review command before exhaustive audit'
);

$artifact_preview_workspace = new ArtifactCleanupPreviewContractWorkspace();
$review_method              = new ReflectionMethod($artifact_preview_workspace, 'build_artifact_cleanup_review_command');
$apply_method               = new ReflectionMethod($artifact_preview_workspace, 'build_artifact_cleanup_apply_command');
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json' === $review_method->invoke($artifact_preview_workspace),
	'artifact cleanup preview should create a DB-backed plan'
);
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup apply <run-id>' === $apply_method->invoke($artifact_preview_workspace),
	'artifact cleanup preview should apply the reviewed DB-backed run by ID'
);
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup apply <run-id> --force' === $apply_method->invoke($artifact_preview_workspace, true),
	'forced artifact previews should preserve force in the DB-backed apply command'
);

$workspace_command_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
artifact_cleanup_plan_contract_assert(false !== $workspace_command_source, 'workspace command source should be readable');
artifact_cleanup_plan_contract_assert(
	false === strpos($workspace_command_source, 'cleanup run --mode=artifacts'),
	'workspace command help should not advertise artifact scheduling as review or apply guidance'
);
artifact_cleanup_plan_contract_assert(
	false !== strpos($workspace_command_source, 'wp datamachine-code workspace cleanup plan --mode=artifacts --format=json'),
	'workspace command help should advertise the DB-backed artifact review command'
);
artifact_cleanup_plan_contract_assert(
	false !== strpos($workspace_command_source, '`workspace worktree emergency-cleanup --apply` is not supported. Review a DB-backed artifact plan with `studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json`, then apply it with `studio wp datamachine-code workspace cleanup apply <run-id>`.'),
	'emergency cleanup --apply should fail with the DB-backed review and apply commands'
);
artifact_cleanup_plan_contract_assert(
	false !== strpos($workspace_command_source, 'Create a DB-backed artifact review run with `studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json`, note its run_id, then apply it with `studio wp datamachine-code workspace cleanup apply <run-id>`.'),
	'emergency cleanup preview should direct operators to create and apply a DB-backed run'
);
artifact_cleanup_plan_contract_assert(
	false !== strpos($workspace_command_source, 'Create a reviewed DB-backed plan with `%s`, note its run_id, then apply it with `%s`; --apply-plan remains a low-level escape hatch.'),
	'cleanup-artifacts preview should direct operators to create and apply a DB-backed run'
);

$worktree_cleanup_engine_source = file_get_contents(dirname(__DIR__) . '/inc/Workspace/WorkspaceWorktreeCleanupEngine.php');
artifact_cleanup_plan_contract_assert(false !== $worktree_cleanup_engine_source, 'worktree cleanup engine source should be readable');
artifact_cleanup_plan_contract_assert(
	false !== strpos($worktree_cleanup_engine_source, "'command'     => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json'"),
	'dirty-artifact remediation should create a DB-backed artifact plan'
);
artifact_cleanup_plan_contract_assert(
	false !== strpos($worktree_cleanup_engine_source, "'alternative' => 'studio wp datamachine-code workspace cleanup apply <run-id>'"),
	'dirty-artifact remediation should apply the reviewed DB-backed run by ID'
);
artifact_cleanup_plan_contract_assert(
	false !== strpos($worktree_cleanup_engine_source, "'hint'        => 'Run studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json, note its run_id, then run studio wp datamachine-code workspace cleanup apply <run-id> to reclaim reviewed reconstructable artifacts; source edits are still protected by dirty_worktree.'"),
	'artifact-only dirty-worktree hints should direct operators through DB-backed plan and apply commands'
);

fwrite(STDOUT, "artifact-cleanup-plan-output-contract ok\n");
