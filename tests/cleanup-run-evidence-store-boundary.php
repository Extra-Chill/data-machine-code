<?php

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	require_once dirname(__DIR__) . '/inc/Cleanup/CleanupRunEvidenceStoreInterface.php';
	require_once dirname(__DIR__) . '/inc/Cleanup/CompositeCleanupRunEvidenceStore.php';

	use DataMachineCode\Cleanup\CleanupRunEvidenceStoreInterface;
	use DataMachineCode\Cleanup\CompositeCleanupRunEvidenceStore;

	final class FakeCleanupRunEvidenceStore implements CleanupRunEvidenceStoreInterface {
		/** @var array<int,array<string,mixed>> */
		public array $calls = array();

		public function __construct(
			private string $source
		) {}

		public function read( string $run_id, bool $include_evidence = false, bool $include_details = false ): array|\WP_Error {
			$this->calls[] = compact('run_id', 'include_evidence', 'include_details');

			return array(
				'source'           => $this->source,
				'run_id'           => $run_id,
				'include_evidence' => $include_evidence,
				'include_details'  => $include_details,
			);
		}
	}

	function cleanup_boundary_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	$workspace = new FakeCleanupRunEvidenceStore('workspace');
	$job       = new FakeCleanupRunEvidenceStore('job');
	$store     = new CompositeCleanupRunEvidenceStore($workspace, $job);

	$result = $store->read('cleanup-run-20260504120000-abc123', true, true);
	cleanup_boundary_assert_same('workspace', $result['source'] ?? null, 'Timestamp cleanup run IDs should route to the DMC workspace evidence store.');
	cleanup_boundary_assert_same(true, $workspace->calls[0]['include_evidence'] ?? null, 'Workspace evidence flag should pass through.');

	$result = $store->read('cleanup-run-123', false, true);
	cleanup_boundary_assert_same('job', $result['source'] ?? null, 'Job cleanup run IDs should route to the Data Machine job adapter.');
	cleanup_boundary_assert_same(true, $job->calls[0]['include_details'] ?? null, 'Job details flag should pass through.');

	$result = $store->read('123');
	cleanup_boundary_assert_same('job', $result['source'] ?? null, 'Numeric cleanup run IDs should route to the Data Machine job adapter.');

	echo "cleanup run evidence store boundary test passed.\n";
}
