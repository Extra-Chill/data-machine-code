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
 *   2. Package-manager install per dependency root, based on lockfile presence:
 *        pnpm-lock.yaml   → pnpm install --frozen-lockfile
 *        bun.lockb/.lock  → bun install --frozen-lockfile
 *        yarn.lock        → yarn install --immutable
 *        package-lock.json → npm ci
 *   3. `composer install --no-interaction --prefer-dist` per dependency root
 *      if `composer.lock` exists
 *
 * Dependency roots include the worktree root plus one-level child directories
 * with lockfiles. This covers lightweight monorepos where each top-level
 * component is installable on its own without requiring repo-specific config.
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
	 * Directories that should never be treated as nested component roots.
	 */
	private const NESTED_ROOT_EXCLUDE_DIRS = array(
		'.git',
		'.github',
		'.claude',
		'.opencode',
		'node_modules',
		'vendor',
	);

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
		$steps   = array_merge( $steps, self::run_packages( $worktree_path ) );
		$steps   = array_merge( $steps, self::run_composer( $worktree_path ) );

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
	 *     packages: ?string,  // Root package manager slug or null.
	 *     composer: bool,
	 *     package_roots: array<int, array{path: string, relative: string, manager: string}>,
	 *     composer_roots: array<int, array{path: string, relative: string}>,
	 * }
	 */
	public static function detect( string $worktree_path ): array {
		$package_roots  = self::discover_package_roots( $worktree_path );
		$composer_roots = self::discover_composer_roots( $worktree_path );

		return array(
			'submodules'     => is_file( rtrim( $worktree_path, '/' ) . '/.gitmodules' ),
			'packages'       => self::detect_package_manager( $worktree_path ),
			'composer'       => is_file( rtrim( $worktree_path, '/' ) . '/composer.lock' ),
			'package_roots'  => $package_roots,
			'composer_roots' => $composer_roots,
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
			$target = (string) ( $step['relative'] ?? '' );
			$label  = '.' === $target || '' === $target ? $kind : sprintf( '%s[%s]', $kind, $target );
			$status = (string) ( $step['status'] ?? '?' );
			$reason = (string) ( $step['reason'] ?? '' );
			$cmd    = (string) ( $step['command'] ?? '' );

			switch ( $status ) {
				case self::STATUS_RAN:
					$lines[] = sprintf( '  ✓ %-18s ran: %s', $label, $cmd );
					break;
				case self::STATUS_SKIPPED:
					$lines[] = sprintf( '  - %-18s skipped (%s)', $label, '' !== $reason ? $reason : 'no trigger' );
					break;
				case self::STATUS_FAILED:
					$exit    = isset( $step['exit_code'] ) ? (int) $step['exit_code'] : -1;
					$lines[] = sprintf( '  ✗ %-18s FAILED (exit %d): %s', $label, $exit, $cmd );
					if ( ! empty( $step['output_tail'] ) ) {
						foreach ( explode( "\n", (string) $step['output_tail'] ) as $out_line ) {
							$lines[] = '      ' . $out_line;
						}
					}
					break;
				default:
					$lines[] = sprintf( '  ? %-18s %s', $label, $status );
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
		$roots = self::discover_package_roots( $worktree_path );
		if ( empty( $roots ) ) {
			return array(
				array(
					'step'   => self::STEP_PACKAGES,
					'status' => self::STATUS_SKIPPED,
					'reason' => 'no lockfile',
				),
			);
		}

		$steps = array();
		foreach ( $roots as $root ) {
			$pm = $root['manager'];

			if ( ! self::binary_available( $pm ) ) {
				$steps[] = array(
					'step'     => self::STEP_PACKAGES,
					'status'   => self::STATUS_SKIPPED,
					'reason'   => sprintf( '%s not on PATH (lockfile present)', $pm ),
					'relative' => $root['relative'],
				);
				continue;
			}

			$command = match ( $pm ) {
				'pnpm'  => 'pnpm install --frozen-lockfile',
				'bun'   => 'bun install --frozen-lockfile',
				'yarn'  => 'yarn install --immutable',
				'npm'   => 'npm ci',
				default => '',
			};

			if ( '' === $command ) {
				$steps[] = array(
					'step'     => self::STEP_PACKAGES,
					'status'   => self::STATUS_SKIPPED,
					'reason'   => sprintf( 'unsupported package manager %s', $pm ),
					'relative' => $root['relative'],
				);
				continue;
			}

			$steps[] = self::run_command( self::STEP_PACKAGES, $root['path'], $command, $root['relative'] );
		}

		return $steps;
	}

	/**
	 * Run `composer install` if `composer.lock` is present.
	 */
	private static function run_composer( string $worktree_path ): array {
		$roots = self::discover_composer_roots( $worktree_path );
		if ( empty( $roots ) ) {
			return array(
				array(
					'step'   => self::STEP_COMPOSER,
					'status' => self::STATUS_SKIPPED,
					'reason' => 'no composer.lock',
				),
			);
		}

		$steps = array();
		foreach ( $roots as $root ) {
			if ( ! self::binary_available( 'composer' ) ) {
				$steps[] = array(
					'step'     => self::STEP_COMPOSER,
					'status'   => self::STATUS_SKIPPED,
					'reason'   => 'composer not on PATH',
					'relative' => $root['relative'],
				);
				continue;
			}

			$steps[] = self::run_command(
				self::STEP_COMPOSER,
				$root['path'],
				'composer install --no-interaction --prefer-dist',
				$root['relative']
			);
		}

		return $steps;
	}

	/**
	 * Discover package-manager roots at the repo root and one directory deep.
	 *
	 * @return array<int, array{path: string, relative: string, manager: string}>
	 */
	private static function discover_package_roots( string $worktree_path ): array {
		$roots = array();
		foreach ( self::candidate_dependency_roots( $worktree_path ) as $candidate ) {
			$manager = self::detect_package_manager( $candidate['path'] );
			if ( null === $manager ) {
				continue;
			}
			$roots[] = array(
				'path'     => $candidate['path'],
				'relative' => $candidate['relative'],
				'manager'  => $manager,
			);
		}
		return $roots;
	}

	/**
	 * Discover Composer roots at the repo root and one directory deep.
	 *
	 * @return array<int, array{path: string, relative: string}>
	 */
	private static function discover_composer_roots( string $worktree_path ): array {
		$roots = array();
		foreach ( self::candidate_dependency_roots( $worktree_path ) as $candidate ) {
			if ( ! is_file( $candidate['path'] . '/composer.lock' ) ) {
				continue;
			}
			$roots[] = $candidate;
		}
		return $roots;
	}

	/**
	 * Return the repo root plus one-level child directories that may own deps.
	 *
	 * @return array<int, array{path: string, relative: string}>
	 */
	private static function candidate_dependency_roots( string $worktree_path ): array {
		$root       = rtrim( $worktree_path, '/' );
		$candidates = array(
			array(
				'path'     => $root,
				'relative' => '.',
			),
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable roots are skipped as non-candidates below.
		$entries = @scandir( $root );
		if ( false === $entries ) {
			return $candidates;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || in_array( $entry, self::NESTED_ROOT_EXCLUDE_DIRS, true ) ) {
				continue;
			}
			$path = $root . '/' . $entry;
			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}
			$candidates[] = array(
				'path'     => $path,
				'relative' => $entry,
			);
		}

		return $candidates;
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
		$env = self::shell_env_prefix();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( '%scommand -v %s 2>/dev/null', $env, escapeshellarg( $binary ) ), $_unused, $exit );
		return 0 === $exit;
	}

	/**
	 * Build a shell env prefix with non-interactive toolchain path fallbacks.
	 */
	private static function shell_env_prefix(): string {
		$path = self::augmented_path();
		if ( null === $path ) {
			return '';
		}
		return sprintf( 'PATH=%s ', escapeshellarg( $path ) );
	}

	/**
	 * Add common nvm binary dirs for shells that do not source .zshrc/.bashrc.
	 */
	private static function augmented_path(): ?string {
		$current = getenv( 'PATH' );
		$current = is_string( $current ) ? $current : '';
		$extra   = self::discover_nvm_bin_dirs();
		if ( empty( $extra ) ) {
			return null;
		}

		$parts = array_filter( explode( PATH_SEPARATOR, $current ), static fn( $part ) => '' !== $part );
		foreach ( array_reverse( $extra ) as $dir ) {
			if ( ! in_array( $dir, $parts, true ) ) {
				array_unshift( $parts, $dir );
			}
		}

		return implode( PATH_SEPARATOR, $parts );
	}

	/**
	 * Find installed nvm Node versions without requiring shell startup files.
	 *
	 * @return string[] Absolute bin directories, newest-looking first.
	 */
	private static function discover_nvm_bin_dirs(): array {
		$home = getenv( 'HOME' );
		if ( ! is_string( $home ) || '' === $home ) {
			return array();
		}

		$versions_dir = rtrim( $home, '/' ) . '/.nvm/versions/node';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing NVM directories simply mean no NVM bin paths are available.
		$entries = @scandir( $versions_dir );
		if ( false === $entries ) {
			return array();
		}

		$bins = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$bin = $versions_dir . '/' . $entry . '/bin';
			if ( is_dir( $bin ) ) {
				$bins[] = $bin;
			}
		}

		rsort( $bins, SORT_NATURAL );
		return $bins;
	}

	/**
	 * Execute a command inside the worktree and capture a result envelope.
	 *
	 * Note: we do not shell-escape the command itself — these are hard-coded
	 * invocations, not user input. The `cd` target is escaped.
	 */
	private static function run_command( string $step, string $worktree_path, string $command, string $relative = '.' ): array {
		$cd        = escapeshellarg( $worktree_path );
		$shell_cmd = sprintf( 'cd %s && %s%s 2>&1', $cd, self::shell_env_prefix(), $command );

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
				'relative'    => $relative,
				'command'     => $command,
				'exit_code'   => $exit_code,
				'output_tail' => $joined,
			);
		}

		return array(
			'step'        => $step,
			'status'      => self::STATUS_RAN,
			'relative'    => $relative,
			'command'     => $command,
			'exit_code'   => 0,
			'output_tail' => $joined,
		);
	}
}
