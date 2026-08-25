<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');
require_once dirname(__DIR__) . '/inc/Workspace/TaskUrl.php';
require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';

use DataMachineCode\Workspace\WorktreeContextInjector;

function task_url_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$source    = 'HTTPS://GitHub.COM/Extra-Chill/Data-Machine-Code/issues/1166';
$canonical = 'https://github.com/Extra-Chill/Data-Machine-Code/issues/1166';
task_url_assert($canonical === WorktreeContextInjector::canonical_task_url(" \t{$source}/?source=homeboy#candidate \n"), 'Task URL canonicalization must trim whitespace, query, fragment, and trailing slash; lowercase scheme and host; and preserve path casing.');
task_url_assert(null === WorktreeContextInjector::canonical_task_url('not a URL'), 'Invalid task URLs must not become task identities.');
$metadata = WorktreeContextInjector::resolve_task_metadata(array( 'task_url' => " {$canonical}/?source=wpca#lookup " ));
task_url_assert($canonical === ( $metadata['task_url'] ?? null ), 'Stored task metadata must use the canonical task URL.');
$persisted = WorktreeContextInjector::resolve_task_metadata(array( 'task_url' => ' HTTPS://user:pass@GitHub.COM:443/Extra-Chill/Data-Machine-Code/issues/1166/?source=dmc ' ));
$requested = WorktreeContextInjector::canonical_task_url('https://user:pass@github.com/Extra-Chill/Data-Machine-Code/issues/1166#homeboy');
task_url_assert($requested === ( $persisted['task_url'] ?? null ) && 'https://user:pass@github.com/Extra-Chill/Data-Machine-Code/issues/1166' === $requested, 'Persisted DMC metadata and a Homeboy-style request must agree after default-port normalization while preserving userinfo and path casing.');
task_url_assert('https://user:pass@github.com:8443/Extra-Chill/Data-Machine-Code/issues/1166' === WorktreeContextInjector::canonical_task_url('https://user:pass@GitHub.COM:8443/Extra-Chill/Data-Machine-Code/issues/1166'), 'Non-default task URL ports must remain part of task identity.');

echo "worktree-task-url-canonicalization: ok\n";
