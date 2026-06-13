<?php
/**
 * Reflection-based introspection of Data Machine Code WP-CLI command classes.
 *
 * AGENTS.md sections must describe the *real* command surface, not a hand-typed
 * pipe-list that silently drifts (see Extra-Chill/data-machine-code#671). This
 * helper reflects over the command classes' `@subcommand` annotations and PHPDoc
 * summaries to produce truthful subcommand lists.
 *
 * IMPORTANT: this is a DMC-local stopgap pending the shared, substrate-level
 * command-tree introspector tracked in Extra-Chill/data-machine#2613. Once that
 * lands, both this helper and the consuming AGENTS.md sections should migrate to
 * the shared implementation. Until then, keep this self-contained.
 *
 * Context safety: this runs on `plugins_loaded` in web/cron compose contexts,
 * NOT only under WP-CLI. It therefore reflects over autoloadable command CLASSES
 * (via ReflectionClass) and never touches the live WP_CLI runner. Reflecting a
 * class does not instantiate it, so command-class constructor dependencies are
 * never exercised.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

defined('ABSPATH') || exit;

final class CommandIntrospector {

	/**
	 * Reflect over a command class and return its subcommands as
	 * `[ 'name' => 'short description', ... ]`, preserving declaration order.
	 *
	 * Each public method annotated with `@subcommand <name>` becomes an entry.
	 * The description is the first non-tag line of the method's PHPDoc summary.
	 *
	 * Returns an empty array when the class is unavailable or has no subcommands,
	 * so callers can fall back gracefully without fatals in any context.
	 *
	 * @param string $command_class Fully-qualified command class name.
	 * @return array<string,string> Ordered map of subcommand => description.
	 */
	public static function subcommands( string $command_class ): array {
		if ( ! class_exists($command_class) ) {
			return array();
		}

		try {
			$reflection = new \ReflectionClass($command_class);
		} catch ( \Throwable $e ) {
			return array();
		}

		$subcommands = array();

		foreach ( $reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method ) {
			if ( $method->isStatic() || $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}

			$doc = $method->getDocComment();
			if ( false === $doc || '' === $doc ) {
				continue;
			}

			$name = self::extract_subcommand_name($doc);
			if ( '' === $name ) {
				continue;
			}

			$subcommands[ $name ] = self::extract_summary($doc);
		}

		return $subcommands;
	}

	/**
	 * Return only the subcommand names for a class, in declaration order.
	 *
	 * @param string $command_class Fully-qualified command class name.
	 * @return string[] Ordered list of subcommand names.
	 */
	public static function subcommand_names( string $command_class ): array {
		return array_keys(self::subcommands($command_class));
	}

	/**
	 * Render a `a|b|c` pipe-list of a class's subcommand names.
	 *
	 * Falls back to the supplied default string when reflection yields nothing,
	 * so AGENTS.md never renders an empty command line.
	 *
	 * @param string $command_class Fully-qualified command class name.
	 * @param string $fallback      Pipe-list to use when reflection is unavailable.
	 * @return string
	 */
	public static function pipe_list( string $command_class, string $fallback = '' ): string {
		$names = self::subcommand_names($command_class);
		if ( empty($names) ) {
			return $fallback;
		}

		return implode('|', $names);
	}

	/**
	 * Pull the `@subcommand <name>` value out of a PHPDoc block.
	 *
	 * @param string $doc Raw docblock text.
	 * @return string Subcommand name, or '' when not annotated.
	 */
	private static function extract_subcommand_name( string $doc ): string {
		if ( preg_match('/@subcommand\s+(\S+)/', $doc, $matches) ) {
			return trim($matches[1]);
		}

		return '';
	}

	/**
	 * Extract the first non-empty, non-tag summary line from a docblock.
	 *
	 * @param string $doc Raw docblock text.
	 * @return string Short description, or '' when none is present.
	 */
	private static function extract_summary( string $doc ): string {
		$lines = preg_split('/\r\n|\r|\n/', $doc);
		if ( false === $lines ) {
			return '';
		}

		foreach ( $lines as $line ) {
			$line = trim($line);
			$line = ltrim($line, '/*');
			$line = trim($line);

			if ( '' === $line ) {
				continue;
			}

			// Skip annotation/usage lines; we only want the prose summary.
			if ( '@' === $line[0] || '#' === $line[0] ) {
				continue;
			}

			return $line;
		}

		return '';
	}
}
