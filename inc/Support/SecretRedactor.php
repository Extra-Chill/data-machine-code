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
	public static function redact( string $value ): string {
		foreach ( self::runtime_secret_values() as $secret ) {
			$value = str_replace($secret, '[redacted]', $value);
		}

		$value = preg_replace('/\b(gh[pousr]_[A-Za-z0-9_]{12,})\b/', '[redacted]', $value) ?? $value;
		$value = preg_replace('/\b(sk-[A-Za-z0-9_-]{12,})\b/', '[redacted]', $value) ?? $value;
		$value = preg_replace('/\b(xox[baprs]-[A-Za-z0-9-]{12,})\b/', '[redacted]', $value) ?? $value;
		$value = preg_replace('/\b(Bearer)\s+[A-Za-z0-9._~+\/-]{8,}/i', '$1 [redacted]', $value) ?? $value;

		return preg_replace('/\b(authorization|token|password|secret|api[_-]?key|wp_ai_gateway_token|openai_api_key)\s*[:=]\s*\S+/i', '$1: [redacted]', $value) ?? $value;
	}

	/**
	 * Runtime secret values that must be redacted when present in process env.
	 *
	 * @return string[]
	 */
	private static function runtime_secret_values(): array {
		$values = array();
		foreach ( array( 'WP_AI_GATEWAY_TOKEN', 'OPENAI_API_KEY' ) as $name ) {
			$value = getenv($name);
			if ( is_string($value) && strlen(trim($value)) >= 8 ) {
				$values[] = trim($value);
			}
		}

		return array_values(array_unique($values));
	}
}
