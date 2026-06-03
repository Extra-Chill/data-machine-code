<?php
/**
 * Integration smoke test for worktree_cleanup_merged.
 *
 * Builds a real workspace in a tempdir with real git repos + real worktrees,
 * simulates various scenarios (merged branch, unmerged branch, dirty tree,
 * protected branch, unmanaged non-slug directory name), then runs cleanup and
 * asserts the correct worktrees got pruned.
 *
 * Run: php tests/smoke-worktree-cleanup.php
 *
 * Skips entirely if `git` is unavailable on $PATH.
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    if (! class_exists(__NAMESPACE__ . '\\FilesystemHelper') ) {
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
    // Stub so the GitHub code path short-circuits without network calls.
    if (! class_exists(__NAMESPACE__ . '\\GitHubAbilities') ) {
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
        function update_option( string $name, $value, $autoload = null ): bool
        {
            global $datamachine_code_test_options;
            $datamachine_code_test_options[ $name ] = $value;
            return true;
        }
    }

    if (! function_exists('apply_filters') ) {
        function apply_filters( string $hook_name, $value )
        {
            return $value;
        }
    }

    if (! function_exists('size_format') ) {
        function size_format( $bytes ): string
        {
            return (string) $bytes;
        }
    }

    if (! function_exists('wp_json_encode') ) {
        function wp_json_encode( $value, int $flags = 0, int $depth = 512 ): string|false
        {
            return json_encode($value, $flags, $depth);
        }
    }

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

    // Skip if git missing.
    exec('git --version 2>&1', $_gv, $gv_exit);
    if (0 !== $gv_exit ) {
        echo "SKIP: git not available\n";
        exit(0);
    }

    // Create isolated workspace in tmp.
    $tmp = sys_get_temp_dir() . '/dmc-cleanup-smoke-' . bin2hex(random_bytes(4));
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

    $assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
        $total++;
        if ($expected === $actual ) {
            echo "  ✓ {$message}\n";
            return;
        }
        $failures++;
        echo "  ✗ {$message}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    };

    $assert_contains = function ( array $haystack, string $needle, string $message ) use ( &$failures, &$total ): void {
        $total++;
        foreach ( $haystack as $item ) {
            $handle = is_array($item) ? ( $item['handle'] ?? '' ) : (string) $item;
            if ($handle === $needle ) {
                echo "  ✓ {$message}\n";
                return;
            }
        }
        $failures++;
        echo "  ✗ {$message}\n";
        echo "    needle:  {$needle}\n";
        echo "    haystack: " . implode(', ', array_map(fn( $i ) => is_array($i) ? ( $i['handle'] ?? '?' ) : $i, $haystack)) . "\n";
    };

    class Cleanup_Inventory_Workspace extends \DataMachineCode\Workspace\Workspace
    {
        public int $full_listing_calls = 0;

        public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            ++$this->full_listing_calls;
            return parent::worktree_list($repo, $state, $opts);
        }
    }

    $run = function ( string $cmd, string $cwd = '' ) {
        $full = '' === $cwd ? $cmd : sprintf('cd %s && %s', escapeshellarg($cwd), $cmd);
        exec($full . ' 2>&1', $out, $rc);
        return array(
        'output' => implode("\n", $out),
        'exit'   => $rc,
        );
    };

    // -------------------------------------------------------------------------
    // Scenario setup
    // -------------------------------------------------------------------------

    echo "Setting up workspace at {$tmp}\n";

    // "remote" bare repo that stands in for origin.
    $remote = $tmp . '/remote.git';
    $run(sprintf('git init --bare %s', escapeshellarg($remote)));

    // Primary checkout: clone + initial commit on main + push.
    $primary = $tmp . '/demo';
    $run(sprintf('git clone %s %s', escapeshellarg($remote), escapeshellarg($primary)));
    $primary_real = realpath($primary) ? realpath($primary) : $primary;
    $run('git config user.email test@example.com', $primary);
    $run('git config user.name test', $primary);
    file_put_contents($primary . '/README.md', "demo\n");
    file_put_contents($primary . '/Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
    file_put_contents($primary . '/package.json', "{\"scripts\":{\"build\":\"echo build\"}}\n");
    file_put_contents($primary . '/.gitignore', "target/\n");
    $run('git add README.md && git commit -m init', $primary);
    $run('git add Cargo.toml package.json .gitignore && git commit -m tooling', $primary);
    $run('git branch -M main', $primary);
    $run('git push -u origin main', $primary);

    // Helper: make a branch on the remote too so "upstream" tracking exists.
    $make_branch = function ( string $branch, string $content ) use ( $primary, $run ) {
        $run(sprintf('git checkout -b %s', escapeshellarg($branch)), $primary);
        file_put_contents($primary . '/' . str_replace('/', '-', $branch) . '.txt', $content);
        $run(sprintf('git add . && git commit -m %s', escapeshellarg('work on ' . $branch)), $primary);
        $run(sprintf('git push -u origin %s', escapeshellarg($branch)), $primary);
        $run('git checkout main', $primary);
    };

    // Branches that have branches on the remote.
    $make_branch('merged-autodelete', 'a');   // → simulate remote delete below
    $make_branch('merged-stale-plan', 'a2');  // → candidate in plan, dirty before apply
    $make_branch('merged-recent', 'b');       // → merged, but too new for age-filtered cleanup
    $make_branch('merged-unknown-age', 'c');  // → merged, but missing created_at metadata
    $make_branch('merged-live-remote', 'd');  // → simulate via PR-merged (stubbed → none)
    $make_branch('unmerged-feature', 'e');    // still active
    $make_branch('dirty-branch', 'f');        // will be dirty in worktree
    $make_branch('artifact-only-dirty', 'f2'); // will be dirty only from build/
    $make_branch('mixed-artifact-dirty', 'f3'); // will have build/ plus a source edit
    $make_branch('external-branch', 'g');     // outside workspace, should never be removed

    // Create worktrees at various paths:
    //   - canonical slug path (demo@merged-autodelete)
    //   - unmanaged sibling path (demo-unmanaged-merged)
    //   - canonical path for the unmerged one
    //   - canonical path for dirty
    $run(sprintf('git worktree add %s merged-autodelete', escapeshellarg($tmp . '/demo@merged-autodelete')), $primary);
    $run(sprintf('git worktree add %s merged-stale-plan', escapeshellarg($tmp . '/demo@merged-stale-plan')), $primary);
    $run(sprintf('git worktree add %s merged-recent', escapeshellarg($tmp . '/demo@merged-recent')), $primary);
    $run(sprintf('git worktree add %s merged-unknown-age', escapeshellarg($tmp . '/demo@merged-unknown-age')), $primary);
    $run(sprintf('git worktree add %s merged-live-remote', escapeshellarg($tmp . '/demo-unmanaged-merged')), $primary);
    $run(sprintf('git worktree add %s unmerged-feature', escapeshellarg($tmp . '/demo@unmerged-feature')), $primary);
    $run(sprintf('git worktree add %s dirty-branch', escapeshellarg($tmp . '/demo@dirty-branch')), $primary);
    $run(sprintf('git worktree add %s artifact-only-dirty', escapeshellarg($tmp . '/demo@artifact-only-dirty')), $primary);
    $run(sprintf('git worktree add %s mixed-artifact-dirty', escapeshellarg($tmp . '/demo@mixed-artifact-dirty')), $primary);
    mkdir($tmp . '-external', 0755, true);
    $run(sprintf('git worktree add %s external-branch', escapeshellarg($tmp . '-external/demo-external')), $primary);
    $external_real = realpath($tmp . '-external/demo-external') ? realpath($tmp . '-external/demo-external') : $tmp . '-external/demo-external';

    // Dirty the dirty worktree.
    file_put_contents($tmp . '/demo@dirty-branch/scratch.txt', 'dirty');
    mkdir($tmp . '/demo@artifact-only-dirty/build', 0755, true);
    file_put_contents($tmp . '/demo@artifact-only-dirty/build/output.js', 'artifact');
    mkdir($tmp . '/demo@mixed-artifact-dirty/build', 0755, true);
    file_put_contents($tmp . '/demo@mixed-artifact-dirty/build/output.js', 'artifact');
    file_put_contents($tmp . '/demo@mixed-artifact-dirty/README.md', "source edit\n");
    mkdir($tmp . '/demo@merged-autodelete/target', 0755, true);
    file_put_contents($tmp . '/demo@merged-autodelete/target/artifact.bin', str_repeat('x', 4096));

    \DataMachineCode\Workspace\WorktreeContextInjector::store_metadata(
        'demo@merged-autodelete',
        array(
        'site_url'   => 'http://example.test',
        'site_name'  => 'Example',
        'agent_slug' => 'agent-one',
        'abspath'    => '/example',
        'timestamp'  => gmdate('c', time() - 14 * 86400),
        )
    );

    \DataMachineCode\Workspace\WorktreeContextInjector::store_metadata(
        'demo@merged-recent',
        array(
        'site_url'   => 'http://example.test',
        'site_name'  => 'Example',
        'agent_slug' => 'agent-one',
        'abspath'    => '/example',
        'timestamp'  => gmdate('c', time() - 3600),
        )
    );

    \DataMachineCode\Workspace\WorktreeContextInjector::store_metadata(
        'demo@unmerged-feature',
        array(
        'site_url'   => 'http://example.test',
        'site_name'  => 'Example',
        'agent_slug' => 'agent-one',
        'abspath'    => '/example',
        'timestamp'  => '2026-04-25T00:00:00+00:00',
        )
    );
    mkdir($tmp . '/demo@inventory-cleanup-eligible', 0755, true);
    mkdir($tmp . '/demo@inventory-finalized-pr', 0755, true);
    mkdir($tmp . '/demo@inventory-active', 0755, true);
    mkdir($tmp . '/demo@inventory-stale-lifecycle', 0755, true);
    mkdir($tmp . '/demo@inventory-missing-metadata', 0755, true);
    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@inventory-cleanup-eligible',
        array(
        'created_at'      => '2026-04-01T00:00:00+00:00',
        'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_CLEANUP_ELIGIBLE,
        'pr_url'          => 'https://github.com/acme/demo/pull/42',
        'pr_number'       => 42,
        )
    );
    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@inventory-finalized-pr',
        array(
        'created_at'      => '2026-04-01T00:00:00+00:00',
        'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_PR_OPENED,
        'finalized_at'    => '2026-04-02T00:00:00+00:00',
        'pr_url'          => 'https://github.com/acme/demo/pull/43',
        'pr_number'       => 43,
        )
    );
    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@inventory-active',
        array(
        'created_at'      => '2026-04-01T00:00:00+00:00',
        'last_seen_at'    => gmdate('c'),
        'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
        )
    );
    \DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
        'demo@inventory-stale-lifecycle',
        array(
        'created_at'      => '2026-04-01T00:00:00+00:00',
        'last_seen_at'    => '2026-04-01T00:00:00+00:00',
        'lifecycle_state' => \DataMachineCode\Workspace\WorktreeContextInjector::STATE_ACTIVE,
        'pr_url'          => 'https://github.com/acme/demo/pull/44',
        'pr_number'       => 44,
        )
    );

    // Simulate "remote deleted the merged-* branches" — classic
    // GitHub auto-delete-on-merge scenario. After a fetch --prune, the
    // local branch's upstream tracking ref will be "gone".
    $run(sprintf('git -C %s push origin --delete merged-autodelete', escapeshellarg($remote)));
    // (The bare remote "origin" is the URL; git push --delete pushes to
    // origin. But our origin IS the bare repo — so we need to update the
    // bare ref directly.)
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-autodelete', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-stale-plan', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-recent', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-unknown-age', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/artifact-only-dirty', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/mixed-artifact-dirty', escapeshellarg($remote)));

    // -------------------------------------------------------------------------
    // Dry-run assertions
    // -------------------------------------------------------------------------

    echo "\nDry-run scenario\n";
    $ws   = new \DataMachineCode\Workspace\Workspace();
    $plan = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        )
    );

    $assert(true, ! is_wp_error($plan) && ( $plan['success'] ?? false ), 'dry_run returns success');
    $assert(true, $plan['dry_run'] ?? false, 'dry_run flag echoes back true');

    $list = $ws->worktree_list('demo');
    $metadata_items = array_filter($list['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@unmerged-feature');
    $metadata_item  = array_values($metadata_items)[0] ?? array();
    $assert('2026-04-25T00:00:00+00:00', $metadata_item['created_at'] ?? null, 'worktree list exposes creation metadata for agent runtime');
    $assert('agent-one', $metadata_item['metadata']['agent_slug'] ?? null, 'worktree list exposes agent metadata for agent runtime');
    $assert(true, isset($metadata_item['size_bytes']), 'worktree list exposes estimated size bytes');
    $assert(true, isset($metadata_item['age_days']), 'worktree list exposes age_days');

    $artifact_items = array_filter($list['worktrees'] ?? array(), fn( $wt ) => ( $wt['handle'] ?? '' ) === 'demo@merged-autodelete');
    $artifact_item  = array_values($artifact_items)[0] ?? array();
    $assert(true, (int) ( $artifact_item['artifact_size_bytes'] ?? 0 ) > 0, 'worktree list reports Rust target artifact size');
    $assert('target', $artifact_item['artifacts'][0]['path'] ?? '', 'worktree list reports Rust target artifact path');

    $assert_contains($plan['candidates'] ?? array(), 'demo@merged-autodelete', 'canonical merged worktree flagged prunable');
    $assert_contains($plan['candidates'] ?? array(), 'demo@merged-recent', 'recent merged worktree is still prunable without age filter');
    $assert_contains($plan['candidates'] ?? array(), 'demo@merged-unknown-age', 'merged worktree without age metadata is still prunable without age filter');

    // dirty-branch should be skipped (reason: dirty)
    $dirty_skips = array_filter($plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@dirty-branch');
    $assert(1, count($dirty_skips), 'dirty worktree skipped with exactly one entry');
    $dirty_row = array_values($dirty_skips)[0] ?? array();
    $dirty_reason = $dirty_row['reason'] ?? '';
    $assert(true, str_contains($dirty_reason, 'dirty'), 'dirty skip reason mentions dirty');
    $assert('dirty_worktree', $dirty_row['reason_code'] ?? '', 'dirty skip exposes stable reason code');

    $artifact_dirty_skips = array_filter($plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@artifact-only-dirty');
    $assert(1, count($artifact_dirty_skips), 'artifact-only dirty worktree skipped with exactly one entry');
    $artifact_dirty_row = array_values($artifact_dirty_skips)[0] ?? array();
    $assert('artifact_only_dirty_worktree', $artifact_dirty_row['reason_code'] ?? '', 'artifact-only dirt exposes stable reason code');
    $assert(array( 'build' ), $artifact_dirty_row['artifact_dirty_paths'] ?? array(), 'artifact-only dirty row reports dirty artifact path');
    $assert('build', $artifact_dirty_row['artifacts'][0]['path'] ?? '', 'artifact-only dirty row reports matching artifact profile path');
    $assert(true, str_contains($artifact_dirty_row['hint'] ?? '', 'cleanup-artifacts --dry-run'), 'artifact-only dirty row points to artifact cleanup lane');

    $mixed_dirty_skips = array_filter($plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@mixed-artifact-dirty');
    $assert(1, count($mixed_dirty_skips), 'mixed source/artifact dirty worktree skipped with exactly one entry');
    $mixed_dirty_row = array_values($mixed_dirty_skips)[0] ?? array();
    $assert('dirty_worktree', $mixed_dirty_row['reason_code'] ?? '', 'mixed source/artifact dirt stays protected as generic dirty worktree');

    // unmerged-feature should be skipped (no merge signal)
    $unmerged = array_filter($plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@unmerged-feature');
    $assert(1, count($unmerged), 'unmerged worktree skipped with exactly one entry');
    $unmerged_row = array_values($unmerged)[0] ?? array();
    $assert('no_merge_signal', $unmerged_row['reason_code'] ?? '', 'unmerged skip exposes stable reason code');

    $external_rows = array_values(array_filter($plan['skipped'] ?? array(), fn( $s ) => ( $s['reason_code'] ?? '' ) === 'external_worktree'));
    $external_row  = $external_rows[0] ?? array();
    $assert('external_worktree', $external_row['reason_code'] ?? '', 'external worktree exposes stable reason code');
    $assert(true, str_contains($external_row['hint'] ?? '', 'outside the DMC workspace'), 'external worktree includes remediation hint');

    $assert(4, (int) ( $plan['summary']['would_remove'] ?? 0 ), 'summary counts cleanup candidates');
    $assert(2, (int) ( $plan['summary']['skipped_by_reason']['dirty_worktree'] ?? 0 ), 'summary counts dirty skips by reason');
    $assert(1, (int) ( $plan['summary']['skipped_by_reason']['artifact_only_dirty_worktree'] ?? 0 ), 'summary counts artifact-only dirty skips separately');
    $assert(1, (int) ( $plan['summary']['cleanup_buckets']['artifact_only_dirty_worktree'] ?? 0 ), 'cleanup buckets expose artifact-only dirty count');
    $assert(true, in_array('artifact_only_dirty_worktree', array_column($plan['summary']['skipped_next_commands'] ?? array(), 'reason_code'), true), 'summary exposes artifact-only dirty next command');
    $assert(true, isset($plan['summary']['skipped_by_reason']['no_merge_signal']), 'summary includes no_merge_signal bucket');
    $assert(true, (int) ( $plan['summary']['total_size_bytes'] ?? 0 ) > 0, 'summary reports total worktree size bytes');
    $assert(true, (int) ( $plan['summary']['artifact_size_bytes'] ?? 0 ) > 0, 'summary reports artifact size bytes');
    $assert(true, ! empty($plan['summary']['top_by_size']), 'summary reports top worktrees by size');

    echo "\nInventory-only dry-run scenario\n";
    $inventory_ws   = new Cleanup_Inventory_Workspace();
    $inventory_plan = $inventory_ws->worktree_cleanup_merged(
        array(
        'dry_run'        => true,
        'skip_github'    => true,
        'inventory_only' => true,
        )
    );
    $assert(true, ! is_wp_error($inventory_plan) && ( $inventory_plan['success'] ?? false ), 'inventory-only dry_run returns success');
    $assert(true, $inventory_plan['inventory_only'] ?? false, 'inventory-only flag echoes back true');
    $assert(0, $inventory_ws->full_listing_calls, 'inventory-only cleanup does not call full worktree_list');
    $assert_contains($inventory_plan['candidates'] ?? array(), 'demo@inventory-cleanup-eligible', 'inventory-only flags explicit cleanup_eligible worktree');
    $assert_contains($inventory_plan['candidates'] ?? array(), 'demo@inventory-finalized-pr', 'inventory-only flags persisted PR-finalized worktree');
    $assert(2, count($inventory_plan['candidates'] ?? array()), 'inventory-only only returns cheap cleanup signals as candidates');
    $inventory_active = array_values(array_filter($inventory_plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@inventory-active'))[0] ?? array();
    $assert('active_no_signal', $inventory_active['reason_code'] ?? '', 'inventory-only active metadata uses stable active/no-signal reason');
    $inventory_stale = array_values(array_filter($inventory_plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@inventory-stale-lifecycle'))[0] ?? array();
    $assert('lifecycle_reconciliation_candidate', $inventory_stale['reason_code'] ?? '', 'inventory-only stale PR-backed metadata surfaces lifecycle reconciliation candidate');
    $inventory_missing = array_values(array_filter($inventory_plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@inventory-missing-metadata'))[0] ?? array();
    $assert('needs_metadata_reconcile', $inventory_missing['reason_code'] ?? '', 'inventory-only missing metadata requires metadata reconciliation');
    $inventory_buckets = (array) ( $inventory_plan['summary']['cleanup_buckets'] ?? array() );
    $assert(2, (int) ( $inventory_buckets['safe_to_remove_now'] ?? 0 ), 'inventory cleanup bucket counts safe-to-remove candidates');
    $assert(7, (int) ( $inventory_buckets['needs_reconciliation'] ?? 0 ), 'inventory cleanup bucket counts reconciliation candidates separately');
    $assert(4, (int) ( $inventory_buckets['needs_full_review'] ?? 0 ), 'inventory cleanup bucket counts full-review rows separately');
    $assert(0, (int) ( $inventory_buckets['blocked_by_dirty_or_unpushed'] ?? -1 ), 'inventory cleanup bucket keeps dirty/unpushed blockers separate');
    $assert(2, (int) ( $inventory_buckets['explicit_cleanup_candidates'] ?? 0 ), 'inventory cleanup bucket counts explicit cleanup candidates');
    $assert(1, (int) ( $inventory_buckets['lifecycle_reconciliation_candidates'] ?? 0 ), 'inventory cleanup bucket counts lifecycle reconciliation candidates');
    $assert(6, (int) ( $inventory_buckets['metadata_reconciliation_candidates'] ?? 0 ), 'inventory cleanup bucket counts metadata reconciliation candidates');
    $assert(4, (int) ( $inventory_buckets['active_no_signal'] ?? 0 ), 'inventory cleanup bucket counts active/no-signal rows');
    $inventory_apply_hint = (array) ( $inventory_plan['summary']['bounded_cleanup_eligible_apply'] ?? array() );
    $assert('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=2', $inventory_plan['summary']['apply_command'] ?? '', 'inventory-only dry-run exposes bounded cleanup-eligible apply command');
    $assert('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=2 --dry-run', $inventory_apply_hint['review_command'] ?? '', 'inventory-only dry-run exposes symmetric bounded review command');
    $inventory_next_commands = (array) ( $inventory_plan['summary']['skipped_next_commands'] ?? array() );
    $assert(true, count($inventory_next_commands) >= 2, 'inventory-only summary includes actionable skipped commands');
    $assert(true, in_array('needs_metadata_reconcile', array_column($inventory_next_commands, 'reason_code'), true), 'inventory-only skipped commands include metadata reconciliation remediation');
    $assert(true, in_array('lifecycle_reconciliation_candidate', array_column($inventory_next_commands, 'reason_code'), true), 'inventory-only skipped commands include lifecycle reconciliation remediation');
    $assert(true, in_array('active_no_signal', array_column($inventory_next_commands, 'reason_code'), true), 'inventory-only skipped commands include active/no-signal explanation');
    $inventory_apply = $inventory_ws->worktree_cleanup_merged(array( 'inventory_only' => true ));
    $assert(true, is_wp_error($inventory_apply), 'inventory-only cleanup refuses non-dry-run apply path');
    $assert(true, str_contains($inventory_apply->get_error_message(), 'bounded-cleanup-eligible-apply'), 'inventory-only apply refusal points to bounded cleanup-eligible apply');
    $assert(false, str_contains($inventory_apply->get_error_message(), 'run full cleanup'), 'inventory-only apply refusal does not tell users to run full cleanup');

    echo "\nEmergency cleanup dry-run scenario\n";
    $emergency_ws   = new Cleanup_Inventory_Workspace();
    $emergency_plan = $emergency_ws->worktree_emergency_cleanup();
    $assert(true, ! is_wp_error($emergency_plan) && ( $emergency_plan['success'] ?? false ), 'emergency cleanup dry-run returns success');
    $assert('emergency', $emergency_plan['mode'] ?? '', 'emergency cleanup exposes mode');
    $assert(true, $emergency_plan['dry_run'] ?? false, 'emergency cleanup defaults to dry-run');
    $assert(0, $emergency_ws->full_listing_calls, 'emergency cleanup initial plan does not call full worktree_list');
    $assert_contains($emergency_plan['artifact_candidates'] ?? array(), 'demo@merged-autodelete', 'emergency cleanup prioritizes reconstructable artifacts');
    $assert_contains($emergency_plan['worktree_candidates'] ?? array(), 'demo@inventory-cleanup-eligible', 'emergency cleanup includes explicit cleanup-eligible worktrees');
    $assert(1, (int) ( $emergency_plan['summary']['would_remove_worktrees'] ?? 0 ), 'emergency cleanup counts worktree candidates separately');
    $assert(true, (int) ( $emergency_plan['summary']['would_remove_artifacts'] ?? 0 ) > 0, 'emergency cleanup counts artifact candidates separately');
    foreach ( array_merge($emergency_plan['artifact_candidates'] ?? array(), $emergency_plan['worktree_candidates'] ?? array()) as $candidate ) {
        if (! empty($candidate['is_primary']) || 'demo' === ( $candidate['handle'] ?? '' ) ) {
            $failures++;
            $total++;
            echo "  ✗ emergency cleanup listed primary as a candidate\n";
        }
    }

    echo "\nCleanup plan chunk emission scenario\n";
    $cleanup_plan = $ws->workspace_cleanup_plan(array( 'include_resolvers' => true ));
    $assert(true, ! is_wp_error($cleanup_plan) && ( $cleanup_plan['success'] ?? false ), 'cleanup plan freezes successfully');
    $cleanup_plan_again = $ws->workspace_cleanup_plan(array( 'include_resolvers' => true ));
    $assert($cleanup_plan['plan_id'] ?? '', $cleanup_plan_again['plan_id'] ?? '', 'cleanup plan ID is stable for unchanged rows and inputs');
    $artifact_row = $cleanup_plan['rows']['artifact_cleanup'][0] ?? array();
    $assert(true, str_starts_with((string) ( $artifact_row['row_id'] ?? '' ), 'cleanup-row-'), 'artifact cleanup row has stable row ID');
    $assert(true, isset($cleanup_plan['rows']['worktree_removal']), 'cleanup plan includes worktree removal row bucket');
    $assert(true, isset($cleanup_plan['rows']['resolver']), 'cleanup plan includes optional resolver row bucket');
    $chunk_report = $ws->workspace_cleanup_plan_chunks(
        array(
        'plan'       => $cleanup_plan,
        'chunk_size' => 1,
        )
    );
    $assert(true, ! is_wp_error($chunk_report) && ( $chunk_report['success'] ?? false ), 'cleanup chunks emit successfully from frozen plan');
    foreach ( $chunk_report['chunks'] ?? array() as $chunk ) {
        $assert(true, (int) ( $chunk['chunk_size'] ?? 0 ) <= 1, 'chunk size respects configured bound');
        $assert(true, str_starts_with((string) ( $chunk['chunk_id'] ?? '' ), 'cleanup-chunk-'), 'chunk has stable chunk ID');
    }

    $large_rows = array();
    for ( $i = 0; $i < 123; ++$i ) {
        $large_rows[] = array(
        'row_id'       => 'cleanup-row-large-' . $i,
        'row_type'     => 'artifact_cleanup',
        'safety_class' => 'safe',
        'handle'       => 'demo@large-' . $i,
        'repo'         => 'demo',
        'branch'       => 'large-' . $i,
        'path'         => $tmp . '/demo@large-' . $i,
        'artifacts'    => array( array( 'path' => 'target', 'size_bytes' => 1 ) ),
        );
    }
    $large_report = $ws->workspace_cleanup_plan_chunks(
        array(
        'chunk_size' => 10,
        'plan'       => array(
                'plan_id'        => 'cleanup-plan-large',
                'workspace_path' => $tmp,
                'rows'           => array(
                    'artifact_cleanup' => $large_rows,
                    'worktree_removal' => array(),
                    'resolver'         => array(),
                ),
                'summary'        => array(),
        ),
        )
    );
    $assert(13, count($large_report['chunks'] ?? array()), 'large cleanup plan is split into bounded chunks');
    $assert(123, (int) ( $large_report['summary']['rows_by_type']['artifact_cleanup'] ?? 0 ), 'large cleanup chunk summary preserves row count');

    $size_plan = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        'sort'        => 'size',
        )
    );
    $assert(true, ! is_wp_error($size_plan) && ( $size_plan['success'] ?? false ), 'size-sorted dry_run returns success');
    $first_size = (int) ( $size_plan['candidates'][0]['size_bytes'] ?? 0 );
    $second_size = (int) ( $size_plan['candidates'][1]['size_bytes'] ?? 0 );
    $assert(true, $first_size >= $second_size, 'sort=size orders cleanup candidates by size descending');

    $age_plan = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        'older_than'  => '7d',
        )
    );
    $assert(true, ! is_wp_error($age_plan) && ( $age_plan['success'] ?? false ), 'age-filtered dry_run returns success');
    $assert(1, (int) ( $age_plan['summary']['would_remove'] ?? 0 ), 'older_than keeps only old cleanup candidate');
    $assert(1, (int) ( $age_plan['summary']['age_filter']['excluded'] ?? 0 ), 'age filter summary counts newer candidate exclusion');
    $assert(2, (int) ( $age_plan['summary']['age_filter']['unknown_age'] ?? 0 ), 'age filter summary counts unknown-age candidate exclusions');
    $assert_contains($age_plan['candidates'] ?? array(), 'demo@merged-autodelete', 'older_than keeps old merged worktree');
    $recent_age_rows = array_values(array_filter($age_plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@merged-recent'));
    $assert('age_filter', $recent_age_rows[0]['reason_code'] ?? '', 'newer merged worktree is skipped by age_filter');
    $assert('excluded', $recent_age_rows[0]['age_filter']['decision'] ?? '', 'age-filter skip row exposes excluded decision');
    $unknown_age_rows = array_values(array_filter($age_plan['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@merged-unknown-age'));
    $assert('unknown_age', $unknown_age_rows[0]['reason_code'] ?? '', 'missing created_at candidate is skipped as unknown_age when age filter is active');
    $assert('unknown_age', $unknown_age_rows[0]['age_filter']['decision'] ?? '', 'unknown-age skip row exposes age-filter decision');
    $old_age_rows = array_values(array_filter($age_plan['candidates'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@merged-autodelete'));
    $assert('included', $old_age_rows[0]['age_filter']['decision'] ?? '', 'kept candidate exposes included age-filter decision');
    $invalid_age_plan = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        'older_than'  => 'soon',
        )
    );
    $assert(true, is_wp_error($invalid_age_plan), 'invalid older_than duration returns WP_Error');

    class Missing_Metadata_Workspace extends \DataMachineCode\Workspace\Workspace
    {
        public function worktree_list( ?string $repo = null, ?string $state = null, array $opts = array() ): array|\WP_Error  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        {
            return array(
            'success'   => true,
            'worktrees' => array(
            array(
            'handle' => 'broken@metadata',
            'repo'   => '',
            'branch' => '',
            'path'   => '',
            ),
            ),
            );
        }
    }

    $missing_plan = ( new Missing_Metadata_Workspace() )->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        )
    );
    $missing_row = $missing_plan['skipped'][0] ?? array();
    $assert('missing_metadata', $missing_row['reason_code'] ?? '', 'missing metadata exposes stable reason code');
    $assert(array( 'repo', 'branch', 'path' ), $missing_row['missing_fields'] ?? array(), 'missing metadata lists missing fields');
    $assert(true, str_contains($missing_row['hint'] ?? '', 'workspace worktree prune'), 'missing metadata includes prune remediation hint');

    $scope_reflection = new \ReflectionMethod(\DataMachineCode\Workspace\Workspace::class, 'scope_worktree_cleanup_to_plan');
    $scoped_mismatch  = $scope_reflection->invoke(
        $ws,
        array(
        array(
                'handle' => 'demo@merged-autodelete',
                'repo'   => 'demo',
                'branch' => 'merged-autodelete',
                'path'   => $tmp . '/demo@merged-autodelete',
                'signal' => 'pr-merged',
        ),
        ),
        $plan['candidates'] ?? array(),
        $plan['skipped'] ?? array()
    );
    $assert(0, count($scoped_mismatch['candidates'] ?? array()), 'plan mismatch does not leave a removable candidate');
    $assert('plan_mismatch', $scoped_mismatch['skipped'][0]['reason_code'] ?? '', 'plan mismatch reports a stable reason code');

    $scoped_slug_branch = $scope_reflection->invoke(
        $ws,
        array(
        array(
                'handle' => 'demo@fix-foo',
                'repo'   => 'demo',
                'branch' => 'fix-foo',
                'path'   => $tmp . '/demo@fix-foo',
                'signal' => 'pr-merged',
        ),
        ),
        array(
        array(
                'handle' => 'demo@fix-foo',
                'repo'   => 'demo',
                'branch' => 'fix/foo',
                'path'   => $tmp . '/demo@fix-foo',
                'signal' => 'pr-merged',
        ),
        ),
        array()
    );
    $assert(1, count($scoped_slug_branch['candidates'] ?? array()), 'plan revalidation accepts slugged branch matching slash branch');
    $assert(array(), $scoped_slug_branch['skipped'] ?? array(), 'slugged branch match is not reported as plan_mismatch');

    // External worktrees are reported with routing metadata, but never owned by cleanup.
    $external_skips = array_filter($plan['skipped'] ?? array(), fn( $s ) => ( $s['path'] ?? '' ) === $external_real);
    $assert(1, count($external_skips), 'external worktree skipped with exactly one entry');
    $external_skip = array_values($external_skips)[0] ?? array();
    $assert('external_worktree', $external_skip['reason_code'] ?? '', 'external skip has stable reason_code');
    $assert('demo', $external_skip['repo'] ?? '', 'external skip includes owning repo');
    $assert('demo', $external_skip['owning_repo'] ?? '', 'external skip includes owning_repo alias');
    $assert('external-branch', $external_skip['branch'] ?? '', 'external skip includes branch');
    $assert($primary_real, $external_skip['primary_path'] ?? '', 'external skip includes primary repo path');
    $assert(true, str_contains($external_skip['hint'] ?? '', 'owning tool'), 'external skip includes remediation hint');

    // Primary itself should NEVER show up as a candidate.
    foreach ( $plan['candidates'] ?? array() as $c ) {
        if ('demo' === ( $c['handle'] ?? '' ) ) {
            $failures++;
            $total++;
            echo "  ✗ primary listed as cleanup candidate (BUG — would destroy primary)\n";
        }
    }

    // -------------------------------------------------------------------------
    // Execute
    // -------------------------------------------------------------------------

    // The reviewed plan still contains this row, but the worktree changes before
    // apply. Plan application must re-run the dirty gate and leave it in place.
    file_put_contents($tmp . '/demo@merged-stale-plan/late-dirty.txt', 'changed after plan');

    echo "\nExecuting cleanup from reviewed plan\n";
    $result = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => false,
        'skip_github' => true,
        'apply_plan'  => $plan,
        )
    );

    $assert(true, ! is_wp_error($result) && ( $result['success'] ?? false ), 'execute returns success');
    $assert(false, $result['dry_run'] ?? true, 'dry_run flag is false on execute');

    // The merged-autodelete candidate should be in removed[].
    $assert_contains($result['removed'] ?? array(), 'demo@merged-autodelete', 'canonical merged worktree was actually removed');
    $assert_contains($result['removed'] ?? array(), 'demo@merged-recent', 'recent merged worktree was actually removed without age filter');
    $assert_contains($result['removed'] ?? array(), 'demo@merged-unknown-age', 'unknown-age merged worktree was actually removed without age filter');

    $stale_skips = array_values(array_filter($result['skipped'] ?? array(), fn( $s ) => ( $s['handle'] ?? '' ) === 'demo@merged-stale-plan'));
    $assert(1, count($stale_skips), 'stale plan row is skipped after revalidation');
    $assert('dirty_worktree', $stale_skips[0]['reason_code'] ?? '', 'stale plan row reuses dirty safety gate');

    // Directory should be gone from disk.
    $assert(false, is_dir($tmp . '/demo@merged-autodelete'), 'merged worktree directory no longer exists on disk');
    $assert(false, is_dir($tmp . '/demo@merged-recent'), 'recent merged worktree directory no longer exists on disk');
    $assert(false, is_dir($tmp . '/demo@merged-unknown-age'), 'unknown-age merged worktree directory no longer exists on disk');

    // Primary survives.
    $assert(true, is_dir($primary . '/.git'), 'primary .git survives cleanup');

    // Protected branches: unmerged/dirty worktrees survive.
    $assert(true, is_dir($tmp . '/demo@unmerged-feature'), 'unmerged worktree survives cleanup');
    $assert(true, is_dir($tmp . '/demo@dirty-branch'), 'dirty worktree survives cleanup');
    $assert(true, is_dir($tmp . '/demo@merged-stale-plan'), 'dirty stale-plan worktree survives cleanup');
    $assert(true, is_dir($tmp . '-external/demo-external'), 'external worktree survives cleanup');

    // The local branch for the removed worktree should be gone.
    $branch_check = $run('git for-each-ref --format="%(refname:short)" refs/heads/merged-autodelete', $primary);
    $assert('', trim($branch_check['output']), 'local branch for removed worktree is deleted');

    // -------------------------------------------------------------------------
    // Negative: primary safety
    // -------------------------------------------------------------------------

    echo "\nSafety — primary never removed\n";

    // Verify validate_containment catches a primary being passed in.
    $reflection = new \ReflectionMethod(\DataMachineCode\Workspace\Workspace::class, 'remove_worktree_by_path');
    $safety = $reflection->invoke($ws, 'demo', 'main', $primary, false);
    $is_error = is_wp_error($safety);
    $assert(true, $is_error, 'remove_worktree_by_path refuses primary (is WP_Error)');
    if ($is_error ) {
        $assert(true, str_contains($safety->get_error_message(), 'not a worktree marker') || str_contains($safety->get_error_message(), 'primary'), 'rejection message identifies primary-like target');
    }

    // -------------------------------------------------------------------------
    // Negative: path outside workspace
    // -------------------------------------------------------------------------

    $outside = sys_get_temp_dir() . '/dmc-outside-' . bin2hex(random_bytes(3));
    mkdir($outside, 0755, true);
    file_put_contents($outside . '/.git', 'gitdir: fake');
    $outside_err = $reflection->invoke($ws, 'demo', 'x', $outside, false);
    $assert(true, is_wp_error($outside_err), 'path outside workspace rejected (is WP_Error)');
    if (is_wp_error($outside_err) ) {
        $assert(true, str_contains($outside_err->get_error_message(), 'outside workspace') || str_contains($outside_err->get_error_message(), 'traversal'), 'rejection message mentions containment');
    }
    exec('rm -rf ' . escapeshellarg($outside));

    echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
