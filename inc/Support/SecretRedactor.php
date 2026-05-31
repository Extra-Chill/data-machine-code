<?php
/**
 * Shared secret redaction helpers.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

class SecretRedactor {

	/**
	 * Redact secret-looking values from arbitrary text.
	 */
	public static function redact( string $value, array $secrets = array() ): string {
		foreach ( self::normalize_secrets($secrets) as $secret ) {
			$value = str_replace($secret, '[redacted]', $value);
		}

		$value = preg_replace('/\b(gh[pousr]_[A-Za-z0-9_]{12,})\b/', '[redacted]', $value) ?? $value;
		$value = preg_replace('/\b(sk-[A-Za-z0-9_-]{12,})\b/', '[redacted]', $value) ?? $value;
		$value = preg_replace('/\b(xox[baprs]-[A-Za-z0-9-]{12,})\b/', '[redacted]', $value) ?? $value;
		$value = preg_replace('/\b(Bearer)\s+[A-Za-z0-9._~+\/-]{8,}/i', '$1 [redacted]', $value) ?? $value;

		return preg_replace('/\b(authorization|token|password|secret|api[_-]?key)\s*[:=]\s*\S+/i', '$1: [redacted]', $value) ?? $value;
	}

	/**
	 * Normalize explicit secret values that must be redacted.
	 *
	 * @param  array<int,string> $secrets Secret values.
	 * @return string[]
	 */
	private static function normalize_secrets( array $secrets ): array {
		$values = array();
		foreach ( $secrets as $value ) {
			if ( strlen(trim($value)) >= 8 ) {
				$values[] = trim($value);
			}
		}

		return array_values(array_unique($values));
	}
}
