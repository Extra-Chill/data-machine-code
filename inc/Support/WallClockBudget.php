<?php
/**
 * Shared monotonic wall-clock budget.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class WallClockBudget {

	/** @var callable():float */
	private $clock;

	private float $started_at;
	private float $deadline;

	/** @param callable():float|null $clock Monotonic seconds clock. */
	private function __construct( private string $label, private float $seconds, ?callable $clock = null ) {
		$this->clock      = $clock ?? static fn(): float => hrtime(true) / 1000000000;
		$this->started_at = $this->now();
		$this->deadline   = $this->started_at + $seconds;
	}

	/**
	 * Seconds for a compact duration such as 30s, 10m, or 1h.
	 *
	 * The single definition of what a duration means. Callers that need their
	 * own error code or a null-on-invalid contract wrap this rather than
	 * restating the grammar, so `--until-budget`, `--older-than`, and
	 * inventory pruning cannot drift apart.
	 *
	 * @return int|null Positive seconds, or null when malformed or non-positive.
	 */
	public static function parse_seconds( mixed $duration ): ?int {
		$label = is_scalar($duration) ? trim((string) $duration) : '';
		if ( '' === $label || ! preg_match('/^(\d+)([smh])$/', $label, $matches) ) {
			return null;
		}

		$value = (int) $matches[1];
		if ( $value < 1 ) {
			return null;
		}

		return $value * match ( $matches[2] ) {
			'h' => 3600,
			'm' => 60,
			default => 1,
		};
	}

	/** Build a budget from a compact duration such as 30s, 10m, or 1h. */
	public static function from_duration( mixed $duration, string $default, string $error_code = 'invalid_wall_clock_budget', ?callable $clock = null ): self|\WP_Error {
		$label   = is_scalar($duration) && '' !== trim((string) $duration) ? trim((string) $duration) : trim($default);
		$seconds = self::parse_seconds($label);
		if ( null === $seconds ) {
			return new \WP_Error($error_code, 'Invalid wall-clock budget. Use a compact value such as 30s, 10m, or 1h.', array( 'status' => 400 ));
		}

		return new self($label, (float) $seconds, $clock);
	}

	/** Build a budget from seconds for fixed internal command contracts. */
	public static function from_seconds( float $seconds, string $label = '', ?callable $clock = null ): self {
		$seconds = max(0.001, $seconds);
		return new self('' !== $label ? $label : rtrim(rtrim(sprintf('%.3F', $seconds), '0'), '.') . 's', $seconds, $clock);
	}

	public function expired(): bool {
		return $this->remaining_seconds() <= 0.0;
	}

	public function remaining_seconds(): float {
		return max(0.0, $this->deadline - $this->now());
	}

	/** Whole seconds safe to pass to a child process where zero means unbounded. */
	public function probe_timeout_seconds( int $maximum ): int {
		$remaining = (int) floor($this->remaining_seconds());
		return $remaining < 1 ? 0 : min(max(1, $maximum), $remaining);
	}

	/** Remaining compact duration for a nested stage, or null when no second remains. */
	public function remaining_duration(): ?string {
		$remaining = (int) floor($this->remaining_seconds());
		return $remaining < 1 ? null : $remaining . 's';
	}

	/** @return array<string,mixed> */
	public function evidence(): array {
		$elapsed = max(0.0, $this->now() - $this->started_at);
		return array(
			'label'             => $this->label,
			'budget_seconds'    => $this->seconds,
			'elapsed_ms'        => (int) round($elapsed * 1000),
			'remaining_ms'      => (int) floor($this->remaining_seconds() * 1000),
			'budget_exhausted'  => $this->expired(),
			'clock'             => 'monotonic',
		);
	}

	private function now(): float {
		return (float) ( $this->clock )();
	}
}
