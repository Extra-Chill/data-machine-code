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

	private const OPTION = 'datamachine_code_remote_workspace_state';

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

		$name = $this->sanitize_name( $name ?: basename( (string) $repo ) );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_clone_name', 'Could not derive a workspace name for the remote repository.', array( 'status' => 400 ) );
		}

		$state                    = $this->state();
		$state['repos'][ $name ]   = array(
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

		$slug   = $this->branch_slug( $branch );
		$handle = $repo_name . '@' . $slug;
		$state  = $this->state();
		$state['worktrees'][ $handle ] = array(
			'repo_name'      => $repo_name,
			'repo'           => $repo,
			'branch'         => $branch,
			'base_ref'       => $from ?: '',
			'pending_files'  => array(),
			'changed_files'  => array(),
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
			$file = GitHubAbilities::getFileContents(
				array(
					'repo' => $context['repo'],
					'path' => $path,
					'ref'  => $context['read_ref'],
				)
			);
			if ( is_wp_error( $file ) && $context['read_ref'] !== '' ) {
				$file = GitHubAbilities::getFileContents( array( 'repo' => $context['repo'], 'path' => $path ) );
			}
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$content = (string) ( $file['file']['content'] ?? '' );
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
		$tree   = GitHubAbilities::getRepoTree( array( 'repo' => $context['repo'], 'ref' => $context['read_ref'] ) );
		if ( is_wp_error( $tree ) && $context['read_ref'] !== '' ) {
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
			return new \WP_Error( 'string_not_found', 'old_string not found in file content.', array( 'status' => 400 ) );
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
			'commit'             => $context['last_commit_sha'] ?: null,
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
			'next_required_args' => array( 'name' => $handle, 'branch' => $context['branch'] ),
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
			$file = GitHubAbilities::getFileContents( array( 'repo' => $context['repo'], 'path' => $path ) );
		}
		if ( is_wp_error( $file ) ) {
			$status = (int) ( $file->get_error_data()['status'] ?? 0 );
			if ( 404 === $status ) {
				return '';
			}

			return $file;
		}

		return (string) ( $file['file']['sha'] ?? '' );
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

		return array(
			'success'            => true,
			'backend'            => 'github_api',
			'name'               => $handle,
			'remote'             => $remote,
			'branch'             => $branch ?: $context['branch'],
			'message'            => 'Remote workspace branch already updated via GitHub API.',
			'conversation_state' => 'incomplete',
			'next_required_tool' => 'create_github_pull_request',
			'next_required_args' => array( 'repo' => $context['repo'], 'head' => $branch ?: $context['branch'] ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolve_handle( string $handle ): array|\WP_Error {
		$state = $this->state();
		if ( isset( $state['worktrees'][ $handle ] ) ) {
			$worktree = (array) $state['worktrees'][ $handle ];
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
