<?php
/**
 * Homeboy capability helper.
 *
 * Detects whether the [Homeboy](https://github.com/Extra-Chill/homeboy)
 * Rust CLI is callable from this host's shell. Homeboy is a code-factory
 * tool that runs release automation, audits, lints, tests, and git
 * primitives over local projects. It operates outside WordPress and
 * never speaks PHP — the only thing DMC needs to know is whether the
 * binary is on PATH so it can teach agents about the verbs.
 *
 * Detection runs once per request and is memoized. Callers should not
 * shell out themselves; use `is_available()` and rely on Data Machine
 * Code's `Environment::has_shell()` for the hard gate.
 *
 * @package DataMachineCode
 * @since   0.11.0
 */

namespace DataMachineCode;

if ( ! defined( 'ABSPATH' ) ) {
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
	 * Is the `homeboy` CLI on this host's PATH?
	 *
	 * Probes via `command -v homeboy` exactly once per request. Returns
	 * false on hosts that disable shell functions, on hosts that lack
	 * the binary, and any time `Environment::has_shell()` returns false.
	 *
	 * @since 0.11.0
	 *
	 * @return bool True when the binary is callable from the current shell.
	 */
	public static function is_available(): bool {
		if ( null !== self::$available_cache ) {
			return self::$available_cache;
		}

		if ( ! Environment::has_shell() ) {
			self::$available_cache = false;
			return false;
		}

		$output = @shell_exec( 'command -v homeboy 2>/dev/null' );
		self::$available_cache = ! empty( trim( (string) $output ) );
		return self::$available_cache;
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
