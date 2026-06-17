<?php
/**
 * Shared JSON encoding and decoding helpers.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

class JsonCodec {

	/**
	 * Encode a value as JSON.
	 */
	public static function encode( mixed $value, int $flags = 0, int $depth = 512 ): ?string {
		$encoded = function_exists('wp_json_encode')
			? wp_json_encode($value, $flags, $depth)
			: json_encode($value, $flags, $depth); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Portable fallback.

		return is_string($encoded) ? $encoded : null;
	}

	/**
	 * Encode a value as JSON, falling back to a caller-provided default on failure.
	 */
	public static function encode_or_default( mixed $value, string $fallback = '{}', int $flags = 0, int $depth = 512 ): string {
		$encoded = self::encode($value, $flags, $depth);
		return null === $encoded ? $fallback : $encoded;
	}

	/**
	 * Decode a JSON object/array string, falling back to the supplied default.
	 *
	 * @param mixed             $value    JSON string.
	 * @param array<mixed>|null $fallback Default returned when decoding fails or the result is not an array.
	 * @return array<mixed>|null
	 */
	public static function decode_array( mixed $value, ?array $fallback = array() ): ?array {
		$decoded = json_decode( (string) $value, true );
		return is_array($decoded) ? $decoded : $fallback;
	}
}
