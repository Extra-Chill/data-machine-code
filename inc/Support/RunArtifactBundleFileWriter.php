<?php
/**
 * Prepare Data Machine run artifacts for repository bundle-file writes.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

/**
 * Converts artifact payloads plus egress policy into GitHub file-write inputs.
 */
class RunArtifactBundleFileWriter {



	/**
	 * Build file write records for artifacts whose policy enables bundle-file egress.
	 *
	 * @param  array<string,mixed> $artifact_payload Data Machine job artifact payload.
	 * @param  array<string,mixed> $egress_policy    Normalized run_artifact_egress_policy.
	 * @param  string              $bundle_root      Repo-relative bundle root. Supports placeholders.
	 * @return array<int,array{file_path:string,content:string,commit_message:string,artifact_type:string}>
	 */
	public static function fileWritesFromArtifacts( array $artifact_payload, array $egress_policy, string $bundle_root = '' ): array {
		$records = self::artifactRecords($artifact_payload);
		if ( empty($records) ) {
			return array();
		}

		$writes = array();
		foreach ( $records as $record ) {
			$source = self::artifactSource($record);
			if ( '' === $source || ! self::policyAllowsBundleFile($source, $egress_policy, $record) ) {
				continue;
			}

			$content = isset($record['content']) ? (string) $record['content'] : '';
			if ( '' === $content ) {
				continue;
			}

			$file_path = self::artifactRepoPath($record, $bundle_root);
			if ( '' === $file_path ) {
				continue;
			}

			$writes[] = array(
				'file_path'      => $file_path,
				'content'        => $content,
				'commit_message' => self::commitMessageForArtifact($record),
				'artifact_type'  => (string) ( $record['type'] ?? $source ),
			);
		}

		return self::dedupeWritesByPath($writes);
	}

	/**
	 * @param  array<string,mixed> $artifact_payload Data Machine artifact payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function artifactRecords( array $artifact_payload ): array {
		$candidates = array();
		foreach ( array( 'daily_memory_artifacts', 'artifacts', 'run_artifacts' ) as $key ) {
			if ( isset($artifact_payload[ $key ]) && is_array($artifact_payload[ $key ]) ) {
				$candidates[] = $artifact_payload[ $key ];
			}
		}

		if ( isset($artifact_payload['type']) ) {
			$candidates[] = array( $artifact_payload );
		}

		$records = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate as $record ) {
				if ( is_array($record) ) {
					$records[] = $record;
				}
			}
		}

		return $records;
	}

	/**
	 * Resolve an artifact record into the policy source key.
	 *
	 * @param array<string,mixed> $record Artifact record.
	 */
	private static function artifactSource( array $record ): string {
		$type   = sanitize_key( (string) ( $record['type'] ?? $record['source_type'] ?? '' ));
		$source = sanitize_key( (string) ( $record['artifact_source'] ?? '' ));

		if ( '' !== $source ) {
			return $source;
		}
		if ( in_array($type, array( 'agent_daily_memory', 'daily_memory' ), true) ) {
			return 'daily_memory';
		}
		if ( in_array($type, array( 'completion_assertions', 'completion_evidence' ), true) ) {
			return 'completion_assertions';
		}
		if ( in_array($type, array( 'transcript_summary', 'transcript' ), true) ) {
			return 'transcript_summary';
		}

		return $type;
	}

	/**
	 * @param array<string,mixed> $egress_policy Normalized egress policy.
	 * @param array<string,mixed> $record        Artifact record.
	 */
	private static function policyAllowsBundleFile( string $source, array $egress_policy, array $record ): bool {
		$source_policy = is_array($egress_policy[ $source ] ?? null) ? $egress_policy[ $source ] : array();
		$egress        = is_array($source_policy['egress'] ?? null) ? $source_policy['egress'] : array();

		if ( in_array('bundle-file', $egress, true) ) {
			return true;
		}

		$record_egress = is_array($record['egress'] ?? null) ? $record['egress'] : array();
		return in_array('bundle-file', $record_egress, true);
	}

	/**
	 * @param array<string,mixed> $record Artifact record.
	 */
	private static function artifactRepoPath( array $record, string $bundle_root ): string {
		$repo_path = self::normalizeRelativePath( (string) ( $record['repo_path'] ?? $record['file_path'] ?? '' ));
		if ( '' !== $repo_path ) {
			return $repo_path;
		}

		$bundle_relative_path = self::normalizeRelativePath( (string) ( $record['bundle_relative_path'] ?? '' ));
		if ( '' === $bundle_relative_path ) {
			return '';
		}

		$root = self::normalizeRelativePath(self::expandPlaceholders('' !== $bundle_root ? $bundle_root : 'bundles/{agent_slug}', $record));
		if ( '' === $root ) {
			return '';
		}

		return self::normalizeRelativePath($root . '/' . $bundle_relative_path);
	}

	/**
	 * @param array<string,mixed> $record Artifact record.
	 */
	private static function commitMessageForArtifact( array $record ): string {
		$type = sanitize_key( (string) ( $record['type'] ?? '' ));
		if ( in_array($type, array( 'agent_daily_memory', 'daily_memory' ), true) ) {
			return 'chore: persist agent daily memory';
		}

		return 'chore: persist Data Machine run artifact';
	}

	/**
	 * @param array<string,mixed> $record Artifact record.
	 */
	private static function expandPlaceholders( string $path, array $record ): string {
		$date  = (string) ( $record['date'] ?? '' );
		$parts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) ? $matches : array( '', '', '', '' );

		$replacements = array(
			'{agent_slug}'  => sanitize_title( (string) ( $record['agent_slug'] ?? '' )),
			'{bundle_slug}' => sanitize_title( (string) ( $record['bundle_slug'] ?? $record['agent_slug'] ?? '' )),
			'{yyyy}'        => $parts[1],
			'{mm}'          => $parts[2],
			'{dd}'          => $parts[3],
		);

		return strtr($path, $replacements);
	}

	private static function normalizeRelativePath( string $path ): string {
		$path = str_replace('\\', '/', trim($path));
		$path = preg_replace('#/+#', '/', $path);
		$path = ltrim(is_string($path) ? $path : '', '/');

		if ( '' === $path || str_contains($path, '../') || str_starts_with($path, '..') ) {
			return '';
		}

		return $path;
	}

	/**
	 * @param  array<int,array{file_path:string,content:string,commit_message:string,artifact_type:string}> $writes File writes.
	 * @return array<int,array{file_path:string,content:string,commit_message:string,artifact_type:string}>
	 */
	private static function dedupeWritesByPath( array $writes ): array {
		$by_path = array();
		foreach ( $writes as $write ) {
			$by_path[ $write['file_path'] ] = $write;
		}

		ksort($by_path, SORT_STRING);
		return array_values($by_path);
	}
}
