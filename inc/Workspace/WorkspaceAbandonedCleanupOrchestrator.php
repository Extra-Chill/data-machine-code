<?php
/**
 * Abandoned worktree cleanup orchestration service.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

/**
 * Composes bounded workspace cleanup abilities into the abandoned cleanup flow.
 */
class WorkspaceAbandonedCleanupOrchestrator {

	private const DEFAULT_SOURCE = 'workspace_abandoned_cleanup_ability';

	/** @var callable */
	private $ability_resolver;

	/** @var callable */
	private $clock;

	private ?object $active_no_signal_report_ability = null;

	/**
	 * @param callable|null $ability_resolver Optional resolver receiving an ability name.
	 * @param callable|null $clock            Optional clock returning microtime-style seconds.
	 */
	public function __construct( ?callable $ability_resolver = null, ?callable $clock = null ) {
		$this->ability_resolver = $ability_resolver ? $ability_resolver : static fn( string $name ) => function_exists('wp_get_ability') ? wp_get_ability($name) : null;
		$this->clock            = $clock ? $clock : static fn(): float => microtime(true);
	}

	/**
	 * Run the operator-facing abandoned-worktree cleanup workflow.
	 *
	 * @param  array<string,mixed> $input Orchestration input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( array $input ): array|\WP_Error {
		$active_no_signal_drain = ! empty($input['active_no_signal_drain']);
		$apply                  = ! empty($input['apply']);
		$force                  = ! empty($input['force']);
		if ( $active_no_signal_drain && $force ) {
			return new \WP_Error('active_no_signal_drain_refuses_force', 'Active/no-signal drain will not force cleanup. Protected blockers remain blocked.', array( 'status' => 400 ));
		}
		if ( $active_no_signal_drain && ! empty($input['discard_unpushed']) ) {
			return new \WP_Error('active_no_signal_drain_refuses_unpushed_discard', 'Active/no-signal drain will not discard unpushed commits.', array( 'status' => 400 ));
		}
		$limit         = isset($input['limit']) ? max(1, min(1000, (int) $input['limit'])) : 100;
		$passes        = isset($input['passes']) ? max(1, min(25, (int) $input['passes'])) : 5;
		$offset        = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
		$default_stage = $active_no_signal_drain ? 'finalized' : 'reconcile';
		$stage         = isset($input['stage']) ? strtolower( (string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $input['stage']) ) : $default_stage;
		$stage         = str_replace('_', '-', $stage);
		$until_budget  = isset($input['until_budget']) && '' !== trim( (string) $input['until_budget']) ? trim( (string) $input['until_budget']) : '';
		$source        = isset($input['source']) && '' !== trim( (string) $input['source']) ? trim( (string) $input['source']) : self::DEFAULT_SOURCE;
		$scope         = isset($input['scope']) && '' !== trim( (string) $input['scope']) ? $this->sanitize_scope( (string) $input['scope']) : '';
		$repo_scope    = isset($input['repo']) && '' !== trim( (string) $input['repo']) ? trim( (string) $input['repo']) : '';
		if ( '' === $scope && '' !== $repo_scope ) {
			$scope = $this->sanitize_scope($repo_scope);
		}
		if ( '' === $repo_scope && '' !== $scope && $this->scope_is_worktree_filter($scope) ) {
			$repo_scope = $scope;
		}
		$deadline    = null;
		$stage_order = $this->stage_order();

		if ( ! isset($stage_order[ $stage ]) ) {
			return new \WP_Error('invalid_worktree_abandoned_stage', 'Invalid stage value. Use reconcile, finalized, equivalent-clean, merged, remote-clean, or bounded.', array( 'status' => 400 ));
		}
		if ( '' !== $until_budget ) {
			$budget_seconds = $this->parse_budget($until_budget);
			if ( is_wp_error($budget_seconds) ) {
				return $budget_seconds;
			}
			$deadline = $this->now() + $budget_seconds;
		}

		$abilities = $this->resolve_required_abilities($active_no_signal_drain);
		if ( is_wp_error($abilities) ) {
			return $abilities;
		}

		$started_at  = $this->now();
		$result      = $this->initial_result($apply, $force, $limit, $stage, $offset, $passes, $active_no_signal_drain, $scope);
		$common_page = array(
			'limit'  => $limit,
			'source' => $source,
		);
		if ( '' !== $scope ) {
			$common_page['scope'] = $scope;
		}
		if ( '' !== $repo_scope ) {
			$common_page['repo'] = $repo_scope;
		}

		if ( $apply ) {
			$bounded = $this->run_bounded_apply($abilities['bounded_apply'], $result, true, $force, $limit, $source, 'initial');
			if ( is_wp_error($bounded) ) {
				return $bounded;
			}

			if ( 'bounded' === $stage ) {
				$prune = $this->execute_ability($abilities['prune'], array());
				if ( is_wp_error($prune) ) {
					return $prune;
				}
				$result['steps']['prune'] = $this->summarize_step($prune);

				return $this->finalize_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at, $active_no_signal_drain);
			}
		}

		if ( $stage_order[ $stage ] <= $stage_order['reconcile'] ) {
			$reconcile_input = array_merge(
				$common_page,
				array(
					'dry_run' => ! $apply,
					'apply'   => $apply,
					'offset'  => 'reconcile' === $stage ? $offset : 0,
				)
			);
			$reconcile       = $this->drain_pages($abilities['reconcile_metadata'], $reconcile_input, $apply, $deadline);
			if ( is_wp_error($reconcile) ) {
				return $reconcile;
			}
			$result['steps']['reconcile_metadata'] = $this->summarize_step($reconcile);
			$result['summary']['scanned']         += (int) ( $result['steps']['reconcile_metadata']['inspected'] ?? 0 );
			$result['summary']['reconciled']       = (int) ( $reconcile['summary']['written'] ?? 0 );
			$result['summary']['would_reconcile']  = (int) ( $reconcile['summary']['proposed'] ?? 0 );

			if ( $this->stage_incomplete($reconcile) ) {
				$bounded = $this->run_bounded_apply($abilities['bounded_apply'], $result, $apply, $force, $limit, $source, 'reconcile');
				if ( is_wp_error($bounded) ) {
					return $bounded;
				}
				$result['evidence']['budget_exhausted'] = $this->budget_expired($deadline);
				$result['continuation']                 = $this->build_continuation('reconcile', $reconcile, $limit, $passes, $force, $until_budget, $active_no_signal_drain, $scope);
				$result['next_commands'][]              = (string) $result['continuation']['next_command'];
				return $this->finalize_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at, $active_no_signal_drain);
			}
		}

		$effective_passes = $apply ? $passes : 1;
		foreach ( range(1, $effective_passes) as $pass ) {
			$result['executed_passes'] = $pass;
			$pass_marked               = 0;
			foreach ( $this->mark_steps($abilities) as $key => $step_config ) {
				$step_stage = (string) $step_config['stage'];
				if ( $stage_order[ $step_stage ] < $stage_order[ $stage ] ) {
					continue;
				}

				if ( $this->budget_expired($deadline) ) {
					$result['evidence']['budget_exhausted'] = true;
					$result['continuation']                 = $this->build_budget_continuation($step_stage, 0, $limit, $passes, $force, $until_budget, $active_no_signal_drain, 'budget_exhausted_before_stage', $scope);
					$result['next_commands'][]              = (string) $result['continuation']['next_command'];
					break 2;
				}

				$step_input = array_merge(
					$common_page,
					array(
						'dry_run' => ! $apply,
						'offset'  => $step_stage === $stage ? $offset : 0,
					)
				);
				$step       = $this->drain_pages($step_config['ability'], $step_input, $apply, $deadline, true);
				if ( is_wp_error($step) ) {
					return $step;
				}

				$step_key                                      = sprintf('%s_pass_%d', $key, $pass);
				$result['steps'][ $step_key ]                  = $this->summarize_step($step);
				$result['summary']['scanned']                 += (int) ( $result['steps'][ $step_key ]['inspected'] ?? 0 );
				$written                                       = (int) ( $step['summary']['written'] ?? 0 );
				$planned                                       = (int) ( $step['summary']['planned'] ?? 0 );
				$pass_marked                                  += $apply ? $written : $planned;
				$result['summary']['marked_cleanup_eligible'] += $written;
				$result['summary']['would_mark_cleanup_eligible'] += $planned;

				if ( $this->stage_incomplete($step) ) {
					$bounded = $this->run_bounded_apply($abilities['bounded_apply'], $result, $apply, $force, $limit, $source, $step_stage);
					if ( is_wp_error($bounded) ) {
						return $bounded;
					}
					$result['evidence']['budget_exhausted'] = $this->budget_expired($deadline);
					if ( ! empty($step['adaptive_stop']) && is_array($step['adaptive_stop']) ) {
						$result['evidence']['adaptive_stop'] = $step['adaptive_stop'];
						$result['summary']['stop_reason']    = (string) ( $step['adaptive_stop']['reason'] ?? 'no_progress_in_stage' );
					}
					$result['continuation']    = $this->build_continuation($step_stage, $step, $limit, $passes, $force, $until_budget, $active_no_signal_drain, $scope);
					$result['next_commands'][] = (string) $result['continuation']['next_command'];
					break 2;
				}
			}

			$bounded = $this->run_bounded_apply($abilities['bounded_apply'], $result, $apply, $force, $limit, $source, sprintf('pass_%d', $pass));
			if ( is_wp_error($bounded) ) {
				return $bounded;
			}
			if ( $this->budget_expired($deadline) ) {
				$result['evidence']['budget_exhausted'] = true;
				$result['continuation']                 = $this->build_budget_continuation($default_stage, 0, $limit, $passes, $force, $until_budget, $active_no_signal_drain, 'budget_exhausted_after_bounded_apply', $scope);
				$result['next_commands'][]              = (string) $result['continuation']['next_command'];
				break;
			}

			$removed_or_would = (int) ( $bounded['summary']['removed'] ?? 0 ) + (int) ( $bounded['summary']['would_remove'] ?? 0 );
			if ( 0 === $pass_marked && 0 === $removed_or_would ) {
				break;
			}
		}

		if ( ! empty($result['continuation']) ) {
			return $this->finalize_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at, $active_no_signal_drain);
		}

		if ( $apply ) {
			$prune = $this->execute_ability($abilities['prune'], array());
			if ( is_wp_error($prune) ) {
				return $prune;
			}
			$result['steps']['prune'] = $this->summarize_step($prune);
		} else {
			$result['steps']['prune'] = array(
				'mode'    => 'prune',
				'skipped' => true,
				'reason'  => 'preview mode does not prune git worktree metadata; re-run with --apply to prune after removal.',
			);
		}

		return $this->finalize_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at, $active_no_signal_drain);
	}

	/** @return array<string,int> */
	private function stage_order(): array {
		return array(
			'reconcile'        => 0,
			'finalized'        => 1,
			'equivalent-clean' => 2,
			'merged'           => 3,
			'remote-clean'     => 4,
			'bounded'          => 5,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function resolve_required_abilities( bool $active_no_signal_drain = false ): array|\WP_Error {
		$required = array(
			'reconcile_metadata' => 'datamachine-code/workspace-worktree-reconcile-metadata',
			'finalized'          => 'datamachine-code/workspace-worktree-active-no-signal-finalized-apply',
			'equivalent_clean'   => 'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply',
			'merged'             => 'datamachine-code/workspace-worktree-active-no-signal-merged-apply',
			'remote_clean'       => 'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply',
			'bounded_apply'      => 'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply',
			'prune'              => 'datamachine-code/workspace-worktree-prune',
		);
		if ( $active_no_signal_drain ) {
			$required['active_no_signal_report'] = 'datamachine-code/workspace-worktree-active-no-signal-report';
		}

		$abilities = array();
		foreach ( $required as $key => $ability_name ) {
			$ability = ( $this->ability_resolver )($ability_name);
			if ( ! $ability ) {
				return new \WP_Error('worktree_abandoned_ability_missing', sprintf('Worktree abandoned cleanup ability not available: %s', $ability_name), array( 'status' => 500 ));
			}
			$abilities[ $key ] = $ability;
		}
		$this->active_no_signal_report_ability = $abilities['active_no_signal_report'] ?? null;

		return $abilities;
	}

	/** @return array<string,array<string,mixed>> */
	private function mark_steps( array $abilities ): array {
		return array(
			'finalized'        => array(
				'stage'   => 'finalized',
				'ability' => $abilities['finalized'],
			),
			'equivalent_clean' => array(
				'stage'   => 'equivalent-clean',
				'ability' => $abilities['equivalent_clean'],
			),
			'merged'           => array(
				'stage'   => 'merged',
				'ability' => $abilities['merged'],
			),
			'remote_clean'     => array(
				'stage'   => 'remote-clean',
				'ability' => $abilities['remote_clean'],
			),
		);
	}

	private function now(): float {
		return (float) ( $this->clock )();
	}

	private function sanitize_scope( string $scope ): string {
		$scope = trim($scope);
		$scope = preg_replace('/[^a-zA-Z0-9_.@:-]/', '-', $scope);
		$scope = trim( (string) $scope, '-' );

		return substr($scope, 0, 120);
	}

	private function scope_is_worktree_filter( string $scope ): bool {
		return 1 === preg_match('/^[a-zA-Z0-9._-]+(?:@[a-zA-Z0-9._-]+)?$/', $scope);
	}

	/** @return array<string,mixed> */
	private function initial_result( bool $apply, bool $force, int $limit, string $stage, int $offset, int $passes, bool $active_no_signal_drain = false, string $scope = '' ): array {
		return array(
			'success'         => true,
			'mode'            => $active_no_signal_drain ? 'active_no_signal_drain' : 'abandoned_worktree_cleanup',
			'applied'         => $apply,
			'destructive'     => $apply,
			'force'           => $force,
			'limit'           => $limit,
			'stage'           => $stage,
			'offset'          => $offset,
			'passes'          => $passes,
			'scope'           => $scope,
			'executed_passes' => 0,
			'generated_at'    => gmdate('c'),
			'steps'           => array(),
			'blocked'         => array(),
			'summary'         => array(
				'scanned'                     => 0,
				'reconciled'                  => 0,
				'marked_cleanup_eligible'     => 0,
				'would_mark_cleanup_eligible' => 0,
				'removed'                     => 0,
				'would_remove'                => 0,
				'bytes_reclaimed'             => 0,
				'blocked'                     => 0,
				'blocked_by_reason'           => array(),
			),
			'next_commands'   => array(),
			'evidence'        => array(
				'safety' => $active_no_signal_drain
					? ( $apply
						? 'Applies only safe active/no-signal classifiers and bounded cleanup; force and unpushed discard are refused.'
						: 'Preview only. Re-run with --apply to promote safe active/no-signal rows and remove eligible worktrees.' )
					: ( $apply
						? 'Applies only rows proven by existing DMC cleanup abilities; unpushed commits remain protected even with --force.'
						: 'Preview only. Re-run with --apply to write cleanup metadata and remove eligible worktrees.' ),
			),
		);
	}

	private function execute_ability( object $ability, array $input ): array|\WP_Error {
		$execute = array( $ability, 'execute' );
		if ( ! is_callable($execute) ) {
			return new \WP_Error('worktree_abandoned_ability_invalid', 'Worktree abandoned cleanup ability is not executable.', array( 'status' => 500 ));
		}

		$result = $execute($input);
		return is_array($result) || is_wp_error($result) ? $result : new \WP_Error('worktree_abandoned_ability_invalid_result', 'Worktree abandoned cleanup ability returned an invalid result.', array( 'status' => 500 ));
	}

	private function finalize_result( array $result, bool $apply, bool $force, int $limit, int $passes, string $until_budget, float $started_at, bool $active_no_signal_drain = false ): array {
		$result['blocked']            = array_values( (array) ( $result['blocked'] ?? array() ) );
		$result['summary']['blocked'] = count($result['blocked']);
		foreach ( $result['blocked'] as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$reason = (string) ( $row['reason_code'] ?? 'unknown' );
			$result['summary']['blocked_by_reason'][ $reason ] = (int) ( $result['summary']['blocked_by_reason'][ $reason ] ?? 0 ) + 1;
		}

		$operation = $active_no_signal_drain ? 'active-no-signal-drain' : 'abandoned';
		if ( empty($result['continuation']) && ! $apply ) {
			$result['next_commands'][] = sprintf('studio wp datamachine-code workspace worktree %s --apply%s --limit=%d --passes=%d%s --format=json', $operation, $force ? ' --force' : '', $limit, $passes, '' !== $until_budget ? ' --until-budget=' . $until_budget : '');
		}
		if ( empty($result['continuation']) && ! $force && ! $active_no_signal_drain ) {
			$result['next_commands'][] = sprintf('studio wp datamachine-code workspace worktree abandoned --apply --force --limit=%d --passes=%d%s --format=json', $limit, $passes, '' !== $until_budget ? ' --until-budget=' . $until_budget : '');
		}
		if ( $active_no_signal_drain && empty($result['continuation']) && empty($result['evidence']['budget_exhausted']) ) {
			$this->append_active_no_signal_backlog_summary($result, min($limit, 25));
		}

		$result['evidence']['elapsed_ms'] = (int) round(( $this->now() - $started_at ) * 1000);

		return $result;
	}

	private function append_active_no_signal_backlog_summary( array &$result, int $limit ): void {
		if ( null === $this->active_no_signal_report_ability ) {
			return;
		}

		$limit  = max(1, $limit);
		$report = $this->execute_ability(
			$this->active_no_signal_report_ability,
			array(
				'limit'        => $limit,
				'offset'       => 0,
				'until_budget' => '15s',
			)
		);
		if ( is_wp_error($report) ) {
			$result['remaining_active_no_signal_backlog'] = array(
				'available'     => false,
				'reason'        => (string) $report->get_error_code(),
				'message'       => $report->get_error_message(),
				'next_commands' => array(
					sprintf('studio wp datamachine-code workspace worktree active-no-signal-report --limit=%d --offset=0 --format=json', $limit),
				),
			);
			return;
		}

		$result['remaining_active_no_signal_backlog'] = $this->build_active_no_signal_backlog_summary($report, $limit);
		foreach ( (array) ( $result['remaining_active_no_signal_backlog']['next_commands'] ?? array() ) as $command ) {
			$result['next_commands'][] = (string) $command;
		}
		$result['next_commands'] = array_values(array_unique(array_filter(array_map('strval', (array) $result['next_commands']))));
	}

	/** @return array<string,mixed> */
	private function build_active_no_signal_backlog_summary( array $report, int $limit ): array {
		$rows       = (array) ( $report['rows'] ?? array() );
		$summary    = (array) ( $report['summary'] ?? array() );
		$pagination = (array) ( $report['pagination'] ?? array() );
		$total      = (int) ( $summary['total_active_no_signal'] ?? $pagination['total'] ?? 0 );
		$sampled    = (int) ( $summary['inspected'] ?? count($rows) );
		$buckets    = array();

		foreach ( (array) ( $summary['by_suggested_action'] ?? array() ) as $reason => $count ) {
			$buckets[ (string) $reason ] = array(
				'count'    => (int) $count,
				'examples' => array(),
			);
		}

		foreach ( $rows as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$reason               = (string) ( $row['suggested_action'] ?? 'insufficient_signal' );
			$buckets[ $reason ] ??= array(
				'count'    => 0,
				'examples' => array(),
			);
			if ( ! isset($summary['by_suggested_action'][ $reason ]) ) {
				++$buckets[ $reason ]['count'];
			}
			if ( count($buckets[ $reason ]['examples']) < 3 ) {
				$buckets[ $reason ]['examples'][] = $this->active_no_signal_backlog_example($row);
			}
		}
		ksort($buckets);

		$commands = array(
			sprintf('studio wp datamachine-code workspace worktree active-no-signal-report --limit=%d --offset=0 --format=json', $limit),
			sprintf('studio wp datamachine-code workspace worktree active-no-signal-report --limit=%d --offset=0 --verbose --format=json', $limit),
		);
		if ( ! empty($pagination['next_command']) ) {
			$commands[] = (string) $pagination['next_command'];
		}

		return array(
			'available'              => true,
			'total_active_no_signal' => $total,
			'sampled'                => $sampled,
			'unreviewed_count'       => max(0, $total - $sampled),
			'by_actionable_reason'   => $buckets,
			'counts_scope'           => 'bounded_post_drain_sample_only',
			'limitation'             => 'Counts by actionable reason cover only this bounded post-drain sample; active-no-signal report has pagination but no safe bucket filter, so full per-bucket totals are not scanned by default.',
			'pagination'             => $pagination,
			'next_commands'          => array_values(array_unique($commands)),
		);
	}

	/** @return array<string,mixed> */
	private function active_no_signal_backlog_example( array $row ): array {
		$example = array(
			'handle' => (string) ( $row['handle'] ?? '' ),
		);
		foreach ( array( 'repo', 'branch', 'path', 'reason' ) as $field ) {
			if ( isset($row[ $field ]) && '' !== (string) $row[ $field ] ) {
				$example[ $field ] = (string) $row[ $field ];
			}
		}
		if ( isset($row['dirty']) ) {
			$example['dirty'] = (int) $row['dirty'];
		}
		if ( isset($row['unpushed']) ) {
			$example['unpushed'] = (int) $row['unpushed'];
		}
		return $example;
	}

	private function stage_incomplete( array $step ): bool {
		$pagination = (array) ( $step['pagination'] ?? $step['continuation'] ?? array() );
		if ( empty($pagination) || ! empty($pagination['complete']) || ! isset($pagination['next_offset']) ) {
			return false;
		}

		$next_offset = (int) $pagination['next_offset'];
		$current     = (int) ( $pagination['offset'] ?? 0 );
		$total       = isset($pagination['total']) ? (int) $pagination['total'] : null;
		if ( ! empty($pagination['partial']) && $next_offset < $current ) {
			return true;
		}
		if ( $next_offset === $current && ! empty($pagination['partial']) ) {
			return true;
		}
		if ( null !== $total && $next_offset >= $total ) {
			return false;
		}

		return $next_offset > $current;
	}

	private function run_bounded_apply( object $ability, array &$result, bool $apply, bool $force, int $limit, string $source, string $step_label ): array|\WP_Error {
		$bounded = $this->execute_ability(
			$ability,
			array(
				'dry_run' => ! $apply,
				'force'   => $force,
				'limit'   => $limit,
				'source'  => $source,
			)
		);
		if ( is_wp_error($bounded) ) {
			return $bounded;
		}

		$step_key                     = sprintf('bounded_apply_%s', $step_label);
		$result['steps'][ $step_key ] = $this->summarize_step($bounded);

		$result['summary']['removed']         += (int) ( $bounded['summary']['removed'] ?? 0 );
		$result['summary']['would_remove']    += (int) ( $bounded['summary']['would_remove'] ?? 0 );
		$result['summary']['bytes_reclaimed'] += (int) ( $bounded['summary']['bytes_reclaimed'] ?? 0 );
		$result['summary']['scanned']         += (int) ( $result['steps'][ $step_key ]['inspected'] ?? 0 );
		$result['blocked']                     = $this->merge_blockers($result['blocked'], (array) ( $bounded['skipped'] ?? array() ));

		return $bounded;
	}

	private function build_continuation( string $stage, array $step, int $limit, int $passes, bool $force, string $until_budget, bool $active_no_signal_drain = false, string $scope = '' ): array {
		$pagination     = (array) ( $step['pagination'] ?? $step['continuation'] ?? array() );
		$next_offset    = isset($pagination['next_offset']) ? max(0, (int) $pagination['next_offset']) : 0;
		$current        = (int) ( $pagination['offset'] ?? 0 );
		$mutated        = (int) ( $step['summary']['written'] ?? 0 ) + (int) ( $step['summary']['removed'] ?? 0 );
		$restart        = ! empty($pagination['partial']) && $next_offset <= $current && $mutated > 0;
		$command_offset = $restart ? 0 : $next_offset;
		$command        = $this->build_continuation_command($stage, $command_offset, $limit, $passes, $force, $until_budget, $active_no_signal_drain, $scope);
		$adaptive       = (array) ( $step['adaptive_stop'] ?? array() );

		$continuation = array(
			'stage'        => $stage,
			'offset'       => $command_offset,
			'next_command' => $command,
			'pagination'   => $pagination,
		);
		if ( $restart ) {
			$written = (int) ( $step['summary']['written'] ?? 0 );
			$removed = (int) ( $step['summary']['removed'] ?? 0 );

			$continuation['candidate_set_changed_restart_required'] = true;
			$continuation['reason']                                 = 'candidate_set_changed_restart_required';
			$continuation['reason_description']                     = 'The previous cleanup pass changed the candidate set, so the next safe continuation intentionally restarts this stage from offset 0.';
			$continuation['progress_delta']                         = array(
				'written'           => $written,
				'removed'           => $removed,
				'total_mutations'   => $written + $removed,
				'previous_offset'   => $current,
				'next_offset'       => $next_offset,
				'restart_offset'    => 0,
				'candidate_set_now' => 'changed',
			);
			$continuation['next_command_label']                     = 'Restart this stage from offset 0 because the cleanup candidate set changed.';
		}
		if ( ! empty($adaptive) ) {
			$continuation['reason']               = (string) ( $adaptive['reason'] ?? 'no_progress_in_stage' );
			$continuation['reason_description']   = (string) ( $adaptive['reason_description'] ?? 'The previous stage page scanned rows but produced no cleanup mutations, so the drain stopped before spending more budget on low-yield pages.' );
			$continuation['recommendation']       = (string) ( $adaptive['recommendation'] ?? 'Stop this drain for now, or run next_command to continue this stage from the next page if you want a deeper scan.' );
			$continuation['priority_hint']        = (string) ( $adaptive['priority_hint'] ?? 'This page produced no mutations. Prefer bounded cleanup-eligible apply or an active/no-signal report before walking more low-yield pages.' );
			$continuation['alternative_commands'] = (array) ( $adaptive['alternative_commands'] ?? array() );
			$continuation['progress_delta']       = (array) ( $adaptive['progress_delta'] ?? array() );
			$continuation['next_command_label']   = 'Continue this stage despite the no-progress adaptive stop.';
		}

		return $continuation;
	}

	private function build_budget_continuation( string $stage, int $offset, int $limit, int $passes, bool $force, string $until_budget, bool $active_no_signal_drain, string $reason, string $scope = '' ): array {
		return array(
			'stage'            => $stage,
			'offset'           => max(0, $offset),
			'next_command'     => $this->build_continuation_command($stage, max(0, $offset), $limit, $passes, $force, $until_budget, $active_no_signal_drain, $scope),
			'reason'           => $reason,
			'budget_exhausted' => true,
			'hint'             => 'Budget expired after safe progress. Re-run next_command to continue the drain from the next safe boundary.',
		);
	}

	private function build_continuation_command( string $stage, int $offset, int $limit, int $passes, bool $force, string $until_budget, bool $active_no_signal_drain, string $scope = '' ): string {
		$operation = $active_no_signal_drain ? 'active-no-signal-drain' : 'abandoned';
		return sprintf('studio wp datamachine-code workspace worktree %s --apply%s --stage=%s --offset=%d --limit=%d --passes=%d%s%s --format=json', $operation, $force ? ' --force' : '', $stage, $offset, $limit, $passes, '' !== $until_budget ? ' --until-budget=' . $until_budget : '', '' !== $scope ? ' --scope=' . $scope : '');
	}

	private function drain_pages( object $ability, array $base_input, bool $apply, ?float $deadline = null, bool $stop_on_no_progress = false ): array|\WP_Error {
		$pages       = array();
		$summary     = array();
		$pagination  = array();
		$offset      = isset($base_input['offset']) ? max(0, (int) $base_input['offset']) : 0;
		$max_pages   = $apply ? 100 : 1;
		$last_result = array();

		for ( $page = 1; $page <= $max_pages; ++$page ) {
			if ( null !== $deadline && $this->budget_expired($deadline) ) {
				break;
			}

			$input = $base_input;
			if ( isset($base_input['offset']) || $page > 1 ) {
				$input['offset'] = $offset;
			}
			$this->apply_remaining_budget($input, $deadline);

			$result = $this->execute_ability($ability, $input);
			if ( is_wp_error($result) ) {
				return $result;
			}

			$last_result = $result;
			$pages[]     = $this->summarize_step($result);

			foreach ( (array) ( $result['summary'] ?? array() ) as $key => $value ) {
				if ( is_numeric($value) ) {
					$summary[ $key ] = (int) ( $summary[ $key ] ?? 0 ) + (int) $value;
				}
			}

			$pagination = (array) ( $result['pagination'] ?? $result['continuation'] ?? array() );
			if ( ! $apply || empty($pagination) || ! empty($pagination['complete']) ) {
				break;
			}

			$next_offset    = isset($pagination['next_offset']) ? (int) $pagination['next_offset'] : null;
			$mutation_count = (int) ( $result['summary']['written'] ?? 0 ) + (int) ( $result['summary']['removed'] ?? 0 );
			$inspected      = (int) ( $result['summary']['inspected'] ?? $result['summary']['processed'] ?? 0 );
			if ( null === $next_offset || ( $next_offset <= $offset && ( $mutation_count <= 0 || empty($pagination['partial']) ) ) ) {
				break;
			}

			$total = isset($pagination['total']) ? (int) $pagination['total'] : null;
			if ( null !== $total && $next_offset >= $total ) {
				break;
			}

			if ( $stop_on_no_progress && $mutation_count <= 0 && $inspected > 0 ) {
				$scope_suffix                 = isset($base_input['scope']) && '' !== (string) $base_input['scope'] ? ' --scope=' . (string) $base_input['scope'] : '';
				$last_result['adaptive_stop'] = array(
					'reason'               => 'no_progress_in_stage',
					'reason_description'   => 'This stage scanned a page and produced no cleanup metadata writes or removals, so the drain stopped before spending more budget on low-yield pages.',
					'recommendation'       => 'Stop this drain for now. If freeing disk is urgent, review bounded cleanup-eligible rows or sample the active/no-signal backlog before walking more pages in this low-yield stage.',
					'priority_hint'        => 'Prioritize already cleanup-eligible rows and actionable active/no-signal evidence before continuing a stage page that produced zero mutations.',
					'alternative_commands' => array(
						sprintf('studio wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=%d%s --format=json', (int) ( $base_input['limit'] ?? 25 ), $scope_suffix),
						sprintf('studio wp datamachine-code workspace worktree active-no-signal-report --limit=%d --offset=0%s --format=json', min(25, (int) ( $base_input['limit'] ?? 25 )), $scope_suffix),
					),
					'progress_delta'       => array(
						'inspected'       => $inspected,
						'written'         => (int) ( $result['summary']['written'] ?? 0 ),
						'removed'         => (int) ( $result['summary']['removed'] ?? 0 ),
						'total_mutations' => $mutation_count,
						'previous_offset' => $offset,
						'next_offset'     => $next_offset,
					),
				);
				break;
			}

			$offset = $next_offset;
		}

		if ( array() === $last_result ) {
			$last_result = array(
				'success'          => true,
				'mode'             => 'abandoned_budget_exhausted',
				'dry_run'          => ! empty($base_input['dry_run']),
				'applied'          => $apply,
				'budget_exhausted' => true,
			);
		}

		$last_result['summary']    = $summary;
		$last_result['pagination'] = $pagination;
		$last_result['pages']      = $pages;
		$last_result['page_count'] = count($pages);

		return $last_result;
	}

	private function parse_budget( string $duration ): int|\WP_Error {
		if ( ! preg_match('/^(\d+)([smh])$/', trim($duration), $matches) ) {
			return new \WP_Error('invalid_worktree_abandoned_budget', 'Invalid until_budget duration. Use a compact value like 60s, 10m, or 1h.', array( 'status' => 400 ));
		}

		$value = (int) $matches[1];
		if ( $value < 1 ) {
			return new \WP_Error('invalid_worktree_abandoned_budget', 'Invalid until_budget duration. Duration must be greater than zero.', array( 'status' => 400 ));
		}

		return match ( $matches[2] ) {
			'h' => $value * HOUR_IN_SECONDS,
			'm' => $value * MINUTE_IN_SECONDS,
			default => $value,
		};
	}

	private function apply_remaining_budget( array &$input, ?float $deadline ): void {
		if ( null === $deadline ) {
			return;
		}

		$remaining             = max(1, (int) floor($deadline - $this->now()));
		$input['until_budget'] = $remaining . 's';
	}

	private function budget_expired( ?float $deadline ): bool {
		return null !== $deadline && $this->now() >= $deadline;
	}

	private function summarize_step( array $step ): array {
		$summary = (array) ( $step['summary'] ?? array() );
		return array(
			'mode'            => (string) ( $step['mode'] ?? '' ),
			'page_count'      => (int) ( $step['page_count'] ?? 1 ),
			'dry_run'         => ! empty($step['dry_run']),
			'applied'         => ! empty($step['applied']) || ! empty($step['destructive']),
			'inspected'       => (int) ( $summary['inspected'] ?? $summary['processed'] ?? 0 ),
			'planned'         => (int) ( $summary['planned'] ?? $summary['would_remove'] ?? $summary['proposed'] ?? 0 ),
			'written'         => (int) ( $summary['written'] ?? 0 ),
			'removed'         => (int) ( $summary['removed'] ?? 0 ),
			'skipped'         => (int) ( $summary['skipped'] ?? 0 ),
			'bytes_reclaimed' => (int) ( $summary['bytes_reclaimed'] ?? 0 ),
			'pagination'      => (array) ( $step['pagination'] ?? $step['continuation'] ?? array() ),
		);
	}

	private function merge_blockers( array $existing, array $incoming ): array {
		$merged = array();
		foreach ( $existing as $row ) {
			$handle            = (string) ( $row['handle'] ?? count($merged) );
			$merged[ $handle ] = $row;
		}

		foreach ( $incoming as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' === $handle ) {
				$handle = 'row_' . count($merged);
			}
			$merged[ $handle ] = array(
				'handle'      => $handle,
				'repo'        => (string) ( $row['repo'] ?? '' ),
				'branch'      => (string) ( $row['branch'] ?? '' ),
				'path'        => (string) ( $row['path'] ?? '' ),
				'reason_code' => (string) ( $row['reason_code'] ?? 'unknown' ),
				'reason'      => (string) ( $row['reason'] ?? '' ),
				'dirty'       => isset($row['dirty']) ? (int) $row['dirty'] : null,
				'unpushed'    => isset($row['unpushed']) ? (int) $row['unpushed'] : null,
			);
		}

		return $merged;
	}
}
