<?php
/**
 * Smoke test for DB-backed cleanup run storage/service.
 *
 * Run: php tests/smoke-cleanup-run-storage.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12 ): string { return substr( str_repeat( 'a', $length ), 0, $length ); }
}

require __DIR__ . '/../vendor/autoload.php';

class DataMachineCodeCleanupRunFakeWpdb {
	public string $prefix = 'wp_';
	public array $runs = array();
	public array $items = array();
	private int $run_id = 0;
	private int $item_id = 0;

	public function insert( string $table, array $data, array $format = array() ): int|false {
		if ( str_contains( $table, 'cleanup_runs' ) ) {
			$data['id'] = ++$this->run_id;
			$this->runs[ $data['run_id'] ] = $data;
			return 1;
		}
		if ( str_contains( $table, 'cleanup_items' ) ) {
			$data['id'] = ++$this->item_id;
			$this->items[] = $data;
			return 1;
		}
		return false;
	}

	public function update( string $table, array $data, array $where ): int|false {
		if ( str_contains( $table, 'cleanup_runs' ) ) {
			$key = (string) ( $where['run_id'] ?? '' );
			$this->runs[ $key ] = array_merge( $this->runs[ $key ] ?? array(), $data );
			return 1;
		}
		if ( str_contains( $table, 'cleanup_items' ) ) {
			$id = (int) ( $where['id'] ?? 0 );
			foreach ( $this->items as &$item ) {
				if ( (int) $item['id'] === $id ) {
					$item = array_merge( $item, $data );
					return 1;
				}
			}
		}
		return false;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s|%d/', (string) $arg, $query, 1 );
		}
		return $query;
	}

	public function get_row( string $query, string $output = ARRAY_A ): ?array {
		if ( preg_match( '/run_id = ([^\s]+)/', $query, $matches ) ) {
			return $this->runs[ $matches[1] ] ?? null;
		}
		return null;
	}

	public function get_results( string $query, string $output = ARRAY_A ): array {
		if ( ! preg_match( '/run_id = ([^\s]+)/', $query, $matches ) ) {
			return array();
		}
		return array_values( array_filter( $this->items, fn( $item ) => (string) $item['run_id'] === $matches[1] ) );
	}
}

class DataMachineCodeCleanupRunFakeWorkspace extends \DataMachineCode\Workspace\Workspace {
	public function __construct() {}
	public function workspace_cleanup_plan( array $opts = array() ): array|WP_Error {
		return array(
			'success'       => true,
			'mode'          => 'cleanup_plan',
			'plan_id'       => 'cleanup-plan-test',
			'safety_policy' => array( 'destructive_rows_need_review' => true ),
			'rows'          => array(
				'artifact_cleanup' => array(
					array( 'handle' => 'demo@old', 'row_type' => 'artifact_cleanup', 'reason_code' => 'profile_artifact', 'artifact_size_bytes' => 15 ),
				),
				'worktree_removal' => array(
					array( 'handle' => 'demo@merged', 'repo' => 'demo', 'branch' => 'merged', 'path' => '/tmp/demo@merged', 'row_type' => 'worktree_removal', 'reason_code' => 'cleanup_eligible', 'size_bytes' => 20 ),
				),
				'resolver' => array(),
			),
			'summary'       => array( 'total_rows' => 2, 'total_size_bytes' => 35 ),
		);
	}
	public function worktree_cleanup_artifacts( array $opts = array() ): array|WP_Error {
		return array( 'removed' => array( array( 'handle' => 'demo@old' ) ), 'skipped' => array(), 'summary' => array() );
	}
	public function worktree_cleanup_merged( array $opts = array() ): array|WP_Error {
		return array( 'removed' => array( array( 'handle' => 'demo@merged' ) ), 'skipped' => array(), 'summary' => array() );
	}
}

function datamachine_code_cleanup_run_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "ok - {$message}\n";
}

$GLOBALS['wpdb'] = new DataMachineCodeCleanupRunFakeWpdb();
$repo = new \DataMachineCode\Storage\CleanupRunRepository();
$service = new \DataMachineCode\Workspace\CleanupRunService( $repo, new DataMachineCodeCleanupRunFakeWorkspace() );

$plan = $service->plan( array( 'mode' => 'retention' ) );
datamachine_code_cleanup_run_assert( ! is_wp_error( $plan ), 'plan succeeds' );
datamachine_code_cleanup_run_assert( isset( $plan['run_id'] ), 'plan returns run_id' );
datamachine_code_cleanup_run_assert( 2 === (int) ( $plan['cleanup_storage']['item_count'] ?? 0 ), 'plan persists cleanup items' );

$status = $service->status( $plan['run_id'] );
datamachine_code_cleanup_run_assert( 2 === (int) ( $status['summary']['total_items'] ?? 0 ), 'status aggregates items' );
datamachine_code_cleanup_run_assert( 2 === (int) ( $status['summary']['items_by_status']['pending'] ?? 0 ), 'status tracks pending items' );

$apply = $service->apply( $plan['run_id'] );
datamachine_code_cleanup_run_assert( ! is_wp_error( $apply ), 'apply succeeds' );

$evidence = $service->evidence( $plan['run_id'] );
datamachine_code_cleanup_run_assert( 2 === (int) ( $evidence['summary']['items_by_status']['applied'] ?? 0 ), 'evidence reflects applied rows' );
datamachine_code_cleanup_run_assert( 35 === (int) ( $evidence['summary']['bytes_reclaimed'] ?? 0 ), 'evidence aggregates reclaimed bytes' );

echo "DB-backed cleanup run storage smoke passed.\n";
