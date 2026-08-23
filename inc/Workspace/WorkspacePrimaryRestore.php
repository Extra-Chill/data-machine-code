<?php

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

trait WorkspacePrimaryRestore {
	/** Plan bounded, non-destructive recovery of a missing primary from registered remote state. */
	public function primary_restore_plan( string $repo, int $limit = 25, int $offset = 0 ): array|\WP_Error {
		$repo = $this->sanitize_name($repo);
		if ( '' === $repo || $limit < 1 || $limit > 200 || $offset < 0 ) {
			return new \WP_Error('invalid_primary_restore_request', 'A repository and bounded limit (1-200) are required.', array( 'status' => 400 ));
		}
		$primary = $this->get_primary_path($repo);
		if ( GitCheckout::exists($primary) ) {
			return new \WP_Error('primary_restore_not_required', sprintf('Primary checkout for "%s" already exists.', $repo), array( 'status' => 409 ));
		}
		if ( file_exists($primary) ) {
			return new \WP_Error('primary_restore_path_unsafe', sprintf('Primary restore target "%s" exists but is not a Git checkout.', $primary), array( 'status' => 409 ));
		}
		$context = ( new RemoteWorkspaceBackend() )->materialization_context($repo);
		if ( is_wp_error($context) || '' === trim((string) ($context['url'] ?? '')) ) {
			return new \WP_Error('primary_restore_remote_unregistered', 'Refusing primary restore without registered remote workspace identity.', array( 'status' => 409, 'cause' => is_wp_error($context) ? $context->get_error_code() : null ));
		}
		if ( $repo !== (string) ($context['repo_name'] ?? '') ) {
			return new \WP_Error('primary_restore_remote_identity_mismatch', 'Registered remote identity does not match the requested primary handle.', array( 'status' => 409 ));
		}
		$all_rows = array_values(array_filter($this->worktree_inventory()->list($repo), static fn( array $row ): bool => empty($row['is_primary'])));
		$has_more = count($all_rows) > $offset + $limit;
		$rows = $all_rows;
		$rows = array_slice($rows, $offset, $limit);
		$linked = array();
		foreach ( $rows as $row ) {
			$path = (string) ($row['path'] ?? ''); $marker = $path . '/.git';
			$pointer = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
			$expected = rtrim($primary, '/') . '/.git/worktrees/';
			$linked[] = array(
				'handle' => (string) ($row['handle'] ?? ''), 'path' => $path, 'branch' => (string) ($row['branch'] ?? ''),
				'dirty' => $row['dirty_count'] ?? null, 'unpushed' => $row['unpushed_count'] ?? null,
				'task' => array_filter(array( 'task_url' => $row['task_url'] ?? null, 'task_ref' => $row['task_ref'] ?? null )),
				'classification' => str_starts_with($pointer, 'gitdir: ' . $expected) && is_dir($path) ? 'terminal_classification_candidate' : 'retained_unverified',
			);
		}
		$plan = array( 'version' => 1, 'repo' => $repo, 'primary_path' => $primary, 'remote' => (string) $context['url'], 'offset' => $offset, 'limit' => $limit, 'has_more' => $has_more, 'linked' => $linked );
		$plan['digest'] = hash('sha256', wp_json_encode($plan) ?: '');
		$plan['apply'] = array( 'ability' => 'datamachine-code/workspace-primary-restore-apply', 'plan' => $plan );
		return $plan;
	}

	/** Apply a restore plan only after an identical live replan. */
	public function primary_restore_apply( array $plan ): array|\WP_Error {
		$expected = (string) ($plan['digest'] ?? '');
		$repo     = $this->sanitize_name((string) ($plan['repo'] ?? ''));
		if ('' !== $repo && GitCheckout::exists($this->get_primary_path($repo))) {
			return array('success' => true, 'already_restored' => true, 'repo' => $repo, 'primary_path' => $this->get_primary_path($repo));
		}
		$current = $this->primary_restore_plan((string) ($plan['repo'] ?? ''), (int) ($plan['limit'] ?? 25), (int) ($plan['offset'] ?? 0));
		if ( is_wp_error($current) || '' === $expected || ! hash_equals($expected, (string) ($current['digest'] ?? '')) ) {
			return new \WP_Error('stale_primary_restore_plan', 'Primary restore plan no longer matches registered remote, primary, or linked-worktree state.', array( 'status' => 409 ));
		}
		$repo = (string) $current['repo'];
		return WorkspaceMutationLock::with_repo($this->workspace_path, $repo, function () use ( $current, $repo ) {
			// Revalidate again inside the mutation lock before materializing the primary.
			$locked = $this->primary_restore_plan($repo, (int) $current['limit'], (int) $current['offset']);
			if ( is_wp_error($locked) || ! hash_equals((string) $current['digest'], (string) ($locked['digest'] ?? '')) ) {
				return new \WP_Error('stale_primary_restore_plan', 'Primary restore state changed before mutation.', array( 'status' => 409 ));
			}
			$classified = array();
			foreach ((array) $locked['linked'] as $row) {
				if ('terminal_classification_candidate' !== ($row['classification'] ?? '')) { continue; }
				$metadata = WorktreeContextInjector::get_metadata((string) $row['handle']) ?? array();
				$metadata['primary_restore'] = array( 'status' => 'terminally_classified', 'at' => gmdate('c'), 'reason' => 'missing_common_git_directory', 'remote' => $locked['remote'] );
				$stored = WorktreeContextInjector::store_lifecycle_metadata((string) $row['handle'], $metadata);
				if ( is_wp_error($stored) ) { return new \WP_Error('primary_restore_metadata_failed', 'Primary was restored but linked-worktree classification could not be persisted.', array( 'status' => 500, 'handle' => $row['handle'] )); }
				$classified[] = $row['handle'];
			}
			$result = array( 'success' => true, 'terminally_classified' => $classified, 'retained_unverified' => array_values(array_map(static fn( array $row ): string => $row['handle'], array_filter((array) $locked['linked'], static fn( array $row ): bool => 'terminal_classification_candidate' !== ($row['classification'] ?? ''))) ) );
			if ( ! empty($locked['has_more']) ) {
				$result['next_offset'] = (int) $locked['offset'] + (int) $locked['limit'];
				return $result;
			}
			$primary = $this->materialize_remote_workspace(array( 'repo_name' => $repo, 'url' => (string) $locked['remote'], 'branch' => '' ));
			if ( is_wp_error($primary) ) { return $primary; }
			$result['primary'] = $primary;
			return $result;
		});
	}
}
