<?php
/**
 * Cleanup run repository.
 *
 * @package DataMachineCode\Storage
 */

namespace DataMachineCode\Storage;

use DataMachineCode\Support\JsonCodec;

defined('ABSPATH') || exit;

if ( ! class_exists(JsonCodec::class) ) {
	require_once dirname(__DIR__) . '/Support/JsonCodec.php';
}

class CleanupRunRepository {



	/**
	 * Create a cleanup run.
	 *
	 * @param  array<string,mixed> $run Run fields.
	 * @return string|\WP_Error
	 */
	public function create_run( array $run ): string|\WP_Error {
		global $wpdb;

		$run_id = (string) ( $run['run_id'] ?? $this->new_run_id() );
		$now    = gmdate('Y-m-d H:i:s');
		$ok     = $wpdb->insert(
			CleanupSchema::runs_table(),
			array(
				'run_id'                => $run_id,
				'mode'                  => (string) ( $run['mode'] ?? 'cleanup_plan' ),
				'status'                => (string) ( $run['status'] ?? 'planned' ),
				'policy'                => $this->encode($run['policy'] ?? array()),
				'requested_by_user_id'  => isset($run['requested_by_user_id']) ? (int) $run['requested_by_user_id'] : null,
				'requested_by_agent_id' => isset($run['requested_by_agent_id']) ? (int) $run['requested_by_agent_id'] : null,
				'parent_job_id'         => isset($run['parent_job_id']) ? (int) $run['parent_job_id'] : null,
				'batch_job_id'          => isset($run['batch_job_id']) ? (int) $run['batch_job_id'] : null,
				'created_at'            => (string) ( $run['created_at'] ?? $now ),
				'started_at'            => $run['started_at'] ?? null,
				'completed_at'          => $run['completed_at'] ?? null,
				'summary'               => $this->encode($run['summary'] ?? array()),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return new \WP_Error('cleanup_run_insert_failed', 'Failed to create cleanup run.');
		}

		return $run_id;
	}

	/**
	 * Insert planned cleanup items.
	 *
	 * @param  string           $run_id Run ID.
	 * @param  array<int,array> $items  Items.
	 * @return int|\WP_Error
	 */
	public function add_items( string $run_id, array $items ): int|\WP_Error {
		global $wpdb;

		$count = 0;
		$now   = gmdate('Y-m-d H:i:s');
		foreach ( $items as $item ) {
			if ( ! is_array($item) ) {
				continue;
			}

			$ok = $wpdb->insert(
				CleanupSchema::items_table(),
				array(
					'run_id'          => $run_id,
					'handle'          => (string) ( $item['handle'] ?? '' ),
					'worktree_id'     => isset($item['worktree_id']) ? (int) $item['worktree_id'] : null,
					'item_type'       => (string) ( $item['item_type'] ?? $item['row_type'] ?? 'unknown' ),
					'planned_action'  => (string) ( $item['planned_action'] ?? $this->planned_action_for_type( (string) ( $item['item_type'] ?? $item['row_type'] ?? '' )) ),
					'status'          => (string) ( $item['status'] ?? 'pending' ),
					'reason_code'     => (string) ( $item['reason_code'] ?? '' ),
					'reason'          => isset($item['reason']) ? (string) $item['reason'] : null,
					'bytes_reclaimed' => max(0, (int) ( $item['bytes_reclaimed'] ?? 0 )),
					'job_id'          => isset($item['job_id']) ? (int) $item['job_id'] : null,
					'chunk_index'     => isset($item['chunk_index']) ? (int) $item['chunk_index'] : null,
					'planned_at'      => (string) ( $item['planned_at'] ?? $now ),
					'applied_at'      => $item['applied_at'] ?? null,
					'evidence'        => $this->encode($item['evidence'] ?? $item),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
			);

			if ( false === $ok ) {
					return new \WP_Error('cleanup_item_insert_failed', 'Failed to create cleanup item.');
			}
			++$count;
		}

		return $count;
	}

	/**
	 * Fetch a cleanup run by ID.
	 *
	 * @param  string $run_id Run ID.
	 * @return array<string,mixed>|null
	 */
	public function get_run( string $run_id ): ?array {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CleanupSchema::runs_table() . ' WHERE run_id = %s', $run_id), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL
		return is_array($row) ? $this->decode_run($row) : null;
	}

	/**
	 * Fetch items for a run.
	 *
	 * @param  string $run_id Run ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_items( string $run_id ): array {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . CleanupSchema::items_table() . ' WHERE run_id = %s ORDER BY id ASC', $run_id), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL
		return array_map(fn( $row ) => $this->decode_item( (array) $row), is_array($rows) ? $rows : array());
	}

	/**
	 * Update run fields.
	 *
	 * @param  string              $run_id Run ID.
	 * @param  array<string,mixed> $fields Fields.
	 * @return bool
	 */
	public function update_run( string $run_id, array $fields ): bool {
		global $wpdb;

		$data = array();
		foreach ( array( 'status', 'started_at', 'completed_at', 'parent_job_id', 'batch_job_id' ) as $field ) {
			if ( array_key_exists($field, $fields) ) {
				$data[ $field ] = $fields[ $field ];
			}
		}
		if ( array_key_exists('summary', $fields) ) {
			$data['summary'] = $this->encode($fields['summary']);
		}
		if ( array() === $data ) {
			return true;
		}

		return false !== $wpdb->update(CleanupSchema::runs_table(), $data, array( 'run_id' => $run_id ));
	}

	/**
	 * Update one item by numeric ID.
	 *
	 * @param  int                 $id     Item ID.
	 * @param  array<string,mixed> $fields Fields.
	 * @return bool
	 */
	public function update_item( int $id, array $fields ): bool {
		global $wpdb;

		$data = array();
		foreach ( array( 'status', 'bytes_reclaimed', 'job_id', 'chunk_index', 'applied_at', 'reason_code', 'reason' ) as $field ) {
			if ( array_key_exists($field, $fields) ) {
				$data[ $field ] = $fields[ $field ];
			}
		}
		if ( array_key_exists('evidence', $fields) ) {
			$data['evidence'] = $this->encode($fields['evidence']);
		}

		return array() === $data || false !== $wpdb->update(CleanupSchema::items_table(), $data, array( 'id' => $id ));
	}

	private function decode_run( array $row ): array {
		$row['policy']  = $this->decode($row['policy'] ?? '');
		$row['summary'] = $this->decode($row['summary'] ?? '');
		return $row;
	}

	private function decode_item( array $row ): array {
		$row['evidence'] = $this->decode($row['evidence'] ?? '');
		return $row;
	}

	private function encode( mixed $value ): string {
		return JsonCodec::encode_or_default($value, '{}', JSON_UNESCAPED_SLASHES);
	}

	private function decode( mixed $value ): array {
		return JsonCodec::decode_array($value, array());
	}

	private function new_run_id(): string {
		return 'cleanup-run-' . gmdate('YmdHis') . '-' . substr(wp_generate_password(12, false, false), 0, 12);
	}

	private function planned_action_for_type( string $type ): string {
		return match ( $type ) {
			'artifact_cleanup' => 'remove_artifacts',
			'worktree_removal' => 'remove_worktree',
			'resolver'         => 'resolve_signal',
			default            => 'review',
		};
	}
}
