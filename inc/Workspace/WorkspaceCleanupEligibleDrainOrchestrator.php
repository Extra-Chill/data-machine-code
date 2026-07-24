<?php
/**
 * Cleanup-eligible worktree drain orchestration.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

/**
 * Repeats the bounded cleanup-eligible primitive until it has no safe rows left.
 */
class WorkspaceCleanupEligibleDrainOrchestrator {

	private const DEFAULT_SOURCE = 'workspace_cleanup_eligible_drain';

	/** @var callable */
	private $ability_resolver;

	/** @var callable */
	private $clock;

	/** @var callable */
	private $disk_reporter;

	/**
	 * @param callable|null $ability_resolver Optional resolver receiving an ability name.
	 * @param callable|null $clock            Optional clock returning microtime-style seconds.
	 * @param callable|null $disk_reporter    Optional reporter receiving workspace path.
	 */
	public function __construct( ?callable $ability_resolver = null, ?callable $clock = null, ?callable $disk_reporter = null ) {
		$this->ability_resolver = $ability_resolver ? $ability_resolver : static fn( string $name ) => function_exists('wp_get_ability') ? wp_get_ability($name) : null;
		$this->clock            = $clock ? $clock : static fn(): float => microtime(true);
		$this->disk_reporter    = $disk_reporter ? $disk_reporter : array( $this, 'build_disk_report' );
	}

	/**
	 * Drain cleanup-eligible rows through the bounded cleanup primitive.
	 *
	 * @param  array<string,mixed> $input Orchestration input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( array $input ): array|\WP_Error {
		$apply                     = ! empty($input['apply']);
		$force                     = ! empty($input['force']);
		$include_repaired_metadata = ! empty($input['include_repaired_metadata']);
		$limit                     = isset($input['limit']) ? max(1, min(200, (int) $input['limit'])) : 25;
		$passes                    = isset($input['passes']) ? max(1, min(100, (int) $input['passes'])) : 10;
		$source                    = isset($input['source']) && '' !== trim( (string) $input['source']) ? trim( (string) $input['source']) : self::DEFAULT_SOURCE;
		$deadline                  = null;
		$started_at                = $this->now();

		if ( ! empty($input['discard_unpushed']) ) {
			return new \WP_Error('cleanup_eligible_drain_refuses_unpushed_discard', 'Cleanup-eligible drain will not discard unpushed commits. Use the bounded single-batch command explicitly for that data-loss mode.', array( 'status' => 400 ));
		}

		if ( isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ) {
			$budget_seconds = $this->parse_budget(trim( (string) $input['until_budget']));
			if ( is_wp_error($budget_seconds) ) {
				return $budget_seconds;
			}
			$deadline = $started_at + $budget_seconds;
		}

		$ability = ( $this->ability_resolver )('datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply');
		if ( ! is_object($ability) || ! is_callable(array( $ability, 'execute' )) ) {
			return new \WP_Error('cleanup_eligible_drain_ability_missing', 'Bounded cleanup-eligible apply ability is not available.', array( 'status' => 500 ));
		}

		$result = array(
			'success'      => true,
			'mode'         => 'cleanup_eligible_drain',
			'applied'      => $apply,
			'destructive'  => $apply,
			'force'        => $force,
			'limit'        => $limit,
			'passes'       => $passes,
			'generated_at' => gmdate('c'),
			'pass_results' => array(),
			'summary'      => array(
				'passes'          => 0,
				'processed'       => 0,
				'planned'         => 0,
				'would_remove'    => 0,
				'removed'         => 0,
				'skipped'         => 0,
				'bytes_reclaimed' => 0,
			),
			'evidence'     => array(
				'safety' => $apply
					? 'Repeats bounded cleanup-eligible apply only; unpushed commits remain protected and discard_unpushed is refused.'
					: 'Preview only. Re-run with --apply to drain cleanup-eligible worktrees.',
			),
		);

		$effective_passes = $apply ? $passes : 1;
		$workspace_path   = '';
		$stop_reason      = 'pass_limit';

		for ( $pass = 1; $pass <= $effective_passes; ++$pass ) {
			if ( $this->budget_expired($deadline) ) {
				$stop_reason = 'budget_exhausted';
				break;
			}

			$pass_input = array(
				'dry_run'                   => ! $apply,
				'force'                     => $force,
				'limit'                     => $limit,
				'include_repaired_metadata' => $include_repaired_metadata,
				'source'                    => $source,
			);
			foreach ( array( 'older_than', 'sort', 'remove_timeout' ) as $key ) {
				if ( isset($input[ $key ]) && '' !== trim( (string) $input[ $key ]) ) {
					$pass_input[ $key ] = $input[ $key ];
				}
			}

			$pass_result = $ability->execute($pass_input);
			if ( is_wp_error($pass_result) ) {
				return $pass_result;
			}
			if ( ! is_array($pass_result) ) {
				return new \WP_Error('cleanup_eligible_drain_invalid_result', 'Bounded cleanup-eligible apply returned an invalid result.', array( 'status' => 500 ));
			}

			$workspace_path = '' !== $workspace_path ? $workspace_path : (string) ( $pass_result['workspace_path'] ?? '' );
			$summary        = (array) ( $pass_result['summary'] ?? array() );
			$continuation   = (array) ( $pass_result['continuation'] ?? array() );
			$evidence       = (array) ( $pass_result['evidence'] ?? array() );
			$pass_summary   = array(
				'pass'              => $pass,
				'dry_run'           => ! empty($pass_result['dry_run']),
				'processed'         => (int) ( $summary['processed'] ?? 0 ),
				'planned'           => ! empty($pass_result['dry_run']) ? count( (array) ( $pass_result['candidates'] ?? array() ) ) : 0,
				'would_remove'      => (int) ( $summary['would_remove'] ?? 0 ),
				'removed'           => (int) ( $summary['removed'] ?? 0 ),
				'skipped'           => (int) ( $summary['skipped'] ?? 0 ),
				'bytes_reclaimed'   => (int) ( $summary['bytes_reclaimed'] ?? 0 ),
				'remaining_total'   => (int) ( $continuation['remaining_total'] ?? 0 ),
				'elapsed_ms'        => (int) ( $evidence['elapsed_ms'] ?? 0 ),
				'removed_handles'   => array_values(array_filter(array_map(fn( $row ) => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '', (array) ( $pass_result['removed'] ?? array() )))),
				'candidate_handles' => array_values(array_filter(array_map(fn( $row ) => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '', (array) ( $pass_result['candidates'] ?? array() )))),
				'skipped_by_reason' => $this->summarize_skipped( (array) ( $pass_result['skipped'] ?? array() ) ),
			);

			$result['pass_results'][] = $pass_summary;
			++$result['summary']['passes'];
			$result['summary']['processed']       += $pass_summary['processed'];
			$result['summary']['planned']         += $pass_summary['planned'];
			$result['summary']['would_remove']    += $pass_summary['would_remove'];
			$result['summary']['removed']         += $pass_summary['removed'];
			$result['summary']['skipped']         += $pass_summary['skipped'];
			$result['summary']['bytes_reclaimed'] += $pass_summary['bytes_reclaimed'];

			if ( ! $apply ) {
				$stop_reason = 'preview';
				break;
			}
			if ( 0 === $pass_summary['removed'] ) {
				$stop_reason = 'empty';
				break;
			}
			if ( 0 === $pass_summary['remaining_total'] && $pass_summary['removed'] < $limit ) {
				$stop_reason = 'empty';
				break;
			}
		}

		if ( 'pass_limit' === $stop_reason && $this->budget_expired($deadline) ) {
			$stop_reason = 'budget_exhausted';
		}

		$result['summary']['stop_reason']       = $stop_reason;
		$result['summary']['final_free_space']  = ( $this->disk_reporter )($workspace_path);
		$result['evidence']['elapsed_ms']       = (int) round(( $this->now() - $started_at ) * 1000);
		$result['evidence']['budget_exhausted'] = 'budget_exhausted' === $stop_reason;

		if ( ! $apply ) {
			$result['next_commands'][] = sprintf('studio wp datamachine-code workspace worktree cleanup-eligible-drain --apply --limit=%d --passes=%d --format=json', $limit, $passes);
		} elseif ( 'pass_limit' === $stop_reason ) {
			$result['next_commands'][] = sprintf('studio wp datamachine-code workspace worktree cleanup-eligible-drain --apply --limit=%d --passes=%d --format=json', $limit, $passes);
		}

		return $result;
	}

	private function now(): float {
		return (float) ( $this->clock )();
	}

	private function parse_budget( string $duration ): int|\WP_Error {
		if ( ! preg_match('/^(\d+)([smh])$/', trim($duration), $matches) ) {
			return new \WP_Error('invalid_cleanup_eligible_drain_budget', 'Invalid until_budget duration. Use a compact value like 60s, 10m, or 1h.', array( 'status' => 400 ));
		}

		$value = (int) $matches[1];
		if ( $value < 1 ) {
			return new \WP_Error('invalid_cleanup_eligible_drain_budget', 'Invalid until_budget duration. Duration must be greater than zero.', array( 'status' => 400 ));
		}

		return match ( $matches[2] ) {
			'h' => $value * HOUR_IN_SECONDS,
			'm' => $value * MINUTE_IN_SECONDS,
			default => $value,
		};
	}

	private function budget_expired( ?float $deadline ): bool {
		return null !== $deadline && $this->now() >= $deadline;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function summarize_skipped( array $rows ): array {
		$summary = array();
		foreach ( $rows as $row ) {
			$reason             = (string) ( $row['reason_code'] ?? 'unknown' );
			$summary[ $reason ] = (int) ( $summary[ $reason ] ?? 0 ) + 1;
		}
		return $summary;
	}

	/** @return array<string,mixed> */
	private function build_disk_report( string $workspace_path ): array {
		if ( '' === $workspace_path || ! is_dir($workspace_path) ) {
			return array(
				'path'        => $workspace_path,
				'free_bytes'  => null,
				'free_human'  => 'unknown',
				'total_bytes' => null,
			);
		}

		$free  = disk_free_space($workspace_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_free_space
		$total = disk_total_space($workspace_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_total_space
		return array(
			'path'        => $workspace_path,
			'free_bytes'  => false === $free ? null : (int) $free,
			'free_human'  => false === $free ? 'unknown' : $this->format_bytes( (int) $free ),
			'total_bytes' => false === $total ? null : (int) $total,
		);
	}

	private function format_bytes( int $bytes ): string {
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$value      = (float) max(0, $bytes);
		$unit       = 0;
		$unit_count = count($units);
		while ( $value >= 1024 && $unit < $unit_count - 1 ) {
			$value /= 1024;
			++$unit;
		}
		return sprintf('%.1f %s', $value, $units[ $unit ]);
	}
}
