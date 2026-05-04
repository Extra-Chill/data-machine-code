<?php
/**
 * Smoke test for DB-backed worktree inventory repository.
 *
 * Run: php tests/smoke-worktree-inventory-repository.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

function current_time( string $type, bool $gmt = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return '2026-05-04 12:00:00';
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode( $value, $flags, $depth );
}

function update_option( string $name, mixed $value ): bool {
	$GLOBALS['datamachine_code_test_options'][ $name ] = $value;
	return true;
}

function dbDelta( string $sql ): array {
	$GLOBALS['datamachine_code_test_schema_sql'] = $sql;
	return array( 'ok' );
}

class DatamachineCodeInventoryFakeWpdb {
	public string $prefix = 'wp_';

	/** @var array<string,array<string,mixed>> */
	public array $rows = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	/** @param array<string,mixed> $data */
	public function replace( string $table, array $data ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->rows[ (string) $data['handle'] ] = $data;
		return 1;
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $this->rows[ (string) $where['handle'] ] );
		return 1;
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	public function update( string $table, array $data, array $where ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$handle = (string) $where['handle'];
		if ( ! isset( $this->rows[ $handle ] ) ) {
			return 0;
		}
		$this->rows[ $handle ] = array_merge( $this->rows[ $handle ], $data );
		return 1;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( string $sql, string $output ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$rows = array_values( $this->rows );
		usort( $rows, fn( array $a, array $b ): int => strcmp( (string) $a['handle'], (string) $b['handle'] ) );
		return $rows;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}
}

require_once dirname( __DIR__ ) . '/inc/Storage/WorktreeInventoryRepository.php';

use DataMachineCode\Storage\WorktreeInventoryRepository;

function datamachine_code_inventory_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}
	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

echo "=== smoke-worktree-inventory-repository ===\n";

$GLOBALS['wpdb'] = new DatamachineCodeInventoryFakeWpdb();

WorktreeInventoryRepository::install_schema();
datamachine_code_inventory_assert( str_contains( (string) $GLOBALS['datamachine_code_test_schema_sql'], 'datamachine_code_worktrees' ), 'schema creates worktree table' );
datamachine_code_inventory_assert( '1' === $GLOBALS['datamachine_code_test_options']['datamachine_code_worktrees_schema_version'], 'schema version is recorded' );

$repository = new WorktreeInventoryRepository();
$repository->upsert(
	array(
		'handle'          => 'demo@feature',
		'repo'            => 'demo',
		'branch'          => 'feature',
		'path'            => '/workspace/demo@feature',
		'lifecycle_state' => 'active',
		'owner'           => array( 'site' => 'Test Site', 'agent' => 'Franklin' ),
		'session'         => array( 'primary_id' => 'ses_123' ),
		'task'            => array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/243' ),
		'metadata'        => array(
			'created_at'      => '2026-05-04T11:59:00Z',
			'lifecycle_state' => 'active',
		),
	)
);

$rows = $repository->list();
datamachine_code_inventory_assert( 1 === count( $rows ), 'upsert writes one row' );
datamachine_code_inventory_assert( 'demo@feature' === $rows[0]['handle'], 'handle is stable inventory key' );
datamachine_code_inventory_assert( 'present' === $rows[0]['last_probe_status'], 'upsert marks present probe status' );
datamachine_code_inventory_assert( 'active' === ( $rows[0]['metadata']['lifecycle_state'] ?? '' ), 'metadata JSON round-trips' );

$repository->upsert(
	array(
		'handle'          => 'demo@feature',
		'repo'            => 'demo',
		'branch'          => 'feature',
		'path'            => '/workspace/demo@feature',
		'lifecycle_state' => 'cleanup_eligible',
		'pr_url'          => 'https://github.com/acme/demo/pull/7',
		'metadata'        => array( 'lifecycle_state' => 'cleanup_eligible' ),
	)
);

$rows = $repository->list();
datamachine_code_inventory_assert( 'cleanup_eligible' === $rows[0]['lifecycle_state'], 'second upsert updates lifecycle state' );
datamachine_code_inventory_assert( 'cleanup_eligible' === $rows[0]['cleanup_signal'], 'cleanup signal is derived from lifecycle state' );

$repository->mark_missing( 'demo@feature' );
$rows = $repository->list();
datamachine_code_inventory_assert( 1 === $rows[0]['missing_path'], 'missing path is recorded instead of silently disappearing' );
datamachine_code_inventory_assert( 'missing_path' === $rows[0]['last_probe_status'], 'missing path probe status is recorded' );

$freshness = $repository->freshness();
datamachine_code_inventory_assert( 1 === $freshness['total_rows'], 'freshness reports total row count' );
datamachine_code_inventory_assert( 1 === $freshness['missing_paths'], 'freshness reports missing path count' );

$repository->delete( 'demo@feature' );
datamachine_code_inventory_assert( array() === $repository->list(), 'delete removes inventory row' );

echo "\nAll worktree inventory repository assertions passed.\n";
