<?php

declare(strict_types=1);

$root   = dirname(__DIR__);
$source = file_get_contents($root . '/inc/Workspace/WorkspaceMetadataReconciliation.php');

if ( false === $source ) {
	throw new RuntimeException('Unable to read WorkspaceMetadataReconciliation.php.');
}

function worktree_metadata_reconciliation_finalizer_contract_assert_contains( string $needle, string $haystack, string $message ): void {
	if ( ! str_contains($haystack, $needle) ) {
		throw new RuntimeException($message . ' Missing: ' . $needle);
	}
}

function worktree_metadata_reconciliation_finalizer_contract_assert_before( string $first, string $second, string $haystack, string $message ): void {
	$first_pos  = strpos($haystack, $first);
	$second_pos = strpos($haystack, $second);
	if ( false === $first_pos || false === $second_pos || $first_pos >= $second_pos ) {
		throw new RuntimeException($message);
	}
}

worktree_metadata_reconciliation_finalizer_contract_assert_contains('$resolved_wt       = array_merge(', $source, 'Reconciliation must pass recovered identity into finalizer detection.');
worktree_metadata_reconciliation_finalizer_contract_assert_contains('$finalizer_signal = $this->detect_worktree_lifecycle_finalizer_signal($resolved_wt, $metadata, $github_cache, $fetched);', $source, 'Finalizer detection must use the resolved worktree row.');
worktree_metadata_reconciliation_finalizer_contract_assert_contains("! empty(\$identity['detached_branch']) && ! \$this->has_stored_lifecycle_finalizer_context(\$metadata)", $source, 'PR-backed detached rows must be allowed to reach finalizer detection.');
worktree_metadata_reconciliation_finalizer_contract_assert_before('$pr_signal    = $this->detect_stored_pr_merged_signal($metadata, $github_cache);', "fetch --prune --quiet origin", $source, 'Stored PR finalizer checks must run before local fetch.');

echo "worktree-metadata-reconciliation-finalizer-contract: ok\n";
