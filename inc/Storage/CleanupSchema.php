<?php
/**
 * Cleanup run database schema.
 *
 * @package DataMachineCode\Storage
 */

namespace DataMachineCode\Storage;

defined( 'ABSPATH' ) || exit;

class CleanupSchema {

	public const RUNS_TABLE  = 'datamachine_code_cleanup_runs';
	public const ITEMS_TABLE = 'datamachine_code_cleanup_items';

	/**
	 * Install or upgrade cleanup tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$runs_table      = self::runs_table();
		$items_table     = self::items_table();

		dbDelta(
			"CREATE TABLE {$runs_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(64) NOT NULL,
				mode varchar(64) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'planned',
				policy longtext NULL,
				requested_by_user_id bigint(20) unsigned NULL,
				requested_by_agent_id bigint(20) unsigned NULL,
				parent_job_id bigint(20) unsigned NULL,
				batch_job_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				started_at datetime NULL,
				completed_at datetime NULL,
				summary longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_id (run_id),
				KEY status (status),
				KEY mode (mode),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$items_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(64) NOT NULL,
				handle varchar(191) NOT NULL DEFAULT '',
				worktree_id bigint(20) unsigned NULL,
				item_type varchar(64) NOT NULL,
				planned_action varchar(64) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				reason_code varchar(96) NOT NULL DEFAULT '',
				reason text NULL,
				bytes_reclaimed bigint(20) unsigned NOT NULL DEFAULT 0,
				job_id bigint(20) unsigned NULL,
				chunk_index int unsigned NULL,
				planned_at datetime NOT NULL,
				applied_at datetime NULL,
				evidence longtext NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY run_status (run_id,status),
				KEY handle (handle),
				KEY job_id (job_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Resolve cleanup runs table name.
	 *
	 * @return string
	 */
	public static function runs_table(): string {
		global $wpdb;
		return ( $wpdb->prefix ?? '' ) . self::RUNS_TABLE;
	}

	/**
	 * Resolve cleanup items table name.
	 *
	 * @return string
	 */
	public static function items_table(): string {
		global $wpdb;
		return ( $wpdb->prefix ?? '' ) . self::ITEMS_TABLE;
	}
}
