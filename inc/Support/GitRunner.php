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
	 * @param string $path Absolute working-tree path (passed to `git -C`).
	 * @param string $args Git arguments (without leading `git`).
	 * @return array{success: true, output: string}|\WP_Error
	 */
	public static function run( string $path, string $args ): array|\WP_Error {
		$escaped = escapeshellarg( $path );
		$command = sprintf( 'git -C %s %s 2>&1', $escaped, $args );

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
}
