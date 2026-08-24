<?php
/**
 * A clone runner can report success without materializing its target.
 */

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}
	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	require_once dirname(__DIR__) . '/inc/Support/CommandSpec.php';
	require_once dirname(__DIR__) . '/inc/Support/GitRunner.php';
	require_once dirname(__DIR__) . '/inc/Workspace/GitCheckout.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceRepositoryLifecycle.php';

	use DataMachineCode\Support\CommandSpec;
	use DataMachineCode\Workspace\WorkspaceRepositoryLifecycle;

	final class FalseSuccessCloneHarness {
		use WorkspaceRepositoryLifecycle;

		public function __construct( private string $workspace_path, private string $target_state, private ?string $remote = null ) {}
		private function require_workspace_visible(): ?WP_Error { return null; }
		private function derive_repo_name( string $url ): ?string { return 'expected-repository'; }
		private function sanitize_name( string $name ): string { return $name; }
		private function ensure_exists(): true { return true; }
		private function find_primary_by_remote( string $url, string $name ): ?array { return null; }
		private function normalize_git_remote_url( string $url ): string { return rtrim(strtolower($url), '/'); }
		private function git_get_remote( string $path ): ?string { return $this->remote; }
		protected function run_clone_command( CommandSpec $command, ?callable $progress_callback, float $started_at ): array|WP_Error {
			$target = $this->workspace_path . '/expected-repository';
			if ( 'incomplete' === $this->target_state ) {
				mkdir($target, 0700, true);
			}
			if ( 'remote_mismatch' === $this->target_state ) {
				mkdir($target . '/.git', 0700, true);
			}
			return array( 'success' => true, 'output' => '' );
		}
		private function ensure_default_branch_tracking( string $repo_path ): void {}
		private function emit_workspace_changed( string $operation, string $repo, string $handle, string $path ): void {}
	}

	function clone_postcondition_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	$workspace = sys_get_temp_dir() . '/dmc-clone-postcondition-' . bin2hex(random_bytes(4));
	mkdir($workspace, 0700, true);
	try {
		$repository = 'https://example.test/expected-repository.git';
		$target     = $workspace . '/expected-repository';
		foreach ( array(
			'missing'         => null,
			'incomplete'      => '',
			'remote_mismatch' => 'https://example.test/other-repository.git',
		) as $expected_state => $remote ) {
			$result = ( new FalseSuccessCloneHarness($workspace, $expected_state, $remote) )->clone_repo($repository);
			clone_postcondition_assert(is_wp_error($result), 'A false-success clone runner must not return success.');
			clone_postcondition_assert('clone_postcondition_failed' === $result->get_error_code(), 'False-success clones must return the stable typed error.');
			$data = $result->get_error_data();
			clone_postcondition_assert($repository === ($data['repository'] ?? null) && 'post_clone_validation' === ($data['phase'] ?? null), 'Validation evidence must identify the requested repository and phase.');
			clone_postcondition_assert($expected_state === ($data['validation_state'] ?? null) && $target === ($data['path'] ?? null), 'Validation must report the exact target state and path.');
			clone_postcondition_assert(is_array($data['next_steps'] ?? null) && str_contains(implode(' ', $data['next_steps']), 'remove it explicitly'), 'Validation errors must provide safe explicit remediation.');
			clone_postcondition_assert('missing' === $expected_state ? ! file_exists($target) : is_dir($target), 'Validation must leave caller target files in place.');
			if ( is_dir($target . '/.git') ) {
				rmdir($target . '/.git');
			}
			if ( is_dir($target) ) {
				rmdir($target);
			}
		}
	} finally {
		rmdir($workspace);
	}

	echo "workspace-clone-postcondition: ok\n";
}
