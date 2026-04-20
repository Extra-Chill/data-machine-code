<?php
/**
 * GitSync Submitter
 *
 * Orchestrates the submit() flow — the blessed path for sending local
 * edits upstream as a pull request. Keeps submit logic out of GitSync
 * proper so GitSync stays a clean CRUD surface over bindings.
 *
 * Model: **sticky proposal branch.** Each binding gets exactly one
 * feature branch on the remote (`gitsync/<slug>`). Every submit
 * rebases that branch onto fresh upstream, applies the user's edits,
 * force-pushes with lease, and opens or updates a single PR. The
 * branch represents "the latest proposal from this binding" — it
 * doesn't accumulate stale per-submit branches.
 *
 * Algorithm:
 *   1. Fetch origin so upstream and gitsync/<slug> refs are current.
 *   2. Stash any dirty/staged changes on the pinned branch.
 *   3. Hard-reset the pinned branch to origin/<pinned> so local state
 *      matches upstream exactly.
 *   4. Create (or reset) local gitsync/<slug> at origin/<pinned>.
 *   5. Check out gitsync/<slug>, pop the stash to restore edits.
 *   6. Stage the requested paths (or the full allowed subset if none
 *      specified) and commit.
 *   7. Push gitsync/<slug> to origin with --force-with-lease.
 *   8. Open or update the PR via GitHubAbilities.
 *   9. Switch back to the pinned branch and reset it to upstream so
 *      the working tree is clean and idempotent across runs.
 *
 * Errors at any step trigger best-effort cleanup — we always try to
 * return the working tree to the pinned branch so a failed submit
 * doesn't leave the binding in a half-migrated state.
 *
 * Phase 2 only supports github.com remotes for submit (PR backend is
 * GitHubAbilities). Non-GitHub remotes error with a clear message;
 * a pluggable PR backend is Phase 3+.
 *
 * @package DataMachineCode\GitSync
 * @since   0.8.0
 * @see     https://github.com/Extra-Chill/data-machine-code/issues/38
 */

namespace DataMachineCode\GitSync;

use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

final class GitSyncSubmitter {

	public const BRANCH_PREFIX = 'gitsync/';

	private GitSyncRegistry $registry;

	public function __construct( GitSyncRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Submit local edits as a PR on the sticky proposal branch.
	 *
	 * @param GitSyncBinding      $binding Binding to submit from.
	 * @param array<string, mixed> $args {
	 *     @type string   $message Commit message (required).
	 *     @type string[] $paths   Relative paths to stage. If omitted,
	 *                             stage every dirty file that sits under
	 *                             the binding's allowed_paths roots.
	 *     @type string   $title   PR title. Defaults to the commit subject.
	 *     @type string   $body    PR body. Defaults to a short note.
	 * }
	 * @return array{success: true, slug: string, branch: string, pr: array<string, mixed>, commit: ?string, message: string}|\WP_Error
	 */
	public function submit( GitSyncBinding $binding, array $args ): array|\WP_Error {
		$gate = $this->checkGates( $binding );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$absolute = $binding->resolveAbsolutePath();
		if ( ! is_dir( $absolute . '/.git' ) && ! is_file( $absolute . '/.git' ) ) {
			return new \WP_Error( 'not_a_repo', sprintf( 'Binding "%s" has no git working tree at %s.', $binding->slug, $absolute ), array( 'status' => 409 ) );
		}

		$message = trim( (string) ( $args['message'] ?? '' ) );
		if ( strlen( $message ) < 8 ) {
			return new \WP_Error( 'message_too_short', 'Commit message must be at least 8 characters.', array( 'status' => 400 ) );
		}
		if ( strlen( $message ) > 200 ) {
			return new \WP_Error( 'message_too_long', 'Commit message must be 200 characters or fewer.', array( 'status' => 400 ) );
		}

		$feature_branch = self::BRANCH_PREFIX . $binding->slug;
		$pinned_branch  = $binding->branch;

		// Paths to stage: explicit list, or derived from the dirty set.
		$paths = $this->resolveStagingPaths( $absolute, $binding, $args );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}
		if ( empty( $paths ) ) {
			return new \WP_Error(
				'nothing_to_submit',
				sprintf( 'Binding "%s" has no changes under its allowed_paths.', $binding->slug ),
				array( 'status' => 400 )
			);
		}

		// --- Begin orchestration. Everything from here until the final
		// restore runs inside a try-finally-shaped block so partial
		// failures always attempt to leave the working tree clean.
		$state = array(
			'stashed'          => false,
			'switched_to_feat' => false,
		);

		$result = $this->runOrchestration( $absolute, $binding, $feature_branch, $pinned_branch, $paths, $message, $args, $state );

		// Best-effort restore: always return to the pinned branch, aligned
		// with upstream, working tree clean.
		$this->restorePinned( $absolute, $pinned_branch, $state );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$binding->last_commit = $result['commit'] ?? null;
		$this->registry->save( $binding );

		return $result;
	}

	/**
	 * Check submit-time policy gates.
	 *
	 * Separate from inline checks so tests can exercise each gate cleanly.
	 *
	 * @return true|\WP_Error
	 */
	private function checkGates( GitSyncBinding $binding ): true|\WP_Error {
		if ( empty( $binding->policy['write_enabled'] ) ) {
			return new \WP_Error( 'write_disabled', sprintf( 'Writes are disabled for binding "%s".', $binding->slug ), array( 'status' => 403 ) );
		}
		if ( empty( $binding->policy['push_enabled'] ) ) {
			return new \WP_Error( 'push_disabled', sprintf( 'Pushes are disabled for binding "%s".', $binding->slug ), array( 'status' => 403 ) );
		}

		$allowed = is_array( $binding->policy['allowed_paths'] ?? null ) ? $binding->policy['allowed_paths'] : array();
		if ( empty( $allowed ) ) {
			return new \WP_Error( 'no_allowed_paths', sprintf( 'Binding "%s" has no allowed_paths — nothing can be submitted.', $binding->slug ), array( 'status' => 403 ) );
		}

		if ( ! GitHubRemote::isGitHubRemote( $binding->remote_url ) ) {
			return new \WP_Error(
				'unsupported_remote',
				sprintf( 'submit() requires a github.com remote (got %s). Non-GitHub backends are Phase 3+.', $binding->remote_url ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * The sticky-branch orchestration, factored out so submit() can run
	 * restorePinned() as a finally even when steps mid-flow fail.
	 *
	 * @param array<string, bool> $state By-ref state flags updated so the
	 *                                    restore path knows what to undo.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function runOrchestration(
		string $absolute,
		GitSyncBinding $binding,
		string $feature_branch,
		string $pinned_branch,
		array $paths,
		string $message,
		array $args,
		array &$state
	): array|\WP_Error {
		$pat      = $this->resolvePat();
		$push_url = GitHubRemote::pushUrlWithPat( $binding->remote_url, $pat );

		// 1. Fetch so origin refs are current (pinned branch + feature branch).
		$fetch = GitRunner::run( $absolute, 'fetch origin --prune' );
		if ( is_wp_error( $fetch ) ) {
			return $fetch;
		}

		// 2. Ensure we're on the pinned branch before stashing, so a later
		// stash-pop lands the changes on the feature branch cleanly.
		$current = GitRepo::branch( $absolute );
		if ( null !== $current && $current !== $pinned_branch ) {
			$checkout = GitRunner::run( $absolute, 'checkout ' . escapeshellarg( $pinned_branch ) );
			if ( is_wp_error( $checkout ) ) {
				return $checkout;
			}
		}

		// 3. Stash all changes (including untracked) so reset --hard doesn't
		// nuke them. Skip the stash when the working tree is already clean.
		$dirty = GitRepo::dirtyCount( $absolute );
		if ( $dirty > 0 ) {
			$stash = GitRunner::run( $absolute, 'stash push --include-untracked -m ' . escapeshellarg( 'gitsync-submit-' . $binding->slug ) );
			if ( is_wp_error( $stash ) ) {
				return $stash;
			}
			$state['stashed'] = true;
		}

		// 4. Align local pinned branch with upstream. Fast-forward would be
		// sufficient when clean, but reset --hard guarantees alignment even
		// after we've stashed divergent local state.
		$reset = GitRunner::run( $absolute, 'reset --hard ' . escapeshellarg( 'origin/' . $pinned_branch ) );
		if ( is_wp_error( $reset ) ) {
			return $reset;
		}

		// 5. Create (or reset) the feature branch from the freshly-aligned
		// upstream tip. `checkout -B` replaces any stale local ref.
		$feat = GitRunner::run( $absolute, 'checkout -B ' . escapeshellarg( $feature_branch ) );
		if ( is_wp_error( $feat ) ) {
			return $feat;
		}
		$state['switched_to_feat'] = true;

		// 6. Re-apply the stashed edits onto the feature branch. If pop
		// fails (conflict against upstream move), abort and surface.
		if ( $state['stashed'] ) {
			$pop = GitRunner::run( $absolute, 'stash pop' );
			if ( is_wp_error( $pop ) ) {
				// Leave the stash in place so the user can recover manually.
				return new \WP_Error(
					'stash_conflict',
					'Upstream moved in a way that conflicts with local edits. Your changes are preserved in the stash — resolve manually and retry.',
					array( 'status' => 409, 'detail' => $pop->get_error_message() )
				);
			}
			$state['stashed'] = false;
		}

		// 7. Stage the requested paths.
		foreach ( $paths as $rel ) {
			$stage = GitRunner::run( $absolute, 'add -- ' . escapeshellarg( $rel ) );
			if ( is_wp_error( $stage ) ) {
				return $stage;
			}
		}

		$staged = GitRunner::run( $absolute, 'diff --cached --name-only' );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$staged_lines = array_filter( array_map( 'trim', explode( "\n", (string) ( $staged['output'] ?? '' ) ) ) );
		if ( empty( $staged_lines ) ) {
			return new \WP_Error( 'nothing_staged', 'After staging, no diff detected. Nothing to submit.', array( 'status' => 400 ) );
		}

		// 8. Commit.
		$commit = GitRunner::run( $absolute, 'commit -m ' . escapeshellarg( $message ) );
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}
		$head = GitRepo::head( $absolute );

		// 9. Push the feature branch with --force. We own gitsync/<slug>
		// exclusively (no other writer touches it by convention), so a
		// plain force is safe and matches the sticky-proposal-branch
		// model. --force-with-lease would be belt-and-suspenders but it
		// can't compute a lease when pushing to a URL rather than a
		// named remote, which produces a "stale info" refusal.
		$push_cmd = sprintf(
			'push --force %s %s',
			escapeshellarg( $push_url ),
			escapeshellarg( $feature_branch . ':' . $feature_branch )
		);
		$push = GitRunner::run( $absolute, $push_cmd );
		if ( is_wp_error( $push ) ) {
			return $push;
		}

		// 10. Open or update the PR. PR failure is surfaced but doesn't undo
		// the push — the branch is upstream, the PR can be opened manually.
		$pr = $this->openOrUpdatePullRequest( $binding, $feature_branch, $pinned_branch, $message, $args, $staged_lines, $pat );
		if ( is_wp_error( $pr ) ) {
			return new \WP_Error(
				'pr_failed',
				sprintf(
					'Branch "%s" pushed, but PR open/update failed: %s',
					$feature_branch,
					$pr->get_error_message()
				),
				array(
					'status'    => 502,
					'branch'    => $feature_branch,
					'head'      => $head,
					'staged'    => array_values( $staged_lines ),
					'pr_error'  => $pr->get_error_code(),
				)
			);
		}

		return array(
			'success' => true,
			'slug'    => $binding->slug,
			'branch'  => $feature_branch,
			'commit'  => $head,
			'staged'  => array_values( $staged_lines ),
			'pr'      => $pr,
			'message' => sprintf( 'Submitted %d file(s) from "%s" via PR #%d.', count( $staged_lines ), $binding->slug, (int) ( $pr['number'] ?? 0 ) ),
		);
	}

	/**
	 * Best-effort restore to the pinned branch after submit finishes or
	 * fails partway through.
	 */
	private function restorePinned( string $absolute, string $pinned_branch, array $state ): void {
		if ( $state['switched_to_feat'] ) {
			// Checkout the pinned branch. Ignore errors — we're already in
			// cleanup mode and the user can recover manually if this fails.
			GitRunner::run( $absolute, 'checkout ' . escapeshellarg( $pinned_branch ) );
		}
		if ( $state['stashed'] ) {
			// We never popped the stash — leave it in place so the user can
			// decide what to do. A noisy log keeps them from losing track.
			do_action(
				'datamachine_log',
				'warning',
				sprintf( 'GitSync submit left a stash on binding working tree (%s). Recover via `git stash list`.', $absolute ),
				array( 'context' => 'gitsync-submit' )
			);
		}
	}

	/**
	 * Resolve the stage set for a submit call.
	 *
	 * If the caller passed an explicit `paths` array, validate each one.
	 * Otherwise, derive the stage set from the current dirty list filtered
	 * by the binding's allowed_paths — the "submit everything I've touched
	 * that the policy lets me touch" convenience.
	 *
	 * @param array<string, mixed> $args
	 * @return string[]|\WP_Error
	 */
	private function resolveStagingPaths( string $absolute, GitSyncBinding $binding, array $args ): array|\WP_Error {
		$allowed = is_array( $binding->policy['allowed_paths'] ?? null ) ? $binding->policy['allowed_paths'] : array();

		$explicit = isset( $args['paths'] ) && is_array( $args['paths'] ) ? $args['paths'] : null;
		if ( null !== $explicit ) {
			$clean = array();
			foreach ( $explicit as $raw ) {
				$rel = ltrim( trim( (string) $raw ), '/' );
				if ( '' === $rel ) {
					continue;
				}
				if ( PathSecurity::hasTraversal( $rel ) ) {
					return new \WP_Error( 'path_traversal', sprintf( 'Invalid path: %s', $rel ), array( 'status' => 400 ) );
				}
				if ( PathSecurity::isSensitivePath( $rel ) ) {
					return new \WP_Error( 'sensitive_path', sprintf( 'Refusing to submit sensitive path: %s', $rel ), array( 'status' => 403 ) );
				}
				if ( ! PathSecurity::isPathAllowed( $rel, $allowed ) ) {
					return new \WP_Error( 'path_not_allowed', sprintf( 'Path "%s" is outside allowed_paths.', $rel ), array( 'status' => 403 ) );
				}
				$clean[] = $rel;
			}
			return $clean;
		}

		// Derive from git status --porcelain on the pinned branch.
		$status = GitRunner::run( $absolute, 'status --porcelain' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) ( $status['output'] ?? '' ) ) ) );

		$derived = array();
		foreach ( $lines as $line ) {
			// Porcelain lines: `XY path[ -> newpath]`. Keep it simple — take
			// the tail after the first whitespace, ignoring rename arrows.
			$parts = preg_split( '/\s+/', $line, 2 );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$rel = trim( $parts[1] );
			if ( str_contains( $rel, ' -> ' ) ) {
				$rel = trim( explode( ' -> ', $rel, 2 )[1] );
			}
			if ( '' === $rel ) {
				continue;
			}

			// Silently skip files outside the policy — this is the
			// convenience path, not a validation path.
			if ( PathSecurity::hasTraversal( $rel ) || PathSecurity::isSensitivePath( $rel ) ) {
				continue;
			}
			if ( ! PathSecurity::isPathAllowed( $rel, $allowed ) ) {
				continue;
			}
			$derived[] = $rel;
		}

		return array_values( array_unique( $derived ) );
	}

	/**
	 * Open a PR on the feature branch, or update the existing one.
	 *
	 * Delegates every HTTP call to `GitHubAbilities::apiGet` /
	 * `apiRequest` so the auth headers, JSON decoding, and error
	 * envelope stay identical to the rest of DMC's GitHub surface.
	 *
	 * Phase 2 only targets github.com — caller has already gated.
	 *
	 * @param string[] $staged Relative paths we just committed.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function openOrUpdatePullRequest(
		GitSyncBinding $binding,
		string $feature_branch,
		string $pinned_branch,
		string $commit_message,
		array $args,
		array $staged,
		string $pat
	): array|\WP_Error {
		if ( '' === $pat ) {
			return new \WP_Error( 'missing_pat', 'GitHub PAT not configured — cannot open PR. Configure via data-machine-code settings.', array( 'status' => 500 ) );
		}

		$slug = GitHubRemote::slug( $binding->remote_url );
		if ( null === $slug ) {
			return new \WP_Error( 'unparseable_remote', sprintf( 'Could not parse GitHub owner/repo from %s.', $binding->remote_url ), array( 'status' => 400 ) );
		}

		$owner = explode( '/', $slug )[0];
		$head  = $owner . ':' . $feature_branch;
		$title = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = $commit_message;
		}
		$body = trim( (string) ( $args['body'] ?? '' ) );
		if ( '' === $body ) {
			$body = $this->buildDefaultBody( $binding, $commit_message, $staged );
		}

		// Look up existing PR on this head.
		$existing = \DataMachineCode\Abilities\GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'pulls' ),
			array(
				'head'     => $head,
				'state'    => 'open',
				'per_page' => 5,
			),
			$pat
		);
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$existing_pr = is_array( $existing['data'] ?? null ) && ! empty( $existing['data'] ) ? $existing['data'][0] : null;

		if ( null !== $existing_pr ) {
			$patched = \DataMachineCode\Abilities\GitHubAbilities::apiRequest(
				'PATCH',
				GitHubRemote::apiUrl( $slug, 'pulls/' . (int) $existing_pr['number'] ),
				array(
					'title' => $title,
					'body'  => $body,
				),
				$pat
			);
			if ( is_wp_error( $patched ) ) {
				return $patched;
			}
			$patched_data = is_array( $patched['data'] ?? null ) ? $patched['data'] : array();
			return array(
				'number'   => (int) ( $patched_data['number'] ?? $existing_pr['number'] ),
				'html_url' => (string) ( $patched_data['html_url'] ?? $existing_pr['html_url'] ),
				'state'    => (string) ( $patched_data['state'] ?? 'open' ),
				'action'   => 'updated',
			);
		}

		$created = \DataMachineCode\Abilities\GitHubAbilities::apiRequest(
			'POST',
			GitHubRemote::apiUrl( $slug, 'pulls' ),
			array(
				'title' => $title,
				'body'  => $body,
				'head'  => $feature_branch,
				'base'  => $pinned_branch,
			),
			$pat
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$created_data = is_array( $created['data'] ?? null ) ? $created['data'] : array();
		return array(
			'number'   => (int) ( $created_data['number'] ?? 0 ),
			'html_url' => (string) ( $created_data['html_url'] ?? '' ),
			'state'    => (string) ( $created_data['state'] ?? 'open' ),
			'action'   => 'opened',
		);
	}

	private function buildDefaultBody( GitSyncBinding $binding, string $commit_message, array $staged ): string {
		$files = '';
		foreach ( array_slice( $staged, 0, 25 ) as $rel ) {
			$files .= "- `{$rel}`\n";
		}
		if ( count( $staged ) > 25 ) {
			$files .= sprintf( "- …and %d more\n", count( $staged ) - 25 );
		}
		return <<<BODY
Proposed by GitSync binding **{$binding->slug}** from local edits.

## Commit
{$commit_message}

## Files
{$files}
---
*Opened via `datamachine/gitsync-submit`. Re-running submit updates this PR in place.*
BODY;
	}

	/**
	 * Fetch the GitHub PAT from DMC's settings, or empty string if
	 * the GitHubAbilities class isn't loaded. The caller decides how
	 * to react to an empty PAT — push may still succeed via system
	 * credential.helper, but PR creation requires one.
	 */
	private function resolvePat(): string {
		if ( ! class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
			return '';
		}
		return (string) \DataMachineCode\Abilities\GitHubAbilities::getPat();
	}
}
