<?php
/**
 * Workspace File Reader
 *
 * Read-only file operations within the agent workspace — reading file
 * contents and listing directory entries in cloned repositories.
 *
 * @package DataMachineCode\Workspace
 * @since 0.32.0
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

class WorkspaceReader {

	/**
	 * @var Workspace
	 */
	private Workspace $workspace;

	/**
	 * @param Workspace $workspace Workspace instance for path resolution and validation.
	 */
	public function __construct( Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * @param string     $name     Repository directory name.
	 * @param string     $path     Relative file path within the repo.
	 * @param int        $max_size Maximum file size in bytes.
	 * @param int|null   $offset   Line number to start reading from (1-indexed).
	 * @param int|null   $limit    Maximum number of lines to return.
	 * @return array{success: bool, content?: string, path?: string, size?: int, lines_read?: int, offset?: int}|\WP_Error
	 */
	public function read_file( string $name, string $path, int $max_size = Workspace::MAX_READ_SIZE, ?int $offset = null, ?int $limit = null ): array|\WP_Error {
		$repo_path = $this->workspace->get_repo_path( $name );
		$path      = ltrim( $path, '/' );

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $name ), array( 'status' => 404 ) );
		}

		$file_path  = $repo_path . '/' . $path;
		$validation = $this->workspace->validate_containment( $file_path, $repo_path );

		if ( ! $validation['valid'] ) {
			return new \WP_Error( 'path_traversal', $validation['message'], array( 'status' => 403 ) );
		}

		$real_path = $validation['real_path'];

		if ( ! is_file( $real_path ) ) {
			return new \WP_Error( 'file_not_found', sprintf( 'File not found: %s', $path ), array( 'status' => 404 ) );
		}

		if ( ! is_readable( $real_path ) ) {
			return new \WP_Error( 'file_not_readable', sprintf( 'File not readable: %s', $path ), array( 'status' => 403 ) );
		}

		$size = filesize( $real_path );

		if ( $size > $max_size ) {
			return new \WP_Error( 'file_too_large', sprintf(
				'File too large: %s (%s). Maximum: %s.',
				$path,
				size_format( $size ),
				size_format( $max_size )
			), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $real_path );

		if ( false === $content ) {
			return new \WP_Error( 'read_failed', sprintf( 'Failed to read file: %s', $path ), array( 'status' => 500 ) );
		}

		// Detect binary: check for null bytes in first 8 KB.
		$sample = substr( $content, 0, 8192 );
		if ( false !== strpos( $sample, "\0" ) ) {
			return new \WP_Error( 'binary_file', sprintf( 'Binary file detected: %s. Only text files can be read.', $path ), array( 'status' => 400 ) );
		}

		// Apply line offset and limit if specified.
		$lines_read = 0;
		$start_line = 1;
		if ( null !== $offset || null !== $limit ) {
			$lines       = explode( "\n", $content );
			$total_lines = count( $lines );

			if ( null !== $offset ) {
				$start_line = max( 1, $offset );
				$lines      = array_slice( $lines, $start_line - 1 );
			}

			if ( null !== $limit ) {
				$lines = array_slice( $lines, 0, $limit );
			}

			$content    = implode( "\n", $lines );
			$lines_read = count( $lines );
		}

		$result = array(
			'success' => true,
			'content' => $content,
			'path'    => $path,
			'size'    => $size,
		);

		if ( null !== $offset || null !== $limit ) {
			$result['lines_read'] = $lines_read;
			$result['offset']     = $start_line;
		}

		return $result;
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * @param string      $name Repository directory name.
	 * @param string|null $path Relative directory path within the repo (null for root).
	 * @return array{success: bool, repo?: string, path?: string, entries?: array}|\WP_Error
	 */
	public function list_directory( string $name, ?string $path = null ): array|\WP_Error {
		$repo_path = $this->workspace->get_repo_path( $name );

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $name ), array( 'status' => 404 ) );
		}

		$target_path = $repo_path;

		if ( null !== $path && '' !== $path ) {
			$path        = ltrim( $path, '/' );
			$target_path = $repo_path . '/' . $path;

			$validation = $this->workspace->validate_containment( $target_path, $repo_path );

			if ( ! $validation['valid'] ) {
				return new \WP_Error( 'path_traversal', $validation['message'], array( 'status' => 403 ) );
			}

			$target_path = $validation['real_path'];
		}

		if ( ! is_dir( $target_path ) ) {
			return new \WP_Error( 'directory_not_found', sprintf( 'Directory not found: %s', $path ?? '/' ), array( 'status' => 404 ) );
		}

		$entries = scandir( $target_path );
		$items   = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $target_path . '/' . $entry;
			$is_dir     = is_dir( $entry_path );

			$item = array(
				'name' => $entry,
				'type' => $is_dir ? 'directory' : 'file',
			);

			if ( ! $is_dir ) {
				$item['size'] = filesize( $entry_path );
			}

			$items[] = $item;
		}

		// Sort: directories first, then alphabetical.
		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return ( 'directory' === $a['type'] ) ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'success' => true,
			'repo'    => $name,
			'path'    => $path ?? '/',
			'entries' => $items,
		);
	}
}
