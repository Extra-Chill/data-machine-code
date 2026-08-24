<?php
/**
 * Bounded discovery of registered source checkouts by repository identity.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorkspaceSourceResolver {

	private const DEFAULT_MAX_ENTRIES = 1000;
	private const MAX_ENTRIES         = 5000;
	private const DEFAULT_BUDGET      = 1.0;
	private const MAX_BUDGET          = 5.0;
	private const MAX_GIT_CONFIG_SIZE = 65536;

	/**
	 * Find registered primary checkouts for one repository without Git or network probes.
	 *
	 * @return array<string,mixed>
	 */
	public static function discover(
		string $workspace_path,
		string $repository,
		string $entrypoint,
		int $max_entries = self::DEFAULT_MAX_ENTRIES,
		float $budget_seconds = self::DEFAULT_BUDGET
	): array {
		$workspace_path = rtrim(trim($workspace_path), '/');
		$repository_key = self::normalize_remote($repository);
		$max_entries    = max(1, min(self::MAX_ENTRIES, $max_entries));
		$budget_seconds = max(0.05, min(self::MAX_BUDGET, $budget_seconds));
		$base           = array(
			'authority'       => 'registered_workspace',
			'workspace_path'  => $workspace_path,
			'repository'      => $repository_key,
			'bounded'         => true,
			'entry_limit'     => $max_entries,
			'budget_seconds'  => $budget_seconds,
			'scanned_entries' => 0,
			'complete'        => false,
			'candidates'      => array(),
		);

		if ( '' === $workspace_path || ! is_dir($workspace_path) || ! is_readable($workspace_path) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Read-only local workspace metadata.
			return array_merge(
				$base,
				array(
					'state'  => 'unavailable',
					'reason' => 'workspace_unavailable',
				)
			);
		}
		if ( '' === $repository_key ) {
			return array_merge(
				$base,
				array(
					'state'  => 'unavailable',
					'reason' => 'source_repository_invalid',
				)
			);
		}

		$candidates = array();
		$seen       = array();
		$started_at = microtime(true);
		$scanned    = 0;
		$complete   = true;
		$reason     = null;

		try {
			foreach ( new \DirectoryIterator($workspace_path) as $entry ) {
				if ( $entry->isDot() ) {
					continue;
				}
				if ( $scanned >= $max_entries ) {
					$complete = false;
					$reason   = 'entry_limit_reached';
					break;
				}
				if ( microtime(true) - $started_at >= $budget_seconds ) {
					$complete = false;
					$reason   = 'scan_budget_exhausted';
					break;
				}

				++$scanned;
				if ( str_starts_with($entry->getFilename(), '.') || str_contains($entry->getFilename(), '@') || ! $entry->isDir() ) {
					continue;
				}
				$path = $entry->getPathname();
				if ( ! is_dir($path . '/.git') || ! is_readable($path . '/' . $entrypoint) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Read-only source entrypoint metadata.
					continue;
				}
				$remote = self::origin_remote($path . '/.git/config');
				if ( null === $remote || self::normalize_remote($remote) !== $repository_key ) {
					continue;
				}

				$real_path = realpath($path);
				$real_path = false === $real_path ? $path : $real_path;
				if ( isset($seen[ $real_path ]) ) {
					continue;
				}
				$seen[ $real_path ] = true;
				$candidates[]       = array(
					'handle'     => $entry->getFilename(),
					'path'       => $path,
					'real_path'  => $real_path,
					'repository' => $repository_key,
				);
			}
		} catch ( \UnexpectedValueException ) {
			return array_merge(
				$base,
				array(
					'state'  => 'unavailable',
					'reason' => 'workspace_unreadable',
				)
			);
		}

		usort($candidates, static fn( array $left, array $right ): int => strcmp( (string) $left['path'], (string) $right['path'] ));
		$result = array_merge(
			$base,
			array(
				'scanned_entries' => $scanned,
				'complete'        => $complete,
				'candidates'      => $candidates,
			)
		);
		if ( count($candidates) > 1 ) {
			$ambiguity = array(
				'state'  => 'ambiguous',
				'reason' => 'multiple_registered_sources',
			);
			if ( ! $complete ) {
				$ambiguity['incomplete_reason'] = $reason;
			}
			return array_merge($result, $ambiguity);
		}
		if ( ! $complete ) {
			return array_merge(
				$result,
				array(
					'state'  => 'incomplete',
					'reason' => $reason,
				)
			);
		}
		if ( 1 === count($candidates) ) {
			return array_merge(
				$result,
				array(
					'state'       => 'resolved',
					'source_path' => $candidates[0]['path'],
				)
			);
		}

		return array_merge(
			$result,
			array(
				'state'  => 'not_found',
				'reason' => 'registered_source_not_found',
			)
		);
	}

	private static function origin_remote( string $config_path ): ?string {
		if ( ! is_file($config_path) || ! is_readable($config_path) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- Bounded local Git metadata.
			return null;
		}
		$size = filesize($config_path);
		if ( false === $size || $size > self::MAX_GIT_CONFIG_SIZE ) {
			return null;
		}
		$body = file_get_contents($config_path, false, null, 0, self::MAX_GIT_CONFIG_SIZE); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local Git metadata.
		if ( ! is_string($body) || ! preg_match('/^\s*\[remote\s+"origin"\]\s*$([\s\S]*?)(?=^\s*\[|\z)/mi', $body, $section) ) {
			return null;
		}
		if ( ! preg_match('/^\s*url\s*=\s*(.+?)\s*$/mi', $section[1], $url) ) {
			return null;
		}

		return trim($url[1], " \t\n\r\0\x0B\"");
	}

	private static function normalize_remote( string $remote ): string {
		$remote = rtrim(trim($remote), '/');
		$remote = preg_replace('/\.git$/i', '', $remote) ?? $remote;
		if ( preg_match('/^[^@\s]+@([^:\s]+):(.+)$/', $remote, $matches) ) {
			return strtolower($matches[1] . '/' . trim($matches[2], '/'));
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure-PHP diagnostic fixture compatibility.
		$parts = function_exists('wp_parse_url') ? wp_parse_url($remote) : parse_url($remote);
		if ( is_array($parts) && ! empty($parts['host']) ) {
			return strtolower( (string) $parts['host'] . '/' . trim( (string) ( $parts['path'] ?? '' ), '/' ) );
		}

		return strtolower($remote);
	}
}
