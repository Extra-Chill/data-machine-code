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
			'available'   => true,
			'table'       => $table,
			'active'      => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND expires_at >= %s", 'active', $now)),
			'active_keys' => self::lock_keys_for_status('active', false),
			'stale'       => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND expires_at < %s", 'active', $now)),
			'stale_keys'  => self::lock_keys_for_status('active', true),
			'locks'       => array_merge(self::lock_rows_for_status('active', false), self::lock_rows_for_status('active', true)),
			'released'    => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'released')),
			'total'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
		);
     // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return the active DB-visible lock row for a specific lock target.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public static function active_lock( string $lock_key, string $scope ): array|null|\WP_Error {
		if ( ! self::is_available() ) {
			return null;
		}

		$ensured = self::ensure_table();
		if ( $ensured instanceof \WP_Error ) {
			return $ensured;
		}

		global $wpdb;
		$table = self::table_name();
		$now   = gmdate('Y-m-d H:i:s');

     // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, not user input.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, lock_key, purpose, scope, owner, run_id, job_id, status, acquired_at, heartbeat_at, expires_at, released_at, metadata_json FROM {$table} WHERE lock_key = %s AND scope = %s AND status = %s AND expires_at >= %s ORDER BY acquired_at DESC, id DESC LIMIT 1",
				$lock_key,
				$scope,
				'active',
				$now
			),
			ARRAY_A
		);
     // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty($row) || ! is_array($row) ) {
			return null;
		}

		$row['id']       = (int) ( $row['id'] ?? 0 );
		$row['job_id']   = isset($row['job_id']) ? (int) $row['job_id'] : null;
		$row['metadata'] = self::decode_metadata( (string) ( $row['metadata_json'] ?? '' ) );
		unset($row['metadata_json']);

		$acquired  = self::timestamp_seconds( (string) ( $row['acquired_at'] ?? '' ) );
		$heartbeat = self::timestamp_seconds( (string) ( $row['heartbeat_at'] ?? '' ) );
		$expires   = self::timestamp_seconds( (string) ( $row['expires_at'] ?? '' ) );
		$time      = time();
		if ( null !== $acquired ) {
			$row['age_seconds'] = max(0, $time - $acquired);
		}
		if ( null !== $heartbeat ) {
			$row['heartbeat_age_seconds'] = max(0, $time - $heartbeat);
		}
		if ( null !== $expires ) {
			$row['expires_in_seconds']  = max(0, $expires - $time);
			$row['retry_after_seconds'] = max(0, $expires - $time);
		}

		return $row;
	}

	/**
	 * Prune expired DB lock rows according to retention policy.
	 *
	 * @return array<string,mixed>
	 */
	public static function prune_expired( array $protected_lock_keys = array() ): array {
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
		$table               = self::table_name();
		$now                 = gmdate('Y-m-d H:i:s');
		$released_cutoff     = gmdate('Y-m-d H:i:s', time() - self::released_ttl_seconds());
		$protected_lock_keys = array_values(array_unique(array_filter(array_map('strval', $protected_lock_keys))));
		$protected_sql       = '';
		$protected_args      = array();
		if ( array() !== $protected_lock_keys ) {
			$protected_sql  = ' AND lock_key NOT IN (' . implode(', ', array_fill(0, count($protected_lock_keys), '%s')) . ')';
			$protected_args = $protected_lock_keys;
		}

	 // phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$mark_sql  = "UPDATE {$table} SET status = %s WHERE status = %s AND expires_at < %s{$protected_sql}";
		$mark_args = array_merge(array( 'stale', 'active', $now ), $protected_args);
		$wpdb->query(call_user_func_array(array( $wpdb, 'prepare' ), array_merge(array( $mark_sql ), $mark_args)));
		$marked = (int) $wpdb->rows_affected;

		$delete_sql  = "DELETE FROM {$table} WHERE status IN (%s, %s) AND COALESCE(released_at, expires_at) < %s{$protected_sql}";
		$delete_args = array_merge(array( 'released', 'stale', $released_cutoff ), $protected_args);
		$wpdb->query(call_user_func_array(array( $wpdb, 'prepare' ), array_merge(array( $delete_sql ), $delete_args)));
	 // phpcs:enable WordPress.DB.PreparedSQL
		$deleted = (int) $wpdb->rows_affected;

		return array(
			'available'           => true,
			'active_marked_stale' => $marked,
			'released_deleted'    => $deleted,
			'protected_active'    => count($protected_lock_keys),
			'protected_keys'      => $protected_lock_keys,
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

	/**
	 * Return distinct lock keys for active or expired active DB rows.
	 *
	 * @return array<int,string>
	 */
	private static function lock_keys_for_status( string $status, bool $expired ): array {
		global $wpdb;
		if ( ! is_object($wpdb) || ! is_callable(array( $wpdb, 'prepare' )) || ! is_callable(array( $wpdb, 'get_col' )) ) {
			return array();
		}

		$table    = self::table_name();
		$now      = gmdate('Y-m-d H:i:s');
		$operator = $expired ? '<' : '>=';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, operator is constant-selected above.
		$query = call_user_func(array( $wpdb, 'prepare' ), "SELECT DISTINCT lock_key FROM {$table} WHERE status = %s AND expires_at {$operator} %s", $status, $now);
		$keys  = call_user_func(array( $wpdb, 'get_col' ), $query);
		if ( ! is_array($keys) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map('strval', $keys),
				static fn( string $key ): bool => '' !== $key
			)
		);
	}

	/**
	 * Return lock rows for owner/session/age diagnostics.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function lock_rows_for_status( string $status, bool $expired ): array {
		global $wpdb;
		if ( ! is_object($wpdb) || ! is_callable(array( $wpdb, 'prepare' )) || ! is_callable(array( $wpdb, 'get_results' )) ) {
			return array();
		}

		$table    = self::table_name();
		$now      = gmdate('Y-m-d H:i:s');
		$operator = $expired ? '<' : '>=';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, operator is constant-selected above.
		$query = call_user_func(array( $wpdb, 'prepare' ), "SELECT id, lock_key, purpose, scope, owner, run_id, job_id, status, acquired_at, heartbeat_at, expires_at, released_at, metadata_json FROM {$table} WHERE status = %s AND expires_at {$operator} %s ORDER BY acquired_at DESC, id DESC LIMIT 25", $status, $now);
		$rows  = call_user_func(array( $wpdb, 'get_results' ), $query, ARRAY_A);
		if ( ! is_array($rows) ) {
			return array();
		}

		return array_values(array_map(static fn( array $row ): array => self::normalize_lock_row($row, $expired ? 'stale' : 'active'), $rows));
	}

	/**
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array<string,mixed>
	 */
	private static function normalize_lock_row( array $row, string $state ): array {
		$row['id']       = (int) ( $row['id'] ?? 0 );
		$row['job_id']   = isset($row['job_id']) ? (int) $row['job_id'] : null;
		$row['state']    = $state;
		$row['metadata'] = self::decode_metadata( (string) ( $row['metadata_json'] ?? '' ) );
		unset($row['metadata_json']);

		$acquired  = self::timestamp_seconds( (string) ( $row['acquired_at'] ?? '' ) );
		$heartbeat = self::timestamp_seconds( (string) ( $row['heartbeat_at'] ?? '' ) );
		$expires   = self::timestamp_seconds( (string) ( $row['expires_at'] ?? '' ) );
		$time      = time();
		if ( null !== $acquired ) {
			$row['age_seconds'] = max(0, $time - $acquired);
		}
		if ( null !== $heartbeat ) {
			$row['heartbeat_age_seconds'] = max(0, $time - $heartbeat);
		}
		if ( null !== $expires ) {
			$row['expires_age_seconds'] = 'stale' === $state ? max(0, $time - $expires) : 0;
			$row['expires_in_seconds']  = 'active' === $state ? max(0, $expires - $time) : 0;
		}

		return $row;
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

	public static function default_owner_context(): array {
		$context = array(
			'host' => function_exists('gethostname') ? (string) gethostname() : 'unknown-host',
			'pid'  => function_exists('getmypid') ? (string) getmypid() : 'unknown-pid',
		);
		// DMC's own namespace — these are not vendor leaks.
		$env_map = array(
			'datamachine_task_url'   => 'DATAMACHINE_TASK_URL',
			'datamachine_task_ref'   => 'DATAMACHINE_TASK_REF',
			'datamachine_agent'      => 'DATAMACHINE_AGENT',
			'datamachine_agent_name' => 'DATAMACHINE_AGENT_NAME',
		);
		foreach ( $env_map as $key => $env ) {
			$value = getenv($env);
			if ( false !== $value && '' !== trim( (string) $value) ) {
				$context[ $key ] = self::bounded_string( (string) $value, 300);
			}
		}

		// Runtime-specific session identifiers are NOT hardcoded here. Integration
		// layers (e.g. wp-coding-agents) declare which env vars to sniff and how to
		// project them into a runtime-keyed envelope via the
		// `datamachine_code_worktree_runtime_signatures` filter — the same registry
		// WorktreeContextInjector reads. DMC enumerates no runtime IDs and no
		// vendor-specific env-var names.
		$runtime_ids = self::resolve_runtime_ids();
		if ( ! empty($runtime_ids) ) {
			$context['runtime_ids'] = $runtime_ids;
		}

		if ( isset($_SERVER['argv']) && is_array($_SERVER['argv']) ) {
			$context['wp_cli_args'] = self::bounded_string(self::redact_secret_values(implode(' ', array_map('strval', $_SERVER['argv']))), 1000);
		}

		return $context;
	}

	/**
	 * Resolve runtime-specific session identifiers from registered signatures.
	 *
	 * Reads the `datamachine_code_worktree_runtime_signatures` filter (the same
	 * public contract WorktreeContextInjector consumes) and projects any env
	 * vars the surrounding runtime exposes into a runtime-keyed envelope:
	 *
	 *   array(
	 *       '<runtime-id>' => array( '<subkey>' => '<value>', ... ),
	 *   )
	 *
	 * DMC enumerates no runtime IDs and no vendor-specific env-var names; the
	 * integration layer declares both. Missing fields stay missing.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function resolve_runtime_ids(): array {
		if ( ! function_exists('apply_filters') ) {
			return array();
		}

		$signatures = apply_filters('datamachine_code_worktree_runtime_signatures', array());
		if ( ! is_array($signatures) ) {
			return array();
		}

		$runtime_ids = array();
		foreach ( $signatures as $runtime_id => $entry ) {
			if ( ! is_string($runtime_id) || '' === $runtime_id || ! is_array($entry) ) {
				continue;
			}
			$resolved = array();
			foreach ( $entry as $subkey => $env_var ) {
				if ( ! is_string($subkey) || '' === $subkey || ! is_string($env_var) || '' === $env_var ) {
					continue;
				}
				$value = getenv($env_var);
				if ( false === $value || '' === trim( (string) $value) ) {
					continue;
				}
				$resolved[ $subkey ] = self::bounded_string( (string) $value, 300);
			}
			if ( ! empty($resolved) ) {
				$runtime_ids[ $runtime_id ] = $resolved;
			}
		}

		return $runtime_ids;
	}

	private static function default_owner(): string {
		$context = self::default_owner_context();
		$host    = (string) ( $context['host'] ?? 'unknown-host' );
		$pid     = function_exists('getmypid') ? (string) getmypid() : 'unknown-pid';
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

	/**
	 * @return array<string,mixed>
	 */
	private static function decode_metadata( string $json ): array {
		$decoded = json_decode($json, true);
		return is_array($decoded) ? $decoded : array();
	}

	private static function timestamp_seconds( string $timestamp ): ?int {
		if ( '' === trim($timestamp) ) {
			return null;
		}

		$seconds = strtotime($timestamp . ' UTC');
		return false === $seconds ? null : (int) $seconds;
	}

	private static function redact_secret_values( string $value ): string {
		return (string) preg_replace('/(--(?:password|token|key|secret|authorization)(?:=|\s+))\S+/i', '$1[redacted]', $value);
	}
}
