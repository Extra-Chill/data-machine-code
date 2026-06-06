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

defined('ABSPATH') || exit;

if ( ! class_exists(ProcessRunner::class) ) {
	require_once __DIR__ . '/ProcessRunner.php';
}
if ( ! class_exists(RuntimeCapabilities::class) ) {
	require_once __DIR__ . '/RuntimeCapabilities.php';
}

final class GitRunner {



	/**
	 * Inspect whether the current PHP runtime can execute local git commands.
	 *
	 * @return array<string,mixed>
	 */
	public static function diagnose(): array {
		return RuntimeCapabilities::git_diagnostic();
	}

	/**
	 * Whether local git commands can run in the current PHP runtime.
	 */
	public static function is_available(): bool {
		$diagnostic = self::diagnose();
		return ! empty($diagnostic['git_available']);
	}

	/**
	 * Whether streaming command execution is available for long-running git ops.
	 */
	public static function supports_streaming(): bool {
		$diagnostic = self::diagnose();
		return ! empty($diagnostic['git_available']) && ! empty($diagnostic['proc_open_available']);
	}

	/**
	 * Build the standard local-git-unavailable error.
	 *
	 * @param  string $operation          Human-readable operation being attempted.
	 * @param  bool   $requires_streaming Whether the operation needs proc_open streaming.
	 * @return \WP_Error
	 */
	public static function unavailable_error( string $operation = 'Git workspace operation', bool $requires_streaming = false ): \WP_Error {
		$diagnostic = self::diagnose();
		$reason     = empty($diagnostic['exec_available'])
		? 'PHP exec() is unavailable.'
		: 'The git binary is unavailable to the PHP runtime.';

		if ( $requires_streaming && ! empty($diagnostic['git_available']) && empty($diagnostic['proc_open_available']) ) {
			$reason = 'PHP proc_open() is unavailable for streaming git operations.';
		}

		$remediation = RuntimeCapabilities::workspace_remediation();

		return new \WP_Error(
			'datamachine_workspace_git_unavailable',
			sprintf('%s cannot run with the current workspace backend. %s %s', $operation, $reason, $remediation),
			array_merge(
				$diagnostic,
				array(
					'status'             => 500,
					'operation'          => $operation,
					'requires_streaming' => $requires_streaming,
					'remediation'        => $remediation,
				)
			)
		);
	}

	/**
	 * Run a git command inside a repository working tree.
	 *
	 * `$path` is assumed to have already been validated for containment by the
	 * caller — this class does not re-check. It only shell-escapes and routes
	 * stderr into the output stream for a single source of truth on errors.
	 *
	 * @param  string $path            Absolute working-tree path (passed to `git -C`).
	 * @param  string $args            Git arguments (without leading `git`).
	 * @param  int    $timeout_seconds Optional timeout in seconds. Zero disables timeout.
	 * @return array{success: true, output: string}|\WP_Error
	 */
	public static function run( string $path, string $args, int $timeout_seconds = 0 ): array|\WP_Error {
		if ( ! self::is_available() ) {
			return self::unavailable_error();
		}

		if ( $timeout_seconds > 0 && ! self::supports_streaming() ) {
			return self::unavailable_error('Timed git workspace operation', true);
		}

		$escaped = escapeshellarg($path);
		$command = sprintf('git -C %s %s 2>&1', $escaped, $args);

		$result = ProcessRunner::run(
			$command,
			array(
				'timeout_seconds' => $timeout_seconds,
				'error_code'      => 'git_command_failed',
			)
		);

		if ( is_wp_error($result) ) {
			$data = $result->get_error_data();
			$data = is_array($data) ? $data : array();
			if ( $timeout_seconds > 0 && isset($data['timeout']) ) {
				return new \WP_Error('git_command_timeout', $result->get_error_message(), $data);
			}

			return new \WP_Error('git_command_failed', str_replace('Process command', 'Git command', $result->get_error_message()), $data);
		}

		return array(
			'success' => true,
			'output'  => $result['output'],
		);
	}
}
