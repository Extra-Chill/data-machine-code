<?php
/**
 * WP-CLI Workspace Command
 *
 * Provides CLI access to the agent workspace — a managed directory
 * for cloning repos and working with files outside the web root.
 *
 * Commands delegate to the same services as WordPress Abilities API primitives.
 * The CLI layer handles argument parsing, confirmation prompts, and output
 * formatting only.
 *
 * @package DataMachineCode\Cli\Commands
 * @since   0.1.0
 */

namespace DataMachineCode\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachineCode\Cleanup\CleanupRunEvidenceStoreInterface;
use DataMachineCode\Cleanup\DataMachineJobCleanupRunEvidenceStore;
use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorktreeContextInjector;
use DataMachineCode\Workspace\WorkspaceMutationLock;

defined('ABSPATH') || exit;

class WorkspaceCommand extends BaseCommand {



	private const CLEANUP_CLI_SOURCE = 'workspace_cleanup_cli';

	private const CLEANUP_MODES = array( 'inventory', 'artifacts', 'retention', 'stale-worktrees', 'emergency' );

	private const METADATA_RECONCILE_DEFAULT_LIMIT = 25;

	private const METADATA_RECONCILE_DEFAULT_BUDGET = '30s';

	private ?CleanupRunEvidenceStoreInterface $cleanup_run_evidence_store = null;

	/**
	 * Show the workspace directory path.
	 *
	 * Displays the resolved workspace path. The path is determined by:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable — VPS)
	 * 3. $HOME/.datamachine-code/workspace (local/macOS)
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : Optional primary or worktree handle, such as <repo> or <repo>@<branch-slug>.
	 *
	 * [--ensure]
	 * : Create the directory if it doesn't exist.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show workspace path
	 *     wp datamachine-code workspace path
	 *
	 *     # Show path for a workspace repo or worktree
	 *     wp datamachine-code workspace path my-plugin@fix-foo
	 *
	 *     # Show path and create if missing
	 *     wp datamachine-code workspace path --ensure
	 *
	 * @subcommand path
	 */
	public function path( array $args, array $assoc_args ): void {
		$ability = wp_get_ability('datamachine-code/workspace-path');
		if ( ! $ability ) {
			WP_CLI::error('Workspace path ability not available.');
			return;
		}

		$input = array(
			'ensure' => ! empty($assoc_args['ensure']),
		);
		if ( ! empty($args[0]) ) {
			$input['name'] = (string) $args[0];
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		if ( ! empty($result['created']) ) {
			WP_CLI::success(sprintf('Created workspace: %s', $result['path']));
			return;
		}

		WP_CLI::log($result['path']);

		if ( empty($result['exists']) && empty($assoc_args['ensure']) ) {
			WP_CLI::warning('Directory does not exist yet. Use --ensure to create it.');
		}
	}

	/**
	 * List repositories in the workspace.
	 *
	 * ## OPTIONS
	 *
	 * [--repo=<repo>]
	 * : Filter by primary repository name. Includes the primary checkout and its worktrees.
	 *
	 * [--type=<type>]
	 * : Filter by checkout type.
	 * ---
	 * options:
	 *   - primary
	 *   - worktree
	 * ---
	 *
	 * [--summary]
	 * : Show compact workspace triage counts instead of one row per checkout.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List workspace repos
	 *     wp datamachine-code workspace list
	 *
	 *     # List as JSON
	 *     wp datamachine-code workspace list --format=json
	 *
	 *     # List one primary checkout and its worktrees
	 *     wp datamachine-code workspace list --repo=my-plugin
	 *
	 *     # List only worktrees for one primary checkout
	 *     wp datamachine-code workspace list --repo=my-plugin --type=worktree --format=json
	 *
	 * @subcommand list
	 */
	public function list_repos( array $args, array $assoc_args ): void {
		$ability = wp_get_ability('datamachine-code/workspace-list');
		if ( ! $ability ) {
			WP_CLI::error('Workspace list ability not available.');
			return;
		}

		$input = array();
		if ( isset($assoc_args['repo']) ) {
			$input['repo'] = (string) $assoc_args['repo'];
		}
		if ( isset($assoc_args['type']) ) {
			$input['type'] = (string) $assoc_args['type'];
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		if ( empty($result['repos']) ) {
			if ( isset($assoc_args['repo']) ) {
				WP_CLI::log(sprintf('No repos matching "%s" in workspace (%s).', (string) $assoc_args['repo'], $result['path'] ?? ''));
				return;
			}

			WP_CLI::log(sprintf('No repos in workspace (%s).', $result['path'] ?? ''));
			WP_CLI::log('Clone one with: wp datamachine-code workspace clone <url>');
			return;
		}

		if ( ! empty($assoc_args['summary']) ) {
			$this->render_workspace_list_summary($result, $assoc_args);
			return;
		}

		$items = array_map(
			function ( $repo ) {
				$freshness = is_array($repo['primary_freshness'] ?? null) ? $repo['primary_freshness'] : null;
				return array(
					'name'      => $repo['name'],
					'kind'      => ! empty($repo['is_worktree']) ? 'worktree' : 'primary',
					'repo'      => $repo['repo'] ?? $repo['name'],
					'branch'    => $repo['branch'] ?? '-',
					'freshness' => is_array($freshness) ? (string) ( $freshness['status'] ?? '-' ) : '-',
					'behind'    => is_array($freshness) && null !== ( $freshness['behind'] ?? null ) ? (string) $freshness['behind'] : '-',
					'remote'    => $repo['remote'] ?? '-',
					'git'       => $repo['git'] ? 'yes' : 'no',
					'path'      => $repo['path'],
				);
			},
			$result['repos']
		);

		$this->format_items(
			$items,
			array( 'name', 'kind', 'repo', 'branch', 'freshness', 'behind', 'remote', 'git' ),
			$assoc_args,
			'name'
		);
	}

	/**
	 * Triage external, noncanonical, and non-git workspace rows without cleanup.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Operation to run.
	 * ---
	 * options:
	 *   - list
	 *   - ignore
	 *   - quarantine
	 *   - adopt
	 * ---
	 *
	 * [<row-id>]
	 * : Row id from `workspace triage list` for ignore/quarantine/adopt.
	 *
	 * [--reason=<reason>]
	 * : Required reason for ignore/quarantine metadata.
	 *
	 * [--name=<name>]
	 * : Optional canonical primary name when adopting a safe row.
	 *
	 * [--status=<status>]
	 * : Filter list by triage status.
	 *
	 * [--include-resolved]
	 * : Include ignored, quarantined, and adopted rows in list output.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace triage list
	 *     wp datamachine-code workspace triage list --include-resolved --format=json
	 *     wp datamachine-code workspace triage quarantine external:abc123 --reason='Created by raw git worktree outside DMC ownership'
	 *     wp datamachine-code workspace triage ignore tmp --reason='Operator scratch directory, not DMC-owned'
	 *     wp datamachine-code workspace triage adopt my-plugin
	 *
	 * @subcommand triage
	 */
	public function triage( array $args, array $assoc_args ): void {
		$operation = (string) ( $args[0] ?? 'list' );
		$workspace = new Workspace();

		switch ( $operation ) {
			case 'list':
				$result = $workspace->workspace_row_triage_list(
					array(
						'status'           => isset($assoc_args['status']) ? (string) $assoc_args['status'] : '',
						'include_resolved' => ! empty($assoc_args['include-resolved']),
					)
				);
				$this->render_workspace_triage_result($result, $assoc_args, true);
				return;

			case 'ignore':
			case 'quarantine':
				if ( empty($args[1]) ) {
					WP_CLI::error('Usage: wp datamachine-code workspace triage ' . $operation . ' <row-id> --reason=<reason>');
					return;
				}
				$result = $workspace->workspace_row_triage_mark( (string) $args[1], 'ignore' === $operation ? 'ignored' : 'quarantined', (string) ( $assoc_args['reason'] ?? '' ) );
				$this->render_workspace_triage_result($result, $assoc_args, false);
				return;

			case 'adopt':
				if ( empty($args[1]) ) {
					WP_CLI::error('Usage: wp datamachine-code workspace triage adopt <row-id> [--name=<name>]');
					return;
				}
				$result = $workspace->workspace_row_triage_adopt( (string) $args[1], isset($assoc_args['name']) ? (string) $assoc_args['name'] : null );
				$this->render_workspace_triage_result($result, $assoc_args, false);
				return;

			default:
				WP_CLI::error(sprintf('Unknown triage operation: %s', $operation));
		}
	}

	/**
	 * Render workspace row triage output.
	 *
	 * @param array<string,mixed>|\WP_Error $result     Result.
	 * @param array<string,mixed>           $assoc_args CLI args.
	 * @param bool                          $is_list    Whether result is a list.
	 */
	private function render_workspace_triage_result( array|\WP_Error $result, array $assoc_args, bool $is_list ): void {
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}

		if ( ! $is_list ) {
			WP_CLI::success( (string) ( $result['message'] ?? 'Workspace triage updated.' ) );
			$row = is_array($result['row'] ?? null) ? (array) $result['row'] : array();
			if ( array() !== $row ) {
				$this->format_items(array( $this->workspace_triage_table_row($row) ), array( 'row_id', 'status', 'issues', 'age_days', 'reason', 'path' ), $assoc_args, 'row_id');
			}
			return;
		}

		WP_CLI::log(sprintf('Workspace: %s', (string) ( $result['workspace_path'] ?? '' )));
		$rows = array_map(fn( array $row ): array => $this->workspace_triage_table_row($row), (array) ( $result['rows'] ?? array() ));
		if ( array() === $rows ) {
			WP_CLI::log('No unresolved external, noncanonical, or non-git workspace rows.');
			return;
		}

		$this->format_items($rows, array( 'row_id', 'status', 'issues', 'age_days', 'repo', 'path' ), $assoc_args, 'row_id');
		WP_CLI::log('Next: use `workspace triage ignore|quarantine <row-id> --reason=<reason>` or `workspace triage adopt <row-id>` for safe primary rows.');
	}

	/**
	 * Build a compact table row for workspace triage output.
	 *
	 * @param  array<string,mixed> $row Triage row.
	 * @return array<string,mixed>
	 */
	private function workspace_triage_table_row( array $row ): array {
		return array(
			'row_id'   => (string) ( $row['row_id'] ?? '' ),
			'status'   => (string) ( $row['triage_status'] ?? '' ),
			'issues'   => implode(',', array_map('strval', (array) ( $row['issues'] ?? array() ))),
			'age_days' => null === ( $row['age_days'] ?? null ) ? '-' : (string) $row['age_days'],
			'repo'     => (string) ( $row['repo'] ?? '' ),
			'reason'   => (string) ( $row['triage_reason'] ?? '' ),
			'path'     => (string) ( $row['path'] ?? '' ),
		);
	}

	/**
	 * Render compact workspace list counts for cleanup triage.
	 *
	 * @param  array<string,mixed> $result     Workspace list ability result.
	 * @param  array<string,mixed> $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_workspace_list_summary( array $result, array $assoc_args ): void {
		$repos   = (array) ( $result['repos'] ?? array() );
		$summary = array(
			'total'     => count($repos),
			'primary'   => 0,
			'worktree'  => 0,
			'context'   => 0,
			'non_git'   => 0,
			'repos'     => array(),
			'workspace' => (string) ( $result['path'] ?? '' ),
		);

		foreach ( $repos as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$kind = ! empty($row['is_context']) ? 'context' : ( ! empty($row['is_worktree']) ? 'worktree' : 'primary' );
			++$summary[ $kind ];
			if ( empty($row['git']) ) {
				++$summary['non_git'];
			}
			$repo = (string) ( $row['repo'] ?? $row['name'] ?? 'unknown' );
			if ( ! isset($summary['repos'][ $repo ]) ) {
				$summary['repos'][ $repo ] = array(
					'repo'     => $repo,
					'primary'  => 0,
					'worktree' => 0,
					'context'  => 0,
					'total'    => 0,
				);
			}
			++$summary['repos'][ $repo ][ $kind ];
			++$summary['repos'][ $repo ]['total'];
		}

		ksort($summary['repos']);
		$summary['repos'] = array_values($summary['repos']);
		if ( $summary['non_git'] > 0 ) {
			$summary['triage_command'] = 'wp datamachine-code workspace triage list --format=json';
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}

		WP_CLI::log(sprintf('Workspace: %s', $summary['workspace']));
		$this->format_items(
			array(
				array(
					'metric' => 'total',
					'count'  => $summary['total'],
				),
				array(
					'metric' => 'primary',
					'count'  => $summary['primary'],
				),
				array(
					'metric' => 'worktree',
					'count'  => $summary['worktree'],
				),
				array(
					'metric' => 'context',
					'count'  => $summary['context'],
				),
				array(
					'metric' => 'non_git',
					'count'  => $summary['non_git'],
				),
			),
			array( 'metric', 'count' ),
			array( 'format' => 'table' ),
			'metric'
		);

		if ( array() !== $summary['repos'] ) {
			WP_CLI::log('Repos:');
			$this->format_items($summary['repos'], array( 'repo', 'primary', 'worktree', 'context', 'total' ), array( 'format' => 'table' ), 'repo');
		}

		if ( ! empty($summary['triage_command']) ) {
			WP_CLI::log(sprintf('Triage: %s', $summary['triage_command']));
		}
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Git repository URL to clone.
	 *
	 * [--name=<name>]
	 * : Directory name in workspace (derived from URL if omitted).
	 *
	 * [--full]
	 * : Disable the default blobless partial clone (`--filter=blob:none`). Useful for servers that do not support partial clone or when all blobs are needed immediately.
	 *
	 * [--allow-duplicate-remote]
	 * : Explicitly allow cloning a second top-level primary for a remote already present in the workspace. Use only for deliberate release/proof checkouts.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clone a repo
	 *     wp datamachine-code workspace clone https://github.com/example/my-plugin.git
	 *
	 *     # Clone with custom name
	 *     wp datamachine-code workspace clone https://github.com/example/my-plugin.git --name=my-plugin-dev
	 *
	 * @subcommand clone
	 */
	public function clone_repo( array $args, array $assoc_args ): void {
		if ( empty($args[0]) ) {
			WP_CLI::error('Repository URL is required.');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-clone');
		if ( ! $ability ) {
			WP_CLI::error('Workspace clone ability not available.');
			return;
		}

		$result = $ability->execute(
			array(
				'url'                    => $args[0],
				'name'                   => $assoc_args['name'] ?? null,
				'full'                   => isset($assoc_args['full']),
				'allow_duplicate_remote' => isset($assoc_args['allow-duplicate-remote']),
			)
		);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		WP_CLI::success( (string) ( $result['message'] ?? 'Repository cloned.' ));
		WP_CLI::log(sprintf('Path: %s', (string) ( $result['path'] ?? '' )));
	}

	/**
	 * Adopt an existing primary checkout already under the workspace root.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Existing git primary checkout path to adopt.
	 *
	 * [--name=<name>]
	 * : Workspace name (derived from path basename if omitted).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace adopt /Users/chubes/Developer/my-plugin --name=my-plugin
	 *
	 * @subcommand adopt
	 */
	public function adopt_repo( array $args, array $assoc_args ): void {
		if ( empty($args[0]) ) {
			WP_CLI::error('Checkout path is required.');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-adopt');
		if ( ! $ability ) {
			WP_CLI::error('Workspace adopt ability not available.');
			return;
		}

		$input = array( 'path' => $args[0] );
		if ( ! empty($assoc_args['name']) ) {
			$input['name'] = $assoc_args['name'];
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		WP_CLI::success($result['message']);
		WP_CLI::log(sprintf('Name: %s', $result['name']));
		WP_CLI::log(sprintf('Path: %s', $result['path']));
	}

	/**
	 * Control task-backed workspace cleanup runs.
	 *
	 * This is the high-level operator surface for cleanup. Destructive runs are
	 * scheduled through Data Machine's system-task scheduler and return immediately
	 * with a run_id. Status, resume, cancel, and evidence commands take that run_id.
	 * Dry-runs stay synchronous and delegate to the same workspace abilities as the
	 * lower-level `workspace worktree cleanup*` commands until DB-backed cleanup
	 * plans land.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Cleanup operation. One of: <plan|apply|until-empty|run|status|resume|cancel|evidence>.
	 *   Existing task-backed controls remain: <run|status|resume|cancel|evidence>.
	 *
	 * [<run-id>]
	 * : Run identifier returned by `plan` or `run`. DB-backed plan IDs look like
	 *   cleanup-run-<timestamp>-<nonce>; job-backed run IDs may be cleanup-run-<job_id>.
	 *
	 * [--mode=<mode>]
	 * : Cleanup mode for `run`.
	 * ---
	 * default: retention
	 * options:
	 *   - inventory
	 *   - metadata
	 *   - artifacts
	 *   - retention
	 *   - stale-worktrees
	 *   - emergency
	 * ---
	 *
	 * [--dry-run]
	 * : Run the selected cleanup review synchronously through workspace abilities.
	 *
	 * [--force]
	 * : Pass force=true into the cleanup task params for modes that support it.
	 *
	 * [--include-artifacts]
	 * : For `plan --mode=retention`, include artifact cleanup rows. Retention
	 *   planning includes a full-workspace artifact inventory by default; this flag
	 *   remains accepted for explicitness and `--mode=artifacts` still creates an
	 *   artifact-only plan. `--mode=stale-worktrees` never includes artifacts unless
	 *   this flag is passed.
	 *
	 * [--older-than=<duration>]
	 * : Pass an age gate such as 7d or 24h into cleanup task params.
	 *
	 * [--top=<count>]
	 * : For `plan`, number of largest reclaimable paths to show in the upfront
	 *   summary. Defaults to 10.
	 *
	 * [--limit=<count>]
	 * : For DB-backed `apply` / `resume`, maximum pending rows to process in this
	 *   invocation (default 25, max 100). For `--mode=artifacts` pages, maximum
	 *   worktrees to scan; dry-run reviews scan this bounded page synchronously,
	 *   and apply runs freeze eligible candidates from the same bounded page.
	 *   Artifact page scans default to 100. Use 0 to disable the artifact scan cap
	 *   (combine with --exhaustive for a full audit).
	 *
	 * [--offset=<count>]
	 * : Pagination offset (0-indexed) for `--mode=artifacts` dry-run and apply
	 *   pages. Walk huge workspaces by feeding the previous response's
	 *   `pagination.next_offset` until `pagination.complete` is true.
	 *
	 * [--exhaustive]
	 * : For `--mode=artifacts --dry-run`, scan every worktree AND run per-worktree
	 *   git status / unpushed-commit safety probes. Slow on huge workspaces; use
	 *   sparingly for full audits.
	 *
	 * [--safety-probes]
	 * : For `--mode=artifacts --dry-run`, run the per-worktree git safety probes
	 *   without disabling the bounded scan. Useful when you want a small slice
	 *   audited with full safety information.
	 *
	 * [--verbose]
	 * : Include full diagnostic child job ID lists in task-backed cleanup status output.
	 *
	 * [--summary]
	 * : For `status` and `evidence`, print compact operator-focused cleanup counts,
	 *   blockers, examples, and next commands instead of full nested evidence.
	 *
	 * [--drain]
	 * : For `cleanup run`, drain the queued parent job, drain active child cleanup
	 *   jobs discovered from cleanup status, then print verified bytes reclaimed.
	 *
	 * [--max-passes=<count>]
	 * : For `cleanup until-empty --mode=artifacts`, maximum plan/apply passes before
	 *   stopping. Defaults to 10, max 25.
	 *
	 * [--budget-seconds=<seconds>]
	 * : For `cleanup until-empty --mode=artifacts`, stop before starting another
	 *   pass after this many seconds.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a DB-backed cleanup plan for review
	 *     wp datamachine-code workspace cleanup plan --mode=retention
	 *
	 *     # Apply a reviewed DB-backed run
	 *     wp datamachine-code workspace cleanup apply cleanup-run-20260504120000-abc123
	 *
	 *     # Start task-backed retention cleanup and capture the returned run_id
	 *     wp datamachine-code workspace cleanup run --mode=retention
	 *
	 *     # Review and then apply destructive stale worktree removal only
	 *     wp datamachine-code workspace cleanup plan --mode=stale-worktrees --older-than=14d --format=json
	 *     wp datamachine-code workspace cleanup run --mode=stale-worktrees --older-than=14d
	 *
	 *     # Review artifact cleanup synchronously (bounded; default limit=100)
	 *     wp datamachine-code workspace cleanup run --mode=artifacts --dry-run
	 *
	 *     # Persist a snapshot-safe artifact cleanup plan, then apply it by run ID
	 *     wp datamachine-code workspace cleanup run --mode=artifacts --dry-run --format=json
	 *     wp datamachine-code workspace cleanup apply cleanup-run-20260504120000-abc123
	 *
	 *     # Repeatedly apply reviewed safe artifact rows until none remain
	 *     wp datamachine-code workspace cleanup until-empty --mode=artifacts --format=json
	 *
	 *     # Full audit (slow on huge workspaces)
	 *     wp datamachine-code workspace cleanup run --mode=artifacts --dry-run --exhaustive --format=json
	 *
	 *     # Inspect progress for a returned run
	 *     wp datamachine-code workspace cleanup status cleanup-run-123
	 *
	 *     # Retry a failed/incomplete run through Data Machine jobs
	 *     wp datamachine-code workspace cleanup resume cleanup-run-123
	 *
	 *     # Cancel a processing run safely by failing the parent job
	 *     wp datamachine-code workspace cleanup cancel cleanup-run-123
	 *
	 *     # Print recorded evidence / engine data
	 *     wp datamachine-code workspace cleanup evidence cleanup-run-123 --format=json
	 *
	 *     # Print compact evidence summary for chat/operator follow-up
	 *     wp datamachine-code workspace cleanup evidence cleanup-run-123 --summary
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$operation = (string) ( $args[0] ?? '' );
		if ( '' === $operation ) {
			WP_CLI::error('Usage: wp datamachine-code workspace cleanup <plan|apply|run|status|resume|cancel|evidence> [<run-id>] [--mode=<mode>]');
			return;
		}

		switch ( $operation ) {
			case 'plan':
				$this->run_cleanup_plan($assoc_args);
				return;

			case 'apply':
				$this->run_cleanup_control_ability('apply', (string) ( $args[1] ?? '' ), $assoc_args);
				return;

			case 'until-empty':
				$this->run_cleanup_until_empty($assoc_args);
				return;

			case 'run':
				$this->run_cleanup_task($assoc_args);
				return;

			case 'status':
			case 'evidence':
				if ( ! $this->is_job_cleanup_run_id( (string) ( $args[1] ?? '' )) ) {
					$this->run_cleanup_control_ability($operation, (string) ( $args[1] ?? '' ), $assoc_args);
					return;
				}
				$job_id = $this->cleanup_run_job_id( (string) ( $args[1] ?? '' ));
				if ( $job_id <= 0 ) {
					WP_CLI::error('Usage: wp datamachine-code workspace cleanup ' . $operation . ' <run-id>');
					return;
				}
				$this->render_cleanup_run_status($job_id, $assoc_args, 'evidence' === $operation);
				return;

			case 'resume':
			case 'cancel':
				if ( ! $this->is_job_cleanup_run_id( (string) ( $args[1] ?? '' )) ) {
					$this->run_cleanup_control_ability($operation, (string) ( $args[1] ?? '' ), $assoc_args);
					return;
				}
				$job_id = $this->cleanup_run_job_id( (string) ( $args[1] ?? '' ));
				if ( $job_id <= 0 ) {
					WP_CLI::error('Usage: wp datamachine-code workspace cleanup ' . $operation . ' <run-id>');
					return;
				}
				$this->control_cleanup_run_job($operation, $job_id, $assoc_args);
				return;

			default:
				WP_CLI::error(sprintf('Unknown cleanup operation: %s', $operation));
				return;
		}
	}

	private function run_cleanup_task( array $assoc_args ): void {
		if ( isset($assoc_args['dry-run']) ) {
			$this->run_cleanup_review($assoc_args);
			return;
		}

		$mode = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) ( $assoc_args['mode'] ?? 'retention' )));
		if ( ! in_array($mode, self::CLEANUP_MODES, true) ) {
			WP_CLI::error(sprintf('Unknown cleanup mode: %s. Expected one of: %s.', $mode, implode(', ', self::CLEANUP_MODES)));
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-cleanup-run');
		if ( ! $ability ) {
			WP_CLI::error('Workspace cleanup run ability not registered.');
			return;
		}

		$result = $ability->execute($this->cleanup_run_input($mode, $assoc_args));

		if ( ! ( $result['success'] ?? false ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Failed to schedule cleanup run.' ));
			return;
		}

		$result = $this->attach_cleanup_run_commands($result, $mode);
		if ( ! empty($assoc_args['drain']) ) {
			if ( 'json' === (string) ( $assoc_args['format'] ?? 'table' ) ) {
				$this->render_cleanup_control_result($result + array( 'drain_state' => 'scheduled' ), $assoc_args);
				$this->flush_cli_output();
			}
			$result = $this->drain_cleanup_run_to_status($result, $assoc_args);
		}

		$this->render_cleanup_control_result($result, $assoc_args);
	}

	private function run_cleanup_until_empty( array $assoc_args ): void {
		$mode = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) ( $assoc_args['mode'] ?? 'artifacts' )));
		if ( 'artifacts' !== $mode ) {
			WP_CLI::error('cleanup until-empty currently supports --mode=artifacts only.');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-cleanup-until-empty');
		if ( ! $ability ) {
			WP_CLI::error('Workspace cleanup until-empty ability not registered.');
			return;
		}

		$input = array( 'mode' => $mode );
		foreach ( array( 'force', 'limit' ) as $key ) {
			if ( isset($assoc_args[ $key ]) ) {
				$input[ $key ] = 'force' === $key ? (bool) $assoc_args[ $key ] : (int) $assoc_args[ $key ];
			}
		}
		foreach (
			array(
				'max-passes'     => 'max_passes',
				'budget-seconds' => 'budget_seconds',
			) as $cli_key => $input_key
		) {
			if ( isset($assoc_args[ $cli_key ]) ) {
				$input[ $input_key ] = (int) $assoc_args[ $cli_key ];
			}
		}

		$result = $ability->execute($input);
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$this->render_cleanup_control_result($result, $assoc_args);
	}

	/**
	 * Flush stdout so JSON drain callers see the scheduled run before draining blocks.
	 *
	 * @return void
	 */
	private function flush_cli_output(): void {
		if ( defined('STDOUT') ) {
			fflush(STDOUT);
		}
		flush();
	}

	/**
	 * Attach operator commands to a queued cleanup run response.
	 *
	 * @param  array<string,mixed> $result Cleanup run result.
	 * @param  string              $mode   Cleanup mode.
	 * @return array<string,mixed>
	 */
	private function attach_cleanup_run_commands( array $result, string $mode ): array {
		$job_id = (int) ( $result['job_id'] ?? 0 );
		$run_id = (string) ( $result['run_id'] ?? ( $job_id > 0 ? $this->cleanup_run_id($job_id) : '' ) );
		if ( $job_id <= 0 || '' === $run_id ) {
			return $result;
		}

		$result['commands'] = array(
			'drain_parent'       => sprintf('studio wp datamachine drain --job-id=%d', $job_id),
			'status'             => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
			'status_verbose'     => sprintf('studio wp datamachine-code workspace cleanup status %s --verbose --format=json', $run_id),
			'one_command_drain'  => sprintf('studio wp datamachine-code workspace cleanup run --mode=%s --drain --format=json', $mode),
			'bytes_verification' => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
		);

		return $result;
	}

	/**
	 * Drain a queued job-backed cleanup run through Data Machine, then return status evidence.
	 *
	 * @param  array<string,mixed> $result     Initial cleanup run result.
	 * @param  array<string,mixed> $assoc_args CLI associative args.
	 * @return array<string,mixed>
	 */
	private function drain_cleanup_run_to_status( array $result, array $assoc_args ): array {
		$job_id = (int) ( $result['job_id'] ?? 0 );
		$run_id = (string) ( $result['run_id'] ?? ( $job_id > 0 ? $this->cleanup_run_id($job_id) : '' ) );
		if ( $job_id <= 0 || '' === $run_id ) {
			$result['drain'] = array(
				'success' => false,
				'error'   => 'Cleanup run did not return a job id to drain.',
			);
			return $result;
		}

		$commands   = array();
		$errors     = array();
		$max_passes = 10;

		$parent_command = sprintf('datamachine drain --job-id=%d', $job_id);
		$commands[]     = 'studio wp ' . $parent_command;
		$error          = $this->run_wp_cli_command($parent_command);
		if ( '' !== $error ) {
			$errors[] = $error;
		}

		for ( $pass = 0; $pass < $max_passes; ++$pass ) {
			$status = $this->cleanup_run_evidence_store()->read($run_id, true, true);
			if ( $status instanceof \WP_Error ) {
				$errors[] = $status->get_error_message();
				break;
			}

			$children         = (array) ( $status['evidence']['children'] ?? array() );
			$active_child_ids = array_values(
				array_unique(
					array_filter(
						array_map(
							'intval',
							array_merge(
								(array) ( $children['pending_job_ids'] ?? array() ),
								(array) ( $children['processing_job_ids'] ?? array() )
							)
						)
					)
				)
			);
			if ( array() === $active_child_ids ) {
				break;
			}

			$child_command = sprintf('datamachine drain --job-id=%s', implode(',', $active_child_ids));
			$commands[]    = 'studio wp ' . $child_command;
			$error         = $this->run_wp_cli_command($child_command);
			if ( '' !== $error ) {
				$errors[] = $error;
				break;
			}
		}

		$final                 = $this->cleanup_run_evidence_store()->read($run_id, false, ! empty($assoc_args['verbose']));
		$output                = $final instanceof \WP_Error ? $result : $final;
		$output['initial_run'] = $result;
		$output['drain']       = array(
			'success'          => array() === $errors,
			'commands'         => $commands,
			'errors'           => $errors,
			'verify_command'   => sprintf('studio wp datamachine-code workspace cleanup status %s --format=json', $run_id),
			'bytes_reclaimed'  => (int) ( $output['cleanup_items']['bytes_reclaimed'] ?? 0 ),
			'freed_human'      => (string) ( $output['cleanup_items']['freed_human'] ?? $this->format_bytes(0) ),
			'completion_state' => (string) ( $output['state'] ?? 'unknown' ),
		);

		return $output;
	}

	/**
	 * Run a WP-CLI command and return an error message on failure.
	 *
	 * @param  string $command Command without the leading `wp` binary.
	 * @return string Empty string on success.
	 */
	private function run_wp_cli_command( string $command ): string {
		try {
			WP_CLI::runcommand(
				$command,
				array(
					'return'     => true,
					'exit_error' => false,
				)
			);
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}

		return '';
	}

	private function cleanup_run_input( string $mode, array $assoc_args ): array {
		$input = array(
			'mode'   => $mode,
			'source' => self::CLEANUP_CLI_SOURCE,
		);
		if ( isset($assoc_args['force']) ) {
			$input['force'] = (bool) $assoc_args['force'];
		}
		if ( isset($assoc_args['older-than']) && '' !== trim( (string) $assoc_args['older-than']) ) {
			$input['older_than'] = trim( (string) $assoc_args['older-than']);
		}
		if ( 'stale-worktrees' === $mode ) {
			$input['worktree_stale_only'] = true;
			if ( ! isset($input['older_than']) ) {
				$input['older_than'] = '14d';
			}
		}
		if ( 'artifacts' === $mode ) {
			if ( isset($assoc_args['limit']) ) {
				$input['limit'] = (int) $assoc_args['limit'];
			}
			if ( isset($assoc_args['offset']) ) {
				$input['offset'] = (int) $assoc_args['offset'];
			}
			if ( ! empty($assoc_args['exhaustive']) ) {
				$input['exhaustive'] = true;
			}
		}

		return $input;
	}

	private function run_cleanup_plan( array $assoc_args ): void {
		$ability = wp_get_ability('datamachine-code/workspace-cleanup-plan');
		if ( ! $ability ) {
			WP_CLI::error('Workspace cleanup plan ability not registered.');
			return;
		}

		$mode = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) ( $assoc_args['mode'] ?? 'retention' )));
		if ( ! in_array($mode, self::CLEANUP_MODES, true) ) {
			WP_CLI::error(sprintf('Unknown cleanup mode: %s. Expected one of: %s.', $mode, implode(', ', self::CLEANUP_MODES)));
			return;
		}

		$input = $this->cleanup_plan_input($mode, $assoc_args);
		if ( 'json' !== (string) ( $assoc_args['format'] ?? 'table' ) ) {
			$profile = ! empty($input['include_artifacts']) ? 'full-workspace inventory, biggest wins first' : 'local worktree merge signals';
			WP_CLI::log(sprintf('Planning cleanup (%s; %s)...', $mode, $profile));
		}

		$result = $ability->execute($input);
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$this->render_cleanup_plan_result($result, $assoc_args);
	}

	/**
	 * Normalize cleanup plan input shared by `cleanup plan` and dry-run `cleanup run`.
	 *
	 * @param  string $mode       Cleanup mode.
	 * @param  array  $assoc_args CLI associative args.
	 * @return array<string,mixed>
	 */
	private function cleanup_plan_input( string $mode, array $assoc_args ): array {
		$include_artifacts = 'retention' === $mode || 'artifacts' === $mode || ! empty($assoc_args['include-artifacts']);
		$include_worktrees = 'artifacts' !== $mode;
		$input             = array(
			'mode'              => $mode,
			'include_artifacts' => $include_artifacts,
			'include_worktrees' => $include_worktrees,
			'include_resolvers' => true,
		);
		if ( isset($assoc_args['older-than']) && '' !== trim( (string) $assoc_args['older-than']) ) {
			$input['worktree_older_than'] = trim( (string) $assoc_args['older-than']);
		}
		if ( isset($assoc_args['top']) ) {
			$input['top_n'] = (int) $assoc_args['top'];
		}
		if ( isset($assoc_args['force']) ) {
			$input['force_artifact_cleanup'] = (bool) $assoc_args['force'];
		}
		if ( isset($assoc_args['sort']) && '' !== trim( (string) $assoc_args['sort']) ) {
			$sort                   = trim( (string) $assoc_args['sort']);
			$input['artifact_sort'] = $sort;
			$input['worktree_sort'] = $sort;
		}
		if ( 'stale-worktrees' === $mode ) {
			$input['worktree_stale_only'] = true;
			if ( empty($input['worktree_older_than']) ) {
				$input['worktree_older_than'] = '14d';
			}
		}

		return $input;
	}

	private function run_cleanup_control_ability( string $operation, string $run_id, array $assoc_args ): void {
		$run_id = trim($run_id);
		if ( '' === $run_id ) {
			WP_CLI::error('Usage: wp datamachine-code workspace cleanup ' . $operation . ' <run-id>');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-cleanup-' . $operation);
		if ( ! $ability ) {
			WP_CLI::error(sprintf('Workspace cleanup %s ability not registered.', $operation));
			return;
		}

		$result = $ability->execute(
			array(
				'run_id' => $run_id,
				'force'  => ! empty($assoc_args['force']),
			) + ( isset($assoc_args['limit']) ? array( 'limit' => (int) $assoc_args['limit'] ) : array() )
		);
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$this->render_cleanup_control_result($result, $assoc_args);
	}

	private function run_cleanup_review( array $assoc_args ): void {
		$mode = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) ( $assoc_args['mode'] ?? 'retention' )));
		if ( ! in_array($mode, self::CLEANUP_MODES, true) ) {
			WP_CLI::error(sprintf('Unknown cleanup mode: %s. Expected one of: %s.', $mode, implode(', ', self::CLEANUP_MODES)));
			return;
		}

		switch ( $mode ) {
			case 'inventory':
				$ability = wp_get_ability('datamachine-code/workspace-hygiene-report');
				$result  = $ability ? $ability->execute(
				array(
					'include_cleanup'         => true,
					'include_sizes'           => true,
					'include_worktree_status' => false,
					'size_limit'              => 200,
				)
				) : new \WP_Error('workspace_hygiene_ability_missing', 'Workspace hygiene ability not registered.');
				$this->render_workspace_hygiene_report_from_ability($result, $assoc_args);
				return;

			case 'artifacts':
				$ability = wp_get_ability('datamachine-code/workspace-cleanup-plan');
				$result  = $ability ? $ability->execute($this->cleanup_plan_input($mode, $assoc_args)) : new \WP_Error('cleanup_plan_ability_missing', 'Workspace cleanup plan ability not registered.');
				if ( is_wp_error($result) ) {
					WP_CLI::error($result->get_error_message());
					return;
				}
				$this->render_cleanup_plan_result($result, $assoc_args);
				return;

			case 'emergency':
				$ability = wp_get_ability('datamachine-code/workspace-worktree-emergency-cleanup');
				$result  = $ability ? $ability->execute(
				array(
					'dry_run' => true,
					'force'   => ! empty($assoc_args['force']),
				)
				) : new \WP_Error('emergency_cleanup_ability_missing', 'Emergency cleanup ability not registered.');
				$this->render_worktree_emergency_cleanup_result_from_ability($result, $assoc_args);
				return;

			case 'stale-worktrees':
				$ability = wp_get_ability('datamachine-code/workspace-worktree-cleanup');
				$input   = array(
					'dry_run'             => true,
					'force'               => ! empty($assoc_args['force']),
					'skip_github'         => true,
					'stale_liveness_only' => true,
					'older_than'          => isset($assoc_args['older-than']) && '' !== trim( (string) $assoc_args['older-than']) ? trim( (string) $assoc_args['older-than']) : '14d',
				);
				$result  = $ability ? $ability->execute($input) : new \WP_Error('worktree_cleanup_ability_missing', 'Worktree cleanup ability not registered.');
				$this->render_worktree_cleanup_result_from_ability($result, $assoc_args);
				return;

			case 'retention':
			default:
				$ability = wp_get_ability('datamachine-code/workspace-worktree-cleanup');
				$input   = array(
					'dry_run'     => true,
					'force'       => ! empty($assoc_args['force']),
					'skip_github' => true,
				);
				if ( isset($assoc_args['older-than']) && '' !== trim( (string) $assoc_args['older-than']) ) {
					$input['older_than'] = trim( (string) $assoc_args['older-than']);
				}
				$result = $ability ? $ability->execute($input) : new \WP_Error('worktree_cleanup_ability_missing', 'Worktree cleanup ability not registered.');
				$this->render_worktree_cleanup_result_from_ability($result, $assoc_args);
				return;
		}
	}

	private function render_workspace_hygiene_report_from_ability( array|\WP_Error $result, array $assoc_args ): void {
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}
		$this->render_workspace_hygiene_report($result, $assoc_args);
	}

	private function render_worktree_cleanup_result_from_ability( array|\WP_Error $result, array $assoc_args ): void {
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}
		$this->render_worktree_cleanup_result($result, $assoc_args);
	}

	private function render_worktree_emergency_cleanup_result_from_ability( array|\WP_Error $result, array $assoc_args ): void {
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}
		$this->render_worktree_emergency_cleanup_result($result, $assoc_args);
	}

	private function render_cleanup_run_status( int $job_id, array $assoc_args, bool $evidence ): void {
		$output = $this->cleanup_run_evidence_store()->read($this->cleanup_run_id($job_id), $evidence, ! empty($assoc_args['verbose']));
		if ( $output instanceof \WP_Error ) {
			WP_CLI::error($output->get_error_message());
			return;
		}

		$this->render_cleanup_control_result($output, $assoc_args);
	}

	private function cleanup_run_evidence_store(): CleanupRunEvidenceStoreInterface {
		if ( null === $this->cleanup_run_evidence_store ) {
			$this->cleanup_run_evidence_store = new DataMachineJobCleanupRunEvidenceStore();
		}

		return $this->cleanup_run_evidence_store;
	}

	private function control_cleanup_run_job( string $operation, int $job_id, array $assoc_args ): void {
		$ability_name = 'resume' === $operation ? 'datamachine-code/retry-job' : 'datamachine-code/fail-job';
		$ability      = wp_get_ability($ability_name);
		if ( ! $ability ) {
			WP_CLI::error(sprintf('Job control ability not registered: %s', $ability_name));
			return;
		}

		$target_job_ids = $this->cleanup_run_control_job_ids($operation, $job_id);
		$results        = array();
		foreach ( $target_job_ids as $target_job_id ) {
			$input = array( 'job_id' => $target_job_id );
			if ( 'resume' === $operation ) {
				$input['force'] = ! empty($assoc_args['force']);
			} else {
				$input['reason'] = 'cleanup_cancelled';
			}

			$result = $ability->execute($input);
			if ( ! ( $result['success'] ?? false ) ) {
				WP_CLI::error( (string) ( $result['error'] ?? 'Cleanup run control failed.' ));
				return;
			}
			$results[] = $result;
		}

		$output                       = $results[0] ?? array(
			'success' => true,
			'job_id'  => $job_id,
		);
		$output['run_id']             = $this->cleanup_run_id($job_id);
		$output['state']              = 'resume' === $operation ? 'running' : 'cancelled';
		$output['controlled_job_ids'] = $target_job_ids;
		$output['results']            = $results;
		$this->render_cleanup_control_result($output, $assoc_args);
	}

	/**
	 * Resolve which Data Machine jobs should be controlled for a job-backed cleanup run.
	 *
	 * @param  string $operation Cleanup control operation.
	 * @param  int    $job_id    Cleanup parent job ID.
	 * @return array<int,int>
	 */
	private function cleanup_run_control_job_ids( string $operation, int $job_id ): array {
		$output = $this->cleanup_run_evidence_store()->read($this->cleanup_run_id($job_id), true, true);
		if ( $output instanceof \WP_Error ) {
			return array( $job_id );
		}

		$children       = (array) ( $output['evidence']['children'] ?? array() );
		$processing_ids = array_map('intval', (array) ( $children['processing_job_ids'] ?? array() ));
		$failed_ids     = array_map('intval', (array) ( $children['failed_job_ids'] ?? array() ));
		$pending_ids    = array_map('intval', (array) ( $children['pending_job_ids'] ?? array() ));

		if ( 'resume' === $operation ) {
			$child_targets = array_values(array_unique(array_filter(array_merge($processing_ids, $failed_ids))));
			return array() !== $child_targets ? $child_targets : array( $job_id );
		}

		return array_values(array_unique(array_filter(array_merge(array( $job_id ), $pending_ids, $processing_ids))));
	}

	private function render_cleanup_control_result( array $result, array $assoc_args ): void {
		$result = $this->attach_current_workspace_lock_status($result);
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( ! empty($assoc_args['summary']) ) {
			$result = $this->build_cleanup_operator_summary($result);
		}
		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}
		if ( 'yaml' === $format && class_exists('Spyc') ) {
			WP_CLI::log( (string) \Spyc::YAMLDump($result, false, false, true));
			return;
		}

		WP_CLI::log(sprintf('State: %s', $result['state'] ?? 'unknown'));
		foreach ( array( 'run_id', 'job_id', 'mode', 'task_type', 'status' ) as $key ) {
			if ( isset($result[ $key ]) && '' !== (string) $result[ $key ] ) {
				WP_CLI::log(sprintf('%s: %s', ucfirst(str_replace('_', ' ', $key)), (string) $result[ $key ]));
			}
		}
		if ( ! empty($assoc_args['summary']) ) {
			$this->render_cleanup_operator_summary($result);
			return;
		}
		if ( ! empty($result['progress']) && is_array($result['progress']) ) {
			$this->render_cleanup_progress_summary( (array) $result['progress']);
		}
		if ( ! empty($result['commands']) && is_array($result['commands']) ) {
			$this->render_cleanup_command_hints( (array) $result['commands']);
		}
		if ( ! empty($result['drain']) && is_array($result['drain']) ) {
			$this->render_cleanup_drain_summary( (array) $result['drain']);
		}
		if ( ! empty($result['remaining_work_summary']) && is_array($result['remaining_work_summary']) ) {
			$this->render_cleanup_remaining_work_summary( (array) $result['remaining_work_summary']);
		}
		if ( ! empty($result['locks']['stale_locks']) && is_array($result['locks']['stale_locks']) ) {
			$this->render_stale_lock_followup( (array) $result['locks']['stale_locks']);
		}
		if ( ! empty($result['evidence']) ) {
			WP_CLI::log( (string) wp_json_encode($result['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}
	}

	/**
	 * Render compact cleanup status/evidence summary tables.
	 *
	 * @param  array<string,mixed> $summary Compact cleanup summary.
	 * @return void
	 */
	private function render_cleanup_operator_summary( array $summary ): void {
		WP_CLI::log('');
		WP_CLI::log('Cleanup operator summary:');
		$cleanup_counts = (array) ( $summary['cleanup_counts'] ?? array() );
		$artifacts      = (array) ( $summary['artifact_cleanup'] ?? array() );
		$this->format_items(
			array(
				array(
					'metric' => 'planned_rows',
					'value'  => (int) ( $cleanup_counts['planned'] ?? 0 ),
				),
				array(
					'metric' => 'applied_rows',
					'value'  => (int) ( $cleanup_counts['applied'] ?? 0 ),
				),
				array(
					'metric' => 'skipped_rows',
					'value'  => (int) ( $cleanup_counts['skipped'] ?? 0 ),
				),
				array(
					'metric' => 'failed_rows',
					'value'  => (int) ( $cleanup_counts['failed'] ?? 0 ),
				),
				array(
					'metric' => 'bytes_reclaimed',
					'value'  => $this->format_bytes($cleanup_counts['bytes_reclaimed'] ?? 0),
				),
				array(
					'metric' => 'remaining_reclaimable_artifacts',
					'value'  => $this->format_bytes($artifacts['remaining_reclaimable_artifact_bytes'] ?? 0),
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);

		$this->render_cleanup_summary_reason_rows('Skipped rows by reason:', (array) ( $summary['skipped_by_reason'] ?? array() ));
		$this->render_cleanup_summary_reason_rows('Failed rows by reason:', (array) ( $summary['failed_by_reason'] ?? array() ));

		$examples = (array) ( $summary['top_blocked_examples'] ?? array() );
		if ( array() !== $examples ) {
			WP_CLI::log('');
			WP_CLI::log('Top blocked examples:');
			$rows = array_map(
				fn( $row ) => array(
					'size'          => $this->format_bytes(is_array($row) ? ( $row['size_bytes'] ?? 0 ) : 0),
					'reason'        => is_array($row) ? (string) ( $row['reason'] ?? '' ) : '',
					'handle'        => is_array($row) ? (string) ( $row['handle'] ?? '' ) : '',
					'artifact_path' => is_array($row) ? (string) ( $row['artifact_path'] ?? '' ) : '',
					'path'          => is_array($row) ? (string) ( $row['path'] ?? '' ) : '',
				),
				array_slice($examples, 0, 10)
			);
			$this->format_items($rows, array( 'size', 'reason', 'handle', 'artifact_path', 'path' ), array( 'format' => 'table' ), 'size');
		}

		$commands = (array) ( $summary['recommended_commands'] ?? array() );
		if ( array() !== $commands ) {
			WP_CLI::log('');
			WP_CLI::log('Recommended next commands:');
			$rows = array_map(
				fn( $row ) => array(
					'bucket'            => is_array($row) ? (string) ( $row['bucket'] ?? '' ) : '',
					'review_command'    => is_array($row) ? (string) ( $row['command'] ?? '' ) : '',
					'apply_command'     => is_array($row) ? (string) ( $row['apply'] ?? '' ) : '',
					'apply_destructive' => is_array($row) && ! empty($row['apply_destructive']) ? 'yes' : 'no',
				),
				array_slice($commands, 0, 10)
			);
			$this->format_items($rows, array( 'bucket', 'review_command', 'apply_command', 'apply_destructive' ), array( 'format' => 'table' ), 'bucket');
		}
	}

	/**
	 * Build compact cleanup status/evidence output for chat/operator workflows.
	 *
	 * @param  array<string,mixed> $result Cleanup status/evidence result.
	 * @return array<string,mixed>
	 */
	private function build_cleanup_operator_summary( array $result ): array {
		$cleanup_items = (array) ( $result['cleanup_items'] ?? $result['evidence']['cleanup_items'] ?? array() );
		$artifacts     = (array) ( $result['artifact_cleanup'] ?? $result['evidence']['artifact_cleanup'] ?? array() );
		$remaining     = (array) ( $result['remaining_work_summary'] ?? array() );

		return array_filter(
			array(
				'success'              => (bool) ( $result['success'] ?? false ),
				'run_id'               => (string) ( $result['run_id'] ?? '' ),
				'job_id'               => isset($result['job_id']) ? (int) $result['job_id'] : null,
				'mode'                 => (string) ( $result['mode'] ?? $result['evidence']['engine_data']['cleanup_run']['mode'] ?? '' ),
				'state'                => (string) ( $result['state'] ?? '' ),
				'status'               => (string) ( $result['status'] ?? '' ),
				'parent_status'        => (string) ( $result['parent_status'] ?? '' ),
				'created_at'           => (string) ( $result['created_at'] ?? '' ),
				'completed_at'         => (string) ( $result['completed_at'] ?? $result['parent_completed_at'] ?? '' ),
				'cleanup_counts'       => array(
					'planned'         => (int) ( $cleanup_items['planned_rows'] ?? 0 ),
					'applied'         => (int) ( $cleanup_items['applied_rows'] ?? 0 ),
					'skipped'         => (int) ( $cleanup_items['skipped_rows'] ?? 0 ),
					'failed'          => (int) ( $cleanup_items['failed_rows'] ?? 0 ),
					'bytes_reclaimed' => (int) ( $cleanup_items['bytes_reclaimed'] ?? 0 ),
					'freed_human'     => (string) ( $cleanup_items['freed_human'] ?? $this->format_bytes($cleanup_items['bytes_reclaimed'] ?? 0) ),
				),
				'artifact_cleanup'     => array(
					'planned'                              => (int) ( $artifacts['planned_rows'] ?? 0 ),
					'applied'                              => (int) ( $artifacts['applied_rows'] ?? 0 ),
					'skipped'                              => (int) ( $artifacts['skipped_rows'] ?? 0 ),
					'failed'                               => (int) ( $artifacts['failed_rows'] ?? 0 ),
					'bytes_reclaimed'                      => (int) ( $artifacts['bytes_reclaimed'] ?? 0 ),
					'remaining_reclaimable_artifact_bytes' => (int) ( $remaining['remaining_reclaimable_artifact_bytes'] ?? $artifacts['remaining_reclaimable_artifact_bytes'] ?? 0 ),
					'remaining_reclaimable_human'          => $this->format_bytes($remaining['remaining_reclaimable_artifact_bytes'] ?? $artifacts['remaining_reclaimable_artifact_bytes'] ?? 0),
				),
				'children'             => $this->build_cleanup_operator_child_summary( (array) ( $result['children'] ?? $result['evidence']['children'] ?? array() ) ),
				'by_type'              => (array) ( $cleanup_items['by_type'] ?? array() ),
				'skipped_by_reason'    => (array) ( $remaining['skipped_by_reason'] ?? $cleanup_items['skipped_examples_by_reason'] ?? array() ),
				'failed_by_reason'     => (array) ( $cleanup_items['failed_by_reason'] ?? $artifacts['failed_by_reason'] ?? array() ),
				'top_blocked_examples' => $this->cleanup_operator_blocked_examples($result),
				'recommended_commands' => (array) ( $remaining['recommended_commands'] ?? array() ),
				'locks'                => (array) ( $result['locks'] ?? array() ),
			),
			fn( $value ) => null !== $value && array() !== $value && '' !== $value
		);
	}

	/**
	 * Summarize child cleanup jobs without unbounded ID lists.
	 *
	 * @param  array<string,mixed> $children Child job aggregate.
	 * @return array<string,mixed>
	 */
	private function build_cleanup_operator_child_summary( array $children ): array {
		return array(
			'total'      => (int) ( $children['total'] ?? 0 ),
			'running'    => (int) ( $children['running'] ?? 0 ),
			'completed'  => (int) ( $children['completed'] ?? 0 ),
			'failed'     => (int) ( $children['failed'] ?? 0 ),
			'skipped'    => (int) ( $children['skipped'] ?? 0 ),
			'statuses'   => (array) ( $children['statuses'] ?? array() ),
			'batch_jobs' => isset($children['batch_total']) ? (int) $children['batch_total'] : count( (array) ( $children['batch_job_ids'] ?? array() ) ),
			'chunk_jobs' => isset($children['chunk_total']) ? (int) $children['chunk_total'] : count( (array) ( $children['chunk_job_ids'] ?? array() ) ),
		);
	}

	/**
	 * Extract largest blocked cleanup examples from compact summaries and full evidence when available.
	 *
	 * @param  array<string,mixed> $result Cleanup status/evidence result.
	 * @return array<int,array<string,mixed>>
	 */
	private function cleanup_operator_blocked_examples( array $result ): array {
		$examples = array();
		foreach ( (array) ( $result['remaining_work_summary']['skipped_by_reason'] ?? array() ) as $reason => $bucket ) {
			foreach ( (array) ( is_array($bucket) ? ( $bucket['examples'] ?? array() ) : array() ) as $row ) {
				if ( is_array($row) ) {
					$examples[] = $this->cleanup_operator_example_row($row, (string) $reason);
				}
			}
		}

		foreach ( (array) ( $result['evidence']['child_jobs'] ?? array() ) as $job ) {
			$engine_data = (array) ( is_array($job) ? ( $job['engine_data'] ?? array() ) : array() );
			foreach ( array( 'skipped', 'failed' ) as $bucket ) {
				foreach ( (array) ( $engine_data[ $bucket ] ?? array() ) as $row ) {
					if ( is_array($row) ) {
						$examples[] = $this->cleanup_operator_example_row($row, (string) ( $row['reason_code'] ?? $bucket ));
					}
				}
			}
		}

		usort($examples, fn( $a, $b ) => (int) ( $b['size_bytes'] ?? 0 ) <=> (int) ( $a['size_bytes'] ?? 0 ));
		$seen    = array();
		$deduped = array_values(array_filter(
			$examples,
			function ( array $row ) use ( &$seen ): bool {
				$key = (string) ( $row['handle'] ?? '' ) . '|' . (string) ( $row['reason'] ?? '' ) . '|' . (string) ( $row['path'] ?? '' );
				if ( isset($seen[ $key ]) ) {
					return false;
				}
				$seen[ $key ] = true;
				return true;
			}
		));
		return array_slice($deduped, 0, 10);
	}

	/**
	 * Normalize one blocked cleanup example row for compact output.
	 *
	 * @param  array<string,mixed> $row    Cleanup row.
	 * @param  string              $reason Fallback reason code.
	 * @return array<string,mixed>
	 */
	private function cleanup_operator_example_row( array $row, string $reason ): array {
		$artifact_path = (string) ( $row['artifact_path'] ?? '' );
		$artifacts     = (array) ( $row['artifacts'] ?? array() );
		if ( '' === $artifact_path && isset($artifacts[0]) && is_array($artifacts[0]) ) {
			$artifact_path = (string) ( $artifacts[0]['path'] ?? '' );
		}

		return array_filter(
			array(
				'handle'        => (string) ( $row['handle'] ?? '' ),
				'reason'        => (string) ( $row['reason_code'] ?? $row['reason'] ?? $reason ),
				'path'          => (string) ( $row['path'] ?? '' ),
				'artifact_path' => $artifact_path,
				'size_bytes'    => $this->cleanup_operator_row_bytes($row),
				'size'          => $this->format_bytes($this->cleanup_operator_row_bytes($row)),
			),
			fn( $value ) => '' !== $value && 0 !== $value
		);
	}

	/**
	 * Return best-known reclaimable bytes for one cleanup row.
	 *
	 * @param  array<string,mixed> $row Cleanup row.
	 * @return int
	 */
	private function cleanup_operator_row_bytes( array $row ): int {
		foreach ( array( 'artifact_size_bytes', 'size_bytes', 'bytes_reclaimed' ) as $field ) {
			if ( isset($row[ $field ]) ) {
				return max(0, (int) $row[ $field ]);
			}
		}
		$total = 0;
		foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
			$total += max(0, (int) ( is_array($artifact) ? ( $artifact['size_bytes'] ?? 0 ) : 0 ));
		}
		return $total;
	}

	/**
	 * Attach live workspace lock status to cleanup triage surfaces when available.
	 *
	 * @param  array<string,mixed> $result Cleanup result.
	 * @return array<string,mixed>
	 */
	private function attach_current_workspace_lock_status( array $result ): array {
		if ( ! isset($result['locks']) && class_exists(Workspace::class) && class_exists(WorkspaceMutationLock::class) ) {
			try {
				$workspace       = new Workspace();
				$result['locks'] = WorkspaceMutationLock::status($workspace->get_path());
			} catch ( \Throwable $e ) {
				$result['locks'] = array(
					'error' => $e->getMessage(),
				);
			}
		}

		return $this->attach_stale_lock_recommendation($result);
	}

	/**
	 * Promote safe stale-lock remediation above the detailed lock evidence.
	 *
	 * @param  array<string,mixed> $result Cleanup result.
	 * @return array<string,mixed>
	 */
	private function attach_stale_lock_recommendation( array $result ): array {
		$report = $result['locks']['stale_locks'] ?? null;
		if ( ! is_array($report) || (int) ( $report['count'] ?? 0 ) <= 0 ) {
			return $result;
		}

		$protected = 0;
		foreach ( array( 'database', 'filesystem' ) as $source ) {
			foreach ( (array) ( $report[ $source ] ?? array() ) as $row ) {
				if ( is_array($row) && empty($row['safe_to_prune']) ) {
					++$protected;
				}
			}
		}

		$result['stale_lock_summary'] = array(
			'stale_database_locks'   => (int) ( $report['database_count'] ?? 0 ),
			'stale_filesystem_locks' => (int) ( $report['filesystem_count'] ?? 0 ),
			'active_protected_locks' => $protected,
			'preview_command'        => (string) ( $report['preview_command'] ?? 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json' ),
			'prune_command'          => (string) ( $report['apply_command'] ?? 'wp datamachine-code workspace worktree locks --prune-stale --format=json' ),
		);

		if ( 0 === $protected ) {
			$result['recommended_next_step'] = $result['stale_lock_summary']['prune_command'];
		}

		return $result;
	}

	/**
	 * Render compact cleanup remaining-work summary.
	 *
	 * @param  array<string,mixed> $summary Remaining-work summary.
	 * @return void
	 */
	private function render_cleanup_remaining_work_summary( array $summary ): void {
		WP_CLI::log('');
		WP_CLI::log('Remaining work summary:');
		$this->format_items(
			array(
				array(
					'metric' => 'remaining_reclaimable_artifact_bytes',
					'value'  => $this->format_bytes($summary['remaining_reclaimable_artifact_bytes'] ?? 0),
				),
				array(
					'metric' => 'remaining_safely_removable_worktrees',
					'value'  => (int) ( $summary['remaining_safely_removable_worktrees'] ?? 0 ),
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);

		$this->render_cleanup_summary_type_rows('Applied rows by type:', (array) ( $summary['applied_by_type'] ?? array() ));
		$this->render_cleanup_summary_reason_rows('Skipped rows by reason:', (array) ( $summary['skipped_by_reason'] ?? array() ));
		$this->render_cleanup_summary_reason_rows('Blocked resolver rows by reason:', (array) ( $summary['blocked_resolvers_by_reason'] ?? array() ));

		$commands = (array) ( $summary['recommended_commands'] ?? array() );
		if ( array() !== $commands ) {
			WP_CLI::log('');
			WP_CLI::log('Recommended next commands:');
			$rows = array_map(
				fn( $row ) => array(
					'bucket'            => is_array($row) ? (string) ( $row['bucket'] ?? '' ) : '',
					'review_command'    => is_array($row) ? (string) ( $row['command'] ?? '' ) : '',
					'apply_command'     => is_array($row) ? (string) ( $row['apply'] ?? '' ) : '',
					'apply_destructive' => is_array($row) && ! empty($row['apply_destructive']) ? 'yes' : 'no',
				),
				array_slice($commands, 0, 10)
			);
			$this->format_items($rows, array( 'bucket', 'review_command', 'apply_command', 'apply_destructive' ), array( 'format' => 'table' ), 'bucket');
		}
	}

	/**
	 * Render cleanup command hints.
	 *
	 * @param  array<string,string> $commands Commands keyed by purpose.
	 * @return void
	 */
	private function render_cleanup_command_hints( array $commands ): void {
		WP_CLI::log('');
		WP_CLI::log('Cleanup commands:');
		$rows = array();
		foreach ( $commands as $purpose => $command ) {
			$rows[] = array(
				'purpose' => (string) $purpose,
				'command' => (string) $command,
			);
		}
		$this->format_items($rows, array( 'purpose', 'command' ), array( 'format' => 'table' ), 'purpose');
	}

	/**
	 * Render cleanup drain summary.
	 *
	 * @param  array<string,mixed> $drain Drain summary.
	 * @return void
	 */
	private function render_cleanup_drain_summary( array $drain ): void {
		WP_CLI::log('');
		WP_CLI::log('Drain summary:');
		$this->format_items(
			array(
				array(
					'metric' => 'success',
					'value'  => ! empty($drain['success']) ? 'yes' : 'no',
				),
				array(
					'metric' => 'completion_state',
					'value'  => (string) ( $drain['completion_state'] ?? 'unknown' ),
				),
				array(
					'metric' => 'bytes_reclaimed',
					'value'  => $this->format_bytes($drain['bytes_reclaimed'] ?? 0),
				),
				array(
					'metric' => 'verify_command',
					'value'  => (string) ( $drain['verify_command'] ?? '' ),
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);
	}

	private function render_cleanup_progress_summary( array $progress ): void {
		WP_CLI::log('');
		WP_CLI::log('Progress:');
		$this->format_items(
			array(
				array(
					'metric' => 'applying_rows',
					'value'  => (int) ( $progress['applying_rows'] ?? 0 ),
				),
				array(
					'metric' => 'pending_or_failed',
					'value'  => (int) ( $progress['pending_or_failed'] ?? 0 ),
				),
				array(
					'metric' => 'resumable',
					'value'  => ! empty($progress['resumable']) ? 'yes' : 'no',
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);
		if ( ! empty($progress['note']) ) {
			WP_CLI::log( (string) $progress['note']);
		}
	}

	private function render_cleanup_summary_type_rows( string $label, array $types ): void {
		if ( array() === $types ) {
			return;
		}
		WP_CLI::log('');
		WP_CLI::log($label);
		$rows = array();
		foreach ( $types as $type => $bucket ) {
			$bucket = (array) $bucket;
			$rows[] = array(
				'type'            => (string) $type,
				'count'           => (int) ( $bucket['count'] ?? 0 ),
				'bytes_reclaimed' => $this->format_bytes($bucket['bytes_reclaimed'] ?? 0),
			);
		}
		$this->format_items($rows, array( 'type', 'count', 'bytes_reclaimed' ), array( 'format' => 'table' ), 'type');
	}

	private function render_cleanup_summary_reason_rows( string $label, array $reasons ): void {
		if ( array() === $reasons ) {
			return;
		}
		WP_CLI::log('');
		WP_CLI::log($label);
		$rows = array();
		foreach ( $reasons as $reason => $bucket ) {
			$bucket   = (array) $bucket;
			$examples = array_map(fn( $row ) => is_array($row) ? (string) ( $row['handle'] ?? '' ) : (string) $row, (array) ( $bucket['examples'] ?? array() ));
			$rows[]   = array(
				'reason'   => (string) $reason,
				'count'    => (int) ( $bucket['count'] ?? 0 ),
				'examples' => implode(', ', array_filter($examples)),
			);
		}
		$this->format_items($rows, array( 'reason', 'count', 'examples' ), array( 'format' => 'table' ), 'reason');
	}

	private function render_cleanup_plan_result( array $result, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		WP_CLI::success(sprintf('Cleanup plan stored as %s.', (string) ( $result['run_id'] ?? '' )));
		WP_CLI::log(sprintf('Run ID: %s', (string) ( $result['run_id'] ?? '' )));
		WP_CLI::log(sprintf('Plan ID: %s', (string) ( $result['plan_id'] ?? '' )));
		WP_CLI::log(sprintf('Rows:   %d', (int) ( $summary['total_rows'] ?? 0 )));
		WP_CLI::log(sprintf('Reclaimable: %s', $this->format_bytes($summary['total_reclaimable_bytes'] ?? $summary['total_size_bytes'] ?? 0)));
		$byte_totals = (array) ( $summary['byte_totals'] ?? array() );
		if ( array() !== $byte_totals ) {
			foreach ( $byte_totals as $type => $bytes ) {
				WP_CLI::log(sprintf('  %s: %s', (string) $type, $this->format_bytes($bytes)));
			}
		}
		WP_CLI::log(sprintf('Apply:  wp datamachine-code workspace cleanup apply %s', (string) ( $result['run_id'] ?? '' )));
		$blocked = (array) ( $summary['blocked_by_type'] ?? array() );
		if ( array_sum(array_map('intval', $blocked)) > 0 ) {
			WP_CLI::log('Blocked/kept rows are included in JSON under `blocked` with reason_code/reason; they are not applyable cleanup rows.');
		}
		$this->render_cleanup_plan_category_totals( (array) ( $summary['category_totals'] ?? array() ) );
		$this->render_cleanup_plan_top_reclaimable( (array) ( $summary['top_reclaimable'] ?? array() ) );
		$this->render_cleanup_plan_blockers( (array) ( $summary['blockers'] ?? array() ) );
		$this->render_cleanup_plan_recommended_commands( (array) ( $summary['recommended_commands'] ?? array() ), (string) ( $result['run_id'] ?? '' ) );
		$inputs = (array) ( $result['inputs'] ?? array() );
		if ( empty($inputs['include_artifacts']) ) {
			WP_CLI::log('Artifacts: skipped for bounded retention planning; run `wp datamachine-code workspace cleanup plan --mode=artifacts` when you want artifact rows.');
		}
	}

	/**
	 * Render reclaimable cleanup bytes by category.
	 *
	 * @param array<string,int> $totals Category totals.
	 */
	private function render_cleanup_plan_category_totals( array $totals ): void {
		if ( array() === $totals ) {
			return;
		}

		WP_CLI::log('');
		WP_CLI::log('Reclaimable space by category:');
		$labels = array(
			'whole_worktrees'      => 'whole worktrees',
			'dependency_artifacts' => 'dependency artifacts',
			'build_outputs'        => 'build outputs',
			'caches'               => 'caches',
		);
		$rows   = array();
		foreach ( $labels as $category => $label ) {
			$rows[] = array(
				'category' => $label,
				'bytes'    => $this->format_bytes($totals[ $category ] ?? 0),
			);
		}
		$this->format_items($rows, array( 'category', 'bytes' ), array( 'format' => 'table' ), 'category');
	}

	/**
	 * Render largest reclaimable paths.
	 *
	 * @param array<int,array<string,mixed>> $paths Top paths.
	 */
	private function render_cleanup_plan_top_reclaimable( array $paths ): void {
		if ( array() === $paths ) {
			return;
		}

		WP_CLI::log('');
		WP_CLI::log('Top reclaimable paths:');
		$rows = array_map(
			fn( $row ) => array(
				'size'     => $this->format_bytes($row['size_bytes'] ?? 0),
				'category' => (string) ( $row['category'] ?? '' ),
				'risk'     => (string) ( $row['safety_class'] ?? '' ),
				'handle'   => (string) ( $row['handle'] ?? '' ),
				'path'     => (string) ( $row['path'] ?? '' ),
			),
			$paths
		);
		$this->format_items($rows, array( 'size', 'category', 'risk', 'handle', 'path' ), array( 'format' => 'table' ), 'size');
	}

	/**
	 * Render blockers grouped by reason and repo.
	 *
	 * @param array<string,array<string,mixed>> $blockers Blocker buckets.
	 */
	private function render_cleanup_plan_blockers( array $blockers ): void {
		if ( array() === $blockers ) {
			return;
		}

		WP_CLI::log('');
		WP_CLI::log('Blockers by reason and repo:');
		$rows = array();
		foreach ( $blockers as $reason => $bucket ) {
			$bucket = (array) $bucket;
			$repos  = array();
			foreach ( (array) ( $bucket['repos'] ?? array() ) as $repo => $repo_bucket ) {
				$repo_bucket = (array) $repo_bucket;
				$repos[]     = sprintf('%s=%d', (string) $repo, (int) ( $repo_bucket['count'] ?? 0 ));
			}
			$rows[] = array(
				'reason'   => (string) $reason,
				'count'    => (int) ( $bucket['count'] ?? 0 ),
				'bytes'    => $this->format_bytes($bucket['size_bytes'] ?? 0),
				'repos'    => implode(', ', array_slice($repos, 0, 5)),
				'examples' => implode(', ', array_slice(array_map('strval', (array) ( $bucket['examples'] ?? array() )), 0, 5)),
			);
		}
		$this->format_items($rows, array( 'reason', 'count', 'bytes', 'repos', 'examples' ), array( 'format' => 'table' ), 'reason');
	}

	/**
	 * Render directly executable recommended cleanup commands.
	 *
	 * @param array<int,array<string,string>> $commands Recommended commands.
	 * @param string                          $run_id   Cleanup run ID.
	 */
	private function render_cleanup_plan_recommended_commands( array $commands, string $run_id ): void {
		if ( array() === $commands ) {
			return;
		}

		WP_CLI::log('');
		WP_CLI::log('Recommended commands:');
		$rows = array_map(
			function ( $row ) use ( $run_id ): array {
				$row     = (array) $row;
				$command = (string) ( $row['command'] ?? '' );
				if ( '' !== $run_id ) {
					$command = str_replace('<run-id>', $run_id, $command);
				}
				return array(
					'label'   => (string) ( $row['label'] ?? '' ),
					'risk'    => (string) ( $row['risk'] ?? '' ),
					'command' => $command,
				);
			},
			$commands
		);
		$this->format_items($rows, array( 'label', 'risk', 'command' ), array( 'format' => 'table' ), 'label');
	}

	private function cleanup_run_id( int $job_id ): string {
		return 'cleanup-run-' . $job_id;
	}

	private function cleanup_run_job_id( string $run_id ): int {
		$run_id = trim($run_id);
		if ( is_numeric($run_id) ) {
			return (int) $run_id;
		}
		if ( preg_match('/^cleanup-run-(\d+)$/', $run_id, $matches) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	private function is_job_cleanup_run_id( string $run_id ): bool {
		return $this->cleanup_run_job_id($run_id) > 0;
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Repository directory name to remove.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove a repo (with confirmation)
	 *     wp datamachine-code workspace remove my-plugin
	 *
	 *     # Remove without confirmation
	 *     wp datamachine-code workspace remove my-plugin --yes
	 *
	 * @subcommand remove
	 */
	public function remove_repo( array $args, array $assoc_args ): void {
		if ( empty($args[0]) ) {
			WP_CLI::error('Repository name is required.');
			return;
		}

		$name = $args[0];

		// Confirm unless --yes is passed. This stays in CLI — abilities don't prompt.
		if ( empty($assoc_args['yes']) ) {
			$workspace = new Workspace();
			$repo_path = $workspace->get_repo_path($name);
			WP_CLI::confirm(sprintf('Remove "%s" from workspace? This deletes %s', $name, $repo_path));
		}

		$ability = wp_get_ability('datamachine-code/workspace-remove');
		if ( ! $ability ) {
			WP_CLI::error('Workspace remove ability not available.');
			return;
		}

		$result = $ability->execute(array( 'name' => $name ));

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		WP_CLI::success($result['message']);
	}

	/**
	 * Show a non-destructive workspace hygiene report.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--skip-cleanup]
	 * : Skip the local cleanup dry-run summary.
	 *
	 * [--skip-sizes]
	 * : Skip best-effort workspace size collection.
	 *
	 * [--include-worktree-status]
	 * : Include full per-worktree git status. This can be expensive on huge workspaces.
	 *
	 * [--refresh-inventory]
	 * : Refresh the DB-backed worktree inventory before reporting freshness.
	 *
	 * [--size-limit=<count>]
	 * : Maximum top-level workspace entries to size.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace hygiene
	 *     wp datamachine-code workspace hygiene --format=json
	 *
	 * @subcommand hygiene
	 */
	public function hygiene( array $args, array $assoc_args ): void {   // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$ability = wp_get_ability('datamachine-code/workspace-hygiene-report');
		if ( ! $ability ) {
			WP_CLI::error('Workspace hygiene ability not available.');
			return;
		}

		$input = array(
			'include_cleanup'         => empty($assoc_args['skip-cleanup']),
			'include_sizes'           => empty($assoc_args['skip-sizes']),
			'include_worktree_status' => ! empty($assoc_args['include-worktree-status']),
			'refresh_inventory'       => ! empty($assoc_args['refresh-inventory']),
		);
		if ( isset($assoc_args['size-limit']) ) {
			$input['size_limit'] = (int) $assoc_args['size-limit'];
		}

		$result = $ability->execute($input);
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$this->render_workspace_hygiene_report($result, $assoc_args);
	}

	/**
	 * Manage the DB-backed workspace inventory.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Inventory operation. Currently: refresh.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace inventory refresh --format=json
	 *
	 * @subcommand inventory
	 */
	public function inventory( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';
		if ( 'refresh' !== $operation ) {
			WP_CLI::error('Usage: wp datamachine-code workspace inventory refresh [--format=<format>]');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-worktree-inventory-refresh');
		if ( ! $ability ) {
			WP_CLI::error('Workspace inventory refresh ability not available.');
			return;
		}

		$result = $ability->execute(array());
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		if ( 'json' === (string) ( $assoc_args['format'] ?? '' ) ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		WP_CLI::success(sprintf('Inventory refreshed: %d upserted, %d marked missing.', (int) ( $summary['upserted'] ?? 0 ), (int) ( $summary['marked_missing'] ?? 0 )));
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Repository directory name.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show repo info
	 *     wp datamachine-code workspace show my-plugin
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		if ( empty($args[0]) ) {
			WP_CLI::error('Repository name is required.');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-show');
		if ( ! $ability ) {
			WP_CLI::error('Workspace show ability not available.');
			return;
		}

		$result = $ability->execute(array( 'name' => $args[0] ));

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		WP_CLI::log(sprintf('Name:     %s', $result['name']));
		WP_CLI::log(sprintf('Path:     %s', $result['path']));
		WP_CLI::log(sprintf('Branch:   %s', $result['branch'] ?? '-'));
		WP_CLI::log(sprintf('Remote:   %s', $result['remote'] ?? '-'));
		WP_CLI::log(sprintf('Latest:   %s', $result['commit'] ?? '-'));
		if ( empty($result['is_worktree']) && is_array($result['primary_freshness'] ?? null) ) {
			$freshness = $result['primary_freshness'];
			WP_CLI::log(sprintf('Freshness: %s', (string) ( $freshness['status'] ?? 'unknown' )));
			WP_CLI::log(sprintf('Upstream: %s', (string) ( $freshness['upstream'] ?? '-' )));
			WP_CLI::log(sprintf('Behind:   %s', null === ( $freshness['behind'] ?? null ) ? '-' : (string) $freshness['behind']));
			WP_CLI::log(sprintf('Ahead:    %s', null === ( $freshness['ahead'] ?? null ) ? '-' : (string) $freshness['ahead']));
			if ( ! empty($freshness['suggested_command']) ) {
				WP_CLI::log(sprintf('Refresh:  %s', (string) $freshness['suggested_command']));
			}
		}

		$dirty = $result['dirty'] ?? 0;
		WP_CLI::log(sprintf('Dirty:    %s', ( 0 === $dirty ) ? 'no' : "yes ({$dirty} files)"));
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * Reads text file contents from a cloned repository in the workspace.
	 * Binary files are detected and rejected. Large files are limited by
	 * --max-size (default 1 MB). Use --offset and --limit to read specific
	 * line ranges.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * [--max-size=<bytes>]
	 * : Maximum file size in bytes.
	 * ---
	 * default: 1048576
	 * ---
	 *
		 * [--offset=<line>]
		 * : Line number to start reading from (1-indexed).
		 *
		 * [--limit=<lines>]
		 * : Maximum number of lines to return.
		 *
		 * [--allow-stale-primary]
		 * : Explicitly read from a stale, diverged, detached, or otherwise unsafe primary checkout.
	 *
	 * ## EXAMPLES
	 *
	 *     # Read a file
	 *     wp datamachine-code workspace read my-plugin src/main.php
	 *
	 *     # Read with custom size limit
	 *     wp datamachine-code workspace read my-plugin composer.json --max-size=2097152
	 *
	 *     # Read lines 100-130 from a file
	 *     wp datamachine-code workspace read extrachill style.css --offset=100 --limit=30
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		if ( empty($args[0]) || empty($args[1]) ) {
			WP_CLI::error('Usage: wp datamachine-code workspace read <repo> <path>');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-read');
		if ( ! $ability ) {
			WP_CLI::error('Workspace read ability not available.');
			return;
		}

		$input = array(
			'repo' => $args[0],
			'path' => $args[1],
		);

		if ( isset($assoc_args['max-size']) ) {
			$input['max_size'] = (int) $assoc_args['max-size'];
		}

		if ( isset($assoc_args['offset']) ) {
			$input['offset'] = (int) $assoc_args['offset'];
		}

		if ( isset($assoc_args['limit']) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}

		if ( ! empty($assoc_args['allow-stale-primary']) ) {
			$input['allow_stale_primary'] = true;
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		// Output raw content — suitable for piping.
		WP_CLI::log($result['content']);
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * Lists files and directories. Directories are listed first, then
	 * files, both sorted alphabetically.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
		 * [<path>]
		 * : Relative directory path within the repo (defaults to root).
		 *
		 * [--allow-stale-primary]
		 * : Explicitly list a stale, diverged, detached, or otherwise unsafe primary checkout.
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List repo root
	 *     wp datamachine-code workspace ls my-plugin
	 *
	 *     # List subdirectory
	 *     wp datamachine-code workspace ls my-plugin src
	 *
	 *     # List as JSON
	 *     wp datamachine-code workspace ls my-plugin --format=json
	 *
	 * @subcommand ls
	 */
	public function ls( array $args, array $assoc_args ): void {
		if ( empty($args[0]) ) {
			WP_CLI::error('Usage: wp datamachine-code workspace ls <repo> [<path>]');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-ls');
		if ( ! $ability ) {
			WP_CLI::error('Workspace ls ability not available.');
			return;
		}

		$input = array( 'repo' => $args[0] );

		if ( ! empty($args[1]) ) {
			$input['path'] = $args[1];
		}

		if ( ! empty($assoc_args['allow-stale-primary']) ) {
			$input['allow_stale_primary'] = true;
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		if ( empty($result['entries']) ) {
			WP_CLI::log('Empty directory.');
			return;
		}

		$items = array_map(
			function ( $entry ) {
				return array(
					'name' => $entry['name'],
					'type' => $entry['type'],
					'size' => isset($entry['size']) ? size_format($entry['size']) : '-',
				);
			},
			$result['entries']
		);

		$this->format_items(
			$items,
			array( 'name', 'type', 'size' ),
			$assoc_args,
			'name'
		);
	}

	/**
	 * Search files in a workspace repo.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name or worktree handle.
	 *
	 * <pattern>
	 * : Regular expression pattern to search for.
	 *
	 * [<path>]
	 * : Relative file or directory path to search within.
	 *
	 * [--include=<glob>]
	 * : Optional glob pattern to limit matching file paths.
	 *
	 * [--max-results=<count>]
	 * : Maximum number of matches to return.
	 * ---
	 * default: 100
	 * ---
	 *
		 * [--context-lines=<count>]
		 * : Number of surrounding lines to include for each match.
		 * ---
		 * default: 0
		 * ---
		 *
		 * [--allow-stale-primary]
		 * : Explicitly grep a stale, diverged, detached, or otherwise unsafe primary checkout.
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace grep my-plugin "function_name" --include="*.php"
	 *
	 * @subcommand grep
	 */
	public function grep( array $args, array $assoc_args ): void {
		if ( empty($args[0]) || ! isset($args[1]) ) {
			WP_CLI::error('Usage: wp datamachine-code workspace grep <repo> <pattern> [<path>]');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-grep');
		if ( ! $ability ) {
			WP_CLI::error('Workspace grep ability not available.');
			return;
		}

		$input = array(
			'repo'    => $args[0],
			'pattern' => $args[1],
		);

		if ( ! empty($args[2]) ) {
			$input['path'] = $args[2];
		}

		if ( isset($assoc_args['include']) ) {
			$input['include'] = $assoc_args['include'];
		}

		if ( isset($assoc_args['max-results']) ) {
			$input['max_results'] = (int) $assoc_args['max-results'];
		}

		if ( isset($assoc_args['context-lines']) ) {
			$input['context_lines'] = (int) $assoc_args['context-lines'];
		}

		if ( ! empty($assoc_args['allow-stale-primary']) ) {
			$input['allow_stale_primary'] = true;
		}

		$result = $ability->execute($input);
		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$matches = (array) ( $result['matches'] ?? array() );
		if ( empty($matches) ) {
			WP_CLI::log('No matches.');
			return;
		}

		$items = array_map(
			function ( $row ) {
				return array(
					'path' => $row['path'] ?? '',
					'line' => $row['line'] ?? 0,
					'text' => $row['text'] ?? '',
				);
			},
			$matches
		);

		$this->format_items($items, array( 'path', 'line', 'text' ), $assoc_args, 'path');
		if ( ! empty($result['truncated']) ) {
			WP_CLI::warning('Results truncated. Increase --max-results for more matches.');
		}
	}

	/**
	 * Write a file to a workspace repo.
	 *
	 * Creates or overwrites a file. Parent directories are created as needed.
	 * Content can be passed via --content flag or piped via stdin.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * [--content=<content>]
	 * : File content to write. Prefix with @ to read from a local file (e.g. --content=@/tmp/code.rs).
	 * : If omitted, reads from stdin.
	 *
	 * ## EXAMPLES
	 *
	 *     # Write with content flag
	 *     wp datamachine-code workspace write my-plugin src/new.php --content="<?php"
	 *
	 *     # Write from a local file (@ syntax)
	 *     wp datamachine-code workspace write my-plugin src/main.php --content=@/tmp/staged-code.php
	 *
	 *     # Write from stdin
	 *     cat local-file.php | wp datamachine-code workspace write my-plugin src/main.php
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		if ( empty($args[0]) || empty($args[1]) ) {
			WP_CLI::error('Usage: wp datamachine-code workspace write <repo> <path> --content=<content>');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-write');
		if ( ! $ability ) {
			WP_CLI::error('Workspace write ability not available.');
			return;
		}

		$content = $assoc_args['content'] ?? null;

		// Resolve @file syntax — read content from a local file.
		if ( null !== $content ) {
			$content = $this->resolveAtFile($content);
		}

		// Read from stdin if --content not provided.
		if ( null === $content ) {
			if ( function_exists('posix_isatty') && posix_isatty(STDIN) ) {
				WP_CLI::error('No content provided. Use --content=<content> or pipe content via stdin.');
				return;
			}
         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents('php://stdin');
			if ( false === $content ) {
				WP_CLI::error('Failed to read from stdin.');
				return;
			}
		}

		$result = $ability->execute(
			array(
				'repo'    => $args[0],
				'path'    => $args[1],
				'content' => $content,
			)
		);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$action = ! empty($result['created']) ? 'Created' : 'Updated';
		WP_CLI::success(sprintf('%s %s (%s)', $action, $result['path'], size_format($result['size'])));
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * Performs exact string replacement. Fails if the old string is not found,
	 * or if multiple matches exist (unless --replace-all is used).
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * --old=<string>
	 * : Text to find. Prefix with @ to read from a local file (e.g. --old=@/tmp/old.txt).
	 *
	 * --new=<string>
	 * : Replacement text. Prefix with @ to read from a local file (e.g. --new=@/tmp/new.txt).
	 *
	 * [--replace-all]
	 * : Replace all occurrences instead of requiring a unique match.
	 *
	 * ## EXAMPLES
	 *
	 *     # Replace a single occurrence
	 *     wp datamachine-code workspace edit my-plugin src/main.php --old="old_func" --new="new_func"
	 *
	 *     # Replace using @ file syntax
	 *     wp datamachine-code workspace edit my-plugin src/main.php --old=@/tmp/old.txt --new=@/tmp/new.txt
	 *
	 *     # Replace all occurrences
	 *     wp datamachine-code workspace edit my-plugin src/main.php --old="v1" --new="v2" --replace-all
	 *
	 * @subcommand edit
	 */
	public function edit( array $args, array $assoc_args ): void {
		if ( empty($args[0]) || empty($args[1]) ) {
			WP_CLI::error('Usage: wp datamachine-code workspace edit <repo> <path> --old=<string> --new=<string>');
			return;
		}

		if ( ! isset($assoc_args['old']) || ! isset($assoc_args['new']) ) {
			WP_CLI::error('Both --old and --new flags are required.');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-edit');
		if ( ! $ability ) {
			WP_CLI::error('Workspace edit ability not available.');
			return;
		}

		$input = array(
			'repo'       => $args[0],
			'path'       => $args[1],
			'old_string' => $this->resolveAtFile($assoc_args['old']),
			'new_string' => $this->resolveAtFile($assoc_args['new']),
		);

		if ( ! empty($assoc_args['replace-all']) ) {
			$input['replace_all'] = true;
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$count = $result['replacements'] ?? 1;
		WP_CLI::success(
			sprintf(
				'Edited %s (%d replacement%s)',
				$result['path'],
				$count,
				1 === $count ? '' : 's'
			)
		);
	}

	/**
	 * Apply a unified diff to a workspace repo.
	 *
	 * The diff is validated with `git apply --check` before any mutation. On
	 * success, the command returns changed files plus diff/status evidence for
	 * the normal workspace git add/commit/push/PR flow.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Patch operation. Currently only: apply
	 *
	 * <repo>
	 * : Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).
	 *
	 * [--patch=<patch>]
	 * : Unified diff content. Prefix with @ to read from a local file. If omitted, reads from stdin.
	 *
	 * [--allow-primary-mutation]
	 * : Permit mutation on the primary checkout (default off). Worktrees are always allowed.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Apply a reviewed patch file in a worktree
	 *     wp datamachine-code workspace patch apply data-machine-code@fix-ci --patch=@/tmp/fix.diff
	 *
	 *     # Apply from stdin
	 *     git diff | wp datamachine-code workspace patch apply data-machine-code@fix-ci
	 *
	 * @subcommand patch
	 */
	public function patch( array $args, array $assoc_args ): void {
		$operation = (string) ( $args[0] ?? '' );
		$repo      = (string) ( $args[1] ?? '' );

		if ( 'apply' !== $operation || '' === $repo ) {
			WP_CLI::error('Usage: wp datamachine-code workspace patch apply <repo> [--patch=<diff>] [--allow-primary-mutation]');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-apply-patch');
		if ( ! $ability ) {
			WP_CLI::error('Workspace apply patch ability not available.');
			return;
		}

		$patch = $assoc_args['patch'] ?? null;
		if ( null !== $patch ) {
			$patch = $this->resolveAtFile( (string) $patch);
		}

		if ( null === $patch ) {
			if ( function_exists('posix_isatty') && posix_isatty(STDIN) ) {
				WP_CLI::error('No patch provided. Use --patch=<diff>, --patch=@/tmp/fix.diff, or pipe a unified diff via stdin.');
				return;
			}
         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$patch = file_get_contents('php://stdin');
			if ( false === $patch ) {
				WP_CLI::error('Failed to read patch from stdin.');
				return;
			}
		}

		$result = $ability->execute(
			array(
				'repo'                   => $repo,
				'patch'                  => $patch,
				'allow_primary_mutation' => ! empty($assoc_args['allow-primary-mutation']),
			)
		);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		if ( 'json' === (string) ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( (string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}

		$changed_files = is_array($result['changed_files'] ?? null) ? $result['changed_files'] : array();
		WP_CLI::success(sprintf('Applied patch to %s (%d changed file%s).', $repo, count($changed_files), 1 === count($changed_files) ? '' : 's'));
		foreach ( $changed_files as $file ) {
			WP_CLI::log('  - ' . $file);
		}
	}

	/**
	 * Delete a tracked or untracked path from a workspace repo.
	 *
	 * Tracked paths are removed via `git rm` (working tree + index in one
	 * shot). Untracked paths are unlinked from disk; directories require
	 * --recursive. Sensitive-path, traversal, allowlist, and primary-mutation
	 * gates apply just like `workspace git add`.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).
	 *
	 * <path>
	 * : Relative path within the repo (file or directory).
	 *
	 * [--recursive]
	 * : Required when target is a directory.
	 *
	 * [--allow-primary-mutation]
	 * : Permit mutation on the primary checkout (default off). Worktrees are always allowed.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete a tracked file (staged via git rm)
	 *     wp datamachine-code workspace delete my-plugin@my-branch src/old_module.php
	 *
	 *     # Delete an entire directory tree
	 *     wp datamachine-code workspace delete my-plugin@my-branch src/classic --recursive
	 *
	 *     # Delete on the primary checkout (rare, gated)
	 *     wp datamachine-code workspace delete my-plugin notes.md --allow-primary-mutation
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( empty($args[0]) || empty($args[1]) ) {
			WP_CLI::error('Usage: wp datamachine-code workspace delete <repo> <path> [--recursive] [--allow-primary-mutation]');
			return;
		}

		$ability = wp_get_ability('datamachine-code/workspace-delete');
		if ( ! $ability ) {
			WP_CLI::error('Workspace delete ability not available.');
			return;
		}

		$input = array(
			'repo' => $args[0],
			'path' => $args[1],
		);

		if ( ! empty($assoc_args['recursive']) ) {
			$input['recursive'] = true;
		}
		if ( ! empty($assoc_args['allow-primary-mutation']) ) {
			$input['allow_primary_mutation'] = true;
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$count = is_array($result['deleted'] ?? null) ? count($result['deleted']) : 1;
		$mode  = ! empty($result['was_tracked']) ? 'git rm' : 'unlink';
		WP_CLI::success(
			sprintf(
				'Deleted %s via %s (%d path%s removed)',
				$result['path'],
				$mode,
				$count,
				1 === $count ? '' : 's'
			)
		);
	}

	/**
	 * Collect every `--<flag>=<value>` occurrence from the raw process argv.
	 *
	 * WP-CLI's parsed `$assoc_args` is last-wins for repeated assoc flags —
	 * it never returns an array, even when the synopsis declares the flag as
	 * repeatable (`...`). For commands that document a flag as repeatable
	 * (`--rel`, `--include`, etc.), we walk $GLOBALS['argv'] directly so
	 * every occurrence is preserved in argv order.
	 *
	 * Empty values are filtered out. Bare `--<flag>` (no value) is ignored
	 * because it's ambiguous in the assoc-flag context.
	 *
	 * @param  string $flag Flag name without the leading `--` (e.g. 'rel').
	 * @return string[] Every value, in argv order, for the named flag.
	 */
	private function collectRepeatableFlag( string $flag ): array {
		$argv = $GLOBALS['argv'] ?? array();
		if ( ! is_array($argv) ) {
			return array();
		}

		$prefix     = '--' . $flag . '=';
		$prefix_len = strlen($prefix);
		$values     = array();

		foreach ( $argv as $token ) {
			if ( ! is_string($token) ) {
				continue;
			}
			if ( 0 === strpos($token, $prefix) ) {
				$value = substr($token, $prefix_len);
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Resolve @file syntax — if a string starts with @, read file contents.
	 *
	 * Mirrors curl's -d @filename convention. If the value doesn't start
	 * with @, it's returned unchanged.
	 *
	 * @param  string $value Raw CLI argument value.
	 * @return string Resolved content (file contents or original value).
	 */
	private function resolveAtFile( string $value ): string {
		if ( 0 !== strpos($value, '@') ) {
			return $value;
		}

		$file_path = substr($value, 1);

		if ( empty($file_path) ) {
			WP_CLI::error('Empty file path after @. Usage: --content=@/path/to/file');
		}

		if ( ! file_exists($file_path) ) {
			WP_CLI::error(sprintf('File not found: %s', $file_path));
		}

		if ( ! is_readable($file_path) ) {
			WP_CLI::error(sprintf('File not readable: %s', $file_path));
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($file_path);

		if ( false === $content ) {
			WP_CLI::error(sprintf('Failed to read file: %s', $file_path));
			return '';
		}

		return $content;
	}

	/**
	 * Git operations for workspace repositories.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Git operation: status, pull, add, commit, push, log, diff
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * [<value>]
	 * : Optional operation value (e.g., commit message for commit).
	 *
	 * [--rel=<path>]
	 * : Relative path (repeatable) for add/diff operations. Named `--rel`
	 *   to avoid colliding with WP-CLI's documented global `--path` flag.
	 *
		 * [--allow-dirty]
		 * : Allow pull with dirty working tree.
		 *
		 * [--allow-primary-refresh]
		 * : Permit safe primary refresh with `git pull --ff-only`.
		 *
		 * [--allow-primary-mutation]
		 * : Legacy alias for `--allow-primary-refresh` on `git pull`, and for non-dangerous primary file/index mutations. Does not permit primary commit, push, reset, or rebase.
		 *
		 * [--allow-dangerous-primary-mutation]
		 * : Permit primary commit, push, reset, or rebase. Use only for an explicitly approved primary mutation.
	 *
	 * [--remote=<remote>]
	 * : Remote name for pull/push (default: origin).
	 *
	 * [--branch=<branch>]
	 * : Branch override for pull/push. For pull, supplies an explicit remote branch when the checkout is detached.
	 *
	 * [--from=<ref>]
	 * : From ref for diff.
	 *
	 * [--to=<ref>]
	 * : To ref for diff.
	 *
	 * [--staged]
	 * : Show staged diff.
	 *
	 * [--limit=<n>]
	 * : Number of log entries to return (default 20).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show git status for a workspace repo
	 *     wp datamachine-code workspace git status data-machine
	 *
	 *     # Pull latest changes
	 *     wp datamachine-code workspace git pull data-machine
	 *
	 *     # Stage docs paths
	 *     wp datamachine-code workspace git add extrachill-docs --rel=ec_docs/community/getting-started.md
	 *
	 *     # Commit staged changes
	 *     wp datamachine-code workspace git commit extrachill-docs "docs: update community guide"
	 *
	 *     # Push current branch to origin
	 *     wp datamachine-code workspace git push extrachill-docs --remote=origin
	 *
	 *     # Show recent log
	 *     wp datamachine-code workspace git log data-machine --limit=10
	 *
	 *     # Show diff for a path
	 *     wp datamachine-code workspace git diff data-machine --rel=inc/Core/FilesRepository/Workspace.php
	 *
	 * @subcommand git
	 */
	public function git( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';
		$repo      = $args[1] ?? '';

		if ( '' === $operation || '' === $repo ) {
			WP_CLI::error('Usage: wp datamachine-code workspace git <operation> <repo> [<value>] [--flags]');
			return;
		}

		$ability_name = match ( $operation ) {
			'status'    => 'datamachine-code/workspace-git-status',
			'pull'      => 'datamachine-code/workspace-git-pull',
			'add'       => 'datamachine-code/workspace-git-add',
			'commit'    => 'datamachine-code/workspace-git-commit',
			'push'      => 'datamachine-code/workspace-git-push',
			'rebase'    => 'datamachine-code/workspace-git-rebase',
			'reset'     => 'datamachine-code/workspace-git-reset',
			'pr-status' => 'datamachine-code/workspace-pr-status',
			'pr-rebase' => 'datamachine-code/workspace-pr-rebase',
			'log'       => 'datamachine-code/workspace-git-log',
			'diff'      => 'datamachine-code/workspace-git-diff',
			default  => '',
		};

		if ( '' === $ability_name ) {
			WP_CLI::error(sprintf('Unknown git operation: %s', $operation));
			return;
		}

		$ability = wp_get_ability($ability_name);
		if ( ! $ability ) {
			WP_CLI::error(sprintf('Workspace git ability not available: %s', $ability_name));
			return;
		}

		$input = array( 'name' => $repo );

		if ( 'pull' === $operation ) {
			$input['allow_primary_refresh'] = ! empty($assoc_args['allow-primary-refresh']) || ! empty($assoc_args['allow-primary-mutation']);
		} elseif ( in_array($operation, array( 'commit', 'push', 'rebase', 'reset', 'pr-rebase' ), true) ) {
			$input['allow_dangerous_primary_mutation'] = ! empty($assoc_args['allow-dangerous-primary-mutation']);
		} elseif ( in_array($operation, array( 'add' ), true) ) {
			$input['allow_primary_mutation'] = ! empty($assoc_args['allow-primary-mutation']);
		}

		if ( 'pull' === $operation ) {
			$input['allow_dirty'] = ! empty($assoc_args['allow-dirty']);
			$input['remote']      = $assoc_args['remote'] ?? 'origin';
			if ( ! empty($assoc_args['branch']) ) {
				$input['branch'] = (string) $assoc_args['branch'];
			}
		}

		if ( 'add' === $operation ) {
			$input['paths'] = $this->collectRepeatableFlag('rel');

			if ( empty($input['paths']) ) {
				WP_CLI::error('git add requires at least one --rel=<relative/path>.');
				return;
			}
		}

		if ( 'commit' === $operation ) {
			$message = $args[2] ?? '';
			if ( '' === trim($message) ) {
				WP_CLI::error('git commit requires a commit message as the third argument.');
				return;
			}
			$input['message'] = $message;
		}

		if ( 'push' === $operation ) {
			$input['remote'] = $assoc_args['remote'] ?? 'origin';
			if ( ! empty($assoc_args['branch']) ) {
				$input['branch'] = (string) $assoc_args['branch'];
			}
			if ( ! empty($assoc_args['force-with-lease']) ) {
				$input['force_with_lease'] = true;
			}
			if ( ! empty($assoc_args['expected-sha']) ) {
				$input['expected_sha'] = (string) $assoc_args['expected-sha'];
			}
		}

		if ( 'rebase' === $operation ) {
			if ( ! empty($assoc_args['onto']) ) {
				$input['onto'] = (string) $assoc_args['onto'];
			}
			if ( ! empty($assoc_args['strategy-option']) ) {
				$input['strategy_option'] = (string) $assoc_args['strategy-option'];
			}
			if ( ! empty($assoc_args['continue']) ) {
				$input['continue'] = true;
			}
		}

		if ( 'reset' === $operation ) {
			if ( ! empty($assoc_args['mode']) ) {
				$input['mode'] = (string) $assoc_args['mode'];
			}
			if ( ! empty($assoc_args['target']) ) {
				$input['target'] = (string) $assoc_args['target'];
			}
			if ( ! empty($assoc_args['allow-destructive']) ) {
				$input['allow_destructive'] = true;
			}
		}

		if ( 'pr-status' === $operation || 'pr-rebase' === $operation ) {
			if ( ! empty($assoc_args['pr']) ) {
				$input['pr'] = (string) $assoc_args['pr'];
			}
		}

		if ( 'pr-status' === $operation && ! empty($assoc_args['branch']) ) {
			$input['branch'] = (string) $assoc_args['branch'];
		}

		if ( 'pr-rebase' === $operation ) {
			if ( ! empty($assoc_args['squash']) ) {
				$input['squash'] = true;
			}
			$drop_paths = $this->collectRepeatableFlag('drop-path');
			if ( ! empty($drop_paths) ) {
				$input['drop_paths'] = $drop_paths;
			}
		}

		if ( 'log' === $operation ) {
			if ( isset($assoc_args['limit']) ) {
				$input['limit'] = (int) $assoc_args['limit'];
			}
		}

		if ( 'diff' === $operation ) {
			if ( isset($assoc_args['from']) ) {
				$input['from'] = (string) $assoc_args['from'];
			}
			if ( isset($assoc_args['to']) ) {
				$input['to'] = (string) $assoc_args['to'];
			}
			if ( ! empty($assoc_args['staged']) ) {
				$input['staged'] = true;
			}
			$diff_paths = $this->collectRepeatableFlag('rel');
			if ( ! empty($diff_paths) ) {
				$input['path'] = (string) $diff_paths[0];
			}
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		$this->renderGitOperationResult($operation, $result, $assoc_args);
	}

	/**
	 * Render CLI output for workspace git operations.
	 *
	 * @param string $operation  Git operation.
	 * @param array  $result     Ability result.
	 * @param array  $assoc_args CLI assoc args.
	 */
	private function renderGitOperationResult( string $operation, array $result, array $assoc_args ): void {
		switch ( $operation ) {
			case 'status':
				WP_CLI::log(sprintf('Repo:   %s', $result['name'] ?? '-'));
				WP_CLI::log(sprintf('Path:   %s', $result['path'] ?? '-'));
				WP_CLI::log(sprintf('Branch: %s', $result['branch'] ?? '-'));
				WP_CLI::log(sprintf('Remote: %s', $result['remote'] ?? '-'));
				WP_CLI::log(sprintf('Latest: %s', $result['commit'] ?? '-'));
				$dirty = (int) ( $result['dirty'] ?? 0 );
				WP_CLI::log(sprintf('Dirty:  %s', 0 === $dirty ? 'no' : "yes ({$dirty} files)"));
				if ( ! empty($result['files']) ) {
					WP_CLI::log('');
					foreach ( $result['files'] as $file ) {
						WP_CLI::log( (string) $file);
					}
				}
				return;

			case 'log':
				if ( empty($result['entries']) ) {
					WP_CLI::log('No commits found.');
					return;
				}

				$items = array_map(
				fn( $entry ) => array(
					'hash'    => $entry['hash'] ?? '',
					'author'  => $entry['author'] ?? '',
					'date'    => $entry['date'] ?? '',
					'subject' => $entry['subject'] ?? '',
				),
				$result['entries']
				);

				$this->format_items($items, array( 'hash', 'author', 'date', 'subject' ), $assoc_args, 'hash');
				return;

			case 'diff':
				WP_CLI::log( (string) ( $result['diff'] ?? '' ));
				return;

			default:
				WP_CLI::success($result['message'] ?? 'Workspace git operation completed.');
				return;
		}
	}

	/**
	 * Manage workspace git worktrees.
	 *
	 * Worktrees let multiple agent sessions work on the same repo without
	 * stepping on each other. Each branch lives in its own directory at
	 * `<workspace>/<repo>@<branch-slug>`. Branch slashes become dashes in
	 * the slug (`fix/foo` → `fix-foo`).
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Worktree operation: add, list, remove, prune, locks, cleanup, cleanup-artifacts,
	 *   bounded-cleanup-eligible-apply, emergency-cleanup, reconcile-metadata,
	 *   active-no-signal-report, active-no-signal-finalized-apply,
	 *   active-no-signal-equivalent-clean-apply,
	 *   active-no-signal-merged-apply, active-no-signal-remote-clean-apply,
	 *   refresh-context, finalize, mark-cleanup-eligible.
	 *
	 * [<repo>]
	 * : Primary repo name (required for add and remove). For refresh-context, finalize,
	 *   and mark-cleanup-eligible,
	 *   pass the full worktree handle (`<repo>@<branch-slug>`) here instead.
	 *
	 * [<branch>]
	 * : Branch name (required for add and remove).
	 *
	 * [--from=<ref>]
	 * : Base ref when creating a branch on add (default origin/HEAD).
	 *
	 * [--base=<ref>]
	 * : Alias for `--from=<ref>`.
	 *
	 * [--base-ref=<ref>]
	 * : Alias for `--from=<ref>`.
	 *
	 * [--base-branch=<branch>]
	 * : Convenience alias for branch-shaped bases. `--base-branch=main`
	 *   maps to `--from=origin/main`. Use `--from`, `--base`, or `--base-ref`
	 *   for exact refs.
	 *
	 * [--skip-context-injection]
	 * : Skip injecting the originating site's agent context into a new
	 *   worktree (applies to `add` only). Default behavior is to make the site's
	 *   composed AGENTS.md visible to OpenCode: symlink it into the worktree root
	 *   when no repo-owned AGENTS.md exists, otherwise add it via local OpenCode
	 *   instructions so both files load. Also writes `.claude/CLAUDE.local.md`
	 *   containing the site's MEMORY.md / USER.md / RULES.md snapshot, and adds
	 *   injected paths to the repository's `info/exclude`. The ability-level input
	 *   is `inject_context=false`; this flag is the CLI shorthand.
	 *
	 * [--skip-bootstrap]
	 * : Skip the default bootstrap pass (applies to `add` only). By default,
	 *   `worktree add` runs detected setup so the checkout is immediately
	 *   test/build-ready:
	 *     - git submodule update --init --recursive (if .gitmodules)
	 *     - pnpm/bun/yarn/npm install (based on root or one-level nested lockfiles)
	 *     - composer install --no-interaction (based on root or one-level nested composer.lock)
	 *   Each step is skipped gracefully when its trigger file or tool is
	 *   missing. Pass `--skip-bootstrap` to create a bare checkout for pure
	 *   read use (faster, no deps installed). The ability-level input is
	 *   `bootstrap=false`; this flag is the CLI shorthand (matches the
	 *   existing `--skip-context-injection` convention).
	 *
	 * [--allow-stale]
	 * : Bypass the staleness gate (applies to `add` only). By default,
	 *   `worktree add` refuses any branch/base that is behind the remote
	 *   default branch after fetch, and refuses to return a worktree that
	 *   would be more than
	 *   `datamachine_worktree_stale_threshold` commits (default 50) behind
	 *   upstream — the stale checkout is torn down and a `worktree_stale`
	 *   error is returned with remediation options. Pass `--allow-stale` to
	 *   opt in to a known-stale checkout. Default-branch freshness is
	 *   zero-tolerance: one missing default-branch commit is stale. The
	 *   ability-level input is
	 *   `allow_stale=true`.
	 *
	 * [--rebase-base]
	 * : After creating the worktree, rebase onto the upstream tip (applies
	 *   to `add` only). For existing branches this is `@{upstream}`; for
	 *   new branches cut off a local base this is `origin/<base>`. On
	 *   rebase conflicts the rebase is aborted and the worktree stays at
	 *   its pre-rebase state — `--rebase-base` is not a silent
	 *   `--allow-stale` bypass. The ability-level input is
	 *   `rebase_base=true`.
	 *
	 * [--task-url=<url>]
	 * : Optional task/issue URL recorded on the worktree at creation time
	 *   for ownership/duplicate detection (applies to `add` only). Falls
	 *   back to the `DATAMACHINE_TASK_URL` environment variable when
	 *   omitted. Used by `worktree list` and the hygiene report to flag
	 *   multiple worktrees pointing at the same task.
	 *
	 * [--task-ref=<ref>]
	 * : Optional short task/issue reference (e.g. `org/repo#123`) recorded
	 *   alongside `--task-url` (applies to `add` only). Falls back to the
	 *   `DATAMACHINE_TASK_REF` environment variable when omitted.
	 *
	 * [--force]
	 * : For `add`, explicitly bypass the disk-budget refusal threshold. For
	 *   `remove`, force-remove a worktree even if it is dirty. For `cleanup`,
	 *   force dirty-worktree removal but not the unpushed-commits safety.
	 *
	 * [--pr=<url-or-number>]
	 * : Attach pull request metadata when finalizing or marking a worktree
	 *   cleanup-eligible.
	 *
	 * [--state=<state>]
	 * : Lifecycle state to record when finalizing a worktree.
	 *
	 * [--dry-run]
	 * : Preview cleanup candidates without removing anything (cleanup and locks only).
	 *
	 * [--prune-stale]
	 * : For `locks`, prune expired DB lock rows and old unlocked filesystem lock
	 *   files. Active filesystem flocks are reported but never removed.
	 *
	 * [--apply-plan=<file>]
	 * : Low-level escape hatch for applying a previously reviewed JSON report.
	 *   Daily cleanup should use `workspace cleanup plan`, then
	 *   `workspace cleanup apply <run-id>`.
	 *   The destructive pass still revalidates every planned row and removes only
	 *   exact current matches. For reconcile-metadata, applies a reviewed
	 *   non-destructive metadata plan.
	 *
	 * [--apply]
	 * : For `reconcile-metadata`, apply DMC-owned bounded metadata reconciliation
	 *   directly without a JSON plan file. For cleanup subcommands, use the
	 *   operation-specific apply path instead.
	 *
	 * [--via-jobs]
	 * : For `reconcile-metadata --apply`, schedule bounded metadata
	 *   reconciliation pages as jobs instead of applying synchronously.
	 *
	 * [--skip-github]
	 * : Skip the GitHub API lookup and rely only on the local `upstream-gone`
	 *   signal (cleanup only). Faster, but misses merged branches where the
	 *   remote branch wasn't auto-deleted.
	 *
	 * [--inventory-only]
	 * : Build a dry-run review from cheap top-level inventory and explicit
	 *   lifecycle cleanup signals only (cleanup only). Avoids full git worktree
	 *   scans, per-worktree status checks, and GitHub lookups.
	 *
	 * [--include-repaired-metadata]
	 * : With cleanup inventory or bounded-cleanup-eligible-apply, explicitly
	 *   include repaired metadata rows as operator-approved cleanup candidates.
	 *   Apply still runs fresh dirty/unpushed/containment/primary safety probes.
	 *
	 * [--discard-unpushed]
	 * : With bounded-cleanup-eligible-apply only, explicitly discard unpushed
	 *   commits after reviewed cleanup eligibility and fresh safety probes. This
	 *   is a data-loss mode and is not implied by --force.
	 *
	 * [--older-than=<duration>]
	 * : Limit cleanup candidates to worktrees with lifecycle `created_at`
	 *   metadata older than the compact duration (cleanup only, e.g. 7d, 24h).
	 *   Candidate worktrees without valid `created_at` metadata are skipped.
	 *
	 * [--remove-timeout=<seconds>]
	 * : Timeout for destructive `git worktree remove` during cleanup apply.
	 *   Defaults to a larger removal-specific budget than cheap git probes.
		 *
	 * [--sort=<field>]
	 * : Sort cleanup candidates by reporting field. For artifact cleanup,
	 *   `--sort=size` scans the cheap inventory once and returns the largest
	 *   artifact opportunities without manual pagination.
	 * ---
	 * options:
	 *   - size
	 *   - age
	 * ---
	 *
	 * [--stale]
	 * : For list, show only worktrees with a stale_reason (old, dirty, or missing metadata).
	 *   Implies `--with-status` (dirty detection requires running `git status`).
	 *
	 * [--with-status]
	 * : For list, run `git status --porcelain` per worktree to populate the dirty count.
	 *   Off by default — expensive on large workspaces (one fork+exec per worktree).
	 *
	 * [--with-size]
	 * : For list, run size and artifact `du` probes per worktree. Off by default —
	 *   expensive on large workspaces (multiple fork+exec calls per worktree).
	 *
	 * [--full]
	 * : For list, shorthand for `--with-status --with-size`. Restores the pre-0.27
	 *   behavior where every worktree is fully probed; only practical for small workspaces.
	 *
	 * [--verbose]
	 * : Show every cleanup row instead of concise samples (cleanup only).
	 *
	 * [--only=<section>]
	 * : Limit cleanup rows to one section or reason code. Supported values:
	 *   candidates, would-remove, would_remove, removed, no_merge_signal, dirty_worktree,
	 *   merged_pr_with_only_obsolete_dirty_changes (alias safe-force-cleanup),
	 *   unpushed_commits, missing_metadata, external_worktree, age_filter, unknown_age.
	 *
	 * [--limit=<count>]
	 * : For `cleanup --dry-run`, `cleanup-artifacts --dry-run`,
	 *   `abandoned`, `reconcile-metadata`, and `active-no-signal-report`,
	 *   positive maximum worktrees to scan in this page. Artifact cleanup defaults
	 *   to 100; metadata reconciliation uses this only when pagination is
	 *   requested. Use `--exhaustive` instead of `--limit=0` for a full artifact
	 *   audit.
	 *
	 * [--passes=<count>]
	 * : For `abandoned`, maximum apply passes to run after marking eligible rows.
	 *   Preview mode always runs a single non-destructive classification pass.
	 *
	 * [--stage=<stage>]
	 * : For `abandoned`, resume from a specific orchestration stage. Supported
	 *   values: reconcile, finalized, equivalent-clean, merged, remote-clean, bounded.
	 *
	 * [--offset=<count>]
	 * : For `cleanup --dry-run`, `cleanup-artifacts --dry-run`,
	 *   `abandoned`, `reconcile-metadata`, and `active-no-signal-report`,
	 *   pagination offset (0-indexed) into the inventory ordering. Walk pages by
	 *   passing the previous response's `pagination.next_offset`.
	 *
	 * [--until-budget=<duration>]
	 * : For `cleanup --dry-run` and `reconcile-metadata`, enforce a compact
	 *   wall-clock budget for dry-run pages or direct-apply drains (e.g. 60s,
	 *   10m). Also supported by `active-no-signal-report` and the active/no-signal
	 *   apply flows. Returns continuation
	 *   evidence and a next command when more rows remain.
	 *
	 * [--exhaustive]
	 * : For `cleanup-artifacts --dry-run`, scan every worktree AND run per-worktree
	 *   git safety probes. This is the explicit unbounded artifact audit mode;
	 *   slow on huge workspaces, so use sparingly.
	 *
	 * [--safety-probes]
	 * : For `cleanup-artifacts --dry-run`, run per-worktree git safety probes
	 *   without disabling the bounded scan. Useful for auditing a small slice
	 *   with full safety information.
	 *
	 * [--pr=<url-or-number>]
	 * : Pull request URL or number for `finalize` or `mark-cleanup-eligible`
	 *   metadata. Supplying `--pr` to `finalize` defaults the requested finalizer
	 *   state to `pr_opened` and records the worktree as cleanup-eligible.
	 *
	 * [--state=<state>]
	 * : Requested lifecycle state for `finalize`. Defaults to `pr_opened` when
	 *   `--pr` is supplied, otherwise `active`. PR/completed states are safely
	 *   transitioned to cleanup eligibility metadata.
	 *
	 * [--format=<format>]
	 * : Output format for list and cleanup (table, json, csv, yaml).
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a worktree for fix/foo on data-machine
	 *     wp datamachine-code workspace worktree add data-machine fix/foo
	 *
	 *     # Create off a specific base
	 *     wp datamachine-code workspace worktree add data-machine feat/bar --from=origin/develop
	 *     wp datamachine-code workspace worktree add data-machine feat/bar --base-branch=develop
	 *
	 *     # List all worktrees (cheap inventory: no per-worktree git status / du probes)
	 *     wp datamachine-code workspace worktree list
	 *
	 *     # List worktrees for one repo
	 *     wp datamachine-code workspace worktree list data-machine
	 *
	 *     # Cheap JSON inventory for huge workspaces (~800 worktrees) — completes fast
	 *     wp datamachine-code workspace worktree list --format=json
	 *
	 *     # Restore the pre-0.27 full probe behavior (slow on huge workspaces)
	 *     wp datamachine-code workspace worktree list --full
	 *
	 *     # Just dirty detection, skip size scan
	 *     wp datamachine-code workspace worktree list --with-status
	 *
	 *     # Remove a worktree
	 *     wp datamachine-code workspace worktree remove data-machine fix/foo
	 *
	 *     # Force-remove a dirty worktree
	 *     wp datamachine-code workspace worktree remove data-machine fix/foo --force
	 *
	 *     # Prune stale worktree registry entries across all primaries
	 *     wp datamachine-code workspace worktree prune
	 *
	 *     # Inspect and safely prune stale workspace mutation locks
	 *     wp datamachine-code workspace worktree locks --format=json
	 *     wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json
	 *     wp datamachine-code workspace worktree locks --prune-stale --format=json
	 *
	 *     # Preview worktrees that would be removed (upstream gone or PR merged)
	 *     wp datamachine-code workspace worktree cleanup --dry-run
	 *
	 *     # Remove all merged worktrees
	 *     wp datamachine-code workspace worktree cleanup
	 *
	 *     # Daily cleanup path: DB-backed plan, then apply only those rows after revalidation
	 *     wp datamachine-code workspace cleanup plan --mode=retention
	 *     wp datamachine-code workspace cleanup apply cleanup-run-20260504120000-abc123
	 *
	 *     # Task-backed cleanup path, then inspect status/evidence by run_id
	 *     wp datamachine-code workspace cleanup run --mode=retention
	 *     wp datamachine-code workspace cleanup status cleanup-run-123
	 *     wp datamachine-code workspace cleanup evidence cleanup-run-123 --format=json
	 *
	 *     # Low-level JSON previews are for diagnostics
	 *     wp datamachine-code workspace worktree cleanup --dry-run --format=json
	 *     wp datamachine-code workspace worktree cleanup-artifacts --dry-run --format=json
	 *     wp datamachine-code workspace worktree emergency-cleanup --format=json
	 *
	 *     # Bounded apply restricted to explicit lifecycle cleanup_eligible worktrees
	 *     # (cheap inventory only — no full git worktree scan, no GitHub lookup)
	 *     wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --limit=25
	 *     wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --limit=25
	 *     wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --discard-unpushed --limit=25
	 *     wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --via-jobs --limit=10 --older-than=7d
	 *     wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --dry-run --include-repaired-metadata --older-than=7d --limit=25
	 *     wp datamachine-code workspace worktree bounded-cleanup-eligible-apply --include-repaired-metadata --older-than=7d --limit=25
	 *
	 *     # Local-only detection (no GitHub API call)
	 *     wp datamachine-code workspace worktree cleanup --skip-github
	 *
	 *     # Cheap inventory review on huge workspaces (no per-worktree git probes)
	 *     wp datamachine-code workspace worktree cleanup --dry-run --inventory-only --skip-github --format=json
	 *
	 *     # One operator pass for abandoned worktrees: reconcile, mark safe rows, remove eligible rows, and report blockers
	 *     wp datamachine-code workspace worktree abandoned --format=json
	 *     wp datamachine-code workspace worktree abandoned --apply --force --limit=100 --passes=5 --until-budget=120s --format=json
	 *
	 *     # Adopt/reconcile unmanaged worktree metadata before cleanup
	 *     wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json
	 *     wp datamachine-code workspace worktree reconcile-metadata --apply --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree reconcile-metadata --apply --limit=50 --until-budget=60s --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-report --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-finalized-apply --dry-run --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-finalized-apply --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-equivalent-clean-apply --dry-run --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-equivalent-clean-apply --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-merged-apply --dry-run --limit=25 --offset=0 --format=json
	 *     wp datamachine-code workspace worktree active-no-signal-merged-apply --limit=25 --offset=0 --format=json
	 *
	 *     # Ignore dirty working-tree safety (caution)
	 *     wp datamachine-code workspace worktree cleanup --force
	 *
	 *     # Create a worktree without injecting site-agent context
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --skip-context-injection
	 *
	 *     # Create a bare worktree (skip the default bootstrap pass)
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --skip-bootstrap
	 *
	 *     # Proceed with a known-stale base/branch (bypass the staleness gate)
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --allow-stale
	 *
	 *     # Auto-rebase onto upstream after creation
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --rebase-base
	 *
	 *     # Re-read the originating site's agent memory into an existing worktree
	 *     wp datamachine-code workspace worktree refresh-context data-machine@fix-foo
	 *
	 *     # Attach PR/finalizer metadata to a worktree
	 *     wp datamachine-code workspace worktree finalize data-machine@fix-foo --pr=https://github.com/org/repo/pull/123
	 *     wp datamachine-code workspace worktree mark-cleanup-eligible data-machine@fix-foo
	 *
	 *     # Record a task/issue URL on a worktree for ownership tracking
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --task-url=https://github.com/org/repo/issues/42
	 *
	 * @subcommand worktree
	 */
	public function worktree( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';

		if ( '' === $operation ) {
			WP_CLI::error('Usage: wp datamachine-code workspace worktree <add|list|remove|prune|locks|cleanup|cleanup-artifacts|abandoned|bounded-cleanup-eligible-apply|emergency-cleanup|reconcile-metadata|backfill-origin-session|active-no-signal-report|active-no-signal-finalized-apply|active-no-signal-equivalent-clean-apply|active-no-signal-merged-apply|active-no-signal-remote-clean-apply|refresh-context|finalize|mark-cleanup-eligible> [<repo>] [<branch>] [--flags]');
			return;
		}

		if ( 'abandoned' === $operation ) {
			$result = $this->run_worktree_abandoned_orchestration($assoc_args);
			if ( is_wp_error($result) ) {
				$this->render_workspace_error($result);
				return;
			}
			$this->render_worktree_abandoned_result($result, $assoc_args);
			return;
		}

		if ( 'locks' === $operation ) {
			$workspace      = new Workspace();
			$workspace_path = $workspace->get_path();
			$dry_run        = ! empty($assoc_args['dry-run']) || empty($assoc_args['prune-stale']);
			$result         = ! empty($assoc_args['prune-stale'])
				? WorkspaceMutationLock::prune_stale($workspace_path, $dry_run)
				: WorkspaceMutationLock::status($workspace_path);
			$this->render_workspace_lock_result($result, $assoc_args, ! empty($assoc_args['prune-stale']));
			return;
		}

		if ( 'backfill-origin-session' === $operation ) {
			$result = WorktreeContextInjector::backfill_legacy_origin_sessions( ! empty($assoc_args['apply']) );
			if ( 'json' === (string) ( $assoc_args['format'] ?? '' ) ) {
				WP_CLI::line( (string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) );
				return;
			}

			$items = array(
				array(
					'field' => 'Applied',
					'value' => ! empty($result['applied']) ? 'yes' : 'no',
				),
				array(
					'field' => 'Planned',
					'value' => (string) ( $result['planned_count'] ?? 0 ),
				),
				array(
					'field' => 'Migrated option',
					'value' => ! empty($result['migrated']) ? 'yes' : 'no',
				),
				array(
					'field' => 'Message',
					'value' => (string) ( $result['message'] ?? '' ),
				),
			);
			$this->format_items($items, array( 'field', 'value' ), $assoc_args);
			if ( ! empty($result['applied']) ) {
				WP_CLI::success('Backfilled legacy origin_session metadata.');
			} else {
				WP_CLI::log('Dry run complete; pass --apply to rewrite metadata.');
			}
			return;
		}

		$ability_name = match ( $operation ) {
			'add'                            => 'datamachine-code/workspace-worktree-add',
			'list'                           => 'datamachine-code/workspace-worktree-list',
			'remove'                         => 'datamachine-code/workspace-worktree-remove',
			'prune'                          => 'datamachine-code/workspace-worktree-prune',
			'cleanup'                        => 'datamachine-code/workspace-worktree-cleanup',
			'cleanup-artifacts'              => 'datamachine-code/workspace-worktree-cleanup-artifacts',
			'bounded-cleanup-eligible-apply' => 'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply',
			'emergency-cleanup'              => 'datamachine-code/workspace-worktree-emergency-cleanup',
			'reconcile-metadata'             => 'datamachine-code/workspace-worktree-reconcile-metadata',
			'active-no-signal-report'        => 'datamachine-code/workspace-worktree-active-no-signal-report',
			'active-no-signal-finalized-apply' => 'datamachine-code/workspace-worktree-active-no-signal-finalized-apply',
			'active-no-signal-equivalent-clean-apply' => 'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply',
			'active-no-signal-merged-apply'   => 'datamachine-code/workspace-worktree-active-no-signal-merged-apply',
			'active-no-signal-remote-clean-apply' => 'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply',
			'refresh-context'                => 'datamachine-code/workspace-worktree-refresh-context',
			'finalize'                       => 'datamachine-code/workspace-worktree-finalize',
			'mark-cleanup-eligible'          => 'datamachine-code/workspace-worktree-finalize',
			default                          => '',
		};

		if ( '' === $ability_name ) {
			WP_CLI::error(sprintf('Unknown worktree operation: %s', $operation));
			return;
		}

		$ability = wp_get_ability($ability_name);
		if ( ! $ability ) {
			WP_CLI::error(sprintf('Worktree ability not available: %s', $ability_name));
			return;
		}

		$input = array();

		switch ( $operation ) {
			case 'add':
				if ( empty($args[1]) || empty($args[2]) ) {
					WP_CLI::error('Usage: worktree add <repo> <branch> [--from=<ref>|--base=<ref>|--base-ref=<ref>|--base-branch=<branch>] [--skip-context-injection] [--skip-bootstrap] [--allow-stale] [--rebase-base] [--force]');
					return;
				}
				$input['repo']    = $args[1];
				$input['branch']  = $args[2];
				$exact_base       = $assoc_args['from'] ?? $assoc_args['base'] ?? $assoc_args['base-ref'] ?? '';
				$exact_base_flags = array_filter(
					array(
						'from'     => $assoc_args['from'] ?? '',
						'base'     => $assoc_args['base'] ?? '',
						'base-ref' => $assoc_args['base-ref'] ?? '',
					)
				);
				if ( count($exact_base_flags) > 1 ) {
					WP_CLI::error('Use only one of --from=<ref>, --base=<ref>, or --base-ref=<ref>.');
					return;
				}
				if ( ! empty($exact_base) && ! empty($assoc_args['base-branch']) ) {
					WP_CLI::error('Use either --from=<ref>/--base=<ref>/--base-ref=<ref> or --base-branch=<branch>, not both.');
					return;
				}
				if ( ! empty($exact_base) ) {
					$input['from'] = (string) $exact_base;
				} elseif ( ! empty($assoc_args['base-branch']) ) {
					$input['from'] = self::base_branch_to_ref( (string) $assoc_args['base-branch']);
				}
				// --skip-context-injection disables the default-on injection step.
				$input['inject_context'] = empty($assoc_args['skip-context-injection']);
				// --skip-bootstrap disables the default-on bootstrap step.
				$input['bootstrap'] = empty($assoc_args['skip-bootstrap']);
				// --allow-stale opts in to a known-stale worktree (default: gate enforced).
				$input['allow_stale'] = ! empty($assoc_args['allow-stale']);
				// --rebase-base auto-rebases onto upstream after creation (default: off).
				$input['rebase_base'] = ! empty($assoc_args['rebase-base']);
				// --force is an explicit disk-budget override for add.
				$input['force'] = ! empty($assoc_args['force']);
				if ( isset($assoc_args['task-url']) && '' !== trim( (string) $assoc_args['task-url']) ) {
					$input['task_url'] = (string) $assoc_args['task-url'];
				}
				if ( isset($assoc_args['task-ref']) && '' !== trim( (string) $assoc_args['task-ref']) ) {
					$input['task_ref'] = (string) $assoc_args['task-ref'];
				}
				break;

			case 'refresh-context':
				if ( empty($args[1]) ) {
					WP_CLI::error('Usage: worktree refresh-context <handle>');
					return;
				}
				$input['handle'] = (string) $args[1];
				break;

			case 'finalize':
				if ( empty($args[1]) ) {
					WP_CLI::error('Usage: worktree finalize <handle> [--pr=<url-or-number>] [--state=<state>]');
					return;
				}
				$input['handle'] = (string) $args[1];
				$input['state']  = isset($assoc_args['state']) && '' !== trim( (string) $assoc_args['state']) ? (string) $assoc_args['state'] : ( isset($assoc_args['pr']) ? 'pr_opened' : 'active' );
				if ( isset($assoc_args['pr']) && '' !== trim( (string) $assoc_args['pr']) ) {
					$input['pr'] = (string) $assoc_args['pr'];
				}
				break;

			case 'mark-cleanup-eligible':
				if ( empty($args[1]) ) {
					WP_CLI::error('Usage: worktree mark-cleanup-eligible <handle> [--pr=<url-or-number>]');
					return;
				}
				$input['handle'] = (string) $args[1];
				$input['state']  = 'cleanup_eligible';
				if ( isset($assoc_args['pr']) && '' !== trim( (string) $assoc_args['pr']) ) {
					$input['pr'] = (string) $assoc_args['pr'];
				}
				break;

			case 'list':
				if ( ! empty($args[1]) ) {
					$input['repo'] = $args[1];
				}
				if ( isset($assoc_args['state']) && '' !== trim( (string) $assoc_args['state']) ) {
					$input['state'] = (string) $assoc_args['state'];
				}
				// Cheap inventory by default — opt in to expensive probes via flags.
				// `--full` is a shorthand for both, `--stale` requires status to detect dirty.
				$want_status             = ! empty($assoc_args['with-status'])
				|| ! empty($assoc_args['full'])
				|| ! empty($assoc_args['stale']);
				$want_disk               = ! empty($assoc_args['with-size'])
				|| ! empty($assoc_args['full']);
				$input['include_status'] = $want_status;
				$input['include_disk']   = $want_disk;
				break;

			case 'remove':
				if ( empty($args[1]) || empty($args[2]) ) {
					WP_CLI::error('Usage: worktree remove <repo> <branch> [--force]');
					return;
				}
				$input['repo']   = $args[1];
				$input['branch'] = $args[2];
				$input['force']  = ! empty($assoc_args['force']);
				break;

			case 'cleanup':
				$input['dry_run']                   = ! empty($assoc_args['dry-run']);
				$input['force']                     = ! empty($assoc_args['force']);
				$input['skip_github']               = ! empty($assoc_args['skip-github']);
				$input['inventory_only']            = ! empty($assoc_args['inventory-only']);
				$input['include_repaired_metadata'] = ! empty($assoc_args['include-repaired-metadata']);
				if ( isset($assoc_args['limit']) ) {
					$input['limit'] = (int) $assoc_args['limit'];
				}
				if ( isset($assoc_args['offset']) ) {
					$input['offset'] = (int) $assoc_args['offset'];
				}
				if ( isset($assoc_args['until-budget']) && '' !== trim( (string) $assoc_args['until-budget']) ) {
					$input['until_budget'] = trim( (string) $assoc_args['until-budget']);
				}
				if ( ! in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml' ), true) ) {
					$input['progress_callback'] = function ( array $event ): void {
						$this->render_worktree_cleanup_progress($event);
					};
				}
				if ( ! empty($assoc_args['apply-plan']) ) {
					if ( ! empty($assoc_args['force']) ) {
						WP_CLI::error('Do not combine --apply-plan with --force. Plan application always revalidates and refuses dirty worktrees.');
						return;
					}
					$input['apply_plan'] = $this->read_worktree_cleanup_plan( (string) $assoc_args['apply-plan']);
				}
				if ( isset($assoc_args['older-than']) && '' !== trim( (string) $assoc_args['older-than']) ) {
					$input['older_than'] = trim( (string) $assoc_args['older-than']);
				}
				if ( isset($assoc_args['sort']) && '' !== trim( (string) $assoc_args['sort']) ) {
					$input['sort'] = trim( (string) $assoc_args['sort']);
				}
				break;
			case 'reconcile-metadata':
				$input['dry_run']  = ! empty($assoc_args['dry-run']);
				$input['apply']    = ! empty($assoc_args['apply']);
				$input['via_jobs'] = ! empty($assoc_args['via-jobs']);
				$input['source']   = self::CLEANUP_CLI_SOURCE;
				$uses_plan         = ! empty($assoc_args['apply-plan']);
				$has_bounds        = isset($assoc_args['limit']) || isset($assoc_args['offset']) || ( isset($assoc_args['until-budget']) && '' !== trim( (string) $assoc_args['until-budget']) );
				if ( ! $uses_plan && ! $has_bounds && ( $input['dry_run'] || $input['apply'] ) ) {
					$input['limit']        = self::METADATA_RECONCILE_DEFAULT_LIMIT;
					$input['offset']       = 0;
					$input['until_budget'] = self::METADATA_RECONCILE_DEFAULT_BUDGET;
				}
				if ( isset($assoc_args['limit']) ) {
					$input['limit'] = (int) $assoc_args['limit'];
				}
				if ( isset($assoc_args['offset']) ) {
					$input['offset'] = (int) $assoc_args['offset'];
				}
				if ( isset($assoc_args['until-budget']) && '' !== trim( (string) $assoc_args['until-budget']) ) {
					$input['until_budget'] = trim( (string) $assoc_args['until-budget']);
				}
				if ( $uses_plan ) {
					$input['apply_plan'] = $this->read_worktree_json_plan( (string) $assoc_args['apply-plan'], 'metadata reconciliation');
				}
				break;

			case 'active-no-signal-report':
			case 'active-no-signal-finalized-apply':
			case 'active-no-signal-equivalent-clean-apply':
			case 'active-no-signal-merged-apply':
			case 'active-no-signal-remote-clean-apply':
				if ( in_array($operation, array( 'active-no-signal-finalized-apply', 'active-no-signal-equivalent-clean-apply', 'active-no-signal-merged-apply', 'active-no-signal-remote-clean-apply' ), true) ) {
					$input['dry_run'] = ! empty($assoc_args['dry-run']);
				}
				if ( isset($assoc_args['limit']) ) {
					$input['limit'] = (int) $assoc_args['limit'];
				}
				if ( isset($assoc_args['offset']) ) {
					$input['offset'] = (int) $assoc_args['offset'];
				}
				if ( isset($assoc_args['until-budget']) && '' !== trim( (string) $assoc_args['until-budget']) ) {
					$input['until_budget'] = trim( (string) $assoc_args['until-budget']);
				}
				break;

			case 'cleanup-artifacts':
				$input['dry_run'] = ! empty($assoc_args['dry-run']);
				$input['force']   = ! empty($assoc_args['force']);
				if ( isset($assoc_args['limit']) ) {
					$input['limit'] = (int) $assoc_args['limit'];
				}
				if ( isset($assoc_args['offset']) ) {
					$input['offset'] = (int) $assoc_args['offset'];
				}
				if ( ! empty($assoc_args['exhaustive']) ) {
					$input['exhaustive'] = true;
				}
				if ( ! empty($assoc_args['safety-probes']) ) {
					$input['safety_probes'] = true;
				}
				if ( isset($assoc_args['sort']) && '' !== trim( (string) $assoc_args['sort']) ) {
					$input['sort'] = trim( (string) $assoc_args['sort']);
				}
				if ( ! empty($assoc_args['apply-plan']) ) {
					$input['apply_plan'] = $this->read_worktree_cleanup_plan( (string) $assoc_args['apply-plan']);
				}
				break;

			case 'emergency-cleanup':
				$input['dry_run'] = true;
				$input['force']   = ! empty($assoc_args['force']);
				if ( ! empty($assoc_args['apply-plan']) ) {
					$input['dry_run']    = false;
					$input['apply_plan'] = $this->read_worktree_json_plan( (string) $assoc_args['apply-plan'], 'emergency cleanup');
				}
				break;

			case 'bounded-cleanup-eligible-apply':
				$input['dry_run']                   = ! empty($assoc_args['dry-run']);
				$input['force']                     = ! empty($assoc_args['force']);
				$input['discard_unpushed']          = ! empty($assoc_args['discard-unpushed']);
				$input['via_jobs']                  = ! empty($assoc_args['via-jobs']);
				$input['include_repaired_metadata'] = ! empty($assoc_args['include-repaired-metadata']);
				$input['source']                    = self::CLEANUP_CLI_SOURCE;
				if ( isset($assoc_args['limit']) ) {
					$input['limit'] = (int) $assoc_args['limit'];
				}
				if ( isset($assoc_args['older-than']) && '' !== trim( (string) $assoc_args['older-than']) ) {
					$input['older_than'] = trim( (string) $assoc_args['older-than']);
				}
				if ( isset($assoc_args['sort']) && '' !== trim( (string) $assoc_args['sort']) ) {
					$input['sort'] = trim( (string) $assoc_args['sort']);
				}
				if ( isset($assoc_args['remove-timeout']) && '' !== trim( (string) $assoc_args['remove-timeout']) ) {
					$input['remove_timeout'] = (int) $assoc_args['remove-timeout'];
				}
				break;
		}

		$result = $ability->execute($input);

		if ( is_wp_error($result) ) {
			$this->render_workspace_error($result);
			return;
		}

		$this->renderWorktreeResult($operation, $result, $assoc_args);
	}

	/**
	 * Run the operator-facing abandoned-worktree cleanup workflow.
	 *
	 * This intentionally composes existing reviewed abilities instead of adding a
	 * second cleanup classifier. The destructive step is gated behind --apply; the
	 * default mode reports what would be marked/removed and which rows remain
	 * blocked by dirty work, unpushed commits, missing primaries, or weak signals.
	 *
	 * @param  array<string,mixed> $assoc_args CLI args.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_worktree_abandoned_orchestration( array $assoc_args ): array|\WP_Error {
		$apply        = ! empty($assoc_args['apply']);
		$force        = ! empty($assoc_args['force']);
		$limit        = isset($assoc_args['limit']) ? max(1, min(1000, (int) $assoc_args['limit'])) : 100;
		$passes       = isset($assoc_args['passes']) ? max(1, min(25, (int) $assoc_args['passes'])) : 5;
		$offset       = isset($assoc_args['offset']) ? max(0, (int) $assoc_args['offset']) : 0;
		$stage        = isset($assoc_args['stage']) ? strtolower( (string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $assoc_args['stage']) ) : 'reconcile';
		$stage        = str_replace('_', '-', $stage);
		$until_budget = isset($assoc_args['until-budget']) && '' !== trim( (string) $assoc_args['until-budget']) ? trim( (string) $assoc_args['until-budget']) : '';
		$deadline     = null;
		$stage_order  = array(
			'reconcile'        => 0,
			'finalized'        => 1,
			'equivalent-clean' => 2,
			'merged'           => 3,
			'remote-clean'     => 4,
			'bounded'          => 5,
		);
		if ( ! isset($stage_order[ $stage ]) ) {
			return new \WP_Error('invalid_worktree_abandoned_stage', 'Invalid --stage value. Use reconcile, finalized, equivalent-clean, merged, remote-clean, or bounded.', array( 'status' => 400 ));
		}
		if ( '' !== $until_budget ) {
			$budget_seconds = $this->parse_worktree_abandoned_budget($until_budget);
			if ( is_wp_error($budget_seconds) ) {
				return $budget_seconds;
			}
			$deadline = microtime(true) + $budget_seconds;
		}

		$required = array(
			'reconcile_metadata' => 'datamachine-code/workspace-worktree-reconcile-metadata',
			'finalized'          => 'datamachine-code/workspace-worktree-active-no-signal-finalized-apply',
			'equivalent_clean'   => 'datamachine-code/workspace-worktree-active-no-signal-equivalent-clean-apply',
			'merged'             => 'datamachine-code/workspace-worktree-active-no-signal-merged-apply',
			'remote_clean'       => 'datamachine-code/workspace-worktree-active-no-signal-remote-clean-apply',
			'bounded_apply'      => 'datamachine-code/workspace-worktree-bounded-cleanup-eligible-apply',
			'prune'              => 'datamachine-code/workspace-worktree-prune',
		);

		$abilities = array();
		foreach ( $required as $key => $ability_name ) {
			$ability = wp_get_ability($ability_name);
			if ( ! $ability ) {
				return new \WP_Error('worktree_abandoned_ability_missing', sprintf('Worktree abandoned cleanup ability not available: %s', $ability_name), array( 'status' => 500 ));
			}
			$abilities[ $key ] = $ability;
		}

		$started_at = microtime(true);
		$result     = array(
			'success'         => true,
			'mode'            => 'abandoned_worktree_cleanup',
			'applied'         => $apply,
			'destructive'     => $apply,
			'force'           => $force,
			'limit'           => $limit,
			'stage'           => $stage,
			'offset'          => $offset,
			'passes'          => $passes,
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
				'safety' => $apply
					? 'Applies only rows proven by existing DMC cleanup abilities; unpushed commits remain protected even with --force.'
					: 'Preview only. Re-run with --apply to write cleanup metadata and remove eligible worktrees.',
			),
		);

		$common_page = array(
			'limit'  => $limit,
			'source' => self::CLEANUP_CLI_SOURCE,
		);

		if ( $apply ) {
			$bounded = $this->run_worktree_abandoned_bounded_apply($abilities['bounded_apply'], $result, true, $force, $limit, 'initial');
			if ( is_wp_error($bounded) ) {
				return $bounded;
			}

			if ( 'bounded' === $stage ) {
				$prune = $abilities['prune']->execute(array());
				if ( is_wp_error($prune) ) {
					return $prune;
				}
				$result['steps']['prune'] = $this->summarize_worktree_abandoned_step($prune);

				return $this->finalize_worktree_abandoned_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at);
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
			$reconcile       = $this->drain_worktree_abandoned_pages($abilities['reconcile_metadata'], $reconcile_input, $apply, $deadline);
			if ( is_wp_error($reconcile) ) {
				return $reconcile;
			}
			$result['steps']['reconcile_metadata'] = $this->summarize_worktree_abandoned_step($reconcile);
			$result['summary']['scanned']         += (int) ( $result['steps']['reconcile_metadata']['inspected'] ?? 0 );
			$result['summary']['reconciled']       = (int) ( $reconcile['summary']['written'] ?? 0 );
			$result['summary']['would_reconcile']  = (int) ( $reconcile['summary']['proposed'] ?? 0 );

			if ( $this->worktree_abandoned_stage_incomplete($reconcile) ) {
				$bounded = $this->run_worktree_abandoned_bounded_apply($abilities['bounded_apply'], $result, $apply, $force, $limit, 'reconcile');
				if ( is_wp_error($bounded) ) {
					return $bounded;
				}
				$result['evidence']['budget_exhausted'] = $this->worktree_abandoned_budget_expired($deadline);
				$result['continuation']                 = $this->build_worktree_abandoned_continuation('reconcile', $reconcile, $limit, $passes, $force, $until_budget);
				$result['next_commands'][]              = (string) $result['continuation']['next_command'];
				return $this->finalize_worktree_abandoned_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at);
			}
		}

		$mark_steps = array(
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

		$effective_passes = $apply ? $passes : 1;
		for ( $pass = 1; $pass <= $effective_passes; ++$pass ) {
			$result['executed_passes'] = $pass;
			$pass_marked               = 0;
			foreach ( $mark_steps as $key => $step_config ) {
				$step_stage = (string) $step_config['stage'];
				if ( $stage_order[ $step_stage ] < $stage_order[ $stage ] ) {
					continue;
				}

				if ( $this->worktree_abandoned_budget_expired($deadline) ) {
					$result['evidence']['budget_exhausted'] = true;
					break 2;
				}

				$step_input = array_merge(
					$common_page,
					array(
						'dry_run' => ! $apply,
						'offset'  => $step_stage === $stage ? $offset : 0,
					)
				);
				$step       = $this->drain_worktree_abandoned_pages($step_config['ability'], $step_input, $apply, $deadline);
				if ( is_wp_error($step) ) {
					return $step;
				}

				$step_key                                      = sprintf('%s_pass_%d', $key, $pass);
				$result['steps'][ $step_key ]                  = $this->summarize_worktree_abandoned_step($step);
				$result['summary']['scanned']                 += (int) ( $result['steps'][ $step_key ]['inspected'] ?? 0 );
				$written                                       = (int) ( $step['summary']['written'] ?? 0 );
				$planned                                       = (int) ( $step['summary']['planned'] ?? 0 );
				$pass_marked                                  += $apply ? $written : $planned;
				$result['summary']['marked_cleanup_eligible'] += $written;
				$result['summary']['would_mark_cleanup_eligible'] += $planned;

				if ( $this->worktree_abandoned_stage_incomplete($step) ) {
					$bounded = $this->run_worktree_abandoned_bounded_apply($abilities['bounded_apply'], $result, $apply, $force, $limit, $step_stage);
					if ( is_wp_error($bounded) ) {
						return $bounded;
					}
					$result['evidence']['budget_exhausted'] = $this->worktree_abandoned_budget_expired($deadline);
					$result['continuation']                 = $this->build_worktree_abandoned_continuation($step_stage, $step, $limit, $passes, $force, $until_budget);
					$result['next_commands'][]              = (string) $result['continuation']['next_command'];
					break 2;
				}
			}

			$bounded = $this->run_worktree_abandoned_bounded_apply($abilities['bounded_apply'], $result, $apply, $force, $limit, sprintf('pass_%d', $pass));
			if ( is_wp_error($bounded) ) {
				return $bounded;
			}

			$removed_or_would = (int) ( $bounded['summary']['removed'] ?? 0 ) + (int) ( $bounded['summary']['would_remove'] ?? 0 );
			if ( 0 === $pass_marked && 0 === $removed_or_would ) {
				break;
			}
		}

		if ( $apply ) {
			$prune = $abilities['prune']->execute(array());
			if ( is_wp_error($prune) ) {
				return $prune;
			}
			$result['steps']['prune'] = $this->summarize_worktree_abandoned_step($prune);
		} else {
			$result['steps']['prune'] = array(
				'mode'    => 'prune',
				'skipped' => true,
				'reason'  => 'preview mode does not prune git worktree metadata; re-run with --apply to prune after removal.',
			);
		}

		return $this->finalize_worktree_abandoned_result($result, $apply, $force, $limit, $passes, $until_budget, $started_at);
	}

	/**
	 * Finalize abandoned cleanup output.
	 *
	 * @param  array<string,mixed> $result       Partial result.
	 * @param  bool                $apply        Whether apply mode is active.
	 * @param  bool                $force        Whether force mode is active.
	 * @param  int                 $limit        Page size.
	 * @param  int                 $passes       Apply passes.
	 * @param  string              $until_budget Original budget argument.
	 * @param  float               $started_at   Start time.
	 * @return array<string,mixed>
	 */
	private function finalize_worktree_abandoned_result( array $result, bool $apply, bool $force, int $limit, int $passes, string $until_budget, float $started_at ): array {
		$result['blocked']            = array_values( (array) ( $result['blocked'] ?? array() ) );
		$result['summary']['blocked'] = count($result['blocked']);
		foreach ( $result['blocked'] as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$reason = (string) ( $row['reason_code'] ?? 'unknown' );

			$result['summary']['blocked_by_reason'][ $reason ] = (int) ( $result['summary']['blocked_by_reason'][ $reason ] ?? 0 ) + 1;
		}

		if ( empty($result['continuation']) && ! $apply ) {
			$result['next_commands'][] = sprintf('studio wp datamachine-code workspace worktree abandoned --apply%s --limit=%d --passes=%d%s --format=json', $force ? ' --force' : '', $limit, $passes, '' !== $until_budget ? ' --until-budget=' . $until_budget : '');
		}
		if ( empty($result['continuation']) && ! $force ) {
			$result['next_commands'][] = sprintf('studio wp datamachine-code workspace worktree abandoned --apply --force --limit=%d --passes=%d%s --format=json', $limit, $passes, '' !== $until_budget ? ' --until-budget=' . $until_budget : '');
		}

		$result['evidence']['elapsed_ms'] = (int) round(( microtime(true) - $started_at ) * 1000);

		return $result;
	}

	/**
	 * Determine whether a paged abandoned-cleanup stage still has remaining rows.
	 *
	 * @param  array<string,mixed> $step Stage result.
	 * @return bool
	 */
	private function worktree_abandoned_stage_incomplete( array $step ): bool {
		$pagination = (array) ( $step['pagination'] ?? $step['continuation'] ?? array() );
		if ( empty($pagination) || ! empty($pagination['complete']) || ! isset($pagination['next_offset']) ) {
			return false;
		}

		$next_offset = (int) $pagination['next_offset'];
		$current     = (int) ( $pagination['offset'] ?? 0 );
		$total       = isset($pagination['total']) ? (int) $pagination['total'] : null;
		if ( $next_offset === $current && ! empty($pagination['partial']) ) {
			return true;
		}
		if ( null !== $total && $next_offset >= $total ) {
			return false;
		}

		return $next_offset > $current;
	}

	/**
	 * Run bounded cleanup removal and merge its accounting into the abandoned result.
	 *
	 * @param  object              $ability    Bounded cleanup ability.
	 * @param  array<string,mixed> $result     Abandoned cleanup result accumulator.
	 * @param  bool                $apply      Whether apply mode is active.
	 * @param  bool                $force      Whether force mode is active.
	 * @param  int                 $limit      Removal page size.
	 * @param  string              $step_label Step label suffix.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_worktree_abandoned_bounded_apply( object $ability, array &$result, bool $apply, bool $force, int $limit, string $step_label ): array|\WP_Error {
		$execute = array( $ability, 'execute' );
		if ( ! is_callable($execute) ) {
			return new \WP_Error('worktree_abandoned_ability_invalid', 'Worktree abandoned cleanup ability is not executable.', array( 'status' => 500 ));
		}

		$bounded = $execute(
			array(
				'dry_run' => ! $apply,
				'force'   => $force,
				'limit'   => $limit,
				'source'  => self::CLEANUP_CLI_SOURCE,
			)
		);
		if ( is_wp_error($bounded) ) {
			return $bounded;
		}

		$result['steps'][ sprintf('bounded_apply_%s', $step_label) ] = $this->summarize_worktree_abandoned_step($bounded);

		$result['summary']['removed']         += (int) ( $bounded['summary']['removed'] ?? 0 );
		$result['summary']['would_remove']    += (int) ( $bounded['summary']['would_remove'] ?? 0 );
		$result['summary']['bytes_reclaimed'] += (int) ( $bounded['summary']['bytes_reclaimed'] ?? 0 );
		$result['summary']['scanned']         += (int) ( $result['steps'][ sprintf('bounded_apply_%s', $step_label) ]['inspected'] ?? 0 );
		$result['blocked']                     = $this->merge_worktree_abandoned_blockers($result['blocked'], (array) ( $bounded['skipped'] ?? array() ));

		return $bounded;
	}

	/**
	 * Build continuation evidence for a partially drained abandoned-cleanup stage.
	 *
	 * @param  string              $stage        Stage name.
	 * @param  array<string,mixed> $step         Stage result.
	 * @param  int                 $limit        Page size.
	 * @param  int                 $passes       Apply passes.
	 * @param  bool                $force        Whether force mode is active.
	 * @param  string              $until_budget Original budget argument.
	 * @return array<string,mixed>
	 */
	private function build_worktree_abandoned_continuation( string $stage, array $step, int $limit, int $passes, bool $force, string $until_budget ): array {
		$pagination  = (array) ( $step['pagination'] ?? $step['continuation'] ?? array() );
		$next_offset = isset($pagination['next_offset']) ? max(0, (int) $pagination['next_offset']) : 0;
		$command     = sprintf('studio wp datamachine-code workspace worktree abandoned --apply%s --stage=%s --offset=%d --limit=%d --passes=%d%s --format=json', $force ? ' --force' : '', $stage, $next_offset, $limit, $passes, '' !== $until_budget ? ' --until-budget=' . $until_budget : '');

		return array(
			'stage'        => $stage,
			'offset'       => $next_offset,
			'next_command' => $command,
			'pagination'   => $pagination,
		);
	}

	/**
	 * Drain paginated abandoned-cleanup classifier pages.
	 *
	 * Preview intentionally stays bounded to one page. Apply mode follows
	 * pagination.next_offset so stale rows beyond page zero can be reconciled or
	 * marked cleanup-eligible before bounded removal runs.
	 *
	 * @param  object              $ability    Ability object with execute().
	 * @param  array<string,mixed> $base_input Base ability input.
	 * @param  bool                $apply      Whether the orchestration is applying changes.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function drain_worktree_abandoned_pages( object $ability, array $base_input, bool $apply, ?float $deadline = null ): array|\WP_Error {
		$execute = array( $ability, 'execute' );
		if ( ! is_callable($execute) ) {
			return new \WP_Error('worktree_abandoned_ability_invalid', 'Worktree abandoned cleanup ability is not executable.', array( 'status' => 500 ));
		}

		$pages       = array();
		$summary     = array();
		$pagination  = array();
		$offset      = isset($base_input['offset']) ? max(0, (int) $base_input['offset']) : 0;
		$max_pages   = $apply ? 100 : 1;
		$last_result = array();

		for ( $page = 1; $page <= $max_pages; ++$page ) {
			if ( null !== $deadline && $this->worktree_abandoned_budget_expired($deadline) ) {
				break;
			}

			$input = $base_input;
			if ( isset($base_input['offset']) || $page > 1 ) {
				$input['offset'] = $offset;
			}
			$this->apply_worktree_abandoned_remaining_budget($input, $deadline);

			$result = $execute($input);
			if ( is_wp_error($result) ) {
				return $result;
			}

			$last_result = $result;
			$pages[]     = $this->summarize_worktree_abandoned_step($result);

			foreach ( (array) ( $result['summary'] ?? array() ) as $key => $value ) {
				if ( is_numeric($value) ) {
					$summary[ $key ] = (int) ( $summary[ $key ] ?? 0 ) + (int) $value;
				}
			}

			$pagination = (array) ( $result['pagination'] ?? $result['continuation'] ?? array() );
			if ( ! $apply || empty($pagination) || ! empty($pagination['complete']) ) {
				break;
			}

			$next_offset = isset($pagination['next_offset']) ? (int) $pagination['next_offset'] : null;
			if ( null === $next_offset || $next_offset <= $offset ) {
				break;
			}

			$total = isset($pagination['total']) ? (int) $pagination['total'] : null;
			if ( null !== $total && $next_offset >= $total ) {
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

	/**
	 * Parse abandoned-cleanup wall-clock budget.
	 *
	 * @param  string $duration Duration like 60s, 10m, or 1h.
	 * @return int|\WP_Error
	 */
	private function parse_worktree_abandoned_budget( string $duration ): int|\WP_Error {
		if ( ! preg_match('/^(\d+)([smh])$/', trim($duration), $matches) ) {
			return new \WP_Error('invalid_worktree_abandoned_budget', 'Invalid --until-budget duration. Use a compact value like 60s, 10m, or 1h.', array( 'status' => 400 ));
		}

		$value = (int) $matches[1];
		if ( $value < 1 ) {
			return new \WP_Error('invalid_worktree_abandoned_budget', 'Invalid --until-budget duration. Duration must be greater than zero.', array( 'status' => 400 ));
		}

		return match ( $matches[2] ) {
			'h' => $value * HOUR_IN_SECONDS,
			'm' => $value * MINUTE_IN_SECONDS,
			default => $value,
		};
	}

	/**
	 * Forward only the remaining abandoned-cleanup budget to one ability call.
	 *
	 * @param array<string,mixed> $input    Ability input.
	 * @param float|null          $deadline Shared wall-clock deadline.
	 */
	private function apply_worktree_abandoned_remaining_budget( array &$input, ?float $deadline ): void {
		if ( null === $deadline ) {
			return;
		}

		$remaining             = max(1, (int) floor($deadline - microtime(true)));
		$input['until_budget'] = $remaining . 's';
	}

	/**
	 * Check whether the abandoned-cleanup shared deadline has expired.
	 *
	 * @param float|null $deadline Shared wall-clock deadline.
	 * @return bool
	 */
	private function worktree_abandoned_budget_expired( ?float $deadline ): bool {
		return null !== $deadline && microtime(true) >= $deadline;
	}

	/**
	 * Build a compact step summary for abandoned cleanup output.
	 *
	 * @param  array<string,mixed> $step Step result.
	 * @return array<string,mixed>
	 */
	private function summarize_worktree_abandoned_step( array $step ): array {
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

	/**
	 * Merge blocked rows by handle so repeated passes do not duplicate output.
	 *
	 * @param  array<int|string,array<string,mixed>> $existing Existing blocked rows.
	 * @param  array<int,mixed>                      $incoming Incoming skipped rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function merge_worktree_abandoned_blockers( array $existing, array $incoming ): array {
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

	/**
	 * Render abandoned worktree cleanup result.
	 *
	 * @param array<string,mixed> $result     Orchestration result.
	 * @param array<string,mixed> $assoc_args CLI args.
	 */
	private function render_worktree_abandoned_result( array $result, array $assoc_args ): void {
		if ( 'json' === (string) ( $assoc_args['format'] ?? '' ) ) {
			if ( empty($assoc_args['verbose']) ) {
				$result = $this->compact_worktree_abandoned_result($result);
			}
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		if ( 'yaml' === (string) ( $assoc_args['format'] ?? '' ) ) {
			$this->format_items(array( $result ), array_keys($result), $assoc_args);
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		WP_CLI::log('Abandoned worktree cleanup:');
		$this->format_items(
			array(
				array(
					'metric' => 'applied',
					'value'  => ! empty($result['applied']) ? 'yes' : 'no',
				),
				array(
					'metric' => 'scanned',
					'value'  => (string) ( $summary['scanned'] ?? 0 ),
				),
				array(
					'metric' => 'reconciled',
					'value'  => (string) ( $summary['reconciled'] ?? 0 ),
				),
				array(
					'metric' => 'marked_cleanup_eligible',
					'value'  => (string) ( $summary['marked_cleanup_eligible'] ?? 0 ),
				),
				array(
					'metric' => 'would_mark_cleanup_eligible',
					'value'  => (string) ( $summary['would_mark_cleanup_eligible'] ?? 0 ),
				),
				array(
					'metric' => 'removed',
					'value'  => (string) ( $summary['removed'] ?? 0 ),
				),
				array(
					'metric' => 'would_remove',
					'value'  => (string) ( $summary['would_remove'] ?? 0 ),
				),
				array(
					'metric' => 'bytes_reclaimed',
					'value'  => $this->format_bytes( (int) ( $summary['bytes_reclaimed'] ?? 0 ) ),
				),
				array(
					'metric' => 'blocked',
					'value'  => (string) ( $summary['blocked'] ?? 0 ),
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);

		$blocked_by_reason = (array) ( $summary['blocked_by_reason'] ?? array() );
		if ( array() !== $blocked_by_reason ) {
			WP_CLI::log('Blocked rows by reason:');
			$items = array();
			foreach ( $blocked_by_reason as $reason => $count ) {
				$items[] = array(
					'reason' => (string) $reason,
					'count'  => (int) $count,
				);
			}
			$this->format_items($items, array( 'reason', 'count' ), array( 'format' => 'table' ), 'reason');
		}

		$next_commands = (array) ( $result['next_commands'] ?? array() );
		if ( array() !== $next_commands ) {
			WP_CLI::log('Next commands:');
			foreach ( $next_commands as $command ) {
				WP_CLI::log('  - ' . (string) $command);
			}
		}
	}

	/**
	 * Trim large abandoned cleanup JSON output while preserving summary counts.
	 *
	 * @param  array<string,mixed> $result Abandoned cleanup result.
	 * @return array<string,mixed>
	 */
	private function compact_worktree_abandoned_result( array $result ): array {
		$result['steps'] = $this->compact_worktree_abandoned_steps( (array) ( $result['steps'] ?? array() ) );

		$blocked = (array) ( $result['blocked'] ?? array() );
		if ( count($blocked) <= 25 ) {
			return $result;
		}

		$examples_by_reason = array();
		foreach ( $blocked as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}

			$reason = (string) ( $row['reason_code'] ?? 'unknown' );
			if ( count($examples_by_reason[ $reason ] ?? array()) >= 3 ) {
				continue;
			}

			$examples_by_reason[ $reason ][] = array(
				'handle'      => (string) ( $row['handle'] ?? '' ),
				'repo'        => (string) ( $row['repo'] ?? '' ),
				'branch'      => (string) ( $row['branch'] ?? '' ),
				'reason_code' => $reason,
				'reason'      => (string) ( $row['reason'] ?? '' ),
				'unpushed'    => isset($row['unpushed']) ? (int) $row['unpushed'] : null,
			);
		}

		$result['blocked_examples']              = $examples_by_reason;
		$result['evidence']['blocked_truncated'] = true;
		$result['evidence']['blocked_full_rows'] = count($blocked);
		$result['evidence']['blocked_full_hint'] = 'Re-run with --verbose --format=json to include full blocked rows.';
		$result['blocked']                       = array();

		return $result;
	}

	/**
	 * Compact large nested step pagination handle lists.
	 *
	 * @param  array<string,mixed> $steps Abandoned cleanup step summaries.
	 * @return array<string,mixed>
	 */
	private function compact_worktree_abandoned_steps( array $steps ): array {
		foreach ( $steps as $step_key => $step ) {
			if ( ! is_array($step) ) {
				continue;
			}

			$pagination = (array) ( $step['pagination'] ?? array() );
			foreach ( array( 'remaining_handles', 'handles' ) as $field ) {
				$handles = (array) ( $pagination[ $field ] ?? array() );
				if ( count($handles) <= 25 ) {
					continue;
				}

				$pagination[ $field . '_examples' ]  = array_slice(array_values($handles), 0, 25);
				$pagination[ $field . '_truncated' ] = true;
				$pagination[ $field . '_count' ]     = count($handles);
				unset($pagination[ $field ]);
			}

			$step['pagination'] = $pagination;
			$steps[ $step_key ] = $step;
		}

		return $steps;
	}

	/**
	 * Render CLI output for worktree operations.
	 *
	 * @param string $operation  Worktree operation.
	 * @param array  $result     Ability result.
	 * @param array  $assoc_args CLI assoc args.
	 */
	private function renderWorktreeResult( string $operation, array $result, array $assoc_args ): void {
		if ( 'add' === $operation && 'json' === (string) ( $assoc_args['format'] ?? '' ) ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		switch ( $operation ) {
			case 'list':
				$worktrees = $result['worktrees'] ?? array();
				if ( ! empty($assoc_args['stale']) ) {
					$worktrees = array_values(array_filter($worktrees, fn( $wt ) => ! empty($wt['stale_reason'])));
				}
				if ( empty($worktrees) ) {
					WP_CLI::log('No worktrees found.');
					$duplicates = (array) ( $result['duplicates'] ?? array() );
					if ( ! empty($duplicates) ) {
						WP_CLI::log(sprintf('Duplicate task ownership groups: %d', count($duplicates)));
					}
					return;
				}
				$items  = array_map(
				function ( $wt ) {
					$skipped = (array) ( $wt['fields_skipped'] ?? array() );
					$dirty   = $wt['dirty'] ?? null;
					$size    = $wt['size_bytes'] ?? null;
					return array(
						'handle'              => $wt['handle'] ?? '',
						'repo'                => $wt['repo'] ?? '',
						'kind'                => ! empty($wt['is_primary']) ? 'primary' : 'worktree',
						'branch'              => $wt['branch'] ?? '-',
						'head'                => isset($wt['head']) ? substr( (string) $wt['head'], 0, 7) : '-',
						'dirty'               => null === $dirty ? '-' : (int) $dirty,
						'created_at'          => $wt['created_at'] ?? null,
						'state'               => $wt['lifecycle_state'] ?? null,
						'liveness'            => $wt['liveness'] ?? 'unknown',
						'liveness_reason'     => $wt['liveness_reason'] ?? '',
						'last_seen_at'        => $wt['last_seen_at'] ?? null,
						'owner'               => isset($wt['owner']['user']) ? (string) $wt['owner']['user'] : 'unknown',
						'agent'               => isset($wt['owner']['agent']) ? (string) $wt['owner']['agent'] : 'unknown',
						'site'                => isset($wt['owner']['site']) ? (string) $wt['owner']['site'] : 'unknown',
						'session'             => isset($wt['session']['primary_id']) ? (string) $wt['session']['primary_id'] : '',
						'task'                => is_array($wt['task'] ?? null) ? (string) ( $wt['task']['task_url'] ?? $wt['task']['task_ref'] ?? '' ) : '',
						'pr'                  => $wt['pr_url'] ?? null,
						'age_days'            => $wt['age_days'] ?? null,
						'size_bytes'          => $size,
						'size'                => null === $size ? '-' : $this->format_bytes($size),
						'artifact_size_bytes' => $wt['artifact_size_bytes'] ?? 0,
						'artifacts'           => in_array('disk', $skipped, true) ? '-' : $this->format_bytes($wt['artifact_size_bytes'] ?? 0),
						'artifact_paths'      => $wt['artifacts'] ?? array(),
						'stale'               => $wt['stale_reason'] ?? '',
						'fields_skipped'      => $skipped,
						'metadata'            => $wt['metadata'] ?? null,
						'session_full'        => $wt['session'] ?? null,
						'owner_full'          => $wt['owner'] ?? null,
						'task_full'           => $wt['task'] ?? null,
						'path'                => $wt['path'] ?? '',
					);
				},
				$worktrees
				);
				$fields = array( 'handle', 'repo', 'kind', 'branch', 'head', 'dirty', 'state', 'liveness', 'last_seen_at', 'owner', 'agent', 'session', 'task', 'pr', 'age_days', 'size', 'artifacts', 'stale', 'path' );
				if ( in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml' ), true) ) {
					$fields = array( 'handle', 'repo', 'kind', 'branch', 'head', 'dirty', 'state', 'created_at', 'liveness', 'liveness_reason', 'last_seen_at', 'owner_full', 'session_full', 'task_full', 'pr', 'age_days', 'size_bytes', 'artifact_size_bytes', 'artifact_paths', 'stale', 'fields_skipped', 'metadata', 'path' );
				}
				$skipped_global = (array) ( $result['fields_skipped'] ?? array() );
				if ( ! empty($skipped_global) && ! in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml', 'csv' ), true) ) {
					WP_CLI::log(
					sprintf(
						'# Cheap listing — fields skipped: %s. Pass --with-status, --with-size, or --full to populate.',
						implode(', ', $skipped_global)
					)
					);
				}
				$this->format_items($items, $fields, $assoc_args, 'handle');
				$duplicates            = (array) ( $result['duplicates'] ?? array() );
				$base_branch_worktrees = (array) ( $result['base_branch_worktrees'] ?? array() );
				if ( ! empty($duplicates) && ! in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml' ), true) ) {
					WP_CLI::log(sprintf('Duplicate task ownership groups: %d', count($duplicates)));
					foreach ( $duplicates as $group ) {
						WP_CLI::log(sprintf('  - [%s=%s] %s', (string) ( $group['kind'] ?? '' ), (string) ( $group['key'] ?? '' ), implode(', ', (array) ( $group['handles'] ?? array() ))));
					}
				}
				if ( ! empty($base_branch_worktrees) && ! in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml' ), true) ) {
					WP_CLI::warning(sprintf('Base branch checked out in %d non-primary worktree%s; gh pr merge --delete-branch can merge remotely but fail local cleanup.', count($base_branch_worktrees), 1 === count($base_branch_worktrees) ? '' : 's'));
					foreach ( $base_branch_worktrees as $warning ) {
						WP_CLI::log(sprintf('  - %s (%s) at %s', (string) ( $warning['handle'] ?? '' ), (string) ( $warning['branch'] ?? '' ), (string) ( $warning['path'] ?? '' )));
					}
				}
				return;

			case 'prune':
				$pruned                = (array) ( $result['pruned'] ?? array() );
				$stale_inventory       = (array) ( $result['stale_inventory'] ?? array() );
				$stale_marker_blockers = (array) ( $result['stale_marker_blockers'] ?? array() );
				if ( empty($pruned) && empty($stale_inventory) && empty($stale_marker_blockers) ) {
					WP_CLI::log('Nothing to prune.');
					return;
				}
				if ( ! empty($pruned) ) {
					WP_CLI::success(sprintf('Pruned worktree registry across: %s', implode(', ', $pruned)));
				}
				if ( ! empty($stale_inventory) ) {
					WP_CLI::success(sprintf('Removed %d stale worktree inventory artifact%s.', count($stale_inventory), 1 === count($stale_inventory) ? '' : 's'));
				}
				if ( ! empty($stale_marker_blockers) ) {
					WP_CLI::warning(sprintf('Found %d path-present stale worktree marker blocker%s; left checkout paths in place for review.', count($stale_marker_blockers), 1 === count($stale_marker_blockers) ? '' : 's'));
					foreach ( $stale_marker_blockers as $blocker ) {
						WP_CLI::log(sprintf('  - %s at %s', (string) ( $blocker['handle'] ?? '' ), (string) ( $blocker['path'] ?? '' )));
					}
				}
				return;

			case 'cleanup':
				$this->render_worktree_cleanup_result($result, $assoc_args);
				return;
			case 'reconcile-metadata':
				$this->render_worktree_metadata_reconciliation_result($result, $assoc_args);
				return;

			case 'active-no-signal-report':
				$this->render_worktree_active_no_signal_report_result($result, $assoc_args);
				return;

			case 'active-no-signal-finalized-apply':
				$this->render_worktree_active_no_signal_finalized_apply_result($result, $assoc_args);
				return;

			case 'active-no-signal-equivalent-clean-apply':
				$this->render_worktree_active_no_signal_equivalent_clean_apply_result($result, $assoc_args);
				return;

			case 'active-no-signal-merged-apply':
				$this->render_worktree_active_no_signal_merged_apply_result($result, $assoc_args);
				return;

			case 'active-no-signal-remote-clean-apply':
				$this->render_worktree_active_no_signal_remote_clean_apply_result($result, $assoc_args);
				return;

			case 'cleanup-artifacts':
				$this->render_worktree_artifact_cleanup_result($result, $assoc_args);
				return;

			case 'bounded-cleanup-eligible-apply':
				$this->render_worktree_bounded_cleanup_eligible_apply_result($result, $assoc_args);
				return;

			case 'emergency-cleanup':
				$this->render_worktree_emergency_cleanup_result($result, $assoc_args);
				return;

			case 'add':
				WP_CLI::success($result['message'] ?? 'Worktree created.');
				if ( isset($result['disk_budget']) && is_array($result['disk_budget']) ) {
					$budget = $result['disk_budget'];
					WP_CLI::log(\DataMachineCode\Workspace\WorktreeDiskBudget::format_summary($budget));
					foreach ( (array) ( $budget['warnings'] ?? array() ) as $warning ) {
						WP_CLI::warning($warning);
					}
					if ( ! empty($budget['force_override_applied']) ) {
						WP_CLI::warning('Disk budget override applied because --force was explicit.');
					}
				}
				if ( ! empty($result['handle']) ) {
					WP_CLI::log(sprintf('Handle: %s', $result['handle']));
					WP_CLI::log(sprintf('Path:   %s', $result['path'] ?? '-'));
					WP_CLI::log(sprintf('Branch: %s%s', $result['branch'] ?? '-', ! empty($result['created_branch']) ? ' (created)' : ''));
				}
				if ( isset($result['context_injected']) ) {
					if ( ! empty($result['context_injected']) ) {
						$written = $result['context_files'] ?? array();
						WP_CLI::log(sprintf('Context: injected (%d file%s)', count($written), 1 === count($written) ? '' : 's'));
						foreach ( $written as $file ) {
							WP_CLI::log('  - ' . $file);
						}
						if ( ! empty($result['context_exclude_path']) ) {
							WP_CLI::log(sprintf('Excluded via: %s', $result['context_exclude_path']));
						}
					} else {
						$reason = $result['context_skip_reason'] ?? 'unknown';
						WP_CLI::log(sprintf('Context: not injected (%s)', $reason));
					}
				}
				if ( isset($result['bootstrap']) && is_array($result['bootstrap']) ) {
					$bs      = $result['bootstrap'];
					$ok      = ! empty($bs['success']);
					$ran_any = ! empty($bs['ran_any']);
					$label   = $ok ? ( $ran_any ? 'Bootstrap: ok' : 'Bootstrap: nothing to do' ) : 'Bootstrap: one or more steps FAILED';
					WP_CLI::log($label);
					WP_CLI::log(\DataMachineCode\Workspace\WorktreeBootstrapper::format($bs));
					if ( ! $ok ) {
						WP_CLI::warning('Worktree was created but bootstrap had failures. Re-run the failing step manually, or remove and retry.');
					}
				}
				$this->render_worktree_freshness($result);
				return;

			case 'refresh-context':
				WP_CLI::success($result['message'] ?? 'Worktree context refreshed.');
				WP_CLI::log(sprintf('Handle: %s', $result['handle'] ?? '-'));
				WP_CLI::log(sprintf('Path:   %s', $result['path'] ?? '-'));
				foreach ( (array) ( $result['written'] ?? array() ) as $file ) {
					WP_CLI::log('  - ' . $file);
				}
				if ( ! empty($result['exclude_path']) ) {
					WP_CLI::log(sprintf('Exclude file: %s', $result['exclude_path']));
				}
				if ( ! empty($result['metadata']['site_url']) ) {
					WP_CLI::log(sprintf('Originating site: %s', $result['metadata']['site_url']));
				}
				return;

			case 'finalize':
			case 'mark-cleanup-eligible':
				WP_CLI::success($result['message'] ?? 'Worktree finalized.');
				WP_CLI::log(sprintf('Handle: %s', $result['handle'] ?? '-'));
				WP_CLI::log(sprintf('State:  %s', $result['lifecycle_state'] ?? '-'));
				if ( ! empty($result['metadata']['pr_url']) ) {
					WP_CLI::log(sprintf('PR:     %s', $result['metadata']['pr_url']));
				}
				return;

			case 'remove':
			default:
				WP_CLI::success($result['message'] ?? 'Worktree operation complete.');
				return;
		}
	}

	/**
	 * Render workspace mutation lock status or prune results.
	 *
	 * @param array<string,mixed> $result Lock status or prune result.
	 */
	private function render_workspace_lock_result( array $result, array $assoc_args, bool $prune ): void {
		if ( 'json' === (string) ( $assoc_args['format'] ?? '' ) ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$status = $prune ? (array) ( $result['after'] ?? array() ) : $result;
		$fs     = (array) ( $status['filesystem'] ?? array() );
		$db     = (array) ( $status['database'] ?? array() );

		WP_CLI::log($prune ? 'Workspace mutation locks: stale prune complete' : 'Workspace mutation locks:');
		WP_CLI::log(sprintf('Active: %d  Stale: %d', (int) ( $status['active'] ?? 0 ), (int) ( $status['stale'] ?? 0 )));
		WP_CLI::log(sprintf('Database: %s active, %s stale, available=%s', (string) ( $db['active'] ?? 0 ), (string) ( $db['stale'] ?? 0 ), ! empty($db['available']) ? 'yes' : 'no'));
		WP_CLI::log(sprintf('Filesystem: %s active, %s stale, %s recent', (string) ( $fs['active'] ?? 0 ), (string) ( $fs['stale'] ?? 0 ), (string) ( $fs['recent'] ?? 0 )));

		if ( $prune ) {
			$filesystem = (array) ( $result['filesystem'] ?? array() );
			WP_CLI::log(sprintf('Filesystem removed: %d; skipped: %d', (int) ( $filesystem['removed_count'] ?? 0 ), (int) ( $filesystem['skipped_count'] ?? 0 )));
			if ( ! empty($result['dry_run']) ) {
				WP_CLI::log('Dry-run only. Re-run without --dry-run to remove stale unlocked lock files.');
			}
		}

		$locks = (array) ( $fs['locks'] ?? array() );
		if ( ! empty($locks) ) {
			$items = array_map(
				static function ( array $lock ): array {
					$owner = (array) ( $lock['owner_evidence'] ?? array() );
					return array(
						'lock_key'      => (string) ( $lock['lock_key'] ?? '' ),
						'scope'         => (string) ( $lock['scope'] ?? '' ),
						'state'         => (string) ( $lock['state'] ?? '' ),
						'age_seconds'   => $lock['age_seconds'] ?? null,
						'safe_to_prune' => ! empty($lock['safe_to_prune']) ? 'yes' : 'no',
						'owner_source'  => (string) ( $owner['source'] ?? '' ),
						'path'          => (string) ( $lock['path'] ?? '' ),
					);
				},
				$locks
			);
			$this->format_items($items, array( 'lock_key', 'scope', 'state', 'age_seconds', 'safe_to_prune', 'owner_source', 'path' ), $assoc_args, 'lock_key');
		}

		$db_locks = (array) ( $db['locks'] ?? array() );
		if ( ! empty($db_locks) ) {
			$items = array_map(
				static function ( array $lock ): array {
					return array(
						'lock_key'    => (string) ( $lock['lock_key'] ?? '' ),
						'scope'       => (string) ( $lock['scope'] ?? '' ),
						'state'       => (string) ( $lock['state'] ?? $lock['status'] ?? '' ),
						'owner'       => (string) ( $lock['owner'] ?? '' ),
						'age_seconds' => $lock['age_seconds'] ?? null,
						'expires_at'  => (string) ( $lock['expires_at'] ?? '' ),
					);
				},
				$db_locks
			);
			WP_CLI::log('');
			WP_CLI::log('Database lock rows:');
			$this->format_items($items, array( 'lock_key', 'scope', 'state', 'owner', 'age_seconds', 'expires_at' ), $assoc_args, 'lock_key');
		}

		if ( ! empty($status['stale_locks']) && is_array($status['stale_locks']) ) {
			$this->render_stale_lock_followup( (array) $status['stale_locks']);
		}

		$guidance = (array) ( $fs['guidance'] ?? $status['recovery_guidance'] ?? array() );
		if ( ! empty($guidance) ) {
			WP_CLI::log(sprintf('Status: %s', (string) ( $guidance['status_command'] ?? 'wp datamachine-code workspace worktree locks --format=json' )));
			WP_CLI::log(sprintf('Prune:  %s', (string) ( $guidance['dry_run_command'] ?? 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json' )));
			WP_CLI::log( (string) ( $guidance['safety'] ?? 'Active filesystem flocks are not pruned.' ) );
		}
	}

	/**
	 * Render stale lock follow-up rows with exact preview/apply commands.
	 *
	 * @param array<string,mixed> $report Stale lock report.
	 */
	private function render_stale_lock_followup( array $report ): void {
		if ( (int) ( $report['count'] ?? 0 ) <= 0 ) {
			return;
		}

		WP_CLI::log('');
		WP_CLI::log('Stale workspace locks:');
		WP_CLI::log(sprintf('Preview: %s', (string) ( $report['preview_command'] ?? 'wp datamachine-code workspace worktree locks --prune-stale --dry-run --format=json' )));
		WP_CLI::log(sprintf('Apply:   %s', (string) ( $report['apply_command'] ?? 'wp datamachine-code workspace worktree locks --prune-stale --format=json' )));
		WP_CLI::log( (string) ( $report['safety'] ?? 'Active filesystem flocks are reported and protected.' ) );

		$rows = array();
		foreach ( (array) ( $report['database'] ?? array() ) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$rows[] = array(
				'source'             => 'database',
				'lock_key'           => (string) ( $row['lock_key'] ?? '' ),
				'scope'              => (string) ( $row['scope'] ?? '' ),
				'owner'              => (string) ( $row['owner'] ?? '' ),
				'session'            => (string) ( $row['session'] ?? '' ),
				'age_seconds'        => $row['age_seconds'] ?? null,
				'live_flock_present' => ! empty($row['live_flock_present']) ? 'yes' : 'no',
				'safe_to_prune'      => ! empty($row['safe_to_prune']) ? 'yes' : 'no',
			);
		}
		foreach ( (array) ( $report['filesystem'] ?? array() ) as $row ) {
			if ( ! is_array($row) ) {
				continue;
			}
			$rows[] = array(
				'source'             => 'filesystem',
				'lock_key'           => (string) ( $row['lock_key'] ?? '' ),
				'scope'              => (string) ( $row['scope'] ?? '' ),
				'owner'              => '',
				'session'            => '',
				'age_seconds'        => $row['age_seconds'] ?? null,
				'live_flock_present' => ! empty($row['live_flock_present']) ? 'yes' : 'no',
				'safe_to_prune'      => ! empty($row['safe_to_prune']) ? 'yes' : 'no',
			);
		}

		$this->format_items($rows, array( 'source', 'lock_key', 'scope', 'owner', 'session', 'age_seconds', 'live_flock_present', 'safe_to_prune' ), array( 'format' => 'table' ), 'lock_key');
	}

	private function render_workspace_error( \WP_Error $error ): void {
		$data = (array) $error->get_error_data();
		if ( 'workspace_repo_busy' !== $error->get_error_code() && ! empty($data['next_commands']) && is_array($data['next_commands']) ) {
			WP_CLI::warning($error->get_error_message());
			WP_CLI::log('Next commands:');
			foreach ( $data['next_commands'] as $command ) {
				if ( is_scalar($command) && '' !== trim( (string) $command) ) {
					WP_CLI::log('  ' . (string) $command);
				}
			}
			if ( ! empty($data['hint']) ) {
				WP_CLI::log('Hint: ' . (string) $data['hint']);
			}
			WP_CLI::error($error->get_error_message());
			return;
		}
		if ( 'workspace_repo_busy' !== $error->get_error_code() ) {
			WP_CLI::error($error->get_error_message());
			return;
		}

		$lock = is_array($data['active_lock'] ?? null) ? (array) $data['active_lock'] : array();
		if ( ! empty($lock) ) {
			WP_CLI::warning(sprintf('Lock owner: %s', (string) ( $lock['owner'] ?? 'unknown' )));
			WP_CLI::log(sprintf('Lock key:   %s', (string) ( $lock['lock_key'] ?? $data['lock_key'] ?? '-' )));
			WP_CLI::log(sprintf('Scope:      %s', (string) ( $lock['scope'] ?? $data['scope'] ?? $data['repo'] ?? '-' )));
			WP_CLI::log(sprintf('Path:       %s', (string) ( $lock['metadata']['lock_path'] ?? $data['lock_path'] ?? '-' )));
			WP_CLI::log(sprintf('Acquired:   %s', (string) ( $lock['acquired_at'] ?? '-' )));
			WP_CLI::log(sprintf('Heartbeat:  %s', (string) ( $lock['heartbeat_at'] ?? '-' )));
			WP_CLI::log(sprintf('Expires:    %s', (string) ( $lock['expires_at'] ?? '-' )));
			if ( isset($lock['age_seconds']) || isset($lock['retry_after_seconds']) ) {
				WP_CLI::log(sprintf('Age/retry:  %ss old; retry after up to %ss', (string) ( $lock['age_seconds'] ?? '-' ), (string) ( $lock['retry_after_seconds'] ?? '-' )));
			}
			$owner_context = (array) ( $lock['metadata']['owner_context'] ?? array() );
			if ( ! empty($owner_context['wp_cli_args']) ) {
				WP_CLI::log(sprintf('Command:    %s', (string) $owner_context['wp_cli_args']));
			}
			$session_id = $this->resolve_owner_context_session_id($owner_context);
			if ( '' !== $session_id ) {
				WP_CLI::log(sprintf('Session:    %s', $session_id));
			}
		}

		$filesystem_lock = is_array($data['filesystem_lock'] ?? null) ? (array) $data['filesystem_lock'] : array();
		if ( ! empty($filesystem_lock) ) {
			WP_CLI::warning(sprintf('Filesystem lock: %s (%s)', (string) ( $filesystem_lock['lock_key'] ?? $data['lock_key'] ?? '-' ), (string) ( $filesystem_lock['state'] ?? 'unknown' )));
			WP_CLI::log(sprintf('Path:       %s', (string) ( $filesystem_lock['path'] ?? $data['lock_path'] ?? '-' )));
			WP_CLI::log(sprintf('Age:        %ss', (string) ( $filesystem_lock['age_seconds'] ?? '-' )));
			$owner_evidence = (array) ( $filesystem_lock['owner_evidence'] ?? array() );
			if ( ! empty($owner_evidence['source']) ) {
				WP_CLI::log(sprintf('Owner src:  %s', (string) $owner_evidence['source']));
			}
			if ( ! empty($owner_evidence['message']) ) {
				WP_CLI::log(sprintf('Owner note: %s', (string) $owner_evidence['message']));
			}
			if ( ! empty($filesystem_lock['operator_guidance']) ) {
				WP_CLI::log(sprintf('Guidance:   %s', (string) $filesystem_lock['operator_guidance']));
			}
		}

		if ( ! empty($data['status_command']) ) {
			WP_CLI::log(sprintf('Inspect:    %s', (string) $data['status_command']));
		}
		if ( ! empty($data['stale_prune_command']) ) {
			WP_CLI::log(sprintf('Recover:    %s', (string) $data['stale_prune_command']));
		}

		WP_CLI::error($error->get_error_message());
	}

	/**
	 * Resolve a displayable runtime session identifier from a lock owner context.
	 *
	 * Runtime-specific session IDs live under the generic `runtime_ids` envelope
	 * (runtime-id => { subkey => value }) populated by WorkspaceLockStore from the
	 * `datamachine_code_worktree_runtime_signatures` registry. DMC names no
	 * runtimes here: it prefers a `session_id` subkey, then falls back to the
	 * first available subkey value, scanning runtimes in registration order.
	 *
	 * @param  array $owner_context Decoded lock owner context.
	 * @return string Session identifier, or '' when none is available.
	 */
	private function resolve_owner_context_session_id( array $owner_context ): string {
		$runtime_ids = (array) ( $owner_context['runtime_ids'] ?? array() );

		foreach ( $runtime_ids as $entry ) {
			if ( is_array($entry) && '' !== trim( (string) ( $entry['session_id'] ?? '' )) ) {
				return (string) $entry['session_id'];
			}
		}

		foreach ( $runtime_ids as $entry ) {
			if ( ! is_array($entry) ) {
				continue;
			}
			foreach ( $entry as $value ) {
				if ( '' !== trim( (string) $value) ) {
					return (string) $value;
				}
			}
		}

		return '';
	}

	/**
	 * Render workspace hygiene report output.
	 *
	 * @param  array $report     Hygiene report.
	 * @param  array $assoc_args CLI args.
	 * @return void
	 */
	private function render_workspace_hygiene_report( array $report, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$size             = (array) ( $report['size'] ?? array() );
		$disk             = (array) ( $report['disk'] ?? array() );
		$worktrees        = (array) ( $report['worktrees'] ?? array() );
		$locks            = (array) ( $report['locks'] ?? array() );
		$database_locks   = (array) ( $locks['database'] ?? array() );
		$filesystem_locks = (array) ( $locks['filesystem'] ?? array() );
		$inventory        = (array) ( $report['inventory']['freshness'] ?? array() );
		$cleanup          = (array) ( $report['cleanup'] ?? array() );
		$cleanup_summary  = (array) ( $cleanup['summary'] ?? array() );

		WP_CLI::log('Workspace hygiene:');
		$this->format_items(
			array(
				array(
					'metric' => 'inventory_rows',
					'value'  => (string) ( $inventory['total_rows'] ?? 0 ),
				),
				array(
					'metric' => 'inventory_missing_paths',
					'value'  => (string) ( $inventory['missing_paths'] ?? 0 ),
				),
				array(
					'metric' => 'inventory_last_probe_at',
					'value'  => (string) ( $inventory['last_probe_at'] ?? '-' ),
				),
				array(
					'metric' => 'workspace_path',
					'value'  => (string) ( $report['workspace_path'] ?? '' ),
				),
				array(
					'metric' => 'workspace_size',
					'value'  => (string) ( $size['total_human'] ?? '-' ),
				),
				array(
					'metric' => 'size_mode',
					'value'  => (string) ( $size['mode'] ?? '-' ),
				),
				array(
					'metric' => 'size_scan_complete',
					'value'  => ! empty($size['scan_complete']) ? 'yes' : 'no',
				),
				array(
					'metric' => 'disk_free',
					'value'  => (string) ( $disk['free_human'] ?? '-' ),
				),
				array(
					'metric' => 'worktree_status_mode',
					'value'  => (string) ( $report['worktree_status_mode'] ?? '-' ),
				),
				array(
					'metric' => 'worktree_count',
					'value'  => (string) ( $worktrees['worktrees'] ?? 0 ),
				),
				array(
					'metric' => 'artifact_dirs',
					'value'  => (string) ( $worktrees['artifacts'] ?? 0 ),
				),
				array(
					'metric' => 'dirty_protected',
					'value'  => (string) ( $worktrees['protected_dirty'] ?? 0 ),
				),
				array(
					'metric' => 'unpushed_protected',
					'value'  => (string) ( $worktrees['protected_unpushed'] ?? 0 ),
				),
				array(
					'metric' => 'missing_metadata',
					'value'  => (string) ( $worktrees['missing_metadata'] ?? 0 ),
				),
				array(
					'metric' => 'external_worktrees',
					'value'  => (string) ( $worktrees['external'] ?? 0 ),
				),
				array(
					'metric' => 'liveness_live',
					'value'  => (string) ( $worktrees['by_liveness']['live'] ?? 0 ),
				),
				array(
					'metric' => 'liveness_stopped',
					'value'  => (string) ( $worktrees['by_liveness']['stopped'] ?? 0 ),
				),
				array(
					'metric' => 'liveness_stale',
					'value'  => (string) ( $worktrees['by_liveness']['stale'] ?? 0 ),
				),
				array(
					'metric' => 'liveness_unknown',
					'value'  => (string) ( $worktrees['by_liveness']['unknown'] ?? 0 ),
				),
				array(
					'metric' => 'duplicate_task_groups',
					'value'  => (string) ( $worktrees['duplicate_task_groups'] ?? 0 ),
				),
				array(
					'metric' => 'active_locks',
					'value'  => (string) ( $locks['active'] ?? 0 ),
				),
				array(
					'metric' => 'stale_locks',
					'value'  => (string) ( $locks['stale'] ?? 0 ),
				),
				array(
					'metric' => 'db_lock_rows',
					'value'  => (string) ( $database_locks['total'] ?? 0 ),
				),
				array(
					'metric' => 'filesystem_lock_files',
					'value'  => (string) ( $filesystem_locks['total'] ?? 0 ),
				),
				array(
					'metric' => 'cleanup_candidates',
					'value'  => (string) ( $cleanup_summary['would_remove'] ?? 0 ),
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);

		if ( ! empty($locks['stale_locks']) && is_array($locks['stale_locks']) ) {
			$this->render_stale_lock_followup( (array) $locks['stale_locks']);
		}

		$duplicates = (array) ( $worktrees['duplicates'] ?? array() );
		if ( array() !== $duplicates ) {
			WP_CLI::log('');
			WP_CLI::log('Duplicate task ownership groups (reported only — never auto-deleted):');
			foreach ( $duplicates as $group ) {
				WP_CLI::log(
					sprintf(
						'  - [%s=%s] %s',
						(string) ( $group['kind'] ?? '' ),
						(string) ( $group['key'] ?? '' ),
						implode(', ', (array) ( $group['handles'] ?? array() ))
					)
				);
			}
		}

		$top_size = array_slice( (array) ( $report['top_repos_by_size'] ?? array() ), 0, 10);
		$by_kind  = array_slice( (array) ( $size['by_kind'] ?? array() ), 0, 10);
		$entries  = array_slice( (array) ( $size['top_entries'] ?? array() ), 0, 10);

		if ( array() !== $by_kind ) {
			WP_CLI::log('');
			WP_CLI::log('Workspace size by kind:');
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'kind'  => $row['kind'] ?? '',
						'size'  => $row['human'] ?? '',
						'bytes' => $row['bytes'] ?? 0,
					),
					$by_kind
				),
				array( 'kind', 'size', 'bytes' ),
				array( 'format' => 'table' ),
				'bytes'
			);
		}

		if ( array() !== $entries ) {
			WP_CLI::log('');
			WP_CLI::log('Top workspace entries by size:');
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'handle' => $row['handle'] ?? '',
						'kind'   => $row['kind'] ?? '',
						'repo'   => $row['repo'] ?? '',
						'size'   => $row['human'] ?? '',
						'bytes'  => $row['bytes'] ?? 0,
					),
					$entries
				),
				array( 'handle', 'kind', 'repo', 'size', 'bytes' ),
				array( 'format' => 'table' ),
				'bytes'
			);
		}

		if ( array() !== $top_size ) {
			WP_CLI::log('');
			WP_CLI::log('Top repos by size:');
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'repo'  => $row['repo'] ?? '',
						'size'  => $row['human'] ?? '',
						'bytes' => $row['bytes'] ?? 0,
					),
					$top_size
				),
				array( 'repo', 'size', 'bytes' ),
				array( 'format' => 'table' ),
				'bytes'
			);
		}

		$top_counts = array_slice( (array) ( $report['top_repos_by_worktrees'] ?? array() ), 0, 10);
		if ( array() !== $top_counts ) {
			WP_CLI::log('');
			WP_CLI::log('Top repos by worktree count:');
			$this->format_items($top_counts, array( 'repo', 'worktree_count' ), array( 'format' => 'table' ), 'worktree_count');
		}

		$candidates = array_slice( (array) ( $cleanup['biggest_candidates'] ?? array() ), 0, 10);
		if ( array() !== $candidates ) {
			WP_CLI::log('');
			WP_CLI::log('Cleanup candidates:');
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'handle' => $row['handle'] ?? '',
						'branch' => $row['branch'] ?? '',
						'signal' => $row['signal'] ?? '',
						'size'   => $row['size_human'] ?? '',
					),
					$candidates
				),
				array( 'handle', 'branch', 'signal', 'size' ),
				array( 'format' => 'table' ),
				'handle'
			);
		}

		if ( ! empty($report['suggested_cleanup_command']) ) {
			WP_CLI::log('');
			WP_CLI::log('Suggested cleanup review:');
			WP_CLI::log( (string) $report['suggested_cleanup_command']);
		}

		foreach ( (array) ( $report['notes'] ?? array() ) as $note ) {
			WP_CLI::log('Note: ' . $note);
		}
	}

	/**
	 * Render cleanup output with a machine-safe JSON contract and concise tables.
	 *
	 * @param  array $result     Cleanup ability result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_cleanup_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		$only   = isset($assoc_args['only']) ? $this->normalize_worktree_cleanup_only( (string) $assoc_args['only']) : '';
		$report = $this->filter_worktree_cleanup_report($result, $only);

		if ( 'json' === $format ) {
			$json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$candidates = $report['candidates'] ?? array();
		$removed    = $report['removed'] ?? array();
		$skipped    = $report['skipped'] ?? array();
		$summary    = $report['summary'] ?? array();
		$dry_run    = ! empty($report['dry_run']);
		$verbose    = ! empty($assoc_args['verbose']);
		$limit      = $verbose ? PHP_INT_MAX : 10;

		if ( empty($candidates) && empty($removed) && empty($skipped) ) {
			WP_CLI::log('No worktrees found.');
			return;
		}

		if ( ! $dry_run ) {
			WP_CLI::log(sprintf('Result: removed %d worktree(s); reclaimed %s; skipped %d.', (int) ( $summary['removed'] ?? count($removed) ), $this->format_bytes($summary['bytes_reclaimed'] ?? 0), (int) ( $summary['skipped'] ?? count($skipped) )));
		}

		WP_CLI::log('Summary:');
		$summary_rows = array(
			array(
				'metric' => 'would_remove',
				'count'  => (int) ( $summary['would_remove'] ?? count($candidates) ),
			),
			array(
				'metric' => 'removed',
				'count'  => (int) ( $summary['removed'] ?? count($removed) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason_code => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason_code,
				'count'  => (int) $count,
			);
		}
		foreach ( (array) ( $summary['cleanup_buckets'] ?? array() ) as $bucket => $count ) {
			$summary_rows[] = array(
				'metric' => 'bucket:' . $bucket,
				'count'  => (int) $count,
			);
		}
		foreach ( (array) ( $summary['stale_reasons'] ?? array() ) as $reason => $count ) {
			$summary_rows[] = array(
				'metric' => 'stale:' . $reason,
				'count'  => (int) $count,
			);
		}
		foreach ( (array) ( $summary['liveness'] ?? array() ) as $state => $count ) {
			$summary_rows[] = array(
				'metric' => 'liveness:' . $state,
				'count'  => (int) $count,
			);
		}
		if ( isset($summary['age_filter']) && is_array($summary['age_filter']) ) {
			$summary_rows[] = array(
				'metric' => 'age_filter:excluded',
				'count'  => (int) ( $summary['age_filter']['excluded'] ?? 0 ),
			);
			$summary_rows[] = array(
				'metric' => 'age_filter:unknown_age',
				'count'  => (int) ( $summary['age_filter']['unknown_age'] ?? 0 ),
			);
		}
		$summary_rows[] = array(
			'metric' => 'total_size',
			'count'  => $this->format_bytes($summary['total_size_bytes'] ?? null),
		);
		$summary_rows[] = array(
			'metric' => 'artifact_size',
			'count'  => $this->format_bytes($summary['artifact_size_bytes'] ?? null),
		);
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		if ( ! empty($summary['size_by_repo']) && is_array($summary['size_by_repo']) ) {
			WP_CLI::log('');
			WP_CLI::log('Top repos by worktree size:');
			$repo_rows = array();
			foreach ( array_slice($summary['size_by_repo'], 0, 5, true) as $repo => $bytes ) {
				$repo_rows[] = array(
					'repo' => (string) $repo,
					'size' => $this->format_bytes($bytes),
				);
			}
			$this->format_items($repo_rows, array( 'repo', 'size' ), array( 'format' => 'table' ), 'size');
		}

		if ( '' !== $only ) {
			WP_CLI::log(sprintf('Filter: %s', $only));
		}

		$this->render_worktree_cleanup_next_commands( (array) ( $summary['skipped_next_commands'] ?? array() ));

		if ( ! empty($candidates) && ( '' === $only || 'candidates' === $only ) ) {
			WP_CLI::log('');
			WP_CLI::log($dry_run ? 'Would remove:' : 'Candidates:');
			$candidate_rows = array_map(
				fn( $c ) => array(
					'handle'      => $c['handle'] ?? '',
					'branch'      => $c['branch'] ?? '',
					'age_days'    => $c['age_days'] ?? '',
					'size'        => $this->format_bytes($c['size_bytes'] ?? null),
					'artifacts'   => $this->format_bytes($c['artifact_size_bytes'] ?? 0),
					'signal'      => $c['signal'] ?? '',
					'reason_code' => $c['reason_code'] ?? ( $c['signal'] ?? '' ),
					'reason'      => $c['reason'] ?? '',
				),
				array_slice($candidates, 0, $limit)
			);
			$fields         = $verbose ? array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason' ) : array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason_code' );
			$this->format_items($candidate_rows, $fields, array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($candidates), $limit, 'candidate rows');
		}

		if ( ! empty($removed) && ( '' === $only || 'removed' === $only ) ) {
			WP_CLI::log('');
			WP_CLI::log('Removed:');
			$removed_rows = array_map(
				fn( $c ) => array(
					'handle'      => $c['handle'] ?? '',
					'branch'      => $c['branch'] ?? '',
					'age_days'    => $c['age_days'] ?? '',
					'size'        => $this->format_bytes($c['size_bytes'] ?? null),
					'artifacts'   => $this->format_bytes($c['artifact_size_bytes'] ?? 0),
					'signal'      => $c['signal'] ?? '',
					'reason_code' => $c['reason_code'] ?? ( $c['signal'] ?? '' ),
					'reason'      => $c['reason'] ?? '',
				),
				array_slice($removed, 0, $limit)
			);
			$fields       = $verbose ? array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason' ) : array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason_code' );
			$this->format_items($removed_rows, $fields, array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($removed), $limit, 'removed rows');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			if ( $verbose ) {
				WP_CLI::log('Skipped:');
				$skipped_rows = array_map(
					fn( $s ) => array(
						'handle'       => $s['handle'] ?? '',
						'reason_code'  => $s['reason_code'] ?? '',
						'reason'       => $s['reason'] ?? '',
						'age_days'     => $s['age_days'] ?? '',
						'size'         => $this->format_bytes($s['size_bytes'] ?? null),
						'artifacts'    => $this->format_bytes($s['artifact_size_bytes'] ?? 0),
						'repo'         => $s['repo'] ?? '',
						'branch'       => $s['branch'] ?? '',
						'path'         => $s['path'] ?? '',
						'primary_path' => $s['primary_path'] ?? '',
						'missing'      => implode(',', (array) ( $s['missing_fields'] ?? array() )),
						'hint'         => $s['hint'] ?? '',
					),
					array_slice($skipped, 0, $limit)
				);
				$this->format_items($skipped_rows, array( 'handle', 'reason_code', 'reason', 'age_days', 'size', 'artifacts', 'repo', 'branch', 'path', 'primary_path', 'missing', 'hint' ), array( 'format' => 'table' ), 'handle');
				$this->render_cleanup_truncation_hint(count($skipped), $limit, 'skipped rows');
			} else {
				WP_CLI::log('Skipped summary:');
				$this->format_items($this->summarize_cleanup_skipped_rows($skipped), array( 'reason_code', 'count', 'examples' ), array( 'format' => 'table' ), 'reason_code');
				WP_CLI::log('Re-run with --verbose to list every skipped row or --only=<reason_code> to inspect one bucket.');
			}
		}

		WP_CLI::log('');
		if ( $dry_run ) {
			if ( ! empty($result['inventory_only']) && ! empty($summary['apply_command']) ) {
				WP_CLI::success(sprintf('%d cleanup-eligible worktree(s) would be removed. Apply this bounded reviewed class with: %s', count($result['candidates'] ?? array()), (string) $summary['apply_command']));
				return;
			}
			WP_CLI::success(sprintf('%d worktree(s) would be removed. Re-run without --dry-run to apply.', count($result['candidates'] ?? array())));
			return;
		}
		WP_CLI::success(sprintf('Removed %d worktree(s); %d skipped.', count($result['removed'] ?? array()), count($result['skipped'] ?? array())));
	}

	/**
	 * Render compact actionable next commands for skipped cleanup buckets.
	 *
	 * @param  array<int,array<string,mixed>> $commands Next command rows.
	 * @return void
	 */
	private function render_worktree_cleanup_next_commands( array $commands ): void {
		if ( empty($commands) ) {
			return;
		}

		WP_CLI::log('');
		WP_CLI::log('Next commands for skipped buckets:');
		$rows = array_map(
			fn( $row ) => array(
				'reason_code' => $row['reason_code'] ?? '',
				'count'       => (int) ( $row['count'] ?? 0 ),
				'command'     => $row['command'] ?? '',
				'alternative' => $row['alternative'] ?? '',
				'destructive' => ! empty($row['destructive']) ? 'yes' : 'no',
			),
			$commands
		);
		$this->format_items($rows, array( 'reason_code', 'count', 'destructive', 'command', 'alternative' ), array( 'format' => 'table' ), 'reason_code');
	}

	/**
	 * Render one human cleanup progress line.
	 *
	 * @param  array $event Progress event.
	 * @return void
	 */
	private function render_worktree_cleanup_progress( array $event ): void {
		$label = (string) ( $event['event'] ?? 'progress' );
		if ( 'start' === $label ) {
			WP_CLI::log(sprintf('Cleanup progress: starting scan of %d worktree(s).', (int) ( $event['total'] ?? 0 )));
			return;
		}

		WP_CLI::log(
			sprintf(
				'Cleanup progress: %s%s checked=%d/%d candidates=%d skipped=%d removed=%d elapsed=%.1fs',
				$label,
				(string) ( $event['handle'] ?? '' ) !== '' ? ' handle=' . (string) $event['handle'] : '',
				(int) ( $event['checked'] ?? 0 ),
				(int) ( $event['total'] ?? 0 ),
				(int) ( $event['candidates'] ?? 0 ),
				(int) ( $event['skipped'] ?? 0 ),
				(int) ( $event['removed'] ?? 0 ),
				(float) ( $event['elapsed'] ?? 0 )
			)
		);
	}

	/**
	 * Render metadata reconciliation output.
	 *
	 * @param  array $result     Reconciliation ability result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_metadata_reconciliation_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary            = (array) ( $result['summary'] ?? array() );
		$proposals          = (array) ( $result['proposals'] ?? array() );
		$written            = (array) ( $result['written'] ?? array() );
		$skipped            = (array) ( $result['skipped'] ?? array() );
		$still_unsafe       = (array) ( $result['still_unsafe'] ?? array() );
		$external_worktrees = (array) ( $result['external_worktrees'] ?? array() );
		$verbose            = ! empty($assoc_args['verbose']);
		$limit              = $verbose ? PHP_INT_MAX : 10;

		WP_CLI::log('Summary:');
		if ( isset($result['pagination']) && is_array($result['pagination']) ) {
			$pagination = (array) $result['pagination'];
			WP_CLI::log(
				sprintf(
					'Page: offset=%d limit=%d scanned=%d total=%d next_offset=%s complete=%s',
					(int) ( $pagination['offset'] ?? 0 ),
					(int) ( $pagination['limit'] ?? 0 ),
					(int) ( $pagination['scanned'] ?? 0 ),
					(int) ( $pagination['total'] ?? 0 ),
					null === ( $pagination['next_offset'] ?? null ) ? '-' : (string) $pagination['next_offset'],
					! empty($pagination['complete']) ? 'yes' : 'no'
				)
			);
		}
		$summary_rows = array(
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'proposed',
				'count'  => (int) ( $summary['proposed'] ?? count($proposals) ),
			),
			array(
				'metric' => 'written',
				'count'  => (int) ( $summary['written'] ?? count($written) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason_code => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason_code,
				'count'  => (int) $count,
			);
		}
		foreach ( (array) ( $summary['proposed_by_state'] ?? array() ) as $state => $count ) {
			$summary_rows[] = array(
				'metric' => 'state:' . $state,
				'count'  => (int) $count,
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		if ( ! empty($proposals) ) {
			WP_CLI::log('');
			WP_CLI::log( ! empty($result['dry_run']) ? 'Would write metadata:' : 'Reviewed proposals:');
			$proposal_rows = array_map(
				fn( $row ) => array(
					'handle'   => $row['handle'] ?? '',
					'branch'   => $row['branch'] ?? '',
					'state'    => $row['proposed_metadata']['lifecycle_state'] ?? '',
					'missing'  => implode(',', (array) ( $row['missing_fields'] ?? array() )),
					'dirty'    => (int) ( $row['dirty'] ?? 0 ),
					'unpushed' => (int) ( $row['unpushed'] ?? 0 ),
				),
				array_slice($proposals, 0, $limit)
			);
			$this->format_items($proposal_rows, array( 'handle', 'branch', 'state', 'missing', 'dirty', 'unpushed' ), array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($proposals), $limit, 'proposal rows');
		}

		if ( ! empty($written) ) {
			WP_CLI::log('');
			WP_CLI::log('Written:');
			$written_rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'state'       => $row['metadata']['lifecycle_state'] ?? '',
					'observed_at' => $row['metadata']['observed_at'] ?? '',
				),
				array_slice($written, 0, $limit)
			);
			$this->format_items($written_rows, array( 'handle', 'branch', 'state', 'observed_at' ), array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($written), $limit, 'written rows');
		}

		if ( ! empty($still_unsafe) ) {
			WP_CLI::log('');
			WP_CLI::log('Still unsafe:');
			$unsafe_rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $verbose ? ( $row['reason'] ?? '' ) : $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' )),
				),
				array_slice($still_unsafe, 0, $limit)
			);
			$this->format_items($unsafe_rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($still_unsafe), $limit, 'still-unsafe rows');
		}

		if ( ! empty($external_worktrees) ) {
			WP_CLI::log('');
			WP_CLI::log('External worktrees:');
			$external_rows = array_map(
				fn( $row ) => array(
					'handle' => $row['handle'] ?? '',
					'repo'   => $row['repo'] ?? '',
					'branch' => $row['branch'] ?? '',
					'path'   => $row['path'] ?? '',
				),
				array_slice($external_worktrees, 0, $limit)
			);
			$this->format_items($external_rows, array( 'handle', 'repo', 'branch', 'path' ), array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($external_worktrees), $limit, 'external worktree rows');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			WP_CLI::log('Skipped:');
			$skipped_rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $verbose ? ( $row['reason'] ?? '' ) : $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' )),
				),
				array_slice($skipped, 0, $limit)
			);
			$this->format_items($skipped_rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
			$this->render_cleanup_truncation_hint(count($skipped), $limit, 'skipped rows');
		}

		WP_CLI::log('');
		if ( ! empty($result['dry_run']) ) {
			if ( isset($result['pagination']['next_offset']) ) {
				if ( ! empty($result['pagination']['next_command']) ) {
					WP_CLI::log('Next page: ' . (string) $result['pagination']['next_command']);
				} else {
					WP_CLI::log(
						sprintf(
							'Next page: wp datamachine-code workspace worktree reconcile-metadata --dry-run --limit=%d --offset=%d --format=json',
							(int) ( $result['pagination']['limit'] ?? 0 ),
							(int) $result['pagination']['next_offset']
						)
					);
				}
			}
			WP_CLI::success(sprintf('%d metadata reconciliation proposal(s). Review JSON output before applying; --apply-plan remains a low-level escape hatch until DB-backed cleanup runs land.', count($proposals)));
			return;
		}
		if ( ! empty($result['job_backed']) ) {
			WP_CLI::success(sprintf('Scheduled %d metadata reconciliation page job(s).', (int) ( $summary['scheduled_jobs'] ?? 0 )));
			return;
		}
		if ( isset($result['pagination']['next_offset']) ) {
			if ( ! empty($result['pagination']['next_command']) ) {
				WP_CLI::log('Next page: ' . (string) $result['pagination']['next_command']);
			} else {
				WP_CLI::log(
					sprintf(
						'Next page: wp datamachine-code workspace worktree reconcile-metadata --apply --limit=%d --offset=%d --format=json',
						(int) ( $result['pagination']['limit'] ?? 0 ),
						(int) $result['pagination']['next_offset']
					)
				);
			}
		}
		WP_CLI::success(sprintf('Wrote metadata for %d worktree(s); %d skipped.', count($written), count($skipped)));
	}

	/**
	 * Render active/no-signal evidence report output.
	 *
	 * @param  array $result     Report result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_active_no_signal_report_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary    = (array) ( $result['summary'] ?? array() );
		$pagination = (array) ( $result['pagination'] ?? array() );
		$rows       = (array) ( $result['rows'] ?? array() );

		WP_CLI::log('Summary:');
		$summary_rows = array(
			array(
				'metric' => 'total_active_no_signal',
				'count'  => (int) ( $summary['total_active_no_signal'] ?? 0 ),
			),
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'with_pr',
				'count'  => (int) ( $summary['with_pr'] ?? 0 ),
			),
			array(
				'metric' => 'without_pr',
				'count'  => (int) ( $summary['without_pr'] ?? 0 ),
			),
			array(
				'metric' => 'dirty_or_unpushed',
				'count'  => (int) ( $summary['dirty_or_unpushed'] ?? 0 ),
			),
		);
		foreach ( (array) ( $summary['by_suggested_action'] ?? array() ) as $action => $count ) {
			$summary_rows[] = array(
				'metric' => 'action:' . $action,
				'count'  => (int) $count,
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		if ( ! empty($pagination) ) {
			WP_CLI::log(
				sprintf(
					'Page: offset=%d limit=%d scanned=%d total=%d next_offset=%s complete=%s',
					(int) ( $pagination['offset'] ?? 0 ),
					(int) ( $pagination['limit'] ?? 0 ),
					(int) ( $pagination['scanned'] ?? 0 ),
					(int) ( $pagination['total'] ?? 0 ),
					null === ( $pagination['next_offset'] ?? null ) ? '-' : (string) $pagination['next_offset'],
					! empty($pagination['complete']) ? 'yes' : 'no'
				)
			);
		}

		if ( ! empty($rows) ) {
			WP_CLI::log('');
			WP_CLI::log('Evidence:');
			$items = array_map(
				fn( $row ) => array(
					'handle'          => $row['handle'] ?? '',
					'branch'          => $row['branch'] ?? '',
					'action'          => $row['suggested_action'] ?? '',
					'dirty'           => null === ( $row['dirty'] ?? null ) ? '-' : (int) $row['dirty'],
					'unpushed'        => null === ( $row['unpushed'] ?? null ) ? '-' : (int) $row['unpushed'],
					'pr'              => is_array($row['pr'] ?? null) ? (string) ( $row['pr']['html_url'] ?? $row['pr']['number'] ?? '' ) : '',
					'outside_default' => null === ( $row['commits_outside_default'] ?? null ) ? '-' : (int) $row['commits_outside_default'],
					'remote_tracking' => null === ( $row['remote_tracking'] ?? null ) ? '-' : ( ! empty($row['remote_tracking']) ? 'yes' : 'no' ),
				),
				$rows
			);
			$this->format_items($items, array( 'handle', 'branch', 'action', 'dirty', 'unpushed', 'pr', 'outside_default', 'remote_tracking' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($pagination['next_command']) ) {
			WP_CLI::log('');
			WP_CLI::log('Next page: ' . (string) $pagination['next_command']);
		}

		WP_CLI::success(sprintf('Inspected %d active/no-signal worktree(s). Review-only; no cleanup was applied.', count($rows)));
	}

	/**
	 * Render finalized active/no-signal metadata apply output.
	 *
	 * @param  array $result     Apply result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_active_no_signal_finalized_apply_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		$planned = (array) ( $result['planned'] ?? array() );
		$written = (array) ( $result['written'] ?? array() );
		$skipped = (array) ( $result['skipped'] ?? array() );
		$dry_run = ! empty($result['dry_run']);

		WP_CLI::log('Finalized active/no-signal apply summary:');
		$summary_rows = array(
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'planned',
				'count'  => (int) ( $summary['planned'] ?? count($planned) ),
			),
			array(
				'metric' => 'written',
				'count'  => (int) ( $summary['written'] ?? count($written) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason,
				'count'  => (int) $count,
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		$rows = $dry_run ? $planned : $written;
		if ( ! empty($rows) ) {
			WP_CLI::log('');
			WP_CLI::log($dry_run ? 'Would promote:' : 'Promoted:');
			$items = array_map(
				fn( $row ) => array(
					'handle' => $row['handle'] ?? '',
					'branch' => $row['branch'] ?? '',
					'pr'     => is_array($row['pr'] ?? null) ? (string) ( $row['pr']['html_url'] ?? $row['pr']['number'] ?? '' ) : (string) ( $row['metadata']['pr_url'] ?? '' ),
					'state'  => $row['metadata']['lifecycle_state'] ?? '',
				),
				$rows
			);
			$this->format_items($items, array( 'handle', 'branch', 'pr', 'state' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			WP_CLI::log('Skipped:');
			$items = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'action'      => $row['action'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $row['reason'] ?? '',
				),
				array_slice($skipped, 0, 10)
			);
			$this->format_items($items, array( 'handle', 'action', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($result['pagination']['next_command']) ) {
			WP_CLI::log('');
			WP_CLI::log('Next page: ' . (string) $result['pagination']['next_command']);
		}

		if ( $dry_run ) {
			WP_CLI::success(sprintf('%d finalized worktree(s) would be promoted to cleanup_eligible metadata.', count($planned)));
			return;
		}
		WP_CLI::success(sprintf('Promoted %d finalized worktree(s) to cleanup_eligible metadata.', count($written)));
	}

	/**
	 * Render equivalent-clean active/no-signal metadata apply output.
	 *
	 * @param  array $result     Apply result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_active_no_signal_equivalent_clean_apply_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		$planned = (array) ( $result['planned'] ?? array() );
		$written = (array) ( $result['written'] ?? array() );
		$skipped = (array) ( $result['skipped'] ?? array() );
		$dry_run = ! empty($result['dry_run']);

		WP_CLI::log('Equivalent-clean active/no-signal apply summary:');
		$summary_rows = array(
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'planned',
				'count'  => (int) ( $summary['planned'] ?? count($planned) ),
			),
			array(
				'metric' => 'written',
				'count'  => (int) ( $summary['written'] ?? count($written) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason,
				'count'  => (int) $count,
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		$rows = $dry_run ? $planned : $written;
		if ( ! empty($rows) ) {
			WP_CLI::log('');
			WP_CLI::log($dry_run ? 'Would promote:' : 'Promoted:');
			$items = array_map(
				fn( $row ) => array(
					'handle' => $row['handle'] ?? '',
					'branch' => $row['branch'] ?? '',
					'signal' => $row['metadata']['cleanup_eligibility_evidence']['signal'] ?? '',
					'state'  => $row['metadata']['lifecycle_state'] ?? '',
				),
				$rows
			);
			$this->format_items($items, array( 'handle', 'branch', 'signal', 'state' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			WP_CLI::log('Skipped:');
			$items = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'action'      => $row['action'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $row['reason'] ?? '',
				),
				array_slice($skipped, 0, 10)
			);
			$this->format_items($items, array( 'handle', 'action', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($result['pagination']['next_command']) ) {
			WP_CLI::log('');
			WP_CLI::log('Next page: ' . (string) $result['pagination']['next_command']);
		}

		if ( $dry_run ) {
			WP_CLI::success(sprintf('%d equivalent-clean worktree(s) would be promoted to cleanup_eligible metadata.', count($planned)));
			return;
		}
		WP_CLI::success(sprintf('Promoted %d equivalent-clean worktree(s) to cleanup_eligible metadata.', count($written)));
	}

	/**
	 * Render merged-to-default active/no-signal metadata apply output.
	 *
	 * @param  array $result     Apply result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_active_no_signal_merged_apply_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		$planned = (array) ( $result['planned'] ?? array() );
		$written = (array) ( $result['written'] ?? array() );
		$skipped = (array) ( $result['skipped'] ?? array() );
		$dry_run = ! empty($result['dry_run']);

		WP_CLI::log('Merged-to-default active/no-signal apply summary:');
		$summary_rows = array(
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'planned',
				'count'  => (int) ( $summary['planned'] ?? count($planned) ),
			),
			array(
				'metric' => 'written',
				'count'  => (int) ( $summary['written'] ?? count($written) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason,
				'count'  => (int) $count,
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		$rows = $dry_run ? $planned : $written;
		if ( ! empty($rows) ) {
			WP_CLI::log('');
			WP_CLI::log($dry_run ? 'Would promote:' : 'Promoted:');
			$items = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'default_ref' => $row['metadata']['cleanup_eligibility_evidence']['default_ref'] ?? '',
					'state'       => $row['metadata']['lifecycle_state'] ?? '',
				),
				$rows
			);
			$this->format_items($items, array( 'handle', 'branch', 'default_ref', 'state' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			WP_CLI::log('Skipped:');
			$items = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'action'      => $row['action'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $row['reason'] ?? '',
				),
				array_slice($skipped, 0, 10)
			);
			$this->format_items($items, array( 'handle', 'action', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($result['pagination']['next_command']) ) {
			WP_CLI::log('');
			WP_CLI::log('Next page: ' . (string) $result['pagination']['next_command']);
		}

		if ( $dry_run ) {
			WP_CLI::success(sprintf('%d merged-to-default worktree(s) would be promoted to cleanup_eligible metadata.', count($planned)));
			return;
		}
		WP_CLI::success(sprintf('Promoted %d merged-to-default worktree(s) to cleanup_eligible metadata.', count($written)));
	}

	/**
	 * Render remote-clean active/no-signal metadata apply output.
	 *
	 * @param  array $result     Apply result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_active_no_signal_remote_clean_apply_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary = (array) ( $result['summary'] ?? array() );
		$planned = (array) ( $result['planned'] ?? array() );
		$written = (array) ( $result['written'] ?? array() );
		$skipped = (array) ( $result['skipped'] ?? array() );
		$dry_run = ! empty($result['dry_run']);

		WP_CLI::log('Remote-clean active/no-signal apply summary:');
		$summary_rows = array(
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'planned',
				'count'  => (int) ( $summary['planned'] ?? count($planned) ),
			),
			array(
				'metric' => 'written',
				'count'  => (int) ( $summary['written'] ?? count($written) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason,
				'count'  => (int) $count,
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		$rows = $dry_run ? $planned : $written;
		if ( ! empty($rows) ) {
			WP_CLI::log('');
			WP_CLI::log($dry_run ? 'Would promote:' : 'Promoted:');
			$items = array_map(
				fn( $row ) => array(
					'handle'     => $row['handle'] ?? '',
					'branch'     => $row['branch'] ?? '',
					'remote_ref' => $row['metadata']['cleanup_eligibility_evidence']['remote_ref'] ?? '',
					'state'      => $row['metadata']['lifecycle_state'] ?? '',
				),
				$rows
			);
			$this->format_items($items, array( 'handle', 'branch', 'remote_ref', 'state' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			WP_CLI::log('Skipped:');
			$items = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'action'      => $row['action'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $row['reason'] ?? '',
				),
				array_slice($skipped, 0, 10)
			);
			$this->format_items($items, array( 'handle', 'action', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($result['pagination']['next_command']) ) {
			WP_CLI::log('');
			WP_CLI::log('Next page: ' . (string) $result['pagination']['next_command']);
		}

		if ( $dry_run ) {
			WP_CLI::success(sprintf('%d remote-clean worktree(s) would be promoted to cleanup_eligible metadata.', count($planned)));
			return;
		}
		WP_CLI::success(sprintf('Promoted %d remote-clean worktree(s) to cleanup_eligible metadata.', count($written)));
	}

	/**
	 * Render artifact-only cleanup output.
	 *
	 * @param  array $result     Artifact cleanup ability result.
	 * @param  array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_artifact_cleanup_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$candidates = (array) ( $result['candidates'] ?? array() );
		$removed    = (array) ( $result['removed'] ?? array() );
		$skipped    = (array) ( $result['skipped'] ?? array() );
		$summary    = (array) ( $result['summary'] ?? array() );
		$dry_run    = ! empty($result['dry_run']);
		$verbose    = ! empty($assoc_args['verbose']);
		$pagination = $result['pagination'] ?? ( $summary['pagination'] ?? null );

		if ( empty($candidates) && empty($removed) && empty($skipped) ) {
			WP_CLI::log('No worktree artifacts found.');
			return;
		}

		WP_CLI::log('Artifact cleanup summary:');
		$this->format_items(
			array(
				array(
					'metric' => 'would_remove_artifacts',
					'count'  => (int) ( $summary['would_remove_artifacts'] ?? 0 ),
				),
				array(
					'metric' => 'removed_artifacts',
					'count'  => (int) ( $summary['removed_artifacts'] ?? 0 ),
				),
				array(
					'metric' => 'skipped_worktrees',
					'count'  => (int) ( $summary['skipped'] ?? count($skipped) ),
				),
				array(
					'metric' => 'artifact_size',
					'count'  => $this->format_bytes($summary['artifact_size_bytes'] ?? null),
				),
			),
			array( 'metric', 'count' ),
			array( 'format' => 'table' ),
			'metric'
		);

		if ( ! empty($candidates) ) {
			WP_CLI::log('');
			WP_CLI::log($dry_run && is_array($pagination) && 'size' === (string) ( $pagination['sort'] ?? '' ) ? 'Largest artifact opportunities:' : ( $dry_run ? 'Would remove artifacts:' : 'Artifact candidates:' ));
			$this->format_items($this->flatten_artifact_cleanup_rows($candidates), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($removed) ) {
			WP_CLI::log('');
			WP_CLI::log('Removed artifacts:');
			$this->format_items($this->flatten_artifact_cleanup_rows($removed), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			if ( $verbose ) {
				WP_CLI::log('Skipped worktrees:');
				$rows = array_map(
					fn( $row ) => array(
						'handle'      => $row['handle'] ?? '',
						'repo'        => $row['repo'] ?? '',
						'branch'      => $row['branch'] ?? '',
						'artifacts'   => count( (array) ( $row['artifacts'] ?? array() )),
						'reason_code' => $row['reason_code'] ?? '',
						'reason'      => $row['reason'] ?? '',
					),
					$skipped
				);
				$this->format_items($rows, array( 'handle', 'repo', 'branch', 'artifacts', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
			} else {
				WP_CLI::log('Skipped worktrees summary:');
				$this->format_items($this->summarize_cleanup_skipped_rows($skipped), array( 'reason_code', 'count', 'examples' ), array( 'format' => 'table' ), 'reason_code');
				WP_CLI::log('Re-run with --verbose to list every skipped worktree.');
			}
		}

		WP_CLI::log('');

		if ( is_array($pagination) ) {
			$mode_label = (string) ( $pagination['mode'] ?? 'bounded_inventory' );
			WP_CLI::log(
				sprintf(
					'Scan: mode=%s scanned=%d total=%d offset=%d limit=%d complete=%s safety_probes=%s',
					$mode_label,
					(int) ( $pagination['scanned'] ?? 0 ),
					(int) ( $pagination['total'] ?? 0 ),
					(int) ( $pagination['offset'] ?? 0 ),
					(int) ( $pagination['limit'] ?? 0 ),
					! empty($pagination['complete']) ? 'yes' : 'no',
					! empty($pagination['safety_probes']) ? 'yes' : 'no'
				)
			);
			if ( ! empty($pagination['partial']) && isset($pagination['next_offset']) ) {
				WP_CLI::log(sprintf('Partial scan — re-run with --offset=%d to continue, or pass --exhaustive for a full audit.', (int) $pagination['next_offset']));
			} elseif ( 'size' === (string) ( $pagination['sort'] ?? '' ) ) {
				WP_CLI::log(sprintf('Ranked by size across %d scanned worktree(s); showing the largest %d candidate(s).', (int) ( $pagination['scanned'] ?? 0 ), count($candidates)));
			}
			WP_CLI::log('');
		}

		if ( $dry_run ) {
			$apply_command = (string) ( $result['apply_command'] ?? $summary['apply_command'] ?? 'studio wp datamachine-code workspace cleanup run --mode=artifacts --format=json' );
			WP_CLI::success(sprintf('%d artifact(s) would be removed. Apply this page with `%s`; --apply-plan remains a low-level escape hatch.', (int) ( $summary['would_remove_artifacts'] ?? 0 ), $apply_command));
			return;
		}
		WP_CLI::success(sprintf('Removed %d artifact(s); %d worktree(s) skipped.', (int) ( $summary['removed_artifacts'] ?? 0 ), count($skipped)));
	}

	/**
	 * Flatten artifact cleanup worktree rows into table rows.
	 *
	 * @param  array<int,array> $rows Worktree artifact rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten_artifact_cleanup_rows( array $rows ): array {
		$flat = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				if ( ! is_array($artifact) ) {
					continue;
				}
				$flat[] = array(
					'handle'   => $row['handle'] ?? '',
					'repo'     => $row['repo'] ?? '',
					'branch'   => $row['branch'] ?? '',
					'artifact' => $artifact['path'] ?? '',
					'size'     => $this->format_bytes($artifact['size_bytes'] ?? null),
					'path'     => rtrim( (string) ( $row['path'] ?? '' ), '/') . '/' . ltrim( (string) ( $artifact['path'] ?? '' ), '/'),
				);
			}
		}

		return $flat;
	}

	/**
	 * Render emergency cleanup output.
	 *
	 * @param  array $result     Emergency cleanup result.
	 * @param  array $assoc_args CLI args.
	 * @return void
	 */
	private function render_worktree_bounded_cleanup_eligible_apply_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary      = (array) ( $result['summary'] ?? array() );
		$candidates   = (array) ( $result['candidates'] ?? array() );
		$removed      = (array) ( $result['removed'] ?? array() );
		$skipped      = (array) ( $result['skipped'] ?? array() );
		$continuation = (array) ( $result['continuation'] ?? array() );
		$dry_run      = ! empty($result['dry_run']);
		$job_backed   = ! empty($result['job_backed']);
		$verbose      = ! empty($assoc_args['verbose']);

		if ( ! $dry_run ) {
			WP_CLI::log(sprintf('Result: removed %d worktree(s); reclaimed %s; skipped %d.', (int) ( $summary['removed'] ?? count($removed) ), $this->format_bytes($summary['bytes_reclaimed'] ?? 0), (int) ( $summary['skipped'] ?? count($skipped) )));
		}

		WP_CLI::log('Bounded cleanup apply summary:');
		$summary_rows = array(
			array(
				'metric' => 'mode',
				'value'  => $dry_run ? 'dry-run' : ( $job_backed ? 'job-backed apply' : 'synchronous apply' ),
			),
			array(
				'metric' => 'processed',
				'value'  => (int) ( $summary['processed'] ?? 0 ),
			),
			array(
				'metric' => 'removed',
				'value'  => (int) ( $summary['removed'] ?? 0 ),
			),
			array(
				'metric' => 'skipped',
				'value'  => (int) ( $summary['skipped'] ?? 0 ),
			),
			array(
				'metric' => 'bytes_reclaimed',
				'value'  => $this->format_bytes($summary['bytes_reclaimed'] ?? 0),
			),
			array(
				'metric' => 'limit',
				'value'  => (int) ( $summary['limit'] ?? 0 ),
			),
		);
		if ( $job_backed ) {
			$summary_rows[] = array(
				'metric' => 'scheduled_jobs',
				'value'  => (int) ( $summary['scheduled_jobs'] ?? 0 ),
			);
		}
		$this->format_items($summary_rows, array( 'metric', 'value' ), array( 'format' => 'table' ), 'metric');

		if ( ! empty($candidates) && ( $dry_run || ! empty($assoc_args['verbose']) ) ) {
			WP_CLI::log('');
			WP_CLI::log('Bounded cleanup-eligible apply candidates:');
			$rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'repo'        => $row['repo'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'pr_url'      => $row['pr_url'] ?? '',
					'created_at'  => $row['created_at'] ?? '',
				),
				$candidates
			);
			$this->format_items($rows, array( 'handle', 'repo', 'branch', 'reason_code', 'pr_url', 'created_at' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($removed) ) {
			WP_CLI::log('');
			WP_CLI::log('Removed worktrees:');
			$rows = array_map(
				fn( $row ) => array(
					'handle'   => $row['handle'] ?? '',
					'repo'     => $row['repo'] ?? '',
					'branch'   => $row['branch'] ?? '',
					'size'     => $this->format_bytes($row['size_bytes'] ?? null),
					'unpushed' => (int) ( $row['unpushed_before_remove'] ?? 0 ),
					'path'     => $row['path'] ?? '',
				),
				$removed
			);
			$this->format_items($rows, array( 'handle', 'repo', 'branch', 'size', 'unpushed', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		$evidence            = (array) ( $result['evidence'] ?? array() );
		$discarded_unpushed = (array) ( $evidence['discarded_unpushed'] ?? array() );
		if ( ! empty($discarded_unpushed) ) {
			WP_CLI::warning('Discarded unpushed commits for cleanup-eligible worktrees. Evidence follows.');
			$rows = array_map(
				fn( $row ) => array(
					'handle'            => $row['handle'] ?? '',
					'unpushed_before'   => (int) ( $row['unpushed_before_remove'] ?? 0 ),
					'path_exists_after' => ! empty($row['path_exists_after']) ? 'yes' : 'no',
					'path'              => $row['path'] ?? '',
				),
				$discarded_unpushed
			);
			$this->format_items($rows, array( 'handle', 'unpushed_before', 'path_exists_after', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			if ( $verbose ) {
				WP_CLI::log('Skipped:');
				$rows = array_map(
					fn( $row ) => array(
						'handle'      => $row['handle'] ?? '',
						'reason_code' => $row['reason_code'] ?? '',
						'reason'      => $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' )),
					),
					$skipped
				);
				$this->format_items($rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
			} else {
				WP_CLI::log('Skipped summary:');
				$this->format_items($this->summarize_cleanup_skipped_rows($skipped), array( 'reason_code', 'count', 'examples' ), array( 'format' => 'table' ), 'reason_code');
				WP_CLI::log('Re-run with --verbose to list every skipped row.');
			}
		}

		WP_CLI::log('');
		$remaining = (int) ( $continuation['remaining_total'] ?? 0 );
		if ( $remaining > 0 ) {
			WP_CLI::log(sprintf('Continuation: %d candidate(s) remaining outside this batch.', $remaining));
			$hint = (string) ( $continuation['next_call_hint'] ?? '' );
			if ( '' !== $hint ) {
				WP_CLI::log('  ' . $hint);
			}
		} else {
			WP_CLI::log('Continuation: no candidates remaining outside this batch.');
		}

		if ( $dry_run ) {
			WP_CLI::success('Bounded cleanup-eligible apply dry-run complete.');
		} elseif ( $job_backed ) {
			WP_CLI::success(sprintf('Bounded cleanup-eligible apply scheduled %d cleanup chunk job(s).', (int) ( $summary['scheduled_jobs'] ?? 0 )));
		} else {
			WP_CLI::success(sprintf('Bounded cleanup-eligible apply removed %d worktree(s); reclaimed %s.', (int) ( $summary['removed'] ?? 0 ), $this->format_bytes($summary['bytes_reclaimed'] ?? 0)));
		}
	}

	private function render_worktree_emergency_cleanup_result( array $result, array $assoc_args ): void {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			WP_CLI::log(false === $json ? '{}' : $json);
			return;
		}

		$summary             = (array) ( $result['summary'] ?? array() );
		$artifact_candidates = (array) ( $result['artifact_candidates'] ?? array() );
		$worktree_candidates = (array) ( $result['worktree_candidates'] ?? array() );
		$removed_artifacts   = (array) ( $result['removed_artifacts'] ?? array() );
		$removed_worktrees   = (array) ( $result['removed_worktrees'] ?? array() );
		$skipped             = (array) ( $result['skipped'] ?? array() );
		$dry_run             = ! empty($result['dry_run']);

		WP_CLI::log('Emergency cleanup summary:');
		$summary_rows = array(
			array(
				'metric' => 'would_remove_artifacts',
				'count'  => (int) ( $summary['would_remove_artifacts'] ?? 0 ),
			),
			array(
				'metric' => 'would_remove_worktrees',
				'count'  => (int) ( $summary['would_remove_worktrees'] ?? 0 ),
			),
			array(
				'metric' => 'removed_artifacts',
				'count'  => (int) ( $summary['removed_artifacts'] ?? 0 ),
			),
			array(
				'metric' => 'removed_worktrees',
				'count'  => (int) ( $summary['removed_worktrees'] ?? 0 ),
			),
			array(
				'metric' => 'artifact_size',
				'count'  => $this->format_bytes($summary['artifact_size_bytes'] ?? null),
			),
			array(
				'metric' => 'worktree_size',
				'count'  => $this->format_bytes($summary['worktree_size_bytes'] ?? null),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? 0 ),
			),
		);
		$this->format_items($summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric');

		if ( ! empty($artifact_candidates) ) {
			WP_CLI::log('');
			WP_CLI::log('Priority 1 - artifact/cache deletion:');
			$this->format_items($this->flatten_artifact_cleanup_rows($artifact_candidates), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($worktree_candidates) ) {
			WP_CLI::log('');
			WP_CLI::log('Priority 2 - oldest finalized/eligible worktrees:');
			$rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'age_days'    => $row['age_days'] ?? '',
					'size'        => $this->format_bytes($row['size_bytes'] ?? null),
					'signal'      => $row['signal'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
				),
				$worktree_candidates
			);
			$this->format_items($rows, array( 'handle', 'branch', 'age_days', 'size', 'signal', 'reason_code' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($removed_artifacts) ) {
			WP_CLI::log('');
			WP_CLI::log('Removed artifacts:');
			$this->format_items($this->flatten_artifact_cleanup_rows($removed_artifacts), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($removed_worktrees) ) {
			WP_CLI::log('');
			WP_CLI::log('Removed worktrees:');
			$this->format_items($removed_worktrees, array( 'handle', 'repo', 'branch', 'path' ), array( 'format' => 'table' ), 'handle');
		}

		if ( ! empty($skipped) ) {
			WP_CLI::log('');
			WP_CLI::log('Skipped:');
			$rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' )),
				),
				$skipped
			);
			$this->format_items($rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle');
		}

		WP_CLI::log('');
		if ( $dry_run ) {
			WP_CLI::success('Emergency plan generated. Prefer `workspace cleanup run --mode=emergency`; --apply-plan remains a low-level escape hatch until DB-backed cleanup runs land.');
			return;
		}
		WP_CLI::success(sprintf('Emergency cleanup removed %d artifact group(s) and %d worktree(s); %d skipped.', count($removed_artifacts), count($removed_worktrees), count($skipped)));
	}

	/**
	 * Read and decode a cleanup plan file for --apply-plan.
	 *
	 * @param  string $path Plan file path.
	 * @return array
	 */
	private function read_worktree_cleanup_plan( string $path ): array {
		return $this->read_worktree_json_plan($path, 'cleanup');
	}

	/**
	 * Read and decode a JSON plan file for --apply-plan.
	 *
	 * @param  string $path  Plan file path.
	 * @param  string $label Human label for errors.
	 * @return array
	 */
	private function read_worktree_json_plan( string $path, string $label ): array {
		if ( '' === trim($path) ) {
			WP_CLI::error('--apply-plan requires a file path.');
			return array();
		}

		if ( ! is_readable($path) ) {
			WP_CLI::error(sprintf('%s plan is not readable: %s', ucfirst($label), $path));
			return array();
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents($path);
		if ( false === $raw ) {
			WP_CLI::error(sprintf('Failed to read %s plan: %s', $label, $path));
			return array();
		}

		$decoded = json_decode($raw, true);
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded) ) {
			WP_CLI::error(sprintf('%s plan must be a JSON object: %s', ucfirst($label), json_last_error_msg()));
			return array();
		}

		return $decoded;
	}

	/**
	 * Filter cleanup row arrays for --only without changing summary counts.
	 *
	 * @param  array  $report Cleanup report.
	 * @param  string $only   Section or reason-code filter.
	 * @return array
	 */
	private function filter_worktree_cleanup_report( array $report, string $only ): array {
		$only = $this->normalize_worktree_cleanup_only($only);

		if ( '' === $only ) {
			return $report;
		}

		if ( 'candidates' !== $only ) {
			$report['candidates'] = array();
		}
		if ( 'removed' !== $only ) {
			$report['removed'] = array();
		}

		if ( ! in_array($only, array( 'candidates', 'removed' ), true) ) {
			$report['skipped'] = array_values(
				array_filter(
					(array) ( $report['skipped'] ?? array() ),
					fn( $row ) => (string) ( $row['reason_code'] ?? '' ) === $only
				)
			);
		} else {
			$report['skipped'] = array();
		}

		return $report;
	}

	/**
	 * Normalize cleanup section/reason aliases used by --only.
	 *
	 * @param  string $only Raw CLI filter value.
	 * @return string Normalized filter value.
	 */
	private function normalize_worktree_cleanup_only( string $only ): string {
		$aliases = array(
			'would-remove'          => 'candidates',
			'would_remove'          => 'candidates',
			'dirty'                 => 'dirty_worktree',
			'merged-obsolete-dirty' => 'merged_pr_with_only_obsolete_dirty_changes',
			'merged_obsolete_dirty' => 'merged_pr_with_only_obsolete_dirty_changes',
			'safe-force-cleanup'    => 'merged_pr_with_only_obsolete_dirty_changes',
			'safe_force_cleanup'    => 'merged_pr_with_only_obsolete_dirty_changes',
			'unpushed'              => 'unpushed_commits',
			'missing-metadata'      => 'needs_metadata_reconcile',
			'missing_metadata'      => 'needs_metadata_reconcile',
			'requires-full-scan'    => 'needs_metadata_reconcile',
			'requires_full_scan'    => 'needs_metadata_reconcile',
			'no-signal'             => 'active_no_signal',
			'no_signal'             => 'active_no_signal',
			'external'              => 'external_worktree',
		);

		return $aliases[ $only ] ?? $only;
	}

	/**
	 * Shorten cleanup reasons for compact human tables.
	 *
	 * @param  string $reason Full reason text.
	 * @return string Shortened reason text.
	 */
	private function shorten_cleanup_reason( string $reason ): string {
		if ( strlen($reason) <= 72 ) {
			return $reason;
		}

		return rtrim(substr($reason, 0, 69)) . '...';
	}

	/**
	 * Print a concise-output truncation hint.
	 *
	 * @param  int    $total Total rows.
	 * @param  int    $limit Rendered row limit.
	 * @param  string $label Row label.
	 * @return void
	 */
	private function render_cleanup_truncation_hint( int $total, int $limit, string $label ): void {
		if ( $total <= $limit ) {
			return;
		}
		WP_CLI::log(sprintf('Showing %d of %d %s. Re-run with --verbose for all rows or --only=<reason_code> to filter.', $limit, $total, $label));
	}

	/**
	 * Summarize skipped cleanup rows by reason with representative handles.
	 *
	 * @param  array<int,array<string,mixed>> $skipped Skipped rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function summarize_cleanup_skipped_rows( array $skipped ): array {
		$summary = array();
		foreach ( $skipped as $row ) {
			$reason_code = (string) ( $row['reason_code'] ?? 'unknown' );
			if ( ! isset($summary[ $reason_code ]) ) {
				$summary[ $reason_code ] = array(
					'reason_code' => $reason_code,
					'count'       => 0,
					'examples'    => array(),
				);
			}
			++$summary[ $reason_code ]['count'];
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' !== $handle && count($summary[ $reason_code ]['examples']) < 3 ) {
				$summary[ $reason_code ]['examples'][] = $handle;
			}
		}

		ksort($summary);
		return array_values(
			array_map(
				fn( $row ) => array(
					'reason_code' => $row['reason_code'],
					'count'       => $row['count'],
					'examples'    => implode(', ', $row['examples']),
				),
				$summary
			)
		);
	}

	/**
	 * Format a byte count without depending on WordPress helpers in smoke tests.
	 *
	 * @param  mixed $bytes Raw byte count.
	 * @return string Human-readable size.
	 */
	private function format_bytes( mixed $bytes ): string {
		if ( null === $bytes || '' === $bytes ) {
			return '-';
		}

		$bytes      = max(0, (float) $bytes);
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$unit       = 0;
		$unit_count = count($units);
		while ( $bytes >= 1024 && $unit < $unit_count - 1 ) {
			$bytes /= 1024;
			++$unit;
		}

		$precision = 0 === $unit ? 0 : 1;
		return number_format($bytes, $precision) . ' ' . $units[ $unit ];
	}

	/**
	 * Render the freshness block for `worktree add` results.
	 *
	 * States, in priority order:
	 *   - fetch_failed=true         → `⚠ fetch failed — staleness unknown` (warning)
	 *   - rebase_attempted=true     → success or conflict status (log or warning)
	 *   - stale_commits_behind>0    → `⚠ <N> commits behind <upstream>` (warning + rebase hint)
	 *   - base_stale_commits_behind>0 → `⚠ base was <N> commits behind <base_upstream>` (warning + rebase hint)
	 *   - otherwise                 → `Freshness: up to date` (log)
	 *
	 * When no staleness signal is present at all (no fetch attempt recorded,
	 * no upstream configured, defaults used) the line is elided entirely —
	 * silence beats an ambiguous "up to date" we can't actually vouch for.
	 *
	 * @param  array $result Ability result payload.
	 * @return void
	 */
	private function render_worktree_freshness( array $result ): void {
		if ( ! empty($result['fetch_failed']) ) {
			$msg = 'Freshness: ⚠ fetch failed — staleness unknown';
			if ( ! empty($result['fetch_error']) ) {
				$msg .= "\n  " . $result['fetch_error'];
			}
			WP_CLI::warning($msg);
			return;
		}

		if ( ! empty($result['rebase_attempted']) ) {
			$target = isset($result['rebase_target']) ? (string) $result['rebase_target'] : 'upstream';
			if ( ! empty($result['rebase_succeeded']) ) {
				WP_CLI::log(sprintf('Freshness: rebased onto %s', $target));
				// Fall through in case either behind-count is still set (e.g. the
				// "other" path's metadata is present but zeroed). Renderer below
				// handles the 0-case correctly.
			} else {
				$msg = sprintf('Freshness: ⚠ rebase onto %s failed — worktree stayed at pre-rebase HEAD', $target);
				if ( ! empty($result['rebase_error']) ) {
					$msg .= "\n  " . $result['rebase_error'];
				}
				WP_CLI::warning($msg);
				// Staleness block below will still fire with the pre-rebase
				// behind-count so the agent sees exactly how stale it is.
			}
		}

		if ( isset($result['stale_commits_behind']) ) {
			$behind   = (int) $result['stale_commits_behind'];
			$upstream = isset($result['upstream']) ? (string) $result['upstream'] : 'upstream';
			if ( $behind > 0 ) {
				WP_CLI::warning(
					sprintf(
						"Freshness: ⚠ %d commits behind %s\n  Rebase before opening a PR:\n    git -C %s pull --rebase origin %s",
						$behind,
						$upstream,
						$result['path'] ?? '<worktree>',
						$result['branch'] ?? '<branch>'
					)
				);
				return;
			}
			WP_CLI::log(sprintf('Freshness: up to date (vs %s)', $upstream));
			return;
		}

		if ( isset($result['base_stale_commits_behind']) ) {
			$behind        = (int) $result['base_stale_commits_behind'];
			$base_upstream = isset($result['base_upstream']) ? (string) $result['base_upstream'] : 'origin';
			if ( $behind > 0 ) {
				WP_CLI::warning(
					sprintf(
						"Freshness: ⚠ base was %d commits behind %s\n  Rebase before opening a PR:\n    git -C %s pull --rebase origin %s",
						$behind,
						$base_upstream,
						$result['path'] ?? '<worktree>',
						$result['branch'] ?? '<branch>'
					)
				);
				return;
			}
			WP_CLI::log(sprintf('Freshness: up to date (base %s)', $base_upstream));
			return;
		}

		// No signal available (default base was origin/HEAD, or no upstream
		// configured for the existing branch). Elide the line rather than
		// print a potentially-misleading "up to date".
	}

	/**
	 * Convert a branch-shaped CLI alias into the exact ref expected downstream.
	 *
	 * `--from` remains the exact-ref escape hatch. `--base-branch` is for the
	 * common branch-name case agents reach for, so bare names become origin refs.
	 *
	 * @param  string $base_branch Branch or ref passed to --base-branch.
	 * @return string Ref to pass to the worktree-add ability.
	 */
	private static function base_branch_to_ref( string $base_branch ): string {
		$base_branch = trim($base_branch);

		if ( preg_match('/^(?:refs\/|(?:origin|upstream)\/|HEAD$|[a-f0-9]{7,40}$)/i', $base_branch) ) {
			return $base_branch;
		}

		return 'origin/' . ltrim($base_branch, '/');
	}
}
