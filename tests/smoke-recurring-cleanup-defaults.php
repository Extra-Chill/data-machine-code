<?php
/**
 * Smoke test for DMC recurring cleanup schedule defaults.
 *
 *   php tests/smoke-recurring-cleanup-defaults.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('WPINC') ) {
        define('WPINC', __DIR__);
    }
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__);
    }

    function datamachine_code_recurring_defaults_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    echo "=== smoke-recurring-cleanup-defaults ===\n";

    $plugin = (string) file_get_contents(dirname(__DIR__) . '/data-machine-code.php');
    $retention_start = strpos($plugin, "\$schedules['workspace_retention_cleanup']");
    $retention_end   = strpos($plugin, "\$schedules['workspace_disk_emergency_cleanup']", (int) $retention_start);
    $direct_start    = strpos($plugin, "\$schedules['worktree_cleanup']");
    $direct_end      = strpos($plugin, "\$schedules['workspace_retention_cleanup']", (int) $direct_start);
    $retention       = false === $retention_start || false === $retention_end ? '' : substr($plugin, $retention_start, $retention_end - $retention_start);
    $direct          = false === $direct_start || false === $direct_end ? '' : substr($plugin, $direct_start, $direct_end - $direct_start);

    datamachine_code_recurring_defaults_assert(str_contains($retention, "'default_enabled' => true"), 'retention cleanup schedule defaults on');
    datamachine_code_recurring_defaults_assert(str_contains($retention, 'WorkspaceRetentionCleanupTask::SETTING_KEY'), 'retention schedule uses retention setting key');
    datamachine_code_recurring_defaults_assert(str_contains($retention, "'worktree_older_than' => '14d'"), 'retention cleanup remains age-gated');
    datamachine_code_recurring_defaults_assert(str_contains($retention, "'skip_github'         => true"), 'retention cleanup stays local-signal only by default');
    datamachine_code_recurring_defaults_assert(str_contains($retention, "'artifact_cleanup'    => true"), 'retention cleanup includes bounded artifact cleanup');
    datamachine_code_recurring_defaults_assert(str_contains($direct, "'default_enabled' => false"), 'direct worktree cleanup remains opt-in');

    echo "\nAll recurring cleanup default smoke tests passed.\n";
}
