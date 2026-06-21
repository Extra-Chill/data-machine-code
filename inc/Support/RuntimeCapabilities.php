<?php
/**
 * Runtime Capabilities
 *
 * Shared shell and binary capability probes for DMC's shell-backed workspace
 * plumbing. Keep host diagnostics in one place so Environment, GitRunner, and
 * bootstrap paths agree on what this PHP runtime can execute.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class RuntimeCapabilities {

	public const CODEBOX_RUNTIME_CONTEXT_SCHEMA = 'wp-codebox/runtime-context/v1';

	/**
	 * @var array{ok: bool, reason: string, exec_available: bool, shell_exec_available: bool, proc_open_available: bool, output?: string, exit_code?: int|null}|null
	 */
	private static ?array $shell_diagnostic = null;

	/**
	 * @var array<string,array{binary: string, available: bool, path: string, exec_available: bool, exit_code: int|null}>
	 */
	private static array $binary_diagnostics = array();

	/**
	 * Whether a runtime context schema is a DMC-supported mounted runtime contract.
	 */
	public static function supports_runtime_context_schema( string $schema ): bool {
		return self::CODEBOX_RUNTIME_CONTEXT_SCHEMA === $schema;
	}

	/**
	 * Return the shell capability diagnostic for this request.
	 *
	 * @return array{ok: bool, reason: string, exec_available: bool, shell_exec_available: bool, proc_open_available: bool, output?: string, exit_code?: int|null}
	 */
	public static function shell_diagnostic(): array {
		if ( null !== self::$shell_diagnostic ) {
			return self::$shell_diagnostic;
		}

		self::$shell_diagnostic = self::evaluate_shell_capability(
			static function ( string $function_name ): bool {
				return function_exists($function_name);
			},
			(string) ini_get('disable_functions'),
			static function ( string $command ): array {
				$output    = array();
				$exit_code = null;

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runtime capability probe.
				exec($command, $output, $exit_code);

				return array(
					'output'    => $output,
					'exit_code' => $exit_code,
				);
			}
		);

		return self::$shell_diagnostic;
	}

	/**
	 * Evaluate shell capability with injectable probes for tests.
	 *
	 * @param  callable $function_exists    Callback receiving a function name and returning availability.
	 * @param  string   $disabled_functions Raw `disable_functions` value.
	 * @param  callable $command_runner     Callback receiving command and returning output plus exit code.
	 * @return array{ok: bool, reason: string, exec_available: bool, shell_exec_available: bool, proc_open_available: bool, output?: string, exit_code?: int|null}
	 */
	public static function evaluate_shell_capability( callable $function_exists, string $disabled_functions, callable $command_runner ): array {
		$disabled             = array_filter(array_map('trim', explode(',', $disabled_functions)));
		$exec_available       = self::function_available('exec', $function_exists, $disabled);
		$shell_exec_available = self::function_available('shell_exec', $function_exists, $disabled);
		$proc_open_available  = self::function_available('proc_open', $function_exists, $disabled);

		$base = array(
			'exec_available'       => $exec_available,
			'shell_exec_available' => $shell_exec_available,
			'proc_open_available'  => $proc_open_available,
		);

		if ( ! $exec_available ) {
			$reason = $function_exists('exec') ? 'exec_disabled' : 'exec_missing';
			return array_merge(
				$base,
				array(
					'ok'     => false,
					'reason' => $reason,
				)
			);
		}

		if ( ! $shell_exec_available ) {
			$reason = $function_exists('shell_exec') ? 'shell_exec_disabled' : 'shell_exec_missing';
			return array_merge(
				$base,
				array(
					'ok'     => false,
					'reason' => $reason,
				)
			);
		}

		$marker = '__datamachine_code_shell_ok__';

		/** @var array{output: array<int,string>, exit_code: int|null} $result */
		$result = $command_runner('printf ' . escapeshellarg($marker) . ' 2>&1');

		$output        = $result['output'];
		$exit_code     = $result['exit_code'];
		$actual_output = trim(implode("\n", array_map('strval', $output)));
		if ( 0 !== $exit_code || $marker !== $actual_output ) {
			return array_merge(
				$base,
				array(
					'ok'        => false,
					'reason'    => 'probe_failed',
					'output'    => $actual_output,
					'exit_code' => $exit_code,
				)
			);
		}

		return array_merge(
			$base,
			array(
				'ok'        => true,
				'reason'    => 'ok',
				'output'    => $actual_output,
				'exit_code' => $exit_code,
			)
		);
	}

	/**
	 * Whether a named binary is available on PATH.
	 */
	public static function binary_available( string $binary, ?string $path = null ): bool {
		$diagnostic = self::binary_diagnostic($binary, $path);
		return ! empty($diagnostic['available']);
	}

	/**
	 * Return a binary lookup diagnostic.
	 *
	 * @return array{binary: string, available: bool, path: string, exec_available: bool, exit_code: int|null}
	 */
	public static function binary_diagnostic( string $binary, ?string $path = null ): array {
		$cache_key = $binary . "\0" . (string) $path;
		if ( isset(self::$binary_diagnostics[ $cache_key ]) ) {
			return self::$binary_diagnostics[ $cache_key ];
		}

		$diagnostic = array(
			'binary'         => $binary,
			'available'      => false,
			'path'           => '',
			'exec_available' => self::shell_diagnostic()['exec_available'],
			'exit_code'      => null,
		);

		if ( '' === $binary || ! preg_match('/^[a-zA-Z0-9_.\-]+$/', $binary) || empty($diagnostic['exec_available']) ) {
			self::$binary_diagnostics[ $cache_key ] = $diagnostic;
			return $diagnostic;
		}

		$prefix = null !== $path && '' !== $path ? sprintf('PATH=%s ', escapeshellarg($path)) : '';
		$output = array();
		$exit   = null;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runtime capability probe.
		exec(sprintf('%scommand -v %s 2>/dev/null', $prefix, escapeshellarg($binary)), $output, $exit);

		$binary_path = trim( (string) ( $output[0] ?? '' ) );
		$diagnostic  = array(
			'binary'         => $binary,
			'available'      => 0 === $exit && '' !== $binary_path,
			'path'           => $binary_path,
			'exec_available' => true,
			'exit_code'      => $exit,
		);

		self::$binary_diagnostics[ $cache_key ] = $diagnostic;
		return $diagnostic;
	}

	/**
	 * Return the local git diagnostic expected by workspace callers.
	 *
	 * @return array<string,mixed>
	 */
	public static function git_diagnostic(): array {
		$shell = self::shell_diagnostic();
		$git   = self::binary_diagnostic('git');

		return array(
			'backend'              => 'local_git',
			'exec_available'       => (bool) $shell['exec_available'],
			'shell_exec_available' => (bool) $shell['shell_exec_available'],
			'proc_open_available'  => (bool) $shell['proc_open_available'],
			'git_available'        => (bool) $git['available'],
			'git_path'             => (string) $git['path'],
			'probe_exit_code'      => $git['exit_code'],
			'shell_reason'         => (string) $shell['reason'],
		);
	}

	/**
	 * Standard remediation for shell-backed workspace operations.
	 */
	public static function workspace_remediation(): string {
		return 'Run workspace abilities in a host runtime with local git access, or provide a Data Machine Code workspace backend that executes these operations outside the constrained PHP sandbox.';
	}

	/**
	 * @param string[] $disabled
	 */
	private static function function_available( string $function_name, callable $function_exists, array $disabled ): bool {
		return $function_exists($function_name) && ! in_array($function_name, $disabled, true);
	}
}
