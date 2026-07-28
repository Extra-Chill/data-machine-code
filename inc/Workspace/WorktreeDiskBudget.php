<?php
/**
 * Worktree Disk Budget
 *
 * Cheap pre-create guardrails for workspace worktree growth.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\CommandSpec;
use DataMachineCode\Support\ProcessRunner;

defined('ABSPATH') || exit;

final class WorktreeDiskBudget {



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

	private const DEFAULT_WARN_FREE_INODES = 2000000;

	private const DEFAULT_REFUSE_FREE_INODES = 1000000;

	private const DEFAULT_WARN_FREE_INODE_PERCENT = 15.0;

	private const DEFAULT_REFUSE_FREE_INODE_PERCENT = 10.0;

	/**
	 * Default worktree-count warning threshold.
	 */
	private const DEFAULT_WARN_WORKTREE_COUNT = 100;

	private const DEFAULT_USAGE_PROBE_TIMEOUT_SECONDS = 3;

	private const SHARED_USAGE_MINIMUM_BYTES = 1073741824;

	private const SHARED_USAGE_MINIMUM_PERCENT = 5.0;

	/** @var array<string,int|null> */
	private static array $workspace_usage_cache = array();

	/**
	 * Inspect workspace disk budget without an unbounded content walk.
	 *
	 * @param  string $workspace_path Workspace root path.
	 * @param  array  $thresholds     Optional threshold override for tests.
	 * @param  bool   $forced         Whether the caller explicitly forced creation.
	 * @param  array  $options        Optional bounded diagnostic probe controls.
	 * @return array<string,mixed>
	 */
	public static function inspect( string $workspace_path, array $thresholds = array(), bool $forced = false, array $options = array() ): array {
		$thresholds  = self::normalize_thresholds($thresholds);
		$free_bytes  = is_dir($workspace_path) ? disk_free_space($workspace_path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_free_space
		$total_bytes = is_dir($workspace_path) ? disk_total_space($workspace_path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_total_space
		$free_bytes  = is_float($free_bytes) ? (int) $free_bytes : null;
		$total_bytes = is_float($total_bytes) ? (int) $total_bytes : null;
		$worktrees   = self::count_worktree_like_dirs($workspace_path);
		$diagnostics = self::collect_volume_diagnostics($workspace_path, $options);
		$inodes      = array_key_exists('inode_metrics', $options)
			? self::normalize_inode_metrics($options['inode_metrics'])
			: self::measure_inode_capacity($workspace_path);

		return self::evaluate(
			array_merge(
				$diagnostics,
				array(
					'workspace_path' => $workspace_path,
					'free_bytes'     => $free_bytes,
					'total_bytes'    => $total_bytes,
					'worktree_count' => $worktrees,
					'total_inodes'   => $inodes['total_inodes'],
					'free_inodes'    => $inodes['free_inodes'],
					'inode_probe'    => $inodes['probe'],
				)
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
	public static function evaluate( array $metrics, array $thresholds = array(), bool $forced = false ): array {
		$thresholds   = self::normalize_thresholds($thresholds);
		$free_bytes   = isset($metrics['free_bytes']) && is_numeric($metrics['free_bytes']) ? (int) $metrics['free_bytes'] : null;
		$total_bytes  = isset($metrics['total_bytes']) && is_numeric($metrics['total_bytes']) ? (int) $metrics['total_bytes'] : null;
		$free_percent = null;
		if ( null !== $free_bytes && null !== $total_bytes && $total_bytes > 0 ) {
			$free_percent = ( $free_bytes / $total_bytes ) * 100;
		}
		$free_inodes        = isset($metrics['free_inodes']) && is_numeric($metrics['free_inodes']) ? max(0, (int) $metrics['free_inodes']) : null;
		$total_inodes       = isset($metrics['total_inodes']) && is_numeric($metrics['total_inodes']) ? max(0, (int) $metrics['total_inodes']) : null;
		$used_inodes        = null !== $free_inodes && null !== $total_inodes ? max(0, $total_inodes - $free_inodes) : null;
		$free_inode_percent = null;
		$used_inode_percent = null;
		if ( null !== $free_inodes && null !== $total_inodes && $total_inodes > 0 ) {
			$free_inode_percent = ( $free_inodes / $total_inodes ) * 100;
			$used_inode_percent = 100 - $free_inode_percent;
		}
		$count                       = isset($metrics['worktree_count']) && is_numeric($metrics['worktree_count']) ? (int) $metrics['worktree_count'] : 0;
		$filesystem_used_bytes       = null !== $free_bytes && null !== $total_bytes ? max(0, $total_bytes - $free_bytes) : null;
		$workspace_allocated_bytes   = isset($metrics['workspace_allocated_bytes']) && is_numeric($metrics['workspace_allocated_bytes'])
			? max(0, (int) $metrics['workspace_allocated_bytes'])
			: null;
		$shared_usage_estimate_bytes = null;
		$shared_usage_detected       = false;
		if ( null !== $filesystem_used_bytes && null !== $workspace_allocated_bytes ) {
			$shared_usage_estimate_bytes = max(0, $filesystem_used_bytes - $workspace_allocated_bytes);
			$materiality_floor           = max(
				self::SHARED_USAGE_MINIMUM_BYTES,
				(int) ceil($filesystem_used_bytes * ( self::SHARED_USAGE_MINIMUM_PERCENT / 100 ))
			);
			$shared_usage_detected       = $shared_usage_estimate_bytes >= $materiality_floor;
		}
		$warnings = array();
		$refused  = false;

		$effective_refuse_bytes = (int) $thresholds['refuse_free_bytes'];
		$effective_warn_bytes   = (int) $thresholds['warn_free_bytes'];
		if ( null !== $total_bytes && $total_bytes > 0 ) {
			$effective_refuse_bytes = self::effective_refuse_free_bytes_threshold(
				(int) $thresholds['refuse_free_bytes'],
				$thresholds['refuse_free_percent'],
				$total_bytes
			);
			$effective_warn_bytes   = self::effective_free_bytes_threshold(
				(int) $thresholds['warn_free_bytes'],
				$thresholds['warn_free_percent'],
				$total_bytes
			);
		}
		$effective_refuse_inodes = (int) $thresholds['refuse_free_inodes'];
		$effective_warn_inodes   = (int) $thresholds['warn_free_inodes'];
		if ( null !== $total_inodes && $total_inodes > 0 ) {
			$effective_refuse_inodes = self::effective_refuse_free_bytes_threshold( (int) $thresholds['refuse_free_inodes'], (float) $thresholds['refuse_free_inode_percent'], $total_inodes);
			$effective_warn_inodes   = self::effective_free_bytes_threshold( (int) $thresholds['warn_free_inodes'], (float) $thresholds['warn_free_inode_percent'], $total_inodes);
		}

		if ( null !== $free_bytes ) {
			if ( $free_bytes < $effective_refuse_bytes ) {
				$refused    = ! $forced;
				$warnings[] = sprintf(
					'Free filesystem space is %.1f GiB%s, below the refusal threshold of %.1f GiB.',
					self::bytes_to_gib($free_bytes),
					null === $free_percent ? '' : sprintf(' (%.1f%%)', $free_percent),
					self::bytes_to_gib($effective_refuse_bytes)
				);
			} elseif ( $free_bytes < $effective_warn_bytes ) {
				$warnings[] = sprintf(
					'Free filesystem space is %.1f GiB%s, below the warning threshold of %.1f GiB or %.1f%% free, whichever is stricter.',
					self::bytes_to_gib($free_bytes),
					null === $free_percent ? '' : sprintf(' (%.1f%%)', $free_percent),
					self::bytes_to_gib( (int) $thresholds['warn_free_bytes'] ),
					$thresholds['warn_free_percent']
				);
			}
		} else {
			$warnings[] = 'Free filesystem space could not be measured.';
		}

		if ( null !== $free_inodes ) {
			if ( $free_inodes < $effective_refuse_inodes ) {
				$refused    = ! $forced;
				$warnings[] = sprintf('Free filesystem inodes are %s%s, below the refusal threshold of %s.', number_format($free_inodes), null === $free_inode_percent ? '' : sprintf(' (%.1f%%)', $free_inode_percent), number_format($effective_refuse_inodes));
			} elseif ( $free_inodes < $effective_warn_inodes ) {
				$warnings[] = sprintf('Free filesystem inodes are %s%s, below the warning threshold of %s or %.1f%% free, whichever is stricter.', number_format($free_inodes), null === $free_inode_percent ? '' : sprintf(' (%.1f%%)', $free_inode_percent), number_format( (int) $thresholds['warn_free_inodes']), (float) $thresholds['warn_free_inode_percent']);
			}
		}

		if ( $count > $thresholds['warn_worktree_count'] ) {
			$warnings[] = sprintf(
				'Workspace has %d worktree-like directories, above the %d warning threshold.',
				$count,
				$thresholds['warn_worktree_count']
			);
		}

		$status              = $refused ? 'refused' : ( empty($warnings) ? 'ok' : 'warning' );
		$trigger_reasons     = array();
		$diagnostic_messages = array();
		if ( $shared_usage_detected ) {
			$diagnostic_messages[] = sprintf(
				'Filesystem usage includes an estimated %.1f GiB outside the measured workspace subtree.',
				self::bytes_to_gib( (int) $shared_usage_estimate_bytes )
			);
		}
		if ( null === $free_inodes || null === $total_inodes ) {
			$diagnostic_messages[] = 'Filesystem inode capacity is unavailable on this platform; byte safeguards remain enforced.';
		}
		if ( null !== $free_bytes && $free_bytes < $effective_refuse_bytes ) {
			$trigger_reasons[] = 'free_space_refusal_threshold';
		} elseif ( null !== $free_bytes && $free_bytes < $effective_warn_bytes ) {
			$trigger_reasons[] = 'free_space_warning_threshold';
		}
		if ( $count > $thresholds['warn_worktree_count'] ) {
			$trigger_reasons[] = 'worktree_count_warning_threshold';
		}
		if ( null !== $free_inodes && $free_inodes < $effective_refuse_inodes ) {
			$trigger_reasons[] = 'free_inode_refusal_threshold';
		} elseif ( null !== $free_inodes && $free_inodes < $effective_warn_inodes ) {
			$trigger_reasons[] = 'free_inode_warning_threshold';
		}

		return array(
			'workspace_path'              => (string) ( $metrics['workspace_path'] ?? '' ),
			'filesystem_total_bytes'      => $total_bytes,
			'filesystem_used_bytes'       => $filesystem_used_bytes,
			'filesystem_free_bytes'       => $free_bytes,
			'safety_basis'                => 'independent_filesystem_bytes_and_inodes',
			'free_bytes'                  => $free_bytes,
			'free_gib'                    => null === $free_bytes ? null : round(self::bytes_to_gib($free_bytes), 2),
			'total_bytes'                 => $total_bytes,
			'total_gib'                   => null === $total_bytes ? null : round(self::bytes_to_gib($total_bytes), 2),
			'free_percent'                => null === $free_percent ? null : round($free_percent, 2),
			'filesystem_total_inodes'     => $total_inodes,
			'filesystem_used_inodes'      => $used_inodes,
			'filesystem_free_inodes'      => $free_inodes,
			'total_inodes'                => $total_inodes,
			'used_inodes'                 => $used_inodes,
			'free_inodes'                 => $free_inodes,
			'free_inode_percent'          => null === $free_inode_percent ? null : round($free_inode_percent, 2),
			'used_inode_percent'          => null === $used_inode_percent ? null : round($used_inode_percent, 2),
			'inode_probe'                 => (string) ( $metrics['inode_probe'] ?? 'unavailable' ),
			'workspace_allocated_bytes'   => $workspace_allocated_bytes,
			'workspace_size_bytes'        => $workspace_allocated_bytes,
			'workspace_size_exact'        => false,
			'workspace_usage_probe'       => (string) ( $metrics['workspace_usage_probe'] ?? 'unavailable' ),
			'mount_target'                => isset($metrics['mount_target']) ? (string) $metrics['mount_target'] : null,
			'mount_source'                => isset($metrics['mount_source']) ? (string) $metrics['mount_source'] : null,
			'mount_source_subdirectory'   => isset($metrics['mount_source_subdirectory']) ? (string) $metrics['mount_source_subdirectory'] : null,
			'shared_usage_estimate_bytes' => $shared_usage_estimate_bytes,
			'shared_usage_detected'       => $shared_usage_detected,
			'diagnostic_messages'         => $diagnostic_messages,
			'worktree_count'              => $count,
			'warn_free_bytes'             => $thresholds['warn_free_bytes'],
			'warn_free_gib'               => round(self::bytes_to_gib( (int) $thresholds['warn_free_bytes'] ), 2),
			'warn_free_percent'           => $thresholds['warn_free_percent'],
			'refuse_free_bytes'           => $thresholds['refuse_free_bytes'],
			'refuse_free_gib'             => round(self::bytes_to_gib( (int) $thresholds['refuse_free_bytes'] ), 2),
			'refuse_free_percent'         => $thresholds['refuse_free_percent'],
			'effective_refuse_bytes'      => $effective_refuse_bytes,
			'effective_refuse_gib'        => round(self::bytes_to_gib($effective_refuse_bytes), 2),
			'effective_warn_bytes'        => $effective_warn_bytes,
			'effective_warn_gib'          => round(self::bytes_to_gib($effective_warn_bytes), 2),
			'warn_free_inodes'            => $thresholds['warn_free_inodes'],
			'warn_free_inode_percent'     => $thresholds['warn_free_inode_percent'],
			'refuse_free_inodes'          => $thresholds['refuse_free_inodes'],
			'refuse_free_inode_percent'   => $thresholds['refuse_free_inode_percent'],
			'effective_refuse_inodes'     => $effective_refuse_inodes,
			'effective_warn_inodes'       => $effective_warn_inodes,
			'warn_worktree_count'         => $thresholds['warn_worktree_count'],
			'forced'                      => $forced,
			'status'                      => $status,
			'warnings'                    => $warnings,
			'emergency_triggered'         => array() !== $trigger_reasons,
			'trigger_reasons'             => $trigger_reasons,
			'cleanup_dry_run_command'     => 'studio wp datamachine-code workspace worktree cleanup --dry-run',
			'artifact_cleanup_command'    => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
			'emergency_cleanup_command'   => 'studio wp datamachine-code workspace worktree emergency-cleanup --format=json',
			'cleanup_recommendations'     => self::cleanup_recommendations($free_bytes, $effective_refuse_bytes, $free_inodes, $effective_refuse_inodes),
			'force_override_required'     => $refused,
			'force_override_applied'      => $forced && ! empty($warnings),
		);
	}

	/**
	 * Build concise operator remediation commands for disk-pressure failures.
	 *
	 * @param  int|null $free_bytes              Current free bytes.
	 * @param  int      $effective_refuse_bytes Effective refusal floor.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cleanup_recommendations( ?int $free_bytes, int $effective_refuse_bytes, ?int $free_inodes, int $effective_refuse_inodes ): array {
		$target_reclaim = null === $free_bytes ? null : max(0, $effective_refuse_bytes - $free_bytes);
		$target_human   = null === $target_reclaim ? 'enough space to clear the refusal threshold' : self::format_bytes($target_reclaim);
		$target_inodes  = null === $free_inodes ? null : max(0, $effective_refuse_inodes - $free_inodes);

		return array(
			array(
				'priority'                => 1,
				'action'                  => 'create a DB-backed plan for the largest reconstructable artifacts',
				'expected_reclaim_bytes'  => $target_reclaim,
				'expected_reclaim'        => $target_human,
				'expected_reclaim_inodes' => $target_inodes,
				'command'                 => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
				'preview_command'         => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
				'apply_command'           => 'studio wp datamachine-code workspace cleanup apply <run-id>',
				'apply_note'              => 'Review output includes the DB-backed run_id required by the apply command.',
			),
			array(
				'priority'                => 2,
				'action'                  => 'review bounded cleanup-eligible worktrees; apply revalidates before removal',
				'expected_reclaim_bytes'  => $target_reclaim,
				'expected_reclaim'        => $target_human,
				'expected_reclaim_inodes' => $target_inodes,
				'command'                 => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25',
				'preview_command'         => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25',
				'apply_command'           => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=25',
				'apply_note'              => 'Apply runs fresh dirty, unpushed, containment, and primary safety probes and may skip rows that the cheap inventory review listed.',
			),
			array(
				'priority'                => 3,
				'action'                  => 'generate combined emergency cleanup report',
				'expected_reclaim_bytes'  => $target_reclaim,
				'expected_reclaim'        => $target_human,
				'expected_reclaim_inodes' => $target_inodes,
				'command'                 => 'studio wp datamachine-code workspace worktree emergency-cleanup --format=json',
				'preview_command'         => 'studio wp datamachine-code workspace worktree emergency-cleanup --format=json',
			),
		);
	}

	/**
	 * Get filterable thresholds.
	 *
	 * @param  string $repo   Repository name.
	 * @param  string $branch Branch name.
	 * @return array<string,int|float>
	 */
	public static function thresholds( string $repo, string $branch ): array {
		$thresholds = array(
			'warn_free_bytes'           => self::DEFAULT_WARN_FREE_GIB * self::BYTES_PER_GIB,
			'refuse_free_bytes'         => self::DEFAULT_REFUSE_FREE_GIB * self::BYTES_PER_GIB,
			'warn_free_percent'         => self::DEFAULT_WARN_FREE_PERCENT,
			'refuse_free_percent'       => self::DEFAULT_REFUSE_FREE_PERCENT,
			'warn_free_inodes'          => self::DEFAULT_WARN_FREE_INODES,
			'refuse_free_inodes'        => self::DEFAULT_REFUSE_FREE_INODES,
			'warn_free_inode_percent'   => self::DEFAULT_WARN_FREE_INODE_PERCENT,
			'refuse_free_inode_percent' => self::DEFAULT_REFUSE_FREE_INODE_PERCENT,
			'warn_worktree_count'       => self::DEFAULT_WARN_WORKTREE_COUNT,
		);

		if ( function_exists('apply_filters') ) {
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

		return self::normalize_thresholds( (array) $thresholds);
	}

	/**
	 * Format a short human-readable summary.
	 *
	 * @param  array $budget Budget report.
	 * @return string
	 */
	public static function format_summary( array $budget ): string {
		$free = null === ( $budget['free_gib'] ?? null ) ? 'unknown' : sprintf('%.1f GiB', (float) $budget['free_gib']);
		if ( null !== ( $budget['free_percent'] ?? null ) ) {
			$free .= sprintf(' (%.1f%%)', (float) $budget['free_percent']);
		}
		$total      = null === ( $budget['total_gib'] ?? null ) ? 'unknown total' : sprintf('%.1f GiB total', (float) $budget['total_gib']);
		$summary    = sprintf(
			'Disk budget: workspace=%s, %s free of %s, %d worktree-like dirs, status=%s.',
			(string) ( $budget['workspace_path'] ?? '' ),
			$free,
			$total,
			(int) ( $budget['worktree_count'] ?? 0 ),
			(string) ( $budget['status'] ?? 'unknown' )
		);
		$inode_free = null === ( $budget['free_inodes'] ?? null ) ? 'unknown' : number_format( (int) $budget['free_inodes']);
		if ( null !== ( $budget['free_inode_percent'] ?? null ) ) {
			$inode_free .= sprintf(' (%.1f%%)', (float) $budget['free_inode_percent']);
		}
		$inode_total = null === ( $budget['total_inodes'] ?? null ) ? 'unknown total' : number_format( (int) $budget['total_inodes']) . ' total';
		$summary    .= sprintf(' Inodes: %s free of %s.', $inode_free, $inode_total);
		if ( ! empty($budget['shared_usage_detected']) && null !== ( $budget['shared_usage_estimate_bytes'] ?? null ) ) {
			$summary .= sprintf(
				' Estimated usage outside the measured workspace subtree: %.1f GiB.',
				self::bytes_to_gib( (int) $budget['shared_usage_estimate_bytes'] )
			);
		}

		return $summary;
	}

	/**
	 * Parse the most specific Linux mountinfo row containing a path.
	 *
	 * @param  string $mountinfo Linux /proc/self/mountinfo contents.
	 * @param  string $workspace_path Workspace root path.
	 * @return array<string,string>|null
	 */
	public static function parse_mountinfo( string $mountinfo, string $workspace_path ): ?array {
		$workspace_path = rtrim($workspace_path, '/');
		$workspace_path = '' === $workspace_path ? '/' : $workspace_path;
		$best           = null;

		$mountinfo_lines = preg_split('/\r?\n/', $mountinfo);
		foreach ( false === $mountinfo_lines ? array() : $mountinfo_lines as $line ) {
			$separator = strpos($line, ' - ');
			if ( false === $separator ) {
				continue;
			}

			$left  = preg_split('/\s+/', substr($line, 0, $separator));
			$left  = false === $left ? array() : $left;
			$right = preg_split('/\s+/', substr($line, $separator + 3));
			$right = false === $right ? array() : $right;
			if ( count($left) < 5 || count($right) < 2 ) {
				continue;
			}

			$target = self::decode_mountinfo_path( (string) $left[4]);
			if ( '/' !== $target && $workspace_path !== $target && ! str_starts_with($workspace_path, rtrim($target, '/') . '/') ) {
				continue;
			}

			if ( null !== $best && strlen($target) <= strlen($best['mount_target']) ) {
				continue;
			}

			$best = array(
				'mount_target'              => $target,
				'mount_source'              => self::decode_mountinfo_path( (string) $right[1]),
				'mount_source_subdirectory' => self::decode_mountinfo_path( (string) $left[3]),
			);
		}

		return $best;
	}

	/**
	 * Collect best-effort diagnostics without affecting the safety decision.
	 *
	 * @param  string $workspace_path Workspace root path.
	 * @param  array  $options Probe controls and test overrides.
	 * @return array<string,mixed>
	 */
	private static function collect_volume_diagnostics( string $workspace_path, array $options ): array {
		$mountinfo = array_key_exists('mountinfo', $options)
			? ( is_string($options['mountinfo']) ? $options['mountinfo'] : '' )
			: self::read_mountinfo();
		$mount     = '' === $mountinfo ? null : self::parse_mountinfo($mountinfo, $workspace_path);

		$include_usage = array_key_exists('include_workspace_usage', $options)
			? (bool) $options['include_workspace_usage']
			: false;
		$allocated     = null;
		$probe         = 'disabled';
		if ( $include_usage ) {
			$allocated = array_key_exists('workspace_allocated_bytes', $options)
				? ( is_numeric($options['workspace_allocated_bytes']) ? max(0, (int) $options['workspace_allocated_bytes']) : null )
				: self::measure_workspace_allocated_bytes(
					$workspace_path,
					max(1, (int) ( $options['usage_probe_timeout_seconds'] ?? self::DEFAULT_USAGE_PROBE_TIMEOUT_SECONDS ))
				);
			$probe     = null === $allocated
				? 'unavailable'
				: (string) ( $options['workspace_usage_probe'] ?? ( array_key_exists('workspace_allocated_bytes', $options) ? 'provided' : 'bounded_du' ) );
		}

		return array_merge(
			array(
				'workspace_allocated_bytes' => $allocated,
				'workspace_usage_probe'     => $probe,
			),
			$mount ?? array()
		);
	}

	private static function read_mountinfo(): string {
		$path = '/proc/self/mountinfo';
		if ( ! is_readable($path) ) {
			return '';
		}

		$contents = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $contents ? '' : $contents;
	}

	private static function measure_workspace_allocated_bytes( string $workspace_path, int $timeout_seconds ): ?int {
		if ( ! is_dir($workspace_path) ) {
			return null;
		}

		$cache_key = $workspace_path . ':' . $timeout_seconds;
		if ( array_key_exists($cache_key, self::$workspace_usage_cache) ) {
			return self::$workspace_usage_cache[ $cache_key ];
		}

		$command = CommandSpec::from_argv(array( 'du', '-sx', '-B1', '--', $workspace_path ));
		if ( $command instanceof \WP_Error ) {
			self::$workspace_usage_cache[ $cache_key ] = null;
			return null;
		}
		$result = ProcessRunner::run(
			$command,
			array(
				'timeout_seconds'  => $timeout_seconds,
				'output_cap_bytes' => 1024,
				'error_as_result'  => true,
			)
		);
		if ( $result instanceof \WP_Error || empty($result['success']) ) {
			self::$workspace_usage_cache[ $cache_key ] = null;
			return null;
		}

		$parts = preg_split('/\s+/', trim( (string) ( $result['output'] ?? '' )));
		$parts = false === $parts ? array() : $parts;
		$bytes = isset($parts[0]) && is_numeric($parts[0]) ? max(0, (int) $parts[0]) : null;

		self::$workspace_usage_cache[ $cache_key ] = $bytes;
		return $bytes;
	}

	/**
	 * Read inode capacity from GNU stat's statfs(2) counters without walking files.
	 *
	 * @return array{total_inodes:int|null,free_inodes:int|null,probe:string}
	 */
	private static function measure_inode_capacity( string $workspace_path ): array {
		if ( ! is_dir($workspace_path) ) {
			return self::normalize_inode_metrics(null);
		}
		$command = CommandSpec::from_argv(array( 'stat', '-f', '-c', '%c:%d', '--', $workspace_path ));
		if ( $command instanceof \WP_Error ) {
			return self::normalize_inode_metrics(null);
		}
		$result = ProcessRunner::run($command, array(
			'timeout_seconds'  => 2,
			'output_cap_bytes' => 256,
			'error_as_result'  => true,
		));
		if ( $result instanceof \WP_Error || empty($result['success']) ) {
			return self::normalize_inode_metrics(null);
		}
		$parts = explode(':', trim( (string) ( $result['output'] ?? '' )), 2);
		if ( 2 !== count($parts) || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) ) {
			return self::normalize_inode_metrics(null);
		}
		return self::normalize_inode_metrics(array(
			'total_inodes' => (int) $parts[0],
			'free_inodes'  => (int) $parts[1],
			'probe'        => 'gnu_statfs',
		));
	}

	/**
	 * @param mixed $metrics Raw or injected inode metrics.
	 * @return array{total_inodes:int|null,free_inodes:int|null,probe:string}
	 */
	private static function normalize_inode_metrics( mixed $metrics ): array {
		if ( ! is_array($metrics) || ! isset($metrics['total_inodes'], $metrics['free_inodes']) || ! is_numeric($metrics['total_inodes']) || ! is_numeric($metrics['free_inodes']) ) {
			return array(
				'total_inodes' => null,
				'free_inodes'  => null,
				'probe'        => 'unavailable',
			);
		}
		$total = max(0, (int) $metrics['total_inodes']);
		$free  = min($total, max(0, (int) $metrics['free_inodes']));
		return array(
			'total_inodes' => $total,
			'free_inodes'  => $free,
			'probe'        => (string) ( $metrics['probe'] ?? 'provided' ),
		);
	}

	private static function decode_mountinfo_path( string $path ): string {
		return strtr(
			$path,
			array(
				'\\040' => ' ',
				'\\011' => "\t",
				'\\012' => "\n",
				'\\134' => '\\',
			)
		);
	}

	/**
	 * Normalize threshold inputs.
	 *
	 * @param  array $thresholds Raw thresholds.
	 * @return array<string,int|float>
	 */
	private static function normalize_thresholds( array $thresholds ): array {
		$warn_free            = isset($thresholds['warn_free_bytes']) && is_numeric($thresholds['warn_free_bytes'])
		? max(0, (int) $thresholds['warn_free_bytes'])
		: self::DEFAULT_WARN_FREE_GIB * self::BYTES_PER_GIB;
		$refuse_free          = isset($thresholds['refuse_free_bytes']) && is_numeric($thresholds['refuse_free_bytes'])
		? max(0, (int) $thresholds['refuse_free_bytes'])
		: self::DEFAULT_REFUSE_FREE_GIB * self::BYTES_PER_GIB;
		$warn_percent         = isset($thresholds['warn_free_percent']) && is_numeric($thresholds['warn_free_percent'])
		? max(0.0, min(100.0, (float) $thresholds['warn_free_percent']))
		: self::DEFAULT_WARN_FREE_PERCENT;
		$refuse_percent       = isset($thresholds['refuse_free_percent']) && is_numeric($thresholds['refuse_free_percent'])
		? max(0.0, min(100.0, (float) $thresholds['refuse_free_percent']))
		: self::DEFAULT_REFUSE_FREE_PERCENT;
		$count                = isset($thresholds['warn_worktree_count']) && is_numeric($thresholds['warn_worktree_count'])
		? max(0, (int) $thresholds['warn_worktree_count'])
		: self::DEFAULT_WARN_WORKTREE_COUNT;
		$warn_inodes          = isset($thresholds['warn_free_inodes']) && is_numeric($thresholds['warn_free_inodes']) ? max(0, (int) $thresholds['warn_free_inodes']) : self::DEFAULT_WARN_FREE_INODES;
		$refuse_inodes        = isset($thresholds['refuse_free_inodes']) && is_numeric($thresholds['refuse_free_inodes']) ? max(0, (int) $thresholds['refuse_free_inodes']) : self::DEFAULT_REFUSE_FREE_INODES;
		$warn_inode_percent   = isset($thresholds['warn_free_inode_percent']) && is_numeric($thresholds['warn_free_inode_percent']) ? max(0.0, min(100.0, (float) $thresholds['warn_free_inode_percent'])) : self::DEFAULT_WARN_FREE_INODE_PERCENT;
		$refuse_inode_percent = isset($thresholds['refuse_free_inode_percent']) && is_numeric($thresholds['refuse_free_inode_percent']) ? max(0.0, min(100.0, (float) $thresholds['refuse_free_inode_percent'])) : self::DEFAULT_REFUSE_FREE_INODE_PERCENT;

		if ( $refuse_free > $warn_free ) {
			$warn_free = $refuse_free;
		}
		if ( $refuse_percent > $warn_percent ) {
			$warn_percent = $refuse_percent;
		}
		if ( $refuse_inodes > $warn_inodes ) {
			$warn_inodes = $refuse_inodes;
		}
		if ( $refuse_inode_percent > $warn_inode_percent ) {
			$warn_inode_percent = $refuse_inode_percent;
		}

		return array(
			'warn_free_bytes'           => $warn_free,
			'refuse_free_bytes'         => $refuse_free,
			'warn_free_percent'         => $warn_percent,
			'refuse_free_percent'       => $refuse_percent,
			'warn_worktree_count'       => $count,
			'warn_free_inodes'          => $warn_inodes,
			'refuse_free_inodes'        => $refuse_inodes,
			'warn_free_inode_percent'   => $warn_inode_percent,
			'refuse_free_inode_percent' => $refuse_inode_percent,
		);
	}

	/**
	 * Format bytes for command guidance without WordPress runtime helpers.
	 *
	 * @param  int|float $bytes Bytes.
	 * @return string
	 */
	private static function format_bytes( int|float $bytes ): string {
		$bytes      = max(0, (float) $bytes);
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$unit       = 0;
		$unit_count = count($units);
		while ( $bytes >= 1024 && $unit < $unit_count - 1 ) {
			$bytes /= 1024;
			++$unit;
		}

		return number_format($bytes, 0 === $unit ? 0 : 1) . ' ' . $units[ $unit ];
	}

	/**
	 * Count worktree-like directories cheaply without consulting every primary.
	 *
	 * @param  string $workspace_path Workspace root path.
	 * @return int
	 */
	private static function count_worktree_like_dirs( string $workspace_path ): int {
		if ( ! is_dir($workspace_path) ) {
			return 0;
		}

		$entries = scandir($workspace_path); // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found,WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		if ( false === $entries ) {
			return 0;
		}

		$count = 0;
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! str_contains($entry, '@') ) {
				continue;
			}

			if ( is_dir($workspace_path . '/' . $entry) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Calculate the free-space threshold for the measured filesystem.
	 *
	 * The absolute GiB floor protects normal workspaces, but bounded ephemeral
	 * filesystems can be smaller than that floor. In that case, the percentage
	 * threshold is the only attainable safety signal.
	 *
	 * @param  int   $absolute_bytes Absolute free-space threshold.
	 * @param  float $percent        Percentage free-space threshold.
	 * @param  int   $total_bytes    Measured filesystem size.
	 * @return int
	 */
	private static function effective_free_bytes_threshold( int $absolute_bytes, float $percent, int $total_bytes ): int {
		$percent_bytes = (int) ceil($total_bytes * ( $percent / 100 ));

		if ( $total_bytes < $absolute_bytes ) {
			return $percent_bytes;
		}

		return max($absolute_bytes, $percent_bytes);
	}

	/**
	 * Calculate the hard refusal threshold for a measured filesystem.
	 *
	 * Large filesystems can safely fall below a percentage threshold while still
	 * having enough absolute free space for a bare worktree checkout. Keep the
	 * percentage refusal only for filesystems smaller than the absolute floor,
	 * where the absolute GiB floor is impossible to satisfy.
	 *
	 * @param  int   $absolute_bytes Absolute free-space threshold.
	 * @param  float $percent        Percentage free-space threshold.
	 * @param  int   $total_bytes    Measured filesystem size.
	 * @return int
	 */
	private static function effective_refuse_free_bytes_threshold( int $absolute_bytes, float $percent, int $total_bytes ): int {
		$percent_bytes = (int) ceil($total_bytes * ( $percent / 100 ));

		if ( $total_bytes < $absolute_bytes ) {
			return $percent_bytes;
		}

		return $absolute_bytes;
	}

	/**
	 * Convert bytes to GiB.
	 *
	 * @param  int $bytes Bytes.
	 * @return float
	 */
	private static function bytes_to_gib( int $bytes ): float {
		return $bytes / self::BYTES_PER_GIB;
	}
}
