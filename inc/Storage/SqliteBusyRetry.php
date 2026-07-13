<?php
/**
 * Bounded retry support for SQLite's single-writer contention.
 *
 * @package DataMachineCode\Storage
 */

namespace DataMachineCode\Storage;

defined('ABSPATH') || exit;

final class SqliteBusyRetry {

	private const DEFAULT_MAX_WAIT_MS = 1000;
	private const DEFAULT_INITIAL_WAIT_MS = 25;
	private const DEFAULT_MAX_DELAY_MS = 250;

	/**
	 * Retry only a SQLite write which reports a transient busy/locked failure.
	 *
	 * @param callable():mixed $operation DB-only mutation callback.
	 * @return mixed|\WP_Error
	 */
	public static function run( string $operation_name, callable $operation ): mixed {
		global $wpdb;

		if ( ! self::is_sqlite($wpdb) ) {
			return $operation();
		}

		$max_wait_ms     = self::filtered_positive_int('datamachine_code_sqlite_busy_retry_max_wait_ms', self::DEFAULT_MAX_WAIT_MS);
		$initial_wait_ms = self::filtered_positive_int('datamachine_code_sqlite_busy_retry_initial_wait_ms', self::DEFAULT_INITIAL_WAIT_MS);
		$max_delay_ms    = self::filtered_positive_int('datamachine_code_sqlite_busy_retry_max_delay_ms', self::DEFAULT_MAX_DELAY_MS);
		$started_at      = hrtime(true);
		$attempts        = 0;

		do {
			++$attempts;
			$busy_message = '';
			try {
				$result = $operation();
			} catch ( \Throwable $error ) {
				if ( ! self::is_busy_error($error->getMessage()) ) {
					throw $error;
				}
				$busy_message = $error->getMessage();
				$result = false;
			}

			if ( false !== $result || ! self::is_busy_error($busy_message ?: (string) ( $wpdb->last_error ?? '' )) ) {
				return $result;
			}

			$elapsed_ms = (int) floor(( hrtime(true) - $started_at ) / 1000000);
			if ( $elapsed_ms >= $max_wait_ms ) {
				return new \WP_Error(
					'workspace_sqlite_lock_contention',
					'SQLite remained locked while updating the Data Machine Code workspace registry. Retry this command after concurrent writers finish. MySQL is recommended for concurrent fleet workloads.',
					array(
						'status'              => 503,
						'backend'             => 'sqlite',
						'operation'           => $operation_name,
						'attempts'            => $attempts,
						'waited_ms'           => $elapsed_ms,
						'max_wait_ms'         => $max_wait_ms,
						'retry_after_seconds' => 1,
						'guidance'            => 'Retry after concurrent registry writers finish. Use MySQL for concurrent fleet cooking; SQLite remains supported for lower-concurrency workloads.',
					)
				);
			}

			$delay_ms = min($max_delay_ms, $initial_wait_ms * ( 2 ** ( $attempts - 1 ) ));
			// Spread competing CLI processes without extending the configured budget.
			$jitter_ms = $delay_ms > 1 ? random_int(0, max(1, (int) floor($delay_ms / 4))) : 0;
			usleep((int) min($delay_ms + $jitter_ms, max(1, $max_wait_ms - $elapsed_ms)) * 1000);
		} while ( true );
	}

	/**
	 * Detect SQLite through the database driver's own exposed signals.
	 */
	public static function is_sqlite( mixed $database ): bool {
		if ( ! is_object($database) ) {
			return false;
		}

		$signals = array(get_class($database));
		if ( method_exists($database, 'db_server_info') ) {
			try {
				$signals[] = (string) $database->db_server_info();
			} catch ( \Throwable ) {
				// A failed capability probe must not alter normal database behavior.
			}
		}
		foreach ( array( 'dbdriver', 'driver', 'db_type' ) as $property ) {
			if ( isset($database->{$property}) ) {
				$signals[] = (string) $database->{$property};
			}
		}

		foreach ( $signals as $signal ) {
			if ( str_contains(strtolower($signal), 'sqlite') ) {
				return true;
			}
		}

		return false;
	}

	private static function is_busy_error( string $message ): bool {
		$message = strtolower($message);
		return str_contains($message, 'database is locked') || str_contains($message, 'database is busy') || str_contains($message, 'sqlite_busy') || str_contains($message, 'sqlite_locked');
	}

	private static function filtered_positive_int( string $hook, int $default ): int {
		$value = function_exists('apply_filters') ? (int) apply_filters($hook, $default) : $default;
		return max(1, $value);
	}
}
