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
 *        package-lock.json/npm-shrinkwrap.json → npm ci
 *   3. `composer install --no-interaction --prefer-dist` per dependency root
 *      if `composer.lock` exists
 *
 * Dependency roots include the worktree root plus one-level child directories
 * with lockfiles. Git submodule roots are excluded: they own independent
 * dependency lifecycles. A repository may explicitly opt a submodule in via
 * `.datamachine/worktree-bootstrap.json`; see `submodule_dependency_roots`.
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

use DataMachineCode\Support\ProcessRunner;
use DataMachineCode\Support\RuntimeCapabilities;
use DataMachineCode\Support\GitRunner;

defined('ABSPATH') || exit;

if ( ! class_exists(ProcessRunner::class) ) {
	require_once dirname(__DIR__) . '/Support/ProcessRunner.php';
}
if ( ! class_exists(RuntimeCapabilities::class) ) {
	require_once dirname(__DIR__) . '/Support/RuntimeCapabilities.php';
}
if ( ! class_exists(GitRunner::class) ) {
	require_once dirname(__DIR__) . '/Support/GitRunner.php';
}

final class WorktreeBootstrapper {
	private const DEFAULT_TARGET_TREE_TIMEOUT_SECONDS = 300;



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

	/** Repository-owned opt-in contract for submodule dependency roots. */
	private const SUBMODULE_BOOTSTRAP_CONFIG = '.datamachine/worktree-bootstrap.json';

	/** Conservative capacity allowances used before dependency trees exist. */
	private const DEFAULT_DEMAND = array(
		'git_bytes'            => 16777216,
		'git_inodes'           => 256,
		'submodule_bytes'      => 1073741824,
		'submodule_inodes'     => 250000,
		'package_root_bytes'   => 2147483648,
		'package_root_inodes'  => 1000000,
		'composer_root_bytes'  => 1073741824,
		'composer_root_inodes' => 250000,
	);

	/** Blobless trees omit blob sizes; reserve 64 KiB for every tracked tree entry. */
	private const BLOBLESS_TRACKED_ENTRY_BYTES = 65536;

	private const DEFAULT_COMMAND_TIMEOUT_SECONDS = 600;
	private const DEFAULT_TOTAL_TIMEOUT_SECONDS   = 1800;
	private static ?float $bootstrap_deadline     = null;

	/**
	 * Run all applicable bootstrap steps inside the given worktree.
	 *
	 * @param  string $worktree_path Absolute path to the worktree root.
	 * @return array{
	 *     success: bool,
	 *     ran_any: bool,
	 *     skipped_package_roots: array<int, array{relative: string, manager: string, reason: string}>,
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
	public static function bootstrap( string $worktree_path, ?int $remaining_operation_seconds = null ): array {
		$total_timeout = self::total_timeout_seconds();
		if ( null !== $remaining_operation_seconds ) {
			$total_timeout = min($total_timeout, max(1, $remaining_operation_seconds));
		}
		self::$bootstrap_deadline = microtime(true) + $total_timeout;
		$package_discovery        = self::discover_package_roots( $worktree_path );
		$steps                    = array();

		$steps[] = self::run_submodules( $worktree_path );
		$steps   = array_merge( $steps, self::run_packages( $package_discovery['roots'] ) );
		$steps   = array_merge( $steps, self::run_composer( $worktree_path ) );

		$failed  = array_filter( $steps, fn( $s ) => self::STATUS_FAILED === ( $s['status'] ?? '' ) );
		$ran_any = (bool) array_filter( $steps, fn( $s ) => self::STATUS_RAN === ( $s['status'] ?? '' ) );

		$result                   = array(
			'success'               => empty( $failed ),
			'ran_any'               => $ran_any,
			'skipped_package_roots' => $package_discovery['skipped'],
			'steps'                 => $steps,
		);
		self::$bootstrap_deadline = null;
		return $result;
	}

	/** Resolve the finite deadline for the complete dependency bootstrap. */
	public static function total_timeout_seconds(): int {
		$timeout = self::DEFAULT_TOTAL_TIMEOUT_SECONDS;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_bootstrap_total_timeout_seconds', $timeout);
		}

		return max(1, $timeout);
	}

	/** Resolve the finite deadline applied to every dependency bootstrap command. */
	public static function command_timeout_seconds( string $step = '', string $relative = '.' ): int {
		$timeout = self::DEFAULT_COMMAND_TIMEOUT_SECONDS;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_bootstrap_command_timeout_seconds', $timeout, $step, $relative);
		}

		return max(1, $timeout);
	}

	/**
	 * Detect which bootstrap steps WOULD run for a given worktree path, without
	 * executing anything. Useful for diagnostics and for the smoke test.
	 *
	 * @param  string $worktree_path Absolute path to the worktree root.
	 * @return array{
	 *     submodules: bool,
	 *     submodule_roots: array<int, string>,
	 *     packages: ?string,  // Root package manager slug or null.
	 *     composer: bool,
	 *     package_roots: array<int, array{path: string, relative: string, manager: string}>,
	 *     skipped_package_roots: array<int, array{relative: string, manager: string, reason: string}>,
	 *     composer_roots: array<int, array{path: string, relative: string}>,
	 * }
	 */
	public static function detect( string $worktree_path ): array {
		$package_discovery = self::discover_package_roots( $worktree_path );
		$composer_roots    = self::discover_composer_roots( $worktree_path );

		return array(
			'submodules'            => is_file( rtrim( $worktree_path, '/' ) . '/.gitmodules' ),
			'submodule_roots'       => array_keys(self::submodule_paths(rtrim($worktree_path, '/'))),
			'packages'              => self::detect_package_manager( $worktree_path ),
			'composer'              => is_file( rtrim( $worktree_path, '/' ) . '/composer.lock' ),
			'package_roots'         => $package_discovery['roots'],
			'skipped_package_roots' => $package_discovery['skipped'],
			'composer_roots'        => $composer_roots,
		);
	}

	/**
	 * Build a conservative pre-create capacity plan from authoritative detection.
	 *
	 * The Git reserve covers the worktree administration/index lock mutation and
	 * is retained for bare checkouts. Dependency allowances are only included
	 * when bootstrap is requested. Values are defaults, not measured forecasts.
	 *
	 * @return array<string,mixed>
	 */
	public static function demand_plan( string $worktree_path, bool $bootstrap = true ): array {
		$detected = self::detect($worktree_path);
		$defaults = self::DEFAULT_DEMAND;
		$source   = 'conservative_defaults';

		if ( function_exists('apply_filters') ) {
			/**
			 * Filters conservative per-operation worktree bootstrap demand allowances.
			 *
			 * @param array  $defaults      Default byte and inode allowances.
			 * @param array  $detected      Result from WorktreeBootstrapper::detect().
			 * @param string $worktree_path Existing checkout used for detection.
			 * @param bool   $bootstrap     Whether dependency bootstrap was requested.
			 */
			$filtered = apply_filters('datamachine_worktree_bootstrap_demand', $defaults, $detected, $worktree_path, $bootstrap);
			if ( $filtered !== $defaults ) {
				$defaults = array_merge($defaults, $filtered);
				$source   = 'wordpress_filter';
			}
		}

		foreach ( self::DEFAULT_DEMAND as $key => $fallback ) {
			$defaults[ $key ] = isset($defaults[ $key ]) && is_numeric($defaults[ $key ])
				? max(0, (int) $defaults[ $key ])
				: $fallback;
		}

		$submodule_count = $bootstrap ? count( (array) $detected['submodule_roots']) : 0;
		$package_count   = $bootstrap ? count( (array) $detected['package_roots']) : 0;
		$composer_count  = $bootstrap ? count( (array) $detected['composer_roots']) : 0;
		$bytes           = $defaults['git_bytes']
			+ ( $submodule_count * $defaults['submodule_bytes'] )
			+ ( $package_count * $defaults['package_root_bytes'] )
			+ ( $composer_count * $defaults['composer_root_bytes'] );
		$inodes          = $defaults['git_inodes']
			+ ( $submodule_count * $defaults['submodule_inodes'] )
			+ ( $package_count * $defaults['package_root_inodes'] )
			+ ( $composer_count * $defaults['composer_root_inodes'] );

		return array(
			'bytes'              => $bytes,
			'inodes'             => $inodes,
			'source'             => $source,
			'fallback_semantics' => 'conservative_defaults_are_used_without_wordpress_or_for_invalid_filtered_values',
			'bootstrap'          => $bootstrap,
			'detected'           => $detected,
			'counts'             => array(
				'submodules'     => $submodule_count,
				'package_roots'  => $package_count,
				'composer_roots' => $composer_count,
			),
			'allowances'         => $defaults,
		);
	}

	/**
	 * Build demand from the exact Git tree that will materialize in the worktree.
	 *
	 * @param callable|null $runner Deterministic test seam receiving repo path and resolved commit.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function demand_plan_for_target( string $repo_path, string $target_ref, bool $bootstrap = true, ?callable $runner = null ): array|\WP_Error {
		$commit = GitRunner::probe_output($repo_path, 'rev-parse --verify ' . escapeshellarg($target_ref . '^{commit}'));
		if ( null === $commit || 1 !== preg_match('/^[0-9a-f]{40,64}$/D', $commit) ) {
			return new \WP_Error('worktree_target_ref_invalid', sprintf('Could not resolve target ref "%s" before capacity admission.', $target_ref), array( 'status' => 400 ));
		}

		$blobless_partial_clone = self::is_blobless_partial_clone($repo_path);
		$tree_command           = 'ls-tree -r -t ' . ( $blobless_partial_clone ? '' : '-l ' ) . '-z --full-tree ' . escapeshellarg($commit);
		if ( null !== $runner ) {
			$tree_output = $runner($repo_path, $commit);
		} else {
			$tree = GitRunner::run($repo_path, $tree_command, self::target_tree_timeout_seconds($repo_path));
			if ( $tree instanceof \WP_Error ) {
				return $tree;
			}
			$tree_output = (string) $tree['output'];
		}
		if ( ! is_string($tree_output) ) {
			return new \WP_Error('worktree_target_tree_unavailable', 'Target tree inspection did not return parseable output.', array( 'status' => 500 ));
		}

		$tree_plan            = self::parse_target_tree($tree_output);
		$blobless_entry_bytes = self::blobless_tracked_entry_bytes($repo_path);
		$tracked_bytes        = $blobless_partial_clone
			? $tree_plan['tracked_entries'] * $blobless_entry_bytes
			: $tree_plan['tracked_bytes'];
		$defaults             = self::filtered_demand_defaults($tree_plan['detected'], $repo_path, $bootstrap);
		$counts               = array(
			'tracked_entries' => $tree_plan['tracked_entries'],
			'submodules'      => $bootstrap ? count($tree_plan['detected']['submodule_roots']) : 0,
			'package_roots'   => $bootstrap ? count($tree_plan['detected']['package_roots']) : 0,
			'composer_roots'  => $bootstrap ? count($tree_plan['detected']['composer_roots']) : 0,
		);

		return array(
			'bytes'                   => $tracked_bytes + $defaults['git_bytes'] + ( $counts['submodules'] * $defaults['submodule_bytes'] ) + ( $counts['package_roots'] * $defaults['package_root_bytes'] ) + ( $counts['composer_roots'] * $defaults['composer_root_bytes'] ),
			'inodes'                  => $counts['tracked_entries'] + $defaults['git_inodes'] + ( $counts['submodules'] * $defaults['submodule_inodes'] ) + ( $counts['package_roots'] * $defaults['package_root_inodes'] ) + ( $counts['composer_roots'] * $defaults['composer_root_inodes'] ),
			'source'                  => 'target_git_tree_conservative',
			'target_ref'              => $target_ref,
			'target_commit'           => $commit,
			'tracked_bytes'           => $tracked_bytes,
			'tracked_bytes_source'    => $blobless_partial_clone ? 'conservative_blobless_entry_estimate' : 'exact_git_blob_sizes',
			'tracked_bytes_per_entry' => $blobless_partial_clone ? $blobless_entry_bytes : null,
			'git_safety_margin'       => array(
				'bytes'  => $defaults['git_bytes'],
				'inodes' => $defaults['git_inodes'],
			),
			'bootstrap'               => $bootstrap,
			'detected'                => $tree_plan['detected'],
			'counts'                  => $counts,
			'allowances'              => $defaults,
			'fallback_semantics'      => $blobless_partial_clone
				? 'tracked target entries are measured from Git metadata; blobless partial clones reserve a conservative 64 KiB per tracked entry because exact blob sizes are unavailable; dependency installs use conservative allowances'
				: 'tracked target entries and bytes are measured from Git; dependency installs use conservative allowances',
		);
	}

	/** Resolve the bounded target-tree inspection budget for capacity admission. */
	public static function target_tree_timeout_seconds( string $repo_path ): int {
		$timeout = self::DEFAULT_TARGET_TREE_TIMEOUT_SECONDS;
		if ( function_exists('apply_filters') ) {
			$timeout = (int) apply_filters('datamachine_code_worktree_target_tree_timeout_seconds', $timeout, $repo_path);
		}

		return max(1, $timeout);
	}

	/** Whether Git config declares a promisor remote with a blob:none filter. */
	private static function is_blobless_partial_clone( string $repo_path ): bool {
		$config = GitRunner::probe_output($repo_path, 'config --get-regexp ' . escapeshellarg('^remote\..*\.(promisor|partialclonefilter)$'));
		if ( null === $config ) {
			return false;
		}

		$remotes = array();
		$lines   = preg_split('/\r?\n/', $config);
		if ( false === $lines ) {
			return false;
		}
		foreach ( $lines as $line ) {
			if ( 1 !== preg_match('/^remote\.([A-Za-z0-9._-]+)\.(promisor|partialclonefilter)\s+(.+)$/D', $line, $matches) ) {
				continue;
			}
			$remotes[ $matches[1] ][ $matches[2] ] = strtolower(trim($matches[3]));
		}

		foreach ( $remotes as $remote ) {
			if ( in_array($remote['promisor'] ?? '', array( 'true', 'yes', 'on', '1' ), true) && 'blob:none' === ( $remote['partialclonefilter'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/** Resolve the conservative per-entry estimate used when blob sizes are absent. */
	private static function blobless_tracked_entry_bytes( string $repo_path ): int {
		$bytes = self::BLOBLESS_TRACKED_ENTRY_BYTES;
		if ( function_exists('apply_filters') ) {
			$bytes = (int) apply_filters('datamachine_code_worktree_blobless_tracked_entry_bytes', $bytes, $repo_path);
		}

		return max(1, $bytes);
	}

	/** Remove demand already materialized by `git worktree add` or rebase. */
	public static function remaining_demand_after_materialization( array $plan ): array {
		$plan['bytes']  = max(0, (int) ( $plan['bytes'] ?? 0 ) - (int) ( $plan['tracked_bytes'] ?? 0 ));
		$plan['inodes'] = max(0, (int) ( $plan['inodes'] ?? 0 ) - (int) ( $plan['counts']['tracked_entries'] ?? 0 ));
		$plan['source'] = 'post_materialization_target_tree_conservative';
		return $plan;
	}

	/** Parse bounded NUL-delimited `git ls-tree -r -t` output, with optional blob sizes. */
	public static function parse_target_tree( string $output ): array {
		$tracked_entries = 0;
		$tracked_bytes   = 0;
		$package_roots   = array();
		$composer_roots  = array();
		$submodule_roots = array();
		$lockfiles       = array( 'pnpm-lock.yaml', 'bun.lockb', 'bun.lock', 'yarn.lock', 'package-lock.json', 'npm-shrinkwrap.json' );

		foreach ( explode("\0", $output) as $record ) {
			if ( '' === $record || 1 !== preg_match('/^(\d{6})\s+(blob|tree|commit)\s+[0-9a-f]+(?:\s+(-|\d+))?\t(.*)$/sD', $record, $matches) ) {
				continue;
			}
			++$tracked_entries;
			$path = $matches[4];
			if ( 'blob' === $matches[2] && is_numeric($matches[3]) ) {
				$tracked_bytes += max(0, (int) $matches[3]);
			}
			if ( '160000' === $matches[1] ) {
				$submodule_roots[ $path ] = array( 'relative' => $path );
			}
			$dirname = dirname($path);
			if ( '.' !== $dirname && str_contains($dirname, '/') ) {
				continue;
			}
			$basename = basename($path);
			if ( in_array($basename, $lockfiles, true) ) {
				$relative = '.' === $dirname ? '.' : $dirname;
				$manager  = self::manager_for_lockfile($basename);
				if ( ! isset($package_roots[ $relative ]) || self::package_manager_priority($manager) < self::package_manager_priority($package_roots[ $relative ]['manager']) ) {
					$package_roots[ $relative ] = array(
						'relative' => $relative,
						'manager'  => $manager,
					);
				}
			}
			if ( 'composer.lock' === $basename ) {
				$relative                    = '.' === $dirname ? '.' : $dirname;
				$composer_roots[ $relative ] = array( 'relative' => $relative );
			}
		}

		return array(
			'tracked_entries' => $tracked_entries,
			'tracked_bytes'   => $tracked_bytes,
			'detected'        => array(
				'submodules'            => array() !== $submodule_roots,
				'submodule_roots'       => array_values($submodule_roots),
				'packages'              => $package_roots['.']['manager'] ?? null,
				'composer'              => isset($composer_roots['.']),
				'package_roots'         => array_values($package_roots),
				'skipped_package_roots' => array(),
				'composer_roots'        => array_values($composer_roots),
			),
		);
	}

	private static function manager_for_lockfile( string $lockfile ): string {
		return match ( $lockfile ) {
			'pnpm-lock.yaml' => 'pnpm',
			'bun.lockb', 'bun.lock' => 'bun',
			'yarn.lock' => 'yarn',
			default => 'npm',
		};
	}

	/** Return the package manager's detection precedence, where lower wins. */
	private static function package_manager_priority( string $manager ): int {
		return match ( $manager ) {
			'pnpm' => 0,
			'bun'  => 1,
			'yarn' => 2,
			'npm'  => 3,
			default => PHP_INT_MAX,
		};
	}

	private static function filtered_demand_defaults( array $detected, string $path, bool $bootstrap ): array {
		$defaults = self::DEFAULT_DEMAND;
		if ( function_exists('apply_filters') ) {
			$filtered = apply_filters('datamachine_worktree_bootstrap_demand', $defaults, $detected, $path, $bootstrap);
			if ( is_array($filtered) ) {
				$defaults = array_merge($defaults, $filtered);
			}
		}
		foreach ( self::DEFAULT_DEMAND as $key => $fallback ) {
			$defaults[ $key ] = isset($defaults[ $key ]) && is_numeric($defaults[ $key ]) ? max(0, (int) $defaults[ $key ]) : $fallback;
		}
		return $defaults;
	}

	/**
	 * Pretty-print a bootstrap result as a multi-line human-readable block.
	 *
	 * @param  array $result Result from {@see self::bootstrap()}.
	 * @return string
	 */
	public static function format( array $result ): string {
		$steps = is_array($result['steps'] ?? null) ? $result['steps'] : array();
		if ( empty($steps) ) {
			return 'Bootstrap: no steps attempted.';
		}

		$lines = array();
		foreach ( $steps as $step ) {
			$kind   = (string) ( $step['step'] ?? '?' );
			$target = (string) ( $step['relative'] ?? '' );
			$label  = '.' === $target || '' === $target ? $kind : sprintf('%s[%s]', $kind, $target);
			$status = (string) ( $step['status'] ?? '?' );
			$reason = (string) ( $step['reason'] ?? '' );
			$cmd    = (string) ( $step['command'] ?? '' );

			switch ( $status ) {
				case self::STATUS_RAN:
					$lines[] = sprintf('  ✓ %-18s ran: %s', $label, $cmd);
					break;
				case self::STATUS_SKIPPED:
					$lines[] = sprintf('  - %-18s skipped (%s)', $label, '' !== $reason ? $reason : 'no trigger');
					break;
				case self::STATUS_FAILED:
					$exit    = isset($step['exit_code']) ? (int) $step['exit_code'] : -1;
					$lines[] = sprintf('  ✗ %-18s FAILED (exit %d): %s', $label, $exit, $cmd);
					if ( ! empty($step['output_tail']) ) {
						foreach ( explode("\n", (string) $step['output_tail']) as $out_line ) {
							$lines[] = '      ' . $out_line;
						}
					}
					break;
				default:
					$lines[] = sprintf('  ? %-18s %s', $label, $status);
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Run `git submodule update --init --recursive` if `.gitmodules` is present.
	 */
	private static function run_submodules( string $worktree_path ): array {
		if ( ! is_file(rtrim($worktree_path, '/') . '/.gitmodules') ) {
			return array(
				'step'   => self::STEP_SUBMODULES,
				'status' => self::STATUS_SKIPPED,
				'reason' => 'no .gitmodules',
			);
		}

		if ( ! self::binary_available('git') ) {
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
	private static function run_packages( array $roots ): array {
		if ( empty($roots) ) {
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

			if ( ! self::binary_available($pm) ) {
				$steps[] = array(
					'step'     => self::STEP_PACKAGES,
					'status'   => self::STATUS_SKIPPED,
					'reason'   => sprintf('%s not on PATH (lockfile present)', $pm),
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
					'reason'   => sprintf('unsupported package manager %s', $pm),
					'relative' => $root['relative'],
				);
				continue;
			}

			$steps[] = self::run_command(self::STEP_PACKAGES, $root['path'], $command, $root['relative'], true);
		}

		return $steps;
	}

	/**
	 * Run `composer install` if `composer.lock` is present.
	 */
	private static function run_composer( string $worktree_path ): array {
		$roots = self::discover_composer_roots($worktree_path);
		if ( empty($roots) ) {
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
			if ( ! self::binary_available('composer') ) {
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
				$root['relative'],
				true
			);
		}

		return $steps;
	}

	/**
	 * Discover package-manager roots at the repo root and one directory deep.
	 *
	 * @return array{roots: array<int, array{path: string, relative: string, manager: string}>, skipped: array<int, array{relative: string, manager: string, reason: string}>}
	 */
	private static function discover_package_roots( string $worktree_path ): array {
		$roots   = array();
		$skipped = array();
		foreach ( self::candidate_dependency_roots( $worktree_path ) as $candidate ) {
			$manager = self::detect_package_manager( $candidate['path'] );
			if ( null === $manager ) {
				continue;
			}
			if ( ! empty( $candidate['submodule'] ) && empty( $candidate['submodule_opted_in'] ) ) {
				$skipped[] = array(
					'relative' => $candidate['relative'],
					'manager'  => $manager,
					'reason'   => 'git submodule dependency root is excluded by default',
				);
				continue;
			}
			$roots[] = array(
				'path'     => $candidate['path'],
				'relative' => $candidate['relative'],
				'manager'  => $manager,
			);
		}
		return array(
			'roots'   => $roots,
			'skipped' => $skipped,
		);
	}

	/**
	 * Discover Composer roots at the repo root and one directory deep.
	 *
	 * @return array<int, array{path: string, relative: string}>
	 */
	private static function discover_composer_roots( string $worktree_path ): array {
		$roots = array();
		foreach ( self::candidate_dependency_roots($worktree_path) as $candidate ) {
			if ( ! empty($candidate['submodule']) && empty($candidate['submodule_opted_in']) ) {
				continue;
			}
			if ( ! is_file($candidate['path'] . '/composer.lock') ) {
				continue;
			}
			$roots[] = $candidate;
		}
		return $roots;
	}

	/**
	 * Return the repo root plus one-level child directories that may own deps.
	 *
	 * @return array<int, array{path: string, relative: string, submodule?: bool, submodule_opted_in?: bool}>
	 */
	private static function candidate_dependency_roots( string $worktree_path ): array {
		$root       = rtrim($worktree_path, '/');
		$submodules = self::submodule_paths($root);
		$opted_in   = self::opted_in_submodule_dependency_roots($root);
		$candidates = array(
			array(
				'path'     => $root,
				'relative' => '.',
			),
		);

     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable roots are skipped as non-candidates below.
		$entries = @scandir($root);
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
				'path'               => $path,
				'relative'           => $entry,
				'submodule'          => isset( $submodules[ $entry ] ),
				'submodule_opted_in' => isset( $opted_in[ $entry ] ),
			);
		}

		return $candidates;
	}

	/**
	 * Read declared submodule paths without depending on an initialized checkout.
	 *
	 * `.gitmodules` is tracked by the superproject and is available even when a
	 * submodule's pinned commit cannot be fetched or checked out.
	 *
	 * @return array<string, true> Relative submodule paths keyed by path.
	 */
	private static function submodule_paths( string $worktree_path ): array {
		$gitmodules = $worktree_path . '/.gitmodules';
		if ( ! is_file( $gitmodules ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid or unreadable declarations simply provide no boundaries.
		$sections = @parse_ini_file( $gitmodules, true, INI_SCANNER_RAW );
		if ( ! is_array( $sections ) ) {
			return array();
		}

		$paths = array();
		foreach ( $sections as $section ) {
			$path = is_array( $section ) ? (string) ( $section['path'] ?? '' ) : '';
			$path = trim( $path, '/' );
			if ( '' !== $path && ! str_contains( $path, '..' ) ) {
				$paths[ $path ] = true;
			}
		}
		return $paths;
	}

	/**
	 * Read the explicit repository contract for submodules DMC may bootstrap.
	 *
	 * @return array<string, true> Relative submodule paths keyed by path.
	 */
	private static function opted_in_submodule_dependency_roots( string $worktree_path ): array {
		$config = $worktree_path . '/' . self::SUBMODULE_BOOTSTRAP_CONFIG;
		if ( ! is_file( $config ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local repository config file.
		$decoded  = json_decode( (string) file_get_contents( $config ), true );
		$declared = is_array( $decoded ) && is_array( $decoded['submodule_dependency_roots'] ?? null )
			? $decoded['submodule_dependency_roots']
			: array();
		$paths    = array();
		foreach ( $declared as $path ) {
			if ( ! is_string($path) ) {
				continue;
			}
			$path = trim( $path, '/' );
			if ( '' !== $path && ! str_contains( $path, '..' ) ) {
				$paths[ $path ] = true;
			}
		}
		return $paths;
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
		$root = rtrim($worktree_path, '/');
		if ( is_file($root . '/pnpm-lock.yaml') ) {
			return 'pnpm';
		}
		// Bun supports both the binary lockb and the text lock file.
		if ( is_file($root . '/bun.lockb') || is_file($root . '/bun.lock') ) {
			return 'bun';
		}
		if ( is_file($root . '/yarn.lock') ) {
			return 'yarn';
		}
		if ( is_file($root . '/package-lock.json') || is_file($root . '/npm-shrinkwrap.json') ) {
			return 'npm';
		}
		return null;
	}

	/**
	 * Is a binary available on PATH? Uses `command -v` which is portable across
	 * bash/zsh/dash — `which` is not POSIX.
	 */
	private static function binary_available( string $binary ): bool {
		return RuntimeCapabilities::binary_available($binary, self::augmented_path());
	}

	/**
	 * Build a shell env prefix with non-interactive toolchain path fallbacks.
	 */
	private static function shell_env_prefix(): string {
		$path = self::augmented_path();
		if ( null === $path ) {
			return '';
		}
		return sprintf('PATH=%s ', escapeshellarg($path));
	}

	/**
	 * Add common nvm binary dirs for shells that do not source .zshrc/.bashrc.
	 */
	private static function augmented_path(): ?string {
		$current = getenv('PATH');
		$current = is_string($current) ? $current : '';
		$extra   = self::discover_nvm_bin_dirs();
		if ( empty($extra) ) {
			return null;
		}

		$parts = array_filter(explode(PATH_SEPARATOR, $current), static fn( $part ) => '' !== $part);
		foreach ( array_reverse($extra) as $dir ) {
			if ( ! in_array($dir, $parts, true) ) {
				array_unshift($parts, $dir);
			}
		}

		return implode(PATH_SEPARATOR, $parts);
	}

	/**
	 * Find installed nvm Node versions without requiring shell startup files.
	 *
	 * @return string[] Absolute bin directories, newest-looking first.
	 */
	private static function discover_nvm_bin_dirs(): array {
		$home = getenv('HOME');
		if ( ! is_string($home) || '' === $home ) {
			return array();
		}

		$versions_dir = rtrim($home, '/') . '/.nvm/versions/node';
     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing NVM directories simply mean no NVM bin paths are available.
		$entries = @scandir($versions_dir);
		if ( false === $entries ) {
			return array();
		}

		$bins = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$bin = $versions_dir . '/' . $entry . '/bin';
			if ( is_dir($bin) ) {
				$bins[] = $bin;
			}
		}

		rsort($bins, SORT_NATURAL);
		return $bins;
	}

	/**
	 * Execute a command inside the worktree and capture a result envelope.
	 *
	 * Note: we do not shell-escape the command itself — these are hard-coded
	 * invocations, not user input. The `cd` target is escaped.
	 */
	private static function run_command( string $step, string $worktree_path, string $command, string $relative = '.', bool $preserve_tracked_files = false ): array {
		$timeout_seconds = self::command_timeout_seconds($step, $relative);
		if ( null !== self::$bootstrap_deadline ) {
			$remaining = (int) floor(self::$bootstrap_deadline - microtime(true));
			if ( $remaining <= 0 ) {
				return array(
					'step'            => $step,
					'status'          => self::STATUS_FAILED,
					'reason'          => 'bootstrap_total_timeout',
					'relative'        => $relative,
					'command'         => $command,
					'exit_code'       => 1,
					'output_tail'     => 'The complete dependency bootstrap deadline was exhausted before this step.',
					'timed_out'       => true,
					'timeout_seconds' => self::total_timeout_seconds(),
				);
			}
			$timeout_seconds = min($timeout_seconds, $remaining);
		}
		$snapshot = $preserve_tracked_files ? self::snapshot_tracked_state($worktree_path) : null;
		if ( $preserve_tracked_files && null === $snapshot ) {
			return array(
				'step'                 => $step,
				'status'               => self::STATUS_FAILED,
				'relative'             => $relative,
				'command'              => $command,
				'exit_code'            => 1,
				'output_tail'          => 'Could not snapshot tracked files before dependency bootstrap.',
				'tracked_file_cleanup' => array(
					'restored_paths' => array(),
					'retained_paths' => array(),
					'error'          => 'pre-command snapshot failed',
				),
			);
		}

		$result = ProcessRunner::run(
			sprintf('%s%s 2>&1', self::shell_env_prefix(), $command),
			array(
				'cwd'              => $worktree_path,
				'timeout_seconds'  => $timeout_seconds,
				'output_cap_bytes' => self::OUTPUT_CAP_BYTES,
				'error_as_result'  => true,
			)
		);

		if ( $result instanceof \WP_Error || empty($result['success']) ) {
			$data        = $result instanceof \WP_Error ? $result->get_error_data() : $result;
			$data        = is_array($data) ? $data : array();
			$message     = $result instanceof \WP_Error ? $result->get_error_message() : 'Process command failed.';
			$timed_out   = isset($data['timeout']);
			$step_result = array(
				'step'            => $step,
				'status'          => self::STATUS_FAILED,
				'reason'          => $timed_out ? 'command_timeout' : 'command_failed',
				'relative'        => $relative,
				'command'         => $command,
				'exit_code'       => (int) ( $data['exit_code'] ?? 1 ),
				'output_tail'     => (string) ( $data['output'] ?? $message ),
				'timed_out'       => $timed_out,
				'timeout_seconds' => $timeout_seconds,
				'cleanup'         => is_array($data['cleanup'] ?? null) ? $data['cleanup'] : null,
			);
		} else {
			$step_result = array(
				'step'            => $step,
				'status'          => self::STATUS_RAN,
				'relative'        => $relative,
				'command'         => $command,
				'exit_code'       => 0,
				'output_tail'     => $result['output'],
				'timed_out'       => false,
				'timeout_seconds' => $timeout_seconds,
			);
		}

		if ( ! $preserve_tracked_files ) {
			return $step_result;
		}

		$cleanup                             = self::restore_tracked_state($worktree_path, $snapshot);
		$step_result['tracked_file_cleanup'] = $cleanup;
		if ( isset($cleanup['error']) ) {
			$step_result['status']      = self::STATUS_FAILED;
			$step_result['exit_code']   = 1;
			$step_result['output_tail'] = trim($step_result['output_tail'] . "\nTracked-file cleanup failed: " . $cleanup['error']);
		}

		return $step_result;
	}

	/**
	 * Snapshot repository-wide index and worktree deltas before dependency tools
	 * run. Their generated tracked changes must not claim or erase prior edits.
	 *
	 * @return array{cached_patch: string, worktree_patch: string, worktree_paths: array<int, string>, retained_paths: array<int, string>}|null
	 */
	private static function snapshot_tracked_state( string $worktree_path ): ?array {
		$repo_root = self::git_output($worktree_path, 'git rev-parse --show-toplevel');
		$repo_root = null === $repo_root ? null : trim($repo_root);
		if ( null === $repo_root || '' === $repo_root ) {
			return null;
		}
		$cached_patch   = self::git_raw_output($repo_root, 'git diff --cached --binary');
		$worktree_patch = self::git_raw_output($repo_root, 'git diff --binary');
		$cached_paths   = self::git_paths($repo_root, 'git diff --cached --name-only -z');
		$worktree_paths = self::git_paths($repo_root, 'git diff --name-only -z');
		if ( null === $cached_patch || null === $worktree_patch || null === $cached_paths || null === $worktree_paths ) {
			return null;
		}

		return array(
			'cached_patch'   => $cached_patch,
			'worktree_patch' => $worktree_patch,
			'worktree_paths' => $worktree_paths,
			'retained_paths' => self::unique_paths(array_merge($cached_paths, $worktree_paths)),
		);
	}

	/**
	 * Restore paths changed by the dependency command, then replay the exact
	 * index and worktree deltas that existed before it.
	 *
	 * @param array{cached_patch: string, worktree_patch: string, worktree_paths: array<int, string>, retained_paths: array<int, string>} $snapshot
	 * @return array{restored_paths: array<int, string>, retained_paths: array<int, string>, error?: string}
	 */
	private static function restore_tracked_state( string $worktree_path, array $snapshot ): array {
		$repo_root = self::git_output($worktree_path, 'git rev-parse --show-toplevel');
		$repo_root = null === $repo_root ? null : trim($repo_root);
		if ( null === $repo_root || '' === $repo_root ) {
			return self::cleanup_error($snapshot, 'could not identify repository root for tracked-file cleanup');
		}
		$cached_paths = self::git_paths($repo_root, 'git diff --cached --name-only -z');
		if ( null === $cached_paths ) {
			return self::cleanup_error($snapshot, 'could not inspect post-command index changes');
		}
		foreach ( $cached_paths as $path ) {
			if ( null === self::git_output($repo_root, 'git reset --mixed HEAD -- ' . escapeshellarg($path)) ) {
				return self::cleanup_error($snapshot, sprintf('could not restore index path %s', $path));
			}
		}
		if ( ! self::apply_patch($repo_root, $snapshot['cached_patch'], true) ) {
			return self::cleanup_error($snapshot, 'could not replay pre-command index changes');
		}

		$worktree_paths = self::git_paths($repo_root, 'git diff --name-only -z');
		if ( null === $worktree_paths ) {
			return self::cleanup_error($snapshot, 'could not inspect post-command worktree changes');
		}
		foreach ( self::unique_paths(array_merge($worktree_paths, $snapshot['worktree_paths'])) as $path ) {
			if ( null === self::git_output($repo_root, 'git checkout-index --force ' . escapeshellarg($path)) ) {
				return self::cleanup_error($snapshot, sprintf('could not restore worktree path %s', $path));
			}
		}
		if ( ! self::apply_patch($repo_root, $snapshot['worktree_patch'], false) ) {
			return self::cleanup_error($snapshot, 'could not replay pre-command worktree changes');
		}

		return array(
			'restored_paths' => self::unique_paths(array_merge($cached_paths, $worktree_paths)),
			'retained_paths' => $snapshot['retained_paths'],
		);
	}

	/** @return array{restored_paths: array<int, string>, retained_paths: array<int, string>, error: string} */
	private static function cleanup_error( array $snapshot, string $error ): array {
		return array(
			'restored_paths' => array(),
			'retained_paths' => $snapshot['retained_paths'],
			'error'          => $error,
		);
	}

	private static function apply_patch( string $worktree_path, string $patch, bool $cached ): bool {
		if ( '' === $patch ) {
			return true;
		}
		$path = tempnam(sys_get_temp_dir(), 'dmc-bootstrap-patch-');
		if ( false === $path || false === file_put_contents($path, $patch) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Git apply requires an exact temporary binary patch file.
			return false;
		}
		$result = self::git_output($worktree_path, sprintf('git apply %s--binary < %s', $cached ? '--cached ' : '', escapeshellarg($path)));
		unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the private temporary patch immediately after Git consumes it.
		return null !== $result;
	}

	/** @return string|null */
	private static function git_output( string $worktree_path, string $command ): ?string {
		$result = ProcessRunner::run($command, array(
			'cwd'             => $worktree_path,
			'error_as_result' => true,
		));
		return $result instanceof \WP_Error || empty($result['success']) ? null : (string) $result['output'];
	}

	/** @return array<int, string>|null */
	private static function git_paths( string $worktree_path, string $command ): ?array {
		$output = self::git_raw_output($worktree_path, $command);
		if ( null === $output ) {
			return null;
		}
		return array_values(array_filter(explode("\0", $output), static fn( $path ) => '' !== $path));
	}

	/** Return Git output without ProcessRunner's text normalization. */
	private static function git_raw_output( string $worktree_path, string $command ): ?string {
		$path = tempnam(sys_get_temp_dir(), 'dmc-bootstrap-git-');
		if ( false === $path ) {
			return null;
		}
		$result = ProcessRunner::run(
			$command . ' > ' . escapeshellarg($path),
			array(
				'cwd'             => $worktree_path,
				'error_as_result' => true,
			)
		);
		$output = ! $result instanceof \WP_Error && ! empty($result['success']) ? file_get_contents($path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads exact NUL-delimited Git output from a local temporary file.
		unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the private Git output file immediately after reading it.
		return false === $output ? null : $output;
	}

	/** @return array<int, string> */
	private static function unique_paths( array $paths ): array {
		$paths = array_values(array_unique($paths));
		sort($paths, SORT_STRING);
		return $paths;
	}
}
