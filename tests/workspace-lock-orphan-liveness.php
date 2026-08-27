<?php
/**
 * Standalone coverage for filesystem lock prunability.
 *
 * An unheld lock file is an orphan regardless of how recently it was written.
 * Liveness must decide prunability; age only guards the window between creating
 * the file and taking the flock (#1273).
 */

declare(strict_types=1);

const ABSPATH = __DIR__ . '/fixtures/';

final class WP_Error {
	public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
	if ('datamachine_code_cleanup_lock_retention_policy' === $hook) {
		return $GLOBALS['lock_retention_policy'] ?? $value;
	}
	return $value;
}

require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceMutationLock.php';

use DataMachineCode\Workspace\WorkspaceMutationLock;

function orphan_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

/** @return array<string,mixed>|null */
function find_lock(array $status, string $lock_key): ?array {
	foreach ((array) ($status['filesystem']['locks'] ?? array()) as $lock) {
		if (is_array($lock) && $lock_key === ($lock['lock_key'] ?? '')) {
			return $lock;
		}
	}
	return null;
}

$workspace = sys_get_temp_dir() . '/dmc-orphan-liveness-' . bin2hex(random_bytes(6));
mkdir($workspace . '/.locks', 0777, true);

// A 5s grace keeps the test fast while still exercising a real boundary.
$GLOBALS['lock_retention_policy'] = array(
	'filesystem_stale_after_seconds' => 86400,
	'orphan_grace_seconds'           => 5,
);

try {
	// 1. An orphan well past the creation grace, but far inside the 24h
	//    staleness window. This is the reported regression: zero holders, yet
	//    previously reported `recent` and refused pruning for a full day.
	$orphan = $workspace . '/.locks/worktree-orphan.lock';
	file_put_contents($orphan, '');
	touch($orphan, time() - 3600);

	// 2. A lock a live process still holds. Must never be prunable.
	$held = $workspace . '/.locks/worktree-held.lock';
	file_put_contents($held, '');
	touch($held, time() - 3600);
	$held_handle = fopen($held, 'c');
	orphan_assert(false !== $held_handle, 'fixture: could not open held lock');
	orphan_assert(flock($held_handle, LOCK_EX | LOCK_NB), 'fixture: could not hold flock');

	// 3. A freshly created orphan inside the grace window, standing in for the
	//    create-then-flock race. Must be protected.
	$fresh = $workspace . '/.locks/worktree-fresh.lock';
	file_put_contents($fresh, '');

	$status = WorkspaceMutationLock::status($workspace);

	$orphan_row = find_lock($status, 'worktree-orphan');
	orphan_assert(null !== $orphan_row, 'orphan lock must be reported');
	orphan_assert('stale' === $orphan_row['state'], 'An unheld lock past the creation grace must be stale, not recent. Got: ' . var_export($orphan_row['state'], true));
	orphan_assert(true === $orphan_row['safe_to_prune'], 'An unheld lock past the creation grace must be prunable.');
	orphan_assert('unlocked_no_live_holder' === $orphan_row['reason'], 'Prunability must be justified by absent liveness, not age.');

	$held_row = find_lock($status, 'worktree-held');
	orphan_assert(null !== $held_row, 'held lock must be reported');
	orphan_assert('active' === $held_row['state'], 'A lock with a live flock must be active.');
	orphan_assert(false === $held_row['safe_to_prune'], 'A lock with a live flock must never be prunable.');

	$fresh_row = find_lock($status, 'worktree-fresh');
	orphan_assert(null !== $fresh_row, 'fresh lock must be reported');
	orphan_assert('recent' === $fresh_row['state'], 'A lock inside the creation grace must stay recent.');
	orphan_assert(false === $fresh_row['safe_to_prune'], 'A lock inside the creation grace must not be prunable.');

	// Reported counts must be actionable rather than always-zero.
	orphan_assert(1 === (int) ($status['filesystem']['stale'] ?? 0), 'Exactly one lock must be reported stale.');
	orphan_assert(1 === (int) ($status['filesystem']['active'] ?? 0), 'Exactly one lock must be reported active.');

	// Dry-run must plan the orphan and protect both the held and fresh locks.
	$preview = WorkspaceMutationLock::prune_stale($workspace, true);
	$planned = (array) ($preview['filesystem']['removed'] ?? array());
	orphan_assert(1 === count($planned), 'Dry run must plan exactly the orphan. Planned: ' . count($planned));
	orphan_assert($orphan === $planned[0], 'Dry run must plan the orphaned lock path.');
	orphan_assert(file_exists($orphan), 'Dry run must not delete anything.');

	// Apply must remove only the orphan.
	$applied = WorkspaceMutationLock::prune_stale($workspace, false);
	orphan_assert(1 === count((array) ($applied['filesystem']['removed'] ?? array())), 'Apply must remove exactly one lock.');
	orphan_assert(! file_exists($orphan), 'Apply must remove the orphaned lock.');
	orphan_assert(file_exists($held), 'Apply must preserve a lock held by a live process.');
	orphan_assert(file_exists($fresh), 'Apply must preserve a lock inside the creation grace.');

	flock($held_handle, LOCK_UN);
	fclose($held_handle);

	echo "workspace-lock-orphan-liveness ok\n";
} finally {
	array_map('unlink', (array) glob($workspace . '/.locks/*.lock'));
	@rmdir($workspace . '/.locks');
	@rmdir($workspace);
}
