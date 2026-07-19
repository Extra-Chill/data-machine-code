<?php
/**
 * Git checkout path helpers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class GitCheckout {

	/**
	 * Whether a path is a Git checkout, including a linked worktree.
	 *
	 * A normal primary checkout has a `.git` directory. A checkout that is
	 * itself a linked Git worktree instead has a `.git` gitdir marker file.
	 */
	public static function exists( string $path ): bool {
		return is_dir($path) && ( is_dir($path . '/.git') || is_file($path . '/.git') );
	}
}
