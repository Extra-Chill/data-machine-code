<?php
/**
 * Shared WP-CLI response rendering helpers.
 *
 * @package DataMachineCode\Cli
 */

namespace DataMachineCode\Cli;

use WP_CLI;

defined('ABSPATH') || exit;

final class CliResponseRenderer {

	/**
	 * Render a payload as pretty JSON.
	 *
	 * @param mixed $payload Response payload.
	 */
	public function json( mixed $payload ): void {
		WP_CLI::line( (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) );
	}

	/** Build a compact error envelope without database adapter diagnostics. */
	public function error_envelope( \WP_Error $error ): array {
		$message = self::sanitize_error_value($error->get_error_message());
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $error->get_error_code(),
				'message' => is_string($message) && '' !== $message ? $message : 'Workspace operation failed.',
				'data'    => self::sanitize_error_value($error->get_error_data()),
			),
		);
	}

	private static function sanitize_error_value( mixed $value, string $key = '' ): mixed {
		if ( in_array(strtolower($key), array( 'wpdb_error', 'sql', 'query', 'trace', 'backtrace' ), true) ) {
			return null;
		}
		if ( is_array($value) ) {
			$sanitized = array();
			foreach ( $value as $item_key => $item ) {
				$clean = self::sanitize_error_value($item, (string) $item_key);
				if ( null !== $clean ) {
					$sanitized[ $item_key ] = $clean;
				}
			}
			return $sanitized;
		}
		if ( is_string($value) && preg_match('/<\/?(?:html|body|div|table|tr|td|p|br)\b|sqlstate\[|database is locked|wordpress database error|stack trace:/i', $value) ) {
			return 'Database diagnostics omitted.';
		}
		return is_object($value) ? null : $value;
	}

	/**
	 * Render rows with WP-CLI's native item formatter.
	 *
	 * @param array<int,array<string,mixed>> $items Rows to render.
	 * @param string[]                       $fields Field order.
	 * @param array<string,mixed>            $assoc_args CLI assoc args.
	 * @param string                         $default_format Default format.
	 */
	public function items( array $items, array $fields, array $assoc_args, string $default_format = 'table' ): void {
		$format = (string) ( $assoc_args['format'] ?? $default_format );

		if ( function_exists('WP_CLI\\Utils\\format_items') ) {
			\WP_CLI\Utils\format_items($format, $items, $fields);
			return;
		}

		foreach ( $items as $item ) {
			WP_CLI::line(implode("\t", array_map(static fn( string $field ): string => (string) ( $item[ $field ] ?? '' ), $fields)));
		}
	}
}
