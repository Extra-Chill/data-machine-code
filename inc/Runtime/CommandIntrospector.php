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
 * NOT only under WP-CLI. It only autoloads command classes when WP-CLI's base
 * command class is already present, then reflects over the class without
 * touching the live WP_CLI runner or constructing command instances.
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
		if ( ! class_exists('WP_CLI_Command', false) ) {
			return array();
		}

		// class_exists() triggers autoloading; once it returns true the
		// ReflectionClass constructor cannot throw, so no try/catch is needed.
		if ( ! class_exists($command_class) ) {
			return array();
		}

		$reflection  = new \ReflectionClass($command_class);
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
	 * Render a `a|b|c` pipe-list of the string-literal `match` arm keys inside a
	 * command method, in declaration order, de-duplicated.
	 *
	 * Some `@subcommand` methods dispatch a second operand (e.g. `workspace
	 * worktree <op>`) through an internal `match ( $operation ) { ... }` rather
	 * than separate annotated methods. The arm keys ARE the real operation
	 * surface, so reflecting them keeps AGENTS.md truthful without hand-typing
	 * the list (see Extra-Chill/data-machine-code#734).
	 *
	 * Falls back to the supplied default string when reflection yields nothing,
	 * so AGENTS.md never renders an empty command line.
	 *
	 * @param string $command_class Fully-qualified command class name.
	 * @param string $method        Method whose body holds the dispatch `match`.
	 * @param string $fallback      Pipe-list to use when reflection is unavailable.
	 * @return string
	 */
	public static function match_arm_pipe_list( string $command_class, string $method, string $fallback = '' ): string {
		$keys = self::match_arm_keys($command_class, $method);
		if ( empty($keys) ) {
			return $fallback;
		}

		return implode('|', $keys);
	}

	/**
	 * Extract the string-literal arm keys of the first `match` expression in a
	 * method body, in source order and de-duplicated.
	 *
	 * Reads the method's source range via reflection and scans the `match (...)`
	 * block for `'literal' =>` / `"literal" =>` arm keys (including comma-grouped
	 * keys mapping to one result). The `default =>` arm is ignored.
	 *
	 * Returns an empty array when the class/method/source is unavailable, so
	 * callers can fall back gracefully without fatals in any context.
	 *
	 * @param string $command_class Fully-qualified command class name.
	 * @param string $method        Method name to inspect.
	 * @return string[] Ordered, de-duplicated list of match arm keys.
	 */
	public static function match_arm_keys( string $command_class, string $method ): array {
		if ( ! class_exists('WP_CLI_Command', false) ) {
			return array();
		}

		// class_exists() triggers autoloading; once it returns true the
		// ReflectionClass constructor cannot throw, so no try/catch is needed.
		if ( ! class_exists($command_class) ) {
			return array();
		}

		$reflection = new \ReflectionClass($command_class);
		if ( ! $reflection->hasMethod($method) ) {
			return array();
		}

		$reflection_method = $reflection->getMethod($method);
		$file              = $reflection_method->getFileName();
		$start_line        = $reflection_method->getStartLine();
		$end_line          = $reflection_method->getEndLine();

		if ( false === $file || ! is_readable($file) || $start_line < 1 || $end_line < $start_line ) {
			return array();
		}

		$source_lines = file($file, FILE_IGNORE_NEW_LINES);
		if ( false === $source_lines ) {
			return array();
		}

		$body = implode("\n", array_slice($source_lines, $start_line - 1, ( $end_line - $start_line ) + 1));

		// Isolate the first `match (...)` block so we don't pick up unrelated
		// associative arrays elsewhere in the method body.
		if ( ! preg_match('/\bmatch\s*\(/', $body, $match_pos, PREG_OFFSET_CAPTURE) ) {
			return array();
		}
		$body = substr($body, (int) $match_pos[0][1]);

		// Each arm key is a single- or double-quoted literal immediately
		// preceding `=>` (comma-grouped keys each match individually).
		if ( ! preg_match_all('/([\'"])([^\'"\\\\]+)\1\s*=>/', $body, $arm_matches) ) {
			return array();
		}

		$keys = array();
		foreach ( $arm_matches[2] as $key ) {
			$key = trim($key);
			if ( '' === $key || in_array($key, $keys, true) ) {
				continue;
			}
			$keys[] = $key;
		}

		return $keys;
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
