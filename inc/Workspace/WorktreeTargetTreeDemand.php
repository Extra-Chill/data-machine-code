<?php
/**
 * Conservative target-tree bootstrap demand shared by WordPress and standalone planning.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class WorktreeTargetTreeDemand {

	public const DEFAULTS = array(
		'git_bytes'            => 16777216,
		'git_inodes'           => 256,
		'submodule_bytes'      => 1073741824,
		'submodule_inodes'     => 250000,
		'package_root_bytes'   => 2147483648,
		'package_root_inodes'  => 1000000,
		'composer_root_bytes'  => 1073741824,
		'composer_root_inodes' => 250000,
	);

	public const BLOBLESS_TRACKED_ENTRY_BYTES = 65536;

	/**
	 * @return array{tracked_entries:int,tracked_bytes:int,detected:array<string,mixed>}
	 */
	public static function parse( string $output ): array {
		$tracked_entries = 0;
		$tracked_bytes   = 0;
		$package_roots   = array();
		$composer_roots  = array();
		$submodule_roots = array();
		$lockfiles       = array( 'pnpm-lock.yaml', 'bun.lockb', 'bun.lock', 'yarn.lock', 'package-lock.json', 'npm-shrinkwrap.json' );

		foreach ( explode("\0", $output) as $record ) {
			if ( '' === $record || 1 !== preg_match('/^(\d{6})\s+(blob|tree|commit)\s+[0-9a-f]+(?:\s+(-|\d+))?\t(.*)$/sD', $record, $matches) ) {
				continue;
			}
			++$tracked_entries;
			$path = $matches[4];
			if ( 'blob' === $matches[2] && is_numeric($matches[3]) ) {
				$tracked_bytes += max(0, (int) $matches[3]);
			}
			if ( '160000' === $matches[1] ) {
				$submodule_roots[ $path ] = array( 'relative' => $path );
			}
			$dirname = dirname($path);
			if ( '.' !== $dirname && str_contains($dirname, '/') ) {
				continue;
			}
			$basename = basename($path);
			if ( in_array($basename, $lockfiles, true) ) {
				$relative = '.' === $dirname ? '.' : $dirname;
				$manager  = self::manager_for_lockfile($basename);
				if ( ! isset($package_roots[ $relative ]) || self::package_manager_priority($manager) < self::package_manager_priority($package_roots[ $relative ]['manager']) ) {
					$package_roots[ $relative ] = array(
						'relative' => $relative,
						'manager'  => $manager,
					);
				}
			}
			if ( 'composer.lock' === $basename ) {
				$relative                    = '.' === $dirname ? '.' : $dirname;
				$composer_roots[ $relative ] = array( 'relative' => $relative );
			}
		}

		return array(
			'tracked_entries' => $tracked_entries,
			'tracked_bytes'   => $tracked_bytes,
			'detected'        => array(
				'submodules'            => array() !== $submodule_roots,
				'submodule_roots'       => array_values($submodule_roots),
				'packages'              => $package_roots['.']['manager'] ?? null,
				'composer'              => isset($composer_roots['.']),
				'package_roots'         => array_values($package_roots),
				'skipped_package_roots' => array(),
				'composer_roots'        => array_values($composer_roots),
			),
		);
	}

	/**
	 * @param array{tracked_entries:int,tracked_bytes:int,detected:array<string,mixed>} $tree_plan
	 * @param array<string,int> $defaults
	 * @return array<string,mixed>
	 */
	public static function assemble(
		array $tree_plan,
		string $target_ref,
		string $commit,
		bool $bootstrap,
		bool $blobless_partial_clone,
		int $blobless_entry_bytes,
		array $defaults
	): array {
		$tracked_bytes = $blobless_partial_clone
			? $tree_plan['tracked_entries'] * $blobless_entry_bytes
			: $tree_plan['tracked_bytes'];
		$counts        = array(
			'tracked_entries' => $tree_plan['tracked_entries'],
			'submodules'      => $bootstrap ? count($tree_plan['detected']['submodule_roots']) : 0,
			'package_roots'   => $bootstrap ? count($tree_plan['detected']['package_roots']) : 0,
			'composer_roots'  => $bootstrap ? count($tree_plan['detected']['composer_roots']) : 0,
		);

		return array(
			'bytes'                   => $tracked_bytes + $defaults['git_bytes'] + ( $counts['submodules'] * $defaults['submodule_bytes'] ) + ( $counts['package_roots'] * $defaults['package_root_bytes'] ) + ( $counts['composer_roots'] * $defaults['composer_root_bytes'] ),
			'inodes'                  => $counts['tracked_entries'] + $defaults['git_inodes'] + ( $counts['submodules'] * $defaults['submodule_inodes'] ) + ( $counts['package_roots'] * $defaults['package_root_inodes'] ) + ( $counts['composer_roots'] * $defaults['composer_root_inodes'] ),
			'source'                  => 'target_git_tree_conservative',
			'target_ref'              => $target_ref,
			'target_commit'           => $commit,
			'tracked_bytes'           => $tracked_bytes,
			'tracked_bytes_source'    => $blobless_partial_clone ? 'conservative_blobless_entry_estimate' : 'exact_git_blob_sizes',
			'tracked_bytes_per_entry' => $blobless_partial_clone ? $blobless_entry_bytes : null,
			'git_safety_margin'       => array(
				'bytes'  => $defaults['git_bytes'],
				'inodes' => $defaults['git_inodes'],
			),
			'bootstrap'               => $bootstrap,
			'detected'                => $tree_plan['detected'],
			'counts'                  => $counts,
			'allowances'              => $defaults,
			'lockfile_identities'     => array(
				'git_tree' => $commit,
			),
			'fallback_semantics'      => $blobless_partial_clone
				? 'tracked target entries are measured from Git metadata; blobless partial clones reserve a conservative 64 KiB per tracked entry because exact blob sizes are unavailable; dependency installs use conservative allowances'
				: 'tracked target entries and bytes are measured from Git; dependency installs use conservative allowances',
		);
	}

	private static function manager_for_lockfile( string $lockfile ): string {
		return match ( $lockfile ) {
			'pnpm-lock.yaml' => 'pnpm',
			'bun.lockb', 'bun.lock' => 'bun',
			'yarn.lock' => 'yarn',
			default => 'npm',
		};
	}

	private static function package_manager_priority( string $manager ): int {
		return match ( $manager ) {
			'pnpm' => 0,
			'bun'  => 1,
			'yarn' => 2,
			'npm'  => 3,
			default => PHP_INT_MAX,
		};
	}
}
