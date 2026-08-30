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
use DataMachineCode\Support\WallClockBudget;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreeDiskBudget {

	private const BYTES_PER_GIB = 1073741824;
	private const DIAGNOSTIC_ID = 'workspace_capacity';

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
	 * @param  array  $demand         Projected operation demand.
	 * @return array<string,mixed>
	 */
	public static function inspect( string $workspace_path, array $thresholds = array(), bool $forced = false, array $options = array(), array $demand = array() ): array {
		$thresholds  = self::normalize_thresholds($thresholds);
		$free_bytes  = is_dir($workspace_path) ? disk_free_space($workspace_path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_free_space
		$total_bytes = is_dir($workspace_path) ? disk_total_space($workspace_path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_total_space
		$free_bytes  = is_float($free_bytes) ? (int) $free_bytes : null;
		$total_bytes = is_float($total_bytes) ? (int) $total_bytes : null;
		$worktrees   = isset($options['worktree_count']) && is_numeric($options['worktree_count'])
			? max(0, (int) $options['worktree_count'])
			: self::count_worktree_like_dirs($workspace_path);
		$diagnostics = self::collect_volume_diagnostics($workspace_path, $options);
		$inodes      = array_key_exists('inode_metrics', $options)
			? self::normalize_inode_metrics($options['inode_metrics'])
			: self::measure_inode_capacity($workspace_path, $options['wall_clock_budget'] ?? null);

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
			$forced,
			$demand
		);
	}

	/**
	 * Evaluate disk-budget status from already-measured values.
	 *
	 * @param  array $metrics    Measured values.
	 * @param  array $thresholds Threshold values.
	 * @param  bool  $forced     Whether the caller explicitly forced creation.
	 * @param  array $demand     Projected operation demand.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $metrics, array $thresholds = array(), bool $forced = false, array $demand = array() ): array {
		$thresholds           = self::normalize_thresholds($thresholds);
		$free_bytes           = isset($metrics['free_bytes']) && is_numeric($metrics['free_bytes']) ? (int) $metrics['free_bytes'] : null;
		$total_bytes          = isset($metrics['total_bytes']) && is_numeric($metrics['total_bytes']) ? (int) $metrics['total_bytes'] : null;
		$demand_bytes         = isset($demand['bytes']) && is_numeric($demand['bytes']) ? max(0, (int) $demand['bytes']) : 0;
		$demand_inodes        = isset($demand['inodes']) && is_numeric($demand['inodes']) ? max(0, (int) $demand['inodes']) : 0;
		$demand_source        = isset($demand['source']) && is_string($demand['source']) && '' !== $demand['source'] ? $demand['source'] : 'not_provided';
		$projected_free_bytes = null === $free_bytes ? null : max(0, $free_bytes - $demand_bytes);
		$free_percent         = null;
		$used_percent         = null;
		if ( null !== $free_bytes && null !== $total_bytes && $total_bytes > 0 ) {
			$free_percent = ( $free_bytes / $total_bytes ) * 100;
			$used_percent = 100 - $free_percent;
		}
		$free_inodes           = isset($metrics['free_inodes']) && is_numeric($metrics['free_inodes']) ? max(0, (int) $metrics['free_inodes']) : null;
		$total_inodes          = isset($metrics['total_inodes']) && is_numeric($metrics['total_inodes']) ? max(0, (int) $metrics['total_inodes']) : null;
		$projected_free_inodes = null === $free_inodes ? null : max(0, $free_inodes - $demand_inodes);
		$used_inodes           = null !== $free_inodes && null !== $total_inodes ? max(0, $total_inodes - $free_inodes) : null;
		$free_inode_percent    = null;
		$used_inode_percent    = null;
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

		$refuse_percent_bytes   = null;
		$warn_percent_bytes     = null;
		$effective_refuse_bytes = (int) $thresholds['refuse_free_bytes'];
		$effective_warn_bytes   = (int) $thresholds['warn_free_bytes'];
		if ( null !== $total_bytes && $total_bytes > 0 ) {
			$refuse_percent_bytes   = (int) ceil($total_bytes * ( (float) $thresholds['refuse_free_percent'] / 100 ));
			$warn_percent_bytes     = (int) ceil($total_bytes * ( (float) $thresholds['warn_free_percent'] / 100 ));
			$effective_refuse_bytes = max( (int) $thresholds['refuse_free_bytes'], $refuse_percent_bytes);
			$effective_warn_bytes   = max( (int) $thresholds['warn_free_bytes'], $warn_percent_bytes);
		}
		$refuse_percent_inodes   = null;
		$warn_percent_inodes     = null;
		$effective_refuse_inodes = (int) $thresholds['refuse_free_inodes'];
		$effective_warn_inodes   = (int) $thresholds['warn_free_inodes'];
		if ( null !== $total_inodes && $total_inodes > 0 ) {
			$refuse_percent_inodes   = (int) ceil($total_inodes * ( (float) $thresholds['refuse_free_inode_percent'] / 100 ));
			$warn_percent_inodes     = (int) ceil($total_inodes * ( (float) $thresholds['warn_free_inode_percent'] / 100 ));
			$effective_refuse_inodes = max( (int) $thresholds['refuse_free_inodes'], $refuse_percent_inodes);
			$effective_warn_inodes   = max( (int) $thresholds['warn_free_inodes'], $warn_percent_inodes);
		}

		if ( null !== $projected_free_bytes ) {
			if ( $projected_free_bytes <= $effective_refuse_bytes ) {
				$refused    = ! $forced;
				$warnings[] = sprintf(
					'Projected free filesystem space is %.1f GiB after %.1f GiB demand (raw %.1f GiB), at or below the refusal floor of %.1f GiB; shortfall is %s.',
					self::bytes_to_gib($projected_free_bytes),
					self::bytes_to_gib($demand_bytes),
					self::bytes_to_gib($free_bytes),
					self::bytes_to_gib($effective_refuse_bytes),
					self::format_bytes($effective_refuse_bytes - $projected_free_bytes + 1)
				);
			} elseif ( $projected_free_bytes <= $effective_warn_bytes ) {
				$warnings[] = sprintf(
					'Projected free filesystem space is %.1f GiB after %.1f GiB demand (raw %.1f GiB), at or below the warning floor of %.1f GiB; shortfall is %s.',
					self::bytes_to_gib($projected_free_bytes),
					self::bytes_to_gib($demand_bytes),
					self::bytes_to_gib($free_bytes),
					self::bytes_to_gib($effective_warn_bytes),
					self::format_bytes($effective_warn_bytes - $projected_free_bytes + 1)
				);
			}
		} else {
			$warnings[] = 'Free filesystem space could not be measured.';
		}

		if ( null !== $projected_free_inodes ) {
			if ( $projected_free_inodes <= $effective_refuse_inodes ) {
				$refused    = ! $forced;
				$warnings[] = sprintf('Projected free filesystem inodes are %s after %s demand (raw %s), at or below the refusal floor of %s; shortfall is %s.', number_format($projected_free_inodes), number_format($demand_inodes), number_format($free_inodes), number_format($effective_refuse_inodes), number_format($effective_refuse_inodes - $projected_free_inodes + 1));
			} elseif ( $projected_free_inodes <= $effective_warn_inodes ) {
				$warnings[] = sprintf('Projected free filesystem inodes are %s after %s demand (raw %s), at or below the warning floor of %s; shortfall is %s.', number_format($projected_free_inodes), number_format($demand_inodes), number_format($free_inodes), number_format($effective_warn_inodes), number_format($effective_warn_inodes - $projected_free_inodes + 1));
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
		if ( null === $projected_free_bytes ) {
			$trigger_reasons[] = 'filesystem_free_bytes_measurement_unavailable';
		}
		if ( $shared_usage_detected ) {
			$diagnostic_messages[] = sprintf(
				'Filesystem usage includes an estimated %.1f GiB outside the measured workspace subtree.',
				self::bytes_to_gib( (int) $shared_usage_estimate_bytes )
			);
		}
		if ( null === $free_inodes || null === $total_inodes ) {
			$diagnostic_messages[] = 'Filesystem inode capacity is unavailable on this platform; byte safeguards remain enforced.';
		}
		if ( null !== $projected_free_bytes ) {
			if ( $projected_free_bytes <= (int) $thresholds['refuse_free_bytes'] ) {
				$trigger_reasons[] = 'projected_free_bytes_absolute_refusal_floor';
			}
			if ( null !== $refuse_percent_bytes && $projected_free_bytes <= $refuse_percent_bytes ) {
				$trigger_reasons[] = 'projected_free_bytes_percentage_refusal_floor';
			}
			if ( $projected_free_bytes > $effective_refuse_bytes && $projected_free_bytes <= (int) $thresholds['warn_free_bytes'] ) {
				$trigger_reasons[] = 'projected_free_bytes_absolute_warning_floor';
			}
			if ( null !== $warn_percent_bytes && $projected_free_bytes > $effective_refuse_bytes && $projected_free_bytes <= $warn_percent_bytes ) {
				$trigger_reasons[] = 'projected_free_bytes_percentage_warning_floor';
			}
		}
		if ( $count > $thresholds['warn_worktree_count'] ) {
			$trigger_reasons[] = 'worktree_count_warning_threshold';
		}
		if ( null !== $projected_free_inodes ) {
			if ( $projected_free_inodes <= (int) $thresholds['refuse_free_inodes'] ) {
				$trigger_reasons[] = 'projected_free_inodes_absolute_refusal_floor';
			}
			if ( null !== $refuse_percent_inodes && $projected_free_inodes <= $refuse_percent_inodes ) {
				$trigger_reasons[] = 'projected_free_inodes_percentage_refusal_floor';
			}
			if ( $projected_free_inodes > $effective_refuse_inodes && $projected_free_inodes <= (int) $thresholds['warn_free_inodes'] ) {
				$trigger_reasons[] = 'projected_free_inodes_absolute_warning_floor';
			}
			if ( null !== $warn_percent_inodes && $projected_free_inodes > $effective_refuse_inodes && $projected_free_inodes <= $warn_percent_inodes ) {
				$trigger_reasons[] = 'projected_free_inodes_percentage_warning_floor';
			}
		}
		$refuse_byte_shortfall  = null === $projected_free_bytes ? null : max(0, $effective_refuse_bytes - $projected_free_bytes + 1);
		$warn_byte_shortfall    = null === $projected_free_bytes ? null : max(0, $effective_warn_bytes - $projected_free_bytes + 1);
		$refuse_inode_shortfall = null === $projected_free_inodes ? null : max(0, $effective_refuse_inodes - $projected_free_inodes + 1);
		$warn_inode_shortfall   = null === $projected_free_inodes ? null : max(0, $effective_warn_inodes - $projected_free_inodes + 1);
		$typed_trigger_reasons  = self::typed_trigger_reasons($trigger_reasons);
		$has_blocking_trigger   = array_filter($typed_trigger_reasons, static fn( array $trigger ): bool => 'blocking' === $trigger['severity']);
		$admission_exception    = self::percentage_byte_floor_exception($demand, $typed_trigger_reasons, $thresholds, $projected_free_bytes, $projected_free_inodes);
		$exception_allowed      = 'admitted' === $admission_exception['status'];
		if ( $exception_allowed ) {
			$refused = false;
			$status  = 'warning';
		}
		$floor_shortfalls       = array(
			'refuse_bytes_absolute'    => null === $projected_free_bytes ? null : max(0, (int) $thresholds['refuse_free_bytes'] - $projected_free_bytes + 1),
			'refuse_bytes_percentage'  => null === $projected_free_bytes || null === $refuse_percent_bytes ? null : max(0, $refuse_percent_bytes - $projected_free_bytes + 1),
			'warn_bytes_absolute'      => null === $projected_free_bytes ? null : max(0, (int) $thresholds['warn_free_bytes'] - $projected_free_bytes + 1),
			'warn_bytes_percentage'    => null === $projected_free_bytes || null === $warn_percent_bytes ? null : max(0, $warn_percent_bytes - $projected_free_bytes + 1),
			'refuse_inodes_absolute'   => null === $projected_free_inodes ? null : max(0, (int) $thresholds['refuse_free_inodes'] - $projected_free_inodes + 1),
			'refuse_inodes_percentage' => null === $projected_free_inodes || null === $refuse_percent_inodes ? null : max(0, $refuse_percent_inodes - $projected_free_inodes + 1),
			'warn_inodes_absolute'     => null === $projected_free_inodes ? null : max(0, (int) $thresholds['warn_free_inodes'] - $projected_free_inodes + 1),
			'warn_inodes_percentage'   => null === $projected_free_inodes || null === $warn_percent_inodes ? null : max(0, $warn_percent_inodes - $projected_free_inodes + 1),
		);

		$budget = array(
			'workspace_path'               => (string) ( $metrics['workspace_path'] ?? '' ),
			'filesystem_total_bytes'       => $total_bytes,
			'filesystem_used_bytes'        => $filesystem_used_bytes,
			'filesystem_free_bytes'        => $free_bytes,
			'safety_basis'                 => 'independent_filesystem_bytes_and_inodes',
			'free_bytes'                   => $free_bytes,
			'used_bytes'                   => $filesystem_used_bytes,
			'free_gib'                     => null === $free_bytes ? null : round(self::bytes_to_gib($free_bytes), 2),
			'total_bytes'                  => $total_bytes,
			'total_gib'                    => null === $total_bytes ? null : round(self::bytes_to_gib($total_bytes), 2),
			'free_percent'                 => null === $free_percent ? null : round($free_percent, 2),
			'used_percent'                 => null === $used_percent ? null : round($used_percent, 2),
			'projected_demand_bytes'       => $demand_bytes,
			'projected_free_bytes'         => $projected_free_bytes,
			'projected_free_percent'       => null === $projected_free_bytes || null === $total_bytes || 0 === $total_bytes ? null : round(( $projected_free_bytes / $total_bytes ) * 100, 2),
			'projected_demand_inodes'      => $demand_inodes,
			'projected_free_inodes'        => $projected_free_inodes,
			'projected_free_inode_percent' => null === $projected_free_inodes || null === $total_inodes || 0 === $total_inodes ? null : round(( $projected_free_inodes / $total_inodes ) * 100, 2),
			'demand_source'                => $demand_source,
			'demand_plan'                  => $demand,
			'filesystem_total_inodes'      => $total_inodes,
			'filesystem_used_inodes'       => $used_inodes,
			'filesystem_free_inodes'       => $free_inodes,
			'total_inodes'                 => $total_inodes,
			'used_inodes'                  => $used_inodes,
			'free_inodes'                  => $free_inodes,
			'free_inode_percent'           => null === $free_inode_percent ? null : round($free_inode_percent, 2),
			'used_inode_percent'           => null === $used_inode_percent ? null : round($used_inode_percent, 2),
			'inode_probe'                  => (string) ( $metrics['inode_probe'] ?? 'unavailable' ),
			'workspace_allocated_bytes'    => $workspace_allocated_bytes,
			'workspace_size_bytes'         => $workspace_allocated_bytes,
			'workspace_size_exact'         => false,
			'workspace_usage_probe'        => (string) ( $metrics['workspace_usage_probe'] ?? 'unavailable' ),
			'mount_target'                 => isset($metrics['mount_target']) ? (string) $metrics['mount_target'] : null,
			'mount_source'                 => isset($metrics['mount_source']) ? (string) $metrics['mount_source'] : null,
			'mount_source_subdirectory'    => isset($metrics['mount_source_subdirectory']) ? (string) $metrics['mount_source_subdirectory'] : null,
			'shared_usage_estimate_bytes'  => $shared_usage_estimate_bytes,
			'shared_usage_detected'        => $shared_usage_detected,
			'diagnostic_messages'          => $diagnostic_messages,
			'worktree_count'               => $count,
			'warn_free_bytes'              => $thresholds['warn_free_bytes'],
			'warn_free_gib'                => round(self::bytes_to_gib( (int) $thresholds['warn_free_bytes'] ), 2),
			'warn_free_percent'            => $thresholds['warn_free_percent'],
			'refuse_free_bytes'            => $thresholds['refuse_free_bytes'],
			'refuse_free_gib'              => round(self::bytes_to_gib( (int) $thresholds['refuse_free_bytes'] ), 2),
			'refuse_free_percent'          => $thresholds['refuse_free_percent'],
			'effective_refuse_bytes'       => $effective_refuse_bytes,
			'effective_refuse_gib'         => round(self::bytes_to_gib($effective_refuse_bytes), 2),
			'effective_warn_bytes'         => $effective_warn_bytes,
			'effective_warn_gib'           => round(self::bytes_to_gib($effective_warn_bytes), 2),
			'refuse_percent_bytes_floor'   => $refuse_percent_bytes,
			'warn_percent_bytes_floor'     => $warn_percent_bytes,
			'warn_free_inodes'             => $thresholds['warn_free_inodes'],
			'warn_free_inode_percent'      => $thresholds['warn_free_inode_percent'],
			'refuse_free_inodes'           => $thresholds['refuse_free_inodes'],
			'refuse_free_inode_percent'    => $thresholds['refuse_free_inode_percent'],
			'effective_refuse_inodes'      => $effective_refuse_inodes,
			'effective_warn_inodes'        => $effective_warn_inodes,
			'refuse_percent_inode_floor'   => $refuse_percent_inodes,
			'warn_percent_inode_floor'     => $warn_percent_inodes,
			'floor_shortfalls'             => $floor_shortfalls,
			'refuse_byte_shortfall'        => $refuse_byte_shortfall,
			'warn_byte_shortfall'          => $warn_byte_shortfall,
			'refuse_inode_shortfall'       => $refuse_inode_shortfall,
			'warn_inode_shortfall'         => $warn_inode_shortfall,
			'target_recovery_bytes'        => $refused ? $refuse_byte_shortfall : $warn_byte_shortfall,
			'target_recovery_inodes'       => $refused ? $refuse_inode_shortfall : $warn_inode_shortfall,
			'warn_worktree_count'          => $thresholds['warn_worktree_count'],
			'forced'                       => $forced,
			'status'                       => $status,
			'creation_allowed'             => ! $refused,
			'admission_exception'          => $admission_exception,
			'warnings'                     => $warnings,
			'emergency_triggered'          => array() !== $trigger_reasons,
			'trigger_reasons'              => $trigger_reasons,
			'typed_trigger_reasons'        => $typed_trigger_reasons,
			'cleanup_dry_run_command'      => 'studio wp datamachine-code workspace worktree cleanup --dry-run',
			'artifact_cleanup_command'     => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
			'emergency_cleanup_command'    => 'studio wp datamachine-code workspace worktree emergency-cleanup --format=json',
			'cleanup_recommendations'      => self::cleanup_recommendations($projected_free_bytes, $effective_refuse_bytes),
			'force_override_required'      => ! $exception_allowed && array() !== $has_blocking_trigger,
			'force_override_applied'       => $forced && array() !== $has_blocking_trigger,
		);
		$budget['diagnostic_id']        = self::DIAGNOSTIC_ID;
		$budget['advisory_fingerprint'] = self::advisory_fingerprint( $budget );
		$budget['evidence_reference']   = sprintf( '%s@%s', self::DIAGNOSTIC_ID, substr( $budget['advisory_fingerprint'], 0, 12 ) );
		$budget['evidence_command']     = 'studio wp datamachine-code workspace hygiene --format=json';
		$budget['recovery_actions']     = array(
			array(
				'action'  => 'inspect_full_capacity_evidence',
				'command' => $budget['evidence_command'],
			),
			array(
				'action'  => 'review_bounded_workspace_sizes',
				'command' => 'studio wp datamachine-code workspace hygiene --include-sizes --size-limit=100 --format=json',
			),
		);
		if ( in_array( 'worktree_count_warning_threshold', $trigger_reasons, true ) ) {
			$budget['recovery_actions'][] = array(
				'action'  => 'preview_bounded_worktree_cleanup',
				'command' => 'studio wp datamachine-code workspace worktree cleanup-eligible-drain --limit=25 --format=json',
			);
			$budget['recovery_actions'][] = array(
				'action'  => 'preview_stale_git_registrations',
				'command' => 'studio wp datamachine-code workspace worktree prune --dry-run --format=json',
			);
		}

		return $budget;
	}

	/** Build a state-level fingerprint so unchanged advisories can be suppressed safely. */
	private static function advisory_fingerprint( array $budget ): string {
		$thresholds = array();
		foreach ( (array) ( $budget['typed_trigger_reasons'] ?? array() ) as $trigger ) {
			$trigger   = (array) $trigger;
			$threshold = (string) ( $trigger['threshold'] ?? '' );
			$resource  = (string) ( $trigger['resource'] ?? '' );
			$key       = $threshold . ':' . $resource;
			switch ( $key ) {
				case 'warning_floor:worktree_count':
					$thresholds['warn_worktree_count'] = $budget['warn_worktree_count'] ?? null;
					break;
				case 'warning_floor:bytes':
					$thresholds['effective_warn_bytes'] = $budget['effective_warn_bytes'] ?? null;
					break;
				case 'refusal_floor:bytes':
					$thresholds['effective_refuse_bytes'] = $budget['effective_refuse_bytes'] ?? null;
					break;
				case 'warning_floor:inodes':
					$thresholds['effective_warn_inodes'] = $budget['effective_warn_inodes'] ?? null;
					break;
				case 'refusal_floor:inodes':
					$thresholds['effective_refuse_inodes'] = $budget['effective_refuse_inodes'] ?? null;
					break;
			}
		}

		return hash(
			'sha256',
			serialize(
				array(
					'status'                  => (string) ( $budget['status'] ?? 'unknown' ),
					'creation_allowed'        => (bool) ( $budget['creation_allowed'] ?? false ),
					'force_override_required' => (bool) ( $budget['force_override_required'] ?? false ),
					'force_override_applied'  => (bool) ( $budget['force_override_applied'] ?? false ),
					'trigger_reasons'         => array_values( array_map( 'strval', (array) ( $budget['trigger_reasons'] ?? array() ) ) ),
					'thresholds'               => $thresholds,
				)
			)
		);
	}

	/** Admit an explicitly requested, small, calibrated demand past only the byte percentage refusal floor. */
	private static function percentage_byte_floor_exception( array $demand, array $triggers, array $thresholds, ?int $projected_free_bytes, ?int $projected_free_inodes ): array {
		$requested = ! empty($demand['allow_percentage_byte_floor_exception']);
		$source    = (string) ($demand['source'] ?? 'not_provided');
		$bytes     = isset($demand['bytes']) && is_numeric($demand['bytes']) ? max(0, (int) $demand['bytes']) : 0;
		$maximum   = 64 * 1024 * 1024;
		if ( function_exists('apply_filters') ) {
			$maximum = max(1, (int) apply_filters('datamachine_code_worktree_percentage_byte_floor_exception_max_demand_bytes', $maximum, $demand));
		}
		$blocking = array_values(array_map(static fn( array $trigger ): string => (string) ($trigger['code'] ?? ''), array_filter($triggers, static fn( array $trigger ): bool => 'blocking' === ($trigger['severity'] ?? ''))));
		$trusted  = in_array($source, array( 'conservative_defaults', 'compatible_observed_percentile', 'post_materialization_target_tree_conservative' ), true);
		$status   = 'not_requested';
		$reason   = null;
		if ( $requested ) {
			if ( array( 'projected_free_bytes_percentage_refusal_floor' ) !== $blocking ) {
				$status = 'rejected';
				$reason = 'not_percentage_byte_floor_only';
			} elseif ( ! $trusted ) {
				$status = 'rejected';
				$reason = 'untrusted_demand_source';
			} elseif ( 0 === $bytes || $bytes >= $maximum ) {
				$status = 'rejected';
				$reason = 'demand_exceeds_bounded_ceiling';
			} else {
				$status = 'admitted';
			}
		}
		return array(
			'type'                           => 'percentage_byte_floor_demand_bounded',
			'operator_intent'                => $requested,
			'status'                         => $status,
			'rejection_reason'               => $reason,
			'waived_trigger'                 => 'admitted' === $status ? 'projected_free_bytes_percentage_refusal_floor' : null,
			'blocking_triggers'              => $blocking,
			'demand_bytes'                   => $bytes,
			'maximum_demand_bytes'           => $maximum,
			'demand_source'                  => $source,
			'trusted_demand_source'          => $trusted,
			'projected_post_create_capacity' => array( 'free_bytes' => $projected_free_bytes, 'free_inodes' => $projected_free_inodes ),
			'retained_hard_floors'           => array( 'refuse_free_bytes' => (int) $thresholds['refuse_free_bytes'], 'refuse_free_inodes' => (int) $thresholds['refuse_free_inodes'], 'refuse_free_inode_percent' => (float) $thresholds['refuse_free_inode_percent'] ),
		);
	}

	/**
	 * Build concise operator remediation commands for disk-pressure failures.
	 *
	 * @param  int|null $free_bytes              Current free bytes.
	 * @param  int      $effective_refuse_bytes Effective refusal floor.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cleanup_recommendations( ?int $free_bytes, int $effective_refuse_bytes ): array {
		$target_recovery = null === $free_bytes ? null : max(0, $effective_refuse_bytes - $free_bytes + 1);
		$target_human    = null === $target_recovery ? 'enough space to clear the refusal threshold' : self::format_bytes($target_recovery);

		return array(
			array(
				'priority'                => 1,
				'action'                  => 'create a DB-backed plan for the largest reconstructable artifacts',
				'target_recovery_bytes'   => $target_recovery,
				'target_recovery'         => $target_human,
				'target_recovery_inodes'  => null,
				'expected_reclaim_bytes'  => $target_recovery,
				'expected_reclaim'        => $target_human,
				'expected_reclaim_inodes' => null,
				'command'                 => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
				'preview_command'         => 'studio wp datamachine-code workspace cleanup plan --mode=artifacts --format=json',
				'apply_command'           => 'studio wp datamachine-code workspace cleanup apply <run-id>',
				'apply_note'              => 'Review output includes the DB-backed run_id required by the apply command.',
			),
			array(
				'priority'                => 2,
				'action'                  => 'review bounded cleanup-eligible worktrees; apply revalidates before removal',
				'target_recovery_bytes'   => $target_recovery,
				'target_recovery'         => $target_human,
				'target_recovery_inodes'  => null,
				'expected_reclaim_bytes'  => $target_recovery,
				'expected_reclaim'        => $target_human,
				'expected_reclaim_inodes' => null,
				'command'                 => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25',
				'preview_command'         => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25',
				'apply_command'           => 'studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=25',
				'apply_note'              => 'Apply runs fresh dirty, unpushed, containment, and primary safety probes and may skip rows that the cheap inventory review listed.',
			),
			array(
				'priority'                => 3,
				'action'                  => 'generate combined emergency cleanup report',
				'target_recovery_bytes'   => $target_recovery,
				'target_recovery'         => $target_human,
				'target_recovery_inodes'  => null,
				'expected_reclaim_bytes'  => $target_recovery,
				'expected_reclaim'        => $target_human,
				'expected_reclaim_inodes' => null,
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
		$inode_used = null === ( $budget['used_inodes'] ?? null ) ? 'unknown' : number_format( (int) $budget['used_inodes']);
		if ( null !== ( $budget['used_inode_percent'] ?? null ) ) {
			$inode_used .= sprintf(' (%.1f%%)', (float) $budget['used_inode_percent']);
		}
		$inode_total = null === ( $budget['total_inodes'] ?? null ) ? 'unknown total' : number_format( (int) $budget['total_inodes']) . ' total';
		$summary    .= sprintf(' Inodes: %s used, %s free, %s; status=%s.', $inode_used, $inode_free, $inode_total, (string) ( $budget['status'] ?? 'unknown' ));
		if ( (int) ( $budget['projected_demand_bytes'] ?? 0 ) > 0 || (int) ( $budget['projected_demand_inodes'] ?? 0 ) > 0 ) {
			$summary .= sprintf(
				' Projected after demand (%s): %s free bytes, %s free inodes.',
				(string) ( $budget['demand_source'] ?? 'unknown' ),
				null === ( $budget['projected_free_bytes'] ?? null ) ? 'unknown' : self::format_bytes( (int) $budget['projected_free_bytes'] ),
				null === ( $budget['projected_free_inodes'] ?? null ) ? 'unknown' : number_format( (int) $budget['projected_free_inodes'] )
			);
		}
		if ( ! empty($budget['shared_usage_detected']) && null !== ( $budget['shared_usage_estimate_bytes'] ?? null ) ) {
			$summary .= sprintf(
				' Estimated usage outside the measured workspace subtree: %.1f GiB.',
				self::bytes_to_gib( (int) $budget['shared_usage_estimate_bytes'] )
			);
		}
		$advisory              = array();
		$blocking              = array();
		$typed_trigger_reasons = isset($budget['typed_trigger_reasons']) && is_array($budget['typed_trigger_reasons'])
			? $budget['typed_trigger_reasons']
			: self::typed_trigger_reasons( (array) ( $budget['trigger_reasons'] ?? array() ) );
		foreach ( $typed_trigger_reasons as $trigger ) {
			$trigger = (array) $trigger;
			$code    = (string) ( $trigger['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			if ( 'blocking' === ( $trigger['severity'] ?? '' ) ) {
				$blocking[] = $code;
			} else {
				$advisory[] = $code;
			}
		}
		$has_blocking_trigger = array() !== $blocking;
		$force_required       = array_key_exists('force_override_required', $budget) ? (bool) $budget['force_override_required'] : $has_blocking_trigger;
		$force_applied        = array_key_exists('force_override_applied', $budget) ? (bool) $budget['force_override_applied'] : ( ! empty($budget['forced']) && $has_blocking_trigger );
		$summary .= sprintf(
			' Admission: %s; force override required=%s; advisory triggers=%s; blocking triggers=%s.',
			array_key_exists('creation_allowed', $budget) ? ( ! empty($budget['creation_allowed']) ? 'allowed' : 'blocked' ) : ( 'refused' === ( $budget['status'] ?? '' ) ? 'blocked' : 'allowed' ),
			$force_required ? 'yes' : 'no',
			empty($advisory) ? 'none' : implode(',', $advisory),
			empty($blocking) ? 'none' : implode(',', $blocking)
		);
		$summary .= sprintf(' Force override applied=%s.', $force_applied ? 'yes' : 'no');

		return $summary;
	}

	/** Format one bounded advisory that references the complete structured evidence. */
	public static function format_advisory( array $budget ): string {
		$codes = array_values( array_filter( array_map( 'strval', (array) ( $budget['trigger_reasons'] ?? array() ) ) ) );
		if ( array() === $codes ) {
			return '';
		}
		$reference = (string) ( $budget['evidence_reference'] ?? self::DIAGNOSTIC_ID );
		$admission = ! empty( $budget['creation_allowed'] ) ? 'admission allowed' : 'admission blocked';
		$preview   = '';
		foreach ( (array) ( $budget['recovery_actions'] ?? array() ) as $action ) {
			if ( 'preview_bounded_worktree_cleanup' === ( $action['action'] ?? '' ) ) {
				$preview = ' Preview bounded worktree cleanup: ' . (string) ( $action['command'] ?? '' );
				continue;
			}
			if ( 'preview_stale_git_registrations' === ( $action['action'] ?? '' ) ) {
				$preview .= ' Preview stale Git registrations: ' . (string) ( $action['command'] ?? '' );
			}
		}

		return sprintf(
			'Capacity advisory [%s]: status=%s; %s; triggers=%s. Full evidence: %s',
			$reference,
			(string) ( $budget['status'] ?? 'unknown' ),
			$admission,
			implode(',', $codes),
			(string) ( $budget['evidence_command'] ?? 'studio wp datamachine-code workspace hygiene --format=json' )
		) . $preview;
	}

	/** @return array<int,array{code:string,severity:string,resource:string,threshold:string}> */
	private static function typed_trigger_reasons( array $trigger_reasons ): array {
		return array_map(
			static function ( string $code ): array {
				$is_blocking = str_contains($code, '_refusal_floor');
				return array(
					'code'      => $code,
					'severity'  => $is_blocking ? 'blocking' : 'advisory',
					'resource'  => str_contains($code, '_inodes_') ? 'inodes' : ( 'worktree_count_warning_threshold' === $code ? 'worktree_count' : 'bytes' ),
					'threshold' => $is_blocking ? 'refusal_floor' : 'warning_floor',
				);
			},
			array_values(array_map('strval', $trigger_reasons))
		);
	}

	/**
	 * Format the capacity thresholds that caused the current status.
	 *
	 * @param  array $budget Budget report.
	 * @return array<int,string>
	 */
	public static function format_trigger_reasons( array $budget ): array {
		$formatted_reasons = array();
		foreach ( (array) ( $budget['trigger_reasons'] ?? array() ) as $reason ) {
			$reason = (string) $reason;
			switch ( $reason ) {
				case 'projected_free_bytes_absolute_refusal_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem space is %s; blocking refusal threshold is %s. Creation is blocked unless --force is explicit.', $reason, self::format_bytes( (int) ( $budget['projected_free_bytes'] ?? 0 )), self::format_bytes( (int) ( $budget['refuse_free_bytes'] ?? 0 )));
					break;
				case 'projected_free_bytes_percentage_refusal_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem space is %.1f%%; blocking refusal threshold is %.1f%%. Creation is blocked unless --force is explicit.', $reason, (float) ( $budget['projected_free_percent'] ?? 0 ), (float) ( $budget['refuse_free_percent'] ?? 0 ));
					break;
				case 'projected_free_bytes_absolute_warning_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem space is %s; advisory warning threshold is %s. Creation remains allowed.', $reason, self::format_bytes( (int) ( $budget['projected_free_bytes'] ?? 0 )), self::format_bytes( (int) ( $budget['warn_free_bytes'] ?? 0 )));
					break;
				case 'projected_free_bytes_percentage_warning_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem space is %.1f%%; advisory warning threshold is %.1f%%. Creation remains allowed.', $reason, (float) ( $budget['projected_free_percent'] ?? 0 ), (float) ( $budget['warn_free_percent'] ?? 0 ));
					break;
				case 'filesystem_free_bytes_measurement_unavailable':
					$formatted_reasons[] = $reason . ': free filesystem space could not be measured. Creation remains allowed, but capacity evidence requires review.';
					break;
				case 'projected_free_inodes_absolute_refusal_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem inodes are %s; blocking refusal threshold is %s. Creation is blocked unless --force is explicit.', $reason, number_format( (int) ( $budget['projected_free_inodes'] ?? 0 )), number_format( (int) ( $budget['refuse_free_inodes'] ?? 0 )));
					break;
				case 'projected_free_inodes_percentage_refusal_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem inodes are %.1f%% free; blocking refusal threshold is %.1f%%. Creation is blocked unless --force is explicit.', $reason, (float) ( $budget['projected_free_inode_percent'] ?? 0 ), (float) ( $budget['refuse_free_inode_percent'] ?? 0 ));
					break;
				case 'projected_free_inodes_absolute_warning_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem inodes are %s; advisory warning threshold is %s. Creation remains allowed.', $reason, number_format( (int) ( $budget['projected_free_inodes'] ?? 0 )), number_format( (int) ( $budget['warn_free_inodes'] ?? 0 )));
					break;
				case 'projected_free_inodes_percentage_warning_floor':
					$formatted_reasons[] = sprintf('%s: projected free filesystem inodes are %.1f%% free; advisory warning threshold is %.1f%%. Creation remains allowed.', $reason, (float) ( $budget['projected_free_inode_percent'] ?? 0 ), (float) ( $budget['warn_free_inode_percent'] ?? 0 ));
					break;
				case 'worktree_count_warning_threshold':
					$formatted_reasons[] = sprintf('%s: workspace has %d worktree-like directories; advisory warning threshold is %d. Creation remains allowed.', $reason, (int) ( $budget['worktree_count'] ?? 0 ), (int) ( $budget['warn_worktree_count'] ?? 0 ));
					break;
			}
		}

		return $formatted_reasons;
	}

	/** Format byte evidence for operator-facing capacity remediation. */
	public static function format_bytes_for_operator( int|float $bytes ): string {
		return self::format_bytes($bytes);
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
	 * Read inode capacity using bounded GNU/BSD `df -i` probes without walking files.
	 *
	 * @param callable|null $runner Deterministic test seam receiving argv and probe.
	 * @return array{total_inodes:int|null,free_inodes:int|null,probe:string}
	 */
	public static function probe_inode_capacity( string $workspace_path, ?callable $runner = null, ?WallClockBudget $budget = null ): array {
		if ( ! is_dir($workspace_path) ) {
			return self::normalize_inode_metrics(null);
		}
		$probes = array(
			'gnu_df_i' => array( 'df', '-P', '-i', '--', $workspace_path ),
			'bsd_df_i' => array( 'df', '-P', '-i', $workspace_path ),
		);
		foreach ( $probes as $probe => $argv ) {
			$timeout = null === $budget ? 2 : $budget->probe_timeout_seconds(2);
			if ( 0 === $timeout ) {
				break;
			}
			if ( null !== $runner ) {
				$result = $runner($argv, $probe);
			} else {
				$command = CommandSpec::from_argv($argv);
				if ( $command instanceof \WP_Error ) {
					continue;
				}
				$result = ProcessRunner::run($command, array(
					'timeout_seconds'  => $timeout,
					'output_cap_bytes' => 256,
					'error_as_result'  => true,
				));
			}
			if ( $result instanceof \WP_Error || ! is_array($result) || empty($result['success']) ) {
				continue;
			}
			$parsed = self::parse_inode_probe_output( (string) ( $result['output'] ?? '' ), $probe);
			if ( 'unavailable' !== $parsed['probe'] ) {
				return $parsed;
			}
		}
		return self::normalize_inode_metrics(null);
	}

	/**
	 * Parse GNU or BSD inode columns by header name rather than column position.
	 *
	 * @return array{total_inodes:int|null,free_inodes:int|null,probe:string}
	 */
	public static function parse_inode_probe_output( string $output, string $probe ): array {
		if ( ! in_array($probe, array( 'gnu_df_i', 'bsd_df_i' ), true) ) {
			return self::normalize_inode_metrics(null);
		}
		$lines = preg_split('/\R/', trim($output));
		$lines = false === $lines ? array() : array_values(array_filter(array_map('trim', $lines), static fn( string $line ): bool => '' !== $line));
		if ( count($lines) < 2 ) {
			return self::normalize_inode_metrics(null);
		}
		$headers     = preg_split('/\s+/', strtolower($lines[0]));
		$headers     = false === $headers ? array() : $headers;
		$values      = preg_split('/\s+/', $lines[ count($lines) - 1 ]);
		$values      = false === $values ? array() : $values;
		$used_index  = array_search('iused', $headers, true);
		$free_index  = array_search('ifree', $headers, true);
		$total_index = array_search('inodes', $headers, true);
		if ( false === $used_index || false === $free_index || ! isset($values[ $used_index ], $values[ $free_index ]) ) {
			return self::normalize_inode_metrics(null);
		}
		$used  = filter_var($values[ $used_index ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ));
		$free  = filter_var($values[ $free_index ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ));
		$total = false !== $total_index && isset($values[ $total_index ])
			? filter_var($values[ $total_index ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ))
			: ( false !== $used && false !== $free ? $used + $free : false );
		if ( false === $used || false === $free || false === $total || $free > $total ) {
			return self::normalize_inode_metrics(null);
		}
		return array(
			'total_inodes' => $total,
			'free_inodes'  => $free,
			'probe'        => $probe,
		);
	}

	private static function measure_inode_capacity( string $workspace_path, mixed $budget = null ): array {
		return self::probe_inode_capacity($workspace_path, null, $budget instanceof WallClockBudget ? $budget : null);
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
		$free  = max(0, (int) $metrics['free_inodes']);
		if ( $free > $total ) {
			return array(
				'total_inodes' => null,
				'free_inodes'  => null,
				'probe'        => 'unavailable',
			);
		}
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
	 * Count managed worktree names without probing every workspace entry.
	 *
	 * Workspace lifecycle owns the `<repo>@<slug>` directory convention. Counting
	 * its names from the root snapshot avoids one remote-filesystem stat per
	 * unrelated worktree while preserving the existing aggregate evidence.
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
			if ( '.' !== $entry && '..' !== $entry && str_contains($entry, '@') ) {
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
	private static function bytes_to_gib( int $bytes ): float {
		return $bytes / self::BYTES_PER_GIB;
	}
}
