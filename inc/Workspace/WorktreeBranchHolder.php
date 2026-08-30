<?php
/**
 * Locate a linked worktree holding a local branch.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreeBranchHolder {

	public static function find( string $listing, string $primary, string $branch ): ?string {
		foreach ( preg_split('/\n\n+/', trim($listing)) ?: array() as $block ) {
			$path = null;
			$ref  = null;
			foreach ( explode("\n", $block) as $line ) {
				if ( str_starts_with($line, 'worktree ') ) {
					$path = substr($line, 9);
				} elseif ( str_starts_with($line, 'branch ') ) {
					$ref = substr($line, 7);
				}
			}
			if ( null !== $path && $path !== $primary && $ref === 'refs/heads/' . $branch ) {
				return $path;
			}
		}
		return null;
	}
}
