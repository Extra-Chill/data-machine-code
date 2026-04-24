<?php
/**
 * Worktree Bootstrapper
 *
 * `git worktree add` intentionally only replays git-tracked state. That leaves
 * a new checkout missing anything non-tracked the repo needs before tests or
 * builds can run — submodules (tracked but not auto-init'd), `node_modules`,
 * Composer vendor dir, etc. Users hit silent failure modes like "vitest can't
 * load spec" or "sh: nx: command not found".
 *
 * This class implements the opt-in `--bootstrap` step: detect what the repo
 * declares it needs (via standard lockfiles + `.gitmodules`), run each step,
 * and report structured results so callers can surface a clear status line.
 *
 * Detection is convention-based, not configurable (yet — issue #50 proposes a
 * `.datamachine/worktree.yml` follow-up for repo-declared custom steps). Steps
 * run in a fixed order:
 *
 *   1. `git submodule update --init --recursive` if `.gitmodules` exists
 *   2. Package-manager install, based on lockfile presence:
 *        pnpm-lock.yaml   → pnpm install --frozen-lockfile
 *        bun.lockb/.lock  → bun install --frozen-lockfile
 *        yarn.lock        → yarn install --immutable
 *        package-lock.json → npm ci
 *   3. `composer install --no-interaction --prefer-dist` if `composer.lock` exists
 *
 * Each step is optional. Missing binaries (no `pnpm` on PATH, etc.) downgrade
 * to a `skipped` result rather than failing. Command failures are returned as
 * structured step results — the worktree itself stays created even if bootstrap
 * partially fails, and the CLI surfaces the failing step so the user can
 * decide whether to retry manually.
 *
 * No WordPress dependency so this class is unit-testable via pure PHP smokes.
 *
 * @package DataMachineCode\Workspace
 * @since   0.8.0
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

final class WorktreeBootstrapper {

	/**
	 * Step kinds in the order they are executed.
	 */
	public const STEP_SUBMODULES = 'submodules';
	public const STEP_PACKAGES   = 'packages';
	public const STEP_COMPOSER   = 'composer';

	/**
	 * Status values reported per step.
	 */
	public const STATUS_RAN     = 'ran';      // Command executed and exited 0.
	public const STATUS_SKIPPED = 'skipped';  // No trigger file, or tool unavailable.
	public const STATUS_FAILED  = 'failed';   // Command executed but exited non-zero.

	/**
	 * Output size cap (bytes) retained per step. Bootstrap installs can emit
	 * tens of megabytes of log noise; we keep the tail for diagnostics only.
	 */
	private const OUTPUT_CAP_BYTES = 4096;

	/**
	 * Run all applicable bootstrap steps inside the given worktree.
	 *
	 * @param string $worktree_path Absolute path to the worktree root.
	 * @return array{
	 *     success: bool,
	 *     ran_any: bool,
	 *     steps: array<int, array{
	 *         step: string,
	 *         status: string,
	 *         reason?: string,
	 *         command?: string,
	 *         exit_code?: int,
	 *         output_tail?: string,
	 *     }>,
	 * }
	 */
	public static function bootstrap( string $worktree_path ): array {
		$steps = array();

		$steps[] = self::run_submodules( $worktree_path );
		$steps[] = self::run_packages( $worktree_path );
		$steps[] = self::run_composer( $worktree_path );

		$failed  = array_filter( $steps, fn( $s ) => self::STATUS_FAILED === ( $s['status'] ?? '' ) );
		$ran_any = (bool) array_filter( $steps, fn( $s ) => self::STATUS_RAN === ( $s['status'] ?? '' ) );

		return array(
			'success' => empty( $failed ),
			'ran_any' => $ran_any,
			'steps'   => $steps,
		);
	}

	/**
	 * Detect which bootstrap steps WOULD run for a given worktree path, without
	 * executing anything. Useful for diagnostics and for the smoke test.
	 *
	 * @param string $worktree_path Absolute path to the worktree root.
	 * @return array{
	 *     submodules: bool,
	 *     packages: ?string,  // Package manager slug ("pnpm", "bun", "yarn", "npm") or null.
	 *     composer: bool,
	 * }
	 */
	public static function detect( string $worktree_path ): array {
		return array(
			'submodules' => is_file( rtrim( $worktree_path, '/' ) . '/.gitmodules' ),
			'packages'   => self::detect_package_manager( $worktree_path ),
			'composer'   => is_file( rtrim( $worktree_path, '/' ) . '/composer.lock' ),
		);
	}

	/**
	 * Pretty-print a bootstrap result as a multi-line human-readable block.
	 *
	 * @param array $result Result from {@see self::bootstrap()}.
	 * @return string
	 */
	public static function format( array $result ): string {
		$steps = is_array( $result['steps'] ?? null ) ? $result['steps'] : array();
		if ( empty( $steps ) ) {
			return 'Bootstrap: no steps attempted.';
		}

		$lines = array();
		foreach ( $steps as $step ) {
			$kind   = (string) ( $step['step'] ?? '?' );
			$status = (string) ( $step['status'] ?? '?' );
			$reason = (string) ( $step['reason'] ?? '' );
			$cmd    = (string) ( $step['command'] ?? '' );

			switch ( $status ) {
				case self::STATUS_RAN:
					$lines[] = sprintf( '  ✓ %-10s ran: %s', $kind, $cmd );
					break;
				case self::STATUS_SKIPPED:
					$lines[] = sprintf( '  - %-10s skipped (%s)', $kind, '' !== $reason ? $reason : 'no trigger' );
					break;
				case self::STATUS_FAILED:
					$exit    = isset( $step['exit_code'] ) ? (int) $step['exit_code'] : -1;
					$lines[] = sprintf( '  ✗ %-10s FAILED (exit %d): %s', $kind, $exit, $cmd );
					if ( ! empty( $step['output_tail'] ) ) {
						foreach ( explode( "\n", (string) $step['output_tail'] ) as $out_line ) {
							$lines[] = '      ' . $out_line;
						}
					}
					break;
				default:
					$lines[] = sprintf( '  ? %-10s %s', $kind, $status );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Run `git submodule update --init --recursive` if `.gitmodules` is present.
	 */
	private static function run_submodules( string $worktree_path ): array {
		if ( ! is_file( rtrim( $worktree_path, '/' ) . '/.gitmodules' ) ) {
			return array(
				'step'   => self::STEP_SUBMODULES,
				'status' => self::STATUS_SKIPPED,
				'reason' => 'no .gitmodules',
			);
		}

		if ( ! self::binary_available( 'git' ) ) {
			return array(
				'step'   => self::STEP_SUBMODULES,
				'status' => self::STATUS_SKIPPED,
				'reason' => 'git not on PATH',
			);
		}

		return self::run_command(
			self::STEP_SUBMODULES,
			$worktree_path,
			'git submodule update --init --recursive'
		);
	}

	/**
	 * Run the detected package manager's install command, if any.
	 */
	private static function run_packages( string $worktree_path ): array {
		$pm = self::detect_package_manager( $worktree_path );
		if ( null === $pm ) {
			return array(
				'step'   => self::STEP_PACKAGES,
				'status' => self::STATUS_SKIPPED,
				'reason' => 'no lockfile',
			);
		}

		if ( ! self::binary_available( $pm ) ) {
			return array(
				'step'   => self::STEP_PACKAGES,
				'status' => self::STATUS_SKIPPED,
				'reason' => sprintf( '%s not on PATH (lockfile present)', $pm ),
			);
		}

		$command = match ( $pm ) {
			'pnpm'  => 'pnpm install --frozen-lockfile',
			'bun'   => 'bun install --frozen-lockfile',
			'yarn'  => 'yarn install --immutable',
			'npm'   => 'npm ci',
			default => '',
		};

		if ( '' === $command ) {
			return array(
				'step'   => self::STEP_PACKAGES,
				'status' => self::STATUS_SKIPPED,
				'reason' => sprintf( 'unsupported package manager %s', $pm ),
			);
		}

		return self::run_command( self::STEP_PACKAGES, $worktree_path, $command );
	}

	/**
	 * Run `composer install` if `composer.lock` is present.
	 */
	private static function run_composer( string $worktree_path ): array {
		if ( ! is_file( rtrim( $worktree_path, '/' ) . '/composer.lock' ) ) {
			return array(
				'step'   => self::STEP_COMPOSER,
				'status' => self::STATUS_SKIPPED,
				'reason' => 'no composer.lock',
			);
		}

		if ( ! self::binary_available( 'composer' ) ) {
			return array(
				'step'   => self::STEP_COMPOSER,
				'status' => self::STATUS_SKIPPED,
				'reason' => 'composer not on PATH',
			);
		}

		return self::run_command(
			self::STEP_COMPOSER,
			$worktree_path,
			'composer install --no-interaction --prefer-dist'
		);
	}

	/**
	 * Detect the active package manager for a checkout based on lockfile
	 * presence. Order: pnpm > bun > yarn > npm. A repo with multiple lockfiles
	 * picks whichever is highest-priority — this matches the convention most
	 * tooling (corepack, package-manager-detector) uses.
	 *
	 * Returns null if no supported lockfile is present, including when
	 * `package.json` exists alone (no lockfile → we can't run a reproducible
	 * install, so we skip rather than guess).
	 *
	 * @return string|null One of: "pnpm", "bun", "yarn", "npm", or null.
	 */
	private static function detect_package_manager( string $worktree_path ): ?string {
		$root = rtrim( $worktree_path, '/' );
		if ( is_file( $root . '/pnpm-lock.yaml' ) ) {
			return 'pnpm';
		}
		// Bun supports both the binary lockb and the text lock file.
		if ( is_file( $root . '/bun.lockb' ) || is_file( $root . '/bun.lock' ) ) {
			return 'bun';
		}
		if ( is_file( $root . '/yarn.lock' ) ) {
			return 'yarn';
		}
		if ( is_file( $root . '/package-lock.json' ) ) {
			return 'npm';
		}
		return null;
	}

	/**
	 * Is a binary available on PATH? Uses `command -v` which is portable across
	 * bash/zsh/dash — `which` is not POSIX.
	 */
	private static function binary_available( string $binary ): bool {
		if ( '' === $binary || ! preg_match( '/^[a-zA-Z0-9_.\-]+$/', $binary ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( 'command -v %s 2>/dev/null', escapeshellarg( $binary ) ), $_unused, $exit );
		return 0 === $exit;
	}

	/**
	 * Execute a command inside the worktree and capture a result envelope.
	 *
	 * Note: we do not shell-escape the command itself — these are hard-coded
	 * invocations, not user input. The `cd` target is escaped.
	 */
	private static function run_command( string $step, string $worktree_path, string $command ): array {
		$cd = escapeshellarg( $worktree_path );
		$shell_cmd = sprintf( 'cd %s && %s 2>&1', $cd, $command );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $shell_cmd, $output, $exit_code );

		$joined = implode( "\n", $output );
		if ( strlen( $joined ) > self::OUTPUT_CAP_BYTES ) {
			$joined = '...' . substr( $joined, -1 * self::OUTPUT_CAP_BYTES );
		}

		if ( 0 !== $exit_code ) {
			return array(
				'step'        => $step,
				'status'      => self::STATUS_FAILED,
				'command'     => $command,
				'exit_code'   => $exit_code,
				'output_tail' => $joined,
			);
		}

		return array(
			'step'        => $step,
			'status'      => self::STATUS_RAN,
			'command'     => $command,
			'exit_code'   => 0,
			'output_tail' => $joined,
		);
	}
}
