<?php
/** Bounded observations for compatible worktree bootstrap demand. */
namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final class WorktreeDemandCalibration {
	private const OPTION = 'datamachine_code_worktree_demand_observations';
	private const MAX_SAMPLES = 12;
	private const MAX_AGE_SECONDS = 2592000;
	private const SAFETY_PERCENT = 25;

	public static function forecast( string $repo, array $plan ): array {
		$identity = self::identity($repo, $plan);
		$defaults = self::bootstrap_defaults($plan);
		$samples = self::compatible_samples($identity['key'], $defaults);
		$package = $defaults;
		$source = 'conservative_defaults';
		$confidence = 'unseen';
		$percentile = null;
		if ( array() !== $samples ) {
			$percentile = 75;
			$package = array( 'bytes' => min($defaults['bytes'], self::with_margin(self::percentile($samples, 'bytes'), $defaults['bytes'])), 'inodes' => min($defaults['inodes'], self::with_margin(self::percentile($samples, 'inodes'), $defaults['inodes'])) );
			$source = 'compatible_observed_percentile';
			$confidence = count($samples) >= 3 ? 'established' : 'limited';
		}
		$source_tree = array( 'bytes' => max(0, (int) ($plan['tracked_bytes'] ?? 0)), 'inodes' => max(0, (int) ($plan['counts']['tracked_entries'] ?? 0)), 'source' => (string) ($plan['tracked_bytes_source'] ?? 'not_available'), 'identity' => (string) ($plan['target_commit'] ?? '') );
		$safety = (array) ($plan['git_safety_margin'] ?? array( 'bytes' => 0, 'inodes' => 0 ));
		$plan['bytes'] = $source_tree['bytes'] + max(0, (int) ($safety['bytes'] ?? 0)) + $package['bytes'];
		$plan['inodes'] = $source_tree['inodes'] + max(0, (int) ($safety['inodes'] ?? 0)) + $package['inodes'];
		$plan['source'] = $source;
		$plan['demand_components'] = array(
			'git_materialization' => array( 'bytes' => $source_tree['bytes'], 'inodes' => $source_tree['inodes'], 'source' => $source_tree['source'] ),
			'source_tree' => array( 'identity' => $source_tree['identity'], 'lockfiles' => $identity['lockfiles'] ),
			'package_bootstrap' => array( 'bytes' => $package['bytes'], 'inodes' => $package['inodes'], 'source' => $source, 'default_bytes' => $defaults['bytes'], 'default_inodes' => $defaults['inodes'] ),
			'safety_margin' => array( 'bytes' => max(0, (int) ($safety['bytes'] ?? 0)), 'inodes' => max(0, (int) ($safety['inodes'] ?? 0)), 'source' => 'conservative_git_margin' ),
		);
		$plan['calibration'] = array( 'source' => $source, 'confidence' => $confidence, 'sample_count' => count($samples), 'observed_percentile' => $percentile, 'compatible_identities' => $identity['fields'] );
		return $plan;
	}

	public static function record( string $repo, array $plan, array $before, array $after, string $outcome ): array {
		$identity = self::identity($repo, $plan);
		$observation = array( 'at' => time(), 'bytes' => self::delta($before['filesystem_free_bytes'] ?? null, $after['filesystem_free_bytes'] ?? null), 'inodes' => self::delta($before['filesystem_free_inodes'] ?? null, $after['filesystem_free_inodes'] ?? null), 'outcome' => $outcome );
		$state = self::state();
		$rows = is_array($state[$identity['key']] ?? null) ? $state[$identity['key']] : array();
		$rows[] = $observation;
		$state[$identity['key']] = array_slice($rows, -self::MAX_SAMPLES);
		self::save($state);
		return array( 'before' => self::capacity($before), 'after' => self::capacity($after), 'observed_delta' => array( 'bytes' => $observation['bytes'], 'inodes' => $observation['inodes'] ), 'outcome' => $outcome, 'calibration' => array( 'sample_count' => count($state[$identity['key']]), 'compatible_identities' => $identity['fields'] ) );
	}

	private static function identity( string $repo, array $plan ): array {
		$lockfiles = is_array($plan['lockfile_identities'] ?? null) ? $plan['lockfile_identities'] : array(); ksort($lockfiles);
		$cache = array(); foreach ( array( 'XDG_CACHE_HOME', 'npm_config_cache', 'PNPM_HOME', 'COMPOSER_CACHE_DIR' ) as $name ) { $cache[$name] = (string) getenv($name); }
		$fields = array( 'repo' => $repo, 'git_tree' => (string) ($plan['target_commit'] ?? ''), 'bootstrap' => (bool) ($plan['bootstrap'] ?? false), 'lockfiles' => $lockfiles, 'package_managers' => array_values(array_unique(array_map(static fn( array $root ): string => (string) ($root['manager'] ?? ''), (array) ($plan['detected']['package_roots'] ?? array())))), 'composer_roots' => array_values(array_map(static fn( array $root ): string => (string) ($root['relative'] ?? ''), (array) ($plan['detected']['composer_roots'] ?? array()))), 'cache_identity' => hash('sha256', json_encode($cache)) );
		return array( 'key' => hash('sha256', json_encode($fields)), 'fields' => $fields, 'lockfiles' => $lockfiles );
	}
	private static function bootstrap_defaults( array $plan ): array { $margin = (array) ($plan['git_safety_margin'] ?? array()); return array( 'bytes' => max(0, (int) ($plan['bytes'] ?? 0) - max(0, (int) ($plan['tracked_bytes'] ?? 0)) - max(0, (int) ($margin['bytes'] ?? 0))), 'inodes' => max(0, (int) ($plan['inodes'] ?? 0) - max(0, (int) ($plan['counts']['tracked_entries'] ?? 0)) - max(0, (int) ($margin['inodes'] ?? 0))) ); }
	private static function compatible_samples( string $key, array $defaults ): array { $now = time(); return array_values(array_filter((array) (self::state()[$key] ?? array()), static fn( $sample ): bool => is_array($sample) && (int) ($sample['at'] ?? 0) >= $now - self::MAX_AGE_SECONDS && 'success' === ($sample['outcome'] ?? '') && is_numeric($sample['bytes'] ?? null) && is_numeric($sample['inodes'] ?? null) && (int) $sample['bytes'] <= $defaults['bytes'] && (int) $sample['inodes'] <= $defaults['inodes'])); }
	private static function percentile( array $samples, string $metric ): int { $values = array_map(static fn( array $sample ): int => max(0, (int) $sample[$metric]), $samples); sort($values, SORT_NUMERIC); return $values[(int) ceil(count($values) * 0.75) - 1]; }
	private static function with_margin( int $value, int $cap ): int { return min($cap, (int) ceil($value * (100 + self::SAFETY_PERCENT) / 100)); }
	private static function delta( mixed $before, mixed $after ): ?int { return is_numeric($before) && is_numeric($after) ? max(0, (int) $before - (int) $after) : null; }
	private static function capacity( array $budget ): array { return array( 'filesystem_free_bytes' => $budget['filesystem_free_bytes'] ?? null, 'filesystem_free_inodes' => $budget['filesystem_free_inodes'] ?? null ); }
	private static function state(): array { $state = function_exists('get_option') ? get_option(self::OPTION, array()) : array(); return is_array($state) ? $state : array(); }
	private static function save( array $state ): void { if ( function_exists('update_option') ) { update_option(self::OPTION, $state, false); } }
}
