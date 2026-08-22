<?php
/**
 * Opaque cursor encoding for bounded list endpoints.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class ListCursor {

	/**
	 * Encode a stable row key and its bound filter scope.
	 *
	 * @param array<string,mixed> $scope Normalized filters that constrain the list.
	 */
	public static function encode( string $after, array $scope ): string {
		$payload = array_merge(array( 'v' => 1, 'after' => $after ), $scope);
		return rtrim(strtr(base64_encode((string) wp_json_encode($payload)), '+/', '-_'), '=');
	}

	/**
	 * Decode a cursor and verify that it belongs to the requested filter scope.
	 *
	 * @param array<string,mixed> $scope Normalized filters that constrain the list.
	 */
	public static function decode( string $cursor, array $scope, string $error_code, string $error_message ): string|\WP_Error {
		$encoded = strtr($cursor, '-_', '+/');
		$decoded = base64_decode(str_pad($encoded, strlen($encoded) + ( 4 - strlen($encoded) % 4 ) % 4, '='), true);
		$payload = is_string($decoded) ? json_decode($decoded, true) : null;

		if ( ! is_array($payload) || 1 !== ( $payload['v'] ?? null ) || ! is_string($payload['after'] ?? null ) ) {
			return new \WP_Error($error_code, $error_message, array( 'status' => 400 ));
		}

		foreach ( $scope as $key => $value ) {
			if ( ( $payload[ $key ] ?? null ) !== $value ) {
				return new \WP_Error($error_code, $error_message, array( 'status' => 400 ));
			}
		}

		return $payload['after'];
	}
}
