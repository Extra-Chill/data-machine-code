<?php
/**
 * DB-backed workspace worktree inventory.
 *
 * @package DataMachineCode\Storage
 */

namespace DataMachineCode\Storage;

use DataMachineCode\Support\JsonCodec;

defined('ABSPATH') || exit;

if ( ! class_exists(JsonCodec::class) ) {
	require_once dirname(__DIR__) . '/Support/JsonCodec.php';
}

class WorktreeInventoryRepository {



	public const TABLE = 'datamachine_code_worktrees';

	/**
	 * Install or upgrade the worktree inventory table.
	 */
	public static function install_schema(): void {
		global $wpdb;

		if ( ! isset($wpdb) ) {
			return;
		}

		$table           = self::table_name();
		$charset_collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			handle varchar(191) NOT NULL,
			repo varchar(191) NOT NULL DEFAULT '',
			branch varchar(191) DEFAULT NULL,
			path text NOT NULL,
			primary_path text DEFAULT NULL,
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			lifecycle_state varchar(64) DEFAULT NULL,
			origin_site varchar(191) DEFAULT NULL,
			origin_agent varchar(191) DEFAULT NULL,
			origin_session varchar(191) DEFAULT NULL,
			task_url text DEFAULT NULL,
			task_ref varchar(191) DEFAULT NULL,
			pr_url text DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			last_seen_at datetime DEFAULT NULL,
			last_probe_at datetime DEFAULT NULL,
			last_probe_status varchar(64) DEFAULT NULL,
			dirty_count int(11) DEFAULT NULL,
			unpushed_count int(11) DEFAULT NULL,
			artifact_count int(11) DEFAULT NULL,
			artifact_size_bytes bigint(20) unsigned DEFAULT NULL,
			size_bytes bigint(20) unsigned DEFAULT NULL,
			cleanup_signal varchar(64) DEFAULT NULL,
			missing_path tinyint(1) NOT NULL DEFAULT 0,
			metadata longtext DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY handle (handle),
			KEY repo (repo),
			KEY lifecycle_state (lifecycle_state),
			KEY last_seen_at (last_seen_at),
			KEY missing_path (missing_path)
		) {$charset_collate};";

		if ( ! function_exists('dbDelta') && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php') ) {
			include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		if ( function_exists('dbDelta') ) {
			dbDelta($sql);
		} else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static schema string.
			$wpdb->query($sql);
		}

		if ( function_exists('update_option') ) {
			update_option('datamachine_code_worktrees_schema_version', '1');
		}
	}

	/**
	 * Return the prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		$prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
		return $prefix . self::TABLE;
	}

	/**
	 * Upsert one inventory row.
	 *
	 * @param  array<string,mixed> $row Inventory row.
	 * @return bool
	 */
	public function upsert( array $row ): bool {
		global $wpdb;

		if ( ! isset($wpdb) ) {
			return false;
		}

		$data = $this->normalize_row($row);
		if ( '' === $data['handle'] ) {
			return false;
		}

		if ( method_exists($wpdb, 'replace') ) {
			$result = $wpdb->replace(self::table_name(), $data);
			return false !== $result;
		}

		return false;
	}

	/**
	 * Delete one inventory row by handle.
	 */
	public function delete( string $handle ): bool {
		global $wpdb;

		$handle = trim($handle);
		if ( '' === $handle || ! isset($wpdb) || ! method_exists($wpdb, 'delete') ) {
			return false;
		}

		$result = $wpdb->delete(self::table_name(), array( 'handle' => $handle ));
		return false !== $result;
	}

	/**
	 * Fetch one inventory row by handle.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( string $handle ): ?array {
		global $wpdb;

		$handle = trim($handle);
		if ( '' === $handle || ! isset($wpdb) ) {
			return null;
		}

		if ( method_exists($wpdb, 'get_row') && method_exists($wpdb, 'prepare') ) {
			$table = self::table_name();
			$row   = $wpdb->get_row(
				$wpdb->prepare('SELECT * FROM %i WHERE handle = %s LIMIT 1', $table, $handle),
				ARRAY_A
			);
			return is_array($row) ? $this->decode_row($row) : null;
		}

		foreach ( $this->list() as $row ) {
			if ( (string) ( $row['handle'] ?? '' ) === $handle ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Mark a known row as missing on disk instead of dropping it silently.
	 */
	public function mark_missing( string $handle ): bool {
		global $wpdb;

		$handle = trim($handle);
		if ( '' === $handle || ! isset($wpdb) || ! method_exists($wpdb, 'update') ) {
			return false;
		}

		$result = $wpdb->update(
			self::table_name(),
			array(
				'missing_path'      => 1,
				'last_probe_at'     => current_time('mysql', true),
				'last_probe_status' => 'missing_path',
				'updated_at'        => current_time('mysql', true),
			),
			array( 'handle' => $handle )
		);

		return false !== $result;
	}

	/**
	 * Prune inventory rows flagged missing_path whose on-disk path is still absent.
	 *
	 * Safety guards:
	 *   - Re-probes each candidate's path on disk; only deletes when the path is
	 *     STILL absent (a stale missing_path flag alone is not trusted).
	 *   - Refuses to delete rows with unpushed_count > 0 or a non-empty pr_url
	 *     unless 'force' is true; such rows are reported as skipped.
	 *
	 * @param  array{dry_run?: bool, force?: bool} $opts Options.
	 * @return array<string,mixed> Result with deleted/skipped lists and summary.
	 */
	public function pruneMissing( array $opts = array() ): array {
		$dry_run = ! empty($opts['dry_run']);
		$force   = ! empty($opts['force']);

		$rows    = $this->missing_path_rows();
		$deleted = array();
		$skipped = array();

		foreach ( $rows as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			$path   = (string) ( $row['path'] ?? '' );

			// Re-probe the disk: only reap when the path is STILL absent.
			if ( '' !== $path && is_dir($path) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir
				$skipped[] = array(
					'handle' => $handle,
					'path'   => $path,
					'reason' => 'path_present_on_disk',
				);
				continue;
			}

			// Protect rows carrying unpushed work or an open PR unless forced.
			if ( ! $force ) {
				$unpushed = isset($row['unpushed_count']) ? (int) $row['unpushed_count'] : 0;
				$pr_url   = isset($row['pr_url']) ? trim( (string) $row['pr_url'] ) : '';

				if ( $unpushed > 0 ) {
					$skipped[] = array(
						'handle'         => $handle,
						'path'           => $path,
						'reason'         => 'unpushed_count',
						'unpushed_count' => $unpushed,
					);
					continue;
				}

				if ( '' !== $pr_url ) {
					$skipped[] = array(
						'handle' => $handle,
						'path'   => $path,
						'reason' => 'pr_url',
						'pr_url' => $pr_url,
					);
					continue;
				}
			}

			if ( $dry_run ) {
				$deleted[] = array(
					'handle' => $handle,
					'path'   => $path,
					'repo'   => (string) ( $row['repo'] ?? '' ),
				);
				continue;
			}

			if ( $this->delete($handle) ) {
				$deleted[] = array(
					'handle' => $handle,
					'path'   => $path,
					'repo'   => (string) ( $row['repo'] ?? '' ),
				);
			} else {
				$skipped[] = array(
					'handle' => $handle,
					'path'   => $path,
					'reason' => 'delete_failed',
				);
			}
		}

		return array(
			'success'   => true,
			'pruned_at' => gmdate('c'),
			'dry_run'   => $dry_run,
			'deleted'   => $deleted,
			'skipped'   => $skipped,
			'summary'   => array(
				'deleted' => count($deleted),
				'skipped' => count($skipped),
				'total'   => count($rows),
			),
		);
	}

	/**
	 * Fetch all rows currently flagged missing_path.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function missing_path_rows(): array {
		global $wpdb;

		if ( ! isset($wpdb) || ! method_exists($wpdb, 'get_results') ) {
			return array();
		}

		$table = self::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$sql = "SELECT * FROM {$table} WHERE missing_path = 1 ORDER BY handle ASC";
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static string, no user input.
		$rows = $wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? array_map(array( $this, 'decode_row' ), $rows) : array();
	}

	/**
	 * Fetch all rows, optionally filtered by repo.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list( ?string $repo = null ): array {
		global $wpdb;

		if ( ! isset($wpdb) || ! method_exists($wpdb, 'get_results') ) {
			return array();
		}

		$table = self::table_name();
		if ( null !== $repo && '' !== trim($repo) && method_exists($wpdb, 'prepare') ) {
            // phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			$sql = $wpdb->prepare("SELECT * FROM {$table} WHERE repo = %s ORDER BY handle ASC", $repo);
            // phpcs:enable WordPress.DB.PreparedSQL
		} else {
			$sql = "SELECT * FROM {$table} ORDER BY handle ASC";
		}

     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above when dynamic input is present.
		$rows = $wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? array_map(array( $this, 'decode_row' ), $rows) : array();
	}

	/**
	 * Summarize inventory freshness for hygiene output.
	 *
	 * @return array<string,mixed>
	 */
	public function freshness(): array {
		$rows       = $this->list();
		$total      = count($rows);
		$missing    = 0;
		$last_probe = null;
		foreach ( $rows as $row ) {
			if ( ! empty($row['missing_path']) ) {
				++$missing;
			}
			if ( ! empty($row['last_probe_at']) && ( null === $last_probe || strcmp( (string) $row['last_probe_at'], (string) $last_probe) > 0 ) ) {
				$last_probe = $row['last_probe_at'];
			}
		}

		return array(
			'table'         => self::table_name(),
			'total_rows'    => $total,
			'missing_paths' => $missing,
			'last_probe_at' => $last_probe,
			'fresh'         => 0 < $total && null !== $last_probe,
		);
	}

	/**
	 * Normalize runtime/listing rows for DB storage.
	 *
	 * @param  array<string,mixed> $row Input row.
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ): array {
		$metadata = is_array($row['metadata'] ?? null) ? (array) $row['metadata'] : array();
		$owner    = is_array($row['owner'] ?? null) ? (array) $row['owner'] : array();
		$session  = is_array($row['session'] ?? null) ? (array) $row['session'] : array();
		$task     = is_array($row['task'] ?? null) ? (array) $row['task'] : ( is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : array() );

		return array(
			'handle'              => (string) ( $row['handle'] ?? $metadata['handle'] ?? '' ),
			'repo'                => (string) ( $row['repo'] ?? $metadata['repo'] ?? '' ),
			'branch'              => isset($row['branch']) ? (string) $row['branch'] : ( isset($metadata['branch']) ? (string) $metadata['branch'] : null ),
			'path'                => (string) ( $row['path'] ?? $metadata['path'] ?? '' ),
			'primary_path'        => isset($row['primary_path']) ? (string) $row['primary_path'] : null,
			'is_primary'          => ! empty($row['is_primary']) ? 1 : 0,
			'lifecycle_state'     => isset($row['lifecycle_state']) ? (string) $row['lifecycle_state'] : ( isset($metadata['lifecycle_state']) ? (string) $metadata['lifecycle_state'] : null ),
			'origin_site'         => (string) ( $owner['site'] ?? $metadata['origin_site'] ?? $metadata['origin_site_name'] ?? '' ),
			'origin_agent'        => (string) ( $owner['agent'] ?? $metadata['origin_agent'] ?? '' ),
			'origin_session'      => isset($session['primary_id']) ? (string) $session['primary_id'] : ( isset($metadata['origin_session']) ? (string) $metadata['origin_session'] : null ),
			'task_url'            => isset($task['task_url']) ? (string) $task['task_url'] : null,
			'task_ref'            => isset($task['task_ref']) ? (string) $task['task_ref'] : null,
			'pr_url'              => isset($row['pr_url']) ? (string) $row['pr_url'] : ( isset($metadata['pr_url']) ? (string) $metadata['pr_url'] : null ),
			'created_at'          => $this->datetime($row['created_at'] ?? $metadata['created_at'] ?? null),
			'last_seen_at'        => $this->datetime($row['last_seen_at'] ?? $metadata['last_seen_at'] ?? null),
			'last_probe_at'       => current_time('mysql', true),
			'last_probe_status'   => ! empty($row['missing_path']) ? 'missing_path' : 'present',
			'dirty_count'         => isset($row['dirty']) ? (int) $row['dirty'] : ( isset($row['dirty_count']) ? (int) $row['dirty_count'] : null ),
			'unpushed_count'      => isset($row['unpushed_count']) ? (int) $row['unpushed_count'] : null,
			'artifact_count'      => isset($row['artifacts']) && is_array($row['artifacts']) ? count($row['artifacts']) : ( isset($row['artifact_count']) ? (int) $row['artifact_count'] : null ),
			'artifact_size_bytes' => isset($row['artifact_size_bytes']) ? (int) $row['artifact_size_bytes'] : null,
			'size_bytes'          => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
			'cleanup_signal'      => $this->cleanup_signal($row, $metadata),
			'missing_path'        => ! empty($row['missing_path']) ? 1 : 0,
			'metadata'            => JsonCodec::encode_or_default($metadata),
			'updated_at'          => current_time('mysql', true),
		);
	}

	/**
	 * Decode JSON columns in DB rows.
	 *
	 * @param  array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private function decode_row( array $row ): array {
		$row['metadata'] = isset($row['metadata']) && is_string($row['metadata']) ? JsonCodec::decode_array($row['metadata'], null) : null;
		foreach ( array( 'id', 'is_primary', 'dirty_count', 'unpushed_count', 'artifact_count', 'artifact_size_bytes', 'size_bytes', 'missing_path' ) as $key ) {
			if ( isset($row[ $key ]) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		return $row;
	}

	/**
	 * Normalize ISO or MySQL dates to GMT MySQL format.
	 */
	private function datetime( mixed $value ): ?string {
		if ( ! is_string($value) || '' === trim($value) ) {
			return null;
		}

		$timestamp = strtotime($value);
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate('Y-m-d H:i:s', $timestamp);
	}

	/**
	 * Derive the explicit cleanup signal stored in the inventory row.
	 *
	 * @param array<string,mixed> $row      Inventory row.
	 * @param array<string,mixed> $metadata Lifecycle metadata.
	 */
	private function cleanup_signal( array $row, array $metadata ): ?string {
		if ( ! empty($row['cleanup_signal']) ) {
			return (string) $row['cleanup_signal'];
		}

		$state = (string) ( $row['lifecycle_state'] ?? $metadata['lifecycle_state'] ?? '' );
		if ( in_array($state, array( 'cleanup_eligible', 'pr_opened', 'merged', 'closed', 'abandoned' ), true) ) {
			return $state;
		}

		return null;
	}
}
