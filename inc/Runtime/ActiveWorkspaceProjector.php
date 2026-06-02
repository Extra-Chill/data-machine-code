<?php
/**
 * Active Workspace Projector
 *
 * Projects active workspace identity (repo, handle, branch, path) into
 * Data Machine's engine_data snapshot at job initialization so AI
 * directives, abilities, and tool calls can read which repo the
 * current job is operating against.
 *
 * == How identity arrives ==
 *
	 * Callers pass workspace
 * identity via `initial_data.active_workspace` on the
 * datamachine/run-flow ability call. The filter callback below reads
 * that input, looks up the matching worktree metadata via
 * WorktreeContextInjector, and stamps the enriched entry into
 * engine_data so any directive or tool can read it via
 * $engine->get( 'active_workspace' ).
 *
 * No automatic "current workspace" tracking. Identity is always
 * explicit so concurrent jobs cannot clobber each other and the
 * extension surface is one clearly-documented input on run-flow.
 *
 * == Schema ==
 *
 * The projected entry has a stable shape suitable for any consumer:
 *
 *   active_workspace: {
 *     handle:        "<repo>@<branch>" or "<repo>" for primary
 *     repo:          short name (last segment of handle, no @branch)
 *     owner:         GitHub owner (when handle includes owner/repo format)
 *     full_name:     "owner/repo" when both are known
 *     branch:        worktree branch, omitted for primary
 *     path:          absolute filesystem path to the worktree
 *     primary:       true when the handle is the primary checkout
 *     origin_site:   site that created the worktree, when known
 *     origin_agent:  agent slug that created the worktree, when known
 *     task_url:      linked task URL (issue/PR) when set
 *     pr_url:        linked PR URL, when set
 *   }
 *
 * Missing fields are omitted (not nulled) so consumers can use
 * isset() checks cleanly.
 *
 * == Caller contract ==
 *
 * Minimum required to activate the projection: pass an
 * active_workspace.handle to run-flow:
 *
 *   wp_get_ability( 'datamachine/run-flow' )->execute( array(
 *       'flow_id'      => $flow_id,
 *       'initial_data' => array(
 *           'active_workspace' => array(
 *               'handle' => 'extrachill-artist-platform@docs/agent-run-123',
 *           ),
 *       ),
 *   ) );
 *
 * Any additional fields the caller supplies (e.g. owner, full_name) are
 * preserved verbatim and override fields derived from worktree metadata.
 *
 * == Layer purity ==
 *
 * This class talks about workspaces, not docs, voice, or any consumer
 * concept. Downstream plugins (e.g. extrachill-docs) consume the
 * active_workspace entry to make their own routing decisions. DMC stays
 * generic.
 *
 * @package DataMachineCode\Runtime
 * @since   0.46.0
 */

namespace DataMachineCode\Runtime;

use DataMachineCode\Workspace\WorktreeContextInjector;

defined('ABSPATH') || exit;

class ActiveWorkspaceProjector {



	/**
	 * Bootstrap: register the engine_snapshot filter.
	 *
	 * @since  0.46.0
	 * @return void
	 */
	public static function register(): void {
		add_filter(
			'datamachine_engine_snapshot',
			array( self::class, 'project_into_snapshot' ),
			20,
			4
		);
	}

	/**
	 * Filter callback — enrich the engine snapshot with active_workspace.
	 *
	 * Reads explicit active_workspace input that the caller passed via
	 * initial_data on run-flow. Looks up the worktree metadata to fill
	 * in fields the caller did not supply. No-op when no handle was
	 * passed.
	 *
	 * @since 0.46.0
	 *
	 * @param  array $snapshot Engine snapshot about to be persisted.
	 * @param  int   $job_id   Job being initialized.
	 * @param  array $flow     Flow row.
	 * @param  array $pipeline Pipeline row.
	 * @return array Modified snapshot.
	 */
	public static function project_into_snapshot( array $snapshot, int $job_id, array $flow, array $pipeline ): array {   // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$explicit = is_array($snapshot['active_workspace'] ?? null)
		? (array) $snapshot['active_workspace']
		: array();

		$handle = (string) ( $explicit['handle'] ?? '' );
		if ( '' === $handle ) {
			// No handle — preserve any pre-existing entry (e.g. hand-set
			// by tests) and exit.
			return $snapshot;
		}

		$entry = self::build_entry($handle, $explicit);
		if ( empty($entry) ) {
			return $snapshot;
		}

		$snapshot['active_workspace'] = $entry;

		return $snapshot;
	}

	/**
	 * Build the active_workspace entry from a handle and optional caller overrides.
	 *
	 * @since 0.46.0
	 *
	 * @param  string              $handle    Workspace handle.
	 * @param  array<string,mixed> $overrides Caller-provided fields to preserve.
	 * @return array<string,mixed>
	 */
	private static function build_entry( string $handle, array $overrides ): array {
		$metadata   = WorktreeContextInjector::get_metadata($handle);
		$is_primary = ! str_contains($handle, '@');

		$entry = array(
			'handle'  => $handle,
			'primary' => $is_primary,
		);

		// Derive repo + branch from handle.
		$handle_parts = explode('@', $handle, 2);
		$repo_slug    = $handle_parts[0] ?? '';
		if ( '' !== $repo_slug ) {
			$entry['repo'] = $repo_slug;
		}
		if ( ! $is_primary && isset($handle_parts[1]) && '' !== $handle_parts[1] ) {
			$entry['branch'] = $handle_parts[1];
		}

		// Enrich from persisted metadata.
		if ( is_array($metadata) ) {
			foreach ( array( 'repo', 'branch', 'path', 'origin_site', 'origin_agent', 'pr_url' ) as $field ) {
				if ( isset($metadata[ $field ]) && '' !== (string) $metadata[ $field ] ) {
					$entry[ $field ] = (string) $metadata[ $field ];
				}
			}

			$task = is_array($metadata['origin_task'] ?? null) ? $metadata['origin_task'] : array();
			if ( isset($task['task_url']) && '' !== (string) $task['task_url'] ) {
				$entry['task_url'] = (string) $task['task_url'];
			}
		}

		// If repo looks like "owner/repo" (common when callers pass full_name
		// or when the handle is "owner/repo"), split it into owner + repo.
		$repo_value = (string) ( $entry['repo'] ?? '' );
		if ( str_contains($repo_value, '/') ) {
			$parts              = explode('/', $repo_value, 2);
			$entry['owner']     = $parts[0];
			$entry['repo']      = $parts[1];
			$entry['full_name'] = $repo_value;
		}

		// Caller overrides win for scalar fields (preserve explicit input).
		foreach ( $overrides as $key => $value ) {
			if ( is_string($key) && '' !== $key && ! is_array($value) ) {
				$entry[ $key ] = $value;
			}
		}

		// Synthesize full_name from owner + repo when both present.
		if ( empty($entry['full_name']) && ! empty($entry['owner']) && ! empty($entry['repo']) ) {
			$entry['full_name'] = $entry['owner'] . '/' . $entry['repo'];
		}

		/**
		 * Filter the projected active_workspace entry before it lands in engine_data.
		 *
		 * Lets extensions enrich or override fields. Returning a non-array
		 * silently preserves the projector's own value.
		 *
		 * @since 0.46.0
		 *
		 * @param array<string,mixed> $entry  Projected entry.
		 * @param string              $handle Source handle.
		 */
		$filtered = apply_filters('datamachine_code_active_workspace', $entry, $handle);
		if ( is_array($filtered) ) {
			$entry = $filtered;
		}

		return $entry;
	}
}
