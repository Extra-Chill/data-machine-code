<?php

declare(strict_types=1);

function standalone_inventory_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

/** @return array{status:int,stdout:string,stderr:string} */
function standalone_inventory_run( array $command ): array {
	$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
	standalone_inventory_assert(is_resource($process), 'Could not start fixture command.');
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return array( 'status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr );
}

function standalone_inventory_git( string $path, array $arguments ): void {
	$result = standalone_inventory_run(array_merge(array( 'git', '-C', $path ), $arguments));
	standalone_inventory_assert(0 === $result['status'], 'Git fixture command failed: ' . $result['stderr']);
}

function standalone_inventory_remove( string $path ): void {
	if ( ! is_dir($path) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

$root       = sys_get_temp_dir() . '/dmc-standalone-inventory-' . bin2hex(random_bytes(6));
$alpha      = $root . '/alpha';
$worktree   = $root . '/alpha@fix-one';
$beta       = $root . '/beta';
$unrelated  = $root . '/unrelated';
$script     = dirname(__DIR__) . '/bin/dmc-worktree-provider';

mkdir($root, 0777, true);
try {
	foreach ( array( $alpha, $beta ) as $primary ) {
		mkdir($primary);
		standalone_inventory_git($primary, array( 'init', '-b', 'main' ));
		standalone_inventory_git($primary, array( 'config', 'user.name', 'Fixture' ));
		standalone_inventory_git($primary, array( 'config', 'user.email', 'fixture@example.test' ));
		file_put_contents($primary . '/README.md', basename($primary) . "\n");
		standalone_inventory_git($primary, array( 'add', 'README.md' ));
		standalone_inventory_git($primary, array( 'commit', '-m', 'fixture' ));
	}
	standalone_inventory_git($alpha, array( 'worktree', 'add', '-b', 'fix/one', $worktree ));
	mkdir($unrelated);
	file_put_contents($unrelated . '/sentinel', "must remain untouched\n");
	$head_before = trim(standalone_inventory_run(array( 'git', '-C', $worktree, 'rev-parse', 'HEAD' ))['stdout']);

	$page_one = standalone_inventory_run(array( PHP_BINARY, $script, 'inventory', $root, '--limit=2', '--format=json' ));
	standalone_inventory_assert(0 === $page_one['status'], 'Standalone inventory failed without WordPress: ' . $page_one['stderr']);
	$first = json_decode($page_one['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_inventory_assert('datamachine-code/standalone-workspace-inventory/v1' === ($first['schema'] ?? null), 'Inventory schema changed.');
	standalone_inventory_assert(array( 'alpha', 'alpha@fix-one' ) === array_column($first['items'] ?? array(), 'handle'), 'Inventory page was not deterministic.');
	standalone_inventory_assert(true === ($first['page']['has_more'] ?? null) && is_string($first['page']['next_cursor'] ?? null), 'Inventory omitted its continuation cursor.');
	standalone_inventory_assert(2 === ($first['scan']['git_probes'] ?? null), 'Inventory probed outside the requested page.');
	standalone_inventory_assert('unavailable' === ($first['lifecycle']['status'] ?? null) && 'wordpress_database' === ($first['lifecycle']['source'] ?? null), 'Inventory confused observations with DB lifecycle state.');
	standalone_inventory_assert(array( 'wordpress_loaded' => false, 'database_accessed' => false, 'network_accessed' => false, 'mutated' => false ) === ($first['execution'] ?? null), 'Inventory execution contract is not standalone and read-only.');

	$page_two = standalone_inventory_run(array( PHP_BINARY, $script, 'inventory', $root, '--limit=2', '--cursor=' . $first['page']['next_cursor'], '--format=json' ));
	$second = json_decode($page_two['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_inventory_assert(array( 'beta' ) === array_column($second['items'] ?? array(), 'handle') && false === ($second['page']['has_more'] ?? null), 'Inventory pagination duplicated or skipped a handle.');

	$show = standalone_inventory_run(array( PHP_BINARY, $script, 'show', $root, 'alpha@fix-one', '--format=json' ));
	standalone_inventory_assert(0 === $show['status'], 'Standalone targeted show failed: ' . $show['stderr']);
	$shown = json_decode($show['stdout'], true, 512, JSON_THROW_ON_ERROR);
	standalone_inventory_assert('datamachine-code/standalone-workspace-show/v1' === ($shown['schema'] ?? null), 'Show schema changed.');
	standalone_inventory_assert('alpha@fix-one' === ($shown['item']['handle'] ?? null) && 'worktree' === ($shown['item']['kind'] ?? null), 'Show returned the wrong identity.');
	standalone_inventory_assert('fix/one' === ($shown['item']['observation']['branch'] ?? null) && $head_before === ($shown['item']['observation']['head'] ?? null), 'Show omitted local Git facts.');
	standalone_inventory_assert(array( 'scope' => 'exact_handle', 'workspace_scanned' => false, 'git_probes' => 1 ) === ($shown['lookup'] ?? null), 'Show did not guarantee exact-target lookup.');
	standalone_inventory_assert(str_contains((string) ($shown['lifecycle']['recovery']['command'] ?? ''), 'workspace show'), 'Show omitted WP-backed lifecycle recovery.');
	standalone_inventory_assert(file_exists($unrelated . '/sentinel'), 'Read-only standalone inspection mutated an unrelated path.');
	$head_after = trim(standalone_inventory_run(array( 'git', '-C', $worktree, 'rev-parse', 'HEAD' ))['stdout']);
	standalone_inventory_assert($head_before === $head_after, 'Standalone inspection mutated Git state.');

	$text = standalone_inventory_run(array( PHP_BINARY, $script, 'inventory', $root, '--limit=1' ));
	standalone_inventory_assert(str_contains($text['stdout'], 'Showing 1 item(s). Continue with --cursor=') && str_contains($text['stdout'], 'Database lifecycle metadata unavailable.'), 'Concise inventory text omitted bounds or recovery guidance.');

	$invalid = standalone_inventory_run(array( PHP_BINARY, $script, 'inventory', $root, '--limit=201', '--format=json' ));
	standalone_inventory_assert(1 === $invalid['status'] && 'invalid_inventory_limit' === (json_decode($invalid['stdout'], true, 512, JSON_THROW_ON_ERROR)['code'] ?? null), 'Inventory accepted output beyond its hard limit.');
} finally {
	standalone_inventory_remove($root);
}

fwrite(STDOUT, "standalone-workspace-inventory: ok\n");
