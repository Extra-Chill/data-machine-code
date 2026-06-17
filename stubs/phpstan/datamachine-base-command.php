<?php
/**
 * PHPStan stub for Data Machine's shared WP-CLI base command.
 *
 * @package DataMachineCode\Stubs
 */

namespace DataMachine\Cli;

defined('ABSPATH') || exit;

/**
 * Static-analysis shape for the external Data Machine BaseCommand class.
 */
abstract class BaseCommand {

	/**
	 * @param array<int,array<string,mixed>> $items      Items to display.
	 * @param array<int,string>              $fields     Default fields/columns to display.
	 * @param array<string,mixed>            $assoc_args Command arguments.
	 * @param string                         $id_field   Field name to use for --format=ids.
	 */
	protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {
	}
}
