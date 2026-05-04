<?php
/**
 * Smoke test for workspace worktree cleanup CLI report rendering.
 *
 *   php tests/smoke-worktree-cleanup-cli.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	class WP_CLI {
		public static array $logs = array();
		public static array $successes = array();

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}

		public static function warning( string $message ): void {
			self::$logs[] = 'warning: ' . $message;
		}
	}

	function is_wp_error( $value ): bool {
		return false;
	}

	function wp_get_ability( string $name ) {
		return $GLOBALS['__abilities'][ $name ] ?? null;
	}

	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

namespace DataMachine\Cli {
	class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void {
			\WP_CLI::log( 'table:' . count( $items ) . ':' . implode( ',', $fields ) );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Cleanup/CleanupRunEvidenceStoreInterface.php';
	require_once dirname( __DIR__ ) . '/inc/Cleanup/DataMachineJobCleanupRunEvidenceStore.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/WorkspaceCommand.php';

	function datamachine_code_cleanup_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_code_cleanup_report(): array {
		$skipped = array();
		for ( $i = 1; $i <= 11; $i++ ) {
			$skipped[] = array(
				'handle'      => 'repo@feature-' . $i,
				'repo'        => 'repo',
				'branch'      => 'feature-' . $i,
				'path'        => '/workspace/repo@feature-' . $i,
				'age_days'    => 20 + $i,
				'size_bytes'          => 1024 * 1024 * $i,
				'artifact_size_bytes' => 512 * 1024 * $i,
				'reason_code' => 1 === $i ? 'dirty_worktree' : 'no_merge_signal',
				'reason'      => 1 === $i ? 'working tree dirty (1 files) - pass force=true to override' : 'no merge signal - leaving in place',
			);
		}

		$skipped[] = array(
			'handle'         => 'broken@metadata',
			'repo'           => '',
			'branch'         => '',
			'path'           => '',
			'reason_code'         => 'missing_metadata',
			'reason'              => 'missing repo/branch/path',
			'missing_fields'      => array( 'repo', 'branch', 'path' ),
			'hint'                => 'Run workspace worktree prune if this is a stale registry entry; inspect manually if the path still exists.',
			'size_bytes'          => 0,
			'artifact_size_bytes' => 0,
		);

		$skipped[] = array(
			'handle'      => 'repo@repaired-metadata',
			'repo'        => 'repo',
			'branch'      => 'repaired-metadata',
			'path'        => '/workspace/repo@repaired-metadata',
			'reason_code' => 'repaired_metadata',
			'reason'      => 'repaired metadata was repaired conservatively; no cleanup signal yet',
		);
		$skipped[] = array(
			'handle'      => 'repo@needs-full-scan',
			'repo'        => 'repo',
			'branch'      => 'needs-full-scan',
			'path'        => '/workspace/repo@needs-full-scan',
			'reason_code' => 'requires_full_scan',
			'reason'      => 'inventory row has no lifecycle metadata; cleanup safety requires a full scan',
		);
		$skipped[] = array(
			'handle'      => 'repo@needs-review',
			'repo'        => 'repo',
			'branch'      => 'needs-review',
			'path'        => '/workspace/repo@needs-review',
			'reason_code' => 'no_inventory_cleanup_signal',
			'reason'      => 'no explicit inventory cleanup signal - leaving in place',
		);

		return array(
			'success'    => true,
			'dry_run'    => true,
			'candidates' => array(
				array(
					'handle'      => 'repo@merged',
					'repo'        => 'repo',
					'branch'      => 'merged',
					'path'        => '/workspace/repo@merged',
					'age_days'    => 42,
					'size_bytes'          => 4 * 1024 * 1024 * 1024,
					'artifact_size_bytes' => 3 * 1024 * 1024 * 1024,
					'signal'      => 'upstream-gone',
					'reason_code' => 'upstream-gone',
					'reason'      => 'remote branch deleted (likely merged + auto-deleted)',
				),
			),
			'removed'    => array(),
			'skipped'    => $skipped,
			'summary'    => array(
				'would_remove'         => 1,
				'removed'              => 0,
				'skipped'              => count( $skipped ),
				'skipped_by_reason'    => array(
					'dirty_worktree'               => 1,
					'missing_metadata'             => 1,
					'repaired_metadata'    => 1,
					'no_inventory_cleanup_signal'  => 1,
					'no_merge_signal'              => 10,
					'requires_full_scan'           => 1,
				),
				'skipped_next_commands' => array(
					array(
						'reason_code' => 'repaired_metadata',
						'count'       => 1,
						'command'     => 'studio wp datamachine-code workspace cleanup run --mode=retention --older-than=7d',
						'alternative' => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25 --older-than=7d',
						'destructive' => true,
					),
					array(
						'reason_code' => 'requires_full_scan',
						'count'       => 1,
						'command'     => 'studio wp datamachine-code workspace worktree reconcile-metadata-batch --dry-run --limit=25 --format=json',
						'alternative' => 'studio wp datamachine-code workspace worktree reconcile-metadata-batch --limit=25',
						'destructive' => false,
					),
					array(
						'reason_code' => 'no_inventory_cleanup_signal',
						'count'       => 1,
						'command'     => 'studio wp datamachine-code workspace worktree finalize <handle> --pr=<pr-url>',
						'alternative' => 'studio wp datamachine-code workspace worktree mark-cleanup-eligible <handle>',
						'destructive' => false,
					),
				),
				'candidates_by_signal' => array(
					'upstream-gone' => 1,
				),
				'total_size_bytes'      => 10 * 1024 * 1024 * 1024,
				'artifact_size_bytes'   => 7 * 1024 * 1024 * 1024,
				'size_by_repo'          => array( 'repo' => 10 * 1024 * 1024 * 1024 ),
				'artifact_size_by_repo' => array( 'repo' => 7 * 1024 * 1024 * 1024 ),
			),
		);
	}

	class FakeCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			if ( isset( $input['progress_callback'] ) && is_callable( $input['progress_callback'] ) ) {
				$input['progress_callback'](
					array(
						'event'      => 'checking',
						'handle'     => 'repo@merged',
						'checked'    => 1,
						'total'      => 13,
						'candidates' => 1,
						'skipped'    => 0,
						'removed'    => 0,
						'elapsed'    => 1.2,
					)
				);
			}
			$report = datamachine_code_cleanup_report();
			if ( isset( $input['older_than'] ) ) {
				$report['summary']['age_filter'] = array(
					'type'             => 'older_than',
					'older_than'       => $input['older_than'],
					'duration_seconds' => 604800,
					'threshold'        => '2026-04-21T00:00:00+00:00',
					'excluded'         => 2,
					'unknown_age'      => 1,
				);
			}
			return $report;
		}
	}

	class FakeArtifactCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'    => true,
				'dry_run'    => ! empty( $input['dry_run'] ),
				'candidates' => array(
					array(
						'handle'    => 'repo@old',
						'repo'      => 'repo',
						'branch'    => 'old',
						'path'      => '/workspace/repo@old',
						'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ),
					),
				),
				'removed'    => array(),
				'skipped'    => array(
					array(
						'handle'      => 'repo@active',
						'repo'        => 'repo',
						'branch'      => 'active',
						'artifacts'   => array( array( 'path' => 'target', 'size_bytes' => 2048 ) ),
						'reason_code' => 'active_symlink_target',
						'reason'      => 'worktree is an active symlink target',
					),
				),
				'summary'    => array(
					'would_remove_artifacts' => 1,
					'removed_artifacts'      => 0,
					'skipped'                => 1,
					'artifact_size_bytes'    => 1024,
				),
			);
		}
	}

	class FakeEmergencyCleanupAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'             => true,
				'mode'                => 'emergency',
				'dry_run'             => empty( $input['apply_plan'] ),
				'artifact_candidates' => array(
					array(
						'handle'    => 'repo@old',
						'repo'      => 'repo',
						'branch'    => 'old',
						'path'      => '/workspace/repo@old',
						'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ),
					),
				),
				'worktree_candidates' => array(
					array(
						'handle'      => 'repo@eligible',
						'repo'        => 'repo',
						'branch'      => 'eligible',
						'path'        => '/workspace/repo@eligible',
						'age_days'    => 90,
						'size_bytes'  => 4096,
						'signal'      => 'cleanup_eligible',
						'reason_code' => 'cleanup_eligible',
					),
				),
				'removed_artifacts'   => empty( $input['apply_plan'] ) ? array() : array( array( 'handle' => 'repo@old', 'repo' => 'repo', 'branch' => 'old', 'path' => '/workspace/repo@old', 'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ) ) ),
				'removed_worktrees'   => array(),
				'skipped'             => array(),
				'summary'             => array(
					'would_remove_artifacts' => 1,
					'would_remove_worktrees' => 1,
					'removed_artifacts'      => empty( $input['apply_plan'] ) ? 0 : 1,
					'removed_worktrees'      => 0,
					'skipped'                => 0,
					'artifact_size_bytes'    => 1024,
					'worktree_size_bytes'    => 4096,
				),
			);
		}
	}

	class FakeListAbility {
		public function execute( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array(
				'success'   => true,
				'worktrees' => array(
					array(
						'handle'      => 'repo',
						'repo'        => 'repo',
						'is_primary'  => true,
						'branch'      => 'main',
						'head'        => 'abcdef123',
						'dirty'       => 0,
						'path'        => '/workspace/repo',
					),
					array(
						'handle'              => 'repo@old',
						'repo'                => 'repo',
						'is_primary'          => false,
						'branch'              => 'old',
						'head'                => 'abcdef456',
						'dirty'               => 0,
						'created_at'          => '2026-04-01T00:00:00+00:00',
						'age_days'            => 28,
						'size_bytes'          => 4 * 1024 * 1024,
						'artifact_size_bytes' => 3 * 1024 * 1024,
						'artifacts'           => array( array( 'path' => 'target', 'size_bytes' => 3 * 1024 * 1024 ) ),
						'stale_reason'        => 'older_than_threshold',
						'path'                => '/workspace/repo@old',
					),
					array(
						'handle'              => 'repo@dirty',
						'repo'                => 'repo',
						'is_primary'          => false,
						'branch'              => 'dirty',
						'head'                => 'abcdef789',
						'dirty'               => 1,
						'age_days'            => null,
						'size_bytes'          => 1024,
						'artifact_size_bytes' => 0,
						'artifacts'           => array(),
						'stale_reason'        => 'dirty',
						'path'                => '/workspace/repo@dirty',
					),
				),
			);
		}
	}

	class FakeCleanupRunAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'   => true,
				'state'     => 'jobs_queued',
				'run_id'    => 'cleanup-run-123',
				'job_id'    => 123,
				'mode'      => (string) ( $input['mode'] ?? '' ),
				'task_type' => 'workspace_retention_cleanup',
			);
		}
	}

	class FakeCleanupStatusAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success' => true,
				'run_id'  => (string) ( $input['run_id'] ?? '' ),
				'state'   => 'planned',
			);
		}
	}

	class FakeHygieneAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'        => true,
				'generated_at'   => '2026-05-04T00:00:00+00:00',
				'workspace_path' => '/workspace',
				'destructive'    => false,
				'size'           => array(),
				'disk'           => array(),
				'worktrees'      => array(),
				'notes'          => array(),
			);
		}
	}

	class FakeGetJobsAbility {
		public function execute( array $input ): array {
			if ( isset( $input['parent_job_id'] ) ) {
				$children = array(
					123 => array(
						array(
							'job_id'        => 124,
							'parent_job_id' => 123,
							'source'        => 'system',
							'status'        => 'completed',
							'engine_data'   => array( 'task_type' => 'workspace_retention_cleanup' ),
						),
					),
					124 => array(
						array(
							'job_id'        => 125,
							'parent_job_id' => 124,
							'source'        => 'batch',
							'status'        => 'processing',
							'engine_data'   => array(
								'batch_id'  => 'dm_batch_123',
								'task_type' => 'worktree_cleanup_chunk',
							),
						),
						array(
							'job_id'        => 126,
							'parent_job_id' => 124,
							'source'        => 'system',
							'status'        => 'completed',
							'engine_data'   => array(
								'task_type'          => 'worktree_cleanup_chunk',
								'system_task_result' => array(
									'success'         => true,
									'chunk_type'      => 'artifacts',
									'planned_count'   => 3,
									'applied_count'   => 2,
									'skipped_count'   => 1,
									'failed_count'    => 0,
									'bytes_reclaimed' => 4096,
									'skipped'         => array(
										array( 'handle' => 'repo@dirty', 'reason_code' => 'dirty_worktree' ),
									),
									'failed'          => array(),
								),
							),
						),
						array(
							'job_id'        => 127,
							'parent_job_id' => 124,
							'source'        => 'system',
							'status'        => 'failed - apply_failed',
							'engine_data'   => array(
								'task_type'          => 'worktree_cleanup_chunk',
								'system_task_result' => array(
									'success'         => false,
									'chunk_type'      => 'artifacts',
									'planned_count'   => 1,
									'applied_count'   => 0,
									'skipped_count'   => 0,
									'failed_count'    => 1,
									'bytes_reclaimed' => 0,
									'skipped'         => array(),
									'failed'          => array(
										array( 'handle' => 'repo@failed', 'reason_code' => 'apply_failed' ),
									),
								),
							),
						),
					),
					125 => array(),
					126 => array(),
					127 => array(),
				);
				$jobs     = $children[ (int) $input['parent_job_id'] ] ?? array();
				$offset   = (int) ( $input['offset'] ?? 0 );
				$per_page = (int) ( $input['per_page'] ?? 100 );

				return array(
					'success' => true,
					'jobs'    => array_slice( $jobs, $offset, $per_page ),
					'total'   => count( $jobs ),
				);
			}

			return array(
				'success' => true,
				'jobs'    => array(
					array(
						'job_id'       => (int) $input['job_id'],
						'flow_id'      => null,
						'pipeline_id'  => null,
						'source'       => 'system',
						'status'       => 'completed',
						'created_at'   => '2026-05-03 00:00:00',
						'completed_at' => '2026-05-03 00:10:00',
						'engine_data'  => array(
							'cleanup_run' => array(
								'mode'   => 'retention',
								'source' => 'workspace_cleanup_cli',
							),
							'system_task_result' => array(
								'success'    => true,
								'job_backed' => true,
								'report'     => array(
									'bytes_reclaimed' => 0,
									'freed_human'     => 'pending child jobs',
								),
							),
						),
					),
				),
			);
		}
	}

	class FakeRetryJobAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'         => true,
				'job_id'          => (int) $input['job_id'],
				'previous_status' => 'failed - test',
				'message'         => 'retried',
			);
		}
	}

	class FakeFailJobAbility {
		public array $last_input = array();

		public function execute( array $input ): array {
			$this->last_input = $input;
			return array(
				'success'         => true,
				'job_id'          => (int) $input['job_id'],
				'previous_status' => 'processing',
				'new_status'      => 'failed - cleanup_cancelled',
			);
		}
	}

	echo "=== smoke-worktree-cleanup-cli ===\n";

	$ability = new FakeCleanupAbility();
	$artifact_ability = new FakeArtifactCleanupAbility();
	$emergency_ability = new FakeEmergencyCleanupAbility();
	$list_ability = new FakeListAbility();
	$cleanup_run_ability = new FakeCleanupRunAbility();
	$cleanup_status_ability = new FakeCleanupStatusAbility();
	$hygiene_ability = new FakeHygieneAbility();
	$get_jobs_ability = new FakeGetJobsAbility();
	$retry_job_ability = new FakeRetryJobAbility();
	$fail_job_ability = new FakeFailJobAbility();
	$GLOBALS['__abilities'] = array(
		'datamachine/workspace-cleanup-run'                 => $cleanup_run_ability,
		'datamachine/workspace-cleanup-status'              => $cleanup_status_ability,
		'datamachine/workspace-hygiene-report'              => $hygiene_ability,
		'datamachine/workspace-worktree-cleanup'           => $ability,
		'datamachine/workspace-worktree-cleanup-artifacts' => $artifact_ability,
		'datamachine/workspace-worktree-emergency-cleanup' => $emergency_ability,
		'datamachine/workspace-worktree-list'              => $list_ability,
		'datamachine/get-jobs'                             => $get_jobs_ability,
		'datamachine/retry-job'                            => $retry_job_ability,
		'datamachine/fail-job'                             => $fail_job_ability,
	);
	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$doc_comment = ( new ReflectionMethod( $command, 'worktree' ) )->getDocComment() ?: '';
	$cleanup_doc_comment = ( new ReflectionMethod( $command, 'cleanup' ) )->getDocComment() ?: '';

	echo "\n[0a] WP-CLI synopsis exposes cleanup flags\n";
	datamachine_code_cleanup_assert( str_contains( $doc_comment, "\n\t * [--inventory-only]" ), 'worktree synopsis declares --inventory-only at top level' );
	datamachine_code_cleanup_assert( str_contains( $doc_comment, "\n\t * [--include-repaired-metadata]" ), 'worktree synopsis declares --include-repaired-metadata at top level' );
	datamachine_code_cleanup_assert( ! str_contains( $doc_comment, "\n\t\t * [--apply-plan=<file>]" ), 'cleanup flags are not hidden behind nested docblock indentation' );
	datamachine_code_cleanup_assert( str_contains( $cleanup_doc_comment, 'Control task-backed workspace cleanup runs.' ), 'workspace cleanup command documents task-backed controller surface' );
	datamachine_code_cleanup_assert( str_contains( $cleanup_doc_comment, '<plan|apply|run|status|resume|cancel|evidence>' ), 'workspace cleanup synopsis exposes DB-backed and task-backed cleanup operations' );
	datamachine_code_cleanup_assert( str_contains( $cleanup_doc_comment, '[--dry-run]' ), 'task-backed cleanup synopsis keeps synchronous dry-run review' );
	datamachine_code_cleanup_assert( str_contains( $doc_comment, 'Daily cleanup path: DB-backed plan, then apply only those rows after revalidation' ), 'worktree examples point daily cleanup to DB-backed run_id controller path' );
	datamachine_code_cleanup_assert( str_contains( $doc_comment, 'workspace cleanup plan --mode=retention' ), 'worktree examples include DB-backed cleanup plan' );
	datamachine_code_cleanup_assert( str_contains( $doc_comment, 'workspace cleanup run --mode=retention' ), 'worktree examples include task-backed cleanup run' );
	datamachine_code_cleanup_assert( ! str_contains( $doc_comment, '> cleanup-plan.json' ), 'worktree examples do not normalize cleanup-plan file redirection' );
	datamachine_code_cleanup_assert( ! str_contains( $doc_comment, '> artifact-plan.json' ), 'worktree examples do not normalize artifact-plan file redirection' );
	datamachine_code_cleanup_assert( ! str_contains( $doc_comment, '> emergency-plan.json' ), 'worktree examples do not normalize emergency-plan file redirection' );
	datamachine_code_cleanup_assert( ! str_contains( $doc_comment, '> reconcile-plan.json' ), 'worktree examples do not normalize reconcile-plan file redirection' );

	echo "\n[0b] task-backed workspace cleanup run/status/control output\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'run' ), array( 'mode' => 'retention', 'format' => 'json' ) );
	$run_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'jobs_queued' === ( $run_json['state'] ?? '' ), 'cleanup run queues a system task' );
	datamachine_code_cleanup_assert( 'cleanup-run-123' === ( $run_json['run_id'] ?? '' ), 'cleanup run returns stable run id' );
	datamachine_code_cleanup_assert( 'retention' === ( $cleanup_run_ability->last_input['mode'] ?? '' ), 'cleanup run ability receives mode' );
	datamachine_code_cleanup_assert( 'workspace_cleanup_cli' === ( $cleanup_run_ability->last_input['source'] ?? '' ), 'cleanup run ability identifies explicit CLI source' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'run' ), array( 'mode' => 'artifacts', 'dry-run' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( true === ( $artifact_ability->last_input['dry_run'] ?? false ), 'cleanup run --dry-run uses artifact cleanup ability directly' );
	datamachine_code_cleanup_assert( 'retention' === ( $cleanup_run_ability->last_input['mode'] ?? '' ), 'cleanup run --dry-run does not schedule cleanup run ability' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'run' ), array( 'mode' => 'inventory', 'format' => 'json' ) );
	$inventory_run_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'jobs_queued' === ( $inventory_run_json['state'] ?? '' ), 'cleanup run queues inventory as a task' );
	datamachine_code_cleanup_assert( 'inventory' === ( $cleanup_run_ability->last_input['mode'] ?? '' ), 'cleanup run can schedule inventory mode' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'status', 'cleanup-run-20260504193024-abc123' ), array( 'format' => 'json' ) );
	$db_status_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'cleanup-run-20260504193024-abc123' === ( $cleanup_status_ability->last_input['run_id'] ?? '' ), 'DB cleanup run IDs are routed to cleanup status ability' );
	datamachine_code_cleanup_assert( 'planned' === ( $db_status_json['state'] ?? '' ), 'DB cleanup run status does not route to job-backed status parser' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'status', 'cleanup-run-123' ), array( 'format' => 'json' ) );
	$status_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'children_processing' === ( $status_json['state'] ?? '' ), 'cleanup status stays active while child batch is processing' );
	datamachine_code_cleanup_assert( 'children_processing' === ( $status_json['status'] ?? '' ), 'cleanup status does not report parent completed while children run' );
	datamachine_code_cleanup_assert( array( 125 ) === ( $status_json['children']['batch_job_ids'] ?? array() ), 'cleanup status reports child batch job ids' );
	datamachine_code_cleanup_assert( 1 === (int) ( $status_json['children']['running'] ?? 0 ), 'cleanup status summarizes running child jobs' );
	datamachine_code_cleanup_assert( ! isset( $status_json['flow_id'] ), 'cleanup status is not linked to a flow id' );
	datamachine_code_cleanup_assert( 4 === (int) ( $status_json['artifact_cleanup']['planned_rows'] ?? 0 ), 'cleanup status aggregates artifact planned rows from child chunks' );
	datamachine_code_cleanup_assert( 4096 === (int) ( $status_json['artifact_cleanup']['bytes_reclaimed'] ?? 0 ), 'cleanup status aggregates artifact bytes from child chunks' );
	datamachine_code_cleanup_assert( 4 === (int) ( $status_json['cleanup_items']['planned_rows'] ?? 0 ), 'cleanup status aggregates planned rows from DB-backed cleanup item evidence' );
	datamachine_code_cleanup_assert( 4096 === (int) ( $status_json['cleanup_items']['bytes_reclaimed'] ?? 0 ), 'cleanup status reconstructs reclaimed bytes from cleanup item evidence' );
	datamachine_code_cleanup_assert( '4.0 KiB' === ( $status_json['system_task_result']['report']['freed_human'] ?? '' ), 'cleanup status replaces pending child job freed placeholder' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'evidence', '123' ), array( 'format' => 'json' ) );
	$evidence_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( isset( $evidence_json['evidence']['engine_data'] ), 'cleanup evidence emits engine data' );
	datamachine_code_cleanup_assert( array( 125 ) === ( $evidence_json['evidence']['children']['batch_job_ids'] ?? array() ), 'cleanup evidence lists child batch jobs' );
	datamachine_code_cleanup_assert( array( 126, 127 ) === ( $evidence_json['evidence']['children']['chunk_job_ids'] ?? array() ), 'cleanup evidence lists child chunk jobs' );
	datamachine_code_cleanup_assert( false === ( $evidence_json['evidence']['storage']['filesystem_plans'] ?? true ), 'cleanup evidence does not depend on filesystem plan JSON' );
	datamachine_code_cleanup_assert( 'datamachine_jobs' === ( $evidence_json['evidence']['storage']['source'] ?? '' ), 'cleanup evidence declares Data Machine job DB source while cleanup tables are pending' );
	datamachine_code_cleanup_assert( 1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['skipped_by_reason']['dirty_worktree'] ?? 0 ), 'cleanup evidence aggregates skipped reasons' );
	datamachine_code_cleanup_assert( 1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['failed_by_reason']['apply_failed'] ?? 0 ), 'cleanup evidence aggregates failed reasons' );
	datamachine_code_cleanup_assert( 1 === (int) ( $evidence_json['evidence']['cleanup_items']['failed_by_reason']['apply_failed'] ?? 0 ), 'cleanup evidence reconstructs failed cleanup item reasons from job rows' );
	datamachine_code_cleanup_assert( 4 === count( $evidence_json['evidence']['child_jobs'] ?? array() ), 'cleanup evidence emits descendant child jobs' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'resume', 'cleanup-run-123' ), array( 'force' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( true === ( $retry_job_ability->last_input['force'] ?? null ), 'cleanup resume forwards force retry flag' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup( array( 'cancel', 'cleanup-run-123' ), array( 'format' => 'json' ) );
	datamachine_code_cleanup_assert( 'cleanup_cancelled' === ( $fail_job_ability->last_input['reason'] ?? '' ), 'cleanup cancel fails job with cleanup cancellation reason' );

	echo "\n[0] list stale output exposes disk fields\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'list' ), array( 'stale' => true ) );
	datamachine_code_cleanup_assert( in_array( 'table:2:handle,repo,kind,branch,head,dirty,state,liveness,last_seen_at,owner,agent,session,task,pr,age_days,size,artifacts,stale,path', WP_CLI::$logs, true ), 'worktree list --stale filters to stale rows and includes disk + liveness fields' );

	echo "\n[1] JSON output is one parseable document\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false, 'skip_github' => true, 'inventory_only' => false, 'include_repaired_metadata' => false ) === $ability->last_input, 'cleanup flags forwarded to ability' );
	datamachine_code_cleanup_assert( 1 === count( WP_CLI::$logs ), 'JSON path writes exactly one stdout log entry' );
	datamachine_code_cleanup_assert( array() === WP_CLI::$successes, 'JSON path emits no success suffix' );
	$decoded = json_decode( WP_CLI::$logs[0], true );
	datamachine_code_cleanup_assert( JSON_ERROR_NONE === json_last_error(), 'JSON output parses cleanly' );
	datamachine_code_cleanup_assert( 'dirty_worktree' === ( $decoded['skipped'][0]['reason_code'] ?? '' ), 'JSON rows include stable reason code' );
	datamachine_code_cleanup_assert( array( 'repo', 'branch', 'path' ) === ( $decoded['skipped'][11]['missing_fields'] ?? array() ), 'JSON missing_metadata includes missing fields' );
	datamachine_code_cleanup_assert( str_contains( $decoded['skipped'][11]['hint'] ?? '', 'workspace worktree prune' ), 'JSON missing_metadata includes remediation hint' );
	datamachine_code_cleanup_assert( 10 === (int) ( $decoded['summary']['skipped_by_reason']['no_merge_signal'] ?? 0 ), 'JSON summary includes reason counts' );
	datamachine_code_cleanup_assert( 3 === count( $decoded['summary']['skipped_next_commands'] ?? array() ), 'JSON summary includes actionable skipped next commands' );
	datamachine_code_cleanup_assert( str_contains( $decoded['summary']['skipped_next_commands'][0]['command'] ?? '', 'workspace cleanup run --mode=retention --older-than=7d' ), 'JSON repaired metadata command is task-backed retention cleanup' );
	datamachine_code_cleanup_assert( str_contains( $decoded['summary']['skipped_next_commands'][1]['command'] ?? '', 'reconcile-metadata-batch --dry-run --limit=25' ), 'JSON full-scan command is bounded metadata reconciliation' );
	datamachine_code_cleanup_assert( str_contains( $decoded['summary']['skipped_next_commands'][2]['command'] ?? '', 'finalize <handle> --pr=<pr-url>' ), 'JSON no-signal command records explicit review metadata' );

	echo "\n[1b] --apply-plan decodes JSON and forbids force\n";
	$plan_file = sys_get_temp_dir() . '/dmc-cleanup-plan-' . bin2hex( random_bytes( 3 ) ) . '.json';
	file_put_contents( $plan_file, wp_json_encode( datamachine_code_cleanup_report() ) );
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'apply-plan' => $plan_file, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( false === ( $ability->last_input['dry_run'] ?? null ), '--apply-plan does not imply dry-run' );
	datamachine_code_cleanup_assert( false === ( $ability->last_input['force'] ?? null ), '--apply-plan forwards force=false' );
	datamachine_code_cleanup_assert( 'repo@merged' === ( $ability->last_input['apply_plan']['candidates'][0]['handle'] ?? '' ), '--apply-plan forwards decoded plan' );
	try {
		$command->worktree( array( 'cleanup' ), array( 'apply-plan' => $plan_file, 'force' => true ) );
		datamachine_code_cleanup_assert( false, '--apply-plan --force throws' );
	} catch ( RuntimeException $e ) {
		datamachine_code_cleanup_assert( str_contains( $e->getMessage(), 'Do not combine --apply-plan with --force' ), '--apply-plan --force is rejected' );
	}
	unlink( $plan_file );

	echo "\n[2] default output is concise and summary-first\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true ) );
	datamachine_code_cleanup_assert( str_starts_with( WP_CLI::$logs[0] ?? '', 'Cleanup progress:' ), 'human output streams cleanup progress before summary' );
	datamachine_code_cleanup_assert( in_array( 'Summary:', WP_CLI::$logs, true ), 'human output includes summary after progress' );
	datamachine_code_cleanup_assert( in_array( 'table:1:handle,branch,age_days,size,artifacts,signal,reason_code', WP_CLI::$logs, true ), 'default candidate table uses compact disk fields' );
	datamachine_code_cleanup_assert( in_array( 'table:10:handle,reason_code,age_days,size,artifacts,reason', WP_CLI::$logs, true ), 'default skipped table omits path and hint fields but keeps disk fields' );
	datamachine_code_cleanup_assert( in_array( 'Top repos by worktree size:', WP_CLI::$logs, true ), 'human output includes top repo size summary' );
	datamachine_code_cleanup_assert( in_array( 'Next commands for skipped buckets:', WP_CLI::$logs, true ), 'human output includes actionable skipped command section' );
	datamachine_code_cleanup_assert( in_array( 'table:3:reason_code,count,destructive,command,alternative', WP_CLI::$logs, true ), 'human output renders compact skipped command table' );
	datamachine_code_cleanup_assert( in_array( 'Showing 10 of 15 skipped rows. Re-run with --verbose for all rows or --only=<reason_code> to filter.', WP_CLI::$logs, true ), 'human output truncates skipped rows with hint' );
	datamachine_code_cleanup_assert( 1 === count( WP_CLI::$successes ), 'human output keeps success suffix' );

	echo "\n[3] verbose output keeps detailed human fields\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'verbose' => true ) );
	datamachine_code_cleanup_assert( in_array( 'table:1:handle,branch,age_days,size,artifacts,signal,reason', WP_CLI::$logs, true ), 'verbose candidate table keeps full reason field' );
	datamachine_code_cleanup_assert( in_array( 'table:15:handle,reason_code,reason,age_days,size,artifacts,repo,branch,path,primary_path,missing,hint', WP_CLI::$logs, true ), 'verbose skipped table keeps diagnostic fields' );

	echo "\n[4] --only filters rows while keeping full summary\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'only' => 'missing-metadata', 'format' => 'json' ) );
	$filtered = json_decode( WP_CLI::$logs[0], true );
	datamachine_code_cleanup_assert( array() === ( $filtered['candidates'] ?? null ), '--only reason hides candidates' );
	datamachine_code_cleanup_assert( 1 === count( $filtered['skipped'] ?? array() ), '--only reason keeps matching skipped rows only' );
	datamachine_code_cleanup_assert( 'missing_metadata' === ( $filtered['skipped'][0]['reason_code'] ?? '' ), '--only alias resolves to reason code' );
	datamachine_code_cleanup_assert( 15 === (int) ( $filtered['summary']['skipped'] ?? 0 ), '--only leaves summary counts unfiltered' );

	echo "\n[5] --only aliases resolve candidate section\n";
	foreach ( array( 'candidates', 'would-remove', 'would_remove' ) as $alias ) {
		WP_CLI::$logs      = array();
		WP_CLI::$successes = array();
		$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'only' => $alias, 'format' => 'json' ) );
		$filtered = json_decode( WP_CLI::$logs[0], true );
		datamachine_code_cleanup_assert( 1 === count( $filtered['candidates'] ?? array() ), "--only={$alias} keeps candidates" );
		datamachine_code_cleanup_assert( array() === ( $filtered['removed'] ?? null ), "--only={$alias} hides removed" );
		datamachine_code_cleanup_assert( array() === ( $filtered['skipped'] ?? null ), "--only={$alias} hides skipped" );
		datamachine_code_cleanup_assert( 1 === (int) ( $filtered['summary']['would_remove'] ?? 0 ), "--only={$alias} keeps summary counts" );
	}

	echo "\n[6] --older-than forwards and renders age summary\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'older-than' => '7d' ) );
	datamachine_code_cleanup_assert( '7d' === ( $ability->last_input['older_than'] ?? null ), 'older-than forwards to cleanup ability as older_than' );
	datamachine_code_cleanup_assert( is_callable( $ability->last_input['progress_callback'] ?? null ), 'human cleanup passes progress callback to ability' );
	datamachine_code_cleanup_assert( in_array( 'table:13:metric,count', WP_CLI::$logs, true ), 'age filter and disk summary rows are rendered' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'older-than' => '7d', 'format' => 'json' ) );
	$older_than_json = json_decode( WP_CLI::$logs[0], true );
	datamachine_code_cleanup_assert( '7d' === ( $older_than_json['summary']['age_filter']['older_than'] ?? '' ), 'JSON summary exposes older_than filter value' );
	datamachine_code_cleanup_assert( 2 === (int) ( $older_than_json['summary']['age_filter']['excluded'] ?? 0 ), 'JSON summary exposes age-filter excluded count' );

	echo "\n[7] --sort forwards cleanup sorting field\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'sort' => 'size', 'format' => 'json' ) );
	datamachine_code_cleanup_assert( 'size' === ( $ability->last_input['sort'] ?? null ), '--sort forwards to cleanup ability' );

	echo "\n[8] --inventory-only forwards bounded cleanup review flag\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'inventory-only' => true, 'skip-github' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( true === ( $ability->last_input['inventory_only'] ?? null ), '--inventory-only forwards to cleanup ability' );
	$command->worktree( array( 'cleanup' ), array( 'dry-run' => true, 'inventory-only' => true, 'include-repaired-metadata' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( true === ( $ability->last_input['include_repaired_metadata'] ?? null ), '--include-repaired-metadata forwards to cleanup ability' );

	echo "\n[8b] emergency-cleanup keeps apply-plan as low-level escape hatch\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'emergency-cleanup' ), array( 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false ) === $emergency_ability->last_input, 'emergency-cleanup defaults to dry-run and no force' );
	$emergency_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'emergency' === ( $emergency_json['mode'] ?? '' ), 'emergency-cleanup JSON includes mode' );
	datamachine_code_cleanup_assert( 'target' === ( $emergency_json['artifact_candidates'][0]['artifacts'][0]['path'] ?? '' ), 'emergency-cleanup JSON includes artifact candidates first' );

	$emergency_plan_file = sys_get_temp_dir() . '/dmc-emergency-cleanup-plan-' . bin2hex( random_bytes( 3 ) ) . '.json';
	file_put_contents( $emergency_plan_file, wp_json_encode( $emergency_json ) );
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'emergency-cleanup' ), array( 'apply-plan' => $emergency_plan_file, 'force' => true ) );
	datamachine_code_cleanup_assert( false === ( $emergency_ability->last_input['dry_run'] ?? null ), 'emergency-cleanup apply-plan enters apply mode' );
	datamachine_code_cleanup_assert( true === ( $emergency_ability->last_input['force'] ?? null ), 'emergency-cleanup forwards explicit force for human-reviewed apply' );
	datamachine_code_cleanup_assert( 'repo@old' === ( $emergency_ability->last_input['apply_plan']['artifact_candidates'][0]['handle'] ?? '' ), 'emergency-cleanup forwards decoded apply plan' );
	datamachine_code_cleanup_assert( 'Emergency cleanup summary:' === ( WP_CLI::$logs[0] ?? '' ), 'emergency-cleanup human output uses emergency summary' );
	unlink( $emergency_plan_file );

	echo "\n[9] cleanup-artifacts forwards plan-first flags and renders separately\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup-artifacts' ), array( 'dry-run' => true, 'format' => 'json' ) );
	datamachine_code_cleanup_assert( array( 'dry_run' => true, 'force' => false ) === $artifact_ability->last_input, 'cleanup-artifacts dry-run flags forwarded to ability' );
	$artifact_json = json_decode( WP_CLI::$logs[0] ?? '', true );
	datamachine_code_cleanup_assert( 'target' === ( $artifact_json['candidates'][0]['artifacts'][0]['path'] ?? '' ), 'cleanup-artifacts JSON includes artifact paths' );

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup-artifacts' ), array( 'dry-run' => true ) );
	datamachine_code_cleanup_assert( str_contains( WP_CLI::$successes[0] ?? '', 'workspace cleanup run --mode=artifacts' ), 'cleanup-artifacts dry-run points daily apply path to task-backed cleanup' );
	datamachine_code_cleanup_assert( str_contains( WP_CLI::$successes[0] ?? '', 'low-level escape hatch' ), 'cleanup-artifacts dry-run demotes apply-plan wording' );
	datamachine_code_cleanup_assert( ! str_contains( WP_CLI::$successes[0] ?? '', 'Save JSON' ), 'cleanup-artifacts dry-run does not normalize saving plan files' );

	$artifact_plan_file = sys_get_temp_dir() . '/dmc-artifact-cleanup-plan-' . bin2hex( random_bytes( 3 ) ) . '.json';
	file_put_contents( $artifact_plan_file, wp_json_encode( $artifact_json ) );
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree( array( 'cleanup-artifacts' ), array( 'apply-plan' => $artifact_plan_file, 'force' => true ) );
	datamachine_code_cleanup_assert( false === ( $artifact_ability->last_input['dry_run'] ?? null ), 'cleanup-artifacts apply-plan enters apply mode' );
	datamachine_code_cleanup_assert( true === ( $artifact_ability->last_input['force'] ?? null ), 'cleanup-artifacts forwards explicit force for dirty/unpushed artifacts' );
	datamachine_code_cleanup_assert( 'repo@old' === ( $artifact_ability->last_input['apply_plan']['candidates'][0]['handle'] ?? '' ), 'cleanup-artifacts forwards decoded apply plan' );
	datamachine_code_cleanup_assert( 'Artifact cleanup summary:' === ( WP_CLI::$logs[0] ?? '' ), 'cleanup-artifacts human output uses artifact-specific summary' );
	unlink( $artifact_plan_file );

	echo "\nAll worktree cleanup CLI smoke tests passed.\n";
}
