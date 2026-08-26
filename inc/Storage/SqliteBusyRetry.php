<?php
/**
 * Bounded retry support for SQLite's single-writer contention.
 *
 * @package DataMachineCode\Storage
 */

namespace DataMachineCode\Storage;

defined('ABSPATH') || exit;

final class SqliteBusyRetry {

	private const DEFAULT_MAX_WAIT_MS     = 1000;
	private const DEFAULT_INITIAL_WAIT_MS = 25;
	private const DEFAULT_MAX_DELAY_MS    = 250;
	private const WRITER_POLL_USEC        = 25000;

	/**
	 * Retry only a database operation which reports a transient SQLite busy/locked failure.
	 *
	 * @param callable():mixed $operation DB-only operation callback.
	 * @param array<string,mixed> $options Optional retry bounds and serialization policy.
	 * @return mixed|\WP_Error
	 */
	public static function run( string $operation_name, callable $operation, array $options = array() ): mixed {
		global $wpdb;

		$sqlite = self::is_sqlite($wpdb);
		if ( ! $sqlite && ( ! is_object($wpdb) || ! method_exists($wpdb, 'suppress_errors') ) ) {
			return $operation();
		}

		$default_max_wait_ms = isset($options['max_wait_ms']) ? max(1, (int) $options['max_wait_ms']) : self::DEFAULT_MAX_WAIT_MS;
		$max_wait_ms     = self::filtered_positive_int('datamachine_code_sqlite_busy_retry_max_wait_ms', $default_max_wait_ms);
		$initial_wait_ms = self::filtered_positive_int('datamachine_code_sqlite_busy_retry_initial_wait_ms', self::DEFAULT_INITIAL_WAIT_MS);
		$max_delay_ms    = self::filtered_positive_int('datamachine_code_sqlite_busy_retry_max_delay_ms', self::DEFAULT_MAX_DELAY_MS);
		$started_at      = hrtime(true);
		$attempts        = 0;
		$serialize       = ! array_key_exists('serialize', $options) || (bool) $options['serialize'];
		$restore_errors  = null;
		if ( is_object($wpdb) && method_exists($wpdb, 'suppress_errors') ) {
			$restore_errors = (bool) $wpdb->suppress_errors(true);
		}
		$output_level = ob_get_level();
		ob_start();

		try {
			$writer = null;
			if ( $sqlite && $serialize ) {
				$writer = self::acquire_writer($operation_name, $started_at, $max_wait_ms, $options);
				if ( $writer instanceof \WP_Error ) {
					return $writer;
				}
			}

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
					$result       = false;
				}

				$last_error = '' !== $busy_message ? $busy_message : (string) ( $wpdb->last_error ?? '' );
				if ( false !== $result || ! self::is_busy_error($last_error) ) {
					return $result;
				}
				if ( null === $writer && $serialize ) {
					$writer = self::acquire_writer($operation_name, $started_at, $max_wait_ms, $options);
					if ( $writer instanceof \WP_Error ) {
						return $writer;
					}
				}

				$elapsed_ms = (int) floor(( hrtime(true) - $started_at ) / 1000000);
				if ( $elapsed_ms >= $max_wait_ms ) {
					return new \WP_Error(
						'workspace_sqlite_lock_contention',
						'The workspace registry is queued behind a competing SQLite writer.',
						array(
							'status'              => 503,
							'retryable'           => true,
							'backend'             => 'sqlite',
							'operation'           => $operation_name,
							'blocker_phase'       => $operation_name,
							'attempts'            => $attempts,
							'waited_ms'           => $elapsed_ms,
							'max_wait_ms'         => $max_wait_ms,
							'retry_after_seconds' => 1,
							'queue_state'         => 'database_busy',
							'mutation_committed'  => false,
						)
					);
				}

				$delay_ms = min($max_delay_ms, $initial_wait_ms * ( 2 ** ( $attempts - 1 ) ));
				// Spread competing CLI processes without extending the configured budget.
				$jitter_ms = $delay_ms > 1 ? random_int(0, max(1, (int) floor($delay_ms / 4))) : 0;
				usleep( (int) min($delay_ms + $jitter_ms, max(1, $max_wait_ms - $elapsed_ms)) * 1000);
			} while ( true );
		} finally {
			if ( isset($writer) && is_array($writer) ) {
				self::release_writer($writer);
			}
			while ( ob_get_level() > $output_level ) {
				ob_end_clean();
			}
			if ( null !== $restore_errors ) {
				$wpdb->suppress_errors($restore_errors);
			}
		}
	}

	/** Acquire the process-shared SQLite writer boundary within the retry budget. */
	private static function acquire_writer( string $operation, int $started_at, int $max_wait_ms, array $options ): array|\WP_Error {
		$path = self::writer_lock_path($options);
		$dir  = dirname($path);
		if ( ! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic local lock setup is rechecked.
			return new \WP_Error('workspace_registry_writer_unavailable', 'The workspace registry writer lock could not be created.', array( 'status' => 500, 'retryable' => false, 'operation' => $operation, 'mutation_committed' => false ));
		}

		$handle = @fopen($path, 'c+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is returned as a typed fail-closed result.
		if ( false === $handle ) {
			return new \WP_Error('workspace_registry_writer_unavailable', 'The workspace registry writer lock could not be opened.', array( 'status' => 500, 'retryable' => false, 'operation' => $operation, 'mutation_committed' => false ));
		}

		$request_id = bin2hex(random_bytes(12));
		$blocker    = array();
		do {
			if ( flock($handle, LOCK_EX | LOCK_NB) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				$owner = array(
					'request_id'  => $request_id,
					'operation'   => $operation,
					'pid'         => getmypid(),
					'acquired_at' => gmdate('c'),
				);
				ftruncate($handle, 0);
				rewind($handle);
				fwrite($handle, (string) json_encode($owner, JSON_UNESCAPED_SLASHES));
				fflush($handle);
				return array( 'handle' => $handle, 'path' => $path, 'owner' => $owner );
			}

			$observed = self::read_writer_owner($handle);
			if ( array() !== $observed ) {
				$blocker = $observed;
			}
			$elapsed_ms = (int) floor(( hrtime(true) - $started_at ) / 1000000);
			if ( $elapsed_ms >= $max_wait_ms ) {
				fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error(
					'workspace_sqlite_lock_contention',
					'The workspace registry write is queued behind a competing SQLite writer.',
					array(
						'status'              => 503,
						'retryable'           => true,
						'backend'             => 'sqlite',
						'operation'           => $operation,
						'blocker_phase'       => 'workspace_registry_writer',
						'queue_state'         => 'queued',
						'request_id'          => $request_id,
						'blocker'             => array_filter(array(
							'request_id'  => $blocker['request_id'] ?? null,
							'operation'   => $blocker['operation'] ?? null,
							'pid'         => isset($blocker['pid']) ? (int) $blocker['pid'] : null,
							'acquired_at' => $blocker['acquired_at'] ?? null,
						), static fn( mixed $value ): bool => null !== $value && '' !== $value),
						'waited_ms'           => $elapsed_ms,
						'max_wait_ms'         => $max_wait_ms,
						'retry_after_seconds' => 1,
						'mutation_committed'  => false,
					)
				);
			}
			usleep((int) min(self::WRITER_POLL_USEC, max(1000, ( $max_wait_ms - $elapsed_ms ) * 1000)));
		} while ( true );
	}

	/** @param array{handle:resource,path:string,owner:array<string,mixed>} $writer */
	private static function release_writer( array $writer ): void {
		$handle = $writer['handle'];
		ftruncate($handle, 0);
		fflush($handle);
		flock($handle, LOCK_UN); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** Read only the compact owner record written while the flock is active. */
	private static function read_writer_owner( $handle ): array {
		rewind($handle);
		$payload = stream_get_contents($handle, 2048);
		$owner   = is_string($payload) ? json_decode($payload, true) : null;
		return is_array($owner) ? $owner : array();
	}

	/** Resolve one lock file shared by all registry users for this WordPress runtime. */
	private static function writer_lock_path( array $options ): string {
		if ( isset($options['lock_path']) && is_string($options['lock_path']) && '' !== trim($options['lock_path']) ) {
			return trim($options['lock_path']);
		}
		$workspace = defined('DATAMACHINE_WORKSPACE_PATH') ? rtrim((string) DATAMACHINE_WORKSPACE_PATH, '/') : '';
		if ( '' !== $workspace ) {
			$path = $workspace . '/.locks/workspace-registry-writer.lock';
		} else {
			$identity = ( defined('ABSPATH') ? (string) ABSPATH : __DIR__ ) . '|' . ( defined('DB_NAME') ? (string) DB_NAME : '' ) . '|' . (string) ( $GLOBALS['wpdb']->prefix ?? '' );
			$path     = rtrim(sys_get_temp_dir(), '/') . '/datamachine-code-registry-' . hash('sha256', $identity) . '.lock';
		}
		$path = function_exists('apply_filters') ? (string) apply_filters('datamachine_code_sqlite_registry_lock_path', $path) : $path;
		return '' !== trim($path) ? trim($path) : rtrim(sys_get_temp_dir(), '/') . '/datamachine-code-registry-fallback.lock';
	}

	/**
	 * Detect SQLite through the database driver's own exposed signals.
	 */
	public static function is_sqlite( mixed $database ): bool {
		if ( ! is_object($database) ) {
			return false;
		}

		$signals = array( get_class($database) );
		if ( method_exists($database, 'db_server_info') ) {
			try {
				$signals[] = (string) $database->db_server_info();
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- A failed optional capability probe is intentionally ignored.
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

	private static function filtered_positive_int( string $hook, int $default_value ): int {
		$value = function_exists('apply_filters') ? (int) apply_filters($hook, $default_value) : $default_value;
		return max(1, $value);
	}
}
