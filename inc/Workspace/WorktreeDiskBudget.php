<?php
/**
 * Worktree Disk Budget
 *
 * Cheap pre-create guardrails for workspace worktree growth.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeDiskBudget
{

    private const BYTES_PER_GIB = 1073741824;

    /**
     * Default warning threshold: 20 GiB free.
     */
    private const DEFAULT_WARN_FREE_GIB = 20;

    /**
     * Default refusal threshold: 10 GiB free.
     */
    private const DEFAULT_REFUSE_FREE_GIB = 10;

    /**
     * Default warning threshold: 15% free.
     */
    private const DEFAULT_WARN_FREE_PERCENT = 15.0;

    /**
     * Default refusal threshold: 10% free.
     */
    private const DEFAULT_REFUSE_FREE_PERCENT = 10.0;

    /**
     * Default worktree-count warning threshold.
     */
    private const DEFAULT_WARN_WORKTREE_COUNT = 100;

    /**
     * Inspect workspace disk budget without walking file contents.
     *
     * @param  string $workspace_path Workspace root path.
     * @param  array  $thresholds     Optional threshold override for tests.
     * @param  bool   $forced         Whether the caller explicitly forced creation.
     * @return array<string,mixed>
     */
    public static function inspect( string $workspace_path, array $thresholds = array(), bool $forced = false ): array
    {
        $thresholds  = self::normalize_thresholds($thresholds);
        $free_bytes  = is_dir($workspace_path) ? disk_free_space($workspace_path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_free_space
        $total_bytes = is_dir($workspace_path) ? disk_total_space($workspace_path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_total_space
        $free_bytes  = is_float($free_bytes) ? (int) $free_bytes : null;
        $total_bytes = is_float($total_bytes) ? (int) $total_bytes : null;
        $worktrees   = self::count_worktree_like_dirs($workspace_path);

        return self::evaluate(
            array(
            'workspace_path' => $workspace_path,
            'free_bytes'     => $free_bytes,
            'total_bytes'    => $total_bytes,
            'worktree_count' => $worktrees,
            ),
            $thresholds,
            $forced
        );
    }

    /**
     * Evaluate disk-budget status from already-measured values.
     *
     * @param  array $metrics    Measured values.
     * @param  array $thresholds Threshold values.
     * @param  bool  $forced     Whether the caller explicitly forced creation.
     * @return array<string,mixed>
     */
    public static function evaluate( array $metrics, array $thresholds = array(), bool $forced = false ): array
    {
        $thresholds   = self::normalize_thresholds($thresholds);
        $free_bytes   = isset($metrics['free_bytes']) && is_numeric($metrics['free_bytes']) ? (int) $metrics['free_bytes'] : null;
        $total_bytes  = isset($metrics['total_bytes']) && is_numeric($metrics['total_bytes']) ? (int) $metrics['total_bytes'] : null;
        $free_percent = null;
        if (null !== $free_bytes && null !== $total_bytes && $total_bytes > 0 ) {
            $free_percent = ( $free_bytes / $total_bytes ) * 100;
        }
        $count    = isset($metrics['worktree_count']) && is_numeric($metrics['worktree_count']) ? (int) $metrics['worktree_count'] : 0;
        $warnings = array();
        $refused  = false;

        $effective_refuse_bytes = $thresholds['refuse_free_bytes'];
        $effective_warn_bytes   = $thresholds['warn_free_bytes'];
        if (null !== $total_bytes && $total_bytes > 0 ) {
            $effective_refuse_bytes = max($effective_refuse_bytes, (int) ceil($total_bytes * ( $thresholds['refuse_free_percent'] / 100 )));
            $effective_warn_bytes   = max($effective_warn_bytes, (int) ceil($total_bytes * ( $thresholds['warn_free_percent'] / 100 )));
        }

        if (null !== $free_bytes ) {
            if ($free_bytes < $effective_refuse_bytes ) {
                $refused    = ! $forced;
                $warnings[] = sprintf(
                    'Free disk space is %.1f GiB%s, below the refusal threshold of %.1f GiB or %.1f%% free, whichever is stricter.',
                    self::bytes_to_gib($free_bytes),
                    null === $free_percent ? '' : sprintf(' (%.1f%%)', $free_percent),
                    self::bytes_to_gib($thresholds['refuse_free_bytes']),
                    $thresholds['refuse_free_percent']
                );
            } elseif ($free_bytes < $effective_warn_bytes ) {
                $warnings[] = sprintf(
                    'Free disk space is %.1f GiB%s, below the warning threshold of %.1f GiB or %.1f%% free, whichever is stricter.',
                    self::bytes_to_gib($free_bytes),
                    null === $free_percent ? '' : sprintf(' (%.1f%%)', $free_percent),
                    self::bytes_to_gib($thresholds['warn_free_bytes']),
                    $thresholds['warn_free_percent']
                );
            }
        } else {
            $warnings[] = 'Free disk space could not be measured.';
        }

        if ($count > $thresholds['warn_worktree_count'] ) {
            $warnings[] = sprintf(
                'Workspace has %d worktree-like directories, above the %d warning threshold.',
                $count,
                $thresholds['warn_worktree_count']
            );
        }

        $status          = $refused ? 'refused' : ( empty($warnings) ? 'ok' : 'warning' );
        $trigger_reasons = array();
        if (null !== $free_bytes && $free_bytes < $effective_refuse_bytes ) {
            $trigger_reasons[] = 'free_space_refusal_threshold';
        } elseif (null !== $free_bytes && $free_bytes < $effective_warn_bytes ) {
            $trigger_reasons[] = 'free_space_warning_threshold';
        }
        if ($count > $thresholds['warn_worktree_count'] ) {
            $trigger_reasons[] = 'worktree_count_warning_threshold';
        }

        return array(
        'workspace_path'            => (string) ( $metrics['workspace_path'] ?? '' ),
        'free_bytes'                => $free_bytes,
        'free_gib'                  => null === $free_bytes ? null : round(self::bytes_to_gib($free_bytes), 2),
        'total_bytes'               => $total_bytes,
        'total_gib'                 => null === $total_bytes ? null : round(self::bytes_to_gib($total_bytes), 2),
        'free_percent'              => null === $free_percent ? null : round($free_percent, 2),
        'workspace_size_bytes'      => null,
        'workspace_size_exact'      => false,
        'worktree_count'            => $count,
        'warn_free_bytes'           => $thresholds['warn_free_bytes'],
        'warn_free_gib'             => round(self::bytes_to_gib($thresholds['warn_free_bytes']), 2),
        'warn_free_percent'         => $thresholds['warn_free_percent'],
        'refuse_free_bytes'         => $thresholds['refuse_free_bytes'],
        'refuse_free_gib'           => round(self::bytes_to_gib($thresholds['refuse_free_bytes']), 2),
        'refuse_free_percent'       => $thresholds['refuse_free_percent'],
        'effective_refuse_bytes'    => $effective_refuse_bytes,
        'effective_refuse_gib'      => round(self::bytes_to_gib($effective_refuse_bytes), 2),
        'effective_warn_bytes'      => $effective_warn_bytes,
        'effective_warn_gib'        => round(self::bytes_to_gib($effective_warn_bytes), 2),
        'warn_worktree_count'       => $thresholds['warn_worktree_count'],
        'forced'                    => $forced,
        'status'                    => $status,
        'warnings'                  => $warnings,
        'emergency_triggered'       => array() !== $trigger_reasons,
        'trigger_reasons'           => $trigger_reasons,
        'cleanup_dry_run_command'   => 'studio wp datamachine-code workspace worktree cleanup --dry-run',
        'artifact_cleanup_command'  => 'studio wp datamachine-code workspace worktree cleanup-artifacts --dry-run',
        'emergency_cleanup_command' => 'studio wp datamachine-code workspace worktree emergency-cleanup --format=json',
        'force_override_required'   => $refused,
        'force_override_applied'    => $forced && ! empty($warnings),
        );
    }

    /**
     * Get filterable thresholds.
     *
     * @param  string $repo   Repository name.
     * @param  string $branch Branch name.
     * @return array<string,int|float>
     */
    public static function thresholds( string $repo, string $branch ): array
    {
        $thresholds = array(
        'warn_free_bytes'     => self::DEFAULT_WARN_FREE_GIB * self::BYTES_PER_GIB,
        'refuse_free_bytes'   => self::DEFAULT_REFUSE_FREE_GIB * self::BYTES_PER_GIB,
        'warn_free_percent'   => self::DEFAULT_WARN_FREE_PERCENT,
        'refuse_free_percent' => self::DEFAULT_REFUSE_FREE_PERCENT,
        'warn_worktree_count' => self::DEFAULT_WARN_WORKTREE_COUNT,
        );

        if (function_exists('apply_filters') ) {
            /**
             * Filters pre-create worktree disk-budget thresholds.
             *
             * @param array  $thresholds Default thresholds.
             * @param string $repo       Repository name.
             * @param string $branch     Branch being materialized.
             */
            // @phpstan-ignore-next-line WordPress accepts context args beyond the filtered value.
            $thresholds = apply_filters('datamachine_worktree_disk_budget_thresholds', $thresholds, $repo, $branch);
        }

        return self::normalize_thresholds((array) $thresholds);
    }

    /**
     * Format a short human-readable summary.
     *
     * @param  array $budget Budget report.
     * @return string
     */
    public static function format_summary( array $budget ): string
    {
        $free = null === ( $budget['free_gib'] ?? null ) ? 'unknown' : sprintf('%.1f GiB', (float) $budget['free_gib']);
        if (null !== ( $budget['free_percent'] ?? null ) ) {
            $free .= sprintf(' (%.1f%%)', (float) $budget['free_percent']);
        }
        $total = null === ( $budget['total_gib'] ?? null ) ? 'unknown total' : sprintf('%.1f GiB total', (float) $budget['total_gib']);
        return sprintf(
            'Disk budget: workspace=%s, %s free of %s, %d worktree-like dirs, status=%s.',
            (string) ( $budget['workspace_path'] ?? '' ),
            $free,
            $total,
            (int) ( $budget['worktree_count'] ?? 0 ),
            (string) ( $budget['status'] ?? 'unknown' )
        );
    }

    /**
     * Normalize threshold inputs.
     *
     * @param  array $thresholds Raw thresholds.
     * @return array<string,int|float>
     */
    private static function normalize_thresholds( array $thresholds ): array
    {
        $warn_free      = isset($thresholds['warn_free_bytes']) && is_numeric($thresholds['warn_free_bytes'])
        ? max(0, (int) $thresholds['warn_free_bytes'])
        : self::DEFAULT_WARN_FREE_GIB * self::BYTES_PER_GIB;
        $refuse_free    = isset($thresholds['refuse_free_bytes']) && is_numeric($thresholds['refuse_free_bytes'])
        ? max(0, (int) $thresholds['refuse_free_bytes'])
        : self::DEFAULT_REFUSE_FREE_GIB * self::BYTES_PER_GIB;
        $warn_percent   = isset($thresholds['warn_free_percent']) && is_numeric($thresholds['warn_free_percent'])
        ? max(0.0, min(100.0, (float) $thresholds['warn_free_percent']))
        : self::DEFAULT_WARN_FREE_PERCENT;
        $refuse_percent = isset($thresholds['refuse_free_percent']) && is_numeric($thresholds['refuse_free_percent'])
        ? max(0.0, min(100.0, (float) $thresholds['refuse_free_percent']))
        : self::DEFAULT_REFUSE_FREE_PERCENT;
        $count          = isset($thresholds['warn_worktree_count']) && is_numeric($thresholds['warn_worktree_count'])
        ? max(0, (int) $thresholds['warn_worktree_count'])
        : self::DEFAULT_WARN_WORKTREE_COUNT;

        if ($refuse_free > $warn_free ) {
            $warn_free = $refuse_free;
        }
        if ($refuse_percent > $warn_percent ) {
            $warn_percent = $refuse_percent;
        }

        return array(
        'warn_free_bytes'     => $warn_free,
        'refuse_free_bytes'   => $refuse_free,
        'warn_free_percent'   => $warn_percent,
        'refuse_free_percent' => $refuse_percent,
        'warn_worktree_count' => $count,
        );
    }

    /**
     * Count worktree-like directories cheaply without consulting every primary.
     *
     * @param  string $workspace_path Workspace root path.
     * @return int
     */
    private static function count_worktree_like_dirs( string $workspace_path ): int
    {
        if (! is_dir($workspace_path) ) {
            return 0;
        }

        $entries = scandir($workspace_path); // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found,WordPress.WP.AlternativeFunctions.file_system_operations_scandir
        if (false === $entries ) {
            return 0;
        }

        $count = 0;
        foreach ( $entries as $entry ) {
            if ('.' === $entry || '..' === $entry || ! str_contains($entry, '@') ) {
                continue;
            }

            if (is_dir($workspace_path . '/' . $entry) ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Convert bytes to GiB.
     *
     * @param  int $bytes Bytes.
     * @return float
     */
    private static function bytes_to_gib( int $bytes ): float
    {
        return $bytes / self::BYTES_PER_GIB;
    }
}
