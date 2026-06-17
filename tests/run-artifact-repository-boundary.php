<?php

declare(strict_types=1);

namespace DataMachine\Core\Steps {
	trait HandlerRegistrationTrait {
		public static function registerHandler( mixed ...$args ): void {}
	}
}

namespace DataMachine\Core\Steps\Publish\Handlers {
	class PublishHandler {
		public function __construct( string $slug ) {}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	if ( ! function_exists('sanitize_key') ) {
		function sanitize_key( string $key ): string {
			return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $key) ?? '');
		}
	}

	if ( ! function_exists('sanitize_title') ) {
		function sanitize_title( string $title ): string {
			$title = strtolower(trim($title));
			$title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
			return trim($title, '-');
		}
	}

	require_once dirname(__DIR__) . '/inc/RunArtifacts/RunArtifactRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Support/RunArtifactBundleFileWriter.php';
	require_once dirname(__DIR__) . '/inc/Handlers/GitHub/GitHubPullRequestPublish.php';

	use DataMachineCode\Handlers\GitHub\GitHubPullRequestPublish;
	use DataMachineCode\RunArtifacts\RunArtifactRepositoryInterface;

	final class FakeRunArtifactRepository implements RunArtifactRepositoryInterface {
		public int $artifact_job_id = 0;
		public int $policy_job_id   = 0;

		public function artifacts_for_job( int $job_id ): array {
			$this->artifact_job_id = $job_id;

			return array(
				'daily_memory_artifacts' => array(
					array(
						'type'                 => 'daily_memory',
						'agent_slug'           => 'boundary agent',
						'bundle_relative_path' => 'memory.md',
						'content'              => '# Boundary evidence',
					),
				),
			);
		}

		public function egress_policy_for_job( int $job_id ): array {
			$this->policy_job_id = $job_id;

			return array(
				'daily_memory' => array(
					'egress' => array( 'bundle-file' ),
				),
			);
		}
	}

	function boundary_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true)));
		}
	}

	$repository = new FakeRunArtifactRepository();
	$handler    = new GitHubPullRequestPublish($repository);
	$method     = new ReflectionMethod($handler, 'bundleFileArtifactsForPublish');

	$files = $method->invoke($handler, array( 'job_id' => 42 ), array( 'bundle_root' => 'bundles/{agent_slug}' ));

	boundary_assert_same(42, $repository->artifact_job_id, 'Handler should read artifacts through the injected repository.');
	boundary_assert_same(42, $repository->policy_job_id, 'Handler should read egress policy through the injected repository.');
	boundary_assert_same('bundles/boundary-agent/memory.md', $files[0]['file_path'] ?? null, 'Injected repository artifacts should still become bundle-file writes.');
	boundary_assert_same('# Boundary evidence', $files[0]['content'] ?? null, 'Artifact content should be preserved.');

	echo "run artifact repository boundary test passed.\n";
}
