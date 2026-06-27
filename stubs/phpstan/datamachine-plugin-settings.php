<?php
/**
 * PHPStan stub for Data Machine plugin settings.
 *
 * @package DataMachineCode\Stubs
 */

namespace DataMachine\Core;

defined('ABSPATH') || exit;

/**
 * Static-analysis shape for the external Data Machine PluginSettings class.
 */
class PluginSettings {

	/**
	 * @param mixed $fallback Fallback value returned when the setting is unset.
	 * @return mixed
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		return $fallback;
	}
}
