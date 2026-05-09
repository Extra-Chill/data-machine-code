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
use DataMachineCode\Support\GitRunner;

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
			return new \WP_Error( 'string_not_found', 'old_string not found in file content.', array(
				'status'      => 400,
				'path'        => $path,
				'suggestions' => $this->build_edit_suggestions( $content, $old_string ),
			) );
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
	 * Apply a unified diff to a workspace repo using git's context checks.
	 *
	 * The patch is checked before it is applied, so stale context or mismatched
	 * file contents fail closed without mutating the checkout.
	 *
	 * @param string $name                   Workspace handle.
	 * @param string $patch                  Unified diff to apply.
	 * @param bool   $allow_primary_mutation Permit mutation on a primary checkout.
	 * @return array{success: bool, name: string, path: string, changed_files: string[], diff: string, status: string, check_output: string, apply_output: string}|\WP_Error
	 */
	public function apply_patch( string $name, string $patch, bool $allow_primary_mutation = false ): array|\WP_Error {
		$repo_path = $this->workspace->get_repo_path( $name );
		$parsed    = $this->workspace->parse_handle( $name );

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Repository "%s" not found in workspace.', $name ), array( 'status' => 404 ) );
		}

		if ( empty( $parsed['is_worktree'] ) && ! $allow_primary_mutation ) {
			return new \WP_Error(
				'primary_mutation_blocked',
				sprintf(
					'Primary checkout "%s" is read-only by default. Pass allow_primary_mutation=true to operate on it, or use a worktree handle (e.g. %s@<branch-slug>).',
					$parsed['repo'],
					$parsed['repo']
				),
				array( 'status' => 403 )
			);
		}

		$patch = str_replace( "\r\n", "\n", $patch );
		if ( '' === trim( $patch ) ) {
			return new \WP_Error( 'empty_patch', 'Patch content is required.', array( 'status' => 400 ) );
		}

		if ( false === strpos( $patch, '--- ' ) || false === strpos( $patch, '+++ ' ) || false === strpos( $patch, '@@' ) ) {
			return new \WP_Error( 'invalid_patch', 'Patch must be a unified diff with file headers and at least one hunk.', array( 'status' => 400 ) );
		}

		$temp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'datamachine-code-patch-' ) : tempnam( sys_get_temp_dir(), 'datamachine-code-patch-' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return new \WP_Error( 'patch_tempfile_failed', 'Failed to create a temporary patch file.', array( 'status' => 500 ) );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$bytes = file_put_contents( $temp, $patch );
			if ( false === $bytes ) {
				return new \WP_Error( 'patch_tempfile_failed', 'Failed to write the temporary patch file.', array( 'status' => 500 ) );
			}

			$patch_arg = escapeshellarg( $temp );
			$check     = GitRunner::run( $repo_path, 'apply --check --whitespace=nowarn ' . $patch_arg );
			if ( is_wp_error( $check ) ) {
				return new \WP_Error(
					'patch_check_failed',
					'Patch did not apply cleanly: ' . $check->get_error_message(),
					array(
						'status' => 400,
						'output' => $check->get_error_data()['output'] ?? '',
					)
				);
			}

			$apply = GitRunner::run( $repo_path, 'apply --whitespace=nowarn ' . $patch_arg );
			if ( is_wp_error( $apply ) ) {
				return new \WP_Error(
					'patch_apply_failed',
					'Patch check passed but apply failed: ' . $apply->get_error_message(),
					array(
						'status' => 500,
						'output' => $apply->get_error_data()['output'] ?? '',
					)
				);
			}

			$diff    = GitRunner::run( $repo_path, 'diff --no-ext-diff --binary' );
			$status  = GitRunner::run( $repo_path, 'status --porcelain --untracked-files=all' );
			$changed = GitRunner::run( $repo_path, 'diff --name-only' );
			$status_output = is_wp_error( $status ) ? '' : $status['output'];
			$changed_files = array_unique( array_merge(
				$this->split_git_lines( is_wp_error( $changed ) ? '' : $changed['output'] ),
				$this->changed_files_from_status( $status_output )
			) );

			return array(
				'success'       => true,
				'name'          => $name,
				'path'          => $repo_path,
				'changed_files' => array_values( $changed_files ),
				'diff'          => is_wp_error( $diff ) ? '' : $diff['output'],
				'status'        => $status_output,
				'check_output'  => $check['output'],
				'apply_output'  => $apply['output'],
			);
		} finally {
			if ( is_file( $temp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $temp );
			}
		}
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

	private function build_preview( array $lines, int $start, int $end ): string {
		$preview = array();
		for ( $context_index = $start; $context_index <= $end; ++$context_index ) {
			$preview[] = sprintf( '%d: %s', $context_index + 1, $lines[ $context_index ] );
		}

		return implode( "\n", $preview );
	}

	/**
	 * Split git command output into non-empty lines.
	 *
	 * @param string $output Git command output.
	 * @return string[]
	 */
	private function split_git_lines( string $output ): array {
		$lines = preg_split( '/\r?\n/', trim( $output ) );
		if ( false === $lines ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Extract changed paths from git status porcelain output, including untracked files.
	 *
	 * @param string $status Git status --porcelain output.
	 * @return string[]
	 */
	private function changed_files_from_status( string $status ): array {
		$files = array();
		$lines = preg_split( '/\r?\n/', rtrim( $status ) );
		if ( false === $lines ) {
			return array();
		}

		foreach ( $lines as $line ) {
			if ( strlen( $line ) < 4 ) {
				continue;
			}
			$path = trim( substr( $line, 3 ) );
			if ( str_contains( $path, ' -> ' ) ) {
				$parts = explode( ' -> ', $path );
				$path  = trim( (string) end( $parts ) );
			}
			if ( '' !== $path ) {
				$files[] = $path;
			}
		}

		return array_values( array_unique( $files ) );
	}
}
