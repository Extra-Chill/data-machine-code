<?php
/**
 * Workspace File Reader
 *
 * Read-only file operations within the agent workspace — reading file
 * contents and listing directory entries in cloned repositories.
 *
 * @package DataMachineCode\Workspace
 * @since   0.32.0
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

if ( ! class_exists(WorkspaceText::class) ) {
	require_once __DIR__ . '/WorkspaceText.php';
}

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
	 * @param  string   $name     Repository directory name.
	 * @param  string   $path     Relative file path within the repo.
	 * @param  int      $max_size Maximum file size in bytes.
	 * @param  int|null $offset   Line number to start reading from (1-indexed).
	 * @param  int|null $limit    Maximum number of lines to return.
	 * @return array{success: bool, content?: string, path?: string, size?: int, lines_read?: int, offset?: int}|\WP_Error
	 */
	public function read_file( string $name, string $path, int $max_size = Workspace::MAX_READ_SIZE, ?int $offset = null, ?int $limit = null, bool $allow_stale_primary = false ): array|\WP_Error {
		$repo_path = $this->prepare_workspace_read($name, $path, $allow_stale_primary);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}
		$path      = ltrim($path, '/');

		$file_path  = $repo_path . '/' . $path;
		$validation = $this->workspace->validate_containment($file_path, $repo_path);

		if ( ! $validation['valid'] ) {
			return new \WP_Error('path_traversal', (string) ( $validation['message'] ?? 'Path traversal detected. Access denied.' ), array( 'status' => 403 ));
		}

		$real_path = (string) ( $validation['real_path'] ?? '' );

		if ( ! is_file($real_path) ) {
			return new \WP_Error('file_not_found', sprintf('File not found: %s', $path), array( 'status' => 404 ));
		}

		if ( ! is_readable($real_path) ) {
			return new \WP_Error('file_not_readable', sprintf('File not readable: %s', $path), array( 'status' => 403 ));
		}

		$size = filesize($real_path);
		if ( false === $size ) {
			return new \WP_Error('file_size_failed', sprintf('Failed to determine file size: %s', $path), array( 'status' => 500 ));
		}

		if ( $size > $max_size ) {
			return new \WP_Error(
				'file_too_large', sprintf(
					'File too large: %s (%s). Maximum: %s.',
					$path,
					size_format($size),
					size_format($max_size)
				), array( 'status' => 400 )
			);
		}

		// Streaming read is required for bounded/offset reads; WP_Filesystem has no streaming API.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen($real_path, 'rb');
		if ( false === $handle ) {
			return new \WP_Error('read_failed', sprintf('Failed to read file: %s', $path), array( 'status' => 500 ));
		}

		// Detect binary: check for null bytes in first 8 KB without loading the whole file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$sample = fread($handle, 8192);
		if ( false === $sample ) {
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error('read_failed', sprintf('Failed to read file: %s', $path), array( 'status' => 500 ));
		}
		if ( false !== strpos($sample, "\0") ) {
			fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error('binary_file', sprintf('Binary file detected: %s. Only text files can be read.', $path), array( 'status' => 400 ));
		}

		$lines_read = 0;
		$start_line = 1;
		if ( null !== $offset || null !== $limit ) {
			rewind($handle);
			$slice      = $this->read_line_slice($handle, $offset, $limit);
			$content    = $slice['content'];
			$lines_read = $slice['lines_read'];
			$start_line = $slice['offset'];
		} else {
			rewind($handle);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_stream_get_contents
			$content = stream_get_contents($handle);
			if ( false === $content ) {
				fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error('read_failed', sprintf('Failed to read file: %s', $path), array( 'status' => 500 ));
			}
		}
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

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

		return $this->with_context_policy($name, $result);
	}

	/**
	 * Read a bounded line slice from an already-open text file handle.
	 *
	 * @param resource $handle Open readable file handle.
	 * @return array{content:string,lines_read:int,offset:int}
	 */
	private function read_line_slice( $handle, ?int $offset, ?int $limit ): array {
		$start_line = max(1, (int) ( $offset ?? 1 ));
		$max_lines  = null === $limit ? null : max(0, $limit);
		$line_no    = 0;
		$lines      = array();

		while ( ! feof($handle) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgets
			$line = fgets($handle);
			if ( false === $line ) {
				break;
			}
			++$line_no;
			if ( $line_no < $start_line ) {
				continue;
			}
			if ( null !== $max_lines && count($lines) >= $max_lines ) {
				break;
			}
			$lines[] = rtrim($line, "\n");
		}

		return array(
			'content'    => implode("\n", $lines),
			'lines_read' => count($lines),
			'offset'     => $start_line,
		);
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * @param  string      $name Repository directory name.
	 * @param  string|null $path Relative directory path within the repo (null for root).
	 * @return array{success: bool, repo?: string, path?: string, entries?: array}|\WP_Error
	 */
	public function list_directory( string $name, ?string $path = null, bool $allow_stale_primary = false ): array|\WP_Error {
		$repo_path = $this->prepare_workspace_read($name, $path ?? '', $allow_stale_primary);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$target_path = $repo_path;

		if ( null !== $path && '' !== $path ) {
			$path        = ltrim($path, '/');
			$target_path = $repo_path . '/' . $path;

			$validation = $this->workspace->validate_containment($target_path, $repo_path);

			if ( ! $validation['valid'] ) {
				return new \WP_Error('path_traversal', (string) ( $validation['message'] ?? 'Path traversal detected. Access denied.' ), array( 'status' => 403 ));
			}

			$target_path = (string) ( $validation['real_path'] ?? '' );
		}

		if ( ! is_dir($target_path) ) {
			return new \WP_Error('directory_not_found', sprintf('Directory not found: %s', $path ?? '/'), array( 'status' => 404 ));
		}

		$entries = scandir($target_path);
		$items   = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $target_path . '/' . $entry;
			$is_dir     = is_dir($entry_path);
			$size       = 0;

			if ( ! $is_dir && is_file($entry_path) ) {
				$file_size = filesize($entry_path);
				$size      = false === $file_size ? 0 : (int) $file_size;
			}

			$item = array(
				'name' => $entry,
				'type' => $is_dir ? 'directory' : 'file',
				'size' => $size,
			);

			$items[] = $item;
		}

		// Sort: directories first, then alphabetical.
		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return ( 'directory' === $a['type'] ) ? -1 : 1;
				}
				return strcasecmp($a['name'], $b['name']);
			}
		);

		$items = WorkspaceAliasResolver::filter_context_entries($name, $path ?? '/', $items);

		$result = array(
			'success' => true,
			'repo'    => $name,
			'path'    => $path ?? '/',
			'entries' => $items,
		);

		return $this->with_context_policy($name, $result);
	}

	/**
	 * Search text files in a workspace repo.
	 *
	 * @param  string      $name            Repository directory name.
	 * @param  string      $pattern         PCRE pattern body to search for.
	 * @param  string|null $path            Optional relative directory/file path to limit search.
	 * @param  string|null $include_pattern Optional glob pattern for file paths.
	 * @param  int         $max_results     Maximum number of matches to return.
	 * @param  int         $context_lines   Number of surrounding lines to include.
	 * @return array{success: bool, repo?: string, path?: string, pattern?: string, matches?: array, count?: int, truncated?: bool}|\WP_Error
	 */
	public function grep( string $name, string $pattern, ?string $path = null, ?string $include_pattern = null, int $max_results = 100, int $context_lines = 0, bool $allow_stale_primary = false ): array|\WP_Error {
		$repo_path = $this->prepare_workspace_read($name, $path ?? '', $allow_stale_primary);
		if ( is_wp_error($repo_path) ) {
			return $repo_path;
		}

		$repo_real = realpath($repo_path);
		if ( false === $repo_real ) {
			return new \WP_Error('repo_not_found', sprintf('Repository "%s" not found in workspace.', $name), array( 'status' => 404 ));
		}

		$target_path = $repo_real;
		$search_path = '/';
		if ( null !== $path && '' !== $path ) {
			$path        = ltrim($path, '/');
			$target_path = $repo_real . '/' . $path;
			$validation  = $this->workspace->validate_containment($target_path, $repo_real);
			if ( ! $validation['valid'] ) {
				return new \WP_Error('path_traversal', (string) ( $validation['message'] ?? 'Path traversal detected. Access denied.' ), array( 'status' => 403 ));
			}
			$target_path = (string) ( $validation['real_path'] ?? '' );
			$search_path = $path;
		}

		if ( ! is_file($target_path) && ! is_dir($target_path) ) {
			return new \WP_Error('path_not_found', sprintf('Path not found: %s', $path ?? '/'), array( 'status' => 404 ));
		}

		$regex = WorkspaceText::compile_search_pattern($pattern);
		if ( is_wp_error($regex) ) {
			return $regex;
		}

		$matches       = array();
		$max_results   = max(1, min(500, $max_results));
		$context_lines = max(0, min(10, $context_lines));
		$files         = is_file($target_path) ? array( $target_path ) : $this->iterable_files($target_path);

		foreach ( $files as $file_path ) {
			$relative_path  = ltrim(substr($file_path, strlen($repo_real)), '/');
			$context_policy = WorkspaceAliasResolver::context_policy_for($name);
			if ( null !== $context_policy && ! WorkspaceAliasResolver::path_allowed_by_policy($relative_path, $context_policy) ) {
				continue;
			}
			if ( str_starts_with($relative_path, '.git/') || ! WorkspaceText::path_matches_include($relative_path, $include_pattern) ) {
				continue;
			}

			$file_matches = $this->grep_file($name, $file_path, $relative_path, $regex, $context_lines, $max_results - count($matches));
			if ( is_wp_error($file_matches) ) {
				continue;
			}

			$matches = array_merge($matches, $file_matches);
			if ( count($matches) >= $max_results ) {
				break;
			}
		}

		$result = array(
			'success'   => true,
			'repo'      => $name,
			'path'      => $search_path,
			'pattern'   => $pattern,
			'matches'   => $matches,
			'count'     => count($matches),
			'truncated' => count($matches) >= $max_results,
		);

		return $this->with_context_policy($name, $result);
	}

	/** Validate read access and resolve an existing local repository path. */
	private function prepare_workspace_read( string $name, string $path, bool $allow_stale_primary ): string|\WP_Error {
		$handle_check = $this->workspace->require_explicit_workspace_handle($name);
		if ( is_wp_error($handle_check) ) {
			return $handle_check;
		}

		$policy_error = WorkspaceAliasResolver::read_error_if_disallowed($name, $path);
		if ( null !== $policy_error ) {
			return $policy_error;
		}

		$primary_read = $this->workspace->ensure_primary_read_allowed($name, $allow_stale_primary);
		if ( is_wp_error($primary_read) ) {
			return $primary_read;
		}

		$repo_path = $this->workspace->get_repo_path($name);
		if ( ! is_dir($repo_path) ) {
			return new \WP_Error('repo_not_found', sprintf('Repository "%s" not found in workspace.', $name), array( 'status' => 404 ));
		}

		return $repo_path;
	}

	/** Attach the read-only context policy attestation when the handle requires one. */
	private function with_context_policy( string $name, array $result ): array {
		if ( WorkspaceAliasResolver::is_context_repository($name) ) {
			$result['workspace_policy'] = WorkspaceAliasResolver::policy_attestation($name);
		}

		return $result;
	}

	/**
	 * @return iterable<string>
	 */
	private function iterable_files( string $path ): iterable {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
				function ( \SplFileInfo $file ) {
						return '.git' !== $file->getFilename();
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->isReadable() ) {
				yield $file->getPathname();
			}
		}
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function grep_file( string $repo, string $file_path, string $relative_path, string $regex, int $context_lines, int $limit ): array|\WP_Error {
		if ( $limit < 1 ) {
			return new \WP_Error('grep_limit_exhausted', 'Search result limit exhausted.', array( 'status' => 400 ));
		}

		$size = filesize($file_path);
		if ( false === $size || $size > Workspace::MAX_READ_SIZE ) {
			return array();
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($file_path);
		if ( false === $content || false !== strpos(substr($content, 0, 8192), "\0") ) {
			return array();
		}

		return WorkspaceText::grep_content($content, $repo, $relative_path, $regex, $context_lines, $limit);
	}
}
