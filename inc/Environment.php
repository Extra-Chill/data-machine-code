<?php
/**
 * Data Machine Code Environment
 *
 * Public signal that a co-located coding agent runtime exists on this host.
 *
 * Data Machine Code is the bridge between WordPress and an external coding
 * agent runtime (Claude Code, OpenCode, kimaki, etc.). Its mere activation
 * is the declarative answer to "is there a coding agent here?" — there is
 * no separate marker file or constant to declare.
 *
 * Other plugins that ship disk-side artifacts for a coding agent (e.g.
 * Intelligence's SKILL.md sync, MEMORY.md disk writes, MCP bridges) should
 * gate their disk hooks on the presence of this class:
 *
 *     if ( class_exists( '\DataMachineCode\Environment' ) ) {
 *         // co-located coding agent runtime exists — register disk hooks
 *     }
 *
 * On managed hosts (WordPress.com, VIP, sandboxed environments) Data Machine
 * Code is never installed by design. The class does not exist and disk
 * artifacts are correctly skipped without any platform sniffing.
 *
 * @package DataMachineCode
 * @since   0.6.0
 */

namespace DataMachineCode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Environment {

	/**
	 * Is the coding agent runtime bridge available on this install?
	 *
	 * Returns true whenever this class is loaded, which is equivalent to
	 * "Data Machine Code is active." Provided as an explicit method so
	 * callers can write capability-style code rather than relying on the
	 * `class_exists()` idiom.
	 *
	 * @since 0.6.0
	 *
	 * @return bool Always true when this class is reachable.
	 */
	public static function is_available(): bool {
		return true;
	}

	/**
	 * Can this install execute shell commands via `shell_exec`?
	 *
	 * Some hosts disable shell functions via `disable_functions` in
	 * php.ini. Coding-agent integrations that spawn processes (e.g. MCP
	 * bridges, git operations through proc_open) should gate on this.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True if `shell_exec` is callable.
	 */
	public static function has_shell(): bool {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'shell_exec', $disabled, true );
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
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Environment capability probe.
		return is_writable( WP_CONTENT_DIR );
	}
}
