<?php
/**
 * Data Machine Code Environment
 *
 * Public signal that Data Machine Code is active on this host.
 *
 * Data Machine Code exposes code-adjacent GitHub, GitSync, workspace, and
 * runtime capabilities. Its activation means those DMC surfaces are available
 * to inspect and call, but callers must still check narrower capabilities
 * before assuming shell execution or broad filesystem writes.
 *
 * Other plugins that ship disk-side artifacts for a coding agent (e.g.
 * Intelligence's SKILL.md sync, MEMORY.md disk writes, MCP bridges) should
 * gate their DMC integrations on the presence of this class, then gate
 * shell/filesystem work on the explicit helpers below:
 *
 *     if ( class_exists( '\DataMachineCode\Environment' ) ) {
 *         // DMC is active; now check has_shell() or has_writable_fs()
 *         // before registering shell-backed or disk-projection hooks.
 *     }
 *
 * Managed hosts may support API-first DMC subsystems such as GitHub abilities
 * and GitSync while still denying shell execution or writes outside approved
 * site-owned paths. Capability checks are intentionally narrower than host
 * detection.
 *
 * @package DataMachineCode
 * @since   0.6.0
 */

namespace DataMachineCode;

use DataMachineCode\Support\RuntimeCapabilities;

if ( ! defined('ABSPATH') ) {
	exit;
}

if ( ! class_exists(RuntimeCapabilities::class) ) {
	require_once __DIR__ . '/Support/RuntimeCapabilities.php';
}

class Environment {



	/**
	 * Is Data Machine Code available on this install?
	 *
	 * Returns true whenever this class is loaded, which is equivalent to
	 * "Data Machine Code is active." This does not imply shell execution,
	 * local git, or writable plugin/theme filesystems; callers that need those
	 * must also check has_shell() and has_writable_fs(). Provided as an explicit
	 * method so callers can write capability-style code rather than relying on
	 * the `class_exists()` idiom.
	 *
	 * @since 0.6.0
	 *
	 * @return bool Always true when this class is reachable.
	 */
	public static function is_available(): bool {
		return true;
	}

	/**
	 * Can this install execute shell commands?
	 *
	 * Some hosts disable shell functions via `disable_functions` in
	 * php.ini. Other hosts expose the functions but cannot execute external
	 * commands. Coding-agent integrations that spawn processes should gate on
	 * this.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True if command execution is usable.
	 */
	public static function has_shell(): bool {
		$diagnostic = self::shell_diagnostic();
		return true === $diagnostic['ok'];
	}

	/**
	 * Return the shell capability diagnostic for this request.
	 *
	 * @since 0.24.0
	 *
	 * @return array{ok: bool, reason: string, exec_available: bool, shell_exec_available: bool, proc_open_available: bool, output?: string, exit_code?: int|null}
	 */
	public static function shell_diagnostic(): array {
		return RuntimeCapabilities::shell_diagnostic();
	}

	/**
	 * Evaluate shell capability with injectable probes for tests.
	 *
	 * @since 0.24.0
	 *
	 * @param  callable $function_exists    Callback receiving a function name and returning availability.
	 * @param  string   $disabled_functions Raw `disable_functions` value.
	 * @param  callable $command_runner     Callback receiving command and returning output plus exit code.
	 * @return array{ok: bool, reason: string, exec_available: bool, shell_exec_available: bool, proc_open_available: bool, output?: string, exit_code?: int|null}
	 */
	private static function evaluate_shell_capability( callable $function_exists, string $disabled_functions, callable $command_runner ): array {
		return RuntimeCapabilities::evaluate_shell_capability($function_exists, $disabled_functions, $command_runner);
	}

	/**
	 * Can this install write files outside of `/uploads`?
	 *
	 * Probes by checking writability of `WP_CONTENT_DIR`. On managed hosts
	 * that sandbox plugin filesystem writes to the uploads directory only,
	 * this returns false.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True if `WP_CONTENT_DIR` is writable by the web user.
	 */
	public static function has_writable_fs(): bool {
		if ( ! defined('WP_CONTENT_DIR') ) {
			return false;
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Environment capability probe.
		return is_writable(WP_CONTENT_DIR);
	}
}
