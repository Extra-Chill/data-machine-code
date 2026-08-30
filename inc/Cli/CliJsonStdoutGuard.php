<?php
/**
 * Protect machine-format CLI stdout from host PHP diagnostics.
 *
 * @package DataMachineCode\Cli
 */

namespace DataMachineCode\Cli;

defined('ABSPATH') || exit;

final class CliJsonStdoutGuard {

	/**
	 * Whether argv requests JSON for a namespaced CLI command.
	 *
	 * @param array<int,mixed>|null $argv Raw process arguments.
	 */
	public static function argv_requests_json( ?array $argv = null, string $command_token = 'datamachine-code' ): bool {
		$tokens = self::tokens($argv);
		return in_array($command_token, $tokens, true) && 'json' === self::format($tokens);
	}

	/**
	 * Redirect PHP diagnostics off stdout for JSON CLI requests.
	 *
	 * Human formats are left unchanged so notices remain visible. Host output
	 * emitted before this process can run cannot be rewritten.
	 *
	 * @param array<int,mixed>|null $argv Raw process arguments.
	 */
	public static function install( ?array $argv = null, string $command_token = 'datamachine-code' ): bool {
		if ( ! defined('WP_CLI') || ! WP_CLI ) {
			return false;
		}
		if ( ! self::argv_requests_json($argv, $command_token) ) {
			return false;
		}

		self::redirect_php_diagnostics();
		return true;
	}

	/**
	 * @param array<int,mixed>|null $argv Raw process arguments.
	 * @return list<string>
	 */
	private static function tokens( ?array $argv ): array {
		return array_values(array_map('strval', $argv ?? ( is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : array() )));
	}

	/**
	 * @param list<string> $tokens Argv tokens.
	 */
	private static function format( array $tokens ): ?string {
		$format = null;
		$count  = count($tokens);
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( str_starts_with($token, '--format=') ) {
				$format = substr($token, strlen('--format='));
				continue;
			}
			if ( '--format' !== $token ) {
				continue;
			}
			$next = $tokens[ $i + 1 ] ?? '';
			if ( '' === $next || str_starts_with($next, '--') ) {
				continue;
			}
			$format = $next;
			++$i;
		}

		return is_string($format) && '' !== $format ? $format : null;
	}

	private static function redirect_php_diagnostics(): void {
		$current = strtolower((string) ini_get('display_errors'));
		if ( in_array($current, array( '', '0', 'off', 'false', 'stderr' ), true) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- JSON stdout is a machine contract; diagnostics belong on stderr.
		ini_set('display_errors', 'stderr');
	}
}
