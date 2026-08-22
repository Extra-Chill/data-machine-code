<?php
/**
 * Shared text search and edit-preview operations for workspace backends.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorkspaceText {

	public static function compile_search_pattern( string $pattern ): string|\WP_Error {
		if ( '' === $pattern ) {
			return new \WP_Error('missing_pattern', 'Search pattern is required.', array( 'status' => 400 ));
		}

		$regex = '~' . str_replace('~', '\\~', $pattern) . '~u';
     // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Validate user-supplied regex without surfacing PHP warnings.
		$previous_handler = set_error_handler(fn() => true);
		$is_valid         = false !== preg_match($regex, '');
		restore_error_handler();
		unset($previous_handler);

		return $is_valid ? $regex : new \WP_Error('invalid_pattern', 'Search pattern is not a valid regular expression.', array( 'status' => 400 ));
	}

	public static function path_matches_include( string $path, ?string $include_pattern ): bool {
		return null === $include_pattern || '' === $include_pattern || fnmatch($include_pattern, $path) || fnmatch($include_pattern, basename($path));
	}

	/** @return array<int,array<string,mixed>> */
	public static function grep_content( string $content, string $repo, string $path, string $regex, int $context_lines, int $limit ): array {
		$lines   = explode("\n", $content);
		$matches = array();
		foreach ( $lines as $index => $line ) {
			if ( ! preg_match($regex, $line) ) {
				continue;
			}

			$start = max(0, $index - $context_lines);
			$end   = min(count($lines) - 1, $index + $context_lines);
			$match = array(
				'match_id'  => substr(hash('sha256', $path . ':' . ( $index + 1 ) . ':' . $line), 0, 16),
				'path'      => $path,
				'line'      => $index + 1,
				'text'      => $line,
				'preview'   => self::build_preview($lines, $start, $end),
				'read_args' => array(
					'repo'   => $repo,
					'path'   => $path,
					'offset' => $start + 1,
					'limit'  => $end - $start + 1,
				),
			);

			if ( $context_lines > 0 ) {
				$match['context'] = array();
				for ( $context_index = $start; $context_index <= $end; ++$context_index ) {
					$match['context'][] = array( 'line' => $context_index + 1, 'text' => $lines[ $context_index ] );
				}
			}

			$matches[] = $match;
			if ( count($matches) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	/** @return array<int,array<string,mixed>> */
	public static function build_edit_suggestions( string $content, string $old_string ): array {
		$candidates = array_values(array_filter(array_map('trim', explode("\n", $old_string)), static fn( $line ) => strlen($line) >= 4));
		usort($candidates, static fn( $a, $b ) => strlen($b) <=> strlen($a));

		$needle = $candidates[0] ?? trim($old_string);
		if ( '' === $needle ) {
			return array();
		}

		$needle      = substr($needle, 0, 120);
		$lines       = explode("\n", $content);
		$suggestions = array();
		foreach ( $lines as $index => $line ) {
			if ( false === strpos($line, $needle) ) {
				continue;
			}

			$start         = max(0, $index - 2);
			$end           = min(count($lines) - 1, $index + 2);
			$suggestions[] = array(
				'line'    => $index + 1,
				'text'    => $line,
				'preview' => self::build_preview($lines, $start, $end),
			);
			if ( count($suggestions) >= 3 ) {
				break;
			}
		}

		return $suggestions;
	}

	/** @param array<int,string> $lines */
	private static function build_preview( array $lines, int $start, int $end ): string {
		$preview = array();
		for ( $index = $start; $index <= $end; ++$index ) {
			$preview[] = sprintf('%d: %s', $index + 1, $lines[ $index ]);
		}
		return implode("\n", $preview);
	}
}
