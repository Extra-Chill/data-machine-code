<?php
/**
 * Workspace File Writer
 *
 * Write and edit operations within the agent workspace — creating new files,
 * overwriting existing files, and performing find-and-replace edits in
 * cloned repositories.
 *
 * All operations are contained within the workspace via path validation.
 * Mutating abilities are CLI-only (show_in_rest = false) for safety.
 *
 * @package DataMachineCode\Workspace
 * @since 0.32.0
 */

namespace DataMachineCode\Workspace;

use DataMachine\Core\FilesRepository\FilesystemHelper;

defined( 'ABSPATH' ) || exit;

class WorkspaceWriter {

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
	 * Write (create or overwrite) a file in a workspace repo.
	 *
	 * Creates parent directories as needed. Path traversal is blocked
	 * by rejecting ".." and "." components pre-write, then verified
	 * post-write via realpath()-based containment check (catches symlinks).
	 *
	 * @param string $name    Repository directory name.
	 * @param string $path    Relative file path within the repo.
	 * @param string $content File content to write.
	 * @return array{success: bool, path?: string, size?: int, created?: bool}|\WP_Error
	 */
	public function write_file( string $name, string $path, string $content ): array|\WP_Error {
		$repo_path = $this->workspace->get_repo_path( $name );
		$path      = ltrim( $path, '/' );

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $name ), array( 'status' => 404 ) );
		}

		if ( '' === $path ) {
			return new \WP_Error( 'missing_path', 'File path is required.', array( 'status' => 400 ) );
		}

		// Reject path traversal components.
		if ( $this->has_traversal( $path ) ) {
			return new \WP_Error( 'path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ) );
		}

		$file_path = $repo_path . '/' . $path;
		$existed   = file_exists( $file_path );

		// Ensure parent directory exists.
		$parent = dirname( $file_path );
		if ( ! is_dir( $parent ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			$created = mkdir( $parent, 0755, true );
			if ( ! $created ) {
				return new \WP_Error( 'mkdir_failed', sprintf( 'Failed to create directory: %s', dirname( $path ) ), array( 'status' => 500 ) );
			}
		}

		// Write the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $file_path, $content );

		if ( false === $bytes ) {
			return new \WP_Error( 'write_failed', sprintf( 'Failed to write file: %s', $path ), array( 'status' => 500 ) );
		}

		// Belt-and-suspenders: verify the written file actually landed inside
		// the repo. has_traversal() catches simple "../" tricks, but symlinks
		// or creative encoding could slip past. realpath() now works because
		// the file exists on disk.
		$containment = $this->workspace->validate_containment( $file_path, $repo_path );
		if ( ! $containment['valid'] ) {
			if ( file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file_path );
			}
			return new \WP_Error( 'path_traversal', 'Path traversal detected. Written file removed.', array( 'status' => 403 ) );
		}

		return array(
			'success' => true,
			'path'    => $path,
			'size'    => $bytes,
			'created' => ! $existed,
		);
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * Finds an exact match of old_string and replaces it with new_string.
	 * Fails if old_string is not found or has multiple matches (unless
	 * replace_all is true).
	 *
	 * @param string $name        Repository directory name.
	 * @param string $path        Relative file path within the repo.
	 * @param string $old_string  Text to find.
	 * @param string $new_string  Replacement text.
	 * @param bool   $replace_all Replace all occurrences (default false).
	 * @return array{success: bool, path?: string, replacements?: int}|\WP_Error
	 */
	public function edit_file( string $name, string $path, string $old_string, string $new_string, bool $replace_all = false ): array|\WP_Error {
		$fs        = FilesystemHelper::get();
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

		if ( ! is_readable( $real_path ) || ! $fs->is_writable( $real_path ) ) {
			return new \WP_Error( 'file_not_writable', sprintf( 'File not readable/writable: %s', $path ), array( 'status' => 403 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $real_path );

		if ( false === $content ) {
			return new \WP_Error( 'read_failed', sprintf( 'Failed to read file: %s', $path ), array( 'status' => 500 ) );
		}

		if ( $old_string === $new_string ) {
			return new \WP_Error( 'identical_strings', 'old_string and new_string are identical.', array( 'status' => 400 ) );
		}

		// Count occurrences.
		$count = substr_count( $content, $old_string );

		if ( 0 === $count ) {
			return new \WP_Error( 'string_not_found', 'old_string not found in file content.', array( 'status' => 400 ) );
		}

		if ( $count > 1 && ! $replace_all ) {
			return new \WP_Error( 'multiple_matches', sprintf(
				'Found %d matches for old_string. Use replace_all to replace all, or provide more context to make the match unique.',
				$count
			), array( 'status' => 400 ) );
		}

		// Perform replacement.
		if ( $replace_all ) {
			$new_content = str_replace( $old_string, $new_string, $content );
		} else {
			$pos         = strpos( $content, $old_string );
			$new_content = substr_replace( $content, $new_string, $pos, strlen( $old_string ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $real_path, $new_content );

		if ( false === $bytes ) {
			return new \WP_Error( 'write_failed', sprintf( 'Failed to write file: %s', $path ), array( 'status' => 500 ) );
		}

		return array(
			'success'      => true,
			'path'         => $path,
			'replacements' => $replace_all ? $count : 1,
		);
	}

	/**
	 * Check if a relative path contains traversal components.
	 *
	 * @param string $path Relative path to check.
	 * @return bool True if path contains ".." or "." components.
	 */
	private function has_traversal( string $path ): bool {
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '.' === $part ) {
				return true;
			}
		}
		return false;
	}
}
