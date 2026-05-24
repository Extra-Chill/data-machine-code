<?php
/**
 * Smoke test for the `merged_pr_with_only_obsolete_dirty_changes` classifier.
 *
 * Builds a real workspace where:
 *   - A branch was merged (simulated by deleting the remote branch + a
 *     `local-merged` shape where the branch is contained in origin/HEAD).
 *   - The worktree has dirty edits to a file that the default branch tip no
 *     longer has.
 *   - A second branch is merged but has dirty edits to a file the default
 *     branch still tracks (must NOT classify into the new bucket).
 *   - A third branch is merged but the dirty entry is untracked (must NOT
 *     classify into the new bucket).
 *
 * Asserts the cleanup dry-run reports the new reason code only for the first
 * worktree, leaves the other two on the generic `dirty_worktree` bucket, and
 * never auto-removes any of them.
 *
 * Run: php tests/smoke-worktree-cleanup-merged-obsolete-dirty.php
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
            public function get_error_code(): string
            {
                return $this->code;
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

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
    include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

    exec('git --version 2>&1', $_gv, $gv_exit);
    if (0 !== $gv_exit ) {
        echo "SKIP: git not available\n";
        exit(0);
    }

    $tmp = sys_get_temp_dir() . '/dmc-merged-obsolete-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    register_shutdown_function(
        function () use ( $tmp ) {
            if (is_dir($tmp) ) {
                exec('rm -rf ' . escapeshellarg($tmp));
            }
        } 
    );

    define('DATAMACHINE_WORKSPACE_PATH', realpath($tmp) ? realpath($tmp) : $tmp);

    $failures = 0;
    $total    = 0;
    $datamachine_code_test_options = array();

    $assert = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
        ++$total;
        if ($expected === $actual ) {
            echo "  ✓ {$message}\n";
            return;
        }
        ++$failures;
        echo "  ✗ {$message}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    };

    $run = function ( string $cmd, string $cwd = '' ) {
        $full = '' === $cwd ? $cmd : sprintf('cd %s && %s', escapeshellarg($cwd), $cmd);
        exec($full . ' 2>&1', $out, $rc);
        return array(
        'output' => implode("\n", $out),
        'exit'   => $rc,
        );
    };

    echo "Setting up workspace at {$tmp}\n";

    // Bare remote stands in for origin.
    $remote = $tmp . '/remote.git';
    $run(sprintf('git init --bare %s', escapeshellarg($remote)));

    $primary = $tmp . '/demo';
    $run(sprintf('git clone %s %s', escapeshellarg($remote), escapeshellarg($primary)));
    $run('git config user.email test@example.com', $primary);
    $run('git config user.name test', $primary);

    // Initial main with two tracked files. `obsolete.txt` will be removed
    // from main later; `survivor.txt` stays on main throughout.
    file_put_contents($primary . '/README.md', "demo\n");
    file_put_contents($primary . '/obsolete.txt', "removed-on-default\n");
    file_put_contents($primary . '/survivor.txt', "stays-on-default\n");
    $run('git add README.md obsolete.txt survivor.txt && git commit -m init', $primary);
    $run('git branch -M main', $primary);
    $run('git push -u origin main', $primary);

    // Branch 1: merged + dirty edit only touches an obsolete-on-default path.
    $run('git checkout -b merged-obsolete-edits', $primary);
    $run('git push -u origin merged-obsolete-edits', $primary);
    $run('git checkout main', $primary);

    // Branch 2: merged + dirty edit touches a file the default branch still has.
    $run('git checkout -b merged-survivor-edits', $primary);
    $run('git push -u origin merged-survivor-edits', $primary);
    $run('git checkout main', $primary);

    // Branch 3: merged + dirty edit is an untracked file.
    $run('git checkout -b merged-untracked-dirty', $primary);
    $run('git push -u origin merged-untracked-dirty', $primary);
    $run('git checkout main', $primary);

    // Now remove `obsolete.txt` from main so the default branch tip no
    // longer has it. This is the "edits already represented by default" shape
    // the new classifier is meant to recognize.
    $run('git rm obsolete.txt && git commit -m "remove obsolete file" && git push origin main', $primary);

    // Worktrees on each merged branch.
    $run(sprintf('git worktree add %s merged-obsolete-edits', escapeshellarg($tmp . '/demo@merged-obsolete-edits')), $primary);
    $run(sprintf('git worktree add %s merged-survivor-edits', escapeshellarg($tmp . '/demo@merged-survivor-edits')), $primary);
    $run(sprintf('git worktree add %s merged-untracked-dirty', escapeshellarg($tmp . '/demo@merged-untracked-dirty')), $primary);

    // Branch 1 worktree: modify the file that no longer exists on main.
    file_put_contents($tmp . '/demo@merged-obsolete-edits/obsolete.txt', "edited-on-stale-branch\n");

    // Branch 2 worktree: modify a file main still has.
    file_put_contents($tmp . '/demo@merged-survivor-edits/survivor.txt', "edited-on-stale-branch\n");

    // Branch 3 worktree: only an untracked file dirty.
    file_put_contents($tmp . '/demo@merged-untracked-dirty/scratch.log', "scratch\n");

    // Simulate "remote auto-deleted these branches after merge". With the
    // remote refs gone, the local upstream tracking ref will be `gone` and
    // `detect_merge_signal` reports `upstream-gone`. `local-merged` would
    // also fire since each branch is fully contained in origin/main.
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-obsolete-edits', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-survivor-edits', escapeshellarg($remote)));
    $run(sprintf('git --git-dir=%s update-ref -d refs/heads/merged-untracked-dirty', escapeshellarg($remote)));

    echo "\nDry-run scenario\n";
    $ws   = new \DataMachineCode\Workspace\Workspace();
    $plan = $ws->worktree_cleanup_merged(
        array(
        'dry_run'     => true,
        'skip_github' => true,
        )
    );

    $assert(true, ! is_wp_error($plan) && ( $plan['success'] ?? false ), 'dry-run returns success');

    $find = function ( string $handle ) use ( $plan ): array {
        foreach ( (array) ( $plan['skipped'] ?? array() ) as $row ) {
            if (( $row['handle'] ?? '' ) === $handle ) {
                return $row;
            }
        }
        return array();
    };

    $obsolete_row  = $find('demo@merged-obsolete-edits');
    $survivor_row  = $find('demo@merged-survivor-edits');
    $untracked_row = $find('demo@merged-untracked-dirty');

    $assert(
        'merged_pr_with_only_obsolete_dirty_changes',
        $obsolete_row['reason_code'] ?? '',
        'merged worktree with only obsolete dirty edits classifies into new bucket'
    );
    $assert(true, 1 === (int) ( $obsolete_row['dirty'] ?? 0 ), 'classified row preserves dirty file count');
    $assert(
        true,
        isset($obsolete_row['dirty_obsolete_paths']['obsolete.txt'])
        && 'absent_on_default' === $obsolete_row['dirty_obsolete_paths']['obsolete.txt'],
        'classified row enumerates the obsolete-on-default dirty path'
    );
    $assert(
        true,
        in_array($obsolete_row['merge_signal'] ?? '', array( 'upstream-gone', 'local-merged', 'pr-merged' ), true),
        'classified row records the merge signal that fired'
    );
    $assert(
        true,
        isset($obsolete_row['default_ref']) && '' !== (string) $obsolete_row['default_ref'],
        'classified row records the default branch ref used'
    );
    $assert(
        true,
        isset($obsolete_row['hint']) && str_contains((string) $obsolete_row['hint'], 'force=true'),
        'classified row hints at the force-cleanup follow-up'
    );

    $assert(
        'dirty_worktree',
        $survivor_row['reason_code'] ?? '',
        'merged worktree with default-tracked dirty edit stays on generic dirty_worktree bucket'
    );

    $assert(
        'dirty_worktree',
        $untracked_row['reason_code'] ?? '',
        'merged worktree with only untracked dirty entries stays on generic dirty_worktree bucket'
    );

    $summary = (array) ( $plan['summary'] ?? array() );
    $by_reason = (array) ( $summary['skipped_by_reason'] ?? array() );
    $assert(
        1,
        (int) ( $by_reason['merged_pr_with_only_obsolete_dirty_changes'] ?? 0 ),
        'summary aggregates new bucket count'
    );
    $assert(
        2,
        (int) ( $by_reason['dirty_worktree'] ?? 0 ),
        'summary still counts genuinely-risky dirty worktrees separately'
    );

    // Confirm cleanup never auto-removes any of the three dirty worktrees on
    // a non-force apply path.
    $candidates = (array) ( $plan['candidates'] ?? array() );
    foreach ( array( 'demo@merged-obsolete-edits', 'demo@merged-survivor-edits', 'demo@merged-untracked-dirty' ) as $handle ) {
        $is_candidate = false;
        foreach ( $candidates as $candidate ) {
            if (( $candidate['handle'] ?? '' ) === $handle ) {
                $is_candidate = true;
                break;
            }
        }
        $assert(false, $is_candidate, sprintf('%s never appears as an auto-removable candidate', $handle));
    }

    echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
    exit($failures > 0 ? 1 : 0);
}
