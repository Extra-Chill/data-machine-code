<?php
/**
 * Workspace row triage reporting and metadata-only actions.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

trait WorkspaceRowTriage {



	/**
	 * List workspace rows that need bounded operator triage.
	 *
	 * This is deliberately reporting-only. It surfaces external git worktrees,
	 * noncanonical top-level handles, and non-git directories without removing or
	 * moving anything.
	 *
	 * @param  array<string,mixed> $opts Options: status, include_resolved.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_row_triage_list( array $opts = array() ): array|\WP_Error {
		$visible = $this->require_workspace_visible();
		if ( null !== $visible ) {
			return $visible;
		}

		$status_filter    = isset($opts['status']) ? trim( (string) $opts['status']) : '';
		$include_resolved = ! empty($opts['include_resolved']);
		$rows             = array_merge($this->workspace_top_level_triage_rows(), $this->workspace_external_worktree_triage_rows());

		if ( '' !== $status_filter ) {
			$rows = array_values(array_filter($rows, static fn( array $row ): bool => $status_filter === (string) ( $row['triage_status'] ?? '' )));
		} elseif ( ! $include_resolved ) {
			$rows = array_values(array_filter($rows, static fn( array $row ): bool => 'unresolved' === (string) ( $row['triage_status'] ?? '' )));
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$status_cmp = strcmp((string) ( $a['triage_status'] ?? '' ), (string) ( $b['triage_status'] ?? '' ));
				return 0 !== $status_cmp ? $status_cmp : strcmp((string) ( $a['row_id'] ?? '' ), (string) ( $b['row_id'] ?? '' ));
			}
		);

		return array(
			'success'        => true,
			'workspace_path' => $this->workspace_path,
			'rows'           => $rows,
			'summary'        => $this->workspace_row_triage_summary($rows),
			'evidence'       => array(
				'scope' => 'metadata-only workspace row triage',
				'note'  => 'Actions from this surface write triage metadata or call safe primary adoption only; no cleanup/removal is performed.',
			),
		);
	}

	/**
	 * Mark a triage row ignored or quarantined.
	 *
	 * @param  string $row_id Row id from workspace_row_triage_list().
	 * @param  string $status ignored or quarantined.
	 * @param  string $reason Operator reason.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_row_triage_mark( string $row_id, string $status, string $reason ): array|\WP_Error {
		$row_id = trim($row_id);
		$status = strtolower(trim($status));
		$reason = trim($reason);

		if ( '' === $row_id ) {
			return new \WP_Error('missing_triage_row', 'A triage row id is required.', array( 'status' => 400 ));
		}
		if ( ! in_array($status, array( 'ignored', 'quarantined' ), true) ) {
			return new \WP_Error('invalid_triage_status', 'Triage status must be ignored or quarantined.', array( 'status' => 400 ));
		}
		if ( '' === $reason ) {
			return new \WP_Error('missing_triage_reason', 'A reason is required when marking a workspace row ignored or quarantined.', array( 'status' => 400 ));
		}

		$row = $this->workspace_row_triage_find($row_id, true);
		if ( is_wp_error($row) ) {
			return $row;
		}

		$now      = gmdate('c');
		$metadata = array(
			'triage_status'     => $status,
			'triage_reason'     => $reason,
			'triage_updated_at' => $now,
			'triage_source'     => 'workspace_row_triage',
			'handle'            => (string) ( $row['handle'] ?? $row_id ),
			'path'              => (string) ( $row['path'] ?? '' ),
			'repo'              => (string) ( $row['repo'] ?? '' ),
			'row_kind'          => (string) ( $row['row_kind'] ?? '' ),
			'row_issues'        => (array) ( $row['issues'] ?? array() ),
		);

		if ( empty($row['triage_metadata']) ) {
			$metadata['triage_created_at'] = $now;
		}

		WorktreeContextInjector::store_lifecycle_metadata((string) ( $row['metadata_key'] ?? $row_id ), $metadata);

		$updated = $this->workspace_row_triage_find($row_id, true);
		if ( is_wp_error($updated) ) {
			return $updated;
		}

		return array(
			'success' => true,
			'action'  => $status,
			'row'     => $updated,
			'message' => sprintf('Workspace row "%s" marked %s.', $row_id, $status),
		);
	}

	/**
	 * Adopt a safe unresolved top-level git primary row.
	 *
	 * @param  string      $row_id Row id from workspace_row_triage_list().
	 * @param  string|null $name   Optional canonical primary name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function workspace_row_triage_adopt( string $row_id, ?string $name = null ): array|\WP_Error {
		$row = $this->workspace_row_triage_find($row_id, true);
		if ( is_wp_error($row) ) {
			$row = $this->workspace_row_triage_adoptable_top_level_row($row_id);
			if ( is_wp_error($row) ) {
				return $row;
			}
		}

		if ( ! empty($row['external']) ) {
			return new \WP_Error('triage_adopt_external_unsupported', 'External worktree rows cannot be adopted by metadata triage. Create a DMC-owned worktree instead, then quarantine the external row.', array( 'status' => 400 ));
		}
		if ( ! empty($row['non_git']) ) {
			return new \WP_Error('triage_adopt_non_git_unsupported', 'Non-git rows cannot be adopted as workspace checkouts.', array( 'status' => 400 ));
		}
		if ( ! empty($row['is_linked_worktree']) ) {
			return new \WP_Error('triage_adopt_linked_worktree_unsupported', 'Linked worktree rows cannot be adopted as primary checkouts.', array( 'status' => 400 ));
		}

		$adopt = $this->adopt_repo((string) ( $row['path'] ?? '' ), $name);
		if ( is_wp_error($adopt) ) {
			return $adopt;
		}

		WorktreeContextInjector::store_lifecycle_metadata(
			(string) ( $row['metadata_key'] ?? $row_id ),
			array(
				'triage_status'     => 'adopted',
				'triage_reason'     => 'Safe primary checkout adopted through workspace row triage.',
				'triage_updated_at' => gmdate('c'),
				'triage_source'     => 'workspace_row_triage',
				'adopted_name'      => (string) ( $adopt['name'] ?? '' ),
			)
		);

		return array(
			'success' => true,
			'action'  => 'adopted',
			'adopt'   => $adopt,
			'row'     => $this->workspace_row_triage_find($row_id, true),
			'message' => sprintf('Workspace row "%s" adopted as %s.', $row_id, (string) ( $adopt['name'] ?? $row_id )),
		);
	}

	/**
	 * Build an adoptable canonical top-level primary row by handle.
	 *
	 * @param  string $row_id Top-level workspace basename.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function workspace_row_triage_adoptable_top_level_row( string $row_id ): array|\WP_Error {
		$parsed = $this->parse_handle($row_id);
		if ( ! empty($parsed['is_worktree']) || (string) $parsed['dir_name'] !== $row_id ) {
			return new \WP_Error('triage_row_not_found', sprintf('Workspace triage row "%s" was not found.', $row_id), array( 'status' => 404 ));
		}

		$path     = $this->workspace_path . '/' . $parsed['dir_name'];
		$git_path = $path . '/.git';
		if ( ! is_dir($path) || ! is_dir($git_path) ) {
			return new \WP_Error('triage_row_not_found', sprintf('Workspace triage row "%s" was not found.', $row_id), array( 'status' => 404 ));
		}

		$metadata = WorktreeContextInjector::get_metadata($row_id) ?? array();
		return $this->workspace_row_triage_normalize_row(
			array(
				'row_id'           => $row_id,
				'metadata_key'     => $row_id,
				'handle'           => $row_id,
				'canonical_handle' => $row_id,
				'repo'             => $row_id,
				'row_kind'         => 'primary',
				'path'             => $path,
				'git'              => true,
				'non_git'          => false,
				'noncanonical'     => false,
				'external'         => false,
				'issues'           => array( 'adoptable_primary' ),
				'metadata'         => $metadata,
			)
		);
	}

	/**
	 * Build triage rows for top-level workspace directories.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function workspace_top_level_triage_rows(): array {
		if ( '' === $this->workspace_path || ! is_dir($this->workspace_path) ) {
			return array();
		}

		$entries = scandir($this->workspace_path);
		if ( ! is_array($entries) ) {
			return array();
		}

		$rows = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir($path) ) {
				continue;
			}

			$parsed             = $this->parse_handle($entry);
			$git_path           = $path . '/.git';
			$is_git             = is_dir($git_path) || is_file($git_path);
			$is_linked_worktree = is_file($git_path);
			$is_noncanonical    = (string) $parsed['dir_name'] !== $entry;
			$is_non_git         = ! $is_git;
			$issues             = array();

			if ( $is_non_git ) {
				$issues[] = 'non_git';
			}
			if ( $is_noncanonical ) {
				$issues[] = 'noncanonical_handle';
			}
			if ( $is_linked_worktree && empty($parsed['is_worktree']) ) {
				$issues[] = 'linked_worktree_without_worktree_handle';
			}

			if ( array() === $issues ) {
				continue;
			}

			$metadata_key = $entry;
			$metadata     = WorktreeContextInjector::get_metadata($metadata_key) ?? array();
			$rows[]       = $this->workspace_row_triage_normalize_row(
				array(
					'row_id'             => $entry,
					'metadata_key'       => $metadata_key,
					'handle'             => $entry,
					'canonical_handle'   => (string) $parsed['dir_name'],
					'repo'               => (string) $parsed['repo'],
					'branch_slug'        => $parsed['branch_slug'],
					'row_kind'           => ! empty($parsed['is_worktree']) ? 'worktree' : 'primary',
					'path'               => $path,
					'git'                => $is_git,
					'non_git'            => $is_non_git,
					'noncanonical'       => $is_noncanonical,
					'external'           => false,
					'is_linked_worktree' => $is_linked_worktree,
					'issues'             => $issues,
					'metadata'           => $metadata,
				)
			);
		}

		return $rows;
	}

	/**
	 * Build triage rows for external git worktrees registered on primaries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function workspace_external_worktree_triage_rows(): array {
		$listing = $this->worktree_list(
			null,
			null,
			array(
				'include_status' => false,
				'include_disk'   => false,
			)
		);
		if ( is_wp_error($listing) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) ( $listing['worktrees'] ?? array() ) as $wt ) {
			if ( empty($wt['external']) ) {
				continue;
			}

			$path         = (string) ( $wt['path'] ?? '' );
			$row_id       = 'external:' . sha1($path);
			$metadata_key = $row_id;
			$metadata     = WorktreeContextInjector::get_metadata($metadata_key) ?? array();

			$rows[] = $this->workspace_row_triage_normalize_row(
				array(
					'row_id'           => $row_id,
					'metadata_key'     => $metadata_key,
					'handle'           => (string) ( $wt['handle'] ?? $path ),
					'canonical_handle' => null,
					'repo'             => (string) ( $wt['repo'] ?? '' ),
					'branch'           => (string) ( $wt['branch'] ?? '' ),
					'head'             => (string) ( $wt['head'] ?? '' ),
					'row_kind'         => 'external_worktree',
					'path'             => $path,
					'git'              => true,
					'non_git'          => false,
					'noncanonical'     => true,
					'external'         => true,
					'issues'           => array( 'external_worktree' ),
					'metadata'         => $metadata,
				)
			);
		}

		return $rows;
	}

	/**
	 * Normalize a triage row for output.
	 *
	 * @param  array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function workspace_row_triage_normalize_row( array $row ): array {
		$metadata      = is_array($row['metadata'] ?? null) ? (array) $row['metadata'] : array();
		$triage_status = $this->workspace_row_triage_status_from_metadata($metadata);

		$created_at = isset($metadata['created_at']) ? (string) $metadata['created_at'] : null;
		$mtime      = @filemtime((string) ( $row['path'] ?? '' )); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort provenance for local workspace rows.
		if ( null === $created_at && false !== $mtime ) {
			$created_at = gmdate('c', $mtime);
		}

		$owner = WorktreeContextInjector::summarize_owner($metadata);

		return array_merge(
			$row,
			array(
				'triage_status'   => $triage_status,
				'triage_reason'   => isset($metadata['triage_reason']) ? (string) $metadata['triage_reason'] : null,
				'triaged_at'      => isset($metadata['triage_updated_at']) ? (string) $metadata['triage_updated_at'] : null,
				'triage_metadata' => array_intersect_key(
					$metadata,
					array(
						'triage_status'     => true,
						'triage_reason'     => true,
						'triage_created_at' => true,
						'triage_updated_at' => true,
						'triage_source'     => true,
						'adopted_name'      => true,
					)
				),
				'created_at'      => $created_at,
				'age_days'        => $this->calculate_age_days(is_string($created_at) ? $created_at : null),
				'owner'           => $owner,
				'provenance'      => array(
					'origin_site'    => $owner['site'],
					'origin_agent'   => $owner['agent'],
					'origin_session' => isset($metadata['origin_session']) ? (string) $metadata['origin_session'] : null,
					'created_at'     => $created_at,
					'last_seen_at'   => isset($metadata['last_seen_at']) ? (string) $metadata['last_seen_at'] : null,
				),
			)
		);
	}

	/**
	 * Return a normalized triage status from row metadata.
	 *
	 * @param  array<string,mixed> $metadata Row metadata.
	 * @return string
	 */
	protected function workspace_row_triage_status_from_metadata( array $metadata ): string {
		$triage_status = isset($metadata['triage_status']) ? (string) $metadata['triage_status'] : 'unresolved';
		return in_array($triage_status, array( 'unresolved', 'ignored', 'quarantined', 'adopted' ), true) ? $triage_status : 'unresolved';
	}

	/**
	 * Find one triage row by id.
	 *
	 * @param  string $row_id           Row id.
	 * @param  bool   $include_resolved Include resolved rows.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function workspace_row_triage_find( string $row_id, bool $include_resolved ): array|\WP_Error {
		$result = $this->workspace_row_triage_list(array( 'include_resolved' => $include_resolved ));
		if ( is_wp_error($result) ) {
			return $result;
		}

		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( $row_id === (string) ( $row['row_id'] ?? '' ) ) {
				return $row;
			}
		}

		return new \WP_Error('triage_row_not_found', sprintf('Workspace triage row "%s" was not found.', $row_id), array( 'status' => 404 ));
	}

	/**
	 * Summarize triage rows.
	 *
	 * @param  array<int,array<string,mixed>> $rows Rows.
	 * @return array<string,mixed>
	 */
	private function workspace_row_triage_summary( array $rows ): array {
		$summary = array(
			'total'              => count($rows),
			'unresolved'         => 0,
			'ignored'            => 0,
			'quarantined'        => 0,
			'adopted'            => 0,
			'external_worktree'  => 0,
			'noncanonical_handle' => 0,
			'non_git'            => 0,
			'by_issue'           => array(),
		);

		foreach ( $rows as $row ) {
			$status = (string) ( $row['triage_status'] ?? 'unresolved' );
			if ( isset($summary[ $status ]) ) {
				++$summary[ $status ];
			}
			foreach ( (array) ( $row['issues'] ?? array() ) as $issue ) {
				$issue                         = (string) $issue;
				$summary['by_issue'][ $issue ] = (int) ( $summary['by_issue'][ $issue ] ?? 0 ) + 1;
				if ( isset($summary[ $issue ]) ) {
					++$summary[ $issue ];
				}
			}
		}

		ksort($summary['by_issue']);
		return $summary;
	}
}
