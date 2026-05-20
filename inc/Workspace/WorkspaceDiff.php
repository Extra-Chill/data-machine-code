<?php
/**
 * Workspace Diff
 *
 * Read-only summary and validation helpers for workspace git diffs.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;

defined( 'ABSPATH' ) || exit;

class WorkspaceDiff {

	private Workspace $workspace;

	public function __construct( ?Workspace $workspace = null ) {
		$this->workspace = null !== $workspace ? $workspace : new Workspace();
	}

	/**
	 * Summarize changed files and compact git diff metadata for a workspace repository.
	 *
	 * @param string      $name   Workspace handle.
	 * @param string|null $from   Optional from ref.
	 * @param string|null $to     Optional to ref.
	 * @param bool        $staged Whether to summarize staged changes.
	 * @param string|null $path   Optional relative path filter.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function summary( string $name, ?string $from = null, ?string $to = null, bool $staged = false, ?string $path = null ): array|\WP_Error {
		$repo_path = $this->resolve_repo_path( $name );
		if ( is_wp_error( $repo_path ) ) {
			return $repo_path;
		}

		$path_args = $this->build_path_args( $path );
		if ( is_wp_error( $path_args ) ) {
			return $path_args;
		}

		$base_args   = $this->build_diff_args( $from, $to, $staged );
		$numstat     = GitRunner::run( $repo_path, implode( ' ', array_merge( $base_args, array( '--numstat' ), $path_args ) ) );
		$name_status = GitRunner::run( $repo_path, implode( ' ', array_merge( $base_args, array( '--name-status' ), $path_args ) ) );
		$shortstat   = GitRunner::run( $repo_path, implode( ' ', array_merge( $base_args, array( '--shortstat' ), $path_args ) ) );
		$status      = GitRunner::run( $repo_path, 'status --porcelain' . ( empty( $path_args ) ? '' : ' -- ' . escapeshellarg( trim( (string) $path ) ) ) );

		foreach ( array( $numstat, $name_status, $shortstat, $status ) as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$files = $this->parse_diff_files( (string) ( $numstat['output'] ?? '' ), (string) ( $name_status['output'] ?? '' ) );
		if ( empty( $from ) && empty( $to ) && ! $staged ) {
			$files = $this->merge_untracked_diff_files( $files, (string) ( $status['output'] ?? '' ) );
		}

		$changed_files = array_values( array_map( static fn( array $file ): string => (string) $file['path'], $files ) );
		$additions     = 0;
		$deletions     = 0;
		$tests_touched = false;
		foreach ( $files as $file ) {
			if ( is_int( $file['additions'] ) ) {
				$additions += $file['additions'];
			}
			if ( is_int( $file['deletions'] ) ) {
				$deletions += $file['deletions'];
			}
			$tests_touched = $tests_touched || (bool) $file['is_test'];
		}

		$parsed = $this->workspace->parse_handle( $name );
		return array(
			'success'       => true,
			'name'          => $parsed['dir_name'],
			'repo'          => $parsed['repo'],
			'from'          => $from,
			'to'            => $to,
			'staged'        => $staged,
			'path'          => $path,
			'changed_files' => $changed_files,
			'files'         => $files,
			'totals'        => array(
				'files'         => count( $files ),
				'additions'     => $additions,
				'deletions'     => $deletions,
				'tests_touched' => $tests_touched,
			),
			'raw'           => array(
				'numstat'     => (string) ( $numstat['output'] ?? '' ),
				'name_status' => (string) ( $name_status['output'] ?? '' ),
				'shortstat'   => trim( (string) ( $shortstat['output'] ?? '' ) ),
				'porcelain'   => (string) ( $status['output'] ?? '' ),
			),
		);
	}

	/**
	 * Validate workspace diff shape against simple path policies.
	 *
	 * @param string              $name   Workspace handle.
	 * @param array<string,mixed> $config Validation config.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate( string $name, array $config ): array|\WP_Error {
		$summary = $this->summary(
			$name,
			isset( $config['from'] ) ? (string) $config['from'] : null,
			isset( $config['to'] ) ? (string) $config['to'] : null,
			! empty( $config['staged'] ),
			isset( $config['path'] ) ? (string) $config['path'] : null
		);
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$required      = isset( $config['require_changed_files'] ) && is_array( $config['require_changed_files'] ) ? $config['require_changed_files'] : array();
		$allow         = $this->normalize_pattern_list( $config['allow'] ?? $required['allow'] ?? array() );
		$deny          = $this->normalize_pattern_list( $config['deny'] ?? $required['deny'] ?? array() );
		$include_any   = $this->normalize_pattern_list( $config['include_any'] ?? $required['include_any'] ?? array() );
		$include_all   = $this->normalize_pattern_list( $config['include_all'] ?? $required['include_all'] ?? array() );
		$require_tests = ! empty( $config['require_tests'] );
		$paths         = $summary['changed_files'] ?? array();

		$denied              = $this->paths_matching_any_pattern( $paths, $deny );
		$outside_allow       = empty( $allow ) ? array() : array_values( array_filter( $paths, fn( string $changed_path ): bool => ! $this->path_matches_any_pattern( $changed_path, $allow ) ) );
		$include_any_matches = $this->paths_matching_any_pattern( $paths, $include_any );
		$missing_include_all = array_values( array_filter( $include_all, fn( string $pattern ): bool => empty( $this->paths_matching_any_pattern( $paths, array( $pattern ) ) ) ) );

		$checks = array(
			array(
				'name'    => 'changed_files_present',
				'pass'    => ! empty( $paths ),
				'details' => array( 'count' => count( $paths ) ),
			),
			array(
				'name'    => 'denied_paths_absent',
				'pass'    => empty( $denied ),
				'details' => array(
					'patterns' => $deny,
					'matches'  => $denied,
				),
			),
			array(
				'name'    => 'changed_paths_allowed',
				'pass'    => empty( $outside_allow ),
				'details' => array(
					'patterns' => $allow,
					'outside'  => $outside_allow,
				),
			),
			array(
				'name'    => 'include_any_matched',
				'pass'    => empty( $include_any ) || ! empty( $include_any_matches ),
				'details' => array(
					'patterns' => $include_any,
					'matches'  => $include_any_matches,
				),
			),
			array(
				'name'    => 'include_all_matched',
				'pass'    => empty( $missing_include_all ),
				'details' => array(
					'patterns' => $include_all,
					'missing'  => $missing_include_all,
				),
			),
			array(
				'name'    => 'tests_touched',
				'pass'    => ! $require_tests || ! empty( $summary['totals']['tests_touched'] ),
				'details' => array(
					'required'      => $require_tests,
					'tests_touched' => (bool) ( $summary['totals']['tests_touched'] ?? false ),
				),
			),
		);

		return array(
			'success' => true,
			'valid'   => ! in_array( false, array_column( $checks, 'pass' ), true ),
			'name'    => $summary['name'],
			'repo'    => $summary['repo'],
			'checks'  => $checks,
			'summary' => $summary,
		);
	}

	/** @return string[]|\WP_Error */
	private function build_path_args( ?string $path ): array|\WP_Error {
		if ( null === $path || '' === trim( $path ) ) {
			return array();
		}

		$relative = trim( $path );
		if ( PathSecurity::hasTraversal( $relative ) || str_starts_with( $relative, '/' ) ) {
			return new \WP_Error( 'invalid_path', sprintf( 'Invalid diff path: %s', $relative ), array( 'status' => 400 ) );
		}

		return array( '--', escapeshellarg( $relative ) );
	}

	/** @return string[] */
	private function build_diff_args( ?string $from, ?string $to, bool $staged ): array {
		$args = array( 'diff' );
		if ( $staged ) {
			$args[] = '--cached';
		}
		if ( ! empty( $from ) ) {
			$args[] = escapeshellarg( $from );
		}
		if ( ! empty( $to ) ) {
			$args[] = escapeshellarg( $to );
		}
		if ( empty( $from ) && empty( $to ) && ! $staged ) {
			$args[] = 'HEAD';
		}

		return $args;
	}

	/** @return string|\WP_Error */
	private function resolve_repo_path( string $handle ): string|\WP_Error {
		$parsed    = $this->workspace->parse_handle( $handle );
		$repo_path = $this->workspace->get_repo_path( $handle );

		if ( ! is_dir( $repo_path ) ) {
			return new \WP_Error( 'repo_not_found', sprintf( 'Workspace handle "%s" not found.', $parsed['dir_name'] ), array( 'status' => 404 ) );
		}

		$git_path = $repo_path . '/.git';
		if ( ! is_dir( $git_path ) && ! is_file( $git_path ) ) {
			return new \WP_Error( 'not_git_repo', sprintf( 'Handle "%s" is not a git repository or worktree.', $parsed['dir_name'] ), array( 'status' => 400 ) );
		}

		$validation = PathSecurity::validateContainment( $repo_path, $this->workspace->get_path() );
		if ( ! $validation['valid'] ) {
			return new \WP_Error( 'path_traversal', $validation['message'], array( 'status' => 403 ) );
		}

		return $validation['real_path'];
	}

	/** @return array<int,array<string,mixed>> */
	private function parse_diff_files( string $numstat_output, string $name_status_output ): array {
		$files = array();
		foreach ( array_filter( explode( "\n", $name_status_output ) ) as $line ) {
			$parts = explode( "\t", trim( $line ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$status = array_shift( $parts );
			$path   = (string) end( $parts );

			$files[ $path ] = array(
				'path'      => $path,
				'old_path'  => count( $parts ) > 1 ? (string) $parts[0] : null,
				'status'    => $status,
				'additions' => null,
				'deletions' => null,
				'is_test'   => $this->is_test_path( $path ),
			);
		}

		foreach ( array_filter( explode( "\n", $numstat_output ) ) as $line ) {
			$parts = explode( "\t", trim( $line ) );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			$path = (string) end( $parts );
			if ( ! isset( $files[ $path ] ) ) {
				$files[ $path ] = array(
					'path'      => $path,
					'old_path'  => null,
					'status'    => 'M',
					'additions' => null,
					'deletions' => null,
					'is_test'   => $this->is_test_path( $path ),
				);
			}
			$files[ $path ]['additions'] = is_numeric( $parts[0] ) ? (int) $parts[0] : null;
			$files[ $path ]['deletions'] = is_numeric( $parts[1] ) ? (int) $parts[1] : null;
		}

		ksort( $files );
		return array_values( $files );
	}

	/** @param array<int,array<string,mixed>> $files Parsed diff files. @return array<int,array<string,mixed>> */
	private function merge_untracked_diff_files( array $files, string $porcelain_output ): array {
		$by_path = array();
		foreach ( $files as $file ) {
			$by_path[ (string) $file['path'] ] = $file;
		}

		foreach ( array_filter( explode( "\n", $porcelain_output ) ) as $line ) {
			if ( ! str_starts_with( $line, '?? ' ) ) {
				continue;
			}
			$path = trim( substr( $line, 3 ) );
			if ( '' === $path || isset( $by_path[ $path ] ) ) {
				continue;
			}
			$by_path[ $path ] = array(
				'path'      => $path,
				'old_path'  => null,
				'status'    => '??',
				'additions' => null,
				'deletions' => null,
				'is_test'   => $this->is_test_path( $path ),
			);
		}

		ksort( $by_path );
		return array_values( $by_path );
	}

	private function is_test_path( string $path ): bool {
		$normalized = strtolower( str_replace( '\\', '/', $path ) );
		return str_starts_with( $normalized, 'test/' )
			|| str_starts_with( $normalized, 'tests/' )
			|| str_contains( $normalized, '/test/' )
			|| str_contains( $normalized, '/tests/' )
			|| (bool) preg_match( '/(^|[._-])(test|spec)\.[a-z0-9]+$/', basename( $normalized ) );
	}

	/** @return string[] */
	private function normalize_pattern_list( mixed $patterns ): array {
		if ( is_string( $patterns ) ) {
			$patterns = array( $patterns );
		}
		if ( ! is_array( $patterns ) ) {
			return array();
		}

		return array_values( array_filter( array_map( static fn( mixed $pattern ): string => trim( (string) $pattern ), $patterns ), static fn( string $pattern ): bool => '' !== $pattern ) );
	}

	/** @param string[] $paths Changed paths. @param string[] $patterns Path patterns. @return string[] */
	private function paths_matching_any_pattern( array $paths, array $patterns ): array {
		return array_values( array_filter( $paths, fn( string $path ): bool => $this->path_matches_any_pattern( $path, $patterns ) ) );
	}

	/** @param string[] $patterns Path patterns. */
	private function path_matches_any_pattern( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$normalized = ltrim( str_replace( '\\', '/', $pattern ), '/' );
			if ( str_ends_with( $normalized, '/' ) && str_starts_with( $path, $normalized ) ) {
				return true;
			}
			if ( fnmatch( $normalized, $path, FNM_PATHNAME ) || fnmatch( $normalized, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
