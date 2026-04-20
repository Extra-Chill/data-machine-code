<?php
/**
 * GitSync Fetcher
 *
 * Pulls files from a GitHub repository onto the local filesystem using
 * the Contents API — no git binary, no .git/ directory, works on any
 * WordPress host that can make outbound HTTPS.
 *
 * Strategy:
 *   1. Ask GitHub for the recursive tree of the pinned branch.
 *   2. For each blob, compare its git-blob SHA to the one we'd compute
 *      from the local file (if any). Download + write only on mismatch.
 *   3. After the sync, any path the binding's `pulled_paths` recorded
 *      that's no longer in the tree gets deleted locally — upstream
 *      removals propagate down, but consumer-added local files (paths
 *      we never pulled) stay untouched.
 *
 * Conflict handling (when local file exists with a different SHA than
 * both upstream and what we last pulled — i.e. the consumer edited a
 * tracked file locally between pulls):
 *   - fail:          abort, surface the conflict list, leave files alone
 *   - upstream_wins: overwrite the local file from upstream
 *   - manual:        skip the conflicting file, surface it in the result
 *
 * @package DataMachineCode\GitSync
 * @since   0.8.0
 */

namespace DataMachineCode\GitSync;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachineCode\Support\GitHubRemote;
use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

final class GitSyncFetcher {

	private GitSyncRegistry $registry;

	public function __construct( GitSyncRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Pull the binding's pinned branch to local disk.
	 *
	 * @param GitSyncBinding $binding
	 * @param array<string, mixed> $args Optional: `allow_dirty` (override
	 *                                    conflict policy for this call).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function pull( GitSyncBinding $binding, array $args = array() ): array|\WP_Error {
		$slug = GitHubRemote::slug( $binding->remote_url );
		if ( null === $slug ) {
			return new \WP_Error( 'unparseable_remote', sprintf( 'Cannot parse GitHub owner/repo from %s.', $binding->remote_url ), array( 'status' => 400 ) );
		}

		$pat = (string) GitHubAbilities::getPat();
		if ( '' === $pat ) {
			return new \WP_Error( 'missing_pat', 'GitHub PAT not configured.', array( 'status' => 500 ) );
		}

		$absolute = $binding->resolveAbsolutePath();
		if ( ! is_dir( $absolute ) && ! wp_mkdir_p( $absolute ) ) {
			return new \WP_Error( 'mkdir_failed', sprintf( 'Could not create local directory %s.', $absolute ), array( 'status' => 500 ) );
		}

		// Containment belt-and-suspenders: once the dir exists, re-validate
		// that it really lives under ABSPATH (symlink-safe).
		$abspath_root = rtrim( ABSPATH, '/' );
		$containment  = PathSecurity::validateContainment( $absolute, $abspath_root );
		if ( ! $containment['valid'] ) {
			return new \WP_Error( 'path_outside_abspath', $containment['message'] ?? 'containment failed', array( 'status' => 403 ) );
		}
		$absolute = $containment['real_path'];

		$tree = GitHubRemote::fetchTree( $slug, $binding->branch, $pat );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$upstream  = $tree['blobs'];
		$tree_sha  = $tree['tree_sha'];
		$truncated = $tree['truncated'];

		$conflict_policy = (string) ( $binding->policy['conflict'] ?? 'fail' );
		$allow_dirty     = ! empty( $args['allow_dirty'] );

		$updated   = array();
		$unchanged = array();
		$conflicts = array();
		$deleted   = array();

		// Walk upstream blobs first — write new + updated files, detect conflicts.
		foreach ( $upstream as $path => $remote_sha ) {
			$dest = $absolute . '/' . $path;

			if ( PathSecurity::isSensitivePath( $path ) ) {
				// Upstream shouldn't ship credentials, but defend anyway.
				$conflicts[] = array( 'path' => $path, 'reason' => 'sensitive_path' );
				continue;
			}

			$local_exists = is_file( $dest );
			$local_sha    = $local_exists ? GitHubRemote::blobSha( (string) file_get_contents( $dest ) ) : null;

			if ( $local_exists && $local_sha === $remote_sha ) {
				$unchanged[] = $path;
				continue;
			}

			// Conflict detection: a tracked file exists locally with a SHA
			// that differs from both the previous upstream (implied by our
			// `pulled_paths` list) and the current remote. The consumer
			// edited it between pulls.
			$tracked = in_array( $path, $binding->pulled_paths, true );
			if ( $local_exists && $tracked && 'fail' === $conflict_policy && ! $allow_dirty ) {
				$conflicts[] = array( 'path' => $path, 'reason' => 'local_modified' );
				continue;
			}
			if ( $local_exists && $tracked && 'manual' === $conflict_policy && ! $allow_dirty ) {
				$conflicts[] = array( 'path' => $path, 'reason' => 'local_modified_manual' );
				continue;
			}
			// upstream_wins or allow_dirty or untracked: fall through and overwrite.

			$content = $this->fetchBlobContent( $slug, $path, $binding->branch, $pat );
			if ( is_wp_error( $content ) ) {
				return $content;
			}

			$parent = dirname( $dest );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				return new \WP_Error( 'mkdir_failed', sprintf( 'Could not create %s.', $parent ), array( 'status' => 500 ) );
			}

			$bytes = file_put_contents( $dest, $content );
			if ( false === $bytes ) {
				return new \WP_Error( 'write_failed', sprintf( 'Could not write %s.', $dest ), array( 'status' => 500 ) );
			}

			$updated[] = $path;
		}

		// Delete files that were pulled previously but are no longer upstream.
		// Untracked local files (never pulled) are left alone — they're
		// assumed consumer-owned proposals.
		foreach ( $binding->pulled_paths as $path ) {
			if ( array_key_exists( $path, $upstream ) ) {
				continue;
			}
			$victim = $absolute . '/' . $path;
			if ( is_file( $victim ) ) {
				unlink( $victim );
			}
			$deleted[] = $path;
		}

		// Rebuild pulled_paths from the upstream we just saw. Anything in
		// conflicts stays in the list — we didn't touch those files, so
		// they remain "tracked" for the next pull's conflict check.
		$new_pulled = array_keys( $upstream );
		foreach ( $conflicts as $conflict ) {
			if ( ! in_array( $conflict['path'], $new_pulled, true ) ) {
				$new_pulled[] = $conflict['path'];
			}
		}

		$binding->pulled_paths = array_values( $new_pulled );
		$binding->last_pulled  = gmdate( 'c' );
		$binding->last_commit  = $tree_sha ?: $binding->last_commit;
		$this->registry->save( $binding );

		return array(
			'success'     => empty( $conflicts ) || 'manual' === $conflict_policy,
			'slug'        => $binding->slug,
			'branch'      => $binding->branch,
			'tree_sha'    => $tree_sha,
			'updated'     => $updated,
			'unchanged'   => count( $unchanged ),
			'deleted'     => $deleted,
			'conflicts'   => $conflicts,
			'truncated'   => $truncated,
			'last_pulled' => $binding->last_pulled,
		);
	}

	/**
	 * Fetch a single blob's content via the Contents API.
	 *
	 * Returns raw decoded file contents (not the normalize envelope) since
	 * callers want to write bytes to disk directly.
	 *
	 * @return string|\WP_Error
	 */
	private function fetchBlobContent( string $slug, string $path, string $branch, string $pat ): string|\WP_Error {
		$response = GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'contents/' . ltrim( $path, '/' ) ),
			array( 'ref' => $branch ),
			$pat
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();

		// The Contents API truncates file payloads > 1MB — when that
		// happens the response omits `content` and the caller must fall
		// back to the Git Data API (blobs endpoint). Do that transparently.
		if ( empty( $data['content'] ) && ! empty( $data['sha'] ) ) {
			return $this->fetchLargeBlob( $slug, (string) $data['sha'], $pat );
		}

		$encoding = (string) ( $data['encoding'] ?? 'base64' );
		if ( 'base64' !== $encoding ) {
			return new \WP_Error(
				'unexpected_encoding',
				sprintf( 'Unexpected encoding "%s" for %s.', $encoding, $path ),
				array( 'status' => 500 )
			);
		}

		$decoded = base64_decode( (string) $data['content'], true );
		if ( false === $decoded ) {
			return new \WP_Error( 'base64_decode_failed', sprintf( 'Could not decode base64 content for %s.', $path ), array( 'status' => 500 ) );
		}

		return $decoded;
	}

	/**
	 * Fetch a blob by its SHA via the Git Data API.
	 *
	 * Used as a fallback for files larger than the Contents API's 1MB
	 * response cap.
	 *
	 * @return string|\WP_Error Raw decoded blob content.
	 */
	private function fetchLargeBlob( string $slug, string $sha, string $pat ): string|\WP_Error {
		$response = GitHubAbilities::apiGet(
			GitHubRemote::apiUrl( $slug, 'git/blobs/' . rawurlencode( $sha ) ),
			array(),
			$pat
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$decoded = base64_decode( (string) ( $data['content'] ?? '' ), true );
		if ( false === $decoded ) {
			return new \WP_Error( 'base64_decode_failed', sprintf( 'Could not decode large blob %s.', $sha ), array( 'status' => 500 ) );
		}
		return $decoded;
	}

}
