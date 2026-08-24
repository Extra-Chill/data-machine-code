<?php

declare(strict_types=1);

function worktree_lifecycle_fixture_has_symlink_component( string $path ): bool {
	if ( ! str_starts_with($path, '/') ) {
		return true;
	}

	$current = '';
	foreach ( explode('/', ltrim($path, '/')) as $component ) {
		$current .= '/' . $component;
		if ( is_link($current) ) {
			return true;
		}
	}

	return false;
}

function worktree_lifecycle_fixture_is_system_temp_path( string $path ): bool {
	$temp_root = realpath(sys_get_temp_dir());
	return false !== $temp_root && str_starts_with($path, rtrim($temp_root, '/') . '/');
}

function worktree_lifecycle_fixture_is_inspectable_repository( string $repository ): bool {
	$git_path = $repository . '/.git';
	if ( is_dir($git_path) ) {
		return true;
	}
	if ( ! is_file($git_path) || is_link($git_path) ) {
		return false;
	}

	$marker = file_get_contents($git_path);
	if ( false === $marker || 1 !== preg_match('/^gitdir: (.+)\\n?$/D', $marker, $matches) ) {
		return false;
	}
	$gitdir = $matches[1];
	if ( ! str_starts_with($gitdir, '/') ) {
		$gitdir = dirname($repository) . '/' . $gitdir;
	}

	return false !== realpath($gitdir) && is_dir($gitdir);
}

/** @return array<int,string> */
function worktree_lifecycle_fixture_registered_worktrees( string $fixture_root ): array {
	$current_directory = realpath(getcwd() ?: '');
	if ( false === $current_directory ) {
		throw new RuntimeException('worktree-add-lifecycle could not resolve the current directory for registry inspection.');
	}

	$repositories = array( $current_directory );
	$entries      = scandir($fixture_root);
	if ( false === $entries ) {
		throw new RuntimeException('worktree-add-lifecycle could not inspect the fixture workspace registry.');
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$repository = $fixture_root . '/' . $entry;
		if ( is_dir($repository) && worktree_lifecycle_fixture_is_inspectable_repository($repository) ) {
			$repositories[] = $repository;
		}
	}

	$registered = array();
	foreach ( array_unique($repositories) as $repository ) {
		$output = array();
		$status = 0;
		exec('git -C ' . escapeshellarg($repository) . ' worktree list --porcelain 2>&1', $output, $status);
		if ( 0 !== $status ) {
			throw new RuntimeException('worktree-add-lifecycle could not inspect an inspectable managed worktree registry.');
		}
		foreach ( $output as $line ) {
			if ( ! str_starts_with($line, 'worktree ') ) {
				continue;
			}
			$path = realpath(substr($line, strlen('worktree ')));
			if ( false === $path ) {
				throw new RuntimeException('worktree-add-lifecycle found an unresolved managed worktree registry path.');
			}
			$registered[] = $path;
		}
	}

	return array_values(array_unique($registered));
}

/** @param array{root:string,identity:string,sentinel:string,sentinel_identity:string,token:string} $fixture */
function worktree_lifecycle_assert_fixture_cleanup_safe( string $path, array $fixture, ?callable $registry_inspector = null ): void {
	$identity          = $fixture['identity'];
	$resolved_path     = realpath($path);
	$resolved_root     = realpath($fixture['root']);
	$resolved_sentinel = realpath($fixture['sentinel']);
	$current_directory = realpath(getcwd() ?: '');
	if (
		$path !== $identity ||
		false === $resolved_path ||
		$resolved_path !== $identity ||
		false === $resolved_root ||
		$resolved_root !== $identity ||
		worktree_lifecycle_fixture_has_symlink_component($fixture['root']) ||
		! worktree_lifecycle_fixture_is_system_temp_path($identity) ||
		false === $current_directory ||
		$identity === $current_directory ||
		str_starts_with($current_directory, $identity . '/') ||
		! is_file($fixture['sentinel']) ||
		is_link($fixture['sentinel']) ||
		false === $resolved_sentinel ||
		$resolved_sentinel !== $fixture['sentinel_identity'] ||
		! str_starts_with($resolved_sentinel, $identity . '/') ||
		! hash_equals($fixture['token'] . "\n", (string) file_get_contents($fixture['sentinel']))
	) {
		throw new RuntimeException('worktree-add-lifecycle refuses fixture cleanup without exact owned-temp sentinel containment.');
	}

	$registered = ( $registry_inspector ?? 'worktree_lifecycle_fixture_registered_worktrees' )($identity);
	if ( ! is_array($registered) ) {
		throw new RuntimeException('worktree-add-lifecycle could not inspect managed worktree registries.');
	}
	foreach ( $registered as $registered_path ) {
		if ( ! is_string($registered_path) || $identity === $registered_path ) {
			throw new RuntimeException('worktree-add-lifecycle refuses fixture cleanup targeting a registered managed worktree.');
		}
	}
}

function worktree_lifecycle_dispose_incomplete_fixture( string $root, string $identity, string $sentinel ): void {
	if (
		$root !== $identity ||
		false === realpath($root) ||
		realpath($root) !== $identity ||
		worktree_lifecycle_fixture_has_symlink_component($root) ||
		! worktree_lifecycle_fixture_is_system_temp_path($identity)
	) {
		return;
	}
	$entries = scandir($identity);
	if ( false === $entries ) {
		return;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		if ( $identity . '/' . $entry !== $sentinel || ! is_file($sentinel) || is_link($sentinel) ) {
			return;
		}
		if ( ! unlink($sentinel) ) {
			return;
		}
	}
	rmdir($identity);
}
