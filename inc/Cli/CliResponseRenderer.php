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
