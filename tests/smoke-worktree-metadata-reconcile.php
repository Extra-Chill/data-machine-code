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
    $make_branch('unmanaged-empty');
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
    $run(sprintf('git worktree add %s unmanaged-empty', escapeshellarg($tmp . '/demo@unmanaged-empty')), $primary);
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
        'demo@unmanaged-empty',
        array()
    );
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
    $default_ref_cache_reflection = new \ReflectionMethod($ws, 'cached_active_no_signal_default_ref_probe');
    $default_ref_probe_cache      = array(
        'default_ref' => array(),
        'stats'       => array(
            'default_ref' => array(
                'hits'   => 0,
                'misses' => 0,
            ),
        ),
    );
    $first_default_ref            = $default_ref_cache_reflection->invokeArgs($ws, array( $primary, &$default_ref_probe_cache ));
    $second_default_ref           = $default_ref_cache_reflection->invokeArgs($ws, array( $primary, &$default_ref_probe_cache ));
    $assert($first_default_ref, $second_default_ref, 'active/no-signal default ref cache returns stable cached values');
    $assert(1, (int) ( $default_ref_probe_cache['stats']['default_ref']['hits'] ?? 0 ), 'active/no-signal default ref cache records one hit after reuse');
    $assert(1, (int) ( $default_ref_probe_cache['stats']['default_ref']['misses'] ?? 0 ), 'active/no-signal default ref cache records one miss before reuse');
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
    $assert(true, (int) ( $active_report['evidence']['probe_cache']['default_ref']['misses'] ?? 0 ) > 0, 'active/no-signal report records default ref cache misses');
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

	class Inconsistent_Identity_Metadata_Workspace extends \DataMachineCode\Workspace\Workspace
	{
		private string $tmp;

        public function __construct( string $tmp )
        {
            $this->tmp = $tmp;
            parent::__construct();
        }

        public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return array(
            'success'   => true,
				'worktrees' => array(
				array(
				'handle'   => 'demo@unmanaged-missing',
				'repo'     => 'demo',
				'branch'   => 'unmanaged-missing',
				'path'     => $this->tmp . '/demo@unmanaged-missing',
				'metadata' => array(
				'handle'          => 'demo@unmanaged-missing',
				'repo'            => 'demo',
				'branch'          => 'old-unmanaged-missing',
				'path'            => $this->tmp . '/demo@unmanaged-missing-old',
				'created_at'      => '2026-04-01T00:00:00+00:00',
				'observed_at'     => '2026-04-01T00:00:00+00:00',
				'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
				),
				),
				array(
				'handle'   => 'demo@unmanaged-partial',
				'repo'     => 'demo',
				'branch'   => 'renamed/current',
				'path'     => $this->tmp . '/demo@unmanaged-partial',
				'metadata' => array(
				'handle'          => 'demo@unmanaged-partial',
				'repo'            => 'demo',
				'branch'          => 'unmanaged-partial',
				'path'            => $this->tmp . '/demo@unmanaged-partial',
				'created_at'      => '2026-04-01T00:00:00+00:00',
				'observed_at'     => '2026-04-01T00:00:00+00:00',
				'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
				),
				),
				array(
				'handle'   => 'demo@unmanaged-empty',
				'repo'     => 'demo',
				'branch'   => 'main',
				'path'     => $this->tmp . '/demo@unmanaged-empty',
				'metadata' => array(
				'handle'          => 'demo@unmanaged-empty',
				'repo'            => 'demo',
				'branch'          => 'unmanaged-empty',
				'path'            => $this->tmp . '/demo@unmanaged-empty',
				'created_at'      => '2026-04-01T00:00:00+00:00',
				'observed_at'     => '2026-04-01T00:00:00+00:00',
				'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
				),
				),
				array(
				'handle'   => 'demo@feature-foo',
				'repo'     => 'demo',
				'branch'   => '',
            'path'     => $this->tmp . '/demo@unmanaged-missing',
            'metadata' => array(
            'handle'          => 'demo@feature-foo',
            'repo'            => 'demo',
            'branch'          => 'feature/bar',
            'path'            => $this->tmp . '/demo@unmanaged-missing',
            'created_at'      => '2026-04-01T00:00:00+00:00',
            'observed_at'     => '2026-04-01T00:00:00+00:00',
            'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
            ),
            ),
            ),
            );
        }
	}

	$identity_plan = ( new Inconsistent_Identity_Metadata_Workspace($tmp) )->worktree_reconcile_metadata(array( 'dry_run' => true ));
	$identity_proposals = array();
	foreach ( (array) ( $identity_plan['proposals'] ?? array() ) as $row ) {
		$identity_proposals[ $row['handle'] ?? '' ] = $row;
	}
	$identity_skips = array();
	foreach ( (array) ( $identity_plan['skipped'] ?? array() ) as $row ) {
		$identity_skips[ $row['handle'] ?? '' ] = $row;
	}
	$stale_identity = $identity_proposals['demo@unmanaged-missing'] ?? array();
	$assert('stale_identity_metadata', $stale_identity['reason_code'] ?? '', 'metadata reconciliation proposes safe stale identity metadata repair');
	$assert('current_git_branch', $stale_identity['proposed_source_of_truth']['branch'] ?? '', 'stale identity proposal names git branch as branch source of truth');
	$assert('studio wp datamachine-code workspace worktree reconcile-metadata --apply --format=json', $stale_identity['next_command'] ?? '', 'stale identity proposal includes apply next command');
	$assert(0, (int) ( $stale_identity['dirty'] ?? -1 ), 'stale identity repair requires clean dirty safety gate');
	$assert(0, (int) ( $stale_identity['unpushed'] ?? -1 ), 'stale identity repair requires clean unpushed safety gate');
	$branch_renamed = $identity_skips['demo@unmanaged-partial'] ?? array();
	$assert('branch_renamed_worktree', $branch_renamed['reason_code'] ?? '', 'metadata reconciliation classifies branch-renamed worktrees');
	$assert('current_git_branch', $branch_renamed['proposed_source_of_truth']['branch'] ?? '', 'branch-renamed row names current git branch as proposed source of truth');
	$assert(true, str_contains($branch_renamed['next_command'] ?? '', 'workspace worktree add'), 'branch-renamed row includes a replacement worktree command');
	$default_checkout = $identity_skips['demo@unmanaged-empty'] ?? array();
	$assert('default_branch_checkout_in_feature_worktree', $default_checkout['reason_code'] ?? '', 'metadata reconciliation classifies default-branch checkout in feature worktree');
	$assert('operator_review_required', $default_checkout['proposed_source_of_truth']['branch'] ?? '', 'default checkout leaves branch source of truth to operator review');
	$assert(true, str_contains($default_checkout['next_command'] ?? '', 'switch <intended-feature-branch>'), 'default checkout includes branch switch next command');
	$manual_identity = $identity_skips['demo@feature-foo'] ?? array();
	$assert('manual_review_identity_metadata', $manual_identity['reason_code'] ?? '', 'metadata reconciliation leaves ambiguous identity conflicts for manual review');
	$assert(true, isset($manual_identity['identity_conflicts']['branch']), 'manual identity skip includes branch mismatch diagnostics');
	$active_report_page = $ws->worktree_active_no_signal_report(array( 'limit' => 1, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 60, 'internal_budget_started' => microtime(true) ));
    $assert(true, ! is_wp_error($active_report_page) && ( $active_report_page['success'] ?? false ), 'paginated active/no-signal report succeeds');
    $assert(true, str_contains($active_report_page['pagination']['next_command'] ?? '', 'active-no-signal-report --limit=1 --offset=1 --until-budget=1s --format=json'), 'active/no-signal report continuation preserves report operation');
    $budgeted_active_report = $ws->worktree_active_no_signal_report(array( 'limit' => 20, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 1, 'internal_budget_started' => microtime(true) - 1 ));
    $assert(true, ! is_wp_error($budgeted_active_report) && ( $budgeted_active_report['success'] ?? false ), 'budgeted active/no-signal report succeeds');
    $assert(true, (bool) ( $budgeted_active_report['pagination']['partial'] ?? false ), 'budgeted active/no-signal report returns partial pagination');
    $assert(true, isset($budgeted_active_report['evidence']['budget']['budget_exhausted']), 'budgeted active/no-signal report exposes budget evidence');
    $bad_active_zero = $ws->worktree_active_no_signal_report(array( 'limit' => 0, 'offset' => 0 ));
    $assert(true, is_wp_error($bad_active_zero), 'active/no-signal report rejects limit=0');
    $assert('invalid_active_no_signal_limit', $bad_active_zero->code ?? '', 'active/no-signal limit=0 rejection uses explicit error code');
    $bad_active_negative = $ws->worktree_active_no_signal_report(array( 'limit' => -1, 'offset' => 0 ));
    $assert(true, is_wp_error($bad_active_negative), 'active/no-signal report rejects negative limits');
    $bad_active_budget = $ws->worktree_active_no_signal_report(array( 'limit' => 0, 'until_budget' => '1s' ));
    $assert(true, is_wp_error($bad_active_budget), 'budgeted active/no-signal report still requires a positive page size');
    $run('git remote set-url origin https://github.com/acme/demo.git', $primary);
    $finalized_dry_run = $ws->worktree_active_no_signal_finalized_apply(array( 'dry_run' => true, 'limit' => 50, 'offset' => 0 ));
    $run(sprintf('git remote set-url origin %s', escapeshellarg($remote)), $primary);
    $assert(true, ! is_wp_error($finalized_dry_run) && ( $finalized_dry_run['success'] ?? false ), 'finalized active/no-signal dry-run succeeds');
    $assert(true, (bool) ( $finalized_dry_run['dry_run'] ?? false ), 'finalized active/no-signal dry-run does not write');
    $assert(1, (int) ( $finalized_dry_run['summary']['planned'] ?? 0 ), 'finalized active/no-signal dry-run plans merged PR rows only');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@head-merged')['cleanup_eligible_at'] ?? '', 'finalized active/no-signal dry-run leaves metadata unchanged');
    $run('git remote set-url origin https://github.com/acme/demo.git', $primary);
    $budgeted_finalized_dry_run = $ws->worktree_active_no_signal_finalized_apply(array( 'dry_run' => true, 'limit' => 20, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 1, 'internal_budget_started' => microtime(true) - 1 ));
    $run(sprintf('git remote set-url origin %s', escapeshellarg($remote)), $primary);
    $assert(true, ! is_wp_error($budgeted_finalized_dry_run) && ( $budgeted_finalized_dry_run['success'] ?? false ), 'budgeted finalized active/no-signal dry-run succeeds');
    $assert(true, str_contains($budgeted_finalized_dry_run['pagination']['next_command'] ?? '', 'active-no-signal-finalized-apply --dry-run'), 'budgeted finalized active/no-signal continuation stays on apply dry-run');
    $assert(true, str_contains($budgeted_finalized_dry_run['pagination']['next_command'] ?? '', '--until-budget=1s'), 'budgeted finalized active/no-signal continuation keeps time budget');
    $run('git remote set-url origin https://github.com/acme/demo.git', $primary);
    $finalized_page_dry_run = $ws->worktree_active_no_signal_finalized_apply(array( 'dry_run' => true, 'limit' => 1, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 60, 'internal_budget_started' => microtime(true) ));
    $run(sprintf('git remote set-url origin %s', escapeshellarg($remote)), $primary);
    $assert(true, str_contains($finalized_page_dry_run['pagination']['next_command'] ?? '', 'active-no-signal-finalized-apply --dry-run --limit=1 --offset=1 --until-budget=1s --format=json'), 'finalized active/no-signal dry-run continuation stays on finalized apply command');
    $assert(false, str_contains($finalized_page_dry_run['pagination']['next_command'] ?? '', 'active-no-signal-report'), 'finalized active/no-signal dry-run continuation does not fall back to report command');

    echo "\nDry-run reconciliation\n";
    $plan = $ws->worktree_reconcile_metadata(array( 'dry_run' => true ));
    $assert(true, ! is_wp_error($plan) && ( $plan['success'] ?? false ), 'dry-run succeeds');
    $assert(true, $plan['dry_run'] ?? false, 'dry-run flag is true');
    $assert(8, (int) ( $plan['summary']['proposed'] ?? 0 ), 'dry-run proposes unmanaged rows and safe merged lifecycle finalizers');
    $assert(0, (int) ( $plan['summary']['written'] ?? 0 ), 'dry-run writes nothing');
    $assert(1, (int) ( $plan['summary']['skipped_by_reason']['external_worktree'] ?? 0 ), 'dry-run distinguishes external worktrees');
    $assert(2, (int) ( $plan['summary']['skipped_by_reason']['unsafe_cleanup_eligible_state'] ?? 0 ), 'dry-run keeps dirty and unpushed merged worktrees out of auto-finalize proposals');
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
    $assert('operator_plan', $by_handle['demo@unmanaged-empty']['source_map']['lifecycle_state'] ?? '', 'empty-array metadata lifecycle source is operator_plan');
    $assert(true, isset($plan['summary']['slow_rows'][0]['elapsed_ms']), 'metadata reconciliation summary includes slow row timing samples');
    $assert(true, (int) ( $plan['summary']['prefiltered']['skipped_rows'] ?? 0 ) > 0, 'metadata reconciliation prefilter skips valid complete metadata rows before expensive probes');
    $assert(true, (int) ( $plan['summary']['prefiltered']['reasons']['missing_metadata'] ?? 0 ) >= 2, 'metadata reconciliation prefilter includes null and empty-array metadata rows');
    $assert(true, (int) ( $plan['summary']['prefiltered']['reasons']['stored_pr_signal'] ?? 0 ) >= 2, 'metadata reconciliation prefilter keeps stored PR finalizer signals');
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

    $bad_reconcile_zero = $ws->worktree_reconcile_metadata(array( 'dry_run' => true, 'limit' => 0, 'offset' => 0 ));
    $assert(true, is_wp_error($bad_reconcile_zero), 'metadata reconciliation rejects limit=0 when pagination is requested');
    $assert('invalid_metadata_reconcile_limit', $bad_reconcile_zero->code ?? '', 'metadata reconciliation limit=0 rejection keeps explicit error code');
    $bad_reconcile_negative = $ws->worktree_reconcile_metadata(array( 'dry_run' => true, 'limit' => -1, 'offset' => 0 ));
    $assert(true, is_wp_error($bad_reconcile_negative), 'metadata reconciliation rejects negative limits');
    $bad_reconcile_budget = $ws->worktree_reconcile_metadata(array( 'apply' => true, 'limit' => 0, 'until_budget' => '1s' ));
    $assert(true, is_wp_error($bad_reconcile_budget), 'budgeted metadata reconciliation direct apply requires a positive page size');

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
    $assert(8, (int) ( $apply['summary']['written'] ?? 0 ), 'apply writes exact current matches');
    $assert(8, (int) ( $apply['summary']['written'] ?? 0 ), 'apply reports written metadata rows');
    $assert(0, (int) ( $apply['summary']['skipped'] ?? 0 ), 'apply skips nothing for current plan');
    $assert(8, count($apply['written'] ?? array()), 'apply exposes written rows distinctly');
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
    $assert(1, (int) ( $bounded_auto_apply['summary']['inspected'] ?? 0 ), 'bounded direct apply summary stays candidate-page scoped');
    $assert(2, (int) ( $bounded_auto_apply['pagination']['limit'] ?? 0 ), 'bounded direct apply preserves pagination limit');
    $assert(2, (int) ( $bounded_auto_apply['pagination']['offset'] ?? 0 ), 'bounded direct apply preserves pagination offset');
    $assert('direct_apply', $bounded_auto_apply['evidence']['apply_source'] ?? '', 'bounded direct apply exposes evidence source');

    $inventory_after = $ws->worktree_cleanup_merged(array( 'dry_run' => true, 'inventory_only' => true, 'skip_github' => true ));
    $assert(1, (int) ( $inventory_after['summary']['skipped_by_reason']['needs_metadata_reconcile'] ?? 0 ), 'inventory cleanup requires fewer metadata reconciliation passes after apply');
    $assert(9, (int) ( $inventory_after['summary']['skipped_by_reason']['active_no_signal'] ?? 0 ), 'inventory cleanup treats reconciled active metadata like current active metadata');
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
    $equivalent_clean_page = $ws->worktree_active_no_signal_equivalent_clean_apply(array( 'dry_run' => true, 'limit' => 1, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 60, 'internal_budget_started' => microtime(true) ));
    $assert(true, str_contains($equivalent_clean_page['pagination']['next_command'] ?? '', 'active-no-signal-equivalent-clean-apply --dry-run --limit=1 --offset=1 --until-budget=1s --format=json'), 'equivalent-clean dry-run continuation stays on equivalent-clean apply command');
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
    for ( $probe_offset = 0; $probe_offset < 20; ++$probe_offset ) {
        $budget_probe = $ws->worktree_reconcile_metadata(array( 'dry_run' => true, 'limit' => 1, 'offset' => $probe_offset ));
        $proposal = $budget_probe['proposals'][0] ?? array();
        if ('demo@unmanaged-missing' === ( $proposal['handle'] ?? '' ) ) {
            $budget_offset = $probe_offset;
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

    echo "\nMerged-to-default active/no-signal apply\n";
    $run('git checkout main', $primary);
    $make_branch('default-merged');
    $make_branch('dirty-default-merged');
    $make_branch('unpushed-default-merged');
    $make_branch('ambiguous-default');
    $make_branch('fix/foo');
    $run('git checkout -b patch-equivalent-default', $primary);
    file_put_contents($primary . '/patch-equivalent-default.txt', 'patch-equivalent-default');
    $run('git add patch-equivalent-default.txt && git commit -m patch-equivalent-default', $primary);
    $run('git push -u origin patch-equivalent-default', $primary);
    $run('git checkout main', $primary);
    file_put_contents($primary . '/patch-equivalent-default.txt', 'patch-equivalent-default');
    $run('git add patch-equivalent-default.txt && git commit -m patch-equivalent-default-main', $primary);
    $run('git push origin main', $primary);
    $run('git checkout -b detached-equivalent', $primary);
    file_put_contents($primary . '/detached-equivalent.txt', 'detached-equivalent');
    $run('git add detached-equivalent.txt && git commit -m detached-equivalent', $primary);
    $run('git push -u origin detached-equivalent', $primary);
    $run('git checkout main', $primary);
    file_put_contents($primary . '/detached-equivalent.txt', 'detached-equivalent');
    $run('git add detached-equivalent.txt && git commit -m detached-equivalent-main', $primary);
    $run('git push origin main', $primary);
    $run('git checkout -b non-default-contained', $primary);
    file_put_contents($primary . '/non-default-contained.txt', 'non-default-contained');
    $run('git add non-default-contained.txt && git commit -m non-default-contained', $primary);
    $run('git push -u origin non-default-contained', $primary);
    $run('git checkout -b integration-branch', $primary);
    $run('git push -u origin integration-branch', $primary);
    $run('git checkout main', $primary);
    foreach (array( 'default-merged', 'dirty-default-merged', 'unpushed-default-merged', 'fix/foo' ) as $merged_branch ) {
        $run('git checkout main', $primary);
        $run(sprintf('git merge --no-ff %s -m %s', escapeshellarg($merged_branch), escapeshellarg('merge ' . $merged_branch)), $primary);
        $run('git push origin main', $primary);
    }
    $run('git checkout main', $primary);
    $merged_branch_worktrees = array(
        'demo@default-merged'          => array( 'branch' => 'default-merged', 'metadata_branch' => 'default-merged' ),
        'demo@dirty-default-merged'    => array( 'branch' => 'dirty-default-merged', 'metadata_branch' => 'dirty-default-merged' ),
        'demo@unpushed-default-merged' => array( 'branch' => 'unpushed-default-merged', 'metadata_branch' => 'unpushed-default-merged' ),
        'demo@ambiguous-default'       => array( 'branch' => 'ambiguous-default', 'metadata_branch' => 'ambiguous-default' ),
        'demo@fix-foo'                 => array( 'branch' => 'fix/foo', 'metadata_branch' => 'fix-foo' ),
        'demo@patch-equivalent-default' => array( 'branch' => 'patch-equivalent-default', 'metadata_branch' => 'patch-equivalent-default' ),
        'demo@detached-equivalent'     => array( 'branch' => 'detached-equivalent', 'metadata_branch' => 'detached-equivalent', 'detach' => true ),
        'demo@non-default-contained'   => array( 'branch' => 'non-default-contained', 'metadata_branch' => 'non-default-contained' ),
    );
    foreach ($merged_branch_worktrees as $handle => $branch_row ) {
        $branch_name     = $branch_row['branch'];
        $metadata_branch = $branch_row['metadata_branch'];
        $run(sprintf('git worktree add %s %s', escapeshellarg($tmp . '/' . $handle), escapeshellarg($branch_name)), $primary);
        if ( ! empty($branch_row['detach']) ) {
            $run('git checkout --detach', $tmp . '/' . $handle);
        }
        \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
            $handle,
            array(
            'handle'          => $handle,
            'repo'            => 'demo',
            'branch'          => $metadata_branch,
            'path'            => $tmp . '/' . $handle,
            'created_at'      => 'demo@ambiguous-default' === $handle ? gmdate('c') : '2026-04-06T00:00:00+00:00',
            'observed_at'     => 'demo@ambiguous-default' === $handle ? gmdate('c') : '2026-04-06T00:00:00+00:00',
            'last_seen_at'    => 'demo@ambiguous-default' === $handle ? gmdate('c') : '2026-04-06T00:00:00+00:00',
            'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
            )
        );
    }
    file_put_contents($tmp . '/demo@dirty-default-merged/scratch.txt', 'dirty');
    file_put_contents($tmp . '/demo@unpushed-default-merged/local-after-merge.txt', 'local');
    $run('git add local-after-merge.txt && git commit -m local-after-merge', $tmp . '/demo@unpushed-default-merged');

    $merged_report = $ws->worktree_active_no_signal_report(array( 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($merged_report) && ( $merged_report['success'] ?? false ), 'merged-to-default active/no-signal report succeeds');
    $merged_rows = array();
    $first_merged_offset = null;
    foreach ((array) ( $merged_report['rows'] ?? array() ) as $row ) {
        $merged_rows[ $row['handle'] ?? '' ] = $row;
        if ( null === $first_merged_offset && 'merged_to_default' === (string) ( $row['suggested_action'] ?? '' ) ) {
            $first_merged_offset = count($merged_rows) - 1;
        }
    }
    $assert('merged_to_default', $merged_rows['demo@default-merged']['suggested_action'] ?? '', 'clean contained branch is classified merged_to_default');
    $assert('fix/foo', $merged_rows['demo@fix-foo']['branch'] ?? '', 'active/no-signal report uses actual checked-out branch with slashes');
    $assert('fix-foo', $merged_rows['demo@fix-foo']['branch_slug'] ?? '', 'active/no-signal report preserves handle branch slug');
    $assert(true, (bool) ( $merged_rows['demo@fix-foo']['branch_identity']['mismatch'] ?? false ), 'active/no-signal report surfaces metadata branch mismatch');
    $assert('merged_to_default', $merged_rows['demo@fix-foo']['suggested_action'] ?? '', 'clean contained slash branch is classified merged_to_default');
    $assert('patch_equivalent_default', $merged_rows['demo@patch-equivalent-default']['suggested_action'] ?? '', 'clean patch-equivalent branch is classified patch_equivalent_default');
    $assert('equivalent_clean', $merged_rows['demo@patch-equivalent-default']['upstream_equivalence']['effective_status'] ?? '', 'clean patch-equivalent branch exposes equivalent_clean evidence');
    $assert('patch_equivalent_default', $merged_rows['demo@detached-equivalent']['suggested_action'] ?? '', 'detached clean patch-equivalent row is classified before apply revalidation');
    $assert('contained_non_default_remote', $merged_rows['demo@non-default-contained']['suggested_action'] ?? '', 'clean non-default-contained branch is classified contained_non_default_remote');
    $assert('contained_non_default_remote', $merged_rows['demo@non-default-contained']['upstream_equivalence']['effective_status'] ?? '', 'clean non-default-contained branch exposes containment evidence');
    $assert('unsafe_dirty_or_unpushed', $merged_rows['demo@dirty-default-merged']['suggested_action'] ?? '', 'dirty contained branch remains unsafe');
    $assert('unsafe_dirty_or_unpushed', $merged_rows['demo@unpushed-default-merged']['suggested_action'] ?? '', 'unpushed contained branch remains unsafe');
    $assert('remote_tracking_clean', $merged_rows['demo@ambiguous-default']['suggested_action'] ?? '', 'clean remote-tracking branch is classified as cleanup-safe local-only checkout');

    $stale_clean_dry_run = $ws->worktree_active_no_signal_stale_clean_apply(array( 'dry_run' => true, 'older_than' => '30d', 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($stale_clean_dry_run) && ( $stale_clean_dry_run['success'] ?? false ), 'stale-clean active/no-signal dry-run succeeds');
    $assert(true, (bool) ( $stale_clean_dry_run['dry_run'] ?? false ), 'stale-clean dry-run does not write');
    $stale_clean_handles = array_map(fn( $row ) => (string) ( $row['handle'] ?? '' ), (array) ( $stale_clean_dry_run['planned'] ?? array() ));
    $assert(true, in_array('demo@default-merged', $stale_clean_handles, true), 'stale-clean dry-run includes old clean rows');
    $assert(false, in_array('demo@dirty-default-merged', $stale_clean_handles, true), 'stale-clean dry-run excludes dirty rows');
    $assert(false, in_array('demo@unpushed-default-merged', $stale_clean_handles, true), 'stale-clean dry-run excludes unpushed rows');
    $assert(false, in_array('demo@ambiguous-default', $stale_clean_handles, true), 'stale-clean dry-run age-filters recent clean rows');
    $assert(true, 1 <= (int) ( $stale_clean_dry_run['summary']['skipped_by_reason']['not_stale_clean_cleanup_candidate'] ?? 0 ), 'stale-clean dry-run reports risky/non-candidate rows separately');
    $assert(true, 1 <= (int) ( $stale_clean_dry_run['summary']['skipped_by_reason']['stale_active_age_filter'] ?? 0 ), 'stale-clean dry-run reports age-filtered rows separately');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@default-merged')['cleanup_eligible_at'] ?? '', 'stale-clean dry-run leaves old clean metadata unchanged');
    $stale_clean_page = $ws->worktree_active_no_signal_stale_clean_apply(array( 'dry_run' => true, 'older_than' => '30d', 'limit' => 1, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 60, 'internal_budget_started' => microtime(true) ));
    $assert(true, str_contains($stale_clean_page['pagination']['next_command'] ?? '', 'active-no-signal-stale-clean-apply --dry-run --older-than=30d --limit=1 --offset=1 --until-budget=1s --format=json'), 'stale-clean dry-run continuation preserves age threshold');

    $merged_dry_run = $ws->worktree_active_no_signal_merged_apply(array( 'dry_run' => true, 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($merged_dry_run) && ( $merged_dry_run['success'] ?? false ), 'merged-to-default active/no-signal dry-run succeeds');
    $assert(true, (bool) ( $merged_dry_run['dry_run'] ?? false ), 'merged-to-default dry-run does not write');
    $assert(2, (int) ( $merged_dry_run['summary']['planned'] ?? 0 ), 'merged-to-default dry-run plans only safe merged rows');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@default-merged')['cleanup_eligible_at'] ?? '', 'merged-to-default dry-run leaves metadata unchanged');
    $merged_page_dry_run = $ws->worktree_active_no_signal_merged_apply(array( 'dry_run' => true, 'limit' => 1, 'offset' => 0, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 60, 'internal_budget_started' => microtime(true) ));
    $assert(true, str_contains($merged_page_dry_run['pagination']['next_command'] ?? '', 'active-no-signal-merged-apply --dry-run --limit=1 --offset=1 --until-budget=1s --format=json'), 'merged-to-default dry-run continuation stays on merged apply command');
    $assert(true, null !== $first_merged_offset, 'merged-to-default report exposes at least one merged candidate offset');
    $merged_page_apply = $ws->worktree_active_no_signal_merged_apply(array( 'limit' => 1, 'offset' => (int) $first_merged_offset, 'internal_budget_label' => '1s', 'internal_budget_seconds' => 60, 'internal_budget_started' => microtime(true) ));
    $assert(1, (int) ( $merged_page_apply['summary']['written'] ?? 0 ), 'merged-to-default page apply writes one row');
    $assert(true, str_contains($merged_page_apply['pagination']['next_command'] ?? '', 'active-no-signal-merged-apply --limit=1 --offset=' . (int) $first_merged_offset . ' --until-budget=1s --format=json'), 'merged-to-default apply continuation accounts for rows removed from active/no-signal page');

    $merged_apply = $ws->worktree_active_no_signal_merged_apply(array( 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($merged_apply) && ( $merged_apply['success'] ?? false ), 'merged-to-default active/no-signal apply succeeds');
    $assert(1, (int) ( $merged_apply['summary']['written'] ?? 0 ), 'merged-to-default apply writes remaining safe merged row metadata');
    $stored_default_merged = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@default-merged');
    $assert('cleanup_eligible', $stored_default_merged['lifecycle_state'] ?? '', 'merged-to-default apply stores cleanup_eligible state');
    $assert('merged', $stored_default_merged['finalized_state'] ?? '', 'merged-to-default apply records merged finalizer state');
    $assert('merged-to-default', $stored_default_merged['cleanup_eligibility_evidence']['signal'] ?? '', 'merged-to-default apply records merge evidence signal');
    $assert(0, (int) ( $stored_default_merged['cleanup_eligibility_evidence']['commits_outside_default'] ?? -1 ), 'merged-to-default evidence records containment count');
    $stored_fix_foo = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@fix-foo');
    $assert('cleanup_eligible', $stored_fix_foo['lifecycle_state'] ?? '', 'merged-to-default apply stores cleanup_eligible state for slash branch');
    $assert('fix/foo', $stored_fix_foo['branch'] ?? '', 'merged-to-default apply stores actual slash branch');
    $clean_equivalent_dry_run = $ws->worktree_active_no_signal_equivalent_clean_apply(array( 'dry_run' => true, 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($clean_equivalent_dry_run) && ( $clean_equivalent_dry_run['success'] ?? false ), 'clean equivalent active/no-signal dry-run succeeds');
    $assert(2, (int) ( $clean_equivalent_dry_run['summary']['planned'] ?? 0 ), 'clean equivalent dry-run plans patch-equivalent and non-default-contained rows');
    $assert(1, (int) ( $clean_equivalent_dry_run['summary']['skipped_by_reason']['missing_branch_identity'] ?? 0 ), 'clean equivalent dry-run skips detached rows without fataling');
    $clean_equivalent_apply = $ws->worktree_active_no_signal_equivalent_clean_apply(array( 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($clean_equivalent_apply) && ( $clean_equivalent_apply['success'] ?? false ), 'clean equivalent active/no-signal apply succeeds');
    $assert(2, (int) ( $clean_equivalent_apply['summary']['written'] ?? 0 ), 'clean equivalent apply writes patch-equivalent and non-default-contained metadata');
    $stored_patch_equivalent = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@patch-equivalent-default');
    $assert('cleanup_eligible', $stored_patch_equivalent['lifecycle_state'] ?? '', 'patch-equivalent apply stores cleanup_eligible state');
    $assert('upstream-equivalent-clean', $stored_patch_equivalent['cleanup_eligibility_evidence']['signal'] ?? '', 'patch-equivalent apply records upstream-equivalent signal');
    $stored_non_default = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@non-default-contained');
    $assert('cleanup_eligible', $stored_non_default['lifecycle_state'] ?? '', 'non-default containment apply stores cleanup_eligible state');
    $assert('contained-non-default-remote', $stored_non_default['cleanup_eligibility_evidence']['signal'] ?? '', 'non-default containment apply records containment signal');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@dirty-default-merged')['cleanup_eligible_at'] ?? '', 'dirty merged-to-default row remains active');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@unpushed-default-merged')['cleanup_eligible_at'] ?? '', 'unpushed merged-to-default row remains active');
    $remote_clean_dry_run = $ws->worktree_active_no_signal_remote_clean_apply(array( 'dry_run' => true, 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($remote_clean_dry_run) && ( $remote_clean_dry_run['success'] ?? false ), 'remote-clean active/no-signal dry-run succeeds');
    $assert(true, 1 <= (int) ( $remote_clean_dry_run['summary']['planned'] ?? 0 ), 'remote-clean dry-run plans clean remote-tracking local checkouts');
    $assert(true, in_array('demo@ambiguous-default', array_map(fn( $row ) => (string) ( $row['handle'] ?? '' ), (array) ( $remote_clean_dry_run['planned'] ?? array() )), true), 'remote-clean dry-run includes clean remote-tracking target row');
    $assert('', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@ambiguous-default')['cleanup_eligible_at'] ?? '', 'remote-clean dry-run leaves metadata unchanged');
    $remote_clean_apply = $ws->worktree_active_no_signal_remote_clean_apply(array( 'limit' => 100, 'offset' => 0 ));
    $assert(true, ! is_wp_error($remote_clean_apply) && ( $remote_clean_apply['success'] ?? false ), 'remote-clean active/no-signal apply succeeds');
    $assert(true, 1 <= (int) ( $remote_clean_apply['summary']['written'] ?? 0 ), 'remote-clean apply writes clean remote-tracking metadata');
    $assert(true, in_array('demo@ambiguous-default', array_map(fn( $row ) => (string) ( $row['handle'] ?? '' ), (array) ( $remote_clean_apply['written'] ?? array() )), true), 'remote-clean apply writes target clean remote-tracking metadata');
    $stored_ambiguous = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@ambiguous-default');
    $assert('cleanup_eligible', $stored_ambiguous['lifecycle_state'] ?? '', 'remote-clean apply stores cleanup_eligible state');
    $assert('remote-tracking-clean', $stored_ambiguous['cleanup_eligibility_evidence']['signal'] ?? '', 'remote-clean apply records remote tracking evidence signal');
    $assert('refs/remotes/origin/ambiguous-default', $stored_ambiguous['cleanup_eligibility_evidence']['remote_ref'] ?? '', 'remote-clean apply records preserving remote ref');
    $assert('merged-to-default', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@default-merged')['cleanup_eligibility_evidence']['signal'] ?? '', 'remote-clean apply does not overwrite merged-to-default evidence');
    $assert('upstream-equivalent-clean', \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@patch-equivalent-default')['cleanup_eligibility_evidence']['signal'] ?? '', 'remote-clean apply does not overwrite upstream-equivalent evidence');

    if ($failures > 0 ) {
        echo "\n{$failures} / {$total} assertions failed.\n";
        exit(1);
    }

    echo "\nAll {$total} worktree metadata reconciliation assertions passed.\n";
}
