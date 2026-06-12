<?php
/**
 * Smoke test for DMC worktree lifecycle metadata.
 *
 * Run: php tests/smoke-worktree-lifecycle-metadata.php
 *
 * @package DataMachineCode\Tests
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

    if (! class_exists(__NAMESPACE__ . '\DirectoryManager') ) {
        class DirectoryManager
        {
            public function get_effective_user_id( int $fallback ): int
            {
                return 7;
            }

            public function resolve_agent_slug( array $args ): string
            {
                return 'agent-one';
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
                return '';
            }

            public static function apiGet( string $url, array $params, string $pat )
            {
                return array( 'data' => array() );
            }
        }
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (! defined('ARRAY_A') ) {
        define('ARRAY_A', 'ARRAY_A');
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

            public function get_error_data()
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
        function get_option( string $name, $default = false )
        {
            global $datamachine_code_test_options;
            return $datamachine_code_test_options[ $name ] ?? $default;
        }
    }

    if (! function_exists('update_option') ) {
        function update_option( string $name, $value, $autoload = null ): bool
        {
            global $datamachine_code_test_options;
            $datamachine_code_test_options[ $name ] = $value;
            return true;
        }
    }

    if (! function_exists('current_time') ) {
        function current_time( string $type, bool $gmt = false ): string  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            return gmdate('Y-m-d H:i:s');
        }
    }

    if (! function_exists('wp_json_encode') ) {
        function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false
        {
            return json_encode($value, $flags, $depth);
        }
    }

    class DatamachineCodeLifecycleInventoryWpdb
    {
        public string $prefix = 'wp_';

        /**
         * @var array<string,array<string,mixed>> 
         */
        public array $rows = array();

        public int $insert_id = 1;

        public string $last_error = '';

        /**
         * @param array<string,mixed> $data 
         */
        public function replace( string $table, array $data ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            $this->rows[ (string) $data['handle'] ] = $data;
            return 1;
        }

        /**
         * @param array<string,mixed> $data 
         */
        public function insert( string $table, array $data, ?array $format = null ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            $this->insert_id++;
            return 1;
        }

        /**
         * @param array<string,mixed> $where 
         */
        public function delete( string $table, array $where ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            unset($this->rows[ (string) $where['handle'] ]);
            return 1;
        }

        /**
         * @param array<string,mixed> $data @param array<string,mixed> $where 
         */
        public function update( string $table, array $data, array $where ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            if (! isset($where['handle']) ) {
                return 1;
            }
            $handle = (string) $where['handle'];
            if (! isset($this->rows[ $handle ]) ) {
                return 0;
            }
            $this->rows[ $handle ] = array_merge($this->rows[ $handle ], $data);
            return 1;
        }

        /**
         * @return array<int,array<string,mixed>> 
         */
        public function get_results( string $sql, string $output ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        {
            return array_values($this->rows);
        }

        public function get_var( string $sql ): ?string
        {
            if (str_contains($sql, 'datamachine_code_locks') ) {
                return $this->prefix . 'datamachine_code_locks';
            }
            return null;
        }

        public function prepare( string $query, mixed ...$args ): string
        {
            foreach ( $args as $arg ) {
                $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
                $query       = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
            }
            return $query;
        }
    }

    if (! function_exists('apply_filters') ) {
        function apply_filters( string $hook_name, $value, ...$args )
        {
            global $datamachine_code_test_filters;
            if (isset($datamachine_code_test_filters[ $hook_name ]) && is_callable($datamachine_code_test_filters[ $hook_name ]) ) {
                return $datamachine_code_test_filters[ $hook_name ]($value, ...$args);
            }
            return $value;
        }
    }

    if (! function_exists('home_url') ) {
        function home_url(): string
        {
            return 'https://origin.example.test';
        }
    }

    if (! function_exists('get_bloginfo') ) {
        function get_bloginfo( string $show ): string
        {
            return 'Origin Site';
        }
    }

    if (! function_exists('get_current_user_id') ) {
        function get_current_user_id(): int
        {
            return 42;
        }
    }

    if (! function_exists('get_userdata') ) {
        function get_userdata( int $user_id ): object
        {
            return (object) array(
            'user_login'   => 'chris',
            'display_name' => 'Chris',
            );
        }
    }

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Storage/WorktreeInventoryRepository.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    include __DIR__ . '/../inc/Workspace/WorktreeStalenessProbe.php';
    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/WorktreeBootstrapper.php';
    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

    exec('git --version 2>&1', $_git_version, $git_exit);
    if (0 !== $git_exit ) {
        echo "SKIP: git not available\n";
        exit(0);
    }

    $assertions = 0;
    $failures   = 0;

    $assert = function ( $expected, $actual, string $message ) use ( &$assertions, &$failures ): void {
        ++$assertions;
        if ($expected === $actual ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        ++$failures;
        echo "  [FAIL] {$message}\n";
        echo '         expected: ' . var_export($expected, true) . "\n";
        echo '         actual:   ' . var_export($actual, true) . "\n";
    };

    $run = function ( string $command, ?string $cwd = null ) use ( &$assert ): string {
        $full = null === $cwd ? $command : sprintf('cd %s && %s', escapeshellarg($cwd), $command);
        exec($full . ' 2>&1', $output, $code);
        $stdout = trim(implode("\n", $output));
        $assert(0, $code, 'command succeeds: ' . $command . ( '' !== $stdout ? "\n{$stdout}" : '' ));
        return $stdout;
    };

    echo "=== smoke-worktree-lifecycle-metadata ===\n";

    $tmp = sys_get_temp_dir() . '/dmc-worktree-metadata-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $tmp = (string) realpath($tmp);
    register_shutdown_function(
        function () use ( $tmp ): void {
            if (is_dir($tmp) ) {
                exec('rm -rf ' . escapeshellarg($tmp) . ' 2>&1');
            }
        } 
    );

    define('DATAMACHINE_WORKSPACE_PATH', $tmp . '/workspace');
    mkdir(DATAMACHINE_WORKSPACE_PATH, 0755, true);
    $primary = DATAMACHINE_WORKSPACE_PATH . '/demo';
    mkdir($primary, 0755, true);

    $run('git init', $primary);
    $run('git config user.email test@example.test', $primary);
    $run('git config user.name Test', $primary);
    file_put_contents($primary . '/README.md', "# Demo\n");
    $run('git add README.md', $primary);
    $run('git commit -m initial', $primary);
    $run('git remote add origin https://github.com/acme/demo.git', $primary);
    $linked_primary = DATAMACHINE_WORKSPACE_PATH . '/linked-primary';
    $run('git worktree add ../linked-primary -b linked-primary HEAD', $primary);
    $assert(true, is_file($linked_primary . '/.git'), 'linked primary fixture uses a .git file');
    // Synthetic runtime registration so the env-driven session capture has a
    // runtime to scan. DMC enumerates no runtime IDs itself — the integration
    // layer (here, the test) declares them via the public filter.
    $GLOBALS['datamachine_code_test_filters']['datamachine_code_worktree_runtime_signatures'] = function ( array $signatures ): array {
        $signatures['smoke-runtime'] = array(
        'run_id' => 'DMC_SMOKE_LIFECYCLE_RUN_ID',
        );
        return $signatures;
    };
    putenv('DMC_SMOKE_LIFECYCLE_RUN_ID=smoke-run-123');

    $ws = new \DataMachineCode\Workspace\Workspace();
    $GLOBALS['wpdb'] = new DatamachineCodeLifecycleInventoryWpdb();
    $linked_list = $ws->worktree_list('linked-primary', null, array( 'include_status' => false, 'include_disk' => false ));
    $linked_items = array_values(array_filter($linked_list['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'linked-primary'));
    $assert(1, count($linked_items), 'worktree_list discovers primary checkouts whose .git is a file');

    $url_worktree = $ws->worktree_add('https://github.com/acme/demo.git', 'feature/url-primary-resolution', 'HEAD', false, false, true, false, false);
    $assert(false, is_wp_error($url_worktree), 'worktree_add accepts URL matching an existing local primary');
    $assert('demo@feature-url-primary-resolution', is_wp_error($url_worktree) ? null : ( $url_worktree['handle'] ?? null ), 'URL repo argument normalizes to primary handle');

    $path_worktree = $ws->worktree_add($primary, 'feature/path-primary-resolution', 'HEAD', false, false, true, false, false);
    $assert(false, is_wp_error($path_worktree), 'worktree_add accepts path to an existing local primary');
    $assert('demo@feature-path-primary-resolution', is_wp_error($path_worktree) ? null : ( $path_worktree['handle'] ?? null ), 'path repo argument normalizes to primary handle');

    $bad_url_worktree = $ws->worktree_add('https://github.com/acme/missing.git', 'feature/missing-url-primary-resolution', 'HEAD', false, false, true, false, false);
    $assert(true, is_wp_error($bad_url_worktree), 'worktree_add rejects URL repo arguments without a matching local primary');
    $assert('unsupported_workspace_repo_argument', is_wp_error($bad_url_worktree) ? $bad_url_worktree->get_error_code() : null, 'missing URL primary returns a clear error code');

    $GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] = function ( array $thresholds ): array {
        $thresholds['refuse_free_bytes']   = 1;
        $thresholds['refuse_free_percent'] = 100;
        $thresholds['warn_free_percent']   = 100;
        return $thresholds;
    };
    $percent_warning_worktree = $ws->worktree_add('demo', 'feature/percent-warning-skip-bootstrap', 'HEAD', false, false, true, false, false);
    unset($GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds']);
    $assert(false, is_wp_error($percent_warning_worktree), 'worktree_add with bootstrap disabled is not blocked by percentage threshold alone');
    $assert('warning', is_wp_error($percent_warning_worktree) ? null : ( $percent_warning_worktree['disk_budget']['status'] ?? null ), 'percentage-only disk pressure remains visible as a warning');

    $GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds'] = function ( array $thresholds ): array {
        $thresholds['refuse_free_bytes']   = PHP_INT_MAX;
        $thresholds['refuse_free_percent'] = 100;
        $thresholds['warn_free_bytes']     = PHP_INT_MAX;
        $thresholds['warn_free_percent']   = 100;
        return $thresholds;
    };
    $refused = $ws->worktree_add('demo', 'feature/disk-budget-refusal', 'HEAD', true, true, true, false, false);
    unset($GLOBALS['datamachine_code_test_filters']['datamachine_worktree_disk_budget_thresholds']);
    $assert(true, is_wp_error($refused), 'worktree_add refuses before creation when disk budget is unsafe');
    $assert(false, is_dir(DATAMACHINE_WORKSPACE_PATH . '/demo@feature-disk-budget-refusal'), 'refused disk budget does not leave a worktree directory');
    $assert(true, str_contains($refused->get_error_message(), DATAMACHINE_WORKSPACE_PATH), 'disk budget refusal names workspace root');
    $assert(true, str_contains($refused->get_error_message(), 'Recommended cleanup, in order:'), 'disk budget refusal groups remediation commands');
    $assert(true, str_contains($refused->get_error_message(), 'cleanup-artifacts --dry-run --sort=size'), 'disk budget refusal suggests largest artifact review');
    $assert(true, str_contains($refused->get_error_message(), 'target reclaim:'), 'disk budget refusal includes target reclaim estimate');

    $result = $ws->worktree_add('demo', 'feature/metadata', 'HEAD', false, false, true, false);
    $assert(true, ! is_wp_error($result) && ( $result['success'] ?? false ), 'worktree_add succeeds without context injection');

    $metadata = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata('demo@feature-metadata');
    $assert(true, isset($GLOBALS['wpdb']->rows['demo@feature-metadata']), 'worktree_add writes DB inventory row');
    $assert('active', $GLOBALS['wpdb']->rows['demo@feature-metadata']['lifecycle_state'] ?? null, 'worktree_add inventory row starts active');
    $assert(true, is_array($metadata), 'lifecycle metadata recorded at add time');
    $assert('Origin Site', $metadata['origin_site'] ?? null, 'origin site is recorded');
    $assert('agent-one', $metadata['origin_agent'] ?? null, 'origin agent is recorded');
    $assert(42, $metadata['origin_user']['id'] ?? null, 'origin user id is recorded without sensitive fields');
    $assert('HEAD', $metadata['base_ref'] ?? null, 'base ref is recorded');
    $assert('requested_ref', $metadata['base_source'] ?? null, 'base source is recorded');
    $assert('active', $metadata['lifecycle_state'] ?? null, 'new worktree lifecycle state defaults to active');
    $assert('demo@feature-metadata', $metadata['handle'] ?? null, 'worktree handle is recorded');
    $assert(DATAMACHINE_WORKSPACE_PATH . '/demo@feature-metadata', $metadata['path'] ?? null, 'worktree path is recorded');
    $assert(true, isset($metadata['origin_session']) && is_array($metadata['origin_session']), 'origin session metadata is recorded when runtime env is available');

    $list  = $ws->worktree_list('demo');
    $items = array_values(array_filter($list['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-metadata'));
    $item  = $items[0] ?? array();
    $assert($metadata['created_at'] ?? null, $item['created_at'] ?? null, 'list data exposes created_at metadata');
    $assert('Origin Site', $item['metadata']['origin_site'] ?? null, 'list data exposes nested lifecycle metadata');
    $assert('active', $item['lifecycle_state'] ?? null, 'list data exposes lifecycle state');

    $finalized = $ws->worktree_finalize('demo@feature-metadata', 'pr_opened', 'https://github.com/acme/demo/pull/123');
    $assert(true, ! is_wp_error($finalized) && ( $finalized['success'] ?? false ), 'worktree_finalize succeeds with PR URL');
    $assert('cleanup_eligible', $finalized['lifecycle_state'] ?? null, 'PR finalizer safely transitions lifecycle state to cleanup_eligible');
    $assert('pr_opened', $finalized['metadata']['finalized_state'] ?? null, 'PR finalizer preserves requested finalizer state');
    $assert(true, isset($finalized['metadata']['cleanup_eligible_at']), 'PR finalizer records cleanup eligibility timestamp');
    $assert(123, $finalized['metadata']['pr_number'] ?? null, 'finalizer extracts PR number');
    $assert('https://github.com/acme/demo/pull/123', $finalized['metadata']['pr_url'] ?? null, 'finalizer stores normalized PR URL');
    $assert('cleanup_eligible', $GLOBALS['wpdb']->rows['demo@feature-metadata']['lifecycle_state'] ?? null, 'worktree_finalize updates DB inventory lifecycle state');
    $assert('cleanup_eligible', $GLOBALS['wpdb']->rows['demo@feature-metadata']['cleanup_signal'] ?? null, 'worktree_finalize records cleanup signal in DB inventory');

    $filtered = $ws->worktree_list('demo', 'cleanup_eligible');
    $filtered_items = array_values(array_filter($filtered['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-metadata'));
    $assert(1, count($filtered_items), 'worktree_list can filter by lifecycle state');

    $invalid_state = $ws->worktree_finalize('demo@feature-metadata', 'donezo');
    $assert(true, is_wp_error($invalid_state), 'invalid finalizer state returns WP_Error');

    $eligible = $ws->worktree_finalize('demo@feature-metadata', 'cleanup_eligible');
    $assert(true, ! is_wp_error($eligible) && ( $eligible['success'] ?? false ), 'worktree can be marked cleanup_eligible');

    $merged = $ws->worktree_finalize('demo@feature-metadata', 'merged');
    $assert(true, ! is_wp_error($merged) && ( $merged['success'] ?? false ), 'merged finalizer succeeds');
    $assert('cleanup_eligible', $merged['lifecycle_state'] ?? null, 'merged finalizer safely transitions lifecycle state to cleanup_eligible');
    $assert('merged', $merged['metadata']['finalized_state'] ?? null, 'merged finalizer preserves requested finalizer state');

    $result_old = $ws->worktree_add('demo', 'feature/old-record', 'HEAD', false, false, true, false);
    $assert(true, ! is_wp_error($result_old) && ( $result_old['success'] ?? false ), 'second worktree_add succeeds');
    $refresh = $ws->worktree_inventory_refresh();
    $assert(true, ! is_wp_error($refresh) && ( $refresh['success'] ?? false ), 'inventory refresh reconciles current worktrees');
    $assert(true, in_array('demo@feature-old-record', $refresh['upserted'] ?? array(), true), 'inventory refresh upserts newly observed worktree');
    $all_metadata = get_option(\DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, array());
    unset($all_metadata['demo@feature-old-record']);
    update_option(\DataMachineCode\Workspace\WorktreeContextInjector::METADATA_OPTION, $all_metadata, false);

    $list_old  = $ws->worktree_list('demo');
    $old_items = array_values(array_filter($list_old['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@feature-old-record'));
    $old_item  = $old_items[0] ?? array();
    $assert(true, isset($old_item['handle']), 'old worktree with missing metadata still lists');
    $assert(true, is_array($old_item['metadata'] ?? null), 'old worktree metadata is recovered from DB inventory when option row is absent');

    $plan = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        )
    );
    $assert(true, ! is_wp_error($plan) && ( $plan['success'] ?? false ), 'cleanup dry-run succeeds with mixed metadata records');
    $eligible_candidates = array_values(array_filter($plan['candidates'] ?? array(), fn( $cand ) => ( $cand['handle'] ?? '' ) === 'demo@feature-metadata'));
    $assert(1, count($eligible_candidates), 'cleanup dry-run treats cleanup_eligible metadata as a candidate signal');
    $assert('cleanup_eligible', $eligible_candidates[0]['signal'] ?? null, 'cleanup_eligible candidate exposes stable signal');
    $feature_skip = array_values(array_filter($plan['skipped'] ?? array(), fn( $skip ) => ( $skip['handle'] ?? '' ) === 'demo@feature-metadata'));
    $old_skip = array_values(array_filter($plan['skipped'] ?? array(), fn( $skip ) => ( $skip['handle'] ?? '' ) === 'demo@feature-old-record'));
    $assert(true, is_array($old_skip[0]['metadata'] ?? null), 'cleanup dry-run uses DB-backed metadata when option row is absent');

    $pr_cleanup_worktree = $ws->worktree_add('demo', 'feature/pr-cleanup', 'HEAD', false, false, true, false);
    $assert(true, ! is_wp_error($pr_cleanup_worktree) && ( $pr_cleanup_worktree['success'] ?? false ), 'PR cleanup worktree fixture is created');
    $pr_cleanup = $ws->cleanup_merged_pr_worktree('acme/demo', 'feature/pr-cleanup', 'https://github.com/acme/demo/pull/456');
    $assert(true, ! is_wp_error($pr_cleanup) && ( $pr_cleanup['success'] ?? false ), 'merged PR worktree cleanup succeeds');
    $assert(true, (bool) ( $pr_cleanup['found'] ?? false ), 'merged PR worktree cleanup finds exact GitHub repo and branch match');
    $assert(false, is_dir(DATAMACHINE_WORKSPACE_PATH . '/demo@feature-pr-cleanup'), 'merged PR worktree cleanup removes the attached worktree directory');
    $branch_check = trim($run('git branch --list feature/pr-cleanup', $primary));
    $assert('', $branch_check, 'merged PR worktree cleanup deletes the local checked-out branch after removal');

    $removed_old = $ws->worktree_remove('demo', 'feature/old-record', true);
    $assert(true, ! is_wp_error($removed_old) && ( $removed_old['success'] ?? false ), 'worktree_remove succeeds for old record');
    $assert(false, isset($GLOBALS['wpdb']->rows['demo@feature-old-record']), 'worktree_remove deletes DB inventory row');

    if ($failures > 0 ) {
        echo "\n{$failures} failure(s) across {$assertions} assertion(s).\n";
        exit(1);
    }

    echo "\nAll {$assertions} worktree lifecycle metadata assertions passed.\n";
}
