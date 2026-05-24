<?php
/**
 * Homeboy capability helper.
 *
 * Detects whether the [Homeboy](https://github.com/Extra-Chill/homeboy)
 * Rust CLI is available to the co-located coding-agent runtime. Homeboy is a
 * code-factory tool that runs release automation, audits, lints, tests, and
 * git primitives over local projects. It operates outside WordPress and never
 * speaks PHP — the only thing DMC needs to know is whether agents should be
 * taught about the verbs.
 *
 * Detection runs once per request and is memoized. Callers should not
 * shell out themselves; use `is_available()` and rely on Data Machine
 * Code's `Environment::has_shell()` for the hard gate.
 *
 * @package DataMachineCode
 * @since   0.11.0
 */

namespace DataMachineCode;

if ( ! defined('ABSPATH') ) {
	exit;
}

class Homeboy {



	/**
	 * Memoized detection result. Null until the first probe; bool after.
	 *
	 * @var bool|null
	 */
	private static $available_cache = null;

	/**
	 * Is the `homeboy` CLI available to the coding-agent runtime?
	 *
	 * Prefer the installer-provided `datamachine_code_homeboy_available` option
	 * because Studio's WordPress PHP runtime may not see the host shell PATH even
	 * when the external coding-agent runtime can call Homeboy. Falls back to a
	 * direct shell probe for VPS and non-sandboxed local installs.
	 *
	 * @since 0.11.0
	 *
	 * @return bool True when the binary is callable from the current shell.
	 */
	public static function is_available(): bool {
		if ( null !== self::$available_cache ) {
			return self::$available_cache;
		}

		$declared = self::get_declared_availability();
		if ( null !== $declared ) {
			self::$available_cache = $declared;
			return self::$available_cache;
		}

		if ( ! Environment::has_shell() ) {
			self::$available_cache = false;
			return false;
		}

     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Host CLI capability probe.
		$output                = shell_exec('command -v homeboy 2>/dev/null');
		self::$available_cache = ! empty(trim( (string) $output));
		return self::$available_cache;
	}

	/**
	 * Read installer-declared Homeboy availability.
	 *
	 * @since 0.23.4
	 *
	 * @return bool|null True/false when declared, null when no declaration exists.
	 */
	private static function get_declared_availability(): ?bool {
		$declared = null;

		if ( function_exists('get_option') ) {
			$declared = get_option('datamachine_code_homeboy_available', null);
		}

		if ( function_exists('apply_filters') ) {
			$declared = apply_filters('datamachine_code_homeboy_available', $declared);
		}

		if ( null === $declared || '' === $declared ) {
			return null;
		}

		if ( is_bool($declared) ) {
			return $declared;
		}

		$normalized = strtolower(trim( (string) $declared));
		if ( in_array($normalized, array( '1', 'true', 'yes', 'on' ), true) ) {
			return true;
		}

		if ( in_array($normalized, array( '0', 'false', 'no', 'off' ), true) ) {
			return false;
		}

		return null;
	}

	/**
	 * Reset the detection cache.
	 *
	 * Test-only seam — production code never needs to re-probe because
	 * PATH does not change mid-request.
	 *
	 * @since 0.11.0
	 */
	public static function reset_cache(): void {
		self::$available_cache = null;
	}
}
