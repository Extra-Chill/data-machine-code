<?php
/**
 * GitHub Fetch Handler
 *
 * Fetches issues, pull requests, or source file contents from a GitHub
 * repository and returns them as DataPackets for pipeline processing.
 * Supports deduplication via processed items, timeframe filtering, and
 * keyword search.
 *
 * @package DataMachineCode\Handlers\GitHub
 * @since 0.1.0
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHub extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'github' );

		self::registerHandler(
			'github',
			'fetch',
			self::class,
			'GitHub',
			'Fetch issues, pull requests, or source file contents from GitHub repositories',
			false,
			null,
			GitHubSettings::class,
			null
		);
	}

	/**
	 * Fetch GitHub data and return as a DataPacket-compatible array.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext  $context Execution context.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$repo        = $config['repo'] ?? '';
		$data_source = $config['data_source'] ?? 'issues';

		if ( empty( $repo ) ) {
			$repo = GitHubAbilities::getDefaultRepo();
		}

		if ( empty( $repo ) ) {
			$context->log( 'error', 'GitHub: No repository configured and no default repo set.' );
			return array();
		}

		if ( ! GitHubAbilities::isConfigured() ) {
			$context->log( 'error', 'GitHub: Personal Access Token not configured.' );
			return array();
		}

		$context->log( 'debug', 'GitHub: Fetching data', array(
			'repo'        => $repo,
			'data_source' => $data_source,
		) );

		if ( 'pull_review_context' === $data_source ) {
			return $this->fetchPullReviewContext( $config, $context, $repo );
		}

		if ( 'repo_review_profile' === $data_source ) {
			return $this->fetchRepoReviewProfile( $config, $context, $repo );
		}

		if ( 'github_pr_documentation_impact' === $data_source ) {
			return $this->fetchPullDocumentationImpact( $config, $context, $repo );
		}

		if ( 'check_runs' === $data_source ) {
			return $this->fetchCheckRuns( $config, $context, $repo );
		}

		if ( 'commit_statuses' === $data_source ) {
			return $this->fetchCommitStatuses( $config, $context, $repo );
		}

		if ( 'homeboy_ci_results' === $data_source ) {
			return $this->fetchHomeboyCiResults( $config, $context, $repo );
		}

		if ( 'files' === $data_source ) {
			return $this->fetchFiles( $config, $context, $repo );
		}

		return $this->fetchIssuesOrPulls( $config, $context, $repo, $data_source );
	}

	/**
	 * Fetch one pull request as review-ready context.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext $context Execution context.
	 * @param string           $repo    Repository in owner/repo format.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	private function fetchPullReviewContext( array $config, ExecutionContext $context, string $repo ): array {
		$pull_number = (int) ( $config['pull_number'] ?? $config['pr_number'] ?? 0 );

		if ( $pull_number <= 0 ) {
			$context->log( 'error', 'GitHub: pull_number is required for pull_review_context.' );
			return array();
		}

		$result = GitHubAbilities::getPullReviewContext( array(
			'repo'                    => $repo,
			'pull_number'             => $pull_number,
			'head_sha'                => $config['head_sha'] ?? '',
			'max_patch_chars'         => $config['max_patch_chars'] ?? 200000,
			'include_file_contents'   => ! empty( $config['include_file_contents'] ),
			'include_base_contents'   => ! empty( $config['include_base_contents'] ),
			'context_paths'           => $config['context_paths'] ?? array(),
			'max_file_content_chars'  => $config['max_file_content_chars'] ?? 20000,
			'max_context_files'       => $config['max_context_files'] ?? 10,
			'max_total_context_chars' => $config['max_total_context_chars'] ?? 100000,
			'include_checks'          => ! empty( $config['include_checks'] ),
			'include_statuses'        => ! empty( $config['include_statuses'] ),
			'max_check_runs'          => $config['max_check_runs'] ?? 30,
			'include_check_output'    => ! empty( $config['include_check_output'] ),
			'include_homeboy_ci'      => ! empty( $config['include_homeboy_ci'] ),
			'artifact_name'           => $config['artifact_name'] ?? $config['homeboy_artifact_name'] ?? 'homeboy-ci-results',
			'max_artifact_bytes'      => $config['max_artifact_bytes'] ?? 2000000,
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: PR review context error — ' . $result->get_error_message() );
			return array();
		}

		$packet = $result['context'] ?? array();
		if ( empty( $packet ) ) {
			$context->log( 'info', 'GitHub: No PR review context returned.' );
			return array();
		}

		$context->log( 'info', sprintf( 'GitHub: Prepared PR review context for %s#%d.', $repo, $pull_number ) );
		return array( 'items' => array( $packet ) );
	}

	/**
	 * Fetch bounded repository-level review context.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext $context Execution context.
	 * @param string           $repo    Repository in owner/repo format.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	private function fetchRepoReviewProfile( array $config, ExecutionContext $context, string $repo ): array {
		$result = GitHubAbilities::getRepoReviewProfile( array(
			'repo'                  => $repo,
			'ref'                   => $config['ref'] ?? $config['branch'] ?? '',
			'max_profile_files'     => $config['max_profile_files'] ?? 14,
			'max_file_chars'        => $config['max_file_chars'] ?? 12000,
			'max_total_chars'       => $config['max_total_chars'] ?? 60000,
			'max_architecture_docs' => $config['max_architecture_docs'] ?? 8,
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: Repo review profile error — ' . $result->get_error_message() );
			return array();
		}

		$profile = $result['profile'] ?? array();
		if ( empty( $profile ) ) {
			$context->log( 'info', 'GitHub: No repo review profile returned.' );
			return array();
		}

		$item_identifier = sprintf( '%s_repo_review_profile_%s', $repo, $config['ref'] ?? $config['branch'] ?? 'HEAD' );
		$packet          = array(
			'title'    => sprintf( 'Repository review profile: %s', $repo ),
			'content'  => wp_json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'metadata' => array(
				'source_type'    => 'github',
				'original_id'    => $item_identifier,
				'dedup_key'      => $item_identifier,
				'original_title' => sprintf( 'Repository review profile for %s', $repo ),
				'github_repo'    => $repo,
				'github_type'    => 'repo_review_profile',
				'review_context' => $profile,
			),
		);

		$context->log( 'info', sprintf( 'GitHub: Prepared repo review profile for %s.', $repo ) );
		return array( 'items' => array( $packet ) );
	}

	/**
	 * Fetch a documentation-impact packet for one pull request.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext $context Execution context.
	 * @param string           $repo    Repository in owner/repo format.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	private function fetchPullDocumentationImpact( array $config, ExecutionContext $context, string $repo ): array {
		$pull_number = (int) ( $config['pull_number'] ?? $config['pr_number'] ?? 0 );

		if ( $pull_number <= 0 ) {
			$context->log( 'error', 'GitHub: pull_number is required for github_pr_documentation_impact.' );
			return array();
		}

		$result = GitHubAbilities::getPullDocumentationImpact( array(
			'repo'        => $repo,
			'pull_number' => $pull_number,
			'head_sha'    => $config['head_sha'] ?? '',
			'base_ref'    => $config['base_ref'] ?? '',
			'docs_paths'  => $config['docs_paths'] ?? $config['docs_path_allowlist'] ?? array(),
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: PR documentation impact error — ' . $result->get_error_message() );
			return array();
		}

		$packet = $result['packet'] ?? array();
		if ( empty( $packet ) ) {
			$context->log( 'info', 'GitHub: No PR documentation impact packet returned.' );
			return array();
		}

		$item_identifier = sprintf( '%s#%d@%s_documentation_impact', $repo, $pull_number, $packet['head_sha'] ?? '' );
		$data_packet     = array(
			'title'    => sprintf( 'PR documentation impact: %s#%d', $repo, $pull_number ),
			'content'  => wp_json_encode( $packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'metadata' => array(
				'source_type'     => 'github_pr_documentation_impact',
				'item_identifier' => $item_identifier,
				'original_id'     => $item_identifier,
				'dedup_key'       => $item_identifier,
				'github_repo'     => $repo,
				'github_type'     => 'github_pr_documentation_impact',
				'github_number'   => $pull_number,
				'github_head_sha' => $packet['head_sha'] ?? '',
				'review_context'  => $packet,
			),
		);

		$context->log( 'info', sprintf( 'GitHub: Prepared PR documentation impact packet for %s#%d.', $repo, $pull_number ) );
		return array( 'items' => array( $data_packet ) );
	}

	/**
	 * Fetch check runs for one commit SHA or ref.
	 */
	private function fetchCheckRuns( array $config, ExecutionContext $context, string $repo ): array {
		$sha = $this->resolveShaFromConfig( $config );
		if ( '' === $sha ) {
			$context->log( 'error', 'GitHub: sha or head_sha is required for check_runs.' );
			return array();
		}

		$result = GitHubAbilities::getCheckRuns( array(
			'repo'                 => $repo,
			'sha'                  => $sha,
			'per_page'             => $config['max_check_runs'] ?? $config['per_page'] ?? 30,
			'include_check_output' => ! empty( $config['include_check_output'] ),
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: Check runs error — ' . $result->get_error_message() );
			return array();
		}

		return array( 'items' => array( $this->buildStatusPacket( $repo, $sha, 'check_runs', $result ) ) );
	}

	/**
	 * Fetch legacy commit statuses for one commit SHA or ref.
	 */
	private function fetchCommitStatuses( array $config, ExecutionContext $context, string $repo ): array {
		$sha = $this->resolveShaFromConfig( $config );
		if ( '' === $sha ) {
			$context->log( 'error', 'GitHub: sha or head_sha is required for commit_statuses.' );
			return array();
		}

		$result = GitHubAbilities::getCommitStatuses( array(
			'repo' => $repo,
			'sha'  => $sha,
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: Commit statuses error — ' . $result->get_error_message() );
			return array();
		}

		return array( 'items' => array( $this->buildStatusPacket( $repo, $sha, 'commit_statuses', $result ) ) );
	}

	/**
	 * Fetch Homeboy CI result artifacts for one pull request or head SHA.
	 */
	private function fetchHomeboyCiResults( array $config, ExecutionContext $context, string $repo ): array {
		$result = GitHubAbilities::getHomeboyCiResults( array(
			'repo'               => $repo,
			'pull_number'        => (int) ( $config['pull_number'] ?? $config['pr_number'] ?? 0 ),
			'head_sha'           => $config['head_sha'] ?? $config['sha'] ?? '',
			'artifact_name'      => $config['artifact_name'] ?? $config['homeboy_artifact_name'] ?? 'homeboy-ci-results',
			'max_artifact_bytes' => $config['max_artifact_bytes'] ?? 2000000,
			'include_raw'        => ! empty( $config['include_raw'] ),
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: Homeboy CI result error — ' . $result->get_error_message() );
			return array();
		}

		$results = $result['results'] ?? array();
		if ( empty( $results ) ) {
			$context->log( 'info', 'GitHub: No Homeboy CI results returned.' );
			return array();
		}

		$sha             = (string) ( $results['head_sha'] ?? $config['head_sha'] ?? '' );
		$item_key        = '' !== $sha ? $sha : (string) ( $config['pull_number'] ?? '' );
		$item_identifier = sprintf( '%s_homeboy_ci_results_%s', $repo, $item_key );

		$packet = array(
			'title'    => sprintf( 'Homeboy CI results: %s@%s', $repo, $sha ),
			'content'  => wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'metadata' => array(
				'source_type'     => 'github',
				'original_id'     => $item_identifier,
				'dedup_key'       => $item_identifier,
				'original_title'  => sprintf( 'Homeboy CI results for %s', $sha ),
				'github_repo'     => $repo,
				'github_type'     => 'homeboy_ci_results',
				'github_head_sha' => $sha,
				'github_state'    => $results['state'] ?? '',
				'review_context'  => $results,
			),
		);

		$context->log( 'info', sprintf( 'GitHub: Prepared Homeboy CI results for %s@%s.', $repo, $sha ) );
		return array( 'items' => array( $packet ) );
	}

	private function resolveShaFromConfig( array $config ): string {
		return trim( (string) ( $config['sha'] ?? $config['head_sha'] ?? $config['ref'] ?? '' ) );
	}

	private function buildStatusPacket( string $repo, string $sha, string $type, array $result ): array {
		$item_identifier = sprintf( '%s_%s_%s', $repo, $type, $sha );

		return array(
			'title'    => sprintf( 'GitHub %s: %s@%s', str_replace( '_', ' ', $type ), $repo, $sha ),
			'content'  => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'metadata' => array(
				'source_type'     => 'github',
				'original_id'     => $item_identifier,
				'dedup_key'       => $item_identifier,
				'original_title'  => sprintf( '%s for %s', $type, $sha ),
				'github_repo'     => $repo,
				'github_type'     => $type,
				'github_head_sha' => $sha,
				'github_state'    => $result['summary']['state'] ?? '',
				'review_context'  => $result,
			),
		);
	}

	/**
	 * Fetch source file contents from a GitHub repository.
	 *
	 * Uses the Git Trees API to list files, applies path/pattern filters,
	 * then fetches content for each matching file via the Contents API.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext $context Execution context.
	 * @param string           $repo    Repository in owner/repo format.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	private function fetchFiles( array $config, ExecutionContext $context, string $repo ): array {
		$branch       = $config['branch'] ?? '';
		$file_path    = $config['file_path'] ?? '';
		$file_pattern = $config['file_pattern'] ?? '';

		$context->log( 'info', sprintf( 'GitHub: Fetching file tree for %s', $repo ) );

		$result = GitHubAbilities::getRepoTree( array(
			'repo'   => $repo,
			'branch' => $branch,
		) );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: Tree API error — ' . $result->get_error_message() );
			return array();
		}

		$files = $result['files'] ?? array();

		if ( empty( $files ) ) {
			$context->log( 'info', 'GitHub: No files found in repository.' );
			return array();
		}

		// Apply path prefix filter.
		if ( ! empty( $file_path ) ) {
			$prefix = rtrim( $file_path, '/' ) . '/';
			$files  = array_filter( $files, fn( $f ) => str_starts_with( $f['path'], $prefix ) || trim( $file_path, '/' ) === $f['path'] );
			$files  = array_values( $files );
		}

		// Apply file pattern filter (simple glob: *.php, *.md, etc.).
		if ( ! empty( $file_pattern ) ) {
			$files = $this->filterByPattern( $files, $file_pattern );
		}

		// Filter out binary/large files (> 500 KB, likely not source).
		$max_file_size = (int) ( $config['max_file_size'] ?? 512000 );
		$files         = array_filter( $files, fn( $f ) => $f['size'] > 0 && $f['size'] <= $max_file_size );
		$files         = array_values( $files );

		if ( empty( $files ) ) {
			$context->log( 'info', 'GitHub: No files matched filters.' );
			return array();
		}

		$context->log( 'info', sprintf( 'GitHub: Found %d matching files.', count( $files ) ) );

		$eligible_items = array();

		foreach ( $files as $file ) {
			$file_result = GitHubAbilities::getFileContents( array(
				'repo'   => $repo,
				'path'   => $file['path'],
				'branch' => $branch,
			) );

			if ( is_wp_error( $file_result ) ) {
				$context->log( 'debug', sprintf( 'GitHub: Skipped %s — %s', $file['path'], $file_result->get_error_message() ) );
				continue;
			}

			$file_data = $file_result['file'];
			$guid      = sprintf( 'github_%s_files_%s', $repo, $file['sha'] );

			$eligible_items[] = array(
				'title'    => $file_data['path'],
				'content'  => $file_data['content'],
				'metadata' => array(
					'source_type'      => 'github',
					'original_id'      => $guid,
					'dedup_key'        => $guid,
					'original_title'   => $file_data['path'],
					'github_repo'      => $repo,
					'github_type'      => 'files',
					'github_file_path' => $file_data['path'],
					'github_file_size' => $file_data['size'],
					'github_file_sha'  => $file_data['sha'],
					'github_url'       => $file_data['html_url'],
					'source_url'       => $file_data['html_url'],
				),
			);
		}

		if ( empty( $eligible_items ) ) {
			$context->log( 'info', 'GitHub: No file contents could be retrieved.' );
			return array();
		}

		$context->log( 'info', sprintf( 'GitHub: Retrieved %d file contents.', count( $eligible_items ) ) );
		return array( 'items' => $eligible_items );
	}

	/**
	 * Fetch issues or pull requests from a GitHub repository.
	 *
	 * @param array            $config      Handler configuration.
	 * @param ExecutionContext $context     Execution context.
	 * @param string           $repo        Repository in owner/repo format.
	 * @param string           $data_source 'issues' or 'pulls'.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	private function fetchIssuesOrPulls( array $config, ExecutionContext $context, string $repo, string $data_source ): array {
		$state  = $config['state'] ?? 'open';
		$labels = $config['labels'] ?? '';

		$input = array(
			'repo'     => $repo,
			'state'    => $state,
			'per_page' => GitHubAbilities::MAX_PER_PAGE,
		);

		if ( ! empty( $labels ) ) {
			$input['labels'] = $labels;
		}

		if ( 'pulls' === $data_source ) {
			$result = GitHubAbilities::listPulls( $input );
			$items  = $result['pulls'] ?? array();
		} else {
			$result = GitHubAbilities::listIssues( $input );
			$items  = $result['issues'] ?? array();
		}

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'GitHub: API error — ' . $result->get_error_message() );
			return array();
		}

		if ( empty( $items ) ) {
			$context->log( 'info', 'GitHub: No items found.' );
			return array();
		}

		$context->log( 'info', sprintf( 'GitHub: Found %d %s.', count( $items ), $data_source ) );

		$search           = $config['search'] ?? '';
		$exclude_keywords = $config['exclude_keywords'] ?? '';
		$timeframe_limit  = $config['timeframe_limit'] ?? 'all_time';
		$eligible_items   = array();

		foreach ( $items as $item ) {
			$guid = sprintf( 'github_%s_%s_%d', $repo, $data_source, $item['number'] );

			$searchable_text = ( $item['title'] ?? '' ) . ' ' . ( $item['body'] ?? '' );

			if ( ! empty( $search ) && ! $this->applyKeywordSearch( $searchable_text, $search ) ) {
				continue;
			}

			if ( ! empty( $exclude_keywords ) && ! $this->applyExcludeKeywords( $searchable_text, $exclude_keywords ) ) {
				continue;
			}

			if ( 'all_time' !== $timeframe_limit && ! empty( $item['created_at'] ) ) {
				$timestamp = strtotime( $item['created_at'] );
				if ( $timestamp && ! $this->applyTimeframeFilter( $timestamp, $timeframe_limit ) ) {
					continue;
				}
			}

			$labels_str = ! empty( $item['labels'] ) ? implode( ', ', $item['labels'] ) : '';

			if ( 'pulls' === $data_source ) {
				$title   = sprintf( 'PR #%d: %s', $item['number'], $item['title'] );
				$content = $this->buildPrContent( $item );
			} else {
				$title   = sprintf( 'Issue #%d: %s', $item['number'], $item['title'] );
				$content = $this->buildIssueContent( $item );
			}

			$eligible_items[] = array(
				'title'    => $title,
				'content'  => $content,
				'metadata' => array(
					'source_type'       => 'github',
					'original_id'       => $guid,
					'dedup_key'         => $guid,
					'original_title'    => $item['title'] ?? '',
					'original_date_gmt' => $item['created_at'] ?? '',
					'github_repo'       => $repo,
					'github_type'       => $data_source,
					'github_number'     => $item['number'],
					'github_state'      => $item['state'] ?? '',
					'github_labels'     => $labels_str,
					'github_user'       => $item['user'] ?? '',
					'github_url'        => $item['html_url'] ?? '',
					'source_url'        => $item['html_url'] ?? '',
				),
			);
		}

		if ( empty( $eligible_items ) ) {
			$context->log( 'info', 'GitHub: All items filtered out.' );
			return array();
		}

		$context->log( 'info', sprintf( 'GitHub: Found %d eligible items.', count( $eligible_items ) ) );
		return array( 'items' => $eligible_items );
	}

	/**
	 * Filter files by a glob-like pattern.
	 *
	 * Supports simple patterns like `*.php`, `*.md`, `*.css`.
	 * Multiple patterns can be comma-separated: `*.php,*.json`.
	 *
	 * @param array  $files   Normalized file entries from getRepoTree.
	 * @param string $pattern Glob pattern(s).
	 * @return array Filtered files.
	 */
	private function filterByPattern( array $files, string $pattern ): array {
		$patterns = array_map( 'trim', explode( ',', $pattern ) );
		$patterns = array_filter( $patterns );

		if ( empty( $patterns ) ) {
			return $files;
		}

		return array_values( array_filter( $files, function ( $file ) use ( $patterns ) {
			$filename = basename( $file['path'] );
			foreach ( $patterns as $p ) {
				if ( fnmatch( $p, $filename ) ) {
					return true;
				}
			}
			return false;
		} ) );
	}

	private function buildIssueContent( array $issue ): string {
		$parts   = array();
		$parts[] = sprintf( '**State:** %s', $issue['state'] ?? 'unknown' );
		$parts[] = sprintf( '**Author:** %s', $issue['user'] ?? 'unknown' );
		if ( ! empty( $issue['labels'] ) ) {
			$parts[] = sprintf( '**Labels:** %s', implode( ', ', $issue['labels'] ) );
		}
		if ( ! empty( $issue['assignees'] ) ) {
			$parts[] = sprintf( '**Assignees:** %s', implode( ', ', $issue['assignees'] ) );
		}
		$parts[] = sprintf( '**Comments:** %d', $issue['comments'] ?? 0 );
		$parts[] = sprintf( '**Created:** %s', $issue['created_at'] ?? '' );
		$parts[] = sprintf( '**URL:** %s', $issue['html_url'] ?? '' );
		$parts[] = '';
		if ( ! empty( $issue['body'] ) ) {
			$parts[] = $issue['body'];
		}
		return implode( "\n", $parts );
	}

	private function buildPrContent( array $pr ): string {
		$parts   = array();
		$parts[] = sprintf( '**State:** %s', $pr['state'] ?? 'unknown' );
		$parts[] = sprintf( '**Author:** %s', $pr['user'] ?? 'unknown' );
		$parts[] = sprintf( '**Branch:** %s → %s', $pr['head'] ?? '?', $pr['base'] ?? '?' );
		if ( ! empty( $pr['draft'] ) ) {
			$parts[] = '**Draft:** Yes';
		}
		if ( ! empty( $pr['merged'] ) ) {
			$parts[] = sprintf( '**Merged:** %s', $pr['merged_at'] ?? 'Yes' );
		}
		if ( ! empty( $pr['labels'] ) ) {
			$parts[] = sprintf( '**Labels:** %s', implode( ', ', $pr['labels'] ) );
		}
		$parts[] = sprintf( '**Created:** %s', $pr['created_at'] ?? '' );
		$parts[] = sprintf( '**URL:** %s', $pr['html_url'] ?? '' );
		$parts[] = '';
		if ( ! empty( $pr['body'] ) ) {
			$parts[] = $pr['body'];
		}
		return implode( "\n", $parts );
	}

	public static function get_label(): string {
		return __( 'GitHub', 'data-machine-code' );
	}
}
