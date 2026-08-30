<?php
/**
 * Filesystem-backed explicit-refresh freshness evidence for primary checkouts.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreeFreshnessEvidence {

	public const SCHEMA = 'datamachine-code/primary-freshness/v1';
	public const FILE   = 'datamachine-code-freshness.json';

	/** @param array<string,mixed> $evidence */
	public static function store( string $repo_path, array $evidence ): bool {
		$path = self::path($repo_path);
		if ( null === $path ) {
			return false;
		}
		$payload = array(
			'schema'             => self::SCHEMA,
			'version'            => (int) ( $evidence['version'] ?? 2 ),
			'remote_refs_digest' => (string) ( $evidence['remote_refs_digest'] ?? '' ),
			'ref_heads'          => is_array($evidence['ref_heads'] ?? null) ? $evidence['ref_heads'] : array(),
			'observed_at'        => (string) ( $evidence['observed_at'] ?? '' ),
		);
		$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($encoded) && false !== @file_put_contents($path, $encoded, LOCK_EX);
	}

	/** @return array<string,mixed>|null */
	public static function read( string $repo_path ): ?array {
		$path    = self::path($repo_path);
		$payload = null === $path ? false : @file_get_contents($path);
		$data    = is_string($payload) ? json_decode($payload, true) : null;
		if ( ! is_array($data) || ! in_array((int) ( $data['version'] ?? 0 ), array( 1, 2 ), true) || empty($data['remote_refs_digest']) ) {
			return null;
		}
		return $data;
	}

	/** @return array<string,mixed>|null */
	public static function matching( string $repo_path, string $remote_refs_digest ): ?array {
		$evidence = self::read($repo_path);
		if ( null === $evidence || '' === $remote_refs_digest ) {
			return null;
		}
		return hash_equals((string) $evidence['remote_refs_digest'], $remote_refs_digest) ? $evidence : null;
	}

	public static function path( string $repo_path ): ?string {
		$git_dir = $repo_path . '/.git';
		return is_dir($git_dir) ? $git_dir . '/' . self::FILE : null;
	}
}
