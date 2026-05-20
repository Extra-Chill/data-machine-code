<?php
/**
 * CLI channel registry.
 *
 * Generic configuration lookup for the CLI transport runtime. Channel
 * configurations map a channel identifier (e.g. the `channel` field of an
 * `agents/dispatch-message` invocation) to a command template that the
 * transport will execute via `proc_open` to deliver an outbound message.
 *
 * This class has zero knowledge of any specific transport. It is a pure
 * configuration layer that exposes whatever has been registered via either:
 *
 *  - the `datamachine_code_cli_channels` filter, or
 *  - the `datamachine_code_cli_channels` option.
 *
 * Filtered values win over the option value, but the filter receives the
 * option value as its starting input so callers can merge if they want.
 *
 * @package DataMachineCode\Channels
 * @since   0.43.0
 */

namespace DataMachineCode\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type ChannelConfig array{
 *     command: string,
 *     args: array<int, string>,
 *     detach?: bool,
 *     timeout?: int,
 *     env?: array<string, string>,
 *     cwd?: string|null,
 * }
 */
class CliChannelRegistry {

	/**
	 * Filter and option key used for the channel registry.
	 *
	 * @var string
	 */
	public const REGISTRY_KEY = 'datamachine_code_cli_channels';

	/**
	 * Return the full registered channel map.
	 *
	 * Invalid entries are silently dropped so a malformed entry can never
	 * cascade into the transport. Validation is intentionally minimal: the
	 * transport itself does not care what command it runs as long as the
	 * shape is right; site admins own the policy of what commands they
	 * register.
	 *
	 * @since 0.43.0
	 *
	 * @return array<string, array<string, mixed>> Channel name => config map.
	 */
	public static function get_channels(): array {
		$option_value = array();
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( self::REGISTRY_KEY, array() );
			if ( is_array( $raw ) ) {
				$option_value = $raw;
			}
		}

		$channels = $option_value;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the CLI channel registry map.
			 *
			 * Consumers register channel configurations here. Each entry must
			 * be a valid config array — see {@see CliChannelRegistry::normalize_entry()}.
			 *
			 * @since 0.43.0
			 *
			 * @param array<string, array<string, mixed>> $channels Existing registry.
			 */
			$filtered = apply_filters( self::REGISTRY_KEY, $channels );
			if ( is_array( $filtered ) ) {
				$channels = $filtered;
			}
		}

		$valid = array();
		foreach ( $channels as $name => $config ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( ! is_array( $config ) ) {
				continue;
			}
			$normalized = self::normalize_entry( $config );
			if ( null === $normalized ) {
				continue;
			}
			$valid[ $name ] = $normalized;
		}

		return $valid;
	}

	/**
	 * Look up a single channel by name.
	 *
	 * @since 0.43.0
	 *
	 * @param string $channel Channel identifier.
	 * @return array<string, mixed>|null Normalized config, or null if unknown / invalid.
	 */
	public static function lookup( string $channel ): ?array {
		if ( '' === $channel ) {
			return null;
		}

		$channels = self::get_channels();
		if ( ! isset( $channels[ $channel ] ) ) {
			return null;
		}

		return $channels[ $channel ];
	}

	/**
	 * Validate and normalize a single channel config entry.
	 *
	 * Returns the normalized array (with defaults applied) on success, or
	 * null when the entry is malformed enough that the transport could not
	 * reasonably execute it. The shape requirements are intentionally
	 * narrow:
	 *
	 *  - `command` must be a non-empty string.
	 *  - `args` must be an array of strings (empty allowed).
	 *  - `detach` defaults to true.
	 *  - `timeout` defaults to 30 seconds and is only meaningful when
	 *    `detach` is false.
	 *  - `env` defaults to an empty array.
	 *  - `cwd` defaults to null.
	 *
	 * @since 0.43.0
	 *
	 * @param array<string, mixed> $config Raw config entry.
	 * @return array<string, mixed>|null Normalized config or null if invalid.
	 */
	public static function normalize_entry( array $config ): ?array {
		$command = $config['command'] ?? null;
		if ( ! is_string( $command ) || '' === trim( $command ) ) {
			return null;
		}

		$args = $config['args'] ?? array();
		if ( ! is_array( $args ) ) {
			return null;
		}
		$normalized_args = array();
		foreach ( $args as $arg ) {
			if ( ! is_string( $arg ) ) {
				return null;
			}
			$normalized_args[] = $arg;
		}

		$detach = $config['detach'] ?? true;
		if ( ! is_bool( $detach ) ) {
			$detach = (bool) $detach;
		}

		$timeout = $config['timeout'] ?? 30;
		if ( ! is_int( $timeout ) || $timeout < 0 ) {
			$timeout = 30;
		}

		$env = $config['env'] ?? array();
		if ( ! is_array( $env ) ) {
			$env = array();
		}
		$normalized_env = array();
		foreach ( $env as $env_key => $env_value ) {
			if ( ! is_string( $env_key ) || '' === $env_key ) {
				continue;
			}
			if ( ! is_scalar( $env_value ) ) {
				continue;
			}
			$normalized_env[ $env_key ] = (string) $env_value;
		}

		$cwd = $config['cwd'] ?? null;
		if ( null !== $cwd && ( ! is_string( $cwd ) || '' === $cwd ) ) {
			$cwd = null;
		}

		return array(
			'command' => $command,
			'args'    => $normalized_args,
			'detach'  => $detach,
			'timeout' => $timeout,
			'env'     => $normalized_env,
			'cwd'     => $cwd,
		);
	}

	/**
	 * Substitute canonical tokens into an args array.
	 *
	 * Tokens are replaced inside each string argument via simple string
	 * replacement. The args list is then passed to `proc_open` as an array
	 * — there is no shell interpolation step, so a `{message}` containing
	 * shell metacharacters is delivered to the child process as a single
	 * argv entry, untouched.
	 *
	 * Recognized tokens: `{recipient}`, `{message}`, `{conversation_id}`,
	 * `{channel}`.
	 *
	 * Unknown tokens are left as-is. Missing input keys substitute the
	 * empty string.
	 *
	 * @since 0.43.0
	 *
	 * @param array<int, string>   $args  Template args.
	 * @param array<string, mixed> $input Canonical dispatch-message input.
	 * @return array<int, string> Args with tokens substituted.
	 */
	public static function substitute_tokens( array $args, array $input ): array {
		$replacements = array(
			'{recipient}'       => self::stringify( $input['recipient'] ?? '' ),
			'{message}'         => self::stringify( $input['message'] ?? '' ),
			'{conversation_id}' => self::stringify( $input['conversation_id'] ?? '' ),
			'{channel}'         => self::stringify( $input['channel'] ?? '' ),
		);

		$result = array();
		foreach ( $args as $arg ) {
			$result[] = strtr( $arg, $replacements );
		}
		return $result;
	}

	/**
	 * Convert a value to a string for token substitution.
	 *
	 * @param mixed $value Source value.
	 * @return string Stringified value.
	 */
	private static function stringify( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}
}
