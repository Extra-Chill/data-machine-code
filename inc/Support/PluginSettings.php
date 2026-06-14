<?php
/**
 * DMC settings facade.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

/**
 * Delegates to Data Machine PluginSettings when available, with option fallback.
 */
final class PluginSettings {

	public static function get( string $key, mixed $fallback = null ): mixed {
		$class    = self::data_machine_plugin_settings_class();
		$callback = array( $class, 'get' );
		if ( is_callable($callback) ) {
			return call_user_func($callback, $key, $fallback);
		}

		if ( function_exists('get_option') ) {
			return get_option($key, $fallback);
		}

		return $fallback;
	}

	private static function data_machine_plugin_settings_class(): string {
		$class = apply_filters('datamachine_code_datamachine_plugin_settings_class', '\\DataMachine\\Core\\PluginSettings');
		return is_string($class) ? $class : '';
	}
}
