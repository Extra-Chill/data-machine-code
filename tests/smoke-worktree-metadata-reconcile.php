<?php
/**
 * Smoke test for unmanaged worktree metadata reconciliation.
 *
 * Run: php tests/smoke-worktree-metadata-reconcile.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    if (! class_exists(__NAMESPACE__ . '\FilesystemHelper') ) {
        class FilesystemHelper
        {
            public static function get()
            {
                return null;
            }
        }
    }
}

namespace DataMachineCode\Abilities {
    if (! class_exists(__NAMESPACE__ . '\GitHubAbilities') ) {
        class GitHubAbilities
        {
            public static function getPat(): string
            {
                return 'test-token';
            }
            public static function apiGet( string $url, array $params, string $pat, int $timeout = 0 )  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
            {
                if (str_ends_with($url, '/pulls/101') ) {
                    return array(
                     'data' => array(
                      'number'    => 101,
                      'state'     => 'closed',
                      'merged_at' => '2026-04-03T00:00:00Z',
                      'html_url'  => 'https://github.com/acme/demo/pull/101',
                     ),
                    );
                }
                if (str_ends_with($url, '/pulls/102') ) {
                    return array(
                    'data' => array(
                    'number'    => 102,
                    'state'     => 'closed',
                    'merged_at' => '',
                    'html_url'  => 'https://github.com/acme/demo/pull/102',
                    ),
                    );
                }
                if (str_ends_with($url, '/pulls') && 'acme:old-merged-branch' === ( $params['head'] ?? '' ) ) {
                    return array(
                    'data' => array(
                    array(
                    'number'    => 103,
                    'state'     => 'closed',
                    'merged_at' => '2026-04-04T00:00:00Z',
                    'html_url'  => 'https://github.com/acme/demo/pull/103',
                    'head'      => array(
                     'ref'  => 'old-merged-branch',
                     'repo' => array( 'full_name' => 'acme/demo' ),
                                ),
                    ),
                    ),
                    );
                }
                if (str_ends_with($url, '/pulls') && 'acme:pr-merged' === ( $params['head'] ?? '' ) ) {
                    return array(
                    'data' => array(
                    array(
                    'number'    => 101,
                    'state'     => 'closed',
                    'merged_at' => '2026-04-03T00:00:00Z',
                    'html_url'  => 'https://github.com/acme/demo/pull/101',
                    'head'      => array(
                     'ref'  => 'pr-merged',
                     'repo' => array( 'full_name' => 'acme/demo' ),
                                ),
                    ),
                    ),
                    );
                }
                if (str_ends_with($url, '/pulls') && 'acme:head-merged' === ( $params['head'] ?? '' ) ) {
                    return array(
                    'data' => array(
                    array(
                    'number'    => 106,
                    'state'     => 'closed',
                    'merged_at' => '2026-04-05T00:00:00Z',
                    'html_url'  => 'https://github.com/acme/demo/pull/106',
                    'head'      => array(
                     'ref'  => 'head-merged',
                     'repo' => array( 'full_name' => 'acme/demo' ),
                                ),
                    ),
                    ),
                    );
                }
                if (str_ends_with($url, '/pulls') && 'acme:pr-closed' === ( $params['head'] ?? '' ) ) {
                    return array(
                    'data' => array(
                    array(
                    'number'    => 102,
                    'state'     => 'closed',
                    'merged_at' => '',
                    'html_url'  => 'https://github.com/acme/demo/pull/102',
                    'head'      => array(
                     'ref'  => 'pr-closed',
                     'repo' => array( 'full_name' => 'acme/demo' ),
                                ),
                    ),
                    ),
                    );
                }
                if (str_ends_with($url, '/pulls') && 'acme:already-current' === ( $params['head'] ?? '' ) ) {
                    return array(
                    'data' => array(
                    array(
                    'number'    => 105,
                    'state'     => 'open',
                    'merged_at' => '',
                    'html_url'  => 'https://github.com/acme/demo/pull/105',
                    'head'      => array(
                     'ref'  => 'already-current',
                     'repo' => array( 'full_name' => 'acme/demo' ),
                                ),
                    ),
                    ),
                    );
                }
                if (str_ends_with($url, '/pulls') && 'acme:open-branch' === ( $params['head'] ?? '' ) ) {
                    return array(
                    'data' => array(
                    array(
                    'number'    => 104,
                    'state'     => 'open',
                    'merged_at' => '',
                    'html_url'  => 'https://github.com/acme/demo/pull/104',
                    'head'      => array(
                     'ref'  => 'open-branch',
                     'repo' => array( 'full_name' => 'acme/demo' ),
                                ),
                    ),
                    ),
                    );
                }
                return array( 'data' => array() );
            }
        }
    }
}

namespace DataMachine\Engine\AI\System\Tasks {
    if (! class_exists(__NAMESPACE__ . '\\SystemTask') ) {
        abstract class SystemTask
        {
            abstract public function executeTask( int $jobId, array $params ): void;
            abstract public function getTaskType(): string;
            protected function completeJob( int $jobId, array $data ): void
            {
                $GLOBALS['datamachine_code_reconcile_chunk_jobs'][ $jobId ] = $data;
            }
            protected function failJob( int $jobId, string $message ): void
            {
                $GLOBALS['datamachine_code_reconcile_chunk_jobs'][ $jobId ] = array( 'error' => $message );
            }
        }
    }
}

namespace DataMachine\Engine\Tasks {
    if (! class_exists(__NAMESPACE__ . '\\TaskScheduler') ) {
        class TaskScheduler
        {
            public static array $batches = array();

            public static function scheduleBatch( string $task_type, array $items, array $context = array() )
            {
                self::$batches[] = array(
                 'task_type' => $task_type,
                 'items'     => $items,
                 'context'   => $context,
                );

                return array(
                 'batch_job_id' => 901,
                 'job_ids'      => range(1001, 1000 + count($items)),
                );
            }
        }
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public string $code;
            public string $message;
            public array $data;
            public function __construct( $code = '', $message = '', $data = array() )
            {
                $this->code    = (string) $code;
                $this->message = (string) $message;
                $this->data    = (array) $data;
            }
            public function get_error_message(): string
            {
                return $this->message;
            }
            public function get_error_code(): string
            {
                return $this->code;
            }
            public function get_error_data(): array
            {
                return $this->data;
            }
        }
    }

    if (! function_exists('is_wp_error') ) {
        function is_wp_error( $thing ): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (! function_exists('wp_mkdir_p') ) {
        function wp_mkdir_p( string $path ): bool
        {
            return is_dir($path) || mkdir($path, 0755, true);
        }
    }

    if (! function_exists('get_option') ) {
        function get_option( string $name, $default_value = false )
        {
            global $datamachine_code_test_options;
            return $datamachine_code_test_options[ $name ] ?? $default_value;
        }
    }

    if (! function_exists('update_option') ) {
        function update_option( string $name, $value, $autoload = null ): bool  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            global $datamachine_code_test_options;
            $datamachine_code_test_options[ $name ] = $value;
            return true;
        }
    }

    if (! function_exists('apply_filters') ) {
        function apply_filters( string $hook_name, $value )  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return $value;
        }
    }

    if (! function_exists('home_url') ) {
        function home_url(): string
        {
            return 'http://origin.test';
        }
    }

    if (! function_exists('get_bloginfo') ) {
        function get_bloginfo( string $show = '' ): string  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return 'Origin Test';
        }
    }

    if (! function_exists('size_format') ) {
        function size_format( $bytes ): string
        {
            return (string) $bytes;
        }
    }

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';
    include __DIR__ . '/../inc/Tasks/WorktreeCleanupChunkTask.php';

    exec('git --version 2>&1', $_gv, $gv_exit);
    if (0 !== $gv_exit ) {
        echo "SKIP: git not available\n";
        exit(0);
    }

    $tmp = sys_get_temp_dir() . '/dmc-reconcile-smoke-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    register_shutdown_function(
        function () use ( $tmp ) {
            if (is_dir($tmp) ) {
                exec('rm -rf ' . escapeshellarg($tmp));
            }
            if (is_dir($tmp . '-external') ) {
                exec('rm -rf ' . escapeshellarg($tmp . '-external'));
            }
        } 
    );

    define('DATAMACHINE_WORKSPACE_PATH', realpath($tmp) ? realpath($tmp) : $tmp);

    $failures = 0;
    $total    = 0;
    $datamachine_code_test_options = array();
    $GLOBALS['datamachine_code_reconcile_chunk_jobs'] = array();

    $assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
        $total++;
        if ($expected === $actual ) {
            echo "  ✓ {$message}\n";
            return;
        }
        $failures++;
        echo "  ✗ {$message}\n";
        echo '    expected: ' . var_export($expected, true) . "\n";
        echo '    actual:   ' . var_export($actual, true) . "\n";
    };

    $run = function ( string $cmd, string $cwd = '' ): array {
        $full = '' === $cwd ? $cmd : sprintf('cd %s && %s', escapeshellarg($cwd), $cmd);
        exec($full . ' 2>&1', $out, $rc);
        return array(
        'output' => implode("\n", $out),
        'exit'   => $rc,
        );
    };

    echo "Setting up workspace at {$tmp}\n";
    $remote  = $tmp . '/remote.git';
    $primary = $tmp . '/demo';
    $run(sprintf('git init --bare %s', escapeshellarg($remote)));
    $run(sprintf('git clone %s %s', escapeshellarg($remote), escapeshellarg($primary)));
    $run('git config user.email test@example.com', $primary);
    $run('git config user.name test', $primary);
    file_put_contents($primary . '/README.md', "demo\n");
    $run('git add README.md && git commit -m init', $primary);
    $run('git branch -M main', $primary);
    $run('git push -u origin main', $primary);
    $run('git remote set-head origin main', $primary);

    $make_branch = function ( string $branch ) use ( $primary, $run ): void {
        $run(sprintf('git checkout -b %s', escapeshellarg($branch)), $primary);
        file_put_contents($primary . '/' . str_replace('/', '-', $branch) . '.txt', $branch);
        $run(sprintf('git add . && git commit -m %s', escapeshellarg('work on ' . $branch)), $primary);
        $run(sprintf('git push -u origin %s', escapeshellarg($branch)), $primary);
        $run('git checkout main', $primary);
    };
    $make_branch('unmanaged-missing');
    $make_branch('unmanaged-partial');
    $make_branch('unmanaged-dirty');
    $make_branch('unmanaged-invalid');
    $make_branch('already-current');
    $make_branch('dirty-active');
    $make_branch('head-merged');
    $make_branch('pr-merged');
    $make_branch('pr-closed');
    $make_branch('upstream-gone');
    $make_branch('dirty-merged');
    $make_branch('unpushed-merged');
    $make_branch('external-branch');
    $run('git checkout -b equivalent-clean', $primary);
    $run('git push -u origin equivalent-clean', $primary);
    $run('git checkout main', $primary);

    $run(sprintf('git worktree add %s unmanaged-missing', escapeshellarg($tmp . '/demo@unmanaged-missing')), $primary);
    $run(sprintf('git worktree add %s unmanaged-partial', escapeshellarg($tmp . '/demo@unmanaged-partial')), $primary);
    $run(sprintf('git worktree add %s unmanaged-dirty', escapeshellarg($tmp . '/demo@unmanaged-dirty')), $primary);
    $run(sprintf('git worktree add %s unmanaged-invalid', escapeshellarg($tmp . '/demo@unmanaged-invalid')), $primary);
    $run(sprintf('git worktree add %s already-current', escapeshellarg($tmp . '/demo@already-current')), $primary);
    $run(sprintf('git worktree add %s dirty-active', escapeshellarg($tmp . '/demo@dirty-active')), $primary);
    $run(sprintf('git worktree add %s head-merged', escapeshellarg($tmp . '/demo@head-merged')), $primary);
    $run(sprintf('git worktree add %s pr-merged', escapeshellarg($tmp . '/demo@pr-merged')), $primary);
    $run(sprintf('git worktree add %s pr-closed', escapeshellarg($tmp . '/demo@pr-closed')), $primary);
    $run(sprintf('git worktree add %s upstream-gone', escapeshellarg($tmp . '/demo@upstream-gone')), $primary);
    $run(sprintf('git worktree add %s dirty-merged', escapeshellarg($tmp . '/demo@dirty-merged')), $primary);
    $run(sprintf('git worktree add %s unpushed-merged', escapeshellarg($tmp . '/demo@unpushed-merged')), $primary);
    $run(sprintf('git worktree add %s equivalent-clean', escapeshellarg($tmp . '/demo@equivalent-clean')), $primary);
    mkdir($tmp . '-external', 0755, true);
    $run(sprintf('git worktree add %s external-branch', escapeshellarg($tmp . '-external/demo-external')), $primary);
    file_put_contents($tmp . '/demo@unmanaged-dirty/scratch.txt', 'dirty');
    file_put_contents($tmp . '/demo@dirty-active/scratch.txt', 'dirty');
    file_put_contents($tmp . '/demo@dirty-merged/scratch.txt', 'dirty');
    file_put_contents($tmp . '/demo@unpushed-merged/local.txt', 'local');
    $run('git add local.txt && git commit -m local-unpushed', $tmp . '/demo@unpushed-merged');
    file_put_contents($tmp . '/demo@equivalent-clean/equivalent.txt', 'equivalent');
    $run('git add equivalent.txt && git commit -m equivalent-local', $tmp . '/demo@equivalent-clean');
    file_put_contents($primary . '/equivalent.txt', 'equivalent');
    $run('git add equivalent.txt && git commit -m equivalent-local && git push origin main', $primary);
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/upstream-gone', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/dirty-merged', escapeshellarg($remote)));

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
        'demo@dirty-active',
        array(
        'handle'          => 'demo@dirty-active',
        'repo'            => 'demo',
        'branch'          => 'dirty-active',
        'path'            => $tmp . '/demo@dirty-active',
        'created_at'      => '2026-04-01T00:00:00+00:00',
        'observed_at'     => '2026-04-01T00:00:00+00:00',
        'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
        )
    );
    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@head-merged',
        array(
        'handle'          => 'demo@head-merged',
        'repo'            => 'demo',
        'branch'          => 'head-merged',
        'path'            => $tmp . '/demo@head-merged',
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
    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@equivalent-clean',
        array(
        'handle'          => 'demo@equivalent-clean',
        'repo'            => 'demo',
        'branch'          => 'equivalent-clean',
        'path'            => $tmp . '/demo@equivalent-clean',
        'created_at'      => '2026-04-01T00:00:00+00:00',
        'observed_at'     => '2026-04-01T00:00:00+00:00',
        'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
        )
    );
    $ws = new \DataMachineCode\Workspace\Workspace();
    $lookup_reflection = new \ReflectionMethod($ws, 'find_closed_pr_for_branch');
    $lookup_cache = array( 'acme/demo' => array() );
    $old_pr       = $lookup_reflection->invokeArgs($ws, array( 'acme/demo', 'old-merged-branch', &$lookup_cache ));
    $open_pr      = $lookup_reflection->invokeArgs($ws, array( 'acme/demo', 'open-branch', &$lookup_cache ));
    $assert(103, (int) ( $old_pr['number'] ?? 0 ), 'direct branch PR lookup finds older merged PRs outside the bounded repo snapshot');
    $assert(null, $open_pr, 'direct branch PR lookup does not finalize open PR branches');

    $run('git remote set-url origin https://github.com/acme/demo.git', $primary);
    $active_report = $ws->worktree_active_no_signal_report(array( 'limit' => 50, 'offset' => 0 ));
    $run(sprintf('git remote set-url origin %s', escapeshellarg($remote)), $primary);
    $assert(true, ! is_wp_error($active_report) && ( $active_report['success'] ?? false ), 'active/no-signal report succeeds');
    $assert(true, (bool) ( $active_report['review_only'] ?? false ), 'active/no-signal report is review-only');
    $assert(true, (int) ( $active_report['summary']['inspected'] ?? 0 ) > 0, 'active/no-signal report inspects rows');
    $active_rows = array();
    foreach ( (array) ( $active_report['rows'] ?? array() ) as $row ) {
        $active_rows[ $row['handle'] ?? '' ] = $row;
    }
    $assert('finalized_pr_reconcile', $active_rows['demo@head-merged']['suggested_action'] ?? '', 'active/no-signal report finds merged PRs by branch head');
    $assert('active_open_pr', $active_rows['demo@already-current']['suggested_action'] ?? '', 'active/no-signal report preserves open PRs as active');
    $assert(106, (int) ( $active_rows['demo@head-merged']['pr']['number'] ?? 0 ), 'active/no-signal report includes PR evidence');
    $assert('unsafe_dirty_or_unpushed', $active_rows['demo@dirty-active']['suggested_action'] ?? '', 'active/no-signal report keeps dirty rows unsafe');
    $assert(true, is_array($active_rows['demo@dirty-active']['upstream_equivalence'] ?? null), 'active/no-signal report includes upstream equivalence diagnostics for dirty rows');
    $assert(true, (int) ( $active_rows['demo@dirty-active']['upstream_equivalence']['dirty_paths']['absent_on_default'] ?? 0 ) >= 1, 'dirty diagnostics classify paths absent on default');
    $assert(true, isset($active_rows['demo@dirty-active']['upstream_equivalence']['effective_status']), 'dirty diagnostics include effective status');
    $assert(250, (int) ( $active_rows['demo@dirty-active']['upstream_equivalence']['path_inspection_limit'] ?? 0 ), 'dirty diagnostics expose path inspection cap');
    $assert(true, isset($active_rows['demo@dirty-active']['upstream_equivalence']['dirty_paths']['generated_or_artifact']), 'dirty diagnostics count generated/artifact paths');
    $assert('equivalent_clean', $active_rows['demo@equivalent-clean']['upstream_equivalence']['effective_status'] ?? '', 'dirty diagnostics classify patch-equivalent clean rows');
    $assert(true, isset($active_rows['demo@dirty-active']['elapsed_ms']), 'active/no-signal rows include elapsed timing');
    $assert(true, isset($active_report['summary']['slow_rows'][0]['elapsed_ms']), 'active/no-signal summary includes slow row timing samples');
    $assert(true, isset($active_rows['demo@dirty-active']['probe_timings_ms']['dirty_count']), 'active/no-signal rows include dirty probe timing');
    $assert(true, isset($active_rows['demo@dirty-active']['probe_timings_ms']['upstream_equivalence']), 'active/no-signal rows include upstream equivalence probe timing');
    $assert('dirty_or_unpushed_rows_are_always_manual_review', $active_rows['demo@dirty-active']['pr_lookup_skipped'] ?? '', 'dirty active/no-signal rows skip GitHub PR lookup');
    $assert(false, isset($active_rows['demo@dirty-active']['probe_timings_ms']['github_pr_lookup']), 'dirty active/no-signal rows do not spend time on GitHub PR lookup');
    $assert(true, isset($active_rows['demo@dirty-active']['upstream_equivalence']['probe_timings_ms']['git_cherry']), 'upstream equivalence includes git cherry probe timing');
    $assert(true, isset($active_rows['demo@dirty-active']['upstream_equivalence']['probe_timings_ms']['dirty_path_classification']), 'upstream equivalence includes dirty path classification timing');
    $assert(true, (int) ( $active_rows['demo@dirty-active']['upstream_equivalence']['dirty_paths']['inspected'] ?? 0 ) >= 1, 'batched dirty path classification preserves inspected path count');
    $budgeted_active_report = $ws->worktree_active_no_signal_report(array( 'limit' => 20, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 1, 'internal_budget_started' => microtime(true) - 1 ));
    $assert(true, ! is_wp_error($budgeted_active_report) && ( $budgeted_active_report['success'] ?? false ), 'budgeted active/no-signal report succeeds');
    $assert(true, (bool) ( $budgeted_active_report['pagination']['partial'] ?? false ), 'budgeted active/no-signal report returns partial pagination');
    $assert(true, isset($budgeted_active_report['evidence']['budget']['budget_exhausted']), 'budgeted active/no-signal report exposes budget evidence');
    $run('git remote set-url origin https://github.com/acme/demo.git', $primary);
    $finalized_dry_run = $ws->worktree_active_no_signal_finalized_apply(array( 'dry_run' => true, 'limit' => 50, 'offset' => 0 ));
    $run(sprintf('git remote set-url origin %s', escapeshellarg($remote)), $primary);
    $assert(true, ! is_wp_error($finalized_dry_run) && ( $finalized_dry_run['success'] ?? false ), 'finalized active/no-signal dry-run succeeds');
    $assert(true, (bool) ( $finalized_dry_run['dry_run'] ?? false ), 'finalized active/no-signal dry-run does not write');
    $assert(1, (int) ( $finalized_dry_run['summary']['planned'] ?? 0 ), 'finalized active/no-signal dry-run plans merged PR rows only');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@head-merged')['cleanup_eligible_at'] ?? '', 'finalized active/no-signal dry-run leaves metadata unchanged');

    echo "\nDry-run reconciliation\n";
    $plan = $ws->worktree_reconcile_metadata(array( 'dry_run' => true ));
    $assert(true, ! is_wp_error($plan) && ( $plan['success'] ?? false ), 'dry-run succeeds');
    $assert(true, $plan['dry_run'] ?? false, 'dry-run flag is true');
    $assert(7, (int) ( $plan['summary']['proposed'] ?? 0 ), 'dry-run proposes unmanaged rows and safe merged lifecycle finalizers');
    $assert(0, (int) ( $plan['summary']['written'] ?? 0 ), 'dry-run writes nothing');
    $assert(1, (int) ( $plan['summary']['skipped_by_reason']['external_worktree'] ?? 0 ), 'dry-run distinguishes external worktrees');
    $assert(3, (int) ( $plan['summary']['skipped_by_reason']['unsafe_cleanup_eligible_state'] ?? 0 ), 'dry-run keeps dirty and unpushed merged worktrees out of auto-finalize proposals');
    $assert(1, count($plan['external_worktrees'] ?? array()), 'dry-run exposes external worktree bucket');

    $by_handle = array();
    foreach ( $plan['proposals'] as $row ) {
        $by_handle[ $row['handle'] ] = $row;
    }
    $assert('operator_plan', $by_handle['demo@unmanaged-missing']['source_map']['lifecycle_state'] ?? '', 'missing metadata lifecycle source is operator_plan');
    $assert('filesystem', $by_handle['demo@unmanaged-missing']['source_map']['created_at'] ?? '', 'missing metadata created_at source is filesystem');
    $assert('reconcile_run', $by_handle['demo@unmanaged-missing']['source_map']['observed_at'] ?? '', 'missing metadata observed_at source is reconcile run');
    $assert('current_site', $by_handle['demo@unmanaged-missing']['source_map']['origin_site'] ?? '', 'missing metadata origin site is inferred from current site');
    $assert(true, isset($by_handle['demo@unmanaged-missing']['elapsed_ms']), 'metadata reconciliation proposal rows include elapsed timing');
    $assert(true, isset($plan['summary']['slow_rows'][0]['elapsed_ms']), 'metadata reconciliation summary includes slow row timing samples');
    $assert('metadata', $by_handle['demo@unmanaged-partial']['source_map']['created_at'] ?? '', 'partial metadata preserves created_at source');
    $assert('git', $by_handle['demo@unmanaged-partial']['source_map']['branch'] ?? '', 'branch source is git');
    $assert(array( 'lifecycle_state' ), $by_handle['demo@unmanaged-invalid']['invalid_fields'] ?? array(), 'invalid lifecycle state is planned for repair');
    $assert(\DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $by_handle['demo@unmanaged-invalid']['proposed_metadata']['lifecycle_state'] ?? '', 'invalid lifecycle state becomes conservative active proposal');
    $assert(1, (int) ( $by_handle['demo@unmanaged-dirty']['dirty'] ?? 0 ), 'dirty row is visible but not cleanup-eligible');
    $assert(\DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE, $by_handle['demo@unmanaged-dirty']['proposed_metadata']['lifecycle_state'] ?? '', 'dirty row proposal stays active');
    $assert('auto_finalize_merged', $by_handle['demo@pr-merged']['reason_code'] ?? '', 'merged stored PR is proposed for auto-finalization');
    $assert('pr-merged', $by_handle['demo@pr-merged']['signal'] ?? '', 'stored PR proposal records pr-merged signal');
    $assert('cleanup_eligible', $by_handle['demo@pr-merged']['proposed_metadata']['lifecycle_state'] ?? '', 'stored PR proposal becomes cleanup_eligible metadata');
    $assert('merged', $by_handle['demo@pr-merged']['proposed_metadata']['finalized_state'] ?? '', 'stored PR proposal preserves merged finalized state');
    $assert('auto_finalize_merged', $by_handle['demo@pr-closed']['reason_code'] ?? '', 'closed stored PR is proposed for auto-finalization');
    $assert('pr-closed', $by_handle['demo@pr-closed']['signal'] ?? '', 'closed stored PR proposal records pr-closed signal');
    $assert('closed', $by_handle['demo@pr-closed']['proposed_metadata']['finalized_state'] ?? '', 'closed stored PR preserves closed finalized state');
    $assert('pr-closed', $by_handle['demo@pr-closed']['proposed_metadata']['cleanup_eligibility_evidence']['signal'] ?? '', 'closed stored PR records cleanup eligibility evidence');
    $assert('auto_finalize_merged', $by_handle['demo@upstream-gone']['reason_code'] ?? '', 'upstream-gone branch is proposed for auto-finalization');
    $assert('upstream-gone', $by_handle['demo@upstream-gone']['signal'] ?? '', 'upstream-gone proposal records local merge signal');
    $unsafe_by_handle = array();
    foreach ( $plan['skipped'] as $row ) {
        $unsafe_by_handle[ $row['handle'] ?? '' ] = $row;
    }
    $assert('unsafe_cleanup_eligible_state', $unsafe_by_handle['demo@dirty-merged']['reason_code'] ?? '', 'dirty merged worktree is not auto-finalized');
    $assert('unsafe_cleanup_eligible_state', $unsafe_by_handle['demo@unpushed-merged']['reason_code'] ?? '', 'unpushed merged worktree is not auto-finalized');
    $stale_plan  = $plan;
    $unsafe_plan = $plan;

    $page = $ws->worktree_reconcile_metadata(array( 'dry_run' => true, 'limit' => 2, 'offset' => 2 ));
    $assert(true, ! is_wp_error($page) && ( $page['success'] ?? false ), 'paginated dry-run succeeds');
    $assert(2, (int) ( $page['pagination']['limit'] ?? 0 ), 'paginated dry-run reports limit');
    $assert(2, (int) ( $page['pagination']['offset'] ?? 0 ), 'paginated dry-run reports offset');
    $assert(2, (int) ( $page['pagination']['scanned'] ?? 0 ), 'paginated dry-run scans only requested page');
    $assert(true, (bool) ( $page['pagination']['partial'] ?? false ), 'paginated dry-run reports continuation');
    $assert(4, (int) ( $page['pagination']['next_offset'] ?? 0 ), 'paginated dry-run advances next offset');
    $assert(2, (int) ( $page['summary']['inspected'] ?? 0 ), 'paginated dry-run summary is page-scoped');
    $assert(true, isset($page['evidence']['fields_skipped_by_listing']), 'paginated dry-run exposes listing evidence');
    $budgeted_page = $ws->worktree_reconcile_metadata(array( 'dry_run' => true, 'limit' => 20, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 1, 'internal_budget_started' => microtime(true) - 1 ));
    $assert(true, ! is_wp_error($budgeted_page) && ( $budgeted_page['success'] ?? false ), 'budgeted metadata reconciliation dry-run succeeds');
    $assert(true, (bool) ( $budgeted_page['pagination']['partial'] ?? false ), 'budgeted metadata reconciliation dry-run returns partial pagination');
    $assert(true, isset($budgeted_page['evidence']['budget']['budget_exhausted']), 'budgeted metadata reconciliation dry-run exposes budget evidence');

    \DataMachine\Engine\Tasks\TaskScheduler::$batches = array();
    $job_backed = $ws->worktree_reconcile_metadata(array( 'apply' => true, 'via_jobs' => true, 'limit' => 3 ));
    $assert(true, ! is_wp_error($job_backed) && ( $job_backed['success'] ?? false ), 'job-backed reconciliation scheduling succeeds');
    $assert(true, (bool) ( $job_backed['job_backed'] ?? false ), 'job-backed reconciliation reports job_backed');
    $assert(false, (bool) ( $job_backed['applied'] ?? true ), 'job-backed parent does not write metadata synchronously');
    $assert(true, (int) ( $job_backed['summary']['scheduled_jobs'] ?? 0 ) >= 4, 'job-backed reconciliation schedules bounded page jobs');
    $scheduled_batch = \DataMachine\Engine\Tasks\TaskScheduler::$batches[0] ?? array();
    $assert('worktree_cleanup_chunk', $scheduled_batch['task_type'] ?? '', 'job-backed reconciliation uses cleanup chunk task');
    $assert('metadata_reconciliation_page', $scheduled_batch['items'][0]['chunk_type'] ?? '', 'scheduled reconciliation job uses metadata page chunk type');
    $assert(array( 0, 3, 6, 9 ), array_slice(array_column((array) ( $scheduled_batch['items'] ?? array() ), 'offset'), 0, 4), 'scheduled reconciliation jobs carry bounded offsets');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@unmanaged-missing')['handle'] ?? '', 'job-backed parent leaves metadata untouched until jobs run');
    $bad_job_backed = $ws->worktree_reconcile_metadata(array( 'dry_run' => true, 'via_jobs' => true, 'limit' => 3 ));
    $assert(true, is_wp_error($bad_job_backed), 'job-backed reconciliation rejects dry-run mode');

    $inventory_before = $ws->worktree_cleanup_merged(array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ));
    $assert(4, (int) ( $inventory_before['summary']['skipped_by_reason']['needs_metadata_reconcile'] ?? 0 ), 'inventory cleanup sees missing metadata before apply');

    echo "\nApply reviewed plan\n";
    $apply = $ws->worktree_reconcile_metadata(array( 'apply_plan' => $plan ));
    $assert(true, ! is_wp_error($apply) && ( $apply['success'] ?? false ), 'apply succeeds');
    $assert(7, (int) ( $apply['summary']['written'] ?? 0 ), 'apply writes exact current matches');
    $assert(7, (int) ( $apply['summary']['written'] ?? 0 ), 'apply reports written metadata rows');
    $assert(0, (int) ( $apply['summary']['skipped'] ?? 0 ), 'apply skips nothing for current plan');
    $assert(7, count($apply['written'] ?? array()), 'apply exposes written rows distinctly');
    $stored = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@unmanaged-missing');
    $assert('demo@unmanaged-missing', $stored['handle'] ?? '', 'stored metadata includes handle');
    $assert(true, ! empty($stored['observed_at']), 'stored metadata includes observed_at');
    $assert('Origin Test', $stored['origin_site'] ?? '', 'stored metadata includes origin site when available');
    $assert('operator_plan', $stored['reconciled_sources']['lifecycle_state'] ?? '', 'stored metadata keeps source map');
    $stored_pr = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@pr-merged');
    $assert('cleanup_eligible', $stored_pr['lifecycle_state'] ?? '', 'apply stores cleanup_eligible for merged PR worktree');
    $assert('merged', $stored_pr['finalized_state'] ?? '', 'apply stores merged finalizer state for merged PR worktree');
    $stored_gone = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@upstream-gone');
    $assert('cleanup_eligible', $stored_gone['lifecycle_state'] ?? '', 'apply stores cleanup_eligible for upstream-gone worktree');
    $assert('upstream-gone', $stored_gone['cleanup_eligibility_evidence']['signal'] ?? '', 'apply stores upstream-gone cleanup eligibility evidence');
    $stored_closed = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@pr-closed');
    $assert('cleanup_eligible', $stored_closed['lifecycle_state'] ?? '', 'apply stores cleanup_eligible for closed PR worktree');
    $assert('closed', $stored_closed['cleanup_eligibility_evidence']['finalized_state'] ?? '', 'apply stores closed PR cleanup eligibility evidence');

    $all_metadata = get_option(\DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, array());
    $all_metadata['demo@unmanaged-missing']['durability_marker'] = 'option-repair-wins';
    update_option(\DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, $all_metadata, false);
    $durable = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@unmanaged-missing');
    $assert('option-repair-wins', $durable['durability_marker'] ?? '', 'option-backed metadata repair remains visible over DB fallback');

    $auto_apply = $ws->worktree_reconcile_metadata(array( 'apply' => true ));
    $assert(true, ! is_wp_error($auto_apply) && ( $auto_apply['success'] ?? false ), 'DMC-owned reconciliation apply path runs without a manual plan');
    $bounded_auto_apply = $ws->worktree_reconcile_metadata(array( 'apply' => true, 'limit' => 2, 'offset' => 2 ));
    $assert(true, ! is_wp_error($bounded_auto_apply) && ( $bounded_auto_apply['success'] ?? false ), 'bounded direct reconciliation apply runs without a manual plan file');
    $assert(true, (bool) ( $bounded_auto_apply['direct_apply'] ?? false ), 'bounded direct apply identifies direct apply source');
    $assert(false, (bool) ( $bounded_auto_apply['dry_run'] ?? true ), 'bounded direct apply is not a dry-run');
    $assert(2, (int) ( $bounded_auto_apply['summary']['inspected'] ?? 0 ), 'bounded direct apply summary stays page-scoped');
    $assert(2, (int) ( $bounded_auto_apply['pagination']['limit'] ?? 0 ), 'bounded direct apply preserves pagination limit');
    $assert(2, (int) ( $bounded_auto_apply['pagination']['offset'] ?? 0 ), 'bounded direct apply preserves pagination offset');
    $assert('direct_apply', $bounded_auto_apply['evidence']['apply_source'] ?? '', 'bounded direct apply exposes evidence source');

    $inventory_after = $ws->worktree_cleanup_merged(array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ));
    $assert(1, (int) ( $inventory_after['summary']['skipped_by_reason']['needs_metadata_reconcile'] ?? 0 ), 'inventory cleanup requires fewer metadata reconciliation passes after apply');
    $assert(8, (int) ( $inventory_after['summary']['skipped_by_reason']['active_no_signal'] ?? 0 ), 'inventory cleanup treats reconciled active metadata like current active metadata');
    $assert(false, isset($inventory_after['summary']['repair_status']), 'inventory cleanup no longer exposes migration status');

    $run('git remote set-url origin https://github.com/acme/demo.git', $primary);
    $finalized_apply = $ws->worktree_active_no_signal_finalized_apply(array( 'limit' => 50, 'offset' => 0 ));
    $run(sprintf('git remote set-url origin %s', escapeshellarg($remote)), $primary);
    $assert(true, ! is_wp_error($finalized_apply) && ( $finalized_apply['success'] ?? false ), 'finalized active/no-signal apply succeeds');
    $assert(1, (int) ( $finalized_apply['summary']['written'] ?? 0 ), 'finalized active/no-signal apply writes merged PR metadata');
    $stored_head_merged = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@head-merged');
    $assert('cleanup_eligible', $stored_head_merged['lifecycle_state'] ?? '', 'finalized active/no-signal apply stores cleanup_eligible state');
    $assert('pr-merged', $stored_head_merged['cleanup_eligibility_evidence']['signal'] ?? '', 'finalized active/no-signal apply stores PR merge evidence');

    $equivalent_clean_dry_run = $ws->worktree_active_no_signal_equivalent_clean_apply(array( 'dry_run' => true, 'limit' => 50, 'offset' => 0 ));
    $assert(true, ! is_wp_error($equivalent_clean_dry_run) && ( $equivalent_clean_dry_run['success'] ?? false ), 'equivalent-clean active/no-signal dry-run succeeds');
    $assert(1, (int) ( $equivalent_clean_dry_run['summary']['planned'] ?? 0 ), 'equivalent-clean dry-run plans only equivalent clean rows');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@equivalent-clean')['cleanup_eligible_at'] ?? '', 'equivalent-clean dry-run leaves metadata unchanged');
    $equivalent_clean_apply = $ws->worktree_active_no_signal_equivalent_clean_apply(array( 'limit' => 50, 'offset' => 0 ));
    $assert(true, ! is_wp_error($equivalent_clean_apply) && ( $equivalent_clean_apply['success'] ?? false ), 'equivalent-clean active/no-signal apply succeeds');
    $assert(1, (int) ( $equivalent_clean_apply['summary']['written'] ?? 0 ), 'equivalent-clean active/no-signal apply writes metadata');
    $stored_equivalent_clean = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@equivalent-clean');
    $assert('cleanup_eligible', $stored_equivalent_clean['lifecycle_state'] ?? '', 'equivalent-clean apply stores cleanup_eligible state');
    $assert('upstream-equivalent-clean', $stored_equivalent_clean['cleanup_eligibility_evidence']['signal'] ?? '', 'equivalent-clean apply stores upstream equivalence evidence');

    echo "\nSafety gates\n";
    $stale_plan['proposals'][0]['branch'] = 'wrong-branch';
    $stale_apply = $ws->worktree_reconcile_metadata(array( 'apply_plan' => $stale_plan ));
    $assert('plan_identity_mismatch', $stale_apply['skipped'][0]['reason_code'] ?? '', 'apply revalidates branch identity before writing');

    foreach ( $unsafe_plan['proposals'] as &$row ) {
        if ('demo@unmanaged-dirty' === $row['handle'] ) {
            $row['proposed_metadata']['lifecycle_state'] = \DataMachineCode\Workspace\WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE;
            $row['source_map']['lifecycle_state']        = 'operator_plan';
        }
    }
    unset($row);
    $unsafe_apply = $ws->worktree_reconcile_metadata(array( 'apply_plan' => $unsafe_plan ));
    $unsafe_skips = array_values(array_filter($unsafe_apply['skipped'] ?? array(), fn( $row ) => 'demo@unmanaged-dirty' === ( $row['handle'] ?? '' )));
    $assert('unsafe_cleanup_eligible_state', $unsafe_skips[0]['reason_code'] ?? '', 'dirty worktree cannot become cleanup_eligible through reconciliation plan');
    $assert(1, count($unsafe_apply['still_unsafe'] ?? array()), 'apply exposes still-unsafe rows distinctly');

    \DataMachineCode\Workspace\WorktreeContextInjector::forget_metadata('demo@unmanaged-missing');
    $budget_offset = 0;
    $budget_listing = $ws->worktree_list(null, null, array( 'include_status' => false, 'include_disk' => false ));
    foreach ( array_values(array_filter((array) ( $budget_listing['worktrees'] ?? array() ), fn( $wt ) => empty($wt['is_primary']))) as $index => $wt ) {
        if ('demo@unmanaged-missing' === ( $wt['handle'] ?? '' ) ) {
            $budget_offset = (int) $index;
            break;
        }
    }
    $budgeted_apply = $ws->worktree_reconcile_metadata(array( 'apply' => true, 'limit' => 1, 'offset' => $budget_offset, 'until_budget' => '1s' ));
    $assert(true, ! is_wp_error($budgeted_apply) && ( $budgeted_apply['success'] ?? false ), 'time-budgeted direct apply succeeds');
    $assert(true, (bool) ( $budgeted_apply['direct_apply'] ?? false ), 'time-budgeted drain uses direct apply path');
    $assert(1, (int) ( $budgeted_apply['pagination']['limit'] ?? 0 ), 'time-budgeted drain preserves page limit');
    $assert(1, (int) ( $budgeted_apply['pagination']['scanned'] ?? 0 ), 'time-budgeted drain scans one page before honoring reserve');
    $assert(true, (bool) ( $budgeted_apply['pagination']['partial'] ?? false ), 'time-budgeted drain reports continuation');
    $assert($budget_offset + 1, (int) ( $budgeted_apply['pagination']['next_offset'] ?? 0 ), 'time-budgeted drain reports next offset');
    $assert(1, (int) ( $budgeted_apply['summary']['written'] ?? 0 ), 'time-budgeted drain writes first page metadata');
    $assert('time-budgeted metadata reconciliation direct apply', $budgeted_apply['evidence']['scope'] ?? '', 'time-budgeted drain exposes evidence scope');
    $assert(true, str_contains($budgeted_apply['pagination']['next_command'] ?? '', '--until-budget=1s'), 'time-budgeted drain exposes next command');

    $task = new \DataMachineCode\Tasks\WorktreeCleanupChunkTask();
    $task->executeTask(1201, array( 'chunk_type' => 'metadata_reconciliation_page', 'limit' => 2, 'offset' => 0 ));
    $job_result = $GLOBALS['datamachine_code_reconcile_chunk_jobs'][1201] ?? array();
    $assert(true, (bool) ( $job_result['success'] ?? false ), 'metadata reconciliation page job completes successfully');
    $assert('metadata_reconciliation_page', $job_result['chunk_type'] ?? '', 'metadata reconciliation page job records chunk type');
    $assert(true, isset($job_result['evidence']['summary']), 'metadata reconciliation page job records summary evidence');

    if ($failures > 0 ) {
        echo "\n{$failures} / {$total} assertions failed.\n";
        exit(1);
    }

    echo "\nAll {$total} worktree metadata reconciliation assertions passed.\n";
}
