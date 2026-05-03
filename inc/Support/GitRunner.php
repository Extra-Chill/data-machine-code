<?php
/**
 * Git Runner
 *
 * Thin shared wrapper around `exec()` for running git commands in a target
 * directory. Used by both `Workspace\Workspace` (for agent-owned checkouts
 * under the workspace root) and `GitSync\GitSync` (for site-owned subtrees
 * under ABSPATH).
 *
 * Keeps the shell invocation pattern in one place so containment checks,
 * stderr merging, and error shape stay consistent across callers.
 *
 * @package DataMachineCode\Support
 * @since   0.7.0
 */

namespace DataMachineCode\Support;

defined( 'ABSPATH' ) || exit;

final class GitRunner {

	/**
	 * Run a git command inside a repository working tree.
	 *
	 * `$path` is assumed to have already been validated for containment by the
	 * caller — this class does not re-check. It only shell-escapes and routes
	 * stderr into the output stream for a single source of truth on errors.
	 *
	 * @param string $path            Absolute working-tree path (passed to `git -C`).
	 * @param string $args            Git arguments (without leading `git`).
	 * @param int    $timeout_seconds Optional timeout in seconds. Zero disables timeout.
	 * @return array{success: true, output: string}|\WP_Error
	 */
	public static function run( string $path, string $args, int $timeout_seconds = 0 ): array|\WP_Error {
		$escaped = escapeshellarg( $path );
		$command = sprintf( 'git -C %s %s 2>&1', $escaped, $args );

		if ( $timeout_seconds > 0 ) {
			return self::run_with_timeout( $command, $timeout_seconds );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return new \WP_Error(
				'git_command_failed',
				sprintf( 'Git command failed (exit %d): %s', $exit_code, implode( "\n", $output ) ),
				array(
					'status' => 500,
					'output' => implode( "\n", $output ),
				)
			);
		}

		return array(
			'success' => true,
			'output'  => implode( "\n", $output ),
		);
	}

	/**
	 * Run a command with a wall-clock timeout.
	 *
	 * @param string $command         Shell command.
	 * @param int    $timeout_seconds Timeout in seconds.
	 * @return array{success: true, output: string}|\WP_Error
	 */
	private static function run_with_timeout( string $command, int $timeout_seconds ): array|\WP_Error {
		$descriptor_spec = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		$process = proc_open( $command, $descriptor_spec, $pipes );
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'git_command_failed', 'Git command failed to start.', array( 'status' => 500 ) );
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$output    = '';
		$deadline  = microtime( true ) + $timeout_seconds;
		$exit_code = null;

		while ( true ) {
			$output .= (string) stream_get_contents( $pipes[1] );
			$output .= (string) stream_get_contents( $pipes[2] );

			$status = proc_get_status( $process );
			if ( empty( $status['running'] ) ) {
				$exit_code = isset( $status['exitcode'] ) ? (int) $status['exitcode'] : null;
				break;
			}

			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $process );
				usleep( 100000 );
				$status = proc_get_status( $process );
				if ( ! empty( $status['running'] ) ) {
					proc_terminate( $process, 9 );
				}

				foreach ( $pipes as $pipe ) {
					fclose( $pipe );
				}
				proc_close( $process );

				return new \WP_Error(
					'git_command_timeout',
					sprintf( 'Git command timed out after %d second(s).', $timeout_seconds ),
					array(
						'status'  => 500,
						'timeout' => $timeout_seconds,
						'output'  => trim( $output ),
					)
				);
			}

			usleep( 50000 );
		}

		$output .= (string) stream_get_contents( $pipes[1] );
		$output .= (string) stream_get_contents( $pipes[2] );
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}

		$close_code = proc_close( $process );
		if ( null === $exit_code ) {
			$exit_code = $close_code;
		}
		$output    = trim( $output );
		if ( 0 !== $exit_code ) {
			return new \WP_Error(
				'git_command_failed',
				sprintf( 'Git command failed (exit %d): %s', $exit_code, $output ),
				array(
					'status' => 500,
					'output' => $output,
				)
			);
		}

		return array(
			'success' => true,
			'output'  => $output,
		);
	}
}
