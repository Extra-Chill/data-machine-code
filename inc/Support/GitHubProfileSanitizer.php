<?php
/**
 * GitHub credential profile sanitizer.
 *
 * Single source of truth for the shape coercion applied when DMC writes the
 * credential profiles array through the `datamachine/update-settings` ability.
 *
 * Lives in its own file so the smoke tests can exercise the same contract
 * without booting the full plugin bootstrap.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class GitHubProfileSanitizer {



	/**
	 * Sanitize a raw credential profiles array.
	 *
	 * - Drops entries without a non-empty `id` (after sanitize_key).
	 * - Normalizes `mode` to `pat` or `app`, defaulting to `pat`.
	 * - Trims `allowed_repos` and drops empties.
	 * - Preserves PEM newlines on `app_private_key` (sanitize_text_field
	 *   would strip them).
	 *
	 * @param  array<int|string,mixed> $profiles
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize( array $profiles ): array {
		$sanitized = array();
		foreach ( $profiles as $entry ) {
			if ( ! is_array($entry) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $entry['id'] ?? '' ));
			if ( '' === $id ) {
				continue;
			}

			$allowed = array();
			if ( isset($entry['allowed_repos']) && is_array($entry['allowed_repos']) ) {
				foreach ( $entry['allowed_repos'] as $repo ) {
					$repo = sanitize_text_field( (string) $repo);
					if ( '' !== $repo ) {
						$allowed[] = $repo;
					}
				}
			}

			$mode = strtolower(sanitize_text_field( (string) ( $entry['mode'] ?? 'pat' )));
			if ( ! in_array($mode, array( 'pat', 'app' ), true) ) {
				$mode = 'pat';
			}

			$sanitized[] = array(
				'id'                  => $id,
				'label'               => sanitize_text_field( (string) ( $entry['label'] ?? $id )),
				'mode'                => $mode,
				'pat'                 => sanitize_text_field( (string) ( $entry['pat'] ?? '' )),
				'app_id'              => sanitize_text_field( (string) ( $entry['app_id'] ?? '' )),
				'app_installation_id' => sanitize_text_field( (string) ( $entry['app_installation_id'] ?? '' )),
				'app_private_key'     => trim( (string) ( $entry['app_private_key'] ?? '' )),
				'default_repo'        => sanitize_text_field( (string) ( $entry['default_repo'] ?? '' )),
				'allowed_repos'       => $allowed,
			);
		}
		return $sanitized;
	}
}
