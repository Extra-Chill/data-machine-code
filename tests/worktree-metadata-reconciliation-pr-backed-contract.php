<?php

declare(strict_types=1);

$root   = dirname(__DIR__);
$source = file_get_contents($root . '/inc/Workspace/WorkspaceMetadataReconciliation.php');

if ( false === $source ) {
	throw new RuntimeException('Unable to read WorkspaceMetadataReconciliation.php.');
}

function worktree_metadata_reconciliation_pr_backed_contract_assert_contains( string $needle, string $haystack, string $message ): void {
	if ( ! str_contains($haystack, $needle) ) {
		throw new RuntimeException($message . ' Missing: ' . $needle);
	}
}

function worktree_metadata_reconciliation_pr_backed_contract_assert_before( string $first, string $second, string $haystack, string $message ): void {
	$first_pos  = strpos($haystack, $first);
	$second_pos = strpos($haystack, $second);
	if ( false === $first_pos || false === $second_pos || $first_pos >= $second_pos ) {
		throw new RuntimeException($message);
	}
}

worktree_metadata_reconciliation_pr_backed_contract_assert_contains('private function has_stored_lifecycle_finalizer_context( array $metadata ): bool', $source, 'Reconciliation must identify PR/finalizer-backed metadata.');
foreach ( array( 'pr_url', 'pr_number', 'pr_repo', 'origin_task', 'finalized_state', 'cleanup_eligible_at' ) as $field ) {
	worktree_metadata_reconciliation_pr_backed_contract_assert_contains("'" . $field . "'", $source, 'Finalizer context must include ' . $field . '.');
}
worktree_metadata_reconciliation_pr_backed_contract_assert_contains("'reason_code' => 'insufficient_finalizer_signal'", $source, 'Ambiguous PR-backed rows must be skipped with a specific reason.');
worktree_metadata_reconciliation_pr_backed_contract_assert_before("'reason_code' => 'insufficient_finalizer_signal'", '$classification = $this->build_worktree_metadata_backfill_classification($metadata, $handle, $repo, $branch, $path);', $source, 'Ambiguous PR-backed rows must not fall through to active metadata backfill.');

echo "worktree-metadata-reconciliation-pr-backed-contract: ok\n";
