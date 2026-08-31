<?php
/**
 * Workspace repository lifecycle operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\CommandSpec;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\ListCursor;
use DataMachineCode\Support\ProcessRunner;

defined('ABSPATH') || exit;

if ( ! class_exists(ProcessRunner::class) ) {
	require_once dirname(__DIR__) . '/Support/ProcessRunner.php';
}
if ( ! class_exists(CommandSpec::class) ) {
	require_once dirname(__DIR__) . '/Support/CommandSpec.php';
}

trait WorkspaceRepositoryLifecycle {

	/** Default number of lightweight inventory rows returned by workspace list. */
	private const WORKSPACE_LIST_DEFAULT_LIMIT = 50;

	/** Maximum page size for workspace list. */
	private const WORKSPACE_LIST_MAX_LIMIT = 200;

	/** Maximum per-repository summary rows returned with a workspace list. */
	private const WORKSPACE_LIST_SUMMARY_REPO_LIMIT = 25;

	/** Maximum wall-clock time spent discovering a bounded inventory page. */
	private const WORKSPACE_LIST_SCAN_BUDGET_SECONDS = 5.0;

	/**
	 * List repositories in the workspace.
	 *
	 * @param  string|null $repo Optional primary repository name to include.
	 * @param  string|null $type Optional checkout type filter: primary or worktree.
	 * @param  array{limit?:int,cursor?:string,all?:bool,include_status?:bool} $options List options.
	 * @return array{success: bool, repos: array, path: string, total: int|null, returned: int, next_cursor: string|null, partial: bool, continuation: array, diagnostics: array, status_requested: bool, summary: array}|\WP_Error
	 */
	public function list_repos( ?string $repo = null, ?string $type = null, array $options = array() ): array|\WP_Error {
		$path    = $this->workspace_path;
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		$repo_filter = null !== $repo && '' !== trim($repo) ? $this->parse_handle($repo)['repo'] : null;
		$type_filter = null !== $type && '' !== trim($type) ? strtolower(trim($type)) : null;
		if ( null !== $type_filter && ! in_array($type_filter, array( 'primary', 'worktree', 'context' ), true) ) {
			return new \WP_Error('invalid_workspace_type', 'Workspace list type must be "primary", "worktree", or "context".', array( 'status' => 400 ));
		}
		$all            = ! empty($options['all']);
		$include_status = ! empty($options['include_status']);
		if ( $all && isset($options['cursor']) ) {
			return new \WP_Error('invalid_workspace_list_pagination', 'Workspace list --all cannot be combined with --cursor.', array( 'status' => 400 ));
		}
		$limit = self::normalize_workspace_list_limit($options['limit'] ?? self::WORKSPACE_LIST_DEFAULT_LIMIT);
		if ( is_wp_error($limit) ) {
			return $limit;
		}
		$cursor = isset($options['cursor']) ? $this->decode_workspace_list_cursor((string) $options['cursor'], $repo_filter, $type_filter) : null;
		if ( is_wp_error($cursor) ) {
			return $cursor;
		}

		if ( ! is_dir($path) ) {
			return array(
				'success'          => true,
				'repos'            => array(),
				'path'             => $path,
				'total'            => 0,
				'returned'         => 0,
				'next_cursor'      => null,
				'partial'          => false,
				'continuation'     => array( 'available' => false, 'cursor' => null, 'reason' => null ),
				'diagnostics'      => $this->workspace_list_diagnostics( 0, 0.0, false, null, null ),
				'status_requested' => $include_status,
				'summary'          => $this->workspace_list_summary($path),
			);
		}

		$repos                 = array();
		$summary               = $this->workspace_list_summary($path);
		$repository_summaries  = array();
		$remaining             = 0;
		$started_at            = microtime(true);
		$scan_budget           = $all ? null : $this->workspace_list_scan_budget_seconds();
		$budget_exhausted      = false;
		foreach ( $this->workspace_list_rows($path, $repo_filter, $type_filter) as $repo_info ) {
			$this->workspace_list_count_summary($summary, $repo_info);
			$this->workspace_list_count_repository_summary($repository_summaries, $repo_info);
			if ( null === $cursor || strcmp($this->workspace_list_row_key($repo_info), $cursor) > 0 ) {
				++$remaining;
				if ( $all ) {
					$repos[] = $repo_info;
				} else {
					$this->workspace_list_insert_bounded_row($repos, $repo_info, $limit);
				}
			}
			if ( null !== $scan_budget && microtime(true) - $started_at >= $scan_budget ) {
				$budget_exhausted = true;
				break;
			}
		}
		if ( $all ) {
			usort($repos, fn( array $left, array $right ): int => strcmp($this->workspace_list_row_key($left), $this->workspace_list_row_key($right)));
		}
		ksort($repository_summaries);
		$summary['repos'] = array_slice(array_values($repository_summaries), 0, self::WORKSPACE_LIST_SUMMARY_REPO_LIMIT);
		$observed_repository_count = count($repository_summaries);
		$summary['repo_count'] = $budget_exhausted ? null : $observed_repository_count;
		$this->workspace_list_finish_summary($summary, ! $budget_exhausted, $observed_repository_count);
		if ( $include_status ) {
			foreach ( $repos as &$repo_info ) {
				if ( empty($repo_info['git']) || ! is_string($repo_info['path'] ?? null) ) {
					continue;
				}
				$remote = $this->git_get_remote($repo_info['path']);
				if ( null !== $remote ) {
					$repo_info['remote'] = $remote;
				}
				$branch = $this->git_get_branch($repo_info['path']);
				if ( null !== $branch ) {
					$repo_info['branch'] = $branch;
				}
				if ( empty($repo_info['is_worktree']) && empty($repo_info['is_context']) ) {
					$repo_info['primary_freshness'] = $this->build_primary_freshness_report($repo_info['path'], (string) $repo_info['name']);
				}
			}
			unset($repo_info);
		}
		$next_cursor = null;
		if ( ! $all && ! $budget_exhausted && $remaining > count($repos) && ! empty($repos) ) {
			$next_cursor = $this->encode_workspace_list_cursor($this->workspace_list_row_key($repos[ count($repos) - 1 ]), $repo_filter, $type_filter);
		}
		$elapsed = max(0.0, microtime(true) - $started_at);

		return array(
			'success'          => true,
			'repos'            => $repos,
			'path'             => $path,
			'total'            => $budget_exhausted ? null : $summary['total'],
			'returned'         => count($repos),
			'next_cursor'      => $next_cursor,
			'partial'          => $budget_exhausted,
			'continuation'     => array(
				'available' => null !== $next_cursor,
				'cursor'    => $next_cursor,
				'reason'    => $budget_exhausted ? 'scan_budget_exhausted' : ( null === $next_cursor ? null : 'more_rows' ),
			),
			'diagnostics'      => $this->workspace_list_diagnostics(1, $elapsed, $budget_exhausted, $budget_exhausted ? 'scan_budget_exhausted' : null, $scan_budget),
			'status_requested' => $include_status,
			'summary'          => $summary,
		);
	}

	/**
	 * Build bounded whole-result counts before pagination removes rows.
	 * @return array<string,mixed>
	 */
	private function workspace_list_summary( string $path ): array {
		return array(
			'total'     => 0,
			'primary'   => 0,
			'worktree'  => 0,
			'context'   => 0,
			'non_git'   => 0,
			'repos'     => array(),
			'workspace' => $path,
		);
	}

	/** @param array<string,mixed> $summary @param array<string,mixed> $row */
	private function workspace_list_count_summary( array &$summary, array $row ): void {
		++$summary['total'];
		$kind = ! empty($row['is_context']) ? 'context' : ( ! empty($row['is_worktree']) ? 'worktree' : 'primary' );
		++$summary[ $kind ];
		if ( empty($row['git']) ) {
			++$summary['non_git'];
		}
	}

	/** @param array<string,mixed> $summary */
	private function workspace_list_finish_summary( array &$summary, bool $complete, int $observed_repository_count ): void {
		$summary['repos_returned'] = count($summary['repos']);
		$summary['repos_omitted']  = $complete ? $summary['repo_count'] - $summary['repos_returned'] : null;
		if ( ! $complete ) {
			$summary['observed'] = array(
				'total'      => $summary['total'],
				'primary'    => $summary['primary'],
				'worktree'   => $summary['worktree'],
				'context'    => $summary['context'],
				'non_git'    => $summary['non_git'],
				'repo_count' => $observed_repository_count,
			);
			$summary['total'] = null;
			$summary['primary'] = null;
			$summary['worktree'] = null;
			$summary['context'] = null;
			$summary['non_git'] = null;
		}
		if ( ( $summary['observed']['non_git'] ?? $summary['non_git'] ) > 0 ) {
			$summary['triage_command'] = 'wp datamachine-code workspace triage list --format=json';
		}
	}

	/** @param array<string,array<string,int|string>> $repositories @param array<string,mixed> $row */
	private function workspace_list_count_repository_summary( array &$repositories, array $row ): void {
		$repo = (string) ( $row['repo'] ?? $row['name'] ?? 'unknown' );
		if ( ! isset($repositories[ $repo ]) ) {
			$repositories[ $repo ] = array( 'repo' => $repo, 'primary' => 0, 'worktree' => 0, 'context' => 0, 'total' => 0 );
		}
		$kind = ! empty($row['is_context']) ? 'context' : ( ! empty($row['is_worktree']) ? 'worktree' : 'primary' );
		++$repositories[ $repo ][ $kind ];
		++$repositories[ $repo ]['total'];
	}

	/** @return array<string,float|int|string|bool|null> */
	private function workspace_list_diagnostics( int $scan_passes, float $elapsed, bool $budget_exhausted, ?string $reason, ?float $budget ): array {
		return array(
			'scan_passes'              => $scan_passes,
			'scan_elapsed_seconds'     => $elapsed,
			'scan_budget_seconds'      => $budget,
			'budget_exhausted'         => $budget_exhausted,
			'budget_exhaustion_reason' => $reason,
		);
	}

	protected function workspace_list_scan_budget_seconds(): float {
		return self::WORKSPACE_LIST_SCAN_BUDGET_SECONDS;
	}

	/** @return \Generator<int,array<string,mixed>> */
	protected function workspace_list_rows( string $path, ?string $repo_filter, ?string $type_filter ): \Generator {
		$this->workspace_list_scan_started();
		if ( 'context' !== $type_filter ) {
			foreach ( new \DirectoryIterator($path) as $entry ) {
				$name = $entry->getFilename();
				if ( $entry->isDot() || str_starts_with($name, '.') || ! $entry->isDir() ) { continue; }
				$entry_path = $entry->getPathname();
				$git_path = $entry_path . '/.git';
				$is_git = is_dir($git_path) || is_file($git_path);
				if ( ! $is_git ) { continue; }
				$parsed = $this->parse_handle($name);
				if ( null !== $repo_filter && $parsed['repo'] !== $repo_filter ) { continue; }
				$is_worktree = is_file($git_path) || $parsed['is_worktree'];
				if ( ( 'primary' === $type_filter && $is_worktree ) || ( 'worktree' === $type_filter && ! $is_worktree ) ) { continue; }
				$row = array( 'name' => $name, 'path' => $entry_path, 'git' => $is_git, 'is_worktree' => $is_worktree, 'repo' => $parsed['repo'] );
				if ( $parsed['is_worktree'] ) { $row['branch_slug'] = $parsed['branch_slug']; }
				yield $row;
			}
		}
		if ( null === $type_filter || 'context' === $type_filter ) {
			foreach ( WorkspaceAliasResolver::context_repositories() as $alias => $context ) {
				if ( null !== $repo_filter && $this->parse_handle((string) ( $context['target'] ?? $alias ))['repo'] !== $repo_filter && $alias !== $repo_filter ) { continue; }
				$target = (string) ( $context['target'] ?? $alias );
				$context_path = $path . '/' . $this->parse_handle($target)['dir_name'];
				yield array( 'name' => $alias, 'path' => is_dir($context_path) ? $context_path : null, 'git' => is_dir($context_path . '/.git') || is_file($context_path . '/.git'), 'is_worktree' => false, 'is_context' => true, 'repo' => (string) ( $context['repo'] ?? $target ), 'ref' => (string) ( $context['ref'] ?? '' ), 'workspace_policy' => WorkspaceAliasResolver::policy_attestation($alias) );
			}
		}
	}

	protected function workspace_list_scan_started(): void {}

	/** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $row */
	protected function workspace_list_insert_bounded_row( array &$rows, array $row, int $limit ): void {
		$key = $this->workspace_list_row_key($row);
		$position = count($rows);
		foreach ( $rows as $index => $existing ) {
			if ( strcmp($key, $this->workspace_list_row_key($existing)) < 0 ) { $position = $index; break; }
		}
		if ( $position >= $limit && $limit === count($rows) ) { return; }
		array_splice($rows, $position, 0, array( $row ));
		if ( count($rows) > $limit ) { array_pop($rows); }
	}

	public static function normalize_workspace_list_limit( mixed $limit ): int|\WP_Error {
		if ( is_int($limit) || ( is_string($limit) && ctype_digit($limit) ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_int($limit) || $limit < 1 || $limit > self::WORKSPACE_LIST_MAX_LIMIT ) {
			return new \WP_Error('invalid_workspace_list_limit', sprintf('Workspace list limit must be an integer between 1 and %d.', self::WORKSPACE_LIST_MAX_LIMIT), array( 'status' => 400 ));
		}
		return $limit;
	}

	/** @param array<string,mixed> $row */
	private function workspace_list_row_key( array $row ): string {
		return (string) ( $row['name'] ?? '' ) . "\0" . (string) ( $row['path'] ?? '' );
	}

	private function encode_workspace_list_cursor( string $after, ?string $repo, ?string $type ): string {
		return ListCursor::encode($after, array( 'repo' => $repo, 'type' => $type ));
	}

	private function decode_workspace_list_cursor( string $cursor, ?string $repo, ?string $type ): string|\WP_Error {
		return ListCursor::decode(
			$cursor,
			array( 'repo' => $repo, 'type' => $type ),
			'invalid_workspace_list_cursor',
			'Workspace list cursor is invalid for the requested filters.'
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * @param  string      $url     Git clone URL.
	 * @param  string|null $name    Directory name override (derived from URL if null).
	 * @param  array       $options Optional clone options.
	 * @return array{success: bool, name?: string, path?: string, message?: string}|\WP_Error
	 */
	public function clone_repo( string $url, ?string $name = null, array $options = array() ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		// Validate URL.
		if ( empty($url) ) {
			return new \WP_Error('missing_url', 'Repository URL is required.', array( 'status' => 400 ));
		}

		// Derive name from URL if not provided.
		if ( null === $name || '' === $name ) {
			$name = $this->derive_repo_name($url);
			if ( null === $name ) {
				return new \WP_Error('invalid_url', sprintf('Could not derive repository name from URL: %s. Use --name to specify.', $url), array( 'status' => 400 ));
			}
		}

		// Reject @-suffixed names — those are reserved for worktrees.
		if ( str_contains($name, '@') ) {
			return new \WP_Error('invalid_clone_name', 'Repository names cannot contain "@". The "@<branch-slug>" suffix is reserved for worktrees (use "workspace worktree add" instead).', array( 'status' => 400 ));
		}

		$name                   = $this->sanitize_name($name);
		$repo_path              = $this->workspace_path . '/' . $name;
		$allow_duplicate_remote = ! empty($options['allow_duplicate_remote']);

		// Check if already exists.
		if ( is_dir($repo_path) ) {
			$existing_remote = file_exists($repo_path . '/.git') ? $this->git_get_remote($repo_path) : null;
			if ( null !== $existing_remote && ! $allow_duplicate_remote && $this->normalize_git_remote_url($url) === $this->normalize_git_remote_url($existing_remote) ) {
				return $this->clone_remote_exists_error(
					$url,
					$name,
					array(
						'name'   => $name,
						'path'   => $repo_path,
						'remote' => $existing_remote,
					)
				);
			}
			return $this->clone_target_exists_error($name, $repo_path);
		}

		// Ensure workspace exists.
		$ensure = $this->ensure_exists();
		if ( is_wp_error($ensure) ) {
			return $ensure;
		}

		$existing_primary = $this->find_primary_by_remote($url, $name);
		if ( null !== $existing_primary && ! $allow_duplicate_remote ) {
			return $this->clone_remote_exists_error($url, $name, $existing_primary);
		}

		if ( ! GitRunner::supports_streaming() ) {
			return GitRunner::unavailable_error('Clone workspace repository', true);
		}

		$partial_clone     = ! (bool) ( $options['full'] ?? false ) && $this->should_use_partial_clone($url);
		$progress_callback = is_callable($options['progress_callback'] ?? null) ? $options['progress_callback'] : null;
		$started_at        = microtime(true);

		$this->emit_clone_progress(
			$progress_callback,
			'start',
			sprintf(
				'Cloning %s into %s%s.',
				$url,
				$repo_path,
				$partial_clone ? ' using partial clone (--filter=blob:none)' : ''
			),
			$started_at
		);

		$env = $this->build_clone_environment($url, $options);
		if ( is_wp_error($env) ) {
			return $env;
		}

		$command = $this->build_clone_command($url, $repo_path, $partial_clone, $env);
		if ( is_wp_error($command) ) {
			return $command;
		}

		$result = $this->run_clone_command($command, $progress_callback, $started_at);

		if ( is_wp_error($result) ) {
			return $this->clone_failed_error($result, $name, $repo_path, $url);
		}

		$this->emit_clone_progress($progress_callback, 'verify', sprintf('Verifying cloned checkout at %s.', $repo_path), $started_at);
		$validation = $this->validate_clone_target($url, $name, $repo_path);
		if ( is_wp_error($validation) ) {
			return $validation;
		}

		// Guarantee the freshly cloned default branch tracks its remote (issue
		// #833). `git clone` normally sets this, but some server/git/partial-clone
		// configurations leave the default branch with no upstream, which later
		// breaks `workspace git pull --allow-primary-refresh`. Establish tracking
		// here so primaries are refreshable from the moment they exist.
		$this->ensure_default_branch_tracking($repo_path);

		$this->emit_workspace_changed('clone', $name, $name, $repo_path);

		return array(
			'success' => true,
			'name'    => $name,
			'path'    => $repo_path,
			'message' => sprintf('Cloned %s into workspace as "%s".', $url, $name),
		);
	}

	/**
	 * Materialize registered remote workspace state through the local lifecycle.
	 *
	 * @param array<string,mixed> $remote Registered remote workspace details.
	 * @param array<string,mixed> $options Local clone/worktree options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function materialize_remote_workspace( array $remote, array $options = array() ): array|\WP_Error {
		$repo_name = trim( (string) ( $remote['repo_name'] ?? '' ) );
		$url       = trim( (string) ( $remote['url'] ?? '' ) );
		$branch    = trim( (string) ( $remote['branch'] ?? '' ) );
		if ( '' === $repo_name || '' === $url ) {
			return new \WP_Error('remote_workspace_materialization_invalid', 'Registered remote workspace is missing its repository identity.', array( 'status' => 400 ));
		}

		$primary = $this->show_repo($repo_name);
		if ( is_wp_error($primary) ) {
			$primary = $this->clone_repo(
				$url,
				$repo_name,
				array(
					'full'                   => ! empty($options['full']),
					'allow_duplicate_remote' => ! empty($options['allow_duplicate_remote']),
				)
			);
			if ( is_wp_error($primary) ) {
				return $primary;
			}
		} elseif ( ! empty($primary['is_worktree']) || $this->normalize_git_remote_url($url) !== $this->normalize_git_remote_url( (string) ( $primary['remote'] ?? '' )) ) {
			return new \WP_Error('remote_workspace_materialization_primary_conflict', sprintf('Workspace primary "%s" does not match the registered remote %s.', $repo_name, $url), array( 'status' => 409 ));
		}

		if ( '' === $branch ) {
			return array(
				'success'              => true,
				'backend'              => 'local_git',
				'handle'               => $repo_name,
				'path'                 => (string) ( $primary['path'] ?? '' ),
				'materialized_primary' => true,
				'message'              => sprintf('Materialized remote workspace primary "%s".', $repo_name),
			);
		}

		$handle   = $repo_name . '@' . $this->slugify_branch($branch);
		$existing = $this->show_repo($handle);
		if ( ! is_wp_error($existing) ) {
			if ( (string) ( $existing['branch'] ?? '' ) !== $branch ) {
				return new \WP_Error('remote_workspace_materialization_worktree_conflict', sprintf('Workspace handle "%s" is already checked out to branch "%s".', $handle, (string) ( $existing['branch'] ?? '' )), array( 'status' => 409 ));
			}
			return array(
				'success'              => true,
				'backend'              => 'local_git',
				'handle'               => $handle,
				'path'                 => (string) ( $existing['path'] ?? '' ),
				'branch'               => $branch,
				'already_materialized' => true,
				'message'              => sprintf('Remote workspace "%s" is already materialized at %s.', $handle, (string) ( $existing['path'] ?? '' )),
			);
		}

		$remote_branch = $this->run_git( (string) ( $primary['path'] ?? '' ), 'ls-remote --heads origin ' . escapeshellarg($branch));
		if ( is_wp_error($remote_branch) ) {
			return $remote_branch;
		}
		$from = '' !== trim( (string) ( $remote_branch['output'] ?? '' ) )
			? 'origin/' . $branch
			: ( '' !== trim( (string) ( $remote['base_ref'] ?? '' ) ) ? (string) $remote['base_ref'] : null );

		$result = $this->worktree_add_request(WorktreeAllocationRequest::from_input(array(
			'repo'                       => $repo_name,
			'branch'                     => $branch,
			'from'                       => $from,
			'inject_context'             => array_key_exists('inject_context', $options) ? (bool) $options['inject_context'] : true,
			'bootstrap'                  => array_key_exists('bootstrap', $options) ? (bool) $options['bootstrap'] : true,
			'allow_stale'                => ! empty($options['allow_stale']),
			'rebase_base'                => ! empty($options['rebase_base']),
			'force'                      => ! empty($options['force']),
			'task'                       => (array) ( $remote['task'] ?? array() ),
			'allow_unverified_freshness' => ! empty($options['allow_unverified_freshness']),
			'require_task_tracker'       => array_key_exists('require_task_tracker', $options) ? (bool) $options['require_task_tracker'] : true,
			'intent'                     => array_filter(array(
				'purpose'        => $remote['purpose'] ?? null,
				'owner_run_ref'  => $remote['owner_run_ref'] ?? null,
				'cleanup_policy' => $remote['cleanup_policy'] ?? null,
			), static fn( $value ) => null !== $value),
		)));
		if ( is_wp_error($result) ) {
			return $result;
		}

		$result['backend']      = 'local_git';
		$result['materialized'] = true;
		return $result;
	}

	/**
	 * Ensure the primary's currently checked-out default branch tracks origin.
	 *
	 * Issue #833: a primary whose default branch has no upstream tracking ref
	 * reports freshness `no_upstream` and cannot be refreshed via
	 * `workspace git pull --allow-primary-refresh`. This sets tracking right
	 * after clone so the primary is refreshable from the start.
	 *
	 * Best-effort and non-fatal: a failure to set tracking must not fail an
	 * otherwise successful clone. No-ops when the branch already tracks, when
	 * HEAD is detached, or when the remote lacks a same-named branch.
	 *
	 * @param  string $repo_path Primary checkout path.
	 * @return void
	 */
	private function ensure_default_branch_tracking( string $repo_path ): void {
		if ( ! is_dir($repo_path . '/.git') ) {
			return;
		}

		$branch = $this->git_get_branch($repo_path);
		if ( null === $branch || '' === $branch || 'HEAD' === $branch ) {
			return;
		}

		// Already tracking? Leave it alone.
		$upstream = $this->run_git($repo_path, 'rev-parse --abbrev-ref --symbolic-full-name @{upstream}');
		if ( ! is_wp_error($upstream) && '' !== trim( (string) ( $upstream['output'] ?? '' )) ) {
			return;
		}

		// Only set tracking when origin has a matching branch.
		$remote_branch = $this->run_git($repo_path, 'ls-remote --heads origin ' . escapeshellarg($branch));
		if ( is_wp_error($remote_branch) || '' === trim( (string) ( $remote_branch['output'] ?? '' )) ) {
			return;
		}

		// Best-effort: ignore failure so clone success is preserved.
		$this->run_git($repo_path, 'branch --set-upstream-to=' . escapeshellarg('origin/' . $branch) . ' ' . escapeshellarg($branch));
	}

	/**
	 * Build a git clone command.
	 *
	 * @param  string $url           Git clone URL.
	 * @param  string $repo_path     Destination path.
	 * @param  bool   $partial_clone Whether to request blobless partial clone.
	 * @param  array<string,string>|null $env Extra process environment.
	 * @return CommandSpec|\WP_Error Command spec.
	 */
	private function build_clone_command( string $url, string $repo_path, bool $partial_clone, ?array $env ): CommandSpec|\WP_Error {
		$args = array( 'git', 'clone', '--progress' );
		if ( $partial_clone ) {
			$args[] = '--filter=blob:none';
		}

		$args[] = $url;
		$args[] = $repo_path;

		$env                        = $env ?? getenv();
		$env                        = is_array($env) ? $env : array();
		$env['GIT_TERMINAL_PROMPT'] = '0';

		return CommandSpec::from_argv($args, array( 'env' => $env ));
	}

	/**
	 * Build additional environment values for git clone.
	 *
	 * @param  string $url     Git clone URL.
	 * @param  array  $options Optional clone options.
	 * @return array<string,string>|null|\WP_Error Extra environment values, null for default environment, or error.
	 */
	private function build_clone_environment( string $url, array $options ): array|null|\WP_Error {
		$auth_token_env = isset($options['auth_token_env']) && is_scalar($options['auth_token_env']) ? trim( (string) $options['auth_token_env']) : '';
		if ( '' === $auth_token_env ) {
			return null;
		}

		if ( ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $auth_token_env) ) {
			return new \WP_Error('invalid_auth_token_env', 'Clone auth token environment variable name is invalid.', array( 'status' => 400 ));
		}

		$token = trim( (string) getenv($auth_token_env));
		if ( '' === $token ) {
			return new \WP_Error('missing_auth_token_env', sprintf('Clone auth token environment variable %s is empty or unavailable.', $auth_token_env), array( 'status' => 400 ));
		}

		$parts = wp_parse_url($url);
		$host  = is_array($parts) && isset($parts['host']) ? strtolower( (string) $parts['host']) : '';
		if ( '' === $host ) {
			return new \WP_Error('unsupported_auth_token_url', 'Clone auth token support requires an HTTPS repository URL.', array( 'status' => 400 ));
		}

		$env = getenv();
		if ( ! is_array($env) ) {
			$env = array();
		}

		$env['GIT_CONFIG_COUNT']   = '1';
		$env['GIT_CONFIG_KEY_0']   = sprintf('http.https://%s/.extraheader', $host);
		$env['GIT_CONFIG_VALUE_0'] = 'AUTHORIZATION: bearer ' . $token;

		return $env;
	}

	/**
	 * Remote HTTP(S) and SSH hosts generally support safe blobless clones; local
	 * paths and file URLs often do not, and are usually test fixtures anyway.
	 *
	 * @param  string $url Git clone URL.
	 * @return bool True when a partial clone should be attempted.
	 */
	private function should_use_partial_clone( string $url ): bool {
		return (bool) preg_match('#^(https?://|git@|ssh://)#', $url);
	}

	/**
	 * Stream a clone command to an optional progress callback.
	 *
	 * @param  CommandSpec   $command           Command spec.
	 * @param  callable|null $progress_callback Optional progress callback.
	 * @param  float         $started_at        Clone start timestamp.
	 * @return array{success: true, output: string}|\WP_Error
	 */
	protected function run_clone_command( CommandSpec $command, ?callable $progress_callback, float $started_at ): array|\WP_Error {
		$result = ProcessRunner::run(
			$command,
			array(
				'error_code'                 => 'clone_failed',
				'poll_interval_microseconds' => 100000,
				'on_output'                  => function ( string $chunk ) use ( $progress_callback, $started_at ): void {
					$this->emit_clone_output($progress_callback, $chunk, $started_at);
				},
			)
		);

		if ( is_wp_error($result) ) {
			$data      = $result->get_error_data();
			$exit_code = is_array($data) ? (int) ( $data['exit_code'] ?? 1 ) : 1;
			$output    = is_array($data) ? (string) ( $data['output'] ?? $result->get_error_message() ) : $result->get_error_message();
			return new \WP_Error(
				'clone_failed',
				sprintf('Git clone failed (exit %d): %s', $exit_code, $output),
				array(
					'status' => 500,
					'output' => $output,
				)
			);
		}

		return array(
			'success' => true,
			'output'  => $result['output'],
		);
	}

	/**
	 * Verify that a successful clone runner result materialized the requested primary.
	 *
	 * @return true|\WP_Error
	 */
	private function validate_clone_target( string $url, string $name, string $repo_path ): true|\WP_Error {
		$phase         = 'post_clone_validation';
		$state         = 'missing';
		$actual_remote = null;
		if ( GitCheckout::exists($repo_path) ) {
			$actual_remote = $this->git_get_remote($repo_path);
			$state         = null === $actual_remote || '' === trim($actual_remote) ? 'incomplete' : 'remote_mismatch';
			if ( null !== $actual_remote && $this->normalize_git_remote_url($url) === $this->normalize_git_remote_url($actual_remote) ) {
				return true;
			}
		} elseif ( is_dir($repo_path) ) {
			$state = 'incomplete';
		}

		$next_steps = array(
			sprintf('Inspect the target: %s', $repo_path),
			sprintf('Confirm it is a checkout of the requested repository: %s', $url),
			sprintf('If the target is safe to discard, remove it explicitly: wp datamachine-code workspace remove %s', $name),
			'Then retry the clone command.',
		);

		return new \WP_Error(
			'clone_postcondition_failed',
			sprintf('Clone postcondition failed during %s for repository %s at %s: target is %s. Next steps: %s', $phase, $url, $repo_path, $state, implode(' ', $next_steps)),
			array(
				'status'           => 500,
				'phase'            => $phase,
				'repository'       => $url,
				'name'             => $name,
				'path'             => $repo_path,
				'validation_state' => $state,
				'actual_remote'    => $actual_remote,
				'next_steps'       => $next_steps,
			)
		);
	}

	/**
	 * Emit normalized clone output chunks.
	 *
	 * @param callable|null $progress_callback Optional progress callback.
	 * @param string        $chunk             Raw process output chunk.
	 * @param float         $started_at        Clone start timestamp.
	 */
	private function emit_clone_output( ?callable $progress_callback, string $chunk, float $started_at ): void {
		$lines = preg_split('/[\r\n]+/', $chunk);
		if ( ! is_array($lines) ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim($line);
			if ( '' === $line ) {
				continue;
			}

			$this->emit_clone_progress($progress_callback, 'git', $line, $started_at);
		}
	}

	/**
	 * Emit one structured clone progress message.
	 *
	 * @param callable|null $progress_callback Optional progress callback.
	 * @param string        $phase             Progress phase.
	 * @param string        $message           Progress message.
	 * @param float         $started_at        Clone start timestamp.
	 */
	private function emit_clone_progress( ?callable $progress_callback, string $phase, string $message, float $started_at ): void {
		if ( null === $progress_callback ) {
			return;
		}

		$progress_callback(
			array(
				'phase'   => $phase,
				'elapsed' => max(0.0, microtime(true) - $started_at),
				'message' => $message,
			)
		);
	}

	/**
	 * Build a recovery-focused error when a clone target exists already.
	 *
	 * @param  string $name      Workspace repo name.
	 * @param  string $repo_path Target path.
	 * @return \WP_Error Error with remediation data.
	 */
	private function clone_target_exists_error( string $name, string $repo_path ): \WP_Error {
		$looks_like_git = is_dir($repo_path . '/.git');
		$state          = $looks_like_git ? 'existing checkout' : 'partial or non-git directory';
		$next_steps     = array(
			sprintf('Inspect the target: %s', $repo_path),
			sprintf('If it is safe to discard, remove it explicitly: wp datamachine-code workspace remove %s', $name),
			'Then retry the clone command.',
		);

		return new \WP_Error(
			'repo_exists',
			sprintf('Clone target already exists as %s: %s. Next steps: %s', $state, $repo_path, implode(' ', $next_steps)),
			array(
				'status'     => 400,
				'path'       => $repo_path,
				'state'      => $state,
				'next_steps' => $next_steps,
			)
		);
	}

	/**
	 * Add recovery guidance when the same remote already has a primary checkout.
	 *
	 * @param  string              $url      Requested clone URL.
	 * @param  string              $name     Requested workspace name.
	 * @param  array<string,mixed> $existing Existing primary checkout summary.
	 * @return \WP_Error Error with remediation data.
	 */
	private function clone_remote_exists_error( string $url, string $name, array $existing ): \WP_Error {
		$existing_name = (string) ( $existing['name'] ?? '' );
		$next_steps    = array(
			sprintf('Reuse existing primary checkout: %s', $existing_name),
			sprintf('Refresh it when needed: %s', $this->primary_refresh_command($existing_name)),
			sprintf('Then create an isolated branch: wp datamachine-code workspace worktree add %s <branch>', $existing_name),
		);

		return new \WP_Error(
			'repo_remote_exists',
			sprintf('A primary checkout for %s already exists as "%s" at %s. Do not clone the same remote as "%s"; refresh/reuse the existing primary instead. Next steps: %s', $url, $existing_name, (string) ( $existing['path'] ?? '' ), $name, implode(' ', $next_steps)),
			array(
				'status'     => 409,
				'url'        => $url,
				'name'       => $name,
				'existing'   => $existing,
				'next_steps' => $next_steps,
			)
		);
	}

	/**
	 * Add recovery guidance to git clone failures.
	 *
	 * @param  \WP_Error $error     Clone process error.
	 * @param  string    $name      Workspace repo name.
	 * @param  string    $repo_path Target path.
	 * @param  string    $url       Git clone URL.
	 * @return \WP_Error Error with remediation data.
	 */
	private function clone_failed_error( \WP_Error $error, string $name, string $repo_path, string $url ): \WP_Error {
		$next_steps = array(
			sprintf('Confirm the repository URL is reachable: %s', $url),
			sprintf('Inspect any partial target: %s', $repo_path),
			sprintf('If the target is safe to discard, remove it explicitly: wp datamachine-code workspace remove %s', $name),
			'Then retry the clone command.',
		);

		$data                 = (array) $error->get_error_data();
		$data['path']         = $repo_path;
		$data['next_steps']   = $next_steps;
		$data['partial_path'] = is_dir($repo_path) ? $repo_path : null;

		return new \WP_Error(
			'clone_failed',
			$error->get_error_message() . ' Next steps: ' . implode(' ', $next_steps),
			$data
		);
	}

	/**
	 * Adopt an existing primary checkout already located in the workspace.
	 *
	 * There is no persistent primary registry today; primary checkouts are
	 * discovered by their on-disk directory names. Adoption is therefore a
	 * non-destructive validation step that makes that convention explicit.
	 *
	 * @param  string      $path Existing checkout path.
	 * @param  string|null $name Workspace name override (derived from basename if null).
	 * @return array{success: bool, name?: string, path?: string, already_adopted?: bool, message?: string}|\WP_Error
	 */
	public function adopt_repo( string $path, ?string $name = null ): array|\WP_Error {
		$path = rtrim(trim($path), '/');
		if ( '' === $path ) {
			return new \WP_Error('missing_path', 'Checkout path is required.', array( 'status' => 400 ));
		}

		if ( ! is_dir($path) || ! is_readable($path) ) {
			return new \WP_Error('adopt_path_unreadable', sprintf('Checkout path does not exist or is not readable: %s', $path), array( 'status' => 400 ));
		}

		$ensure = $this->ensure_exists();
		if ( is_wp_error($ensure) ) {
			return $ensure;
		}

		$validation = $this->validate_containment($path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error('adopt_outside_workspace', 'Only checkouts already under DATAMACHINE_WORKSPACE_PATH can be adopted.', array( 'status' => 400 ));
		}

		$real_path = $validation['real_path'] ?? '';
		if ( '' === $real_path ) {
			return new \WP_Error('adopt_path_unresolved', sprintf('Could not resolve checkout path: %s', $path), array( 'status' => 400 ));
		}

		$git_path = $real_path . '/.git';
		if ( is_file($git_path) ) {
			return new \WP_Error('adopt_linked_worktree', 'Cannot adopt a linked worktree as a primary checkout. Pass the primary checkout path instead.', array( 'status' => 400 ));
		}

		if ( ! is_dir($git_path) ) {
			return new \WP_Error('adopt_not_git_primary', sprintf('Path is not a git primary checkout: %s', $real_path), array( 'status' => 400 ));
		}

		if ( null === $name || '' === trim($name) ) {
			$name = basename($real_path);
		}

		if ( str_contains($name, '@') ) {
			return new \WP_Error('invalid_adopt_name', 'Repository names cannot contain "@". The "@<branch-slug>" suffix is reserved for worktrees.', array( 'status' => 400 ));
		}

		$name = $this->sanitize_name($name);
		if ( '' === $name ) {
			return new \WP_Error('invalid_adopt_name', 'Adopted repository name is empty after sanitization.', array( 'status' => 400 ));
		}

		$expected_path = $this->workspace_path . '/' . $name;
		if ( is_dir($expected_path) ) {
			$expected_real = realpath($expected_path);
			if ( false !== $expected_real && $expected_real !== $real_path ) {
				return new \WP_Error('adopt_name_collision', sprintf('Workspace name "%s" already points at a different directory: %s', $name, $expected_real), array( 'status' => 400 ));
			}
		} else {
			return new \WP_Error('adopt_requires_workspace_path', sprintf('Adoption is non-destructive: %s must already be located at %s. Move or symlink operations are intentionally not performed by v1.', $real_path, $expected_path), array( 'status' => 400 ));
		}

		$this->emit_workspace_changed('adopt', $name, $name, $real_path);

		return array(
			'success'         => true,
			'name'            => $name,
			'path'            => $real_path,
			'already_adopted' => true,
			'message'         => sprintf('Workspace checkout "%s" is already adopted at %s. No filesystem changes were made.', $name, $real_path),
		);
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array{success: bool, message: string}|\WP_Error
	 */
	public function remove_repo( string $handle ): array|\WP_Error {
		$parsed    = $this->parse_handle($handle);
		$repo_path = $this->workspace_path . '/' . $parsed['dir_name'];

		if ( ! is_dir($repo_path) ) {
			return new \WP_Error('repo_not_found', sprintf('Workspace handle "%s" not found.', $parsed['dir_name']), array( 'status' => 404 ));
		}

		// Safety: ensure path is within workspace.
		$validation = $this->validate_containment($repo_path, $this->workspace_path);
		if ( ! $validation['valid'] ) {
			return new \WP_Error('path_traversal', $validation['message'], array( 'status' => 403 ));
		}
		$protection = GitCheckout::deletion_protection($validation['real_path'], $this->workspace_path);
		if ( null !== $protection ) {
			return new \WP_Error($protection['code'], $protection['message'], array( 'status' => 409 ) + $protection);
		}

		$removed = $this->remove_contained_directory_recursive($validation['real_path'], $this->workspace_path, $this->workspace_path);
		if ( is_wp_error($removed) ) {
			return $removed;
		}

		// If we removed a worktree directory but didn't go through `git worktree remove`,
		// prune the registry on the primary so it doesn't keep stale entries.
		if ( $parsed['is_worktree'] ) {
			$primary_path = $this->get_primary_path($parsed['repo']);
			if ( GitCheckout::exists($primary_path) ) {
				WorkspaceMutationLock::with_repo(
					$this->workspace_path,
					$parsed['repo'],
					fn() => $this->run_git($primary_path, 'worktree prune')
				);
			}
			$this->worktree_inventory()->delete($parsed['dir_name']);
		}

		$this->emit_workspace_changed(
			$parsed['is_worktree'] ? 'worktree_remove' : 'remove',
			$parsed['repo'],
			$parsed['dir_name'],
			$repo_path
		);

		return array(
			'success' => true,
			'message' => sprintf('Removed "%s" from workspace.', $parsed['dir_name']),
		);
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * @param  string $handle  Workspace handle.
	 * @param  bool   $refresh Fetch the tracked remote before classifying primary freshness.
	 * @return array{success: bool, name?: string, repo?: string, is_worktree?: bool, is_context?: bool, path?: string|null, branch?: string|null, remote?: string|null, commit?: string|null, dirty?: int, workspace_capacity?: array, primary_freshness?: array|null, workspace_policy?: array}|\WP_Error
	 */
	public function show_repo( string $handle, bool $refresh = false ): array|\WP_Error {
		$show_started            = microtime(true);
		$registry_lookup_started = microtime(true);
		$requested_handle = $handle;
		$context_policy   = null;
		$parsed           = $this->parse_handle($handle);
		$repo_path        = $this->workspace_path . '/' . $parsed['dir_name'];
		$registry_lookup_ms = (int) round(( microtime(true) - $registry_lookup_started ) * 1000);
		$inspection       = WorkspaceTargetInspector::inspect($repo_path, $parsed['dir_name']);
		if ( is_wp_error($inspection) ) {
			return $inspection;
		}

		if ( empty($inspection['exists']) ) {
			$context_policy = WorkspaceAliasResolver::context_policy_for($handle);
		}
		if ( null !== $context_policy ) {
			$target     = (string) ( $context_policy['target'] ?? $handle );
			$parsed     = $this->parse_handle($target);
			$repo_path  = $this->workspace_path . '/' . $parsed['dir_name'];
			$ref        = (string) ( $context_policy['ref'] ?? '' );
			$inspection = WorkspaceTargetInspector::inspect($repo_path, $handle);
			if ( is_wp_error($inspection) ) {
				return $inspection;
			}
			if ( empty($inspection['exists']) ) {
				return array(
					'success'            => true,
					'name'               => (string) $context_policy['alias'],
					'repo'               => (string) ( $context_policy['repo'] ?? $target ),
					'is_worktree'        => false,
					'is_context'         => true,
					'path'               => null,
					'branch'             => '' !== $ref ? $ref : null,
					'remote'             => '' !== (string) ( $context_policy['repo'] ?? '' ) ? GitHubRemote::cloneUrl( (string) $context_policy['repo'] ) : null,
					'commit'             => null,
					'dirty'              => 0,
					'workspace_capacity' => WorktreeDiskBudget::for_routine_read(WorktreeDiskBudget::inspect($this->workspace_path)),
					'workspace_policy'   => WorkspaceAliasResolver::policy_attestation($handle),
				);
			}
			$handle = $target;
		}

		if ( empty($inspection['exists']) && null === $context_policy ) {
			$resolved_handle = $this->resolve_primary_repo_name($handle);
			if ( ! is_wp_error($resolved_handle) && $resolved_handle !== $handle ) {
				$handle     = $resolved_handle;
				$parsed     = $this->parse_handle($handle);
				$repo_path  = $this->workspace_path . '/' . $parsed['dir_name'];
				$inspection = WorkspaceTargetInspector::inspect($repo_path, $parsed['dir_name']);
				if ( is_wp_error($inspection) ) {
					return $inspection;
				}
			}
		}

		if ( empty($inspection['exists']) ) {
			return new \WP_Error('repo_not_found', sprintf('Workspace handle "%s" not found.', $requested_handle), array( 'status' => 404 ));
		}

		$primary_freshness = ! $parsed['is_worktree'] && is_string($inspection['branch_status'] ?? null)
			? $this->build_primary_freshness_report_from_status_output(
				(string) $inspection['branch_status'],
				$parsed['dir_name'],
				isset($inspection['tracking_ref_observed_at']) && is_string($inspection['tracking_ref_observed_at'])
					? $inspection['tracking_ref_observed_at']
					: null
			)
			: null;
		$remote_freshness_started = microtime(true);
		if ( $refresh && is_array($primary_freshness) ) {
			$primary_freshness = $this->refresh_primary_freshness_report($repo_path, $parsed['dir_name'], $primary_freshness);
		}
		$remote_freshness_ms = (int) round(( microtime(true) - $remote_freshness_started ) * 1000);

		$capacity_started = microtime(true);
		$capacity         = WorktreeDiskBudget::for_routine_read(WorktreeDiskBudget::inspect($this->workspace_path));
		$capacity_ms      = (int) round(( microtime(true) - $capacity_started ) * 1000);
		$result = array(
			'success'            => true,
			'name'               => null !== $context_policy ? (string) $context_policy['alias'] : $parsed['dir_name'],
			'repo'               => $parsed['repo'],
			'is_worktree'        => $parsed['is_worktree'],
			'is_context'         => null !== $context_policy,
			'path'               => $repo_path,
			'branch'             => $inspection['branch'] ?? null,
			'remote'             => $inspection['remote'] ?? null,
			'commit'             => $inspection['commit'] ?? null,
			'dirty'              => (int) ( $inspection['dirty'] ?? 0 ),
			'workspace_capacity' => $capacity,
			'primary_freshness'  => $primary_freshness,
		);
		$optional_enrichments_started = microtime(true);
		if ( $parsed['is_worktree'] ) {
			$result['readiness'] = WorktreeContextInjector::bootstrap_readiness(WorktreeContextInjector::get_metadata($parsed['dir_name']));
		}
		if ( null !== $context_policy ) {
			$result['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($handle);
		}
		if ( function_exists('do_action') ) {
			$probe_timings = is_array($inspection['probe_timings_ms'] ?? null) ? $inspection['probe_timings_ms'] : array();
			do_action(
				'datamachine_code_workspace_show_profiled',
				array(
					'handle'     => $requested_handle,
					'timings_ms' => array(
						'registry_lookup'      => $registry_lookup_ms,
						'capacity'             => $capacity_ms,
						'git_status'           => array_sum(array_map('intval', array_intersect_key($probe_timings, array_flip(array( 'branch', 'remote', 'commit', 'status' ))))),
						'remote_freshness'     => $remote_freshness_ms,
						'optional_enrichments' => (int) round(( microtime(true) - $optional_enrichments_started ) * 1000),
						'total'                => (int) round(( microtime(true) - $show_started ) * 1000),
					),
				)
			);
		}

		return $result;
	}
}
