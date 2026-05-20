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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Environment {

	/**
	 * Memoized shell capability diagnostic. Null until the first probe.
	 *
	 * @var array{ok: bool, reason: string, output?: string, exit_code?: int|null}|null
	 */
	private static $shell_diagnostic_cache = null;

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
	 * @return array{ok: bool, reason: string, output?: string, exit_code?: int|null}
	 */
	public static function shell_diagnostic(): array {
		if ( null !== self::$shell_diagnostic_cache ) {
			return self::$shell_diagnostic_cache;
		}

		self::$shell_diagnostic_cache = self::evaluate_shell_capability(
			static function ( string $function_name ): bool {
				return function_exists( $function_name );
			},
			(string) ini_get( 'disable_functions' ),
			static function ( string $command ): array {
				$output    = array();
				$exit_code = null;

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Environment capability probe.
				exec( $command, $output, $exit_code );

				return array(
					'output'    => $output,
					'exit_code' => $exit_code,
				);
			}
		);

		return self::$shell_diagnostic_cache;
	}

	/**
	 * Evaluate shell capability with injectable probes for tests.
	 *
	 * @since 0.24.0
	 *
	 * @param callable $function_exists    Callback receiving a function name and returning availability.
	 * @param string   $disabled_functions Raw `disable_functions` value.
	 * @param callable $command_runner     Callback receiving command and returning output plus exit code.
	 * @return array{ok: bool, reason: string, output?: string, exit_code?: int|null}
	 */
	private static function evaluate_shell_capability( callable $function_exists, string $disabled_functions, callable $command_runner ): array {
		$required_functions = array( 'exec', 'shell_exec' );
		$disabled           = array_filter( array_map( 'trim', explode( ',', $disabled_functions ) ) );

		foreach ( $required_functions as $function_name ) {
			if ( ! $function_exists( $function_name ) ) {
				return array(
					'ok'     => false,
					'reason' => $function_name . '_missing',
				);
			}

			if ( in_array( $function_name, $disabled, true ) ) {
				return array(
					'ok'     => false,
					'reason' => $function_name . '_disabled',
				);
			}
		}

		$marker = '__datamachine_code_shell_ok__';

		/** @var array{output: array<int, string>, exit_code: int|null} $result */
		$result = $command_runner( 'printf ' . escapeshellarg( $marker ) . ' 2>&1' );

		$output    = $result['output'];
		$exit_code = $result['exit_code'];

		$actual_output = trim( implode( "\n", array_map( 'strval', $output ) ) );
		if ( 0 !== $exit_code || $marker !== $actual_output ) {
			return array(
				'ok'        => false,
				'reason'    => 'probe_failed',
				'output'    => $actual_output,
				'exit_code' => $exit_code,
			);
		}

		return array(
			'ok'        => true,
			'reason'    => 'ok',
			'output'    => $actual_output,
			'exit_code' => $exit_code,
		);
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
