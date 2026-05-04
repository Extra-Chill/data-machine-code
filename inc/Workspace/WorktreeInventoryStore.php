<?php
/**
 * DB-backed worktree inventory store.
 *
 * Mirrors current lifecycle metadata into a first-class DMC table so worktree
 * ownership and session liveness can be queried without treating filesystem
 * metadata as the coordination layer.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeInventoryStore {

	/**
	 * Schema version for the DMC worktree inventory table.
	 */
	private const SCHEMA_VERSION = '2026050401';

	/**
	 * Option key that records the installed inventory schema version.
	 */
	private const SCHEMA_OPTION = 'datamachine_code_worktree_inventory_schema_version';

	/**
	 * Resolve the worktree inventory table name.
	 */
	public static function table_name(): ?string {
		global $wpdb;

		if ( ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			return null;
		}

		return $wpdb->prefix . 'datamachine_code_worktrees';
	}

	/**
	 * Check whether WordPress database APIs are available.
	 */
	public static function is_available(): bool {
		global $wpdb;

		return is_object( $wpdb ) && method_exists( $wpdb, 'replace' ) && method_exists( $wpdb, 'get_row' );
	}

	/**
	 * Create/update the inventory table when the DB migration surface exists.
	 */
	public static function ensure_schema(): bool {
		global $wpdb;

		$table = self::table_name();
		if ( null === $table || ! is_object( $wpdb ) ) {
			return false;
		}

		if ( self::table_exists() ) {
			return true;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) . '/wp-admin/includes/upgrade.php' : '';
			if ( '' !== $upgrade && is_file( $upgrade ) ) {
				require_once $upgrade;
			}
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			return false;
		}

		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			handle varchar(191) NOT NULL,
			repo varchar(191) NOT NULL DEFAULT '',
			branch varchar(255) DEFAULT NULL,
			path text NULL,
			primary_path text NULL,
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			lifecycle_state varchar(64) DEFAULT NULL,
			origin_site text NULL,
			origin_agent varchar(191) DEFAULT NULL,
			origin_session text NULL,
			task_url text NULL,
			task_ref varchar(255) DEFAULT NULL,
			pr_url text NULL,
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
			metadata longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY handle (handle),
			KEY repo (repo),
			KEY lifecycle_state (lifecycle_state),
			KEY last_seen_at (last_seen_at),
			KEY cleanup_signal (cleanup_signal)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( function_exists( 'update_option' ) ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		}

		return self::table_exists();
	}

	/**
	 * Persist a worktree metadata row into the inventory table.
	 *
	 * @param string              $handle   Workspace handle.
	 * @param array<string,mixed> $metadata Lifecycle metadata.
	 */
	public static function upsert_metadata( string $handle, array $metadata ): bool {
		global $wpdb;

		$table = self::table_name();
		if ( null === $table || ! self::is_available() ) {
			return false;
		}

		if ( ! self::table_exists() && ! self::ensure_schema() ) {
			return false;
		}

		$row = self::metadata_to_row( $handle, $metadata );
		return false !== $wpdb->replace(
			$table,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch metadata from the DB inventory table.
	 */
	public static function get_metadata( string $handle ): ?array {
		global $wpdb;

		$table = self::table_name();
		if ( null === $table || ! self::is_available() || ! self::table_exists() ) {
			return null;
		}

		$output_type = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		$row         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE handle = %s", $handle ), $output_type );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::row_to_metadata( $row );
	}

	/**
	 * Delete an inventory row.
	 */
	public static function delete( string $handle ): bool {
		global $wpdb;

		$table = self::table_name();
		if ( null === $table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) || ! self::table_exists() ) {
			return false;
		}

		return false !== $wpdb->delete( $table, array( 'handle' => $handle ), array( '%s' ) );
	}

	/**
	 * Determine whether the inventory table currently exists.
	 */
	private static function table_exists(): bool {
		global $wpdb;

		$table = self::table_name();
		if ( null === $table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Convert lifecycle metadata to a typed inventory row.
	 *
	 * @param string              $handle   Workspace handle.
	 * @param array<string,mixed> $metadata Lifecycle metadata.
	 * @return array<string,mixed>
	 */
	private static function metadata_to_row( string $handle, array $metadata ): array {
		$task    = is_array( $metadata['origin_task'] ?? null ) ? $metadata['origin_task'] : array();
		$session = is_array( $metadata['origin_session'] ?? null ) ? $metadata['origin_session'] : array();
		$handle_parts = explode( '@', $handle, 2 );
		$repo         = (string) ( $metadata['repo'] ?? $handle_parts[0] );

		return array(
			'handle'              => $handle,
			'repo'                => $repo,
			'branch'              => isset( $metadata['branch'] ) ? (string) $metadata['branch'] : null,
			'path'                => isset( $metadata['path'] ) ? (string) $metadata['path'] : null,
			'primary_path'        => isset( $metadata['primary_path'] ) ? (string) $metadata['primary_path'] : null,
			'is_primary'          => str_contains( $handle, '@' ) ? 0 : 1,
			'lifecycle_state'     => isset( $metadata['lifecycle_state'] ) ? (string) $metadata['lifecycle_state'] : null,
			'origin_site'         => isset( $metadata['origin_site'] ) ? (string) $metadata['origin_site'] : null,
			'origin_agent'        => isset( $metadata['origin_agent'] ) ? (string) $metadata['origin_agent'] : null,
			'origin_session'      => empty( $session ) ? null : self::encode_json( $session ),
			'task_url'            => isset( $task['task_url'] ) ? (string) $task['task_url'] : null,
			'task_ref'            => isset( $task['task_ref'] ) ? (string) $task['task_ref'] : null,
			'pr_url'              => isset( $metadata['pr_url'] ) ? (string) $metadata['pr_url'] : null,
			'created_at'          => self::mysql_datetime( $metadata['created_at'] ?? null ),
			'last_seen_at'        => self::mysql_datetime( $metadata['last_seen_at'] ?? null ),
			'last_probe_at'       => self::mysql_datetime( $metadata['last_probe_at'] ?? null ),
			'last_probe_status'   => isset( $metadata['last_probe_status'] ) ? (string) $metadata['last_probe_status'] : null,
			'dirty_count'         => isset( $metadata['dirty_count'] ) ? (int) $metadata['dirty_count'] : null,
			'unpushed_count'      => isset( $metadata['unpushed_count'] ) ? (int) $metadata['unpushed_count'] : null,
			'artifact_count'      => isset( $metadata['artifact_count'] ) ? (int) $metadata['artifact_count'] : null,
			'artifact_size_bytes' => isset( $metadata['artifact_size_bytes'] ) ? (int) $metadata['artifact_size_bytes'] : null,
			'size_bytes'          => isset( $metadata['size_bytes'] ) ? (int) $metadata['size_bytes'] : null,
			'cleanup_signal'      => WorktreeContextInjector::has_cleanup_signal( $metadata ) ? 'cleanup_eligible' : null,
			'metadata'            => self::encode_json( $metadata ),
			'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Convert an inventory row back to lifecycle metadata.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private static function row_to_metadata( array $row ): array {
		$metadata = self::decode_json( (string) ( $row['metadata'] ?? '' ) );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$metadata['handle'] = (string) ( $row['handle'] ?? ( $metadata['handle'] ?? '' ) );
		foreach ( array( 'repo', 'branch', 'path', 'primary_path', 'lifecycle_state', 'origin_site', 'origin_agent', 'task_url', 'task_ref', 'pr_url' ) as $key ) {
			if ( ! empty( $row[ $key ] ) && ! isset( $metadata[ $key ] ) ) {
				$metadata[ $key ] = $row[ $key ];
			}
		}

		if ( ! empty( $row['origin_session'] ) && ! isset( $metadata['origin_session'] ) ) {
			$session = self::decode_json( (string) $row['origin_session'] );
			if ( is_array( $session ) ) {
				$metadata['origin_session'] = $session;
			}
		}

		if ( ! isset( $metadata['origin_task'] ) && ( ! empty( $row['task_url'] ) || ! empty( $row['task_ref'] ) ) ) {
			$metadata['origin_task'] = array_filter(
				array(
					'task_url' => $row['task_url'] ?? null,
					'task_ref' => $row['task_ref'] ?? null,
				),
				fn( $value ) => null !== $value && '' !== $value
			);
		}

		foreach ( array( 'created_at', 'last_seen_at', 'last_probe_at' ) as $key ) {
			if ( ! empty( $row[ $key ] ) && ! isset( $metadata[ $key ] ) ) {
				$metadata[ $key ] = gmdate( 'c', strtotime( (string) $row[ $key ] ) );
			}
		}

		return $metadata;
	}

	/**
	 * Encode JSON with a WordPress fallback for standalone smoke tests.
	 */
	private static function encode_json( array $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $value );
		}

		return (string) json_encode( $value );
	}

	/**
	 * Decode JSON into an array.
	 */
	private static function decode_json( string $value ): ?array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Normalize an ISO timestamp to a MySQL UTC datetime.
	 */
	private static function mysql_datetime( mixed $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
