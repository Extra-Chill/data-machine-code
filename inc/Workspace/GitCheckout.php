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

	/**
	 * Refuse deletion of an authoritative primary or a path that owns a linked
	 * worktree's common Git directory. This is intentionally filesystem-based:
	 * inventory can be stale precisely when this guard is needed most.
	 *
	 * @return array{code:string,message:string,common_dir?:string,worktrees?:array<int,string>}|null
	 */
	public static function deletion_protection( string $candidate, string $workspace ): ?array {
		$candidate_real = realpath($candidate);
		$workspace_real = realpath($workspace);
		if ( false === $candidate_real || false === $workspace_real ) {
			return array( 'code' => 'primary_common_dir_protected', 'message' => 'Refusing deletion because the candidate or workspace path could not be resolved.' );
		}
		$candidate_real = rtrim($candidate_real, '/');
		$workspace_real = rtrim($workspace_real, '/');
		foreach ( new \DirectoryIterator($workspace_real) as $entry ) {
			if ( $entry->isDot() || ! $entry->isDir() ) {
				continue;
			}
			$path = $entry->getPathname();
			$git  = $path . '/.git';
			// An un-suffixed checkout with a .git directory is authoritative.
			if ( is_dir($git) && $candidate_real === rtrim((string) realpath($path), '/') ) {
				return array( 'code' => 'primary_common_dir_protected', 'message' => sprintf('Refusing to delete authoritative primary %s.', $path), 'common_dir' => $git );
			}
			if ( ! is_file($git) ) {
				continue;
			}
			$marker = trim((string) file_get_contents($git));
			if ( ! str_starts_with($marker, 'gitdir:') ) {
				continue;
			}
			$gitdir = trim(substr($marker, strlen('gitdir:')));
			if ( '' === $gitdir || ! str_starts_with($gitdir, '/') ) {
				continue;
			}
			// A linked worktree gitdir is <common-dir>/worktrees/<id>.
			$common_dir = dirname(dirname($gitdir));
			if ( self::path_contains($candidate_real, $common_dir) ) {
				return array(
					'code'       => 'primary_common_dir_protected',
					'message'    => sprintf('Refusing to delete %s because it owns the common Git directory used by linked worktree %s.', $candidate_real, $path),
					'common_dir' => $common_dir,
					'worktrees'  => array( $path ),
				);
			}
		}

		return null;
	}

	/**
	 * Parse `git worktree list --porcelain` into registrations Git can prune.
	 *
	 * @return array<int,array{path:string,reason:string}>
	 */
	public static function prunable_registrations_from_porcelain( string $porcelain ): array {
		$registrations = array();
		$current_path  = '';
		$lines         = preg_split( '/\r?\n/', $porcelain );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( str_starts_with( $line, 'worktree ' ) ) {
				$current_path = trim( substr( $line, strlen( 'worktree ' ) ) );
				continue;
			}
			if ( ! str_starts_with( $line, 'prunable ' ) || '' === $current_path ) {
				continue;
			}
			$registrations[] = array(
				'path'   => $current_path,
				'reason' => trim( substr( $line, strlen( 'prunable ' ) ) ),
			);
		}

		return $registrations;
	}

	/** Git args that preview or immediately drop proven-stale worktree registrations. */
	public static function prune_git_args( bool $dry_run ): string {
		return $dry_run
			? 'worktree prune --dry-run -v --expire=now'
			: 'worktree prune -v --expire=now';
	}

	private static function path_contains( string $container, string $path ): bool {
		$container = rtrim($container, '/') . '/';
		$path      = rtrim($path, '/') . '/';
		return str_starts_with($path, $container) || $container === $path;
	}
}
