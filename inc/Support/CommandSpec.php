<?php
/**
 * Command Spec
 *
 * Explicit argv-based process contract for command execution that should not be
 * interpreted by a shell.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class CommandSpec {

	/** @var list<string> */
	private array $argv;

	/** @var string|null */
	private ?string $cwd;

	/** @var array<string,string>|null */
	private ?array $env;

	/**
	 * @param list<string>               $argv Command argv. The first item is the executable.
	 * @param array{cwd?: string|null, env?: array<string,mixed>|null} $options Execution policy.
	 */
	private function __construct( array $argv, array $options = array() ) {
		$this->argv = $argv;
		$this->cwd  = isset($options['cwd']) && is_string($options['cwd']) && '' !== $options['cwd'] ? $options['cwd'] : null;
		$this->env  = isset($options['env']) && is_array($options['env']) ? self::normalize_env($options['env']) : null;
	}

	/**
	 * Build a command spec from argv tokens.
	 *
	 * @param  array<int,mixed>          $argv Command argv. The first item is the executable.
	 * @param  array{cwd?: string|null, env?: array<string,mixed>|null} $options Execution policy.
	 * @return self|\WP_Error
	 */
	public static function from_argv( array $argv, array $options = array() ): self|\WP_Error {
		$normalized = array();
		foreach ( $argv as $arg ) {
			if ( ! is_scalar($arg) ) {
				return new \WP_Error('invalid_command_spec', 'Command argv entries must be scalar strings.', array( 'status' => 400 ));
			}

			$arg = (string) $arg;
			if ( str_contains($arg, "\0") ) {
				return new \WP_Error('invalid_command_spec', 'Command argv entries must not contain null bytes.', array( 'status' => 400 ));
			}

			$normalized[] = $arg;
		}

		if ( array() === $normalized || '' === $normalized[0] ) {
			return new \WP_Error('invalid_command_spec', 'Command argv must include an executable.', array( 'status' => 400 ));
		}

		return new self($normalized, $options);
	}

	/**
	 * @return list<string>
	 */
	public function argv(): array {
		return $this->argv;
	}

	public function cwd(): ?string {
		return $this->cwd;
	}

	/**
	 * @return array<string,string>|null
	 */
	public function env(): ?array {
		return $this->env;
	}

	/**
	 * @return array<string,string>|null
	 */
	private static function normalize_env( array $env ): ?array {
		$normalized = array();
		foreach ( $env as $key => $value ) {
			if ( is_scalar($value) ) {
				$normalized[ (string) $key ] = (string) $value;
			}
		}

		return array() === $normalized ? null : $normalized;
	}
}
