<?php
/**
 * Smoke test for workspace cleanup run scheduling context.
 *
 *   php tests/smoke-workspace-cleanup-run-context.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__);
	}

	function sanitize_key( $key ): string {
		return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
	}

	class WP_Error {
		public function __construct(
			private string $code,
			private string $message,
			private array $data = array()
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public function get_effective_user_id( int $user_id ): int {
			return $user_id > 0 ? $user_id : 1;
		}

		public function resolve_agent_slug( array $args ): string {
			return 1 === (int) ( $args['user_id'] ?? 0 ) ? 'intelligence-chubes4' : '';
		}
	}
}

namespace DataMachine\Engine\Tasks {
	class TaskScheduler {
		public static array $last_context = array();
		public static array|false $next_result = array( 'job_ids' => array( 987 ), 'batch_job_id' => 0 );

		public static function scheduleBatch( string $task_type, array $items, array $context = array() ): array|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			self::$last_context = $context;
			return self::$next_result;
		}
	}
}

namespace {
	include_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

	function datamachine_code_cleanup_run_context_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite(STDERR, "Assertion failed: {$message}\n");
			exit(1);
		}
	}

	$result = \DataMachineCode\Abilities\WorkspaceAbilities::workspaceCleanupRun(
		array(
			'mode'   => 'artifacts',
			'source' => 'workspace_cleanup_cli',
		)
	);

	datamachine_code_cleanup_run_context_assert(is_array($result), 'cleanup run returns success array');
	datamachine_code_cleanup_run_context_assert('cleanup-run-987' === ( $result['run_id'] ?? '' ), 'cleanup run uses direct job id');
	datamachine_code_cleanup_run_context_assert('intelligence-chubes4' === ( \DataMachine\Engine\Tasks\TaskScheduler::$last_context['agent_slug'] ?? '' ), 'cleanup run supplies resolved agent slug');

	\DataMachine\Engine\Tasks\TaskScheduler::$next_result = array( 'job_ids' => array(), 'batch_job_id' => 0 );
	$result = \DataMachineCode\Abilities\WorkspaceAbilities::workspaceCleanupRun(
		array(
			'mode'       => 'artifacts',
			'agent_slug' => 'explicit-agent',
		)
	);

	datamachine_code_cleanup_run_context_assert($result instanceof \WP_Error, 'cleanup run without scheduled job returns an error');
	datamachine_code_cleanup_run_context_assert('workspace_cleanup_schedule_empty' === $result->get_error_code(), 'cleanup run reports empty schedule explicitly');
	datamachine_code_cleanup_run_context_assert('explicit-agent' === ( \DataMachine\Engine\Tasks\TaskScheduler::$last_context['agent_slug'] ?? '' ), 'explicit agent slug is sanitized and forwarded');

	echo "Workspace cleanup run context smoke passed.\n";
}
