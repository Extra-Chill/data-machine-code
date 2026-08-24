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

$canonical = 'https://GitHub.com/Extra-Chill/data-machine-code/issues/1166';
task_url_assert($canonical === WorktreeContextInjector::canonical_task_url(" \t{$canonical}/?source=homeboy#candidate \n"), 'Task URL canonicalization must trim whitespace, query, fragment, and trailing slash while preserving casing.');
task_url_assert(null === WorktreeContextInjector::canonical_task_url('not a URL'), 'Invalid task URLs must not become task identities.');
$metadata = WorktreeContextInjector::resolve_task_metadata(array( 'task_url' => " {$canonical}/?source=wpca#lookup " ));
task_url_assert($canonical === ( $metadata['task_url'] ?? null ), 'Stored task metadata must use the canonical task URL.');

echo "worktree-task-url-canonicalization: ok\n";
