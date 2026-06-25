<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceCleanupPlan.php';

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
		$preview_command = 'studio wp datamachine-code workspace cleanup run --mode=artifacts --dry-run --format=json';

		return array(
			'success'       => true,
			'dry_run'       => true,
			'apply_command' => $preview_command,
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
			'summary'       => array(
				'apply_command'          => $preview_command,
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
			'skipped'    => array(),
			'summary'    => array(),
		);
	}

	private function stable_cleanup_hash( array $data, string $prefix ): string {
		return $prefix . '-' . substr(hash('sha256', wp_json_encode($data)), 0, 12);
	}
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

$artifact_plan = $plan['plans']['artifact_cleanup'] ?? array();
artifact_cleanup_plan_contract_assert(! array_key_exists('apply_command', $artifact_plan), 'nested artifact plan should not expose apply_command');
artifact_cleanup_plan_contract_assert(! array_key_exists('apply_command', $artifact_plan['summary'] ?? array()), 'nested artifact summary should not expose apply_command');
artifact_cleanup_plan_contract_assert(
	'studio wp datamachine-code workspace cleanup run --mode=artifacts --dry-run --format=json' === ( $artifact_plan['preview_command'] ?? '' ),
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

fwrite(STDOUT, "artifact-cleanup-plan-output-contract ok\n");
