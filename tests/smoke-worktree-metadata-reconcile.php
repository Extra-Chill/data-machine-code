<?php
/**
 * Smoke test for unmanaged worktree metadata reconciliation.
 *
 * Run: php tests/smoke-worktree-metadata-reconcile.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists( __NAMESPACE__ . '\FilesystemHelper' ) ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace DataMachineCode\Abilities {
	if ( ! class_exists( __NAMESPACE__ . '\GitHubAbilities' ) ) {
		class GitHubAbilities {
			public static function getPat(): string {
				return 'test-token';
			}
			public static function apiGet( string $url, array $params, string $pat ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				if ( str_ends_with( $url, '/pulls/101' ) ) {
					return array(
						'data' => array(
							'number'    => 101,
							'state'     => 'closed',
							'merged_at' => '2026-04-03T00:00:00Z',
							'html_url'  => 'https://github.com/acme/demo/pull/101',
						),
					);
				}
				if ( str_ends_with( $url, '/pulls/102' ) ) {
					return array(
						'data' => array(
							'number'    => 102,
							'state'     => 'closed',
							'merged_at' => '',
							'html_url'  => 'https://github.com/acme/demo/pull/102',
						),
					);
				}
				return array( 'data' => array() );
			}
		}
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	if ( ! class_exists( __NAMESPACE__ . '\\SystemTask' ) ) {
		abstract class SystemTask {
			abstract public function executeTask( int $jobId, array $params ): void;
			abstract public function getTaskType(): string;
			protected function completeJob( int $jobId, array $data ): void {
				$GLOBALS['datamachine_code_reconcile_chunk_jobs'][ $jobId ] = $data;
			}
			protected function failJob( int $jobId, string $message ): void {
				$GLOBALS['datamachine_code_reconcile_chunk_jobs'][ $jobId ] = array( 'error' => $message );
			}
		}
	}
}

namespace DataMachine\Engine\Tasks {
	if ( ! class_exists( __NAMESPACE__ . '\\TaskScheduler' ) ) {
		class TaskScheduler {
			public static array $batches = array();

			public static function scheduleBatch( string $task_type, array $items, array $context = array() ) {
				self::$batches[] = array(
					'task_type' => $task_type,
					'items'     => $items,
					'context'   => $context,
				);

				return array(
					'batch_job_id' => 901,
					'job_ids'      => range( 1001, 1000 + count( $items ) ),
				);
			}
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;
			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = (array) $data;
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_code(): string {
				return $this->code;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $path ): bool {
			return is_dir( $path ) || mkdir( $path, 0755, true );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default_value = false ) {
			global $datamachine_code_test_options;
			return $datamachine_code_test_options[ $name ] ?? $default_value;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			global $datamachine_code_test_options;
			$datamachine_code_test_options[ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $value;
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		function home_url(): string {
			return 'http://origin.test';
		}
	}

	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( string $show = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return 'Origin Test';
		}
	}

	if ( ! function_exists( 'size_format' ) ) {
		function size_format( $bytes ): string {
			return (string) $bytes;
		}
	}

	require __DIR__ . '/../inc/Support/GitHubRemote.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	require __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';
	require __DIR__ . '/../inc/Tasks/WorktreeCleanupChunkTask.php';

	exec( 'git --version 2>&1', $_gv, $gv_exit );
	if ( 0 !== $gv_exit ) {
		echo "SKIP: git not available\n";
		exit( 0 );
	}

	$tmp = sys_get_temp_dir() . '/dmc-reconcile-smoke-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp, 0755, true );
	register_shutdown_function( function () use ( $tmp ) {
		if ( is_dir( $tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		}
		if ( is_dir( $tmp . '-external' ) ) {
			exec( 'rm -rf ' . escapeshellarg( $tmp . '-external' ) );
		}
	} );

	define( 'DATAMACHINE_WORKSPACE_PATH', realpath( $tmp ) ? realpath( $tmp ) : $tmp );

	$failures = 0;
	$total    = 0;
	$datamachine_code_test_options = array();
	$GLOBALS['datamachine_code_reconcile_chunk_jobs'] = array();

	$assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $expected === $actual ) {
			echo "  ✓ {$message}\n";
			return;
		}
		$failures++;
		echo "  ✗ {$message}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$run = function ( string $cmd, string $cwd = '' ): array {
		$full = '' === $cwd ? $cmd : sprintf( 'cd %s && %s', escapeshellarg( $cwd ), $cmd );
		exec( $full . ' 2>&1', $out, $rc );
		return array(
			'output' => implode( "\n", $out ),
			'exit'   => $rc,
		);
	};

	echo "Setting up workspace at {$tmp}\n";
	$remote  = $tmp . '/remote.git';
	$primary = $tmp . '/demo';
	$run( sprintf( 'git init --bare %s', escapeshellarg( $remote ) ) );
	$run( sprintf( 'git clone %s %s', escapeshellarg( $remote ), escapeshellarg( $primary ) ) );
	$run( 'git config user.email test@example.com', $primary );
	$run( 'git config user.name test', $primary );
	file_put_contents( $primary . '/README.md', "demo\n" );
	$run( 'git add README.md && git commit -m init', $primary );
	$run( 'git branch -M main', $primary );
	$run( 'git push -u origin main', $primary );

	$make_branch = function ( string $branch ) use ( $primary, $run ): void {
		$run( sprintf( 'git checkout -b %s', escapeshellarg( $branch ) ), $primary );
		file_put_contents( $primary . '/' . str_replace( '/', '-', $branch ) . '.txt', $branch );
		$run( sprintf( 'git add . && git commit -m %s', escapeshellarg( 'work on ' . $branch ) ), $primary );
		$run( sprintf( 'git push -u origin %s', escapeshellarg( $branch ) ), $primary );
		$run( 'git checkout main', $primary );
	};
	$make_branch( 'unmanaged-missing' );
	$make_branch( 'unmanaged-partial' );
	$make_branch( 'unmanaged-dirty' );
	$make_branch( 'unmanaged-invalid' );
	$make_branch( 'already-current' );
	$make_branch( 'pr-merged' );
	$make_branch( 'pr-closed' );
	$make_branch( 'upstream-gone' );
	$make_branch( 'dirty-merged' );
	$make_branch( 'unpushed-merged' );
	$make_branch( 'external-branch' );

	$run( sprintf( 'git worktree add %s unmanaged-missing', escapeshellarg( $tmp . '/demo@unmanaged-missing' ) ), $primary );
	$run( sprintf( 'git worktree add %s unmanaged-partial', escapeshellarg( $tmp . '/demo@unmanaged-partial' ) ), $primary );
	$run( sprintf( 'git worktree add %s unmanaged-dirty', escapeshellarg( $tmp . '/demo@unmanaged-dirty' ) ), $primary );
	$run( sprintf( 'git worktree add %s unmanaged-invalid', escapeshellarg( $tmp . '/demo@unmanaged-invalid' ) ), $primary );
	$run( sprintf( 'git worktree add %s already-current', escapeshellarg( $tmp . '/demo@already-current' ) ), $primary );
	$run( sprintf( 'git worktree add %s pr-merged', escapeshellarg( $tmp . '/demo@pr-merged' ) ), $primary );
	$run( sprintf( 'git worktree add %s pr-closed', escapeshellarg( $tmp . '/demo@pr-closed' ) ), $primary );
	$run( sprintf( 'git worktree add %s upstream-gone', escapeshellarg( $tmp . '/demo@upstream-gone' ) ), $primary );
	$run( sprintf( 'git worktree add %s dirty-merged', escapeshellarg( $tmp . '/demo@dirty-merged' ) ), $primary );
	$run( sprintf( 'git worktree add %s unpushed-merged', escapeshellarg( $tmp . '/demo@unpushed-merged' ) ), $primary );
	mkdir( $tmp . '-external', 0755, true );
	$run( sprintf( 'git worktree add %s external-branch', escapeshellarg( $tmp . '-external/demo-external' ) ), $primary );
	file_put_contents( $tmp . '/demo@unmanaged-dirty/scratch.txt', 'dirty' );
	file_put_contents( $tmp . '/demo@dirty-merged/scratch.txt', 'dirty' );
	file_put_contents( $tmp . '/demo@unpushed-merged/local.txt', 'local' );
	$run( 'git add local.txt && git commit -m local-unpushed', $tmp . '/demo@unpushed-merged' );
	$run( sprintf( 'git --git-dir=%s update-ref -d refs/heads/upstream-gone', escapeshellarg( $remote ) ) );
	$run( sprintf( 'git --git-dir=%s update-ref -d refs/heads/dirty-merged', escapeshellarg( $remote ) ) );

	\DataMachineCode\Workspace\WorktreeContextInjector::store_metadata(
		'demo@unmanaged-partial',
		array(
			'site_url'   => 'http://example.test',
			'site_name'  => 'Example',
			'agent_slug' => 'agent-one',
			'abspath'    => '/example',
			'timestamp'  => '2026-04-01T00:00:00+00:00',
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@unmanaged-invalid',
		array(
			'handle'          => 'demo@unmanaged-invalid',
			'repo'            => 'demo',
			'branch'          => 'unmanaged-invalid',
			'path'            => $tmp . '/demo@unmanaged-invalid',
			'created_at'      => '2026-04-02T00:00:00+00:00',
			'lifecycle_state' => 'donezo',
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@already-current',
		array(
			'handle'          => 'demo@already-current',
			'repo'            => 'demo',
			'branch'          => 'already-current',
			'path'            => $tmp . '/demo@already-current',
			'created_at'      => '2026-04-01T00:00:00+00:00',
			'observed_at'     => '2026-04-01T00:00:00+00:00',
			'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@pr-merged',
		array(
			'handle'          => 'demo@pr-merged',
			'repo'            => 'demo',
			'branch'          => 'pr-merged',
			'path'            => $tmp . '/demo@pr-merged',
			'created_at'      => '2026-04-01T00:00:00+00:00',
			'observed_at'     => '2026-04-01T00:00:00+00:00',
			'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
			'pr_url'          => 'https://github.com/acme/demo/pull/101',
			'pr_number'       => 101,
			'pr_repo'         => 'acme/demo',
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@unpushed-merged',
		array(
			'handle'          => 'demo@unpushed-merged',
			'repo'            => 'demo',
			'branch'          => 'unpushed-merged',
			'path'            => $tmp . '/demo@unpushed-merged',
			'created_at'      => '2026-04-01T00:00:00+00:00',
			'observed_at'     => '2026-04-01T00:00:00+00:00',
			'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
			'pr_url'          => 'https://github.com/acme/demo/pull/101',
			'pr_number'       => 101,
			'pr_repo'         => 'acme/demo',
		)
	);
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@pr-closed',
		array(
			'handle'          => 'demo@pr-closed',
			'repo'            => 'demo',
			'branch'          => 'pr-closed',
			'path'            => $tmp . '/demo@pr-closed',
			'created_at'      => '2026-04-01T00:00:00+00:00',
			'observed_at'     => '2026-04-01T00:00:00+00:00',
			'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
			'pr_url'          => 'https://github.com/acme/demo/pull/102',
			'pr_number'       => 102,
			'pr_repo'         => 'acme/demo',
		)
	);

	$ws = new \DataMachineCode\Workspace\Workspace();

	echo "\nDry-run reconciliation\n";
	$plan = $ws->worktree_reconcile_metadata( array( 'dry_run' => true ) );
	$assert( true, ! is_wp_error( $plan ) && ( $plan['success'] ?? false ), 'dry-run succeeds' );
	$assert( true, $plan['dry_run'] ?? false, 'dry-run flag is true' );
	$assert( 7, (int) ( $plan['summary']['proposed'] ?? 0 ), 'dry-run proposes unmanaged rows and safe merged lifecycle finalizers' );
	$assert( 0, (int) ( $plan['summary']['written'] ?? 0 ), 'dry-run writes nothing' );
	$assert( 1, (int) ( $plan['summary']['skipped_by_reason']['external_worktree'] ?? 0 ), 'dry-run distinguishes external worktrees' );
	$assert( 2, (int) ( $plan['summary']['skipped_by_reason']['unsafe_cleanup_eligible_state'] ?? 0 ), 'dry-run keeps dirty and unpushed merged worktrees out of auto-finalize proposals' );
	$assert( 1, count( $plan['external_worktrees'] ?? array() ), 'dry-run exposes external worktree bucket' );

	$by_handle = array();
	foreach ( $plan['proposals'] as $row ) {
		$by_handle[ $row['handle'] ] = $row;
	}
	$assert( 'operator_plan', $by_handle['demo@unmanaged-missing']['source_map']['lifecycle_state'] ?? '', 'missing metadata lifecycle source is operator_plan' );
	$assert( 'filesystem', $by_handle['demo@unmanaged-missing']['source_map']['created_at'] ?? '', 'missing metadata created_at source is filesystem' );
	$assert( 'reconcile_run', $by_handle['demo@unmanaged-missing']['source_map']['observed_at'] ?? '', 'missing metadata observed_at source is reconcile run' );
	$assert( 'current_site', $by_handle['demo@unmanaged-missing']['source_map']['origin_site'] ?? '', 'missing metadata origin site is inferred from current site' );
	$assert( 'metadata', $by_handle['demo@unmanaged-partial']['source_map']['created_at'] ?? '', 'partial metadata preserves created_at source' );
	$assert( 'git', $by_handle['demo@unmanaged-partial']['source_map']['branch'] ?? '', 'branch source is git' );
	$assert( array( 'lifecycle_state' ), $by_handle['demo@unmanaged-invalid']['invalid_fields'] ?? array(), 'invalid lifecycle state is planned for repair' );
	$assert( \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $by_handle['demo@unmanaged-invalid']['proposed_metadata']['lifecycle_state'] ?? '', 'invalid lifecycle state becomes conservative active proposal' );
	$assert( 1, (int) ( $by_handle['demo@unmanaged-dirty']['dirty'] ?? 0 ), 'dirty row is visible but not cleanup-eligible' );
	$assert( \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $by_handle['demo@unmanaged-dirty']['proposed_metadata']['lifecycle_state'] ?? '', 'dirty row proposal stays active' );
	$assert( 'auto_finalize_merged', $by_handle['demo@pr-merged']['reason_code'] ?? '', 'merged stored PR is proposed for auto-finalization' );
	$assert( 'pr-merged', $by_handle['demo@pr-merged']['signal'] ?? '', 'stored PR proposal records pr-merged signal' );
	$assert( 'cleanup_eligible', $by_handle['demo@pr-merged']['proposed_metadata']['lifecycle_state'] ?? '', 'stored PR proposal becomes cleanup_eligible metadata' );
	$assert( 'merged', $by_handle['demo@pr-merged']['proposed_metadata']['finalized_state'] ?? '', 'stored PR proposal preserves merged finalized state' );
	$assert( 'auto_finalize_merged', $by_handle['demo@pr-closed']['reason_code'] ?? '', 'closed stored PR is proposed for auto-finalization' );
	$assert( 'pr-closed', $by_handle['demo@pr-closed']['signal'] ?? '', 'closed stored PR proposal records pr-closed signal' );
	$assert( 'closed', $by_handle['demo@pr-closed']['proposed_metadata']['finalized_state'] ?? '', 'closed stored PR preserves closed finalized state' );
	$assert( 'pr-closed', $by_handle['demo@pr-closed']['proposed_metadata']['cleanup_eligibility_evidence']['signal'] ?? '', 'closed stored PR records cleanup eligibility evidence' );
	$assert( 'auto_finalize_merged', $by_handle['demo@upstream-gone']['reason_code'] ?? '', 'upstream-gone branch is proposed for auto-finalization' );
	$assert( 'upstream-gone', $by_handle['demo@upstream-gone']['signal'] ?? '', 'upstream-gone proposal records local merge signal' );
	$unsafe_by_handle = array();
	foreach ( $plan['skipped'] as $row ) {
		$unsafe_by_handle[ $row['handle'] ?? '' ] = $row;
	}
	$assert( 'unsafe_cleanup_eligible_state', $unsafe_by_handle['demo@dirty-merged']['reason_code'] ?? '', 'dirty merged worktree is not auto-finalized' );
	$assert( 'unsafe_cleanup_eligible_state', $unsafe_by_handle['demo@unpushed-merged']['reason_code'] ?? '', 'unpushed merged worktree is not auto-finalized' );
	$stale_plan  = $plan;
	$unsafe_plan = $plan;

	$page = $ws->worktree_reconcile_metadata( array( 'dry_run' => true, 'limit' => 2, 'offset' => 2 ) );
	$assert( true, ! is_wp_error( $page ) && ( $page['success'] ?? false ), 'paginated dry-run succeeds' );
	$assert( 2, (int) ( $page['pagination']['limit'] ?? 0 ), 'paginated dry-run reports limit' );
	$assert( 2, (int) ( $page['pagination']['offset'] ?? 0 ), 'paginated dry-run reports offset' );
	$assert( 2, (int) ( $page['pagination']['scanned'] ?? 0 ), 'paginated dry-run scans only requested page' );
	$assert( true, (bool) ( $page['pagination']['partial'] ?? false ), 'paginated dry-run reports continuation' );
	$assert( 4, (int) ( $page['pagination']['next_offset'] ?? 0 ), 'paginated dry-run advances next offset' );
	$assert( 2, (int) ( $page['summary']['inspected'] ?? 0 ), 'paginated dry-run summary is page-scoped' );
	$assert( true, isset( $page['evidence']['fields_skipped_by_listing'] ), 'paginated dry-run exposes listing evidence' );

	\DataMachine\Engine\Tasks\TaskScheduler::$batches = array();
	$job_backed = $ws->worktree_reconcile_metadata( array( 'apply' => true, 'via_jobs' => true, 'limit' => 3 ) );
	$assert( true, ! is_wp_error( $job_backed ) && ( $job_backed['success'] ?? false ), 'job-backed reconciliation scheduling succeeds' );
	$assert( true, (bool) ( $job_backed['job_backed'] ?? false ), 'job-backed reconciliation reports job_backed' );
	$assert( false, (bool) ( $job_backed['applied'] ?? true ), 'job-backed parent does not write metadata synchronously' );
	$assert( true, (int) ( $job_backed['summary']['scheduled_jobs'] ?? 0 ) >= 4, 'job-backed reconciliation schedules bounded page jobs' );
	$scheduled_batch = \DataMachine\Engine\Tasks\TaskScheduler::$batches[0] ?? array();
	$assert( 'worktree_cleanup_chunk', $scheduled_batch['task_type'] ?? '', 'job-backed reconciliation uses cleanup chunk task' );
	$assert( 'metadata_reconciliation_page', $scheduled_batch['items'][0]['chunk_type'] ?? '', 'scheduled reconciliation job uses metadata page chunk type' );
	$assert( array( 0, 3, 6, 9 ), array_slice( array_column( (array) ( $scheduled_batch['items'] ?? array() ), 'offset' ), 0, 4 ), 'scheduled reconciliation jobs carry bounded offsets' );
	$assert( '', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@unmanaged-missing' )['handle'] ?? '', 'job-backed parent leaves metadata untouched until jobs run' );
	$bad_job_backed = $ws->worktree_reconcile_metadata( array( 'dry_run' => true, 'via_jobs' => true, 'limit' => 3 ) );
	$assert( true, is_wp_error( $bad_job_backed ), 'job-backed reconciliation rejects dry-run mode' );

	$inventory_before = $ws->worktree_cleanup_merged( array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ) );
	$assert( 4, (int) ( $inventory_before['summary']['skipped_by_reason']['needs_metadata_reconcile'] ?? 0 ), 'inventory cleanup sees missing metadata before apply' );

	echo "\nApply reviewed plan\n";
	$apply = $ws->worktree_reconcile_metadata( array( 'apply_plan' => $plan ) );
	$assert( true, ! is_wp_error( $apply ) && ( $apply['success'] ?? false ), 'apply succeeds' );
	$assert( 7, (int) ( $apply['summary']['written'] ?? 0 ), 'apply writes exact current matches' );
	$assert( 7, (int) ( $apply['summary']['written'] ?? 0 ), 'apply reports written metadata rows' );
	$assert( 0, (int) ( $apply['summary']['skipped'] ?? 0 ), 'apply skips nothing for current plan' );
	$assert( 7, count( $apply['written'] ?? array() ), 'apply exposes written rows distinctly' );
	$stored = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@unmanaged-missing' );
	$assert( 'demo@unmanaged-missing', $stored['handle'] ?? '', 'stored metadata includes handle' );
	$assert( true, ! empty( $stored['observed_at'] ), 'stored metadata includes observed_at' );
	$assert( 'Origin Test', $stored['origin_site'] ?? '', 'stored metadata includes origin site when available' );
	$assert( 'operator_plan', $stored['reconciled_sources']['lifecycle_state'] ?? '', 'stored metadata keeps source map' );
	$stored_pr = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@pr-merged' );
	$assert( 'cleanup_eligible', $stored_pr['lifecycle_state'] ?? '', 'apply stores cleanup_eligible for merged PR worktree' );
	$assert( 'merged', $stored_pr['finalized_state'] ?? '', 'apply stores merged finalizer state for merged PR worktree' );
	$stored_gone = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@upstream-gone' );
	$assert( 'cleanup_eligible', $stored_gone['lifecycle_state'] ?? '', 'apply stores cleanup_eligible for upstream-gone worktree' );
	$assert( 'upstream-gone', $stored_gone['cleanup_eligibility_evidence']['signal'] ?? '', 'apply stores upstream-gone cleanup eligibility evidence' );
	$stored_closed = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@pr-closed' );
	$assert( 'cleanup_eligible', $stored_closed['lifecycle_state'] ?? '', 'apply stores cleanup_eligible for closed PR worktree' );
	$assert( 'closed', $stored_closed['cleanup_eligibility_evidence']['finalized_state'] ?? '', 'apply stores closed PR cleanup eligibility evidence' );

	$all_metadata = get_option( \DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, array() );
	$all_metadata['demo@unmanaged-missing']['durability_marker'] = 'option-repair-wins';
	update_option( \DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, $all_metadata, false );
	$durable = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@unmanaged-missing' );
	$assert( 'option-repair-wins', $durable['durability_marker'] ?? '', 'option-backed metadata repair remains visible over DB fallback' );

	$auto_apply = $ws->worktree_reconcile_metadata( array( 'apply' => true ) );
	$assert( true, ! is_wp_error( $auto_apply ) && ( $auto_apply['success'] ?? false ), 'DMC-owned reconciliation apply path runs without a manual plan' );
	$bounded_auto_apply = $ws->worktree_reconcile_metadata( array( 'apply' => true, 'limit' => 2, 'offset' => 2 ) );
	$assert( true, ! is_wp_error( $bounded_auto_apply ) && ( $bounded_auto_apply['success'] ?? false ), 'bounded direct reconciliation apply runs without a manual plan file' );
	$assert( true, (bool) ( $bounded_auto_apply['direct_apply'] ?? false ), 'bounded direct apply identifies direct apply source' );
	$assert( false, (bool) ( $bounded_auto_apply['dry_run'] ?? true ), 'bounded direct apply is not a dry-run' );
	$assert( 2, (int) ( $bounded_auto_apply['summary']['inspected'] ?? 0 ), 'bounded direct apply summary stays page-scoped' );
	$assert( 2, (int) ( $bounded_auto_apply['pagination']['limit'] ?? 0 ), 'bounded direct apply preserves pagination limit' );
	$assert( 2, (int) ( $bounded_auto_apply['pagination']['offset'] ?? 0 ), 'bounded direct apply preserves pagination offset' );
	$assert( 'direct_apply', $bounded_auto_apply['evidence']['apply_source'] ?? '', 'bounded direct apply exposes evidence source' );

	$inventory_after = $ws->worktree_cleanup_merged( array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ) );
	$assert( 1, (int) ( $inventory_after['summary']['skipped_by_reason']['needs_metadata_reconcile'] ?? 0 ), 'inventory cleanup requires fewer metadata reconciliation passes after apply' );
	$assert( 5, (int) ( $inventory_after['summary']['skipped_by_reason']['active_no_signal'] ?? 0 ), 'inventory cleanup treats reconciled active metadata like current active metadata' );
	$assert( false, isset( $inventory_after['summary']['repair_status'] ), 'inventory cleanup no longer exposes migration status' );

	echo "\nSafety gates\n";
	$stale_plan['proposals'][0]['branch'] = 'wrong-branch';
	$stale_apply = $ws->worktree_reconcile_metadata( array( 'apply_plan' => $stale_plan ) );
	$assert( 'plan_identity_mismatch', $stale_apply['skipped'][0]['reason_code'] ?? '', 'apply revalidates branch identity before writing' );

	foreach ( $unsafe_plan['proposals'] as &$row ) {
		if ( 'demo@unmanaged-dirty' === $row['handle'] ) {
			$row['proposed_metadata']['lifecycle_state'] = \DataMachineCode\Workspace\WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE;
			$row['source_map']['lifecycle_state']        = 'operator_plan';
		}
	}
	unset( $row );
	$unsafe_apply = $ws->worktree_reconcile_metadata( array( 'apply_plan' => $unsafe_plan ) );
	$unsafe_skips = array_values( array_filter( $unsafe_apply['skipped'] ?? array(), fn( $row ) => 'demo@unmanaged-dirty' === ( $row['handle'] ?? '' ) ) );
	$assert( 'unsafe_cleanup_eligible_state', $unsafe_skips[0]['reason_code'] ?? '', 'dirty worktree cannot become cleanup_eligible through reconciliation plan' );
	$assert( 1, count( $unsafe_apply['still_unsafe'] ?? array() ), 'apply exposes still-unsafe rows distinctly' );

	$task = new \DataMachineCode\Tasks\WorktreeCleanupChunkTask();
	$task->executeTask( 1201, array( 'chunk_type' => 'metadata_reconciliation_page', 'limit' => 2, 'offset' => 0 ) );
	$job_result = $GLOBALS['datamachine_code_reconcile_chunk_jobs'][1201] ?? array();
	$assert( true, (bool) ( $job_result['success'] ?? false ), 'metadata reconciliation page job completes successfully' );
	$assert( 'metadata_reconciliation_page', $job_result['chunk_type'] ?? '', 'metadata reconciliation page job records chunk type' );
	$assert( true, isset( $job_result['evidence']['summary'] ), 'metadata reconciliation page job records summary evidence' );

	if ( $failures > 0 ) {
		echo "\n{$failures} / {$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$total} worktree metadata reconciliation assertions passed.\n";
}
