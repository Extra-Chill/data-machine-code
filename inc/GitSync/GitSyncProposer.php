<?php
/**
 * GitSync Proposer
 *
 * Pushes local changes upstream via the GitHub Contents + Git Data APIs.
 * Two modes:
 *
 *   submit() — writes changes to the binding's sticky proposal branch
 *              (`gitsync/<slug>`) and opens or updates a PR against the
 *              pinned branch. Default flow for content sync.
 *
 *   push()   — writes changes directly to the pinned branch (no PR).
 *              Two-key auth required: policy.write_enabled AND
 *              policy.safe_direct_push must both be true. Meant for
 *              personal-wiki / single-owner scenarios.
 *
 * No git binary involved. Every operation is `wp_remote_request` +
 * `file_get_contents` on local files, so this works identically on
 * WordPress.com, VPS, or laptop.
 *
 * Per-file commits — one `createOrUpdateFile` per changed file means N
 * commits per submit. PR reviewers still see the aggregate diff; the
 * extra commits are tolerable. Optimization path (single tree + commit
 * via the Git Data API) noted for a future pass.
 *
 * @package DataMachineCode\GitSync
 * @since   0.8.0
 */

namespace DataMachineCode\GitSync;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

final class GitSyncProposer {

	public const BRANCH_PREFIX = 'gitsync/';

	private GitSyncRegistry $registry;

	public function __construct( GitSyncRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Submit local edits as a PR on the sticky proposal branch.
	 *
	 * @param GitSyncBinding      $binding
	 * @param array<string, mixed> $args {
	 *     @type string   $message Commit-message base (also used as PR title default).
	 *     @type string[] $paths   Optional explicit path list. If omitted,
	 *                             every changed file under allowed_paths
	 *                             is included.
	 *     @type string   $title   PR title. Defaults to $message.
	 *     @type string   $body    PR body. Defaults to an auto-summary.
	 * }
	 * @return array<string, mixed>|\WP_Error
	 */
	public function submit( GitSyncBinding $binding, array $args ): array|\WP_Error {
		$gate = $this->commonGates( $binding );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$message = trim( (string) ( $args['message'] ?? '' ) );
		$message_err = $this->validateMessage( $message );
		if ( is_wp_error( $message_err ) ) {
			return $message_err;
		}

		$ctx = $this->buildContext( $binding );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$changes = $this->resolveChanges( $binding, $ctx, $args );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		if ( empty( $changes ) ) {
			return new \WP_Error(
				'nothing_to_submit',
				sprintf( 'Binding "%s" has no changes under its allowed_paths.', $binding->slug ),
				array( 'status' => 400 )
			);
		}

		$feature_branch = self::BRANCH_PREFIX . $binding->slug;

		// Ensure the feature branch exists and points at the current
		// pinned-branch HEAD. If it already existed (previous submit),
		// force it back to upstream so per-file SHAs line up and the PR
		// diff stays focused on this submit's changes.
		$reset = $this->ensureFeatureBranchAtBase( $ctx['slug'], $feature_branch, $ctx['base_sha'], $ctx['pat'] );
		if ( is_wp_error( $reset ) ) {
			return $reset;
		}

		// When we just reset the feature branch to base, every file's SHA
		// on the feature branch matches its SHA on base. We already have
		// those SHAs in $ctx['upstream'].
		$commits = array();
		foreach ( $changes as $change ) {
			$result = $this->putFile(
				$ctx['slug'],
				$change['path'],
				$change['content'],
				sprintf( '%s: %s', $message, $change['path'] ),
				$feature_branch,
				$ctx['upstream'][ $change['path'] ] ?? null,
				$ctx['pat']
			);
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'upload_failed',
					sprintf( 'Commit of %s to %s failed: %s', $change['path'], $feature_branch, $result->get_error_message() ),
					array( 'status' => 502, 'path' => $change['path'], 'branch' => $feature_branch )
				);
			}
			$commits[] = array(
				'path'   => $change['path'],
				'commit' => (string) ( $result['commit']['sha'] ?? '' ),
			);
		}

		$pr = $this->openOrUpdatePullRequest( $ctx['slug'], $binding, $feature_branch, $message, $args, array_column( $changes, 'path' ), $ctx['pat'] );
		if ( is_wp_error( $pr ) ) {
			return new \WP_Error(
				'pr_failed',
				sprintf( 'Branch "%s" updated, but PR open/update failed: %s', $feature_branch, $pr->get_error_message() ),
				array( 'status' => 502, 'branch' => $feature_branch, 'commits' => $commits, 'pr_error' => $pr->get_error_code() )
			);
		}

		$binding->last_commit = end( $commits )['commit'] ?? $binding->last_commit;
		$this->registry->save( $binding );

		return array(
			'success' => true,
			'slug'    => $binding->slug,
			'branch'  => $feature_branch,
			'commits' => $commits,
			'pr'      => $pr,
			'message' => sprintf( 'Proposed %d file(s) on "%s" via PR #%d.', count( $commits ), $binding->slug, (int) ( $pr['number'] ?? 0 ) ),
		);
	}

	/**
	 * Commit local changes directly to the pinned branch — no PR.
	 *
	 * Requires both policy.write_enabled and policy.safe_direct_push.
	 * The second key is intentional friction: bindings default to the
	 * PR flow, and direct-to-pinned push should require a deliberate
	 * second opt-in.
	 *
	 * @param GitSyncBinding      $binding
	 * @param array<string, mixed> $args {
	 *     @type string   $message Commit message base.
	 *     @type string[] $paths   Optional explicit path list.
	 * }
	 * @return array<string, mixed>|\WP_Error
	 */
	public function push( GitSyncBinding $binding, array $args ): array|\WP_Error {
		$gate = $this->commonGates( $binding );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		if ( empty( $binding->policy['safe_direct_push'] ) ) {
			return new \WP_Error(
				'direct_push_blocked',
				sprintf(
					'Direct push to the pinned branch is blocked for binding "%s". Set policy.safe_direct_push=true, or use submit() to open a PR instead.',
					$binding->slug
				),
				array( 'status' => 403 )
			);
		}

		$message = trim( (string) ( $args['message'] ?? '' ) );
		$message_err = $this->validateMessage( $message );
		if ( is_wp_error( $message_err ) ) {
			return $message_err;
		}

		$ctx = $this->buildContext( $binding );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$changes = $this->resolveChanges( $binding, $ctx, $args );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		if ( empty( $changes ) ) {
			return new \WP_Error( 'nothing_to_push', sprintf( 'Binding "%s" has no changes under its allowed_paths.', $binding->slug ), array( 'status' => 400 ) );
		}

		$commits = array();
		foreach ( $changes as $change ) {
			$result = $this->putFile(
				$ctx['slug'],
				$change['path'],
				$change['content'],
				sprintf( '%s: %s', $message, $change['path'] ),
				$binding->branch,
				$ctx['upstream'][ $change['path'] ] ?? null,
				$ctx['pat']
			);
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'upload_failed',
					sprintf( 'Commit of %s to %s failed: %s', $change['path'], $binding->branch, $result->get_error_message() ),
					array( 'status' => 502, 'path' => $change['path'], 'branch' => $binding->branch )
				);
			}
			$commits[] = array(
				'path'   => $change['path'],
				'commit' => (string) ( $result['commit']['sha'] ?? '' ),
			);
		}

		$binding->last_commit = end( $commits )['commit'] ?? $binding->last_commit;
		$this->registry->save( $binding );

		return array(
			'success' => true,
			'slug'    => $binding->slug,
			'branch'  => $binding->branch,
			'commits' => $commits,
			'message' => sprintf( 'Pushed %d file(s) directly to "%s" on "%s".', count( $commits ), $binding->branch, $binding->slug ),
		);
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	private function commonGates( GitSyncBinding $binding ): true|\WP_Error {
		if ( empty( $binding->policy['write_enabled'] ) ) {
			return new \WP_Error(
				'write_disabled',
				sprintf( 'Writes are disabled for binding "%s" (policy.write_enabled=false).', $binding->slug ),
				array( 'status' => 403 )
			);
		}
		$allowed = is_array( $binding->policy['allowed_paths'] ?? null ) ? $binding->policy['allowed_paths'] : array();
		if ( empty( $allowed ) ) {
			return new \WP_Error(
				'no_allowed_paths',
				sprintf( 'Binding "%s" has no allowed_paths — nothing can be uploaded.', $binding->slug ),
				array( 'status' => 403 )
			);
		}
		if ( ! GitHubRemote::isGitHubRemote( $binding->remote_url ) ) {
			return new \WP_Error(
				'unsupported_remote',
				sprintf( 'GitSync requires a github.com remote (got %s).', $binding->remote_url ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	private function validateMessage( string $message ): true|\WP_Error {
		if ( '' === $message ) {
			return new \WP_Error( 'missing_message', 'Commit/PR message is required.', array( 'status' => 400 ) );
		}
		if ( strlen( $message ) < 8 ) {
			return new \WP_Error( 'message_too_short', 'Message must be at least 8 characters.', array( 'status' => 400 ) );
		}
		if ( strlen( $message ) > 200 ) {
			return new \WP_Error( 'message_too_long', 'Message must be 200 characters or fewer.', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Assemble the context every write path needs up front:
	 *   - resolved absolute path under ABSPATH
	 *   - GitHub PAT
	 *   - owner/repo slug
	 *   - pinned-branch HEAD SHA
	 *   - upstream tree (path → blob sha) for diffing against local disk
	 *
	 * @return array{absolute:string,pat:string,slug:string,base_sha:string,upstream:array<string,string>}|\WP_Error
	 */
	private function buildContext( GitSyncBinding $binding ): array|\WP_Error {
		$slug = GitHubRemote::slug( $binding->remote_url );
		if ( null === $slug ) {
			return new \WP_Error( 'unparseable_remote', sprintf( 'Cannot parse GitHub owner/repo from %s.', $binding->remote_url ), array( 'status' => 400 ) );
		}

		$pat = (string) GitHubAbilities::getPat();
		if ( '' === $pat ) {
			return new \WP_Error( 'missing_pat', 'GitHub PAT not configured — cannot write.', array( 'status' => 500 ) );
		}

		$absolute = $binding->resolveAbsolutePath();
		if ( ! is_dir( $absolute ) ) {
			return new \WP_Error( 'missing_working_dir', sprintf( 'Local directory %s does not exist. Pull first.', $absolute ), array( 'status' => 404 ) );
		}

		$ref = GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'git/ref/heads/' . rawurlencode( $binding->branch ) ),
			array(),
			$pat
		);
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		$base_sha = (string) ( $ref['data']['object']['sha'] ?? '' );
		if ( '' === $base_sha ) {
			return new \WP_Error( 'missing_base_sha', sprintf( 'Could not resolve SHA for branch "%s".', $binding->branch ), array( 'status' => 502 ) );
		}

		$tree = GitHubRemote::fetchTree( $slug, $binding->branch, $pat );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$upstream = $tree['blobs'];

		return array(
			'absolute' => $absolute,
			'pat'      => $pat,
			'slug'     => $slug,
			'base_sha' => $base_sha,
			'upstream' => $upstream,
		);
	}

	/**
	 * Determine which files to upload.
	 *
	 * Explicit `$args['paths']` → validate + load each; otherwise scan the
	 * binding's local path recursively, filter to `allowed_paths`, keep
	 * only files whose git-blob SHA differs from upstream.
	 *
	 * @param array{absolute:string,upstream:array<string,string>} $ctx
	 * @return array<int,array{path:string,content:string}>|\WP_Error
	 */
	private function resolveChanges( GitSyncBinding $binding, array $ctx, array $args ): array|\WP_Error {
		$allowed = is_array( $binding->policy['allowed_paths'] ?? null ) ? $binding->policy['allowed_paths'] : array();

		$explicit = isset( $args['paths'] ) && is_array( $args['paths'] ) ? $args['paths'] : null;
		if ( null !== $explicit ) {
			$changes = array();
			foreach ( $explicit as $raw ) {
				$rel = ltrim( trim( (string) $raw ), '/' );
				if ( '' === $rel ) {
					continue;
				}
				if ( PathSecurity::hasTraversal( $rel ) ) {
					return new \WP_Error( 'path_traversal', sprintf( 'Invalid path: %s', $rel ), array( 'status' => 400 ) );
				}
				if ( PathSecurity::isSensitivePath( $rel ) ) {
					return new \WP_Error( 'sensitive_path', sprintf( 'Refusing to upload sensitive path: %s', $rel ), array( 'status' => 403 ) );
				}
				if ( ! PathSecurity::isPathAllowed( $rel, $allowed ) ) {
					return new \WP_Error( 'path_not_allowed', sprintf( 'Path "%s" is outside allowed_paths.', $rel ), array( 'status' => 403 ) );
				}
				$content = $this->readLocal( $ctx['absolute'], $rel );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				if ( GitHubRemote::blobSha( $content ) === ( $ctx['upstream'][ $rel ] ?? null ) ) {
					// No diff against upstream; skip this explicitly-requested file.
					continue;
				}
				$changes[] = array( 'path' => $rel, 'content' => $content );
			}
			return $changes;
		}

		// Derived mode: scan the local dir, filter by allowed_paths + sensitive,
		// pick files whose SHA differs from upstream.
		$changes = array();
		foreach ( $this->iterateLocalFiles( $ctx['absolute'] ) as $rel ) {
			if ( PathSecurity::hasTraversal( $rel ) || PathSecurity::isSensitivePath( $rel ) ) {
				continue;
			}
			if ( ! PathSecurity::isPathAllowed( $rel, $allowed ) ) {
				continue;
			}
			$content = $this->readLocal( $ctx['absolute'], $rel );
			if ( is_wp_error( $content ) ) {
				continue;
			}
			$local_sha = GitHubRemote::blobSha( $content );
			if ( $local_sha === ( $ctx['upstream'][ $rel ] ?? null ) ) {
				continue;
			}
			$changes[] = array( 'path' => $rel, 'content' => $content );
		}
		return $changes;
	}

	private function readLocal( string $absolute, string $rel ): string|\WP_Error {
		$full = $absolute . '/' . $rel;
		if ( ! is_file( $full ) ) {
			return new \WP_Error( 'missing_file', sprintf( 'Local file %s does not exist.', $full ), array( 'status' => 404 ) );
		}
		$content = file_get_contents( $full );
		if ( false === $content ) {
			return new \WP_Error( 'read_failed', sprintf( 'Could not read %s.', $full ), array( 'status' => 500 ) );
		}
		return $content;
	}

	/**
	 * Iterate every file under $absolute recursively, yielding paths
	 * relative to $absolute with forward slashes.
	 *
	 * @return iterable<string>
	 */
	private function iterateLocalFiles( string $absolute ): iterable {
		if ( ! is_dir( $absolute ) ) {
			return array();
		}
		$rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $absolute, \RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $rii as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = str_replace( '\\', '/', $file->getPathname() );
			$rel  = ltrim( substr( $path, strlen( $absolute ) ), '/' );
			if ( '' === $rel ) {
				continue;
			}
			yield $rel;
		}
	}

	/**
	 * Ensure the sticky feature branch exists and points at $base_sha.
	 *
	 * - Branch missing → create it via POST git/refs.
	 * - Branch exists at other SHA → force-update via PATCH git/refs/:ref.
	 * - Branch already at $base_sha → no-op.
	 */
	private function ensureFeatureBranchAtBase( string $slug, string $feature_branch, string $base_sha, string $pat ): true|\WP_Error {
		$ref = GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'git/ref/heads/' . rawurlencode( $feature_branch ) ),
			array(),
			$pat
		);

		if ( is_wp_error( $ref ) ) {
			// Not-found → create it fresh.
			if ( 'github_not_found' === $ref->get_error_code() ) {
				$created = GitHubAbilities::apiRequest(
					'POST',
					GitHubRemote::apiUrl( $slug, 'git/refs' ),
					array(
						'ref' => 'refs/heads/' . $feature_branch,
						'sha' => $base_sha,
					),
					$pat
				);
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				return true;
			}
			return $ref;
		}

		$current = (string) ( $ref['data']['object']['sha'] ?? '' );
		if ( $current === $base_sha ) {
			return true;
		}

		$patched = GitHubAbilities::apiRequest(
			'PATCH',
			GitHubRemote::apiUrl( $slug, 'git/refs/heads/' . rawurlencode( $feature_branch ) ),
			array(
				'sha'   => $base_sha,
				'force' => true,
			),
			$pat
		);
		if ( is_wp_error( $patched ) ) {
			return $patched;
		}
		return true;
	}

	/**
	 * PUT a single file's contents via the Contents API, creating or
	 * updating as appropriate. Returns the API response body.
	 */
	private function putFile( string $slug, string $path, string $content, string $message, string $branch, ?string $existing_sha, string $pat ): array|\WP_Error {
		$body = array(
			'message' => $message,
			'content' => base64_encode( $content ),
			'branch'  => $branch,
		);
		if ( null !== $existing_sha ) {
			$body['sha'] = $existing_sha;
		}

		$response = GitHubAbilities::apiRequest(
			'PUT',
			GitHubRemote::apiUrl( $slug, 'contents/' . ltrim( $path, '/' ) ),
			$body,
			$pat
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	/**
	 * Open a new PR or update the existing one on the feature branch.
	 *
	 * @param string[] $changed_paths Paths that went into the submit (for
	 *                                 the default PR body summary).
	 */
	private function openOrUpdatePullRequest(
		string $slug,
		GitSyncBinding $binding,
		string $feature_branch,
		string $commit_message,
		array $args,
		array $changed_paths,
		string $pat
	): array|\WP_Error {
		$owner = explode( '/', $slug )[0];
		$head  = $owner . ':' . $feature_branch;
		$title = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = $commit_message;
		}
		$body = trim( (string) ( $args['body'] ?? '' ) );
		if ( '' === $body ) {
			$body = $this->buildDefaultBody( $binding, $commit_message, $changed_paths );
		}

		$existing = GitHubAbilities::apiGet(
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
		$existing_list = is_array( $existing['data'] ?? null ) ? $existing['data'] : array();
		$existing_pr   = ! empty( $existing_list ) ? $existing_list[0] : null;

		if ( null !== $existing_pr ) {
			$patched = GitHubAbilities::apiRequest(
				'PATCH',
				GitHubRemote::apiUrl( $slug, 'pulls/' . (int) $existing_pr['number'] ),
				array( 'title' => $title, 'body' => $body ),
				$pat
			);
			if ( is_wp_error( $patched ) ) {
				return $patched;
			}
			$data = is_array( $patched['data'] ?? null ) ? $patched['data'] : array();
			return array(
				'number'   => (int) ( $data['number'] ?? $existing_pr['number'] ),
				'html_url' => (string) ( $data['html_url'] ?? $existing_pr['html_url'] ),
				'state'    => (string) ( $data['state'] ?? 'open' ),
				'action'   => 'updated',
			);
		}

		$created = GitHubAbilities::apiRequest(
			'POST',
			GitHubRemote::apiUrl( $slug, 'pulls' ),
			array(
				'title' => $title,
				'body'  => $body,
				'head'  => $feature_branch,
				'base'  => $binding->branch,
			),
			$pat
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$data = is_array( $created['data'] ?? null ) ? $created['data'] : array();
		return array(
			'number'   => (int) ( $data['number'] ?? 0 ),
			'html_url' => (string) ( $data['html_url'] ?? '' ),
			'state'    => (string) ( $data['state'] ?? 'open' ),
			'action'   => 'opened',
		);
	}

	private function buildDefaultBody( GitSyncBinding $binding, string $message, array $paths ): string {
		$files = '';
		foreach ( array_slice( $paths, 0, 25 ) as $rel ) {
			$files .= "- `{$rel}`\n";
		}
		if ( count( $paths ) > 25 ) {
			$files .= sprintf( "- …and %d more\n", count( $paths ) - 25 );
		}
		return <<<BODY
Proposed by GitSync binding **{$binding->slug}** from local edits.

## Commit
{$message}

## Files
{$files}
---
*Opened via `datamachine/gitsync-submit`. Re-running submit updates this PR in place.*
BODY;
	}

}
