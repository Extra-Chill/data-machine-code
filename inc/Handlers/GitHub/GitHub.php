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

		if ( 'files' === $data_source ) {
			return $this->fetchFiles( $config, $context, $repo );
		}

		return $this->fetchIssuesOrPulls( $config, $context, $repo, $data_source );
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
