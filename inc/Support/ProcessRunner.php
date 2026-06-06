<?php
/**
 * Process Runner
 *
 * Shared shell command execution primitive for DMC plumbing. Keeps timeout,
 * output capture, progress streaming, environment, and error envelopes aligned
 * across git, clone, and bootstrap paths.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class ProcessRunner {



	/**
	 * Execute a shell command and return a normalized result envelope.
	 *
	 * @param  string              $command Shell command to execute.
	 * @param  array<string,mixed> $options Execution options.
	 * @return array{success: bool, output: string, exit_code: int}|\WP_Error
	 */
	public static function run( string $command, array $options = array() ): array|\WP_Error {
		$timeout_seconds = max(0, (int) ( $options['timeout_seconds'] ?? 0 ));
		$output_cap      = max(0, (int) ( $options['output_cap_bytes'] ?? 0 ));
		$on_output       = is_callable($options['on_output'] ?? null) ? $options['on_output'] : null;
		$env             = isset($options['env']) && is_array($options['env']) ? $options['env'] : null;
		$cwd             = isset($options['cwd']) && is_string($options['cwd']) && '' !== $options['cwd'] ? $options['cwd'] : null;

		if ( 0 === $timeout_seconds && null === $on_output && null === $env && null === $cwd ) {
			return self::run_via_exec($command, $options, $output_cap);
		}

		if ( ! function_exists('proc_open') ) {
			return self::error(
				$options,
				0 === $timeout_seconds ? 'Process command failed to start.' : sprintf('Process command timed out after %d second(s).', $timeout_seconds),
				array(
					'proc_open_available' => false,
				)
			);
		}

		return self::run_via_proc_open($command, $options, $timeout_seconds, $output_cap, $on_output, $cwd, $env);
	}

	/**
	 * @param  array<string,mixed> $options
	 * @return array{success: bool, output: string, exit_code: int}|\WP_Error
	 */
	private static function run_via_exec( string $command, array $options, int $output_cap ): array|\WP_Error {
		if ( ! function_exists('exec') ) {
			return self::error($options, 'Process command failed to start.', array( 'exec_available' => false ));
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec($command, $output, $exit_code);
		$joined = self::cap_output(implode("\n", $output), $output_cap);

		if ( 0 !== $exit_code ) {
			return self::error(
				$options,
				sprintf('Process command failed (exit %d): %s', $exit_code, $joined),
				array(
					'exit_code' => $exit_code,
					'output'    => $joined,
				)
			);
		}

		return array(
			'success'   => true,
			'output'    => $joined,
			'exit_code' => 0,
		);
	}

	/**
	 * @param  array<string,mixed> $options
	 * @param  callable|null       $on_output
	 * @param  array<string,mixed>|null $env
	 * @return array{success: bool, output: string, exit_code: int}|\WP_Error
	 */
	private static function run_via_proc_open( string $command, array $options, int $timeout_seconds, int $output_cap, ?callable $on_output, ?string $cwd, ?array $env ): array|\WP_Error {
		$descriptor_spec = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		$process = proc_open($command, $descriptor_spec, $pipes, $cwd, $env);
		if ( ! is_resource($process) ) {
			return self::error($options, 'Process command failed to start.', array( 'status' => 500 ));
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$output    = '';
		$deadline  = $timeout_seconds > 0 ? microtime(true) + $timeout_seconds : null;
		$exit_code = null;

		while ( true ) {
			$chunk = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
			if ( '' !== $chunk ) {
				$output .= $chunk;
				if ( null !== $on_output ) {
					$on_output($chunk);
				}
			}

			$status = proc_get_status($process);
			if ( empty($status['running']) ) {
				$exit_code = isset($status['exitcode']) ? (int) $status['exitcode'] : null;
				break;
			}

			if ( null !== $deadline && microtime(true) >= $deadline ) {
				$output = self::terminate_timed_out_process($process, $pipes, $output);
				return self::error(
					$options,
					sprintf('Process command timed out after %d second(s).', $timeout_seconds),
					array(
						'timeout' => $timeout_seconds,
						'output'  => self::cap_output(trim($output), $output_cap),
					)
				);
			}

			usleep((int) ( $options['poll_interval_microseconds'] ?? 50000 ));
		}

		$output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
		foreach ( $pipes as $pipe ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes are not WordPress filesystem paths.
			fclose($pipe);
		}

		$close_code = proc_close($process);
		if ( null === $exit_code ) {
			$exit_code = $close_code;
		}

		$output = self::cap_output(trim(str_replace("\r", "\n", $output)), $output_cap);
		if ( 0 !== $exit_code ) {
			return self::error(
				$options,
				sprintf('Process command failed (exit %d): %s', $exit_code, $output),
				array(
					'exit_code' => $exit_code,
					'output'    => $output,
				)
			);
		}

		return array(
			'success'   => true,
			'output'    => $output,
			'exit_code' => 0,
		);
	}

	/**
	 * @param resource $process
	 * @param array<int,resource> $pipes
	 */
	private static function terminate_timed_out_process( $process, array $pipes, string $output ): string {
		proc_terminate($process);
		usleep(100000);
		$status = proc_get_status($process);
		if ( ! empty($status['running']) ) {
			proc_terminate($process, 9);
		}

		$output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
		foreach ( $pipes as $pipe ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes are not WordPress filesystem paths.
			fclose($pipe);
		}
		proc_close($process);

		return $output;
	}

	private static function cap_output( string $output, int $output_cap ): string {
		if ( $output_cap > 0 && strlen($output) > $output_cap ) {
			return '...' . substr($output, -1 * $output_cap);
		}

		return $output;
	}

	/**
	 * @param array<string,mixed> $options
	 * @param array<string,mixed> $data
	 */
	private static function error( array $options, string $message, array $data = array() ): array|\WP_Error {
		if ( ! empty($options['error_as_result']) ) {
			return array(
				'success'   => false,
				'output'    => (string) ( $data['output'] ?? $message ),
				'exit_code' => (int) ( $data['exit_code'] ?? 1 ),
			);
		}

		$code = isset($options['error_code']) && is_string($options['error_code']) && '' !== $options['error_code'] ? $options['error_code'] : 'process_command_failed';
		return new \WP_Error(
			$code,
			$message,
			array_merge(
				array( 'status' => (int) ( $options['status'] ?? 500 ) ),
				$data
			)
		);
	}
	}
