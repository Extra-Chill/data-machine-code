<?php
/**
 * Bounded filesystem locking for database-independent workspace operations.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class StandaloneFileLock {

	/** @return resource|null */
	public static function acquire( string $path, float $timeout, int $sleep_microseconds ) {
		$handle = @fopen($path, 'c');
		if ( false === $handle ) {
			return null;
		}
		$started = microtime(true);
		do {
			if ( flock($handle, LOCK_EX | LOCK_NB) ) {
				return $handle;
			}
			usleep($sleep_microseconds);
		} while ( microtime(true) - $started < $timeout );
		fclose($handle);
		return null;
	}

	/** @param resource $handle */
	public static function release( $handle ): void {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
}
