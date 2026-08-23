<?php

declare(strict_types=1);

namespace DataMachine\Engine\Tasks {
	final class TaskScheduler {
		public static int $calls = 0;
		public static function scheduleBatch( string $task_type, array $params, array $context ): array|false {
			++self::$calls;
			return array( 'job_ids' => array( 51 ) );
		}
	}
}

namespace {
	define('ABSPATH', dirname(__DIR__) . '/');
	define('ARRAY_A', 'ARRAY_A');

	final class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
	function sanitize_key( string $value ): string { return $value; }

	final class SafeCleanupSchedulerWpdb {
		public string $prefix = 'wp_';
		public array $runs = array();
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace('/%[ds]/', "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
			}
			return $query;
		}
		public function get_row( string $query, string $output ): ?array {
			preg_match("/run_id = '([^']+)'/", $query, $match);
			return isset($match[1], $this->runs[$match[1]]) ? $this->runs[$match[1]] : null;
		}
		public function insert( string $table, array $row, array $formats ): bool {
			if ( isset($this->runs[$row['run_id']]) ) { return false; }
			$this->runs[$row['run_id']] = $row;
			return true;
		}
		public function update( string $table, array $fields, array $where ): bool {
			$this->runs[$where['run_id']] = array_merge($this->runs[$where['run_id']], $fields);
			return true;
		}
	}

	function scheduler_lifecycle_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException($message); }
	}

	$GLOBALS['wpdb'] = new SafeCleanupSchedulerWpdb();
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepositoryInterface.php';
	require_once dirname(__DIR__) . '/inc/Support/JsonCodec.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupSchema.php';
	require_once dirname(__DIR__) . '/inc/Storage/CleanupRunRepository.php';
	require_once dirname(__DIR__) . '/inc/Abilities/WorkspaceAbilities.php';

	$first = \DataMachineCode\Abilities\WorkspaceAbilities::workspaceCleanupSafeRun(array( 'request_id' => 'caller-retry-1', 'dry_run' => true ));
	$second = \DataMachineCode\Abilities\WorkspaceAbilities::workspaceCleanupSafeRun(array( 'request_id' => 'caller-retry-1', 'dry_run' => true ));
	scheduler_lifecycle_assert(false === is_wp_error($first), 'Initial safe scheduler request succeeds.');
	scheduler_lifecycle_assert(($first['run_id'] ?? null) === ($second['run_id'] ?? null), 'Duplicate request IDs return the same durable run.');
	scheduler_lifecycle_assert(true === ($second['idempotent'] ?? false), 'Duplicate request IDs return an explicit idempotent envelope.');
	scheduler_lifecycle_assert(1 === \DataMachine\Engine\Tasks\TaskScheduler::$calls, 'Duplicate request IDs schedule exactly one task.');
	scheduler_lifecycle_assert("studio wp datamachine-code workspace cleanup safe --format=json --request-id='caller-retry-1'" === ($first['commands']['resume'] ?? null), 'Initial envelope resume uses the safe scheduler path.');

	echo "workspace safe cleanup scheduler lifecycle test passed.\n";
}
