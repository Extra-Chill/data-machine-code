<?php
/**
 * DMC permission facade.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

/**
 * Delegates to Data Machine permissions when available, with a WP fallback.
 */
final class PermissionHelper {

	public static function can_manage(): bool {
		$class    = self::data_machine_permission_helper_class();
		$callback = array( $class, 'can_manage' );
		if ( is_callable($callback) ) {
			return (bool) call_user_func($callback);
		}

		$allowed = function_exists('current_user_can') ? current_user_can('manage_options') : false;
		return (bool) apply_filters('datamachine_code_can_manage', $allowed);
	}

	/** @return array<string,mixed> */
	public static function get_runtime_context(): array {
		$class    = self::data_machine_permission_helper_class();
		$callback = array( $class, 'get_runtime_context' );
		if ( is_callable($callback) ) {
			$context = call_user_func($callback);
			return is_array($context) ? $context : array();
		}

		return array();
	}

	public static function get_execution_principal(): mixed {
		$class    = self::data_machine_permission_helper_class();
		$callback = array( $class, 'get_execution_principal' );
		if ( is_callable($callback) ) {
			return call_user_func($callback);
		}

		return null;
	}

	public static function get_acting_agent_slug(): string {
		$class    = self::data_machine_permission_helper_class();
		$callback = array( $class, 'get_acting_agent_slug' );
		if ( is_callable($callback) ) {
			$agent_slug = call_user_func($callback);
			return is_string($agent_slug) ? $agent_slug : '';
		}

		return '';
	}

	private static function data_machine_permission_helper_class(): string {
		$class = apply_filters('datamachine_code_datamachine_permission_helper_class', '\\DataMachine\\Abilities\\PermissionHelper');
		return is_string($class) ? $class : '';
	}
}
