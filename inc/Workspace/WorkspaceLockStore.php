<?php
/**
 * DB-visible workspace lock store.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorkspaceLockStore {



	private const DEFAULT_EXPIRES_SECONDS      = 900;
	private const DEFAULT_RELEASED_TTL_SECONDS = 604800;

	/**
	 * Whether a WordPress database handle is available.
	 */
	public static function is_available(): bool {
		global $wpdb;
		return is_object($wpdb) && isset($wpdb->prefix);
	}

	/**
	 * Register an acquired lock in the database.
	 *
	 * @param  array<string,mixed> $args Lock fields.
	 * @return int|\WP_Error Lock row id, 0 when DB is unavailable, or error.
	 */
	public static function register_acquired( array $args ): int|\WP_Error {
		if ( ! self::is_available() ) {
			return 0;
		}

		$ensured = self::ensure_table();
		if ( $ensured instanceof \WP_Error ) {
			return $ensured;
		}

		global $wpdb;
		$now     = gmdate('Y-m-d H:i:s');
		$expires = gmdate('Y-m-d H:i:s', time() + self::expires_seconds());
		$meta    = isset($args['metadata']) && is_array($args['metadata']) ? $args['metadata'] : array();

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'lock_key'      => self::bounded_string( (string) ( $args['lock_key'] ?? '' ), 190),
				'purpose'       => self::bounded_string( (string) ( $args['purpose'] ?? 'workspace_repo_mutation' ), 100),
				'scope'         => self::bounded_string( (string) ( $args['scope'] ?? '' ), 190),
				'owner'         => self::bounded_string( (string) ( $args['owner'] ?? self::default_owner() ), 190),
				'run_id'        => self::nullable_bounded_string($args['run_id'] ?? null, 100),
				'job_id'        => isset($args['job_id']) ? (int) $args['job_id'] : null,
				'status'        => 'active',
				'acquired_at'   => $now,
				'heartbeat_at'  => $now,
				'expires_at'    => $expires,
				'released_at'   => null,
				'metadata_json' => self::encode_metadata($meta),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'workspace_lock_db_insert_failed',
				'Failed to record workspace lock ownership in the database.',
				array(
					'status'     => 500,
					'wpdb_error' => (string) $wpdb->last_error,
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a lock row released.
	 */
	public static function release( int $lock_id ): void {
		if ( $lock_id <= 0 || ! self::is_available() ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'      => 'released',
				'released_at' => gmdate('Y-m-d H:i:s'),
			),
			array( 'id' => $lock_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Build active/stale/released lock counts.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		if ( ! self::is_available() ) {
			return array(
				'available' => false,
				'table'     => null,
				'active'    => 0,
				'stale'     => 0,
				'released'  => 0,
				'total'     => 0,
			);
		}

		$ensured = self::ensure_table();
		if ( $ensured instanceof \WP_Error ) {
			return array(
				'available' => false,
				'error'     => $ensured->get_error_message(),
				'table'     => self::table_name(),
				'active'    => 0,
				'stale'     => 0,
				'released'  => 0,
				'total'     => 0,
			);
		}

		global $wpdb;
		$table = self::table_name();
		$now   = gmdate('Y-m-d H:i:s');

     // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, not user input.
		return array(
			'available' => true,
			'table'     => $table,
			'active'    => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND expires_at >= %s", 'active', $now)),
			'stale'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND expires_at < %s", 'active', $now)),
			'released'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'released')),
			'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
		);
     // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Prune expired DB lock rows according to retention policy.
	 *
	 * @return array<string,mixed>
	 */
	public static function prune_expired(): array {
		$status = self::status();
		if ( empty($status['available']) ) {
			return array(
				'available'           => false,
				'active_marked_stale' => 0,
				'released_deleted'    => 0,
				'before'              => $status,
				'after'               => $status,
			);
		}

		global $wpdb;
		$table           = self::table_name();
		$now             = gmdate('Y-m-d H:i:s');
		$released_cutoff = gmdate('Y-m-d H:i:s', time() - self::released_ttl_seconds());

     // phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$wpdb->query($wpdb->prepare("UPDATE {$table} SET status = %s WHERE status = %s AND expires_at < %s", 'stale', 'active', $now));
		$marked = (int) $wpdb->rows_affected;

		$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE status IN (%s, %s) AND COALESCE(released_at, expires_at) < %s", 'released', 'stale', $released_cutoff));
     // phpcs:enable WordPress.DB.PreparedSQL
		$deleted = (int) $wpdb->rows_affected;

		return array(
			'available'           => true,
			'active_marked_stale' => $marked,
			'released_deleted'    => $deleted,
			'before'              => $status,
			'after'               => self::status(),
		);
	}

	private static function ensure_table(): true|\WP_Error {
		global $wpdb;
		$table = self::table_name();

		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ( $exists === $table ) {
			return true;
		}

		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lock_key varchar(190) NOT NULL,
			purpose varchar(100) NOT NULL,
			scope varchar(190) NOT NULL,
			owner varchar(190) NOT NULL,
			run_id varchar(100) NULL,
			job_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			acquired_at datetime NOT NULL,
			heartbeat_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			released_at datetime NULL,
			metadata_json longtext NULL,
			PRIMARY KEY  (id),
			KEY lock_key (lock_key),
			KEY status_expires (status, expires_at),
			KEY scope_status (scope, status),
			KEY run_id (run_id),
			KEY job_id (job_id)
		) {$charset_collate};";

		dbDelta($sql);
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ( $exists !== $table ) {
			return new \WP_Error('workspace_lock_table_missing', sprintf('Failed to create workspace locks table: %s.', $table), array( 'status' => 500 ));
		}

		return true;
	}

	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'datamachine_code_locks';
	}

	private static function expires_seconds(): int {
		$seconds = self::DEFAULT_EXPIRES_SECONDS;
		if ( function_exists('apply_filters') ) {
			$seconds = (int) apply_filters('datamachine_code_lock_expires_seconds', $seconds);
		}
		return max(60, $seconds);
	}

	private static function released_ttl_seconds(): int {
		$seconds = self::DEFAULT_RELEASED_TTL_SECONDS;
		if ( function_exists('apply_filters') ) {
			$seconds = (int) apply_filters('datamachine_code_lock_released_ttl_seconds', $seconds);
		}
		return max(3600, $seconds);
	}

	private static function default_owner(): string {
		$host = function_exists('gethostname') ? (string) gethostname() : 'unknown-host';
		$pid  = function_exists('getmypid') ? (string) getmypid() : 'unknown-pid';
		return $host . ':' . $pid;
	}

	private static function bounded_string( string $value, int $max ): string {
		$value = trim($value);
		if ( '' === $value ) {
			$value = 'unknown';
		}
		return substr($value, 0, $max);
	}

	private static function nullable_bounded_string( mixed $value, int $max ): ?string {
		if ( null === $value || '' === trim( (string) $value) ) {
			return null;
		}
		return self::bounded_string( (string) $value, $max);
	}

	/**
	 * @param array<string,mixed> $metadata Metadata to encode.
	 */
	private static function encode_metadata( array $metadata ): string {
		$metadata = array_slice($metadata, 0, 20, true);
		$json     = wp_json_encode($metadata);
		$json     = false === $json ? '{}' : (string) $json;
		return substr($json, 0, 8192);
	}
}
