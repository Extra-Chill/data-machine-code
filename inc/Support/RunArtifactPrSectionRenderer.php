<?php
/**
 * Render Data Machine run artifacts into stable PR markdown sections.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Converts artifact-like arrays into idempotent PR body/comment sections.
 */
class RunArtifactPrSectionRenderer {

	public const START_MARKER      = '<!-- datamachine-run-artifacts:start -->';
	public const END_MARKER        = '<!-- datamachine-run-artifacts:end -->';
	public const MODE_BODY_SECTION = 'body-section';
	public const MODE_COMMENT      = 'comment';

	/**
	 * Render artifacts as a full managed markdown section.
	 *
	 * @param array<int|string, mixed> $artifacts Artifact-like records from Data Machine.
	 * @return string Markdown section wrapped in stable markers.
	 */
	public static function render( array $artifacts ): string {
		$sanitized = self::sanitizeValue( $artifacts );
		if ( ! is_array( $sanitized ) ) {
			$sanitized = array();
		}

		$journals   = self::collectJournalEntries( $sanitized );
		$evidence   = self::collectCompletionEvidence( $sanitized );
		$transcript = self::collectTranscriptSummaries( $sanitized );
		if ( empty( $journals ) && empty( $evidence ) && empty( $transcript ) ) {
			return '';
		}

		$lines = array(
			self::START_MARKER,
			'## Agent Run Artifacts',
		);

		if ( ! empty( $journals ) ) {
			$lines[] = '';
			$lines[] = '### Agent Journal';
			foreach ( $journals as $index => $entry ) {
				if ( $index > 0 ) {
					$lines[] = '';
					$lines[] = '---';
					$lines[] = '';
				}
				$lines[] = $entry;
			}
		}

		if ( ! empty( $evidence ) ) {
			$lines[] = '';
			$lines[] = '### Completion Evidence';
			foreach ( $evidence as $label => $status ) {
				$lines[] = sprintf( '- `%s`: %s', self::markdownInlineCode( (string) $label ), self::plainText( (string) $status ) );
			}
		}

		if ( ! empty( $transcript ) ) {
			$lines[] = '';
			$lines[] = '### Transcript Summary';
			foreach ( $transcript as $index => $summary ) {
				if ( $index > 0 ) {
					$lines[] = '';
					$lines[] = '---';
					$lines[] = '';
				}
				$lines[] = $summary;
			}
		}

		$lines[] = self::END_MARKER;

		return implode( "\n", $lines );
	}

	/**
	 * Render artifacts as the managed body for a PR comment.
	 *
	 * Comment upsert is owned by GitHubAbilities; this method only prepares the
	 * stable artifact markdown that can be passed to that disabled-by-default seam.
	 *
	 * @param array<int|string, mixed> $artifacts Artifact-like records from Data Machine.
	 * @return string Markdown comment body wrapped in stable markers.
	 */
	public static function renderCommentBody( array $artifacts ): string {
		return self::render( $artifacts );
	}

	/**
	 * Render artifacts into the requested attachment mode without performing I/O.
	 *
	 * @param string                   $mode      Attachment mode: body-section or comment.
	 * @param array<int|string, mixed> $artifacts Artifact-like records from Data Machine.
	 * @param string                   $existing  Existing PR body text for body-section mode.
	 * @return string Rendered PR body or comment body.
	 */
	public static function renderForMode( string $mode, array $artifacts, string $existing = '' ): string {
		if ( self::MODE_COMMENT === $mode ) {
			return self::renderCommentBody( $artifacts );
		}

		return self::replaceSection( $existing, $artifacts );
	}

	/**
	 * Replace or append the managed artifact section in existing markdown.
	 *
	 * @param string                   $body      Existing PR body/comment text.
	 * @param array<int|string, mixed> $artifacts Artifact-like records from Data Machine.
	 * @return string Updated markdown.
	 */
	public static function replaceSection( string $body, array $artifacts ): string {
		$section = self::render( $artifacts );
		$pattern = '/' . preg_quote( self::START_MARKER, '/' ) . '.*?' . preg_quote( self::END_MARKER, '/' ) . '/s';
		if ( '' === $section ) {
			$body = preg_replace( $pattern, '', $body, 1 ) ?? $body;
			return rtrim( $body );
		}

		if ( preg_match( $pattern, $body ) ) {
			return preg_replace( $pattern, $section, $body, 1 ) ?? $body;
		}

		$body = rtrim( $body );
		if ( '' === $body ) {
			return $section;
		}

		return $body . "\n\n" . $section;
	}

	/**
	 * @param array<int|string, mixed> $artifacts Sanitized artifacts.
	 * @return array<int, string>
	 */
	private static function collectJournalEntries( array $artifacts ): array {
		$entries = array();
		self::walkArtifactRecords(
			$artifacts,
			static function ( array $record ) use ( &$entries ): void {
				$type = self::artifactType( $record );
				if ( ! self::isJournalType( $type ) ) {
					return;
				}

				$content = self::firstString( $record, array( 'content', 'markdown', 'body', 'text', 'entry', 'daily_memory', 'journal' ) );
				if ( '' === $content && isset( $record['data'] ) && is_array( $record['data'] ) ) {
					$content = self::firstString( $record['data'], array( 'content', 'markdown', 'body', 'text', 'entry', 'daily_memory', 'journal' ) );
				}

				if ( '' !== $content ) {
					$entries[] = self::markdownBlockText( $content );
				}
			}
		);

		return $entries;
	}

	/**
	 * @param array<int|string, mixed> $artifacts Sanitized artifacts.
	 * @return array<string, string>
	 */
	private static function collectCompletionEvidence( array $artifacts ): array {
		$evidence  = array();
		$required  = isset( $artifacts['required_tool_names'] ) && is_array( $artifacts['required_tool_names'] ) ? $artifacts['required_tool_names'] : array();
		$satisfied = isset( $artifacts['satisfied_tool_names'] ) && is_array( $artifacts['satisfied_tool_names'] ) ? $artifacts['satisfied_tool_names'] : array();
		foreach ( $required as $tool_name ) {
			$tool_name = trim( (string) $tool_name );
			if ( '' !== $tool_name ) {
				$evidence[ $tool_name ] = in_array( $tool_name, $satisfied, true ) ? 'satisfied' : 'not satisfied';
			}
		}

		self::walkArtifactRecords(
			$artifacts,
			static function ( array $record ) use ( &$evidence ): void {
				$type = self::artifactType( $record );
				if ( ! self::isCompletionEvidenceType( $type ) ) {
					return;
				}

				$items = self::completionItemsFromRecord( $record );
				foreach ( $items as $label => $status ) {
					if ( '' !== $label && '' !== $status ) {
						$evidence[ $label ] = $status;
					}
				}
			}
		);

		return $evidence;
	}

	/**
	 * @param array<int|string, mixed> $record Artifact record.
	 * @return array<string, string>
	 */
	private static function completionItemsFromRecord( array $record ): array {
		$candidates = array();
		foreach ( array( 'evidence', 'completion_evidence', 'completion_assertions', 'assertions', 'tools' ) as $key ) {
			if ( isset( $record[ $key ] ) && is_array( $record[ $key ] ) ) {
				$candidates[] = $record[ $key ];
			}
			if ( isset( $record['data'][ $key ] ) && is_array( $record['data'][ $key ] ) ) {
				$candidates[] = $record['data'][ $key ];
			}
		}

		if ( empty( $candidates ) ) {
			$candidates[] = $record;
		}

		$items = array();
		foreach ( $candidates as $candidate ) {
			if ( self::isList( $candidate ) ) {
				foreach ( $candidate as $item ) {
					if ( is_array( $item ) ) {
						$label  = self::firstString( $item, array( 'tool', 'tool_name', 'ability', 'name', 'label', 'assertion' ) );
						$status = self::firstString( $item, array( 'status', 'state', 'result', 'value' ) );
						if ( '' === $status && isset( $item['satisfied'] ) ) {
							$status = $item['satisfied'] ? 'satisfied' : 'not satisfied';
						}
						$items[ $label ] = $status;
					} elseif ( is_string( $item ) && '' !== trim( $item ) ) {
						$items[ trim( $item ) ] = 'satisfied';
					}
				}
				continue;
			}

			foreach ( $candidate as $key => $value ) {
				if ( is_array( $value ) ) {
					$label = self::firstString( $value, array( 'tool', 'tool_name', 'ability', 'name', 'label', 'assertion' ) );
					if ( '' === $label ) {
						$label = is_string( $key ) ? $key : '';
					}
					$status = self::firstString( $value, array( 'status', 'state', 'result', 'value' ) );
					if ( '' === $status && isset( $value['satisfied'] ) ) {
						$status = $value['satisfied'] ? 'satisfied' : 'not satisfied';
					}
					$items[ $label ] = $status;
				} elseif ( is_string( $key ) ) {
					$items[ $key ] = is_bool( $value ) ? ( $value ? 'satisfied' : 'not satisfied' ) : (string) $value;
				}
			}
		}

		return $items;
	}

	/**
	 * @param array<int|string, mixed> $artifacts Sanitized artifacts.
	 * @return array<int, string>
	 */
	private static function collectTranscriptSummaries( array $artifacts ): array {
		$summaries = array();
		if ( isset( $artifacts['transcript'] ) && is_array( $artifacts['transcript'] ) ) {
			$summary = self::transcriptMetadataSummary( $artifacts['transcript'] );
			if ( '' !== $summary ) {
				$summaries[] = $summary;
			}
		}

		self::walkArtifactRecords(
			$artifacts,
			static function ( array $record ) use ( &$summaries ): void {
				$type = self::artifactType( $record );
				if ( ! in_array( $type, array( 'transcript_summary', 'agent_transcript_summary' ), true ) ) {
					return;
				}

				$summary = self::firstString( $record, array( 'summary', 'content', 'markdown', 'body', 'text' ) );
				if ( '' === $summary && isset( $record['data'] ) && is_array( $record['data'] ) ) {
					$summary = self::firstString( $record['data'], array( 'summary', 'content', 'markdown', 'body', 'text' ) );
				}

				if ( '' !== $summary ) {
					$summaries[] = self::markdownBlockText( $summary );
				}
			}
		);

		return $summaries;
	}

	/**
	 * @param array<int|string, mixed> $value Artifact array.
	 * @param callable                 $callback Record callback.
	 */
	private static function walkArtifactRecords( array $value, callable $callback ): void {
		if ( isset( $value['type'] ) || isset( $value['name'] ) || isset( $value['artifact_type'] ) ) {
			$callback( $value );
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				self::walkArtifactRecords( $item, $callback );
			}
		}
	}

	/**
	 * @param array<int|string, mixed> $record Artifact record.
	 */
	private static function artifactType( array $record ): string {
		$type = self::firstString( $record, array( 'type', 'name', 'artifact_type', 'kind' ) );
		return strtolower( str_replace( '-', '_', $type ) );
	}

	private static function isJournalType( string $type ): bool {
		return in_array( $type, array( 'agent_daily_memory', 'daily_memory', 'agent_journal', 'journal' ), true );
	}

	private static function isCompletionEvidenceType( string $type ): bool {
		return in_array( $type, array( 'completion_evidence', 'completion_assertions', 'agent_completion_evidence', 'required_tools' ), true );
	}

	/**
	 * @param array<int|string, mixed> $value Input value.
	 * @return mixed Sanitized value.
	 */
	private static function sanitizeValue( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && self::isSecretLikeKey( $key ) ) {
					continue;
				}
				$sanitized[ $key ] = self::sanitizeValue( $item );
			}
			return $sanitized;
		}

		if ( is_string( $value ) ) {
			return self::redactSecretLikeString( $value );
		}

		return $value;
	}

	private static function isSecretLikeKey( string $key ): bool {
		return 1 === preg_match( '/(authorization|credential|secret|token|password|passwd|private[_-]?key|api[_-]?key|github[_-]?pat|bearer)/i', $key );
	}

	private static function redactSecretLikeString( string $value ): string {
		$value = preg_replace( '/\b(gh[pousr]_[A-Za-z0-9_]{12,})\b/', '[redacted]', $value ) ?? $value;
		$value = preg_replace( '/\b(sk-[A-Za-z0-9_-]{12,})\b/', '[redacted]', $value ) ?? $value;
		$value = preg_replace( '/\b(xox[baprs]-[A-Za-z0-9-]{12,})\b/', '[redacted]', $value ) ?? $value;

		return preg_replace( '/\b(authorization|token|password|secret|api[_-]?key)\s*[:=]\s*\S+/i', '$1: [redacted]', $value ) ?? $value;
	}

	/**
	 * @param array<int|string, mixed> $value Source array.
	 * @param array<int, string>       $keys  Candidate keys.
	 */
	private static function firstString( array $value, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				$string = trim( (string) $value[ $key ] );
				if ( '' !== $string ) {
					return $string;
				}
			}
		}

		return '';
	}

	private static function markdownBlockText( string $value ): string {
		return trim( str_replace( "\r\n", "\n", $value ) );
	}

	private static function markdownInlineCode( string $value ): string {
		return str_replace( '`', '\\`', $value );
	}

	private static function plainText( string $value ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );
		return str_replace( array( "\r", "\n" ), ' ', $value );
	}

	/**
	 * @param array<string, mixed> $transcript Data Machine transcript metadata.
	 */
	private static function transcriptMetadataSummary( array $transcript ): string {
		if ( ! empty( $transcript['missing'] ) ) {
			$session_id = self::firstString( $transcript, array( 'session_id' ) );
			return '' === $session_id ? 'Transcript session was not found.' : sprintf( 'Transcript session `%s` was not found.', self::markdownInlineCode( $session_id ) );
		}

		$parts = array();
		foreach ( array( 'session_id', 'provider', 'model', 'mode' ) as $key ) {
			$value = self::firstString( $transcript, array( $key ) );
			if ( '' !== $value ) {
				$parts[] = sprintf( '%s: `%s`', str_replace( '_', ' ', $key ), self::markdownInlineCode( $value ) );
			}
		}

		foreach ( array( 'message_count', 'turn_count' ) as $key ) {
			if ( isset( $transcript[ $key ] ) && is_numeric( $transcript[ $key ] ) ) {
				$parts[] = sprintf( '%s: %d', str_replace( '_', ' ', $key ), (int) $transcript[ $key ] );
			}
		}

		if ( isset( $transcript['completed'] ) ) {
			$parts[] = 'completed: ' . ( $transcript['completed'] ? 'yes' : 'no' );
		}

		return implode( "\n", array_map( static fn( string $part ): string => '- ' . $part, $parts ) );
	}

	/**
	 * @param array<int|string, mixed> $value Array to inspect.
	 */
	private static function isList( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
