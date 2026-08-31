<?php
/** Bounded observations for compatible worktree bootstrap demand. */
namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeDemandCalibration {
	private const OPTION = 'datamachine_code_worktree_demand_observations';
	private const MAX_SAMPLES = 12;
	private const MIN_SAMPLES = 3;
	private const MAX_BUCKETS = 64;
	private const MAX_OPTION_BYTES = 65536;
	private const MAX_AGE_SECONDS = 2592000;
	private const SAFETY_PERCENT = 25;

	public static function forecast( string $repo, array $plan ): array {
		$identity = self::identity($repo, $plan);
		$defaults = self::bootstrap_defaults($plan);
		$state = self::prune(self::state());
		$samples = self::compatible_samples($state[$identity['key']]['samples'] ?? array(), $defaults);
		$package = $defaults;
		$source = 'conservative_defaults';
		$confidence = 'unseen';
		$percentile = null;
		if ( count($samples) >= self::MIN_SAMPLES ) {
			$percentile = 75;
			$package = array( 'bytes' => min($defaults['bytes'], self::with_margin(self::percentile($samples, 'bytes'), $defaults['bytes'])), 'inodes' => min($defaults['inodes'], self::with_margin(self::percentile($samples, 'inodes'), $defaults['inodes'])) );
			$source = 'compatible_observed_percentile';
			$confidence = 'established';
		} elseif ( array() !== $samples ) {
			$confidence = 'insufficient_nonzero_samples';
		}
		$source_tree = array( 'bytes' => max(0, (int) ($plan['tracked_bytes'] ?? 0)), 'inodes' => max(0, (int) ($plan['counts']['tracked_entries'] ?? 0)), 'source' => (string) ($plan['tracked_bytes_source'] ?? 'not_available'), 'identity' => (string) ($plan['target_commit'] ?? '') );
		$safety = (array) ($plan['git_safety_margin'] ?? array( 'bytes' => 0, 'inodes' => 0 ));
		$plan['bytes'] = $source_tree['bytes'] + max(0, (int) ($safety['bytes'] ?? 0)) + $package['bytes'];
		$plan['inodes'] = $source_tree['inodes'] + max(0, (int) ($safety['inodes'] ?? 0)) + $package['inodes'];
		$plan['source'] = $source;
		$plan['demand_components'] = array( 'git_materialization' => array( 'bytes' => $source_tree['bytes'], 'inodes' => $source_tree['inodes'], 'source' => $source_tree['source'] ), 'source_tree' => array( 'identity' => $source_tree['identity'], 'lockfiles' => $identity['lockfiles'] ), 'package_bootstrap' => array( 'bytes' => $package['bytes'], 'inodes' => $package['inodes'], 'source' => $source, 'default_bytes' => $defaults['bytes'], 'default_inodes' => $defaults['inodes'] ), 'safety_margin' => array( 'bytes' => max(0, (int) ($safety['bytes'] ?? 0)), 'inodes' => max(0, (int) ($safety['inodes'] ?? 0)), 'source' => 'conservative_git_margin' ) );
		$plan['calibration'] = array( 'source' => $source, 'confidence' => $confidence, 'sample_count' => count($samples), 'minimum_sample_count' => self::MIN_SAMPLES, 'observed_percentile' => $percentile, 'compatible_identities' => $identity['fields'] );
		return $plan;
	}

	/** Record only the interval after source checkout/rebase and before/after bootstrap. */
	public static function record_bootstrap( string $repo, array $plan, array $before, array $after, bool $success ): array {
		$bytes = self::delta($before['filesystem_free_bytes'] ?? null, $after['filesystem_free_bytes'] ?? null);
		$inodes = self::delta($before['filesystem_free_inodes'] ?? null, $after['filesystem_free_inodes'] ?? null);
		$evidence = array( 'before' => self::capacity($before), 'after' => self::capacity($after), 'bootstrap_delta' => array( 'bytes' => $bytes, 'inodes' => $inodes ), 'outcome' => $success ? 'success' : 'failed', 'recorded' => false );
		if ( ! $success || null === $bytes || null === $inodes || $bytes <= 0 || $inodes <= 0 ) {
			$evidence['reason'] = $success ? 'nonpositive_or_unavailable_bootstrap_delta' : 'bootstrap_failed';
			return $evidence;
		}
		$identity = self::identity($repo, $plan);
		$state = self::prune(self::state());
		$bucket = is_array($state[$identity['key']] ?? null) ? $state[$identity['key']] : array( 'samples' => array() );
		$bucket['samples'][] = array( 'at' => time(), 'bytes' => $bytes, 'inodes' => $inodes );
		$bucket['samples'] = array_slice($bucket['samples'], -self::MAX_SAMPLES);
		$bucket['last_used'] = time();
		$state[$identity['key']] = $bucket;
		self::save(self::prune($state));
		$evidence['recorded'] = true;
		return $evidence;
	}

	private static function identity( string $repo, array $plan ): array {
		$lockfiles = is_array($plan['lockfile_identities'] ?? null) ? $plan['lockfile_identities'] : array(); ksort($lockfiles);
		$cache = array(); foreach ( array( 'XDG_CACHE_HOME', 'npm_config_cache', 'PNPM_HOME', 'COMPOSER_CACHE_DIR' ) as $name ) { $cache[$name] = (string) getenv($name); }
		$fields = array( 'repo' => $repo, 'git_tree' => (string) ($plan['target_commit'] ?? ''), 'bootstrap' => (bool) ($plan['bootstrap'] ?? false), 'lockfiles' => $lockfiles, 'package_managers' => array_values(array_unique(array_map(static fn( array $root ): string => (string) ($root['manager'] ?? ''), (array) ($plan['detected']['package_roots'] ?? array())))), 'composer_roots' => array_values(array_map(static fn( array $root ): string => (string) ($root['relative'] ?? ''), (array) ($plan['detected']['composer_roots'] ?? array()))), 'cache_identity' => hash('sha256', json_encode($cache)) );
		return array( 'key' => hash('sha256', json_encode($fields)), 'fields' => $fields, 'lockfiles' => $lockfiles );
	}
	private static function bootstrap_defaults( array $plan ): array { $margin = (array) ($plan['git_safety_margin'] ?? array()); return array( 'bytes' => max(0, (int) ($plan['bytes'] ?? 0) - max(0, (int) ($plan['tracked_bytes'] ?? 0)) - max(0, (int) ($margin['bytes'] ?? 0))), 'inodes' => max(0, (int) ($plan['inodes'] ?? 0) - max(0, (int) ($plan['counts']['tracked_entries'] ?? 0)) - max(0, (int) ($margin['inodes'] ?? 0))) ); }
	private static function compatible_samples( array $samples, array $defaults ): array { $now = time(); return array_values(array_filter($samples, static fn( $sample ): bool => is_array($sample) && (int) ($sample['at'] ?? 0) >= $now - self::MAX_AGE_SECONDS && is_numeric($sample['bytes'] ?? null) && is_numeric($sample['inodes'] ?? null) && (int) $sample['bytes'] > 0 && (int) $sample['inodes'] > 0 && (int) $sample['bytes'] <= $defaults['bytes'] && (int) $sample['inodes'] <= $defaults['inodes'])); }
	private static function prune( array $state ): array { $now = time(); foreach ( $state as $key => $bucket ) { $samples = self::compatible_samples((array) ($bucket['samples'] ?? array()), array( 'bytes' => PHP_INT_MAX, 'inodes' => PHP_INT_MAX )); if ( array() === $samples ) { unset($state[$key]); } else { $state[$key] = array( 'last_used' => max((int) ($bucket['last_used'] ?? 0), max(array_column($samples, 'at'))), 'samples' => array_slice($samples, -self::MAX_SAMPLES) ); } } uasort($state, static fn( array $a, array $b ): int => (int) ($a['last_used'] ?? 0) <=> (int) ($b['last_used'] ?? 0)); while (count($state) > self::MAX_BUCKETS || strlen(serialize($state)) > self::MAX_OPTION_BYTES) { array_shift($state); } return $state; }
	private static function percentile( array $samples, string $metric ): int { $values = array_map(static fn( array $sample ): int => (int) $sample[$metric], $samples); sort($values, SORT_NUMERIC); return $values[(int) ceil(count($values) * 0.75) - 1]; }
	private static function with_margin( int $value, int $cap ): int { return min($cap, (int) ceil($value * (100 + self::SAFETY_PERCENT) / 100)); }
	private static function delta( mixed $before, mixed $after ): ?int { return is_numeric($before) && is_numeric($after) ? (int) $before - (int) $after : null; }
	private static function capacity( array $budget ): array { return array( 'filesystem_free_bytes' => $budget['filesystem_free_bytes'] ?? null, 'filesystem_free_inodes' => $budget['filesystem_free_inodes'] ?? null ); }
	private static function state(): array { $state = function_exists('get_option') ? get_option(self::OPTION, array()) : array(); return is_array($state) ? $state : array(); }
	private static function save( array $state ): void { if ( function_exists('update_option') ) { update_option(self::OPTION, $state, false); } }
}
