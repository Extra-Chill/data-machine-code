<?php

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	if ( ! class_exists(Workspace::class) ) {
		class Workspace {
			/** @var array<int,array<string,mixed>> */
			public array $artifact_calls = array();

			/** @var array<int,array<string,mixed>> */
		public array $worktree_calls = array();

		public function workspace_cleanup_plan( array $opts ): array {
			return array(
				'mode'          => 'cleanup_plan',
				'safety_policy' => array( 'force_artifact_cleanup' => ! empty($opts['force_artifact_cleanup']) ),
				'rows'          => array(),
				'summary'       => array(
					'apply_command'        => 'studio wp datamachine-code workspace cleanup apply <run-id> --force',
					'recommended_commands' => array( array( 'command' => 'studio wp datamachine-code workspace cleanup apply <run-id> --force' ) ),
				),
			);
		}

			public function worktree_cleanup_artifacts( array $opts ): array {
				$this->artifact_calls[] = $opts;
				return array(
					'removed' => array(
						array( 'handle' => 'repo@dirty-artifacts', 'artifact_size_bytes' => 1024 ),
						array( 'handle' => 'repo@unpushed-artifacts', 'artifact_size_bytes' => 2048 ),
					),
					'skipped' => array(),
				);
			}

			public function worktree_cleanup_merged( array $opts ): array {
				$this->worktree_calls[] = $opts;
				return array(
					'removed' => array(),
					'skipped' => array(
						array( 'handle' => 'repo@source', 'reason_code' => 'dirty_worktree', 'reason' => 'source edits remain protected' ),
						array( 'handle' => 'repo@branch', 'reason_code' => 'unpushed_commits', 'reason' => 'branch commits remain protected' ),
						array( 'handle' => 'repo@commit', 'reason_code' => 'plan_mismatch', 'reason' => 'commit identity remains protected' ),
						array( 'handle' => 'repo@primary', 'reason_code' => 'primary_worktree', 'reason' => 'primary checkout remains protected' ),
					),
				);
			}
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '' ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}

	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode($value, $flags, $depth); }
	}

	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepository.php';
	require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';
	require_once dirname(__DIR__) . '/inc/Workspace/CleanupRunService.php';

	final class ForcedArtifactPolicyRepository extends DataMachineCode\Storage\CleanupRunRepository {
		/** @var array<string,array<string,mixed>> */
		public array $runs = array();

		/** @var array<string,array<int,array<string,mixed>>> */
		public array $items = array();

		public function create_run( array $run ): string|WP_Error {
			$this->runs['cleanup-run-planned'] = $run + array( 'run_id' => 'cleanup-run-planned' );
			return 'cleanup-run-planned';
		}
		public function add_items( string $run_id, array $items ): int|WP_Error { return count($items); }

		public function get_run( string $run_id ): ?array { return $this->runs[ $run_id ] ?? null; }
		public function get_items( string $run_id ): array { return $this->items[ $run_id ] ?? array(); }
		public function update_run( string $run_id, array $fields ): bool {
			$this->runs[ $run_id ] = array_merge($this->runs[ $run_id ], $fields);
			return true;
		}
		public function update_item( int $id, array $fields ): bool {
			foreach ( $this->items as &$items ) {
				foreach ( $items as &$item ) {
					if ( $id === $item['id'] ) {
						$item = array_merge($item, $fields);
						return true;
					}
				}
			}
			return false;
		}
	}

	function forced_artifact_policy_assert( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
		}
	}

	$repo = new ForcedArtifactPolicyRepository();
	$repo->runs['cleanup-run-forced-artifacts'] = array(
		'run_id' => 'cleanup-run-forced-artifacts',
		'mode'   => 'artifacts',
		'status' => 'planned',
		'policy' => array( 'force_artifact_cleanup' => true ),
		'summary' => array(),
	);
	$repo->items['cleanup-run-forced-artifacts'] = array(
		array( 'id' => 1, 'handle' => 'repo@dirty-artifacts', 'item_type' => 'artifact_cleanup', 'status' => 'pending', 'evidence' => array( 'handle' => 'repo@dirty-artifacts' ) ),
		array( 'id' => 2, 'handle' => 'repo@unpushed-artifacts', 'item_type' => 'artifact_cleanup', 'status' => 'pending', 'evidence' => array( 'handle' => 'repo@unpushed-artifacts' ) ),
		array( 'id' => 3, 'handle' => 'repo@source', 'item_type' => 'worktree_removal', 'status' => 'pending', 'evidence' => array( 'handle' => 'repo@source' ) ),
		array( 'id' => 4, 'handle' => 'repo@branch', 'item_type' => 'worktree_removal', 'status' => 'pending', 'evidence' => array( 'handle' => 'repo@branch' ) ),
		array( 'id' => 5, 'handle' => 'repo@commit', 'item_type' => 'worktree_removal', 'status' => 'pending', 'evidence' => array( 'handle' => 'repo@commit' ) ),
		array( 'id' => 6, 'handle' => 'repo@primary', 'item_type' => 'worktree_removal', 'status' => 'pending', 'evidence' => array( 'handle' => 'repo@primary' ) ),
	);

	$workspace = new DataMachineCode\Workspace\Workspace();
	$service   = new DataMachineCode\Workspace\CleanupRunService($repo, $workspace);
	$planned   = $service->plan(array( 'force_artifact_cleanup' => true ));
	forced_artifact_policy_assert(true, $repo->runs['cleanup-run-planned']['policy']['force_artifact_cleanup'] ?? null, 'DB-backed plans must persist reviewed artifact force intent.');
	forced_artifact_policy_assert('studio wp datamachine-code workspace cleanup apply cleanup-run-planned --force', $planned['summary']['apply_command'] ?? null, 'Primary DB-backed apply command must reproduce stored force intent.');
	forced_artifact_policy_assert('studio wp datamachine-code workspace cleanup apply cleanup-run-planned --force', $planned['summary']['recommended_commands'][0]['command'] ?? null, 'Recommended DB-backed apply command must reproduce stored force intent.');
	$result    = $service->apply('cleanup-run-forced-artifacts');

	forced_artifact_policy_assert(true, $workspace->artifact_calls[0]['force'] ?? null, 'Stored force policy must revalidate dirty and unpushed artifact rows with force.');
	forced_artifact_policy_assert(false, array_key_exists('force', $workspace->worktree_calls[0] ?? array()), 'Force must not be passed to worktree removal.');
	forced_artifact_policy_assert('applied', $repo->items['cleanup-run-forced-artifacts'][0]['status'], 'Forced artifact row should apply.');
	forced_artifact_policy_assert('applied', $repo->items['cleanup-run-forced-artifacts'][1]['status'], 'Unpushed artifact row should apply under stored force intent.');
	foreach ( array( 2 => 'dirty_worktree', 3 => 'unpushed_commits', 4 => 'plan_mismatch', 5 => 'primary_worktree' ) as $index => $reason ) {
		forced_artifact_policy_assert('skipped', $repo->items['cleanup-run-forced-artifacts'][ $index ]['status'], 'Non-artifact protection must remain intact.');
		forced_artifact_policy_assert($reason, $repo->items['cleanup-run-forced-artifacts'][ $index ]['reason_code'], 'Non-artifact protection reason must remain intact.');
	}
	forced_artifact_policy_assert(2, $result['applied'] ?? null, 'Only artifact rows should be applied.');

	fwrite(STDOUT, "cleanup-run-forced-artifact-policy ok\n");
}
