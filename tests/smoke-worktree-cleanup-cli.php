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
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__);
    }

    class WP_CLI
    {
        public static array $logs = array();
        public static array $successes = array();
        public static array $runcommands = array();

        public static function error( string $message ): void
        {
            throw new RuntimeException($message);
        }

        public static function success( string $message ): void
        {
            self::$successes[] = $message;
        }

        public static function log( string $message ): void
        {
            self::$logs[] = $message;
        }

        public static function warning( string $message ): void
        {
            self::$logs[] = 'warning: ' . $message;
        }

        public static function runcommand( string $command, array $options = array() ): string
        {
            self::$runcommands[] = $command;
            if ('datamachine drain --job-id=123' === $command ) {
                $GLOBALS['datamachine_code_cleanup_parent_drained'] = true;
            }
            if ('datamachine drain --job-id=125' === $command ) {
                $GLOBALS['datamachine_code_cleanup_child_drained'] = true;
            }
            return '';
        }
    }

    function is_wp_error( $value ): bool
    {
        return false;
    }

    function wp_get_ability( string $name )
    {
        return $GLOBALS['__abilities'][ $name ] ?? null;
    }

    function wp_json_encode( $data, int $flags = 0 )
    {
        return json_encode($data, $flags);
    }
}

namespace DataMachine\Cli {
    class BaseCommand
    {
        protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void
        {
            \WP_CLI::log('table:' . count($items) . ':' . implode(',', $fields));
        }
    }
}

namespace {
    include_once dirname(__DIR__) . '/inc/Cleanup/CleanupRunEvidenceStoreInterface.php';
    include_once dirname(__DIR__) . '/inc/Cleanup/CleanupRemainingWorkSummary.php';
    include_once dirname(__DIR__) . '/inc/Cleanup/DataMachineJobCleanupRunEvidenceStore.php';
    include_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

    function datamachine_code_cleanup_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    function datamachine_code_cleanup_report(): array
    {
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
        'reason_code'         => 'needs_metadata_reconcile',
        'reason'              => 'inventory row has no lifecycle metadata; metadata reconciliation is required before cleanup planning can classify it',
        'missing_fields'      => array( 'repo', 'branch', 'path' ),
        'hint'                => 'Run workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json to generate reviewed metadata reconciliation rows.',
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
        'reason_code' => 'needs_metadata_reconcile',
        'reason'      => 'inventory row has no lifecycle metadata; metadata reconciliation is required before cleanup planning can classify it',
        );
        $skipped[] = array(
        'handle'      => 'repo@needs-review',
        'repo'        => 'repo',
        'branch'      => 'needs-review',
        'path'        => '/workspace/repo@needs-review',
        'reason_code' => 'active_no_signal',
        'reason'      => 'active lifecycle metadata has no cleanup signal; leaving in place',
        );
        $skipped[] = array(
        'handle'      => 'repo@needs-lifecycle-reconcile',
        'repo'        => 'repo',
        'branch'      => 'needs-lifecycle-reconcile',
        'path'        => '/workspace/repo@needs-lifecycle-reconcile',
        'reason_code' => 'lifecycle_reconciliation_candidate',
        'reason'      => 'stale or PR/task-backed lifecycle metadata has no cleanup signal; reconcile lifecycle state before cleanup eligibility is decided',
        );
		$skipped[] = array(
		'handle'      => 'repo@unpushed',
		'repo'        => 'repo',
		'branch'      => 'unpushed',
		'path'        => '/workspace/repo@unpushed',
		'reason_code' => 'unpushed_commits',
		'reason'      => 'worktree has commits ahead of upstream; leaving in place',
		);
		$skipped[] = array(
		'handle'      => 'repo@broken-marker',
		'repo'        => 'repo',
		'branch'      => 'broken-marker',
		'path'        => '/workspace/repo@broken-marker',
		'primary_path' => '/workspace/repo',
		'reason_code' => 'stale_worktree_marker',
		'reason'      => 'git worktree metadata marker is stale or missing',
		);
		$skipped[] = array(
		'handle'      => 'missing-primary@feature',
		'repo'        => 'missing-primary',
		'branch'      => 'feature',
		'path'        => '/workspace/missing-primary@feature',
		'reason_code' => 'primary_missing',
		'reason'      => 'primary checkout missing; refusing cleanup',
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
        'skipped'              => count($skipped),
        'skipped_by_reason'    => array(
                    'active_no_signal'                    => 1,
                    'dirty_worktree'                       => 1,
                    'lifecycle_reconciliation_candidate'  => 1,
                    'needs_metadata_reconcile'             => 2,
                    'no_merge_signal'                      => 10,
					'primary_missing'                      => 1,
                    'repaired_metadata'                    => 1,
					'stale_worktree_marker'                => 1,
					'unpushed_commits'                     => 1,
        ),
        'cleanup_buckets'       => array(
        'blocked_by_dirty_or_unpushed'       => 1,
        'needs_full_review'                  => 11,
        'needs_reconciliation'               => 3,
        'safe_to_remove_now'                 => 1,
        'active_no_signal'                    => 1,
        'dirty_unpushed'                      => 1,
        'explicit_cleanup_candidates'         => 0,
        'lifecycle_reconciliation_candidates' => 1,
        'metadata_reconciliation_candidates'  => 2,
        ),
        'stale_reasons'        => array(
        'dirty' => 1,
        ),
        'liveness'             => array(
        'stale' => 1,
        ),
        'skipped_next_commands' => array(
        array(
                        'reason_code' => 'lifecycle_reconciliation_candidate',
                        'count'       => 1,
                        'command'     => 'studio wp datamachine-code workspace worktree cleanup --dry-run --format=json',
                        'alternative' => 'studio wp datamachine-code workspace cleanup plan --mode=retention --format=json',
                        'destructive' => false,
        ),
        array(
         'reason_code' => 'needs_metadata_reconcile',
         'count'       => 2,
         'command'     => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json',
         'alternative' => 'Low-level apply still requires a reviewed --apply-plan=<file> until DB-backed cleanup runs land.',
         'destructive' => false,
        ),
        array(
         'reason_code' => 'active_no_signal',
         'count'       => 1,
         'command'     => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=25 --offset=0 --format=json',
         'alternative' => 'studio wp datamachine-code workspace worktree active-no-signal-merged-apply --dry-run --limit=25 --offset=0 --format=json',
         'destructive' => false,
        ),
        array(
         'reason_code' => 'no_merge_signal',
         'count'       => 10,
         'command'     => 'studio wp datamachine-code workspace worktree active-no-signal-report --limit=25 --offset=0 --format=json',
         'alternative' => 'studio wp datamachine-code workspace worktree active-no-signal-finalized-apply --dry-run --limit=25 --offset=0 --format=json',
         'destructive' => false,
        ),
        array(
         'reason_code' => 'repaired_metadata',
         'count'       => 1,
         'command'     => 'studio wp datamachine-code workspace cleanup run --mode=retention --older-than=7d',
         'alternative' => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25 --older-than=7d',
         'destructive' => true,
        ),
		array(
		 'reason_code' => 'dirty_worktree',
		 'count'       => 1,
		 'command'     => 'git -C <worktree-path> status --short --branch --untracked-files=normal',
		 'alternative' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=dirty_worktree --verbose --format=json',
		 'destructive' => false,
		),
		array(
		 'reason_code' => 'unpushed_commits',
		 'count'       => 1,
		 'command'     => 'git -C <worktree-path> log --oneline --decorate @{u}..HEAD',
		 'alternative' => 'studio wp datamachine-code workspace cleanup run --mode=retention --dry-run --only=unpushed_commits --verbose --format=json',
		 'destructive' => false,
		),
		array(
		 'reason_code' => 'stale_worktree_marker',
		 'count'       => 1,
		 'command'     => 'git -C <primary-path> worktree prune --dry-run --verbose',
		 'alternative' => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --format=json',
		 'destructive' => false,
		),
		array(
		 'reason_code' => 'primary_missing',
		 'count'       => 1,
		 'command'     => 'studio wp datamachine-code workspace show <repo>',
		 'alternative' => 'Recreate with `studio wp datamachine-code workspace clone <remote-url> --name=<repo>` or adopt an existing checkout with `studio wp datamachine-code workspace adopt <path> --name=<repo>`.',
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

    class FakeCleanupAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            if (isset($input['progress_callback']) && is_callable($input['progress_callback']) ) {
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
            if (! empty($input['inventory_only']) ) {
                $report['inventory_only']              = true;
                $report['summary']['apply_command']    = 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=1';
                $report['summary']['bounded_cleanup_eligible_apply'] = array(
                'review_command' => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=1 --dry-run',
                'apply_command'  => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=1',
                );
            }
            if (isset($input['older_than']) ) {
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

    class FakeArtifactCleanupAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $apply_command = 'studio wp datamachine-code workspace cleanup run --mode=artifacts';
            if ( ! empty($input['exhaustive']) ) {
                $apply_command .= ' --exhaustive';
            } else {
                $apply_command .= ' --limit=' . (int) ( $input['limit'] ?? 100 );
                $apply_command .= ' --offset=' . (int) ( $input['offset'] ?? 0 );
            }
			$apply_command .= ' --format=json';
			$candidates = array(
			array(
			'handle'    => 'repo@old',
			'repo'      => 'repo',
			'branch'    => 'old',
			'path'      => '/workspace/repo@old',
			'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ),
			),
			);
			$pagination = array( 'mode' => 'bounded_inventory', 'scanned' => 1, 'total' => 1, 'offset' => 0, 'limit' => (int) ( $input['limit'] ?? 100 ), 'complete' => true, 'partial' => false, 'safety_probes' => false );
			if ( 'size' === (string) ( $input['sort'] ?? '' ) ) {
				$candidates[] = array(
				'handle'    => 'repo@large',
				'repo'      => 'repo',
				'branch'    => 'large',
				'path'      => '/workspace/repo@large',
				'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 4096 ) ),
				);
				$pagination = array( 'mode' => 'ranked_inventory', 'scanned' => 200, 'total' => 200, 'offset' => 0, 'limit' => (int) ( $input['limit'] ?? 100 ), 'complete' => true, 'partial' => false, 'safety_probes' => false, 'sort' => 'size', 'ranked_total' => 2 );
			}
			return array(
			'success'       => true,
			'dry_run'       => ! empty($input['dry_run']),
			'apply_command' => $apply_command,
			'candidates'    => $candidates,
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
			'apply_command'          => $apply_command,
			'would_remove_artifacts' => count($candidates),
			'removed_artifacts'      => 0,
			'skipped'                => 1,
			'artifact_size_bytes'    => 1024,
			'pagination'             => $pagination,
			),
			'pagination' => $pagination,
			);
		}
	}

    class FakeEmergencyCleanupAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            return array(
            'success'             => true,
            'mode'                => 'emergency',
            'dry_run'             => empty($input['apply_plan']),
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
            'removed_artifacts'   => empty($input['apply_plan']) ? array() : array( array( 'handle' => 'repo@old', 'repo' => 'repo', 'branch' => 'old', 'path' => '/workspace/repo@old', 'artifacts' => array( array( 'path' => 'target', 'size_bytes' => 1024 ) ) ) ),
            'removed_worktrees'   => array(),
            'skipped'             => array(),
            'summary'             => array(
            'would_remove_artifacts' => 1,
            'would_remove_worktrees' => 1,
            'removed_artifacts'      => empty($input['apply_plan']) ? 0 : 1,
            'removed_worktrees'      => 0,
            'skipped'                => 0,
            'artifact_size_bytes'    => 1024,
            'worktree_size_bytes'    => 4096,
            ),
            );
        }
    }

    class FakeActiveNoSignalAbility
    {
        public array $last_input = array();
        public array $inputs = array();
        public ?int $stall_at_offset = null;
        private string $mode;

        public function __construct( string $mode )
        {
            $this->mode = $mode;
        }

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $this->inputs[]   = $input;
            $limit = (int) ( $input['limit'] ?? 25 );
            $offset = (int) ( $input['offset'] ?? 0 );
            $budget = isset($input['until_budget']) && '' !== trim((string) $input['until_budget']) ? ' --until-budget=' . trim((string) $input['until_budget']) : '';
            $dry_run = 'report' !== $this->mode && ! empty($input['dry_run']) ? ' --dry-run' : '';
            $next_command = sprintf('studio wp datamachine-code workspace worktree active-no-signal-%s%s --limit=%d --offset=%d%s --format=json', $this->mode, $dry_run, $limit, $offset + $limit, $budget);
            if ( null !== $this->stall_at_offset && $offset === $this->stall_at_offset ) {
                return array(
                'success' => true,
                'mode'    => 'active_no_signal_' . str_replace('-', '_', $this->mode),
                'dry_run' => ! empty($input['dry_run']),
                'summary' => array(
                'inspected' => 0,
                'planned'   => 0,
                'written'   => 0,
                'skipped'   => 0,
                ),
                'pagination' => array(
                'total'       => $offset + $limit,
                'offset'      => $offset,
                'limit'       => $limit,
                'scanned'     => 0,
                'partial'     => true,
                'complete'    => false,
                'next_offset' => $offset,
                ),
                );
            }

            if ( 'report' === $this->mode ) {
                return array(
                'success'      => true,
                'mode'         => 'active_no_signal_report',
                'review_only'  => true,
                'rows'         => array(
                array(
                'handle'          => 'repo@needs-review',
                'branch'          => 'needs-review',
                'suggested_action'=> 'manual_review',
                'dirty'           => 0,
                'unpushed'        => 0,
                'pr'              => null,
                'remote_tracking' => false,
                ),
                ),
                'summary'      => array( 'inspected' => 1, 'by_suggested_action' => array( 'manual_review' => 1 ) ),
                'pagination'   => array(
                'total'        => 2,
                'offset'       => $offset,
                'limit'        => $limit,
                'scanned'      => 1,
                'partial'      => true,
                'complete'     => false,
                'next_offset'  => $offset + $limit,
                'next_command' => $next_command,
                ),
                );
            }

            return array(
            'success'     => true,
            'mode'        => 'active_no_signal_' . str_replace('-', '_', $this->mode),
            'dry_run'     => ! empty($input['dry_run']),
            'applied'     => empty($input['dry_run']),
            'destructive' => false,
            'planned'     => array(
            array(
            'handle'   => 'repo@needs-review',
            'branch'   => 'needs-review',
            'metadata' => array(
            'lifecycle_state' => 'cleanup_eligible',
            'cleanup_eligibility_evidence' => array( 'signal' => 'test' ),
            ),
            ),
            ),
            'written'     => array(),
            'skipped'     => array(),
            'summary'     => array( 'inspected' => 1, 'planned' => 1, 'written' => 0, 'skipped' => 0 ),
            'pagination'  => array(
            'total'        => 2,
            'offset'       => $offset,
            'limit'        => $limit,
            'scanned'      => 1,
            'partial'      => true,
            'complete'     => false,
            'next_offset'  => $offset + $limit,
            'next_command' => $next_command,
            ),
            );
        }
    }

    class FakeReconcileMetadataAbility
    {
        public array $last_input = array();
        public array $inputs = array();
        public ?int $stall_at_offset = null;

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $this->inputs[]   = $input;
            $limit            = (int) ( $input['limit'] ?? 25 );
            $offset           = (int) ( $input['offset'] ?? 0 );
            if ( null !== $this->stall_at_offset && $offset === $this->stall_at_offset ) {
                return array(
                'success' => true,
                'mode'    => 'metadata_reconcile',
                'dry_run' => ! empty($input['dry_run']),
                'summary' => array(
                'inspected' => 0,
                'proposed'  => 0,
                'written'   => 0,
                ),
                'pagination' => array(
                'total'       => $offset + $limit,
                'offset'      => $offset,
                'limit'       => $limit,
                'scanned'     => 0,
                'partial'     => true,
                'complete'    => false,
                'next_offset' => $offset,
                ),
                );
            }
            return array(
            'success' => true,
            'mode'    => 'metadata_reconcile',
            'dry_run' => ! empty($input['dry_run']),
            'summary' => array(
            'inspected' => 1,
            'proposed'  => 1,
            'written'   => empty($input['dry_run']) ? 1 : 0,
            ),
            'pagination' => array(
            'total'       => 2,
            'offset'      => $offset,
            'limit'       => $limit,
            'scanned'     => 1,
            'partial'     => $offset + $limit < 2,
            'complete'    => $offset + $limit >= 2,
            'next_offset' => $offset + $limit,
            ),
            );
        }
    }

    class FakeBoundedCleanupEligibleApplyAbility
    {
        public array $last_input = array();
        public array $inputs = array();
        public int $extra_skipped = 0;

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $this->inputs[]   = $input;
            $skipped = array(
            array(
            'handle'      => 'repo@dirty',
            'repo'        => 'repo',
            'branch'      => 'dirty',
            'path'        => '/workspace/repo@dirty',
            'reason_code' => 'dirty_worktree',
            'reason'      => 'working tree dirty',
            'dirty'       => 1,
            'unpushed'    => 0,
            ),
            array(
            'handle'      => 'repo@unpushed',
            'repo'        => 'repo',
            'branch'      => 'unpushed',
            'path'        => '/workspace/repo@unpushed',
            'reason_code' => 'unpushed_commits',
            'reason'      => 'unpushed commits remain protected',
            'dirty'       => 0,
            'unpushed'    => 2,
            ),
            );
            for ( $i = 0; $i < $this->extra_skipped; ++$i ) {
                $skipped[] = array(
                'handle'      => 'repo@blocked-' . $i,
                'repo'        => 'repo',
                'branch'      => 'blocked-' . $i,
                'path'        => '/workspace/repo@blocked-' . $i,
                'reason_code' => 0 === $i % 2 ? 'active_no_signal' : 'needs_metadata_reconcile',
                'reason'      => 'large blocked output fixture',
                );
            }
            $remaining_handles = array_map(fn( $row ) => (string) ( $row['handle'] ?? '' ), $skipped);

            return array(
            'success' => true,
            'mode'    => 'bounded_cleanup_eligible_apply',
            'dry_run' => ! empty($input['dry_run']),
            'summary' => array(
            'inspected'        => 3,
            'would_remove'     => ! empty($input['dry_run']) ? 1 : 0,
            'removed'          => empty($input['dry_run']) ? 1 : 0,
            'skipped'          => 2,
            'bytes_reclaimed'  => empty($input['dry_run']) ? 4096 : 0,
            ),
            'skipped' => $skipped,
            'pagination' => array(
            'remaining_total'   => count($remaining_handles),
            'remaining_handles' => $remaining_handles,
            ),
            );
        }
    }

    class FakePruneAbility
    {
        public int $calls = 0;

        public function execute( array $input ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            ++$this->calls;
            return array(
            'success' => true,
            'mode'    => 'prune',
            'summary' => array(
            'processed' => 1,
            ),
            );
        }
    }

	class FakeListAbility
	{
        public function execute( array $input ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
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

	class FakeWorkspaceListAbility
	{
		public function execute( array $input ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		{
			return array(
			'success' => true,
			'path'    => '/workspace',
			'repos'   => array(
			array(
			'name'        => 'repo',
			'repo'        => 'repo',
			'branch'      => 'main',
			'remote'      => 'https://example.com/repo.git',
			'git'         => true,
			'is_worktree' => false,
			'path'        => '/workspace/repo',
			),
			array(
			'name'        => 'repo@feature-one',
			'repo'        => 'repo',
			'branch'      => 'feature/one',
			'git'         => true,
			'is_worktree' => true,
			'path'        => '/workspace/repo@feature-one',
			),
			array(
			'name'        => 'docs@cleanup',
			'repo'        => 'docs',
			'branch'      => 'cleanup',
			'git'         => false,
			'is_worktree' => true,
			'path'        => '/workspace/docs@cleanup',
			),
			array(
			'name'       => 'context-docs',
			'repo'       => 'docs',
			'git'        => true,
			'is_context' => true,
			'path'       => '/workspace/docs',
			),
			),
			);
		}
	}

	class FakeCleanupRunAbility
	{
		public array $last_input = array();

        public function execute( array $input ): array
        {
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

	class FakeCleanupPlanAbility
	{
		public array $last_input = array();

		public function execute( array $input ): array
		{
			$this->last_input = $input;
			return array(
			'success' => true,
			'run_id'  => 'cleanup-run-20260612000000-test',
			'plan_id' => 'cleanup-plan-test',
			'inputs'  => $input,
			'summary' => array(
			'total_rows'       => 2,
			'total_size_bytes' => 4096,
			'category_totals'  => array(
			'whole_worktrees'      => 1024,
			'dependency_artifacts' => 2048,
			'build_outputs'        => 512,
			'caches'               => 512,
			),
			'top_reclaimable'  => array(
			array(
			'path'         => '/workspace/repo@feature/node_modules',
			'handle'       => 'repo@feature',
			'repo'         => 'repo',
			'category'     => 'dependency_artifacts',
			'safety_class' => 'safe',
			'size_bytes'   => 2048,
			),
			),
			'blockers'         => array(
			'dirty_worktree' => array(
			'count'      => 1,
			'size_bytes' => 1024,
			'repos'      => array(
			'repo' => array(
			'count'      => 1,
			'size_bytes' => 1024,
			'examples'   => array( 'repo@dirty' ),
			),
			),
			'examples'   => array( 'repo@dirty' ),
			),
			),
			'recommended_commands' => array(
			array(
			'label'   => 'apply_reviewed_plan',
			'risk'    => 'reviewed_destructive',
			'command' => 'studio wp datamachine-code workspace cleanup apply <run-id>',
			),
			array(
			'label'   => 'resolve_metadata_blockers',
			'risk'    => 'none',
			'command' => 'studio wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json',
			),
			),
			),
			);
		}
	}

	class FakeCleanupStatusAbility
	{
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            return array(
            'success' => true,
            'run_id'  => (string) ( $input['run_id'] ?? '' ),
            'state'   => 'planned',
			'locks'   => array(
			'stale_locks' => array(
			'count'            => 1,
			'database_count'   => 1,
			'filesystem_count' => 0,
			'preview_command'  => 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json',
			'apply_command'    => 'wp datamachine-code workspace worktree locks --prune-stale --format=json',
			'safety'           => 'Preview is non-destructive. Apply prunes expired DB rows and old unlocked filesystem lock files only; live filesystem flocks are reported and protected.',
			'database'         => array(
			array(
			'source'             => 'database',
			'lock_key'           => 'worktree-demo',
			'scope'              => 'demo',
			'owner'              => 'owner:123',
			'session'            => 'ses-demo',
			'age_seconds'        => 3600,
			'live_flock_present' => false,
			'safe_to_prune'      => true,
			),
			),
			'filesystem'       => array(),
			),
			),
            );
        }
    }

    class FakeHygieneAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
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

    class FakeGetJobsAbility
    {
        public function execute( array $input ): array
        {
            if (isset($input['parent_job_id']) ) {
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
                'status'        => ! empty($GLOBALS['datamachine_code_cleanup_child_drained']) ? 'completed' : 'processing',
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
                 'task_type'        => 'worktree_cleanup_chunk',
                 'success'          => true,
                 'chunk_type'       => 'artifact_discovery',
                 'planned_count'    => 3,
                 'applied_count'    => 2,
                 'skipped_count'    => 1,
                 'failed_count'     => 0,
                 'bytes_reclaimed'  => 4096,
				  'skipped'          => array(
				   array( 'handle' => 'repo@dirty', 'repo' => 'repo', 'branch' => 'dirty', 'path' => '/workspace/repo@dirty', 'reason_code' => 'dirty_worktree', 'artifact_size_bytes' => 8192 ),
				   array( 'handle' => 'repo@unpushed', 'repo' => 'repo', 'branch' => 'unpushed', 'path' => '/workspace/repo@unpushed', 'reason_code' => 'unpushed_commits' ),
				   array( 'handle' => 'repo@broken-marker', 'repo' => 'repo', 'branch' => 'broken-marker', 'path' => '/workspace/repo@broken-marker', 'primary_path' => '/workspace/repo', 'reason_code' => 'stale_worktree_marker' ),
				   array( 'handle' => 'missing-primary@feature', 'repo' => 'missing-primary', 'branch' => 'feature', 'path' => '/workspace/missing-primary@feature', 'reason_code' => 'primary_missing' ),
				  ),
                 'failed'           => array(),
                   ),
                  ),
                  array(
                 'job_id'        => 127,
                 'parent_job_id' => 124,
                 'source'        => 'system',
                 'status'        => 'failed - apply_failed',
                 'engine_data'   => array(
                 'task_type'        => 'worktree_cleanup_chunk',
                 'success'          => false,
                 'chunk_type'       => 'artifacts',
                 'planned_count'    => 1,
                 'applied_count'    => 0,
                 'skipped_count'    => 0,
                 'failed_count'     => 1,
                 'bytes_reclaimed'  => 0,
                 'skipped'          => array(),
                 'failed'           => array(
                   array( 'handle' => 'repo@failed', 'reason_code' => 'apply_failed', 'artifact_size_bytes' => 2048 ),
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
                 'jobs'    => array_slice($jobs, $offset, $per_page),
                 'total'   => count($jobs),
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
            'success'     => true,
            'job_backed'  => true,
            'report'      => array(
            'bytes_reclaimed' => 0,
            'freed_human'     => 'pending child jobs',
            ),
                        ),
            ),
            ),
            );
        }
    }

    class FakeRetryJobAbility
    {
        public array $last_input = array();
        public array $inputs = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $this->inputs[]   = $input;
            return array(
            'success'         => true,
            'job_id'          => (int) $input['job_id'],
            'previous_status' => 'failed - test',
            'message'         => 'retried',
            );
        }
    }

    class FakeFailJobAbility
    {
        public array $last_input = array();
        public array $inputs = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            $this->inputs[]   = $input;
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
    $active_report_ability = new FakeActiveNoSignalAbility('report');
    $active_finalized_ability = new FakeActiveNoSignalAbility('finalized-apply');
    $active_equivalent_clean_ability = new FakeActiveNoSignalAbility('equivalent-clean-apply');
    $active_merged_ability = new FakeActiveNoSignalAbility('merged-apply');
    $active_remote_clean_ability = new FakeActiveNoSignalAbility('remote-clean-apply');
    $reconcile_metadata_ability = new FakeReconcileMetadataAbility();
	$bounded_apply_ability = new FakeBoundedCleanupEligibleApplyAbility();
	$prune_ability = new FakePruneAbility();
	$list_ability = new FakeListAbility();
	$workspace_list_ability = new FakeWorkspaceListAbility();
	$cleanup_run_ability = new FakeCleanupRunAbility();
	$cleanup_plan_ability = new FakeCleanupPlanAbility();
	$cleanup_status_ability = new FakeCleanupStatusAbility();
    $hygiene_ability = new FakeHygieneAbility();
    $get_jobs_ability = new FakeGetJobsAbility();
    $retry_job_ability = new FakeRetryJobAbility();
    $fail_job_ability = new FakeFailJobAbility();
	$GLOBALS['__abilities'] = array(
	'datamachine-code/workspace-list'                        => $workspace_list_ability,
	'datamachine-code/workspace-cleanup-plan'                => $cleanup_plan_ability,
	'datamachine-code/workspace-cleanup-run'                 => $cleanup_run_ability,
    'datamachine-code/workspace-cleanup-apply'               => $cleanup_status_ability,
    'datamachine-code/workspace-cleanup-status'              => $cleanup_status_ability,
    'datamachine-code/workspace-cleanup-resume'              => $cleanup_status_ability,
    'datamachine-code/workspace-hygiene-report'              => $hygiene_ability,
    'datamachine-code/workspace-worktree-cleanup'           => $ability,
    'datamachine-code/workspace-worktree-cleanup-artifacts' => $artifact_ability,
    'datamachine-code/workspace-worktree-emergency-cleanup' => $emergency_ability,
    'datamachine-code/workspace-worktree-active-no-signal-report' => $active_report_ability,
    'datamachine-code/workspace-worktree-active-no-signal-finalized-apply' => $active_finalized_ability,
    'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply' => $active_equivalent_clean_ability,
    'datamachine-code/workspace-worktree-active-no-signal-merged-apply' => $active_merged_ability,
    'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply' => $active_remote_clean_ability,
    'datamachine-code/workspace-worktree-reconcile-metadata' => $reconcile_metadata_ability,
    'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply' => $bounded_apply_ability,
    'datamachine-code/workspace-worktree-prune'              => $prune_ability,
    'datamachine-code/workspace-worktree-list'              => $list_ability,
    'datamachine/get-jobs'                             => $get_jobs_ability,
    'datamachine-code/retry-job'                       => $retry_job_ability,
    'datamachine-code/fail-job'                        => $fail_job_ability,
    'datamachine/retry-job'                            => $retry_job_ability,
    'datamachine/fail-job'                             => $fail_job_ability,
    );
    $command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
    $doc_comment = ( new ReflectionMethod($command, 'worktree') )->getDocComment() ?: '';
    $cleanup_doc_comment = ( new ReflectionMethod($command, 'cleanup') )->getDocComment() ?: '';

    echo "\n[0a] WP-CLI synopsis exposes cleanup flags\n";
    datamachine_code_cleanup_assert(str_contains($doc_comment, "\n\t * [--inventory-only]"), 'worktree synopsis declares --inventory-only at top level');
    datamachine_code_cleanup_assert(str_contains($doc_comment, "\n\t * [--include-repaired-metadata]"), 'worktree synopsis declares --include-repaired-metadata at top level');
    datamachine_code_cleanup_assert(str_contains($doc_comment, "\n\t * [--apply]"), 'worktree synopsis declares --apply at top level');
    datamachine_code_cleanup_assert(str_contains($doc_comment, "\n\t * [--via-jobs]"), 'worktree synopsis declares --via-jobs at top level');
    datamachine_code_cleanup_assert(str_contains($doc_comment, "\n\t * [--passes=<count>]"), 'worktree synopsis declares abandoned --passes at top level');
    datamachine_code_cleanup_assert(str_contains($doc_comment, "\n\t * [--stage=<stage>]"), 'worktree synopsis declares abandoned --stage at top level');
    datamachine_code_cleanup_assert(! str_contains($doc_comment, "\n\t\t * [--apply-plan=<file>]"), 'cleanup flags are not hidden behind nested docblock indentation');
    datamachine_code_cleanup_assert(str_contains($cleanup_doc_comment, 'Control task-backed workspace cleanup runs.'), 'workspace cleanup command documents task-backed controller surface');
    datamachine_code_cleanup_assert(str_contains($cleanup_doc_comment, '<plan|apply|run|status|resume|cancel|evidence>'), 'workspace cleanup synopsis exposes DB-backed and task-backed cleanup operations');
    datamachine_code_cleanup_assert(str_contains($cleanup_doc_comment, '[--dry-run]'), 'task-backed cleanup synopsis keeps synchronous dry-run review');
    datamachine_code_cleanup_assert(str_contains($cleanup_doc_comment, '[--drain]'), 'task-backed cleanup synopsis exposes drain option');
    datamachine_code_cleanup_assert(str_contains($cleanup_doc_comment, 'apply runs freeze eligible candidates'), 'workspace cleanup limit help clarifies artifact apply scoping');
    datamachine_code_cleanup_assert(str_contains($doc_comment, 'positive maximum worktrees to scan'), 'worktree limit help requires positive page sizes');
    datamachine_code_cleanup_assert(str_contains($doc_comment, 'Use `--exhaustive` instead of `--limit=0`'), 'worktree limit help points unbounded artifact scans to exhaustive mode');
    datamachine_code_cleanup_assert(str_contains($doc_comment, 'explicit unbounded artifact audit mode'), 'worktree exhaustive help documents unbounded artifact audit mode');
    datamachine_code_cleanup_assert(str_contains($doc_comment, 'Daily cleanup path: DB-backed plan, then apply only those rows after revalidation'), 'worktree examples point daily cleanup to DB-backed run_id controller path');
    datamachine_code_cleanup_assert(str_contains($doc_comment, 'workspace cleanup plan --mode=retention'), 'worktree examples include DB-backed cleanup plan');
    datamachine_code_cleanup_assert(str_contains($doc_comment, 'workspace cleanup run --mode=retention'), 'worktree examples include task-backed cleanup run');
    datamachine_code_cleanup_assert(! str_contains($doc_comment, '> cleanup-plan.json'), 'worktree examples do not normalize cleanup-plan file redirection');
	datamachine_code_cleanup_assert(! str_contains($doc_comment, '> artifact-plan.json'), 'worktree examples do not normalize artifact-plan file redirection');
	datamachine_code_cleanup_assert(! str_contains($doc_comment, '> emergency-plan.json'), 'worktree examples do not normalize emergency-plan file redirection');
	datamachine_code_cleanup_assert(! str_contains($doc_comment, '> reconcile-plan.json'), 'worktree examples do not normalize reconcile-plan file redirection');

	echo "\n[0a2] workspace list compact triage output\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->list_repos(array(), array( 'summary' => true ));
	datamachine_code_cleanup_assert(in_array('Workspace: /workspace', WP_CLI::$logs, true), 'workspace list --summary prints workspace path');
	datamachine_code_cleanup_assert(in_array('table:5:metric,count', WP_CLI::$logs, true), 'workspace list --summary prints compact metric counts');
	datamachine_code_cleanup_assert(in_array('table:2:repo,primary,worktree,context,total', WP_CLI::$logs, true), 'workspace list --summary groups counts by repo');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->list_repos(array(), array( 'summary' => true, 'format' => 'json' ));
	$list_summary_json = json_decode(WP_CLI::$logs[0] ?? '', true);
	datamachine_code_cleanup_assert(4 === (int) ( $list_summary_json['total'] ?? 0 ), 'workspace list --summary JSON keeps total count');
	datamachine_code_cleanup_assert(2 === (int) ( $list_summary_json['worktree'] ?? 0 ), 'workspace list --summary JSON counts worktrees');
	datamachine_code_cleanup_assert(1 === (int) ( $list_summary_json['non_git'] ?? 0 ), 'workspace list --summary JSON counts non-git rows');

	echo "\n[0b] task-backed workspace cleanup run/status/control output\n";
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'plan' ), array( 'mode' => 'retention' ));
	datamachine_code_cleanup_assert('retention' === ( $cleanup_plan_ability->last_input['mode'] ?? '' ), 'cleanup plan receives retention mode');
	datamachine_code_cleanup_assert(false === ( $cleanup_plan_ability->last_input['include_artifacts'] ?? true ), 'retention cleanup plan skips exhaustive artifact scan by default');
	datamachine_code_cleanup_assert(true === ( $cleanup_plan_ability->last_input['include_worktrees'] ?? false ), 'retention cleanup plan keeps inventory worktree planning enabled');
	datamachine_code_cleanup_assert(in_array('Planning cleanup (retention; local worktree merge signals)...', WP_CLI::$logs, true), 'human cleanup plan reports bounded scan profile before planning');
	datamachine_code_cleanup_assert(in_array('Reclaimable space by category:', WP_CLI::$logs, true), 'human cleanup plan reports category totals up front');
	datamachine_code_cleanup_assert(in_array('Top reclaimable paths:', WP_CLI::$logs, true), 'human cleanup plan reports top reclaimable paths');
	datamachine_code_cleanup_assert(in_array('Blockers by reason and repo:', WP_CLI::$logs, true), 'human cleanup plan reports blockers before apply');
	datamachine_code_cleanup_assert(in_array('Recommended commands:', WP_CLI::$logs, true), 'human cleanup plan reports directly executable commands');
	datamachine_code_cleanup_assert(in_array('table:4:category,bytes', WP_CLI::$logs, true), 'category table has expected report shape');
	datamachine_code_cleanup_assert(in_array('table:1:size,category,risk,handle,path', WP_CLI::$logs, true), 'top reclaimable table has expected report shape');
	datamachine_code_cleanup_assert(in_array('table:1:reason,count,bytes,repos,examples', WP_CLI::$logs, true), 'blocker table has expected report shape');
	datamachine_code_cleanup_assert(in_array('table:2:label,risk,command', WP_CLI::$logs, true), 'recommended command table includes risk labels');
	datamachine_code_cleanup_assert(in_array('Artifacts: skipped for bounded retention planning; run `wp datamachine-code workspace cleanup plan --mode=artifacts` when you want artifact rows.', WP_CLI::$logs, true), 'human cleanup plan shows explicit artifact follow-up command');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'plan' ), array( 'mode' => 'retention', 'top' => 3 ));
	datamachine_code_cleanup_assert(3 === (int) ( $cleanup_plan_ability->last_input['top_n'] ?? 0 ), 'cleanup plan forwards top-N summary size');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'plan' ), array( 'mode' => 'retention', 'include-artifacts' => true, 'format' => 'json' ));
	datamachine_code_cleanup_assert(true === ( $cleanup_plan_ability->last_input['include_artifacts'] ?? false ), 'retention cleanup plan can explicitly include artifacts');
	datamachine_code_cleanup_assert('{' === substr(WP_CLI::$logs[0] ?? '', 0, 1), 'json cleanup plan output is not prefixed by progress text');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'plan' ), array( 'mode' => 'artifacts', 'format' => 'json' ));
	datamachine_code_cleanup_assert(true === ( $cleanup_plan_ability->last_input['include_artifacts'] ?? false ), 'artifact cleanup plan includes artifact scan');
	datamachine_code_cleanup_assert(false === ( $cleanup_plan_ability->last_input['include_worktrees'] ?? true ), 'artifact cleanup plan skips worktree removal rows');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'run' ), array( 'mode' => 'retention', 'format' => 'json' ));
    $run_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert('jobs_queued' === ( $run_json['state'] ?? '' ), 'cleanup run queues a system task');
    datamachine_code_cleanup_assert('cleanup-run-123' === ( $run_json['run_id'] ?? '' ), 'cleanup run returns stable run id');
    datamachine_code_cleanup_assert('studio wp datamachine drain --job-id=123' === ( $run_json['commands']['drain_parent'] ?? '' ), 'cleanup run JSON includes exact parent drain command');
    datamachine_code_cleanup_assert(str_contains((string) ( $run_json['commands']['one_command_drain'] ?? '' ), '--drain'), 'cleanup run JSON exposes one-command drain path');
    datamachine_code_cleanup_assert('retention' === ( $cleanup_run_ability->last_input['mode'] ?? '' ), 'cleanup run ability receives mode');
    datamachine_code_cleanup_assert('workspace_cleanup_cli' === ( $cleanup_run_ability->last_input['source'] ?? '' ), 'cleanup run ability identifies explicit CLI source');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'run' ), array( 'mode' => 'artifacts', 'limit' => 25, 'offset' => 50, 'format' => 'json' ));
    datamachine_code_cleanup_assert('artifacts' === ( $cleanup_run_ability->last_input['mode'] ?? '' ), 'cleanup run can schedule artifact mode');
    datamachine_code_cleanup_assert(25 === (int) ( $cleanup_run_ability->last_input['limit'] ?? 0 ), 'cleanup run forwards artifact apply limit');
    datamachine_code_cleanup_assert(50 === (int) ( $cleanup_run_ability->last_input['offset'] ?? 0 ), 'cleanup run forwards artifact apply offset');
    $last_scheduled_cleanup_run = $cleanup_run_ability->last_input;

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'run' ), array( 'mode' => 'artifacts', 'dry-run' => true, 'format' => 'json' ));
    datamachine_code_cleanup_assert(true === ( $artifact_ability->last_input['dry_run'] ?? false ), 'cleanup run --dry-run uses artifact cleanup ability directly');
    datamachine_code_cleanup_assert($last_scheduled_cleanup_run === $cleanup_run_ability->last_input, 'cleanup run --dry-run does not schedule cleanup run ability');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'run' ), array( 'mode' => 'inventory', 'format' => 'json' ));
    $inventory_run_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert('jobs_queued' === ( $inventory_run_json['state'] ?? '' ), 'cleanup run queues inventory as a task');
    datamachine_code_cleanup_assert('inventory' === ( $cleanup_run_ability->last_input['mode'] ?? '' ), 'cleanup run can schedule inventory mode');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'status', 'cleanup-run-20260504193024-abc123' ), array( 'format' => 'json' ));
    $db_status_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert('cleanup-run-20260504193024-abc123' === ( $cleanup_status_ability->last_input['run_id'] ?? '' ), 'DB cleanup run IDs are routed to cleanup status ability');
    datamachine_code_cleanup_assert('planned' === ( $db_status_json['state'] ?? '' ), 'DB cleanup run status does not route to job-backed status parser');
	datamachine_code_cleanup_assert(1 === (int) ( $db_status_json['locks']['stale_locks']['database_count'] ?? 0 ), 'cleanup status JSON surfaces stale DB locks');
	datamachine_code_cleanup_assert('wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json' === (string) ( $db_status_json['locks']['stale_locks']['preview_command'] ?? '' ), 'cleanup status JSON includes exact stale lock preview command');
	datamachine_code_cleanup_assert('wp datamachine-code workspace worktree locks --prune-stale --format=json' === (string) ( $db_status_json['locks']['stale_locks']['apply_command'] ?? '' ), 'cleanup status JSON includes exact stale lock apply command');
	datamachine_code_cleanup_assert('ses-demo' === (string) ( $db_status_json['locks']['stale_locks']['database'][0]['session'] ?? '' ), 'cleanup status JSON includes stale DB lock session');
	datamachine_code_cleanup_assert(3600 === (int) ( $db_status_json['locks']['stale_locks']['database'][0]['age_seconds'] ?? 0 ), 'cleanup status JSON includes stale DB lock age');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'status', 'cleanup-run-20260504193024-abc123' ), array());
	datamachine_code_cleanup_assert(in_array('Stale workspace locks:', WP_CLI::$logs, true), 'human cleanup status renders stale lock follow-up section');
	datamachine_code_cleanup_assert(in_array('Preview: wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json', WP_CLI::$logs, true), 'human cleanup status renders exact prune preview command');
	datamachine_code_cleanup_assert(in_array('Apply:   wp datamachine-code workspace worktree locks --prune-stale --format=json', WP_CLI::$logs, true), 'human cleanup status renders exact prune apply command');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'apply', 'cleanup-run-20260504193024-abc123' ), array( 'limit' => 7, 'format' => 'json' ));
    datamachine_code_cleanup_assert(7 === (int) ( $cleanup_status_ability->last_input['limit'] ?? 0 ), 'DB cleanup apply forwards bounded limit');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'resume', 'cleanup-run-20260504193024-abc123' ), array( 'limit' => 9, 'format' => 'json' ));
    datamachine_code_cleanup_assert(9 === (int) ( $cleanup_status_ability->last_input['limit'] ?? 0 ), 'DB cleanup resume forwards bounded limit');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'status', 'cleanup-run-123' ), array( 'format' => 'json' ));
    $status_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert('children_processing' === ( $status_json['state'] ?? '' ), 'cleanup status stays active while child batch is processing');
    datamachine_code_cleanup_assert('children_processing' === ( $status_json['status'] ?? '' ), 'cleanup status does not report parent completed while children run');
    datamachine_code_cleanup_assert('2026-05-03 00:10:00' === ( $status_json['parent_completed_at'] ?? '' ), 'cleanup status labels parent scheduler completion separately');
    datamachine_code_cleanup_assert(! isset($status_json['completed_at']), 'cleanup status does not expose parent completion as run completion');
    datamachine_code_cleanup_assert(! isset($status_json['children']['job_ids']), 'cleanup status omits full child job ids by default');
    datamachine_code_cleanup_assert(! isset($status_json['children']['chunk_job_ids']), 'cleanup status omits full child chunk job ids by default');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['children']['batch_total'] ?? 0 ), 'cleanup status reports child batch totals by default');
    datamachine_code_cleanup_assert(2 === (int) ( $status_json['children']['chunk_total'] ?? 0 ), 'cleanup status reports child chunk totals by default');
    datamachine_code_cleanup_assert(array( 125 ) === ( $status_json['children']['processing_job_ids'] ?? array() ), 'cleanup status reports bounded processing job ids by default');
    datamachine_code_cleanup_assert(array( 127 ) === ( $status_json['children']['failed_job_ids'] ?? array() ), 'cleanup status reports failed job ids by default');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['children']['running'] ?? 0 ), 'cleanup status summarizes running child jobs');
    datamachine_code_cleanup_assert(! isset($status_json['flow_id']), 'cleanup status is not linked to a flow id');
    datamachine_code_cleanup_assert(4 === (int) ( $status_json['artifact_cleanup']['planned_rows'] ?? 0 ), 'cleanup status aggregates artifact planned rows from child chunks');
    datamachine_code_cleanup_assert(4096 === (int) ( $status_json['artifact_cleanup']['bytes_reclaimed'] ?? 0 ), 'cleanup status aggregates artifact bytes from child chunks');
    datamachine_code_cleanup_assert(4 === (int) ( $status_json['cleanup_items']['planned_rows'] ?? 0 ), 'cleanup status aggregates planned rows from DB-backed cleanup item evidence');
    datamachine_code_cleanup_assert(4096 === (int) ( $status_json['cleanup_items']['bytes_reclaimed'] ?? 0 ), 'cleanup status reconstructs reclaimed bytes from cleanup item evidence');
    datamachine_code_cleanup_assert(3 === (int) ( $status_json['cleanup_items']['by_type']['artifact_discovery']['planned_rows'] ?? 0 ), 'cleanup status preserves artifact discovery chunk type aggregation');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['cleanup_items']['by_type']['artifacts']['planned_rows'] ?? 0 ), 'cleanup status preserves artifact apply chunk type aggregation');
    datamachine_code_cleanup_assert(! isset($status_json['cleanup_items']['by_type']['unknown']), 'cleanup status does not aggregate completed chunks under unknown type');
    datamachine_code_cleanup_assert(2 === (int) ( $status_json['remaining_work_summary']['applied_by_type']['artifact_discovery']['count'] ?? 0 ), 'cleanup status groups applied rows by type in remaining-work summary');
    datamachine_code_cleanup_assert(4096 === (int) ( $status_json['remaining_work_summary']['applied_by_type']['artifact_discovery']['bytes_reclaimed'] ?? 0 ), 'cleanup status reports reclaimed bytes by applied type');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['remaining_work_summary']['skipped_by_reason']['dirty_worktree']['count'] ?? 0 ), 'cleanup status groups skipped rows by reason in remaining-work summary');
    datamachine_code_cleanup_assert('repo@dirty' === (string) ( $status_json['remaining_work_summary']['skipped_by_reason']['dirty_worktree']['examples'][0]['handle'] ?? '' ), 'cleanup status includes skipped examples');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['remaining_work_summary']['skipped_by_reason']['unpushed_commits']['count'] ?? 0 ), 'cleanup status groups unpushed blockers separately');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['remaining_work_summary']['skipped_by_reason']['stale_worktree_marker']['count'] ?? 0 ), 'cleanup status groups stale marker blockers separately');
    datamachine_code_cleanup_assert(1 === (int) ( $status_json['remaining_work_summary']['skipped_by_reason']['primary_missing']['count'] ?? 0 ), 'cleanup status groups missing primary blockers separately');
    datamachine_code_cleanup_assert(10240 === (int) ( $status_json['remaining_work_summary']['remaining_reclaimable_artifact_bytes'] ?? 0 ), 'cleanup status reports remaining reclaimable artifact bytes');
    datamachine_code_cleanup_assert('studio wp datamachine drain --job-id=125' === ( $status_json['drain']['commands']['active_children'] ?? '' ), 'cleanup status includes exact active child drain command');
    datamachine_code_cleanup_assert(4096 === (int) ( $status_json['drain']['bytes_reclaimed'] ?? 0 ), 'cleanup status drain summary verifies bytes reclaimed');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($status_json['remaining_work_summary']['recommended_commands'] ?? array()), 'workspace cleanup run --mode=artifacts'), 'cleanup status recommends next DMC commands per bucket');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($status_json['remaining_work_summary']['recommended_commands'] ?? array()), 'git -C <worktree-path> status --short --branch'), 'cleanup status recommends dirty git status evidence');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($status_json['remaining_work_summary']['recommended_commands'] ?? array()), 'git -C <worktree-path> log --oneline --decorate @{u}..HEAD'), 'cleanup status recommends unpushed git log evidence');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($status_json['remaining_work_summary']['recommended_commands'] ?? array()), 'git -C <primary-path> worktree prune --dry-run --verbose'), 'cleanup status recommends stale marker prune preview');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($status_json['remaining_work_summary']['recommended_commands'] ?? array()), 'workspace show <repo>'), 'cleanup status recommends missing primary report');
    datamachine_code_cleanup_assert('4.0 KiB' === ( $status_json['system_task_result']['report']['freed_human'] ?? '' ), 'cleanup status replaces pending child job freed placeholder');
    datamachine_code_cleanup_assert(! isset($status_json['system_task_result']['children']['job_ids']), 'cleanup status system task result omits full child job ids by default');

    WP_CLI::$logs        = array();
    WP_CLI::$successes   = array();
    WP_CLI::$runcommands = array();
    $GLOBALS['datamachine_code_cleanup_parent_drained'] = false;
    $GLOBALS['datamachine_code_cleanup_child_drained']  = false;
    $command->cleanup(array( 'run' ), array( 'mode' => 'artifacts', 'drain' => true, 'format' => 'json' ));
    $drained_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(array( 'datamachine drain --job-id=123', 'datamachine drain --job-id=125' ) === WP_CLI::$runcommands, 'cleanup run --drain drains parent then active child jobs');
    datamachine_code_cleanup_assert(4096 === (int) ( $drained_json['drain']['bytes_reclaimed'] ?? 0 ), 'cleanup run --drain reports verified reclaimed bytes');
    datamachine_code_cleanup_assert('partial_failed' === (string) ( $drained_json['drain']['completion_state'] ?? '' ), 'cleanup run --drain reports final cleanup state');
    datamachine_code_cleanup_assert('studio wp datamachine-code workspace cleanup status cleanup-run-123 --format=json' === (string) ( $drained_json['drain']['verify_command'] ?? '' ), 'cleanup run --drain emits one verification command');
    $GLOBALS['datamachine_code_cleanup_parent_drained'] = false;
    $GLOBALS['datamachine_code_cleanup_child_drained']  = false;

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'status', 'cleanup-run-123' ), array());
    datamachine_code_cleanup_assert(in_array('Remaining work summary:', WP_CLI::$logs, true), 'human cleanup status renders compact remaining-work summary');
    datamachine_code_cleanup_assert(in_array('Applied rows by type:', WP_CLI::$logs, true), 'human cleanup status renders applied grouped section');
    datamachine_code_cleanup_assert(in_array('Skipped rows by reason:', WP_CLI::$logs, true), 'human cleanup status renders skipped grouped section');
    datamachine_code_cleanup_assert(in_array('Recommended next commands:', WP_CLI::$logs, true), 'human cleanup status renders next command section');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'status', 'cleanup-run-123' ), array( 'format' => 'json', 'verbose' => true ));
    $verbose_status_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(array( 124, 125, 126, 127 ) === ( $verbose_status_json['children']['job_ids'] ?? array() ), 'cleanup status --verbose exposes full child job ids');
    datamachine_code_cleanup_assert(array( 125 ) === ( $verbose_status_json['children']['batch_job_ids'] ?? array() ), 'cleanup status --verbose exposes child batch job ids');
    datamachine_code_cleanup_assert(array( 126, 127 ) === ( $verbose_status_json['children']['chunk_job_ids'] ?? array() ), 'cleanup status --verbose exposes child chunk job ids');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'evidence', '123' ), array( 'format' => 'json' ));
    $evidence_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(isset($evidence_json['evidence']['engine_data']), 'cleanup evidence emits engine data');
    datamachine_code_cleanup_assert(array( 125 ) === ( $evidence_json['evidence']['children']['batch_job_ids'] ?? array() ), 'cleanup evidence lists child batch jobs');
    datamachine_code_cleanup_assert(array( 126, 127 ) === ( $evidence_json['evidence']['children']['chunk_job_ids'] ?? array() ), 'cleanup evidence lists child chunk jobs');
    datamachine_code_cleanup_assert(false === ( $evidence_json['evidence']['storage']['filesystem_plans'] ?? true ), 'cleanup evidence does not depend on filesystem plan JSON');
    datamachine_code_cleanup_assert('datamachine_jobs' === ( $evidence_json['evidence']['storage']['source'] ?? '' ), 'cleanup evidence declares Data Machine job DB source while cleanup tables are pending');
    datamachine_code_cleanup_assert(1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['skipped_by_reason']['dirty_worktree'] ?? 0 ), 'cleanup evidence aggregates skipped reasons');
    datamachine_code_cleanup_assert(1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['skipped_by_reason']['unpushed_commits'] ?? 0 ), 'cleanup evidence aggregates unpushed skipped reasons');
    datamachine_code_cleanup_assert(1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['skipped_by_reason']['stale_worktree_marker'] ?? 0 ), 'cleanup evidence aggregates stale marker skipped reasons');
    datamachine_code_cleanup_assert(1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['skipped_by_reason']['primary_missing'] ?? 0 ), 'cleanup evidence aggregates missing primary skipped reasons');
    datamachine_code_cleanup_assert(1 === (int) ( $evidence_json['evidence']['artifact_cleanup']['failed_by_reason']['apply_failed'] ?? 0 ), 'cleanup evidence aggregates failed reasons');
    datamachine_code_cleanup_assert(1 === (int) ( $evidence_json['evidence']['cleanup_items']['failed_by_reason']['apply_failed'] ?? 0 ), 'cleanup evidence reconstructs failed cleanup item reasons from job rows');
    datamachine_code_cleanup_assert(4 === count($evidence_json['evidence']['child_jobs'] ?? array()), 'cleanup evidence emits descendant child jobs');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'resume', 'cleanup-run-123' ), array( 'force' => true, 'format' => 'json' ));
    $resume_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(array( 125, 127 ) === ( $resume_json['controlled_job_ids'] ?? array() ), 'cleanup resume controls active child jobs before the parent');
    datamachine_code_cleanup_assert(true === ( $retry_job_ability->last_input['force'] ?? null ), 'cleanup resume forwards force retry flag');
    datamachine_code_cleanup_assert(array( 125, 127 ) === array_map(fn( $input ) => (int) ( $input['job_id'] ?? 0 ), $retry_job_ability->inputs), 'cleanup resume retries processing and failed child jobs');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->cleanup(array( 'cancel', 'cleanup-run-123' ), array( 'format' => 'json' ));
    $cancel_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(array( 123, 125 ) === ( $cancel_json['controlled_job_ids'] ?? array() ), 'cleanup cancel controls parent and active child jobs');
    datamachine_code_cleanup_assert('cleanup_cancelled' === ( $fail_job_ability->last_input['reason'] ?? '' ), 'cleanup cancel fails job with cleanup cancellation reason');

    echo "\n[0] list stale output exposes disk fields\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'list' ), array( 'stale' => true ));
    datamachine_code_cleanup_assert(in_array('table:2:handle,repo,kind,branch,head,dirty,state,liveness,last_seen_at,owner,agent,session,task,pr,age_days,size,artifacts,stale,path', WP_CLI::$logs, true), 'worktree list --stale filters to stale rows and includes disk + liveness fields');

    echo "\n[1] JSON output is one parseable document\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'format' => 'json' ));
    datamachine_code_cleanup_assert(array( 'dry_run' => true, 'force' => false, 'skip_github' => true, 'inventory_only' => false, 'include_repaired_metadata' => false ) === $ability->last_input, 'cleanup flags forwarded to ability');
    datamachine_code_cleanup_assert(1 === count(WP_CLI::$logs), 'JSON path writes exactly one stdout log entry');
    datamachine_code_cleanup_assert(array() === WP_CLI::$successes, 'JSON path emits no success suffix');
    $decoded = json_decode(WP_CLI::$logs[0], true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'JSON output parses cleanly');
    datamachine_code_cleanup_assert('dirty_worktree' === ( $decoded['skipped'][0]['reason_code'] ?? '' ), 'JSON rows include stable reason code');
    datamachine_code_cleanup_assert(array( 'repo', 'branch', 'path' ) === ( $decoded['skipped'][11]['missing_fields'] ?? array() ), 'JSON metadata reconciliation row includes missing fields');
    datamachine_code_cleanup_assert('needs_metadata_reconcile' === ( $decoded['skipped'][11]['reason_code'] ?? '' ), 'JSON missing metadata row uses metadata reconciliation reason');
    datamachine_code_cleanup_assert(str_contains($decoded['skipped'][11]['hint'] ?? '', 'reconcile-metadata --dry-run'), 'JSON metadata reconciliation row includes remediation hint');
    datamachine_code_cleanup_assert(10 === (int) ( $decoded['summary']['skipped_by_reason']['no_merge_signal'] ?? 0 ), 'JSON summary includes reason counts');
    datamachine_code_cleanup_assert(1 === (int) ( $decoded['summary']['cleanup_buckets']['safe_to_remove_now'] ?? 0 ), 'JSON summary includes safe-to-remove bucket count');
    datamachine_code_cleanup_assert(3 === (int) ( $decoded['summary']['cleanup_buckets']['needs_reconciliation'] ?? 0 ), 'JSON summary includes combined reconciliation bucket count');
    datamachine_code_cleanup_assert(11 === (int) ( $decoded['summary']['cleanup_buckets']['needs_full_review'] ?? 0 ), 'JSON summary includes full-review bucket count');
    datamachine_code_cleanup_assert(1 === (int) ( $decoded['summary']['cleanup_buckets']['blocked_by_dirty_or_unpushed'] ?? 0 ), 'JSON summary includes dirty/unpushed blocker bucket count');
    datamachine_code_cleanup_assert(1 === (int) ( $decoded['summary']['cleanup_buckets']['lifecycle_reconciliation_candidates'] ?? 0 ), 'JSON summary includes lifecycle reconciliation bucket count');
    datamachine_code_cleanup_assert(2 === (int) ( $decoded['summary']['cleanup_buckets']['metadata_reconciliation_candidates'] ?? 0 ), 'JSON summary includes metadata reconciliation bucket count');
    datamachine_code_cleanup_assert(1 === (int) ( $decoded['summary']['stale_reasons']['dirty'] ?? 0 ), 'JSON summary includes stale reason metadata counts');
    datamachine_code_cleanup_assert(1 === (int) ( $decoded['summary']['liveness']['stale'] ?? 0 ), 'JSON summary includes liveness metadata counts');
    datamachine_code_cleanup_assert(9 === count($decoded['summary']['skipped_next_commands'] ?? array()), 'JSON summary includes actionable skipped next commands');
    datamachine_code_cleanup_assert(str_contains($decoded['summary']['skipped_next_commands'][0]['command'] ?? '', 'worktree cleanup --dry-run --format=json'), 'JSON lifecycle command runs DMC-owned cleanup signal detection');
    datamachine_code_cleanup_assert(str_contains($decoded['summary']['skipped_next_commands'][1]['command'] ?? '', 'reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json'), 'JSON metadata command is bounded metadata reconciliation');
    datamachine_code_cleanup_assert(str_contains($decoded['summary']['skipped_next_commands'][2]['command'] ?? '', 'active-no-signal-report'), 'JSON active/no-signal command routes to evidence report');
    datamachine_code_cleanup_assert(str_contains($decoded['summary']['skipped_next_commands'][3]['alternative'] ?? '', 'active-no-signal-finalized-apply --dry-run'), 'JSON no-merge command routes finalized PR rows to dry-run apply');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($decoded['summary']['skipped_next_commands'] ?? array()), 'git -C <worktree-path> status --short --branch'), 'JSON dirty bucket recommends narrow git status evidence');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($decoded['summary']['skipped_next_commands'] ?? array()), 'git -C <worktree-path> log --oneline --decorate @{u}..HEAD'), 'JSON unpushed bucket recommends narrow git log evidence');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($decoded['summary']['skipped_next_commands'] ?? array()), 'git -C <primary-path> worktree prune --dry-run --verbose'), 'JSON stale marker bucket recommends prune preview');
    datamachine_code_cleanup_assert(str_contains((string) wp_json_encode($decoded['summary']['skipped_next_commands'] ?? array()), 'workspace show <repo>'), 'JSON primary-missing bucket recommends primary report');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '30s', 'format' => 'json' ));
    datamachine_code_cleanup_assert(5 === (int) ( $ability->last_input['limit'] ?? 0 ), 'cleanup forwards dry-run limit');
    datamachine_code_cleanup_assert(10 === (int) ( $ability->last_input['offset'] ?? 0 ), 'cleanup forwards dry-run offset');
    datamachine_code_cleanup_assert('30s' === ( $ability->last_input['until_budget'] ?? '' ), 'cleanup forwards dry-run time budget');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'active-no-signal-report' ), array( 'limit' => 5, 'offset' => 10, 'until-budget' => '30s', 'format' => 'json' ));
	datamachine_code_cleanup_assert('30s' === ( $active_report_ability->last_input['until_budget'] ?? '' ), 'active/no-signal report forwards time budget');
	$active_report_json = json_decode(WP_CLI::$logs[0] ?? '', true);
	datamachine_code_cleanup_assert(str_contains($active_report_json['pagination']['next_command'] ?? '', '--until-budget=30s'), 'active/no-signal report JSON continuation keeps time budget');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'reconcile-metadata' ), array( 'dry-run' => true, 'format' => 'json' ));
	datamachine_code_cleanup_assert(25 === (int) ( $reconcile_metadata_ability->last_input['limit'] ?? 0 ), 'metadata reconciliation dry-run defaults to bounded limit');
	datamachine_code_cleanup_assert(0 === (int) ( $reconcile_metadata_ability->last_input['offset'] ?? -1 ), 'metadata reconciliation dry-run defaults to first page');
	datamachine_code_cleanup_assert('30s' === ( $reconcile_metadata_ability->last_input['until_budget'] ?? '' ), 'metadata reconciliation dry-run defaults to bounded time budget');

	WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'active-no-signal-finalized-apply' ), array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '30s', 'format' => 'json' ));
    datamachine_code_cleanup_assert('30s' === ( $active_finalized_ability->last_input['until_budget'] ?? '' ), 'finalized active/no-signal apply forwards time budget');
    $active_finalized_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(str_contains($active_finalized_json['pagination']['next_command'] ?? '', 'active-no-signal-finalized-apply --dry-run'), 'finalized active/no-signal JSON continuation stays on dry-run apply');
    datamachine_code_cleanup_assert(str_contains($active_finalized_json['pagination']['next_command'] ?? '', '--until-budget=30s'), 'finalized active/no-signal JSON continuation keeps time budget');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'active-no-signal-equivalent-clean-apply' ), array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '30s', 'format' => 'json' ));
    datamachine_code_cleanup_assert('30s' === ( $active_equivalent_clean_ability->last_input['until_budget'] ?? '' ), 'equivalent-clean active/no-signal apply forwards time budget');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'active-no-signal-merged-apply' ), array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '30s', 'format' => 'json' ));
    datamachine_code_cleanup_assert('30s' === ( $active_merged_ability->last_input['until_budget'] ?? '' ), 'merged active/no-signal apply forwards time budget');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'active-no-signal-remote-clean-apply' ), array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '30s', 'format' => 'json' ));
    datamachine_code_cleanup_assert('30s' === ( $active_remote_clean_ability->last_input['until_budget'] ?? '' ), 'remote-clean active/no-signal apply forwards time budget');
    $active_remote_clean_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(str_contains($active_remote_clean_json['pagination']['next_command'] ?? '', 'active-no-signal-remote-clean-apply --dry-run'), 'remote-clean active/no-signal JSON continuation stays on dry-run apply');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'active-no-signal-finalized-apply' ), array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '30s' ));
    datamachine_code_cleanup_assert(in_array('Next page: studio wp datamachine-code workspace worktree active-no-signal-finalized-apply --dry-run --limit=5 --offset=15 --until-budget=30s --format=json', WP_CLI::$logs, true), 'human active/no-signal apply continuation keeps dry-run and time budget');

    echo "\n[1a] abandoned cleanup orchestrates safe DMC abilities\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'force' => true, 'limit' => 1, 'passes' => 1, 'until-budget' => '30s', 'format' => 'json' ));
    $abandoned_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned JSON output parses cleanly');
    datamachine_code_cleanup_assert(true === ( $abandoned_json['applied'] ?? null ), 'abandoned apply mode is explicit in JSON');
    datamachine_code_cleanup_assert(true === ( $abandoned_json['force'] ?? null ), 'abandoned force mode is explicit in JSON');
    datamachine_code_cleanup_assert('bounded_apply_initial' === array_key_first($abandoned_json['steps'] ?? array()), 'abandoned apply drains already-marked rows before slow classifiers');
    datamachine_code_cleanup_assert(1 === (int) ( $reconcile_metadata_ability->last_input['limit'] ?? 0 ), 'abandoned forwards limit to metadata reconciliation');
    datamachine_code_cleanup_assert(false === ( $reconcile_metadata_ability->last_input['dry_run'] ?? null ), 'abandoned --apply applies metadata reconciliation');
    datamachine_code_cleanup_assert(array( 0, 1 ) === array_map(fn( $input ) => (int) ( $input['offset'] ?? 0 ), array_slice($reconcile_metadata_ability->inputs, -2)), 'abandoned drains metadata reconciliation pages in apply mode');
    $abandoned_forwarded_budget = (string) ( $active_finalized_ability->last_input['until_budget'] ?? '' );
    datamachine_code_cleanup_assert(1 === preg_match('/^\d+s$/', $abandoned_forwarded_budget) && (int) $abandoned_forwarded_budget <= 30, 'abandoned forwards remaining time budget to active/no-signal marking');
    datamachine_code_cleanup_assert(array( 0, 1 ) === array_map(fn( $input ) => (int) ( $input['offset'] ?? 0 ), array_slice($active_finalized_ability->inputs, -2)), 'abandoned drains active/no-signal classifier pages in apply mode');
    datamachine_code_cleanup_assert(array( 0, 1 ) === array_map(fn( $input ) => (int) ( $input['offset'] ?? 0 ), array_slice($active_remote_clean_ability->inputs, -2)), 'abandoned drains remote-clean active/no-signal classifier pages before bounded cleanup');
    datamachine_code_cleanup_assert(true === ( $bounded_apply_ability->last_input['force'] ?? null ), 'abandoned forwards force only to bounded cleanup removal');
    datamachine_code_cleanup_assert(false === ( $bounded_apply_ability->last_input['dry_run'] ?? null ), 'abandoned --apply removes eligible rows');
    datamachine_code_cleanup_assert(1 === $prune_ability->calls, 'abandoned prunes stale git metadata after cleanup pass');
    datamachine_code_cleanup_assert(2 === (int) ( $abandoned_json['summary']['removed'] ?? 0 ), 'abandoned summary reports removed rows from initial and post-classifier drains');
    datamachine_code_cleanup_assert(2 === (int) ( $abandoned_json['summary']['blocked'] ?? 0 ), 'abandoned summary reports blocked rows');
    datamachine_code_cleanup_assert(1 === (int) ( $abandoned_json['summary']['blocked_by_reason']['unpushed_commits'] ?? 0 ), 'abandoned preserves unpushed-commit blocker evidence');

    $bounded_apply_ability->extra_skipped = 30;
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'force' => true, 'stage' => 'bounded', 'limit' => 10, 'passes' => 1, 'format' => 'json' ));
    $abandoned_compact_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned compact JSON output parses cleanly');
    datamachine_code_cleanup_assert(32 === (int) ( $abandoned_compact_json['summary']['blocked'] ?? 0 ), 'abandoned compact JSON keeps blocked summary count');
    datamachine_code_cleanup_assert(array() === ( $abandoned_compact_json['blocked'] ?? null ), 'abandoned compact JSON omits full blocked rows');
    datamachine_code_cleanup_assert(true === ( $abandoned_compact_json['evidence']['blocked_truncated'] ?? false ), 'abandoned compact JSON records blocked truncation evidence');
    datamachine_code_cleanup_assert(isset($abandoned_compact_json['blocked_examples']['active_no_signal'][0]['handle']), 'abandoned compact JSON includes grouped blocked examples');
    datamachine_code_cleanup_assert(! isset($abandoned_compact_json['steps']['bounded_apply_initial']['pagination']['remaining_handles']), 'abandoned compact JSON omits full nested remaining handles');
    datamachine_code_cleanup_assert(32 === (int) ( $abandoned_compact_json['steps']['bounded_apply_initial']['pagination']['remaining_handles_count'] ?? 0 ), 'abandoned compact JSON keeps nested remaining handle count');
    datamachine_code_cleanup_assert(25 === count($abandoned_compact_json['steps']['bounded_apply_initial']['pagination']['remaining_handles_examples'] ?? array()), 'abandoned compact JSON keeps bounded nested handle examples');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'force' => true, 'stage' => 'bounded', 'limit' => 10, 'passes' => 1, 'verbose' => true, 'format' => 'json' ));
    $abandoned_verbose_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned verbose JSON output parses cleanly');
    datamachine_code_cleanup_assert(32 === count($abandoned_verbose_json['blocked'] ?? array()), 'abandoned verbose JSON keeps full blocked rows');
    datamachine_code_cleanup_assert(32 === count($abandoned_verbose_json['steps']['bounded_apply_initial']['pagination']['remaining_handles'] ?? array()), 'abandoned verbose JSON keeps full nested remaining handles');
    datamachine_code_cleanup_assert(! isset($abandoned_verbose_json['evidence']['blocked_truncated']), 'abandoned verbose JSON does not report truncation');
    $bounded_apply_ability->extra_skipped = 0;

    $reconcile_call_count = count($reconcile_metadata_ability->inputs);
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'stage' => 'finalized', 'offset' => 7, 'limit' => 1, 'passes' => 1, 'format' => 'json' ));
    $abandoned_resume_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned resume JSON output parses cleanly');
    datamachine_code_cleanup_assert('finalized' === ( $abandoned_resume_json['stage'] ?? '' ), 'abandoned resume reports requested stage');
    datamachine_code_cleanup_assert(7 === (int) ( $abandoned_resume_json['offset'] ?? 0 ), 'abandoned resume reports requested offset');
    datamachine_code_cleanup_assert($reconcile_call_count === count($reconcile_metadata_ability->inputs), 'abandoned resume skips completed reconciliation stage');
    datamachine_code_cleanup_assert(7 === (int) ( $active_finalized_ability->last_input['offset'] ?? -1 ), 'abandoned resume forwards offset to requested classifier stage');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'stage' => 'remote-clean', 'offset' => 11, 'limit' => 1, 'passes' => 1, 'format' => 'json' ));
    $abandoned_remote_clean_resume_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned remote-clean resume JSON output parses cleanly');
    datamachine_code_cleanup_assert('remote-clean' === ( $abandoned_remote_clean_resume_json['stage'] ?? '' ), 'abandoned remote-clean resume reports requested stage');
    datamachine_code_cleanup_assert(11 === (int) ( $active_remote_clean_ability->last_input['offset'] ?? -1 ), 'abandoned resume forwards offset to remote-clean stage');

    $bounded_call_count_before_stalled_classifier = count($bounded_apply_ability->inputs);
    $active_remote_clean_ability->stall_at_offset = 42;
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'force' => true, 'stage' => 'remote-clean', 'offset' => 42, 'limit' => 10, 'passes' => 1, 'until-budget' => '30s', 'format' => 'json' ));
    $abandoned_remote_clean_stalled_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned stalled remote-clean JSON output parses cleanly');
    datamachine_code_cleanup_assert('remote-clean' === ( $abandoned_remote_clean_stalled_json['continuation']['stage'] ?? '' ), 'abandoned stalled remote-clean emits remote-clean continuation');
    datamachine_code_cleanup_assert(42 === (int) ( $abandoned_remote_clean_stalled_json['continuation']['offset'] ?? -1 ), 'abandoned stalled remote-clean keeps current offset continuation');
    datamachine_code_cleanup_assert(count($bounded_apply_ability->inputs) === $bounded_call_count_before_stalled_classifier + 2, 'abandoned drains bounded cleanup before and after stalled classifier continuation');
    datamachine_code_cleanup_assert('bounded_apply_initial' === array_key_first($abandoned_remote_clean_stalled_json['steps'] ?? array()), 'abandoned stalled classifier run starts with bounded cleanup');
    datamachine_code_cleanup_assert(isset($abandoned_remote_clean_stalled_json['steps']['bounded_apply_remote-clean']), 'abandoned stalled classifier output includes bounded cleanup step');
    datamachine_code_cleanup_assert(2 === (int) ( $abandoned_remote_clean_stalled_json['summary']['removed'] ?? 0 ), 'abandoned stalled classifier summary includes bounded removals');
    $active_remote_clean_ability->stall_at_offset = null;

    $reconcile_metadata_ability->stall_at_offset = 90;
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'apply' => true, 'stage' => 'reconcile', 'offset' => 90, 'limit' => 10, 'passes' => 1, 'until-budget' => '30s', 'format' => 'json' ));
    $abandoned_reconcile_resume_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(JSON_ERROR_NONE === json_last_error(), 'abandoned same-offset reconcile continuation JSON output parses cleanly');
    datamachine_code_cleanup_assert('reconcile' === ( $abandoned_reconcile_resume_json['continuation']['stage'] ?? '' ), 'abandoned same-offset reconcile continuation keeps reconcile stage');
    datamachine_code_cleanup_assert(90 === (int) ( $abandoned_reconcile_resume_json['continuation']['offset'] ?? -1 ), 'abandoned same-offset reconcile continuation keeps current offset');
    datamachine_code_cleanup_assert(str_contains($abandoned_reconcile_resume_json['continuation']['next_command'] ?? '', '--stage=reconcile --offset=90'), 'abandoned same-offset reconcile continuation emits resumed command');
    $reconcile_metadata_ability->stall_at_offset = null;

    $prune_calls_before_preview = $prune_ability->calls;
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'abandoned' ), array( 'limit' => 5, 'passes' => 3, 'format' => 'json' ));
    $abandoned_preview_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(false === ( $abandoned_preview_json['applied'] ?? null ), 'abandoned preview leaves apply mode false');
    datamachine_code_cleanup_assert(1 === (int) ( $abandoned_preview_json['executed_passes'] ?? 0 ), 'abandoned preview runs one classification pass even when more passes are requested');
    datamachine_code_cleanup_assert($prune_calls_before_preview === $prune_ability->calls, 'abandoned preview does not prune git metadata');
    datamachine_code_cleanup_assert(true === ( $abandoned_preview_json['steps']['prune']['skipped'] ?? false ), 'abandoned preview explains skipped prune step');

    echo "\n[1b] --apply-plan decodes JSON and forbids force\n";
    $plan_file = sys_get_temp_dir() . '/dmc-cleanup-plan-' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($plan_file, wp_json_encode(datamachine_code_cleanup_report()));
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'apply-plan' => $plan_file, 'skip-github' => true, 'format' => 'json' ));
    datamachine_code_cleanup_assert(false === ( $ability->last_input['dry_run'] ?? null ), '--apply-plan does not imply dry-run');
    datamachine_code_cleanup_assert(false === ( $ability->last_input['force'] ?? null ), '--apply-plan forwards force=false');
    datamachine_code_cleanup_assert('repo@merged' === ( $ability->last_input['apply_plan']['candidates'][0]['handle'] ?? '' ), '--apply-plan forwards decoded plan');
    try {
        $command->worktree(array( 'cleanup' ), array( 'apply-plan' => $plan_file, 'force' => true ));
        datamachine_code_cleanup_assert(false, '--apply-plan --force throws');
    } catch ( RuntimeException $e ) {
        datamachine_code_cleanup_assert(str_contains($e->getMessage(), 'Do not combine --apply-plan with --force'), '--apply-plan --force is rejected');
    }
    unlink($plan_file);

    echo "\n[2] default output is concise and summary-first\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true ));
    datamachine_code_cleanup_assert(str_starts_with(WP_CLI::$logs[0] ?? '', 'Cleanup progress:'), 'human output streams cleanup progress before summary');
	datamachine_code_cleanup_assert(in_array('Summary:', WP_CLI::$logs, true), 'human output includes summary after progress');
	datamachine_code_cleanup_assert(in_array('table:1:handle,branch,age_days,size,artifacts,signal,reason_code', WP_CLI::$logs, true), 'default candidate table uses compact disk fields');
	datamachine_code_cleanup_assert(in_array('Skipped summary:', WP_CLI::$logs, true), 'default cleanup output summarizes skipped rows by reason');
	datamachine_code_cleanup_assert(in_array('table:9:reason_code,count,examples', WP_CLI::$logs, true), 'default skipped summary groups reasons instead of listing every row');
	datamachine_code_cleanup_assert(in_array('Re-run with --verbose to list every skipped row or --only=<reason_code> to inspect one bucket.', WP_CLI::$logs, true), 'default skipped summary points to verbose or filtered follow-up');
	datamachine_code_cleanup_assert(! in_array('table:10:handle,reason_code,age_days,size,artifacts,reason', WP_CLI::$logs, true), 'default skipped table does not list individual skipped rows');
	datamachine_code_cleanup_assert(in_array('Top repos by worktree size:', WP_CLI::$logs, true), 'human output includes top repo size summary');
	datamachine_code_cleanup_assert(in_array('Next commands for skipped buckets:', WP_CLI::$logs, true), 'human output includes actionable skipped command section');
	datamachine_code_cleanup_assert(in_array('table:9:reason_code,count,destructive,command,alternative', WP_CLI::$logs, true), 'human output renders compact skipped command table');
	datamachine_code_cleanup_assert(1 === count(WP_CLI::$successes), 'human output keeps success suffix');

    echo "\n[3] verbose output keeps detailed human fields\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'verbose' => true ));
    datamachine_code_cleanup_assert(in_array('table:1:handle,branch,age_days,size,artifacts,signal,reason', WP_CLI::$logs, true), 'verbose candidate table keeps full reason field');
    datamachine_code_cleanup_assert(in_array('table:19:handle,reason_code,reason,age_days,size,artifacts,repo,branch,path,primary_path,missing,hint', WP_CLI::$logs, true), 'verbose skipped table keeps diagnostic fields');

    echo "\n[4] --only filters rows while keeping full summary\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'only' => 'missing-metadata', 'format' => 'json' ));
    $filtered = json_decode(WP_CLI::$logs[0], true);
    datamachine_code_cleanup_assert(array() === ( $filtered['candidates'] ?? null ), '--only reason hides candidates');
    datamachine_code_cleanup_assert(2 === count($filtered['skipped'] ?? array()), '--only reason keeps matching skipped rows only');
    datamachine_code_cleanup_assert('needs_metadata_reconcile' === ( $filtered['skipped'][0]['reason_code'] ?? '' ), '--only alias resolves to metadata reconciliation reason code');
    datamachine_code_cleanup_assert(19 === (int) ( $filtered['summary']['skipped'] ?? 0 ), '--only leaves summary counts unfiltered');

    echo "\n[5] --only aliases resolve candidate section\n";
    foreach ( array( 'candidates', 'would-remove', 'would_remove' ) as $alias ) {
        WP_CLI::$logs      = array();
        WP_CLI::$successes = array();
        $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'only' => $alias, 'format' => 'json' ));
        $filtered = json_decode(WP_CLI::$logs[0], true);
        datamachine_code_cleanup_assert(1 === count($filtered['candidates'] ?? array()), "--only={$alias} keeps candidates");
        datamachine_code_cleanup_assert(array() === ( $filtered['removed'] ?? null ), "--only={$alias} hides removed");
        datamachine_code_cleanup_assert(array() === ( $filtered['skipped'] ?? null ), "--only={$alias} hides skipped");
        datamachine_code_cleanup_assert(1 === (int) ( $filtered['summary']['would_remove'] ?? 0 ), "--only={$alias} keeps summary counts");
    }

    echo "\n[6] --older-than forwards and renders age summary\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'older-than' => '7d' ));
    datamachine_code_cleanup_assert('7d' === ( $ability->last_input['older_than'] ?? null ), 'older-than forwards to cleanup ability as older_than');
    datamachine_code_cleanup_assert(is_callable($ability->last_input['progress_callback'] ?? null), 'human cleanup passes progress callback to ability');
    datamachine_code_cleanup_assert(in_array('table:27:metric,count', WP_CLI::$logs, true), 'age filter, cleanup buckets, stale/liveness, and disk summary rows are rendered');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'older-than' => '7d', 'format' => 'json' ));
    $older_than_json = json_decode(WP_CLI::$logs[0], true);
    datamachine_code_cleanup_assert('7d' === ( $older_than_json['summary']['age_filter']['older_than'] ?? '' ), 'JSON summary exposes older_than filter value');
    datamachine_code_cleanup_assert(2 === (int) ( $older_than_json['summary']['age_filter']['excluded'] ?? 0 ), 'JSON summary exposes age-filter excluded count');

    echo "\n[7] --sort forwards cleanup sorting field\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
	$command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'skip-github' => true, 'sort' => 'size', 'format' => 'json' ));
	datamachine_code_cleanup_assert('size' === ( $ability->last_input['sort'] ?? null ), '--sort forwards to cleanup ability');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->cleanup(array( 'run' ), array( 'mode' => 'artifacts', 'dry-run' => true, 'sort' => 'size', 'format' => 'json' ));
	datamachine_code_cleanup_assert('size' === ( $artifact_ability->last_input['sort'] ?? null ), 'workspace cleanup artifact dry-run forwards size sort');
	$ranked_artifact_json = json_decode(WP_CLI::$logs[0] ?? '', true);
	datamachine_code_cleanup_assert('ranked_inventory' === ( $ranked_artifact_json['pagination']['mode'] ?? '' ), 'artifact size sort reports ranked inventory mode');
	datamachine_code_cleanup_assert('size' === ( $ranked_artifact_json['pagination']['sort'] ?? '' ), 'artifact size sort advertises size ordering');

    echo "\n[8] --inventory-only forwards bounded cleanup review flag\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'inventory-only' => true, 'skip-github' => true, 'format' => 'json' ));
    datamachine_code_cleanup_assert(true === ( $ability->last_input['inventory_only'] ?? null ), '--inventory-only forwards to cleanup ability');
    $inventory_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert(str_contains($inventory_json['summary']['apply_command'] ?? '', 'bounded-cleanup-eligible-apply --limit=1'), 'inventory-only JSON exposes bounded cleanup-eligible apply command');
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
	$command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'inventory-only' => true, 'skip-github' => true ));
	datamachine_code_cleanup_assert(str_contains(WP_CLI::$successes[0] ?? '', 'Apply this bounded reviewed class with: studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=1'), 'inventory-only human output prints bounded apply command');
	$command->worktree(array( 'cleanup' ), array( 'dry-run' => true, 'inventory-only' => true, 'include-repaired-metadata' => true, 'format' => 'json' ));
	datamachine_code_cleanup_assert(true === ( $ability->last_input['include_repaired_metadata'] ?? null ), '--include-repaired-metadata forwards to cleanup ability');

	$bounded_apply_ability->extra_skipped = 30;
	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'bounded-cleanup-eligible-apply' ), array( 'limit' => 25 ));
	datamachine_code_cleanup_assert(in_array('Result: removed 1 worktree(s); reclaimed 4.0 KiB; skipped 2.', WP_CLI::$logs, true), 'bounded cleanup apply highlights removed/reclaimed totals first');
	datamachine_code_cleanup_assert(in_array('Skipped summary:', WP_CLI::$logs, true), 'bounded cleanup apply summarizes skipped rows by default');
	datamachine_code_cleanup_assert(in_array('table:4:reason_code,count,examples', WP_CLI::$logs, true), 'bounded cleanup apply groups skipped reasons');
	datamachine_code_cleanup_assert(! in_array('table:32:handle,reason_code,reason', WP_CLI::$logs, true), 'bounded cleanup apply default output does not list every skipped row');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'bounded-cleanup-eligible-apply' ), array( 'limit' => 25, 'verbose' => true ));
	datamachine_code_cleanup_assert(in_array('table:32:handle,reason_code,reason', WP_CLI::$logs, true), 'bounded cleanup apply verbose output lists skipped rows');
	$bounded_apply_ability->extra_skipped = 0;

	echo "\n[8a] active/no-signal apply forwards bounded continuation flags\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(
        array( 'active-no-signal-finalized-apply' ),
        array( 'dry-run' => true, 'limit' => 5, 'offset' => 10, 'until-budget' => '60s', 'format' => 'json' )
    );
    datamachine_code_cleanup_assert(true === ( $active_finalized_ability->last_input['dry_run'] ?? null ), 'active/no-signal apply forwards dry-run flag');
    datamachine_code_cleanup_assert(5 === (int) ( $active_finalized_ability->last_input['limit'] ?? 0 ), 'active/no-signal apply forwards limit');
    datamachine_code_cleanup_assert(10 === (int) ( $active_finalized_ability->last_input['offset'] ?? 0 ), 'active/no-signal apply forwards offset');
    datamachine_code_cleanup_assert('60s' === ( $active_finalized_ability->last_input['until_budget'] ?? '' ), 'active/no-signal apply forwards until-budget continuation');

    echo "\n[8b] emergency-cleanup keeps apply-plan as low-level escape hatch\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'emergency-cleanup' ), array( 'format' => 'json' ));
    datamachine_code_cleanup_assert(array( 'dry_run' => true, 'force' => false ) === $emergency_ability->last_input, 'emergency-cleanup defaults to dry-run and no force');
    $emergency_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert('emergency' === ( $emergency_json['mode'] ?? '' ), 'emergency-cleanup JSON includes mode');
    datamachine_code_cleanup_assert('target' === ( $emergency_json['artifact_candidates'][0]['artifacts'][0]['path'] ?? '' ), 'emergency-cleanup JSON includes artifact candidates first');

    $emergency_plan_file = sys_get_temp_dir() . '/dmc-emergency-cleanup-plan-' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($emergency_plan_file, wp_json_encode($emergency_json));
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'emergency-cleanup' ), array( 'apply-plan' => $emergency_plan_file, 'force' => true ));
    datamachine_code_cleanup_assert(false === ( $emergency_ability->last_input['dry_run'] ?? null ), 'emergency-cleanup apply-plan enters apply mode');
    datamachine_code_cleanup_assert(true === ( $emergency_ability->last_input['force'] ?? null ), 'emergency-cleanup forwards explicit force for human-reviewed apply');
    datamachine_code_cleanup_assert('repo@old' === ( $emergency_ability->last_input['apply_plan']['artifact_candidates'][0]['handle'] ?? '' ), 'emergency-cleanup forwards decoded apply plan');
    datamachine_code_cleanup_assert('Emergency cleanup summary:' === ( WP_CLI::$logs[0] ?? '' ), 'emergency-cleanup human output uses emergency summary');
    unlink($emergency_plan_file);

    echo "\n[9] cleanup-artifacts forwards plan-first flags and renders separately\n";
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup-artifacts' ), array( 'dry-run' => true, 'format' => 'json' ));
    datamachine_code_cleanup_assert(array( 'dry_run' => true, 'force' => false ) === $artifact_ability->last_input, 'cleanup-artifacts dry-run flags forwarded to ability');
    $artifact_json = json_decode(WP_CLI::$logs[0] ?? '', true);
    datamachine_code_cleanup_assert('target' === ( $artifact_json['candidates'][0]['artifacts'][0]['path'] ?? '' ), 'cleanup-artifacts JSON includes artifact paths');
    datamachine_code_cleanup_assert('studio wp datamachine-code workspace cleanup run --mode=artifacts --limit=100 --offset=0 --format=json' === ( $artifact_json['apply_command'] ?? '' ), 'cleanup-artifacts JSON includes matching high-level apply command');
    datamachine_code_cleanup_assert(( $artifact_json['apply_command'] ?? '' ) === ( $artifact_json['summary']['apply_command'] ?? null ), 'cleanup-artifacts summary repeats matching apply command');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'cleanup-artifacts' ), array( 'dry-run' => true ));
	datamachine_code_cleanup_assert(str_contains(WP_CLI::$successes[0] ?? '', 'workspace cleanup run --mode=artifacts --limit=100 --offset=0 --format=json'), 'cleanup-artifacts dry-run points daily apply path to same task-backed page');
	datamachine_code_cleanup_assert(str_contains(WP_CLI::$successes[0] ?? '', 'low-level escape hatch'), 'cleanup-artifacts dry-run demotes apply-plan wording');
	datamachine_code_cleanup_assert(! str_contains(WP_CLI::$successes[0] ?? '', 'Save JSON'), 'cleanup-artifacts dry-run does not normalize saving plan files');
	datamachine_code_cleanup_assert(in_array('Skipped worktrees summary:', WP_CLI::$logs, true), 'cleanup-artifacts human output summarizes skipped worktrees by default');
	datamachine_code_cleanup_assert(in_array('table:1:reason_code,count,examples', WP_CLI::$logs, true), 'cleanup-artifacts skipped summary is grouped by reason');
	datamachine_code_cleanup_assert(! in_array('table:1:handle,repo,branch,artifacts,reason_code,reason', WP_CLI::$logs, true), 'cleanup-artifacts default output does not list every skipped worktree');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'cleanup-artifacts' ), array( 'dry-run' => true, 'sort' => 'size' ));
	datamachine_code_cleanup_assert('size' === ( $artifact_ability->last_input['sort'] ?? null ), 'cleanup-artifacts forwards size sort');
	datamachine_code_cleanup_assert(in_array('Largest artifact opportunities:', WP_CLI::$logs, true), 'cleanup-artifacts size sort labels ranked opportunities');
	datamachine_code_cleanup_assert(in_array('Ranked by size across 200 scanned worktree(s); showing the largest 2 candidate(s).', WP_CLI::$logs, true), 'cleanup-artifacts size sort explains full-inventory ranking');

	WP_CLI::$logs      = array();
	WP_CLI::$successes = array();
	$command->worktree(array( 'cleanup-artifacts' ), array( 'dry-run' => true, 'verbose' => true ));
	datamachine_code_cleanup_assert(in_array('table:1:handle,repo,branch,artifacts,reason_code,reason', WP_CLI::$logs, true), 'cleanup-artifacts verbose output lists skipped worktrees');

    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup-artifacts' ), array( 'dry-run' => true, 'limit' => 0, 'exhaustive' => true, 'format' => 'json' ));
    datamachine_code_cleanup_assert(0 === (int) ( $artifact_ability->last_input['limit'] ?? -1 ), 'cleanup-artifacts forwards limit=0 when exhaustive is explicit');
    datamachine_code_cleanup_assert(true === ( $artifact_ability->last_input['exhaustive'] ?? false ), 'cleanup-artifacts forwards exhaustive flag');

    $artifact_plan_file = sys_get_temp_dir() . '/dmc-artifact-cleanup-plan-' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($artifact_plan_file, wp_json_encode($artifact_json));
    WP_CLI::$logs      = array();
    WP_CLI::$successes = array();
    $command->worktree(array( 'cleanup-artifacts' ), array( 'apply-plan' => $artifact_plan_file, 'force' => true ));
    datamachine_code_cleanup_assert(false === ( $artifact_ability->last_input['dry_run'] ?? null ), 'cleanup-artifacts apply-plan enters apply mode');
    datamachine_code_cleanup_assert(true === ( $artifact_ability->last_input['force'] ?? null ), 'cleanup-artifacts forwards explicit force for dirty/unpushed artifacts');
    datamachine_code_cleanup_assert('repo@old' === ( $artifact_ability->last_input['apply_plan']['candidates'][0]['handle'] ?? '' ), 'cleanup-artifacts forwards decoded apply plan');
    datamachine_code_cleanup_assert('Artifact cleanup summary:' === ( WP_CLI::$logs[0] ?? '' ), 'cleanup-artifacts human output uses artifact-specific summary');
    unlink($artifact_plan_file);

    echo "\nAll worktree cleanup CLI smoke tests passed.\n";
}
