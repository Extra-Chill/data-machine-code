<?php
/**
 * Repeatable WP-CLI option parser.
 *
 * @package DataMachineCode\Cli
 */

namespace DataMachineCode\Cli;

defined('ABSPATH') || exit;

final class CliRepeatableOptionParser {

	/**
	 * Collect every occurrence of a repeatable assoc flag from raw argv.
	 *
	 * WP-CLI's parsed assoc args are last-wins for repeated flags, so commands
	 * that accept repeatable options need to inspect raw argv to preserve order.
	 * Supports both `--flag=value` and `--flag value` forms.
	 *
	 * @param  string              $flag Flag name without leading `--`.
	 * @param  array<int,mixed>|null $argv Raw argv tokens. Defaults to `$GLOBALS['argv']`.
	 * @return string[] Values in argv order, with empty values omitted.
	 */
	public static function collect( string $flag, ?array $argv = null ): array {
		$argv = $argv ?? ( is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : array() );

		$flag       = ltrim($flag, '-');
		$long_flag  = '--' . $flag;
		$assignment = $long_flag . '=';
		$values     = array();
		$count      = count($argv);

		for ( $i = 0; $i < $count; ++$i ) {
			$token = $argv[ $i ];
			if ( ! is_string($token) ) {
				continue;
			}

			if ( 0 === strpos($token, $assignment) ) {
				$value = substr($token, strlen($assignment));
				if ( '' !== $value ) {
					$values[] = $value;
				}
				continue;
			}

			if ( $long_flag !== $token ) {
				continue;
			}

			$next = $argv[ $i + 1 ] ?? null;
			if ( ! is_string($next) || '' === $next || str_starts_with($next, '--') ) {
				continue;
			}

			$values[] = $next;
			++$i;
		}

		return $values;
	}
}
