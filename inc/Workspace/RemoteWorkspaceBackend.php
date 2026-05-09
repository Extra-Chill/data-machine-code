<?php
/**
 * GitHub-backed workspace backend for constrained PHP runtimes.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Abilities\GitHubAbilities;

defined( 'ABSPATH' ) || exit;

class RemoteWorkspaceBackend {

	private const OPTION        = 'datamachine_code_remote_workspace_state';
	private const MAX_READ_SIZE = 1048576;

	/**
	 * Whether the remote backend should handle workspace operations.
	 */
	public static function should_handle(): bool {
		return ! \DataMachineCode\Support\GitRunner::is_available();
	}

	/**
	 * Clone/register a GitHub repository as a remote workspace primary.
	 *
	 * @param string      $url  GitHub repository URL.
	 * @param string|null $name Optional workspace repo name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function clone_repo( string $url, ?string $name = null ): array|\WP_Error {
		$repo = $this->repo_from_url( $url );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$name = $this->sanitize_name( null !== $name && '' !== $name ? $name : basename( (string) $repo ) );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_clone_name', 'Could not derive a workspace name for the remote repository.', array( 'status' => 400 ) );
		}

		$state                        = $this->state();
		$state['repos'][ $name ]      = array(
			'repo' => $repo,
			'url'  => $url,
		);
		$state['repo_names'][ $repo ] = $name;
		$this->save_state( $state );

		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'name'               => $name,
			'path'               => 'github://' . $repo,
			'message'            => sprintf( 'Registered %s as remote workspace "%s".', $repo, $name ),
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'workspace_worktree_add',
			'next_required_args' => array( 'repo' => $name ),
		);
	}

	/**
	 * Create/register a remote worktree branch.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function worktree_add( string $repo_name, string $branch, ?string $from = null ): array|\WP_Error {
		$repo = $this->resolve_repo( $repo_name );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$branch = trim( $branch );
		if ( '' === $branch ) {
			return new \WP_Error( 'missing_branch', 'Branch is required.', array( 'status' => 400 ) );
		}

		$slug                          = $this->branch_slug( $branch );
		$handle                        = $repo_name . '@' . $slug;
		$state                         = $this->state();
		$state['worktrees'][ $handle ] = array(
			'repo_name'       => $repo_name,
			'repo'            => $repo,
			'branch'          => $branch,
			'base_ref'        => null !== $from && '' !== $from ? $from : '',
			'pending_files'   => array(),
			'changed_files'   => array(),
			'last_commit_sha' => '',
		);
		$this->save_state( $state );

		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'handle'             => $handle,
			'path'               => 'github://' . $repo . '#' . $branch,
			'branch'             => $branch,
			'slug'               => $slug,
			'created_branch'     => true,
			'message'            => sprintf( 'Registered remote workspace %s for %s.', $handle, $repo ),
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'workspace_read or workspace_edit or workspace_write',
			'next_required_args' => array( 'repo' => $handle ),
		);
	}

	/**
	 * Read a file from GitHub or pending remote workspace state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read_file( string $handle, string $path, int $max_size, ?int $offset = null, ?int $limit = null ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$path = $this->normalize_path( $path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$content = $context['pending_files'][ $path ] ?? null;
		if ( null === $content ) {
			$file_input = array(
				'repo' => $context['repo'],
				'path' => $path,
			);
			if ( '' !== $context['read_ref'] ) {
				$file_input['ref'] = $context['read_ref'];
			}

			$file = GitHubAbilities::getFileContents( $file_input );
			if ( is_wp_error( $file ) && '' !== $context['read_ref'] ) {
				$file = GitHubAbilities::getFileContents(
					array(
						'repo' => $context['repo'],
						'path' => $path,
					)
				);
			}
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( empty( $file['files'][0] ) ) {
				$error = $file['errors'][0]['message'] ?? sprintf( 'File not found: %s.', $path );
				return new \WP_Error( 'remote_workspace_file_unavailable', $error, array( 'status' => 404 ) );
			}
			$content = (string) ( $file['files'][0]['content'] ?? '' );
		}

		$size = strlen( $content );
		if ( $size > $max_size ) {
			return new \WP_Error( 'file_too_large', sprintf( 'File too large: %s.', $path ), array( 'status' => 400 ) );
		}

		$result_content = $content;
		if ( null !== $offset || null !== $limit ) {
			$start_line     = max( 1, (int) ( $offset ?? 1 ) );
			$lines          = explode( "\n", $content );
			$lines          = array_slice( $lines, $start_line - 1, null === $limit ? null : max( 0, $limit ) );
			$result_content = implode( "\n", $lines );
		}

		return array(
			'success' => true,
			'backend' => 'github_api',
			'content' => $result_content,
			'path'    => $path,
			'size'    => $size,
		);
	}

	/**
	 * List repository files under a path prefix.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function list_directory( string $handle, ?string $path = null ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$prefix = null === $path ? '' : trim( ltrim( $path, '/' ), '/' );
		$tree   = GitHubAbilities::getRepoTree(
			array(
				'repo' => $context['repo'],
				'ref'  => $context['read_ref'],
			)
		);
		if ( is_wp_error( $tree ) && '' !== $context['read_ref'] ) {
			$tree = GitHubAbilities::getRepoTree( array( 'repo' => $context['repo'] ) );
		}
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$entries = array();
		foreach ( (array) ( $tree['files'] ?? array() ) as $file ) {
			$file_path = (string) ( $file['path'] ?? '' );
			if ( '' !== $prefix && ! str_starts_with( $file_path, $prefix . '/' ) ) {
				continue;
			}

			$relative = '' === $prefix ? $file_path : substr( $file_path, strlen( $prefix ) + 1 );
			if ( '' === $relative || str_contains( $relative, '/' ) ) {
				continue;
			}

			$entries[] = array(
				'name' => $relative,
				'type' => (string) ( $file['type'] ?? 'file' ),
				'size' => (int) ( $file['size'] ?? 0 ),
			);
		}

		return array(
			'success' => true,
			'backend' => 'github_api',
			'repo'    => $handle,
			'path'    => '' === $prefix ? '/' : $prefix,
			'entries' => $entries,
		);
	}

	/**
	 * Search text files through the GitHub-backed workspace backend.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function grep( string $handle, string $pattern, ?string $path = null, ?string $include_pattern = null, int $max_results = 100, int $context_lines = 0 ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$prefix = null === $path ? '' : trim( ltrim( $path, '/' ), '/' );
		if ( str_contains( $prefix, '..' ) ) {
			return new \WP_Error( 'path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ) );
		}

		$regex = $this->compile_search_pattern( $pattern );
		if ( is_wp_error( $regex ) ) {
			return $regex;
		}

		$tree_input = array(
			'repo' => $context['repo'],
			'ref'  => $context['read_ref'],
		);
		if ( '' !== $prefix ) {
			$tree_input['path'] = $prefix;
		}

		$tree = GitHubAbilities::getRepoTree( $tree_input );
		if ( is_wp_error( $tree ) && '' !== $context['read_ref'] ) {
			unset( $tree_input['ref'] );
			$tree = GitHubAbilities::getRepoTree( $tree_input );
		}
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$max_results   = max( 1, min( 500, $max_results ) );
		$context_lines = max( 0, min( 10, $context_lines ) );
		$matches       = array();
		$seen          = array();
		$files         = (array) ( $tree['files'] ?? array() );

		foreach ( array_keys( (array) $context['pending_files'] ) as $pending_path ) {
			if ( '' === $prefix || $pending_path === $prefix || str_starts_with( $pending_path, $prefix . '/' ) ) {
				array_unshift( $files, array(
					'path' => $pending_path,
					'type' => 'file',
					'size' => strlen( (string) $context['pending_files'][ $pending_path ] ),
				) );
			}
		}

		foreach ( $files as $file ) {
			$file_path = (string) ( $file['path'] ?? '' );
			if ( '' === $file_path || isset( $seen[ $file_path ] ) || ! $this->path_matches_include( $file_path, $include_pattern ) ) {
				continue;
			}
			$seen[ $file_path ] = true;

			if ( (int) ( $file['size'] ?? 0 ) > self::MAX_READ_SIZE ) {
				continue;
			}

			$read = $this->read_file( $handle, $file_path, self::MAX_READ_SIZE );
			if ( is_wp_error( $read ) ) {
				continue;
			}

			$content = (string) ( $read['content'] ?? '' );
			if ( false !== strpos( substr( $content, 0, 8192 ), "\0" ) ) {
				continue;
			}

			$file_matches = $this->grep_content( $content, $handle, $file_path, $regex, $context_lines, $max_results - count( $matches ) );
			$matches      = array_merge( $matches, $file_matches );
			if ( count( $matches ) >= $max_results ) {
				break;
			}
		}

		return array(
			'success'   => true,
			'backend'   => 'github_api',
			'repo'      => $handle,
			'path'      => '' === $prefix ? '/' : $prefix,
			'pattern'   => $pattern,
			'matches'   => $matches,
			'count'     => count( $matches ),
			'truncated' => count( $matches ) >= $max_results,
		);
	}

	/**
	 * Stage file content in the remote workspace.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function write_file( string $handle, string $path, string $content ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$path = $this->normalize_path( $path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$state = $this->state();
		$state['worktrees'][ $context['handle'] ]['pending_files'][ $path ] = $content;
		$state['worktrees'][ $context['handle'] ]['changed_files'][ $path ] = $path;
		$this->save_state( $state );

		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'path'               => $path,
			'size'               => strlen( $content ),
			'created'            => true,
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'workspace_git_status',
			'next_required_args' => array( 'name' => $context['handle'] ),
		);
	}

	/**
	 * Stage a find-and-replace edit in the remote workspace.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function edit_file( string $handle, string $path, string $old_string, string $new_string, bool $replace_all = false ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$current = $this->read_file( $handle, $path, PHP_INT_MAX );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$content = (string) ( $current['content'] ?? '' );
		$count   = substr_count( $content, $old_string );
		if ( 0 === $count ) {
			return new \WP_Error( 'string_not_found', 'old_string not found in file content.', array(
				'status'      => 400,
				'path'        => (string) ( $current['path'] ?? $path ),
				'suggestions' => $this->build_edit_suggestions( $content, $old_string ),
			) );
		}
		if ( $count > 1 && ! $replace_all ) {
			return new \WP_Error( 'multiple_matches', sprintf( 'Found %d matches for old_string.', $count ), array( 'status' => 400 ) );
		}

		$new_content = $replace_all
			? str_replace( $old_string, $new_string, $content )
			: substr_replace( $content, $new_string, strpos( $content, $old_string ), strlen( $old_string ) );

		$write = $this->write_file( $handle, $path, $new_content );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'path'               => $write['path'],
			'replacements'       => $replace_all ? $count : 1,
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'workspace_git_status',
			'next_required_args' => array( 'name' => $context['handle'] ),
		);
	}

	/**
	 * Return pending remote workspace changes.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_status( string $handle ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$files = array_values( array_unique( array_values( (array) $context['changed_files'] ) ) );
		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'name'               => $handle,
			'repo'               => $context['repo_name'],
			'is_worktree'        => true,
			'path'               => 'github://' . $context['repo'] . '#' . $context['branch'],
			'branch'             => $context['branch'],
			'remote'             => 'https://github.com/' . $context['repo'] . '.git',
			'commit'             => '' !== $context['last_commit_sha'] ? $context['last_commit_sha'] : null,
			'dirty'              => count( $files ),
			'files'              => $files,
			'conversation_state' => 'incomplete',
			'next_required_tool' => count( $files ) > 0 ? 'workspace_git_commit' : 'workspace_edit or workspace_write',
			'next_required_args' => array( 'name' => $handle ),
		);
	}

	/**
	 * Compatibility no-op: files are tracked by pending remote workspace state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_add( string $handle, array $paths ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return array(
			'success' => true,
			'backend' => 'github_api',
			'name'    => $handle,
			'paths'   => array_values( array_map( 'strval', $paths ) ),
			'message' => 'Remote workspace changes are staged automatically.',
		);
	}

	/**
	 * Commit pending remote workspace changes through GitHub Contents API.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_commit( string $handle, string $message ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( '' === trim( $message ) ) {
			return new \WP_Error( 'missing_commit_message', 'Commit message is required.', array( 'status' => 400 ) );
		}

		$pending = (array) $context['pending_files'];
		if ( empty( $pending ) ) {
			return new \WP_Error( 'nothing_to_commit', 'No remote workspace changes to commit.', array( 'status' => 400 ) );
		}

		$last_sha = '';
		foreach ( $pending as $path => $content ) {
			$current_sha = $this->file_sha_for_commit( $context, (string) $path );
			if ( is_wp_error( $current_sha ) ) {
				return $current_sha;
			}

			$input = array(
				'repo'           => $context['repo'],
				'file_path'      => $path,
				'content'        => (string) $content,
				'commit_message' => $message,
				'branch'         => $context['branch'],
			);
			if ( '' !== $current_sha ) {
				$input['sha'] = $current_sha;
			}

			$result = GitHubAbilities::createOrUpdateFile(
				$input
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$last_sha = (string) ( $result['commit']['sha'] ?? $last_sha );
		}

		$state = $this->state();
		$state['worktrees'][ $context['handle'] ]['pending_files']   = array();
		$state['worktrees'][ $context['handle'] ]['last_commit_sha'] = $last_sha;
		$this->save_state( $state );

		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'name'               => $handle,
			'commit'             => $last_sha,
			'message'            => sprintf( 'Committed remote workspace changes to %s.', $context['branch'] ),
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'workspace_git_push',
			'next_required_args' => array(
				'name'   => $handle,
				'branch' => $context['branch'],
			),
		);
	}

	/**
	 * Resolve the current file SHA for a Contents API update.
	 *
	 * @param array<string,mixed> $context Remote workspace context.
	 */
	private function file_sha_for_commit( array $context, string $path ): string|\WP_Error {
		$file = GitHubAbilities::getFileContents(
			array(
				'repo' => $context['repo'],
				'path' => $path,
				'ref'  => $context['read_ref'],
			)
		);
		if ( is_wp_error( $file ) && '' !== $context['read_ref'] ) {
			$file = GitHubAbilities::getFileContents(
				array(
					'repo' => $context['repo'],
					'path' => $path,
				)
			);
		}
		if ( is_wp_error( $file ) ) {
			$status = (int) ( $file->get_error_data()['status'] ?? 0 );
			if ( 404 === $status ) {
				return '';
			}

			return $file;
		}
		if ( empty( $file['files'][0] ) ) {
			$status = (int) ( $file['errors'][0]['status'] ?? 0 );
			if ( 404 === $status ) {
				return '';
			}

			return new \WP_Error( 'remote_workspace_file_unavailable', $file['errors'][0]['message'] ?? sprintf( 'File not found: %s.', $path ), array( 'status' => 404 ) );
		}

		return (string) ( $file['files'][0]['sha'] ?? '' );
	}

	/**
	 * Compatibility no-op: commit already wrote to the remote branch.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function git_push( string $handle, string $remote = 'origin', ?string $branch = null ): array|\WP_Error {
		$context = $this->resolve_handle( $handle );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$push_branch = null !== $branch && '' !== $branch ? $branch : $context['branch'];
		$branch_url  = '' !== $push_branch ? 'https://github.com/' . $context['repo'] . '/tree/' . rawurlencode( $push_branch ) : null;

		return array(
			'success'            => true,
			'kind'               => 'branch_push',
			'backend'            => 'github_api',
			'name'               => $handle,
			'repo'               => $context['repo'],
			'workspace_repo'     => $context['repo_name'] ?? $handle,
			'github_repo'        => $context['repo'],
			'remote'             => $remote,
			'branch'             => $push_branch,
			'url'                => $branch_url,
			'html_url'           => $branch_url,
			'message'            => 'Remote workspace branch already updated via GitHub API.',
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'create_github_pull_request',
			'next_required_args' => array(
				'repo' => $context['repo'],
				'head' => $push_branch,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolve_handle( string $handle ): array|\WP_Error {
		$state = $this->state();
		if ( isset( $state['worktrees'][ $handle ] ) ) {
			$worktree             = (array) $state['worktrees'][ $handle ];
			$worktree['handle']   = $handle;
			$worktree['read_ref'] = (string) ( $worktree['branch'] ?? '' );
			return $worktree;
		}

		$repo = $this->resolve_repo( $handle );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		return array(
			'handle'          => $handle,
			'repo_name'       => $handle,
			'repo'            => $repo,
			'branch'          => '',
			'read_ref'        => '',
			'pending_files'   => array(),
			'changed_files'   => array(),
			'last_commit_sha' => '',
		);
	}

	private function resolve_repo( string $repo_name ): string|\WP_Error {
		$state = $this->state();
		if ( isset( $state['repos'][ $repo_name ]['repo'] ) ) {
			return (string) $state['repos'][ $repo_name ]['repo'];
		}

		if ( str_contains( $repo_name, '/' ) ) {
			return $repo_name;
		}

		return new \WP_Error( 'remote_workspace_repo_not_found', sprintf( 'Remote workspace repository "%s" is not registered. Call workspace_clone first.', $repo_name ), array( 'status' => 404 ) );
	}

	private function repo_from_url( string $url ): string|\WP_Error {
		$url = trim( $url );
		if ( preg_match( '#github\.com[:/]([^/]+)/([^/.]+)(?:\.git)?$#', $url, $matches ) ) {
			return $matches[1] . '/' . $matches[2];
		}

		return new \WP_Error( 'unsupported_remote_workspace_url', 'Remote workspace backend currently supports GitHub repository URLs only.', array( 'status' => 400 ) );
	}

	private function normalize_path( string $path ): string|\WP_Error {
		$path = trim( ltrim( $path, '/' ) );
		if ( '' === $path ) {
			return new \WP_Error( 'missing_path', 'File path is required.', array( 'status' => 400 ) );
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '.' === $part || '..' === $part || '' === $part ) {
				return new \WP_Error( 'path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ) );
			}
		}
		return $path;
	}

	private function sanitize_name( string $name ): string {
		return trim( strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $name ) ), '-' );
	}

	private function branch_slug( string $branch ): string {
		return trim( strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $branch ) ), '-' );
	}

	private function compile_search_pattern( string $pattern ): string|\WP_Error {
		if ( '' === $pattern ) {
			return new \WP_Error( 'missing_pattern', 'Search pattern is required.', array( 'status' => 400 ) );
		}

		$regex = '~' . str_replace( '~', '\\~', $pattern ) . '~u';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Validate user-supplied regex without surfacing PHP warnings.
		$previous_handler = set_error_handler( fn() => true );
		$is_valid         = false !== preg_match( $regex, '' );
		restore_error_handler();
		unset( $previous_handler );

		if ( ! $is_valid ) {
			return new \WP_Error( 'invalid_pattern', 'Search pattern is not a valid regular expression.', array( 'status' => 400 ) );
		}

		return $regex;
	}

	private function path_matches_include( string $path, ?string $include_pattern ): bool {
		if ( null === $include_pattern || '' === $include_pattern ) {
			return true;
		}

		return fnmatch( $include_pattern, $path ) || fnmatch( $include_pattern, basename( $path ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function grep_content( string $content, string $repo, string $path, string $regex, int $context_lines, int $limit ): array {
		$lines   = explode( "\n", $content );
		$matches = array();
		foreach ( $lines as $index => $line ) {
			if ( ! preg_match( $regex, $line ) ) {
				continue;
			}

			$start      = max( 0, $index - $context_lines );
			$end        = min( count( $lines ) - 1, $index + $context_lines );
			$read_limit = $end - $start + 1;

			$match = array(
				'match_id'  => substr( hash( 'sha256', $path . ':' . ( $index + 1 ) . ':' . $line ), 0, 16 ),
				'path'      => $path,
				'line'      => $index + 1,
				'text'      => $line,
				'preview'   => $this->build_preview( $lines, $start, $end ),
				'read_args' => array(
					'repo'   => $repo,
					'path'   => $path,
					'offset' => $start + 1,
					'limit'  => $read_limit,
				),
			);

			if ( $context_lines > 0 ) {
				$match['context'] = array();
				for ( $context_index = $start; $context_index <= $end; ++$context_index ) {
					$match['context'][] = array(
						'line' => $context_index + 1,
						'text' => $lines[ $context_index ],
					);
				}
			}

			$matches[] = $match;
			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	private function build_preview( array $lines, int $start, int $end ): string {
		$preview = array();
		for ( $context_index = $start; $context_index <= $end; ++$context_index ) {
			$preview[] = sprintf( '%d: %s', $context_index + 1, $lines[ $context_index ] );
		}

		return implode( "\n", $preview );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function build_edit_suggestions( string $content, string $old_string ): array {
		$candidates = array_values( array_filter( array_map( 'trim', explode( "\n", $old_string ) ), static fn( $line ) => strlen( $line ) >= 4 ) );
		usort( $candidates, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$needle = $candidates[0] ?? trim( $old_string );
		if ( '' === $needle ) {
			return array();
		}

		$needle      = substr( $needle, 0, 120 );
		$lines       = explode( "\n", $content );
		$suggestions = array();
		foreach ( $lines as $index => $line ) {
			if ( false === strpos( $line, $needle ) ) {
				continue;
			}

			$start         = max( 0, $index - 2 );
			$end           = min( count( $lines ) - 1, $index + 2 );
			$suggestions[] = array(
				'line'    => $index + 1,
				'text'    => $line,
				'preview' => $this->build_preview( $lines, $start, $end ),
			);

			if ( count( $suggestions ) >= 3 ) {
				break;
			}
		}

		return $suggestions;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['repos']      = is_array( $state['repos'] ?? null ) ? $state['repos'] : array();
		$state['repo_names'] = is_array( $state['repo_names'] ?? null ) ? $state['repo_names'] : array();
		$state['worktrees']  = is_array( $state['worktrees'] ?? null ) ? $state['worktrees'] : array();
		return $state;
	}

	/**
	 * @param array<string,mixed> $state State to persist.
	 */
	private function save_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $state, false );
		}
	}
}
