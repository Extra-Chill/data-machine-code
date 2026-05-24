<?php
/**
 * Smoke test for artifact-only worktree cleanup.
 *
 *   php tests/smoke-worktree-cleanup-artifacts.php
 *
 * @package DataMachineCode\Tests
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
    if (! class_exists(__NAMESPACE__ . '\\GitHubAbilities') ) {
        class GitHubAbilities
        {
            public static function getPat(): string
            {
                return '';
            }
            public static function apiGet( string $url, array $params, string $pat )  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
            {
                return array( 'data' => array() );
            }
        }
    }
}

namespace {
    $tmp      = sys_get_temp_dir() . '/dmc-artifact-cleanup-smoke-' . bin2hex(random_bytes(4));
    $site_tmp = $tmp . '-site';
    mkdir($tmp, 0755, true);
    mkdir($site_tmp . '/wp-content/plugins', 0755, true);
    mkdir($site_tmp . '/wp-content/themes', 0755, true);

    register_shutdown_function(
        function () use ( $tmp, $site_tmp ) {
            foreach ( array( $tmp, $site_tmp ) as $path ) {
                if (is_dir($path) ) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                    exec('rm -rf ' . escapeshellarg($path));
                }
            }
        } 
    );

    if (! defined('ABSPATH') ) {
        define('ABSPATH', $site_tmp . '/');
    }
    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', realpath($tmp) ?: $tmp);
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

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
    exec('git --version 2>&1', $_gv, $gv_exit);
    if (0 !== $gv_exit ) {
        echo "SKIP: git not available\n";
        exit(0);
    }

    $failures = 0;
    $total    = 0;
    $datamachine_code_test_options = array();

    $assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
        ++$total;
        if ($expected === $actual ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        ++$failures;
        echo "  [FAIL] {$message}\n";
        echo '    expected: ' . var_export($expected, true) . "\n";
        echo '    actual:   ' . var_export($actual, true) . "\n";
    };

    $run = function ( string $cmd, string $cwd = '' ): void {
        $full = '' === $cwd ? $cmd : sprintf('cd %s && %s', escapeshellarg($cwd), $cmd);
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec($full . ' 2>&1', $out, $rc);
        if (0 !== $rc ) {
            throw new RuntimeException("Command failed: {$full}\n" . implode("\n", $out));
        }
    };

    $remote = $tmp . '/remote.git';
    $run(sprintf('git init --bare %s', escapeshellarg($remote)));

    $primary = $tmp . '/demo';
    $run(sprintf('git clone %s %s', escapeshellarg($remote), escapeshellarg($primary)));
    $run('git config user.email test@example.com', $primary);
    $run('git config user.name test', $primary);
    file_put_contents($primary . '/README.md', "demo\n");
    file_put_contents($primary . '/Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
    file_put_contents($primary . '/.gitignore', "target/\n");
    $run('git add README.md Cargo.toml .gitignore && git commit -m init', $primary);
    $run('git branch -M main', $primary);
    $run('git push -u origin main', $primary);

    $make_branch = function ( string $branch ) use ( $primary, $run ): void {
        $run(sprintf('git checkout -b %s', escapeshellarg($branch)), $primary);
        file_put_contents($primary . '/' . $branch . '.txt', $branch);
        $run(sprintf('git add . && git commit -m %s', escapeshellarg('work ' . $branch)), $primary);
        $run(sprintf('git push -u origin %s', escapeshellarg($branch)), $primary);
        $run('git checkout main', $primary);
    };

    foreach ( array( 'clean', 'dirty', 'unpushed', 'active' ) as $branch ) {
        $make_branch($branch);
        $run(sprintf('git worktree add %s %s', escapeshellarg($tmp . '/demo@' . $branch), escapeshellarg($branch)), $primary);
        mkdir($tmp . '/demo@' . $branch . '/target', 0755, true);
        file_put_contents($tmp . '/demo@' . $branch . '/target/artifact.bin', str_repeat($branch, 128));
    }

    file_put_contents($tmp . '/demo@dirty/scratch.txt', 'dirty');
    file_put_contents($tmp . '/demo@unpushed/local.txt', 'local');
    $run('git add local.txt && git commit -m local', $tmp . '/demo@unpushed');
    symlink($tmp . '/demo@active', $site_tmp . '/wp-content/plugins/demo-active');

    $workspace = new \DataMachineCode\Workspace\Workspace();

    echo "=== smoke-worktree-cleanup-artifacts ===\n";

    // Bounded default dry-run: cheap inventory + artifact detection, no
    // per-worktree safety probes. Dirty/unpushed worktrees still surface as
    // candidates with `safety_probes_deferred=true` so the apply path can
    // revalidate them before deletion.
    $plan = $workspace->worktree_cleanup_artifacts(array( 'dry_run' => true ));
    $assert(false, is_wp_error($plan), 'bounded dry-run returns a plan');
    $assert(true, (bool) ( $plan['dry_run'] ?? false ), 'bounded dry-run flag is true');
    $assert(true, isset($plan['pagination']), 'bounded dry-run includes pagination envelope');
    $assert('bounded_inventory', $plan['pagination']['mode'] ?? '', 'bounded dry-run advertises bounded_inventory mode');
    $assert(false, (bool) ( $plan['pagination']['safety_probes'] ?? true ), 'bounded dry-run reports safety_probes=false');
    $assert(true, (bool) ( $plan['pagination']['complete'] ?? false ), 'bounded dry-run completes when total <= limit');

    $bounded_skip_reasons = array_column($plan['skipped'] ?? array(), 'reason_code', 'handle');
    $assert('active_symlink_target', $bounded_skip_reasons['demo@active'] ?? '', 'active plugin symlink target is protected even in bounded mode');

    $bounded_handles = array_column($plan['candidates'] ?? array(), 'handle');
    $assert(true, in_array('demo@clean', $bounded_handles, true), 'bounded dry-run surfaces clean worktree');
    $assert(true, in_array('demo@dirty', $bounded_handles, true), 'bounded dry-run surfaces dirty worktree (deferred safety probes)');
    $assert(true, in_array('demo@unpushed', $bounded_handles, true), 'bounded dry-run surfaces unpushed worktree (deferred safety probes)');
    foreach ( $plan['candidates'] ?? array() as $candidate ) {
        $assert(true, (bool) ( $candidate['safety_probes_deferred'] ?? false ), 'bounded candidate ' . ( $candidate['handle'] ?? '?' ) . ' is flagged safety_probes_deferred');
    }
    $assert(true, is_dir($tmp . '/demo@clean/target'), 'bounded dry-run does not delete target directory');

    // Exhaustive dry-run: full safety probes — restores the historical strict
    // view where dirty/unpushed worktrees are skipped at dry-run time.
    $exhaustive_plan = $workspace->worktree_cleanup_artifacts(array( 'dry_run' => true, 'exhaustive' => true ));
    $assert(false, is_wp_error($exhaustive_plan), 'exhaustive dry-run returns a plan');
    $assert('exhaustive', $exhaustive_plan['pagination']['mode'] ?? '', 'exhaustive dry-run advertises exhaustive mode');
    $assert(true, (bool) ( $exhaustive_plan['pagination']['safety_probes'] ?? false ), 'exhaustive dry-run reports safety_probes=true');
    $assert(1, count($exhaustive_plan['candidates'] ?? array()), 'exhaustive dry-run skips dirty/unpushed worktrees');
    $assert('demo@clean', $exhaustive_plan['candidates'][0]['handle'] ?? '', 'exhaustive clean worktree is candidate');
    $assert('target', $exhaustive_plan['candidates'][0]['artifacts'][0]['path'] ?? '', 'exhaustive candidate artifact path comes from profile');

    $skip_reasons = array_column($exhaustive_plan['skipped'] ?? array(), 'reason_code', 'handle');
    $assert('dirty_worktree', $skip_reasons['demo@dirty'] ?? '', 'exhaustive dirty worktree is protected');
    $assert('unpushed_commits', $skip_reasons['demo@unpushed'] ?? '', 'exhaustive unpushed worktree is protected');
    $assert('active_symlink_target', $skip_reasons['demo@active'] ?? '', 'exhaustive active plugin symlink target is protected');

    // Pagination smoke: limit=1 returns a partial scan with continuation.
    $page_one = $workspace->worktree_cleanup_artifacts(array( 'dry_run' => true, 'limit' => 1, 'offset' => 0 ));
    $assert(false, is_wp_error($page_one), 'page-1 dry-run succeeds');
    $assert(1, (int) ( $page_one['pagination']['scanned'] ?? 0 ), 'page-1 scanned exactly one worktree');
    $assert(true, (bool) ( $page_one['pagination']['partial'] ?? false ), 'page-1 reports partial=true');
    $assert(false, (bool) ( $page_one['pagination']['complete'] ?? true ), 'page-1 reports complete=false');
    $assert(1, (int) ( $page_one['pagination']['next_offset'] ?? 0 ), 'page-1 next_offset advances by limit');

    $page_two = $workspace->worktree_cleanup_artifacts(array( 'dry_run' => true, 'limit' => 1, 'offset' => (int) $page_one['pagination']['next_offset'] ));
    $assert(1, (int) ( $page_two['pagination']['offset'] ?? 0 ), 'page-2 offset honored');
    $assert(1, (int) ( $page_two['pagination']['scanned'] ?? 0 ), 'page-2 scanned exactly one worktree');

    $direct_apply = $workspace->worktree_cleanup_artifacts(array());
    $assert(true, is_wp_error($direct_apply), 'direct apply without plan is rejected');
    $assert('artifact_cleanup_plan_required', $direct_apply->code ?? '', 'direct apply error is explicit');

    // Build a stricter plan from the exhaustive scan for precise apply-shape
    // assertions. This keeps the source-file-mismatch test deterministic
    // (the bounded plan now contains dirty/unpushed rows that revalidation
    // would skip first, before reaching the artifact mismatch row).
    $strict_plan = $exhaustive_plan;
    $source_plan = $strict_plan;
    $source_plan['candidates'][0]['artifacts'] = array( array( 'path' => 'README.md', 'size_bytes' => 4 ) );
    $source_apply = $workspace->worktree_cleanup_artifacts(array( 'apply_plan' => $source_plan ));
    $assert(false, is_wp_error($source_apply), 'source-file-shaped artifact plan returns a report, not deletion');
    $source_apply_skip_reasons = array_column($source_apply['skipped'] ?? array(), 'reason_code', 'handle');
    $assert('artifact_plan_mismatch', $source_apply_skip_reasons['demo@clean'] ?? '', 'source-file path is rejected by profile revalidation');
    $assert(true, is_file($tmp . '/demo@clean/README.md'), 'source file remains after mismatched plan');
    $assert(true, is_dir($tmp . '/demo@clean/target'), 'real artifact remains after mismatched plan');

    // Apply a bounded-mode plan: revalidation re-runs safety probes for the
    // planned subset only, so dirty/unpushed candidates from the bounded view
    // surface as proper skips (not silent removals).
    $apply = $workspace->worktree_cleanup_artifacts(array( 'apply_plan' => $plan ));
    $assert(false, is_wp_error($apply), 'apply-plan returns report');
    $assert(false, (bool) ( $apply['dry_run'] ?? true ), 'apply-plan is destructive mode');
    $assert(1, (int) ( $apply['summary']['removed_artifacts'] ?? 0 ), 'apply-plan from bounded plan only removes safe-revalidated rows');
    $assert(false, is_dir($tmp . '/demo@clean/target'), 'apply-plan removes clean artifact directory');
    $assert(true, is_dir($tmp . '/demo@dirty/target'), 'apply-plan revalidation skips dirty worktree even when bounded plan flagged it');
    $assert(true, is_dir($tmp . '/demo@unpushed/target'), 'apply-plan revalidation skips unpushed worktree even when bounded plan flagged it');
    $assert(true, is_dir($tmp . '/demo@clean'), 'apply-plan leaves worktree directory in place');

    $apply_skip_reasons = array_column($apply['skipped'] ?? array(), 'reason_code', 'handle');
    $assert('dirty_worktree', $apply_skip_reasons['demo@dirty'] ?? '', 'apply-plan revalidation skips dirty rows with explicit reason');
    $assert('unpushed_commits', $apply_skip_reasons['demo@unpushed'] ?? '', 'apply-plan revalidation skips unpushed rows with explicit reason');

    $force_plan = $workspace->worktree_cleanup_artifacts(array( 'dry_run' => true, 'exhaustive' => true, 'force' => true ));
    $force_handles = array_column($force_plan['candidates'] ?? array(), 'handle');
    $assert(true, in_array('demo@dirty', $force_handles, true), 'force permits dirty artifact candidate (exhaustive)');
    $assert(true, in_array('demo@unpushed', $force_handles, true), 'force permits unpushed artifact candidate (exhaustive)');
    $force_skip_reasons = array_column($force_plan['skipped'] ?? array(), 'reason_code', 'handle');
    $assert('active_symlink_target', $force_skip_reasons['demo@active'] ?? '', 'force still protects active symlink target');

    if ($failures > 0 ) {
        echo "\nFAILURES: {$failures}/{$total}\n";
        exit(1);
    }

    echo "\nAll {$total} artifact cleanup smoke assertions passed.\n";
}
